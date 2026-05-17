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
    
    // Add bottom padding to .mfm-wrap
    $c = preg_replace('/\.mfm-wrap\s*\{\s*max-width:1400px;\s*margin:0\s*auto;\s*padding:10px;\s*\}/is', '.mfm-wrap { max-width:1400px; margin:0 auto; padding:10px; padding-bottom:120px; }', $c);
    
    file_put_contents($f, $c);
}
echo "Padding fixed!\n";
