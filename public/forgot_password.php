<?php
ob_start();
session_start();

// Include database connection and configs
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/../config/password_reset_whitelist.php';
require_once __DIR__ . '/../includes/mailer.php';

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
            ensurePasswordResetTokensTable($pdo);

            // Clean any stray whitespace/CR/LF from email column in DB
            try { cleanPasswordResetEmails($pdo); } catch(Exception $ce) {}

            $input = normalizePasswordResetIdentifier($recovery_id);
            $user = findActivePasswordResetUser($pdo, $input);

            // Log attempt
            try {
                $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Password Reset Request', ?, ?)")
                    ->execute([($user['user_id'] ?? null), "Password reset requested for: {$recovery_id}", $_SERVER['REMOTE_ADDR']]);
            } catch (Exception $e) {}

            if ($user) {
                if (empty($user['email'])) {
                    $error = "This account has no registered email address. Please contact the administrator to update your profile.";
                } elseif (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                    $error = "This account has an invalid registered email address. Please contact the administrator to update your profile.";
                } else {
                    if (!isEmailWhitelistedForPasswordReset($user['email'])) {
                        $error = "Password reset is currently restricted. Please contact your system administrator for assistance.";
                        error_log("Password reset blocked for non-whitelisted email: {$user['email']} (user_id={$user['user_id']})");
                    } else {
                        // Log: PASSWORD_RESET_REQUESTED
                        if (function_exists('log_auth_audit_trail')) {
                            log_auth_audit_trail($pdo, $user['user_id'], $user['email'], 'PASSWORD_RESET_REQUESTED', 'SUCCESS', "Password reset OTP requested for: {$user['email']}");
                        }

                        // Generate cryptographically secure 6-digit OTP
                        $otp_code  = sprintf('%06d', random_int(100000, 999999));
                        // Store HASH only — never store plaintext OTP in database
                        $otp_hash  = hash('sha256', $otp_code);

                        // Invalidate all previous OTP tokens for this user
                        $pdo->prepare("UPDATE password_reset_tokens SET is_used = 1 WHERE user_id = ? AND token_type = 'reset'")->execute([$user['user_id']]);

                        // Insert new hashed OTP with 5-minute expiration
                        $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, token_type, expires_at, attempts, ip_address) VALUES (?, ?, 'reset', DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, ?)")
                            ->execute([$user['user_id'], $otp_hash, $_SERVER['REMOTE_ADDR']]);

                        // Attempt to send OTP via SMTP — no local fallback
                        @set_time_limit(60);
                        $email_sent = sendOtpEmail($user['email'], $otp_code);
                        // $otp_code is no longer needed after this point — do NOT pass it to the view
                        unset($otp_code);

                        // Audit log
                        if ($email_sent) {
                            if (function_exists('log_auth_audit_trail')) {
                                log_auth_audit_trail($pdo, $user['user_id'], $user['email'], 'PASSWORD_RESET_OTP_SENT', 'SUCCESS', "OTP email delivered to: {$user['email']}");
                            }
                        } else {
                            error_log("[OTP] SMTP delivery failed for user_id={$user['user_id']} email={$user['email']}");
                            if (function_exists('log_auth_audit_trail')) {
                                log_auth_audit_trail($pdo, $user['user_id'], $user['email'], 'PASSWORD_RESET_OTP_SENT', 'FAILED', "SMTP delivery failed for: {$user['email']}");
                            }
                        }

                        // Only redirect with sent=1 if SMTP actually succeeded
                        if ($email_sent) {
                            $redirect = 'verify_otp.php?email=' . urlencode($user['email']) . '&sent=1';
                        } else {
                            $redirect = 'verify_otp.php?email=' . urlencode($user['email']) . '&email_failed=1';
                        }
                        header('Location: ' . $redirect);
                        exit;
                    }
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
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <script src="../assets/js/security_frontend.js?v=2.0.4"></script>
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
                <img src="<?php echo '../' . get_system_logo_url(isset($station_id) ? (int)$station_id : (isset($user['station_id']) ? (int)$user['station_id'] : 0)); ?>" alt="Petron Logo" class="brand-logo">
                <span class="brand-tagline">Station Management System</span>
            </div>

            <?php if ($error): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="POST" action="" id="forgotForm">
                <div class="form-group">
                    <label for="recovery_id" class="field-label">Email or Username</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="text" name="recovery_id" id="recovery_id" class="field-input"
                               placeholder="Enter your email or username" required autofocus autocomplete="username">
                    </div>
                </div>

                <button type="submit" class="btn-login" id="submitBtn">
                    <i class="fas fa-paper-plane"></i>
                    <span>Send OTP</span>
                </button>
            </form>
            <?php endif; ?>

            <div class="login-footer">
                <a href="login.php">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
        <div class="page-footer">
            &copy; <?php echo date('Y'); ?> Petron Station Management System. All Rights Reserved.
        </div>
    </div>

<script>
const forgotForm = document.getElementById('forgotForm');
const submitBtn = document.getElementById('submitBtn');
if (forgotForm && submitBtn) {
    forgotForm.addEventListener('submit', () => {
        submitBtn.disabled = true;
        const span = submitBtn.querySelector('span');
        if (span) span.textContent = 'Sending OTP...';
        const icon = submitBtn.querySelector('i');
        if (icon) icon.className = 'fas fa-spinner fa-spin';
    });
}
</script>
</body>
</html>
