<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== MERCHANDISE TRANSACTIONS RECORD COUNT ===\n";
$cnt_m = $pdo->query("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = 1253")->fetchColumn();
echo "Count: $cnt_m\n";

echo "=== JOB ORDERS RECORD COUNT ===\n";
$cnt_j = $pdo->query("SELECT COUNT(*) FROM job_orders WHERE station_id = 1253")->fetchColumn();
echo "Count: $cnt_j\n";

echo "=== PAYMENT METHODS IN MERCHANDISE_TRANSACTIONS ===\n";
$pm_m = $pdo->query("SELECT DISTINCT payment_method FROM merchandise_transactions WHERE station_id = 1253")->fetchAll(PDO::FETCH_COLUMN);
print_r($pm_m);

echo "=== WORKFLOW STATUSES IN MERCHANDISE_TRANSACTIONS ===\n";
$st_m = $pdo->query("SELECT DISTINCT workflow_status FROM merchandise_transactions WHERE station_id = 1253")->fetchAll(PDO::FETCH_COLUMN);
print_r($st_m);

echo "=== JOB ORDER STATUSES ===\n";
$st_j = $pdo->query("SELECT DISTINCT status FROM job_orders WHERE station_id = 1253")->fetchAll(PDO::FETCH_COLUMN);
print_r($st_j);
