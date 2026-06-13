<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== purchase_orders station_id values ===\n";
$stmt = $pdo->query("SELECT DISTINCT station_id FROM purchase_orders");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

echo "=== fuel_deliveries station_id values ===\n";
$stmt = $pdo->query("SELECT DISTINCT station_id FROM fuel_deliveries");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
