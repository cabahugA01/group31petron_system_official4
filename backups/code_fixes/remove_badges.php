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
        
        // Match the div that contains the global notification tags
        $c = preg_replace('/<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">\s*<\?php if \(\$high_variances > 0\): \?>.*?<\?php endif; \?>\s*<\/div>/is', '', $c);
        
        file_put_contents($f, $c);
    }
}
echo "Removed global notification badges!\n";
