<?php
$lines = file(__DIR__ . '/../public/staff_transactions_hub.php');
$found = [];
foreach ($lines as $num => $line) {
    if (strpos($line, '<style>') !== false || strpos($line, '</style>') !== false) {
        $found[] = ($num + 1) . ": " . trim($line);
    }
    if (strpos($line, 'button') !== false && $num < 3100) {
        $found[] = ($num + 1) . ": " . trim($line);
    }
}
echo implode("\n", array_slice($found, 0, 100));
