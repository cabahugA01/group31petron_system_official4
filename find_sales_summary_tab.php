<?php
$file = __DIR__ . '/public/manager_fuel_management_complete.php';
$lines = file($file);
foreach ($lines as $i => $line) {
    if (stripos($line, 'sales-summary') !== false || stripos($line, 'daily-operations') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
