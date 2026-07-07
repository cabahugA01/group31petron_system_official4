<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user'] = [
    'id' => 7,
    'name' => 'Yyang Cabahug',
    'username' => 'yyang',
    'role' => 'staff',
    'station_id' => 1253
];
header('Location: staff_fuel_sales_summary.php');
exit;
