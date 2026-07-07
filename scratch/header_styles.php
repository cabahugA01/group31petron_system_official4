<?php
$lines = file('c:/xampp/htdocs/group31petron_system_official4/partials/header.php');
foreach ($lines as $i => $line) {
    if (stripos($line, 'top-header') !== false || stripos($line, 'header-left') !== false || stripos($line, 'header-right') !== false || stripos($line, 'header-center') !== false) {
        echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
    }
}
