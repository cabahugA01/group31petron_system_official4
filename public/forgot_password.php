<?php
ob_start();
session_start();

// Include database connection and configs
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../config/email_config.php';

$error = '';
$success = '';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recovery_id = trim($_POST['recovery_id'] ?? '');
    
    if (empty($recovery_id)) {
        $error = "Please enter your Email or Username.";
    } else {
        try {
            // Auto-detect column names
            $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $uid_col = 'id';
            
            // Detect status format (Active vs active)
            $all_status = $pdo->query("SELECT DISTINCT status FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $status_active = in_array('Active', $all_status) ? 'Active' : 'active';

            // Auto-create password_reset_tokens if missing
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
                    `id`         INT(11)     NOT NULL AUTO_INCREMENT,
                    `user_id`    INT(11)     NOT NULL,
                    `token`      VARCHAR(10) NOT NULL,
                    `token_type` VARCHAR(20) NOT NULL DEFAULT 'reset',
                    `expires_at` DATETIME    NOT NULL,
                    `used_at`    DATETIME    DEFAULT NULL,
                    `ip_address` VARCHAR(45) DEFAULT NULL,
                    `is_used`    TINYINT(1)  NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Clean any stray CR/LF from email column
            try { $pdo->exec("UPDATE users SET email = TRIM(REPLACE(REPLACE(email, CHAR(13), ''), CHAR(10), ''))"); } catch(Exception $ce) {}

            // Detect input type: EMAIL or USERNAME only
            $detected_type = (strpos($recovery_id, '@') !== false) ? 'email' : 'username';

            // Query user — use TRIM(email) to handle any residual dirty data
            if ($detected_type === 'email') {
                $sql = "SELECT `{$uid_col}` AS user_id, username, TRIM(email) AS email FROM users WHERE TRIM(email) = ? AND status = ? LIMIT 1";
            } else {
                $sql = "SELECT `{$uid_col}` AS user_id, username, TRIM(email) AS email FROM users WHERE username = ? AND status = ? LIMIT 1";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([trim($recovery_id), $status_active]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Strip any remaining CR/LF from fetched email
            if ($user && !empty($user['email'])) {
                $user['email'] = trim(preg_replace('/[\r\n]+/', '', $user['email']));
            }

            // Log attempt
            try {
                $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Password Reset Request', ?, ?)")
                    ->execute([($user['user_id'] ?? null), "Password reset requested for: {$recovery_id}", $_SERVER['REMOTE_ADDR']]);
            } catch (Exception $e) {}

            if ($user) {
                if (empty($user['email'])) {
                    $error = "This account has no email address. Please contact administrator.";
                } else {
                    // Generate OTP
                    $otp_code = sprintf("%06d", random_int(100000, 999999));

                    // Store OTP using DATE_ADD(NOW()) so expiry matches MySQL server time
                    // (avoids PHP timezone vs MySQL timezone mismatch)
                    $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$user['user_id']]);
                    $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, token_type, expires_at, ip_address) VALUES (?, ?, 'reset', DATE_ADD(NOW(), INTERVAL 5 MINUTE), ?)")
                        ->execute([$user['user_id'], $otp_code, $_SERVER['REMOTE_ADDR']]);

                    // Attempt to send email (non-blocking — redirect happens regardless)
                    if (function_exists('sendPasswordResetOTP')) {
                        sendPasswordResetOTP($user['email'], $otp_code);
                    }

                    // Always redirect to verify_otp.php (dev mode shows OTP hint there)
                    header("Location: verify_otp.php?email=" . urlencode($user['email']));
                    exit;
                }
            } else {
                // For security, show generic message even if user not found
                $success = "If that account exists, you will receive a password reset email shortly.";
            }
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        }
    }
}

// Configuration variables
$system_name = "Petron Station & Service Center Management System";
$current_year = date("Y");
$footer_text = "&copy; {$current_year} {$system_name}. All Rights Reserved.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Petron Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <style>
        :root {
            --blue-glow: rgba(0, 100, 255, 0.45);
            --red-glow: rgba(227, 6, 19, 0.35);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            background: transparent;
        }

        /* ── Base Image Background only ── */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
            display: none !important;
        }

        .bg-image {
            display: block !important;
            background: url('../assets/img/background.jpg') center center / cover no-repeat;
            z-index: 1;
        }

        .login-wrap {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 520px;
            padding: 0 20px;
        }

        .login-card {
            background: linear-gradient(160deg, #002F6C 0%, #001a3d 60%, #A80016 100%);
            border-radius: 28px;
            padding: 54px 52px 46px;
            position: relative;
            overflow: visible;
            color: #ffffff;
            box-shadow:
                0 8px 32px rgba(0,0,0,.40),
                0 24px 56px rgba(0,0,0,.30);
            width: 100%;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 10%; left: -18px;
            width: 12px; height: 80%;
            background: linear-gradient(180deg, rgba(0,100,255,0) 0%, rgba(0,100,255,0.9) 30%, rgba(0,150,255,1) 50%, rgba(0,100,255,0.9) 70%, rgba(0,100,255,0) 100%);
            border-radius: 50%;
            filter: blur(8px);
            animation: sideGlowBlue 3s ease-in-out infinite alternate;
            z-index: 2;
        }

        .login-card::after {
            content: '';
            position: absolute;
            top: 10%; right: -18px;
            width: 12px; height: 80%;
            background: linear-gradient(180deg, rgba(227,6,19,0) 0%, rgba(227,6,19,0.9) 30%, rgba(255,40,40,1) 50%, rgba(227,6,19,0.9) 70%, rgba(227,6,19,0) 100%);
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


        .brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 32px;
            text-align: center;
        }

        .brand-logo {
            width: 88px;
            height: auto;
            object-fit: contain;
            margin-bottom: 16px;
            filter: drop-shadow(0 4px 16px rgba(227,6,19,.4));
            animation: logoFloat 3s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-5px); }
        }

        .brand-tagline {
            display: block;
            margin-top: 10px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2.8px;
            text-transform: uppercase;
            color: rgba(180,210,255,.9);
            text-shadow: 0 0 12px rgba(100,160,255,.4);
        }

        .field-group {
            margin-bottom: 24px;
            position: relative;
        }

        .field-label {
            display: block;
            margin-bottom: 8px;
            font-size: 12.5px;
            font-weight: 700;
            color: rgba(255,255,255,.9);
            letter-spacing: .8px;
            text-transform: uppercase;
            text-shadow: 0 1px 3px rgba(0,0,0,.5);
        }

        /* Hide type detection badge - auto-detection runs silently in background */
        .type-badge {
            display: none !important;
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
            border-radius: 14px;
            background: rgba(0,0,0,.45);
            border: 1.5px solid rgba(255,255,255,.15);
            box-shadow: 0 2px 6px rgba(0,0,0,.35) inset;
            transition: border-color .25s, box-shadow .25s;
        }

        .input-wrap:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 14px rgba(59,130,246,.5), 0 2px 6px rgba(0,0,0,.3) inset;
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
            height: 48px;
            background: transparent;
            border: none;
            outline: none;
            padding: 0 16px 0 46px;
            color: #ffffff;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0,0,0,.4);
        }

        .field-input::placeholder {
            color: rgba(200,220,255,.45);
            font-weight: 400;
        }



        .btn-submit {
            width: 100%;
            height: 50px;
            background: linear-gradient(135deg, #002F6C, #0050b3);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 14px;
            color: #ffffff;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: transform .15s, box-shadow .2s;
            box-shadow: 0 4px 15px rgba(0,47,108,.4), 0 1px 0 rgba(255,255,255,.15) inset;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-submit:hover {
            box-shadow: 0 6px 20px rgba(0,47,108,.6), 0 1px 0 rgba(255,255,255,.25) inset;
            transform: translateY(-1px);
        }

        .btn-submit:active {
            transform: translateY(1px);
            box-shadow: 0 2px 10px rgba(0,47,108,.4);
        }

        .btn-submit:disabled {
            background: #4a5568;
            border-color: rgba(255,255,255,.05);
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .error-banner, .success-banner {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,.25);
            text-align: left;
        }

        .error-banner {
            background: rgba(220,38,38,.25);
            border: 1.5px solid rgba(220,38,38,.45);
            color: #fca5a5;
        }

        .success-banner {
            background: rgba(16,185,129,.2);
            border: 1.5px solid rgba(16,185,129,.45);
            color: #a7f3d0;
        }

        .links-wrap {
            margin-top: 24px;
            display: flex;
            justify-content: center;
        }

        .forgot-link {
            color: rgba(255,255,255,.8);
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            transition: color .2s, text-shadow .2s;
            text-shadow: 0 1px 2px rgba(0,0,0,.5);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .forgot-link:hover {
            color: #93c5fd;
            text-shadow: 0 0 8px rgba(147,197,253,.5);
        }

        .page-footer {
            margin-top: 28px;
            font-size: 12px;
            font-weight: 500;
            color: #ffffff;
            letter-spacing: .5px;
            text-align: center;
            text-shadow: 0 1px 6px rgba(0,0,0,.9), 0 2px 12px rgba(0,0,0,.8);
        }

        @media (max-width: 540px) {
            .login-wrap { padding: 0 12px; }
            .login-card { padding: 38px 28px 32px; }
        }
    </style>
</head>
<body>

<!-- 4D Background Layers -->
<div class="bg-layer bg-image"></div>
<div class="bg-layer bg-gradient"></div>
<div class="bg-layer bg-orbs">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
</div>
<div class="bg-layer bg-particles">
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
</div>
<div class="bg-layer bg-grid"></div>

<div class="login-wrap">
    <div class="login-card">
        <!-- Branding -->
        <div class="brand">
            <img src="<?php echo '../' . get_system_logo_url(isset($station_id) ? (int)$station_id : (isset($user['station_id']) ? (int)$user['station_id'] : 0)); ?>" alt="Petron" class="brand-logo">
            <span class="brand-tagline">Station Management System</span>
        </div>

        <!-- Error Message -->
        <?php if ($error): ?>
            <div class="error-banner">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Success Message -->
        <?php if ($success): ?>
            <div class="success-banner">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <!-- Forgot Password Form -->
        <form method="POST" action=""  id="forgotForm">
            <div class="field-group">
                <label for="recovery_id" class="field-label">Account ID</label>
                <div class="input-wrap">
                    <i class="fas fa-id-badge input-icon"></i>
                    <input type="text" name="recovery_id" id="recovery_id" class="field-input" placeholder="Enter Account" required autofocus aria-label="Account ID">
                    <span class="type-badge" id="typeBadge"></span>
                </div>
            </div>
            
            <button type="submit" class="btn-submit" id="submitBtn">
                <div class="spinner" id="spinner"></div>
                <span id="btnText">Send Reset Link</span>
            </button>
        </form>
        <?php endif; ?>

        <!-- Secondary Links -->
        <div class="links-wrap">
            <a href="login.php" class="forgot-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>

    <div class="page-footer">
        <?php echo $footer_text; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('forgotForm');
        const submitBtn = document.getElementById('submitBtn');
        const spinner = document.getElementById('spinner');
        const btnText = document.getElementById('btnText');
        const recoveryInput = document.getElementById('recovery_id');

        // Auto-detection runs silently in background (no UI badge display)
        function detectType(val) {
            val = (val || '').trim();
            if (!val) return null;
            if (val.indexOf('@') !== -1) return 'email';
            if (/^\d{11}$/.test(val)) return 'phone';
            return 'username';
        }

        if (form) {
            form.addEventListener('submit', () => {
                submitBtn.disabled = true;
                if (spinner) {
                    spinner.style.display = 'block';
                }
                if (btnText) {
                    btnText.textContent = 'Sending...';
                }
            });
        }
    });
</script>

</body>
</html>
