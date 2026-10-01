<?php
$files = scandir(__DIR__);
$matching = [];
foreach ($files as $f) {
    if (stripos($f, 'pric') !== false || stripos($f, 'prod') !== false || stripos($f, 'fuel') !== false || stripos($f, 'pump') !== false) {
        $matching[] = $f;
    }
}
header('Content-Type: text/plain');
echo implode("\n", $matching);
