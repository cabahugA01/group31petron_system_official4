<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== FUEL_TYPES TABLE ===\n";
$ft = $pdo->query("SELECT * FROM fuel_types")->fetchAll(PDO::FETCH_ASSOC);
print_r($ft);

echo "=== FUEL_PUMPS TABLE WITH FUEL_TYPES JOIN ===\n";
$fp = $pdo->query("SELECT fp.id, fp.pump_number, fp.fuel_type_id, ft.name as fuel_type_name 
                   FROM fuel_pumps fp 
                   LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id")->fetchAll(PDO::FETCH_ASSOC);
print_r($fp);

echo "=== FUEL_INVENTORY TABLE ===\n";
$fi = $pdo->query("SELECT fi.*, ft.name as fuel_type_name FROM fuel_inventory fi LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id")->fetchAll(PDO::FETCH_ASSOC);
print_r($fi);
