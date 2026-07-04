<?php
/**
 * STAFF DELIVERIES REPORT
 * Fuel Deliveries & Merchandise Deliveries in separate tabs
 * Plain black & white design, no colors or icons
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

function column_exists($pdo, $table, $column) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Check available tables
$has_fuel_deliveries = table_exists($pdo, 'fuel_deliveries');
$has_merchandise_deliveries = table_exists($pdo, 'merchandise_deliveries');
$has_deliveries_oversight = table_exists($pdo, 'deliveries_oversight');
$has_suppliers = table_exists($pdo, 'suppliers');
$has_fuel_types = table_exists($pdo, 'fuel_types');
$has_products = table_exists($pdo, 'products');
$has_purchase_orders = table_exists($pdo, 'purchase_orders');

// Initialize data
$fuel_deliveries = [];
$merchandise_deliveries = [];
$fuel_shift1_total = 0;
$fuel_shift2_total = 0;
$merch_shift1_total = 0;
$merch_shift2_total = 0;

// ============================================================
// FETCH FUEL DELIVERIES
// ============================================================
if ($has_fuel_deliveries) {
    try {
        $sql = "SELECT 
                    fd.id,
                    fd.batch_id AS delivery_id,
                    fd.supplier AS supplier,
                    fd.fuel_type AS fuel_type,
                    fd.delivery_liters AS quantity,
                    0 AS unit_price,
                    0 AS total_amount,
                    fd.delivery_date,
                    COALESCE(fd.invoice_no, '—') AS po_reference,
                    0 AS expected_quantity,
                    fd.delivery_liters AS actual_quantity,
                    0 AS variance,
                    fd.status,
                    CASE WHEN HOUR(fd.created_at) >= 6 AND HOUR(fd.created_at) < 14 THEN 'Shift 1' ELSE 'Shift 2' END AS shift,
                    COALESCE(fd.notes, '—') AS remarks,
                    COALESCE(u.username, COALESCE(fd.received_by, '—')) AS encoder,
                    fd.created_at
            FROM fuel_deliveries fd
            LEFT JOIN users u ON fd.received_by = u.id
            WHERE fd.station_id = ? 
                 AND DATE(fd.delivery_date) BETWEEN ? AND ?
                 ORDER BY fd.delivery_date DESC, fd.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $date_start, $date_end]);
        $fuel_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate shift totals
        foreach ($fuel_deliveries as $delivery) {
            $shift = strtolower($delivery['shift'] ?? '');
            $amount = (float)$delivery['total_amount'];
            
            if (strpos($shift, 'shift 1') !== false || strpos($shift, '1') !== false) {
                $fuel_shift1_total += $amount;
            } else {
                $fuel_shift2_total += $amount;
            }
        }
        
    } catch (Exception $e) {
        $error_message = "Error fetching fuel deliveries: " . $e->getMessage();
    }
}

// ============================================================
// FETCH MERCHANDISE DELIVERIES
// ============================================================
if ($has_deliveries_oversight || $has_merchandise_deliveries) {
    try {
        if ($has_deliveries_oversight) {
        $sql = "SELECT 
                    md.id,
                    COALESCE(md.batch_id, '—') AS delivery_id,
                    md.supplier AS supplier,
                    md.product AS product_name,
                    md.quantity,
                    COALESCE(md.unit_price, 0) AS unit_price,
                    COALESCE(md.payable_amount, md.expected_amount, 0) AS total_amount,
                    md.delivery_date,
                    COALESCE(md.delivery_ref, '—') AS po_reference,
                    COALESCE(md.expected_quantity, md.quantity, 0) AS expected_quantity,
                    COALESCE(md.actual_quantity, md.quantity, 0) AS actual_quantity,
                    0 AS variance,
                    md.status,
                    'Shift 1' AS shift,
                    COALESCE(md.remarks, '—') AS remarks,
                    COALESCE(u.username, '—') AS encoder,
                    md.created_at
            FROM deliveries_oversight md
            LEFT JOIN users u ON md.encoded_by = u.id
            WHERE md.station_id = ? AND md.delivery_type = 'merchandise'
                 AND DATE(md.delivery_date) BETWEEN ? AND ?
                 ORDER BY md.delivery_date DESC, md.created_at DESC";
        } else {
            $productSelect = $has_products ? "COALESCE(p.name, CONCAT('Product #', md.product_id))" : "CONCAT('Product #', md.product_id)";
            $supplierSelect = $has_suppliers ? "COALESCE(s.name, CONCAT('Supplier #', md.supplier_id), 'N/A')" : "COALESCE(CONCAT('Supplier #', md.supplier_id), 'N/A')";
            $unitPriceSelect = $has_purchase_orders
                ? ($has_products ? "COALESCE(po.unit_price, p.cost, p.price, 0)" : "COALESCE(po.unit_price, 0)")
                : ($has_products ? "COALESCE(p.cost, p.price, 0)" : "0");
            $totalAmountSelect = $has_purchase_orders
                ? "COALESCE(po.total_amount, md.quantity * {$unitPriceSelect}, 0)"
                : "COALESCE(md.quantity * {$unitPriceSelect}, 0)";
            $poReferenceSelect = $has_purchase_orders
                ? "COALESCE(po.po_number, CONCAT('PO #', md.po_id), 'N/A')"
                : "COALESCE(CONCAT('PO #', md.po_id), 'N/A')";

            $sql = "SELECT
                        md.id,
                        CONCAT('MD-', md.id) AS delivery_id,
                        {$supplierSelect} AS supplier,
                        {$productSelect} AS product_name,
                        md.quantity,
                        {$unitPriceSelect} AS unit_price,
                        {$totalAmountSelect} AS total_amount,
                        md.delivery_date,
                        {$poReferenceSelect} AS po_reference,
                        md.quantity AS expected_quantity,
                        md.quantity AS actual_quantity,
                        0 AS variance,
                        md.status,
                        CASE WHEN HOUR(COALESCE(md.created_at, md.delivery_date)) >= 6 AND HOUR(COALESCE(md.created_at, md.delivery_date)) < 14 THEN 'Shift 1' ELSE 'Shift 2' END AS shift,
                        COALESCE(md.remarks, md.manager_reason, md.notes, 'N/A') AS remarks,
                        COALESCE(u.username, 'N/A') AS encoder,
                        md.created_at
                FROM merchandise_deliveries md
                LEFT JOIN users u ON md.encoded_by = u.id ";

            if ($has_products) {
                $sql .= "LEFT JOIN products p ON p.id = md.product_id ";
            }
            if ($has_suppliers) {
                $sql .= "LEFT JOIN suppliers s ON s.id = md.supplier_id ";
            }
            if ($has_purchase_orders) {
                $sql .= "LEFT JOIN purchase_orders po ON po.id = md.po_id ";
            }

            $sql .= "WHERE md.station_id = ?
                     AND DATE(md.delivery_date) BETWEEN ? AND ?
                     ORDER BY md.delivery_date DESC, md.created_at DESC";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $date_start, $date_end]);
        $merchandise_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate shift totals
        foreach ($merchandise_deliveries as $delivery) {
            $shift = strtolower($delivery['shift'] ?? '');
            $amount = (float)$delivery['total_amount'];
            
            if (strpos($shift, 'shift 1') !== false || strpos($shift, '1') !== false) {
                $merch_shift1_total += $amount;
            } else {
                $merch_shift2_total += $amount;
            }
        }
        
    } catch (Exception $e) {
        $error_message = "Error fetching merchandise deliveries: " . $e->getMessage();
    }
}

// ============================================================
// EXCEL EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="Deliveries_Report_' . $date_start . '_to_' . $date_end . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<!--[if gte mso 9]>';
    echo '<xml>';
    echo '<x:ExcelWorkbook>';
    echo '<x:ExcelWorksheets>';
    echo '<x:ExcelWorksheet>';
    echo '<x:Name>Deliveries Report</x:Name>';
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
    echo '<h1>DELIVERIES REPORT</h1>';
    echo '<p><strong>Station:</strong> ' . htmlspecialchars($station_name) . '</p>';
    echo '<p><strong>Period:</strong> ' . date('F d, Y', strtotime($date_start)) . ' - ' . date('F d, Y', strtotime($date_end)) . '</p>';
    echo '<br/>';
    
    // FUEL DELIVERIES
    echo '<h2>FUEL DELIVERIES</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Delivery ID</th>';
    echo '<th>Supplier</th>';
    echo '<th>Fuel Type</th>';
    echo '<th>Quantity (L)</th>';
    echo '<th>Unit Price</th>';
    echo '<th>Total Amount</th>';
    echo '<th>Date</th>';
    echo '<th>PO Reference</th>';
    echo '<th>Expected</th>';
    echo '<th>Actual</th>';
    echo '<th>Variance</th>';
    echo '<th>Status</th>';
    echo '<th>Shift</th>';
    echo '<th>Encoder</th>';
    echo '<th>Remarks</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($fuel_deliveries) > 0) {
        foreach ($fuel_deliveries as $delivery) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($delivery['delivery_id']) . '</td>';
            echo '<td>' . htmlspecialchars($delivery['supplier']) . '</td>';
            echo '<td>' . htmlspecialchars($delivery['fuel_type']) . '</td>';
            echo '<td class="text-right">' . number_format($delivery['quantity'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($delivery['unit_price'], 2) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($delivery['total_amount'], 2) . '</td>';
            echo '<td>' . date('m/d/Y', strtotime($delivery['delivery_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($delivery['po_reference'] ?? '—') . '</td>';
            echo '<td class="text-right">' . number_format($delivery['expected_quantity'] ?? 0, 2) . '</td>';
            echo '<td class="text-right">' . number_format($delivery['actual_quantity'] ?? 0, 2) . '</td>';
            echo '<td class="text-right">' . number_format($delivery['variance'] ?? 0, 2) . '</td>';
            echo '<td class="text-center">' . strtoupper($delivery['status'] ?? 'PENDING') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['shift'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['encoder'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['remarks'] ?? '—') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="15" style="text-align: center; padding: 20px;">No fuel deliveries found for this period.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // Fuel Shift Summary
    echo '<h3>FUEL DELIVERIES - SHIFT SUMMARY</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Shift</th>';
    echo '<th>Total Deliveries</th>';
    echo '<th>Total Amount</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    $shift1_count = count(array_filter($fuel_deliveries, function($d) {
        $shift = strtolower($d['shift'] ?? '');
        return strpos($shift, 'shift 1') !== false || strpos($shift, '1') !== false;
    }));
    $shift2_count = count(array_filter($fuel_deliveries, function($d) {
        $shift = strtolower($d['shift'] ?? '');
        return strpos($shift, 'shift 2') !== false || strpos($shift, '2') !== false;
    }));
    echo '<tr>';
    echo '<td><strong>Shift 1 (6AM - 2PM)</strong></td>';
    echo '<td class="text-center">' . $shift1_count . '</td>';
    echo '<td class="text-right font-bold">₱' . number_format($fuel_shift1_total, 2) . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td><strong>Shift 2 (2PM - 10PM)</strong></td>';
    echo '<td class="text-center">' . $shift2_count . '</td>';
    echo '<td class="text-right font-bold">₱' . number_format($fuel_shift2_total, 2) . '</td>';
    echo '</tr>';
    echo '</tbody>';
    echo '</table>';
    echo '<br/><br/>';
    
    // MERCHANDISE DELIVERIES
    echo '<h2>MERCHANDISE DELIVERIES</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Delivery ID</th>';
    echo '<th>Supplier</th>';
    echo '<th>Product Name</th>';
    echo '<th>Quantity</th>';
    echo '<th>Unit Price</th>';
    echo '<th>Total Amount</th>';
    echo '<th>Date</th>';
    echo '<th>PO Reference</th>';
    echo '<th>Expected</th>';
    echo '<th>Actual</th>';
    echo '<th>Variance</th>';
    echo '<th>Status</th>';
    echo '<th>Shift</th>';
    echo '<th>Encoder</th>';
    echo '<th>Remarks</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($merchandise_deliveries) > 0) {
        foreach ($merchandise_deliveries as $delivery) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($delivery['delivery_id']) . '</td>';
            echo '<td>' . htmlspecialchars($delivery['supplier']) . '</td>';
            echo '<td>' . htmlspecialchars($delivery['product_name']) . '</td>';
            echo '<td class="text-right">' . number_format($delivery['quantity'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($delivery['unit_price'], 2) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($delivery['total_amount'], 2) . '</td>';
            echo '<td>' . date('m/d/Y', strtotime($delivery['delivery_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($delivery['po_reference'] ?? '—') . '</td>';
            echo '<td class="text-right">' . number_format($delivery['expected_quantity'] ?? 0, 2) . '</td>';
            echo '<td class="text-right">' . number_format($delivery['actual_quantity'] ?? 0, 2) . '</td>';
            echo '<td class="text-right">' . number_format($delivery['variance'] ?? 0, 2) . '</td>';
            echo '<td class="text-center">' . strtoupper($delivery['status'] ?? 'PENDING') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['shift'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['encoder'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['remarks'] ?? '—') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="15" style="text-align: center; padding: 20px;">No merchandise deliveries found for this period.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // Merchandise Shift Summary
    echo '<h3>MERCHANDISE DELIVERIES - SHIFT SUMMARY</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Shift</th>';
    echo '<th>Total Deliveries</th>';
    echo '<th>Total Amount</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    $merch_shift1_count = count(array_filter($merchandise_deliveries, function($d) {
        $shift = strtolower($d['shift'] ?? '');
        return strpos($shift, 'shift 1') !== false || strpos($shift, '1') !== false;
    }));
    $merch_shift2_count = count(array_filter($merchandise_deliveries, function($d) {
        $shift = strtolower($d['shift'] ?? '');
        return strpos($shift, 'shift 2') !== false || strpos($shift, '2') !== false;
    }));
    echo '<tr>';
    echo '<td><strong>Shift 1 (6AM - 2PM)</strong></td>';
    echo '<td class="text-center">' . $merch_shift1_count . '</td>';
    echo '<td class="text-right font-bold">₱' . number_format($merch_shift1_total, 2) . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td><strong>Shift 2 (2PM - 10PM)</strong></td>';
    echo '<td class="text-center">' . $merch_shift2_count . '</td>';
    echo '<td class="text-right font-bold">₱' . number_format($merch_shift2_total, 2) . '</td>';
    echo '</tr>';
    echo '</tbody>';
    echo '</table>';
    
    echo '</body>';
    echo '</html>';
    exit;
}

$page_title = "Deliveries Report";

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
    
    /* == PAGE HEADER - matches Transaction module int-head standard == */
    .int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:0; padding:14px 20px 12px; border-bottom:1px solid #e0e0e0; background:#fff; }
    .int-head h1 { font-size:20px; font-weight:700; color:#00264D; margin:0; text-transform:uppercase; display:flex; align-items:center; gap:8px; }
    .int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none; }
    .int-head .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    
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
    
    /* Export Buttons (Filter Button Style) */
    .flt-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid transparent;
        transition: all 0.15s;
        height: 34px;
        line-height: 1;
        white-space: nowrap;
        text-decoration: none;
        background: white !important;
    }
    .flt-btn-search { color: #002F70 !important; border-color: #002F70 !important; }
    .flt-btn-search:hover { background: #002F70 !important; color: #fff !important; }
    .flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
    .flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
    .flt-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
    .flt-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
    .flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
    .flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }
    .flt-btn-csv    { color: #002F70 !important; border-color: #002F70 !important; }
    .flt-btn-csv:hover    { background: #002F70 !important; color: #fff !important; }
    
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
    table th:nth-child(1), table td:nth-child(1) { width: 6%; }  /* Delivery ID */
    table th:nth-child(2), table td:nth-child(2) { width: 8%; }  /* Supplier */
    table th:nth-child(3), table td:nth-child(3) { width: 8%; }  /* Fuel/Product */
    table th:nth-child(4), table td:nth-child(4) { width: 5%; }  /* Qty */
    table th:nth-child(5), table td:nth-child(5) { width: 6%; }  /* Unit Price */
    table th:nth-child(6), table td:nth-child(6) { width: 7%; }  /* Total */
    table th:nth-child(7), table td:nth-child(7) { width: 7%; }  /* Date */
    table th:nth-child(8), table td:nth-child(8) { width: 6%; }  /* PO Ref */
    table th:nth-child(9), table td:nth-child(9) { width: 5%; }  /* Expected */
    table th:nth-child(10), table td:nth-child(10) { width: 5%; } /* Actual */
    table th:nth-child(11), table td:nth-child(11) { width: 5%; } /* Variance */
    table th:nth-child(12), table td:nth-child(12) { width: 6%; } /* Status */
    table th:nth-child(13), table td:nth-child(13) { width: 5%; } /* Shift */
    table th:nth-child(14), table td:nth-child(14) { width: 7%; } /* Encoder */
    table th:nth-child(15), table td:nth-child(15) { width: 14%; } /* Remarks */
    
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
    
    .remarks-section {
        margin-top: 20px;
        padding: 15px;
        border: 1px solid #000;
        background: #fff;
    }
    
    .remarks-section h3 {
        font-size: 14px;
        color: #000;
        margin: 0 0 10px 0;
        font-weight: 700;
        border-bottom: 1px solid #000;
        padding-bottom: 8px;
        text-transform: uppercase;
    }
    
    .remarks-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .remarks-list li {
        padding: 8px;
        border-bottom: 1px solid #ddd;
        font-size: 11px;
    }
    
    .remarks-list li:last-child {
        border-bottom: none;
    }
    
    .status-badge {
        padding: 3px 6px;
        border: 1px solid #000;
        font-size: 9px;
        font-weight: 700;
        display: inline-block;
    }
    
    @media print {
        @page {
            size: legal portrait;
            margin: 0.5in 0.4in;
        }

        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
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
        .header h1 { font-size: 16px !important; font-weight: 700 !important; color: #000 !important; margin: 0 0 3px 0 !important; text-transform: uppercase !important; }
        .header p { font-size: 10px !important; color: #000 !important; margin: 2px 0 !important; }

        .section-title { font-size: 12px !important; font-weight: 700 !important; margin: 10px 0 4px 0 !important; padding-bottom: 3px !important; border-bottom: 2px solid #000 !important; text-transform: uppercase !important; color: #000 !important; page-break-after: avoid !important; }

        .table-container { overflow: visible !important; margin-bottom: 6px !important; width: 100% !important; text-align: center !important; }

        table { width: 95% !important; max-width: 100% !important; border-collapse: collapse !important; font-size: 10px !important; table-layout: auto !important; margin: 0 auto 8px auto !important; }
        thead { display: table-header-group !important; }
        tbody { display: table-row-group !important; }
        tr { page-break-inside: avoid !important; }
        th { font-size: 10px !important; padding: 6px 8px !important; border: 1px solid #000 !important; background: #fff !important; color: #000 !important; font-weight: 700 !important; text-align: center !important; white-space: nowrap !important; }
        td { font-size: 9px !important; padding: 5px 8px !important; border: 1px solid #000 !important; white-space: nowrap !important; vertical-align: top !important; }

        .shift-summary { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 6px !important; margin: 6px 0 !important; page-break-inside: avoid !important; }
        .shift-box { border: 1px solid #000 !important; padding: 5px !important; page-break-inside: avoid !important; }
        .shift-box h3 { font-size: 10px !important; font-weight: 700 !important; border-bottom: 1px solid #000 !important; padding-bottom: 2px !important; margin: 0 0 4px 0 !important; color: #000 !important; }
        .shift-box table { width: auto !important; margin: 0 !important; }
        .shift-box td { padding: 3px !important; font-size: 9px !important; border: none !important; border-bottom: 1px solid #ddd !important; }

        .remarks-section { border: 1px solid #000 !important; padding: 5px !important; margin-top: 6px !important; page-break-inside: avoid !important; }
        .remarks-section h3 { font-size: 8px !important; font-weight: 700 !important; border-bottom: 1px solid #000 !important; padding-bottom: 2px !important; margin: 0 0 4px 0 !important; }
            color: #000 !important;
        }

        .remarks-list li {
            font-size: 7px !important;
            padding: 2px !important;
            border-bottom: 1px solid #eee !important;
        }

        .status-badge {
            font-size: 5px !important;
            padding: 1px 2px !important;
            border: 1px solid #000 !important;
        }
    }
</style>

<!-- Module Page Header - matches Transaction module int-head standard -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-truck"></i> Deliveries</h1>
        <div class="sub">View and track merchandise and fuel delivery records for this station.</div>
    </div>
</div>

<!-- Main Content Wrapper -->
<div class="main-content">
    <!-- Controls Section (Not Printed) -->
    <div class="controls">
        <div class="date-controls">
            <label>Date Range:</label>
            <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>">
            <span>to</span>
            <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>">
            <button class="btn btn-primary" onclick="applyFilters()">Apply</button>
        </div>
        
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <!-- Excel -->
            <a href="?date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>&export=excel" 
               class="flt-btn flt-btn-excel" title="Export to Excel">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <!-- CSV -->
            <button onclick="exportTableToCSV('deliveriesTable','deliveries_report_<?= date('Ymd') ?>.csv')"
                    class="flt-btn flt-btn-csv" title="Export to CSV">
                <i class="fas fa-file-csv"></i> CSV
            </button>
            <!-- PDF -->
            <button onclick="window.print()" class="flt-btn flt-btn-pdf" title="Print / Export PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
        </div>
    </div>
    
    <!-- Printable Document Area -->
    <div class="print-area">
    <div class="container">
        <div class="header">
            <h1>DELIVERIES REPORT</h1>
            <p><?= htmlspecialchars($station_name) ?></p>
            <p>Period: <?= date('F d, Y', strtotime($date_start)) ?> - <?= date('F d, Y', strtotime($date_end)) ?></p>
        </div>
        
        <div class="content">
            <!-- FUEL DELIVERIES SECTION -->
            <div class="section-title">FUEL DELIVERIES</div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>DEL ID</th>
                                <th>SUPPLIER</th>
                                <th>FUEL TYPE</th>
                                <th class="text-right">QTY (L)</th>
                                <th class="text-right">PRICE</th>
                                <th class="text-right">TOTAL</th>
                                <th>DATE</th>
                                <th>PO REF</th>
                                <th class="text-right">EXP</th>
                                <th class="text-right">ACT</th>
                                <th class="text-right">VAR</th>
                                <th>STATUS</th>
                                <th>SHIFT</th>
                                <th>ENCODER</th>
                                <th>REMARKS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (count($fuel_deliveries) > 0):
                                foreach ($fuel_deliveries as $delivery): 
                            ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($delivery['delivery_id']) ?></td>
                                <td><?= htmlspecialchars($delivery['supplier']) ?></td>
                                <td><?= htmlspecialchars($delivery['fuel_type']) ?></td>
                                <td class="text-right"><?= number_format($delivery['quantity'], 1) ?></td>
                                <td class="text-right">₱<?= number_format($delivery['unit_price'], 2) ?></td>
                                <td class="text-right font-bold">₱<?= number_format($delivery['total_amount'], 2) ?></td>
                                <td><?= date('m/d/y', strtotime($delivery['delivery_date'])) ?></td>
                                <td><?= htmlspecialchars($delivery['po_reference'] ?? '—') ?></td>
                                <td class="text-right"><?= number_format($delivery['expected_quantity'] ?? 0, 1) ?></td>
                                <td class="text-right"><?= number_format($delivery['actual_quantity'] ?? 0, 1) ?></td>
                                <td class="text-right"><?= number_format($delivery['variance'] ?? 0, 1) ?></td>
                                <td><span class="status-badge"><?= strtoupper($delivery['status'] ?? 'PENDING') ?></span></td>
                                <td><?= htmlspecialchars($delivery['shift'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($delivery['encoder'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($delivery['remarks'] ?? '—') ?></td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="15" style="text-align: center; padding: 40px;">
                                    No fuel deliveries found for this period.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Shift Summary -->
                <div class="section-title">SHIFT SUMMARY & REMARKS</div>
                <div class="shift-summary">
                    <div class="shift-box">
                        <h3>SHIFT 1 (6AM - 2PM)</h3>
                        <table>
                            <tr>
                                <td style="width: 70%;">Total Deliveries:</td>
                                <td class="text-right font-bold">
                                    <?php
                                    $shift1_count = count(array_filter($fuel_deliveries, function($d) {
                                        $shift = strtolower($d['shift'] ?? '');
                                        return strpos($shift, 'shift 1') !== false || strpos($shift, '1') !== false;
                                    }));
                                    echo $shift1_count;
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Total Amount:</td>
                                <td class="text-right font-bold">₱<?= number_format($fuel_shift1_total, 2) ?></td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="shift-box">
                        <h3>SHIFT 2 (2PM - 10PM)</h3>
                        <table>
                            <tr>
                                <td style="width: 70%;">Total Deliveries:</td>
                                <td class="text-right font-bold">
                                    <?php
                                    $shift2_count = count(array_filter($fuel_deliveries, function($d) {
                                        $shift = strtolower($d['shift'] ?? '');
                                        return strpos($shift, 'shift 2') !== false || strpos($shift, '2') !== false;
                                    }));
                                    echo $shift2_count;
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Total Amount:</td>
                                <td class="text-right font-bold">₱<?= number_format($fuel_shift2_total, 2) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Remarks Section -->
                <div class="remarks-section">
                    <h3>DELIVERY REMARKS & ISSUES</h3>
                    <ul class="remarks-list">
                        <?php
                        $has_remarks = false;
                        foreach ($fuel_deliveries as $delivery) {
                            if (!empty($delivery['remarks']) && $delivery['remarks'] !== '—') {
                                $has_remarks = true;
                                echo '<li>';
                                echo '<strong>' . htmlspecialchars($delivery['delivery_id']) . '</strong> - ';
                                echo htmlspecialchars($delivery['remarks']);
                                echo ' <small>(' . date('M d, Y', strtotime($delivery['delivery_date'])) . ')</small>';
                                echo '</li>';
                            }
                        }
                        if (!$has_remarks) {
                            echo '<li>No remarks or issues reported for this period.</li>';
                        }
                        ?>
                    </ul>
                </div>
                
            <!-- MERCHANDISE DELIVERIES SECTION -->
            <div class="section-title" style="margin-top: 50px;">MERCHANDISE DELIVERIES</div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>DEL ID</th>
                                <th>SUPPLIER</th>
                                <th>PRODUCT</th>
                                <th class="text-right">QTY</th>
                                <th class="text-right">PRICE</th>
                                <th class="text-right">TOTAL</th>
                                <th>DATE</th>
                                <th>PO REF</th>
                                <th class="text-right">EXP</th>
                                <th class="text-right">ACT</th>
                                <th class="text-right">VAR</th>
                                <th>STATUS</th>
                                <th>SHIFT</th>
                                <th>ENCODER</th>
                                <th>REMARKS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (count($merchandise_deliveries) > 0):
                                foreach ($merchandise_deliveries as $delivery): 
                            ?>
                            <tr>
                                <td class="font-bold"><?= htmlspecialchars($delivery['delivery_id']) ?></td>
                                <td><?= htmlspecialchars($delivery['supplier']) ?></td>
                                <td><?= htmlspecialchars($delivery['product_name']) ?></td>
                                <td class="text-right"><?= number_format($delivery['quantity'], 1) ?></td>
                                <td class="text-right">₱<?= number_format($delivery['unit_price'], 2) ?></td>
                                <td class="text-right font-bold">₱<?= number_format($delivery['total_amount'], 2) ?></td>
                                <td><?= date('m/d/y', strtotime($delivery['delivery_date'])) ?></td>
                                <td><?= htmlspecialchars($delivery['po_reference'] ?? '—') ?></td>
                                <td class="text-right"><?= number_format($delivery['expected_quantity'] ?? 0, 1) ?></td>
                                <td class="text-right"><?= number_format($delivery['actual_quantity'] ?? 0, 1) ?></td>
                                <td class="text-right"><?= number_format($delivery['variance'] ?? 0, 1) ?></td>
                                <td><span class="status-badge"><?= strtoupper($delivery['status'] ?? 'PENDING') ?></span></td>
                                <td><?= htmlspecialchars($delivery['shift'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($delivery['encoder'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($delivery['remarks'] ?? '—') ?></td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="15" style="text-align: center; padding: 40px;">
                                    No merchandise deliveries found for this period.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Shift Summary -->
                <div class="section-title">SHIFT SUMMARY & REMARKS</div>
                <div class="shift-summary">
                    <div class="shift-box">
                        <h3>SHIFT 1 (6AM - 2PM)</h3>
                        <table>
                            <tr>
                                <td style="width: 70%;">Total Deliveries:</td>
                                <td class="text-right font-bold">
                                    <?php
                                    $shift1_count = count(array_filter($merchandise_deliveries, function($d) {
                                        $shift = strtolower($d['shift'] ?? '');
                                        return strpos($shift, 'shift 1') !== false || strpos($shift, '1') !== false;
                                    }));
                                    echo $shift1_count;
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Total Amount:</td>
                                <td class="text-right font-bold">₱<?= number_format($merch_shift1_total, 2) ?></td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="shift-box">
                        <h3>SHIFT 2 (2PM - 10PM)</h3>
                        <table>
                            <tr>
                                <td style="width: 70%;">Total Deliveries:</td>
                                <td class="text-right font-bold">
                                    <?php
                                    $shift2_count = count(array_filter($merchandise_deliveries, function($d) {
                                        $shift = strtolower($d['shift'] ?? '');
                                        return strpos($shift, 'shift 2') !== false || strpos($shift, '2') !== false;
                                    }));
                                    echo $shift2_count;
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Total Amount:</td>
                                <td class="text-right font-bold">₱<?= number_format($merch_shift2_total, 2) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Remarks Section -->
                <div class="remarks-section">
                    <h3>DELIVERY REMARKS & ISSUES</h3>
                    <ul class="remarks-list">
                        <?php
                        $has_remarks = false;
                        foreach ($merchandise_deliveries as $delivery) {
                            if (!empty($delivery['remarks']) && $delivery['remarks'] !== '—') {
                                $has_remarks = true;
                                echo '<li>';
                                echo '<strong>' . htmlspecialchars($delivery['delivery_id']) . '</strong> - ';
                                echo htmlspecialchars($delivery['remarks']);
                                echo ' <small>(' . date('M d, Y', strtotime($delivery['delivery_date'])) . ')</small>';
                                echo '</li>';
                            }
                        }
                        if (!$has_remarks) {
                            echo '<li>No remarks or issues reported for this period.</li>';
                        }
                        ?>
                    </ul>
                </div>
            
        </div>
    </div>
    </div><!-- End print-area -->
    
    <script>
        function applyFilters() {
            const dateStart = document.getElementById('date_start').value;
            const dateEnd = document.getElementById('date_end').value;
            window.location.href = `?date_start=${dateStart}&date_end=${dateEnd}`;
        }
    </script>
</div><!-- End main-content -->

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
