<?php
$dirs = [__DIR__ . '/../public', __DIR__ . '/../backend', __DIR__ . '/../partials'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    $files = scandir($dir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            $content = file_get_contents($dir . '/' . $file);
            if (stripos($content, 'function user_station_name') !== false) {
                echo "Found in $dir/$file\n";
            }
        }
    }
}
