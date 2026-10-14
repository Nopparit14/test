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

// Accept both GET and POST (JSON or FormData)
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true) ?: $_REQUEST;

$action = $input['action'] ?? 'check';
$key = trim($input['key'] ?? '');
$deviceId = trim($input['device_id'] ?? '');
$deviceName = trim($input['device_name'] ?? 'iOS Device');

if (empty($key)) {
    echo json_encode(['status' => 'error', 'message' => 'License key is required.']);
    exit;
}

// Find key in database
$stmt = $pdo->prepare("SELECT * FROM `licenses` WHERE `license_key` = ? LIMIT 1");
$stmt->execute([$key]);
$license = $stmt->fetch();

if (!$license) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid license key!']);
    exit;
}

// Check if banned
if ($license['status'] === 'banned') {
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

// 2. If key is ACTIVE -> CHECK EXPIRY & DEVICE LOCK (HWID)
if (!empty($license['device_id']) && !empty($deviceId) && $license['device_id'] !== $deviceId) {
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
        // Mark as expired in DB
        $pdo->prepare("UPDATE `licenses` SET `status` = 'expired' WHERE `id` = ?")->execute([$license['id']]);

        echo json_encode([
            'status' => 'error',
            'message' => 'License key has expired on ' . $license['expires_at']
        ]);
        exit;
    }

    $interval = $now->diff($expiresDate);
    $daysLeft = $interval->days;

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
echo json_encode([
    'status' => 'success',
    'message' => 'Lifetime license is active.',
    'license_key' => $key,
    'expires_at' => 'Lifetime',
    'days_left' => 9999
]);
