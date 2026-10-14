<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_logged'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$alert = '';
$alertType = 'success';
$newlyGeneratedKeys = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Generate Keys (Single or Batch)
    if ($action === 'generate') {
        $count = max(1, min(100, (int)($_POST['count'] ?? 1)));
        $duration = (int)($_POST['duration'] ?? 30);
        $note = trim($_POST['note'] ?? '');
        $prefix = strtoupper(trim($_POST['prefix'] ?? 'OGIOS'));
        if (empty($prefix)) $prefix = 'OGIOS';

        $stmt = $pdo->prepare("INSERT INTO `licenses` (`license_key`, `duration_days`, `status`, `note`) VALUES (?, ?, 'unused', ?)");

        for ($i = 0; $i < $count; $i++) {
            $rand1 = strtoupper(bin2hex(random_bytes(3)));
            $rand2 = strtoupper(bin2hex(random_bytes(3)));
            $rand3 = strtoupper(bin2hex(random_bytes(2)));
            $newKey = "{$prefix}-{$rand1}-{$rand2}-{$rand3}";
            $stmt->execute([$newKey, $duration, $note]);
            $newlyGeneratedKeys[] = $newKey;
        }
        $alert = "Successfully generated {$count} new license key(s)!";
    }

    // 2. Reset Device ID (HWID)
    if ($action === 'reset_hwid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE `licenses` SET `device_id` = NULL, `device_name` = NULL WHERE `id` = ?")->execute([$id]);
            $alert = "Hardware ID (HWID) unbound successfully!";
        }
    }

    // 3. Extend License Expiration (+Days)
    if ($action === 'extend_days') {
        $id = (int)($_POST['id'] ?? 0);
        $days = (int)($_POST['days'] ?? 30);
        if ($id > 0 && $days > 0) {
            $stmt = $pdo->prepare("SELECT expires_at FROM `licenses` WHERE `id` = ?");
            $stmt->execute([$id]);
            $currentExp = $stmt->fetchColumn();

            $baseTime = (!empty($currentExp) && strtotime($currentExp) > time()) ? strtotime($currentExp) : time();
            $newExp = date('Y-m-d H:i:s', strtotime("+{$days} days", $baseTime));

            $pdo->prepare("UPDATE `licenses` SET `expires_at` = ?, `status` = 'active' WHERE `id` = ?")->execute([$newExp, $id]);
            $alert = "Extended license by {$days} days (New expiry: {$newExp})!";
        }
    }

    // 4. Toggle Ban Status
    if ($action === 'toggle_ban') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT status FROM `licenses` WHERE `id` = ?");
            $stmt->execute([$id]);
            $curr = $stmt->fetchColumn();
            $newStatus = ($curr === 'banned') ? 'active' : 'banned';
            $pdo->prepare("UPDATE `licenses` SET `status` = ? WHERE `id` = ?")->execute([$newStatus, $id]);
            $alert = "License status changed to " . strtoupper($newStatus) . "!";
        }
    }

    // 5. Delete Key
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM `licenses` WHERE `id` = ?")->execute([$id]);
            $alert = "License key deleted permanently.";
        }
    }

    // 6. Change Password
    if ($action === 'change_password') {
        $newPass = trim($_POST['new_password'] ?? '');
        if (strlen($newPass) >= 6) {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE `admins` SET `password` = ? WHERE `username` = ?")->execute([$hash, $_SESSION['admin_user']]);
            $alert = "Admin password updated successfully!";
        } else {
            $alert = "Password must be at least 6 characters long.";
            $alertType = 'error';
        }
    }

    // 7. Clear Activity Logs
    if ($action === 'clear_logs') {
        $pdo->query("TRUNCATE TABLE `activity_logs`");
        $alert = "Activity audit logs cleared.";
    }
}

// Global Stats
$totalKeys = $pdo->query("SELECT COUNT(*) FROM `licenses`")->fetchColumn();
$activeKeys = $pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` = 'active'")->fetchColumn();
$unusedKeys = $pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` = 'unused'")->fetchColumn();
$expiredKeys = $pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` = 'expired'")->fetchColumn();
$bannedKeys = $pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` = 'banned'")->fetchColumn();
$boundDevicesCount = $pdo->query("SELECT COUNT(DISTINCT device_id) FROM `licenses` WHERE device_id IS NOT NULL AND device_id != ''")->fetchColumn();

// Chart Data 1: Last 7 Days Activity
$sevenDaysActivations = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `activity_logs` WHERE `action` = 'ACTIVATED_SUCCESS' AND DATE(`created_at`) = ?");
    $stmt->execute([$date]);
    $sevenDaysActivations[] = [
        'day' => date('M d', strtotime($date)),
        'count' => (int)$stmt->fetchColumn()
    ];
}

// Tab Routing
$tab = $_GET['tab'] ?? 'dashboard';
$validTabs = ['dashboard', 'licenses', 'generator', 'devices', 'logs', 'settings'];
if (!in_array($tab, $validTabs, true)) $tab = 'dashboard';

// Fetch Licenses with Filtering
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM `licenses` WHERE 1=1";
$params = [];

if ($statusFilter && in_array($statusFilter, ['active', 'unused', 'expired', 'banned'])) {
    $sql .= " AND `status` = ?";
    $params[] = $statusFilter;
}

if ($search !== '') {
    $sql .= " AND (`license_key` LIKE ? OR `device_id` LIKE ? OR `device_name` LIKE ? OR `note` LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}

$sql .= " ORDER BY `id` DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$licenses = $stmt->fetchAll();

// Fetch Recent Logs
$recentLogs = $pdo->query("SELECT * FROM `activity_logs` ORDER BY `id` DESC LIMIT 50")->fetchAll();

// Server Host Detection for API URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$apiEndpointURL = "{$protocol}{$host}{$uri}/api.php";

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pageTitles = [
    'dashboard' => ['Dashboard', 'ภาพรวมระบบและสถิติล่าสุด'],
    'licenses'  => ['License Keys', 'จัดการ ค้นหา และควบคุมคีย์ทั้งหมด'],
    'generator' => ['Generator Studio', 'สร้างคีย์เดี่ยวหรือแบบแบตช์'],
    'devices'   => ['Active Devices', 'อุปกรณ์ที่ผูกกับคีย์ (HWID)'],
    'logs'      => ['Activity Logs', 'บันทึกความปลอดภัยและการใช้งาน'],
    'settings'  => ['System Settings', 'ความปลอดภัยและการเชื่อมต่อ API'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#070a11">
    <title>XSTH • License Studio</title>
    <script>
        // ตั้งค่าสถานะ sidebar ก่อนเรนเดอร์ เพื่อไม่ให้กระพริบ
        (function () {
            try {
                if (localStorage.getItem('xsth_sb') === '1' && window.innerWidth > 960) {
                    document.documentElement.classList.add('sb-collapsed');
                }
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --bg: #070a11;
            --bg-2: #0b101a;
            --panel: #0f1623;
            --panel-2: #131c2c;
            --line: rgba(148, 170, 205, 0.12);
            --line-2: rgba(148, 170, 205, 0.2);
            --accent: #4f9dff;
            --accent-2: #7c6cff;
            --accent-soft: rgba(79, 157, 255, 0.14);
            --grad: linear-gradient(135deg, #5aa9ff 0%, #5b6dff 55%, #8a5cff 100%);
            --text: #eef4ff;
            --muted: #9db0cc;
            --dim: #6c7f9c;
            --ok: #22c997;
            --warn: #f5b13d;
            --bad: #ff5d6c;
            --violet: #b08cff;
            --sb-w: 272px;
            --sb-w-collapsed: 80px;
            --radius: 18px;
            --ease: cubic-bezier(.4, 0, .2, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Plus Jakarta Sans', 'Noto Sans Thai', system-ui, sans-serif;
            background:
                radial-gradient(900px 500px at 85% -10%, rgba(91, 109, 255, 0.12), transparent 60%),
                radial-gradient(700px 400px at -5% 110%, rgba(79, 157, 255, 0.08), transparent 60%),
                var(--bg);
            background-attachment: fixed;
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
            line-height: 1.5;
        }
        a { color: inherit; }
        button, input, select { font-family: inherit; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: rgba(148, 170, 205, 0.18); border-radius: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }

        /* ============ SIDEBAR ============ */
        .sidebar {
            position: fixed; inset: 0 auto 0 0;
            width: var(--sb-w);
            background: linear-gradient(180deg, #0d1422 0%, #0a0f1a 100%);
            border-right: 1px solid var(--line);
            display: flex; flex-direction: column;
            z-index: 100;
            transition: width .3s var(--ease), transform .3s var(--ease);
        }
        .sb-brand {
            height: 76px;
            padding: 0 20px;
            display: flex; align-items: center; gap: 12px;
            border-bottom: 1px solid var(--line);
            flex-shrink: 0;
            overflow: hidden;
        }
        .brand-logo {
            flex: 0 0 40px; height: 40px;
            border-radius: 12px;
            background: var(--grad);
            display: grid; place-items: center;
            color: #fff; font-size: 18px;
            box-shadow: 0 8px 22px rgba(91, 109, 255, 0.4);
        }
        .brand-text { white-space: nowrap; transition: opacity .2s; }
        .brand-text b { display: block; font-size: 18px; font-weight: 800; letter-spacing: 2px; line-height: 1.1; }
        .brand-text small { font-size: 9.5px; font-weight: 700; letter-spacing: 1.6px; color: var(--accent); text-transform: uppercase; }

        .sb-nav { flex: 1; padding: 16px 12px; display: flex; flex-direction: column; gap: 4px; overflow-y: auto; overflow-x: hidden; }
        .sb-label { font-size: 10px; font-weight: 700; color: var(--dim); letter-spacing: 1.4px; text-transform: uppercase; padding: 10px 14px 6px; white-space: nowrap; }
        .nav-item {
            position: relative;
            display: flex; align-items: center; gap: 14px;
            height: 46px; padding: 0 14px;
            border-radius: 13px;
            color: var(--muted);
            text-decoration: none;
            font-size: 14px; font-weight: 600;
            white-space: nowrap;
            transition: background .2s, color .2s, transform .2s;
        }
        .nav-item i { flex: 0 0 22px; text-align: center; font-size: 16px; }
        .nav-item span.t { transition: opacity .2s; }
        .nav-item:hover { background: rgba(255, 255, 255, 0.05); color: #fff; }
        .nav-item.active {
            color: #fff;
            background: linear-gradient(135deg, rgba(90, 169, 255, 0.22), rgba(138, 92, 255, 0.18));
            box-shadow: inset 0 0 0 1px rgba(110, 140, 255, 0.35);
        }
        .nav-item.active::before {
            content: ''; position: absolute; left: -12px; top: 11px; bottom: 11px; width: 4px;
            border-radius: 0 4px 4px 0; background: var(--grad);
        }
        .nav-item .count {
            margin-left: auto; font-size: 11px; font-weight: 700;
            background: rgba(255, 255, 255, 0.08); padding: 2px 8px; border-radius: 20px; color: var(--muted);
        }

        .sb-foot { padding: 14px; border-top: 1px solid var(--line); flex-shrink: 0; }
        .profile { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 14px; background: rgba(255, 255, 255, 0.03); overflow: hidden; }
        .avatar {
            flex: 0 0 38px; height: 38px; border-radius: 11px;
            background: var(--grad); display: grid; place-items: center;
            font-weight: 800; font-size: 14px; color: #fff;
        }
        .profile-info { min-width: 0; flex: 1; white-space: nowrap; }
        .profile-info b { display: block; font-size: 13px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; }
        .profile-info small { font-size: 11px; color: var(--ok); font-weight: 600; }
        .logout {
            flex: 0 0 34px; height: 34px; border-radius: 10px; display: grid; place-items: center;
            color: var(--dim); text-decoration: none; transition: .2s;
        }
        .logout:hover { color: var(--bad); background: rgba(255, 93, 108, 0.12); }

        /* Collapsed (desktop only) */
        @media (min-width: 961px) {
            html.sb-collapsed .sidebar { width: var(--sb-w-collapsed); }
            html.sb-collapsed .brand-text,
            html.sb-collapsed .nav-item span.t,
            html.sb-collapsed .nav-item .count,
            html.sb-collapsed .profile-info { display: none; }

            /* โลโก้อยู่กึ่งกลาง */
            html.sb-collapsed .sb-brand { padding: 0; justify-content: center; gap: 0; }

            /* เมนู: ปุ่มสี่เหลี่ยมจัตุรัส อยู่กึ่งกลาง */
            html.sb-collapsed .sb-nav { padding: 16px 0; align-items: center; gap: 6px; overflow: visible; }
            html.sb-collapsed .nav-item {
                width: 50px; height: 50px; padding: 0; gap: 0;
                justify-content: center; border-radius: 14px;
            }
            html.sb-collapsed .nav-item i { flex: none; font-size: 18px; }
            html.sb-collapsed .nav-item.active::before { left: -15px; top: 13px; bottom: 13px; }

            /* หัวข้อกลุ่มกลายเป็นเส้นคั่นบางๆ */
            html.sb-collapsed .sb-label {
                font-size: 0; width: 28px; height: 1px; padding: 0; margin: 8px 0;
                background: var(--line-2);
            }
            html.sb-collapsed .sb-label:first-child { display: none; }

            /* โปรไฟล์: avatar + ปุ่มออกจากระบบ เรียงแนวตั้ง */
            html.sb-collapsed .sb-foot { padding: 14px 0; }
            html.sb-collapsed .profile {
                flex-direction: column; align-items: center; gap: 8px; padding: 0; background: none;
            }
            html.sb-collapsed .avatar { flex: none; width: 40px; height: 40px; }

            /* Tooltip */
            html.sb-collapsed .nav-item[data-tip]:hover::after {
                content: attr(data-tip);
                position: absolute; left: calc(100% + 14px); top: 50%; transform: translateY(-50%);
                background: #1a2438; color: #fff; padding: 7px 13px; border-radius: 9px;
                font-size: 12px; font-weight: 600; white-space: nowrap;
                box-shadow: 0 8px 24px rgba(0, 0, 0, .5); border: 1px solid var(--line-2);
                z-index: 200; pointer-events: none;
            }
        }

        .overlay {
            position: fixed; inset: 0; background: rgba(3, 6, 12, 0.65);
            backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
            opacity: 0; visibility: hidden; transition: .3s var(--ease); z-index: 90;
        }

        /* ============ MAIN ============ */
        .main { margin-left: var(--sb-w); min-height: 100vh; transition: margin-left .3s var(--ease); }
        html.sb-collapsed .main { margin-left: var(--sb-w-collapsed); }

        .topbar {
            position: sticky; top: 0; z-index: 60;
            display: flex; align-items: center; gap: 14px;
            padding: 14px 32px;
            background: rgba(7, 10, 17, 0.72);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--line);
        }
        .icon-btn {
            flex: 0 0 42px; height: 42px; border-radius: 12px;
            border: 1px solid var(--line-2); background: var(--panel);
            color: var(--text); cursor: pointer; display: grid; place-items: center;
            font-size: 16px; transition: .2s;
        }
        .icon-btn:hover { background: var(--panel-2); border-color: rgba(110, 140, 255, 0.5); color: var(--accent); }
        .topbar-title { min-width: 0; flex: 1; }
        .topbar-title h1 { font-size: 20px; font-weight: 800; letter-spacing: -0.02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .topbar-title p { font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .content { padding: 28px 32px 48px; max-width: 1600px; margin: 0 auto; }

        /* ============ BUTTONS ============ */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 0 18px; height: 42px; border-radius: 12px;
            font-size: 13px; font-weight: 700; border: 1px solid transparent;
            cursor: pointer; text-decoration: none; transition: .2s; white-space: nowrap; color: #fff;
        }
        .btn-primary { background: var(--grad); box-shadow: 0 8px 22px rgba(91, 109, 255, 0.35); }
        .btn-primary:hover { transform: translateY(-1px); filter: brightness(1.1); }
        .btn-ghost { background: var(--panel); border-color: var(--line-2); }
        .btn-ghost:hover { background: var(--panel-2); border-color: rgba(110, 140, 255, 0.45); }
        .btn-sm { height: 34px; padding: 0 12px; font-size: 12px; border-radius: 10px; }
        .btn-danger-text { color: var(--bad); }
        .act {
            width: 34px; height: 34px; border-radius: 10px;
            border: 1px solid var(--line-2); background: var(--panel-2);
            color: var(--muted); cursor: pointer; display: inline-grid; place-items: center;
            font-size: 13px; transition: .2s;
        }
        .act:hover { color: #fff; border-color: rgba(110, 140, 255, 0.5); background: #1a2640; }
        .act.danger:hover { color: var(--bad); border-color: var(--bad); background: rgba(255, 93, 108, 0.1); }

        /* ============ CARDS ============ */
        .card {
            background: linear-gradient(180deg, var(--panel) 0%, rgba(15, 22, 35, 0.85) 100%);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .card-head {
            display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
            padding: 18px 22px; border-bottom: 1px solid var(--line);
        }
        .card-head h3 { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .card-head h3 i { color: var(--accent); }
        .card-body { padding: 22px; }
        .stack { display: flex; flex-direction: column; gap: 22px; }

        /* Metrics */
        .metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; }
        .metric { padding: 20px; position: relative; overflow: hidden; transition: .25s var(--ease); }
        .metric:hover { transform: translateY(-3px); border-color: var(--line-2); }
        .metric::after {
            content: ''; position: absolute; right: -30px; top: -30px; width: 110px; height: 110px;
            border-radius: 50%; background: var(--c, var(--accent)); opacity: .08; filter: blur(8px);
        }
        .metric-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
        .metric-top span { font-size: 11.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; }
        .metric-ico {
            width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; font-size: 16px;
            color: var(--c); background: color-mix(in srgb, var(--c) 15%, transparent);
        }
        .metric-val { font-size: 30px; font-weight: 800; letter-spacing: -0.03em; line-height: 1.1; }
        .metric-sub { font-size: 12px; color: var(--dim); margin-top: 6px; }

        .charts { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 22px; }
        .chart-box { position: relative; height: 270px; }

        /* ============ TABLE ============ */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left; padding: 14px 20px; font-size: 11px; font-weight: 700;
            color: var(--dim); text-transform: uppercase; letter-spacing: .9px;
            background: rgba(255, 255, 255, 0.02); border-bottom: 1px solid var(--line); white-space: nowrap;
        }
        td { padding: 14px 20px; font-size: 13px; border-bottom: 1px solid rgba(255, 255, 255, 0.04); vertical-align: middle; }
        tbody tr { transition: background .15s; }
        tbody tr:hover { background: rgba(255, 255, 255, 0.025); }
        tbody tr:last-child td { border-bottom: none; }
        .empty { text-align: center; color: var(--dim); padding: 44px 20px !important; }
        .mono { font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace; }

        .key {
            display: inline-flex; align-items: center; gap: 8px;
            font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; font-weight: 700;
            color: #8cc4ff; background: rgba(79, 157, 255, 0.09);
            border: 1px solid rgba(79, 157, 255, 0.22);
            padding: 6px 11px; border-radius: 9px; cursor: pointer; transition: .2s; word-break: break-all;
        }
        .key:hover { background: rgba(79, 157, 255, 0.2); color: #fff; }

        .badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 20px;
            font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; white-space: nowrap;
        }
        .badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge.active  { color: #3ddba9; background: rgba(34, 201, 151, 0.13); border: 1px solid rgba(34, 201, 151, 0.3); }
        .badge.unused  { color: #ffc861; background: rgba(245, 177, 61, 0.13); border: 1px solid rgba(245, 177, 61, 0.3); }
        .badge.expired { color: #ff8793; background: rgba(255, 93, 108, 0.13); border: 1px solid rgba(255, 93, 108, 0.3); }
        .badge.banned  { color: #c6a8ff; background: rgba(176, 140, 255, 0.13); border: 1px solid rgba(176, 140, 255, 0.3); }

        .actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .actions form { display: inline; }

        /* Toolbar */
        .filters { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 2px; max-width: 100%; }
        .filters::-webkit-scrollbar { display: none; }
        .chip {
            padding: 8px 14px; border-radius: 10px; font-size: 12.5px; font-weight: 700;
            color: var(--muted); text-decoration: none; background: rgba(255, 255, 255, 0.03);
            border: 1px solid transparent; transition: .2s; white-space: nowrap;
        }
        .chip:hover { color: #fff; background: rgba(255, 255, 255, 0.07); }
        .chip.active { color: #fff; background: var(--accent-soft); border-color: rgba(79, 157, 255, 0.45); }
        .toolbar-right { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .search { display: flex; gap: 8px; }
        .input, .search input {
            height: 42px; padding: 0 14px; background: rgba(5, 8, 14, 0.7);
            border: 1px solid var(--line-2); border-radius: 12px; color: #fff; font-size: 13px; outline: none; transition: .2s;
        }
        .search input { width: 250px; }
        .input:focus, .search input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79, 157, 255, 0.14); }

        /* Forms */
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .field { margin-bottom: 18px; }
        .field label { display: block; font-size: 12.5px; font-weight: 700; color: #cfdcf0; margin-bottom: 8px; }
        .field label i { color: var(--accent); margin-right: 6px; }
        .field .input { width: 100%; height: 50px; padding: 0 16px; font-size: 14px; }
        .field select.input {
            appearance: none; -webkit-appearance: none; padding-right: 40px; cursor: pointer;
            background-image: linear-gradient(45deg, transparent 50%, #94a8c6 50%), linear-gradient(135deg, #94a8c6 50%, transparent 50%);
            background-position: calc(100% - 20px) 50%, calc(100% - 14px) 50%;
            background-size: 6px 6px; background-repeat: no-repeat;
        }
        .field select.input option { background: #101827; }
        .hint { font-size: 11.5px; color: var(--dim); margin-top: 6px; display: block; }
        .gen-wrap { max-width: 860px; margin: 0 auto; }
        .gen-hero { display: flex; gap: 16px; align-items: center; padding: 24px 24px 4px; }
        .gen-hero .ico {
            width: 52px; height: 52px; border-radius: 15px; display: grid; place-items: center; font-size: 22px;
            color: #fff; background: var(--grad); box-shadow: 0 10px 26px rgba(91, 109, 255, .4); flex-shrink: 0;
        }
        .gen-hero h2 { font-size: 21px; font-weight: 800; letter-spacing: -0.02em; }
        .gen-hero p { font-size: 13px; color: var(--muted); }

        .newkeys {
            background: rgba(34, 201, 151, 0.07); border: 1px solid rgba(34, 201, 151, 0.28);
            border-radius: var(--radius); padding: 18px 20px; margin-bottom: 22px; animation: pop .4s var(--ease);
        }
        .newkeys-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
        .newkeys-head h3 { color: #3ddba9; font-size: 14.5px; font-weight: 800; }
        .newkeys textarea {
            width: 100%; height: 130px; margin-top: 12px; padding: 12px; resize: vertical;
            background: #05080e; border: 1px solid rgba(34, 201, 151, 0.25); border-radius: 12px;
            color: #4ade80; font-family: ui-monospace, Consolas, monospace; font-size: 13px; outline: none;
        }
        @keyframes pop { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }

        .code-box {
            background: rgba(255, 255, 255, 0.03); border: 1px solid var(--line); border-radius: 14px;
            padding: 14px 16px; font-size: 12px; color: var(--muted); margin-top: 16px; overflow-x: auto;
        }
        .code-box code { color: #4ade80; font-family: ui-monospace, Consolas, monospace; white-space: nowrap; }

        /* ============ TOAST ============ */
        .toasts { position: fixed; top: 18px; right: 18px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
        .toast {
            --tc: var(--ok); --dur: 3500ms; pointer-events: auto; position: relative;
            display: flex; align-items: center; gap: 12px; min-width: 250px; max-width: 360px;
            padding: 12px 12px 14px; border-radius: 14px;
            background: rgba(16, 24, 39, 0.88); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            border: 1px solid var(--line-2); box-shadow: 0 14px 36px rgba(0, 0, 0, .5);
            overflow: hidden; animation: tIn .4s cubic-bezier(.2, .9, .3, 1.15) both;
        }
        .toast.error { --tc: var(--bad); }
        .toast.info { --tc: var(--accent); }
        .toast-ico {
            flex: 0 0 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; font-size: 13px;
            color: var(--tc); background: color-mix(in srgb, var(--tc) 16%, transparent);
        }
        .toast-body { flex: 1; min-width: 0; }
        .toast-title { font-size: 12px; font-weight: 800; }
        .toast-msg { font-size: 12px; color: var(--muted); word-break: break-word; }
        .toast-x { border: none; background: none; color: var(--dim); cursor: pointer; width: 24px; height: 24px; border-radius: 7px; }
        .toast-x:hover { background: rgba(255, 255, 255, .08); color: #fff; }
        .toast-bar { position: absolute; left: 0; bottom: 0; height: 2px; width: 100%; background: var(--tc); transform-origin: left; animation: tBar var(--dur) linear forwards; }
        .toast:hover .toast-bar { animation-play-state: paused; }
        .toast.hide { animation: tOut .28s ease forwards; }
        @keyframes tIn { from { opacity: 0; transform: translateX(24px) scale(.96); } to { opacity: 1; transform: none; } }
        @keyframes tOut { to { opacity: 0; transform: translateX(24px) scale(.96); } }
        @keyframes tBar { from { transform: scaleX(1); } to { transform: scaleX(0); } }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 1200px) {
            .metrics { grid-template-columns: repeat(2, 1fr); }
            .charts { grid-template-columns: 1fr; }
        }

        /* Tablet / Mobile: sidebar เป็น drawer */
        @media (max-width: 960px) {
            .sidebar { transform: translateX(-100%); width: min(290px, 84vw) !important; box-shadow: none; }
            html.sb-open .sidebar { transform: none; box-shadow: 20px 0 60px rgba(0, 0, 0, .6); }
            html.sb-open .overlay { opacity: 1; visibility: visible; }
            html.sb-open body { overflow: hidden; }
            .main, html.sb-collapsed .main { margin-left: 0; }
            .topbar { padding: 12px 16px; }
            .content { padding: 18px 16px 40px; }
        }

        /* Mobile: ตารางเป็นการ์ด */
        @media (max-width: 760px) {
            .topbar-title p { display: none; }
            .topbar-title h1 { font-size: 17px; }
            .topbar .btn-label { display: none; }
            .topbar .btn-primary { width: 42px; padding: 0; }
            .metrics { gap: 12px; }
            .metric { padding: 16px; }
            .metric-val { font-size: 24px; }
            .metric-sub { display: none; }
            .grid-2 { grid-template-columns: 1fr; gap: 0; }
            .card-head { padding: 16px; }
            .card-body { padding: 16px; }
            .search { width: 100%; }
            .search input { flex: 1; width: auto; min-width: 0; }
            .toolbar-right { width: 100%; }
            .toolbar-right > .btn { flex: 1; }
            .toasts { left: 12px; right: 12px; top: 12px; }
            .toast { min-width: 0; max-width: none; }

            table.responsive, table.responsive tbody, table.responsive tr, table.responsive td { display: block; width: 100%; }
            table.responsive thead { display: none; }
            table.responsive tbody { padding: 12px; }
            table.responsive tr {
                background: var(--panel-2); border: 1px solid var(--line); border-radius: 14px;
                padding: 8px 14px; margin-bottom: 12px;
            }
            table.responsive tbody tr:hover { background: var(--panel-2); }
            table.responsive td {
                display: flex; justify-content: space-between; align-items: center; gap: 14px;
                padding: 9px 0; border-bottom: 1px dashed var(--line); text-align: right; font-size: 13px;
            }
            table.responsive td:last-child { border-bottom: none; }
            table.responsive td::before {
                content: attr(data-label); flex: 0 0 auto; font-size: 10.5px; font-weight: 700; color: var(--dim);
                text-transform: uppercase; letter-spacing: .6px; text-align: left;
            }
            table.responsive td.empty { display: block; text-align: center; }
            table.responsive td.empty::before { display: none; }
            table.responsive .actions { justify-content: flex-end; }
            table.responsive .key { text-align: left; }
        }
        @media (max-width: 380px) {
            .metrics { grid-template-columns: 1fr; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
    </style>
</head>
<body>

<!-- ============ SIDEBAR ============ -->
<aside class="sidebar" id="sidebar">
    <div class="sb-brand">
        <div class="brand-logo"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="brand-text"><b>XSTH</b><small>License Studio Pro</small></div>
    </div>

    <nav class="sb-nav">
        <div class="sb-label">Main</div>
        <a href="index.php?tab=dashboard" class="nav-item <?= $tab === 'dashboard' ? 'active' : '' ?>" data-tip="Dashboard">
            <i class="fa-solid fa-chart-pie"></i><span class="t">Dashboard</span>
        </a>
        <a href="index.php?tab=licenses" class="nav-item <?= $tab === 'licenses' ? 'active' : '' ?>" data-tip="License Keys">
            <i class="fa-solid fa-key"></i><span class="t">License Keys</span><span class="count"><?= (int)$totalKeys ?></span>
        </a>
        <a href="index.php?tab=generator" class="nav-item <?= $tab === 'generator' ? 'active' : '' ?>" data-tip="Generator Studio">
            <i class="fa-solid fa-wand-magic-sparkles"></i><span class="t">Generator Studio</span>
        </a>
        <div class="sb-label">Monitor</div>
        <a href="index.php?tab=devices" class="nav-item <?= $tab === 'devices' ? 'active' : '' ?>" data-tip="Active Devices">
            <i class="fa-solid fa-mobile-screen-button"></i><span class="t">Active Devices</span><span class="count"><?= (int)$boundDevicesCount ?></span>
        </a>
        <a href="index.php?tab=logs" class="nav-item <?= $tab === 'logs' ? 'active' : '' ?>" data-tip="Activity Logs">
            <i class="fa-solid fa-clock-rotate-left"></i><span class="t">Activity Logs</span>
        </a>
        <div class="sb-label">System</div>
        <a href="index.php?tab=settings" class="nav-item <?= $tab === 'settings' ? 'active' : '' ?>" data-tip="System Settings">
            <i class="fa-solid fa-sliders"></i><span class="t">System Settings</span>
        </a>
    </nav>

    <div class="sb-foot">
        <div class="profile">
            <div class="avatar"><?= e(strtoupper(substr($_SESSION['admin_user'], 0, 1))) ?></div>
            <div class="profile-info">
                <b><?= e($_SESSION['admin_user']) ?></b>
                <small>● Super Admin</small>
            </div>
            <a href="logout.php" class="logout" title="Sign Out"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
        </div>
    </div>
</aside>
<div class="overlay" id="overlay"></div>

<!-- ============ MAIN ============ -->
<div class="main">
    <header class="topbar">
        <button class="icon-btn" id="sbToggle" aria-label="Toggle menu" title="เปิด/ปิดเมนู">
            <i class="fa-solid fa-bars-staggered" id="sbToggleIcon"></i>
        </button>
        <div class="topbar-title">
            <h1><?= e($pageTitles[$tab][0]) ?></h1>
            <p><?= e($pageTitles[$tab][1]) ?></p>
        </div>
        <a href="index.php?tab=generator" class="btn btn-primary"><i class="fa-solid fa-plus"></i><span class="btn-label">Generate Keys</span></a>
    </header>

    <main class="content">

        <?php if (!empty($newlyGeneratedKeys)): ?>
            <div class="newkeys">
                <div class="newkeys-head">
                    <h3><i class="fa-solid fa-circle-check"></i> สร้างคีย์สำเร็จ <?= count($newlyGeneratedKeys) ?> รายการ</h3>
                    <div style="display:flex; gap:8px;">
                        <button onclick="copyGeneratedKeys()" class="btn btn-ghost btn-sm"><i class="fa-regular fa-copy"></i> Copy All</button>
                        <button onclick="downloadKeysText()" class="btn btn-ghost btn-sm"><i class="fa-solid fa-download"></i> Save .txt</button>
                    </div>
                </div>
                <textarea id="generatedKeysText" readonly><?= e(implode("\n", $newlyGeneratedKeys)) ?></textarea>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
        <!-- ============ DASHBOARD ============ -->
        <div class="metrics">
            <div class="card metric" style="--c: var(--accent)">
                <div class="metric-top"><span>Total Keys</span><div class="metric-ico"><i class="fa-solid fa-key"></i></div></div>
                <div class="metric-val"><?= number_format($totalKeys) ?></div>
                <div class="metric-sub">คีย์ทั้งหมดในฐานข้อมูล</div>
            </div>
            <div class="card metric" style="--c: var(--ok)">
                <div class="metric-top"><span>Active</span><div class="metric-ico"><i class="fa-solid fa-shield-halved"></i></div></div>
                <div class="metric-val"><?= number_format($activeKeys) ?></div>
                <div class="metric-sub">กำลังใช้งานอยู่</div>
            </div>
            <div class="card metric" style="--c: var(--warn)">
                <div class="metric-top"><span>Unused Stock</span><div class="metric-ico"><i class="fa-solid fa-boxes-stacked"></i></div></div>
                <div class="metric-val"><?= number_format($unusedKeys) ?></div>
                <div class="metric-sub">พร้อมส่งมอบให้ลูกค้า</div>
            </div>
            <div class="card metric" style="--c: var(--accent-2)">
                <div class="metric-top"><span>Bound Devices</span><div class="metric-ico"><i class="fa-solid fa-mobile-screen"></i></div></div>
                <div class="metric-val"><?= number_format($boundDevicesCount) ?></div>
                <div class="metric-sub">อุปกรณ์ที่ล็อก HWID แล้ว</div>
            </div>
        </div>

        <div class="charts">
            <div class="card">
                <div class="card-head"><h3><i class="fa-solid fa-chart-line"></i> Activations (7 วันล่าสุด)</h3><span style="font-size:12px;color:var(--dim)">Live</span></div>
                <div class="card-body"><div class="chart-box"><canvas id="activationsChart"></canvas></div></div>
            </div>
            <div class="card">
                <div class="card-head"><h3><i class="fa-solid fa-chart-pie"></i> สถานะคีย์</h3></div>
                <div class="card-body"><div class="chart-box"><canvas id="statusChart"></canvas></div></div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h3><i class="fa-solid fa-clock"></i> กิจกรรมล่าสุด</h3>
                <a href="index.php?tab=logs" class="btn btn-ghost btn-sm">ดูทั้งหมด →</a>
            </div>
            <div class="table-wrap">
                <table class="responsive">
                    <thead><tr><th>License Key</th><th>Action</th><th>Device HWID</th><th>Model</th><th>IP</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php if (empty($recentLogs)): ?>
                        <tr><td colspan="6" class="empty">ยังไม่มีกิจกรรม</td></tr>
                    <?php else: foreach (array_slice($recentLogs, 0, 6) as $log): ?>
                        <tr>
                            <td data-label="Key"><span class="key" data-copy="<?= e($log['license_key']) ?>"><?= e($log['license_key']) ?></span></td>
                            <td data-label="Action"><span class="badge active"><?= e($log['action']) ?></span></td>
                            <td data-label="HWID" class="mono" style="color:var(--muted);font-size:12px"><?= e(substr($log['device_id'] ?? '-', 0, 16)) ?></td>
                            <td data-label="Model" style="font-weight:600"><?= e($log['device_name'] ?: 'iOS') ?></td>
                            <td data-label="IP" style="color:var(--dim);font-size:12px"><?= e($log['ip_address']) ?></td>
                            <td data-label="Time" style="color:var(--muted);font-size:12px"><?= e(date('M d, H:i', strtotime($log['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($tab === 'licenses'): ?>
        <!-- ============ LICENSES ============ -->
        <div class="card">
            <div class="card-head">
                <div class="filters">
                    <a href="index.php?tab=licenses" class="chip <?= $statusFilter === '' ? 'active' : '' ?>">All (<?= (int)$totalKeys ?>)</a>
                    <a href="index.php?tab=licenses&status=unused" class="chip <?= $statusFilter === 'unused' ? 'active' : '' ?>">Unused (<?= (int)$unusedKeys ?>)</a>
                    <a href="index.php?tab=licenses&status=active" class="chip <?= $statusFilter === 'active' ? 'active' : '' ?>">Active (<?= (int)$activeKeys ?>)</a>
                    <a href="index.php?tab=licenses&status=expired" class="chip <?= $statusFilter === 'expired' ? 'active' : '' ?>">Expired (<?= (int)$expiredKeys ?>)</a>
                    <a href="index.php?tab=licenses&status=banned" class="chip <?= $statusFilter === 'banned' ? 'active' : '' ?>">Banned (<?= (int)$bannedKeys ?>)</a>
                </div>
                <div class="toolbar-right">
                    <button onclick="exportTableToCSV()" class="btn btn-ghost"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                    <form method="GET" class="search">
                        <input type="hidden" name="tab" value="licenses">
                        <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
                        <input type="text" name="q" placeholder="ค้นหา Key, HWID, Note..." value="<?= e($search) ?>">
                        <button type="submit" class="btn btn-ghost" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
                    </form>
                </div>
            </div>
            <div class="table-wrap">
                <table id="licensesTable" class="responsive">
                    <thead>
                        <tr><th>License Key</th><th>Duration</th><th>Status</th><th>Device (HWID)</th><th>Activated</th><th>Expiration</th><th>Note</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($licenses)): ?>
                        <tr><td colspan="8" class="empty">ไม่พบข้อมูลที่ตรงกับเงื่อนไข</td></tr>
                    <?php else: foreach ($licenses as $l): ?>
                        <tr>
                            <td data-label="Key"><span class="key" data-copy="<?= e($l['license_key']) ?>" title="คลิกเพื่อคัดลอก"><i class="fa-regular fa-copy"></i> <?= e($l['license_key']) ?></span></td>
                            <td data-label="Duration" style="font-weight:700">
                                <?= $l['duration_days'] > 0 ? (int)$l['duration_days'] . ' Days' : '<span style="color:var(--violet)">Lifetime</span>' ?>
                            </td>
                            <td data-label="Status"><span class="badge <?= e($l['status']) ?>"><?= e($l['status']) ?></span></td>
                            <td data-label="HWID">
                                <?php if ($l['device_id']): ?>
                                    <div>
                                        <div class="mono" style="font-size:11px" title="<?= e($l['device_id']) ?>"><?= e(substr($l['device_id'], 0, 14)) ?>...</div>
                                        <div style="font-size:10.5px;color:var(--dim)"><?= e($l['device_name'] ?: 'iOS') ?></div>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--dim);font-size:12px">Unbound</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Activated" style="color:var(--muted);font-size:12px"><?= $l['activated_at'] ? e(date('M d, Y', strtotime($l['activated_at']))) : '-' ?></td>
                            <td data-label="Expires">
                                <?php if ($l['expires_at']): ?>
                                    <span style="font-size:12px;font-weight:700;color:<?= strtotime($l['expires_at']) < time() ? 'var(--bad)' : 'var(--ok)' ?>"><?= e(date('M d, Y', strtotime($l['expires_at']))) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--dim);font-size:12px"><?= $l['duration_days'] == 0 ? 'Never' : 'Not Started' ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Note" style="color:var(--muted);font-size:12px"><?= e($l['note'] ?: '-') ?></td>
                            <td data-label="Actions">
                                <div class="actions">
                                    <form method="POST" onsubmit="return confirm('เพิ่ม +30 วัน ให้คีย์นี้?')">
                                        <input type="hidden" name="action" value="extend_days">
                                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                        <input type="hidden" name="days" value="30">
                                        <button type="submit" class="act" title="+30 Days"><i class="fa-solid fa-calendar-plus"></i></button>
                                    </form>
                                    <?php if ($l['device_id']): ?>
                                    <form method="POST" onsubmit="return confirm('รีเซ็ต HWID (ปลดล็อกเครื่อง) ของคีย์นี้?')">
                                        <input type="hidden" name="action" value="reset_hwid">
                                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                        <button type="submit" class="act" title="Reset HWID"><i class="fa-solid fa-arrows-rotate"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_ban">
                                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                        <button type="submit" class="act" title="<?= $l['status'] === 'banned' ? 'Unban' : 'Ban' ?>">
                                            <i class="fa-solid <?= $l['status'] === 'banned' ? 'fa-check' : 'fa-ban' ?>" style="color:<?= $l['status'] === 'banned' ? 'var(--ok)' : 'var(--warn)' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('ลบคีย์นี้ถาวร?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                        <button type="submit" class="act danger" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($tab === 'generator'): ?>
        <!-- ============ GENERATOR ============ -->
        <div class="card gen-wrap">
            <div class="gen-hero">
                <div class="ico"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                <div>
                    <h2>License Key Generator</h2>
                    <p>สร้างคีย์เดี่ยวหรือหลายคีย์พร้อมกัน กำหนดอายุและ Prefix ได้</p>
                </div>
            </div>
            <form method="POST" class="card-body">
                <input type="hidden" name="action" value="generate">
                <div class="grid-2">
                    <div class="field">
                        <label><i class="fa-solid fa-tag"></i>Key Prefix</label>
                        <input type="text" name="prefix" class="input" value="XSTH" placeholder="เช่น XSTH, VIP, PRO" required>
                    </div>
                    <div class="field">
                        <label><i class="fa-solid fa-hourglass-half"></i>License Duration</label>
                        <select name="duration" class="input">
                            <option value="1">1 Day (Test / Trial)</option>
                            <option value="3">3 Days</option>
                            <option value="7">7 Days (Weekly)</option>
                            <option value="15">15 Days (Half Month)</option>
                            <option value="30" selected>30 Days (Monthly)</option>
                            <option value="90">90 Days (Quarterly)</option>
                            <option value="365">365 Days (Annual VIP)</option>
                            <option value="0">Lifetime (Permanent)</option>
                        </select>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label><i class="fa-solid fa-layer-group"></i>Quantity (Batch)</label>
                        <input type="number" name="count" class="input" value="1" min="1" max="100" required>
                        <span class="hint">สร้างได้สูงสุด 100 คีย์ต่อครั้ง</span>
                    </div>
                    <div class="field">
                        <label><i class="fa-regular fa-note-sticky"></i>Note / Customer Tag</label>
                        <input type="text" name="note" class="input" placeholder="เช่น Reseller A / ลูกค้า Telegram">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="height:52px;font-size:15px;width:100%;max-width:280px;">
                    <i class="fa-solid fa-bolt"></i> Generate Keys Now
                </button>
            </form>
        </div>

        <?php elseif ($tab === 'devices'): ?>
        <!-- ============ DEVICES ============ -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fa-solid fa-mobile-screen"></i> Connected Devices (HWID)</h3>
                <span style="font-size:13px;color:var(--muted)">ทั้งหมด: <b style="color:#fff"><?= (int)$boundDevicesCount ?></b> เครื่อง</span>
            </div>
            <div class="table-wrap">
                <table class="responsive">
                    <thead><tr><th>Device HWID</th><th>Model</th><th>License Key</th><th>Expiry</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php $devices = $pdo->query("SELECT * FROM `licenses` WHERE `device_id` IS NOT NULL AND `device_id` != '' ORDER BY `activated_at` DESC")->fetchAll(); ?>
                    <?php if (empty($devices)): ?>
                        <tr><td colspan="5" class="empty">ยังไม่มีอุปกรณ์ที่ผูกไว้</td></tr>
                    <?php else: foreach ($devices as $d): ?>
                        <tr>
                            <td data-label="HWID" class="mono" style="color:#8cc4ff;font-weight:700;word-break:break-all"><?= e($d['device_id']) ?></td>
                            <td data-label="Model" style="font-weight:700"><i class="fa-brands fa-apple" style="margin-right:6px"></i><?= e($d['device_name'] ?: 'iOS Device') ?></td>
                            <td data-label="Key"><span class="key" data-copy="<?= e($d['license_key']) ?>"><?= e($d['license_key']) ?></span></td>
                            <td data-label="Expiry" style="font-size:12px;color:var(--muted)"><?= $d['expires_at'] ? e(date('M d, Y', strtotime($d['expires_at']))) : 'Lifetime' ?></td>
                            <td data-label="Actions">
                                <form method="POST" onsubmit="return confirm('ปลดล็อกเครื่องนี้เพื่อให้ผู้ใช้เปลี่ยนมือถือได้?')">
                                    <input type="hidden" name="action" value="reset_hwid">
                                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm"><i class="fa-solid fa-link-slash"></i> Reset HWID</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($tab === 'logs'): ?>
        <!-- ============ LOGS ============ -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fa-solid fa-list-check"></i> Security Audit Logs</h3>
                <form method="POST" onsubmit="return confirm('ล้าง Log ทั้งหมด?')">
                    <input type="hidden" name="action" value="clear_logs">
                    <button type="submit" class="btn btn-ghost btn-sm btn-danger-text"><i class="fa-solid fa-broom"></i> Clear Logs</button>
                </form>
            </div>
            <div class="table-wrap">
                <table class="responsive">
                    <thead><tr><th>Time</th><th>License Key</th><th>Event</th><th>Device HWID</th><th>Model</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php if (empty($recentLogs)): ?>
                        <tr><td colspan="6" class="empty">ไม่พบบันทึก</td></tr>
                    <?php else: foreach ($recentLogs as $log): ?>
                        <tr>
                            <td data-label="Time" style="color:var(--dim);font-size:12px"><?= e(date('Y-m-d H:i:s', strtotime($log['created_at']))) ?></td>
                            <td data-label="Key"><span class="key" data-copy="<?= e($log['license_key']) ?>"><?= e($log['license_key']) ?></span></td>
                            <td data-label="Event">
                                <?php if (strpos($log['action'], 'SUCCESS') !== false): ?>
                                    <span class="badge active"><?= e($log['action']) ?></span>
                                <?php elseif (strpos($log['action'], 'BLOCKED') !== false || strpos($log['action'], 'FAILED') !== false): ?>
                                    <span class="badge expired"><?= e($log['action']) ?></span>
                                <?php else: ?>
                                    <span class="badge unused"><?= e($log['action']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="HWID" class="mono" style="color:var(--muted);font-size:11px;word-break:break-all"><?= e($log['device_id'] ?: '-') ?></td>
                            <td data-label="Model" style="font-weight:600"><?= e($log['device_name'] ?: 'iOS') ?></td>
                            <td data-label="IP" style="color:var(--dim);font-size:12px"><?= e($log['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($tab === 'settings'): ?>
        <!-- ============ SETTINGS ============ -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,360px),1fr));gap:20px;">
            <div class="card">
                <div class="card-head"><h3><i class="fa-solid fa-lock"></i> เปลี่ยนรหัสผ่านแอดมิน</h3></div>
                <div class="card-body">
                    <p style="font-size:13px;color:var(--muted);margin-bottom:20px">อัปเดตรหัสผ่านหลักเพื่อป้องกันการเข้าถึงโดยไม่ได้รับอนุญาต</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="field">
                            <label>Admin Username</label>
                            <input type="text" class="input" value="<?= e($_SESSION['admin_user']) ?>" disabled>
                        </div>
                        <div class="field">
                            <label>New Password (อย่างน้อย 6 ตัวอักษร)</label>
                            <input type="password" name="new_password" class="input" placeholder="••••••••••••" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h3><i class="fa-solid fa-code"></i> iOS App API</h3></div>
                <div class="card-body">
                    <p style="font-size:13px;color:var(--muted);margin-bottom:20px">ใช้ URL นี้ในโปรเจกต์ Xcode ที่ไฟล์ <code>LicenseManager.swift</code></p>
                    <div class="field">
                        <label>API Endpoint URL</label>
                        <div style="display:flex;gap:8px">
                            <input type="text" id="apiEndpoint" class="input mono" value="<?= e($apiEndpointURL) ?>" readonly style="color:#8cc4ff">
                            <button class="btn btn-ghost" style="height:50px" onclick="copyToClipboard(document.getElementById('apiEndpoint').value)"><i class="fa-regular fa-copy"></i></button>
                        </div>
                    </div>
                    <div class="code-box">
                        <p style="margin-bottom:6px"><b style="color:#fff">In iOS Swift:</b></p>
                        <code>static var apiURL = "<?= e($apiEndpointURL) ?>"</code>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<div class="toasts" id="toastStack"></div>

<script>
    /* ---------- Sidebar toggle ---------- */
    (function () {
        const root = document.documentElement;
        const btn = document.getElementById('sbToggle');
        const icon = document.getElementById('sbToggleIcon');
        const overlay = document.getElementById('overlay');
        const isMobile = () => window.innerWidth <= 960;

        function syncIcon() {
            const open = isMobile() ? root.classList.contains('sb-open') : !root.classList.contains('sb-collapsed');
            icon.className = 'fa-solid ' + (open ? 'fa-bars-staggered' : 'fa-bars');
        }
        btn.addEventListener('click', () => {
            if (isMobile()) {
                root.classList.toggle('sb-open');
            } else {
                root.classList.toggle('sb-collapsed');
                try { localStorage.setItem('xsth_sb', root.classList.contains('sb-collapsed') ? '1' : '0'); } catch (e) {}
                setTimeout(() => window.dispatchEvent(new Event('resize')), 320); // ให้ chart ปรับขนาด
            }
            syncIcon();
        });
        overlay.addEventListener('click', () => { root.classList.remove('sb-open'); syncIcon(); });
        document.querySelectorAll('.sidebar .nav-item').forEach(a => a.addEventListener('click', () => root.classList.remove('sb-open')));
        document.addEventListener('keydown', e => { if (e.key === 'Escape') { root.classList.remove('sb-open'); syncIcon(); } });
        window.addEventListener('resize', () => { if (!isMobile()) root.classList.remove('sb-open'); syncIcon(); });
        syncIcon();
    })();

    /* ---------- Toast ---------- */
    const TOAST_META = {
        success: { icon: 'fa-check', title: 'สำเร็จ' },
        error:   { icon: 'fa-triangle-exclamation', title: 'เกิดข้อผิดพลาด' },
        info:    { icon: 'fa-circle-info', title: 'แจ้งเตือน' }
    };
    function showToast(message, type = 'success', duration = 3500) {
        const stack = document.getElementById('toastStack');
        const meta = TOAST_META[type] || TOAST_META.info;
        const t = document.createElement('div');
        t.className = 'toast ' + type;
        t.style.setProperty('--dur', duration + 'ms');
        t.innerHTML = `<div class="toast-ico"><i class="fa-solid ${meta.icon}"></i></div>
            <div class="toast-body"><div class="toast-title">${meta.title}</div><div class="toast-msg"></div></div>
            <button class="toast-x" aria-label="Close"><i class="fa-solid fa-xmark"></i></button><div class="toast-bar"></div>`;
        t.querySelector('.toast-msg').textContent = message;
        const close = () => { if (t.classList.contains('hide')) return; t.classList.add('hide'); setTimeout(() => t.remove(), 280); };
        t.querySelector('.toast-x').onclick = close;
        t.querySelector('.toast-bar').addEventListener('animationend', close);
        stack.appendChild(t);
    }
    <?php if ($alert): ?>
    document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($alert) ?>, <?= json_encode($alertType) ?>));
    <?php endif; ?>

    /* ---------- Clipboard ---------- */
    function copyText(text, msg) {
        const done = () => showToast(msg, 'info', 2200);
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            ta.remove();
        }
    }
    function copyKey(key) { copyText(key, 'คัดลอกคีย์แล้ว: ' + key); }
    function copyToClipboard(text) { copyText(text, 'คัดลอกแล้ว'); }
    function copyGeneratedKeys() { copyText(document.getElementById('generatedKeysText').value, 'คัดลอกคีย์ทั้งหมดแล้ว'); }
    document.addEventListener('click', e => {
        const el = e.target.closest('[data-copy]');
        if (el) copyKey(el.dataset.copy);
    });

    function downloadKeysText() {
        const txt = document.getElementById('generatedKeysText').value;
        const a = document.createElement('a');
        a.download = 'XSTH_Keys_' + new Date().toISOString().slice(0, 10) + '.txt';
        a.href = URL.createObjectURL(new Blob([txt], { type: 'text/plain' }));
        a.click();
    }

    function exportTableToCSV() {
        const csv = [];
        document.querySelectorAll('#licensesTable tr').forEach(r => {
            const cols = r.querySelectorAll('td, th'), row = [];
            for (let j = 0; j < cols.length - 1; j++) {
                row.push('"' + cols[j].innerText.replace(/\s+/g, ' ').trim().replace(/"/g, '""') + '"');
            }
            csv.push(row.join(','));
        });
        const a = document.createElement('a');
        a.download = 'XSTH_Licenses_Export.csv';
        a.href = URL.createObjectURL(new Blob(['\ufeff' + csv.join('\n')], { type: 'text/csv;charset=utf-8' }));
        a.click();
    }

    <?php if ($tab === 'dashboard'): ?>
    /* ---------- Charts ---------- */
    document.addEventListener('DOMContentLoaded', () => {
        Chart.defaults.font.family = "'Plus Jakarta Sans','Noto Sans Thai',sans-serif";
        const ctx = document.getElementById('activationsChart').getContext('2d');
        const g = ctx.createLinearGradient(0, 0, 0, 260);
        g.addColorStop(0, 'rgba(91,109,255,0.4)');
        g.addColorStop(1, 'rgba(91,109,255,0)');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($sevenDaysActivations, 'day')) ?>,
                datasets: [{
                    label: 'Activations',
                    data: <?= json_encode(array_column($sevenDaysActivations, 'count')) ?>,
                    borderColor: '#6aa9ff', borderWidth: 3, backgroundColor: g, fill: true, tension: 0.4,
                    pointBackgroundColor: '#fff', pointBorderColor: '#6aa9ff', pointRadius: 4, pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#6c7f9c' } },
                    y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#6c7f9c', stepSize: 1 }, beginAtZero: true }
                }
            }
        });
        new Chart(document.getElementById('statusChart').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Unused', 'Expired', 'Banned'],
                datasets: [{
                    data: [<?= (int)$activeKeys ?>, <?= (int)$unusedKeys ?>, <?= (int)$expiredKeys ?>, <?= (int)$bannedKeys ?>],
                    backgroundColor: ['#22c997', '#f5b13d', '#ff5d6c', '#b08cff'],
                    borderWidth: 0, hoverOffset: 6
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '72%',
                plugins: { legend: { position: 'bottom', labels: { color: '#9db0cc', usePointStyle: true, padding: 14, font: { weight: '600' } } } }
            }
        });
    });
    <?php endif; ?>
</script>
</body>
</html>