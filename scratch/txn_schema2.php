<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== transaction_adjustments ===\n";
$cols = $pdo->query('DESCRIBE transaction_adjustments')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}  default={$c['Default']}\n";

echo "\n=== voided_transactions ===\n";
try {
    $cols = $pdo->query('DESCRIBE voided_transactions')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}  default={$c['Default']}\n";
} catch (Exception $e) { echo "ERROR: ".$e->getMessage()."\n"; }

echo "\n=== Sample merchandise_transactions rows ===\n";
$rows = $pdo->query('SELECT id, transaction_id, transaction_type, validation_status, workflow_status, customer_name, total_amount FROM merchandise_transactions ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  id={$r['id']} txn_id={$r['transaction_id']} type={$r['transaction_type']} val={$r['validation_status']} wf={$r['workflow_status']} cust={$r['customer_name']} total={$r['total_amount']}\n";
}

echo "\n=== Sample transaction_adjustments rows ===\n";
try {
    $rows = $pdo->query('SELECT * FROM transaction_adjustments ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) echo "  (empty)\n";
    foreach ($rows as $r) echo "  ".json_encode($r)."\n";
} catch (Exception $e) { echo "ERROR: ".$e->getMessage()."\n"; }

echo "\n=== Sample voided_transactions rows ===\n";
try {
    $rows = $pdo->query('SELECT * FROM voided_transactions ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) echo "  (empty)\n";
    foreach ($rows as $r) echo "  ".json_encode($r)."\n";
} catch (Exception $e) { echo "ERROR: ".$e->getMessage()."\n"; }
