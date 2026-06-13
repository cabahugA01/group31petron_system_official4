<?php
require_once __DIR__ . '/../public/db_connect.php';
$station_id = 1253;

echo "=== fuel_deliveries for station_id = $station_id ===\n";
$stmt = $pdo->prepare("SELECT id, batch_id, supplier, fuel_type, delivery_liters, delivery_date FROM fuel_deliveries WHERE station_id = ?");
$stmt->execute([$station_id]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
