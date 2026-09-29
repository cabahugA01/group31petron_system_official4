<?php
header('Content-Type: application/json');

$root = dirname(__DIR__);
$dirs = ['backend', 'public', 'partials'];
$results = [];

$queries = [
    'Pending Price Change Approvals',
    'price update request',
    'No Active Shifts Today'
];

foreach ($dirs as $d) {
    $dirPath = $root . DIRECTORY_SEPARATOR . $d;
    if (!is_dir($dirPath)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirPath));
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $path = $file->getPathname();
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (!in_array($ext, ['php', 'js'])) continue;

        $content = file_get_contents($path);
        foreach ($queries as $q) {
            if (stripos($content, $q) !== false) {
                $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
                $results[] = [
                    'file' => $rel,
                    'query' => $q
                ];
            }
        }
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
