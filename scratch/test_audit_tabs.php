<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../public/reports/admin_reports_data.php';

$tabs = ['user_activity_logs','login_history','transaction_logs','inventory_logs','approval_logs','archived_deactivated'];
foreach ($tabs as $t) {
    try {
        $d = getAdminReportData($pdo, 0, '2026-01-01', '2026-12-31', 'audit', $t, []);
        echo $t . ': ' . count($d['rows'] ?? []) . " rows fetched successfully\n";
    } catch (Exception $e) {
        echo $t . ': ERROR - ' . $e->getMessage() . "\n";
    }
}
