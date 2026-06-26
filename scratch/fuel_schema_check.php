<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_inventory FULL SCHEMA ===\n";
$s = $pdo->query('DESCRIBE fuel_inventory');
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) echo '  '.str_pad($r['Field'],30).$r['Type']."\n";

echo "\n=== fuel_transactions FULL SCHEMA ===\n";
$s = $pdo->query('DESCRIBE fuel_transactions');
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) echo '  '.str_pad($r['Field'],30).$r['Type']."\n";

echo "\n=== fuel_inventory CURRENT STATE ===\n";
$s = $pdo->query('SELECT id,station_id,fuel_type,current_level,current_stock,last_updated FROM fuel_inventory');
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) print_r($r);

echo "\n=== fuel_transactions STATUS COUNTS ===\n";
$s = $pdo->query('SELECT status, COUNT(*) as cnt, SUM(liters_sold) as total_liters FROM fuel_transactions GROUP BY status');
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) print_r($r);

echo "\n=== fuel_deliveries STATUS COUNTS ===\n";
$s = $pdo->query('SELECT status, COUNT(*) as cnt, SUM(delivery_liters) as total_liters, GROUP_CONCAT(fuel_type) as types FROM fuel_deliveries GROUP BY status');
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) print_r($r);

// Check if there's a liters_sold formula issue
echo "\n=== fuel_transactions: liters_sold vs (present-previous-calibration) ===\n";
$s = $pdo->query('SELECT id, fuel_type, present_reading, previous_reading, calibration, liters_sold, 
    (present_reading - previous_reading - calibration) as computed FROM fuel_transactions LIMIT 5');
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) print_r($r);
