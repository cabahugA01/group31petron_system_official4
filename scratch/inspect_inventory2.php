<?php
require_once __DIR__ . '/../public/db_connect.php';

// Check for adjustment-related tables
$tables_check = ['inventory_adjustments', 'stock_adjustments', 'adjustment_requests', 'inventory_adjustment_requests'];
foreach ($tables_check as $t) {
    try { $r = $pdo->query("SHOW TABLES LIKE '$t'")->fetch(); echo ($r ? "EXISTS" : "NOT FOUND") . ": $t\n"; }
    catch(Exception $e) { echo "ERROR: $t - {$e->getMessage()}\n"; }
}

// Check inventory_logs more carefully
echo "\n=== INVENTORY_LOGS SAMPLE (3) ===\n";
$rows = $pdo->query("SELECT * FROM inventory_logs WHERE station_id = 1253 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

echo "\n=== DISTINCT ACTIONS IN INVENTORY_LOGS ===\n";
$acts = $pdo->query("SELECT DISTINCT action FROM inventory_logs WHERE station_id = 1253")->fetchAll(PDO::FETCH_COLUMN);
print_r($acts);

echo "\n=== DISTINCT REFERENCE_TYPES IN INVENTORY_LOGS ===\n";
$refs = $pdo->query("SELECT DISTINCT reference_type FROM inventory_logs WHERE station_id = 1253")->fetchAll(PDO::FETCH_COLUMN);
print_r($refs);

echo "\n=== PRODUCTS WITH CATEGORY ===\n";
$prods = $pdo->query("SELECT p.id, p.sku, p.name, p.category_id, pc.name as category_name, p.unit, p.current_stock, p.min_stock_level FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE p.station_id = 1253 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($prods);

echo "\n=== MERCHANDISE_BATCHES WITH EXPIRY ===\n";
$batches = $pdo->query("SELECT mb.*, do.expiry_date, p.name as product_name FROM merchandise_batches mb LEFT JOIN products p ON mb.product_id = p.id LEFT JOIN deliveries_oversight do ON mb.delivery_id = do.id WHERE mb.station_id = 1253 LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
print_r($batches);

echo "\n=== FUEL_INVENTORY FOR STATION 1253 ===\n";
$fuel = $pdo->query("SELECT * FROM fuel_inventory WHERE station_id = 1253")->fetchAll(PDO::FETCH_ASSOC);
print_r($fuel);
