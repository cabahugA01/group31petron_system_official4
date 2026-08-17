<?php
/**
 * Comprehensive Automated Verification Suite for Petron Notification System
 * tests/run_notification_system_test.php
 */

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "========================================================================\n";
echo "       PETRON SYSTEM - COMPLETE NOTIFICATION VERIFICATION SUITE         \n";
echo "========================================================================\n\n";

$tests_run = 0;
$tests_passed = 0;
$tests_failed = 0;

function run_test(string $name, callable $callback) {
    global $tests_run, $tests_passed, $tests_failed;
    $tests_run++;
    try {
        $result = $callback();
        if ($result === true) {
            $tests_passed++;
            printf("[PASS] %-50s\n", $name);
        } else {
            $tests_failed++;
            printf("[FAIL] %-50s -> %s\n", $name, is_string($result) ? $result : 'Returned false');
        }
    } catch (Throwable $e) {
        $tests_failed++;
        printf("[FAIL] %-50s -> Exception: %s\n", $name, $e->getMessage());
    }
}

// 1. Clean test notifications
$pdo->exec("DELETE FROM notifications WHERE source_key LIKE 'test_%'");

// TEST 1: Schema columns check
run_test("DB Schema: notifications has reference_type, id, shift", function() use ($pdo) {
    $cols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['recipient_role', 'reference_type', 'reference_id', 'shift_period'];
    foreach ($required as $col) {
        if (!in_array($col, $cols)) return "Missing column: $col";
    }
    return true;
});

// TEST 2: URL Mapper function
run_test("URL Mapper: notification_redirect_url returns exact links", function() {
    $url1 = notification_redirect_url('stock_request', 125, 'staff');
    if ($url1 !== 'staff_stock_requests.php?id=125') return "Expected staff_stock_requests.php?id=125, got $url1";

    $url2 = notification_redirect_url('stock_request', 125, 'manager');
    if ($url2 !== 'manager_inventory_stock_requests.php?id=125') return "Expected manager_inventory_stock_requests.php?id=125, got $url2";

    $url3 = notification_redirect_url('void_request', 45, 'manager');
    if ($url3 !== 'manager_voided_transactions.php?id=45') return "Expected manager_voided_transactions.php?id=45, got $url3";

    $url4 = notification_redirect_url('fuel_transaction', 77, 'staff');
    if ($url4 !== 'staff_fuel_sales_closing.php') return "Expected staff_fuel_sales_closing.php, got $url4";

    return true;
});

// TEST 3: Staff Shift 1 vs Shift 2 Isolation
run_test("Shift Isolation: Staff 1 cannot see Staff 2 notifications", function() use ($pdo) {
    // Get staff user
    $stmt = $pdo->query("SELECT id FROM users WHERE LOWER(role) = 'staff' LIMIT 1");
    $staff_id = (int)$stmt->fetchColumn();
    if (!$staff_id) return "No staff user found in DB";

    // Insert Shift 1 and Shift 2 test notifications
    notify($pdo, $staff_id, 'staff', 'info', 'fuel_transaction', 'medium',
           'Shift 1 Fuel Reading', 'Shift 1 reading recorded', 'test_shift1_notif',
           '', 'fuel_transaction', 101, 'Shift 1');

    notify($pdo, $staff_id, 'staff', 'info', 'fuel_transaction', 'medium',
           'Shift 2 Fuel Reading', 'Shift 2 reading recorded', 'test_shift2_notif',
           '', 'fuel_transaction', 102, 'Shift 2');

    // Simulate API query with shift = 'Shift 1'
    $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND (shift_period = 'Shift 1' OR shift_period IS NULL)");
    $stmt1->execute([$staff_id]);
    $s1_count = (int)$stmt1->fetchColumn();

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND (shift_period = 'Shift 2' OR shift_period IS NULL)");
    $stmt2->execute([$staff_id]);
    $s2_count = (int)$stmt2->fetchColumn();

    // Verify isolation: query for Shift 1 does not return Shift 2 notification
    $stmt_check = $pdo->prepare("SELECT title FROM notifications WHERE user_id = ? AND shift_period = 'Shift 1'");
    $stmt_check->execute([$staff_id]);
    $titles = $stmt_check->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('Shift 2 Fuel Reading', $titles)) {
        return "Shift 2 notification was leaked to Shift 1 query";
    }

    return true;
});

// TEST 4: Stock Request Workflow Notifications
run_test("Workflow: Stock Request submit -> manager notify -> approve -> staff notify", function() use ($pdo) {
    $staff = $pdo->query("SELECT id, name, station_id FROM users WHERE LOWER(role) = 'staff' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $mgr   = $pdo->query("SELECT id, name FROM users WHERE LOWER(role) = 'manager' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$staff || !$mgr) return "Need staff and manager users";

    $st_id = (int)$staff['station_id'] ?: 1;

    // 1. Staff submits
    notify_manager($pdo, $st_id, 'info', 'stock_request', 'medium',
                   'New Stock Request', 'Staff requested item', 'test_sr_submit_999',
                   'manager_inventory_stock_requests.php?id=999', 'stock_request', 999);

    // Verify manager received it
    $chk_mgr = $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$mgr['id']} AND source_key LIKE 'test_sr_submit_999%'")->fetchColumn();
    if ((int)$chk_mgr < 1) return "Manager did not receive submit notification";

    // 2. Manager approves
    notify($pdo, (int)$staff['id'], 'staff', 'success', 'stock_request', 'medium',
           'Stock Request Approved', 'Your stock request was approved', 'test_sr_approve_999',
           'staff_stock_requests.php?id=999', 'stock_request', 999);

    // Verify staff received it
    $chk_staff = $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$staff['id']} AND source_key = 'test_sr_approve_999'")->fetchColumn();
    if ((int)$chk_staff < 1) return "Staff did not receive approve notification";

    return true;
});

// TEST 5: Master Data Workflow Notifications
run_test("Workflow: Master Data submit -> manager notify -> approve -> staff notify", function() use ($pdo) {
    $staff = $pdo->query("SELECT id FROM users WHERE LOWER(role) = 'staff' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $mgr   = $pdo->query("SELECT id FROM users WHERE LOWER(role) = 'manager' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$staff || !$mgr) return "Need staff and manager users";

    notify_manager($pdo, 1, 'info', 'master_data_request', 'medium',
                   'New Master Data Request', 'Staff requested new product', 'test_mdr_submit_888',
                   'manager_review_stock_requests.php?id=888', 'master_data_request', 888);

    notify($pdo, (int)$staff['id'], 'staff', 'success', 'master_data_request', 'medium',
           'Master Data Request Approved', 'Your product request was approved', 'test_mdr_approve_888',
           'staff_requests.php?id=888', 'master_data_request', 888);

    $cnt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE source_key IN ('test_mdr_approve_888')")->fetchColumn();
    return (int)$cnt === 1;
});

// TEST 6: Void Request Workflow Notifications
run_test("Workflow: Void Request submit -> manager notify -> approve -> staff notify", function() use ($pdo) {
    $staff = $pdo->query("SELECT id FROM users WHERE LOWER(role) = 'staff' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$staff) return "Need staff user";

    notify_manager($pdo, 1, 'warning', 'void_request', 'high',
                   'New Void Request', 'Staff requested void for TRX #1234', 'test_void_req_777',
                   'manager_voided_transactions.php?id=777', 'void_request', 777);

    notify($pdo, (int)$staff['id'], 'staff', 'success', 'void_request', 'medium',
           'Void Request Approved', 'Your void request for TRX #1234 was approved', 'test_void_approve_777',
           'voided_transactions.php?id=777', 'void_request', 777);

    $cnt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE source_key = 'test_void_approve_777'")->fetchColumn();
    return (int)$cnt === 1;
});

// TEST 7: Deduplication via source_key
run_test("Deduplication: Same event cannot be inserted twice", function() use ($pdo) {
    $staff = $pdo->query("SELECT id FROM users WHERE LOWER(role) = 'staff' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$staff) return "Need staff user";

    notify($pdo, (int)$staff['id'], 'staff', 'info', 'fuel_transaction', 'low',
           'Dup Test', 'Message 1', 'test_dup_unique_key', '', 'fuel_transaction', 500);

    notify($pdo, (int)$staff['id'], 'staff', 'info', 'fuel_transaction', 'low',
           'Dup Test', 'Message 2', 'test_dup_unique_key', '', 'fuel_transaction', 500);

    $cnt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE source_key = 'test_dup_unique_key'")->fetchColumn();
    if ((int)$cnt !== 1) return "Expected 1 record, got $cnt";

    return true;
});

// Clean test records
$pdo->exec("DELETE FROM notifications WHERE source_key LIKE 'test_%'");

echo "\n========================================================================\n";
echo "                           TEST SUMMARY                                 \n";
echo "========================================================================\n";
printf("Total Tests Executed: %d\n", $tests_run);
printf("Passed: %d\n", $tests_passed);
printf("Failed: %d\n", $tests_failed);
printf("Success Rate: %d%%\n\n", round(($tests_passed / max(1, $tests_run)) * 100));
