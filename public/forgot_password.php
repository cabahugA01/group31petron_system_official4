<?php
ob_start();
session_start();

// Include database connection and email config
try {
    require_once __DIR__ . '/../public/db_connect.php';
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $error = "Database connection error. Please try again later.";
}

try {
    require_once __DIR__ . '/../config/email_config.php';
} catch (Exception $e) {
    error_log("Email config failed: " . $e->getMessage());
    $error = "Email service error. Please try again later.";
}

// Configuration variables
$system_name = "Petron Station & Service Center Management System";
$current_year = date("Y");
$footer_text = "&copy; {$current_year} {$system_name}. All Rights Reserved.";

$message = '';
$message_type = '';
$error = '';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            // Check if database connection is available
            if (!isset($pdo) || !$pdo) {
                throw new Exception("Database connection not available");
            }

            // Check if user exists in the existing users table
            $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = ? AND status = 'active' AND is_deleted = 0 LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Log attempt regardless of whether user exists
            try {
                $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Password Reset Request', ?, ?)");
                $logStmt->execute([($user['id'] ?? 0), "Password reset requested for email: " . $email, $_SERVER['REMOTE_ADDR']]);
            } catch (PDOException $logError) {
                // Continue even if logging fails
                error_log("Logging error: " . $logError->getMessage());
            }

            if ($user) {
                // Generate secure 6-digit OTP
                $token = sprintf("%06d", random_int(0, 999999));
                $expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));

                // Delete any existing tokens for this user
                $deleteStmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
                $deleteStmt->execute([$user['id']]);

                // Store new OTP
                $tokenStmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, token_type, expires_at, ip_address) VALUES (?, ?, 'reset', ?, ?)");
                $tokenStmt->execute([$user['id'], $token, $expires_at, $_SERVER['REMOTE_ADDR']]);

                // Send OTP email
                $email_sent = sendPasswordResetOTP($email, $token);

                if ($email_sent) {
                    header("Location: verify_otp.php?email=" . urlencode($email));
                    exit;
                } else {
                    $error = "Failed to send reset email. Please try again later.";
                }
            } else {
                // Vague message for security — don't reveal if email exists or not
                $error = "If that email is registered, you will receive a reset code shortly.";
            }
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        } catch (Exception $e) {
            error_log("General error: " . $e->getMessage());
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
    <title>Forgot Password | Petron Management System</title>
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
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .forgot-card {
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
            0% {
                border-color: var(--petron-blue);
                box-shadow: 0 10px 25px rgba(0, 47, 108, 0.1);
            }
            25% {
                border-color: var(--petron-blue);
                box-shadow: 0 10px 25px rgba(0, 47, 108, 0.2);
            }
            50% {
                border-color: var(--petron-red);
                box-shadow: 0 10px 25px rgba(227, 6, 19, 0.3);
            }
            75% {
                border-color: var(--petron-red);
                box-shadow: 0 10px 25px rgba(227, 6, 19, 0.2);
            }
            100% {
                border-color: var(--petron-blue);
                box-shadow: 0 10px 25px rgba(0, 47, 108, 0.1);
            }
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
            padding: 12px 15px 12px 45px;
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

        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #555;
            margin-bottom: 20px;
        }

        .checkbox-group input {
            margin-right: 10px;
            width: 16px;
            height: 16px;
        }

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

        .btn-submit:hover {
            background-color: #001f4d;
        }

        .btn-submit:disabled {
            background-color: #99aab5;
            cursor: not-allowed;
        }

        /* Utilities */
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

        .links a:hover {
            text-decoration: underline;
        }

        .footer {
            margin-top: 40px;
            color: var(--petron-blue);
            font-size: 16px;
            font-weight: bold;
            animation: footerColorAnimation 3s ease-in-out infinite;
        }

        @keyframes footerColorAnimation {
            0% {
                color: var(--petron-blue);
            }
            25% {
                color: var(--petron-blue);
            }
            50% {
                color: var(--petron-red);
            }
            75% {
                color: var(--petron-red);
            }
            100% {
                color: var(--petron-blue);
            }
        }

        /* Spinner Animation */
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.3s ease;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background-color: var(--petron-blue);
            color: white;
            padding: 20px;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: rgba(255, 255, 255, 0.8);
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: white;
        }

        .modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
            max-height: 50vh;
        }

        .modal-body h3 {
            color: var(--petron-blue);
            font-size: 14px;
            margin-top: 15px;
            margin-bottom: 8px;
        }

        .modal-body h3:first-child {
            margin-top: 0;
        }

        .modal-body p {
            font-size: 13px;
            line-height: 1.6;
            color: #555;
            margin: 0 0 12px 0;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-radius: 0 0 10px 10px;
        }

        .btn-modal {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-close {
            background-color: #f0f0f0;
            color: #555;
        }

        .btn-close:hover {
            background-color: #e0e0e0;
        }

        .btn-agree {
            background-color: var(--petron-blue);
            color: white;
        }

        .btn-agree:hover {
            background-color: #001f4d;
        }

        /* Responsive */
        @media (max-width: 600px) {
            .forgot-card {
                width: 95%;
                padding: 30px 20px;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
            
            .modal-body {
                max-height: 60vh;
            }
        }
    </style>
</head>
<body>

    <div class="forgot-card">
        <!-- Branding -->
        <img src="../assets/img/Petron Logo.png" alt="Petron logo" class="brand-logo">
        <h1 class="brand-title">Forgot Password</h1>
        <p class="brand-subtitle">Reset Your Password</p>

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

        <?php if (!$message): ?>
        <!-- Forgot Password Form -->
        <form method="POST" action="" id="forgotForm">
            <div class="form-group">
                <label for="email" style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">Email Address</label>
                <div class="input-group">
                    <span class="input-icon"><i class="fas fa-envelope"></i></span>
                    <input type="email" name="email" id="email" class="form-control" placeholder="Enter your email address" required autofocus aria-label="Email Address">
                </div>
            </div>

            
            <button type="submit" class="btn-submit" id="submitBtn">
                <div class="spinner" id="spinner"></div>
                <span id="btnText">Send Reset Link</span>
            </button>
        </form>
        <?php endif; ?>

        <!-- Secondary Links -->
        <div class="links">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>

    <div class="footer">
        <?php echo $footer_text; ?>
    </div>

    <script>
        // Simple form loading state
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('forgotForm');
            const submitBtn = document.getElementById('submitBtn');
            const spinner = document.getElementById('spinner');
            const btnText = document.getElementById('btnText');

            if (form) {
                form.addEventListener('submit', (e) => {
                    // Disable button and show loading state
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
