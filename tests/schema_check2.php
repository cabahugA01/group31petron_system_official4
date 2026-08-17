<?php
require_once __DIR__ . '/../public/db_connect.php';

// Check master data requests table
foreach (['master_data_requests','master_requests','merchandise_requests','service_type_requests','customer_requests'] as $t) {
    try {
        $tc = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
        echo "=== $t ===\n  " . implode(', ', array_column($tc,'Field')) . "\n";
    } catch (Exception $e) { echo "=== $t: NOT FOUND ===\n"; }
}

// Check job_orders table
$tc = $pdo->query("DESCRIBE job_orders")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== job_orders ===\n  " . implode(', ', array_column($tc,'Field')) . "\n";

// Check merchandise_transactions
$tc = $pdo->query("DESCRIBE merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== merchandise_transactions ===\n  " . implode(', ', array_column($tc,'Field')) . "\n";

// Check fuel_adjustments
$tc = $pdo->query("DESCRIBE fuel_adjustments")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== fuel_adjustments ===\n  " . implode(', ', array_column($tc,'Field')) . "\n";

// Check shifts table
foreach (['shifts','shift_records','staff_shifts'] as $t) {
    try {
        $tc = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
        echo "\n=== $t ===\n  " . implode(', ', array_column($tc,'Field')) . "\n";
    } catch (Exception $e) { echo "\n=== $t: NOT FOUND ===\n"; }
}

// Check for request_transaction_action or adjust_transaction tables
foreach (['transaction_adjustments','adjustment_requests','merchandise_adjustments'] as $t) {
    try {
        $tc = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
        echo "\n=== $t ===\n  " . implode(', ', array_column($tc,'Field')) . "\n";
    } catch (Exception $e) { echo "\n=== $t: NOT FOUND ===\n"; }
}

// Check fuel_sales_closing / shift_closing
foreach (['fuel_sales_closing','shift_closing','fuel_closing_records','fuel_shift_closing'] as $t) {
    try {
        $tc = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
        echo "\n=== $t ===\n  " . implode(', ', array_column($tc,'Field')) . "\n";
    } catch (Exception $e) { echo "\n=== $t: NOT FOUND ===\n"; }
}
