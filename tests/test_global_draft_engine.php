<?php
/**
 * Global Draft & Autosave Engine Automated Test Suite
 * tests/test_global_draft_engine.php
 */
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "========================================================================\n";
echo "       GLOBAL DRAFT & AUTOSAVE ENGINE — AUTOMATED TEST SUITE            \n";
echo "========================================================================\n\n";

$pass_count = 0;
$total_count = 0;

function draft_test($title, $condition, $details) {
    global $pass_count, $total_count;
    $total_count++;
    if ($condition) {
        $pass_count++;
        echo "[PASS] $title\n       -> $details\n";
    } else {
        echo "[FAIL] $title\n       -> $details\n";
    }
}

$station_id = 1253;
$valid_user = (int)$pdo->query("SELECT id FROM users WHERE status='Active' LIMIT 1")->fetchColumn() ?: 1;
$user1_id = $valid_user;
$user2_id = $valid_user + 999;
$sku = 'DRAFT-OIL-' . time();

$pdo->prepare("
    INSERT INTO inventory_products (product_name, sku, category, unit, cost_price, unit_price, stock, stock_quantity, created_at)
    VALUES ('Draft Test Engine Oil', ?, 'merchandise', 'pcs', 300.00, 450.00, 100, 100, NOW())
")->execute([$sku]);
$product_id = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO station_inventory (station_id, product_id, stock_level, critical_level, reorder_level, unit, last_updated)
    VALUES (?, ?, 100, 10, 15, 'pcs', NOW())
")->execute([$station_id, $product_id]);

// Initial stock
$initial_stock = (int)$pdo->query("SELECT stock_level FROM station_inventory WHERE station_id=$station_id AND product_id=$product_id")->fetchColumn();
$initial_tx_count = (int)$pdo->query("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=$station_id")->fetchColumn();

// ── TEST 1: Save Draft (NO Inventory deduction, NO sales created) ──
$draft_data = [
    'customer_name' => 'Juan Dela Cruz',
    'vehicle_plate' => 'ABC-1234',
    'product_id' => $product_id,
    'product_name' => 'Draft Test Engine Oil',
    'quantity' => 5,
    'price' => 450.00,
    'payment_method' => 'Cash'
];

$saved = save_user_draft($pdo, $user1_id, $station_id, 'merchandise_transaction', $draft_data);
$stock_after_draft = (int)$pdo->query("SELECT stock_level FROM station_inventory WHERE station_id=$station_id AND product_id=$product_id")->fetchColumn();
$tx_after_draft = (int)$pdo->query("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=$station_id")->fetchColumn();

draft_test(
    'Rule 1: Draft Storage without Business Side-Effects',
    $saved && ($stock_after_draft === $initial_stock) && ($tx_after_draft === $initial_tx_count),
    "Draft saved. Stock remained $stock_after_draft (0 deducted), Official Tx count remained $tx_after_draft"
);

// ── TEST 2: Accurate Draft Retrieval ──
$fetched_draft = get_user_draft($pdo, $user1_id, 'merchandise_transaction');
$data_matches = ($fetched_draft && 
                 $fetched_draft['data']['customer_name'] === 'Juan Dela Cruz' && 
                 $fetched_draft['data']['quantity'] == 5);

draft_test(
    'Rule 2: Form State Recovery & Integrity',
    $data_matches,
    "Draft retrieved: Customer = '{$fetched_draft['data']['customer_name']}', Qty = {$fetched_draft['data']['quantity']}"
);

// ── TEST 3: User & Shift Isolation ──
$user2_draft = get_user_draft($pdo, $user2_id, 'merchandise_transaction');
draft_test(
    'Rule 3: User & Session Isolation',
    $user2_draft === null,
    "User $user2_id has 0 drafts (User $user1_id's draft is completely isolated)"
);

// ── TEST 4: Draft Discard Action ──
$discarded = discard_user_draft($pdo, $user1_id, 'merchandise_transaction');
$after_discard = get_user_draft($pdo, $user1_id, 'merchandise_transaction');
draft_test(
    'Rule 4: Explicit Discard Action',
    $discarded && ($after_discard === null),
    'Draft discarded successfully. Form state reset.'
);

// ── TEST 5: Final Submission -> Business Execution & Auto-Clear ──
// Save draft again
save_user_draft($pdo, $user1_id, $station_id, 'merchandise_transaction', $draft_data);

// Simulate final official submit with inventory deduction
$res_sale = record_merchandise_sale_movement($pdo, $station_id, $product_id, 5, 'TXN-DRAFT-FINAL', $user1_id);

// Clear draft on successful final submit
clear_user_draft($pdo, $user1_id, 'merchandise_transaction');

$final_stock = (int)$pdo->query("SELECT stock_level FROM station_inventory WHERE station_id=$station_id AND product_id=$product_id")->fetchColumn();
$final_draft = get_user_draft($pdo, $user1_id, 'merchandise_transaction');

draft_test(
    'Rule 5: Official Submit -> Inventory Movement + Draft Cleanup',
    ($final_stock === 95) && ($final_draft === null) && ($res_sale['success'] === true),
    "Final submission processed: Stock = $final_stock (5 deducted), Draft cleared from database"
);

// Cleanup test product
$pdo->prepare("DELETE FROM inventory_logs WHERE product_id = ?")->execute([$product_id]);
$pdo->prepare("DELETE FROM station_inventory WHERE product_id = ?")->execute([$product_id]);
$pdo->prepare("DELETE FROM inventory_products WHERE id = ?")->execute([$product_id]);
$pdo->prepare("DELETE FROM user_form_drafts WHERE user_id IN (?, ?)")->execute([$user1_id, $user2_id]);

echo "\n========================================================================\n";
echo "SUMMARY: $pass_count / $total_count Draft Engine Tests Passed (" . round(($pass_count / $total_count) * 100) . "%)\n";
echo "========================================================================\n";
