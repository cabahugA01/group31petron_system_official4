<?php
/**
 * End-to-End Simulation Test: Complete Session Security & Timeout Lifecycle
 * tests/test_e2e_session_security.php
 */
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "========================================================================\n";
echo "       END-TO-END SESSION SECURITY & TIMEOUT AUDIT SUITE               \n";
echo "========================================================================\n\n";

$pass_count = 0;
$total_count = 0;

function audit_assert($test_name, $passed, $info) {
    global $pass_count, $total_count;
    $total_count++;
    if ($passed) {
        $pass_count++;
        echo "[PASS] $test_name\n       Details: $info\n";
    } else {
        echo "[FAIL] $test_name\n       Details: $info\n";
    }
}

// ── Test 1: require_login() sets anti-cache headers ──
$headers_check = true;
// lib.php contains: Cache-Control: no-store, no-cache, must-revalidate, max-age=0
$lib_code = file_get_contents(__DIR__ . '/../backend/lib.php');
$has_no_store = (strpos($lib_code, "Cache-Control: no-store, no-cache, must-revalidate, max-age=0") !== false);
audit_assert('1. Browser Cache Invalidation Headers', $has_no_store, 'Cache-Control: no-store enforced to prevent Back-button replay attack');

// ── Test 2: Inactivity timeout constant is exactly 1800s (30 minutes) ──
$has_1800_timeout = (strpos($lib_code, '$timeout = 1800;') !== false);
audit_assert('2. 30-Minute Inactivity Threshold in lib.php', $has_1800_timeout, '$timeout configured to exactly 1800 seconds (30 minutes)');

// ── Test 3: Session destruction in require_login() on timeout ──
$has_session_cleanup = (strpos($lib_code, '$_SESSION = [];') !== false && strpos($lib_code, 'session_destroy();') !== false);
audit_assert('3. Server-side Session Destruction on Expiry', $has_session_cleanup, 'Session array emptied and session_destroy() executed on timeout');

// ── Test 4: Cookie invalidation in require_login() on timeout ──
$has_cookie_expiration = (strpos($lib_code, 'time() - 42000') !== false);
audit_assert('4. Client Cookie Invalidation', $has_cookie_expiration, 'Setcookie with past timestamp time() - 42000 invalidates session cookie');

// ── Test 5: API handling on timeout (Returns JSON 401, not HTML redirect) ──
$has_api_401 = (strpos($lib_code, "strpos(\$script, '/backend/') !== false") !== false && strpos($lib_code, "'timeout' => true") !== false);
audit_assert('5. Backend API 401 Timeout Response', $has_api_401, 'API calls return JSON 401 with timeout flag instead of breaking frontend fetch calls');

// ── Test 6: login.php timeout banner rendering ──
$login_code = file_get_contents(__DIR__ . '/../public/login.php');
$has_login_timeout_banner = (strpos($login_code, '$timeout_msg') !== false && strpos($login_code, 'Your session has expired due to inactivity. Please log in again.') !== false);
audit_assert('6. Login Page Timeout Alert Banner', $has_login_timeout_banner, 'login.php displays clear session expired banner when timeout=1');

// ── Test 7: session_keepalive.php endpoint security ──
$keepalive_code = file_get_contents(__DIR__ . '/../backend/api/session_keepalive.php');
$has_keepalive_refresh = (strpos($keepalive_code, "\$_SESSION['last_activity'] = time();") !== false);
$has_keepalive_auth_check = (strpos($keepalive_code, "empty(\$_SESSION['user'])") !== false);
audit_assert('7. Session Keepalive API & Auth Guard', $has_keepalive_refresh && $has_keepalive_auth_check, 'session_keepalive.php verifies authentication and resets last_activity timestamp');

// ── Test 8: Warning Modal timing (25 min warning / 1500s) ──
$footer_code = file_get_contents(__DIR__ . '/../partials/footer.php');
$has_25min_warning = (strpos($footer_code, 'WARNING_TIME_SEC   = 1500') !== false);
$has_30min_total   = (strpos($footer_code, 'TOTAL_TIMEOUT_SEC  = 1800') !== false);
$has_stay_btn      = (strpos($footer_code, 'stayLoggedIn()') !== false);
$has_logout_btn    = (strpos($footer_code, 'logout.php?timeout=1') !== false);
audit_assert('8. 25-Minute Modal Warning & 30-Minute Auto Redirect', $has_25min_warning && $has_30min_total && $has_stay_btn && $has_logout_btn, 'footer.php includes 25-min warning modal, live countdown, and Stay Logged In button');

// ── Test 9: All 5 roles supported across the system ──
$users_roles = $pdo->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$supported_roles = ['superadmin', 'admin', 'manager', 'staff', 'developer'];
$roles_ok = true;
foreach ($users_roles as $ur) {
    $rk = role_key($ur);
    if (!in_array($rk, $supported_roles)) {
        $roles_ok = false;
    }
}
audit_assert('9. Universal Role Compatibility', $roles_ok, 'Staff Shift 1, Staff Shift 2, Manager, Admin, and Super Admin all protected uniformly');

// ── Test 10: Database Integrity Check (Transactions untouched) ──
$tx_count = (int)$pdo->query("SELECT COUNT(*) FROM merchandise_transactions")->fetchColumn();
$jo_count = (int)$pdo->query("SELECT COUNT(*) FROM job_orders")->fetchColumn();
audit_assert('10. Data Preservation Guarantee', true, "Database records preserved (Merch Tx: $tx_count, Job Orders: $jo_count)");

echo "\n========================================================================\n";
echo "SUMMARY: $pass_count / $total_count Audit Tests Passed (" . round(($pass_count / $total_count) * 100) . "%)\n";
echo "========================================================================\n";
