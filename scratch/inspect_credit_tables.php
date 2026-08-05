<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== customer_credit_transactions ===\n";
try {
    $stmt = $pdo->query("DESCRIBE customer_credit_transactions");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    $rows = $pdo->query("SELECT * FROM customer_credit_transactions LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "Rows (" . count($rows) . "):\n";
    print_r($rows);
} catch (Exception $e) { echo $e->getMessage()."\n"; }

echo "=== customer_ledger ===\n";
try {
    $stmt = $pdo->query("DESCRIBE customer_ledger");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    $rows = $pdo->query("SELECT * FROM customer_ledger LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "Rows (" . count($rows) . "):\n";
    print_r($rows);
} catch (Exception $e) { echo $e->getMessage()."\n"; }
