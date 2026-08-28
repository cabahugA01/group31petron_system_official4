<?php
session_start();
$_SESSION['user'] = [
    'id' => 4,
    'role' => 'admin',
    'username' => 'pepito',
    'station_id' => 1253,
    'first_name' => 'Romeca Katherine Jane',
    'last_name' => 'Tello Pepito'
];
$_SESSION['last_activity'] = time();

$_GET['format'] = 'pdf';

ob_start();
try {
    include __DIR__ . '/public/export_employee_list.php';
} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage();
}
$out = ob_get_clean();

file_put_contents(__DIR__ . '/scratch/official_simplereport_pdf.pdf', $out);
echo "OFFICIAL SIMPLE REPORT PDF GENERATED: " . strlen($out) . " bytes\n";
