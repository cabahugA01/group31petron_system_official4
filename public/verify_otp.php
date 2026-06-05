<?php
session_start();
ob_start();

// Include database connection
require_once __DIR__ . '/../public/db_connect.php';

$message = '';
$message_type = '';
$error = '';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$email = $_GET['email'] ?? $_POST['email'] ?? '';
$phone = $_GET['phone'] ?? $_POST['phone'] ?? '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');
    
    if (empty($otp)) {
        $error = "Please enter the 6-digit OTP.";
    } elseif (strlen($otp) !== 6 || !is_numeric($otp)) {
        $error = "Please enter a valid 6-digit OTP.";
    } else {
        try {
            if (!empty($email)) {
                // Verify token using email
                $stmt = $pdo->prepare("
                    SELECT prt.user_id, prt.token, prt.expires_at, prt.is_used, prt.used_at,
                           u.username, u.email, u.phone 
                    FROM password_reset_tokens prt
                    JOIN users u ON prt.user_id = u.id
                    WHERE prt.token = ? AND prt.token_type = 'reset' AND u.status = 'active' AND (u.is_deleted = 0 OR u.is_deleted IS NULL) AND u.email = ?
                    LIMIT 1
                ");
                $stmt->execute([$otp, $email]);
            } else {
                // Verify token using phone
                $stmt = $pdo->prepare("
                    SELECT prt.user_id, prt.token, prt.expires_at, prt.is_used, prt.used_at,
                           u.username, u.email, u.phone 
                    FROM password_reset_tokens prt
                    JOIN users u ON prt.user_id = u.id
                    WHERE prt.token = ? AND prt.token_type = 'reset' AND u.status = 'active' AND (u.is_deleted = 0 OR u.is_deleted IS NULL) AND u.phone = ?
                    LIMIT 1
                ");
                $stmt->execute([$otp, $phone]);
            }
            $token_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$token_data) {
                $error = "Invalid OTP. Please check the code and try again.";
            } elseif (strtotime($token_data['expires_at']) < time()) {
                $error = "OTP has expired. Please request a new password reset.";
            } elseif ($token_data['is_used'] == 1 && $token_data['used_at'] !== null) {
                $error = "OTP has already been used. Please request a new password reset.";
            } else {
                // OTP is valid! Redirect to reset form
                header("Location: forgot_password_reset.php?token=" . urlencode($otp) . "&email=" . urlencode($token_data['email'] ?? '') . "&phone=" . urlencode($token_data['phone'] ?? ''));
                exit;
            }
        } catch (PDOException $e) {
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

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: url('../assets/img/background.jpg') center center / cover no-repeat;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
            position: relative;
        }

        .login-wrap {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 2;
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
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes cardGlowFlow {
            0%, 100% { box-shadow: 0 4px 0 rgba(255,255,255,.05) inset, 0 -2px 0 rgba(0,0,0,.6) inset, 0 12px 40px rgba(0,0,0,.6), 0 32px 80px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.08), 0 0 50px var(--blue-glow); }
            50% { box-shadow: 0 4px 0 rgba(255,255,255,.05) inset, 0 -2px 0 rgba(0,0,0,.6) inset, 0 12px 40px rgba(0,0,0,.6), 0 32px 80px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.08), 0 0 60px var(--red-glow); }
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
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 4px;
            text-shadow: 0 1px 2px rgba(0,0,0,.4);
        }

        .field-input::placeholder {
            color: rgba(255,255,255,.3);
            letter-spacing: normal;
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

        .error-banner, .info-banner {
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

        .info-banner {
            background: rgba(59,130,246,.2);
            border: 1.5px solid rgba(59,130,246,.45);
            color: #bfdbfe;
        }

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
            cursor: default;
            user-select: none;
            pointer-events: none;
        }

        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 540px) {
            .login-wrap { padding: 0 12px; }
            .login-card { padding: 38px 28px 32px; }
        }
    </style>
</head>
<body>

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
        
        <?php if (empty($error)): ?>
            <?php if (!empty($email)): ?>
                <div class="info-banner">
                    <i class="fas fa-info-circle"></i>
                    <span>If the email <strong><?php echo htmlspecialchars($email); ?></strong> is registered, we sent an OTP. Please check your inbox.</span>
                </div>
            <?php elseif (!empty($phone)): ?>
                <div class="info-banner">
                    <i class="fas fa-info-circle"></i>
                    <span>If the phone <strong><?php echo htmlspecialchars($phone); ?></strong> is registered, we sent an OTP SMS. Please check your messages.</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- OTP Form -->
        <form method="POST" action="" id="verifyForm">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <input type="hidden" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
            <div class="field-group">
                <label for="otp" class="field-label">Enter OTP</label>
                <div class="input-wrap">
                    <i class="fas fa-key input-icon"></i>
                    <input type="text" name="otp" id="otp" class="field-input" placeholder="123456" maxlength="6" required autofocus autocomplete="off">
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <div class="spinner" id="spinner"></div>
                <span id="btnText">Verify OTP</span>
            </button>
        </form>

        <!-- Secondary Links -->
        <div class="links-wrap">
            <a href="forgot_password.php" class="forgot-link"><i class="fas fa-redo"></i> Request New OTP</a>
            <a href="login.php" class="forgot-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>

    <div class="page-footer">
        &copy; <?php echo date('Y'); ?> Petron Station &amp; Service Center Management System. All Rights Reserved.
    </div>
</div>

<script>
    const form = document.getElementById('verifyForm');
    const submitBtn = document.getElementById('submitBtn');
    const spinner = document.getElementById('spinner');
    const btnText = document.getElementById('btnText');

    if (form) {
        form.addEventListener('submit', () => {
            submitBtn.disabled = true;
            spinner.style.display = 'block';
            btnText.textContent = 'Verifying...';
        });
    }
</script>

</body>
</html>
