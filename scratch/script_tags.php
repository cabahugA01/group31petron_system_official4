<?php
$lines = file('c:/xampp/htdocs/group31petron_system_official4/partials/header.php');
foreach ($lines as $i => $line) {
    if (strpos($line, '<script') !== false) {
        echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
    }
    if (strpos($line, '</script>') !== false) {
        echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
    }
}
