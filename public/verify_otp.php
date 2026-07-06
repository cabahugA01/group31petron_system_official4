<?php
session_start();
ob_start();

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../config/email_config.php';

$message = '';
$error = '';
$success = '';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// EMAIL ONLY - No phone support
$email = trim($_GET['email'] ?? $_POST['email'] ?? $_SESSION['reset_email'] ?? '');
$email_failed = isset($_GET['email_failed']) && $_GET['email_failed'] === '1';

// Store email in session for resend functionality
if (!empty($email)) {
    $_SESSION['reset_email'] = $email;
}

// Handle RESEND OTP request
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['resend']) && $_GET['resend'] === '1' && !empty($email)) {
    try {
        $uid_col = 'id';

        // Find user by email
        $stmt = $pdo->prepare("SELECT `{$uid_col}` AS user_id, username, TRIM(email) AS email, role FROM users WHERE LOWER(TRIM(email)) = LOWER(?) AND LOWER(TRIM(status)) = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Generate new OTP
            $otp_code = sprintf("%06d", random_int(100000, 999999));

            // Delete old OTPs and insert new one
            $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND token_type = 'reset'")->execute([$user['user_id']]);
            $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, token_type, expires_at, ip_address) VALUES (?, ?, 'reset', DATE_ADD(NOW(), INTERVAL 5 MINUTE), ?)")
                ->execute([$user['user_id'], $otp_code, $_SERVER['REMOTE_ADDR']]);

            // Send new OTP via email with extended timeout
            $resend_sent = false;
            if (function_exists('sendPasswordResetOTP')) {
                @set_time_limit(60);
                $resend_sent = (bool) sendPasswordResetOTP($user['email'], $otp_code);
                if (!$resend_sent) {
                    error_log("OTP resend email FAILED for user_id={$user['user_id']} email={$user['email']}");
                }
            }

            // Log resend attempt
            try {
                $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'OTP Resend', ?, ?)")
                    ->execute([$user['user_id'], "OTP resent to: {$email}", $_SERVER['REMOTE_ADDR']]);
            } catch (Exception $e) {}

            if ($resend_sent) {
                $email_failed = false;
                $success = "A new OTP has been sent to your email. Please check your inbox.";
            } else {
                $email_failed = true;
                $error = "Could not send email. Use the OTP shown below.";
            }
        } else {
            $error = "Unable to resend OTP. Please start the password reset process again.";
        }
    } catch (Exception $e) {
        error_log("OTP resend error: " . $e->getMessage());
        $error = "System error. Please try again later.";
    }
}

// Handle OTP submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
    $otp = trim($_POST['otp'] ?? '');

    if (empty($otp)) {
        $error = "Please enter the 6-digit OTP.";
    } elseif (strlen($otp) !== 6 || !is_numeric($otp)) {
        $error = "Please enter a valid 6-digit OTP.";
    } else {
        try {
            // Auto-detect column names
            $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $uid_col = 'id';
            $status_active = in_array('Active', $pdo->query("SELECT DISTINCT status FROM users")->fetchAll(PDO::FETCH_COLUMN)) ? 'Active' : 'active';

            // Verify OTP via EMAIL only
            if (!empty($email)) {
                $stmt = $pdo->prepare("
                    SELECT prt.user_id, prt.token, prt.is_used,
                           (prt.expires_at > NOW()) AS is_valid_time,
                           u.username, TRIM(u.email) AS email
                    FROM   password_reset_tokens prt
                    JOIN   users u ON prt.user_id = u.`{$uid_col}`
                    WHERE  prt.token      = ?
                      AND  prt.token_type = 'reset'
                      AND  LOWER(TRIM(u.status)) = 'active'
                      AND  LOWER(TRIM(u.email)) = LOWER(?)
                    ORDER BY prt.id DESC
                    LIMIT  1
                ");
                $stmt->execute([$otp, $email]);
                $token_data = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($token_data) {
                    if (!$token_data['is_valid_time']) {
                        $error = "OTP has expired. Please click 'Resend OTP' below.";
                    } elseif ($token_data['is_used'] == 1) {
                        $error = "OTP has already been used. Please request a new one.";
                    } else {
                        // Valid OTP! Redirect to reset password page
                        // Clear session email after successful verification
                        unset($_SESSION['reset_email']);
                        header("Location: forgot_password_reset.php?token=" . urlencode($otp) . "&email=" . urlencode($email));
                        exit;
                    }
                } else {
                    $error = "Invalid OTP. Please check the code and try again.";
                }
            } else {
                $error = "Email is required for verification.";
            }
        } catch (Exception $e) {
            error_log("OTP validation error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP | Petron Management System</title>
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

        .alert-error, .alert-success, .alert-info {
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
        .alert-info {
            background: rgba(59,130,246,.12);
            border: 1px solid rgba(59,130,246,.35);
            color: #93c5fd;
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            25%      { transform: translateX(-6px); }
            75%      { transform: translateX(6px); }
        }

        .otp-timer {
            text-align: center;
            font-size: 12.5px;
            color: rgba(255,255,255,.65);
            margin-bottom: 20px;
            text-shadow: 0 1px 3px rgba(0,0,0,.5);
        }
        .otp-timer span { color: #fbbf24; font-weight: 700; }

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
            height: 48px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 13px;
            outline: none;
            padding: 0 16px 0 46px;
            color: #ffffff;
            font-family: inherit;
            font-size: 22px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 6px;
            text-shadow: 0 1px 2px rgba(0,0,0,.4);
            transition: border-color .25s, box-shadow .25s, background .25s;
        }
        .field-input::placeholder {
            color: rgba(200,220,255,.4);
            letter-spacing: normal;
            font-size: 15px;
            font-weight: 400;
        }
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

            <?php if (empty($error) && empty($success) && !empty($email) && !$email_failed): ?>
                <div class="alert-info">
                    <i class="fas fa-envelope"></i>
                    <span>We sent a 6-digit OTP to <strong><?php echo htmlspecialchars($email); ?></strong>. Please check your inbox.</span>
                </div>
            <?php endif; ?>

            <?php if ($email_failed): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Email delivery failed. Please click <strong>Resend OTP</strong> below.</span>
                </div>
            <?php endif; ?>

            <div class="otp-timer" id="otpTimer">
                OTP expires in <span id="countdown">05:00</span>
            </div>

            <form method="POST" action="verify_otp.php?email=<?php echo urlencode($email); ?>">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <div class="form-group">
                    <label for="otp" class="field-label">Enter OTP</label>
                    <div class="input-wrap">
                        <i class="fas fa-key input-icon"></i>
                        <input type="text" name="otp" id="otp" class="field-input"
                               placeholder="123456" maxlength="6" inputmode="numeric"
                               pattern="\d{6}" required autofocus autocomplete="one-time-code">
                    </div>
                </div>

                <button type="submit" class="btn-login" id="submitBtn">
                    <i class="fas fa-check-circle"></i>
                    <span>Verify OTP</span>
                </button>
            </form>

            <div class="login-footer">
                <?php if (!empty($email)): ?>
                <a href="?resend=1&email=<?php echo urlencode($email); ?>">
                    <i class="fas fa-redo"></i> Resend OTP
                </a>
                <?php endif; ?>
                <a href="forgot_password.php">
                    <i class="fas fa-arrow-left"></i> Start Over
                </a>
                <a href="login.php">
                    <i class="fas fa-sign-in-alt"></i> Back to Login
                </a>
            </div>
        </div>
        <div class="page-footer">
            &copy; <?php echo date('Y'); ?> Petron Station Management System. All Rights Reserved.
        </div>
    </div>

<script>
(function() {
    let seconds = 300;
    const el = document.getElementById('countdown');
    const tick = setInterval(() => {
        seconds--;
        if (seconds <= 0) {
            clearInterval(tick);
            el.textContent = '00:00';
            el.style.color = '#f87171';
        } else {
            const m = String(Math.floor(seconds / 60)).padStart(2, '0');
            const s = String(seconds % 60).padStart(2, '0');
            el.textContent = m + ':' + s;
            if (seconds <= 60) el.style.color = '#f87171';
        }
    }, 1000);
})();

const otpInput = document.getElementById('otp');
otpInput.addEventListener('input', () => {
    otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 6);
    if (otpInput.value.length === 6) {
        otpInput.form.submit();
    }
});
</script>
</body>
</html>
