<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: text/plain');

$stmt = $pdo->query("SELECT id, name FROM stations");
$stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "STATIONS:\n";
print_r($stations);

echo "\n--- FUEL INVENTORY ---\n";
$stmt = $pdo->query("SELECT id, station_id, fuel_type_id, fuel_type, ugt_no, price_per_liter, status FROM fuel_inventory");
$inv = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($inv);

echo "\n--- FUEL PUMPS ---\n";
$stmt = $pdo->query("SELECT id, station_id, pump_number, pump_name, nozzle_number, fuel_type_id, ugt_no, status FROM fuel_pumps");
$pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($pumps);

echo "\n--- NOZZLES ---\n";
$stmt = $pdo->query("SELECT id, station_id, pump_id, pump_name, nozzle_number, fuel_type_id, ugt_no, status FROM nozzles");
$nozzles = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($nozzles);
