<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== merchandise_transactions sample ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM merchandise_transactions LIMIT 3");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage()."\n"; }

echo "=== job_orders sample ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM job_orders LIMIT 3");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage()."\n"; }

echo "=== merchandise_transaction_items sample ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM merchandise_transaction_items LIMIT 3");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage()."\n"; }
