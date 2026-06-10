<?php
require_once __DIR__ . '/../public/db_connect.php';
$stmt = $pdo->prepare("SELECT * FROM fuel_pumps WHERE station_id = 1253");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== fuel_pumps for station 1253 ===\n";
print_r($rows);
