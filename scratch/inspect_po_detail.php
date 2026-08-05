<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== purchase_orders columns ===\n";
$stmt = $pdo->query("DESCRIBE purchase_orders");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']}  [{$c['Type']}]\n";
}
$smp = $pdo->query("SELECT * FROM purchase_orders LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "SAMPLE: " . json_encode($smp) . "\n\n";

echo "=== PO statuses ===\n";
$stmt = $pdo->query("SELECT DISTINCT status FROM purchase_orders");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $s) echo "  '$s'\n";

echo "\n=== delivery statuses ===\n";
$stmt = $pdo->query("SELECT DISTINCT status FROM deliveries_oversight");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $s) echo "  '$s'\n";

echo "\n=== merchandise_stock_in statuses / approval ===\n";
$stmt = $pdo->query("DESCRIBE merchandise_stock_in");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']}  [{$c['Type']}]\n";
}
$smp = $pdo->query("SELECT msi.*, u.name as approver_name FROM merchandise_stock_in msi LEFT JOIN users u ON msi.encoded_by = u.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "SAMPLE: " . json_encode($smp) . "\n";

echo "\n=== purchase_orders items count ===\n";
$stmt = $pdo->query("SELECT po.po_number, COUNT(poi.id) as item_count, SUM(poi.quantity_ordered) as total_qty, SUM(poi.total_price) as est_cost, po.expected_delivery_date, po.status FROM purchase_orders po LEFT JOIN purchase_order_items poi ON po.id = poi.po_id GROUP BY po.id LIMIT 3");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) echo json_encode($r) . "\n";
