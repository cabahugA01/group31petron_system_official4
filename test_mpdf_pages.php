<?php
session_start();
$_SESSION['user'] = [
    'id' => 4,
    'role' => 'admin',
    'username' => 'pepito',
    'station_id' => 1253
];
$_SESSION['last_activity'] = time();

$_GET['format'] = 'pdf';
ob_start();
try {
    include __DIR__ . '/public/export_employee_list.php';
} catch (Exception $e) {}
$out = ob_get_clean();

file_put_contents(__DIR__ . '/scratch/Petron_User_Management_Report_2026-08-28.pdf', $out);
echo "SUCCESS: Saved clean PDF! Size: " . strlen($out) . " bytes\n";
