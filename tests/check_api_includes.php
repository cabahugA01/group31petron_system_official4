<?php
// Check what libraries the "no-lib" API files actually include
$files = [
    'backend/api/get_mechanic_status.php',
    'backend/api/get_service_types.php',
    'backend/api/get_vehicle_types.php',
    'backend/api/save_fuel_reading_adjustment.php',
    'backend/api/superadmin_admin_management_api.php',
    'backend/api/superadmin_module_config_api.php',
    'backend/api/superadmin_station_management_api.php',
];
foreach ($files as $f) {
    if (!file_exists($f)) { echo $f . ": FILE NOT FOUND\n"; continue; }
    $c = file_get_contents($f);
    preg_match_all("/require[_once]*\s*[\(\s]*[\"'](.*?)[\"\'][)\s]*;/i", $c, $m);
    $includes = $m[1] ?? [];
    $hasLogin = strpos($c, 'require_login()') !== false ? 'YES' : 'NO';
    echo sprintf("%-55s login=%-3s includes: %s\n", basename($f), $hasLogin, implode(', ', $includes));
}
