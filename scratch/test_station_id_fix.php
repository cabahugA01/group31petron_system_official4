<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../public/reports/admin_reports_data.php';

echo "=== TESTING ALL CATEGORIES WITH STATION_ID = 1253 ===\n";
$station_id = 1253;
$date_from = '2026-08-01';
$date_to = '2026-08-05';

$cats = [
    'sales' => ['fuel_sales', 'daily_merch_service'],
    'inventory' => ['merch_inventory', 'fuel_inventory', 'inventory_movement', 'inventory_adjustment', 'expired_damaged'],
    'operations' => ['job_order', 'mechanic_performance'],
    'procurement' => ['purchase_order', 'delivery_validation', 'po_vs_received', 'stock_in_approval'],
    'financial' => ['revenue_summary', 'receivables'],
    'customer' => ['customer_report'],
    'audit' => ['login_history', 'user_activity_logs', 'transaction_logs', 'inventory_logs', 'approval_logs', 'archived_logs'],
];

foreach ($cats as $c => $tabs) {
    foreach ($tabs as $t) {
        try {
            $data = getAdminReportData($pdo, $station_id, $date_from, $date_to, $c, $t);
            echo "[OK] Category: $c | Tab: $t\n";
        } catch (Exception $e) {
            echo "[FAIL] Category: $c | Tab: $t -> " . $e->getMessage() . "\n";
        }
    }
}
