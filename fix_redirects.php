<?php
$files = [
    'public/manager_fuel_transactions.php' => [
        "manager_fuel_management_complete.php#fuel-transactions" => "manager_fuel_transactions.php",
        "manager_fuel_management_complete.php#variance-reports" => "manager_fuel_transactions.php",
        "manager_fuel_management_complete.php#reconciliation" => "manager_fuel_transactions.php",
    ],
    'public/manager_fuel_deliveries.php' => [
        "manager_fuel_management_complete.php#fuel-deliveries" => "manager_fuel_deliveries.php",
    ],
    'public/manager_fuel_adjustments.php' => [
        "manager_fuel_management_complete.php#adjustments" => "manager_fuel_adjustments.php",
    ],
    'public/manager_fuel_pump_master.php' => [
        "manager_fuel_management_complete.php#pump-master" => "manager_fuel_pump_master.php",
    ]
];

foreach ($files as $file => $replacements) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        foreach ($replacements as $old => $new) {
            $content = str_replace($old, $new, $content);
        }
        // Also just blindly replace any remaining ones to redirect to the current file to avoid sending them to the old complete file
        $basename = basename($file);
        $content = preg_replace('/manager_fuel_management_complete\.php(#[a-zA-Z0-9\-_]*)?/', $basename, $content);
        
        file_put_contents($file, $content);
    }
}
echo "Redirects fixed!\n";
