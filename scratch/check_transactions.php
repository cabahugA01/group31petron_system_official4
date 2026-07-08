<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "=== DB CHECK: merchandise_transactions ===\n";
    $stmt = $pdo->query("SELECT id, total_amount, transaction_type, job_order_service, job_order_id, job_order_db_id, created_at FROM merchandise_transactions LIMIT 20");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No transactions found.\n";
    } else {
        print_r($rows);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
