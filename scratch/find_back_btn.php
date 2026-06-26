<?php
$lines = file(__DIR__ . '/../public/staff_transactions_hub.php');
$found = [];
foreach ($lines as $num => $line) {
    if (stripos($line, 'back') !== false && (stripos($line, 'button') !== false || stripos($line, 'btn') !== false || stripos($line, 'href') !== false)) {
        $found[] = ($num + 1) . ": " . trim($line);
    }
}
echo implode("\n", $found);
