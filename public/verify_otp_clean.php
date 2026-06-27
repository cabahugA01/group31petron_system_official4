<?php
session_start();
ob_start();

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

$error = '';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// EMAIL ONLY - No phone support
$email = trim($_GET['email'] ?? $_POST['email'] ?? '');

// ── Dev Mode: Show OTP on screen when in development ─────────────────
$dev_otp = null;
$show_dev_mode = false;

try {
    if (!empty($email)) {
        // Try to fetch the latest OTP for this email
        $s = $pdo->prepare("
            SELECT prt.token 
            FROM password_reset_tokens prt 
            JOIN users u ON prt.user_id = u.user_id OR prt.user_id = u.id
            WHERE TRIM(u.email) = ? 
              AND prt.token_type = 'reset' 
              AND prt.is_used = 0 
              AND prt.expires_at > NOW() 
            ORDER BY prt.id DESC 
            LIMIT 1
        ");
        $s->execute([$email]);
        $dev_otp = $s->fetchColumn() ?: null;
        
        // Show dev mode if OTP exists (simulated environment)
        if ($dev_otp !== null) {
            $show_dev_mode = true;
        }
    }
} catch (Exception $e) { /* silently ignore */ }

// Handle OTP submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    SELECT prt.user_id, prt.token, prt.expires_at, prt.is_used,
                           u.username, TRIM(u.email) AS email
                    FROM   password_reset_tokens prt
                    JOIN   users u ON prt.user_id = u.`{$uid_col}`
                    WHERE  prt.token      = ?
                      AND  prt.token_type = 'reset'
                      AND  u.status       = ?
                      AND  TRIM(u.email)  = ?
                    LIMIT  1
                ");
                $stmt->execute([$otp, $status_active, $email]);
                $token_data = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($token_data) {
                    if (strtotime($token_data['expires_at']) < time()) {
                        $error = "OTP has expired. Please request a new password reset.";
                    } elseif ($token_data['is_used'] == 1) {
                        $error = "OTP has already been used. Please request a new password reset.";
                    } else {
                        // Valid OTP! Redirect to reset password page
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
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            background: transparent;
        }

        /* ── Base Image Background only ── */
        .bg-layer { position: fixed; inset: 0; z-index: 0; display: none !important; }
        .bg-image { display: block !important; background: url('../assets/img/background.jpg') center center / cover no-repeat; z-index: 1; }
        
        .bg-gradient {
            background: linear-gradient(135deg, rgba(0,47,108,0.3) 0%, rgba(227,6,19,0.15) 25%, rgba(0,15,45,0.4) 50%, rgba(0,80,180,0.2) 75%, rgba(0,26,61,0.35) 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            z-index: 2;
            mix-blend-mode: multiply;
            opacity: 0.7;
        }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
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
            margin-bottom: 16px;
            filter: drop-shadow(0 4px 16px rgba(227,6,19,.4));
        }

        .brand-tagline {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2.8px;
            text-transform: uppercase;
            color: rgba(180,210,255,.9);
        }

        .error-banner, .info-banner {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .error-banner {
            background: rgba(220,38,38,.25);
            border: 1.5px solid rgba(220,38,38,.45);
            color: #fca5a5;
        }

        .info-banner {
            background: rgba(59,130,246,.2);
            border: 1.5px solid rgba(59,130,246,.45);
            color: #bfdbfe;
        }

        .dev-mode-box {
            background: rgba(234,179,8,0.15);
            border: 1.5px solid rgba(234,179,8,0.5);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            text-align: center;
        }

        .dev-mode-box strong {
            display: block;
            color: #fbbf24;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .dev-mode-box .otp-code {
            font-size: 32px;
            letter-spacing: 8px;
            color: #fff;
            font-weight: 900;
            font-family: monospace;
        }

        .field-group { margin-bottom: 24px; }

        .field-label {
            display: block;
            margin-bottom: 8px;
            font-size: 12.5px;
            font-weight: 700;
            color: rgba(255,255,255,.9);
            letter-spacing: .8px;
            text-transform: uppercase;
        }

        .input-wrap {
            position: relative;
            border-radius: 14px;
            background: rgba(0,0,0,.45);
            border: 1.5px solid rgba(255,255,255,.15);
            transition: border-color .25s;
        }

        .input-wrap:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 14px rgba(59,130,246,.5);
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,.92);
            font-size: 16px;
        }

        .field-input {
            width: 100%;
            height: 48px;
            background: transparent;
            border: none;
            outline: none;
            padding: 0 16px 0 46px;
            color: #ffffff;
            font-size: 22px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 6px;
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
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: transform .15s;
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0,47,108,.6);
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .forgot-link:hover { color: #93c5fd; }

        @media (max-width: 540px) {
            .login-wrap { padding: 0 12px; }
            .login-card  { padding: 38px 28px 32px; }
        }
    </style>
</head>
<body>

<div class="bg-layer bg-image"></div>
<div class="bg-layer bg-gradient"></div>

<div class="login-wrap">
    <div class="login-card">
        <div class="brand">
            <img src="<?php echo '../' . get_system_logo_url(isset($station_id) ? (int)$station_id : (isset($user['station_id']) ? (int)$user['station_id'] : 0)); ?>" alt="Petron" class="brand-logo">
            <span class="brand-tagline">Station Management System</span>
        </div>

        <?php if ($error): ?>
            <div class="error-banner">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if (empty($error) && !empty($email)): ?>
            <div class="info-banner">
                <i class="fas fa-envelope"></i>
                <span>We sent a 6-digit OTP to <strong><?php echo htmlspecialchars($email); ?></strong>. Please check your inbox.</span>
            </div>
        <?php endif; ?>

        <?php if ($show_dev_mode && $dev_otp !== null): ?>
            <div class="dev-mode-box">
                <strong>⚙️ Dev Mode - OTP for Testing</strong>
                <div class="otp-code"><?php echo htmlspecialchars($dev_otp); ?></div>
                <small style="color:#fde68a;font-size:11px;">Check your email for the actual OTP</small>
            </div>
        <?php endif; ?>

        <div class="otp-timer">
            OTP expires in <span id="countdown">05:00</span>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            
            <div class="field-group">
                <label for="otp" class="field-label">Enter OTP</label>
                <div class="input-wrap">
                    <i class="fas fa-key input-icon"></i>
                    <input type="text" 
                           name="otp" 
                           id="otp" 
                           class="field-input"
                           placeholder="123456" 
                           maxlength="6" 
                           inputmode="numeric"
                           pattern="\d{6}" 
                           required 
                           autofocus 
                           autocomplete="one-time-code">
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <span>Verify OTP</span>
                <i class="fas fa-check-circle"></i>
            </button>
        </form>

        <div class="links-wrap">
            <a href="forgot_password.php" class="forgot-link">
                <i class="fas fa-redo"></i> Request New OTP
            </a>
            <a href="login.php" class="forgot-link">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
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
