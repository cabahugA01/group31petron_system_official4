<?php
require 'public/db_connect.php';
$apps = $pdo->query('SELECT * FROM pending_price_approvals')->fetchAll(PDO::FETCH_ASSOC);
echo "Pending Approvals:\n";
print_r($apps);
$fuel = $pdo->query('SELECT id, fuel_type, station_id FROM fuel_inventory')->fetchAll(PDO::FETCH_ASSOC);
echo "\nFuel Inventory:\n";
print_r($fuel);
