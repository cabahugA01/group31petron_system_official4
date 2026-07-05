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
if (isset($_GET['resend']) && $_GET['resend'] === '1' && !empty($email)) {
    try {
        $uid_col = 'id';

        // Find user by email
        $stmt = $pdo->prepare("SELECT `{$uid_col}` AS user_id, username, TRIM(email) AS email FROM users WHERE LOWER(TRIM(email)) = LOWER(?) AND LOWER(TRIM(status)) = 'active' LIMIT 1");
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
        :root {
            --blue-glow: rgba(0, 100, 255, 0.45);
            --red-glow: rgba(227, 6, 19, 0.35);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

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

        .field-group { margin-bottom: 24px; position: relative; }

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
            font-size: 22px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 6px;
            text-shadow: 0 1px 2px rgba(0,0,0,.4);
        }

        .field-input::placeholder {
            color: rgba(255,255,255,.3);
            letter-spacing: normal;
            font-size: 16px;
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

        .btn-submit:active  { transform: translateY(1px); box-shadow: 0 2px 10px rgba(0,47,108,.4); }
        .btn-submit:disabled { background: #4a5568; border-color: rgba(255,255,255,.05); cursor: not-allowed; box-shadow: none; transform: none; }

        .error-banner, .info-banner, .success-banner {
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

        .error-banner { background: rgba(220,38,38,.25); border: 1.5px solid rgba(220,38,38,.45); color: #fca5a5; }
        .success-banner { background: rgba(16,185,129,.2); border: 1.5px solid rgba(16,185,129,.45); color: #a7f3d0; }
        .info-banner {
            background: rgba(59,130,246,.2);
            border: 1.5px solid rgba(59,130,246,.45);
            color: #bfdbfe;
        }

        .otp-timer {
            text-align: center;
            font-size: 12.5px;
            color: rgba(255,255,255,.55);
            margin-bottom: 20px;
        }

        .otp-timer span { color: #fbbf24; font-weight: 700; }

        .links-wrap {
            margin-top: 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: center;
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

        .forgot-link:hover { color: #93c5fd; text-shadow: 0 0 8px rgba(147,197,253,.5); }

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
            .login-card  { padding: 38px 28px 32px; }
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
            <img src="<?php echo '../' . get_system_logo_url(isset($station_id) ? (int)$station_id : (isset($user['station_id']) ? (int)$user['station_id'] : 0)); ?>" alt="Petron logo" class="brand-logo">
            <span class="brand-tagline">Station Management System</span>
        </div>

        <!-- Error Message -->
        <?php if ($error): ?>
            <div class="error-banner" role="alert">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Success Message -->
        <?php if ($success): ?>
            <div class="success-banner" role="status">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <!-- Info Banner -->
        <?php if (empty($error) && empty($success) && !empty($email) && !$email_failed): ?>
            <div class="info-banner">
                <i class="fas fa-envelope"></i>
                <span>We sent a 6-digit OTP to <strong><?php echo htmlspecialchars($email); ?></strong>. Please check your inbox.</span>
            </div>
        <?php endif; ?>

        <!-- Email Failed Warning -->
        <?php if ($email_failed): ?>
            <div style="background:rgba(239,68,68,.15);border:1.5px solid rgba(239,68,68,.45);border-radius:12px;padding:12px 16px;font-size:13px;font-weight:600;margin-bottom:20px;color:#fca5a5;display:flex;align-items:center;gap:12px;">
                <i class="fas fa-exclamation-circle"></i>
                <span>Email delivery failed. Please click <strong>Resend OTP</strong> below or contact your administrator.</span>
            </div>
        <?php endif; ?>

        <!-- Countdown -->
        <div class="otp-timer" id="otpTimer">
            OTP expires in <span id="countdown">05:00</span>
        </div>

        <!-- OTP Form -->
        <form method="POST" action="">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <div class="field-group">
                <label for="otp" class="field-label">Enter OTP</label>
                <div class="input-wrap">
                    <i class="fas fa-key input-icon"></i>
                    <input type="text" name="otp" id="otp" class="field-input"
                           placeholder="123456" maxlength="6" inputmode="numeric"
                           pattern="\d{6}" required autofocus autocomplete="one-time-code">
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <span>Verify OTP</span>
                <i class="fas fa-check-circle"></i>
            </button>
        </form>

        <!-- Links -->
        <div class="links-wrap">
            <?php if (!empty($email)): ?>
            <a href="?resend=1&email=<?php echo urlencode($email); ?>" class="forgot-link">
                <i class="fas fa-redo"></i> Resend OTP
            </a>
            <?php endif; ?>
            <a href="forgot_password.php" class="forgot-link">
                <i class="fas fa-arrow-left"></i> Start Over
            </a>
            <a href="login.php" class="forgot-link">
                <i class="fas fa-sign-in-alt"></i> Back to Login
            </a>
        </div>
    </div>

    <div class="page-footer">
        &copy; <?php echo date('Y'); ?> Petron Station Management System. All Rights Reserved.
    </div>
</div>

<script>
// Countdown timer (5 minutes)
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

// Auto-submit when 6 digits entered
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
