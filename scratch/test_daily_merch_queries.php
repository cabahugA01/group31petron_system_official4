<?php
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1253;
$date_from = '2026-07-01';
$date_to = '2026-08-05';

echo "=== 1. MERCHANDISE SALES TRANSACTIONS ===\n";
$sql_merch = "SELECT 
                mt.transaction_id as receipt_no,
                DATE(mt.transaction_date) as date,
                COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
                COALESCE(mti.category, 'General') as category,
                COALESCE(mti.product_name, mt.item_sku, 'Merchandise Product') as product,
                COALESCE(mti.quantity, mt.quantity, 1) as qty,
                COALESCE(mti.unit_price, mt.unit_price, 0) as unit_price,
                COALESCE(mti.subtotal, mt.total_amount, 0) as amount,
                COALESCE(mt.payment_method, 'Cash') as payment_method
              FROM merchandise_transactions mt
              LEFT JOIN merchandise_transaction_items mti ON mt.id = mti.transaction_id AND (mti.item_type IS NULL OR mti.item_type = 'merchandise')
              WHERE DATE(mt.transaction_date) BETWEEN :date_from AND :date_to
                AND mt.station_id = :station_id
                AND LOWER(COALESCE(mt.transaction_type,'')) NOT IN ('job_order','service')
              ORDER BY mt.transaction_date DESC";
$stmt_m = $pdo->prepare($sql_merch);
$stmt_m->execute(['date_from' => $date_from, 'date_to' => $date_to, 'station_id' => $station_id]);
$merch = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
print_r($merch);

echo "=== 2. JOB ORDER TRANSACTIONS ===\n";
$sql_jo = "SELECT 
            COALESCE(NULLIF(mt.job_order_id,''), mt.transaction_id) as jo_no,
            DATE(mt.transaction_date) as date,
            COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
            CONCAT(COALESCE(mt.job_order_vehicle_plate,'N/A'), ' - ', COALESCE(mt.job_order_vehicle_type,'Vehicle')) as vehicle,
            COALESCE(NULLIF(mt.job_order_mechanic_name,''), 'Unassigned') as mechanic,
            COALESCE(NULLIF(mt.job_order_service,''), 'Vehicle Service') as service,
            COALESCE(mt.subtotal_amount, 0) as labor_fee,
            COALESCE(mt.vat_amount, 0) as service_fee,
            COALESCE(mt.total_amount - mt.subtotal_amount, 0) as parts_cost,
            COALESCE(mt.total_amount, 0) as total_amount,
            COALESCE(mt.payment_method, 'Cash') as payment_method,
            COALESCE(mt.workflow_status, 'Completed') as status
           FROM merchandise_transactions mt
           WHERE DATE(mt.transaction_date) BETWEEN :date_from AND :date_to
             AND mt.station_id = :station_id
             AND LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service')
           ORDER BY mt.transaction_date DESC";
$stmt_j = $pdo->prepare($sql_jo);
$stmt_j->execute(['date_from' => $date_from, 'date_to' => $date_to, 'station_id' => $station_id]);
$jos = $stmt_j->fetchAll(PDO::FETCH_ASSOC);
print_r($jos);
