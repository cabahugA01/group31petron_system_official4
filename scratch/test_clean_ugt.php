<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../public/reports/admin_reports_data.php';

$data = getAdminReportData($pdo, 1253, '2026-07-01', '2026-08-05', 'sales', 'fuel_sales');
echo "=== UGT ROWS WITH CLEAN UGT NO ===\n";
$i = 1;
foreach ($data['ugt_rows'] as &$r) {
    $ugt_formatted = sprintf('UGT-%02d', $i++);
    echo "  {$ugt_formatted} | Fuel Type: {$r['fuel_type']} | Pump ID: {$r['pump_id']} | Net Vol: {$r['net_volume_sold']} L | Sales: ₱" . number_format($r['total_fuel_sales'], 2) . "\n";
}
