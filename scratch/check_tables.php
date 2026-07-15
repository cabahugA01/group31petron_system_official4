<?php
require_once 'C:/xampp/htdocs/group31petron_system_official4/public/db_connect.php';
$cols = $pdo->query('DESCRIBE purchase_order_items')->fetchAll(PDO::FETCH_COLUMN);
echo "purchase_order_items cols: " . implode(', ', $cols) . "\n";

// Also check a sample merch PO with items
$stmt = $pdo->prepare("
    SELECT po.po_number, po.unit_price, po.total_amount, po.remarks,
           po.expected_delivery_date, s.name AS supplier_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.station_id = 1253 AND po.status IN ('Admin Finalized','Approved') AND po.type='merch'
    LIMIT 3
");
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "PO: {$r['po_number']} | unit_price: {$r['unit_price']} | total: {$r['total_amount']}\n";
}
