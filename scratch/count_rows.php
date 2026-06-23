<?php
require_once __DIR__ . '/../public/db_connect.php';
$tables = [
    'merchandise_transactions',
    'sales',
    'job_orders',
    'fuel_transactions'
];
foreach ($tables as $t) {
    try {
        $cnt = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo "$t: $cnt rows\n";
    } catch (Exception $e) {
        echo "$t: Error - " . $e->getMessage() . "\n";
    }
}
