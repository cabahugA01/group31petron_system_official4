<?php
require 'public/db_connect.php';
echo "=== FUEL TYPES ===\n";
print_r($pdo->query("SELECT * FROM fuel_types")->fetchAll(PDO::FETCH_ASSOC));

echo "=== FUEL INVENTORY FOR STATION 1253 ===\n";
print_r($pdo->query("SELECT * FROM fuel_inventory WHERE station_id = 1253")->fetchAll(PDO::FETCH_ASSOC));
