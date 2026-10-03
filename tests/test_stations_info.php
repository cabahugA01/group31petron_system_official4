<?php
require_once __DIR__ . '/../public/db_connect.php';

header('Content-Type: text/plain');

echo "=== STATIONS ===\n";
$s = $pdo->query("SELECT * FROM stations");
print_r($s->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== USERS (Judy or Managers/Admins) ===\n";
$u = $pdo->query("SELECT id, username, name, role, station_id FROM users WHERE name LIKE '%Judy%' OR role IN ('admin', 'manager')");
print_r($u->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== ALL FUEL PRODUCTS IN FUEL_INVENTORY ===\n";
$fi = $pdo->query("SELECT fi.id, fi.station_id, s.station_name, fi.fuel_type, fi.ugt_no, fi.price_per_liter, fi.status, fi.fuel_type_id
    FROM fuel_inventory fi
    LEFT JOIN stations s ON fi.station_id = s.id
    ORDER BY fi.station_id, fi.id");
print_r($fi->fetchAll(PDO::FETCH_ASSOC));
