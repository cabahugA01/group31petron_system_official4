<?php
$files = [
    'public/manager_validated_transactions.php',
    'public/manager_transaction_monitoring.php',
    'public/manager_fuel_transaction_validation.php'
];

foreach ($files as $file) {
    $path = __DIR__ . '/../' . $file;
    if (file_exists($path)) {
        $content = file_get_contents($path);
        echo "=== $file ===\n";
        if (strpos($content, 'Reject') !== false) echo "Contains Reject\n";
        if (strpos($content, 'Adjust') !== false) echo "Contains Adjust\n";
        if (strpos($content, 'Approve') !== false) echo "Contains Approve\n";
    }
}
