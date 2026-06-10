<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Find Edgar Eslit
$u = $pdo->query("SELECT * FROM users WHERE username='manager' OR last_name='ESLIT' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "=== User ===\n";
print_r($u);

if ($u) {
    $station_id = $u['station_id'];
    echo "\n=== fuel_inventory for station $station_id ===\n";
    $rows = $pdo->query("SELECT * FROM fuel_inventory WHERE station_id = $station_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) print_r($r);
    
    echo "\n=== fuel_pumps for station $station_id ===\n";
    $rows = $pdo->query("SELECT * FROM fuel_pumps WHERE station_id = $station_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) print_r($r);
}
