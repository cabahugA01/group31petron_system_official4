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
        // Remove the exact block I added
        $c = preg_replace('/<div class="sub" style="margin-top:10px; line-height:1\.6; font-size:0\.9rem;">.*?<\/div>/is', '', $c);
        file_put_contents($f, $c);
    }
}
echo "Removed the documentation text from the UI!\n";
