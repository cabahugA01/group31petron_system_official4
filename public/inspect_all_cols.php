<?php
require_once __DIR__ . '/db_connect.php';
echo "<pre>\n";
echo "=== FUEL INVENTORY FOR 1253 ===\n";
$fi = $pdo->query("SELECT * FROM fuel_inventory WHERE station_id = 1253")->fetchAll(PDO::FETCH_ASSOC);
print_r($fi);

echo "\n=== FUEL PUMPS FOR 1253 ===\n";
$fp = $pdo->query("SELECT * FROM fuel_pumps WHERE station_id = 1253 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
print_r($fp);
echo "</pre>";
