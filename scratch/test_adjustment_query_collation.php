<?php
require __DIR__ . '/../public/db_connect.php';

try {
    $sql = "
        SELECT 
            ta.id AS adj_id,
            ta.transaction_id,
            ta.transaction_type,
            COALESCE(ta.customer_name, 'Walk-in') AS customer,
            ta.original_amount,
            ta.updated_amount,
            ta.amount_difference,
            ta.adjustment_reason,
            ta.manager_remarks,
            ta.adjustment_date,
            ta.fields_changed,
            mt.job_order_id,
            mt.job_order_vehicle_plate,
            mt.payment_method,
            mt.workflow_status,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '), u.username, 'Unknown') AS adjusted_by_name,
            (SELECT GROUP_CONCAT(product_name SEPARATOR ', ') FROM merchandise_transaction_items WHERE transaction_id = mt.id) AS item_names
        FROM transaction_adjustments ta
        LEFT JOIN merchandise_transactions mt ON mt.transaction_id COLLATE utf8mb4_unicode_ci = ta.transaction_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN users u ON u.id = ta.adjusted_by
        LIMIT 5
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "=== Query Success! ===\n";
    print_r($rows);
} catch (Exception $e) {
    echo "SQL ERROR: " . $e->getMessage() . "\n";
}
