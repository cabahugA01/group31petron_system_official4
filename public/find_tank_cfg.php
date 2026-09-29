<?php
header('Content-Type: application/json');

$root = dirname(__DIR__);
$dirs = ['backend', 'public', 'partials'];
$matches = [];

foreach ($dirs as $d) {
    $dirPath = $root . DIRECTORY_SEPARATOR . $d;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirPath));
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $path = $file->getPathname();
        if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') continue;
        $content = file_get_contents($path);
        if (strpos($content, 'function get_tank_config') !== false) {
            $matches[] = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
        }
    }
}

echo json_encode($matches, JSON_PRETTY_PRINT);
