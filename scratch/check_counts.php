<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = ['fuel_transactions', 'fuel_readings', 'fuel_daily_readings', 'fuel_reconciliation', 'variance_alerts'];
foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t: $count rows\n";
    } catch (Exception $e) {
        echo "$t: error: " . $e->getMessage() . "\n";
    }
}
