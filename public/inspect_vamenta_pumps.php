<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/db_connect.php';

echo "<pre>\n";
foreach ([1251, 1253] as $sid) {
    echo "========================================\n";
    echo "STATION ID: $sid\n";
    echo "========================================\n";
    
    echo "--- FUEL INVENTORY ---\n";
    $fi = $pdo->prepare("SELECT id, fuel_type, ugt_no, capacity, current_stock, price_per_liter, status FROM fuel_inventory WHERE station_id = ?");
    $fi->execute([$sid]);
    print_r($fi->fetchAll(PDO::FETCH_ASSOC));

    echo "--- FUEL PUMPS ---\n";
    $fp = $pdo->prepare("SELECT id, pump_number, pump_name, nozzle_number, fuel_type_id, tank_id, ugt_no, status FROM fuel_pumps WHERE station_id = ? ORDER BY id ASC");
    $fp->execute([$sid]);
    print_r($fp->fetchAll(PDO::FETCH_ASSOC));

    echo "--- NOZZLES ---\n";
    $nz = $pdo->prepare("SELECT id, pump_id, pump_name, nozzle_number, fuel_type_id, tank_id, ugt_no, status FROM nozzles WHERE station_id = ? ORDER BY id ASC");
    $nz->execute([$sid]);
    print_r($nz->fetchAll(PDO::FETCH_ASSOC));
}

echo "\n--- USERS AT VAMENTA STATIONS ---\n";
$u = $pdo->query("SELECT id, username, role, station_id FROM users WHERE station_id IN (1251, 1253)")->fetchAll(PDO::FETCH_ASSOC);
print_r($u);

echo "\n--- CHECK ALL STATIONS THAT HAVE PUMP_NUMBER LIKE 'DIESEL 1 - 1' ---\n";
$p_any = $pdo->query("SELECT DISTINCT station_id, pump_number, pump_name FROM fuel_pumps WHERE pump_number LIKE '%DIESEL 1%' OR pump_name LIKE '%DIESEL 1%'")->fetchAll(PDO::FETCH_ASSOC);
print_r($p_any);

echo "</pre>";
