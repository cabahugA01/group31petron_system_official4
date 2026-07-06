<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "<pre>";

// Check if the voided transaction's items still exist
$s = $pdo->query("SELECT id, transaction_id FROM merchandise_transactions ORDER BY id DESC LIMIT 10");
$mts = $s->fetchAll(PDO::FETCH_ASSOC);
echo "=== merchandise_transactions IDs ===\n";
foreach ($mts as $m) { echo "id={$m['id']} txn_id={$m['transaction_id']}\n"; }

// Check voided_transactions
$s2 = $pdo->query("SELECT id, transaction_id, amount FROM voided_transactions ORDER BY id DESC LIMIT 5");
$vts = $s2->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== voided_transactions ===\n";
foreach ($vts as $v) { echo "id={$v['id']} txn_id={$v['transaction_id']} amount={$v['amount']}\n"; }

// Cross-check: does the voided transaction_id exist in merchandise_transactions?
foreach ($vts as $v) {  $tid = $v['transaction_id'];  $s3 = $pdo->prepare("SELECT id FROM merchandise_transactions WHERE transaction_id = ?");  $s3->execute([$tid]);  $found = $s3->fetch();  echo "Voided TXN $tid exists in merchandise_transactions: " . ($found ? "YES (id={$found['id']})" : "NO") . "\n";  if ($found) {  $s4 = $pdo->prepare("SELECT product_name, quantity, unit_price, item_type FROM merchandise_transaction_items WHERE transaction_id = ?");  $s4->execute([$found['id']]);  $items = $s4->fetchAll(PDO::FETCH_ASSOC);  echo "  Items: " . count($items) . "\n";  foreach ($items as $it) { echo "  -> {$it['product_name']} qty={$it['quantity']} price={$it['unit_price']}\n"; }  }
}
echo "</pre>";
?>
