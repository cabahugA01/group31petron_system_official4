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
.pagination-wrapper,
.client-side-pagination,
.petron-pagination-bar,
.petron-rows-select-wrap,
.rows-per-page {
    display: none !important;
}

/* Export Group — matches Sales Reports design */
.rpt-export-group {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    margin-left: auto !important;
    white-space: nowrap !important;
}
.rpt-export-btn {
    padding: 7px 13px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    border-radius: 4px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    background: #ffffff !important;
    border: 1px solid !important;
    transition: all 0.18s !important;
    text-decoration: none !important;
}
.rpt-btn-print  { color: #475569 !important; border-color: transparent !important; background: transparent !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; }
.rpt-btn-csv   { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdf4 !important; }

.status-badge-submitted {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    background: #dcfce7;
    color: #15803d;
}
.status-badge-pending {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    background: #fef9c3;
    color: #a16207;
}

@media print {
    @page { size: A4 landscape; margin: 10mm 12mm; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-shadow: none !important; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; overflow: visible !important; height: auto !important; font-size: 10px !important; }
    body > *:not(.sfss-print-only) { display: none !important; }
    .stock-page .controls, nav, header, footer, aside, .sidebar, .main-sidebar, .main-header, .navbar, .topbar,
    #toggleScrollBtn, .toggle-scroll-btn, .toast, .toast-container { display: none !important; }
    .sfss-print-only { display: block !important; position: static !important; width: 100% !important; margin: 0 !important; padding: 0 !important; background: #fff !important; font-size: 10px !important; color: #333 !important; }
    .sfss-print-only *, .sfss-print-only *::before, .sfss-print-only *::after { box-shadow: none !important; text-shadow: none !important; }
    .sfss-print-only img, .sfss-print-only canvas, .sfss-print-only i, .sfss-print-only svg,
    .sfss-print-only .fas, .sfss-print-only .far, .sfss-print-only .fab, .sfss-print-only .fa,
    .sfss-print-only [class*="fa-"], .sfss-print-only [class*="watermark"] { display: none !important; width: 0 !important; height: 0 !important; font-size: 0 !important; margin: 0 !important; padding: 0 !important; }
    .sfss-print-only .header { text-align: center !important; border-bottom: none !important; padding: 0 !important; margin: 0 0 6px 0 !important; }
    .sfss-print-only .header h1 { display: block !important; font-size: 13px !important; font-weight: 700 !important; color: #000 !important; margin: 0 0 2px 0 !important; }
    .sfss-print-only .table-container { overflow: visible !important; width: 100% !important; margin: 0 0 5px 0 !important; }
    .sfss-print-only table { width: 100% !important; border-collapse: collapse !important; font-size: 9px !important; margin: 0 !important; }
    .sfss-print-only thead { display: table-header-group !important; }
    .sfss-print-only tbody { display: table-row-group !important; }
    .sfss-print-only tr { display: table-row !important; page-break-inside: avoid !important; }
    .sfss-print-only th { display: table-cell !important; font-size: 9px !important; padding: 4px 6px !important; border: 1px solid #000 !important; background: #002F6C !important; color: #fff !important; font-weight: 600 !important; text-align: center !important; }
    .sfss-print-only td { display: table-cell !important; font-size: 9px !important; padding: 3px 6px !important; border-bottom: 1px solid #e2e8f0 !important; vertical-align: top !important; color: #0f172a !important; }
    .sfss-print-only .container { display: block !important; margin: 0 !important; padding: 0 !important; max-width: 100% !important; height: auto !important; }
    .sfss-print-only, .sfss-print-only * { min-height: 0 !important; height: auto !important; }
    .sfss-print-only table { display: table !important; height: auto !important; }
    .sfss-print-only table tr { display: table-row !important; }
    .sfss-print-only table td { display: table-cell !important; }
    .status-badge-submitted, .status-badge-pending { border-radius: 0 !important; padding: 1px 4px !important; font-size: 8px !important; }
    .sfss-print-only .print-only-sig { display: table !important; width: 100% !important; border-collapse: collapse !important; }
    .sfss-print-only .print-only-sig td { display: table-cell !important; border: none !important; }
}
</style>

<style>
    /* Hide signature on screen */
    .print-only-sig { display: none !important; }
</style>

<div class="stock-page" style="padding: 20px;">

    <!-- TOP CONTROLS & FILTERS -->
    <div class="controls" style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
        
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-weight:700; color:#002F6C; font-size:12px; text-transform:uppercase;">From</label>
                <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>"
                       style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff;">
            </div>

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-weight:700; color:#002F6C; font-size:12px; text-transform:uppercase;">To</label>
                <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>"
                       style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff;">
            </div>

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-weight:700; color:#002F6C; font-size:12px; text-transform:uppercase;">Fuel Type</label>
                <select id="filter_fuel_type" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff;">
                    <option value="">All Fuel Types</option>
                    <option value="Diesel" <?= strtolower($filter_fuel_type) === 'diesel' ? 'selected' : '' ?>>Diesel</option>
                    <option value="Turbo Diesel" <?= strtolower($filter_fuel_type) === 'turbo diesel' ? 'selected' : '' ?>>Turbo Diesel</option>
                    <option value="XCS Plus" <?= strtolower($filter_fuel_type) === 'xcs plus' ? 'selected' : '' ?>>XCS Plus</option>
                    <option value="Xtra UNL" <?= strtolower($filter_fuel_type) === 'xtra unl' ? 'selected' : '' ?>>Xtra UNL</option>
                    <option value="Kerosene" <?= strtolower($filter_fuel_type) === 'kerosene' ? 'selected' : '' ?>>Kerosene</option>
                </select>
            </div>

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-weight:700; color:#002F6C; font-size:12px; text-transform:uppercase;">UGT</label>
                <select id="filter_ugt" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff;">
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

            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-weight:700; color:#002F6C; font-size:12px; text-transform:uppercase;">Status</label>
                <select id="filter_status" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff;">
                    <option value="">All Statuses</option>
                    <option value="Pending" <?= strtolower($filter_status) === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Submitted" <?= strtolower($filter_status) === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                </select>
            </div>

            <button type="button" onclick="applyFilters()" style="padding:6px 16px; background:#002F6C; color:#fff; font-weight:700; border:none; border-radius:6px; font-size:13px; cursor:pointer;">
                <i class="fas fa-filter"></i> Apply
            </button>
        </div>

        <!-- EXPORT & PRINT BUTTONS — right-aligned, matching Sales Reports design -->
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
        <div class="container" style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:24px; box-shadow:0 1px 4px rgba(0,0,0,0.02);">
            
            <!-- HEADER -->
            <div class="header" style="text-align:center; margin-bottom:18px; border-bottom:2px solid #002F6C; padding-bottom:12px;">
                <h1 style="font-size:20px; font-weight:800; color:#002F6C; margin:0 0 4px 0; letter-spacing:0.5px; font-family:'Segoe UI', sans-serif;">FUEL RECONCILIATION REPORT</h1>
                <div style="font-size:13px; font-weight:700; color:#1e293b; margin-bottom:4px;">
                    <?= htmlspecialchars($station_name) ?><?= $station_location ? ' — ' . htmlspecialchars($station_location) : '' ?>
                </div>
                <div style="font-size:12px; color:#475569; font-weight:600;">
                    <span><strong>Date:</strong> <?= date('F d, Y', strtotime($date_start)) ?> – <?= date('F d, Y', strtotime($date_end)) ?></span>
                </div>
            </div>

            <!-- TABLE -->
            <div class="table-container mb-4" style="overflow-x:auto;">
                <table id="reconTable" style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#002F6C; color:#fff;">
                            <th style="padding:10px; border:1px solid #001a36; text-align:left;">UGT No.</th>
                            <th style="padding:10px; border:1px solid #001a36; text-align:left;">Fuel Type</th>
                            <th style="padding:10px; border:1px solid #001a36; text-align:right;">Beginning Reading</th>
                            <th style="padding:10px; border:1px solid #001a36; text-align:right;">Ending Reading</th>
                            <th style="padding:10px; border:1px solid #001a36; text-align:right;">Calibration</th>
                            <th style="padding:10px; border:1px solid #001a36; text-align:right;">Net Volume</th>
                            <th style="padding:10px; border:1px solid #001a36; text-align:right;">Selling Price</th>
                            <th style="padding:10px; border:1px solid #001a36; text-align:right;">Fuel Sales</th>
                            <th style="padding:10px; border:1px solid #001a36; text-align:center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($reconciliation_rows) > 0): ?>
                            <?php foreach ($reconciliation_rows as $row): ?>
                                <tr>
                                    <td style="padding:8px 10px; border:1px solid #e2e8f0; font-weight:700; color:#002F6C;"><?= htmlspecialchars($row['ugt_no']) ?></td>
                                    <td style="padding:8px 10px; border:1px solid #e2e8f0;"><?= htmlspecialchars($row['fuel_type']) ?></td>
                                    <td style="padding:8px 10px; border:1px solid #e2e8f0; text-align:right;"><?= number_format($row['beginning_reading'], 2) ?></td>
                                    <td style="padding:8px 10px; border:1px solid #e2e8f0; text-align:right;"><?= number_format($row['ending_reading'], 2) ?></td>
                                    <td style="padding:8px 10px; border:1px solid #e2e8f0; text-align:right; color:#d97706;"><?= number_format($row['calibration'], 2) ?></td>
                                    <td style="padding:8px 10px; border:1px solid #e2e8f0; text-align:right; font-weight:700; color:#15803d;"><?= number_format($row['net_volume'], 2) ?> L</td>
                                    <td style="padding:8px 10px; border:1px solid #e2e8f0; text-align:right;">₱<?= number_format($row['selling_price'], 2) ?></td>
                                    <td style="padding:8px 10px; border:1px solid #e2e8f0; text-align:right; font-weight:800; color:#002F6C;">₱<?= number_format($row['fuel_sales'], 2) ?></td>
                                    <td style="padding:8px 10px; border:1px solid #e2e8f0; text-align:center;">
                                        <span class="<?= strtolower($row['status']) === 'submitted' ? 'status-badge-submitted' : 'status-badge-pending' ?>">
                                            <?= htmlspecialchars($row['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <!-- FOOTER TOTALS -->
                            <tr style="font-weight:800; background:#e8f0fe; border-top:2px solid #002F6C;">
                                <td colspan="5" style="padding:10px; border:1px solid #002F6C; text-align:right; text-transform:uppercase;">TOTALS</td>
                                <td style="padding:10px; border:1px solid #002F6C; text-align:right; color:#15803d; font-size:13px;"><?= number_format($total_net_volume, 2) ?> L</td>
                                <td style="padding:10px; border:1px solid #002F6C; text-align:center;">-</td>
                                <td style="padding:10px; border:1px solid #002F6C; text-align:right; color:#002F6C; font-size:14px;">₱<?= number_format($total_fuel_sales, 2) ?></td>
                                <td style="padding:10px; border:1px solid #002F6C;"></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align:center; padding:30px; color:#6b7280; font-style:italic;">
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
                        <div style="font-size:10px; font-weight:700; color:#333; margin-bottom:25px;">PREPARED BY:</div>
                        <div style="border-top:1px solid #000; padding-top:4px; font-weight:700; font-size:11px; color:#000;">
                            <?= htmlspecialchars($cashier_name) ?>
                        </div>
                        <div style="font-size:9.5px; color:#555; margin-top:2px;">Staff</div>
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
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
