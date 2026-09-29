<?php
header('Content-Type: application/json');

$lines = file(__DIR__ . '/../backend/lib.php');
$start = 0;
foreach ($lines as $num => $line) {
    if (strpos($line, 'function get_tank_config') !== false) {
        $start = $num + 1;
        break;
    }
}

echo json_encode([
    'start_line' => $start,
    'preview' => array_slice($lines, max(0, $start - 1), 60)
], JSON_PRETTY_PRINT);
