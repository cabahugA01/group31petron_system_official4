<?php
$lines = file(__DIR__ . '/../public/staff_transactions_hub.php');
$found = [];
foreach ($lines as $num => $line) {
    if (strpos($line, 'function ') !== false && (strpos($line, 'Customer') !== false || strpos($line, 'type') !== false || strpos($line, 'select') !== false)) {
        $found[] = ($num + 1) . ": " . trim($line);
    }
}
echo implode("\n", array_slice($found, 0, 50));
