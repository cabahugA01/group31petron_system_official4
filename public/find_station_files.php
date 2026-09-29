<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

// Find all PHP files referencing latitude, longitude, stations, or maps
$directory = new RecursiveDirectoryIterator(__DIR__ . '/..');
$iterator = new RecursiveIteratorIterator($directory);
$matches = [];

foreach ($iterator as $file) {
    if ($file->isFile() && in_array($file->getExtension(), ['php', 'sql', 'js'])) {
        $path = $file->getPathname();
        // Skip vendor and node_modules
        if (strpos($path, 'vendor') !== false || strpos($path, 'node_modules') !== false) {
            continue;
        }
        $content = @file_get_contents($path);
        if ($content === false) continue;

        $has_coords = (stripos($content, 'latitude') !== false || stripos($content, 'longitude') !== false);
        $has_station_map = (stripos($content, 'station') !== false && (stripos($content, 'map') !== false || stripos($content, 'leaflet') !== false || stripos($content, 'address') !== false));
        
        if ($has_coords || $has_station_map) {
            $rel = str_replace(realpath(__DIR__ . '/..'), '', $path);
            $matches[] = [
                'file' => $rel,
                'has_coords' => $has_coords,
                'has_station_map' => $has_station_map
            ];
        }
    }
}

// Let's also inspect how the stations table is populated: check database folder files
$sql_files = glob(__DIR__ . '/../database/*.sql');
$sql_info = [];
foreach ($sql_files as $f) {
    $sql_info[] = [
        'file' => basename($f),
        'size' => filesize($f)
    ];
}

echo json_encode([
    'matches_count' => count($matches),
    'matches' => $matches,
    'sql_files' => $sql_info
], JSON_PRETTY_PRINT);
