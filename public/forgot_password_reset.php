<?php
ob_start();
session_start();

// Include database connection
require_once __DIR__ . '/../public/db_connect.php';

$message = '';
$message_type = '';
$error = '';
$token_valid = false;
$user_data = null;

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Get token, email and phone from URL
$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';
$phone = $_GET['phone'] ?? '';

if (empty($token) || (empty($email) && empty($phone))) {
    $error = "Invalid reset request. Please request a new password reset.";
} else {
    try {
        if (!empty($email)) {
            // Validate token against email
            $stmt = $pdo->prepare("
                SELECT prt.user_id, prt.token, prt.expires_at, prt.is_used, prt.used_at,
                       u.username, u.email, u.phone 
                FROM password_reset_tokens prt
                JOIN users u ON prt.user_id = u.id
                WHERE prt.token = ? AND u.email = ? AND prt.token_type = 'reset' AND u.status = 'active' AND (u.is_deleted = 0 OR u.is_deleted IS NULL)
                LIMIT 1
            ");
            $stmt->execute([$token, $email]);
        } else {
            // Validate token against phone
            $stmt = $pdo->prepare("
                SELECT prt.user_id, prt.token, prt.expires_at, prt.is_used, prt.used_at,
                       u.username, u.email, u.phone 
                FROM password_reset_tokens prt
                JOIN users u ON prt.user_id = u.id
                WHERE prt.token = ? AND u.phone = ? AND prt.token_type = 'reset' AND u.status = 'active' AND (u.is_deleted = 0 OR u.is_deleted IS NULL)
                LIMIT 1
            ");
            $stmt->execute([$token, $phone]);
        }
        $token_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$token_data) {
            $error = "Invalid or expired reset link. Please request a new password reset.";
        } elseif (strtotime($token_data['expires_at']) < time()) {
            $error = "Reset link has expired. Please request a new password reset.";
        } elseif ($token_data['is_used'] == 1 && $token_data['used_at'] !== null) {
            $error = "Reset link has already been used. Please request a new password reset.";
        } else {
            $token_valid = true;
            $user_data = $token_data;
        }
    } catch (PDOException $e) {
        error_log("Token validation error: " . $e->getMessage());
        $error = "System error. Please try again later.";
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Strong password validation
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
        $password_errors[] = "Passwords do not match.";
    }
    
    if (empty($password_errors)) {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Update password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->execute([$hashed_password, $user_data['user_id']]);
            
            // Mark token as used
            $tokenStmt = $pdo->prepare("UPDATE password_reset_tokens SET is_used = 1, used_at = NOW() WHERE token = ?");
            $tokenStmt->execute([$token]);
            
            // Log the password reset
            $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Password Reset', 'Password successfully reset', ?)");
            $logStmt->execute([$user_data['user_id'], $_SERVER['REMOTE_ADDR']]);
            
            // Commit transaction
            $pdo->commit();
            
            $message = "Your password has been successfully reset. You can now login with your new password.";
            $message_type = "success";
            
            // Clear token data to hide form
            $token_valid = false;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
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
    <title>Reset Password | Petron Management System</title>
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
            padding: 0 46px;
            color: #ffffff;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0,0,0,.4);
        }

        .field-input::placeholder {
            color: rgba(255,255,255,.45);
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

        /* Hide browser native password reveal eye (Chrome/Edge/IE) */
        .field-input[type="password"]::-ms-reveal,
        .field-input[type="password"]::-ms-clear,
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none !important;
        }
        input[type="password"]::-webkit-contacts-auto-fill-button,
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="password"]::-webkit-strong-password-auto-fill-button {
            display: none !important;
            visibility: hidden;
            pointer-events: none;
        }

        /* Password Strength */
        .password-strength {
            margin-top: 8px;
            font-size: 12px;
            font-weight: 600;
            text-shadow: 0 1px 2px rgba(0,0,0,.5);
        }

        .strength-weak   { color: #f87171; }
        .strength-medium { color: #fbbf24; }
        .strength-strong { color: #34d399; }

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

        .error-banner, .success-banner, .info-banner {
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
            <img src="../assets/img/Petron Logo.png" alt="Petron" class="brand-logo">
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
        <?php if ($message): ?>
            <div class="success-banner" role="status">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($token_valid): ?>
            <!-- Info Message -->
            <div class="info-banner">
                <i class="fas fa-info-circle"></i>
                <span>Resetting password for: <strong><?php echo htmlspecialchars($user_data['email'] ?: ($user_data['phone'] ?: $user_data['username'])); ?></strong></span>
            </div>

            <!-- Reset Password Form -->
            <form method="POST" action="" id="resetForm">
                <div class="field-group">
                    <label for="password" class="field-label">New Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" id="password" class="field-input" placeholder="Enter password" required autofocus aria-label="New Password">
                        <button type="button" class="pw-toggle" id="togglePassword" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength" id="passwordStrength"></div>
                </div>

                <div class="field-group">
                    <label for="confirm_password" class="field-label">Confirm Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="confirm_password" id="confirm_password" class="field-input" placeholder="Enter password" required aria-label="Confirm Password">
                        <button type="button" class="pw-toggle" id="toggleConfirmPassword" aria-label="Show confirm password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">
                    <div class="spinner" id="spinner"></div>
                    <span id="btnText">Reset Password</span>
                </button>
            </form>
        <?php endif; ?>

        <!-- Secondary Links -->
        <div class="links-wrap">
            <a href="login.php" class="forgot-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
            <?php if (!$token_valid && !$message): ?>
                <a href="forgot_password.php" class="forgot-link"><i class="fas fa-redo"></i> Request New Reset Link</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="page-footer">
        &copy; <?php echo date('Y'); ?> Petron Station &amp; Service Center Management System. All Rights Reserved.
    </div>
</div>

<?php if ($token_valid): ?>
<script>
    const togglePassword        = document.getElementById('togglePassword');
    const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
    const passwordInput         = document.getElementById('password');
    const confirmPasswordInput  = document.getElementById('confirm_password');
    const passwordStrength      = document.getElementById('passwordStrength');

    togglePassword.addEventListener('click', () => {
        const type = passwordInput.type === 'password' ? 'text' : 'password';
        passwordInput.type = type;
        togglePassword.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
    });

    toggleConfirmPassword.addEventListener('click', () => {
        const type = confirmPasswordInput.type === 'password' ? 'text' : 'password';
        confirmPasswordInput.type = type;
        toggleConfirmPassword.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
    });

    passwordInput.addEventListener('input', () => {
        const p = passwordInput.value;
        let strength = 0;
        if (p.length >= 8)           strength++;
        if (/[a-z]/.test(p))         strength++;
        if (/[A-Z]/.test(p))         strength++;
        if (/[0-9]/.test(p))         strength++;
        if (/[$@#&!^*(),.?]/.test(p)) strength++;

        const map = {
            0: '', 1: '<span class="strength-weak">Weak password</span>',
            2: '<span class="strength-weak">Weak password</span>',
            3: '<span class="strength-medium">Medium strength</span>',
            4: '<span class="strength-medium">Medium strength</span>',
            5: '<span class="strength-strong">Strong password</span>'
        };
        passwordStrength.innerHTML = map[strength] ?? '';
    });

    const form      = document.getElementById('resetForm');
    const submitBtn = document.getElementById('submitBtn');
    const spinner   = document.getElementById('spinner');
    const btnText   = document.getElementById('btnText');

    form.addEventListener('submit', () => {
        submitBtn.disabled = true;
        spinner.style.display = 'block';
        btnText.textContent = 'Resetting...';
    });
</script>
<?php endif; ?>

</body>
</html>
