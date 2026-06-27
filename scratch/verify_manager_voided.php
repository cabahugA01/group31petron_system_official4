<?php
require __DIR__ . '/../public/db_connect.php';

$station_id = 1253;
$where = "WHERE vt.station_id=?";
$params = [$station_id];

echo "=== Testing manager_voided_transactions.php fixed query ===\n";
try {
    $s = $pdo->prepare("SELECT vt.id as void_id, vt.transaction_id, vt.transaction_type,
        COALESCE(vt.customer_name,'Walk-in') as customer,
        vt.amount, vt.void_reason, vt.manager_remarks, vt.void_date,
        vt.fields_changed, vt.job_order_no, vt.vehicle_plate, vt.payment_method,
        COALESCE(NULLIF(vt.voided_by_name,''), NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '), u.username, 'Unknown') as voided_by_name,
        (SELECT GROUP_CONCAT(mti.product_name SEPARATOR ', ') FROM merchandise_transactions mt2
         INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt2.id
         WHERE mt2.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci) AS item_names
        FROM voided_transactions vt LEFT JOIN users u ON u.id=vt.voided_by
        $where ORDER BY vt.void_date DESC LIMIT 500");
    $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    echo "SUCCESS! Rows: " . count($rows) . "\n\n";
    foreach ($rows as $r) {
        echo "VOID-{$r['void_id']} | {$r['transaction_id']} | {$r['customer']} | \u20b1{$r['amount']} | {$r['transaction_type']}\n";
        echo "  Items:   " . ($r['item_names'] ?: '—') . "\n";
        echo "  Payment: " . ($r['payment_method'] ?: '—') . "\n";
        echo "  By:      {$r['voided_by_name']}\n";
        echo "  Date:    {$r['void_date']}\n\n";
    }
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
