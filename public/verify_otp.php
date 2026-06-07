<?php
session_start();
ob_start();

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../config/email_config.php';

$message = '';
$error = '';
$success = '';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// EMAIL ONLY - No phone support
$email = trim($_GET['email'] ?? $_POST['email'] ?? $_SESSION['reset_email'] ?? '');

// Store email in session for resend functionality
if (!empty($email)) {
    $_SESSION['reset_email'] = $email;
}

// Handle RESEND OTP request
if (isset($_GET['resend']) && $_GET['resend'] === '1' && !empty($email)) {
    try {
        // Auto-detect column names
        $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $uid_col = in_array('user_id', $cols) ? 'user_id' : 'id';
        $status_active = in_array('Active', $pdo->query("SELECT DISTINCT status FROM users")->fetchAll(PDO::FETCH_COLUMN)) ? 'Active' : 'active';

        // Find user by email
        $stmt = $pdo->prepare("SELECT `{$uid_col}` AS user_id, username, TRIM(email) AS email FROM users WHERE TRIM(email) = ? AND status = ? LIMIT 1");
        $stmt->execute([$email, $status_active]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Generate new OTP
            $otp_code = sprintf("%06d", random_int(100000, 999999));

            // Delete old OTPs and insert new one
            $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND token_type = 'reset'")->execute([$user['user_id']]);
            $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, token_type, expires_at, ip_address) VALUES (?, ?, 'reset', DATE_ADD(NOW(), INTERVAL 5 MINUTE), ?)")
                ->execute([$user['user_id'], $otp_code, $_SERVER['REMOTE_ADDR']]);

            // Send new OTP via email
            if (function_exists('sendPasswordResetOTP')) {
                sendPasswordResetOTP($user['email'], $otp_code);
            }

            // Log resend attempt
            try {
                $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'OTP Resend', ?, ?)")
                    ->execute([$user['user_id'], "OTP resent to: {$email}", $_SERVER['REMOTE_ADDR']]);
            } catch (Exception $e) {}

            $success = "A new OTP has been sent to your email. Please check your inbox.";
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
            $uid_col = in_array('user_id', $cols) ? 'user_id' : 'id';
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
                      AND  u.status       = ?
                      AND  TRIM(u.email)  = ?
                    ORDER BY prt.id DESC
                    LIMIT  1
                ");
                $stmt->execute([$otp, $status_active, $email]);
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
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
            background: #000814;
        }

        /* 4D Animated Background Layers */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
        }

        /* Base image layer */
        .bg-image {
            background: url('../assets/img/background.jpg') center/cover no-repeat;
            filter: brightness(0.6) blur(0px);
            z-index: 1;
        }

        /* Animated gradient overlay */
        .bg-gradient {
            background: linear-gradient(
                135deg,
                rgba(0, 47, 108, 0.3) 0%,
                rgba(227, 6, 19, 0.15) 25%,
                rgba(0, 15, 45, 0.4) 50%,
                rgba(0, 80, 180, 0.2) 75%,
                rgba(0, 26, 61, 0.35) 100%
            );
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            z-index: 2;
            mix-blend-mode: multiply;
            opacity: 0.7;
        }

        @keyframes gradientShift {
            0%   { background-position: 0% 50%; }
            25%  { background-position: 50% 100%; }
            50%  { background-position: 100% 50%; }
            75%  { background-position: 50% 0%; }
            100% { background-position: 0% 50%; }
        }

        /* Floating particles layer */
        .bg-particles {
            z-index: 3;
            pointer-events: none;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(96, 165, 250, 0.8), transparent);
            animation: float linear infinite;
            opacity: 0;
        }

        .particle:nth-child(1) { 
            width: 4px; height: 4px; 
            left: 10%; top: 80%; 
            animation-duration: 8s; 
            animation-delay: 0s;
            box-shadow: 0 0 20px rgba(96, 165, 250, 0.6);
        }
        .particle:nth-child(2) { 
            width: 6px; height: 6px; 
            left: 20%; top: 60%; 
            animation-duration: 12s; 
            animation-delay: 1s;
            box-shadow: 0 0 25px rgba(227, 6, 19, 0.5);
            background: radial-gradient(circle, rgba(227, 6, 19, 0.7), transparent);
        }
        .particle:nth-child(3) { 
            width: 3px; height: 3px; 
            left: 35%; top: 90%; 
            animation-duration: 10s; 
            animation-delay: 2s;
            box-shadow: 0 0 15px rgba(96, 165, 250, 0.5);
        }
        .particle:nth-child(4) { 
            width: 5px; height: 5px; 
            left: 50%; top: 85%; 
            animation-duration: 14s; 
            animation-delay: 0.5s;
            box-shadow: 0 0 22px rgba(147, 197, 253, 0.6);
        }
        .particle:nth-child(5) { 
            width: 4px; height: 4px; 
            left: 65%; top: 75%; 
            animation-duration: 11s; 
            animation-delay: 1.5s;
            box-shadow: 0 0 18px rgba(96, 165, 250, 0.5);
        }
        .particle:nth-child(6) { 
            width: 7px; height: 7px; 
            left: 80%; top: 70%; 
            animation-duration: 13s; 
            animation-delay: 2.5s;
            box-shadow: 0 0 28px rgba(227, 6, 19, 0.6);
            background: radial-gradient(circle, rgba(227, 6, 19, 0.8), transparent);
        }
        .particle:nth-child(7) { 
            width: 3px; height: 3px; 
            left: 90%; top: 80%; 
            animation-duration: 9s; 
            animation-delay: 1.8s;
            box-shadow: 0 0 16px rgba(96, 165, 250, 0.4);
        }
        .particle:nth-child(8) { 
            width: 5px; height: 5px; 
            left: 15%; top: 50%; 
            animation-duration: 15s; 
            animation-delay: 3s;
            box-shadow: 0 0 24px rgba(147, 197, 253, 0.7);
        }

        @keyframes float {
            0% {
                transform: translateY(0) translateX(0) scale(1);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100vh) translateX(30px) scale(1.5);
                opacity: 0;
            }
        }

        /* Glowing orbs layer */
        .bg-orbs {
            z-index: 4;
            pointer-events: none;
            opacity: 0.6;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.25;
            animation: orbFloat ease-in-out infinite alternate;
        }

        .orb-1 {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(0, 47, 108, 0.4), transparent);
            top: -10%;
            left: -10%;
            animation-duration: 8s;
        }

        .orb-2 {
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(227, 6, 19, 0.3), transparent);
            bottom: -10%;
            right: -10%;
            animation-duration: 10s;
            animation-delay: 1s;
        }

        @keyframes orbFloat {
            0% {
                transform: translate(0, 0) scale(1);
            }
            100% {
                transform: translate(20px, -20px) scale(1.1);
            }
        }

        /* Grid overlay */
        .bg-grid {
            background-image: 
                linear-gradient(rgba(96, 165, 250, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(96, 165, 250, 0.02) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: 5;
            pointer-events: none;
            animation: gridMove 20s linear infinite;
        }

        @keyframes gridMove {
            0% {
                background-position: 0 0;
            }
            100% {
                background-position: 50px 50px;
            }
        }

        .login-wrap {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 10;
            position: relative;
        }

        .login-card {
            background: rgba(0, 15, 45, 0.9);
            backdrop-filter: blur(24px) saturate(1.8) brightness(1.05);
            -webkit-backdrop-filter: blur(24px) saturate(1.8) brightness(1.05);
            width: 100%;
            max-width: 520px;
            border-radius: 28px;
            padding: 48px 40px 36px;
            box-shadow:
                0 4px 0 rgba(255,255,255,.05) inset,
                0 -2px 0 rgba(0,0,0,.6) inset,
                0 12px 40px rgba(0,0,0,.6),
                0 32px 80px rgba(0,0,0,.65),
                0 0 0 1px rgba(255,255,255,.08),
                0 0 50px var(--blue-glow);
            position: relative;
            animation: cardGlowFlow 8s linear infinite;
        }

        .login-card::before {
            content: '';
            position: absolute;
            inset: -1.5px;
            border-radius: 29px;
            background: linear-gradient(90deg, #002F6C, #E30613, #002F6C);
            background-size: 200% auto;
            animation: borderFlow 6s linear infinite;
            z-index: -1;
            opacity: 0.85;
        }

        @keyframes borderFlow {
            0%   { background-position: 0% 50%; }
            50%  { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes cardGlowFlow {
            0%, 100% { box-shadow: 0 4px 0 rgba(255,255,255,.05) inset, 0 -2px 0 rgba(0,0,0,.6) inset, 0 12px 40px rgba(0,0,0,.6), 0 32px 80px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.08), 0 0 50px var(--blue-glow); }
            50%       { box-shadow: 0 4px 0 rgba(255,255,255,.05) inset, 0 -2px 0 rgba(0,0,0,.6) inset, 0 12px 40px rgba(0,0,0,.6), 0 32px 80px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.08), 0 0 60px var(--red-glow); }
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
            <img src="../assets/img/Petron Logo.png" alt="Petron logo" class="brand-logo">
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
        <?php if (empty($error) && empty($success) && !empty($email)): ?>
            <div class="info-banner">
                <i class="fas fa-envelope"></i>
                <span>We sent a 6-digit OTP to <strong><?php echo htmlspecialchars($email); ?></strong>. Please check your inbox.</span>
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
