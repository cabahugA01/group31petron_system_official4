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
$has_suppliers = table_exists($pdo, 'suppliers');
$has_fuel_types = table_exists($pdo, 'fuel_types');
$has_products = table_exists($pdo, 'products');

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
                    fd.delivery_id,
                    ";
        
        if ($has_suppliers) {
            $sql .= "COALESCE(s.name, fd.supplier) AS supplier, ";
        } else {
            $sql .= "fd.supplier, ";
        }
        
        if ($has_fuel_types) {
            $sql .= "COALESCE(ft.name, fd.fuel_type) AS fuel_type, ";
        } else {
            $sql .= "fd.fuel_type, ";
        }
        
        $sql .= "fd.quantity,
                 fd.unit_price,
                 fd.total_amount,
                 fd.delivery_date,
                 fd.po_reference,
                 fd.expected_quantity,
                 fd.actual_quantity,
                 fd.variance,
                 fd.status,
                 fd.shift,
                 fd.remarks,
                 fd.encoder,
                 fd.created_at
            FROM fuel_deliveries fd ";
        
        if ($has_suppliers) {
            $sql .= "LEFT JOIN suppliers s ON fd.supplier_id = s.id ";
        }
        
        if ($has_fuel_types) {
            $sql .= "LEFT JOIN fuel_types ft ON fd.fuel_type_id = ft.id ";
        }
        
        $sql .= "WHERE fd.station_id = ? 
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
if ($has_merchandise_deliveries) {
    try {
        $sql = "SELECT 
                    md.id,
                    md.delivery_id,
                    ";
        
        if ($has_suppliers) {
            $sql .= "COALESCE(s.name, md.supplier) AS supplier, ";
        } else {
            $sql .= "md.supplier, ";
        }
        
        if ($has_products) {
            $sql .= "COALESCE(p.name, md.product_name) AS product_name, ";
        } else {
            $sql .= "md.product_name, ";
        }
        
        $sql .= "md.quantity,
                 md.unit_price,
                 md.total_amount,
                 md.delivery_date,
                 md.po_reference,
                 md.expected_quantity,
                 md.actual_quantity,
                 md.variance,
                 md.status,
                 md.shift,
                 md.remarks,
                 md.encoder,
                 md.created_at
            FROM merchandise_deliveries md ";
        
        if ($has_suppliers) {
            $sql .= "LEFT JOIN suppliers s ON md.supplier_id = s.id ";
        }
        
        if ($has_products) {
            $sql .= "LEFT JOIN products p ON md.product_id = p.id ";
        }
        
        $sql .= "WHERE md.station_id = ? 
                 AND DATE(md.delivery_date) BETWEEN ? AND ?
                 ORDER BY md.delivery_date DESC, md.created_at DESC";
        
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
// CSV EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment;filename="Deliveries_Report_' . $date_start . '_to_' . $date_end . '.csv"');
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['DELIVERIES REPORT']);
    fputcsv($out, ['Station', $station_name]);
    fputcsv($out, ['Period', date('F d, Y', strtotime($date_start)) . ' - ' . date('F d, Y', strtotime($date_end))]);
    fputcsv($out, []);

    $headers = ['Delivery ID', 'Supplier', 'Item', 'Quantity', 'Unit Price', 'Total Amount', 'Date', 'PO Reference', 'Expected', 'Actual', 'Variance', 'Status', 'Shift', 'Encoder', 'Remarks'];

    fputcsv($out, ['FUEL DELIVERIES']);
    fputcsv($out, $headers);
    foreach ($fuel_deliveries as $delivery) {
        fputcsv($out, [
            $delivery['delivery_id'] ?? '',
            $delivery['supplier'] ?? '',
            $delivery['fuel_type'] ?? '',
            number_format((float)($delivery['quantity'] ?? 0), 2),
            'PHP ' . number_format((float)($delivery['unit_price'] ?? 0), 2),
            'PHP ' . number_format((float)($delivery['total_amount'] ?? 0), 2),
            !empty($delivery['delivery_date']) ? date('m/d/Y', strtotime($delivery['delivery_date'])) : '',
            $delivery['po_reference'] ?? '',
            number_format((float)($delivery['expected_quantity'] ?? 0), 2),
            number_format((float)($delivery['actual_quantity'] ?? 0), 2),
            number_format((float)($delivery['variance'] ?? 0), 2),
            strtoupper($delivery['status'] ?? 'PENDING'),
            $delivery['shift'] ?? 'N/A',
            $delivery['encoder'] ?? 'N/A',
            $delivery['remarks'] ?? '',
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['MERCHANDISE DELIVERIES']);
    fputcsv($out, $headers);
    foreach ($merchandise_deliveries as $delivery) {
        fputcsv($out, [
            $delivery['delivery_id'] ?? '',
            $delivery['supplier'] ?? '',
            $delivery['product_name'] ?? '',
            number_format((float)($delivery['quantity'] ?? 0), 2),
            'PHP ' . number_format((float)($delivery['unit_price'] ?? 0), 2),
            'PHP ' . number_format((float)($delivery['total_amount'] ?? 0), 2),
            !empty($delivery['delivery_date']) ? date('m/d/Y', strtotime($delivery['delivery_date'])) : '',
            $delivery['po_reference'] ?? '',
            number_format((float)($delivery['expected_quantity'] ?? 0), 2),
            number_format((float)($delivery['actual_quantity'] ?? 0), 2),
            number_format((float)($delivery['variance'] ?? 0), 2),
            strtoupper($delivery['status'] ?? 'PENDING'),
            $delivery['shift'] ?? 'N/A',
            $delivery['encoder'] ?? 'N/A',
            $delivery['remarks'] ?? '',
        ]);
    }
    fclose($out);
    exit;
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
        @page { size: A4 portrait; margin: 10mm 12mm; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-shadow: none !important; text-shadow: none !important; background-image: none !important; }
        html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; overflow: visible !important; height: auto !important; font-size: 10px !important; }

        /* Hide all page chrome — keep only sfss-print-only */
        body > *:not(.sfss-print-only) { display: none !important; }
        nav, header, footer, aside, .sidebar, .main-sidebar, .main-header, .navbar, .topbar,
        .controls, #toggleScrollBtn, .toggle-scroll-btn, .toast, .toast-container { display: none !important; }

        /* Print container */
        .sfss-print-only {
            display: block !important; position: static !important;
            width: 100% !important; max-width: 100% !important;
            margin: 0 !important; padding: 0 !important;
            background: #fff !important; font-size: 10px !important; color: #333 !important;
        }
        .sfss-print-only *, .sfss-print-only *::before, .sfss-print-only *::after { box-shadow: none !important; text-shadow: none !important; }

        /* Hide icons inside print container */
        .sfss-print-only i, .sfss-print-only svg,
        .sfss-print-only .fas, .sfss-print-only .far, .sfss-print-only .fab, .sfss-print-only .fa,
        .sfss-print-only [class*="fa-"] { display: none !important; width: 0 !important; height: 0 !important; font-size: 0 !important; margin: 0 !important; padding: 0 !important; }

        .sfss-print-only .header { text-align: center !important; border-bottom: 2px solid #000 !important; padding: 6px 0 !important; margin: 0 0 8px 0 !important; }
        .sfss-print-only .header h1 { font-size: 16px !important; font-weight: 700 !important; color: #000 !important; margin: 0 0 3px 0 !important; }
        .sfss-print-only .header p { font-size: 10px !important; color: #000 !important; margin: 2px 0 !important; }
        .sfss-print-only .section-title { font-size: 12px !important; font-weight: 700 !important; margin: 8px 0 4px 0 !important; padding-bottom: 3px !important; border-bottom: 2px solid #000 !important; page-break-after: avoid !important; }
        .sfss-print-only .table-container { overflow: visible !important; width: 100% !important; }
        .sfss-print-only table { width: 100% !important; border-collapse: collapse !important; font-size: 9px !important; margin: 0 0 8px 0 !important; }
        .sfss-print-only thead { display: table-header-group !important; }
        .sfss-print-only tbody { display: table-row-group !important; }
        .sfss-print-only tr { page-break-inside: avoid !important; }
        .sfss-print-only th { font-size: 9px !important; padding: 5px 7px !important; border: 1px solid #000 !important; background: #00264D !important; color: #fff !important; font-weight: 700 !important; text-align: center !important; }
        .sfss-print-only td { font-size: 9px !important; padding: 4px 7px !important; border: 1px solid #ddd !important; vertical-align: top !important; }
        .sfss-print-only .shift-summary { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 6px !important; margin: 6px 0 !important; page-break-inside: avoid !important; }
        .sfss-print-only .shift-box { border: 1px solid #000 !important; padding: 5px !important; }
        .sfss-print-only .shift-box h3 { font-size: 10px !important; border-bottom: 1px solid #000 !important; padding-bottom: 2px !important; margin: 0 0 4px 0 !important; }
        .sfss-print-only .remarks-section { border: 1px solid #000 !important; padding: 5px !important; margin-top: 6px !important; }
        .sfss-print-only .status-badge { display: inline-block !important; padding: 1px 3px !important; border: 1px solid #000 !important; border-radius: 3px !important; font-size: 8px !important; }
        .sfss-print-only, .sfss-print-only * { min-height: 0 !important; height: auto !important; }
        .sfss-print-only .container, .sfss-print-only .content { margin: 0 !important; padding: 0 !important; }
    }
</style>

<!-- Main Content Wrapper -->
<div class="stock-page">
    <!-- Controls Section (Not Printed) -->
    <div class="controls">
        <div class="date-controls">
            <label>Date Range:</label>
            <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>">
            <span>to</span>
            <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>">
            <button class="btn btn-primary" onclick="applyFilters()">Apply</button>
        </div>
        
        <div>
            <a href="?date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>&export=excel" class="btn">Export Excel</a>
            <a href="?date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>&export=csv" class="btn">CSV</a>
            <button type="button" class="btn" onclick="_sfssDoNativePrint(this, 'Export PDF')">Export PDF</button>
            <button type="button" class="btn" onclick="_sfssDoNativePrint()">Print</button>
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

        function _sfssDoNativePrint(btn, label) {
            var old = document.querySelector('.sfss-print-only');
            if (old) old.remove();

            var area = document.querySelector('.print-area');
            if (!area) { window.print(); return; }

            var origTitle = document.title;
            document.title = 'Deliveries Report';

            if (btn && label) {
                var origHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening PDF dialog...';
                btn.disabled = true;
            }

            var printDiv = document.createElement('div');
            printDiv.className     = 'sfss-print-only';
            printDiv.innerHTML     = area.innerHTML;
            printDiv.style.display = 'block';
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
                    if (btn && label) { btn.innerHTML = origHTML; btn.disabled = false; }
                    window.removeEventListener('afterprint', cleanup);
                };
                window.addEventListener('afterprint', cleanup);
                setTimeout(cleanup, 30000);
            }, 150);
        }
    </script>
</div><!-- End stock-page -->

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
