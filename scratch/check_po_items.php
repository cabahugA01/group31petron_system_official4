<?php
require_once 'public/db_connect.php';
// Check what statuses are actually in the purchase_orders for merch
$stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM purchase_orders WHERE type = 'merch' GROUP BY status");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "--- Merch PO Statuses ---\n";
print_r($rows);

// Check if purchase_order_items has any data
$count = $pdo->query("SELECT COUNT(*) FROM purchase_order_items")->fetchColumn();
echo "\n--- purchase_order_items row count: $count ---\n";

$stmt2 = $pdo->query("SELECT poi.*, po.type FROM purchase_order_items poi JOIN purchase_orders po ON poi.po_id = po.id WHERE po.type = 'merch' LIMIT 10");
$items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "--- Sample Merch PO items ---\n";
print_r($items);

// Check po_items for merch POs
$stmt3 = $pdo->query("SELECT pi.*, po.type FROM po_items pi JOIN purchase_orders po ON pi.po_id = po.id WHERE po.type = 'merch' LIMIT 10");
$pi = $stmt3->fetchAll(PDO::FETCH_ASSOC);
echo "--- po_items for merch POs ---\n";
print_r($pi);
