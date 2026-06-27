<?php
$pdo = new PDO('mysql:host=localhost;dbname=petron_pos_db_secure', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== station_inventory columns ===\n";
try {
    $stmt = $pdo->query("DESCRIBE station_inventory");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { echo $r['Field'] . ": " . $r['Type'] . "\n"; }
} catch(Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== inventory_products columns ===\n";
try {
    $stmt = $pdo->query("DESCRIBE inventory_products");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { echo $r['Field'] . ": " . $r['Type'] . "\n"; }
} catch(Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== merchandise_transaction_items columns ===\n";
try {
    $stmt = $pdo->query("DESCRIBE merchandise_transaction_items");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { echo $r['Field'] . ": " . $r['Type'] . "\n"; }
} catch(Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== fuel_inventory sample (first 3 rows) ===\n";
try {
    $stmt = $pdo->query("SELECT id, station_id, fuel_type, current_level, current_stock, capacity, reorder_level, critical_level, status FROM fuel_inventory LIMIT 3");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { print_r($r); }
} catch(Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== station_inventory sample (first 3 rows) ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM station_inventory LIMIT 3");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { print_r($r); }
} catch(Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
