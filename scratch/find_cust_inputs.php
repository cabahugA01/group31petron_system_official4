<?php
$lines = file(__DIR__ . '/../public/staff_transactions_hub.php');
$found = [];
foreach ($lines as $num => $line) {
    if (strpos($line, 'name=') !== false && (strpos($line, 'cust') !== false || strpos($line, 'customer') !== false || strpos($line, 'first') !== false || strpos($line, 'last') !== false)) {
        $found[] = ($num + 1) . ": " . trim($line);
    }
}
echo implode("\n", array_slice($found, 0, 50));
