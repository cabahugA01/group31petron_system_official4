<?php
ob_start();
session_start();

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');
    
    if (empty($otp)) {
        $error = "Please enter the 6-digit OTP.";
    } elseif (strlen($otp) !== 6 || !is_numeric($otp)) {
        $error = "Please enter a valid 6-digit OTP.";
    } else {
        try {
            // Verify token against existing password_reset_tokens table
            $stmt = $pdo->prepare("
                SELECT prt.user_id, prt.token, prt.expires_at, prt.is_used, prt.used_at,
                       u.username, u.email 
                FROM password_reset_tokens prt
                JOIN users u ON prt.user_id = u.id
                WHERE prt.token = ? AND prt.token_type = 'reset' AND u.status = 'active' AND u.is_deleted = 0 AND u.email = ?
                LIMIT 1
            ");
            $stmt->execute([$otp, $email]);
            $token_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$token_data) {
                $error = "Invalid OTP. Please check your email and try again.";
            } elseif (strtotime($token_data['expires_at']) < time()) {
                $error = "OTP has expired. Please request a new password reset.";
            } elseif ($token_data['is_used'] == 1 && $token_data['used_at'] !== null) {
                $error = "OTP has already been used. Please request a new password reset.";
            } else {
                // OTP is valid! Redirect to reset form
                header("Location: forgot_password_reset.php?token=" . urlencode($otp) . "&email=" . urlencode($email));
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

        .verify-card {
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
            padding: 12px 15px 12px 45px;
            font-size: 20px;
            letter-spacing: 4px;
            font-weight: bold;
            text-align: center;
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
        
        .info-message {
            background-color: #ebf5ff;
            color: #002F6C;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #cce5ff;
            text-align: left;
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
            0%   { color: var(--petron-blue); }
            25%  { color: var(--petron-blue); }
            50%  { color: var(--petron-red);  }
            75%  { color: var(--petron-red);  }
            100% { color: var(--petron-blue); }
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
    </style>
</head>
<body>

    <div class="verify-card">
        <!-- Branding -->
        <img src="../assets/img/Petron Logo.png" alt="Petron logo" class="brand-logo">
        <h1 class="brand-title">Verify OTP</h1>
        <p class="brand-subtitle">Enter the 6-digit code</p>

        <!-- Error Message -->
        <?php if ($error): ?>
            <div class="error-banner" role="alert">
                <span><i class="fas fa-exclamation-triangle"></i></span>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if (empty($error) && !empty($email)): ?>
            <div class="info-message">
                If the email <strong><?php echo htmlspecialchars($email); ?></strong> is registered with us, we sent an OTP. Please check your inbox and spam folder.
            </div>
        <?php endif; ?>

        <!-- OTP Form -->
        <form method="POST" action="" id="verifyForm">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <div class="form-group">
                <label for="otp" style="display: block; margin-bottom: 5px; font-weight: 600; color: #333;">Enter OTP</label>
                <div class="input-group">
                    <span class="input-icon"><i class="fas fa-key"></i></span>
                    <input type="text" name="otp" id="otp" class="form-control" placeholder="123456" maxlength="6" required autofocus autocomplete="off">
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <div class="spinner" id="spinner"></div>
                <span id="btnText">Verify OTP</span>
            </button>
        </form>

        <!-- Secondary Links -->
        <div class="links">
            <a href="forgot_password.php"><i class="fas fa-redo"></i> Request New OTP</a>
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>

    <div class="footer">
        &copy; <?php echo date('Y'); ?> Petron Station &amp; Service Center Management System. All Rights Reserved.
    </div>

    <script>
        // Loading State
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
