<?php
// session_start() MUST come before ob_start() — ob_end_clean() would discard the Set-Cookie header
session_start();
ob_start(); // Buffer output to prevent "headers already sent" errors

// Include database connection
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// Configuration variables
$system_name = "Petron Station & Service Center Management System";
$current_year = date("Y");
$footer_text = "&copy; {$current_year} {$system_name}. All Rights Reserved.";

$error = '';

// Check for login error from auth/login.php
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 1. Check if already logged in
if (isset($_SESSION['user'])) {
    $userRole = $_SESSION['user']['role'] ?? 'staff';
    $role = function_exists('role_key') ? role_key($userRole) : strtolower(trim($userRole));
    if ($role === 'superadmin') {
        $redirect_url = 'super_admin_dashboard.php';
    } elseif ($role === 'admin') {
        $redirect_url = 'admin_dashboard.php';
    } elseif ($role === 'manager') {
        $redirect_url = 'manager_dashboard.php';
    } else {
        $redirect_url = 'staff_dashboard.php';
    }
    header("Location: $redirect_url");
    exit;
}

// Handle logout if requested
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 2. Handle Login Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $terms_agreed = isset($_POST['terms']);

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } elseif (!$terms_agreed) {
        $error = "You must agree to the Terms of Use before logging in.";
    } else {
        try {
            // Prepare statement to prevent SQL injection
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Verify password using secure hash verification
                $valid_login = false;
                if ($user) {
                    // Check account status
                    if (($user['status'] ?? 'active') !== 'active') {
                        $error = "Your account is inactive. Please contact the administrator.";
                    }
                    // Verify password hash
                    elseif (password_verify($password, $user['password'])) {
                        $valid_login = true;
                    }
                }

            if ($valid_login) {

                // Update last login
                try {
                    $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$user['id']]);
                } catch (Exception $e) { /* ignore */ }

                // Normal login success session
                unset($user['password']);
                $_SESSION['user'] = $user;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];

                try {
                    // Check if activity_logs table exists before inserting
                    $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Login', 'User logged in', ?)");
                        $logStmt->execute([$user['id'], $_SERVER['REMOTE_ADDR']]);
                    }

                    // Check if audit_logs table exists before inserting
                    $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $login_name   = $user['name'] ?? $user['username'] ?? 'Unknown';
                        $login_role   = ucfirst(strtolower($user['role'] ?? 'staff'));
                        $login_detail = "{$login_name} ({$login_role}) logged in";
                        $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'user', 'Login', ?, 'users', ?, 'Success', ?, ?, NOW())");
                        $auditStmt->execute([
                            $user['id'],
                            $login_detail,
                            $user['id'],
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ]);
                    }
                } catch (Exception $e) { /* Fail silently if logs table missing */ }

                // Auto Clock In for staff roles on login
                $role = role_key($user['role'] ?? '');
                $staff_roles = ['staff'];
                if (in_array($role, $staff_roles)) {
                    try {
                        $station_id = $user['station_id'] ?? null;
                        // Only clock in if not already clocked in
                        $check = $pdo->prepare("SELECT id FROM labor_sessions WHERE user_id = ? AND end_time IS NULL");
                        $check->execute([$user['id']]);
                        if (!$check->fetch() && $station_id) {
                            // Determine current shift
                            $sp = $pdo->prepare(
                                "SELECT shift_key, shift_name FROM shift_periods
                                 WHERE is_active = 1 AND start_time <= TIME(NOW()) AND end_time >= TIME(NOW())
                                 ORDER BY sort_order ASC LIMIT 1"
                            );
                            $sp->execute();
                            $shift = $sp->fetch(PDO::FETCH_ASSOC);
                            if (!$shift) {
                                // Fallback: use the last active shift
                                $sp2 = $pdo->query(
                                    "SELECT shift_key, shift_name FROM shift_periods
                                     WHERE is_active = 1 ORDER BY sort_order DESC LIMIT 1"
                                );
                                $shift = $sp2 ? $sp2->fetch(PDO::FETCH_ASSOC) : null;
                            }
                            if (!$shift) {
                                $shift = ['shift_key' => 'first', 'shift_name' => 'First Shift'];
                            }
                            $pdo->prepare(
                                "INSERT INTO labor_sessions (user_id, station_id, start_time, shift_period, shift_name)
                                 VALUES (?, ?, NOW(), ?, ?)"
                            )->execute([$user['id'], $station_id, $shift['shift_key'], $shift['shift_name']]);
                            // Log the auto clock-in
                            $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
                            if (!empty($tables)) {
                                $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Clock In', ?, ?)")
                                    ->execute([$user['id'], "Auto clock-in on login - Station {$station_id} - {$shift['shift_name']}", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                            }
                        }
                    } catch (Exception $e) { /* Fail silently, do not block login */ }
                }

                // RBAC Redirect Logic
                if ($role === 'superadmin') {
                    header("Location: super_admin_dashboard.php");
                } elseif ($role === 'admin') {
                    header("Location: admin_dashboard.php");
                } elseif ($role === 'manager') {
                    header("Location: manager_dashboard.php");
                } else {
                    header("Location: staff_dashboard.php");
                }
                exit;
            } else {
                // Audit Logging: Failed Attempt
                try {
                    // Check if activity_logs table exists before inserting
                    $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Login Failed', ?, ?)");
                        $logStmt->execute([($user['id'] ?? 0), "Failed login attempt for username: $username", $_SERVER['REMOTE_ADDR']]);
                    }

                    // Check if audit_logs table exists before inserting
                    $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $fail_role   = ucfirst(strtolower($user['role'] ?? 'unknown'));
                        $fail_detail = "Failed login attempt — username: {$username}" . ($fail_role !== 'Unknown' ? " ({$fail_role})" : '');
                        $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'user', 'Login Failed', ?, 'users', ?, 'Failed', ?, ?, NOW())");
                        $auditStmt->execute([
                            $user['id'] ?? null,
                            $fail_detail,
                            $user['id'] ?? null,
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ]);
                    }
                } catch (Exception $e) { /* Fail silently */ }

                if (empty($error)) {
                    $error = "Invalid username or password.";
                }
            }
        } catch (PDOException $e) {
            // Log error internally, show generic message to user
            error_log($e->getMessage());
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
    <title>Login | Petron Management System</title>
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

        .login-card {
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
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .brand-subtitle {
            color: #666;
            font-size: 14px;
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
            padding: 12px 15px 12px 45px; /* Space for left icon, right padding for eye */
            font-size: 16px;
            border: 1px solid #ddd;
            border-radius: 5px;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }

        /* Special padding for username field (only left icon) */
        .form-control.username-field {
            padding-right: 15px;
        }

        /* Special padding for password field (both icons) */
        .form-control.password-field {
            padding-right: 40px; /* Space for eye icon at right edge */
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
        .btn-login {
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

        .btn-login:hover {
            background-color: #001f4d;
        }

        .btn-login:disabled {
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

    <div class="login-card">
        <!-- Branding -->
        <img src="../assets/img/Petron Logo.png" alt="Petron logo" class="brand-logo">

        <!-- Error Message -->
        <?php if ($error): ?>
            <div class="error-banner" role="alert">
                <span><i class="fas fa-exclamation-triangle"></i></span>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="" id="loginForm">
            <div class="form-group">
                <div class="input-group">
                    <span class="input-icon"><i class="fas fa-user"></i></span>
                    <input type="text" name="username" class="form-control username-field" placeholder="Username" required autofocus aria-label="Username" autocomplete="username">
                </div>
            </div>

            <div class="form-group">
                <div class="input-group">
                    <span class="input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" name="password" id="password" class="form-control password-field" placeholder="Password" required aria-label="Password" autocomplete="current-password">
                    <button type="button" class="toggle-password" id="toggleBtn" aria-label="Show password"></button>
                </div>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" name="terms" id="terms" aria-label="Agree to Terms of Use">
                <label for="terms">I agree to the <a href="#" style="color: var(--petron-blue); text-decoration: none; font-weight: 500;">Terms of Use</a></label>
            </div>

            <button type="submit" class="btn-login" id="submitBtn">
                <div class="spinner" id="spinner"></div>
                <span id="btnText">Login</span>
            </button>
        </form>

        <!-- Secondary Links -->
        <div class="links">
            <a href="forgot_password.php">Forgot Password?</a>
        </div>
    </div>

    <div class="footer">
        <?php echo $footer_text; ?>
    </div>

    <script>
        // Toggle Password Visibility
        const toggleBtn = document.getElementById('toggleBtn');
        const passwordInput = document.getElementById('password');

        toggleBtn.addEventListener('click', () => {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            toggleBtn.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
        });

        // Terms and Conditions Modal and Login Button Management
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('termsModal');
            const termsCheckbox = document.getElementById('terms');
            const termsLink = document.querySelector('.checkbox-group a');
            const modalClose = document.getElementById('modalClose');
            const btnClose = document.getElementById('btnClose');
            const btnAgree = document.getElementById('btnAgree');
            const submitBtn = document.getElementById('submitBtn');
            const form = document.getElementById('loginForm');
            const spinner = document.getElementById('spinner');
            const btnText = document.getElementById('btnText');

            // Check if all required elements exist
            if (!termsCheckbox || !submitBtn || !form) {
                console.error('Required form elements not found');
                return;
            }

            // Initially disable login button
            submitBtn.disabled = true;

            // Check terms checkbox state
            function checkLoginButtonState() {
                if (termsCheckbox.checked) {
                    submitBtn.disabled = false;
                } else {
                    submitBtn.disabled = true;
                }
            }

            // Add event listener to terms checkbox
            termsCheckbox.addEventListener('change', checkLoginButtonState);

            // Open modal function
            function openModal() {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }

            // Close modal function
            function closeModal() {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }

            // Open modal when clicking Terms link
            if (termsLink) {
                termsLink.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (modal) {
                        openModal();
                    }
                });
            }

            // Open modal when checking checkbox
            if (termsCheckbox) {
                termsCheckbox.addEventListener('click', (e) => {
                    if (!e.target.checked) {
                        e.target.checked = false;
                    } else {
                        e.preventDefault();
                        if (modal) {
                            openModal();
                        }
                    }
                });
            }

            // Close modal when clicking X
            if (modalClose && modal) {
                modalClose.addEventListener('click', closeModal);
            }

            // Close modal when clicking Close button
            if (btnClose && modal) {
                btnClose.addEventListener('click', closeModal);
            }

            // Agree and close modal
            if (btnAgree && modal) {
                btnAgree.addEventListener('click', () => {
                    if (termsCheckbox) {
                        termsCheckbox.checked = true;
                        checkLoginButtonState(); // Enable the login button
                    }
                    closeModal();
                });
            }

            // Close modal when clicking outside
            window.addEventListener('click', (e) => {
                if (modal && e.target === modal) {
                    closeModal();
                }
            });

            // Close modal on Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal && modal.style.display === 'block') {
                    closeModal();
                }
            });

            // Form submission validation
            form.addEventListener('submit', (e) => {
                // Double-check terms agreement before submission
                if (!termsCheckbox.checked) {
                    e.preventDefault();
                    alert('You must agree to the Terms of Use before logging in.');
                    return false;
                }
                
                // Disable button and show spinner
                submitBtn.disabled = true;
                if (spinner) {
                    spinner.style.display = 'block';
                }
                if (btnText) {
                    btnText.textContent = 'Authenticating...';
                }
            });
        });
    </script>

    <!-- Terms and Conditions Modal -->
    <div id="termsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-file-contract"></i> Terms and Conditions</h2>
                <span class="modal-close" id="modalClose">&times;</span>
            </div>
            <div class="modal-body">
                <h3>1. Acceptance of Terms</h3>
                <p>By accessing and using the Petron Management System, you agree to comply with these Terms and Conditions. If you do not agree with any part of these terms, please do not use this system.</p>
                
                <h3>2. User Responsibilities</h3>
                <p>You are responsible for maintaining the confidentiality of your account credentials and for all activities that occur under your account. You must notify the administrator immediately of any unauthorized use of your account.</p>
                
                <h3>3. System Usage</h3>
                <p>This system is intended for authorized Petron personnel only. Unauthorized access, use, or distribution of data is strictly prohibited and may result in disciplinary action and legal consequences.</p>
                
                <h3>4. Data Privacy</h3>
                <p>All data entered into the system is subject to our privacy policy. Personal information will be handled in accordance with applicable data protection laws.</p>
                
                <h3>5. Intellectual Property</h3>
                <p>All content, features, and functionality of this system are the exclusive property of Petron Corporation and are protected by copyright and trademark laws.</p>
                
                <h3>6. Modifications</h3>
                <p>We reserve the right to modify these terms at any time. Continued use of the system after changes constitutes acceptance of the new terms.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal btn-close" id="btnClose">Close</button>
                <button type="button" class="btn-modal btn-agree" id="btnAgree">I Agree</button>
            </div>
        </div>
    </div>

</body>
</html>
