<?php
$files = glob("C:/xampp/htdocs/group31petron_system_official4/public/*.php");
foreach ($files as $f) {
    $content = file_get_contents($f);
    if (preg_match_all('/setInterval\s*\(([^,]+),\s*([0-9]+)\)/i', $content, $matches, PREG_SET_ORDER)) {
        echo "File: " . basename($f) . "\n";
        foreach ($matches as $m) {
            echo "   -> setInterval(" . trim($m[1]) . ", " . trim($m[2]) . "ms)\n";
        }
    }
}
