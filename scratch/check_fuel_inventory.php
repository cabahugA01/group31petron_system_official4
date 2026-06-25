<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_inventory counts per station ===\n";
$rows = $pdo->query("SELECT station_id, COUNT(*) as cnt FROM fuel_inventory GROUP BY station_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);

echo "=== fuel_inventory rows for station 1253 ===\n";
$rows = $pdo->query("SELECT * FROM fuel_inventory WHERE station_id = 1253")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);
