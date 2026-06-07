<?php
// session_start() MUST come before ob_start() — ob_end_clean() would discard the Set-Cookie header
session_start();
ob_start(); // Buffer output to prevent "headers already sent" errors

// Include database connection
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// 0. Force HTTPS for security (except local development)
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    // Do not force HTTPS on localhost
    if (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false && $host !== '::1') {
        header("Location: https://" . $host . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Configuration variables
$system_name = "Petron Station & Service Center Management System";
$current_year = date("Y");
$footer_text = "&copy; {$current_year} {$system_name}. All Rights Reserved.";

// 2. Generate Math CAPTCHA (regenerate on every load, preserved across POST failure)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['captcha_answer'])) {
    $captcha_a = random_int(1, 12);
    $captcha_b = random_int(1, 12);
    $_SESSION['captcha_answer'] = $captcha_a + $captcha_b;
    $_SESSION['captcha_question'] = "{$captcha_a} + {$captcha_b}";
}
$captcha_question = $_SESSION['captcha_question'] ?? '? + ?';

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
    $login_input = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $captcha_input = trim($_POST['captcha'] ?? '');

    if (empty($login_input) || empty($password)) {
        $error = "Please enter both Email/Phone/Username and password.";
        // Regenerate CAPTCHA on any error
        $captcha_a = random_int(1, 12); $captcha_b = random_int(1, 12);
        $_SESSION['captcha_answer'] = $captcha_a + $captcha_b;
        $_SESSION['captcha_question'] = "{$captcha_a} + {$captcha_b}";
        $captcha_question = $_SESSION['captcha_question'];
    } elseif (empty($captcha_input) || !is_numeric($captcha_input) || (int)$captcha_input !== (int)($_SESSION['captcha_answer'] ?? -1)) {
        $error = "Incorrect CAPTCHA answer. Please try again.";
        
        // Audit Logging for CAPTCHA Failure
        try {
            $check_user = null;
            if (!empty($login_input)) {
                try {
                    $_c = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                    $_u = in_array('user_id',     $_c) ? 'user_id'     : 'id';
                    $_p = in_array('phone_number', $_c) ? 'phone_number' : 'phone';
                    $check_stmt = $pdo->prepare("SELECT `{$_u}` AS user_id, role FROM users WHERE username = ? OR email = ? OR `{$_p}` = ? LIMIT 1");
                    $check_stmt->execute([$login_input, $login_input, $login_input]);
                    $check_user = $check_stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) { $check_user = null; }
            }
            $target_uid  = $check_user ? $check_user['user_id'] : null;
            $target_role = $check_user ? $check_user['role']    : 'guest';

            // Log to login_attempts for lockout policy
            $tables = $pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchAll();
            if (!empty($tables)) {
                $logAttempts = $pdo->prepare("INSERT INTO login_attempts (user_id, username, ip_address, user_agent, attempt_time, status, failure_reason) VALUES (?, ?, ?, ?, NOW(), 'failed', 'CAPTCHA verification failed.')");
                $logAttempts->execute([$target_uid, $login_input, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null]);
            }

            // Log to activity_logs
            $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
            if (!empty($tables)) {
                $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Login Failed', ?, ?)");
                $logStmt->execute([$target_uid ?? 0, "Failed login due to incorrect CAPTCHA for: $login_input", $_SERVER['REMOTE_ADDR']]);
            }

            // Log to audit_logs
            $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
            if (!empty($tables)) {
                $fail_role = ucfirst(strtolower($target_role));
                $fail_detail = "Failed login — Incorrect CAPTCHA answer for login ID: {$login_input}" . ($fail_role !== 'Guest' ? " ({$fail_role})" : '');
                $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'user', 'CAPTCHA Failed', ?, 'users', ?, 'Failed', ?, ?, NOW())");
                $auditStmt->execute([
                    $target_uid,
                    $fail_detail,
                    $target_uid,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            }
        } catch (Exception $e) {}

        // Always regenerate CAPTCHA after a failed attempt
        $captcha_a = random_int(1, 12); $captcha_b = random_int(1, 12);
        $_SESSION['captcha_answer'] = $captcha_a + $captcha_b;
        $_SESSION['captcha_question'] = "{$captcha_a} + {$captcha_b}";
        $captcha_question = $_SESSION['captcha_question'];
    } else {
        try {
            // --- Account Lockout Policy ---
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
            } catch (Exception $e) { /* Ignore if table missing */ }

            if (empty($error)) {
                // Auto-detect credential type:
                // Input contains @ -> Email login.
                // Input is exactly 11 digits -> Phone login.
                // Else -> Username login.
            // ── Auto-detect schema column names ──────────────────────
            $s_cols  = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $s_uid   = in_array('user_id',      $s_cols) ? 'user_id'      : 'id';
            $s_phone = in_array('phone_number',  $s_cols) ? 'phone_number'  : 'phone';
            $s_pass  = in_array('password_hash', $s_cols) ? 'password_hash' : 'password';
            $s_stat  = in_array('Active', $pdo->query("SELECT DISTINCT status FROM users LIMIT 10")->fetchAll(PDO::FETCH_COLUMN)) ? 'Active' : 'active';

            $login_type = 'Username';
            $sql = "SELECT *, `{$s_uid}` AS _uid FROM users WHERE username = ? AND status = '{$s_stat}' LIMIT 1";

            if (strpos($login_input, '@') !== false) {
                $login_type = 'Email';
                $sql = "SELECT *, `{$s_uid}` AS _uid FROM users WHERE email = ? AND status = '{$s_stat}' LIMIT 1";
            } elseif (preg_match('/^\d{10,13}$/', $login_input)) {
                $login_type = 'Phone';
                $sql = "SELECT *, `{$s_uid}` AS _uid FROM users WHERE `{$s_phone}` = ? AND status = '{$s_stat}' LIMIT 1";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$login_input]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $valid_login = false;
            if ($user) {
                // Use _uid alias for the actual PK value
                $user['user_id'] = $user['_uid'] ?? $user['user_id'] ?? $user['id'] ?? null;
                $status = $user['status'] ?? 'Disabled';
                $status_lower = strtolower($status);
                if ($status_lower === 'locked') {
                    $error = "Your account is locked due to too many failed attempts. Please contact the administrator.";
                } elseif ($status_lower === 'disabled') {
                    $error = "Your account is disabled. Please contact the administrator.";
                } elseif ($status_lower !== 'active') {
                    $error = "Your account is inactive. Please contact the administrator.";
                } elseif (password_verify($password, $user[$s_pass])) {
                    $valid_login = true;
                }
            }

            if ($valid_login) {
                // Successful Password Verification - Log in directly (NO OTP)
                try {
                    $tables = $pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchAll();
                    if (!empty($tables)) {
                        $pdo->prepare("INSERT INTO login_attempts (user_id, username, ip_address, user_agent, attempt_time, status) VALUES (?, ?, ?, ?, NOW(), 'success')")
                            ->execute([$user['user_id'], $login_input, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null]);
                        $pdo->prepare("DELETE FROM login_attempts WHERE (username = ? OR ip_address = ?) AND status = 'failed'")
                            ->execute([$login_input, $_SERVER['REMOTE_ADDR']]);
                    }
                } catch (Exception $e) {}

                // Direct login - Set session and redirect to dashboard
                unset($user[$s_pass]); // Remove password from session
                $_SESSION['user']    = $user;
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role']    = $user['role'];

                // Update last login timestamp
                try {
                    $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE `{$s_uid}` = ?")->execute([$user['user_id']]);
                } catch (Exception $e) { /* ignore */ }

                // Activity logging
                try {
                    $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Login', ?, ?)");
                        $logStmt->execute([$user['user_id'], "User logged in via {$login_type}", $_SERVER['REMOTE_ADDR']]);
                    }

                    $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $login_name   = $user['first_name'] ?? $user['username'] ?? 'Unknown';
                        $login_role   = ucfirst(strtolower($user['role'] ?? 'staff'));
                        $login_detail = "{$login_name} ({$login_role}) logged in via {$login_type}";
                        $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'user', 'Login', ?, 'users', ?, 'Success', ?, ?, NOW())");
                        $auditStmt->execute([
                            $user['user_id'],
                            $login_detail,
                            $user['user_id'],
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
                        $check->execute([$user['user_id']]);
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
                                $sp2   = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order DESC LIMIT 1");
                                $shift = $sp2 ? $sp2->fetch(PDO::FETCH_ASSOC) : null;
                            }
                            if (!$shift) $shift = ['shift_key' => 'first', 'shift_name' => 'First Shift'];

                            $pdo->prepare(
                                "INSERT INTO labor_sessions (user_id, station_id, start_time, shift_period, shift_name)
                                 VALUES (?, ?, NOW(), ?, ?)"
                            )->execute([$user['user_id'], $station_id, $shift['shift_key'], $shift['shift_name']]);

                            $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
                            if (!empty($tables)) {
                                $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Clock In', ?, ?)")
                                    ->execute([$user['user_id'], "Auto clock-in on login - Station {$station_id} - {$shift['shift_name']}", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
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
                    // Log to login_attempts for lockout policy
                    $tables = $pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchAll();
                    if (!empty($tables)) {
                        $logAttempts = $pdo->prepare("INSERT INTO login_attempts (user_id, username, ip_address, user_agent, attempt_time, status, failure_reason) VALUES (?, ?, ?, ?, NOW(), 'failed', 'Invalid credentials or password.')");
                        $logAttempts->execute([($user['user_id'] ?? null), $login_input, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? null]);
                    }

                    $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Login Failed', ?, ?)");
                        $logStmt->execute([($user['user_id'] ?? null), "Failed login attempt for login ID: $login_input via {$login_type}", $_SERVER['REMOTE_ADDR']]);
                    }

                    $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $fail_role   = ucfirst(strtolower($user['role'] ?? 'unknown'));
                        $fail_detail = "Failed login attempt — login ID: {$login_input} via {$login_type}" . ($fail_role !== 'Unknown' ? " ({$fail_role})" : '');
                        $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'user', 'Login Failed', ?, 'users', ?, 'Failed', ?, ?, NOW())");
                        $auditStmt->execute([
                            $user['user_id'] ?? null,
                            $fail_detail,
                            $user['user_id'] ?? null,
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ]);
                    }
                } catch (Exception $e) { /* Fail silently */ }

                if (empty($error)) {
                    $error = "Invalid credentials or password.";
                }
            }
            } // End of if (empty($error)) wrapper for lockout
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

        /* 4D Animated Background Layers */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
        }

        /* Base image layer with blur and overlay */
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

        /* Generate multiple particle sizes */
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
        .particle:nth-child(9) { 
            width: 4px; height: 4px; 
            left: 75%; top: 40%; 
            animation-duration: 10.5s; 
            animation-delay: 2.2s;
            box-shadow: 0 0 20px rgba(96, 165, 250, 0.5);
        }
        .particle:nth-child(10) { 
            width: 6px; height: 6px; 
            left: 40%; top: 65%; 
            animation-duration: 12.5s; 
            animation-delay: 0.8s;
            box-shadow: 0 0 26px rgba(227, 6, 19, 0.5);
            background: radial-gradient(circle, rgba(227, 6, 19, 0.7), transparent);
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

        .orb-3 {
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(0, 80, 180, 0.35), transparent);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation-duration: 12s;
            animation-delay: 2s;
        }

        @keyframes orbFloat {
            0% {
                transform: translate(0, 0) scale(1);
            }
            100% {
                transform: translate(20px, -20px) scale(1.1);
            }
        }

        /* Scanlines effect */
        .bg-scanlines {
            background: repeating-linear-gradient(
                0deg,
                rgba(0, 0, 0, 0.05) 0px,
                rgba(0, 0, 0, 0.05) 1px,
                transparent 1px,
                transparent 2px
            );
            z-index: 5;
            pointer-events: none;
            opacity: 0.15;
        }

        /* Grid overlay */
        .bg-grid {
            background-image: 
                linear-gradient(rgba(96, 165, 250, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(96, 165, 250, 0.02) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: 6;
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

        /* Light rays */
        .bg-rays {
            z-index: 7;
            pointer-events: none;
            overflow: hidden;
        }

        .ray {
            position: absolute;
            width: 2px;
            height: 100%;
            background: linear-gradient(
                to bottom,
                transparent 0%,
                rgba(96, 165, 250, 0.1) 10%,
                rgba(96, 165, 250, 0.2) 50%,
                rgba(96, 165, 250, 0.1) 90%,
                transparent 100%
            );
            animation: rayShine 3s ease-in-out infinite;
            opacity: 0;
        }

        .ray:nth-child(1) {
            left: 20%;
            animation-delay: 0s;
        }

        .ray:nth-child(2) {
            left: 50%;
            animation-delay: 1s;
        }

        .ray:nth-child(3) {
            left: 80%;
            animation-delay: 2s;
        }

        @keyframes rayShine {
            0%, 100% {
                opacity: 0;
                transform: translateY(-100%);
            }
            50% {
                opacity: 1;
                transform: translateY(0);
            }
        }


        .login-wrap {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 520px;
            padding: 0 20px;
        }

        .login-card {
            background: linear-gradient(160deg, rgba(0,15,45,.88) 0%, rgba(0,25,65,.92) 100%);
            backdrop-filter: blur(32px) saturate(1.8) brightness(1.1);
            -webkit-backdrop-filter: blur(32px) saturate(1.8) brightness(1.1);
            border-radius: 28px;
            padding: 54px 52px 46px;
            position: relative;
            /* Layered 4D depth shadows */
            box-shadow:
                0 2px 0 rgba(255,255,255,.08) inset,
                0 -1px 0 rgba(0,0,0,.4) inset,
                0 8px 32px rgba(0,0,0,.5),
                0 32px 80px rgba(0,0,0,.6),
                0 0 0 1px rgba(255,255,255,.1),
                0 0 60px var(--blue-glow);
            animation: cardGlow 4s ease-in-out infinite alternate;
        }
        /* Animated gradient border */
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
        /* Top shine streak */
        .login-card::after {
            content: '';
            position: absolute;
            top: 0; left: 10%; right: 10%;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.35), transparent);
            border-radius: 50%;
        }
        @keyframes cardGlow {
            from { box-shadow: 0 2px 0 rgba(255,255,255,.08) inset, 0 -1px 0 rgba(0,0,0,.4) inset, 0 8px 32px rgba(0,0,0,.5), 0 32px 80px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.1), 0 0 60px var(--blue-glow); }
            to   { box-shadow: 0 2px 0 rgba(255,255,255,.08) inset, 0 -1px 0 rgba(0,0,0,.4) inset, 0 8px 32px rgba(0,0,0,.5), 0 32px 80px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.1), 0 0 80px var(--red-glow); }
        }

        .brand { text-align: center; margin-bottom: 30px; }
        .brand-logo {
            width: 88px; height: auto;
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
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2.8px;
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
            margin-bottom: 22px;
            font-size: 13px;
            color: #ff8080;
            animation: shake .35s ease;
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            25%      { transform: translateX(-6px); }
            75%      { transform: translateX(6px); }
        }
        .alert-error i { flex-shrink: 0; font-size: 15px; }

        .form-group { margin-bottom: 18px; }

        .field-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--label);
            margin-bottom: 8px;
            text-shadow: 0 1px 4px rgba(0,0,0,.5);
        }

        /* type-badge hidden — auto-detection runs silently in the background */
        .type-badge { display: none !important; }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
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
            padding: 14px 46px;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 500;
            color: #ffffff;
            background: rgba(255,255,255,.10);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 13px;
            outline: none;
            transition: border-color .25s, box-shadow .25s, background .25s;
            caret-color: #93c5fd;
            text-shadow: 0 1px 3px rgba(0,0,0,.4);
        }
        .field-input.no-right { padding-right: 18px; }
        .field-input::placeholder { color: rgba(200,220,255,.45); font-weight: 400; }
        .field-input:focus {
            background: rgba(255,255,255,.15);
            border-color: rgba(96,165,250,.75);
            box-shadow: 0 0 0 3px rgba(96,165,250,.15), 0 0 24px rgba(0,80,200,.35);
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

        /* Hide browser native password reveal eye (Edge/IE/Chrome) */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear { display: none !important; }
        input[type="password"]::-webkit-contacts-auto-fill-button,
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="password"]::-webkit-strong-password-auto-fill-button {
            display: none !important; visibility: hidden; pointer-events: none;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
        }
        .checkbox-group input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 18px; height: 18px;
            border: 2px solid rgba(255,255,255,.22);
            border-radius: 5px;
            background: rgba(255,255,255,.06);
            cursor: pointer;
            flex-shrink: 0;
            position: relative;
            transition: border-color .2s, background .2s;
        }
        .checkbox-group input[type="checkbox"]:checked {
            background: var(--blue-mid);
            border-color: #60a5fa;
        }
        .checkbox-group input[type="checkbox"]:checked::after {
            content: '';
            position: absolute;
            top: 2px; left: 5px;
            width: 5px; height: 9px;
            border: 2px solid #fff;
            border-top: none; border-left: none;
            transform: rotate(45deg);
        }
        .checkbox-group label {
            font-size: 13px;
            color: rgba(210,230,255,.9);
            cursor: pointer;
            line-height: 1.4;
            text-shadow: 0 1px 3px rgba(0,0,0,.5);
        }
        .checkbox-group label a {
            color: #93c5fd;
            text-decoration: none;
            font-weight: 700;
        }
        .checkbox-group label a:hover { color: #bfdbfe; text-decoration: underline; }

        .btn-login {
            width: 100%;
            padding: 14px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .4px;
            color: #fff;
            background: linear-gradient(135deg, #002F6C 0%, #0050b3 100%);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: transform .15s, box-shadow .2s;
            box-shadow: 0 4px 24px rgba(0,47,108,.5), 0 0 0 1px rgba(255,255,255,.07) inset;
            position: relative;
            overflow: hidden;
        }
        .btn-login::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,.12) 0%, transparent 60%);
            opacity: 0;
            transition: opacity .2s;
        }
        .btn-login:hover:not(:disabled)::before { opacity: 1; }
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(0,47,108,.65), 0 0 0 1px rgba(255,255,255,.1) inset;
        }
        .btn-login:active:not(:disabled) { transform: translateY(0); }
        .btn-login:disabled {
            background: rgba(255,255,255,.07);
            color: rgba(255,255,255,.28);
            cursor: not-allowed;
            box-shadow: none;
        }

        .spinner {
            width: 17px; height: 17px;
            border: 2.5px solid rgba(255,255,255,.25);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            display: none;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .login-footer {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        .login-footer a {
            font-size: 13px;
            font-weight: 600;
            color: rgba(180,210,255,.75);
            text-decoration: none;
            transition: color .2s;
            text-shadow: 0 1px 4px rgba(0,0,0,.4);
        }
        .login-footer a:hover { color: #93c5fd; }

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

        /* Modal 4D Redesign */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.75);
            backdrop-filter: blur(8px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.open { display: flex; animation: fadeIn .25s ease; }
        @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }

        .modal-box {
            background: linear-gradient(165deg, rgba(0, 15, 45, 0.95) 0%, rgba(0, 25, 65, 0.97) 100%);
            backdrop-filter: blur(32px) saturate(1.8) brightness(1.1);
            -webkit-backdrop-filter: blur(32px) saturate(1.8) brightness(1.1);
            border-radius: 24px;
            width: 92%;
            max-width: 520px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            position: relative;
            box-shadow: 
                0 2px 0 rgba(255,255,255,.08) inset, 
                0 -1px 0 rgba(0,0,0,.4) inset, 
                0 16px 48px rgba(0,0,0,.6), 
                0 32px 96px rgba(0,0,0,.7), 
                0 0 0 1px rgba(255,255,255,.12), 
                0 0 50px var(--blue-glow);
            animation: modalSlideUp .3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .modal-box::before {
            content: '';
            position: absolute;
            inset: -1.5px;
            border-radius: 25px;
            background: linear-gradient(135deg, rgba(0,100,255,.4), rgba(227,6,19,.35), rgba(0,60,180,.4));
            z-index: -1;
        }
        
        @keyframes modalSlideUp {
            from { transform: translateY(30px) scale(0.95); opacity:0; }
            to   { transform: translateY(0) scale(1);    opacity:1; }
        }
        .modal-head {
            background: linear-gradient(135deg, rgba(0, 47, 108, 0.95), rgba(0, 26, 61, 0.95));
            padding: 20px 24px;
            border-radius: 24px 24px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }
        .modal-head h2 {
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: .5px;
            text-transform: uppercase;
            text-shadow: 0 0 10px rgba(100,160,255,.5);
        }
        .modal-head .modal-x {
            background: none; border: none;
            color: rgba(255,255,255,.65);
            font-size: 24px; cursor: pointer;
            transition: color .2s, transform .2s; line-height: 1;
        }
        .modal-head .modal-x:hover { color: #fff; transform: scale(1.1); }
 
        .modal-body-scroll {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }
        /* Custom scrollbar for modal body */
        .modal-body-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .modal-body-scroll::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 3px;
        }
        .modal-body-scroll::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 3px;
        }
        .modal-body-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .modal-body-scroll h3 {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #93c5fd;
            margin: 20px 0 8px;
            text-shadow: 0 0 8px rgba(147,197,253,.3);
        }
        .modal-body-scroll h3:first-child { margin-top: 0; }
        .modal-body-scroll p {
            font-size: 13.5px;
            line-height: 1.7;
            color: rgba(255, 255, 255, 0.82);
            text-shadow: 0 1px 2px rgba(0,0,0,.4);
            margin-bottom: 12px;
        }
        .modal-foot {
            padding: 16px 24px;
            border-top: 1px solid rgba(255,255,255,.08);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: rgba(0, 10, 30, 0.4);
            border-radius: 0 0 24px 24px;
        }
        .btn-modal {
            padding: 10px 22px;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all .2s;
        }
        .btn-cancel {
            background: rgba(255,255,255,.08);
            color: rgba(255,255,255,.8);
            border: 1px solid rgba(255,255,255,.1);
        }
        .btn-cancel:hover { background: rgba(255,255,255,.15); color: #fff; }
        .btn-agree {
            background: linear-gradient(135deg, #002F6C, #0050b3);
            color: #fff;
            box-shadow: 0 4px 14px rgba(0,47,108,.4);
            border: 1px solid rgba(255,255,255,.08);
        }
        .btn-agree:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(0,47,108,.55); }

        @media (max-width: 540px) {
            .login-wrap { padding: 0 12px; }
            .login-card  { padding: 38px 28px 32px; }
        }

        /* ── Math CAPTCHA ── */
        .captcha-group {
            margin-bottom: 18px;
            width: 100%;
            box-sizing: border-box;
        }
        .captcha-box {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            box-sizing: border-box;
        }
        .captcha-question {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: auto;
            min-width: 0;
            padding: 0 14px;
            height: 48px;
            background: rgba(0,47,108,.45);
            border: 1.5px solid rgba(100,160,255,.3);
            border-radius: 12px;
            color: #93c5fd;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 1px;
            white-space: nowrap;
            box-shadow: 0 0 12px rgba(59,130,246,.2) inset;
            user-select: none;
        }
        .captcha-equals {
            color: rgba(255,255,255,.7);
            font-size: 20px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .captcha-input {
            flex: 1;
            min-width: 0;
            height: 48px;
            background: rgba(0,0,0,.45);
            border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 12px;
            box-shadow: 0 2px 6px rgba(0,0,0,.35) inset;
            padding: 0 12px;
            color: #ffffff;
            font-family: inherit;
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 4px;
            outline: none;
            transition: border-color .25s, box-shadow .25s;
            box-sizing: border-box;
        }
        .captcha-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 14px rgba(59,130,246,.5), 0 2px 6px rgba(0,0,0,.3) inset;
        }
        .captcha-input::placeholder {
            color: rgba(255,255,255,.25);
            font-weight: 400;
            letter-spacing: normal;
            font-size: 14px;
        }
        /* Captcha validation states */
        .captcha-input.captcha-error {
            border-color: #ef4444 !important;
            background: rgba(239, 68, 68, 0.15) !important;
            box-shadow: 0 0 16px rgba(239, 68, 68, 0.6), 0 2px 6px rgba(0,0,0,.3) inset !important;
        }
        .captcha-input.captcha-success {
            border-color: #22c55e !important;
            background: rgba(34, 197, 94, 0.15) !important;
            box-shadow: 0 0 16px rgba(34, 197, 94, 0.6), 0 2px 6px rgba(0,0,0,.3) inset !important;
        }
        .captcha-refresh {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            background: rgba(0,47,108,.45);
            border: 1.5px solid rgba(100,160,255,.3);
            border-radius: 12px;
            color: #93c5fd;
            font-size: 18px;
            cursor: pointer;
            transition: all .25s;
            box-shadow: 0 0 12px rgba(59,130,246,.2) inset;
        }
        .captcha-refresh:hover:not(:disabled) {
            background: rgba(0,47,108,.65);
            border-color: rgba(100,160,255,.5);
            color: #60a5fa;
            box-shadow: 0 0 16px rgba(59,130,246,.35) inset;
            transform: scale(1.05);
        }
        .captcha-refresh:active:not(:disabled) {
            transform: scale(0.95);
        }
        .captcha-refresh:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .captcha-refresh.spinning {
            animation: spinRefresh 0.6s ease;
        }
        @keyframes spinRefresh {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
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
    <div class="orb orb-3"></div>
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
    <div class="particle"></div>
    <div class="particle"></div>
</div>
<div class="bg-layer bg-scanlines"></div>
<div class="bg-layer bg-grid"></div>
<div class="bg-layer bg-rays">
    <div class="ray"></div>
    <div class="ray"></div>
    <div class="ray"></div>
</div>

<div class="login-wrap">
    <div class="login-card">

        <!-- Branding -->
        <div class="brand">
            <img src="../assets/img/Petron Logo.png" alt="Petron" class="brand-logo">
            <span class="brand-tagline">Station Management System</span>
        </div>

        <!-- Error Banner -->
        <?php if ($error): ?>
        <div class="alert-error" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="" id="loginForm" novalidate>

            <!-- Account ID -->
            <div class="form-group">
                <div class="field-label">
                    <span>Account ID</span>
                    <!-- type-badge kept in DOM for JS compat but hidden via CSS -->
                    <span class="type-badge" id="typeBadge"></span>
                </div>
                <div class="input-wrap">
                    <i class="fas fa-id-badge input-icon"></i>
                    <input
                        type="text"
                        name="username"
                        id="accountId"
                        class="field-input no-right"
                        placeholder="Enter Account"
                        required
                        autofocus
                        autocomplete="username"
                        aria-label="Account ID"
                    >
                </div>
            </div>

            <!-- Password -->
            <div class="form-group">
                <div class="field-label">
                    <span>Password</span>
                </div>
                <div class="input-wrap">
                    <i class="fas fa-lock input-icon"></i>
                    <input
                        type="password"
                        name="password"
                        id="passwordField"
                        class="field-input"
                        placeholder="Enter password"
                        required
                        autocomplete="current-password"
                        aria-label="Password"
                    >
                    <button type="button" class="pw-toggle" id="pwToggle" aria-label="Show or hide password">
                        <i class="fas fa-eye" id="pwIcon"></i>
                    </button>
                </div>
            </div>

            <!-- CAPTCHA -->
            <div class="captcha-group">
                <div class="captcha-box">
                    <div class="captcha-question" id="captchaQuestion"><?php echo htmlspecialchars($captcha_question); ?></div>
                    <div class="captcha-equals">=</div>
                    <input
                        type="text"
                        name="captcha"
                        id="captchaInput"
                        class="captcha-input"
                        placeholder=""
                        maxlength="3"
                        inputmode="numeric"
                        autocomplete="off"
                        required
                        aria-label="CAPTCHA Answer"
                    >
                    <button type="button" class="captcha-refresh" id="refreshCaptcha" aria-label="Refresh CAPTCHA">
                        <i class="fas fa-redo-alt"></i>
                    </button>
                </div>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-login" id="submitBtn">
                <div class="spinner" id="spinner"></div>
                <span id="btnText"><i class="fas fa-sign-in-alt" style="margin-right:6px;font-size:13px;"></i>Login</span>
            </button>
        </form>

        <div class="login-footer">
            <a href="forgot_password.php">
                <i class="fas fa-key" style="margin-right:5px;font-size:11px;opacity:.7;"></i>Forgot Password?
            </a>
        </div>
    </div>
</div>

<div class="page-footer"><?php echo $footer_text; ?></div>



<script>
(function () {
    var form       = document.getElementById('loginForm');
    var accountId  = document.getElementById('accountId');
    var pwField    = document.getElementById('passwordField');
    var pwToggle   = document.getElementById('pwToggle');
    var pwIcon     = document.getElementById('pwIcon');
    var submitBtn  = document.getElementById('submitBtn');
    var spinner    = document.getElementById('spinner');
    var btnText    = document.getElementById('btnText');
    var typeBadge  = document.getElementById('typeBadge');
    var refreshBtn = document.getElementById('refreshCaptcha');
    var captchaQuestion = document.getElementById('captchaQuestion');
    var captchaInput = document.getElementById('captchaInput');
    
    /* Real-time captcha validation */
    function validateCaptcha() {
        var answer = captchaInput.value.trim();
        if (!answer) {
            captchaInput.classList.remove('captcha-error', 'captcha-success');
            return;
        }
        
        // Get the question text and calculate expected answer
        var questionText = captchaQuestion.textContent.trim();
        var match = questionText.match(/(\d+)\s*\+\s*(\d+)/);
        
        if (match) {
            var num1 = parseInt(match[1], 10);
            var num2 = parseInt(match[2], 10);
            var correctAnswer = num1 + num2;
            var userAnswer = parseInt(answer, 10);
            
            if (userAnswer === correctAnswer) {
                captchaInput.classList.remove('captcha-error');
                captchaInput.classList.add('captcha-success');
            } else {
                captchaInput.classList.remove('captcha-success');
                captchaInput.classList.add('captcha-error');
            }
        }
    }
    
    // Validate on input
    captchaInput.addEventListener('input', validateCaptcha);
    
    // Validate on blur
    captchaInput.addEventListener('blur', validateCaptcha);

    /* Live type detection */
    function detectType(val) {
        val = (val || '').trim();
        if (!val) return null;
        if (val.indexOf('@') !== -1) return 'email';
        if (/^\d{11}$/.test(val))   return 'phone';
        return 'username';
    }
    var typeLabels = { email: 'Email', phone: 'Phone', username: 'Username' };

    accountId.addEventListener('input', function () {
        var t = detectType(this.value);
        typeBadge.className = 'type-badge';
        if (t) {
            typeBadge.classList.add(t);
            typeBadge.textContent = typeLabels[t];
        } else {
            typeBadge.textContent = '';
        }
    });

    /* Password toggle */
    pwToggle.addEventListener('click', function () {
        var isText = pwField.type === 'text';
        pwField.type = isText ? 'password' : 'text';
        pwIcon.className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
    });

    /* Refresh CAPTCHA */
    refreshBtn.addEventListener('click', function () {
        // Prevent multiple clicks
        if (refreshBtn.disabled) return;
        
        // Disable button and add spinning animation
        refreshBtn.disabled = true;
        refreshBtn.classList.add('spinning');
        
        // Send AJAX request to refresh CAPTCHA
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'refresh_captcha.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function () {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        captchaQuestion.textContent = response.question;
                        captchaInput.value = '';
                        captchaInput.classList.remove('captcha-error', 'captcha-success');
                        captchaInput.focus();
                    } else {
                        console.error('CAPTCHA refresh failed:', response.error || 'Unknown error');
                    }
                } catch (e) {
                    console.error('Error parsing CAPTCHA response:', e);
                }
            } else {
                console.error('Server error:', xhr.status);
            }
            
            // Remove spinning animation and re-enable button after 600ms
            setTimeout(function () {
                refreshBtn.classList.remove('spinning');
                refreshBtn.disabled = false;
            }, 600);
        };
        
        xhr.onerror = function () {
            console.error('Error refreshing CAPTCHA');
            setTimeout(function () {
                refreshBtn.classList.remove('spinning');
                refreshBtn.disabled = false;
            }, 600);
        };
        
        xhr.send('refresh=1');
    });

    /* Form submit */
    form.addEventListener('submit', function (e) {
        submitBtn.disabled = true;
        spinner.style.display = 'block';
        btnText.innerHTML = 'Authenticating\u2026';
    });
}());
</script>

</body>
</html>
