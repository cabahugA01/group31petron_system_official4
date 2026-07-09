<?php
require_once 'public/db_connect.php';

// Describe purchase_order_items
$cols = $pdo->query("DESCRIBE purchase_order_items")->fetchAll(PDO::FETCH_ASSOC);
echo "--- purchase_order_items columns ---\n";
foreach ($cols as $c) echo $c['Field'] . "\n";

// All items
$stmt = $pdo->query("SELECT * FROM purchase_order_items LIMIT 10");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n--- All purchase_order_items rows ---\n";
print_r($items);

// Merch POs
$stmt2 = $pdo->query("SELECT id, po_number, status, type, total_amount FROM purchase_orders WHERE type='merch' ORDER BY created_at DESC");
$pos = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "\n--- All merch purchase_orders ---\n";
print_r($pos);
