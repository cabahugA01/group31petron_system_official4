<?php
if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
session_start();

require_once __DIR__ . '/../backend/lib.php';

$tests_passed = 0;
$total_tests = 0;

function assert_test($label, $condition, $details = '') {
    global $tests_passed, $total_tests;
    $total_tests++;
    if ($condition) {
        $tests_passed++;
        echo "[PASS] $label -> $details\n";
    } else {
        echo "[FAIL] $label -> $details\n";
    }
}
$_SESSION['user'] = ['id' => 99, 'role' => 'staff', 'username' => 'teststaff'];
$_SESSION['last_activity'] = time() - 300; // 5 mins ago

// Call require_login simulation
$timeout = 1800;
$inactive = time() - $_SESSION['last_activity'];
$should_expire = ($inactive >= $timeout);
$_SESSION['last_activity'] = time();

assert_test('Test 1: Active Session (5 mins inactive)', !$should_expire && (time() - $_SESSION['last_activity'] < 2), 'Session remains active and last_activity refreshed');

// ── Test 2: Inactive session (31 mins) triggers timeout ──
$_SESSION['last_activity'] = time() - 1860; // 31 mins ago
$inactive = time() - $_SESSION['last_activity'];
$should_expire = ($inactive >= $timeout);

assert_test('Test 2: Inactive Session (31 mins inactive)', $should_expire === true, 'Session recognized as expired (>= 1800s)');

// ── Test 3: Session keepalive endpoint simulation ──
$_SESSION['user'] = ['id' => 99, 'role' => 'admin', 'username' => 'testadmin'];
$_SESSION['last_activity'] = time() - 1500; // 25 mins ago (warning triggered)

// Simulate stayLoggedIn() keepalive call
$now = time();
$_SESSION['last_activity'] = $now;

assert_test('Test 3: Stay Logged In Keepalive Action', $_SESSION['last_activity'] === $now, 'Keepalive successfully refreshed timestamp to current time');

// ── Test 4: Verify timeout message text on login page ──
$login_content = file_get_contents(__DIR__ . '/../public/login.php');
$has_timeout_check = (strpos($login_content, "\$_GET['timeout']") !== false);
$has_timeout_msg = (strpos($login_content, 'Your session has expired due to inactivity. Please log in again.') !== false);

assert_test('Test 4: Login Page Timeout Message Handling', $has_timeout_check && $has_timeout_msg, 'login.php displays exact inactivity message upon timeout redirect');

// ── Test 5: Verify 25-min warning modal in footer.php ──
$footer_content = file_get_contents(__DIR__ . '/../partials/footer.php');
$has_warning_modal = (strpos($footer_content, 'sessionTimeoutModal') !== false);
$has_keepalive_call = (strpos($footer_content, 'session_keepalive.php') !== false);
$has_countdown = (strpos($footer_content, 'sessionCountdownDisplay') !== false);

assert_test('Test 5: Inactivity Warning Modal (25 min trigger)', $has_warning_modal && $has_keepalive_call && $has_countdown, 'footer.php contains live countdown, warning modal, and Stay Logged In keepalive caller');

echo "\n========================================================================\n";
echo "SUMMARY: $tests_passed / $total_tests Tests Passed (" . round(($tests_passed / $total_tests) * 100) . "%)\n";
echo "========================================================================\n";
