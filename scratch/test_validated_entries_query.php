<?php
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1;
$start_date = '2020-01-01';
$end_date = '2030-12-31';
$transaction_type = 'all';
$staff_filter = '';

try {
    $base_query = "
        SELECT * FROM (
            SELECT 
                mt.id AS row_id,
                mt.transaction_id AS txn_id,
                COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
                CASE
                    WHEN (
                        TRIM(COALESCE(mt.job_order_service,'')) <> ''
                        OR (SELECT COUNT(*) FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id AND i.item_type = 'service') > 0
                    ) AND (
                        (SELECT COUNT(*) FROM merchandise_transaction_items i2 WHERE i2.transaction_id = mt.id AND COALESCE(i2.item_type,'merchandise') = 'merchandise') > 0
                    ) THEN 'JO + Merchandise'
                    WHEN (
                        TRIM(COALESCE(mt.job_order_service,'')) <> ''
                        OR (SELECT COUNT(*) FROM merchandise_transaction_items i3 WHERE i3.transaction_id = mt.id AND i3.item_type = 'service') > 0
                    ) THEN 'Job Order'
                    ELSE 'Merchandise'
                END AS entry_type,
                COALESCE(
                    NULLIF((SELECT GROUP_CONCAT(CONCAT(i.product_name, ' - ', i.quantity, ' pcs @ ₱', FORMAT(i.unit_price, 2)) ORDER BY i.id SEPARATOR ' | ')
                            FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id AND COALESCE(i.item_type, 'merchandise') = 'merchandise'),''),
                    mt.item_sku, 
                    CASE WHEN TRIM(COALESCE(mt.job_order_service,'')) <> '' THEN '—' ELSE 'N/A' END
                ) AS items_parts,
                COALESCE(
                    NULLIF((SELECT GROUP_CONCAT(CONCAT(i.product_name, ' - ', i.quantity, ' pcs @ ₱', FORMAT(i.unit_price, 2)) ORDER BY i.id SEPARATOR ' | ')
                            FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id AND i.item_type = 'service'),''),
                    NULLIF(mt.job_order_service,''),
                    CASE WHEN mt.item_sku IS NOT NULL AND mt.item_sku <> '' THEN '—' ELSE 'N/A' END
                ) AS service_type,
                NULLIF(TRIM(CONCAT(
                    COALESCE(mt.job_order_vehicle_plate,''),
                    CASE WHEN TRIM(COALESCE(mt.job_order_vehicle_type,'')) <> '' THEN CONCAT(' · ', mt.job_order_vehicle_type) ELSE '' END
                )),'') AS vehicle_info,
                mt.total_amount AS amount,
                COALESCE(mt.amount_paid, 0) AS amount_paid,
                COALESCE(mt.payment_method,'Cash') AS payment_method,
                CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END AS txn_date,
                COALESCE(mt.validation_status,'Pending') AS validation_status,
                COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name,
                'merchandise_transactions' AS _source
            FROM merchandise_transactions mt
            LEFT JOIN users u ON u.id = mt.staff_id
            WHERE mt.station_id = ? AND LOWER(TRIM(COALESCE(mt.validation_status,''))) IN ('approved','completed','adjusted','rejected','voided','official','validated')

            UNION ALL

            SELECT
                jo.id AS row_id,
                CONCAT('JO-', jo.id) AS txn_id,
                COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
                'Job Order' AS entry_type,
                '—' AS items_parts,
                CONCAT(
                    COALESCE(jo.service_type,'Service'),
                    CASE WHEN jo.vehicle_plate IS NOT NULL AND jo.vehicle_plate != '' THEN CONCAT(' | ', jo.vehicle_plate) ELSE '' END,
                    CASE WHEN COALESCE(NULLIF(CONCAT(m.first_name,' ',m.last_name),' '), m.username, '') != '' THEN CONCAT(' | Mech: ', COALESCE(NULLIF(CONCAT(m.first_name,' ',m.last_name),' '), m.username, '')) ELSE '' END
                ) AS service_type,
                NULLIF(TRIM(CONCAT(
                    COALESCE(jo.vehicle_plate,''),
                    CASE WHEN jo.vehicle_type IS NOT NULL AND jo.vehicle_type != '' THEN CONCAT(' · ', jo.vehicle_type) ELSE '' END
                )),'') AS vehicle_info,
                COALESCE(jo.total_cost, jo.estimated_cost, 0) AS amount,
                COALESCE(jo.amount_paid, 0) AS amount_paid,
                COALESCE(jo.payment_method,'N/A') AS payment_method,
                jo.created_at AS txn_date,
                COALESCE(NULLIF(TRIM(jo.validation_status),''),'Pending') AS validation_status,
                COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS staff_name,
                'job_orders' AS _source
            FROM job_orders jo
            LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
            LEFT JOIN users m ON m.id = jo.assigned_mechanic_id
            WHERE jo.station_id = ? AND LOWER(TRIM(COALESCE(jo.validation_status,''))) IN ('approved','completed','adjusted','rejected','voided','official','validated')
        ) combined
        WHERE DATE(combined.txn_date) BETWEEN ? AND ?
    ";

    $params = [$station_id, $station_id, $start_date, $end_date];
    
    $stmt = $pdo->prepare($base_query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Query succeeded! Returned " . count($results) . " rows.\n";
} catch (Exception $e) {
    echo "Query failed: " . $e->getMessage() . "\n";
}
