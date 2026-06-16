<?php
session_start();
$_SESSION['user'] = [
    'id' => 1,
    'username' => 'Kathrine',
    'role' => 'admin',
    'station_id' => 1
];

// Include db and run the main file via buffer
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

ob_start();
try {
    include __DIR__ . '/../public/admin_transactions_oversight.php';
    echo "NO COMPILE OR EXECUTION ERROR!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
$output = ob_get_clean();
// Check if output contains php fatals or database errors
if (strpos(strtolower($output), 'fatal error') !== false || strpos(strtolower($output), 'database error') !== false || strpos(strtolower($output), 'sqlstate') !== false) {
    echo "API FAILED WITH ERRORS:\n" . substr($output, 0, 1000) . "\n";
} else {
    echo "SUCCESS: Page rendered cleanly.\n";
}
