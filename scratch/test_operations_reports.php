<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../public/reports/admin_reports_data.php';

$station_id = 1253;
$date_from  = '2026-07-01';
$date_to    = '2026-08-05';

$tabs = ['job_order', 'mechanic_performance'];

foreach ($tabs as $tab) {
    echo "=== TESTING OPERATIONS TAB: $tab ===\n";
    try {
        $res = getAdminReportData($pdo, $station_id, $date_from, $date_to, 'operations', $tab, []);
        $rowCount = count($res['rows'] ?? []);
        echo "Success! Row count: $rowCount\n";
        echo "Summary totals: ";
        print_r($res['summary'] ?? []);
        if ($rowCount > 0) {
            echo "Sample row: ";
            print_r($res['rows'][0]);
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
