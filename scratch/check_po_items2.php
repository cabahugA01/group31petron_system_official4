<?php
require_once 'public/db_connect.php';

// All purchase_order_items
$stmt = $pdo->query("SELECT poi.id, poi.po_id, poi.item_name, poi.quantity FROM purchase_order_items LIMIT 20");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "--- All purchase_order_items ---\n";
print_r($items);

// All merch purchase_orders
$stmt2 = $pdo->query("SELECT id, po_number, status, type, total_amount FROM purchase_orders WHERE type='merch' ORDER BY created_at DESC");
$pos = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "--- All merch purchase_orders ---\n";
print_r($pos);

// Check po_request_link table
$stmt3 = $pdo->query("SELECT * FROM po_request_link LIMIT 10");
$prl = $stmt3->fetchAll(PDO::FETCH_ASSOC);
echo "--- po_request_link ---\n";
print_r($prl);
