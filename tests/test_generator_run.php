<?php
// Mock session as superadmin
session_start();
$_SESSION['user'] = [
    'id' => 1,
    'user_id' => 1,
    'username' => 'developer',
    'role' => 'superadmin',
    'station_id' => 0
];
$_SESSION['role'] = 'superadmin';
$_SESSION['user_id'] = 1;

ob_start();
require __DIR__ . '/../backend/api/superadmin_notification_generator.php';
$output = ob_get_clean();

echo "Generator output: " . $output . "\n";

require __DIR__ . '/../public/db_connect.php';
$total = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
echo "Total notifications after generator run: " . $total . "\n";
