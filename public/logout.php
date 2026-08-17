<?php
// session_start() MUST come before ob_start()
session_start();
ob_start(); // Buffer output to prevent "headers already sent" errors

// Auto Clock Out for staff roles on logout
$staff_roles = ['staff', 'cashier', 'pump_attendant'];
$user_role   = strtolower(trim($_SESSION['role'] ?? $_SESSION['user']['role'] ?? ''));
$user_id     = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;
$user_name   = $_SESSION['user']['name'] ?? $_SESSION['username'] ?? 'Unknown';

if ($user_id) {
    try {
        require_once __DIR__ . '/db_connect.php';

        $user_role_disp = ucfirst(strtolower($user_role ?: 'staff'));
        $logout_detail  = "{$user_name} ({$user_role_disp}) logged out";

        // Log logout to audit_logs
        $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
        if (!empty($tables)) {
            $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
                           VALUES (?, 'user', 'Logout', ?, 'users', ?, 'Success', ?, ?, NOW())")
                ->execute([
                    $user_id,
                    $logout_detail,
                    $user_id,
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                ]);
        }

        // Log logout to activity_logs
        $tables_act = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
        if (!empty($tables_act)) {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Logout', ?, ?)")
                ->execute([$user_id, $logout_detail, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
        }

        // Auto Clock Out for staff roles
        if (in_array($user_role, $staff_roles)) {
            $stmt = $pdo->prepare(
                "UPDATE labor_sessions
                 SET end_time = NOW(),
                     hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60, 2)
                 WHERE user_id = ? AND end_time IS NULL"
            );
            $stmt->execute([$user_id]);
            if ($stmt->rowCount() > 0) {
                if (!empty($tables_act)) {
                    $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Clock Out', 'Auto clock-out on logout', ?)")
                        ->execute([$user_id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                }
            }
        }
    } catch (Exception $e) { /* Fail silently, do not block logout */ }
}

// Clear all session variables
$_SESSION = [];

// Invalidate the session cookie in the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// Destroy the session on the server
session_destroy();

// Prevent browser/proxy from caching protected pages —
// critical so that browser Back button cannot bypass logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// Redirect to login page
$redirect_url = 'login.php';
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $redirect_url .= '?timeout=1';
}
header('Location: ' . $redirect_url);
exit;
?>
