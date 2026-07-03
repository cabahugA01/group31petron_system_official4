<?php
$dir = __DIR__ . '/../public';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        if (stripos($content, 'fuel_adjustments') !== false) {
            echo $file->getFilename() . "\n";
        }
    }
}
