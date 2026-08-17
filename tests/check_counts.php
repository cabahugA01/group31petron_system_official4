<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "--- Current Notification & Pending Counts ---\n";
$tables = [
    'notifications',
    'job_orders',
    'merchandise_transactions',
    'fuel_transactions',
    'stock_requests',
    'fuel_stock_requests',
    'purchase_orders',
    'pending_price_approvals',
    'fuel_adjustments'
];

foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        echo str_pad($t, 30) . ": " . $c . "\n";
    } catch (Exception $e) {
        echo str_pad($t, 30) . ": (table not found)\n";
    }
}
