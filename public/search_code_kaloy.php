<?php
header('Content-Type: application/json');

$root = dirname(__DIR__);
$dirs = ['backend', 'public', 'partials', 'config'];
$results = [];

foreach ($dirs as $d) {
    $dirPath = $root . DIRECTORY_SEPARATOR . $d;
    if (!is_dir($dirPath)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirPath));
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $path = $file->getPathname();
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (!in_array($ext, ['php', 'js', 'json'])) continue;

        $content = file_get_contents($path);
        if (stripos($content, 'Larosa') !== false || stripos($content, 'Kaloy') !== false) {
            $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
            $results[] = $rel;
        }
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
