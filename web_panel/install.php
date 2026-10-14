<?php
require_once __DIR__ . '/config.php';

$message = '';
$status = '';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

    // Admins table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admins` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Licenses table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `licenses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_key` VARCHAR(64) NOT NULL UNIQUE,
            `duration_days` INT NOT NULL DEFAULT 30,
            `status` ENUM('unused', 'active', 'expired', 'banned') NOT NULL DEFAULT 'unused',
            `device_id` VARCHAR(128) DEFAULT NULL,
            `device_name` VARCHAR(128) DEFAULT NULL,
            `activated_at` DATETIME DEFAULT NULL,
            `expires_at` DATETIME DEFAULT NULL,
            `note` VARCHAR(255) DEFAULT '',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_license_key` (`license_key`),
            INDEX `idx_device_id` (`device_id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Activity Logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_key` VARCHAR(64) NOT NULL,
            `action` VARCHAR(50) NOT NULL,
            `device_id` VARCHAR(128) DEFAULT NULL,
            `device_name` VARCHAR(128) DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_log_key` (`license_key`),
            INDEX `idx_log_date` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Insert default admin if not exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `admins` WHERE `username` = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $insert = $pdo->prepare("INSERT INTO `admins` (`username`, `password`) VALUES ('admin', ?)");
        $insert->execute([$hash]);
    }

    $status = 'success';
    $message = 'Database and tables installed successfully! Default Admin created.';
} catch (Exception $e) {
    $status = 'error';
    $message = 'Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OGIOS License Panel - Installation</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: #0a0b0e; color: #fff; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .card { background: #13151b; border: 1px solid #232733; border-radius: 20px; padding: 40px; max-width: 500px; width: 100%; box-shadow: 0 20px 40px rgba(0,0,0,0.5); text-align: center; }
        h1 { font-size: 24px; font-weight: 800; margin-bottom: 12px; color: #fff; }
        .badge { display: inline-block; padding: 8px 16px; border-radius: 30px; font-weight: 700; font-size: 14px; margin-bottom: 24px; }
        .badge.success { background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); }
        .badge.error { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .info-box { background: #1a1d26; border-radius: 12px; padding: 20px; text-align: left; margin-bottom: 24px; border: 1px solid #2d3342; font-size: 14px; line-height: 1.6; }
        .info-box span { color: #f43f5e; font-weight: 700; }
        .btn { display: inline-block; width: 100%; padding: 14px; background: #e11d48; color: #fff; font-weight: 700; text-decoration: none; border-radius: 12px; transition: 0.2s; }
        .btn:hover { background: #be123c; transform: translateY(-1px); }
    </style>
</head>
<body>
    <div class="card">
        <h1>🚀 OGIOS License Setup</h1>
        <div class="badge <?= $status ?>"><?= htmlspecialchars($message) ?></div>

        <?php if ($status === 'success'): ?>
        <div class="info-box">
            <p><strong>Database:</strong> <?= DB_NAME ?></p>
            <p><strong>Username:</strong> <span>admin</span></p>
            <p><strong>Password:</strong> <span>admin123</span></p>
            <p style="margin-top: 10px; font-size: 12px; color: #8892b0;">(Please change your password after logging in)</p>
        </div>
        <a href="login.php" class="btn">Go to Admin Dashboard →</a>
        <?php else: ?>
        <div class="info-box">
            <p>Make sure MySQL is running in your <strong>XAMPP Control Panel</strong>.</p>
        </div>
        <a href="install.php" class="btn">Try Again</a>
        <?php endif; ?>
    </div>
</body>
</html>
