<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "========================================================================\n";
echo "       GLOBAL INVENTORY MOVEMENT ENGINE — AUTOMATED TEST SUITE          \n";
echo "========================================================================\n\n";

$station_id = 1253;
$test_sku = 'TEST-ENG-' . time();
$test_prod_name = 'Test Engine Oil ' . time();

// 1. Create a dummy test product in inventory_products and station_inventory
$pdo->prepare("
    INSERT INTO inventory_products (product_name, sku, category, unit, cost_price, unit_price, stock, stock_quantity, created_at)
    VALUES (?, ?, 'Oil & Lubricants', 'pcs', 100.00, 150.00, 100, 100, NOW())
")->execute([$test_prod_name, $test_sku]);
$prod_id = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO station_inventory (station_id, product_id, stock_level, critical_level, reorder_level, unit, last_updated)
    VALUES (?, ?, 100, 10, 5, 'pcs', NOW())
")->execute([$station_id, $prod_id]);

function get_stock($pdo, $station_id, $prod_id) {
    $st = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE station_id = ? AND product_id = ?");
    $st->execute([$station_id, $prod_id]);
    return (float)$st->fetchColumn();
}

$passed = 0;
$total = 0;

function assert_test($condition, $test_name, $detail = '') {
    global $passed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "[PASS] $test_name " . ($detail ? "-> $detail" : "") . "\n";
    } else {
        echo "[FAIL] $test_name " . ($detail ? "-> $detail" : "") . "\n";
    }
}

// Initial Stock Check
assert_test(get_stock($pdo, $station_id, $prod_id) == 100, "Rule 0: Initial Stock Baseline", "Stock = 100");

// Rule 1: Customer buys 5 pcs -> Stock = 95, Movement: OUT
$txn_no_1 = 'TXN-TEST-' . time() . '-01';
$res1 = record_merchandise_sale_movement($pdo, $station_id, $prod_id, 5, $txn_no_1, 1);
$s1 = get_stock($pdo, $station_id, $prod_id);
assert_test($s1 == 95 && $res1['success'] && $res1['movement_type'] === 'OUT', "Rule 1: Customer Buys Merchandise (-5)", "Stock = $s1 (Expected 95)");

// Rule 2: Approved Stock-In 20 pcs -> Stock = 115, Movement: IN
$po_no_1 = 'PO-TEST-' . time() . '-01';
$res2 = record_stock_in_movement($pdo, $station_id, $prod_id, 20, $po_no_1, 1);
$s2 = get_stock($pdo, $station_id, $prod_id);
assert_test($s2 == 115 && $res2['success'] && $res2['movement_type'] === 'IN', "Rule 2: Approved Stock-In (+20)", "Stock = $s2 (Expected 115)");

// Rule 3: Approved Void of Transaction 1 -> Stock = 120, Movement: IN
$res3 = record_void_reversal_movement($pdo, $station_id, $prod_id, 5, $txn_no_1, 1);
$s3 = get_stock($pdo, $station_id, $prod_id);
assert_test($s3 == 120 && $res3['success'] && $res3['movement_type'] === 'IN', "Rule 3: Approved Void Reversal (+5)", "Stock = $s3 (Expected 120)");

// Rule 4: Duplicate Void Prevention -> Block second void on same txn_no_1
$res4 = record_void_reversal_movement($pdo, $station_id, $prod_id, 5, $txn_no_1, 1);
$s4 = get_stock($pdo, $station_id, $prod_id);
assert_test($s4 == 120 && !$res4['success'] && !empty($res4['already_reversed']), "Rule 4: Duplicate Void Prevention", "Blocked duplicate reversal, Stock remained $s4");

// Rule 5: Approved Adjustment -3 -> Stock = 117, Movement: OUT
$adj_no_1 = 'ADJ-TEST-' . time() . '-01';
$res5 = record_adjustment_movement($pdo, $station_id, $prod_id, -3, $adj_no_1, 1, 'Physical count deficit');
$s5 = get_stock($pdo, $station_id, $prod_id);
assert_test($s5 == 117 && $res5['success'] && $res5['movement_type'] === 'OUT', "Rule 5: Approved Adjustment (-3)", "Stock = $s5 (Expected 117)");

// Rule 6: Approved Adjustment +5 -> Stock = 122, Movement: IN
$adj_no_2 = 'ADJ-TEST-' . time() . '-02';
$res6 = record_adjustment_movement($pdo, $station_id, $prod_id, 5, $adj_no_2, 1, 'Found surplus batch');
$s6 = get_stock($pdo, $station_id, $prod_id);
assert_test($s6 == 122 && $res6['success'] && $res6['movement_type'] === 'IN', "Rule 6: Approved Adjustment (+5)", "Stock = $s6 (Expected 122)");

// Rule 7: Customer Return +3 -> Stock = 125, Movement: IN
$ret_no_1 = 'RET-TEST-' . time() . '-01';
$res7 = record_return_movement($pdo, $station_id, $prod_id, 3, $ret_no_1, 1, 'Customer unopened return');
$s7 = get_stock($pdo, $station_id, $prod_id);
assert_test($s7 == 125 && $res7['success'] && $res7['movement_type'] === 'IN', "Rule 7: Customer Return (+3)", "Stock = $s7 (Expected 125)");

// Rule 8: Check Inventory Movement Log History count
$stLogs = $pdo->prepare("SELECT COUNT(*) FROM inventory_logs WHERE product_id = ?");
$stLogs->execute([$prod_id]);
$logCount = (int)$stLogs->fetchColumn();
assert_test($logCount === 6, "Rule 8: Exactly 1 Movement Log Per Valid Action", "Total Logs Recorded = $logCount");

// Cleanup test product
$pdo->prepare("DELETE FROM inventory_logs WHERE product_id = ?")->execute([$prod_id]);
$pdo->prepare("DELETE FROM station_inventory WHERE product_id = ?")->execute([$prod_id]);
$pdo->prepare("DELETE FROM inventory_products WHERE id = ?")->execute([$prod_id]);

echo "\n========================================================================\n";
echo "SUMMARY: $passed / $total Tests Passed (" . round(($passed/$total)*100) . "%)\n";
echo "========================================================================\n";
