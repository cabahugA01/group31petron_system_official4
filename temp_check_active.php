<?php
$dir = new RecursiveDirectoryIterator(__DIR__);
$it = new RecursiveIteratorIterator($dir);
$files = new RegexIterator($it, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);
$broken = [];
$checked = 0;

foreach ($files as $f) {
    $path = $f[0];
    if (strpos($path, 'vendor') !== false || strpos($path, 'scratch') !== false || strpos($path, '.git') !== false || strpos($path, 'app') !== false) continue;
    $checked++;
    $code = file_get_contents($path);
    $fileDir = dirname($path);

    // match __DIR__ . '/something'
    if (preg_match_all("/(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*['\"]([^'\"]+)['\"]/", $code, $m)) {
        foreach ($m[1] as $rel) {
            $target = $fileDir . '/' . ltrim($rel, '/\\');
            if (!file_exists($target)) {
                $broken[] = ['file' => $path, 'target' => $rel, 'resolved' => realpath(dirname($target)) ?: $target];
            }
        }
    }
}

echo "Checked $checked active application files (excluding app/ prototype and vendor).\n";
if (empty($broken)) {
    echo "ALL ACTIVE APPLICATION INCLUDES RESOLVE 100% PERFECTLY!\n";
} else {
    echo "BROKEN INCLUDES FOUND: " . count($broken) . "\n";
    foreach ($broken as $b) {
        echo "- In {$b['file']}: cannot find {$b['target']}\n";
    }
}
