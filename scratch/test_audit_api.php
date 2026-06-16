<?php
// Mock session
session_start();
$_SESSION['user'] = [
    'id' => 1,
    'username' => 'Kathrine',
    'role' => 'admin',
    'station_id' => 1
];

// Mock GET parameters
$_GET['action'] = 'audit_trail';
$_GET['date_from'] = date('Y-m-01');
$_GET['date_to'] = date('Y-m-d');

// Include db connection first to have $pdo defined
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// We override api_ok and api_err if they are defined, or let them execute.
// Let's see if we can capture output of the file
ob_start();
try {
    include __DIR__ . '/../backend/api/admin_reports_audit_api.php';
} catch (Exception $e) {
    echo "API EXCEPTION: " . $e->getMessage() . "\n";
}
$output = ob_get_clean();
echo "API OUTPUT:\n" . $output . "\n";
