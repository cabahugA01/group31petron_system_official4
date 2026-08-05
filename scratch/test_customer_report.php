<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../public/reports/admin_reports_data.php';

try {
    $data = getAdminReportData($pdo, 0, '2026-01-01', '2026-12-31', 'customer', 'customer_report', []);
    echo "Customer rows: " . count($data['rows'] ?? []) . "\n";
    echo "Customer details entries: " . count($data['customer_details'] ?? []) . "\n";
    echo "Success!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
