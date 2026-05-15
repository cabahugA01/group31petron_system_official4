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

// Get token and email from URL
$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

if (empty($token) || empty($email)) {
    $error = "Invalid reset request. Please request a new password reset.";
} else {
    try {
        // Validate token against existing password_reset_tokens table
        $stmt = $pdo->prepare("
            SELECT prt.user_id, prt.token, prt.expires_at, prt.is_used, prt.used_at,
                   u.username, u.email 
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.id
            WHERE prt.token = ? AND u.email = ? AND prt.token_type = 'reset' AND u.status = 'active' AND u.is_deleted = 0
            LIMIT 1
        ");
        $stmt->execute([$token, $email]);
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <style>
        :root {
            --petron-blue: #002F6C;
            --petron-red: #E30613;
            --petron-gray: #CCCCCC;
            --bg-color: #f4f6f9;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: url('../assets/img/background.jpg') center center/cover no-repeat, linear-gradient(135deg, var(--petron-blue) 0%, #001a4d 100%);
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .reset-card {
            background: #ffffff;
            width: 100%;
            max-width: 420px;
            padding: 40px;
            border-radius: 8px;
            border: 3px solid var(--petron-blue);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            text-align: center;
            animation: borderAnimation 3s ease-in-out infinite;
        }

        @keyframes borderAnimation {
            0%   { border-color: var(--petron-blue); box-shadow: 0 10px 25px rgba(0, 47, 108, 0.1); }
            25%  { border-color: var(--petron-blue); box-shadow: 0 10px 25px rgba(0, 47, 108, 0.2); }
            50%  { border-color: var(--petron-red);  box-shadow: 0 10px 25px rgba(227, 6, 19, 0.3); }
            75%  { border-color: var(--petron-red);  box-shadow: 0 10px 25px rgba(227, 6, 19, 0.2); }
            100% { border-color: var(--petron-blue); box-shadow: 0 10px 25px rgba(0, 47, 108, 0.1); }
        }

        /* Branding */
        .brand-logo {
            max-width: 120px;
            height: auto;
            margin-bottom: 15px;
        }

        .brand-title {
            color: var(--petron-blue);
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .brand-subtitle {
            color: #666;
            font-size: 12px;
            margin-bottom: 30px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            color: #999;
            font-size: 18px;
            z-index: 10;
        }

        .form-control {
            width: 100%;
            padding: 12px 40px 12px 45px;
            font-size: 16px;
            border: 1px solid #ddd;
            border-radius: 5px;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }

        .form-control:focus {
            border-color: var(--petron-blue);
            box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1);
            outline: none;
        }

        .toggle-password {
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 16px;
            padding: 8px;
            z-index: 10;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0 4px 4px 0;
            transition: all 0.2s ease;
        }

        .toggle-password:hover {
            color: var(--petron-blue);
            background-color: rgba(0, 47, 108, 0.1);
        }

        /* Password Strength Indicator */
        .password-strength {
            margin-top: 6px;
            font-size: 12px;
        }

        .strength-weak   { color: var(--petron-red); }
        .strength-medium { color: #f39c12; }
        .strength-strong { color: #27ae60; }

        /* Button */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background-color: var(--petron-blue);
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .btn-submit:hover    { background-color: #001f4d; }
        .btn-submit:disabled { background-color: #99aab5; cursor: not-allowed; }

        /* Banners */
        .error-banner {
            background-color: #fde8e8;
            color: var(--petron-red);
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #fbd5d5;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-banner {
            background-color: #e8f5e8;
            color: #2d5a2d;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #d5e8d5;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-banner {
            background-color: #ebf5ff;
            color: var(--petron-blue);
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #cce5ff;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .links {
            margin-top: 25px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            font-size: 14px;
        }

        .links a {
            color: var(--petron-blue);
            text-decoration: none;
            font-weight: 500;
        }

        .links a:hover { text-decoration: underline; }

        /* Footer */
        .footer {
            margin-top: 40px;
            color: var(--petron-blue);
            font-size: 16px;
            font-weight: bold;
            animation: footerColorAnimation 3s ease-in-out infinite;
        }

        @keyframes footerColorAnimation {
            0%   { color: var(--petron-blue); }
            25%  { color: var(--petron-blue); }
            50%  { color: var(--petron-red);  }
            75%  { color: var(--petron-red);  }
            100% { color: var(--petron-blue); }
        }

        /* Spinner */
        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
            display: none;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* Responsive */
        @media (max-width: 600px) {
            .reset-card { width: 95%; padding: 30px 20px; }
        }
    </style>
</head>
<body>

    <div class="reset-card">
        <!-- Branding -->
        <img src="../assets/img/Petron Logo.png" alt="Petron logo" class="brand-logo">
        <h1 class="brand-title">Reset Password</h1>
        <p class="brand-subtitle">Create New Password</p>

        <!-- Error Message -->
        <?php if ($error): ?>
            <div class="error-banner" role="alert">
                <span><i class="fas fa-exclamation-triangle"></i></span>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Success Message -->
        <?php if ($message): ?>
            <div class="success-banner" role="status">
                <span><i class="fas fa-check-circle"></i></span>
                <span><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($token_valid): ?>
            <!-- Info Message -->
            <div class="info-banner">
                <span><i class="fas fa-info-circle"></i></span>
                <span>Resetting password for: <strong><?php echo htmlspecialchars($user_data['email']); ?></strong></span>
            </div>

            <!-- Reset Password Form -->
            <form method="POST" action="" id="resetForm">
                <div class="form-group">
                    <label for="password" style="display:block;margin-bottom:5px;font-weight:600;color:#333;">New Password</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Enter new password" required autofocus aria-label="New Password">
                        <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength" id="passwordStrength"></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" style="display:block;margin-bottom:5px;font-weight:600;color:#333;">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Confirm new password" required aria-label="Confirm Password">
                        <button type="button" class="toggle-password" id="toggleConfirmPassword" aria-label="Show confirm password">
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
        <div class="links">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
            <?php if (!$token_valid && !$message): ?>
                <a href="forgot_password.php"><i class="fas fa-redo"></i> Request New Reset Link</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        &copy; <?php echo date('Y'); ?> Petron Station &amp; Service Center Management System. All Rights Reserved.
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
