<?php
$lines = file('admin_set_prices_handler.php');
foreach ($lines as $n => $l) {
    if (strpos($l, 'get_fuel_details_admin') !== false) {
        echo "Line " . ($n + 1) . ": $l\n";
    }
}
