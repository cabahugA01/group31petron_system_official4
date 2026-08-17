<?php
require_once __DIR__ . '/../public/db_connect.php';

// Check current notifications table schema
$cols = $pdo->query("DESCRIBE notifications")->fetchAll(PDO::FETCH_ASSOC);
echo "=== notifications table columns ===\n";
foreach ($cols as $c) echo "  " . $c['Field'] . " (" . $c['Type'] . ")\n";

// Check users table for shift/shift_id column
$ucols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== users table columns ===\n";
foreach ($ucols as $c) echo "  " . $c['Field'] . " (" . $c['Type'] . ")\n";

// Check fuel_readings or fuel_meter_readings table for shift_period column
foreach (['fuel_readings','fuel_meter_readings','fuel_transactions'] as $t) {
    try {
        $tc = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
        echo "\n=== $t columns ===\n";
        foreach ($tc as $c) {
            if (stripos($c['Field'],'shift') !== false || stripos($c['Field'],'period') !== false) {
                echo "  >> " . $c['Field'] . " (" . $c['Type'] . ")\n";
            }
        }
        echo "  (full: " . implode(', ', array_column($tc,'Field')) . ")\n";
    } catch (Exception $e) {
        echo "\n=== $t: NOT FOUND ===\n";
    }
}

// Check stock_requests columns
$t = 'stock_requests';
$tc = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== $t columns ===\n";
echo "  " . implode(', ', array_column($tc,'Field')) . "\n";

// Check void_requests / void_transactions
foreach (['void_requests','voided_transactions','transaction_void_requests'] as $t) {
    try {
        $tc = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
        echo "\n=== $t columns ===\n";
        echo "  " . implode(', ', array_column($tc,'Field')) . "\n";
    } catch (Exception $e) {
        echo "\n=== $t: NOT FOUND ===\n";
    }
}
