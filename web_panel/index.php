<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_logged'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$alert = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Generate Keys (Single or Batch)
    if ($action === 'generate') {
        $count = max(1, min(100, (int)($_POST['count'] ?? 1)));
        $duration = (int)($_POST['duration'] ?? 30);
        $note = trim($_POST['note'] ?? '');
        $prefix = trim($_POST['prefix'] ?? 'OGIOS');
        if (empty($prefix)) $prefix = 'OGIOS';

        $stmt = $pdo->prepare("INSERT INTO `licenses` (`license_key`, `duration_days`, `status`, `note`) VALUES (?, ?, 'unused', ?)");
        
        for ($i = 0; $i < $count; $i++) {
            $randomPart = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
            $newKey = "{$prefix}-{$randomPart}";
            $stmt->execute([$newKey, $duration, $note]);
        }
        $alert = "Successfully generated {$count} new license key(s)!";
    }

    // 2. Reset Device ID (HWID)
    if ($action === 'reset_hwid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE `licenses` SET `device_id` = NULL, `device_name` = NULL WHERE `id` = ?")->execute([$id]);
            $alert = "Device binding (HWID) has been reset!";
        }
    }

    // 3. Toggle Ban Status
    if ($action === 'toggle_ban') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT status FROM `licenses` WHERE `id` = ?");
            $stmt->execute([$id]);
            $curr = $stmt->fetchColumn();
            $newStatus = ($curr === 'banned') ? 'active' : 'banned';
            $pdo->prepare("UPDATE `licenses` SET `status` = ? WHERE `id` = ?")->execute([$newStatus, $id]);
            $alert = "License status updated to {$newStatus}!";
        }
    }

    // 4. Delete Key
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM `licenses` WHERE `id` = ?")->execute([$id]);
            $alert = "License key deleted permanently.";
        }
    }
}

// Stats
$totalKeys = $pdo->query("SELECT COUNT(*) FROM `licenses`")->fetchColumn();
$activeKeys = $pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` = 'active'")->fetchColumn();
$unusedKeys = $pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` = 'unused'")->fetchColumn();
$bannedKeys = $pdo->query("SELECT COUNT(*) FROM `licenses` WHERE `status` IN ('expired', 'banned')")->fetchColumn();

// Search & List
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT * FROM `licenses` 
        WHERE `license_key` LIKE ? OR `device_id` LIKE ? OR `note` LIKE ? 
        ORDER BY `id` DESC LIMIT 100
    ");
    $term = "%{$search}%";
    $stmt->execute([$term, $term, $term]);
} else {
    $stmt = $pdo->query("SELECT * FROM `licenses` ORDER BY `id` DESC LIMIT 100");
}
$licenses = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OGIOS - License Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0a0b0e;
            --card-bg: #13151b;
            --border: #232733;
            --accent: #e11d48;
            --accent-hover: #be123c;
            --text-main: #f8fafc;
            --text-sub: #94a3b8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg-dark); color: var(--text-main); min-height: 100vh; padding: 24px; }
        .container { max-width: 1300px; margin: 0 auto; }
        
        /* Top Navigation */
        .navbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
        .logo { font-size: 24px; font-weight: 800; letter-spacing: 1.5px; color: #fff; }
        .logo span { color: var(--accent); }
        .nav-right { display: flex; align-items: center; gap: 16px; }
        .user-tag { font-size: 13px; color: var(--text-sub); background: #1a1d26; padding: 6px 14px; border-radius: 20px; border: 1px solid var(--border); }
        .btn-logout { background: transparent; border: 1px solid #475569; color: #cbd5e1; padding: 7px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; transition: 0.2s; }
        .btn-logout:hover { border-color: var(--accent); color: var(--accent); }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 28px; }
        .stat-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 18px; padding: 22px; position: relative; overflow: hidden; }
        .stat-card::after { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--border); }
        .stat-card.total::after { background: #3b82f6; }
        .stat-card.active::after { background: #10b981; }
        .stat-card.unused::after { background: #f59e0b; }
        .stat-card.banned::after { background: #ef4444; }
        .stat-num { font-size: 32px; font-weight: 800; margin-top: 6px; }
        .stat-label { font-size: 13px; font-weight: 600; color: var(--text-sub); }

        /* Action Bar */
        .action-bar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .search-box { display: flex; gap: 10px; flex: 1; max-width: 450px; }
        .search-box input { flex: 1; padding: 12px 16px; background: #13151b; border: 1px solid var(--border); border-radius: 12px; color: #fff; font-size: 14px; outline: none; }
        .search-box input:focus { border-color: var(--accent); }
        .btn { padding: 12px 20px; border-radius: 12px; font-weight: 700; font-size: 14px; border: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); }
        .btn-secondary { background: #1e222d; color: #fff; border: 1px solid var(--border); }
        .btn-secondary:hover { background: #272d3b; }

        /* Table Card */
        .table-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 16px 20px; background: #0f1015; color: #94a3b8; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border); }
        td { padding: 16px 20px; border-bottom: 1px solid #1c202a; font-size: 14px; vertical-align: middle; }
        tr:hover { background: #161821; }

        .key-badge { font-family: monospace; font-size: 14px; font-weight: 700; color: #38bdf8; background: #0c2333; padding: 6px 12px; border-radius: 8px; border: 1px solid #075985; cursor: pointer; transition: 0.2s; }
        .key-badge:hover { filter: brightness(1.2); }
        
        .badge { display: inline-block; padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge.active { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge.unused { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge.expired { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge.banned { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }

        .btn-icon { padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: #1a1d26; color: #cbd5e1; transition: 0.2s; }
        .btn-icon:hover { background: #272c3a; color: #fff; }
        .btn-delete:hover { border-color: #ef4444; color: #ef4444; }

        /* Modal Generator */
        .modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; z-index: 100; padding: 20px; }
        .modal { background: #13151b; border: 1px solid var(--border); border-radius: 24px; padding: 32px; max-width: 500px; width: 100%; box-shadow: 0 25px 50px rgba(0,0,0,0.8); }
        .modal h2 { font-size: 20px; font-weight: 800; margin-bottom: 20px; }
        .modal-group { margin-bottom: 16px; }
        .modal-group label { display: block; font-size: 13px; font-weight: 600; color: #cbd5e1; margin-bottom: 6px; }
        .modal-group select, .modal-group input { width: 100%; padding: 12px 14px; background: #0c0e12; border: 1px solid var(--border); border-radius: 10px; color: #fff; outline: none; font-size: 14px; }
        .modal-group select:focus, .modal-group input:focus { border-color: var(--accent); }
        .modal-buttons { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }

        .alert-banner { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; padding: 14px 20px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Top Nav -->
        <div class="navbar">
            <div class="logo">OGIOS <span>ADMIN</span></div>
            <div class="nav-right">
                <span class="user-tag">👤 <?= htmlspecialchars($_SESSION['admin_user']) ?></span>
                <a href="logout.php" class="btn-logout">Logout</a>
            </div>
        </div>

        <?php if ($alert): ?>
            <div class="alert-banner">✅ <?= htmlspecialchars($alert) ?></div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-label">Total Licenses</div>
                <div class="stat-num"><?= $totalKeys ?></div>
            </div>
            <div class="stat-card active">
                <div class="stat-label">Active (In-Use)</div>
                <div class="stat-num" style="color: #34d399;"><?= $activeKeys ?></div>
            </div>
            <div class="stat-card unused">
                <div class="stat-label">Unused Available</div>
                <div class="stat-num" style="color: #fbbf24;"><?= $unusedKeys ?></div>
            </div>
            <div class="stat-card banned">
                <div class="stat-label">Expired / Banned</div>
                <div class="stat-num" style="color: #f87171;"><?= $bannedKeys ?></div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <form method="GET" class="search-box">
                <input type="text" name="q" placeholder="Search by Key, Device HWID, Note..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-secondary">Search</button>
                <?php if ($search): ?><a href="index.php" class="btn btn-secondary">Clear</a><?php endif; ?>
            </form>
            <button onclick="document.getElementById('genModal').style.display='flex'" class="btn btn-primary">
                ➕ Generate New Keys
            </button>
        </div>

        <!-- Table Card -->
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>License Key</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Device (HWID)</th>
                        <th>Activated / Expires</th>
                        <th>Note</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($licenses)): ?>
                        <tr><td colspan="7" style="text-align: center; color: #64748b; padding: 40px;">No licenses found. Click "Generate New Keys" to create one.</td></tr>
                    <?php else: ?>
                        <?php foreach ($licenses as $l): ?>
                            <tr>
                                <td>
                                    <span class="key-badge" onclick="copyKey('<?= $l['license_key'] ?>')" title="Click to copy">
                                        <?= htmlspecialchars($l['license_key']) ?>
                                    </span>
                                </td>
                                <td><?= $l['duration_days'] > 0 ? $l['duration_days'] . ' Days' : 'Lifetime' ?></td>
                                <td><span class="badge <?= $l['status'] ?>"><?= $l['status'] ?></span></td>
                                <td>
                                    <?php if ($l['device_id']): ?>
                                        <div style="font-size: 12px; color: #e2e8f0; font-family: monospace; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($l['device_id']) ?>">
                                            <?= htmlspecialchars(substr($l['device_id'], 0, 14)) ?>...
                                        </div>
                                        <span style="font-size: 11px; color: #64748b;"><?= htmlspecialchars($l['device_name'] ?: 'iOS') ?></span>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-size: 12px;">Not Bound</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($l['activated_at']): ?>
                                        <div style="font-size: 12px; color: #cbd5e1;"><?= date('M d, Y', strtotime($l['activated_at'])) ?></div>
                                        <div style="font-size: 11px; color: #f43f5e; font-weight: 600;">Exp: <?= $l['expires_at'] ? date('M d, Y', strtotime($l['expires_at'])) : 'Never' ?></div>
                                    <?php else: ?>
                                        <span style="color: #64748b; font-size: 12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px; color: #94a3b8;"><?= htmlspecialchars($l['note'] ?: '-') ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <?php if ($l['device_id']): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Reset device binding for this key?')">
                                                <input type="hidden" name="action" value="reset_hwid">
                                                <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                                <button type="submit" class="btn-icon" title="Reset HWID">Unbind</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_ban">
                                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                            <button type="submit" class="btn-icon"><?= $l['status'] === 'banned' ? 'Unban' : 'Ban' ?></button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this key permanently?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                            <button type="submit" class="btn-icon btn-delete">🗑️</button>
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

    <!-- Modal: Key Generator -->
    <div id="genModal" class="modal-bg">
        <div class="modal">
            <h2>✨ Generate License Keys</h2>
            <form method="POST">
                <input type="hidden" name="action" value="generate">
                <div class="modal-group">
                    <label>Key Prefix</label>
                    <input type="text" name="prefix" value="OGIOS" required>
                </div>
                <div class="modal-group">
                    <label>Duration</label>
                    <select name="duration">
                        <option value="1">1 Day</option>
                        <option value="3">3 Days</option>
                        <option value="7">7 Days</option>
                        <option value="30" selected>30 Days (1 Month)</option>
                        <option value="90">90 Days (3 Months)</option>
                        <option value="365">365 Days (1 Year)</option>
                        <option value="0">Lifetime (No Expiry)</option>
                    </select>
                </div>
                <div class="modal-group">
                    <label>How Many Keys to Generate?</label>
                    <input type="number" name="count" min="1" max="100" value="1" required>
                </div>
                <div class="modal-group">
                    <label>Note / Customer Name</label>
                    <input type="text" name="note" placeholder="e.g. John Doe / Telegram @user">
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="document.getElementById('genModal').style.display='none'" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate Now</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function copyKey(key) {
            navigator.clipboard.writeText(key).then(() => {
                alert('Copied to clipboard: ' + key);
            });
        }
    </script>
</body>
</html>
