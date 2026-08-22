<?php
// ── Session Hardening: Strict Cookie Params before session_start() ──
if (session_status() === PHP_SESSION_NONE) {
    $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_secure,
        'httponly' => true,
        'samesite' => 'Strict'  // Upgraded from Lax → Strict (blocks CSRF via cross-site nav)
    ]);
    session_start();
}
ob_start(); // Buffer output to prevent "headers already sent" errors

// Include database connection
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// ── Force HTTPS (skip on localhost) ──
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false && $host !== '::1') {
        header("Location: https://" . $host . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// ── Security HTTP Headers ──
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private");
header("Pragma: no-cache");
// Strict-Transport-Security (only on HTTPS)
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}
// Content-Security-Policy — blocks clickjacking, form hijacking, unwanted external sources
// Note: 'unsafe-inline' for scripts is needed because the page uses many inline <script> blocks.
// The critical protections are: frame-ancestors (clickjacking), form-action (CSRF), base-uri (base injection).
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://fonts.gstatic.com; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self';");

// ── Generate / Rotate CSRF Token ──
// Rotate the token on each GET so each page load has a fresh token
if (empty($_SESSION['csrf_token']) || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// Configuration variables
$system_name = "Petron Station & Service Center Management System";
$current_year = date("Y");
$footer_text = "&copy; {$current_year} {$system_name}. All Rights Reserved.";

// Helper to generate math captcha (Addition only)
if (!function_exists('generate_strong_captcha')) {
    function generate_strong_captcha() {
        $a = random_int(5, 35);
        $b = random_int(3, 25);
        $answer = $a + $b;
        $question = "{$a} + {$b}";

        $_SESSION['captcha_answer'] = $answer;
        $_SESSION['captcha_question'] = $question;
        return ['question' => $question, 'answer' => $answer];
    }
}

// 2. Generate Math CAPTCHA (always fresh on every page load or when missing)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['captcha_answer'])) {
    generate_strong_captcha();
}
$captcha_question = $_SESSION['captcha_question'] ?? '? + ?';

$error = '';
$success_msg = '';
$login_success = false;
$dashboard_url = '';
$is_locked_out = false;
$lockout_remaining_sec = 0;
$lockout_duration = 5; // 5 seconds lockout
$max_attempts = 5;

// Check if currently locked out on page load / session
if (!empty($_SESSION['login_fail_count']) && $_SESSION['login_fail_count'] >= $max_attempts) {
    $sec_since = time() - (int)($_SESSION['last_fail_time'] ?? 0);
    if ($sec_since < $lockout_duration) {
        $is_locked_out = true;
        $lockout_remaining_sec = max(1, $lockout_duration - $sec_since);
        $error = "Too many failed login attempts. Your account is temporarily locked. Please try again after {$lockout_remaining_sec} seconds.";
    } else {
        $_SESSION['login_fail_count'] = 0;
    }
}

// Check for password reset success
if (isset($_GET['reset_success']) && $_GET['reset_success'] === '1') {
    $success_msg = 'Password reset successful. Please log in with your new password.';
}

// Check for session timeout due to inactivity
$timeout_msg = '';
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $timeout_msg = 'Your session has expired due to inactivity. Please log in again.';
}

// Check for login error from auth/login.php
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

// 1. Check if already logged in (only on GET requests)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['user'])) {
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
    $csrf_token = $_POST['csrf_token'] ?? '';

    // ── Honeypot bot trap: bots usually fill hidden fields ──
    if (!empty($_POST['_email_confirm'])) {
        // Silently reject - looks like a successful page to bots
        http_response_code(200);
        exit;
    }

    // ── Strip null bytes and sanitize inputs ──
    $login_input   = trim(str_replace(["\0", "\r", "\n"], '', strip_tags($_POST['username'] ?? $_POST['account_id'] ?? '')));
    $password      = $_POST['password'] ?? $_POST['password_hash'] ?? '';
    $captcha_input = trim(preg_replace('/[^0-9]/', '', $_POST['captcha'] ?? ''));
    $ip_address    = filter_var($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', FILTER_VALIDATE_IP) ?: '0.0.0.0';

    // ── Reject obviously bad input early ──
    if (mb_strlen($login_input) > 120 || mb_strlen($password) > 256 || mb_strlen($captcha_input) > 4) {
        $error = "Invalid input.";
        generate_strong_captcha();
        $captcha_question = $_SESSION['captcha_question'];
        goto end_post;
    }

    // ── Check Pre-Existing Lockout before checking credentials ──
    if (!$is_locked_out) {
        // Check session
        if (!empty($_SESSION['login_fail_count']) && $_SESSION['login_fail_count'] >= $max_attempts) {
            $sec_since = time() - (int)($_SESSION['last_fail_time'] ?? 0);
            if ($sec_since < $lockout_duration) {
                $is_locked_out = true;
                $lockout_remaining_sec = max(1, $lockout_duration - $sec_since);
                $error = "Too many failed login attempts. Your account is temporarily locked. Please try again after {$lockout_remaining_sec} seconds.";
            } else {
                $_SESSION['login_fail_count'] = 0;
            }
        }
        // Check DB login_attempts
        if (!$is_locked_out) {
            try {
                $tables = $pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchAll();
                if (!empty($tables)) {
                    $stmtLock = $pdo->prepare("
                        SELECT COUNT(*) as fail_count, 
                               TIMESTAMPDIFF(SECOND, MAX(attempt_time), NOW()) as sec_since_last 
                        FROM login_attempts 
                        WHERE (username = ? OR ip_address = ?) 
                          AND status = 'failed' 
                          AND attempt_time > NOW() - INTERVAL 10 MINUTE
                    ");
                    $stmtLock->execute([$login_input, $ip_address]);
                    $lockInfo = $stmtLock->fetch(PDO::FETCH_ASSOC);
                    if ($lockInfo && (int)$lockInfo['fail_count'] >= $max_attempts) {
                        $sec_since = (int)$lockInfo['sec_since_last'];
                        if ($sec_since < $lockout_duration) {
                            $is_locked_out = true;
                            $lockout_remaining_sec = max(1, $lockout_duration - $sec_since);
                            $error = "Too many failed login attempts. Your account is temporarily locked. Please try again after {$lockout_remaining_sec} seconds.";
                        }
                    }
                }
            } catch (Exception $e) {}
        }
    }

    // If currently locked out, reject immediately
    if ($is_locked_out) {
        generate_strong_captcha();
        $captcha_question = $_SESSION['captcha_question'];
    }
    // 2a. Validate CSRF Token
    elseif (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = "Invalid or expired security token. Please try again.";
        generate_strong_captcha();
        $captcha_question = $_SESSION['captcha_question'];
    } elseif (strlen($login_input) > 120 || strlen($password) > 256) {
        $error = "Invalid credentials or input length exceeds maximum allowed.";
        generate_strong_captcha();
        $captcha_question = $_SESSION['captcha_question'];
    } elseif (empty($login_input) || empty($password)) {
        $error = "Please enter both Email/Phone/Username and password.";
        // Regenerate CAPTCHA on error
        generate_strong_captcha();
        $captcha_question = $_SESSION['captcha_question'];
    } elseif ($captcha_input === '' || !preg_match('/^\d+$/', $captcha_input) || (int)$captcha_input !== (int)($_SESSION['captcha_answer'] ?? -1)) {
        $error = "Incorrect CAPTCHA answer. Please try again.";
        
        // Record failed attempt
        $_SESSION['login_fail_count'] = ($_SESSION['login_fail_count'] ?? 0) + 1;
        $_SESSION['last_fail_time'] = time();

        if ($_SESSION['login_fail_count'] >= $max_attempts) {
            $is_locked_out = true;
            $lockout_remaining_sec = $lockout_duration;
            $error = "Too many failed login attempts. Your account is temporarily locked. Please try again after {$lockout_duration} seconds.";
        }

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
        generate_strong_captcha();
        $captcha_question = $_SESSION['captcha_question'];
    } else {
        try {
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
            } else {
                // Dummy verify to eliminate timing discrepancies between existing and non-existing accounts
                password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
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

                // Reset failed attempts counter
                $_SESSION['login_fail_count'] = 0;
                unset($_SESSION['last_fail_time']);

                // Direct login - Set session and redirect to dashboard
                unset($user[$s_pass]); // Remove password from session
                $_SESSION['user']          = $user;
                $_SESSION['user_id']       = $user['user_id'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['last_activity'] = time();

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

                // Determine dashboard URL based on role
                if ($role === 'superadmin') {
                    $dashboard_url = 'super_admin_dashboard.php';
                } elseif ($role === 'admin') {
                    $dashboard_url = 'admin_dashboard.php';
                } elseif ($role === 'manager') {
                    $dashboard_url = 'manager_dashboard.php';
                } else {
                    $dashboard_url = 'staff_dashboard.php';
                }
                // Regenerate session ID for security
                session_regenerate_id(true);

                // Set login success flag so login.php renders its natural page with Login Successfully
                $login_success = true;
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

                // Increment session-level fail counter
                $_SESSION['login_fail_count'] = ($_SESSION['login_fail_count'] ?? 0) + 1;
                $_SESSION['last_fail_time'] = time();

                if ($_SESSION['login_fail_count'] >= $max_attempts) {
                    $is_locked_out = true;
                    $lockout_remaining_sec = $lockout_duration;
                    $error = "Too many failed login attempts. Your account is temporarily locked. Please try again after {$lockout_duration} seconds.";
                } elseif (empty($error)) {
                    $attempts_left = $max_attempts - (int)$_SESSION['login_fail_count'];
                    $error = "Invalid credentials or password. {$attempts_left} attempt(s) remaining before temporary lockout.";
                }
            }
            } // End of if (empty($error)) wrapper for lockout
        } catch (PDOException $e) {
            // Log error internally, show generic message to user
            error_log($e->getMessage());
            $error = "System error. Please try again later.";
        }
    }

    // Always regenerate a brand new CAPTCHA on every failed attempt
    if (!$login_success) {
        generate_strong_captcha();
        $captcha_question = $_SESSION['captcha_question'];
        // Rotate CSRF token after each failed attempt (prevents replay attacks)
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    end_post: // goto target for early exit on bad input
    (function(){})(); // no-op statement required after label in PHP
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

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
            /* Absolute path so Edge trailing-slash URLs don't break the background */
            background: #000c1e url('<?= htmlspecialchars($_asset_base) ?>/img/background.jpg') center center / cover no-repeat fixed;
        }

        /* 4D Animated Background Layers */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
        }

        /* Base image layer — real Petron station photo, fixed position */
        .bg-image {
            background: url('<?= htmlspecialchars($_asset_base) ?>/img/background.jpg') center center / cover no-repeat fixed;
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
            background: rgba(227,6,19,.28);
            border: 1px solid rgba(227,6,19,.65);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 22px;
            font-size: 13px;
            font-weight: 600;
            color: #ffc2c2;
            animation: shake .35s ease;
            box-shadow: 0 4px 16px rgba(227,6,19,0.25);
        }
        .alert-success {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(16,185,129,.15);
            border: 1px solid rgba(16,185,129,.4);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 22px;
            font-size: 13px;
            color: #6ee7b7;
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            25%      { transform: translateX(-6px); }
            75%      { transform: translateX(6px); }
        }
        .alert-error i, .alert-success i { flex-shrink: 0; font-size: 15px; }

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

        /* Hide browser native password reveal eye & number spinners */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear { display: none !important; }
        input[type="password"]::-webkit-contacts-auto-fill-button,
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="password"]::-webkit-strong-password-auto-fill-button {
            display: none !important; visibility: hidden; pointer-events: none;
        }
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type="number"] {
            -moz-appearance: textfield;
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
            justify-content: space-between;
            gap: 10px;
            width: 100%;
            box-sizing: border-box;
        }
        .captcha-question {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            min-width: 0;
            padding: 0 14px;
            height: 48px;
            background: rgba(0,47,108,.45);
            border: 1.5px solid rgba(100,160,255,.3);
            border-radius: 12px;
            color: #93c5fd;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 1.5px;
            white-space: nowrap;
            box-shadow: 0 0 12px rgba(59,130,246,.2) inset;
            user-select: none;
        }
        .captcha-equals {
            color: rgba(255,255,255,.7);
            font-size: 20px;
            font-weight: 700;
            flex-shrink: 0;
            padding: 0 2px;
        }
        .captcha-input {
            width: 85px;
            flex: 0 0 85px;
            height: 48px;
            background: rgba(0,0,0,.45);
            border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 12px;
            box-shadow: 0 2px 6px rgba(0,0,0,.35) inset;
            padding: 0 8px;
            color: #ffffff;
            font-family: inherit;
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 1px;
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

        /* ── Top Navigation Bar ── */
        .login-navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding: 0 28px;
            height: 60px;
            background: transparent;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            border-bottom: none;
            box-shadow: none;
            animation: navSlideDown 0.5s cubic-bezier(0.22,1,0.36,1) both;
        }
        @keyframes navSlideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to   { transform: translateY(0);     opacity: 1; }
        }
        .login-navbar .nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .login-navbar .nav-brand img {
            height: 36px;
            width: auto;
            filter: drop-shadow(0 0 6px rgba(227,6,19,0.4));
        }
        .login-navbar .nav-brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.1;
        }
        .login-navbar .nav-brand-text span:first-child {
            font-size: 15px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: 0.5px;
            text-shadow: 0 0 12px rgba(255,255,255,0.3);
        }
        .login-navbar .nav-brand-text span:last-child {
            font-size: 10px;
            font-weight: 500;
            color: rgba(180,210,255,0.75);
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .login-navbar .nav-links {
            display: flex;
            align-items: center;
            gap: 6px;
            list-style: none;
        }
        .login-navbar .nav-links li a,
        .login-navbar .nav-links li button {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 18px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            color: #111111;
            text-decoration: none;
            background: transparent;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: color 0.2s, background 0.2s;
            letter-spacing: 0.3px;
            position: relative;
            text-shadow: none;
        }
        .login-navbar .nav-links li a::after,
        .login-navbar .nav-links li button::after {
            content: '';
            position: absolute;
            bottom: 4px;
            left: 18px;
            right: 18px;
            height: 2px;
            background: linear-gradient(90deg, #E30613, #002F6C);
            border-radius: 2px;
            transform: scaleX(0);
            transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1);
        }
        .login-navbar .nav-links li a:hover,
        .login-navbar .nav-links li button:hover {
            color: #002F6C;
            background: rgba(0,47,108,0.08);
        }
        .login-navbar .nav-links li a:hover::after,
        .login-navbar .nav-links li button:hover::after {
            transform: scaleX(1);
        }
        .login-navbar .nav-links li a.active {
            color: #111111;
            background: transparent;
        }
        .login-navbar .nav-links li a:active,
        .login-navbar .nav-links li button:active {
            color: #002F6C;
            background: rgba(0,47,108,0.12);
        }
        /* Mobile nav toggle */
        .nav-toggle {
            display: none;
            background: none;
            border: 1.5px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            padding: 6px 10px;
            color: #fff;
            cursor: pointer;
            font-size: 18px;
            transition: background 0.2s;
        }
        .nav-toggle:hover { background: rgba(255,255,255,0.1); }
        @media (max-width: 600px) {
            .login-navbar { padding: 0 20px; }
            .nav-toggle { display: flex; align-items: center; }
            .login-navbar .nav-links {
                display: none;
                position: absolute;
                top: 64px; left: 0; right: 0;
                flex-direction: column;
                background: rgba(0, 15, 40, 0.97);
                backdrop-filter: blur(20px);
                padding: 12px 20px 20px;
                gap: 4px;
                border-bottom: 1px solid rgba(255,255,255,0.08);
            }
            .login-navbar .nav-links.open { display: flex; }
            .login-navbar .nav-links li a,
            .login-navbar .nav-links li button { width: 100%; border-radius: 10px; }
        }
        /* Push body content below navbar */
        .login-wrap {
            margin-top: 0;
        }

        /* ── In-Page Sections Styling ── */
        .page-sections-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-bottom: 48px; /* space for fixed footer */
        }

        .page-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 90px 24px 70px;
            scroll-margin-top: 60px;
            box-sizing: border-box;
        }

        .section-home {
            min-height: 100vh;
            padding-top: 80px;
            padding-bottom: 60px;
        }

        /* ── Full-Width Integrated Sections ── */
        .page-section {
            width: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 70px 24px 60px;
            scroll-margin-top: 0;
            box-sizing: border-box;
        }

        .section-home {
            min-height: 100vh;
            justify-content: center;
            padding-top: 60px;
            padding-bottom: 60px;
        }

        /* Transparent overlays for about/contact sections */
        .section-about,
        .section-contact {
            background: transparent;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            border: none;
        }

        .section-inner {
            width: 100%;
            max-width: 960px;
            margin: 0 auto;
            text-align: center;
            color: #111111;
            box-sizing: border-box;
        }

        /* Reveal animation */
        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity 0.7s cubic-bezier(0.22,1,0.36,1), transform 0.7s cubic-bezier(0.22,1,0.36,1);
        }
        .reveal.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .reveal-delay-1 { transition-delay: 0.1s; }
        .reveal-delay-2 { transition-delay: 0.2s; }
        .reveal-delay-3 { transition-delay: 0.3s; }
        .reveal-delay-4 { transition-delay: 0.4s; }

        .section-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #ffffff;
            background: linear-gradient(135deg, #002F6C, #0050b3);
            border: none;
            padding: 8px 22px;
            border-radius: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0,47,108,0.35);
        }

        .section-heading {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.2px;
            color: #0a1628;
            margin: 0 0 14px 0;
            line-height: 1.2;
        }

        .section-divider {
            width: 60px;
            height: 4px;
            background: linear-gradient(90deg, #E30613, #002F6C);
            border-radius: 4px;
            margin: 0 auto 28px auto;
        }

        .about-body-p {
            font-size: 16px;
            line-height: 1.9;
            color: #111111;
            margin-bottom: 18px;
            text-align: center;
            font-weight: 500;
            max-width: 760px;
            margin-left: auto;
            margin-right: auto;
        }
        .about-body-p:last-of-type {
            margin-bottom: 0;
        }

        /* Clean Box-Free Contact Layout */
        .clean-contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 40px;
            margin-top: 24px;
            text-align: center;
        }

        .contact-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 14px;
            padding: 8px;
            background: transparent;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            border-radius: 0;
            border: none;
            box-shadow: none;
        }

        .contact-person-role {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.8px;
            color: #002F6C;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: transparent;
            padding: 0;
            border-radius: 0;
            width: fit-content;
            margin: 0 auto;
        }

        .contact-person-title {
            font-size: 20px;
            font-weight: 800;
            color: #111111;
            letter-spacing: 0.2px;
            line-height: 1.3;
            text-align: center;
        }

        .contact-divider {
            width: 45px;
            height: 3px;
            background: linear-gradient(90deg, #E30613, #002F6C);
            border-radius: 3px;
            margin: 0 auto;
        }

        .contact-link-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-size: 15px;
            color: #111111;
            font-weight: 600;
        }

        .contact-link-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(0,47,108,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .contact-link-icon i {
            font-size: 13px;
            color: #002F6C;
        }

        .contact-link-row a {
            color: #002F6C;
            text-decoration: none;
            font-weight: 700;
            transition: color 0.2s;
        }
        .contact-link-row a:hover {
            color: #E30613;
            text-decoration: underline;
        }

        /* Site Footer Section */
        .page-site-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
            padding: 14px 24px;
            text-align: center;
            color: #111111;
            font-size: 12.5px;
            font-weight: 600;
            background: transparent;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            box-shadow: none;
            box-sizing: border-box;
            letter-spacing: 0.3px;
            text-shadow: none;
            z-index: 900;
        }
    </style>
</head>
<body>

<!-- ── Top Navigation Bar ── -->
<nav class="login-navbar" id="loginNavbar" role="navigation" aria-label="Main navigation">
    <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" onclick="document.getElementById('navLinks').classList.toggle('open')">
        <i class="fas fa-bars"></i>
    </button>
    <ul class="nav-links" id="navLinks">
        <li><a href="#home" class="active" id="nav-home" onclick="document.getElementById('navLinks').classList.remove('open')"><i class="fas fa-home"></i> Home</a></li>
        <li><a href="#about-us" id="nav-about" onclick="document.getElementById('navLinks').classList.remove('open')"><i class="fas fa-info-circle"></i> About Us</a></li>
        <li><a href="#contact-us" id="nav-contact" onclick="document.getElementById('navLinks').classList.remove('open')"><i class="fas fa-envelope"></i> Contact Us</a></li>
    </ul>
</nav>

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

<div class="page-sections-wrapper">

    <!-- ── SECTION 1: HOME (LOGIN) ── -->
    <section id="home" class="page-section section-home">
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

                <!-- Success Banner on Login -->
                <?php if ($login_success): ?>
                <div class="alert-success" role="alert" style="display:flex;align-items:center;justify-content:center;gap:10px;padding:16px 20px;font-size:15px;font-weight:700;margin: 24px 0 16px;">
                    <i class="fas fa-check-circle" style="font-size:18px;"></i>
                    <span>Login Successfully</span>
                </div>
                <p style="text-align:center;color:rgba(200,225,255,.85);font-size:13px;margin:16px 0 8px;">
                    <i class="fas fa-circle-notch fa-spin" style="margin-right:8px;"></i>Redirecting to your dashboard…
                </p>
                <script>
                setTimeout(function(){
                    window.location.href = <?= json_encode($dashboard_url) ?>;
                }, 1100);
                </script>
                <?php else: ?>

                <!-- Inactivity Timeout Banner -->
                <?php if (!empty($timeout_msg)): ?>
                <div class="alert-info" role="alert" style="display:flex;align-items:center;gap:12px;padding:14px 18px;background:rgba(0,47,108,0.55);border:1.5px solid rgba(147,197,253,0.6);border-radius:14px;color:#dbeafe;font-size:13.5px;font-weight:600;margin:16px 0;line-height:1.45;box-shadow:0 4px 14px rgba(0,0,0,0.25);">
                    <i class="fas fa-clock" style="font-size:18px;color:#93c5fd;flex-shrink:0;"></i>
                    <span><?php echo htmlspecialchars($timeout_msg); ?></span>
                </div>
                <?php endif; ?>

                <!-- Error Banner -->
                <?php if ($error): ?>
                <div class="alert-error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <!-- Success Banner -->
                <?php if ($success_msg): ?>
                <div class="alert-success" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" action="<?= htmlspecialchars($_login_base . '/public/login.php') ?>" id="loginForm" novalidate autocomplete="off">
                    <input type="hidden" name="csrf_token" id="csrfToken" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <!-- Honeypot: hidden from real users, bots fill this automatically -->
                    <div style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true" tabindex="-1">
                        <label for="_email_confirm">Email Confirm</label>
                        <input type="text" name="_email_confirm" id="_email_confirm" value="" tabindex="-1" autocomplete="off">
                    </div>

                    <!-- Account ID -->
                    <div class="form-group">
                        <div class="field-label">
                            <span>Account ID</span>
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
                                placeholder="Enter Password"
                                required
                                autocomplete="current-password"
                                aria-label="Password"
                            >
                            <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password visibility">
                                <i class="fas fa-eye" id="pwIcon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Math Captcha -->
                    <div class="form-group" style="margin-top:16px;">
                        <div class="captcha-box">
                            <div class="captcha-question" id="captchaQuestion">
                                <?= htmlspecialchars($captcha_question) ?>
                            </div>
                            <div class="captcha-equals">=</div>
                            <input
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                name="captcha"
                                id="captchaInput"
                                class="captcha-input"
                                required
                                maxlength="4"
                                autocomplete="off"
                                aria-label="Captcha Answer"
                                oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                            >
                            <button type="button" class="captcha-refresh" id="refreshCaptcha" title="Get new math question" aria-label="Refresh Captcha">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="form-actions" style="margin-top:20px;">
                        <button type="submit" class="btn-login" id="submitBtn">
                            <span class="spinner" id="spinner"></span>
                            <span id="btnText"><i class="fas fa-sign-in-alt" style="margin-right:6px;font-size:13px;"></i>Login</span>
                        </button>
                    </div>

                </form>

                <div class="login-footer">
                    <a href="<?= htmlspecialchars($_login_base . '/public/forgot_password.php') ?>">
                        <i class="fas fa-key" style="margin-right:5px;font-size:11px;opacity:.7;"></i>Forgot Password?
                    </a>
                </div>
                <?php endif; ?>
            </div><!-- /.login-card -->
        </div><!-- /.login-wrap -->
    </section>

    <!-- ── TEMPORARY LOCKOUT MODAL ── -->
    <div id="lockoutModal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.60);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);align-items:center;justify-content:center;">
        <div style="background:linear-gradient(165deg,rgba(60,0,0,0.97) 0%,rgba(90,10,10,0.98) 100%);border-radius:14px;width:86%;max-width:300px;padding:0;box-shadow:0 6px 28px rgba(0,0,0,0.7),0 0 0 1px rgba(255,80,80,0.28);animation:lockoutPop .3s cubic-bezier(0.34,1.56,0.64,1);">
            <!-- Header -->
            <div style="background:linear-gradient(135deg,rgba(170,15,15,0.9),rgba(110,0,0,0.95));padding:10px 16px;border-radius:14px 14px 0 0;border-bottom:1px solid rgba(255,80,80,0.18);display:flex;align-items:center;gap:8px;">
                <i class="fas fa-shield-alt" style="font-size:13px;color:#ff8080;"></i>
                <span style="font-size:12px;font-weight:700;color:#fff;letter-spacing:.4px;text-transform:uppercase;">Account Temporarily Locked</span>
            </div>
            <!-- Body -->
            <div style="padding:16px 18px 12px;text-align:center;">
                <div style="width:44px;height:44px;margin:0 auto 8px;background:rgba(220,40,40,0.15);border-radius:50%;border:1.5px solid rgba(255,80,80,0.28);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-lock" style="font-size:17px;color:#ff6b6b;"></i>
                </div>
                <p style="color:rgba(255,255,255,0.85);font-size:12px;line-height:1.5;margin:0 0 10px;">
                    Too many failed attempts.<br>Temporarily locked for security.
                </p>
                <!-- Countdown -->
                <div style="background:rgba(0,0,0,0.32);border-radius:9px;padding:8px 12px;border:1px solid rgba(255,80,80,0.16);margin-bottom:10px;">
                    <div style="font-size:10px;color:rgba(255,180,180,0.7);text-transform:uppercase;letter-spacing:.7px;margin-bottom:3px;">Please wait</div>
                    <div id="lockoutCountdown" style="font-size:30px;font-weight:800;color:#ff6b6b;line-height:1;text-shadow:0 0 10px rgba(255,80,80,0.4);">5</div>
                    <div style="font-size:10px;color:rgba(255,200,200,0.55);margin-top:2px;">seconds remaining</div>
                </div>
                <p style="margin:0;font-size:11px;color:rgba(255,200,200,0.6);line-height:1.45;">
                    <i class="fas fa-info-circle" style="margin-right:4px;opacity:.6;"></i>You may try again once the timer expires.
                </p>
            </div>
            <!-- Footer -->
            <div style="padding:8px 18px 14px;display:flex;justify-content:center;">
                <button id="lockoutOkBtn" disabled style="background:linear-gradient(135deg,#b91c1c,#dc2626);color:#fff;border:none;border-radius:8px;padding:7px 24px;font-size:12.5px;font-weight:600;cursor:not-allowed;opacity:.4;transition:all .3s;font-family:inherit;letter-spacing:.2px;">
                    <i class="fas fa-unlock" style="margin-right:5px;font-size:10px;"></i>Try Again
                </button>
            </div>
        </div>
    </div>
    <style>
        @keyframes lockoutPop {
            from { transform: scale(0.88) translateY(20px); opacity: 0; }
            to   { transform: scale(1) translateY(0);       opacity: 1; }
        }
    </style>
    <?php if ($is_locked_out): ?>
    <script>
    (function() {
        var remaining = <?= (int)$lockout_remaining_sec ?>;
        var modal = document.getElementById('lockoutModal');
        var countEl = document.getElementById('lockoutCountdown');
        var okBtn = document.getElementById('lockoutOkBtn');

        // Clear all form fields when locked out
        var accountInput = document.getElementById('accountId');
        var passwordInput = document.getElementById('passwordField');
        var captchaInput = document.getElementById('captchaInput');
        if (accountInput) accountInput.value = '';
        if (passwordInput) passwordInput.value = '';
        if (captchaInput) captchaInput.value = '';

        // Show modal immediately
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        function tick() {
            countEl.textContent = remaining;
            if (remaining <= 0) {
                clearInterval(timer);
                okBtn.disabled = false;
                okBtn.style.cursor = 'pointer';
                okBtn.style.opacity = '1';
                countEl.textContent = '0';
                countEl.style.color = '#4ade80';
            } else {
                remaining--;
            }
        }

        tick();
        var timer = setInterval(tick, 1000);

        okBtn.addEventListener('click', function() {
            if (!okBtn.disabled) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        });
    }());
    </script>
    <?php endif; ?>

    <!-- ── SECTION 2: ABOUT US ── -->
    <section id="about-us" class="page-section section-about">
        <div class="section-inner">
            <div class="section-badge reveal">
                <i class="fas fa-info-circle"></i> About Us
            </div>
            <h2 class="section-heading reveal reveal-delay-1">Petron Station Management System</h2>
            <div class="section-divider reveal reveal-delay-1"></div>
            <p class="about-body-p reveal reveal-delay-2">
                The Petron Station Management System is a web-based back-office management solution developed to support the daily operations of Petron Station &amp; Service Center – Vamenta Blvd., Carmen, Cagayan de Oro City, Misamis Oriental.
            </p>
            <p class="about-body-p reveal reveal-delay-2">
                The system provides a centralized platform for managing fuel operations, merchandise and job order transactions, inventory, customer records, reporting, approvals, notifications, and audit trails. It is designed to improve operational efficiency, record accuracy, accountability, and monitoring within the station.
            </p>
            <p class="about-body-p reveal reveal-delay-3">
                Currently implemented for one Petron franchise branch, the system follows a scalable and nationwide-ready design that can support future expansion to additional franchise branches.
            </p>
        </div>
    </section>

    <!-- ── SECTION 3: CONTACT US ── -->
    <section id="contact-us" class="page-section section-contact">
        <div class="section-inner">
            <div class="section-badge reveal">
                <i class="fas fa-envelope"></i> Contact Us
            </div>
            <div class="section-divider reveal reveal-delay-1"></div>

            <div class="clean-contact-grid">
                <!-- Admin / Owner -->
                <div class="contact-col reveal reveal-delay-2">
                    <div class="contact-person-role">
                        <i class="fas fa-user-shield"></i> Admin / Owner
                    </div>
                    <div class="contact-person-title">Romeca Katherine Jane Tello Pepito</div>
                    <div class="contact-divider"></div>
                    <div class="contact-link-row">
                        <div class="contact-link-icon"><i class="fas fa-envelope"></i></div>
                        <a href="mailto:romeca.katherine@gmail.com">romeca.katherine@gmail.com</a>
                    </div>
                    <div class="contact-link-row">
                        <div class="contact-link-icon"><i class="fas fa-phone-alt"></i></div>
                        <a href="tel:+639177918140">+63 917 791 8140</a>
                    </div>
                </div>

                <!-- Developer -->
                <div class="contact-col reveal reveal-delay-3">
                    <div class="contact-person-role">
                        <i class="fas fa-code"></i> Developer
                    </div>
                    <div class="contact-person-title">Christian Valencia</div>
                    <div class="contact-divider"></div>
                    <div class="contact-link-row">
                        <div class="contact-link-icon"><i class="fas fa-envelope"></i></div>
                        <a href="mailto:christianval0813@gmail.com">christianval0813@gmail.com</a>
                    </div>
                    <div class="contact-link-row">
                        <div class="contact-link-icon"><i class="fas fa-phone-alt"></i></div>
                        <a href="tel:+639288089251">+63 928 808 9251</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── SITE FOOTER ── -->
    <footer class="page-site-footer">
        &copy; <?= date('Y') ?> Petron Station Management System. All Rights Reserved.
    </footer>

</div><!-- /.page-sections-wrapper -->

<script>
(function () {
    var form       = document.getElementById('loginForm');
    if (!form) return;
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
    
    /* Calculate expected CAPTCHA answer from question text */
    function getExpectedAnswer() {
        if (!captchaQuestion) return null;
        var text = (captchaQuestion.textContent || '').trim();
        var matchAdd = text.match(/(\d+)\s*\+\s*(\d+)/);

        if (matchAdd) {
            return parseInt(matchAdd[1], 10) + parseInt(matchAdd[2], 10);
        }
        return null;
    }

    /* Real-time captcha validation */
    function validateCaptcha() {
        if (!captchaInput) return;
        // Strictly sanitize value to digits only
        var cleanVal = captchaInput.value.replace(/[^0-9]/g, '');
        if (captchaInput.value !== cleanVal) {
            captchaInput.value = cleanVal;
        }

        var answer = captchaInput.value.trim();
        if (!answer) {
            captchaInput.classList.remove('captcha-error', 'captcha-success');
            return;
        }
        
        var correctAnswer = getExpectedAnswer();
        if (correctAnswer !== null) {
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
    if (captchaInput) {
        captchaInput.addEventListener('input', validateCaptcha);
        captchaInput.addEventListener('blur', validateCaptcha);

        // Block non-numeric keystrokes strictly (disallows letters, symbols, spaces, negatives, punctuation)
        captchaInput.addEventListener('keydown', function (e) {
            var allowedControlKeys = [
                'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                'Home', 'End'
            ];
            if (allowedControlKeys.indexOf(e.key) !== -1 || e.ctrlKey || e.metaKey || e.altKey) {
                return;
            }
            // If not a digit 0-9, prevent typing
            if (!/^[0-9]$/.test(e.key)) {
                e.preventDefault();
            }
        });

        // Paste event: only accept numbers
        captchaInput.addEventListener('paste', function (e) {
            e.preventDefault();
            var pasteData = (e.clipboardData || window.clipboardData).getData('text');
            var numericOnly = (pasteData || '').replace(/[^0-9]/g, '');
            if (numericOnly) {
                var start = this.selectionStart || 0;
                var end = this.selectionEnd || 0;
                var currentVal = this.value;
                var finalVal = (currentVal.substring(0, start) + numericOnly + currentVal.substring(end)).slice(0, 4);
                this.value = finalVal;
                validateCaptcha();
            }
        });

        // Drop event: prevent non-numeric drop
        captchaInput.addEventListener('drop', function (e) {
            e.preventDefault();
        });
    }

    /* Live type detection */
    function detectType(val) {
        val = (val || '').trim();
        if (!val) return null;
        if (val.indexOf('@') !== -1) return 'email';
        if (/^\d{11}$/.test(val))   return 'phone';
        return 'username';
    }
    var typeLabels = { email: 'Email', phone: 'Phone', username: 'Username' };

    if (accountId) {
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
    }

    /* Password toggle */
    if (pwToggle && pwField) {
        pwToggle.addEventListener('click', function () {
            var isText = pwField.type === 'text';
            pwField.type = isText ? 'password' : 'text';
            if (pwIcon) pwIcon.className = isText ? 'fas fa-eye' : 'fas fa-eye-slash';
        });
    }

    /* Refresh CAPTCHA - Flexible & Auto-healing across idle sessions */
    function doRefreshCaptcha() {
        if (!refreshBtn || refreshBtn.disabled) return;
        
        refreshBtn.disabled = true;
        refreshBtn.classList.add('spinning');
        
        function applyNewCaptcha(data) {
            if (data && data.question) {
                captchaQuestion.textContent = data.question;
                captchaInput.value = '';
                captchaInput.classList.remove('captcha-error', 'captcha-success');
                if (data.csrf_token) {
                    var csrfInput = document.getElementById('csrfToken');
                    if (csrfInput) csrfInput.value = data.csrf_token;
                }
                captchaInput.focus();
                validateCaptcha();
            }
        }

        var baseUrl = '<?= htmlspecialchars($_login_base) ?>/public/refresh_captcha.php';
        var csrfVal = document.getElementById('csrfToken') ? document.getElementById('csrfToken').value : '';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', baseUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function () {
            var ok = false;
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response && response.success && response.question) {
                        applyNewCaptcha(response);
                        ok = true;
                    }
                } catch (e) {}
            }
            if (!ok) {
                // Fallback GET request if POST was blocked or session expired
                fetch(baseUrl + '?t=' + Date.now())
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.question) applyNewCaptcha(res);
                    })
                    .catch(function() {});
            }
            setTimeout(function () {
                refreshBtn.classList.remove('spinning');
                refreshBtn.disabled = false;
            }, 300);
        };
        
        xhr.onerror = function () {
            fetch(baseUrl + '?t=' + Date.now())
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res && res.question) applyNewCaptcha(res);
                })
                .catch(function() {});
            setTimeout(function () {
                refreshBtn.classList.remove('spinning');
                refreshBtn.disabled = false;
            }, 300);
        };
        
        xhr.send('refresh=1&csrf_token=' + encodeURIComponent(csrfVal));
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', doRefreshCaptcha);
    }

    /* Form submit */
    if (form) {
        form.addEventListener('submit', function (e) {
            var cVal = (captchaInput ? captchaInput.value : '').trim();
            var expected = getExpectedAnswer();
            if (!cVal || !/^\d+$/.test(cVal) || (expected !== null && parseInt(cVal, 10) !== expected)) {
                e.preventDefault();
                if (captchaInput) {
                    captchaInput.classList.remove('captcha-success');
                    captchaInput.classList.add('captcha-error');
                    captchaInput.focus();
                }
                return false;
            }
            submitBtn.disabled = true;
            if (spinner) spinner.style.display = 'none';
            btnText.innerHTML = '<i class="fas fa-circle-notch fa-spin" style="margin-right:6px;font-size:14px;"></i>Signing in\u2026';
        });
    }

    // If PHP returned an error (page reload), always reset the button
    // so the user is never stuck on "Authenticating..."
    window.addEventListener('pageshow', function () {
        if (submitBtn && submitBtn.disabled) {
            submitBtn.disabled = false;
            if (spinner) spinner.style.display = 'none';
            btnText.innerHTML = '<i class="fas fa-sign-in-alt" style="margin-right:6px;font-size:13px;"></i>Login';
        }
    });
}());
</script>

<script>
// ── Smooth Scroll Navigation Active Link Highlighter ──
document.addEventListener('DOMContentLoaded', function() {
    var navLinks = document.querySelectorAll('.nav-links a');
    var sections = document.querySelectorAll('.page-section');

    function changeActiveNav() {
        var index = sections.length;
        while(--index && window.scrollY + 120 < sections[index].offsetTop) {}
        navLinks.forEach(function(link) { link.classList.remove('active'); });
        if (navLinks[index]) navLinks[index].classList.add('active');
    }
    changeActiveNav();
    window.addEventListener('scroll', changeActiveNav);

    // ── Scroll Reveal (Intersection Observer) ──
    var reveals = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });
        reveals.forEach(function(el) { observer.observe(el); });
    } else {
        // Fallback: show all
        reveals.forEach(function(el) { el.classList.add('visible'); });
    }
});
</script>

</body>
</html>
