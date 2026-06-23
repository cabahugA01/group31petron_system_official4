<?php
$files = [
    'public/staff_transactions_hub.php',
    'backend/process_staff_transaction.php',
    'backend/submit_transaction.php',
    'public/transactions.php'
];

foreach ($files as $file) {
    $path = __DIR__ . '/../' . $file;
    if (!file_exists($path)) {
        echo "$file does not exist\n";
        continue;
    }
    $content = file_get_contents($path);
    echo "=== File: $file ===\n";
    if (strpos($content, 'merchandise_transactions') !== false) {
        echo "Contains: merchandise_transactions\n";
    }
    if (strpos($content, 'sales') !== false) {
        echo "Contains: sales\n";
    }
    if (strpos($content, 'job_orders') !== false) {
        echo "Contains: job_orders\n";
    }
}
