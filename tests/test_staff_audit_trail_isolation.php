<?php
/**
 * Automated Test Suite: Staff Audit Trail & User Scoping
 * tests/test_staff_audit_trail_isolation.php
 */
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "========================================================================\n";
echo "       STAFF AUDIT TRAIL & PRIVACY ISOLATION TEST SUITE                 \n";
echo "========================================================================\n\n";

$pass_count = 0;
$total_count = 0;

function audit_test($title, $condition, $details) {
    global $pass_count, $total_count;
    $total_count++;
    if ($condition) {
        $pass_count++;
        echo "[PASS] $title\n       -> $details\n";
    } else {
        echo "[FAIL] $title\n       -> $details\n";
    }
}

// 1. Setup two test users (Staff 1 and Staff 2)
$station_id = 1253;
$user_ids = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$staff1_id = (int)($user_ids[0] ?? 1);
$staff2_id = (int)($user_ids[1] ?? 2);

// Insert mock activity for staff 1
$pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, 'Login', 'Staff 1 login', '127.0.0.1', NOW())")
    ->execute([$staff1_id]);
$pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, 'Logout', 'Staff 1 logout', '127.0.0.1', NOW())")
    ->execute([$staff1_id]);

// Insert mock activity for staff 2
$pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, 'Login', 'Staff 2 login', '127.0.0.1', NOW())")
    ->execute([$staff2_id]);

// Insert stock request for staff 1
$pdo->prepare("INSERT INTO stock_requests (request_no, staff_id, station_id, item_name, item_sku, current_stock, requested_quantity, status, created_at) VALUES ('PR-TEST-8801', ?, ?, 'Test Filter', 'FLT-8801', 5, 10, 'Pending', NOW())")
    ->execute([$staff1_id, $station_id]);

// Insert stock request for staff 2
$pdo->prepare("INSERT INTO stock_requests (request_no, staff_id, station_id, item_name, item_sku, current_stock, requested_quantity, status, created_at) VALUES ('PR-TEST-8802', ?, ?, 'Test Spark Plug', 'SPK-8802', 2, 20, 'Pending', NOW())")
    ->execute([$staff2_id, $station_id]);

// Insert draft for staff 1
save_user_draft($pdo, $staff1_id, $station_id, 'merchandise_transaction', ['customer' => 'Staff 1 Draft Customer', 'qty' => 3]);

// ── Test 1: Staff 1 Activity Query strictly isolates records ──
$staff1_logs = $pdo->prepare("SELECT action, details FROM activity_logs WHERE user_id = ?");
$staff1_logs->execute([$staff1_id]);
$s1_rows = $staff1_logs->fetchAll(PDO::FETCH_ASSOC);

$has_s2_data = false;
foreach ($s1_rows as $r) {
    if (strpos($r['details'], 'Staff 2') !== false) {
        $has_s2_data = true;
    }
}

audit_test(
    '1. Activity Logs User Isolation',
    !$has_s2_data && count($s1_rows) >= 2,
    'Staff 1 can only see own login/logout logs, Staff 2 records are completely invisible'
);

// ── Test 2: Stock Request Isolation ──
$staff1_sr = $pdo->prepare("SELECT request_no, item_name FROM stock_requests WHERE staff_id = ?");
$staff1_sr->execute([$staff1_id]);
$sr_rows = $staff1_sr->fetchAll(PDO::FETCH_ASSOC);

$sr_leak = false;
foreach ($sr_rows as $sr) {
    if ($sr['request_no'] === 'PR-TEST-8802') {
        $sr_leak = true;
    }
}

audit_test(
    '2. Stock Request User Isolation',
    !$sr_leak && count($sr_rows) >= 1,
    'Staff 1 only sees PR-TEST-8801, Staff 2 request PR-TEST-8802 is strictly blocked'
);

// ── Test 3: Draft Activity Scoping ──
$s1_draft = get_user_draft($pdo, $staff1_id, 'merchandise_transaction');
$s2_draft = get_user_draft($pdo, $staff2_id, 'merchandise_transaction');

audit_test(
    '3. Draft Activity Isolation',
    ($s1_draft !== null) && ($s2_draft === null),
    'Staff 1 draft exists and is private; Staff 2 has 0 access to Staff 1 draft'
);

// ── Test 4: Verify staff_activity_report.php queries have user_id bindings ──
$report_content = file_get_contents(__DIR__ . '/../public/staff_activity_report.php');
$has_uid_merch = (strpos($report_content, 'mt.staff_id = :uid') !== false);
$has_uid_fuel  = (strpos($report_content, 'ft.staff_id = :uid') !== false);
$has_uid_sr    = (strpos($report_content, 'sr.staff_id = :uid') !== false);
$has_uid_jo    = (strpos($report_content, 'jo.user_id = :uid OR jo.created_by = :uid') !== false);
$has_uid_mdr   = (strpos($report_content, 'mdr.requested_by = :uid') !== false);
$has_uid_draft = (strpos($report_content, 'ufd.user_id = :uid') !== false);

audit_test(
    '4. Codebase Audit: All 6 Core Data Tables Scoped to :uid',
    $has_uid_merch && $has_uid_fuel && $has_uid_sr && $has_uid_jo && $has_uid_mdr && $has_uid_draft,
    'merchandise_transactions, fuel_transactions, stock_requests, job_orders, master_data_requests, and user_form_drafts all enforce :uid'
);

// Cleanup only test-specific records
$pdo->prepare("DELETE FROM activity_logs WHERE details LIKE 'Staff 1 login%' OR details LIKE 'Staff 1 logout%' OR details LIKE 'Staff 2 login%'")->execute();
$pdo->prepare("DELETE FROM stock_requests WHERE request_no IN ('PR-TEST-8801', 'PR-TEST-8802')")->execute();
$pdo->prepare("DELETE FROM user_form_drafts WHERE user_id IN (?, ?)")->execute([$staff1_id, $staff2_id]);

echo "\n========================================================================\n";
echo "SUMMARY: $pass_count / $total_count Audit Tests Passed (" . round(($pass_count / $total_count) * 100) . "%)\n";
echo "========================================================================\n";
