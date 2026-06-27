<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = [
    'fuel_transactions',
    'merchandise_transactions',
    'job_orders',
    'fuel_inventory',
    'station_inventory',
    'inventory',
    'stock_requests',
    'fuel_stock_requests'
];

foreach ($tables as $table) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "Table: $table - Count: $count\n";
    } catch (Exception $e) {
        echo "Table: $table - Error: {$e->getMessage()}\n";
    }
}
