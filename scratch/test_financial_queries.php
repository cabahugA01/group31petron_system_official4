<?php
require_once __DIR__ . '/../public/db_connect.php';

$date_from = '2026-07-01';
$date_to   = '2026-08-06';

echo "=== Revenue Summary Query Test ===\n";
$sql_rev = "SELECT 
                d.date,
                COALESCE(f.fuel_rev, 0) as fuel_revenue,
                COALESCE(m.merch_rev, 0) as merchandise_revenue,
                COALESCE(s.serv_rev, 0) as service_revenue,
                (COALESCE(f.fuel_rev, 0) + COALESCE(m.merch_rev, 0) + COALESCE(s.serv_rev, 0)) as gross_revenue,
                0.00 as total_discounts,
                (COALESCE(f.fuel_rev, 0) + COALESCE(m.merch_rev, 0) + COALESCE(s.serv_rev, 0)) as net_revenue
            FROM (
                SELECT DATE(transaction_date) as date FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df1 AND :dt1
                UNION
                SELECT DATE(transaction_date) as date FROM merchandise_transactions WHERE DATE(transaction_date) BETWEEN :df2 AND :dt2
            ) d
            LEFT JOIN (
                SELECT DATE(transaction_date) as date, SUM(total_amount) as fuel_rev
                FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df3 AND :dt3 GROUP BY DATE(transaction_date)
            ) f ON d.date = f.date
            LEFT JOIN (
                SELECT DATE(transaction_date) as date, SUM(total_amount) as merch_rev
                FROM merchandise_transactions WHERE LOWER(COALESCE(transaction_type,'')) NOT IN ('job_order','service')
                AND DATE(transaction_date) BETWEEN :df4 AND :dt4 GROUP BY DATE(transaction_date)
            ) m ON d.date = m.date
            LEFT JOIN (
                SELECT DATE(transaction_date) as date, SUM(total_amount) as serv_rev
                FROM merchandise_transactions WHERE LOWER(COALESCE(transaction_type,'')) IN ('job_order','service')
                AND DATE(transaction_date) BETWEEN :df5 AND :dt5 GROUP BY DATE(transaction_date)
            ) s ON d.date = s.date
            ORDER BY d.date DESC";

$stmt = $pdo->prepare($sql_rev);
$stmt->execute([
    'df1' => $date_from, 'dt1' => $date_to,
    'df2' => $date_from, 'dt2' => $date_to,
    'df3' => $date_from, 'dt3' => $date_to,
    'df4' => $date_from, 'dt4' => $date_to,
    'df5' => $date_from, 'dt5' => $date_to
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Rows returned: " . count($rows) . "\n";
print_r(array_slice($rows, 0, 5));

echo "\n=== Customers Credit AR Test ===\n";
$sql_ar = "SELECT c.id, c.name as customer,
                  COALESCE(c.account_status, 'Credit Account (AR)') as account_type,
                  CONCAT('INV-', LPAD(c.id, 5, '0')) as invoice_no,
                  DATE(c.created_at) as transaction_date,
                  COALESCE(c.credit_terms, '30 Days') as credit_terms,
                  COALESCE(c.current_balance, c.outstanding_balance, 0) as outstanding_balance,
                  c.status
           FROM customers c
           WHERE (c.current_balance > 0 OR c.outstanding_balance > 0 OR LOWER(c.type) = 'credit')";
$stmt_ar = $pdo->query($sql_ar);
print_r($stmt_ar->fetchAll(PDO::FETCH_ASSOC));
