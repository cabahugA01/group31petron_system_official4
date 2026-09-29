<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$stations = $pdo->query("SELECT id, name, location, address, region, latitude, longitude FROM stations")->fetchAll(PDO::FETCH_ASSOC);

$patterns = [];
$sample_by_region = [];

foreach ($stations as $s) {
    $reg = $s['region'] ?? 'Unknown';
    if (!isset($sample_by_region[$reg])) {
        $sample_by_region[$reg] = [];
    }
    if (count($sample_by_region[$reg]) < 3) {
        $sample_by_region[$reg][] = [
            'id' => $s['id'],
            'name' => $s['name'],
            'location' => $s['location'],
            'address' => $s['address'],
            'lat' => $s['latitude'],
            'lng' => $s['longitude']
        ];
    }
}

echo json_encode([
    'total' => count($stations),
    'sample_by_region' => $sample_by_region
], JSON_PRETTY_PRINT);
