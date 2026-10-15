<?php
session_start();
require_once __DIR__ . '/config.php';

// Authentication Check: Admin or Reseller
if (!isset($_SESSION['admin_logged']) && !isset($_SESSION['reseller_logged'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$alert = '';
$alertType = 'success';
$newlyGeneratedKeys = [];

$isAdmin = !empty($_SESSION['admin_logged']);
$isReseller = !$isAdmin && !empty($_SESSION['reseller_logged']);
$currentResellerId = $isReseller ? (int)($_SESSION['reseller_id'] ?? 0) : null;
$currentUsername = $_SESSION['admin_user'] ?? 'admin';
$currentDisplayName = $_SESSION['display_name'] ?? $currentUsername;

// Refresh Reseller Profile from Database if logged in as Reseller
$currentReseller = null;
$currentResellerCredits = 0;
$resellerExpiresAt = null;
$isResellerExpired = false;
$isResellerActive = true;

if ($isReseller && $currentResellerId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM `resellers` WHERE `id` = ? LIMIT 1");
    $stmt->execute([$currentResellerId]);
    $currentReseller = $stmt->fetch();

    if (!$currentReseller) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $currentResellerCredits = (int)$currentReseller['credits'];
    $resellerExpiresAt = $currentReseller['expires_at'];
    $isResellerExpired = !empty($resellerExpiresAt) && strtotime($resellerExpiresAt) < time();
    $isResellerActive = ($currentReseller['status'] === 'active') && !$isResellerExpired;
    $currentDisplayName = !empty($currentReseller['name']) ? $currentReseller['name'] : $currentReseller['username'];
}

// ----------------- HANDLE POST ACTIONS -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Generate License Keys
    if ($action === 'generate') {
        $count = max(1, min(100, (int)($_POST['count'] ?? 1)));
        $duration = (int)($_POST['duration'] ?? 30);
        $note = trim($_POST['note'] ?? '');
        $prefix = strtoupper(trim($_POST['prefix'] ?? 'XSTH'));
        if (empty($prefix)) $prefix = 'XSTH';

        if ($isReseller) {
            if (!$isResellerActive) {
                if ($isResellerExpired) {
                    $alert = "บัญชีตัวแทนของคุณหมดอายุแล้ว กรุณาติดต่อแอดมินเพื่อต่ออายุ (Account Expired)";
                } else {
                    $alert = "บัญชีตัวแทนของคุณถูกระงับการใช้งานชั่วคราว (Account Suspended)";
                }
                $alertType = 'error';
            } elseif ($currentResellerCredits < $count) {
                $alert = "เครดิตไม่เพียงพอ! ต้องการ {$count} เครดิต แต่คงเหลือ {$currentResellerCredits} เครดิต กรุณาติดต่อแอดมินเพื่อเติมเครดิต";
                $alertType = 'error';
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE `resellers` SET `credits` = `credits` - ? WHERE `id` = ?")->execute([$count, $currentResellerId]);
                    $currentResellerCredits -= $count;

                    $stmt = $pdo->prepare("INSERT INTO `licenses` (`license_key`, `duration_days`, `status`, `note`, `reseller_id`, `created_by`) VALUES (?, ?, 'unused', ?, ?, ?)");

                    for ($i = 0; $i < $count; $i++) {
                        $rand1 = strtoupper(bin2hex(random_bytes(3)));
                        $rand2 = strtoupper(bin2hex(random_bytes(3)));
                        $rand3 = strtoupper(bin2hex(random_bytes(2)));
                        $newKey = "{$prefix}-{$rand1}-{$rand2}-{$rand3}";
                        $stmt->execute([$newKey, $duration, $note, $currentResellerId, $currentReseller['username']]);
                        $newlyGeneratedKeys[] = $newKey;
                    }

                    $pdo->prepare("INSERT INTO `activity_logs` (`license_key`, `action`, `device_name`, `ip_address`) VALUES (?, 'RESELLER_GENERATE_BATCH', ?, ?)")
                        ->execute([$newlyGeneratedKeys[0], "Reseller: {$currentReseller['username']} ({$count} keys)", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

                    $pdo->commit();
                    $alert = "สร้างคีย์สำเร็จ {$count} รายการ! หักเครดิต {$count} เครดิต (คงเหลือ {$currentResellerCredits} เครดิต)";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $alert = "เกิดข้อผิดพลาดในการสร้างคีย์: " . $e->getMessage();
                    $alertType = 'error';
                }
            }
        } else {
            $assignedResellerId = !empty($_POST['assigned_reseller_id']) ? (int)$_POST['assigned_reseller_id'] : null;
            $createdBy = 'admin';

            if ($assignedResellerId > 0) {
                $rCheck = $pdo->prepare("SELECT username FROM `resellers` WHERE `id` = ?");
                $rCheck->execute([$assignedResellerId]);
                $rName = $rCheck->fetchColumn();
                if ($rName) {
                    $createdBy = $rName;
                } else {
                    $assignedResellerId = null;
                }
            }

            $stmt = $pdo->prepare("INSERT INTO `licenses` (`license_key`, `duration_days`, `status`, `note`, `reseller_id`, `created_by`) VALUES (?, ?, 'unused', ?, ?, ?)");

            for ($i = 0; $i < $count; $i++) {
                $rand1 = strtoupper(bin2hex(random_bytes(3)));
                $rand2 = strtoupper(bin2hex(random_bytes(3)));
                $rand3 = strtoupper(bin2hex(random_bytes(2)));
                $newKey = "{$prefix}-{$rand1}-{$rand2}-{$rand3}";
                $stmt->execute([$newKey, $duration, $note, $assignedResellerId, $createdBy]);
                $newlyGeneratedKeys[] = $newKey;
            }
            $alert = "สร้างคีย์ใหม่สำเร็จ {$count} รายการ!";
        }
    }

    // 2. Reset HWID
    if ($action === 'reset_hwid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            if ($isReseller) {
                $stmt = $pdo->prepare("UPDATE `licenses` SET `device_id` = NULL, `device_name` = NULL WHERE `id` = ? AND `reseller_id` = ?");
                $stmt->execute([$id, $currentResellerId]);
                if ($stmt->rowCount() > 0) {
                    $alert = "รีเซ็ต HWID (ปลดล็อกเครื่อง) สำเร็จ! ลูกค้าสามารถนำคีย์ไปเข้าสู่ระบบเครื่องใหม่ได้ทันที";
                } else {
                    $alert = "ไม่พบสิทธิ์หรือคีย์นี้ไม่ได้อยู่ในการดูแลของคุณ";
                    $alertType = 'error';
                }
            } else {
                $stmt = $pdo->prepare("UPDATE `licenses` SET `device_id` = NULL, `device_name` = NULL WHERE `id` = ?");
                $stmt->execute([$id]);
                $alert = "รีเซ็ต HWID (ปลดล็อกเครื่อง) สำเร็จ!";
            }
        }
    }

    // 2.5 Re-Key
    if ($action === 'rekey') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $sqlCheck = $isReseller ? "SELECT license_key FROM `licenses` WHERE `id` = ? AND `reseller_id` = ?" : "SELECT license_key FROM `licenses` WHERE `id` = ?";
            $stmt = $pdo->prepare($sqlCheck);
            $stmt->execute($isReseller ? [$id, $currentResellerId] : [$id]);
            $oldKey = $stmt->fetchColumn();

            if ($oldKey) {
                $parts = explode('-', $oldKey);
                $prefix = (count($parts) > 1 && strlen($parts[0]) <= 10) ? $parts[0] : 'XSTH';
                $rand1 = strtoupper(bin2hex(random_bytes(3)));
                $rand2 = strtoupper(bin2hex(random_bytes(3)));
                $rand3 = strtoupper(bin2hex(random_bytes(2)));
                $newKey = "{$prefix}-{$rand1}-{$rand2}-{$rand3}";

                $pdo->prepare("UPDATE `licenses` SET `license_key` = ?, `device_id` = NULL, `device_name` = NULL WHERE `id` = ?")
                    ->execute([$newKey, $id]);

                $actor = $isReseller ? "Reseller: @{$currentUsername}" : "Admin";
                $pdo->prepare("INSERT INTO `activity_logs` (`license_key`, `action`, `device_name`, `ip_address`) VALUES (?, 'REKEY_REPLACE', ?, ?)")
                    ->execute([$newKey, "{$actor} รีคีย์จาก {$oldKey} เป็น {$newKey}", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

                $alert = "รีคีย์สำเร็จ! รหัสคีย์ใหม่ของคุณคือ: <b>{$newKey}</b> (ปลดล็อกเครื่องและเปลี่ยนรหัสเรียบร้อย)";
            } else {
                $alert = "ไม่พบสิทธิ์หรือคีย์นี้ไม่ได้อยู่ในการดูแลของคุณ";
                $alertType = 'error';
            }
        }
    }

    // 3. Extend Days for License
    if ($action === 'extend_days') {
        $id = (int)($_POST['id'] ?? 0);
        $days = (int)($_POST['days'] ?? 30);
        if ($id > 0 && $days > 0) {
            $sqlCheck = $isReseller ? "SELECT expires_at FROM `licenses` WHERE `id` = ? AND `reseller_id` = ?" : "SELECT expires_at FROM `licenses` WHERE `id` = ?";
            $stmt = $pdo->prepare($sqlCheck);
            $stmt->execute($isReseller ? [$id, $currentResellerId] : [$id]);
            $currentExp = $stmt->fetchColumn();

            if ($currentExp !== false) {
                $baseTime = (!empty($currentExp) && strtotime($currentExp) > time()) ? strtotime($currentExp) : time();
                $newExp = date('Y-m-d H:i:s', strtotime("+{$days} days", $baseTime));

                $pdo->prepare("UPDATE `licenses` SET `expires_at` = ?, `status` = 'active' WHERE `id` = ?")->execute([$newExp, $id]);
                $alert = "เพิ่มอายุคีย์ +{$days} วัน เรียบร้อยแล้ว (หมดอายุใหม่: {$newExp})";
            }
        }
    }

    // 4. Toggle Ban Status
    if ($action === 'toggle_ban' && $isAdmin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT status FROM `licenses` WHERE `id` = ?");
            $stmt->execute([$id]);
            $curr = $stmt->fetchColumn();
            $newStatus = ($curr === 'banned') ? 'active' : 'banned';
            $pdo->prepare("UPDATE `licenses` SET `status` = ? WHERE `id` = ?")->execute([$newStatus, $id]);
            $alert = "เปลี่ยนสถานะคีย์เป็น " . strtoupper($newStatus) . " เรียบร้อยแล้ว!";
        }
    }

    // 5. Delete License Key
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            if ($isReseller) {
                $stmt = $pdo->prepare("DELETE FROM `licenses` WHERE `id` = ? AND `reseller_id` = ?");
                $stmt->execute([$id, $currentResellerId]);
                if ($stmt->rowCount() > 0) {
                    $alert = "ลบคีย์ของคุณออกจากระบบเรียบร้อยแล้ว!";
                } else {
                    $alert = "คุณไม่มีสิทธิ์ลบคีย์นี้";
                    $alertType = 'error';
                }
            } elseif ($isAdmin) {
                $pdo->prepare("DELETE FROM `licenses` WHERE `id` = ?")->execute([$id]);
                $alert = "ลบคีย์ออกจากระบบเรียบร้อยแล้ว!";
            }
        }
    }

    // ===== REMOTE CONTROL (Admin only) =====
    if ($isAdmin && in_array($action, ['toggle_kill_switch', 'save_kill_message', 'save_announcement', 'save_version_control'], true)) {
        getSystemSettings($pdo); // ensure table exists
        $rcLog = function ($act) use ($pdo, $currentUsername) {
            try {
                $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
                $pdo->prepare("INSERT INTO `activity_logs` (`license_key`, `action`, `device_name`, `ip_address`) VALUES ('SYSTEM', ?, ?, ?)")
                    ->execute([$act, $currentUsername, $ip]);
            } catch (Exception $ex) {}
        };
        $validUrl = function ($u) { return $u === '' || (filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u)); };

        if ($action === 'toggle_kill_switch') {
            $on = ($_POST['state'] ?? '') === '1' ? '1' : '0';
            saveSystemSetting($pdo, 'kill_switch_enabled', $on);
            $rcLog($on === '1' ? 'KILL_SWITCH_ON' : 'KILL_SWITCH_OFF');
            $alert = $on === '1' ? 'เปิด Emergency Maintenance แล้ว — แอปลูกค้าทุกเครื่องจะถูกล็อกทันที' : 'ปิด Emergency Maintenance แล้ว — ระบบกลับมาใช้งานได้ปกติ';
            $alertType = $on === '1' ? 'error' : 'success';
        }

        if ($action === 'save_kill_message') {
            $msg = mb_substr(trim($_POST['kill_switch_message'] ?? ''), 0, 1000);
            if ($msg === '') {
                $alert = 'กรุณากรอกข้อความแจ้งเตือน';
                $alertType = 'error';
            } else {
                saveSystemSetting($pdo, 'kill_switch_message', $msg);
                $alert = 'บันทึกข้อความ Maintenance เรียบร้อยแล้ว';
            }
        }

        if ($action === 'save_announcement') {
            $link = trim($_POST['announcement_link'] ?? '');
            if (!$validUrl($link)) {
                $alert = 'ลิงก์ประกาศต้องขึ้นต้นด้วย http:// หรือ https://';
                $alertType = 'error';
            } else {
                saveSystemSetting($pdo, 'announcement_enabled', !empty($_POST['announcement_enabled']) ? '1' : '0');
                saveSystemSetting($pdo, 'announcement_title', mb_substr(trim($_POST['announcement_title'] ?? ''), 0, 120));
                saveSystemSetting($pdo, 'announcement_message', mb_substr(trim($_POST['announcement_message'] ?? ''), 0, 1000));
                saveSystemSetting($pdo, 'announcement_link', $link);
                saveSystemSetting($pdo, 'announcement_button_text', mb_substr(trim($_POST['announcement_button_text'] ?? ''), 0, 40) ?: 'ตกลง');
                $rcLog(!empty($_POST['announcement_enabled']) ? 'ANNOUNCEMENT_ON' : 'ANNOUNCEMENT_OFF');
                $alert = 'บันทึกประกาศเรียบร้อยแล้ว';
            }
        }

        if ($action === 'save_version_control') {
            $ver = trim($_POST['latest_app_version'] ?? '');
            $url = trim($_POST['update_download_url'] ?? '');
            $force = !empty($_POST['force_update_enabled']) ? '1' : '0';
            if (!preg_match('/^\d+(\.\d+){0,3}$/', $ver)) {
                $alert = 'รูปแบบเวอร์ชั่นไม่ถูกต้อง (ตัวอย่าง: 1.1.2)';
                $alertType = 'error';
            } elseif (!$validUrl($url) || ($force === '1' && $url === '')) {
                $alert = 'กรุณาใส่ลิงก์ดาวน์โหลด .ipa ที่ขึ้นต้นด้วย http:// หรือ https://';
                $alertType = 'error';
            } else {
                saveSystemSetting($pdo, 'latest_app_version', $ver);
                saveSystemSetting($pdo, 'update_download_url', $url);
                saveSystemSetting($pdo, 'update_changelog', mb_substr(trim($_POST['update_changelog'] ?? ''), 0, 1000));
                saveSystemSetting($pdo, 'force_update_enabled', $force);
                $rcLog($force === '1' ? 'FORCE_UPDATE_ON_' . $ver : 'VERSION_SET_' . $ver);
                $alert = 'บันทึกการตั้งค่าเวอร์ชั่นเรียบร้อยแล้ว';
            }
        }
    }

    // 6. Change Password
    if ($action === 'change_password') {
        $newPass = trim($_POST['new_password'] ?? '');
        if (strlen($newPass) >= 6) {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            if ($isAdmin) {
                $pdo->prepare("UPDATE `admins` SET `password` = ? WHERE `username` = ?")->execute([$hash, $currentUsername]);
                $alert = "เปลี่ยนรหัสผ่านแอดมินเรียบร้อยแล้ว!";
            } else {
                $pdo->prepare("UPDATE `resellers` SET `password` = ? WHERE `id` = ?")->execute([$hash, $currentResellerId]);
                $alert = "เปลี่ยนรหัสผ่านตัวแทนเรียบร้อยแล้ว!";
            }
        } else {
            $alert = "รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร";
            $alertType = 'error';
        }
    }

    // 7. Clear Logs (Admin only)
    if ($action === 'clear_logs' && $isAdmin) {
        $pdo->query("TRUNCATE TABLE `activity_logs`");
        $alert = "ล้างบันทึกกิจกรรมความปลอดภัยทั้งหมดแล้ว";
    }

    // 8. ADD NEW RESELLER (Admin only)
    if ($action === 'add_reseller' && $isAdmin) {
        $rUsername = strtolower(trim($_POST['username'] ?? ''));
        $rPassword = trim($_POST['password'] ?? '');
        $rName = trim($_POST['name'] ?? '');
        $rCredits = max(0, (int)($_POST['credits'] ?? 0));
        $rExpiryPreset = $_POST['expiry_preset'] ?? '30';
        $rCustomExpiry = trim($_POST['custom_expiry'] ?? '');
        $rNote = trim($_POST['note'] ?? '');

        if (empty($rUsername) || empty($rPassword) || empty($rName)) {
            $alert = "กรุณากรอกข้อมูลตัวแทนให้ครบถ้วน (ชื่อผู้ใช้, รหัสผ่าน, และชื่อแสดง/ติดต่อ)";
            $alertType = 'error';
        } elseif (strlen($rUsername) < 3) {
            $alert = "ชื่อผู้ใช้ต้องมีความยาวอย่างน้อย 3 ตัวอักษร";
            $alertType = 'error';
        } else {
            $check1 = $pdo->prepare("SELECT COUNT(*) FROM `resellers` WHERE `username` = ?");
            $check1->execute([$rUsername]);
            $check2 = $pdo->prepare("SELECT COUNT(*) FROM `admins` WHERE `username` = ?");
            $check2->execute([$rUsername]);

            if ($check1->fetchColumn() > 0 || $check2->fetchColumn() > 0) {
                $alert = "ชื่อผู้ใช้ '{$rUsername}' มีอยู่ในระบบแล้ว กรุณาเลือกชื่ออื่น";
                $alertType = 'error';
            } else {
                $expiresAt = null;
                if ($rExpiryPreset === '7') {
                    $expiresAt = date('Y-m-d 23:59:59', strtotime('+7 days'));
                } elseif ($rExpiryPreset === '30') {
                    $expiresAt = date('Y-m-d 23:59:59', strtotime('+30 days'));
                } elseif ($rExpiryPreset === '90') {
                    $expiresAt = date('Y-m-d 23:59:59', strtotime('+90 days'));
                } elseif ($rExpiryPreset === '365') {
                    $expiresAt = date('Y-m-d 23:59:59', strtotime('+365 days'));
                } elseif ($rExpiryPreset === 'never') {
                    $expiresAt = null;
                } elseif ($rExpiryPreset === 'custom' && !empty($rCustomExpiry)) {
                    $expiresAt = date('Y-m-d H:i:s', strtotime($rCustomExpiry));
                }

                $hash = password_hash($rPassword, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO `resellers` (`username`, `password`, `name`, `credits`, `expires_at`, `status`, `note`) VALUES (?, ?, ?, ?, ?, 'active', ?)");
                $stmt->execute([$rUsername, $hash, $rName, $rCredits, $expiresAt, $rNote]);

                $alert = "เพิ่มตัวแทนจำหน่าย '{$rUsername}' สำเร็จ! (เครดิตเริ่มต้น: {$rCredits})";
            }
        }
    }

    // 9. EDIT RESELLER CREDITS (Admin only)
    if ($action === 'edit_reseller_credits' && $isAdmin) {
        $rId = (int)($_POST['reseller_id'] ?? 0);
        $mode = $_POST['credit_mode'] ?? 'add';
        $amount = (int)($_POST['amount'] ?? 0);

        if ($rId > 0 && $amount >= 0) {
            $stmt = $pdo->prepare("SELECT username, credits FROM `resellers` WHERE `id` = ?");
            $stmt->execute([$rId]);
            $reseller = $stmt->fetch();

            if ($reseller) {
                $current = (int)$reseller['credits'];
                $newCredits = $current;
                if ($mode === 'add') {
                    $newCredits = $current + $amount;
                } elseif ($mode === 'deduct') {
                    $newCredits = max(0, $current - $amount);
                } elseif ($mode === 'set') {
                    $newCredits = max(0, $amount);
                }

                $pdo->prepare("UPDATE `resellers` SET `credits` = ? WHERE `id` = ?")->execute([$newCredits, $rId]);
                $alert = "อัปเดตเครดิตของตัวแทน {$reseller['username']} สำเร็จ! ยอดใหม่: {$newCredits} เครดิต";
            }
        }
    }

    // 10. EDIT RESELLER EXPIRATION (Admin only)
    if ($action === 'edit_reseller_expiry' && $isAdmin) {
        $rId = (int)($_POST['reseller_id'] ?? 0);
        $mode = $_POST['expiry_mode'] ?? 'custom';
        $customDate = trim($_POST['custom_date'] ?? '');

        if ($rId > 0) {
            $stmt = $pdo->prepare("SELECT expires_at FROM `resellers` WHERE `id` = ?");
            $stmt->execute([$rId]);
            $currExp = $stmt->fetchColumn();

            $baseTime = (!empty($currExp) && strtotime($currExp) > time()) ? strtotime($currExp) : time();
            $newExpiry = null;

            if ($mode === '+7') {
                $newExpiry = date('Y-m-d 23:59:59', strtotime('+7 days', $baseTime));
            } elseif ($mode === '+30') {
                $newExpiry = date('Y-m-d 23:59:59', strtotime('+30 days', $baseTime));
            } elseif ($mode === '+90') {
                $newExpiry = date('Y-m-d 23:59:59', strtotime('+90 days', $baseTime));
            } elseif ($mode === '+365') {
                $newExpiry = date('Y-m-d 23:59:59', strtotime('+365 days', $baseTime));
            } elseif ($mode === 'never') {
                $newExpiry = null;
            } elseif ($mode === 'custom' && !empty($customDate)) {
                $newExpiry = date('Y-m-d H:i:s', strtotime($customDate));
            }

            $pdo->prepare("UPDATE `resellers` SET `expires_at` = ? WHERE `id` = ?")->execute([$newExpiry, $rId]);
            $alert = "บันทึกวันหมดอายุของตัวแทนสำเร็จ!" . ($newExpiry ? " (หมดอายุ: " . date('d/m/Y H:i', strtotime($newExpiry)) . ")" : " (ตลอดชีพ)");
        }
    }

    // 11. TOGGLE RESELLER STATUS (Admin only)
    if ($action === 'toggle_reseller_status' && $isAdmin) {
        $rId = (int)($_POST['reseller_id'] ?? 0);
        if ($rId > 0) {
            $stmt = $pdo->prepare("SELECT status, username FROM `resellers` WHERE `id` = ?");
            $stmt->execute([$rId]);
            $res = $stmt->fetch();
            if ($res) {
                $newStat = ($res['status'] === 'active') ? 'inactive' : 'active';
                $pdo->prepare("UPDATE `resellers` SET `status` = ? WHERE `id` = ?")->execute([$newStat, $rId]);
                $alert = "เปลี่ยนสถานะของตัวแทน {$res['username']} เป็น " . ($newStat === 'active' ? 'เปิดใช้งาน' : 'ระงับการใช้งาน') . " สำเร็จ!";
            }
        }
    }

    // 12. RESET RESELLER PASSWORD (Admin only)
    if ($action === 'reset_reseller_password' && $isAdmin) {
        $rId = (int)($_POST['reseller_id'] ?? 0);
        $newPass = trim($_POST['new_password'] ?? '');
        if ($rId > 0 && strlen($newPass) >= 4) {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE `resellers` SET `password` = ? WHERE `id` = ?")->execute([$hash, $rId]);
            $alert = "เปลี่ยนรหัสผ่านของตัวแทนเรียบร้อยแล้ว!";
        } else {
            $alert = "รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 4 ตัวอักษร";
            $alertType = 'error';
        }
    }

    // 13. DELETE RESELLER (Admin only)
    if ($action === 'delete_reseller' && $isAdmin) {
        $rId = (int)($_POST['reseller_id'] ?? 0);
        if ($rId > 0) {
            $pdo->prepare("UPDATE `licenses` SET `reseller_id` = NULL WHERE `reseller_id` = ?")->execute([$rId]);
            $pdo->prepare("DELETE FROM `resellers` WHERE `id` = ?")->execute([$rId]);
            $alert = "ลบตัวแทนจำหน่ายเรียบร้อยแล้ว (คีย์ที่สร้างไว้ยังคงอยู่ในระบบ)";
        }
    }

    // 14. UPLOAD CLOUD PATCH (OTA)
    if ($action === 'upload_cloud_patch' && $isAdmin) {
        ensureCloudPatchesTable($pdo);
        $patchName = trim($_POST['patch_name'] ?? '');
        $targetFilename = trim($_POST['target_filename'] ?? '');
        $version = trim($_POST['version'] ?? '1.0');
        $description = trim($_POST['description'] ?? '');

        if (empty($patchName) || empty($targetFilename)) {
            $alert = "กรุณากรอกชื่อแพตช์และชื่อไฟล์เป้าหมาย (Target Filename)";
            $alertType = 'error';
        } elseif (empty($_FILES['patch_file']['tmp_name']) || $_FILES['patch_file']['error'] !== UPLOAD_ERR_OK) {
            $alert = "กรุณาเลือกไฟล์แพตช์ที่ต้องการอัปโหลด (.3105)";
            $alertType = 'error';
        } else {
            $uploadDir = __DIR__ . '/uploads/patches/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }

            $origName = $_FILES['patch_file']['name'];
            $ext = pathinfo($origName, PATHINFO_EXTENSION);
            if (empty($ext)) $ext = '3105';

            $safeTarget = preg_replace('/[^a-zA-Z0-9_\-\.\(\)\s]/', '', $targetFilename);
            if (empty($safeTarget)) $safeTarget = 'patch.3105';
            if (!preg_match('/\.3105$/i', $safeTarget) && !preg_match('/\.(ogios|dat|bin)$/i', $safeTarget)) {
                $safeTarget .= '.3105';
            }

            $storageFilename = 'patch_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $uploadDir . $storageFilename;
            $relativeDbPath = 'uploads/patches/' . $storageFilename;

            if (move_uploaded_file($_FILES['patch_file']['tmp_name'], $destPath)) {
                $fileSize = filesize($destPath);
                $fileHash = hash_file('sha256', $destPath);

                // Upsert to cloud_patches
                $stmt = $pdo->prepare("INSERT INTO `cloud_patches` (`patch_name`, `target_filename`, `version`, `file_path`, `file_size`, `file_hash`, `description`, `is_active`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE 
                        `patch_name` = VALUES(`patch_name`),
                        `version` = VALUES(`version`),
                        `file_path` = VALUES(`file_path`),
                        `file_size` = VALUES(`file_size`),
                        `file_hash` = VALUES(`file_hash`),
                        `description` = VALUES(`description`),
                        `is_active` = 1,
                        `updated_at` = NOW()");
                $stmt->execute([$patchName, $safeTarget, $version, $relativeDbPath, $fileSize, $fileHash, $description]);

                $alert = "อัปโหลดและเปิดใช้งาน Cloud Patch '{$safeTarget}' (v{$version}) เรียบร้อยแล้ว!";
            } else {
                $alert = "ไม่สามารถบันทึกไฟล์อัปโหลดลงบนเซิร์ฟเวอร์ได้ ตรวจสอบสิทธิ์โฟลเดอร์ uploads/";
                $alertType = 'error';
            }
        }
    }

    // 15. TOGGLE CLOUD PATCH
    if ($action === 'toggle_cloud_patch' && $isAdmin) {
        $pId = (int)($_POST['patch_id'] ?? 0);
        $state = (int)($_POST['state'] ?? 0);
        if ($pId > 0) {
            $pdo->prepare("UPDATE `cloud_patches` SET `is_active` = ? WHERE `id` = ?")->execute([$state, $pId]);
            $alert = $state ? "เปิดใช้งาน Cloud Patch เรียบร้อย" : "ปิดการใช้งาน Cloud Patch ชั่วคราว";
        }
    }

    // 16. DELETE CLOUD PATCH
    if ($action === 'delete_cloud_patch' && $isAdmin) {
        $pId = (int)($_POST['patch_id'] ?? 0);
        if ($pId > 0) {
            $stmt = $pdo->prepare("SELECT `file_path` FROM `cloud_patches` WHERE `id` = ?");
            $stmt->execute([$pId]);
            $fp = $stmt->fetchColumn();
            if ($fp && file_exists(__DIR__ . '/' . ltrim($fp, '/\\'))) {
                @unlink(__DIR__ . '/' . ltrim($fp, '/\\'));
            }
            $pdo->prepare("DELETE FROM `cloud_patches` WHERE `id` = ?")->execute([$pId]);
            $alert = "ลบ Cloud Patch เรียบร้อยแล้ว";
        }
    }
}

// ----------------- DATA QUERIES & ROUTING -----------------
$tab = $_GET['tab'] ?? 'dashboard';

if ($isAdmin) {
    $validTabs = ['dashboard', 'licenses', 'generator', 'devices', 'resellers', 'remote', 'patches', 'logs', 'settings'];
} else {
    $validTabs = ['dashboard', 'licenses', 'generator', 'devices'];
}
if (!in_array($tab, $validTabs, true)) {
    $tab = 'dashboard';
}

// Statistics
if ($isAdmin) {
    $totalKeys = (int)$pdo->query("SELECT COUNT(*) FROM `licenses`")->fetchColumn();
    $activeKeys = (int)$pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` = 'active'")->fetchColumn();
    $unusedKeys = (int)$pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` = 'unused'")->fetchColumn();
    $expiredKeys = (int)$pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` = 'expired'")->fetchColumn();
    $bannedKeys = (int)$pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` = 'banned'")->fetchColumn();
    $boundDevicesCount = (int)$pdo->query("SELECT COUNT(DISTINCT device_id) FROM `licenses` WHERE device_id IS NOT NULL AND device_id != ''")->fetchColumn();

    $totalResellers = (int)$pdo->query("SELECT COUNT(*) FROM `resellers`")->fetchColumn();
    $activeResellers = (int)$pdo->query("SELECT COUNT(*) FROM `resellers` WHERE `status` = 'active' AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn();
    $totalResellerCredits = (int)$pdo->query("SELECT SUM(credits) FROM `resellers`")->fetchColumn();
    $totalResellerKeys = (int)$pdo->query("SELECT COUNT(*) FROM `licenses` WHERE reseller_id IS NOT NULL")->fetchColumn();
} else {
    $cnt = function ($extra = '') use ($pdo, $currentResellerId) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM `licenses` WHERE `reseller_id` = ? " . $extra);
        $s->execute([$currentResellerId]);
        return (int)$s->fetchColumn();
    };
    $totalKeys = $cnt();
    $activeKeys = $cnt("AND `status` = 'active'");
    $unusedKeys = $cnt("AND `status` = 'unused'");
    $expiredKeys = $cnt("AND `status` = 'expired'");
    $bannedKeys = $cnt("AND `status` = 'banned'");
    $boundDevicesCount = $cnt("AND device_id IS NOT NULL AND device_id != ''");
    $boundDevicesCount = (int)$boundDevicesCount;
    // distinct count for reseller
    $s = $pdo->prepare("SELECT COUNT(DISTINCT device_id) FROM `licenses` WHERE `reseller_id` = ? AND device_id IS NOT NULL AND device_id != ''");
    $s->execute([$currentResellerId]);
    $boundDevicesCount = (int)$s->fetchColumn();
    $totalResellers = $activeResellers = $totalResellerCredits = $totalResellerKeys = 0;
}

// Chart Data: Last 7 Days Activity
$sevenDaysActivations = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `activity_logs` WHERE `action` = 'ACTIVATED_SUCCESS' AND DATE(`created_at`) = ?");
        $stmt->execute([$date]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `activity_logs` al JOIN `licenses` l ON al.license_key = l.license_key WHERE al.action = 'ACTIVATED_SUCCESS' AND DATE(al.created_at) = ? AND l.reseller_id = ?");
        $stmt->execute([$date, $currentResellerId]);
    }
    $sevenDaysActivations[] = [
        'day' => date('M d', strtotime($date)),
        'count' => (int)$stmt->fetchColumn()
    ];
}

// Fetch Licenses with Filtering
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$filterResellerId = !empty($_GET['reseller']) ? (int)$_GET['reseller'] : 0;

$sql = "SELECT * FROM `licenses` WHERE 1=1";
$params = [];

if ($isReseller) {
    $sql .= " AND `reseller_id` = ?";
    $params[] = $currentResellerId;
} elseif ($filterResellerId > 0) {
    $sql .= " AND `reseller_id` = ?";
    $params[] = $filterResellerId;
}

if ($statusFilter && in_array($statusFilter, ['active', 'unused', 'expired', 'banned'])) {
    $sql .= " AND `status` = ?";
    $params[] = $statusFilter;
}

if ($search !== '') {
    $sql .= " AND (`license_key` LIKE ? OR `device_id` LIKE ? OR `device_name` LIKE ? OR `note` LIKE ? OR `created_by` LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

$sql .= " ORDER BY `id` DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$licenses = $stmt->fetchAll();

// Resellers list
$allResellersList = [];
if ($isAdmin) {
    $allResellersList = $pdo->query("
        SELECT r.*, 
            COUNT(l.id) AS total_keys,
            SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) AS active_keys
        FROM `resellers` r
        LEFT JOIN `licenses` l ON l.reseller_id = r.id
        GROUP BY r.id
        ORDER BY r.id DESC
    ")->fetchAll();
}

// Recent Logs
if ($isAdmin) {
    $recentLogs = $pdo->query("SELECT * FROM `activity_logs` ORDER BY `id` DESC LIMIT 50")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT al.* FROM `activity_logs` al JOIN `licenses` l ON al.license_key = l.license_key WHERE l.reseller_id = ? ORDER BY al.id DESC LIMIT 30");
    $stmt->execute([$currentResellerId]);
    $recentLogs = $stmt->fetchAll();
}

// API URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$apiEndpointURL = "{$protocol}{$host}{$uri}/api.php";

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function getResellerExpiryBadge($expiresAt) {
    if (empty($expiresAt)) {
        return '<span class="badge" style="background:rgba(59,130,246,0.12);color:#60a5fa;border:1px solid rgba(59,130,246,0.3);">ตลอดชีพ</span>';
    }
    $expTime = strtotime($expiresAt);
    $diffSecs = $expTime - time();
    $dateStr = date('d/m/Y H:i', $expTime);
    if ($diffSecs <= 0) {
        return '<span class="badge reseller-expired" title="' . e($dateStr) . '">หมดอายุแล้ว (' . e(date('d/m/Y', $expTime)) . ')</span>';
    }
    $diffDays = ceil($diffSecs / 86400);
    if ($diffDays <= 3) {
        return '<span class="badge reseller-expiring" title="' . e($dateStr) . '">เหลือ ' . $diffDays . ' วัน (' . e(date('d/m/Y', $expTime)) . ')</span>';
    }
    return '<span class="badge reseller-active" title="' . e($dateStr) . '">เหลือ ' . $diffDays . ' วัน (' . e(date('d/m/Y', $expTime)) . ')</span>';
}

$sysSettings = ($isAdmin && $tab === 'remote') ? getSystemSettings($pdo) : [];
$cloudPatches = ($isAdmin && $tab === 'patches') ? (function() use ($pdo) {
    ensureCloudPatchesTable($pdo);
    return $pdo->query("SELECT * FROM `cloud_patches` ORDER BY `id` DESC")->fetchAll();
})() : [];

$pageTitles = [
    'dashboard' => ['Dashboard', $isAdmin ? 'ภาพรวมระบบและสถิติคีย์ทั้งหมด' : 'ภาพรวมบัญชีตัวแทนและสถิติคีย์ของคุณ'],
    'licenses'  => ['License Keys', $isAdmin ? 'จัดการ ค้นหา และควบคุมคีย์ทั้งหมด' : 'รายการคีย์ที่คุณสร้างและการผูกอุปกรณ์'],
    'generator' => ['สร้างคีย์', $isAdmin ? 'สร้างคีย์เดี่ยวหรือแบบแบตช์ได้ไม่จำกัด' : 'สร้างคีย์ลูกค้าโดยหักจากเครดิตของคุณ (1 คีย์ = 1 เครดิต)'],
    'devices'   => ['อุปกรณ์', 'อุปกรณ์ที่ผูกกับคีย์ (Hardware ID)'],
    'resellers' => ['ตัวแทน', 'จัดการตัวแทนจำหน่าย วันหมดอายุ และเครดิต'],
    'remote'    => ['Remote Control', 'สวิตช์ฉุกเฉิน ประกาศเข้าแอป และบังคับอัปเดตเวอร์ชั่น'],
    'patches'   => ['Cloud Patches (OTA)', 'จัดการไฟล์แพตช์สูตร (.3105) อัปเดตไปยังแอปของลูกค้าอัตโนมัติ'],
    'logs'      => ['Activity Logs', 'บันทึกความปลอดภัยและการใช้งาน'],
    'settings'  => ['ตั้งค่า', 'ความปลอดภัยและการเชื่อมต่อ API'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>XSTH License <?= $isAdmin ? 'Admin' : 'Reseller' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
<style>
:root {
  --bg: #000000;
  --bg-subtle: #080808;
  --panel: #0d0d0d;
  --panel-hover: #141414;
  --panel-2: #121212;
  --ink: #ffffff;
  --muted: #a3a3a3;
  --dim: #525252;
  --line: #1c1c1c;
  --line-strong: #2e2e2e;
  --accent: #ffffff;
  --accent-hover: #e5e5e5;
  --accent-soft: rgba(255, 255, 255, 0.08);
  --ok: #22c55e;
  --ok-soft: rgba(34, 197, 94, 0.12);
  --warn: #eab308;
  --warn-soft: rgba(234, 179, 8, 0.12);
  --bad: #ef4444;
  --bad-soft: rgba(239, 68, 68, 0.12);
  --violet: #a855f7;
  --violet-soft: rgba(168, 85, 247, 0.12);
  --cyan: #38bdf8;
  --r: 12px;
  --sb: 256px;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Plus Jakarta Sans', 'Noto Sans Thai', system-ui, -apple-system, sans-serif;
  background: var(--bg);
  color: var(--ink);
  font-size: 14px;
  line-height: 1.6;
  min-height: 100vh;
  -webkit-font-smoothing: antialiased;
}
a { color: inherit; text-decoration: none; }
button, input, select, textarea { font-family: inherit; font-size: inherit; }
:focus-visible { outline: 2px solid #525252; outline-offset: 2px; }

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-thumb { background: #262626; border-radius: 6px; }
::-webkit-scrollbar-thumb:hover { background: #404040; }
::-webkit-scrollbar-track { background: transparent; }

/* ============ SIDEBAR ============ */
.sidebar {
  position: fixed; inset: 0 auto 0 0;
  width: var(--sb);
  background: var(--bg);
  border-right: 1px solid var(--line);
  display: flex; flex-direction: column;
  z-index: 50;
  transition: transform .25s ease;
}
.brand {
  padding: 24px 20px 20px;
  display: flex; align-items: center; gap: 12px;
  border-bottom: 1px solid var(--line);
}
.brand-icon {
  width: 38px; height: 38px; border-radius: 10px;
  background: #ffffff; color: #000000;
  display: grid; place-items: center;
  font-size: 16px; font-weight: 800;
  flex-shrink: 0;
}
.brand-title {
  font-size: 16px; font-weight: 800;
  letter-spacing: 0.3px; line-height: 1.2;
}
.brand-title small {
  display: block; font-size: 11px; font-weight: 600;
  color: var(--dim); letter-spacing: 0.6px; text-transform: uppercase; margin-top: 2px;
}

.nav {
  flex: 1; padding: 16px 12px;
  display: flex; flex-direction: column; gap: 3px;
  overflow-y: auto;
}
.nav-gap { height: 12px; }
.nav a {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 14px; border-radius: 8px;
  color: var(--muted); font-weight: 600; font-size: 13.5px;
  transition: all .15s ease;
  border: 1px solid transparent;
}
.nav a i {
  width: 18px; text-align: center; font-size: 15px;
  color: var(--dim); transition: color .15s;
}
.nav a:hover {
  background: var(--panel-hover);
  color: #fff;
}
.nav a:hover i { color: #fff; }
.nav a.active {
  background: #141414;
  color: #fff;
  border-color: var(--line-strong);
}
.nav a.active i { color: #fff; }
.nav .n {
  margin-left: auto; font-size: 11px; font-weight: 700;
  padding: 2px 7px; border-radius: 6px;
  background: #171717; color: var(--muted); border: 1px solid var(--line);
}
.nav a.active .n {
  background: #262626; color: #fff; border-color: #404040;
}

.me {
  padding: 14px 16px; border-top: 1px solid var(--line);
  display: flex; align-items: center; gap: 12px;
  background: #050505;
}
.me .av {
  width: 36px; height: 36px; border-radius: 10px;
  background: #171717; border: 1px solid var(--line-strong);
  color: #fff; display: grid; place-items: center;
  font-weight: 800; font-size: 14px; flex-shrink: 0;
}
.me div { flex: 1; min-width: 0; }
.me b {
  display: block; font-size: 13.5px; font-weight: 700;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #fff;
}
.me small { font-size: 11.5px; color: var(--dim); }
.me a.logout-btn {
  width: 32px; height: 32px; border-radius: 8px;
  display: grid; place-items: center;
  color: var(--dim); transition: all .15s;
  background: #121212; border: 1px solid var(--line);
}
.me a.logout-btn:hover {
  color: #fff; background: var(--bad); border-color: var(--bad);
}

/* ============ MAIN LAYOUT ============ */
.main { margin-left: var(--sb); min-height: 100vh; display: flex; flex-direction: column; background: var(--bg); }
.topbar {
  display: flex; align-items: center; gap: 16px;
  padding: 18px 36px;
  background: var(--bg);
  border-bottom: 1px solid var(--line);
  position: sticky; top: 0; z-index: 30;
}
.topbar h1 {
  font-size: 20px; font-weight: 800; letter-spacing: -0.3px; line-height: 1.2; color: #fff;
}
.topbar p { font-size: 13px; color: var(--muted); margin-top: 2px; }
.grow { flex: 1; min-width: 0; }
.menu-btn {
  display: none; width: 38px; height: 38px;
  border: 1px solid var(--line); background: var(--panel);
  color: var(--ink); border-radius: 8px; cursor: pointer;
}
.content {
  padding: 24px 36px 64px;
  max-width: 1600px; width: 100%;
}
.overlay { display: none; }

/* ============ CARDS & METRICS ============ */
.card {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: var(--r);
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.8);
  transition: border-color .15s ease;
}
.card:hover { border-color: var(--line-strong); }
.card-head {
  display: flex; justify-content: space-between; align-items: center;
  gap: 12px; flex-wrap: wrap;
  padding: 18px 22px; border-bottom: 1px solid var(--line);
}
.card-head h3 {
  font-size: 15px; font-weight: 700; letter-spacing: -0.2px;
  display: flex; align-items: center; gap: 10px; color: #fff;
}
.card-head h3 i { color: var(--muted); }
.card-body { padding: 22px; }

.stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px; margin-bottom: 22px;
}
.stat {
  padding: 18px 20px;
  position: relative; overflow: hidden;
  background: var(--panel); border: 1px solid var(--line);
  border-radius: var(--r);
  transition: border-color .15s ease;
}
.stat:hover { border-color: #333333; }
.stat-top {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 8px;
}
.stat span {
  color: var(--muted); font-size: 12px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.5px;
}
.stat-icon {
  width: 32px; height: 32px; border-radius: 8px;
  display: grid; place-items: center; font-size: 14px;
  background: #141414; border: 1px solid var(--line); color: var(--muted);
}
.stat b {
  display: block; font-size: 28px; font-weight: 800;
  letter-spacing: -0.5px; line-height: 1.1; color: #ffffff;
}
.stat small { font-size: 12px; color: var(--dim); margin-top: 4px; display: block; }

.charts {
  display: grid; grid-template-columns: 2fr 1fr;
  gap: 16px; margin-bottom: 22px;
}
.chart-box { height: 250px; position: relative; }

/* ============ BUTTONS ============ */
.btn {
  display: inline-flex; align-items: center; justify-content: center;
  gap: 8px; height: 38px; padding: 0 16px; border-radius: 8px;
  border: 1px solid var(--line-strong); background: #171717;
  color: #fff; font-weight: 600; font-size: 13px;
  cursor: pointer; white-space: nowrap; transition: all .15s ease;
}
.btn:hover {
  background: #242424; border-color: #404040;
}
.btn:active { transform: scale(0.99); }
.btn-primary {
  background: #ffffff !important;
  border-color: #ffffff !important;
  color: #000000 !important;
  box-shadow: 0 2px 8px rgba(255, 255, 255, 0.15);
}
.btn-primary:hover {
  background: #e5e5e5 !important;
  border-color: #e5e5e5 !important;
}
.btn-sm { height: 32px; padding: 0 12px; font-size: 12px; border-radius: 6px; }
.btn-danger {
  background: var(--bad-soft); border-color: rgba(239, 68, 68, 0.4); color: #fca5a5;
}
.btn-danger:hover {
  background: var(--bad); color: #fff; border-color: var(--bad);
}
.btn[disabled] { opacity: .35; cursor: not-allowed; }
.danger-text { color: var(--bad); }


/* ============ REMOTE CONTROL ============ */
.rc-banner { display:flex; align-items:center; gap:16px; flex-wrap:wrap; padding:20px 22px; border-radius:var(--r); border:1px solid var(--line-strong); background:var(--panel); margin-bottom:16px; }
.rc-banner.on { border-color:rgba(239,68,68,.5); background:rgba(239,68,68,.08); }
.rc-dot { width:12px; height:12px; border-radius:50%; background:var(--ok); box-shadow:0 0 0 4px rgba(34,197,94,.15); flex:none; }
.rc-banner.on .rc-dot { background:var(--bad); box-shadow:0 0 0 4px rgba(239,68,68,.2); animation:rcpulse 1.2s infinite; }
@keyframes rcpulse { 50% { box-shadow:0 0 0 8px rgba(239,68,68,0); } }
.rc-banner .rc-txt { flex:1; min-width:220px; }
.rc-banner .rc-txt b { display:block; font-size:15px; }
.rc-banner .rc-txt span { font-size:12.5px; color:var(--muted); }
textarea.input { height:auto; min-height:88px; padding:10px 14px; resize:vertical; line-height:1.5; font-family:inherit; }
.rc-check { display:flex; align-items:center; gap:10px; margin-bottom:16px; font-size:13.5px; font-weight:600; cursor:pointer; }
.rc-check input { width:18px; height:18px; accent-color:#fff; cursor:pointer; }

/* ============ INPUTS & FORMS ============ */
.input {
  width: 100%; height: 42px; padding: 0 14px;
  border: 1px solid var(--line-strong); border-radius: 8px;
  background: #080808; color: #ffffff; font-size: 13.5px;
  outline: none; transition: border-color .15s ease;
}
.input:focus {
  border-color: #525252;
  box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.1);
  background: #0c0c0c;
}
select.input {
  cursor: pointer;
  background-image: linear-gradient(45deg, transparent 50%, #737373 50%), linear-gradient(135deg, #737373 50%, transparent 50%);
  background-position: calc(100% - 16px) 50%, calc(100% - 11px) 50%;
  background-size: 5px 5px; background-repeat: no-repeat;
  padding-right: 36px;
  appearance: none; -webkit-appearance: none;
}
select.input option { background: #121212; color: #fff; }
.field { margin-bottom: 16px; }
.field label {
  display: block; font-weight: 600; font-size: 13px;
  color: #d4d4d4; margin-bottom: 6px;
}
.hint { display: block; margin-top: 5px; font-size: 12px; color: var(--dim); }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

/* ============ TABLE ============ */
.table-wrap { overflow-x: auto; width: 100%; }
table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
th {
  text-align: left; padding: 12px 18px; font-size: 11.5px; font-weight: 700;
  color: #737373; text-transform: uppercase; letter-spacing: .5px;
  border-bottom: 1px solid var(--line); background: #080808;
  white-space: nowrap;
}
td {
  padding: 13px 18px; border-bottom: 1px solid var(--line);
  vertical-align: middle;
}
tr:last-child td { border-bottom: none; }
tbody tr { transition: background .12s ease; }
tbody tr:hover { background: #121212; }
td.empty { text-align: center; color: var(--dim); padding: 44px 20px; font-size: 13.5px; }

/* Key chip */
.key {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 13px; font-weight: 700; color: #ffffff;
  background: #141414; padding: 5px 10px; border-radius: 6px;
  border: 1px solid var(--line-strong); cursor: pointer;
  display: inline-flex; align-items: center; gap: 7px;
  transition: all .12s ease;
}
.key:hover {
  background: #242424; border-color: #525252;
}
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
.dim { color: var(--dim); font-size: 12.5px; }

/* Badges */
.badge {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 3px 9px; border-radius: 6px;
  font-size: 11.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .4px; border: 1px solid transparent;
}
.badge::before {
  content: ''; width: 5px; height: 5px; border-radius: 50%;
  background: currentColor;
}
.badge.active, .badge.reseller-active {
  color: #4ade80; background: var(--ok-soft); border-color: rgba(74, 222, 128, 0.3);
}
.badge.unused, .badge.reseller-expiring {
  color: #facc15; background: var(--warn-soft); border-color: rgba(250, 204, 21, 0.3);
}
.badge.expired, .badge.reseller-expired {
  color: #f87171; background: var(--bad-soft); border-color: rgba(248, 113, 113, 0.3);
}
.badge.banned {
  color: #c084fc; background: var(--violet-soft); border-color: rgba(192, 132, 252, 0.3);
}

.tag {
  display: inline-block; padding: 2px 8px; border-radius: 6px;
  font-size: 12px; font-weight: 600;
  background: #141414; border: 1px solid var(--line);
  color: var(--muted);
}
.tag.admin {
  background: var(--warn-soft); border-color: rgba(234, 179, 8, 0.3); color: #facc15;
}

/* Actions in Table */
.actions { display: flex; gap: 5px; align-items: center; }
.act {
  width: 30px; height: 30px; border-radius: 6px;
  border: 1px solid var(--line); background: #121212;
  color: var(--muted); cursor: pointer; display: inline-grid; place-items: center;
  font-size: 12px; transition: all .12s ease;
}
.act:hover {
  background: #242424; color: #fff;
  border-color: var(--line-strong);
}
.act.danger:hover {
  color: #f87171; border-color: rgba(239, 68, 68, 0.4);
  background: var(--bad-soft);
}

/* Toolbar & Filters */
.filters { display: flex; gap: 5px; flex-wrap: wrap; }
.chip {
  padding: 6px 12px; border-radius: 6px; font-size: 12.5px; font-weight: 600;
  color: var(--muted); background: #121212;
  border: 1px solid var(--line); transition: all .12s ease;
}
.chip:hover { background: #1e1e1e; color: #fff; }
.chip.active {
  background: #ffffff; color: #000000;
  border-color: #ffffff;
}
.toolbar-right { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.search { display: flex; gap: 6px; }
.search input { width: 230px; }

/* Generated Keys Box */
.newkeys {
  background: rgba(34, 197, 94, 0.06); border: 1px solid rgba(34, 197, 94, 0.25);
  border-radius: var(--r); padding: 16px 20px; margin-bottom: 20px;
}
.newkeys-head {
  display: flex; justify-content: space-between; align-items: center;
  gap: 10px; flex-wrap: wrap;
}
.newkeys h3 { font-size: 14.5px; font-weight: 800; color: #4ade80; }
.newkeys textarea {
  width: 100%; height: 110px; margin-top: 10px; padding: 10px;
  border: 1px solid var(--line-strong); border-radius: 8px;
  background: #000000; color: #4ade80;
  font-family: ui-monospace, Consolas, monospace; font-size: 13px;
  resize: vertical; outline: none;
}

.notice {
  padding: 12px 14px; border-radius: 8px; font-size: 13px;
  margin-bottom: 16px; border: 1px solid;
}
.notice.bad {
  background: var(--bad-soft); border-color: rgba(239, 68, 68, 0.3); color: #fca5a5;
}
.notice.warn {
  background: var(--warn-soft); border-color: rgba(234, 179, 8, 0.3); color: #fde047;
}

/* Generator & Summary */
.gen-grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 18px; align-items: start; }
.sum-row {
  display: flex; justify-content: space-between; padding: 10px 0;
  border-bottom: 1px solid var(--line); font-size: 13.5px;
}
.sum-row:last-child { border: none; }
.sum-row span { color: var(--muted); }
.keybox {
  font-family: ui-monospace, Consolas, monospace; font-size: 15px; font-weight: 700;
  text-align: center; padding: 14px; background: #050505; color: #ffffff;
  border: 1px solid var(--line-strong); border-radius: 8px;
  margin-bottom: 8px; letter-spacing: 0.8px;
}

.overview { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 20px; }
.overview .card { padding: 18px 20px; }
.overview .lbl { color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.overview .big { font-size: 28px; font-weight: 800; line-height: 1.2; margin-top: 4px; color: #fff; }
.overview .big.sm { font-size: 19px; padding: 4px 0; }
.overview .sub { font-size: 12px; color: var(--dim); margin-top: 4px; }
.credit-bar {
  height: 4px; background: #222222;
  border-radius: 4px; margin: 8px 0; overflow: hidden;
}
.credit-bar i { display: block; height: 100%; background: #ffffff; border-radius: 4px; }
.pill {
  display: inline-block; padding: 2px 8px; border-radius: 6px;
  background: #1c1c1c; color: #ffffff; font-size: 11.5px; font-weight: 600;
}
.pill.bad { background: var(--bad-soft); color: #f87171; }

/* ============ MODAL ============ */
.modal {
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.85);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  display: none; align-items: center; justify-content: center;
  padding: 16px; z-index: 100;
}
.modal.show { display: flex; }
.modal-box {
  background: #0d0d0d;
  border: 1px solid var(--line-strong);
  border-radius: 12px;
  width: 100%; max-width: 440px; max-height: 90vh;
  overflow: auto;
  box-shadow: 0 25px 60px rgba(0, 0, 0, 0.9);
}
.modal-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 16px 20px; border-bottom: 1px solid var(--line);
}
.modal-head h3 { font-size: 15px; font-weight: 700; color: #fff; }
.modal-head button {
  width: 30px; height: 30px; border-radius: 6px;
  border: none; background: #171717;
  font-size: 13px; color: var(--muted); cursor: pointer;
  display: grid; place-items: center;
}
.modal-head button:hover { background: #262626; color: #fff; }
.modal-body { padding: 20px; }
.modal-foot {
  display: flex; justify-content: flex-end; gap: 8px;
  padding: 14px 20px; border-top: 1px solid var(--line);
  background: #080808;
}
.who {
  background: #050505; border: 1px solid var(--line);
  border-radius: 8px; padding: 10px 14px; margin-bottom: 16px;
}

/* ============ TOAST ============ */
.toasts {
  position: fixed; top: 18px; right: 18px; z-index: 200;
  display: flex; flex-direction: column; gap: 8px;
}
.toast {
  background: #141414;
  border: 1px solid var(--line-strong);
  color: #fff; padding: 11px 16px; border-radius: 8px;
  font-size: 13px; font-weight: 600;
  max-width: 360px; display: flex; gap: 10px; align-items: center;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.8);
}
.toast i { font-size: 14px; color: var(--ok); }
.toast.error { border-color: rgba(239, 68, 68, 0.4); }
.toast.error i { color: var(--bad); }

@media (max-width: 1100px) {
  .stats { grid-template-columns: repeat(2, 1fr); }
  .charts, .gen-grid { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
  .sidebar { transform: translateX(-100%); }
  html.open .sidebar { transform: none; }
  html.open .overlay { display: block; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.8); z-index: 40; }
  .main { margin-left: 0; }
  .menu-btn { display: block; }
  .topbar { padding: 14px 18px; }
  .content { padding: 16px 18px 48px; }
  .overview { grid-template-columns: 1fr; }
}
@media (max-width: 700px) {
  .topbar p, .btn-label { display: none; }
  .grid-2 { grid-template-columns: 1fr; }
  .search, .search input { width: 100%; }
  .toolbar-right { width: 100%; }
  table.responsive thead { display: none; }
  table.responsive, table.responsive tbody, table.responsive tr, table.responsive td { display: block; width: 100%; }
  table.responsive tr { padding: 10px 14px; border-bottom: 6px solid var(--bg); background: var(--panel); border-radius: 8px; margin-bottom: 6px; }
  table.responsive td { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border: none; text-align: right; }
  table.responsive td::before { content: attr(data-label); color: var(--muted); font-size: 12px; text-align: left; }
  table.responsive td.empty::before { display: none; }
}
</style>
</head>
<body>
<div class="toasts" id="toastStack"></div>

<aside class="sidebar">
  <div class="brand">
    <div class="brand-icon"><i class="fa-solid fa-shield-halved"></i></div>
    <div class="brand-title">
      XSTH 
      <small><?= $isAdmin ? 'License Admin' : 'Reseller Portal' ?></small>
    </div>
  </div>
  <nav class="nav">
    <a href="index.php?tab=dashboard" class="<?= $tab==='dashboard'?'active':'' ?>"><i class="fa-solid fa-chart-simple"></i>Dashboard</a>
    <a href="index.php?tab=licenses" class="<?= $tab==='licenses'?'active':'' ?>"><i class="fa-solid fa-key"></i><?= $isAdmin?'License Keys':'My Licenses' ?><span class="n"><?= (int)$totalKeys ?></span></a>
    <a href="index.php?tab=generator" class="<?= $tab==='generator'?'active':'' ?>"><i class="fa-solid fa-plus"></i>สร้างคีย์</a>
    <a href="index.php?tab=devices" class="<?= $tab==='devices'?'active':'' ?>"><i class="fa-solid fa-mobile-screen-button"></i>อุปกรณ์<span class="n"><?= (int)$boundDevicesCount ?></span></a>
    <?php if ($isAdmin): ?>
    <div class="nav-gap"></div>
    <a href="index.php?tab=resellers" class="<?= $tab==='resellers'?'active':'' ?>"><i class="fa-solid fa-users"></i>ตัวแทน<span class="n"><?= (int)$totalResellers ?></span></a>
    <a href="index.php?tab=remote" class="<?= $tab==='remote'?'active':'' ?>"><i class="fa-solid fa-tower-broadcast"></i>Remote Control</a>
    <a href="index.php?tab=patches" class="<?= $tab==='patches'?'active':'' ?>"><i class="fa-solid fa-cloud-arrow-up"></i>Cloud Patches (OTA)</a>
    <a href="index.php?tab=logs" class="<?= $tab==='logs'?'active':'' ?>"><i class="fa-solid fa-clock-rotate-left"></i>Activity Logs</a>
    <a href="index.php?tab=settings" class="<?= $tab==='settings'?'active':'' ?>"><i class="fa-solid fa-gear"></i>ตั้งค่า</a>
    <?php endif; ?>
  </nav>
  <div class="me">
    <div class="av"><?= e(strtoupper(substr($currentDisplayName,0,1))) ?></div>
    <div><b><?= e($currentDisplayName) ?></b><small><?= $isAdmin ? 'Super Admin' : number_format($currentResellerCredits).' เครดิต' ?></small></div>
    <a href="logout.php" class="logout-btn" title="ออกจากระบบ"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
  </div>
</aside>
<div class="overlay" id="overlay"></div>

<div class="main">
  <header class="topbar">
    <button class="menu-btn" id="menuBtn" aria-label="เมนู"><i class="fa-solid fa-bars"></i></button>
    <div class="grow"><h1><?= e($pageTitles[$tab][0]) ?></h1><p><?= e($pageTitles[$tab][1]) ?></p></div>
    <?php if ($isAdmin && $tab === 'resellers'): ?>
      <button type="button" onclick="openModal('modalAddReseller')" class="btn btn-primary"><i class="fa-solid fa-plus"></i><span class="btn-label">เพิ่มตัวแทน</span></button>
    <?php elseif ($tab !== 'generator'): ?>
      <a href="index.php?tab=generator" class="btn btn-primary"><i class="fa-solid fa-plus"></i><span class="btn-label">สร้างคีย์</span></a>
    <?php endif; ?>
  </header>

  <main class="content">
  <?php if (!empty($newlyGeneratedKeys)): ?>
    <div class="newkeys">
      <div class="newkeys-head">
        <h3>สร้างคีย์สำเร็จ <?= count($newlyGeneratedKeys) ?> รายการ</h3>
        <div style="display:flex;gap:6px">
          <button onclick="copyText(document.getElementById('generatedKeysText').value,'คัดลอกคีย์ทั้งหมดแล้ว')" class="btn btn-sm"><i class="fa-regular fa-copy"></i> คัดลอกทั้งหมด</button>
          <button onclick="downloadKeysText()" class="btn btn-sm"><i class="fa-solid fa-download"></i> บันทึก .txt</button>
        </div>
      </div>
      <textarea id="generatedKeysText" readonly><?= e(implode("\n",$newlyGeneratedKeys)) ?></textarea>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'dashboard'): ?>
    <?php if ($isReseller): ?>
    <div class="overview">
      <div class="card">
        <div class="lbl">เครดิตคงเหลือ</div>
        <div class="big"><?= number_format($currentResellerCredits) ?></div>
        <div class="credit-bar"><i style="width:<?= min(100,max(3,$currentResellerCredits*2)) ?>%"></i></div>
        <div class="sub">1 เครดิต = 1 คีย์ ตัดเมื่อสร้างสำเร็จ</div>
      </div>
      <div class="card">
        <div class="lbl">อายุบัญชีตัวแทน</div>
        <div class="big sm"><?= empty($resellerExpiresAt) ? 'ตลอดชีพ' : e(date('d/m/Y H:i',strtotime($resellerExpiresAt))) ?></div>
        <?php if (!$isResellerActive): ?>
          <span class="pill bad"><?= $isResellerExpired ? 'หมดอายุแล้ว' : 'ถูกระงับ' ?></span>
        <?php elseif (!empty($resellerExpiresAt)): ?>
          <span class="pill">เหลืออีก <?= (int)ceil((strtotime($resellerExpiresAt)-time())/86400) ?> วัน</span>
        <?php else: ?><span class="pill">ใช้งานได้ปกติ</span><?php endif; ?>
      </div>
      <div class="card">
        <div class="lbl">คีย์ของคุณ</div>
        <div class="big"><?= number_format($totalKeys) ?></div>
        <div class="sub">ใช้งานอยู่ <?= number_format($activeKeys) ?> · ยังไม่ใช้ <?= number_format($unusedKeys) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="stats">
      <div class="card stat">
        <div class="stat-top"><span>คีย์ทั้งหมด</span><div class="stat-icon"><i class="fa-solid fa-key" style="color:#818cf8;"></i></div></div>
        <b><?= number_format($totalKeys) ?></b>
        <small>จำนวนคีย์ในระบบทั้งหมด</small>
      </div>
      <div class="card stat">
        <div class="stat-top"><span>ใช้งานอยู่</span><div class="stat-icon"><i class="fa-solid fa-circle-check" style="color:#34d399;"></i></div></div>
        <b style="color:#34d399"><?= number_format($activeKeys) ?></b>
        <small>ผูกอุปกรณ์แล้ว</small>
      </div>
      <div class="card stat">
        <div class="stat-top"><span>ยังไม่ใช้</span><div class="stat-icon"><i class="fa-solid fa-hourglass-half" style="color:#fbbf24;"></i></div></div>
        <b style="color:#fbbf24"><?= number_format($unusedKeys) ?></b>
        <small>พร้อมแจกหรือขาย</small>
      </div>
      <div class="card stat">
        <div class="stat-top"><span>อุปกรณ์ที่ผูกแล้ว</span><div class="stat-icon"><i class="fa-solid fa-mobile-screen-button" style="color:#38bdf8;"></i></div></div>
        <b><?= number_format($boundDevicesCount) ?></b>
        <small>HWID ไม่ซ้ำกัน</small>
      </div>
    </div>
    <?php if ($isAdmin): ?>
    <div class="stats">
      <div class="card stat">
        <div class="stat-top"><span>ตัวแทนทั้งหมด</span><div class="stat-icon"><i class="fa-solid fa-users" style="color:#c084fc;"></i></div></div>
        <b><?= number_format($totalResellers) ?></b>
        <small>บัญชีตัวแทนในระบบ</small>
      </div>
      <div class="card stat">
        <div class="stat-top"><span>ตัวแทนที่ใช้งานได้</span><div class="stat-icon"><i class="fa-solid fa-user-check" style="color:#34d399;"></i></div></div>
        <b style="color:#34d399"><?= number_format($activeResellers) ?></b>
        <small>สถานะปกติและยังไม่หมดอายุ</small>
      </div>
      <div class="card stat">
        <div class="stat-top"><span>เครดิตคงค้างรวม</span><div class="stat-icon"><i class="fa-solid fa-coins" style="color:#fbbf24;"></i></div></div>
        <b><?= number_format($totalResellerCredits) ?></b>
        <small>เครดิตที่ตัวแทนถือครอง</small>
      </div>
      <div class="card stat">
        <div class="stat-top"><span>คีย์สร้างโดยตัวแทน</span><div class="stat-icon"><i class="fa-solid fa-shield-halved" style="color:#38bdf8;"></i></div></div>
        <b><?= number_format($totalResellerKeys) ?></b>
        <small>คีย์ที่ตัวแทนกดสร้าง</small>
      </div>
    </div>
    <?php endif; ?>

    <div class="charts">
      <div class="card"><div class="card-head"><h3>การเปิดใช้งาน 7 วันล่าสุด</h3></div><div class="card-body"><div class="chart-box"><canvas id="activationsChart"></canvas></div></div></div>
      <div class="card"><div class="card-head"><h3>สถานะคีย์</h3></div><div class="card-body"><div class="chart-box"><canvas id="statusChart"></canvas></div></div></div>
    </div>

    <div class="card">
      <div class="card-head"><h3>กิจกรรมล่าสุด</h3><?php if ($isAdmin): ?><a href="index.php?tab=logs" class="btn btn-sm">ดูทั้งหมด</a><?php endif; ?></div>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>คีย์</th><th>เหตุการณ์</th><th>HWID</th><th>รุ่น</th><th>IP</th><th>เวลา</th></tr></thead>
        <tbody>
        <?php if (empty($recentLogs)): ?><tr><td colspan="6" class="empty">ยังไม่มีกิจกรรม</td></tr>
        <?php else: foreach (array_slice($recentLogs,0,6) as $log): ?>
          <tr>
            <td data-label="คีย์"><span class="key" data-copy="<?= e($log['license_key']) ?>"><?= e($log['license_key']) ?></span></td>
            <td data-label="เหตุการณ์"><span class="tag"><?= e($log['action']) ?></span></td>
            <td data-label="HWID" class="mono dim"><?= e(substr($log['device_id'] ?? '-',0,16)) ?></td>
            <td data-label="รุ่น"><?= e($log['device_name'] ?: 'iOS') ?></td>
            <td data-label="IP" class="dim"><?= e($log['ip_address']) ?></td>
            <td data-label="เวลา" class="dim"><?= e(date('d M, H:i',strtotime($log['created_at']))) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
    </div>

  <?php elseif ($tab === 'licenses'): ?>
    <?php $rq = $filterResellerId ? '&reseller='.$filterResellerId : ''; ?>
    <div class="card">
      <div class="card-head">
        <div class="filters">
          <a href="index.php?tab=licenses<?= $rq ?>" class="chip <?= $statusFilter===''?'active':'' ?>">ทั้งหมด <?= (int)$totalKeys ?></a>
          <a href="index.php?tab=licenses&status=unused<?= $rq ?>" class="chip <?= $statusFilter==='unused'?'active':'' ?>">ยังไม่ใช้ <?= (int)$unusedKeys ?></a>
          <a href="index.php?tab=licenses&status=active<?= $rq ?>" class="chip <?= $statusFilter==='active'?'active':'' ?>">ใช้งานอยู่ <?= (int)$activeKeys ?></a>
          <a href="index.php?tab=licenses&status=expired<?= $rq ?>" class="chip <?= $statusFilter==='expired'?'active':'' ?>">หมดอายุ <?= (int)$expiredKeys ?></a>
          <a href="index.php?tab=licenses&status=banned<?= $rq ?>" class="chip <?= $statusFilter==='banned'?'active':'' ?>">ถูกแบน <?= (int)$bannedKeys ?></a>
        </div>
        <div class="toolbar-right">
          <button onclick="exportTableToCSV()" class="btn btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
          <form method="GET" class="search">
            <input type="hidden" name="tab" value="licenses">
            <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
            <?php if ($filterResellerId): ?><input type="hidden" name="reseller" value="<?= (int)$filterResellerId ?>"><?php endif; ?>
            <input type="text" name="q" class="input" style="height:32px" placeholder="ค้นหาคีย์, HWID, โน้ต..." value="<?= e($search) ?>">
            <button class="btn btn-sm" aria-label="ค้นหา"><i class="fa-solid fa-magnifying-glass"></i></button>
          </form>
        </div>
      </div>
      <div class="table-wrap"><table id="licensesTable" class="responsive">
        <thead><tr><th>คีย์</th><th>ระยะเวลา</th><th>สถานะ</th><?php if ($isAdmin): ?><th>สร้างโดย</th><?php endif; ?><th>อุปกรณ์</th><th>เปิดใช้</th><th>หมดอายุ</th><th>โน้ต</th><th>จัดการ</th></tr></thead>
        <tbody>
        <?php if (empty($licenses)): ?><tr><td colspan="<?= $isAdmin?9:8 ?>" class="empty">ไม่พบข้อมูลที่ตรงกับเงื่อนไข</td></tr>
        <?php else: foreach ($licenses as $l): ?>
          <tr>
            <td data-label="คีย์"><span class="key" data-copy="<?= e($l['license_key']) ?>" title="คลิกเพื่อคัดลอก"><?= e($l['license_key']) ?></span></td>
            <td data-label="ระยะเวลา"><?= $l['duration_days']>0 ? (int)$l['duration_days'].' วัน' : '<span style="color:var(--violet)">ตลอดชีพ</span>' ?></td>
            <td data-label="สถานะ"><span class="badge <?= e($l['status']) ?>"><?= e($l['status']) ?></span></td>
            <?php if ($isAdmin): ?><td data-label="สร้างโดย"><?= $l['created_by']==='admin' ? '<span class="tag admin">admin</span>' : '<span class="tag">'.e($l['created_by']).'</span>' ?></td><?php endif; ?>
            <td data-label="อุปกรณ์"><?php if ($l['device_id']): ?><div class="mono" style="font-size:12px" title="<?= e($l['device_id']) ?>"><?= e(substr($l['device_id'],0,14)) ?>…</div><div class="dim"><?= e($l['device_name'] ?: 'iOS') ?></div><?php else: ?><span class="dim">ยังไม่ผูก</span><?php endif; ?></td>
            <td data-label="เปิดใช้" class="dim"><?= $l['activated_at'] ? e(date('d M Y',strtotime($l['activated_at']))) : '-' ?></td>
            <td data-label="หมดอายุ"><?php if ($l['expires_at']): ?><span style="font-size:13px;color:<?= strtotime($l['expires_at'])<time()?'var(--bad)':'var(--ok)' ?>"><?= e(date('d M Y',strtotime($l['expires_at']))) ?></span><?php else: ?><span class="dim"><?= $l['duration_days']==0 ? 'ไม่หมดอายุ' : 'ยังไม่เริ่ม' ?></span><?php endif; ?></td>
            <td data-label="โน้ต" class="dim"><?= e($l['note'] ?: '-') ?></td>
            <td data-label="จัดการ"><div class="actions">
              <form method="POST" onsubmit="return requestActionConfirm(event, this, &#39;รีเซ็ต HWID&#39;, &#39;ปลดล็อกเครื่องให้กับคีย์นี้ เพื่อให้ลูกค้านำไปใช้กับเครื่องใหม่ได้ทันที&#39;, &#39;fa-solid fa-arrows-rotate&#39;, false, &#39;รีเซ็ตเครื่อง&#39;)"><input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="act" title="รีเซ็ต HWID"><i class="fa-solid fa-arrows-rotate"></i></button></form>
              <form method="POST" onsubmit="return requestActionConfirm(event, this, &#39;สุ่มรหัสคีย์ใหม่ (Re-Key)&#39;, &#39;สุ่มรหัสคีย์ใหม่แทนที่คีย์เดิมและปลดล็อกเครื่องทันที โดยไม่หักเครดิตเพิ่ม&#39;, &#39;fa-solid fa-shuffle&#39;, false, &#39;สุ่มคีย์ใหม่&#39;)"><input type="hidden" name="action" value="rekey"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="act" title="รีคีย์"><i class="fa-solid fa-shuffle"></i></button></form>
              <?php if ($isAdmin): ?>
              <form method="POST" onsubmit="return confirm('เพิ่ม 30 วัน ให้คีย์นี้?')"><input type="hidden" name="action" value="extend_days"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="days" value="30"><button class="act" title="+30 วัน"><i class="fa-solid fa-calendar-plus"></i></button></form>
              <form method="POST"><input type="hidden" name="action" value="toggle_ban"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="act" title="<?= $l['status']==='banned'?'ปลดแบน':'แบน' ?>"><i class="fa-solid <?= $l['status']==='banned'?'fa-check':'fa-ban' ?>"></i></button></form>
              <?php endif; ?>
              <form method="POST" onsubmit="return requestActionConfirm(event, this, &#39;ลบคีย์นี้ถาวร&#39;, &#39;ต้องการลบคีย์นี้ออกจากระบบถาวรหรือไม่? การดำเนินการนี้ไม่สามารถเรียกคืนได้&#39;, &#39;fa-solid fa-trash-can&#39;, true, &#39;ลบคีย์ถาวร&#39;)"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="act danger" title="ลบ"><i class="fa-solid fa-trash-can"></i></button></form>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
    </div>

  <?php elseif ($tab === 'generator'): ?>
    <?php $genOff = $isReseller && (!$isResellerActive || $currentResellerCredits <= 0); ?>
    <div class="gen-grid">
      <div class="card">
        <div class="card-body">
          <?php if ($isReseller && !$isResellerActive): ?>
            <div class="notice bad"><?= $isResellerExpired ? 'บัญชีตัวแทนหมดอายุแล้ว กรุณาติดต่อแอดมินเพื่อต่ออายุ' : 'บัญชีถูกระงับชั่วคราว กรุณาติดต่อแอดมิน' ?></div>
          <?php elseif ($isReseller && $currentResellerCredits <= 0): ?>
            <div class="notice warn">เครดิตหมดแล้ว กรุณาติดต่อแอดมินเพื่อเติมเครดิต</div>
          <?php endif; ?>
          <form method="POST" id="genForm">
            <input type="hidden" name="action" value="generate">
            <div class="grid-2">
              <div class="field">
                <label>คำนำหน้าคีย์</label>
                <input type="text" name="prefix" id="keyPrefixInput" class="input" value="XSTH" maxlength="10" list="prefixList" required>
                <datalist id="prefixList"><option value="XSTH"><option value="VIP"><option value="PRO"><option value="PLUS"></datalist>
              </div>
              <div class="field">
                <label>ระยะเวลาใช้งาน</label>
                <select name="duration" id="durationSelect" class="input">
                  <option value="1">1 วัน (ทดลอง)</option><option value="3">3 วัน</option><option value="7">7 วัน</option>
                  <option value="15">15 วัน</option><option value="30" selected>30 วัน</option><option value="90">90 วัน</option>
                  <option value="365">365 วัน</option><option value="0">ตลอดชีพ</option>
                </select>
              </div>
            </div>
            <div class="grid-2">
              <div class="field">
                <label>จำนวนคีย์</label>
                <input type="number" name="count" id="genCountInput" class="input" value="1" min="1" max="<?= $isReseller ? max(1,min(100,$currentResellerCredits)) : 100 ?>" <?= $genOff?'disabled':'' ?> required>
                <span class="hint"><?= $isReseller ? 'สูงสุดตามเครดิตคงเหลือ และไม่เกิน 100 คีย์ต่อครั้ง' : 'สูงสุด 100 คีย์ต่อครั้ง' ?></span>
              </div>
              <div class="field">
                <label>โน้ต / ชื่อลูกค้า</label>
                <input type="text" name="note" class="input" placeholder="เช่น คุณเอ ขายวันที่ 15">
              </div>
            </div>
            <?php if ($isAdmin && !empty($allResellersList)): ?>
            <div class="field">
              <label>เจ้าของคีย์</label>
              <select name="assigned_reseller_id" class="input">
                <option value="">Admin (ไม่ระบุตัวแทน)</option>
                <?php foreach ($allResellersList as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?> (@<?= e($r['username']) ?>) · เครดิต <?= (int)$r['credits'] ?></option><?php endforeach; ?>
              </select>
              <span class="hint">คีย์จะนับเป็นของตัวแทนที่เลือก โดยไม่หักเครดิตของตัวแทน</span>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" style="width:100%;height:44px" <?= $genOff?'disabled':'' ?>><i class="fa-solid fa-plus"></i><span id="btnGenText"><?= $isReseller ? 'สร้าง 1 คีย์ (หัก 1 เครดิต)' : 'สร้างคีย์' ?></span></button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h3>สรุป</h3></div>
        <div class="card-body">
          <div class="keybox" id="liveKeyPreview">XSTH-8A91F2-3C4D05-77B2</div>
          <div class="dim" style="text-align:center;margin-bottom:12px">ตัวอย่างรูปแบบคีย์</div>
          <div class="sum-row"><span>ระยะเวลา</span><b id="previewDurationText">30 วัน</b></div>
          <div class="sum-row"><span>ผูกเครื่อง</span><b>อัตโนมัติเมื่อเปิดใช้ครั้งแรก</b></div>
          <div class="sum-row"><span>รีเซ็ต / รีคีย์</span><b>ฟรี</b></div>
          <?php if ($isReseller): ?>
          <div class="sum-row"><span>เครดิตคงเหลือ</span><b><?= number_format($currentResellerCredits) ?></b></div>
          <div class="sum-row"><span>เครดิตที่จะหัก</span><b id="calcDeductText" style="color:var(--bad)">-1</b></div>
          <div class="sum-row"><span>คงเหลือสุทธิ</span><b id="calcRemainText"><?= number_format(max(0,$currentResellerCredits-1)) ?></b></div>
          <div style="margin-top:12px"><span id="calcStatusBadge" class="pill <?= $currentResellerCredits>=1?'':'bad' ?>"><?= $currentResellerCredits>=1 ? 'เครดิตเพียงพอ' : 'เครดิตไม่เพียงพอ' ?></span></div>
          <div class="hint" style="margin-top:12px">1 เครดิต = 1 คีย์ ไม่ว่าจะเลือกอายุกี่วัน บัญชีหมดอายุ: <?= getResellerExpiryBadge($resellerExpiresAt) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'resellers' && $isAdmin): ?>
    <div class="stats">
      <div class="card stat">
        <div class="stat-top"><span>ตัวแทนทั้งหมด</span><div class="stat-icon"><i class="fa-solid fa-users" style="color:#c084fc;"></i></div></div>
        <b><?= number_format($totalResellers) ?></b>
        <small>บัญชีตัวแทนทั้งหมด</small>
      </div>
      <div class="card stat">
        <div class="stat-top"><span>ใช้งานได้</span><div class="stat-icon"><i class="fa-solid fa-user-check" style="color:#34d399;"></i></div></div>
        <b style="color:#34d399"><?= number_format($activeResellers) ?></b>
        <small>สถานะพร้อมใช้งาน</small>
      </div>
      <div class="card stat">
        <div class="stat-top"><span>เครดิตรวม</span><div class="stat-icon"><i class="fa-solid fa-coins" style="color:#fbbf24;"></i></div></div>
        <b><?= number_format($totalResellerCredits) ?></b>
        <small>ยอดเครดิตสะสมตัวแทน</small>
      </div>
      <div class="card stat">
        <div class="stat-top"><span>คีย์ที่สร้าง</span><div class="stat-icon"><i class="fa-solid fa-key" style="color:#38bdf8;"></i></div></div>
        <b><?= number_format($totalResellerKeys) ?></b>
        <small>ยอดสร้างคีย์รวมของตัวแทน</small>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>รายชื่อตัวแทน</h3></div>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>ตัวแทน</th><th>เครดิต</th><th>วันหมดอายุ</th><th>สถานะ</th><th>คีย์</th><th>สร้างเมื่อ</th><th>จัดการ</th></tr></thead>
        <tbody>
        <?php if (empty($allResellersList)): ?><tr><td colspan="7" class="empty">ยังไม่มีตัวแทน กด "เพิ่มตัวแทน" เพื่อเริ่มต้น</td></tr>
        <?php else: foreach ($allResellersList as $r): ?>
          <tr>
            <td data-label="ตัวแทน"><b><?= e($r['name']) ?></b><div class="dim">@<?= e($r['username']) ?><?= !empty($r['note']) ? ' · '.e($r['note']) : '' ?></div></td>
            <td data-label="เครดิต"><b><?= number_format($r['credits']) ?></b></td>
            <td data-label="วันหมดอายุ"><?= getResellerExpiryBadge($r['expires_at']) ?></td>
            <td data-label="สถานะ"><form method="POST"><input type="hidden" name="action" value="toggle_reseller_status"><input type="hidden" name="reseller_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" title="คลิกเพื่อสลับสถานะ"><?= $r['status']==='active' ? '<span style="color:var(--ok)">เปิดใช้งาน</span>' : '<span style="color:var(--bad)">ระงับ</span>' ?></button></form></td>
            <td data-label="คีย์"><a href="index.php?tab=licenses&reseller=<?= (int)$r['id'] ?>" style="color:var(--accent)"><?= number_format($r['total_keys']) ?> คีย์</a></td>
            <td data-label="สร้างเมื่อ" class="dim"><?= date('d/m/Y',strtotime($r['created_at'])) ?></td>
            <td data-label="จัดการ"><div class="actions">
              <button type="button" class="act" title="จัดการเครดิต" onclick='openCreditModal(<?= json_encode($r) ?>)'><i class="fa-solid fa-coins"></i></button>
              <button type="button" class="act" title="วันหมดอายุ" onclick='openExpiryModal(<?= json_encode($r) ?>)'><i class="fa-solid fa-calendar-days"></i></button>
              <button type="button" class="act" title="รีเซ็ตรหัสผ่าน" onclick='openPasswordModal(<?= json_encode($r) ?>)'><i class="fa-solid fa-lock"></i></button>
              <form method="POST" onsubmit="return requestActionConfirm(event, this, &#39;ลบตัวแทนจำหน่าย&#39;, &#39;ต้องการลบตัวแทนนี้หรือไม่? คีย์ที่สร้างไว้ทั้งหมดจะยังคงอยู่&#39;, &#39;fa-solid fa-user-xmark&#39;, true, &#39;ลบตัวแทน&#39;)"><input type="hidden" name="action" value="delete_reseller"><input type="hidden" name="reseller_id" value="<?= (int)$r['id'] ?>"><button class="act danger" title="ลบตัวแทน"><i class="fa-solid fa-trash-can"></i></button></form>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
    </div>

  <?php elseif ($tab === 'devices'): ?>
    <?php
    if ($isAdmin) { $devices = $pdo->query("SELECT * FROM `licenses` WHERE `device_id` IS NOT NULL AND `device_id` != '' ORDER BY `activated_at` DESC")->fetchAll(); }
    else { $stmt = $pdo->prepare("SELECT * FROM `licenses` WHERE `reseller_id` = ? AND `device_id` IS NOT NULL AND `device_id` != '' ORDER BY `activated_at` DESC"); $stmt->execute([$currentResellerId]); $devices = $stmt->fetchAll(); }
    ?>
    <div class="card">
      <div class="card-head"><h3>อุปกรณ์ที่ผูกอยู่ <span class="dim">(<?= (int)$boundDevicesCount ?> เครื่อง)</span></h3></div>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>HWID</th><th>รุ่น</th><th>คีย์</th><th>หมดอายุ</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($devices)): ?><tr><td colspan="5" class="empty">ยังไม่มีอุปกรณ์ที่ผูกไว้</td></tr>
        <?php else: foreach ($devices as $d): ?>
          <tr>
            <td data-label="HWID" class="mono" style="font-size:12px;word-break:break-all"><?= e($d['device_id']) ?></td>
            <td data-label="รุ่น"><?= e($d['device_name'] ?: 'iOS Device') ?></td>
            <td data-label="คีย์"><span class="key" data-copy="<?= e($d['license_key']) ?>"><?= e($d['license_key']) ?></span></td>
            <td data-label="หมดอายุ" class="dim"><?= $d['expires_at'] ? e(date('d M Y',strtotime($d['expires_at']))) : 'ตลอดชีพ' ?></td>
            <td><form method="POST" onsubmit="return confirm('ปลดล็อกเครื่องนี้เพื่อให้ผู้ใช้เปลี่ยนมือถือได้?')"><input type="hidden" name="action" value="reset_hwid"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm"><i class="fa-solid fa-link-slash"></i> รีเซ็ต HWID</button></form></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
    </div>

  <?php elseif ($tab === 'logs' && $isAdmin): ?>
    <div class="card">
      <div class="card-head"><h3>บันทึกกิจกรรม</h3>
        <form method="POST" onsubmit="return confirm('ล้างบันทึกทั้งหมด?')"><input type="hidden" name="action" value="clear_logs"><button class="btn btn-sm danger-text"><i class="fa-solid fa-broom"></i> ล้างบันทึก</button></form></div>
      <div class="table-wrap"><table class="responsive">
        <thead><tr><th>เวลา</th><th>คีย์</th><th>เหตุการณ์</th><th>HWID</th><th>รุ่น</th><th>IP</th></tr></thead>
        <tbody>
        <?php if (empty($recentLogs)): ?><tr><td colspan="6" class="empty">ไม่พบบันทึก</td></tr>
        <?php else: foreach ($recentLogs as $log): ?>
          <tr>
            <td data-label="เวลา" class="dim"><?= e(date('Y-m-d H:i:s',strtotime($log['created_at']))) ?></td>
            <td data-label="คีย์"><span class="key" data-copy="<?= e($log['license_key']) ?>"><?= e($log['license_key']) ?></span></td>
            <td data-label="เหตุการณ์"><?php $cls = strpos($log['action'],'SUCCESS')!==false ? 'active' : ((strpos($log['action'],'BLOCKED')!==false||strpos($log['action'],'FAILED')!==false) ? 'expired' : 'unused'); ?><span class="badge <?= $cls ?>"><?= e($log['action']) ?></span></td>
            <td data-label="HWID" class="mono dim" style="word-break:break-all"><?= e($log['device_id'] ?: '-') ?></td>
            <td data-label="รุ่น"><?= e($log['device_name'] ?: 'iOS') ?></td>
            <td data-label="IP" class="dim"><?= e($log['ip_address']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
    </div>

  <?php elseif ($tab === 'remote' && $isAdmin): ?>
    <?php $ks = $sysSettings['kill_switch_enabled'] === '1'; ?>
    <div class="rc-banner <?= $ks ? 'on' : '' ?>">
      <span class="rc-dot"></span>
      <div class="rc-txt">
        <b><?= $ks ? 'Emergency Maintenance: เปิดอยู่' : 'ระบบทำงานปกติ' ?></b>
        <span><?= $ks ? 'แอปลูกค้าทุกเครื่องถูกล็อก ไม่สามารถเปิดสูตรได้ จนกว่าจะปิดสวิตช์' : 'ลูกค้าสามารถใช้งานได้ตามปกติ กดปุ่มเมื่อเกมมีอัปเดตใหญ่หรือมีคลื่นแบน' ?></span>
      </div>
      <form method="POST" onsubmit="return requestActionConfirm(event, this, <?= $ks ? '&quot;ปิด Emergency Maintenance&quot;' : '&quot;เปิด Emergency Maintenance&quot;' ?>, <?= $ks ? '&quot;ลูกค้าทุกคนจะกลับมาเปิดสูตรได้ตามปกติ&quot;' : '&quot;แอปลูกค้าทุกเครื่องจะถูกล็อกทันที และแสดงข้อความแจ้งเตือน&quot;' ?>, &quot;fa-solid fa-power-off&quot;, <?= $ks ? 'false' : 'true' ?>, <?= $ks ? '&quot;ปิดสวิตช์&quot;' : '&quot;ปิดระบบทันที&quot;' ?>)">
        <input type="hidden" name="action" value="toggle_kill_switch">
        <input type="hidden" name="state" value="<?= $ks ? '0' : '1' ?>">
        <button class="btn <?= $ks ? 'btn-primary' : 'btn-danger' ?>"><i class="fa-solid fa-power-off"></i> <?= $ks ? 'ปิด Maintenance' : 'เปิด Emergency Maintenance' ?></button>
      </form>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:16px">
      <div class="card"><div class="card-head"><h3><i class="fa-solid fa-triangle-exclamation"></i>ข้อความ Maintenance</h3></div>
        <form method="POST" class="card-body"><input type="hidden" name="action" value="save_kill_message">
          <div class="field"><label>ข้อความที่แสดงในแอปลูกค้า</label>
            <textarea name="kill_switch_message" class="input" maxlength="1000" required><?= e($sysSettings['kill_switch_message']) ?></textarea></div>
          <button class="btn btn-primary">บันทึกข้อความ</button></form></div>

      <div class="card"><div class="card-head"><h3><i class="fa-solid fa-bullhorn"></i>ประกาศเข้าแอป (MOTD)</h3></div>
        <form method="POST" class="card-body"><input type="hidden" name="action" value="save_announcement">
          <label class="rc-check"><input type="checkbox" name="announcement_enabled" value="1" <?= $sysSettings['announcement_enabled']==='1' ? 'checked' : '' ?>> แสดงประกาศในแอปลูกค้า</label>
          <div class="field"><label>หัวข้อ</label><input class="input" name="announcement_title" maxlength="120" value="<?= e($sysSettings['announcement_title']) ?>"></div>
          <div class="field"><label>ข้อความ</label><textarea name="announcement_message" class="input" maxlength="1000"><?= e($sysSettings['announcement_message']) ?></textarea></div>
          <div class="grid-2">
            <div class="field"><label>ลิงก์ (ไม่บังคับ)</label><input class="input" name="announcement_link" placeholder="https://t.me/..." value="<?= e($sysSettings['announcement_link']) ?>"></div>
            <div class="field"><label>ข้อความบนปุ่ม</label><input class="input" name="announcement_button_text" maxlength="40" value="<?= e($sysSettings['announcement_button_text']) ?>"></div>
          </div>
          <button class="btn btn-primary">บันทึกประกาศ</button></form></div>

      <div class="card"><div class="card-head"><h3><i class="fa-solid fa-cloud-arrow-down"></i>Force Update &amp; Version Control</h3></div>
        <form method="POST" class="card-body"><input type="hidden" name="action" value="save_version_control">
          <label class="rc-check"><input type="checkbox" name="force_update_enabled" value="1" <?= $sysSettings['force_update_enabled']==='1' ? 'checked' : '' ?>> บังคับอัปเดต (เวอร์ชั่นเก่ากว่าด้านล่างจะใช้งานไม่ได้)</label>
          <div class="field"><label>เวอร์ชั่นล่าสุด</label><input class="input" name="latest_app_version" placeholder="1.1.2" pattern="\d+(\.\d+){0,3}" value="<?= e($sysSettings['latest_app_version']) ?>" required></div>
          <div class="field"><label>ลิงก์ดาวน์โหลด .ipa</label><input class="input" name="update_download_url" placeholder="https://.../XSTEAM.ipa" value="<?= e($sysSettings['update_download_url']) ?>"></div>
          <div class="field"><label>รายละเอียดอัปเดต (Changelog)</label><textarea name="update_changelog" class="input" maxlength="1000"><?= e($sysSettings['update_changelog']) ?></textarea></div>
          <button class="btn btn-primary">บันทึกเวอร์ชั่น</button></form></div>

      <div class="card"><div class="card-head"><h3><i class="fa-solid fa-code"></i>วิธีเรียกจากแอป</h3></div>
        <div class="card-body">
          <div class="dim">เรียกตอนเปิดแอป (ไม่ต้องมีคีย์) แล้วอ่าน <span class="mono">remote_config</span></div>
          <pre class="mono" style="font-size:12px;background:var(--bg);padding:12px;border-radius:8px;overflow-x:auto;margin:8px 0 12px"><?= e($apiEndpointURL) ?>?action=remote_config&amp;app_version=1.1.0</pre>
          <div class="dim">ค่า <span class="mono">status</span> ที่แอปต้องรองรับ: <span class="mono">maintenance</span> (ล็อกแอป + แสดง message), <span class="mono">update_required</span> (แสดงปุ่มโหลด IPA จาก <span class="mono">remote_config.version_control.download_url</span>), <span class="mono">success</span> / <span class="mono">error</span>. ส่ง <span class="mono">app_version</span> ทุกครั้งที่เรียก API เพื่อให้เซิร์ฟเวอร์เช็กเวอร์ชั่นให้</div>
        </div></div>
    </div>

  <?php elseif ($tab === 'patches' && $isAdmin): ?>
    <?php
      $totalCloudPatches = count($cloudPatches);
      $activeCloudPatches = count(array_filter($cloudPatches, fn($p) => (int)$p['is_active'] === 1));
      $totalCloudDownloads = array_sum(array_column($cloudPatches, 'downloads_count'));
    ?>
    <div class="stats" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
      <div class="stat"><div class="stat-top"><span>ไฟล์แพตช์ทั้งหมด</span><div class="stat-icon"><i class="fa-solid fa-file-code"></i></div></div><b><?= number_format($totalCloudPatches) ?></b><small>รายการแพตช์ในระบบ</small></div>
      <div class="stat"><div class="stat-top"><span>เปิดใช้งาน (Active OTA)</span><div class="stat-icon"><i class="fa-solid fa-cloud-arrow-down" style="color:#4ade80"></i></div></div><b style="color:#4ade80"><?= number_format($activeCloudPatches) ?></b><small>แอปสามารถซิงค์ได้ทันที</small></div>
      <div class="stat"><div class="stat-top"><span>ยอดดาวน์โหลดรวม</span><div class="stat-icon"><i class="fa-solid fa-download" style="color:#60a5fa"></i></div></div><b style="color:#60a5fa"><?= number_format($totalCloudDownloads) ?></b><small>ครั้งที่แอปซิงค์ไฟล์สำเร็จ</small></div>
    </div>

    <div class="grid-2" style="margin-bottom:20px;align-items:start">
      <!-- Upload Card -->
      <div class="card">
        <div class="card-head">
          <h3><i class="fa-solid fa-cloud-arrow-up"></i>อัปโหลดสูตรใหม่ (.3105) ขึ้น Cloud OTA</h3>
        </div>
        <form method="POST" enctype="multipart/form-data" class="card-body">
          <input type="hidden" name="action" value="upload_cloud_patch">

          <div class="field">
            <label>ไฟล์เป้าหมายในแอป (Target Filename)</label>
            <div style="display:flex;gap:8px">
              <input class="input" name="target_filename" id="targetFilenameInput" placeholder="เช่น XSTH.3105" required>
              <select class="input" style="width:190px" onchange="if(this.value){document.getElementById('targetFilenameInput').value=this.value;}">
                <option value="">-- เลือกจากสูตรหลัก --</option>
                <option value="XSTH.3105">XSTH.3105 (Aim Drag)</option>
                <option value="OGIOS File (6).3105">OGIOS File (6).3105</option>
                <option value="OGIOS File (7).3105">OGIOS File (7).3105 (Aim Neck)</option>
                <option value="OGIOS File (8).3105">OGIOS File (8).3105 (HS Peit Off)</option>
                <option value="OGIOS File (10).3105">OGIOS File (10).3105 (144 FPS)</option>
                <option value="OGIOS File (12).3105">OGIOS File (12).3105 (Aim Body)</option>
                <option value="OGIOS File (2).3105">OGIOS File (2).3105 (Aim Chest)</option>
                <option value="OGIOS File (14).3105">OGIOS File (14).3105 (Magic Bullet)</option>
              </select>
            </div>
            <span class="hint">ต้องตรงกับชื่อไฟล์สูตรที่แอปเรียกใช้ (นามสกุล .3105)</span>
          </div>

          <div class="grid-2">
            <div class="field">
              <label>ชื่อแพตช์ / สูตร</label>
              <input class="input" name="patch_name" placeholder="เช่น Aim Drag Headshot v2.0" required>
            </div>
            <div class="field">
              <label>เลขเวอร์ชั่น</label>
              <input class="input" name="version" placeholder="1.0" value="1.0" required>
            </div>
          </div>

          <div class="field">
            <label>รายละเอียด / สิ่งที่ปรับปรุง (Changelog)</label>
            <textarea class="input" name="description" placeholder="เช่น ปรับปรุงสูตรดึงหัวตามแพตช์ใหม่ของเกม..."></textarea>
          </div>

          <div class="field">
            <label>เลือกไฟล์สูตร (.3105, .ogios, .dat)</label>
            <input type="file" name="patch_file" class="input" accept=".3105,.ogios,.dat,.bin" required>
          </div>

          <button class="btn btn-primary" style="width:100%"><i class="fa-solid fa-upload"></i> อัปโหลดและกระจายแพตช์ (Publish OTA)</button>
        </form>
      </div>

      <!-- OTA Guide Card -->
      <div class="card">
        <div class="card-head">
          <h3><i class="fa-solid fa-circle-info"></i>การทำงานของ Cloud OTA</h3>
        </div>
        <div class="card-body">
          <p class="dim" style="line-height:1.6;margin-bottom:14px">
            ระบบ <b>Cloud Patches OTA</b> ช่วยให้คุณสามารถแก้สูตรและส่งไปยังแอปของลูกค้าทุกคนได้ในคลิกเดียว โดยลูกค้า<b>ไม่ต้องดาวน์โหลดหรือติดตั้งแอป (.ipa) ใหม่</b>
          </p>
          <div class="sum-row"><span>1. เช็คอัตโนมัติ</span><b>เมื่อเปิดแอปหรือกดเช็ค</b></div>
          <div class="sum-row"><span>2. ตรวจสอบ SHA256</span><b>เทียบความต่างของไฟล์</b></div>
          <div class="sum-row"><span>3. ดาวน์โหลดเบื้องหลัง</span><b>เซฟทับไฟล์เดิมทันที</b></div>
          <div class="sum-row"><span>4. มีผลทันที</span><b>สูตรในแอปอัปเดตสด</b></div>

          <div style="margin-top:16px">
            <div class="dim" style="margin-bottom:6px">API Endpoint สำหรับดึงรายการสูตร:</div>
            <pre class="mono" style="font-size:12px;background:var(--bg);padding:10px;border-radius:8px;overflow-x:auto"><?= e($apiEndpointURL) ?>?action=list_patches</pre>
          </div>
        </div>
      </div>
    </div>

    <!-- Patches List Table Card -->
    <div class="card">
      <div class="card-head">
        <h3><i class="fa-solid fa-list-check"></i>รายการสูตรบน Cloud OTA (<?= count($cloudPatches) ?>)</h3>
      </div>
      <div class="card-body" style="padding:0">
        <div class="table-wrap">
          <table class="responsive">
            <thead>
              <tr>
                <th>ไฟล์เป้าหมาย (Target)</th>
                <th>ชื่อสูตร &amp; เวอร์ชั่น</th>
                <th>ขนาดไฟล์</th>
                <th>SHA-256 Checksum</th>
                <th>ยอดดาวน์โหลด</th>
                <th>อัปเดตล่าสุด</th>
                <th>สถานะ</th>
                <th style="text-align:right">จัดการ</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($cloudPatches)): ?>
              <tr><td colspan="8" class="empty" style="text-align:center;padding:32px;color:var(--muted)">ยังไม่มีไฟล์สูตรบน Cloud OTA — อัปโหลดไฟล์แรกของคุณด้านบนได้เลย</td></tr>
            <?php else: foreach ($cloudPatches as $cp): ?>
              <tr>
                <td data-label="เป้าหมาย">
                  <span class="mono" style="font-weight:700;color:#fff;background:#171717;padding:4px 8px;border-radius:6px;border:1px solid var(--line-strong)">
                    <?= e($cp['target_filename']) ?>
                  </span>
                </td>
                <td data-label="ชื่อสูตร">
                  <b><?= e($cp['patch_name']) ?></b>
                  <span class="pill" style="margin-left:6px;background:rgba(59,130,246,0.15);color:#60a5fa">v<?= e($cp['version']) ?></span>
                  <?php if (!empty($cp['description'])): ?>
                    <small class="dim" style="display:block"><?= e($cp['description']) ?></small>
                  <?php endif; ?>
                </td>
                <td data-label="ขนาด" class="mono"><?= number_format($cp['file_size'] / 1024, 1) ?> KB</td>
                <td data-label="SHA256">
                  <span class="mono dim" style="font-size:11px" title="<?= e($cp['file_hash']) ?>">
                    <?= substr($cp['file_hash'], 0, 10) ?>...<?= substr($cp['file_hash'], -8) ?>
                  </span>
                  <button type="button" class="btn btn-sm" style="height:24px;padding:0 6px;margin-left:4px" onclick="copyText('<?= e($cp['file_hash']) ?>', 'คัดลอก Checksum แล้ว')" title="คัดลอก Hash"><i class="fa-regular fa-copy"></i></button>
                </td>
                <td data-label="ดาวน์โหลด" class="mono"><i class="fa-solid fa-arrow-down" style="color:var(--muted);font-size:11px"></i> <?= number_format($cp['downloads_count']) ?></td>
                <td data-label="อัปเดต" class="dim" style="font-size:12px"><?= date('d/m/Y H:i', strtotime($cp['updated_at'])) ?></td>
                <td data-label="สถานะ">
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle_cloud_patch">
                    <input type="hidden" name="patch_id" value="<?= (int)$cp['id'] ?>">
                    <input type="hidden" name="state" value="<?= (int)$cp['is_active'] ? '0' : '1' ?>">
                    <button class="pill" style="cursor:pointer;border:none;background:<?= (int)$cp['is_active'] ? 'rgba(34,197,94,0.15)' : 'rgba(239,68,68,0.15)' ?>;color:<?= (int)$cp['is_active'] ? '#4ade80' : '#f87171' ?>">
                      <i class="fa-solid fa-circle" style="font-size:7px;margin-right:4px"></i> <?= (int)$cp['is_active'] ? 'เปิด OTA' : 'ปิดชั่วคราว' ?>
                    </button>
                  </form>
                </td>
                <td data-label="จัดการ" style="text-align:right">
                  <div style="display:inline-flex;gap:6px">
                    <a href="api.php?action=download_patch&id=<?= (int)$cp['id'] ?>" class="btn btn-sm" title="ทดสอบดาวน์โหลด"><i class="fa-solid fa-download"></i></a>
                    <form method="POST" onsubmit="return requestActionConfirm(event, this, 'ลบไฟล์สูตร <?= e($cp['target_filename']) ?>?', 'ไฟล์แพตช์นี้จะถูกลบออกจากเซิร์ฟเวอร์อย่างถาวร', 'fa-solid fa-trash', true, 'ลบแพตช์')">
                      <input type="hidden" name="action" value="delete_cloud_patch">
                      <input type="hidden" name="patch_id" value="<?= (int)$cp['id'] ?>">
                      <button class="btn btn-sm btn-danger" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'settings' && $isAdmin): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:16px">
      <div class="card"><div class="card-head"><h3>เปลี่ยนรหัสผ่านแอดมิน</h3></div>
        <form method="POST" class="card-body"><input type="hidden" name="action" value="change_password">
          <div class="field"><label>ชื่อผู้ใช้</label><input class="input" value="<?= e($currentUsername) ?>" disabled></div>
          <div class="field"><label>รหัสผ่านใหม่</label><input type="password" name="new_password" class="input" minlength="6" required><span class="hint">อย่างน้อย 6 ตัวอักษร</span></div>
          <button class="btn btn-primary">บันทึกรหัสผ่าน</button></form></div>
      <div class="card"><div class="card-head"><h3>API Endpoint</h3></div>
        <div class="card-body">
          <div class="field"><label>URL</label><div style="display:flex;gap:6px"><input id="apiEndpoint" class="input" value="<?= e($apiEndpointURL) ?>" readonly><button type="button" class="btn" onclick="copyText(document.getElementById('apiEndpoint').value,'คัดลอกแล้ว')" aria-label="คัดลอก"><i class="fa-regular fa-copy"></i></button></div></div>
          <div class="dim">ตัวอย่างการเรียกจาก Swift</div>
          <pre class="mono" style="font-size:12px;background:var(--bg);padding:12px;border-radius:8px;overflow-x:auto;margin-top:6px">let url = URL(string: "<?= e($apiEndpointURL) ?>?key=KEY&device_id=UDID")!</pre>
        </div></div>
    </div>
  <?php endif; ?>
  </main>
</div>

<?php if ($isAdmin): ?>
<div class="modal" id="modalAddReseller"><div class="modal-box">
  <div class="modal-head"><h3>เพิ่มตัวแทน</h3><button type="button" onclick="closeModal('modalAddReseller')" aria-label="ปิด"><i class="fa-solid fa-xmark"></i></button></div>
  <form method="POST"><input type="hidden" name="action" value="add_reseller">
    <div class="modal-body">
      <div class="grid-2">
        <div class="field"><label>ชื่อผู้ใช้</label><input name="username" class="input" required></div>
        <div class="field"><label>รหัสผ่าน</label><input type="password" name="password" class="input" minlength="4" required></div>
      </div>
      <div class="grid-2">
        <div class="field"><label>ชื่อที่แสดง</label><input name="name" class="input" placeholder="เช่น ร้าน A / Line @tony" required></div>
        <div class="field"><label>เครดิตเริ่มต้น</label><input type="number" name="credits" class="input" value="10" min="0" required></div>
      </div>
      <div class="field"><label>วันหมดอายุบัญชี</label>
        <select name="expiry_preset" class="input" onchange="document.getElementById('addCustomExpiryWrap').style.display=this.value==='custom'?'block':'none'">
          <option value="30" selected>30 วัน</option><option value="7">7 วัน</option><option value="90">90 วัน</option><option value="365">365 วัน</option><option value="never">ตลอดชีพ</option><option value="custom">ระบุวันที่เอง</option>
        </select>
        <div id="addCustomExpiryWrap" style="display:none;margin-top:8px"><input type="datetime-local" name="custom_expiry" class="input" value="<?= date('Y-m-d\T23:59',strtotime('+30 days')) ?>"></div>
      </div>
      <div class="field"><label>โน้ต</label><input name="note" class="input"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" onclick="closeModal('modalAddReseller')">ยกเลิก</button><button class="btn btn-primary">สร้างบัญชี</button></div>
  </form></div></div>

<div class="modal" id="modalManageCredits"><div class="modal-box">
  <div class="modal-head"><h3>จัดการเครดิต</h3><button type="button" onclick="closeModal('modalManageCredits')" aria-label="ปิด"><i class="fa-solid fa-xmark"></i></button></div>
  <form method="POST"><input type="hidden" name="action" value="edit_reseller_credits"><input type="hidden" name="reseller_id" id="creditResellerId">
    <div class="modal-body">
      <div class="who"><b id="creditResellerName">-</b><div class="dim">เครดิตปัจจุบัน: <span id="creditCurrentVal">0</span></div></div>
      <div class="field"><label>รูปแบบ</label><select name="credit_mode" class="input"><option value="add">เติมเครดิต</option><option value="deduct">หักเครดิต</option><option value="set">กำหนดยอดตรง ๆ</option></select></div>
      <div class="field"><label>จำนวน</label><input type="number" name="amount" id="creditAmountInput" class="input" value="10" min="1" required>
        <div style="display:flex;gap:6px;margin-top:8px"><?php foreach ([5,10,50,100] as $n): ?><button type="button" class="btn btn-sm" onclick="document.getElementById('creditAmountInput').value=<?= $n ?>"><?= $n ?></button><?php endforeach; ?></div></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" onclick="closeModal('modalManageCredits')">ยกเลิก</button><button class="btn btn-primary">บันทึก</button></div>
  </form></div></div>

<div class="modal" id="modalManageExpiry"><div class="modal-box">
  <div class="modal-head"><h3>วันหมดอายุตัวแทน</h3><button type="button" onclick="closeModal('modalManageExpiry')" aria-label="ปิด"><i class="fa-solid fa-xmark"></i></button></div>
  <form method="POST"><input type="hidden" name="action" value="edit_reseller_expiry"><input type="hidden" name="reseller_id" id="expiryResellerId">
    <div class="modal-body">
      <div class="who"><b id="expiryResellerName">-</b><div class="dim">หมดอายุ: <span id="expiryCurrentVal">-</span></div></div>
      <div class="field"><label>การต่ออายุ</label>
        <select name="expiry_mode" class="input" onchange="document.getElementById('editCustomExpiryWrap').style.display=this.value==='custom'?'block':'none'">
          <option value="+30">เพิ่ม 30 วัน</option><option value="+7">เพิ่ม 7 วัน</option><option value="+90">เพิ่ม 90 วัน</option><option value="+365">เพิ่ม 365 วัน</option><option value="never">ตลอดชีพ</option><option value="custom">ระบุวันที่เอง</option>
        </select>
        <div id="editCustomExpiryWrap" style="display:none;margin-top:8px"><input type="datetime-local" name="custom_date" id="editCustomDateInput" class="input"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" onclick="closeModal('modalManageExpiry')">ยกเลิก</button><button class="btn btn-primary">บันทึก</button></div>
  </form></div></div>

<div class="modal" id="modalResetPassword"><div class="modal-box">
  <div class="modal-head"><h3>รีเซ็ตรหัสผ่านตัวแทน</h3><button type="button" onclick="closeModal('modalResetPassword')" aria-label="ปิด"><i class="fa-solid fa-xmark"></i></button></div>
  <form method="POST"><input type="hidden" name="action" value="reset_reseller_password"><input type="hidden" name="reseller_id" id="passwordResellerId">
    <div class="modal-body">
      <div class="who"><b id="passwordResellerName">-</b></div>
      <div class="field"><label>รหัสผ่านใหม่</label><input type="password" name="new_password" class="input" minlength="4" required><span class="hint">อย่างน้อย 4 ตัวอักษร ใช้เข้าสู่ระบบได้ทันที</span></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" onclick="closeModal('modalResetPassword')">ยกเลิก</button><button class="btn btn-primary">เปลี่ยนรหัสผ่าน</button></div>
  </form></div></div>
<?php endif; ?>

<script>
const root = document.documentElement;
document.getElementById('menuBtn').onclick = () => root.classList.toggle('open');
document.getElementById('overlay').onclick = () => root.classList.remove('open');

function showToast(msg, type='success', ms=3500){
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.innerHTML = '<i class="fa-solid ' + (type==='error' ? 'fa-triangle-exclamation' : 'fa-check') + '"></i><span></span>';
  t.querySelector('span').textContent = msg;
  document.getElementById('toastStack').appendChild(t);
  setTimeout(() => t.remove(), ms);
}
<?php if ($alert): ?>
document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode(strip_tags($alert)) ?>, <?= json_encode($alertType) ?>, 6000));
<?php endif; ?>

function copyText(text, msg){
  const done = () => showToast(msg, 'success', 2000);
  if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
  const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); done(); } catch(e) {} ta.remove();
}
document.addEventListener('click', e => {
  const el = e.target.closest('[data-copy]');
  if (el) copyText(el.dataset.copy, 'คัดลอกแล้ว: ' + el.dataset.copy);
});

function downloadKeysText(){
  const a = document.createElement('a');
  a.download = 'XSTH_Keys_' + new Date().toISOString().slice(0,10) + '.txt';
  a.href = URL.createObjectURL(new Blob([document.getElementById('generatedKeysText').value], {type:'text/plain'}));
  a.click();
}
function exportTableToCSV(){
  const rows = [];
  document.querySelectorAll('#licensesTable tr').forEach(r => {
    const cols = r.querySelectorAll('td, th'), row = [];
    for (let j = 0; j < cols.length - 1; j++) row.push('"' + cols[j].innerText.replace(/\s+/g,' ').trim().replace(/"/g,'""') + '"');
    rows.push(row.join(','));
  });
  const a = document.createElement('a');
  a.download = 'XSTH_Licenses.csv';
  a.href = URL.createObjectURL(new Blob(['\ufeff' + rows.join('\n')], {type:'text/csv;charset=utf-8'}));
  a.click();
}

function openModal(id){ document.getElementById(id)?.classList.add('show'); }
function closeModal(id){ document.getElementById(id)?.classList.remove('show'); }
document.addEventListener('click', e => { if (e.target.classList.contains('modal')) e.target.classList.remove('show'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal.show').forEach(m => m.classList.remove('show')); });

function openCreditModal(r){
  creditResellerId.value = r.id;
  creditResellerName.textContent = r.name + ' (@' + r.username + ')';
  creditCurrentVal.textContent = r.credits + ' เครดิต';
  creditAmountInput.value = 10;
  openModal('modalManageCredits');
}
function openExpiryModal(r){
  expiryResellerId.value = r.id;
  expiryResellerName.textContent = r.name + ' (@' + r.username + ')';
  expiryCurrentVal.textContent = r.expires_at || 'ตลอดชีพ';
  if (r.expires_at) editCustomDateInput.value = r.expires_at.replace(' ','T').slice(0,16);
  openModal('modalManageExpiry');
}
function openPasswordModal(r){
  passwordResellerId.value = r.id;
  passwordResellerName.textContent = r.name + ' (@' + r.username + ')';
  openModal('modalResetPassword');
}

<?php if ($tab === 'dashboard'): ?>
document.addEventListener('DOMContentLoaded', () => {
  Chart.defaults.font.family = "'IBM Plex Sans Thai',sans-serif";
  Chart.defaults.color = '#8b92a5';
  new Chart(document.getElementById('activationsChart'), {
    type: 'bar',
    data: { labels: <?= json_encode(array_column($sevenDaysActivations,'day')) ?>,
      datasets: [{ data: <?= json_encode(array_column($sevenDaysActivations,'count')) ?>, backgroundColor: '#ffffff', borderRadius: 4, maxBarThickness: 28 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
      scales: { x: { grid: { display: false }, ticks: { color: '#8b92a5' } }, y: { beginAtZero: true, ticks: { stepSize: 1, color: '#8b92a5' }, grid: { color: '#1a1a1a' } } } }
  });
  new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: { labels: ['ใช้งานอยู่','ยังไม่ใช้','หมดอายุ','ถูกแบน'],
      datasets: [{ data: [<?= (int)$activeKeys ?>,<?= (int)$unusedKeys ?>,<?= (int)$expiredKeys ?>,<?= (int)$bannedKeys ?>],
        backgroundColor: ['#22c55e','#f59e0b','#ef4444','#a855f7'], borderWidth: 2, borderColor: '#0d0d0d' }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '68%',
      plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, color: '#8b92a5' } } } }
  });
});
<?php endif; ?>

/* Generator */
const isResellerUser = <?= $isReseller ? 'true' : 'false' ?>;
const credits = <?= (int)$currentResellerCredits ?>;
function updatePreview(){
  const p = document.getElementById('keyPrefixInput'), d = document.getElementById('durationSelect');
  if (!p) return;
  document.getElementById('liveKeyPreview').textContent = ((p.value.trim() || 'XSTH').toUpperCase()) + '-8A91F2-3C4D05-77B2';
  document.getElementById('previewDurationText').textContent = d.options[d.selectedIndex].text;
}
function updateCalc(){
  const c = document.getElementById('genCountInput'); if (!c) return;
  const n = parseInt(c.value) || 1;
  document.getElementById('btnGenText').textContent = isResellerUser ? `สร้าง ${n} คีย์ (หัก ${n} เครดิต)` : `สร้าง ${n} คีย์`;
  if (!isResellerUser) return;
  const remain = credits - n, badge = document.getElementById('calcStatusBadge');
  document.getElementById('calcDeductText').textContent = '-' + n;
  document.getElementById('calcRemainText').textContent = Math.max(0, remain);
  badge.className = 'pill' + (remain < 0 ? ' bad' : '');
  badge.textContent = remain < 0 ? `เครดิตไม่เพียงพอ (ต้องการ ${n})` : 'เครดิตเพียงพอ';
}
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('keyPrefixInput')?.addEventListener('input', updatePreview);
  document.getElementById('durationSelect')?.addEventListener('change', updatePreview);
  document.getElementById('genCountInput')?.addEventListener('input', updateCalc);
  updatePreview();
});
</script>

<!-- Beautiful Custom Confirm Modal (No Native Alerts) -->
<div class="modal" id="modalConfirmAction">
  <div class="modal-box" style="max-width:420px; text-align:center; padding:28px 24px;">
    <div style="width:54px; height:54px; border-radius:50%; background:rgba(239,68,68,0.12); color:#ef4444; display:grid; place-items:center; font-size:22px; margin:0 auto 16px;" id="confirmIcon">
      <i class="fa-solid fa-triangle-exclamation"></i>
    </div>
    <h3 style="font-size:18px; font-weight:800; margin-bottom:8px; color:#fff;" id="confirmTitle">ยืนยันการดำเนินการ</h3>
    <p style="font-size:13.5px; color:var(--muted); line-height:1.6; margin-bottom:24px;" id="confirmDesc">คุณแน่ใจหรือไม่ว่าต้องการดำเนินการนี้?</p>
    <div style="display:flex; gap:12px; justify-content:center;">
      <button type="button" class="btn" style="flex:1;" onclick="closeModal('modalConfirmAction')">ยกเลิก</button>
      <button type="button" class="btn btn-primary" style="flex:1;" id="confirmBtnAction">ดำเนินการ</button>
    </div>
  </div>
</div>

<script>
let pendingFormToSubmit = null;
function requestActionConfirm(e, form, title, desc, iconClass = 'fa-solid fa-triangle-exclamation', isDanger = false, confirmText = 'ดำเนินการ') {
  e.preventDefault();
  pendingFormToSubmit = form;
  document.getElementById('confirmTitle').textContent = title;
  document.getElementById('confirmDesc').textContent = desc;
  
  const iconWrap = document.getElementById('confirmIcon');
  iconWrap.innerHTML = '<i class="' + iconClass + '"></i>';
  if (isDanger) {
    iconWrap.style.background = 'rgba(244, 63, 94, 0.15)';
    iconWrap.style.color = '#f43f5e';
    document.getElementById('confirmBtnAction').className = 'btn btn-danger';
  } else {
    iconWrap.style.background = 'rgba(99, 102, 241, 0.15)';
    iconWrap.style.color = '#818cf8';
    document.getElementById('confirmBtnAction').className = 'btn btn-primary';
  }
  document.getElementById('confirmBtnAction').textContent = confirmText;
  openModal('modalConfirmAction');
  return false;
}

document.getElementById('confirmBtnAction').addEventListener('click', function() {
  if (pendingFormToSubmit) {
    closeModal('modalConfirmAction');
    pendingFormToSubmit.submit();
  }
});
</script>

</body>
</html>