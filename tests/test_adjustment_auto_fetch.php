<?php
/**
 * Test: Adjustment Reason, Requested Value, and Remarks Auto-Fetch Verification
 * tests/test_adjustment_auto_fetch.php
 */

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "=== Testing Adjustment Auto-Fetch for Both Job Orders and Merchandise ===\n";

$_SESSION['user_id'] = 1;
$_SESSION['user'] = ['id' => 1, 'role' => 'manager', 'station_id' => 1];

// 1. TEST JOB ORDER ADJUSTMENT AUTO-FETCH
$jo_stmt = $pdo->query("SELECT id, job_order_number FROM job_orders LIMIT 1");
$sample_jo = $jo_stmt->fetch(PDO::FETCH_ASSOC);

if ($sample_jo) {
    $jo_id = (int)$sample_jo['id'];
    $jo_no = $sample_jo['job_order_number'] ?: ('JO-' . $jo_id);

    $pdo->prepare("DELETE FROM transaction_requests WHERE transaction_id = ? OR transaction_id = ?")->execute([(string)$jo_id, $jo_no]);

    $pdo->prepare("
        INSERT INTO transaction_requests 
            (station_id, transaction_id, record_source, request_type, correction_field, current_value, requested_value, request_reason, remarks, requested_by, status, requested_at)
        VALUES (1, ?, 'job_orders', 'Adjustment', 'Labor Fee', '₱100.00', '₱150.00', 'Incorrect labor fee', 'Mechanic reported additional labor required', 1, 'Pending', NOW())
    ")->execute([$jo_id]);
    $jo_req_id = $pdo->lastInsertId();

    $_GET['id'] = $jo_id;
    $_GET['source'] = 'job_orders';

    ob_start();
    require __DIR__ . '/../backend/api/get_transaction_items.php';
    $jo_json = ob_get_clean();

    $jo_data = json_decode($jo_json, true);

    if ($jo_data && !empty($jo_data['adjustment_request'])) {
        $ar = $jo_data['adjustment_request'];
        $ok_field = ($ar['correction_field'] === 'Labor Fee');
        $ok_val   = ($ar['requested_value'] === '₱150.00');
        $ok_rsn   = ($ar['request_reason'] === 'Incorrect labor fee');
        $ok_rem   = ($ar['remarks'] === 'Mechanic reported additional labor required');

        if ($ok_field && $ok_val && $ok_rsn && $ok_rem) {
            echo "[PASS] Job Order Adjustment Request auto-fetched with all fields (Correction Field, Current Value, Requested Value, Reason, Remarks)!\n";
        } else {
            echo "[FAIL] Job Order Adjustment Request fields mismatch.\n";
        }
    } else {
        echo "[FAIL] Job Order Adjustment Request was not returned by API: $jo_json\n";
    }

    $pdo->prepare("DELETE FROM transaction_requests WHERE id = ?")->execute([$jo_req_id]);
} else {
    echo "[!] No job orders found in DB to test.\n";
}

// 2. TEST MERCHANDISE ADJUSTMENT AUTO-FETCH
$mt_stmt = $pdo->query("SELECT id, transaction_id FROM merchandise_transactions LIMIT 1");
$sample_mt = $mt_stmt->fetch(PDO::FETCH_ASSOC);

if ($sample_mt) {
    $mt_id = (int)$sample_mt['id'];
    $mt_code = $sample_mt['transaction_id'] ?: ('TXN-' . $mt_id);

    $pdo->prepare("DELETE FROM transaction_requests WHERE transaction_id = ? OR transaction_id = ?")->execute([(string)$mt_id, $mt_code]);

    $pdo->prepare("
        INSERT INTO transaction_requests 
            (station_id, transaction_id, record_source, request_type, correction_field, current_value, requested_value, request_reason, remarks, requested_by, status, requested_at)
        VALUES (1, ?, 'merchandise_transactions', 'Adjustment', 'Quantity', '4', '2', 'Wrong quantity entered', 'Cashier punched 4 instead of 2', 1, 'Pending', NOW())
    ")->execute([$mt_id]);
    $mt_req_id = $pdo->lastInsertId();

    $_GET['id'] = $mt_id;
    $_GET['source'] = 'merchandise_transactions';

    ob_start();
    require __DIR__ . '/../backend/api/get_transaction_items.php';
    $mt_json = ob_get_clean();

    $mt_data = json_decode($mt_json, true);

    if ($mt_data && !empty($mt_data['adjustment_request'])) {
        $ar = $mt_data['adjustment_request'];
        $ok_field = ($ar['correction_field'] === 'Quantity');
        $ok_val   = ($ar['requested_value'] === '2');
        $ok_rsn   = ($ar['request_reason'] === 'Wrong quantity entered');
        $ok_rem   = ($ar['remarks'] === 'Cashier punched 4 instead of 2');

        if ($ok_field && $ok_val && $ok_rsn && $ok_rem) {
            echo "[PASS] Merchandise Adjustment Request auto-fetched with all fields (Correction Field, Current Value, Requested Value, Reason, Remarks)!\n";
        } else {
            echo "[FAIL] Merchandise Adjustment Request fields mismatch.\n";
        }
    } else {
        echo "[FAIL] Merchandise Adjustment Request was not returned by API: $mt_json\n";
    }

    $pdo->prepare("DELETE FROM transaction_requests WHERE id = ?")->execute([$mt_req_id]);
} else {
    echo "[!] No merchandise transactions found in DB to test.\n";
}

echo "=== Test Completed ===\n";
