<?php
$search = $argv[1] ?? '';
if (!$search) {
    die("Provide search term\n");
}

function search_dir($dir, $term) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $path = $file->getPathname();
        if (strpos($path, '.git') !== false || strpos($path, 'node_modules') !== false) continue;
        $content = file_get_contents($path);
        if (strpos($content, $term) !== false) {
            echo "Match in $path\n";
            // Print matching lines
            $lines = explode("\n", $content);
            foreach ($lines as $i => $line) {
                if (strpos($line, $term) !== false) {
                    echo "  Line " . ($i + 1) . ": " . trim($line) . "\n";
                }
            }
        }
    }
}

search_dir(__DIR__, $search);
