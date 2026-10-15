<?php
session_start();
require_once __DIR__ . '/config.php';

// If already logged in, redirect
if (isset($_SESSION['admin_logged']) || isset($_SESSION['reseller_logged'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$username_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $username_val = $username;

    if ($username !== '' && $password !== '') {
        $pdo = getDB();

        // 1. Check Admin Account first
        $stmt = $pdo->prepare("SELECT * FROM `admins` WHERE `username` = ? LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_logged'] = true;
            $_SESSION['admin_user'] = $admin['username'];
            $_SESSION['user_role'] = 'admin';
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['display_name'] = $admin['username'];
            header('Location: index.php');
            exit;
        }

        // 2. Check Reseller Account
        $stmt = $pdo->prepare("SELECT * FROM `resellers` WHERE `username` = ? LIMIT 1");
        $stmt->execute([$username]);
        $reseller = $stmt->fetch();

        if ($reseller && password_verify($password, $reseller['password'])) {
            if ($reseller['status'] === 'inactive') {
                $error = 'บัญชีตัวแทนนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
            } elseif (!empty($reseller['expires_at']) && strtotime($reseller['expires_at']) < time()) {
                $error = 'บัญชีตัวแทนหมดอายุเมื่อ ' . date('d/m/Y', strtotime($reseller['expires_at'])) . ' กรุณาติดต่อแอดมินเพื่อต่ออายุ';
            } else {
                $_SESSION['reseller_logged'] = true;
                $_SESSION['reseller_id'] = (int)$reseller['id'];
                $_SESSION['reseller_user'] = $reseller['username'];
                $_SESSION['user_role'] = 'reseller';
                $_SESSION['display_name'] = !empty($reseller['name']) ? $reseller['name'] : $reseller['username'];
                $_SESSION['admin_user'] = $reseller['username'];
                header('Location: index.php');
                exit;
            }
        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    } else {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่านให้ครบถ้วน';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ — XSTH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #000000;
            --surface: #0a0a0a;
            --border: #1f1f1f;
            --border-focus: #3b82f6;
            --text: #f3f4f6;
            --text-secondary: #9ca3af;
            --text-tertiary: #6b7280;
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
            --error-bg: rgba(239, 68, 68, 0.1);
            --error-border: rgba(239, 68, 68, 0.3);
            --error-text: #f87171;
            --radius: 10px;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Noto Sans Thai', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            -webkit-font-smoothing: antialiased;
        }

        .auth-container {
            width: 100%;
            max-width: 380px;
        }

        /* Clean Header */
        .auth-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .auth-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            background: #141414;
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 16px;
            color: #fff;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .auth-title {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: var(--text);
            margin-bottom: 6px;
        }

        .auth-subtitle {
            font-size: 13.5px;
            color: var(--text-secondary);
        }

        /* Clean Card */
        .auth-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px 24px;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
        }



        /* Error alert */
        .alert-error {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error-text);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 18px;
        }

        /* Form elements */
        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12.5px;
            font-weight: 600;
            color: #d1d5db;
            margin-bottom: 6px;
        }

        .form-input-wrap {
            position: relative;
        }

        .form-input {
            width: 100%;
            height: 42px;
            background: #090a0f;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            padding: 0 14px;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-input::placeholder {
            color: #4b5563;
        }

        .form-input:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        /* Fix autofill */
        input:-webkit-autofill,
        input:-webkit-autofill:hover, 
        input:-webkit-autofill:focus {
            -webkit-text-fill-color: #fff !important;
            -webkit-box-shadow: 0 0 0 1000px #090a0f inset !important;
            box-shadow: 0 0 0 1000px #090a0f inset !important;
            transition: background-color 5000s ease-in-out 0s;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-tertiary);
            font-size: 12px;
            font-weight: 600;
            padding: 6px;
            cursor: pointer;
            border-radius: 4px;
        }

        .password-toggle:hover {
            color: var(--text-secondary);
        }

        .form-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12.5px;
            margin: 14px 0 20px;
            color: var(--text-secondary);
        }

        .form-meta label {
            display: flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
        }

        .form-meta input[type="checkbox"] {
            width: 14px;
            height: 14px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .form-meta a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.15s;
        }

        .form-meta a:hover {
            color: #fff;
            text-decoration: underline;
        }

        /* Clean Primary Button */
        .btn-submit {
            width: 100%;
            height: 42px;
            background: #fff;
            color: #000;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.15s ease, transform 0.1s ease;
        }

        .btn-submit:hover {
            opacity: 0.92;
        }

        .btn-submit:active {
            transform: scale(0.99);
        }

        .auth-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: var(--text-tertiary);
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <div class="auth-logo">XS</div>
            <h1 class="auth-title">เข้าสู่ระบบ</h1>
            <p class="auth-subtitle">ระบบจัดการคีย์ใบอนุญาต XSTH</p>
        </div>

        <div class="auth-card">


            <?php if (!empty($error)): ?>
                <div class="alert-error">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="authForm">
                <div class="form-group">
                    <label class="form-label" for="usernameField">ชื่อผู้ใช้</label>
                    <div class="form-input-wrap">
                        <input 
                            type="text" 
                            name="username" 
                            id="usernameField" 
                            class="form-input" 
                            placeholder="กรอกชื่อผู้ใช้..." 
                            value="<?= htmlspecialchars($username_val, ENT_QUOTES, 'UTF-8') ?>"
                            required 
                            autofocus
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="passwordField">
                        <span>รหัสผ่าน</span>
                    </label>
                    <div class="form-input-wrap">
                        <input 
                            type="password" 
                            name="password" 
                            id="passwordField" 
                            class="form-input" 
                            placeholder="••••••••" 
                            required
                        >
                        <button type="button" class="password-toggle" onclick="togglePass()" id="toggleBtn">แสดง</button>
                    </div>
                </div>

                <div class="form-meta">
                    <label>
                        <input type="checkbox" name="remember" checked>
                        <span>จดจำการใช้งาน</span>
                    </label>
                    <a href="javascript:void(0)" onclick="alert('กรุณาติดต่อผู้ดูแลระบบเพื่อขอรีเซ็ตรหัสผ่าน')">ลืมรหัสผ่าน?</a>
                </div>

                <button type="submit" class="btn-submit" id="btnSubmit">เข้าสู่ระบบ</button>
            </form>
        </div>

        <div class="auth-footer">
            XSTH Studio &copy; <?= date('Y') ?>
        </div>
    </div>

    <script>
        function togglePass() {
            const input = document.getElementById('passwordField');
            const btn = document.getElementById('toggleBtn');
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = 'ซ่อน';
            } else {
                input.type = 'password';
                btn.textContent = 'แสดง';
            }
        }



        document.getElementById('authForm').addEventListener('submit', function() {
            const btn = document.getElementById('btnSubmit');
            btn.textContent = 'กำลังเข้าสู่ระบบ...';
            btn.style.opacity = '0.7';
            btn.style.pointerEvents = 'none';
        });
    </script>
</body>
</html>
