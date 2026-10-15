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
        'php_version' => PHP_VERSION,
        'ip' => $clientIP,
        'remote_config' => $remoteConfigPayload
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ----------------- CLOUD PATCHES (OTA) ENDPOINTS -----------------
if (!function_exists('ensureCloudPatchesTable')) {
    function ensureCloudPatchesTable($pdo) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `cloud_patches` (
                `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `patch_name` varchar(100) NOT NULL,
                `target_filename` varchar(100) NOT NULL,
                `version` varchar(32) NOT NULL DEFAULT '1.0',
                `file_path` varchar(255) NOT NULL,
                `file_size` bigint(20) NOT NULL DEFAULT 0,
                `file_hash` varchar(64) NOT NULL,
                `description` text DEFAULT NULL,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `downloads_count` int(11) NOT NULL DEFAULT 0,
                `created_at` datetime DEFAULT NULL,
                `updated_at` datetime DEFAULT NULL,
                UNIQUE KEY `uniq_target` (`target_filename`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

if ($action === 'list_patches' || $action === 'patches') {
    $patches = [];
    try {
        ensureCloudPatchesTable($pdo);
        $stmt = $pdo->query("SELECT `id`, `patch_name`, `target_filename`, `version`, `file_size`, `file_hash`, `description`, `updated_at` FROM `cloud_patches` WHERE `is_active` = 1 ORDER BY `id` ASC");
        if ($stmt) {
            $patches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $patches = [];
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = rtrim(dirname($_SERVER['PHP_SELF'] ?? '/'), '/\\');
    $baseURL = "{$protocol}{$host}{$uri}/api.php";

    foreach ($patches as &$p) {
        $p['download_url'] = "{$baseURL}?action=download_patch&id={$p['id']}";
        $p['file_size'] = (int)($p['file_size'] ?? 0);
        $p['id'] = (int)($p['id'] ?? 0);
    }
    unset($p);

    echo json_encode([
        'status' => 'success',
        'count' => count($patches),
        'patches' => $patches,
        'server_time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_patch') {
    try {
        ensureCloudPatchesTable($pdo);
        $id = isset($input['id']) ? (int)$input['id'] : 0;
        $target = trim($input['target'] ?? $input['filename'] ?? '');

        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM `cloud_patches` WHERE `id` = ? AND `is_active` = 1 LIMIT 1");
            $stmt->execute([$id]);
        } else if (!empty($target)) {
            $stmt = $pdo->prepare("SELECT * FROM `cloud_patches` WHERE `target_filename` = ? AND `is_active` = 1 LIMIT 1");
            $stmt->execute([$target]);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing patch id or target filename'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $patch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$patch) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Patch not found or disabled'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $realFilePath = __DIR__ . '/' . ltrim($patch['file_path'], '/\\');
        if (!file_exists($realFilePath)) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Physical file not found on server'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Increment download counter
        try {
            $pdo->prepare("UPDATE `cloud_patches` SET `downloads_count` = `downloads_count` + 1 WHERE `id` = ?")->execute([$patch['id']]);
        } catch (Throwable $e) {}

        // Stream the binary .3105 file
        $downloadName = $patch['target_filename'];
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($realFilePath));
        readfile($realFilePath);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
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

    sendSystemNotification($pdo, '🎉 ลูกค้าเปิดใช้งานคีย์ใหม่', 'มีลูกค้าทำการเชื่อมต่อและเปิดใช้งานคีย์เข้าสู่ระบบสำเร็จ', 'activation', [
        'รหัสคีย์' => $key,
        'อายุการใช้งาน' => $duration > 0 ? "{$duration} วัน" : 'ตลอดชีพ (Lifetime)',
        'อุปกรณ์' => $deviceName ?: 'iOS Device',
        'เลขเครื่อง (UDID)' => !empty($deviceId) ? (substr($deviceId, 0, 8) . '...' . substr($deviceId, -4)) : 'Unknown',
        'IP Address' => $clientIP,
        'หมดอายุวันที่' => $expiresAt ? date('d/m/Y H:i', strtotime($expiresAt)) : 'ตลอดชีพ'
    ]);

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