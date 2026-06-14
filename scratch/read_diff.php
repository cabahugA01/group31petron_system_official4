<?php
$file = __DIR__ . '/diff.txt';
if (!file_exists($file)) {
    echo "File not found: $file\n";
    exit;
}
$content = file_get_contents($file);
if (substr($content, 0, 2) === "\xFF\xFE") {
    $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
}
$lines = explode("\n", $content);
echo "Total lines in diff: " . count($lines) . "\n";
