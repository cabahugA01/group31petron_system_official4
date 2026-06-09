<?php
$files = [
    'public/manager_fuel_transactions.php',
    'public/manager_fuel_deliveries.php',
    'public/manager_fuel_adjustments.php',
    'public/manager_fuel_pump_master.php'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        
        // Remove ALL section-head divs completely
        $c = preg_replace('/<div class="section-head"[^>]*>.*?<\/div>\s*/is', '', $c);
        
        file_put_contents($f, $c);
    }
}
echo "Removed all internal section headers to make the UI ultra clean!\n";
