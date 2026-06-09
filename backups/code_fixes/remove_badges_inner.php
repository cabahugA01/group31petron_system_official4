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
        
        // Remove specific header badges
        $c = preg_replace('/<span class="tag-open">\s*<i class="fas fa-clock"><\/i>\s*<\?php echo [^;]+; \?>\s*Pending Validation\s*<\/span>/is', '', $c);
        $c = preg_replace('/<span class="tag-open">\s*<i class="fas fa-clock"><\/i>\s*<\?php echo [^;]+; \?>\s*Awaiting Review\s*<\/span>/is', '', $c);
        $c = preg_replace('/<span class="tag-open">\s*<i class="fas fa-clock"><\/i>\s*<\?php echo [^;]+; \?>\s*Awaiting Approval\s*<\/span>/is', '', $c);
        
        // Remove high variance badge
        $c = preg_replace('/<span class="tag-investigate">\s*<i class="fas fa-exclamation-triangle"><\/i>\s*<\?php echo [^;]+; \?>\s*High Variance[^\n]*\s*<\/span>/is', '', $c);
        
        file_put_contents($f, $c);
    }
}
echo "Cleaned up inner header badges safely!\n";
