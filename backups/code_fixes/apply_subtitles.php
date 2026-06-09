<?php
$files = [
    'public/manager_fuel_transactions.php' => [
        'title' => 'Fuel Transactions',
        'sub' => 'Validation of Pump Readings and Reconciliation'
    ],
    'public/manager_fuel_deliveries.php' => [
        'title' => 'Fuel Deliveries',
        'sub' => 'Verification of Supplier Deliveries'
    ],
    'public/manager_fuel_adjustments.php' => [
        'title' => 'Adjustments',
        'sub' => 'Correction of Fuel Records'
    ],
    'public/manager_fuel_pump_master.php' => [
        'title' => 'Pump Master',
        'sub' => 'Management of Pump Calibration and Records'
    ]
];

foreach ($files as $f => $data) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        
        $new_header = '<h1 class="h1">' . $data['title'] . '</h1><div class="sub" style="margin-top:6px; color:#555; font-size:0.9rem;">' . $data['sub'] . '</div>';
        
        // Find the h1 and replace it with the new header combo
        $c = preg_replace('/<h1 class="h1">.*?<\/h1>(\s*<div class="sub".*?<\/div>)?/is', $new_header, $c);
        
        file_put_contents($f, $c);
    }
}
echo "New clean sub-texts applied successfully!\n";
