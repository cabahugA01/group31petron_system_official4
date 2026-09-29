<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$stmt = $pdo->prepare("SELECT * FROM stations WHERE name LIKE ? OR address LIKE ? OR location LIKE ?");
$stmt->execute(['%kauswagan%', '%kauswagan%', '%kauswagan%']);
$kauswagan_stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Let's also check if there are other stations in Cagayan de Oro
$stmt2 = $pdo->prepare("SELECT * FROM stations WHERE name LIKE ? OR address LIKE ? OR location LIKE ?");
$stmt2->execute(['%cagayan de oro%', '%cagayan de oro%', '%cagayan de oro%']);
$cdo_stations = $stmt2->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'kauswagan_stations' => $kauswagan_stations,
    'cdo_stations' => $cdo_stations
], JSON_PRETTY_PRINT);
