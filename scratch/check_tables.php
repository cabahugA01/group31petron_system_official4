<?php
require_once __DIR__ . '/../public/db_connect.php';

$key_tables = [
    'fuel_transactions', 'merchandise_transactions', 'merchandise_transaction_items',
    'job_orders', 'users', 'customers', 'purchase_orders', 'fuel_purchase_orders',
    'fuel_inventory', 'station_inventory', 'products', 'stations',
    'deliveries_oversight', 'pending_price_approvals', 'fuel_types',
    'audit_logs', 'activity_logs', 'login_attempts', 'system_backups', 'system_settings',
    'stock_requests',
];

echo "=== KEY TABLE EXISTENCE CHECK ===\n";
foreach ($key_tables as $tbl) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $stmt->execute([$tbl]);
        $exists = (bool)$stmt->fetchColumn();
        echo ($exists ? "[OK]  " : "[MISS]") . " $tbl\n";
        if ($exists) {
            $cnt = $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
            echo "       rows: $cnt\n";
        }
    } catch (Exception $e) {
        echo "[ERR] $tbl => " . $e->getMessage() . "\n";
    }
}

echo "\n=== ALL TABLES ===\n";
$all = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
sort($all);
foreach ($all as $t) echo "  $t\n";
