<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

$station_id = 1253;
$search = '';
$date_from = date('Y-m-d', strtotime('-90 days'));
$date_to = date('Y-m-d');

echo "=== DIAGNOSTIC START ===\n";

try {  $sql = "  SELECT  mt.id AS row_id,  mt.transaction_id AS txn_id,  COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,  CASE  WHEN mt.transaction_type = 'combined' THEN 'Combined'  WHEN mt.transaction_type = 'job_order' THEN 'Job Order'  ELSE 'Merchandise'  END AS entry_type,  GROUP_CONCAT(CONCAT(mti.product_name, ' (x', mti.quantity, ')') ORDER BY mti.id SEPARATOR ', ') AS items_service,  '' AS vehicle_plate,  mt.total_amount AS amount,  mt.amount_paid AS amount_paid,  COALESCE(mt.payment_method,'Cash') AS payment_method,  COALESCE(NULLIF(mt.transaction_date, '0000-00-00'), mt.created_at) AS txn_date,  COALESCE(mt.validation_status,'Approved') AS validation_status,  'merchandise_transactions' AS _source  FROM merchandise_transactions mt  LEFT JOIN users u ON u.id = mt.staff_id  LEFT JOIN users v ON v.id = mt.validated_by  LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id  WHERE mt.station_id = ? AND DATE(COALESCE(NULLIF(mt.transaction_date, '0000-00-00'), mt.created_at)) >= ? AND DATE(COALESCE(NULLIF(mt.transaction_date, '0000-00-00'), mt.created_at)) <= ?  GROUP BY mt.id  ORDER BY txn_date DESC  ";  $stmt = $pdo->prepare($sql);  $stmt->execute([$station_id, $date_from, $date_to]);  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);  echo "Row count: " . count($rows) . "\n";  foreach ($rows as $r) {  echo "Row ID: " . $r['row_id'] . " | Txn ID: " . $r['txn_id'] . " | Customer: " . $r['customer'] . " | Type: " . $r['entry_type'] . " | Amount: " . $r['amount'] . " | Validation Status: " . $r['validation_status'] . "\n";  }
} catch (Exception $e) {  echo "Query Error: " . $e->getMessage() . "\n";
}

echo "=== DIAGNOSTIC END ===\n";
