<?php
require_once __DIR__ . '/../public/db_connect.php';

// Describe purchase_orders
echo "=== purchase_orders ===\n";
try {
    $r = $pdo->query('DESCRIBE purchase_orders');
    foreach($r->fetchAll(PDO::FETCH_ASSOC) as $row) echo $row['Field'].' ('.$row['Type'].")\n";
} catch(Exception $e) { echo 'Error: '.$e->getMessage()."\n"; }

// Sample rows
echo "\n=== Sample rows (purchase_orders, last 3) ===\n";
try {
    $r = $pdo->query('SELECT id, po_number, batch_id, status, type, product_name, quantity, unit_price, total_amount, created_by, created_at FROM purchase_orders ORDER BY id DESC LIMIT 3');
    foreach($r->fetchAll(PDO::FETCH_ASSOC) as $row) { print_r($row); }
} catch(Exception $e) { echo 'Error: '.$e->getMessage()."\n"; }

// Describe fuel_purchase_orders
echo "\n=== fuel_purchase_orders ===\n";
try {
    $r = $pdo->query('DESCRIBE fuel_purchase_orders');
    foreach($r->fetchAll(PDO::FETCH_ASSOC) as $row) echo $row['Field'].' ('.$row['Type'].")\n";
} catch(Exception $e) { echo 'Error: '.$e->getMessage()."\n"; }

// Sample fuel rows
echo "\n=== Sample rows (fuel_purchase_orders, last 3) ===\n";
try {
    $r = $pdo->query('SELECT id, po_number, batch_id, status, fuel_type_id, volume, unit_price, total_amount, created_by, created_at FROM fuel_purchase_orders ORDER BY id DESC LIMIT 3');
    foreach($r->fetchAll(PDO::FETCH_ASSOC) as $row) { print_r($row); }
} catch(Exception $e) { echo 'Error: '.$e->getMessage()."\n"; }

// Count all statuses
echo "\n=== purchase_orders status counts ===\n";
try {
    $r = $pdo->query("SELECT status, COUNT(*) as cnt FROM purchase_orders GROUP BY status");
    foreach($r->fetchAll(PDO::FETCH_ASSOC) as $row) echo $row['status'].': '.$row['cnt']."\n";
} catch(Exception $e) { echo 'Error: '.$e->getMessage()."\n"; }

echo "\n=== fuel_purchase_orders status counts ===\n";
try {
    $r = $pdo->query("SELECT status, COUNT(*) as cnt FROM fuel_purchase_orders GROUP BY status");
    foreach($r->fetchAll(PDO::FETCH_ASSOC) as $row) echo $row['status'].': '.$row['cnt']."\n";
} catch(Exception $e) { echo 'Error: '.$e->getMessage()."\n"; }
