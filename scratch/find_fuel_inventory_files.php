<?php
$dir = __DIR__ . '/../public';
$files = scandir($dir);
foreach ($files as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        $content = file_get_contents($dir . '/' . $file);
        if (stripos($content, 'fuel_inventory') !== false) {
            echo "$file\n";
        }
    }
}
