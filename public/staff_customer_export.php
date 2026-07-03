<?php
/**
 * STAFF CUSTOMER REPORT EXPORT
 * Exports the same customer report data shown on staff_customers_report.php.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/staff_customer_report_data.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    die('Unauthorized');
}

if (!$station_id) die('Error: You are not assigned to a station.');

$format = strtolower(trim((string)($_GET['format'] ?? 'excel')));
$report = staff_customer_report_build($pdo, (int)$station_id, $_GET);
$filters = $report['filters'];
$summary = $report['summary'];
$rows = $report['rows'];

$station_name = 'Petron Station Management System';
$station_location = '';
try {
    $stmt = $pdo->prepare("SELECT name, location FROM stations WHERE id = ? LIMIT 1");
    $stmt->execute([$station_id]);
    $station = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($station) {
        $station_name = $station['name'] ?: $station_name;
        $station_location = $station['location'] ?? '';
    }
} catch (Exception $e) {}

$generated_by = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($generated_by === '') $generated_by = $me['username'] ?? 'Staff';

function staff_customer_export_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function staff_customer_export_date($date, string $format = 'M d'): string {
    $timestamp = strtotime((string)$date);
    return $timestamp ? date($format, $timestamp) : 'N/A';
}

if ($format === 'csv') {
    $filename = 'Customer_Report_' . $filters['date_start'] . '_to_' . $filters['date_end'] . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Customer ID', 'Customer Name', 'Type', 'Vehicle', 'Transaction Type', 'Total Amount', 'Date', 'Staff']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['customer_id_display'],
            $row['customer_name'],
            $row['customer_type'],
            $row['vehicle'],
            $row['transaction_type'],
            number_format((float)$row['total_amount'], 2, '.', ''),
            staff_customer_export_date($row['transaction_date']),
            $row['staff_name'],
        ]);
    }
    fclose($output);
    exit;
}

$filename = 'Customer_Report_' . $filters['date_start'] . '_to_' . $filters['date_end'] . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
echo '<head>';
echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
echo '<style>';
echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; margin-bottom: 14px; }';
echo 'th, td { border: 1px solid #000000; padding: 7px; text-align: left; font-size: 11px; }';
echo 'th { background-color: #E0E0E0; font-weight: bold; text-align: center; }';
echo 'h1 { font-size: 20px; font-weight: bold; margin: 10px 0; text-align: center; }';
echo 'h2 { font-size: 14px; font-weight: bold; margin: 16px 0 8px; background-color: #F0F0F0; padding: 6px; border: 1px solid #000; }';
echo 'p { margin: 4px 0; text-align: center; }';
echo '.text-right { text-align: right; }';
echo '.text-center { text-align: center; }';
echo '.font-bold { font-weight: bold; }';
echo '</style>';
echo '</head><body>';

echo '<h1>CUSTOMER REPORT</h1>';
echo '<p>Petron Station Management System</p>';
echo '<p>' . staff_customer_export_h($station_location ?: $station_name) . '</p>';
echo '<p><strong>Period:</strong> ' . date('F d, Y', strtotime($filters['date_start'])) . ' - ' . date('F d, Y', strtotime($filters['date_end'])) . '</p>';

echo '<h2>CUSTOMER SUMMARY</h2>';
echo '<table><tbody>';
echo '<tr><td class="font-bold">Total Customers Served</td><td class="text-right font-bold">' . number_format($summary['total_served']) . '</td></tr>';
echo '<tr><td class="font-bold">Walk-in Customers</td><td class="text-right font-bold">' . number_format($summary['walkin']) . '</td></tr>';
echo '<tr><td class="font-bold">Registered Customers</td><td class="text-right font-bold">' . number_format($summary['registered']) . '</td></tr>';
echo '<tr><td class="font-bold">New Registered Customers</td><td class="text-right font-bold">' . number_format($summary['new_registered']) . '</td></tr>';
echo '<tr><td class="font-bold">Returning Customers</td><td class="text-right font-bold">' . number_format($summary['returning']) . '</td></tr>';
echo '</tbody></table>';

echo '<h2>CUSTOMER TRANSACTION REPORT</h2>';
echo '<table><thead><tr>';
foreach (['Customer ID', 'Customer Name', 'Type', 'Vehicle', 'Transaction Type', 'Total Amount', 'Date', 'Staff'] as $heading) {
    echo '<th>' . staff_customer_export_h($heading) . '</th>';
}
echo '</tr></thead><tbody>';
if (count($rows) > 0) {
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td class="font-bold">' . staff_customer_export_h($row['customer_id_display']) . '</td>';
        echo '<td>' . staff_customer_export_h($row['customer_name']) . '</td>';
        echo '<td>' . staff_customer_export_h($row['customer_type']) . '</td>';
        echo '<td>' . staff_customer_export_h($row['vehicle']) . '</td>';
        echo '<td>' . staff_customer_export_h($row['transaction_type']) . '</td>';
        echo '<td class="text-right font-bold">₱' . number_format((float)$row['total_amount'], 2) . '</td>';
        echo '<td>' . staff_customer_export_h(staff_customer_export_date($row['transaction_date'])) . '</td>';
        echo '<td>' . staff_customer_export_h($row['staff_name']) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="8" class="text-center">No customer transactions found for the selected filters.</td></tr>';
}
echo '</tbody></table>';

echo '<h2>CUSTOMER TYPE SUMMARY</h2>';
echo '<table><tbody>';
echo '<tr><td class="font-bold">Walk-in Customers</td><td class="text-right font-bold">' . number_format($report['customer_type_summary']['Walk-in']) . '</td></tr>';
echo '<tr><td class="font-bold">Registered Customers</td><td class="text-right font-bold">' . number_format($report['customer_type_summary']['Registered']) . '</td></tr>';
echo '</tbody></table>';

echo '<h2>TRANSACTION TYPE SUMMARY</h2>';
echo '<table><tbody>';
echo '<tr><td class="font-bold">Merchandise Customers</td><td class="text-right font-bold">' . number_format($report['transaction_type_summary']['Merchandise']) . '</td></tr>';
echo '<tr><td class="font-bold">Job Order Customers</td><td class="text-right font-bold">' . number_format($report['transaction_type_summary']['Job Order']) . '</td></tr>';
echo '<tr><td class="font-bold">Fuel Customers</td><td class="text-right font-bold">' . number_format($report['transaction_type_summary']['Fuel']) . '</td></tr>';
echo '</tbody></table>';

echo '<h2>STAFF CUSTOMER SUMMARY</h2>';
echo '<table><thead><tr><th>Staff</th><th>Customers Served</th></tr></thead><tbody>';
if (count($report['staff_summary']) > 0) {
    foreach ($report['staff_summary'] as $staff) {
        echo '<tr><td class="font-bold">' . staff_customer_export_h($staff['staff']) . '</td><td class="text-right font-bold">' . number_format($staff['customers_served']) . '</td></tr>';
    }
} else {
    echo '<tr><td colspan="2" class="text-center">No staff customer data found.</td></tr>';
}
echo '</tbody></table>';

echo '<h2>DAILY CUSTOMER SUMMARY</h2>';
echo '<table><thead><tr><th>Date</th><th>Walk-in</th><th>Registered</th><th>Total</th></tr></thead><tbody>';
if (count($report['daily_summary']) > 0) {
    foreach ($report['daily_summary'] as $day) {
        echo '<tr>';
        echo '<td class="font-bold">' . staff_customer_export_h(staff_customer_export_date($day['date'])) . '</td>';
        echo '<td class="text-right">' . number_format($day['walkin']) . '</td>';
        echo '<td class="text-right">' . number_format($day['registered']) . '</td>';
        echo '<td class="text-right font-bold">' . number_format($day['total']) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="4" class="text-center">No daily customer data found.</td></tr>';
}
echo '</tbody></table>';

echo '<h2>REPEAT CUSTOMERS</h2>';
echo '<table><thead><tr><th>Customer</th><th>Visits</th><th>Last Visit</th></tr></thead><tbody>';
if (count($report['repeat_customers']) > 0) {
    foreach ($report['repeat_customers'] as $repeat) {
        echo '<tr>';
        echo '<td class="font-bold">' . staff_customer_export_h($repeat['customer']) . '</td>';
        echo '<td class="text-right">' . number_format($repeat['visits']) . '</td>';
        echo '<td>' . staff_customer_export_h(staff_customer_export_date($repeat['last_visit'])) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="3" class="text-center">No repeat customers found for the selected period.</td></tr>';
}
echo '</tbody></table>';

echo '<h2>FOOTER</h2>';
echo '<table><tbody>';
echo '<tr><td class="font-bold">Generated By:</td><td>' . staff_customer_export_h($generated_by) . '</td></tr>';
echo '<tr><td class="font-bold">Generated Date:</td><td>' . date('F d, Y') . '</td></tr>';
echo '<tr><td class="font-bold">Report Type:</td><td>Customer Report</td></tr>';
echo '</tbody></table>';

echo '</body></html>';

try {
    if (function_exists('write_audit_log')) {
        write_audit_log($pdo, 'Export', 'Exported customer report to ' . $format, 'customers', 0, 'report');
    }
} catch (Exception $e) {}
