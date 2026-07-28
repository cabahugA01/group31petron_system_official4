<?php
$lines = file('c:/xampp/htdocs/group31petron_system_official4/partials/header.php');
foreach ($lines as $i => $line) {
    if (strpos($line, 'sidebar-sub-item') !== false || strpos($line, 'Merchandise Inventory') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
