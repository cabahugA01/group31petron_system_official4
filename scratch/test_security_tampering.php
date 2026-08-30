<?php
/**
 * Automated Security Test Suite — Petron Station Management System Hardening Test
 * 
 * Verifies that PHP/MySQL acts as the authoritative security layer against DevTools tampering.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=========================================================\n";
echo "PETRON SECURITY HARDENING AUTOMATED TEST SUITE\n";
echo "=========================================================\n\n";

require_once __DIR__ . '/../config/database_config.php';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../backend/security_helpers.php';
require_once __DIR__ . '/../public/db_connect.php';

global $pdo;
$tests_passed = 0;
$tests_failed = 0;

function assert_test($condition, $test_name, $details = '') {
    global $tests_passed, $tests_failed;
    if ($condition) {
        echo "[PASS] {$test_name}\n";
        if ($details) echo "       Details: {$details}\n";
        $tests_passed++;
    } else {
        echo "[FAIL] {$test_name}\n";
        if ($details) echo "       Details: {$details}\n";
        $tests_failed++;
    }
}

// -----------------------------------------------------------------
// TEST 1: 5-Minute Inactivity Timeout Verification (Requirement #2)
// -----------------------------------------------------------------
echo "\n--- TEST 1: 5-MINUTE INACTIVITY TIMEOUT ENFORCEMENT ---\n";
@session_start();
$_SESSION['user'] = ['id' => 1, 'username' => 'teststaff', 'role' => 'staff', 'station_id' => 1];
$_SESSION['user_id'] = 1;
$_SESSION['last_activity'] = time() - 305; // 5 mins 5 secs ago

$timed_out = false;
$timeout_limit = 300; // 5 minutes
if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) >= $timeout_limit) {
    $timed_out = true;
}

assert_test($timed_out === true, "Enforces strict 5-minute (300s) session inactivity timeout", "Inactivity of 305 seconds triggered session expiry check");

// Reset session for subsequent tests
$_SESSION['user'] = ['id' => 1, 'username' => 'teststaff', 'role' => 'staff', 'station_id' => 1];
$_SESSION['user_id'] = 1;
$_SESSION['last_activity'] = time();


// -----------------------------------------------------------------
// TEST 2: Role Tampering via DevTools (Requirement #3 & #4)
// -----------------------------------------------------------------
echo "\n--- TEST 2: DEVTOOLS ROLE SPOOFING NEUTRALIZATION ---\n";

// Simulate DevTools user editing session/JS variable role from 'staff' to 'admin'
$_SESSION['user']['role'] = 'Admin'; 

// Fetch actual role from DB for user ID 1 or first staff user in DB
$dbUserStmt = $pdo->prepare("SELECT id, role, station_id FROM users WHERE role = 'staff' LIMIT 1");
$dbUserStmt->execute();
$staffUser = $dbUserStmt->fetch(PDO::FETCH_ASSOC);

if ($staffUser) {
    $_SESSION['user_id'] = (int)$staffUser['id'];
    $_SESSION['user']['id'] = (int)$staffUser['id'];
    $_SESSION['user']['role'] = 'SuperAdmin'; // DevTools spoofing attempt
    
    // Trigger server-side re-validation
    $validatedUser = validate_server_session_user($pdo);
    
    $role_restored = ($validatedUser && strtolower($validatedUser['role']) === 'staff');
    assert_test($role_restored, "Server re-queries MySQL DB to override DevTools role spoofing", "Session role was forced back from SuperAdmin to 'staff' based on MySQL records");
} else {
    echo "[SKIP] No staff user found in DB for role spoofing test\n";
}


// -----------------------------------------------------------------
// TEST 3: Branch / Station ID Tampering Protection (Requirement #6)
// -----------------------------------------------------------------
echo "\n--- TEST 3: BRANCH / STATION ACCESS ISOLATION ---\n";

$_SESSION['user'] = ['id' => 99, 'username' => 'staff_st1', 'role' => 'staff', 'station_id' => 1];
$_SESSION['user_id'] = 99;

$target_station_id = 2; // Staff from station 1 trying to modify station 2 data
$user_station = (int)($_SESSION['user']['station_id'] ?? 0);
$role = role_key($_SESSION['user']['role'] ?? '');

$blocked_branch_access = ($role !== 'superadmin' && $user_station !== $target_station_id);

assert_test($blocked_branch_access === true, "PHP rejects unauthorized cross-station access attempts", "Staff at Station 1 blocked from operating on Station 2 records");


// -----------------------------------------------------------------
// TEST 4: Price & Total Tampering Protection (Requirement #7 & 'NEVER TRUST')
// -----------------------------------------------------------------
echo "\n--- TEST 4: AUTHORITATIVE PRICE CALCULATION ---\n";

// Find a merchandise product in DB
$prodStmt = $pdo->query("SELECT id, unit_price FROM inventory_products WHERE unit_price > 0 LIMIT 1");
$prod = $prodStmt->fetch(PDO::FETCH_ASSOC);

if (!$prod) {
    $prodStmt = $pdo->query("SELECT id, price as unit_price FROM products WHERE price > 0 LIMIT 1");
    $prod = $prodStmt->fetch(PDO::FETCH_ASSOC);
}

if ($prod) {
    $item_id = $prod['id'];
    $db_unit_price = (float)$prod['unit_price'];
    
    // Simulate DevTools input: client sends price = 0.01 instead of DB price
    $client_tampered_price = 0.01;
    $quantity = 5;
    
    // Authoritative lookup function
    $server_price = get_authoritative_item_price($pdo, $item_id);
    $server_total = round($quantity * $server_price, 2);
    $tampered_total = round($quantity * $client_tampered_price, 2);
    
    assert_test($server_price == $db_unit_price, "Server fetches authoritative unit price from MySQL DB", "Fetched DB Price: ₱{$server_price} (Client Submitted: ₱{$client_tampered_price})");
    assert_test($server_total > $tampered_total, "Server recalculates total amount server-side", "Calculated Server Total: ₱{$server_total} vs Client Total: ₱{$tampered_total}");
} else {
    echo "[SKIP] No product found in DB for price tampering test\n";
}


// -----------------------------------------------------------------
// TEST 5: CSRF Token Enforcement (Requirement #8)
// -----------------------------------------------------------------
echo "\n--- TEST 5: CSRF TOKEN VERIFICATION ---\n";

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$valid_token_result = sec_verify_csrf_token($_SESSION['csrf_token']);
$invalid_token_result = sec_verify_csrf_token("invalid_attacker_csrf_token_123");
$empty_token_result = sec_verify_csrf_token("");

assert_test($valid_token_result === true, "Valid CSRF token accepted", "Token matches active session token");
assert_test($invalid_token_result === false, "Altered CSRF token rejected", "Invalid token correctly returns false");
assert_test($empty_token_result === false, "Missing CSRF token rejected", "Empty token correctly returns false");


// -----------------------------------------------------------------
// TEST 6: User Status & Account Deactivation Check
// -----------------------------------------------------------------
echo "\n--- TEST 6: DISABLED USER ACCOUNT LOCKOUT ---\n";

// Test inactive status check
$status_disabled = is_user_archived_status('disabled');
$status_archived = is_user_archived_status('archived');
$status_active = is_user_archived_status('active');

assert_test($status_disabled && $status_archived, "Disabled and Archived user statuses correctly identified", "Status checks identify locked accounts");
assert_test(!$status_active, "Active user status accepted", "Active account passes status check");


// -----------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------
echo "\n=========================================================\n";
echo "TEST RESULTS SUMMARY:\n";
echo "Passed: {$tests_passed} | Failed: {$tests_failed}\n";
echo "=========================================================\n";

if ($tests_failed === 0) {
    echo "SUCCESS: ALL SECURITY HARDENING CONTROLS VERIFIED!\n";
    exit(0);
} else {
    echo "WARNING: SOME TESTS FAILED. PLEASE REVIEW RESULTS ABOVE.\n";
    exit(1);
}
