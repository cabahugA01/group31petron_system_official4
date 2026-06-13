<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = ['purchase_orders', 'suppliers', 'fuel_deliveries', 'fuel_batches', 'products', 'merchandise_transactions', 'fuel_transactions', 'job_orders'];
foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "Table: $t | Count: $c\n";
    } catch (Exception $e) {
        echo "Table: $t | Error: " . $e->getMessage() . "\n";
    }
}
