<?php
/**
 * STAFF FUEL REPORTS
 *
 * Reports visible to Staff in Fuel Management:
 *   1. Meter Reading Report  — their own encoded pump readings (all statuses)
 *   2. Fuel Deliveries Report — their own recorded delivery receipts
 *
 * NOT shown here (Manager/Admin only):
 *   - Volume Sales Summary
 *   - Volume & Amount Summary
 *   - Variance Reports
 *   - Audit Trail
 */
$page_id = 'fuel_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');
$station_name = 'Station';
$station_location = '';

// Only staff roles can access this page
if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard.php');
    exit;
}

try {
    $st = $pdo->prepare("SELECT name, location FROM stations WHERE id = ? LIMIT 1");
    $st->execute([$station_id]);
    $station = $st->fetch(PDO::FETCH_ASSOC);
    if ($station) {
        $station_name = $station['name'] ?: $station_name;
        $station_location = $station['location'] ?? '';
    }
} catch (Exception $e) { /* non-fatal */ }

// Active view: meter_readings | deliveries  (default: meter_readings)
$view = $_GET['view'] ?? 'meter_readings';
if (!in_array($view, ['meter_readings', 'deliveries'])) {
    $view = 'meter_readings';
}

// ── Filters — read from GET, sanitize ────────────────────────
$filter_date_from = $_GET['date_from'] ?? date('Y-m-d');
$filter_date_to   = $_GET['date_to']   ?? date('Y-m-d');
$filter_shift     = $_GET['shift']     ?? '';
$filter_fuel_type = $_GET['fuel_type'] ?? '';
$filter_staff_id  = (int)($_GET['staff_id'] ?? 0);   // 0 = all (staff see only themselves)
$filter_status    = $_GET['status']    ?? '';

// Sanitize dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) $filter_date_from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to))   $filter_date_to   = date('Y-m-d');

// Staff always see only their own entries — ignore staff_id filter for non-managers
$filter_staff_id = (int)$me['id'];

// ── Shift periods — from DB, fallback to known defaults ───────
$shift_periods = [];
try {
    $sp = $pdo->query("SELECT shift_key, shift_name, start_time, end_time FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC");
    $shift_periods = $sp->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* fallback below */ }
if (empty($shift_periods)) {
    $shift_periods = [
        ['shift_key' => 'first',  'shift_name' => 'First Shift: 6:00 AM – 2:00 PM',          'start_time' => '06:00:00', 'end_time' => '14:00:00'],
        ['shift_key' => 'second', 'shift_name' => 'Second Shift: 2:00 PM – 12:00 Midnight',   'start_time' => '14:00:00', 'end_time' => '23:59:59'],
    ];
}

// ── Fuel types — distinct values from this staff's transactions ─
$fuel_type_list = [];
try {
    $ft = $pdo->prepare("
        SELECT DISTINCT fuel_type
        FROM fuel_transactions
        WHERE station_id = ? AND staff_id = ?
          AND fuel_type IS NOT NULL AND fuel_type <> ''
        ORDER BY fuel_type ASC
    ");
    $ft->execute([$station_id, $me['id']]);
    $fuel_type_list = array_column($ft->fetchAll(PDO::FETCH_ASSOC), 'fuel_type');
} catch (Exception $e) { $fuel_type_list = []; }

// ── Status options — actual values stored in fuel_transactions ─
// Canonical set: Pending Validation, Approved, Verified, Adjusted, Rejected
$status_options = [
    'Pending Validation' => 'Pending Validation',
    'Approved'           => 'Approved',
    'Verified'           => 'Verified',
    'Adjusted'           => 'Adjusted',
    'Rejected'           => 'Rejected',
];

// ── DATA: Meter Reading Table ─────────────────────────────────
$meter_rows = [];
if ($view === 'meter_readings') {
    try {
        $sql = "
            SELECT
                ft.transaction_id,
                ft.fuel_type,
                ft.previous_reading   AS beginning,
                ft.present_reading    AS ending,
                ft.calibration        AS cal,
                ft.liters_sold,
                ft.price_per_liter,
                ft.total_amount,
                ft.shift_period,
                ft.shift_name,
                ft.notes,
                ft.status,
                ft.validated_at,
                DATE(ft.transaction_date) AS reading_date,
                TIME(ft.transaction_date) AS reading_time,
                u.name                    AS staff_name,
                vm.name                   AS validated_by_name
            FROM fuel_transactions ft
            LEFT JOIN users u  ON ft.staff_id    = u.id
            LEFT JOIN users vm ON ft.validated_by = vm.id
            WHERE ft.station_id = ?
              AND ft.staff_id   = ?
              AND DATE(ft.transaction_date) BETWEEN ? AND ?
        ";
        $params = [$station_id, $filter_staff_id, $filter_date_from, $filter_date_to];

        if ($filter_shift !== '') {
            $sql    .= " AND ft.shift_period = ?";
            $params[] = $filter_shift;
        }
        if ($filter_fuel_type !== '') {
            $sql    .= " AND LOWER(TRIM(ft.fuel_type)) = LOWER(TRIM(?))";
            $params[] = $filter_fuel_type;
        }
        if ($filter_status !== '') {
            // Match case-insensitively — DB stores mixed case
            $sql    .= " AND LOWER(TRIM(ft.status)) = LOWER(TRIM(?))";
            $params[] = $filter_status;
        }

        $sql .= " ORDER BY ft.fuel_type ASC, ft.transaction_date ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $meter_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $meter_rows = [];
    }
}

// ── DATA: Fuel Deliveries Report ──────────────────────────────
$delivery_rows = [];
$delivery_summary = ['ytd' => 0, 'monthly' => 0, 'weekly' => 0, 'total_records' => 0];
if ($view === 'deliveries') {
    try {
        $sql = "
            SELECT
                fd.id,
                fd.delivery_date,
                fd.fuel_type,
                fd.supplier,
                fd.invoice_no,
                fd.delivery_liters,
                fd.tanker_number,
                fd.notes,
                fd.status,
                fd.created_at,
                fd.verified_at,
                v.name AS verified_by_name
            FROM fuel_deliveries fd
            LEFT JOIN users v ON fd.verified_by = v.id
            WHERE fd.station_id  = ?
              AND fd.received_by = ?
              AND DATE(fd.delivery_date) BETWEEN ? AND ?
        ";
        $params = [$station_id, $me['id'], $filter_date_from, $filter_date_to];

        if ($filter_fuel_type !== '') {
            $sql    .= " AND LOWER(TRIM(fd.fuel_type)) = LOWER(TRIM(?))";
            $params[] = $filter_fuel_type;
        }

        $sql .= " ORDER BY fd.delivery_date DESC, fd.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $delivery_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $delivery_rows = [];
    }

    // ── Delivery summary aggregates (YTD / Monthly / Weekly) ─────────────────
    try {
        $agg = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN YEAR(delivery_date) = YEAR(CURDATE()) THEN delivery_liters ELSE 0 END), 0)                                                AS ytd,
                COALESCE(SUM(CASE WHEN YEAR(delivery_date) = YEAR(CURDATE()) AND MONTH(delivery_date) = MONTH(CURDATE()) THEN delivery_liters ELSE 0 END), 0)    AS monthly,
                COALESCE(SUM(CASE WHEN delivery_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN delivery_liters ELSE 0 END), 0)                                 AS weekly,
                COUNT(*) AS total_records
            FROM fuel_deliveries
            WHERE station_id  = ?
              AND received_by = ?
        ");
        $agg->execute([$station_id, $me['id']]);
        $delivery_summary = $agg->fetch(PDO::FETCH_ASSOC) ?: $delivery_summary;
    } catch (Exception $e) { /* non-fatal */ }
}

// ── Status badge helper ───────────────────────────────────────
function sfr_badge(string $status): string {
    $s = strtolower(trim($status));
    $map = [
        'pending validation' => ['#fef9c3','#854d0e','Pending Validation'],
        'pending'            => ['#fef9c3','#854d0e','Pending'],
        'approved'           => ['#dcfce7','#166534','Approved'],
        'verified'           => ['#dcfce7','#166534','Verified'],
        'adjusted'           => ['#dbeafe','#1d4ed8','Adjusted'],
        'rejected'           => ['#fee2e2','#991b1b','Rejected'],
    ];
    [$bg, $color, $label] = $map[$s] ?? ['#f1f5f9','#64748b', htmlspecialchars($status)];
    return "<span style=\"background:{$bg};color:{$color};padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;\">{$label}</span>";
}

function sfr_clean($value): string {
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : '-';
}

function sfr_num($value, int $places = 2): string {
    return number_format((float)($value ?? 0), $places);
}

function sfr_shift_label(array $row, array $shift_periods): string {
    $shift_label = trim((string)($row['shift_name'] ?? ''));
    $shift_period = trim((string)($row['shift_period'] ?? ''));
    if ($shift_label === '' && $shift_period !== '') {
        foreach ($shift_periods as $sp) {
            if (($sp['shift_key'] ?? '') === $shift_period) {
                $shift_label = (string)($sp['shift_name'] ?? '');
                break;
            }
        }
    }
    return $shift_label !== '' ? $shift_label : ucwords(str_replace('_', ' ', $shift_period ?: '-'));
}

function sfr_export_query(array $extra = []): string {
    global $view, $filter_date_from, $filter_date_to, $filter_shift, $filter_fuel_type, $filter_status;
    $params = [
        'view' => $view,
        'date_from' => $filter_date_from,
        'date_to' => $filter_date_to,
        'fuel_type' => $filter_fuel_type,
    ];
    if ($view === 'meter_readings') {
        $params['shift'] = $filter_shift;
        $params['status'] = $filter_status;
    }
    return http_build_query(array_merge($params, $extra));
}

function sfr_export_dataset(array $meter_rows, array $delivery_rows, array $shift_periods, array $me, string $view): array {
    $staff_name = sfr_clean($me['name'] ?? $me['username'] ?? 'Staff');
    if ($view === 'deliveries') {
        $headers = ['Delivery ID', 'Delivery Date', 'Fuel Type', 'Supplier', 'Quantity (L)', 'Invoice No.', 'Tanker No.', 'Staff / Cashier', 'Remarks', 'Status', 'Verified By'];
        $rows = [];
        foreach ($delivery_rows as $d) {
            $verified = sfr_clean($d['verified_by_name'] ?? '');
            if ($verified !== '-' && !empty($d['verified_at'])) {
                $verified .= ' - ' . date('M j, Y h:i A', strtotime($d['verified_at']));
            }
            $rows[] = [
                '#' . (int)($d['id'] ?? 0),
                !empty($d['delivery_date']) ? date('M j, Y', strtotime($d['delivery_date'])) : '-',
                sfr_clean($d['fuel_type'] ?? ''),
                sfr_clean($d['supplier'] ?? ''),
                sfr_num($d['delivery_liters'] ?? 0),
                sfr_clean($d['invoice_no'] ?? ''),
                sfr_clean($d['tanker_number'] ?? ''),
                $staff_name,
                sfr_clean($d['notes'] ?? ''),
                sfr_clean($d['status'] ?? 'Pending'),
                $verified,
            ];
        }
        return ['Staff Fuel Deliveries Report', 'staff_fuel_deliveries_report', $headers, $rows];
    }

    $headers = ['Fuel Type', 'Beginning', 'Ending', 'Calibration', 'Liters Sold', 'Price/L', 'Amount', 'Shift', 'Date', 'Time', 'Staff / Cashier', 'Notes', 'Status', 'Validated By'];
    $rows = [];
    foreach ($meter_rows as $r) {
        $validated = sfr_clean($r['validated_by_name'] ?? '');
        if ($validated !== '-' && !empty($r['validated_at'])) {
            $validated .= ' - ' . date('M j, Y h:i A', strtotime($r['validated_at']));
        }
        $rows[] = [
            sfr_clean($r['fuel_type'] ?? ''),
            sfr_num($r['beginning'] ?? 0),
            sfr_num($r['ending'] ?? 0),
            sfr_num($r['cal'] ?? 0, 3),
            sfr_num($r['liters_sold'] ?? 0),
            'PHP ' . sfr_num($r['price_per_liter'] ?? 0),
            'PHP ' . sfr_num($r['total_amount'] ?? 0),
            sfr_shift_label($r, $shift_periods),
            !empty($r['reading_date']) ? date('M j, Y', strtotime($r['reading_date'])) : '-',
            !empty($r['reading_time']) ? date('h:i A', strtotime($r['reading_time'])) : '-',
            sfr_clean($r['staff_name'] ?? $staff_name),
            sfr_clean($r['notes'] ?? ''),
            sfr_clean($r['status'] ?? 'Pending Validation'),
            $validated,
        ];
    }
    return ['Staff Meter Reading Report', 'staff_meter_reading_report', $headers, $rows];
}

if (isset($_GET['export'])) {
    $export_format = strtolower(trim((string)$_GET['export']));
    if (in_array($export_format, ['excel', 'csv'], true)) {
        [$export_title, $export_file, $export_headers, $export_rows] = sfr_export_dataset($meter_rows, $delivery_rows, $shift_periods, $me, $view);
        $filename_base = $export_file . '_' . date('Ymd', strtotime($filter_date_from)) . '_' . date('Ymd', strtotime($filter_date_to));
        $meta_rows = [
            ['Station', $station_name],
            ['Location', $station_location ?: '-'],
            ['Staff', sfr_clean($me['name'] ?? $me['username'] ?? 'Staff')],
            ['Date Range', date('M j, Y', strtotime($filter_date_from)) . ' - ' . date('M j, Y', strtotime($filter_date_to))],
            ['Generated', date('M j, Y h:i A')],
        ];

        if ($export_format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
            header('Cache-Control: max-age=0');
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF");
            fputcsv($out, [$export_title]);
            foreach ($meta_rows as $row) fputcsv($out, $row);
            fputcsv($out, []);
            fputcsv($out, $export_headers);
            foreach ($export_rows as $row) fputcsv($out, $row);
            fclose($out);
            exit;
        }

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename_base . '.xls"');
        header('Cache-Control: max-age=0');
        echo "\xEF\xBB\xBF";
        echo '<html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;}table{border-collapse:collapse;width:100%;}th,td{border:1px solid #222;padding:7px;}th{background:#e8eef8;font-weight:bold;}h1{font-size:18px;}</style></head><body>';
        echo '<h1>' . htmlspecialchars($export_title) . '</h1>';
        echo '<table>';
        foreach ($meta_rows as $row) {
            echo '<tr><th style="width:180px;text-align:left;">' . htmlspecialchars($row[0]) . '</th><td>' . htmlspecialchars($row[1]) . '</td></tr>';
        }
        echo '</table><br>';
        echo '<table><thead><tr>';
        foreach ($export_headers as $head) echo '<th>' . htmlspecialchars($head) . '</th>';
        echo '</tr></thead><tbody>';
        if (empty($export_rows)) {
            echo '<tr><td colspan="' . count($export_headers) . '" style="text-align:center;">No records found.</td></tr>';
        } else {
            foreach ($export_rows as $row) {
                echo '<tr>';
                foreach ($row as $cell) echo '<td>' . htmlspecialchars($cell) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></body></html>';
        exit;
    }
}

$sfr_report_title = $view === 'deliveries' ? 'Staff Fuel Deliveries Report' : 'Staff Meter Reading Report';
$sfr_report_file = ($view === 'deliveries' ? 'staff_fuel_deliveries_report' : 'staff_meter_reading_report')
    . '_' . date('Ymd', strtotime($filter_date_from)) . '_' . date('Ymd', strtotime($filter_date_to));

require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   STAFF FUEL REPORTS — Page Styles
═══════════════════════════════════════════════════════════════ */
.sfr-page { padding: 0; min-width: 0; }

.sfr-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 20px;
    gap: 14px;
    flex-wrap: wrap;
}

.sfr-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.sfr-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: rgba(0,47,108,.12);
    color: var(--petron-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.sfr-title h1 {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: var(--petron-blue) !important;
    margin: 0 !important;
}

.sfr-title p {
    font-size: 12px;
    color: #64748b;
    margin: 3px 0 0;
}

/* Tab nav */
.sfr-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    padding: 0 4px;
    border-bottom: none;
}

.sfr-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    text-decoration: none;
    background: #ffffff !important;
    border: 1px solid #002F6C !important;
    color: #002F6C !important;
    white-space: nowrap;
}

.sfr-tab:hover {
    background: #002F6C !important;
    color: #ffffff !important;
}

.sfr-tab.active {
    background: #002F6C !important;
    border: 1px solid #002F6C !important;
    color: #ffffff !important;
    font-weight: 700;
}

/* Filter bar */
.sfr-filter-bar {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 18px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.sfr-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    }

.sfr-field label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.sfr-input, .sfr-select {
    padding: 9px 12px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: 13px;
    color: #1e293b;
    background: #fff;
    transition: border-color .15s;
    width: 100%;
}

.sfr-input:focus, .sfr-select:focus {
    outline: none;
    border-color: var(--petron-blue);
    box-shadow: 0 0 0 3px rgba(0,47,108,.08);
}

.sfr-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .2s ease-in-out;
    text-decoration: none;
    white-space: nowrap;
    box-sizing: border-box;
    background: #ffffff !important;
}

.sfr-btn.primary {
    color: #002F6C !important;
    border: 1px solid #002F6C !important;
}
.sfr-btn.primary:hover {
    background: #002F6C !important;
    color: #ffffff !important;
}
.sfr-btn.secondary {
    color: #475569 !important;
    border: 1px solid #475569 !important;
}
.sfr-btn.secondary:hover {
    background: #475569 !important;
    color: #ffffff !important;
}

.sfr-export-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.sfr-export-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 13px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #00264D;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
}

.sfr-export-btn.excel,
.sfr-export-btn.csv,
.sfr-export-btn.pdf,
.sfr-export-btn.print { color: #00264D; border-color: #cbd5e1; }

.sfr-export-btn:hover,
.sfr-export-btn.excel:hover,
.sfr-export-btn.csv:hover,
.sfr-export-btn.pdf:hover,
.sfr-export-btn.print:hover {
    background: #f8fafc;
    border-color: #00264D;
    color: #00264D;
}

.sfr-print-area {
    min-width: 0;
}

.sfr-print-heading {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #002F6C;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 14px;
    color: #475569;
    font-size: 12px;
    line-height: 1.5;
}

.sfr-print-heading strong {
    display: block;
    color: #002F6C;
    font-size: 15px;
    margin-bottom: 4px;
}

/* Card */
.sfr-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    overflow: hidden;
    margin-bottom: 20px;
}

.sfr-card-header {
    padding: 14px 20px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.sfr-card-header h3 {
    font-size: 13px !important;
    font-weight: 700 !important;
    color: #1e293b !important;
    margin: 0 !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
}

.sfr-card-header .row-count {
    margin-left: auto;
    font-size: 11px;
    color: #64748b;
    font-weight: 500;
}

/* Table */
.sfr-table-wrap { overflow-x:auto;-webkit-overflow-scrolling:touch; }

.sfr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.sfr-table th {
    background: #f8fafc;
    padding: 10px 13px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .4px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}

.sfr-table td {
    padding: 10px 13px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
}

.sfr-table tr:last-child td { border-bottom: none; }
.sfr-table tr:hover td { background: #f8fafc; }
.sfr-table .num { text-align: right; font-variant-numeric: tabular-nums; }

.sfr-empty {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
    font-size: 13px;
}

.sfr-empty i { font-size: 28px; display: block; margin-bottom: 10px; }

/* Info notice */
.sfr-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 16px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 9px;
    margin-bottom: 18px;
    font-size: 13px;
    color: #1d4ed8;
}

.sfr-notice i { flex-shrink: 0; margin-top: 1px; }

@media (max-width: 768px) {
    .sfr-filter-bar { flex-direction: column; }
    .sfr-field { min-width: 100%; }
    .sfr-export-actions { justify-content: flex-start; width: 100%; }
}
</style>

<div class="sfr-page">

    <!-- Page Header -->
    <div class="sfr-header">
        <div class="sfr-title">
            <div class="sfr-icon"><i class="fas fa-file-alt"></i></div>
            <div>
                <h1>Fuel Reports</h1>
                <p>
                    <i class="fas fa-user"></i> <?= htmlspecialchars($me['name'] ?? $me['username']) ?>
                    &nbsp;|&nbsp;
                    <i class="fas fa-calendar"></i> <?= date('F j, Y') ?>
                </p>
            </div>
        </div>
        <a href="staff_transactions_hub.php?section=fuel" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Fuel Transactions
        </a>
    </div>

    <!-- Info notice: what staff can and cannot see -->
    <div class="sfr-notice">
        <i class="fas fa-info-circle"></i>
        <div>
            <strong>Staff Reports:</strong>
            You can view your own <strong>Meter Readings</strong> and <strong>Fuel Deliveries</strong>.
            Volume Sales Summary, Amount Summary, Variance Reports, and Audit Trail are
            <strong>Manager/Admin only</strong>.
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="sfr-tabs">
        <a href="staff_fuel_reports.php?view=meter_readings&date_from=<?= urlencode($filter_date_from) ?>&date_to=<?= urlencode($filter_date_to) ?>&shift=<?= urlencode($filter_shift) ?>&fuel_type=<?= urlencode($filter_fuel_type) ?>"
           class="sfr-tab <?= $view === 'meter_readings' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i> Meter Reading Report
        </a>
        <a href="staff_fuel_reports.php?view=deliveries&date_from=<?= urlencode($filter_date_from) ?>&date_to=<?= urlencode($filter_date_to) ?>&fuel_type=<?= urlencode($filter_fuel_type) ?>"
           class="sfr-tab <?= $view === 'deliveries' ? 'active' : '' ?>">
            <i class="fas fa-truck"></i> Fuel Deliveries Report
        </a>
    </div>

    <!-- ── Filter Bar ─────────────────────────────────────────── -->
    <form method="GET" action="staff_fuel_reports.php" id="sfrFilterForm">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:18px;box-shadow:0 1px 4px rgba(0,0,0,.04);">

            <!-- Row 1: Date Range + Shift + Fuel Type -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:12px;">

                <!-- Date From -->
                <div class="sfr-field">
                    <label><i class="fas fa-calendar-day" style="margin-right:3px;color:#64748b;"></i> Date From</label>
                    <input type="date" name="date_from" class="sfr-input"
                           value="<?= htmlspecialchars($filter_date_from) ?>">
                </div>

                <!-- Date To -->
                <div class="sfr-field">
                    <label><i class="fas fa-calendar-day" style="margin-right:3px;color:#64748b;"></i> Date To</label>
                    <input type="date" name="date_to" class="sfr-input"
                           value="<?= htmlspecialchars($filter_date_to) ?>">
                </div>

                <!-- Shift Period — from DB -->
                <?php if ($view === 'meter_readings'): ?>
                <div class="sfr-field" style="">
                    <label><i class="fas fa-clock" style="margin-right:3px;color:#64748b;"></i> Shift Period</label>
                    <select name="shift" class="sfr-select">
                        <option value="">All Shifts</option>
                        <?php foreach ($shift_periods as $sp): ?>
                        <option value="<?= htmlspecialchars($sp['shift_key']) ?>"
                            <?= $filter_shift === $sp['shift_key'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sp['shift_name']) ?>
                            <?php if (!empty($sp['start_time']) && !empty($sp['end_time'])): ?>
                            (<?= date('g:i A', strtotime($sp['start_time'])) ?> – <?= date('g:i A', strtotime($sp['end_time'])) ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Fuel Type — from DB (distinct values this staff has encoded) -->
                <div class="sfr-field">
                    <label><i class="fas fa-gas-pump" style="margin-right:3px;color:#64748b;"></i> Fuel Type</label>
                    <select name="fuel_type" class="sfr-select">
                        <option value="">All Types</option>
                        <?php foreach ($fuel_type_list as $ft): ?>
                        <option value="<?= htmlspecialchars($ft) ?>"
                            <?= $filter_fuel_type === $ft ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ft) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if (empty($fuel_type_list)): ?>
                        <option disabled>No data yet</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Status — actual values from fuel_transactions -->
                <?php if ($view === 'meter_readings'): ?>
                <div class="sfr-field">
                    <label><i class="fas fa-flag" style="margin-right:3px;color:#64748b;"></i> Status</label>
                    <select name="status" class="sfr-select">
                        <option value="">All Statuses</option>
                        <?php foreach ($status_options as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= $filter_status === $val ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

            </div>

            <!-- Row 2: Staff info (read-only display) + Action buttons -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">

                <!-- Staff identity chip — staff always see their own data -->
                <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:#64748b;">
                    <span style="background:#eff6ff;color:#1d4ed8;padding:4px 12px;border-radius:20px;font-weight:700;display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-user"></i>
                        <?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?>
                        <span style="opacity:.6;font-weight:400;">— your entries only</span>
                    </span>
                    <?php if ($filter_date_from || $filter_date_to || $filter_shift || $filter_fuel_type || $filter_status): ?>
                    <span style="font-size:11px;color:#94a3b8;">
                        <?php
                        $active = [];
                        if ($filter_date_from && $filter_date_to) $active[] = date('M j', strtotime($filter_date_from)) . ' – ' . date('M j, Y', strtotime($filter_date_to));
                        if ($filter_shift) {
                            foreach ($shift_periods as $sp) {
                                if ($sp['shift_key'] === $filter_shift) { $active[] = $sp['shift_name']; break; }
                            }
                        }
                        if ($filter_fuel_type) $active[] = $filter_fuel_type;
                        if ($filter_status)    $active[] = $filter_status;
                        echo implode(' &bull; ', array_map('htmlspecialchars', $active));
                        ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- Buttons -->
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:flex-end;">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="submit" class="txn-btn primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="staff_fuel_reports.php?view=<?= htmlspecialchars($view) ?>" class="txn-btn secondary">
                            <i class="fas fa-undo"></i> Reset
                        </a>
                    </div>

                    <div class="sfr-export-actions">
                        <a href="staff_fuel_reports.php?<?= htmlspecialchars(sfr_export_query(['export' => 'excel'])) ?>" class="sfr-export-btn excel" title="Export to Excel">
                            <i class="fas fa-file-excel"></i> Excel
                        </a>
                        <a href="staff_fuel_reports.php?<?= htmlspecialchars(sfr_export_query(['export' => 'csv'])) ?>" class="sfr-export-btn csv" title="Export to CSV">
                            <i class="fas fa-file-csv"></i> CSV
                        </a>
                        <button type="button"
                                onclick="exportPrintableAreaToPDF('#sfrPrintableArea', '<?= htmlspecialchars($sfr_report_title, ENT_QUOTES) ?>', '<?= htmlspecialchars($sfr_report_file, ENT_QUOTES) ?>', this)"
                                class="sfr-export-btn pdf"
                                title="Export PDF">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </button>
                        <button type="button"
                                onclick="printReportArea('#sfrPrintableArea')"
                                class="sfr-export-btn print"
                                title="Print report">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>

            </div>

        </div>
    </form>

    <div id="sfrPrintableArea" class="sfr-print-area">

        <div class="sfr-print-heading report-meta">
            <strong><?= htmlspecialchars($sfr_report_title) ?></strong>
            Station: <?= htmlspecialchars($station_name) ?>
            <?php if ($station_location): ?> | <?= htmlspecialchars($station_location) ?><?php endif; ?>
            | Staff: <?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?>
            | Date: <?= date('M j, Y', strtotime($filter_date_from)) ?> - <?= date('M j, Y', strtotime($filter_date_to)) ?>
        </div>

    <?php /* ══════════════════════════════════════════════════════
           VIEW: METER READING REPORT
    ══════════════════════════════════════════════════════ */ ?>
    <?php if ($view === 'meter_readings'): ?>

    <div class="sfr-card">
        <div class="sfr-card-header" style="background:#f0f4ff;">
            <i class="fas fa-tachometer-alt" style="color:var(--petron-blue);"></i>
            <h3>Meter Reading Table</h3>
            <span class="row-count"><?= count($meter_rows) ?> entr<?= count($meter_rows) === 1 ? 'y' : 'ies' ?></span>
        </div>

        <?php if (empty($meter_rows)): ?>
        <div class="sfr-empty">
            <i class="fas fa-tachometer-alt"></i>
            No meter readings found for the selected period.
            <br>
            <a href="staff_transactions_hub.php?section=fuel" style="color:var(--petron-blue);font-size:12px;margin-top:8px;display:inline-block;">
                <i class="fas fa-plus"></i> Encode a reading
            </a>
        </div>
        <?php else: ?>
        <div class="sfr-table-wrap">
            <table class="sfr-table">
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th class="num">Beginning</th>
                        <th class="num">Ending</th>
                        <th class="num">Cal</th>
                        <th class="num">Liters Sold</th>
                        <th class="num">Price/L</th>
                        <th class="num">Amount</th>
                        <th>Shift</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Staff / Cashier</th>
                        <th>Notes</th>
                        <th>Status</th>
                        <th>Validated By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($meter_rows as $r):
                    $shift_label = $r['shift_name'] ?? '';
                    if (!$shift_label && $r['shift_period']) {
                        foreach ($shift_periods as $sp) {
                            if ($sp['shift_key'] === $r['shift_period']) {
                                $shift_label = $sp['shift_name'];
                                break;
                            }
                        }
                    }
                    if (!$shift_label) $shift_label = ucwords(str_replace('_', ' ', $r['shift_period'] ?? '—'));
                    $staff_display = htmlspecialchars($r['staff_name'] ?? $me['name'] ?? $me['username'] ?? '—');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['fuel_type'] ?? '—') ?></strong></td>
                    <td class="num"><?= number_format((float)$r['beginning'], 2) ?></td>
                    <td class="num"><?= number_format((float)$r['ending'], 2) ?></td>
                    <td class="num"><?= number_format((float)$r['cal'], 3) ?></td>
                    <td class="num"><strong><?= number_format((float)$r['liters_sold'], 2) ?> L</strong></td>
                    <td class="num">₱<?= number_format((float)$r['price_per_liter'], 2) ?></td>
                    <td class="num"><strong>₱<?= number_format((float)$r['total_amount'], 2) ?></strong></td>
                    <td style="font-size:11px;color:#64748b;white-space:nowrap;"><?= htmlspecialchars($shift_label) ?></td>
                    <td style="font-size:11px;color:#64748b;white-space:nowrap;">
                        <?= $r['reading_date'] ? date('M j, Y', strtotime($r['reading_date'])) : '—' ?>
                    </td>
                    <td style="font-size:11px;color:#64748b;white-space:nowrap;">
                        <?= $r['reading_time'] ? date('h:i A', strtotime($r['reading_time'])) : '—' ?>
                    </td>
                    <td style="font-size:12px;font-weight:600;"><?= $staff_display ?></td>
                    <td style="font-size:11px;color:#64748b;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= htmlspecialchars($r['notes'] ?? '') ?>">
                        <?= htmlspecialchars($r['notes'] ?? '—') ?>
                    </td>
                    <td><?= sfr_badge($r['status'] ?? 'Pending Validation') ?></td>
                    <td style="font-size:11px;color:#64748b;">
                        <?php if ($r['validated_by_name']): ?>
                            <?= htmlspecialchars($r['validated_by_name']) ?>
                            <?php if ($r['validated_at']): ?>
                            <br><span style="color:#94a3b8;"><?= date('M j, h:i A', strtotime($r['validated_at'])) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php /* ══════════════════════════════════════════════════════
           VIEW: FUEL DELIVERIES REPORT
    ══════════════════════════════════════════════════════ */ ?>
    <?php elseif ($view === 'deliveries'): ?>

    <!-- YTD / Monthly / Weekly Summary Cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px;">

        <div style="background:#fff;border:1px solid #e2e8f0;border-top:4px solid #16a34a;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">
                <i class="fas fa-calendar" style="color:#16a34a;margin-right:4px;"></i> Year-to-Date
            </div>
            <div style="font-size:1.6rem;font-weight:800;color:#15803d;">
                <?= number_format((float)$delivery_summary['ytd'], 2) ?> L
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Total delivered <?= date('Y') ?></div>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-top:4px solid #0891b2;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">
                <i class="fas fa-calendar-alt" style="color:#0891b2;margin-right:4px;"></i> This Month
            </div>
            <div style="font-size:1.6rem;font-weight:800;color:#0e7490;">
                <?= number_format((float)$delivery_summary['monthly'], 2) ?> L
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:4px;"><?= date('F Y') ?></div>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-top:4px solid #7c3aed;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">
                <i class="fas fa-calendar-week" style="color:#7c3aed;margin-right:4px;"></i> This Week
            </div>
            <div style="font-size:1.6rem;font-weight:800;color:#6d28d9;">
                <?= number_format((float)$delivery_summary['weekly'], 2) ?> L
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Last 7 days</div>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-top:4px solid #f59e0b;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">
                <i class="fas fa-list" style="color:#f59e0b;margin-right:4px;"></i> Total Records
            </div>
            <div style="font-size:1.6rem;font-weight:800;color:#b45309;">
                <?= number_format((int)$delivery_summary['total_records']) ?>
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:4px;">All-time deliveries</div>
        </div>

    </div>

    <div class="sfr-card">
        <div class="sfr-card-header" style="background:#f0fdf4;">
            <i class="fas fa-truck" style="color:#16a34a;"></i>
            <h3 style="color:#15803d;">Fuel Deliveries Report</h3>
            <span class="row-count"><?= count($delivery_rows) ?> record<?= count($delivery_rows) === 1 ? '' : 's' ?></span>
            <a href="staff_fuel_deliveries.php" class="sfr-btn primary" style="margin-left:auto;padding:6px 14px;font-size:12px;">
                <i class="fas fa-plus"></i> Record Delivery
            </a>
        </div>

        <?php if (empty($delivery_rows)): ?>
        <div class="sfr-empty">
            <i class="fas fa-truck"></i>
            No fuel deliveries recorded for the selected period.
            <br>
            <a href="staff_fuel_deliveries.php" style="color:#16a34a;font-size:12px;margin-top:8px;display:inline-block;">
                <i class="fas fa-plus"></i> Record a delivery
            </a>
        </div>
        <?php else: ?>
        <div class="sfr-table-wrap">
            <table class="sfr-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Delivery Date</th>
                        <th>Fuel Type</th>
                        <th>Supplier</th>
                        <th class="num">Quantity (L)</th>
                        <th>Invoice No.</th>
                        <th>Tanker No.</th>
                        <th>Staff / Cashier</th>
                        <th>Remarks</th>
                        <th>Status</th>
                        <th>Verified By</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($delivery_rows as $d):
                    $staff_display = htmlspecialchars($me['name'] ?? $me['username'] ?? '—');
                ?>
                <tr>
                    <td><strong>#<?= (int)$d['id'] ?></strong></td>
                    <td style="white-space:nowrap;">
                        <?= $d['delivery_date'] ? date('M j, Y', strtotime($d['delivery_date'])) : '—' ?>
                    </td>
                    <td><strong><?= htmlspecialchars($d['fuel_type'] ?? '—') ?></strong></td>
                    <td><?= htmlspecialchars($d['supplier'] ?? '—') ?></td>
                    <td class="num"><strong><?= number_format((float)$d['delivery_liters'], 2) ?> L</strong></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($d['invoice_no'] ?? '—') ?></td>
                    <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($d['tanker_number'] ?? '—') ?></td>
                    <td style="font-size:12px;font-weight:600;"><?= $staff_display ?></td>
                    <td style="font-size:11px;color:#64748b;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= htmlspecialchars($d['notes'] ?? '') ?>">
                        <?= htmlspecialchars($d['notes'] ?? '—') ?>
                    </td>
                    <td><?= sfr_badge($d['status'] ?? 'Pending') ?></td>
                    <td style="font-size:11px;color:#64748b;">
                        <?php if ($d['verified_by_name']): ?>
                            <?= htmlspecialchars($d['verified_by_name']) ?>
                            <?php if ($d['verified_at']): ?>
                            <br><span style="color:#94a3b8;"><?= date('M j, h:i A', strtotime($d['verified_at'])) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#94a3b8;">Awaiting validation</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>

    </div><!-- /sfrPrintableArea -->

</div><!-- /sfr-page -->

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
