<?php
$lines = file('c:/xampp/htdocs/group31petron_system_official4/partials/header.php');
foreach ($lines as $i => $line) {
    if (strpos($line, 'stock_request') !== false || strpos($line, 'Purchase Request') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
