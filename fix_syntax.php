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
    
    // Fix the ternary operator syntax error caused by the previous string replacement
    $c = str_replace("==='success'-'check-circle'", "==='success'?'check-circle'", $c);
    
    file_put_contents($f, $c);
}
echo "Syntax error fixed!\n";
