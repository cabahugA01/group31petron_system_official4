<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== DETAILED TRANSACTION DIAGNOSTICS ===\n";

try {
    $stmt = $pdo->query("
        SELECT id, transaction_type, job_order_service, job_order_id, job_order_db_id, customer_name, total_amount, created_at 
        FROM merchandise_transactions 
        WHERE transaction_type IN ('job_order', 'combined')
           OR NULLIF(TRIM(COALESCE(job_order_service, '')), '') IS NOT NULL
           OR job_order_id IS NOT NULL
           OR job_order_db_id IS NOT NULL
    ");
    echo "Potential Job Orders in merchandise_transactions:\n";
    $count = 0;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count++;
        echo "- ID: {$r['id']}, Type: {$r['transaction_type']}, Svc: '{$r['job_order_service']}', JO_ID: '{$r['job_order_id']}', JO_DB_ID: '{$r['job_order_db_id']}', Customer: {$r['customer_name']}, Amt: {$r['total_amount']}, Date: {$r['created_at']}\n";
    }
    if ($count === 0) {
        echo "No transactions match potential job order criteria.\n";
    }
} catch (Exception $e) {
    echo "Query failed: " . $e->getMessage() . "\n";
}
