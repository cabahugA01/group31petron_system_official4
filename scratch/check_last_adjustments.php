<?php
require __DIR__ . '/../public/db_connect.php';

$rows = $pdo->query("SELECT id, transaction_id, transaction_type, original_amount, updated_amount, fields_changed FROM transaction_adjustments ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "=== Last 10 adjustments ===\n";
foreach ($rows as $r) {
    echo "ID: " . $r['id'] . " | Txn ID: " . $r['transaction_id'] . " | Type: " . $r['transaction_type'] . "\n";
    echo "Original: " . $r['original_amount'] . " | Updated: " . $r['updated_amount'] . "\n";
    echo "Fields changed: " . $r['fields_changed'] . "\n";
    echo "----------------------------------------\n";
}
