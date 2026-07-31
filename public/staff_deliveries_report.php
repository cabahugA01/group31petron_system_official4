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

// Determine user's assigned shift (for filtering shift summary display)
// Staff only see their own shift box; managers/admins see both
$user_shift_number = 0; // 0 = show both, 1 = shift 1 only, 2 = shift 2 only
if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    try {
        // Try active labor session first
        $ls_stmt = $pdo->prepare("SELECT shift_period, shift_name FROM labor_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
        $ls_stmt->execute([$user_id]);
        $ls = $ls_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ls) {
            // Fall back to most recent session from today
            $ls_stmt2 = $pdo->prepare("SELECT shift_period, shift_name FROM labor_sessions WHERE user_id = ? AND DATE(start_time) = CURDATE() ORDER BY start_time DESC LIMIT 1");
            $ls_stmt2->execute([$user_id]);
            $ls = $ls_stmt2->fetch(PDO::FETCH_ASSOC);
        }
        if ($ls) {
            $sp = strtolower(trim($ls['shift_period'] ?? ''));
            $sn = strtolower(trim($ls['shift_name'] ?? ''));
            $combined = $sp . ' ' . $sn;
            if (strpos($combined, '2') !== false || strpos($combined, 'second') !== false || strpos($combined, 'afternoon') !== false || strpos($combined, 'evening') !== false) {
                $user_shift_number = 2;
            } elseif (strpos($combined, '1') !== false || strpos($combined, 'first') !== false || strpos($combined, 'morning') !== false) {
                $user_shift_number = 1;
            }
        }
    } catch (Exception $e) { $user_shift_number = 0; }

    // User-specific overrides: Yyang is Shift 1, Judy Lastimosa is Shift 2
    $username_lower = isset($me['username']) ? strtolower(trim($me['username'])) : '';
    $first_name_lower = isset($me['first_name']) ? strtolower(trim($me['first_name'])) : '';
    $last_name_lower = isset($me['last_name']) ? strtolower(trim($me['last_name'])) : '';

    if ($username_lower === 'yyang' || $first_name_lower === 'yyang') {
        $user_shift_number = 1;
    } elseif ($username_lower === 'judy' || $first_name_lower === 'judy' || $last_name_lower === 'lastimosa') {
        $user_shift_number = 2;
    }
}

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
                    COALESCE(fd.invoice_no, 'â€”') AS po_reference,
                    0 AS expected_quantity,
                    fd.delivery_liters AS actual_quantity,
                    0 AS variance,
                    fd.status,
                    CASE WHEN HOUR(fd.created_at) >= 6 AND HOUR(fd.created_at) < 14 THEN 'Shift 1' ELSE 'Shift 2' END AS shift,
                    COALESCE(fd.notes, 'â€”') AS remarks,
                    COALESCE(u.username, COALESCE(fd.received_by, 'â€”')) AS encoder,
                    fd.created_at
            FROM fuel_deliveries fd
            LEFT JOIN users u ON fd.received_by = u.id
            WHERE fd.station_id = ? 
                 AND DATE(fd.delivery_date) BETWEEN ? AND ?
                 ORDER BY fd.delivery_date DESC, fd.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $date_start, $date_end]);
        $fuel_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($user_shift_number !== 0) {
            $fuel_deliveries = array_filter($fuel_deliveries, function($delivery) use ($user_shift_number) {
                $shift = strtolower($delivery['shift'] ?? '');
                $is_shift1 = (strpos($shift, 'shift 1') !== false || strpos($shift, '1') !== false);
                return $user_shift_number === 1 ? $is_shift1 : !$is_shift1;
            });
        }
        
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
                    COALESCE(md.batch_id, 'â€”') AS delivery_id,
                    md.supplier AS supplier,
                    md.product AS product_name,
                    md.quantity,
                    COALESCE(md.unit_price, 0) AS unit_price,
                    COALESCE(md.payable_amount, md.expected_amount, 0) AS total_amount,
                    md.delivery_date,
                    COALESCE(md.delivery_ref, 'â€”') AS po_reference,
                    COALESCE(md.expected_quantity, md.quantity, 0) AS expected_quantity,
                    COALESCE(md.actual_quantity, md.quantity, 0) AS actual_quantity,
                    0 AS variance,
                    md.status,
                    'Shift 1' AS shift,
                    COALESCE(md.remarks, 'â€”') AS remarks,
                    COALESCE(u.username, 'â€”') AS encoder,
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
        
        if ($user_shift_number !== 0) {
            $merchandise_deliveries = array_filter($merchandise_deliveries, function($delivery) use ($user_shift_number) {
                $shift = strtolower($delivery['shift'] ?? '');
                $is_shift1 = (strpos($shift, 'shift 1') !== false || strpos($shift, '1') !== false);
                return $user_shift_number === 1 ? $is_shift1 : !$is_shift1;
            });
        }
        
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
            echo '<td class="text-right">â‚±' . number_format($delivery['unit_price'], 2) . '</td>';
            echo '<td class="text-right font-bold">â‚±' . number_format($delivery['total_amount'], 2) . '</td>';
            echo '<td>' . date('m/d/Y', strtotime($delivery['delivery_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($delivery['po_reference'] ?? 'â€”') . '</td>';
            echo '<td class="text-right">' . number_format($delivery['expected_quantity'] ?? 0, 2) . '</td>';
            echo '<td class="text-right">' . number_format($delivery['actual_quantity'] ?? 0, 2) . '</td>';
            echo '<td class="text-right">' . number_format($delivery['variance'] ?? 0, 2) . '</td>';
            echo '<td class="text-center">' . strtoupper($delivery['status'] ?? 'PENDING') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['shift'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['encoder'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['remarks'] ?? 'â€”') . '</td>';
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
    echo '<td class="text-right font-bold">â‚±' . number_format($fuel_shift1_total, 2) . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td><strong>Shift 2 (2PM - 10PM)</strong></td>';
    echo '<td class="text-center">' . $shift2_count . '</td>';
    echo '<td class="text-right font-bold">â‚±' . number_format($fuel_shift2_total, 2) . '</td>';
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
            echo '<td class="text-right">â‚±' . number_format($delivery['unit_price'], 2) . '</td>';
            echo '<td class="text-right font-bold">â‚±' . number_format($delivery['total_amount'], 2) . '</td>';
            echo '<td>' . date('m/d/Y', strtotime($delivery['delivery_date'])) . '</td>';
            echo '<td>' . htmlspecialchars($delivery['po_reference'] ?? 'â€”') . '</td>';
            echo '<td class="text-right">' . number_format($delivery['expected_quantity'] ?? 0, 2) . '</td>';
            echo '<td class="text-right">' . number_format($delivery['actual_quantity'] ?? 0, 2) . '</td>';
            echo '<td class="text-right">' . number_format($delivery['variance'] ?? 0, 2) . '</td>';
            echo '<td class="text-center">' . strtoupper($delivery['status'] ?? 'PENDING') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['shift'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['encoder'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($delivery['remarks'] ?? 'â€”') . '</td>';
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
    echo '<td class="text-right font-bold">â‚±' . number_format($merch_shift1_total, 2) . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td><strong>Shift 2 (2PM - 10PM)</strong></td>';
    echo '<td class="text-center">' . $merch_shift2_count . '</td>';
    echo '<td class="text-right font-bold">â‚±' . number_format($merch_shift2_total, 2) . '</td>';
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
        overflow-x: hidden;
        width: 100%;
    }
    
    .main-content {
        width: 100%;
        max-width: 100%;
        padding: 0;
        margin: 0;
        overflow-x: hidden;
    }
    
    .container {
        max-width: 100%;
        margin: 0;
        padding: 0;
        background: white;
        overflow-x: hidden;
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

    /* Hide prepared-by on screen, only show on print */
    .print-only-signature { display: none !important; }
    @media print { .print-only-signature { display: table !important; } }

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
    .flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
    .flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
    .flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
    .flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
    .flt-btn-csv { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
    .flt-btn-csv:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
    
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
        overflow-x: auto;
        margin-bottom: 20px;
        width: 100%;
        max-width: 100%;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border: 1px solid #cbd5e1;
        font-size: 11px;
        table-layout: auto;
        max-width: 100%;
    }
    
    thead {
        background: #002F6C;
        color: #fff;
    }
    
    th {
        padding: 8px 6px;
        text-align: center;
        font-weight: 700;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0;
        border: 1px solid #002F6C;
        white-space: nowrap;
        background: #002F6C;
        color: #ffffff;
    }
    
    td {
        padding: 7px 6px;
        border: 1px solid #e2e8f0;
        font-size: 11px;
        white-space: normal;
        word-break: break-word;
        vertical-align: middle;
        color: #1e293b;
    }
    
    tbody tr {
        background: #fff;
    }
    
    /* Optimized column min-widths so all 15 columns display fully without squeezing */
    table th:nth-child(1), table td:nth-child(1) { min-width: 60px; text-align: center; }  /* Delivery ID */
    table th:nth-child(2), table td:nth-child(2) { min-width: 120px; } /* Supplier */
    table th:nth-child(3), table td:nth-child(3) { min-width: 120px; } /* Fuel/Product */
    table th:nth-child(4), table td:nth-child(4) { min-width: 50px; text-align: right; }  /* Qty */
    table th:nth-child(5), table td:nth-child(5) { min-width: 65px; text-align: right; }  /* Unit Price */
    table th:nth-child(6), table td:nth-child(6) { min-width: 75px; text-align: right; }  /* Total */
    table th:nth-child(7), table td:nth-child(7) { min-width: 75px; text-align: center; } /* Date */
    table th:nth-child(8), table td:nth-child(8) { min-width: 90px; text-align: center; } /* PO Ref */
    table th:nth-child(9), table td:nth-child(9) { min-width: 50px; text-align: right; }  /* Expected */
    table th:nth-child(10), table td:nth-child(10) { min-width: 50px; text-align: right; } /* Actual */
    table th:nth-child(11), table td:nth-child(11) { min-width: 50px; text-align: right; } /* Variance */
    table th:nth-child(12), table td:nth-child(12) { min-width: 110px; text-align: center; } /* Status */
    table th:nth-child(13), table td:nth-child(13) { min-width: 65px; text-align: center; } /* Shift */
    table th:nth-child(14), table td:nth-child(14) { min-width: 75px; text-align: center; } /* Encoder */
    table th:nth-child(15), table td:nth-child(15) { min-width: 150px; } /* Remarks */
    
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .font-bold { font-weight: 700; }
    
    /* â”€â”€ Shift Summary Boxes â”€â”€ */
    .shift-summary {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin: 14px 0;
    }

    .shift-box {
        background: #fff;
        border: 1.5px solid #cbd5e1;
        border-radius: 6px;
        overflow: hidden;
        width: 100%;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }

    .shift-box h3 {
        font-size: 12px;
        color: #ffffff;
        margin: 0;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: #002F6C;
        padding: 8px 14px;
    }

    .shift-box table {
        font-size: 12px;
        width: 100%;
        border-collapse: collapse;
    }

    .shift-box td {
        padding: 8px 14px;
        border: none;
        border-bottom: 1px solid #f1f5f9;
        color: #1e293b;
        background: #fff;
        font-size: 12px;
    }

    .shift-box tr:last-child td {
        border-bottom: none;
        font-weight: 700;
        color: #002F70;
    }

    /* â”€â”€ Remarks Section â”€â”€ */
    .remarks-section {
        margin-top: 12px;
        border: 1.5px solid #cbd5e1;
        border-radius: 6px;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }

    .remarks-section h3 {
        font-size: 12px;
        color: #ffffff;
        margin: 0;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: #002F6C;
        padding: 8px 14px;
    }

    .remarks-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .remarks-list li {
        padding: 8px 14px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 12px;
        color: #334155;
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
            size: A4 portrait;
            margin: 0.5in 0.4in;
        }

        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        html { 
            overflow-x: hidden !important; 
            width: 100% !important; 
            max-width: 100% !important; 
        }

        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: white !important;
            overflow-x: hidden !important;
        }
        html, body { 
            margin: 0 !important; 
            padding: 0 !important; 
            background: white !important; 
            overflow: hidden !important; 
            overflow-x: hidden !important; 
            width: 100% !important; 
            max-width: 100% !important; 
        }
        .container, .content { 
            margin: 0 !important; 
            padding: 0 !important; 
            overflow-x: hidden !important; 
            width: 100% !important; 
            max-width: 100% !important; 
        }

        /* â”€â”€ Kill ALL icons â”€â”€ */
        i, svg, .fas, .far, .fab, .fa, [class*="fa-"] {
            display: none !important;
            width: 0 !important; height: 0 !important;
            font-size: 0 !important; line-height: 0 !important;
            margin: 0 !important; padding: 0 !important;
        }

        .header { text-align: center !important; border-bottom: none !important; padding: 4px 0 8px 0 !important; margin: 0 0 10px 0 !important; }
        .header h1 { font-size: 16px !important; font-weight: 800 !important; color: #000 !important; margin: 0 0 4px 0 !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; }
        .header p, .header div { font-size: 10px !important; color: #000 !important; margin: 2px 0 !important; }

        .section-title { font-size: 11px !important; font-weight: 800 !important; margin: 12px 0 4px 0 !important; padding-bottom: 3px !important; border-bottom: 2px solid #000 !important; text-transform: uppercase !important; color: #000 !important; page-break-after: avoid !important; }

        .table-container { 
            overflow: hidden !important; 
            overflow-x: hidden !important; 
            margin-bottom: 6px !important; 
            width: 100% !important; 
            max-width: 100% !important; 
            text-align: center !important; 
        }

        table { 
            width: 100% !important; 
            max-width: 100% !important; 
            border-collapse: collapse !important; 
            font-size: 8.5px !important; 
            table-layout: auto !important; 
            margin: 0 auto 8px auto !important; 
        }
        thead { display: table-header-group !important; }
        tbody { display: table-row-group !important; }
        tr { page-break-inside: avoid !important; }
        th { 
            font-size: 8.5px !important; 
            padding: 5px 3px !important; 
            border: 1px solid #000 !important; 
            background: #002F70 !important; 
            color: #ffffff !important; 
            font-weight: 800 !important; 
            text-align: center !important; 
            white-space: nowrap !important; 
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        td { 
            font-size: 8px !important; 
            padding: 4px 3px !important; 
            border: 1px solid #000 !important; 
            white-space: normal !important; 
            word-break: break-word !important; 
            vertical-align: middle !important; 
            color: #000 !important;
            background: #fff !important;
        }

        .shift-summary { display: flex !important; flex-direction: column !important; gap: 8px !important; margin: 8px 0 !important; page-break-inside: avoid !important; }
        .shift-box { border: 1px solid #000 !important; page-break-inside: avoid !important; width: 100% !important; overflow: hidden !important; border-radius: 4px !important; }
        .shift-box h3 { font-size: 9px !important; font-weight: 700 !important; margin: 0 !important; padding: 4px 8px !important; color: #fff !important; background: #002F70 !important; text-transform: uppercase !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .shift-box table { width: 100% !important; margin: 0 !important; border-collapse: collapse !important; }
        .shift-box td { padding: 4px 8px !important; font-size: 9px !important; border: none !important; border-bottom: 1px solid #eee !important; color: #000 !important; }
        .shift-box tr:last-child td { font-weight: 700 !important; border-bottom: none !important; }

        .remarks-section { border: 1px solid #000 !important; margin-top: 8px !important; page-break-inside: avoid !important; overflow: hidden !important; border-radius: 4px !important; }
        .remarks-section h3 { font-size: 9px !important; font-weight: 700 !important; margin: 0 !important; padding: 4px 8px !important; color: #fff !important; background: #002F70 !important; text-transform: uppercase !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .remarks-list { margin: 0 !important; padding: 0 !important; list-style: none !important; }
        .remarks-list li { font-size: 8px !important; padding: 4px 8px !important; border-bottom: 1px solid #eee !important; color: #000 !important;
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

<!-- Main Content Wrapper -->
<div class="stock-page">
    <!-- Controls Section (Not Printed) -->
    <div class="controls">
        <div class="date-controls">
            <label style="font-weight:700;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:.4px;">From</label>
            <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>">
            <span style="font-weight:700;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:.4px;">To</span>
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
            <button type="button" onclick="exportPrintableAreaToPDF('.print-area', 'Staff Deliveries Report', 'staff_deliveries_report_<?= date('Ymd', strtotime($date_start)) ?>_<?= date('Ymd', strtotime($date_end)) ?>', this)" class="flt-btn flt-btn-pdf" title="Export PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <!-- Print -->
            <button type="button" onclick="printReportArea()" class="flt-btn flt-btn-print" title="Print report">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
    
    <!-- Printable Document Area -->
    <div class="print-area">
    <div class="container">
        <div class="header">
            <h1>DELIVERIES REPORT</h1>
            <p style="font-weight:700;font-size:12px;margin-bottom:4px;"><?= htmlspecialchars($station_name) ?></p>
            <div style="font-size:11px;color:#334155;font-weight:600;display:flex;justify-content:center;gap:12px;flex-wrap:wrap;margin-top:4px;">
                <span><strong>Period:</strong> <?= date('M d, Y', strtotime($date_start)) ?> - <?= date('M d, Y', strtotime($date_end)) ?></span>
                <span>â€¢</span>
                <span><strong>Shift:</strong> <?= $user_shift_number === 1 ? 'Shift 1 (6AM - 2PM)' : ($user_shift_number === 2 ? 'Shift 2 (2PM - 10PM)' : 'All Shifts (Shift 1 & Shift 2)') ?></span>

            </div>
        </div>
        
        <div class="content">
            <!-- FUEL DELIVERIES SECTION -->
            <div class="section-title">FUEL DELIVERIES</div>
                
                <div class="table-container">
                    <table id="deliveriesTable">
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
                                <td class="text-right">â‚±<?= number_format($delivery['unit_price'], 2) ?></td>
                                <td class="text-right font-bold">â‚±<?= number_format($delivery['total_amount'], 2) ?></td>
                                <td><?= date('m/d/y', strtotime($delivery['delivery_date'])) ?></td>
                                <td><?= htmlspecialchars($delivery['po_reference'] ?? 'â€”') ?></td>
                                <td class="text-right"><?= number_format($delivery['expected_quantity'] ?? 0, 1) ?></td>
                                <td class="text-right"><?= number_format($delivery['actual_quantity'] ?? 0, 1) ?></td>
                                <td class="text-right"><?= number_format($delivery['variance'] ?? 0, 1) ?></td>
                                <td><span class="status-badge"><?= strtoupper($delivery['status'] ?? 'PENDING') ?></span></td>
                                <td><?= htmlspecialchars($delivery['shift'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($delivery['encoder'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($delivery['remarks'] ?? 'â€”') ?></td>
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
                
                <!-- Shift Summary removed -->
                
                <!-- Remarks Section removed -->
                
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
                                <td class="text-right">â‚±<?= number_format($delivery['unit_price'], 2) ?></td>
                                <td class="text-right font-bold">â‚±<?= number_format($delivery['total_amount'], 2) ?></td>
                                <td><?= date('m/d/y', strtotime($delivery['delivery_date'])) ?></td>
                                <td><?= htmlspecialchars($delivery['po_reference'] ?? 'â€”') ?></td>
                                <td class="text-right"><?= number_format($delivery['expected_quantity'] ?? 0, 1) ?></td>
                                <td class="text-right"><?= number_format($delivery['actual_quantity'] ?? 0, 1) ?></td>
                                <td class="text-right"><?= number_format($delivery['variance'] ?? 0, 1) ?></td>
                                <td><span class="status-badge"><?= strtoupper($delivery['status'] ?? 'PENDING') ?></span></td>
                                <td><?= htmlspecialchars($delivery['shift'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($delivery['encoder'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($delivery['remarks'] ?? 'â€”') ?></td>
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
                
                <!-- Shift Summary & Remarks removed -->

                <!-- PREPARED BY SIGNATURE -->
                <table class="print-only-signature" style="width:100%; margin-top:30px; page-break-inside:avoid; border:none; border-collapse:collapse;">
                    <tr>
                        <td style="border:none;"></td>
                        <td style="border:none; width:240px; text-align:center;">
                            <div style="font-size:10px; font-weight:700; color:#000; margin-bottom:30px; text-transform:uppercase;">PREPARED BY:</div>
                            <div style="border-top:1.5px solid #000; padding-top:4px; font-weight:700; font-size:11px; color:#000;">
                                <?= htmlspecialchars(trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'System User')) ?>
                            </div>
                            <div style="font-size:9.5px; color:#444; margin-top:2px;"><?= htmlspecialchars(ucfirst($role)) ?></div>
                        </td>
                    </tr>
                </table>
            
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
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
