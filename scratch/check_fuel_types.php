<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_types ===\n";
$rows = $pdo->query("SELECT * FROM fuel_types ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);

echo "\n=== fuel_pumps with fuel_type name for station 1253 ===\n";
$rows = $pdo->query("
    SELECT fp.id, fp.station_id, fp.pump_number, ft.name AS fuel_type_name, fp.status
    FROM fuel_pumps fp
    JOIN fuel_types ft ON ft.id = fp.fuel_type_id
    WHERE fp.station_id = 1253
    ORDER BY fp.id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);
