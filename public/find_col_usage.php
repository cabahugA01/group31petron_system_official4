<?php
header('Content-Type: application/json');

$directory = new RecursiveDirectoryIterator(__DIR__ . '/..');
$iterator = new RecursiveIteratorIterator($directory);

$column_uses = [
    'address' => [],
    'location' => [],
    'barangay' => [],
    'city' => [],
    'province' => [],
    'region' => [],
    'latitude' => [],
    'longitude' => []
];

foreach ($iterator as $file) {
    if ($file->isFile() && in_array($file->getExtension(), ['php', 'js'])) {
        $path = $file->getPathname();
        if (strpos($path, 'vendor') !== false || strpos($path, 'node_modules') !== false) continue;
        $content = @file_get_contents($path);
        if (!$content) continue;

        $rel = str_replace(realpath(__DIR__ . '/..'), '', $path);
        foreach ($column_uses as $col => &$list) {
            if (preg_match('/\b' . $col . '\b/i', $content)) {
                $list[] = $rel;
            }
        }
    }
}

echo json_encode($column_uses, JSON_PRETTY_PRINT);
