<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== job_orders structure ===\n";
try {
    $stmt = $pdo->query("DESCRIBE job_orders");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    
    $count = $pdo->query("SELECT COUNT(*) FROM job_orders")->fetchColumn();
    echo "Count in job_orders table: $count\n";
    if ($count > 0) {
        $stmt = $pdo->query("SELECT * FROM job_orders LIMIT 3");
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    echo $e->getMessage()."\n";
}

echo "=== merchandise_transactions with transaction_type = 'job_order' ===\n";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM merchandise_transactions WHERE LOWER(transaction_type) = 'job_order' OR LOWER(transaction_type) = 'service'")->fetchColumn();
    echo "Count in merchandise_transactions (job_order/service): $count\n";
    if ($count > 0) {
        $stmt = $pdo->query("SELECT * FROM merchandise_transactions WHERE LOWER(transaction_type) = 'job_order' OR LOWER(transaction_type) = 'service' LIMIT 2");
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    echo $e->getMessage()."\n";
}
