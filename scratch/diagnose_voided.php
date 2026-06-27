<?php
require __DIR__ . '/../public/db_connect.php';

$station_id = 1253;
$where = ["vt.station_id = ?"];
$params = [$station_id];

echo "=== Testing FIXED query (from voided_transactions.php) ===\n";
try {
    $stmt = $pdo->prepare("
        SELECT 
            vt.id, vt.transaction_id, vt.transaction_type, vt.customer_name,
            vt.amount, vt.void_reason, vt.manager_remarks, vt.void_date,
            vt.fields_changed, vt.job_order_no, vt.vehicle_plate, vt.payment_method,
            COALESCE(
                NULLIF(vt.voided_by_name,''),
                NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '),
                u.username, 'Manager'
            ) AS voided_by_name,
            (SELECT GROUP_CONCAT(mti.product_name SEPARATOR ', ')
             FROM merchandise_transactions mt2
             INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt2.id
             WHERE mt2.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci
            ) AS item_names
        FROM voided_transactions vt
        LEFT JOIN users u ON u.id = vt.voided_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY vt.void_date DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $voided = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "SUCCESS! Rows returned: " . count($voided) . "\n\n";
    foreach ($voided as $v) {
        echo "--- Row ID={$v['id']} ---\n";
        echo "  TXN:      {$v['transaction_id']}\n";
        echo "  Type:     {$v['transaction_type']}\n";
        echo "  Customer: {$v['customer_name']}\n";
        echo "  Amount:   {$v['amount']}\n";
        echo "  JO No:    " . ($v['job_order_no'] ?: '—') . "\n";
        echo "  Plate:    " . ($v['vehicle_plate'] ?: '—') . "\n";
        echo "  Payment:  " . ($v['payment_method'] ?: '—') . "\n";
        echo "  By:       {$v['voided_by_name']}\n";
        echo "  Items:    " . ($v['item_names'] ?: '— (none)') . "\n";
        echo "  Reason:   {$v['void_reason']}\n";
        echo "  Date:     {$v['void_date']}\n";
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
