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

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true) ?: $_REQUEST;

$action = $input['action'] ?? 'check';
$key = trim($input['key'] ?? '');
$deviceId = trim($input['device_id'] ?? '');
$deviceName = trim($input['device_name'] ?? 'iOS Device');
$clientIP = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

function logActivity($pdo, $key, $action, $deviceId, $deviceName, $ip) {
    try {
        $stmt = $pdo->prepare("INSERT INTO `activity_logs` (`license_key`, `action`, `device_id`, `device_name`, `ip_address`) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$key, $action, $deviceId, $deviceName, $ip]);
    } catch (Exception $e) {}
}

if ($action === 'ping') {
    echo json_encode([
        'status' => 'success',
        'message' => 'API is online and reachable.',
        'server_time' => date('Y-m-d H:i:s'),
        'ip' => $clientIP
    ]);
    exit;
}

if (empty($key)) {
    if (!empty($deviceId)) {
        // Check if this device UDID has an active license
        $stmt = $pdo->prepare("SELECT * FROM `licenses` WHERE `device_id` = ? AND `status` = 'active' ORDER BY `id` DESC LIMIT 1");
        $stmt->execute([$deviceId]);
        $license = $stmt->fetch();
        if ($license) {
            $key = $license['license_key'];
        } else {
            echo json_encode(['status' => 'error', 'message' => 'UDID not registered. Please enter your license key.']);
            exit;
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'License key or Device UDID is required.']);
        exit;
    }
}

// Find key in database
$stmt = $pdo->prepare("SELECT * FROM `licenses` WHERE `license_key` = ? LIMIT 1");
$stmt->execute([$key]);
$license = $stmt->fetch();

if (!$license) {
    logActivity($pdo, $key, 'FAILED_INVALID_KEY', $deviceId, $deviceName, $clientIP);
    echo json_encode(['status' => 'error', 'message' => 'Invalid license key!']);
    exit;
}

// Check if banned
if ($license['status'] === 'banned') {
    logActivity($pdo, $key, 'BLOCKED_BANNED', $deviceId, $deviceName, $clientIP);
    echo json_encode(['status' => 'error', 'message' => 'This license key has been banned.']);
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
        'days_left' => $duration > 0 ? $duration : 9999
    ]);
    exit;
}

// 2. If key is ACTIVE -> CHECK HWID LOCK
if (!empty($license['device_id']) && !empty($deviceId) && $license['device_id'] !== $deviceId) {
    logActivity($pdo, $key, 'BLOCKED_HWID_MISMATCH', $deviceId, $deviceName, $clientIP);
    echo json_encode([
        'status' => 'error',
        'message' => 'HWID Mismatch! This key is already bound to another device.'
    ]);
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
            'message' => 'License key has expired on ' . $license['expires_at']
        ]);
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
        'days_left' => $daysLeft
    ]);
    exit;
}

// Lifetime key
logActivity($pdo, $key, 'VERIFIED_LIFETIME', $deviceId, $deviceName, $clientIP);
echo json_encode([
    'status' => 'success',
    'message' => 'Lifetime license is active.',
    'license_key' => $key,
    'expires_at' => 'Lifetime',
    'days_left' => 9999
]);
