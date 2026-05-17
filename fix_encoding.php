<?php
$files = [
    'public/manager_fuel_transactions.php',
    'public/manager_fuel_deliveries.php',
    'public/manager_fuel_adjustments.php',
    'public/manager_fuel_pump_master.php'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    
    // Replace the replacement character with a hyphen
    $c = str_replace("\xEF\xBF\xBD", "-", $c);
    
    // Some specific fixes:
    $c = str_replace('Pump #-', 'Pump #', $c); // If it replaced it in Pump #, change to Pump #
    $c = str_replace('?-', '-', $c); // Just in case
    
    file_put_contents($f, $c);
}
echo "Replacement complete!\n";
