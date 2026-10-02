<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: text/plain');

echo "=== FUEL INVENTORY FOR STATION 1253 ===\n";
$stmt = $pdo->prepare("SELECT id, station_id, fuel_type_id, fuel_type, ugt_no, capacity, current_level, current_stock, price_per_liter, status FROM fuel_inventory WHERE station_id = 1253 ORDER BY id ASC");
$stmt->execute();
$fi = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($fi);

echo "\n=== FUEL PUMPS FOR STATION 1253 ===\n";
$stmt = $pdo->prepare("SELECT id, station_id, tank_id, pump_number, pump_name, nozzle_number, fuel_type_id, ugt_no, status, calibration_value FROM fuel_pumps WHERE station_id = 1253 ORDER BY id ASC");
$stmt->execute();
$fp = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($fp);

echo "\n=== NOZZLES FOR STATION 1253 ===\n";
$stmt = $pdo->prepare("SELECT id, station_id, pump_id, pump_name, nozzle_number, fuel_type_id, ugt_no, status FROM nozzles WHERE station_id = 1253 ORDER BY id ASC");
$stmt->execute();
$noz = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($noz);
