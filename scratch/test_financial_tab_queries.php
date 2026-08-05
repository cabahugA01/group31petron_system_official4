<?php
require_once __DIR__ . '/../public/db_connect.php';

$date_from = '2026-07-01';
$date_to   = '2026-08-06';

echo "=== 1. Accounts Receivable Query Test ===\n";
$sql_ar = "SELECT 
            COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), c.name, 'Credit Customer') as customer,
            COALESCE(mt.payment_method, 'Credit Account (AR)') as account_type,
            COALESCE(mt.transaction_id, CONCAT('INV-', mt.id)) as invoice_no,
            DATE(mt.transaction_date) as transaction_date,
            COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY)) as due_date,
            COALESCE(mt.balance_due, mt.total_amount, 0) as outstanding_balance,
            GREATEST(DATEDIFF(CURDATE(), COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))), 0) as days_overdue,
            CASE
              WHEN LOWER(COALESCE(mt.payment_status,'')) = 'paid' THEN 'Paid'
              WHEN DATE(COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))) = CURDATE() THEN 'Due Today'
              WHEN DATE(COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))) < CURDATE() THEN 'Overdue'
              ELSE 'Current'
            END as status
           FROM merchandise_transactions mt
           LEFT JOIN customers c ON mt.customer_id = c.id
           WHERE (LOWER(COALESCE(mt.payment_method,'')) LIKE '%credit%' OR LOWER(COALESCE(mt.payment_method,'')) LIKE '%fleet%' OR mt.credit_customer_id IS NOT NULL)
           ORDER BY mt.transaction_date DESC";
$stmt = $pdo->query($sql_ar);
$ar_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "AR rows from transactions: " . count($ar_rows) . "\n";
print_r($ar_rows);

if (empty($ar_rows)) {
    // Check customers table
    $sql_ar_cust = "SELECT 
                        c.name as customer,
                        'Credit Account (AR)' as account_type,
                        CONCAT('INV-', LPAD(c.id, 5, '0')) as invoice_no,
                        DATE(c.created_at) as transaction_date,
                        DATE_ADD(DATE(c.created_at), INTERVAL 30 DAY) as due_date,
                        COALESCE(NULLIF(c.current_balance, 0), NULLIF(c.outstanding_balance, 0), c.credit_limit, 0) as outstanding_balance,
                        GREATEST(DATEDIFF(CURDATE(), DATE_ADD(DATE(c.created_at), INTERVAL 30 DAY)), 0) as days_overdue,
                        CASE
                          WHEN COALESCE(c.current_balance, 0) <= 0 AND COALESCE(c.outstanding_balance, 0) <= 0 THEN 'Paid'
                          WHEN DATE_ADD(DATE(c.created_at), INTERVAL 30 DAY) = CURDATE() THEN 'Due Today'
                          WHEN DATE_ADD(DATE(c.created_at), INTERVAL 30 DAY) < CURDATE() THEN 'Overdue'
                          ELSE 'Current'
                        END as status
                    FROM customers c
                    WHERE LOWER(c.type) = 'credit' OR c.current_balance > 0 OR c.outstanding_balance > 0";
    $stmt2 = $pdo->query($sql_ar_cust);
    $ar_rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "AR rows from customers: " . count($ar_rows2) . "\n";
    print_r($ar_rows2);
}

echo "\n=== 2. Payment Collection Query Test ===\n";
$sql_col = "SELECT 
                mt.transaction_id as or_no,
                COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
                COALESCE(mt.credit_po_number, mt.transaction_id) as invoice_no,
                COALESCE(mt.payment_method, 'Cash') as payment_method,
                COALESCE(mt.amount_paid, mt.total_amount, 0) as amount_paid,
                COALESCE(u.name, 'Cashier Staff') as collected_by,
                mt.transaction_date as payment_date
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            WHERE LOWER(COALESCE(mt.payment_status,'Paid')) = 'paid'
              AND DATE(mt.transaction_date) BETWEEN :df AND :dt
            ORDER BY mt.transaction_date DESC LIMIT 5";
$stmt = $pdo->prepare($sql_col);
$stmt->execute(['df' => $date_from, 'dt' => $date_to]);
$col_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Collections rows: " . count($col_rows) . "\n";
print_r($col_rows);

echo "\n=== 3. Sales vs Collection Query Test ===\n";
$sql_svc = "SELECT 
                d.date,
                COALESCE(s.total_sales, 0) as total_sales,
                COALESCE(cs.total_credit_sales, 0) as total_credit_sales,
                COALESCE(col.total_collections, 0) as total_collections,
                GREATEST(COALESCE(cs.total_credit_sales, 0) - COALESCE(col.total_collections, 0), 0) as outstanding_balance
            FROM (
                SELECT DATE(transaction_date) as date FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df1 AND :dt1
                UNION
                SELECT DATE(transaction_date) as date FROM merchandise_transactions WHERE DATE(transaction_date) BETWEEN :df2 AND :dt2
            ) d
            LEFT JOIN (
                SELECT date, SUM(amount) as total_sales FROM (
                    SELECT DATE(transaction_date) as date, total_amount as amount FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df3 AND :dt3
                    UNION ALL
                    SELECT DATE(transaction_date) as date, total_amount as amount FROM merchandise_transactions WHERE DATE(transaction_date) BETWEEN :df4 AND :dt4
                ) t1 GROUP BY date
            ) s ON d.date = s.date
            LEFT JOIN (
                SELECT DATE(transaction_date) as date, SUM(total_amount) as total_credit_sales
                FROM merchandise_transactions
                WHERE (LOWER(COALESCE(payment_method,'')) LIKE '%credit%' OR LOWER(COALESCE(payment_method,'')) LIKE '%fleet%')
                  AND DATE(transaction_date) BETWEEN :df5 AND :dt5
                GROUP BY DATE(transaction_date)
            ) cs ON d.date = cs.date
            LEFT JOIN (
                SELECT DATE(transaction_date) as date, SUM(COALESCE(amount_paid, total_amount, 0)) as total_collections
                FROM merchandise_transactions
                WHERE LOWER(COALESCE(payment_status,'Paid')) = 'paid'
                  AND DATE(transaction_date) BETWEEN :df6 AND :dt6
                GROUP BY DATE(transaction_date)
            ) col ON d.date = col.date
            ORDER BY d.date DESC";
$stmt = $pdo->prepare($sql_svc);
$stmt->execute([
    'df1' => $date_from, 'dt1' => $date_to,
    'df2' => $date_from, 'dt2' => $date_to,
    'df3' => $date_from, 'dt3' => $date_to,
    'df4' => $date_from, 'dt4' => $date_to,
    'df5' => $date_from, 'dt5' => $date_to,
    'df6' => $date_from, 'dt6' => $date_to
]);
$svc_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Sales vs Collection rows: " . count($svc_rows) . "\n";
print_r($svc_rows);
