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

// Chart Data 2: Duration Distribution
$durations = [
    '1D' => (int)$pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `duration_days` = 1")->fetchColumn(),
    '7D' => (int)$pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `duration_days` = 7")->fetchColumn(),
    '30D' => (int)$pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `duration_days` = 30")->fetchColumn(),
    'Lifetime' => (int)$pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `duration_days` = 0")->fetchColumn()
];

// Tab Routing
$tab = $_GET['tab'] ?? 'dashboard';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OGIOS • License Management Studio</title>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --bg-base: #08090d;
            --bg-sidebar: #0f1118;
            --bg-card: #141722;
            --bg-card-hover: #191d2b;
            --border: #212638;
            --border-light: rgba(255, 255, 255, 0.08);
            --accent: #e11d48;
            --accent-glow: rgba(225, 29, 72, 0.35);
            --accent-gradient: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
            --text-main: #ffffff;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --info: #38bdf8;
            --danger: #ef4444;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg-base); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; }

        /* ================= SIDEBAR ================= */
        .sidebar {
            width: 270px;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; bottom: 0; left: 0;
            z-index: 50;
            transition: 0.3s ease;
        }
        .sidebar-brand {
            padding: 26px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--border);
        }
        .brand-icon {
            width: 42px; height: 42px;
            background: var(--accent-gradient);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: #fff;
            box-shadow: 0 4px 14px var(--accent-glow);
        }
        .brand-text h2 { font-size: 20px; font-weight: 900; letter-spacing: 1.5px; }
        .brand-text p { font-size: 10px; font-weight: 700; color: var(--accent); letter-spacing: 1.2px; text-transform: uppercase; }

        .nav-menu { padding: 20px 14px; flex: 1; display: flex; flex-direction: column; gap: 6px; }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 13px 16px;
            border-radius: 14px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            transition: 0.2s;
        }
        .nav-item i { font-size: 17px; width: 22px; text-align: center; }
        .nav-item:hover { color: #fff; background: rgba(255,255,255,0.04); }
        .nav-item.active {
            color: #fff;
            background: var(--accent-gradient);
            box-shadow: 0 6px 16px var(--accent-glow);
        }

        .sidebar-footer {
            padding: 18px;
            border-top: 1px solid var(--border);
            background: rgba(0,0,0,0.2);
        }
        .admin-profile {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .admin-info { display: flex; align-items: center; gap: 10px; }
        .avatar-circle {
            width: 38px; height: 38px;
            border-radius: 10px;
            background: #1e2230;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 14px;
            border: 1px solid var(--border);
        }
        .admin-name { font-size: 13px; font-weight: 800; color: #fff; }
        .admin-role { font-size: 11px; color: var(--success); font-weight: 600; }
        .btn-logout { color: var(--text-dim); text-decoration: none; font-size: 16px; padding: 8px; border-radius: 8px; transition: 0.2s; }
        .btn-logout:hover { color: var(--danger); background: rgba(239, 68, 68, 0.1); }

        /* ================= MAIN CONTENT ================= */
        .main-wrapper {
            margin-left: 270px;
            flex: 1;
            padding: 32px 36px;
            max-width: 1550px;
        }

        /* Top Bar */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-title h1 { font-size: 26px; font-weight: 900; color: #fff; letter-spacing: -0.5px; }
        .page-title p { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

        .header-actions { display: flex; align-items: center; gap: 12px; }

        /* Alert Banner */
        .alert-box {
            padding: 14px 20px;
            border-radius: 14px;
            margin-bottom: 26px;
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeIn 0.3s ease;
        }
        .alert-box.success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.35); color: #34d399; }
        .alert-box.error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.35); color: #f87171; }

        /* Metric Cards */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        .metric-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: 0.25s ease;
        }
        .metric-card:hover { transform: translateY(-3px); border-color: rgba(255,255,255,0.15); }
        .metric-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .metric-title { font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .metric-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .metric-icon.blue { background: rgba(56, 189, 248, 0.12); color: var(--info); }
        .metric-icon.green { background: rgba(16, 185, 129, 0.12); color: var(--success); }
        .metric-icon.amber { background: rgba(245, 158, 11, 0.12); color: var(--warning); }
        .metric-icon.red { background: rgba(225, 29, 72, 0.12); color: var(--accent); }

        .metric-value { font-size: 32px; font-weight: 900; color: #fff; letter-spacing: -1px; }
        .metric-subtitle { font-size: 12px; color: var(--text-dim); margin-top: 4px; font-weight: 600; }

        /* Charts Row */
        .charts-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }
        @media (max-width: 1080px) { .charts-row { grid-template-columns: 1fr; } }
        .chart-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px;
        }
        .card-header-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .card-header-title h3 { font-size: 16px; font-weight: 800; color: #fff; }
        .chart-container { position: relative; height: 260px; width: 100%; }

        /* Data Card & Tables */
        .content-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 22px;
            overflow: hidden;
            margin-bottom: 28px;
        }
        .card-toolbar {
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 16px;
        }
        .filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-btn {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-muted);
            text-decoration: none;
            background: rgba(255,255,255,0.03);
            border: 1px solid transparent;
            transition: 0.2s;
        }
        .filter-btn:hover { color: #fff; background: rgba(255,255,255,0.07); }
        .filter-btn.active {
            color: #fff;
            background: rgba(225, 29, 72, 0.15);
            border-color: rgba(225, 29, 72, 0.4);
        }

        .search-group { display: flex; gap: 8px; }
        .search-input {
            padding: 10px 16px;
            background: #0a0c12;
            border: 1px solid var(--border);
            border-radius: 12px;
            color: #fff;
            font-size: 13px;
            width: 260px;
            outline: none;
        }
        .search-input:focus { border-color: var(--accent); }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th {
            padding: 16px 22px;
            background: #0d0f17;
            color: var(--text-dim);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 16px 22px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            font-size: 13px;
            vertical-align: middle;
        }
        tr:hover { background: rgba(255,255,255,0.02); }

        /* Key Pill Badge */
        .key-text {
            font-family: monospace;
            font-size: 13px;
            font-weight: 800;
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.08);
            border: 1px solid rgba(56, 189, 248, 0.2);
            padding: 6px 12px;
            border-radius: 9px;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .key-text:hover { background: rgba(56, 189, 248, 0.18); color: #fff; }

        /* Status Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .badge.active { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); }
        .badge.unused { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.35); }
        .badge.expired { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.35); }
        .badge.banned { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.35); }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 13px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-primary { background: var(--accent-gradient); color: #fff; box-shadow: 0 4px 14px var(--accent-glow); }
        .btn-primary:hover { transform: translateY(-1px); filter: brightness(1.1); }
        .btn-secondary { background: #1b1f2b; color: #fff; border: 1px solid var(--border); }
        .btn-secondary:hover { background: #252a3a; }
        .btn-action-icon {
            width: 34px; height: 34px;
            border-radius: 9px;
            border: 1px solid var(--border);
            background: #181b26;
            color: var(--text-muted);
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .btn-action-icon:hover { color: #fff; background: #242938; }
        .btn-action-icon.danger:hover { color: var(--danger); border-color: var(--danger); }

        /* Newly Generated Modal / Banner */
        .new-keys-box {
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 18px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .new-keys-textarea {
            width: 100%;
            height: 120px;
            background: #090b10;
            border: 1px solid rgba(16, 185, 129, 0.25);
            border-radius: 12px;
            color: #4ade80;
            font-family: monospace;
            padding: 12px;
            font-size: 13px;
            margin-top: 10px;
            outline: none;
        }

        /* Form Components */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; font-weight: 700; color: var(--text-muted); margin-bottom: 8px; }
        .form-control {
            width: 100%;
            padding: 13px 16px;
            background: #0c0e14;
            border: 1px solid var(--border);
            border-radius: 12px;
            color: #fff;
            font-size: 14px;
            outline: none;
        }
        .form-control:focus { border-color: var(--accent); }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

    <!-- ================= SIDEBAR ================= -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div class="brand-text">
                <h2>OGIOS</h2>
                <p>License Studio Pro</p>
            </div>
        </div>

        <nav class="nav-menu">
            <a href="index.php?tab=dashboard" class="nav-item <?= $tab === 'dashboard' ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-pie"></i> Dashboard
            </a>
            <a href="index.php?tab=licenses" class="nav-item <?= $tab === 'licenses' ? 'active' : '' ?>">
                <i class="fa-solid fa-key"></i> License Keys
            </a>
            <a href="index.php?tab=generator" class="nav-item <?= $tab === 'generator' ? 'active' : '' ?>">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Generator Studio
            </a>
            <a href="index.php?tab=devices" class="nav-item <?= $tab === 'devices' ? 'active' : '' ?>">
                <i class="fa-solid fa-mobile-screen-button"></i> Active Devices
            </a>
            <a href="index.php?tab=logs" class="nav-item <?= $tab === 'logs' ? 'active' : '' ?>">
                <i class="fa-solid fa-clock-rotate-left"></i> Activity Logs
            </a>
            <a href="index.php?tab=settings" class="nav-item <?= $tab === 'settings' ? 'active' : '' ?>">
                <i class="fa-solid fa-sliders"></i> System Settings
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="admin-profile">
                <div class="admin-info">
                    <div class="avatar-circle"><?= strtoupper(substr($_SESSION['admin_user'], 0, 1)) ?></div>
                    <div>
                        <div class="admin-name"><?= htmlspecialchars($_SESSION['admin_user']) ?></div>
                        <div class="admin-role">Super Admin</div>
                    </div>
                </div>
                <a href="logout.php" class="btn-logout" title="Sign Out"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
            </div>
        </div>
    </aside>

    <!-- ================= MAIN CONTENT ================= -->
    <main class="main-wrapper">

        <!-- Top Header -->
        <div class="top-header">
            <div class="page-title">
                <h1>
                    <?php 
                        switch($tab) {
                            case 'licenses': echo 'License Management'; break;
                            case 'generator': echo 'Key Generator Studio'; break;
                            case 'devices': echo 'Connected Devices (HWID)'; break;
                            case 'logs': echo 'Audit & Security Logs'; break;
                            case 'settings': echo 'System & Security Settings'; break;
                            default: echo 'Dashboard Overview';
                        }
                    ?>
                </h1>
                <p>Manage, generate and enforce licenses for your iOS OGIOS applications</p>
            </div>
            <div class="header-actions">
                <a href="index.php?tab=generator" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Generate Keys</a>
            </div>
        </div>

        <?php if ($alert): ?>
            <div class="alert-box <?= $alertType ?>">
                <i class="fa-solid <?= $alertType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                <span><?= htmlspecialchars($alert) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($newlyGeneratedKeys)): ?>
            <div class="new-keys-box">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="color: #34d399; font-size: 15px; font-weight: 800;"><i class="fa-solid fa-sparkles"></i> Keys Generated Successfully!</h3>
                    <div style="display: flex; gap: 8px;">
                        <button onclick="copyGeneratedKeys()" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;"><i class="fa-regular fa-copy"></i> Copy All</button>
                        <button onclick="downloadKeysText()" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;"><i class="fa-solid fa-download"></i> Save .txt</button>
                    </div>
                </div>
                <textarea id="generatedKeysText" class="new-keys-textarea" readonly><?= implode("\n", $newlyGeneratedKeys) ?></textarea>
            </div>
        <?php endif; ?>

        <!-- ================= TAB: DASHBOARD ================= -->
        <?php if ($tab === 'dashboard'): ?>
            <!-- Metrics -->
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-title">Total Keys</span>
                        <div class="metric-icon blue"><i class="fa-solid fa-key"></i></div>
                    </div>
                    <div class="metric-value"><?= number_format($totalKeys) ?></div>
                    <div class="metric-subtitle">Generated in database</div>
                </div>
                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-title">Active Licenses</span>
                        <div class="metric-icon green"><i class="fa-solid fa-shield-check"></i></div>
                    </div>
                    <div class="metric-value" style="color: var(--success);"><?= number_format($activeKeys) ?></div>
                    <div class="metric-subtitle">Currently active & working</div>
                </div>
                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-title">Unused Stock</span>
                        <div class="metric-icon amber"><i class="fa-solid fa-boxes-stacked"></i></div>
                    </div>
                    <div class="metric-value" style="color: var(--warning);"><?= number_format($unusedKeys) ?></div>
                    <div class="metric-subtitle">Ready for delivery to buyers</div>
                </div>
                <div class="metric-card">
                    <div class="metric-header">
                        <span class="metric-title">Bound Devices</span>
                        <div class="metric-icon red"><i class="fa-solid fa-mobile-screen"></i></div>
                    </div>
                    <div class="metric-value" style="color: var(--accent);"><?= number_format($boundDevicesCount) ?></div>
                    <div class="metric-subtitle">Unique hardware IDs locked</div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="charts-row">
                <div class="chart-card">
                    <div class="card-header-title">
                        <h3><i class="fa-solid fa-chart-line" style="color: var(--accent); margin-right: 8px;"></i> Activations Trend (Last 7 Days)</h3>
                        <span style="font-size: 12px; color: var(--text-dim);">Live API Sync</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="activationsChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="card-header-title">
                        <h3><i class="fa-solid fa-chart-pie" style="color: var(--info); margin-right: 8px;"></i> License Status Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Activations Table -->
            <div class="content-card">
                <div class="card-toolbar">
                    <h3 style="font-size: 15px; font-weight: 800;"><i class="fa-solid fa-clock" style="color: var(--accent); margin-right: 8px;"></i> Recent Audit Activations</h3>
                    <a href="index.php?tab=logs" class="btn btn-secondary" style="font-size: 12px;">View All Logs →</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>License Key</th>
                            <th>Action</th>
                            <th>Device HWID</th>
                            <th>Device Model</th>
                            <th>IP Address</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentLogs)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-dim); padding: 30px;">No audit activity logged yet.</td></tr>
                        <?php else: ?>
                            <?php foreach (array_slice($recentLogs, 0, 6) as $log): ?>
                                <tr>
                                    <td><span class="key-text" onclick="copyKey('<?= $log['license_key'] ?>')"><?= htmlspecialchars($log['license_key']) ?></span></td>
                                    <td><span class="badge active" style="font-size: 10px;"><?= htmlspecialchars($log['action']) ?></span></td>
                                    <td style="font-family: monospace; color: var(--text-muted); font-size: 12px;"><?= htmlspecialchars(substr($log['device_id'] ?? '-', 0, 16)) ?></td>
                                    <td style="color: #fff; font-weight: 600;"><?= htmlspecialchars($log['device_name'] ?: 'iOS') ?></td>
                                    <td style="color: var(--text-dim); font-size: 12px;"><?= htmlspecialchars($log['ip_address']) ?></td>
                                    <td style="color: var(--text-muted); font-size: 12px;"><?= date('M d, H:i', strtotime($log['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <!-- ================= TAB: LICENSES ================= -->
        <?php elseif ($tab === 'licenses'): ?>
            <div class="content-card">
                <div class="card-toolbar">
                    <!-- Filter Tabs -->
                    <div class="filter-tabs">
                        <a href="index.php?tab=licenses" class="filter-btn <?= $statusFilter === '' ? 'active' : '' ?>">All (<?= $totalKeys ?>)</a>
                        <a href="index.php?tab=licenses&status=unused" class="filter-btn <?= $statusFilter === 'unused' ? 'active' : '' ?>">Unused (<?= $unusedKeys ?>)</a>
                        <a href="index.php?tab=licenses&status=active" class="filter-btn <?= $statusFilter === 'active' ? 'active' : '' ?>">Active (<?= $activeKeys ?>)</a>
                        <a href="index.php?tab=licenses&status=expired" class="filter-btn <?= $statusFilter === 'expired' ? 'active' : '' ?>">Expired (<?= $expiredKeys ?>)</a>
                        <a href="index.php?tab=licenses&status=banned" class="filter-btn <?= $statusFilter === 'banned' ? 'active' : '' ?>">Banned (<?= $bannedKeys ?>)</a>
                    </div>

                    <!-- Search & Actions -->
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button onclick="exportTableToCSV()" class="btn btn-secondary" title="Export current list to CSV"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                        <form method="GET" class="search-group">
                            <input type="hidden" name="tab" value="licenses">
                            <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
                            <input type="text" name="q" class="search-input" placeholder="Search Key, HWID, Note..." value="<?= htmlspecialchars($search) ?>">
                            <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-magnifying-glass"></i></button>
                        </form>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table id="licensesTable">
                        <thead>
                            <tr>
                                <th>License Key</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Bound Device (HWID)</th>
                                <th>Activated Date</th>
                                <th>Expiration</th>
                                <th>Note / Client</th>
                                <th>Quick Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($licenses)): ?>
                                <tr><td colspan="8" style="text-align: center; color: var(--text-dim); padding: 40px;">No licenses found matching your criteria.</td></tr>
                            <?php else: ?>
                                <?php foreach ($licenses as $l): ?>
                                    <tr>
                                        <td>
                                            <span class="key-text" onclick="copyKey('<?= $l['license_key'] ?>')" title="Click to copy">
                                                <i class="fa-regular fa-copy"></i> <?= htmlspecialchars($l['license_key']) ?>
                                            </span>
                                        </td>
                                        <td style="font-weight: 700;">
                                            <?= $l['duration_days'] > 0 ? $l['duration_days'] . ' Days' : '<span style="color: #c084fc;">Lifetime</span>' ?>
                                        </td>
                                        <td><span class="badge <?= $l['status'] ?>"><?= $l['status'] ?></span></td>
                                        <td>
                                            <?php if ($l['device_id']): ?>
                                                <div style="font-family: monospace; font-size: 11px; color: #fff; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($l['device_id']) ?>">
                                                    <?= htmlspecialchars(substr($l['device_id'], 0, 14)) ?>...
                                                </div>
                                                <div style="font-size: 10px; color: var(--text-dim);"><?= htmlspecialchars($l['device_name'] ?: 'iOS') ?></div>
                                            <?php else: ?>
                                                <span style="color: var(--text-dim); font-size: 12px;">Unbound</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="color: var(--text-muted); font-size: 12px;">
                                                <?= $l['activated_at'] ? date('M d, Y', strtotime($l['activated_at'])) : '-' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($l['expires_at']): ?>
                                                <div style="font-size: 12px; font-weight: 700; color: <?= strtotime($l['expires_at']) < time() ? 'var(--danger)' : 'var(--success)' ?>">
                                                    <?= date('M d, Y', strtotime($l['expires_at'])) ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-dim); font-size: 12px;"><?= $l['duration_days'] == 0 ? 'Never' : 'Not Started' ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color: var(--text-muted); font-size: 12px;"><?= htmlspecialchars($l['note'] ?: '-') ?></td>
                                        <td>
                                            <div style="display: flex; gap: 6px;">
                                                <!-- Extend Days -->
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Add +30 Days to this license?')">
                                                    <input type="hidden" name="action" value="extend_days">
                                                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                    <input type="hidden" name="days" value="30">
                                                    <button type="submit" class="btn-action-icon" title="+30 Days"><i class="fa-solid fa-calendar-plus"></i></button>
                                                </form>

                                                <!-- Reset HWID -->
                                                <?php if ($l['device_id']): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Reset HWID (Unbind phone) for this key?')">
                                                        <input type="hidden" name="action" value="reset_hwid">
                                                        <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                        <button type="submit" class="btn-action-icon" title="Reset HWID"><i class="fa-solid fa-arrows-rotate"></i></button>
                                                    </form>
                                                <?php endif; ?>

                                                <!-- Toggle Ban -->
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="toggle_ban">
                                                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                    <button type="submit" class="btn-action-icon" title="<?= $l['status'] === 'banned' ? 'Unban' : 'Ban' ?>">
                                                        <i class="fa-solid <?= $l['status'] === 'banned' ? 'fa-check' : 'fa-ban' ?>" style="color: <?= $l['status'] === 'banned' ? 'var(--success)' : 'var(--warning)' ?>"></i>
                                                    </button>
                                                </form>

                                                <!-- Delete -->
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this key permanently?')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                    <button type="submit" class="btn-action-icon danger" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ================= TAB: GENERATOR STUDIO ================= -->
        <?php elseif ($tab === 'generator'): ?>
            <div class="content-card" style="max-width: 800px; padding: 32px;">
                <h2 style="font-size: 20px; font-weight: 900; margin-bottom: 8px;"><i class="fa-solid fa-wand-magic-sparkles" style="color: var(--accent); margin-right: 8px;"></i> License Key Generator Studio</h2>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 26px;">Create single or bulk batches of licenses with custom durations and prefixes.</p>

                <form method="POST">
                    <input type="hidden" name="action" value="generate">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fa-solid fa-tag"></i> Key Prefix</label>
                            <input type="text" name="prefix" class="form-control" value="OGIOS" placeholder="e.g. OGIOS, VIP, PRO" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fa-solid fa-hourglass-half"></i> License Duration</label>
                            <select name="duration" class="form-control">
                                <option value="1">1 Day (Test / Trial)</option>
                                <option value="3">3 Days</option>
                                <option value="7">7 Days (Weekly)</option>
                                <option value="15">15 Days (Half Month)</option>
                                <option value="30" selected>30 Days (Monthly Package)</option>
                                <option value="90">90 Days (Quarterly)</option>
                                <option value="365">365 Days (Annual VIP)</option>
                                <option value="0">Lifetime (Permanent)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fa-solid fa-layer-group"></i> Quantity to Generate (Batch)</label>
                            <input type="number" name="count" class="form-control" value="1" min="1" max="100" required>
                            <span style="font-size: 11px; color: var(--text-dim); margin-top: 4px; display: block;">You can generate up to 100 keys at once.</span>
                        </div>
                        <div class="form-group">
                            <label><i class="fa-regular fa-note-sticky"></i> Note / Customer Tag</label>
                            <input type="text" name="note" class="form-control" placeholder="e.g. Reseller A / Customer Telegram">
                        </div>
                    </div>

                    <div style="margin-top: 24px; display: flex; gap: 14px;">
                        <button type="submit" class="btn btn-primary" style="padding: 14px 28px; font-size: 14px;">
                            <i class="fa-solid fa-bolt"></i> Generate Keys Now
                        </button>
                    </div>
                </form>
            </div>

        <!-- ================= TAB: ACTIVE DEVICES ================= -->
        <?php elseif ($tab === 'devices'): ?>
            <div class="content-card">
                <div class="card-toolbar">
                    <h3 style="font-size: 15px; font-weight: 800;"><i class="fa-solid fa-mobile-screen" style="color: var(--accent); margin-right: 8px;"></i> Connected Devices Hardware Lock (HWID)</h3>
                    <span style="font-size: 13px; color: var(--text-muted);">Total Bound Phones: <?= $boundDevicesCount ?></span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Device HWID</th>
                            <th>Device Model</th>
                            <th>Bound License Key</th>
                            <th>Expiry</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                            $devices = $pdo->query("SELECT * FROM `licenses` WHERE `device_id` IS NOT NULL AND `device_id` != '' ORDER BY `activated_at` DESC")->fetchAll();
                        ?>
                        <?php if (empty($devices)): ?>
                            <tr><td colspan="5" style="text-align: center; color: var(--text-dim); padding: 40px;">No devices currently bound.</td></tr>
                        <?php else: ?>
                            <?php foreach ($devices as $d): ?>
                                <tr>
                                    <td style="font-family: monospace; color: #38bdf8; font-weight: 700;"><?= htmlspecialchars($d['device_id']) ?></td>
                                    <td style="font-weight: 700; color: #fff;"><i class="fa-brands fa-apple" style="margin-right: 6px;"></i> <?= htmlspecialchars($d['device_name'] ?: 'iOS Device') ?></td>
                                    <td><span class="key-text" onclick="copyKey('<?= $d['license_key'] ?>')"><?= htmlspecialchars($d['license_key']) ?></span></td>
                                    <td style="font-size: 12px; color: var(--text-muted);"><?= $d['expires_at'] ? date('M d, Y', strtotime($d['expires_at'])) : 'Lifetime' ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Unbind this device so the user can switch phone?')">
                                            <input type="hidden" name="action" value="reset_hwid">
                                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                            <button type="submit" class="btn btn-secondary" style="padding: 6px 12px; font-size: 11px;"><i class="fa-solid fa-link-slash"></i> Reset HWID</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <!-- ================= TAB: LOGS ================= -->
        <?php elseif ($tab === 'logs'): ?>
            <div class="content-card">
                <div class="card-toolbar">
                    <h3 style="font-size: 15px; font-weight: 800;"><i class="fa-solid fa-list-check" style="color: var(--accent); margin-right: 8px;"></i> Security Audit Logs</h3>
                    <form method="POST" onsubmit="return confirm('Clear all audit logs?')">
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="btn btn-secondary" style="font-size: 12px; color: var(--danger);"><i class="fa-solid fa-broom"></i> Clear Logs</button>
                    </form>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>License Key</th>
                            <th>Action / Event</th>
                            <th>Device HWID</th>
                            <th>Device Model</th>
                            <th>Client IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentLogs)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-dim); padding: 40px;">No audit records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td style="color: var(--text-dim); font-size: 12px;"><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></td>
                                    <td><span class="key-text" onclick="copyKey('<?= $log['license_key'] ?>')"><?= htmlspecialchars($log['license_key']) ?></span></td>
                                    <td>
                                        <?php if (strpos($log['action'], 'SUCCESS') !== false): ?>
                                            <span class="badge active"><?= htmlspecialchars($log['action']) ?></span>
                                        <?php elseif (strpos($log['action'], 'BLOCKED') !== false || strpos($log['action'], 'FAILED') !== false): ?>
                                            <span class="badge expired"><?= htmlspecialchars($log['action']) ?></span>
                                        <?php else: ?>
                                            <span class="badge unused"><?= htmlspecialchars($log['action']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-family: monospace; color: var(--text-muted); font-size: 11px;"><?= htmlspecialchars($log['device_id'] ?: '-') ?></td>
                                    <td style="color: #fff; font-weight: 600;"><?= htmlspecialchars($log['device_name'] ?: 'iOS') ?></td>
                                    <td style="color: var(--text-dim); font-size: 12px;"><?= htmlspecialchars($log['ip_address']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <!-- ================= TAB: SETTINGS ================= -->
        <?php elseif ($tab === 'settings'): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px;">
                <!-- Security Settings -->
                <div class="content-card" style="padding: 28px;">
                    <h3 style="font-size: 17px; font-weight: 800; margin-bottom: 8px;"><i class="fa-solid fa-lock" style="color: var(--accent); margin-right: 8px;"></i> Change Admin Password</h3>
                    <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 22px;">Update your master credentials to prevent unauthorized panel access.</p>

                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label>Admin Username</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['admin_user']) ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label>New Password (min 6 characters)</label>
                            <input type="password" name="new_password" class="form-control" placeholder="••••••••••••" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Update Password</button>
                    </form>
                </div>

                <!-- API Integration Information -->
                <div class="content-card" style="padding: 28px;">
                    <h3 style="font-size: 17px; font-weight: 800; margin-bottom: 8px;"><i class="fa-solid fa-code" style="color: var(--info); margin-right: 8px;"></i> iOS App API Integration</h3>
                    <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 22px;">Use this URL in your iOS Xcode project inside <code>LicenseManager.swift</code>.</p>

                    <div class="form-group">
                        <label>API Endpoint URL</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="apiEndpoint" class="form-control" value="<?= htmlspecialchars($apiEndpointURL) ?>" readonly style="font-family: monospace; color: #38bdf8;">
                            <button onclick="copyToClipboard(document.getElementById('apiEndpoint').value)" class="btn btn-secondary"><i class="fa-regular fa-copy"></i></button>
                        </div>
                    </div>

                    <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 14px; padding: 16px; margin-top: 18px; font-size: 12px; line-height: 1.6; color: var(--text-muted);">
                        <p><strong style="color: #fff;">In iOS Swift:</strong></p>
                        <code style="color: #4ade80;">static var apiURL = "<?= htmlspecialchars($apiEndpointURL) ?>"</code>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- ================= SCRIPTS ================= -->
    <script>
        function copyKey(key) {
            navigator.clipboard.writeText(key).then(() => {
                alert('Copied License Key: ' + key);
            });
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Copied to clipboard!');
            });
        }

        function copyGeneratedKeys() {
            const txt = document.getElementById('generatedKeysText');
            navigator.clipboard.writeText(txt.value).then(() => {
                alert('All generated keys copied to clipboard!');
            });
        }

        function downloadKeysText() {
            const txt = document.getElementById('generatedKeysText').value;
            const blob = new Blob([txt], { type: 'text/plain' });
            const anchor = document.createElement('a');
            anchor.download = 'OGIOS_Keys_' + new Date().toISOString().slice(0,10) + '.txt';
            anchor.href = window.URL.createObjectURL(blob);
            anchor.click();
        }

        function exportTableToCSV() {
            let csv = [];
            let rows = document.querySelectorAll("#licensesTable tr");
            for (let i = 0; i < rows.length; i++) {
                let row = [], cols = rows[i].querySelectorAll("td, th");
                for (let j = 0; j < cols.length - 1; j++) {
                    let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim();
                    row.push('"' + text.replace(/"/g, '""') + '"');
                }
                csv.push(row.join(","));
            }
            let blob = new Blob([csv.join("\n")], { type: "text/csv" });
            let a = document.createElement("a");
            a.download = "OGIOS_Licenses_Export.csv";
            a.href = window.URL.createObjectURL(blob);
            a.click();
        }

        // Initialize Charts if on Dashboard
        <?php if ($tab === 'dashboard'): ?>
        document.addEventListener('DOMContentLoaded', () => {
            // Line Chart: Activations Trend
            const trendCtx = document.getElementById('activationsChart').getContext('2d');
            const trendGradient = trendCtx.createLinearGradient(0, 0, 0, 250);
            trendGradient.addColorStop(0, 'rgba(225, 29, 72, 0.45)');
            trendGradient.addColorStop(1, 'rgba(225, 29, 72, 0.0)');

            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_column($sevenDaysActivations, 'day')) ?>,
                    datasets: [{
                        label: 'Successful Activations',
                        data: <?= json_encode(array_column($sevenDaysActivations, 'count')) ?>,
                        borderColor: '#e11d48',
                        borderWidth: 3,
                        backgroundColor: trendGradient,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#e11d48',
                        pointRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: { grid: { color: 'rgba(255, 255, 255, 0.05)' }, ticks: { color: '#64748b' } },
                        y: { grid: { color: 'rgba(255, 255, 255, 0.05)' }, ticks: { color: '#64748b', stepSize: 1 }, beginAtZero: true }
                    }
                }
            });

            // Doughnut Chart: Status
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Active', 'Unused', 'Expired', 'Banned'],
                    datasets: [{
                        data: [<?= $activeKeys ?>, <?= $unusedKeys ?>, <?= $expiredKeys ?>, <?= $bannedKeys ?>],
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#a855f7'],
                        borderWidth: 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { color: '#94a3b8', font: { family: 'Plus Jakarta Sans', weight: 'bold' } } }
                    },
                    cutout: '72%'
                }
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>
