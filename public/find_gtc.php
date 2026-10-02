<?php
$lines = file(__DIR__ . '/../backend/lib.php');
foreach ($lines as $n => $l) {
    if (strpos($l, 'get_tank_config') !== false) {
        echo "Line " . ($n + 1) . ": $l\n";
    }
}
