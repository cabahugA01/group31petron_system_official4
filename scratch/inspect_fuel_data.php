<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== FUEL TRANSACTIONS SAMPLE ===\n";
$stmt = $pdo->query("SELECT id, transaction_id, station_id, pump_id, fuel_type, present_reading, previous_reading, liters_sold, total_amount, status, transaction_date FROM fuel_transactions LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== FUEL ADJUSTMENTS SAMPLE ===\n";
$stmt = $pdo->query("SELECT * FROM fuel_adjustments LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== FUEL DELIVERIES SAMPLE ===\n";
$stmt = $pdo->query("SELECT * FROM fuel_deliveries LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== FUEL PURCHASE ORDERS SAMPLE ===\n";
$stmt = $pdo->query("SELECT * FROM fuel_purchase_orders LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== FUEL STOCK REQUESTS SAMPLE ===\n";
$stmt = $pdo->query("SELECT * FROM fuel_stock_requests LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
