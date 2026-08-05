<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../public/reports/admin_reports_data.php';

$station_id = 1253;
$date_from = '2026-07-01';
$date_to = '2026-08-05';

$tabs = ['merch_inventory', 'fuel_inventory', 'inventory_movement', 'inventory_adjustment', 'expired_damaged'];

foreach ($tabs as $tab) {
    echo "=== TESTING TAB: $tab ===\n";
    try {
        $res = getAdminReportData($pdo, $station_id, $date_from, $date_to, 'inventory', $tab, []);
        $rowCount = count($res['rows'] ?? []);
        echo "Success! Row count: $rowCount\n";
        if ($rowCount > 0) {
            echo "Sample row: ";
            print_r($res['rows'][0]);
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
