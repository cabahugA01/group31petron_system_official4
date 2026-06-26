<?php
session_start();
$_SESSION['user'] = [
    'id' => 12,
    'username' => 'edgar_manager',
    'role' => 'manager',
    'station_id' => 1253
];
$_SERVER['SCRIPT_NAME'] = '/public/manager_inventory_fuel.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['tab'] = 'overview';

// Define some dummy server vars to avoid warnings
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

ob_start();
include __DIR__ . '/../public/manager_inventory_fuel.php';
$html = ob_get_clean();

$rows_count = substr_count($html, 'class="fuel-row"');
echo "Number of fuel-row elements rendered: " . $rows_count . "\n";

// Let's print out the first few fuel-row IDs if any
preg_match_all('/data-id="([^"]+)"/', $html, $matches);
echo "Rendered IDs: " . implode(', ', $matches[1]) . "\n";
