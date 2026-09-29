<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$stations = $pdo->query("SELECT id, name, location, address, region FROM stations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'total' => count($stations),
    'comma_count' => [],
    'has_brgy' => 0,
    'has_city' => 0,
    'regions' => []
];

foreach ($stations as $s) {
    $raw = $s['address'] ?: $s['name'];
    $parts = explode(',', $raw);
    $c = count($parts);
    $stats['comma_count'][$c] = ($stats['comma_count'][$c] ?? 0) + 1;
    
    if (preg_match('/\b(brgy|barangay|bgy)\b/i', $raw)) {
        $stats['has_brgy']++;
    }
    if (preg_match('/\b(city|municipality)\b/i', $raw)) {
        $stats['has_city']++;
    }
    
    $r = $s['region'] ?? 'EMPTY';
    $stats['regions'][$r] = ($stats['regions'][$r] ?? 0) + 1;
}

echo json_encode($stats, JSON_PRETTY_PRINT);
