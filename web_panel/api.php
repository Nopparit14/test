<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

$pdo = getDB();

$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : null;
$input = is_array($jsonInput) ? array_merge($_REQUEST, $jsonInput) : $_REQUEST;

$action = $input['action'] ?? 'check';
$key = trim($input['key'] ?? '');
$deviceId = trim($input['device_id'] ?? '');
$deviceName = trim($input['device_name'] ?? 'iOS Device');
$appVersion = trim($input['app_version'] ?? '');
$clientIP = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

function logActivity($pdo, $key, $action, $deviceId, $deviceName, $ip) {
    try {
        $stmt = $pdo->prepare("INSERT INTO `activity_logs` (`license_key`, `action`, `device_id`, `device_name`, `ip_address`) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$key, $action, $deviceId, $deviceName, $ip]);
    } catch (Exception $e) {}
}

// ----------------- REMOTE SYSTEM SETTINGS (helpers live in config.php) -----------------
$systemSettings = getSystemSettings($pdo);

// Force-update: only when enabled AND the app reports its version (app_version)
$updateRequired = $systemSettings['force_update_enabled'] === '1'
    && $appVersion !== ''
    && version_compare($appVersion, $systemSettings['latest_app_version'], '<');

$remoteConfigPayload = [
    'kill_switch' => [
        'enabled' => $systemSettings['kill_switch_enabled'] === '1',
        'message' => $systemSettings['kill_switch_message']
    ],
    'announcement' => [
        'enabled' => $systemSettings['announcement_enabled'] === '1',
        'title' => $systemSettings['announcement_title'],
        'message' => $systemSettings['announcement_message'],
        'link' => $systemSettings['announcement_link'],
        'button_text' => $systemSettings['announcement_button_text']
    ],
    'version_control' => [
        'force_update' => $systemSettings['force_update_enabled'] === '1',
        'update_required' => $updateRequired,
        'latest_version' => $systemSettings['latest_app_version'],
        'download_url' => $systemSettings['update_download_url'],
        'changelog' => $systemSettings['update_changelog']
    ]
];

// Direct fetch of remote config (e.g. app startup / background check)
if ($action === 'remote_config' || $action === 'config') {
    $status = $systemSettings['kill_switch_enabled'] === '1' ? 'maintenance' : ($updateRequired ? 'update_required' : 'success');
    $msg = $systemSettings['kill_switch_enabled'] === '1'
        ? ($systemSettings['kill_switch_message'] ?: 'Emergency maintenance in progress.')
        : ($updateRequired ? ('กรุณาอัปเดตแอปเป็นเวอร์ชั่น ' . $systemSettings['latest_app_version'] . ' ก่อนใช้งาน') : 'OK');

    echo json_encode([
        'status' => $status,
        'message' => $msg,
        'remote_config' => $remoteConfigPayload,
        'server_time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Ping / API Heartbeat
if ($action === 'ping') {
    echo json_encode([
        'status' => 'success',
        'message' => 'API is online and reachable.',
        'server_time' => date('Y-m-d H:i:s'),
        'ip' => $clientIP,
        'remote_config' => $remoteConfigPayload
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Check Emergency Kill-Switch before anything else
if ($systemSettings['kill_switch_enabled'] === '1') {
    echo json_encode([
        'status' => 'maintenance',
        'message' => $systemSettings['kill_switch_message'] ?: 'Emergency maintenance in progress.',
        'remote_config' => $remoteConfigPayload
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Force update: block outdated app versions
if ($updateRequired) {
    echo json_encode([
        'status' => 'update_required',
        'message' => 'กรุณาอัปเดตแอปเป็นเวอร์ชั่น ' . $systemSettings['latest_app_version'] . ' ก่อนใช้งาน',
        'remote_config' => $remoteConfigPayload
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Check by UDID if key is empty
if (empty($key)) {
    if (!empty($deviceId)) {
        $stmt = $pdo->prepare("SELECT * FROM `licenses` WHERE `device_id` = ? AND `status` = 'active' ORDER BY `id` DESC LIMIT 1");
        $stmt->execute([$deviceId]);
        $license = $stmt->fetch();
        if ($license) {
            $key = $license['license_key'];
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'UDID not registered. Please enter your license key.',
                'remote_config' => $remoteConfigPayload
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'License key or Device UDID is required.',
            'remote_config' => $remoteConfigPayload
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Find key in database
$stmt = $pdo->prepare("SELECT * FROM `licenses` WHERE `license_key` = ? LIMIT 1");
$stmt->execute([$key]);
$license = $stmt->fetch();

if (!$license) {
    logActivity($pdo, $key, 'FAILED_INVALID_KEY', $deviceId, $deviceName, $clientIP);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid license key!',
        'remote_config' => $remoteConfigPayload
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Check if banned
if ($license['status'] === 'banned') {
    logActivity($pdo, $key, 'BLOCKED_BANNED', $deviceId, $deviceName, $clientIP);
    echo json_encode([
        'status' => 'error',
        'message' => 'This license key has been banned.',
        'remote_config' => $remoteConfigPayload
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$now = new DateTime();

// 1. If key is UNUSED -> ACTIVATE IT
if ($license['status'] === 'unused') {
    $duration = (int)$license['duration_days'];
    $expiresAt = null;

    if ($duration > 0) {
        $expiry = clone $now;
        $expiry->modify("+{$duration} days");
        $expiresAt = $expiry->format('Y-m-d H:i:s');
    }

    $update = $pdo->prepare("
        UPDATE `licenses` 
        SET `status` = 'active', 
            `device_id` = ?, 
            `device_name` = ?, 
            `activated_at` = ?, 
            `expires_at` = ? 
        WHERE `id` = ?
    ");
    $update->execute([$deviceId, $deviceName, $now->format('Y-m-d H:i:s'), $expiresAt, $license['id']]);

    logActivity($pdo, $key, 'ACTIVATED_SUCCESS', $deviceId, $deviceName, $clientIP);

    echo json_encode([
        'status' => 'success',
        'message' => 'License activated successfully!',
        'license_key' => $key,
        'duration_days' => $duration,
        'expires_at' => $expiresAt ?: 'Lifetime',
        'days_left' => $duration > 0 ? $duration : 9999,
        'remote_config' => $remoteConfigPayload
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 2. If key is ACTIVE -> CHECK HWID LOCK
if (!empty($license['device_id']) && !empty($deviceId) && $license['device_id'] !== $deviceId) {
    logActivity($pdo, $key, 'BLOCKED_HWID_MISMATCH', $deviceId, $deviceName, $clientIP);
    echo json_encode([
        'status' => 'error',
        'message' => 'HWID Mismatch! This key is already bound to another device.',
        'remote_config' => $remoteConfigPayload
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Check expiration
if (!empty($license['expires_at'])) {
    $expiresDate = new DateTime($license['expires_at']);
    if ($now > $expiresDate) {
        $pdo->prepare("UPDATE `licenses` SET `status` = 'expired' WHERE `id` = ?")->execute([$license['id']]);
        logActivity($pdo, $key, 'EXPIRED', $deviceId, $deviceName, $clientIP);
        echo json_encode([
            'status' => 'error',
            'message' => 'License key has expired on ' . $license['expires_at'],
            'remote_config' => $remoteConfigPayload
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $interval = $now->diff($expiresDate);
    $daysLeft = $interval->days;

    logActivity($pdo, $key, 'VERIFIED_ACTIVE', $deviceId, $deviceName, $clientIP);

    echo json_encode([
        'status' => 'success',
        'message' => 'License is active.',
        'license_key' => $key,
        'expires_at' => $license['expires_at'],
        'days_left' => $daysLeft,
        'remote_config' => $remoteConfigPayload
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Lifetime key
logActivity($pdo, $key, 'VERIFIED_LIFETIME', $deviceId, $deviceName, $clientIP);
echo json_encode([
    'status' => 'success',
    'message' => 'Lifetime license is active.',
    'license_key' => $key,
    'expires_at' => 'Lifetime',
    'days_left' => 9999,
    'remote_config' => $remoteConfigPayload
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);