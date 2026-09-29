<?php
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

$directory = new RecursiveDirectoryIterator(__DIR__ . '/..');
$iterator = new RecursiveIteratorIterator($directory);
$coord_files = [];

foreach ($iterator as $file) {
    if ($file->isFile() && in_array($file->getExtension(), ['php', 'sql', 'js'])) {
        $path = $file->getPathname();
        if (strpos($path, 'vendor') !== false || strpos($path, 'node_modules') !== false) {
            continue;
        }
        $content = @file_get_contents($path);
        if ($content === false) continue;

        if (stripos($content, 'latitude') !== false || stripos($content, 'longitude') !== false) {
            $rel = str_replace(realpath(__DIR__ . '/..'), '', $path);
            $coord_files[] = $rel;
        }
    }
}

// Let's also check what station management files exist:
$mgmt_files = [];
foreach ($iterator as $file) {
    if ($file->isFile() && stripos($file->getFilename(), 'station') !== false) {
        $mgmt_files[] = str_replace(realpath(__DIR__ . '/..'), '', $file->getPathname());
    }
}

echo json_encode([
    'coord_files' => $coord_files,
    'station_files' => $mgmt_files
], JSON_PRETTY_PRINT);
