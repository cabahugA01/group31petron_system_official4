<?php
$c = file_get_contents('partials/header.php');
$lines = explode("\n", $c);
// Find manager nav sections generically
foreach ($lines as $i => $l) {
    if (stripos($l, 'manager') !== false && stripos($l, 'href') !== false) {
        echo ($i+1) . ': ' . trim($l) . "\n";
    }
}
