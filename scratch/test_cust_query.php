<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== Customer Summary Query Test ===\n";
$sql = "SELECT c.id,
               COALESCE(NULLIF(c.customer_id,''), CONCAT('CUST-', LPAD(c.id, 4, '0'))) as customer_code_id,
               c.name as customer_name,
               COALESCE(NULLIF(c.contact_number,''), NULLIF(c.phone,''), 'N/A') as contact_no,
               COALESCE(NULLIF(c.customer_type,''), NULLIF(c.type,''), 'Walk-in') as customer_type,
               (SELECT COUNT(DISTINCT DATE(transaction_date)) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as total_visits,
               (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as total_transactions,
               (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id AND LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service')) as total_job_orders,
               (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id AND LOWER(COALESCE(mt.transaction_type,'')) NOT IN ('job_order','service')) as total_merch_purchases,
               (SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as total_amount_spent,
               COALESCE(c.current_balance, c.outstanding_balance, 0) as outstanding_balance,
               (SELECT MAX(transaction_date) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as last_visit,
               COALESCE(c.status, 'Active') as status
        FROM customers c
        ORDER BY total_amount_spent DESC";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Customer count: " . count($rows) . "\n";
print_r($rows);
