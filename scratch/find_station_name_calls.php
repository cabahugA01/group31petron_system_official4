<?php
$dir = __DIR__ . '/../public';
$files = scandir($dir);
foreach ($files as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        $content = file_get_contents($dir . '/' . $file);
        if (stripos($content, 'user_station_name') !== false) {
            echo "$file\n";
        }
    }
}
