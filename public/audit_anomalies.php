<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$stations = $pdo->query("SELECT id, name, address, barangay, city, province, region, latitude, longitude FROM stations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$anomalies = [];
foreach ($stations as $s) {
    $c = $s['city'];
    $p = $s['province'];
    $r = $s['region'];
    
    // Check if CDO is outside Region X
    if (stripos($s['name'], 'Cagayan de Oro') !== false && $r !== 'Region X') {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'CDO not in Region X', 'station' => $s];
    }
    // Check if Davao is outside Region XI
    if (stripos($s['name'], 'Davao City') !== false && $r !== 'Region XI') {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'Davao City not in Region XI', 'station' => $s];
    }
    // Check if Cebu is outside Region VII
    if (stripos($s['name'], 'Cebu City') !== false && $r !== 'Region VII') {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'Cebu City not in Region VII', 'station' => $s];
    }
    // Check if Iloilo is outside Region VI
    if (stripos($s['name'], 'Iloilo City') !== false && $r !== 'Region VI') {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'Iloilo City not in Region VI', 'station' => $s];
    }
    // Check if Bacolod is outside Region VI
    if (stripos($s['name'], 'Bacolod City') !== false && $r !== 'Region VI') {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'Bacolod City not in Region VI', 'station' => $s];
    }
    // Check if Zamboanga City is outside Region IX
    if (stripos($s['name'], 'Zamboanga City') !== false && $r !== 'Region IX') {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'Zamboanga City not in Region IX', 'station' => $s];
    }
    // Check if GenSan is outside Region XII
    if (stripos($s['name'], 'Gen. Santos') !== false && $r !== 'Region XII') {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'GenSan not in Region XII', 'station' => $s];
    }
    // Check if Tacloban is outside Region VIII
    if (stripos($s['name'], 'Tacloban') !== false && $r !== 'Region VIII') {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'Tacloban not in Region VIII', 'station' => $s];
    }
    // Check if Butuan is outside Region XIII
    if (stripos($s['name'], 'Butuan City') !== false && $r !== 'Region XIII') {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'Butuan not in Region XIII', 'station' => $s];
    }
    // Check if Baguio is outside CAR
    if (stripos($s['name'], 'Baguio') !== false && $r !== 'CAR') {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'Baguio not in CAR', 'station' => $s];
    }
    // Check if city equals province
    if (strtolower($c) === strtolower($p) && !in_array(strtolower($c), ['ilocos norte', 'cavite', 'batangas', 'quezon', 'bataan', 'tarlac', 'bulacan', 'pampanga', 'cebu', 'bohol', 'aklan', 'capiz', 'antique', 'siquijor', 'biliran'])) {
        $anomalies[] = ['id' => $s['id'], 'reason' => 'City equals province', 'station' => $s];
    }
}

echo json_encode([
    'anomaly_count' => count($anomalies),
    'anomalies' => $anomalies
], JSON_PRETTY_PRINT);
