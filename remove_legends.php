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
        
        // Remove the Verified/Rejected/Adjusted/Highlighted legend
        $c = preg_replace('/<div style="margin-top:8px;font-size:\.76rem;color:#888;display:flex;gap:16px;flex-wrap:wrap;">.*?<\/div>/is', '', $c);
        
        // Remove the Approved/Rejected/Pending legend
        $c = preg_replace('/<div style="margin-top:12px;font-size:\.75rem;color:#666;">.*?<\/div>/is', '', $c);
        
        // Also remove any similar hardcoded legends using string replacement for safety
        $c = preg_replace('/<span style="color:#CC8800;"><i class="fas fa-clock"><\/i> Highlighted rows = awaiting manager validation<\/span>/is', '', $c);
        
        file_put_contents($f, $c);
    }
}
echo "Removed table legends!\n";
