<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== Merchandise transactions query test ===\n";
$sql = "SELECT 
            mt.id,
            mt.transaction_id as receipt_no,
            DATE(mt.transaction_date) as date,
            COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
            COALESCE(mti.category, p.category_id, 'General') as category_name,
            COALESCE(mti.product_name, mt.item_sku, 'Merchandise Product') as product,
            COALESCE(mti.quantity, mt.quantity, 1) as quantity,
            COALESCE(mti.unit_price, mt.unit_price, 0) as unit_price,
            COALESCE(mti.subtotal, mt.total_amount, 0) as amount,
            COALESCE(mt.payment_method, 'Cash') as payment_method
        FROM merchandise_transactions mt
        LEFT JOIN merchandise_transaction_items mti ON mt.id = mti.transaction_id AND (mti.item_type IS NULL OR mti.item_type = 'merchandise')
        LEFT JOIN products p ON mti.product_id = p.id
        WHERE LOWER(COALESCE(mt.transaction_type,'')) NOT IN ('job_order','service')
        ORDER BY mt.transaction_date DESC LIMIT 5";
$stmt = $pdo->query($sql);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== Job Orders query test ===\n";
$sql_jo = "SELECT 
            COALESCE(NULLIF(mt.job_order_id,''), mt.transaction_id) as jo_no,
            DATE(mt.transaction_date) as date,
            COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
            CONCAT(COALESCE(mt.job_order_vehicle_plate,'N/A'), ' - ', COALESCE(mt.job_order_vehicle_type,'Vehicle')) as vehicle,
            COALESCE(NULLIF(mt.job_order_mechanic_name,''), 'Unassigned') as mechanic,
            COALESCE(NULLIF(mt.job_order_service,''), 'Vehicle Service') as service,
            COALESCE(mt.subtotal_amount, 0) as labor_fee,
            COALESCE(mt.vat_amount, 0) as service_fee,
            GREATEST(COALESCE(mt.total_amount,0) - COALESCE(mt.subtotal_amount,0) - COALESCE(mt.vat_amount,0), 0) as parts_cost,
            COALESCE(mt.total_amount, 0) as total_amount,
            COALESCE(mt.payment_method, 'Cash') as payment_method,
            COALESCE(mt.workflow_status, mt.validation_status, 'Completed') as status
           FROM merchandise_transactions mt
           WHERE LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service')
           ORDER BY mt.transaction_date DESC LIMIT 5";
$stmt_jo = $pdo->query($sql_jo);
print_r($stmt_jo->fetchAll(PDO::FETCH_ASSOC));
