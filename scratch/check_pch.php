<?php
require_once __DIR__ . '/../public/db_connect.php';
$stmt = $pdo->query("DESCRIBE pump_calibration_history");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
