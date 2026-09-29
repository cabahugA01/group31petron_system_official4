<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$stations = $pdo->query("SELECT id, name, location, address, region FROM stations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$cities = [];
$provinces = [];
$regions = [];

function clean_text($str) {
    $str = str_replace('??', 'ñ', $str);
    $str = preg_replace('/\s*\(Car Care Center\)/i', '', $str);
    $str = preg_replace('/\s*\(Treats Store\)/i', '', $str);
    $str = preg_replace('/\s*\(Service Station\)/i', '', $str);
    return trim($str);
}

foreach ($stations as $s) {
    $raw = clean_text($s['address'] ?: $s['name']);
    $parts = array_map('trim', explode(',', $raw));
    $count = count($parts);
    
    // Check province and city candidates
    if ($count >= 4) {
        $prov = $parts[$count - 1];
        $city = $parts[$count - 2];
        $brgy = $parts[$count - 3];
    } elseif ($count == 3) {
        $prov = $parts[$count - 1];
        $city = $parts[$count - 2];
        $brgy = '';
    } else {
        $prov = $s['location'] ?? '';
        $city = $parts[0] ?? '';
        $brgy = '';
    }
    
    $provinces[$prov] = ($provinces[$prov] ?? 0) + 1;
    $cities[$city] = ($cities[$city] ?? 0) + 1;
}

echo json_encode([
    'unique_cities_count' => count($cities),
    'unique_provinces_count' => count($provinces),
    'top_cities' => array_slice($cities, 0, 30, true),
    'top_provinces' => array_slice($provinces, 0, 30, true)
], JSON_PRETTY_PRINT);
