<?php
$files = [
    'public/manager_fuel_deliveries.php',
    'public/manager_fuel_adjustments.php',
    'public/manager_fuel_pump_master.php'
];

foreach ($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    
    // In the CSS block, find .fuel-section { display:none; ... } and replace with block
    $c = preg_replace('/\.fuel-section\s*\{\s*display:none;/is', '.fuel-section { display:block;', $c);
    
    file_put_contents($f, $c);
}
echo "Content visible!\n";
