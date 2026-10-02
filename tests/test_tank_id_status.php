<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: text/plain');

$stmt = $pdo->query("SELECT station_id, COUNT(*) as total_pumps, SUM(CASE WHEN tank_id IS NOT NULL AND tank_id > 0 THEN 1 ELSE 0 END) as with_tank_id FROM fuel_pumps GROUP BY station_id");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
