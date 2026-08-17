<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = [
    'audit_logs', 'activity_logs', 'audit_trail', 'merchandise_transactions',
    'job_orders', 'fuel_transactions', 'fuel_sales_closings', 'stock_requests',
    'void_requests', 'transaction_adjustments', 'user_form_drafts', 'master_data_requests'
];

foreach ($tables as $table) {
    try {
        $cols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN);
        echo "TABLE: $table\nCOLUMNS: " . implode(', ', $cols) . "\n\n";
    } catch (Exception $e) {
        echo "TABLE: $table (NOT FOUND OR ERROR: " . $e->getMessage() . ")\n\n";
    }
}
