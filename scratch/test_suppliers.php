<?php
require_once __DIR__ . '/../public/db_connect.php';
$station_id = 1253;

echo "=== TEST QUERY 3 (UNION) ===\n";
try {
    $q = $pdo->prepare("
        SELECT 
            supplier,
            SUM(CASE WHEN po_id IS NOT NULL THEN 1 ELSE 0 END) AS total_deliveries,
            SUM(delivery_liters) AS total_liters,
            SUM(total_amount) AS total_amount,
            SUM(outstanding_balance) AS outstanding_balance,
            MAX(last_date) AS last_delivery
        FROM (
            SELECT 
                COALESCE(s.name, po.supplier_name, 'Unknown') AS supplier,
                po.id AS po_id,
                0 AS delivery_liters,
                COALESCE(po.total_amount, 0) AS total_amount,
                COALESCE(CASE WHEN po.status NOT IN ('Received','Cancelled','Rejected by Admin','Admin Finalized') THEN po.total_amount ELSE 0 END, 0) AS outstanding_balance,
                po.created_at AS last_date
            FROM purchase_orders po
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.station_id = ?
            
            UNION ALL
            
            SELECT 
                supplier AS supplier,
                NULL AS po_id,
                delivery_liters AS delivery_liters,
                0 AS total_amount,
                0 AS outstanding_balance,
                delivery_date AS last_date
            FROM fuel_deliveries
            WHERE station_id = ?
        ) t
        GROUP BY supplier
        ORDER BY total_deliveries DESC
        LIMIT 10
    ");
    $q->execute([$station_id, $station_id]);
    $results = $q->fetchAll(PDO::FETCH_ASSOC);
    print_r($results);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
