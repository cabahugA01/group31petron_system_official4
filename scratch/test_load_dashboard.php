<?php
session_start();
$_SESSION['user'] = [
    'user_id' => 3,
    'username' => 'Edgar',
    'role' => 'manager',
    'first_name' => 'Edgar',
    'last_name' => 'Eslit',
    'station_id' => 1253,
    'status' => 'Active'
];
$_SESSION['user_id'] = 3;
$_SESSION['role'] = 'manager';

try {
    ob_start();
    include __DIR__ . '/../public/manager_dashboard.php';
    $output = ob_get_clean();
    
    // Find where Financial Snapshot is
    $pos = strpos($output, 'Financial Snapshot');
    if ($pos !== false) {
        echo "=== HTML SNIPPET AROUND FINANCIAL SNAPSHOT ===\n";
        echo substr($output, $pos - 100, 1500);
    } else {
        echo "Could not find 'Financial Snapshot' in output HTML\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
