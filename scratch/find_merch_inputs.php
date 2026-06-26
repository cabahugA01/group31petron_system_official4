<?php
$lines = file(__DIR__ . '/../public/staff_transactions_hub.php');
$found = [];
foreach ($lines as $num => $line) {
    if (stripos($line, 'merchFirstName') !== false || stripos($line, 'merchLastName') !== false || stripos($line, 'merchContactNumber') !== false) {
        $found[] = ($num + 1) . ": " . trim($line);
    }
}
echo implode("\n", $found);
