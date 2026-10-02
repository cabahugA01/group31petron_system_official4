<?php
require_once __DIR__ . '/db_connect.php';
echo "<pre>\n";
echo "=== STATION 1 FUEL INVENTORY ===\n";
$fi1 = $pdo->query("SELECT * FROM fuel_inventory WHERE station_id = 1")->fetchAll(PDO::FETCH_ASSOC);
print_r($fi1);

echo "=== STATION 1 FUEL PUMPS ===\n";
$fp1 = $pdo->query("SELECT * FROM fuel_pumps WHERE station_id = 1")->fetchAll(PDO::FETCH_ASSOC);
print_r($fp1);
echo "</pre>";
