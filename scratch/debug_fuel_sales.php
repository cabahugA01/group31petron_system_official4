<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../public/reports/admin_reports_data.php';

echo "=== FUEL SUMMARY AFTER REGEXP NORMALIZE ===\n";
$rows = $pdo->query(
    "SELECT TRIM(REGEXP_REPLACE(fuel_type, '\\\\s*[0-9]+\\\\s*-\\\\s*[0-9]+\\\\s*\$', '')) as fuel_type_name,
            COUNT(DISTINCT pump_id) as ugt_count,
            SUM(liters_sold) as total_volume,
            MAX(price_per_liter) as avg_price,
            SUM(total_amount) as total_sales
     FROM fuel_transactions
     WHERE station_id = 1253
     GROUP BY fuel_type_name
     ORDER BY total_sales DESC"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) print_r($r);

echo "\n=== DISTINCT fuel_types NORMALIZED ===\n";
$types = $pdo->query(
    "SELECT DISTINCT TRIM(REGEXP_REPLACE(fuel_type, '\\\\s*[0-9]+\\\\s*-\\\\s*[0-9]+\\\\s*\$', '')) as ft
     FROM fuel_transactions WHERE station_id = 1253 ORDER BY ft"
)->fetchAll(PDO::FETCH_COLUMN);
print_r($types);

echo "\n=== FULL DATA TEST (last 30 days) ===\n";
$data = getAdminReportData($pdo, 1253, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'), 'sales', 'fuel_sales');
echo "ugt_rows: " . count($data['ugt_rows']) . "\n";
echo "fuel_summary: " . count($data['fuel_summary']) . "\n";
echo "reconciliation total_fuel_sales: ₱" . number_format((float)($data['reconciliation']['total_fuel_sales'] ?? 0), 2) . "\n";
echo "variance rows: " . count($data['variance']) . "\n";
echo "pump_list count: " . count($data['pump_list']) . "\n";
echo "fuel_types available: ";
print_r($data['fuel_types']);

echo "\n=== FUEL SUMMARY ROWS ===\n";
foreach ($data['fuel_summary'] as $r) {
    echo "  {$r['fuel_type']} | UGTs: {$r['ugt_count']} | Vol: {$r['total_volume']} L | Sales: ₱" . number_format((float)$r['total_sales'], 2) . "\n";
}
