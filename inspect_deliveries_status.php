<?php
$file = __DIR__ . '/public/manager_fuel_management_complete.php';
$lines = file($file);
for ($i = 1500; $i <= 1530; $i++) {
    if (isset($lines[$i])) {
        echo ($i + 1) . ": " . $lines[$i];
    }
}
