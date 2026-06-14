<?php
/**
 * LOGIN PAGE - Petron Station Management System
 * New Flow: Station ID + Email/Username + Password + CAPTCHA
 * NO PHONE LOGIN
 */

session_start();
ob_start();

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// Force HTTPS (except localhost)
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false && $host !== '::1') {
        header("Location: https://" . $host . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Configuration
$system_name = "Petron Station & Service Center Management System";
$current_year = date("Y");

// Generate Math CAPTCHA
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['captcha_answer'])) {
    $captcha_a = random_int(1, 12);
    $captcha_b = random_int(1, 12);
    $_SESSION['captcha_answer'] = $captcha_a + $captcha_b;
    $_SESSION['captcha_question'] = "{$captcha_a} + {$captcha_b}";
}
$captcha_question = $_SESSION['captcha_question'] ?? '? + ?';

$error = '';
$success = '';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Check if already logged in
if (isset($_SESSION['user'])) {
    $userRole = $_SESSION['user']['role'] ?? 'staff';
    $role = function_exists('role_key') ? role_key($userRole) : strtolower(trim($userRole));
    $dashboards = [
        'superadmin' => 'super_admin_dashboard.php',
        'admin' => 'admin_dashboard.php',
        'manager' => 'manager_dashboard.php',
        'staff' => 'staff_dashboard.php'
    ];
    header("Location: " . ($dashboards[$role] ?? 'staff_dashboard.php'));
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login_new.php");
    exit;
}

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $station_id = trim($_POST['station_id'] ?? '');
    $login_input = trim($_POST['login_input'] ?? '');
    $password = $_POST['password_hash'] ?? '';
    $captcha_input = trim($_POST['captcha'] ?? '');
    
    // Validation
    if (empty($station_id) || empty($login_input) || empty($password)) {
        $error = "Please fill in all fields: Station ID, Email/Username, and Password.";
        
        // Regenerate CAPTCHA
        $captcha_a = random_int(1, 12);
        $captcha_b = random_int(1, 12);
        $_SESSION['captcha_answer'] = $captcha_a + $captcha_b;
        $_SESSION['captcha_question'] = "{$captcha_a} + {$captcha_b}";
        $captcha_question = $_SESSION['captcha_question'];
        
    } elseif (empty($captcha_input) || !is_numeric($captcha_input) || (int)$captcha_input !== (int)($_SESSION['captcha_answer'] ?? -1)) {
        $error = "Incorrect CAPTCHA answer. Please try again.";
        
        // Log CAPTCHA failure
        try {
            $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
            if (!empty($tables)) {
                $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, status, ip_address, user_agent, created_at) VALUES (NULL, 'user', 'Login Failed', ?, 'users', 'Failed', ?, ?, NOW())");
                $auditStmt->execute([
                    "CAPTCHA Failed - Station: {$station_id}, Login: {$login_input}",
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            }
        } catch (Exception $e) {}
        
        // Regenerate CAPTCHA
        $captcha_a = random_int(1, 12);
        $captcha_b = random_int(1, 12);
        $_SESSION['captcha_answer'] = $captcha_a + $captcha_b;
        $_SESSION['captcha_question'] = "{$captcha_a} + {$captcha_b}";
        $captcha_question = $_SESSION['captcha_question'];
        
    } else {
        try {
            // Account lockout check
            $lockout_time = 15; // minutes
            $max_attempts = 5;
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            
            try {
                $tables = $pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchAll();
                if (!empty($tables)) {
                    $stmtLock = $pdo->prepare("
                        SELECT COUNT(*) FROM login_attempts 
                        WHERE (username = ? OR ip_address = ?) 
                          AND status = 'failed' 
                          AND attempt_time > NOW() - INTERVAL ? MINUTE
                    ");
                    $stmtLock->execute([$login_input, $ip_address, $lockout_time]);
                    if ($stmtLock->fetchColumn() >= $max_attempts) {
                        $error = "Too many failed login attempts. Your account is temporarily locked. Please try again after {$lockout_time} minutes.";
                    }
                }
            } catch (Exception $e) {}
            
            if (empty($error)) {
                // Auto-detect: Email (contains @) or Username
                $login_type = strpos($login_input, '@') !== false ? 'Email' : 'Username';
                
                // Build query
                if ($login_type === 'Email') {
                    $sql = "SELECT * FROM users WHERE email = ? AND station_id = ? AND status = 'Active' LIMIT 1";
                } else {
                    $sql = "SELECT * FROM users WHERE username = ? AND station_id = ? AND status = 'Active' LIMIT 1";
                }
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$login_input, $station_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $valid_login = false;
                
                if ($user) {
                    $status = $user['status'] ?? 'Disabled';
                    $status_lower = strtolower($status);
                    
                    if ($status_lower === 'locked') {
                        $error = "Your account is locked. Please contact the administrator.";
                    } elseif ($status_lower === 'disabled') {
                        $error = "Your account is disabled. Please contact the administrator.";
                    } elseif ($status_lower !== 'active') {
                        $error = "Your account is inactive. Please contact the administrator.";
                    } elseif (password_verify($password, $user['password_hash'] ?? $user['password_hash'] ?? '')) {
                        $valid_login = true;
                    } else {
                        $error = "Invalid password.";
                    }
                } else {
                    $error = "Invalid Station ID, Email/Username, or account not found.";
                }
                
                if ($valid_login) {
                    // SUCCESS! Log attempt and redirect
                    try {
                        $user_id = $user['user_id'] ?? $user['id'] ?? null;
                        
                        // Log success
                        $tables = $pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchAll();
                        if (!empty($tables)) {
                            $pdo->prepare("INSERT INTO login_attempts (user_id, username, ip_address, user_agent, attempt_time, status) VALUES (?, ?, ?, ?, NOW(), 'success')")
                                ->execute([$user_id, $login_input, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null]);
                            
                            // Clear failed attempts
                            $pdo->prepare("DELETE FROM login_attempts WHERE (username = ? OR ip_address = ?) AND status = 'failed'")
                                ->execute([$login_input, $_SERVER['REMOTE_ADDR']]);
                        }
                        
                        // Audit log
                        $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
                        if (!empty($tables)) {
                            $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'user', 'Login Success', ?, 'users', ?, 'Success', ?, ?, NOW())");
                            $auditStmt->execute([
                                $user_id,
                                "Successful login - Station: {$station_id}, {$login_type}: {$login_input}, Role: {$user['role']}",
                                $user_id,
                                $_SERVER['REMOTE_ADDR'] ?? null,
                                $_SERVER['HTTP_USER_AGENT'] ?? null
                            ]);
                        }
                    } catch (Exception $e) {}
                    
                    // Set session
                    $_SESSION['user'] = $user;
                    $_SESSION['user']['user_id'] = $user['user_id'] ?? $user['id'] ?? null;
                    
                    // Redirect to dashboard
                    $role = function_exists('role_key') ? role_key($user['role']) : strtolower(trim($user['role']));
                    $dashboards = [
                        'superadmin' => 'super_admin_dashboard.php',
                        'admin' => 'admin_dashboard.php',
                        'manager' => 'manager_dashboard.php',
                        'staff' => 'staff_dashboard.php'
                    ];
                    header("Location: " . ($dashboards[$role] ?? 'staff_dashboard.php'));
                    exit;
                    
                } else {
                    // FAILED! Log attempt
                    try {
                        $user_id = $user['user_id'] ?? $user['id'] ?? null;
                        
                        // Log failed attempt
                        $tables = $pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchAll();
                        if (!empty($tables)) {
                            $pdo->prepare("INSERT INTO login_attempts (user_id, username, ip_address, user_agent, attempt_time, status, failure_reason) VALUES (?, ?, ?, ?, NOW(), 'failed', ?)")
                                ->execute([$user_id, $login_input, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null, $error]);
                        }
                        
                        // Audit log
                        $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
                        if (!empty($tables)) {
                            $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'user', 'Login Failed', ?, 'users', ?, 'Failed', ?, ?, NOW())");
                            $auditStmt->execute([
                                $user_id,
                                "Failed login - Station: {$station_id}, {$login_type}: {$login_input}, Reason: {$error}",
                                $user_id,
                                $_SERVER['REMOTE_ADDR'] ?? null,
                                $_SERVER['HTTP_USER_AGENT'] ?? null
                            ]);
                        }
                    } catch (Exception $e) {}
                }
            }
            
        } catch (PDOException $e) {
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:      #002F6C;
            --blue-mid:  #003d8a;
            --blue-glow: rgba(0,80,180,.6);
            --red:       #E30613;
            --red-glow:  rgba(227,6,19,.45);
            --text:      #ffffff;
            --muted:     rgba(255,255,255,.85);
            --label:     rgba(180,210,255,.9);
            --icon:      rgba(160,200,255,.75);
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
            background: #000814;
        }

        /* 4D Animated Background */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
        }

        .bg-image {
            background: url('../assets/img/background.jpg') center/cover no-repeat;
            filter: brightness(0.6) blur(0px);
            z-index: 1;
        }

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

        .login-wrap {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 480px;
            padding: 0 20px;
        }

        .login-card {
            background: linear-gradient(160deg, rgba(0,15,45,.88) 0%, rgba(0,25,65,.92) 100%);
            backdrop-filter: blur(32px) saturate(1.8) brightness(1.1);
            border-radius: 28px;
            padding: 48px 45px 42px;
            position: relative;
            box-shadow:
                0 2px 0 rgba(255,255,255,.08) inset,
                0 -1px 0 rgba(0,0,0,.4) inset,
                0 8px 32px rgba(0,0,0,.5),
                0 32px 80px rgba(0,0,0,.6),
                0 0 0 1px rgba(255,255,255,.1),
                0 0 60px var(--blue-glow);
            animation: cardGlow 4s ease-in-out infinite alternate;
        }

        .login-card::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 30px;
            background: linear-gradient(135deg, rgba(0,100,255,.5), rgba(227,6,19,.4), rgba(0,60,180,.5));
            background-size: 300% 300%;
            animation: borderAnim 5s ease infinite;
            z-index: -1;
        }

        @keyframes borderAnim {
            0%   { background-position: 0% 50%; }
            50%  { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes cardGlow {
            from { box-shadow: 0 2px 0 rgba(255,255,255,.08) inset, 0 -1px 0 rgba(0,0,0,.4) inset, 0 8px 32px rgba(0,0,0,.5), 0 32px 80px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.1), 0 0 60px var(--blue-glow); }
            to   { box-shadow: 0 2px 0 rgba(255,255,255,.08) inset, 0 -1px 0 rgba(0,0,0,.4) inset, 0 8px 32px rgba(0,0,0,.5), 0 32px 80px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.1), 0 0 80px var(--red-glow); }
        }

        .brand { text-align: center; margin-bottom: 28px; }
        .brand-logo {
            width: 75px; height: auto;
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
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: rgba(180,210,255,.9);
            text-shadow: 0 0 12px rgba(100,160,255,.4);
        }

        .alert-error {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(227,6,19,.12);
            border: 1px solid rgba(227,6,19,.35);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 20px;
            font-size: 12.5px;
            color: #ff8080;
            animation: shake .35s ease;
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            25%      { transform: translateX(-6px); }
            75%      { transform: translateX(6px); }
        }
        .alert-error i { flex-shrink: 0; font-size: 14px; }

        .form-group { margin-bottom: 16px; }

        .field-label {
            display: block;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            color: var(--label);
            margin-bottom: 7px;
            text-shadow: 0 1px 4px rgba(0,0,0,.5);
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--icon);
            font-size: 15px;
            pointer-events: none;
            z-index: 2;
        }

        .form-input {
            width: 100%;
            padding: 13px 15px 13px 42px;
            background: rgba(255,255,255,.08);
            border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all .25s ease;
            backdrop-filter: blur(8px);
        }

        .form-input:focus {
            outline: none;
            background: rgba(255,255,255,.12);
            border-color: rgba(96,165,250,.5);
            box-shadow: 0 0 0 3px rgba(96,165,250,.15), 0 4px 12px rgba(0,0,0,.3);
        }

        .form-input::placeholder {
            color: rgba(255,255,255,.4);
        }

        .captcha-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .captcha-question {
            flex-shrink: 0;
            background: rgba(255,255,255,.1);
            border: 1.5px solid rgba(255,255,255,.2);
            border-radius: 8px;
            padding: 11px 16px;
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            text-align: center;
            min-width: 85px;
            letter-spacing: 1px;
        }

        .captcha-input {
            flex: 1;
            padding: 13px 15px;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-mid) 100%);
            border: none;
            border-radius: 10px;
            color: var(--text);
            font-size: 14.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            cursor: pointer;
            transition: all .3s ease;
            box-shadow: 0 4px 12px rgba(0,47,108,.4);
            margin-top: 22px;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, var(--blue-mid) 0%, #0050b8 100%);
            box-shadow: 0 6px 20px rgba(0,80,180,.5);
            transform: translateY(-2px);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .footer {
            text-align: center;
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,.1);
        }

        .footer-link {
            color: rgba(96,165,250,.85);
            text-decoration: none;
            font-size: 12.5px;
            transition: color .25s ease;
        }

        .footer-link:hover {
            color: rgba(147,197,253,1);
            text-decoration: underline;
        }

        .copyright {
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            color: rgba(255,255,255,.5);
        }
    </style>
</head>
<body>
    <div class="bg-layer bg-image"></div>
    <div class="bg-layer bg-gradient"></div>

    <div class="login-wrap">
        <div class="login-card">
            <div class="brand">
                <img src="<?php echo '../' . get_system_logo_url(isset($station_id) ? (int)$station_id : (isset($user['station_id']) ? (int)$user['station_id'] : 0)); ?>" alt="Petron Logo" class="brand-logo">
                <span class="brand-tagline">Station Management</span>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label class="field-label">Station ID</label>
                    <div class="input-wrapper">
                        <i class="fas fa-gas-pump input-icon"></i>
                        <input type="number" name="station_id" class="form-input" 
                               placeholder="Enter Station ID" 
                               value="<?php echo htmlspecialchars($_POST['station_id'] ?? ''); ?>" 
                               required min="1">
                    </div>
                </div>

                <div class="form-group">
                    <label class="field-label">Email or Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="login_input" class="form-input" 
                               placeholder="Enter Email or Username" 
                               value="<?php echo htmlspecialchars($_POST['login_input'] ?? ''); ?>" 
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="field-label">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password_hash" class="form-input" 
                               placeholder="Enter Password" 
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="field-label">Security Check</label>
                    <div class="captcha-group">
                        <div class="captcha-question"><?php echo htmlspecialchars($captcha_question); ?> = ?</div>
                        <input type="number" name="captcha" class="form-input captcha-input" 
                               placeholder="Answer" 
                               required>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>

            <div class="footer">
                <a href="forgot_password.php" class="footer-link">
                    <i class="fas fa-key"></i> Forgot Password?
                </a>
            </div>
        </div>

        <div class="copyright">
            &copy; <?php echo $current_year; ?> <?php echo htmlspecialchars($system_name); ?>. All Rights Reserved.
        </div>
    </div>
</body>
</html>
