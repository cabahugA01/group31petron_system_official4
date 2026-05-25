<?php
$file = __DIR__ . '/public/manager_fuel_management_complete.php';
$lines = file($file);
foreach ($lines as $i => $line) {
    if (stripos($line, 'id="fuel-transactions"') !== false ||
        stripos($line, 'id="sales-summary"') !== false ||
        stripos($line, 'id="fuel-deliveries"') !== false ||
        stripos($line, 'id="daily-operations"') !== false ||
        stripos($line, 'id="pump-master"') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
