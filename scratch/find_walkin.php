<?php
$lines = file(__DIR__ . '/../public/staff_transactions_hub.php');
$found = [];
foreach ($lines as $num => $line) {
    if (stripos($line, 'Walk-in') !== false) {
        $found[] = ($num + 1) . ": " . trim($line);
    }
}
echo implode("\n", array_slice($found, 0, 50));
