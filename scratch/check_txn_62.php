<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "=== DB CHECK: transaction 62 ===\n";
    $stmt = $pdo->query("SELECT * FROM merchandise_transactions WHERE id = 62");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($row);
    
    echo "=== DB CHECK: merchandise_transaction_items for transaction 62 ===\n";
    $stmt = $pdo->query("SELECT * FROM merchandise_transaction_items WHERE transaction_id = 62");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($items);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
