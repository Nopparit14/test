<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ogios_license');
// Security key for hashing or extra API validation
define('API_SECRET', 'OGIOS_SECRET_SALT_2026');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            die(json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ----------------- REMOTE CONTROL SETTINGS (shared by api.php & index.php) -----------------
function systemSettingsDefaults() {
    return [
        'kill_switch_enabled' => '0',
        'kill_switch_message' => '⚠️ ระบบกำลังอัปเดตความปลอดภัย (Emergency Maintenance) ขอให้งดเปิดสูตรชั่วคราว เพื่อความปลอดภัยของไอดีเกมของคุณ กรุณารอประกาศจากแอดมิน',
        'announcement_enabled' => '0',
        'announcement_title' => '📢 ประกาศจากทีมงาน XSTH',
        'announcement_message' => '',
        'announcement_link' => '',
        'announcement_button_text' => 'ตกลง',
        'force_update_enabled' => '0',
        'latest_app_version' => '1.1.2',
        'update_download_url' => '',
        'update_changelog' => ''
    ];
}

function getSystemSettings($pdo) {
    $defaults = systemSettingsDefaults();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key` varchar(64) NOT NULL PRIMARY KEY,
            `setting_value` text DEFAULT NULL,
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $pdo->query("SELECT `setting_key`, `setting_value` FROM `system_settings`");
        $dbRows = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        return array_merge($defaults, $dbRows ?: []);
    } catch (Exception $e) {
        return $defaults;
    }
}

function saveSystemSetting($pdo, $key, $value) {
    $stmt = $pdo->prepare("INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)");
    $stmt->execute([$key, (string)$value]);
}

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
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            UNIQUE KEY `uniq_target` (`target_filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}