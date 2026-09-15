<?php
/**
 * FUEL RECONCILIATION REPORT
 * Real-time Fuel Reconciliation report replacing Deliveries Report.
 * Includes Date, Fuel Type, UGT, and Status filters with Excel, CSV, PDF & Print options.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$user_id = (int)($me['id'] ?? 0);
$station_id = user_station_id();

// Cashier / Prepared-by name
$cashier_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($cashier_name === '') $cashier_name = $me['name'] ?? $me['username'] ?? 'N/A';

// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php'); exit;
}

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) die('Error: You are not assigned to a station.');

// Get Station Info
$station_name = 'Station';
$station_location = '';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $station_name = $st['name'];
        $station_location = $st['location'] ?? '';
    }
} catch (Exception $e) {}

// Date & Filter handling
$today = date('Y-m-d');
$date_start = trim($_GET['date_start'] ?? $_GET['date_from'] ?? date('Y-m-01'));
$date_end   = trim($_GET['date_end']   ?? $_GET['date_to']   ?? $today);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = $today;

$filter_fuel_type = trim($_GET['fuel_type'] ?? $_GET['filter_fuel_type'] ?? '');
$filter_ugt       = trim($_GET['ugt']       ?? $_GET['filter_ugt']       ?? '');
$filter_status    = trim($_GET['status']    ?? $_GET['filter_status']    ?? '');

// Helper mapping functions
if (!function_exists('get_exact_ugt_no')) {
    function get_exact_ugt_no(string $rawFuelType): string {
        $s = strtoupper(trim($rawFuelType));
        if (strpos($s, 'DIESEL 2') !== false || strpos($s, 'DIESEL-2') !== false || strpos($s, 'UGT #2') !== false || strpos($s, 'UGT-02') !== false || strpos($s, 'UGT 2') !== false) return 'UGT #2';
        if (strpos($s, 'DIESEL 1') !== false || strpos($s, 'DIESEL-1') !== false || strpos($s, 'UGT #1') !== false || strpos($s, 'UGT-01') !== false || strpos($s, 'UGT 1') !== false) return 'UGT #1';
        if (strpos($s, 'XTRA UNL 2') !== false || strpos($s, 'XTRA 2') !== false || strpos($s, 'UNL 2') !== false || strpos($s, 'UGT #6') !== false || strpos($s, 'UGT-06') !== false || strpos($s, 'UGT 6') !== false) return 'UGT #6';
        if (strpos($s, 'XTRA UNL 1') !== false || strpos($s, 'XTRA 1') !== false || strpos($s, 'UNL 1') !== false || strpos($s, 'UGT #4') !== false || strpos($s, 'UGT-04') !== false || strpos($s, 'UGT 4') !== false) return 'UGT #4';
        if (strpos($s, 'TURBO') !== false || strpos($s, 'UGT #5') !== false || strpos($s, 'UGT-05') !== false || strpos($s, 'UGT 5') !== false) return 'UGT #5';
        if (strpos($s, 'XCS') !== false || strpos($s, 'UGT #3') !== false || strpos($s, 'UGT-03') !== false || strpos($s, 'UGT 3') !== false) return 'UGT #3';
        if (strpos($s, 'KEROSENE') !== false || strpos($s, 'UGT #7') !== false || strpos($s, 'UGT-07') !== false || strpos($s, 'UGT 7') !== false) return 'UGT #7';
        if (strpos($s, 'DIESEL') !== false) {
            if (strpos($s, '2') !== false) return 'UGT #2';
            return 'UGT #1';
        }
        if (strpos($s, 'XTRA') !== false || strpos($s, 'UNL') !== false) {
            if (strpos($s, '2') !== false) return 'UGT #6';
            return 'UGT #4';
        }
        return 'UGT #1';
    }
}

if (!function_exists('staff_report_fuel_display_name')) {
    function staff_report_fuel_display_name($fuel_type): string {
        $name = trim((string)$fuel_type);
        $name = preg_replace('/\s+\d+\s*-\s*\d+$/', '', $name);
        $name = preg_replace('/\s*-\s*\d+$/', '', $name);
        $name = trim($name);
        $normalized = strtoupper(preg_replace('/\s+/', ' ', $name));
        if (strpos($normalized, 'TURBO') !== false && strpos($normalized, 'DIESEL') !== false) return 'Turbo Diesel';
        if (strpos($normalized, 'KEROSENE') !== false) return 'Kerosene';
        if (strpos($normalized, 'XCS') !== false) return 'XCS Plus';
        if (strpos($normalized, 'XTRA') !== false && strpos($normalized, 'UNL') !== false) return 'Xtra UNL';
        if (strpos($normalized, 'DIESEL') !== false) return 'Diesel';
        return $name !== '' ? $name : 'Fuel';
    }
}

// Fetch real fuel transactions/reconciliation data
$reconciliation_rows = [];
try {
    $where = "WHERE ft.station_id = :station_id AND DATE(COALESCE(ft.transaction_date, ft.created_at)) BETWEEN :date_start AND :date_end AND LOWER(COALESCE(ft.status, '')) NOT IN ('voided','rejected','cancelled','canceled')";
    $params = [
        'station_id' => $station_id,
        'date_start' => $date_start,
        'date_end'   => $date_end,
    ];

    if ($filter_fuel_type !== '') {
        $where .= " AND (LOWER(ft.fuel_type) LIKE :ft_filter OR LOWER(COALESCE(fp.pump_number, '')) LIKE :ft_filter)";
        $params['ft_filter'] = '%' . strtolower($filter_fuel_type) . '%';
    }

    if ($filter_status !== '') {
        if (strtolower($filter_status) === 'submitted') {
            $where .= " AND LOWER(COALESCE(ft.status, '')) IN ('verified','approved','completed','submitted')";
        } elseif (strtolower($filter_status) === 'pending') {
            $where .= " AND LOWER(COALESCE(ft.status, '')) NOT IN ('verified','approved','completed','submitted')";
        }
    }

    $sql = "SELECT 
                ft.id,
                ft.fuel_type AS raw_fuel_type,
                ft.pump_id,
                COALESCE(NULLIF(fp.pump_number, ''), ft.fuel_type) AS pump_name,
                COALESCE(ft.previous_reading, 0) AS beginning_reading,
                COALESCE(ft.present_reading, 0) AS ending_reading,
                COALESCE(ft.calibration, 0) AS calibration,
                GREATEST(0, COALESCE(ft.present_reading, 0) - COALESCE(ft.previous_reading, 0) - COALESCE(ft.calibration, 0)) AS net_volume,
                COALESCE(ft.price_per_liter, 0) AS selling_price,
                COALESCE(ft.total_amount, 0) AS fuel_sales,
                CASE 
                  WHEN LOWER(COALESCE(ft.status, '')) IN ('verified','approved','completed','submitted') THEN 'Submitted'
                  ELSE 'Pending'
                END AS status,
                COALESCE(ft.transaction_date, ft.created_at) AS transaction_date
            FROM fuel_transactions ft
            LEFT JOIN fuel_pumps fp ON fp.id = ft.pump_id AND fp.station_id = ft.station_id
            {$where}
            ORDER BY COALESCE(ft.transaction_date, ft.created_at) DESC, ft.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($raw_rows as $r) {
        $rawFuel = $r['raw_fuel_type'] ?? '';
        $ugtNo   = get_exact_ugt_no($rawFuel);
        $cleanFt = staff_report_fuel_display_name($rawFuel);
        $displayFt = !empty($rawFuel) ? $rawFuel : $cleanFt;

        $netVol = (float)$r['net_volume'];
        $price  = (float)$r['selling_price'];
        $sales  = (float)$r['fuel_sales'];
        if ($sales <= 0 && $netVol > 0 && $price > 0) {
            $sales = round($netVol * $price, 2);
        }

        $row = [
            'id'                => $r['id'],
            'ugt_no'            => $ugtNo,
            'fuel_type'         => $displayFt,
            'clean_fuel_type'   => $cleanFt,
            'beginning_reading' => (float)$r['beginning_reading'],
            'ending_reading'    => (float)$r['ending_reading'],
            'calibration'       => (float)$r['calibration'],
            'net_volume'        => $netVol,
            'selling_price'     => $price,
            'fuel_sales'        => $sales,
            'status'            => $r['status'],
            'transaction_date'  => $r['transaction_date'],
        ];

        if ($filter_ugt !== '' && strtolower($ugtNo) !== strtolower($filter_ugt)) {
            continue;
        }

        $reconciliation_rows[] = $row;
    }
} catch (Exception $e) {
    $error_message = "Error fetching fuel reconciliation report: " . $e->getMessage();
}

$total_net_volume = array_sum(array_column($reconciliation_rows, 'net_volume'));
$total_fuel_sales = array_sum(array_column($reconciliation_rows, 'fuel_sales'));

// ============================================================
// EXCEL EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="Fuel_Reconciliation_Report_' . $date_start . '_to_' . $date_end . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Fuel Reconciliation</x:Name><x:WorksheetOptions><x:Print><x:ValidPrinterInfo/></x:Print></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 11px; }';
    echo 'th, td { border: 1px solid #000000; padding: 6px; text-align: left; }';
    echo 'th { background-color: #002F6C; color: #ffffff; font-weight: bold; text-align: center; }';
    echo '.text-right { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo '.font-bold { font-weight: bold; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    echo '<h2>FUEL RECONCILIATION REPORT</h2>';
    echo '<p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>';
    echo '<p><strong>Date Period:</strong> ' . date('F d, Y', strtotime($date_start)) . ' - ' . date('F d, Y', strtotime($date_end)) . '</p>';
    echo '<br/>';
    
    echo '<table>';
    echo '<thead>';
    echo '<tr>';
    echo '<th>UGT No.</th>';
    echo '<th>Fuel Type</th>';
    echo '<th>Beginning Reading</th>';
    echo '<th>Ending Reading</th>';
    echo '<th>Calibration</th>';
    echo '<th>Net Volume (L)</th>';
    echo '<th>Selling Price/L</th>';
    echo '<th>Fuel Sales</th>';
    echo '<th>Status</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($reconciliation_rows) > 0) {
        foreach ($reconciliation_rows as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['ugt_no']) . '</td>';
            echo '<td>' . htmlspecialchars($row['fuel_type']) . '</td>';
            echo '<td class="text-right">' . number_format($row['beginning_reading'], 2) . '</td>';
            echo '<td class="text-right">' . number_format($row['ending_reading'], 2) . '</td>';
            echo '<td class="text-right">' . number_format($row['calibration'], 2) . '</td>';
            echo '<td class="text-right">' . number_format($row['net_volume'], 2) . ' L</td>';
            echo '<td class="text-right">PHP ' . number_format($row['selling_price'], 2) . '</td>';
            echo '<td class="text-right">PHP ' . number_format($row['fuel_sales'], 2) . '</td>';
            echo '<td class="text-center">' . htmlspecialchars($row['status']) . '</td>';
            echo '</tr>';
        }
        echo '<tr style="font-weight:bold; background-color:#e8f0fe;">';
        echo '<td colspan="5" class="text-right">TOTALS</td>';
        echo '<td class="text-right">' . number_format($total_net_volume, 2) . ' L</td>';
        echo '<td></td>';
        echo '<td class="text-right">PHP ' . number_format($total_fuel_sales, 2) . '</td>';
        echo '<td></td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="9" class="text-center">No fuel reconciliation records found for this period.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit;
}

// ============================================================
// CSV EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_slug = date('Ymd', strtotime($date_start));
    if ($date_start !== $date_end) $export_slug .= '_to_' . date('Ymd', strtotime($date_end));
    $filename = 'Fuel_Reconciliation_Report_' . $export_slug . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel compatibility

    fputcsv($out, ['FUEL RECONCILIATION REPORT']);
    fputcsv($out, [$station_name . ($station_location ? ' — ' . $station_location : '')]);
    fputcsv($out, ['Date Period:', date('F d, Y', strtotime($date_start)) . ' - ' . date('F d, Y', strtotime($date_end))]);
    fputcsv($out, []);

    fputcsv($out, ['UGT No.', 'Fuel Type', 'Beginning Reading', 'Ending Reading', 'Calibration', 'Net Volume (L)', 'Selling Price/L', 'Fuel Sales', 'Status']);

    foreach ($reconciliation_rows as $row) {
        fputcsv($out, [
            $row['ugt_no'],
            $row['fuel_type'],
            number_format($row['beginning_reading'], 2),
            number_format($row['ending_reading'], 2),
            number_format($row['calibration'], 2),
            number_format($row['net_volume'], 2) . ' L',
            'PHP ' . number_format($row['selling_price'], 2),
            'PHP ' . number_format($row['fuel_sales'], 2),
            $row['status'],
        ]);
    }
    fputcsv($out, ['', '', '', '', 'TOTAL', number_format($total_net_volume, 2) . ' L', '', 'PHP ' . number_format($total_fuel_sales, 2), '']);

    fclose($out);
    exit;
}

// Page title
$page_title = 'Fuel Reconciliation Report - ' . $station_name;

require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
html, body {
    max-width: 100vw !important;
    overflow-x: hidden !important;
}
.pagination-wrapper,
.client-side-pagination,
.petron-pagination-bar,
.petron-rows-select-wrap,
.rows-per-page {
    display: none !important;
}

/* Main Page Container - Zero Horizontal Scrolling */
.stock-page {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    padding: 16px !important;
    overflow-x: hidden !important;
}

/* Card Container */
.frr-card-container {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 12px !important;
    padding: 20px !important;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03) !important;
    overflow-x: hidden !important;
}

/* Top Controls Bar */
.frr-controls-bar {
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 12px !important;
    padding: 14px 18px !important;
    margin-bottom: 16px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    flex-wrap: wrap !important;
    box-shadow: 0 2px 5px rgba(0,0,0,0.03) !important;
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: 100% !important;
}
.frr-filters-group {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
    flex: 1 1 auto !important;
}
.frr-filter-item {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.frr-filter-label {
    font-weight: 800 !important;
    color: #002F6C !important;
    font-size: 13px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    white-space: nowrap !important;
}
.frr-filter-input, .frr-filter-select {
    height: 38px !important;
    padding: 6px 10px !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 7px !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    background: #ffffff !important;
    outline: none !important;
    box-sizing: border-box !important;
}
.frr-filter-input:focus, .frr-filter-select:focus {
    border-color: #002F6C !important;
    box-shadow: 0 0 0 3px rgba(0,47,108,0.12) !important;
}
.frr-btn-apply {
    height: 38px !important;
    padding: 0 18px !important;
    background: #002F6C !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    border: none !important;
    border-radius: 7px !important;
    font-size: 13.5px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: background 0.15s !important;
}
.frr-btn-apply:hover {
    background: #001f4d !important;
}

/* Export Buttons */
.rpt-export-group {
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    margin-left: auto !important;
    white-space: nowrap !important;
}
.rpt-export-btn {
    padding: 7px 14px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    border-radius: 5px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    transition: all 0.18s !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
    white-space: nowrap !important;
}
.rpt-btn-print  { color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; }
.rpt-btn-csv   { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdf4 !important; }

/* Status Badges */
.status-badge-submitted {
    display: inline-block !important;
    padding: 5px 12px !important;
    border-radius: 14px !important;
    font-size: 13px !important;
    font-weight: 800 !important;
    background: #dcfce7 !important;
    color: #15803d !important;
    border: 1.5px solid #86efac !important;
}
.status-badge-pending {
    display: inline-block !important;
    padding: 5px 12px !important;
    border-radius: 14px !important;
    font-size: 13px !important;
    font-weight: 800 !important;
    background: #fef9c3 !important;
    color: #854d0e !important;
    border: 1.5px solid #fde047 !important;
}

/* Table Wrapper & Proportional Layout */
.frr-table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
    margin-top: 14px !important;
    border-radius: 8px !important;
    border: 1.5px solid #cbd5e1 !important;
}
table.recon-table {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
    border-collapse: collapse !important;
    margin: 0 !important;
}
table.recon-table th {
    background: #002F6C !important;
    color: #ffffff !important;
    font-size: 13px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.2px !important;
    padding: 11px 6px !important;
    border: 1px solid #001f4d !important;
    white-space: normal !important;
    word-break: normal !important;
    overflow-wrap: normal !important;
    hyphens: none !important;
    vertical-align: middle !important;
}
table.recon-table td {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    vertical-align: middle !important;
    padding: 10px 8px !important;
    border: 1px solid #e2e8f0 !important;
    color: #0f172a !important;
    font-size: 14px !important;
    background: #ffffff;
}
.recon-th-calib,
th.recon-th-calib {
    white-space: nowrap !important;
}
table.recon-table tr:hover td {
    background: #f8fafc !important;
}
.recon-val-ugt {
    font-weight: 800 !important;
    color: #002F6C !important;
    font-size: 14.5px !important;
}
.recon-val-fuel {
    font-weight: 700 !important;
    color: #1e293b !important;
    font-size: 14px !important;
}
.recon-val-num {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
}
.recon-val-calib {
    font-size: 14.5px !important;
    font-weight: 700 !important;
    color: #b45309 !important;
    white-space: nowrap !important;
}
.recon-val-vol {
    font-size: 14.5px !important;
    font-weight: 800 !important;
    color: #15803d !important;
}
.recon-val-price {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #334155 !important;
}
.recon-val-sales {
    font-size: 15px !important;
    font-weight: 800 !important;
    color: #002F6C !important;
}
.recon-total-row td {
    background: #eff6ff !important;
    border: 1.5px solid #002F6C !important;
    font-weight: 900 !important;
}

/* Hide signature on screen */
.print-only-sig { display: none !important; }

@media print {
    @page { size: A4 landscape; margin: 10mm 12mm; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-shadow: none !important; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; overflow: visible !important; height: auto !important; font-size: 10px !important; }
    body > *:not(.sfss-print-only) { display: none !important; }
    .stock-page .controls, .frr-controls-bar, nav, header, footer, aside, .sidebar, .main-sidebar, .main-header, .navbar, .topbar,
    #toggleScrollBtn, .toggle-scroll-btn, .toast, .toast-container { display: none !important; }
    .sfss-print-only { display: block !important; position: static !important; width: 100% !important; margin: 0 !important; padding: 0 !important; background: #fff !important; font-size: 10px !important; color: #333 !important; }
    .sfss-print-only *, .sfss-print-only *::before, .sfss-print-only *::after { box-shadow: none !important; text-shadow: none !important; }
    .sfss-print-only img, .sfss-print-only canvas, .sfss-print-only i, .sfss-print-only svg,
    .sfss-print-only .fas, .sfss-print-only .far, .sfss-print-only .fab, .sfss-print-only .fa,
    .sfss-print-only [class*="fa-"], .sfss-print-only [class*="watermark"] { display: none !important; width: 0 !important; height: 0 !important; font-size: 0 !important; margin: 0 !important; padding: 0 !important; }
    .sfss-print-only .header { text-align: center !important; border-bottom: none !important; padding: 0 !important; margin: 0 0 6px 0 !important; }
    .sfss-print-only .header h1 { display: block !important; font-size: 13px !important; font-weight: 700 !important; color: #000 !important; margin: 0 0 2px 0 !important; }
    .sfss-print-only .table-container, .sfss-print-only .frr-table-wrap { overflow: visible !important; width: 100% !important; margin: 0 0 5px 0 !important; }
    .sfss-print-only table { width: 100% !important; border-collapse: collapse !important; font-size: 9px !important; margin: 0 !important; }
    .sfss-print-only thead { display: table-header-group !important; }
    .sfss-print-only tbody { display: table-row-group !important; }
    .sfss-print-only tr { display: table-row !important; page-break-inside: avoid !important; }
    .sfss-print-only th { display: table-cell !important; font-size: 9px !important; padding: 4px 6px !important; border: 1px solid #000 !important; background: #002F6C !important; color: #fff !important; font-weight: 600 !important; text-align: center !important; }
    .sfss-print-only td { display: table-cell !important; font-size: 9px !important; padding: 3px 6px !important; border-bottom: 1px solid #e2e8f0 !important; vertical-align: top !important; color: #0f172a !important; }
    .sfss-print-only .frr-card-container, .sfss-print-only .container { display: block !important; margin: 0 !important; padding: 0 !important; max-width: 100% !important; height: auto !important; border: none !important; box-shadow: none !important; }
    .sfss-print-only, .sfss-print-only * { min-height: 0 !important; height: auto !important; }
    .status-badge-submitted, .status-badge-pending { border-radius: 0 !important; padding: 1px 4px !important; font-size: 8px !important; }
    .sfss-print-only .print-only-sig { display: table !important; width: 100% !important; border-collapse: collapse !important; }
    .sfss-print-only .print-only-sig td { display: table-cell !important; border: none !important; }
}

/* ── Petron Downward Custom Dropdowns ── */
.petron-dropdown-source { display: none !important; }
.petron-dropdown-wrap {
    position: relative !important;
    display: inline-block !important;
    vertical-align: middle !important;
    box-sizing: border-box !important;
}
.petron-dropdown-wrap.is-open { z-index: 10050 !important; }
.petron-dropdown-trigger {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    width: 100% !important;
    height: 38px !important;
    padding: 6px 12px !important;
    background: #fff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 7px !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    gap: 8px !important;
    white-space: nowrap !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-trigger {
    border-color: #1967d2 !important;
    box-shadow: 0 0 0 2px rgba(25,103,210,.2) !important;
}
.petron-dropdown-label {
    flex: 1 !important;
    text-align: left !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.petron-dropdown-arrow {
    font-size: 10px !important;
    color: #64748b !important;
    transition: transform .2s !important;
    flex-shrink: 0 !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-arrow {
    transform: rotate(180deg) !important;
}
.petron-dropdown-menu {
    position: absolute !important;
    top: calc(100% + 2px) !important;
    bottom: auto !important;
    left: 0 !important;
    z-index: 10051 !important;
    min-width: 100% !important;
    background: #fff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 7px !important;
    box-shadow: 0 8px 24px rgba(0,0,0,.15) !important;
    max-height: 240px !important;
    overflow-y: auto !important;
    display: none !important;
    padding: 4px 0 !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-menu {
    display: block !important;
}
.petron-dropdown-item {
    padding: 8px 14px !important;
    font-size: 13px !important;
    color: #1e293b !important;
    background: #fff !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    transition: background .12s, color .12s !important;
}
.petron-dropdown-item:hover,
.petron-dropdown-item.is-selected {
    background: #1967d2 !important;
    color: #fff !important;
}
</style>

<div class="stock-page">

    <!-- TOP CONTROLS & FILTERS -->
    <div class="frr-controls-bar">
        
        <div class="frr-filters-group">
            <div class="frr-filter-item">
                <label class="frr-filter-label">From</label>
                <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>" class="frr-filter-input">
            </div>

            <div class="frr-filter-item">
                <label class="frr-filter-label">To</label>
                <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>" class="frr-filter-input">
            </div>

            <div class="frr-filter-item">
                <label class="frr-filter-label">Fuel Type</label>
                <select id="filter_fuel_type" class="frr-filter-select">
                    <option value="">All Fuel Types</option>
                    <option value="Diesel" <?= strtolower($filter_fuel_type) === 'diesel' ? 'selected' : '' ?>>Diesel</option>
                    <option value="Turbo Diesel" <?= strtolower($filter_fuel_type) === 'turbo diesel' ? 'selected' : '' ?>>Turbo Diesel</option>
                    <option value="XCS Plus" <?= strtolower($filter_fuel_type) === 'xcs plus' ? 'selected' : '' ?>>XCS Plus</option>
                    <option value="Xtra UNL" <?= strtolower($filter_fuel_type) === 'xtra unl' ? 'selected' : '' ?>>Xtra UNL</option>
                    <option value="Kerosene" <?= strtolower($filter_fuel_type) === 'kerosene' ? 'selected' : '' ?>>Kerosene</option>
                </select>
            </div>

            <div class="frr-filter-item">
                <label class="frr-filter-label">UGT</label>
                <select id="filter_ugt" class="frr-filter-select">
                    <option value="">All UGTs</option>
                    <option value="UGT #1" <?= strtolower($filter_ugt) === 'ugt #1' ? 'selected' : '' ?>>UGT #1</option>
                    <option value="UGT #2" <?= strtolower($filter_ugt) === 'ugt #2' ? 'selected' : '' ?>>UGT #2</option>
                    <option value="UGT #3" <?= strtolower($filter_ugt) === 'ugt #3' ? 'selected' : '' ?>>UGT #3</option>
                    <option value="UGT #4" <?= strtolower($filter_ugt) === 'ugt #4' ? 'selected' : '' ?>>UGT #4</option>
                    <option value="UGT #5" <?= strtolower($filter_ugt) === 'ugt #5' ? 'selected' : '' ?>>UGT #5</option>
                    <option value="UGT #6" <?= strtolower($filter_ugt) === 'ugt #6' ? 'selected' : '' ?>>UGT #6</option>
                    <option value="UGT #7" <?= strtolower($filter_ugt) === 'ugt #7' ? 'selected' : '' ?>>UGT #7</option>
                </select>
            </div>

            <div class="frr-filter-item">
                <label class="frr-filter-label">Status</label>
                <select id="filter_status" class="frr-filter-select">
                    <option value="">All Statuses</option>
                    <option value="Pending" <?= strtolower($filter_status) === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Submitted" <?= strtolower($filter_status) === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                </select>
            </div>

            <button type="button" onclick="applyFilters()" class="frr-btn-apply">
                <i class="fas fa-filter"></i> Apply
            </button>
        </div>

        <!-- EXPORT & PRINT BUTTONS -->
        <div class="rpt-export-group">
            <button type="button" onclick="_sfss_doNativePrint()" class="rpt-export-btn rpt-btn-print" title="Print report">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" onclick="exportPrintableAreaToPDF('.print-area', 'Fuel Reconciliation Report', 'fuel_reconciliation_report_<?= date('Ymd', strtotime($date_start)) ?>_<?= date('Ymd', strtotime($date_end)) ?>', this)" class="rpt-export-btn rpt-btn-pdf" title="Export PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <a href="?date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>&fuel_type=<?= urlencode($filter_fuel_type) ?>&ugt=<?= urlencode($filter_ugt) ?>&status=<?= urlencode($filter_status) ?>&export=excel" 
               class="rpt-export-btn rpt-btn-excel" title="Export to Excel">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <button type="button" onclick="sfssExportCSV()" class="rpt-export-btn rpt-btn-csv" title="Export to CSV">
                <i class="fas fa-file-csv"></i> CSV
            </button>
        </div>
    </div>

    <!-- PRINTABLE REPORT DOCUMENT AREA -->
    <div class="print-area">
        <div class="frr-card-container">
            
            <!-- HEADER -->
            <div class="header" style="text-align:center; margin-bottom:20px; border-bottom:2.5px solid #002F6C; padding-bottom:14px;">
                <h1 style="font-size:24px; font-weight:900; color:#002F6C; margin:0 0 6px 0; letter-spacing:0.5px; font-family:'Segoe UI', sans-serif;">FUEL RECONCILIATION REPORT</h1>
                <div style="font-size:15px; font-weight:800; color:#1e293b; margin-bottom:5px;">
                    <?= htmlspecialchars($station_name) ?><?= $station_location ? ' — ' . htmlspecialchars($station_location) : '' ?>
                </div>
                <div style="font-size:14px; color:#475569; font-weight:700;">
                    <span><strong>Date:</strong> <?= date('F d, Y', strtotime($date_start)) ?> – <?= date('F d, Y', strtotime($date_end)) ?></span>
                </div>
            </div>

            <!-- TABLE -->
            <div class="frr-table-wrap mb-4">
                <table id="reconTable" class="recon-table report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 7.5%;">  <!-- UGT No. -->
                        <col style="width: 14%;">   <!-- Fuel Type -->
                        <col style="width: 11%;">   <!-- Beginning Reading -->
                        <col style="width: 11%;">   <!-- Ending Reading -->
                        <col style="width: 12.5%;"> <!-- Calibration -->
                        <col style="width: 11%;">   <!-- Net Volume -->
                        <col style="width: 11%;">   <!-- Selling Price -->
                        <col style="width: 13%;">   <!-- Fuel Sales -->
                        <col style="width: 9%;">    <!-- Status -->
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="text-align:left;">UGT No.</th>
                            <th style="text-align:left;">Fuel Type</th>
                            <th style="text-align:right;">Beginning Reading</th>
                            <th style="text-align:right;">Ending Reading</th>
                            <th class="recon-th-calib" style="text-align:right; white-space:nowrap;">Calibration</th>
                            <th style="text-align:right;">Net Volume</th>
                            <th style="text-align:right;">Selling Price</th>
                            <th style="text-align:right;">Fuel Sales</th>
                            <th style="text-align:center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($reconciliation_rows) > 0): ?>
                            <?php foreach ($reconciliation_rows as $row): ?>
                                <tr>
                                    <td class="recon-val-ugt"><?= htmlspecialchars($row['ugt_no']) ?></td>
                                    <td class="recon-val-fuel"><?= htmlspecialchars($row['fuel_type']) ?></td>
                                    <td class="recon-val-num" style="text-align:right;"><?= number_format($row['beginning_reading'], 2) ?></td>
                                    <td class="recon-val-num" style="text-align:right;"><?= number_format($row['ending_reading'], 2) ?></td>
                                    <td class="recon-val-calib" style="text-align:right;"><?= number_format($row['calibration'], 2) ?></td>
                                    <td class="recon-val-vol" style="text-align:right;"><?= number_format($row['net_volume'], 2) ?> L</td>
                                    <td class="recon-val-price" style="text-align:right;">₱<?= number_format($row['selling_price'], 2) ?></td>
                                    <td class="recon-val-sales" style="text-align:right;">₱<?= number_format($row['fuel_sales'], 2) ?></td>
                                    <td style="text-align:center;">
                                        <span class="<?= strtolower($row['status']) === 'submitted' ? 'status-badge-submitted' : 'status-badge-pending' ?>">
                                            <?= htmlspecialchars($row['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <!-- FOOTER TOTALS -->
                            <tr class="recon-total-row">
                                <td colspan="5" style="text-align:right; text-transform:uppercase; font-size:15px; color:#002F6C;">TOTALS</td>
                                <td style="text-align:right; color:#15803d; font-size:15.5px;"><?= number_format($total_net_volume, 2) ?> L</td>
                                <td style="text-align:center; font-size:14px; color:#64748b;">-</td>
                                <td style="text-align:right; color:#002F6C; font-size:16px;">₱<?= number_format($total_fuel_sales, 2) ?></td>
                                <td></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align:center; padding:32px 16px; color:#64748b; font-size:15px; font-weight:700; font-style:italic;">
                                    No fuel reconciliation records found for this period and selected filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PREPARED BY SIGNATURE (Print Only) -->
            <table class="print-only-sig" style="width:100%; margin-top:30px; page-break-inside:avoid; border:none; border-collapse:collapse;">
                <tr>
                    <td style="border:none;"></td>
                    <td style="border:none; width:220px; text-align:center;">
                        <div style="font-size:11px; font-weight:800; color:#002F6C; margin-bottom:28px;">PREPARED BY:</div>
                        <div style="border-top:1.5px solid #002F6C; padding-top:4px; font-weight:800; font-size:13px; color:#0f172a;">
                            <?= htmlspecialchars($cashier_name) ?>
                        </div>
                        <div style="font-size:11px; color:#64748b; font-weight:600; margin-top:2px;">Staff</div>
                    </td>
                </tr>
            </table>

        </div>
    </div>

</div>

<script>
function applyFilters() {
    const ds = document.getElementById('date_start').value;
    const de = document.getElementById('date_end').value;
    const ft = document.getElementById('filter_fuel_type').value;
    const ugt = document.getElementById('filter_ugt').value;
    const st = document.getElementById('filter_status').value;

    if (!ds || !de) {
        alert('Please select both From and To dates.');
        return;
    }
    if (de < ds) {
        alert('To Date cannot be earlier than From Date.');
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set('date_start', ds);
    url.searchParams.set('date_end', de);
    if (ft) url.searchParams.set('fuel_type', ft); else url.searchParams.delete('fuel_type');
    if (ugt) url.searchParams.set('ugt', ugt); else url.searchParams.delete('ugt');
    if (st) url.searchParams.set('status', st); else url.searchParams.delete('status');

    window.location.href = url.toString();
}

// CSV — server-side for proper UTF-8 encoding
function sfssExportCSV() {
    var ds  = document.getElementById('date_start').value;
    var de  = document.getElementById('date_end').value;
    var ft  = document.getElementById('filter_fuel_type').value;
    var ugt = document.getElementById('filter_ugt').value;
    var st  = document.getElementById('filter_status').value;
    var url = window.location.pathname + '?export=csv'
        + '&date_start=' + encodeURIComponent(ds)
        + '&date_end='   + encodeURIComponent(de);
    if (ft)  url += '&fuel_type=' + encodeURIComponent(ft);
    if (ugt) url += '&ugt='       + encodeURIComponent(ugt);
    if (st)  url += '&status='    + encodeURIComponent(st);
    window.location.href = url;
}

// PDF — opens browser print dialog with spinner feedback
function exportPrintableAreaToPDF(selector, title, filename, btn) {
    if (btn) {
        var origHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening PDF dialog...';
        btn.disabled  = true;
        _sfss_doNativePrint(function() {
            btn.innerHTML = origHTML;
            btn.disabled  = false;
        });
    } else {
        _sfss_doNativePrint();
    }
}

function sfssPrintReportArea() {
    _sfss_doNativePrint();
}

// Core print helper — extracts .print-area, injects into sfss-print-only, triggers window.print()
function _sfss_doNativePrint(afterPrint) {
    var old = document.querySelector('.sfss-print-only');
    if (old) old.remove();

    var area = document.querySelector('.print-area');
    if (!area) { window.print(); return; }

    var origTitle   = document.title;
    document.title  = 'Fuel Reconciliation Report';

    var printDiv = document.createElement('div');
    printDiv.className        = 'sfss-print-only';
    printDiv.innerHTML        = area.innerHTML;
    printDiv.style.display    = 'block';
    printDiv.style.visibility = 'visible';
    document.body.appendChild(printDiv);

    var scrollBtn = document.getElementById('toggleScrollBtn');
    if (scrollBtn) scrollBtn.style.setProperty('display', 'none', 'important');

    setTimeout(function() {
        window.print();
        var cleanup = function() {
            var p = document.querySelector('.sfss-print-only');
            if (p) p.remove();
            document.title = origTitle;
            if (scrollBtn) scrollBtn.style.setProperty('display', 'flex', 'important');
            window.removeEventListener('afterprint', cleanup);
            if (typeof afterPrint === 'function') afterPrint();
        };
        window.addEventListener('afterprint', cleanup);
        setTimeout(cleanup, 30000); // fallback cleanup
    }, 150);
}

// ── Petron Downward Custom Dropdowns for Fuel Reconciliation Report ──
(function() {
    function setupFrrPetronDD() {
        var selectors = [
            '#filter_fuel_type',
            '#filter_ugt',
            '#filter_status'
        ];
        selectors.forEach(function(selId) {
            var select = document.querySelector(selId);
            if (!select || select.dataset.petronDownReady === '1') return;
            select.dataset.petronDownReady = '1';

            var wrap = document.createElement('div');
            wrap.className = 'petron-dropdown-wrap';
            if (select.id === 'filter_fuel_type') wrap.style.minWidth = '150px';
            else if (select.id === 'filter_ugt') wrap.style.minWidth = '120px';
            else if (select.id === 'filter_status') wrap.style.minWidth = '130px';
            else wrap.style.minWidth = Math.max(select.offsetWidth || 0, 130) + 'px';

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'petron-dropdown-trigger';

            var label = document.createElement('span');
            label.className = 'petron-dropdown-label';

            var arrow = document.createElement('i');
            arrow.className = 'fas fa-chevron-down petron-dropdown-arrow';

            trigger.appendChild(label);
            trigger.appendChild(arrow);

            var menu = document.createElement('div');
            menu.className = 'petron-dropdown-menu';

            Array.from(select.options).forEach(function(option) {
                if (option.hidden) return;
                var item = document.createElement('div');
                item.className = 'petron-dropdown-item';
                item.dataset.value = option.value;
                item.textContent = option.textContent;
                item.addEventListener('click', function(e) {
                    e.stopPropagation();
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    if (typeof select.onchange === 'function') {
                        select.onchange();
                    }
                    syncLabel();
                    wrap.classList.remove('is-open');
                });
                menu.appendChild(item);
            });

            function syncLabel() {
                var sel = select.options[select.selectedIndex];
                label.textContent = sel ? sel.textContent.trim() : '';
                Array.from(menu.querySelectorAll('.petron-dropdown-item')).forEach(function(i) {
                    i.classList.toggle('is-selected', i.dataset.value === select.value);
                });
            }

            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                var willOpen = !wrap.classList.contains('is-open');
                document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) { w.classList.remove('is-open'); });
                if (willOpen) {
                    var rect = wrap.getBoundingClientRect();
                    menu.style.left = (rect.right + 10 > window.innerWidth) ? 'auto' : '0';
                    menu.style.right = (rect.right + 10 > window.innerWidth) ? '0' : 'auto';
                    wrap.classList.add('is-open');
                    var s = menu.querySelector('.petron-dropdown-item.is-selected');
                    if (s) s.scrollIntoView({ block: 'nearest' });
                }
            });

            select.addEventListener('change', syncLabel);
            select.classList.add('petron-dropdown-source');
            select.style.display = 'none';
            select.hidden = true;
            select.parentNode.insertBefore(wrap, select.nextSibling);
            wrap.appendChild(trigger);
            wrap.appendChild(menu);
            syncLabel();
        });

        if (!window.__petronDownCloseBoundFrr) {
            window.__petronDownCloseBoundFrr = true;
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.petron-dropdown-wrap')) {
                    document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) { w.classList.remove('is-open'); });
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) { w.classList.remove('is-open'); });
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupFrrPetronDD);
    } else {
        setupFrrPetronDD();
    }
    window.addEventListener('load', setupFrrPetronDD);
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
