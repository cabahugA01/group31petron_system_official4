<?php
/**
 * STAFF JOB ORDERS REPORT
 * Complete job order tracking with shift summaries
 * Plain black & white design, structured tabular format
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$user_id = (int)($me['id'] ?? 0);
$station_id = user_station_id();

// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php'); exit;
}

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) die('Error: You are not assigned to a station.');

$redirect_date = trim($_GET['report_date'] ?? $_GET['date_start'] ?? $_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $redirect_date)) {
    $redirect_date = date('Y-m-d');
}
$redirect_from = trim($_GET['date_from'] ?? $_GET['date_start'] ?? $redirect_date);
$redirect_to = trim($_GET['date_to'] ?? $_GET['date_end'] ?? $redirect_date);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $redirect_from)) $redirect_from = $redirect_date;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $redirect_to)) $redirect_to = $redirect_from;
if ($redirect_to < $redirect_from) $redirect_to = $redirect_from;

$redirect_params = [
    'report_date' => $redirect_date,
    'tab' => 'merchandise',
    'type' => 'merchandise',
    'date_from' => $redirect_from,
    'date_to' => $redirect_to,
];
if (isset($_GET['export'])) {
    $export = strtolower(trim((string)$_GET['export']));
    if (in_array($export, ['excel', 'csv', 'pdf'], true)) {
        $redirect_params['export'] = $export;
    }
}

header('Location: staff_fuel_sales_summary.php?' . http_build_query($redirect_params));
exit;

// Get Station Info
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $station_name = $st['name'];
    }
} catch (Exception $e) {}

// Date handling
$today = date('Y-m-d');
$date_start = trim($_GET['date_start'] ?? date('Y-m-01'));
$date_end = trim($_GET['date_end'] ?? $today);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end)) $date_end = $today;

// Helper functions
function table_exists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Normalize shift_period label to boolean: true = Shift 1, false = Shift 2.
 * Handles 'First Shift', 'General', 'Morning', '1st', 'Shift 1', etc.
 */
if (!function_exists('is_shift1')) {
    function is_shift1(string $shift): bool {
        $s = strtolower(trim($shift));
        $s2_keys = ['shift 2','shift2','second','2nd','evening','afternoon','night','pm'];
        $s1_keys = ['shift 1','shift1','first','1st','morning','day','general','am'];
        foreach ($s2_keys as $kw) { if (strpos($s, $kw) !== false) return false; }
        foreach ($s1_keys as $kw) { if (strpos($s, $kw) !== false) return true; }
        return strpos($s, '2') === false;
    }
}

// Check available tables
$has_job_orders = table_exists($pdo, 'job_orders');
$has_service_transactions = table_exists($pdo, 'service_transactions');

// Initialize data
$job_orders = [];
$shift1_total = 0;
$shift2_total = 0;
$shift1_count = 0;
$shift2_count = 0;

// ============================================================
// FETCH JOB ORDERS
// ============================================================
if ($has_job_orders) {
    try {
        $sql = "SELECT 
                    jo.id,
                    COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-', jo.id)) AS job_order_id,
                    COALESCE(c.name, jo.customer_name, 'Walk-in') AS customer_name,
                    COALESCE(c.contact_number, '—') AS customer_contact,
                    jo.service_type,
                    (
                        SELECT GROUP_CONCAT(CONCAT(ip.product_name, ' (x', jop.quantity_used, ')') SEPARATOR ', ')
                        FROM job_order_parts jop
                        LEFT JOIN inventory_products ip ON jop.product_id = ip.id
                        WHERE jop.job_order_id = jo.id
                    ) AS parts_materials_used,
                    COALESCE((
                        SELECT SUM(jop.quantity_used)
                        FROM job_order_parts jop
                        WHERE jop.job_order_id = jo.id
                    ), 0) AS quantity,
                    COALESCE((
                        SELECT AVG(jop.unit_cost)
                        FROM job_order_parts jop
                        WHERE jop.job_order_id = jo.id
                    ), 0) AS unit_price,
                    COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) AS labor_fee,
                    COALESCE(jo.total_cost, jo.estimated_cost, 0) AS total_service_amount,
                    COALESCE(jo.payment_method, 'Cash') AS payment_mode,
                    CASE WHEN HOUR(jo.created_at) >= 6 AND HOUR(jo.created_at) < 14 THEN 'Shift 1' ELSE 'Shift 2' END AS shift,
                    jo.status,
                    u.username AS encoder,
                    jo.notes AS remarks,
                    jo.created_at
            FROM job_orders jo
            LEFT JOIN customers c ON jo.customer_id = c.id
            LEFT JOIN users u ON COALESCE(jo.created_by, jo.user_id) = u.id
            WHERE jo.station_id = ? 
              AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $date_start, $date_end]);
        $job_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate shift totals
        foreach ($job_orders as $jo) {
            $amount = (float)$jo['total_service_amount'];
            if (is_shift1($jo['shift'] ?? '')) {
                $shift1_total += $amount;
                $shift1_count++;
            } else {
                $shift2_total += $amount;
                $shift2_count++;
            }
        }
        
    } catch (Exception $e) {
        $error_message = "Error fetching job orders: " . $e->getMessage();
    }
} elseif ($has_service_transactions) {
    // Fallback to service_transactions table
    try {
        $sql = "SELECT 
                    st.id,
                    CONCAT('JO-', LPAD(st.id, 3, '0')) AS job_order_id,
                    COALESCE(c.name, 'Walk-in') AS customer_name,
                    COALESCE(c.contact_number, '—') AS customer_contact,
                    st.service_type,
                    st.parts_used AS parts_materials_used,
                    1 AS quantity,
                    (st.total_amount - st.labor_fee) AS unit_price,
                    st.labor_fee,
                    st.total_amount AS total_service_amount,
                    'Cash' AS payment_mode,
                    st.shift,
                    'Completed' AS status,
                    u.username AS encoder,
                    '' AS remarks,
                    st.created_at
            FROM service_transactions st
            LEFT JOIN customers c ON st.customer_id = c.id
            LEFT JOIN users u ON st.user_id = u.id
            WHERE st.station_id = ? 
              AND DATE(st.created_at) BETWEEN ? AND ?
            ORDER BY st.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $date_start, $date_end]);
        $job_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate shift totals
        foreach ($job_orders as $jo) {
            $shift = strtolower($jo['shift'] ?? '');
            $amount = (float)$jo['total_service_amount'];
            
            if (strpos($shift, 'shift 1') !== false || strpos($shift, '1') !== false) {
                $shift1_total += $amount;
                $shift1_count++;
            } else {
                $shift2_total += $amount;
                $shift2_count++;
            }
        }
        
    } catch (Exception $e) {}
}

// ============================================================
// EXCEL EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="Job_Orders_Report_' . $date_start . '_to_' . $date_end . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<!--[if gte mso 9]>';
    echo '<xml>';
    echo '<x:ExcelWorkbook>';
    echo '<x:ExcelWorksheets>';
    echo '<x:ExcelWorksheet>';
    echo '<x:Name>Job Orders Report</x:Name>';
    echo '<x:WorksheetOptions>';
    echo '<x:Print>';
    echo '<x:ValidPrinterInfo/>';
    echo '</x:Print>';
    echo '</x:WorksheetOptions>';
    echo '</x:ExcelWorksheet>';
    echo '</x:ExcelWorksheets>';
    echo '</x:ExcelWorkbook>';
    echo '</xml>';
    echo '<![endif]-->';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }';
    echo 'th, td { border: 1px solid #000000; padding: 8px; text-align: left; }';
    echo 'th { background-color: #E0E0E0; font-weight: bold; text-align: center; }';
    echo '.text-right { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo '.font-bold { font-weight: bold; }';
    echo 'h1 { font-size: 18px; font-weight: bold; margin: 10px 0; }';
    echo 'h2 { font-size: 14px; font-weight: bold; margin: 15px 0 10px 0; background-color: #F0F0F0; padding: 5px; border: 1px solid #000; }';
    echo 'h3 { font-size: 12px; font-weight: bold; margin: 10px 0 5px 0; }';
    echo 'p { margin: 5px 0; }';
    echo '.summary-table { background-color: #F9F9F9; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Header
    echo '<h1>JOB ORDERS REPORT</h1>';
    echo '<p>' . htmlspecialchars($station_name) . '</p>';
    echo '<p><strong>Period:</strong> ' . date('F d, Y', strtotime($date_start)) . ' - ' . date('F d, Y', strtotime($date_end)) . '</p>';
    echo '<br/>';
    
    // JOB ORDERS TABLE
    echo '<h2>JOB ORDERS</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Job Order ID</th>';
    echo '<th>Customer Info</th>';
    echo '<th>Service Type</th>';
    echo '<th>Parts/Materials Used</th>';
    echo '<th>Quantity</th>';
    echo '<th>Unit Price</th>';
    echo '<th>Labor Fee</th>';
    echo '<th>Total Service Amount</th>';
    echo '<th>Payment Mode</th>';
    echo '<th>Shift</th>';
    echo '<th>Status</th>';
    echo '<th>Encoder</th>';
    echo '<th>Remarks</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($job_orders) > 0) {
        foreach ($job_orders as $jo) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($jo['job_order_id']) . '</td>';
            echo '<td>' . htmlspecialchars($jo['customer_name']) . '<br>' . htmlspecialchars($jo['customer_contact']) . '</td>';
            echo '<td>' . htmlspecialchars($jo['service_type']) . '</td>';
            echo '<td>' . (!empty($jo['parts_materials_used']) ? htmlspecialchars($jo['parts_materials_used']) : '—') . '</td>';
            echo '<td class="text-right">' . ($jo['quantity'] > 0 ? number_format($jo['quantity'], 0) : '—') . '</td>';
            echo '<td class="text-right">' . ($jo['quantity'] > 0 ? '₱' . number_format($jo['unit_price'], 2) : '—') . '</td>';
            echo '<td class="text-right">₱' . number_format($jo['labor_fee'], 2) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($jo['total_service_amount'], 2) . '</td>';
            echo '<td>' . htmlspecialchars($jo['payment_mode']) . '</td>';
            echo '<td>' . htmlspecialchars($jo['shift'] ?? 'N/A') . '</td>';
            echo '<td class="text-center">' . strtoupper($jo['status']) . '</td>';
            echo '<td>' . htmlspecialchars($jo['encoder'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($jo['remarks'] ?? '—') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="13" style="text-align: center; padding: 20px;">No job orders found for this period.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // Shift Summary
    echo '<h3>JOB ORDERS - SHIFT SUMMARY</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Shift</th>';
    echo '<th>Total Job Orders</th>';
    echo '<th>Total Amount</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    echo '<tr>';
    echo '<td><strong>Shift 1 (6AM - 2PM)</strong></td>';
    echo '<td class="text-center">' . $shift1_count . '</td>';
    echo '<td class="text-right font-bold">₱' . number_format($shift1_total, 2) . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td><strong>Shift 2 (2PM - 10PM)</strong></td>';
    echo '<td class="text-center">' . $shift2_count . '</td>';
    echo '<td class="text-right font-bold">₱' . number_format($shift2_total, 2) . '</td>';
    echo '</tr>';
    echo '<tr class="font-bold">';
    echo '<td>OVERALL TOTAL</td>';
    echo '<td class="text-center">' . ($shift1_count + $shift2_count) . '</td>';
    echo '<td class="text-right">₱' . number_format($shift1_total + $shift2_total, 2) . '</td>';
    echo '</tr>';
    echo '</tbody>';
    echo '</table>';
    
    echo '</body>';
    echo '</html>';
    exit;
}

$page_title = "Job Orders Report";

// Include system header
require_once __DIR__ . '/../partials/header.php';
?>

<style>
    body {
        margin: 0;
        padding: 0;
        background: white;
    }
    
    .main-content {
        width: 100%;
        max-width: 100%;
        padding: 0;
        margin: 0;
    }
    
    .container {
        max-width: 100%;
        margin: 0;
        padding: 0;
        background: white;
    }
    
    .header {
        background: #fff;
        color: #000;
        padding: 15px 20px;
        text-align: center;
        border-bottom: 2px solid #000;
        margin-bottom: 0;
    }
    
    .header h1 {
        font-size: 22px;
        margin: 0 0 8px 0;
        font-weight: 700;
        color: #000;
    }
    
    .header p {
        font-size: 12px;
        color: #000;
        margin: 3px 0;
    }
    
    .controls {
        padding: 12px 20px;
        background: #fff;
        border-bottom: 1px solid #000;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .date-controls {
        display: flex;
        gap: 8px;
        align-items: center;
        font-size: 12px;
    }
    
    .date-controls label {
        font-weight: 700;
        color: #000;
    }
    
    .date-controls input[type="date"] {
        padding: 6px 10px;
        border: 1px solid #000;
        font-size: 12px;
    }
    
    .btn {
        padding: 6px 12px;
        border: 1px solid #000;
        background: #fff;
        cursor: pointer;
        font-size: 12px;
        font-weight: 500;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #000;
    }
    
    .btn:hover {
        background: #f5f5f5;
    }
    
    .btn-primary {
        background: #000;
        color: #fff;
    }
    
    .btn-primary:hover {
        background: #333;
    }
    
    .print-area {
        background: #fff;
    }
    
    .content {
        padding: 15px 20px;
    }
    
    .section-title {
        font-size: 16px;
        font-weight: 700;
        margin: 20px 0 10px 0;
        color: #000;
        padding-bottom: 8px;
        border-bottom: 2px solid #000;
        text-transform: uppercase;
    }
    
    .table-container {
        overflow-x: visible;
        margin-bottom: 20px;
        width: 100%;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border: 1px solid #000;
        font-size: 10px;
        table-layout: fixed;
    }
    
    thead {
        background: #fff;
        color: #000;
    }
    
    th {
        padding: 6px 4px;
        text-align: left;
        font-weight: 700;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0;
        border: 1px solid #000;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    td {
        padding: 5px 4px;
        border: 1px solid #000;
        font-size: 10px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    tbody tr {
        background: #fff;
    }
    
    /* Column width optimization */
    table th:nth-child(1), table td:nth-child(1) { width: 6%; }  /* Job Order ID */
    table th:nth-child(2), table td:nth-child(2) { width: 10%; } /* Customer Info */
    table th:nth-child(3), table td:nth-child(3) { width: 8%; }  /* Service Type */
    table th:nth-child(4), table td:nth-child(4) { width: 10%; } /* Parts/Materials */
    table th:nth-child(5), table td:nth-child(5) { width: 5%; }  /* Quantity */
    table th:nth-child(6), table td:nth-child(6) { width: 7%; }  /* Unit Price */
    table th:nth-child(7), table td:nth-child(7) { width: 7%; }  /* Labor Fee */
    table th:nth-child(8), table td:nth-child(8) { width: 8%; }  /* Total Amount */
    table th:nth-child(9), table td:nth-child(9) { width: 6%; }  /* Payment Mode */
    table th:nth-child(10), table td:nth-child(10) { width: 5%; } /* Shift */
    table th:nth-child(11), table td:nth-child(11) { width: 7%; } /* Status */
    table th:nth-child(12), table td:nth-child(12) { width: 7%; } /* Encoder */
    table th:nth-child(13), table td:nth-child(13) { width: 14%; } /* Remarks */
    
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .font-bold { font-weight: 700; }
    
    .shift-summary {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin: 20px 0;
    }
    
    .shift-box {
        background: #fff;
        padding: 15px;
        border: 1px solid #000;
    }
    
    .shift-box h3 {
        font-size: 14px;
        color: #000;
        margin: 0 0 10px 0;
        font-weight: 700;
        border-bottom: 1px solid #000;
        padding-bottom: 8px;
        text-transform: uppercase;
    }
    
    .shift-box table {
        font-size: 11px;
    }
    
    .shift-box td {
        padding: 6px 4px;
        border: none;
        border-bottom: 1px solid #ddd;
    }
    
    @media print {
        @page {
            size: A4 portrait;
            margin: 0.5in 0.4in;
        }

        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area {
            position: fixed !important; top: 0 !important; left: 0 !important;
            width: 100% !important; margin: 0 !important; padding: 0 !important;
            background: white !important;
        }
        html, body { margin: 0 !important; padding: 0 !important; background: white !important; overflow: visible !important; }
        .container, .content { margin: 0 !important; padding: 0 !important; }

        /* ── Kill ALL icons ── */
        i, svg, .fas, .far, .fab, .fa, [class*="fa-"] {
            display: none !important;
            width: 0 !important; height: 0 !important;
            font-size: 0 !important; line-height: 0 !important;
            margin: 0 !important; padding: 0 !important;
        }

        .header { text-align: center !important; border-bottom: 2px solid #000 !important; padding: 6px 0 !important; margin: 0 0 8px 0 !important; }
        .header h1 { font-size: 16px !important; font-weight: 700 !important; color: #000 !important; margin: 0 0 3px 0 !important; }
        .header p { font-size: 10px !important; color: #000 !important; margin: 2px 0 !important; }
        .section-title { font-size: 12px !important; font-weight: 700 !important; margin: 8px 0 4px 0 !important; padding-bottom: 3px !important; border-bottom: 2px solid #000 !important; page-break-after: avoid !important; }
        .table-container { overflow: visible !important; width: 100% !important; text-align: center !important; }
        table { width: 95% !important; max-width: 100% !important; border-collapse: collapse !important; font-size: 10px !important; table-layout: auto !important; margin: 0 auto 8px auto !important; }
        thead { display: table-header-group !important; }
        tbody { display: table-row-group !important; }
        tr { page-break-inside: avoid !important; }
        th { font-size: 10px !important; padding: 6px 8px !important; border: 1px solid #000 !important; background: #fff !important; color: #000 !important; font-weight: 700 !important; text-align: center !important; white-space: nowrap !important; }
        td { font-size: 9px !important; padding: 5px 8px !important; border: 1px solid #000 !important; white-space: nowrap !important; vertical-align: top !important; }
        .shift-summary { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 6px !important; margin: 6px 0 !important; page-break-inside: avoid !important; }
        .shift-box { border: 1px solid #000 !important; padding: 5px !important; }
        .shift-box h3 { font-size: 10px !important; border-bottom: 1px solid #000 !important; padding-bottom: 2px !important; margin: 0 0 4px 0 !important; }
        .shift-box table { width: auto !important; margin: 0 !important; }
        .shift-box td { border: none !important; border-bottom: 1px solid #ddd !important; font-size: 9px !important; }
        .remarks-section { border: 1px solid #000 !important; padding: 5px !important; margin-top: 6px !important; }
        .remarks-section h3 { font-size: 8px !important; border-bottom: 1px solid #000 !important; padding-bottom: 2px !important; margin: 0 0 4px 0 !important; }
        .remarks-list li { font-size: 7px !important; padding: 2px !important; }
    }
</style>

<div class="stock-page">
<!-- CONTROLS - OUTSIDE PRINTABLE AREA -->
<div class="controls">
    <div class="date-controls">
        <label><strong>From:</strong></label>
        <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>">
        <label><strong>To:</strong></label>
        <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>">
        <button class="btn btn-primary" onclick="applyFilters()">
            Apply
        </button>
    </div>
    
    <div>
        <a href="?export=excel&date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>" class="btn">
            Export Excel
        </a>
        <button type="button" class="btn" onclick="exportPrintableAreaToPDF('.print-area', 'Staff Job Orders Report', 'staff_job_orders_report_<?= date('Ymd', strtotime($date_start)) ?>_<?= date('Ymd', strtotime($date_end)) ?>', this)">
            PDF
        </button>
        <button type="button" class="btn" onclick="printReportArea()">
            Print
        </button>
    </div>
</div>

<!-- PRINTABLE DOCUMENT AREA -->
<div class="print-area">
    <div class="container">
        <div class="header">
            <h1>JOB ORDERS REPORT</h1>
            <p><?= htmlspecialchars($station_name) ?></p>
            <p><strong>Period:</strong> <?= date('F d, Y', strtotime($date_start)) ?> - <?= date('F d, Y', strtotime($date_end)) ?></p>
        </div>
        
        <div class="content">
            <!-- Job Orders Table -->
            <div class="section-title">
                JOB ORDERS
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>JOB ORDER ID</th>
                            <th>CUSTOMER INFO</th>
                            <th>SERVICE TYPE</th>
                            <th>PARTS/MATERIALS</th>
                            <th>QTY</th>
                            <th>UNIT PRICE</th>
                            <th>LABOR FEE</th>
                            <th>TOTAL AMOUNT</th>
                            <th>PAYMENT</th>
                            <th>SHIFT</th>
                            <th>STATUS</th>
                            <th>ENCODER</th>
                            <th>REMARKS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (count($job_orders) > 0):
                            foreach ($job_orders as $jo): 
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($jo['job_order_id']) ?></strong></td>
                            <td><?= htmlspecialchars($jo['customer_name']) ?><br><small><?= htmlspecialchars($jo['customer_contact']) ?></small></td>
                            <td><?= htmlspecialchars($jo['service_type']) ?></td>
                            <td><?= !empty($jo['parts_materials_used']) ? htmlspecialchars($jo['parts_materials_used']) : '—' ?></td>
                            <td class="text-right"><?= $jo['quantity'] > 0 ? number_format($jo['quantity'], 0) : '—' ?></td>
                            <td class="text-right"><?= $jo['quantity'] > 0 ? '₱' . number_format($jo['unit_price'], 2) : '—' ?></td>
                            <td class="text-right">₱<?= number_format($jo['labor_fee'], 2) ?></td>
                            <td class="text-right font-bold">₱<?= number_format($jo['total_service_amount'], 2) ?></td>
                            <td><?= htmlspecialchars($jo['payment_mode']) ?></td>
                            <td><?= htmlspecialchars($jo['shift'] ?? 'N/A') ?></td>
                            <td class="text-center"><span class="status-badge"><?= strtoupper($jo['status']) ?></span></td>
                            <td><?= htmlspecialchars($jo['encoder'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($jo['remarks'] ?? '—') ?></td>
                        </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <tr>
                            <td colspan="13" style="text-align: center; padding: 40px;">
                                No job orders found for this period.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Shift Summary -->
            <div class="section-title">
                SHIFT SUMMARY
            </div>
            <div class="shift-summary">
                <!-- Shift 1 -->
                <div class="shift-box">
                    <h3>SHIFT 1 (6AM - 2PM)</h3>
                    <table>
                        <tbody>
                            <tr>
                                <td><strong>Total Job Orders:</strong></td>
                                <td class="text-right font-bold"><?= $shift1_count ?></td>
                            </tr>
                            <tr>
                                <td><strong>Total Amount:</strong></td>
                                <td class="text-right font-bold">₱<?= number_format($shift1_total, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Shift 2 -->
                <div class="shift-box">
                    <h3>SHIFT 2 (2PM - 10PM)</h3>
                    <table>
                        <tbody>
                            <tr>
                                <td><strong>Total Job Orders:</strong></td>
                                <td class="text-right font-bold"><?= $shift2_count ?></td>
                            </tr>
                            <tr>
                                <td><strong>Total Amount:</strong></td>
                                <td class="text-right font-bold">₱<?= number_format($shift2_total, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Overall Summary -->
            <div class="section-title">
                OVERALL DAILY SUMMARY
            </div>
            <div class="shift-box" style="max-width: 600px; margin: 0 auto;">
                <table>
                    <tbody>
                        <tr>
                            <td><strong>Total Job Orders:</strong></td>
                            <td class="text-right font-bold"><?= ($shift1_count + $shift2_count) ?></td>
                        </tr>
                        <tr>
                            <td class="font-bold" style="font-size: 14px;">TOTAL REVENUE:</td>
                            <td class="text-right font-bold" style="font-size: 18px;">₱<?= number_format($shift1_total + $shift2_total, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<script>
    function applyFilters() {
        const dateStart = document.getElementById('date_start').value;
        const dateEnd = document.getElementById('date_end').value;
        window.location.href = `?date_start=${dateStart}&date_end=${dateEnd}`;
    }
    
    // Allow Enter key to apply filters
    document.getElementById('date_start').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
    
    document.getElementById('date_end').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
</script>

<?php
// Include system footer
require_once __DIR__ . '/../partials/footer.php';
?>
