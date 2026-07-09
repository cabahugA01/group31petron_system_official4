<?php
require_once 'public/db_connect.php';

// fuel_purchase_orders schema
echo "--- fuel_purchase_orders columns ---\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_purchase_orders")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo $c['Field'] . " - " . $c['Type'] . " - Null: " . $c['Null'] . "\n";
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

// fuel_stock_requests schema
echo "\n--- fuel_stock_requests columns ---\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_stock_requests")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo $c['Field'] . " - " . $c['Type'] . " - Null: " . $c['Null'] . "\n";
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

// fuel_inventory columns
echo "\n--- fuel_inventory columns ---\n";
try {
    $cols = $pdo->query("DESCRIBE fuel_inventory")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo $c['Field'] . " - " . $c['Type'] . " - Null: " . $c['Null'] . "\n";
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

// Sample fuel_purchase_orders
echo "\n--- Sample fuel_purchase_orders ---\n";
try {
    $rows = $pdo->query("SELECT * FROM fuel_purchase_orders LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

// Count fuel_purchase_orders statuses
echo "\n--- fuel_purchase_orders statuses ---\n";
try {
    $rows = $pdo->query("SELECT status, COUNT(*) as cnt FROM fuel_purchase_orders GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

// Count purchase_orders type='fuel'
echo "\n--- purchase_orders type=fuel count ---\n";
try {
    $rows = $pdo->query("SELECT status, COUNT(*) as cnt FROM purchase_orders WHERE type='fuel' GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }
