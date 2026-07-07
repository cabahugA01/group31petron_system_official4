<?php
$dir = __DIR__ . '/public';
$files = glob("$dir/*.php");
foreach ($files as $file) {
    $content = file_get_content_or_false($file);
    if ($content && stripos($content, 'stock_requests') !== false) {
        echo basename($file) . "\n";
    }
}
function file_get_content_or_false($f) {
    return @file_get_contents($f);
}
