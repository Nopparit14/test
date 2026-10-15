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
        'update_changelog' => '',
        // Discord & Telegram Notification Settings
        'discord_webhook_url' => '',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'notify_license_activation' => '1',
        'notify_reseller_generate' => '1',
        'notify_kill_switch' => '1',
        'notify_cloud_ota' => '1'
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

function sendSystemNotification($pdo, $title, $message, $type = 'info', $fields = []) {
    try {
        $settings = getSystemSettings($pdo);

        if ($type === 'activation' && ($settings['notify_license_activation'] ?? '1') !== '1') return;
        if ($type === 'reseller' && ($settings['notify_reseller_generate'] ?? '1') !== '1') return;
        if ($type === 'kill_switch' && ($settings['notify_kill_switch'] ?? '1') !== '1') return;
        if ($type === 'ota' && ($settings['notify_cloud_ota'] ?? '1') !== '1') return;

        $colorMap = [
            'activation' => 0x22c55e, // Green
            'reseller'   => 0xeab308, // Gold
            'kill_switch'=> 0xef4444, // Red
            'ota'        => 0x38bdf8, // Cyan
            'test'       => 0xa855f7, // Purple
            'info'       => 0x38bdf8  // Blue
        ];
        $embedColor = $colorMap[$type] ?? 0x38bdf8;

        // 1. Send Discord Webhook
        $discordUrl = trim($settings['discord_webhook_url'] ?? '');
        if (!empty($discordUrl) && filter_var($discordUrl, FILTER_VALIDATE_URL)) {
            $embedFields = [];
            foreach ($fields as $k => $v) {
                $embedFields[] = [
                    'name' => (string)$k,
                    'value' => (string)$v,
                    'inline' => true
                ];
            }

            $payload = [
                'username' => 'XSTH Security Bot',
                'avatar_url' => 'https://img.icons8.com/neon/96/shield.png',
                'embeds' => [
                    [
                        'title' => $title,
                        'description' => $message,
                        'color' => $embedColor,
                        'fields' => $embedFields,
                        'footer' => [
                            'text' => 'XSTH License System • ' . date('d/m/Y H:i:s')
                        ]
                    ]
                ]
            ];

            $ch = curl_init($discordUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_exec($ch);
            curl_close($ch);
        }

        // 2. Send Telegram Bot
        $tgToken = trim($settings['telegram_bot_token'] ?? '');
        $tgChatId = trim($settings['telegram_chat_id'] ?? '');
        if (!empty($tgToken) && !empty($tgChatId)) {
            $tgText = "<b>" . htmlspecialchars($title) . "</b>\n";
            $tgText .= htmlspecialchars($message) . "\n\n";
            foreach ($fields as $k => $v) {
                $tgText .= "• <b>" . htmlspecialchars($k) . ":</b> " . htmlspecialchars($v) . "\n";
            }
            $tgText .= "\n🕒 <i>" . date('d/m/Y H:i:s') . "</i>";

            $tgUrl = "https://api.telegram.org/bot{$tgToken}/sendMessage";
            $ch = curl_init($tgUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'chat_id' => $tgChatId,
                'text' => $tgText,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $e) {}
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
            `created_at` datetime DEFAULT NULL,
            `updated_at` datetime DEFAULT NULL,
            UNIQUE KEY `uniq_target` (`target_filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}