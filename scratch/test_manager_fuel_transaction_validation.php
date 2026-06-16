<?php
session_start();
$_SESSION['user'] = [
    'id' => 3, // manager user id
    'username' => 'Edgar',
    'role' => 'manager',
    'station_id' => 1
];

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

ob_start();
try {
    include __DIR__ . '/../public/manager_fuel_transaction_validation.php';
    echo "NO COMPILE OR EXECUTION ERROR!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
$output = ob_get_clean();
if (strpos(strtolower($output), 'fatal error') !== false || strpos(strtolower($output), 'database error') !== false || strpos(strtolower($output), 'sqlstate') !== false) {
    echo "FUEL TRANSACTION VALIDATION FAILED WITH ERRORS:\n" . substr($output, 0, 1000) . "\n";
} else {
    echo "SUCCESS: Fuel Transaction Validation Page rendered cleanly.\n";
}
