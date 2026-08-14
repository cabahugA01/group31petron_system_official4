<?php
ob_start();
session_start();

// Include database connection
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../config/password_reset_whitelist.php';

$message = '';
$message_type = '';
$error = '';
$token_valid = false;
$user_data = null;

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Get token and email from URL
$token = trim($_GET['token'] ?? '');
$email = normalizePasswordResetEmail($_GET['email'] ?? '');

if (empty($token) || empty($email)) {
    $error = "Invalid reset request. Please request a new password reset.";
} else {
    try {
        $cols     = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $uid_col  = 'id';
        $pass_col = in_array('password_hash', $cols) ? 'password_hash' : 'password_hash';
        ensurePasswordResetTokensTable($pdo);

        $token_hash = hash('sha256', $token);
        $stmt = $pdo->prepare("
            SELECT prt.id AS token_id, prt.user_id, prt.token, prt.is_used, prt.used_at,
                   (prt.expires_at > NOW()) AS is_valid_time,
                   u.username, TRIM(u.email) AS email
            FROM   password_reset_tokens prt
            JOIN   users u ON prt.user_id = u.`{$uid_col}`
            WHERE  prt.token      = ?
              AND  LOWER(TRIM(REPLACE(REPLACE(u.email, CHAR(13), ''), CHAR(10), '')))  = LOWER(?)
              AND  prt.token_type = 'password_change'
              AND  LOWER(TRIM(u.status)) = 'active'
            ORDER BY prt.id DESC
            LIMIT  1
        ");
        $stmt->execute([$token_hash, $email]);
        $token_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$token_data) {
            $error = "Invalid or expired reset link. Please request a new password reset.";
        } elseif (!$token_data['is_valid_time']) {
            $error = "Reset link has expired. Please request a new password reset.";
        } elseif ((int)$token_data['is_used'] === 1 || $token_data['used_at'] !== null) {
            $error = "Reset link has already been used. Please request a new password reset.";
        } else {
            $token_valid = true;
            $user_data   = $token_data;
        }
    } catch (PDOException $e) {
        error_log("Token validation error: " . $e->getMessage());
        $error = "System error. Please try again later.";
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    $password = $_POST['password_hash'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $password_errors = [];
    if (strlen($password) < 8) {
        $password_errors[] = "Password must be at least 8 characters long.";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $password_errors[] = "Password must contain at least one uppercase letter.";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $password_errors[] = "Password must contain at least one lowercase letter.";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $password_errors[] = "Password must contain at least one number.";
    }
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $password_errors[] = "Password must contain at least one special character.";
    }
    
    if ($password !== $confirm_password) {
        $password_errors = ["Passwords do not match."];
    }
    
    if (empty($password_errors)) {
        try {
            $pdo->beginTransaction();

            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET `{$pass_col}` = ? WHERE `{$uid_col}` = ?")
                ->execute([$hashed, $user_data['user_id']]);

            // Invalidate the password change token and any remaining reset tokens for this user
            $pdo->prepare("UPDATE password_reset_tokens SET is_used = 1, used_at = NOW() WHERE user_id = ?")
                ->execute([$user_data['user_id']]);

            if (function_exists('log_auth_audit_trail')) {
                log_auth_audit_trail($pdo, $user_data['user_id'], $email, 'PASSWORD_RESET_COMPLETED', 'SUCCESS', 'Password reset completed successfully');
            }

            $pdo->commit();

            $message      = "Password reset successful. You can now log in.";
            $message_type = "success";
            $token_valid  = false;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Password reset error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        }
    } else {
        $error = implode(" ", $password_errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Password | Petron Management System</title>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:      #002F6C;
            --blue-mid:  #003d8a;
            --blue-glow: rgba(0,91,255,.6);
            --red:       #E30613;
            --red-glow:  rgba(227,6,19,.6);
            --text:      #ffffff;
            --muted:     rgba(255,255,255,.9);
            --label:     #ffffff;
            --icon:      rgba(200,225,255,.85);
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            background: transparent;
        }

        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
        }

        .bg-image {
            background: url('../assets/img/background.jpg') center center / cover no-repeat;
            z-index: 1;
        }

        .login-wrap {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 520px;
            padding: 0 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .login-card {
            background: linear-gradient(160deg, #002F6C 0%, #001a3d 60%, #A80016 100%);
            border-radius: 28px;
            padding: 54px 52px 46px;
            position: relative;
            overflow: visible;
            color: #ffffff;
            width: 100%;
            box-shadow:
                0 8px 32px rgba(0,0,0,.40),
                0 24px 56px rgba(0,0,0,.30);
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 10%;
            left: -18px;
            width: 12px;
            height: 80%;
            background: linear-gradient(180deg,
                rgba(0, 100, 255, 0) 0%,
                rgba(0, 100, 255, 0.9) 30%,
                rgba(0, 150, 255, 1) 50%,
                rgba(0, 100, 255, 0.9) 70%,
                rgba(0, 100, 255, 0) 100%
            );
            border-radius: 50%;
            filter: blur(8px);
            animation: sideGlowBlue 3s ease-in-out infinite alternate;
            z-index: 2;
        }

        .login-card::after {
            content: '';
            position: absolute;
            top: 10%;
            right: -18px;
            width: 12px;
            height: 80%;
            background: linear-gradient(180deg,
                rgba(227, 6, 19, 0) 0%,
                rgba(227, 6, 19, 0.9) 30%,
                rgba(255, 40, 40, 1) 50%,
                rgba(227, 6, 19, 0.9) 70%,
                rgba(227, 6, 19, 0) 100%
            );
            border-radius: 50%;
            filter: blur(8px);
            animation: sideGlowRed 3s ease-in-out infinite alternate;
            z-index: 2;
        }

        @keyframes sideGlowBlue {
            0%   { opacity: 0.4; height: 60%; top: 20%; filter: blur(8px); }
            50%  { opacity: 1;   height: 85%; top: 8%;  filter: blur(6px); }
            100% { opacity: 0.6; height: 70%; top: 15%; filter: blur(10px); }
        }
        @keyframes sideGlowRed {
            0%   { opacity: 0.6; height: 70%; top: 15%; filter: blur(10px); }
            50%  { opacity: 1;   height: 85%; top: 8%;  filter: blur(6px); }
            100% { opacity: 0.4; height: 60%; top: 20%; filter: blur(8px); }
        }

        .brand { text-align: center; margin-bottom: 30px; }
        .brand-logo {
            width: 88px; height: auto;
            filter: drop-shadow(0 4px 16px rgba(227,6,19,.4));
            animation: logoFloat 3s ease-in-out infinite;
        }
        @keyframes logoFloat {
            0%,100% { transform: translateY(0); }
            50%      { transform: translateY(-5px); }
        }
        .brand-tagline {
            display: block;
            margin-top: 10px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2.8px;
            text-transform: uppercase;
            color: rgba(200,220,255,.95);
            text-shadow: 0 1px 4px rgba(0,0,0,.5);
        }

        .alert-error, .alert-success {
            display: flex;
            align-items: center;
            gap: 10px;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 22px;
            font-size: 13px;
        }
        .alert-error {
            background: rgba(227,6,19,.12);
            border: 1px solid rgba(227,6,19,.35);
            color: #ff8080;
            animation: shake .35s ease;
        }
        .alert-success {
            background: rgba(16,185,129,.12);
            border: 1px solid rgba(16,185,129,.35);
            color: #6ee7b7;
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            25%      { transform: translateX(-6px); }
            75%      { transform: translateX(6px); }
        }

        .form-group { margin-bottom: 18px; }

        .field-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--label);
            margin-bottom: 8px;
            text-shadow: 0 1px 4px rgba(0,0,0,.5);
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-icon {
            position: absolute;
            left: 16px;
            color: rgba(255,255,255,.92);
            font-size: 16px;
            pointer-events: none;
            transition: color .2s, text-shadow .2s;
            z-index: 2;
            text-shadow: 0 0 10px rgba(255,255,255,.5), 0 1px 3px rgba(0,0,0,.6);
        }
        .input-wrap:focus-within .input-icon {
            color: #ffffff;
            text-shadow: 0 0 16px rgba(96,165,250,.9), 0 1px 3px rgba(0,0,0,.6);
        }

        .field-input {
            width: 100%;
            padding: 14px 46px;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 500;
            color: #ffffff;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 13px;
            outline: none;
            transition: border-color .25s, box-shadow .25s, background .25s;
            caret-color: #93c5fd;
            text-shadow: 0 1px 3px rgba(0,0,0,.4);
        }
        .field-input::placeholder { color: rgba(200,220,255,.55); font-weight: 400; }
        .field-input:focus {
            background: rgba(255,255,255,.18);
            border-color: rgba(147,197,253,.8);
            box-shadow: 0 0 0 3px rgba(96,165,250,.20);
        }

        .pw-toggle {
            position: absolute;
            right: 0;
            width: 46px;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            color: rgba(180,210,255,.65);
            cursor: pointer;
            font-size: 14px;
            transition: color .2s, text-shadow .2s;
            border-radius: 0 13px 13px 0;
            z-index: 2;
        }
        .pw-toggle:hover { color: #93c5fd; text-shadow: 0 0 10px rgba(96,165,250,.6); }

        /* Hide browser native password reveal eye (Edge/IE/Chrome) */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear { display: none !important; }
        input[type="password"]::-webkit-contacts-auto-fill-button,
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="password"]::-webkit-strong-password-auto-fill-button {
            display: none !important; visibility: hidden; pointer-events: none;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .4px;
            color: #fff;
            background: linear-gradient(135deg, #002F6C 0%, #0050b3 100%);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: transform .15s, box-shadow .2s;
            box-shadow: 0 4px 24px rgba(0,47,108,.5), 0 0 0 1px rgba(255,255,255,.07) inset;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
        }
        .btn-login::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,.12) 0%, transparent 60%);
            opacity: 0;
            transition: opacity .2s;
        }
        .btn-login:hover:not(:disabled)::before { opacity: 1; }
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(0,47,108,.65), 0 0 0 1px rgba(255,255,255,.1) inset;
        }
        .btn-login:active:not(:disabled) { transform: translateY(0); }
        .btn-login:disabled {
            background: rgba(255,255,255,.07);
            color: rgba(255,255,255,.28);
            cursor: not-allowed;
            box-shadow: none;
        }

        .login-footer {
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: center;
            margin-top: 24px;
        }
        .login-footer a {
            font-size: 13px;
            font-weight: 600;
            color: rgba(180,210,255,.75);
            text-decoration: none;
            transition: color .2s;
            text-shadow: 0 1px 4px rgba(0,0,0,.4);
        }
        .login-footer a:hover { color: #93c5fd; }

        .page-footer {
            margin-top: 28px;
            font-size: 12px;
            font-weight: 500;
            color: #ffffff;
            letter-spacing: .5px;
            text-align: center;
            text-shadow: 0 1px 6px rgba(0,0,0,.9), 0 2px 12px rgba(0,0,0,.8);
            cursor: default;
            user-select: none;
            pointer-events: none;
        }
        @media (max-width: 540px) {
            .login-wrap { padding: 0 12px; }
            .login-card { padding: 38px 28px 32px; }
        }
    </style>
</head>
<body>
    <div class="bg-layer bg-image"></div>

    <div class="login-wrap">
        <div class="login-card">
            <div class="brand">
                <img src="<?php echo '../' . get_system_logo_url(isset($station_id) ? (int)$station_id : 0); ?>" alt="Petron Logo" class="brand-logo">
                <span class="brand-tagline">Station Management System</span>
            </div>

            <h2 style="font-size: 18px; font-weight: 700; text-align: center; margin-bottom: 20px; color: #ffffff;">CREATE NEW PASSWORD</h2>

            <?php if ($error): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php if (!$token_valid && empty($message)): ?>
                <div style="margin-top: 18px;">
                    <a href="login.php" class="btn-login" style="text-decoration:none;">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>BACK TO LOGIN</span>
                    </a>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert-success" style="padding: 16px; font-size: 14px; margin-bottom: 20px;">
                    <i class="fas fa-check-circle" style="font-size: 18px;"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
                <div style="margin-top: 10px;">
                    <a href="login.php?reset_success=1" class="btn-login" style="text-decoration:none;">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>PROCEED TO LOGIN</span>
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($token_valid && empty($message)): ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="password_hash" class="field-label">New Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password_hash" id="password_hash" class="field-input"
                               placeholder="Enter new password" required autofocus minlength="8" autocomplete="new-password">
                        <button type="button" class="pw-toggle" id="pwToggle1" aria-label="Show or hide password">
                            <i class="fas fa-eye" id="pwIcon1"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="field-label">Confirm Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-check-double input-icon"></i>
                        <input type="password" name="confirm_password" id="confirm_password" class="field-input"
                               placeholder="Confirm new password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="pw-toggle" id="pwToggle2" aria-label="Show or hide confirm password">
                            <i class="fas fa-eye" id="pwIcon2"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-save"></i>
                    <span>RESET PASSWORD</span>
                </button>
            </form>
            <?php endif; ?>

            <?php if ($token_valid && empty($message)): ?>
            <div class="login-footer">
                <a href="login.php">
                    <i class="fas fa-sign-in-alt"></i> BACK TO LOGIN
                </a>
            </div>
            <?php endif; ?>
        </div>
        <div class="page-footer">
            &copy; <?php echo date('Y'); ?> Petron Station Management System. All Rights Reserved.
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        function setupPasswordToggle(toggleBtnId, inputId, iconId) {
            var btn = document.getElementById(toggleBtnId);
            var input = document.getElementById(inputId);
            var icon = document.getElementById(iconId);
            if (btn && input && icon) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var isText = input.type === 'text';
                    input.type = isText ? 'password' : 'text';
                    icon.className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
                });
            }
        }
        setupPasswordToggle('pwToggle1', 'password_hash', 'pwIcon1');
        setupPasswordToggle('pwToggle2', 'confirm_password', 'pwIcon2');
    });
    </script>
</body>
</html>
