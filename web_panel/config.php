<?php
// Polyfills for backward compatibility with PHP 7.x
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return (string)$needle !== '' && strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

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
    $results = ['discord' => ['sent' => false, 'code' => 0, 'error' => ''], 'telegram' => ['sent' => false, 'code' => 0, 'error' => '']];
    try {
        $settings = getSystemSettings($pdo);

        if ($type === 'activation' && ($settings['notify_license_activation'] ?? '1') !== '1') return $results;
        if ($type === 'reseller' && ($settings['notify_reseller_generate'] ?? '1') !== '1') return $results;
        if ($type === 'kill_switch' && ($settings['notify_kill_switch'] ?? '1') !== '1') return $results;
        if ($type === 'ota' && ($settings['notify_cloud_ota'] ?? '1') !== '1') return $results;

        $colorMap = [
            'activation' => 0x22c55e, // Emerald Green
            'reseller'   => 0xf59e0b, // Amber Gold
            'kill_switch'=> 0xef4444, // Crimson Red
            'ota'        => 0x06b6d4, // Cyan Sky
            'test'       => 0x8b5cf6, // Violet
            'info'       => 0x3b82f6  // Blue
        ];
        $embedColor = $colorMap[$type] ?? 0x3b82f6;

        $thumbMap = [
            'activation' => 'https://img.icons8.com/fluency/96/key.png',
            'reseller'   => 'https://img.icons8.com/fluency/96/conference-call.png',
            'kill_switch'=> 'https://img.icons8.com/fluency/96/error.png',
            'ota'        => 'https://img.icons8.com/fluency/96/cloud-sync.png',
            'test'       => 'https://img.icons8.com/fluency/96/rocket.png',
            'info'       => 'https://img.icons8.com/fluency/96/info-popup.png'
        ];
        $thumbnailUrl = $thumbMap[$type] ?? $thumbMap['info'];

        // 1. Send Discord Webhook
        $discordUrl = trim($settings['discord_webhook_url'] ?? '');
        if (!empty($discordUrl) && filter_var($discordUrl, FILTER_VALIDATE_URL)) {
            $embedFields = [];
            foreach ($fields as $k => $v) {
                $valStr = (string)$v;
                // Highlight keys, UDIDs, IPs, and filenames in Discord markdown code blocks
                if (preg_match('/(คีย์|UDID|IP|ไฟล์|เวอร์ชั่น)/u', (string)$k) && !str_starts_with($valStr, '`')) {
                    $formattedVal = "`{$valStr}`";
                } else {
                    $formattedVal = $valStr;
                }
                $embedFields[] = [
                    'name' => (string)$k,
                    'value' => $formattedVal,
                    'inline' => true
                ];
            }

            $payload = [
                'username' => 'XSTH Security Guard',
                'avatar_url' => 'https://img.icons8.com/fluency/96/shield.png',
                'embeds' => [
                    [
                        'author' => [
                            'name' => 'XSTH VIP Security & OTA Manager',
                            'icon_url' => 'https://img.icons8.com/fluency/96/shield.png'
                        ],
                        'title' => $title,
                        'description' => $message,
                        'color' => $embedColor,
                        'fields' => $embedFields,
                        'thumbnail' => [
                            'url' => $thumbnailUrl
                        ],
                        'footer' => [
                            'text' => 'XSTH License System • Realtime Alert',
                            'icon_url' => 'https://img.icons8.com/fluency/96/server.png'
                        ],
                        'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
                    ]
                ]
            ];

            $ch = curl_init($discordUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            $results['discord']['code'] = $httpCode;
            if ($httpCode >= 200 && $httpCode < 300) {
                $results['discord']['sent'] = true;
            } else {
                $results['discord']['error'] = $curlErr ?: "HTTP {$httpCode}: " . mb_substr($resp ?: '', 0, 150);
            }
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            $results['telegram']['code'] = $httpCode;
            if ($httpCode >= 200 && $httpCode < 300) {
                $results['telegram']['sent'] = true;
            } else {
                $results['telegram']['error'] = $curlErr ?: "HTTP {$httpCode}";
            }
        }
    } catch (Throwable $e) {}
    return $results;
}

function ensureCloudPatchesTable($pdo) {
    try {
        // Create bare-minimum table (id only) — all columns added via ALTER migrations below
        $pdo->exec("CREATE TABLE IF NOT EXISTS `cloud_patches` (
            `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Fetch existing columns
        $existingCols = [];
        $colRows = $pdo->query("SHOW COLUMNS FROM `cloud_patches`");
        if ($colRows) {
            foreach ($colRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existingCols[] = strtolower($row['Field']);
            }
        }

        // Add ALL columns that might be missing (handles any old schema)
        $migrations = [
            'patch_name'      => "ALTER TABLE `cloud_patches` ADD COLUMN `patch_name` varchar(100) NOT NULL DEFAULT ''",
            'target_filename' => "ALTER TABLE `cloud_patches` ADD COLUMN `target_filename` varchar(100) NOT NULL DEFAULT ''",
            'version'         => "ALTER TABLE `cloud_patches` ADD COLUMN `version` varchar(32) NOT NULL DEFAULT '1.0'",
            'file_path'       => "ALTER TABLE `cloud_patches` ADD COLUMN `file_path` varchar(255) NOT NULL DEFAULT ''",
            'file_size'       => "ALTER TABLE `cloud_patches` ADD COLUMN `file_size` bigint(20) NOT NULL DEFAULT 0",
            'file_hash'       => "ALTER TABLE `cloud_patches` ADD COLUMN `file_hash` varchar(64) NOT NULL DEFAULT ''",
            'description'     => "ALTER TABLE `cloud_patches` ADD COLUMN `description` text DEFAULT NULL",
            'is_active'       => "ALTER TABLE `cloud_patches` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1",
            'downloads_count' => "ALTER TABLE `cloud_patches` ADD COLUMN `downloads_count` int(11) NOT NULL DEFAULT 0",
            'created_at'      => "ALTER TABLE `cloud_patches` ADD COLUMN `created_at` datetime DEFAULT NULL",
            'updated_at'      => "ALTER TABLE `cloud_patches` ADD COLUMN `updated_at` datetime DEFAULT NULL",
            'filename'        => "ALTER TABLE `cloud_patches` ADD COLUMN `filename` varchar(255) NOT NULL DEFAULT ''",
            'storage_path'    => "ALTER TABLE `cloud_patches` ADD COLUMN `storage_path` varchar(255) NOT NULL DEFAULT ''",
        ];
        foreach ($migrations as $col => $sql) {
            if (!in_array($col, $existingCols, true)) {
                try { $pdo->exec($sql); } catch (Throwable $e2) {}
            }
        }

        // Fix legacy columns that exist but have no DEFAULT value (prevents HY000 errors)
        $fixDefaults = [
            'filename'        => "ALTER TABLE `cloud_patches` MODIFY COLUMN `filename` varchar(255) NOT NULL DEFAULT ''",
            'storage_path'    => "ALTER TABLE `cloud_patches` MODIFY COLUMN `storage_path` varchar(255) NOT NULL DEFAULT ''",
            'patch_name'      => "ALTER TABLE `cloud_patches` MODIFY COLUMN `patch_name` varchar(100) NOT NULL DEFAULT ''",
            'target_filename' => "ALTER TABLE `cloud_patches` MODIFY COLUMN `target_filename` varchar(100) NOT NULL DEFAULT ''",
            'file_path'       => "ALTER TABLE `cloud_patches` MODIFY COLUMN `file_path` varchar(255) NOT NULL DEFAULT ''",
            'file_hash'       => "ALTER TABLE `cloud_patches` MODIFY COLUMN `file_hash` varchar(64) NOT NULL DEFAULT ''",
        ];
        foreach ($fixDefaults as $col => $sql) {
            if (in_array($col, $existingCols, true)) {
                try { $pdo->exec($sql); } catch (Throwable $e4) {}
            }
        }

        // Add unique index on target_filename if missing
        try {
            $idxRows = $pdo->query("SHOW INDEX FROM `cloud_patches` WHERE Key_name = 'uniq_target'");
            if ($idxRows && $idxRows->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `cloud_patches` ADD UNIQUE KEY `uniq_target` (`target_filename`)");
            }
        } catch (Throwable $e3) {}

    } catch (Throwable $e) {}
}