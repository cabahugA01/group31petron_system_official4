<?php
$files = scandir(__DIR__);
$hits = [];
foreach ($files as $f) {
    if (pathinfo($f, PATHINFO_EXTENSION) === 'php') {
        $content = file_get_contents(__DIR__ . '/' . $f);
        if (stripos($content, 'Product & Pricing') !== false || stripos($content, 'Product and Pricing') !== false || stripos($content, 'Product &amp; Pricing') !== false) {
            $hits[] = $f;
        }
    }
}
header('Content-Type: text/plain');
echo "HITS FOR Product & Pricing:\n" . implode("\n", $hits);
