<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== Cleaning Fuel Transaction Data ===\n";

$tables = [
    'fuel_transactions',
    'fuel_readings',
    'fuel_daily_readings',
    'fuel_reconciliation'
];

foreach ($tables as $t) {
    try {
        $pdo->exec("DELETE FROM `$t` WHERE 1=1");
        echo "Deleted all rows from $t.\n";
    } catch (Exception $e) {
        echo "Error deleting from $t: " . $e->getMessage() . "\n";
    }
}

// Clear Fuel type alerts from variance_alerts
try {
    $stmt = $pdo->prepare("DELETE FROM variance_alerts WHERE LOWER(TRIM(transaction_type)) = 'fuel'");
    $stmt->execute();
    echo "Deleted Fuel-type variance alerts.\n";
} catch (Exception $e) {
    echo "Error deleting from variance_alerts: " . $e->getMessage() . "\n";
}

echo "\n=== Verifying Final Counts ===\n";
foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t: $count rows\n";
    } catch (Exception $e) {
        echo "$t: error: " . $e->getMessage() . "\n";
    }
}
try {
    $count = $pdo->query("SELECT COUNT(*) FROM variance_alerts WHERE LOWER(TRIM(transaction_type)) = 'fuel'")->fetchColumn();
    echo "variance_alerts (Fuel): $count rows\n";
} catch (Exception $e) {}
