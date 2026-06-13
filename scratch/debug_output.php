<?php
session_start();
$_SESSION['user'] = [
    'id' => 3,
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

ob_start();
try {
    include __DIR__ . '/../public/manager_dashboard.php';
} catch (Throwable $e) {
    echo "\nEXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
}
$out = ob_get_clean();
echo "LENGTH: " . strlen($out) . "\n";
echo "FIRST 1000 CHARACTERS:\n";
echo substr($out, 0, 1000) . "\n";
if (strlen($out) > 1000) {
    echo "LAST 1000 CHARACTERS:\n";
    echo substr($out, -1000) . "\n";
}
