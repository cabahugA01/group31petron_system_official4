<?php
$content = file_get_contents(__DIR__ . '/../backend/lib.php');
preg_match_all('/function\s+([a-zA-Z0-9_]+)/', $content, $matches);
foreach ($matches[1] as $fn) {
    if (stripos($fn, 'station') !== false) {
        echo "$fn\n";
    }
}
