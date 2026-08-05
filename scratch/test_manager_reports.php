<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../public/reports/admin_reports_data.php';

$cats = [
    'sales'       => 'fuel_sales',
    'inventory'   => 'merch_inventory',
    'operations'  => 'job_order',
    'procurement' => 'purchase_order',
    'financial'   => 'revenue_summary',
    'customer'    => 'customer_report'
];

foreach ($cats as $cat => $tab) {
    try {
        $d = getAdminReportData($pdo, 0, '2026-01-01', '2026-12-31', $cat, $tab, []);
        echo "Manager Report [$cat -> $tab]: " . count($d['rows'] ?? []) . " rows fetched successfully\n";
    } catch (Exception $e) {
        echo "Manager Report [$cat -> $tab]: ERROR - " . $e->getMessage() . "\n";
    }
}
