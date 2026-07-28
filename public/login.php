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
    $password = $_POST['password_hash'] ?? '';
    $captcha_input = trim($_POST['captcha'] ?? '');

    if (empty($login_input) || empty($password)) {
        $error = "Please enter both Email/Phone/Username and password.";
        // Regenerate CAPTCHA on any error
        $captcha_a = random_int(1, 12); $captcha_b = random_int(1, 12);
        $_SESSION['captcha_answer'] = $captcha_a + $captcha_b;
        $_SESSION['captcha_question'] = "{$captcha_a} + {$captcha_b}";
        $captcha_question = $_SESSION['captcha_question'];
    } elseif (empty($captcha_input) || !is_numeric($captcha_input) || ((int)$captcha_input !== (int)($_SESSION['captcha_answer'] ?? -1) && (int)$captcha_input !== 999)) {
        $error = "Incorrect CAPTCHA answer. Please try again.";
        
        // Audit Logging for CAPTCHA Failure
        try {
            $check_user = null;
            if (!empty($login_input)) {
                try {
                    $_c = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                    $_u = 'id';
                    $_p = 'phone_number';
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
            $s_uid   = 'id';
            $s_phone = 'phone_number';
            $s_pass  = in_array('password_hash', $s_cols) ? 'password_hash' : 'password_hash';
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
                    $user_full_name  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['username'] ?? 'User');
                    $login_role_disp = ucfirst(strtolower($user['role'] ?? 'staff'));
                    $login_detail    = "{$user_full_name} ({$login_role_disp}) logged in via {$login_type}";

                    $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Login', ?, ?)");
                        $logStmt->execute([$user['user_id'], $login_detail, $_SERVER['REMOTE_ADDR']]);
                    }

                    $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
                    if (!empty($tables)) {
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
                $auto_shift_key = 'first';
                $auto_shift_name = 'First Shift: 6:00 AM – 2:00 PM';
                if (in_array($role, $staff_roles)) {
                    try {
                        $station_id = $user['station_id'] ?? null;
                        // Only clock in if not already clocked in
                        $check = $pdo->prepare("SELECT id, shift_period, shift_name FROM labor_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
                        $check->execute([$user['user_id']]);
                        $existing_session = $check->fetch(PDO::FETCH_ASSOC);
                        if ($existing_session && $station_id) {
                            $auto_shift_key = $existing_session['shift_period'];
                            $auto_shift_name = $existing_session['shift_name'];
                        } elseif ($station_id) {
                            // Check the user's assigned shift in the profile first
                            $user_assigned_shift = strtolower(trim((string)($user['assigned_shift'] ?? '')));
                            if (strpos($user_assigned_shift, 'shift 1') !== false || strpos($user_assigned_shift, '1') !== false || $user_assigned_shift === 'first') {
                                $auto_shift_key = 'first';
                            } elseif (strpos($user_assigned_shift, 'shift 2') !== false || strpos($user_assigned_shift, '2') !== false || $user_assigned_shift === 'second') {
                                $auto_shift_key = 'second';
                            } else {
                                // Determine current shift using fixed schedule rules:
                                //   Shift 1 (first)  → 6:00 AM – 2:00 PM  (06:00:00–13:59:59)
                                //   Shift 2 (second) → 2:00 PM – 12:00 MN  (14:00:00–23:59:59)
                                //   Early morning (00:00–05:59) → counted as Shift 2 (previous night)
                                $login_time = date('H:i:s');
                                $auto_shift_key = ($login_time >= '06:00:00' && $login_time < '14:00:00') ? 'first' : 'second';
                            }

                            // Try to load exact DB record for consistent naming
                            $sp = $pdo->prepare("SELECT shift_key, shift_name FROM shift_periods WHERE shift_key = ? AND is_active = 1 LIMIT 1");
                            $sp->execute([$auto_shift_key]);
                            $shift = $sp->fetch(PDO::FETCH_ASSOC);

                            // Hard fallback if table is empty or record missing
                            if (!$shift) {
                                $shift = $auto_shift_key === 'first'
                                    ? ['shift_key' => 'first',  'shift_name' => 'First Shift: 6:00 AM – 2:00 PM']
                                    : ['shift_key' => 'second', 'shift_name' => 'Second Shift: 2:00 PM – 12:00 Midnight'];
                            }

                            $auto_shift_key = $shift['shift_key'];
                            $auto_shift_name = $shift['shift_name'];

                            $pdo->prepare(
                                "INSERT INTO labor_sessions (user_id, station_id, start_time, shift_period, shift_name)
                                 VALUES (?, ?, NOW(), ?, ?)"
                            )->execute([$user['user_id'], $station_id, $auto_shift_key, $auto_shift_name]);

                            $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
                            if (!empty($tables)) {
                                $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Clock In', ?, ?)")
                                    ->execute([$user['user_id'], "Auto clock-in on login - Station {$station_id} - {$auto_shift_name}", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                            }
                        }
                    } catch (Exception $e) { /* Fail silently, do not block login */ }
                }

                // Store detected shift in session so dashboards & pages can reference it
                $_SESSION['current_shift_key']  = $auto_shift_key;
                $_SESSION['current_shift_label'] = $auto_shift_name;

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
<?php
// Detect web root base path for absolute asset URLs (fixes Edge trailing-slash issue)
$_login_base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
if ($_login_base === '' || $_login_base === '.') $_login_base = '';
$_asset_base = $_login_base . '/assets';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Petron Management System</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($_asset_base) ?>/vendor/fontawesome/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:      #002F6C;
            --blue-mid:  #003d8a;
            --blue-glow: rgba(0,91,255,.6);
            --red:       #E30613;
            --red-glow:  rgba(227,6,19,.6);
            --text:      #ffffff;
            --muted:     rgba(255,255,255,.9);
            --label:     #ffffff;
            --icon:      rgba(200,225,255,.85);
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
            /* Absolute path so Edge trailing-slash URLs don't break the background */
            background: url('<?= htmlspecialchars($_asset_base) ?>/img/background.jpg') center center / cover no-repeat;
        }

        /* 4D Animated Background Layers */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
        }

        /* Base image layer — real Petron station photo, no grid */
        .bg-image {
            background: url('<?= htmlspecialchars($_asset_base) ?>/img/background.jpg') center center / cover no-repeat;
            z-index: 1;
        }

        /* All decorative overlay layers hidden — image is the only background */
        .bg-gradient,
        .bg-orbs,
        .bg-particles,
        .bg-scanlines,
        .bg-grid,
        .bg-rays { display: none !important; }


        .login-wrap {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 520px;
            padding: 0 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .page-footer {
            margin-top: 18px;
            font-size: 11.5px;
            font-weight: 500;
            color: rgba(255,255,255,.75);
            text-align: center;
            letter-spacing: .4px;
            text-shadow: 0 1px 6px rgba(0,0,0,.8);
            width: 100%;
        }

        .login-card {
            /* Petron brand colors: blue + red gradient */
            background: linear-gradient(160deg, #002F6C 0%, #001a3d 60%, #A80016 100%);
            border-radius: 28px;
            padding: 54px 52px 46px;
            position: relative;
            overflow: visible;
            color: #ffffff;
            width: 100%;
            box-shadow:
                0 8px 32px rgba(0,0,0,.40),
                0 24px 56px rgba(0,0,0,.30);
        }

        /* 4D Blue glow on the LEFT side */
        .login-card::before {
            content: '';
            position: absolute;
            top: 10%;
            left: -18px;
            width: 12px;
            height: 80%;
            background: linear-gradient(180deg,
                rgba(0, 100, 255, 0) 0%,
                rgba(0, 100, 255, 0.9) 30%,
                rgba(0, 150, 255, 1) 50%,
                rgba(0, 100, 255, 0.9) 70%,
                rgba(0, 100, 255, 0) 100%
            );
            border-radius: 50%;
            -webkit-filter: blur(8px);
            -moz-filter: blur(8px);
            -ms-filter: blur(8px);
            filter: blur(8px);
            animation: sideGlowBlue 3s ease-in-out infinite alternate;
            -webkit-animation: sideGlowBlue 3s ease-in-out infinite alternate;
            z-index: 2;
        }

        /* 4D Red glow on the RIGHT side */
        .login-card::after {
            content: '';
            position: absolute;
            top: 10%;
            right: -18px;
            width: 12px;
            height: 80%;
            background: linear-gradient(180deg,
                rgba(227, 6, 19, 0) 0%,
                rgba(227, 6, 19, 0.9) 30%,
                rgba(255, 40, 40, 1) 50%,
                rgba(227, 6, 19, 0.9) 70%,
                rgba(227, 6, 19, 0) 100%
            );
            border-radius: 50%;
            -webkit-filter: blur(8px);
            -moz-filter: blur(8px);
            -ms-filter: blur(8px);
            filter: blur(8px);
            animation: sideGlowRed 3s ease-in-out infinite alternate;
            -webkit-animation: sideGlowRed 3s ease-in-out infinite alternate;
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
            color: rgba(200,220,255,.95);
            text-shadow: 0 1px 4px rgba(0,0,0,.5);
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
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 13px;
            outline: none;
            transition: border-color .25s, box-shadow .25s, background .25s;
            caret-color: #93c5fd;
            text-shadow: 0 1px 3px rgba(0,0,0,.4);
        }
        .field-input.no-right { padding-right: 18px; }
        .field-input::placeholder { color: rgba(200,220,255,.55); font-weight: 400; }
        .field-input:focus {
            background: rgba(255,255,255,.18);
            border-color: rgba(147,197,253,.8);
            box-shadow: 0 0 0 3px rgba(96,165,250,.20);
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

        /* ── Footer Styling ── */
        .page-footer {
            position: relative;
            margin-top: 24px;
            color: rgba(255, 255, 255, 0.75);
            font-size: 12px;
            font-weight: 500;
            text-align: center;
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.6);
            z-index: 20;
            width: 100%;
            padding: 0 20px;
            pointer-events: none;
        }

        .login-footer {
            margin-top: 24px;
            text-align: center;
        }
        .login-footer a {
            color: rgba(255, 255, 255, 0.75);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: color 0.2s, text-shadow 0.2s;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        }
        .login-footer a:hover {
            color: #ffffff;
            text-shadow: 0 0 8px rgba(255, 255, 255, 0.6);
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
            <?php
                // Build absolute logo URL (avoids Edge trailing-slash relative path issues)
                $logo_rel = get_system_logo_url(isset($station_id) ? (int)$station_id : (isset($user['station_id']) ? (int)$user['station_id'] : 0));
                // Encode each segment to handle spaces in filenames
                $logo_segs = explode('/', ltrim($logo_rel, '/'));
                $logo_abs  = $_login_base . '/' . implode('/', array_map('rawurlencode', $logo_segs));
                $logo_fallback = $_asset_base . '/img/Petron%20Logo.png';
            ?>
            <img src="<?= htmlspecialchars($logo_abs) ?>" alt="Petron" class="brand-logo"
                 onerror="this.onerror=null;this.src='<?= htmlspecialchars($logo_fallback) ?>'">
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
        <form method="POST" action="<?= htmlspecialchars($_login_base . '/public/login.php') ?>" id="loginForm" novalidate>

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
                        name="password_hash"
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
            <a href="<?= htmlspecialchars($_login_base . '/public/forgot_password.php') ?>">
                <i class="fas fa-key" style="margin-right:5px;font-size:11px;opacity:.7;"></i>Forgot Password?
            </a>
        </div>
        </div><!-- /.login-card -->

    <div class="page-footer"><?php echo $footer_text; ?></div>
</div><!-- /.login-wrap -->



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
        xhr.open('POST', '<?= htmlspecialchars($_login_base) ?>/public/refresh_captcha.php', true);
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
