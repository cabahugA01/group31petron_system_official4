<?php
/**
 * Export Manager Shift Summary Reports (PDF)
 * Daily/Shift summary with Shift 1 & Shift 2 totals
 * Manager Side Only
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    die('Access denied');
}

$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

try {
    // Get station name
    $station_name = 'Station';
    $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
    $stmt->execute([$station_id]);
    $st = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($st) $station_name = $st['name'];

    // Fetch shift data for each shift period
    $shifts_query = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC");
    $shift_periods = $shifts_query->fetchAll(PDO::FETCH_ASSOC);

    $report_data = [];

    foreach ($shift_periods as $shift) {
        $shift_key = $shift['shift_key'];
        $shift_name = $shift['shift_name'];

        // Fuel transactions summary
        $fuel_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_txn,
                SUM(liters_sold) AS total_liters,
                SUM(liters_sold * price_per_liter) AS total_sales
            FROM fuel_transactions
            WHERE station_id = ?
              AND shift_period = ?
              AND DATE(transaction_date) BETWEEN ? AND ?
        ");
        $fuel_stmt->execute([$station_id, $shift_key, $date_from, $date_to]);
        $fuel_data = $fuel_stmt->fetch(PDO::FETCH_ASSOC);

        // Merchandise transactions summary
        $merch_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_txn,
                SUM(total_amount) AS total_sales,
                SUM(COALESCE(amount_paid, 0)) AS total_paid,
                SUM(total_amount - COALESCE(amount_paid, 0)) AS total_balance
            FROM merchandise_transactions
            WHERE station_id = ?
              AND shift_period = ?
              AND DATE(CASE WHEN transaction_date > '2000-01-01' THEN transaction_date ELSE created_at END) BETWEEN ? AND ?
              AND COALESCE(transaction_type, 'merchandise') = 'merchandise'
        ");
        $merch_stmt->execute([$station_id, $shift_key, $date_from, $date_to]);
        $merch_data = $merch_stmt->fetch(PDO::FETCH_ASSOC);

        // Top services
        $services_stmt = $pdo->prepare("
            SELECT 
                service_type,
                COUNT(*) AS service_count,
                SUM(total_cost) AS service_revenue
            FROM job_orders
            WHERE station_id = ?
              AND shift_period = ?
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY service_type
            ORDER BY service_count DESC
            LIMIT 5
        ");
        $services_stmt->execute([$station_id, $shift_key, $date_from, $date_to]);
        $top_services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top merchandise items
        $items_stmt = $pdo->prepare("
            SELECT 
                mti.product_name,
                SUM(mti.quantity) AS total_qty,
                SUM(mti.quantity * mti.unit_price) AS total_revenue
            FROM merchandise_transaction_items mti
            INNER JOIN merchandise_transactions mt ON mt.id = mti.transaction_id
            WHERE mt.station_id = ?
              AND mt.shift_period = ?
              AND DATE(CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END) BETWEEN ? AND ?
            GROUP BY mti.product_name
            ORDER BY total_qty DESC
            LIMIT 5
        ");
        $items_stmt->execute([$station_id, $shift_key, $date_from, $date_to]);
        $top_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Payment breakdown
        $payment_stmt = $pdo->prepare("
            SELECT 
                payment_status,
                COUNT(*) AS count,
                SUM(total_amount) AS total
            FROM merchandise_transactions
            WHERE station_id = ?
              AND shift_period = ?
              AND DATE(CASE WHEN transaction_date > '2000-01-01' THEN transaction_date ELSE created_at END) BETWEEN ? AND ?
            GROUP BY payment_status
        ");
        $payment_stmt->execute([$station_id, $shift_key, $date_from, $date_to]);
        $payment_breakdown = $payment_stmt->fetchAll(PDO::FETCH_ASSOC);

        $report_data[$shift_key] = [
            'shift_name' => $shift_name,
            'fuel' => $fuel_data,
            'merchandise' => $merch_data,
            'top_services' => $top_services,
            'top_items' => $top_items,
            'payment_breakdown' => $payment_breakdown
        ];
    }

    // Generate PDF Report
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html><html><head>';
    echo '<meta charset="UTF-8"><title>Shift Summary Report - ' . htmlspecialchars($station_name) . '</title>';
    echo '<style>';
    echo '@page{size:legal portrait;margin:0.5in;}';
    echo 'body{font-family:Arial,sans-serif;font-size:11px;margin:0;padding:20px;color:#000;}';
    echo 'h1{color:#002F70;font-size:20px;text-align:center;margin-bottom:5px;text-transform:uppercase;}';
    echo 'h2{color:#002F70;font-size:16px;margin:20px 0 10px;padding:8px;background:#f0f4ff;border-left:4px solid #002F70;}';
    echo '.info{text-align:center;font-size:12px;color:#555;margin-bottom:20px;border-bottom:2px solid #002F70;padding-bottom:10px;}';
    echo 'table{width:100%;border-collapse:collapse;margin:10px 0;font-size:10px;}';
    echo 'th,td{border:1px solid #ddd;padding:6px;text-align:left;}';
    echo 'th{background-color:#002F70;color:white;font-weight:bold;font-size:10px;text-align:center;}';
    echo '.amount{text-align:right;font-weight:bold;color:#002F70;}';
    echo '.summary-box{background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;padding:12px;margin:10px 0;}';
    echo '.summary-row{display:flex;justify-content:space-between;padding:5px 0;}';
    echo '.summary-label{font-weight:600;color:#475569;}';
    echo '.summary-value{font-weight:700;color:#002F70;font-size:13px;}';
    echo '.shift-section{page-break-inside:avoid;margin-bottom:30px;}';
    echo '@media print{button{display:none;}}';
    echo '</style></head><body>';
    
    echo '<h1>' . htmlspecialchars($station_name) . ' - Daily Shift Summary Report</h1>';
    echo '<div class="info">Report Period: ' . date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to)) . ' | Generated: ' . date('F d, Y h:i A') . ' | Manager: ' . htmlspecialchars($me['name']) . '</div>';
    
    echo '<button onclick="window.print()" style="padding:10px 20px;background:#002F70;color:white;border:none;border-radius:6px;cursor:pointer;margin-bottom:15px;font-size:12px;font-weight:600;">Print / Save as PDF</button>';

    // Loop through each shift
    foreach ($report_data as $shift_key => $data) {
        echo '<div class="shift-section">';
        echo '<h2>' . htmlspecialchars($data['shift_name']) . ' Summary</h2>';

        // Summary boxes
        echo '<div class="summary-box">';
        echo '<div class="summary-row"><span class="summary-label">Total Fuel Sales:</span><span class="summary-value">₱' . number_format((float)($data['fuel']['total_sales'] ?? 0), 2) . '</span></div>';
        echo '<div class="summary-row"><span class="summary-label">Total Merchandise Sales:</span><span class="summary-value">₱' . number_format((float)($data['merchandise']['total_sales'] ?? 0), 2) . '</span></div>';
        echo '<div class="summary-row"><span class="summary-label">Total Services:</span><span class="summary-value">' . (int)($data['fuel']['total_txn'] ?? 0) . ' fuel + ' . (int)($data['merchandise']['total_txn'] ?? 0) . ' merchandise</span></div>';
        echo '<div class="summary-row"><span class="summary-label">Shift Total Revenue:</span><span class="summary-value">₱' . number_format((float)(($data['fuel']['total_sales'] ?? 0) + ($data['merchandise']['total_sales'] ?? 0)), 2) . '</span></div>';
        echo '</div>';

        // Top Services
        if (!empty($data['top_services'])) {
            echo '<h3 style="color:#002F70;font-size:13px;margin:15px 0 8px;">Top Services</h3>';
            echo '<table>';
            echo '<thead><tr><th>Service Type</th><th>Count</th><th>Revenue</th></tr></thead><tbody>';
            foreach ($data['top_services'] as $svc) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($svc['service_type']) . '</td>';
                echo '<td style="text-align:center;">' . (int)$svc['service_count'] . '</td>';
                echo '<td class="amount">₱' . number_format((float)$svc['service_revenue'], 2) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Top Items Sold
        if (!empty($data['top_items'])) {
            echo '<h3 style="color:#002F70;font-size:13px;margin:15px 0 8px;">Top Items Sold</h3>';
            echo '<table>';
            echo '<thead><tr><th>Item</th><th>Quantity</th><th>Revenue</th></tr></thead><tbody>';
            foreach ($data['top_items'] as $item) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($item['product_name']) . '</td>';
                echo '<td style="text-align:center;">' . (int)$item['total_qty'] . ' pcs</td>';
                echo '<td class="amount">₱' . number_format((float)$item['total_revenue'], 2) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Payment Breakdown
        if (!empty($data['payment_breakdown'])) {
            echo '<h3 style="color:#002F70;font-size:13px;margin:15px 0 8px;">Payment Status Breakdown</h3>';
            echo '<table>';
            echo '<thead><tr><th>Payment Status</th><th>Count</th><th>Amount</th></tr></thead><tbody>';
            foreach ($data['payment_breakdown'] as $pay) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($pay['payment_status']) . '</td>';
                echo '<td style="text-align:center;">' . (int)$pay['count'] . '</td>';
                echo '<td class="amount">₱' . number_format((float)$pay['total'], 2) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>'; // End shift-section
    }

    // Overall Summary
    $total_fuel_sales = array_sum(array_column(array_column($report_data, 'fuel'), 'total_sales'));
    $total_merch_sales = array_sum(array_column(array_column($report_data, 'merchandise'), 'total_sales'));
    $grand_total = $total_fuel_sales + $total_merch_sales;

    echo '<div style="margin-top:30px;padding:15px;background:#002F70;color:white;border-radius:6px;">';
    echo '<h3 style="margin:0 0 10px;font-size:15px;text-align:center;">OVERALL SUMMARY</h3>';
    echo '<div style="display:flex;justify-content:space-around;font-size:13px;font-weight:600;">';
    echo '<div><span>Total Fuel Sales: </span><span style="font-size:16px;">₱' . number_format($total_fuel_sales, 2) . '</span></div>';
    echo '<div><span>Total Merchandise Sales: </span><span style="font-size:16px;">₱' . number_format($total_merch_sales, 2) . '</span></div>';
    echo '<div><span>Grand Total: </span><span style="font-size:18px;">₱' . number_format($grand_total, 2) . '</span></div>';
    echo '</div></div>';

    echo '</body></html>';
    exit;

} catch (Exception $e) {
    die('Export error: ' . $e->getMessage());
}
