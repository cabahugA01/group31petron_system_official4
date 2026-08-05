<?php
require_once 'public/db_connect.php';

echo "=== fuel_pumps columns & sample for station 1253 & station 1 ===" . PHP_EOL;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM fuel_pumps")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  " . $c['Field'] . " (" . $c['Type'] . ")" . PHP_EOL;
    
    $rows = $pdo->query("SELECT * FROM fuel_pumps WHERE station_id IN (1253, 1) LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    echo "Sample pumps:" . PHP_EOL;
    foreach ($rows as $r) echo json_encode($r) . PHP_EOL;
} catch (Exception $e) { echo $e->getMessage() . PHP_EOL; }

echo PHP_EOL . "=== fuel_inventory for station 1253 & 1 ===" . PHP_EOL;
try {
    $rows = $pdo->query("SELECT * FROM fuel_inventory WHERE station_id IN (1253, 1)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) echo json_encode($r) . PHP_EOL;
} catch (Exception $e) { echo $e->getMessage() . PHP_EOL; }

echo PHP_EOL . "=== pump_configuration or ugt tables ===" . PHP_EOL;
try {
    $rows = $pdo->query("SELECT * FROM pump_configuration LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) echo json_encode($r) . PHP_EOL;
} catch (Exception $e) { echo $e->getMessage() . PHP_EOL; }

echo PHP_EOL . "=== fuel_types table ===" . PHP_EOL;
try {
    $rows = $pdo->query("SELECT * FROM fuel_types LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) echo json_encode($r) . PHP_EOL;
} catch (Exception $e) { echo $e->getMessage() . PHP_EOL; }
