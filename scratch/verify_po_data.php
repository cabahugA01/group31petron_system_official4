<?php
require_once __DIR__ . '/../public/db_connect.php';

$stmt = $pdo->query("
    SELECT po.id, po.po_number, po.product_name, po.quantity, po.total_amount, po.status,
           COUNT(poi.id) AS item_count
    FROM purchase_orders po
    LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
    WHERE po.type = 'merch'
    GROUP BY po.id
    ORDER BY po.id DESC
    LIMIT 20
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Merchandise Purchase Orders ===\n";
foreach ($rows as $r) {
    echo "ID:{$r['id']} | PO#:{$r['po_number']} | Product:{$r['product_name']} | Qty:{$r['quantity']} | Total:{$r['total_amount']} | Status:{$r['status']} | Items:{$r['item_count']}\n";
}
echo "\nTotal: " . count($rows) . " POs\n";

echo "\n=== Null check ===\n";
$null_check = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE type='merch' AND (product_name IS NULL OR product_name = '' OR quantity IS NULL OR quantity = 0)")->fetchColumn();
echo "Merchandise POs with null/empty product_name or quantity: $null_check\n";

echo "\n=== Notification count (Pending Admin Validation) ===\n";
$notif = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='Pending Admin Validation'")->fetchColumn();
echo "Pending Admin Validation count: $notif\n";
