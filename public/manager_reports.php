<?php
// ============================================================
// Manager Reports — public/manager_reports.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'manager_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php'); exit;
}

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}
if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

// ============================================================
// SECTION & DATE RANGE LOGIC
// ============================================================
$valid_sections = ['sales', 'job_orders', 'balances', 'deliveries', 'staff', 'validation', 'audit_trail'];
$section = trim($_GET['section'] ?? 'sales');
if (!in_array($section, $valid_sections)) $section = 'sales';

$range = strtolower(trim($_GET['range'] ?? 'month'));
if (!in_array($range, ['today', 'week', 'month', 'custom'])) $range = 'month';

$today = date('Y-m-d');
switch ($range) {
    case 'week':
        $date_start = date('Y-m-d', strtotime('monday this week'));
        $date_end   = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $date_start = date('Y-m-01');
        $date_end   = date('Y-m-t');
        break;
    case 'custom':
        $date_start = trim($_GET['start'] ?? $today);
        $date_end   = trim($_GET['end']   ?? $today);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = $today;
        if ($date_end < $date_start) $date_end = $date_start;
        break;
    default: // today
        $date_start = $today;
        $date_end   = $today;
        break;
}

// ============================================================
// CSV EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    $filename = "report_{$section}_{$date_start}_to_{$date_end}.csv";
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    switch ($section) {
        case 'sales':
            // ── Fuel Sales ────────────────────────────────────────────────
            fputcsv($out, ['FUEL SALES REPORT']);
            fputcsv($out, ['Date', 'Fuel Type', 'Transactions', 'Liters Sold', 'Revenue', 'Variance vs Pump (avg L)']);
            try {
                $has_vt = false;
                try { $pdo->query("SELECT 1 FROM fuel_variance_reports LIMIT 1"); $has_vt = true; } catch (Exception $e) {}
                $fsc = "LOWER(TRIM(ft.status)) IN ('verified','adjusted','complete','completed','approved','validated','verified sale')";
                if ($has_vt) {
                    $s = $pdo->prepare("
                        SELECT DATE(ft.transaction_date) AS sale_date, ft.fuel_type,
                               COUNT(ft.transaction_id) AS txn_count,
                               COALESCE(SUM(ft.liters_sold),0) AS total_liters,
                               COALESCE(SUM(ft.total_amount),0) AS total_revenue,
                               COALESCE(AVG(fvr.variance_liters),0) AS avg_variance_liters
                        FROM fuel_transactions ft
                        LEFT JOIN fuel_variance_reports fvr
                            ON fvr.station_id=ft.station_id AND DATE(fvr.report_date)=DATE(ft.transaction_date)
                            AND LOWER(TRIM(fvr.fuel_type))=LOWER(TRIM(ft.fuel_type))
                        WHERE ft.station_id=? AND $fsc AND DATE(ft.transaction_date) BETWEEN ? AND ?
                        GROUP BY DATE(ft.transaction_date), ft.fuel_type ORDER BY sale_date DESC, ft.fuel_type");
                } else {
                    $s = $pdo->prepare("
                        SELECT DATE(ft.transaction_date) AS sale_date, ft.fuel_type,
                               COUNT(ft.transaction_id) AS txn_count,
                               COALESCE(SUM(ft.liters_sold),0) AS total_liters,
                               COALESCE(SUM(ft.total_amount),0) AS total_revenue, 0 AS avg_variance_liters
                        FROM fuel_transactions ft
                        WHERE ft.station_id=? AND $fsc AND DATE(ft.transaction_date) BETWEEN ? AND ?
                        GROUP BY DATE(ft.transaction_date), ft.fuel_type ORDER BY sale_date DESC, ft.fuel_type");
                }
                $s->execute([$station_id, $date_start, $date_end]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    fputcsv($out, [
                        date('M j, Y', strtotime($row['sale_date'])),
                        $row['fuel_type'],
                        $row['txn_count'],
                        number_format($row['total_liters'], 2) . ' L',
                        number_format($row['total_revenue'], 2),
                        number_format($row['avg_variance_liters'], 4) . ' L',
                    ]);
                }
            } catch (Exception $e) {}

            fputcsv($out, []);

            // ── Merchandise Sales ─────────────────────────────────────────
            fputcsv($out, ['MERCHANDISE SALES REPORT']);
            fputcsv($out, ['Date', 'Transactions', 'Qty Sold', 'Revenue', 'Cash', 'Card', 'E-Wallet', 'E-Fuel Card', 'Credit']);
            try {
                $mde = "CASE WHEN mt.transaction_date > '2000-01-01' THEN DATE(mt.transaction_date) ELSE DATE(mt.created_at) END";
                $s = $pdo->prepare("
                    SELECT ($mde) AS sale_date, COUNT(mt.id) AS txn_count,
                           COALESCE(SUM(mt.total_amount),0) AS total_revenue,
                           COALESCE(SUM(CASE WHEN si.id IS NOT NULL THEN si.quantity ELSE 0 END),0) AS total_quantity,
                           COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('cash') THEN mt.total_amount ELSE 0 END),0) AS pay_cash,
                           COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('credit card','card','debit card') THEN mt.total_amount ELSE 0 END),0) AS pay_card,
                           COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('gcash','maya','paymaya','e-wallet','ewallet') THEN mt.total_amount ELSE 0 END),0) AS pay_ewallet,
                           COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('e-fuel card','fuel card','efuel') THEN mt.total_amount ELSE 0 END),0) AS pay_efuel,
                           COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('account receivable','credit','utang') THEN mt.total_amount ELSE 0 END),0) AS pay_credit
                    FROM merchandise_transactions mt
                    LEFT JOIN sale_items si ON si.sale_id = mt.id
                    WHERE mt.station_id=? AND ($mde) BETWEEN ? AND ?
                        AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
                    GROUP BY ($mde) ORDER BY sale_date DESC");
                $s->execute([$station_id, $date_start, $date_end]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    fputcsv($out, [
                        date('M j, Y', strtotime($row['sale_date'])),
                        $row['txn_count'],
                        $row['total_quantity'],
                        number_format($row['total_revenue'], 2),
                        number_format($row['pay_cash'], 2),
                        number_format($row['pay_card'], 2),
                        number_format($row['pay_ewallet'], 2),
                        number_format($row['pay_efuel'], 2),
                        number_format($row['pay_credit'], 2),
                    ]);
                }
            } catch (Exception $e) {}

            fputcsv($out, []);

            // ── Daily Summary ─────────────────────────────────────────────
            fputcsv($out, ['DAILY SUMMARY REPORT']);
            fputcsv($out, ['Date', 'Total Fuel Liters Sold', 'Fuel Revenue', 'Merchandise Revenue', 'Combined Daily Revenue', 'Variance Alert']);
            try {
                $has_vt = false;
                try { $pdo->query("SELECT 1 FROM fuel_variance_reports LIMIT 1"); $has_vt = true; } catch (Exception $e) {}
                $fsc = "LOWER(TRIM(ft.status)) IN ('verified','adjusted','complete','completed','approved','validated','verified sale')";
                $mde = "CASE WHEN mt.transaction_date > '2000-01-01' THEN DATE(mt.transaction_date) ELSE DATE(mt.created_at) END";
                if ($has_vt) {
                    $s = $pdo->prepare("
                        SELECT d.sale_date,
                               COALESCE(f.fuel_liters,0) AS total_fuel_liters,
                               COALESCE(f.fuel_rev,0) AS fuel_revenue,
                               COALESCE(m.merch_rev,0) AS merch_revenue,
                               COALESCE(f.fuel_rev,0)+COALESCE(m.merch_rev,0) AS total_revenue,
                               COALESCE(f.avg_variance,0) AS fuel_variance
                        FROM (
                            SELECT DISTINCT DATE(ft.transaction_date) AS sale_date FROM fuel_transactions ft
                            WHERE ft.station_id=? AND DATE(ft.transaction_date) BETWEEN ? AND ? AND $fsc
                            UNION
                            SELECT DISTINCT ($mde) FROM merchandise_transactions mt
                            WHERE mt.station_id=? AND ($mde) BETWEEN ? AND ?
                                AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
                        ) d
                        LEFT JOIN (
                            SELECT DATE(ft.transaction_date) AS sale_date, SUM(ft.liters_sold) AS fuel_liters,
                                   SUM(ft.total_amount) AS fuel_rev, AVG(fvr.variance_liters) AS avg_variance
                            FROM fuel_transactions ft
                            LEFT JOIN fuel_variance_reports fvr ON fvr.station_id=ft.station_id
                                AND DATE(fvr.report_date)=DATE(ft.transaction_date)
                                AND LOWER(TRIM(fvr.fuel_type))=LOWER(TRIM(ft.fuel_type))
                            WHERE ft.station_id=? AND $fsc GROUP BY DATE(ft.transaction_date)
                        ) f ON f.sale_date=d.sale_date
                        LEFT JOIN (
                            SELECT ($mde) AS sale_date, SUM(mt.total_amount) AS merch_rev
                            FROM merchandise_transactions mt WHERE mt.station_id=?
                                AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
                            GROUP BY ($mde)
                        ) m ON m.sale_date=d.sale_date
                        ORDER BY d.sale_date DESC");
                    $s->execute([$station_id,$date_start,$date_end,$station_id,$date_start,$date_end,$station_id,$station_id]);
                } else {
                    $s = $pdo->prepare("
                        SELECT d.sale_date,
                               COALESCE(f.fuel_liters,0) AS total_fuel_liters,
                               COALESCE(f.fuel_rev,0) AS fuel_revenue,
                               COALESCE(m.merch_rev,0) AS merch_revenue,
                               COALESCE(f.fuel_rev,0)+COALESCE(m.merch_rev,0) AS total_revenue,
                               0 AS fuel_variance
                        FROM (
                            SELECT DISTINCT DATE(ft.transaction_date) AS sale_date FROM fuel_transactions ft
                            WHERE ft.station_id=? AND DATE(ft.transaction_date) BETWEEN ? AND ? AND $fsc
                            UNION
                            SELECT DISTINCT ($mde) FROM merchandise_transactions mt
                            WHERE mt.station_id=? AND ($mde) BETWEEN ? AND ?
                                AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
                        ) d
                        LEFT JOIN (
                            SELECT DATE(ft.transaction_date) AS sale_date, SUM(ft.liters_sold) AS fuel_liters, SUM(ft.total_amount) AS fuel_rev
                            FROM fuel_transactions ft WHERE ft.station_id=? AND $fsc GROUP BY DATE(ft.transaction_date)
                        ) f ON f.sale_date=d.sale_date
                        LEFT JOIN (
                            SELECT ($mde) AS sale_date, SUM(mt.total_amount) AS merch_rev
                            FROM merchandise_transactions mt WHERE mt.station_id=?
                                AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
                            GROUP BY ($mde)
                        ) m ON m.sale_date=d.sale_date
                        ORDER BY d.sale_date DESC");
                    $s->execute([$station_id,$date_start,$date_end,$station_id,$date_start,$date_end,$station_id,$station_id]);
                }
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $variance_alert = abs((float)$row['fuel_variance']) > 0.5 ? 'YES – ' . number_format($row['fuel_variance'], 4) . ' L avg' : 'None';
                    fputcsv($out, [
                        date('M j, Y', strtotime($row['sale_date'])),
                        number_format($row['total_fuel_liters'], 2) . ' L',
                        number_format($row['fuel_revenue'], 2),
                        number_format($row['merch_revenue'], 2),
                        number_format($row['total_revenue'], 2),
                        $variance_alert,
                    ]);
                }
            } catch (Exception $e) {}
            break;

        case 'job_orders':
            // Export Job Orders
            fputcsv($out, ['JOB ORDERS']);
            fputcsv($out, ['JO Ref', 'Customer', 'Vehicle Plate', 'Service Type', 'Staff Assignment', 'Mechanic', 'Status', 'Labor Cost', 'Parts Cost', 'Total Amount', 'Created At']);
            try {
                $s = $pdo->prepare("SELECT 
                    COALESCE(jo.job_order_id, jo.jo_number, CONCAT('JO-', jo.id)) AS jo_ref,
                    COALESCE(c.name, jo.customer_name, 'Walk-in') AS customer,
                    COALESCE(jo.vehicle_plate, '—') AS vehicle_plate,
                    COALESCE(jo.service_type, jo.service_description, '—') AS service_type,
                    COALESCE(staff.name, '—') AS assigned_staff,
                    COALESCE(m.full_name, m.name, '—') AS mechanic,
                    CASE 
                        WHEN jo.status IN ('Pending', 'In Progress') THEN 'Pending'
                        WHEN jo.status = 'Completed' THEN 'Completed'
                        WHEN jo.validation_status = 'Approved' THEN 'Approved'
                        WHEN jo.validation_status = 'Rejected' THEN 'Rejected'
                        WHEN jo.validation_status = 'Adjusted' THEN 'Adjusted'
                        ELSE jo.status
                    END as display_status,
                    COALESCE(jo.labor_cost, 0) AS labor_cost,
                    COALESCE(jo.parts_cost, 0) AS parts_cost,
                    COALESCE(jo.total_amount, 0) AS total_amount,
                    jo.created_at
                    FROM job_orders jo 
                    LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id 
                    LEFT JOIN customers c ON c.id = jo.customer_id
                    LEFT JOIN users staff ON staff.id = jo.user_id
                    WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ? 
                    ORDER BY jo.created_at DESC");
                $s->execute([$station_id, $date_start, $date_end]);
                $jo_export_data = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                foreach ($jo_export_data as $row) {
                    fputcsv($out, [
                        $row['jo_ref'],
                        $row['customer'],
                        $row['vehicle_plate'],
                        $row['service_type'],
                        $row['assigned_staff'],
                        $row['mechanic'],
                        $row['display_status'],
                        number_format($row['labor_cost'], 2),
                        number_format($row['parts_cost'], 2),
                        number_format($row['total_amount'], 2),
                        date('M j, Y', strtotime($row['created_at']))
                    ]);
                }
            } catch (Exception $e) {}
            break;

        case 'balances':
            // Export Customer Balances
            fputcsv($out, ['CUSTOMER BALANCES']);
            fputcsv($out, ['Customer', 'Credit Limit', 'Outstanding Balance', 'Credit Usage %', 'Payment Status', 'Earliest Due Date', 'Latest Due Date', 'Outstanding Transactions']);
            try {
                $s = $pdo->prepare("SELECT 
                    c.name,
                    COALESCE(c.credit_limit, 0) AS credit_limit,
                    COALESCE(SUM(jo.total_amount - COALESCE(jo.amount_paid, 0)), 0) AS outstanding,
                    COALESCE(MIN(jo.due_date), CURDATE()) AS earliest_due_date,
                    COALESCE(MAX(jo.due_date), CURDATE()) AS latest_due_date,
                    COUNT(DISTINCT jo.id) as outstanding_transactions,
                    CASE 
                        WHEN COALESCE(MIN(jo.due_date), CURDATE()) < CURDATE() THEN 'Overdue'
                        WHEN COALESCE(MIN(jo.due_date), CURDATE()) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Due Soon'
                        ELSE 'Current'
                    END as payment_status
                    FROM customers c 
                    LEFT JOIN job_orders jo ON jo.customer_id = c.id 
                        AND jo.payment_method IN ('Credit', 'Account Receivable', 'utang', 'Utang') 
                        AND jo.payment_status != 'Paid' 
                        AND jo.station_id = ?
                    WHERE c.station_id = ? 
                    GROUP BY c.id, c.name, c.credit_limit 
                    HAVING outstanding > 0 
                    ORDER BY 
                        CASE 
                            WHEN COALESCE(MIN(jo.due_date), CURDATE()) < CURDATE() THEN 1
                            WHEN COALESCE(MIN(jo.due_date), CURDATE()) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 2
                            ELSE 3
                        END,
                        earliest_due_date ASC,
                        outstanding DESC");
                $s->execute([$station_id, $station_id]);
                $balance_export_data = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                foreach ($balance_export_data as $row) {
                    $util = $row['credit_limit'] > 0 ? ($row['outstanding'] / $row['credit_limit']) * 100 : 100;
                    fputcsv($out, [
                        $row['name'],
                        number_format($row['credit_limit'], 2),
                        number_format($row['outstanding'], 2),
                        number_format($util, 1) . '%',
                        $row['payment_status'],
                        date('M j, Y', strtotime($row['earliest_due_date'])),
                        date('M j, Y', strtotime($row['latest_due_date'])),
                        $row['outstanding_transactions']
                    ]);
                }
            } catch (Exception $e) {}
            break;

        case 'deliveries':
            // Export Deliveries Report
            fputcsv($out, ['DELIVERIES REPORT']);
            fputcsv($out, ['Delivery ID / Reference', 'Supplier Name', 'Product / Category', 'Quantity Delivered', 'Date & Time Received', 'Encoded By', 'Status', 'Remarks']);
            try {
                $s = $pdo->prepare("SELECT 
                    COALESCE(d.delivery_ref, CONCAT('DEL-', d.id)) AS delivery_id,
                    COALESCE(d.supplier, 'Unknown Supplier') AS supplier_name,
                    COALESCE(d.product_name, d.fuel_type, 'Unknown Product') AS product_category,
                    COALESCE(d.quantity, 0) AS quantity_delivered,
                    COALESCE(d.unit, 'units') AS unit_type,
                    COALESCE(d.delivery_date, d.created_at) AS date_time_received,
                    COALESCE(u.name, 'Unknown Staff') AS encoded_by,
                    d.status,
                    COALESCE(d.admin_notes, d.remarks, '') AS remarks
                    FROM deliveries_oversight d 
                    LEFT JOIN users u ON u.id = d.created_by 
                    WHERE d.station_id = ? 
                        AND DATE(COALESCE(d.delivery_date, d.created_at)) BETWEEN ? AND ? 
                    ORDER BY 
                        CASE 
                            WHEN d.status = 'Pending' THEN 1
                            WHEN d.status IN ('Approved', 'Confirmed', 'Validated') THEN 2
                            WHEN d.status IN ('Rejected', 'Flagged', 'Discrepancy') THEN 3
                            ELSE 4
                        END,
                        COALESCE(d.delivery_date, d.created_at) DESC");
                $s->execute([$station_id, $date_start, $date_end]);
                $delivery_export_data = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                foreach ($delivery_export_data as $row) {
                    fputcsv($out, [
                        $row['delivery_id'],
                        $row['supplier_name'],
                        $row['product_category'],
                        number_format($row['quantity_delivered'], 2) . ' ' . $row['unit_type'],
                        date('M j, Y H:i', strtotime($row['date_time_received'])),
                        $row['encoded_by'],
                        $row['status'],
                        $row['remarks'] ?: '—'
                    ]);
                }
            } catch (Exception $e) {}
            break;

        case 'staff':
            // Export Staff Performance Report
            fputcsv($out, ['STAFF PERFORMANCE REPORT']);
            fputcsv($out, ['Staff ID / Name', 'Role', 'Transactions Encoded', 'Job Orders Encoded', 'Deliveries Encoded', 'Total Hours Worked', 'Shift Count', 'Attendance Days', 'Performance Score']);
            try {
                $s = $pdo->prepare("SELECT 
                    u.id AS staff_id,
                    u.name AS staff_name,
                    u.role,
                    COALESCE(fuel_txns.fuel_transactions, 0) AS fuel_transactions,
                    COALESCE(merch_txns.merch_transactions, 0) AS merch_transactions,
                    COALESCE(total_txns.total_transactions, 0) AS total_transactions,
                    COALESCE(job_orders.job_orders_encoded, 0) AS job_orders_encoded,
                    COALESCE(deliveries.deliveries_encoded, 0) AS deliveries_encoded,
                    COALESCE(attendance.total_hours, 0) AS total_hours,
                    COALESCE(attendance.shift_count, 0) AS shift_count,
                    COALESCE(attendance.attendance_days, 0) AS attendance_days
                    FROM users u 
                    LEFT JOIN (
                        SELECT staff_id, COUNT(*) AS fuel_transactions
                        FROM fuel_transactions 
                        WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
                        GROUP BY staff_id
                    ) fuel_txns ON fuel_txns.staff_id = u.id
                    LEFT JOIN (
                        SELECT staff_id, COUNT(*) AS merch_transactions
                        FROM merchandise_transactions 
                        WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
                        GROUP BY staff_id
                    ) merch_txns ON merch_txns.staff_id = u.id
                    LEFT JOIN (
                        SELECT 
                            staff_id, 
                            COUNT(*) AS total_transactions
                        FROM (
                            SELECT staff_id FROM fuel_transactions 
                            WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
                            UNION ALL
                            SELECT staff_id FROM merchandise_transactions 
                            WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
                        ) all_txns
                        GROUP BY staff_id
                    ) total_txns ON total_txns.staff_id = u.id
                    LEFT JOIN (
                        SELECT user_id, COUNT(*) AS job_orders_encoded
                        FROM job_orders 
                        WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?
                        GROUP BY user_id
                    ) job_orders ON job_orders.user_id = u.id
                    LEFT JOIN (
                        SELECT created_by, COUNT(*) AS deliveries_encoded
                        FROM deliveries_oversight 
                        WHERE station_id = ? AND DATE(COALESCE(delivery_date, created_at)) BETWEEN ? AND ?
                        GROUP BY created_by
                    ) deliveries ON deliveries.created_by = u.id
                    LEFT JOIN (
                        SELECT 
                            user_id,
                            SUM(hours_worked) AS total_hours,
                            COUNT(DISTINCT DATE(start_time)) AS attendance_days,
                            COUNT(*) AS shift_count
                        FROM labor_sessions 
                        WHERE station_id = ? AND DATE(start_time) BETWEEN ? AND ?
                        GROUP BY user_id
                    ) attendance ON attendance.user_id = u.id
                    WHERE u.station_id = ? AND u.status = 'active'
                    ORDER BY 
                        (COALESCE(total_txns.total_transactions, 0) + COALESCE(job_orders.job_orders_encoded, 0) + COALESCE(deliveries.deliveries_encoded, 0)) DESC,
                        u.name ASC");
                $s->execute([
                    $station_id, $date_start, $date_end,
                    $station_id, $date_start, $date_end,
                    $station_id, $date_start, $date_end,
                    $station_id, $date_start, $date_end,
                    $station_id, $date_start, $date_end,
                    $station_id
                ]);
                $staff_export_data = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                foreach ($staff_export_data as $row) {
                    $performance_score = ($row['total_transactions'] * 1) + ($row['job_orders_encoded'] * 2) + ($row['deliveries_encoded'] * 3);
                    fputcsv($out, [
                        '#' . $row['staff_id'] . ' - ' . $row['staff_name'],
                        ucfirst($row['role']),
                        $row['total_transactions'] . ' (Fuel: ' . $row['fuel_transactions'] . ', Merch: ' . $row['merch_transactions'] . ')',
                        $row['job_orders_encoded'],
                        $row['deliveries_encoded'],
                        number_format($row['total_hours'], 1) . 'h',
                        $row['shift_count'],
                        $row['attendance_days'],
                        $performance_score
                    ]);
                }
            } catch (Exception $e) {}
            break;

        case 'validation':
            // Export Validation Logs Report
            fputcsv($out, ['VALIDATION LOGS REPORT']);
            fputcsv($out, ['Date & Time','Manager','Role','Action','Module','Reference','Details','Reason','Encoded By']);
            try {
                // Job order validations
                $s = $pdo->prepare("
                    SELECT jo.validated_at AS date_time, COALESCE(u.name,'Unknown') AS manager_name,
                        COALESCE(u.role,'manager') AS manager_role,
                        COALESCE(jo.validation_status,'Validated') AS action,
                        'Job Order' AS module,
                        COALESCE(jo.job_order_id,jo.job_order_number,CONCAT('JO-',jo.id)) AS reference_id,
                        CONCAT(COALESCE(jo.service_type,jo.service_description,'Service'),' — ',COALESCE(jo.customer_name,'Walk-in')) AS details,
                        COALESCE(jo.adjustment_reason,jo.admin_remarks,'') AS reason,
                        COALESCE(staff.name,'Unknown') AS encoded_by
                    FROM job_orders jo
                    LEFT JOIN users u ON u.id=jo.validated_by
                    LEFT JOIN users staff ON staff.id=jo.created_by
                    WHERE jo.station_id=? AND jo.validated_at IS NOT NULL
                      AND DATE(jo.validated_at) BETWEEN ? AND ?
                    ORDER BY jo.validated_at DESC");
                $s->execute([$station_id,$date_start,$date_end]);
                $val_exp = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // Merchandise validations
                try {
                    $s2 = $pdo->prepare("
                        SELECT mt.validated_at AS date_time, COALESCE(u.name,'Unknown') AS manager_name,
                            COALESCE(u.role,'manager') AS manager_role,
                            COALESCE(mt.validation_status,'Validated') AS action,
                            'Merchandise' AS module, mt.transaction_id AS reference_id,
                            CONCAT('Merchandise — ',COALESCE(mt.customer_name,'Walk-in'),' (',mt.payment_method,')') AS details,
                            COALESCE(mt.rejection_reason,mt.adjustment_reason,'') AS reason,
                            COALESCE(staff.name,'Unknown') AS encoded_by
                        FROM merchandise_transactions mt
                        LEFT JOIN users u ON u.id=mt.validated_by
                        LEFT JOIN users staff ON staff.id=mt.staff_id
                        WHERE mt.station_id=? AND mt.validated_at IS NOT NULL
                          AND mt.validation_status IN ('Approved','Rejected','Adjusted')
                          AND DATE(mt.validated_at) BETWEEN ? AND ?
                        ORDER BY mt.validated_at DESC");
                    $s2->execute([$station_id,$date_start,$date_end]);
                    $val_exp = array_merge($val_exp, $s2->fetchAll(PDO::FETCH_ASSOC) ?: []);
                } catch(Exception $e2){}

                // Delivery validations
                try {
                    $s3 = $pdo->prepare("
                        SELECT d.admin_action_at AS date_time, COALESCE(u.name,'Unknown') AS manager_name,
                            COALESCE(u.role,'manager') AS manager_role, d.status AS action,
                            'Delivery' AS module,
                            COALESCE(d.delivery_ref,CONCAT('DEL-',d.id)) AS reference_id,
                            CONCAT(COALESCE(d.product,d.delivery_type,'Delivery'),' from ',COALESCE(d.supplier,'Unknown')) AS details,
                            COALESCE(d.admin_notes,d.remarks,'') AS reason,
                            COALESCE(enc.name,'Unknown') AS encoded_by
                        FROM deliveries_oversight d
                        LEFT JOIN users u ON u.id=d.admin_id
                        LEFT JOIN users enc ON enc.id=d.encoded_by
                        WHERE d.station_id=? AND d.admin_id IS NOT NULL AND d.admin_action_at IS NOT NULL
                          AND DATE(d.admin_action_at) BETWEEN ? AND ?
                        ORDER BY d.admin_action_at DESC");
                    $s3->execute([$station_id,$date_start,$date_end]);
                    $val_exp = array_merge($val_exp, $s3->fetchAll(PDO::FETCH_ASSOC) ?: []);
                } catch(Exception $e3){}

                usort($val_exp, function($a,$b){ return strtotime($b['date_time']) - strtotime($a['date_time']); });
                foreach ($val_exp as $row) {
                    fputcsv($out, [
                        date('M j, Y g:i A', strtotime($row['date_time'])),
                        $row['manager_name'], ucfirst($row['manager_role']),
                        $row['action'], $row['module'], $row['reference_id'],
                        $row['details'], $row['reason'] ?: '—', $row['encoded_by']
                    ]);
                }
            } catch (Exception $e) {}
            break;

        case 'audit_trail':
            fputcsv($out, ['AUDIT TRAIL REPORT']);
            fputcsv($out, ['Date & Time', 'User', 'Role', 'Action', 'Module', 'Details', 'IP Address', 'Status']);
            try {
                $s = $pdo->prepare("
                    SELECT al.created_at, al.action_type, al.action_details,
                           al.entity_type, al.status, al.ip_address,
                           u.name AS user_name, u.role AS user_role
                    FROM audit_logs al
                    LEFT JOIN users u ON u.id = al.user_id
                    WHERE al.user_id IS NOT NULL
                      AND DATE(al.created_at) BETWEEN ? AND ?
                      AND LOWER(TRIM(COALESCE(u.role,''))) NOT IN ('admin','superadmin','super admin','super_admin')
                      AND u.station_id = ?
                    ORDER BY al.created_at DESC
                    LIMIT 5000
                ");
                $s->execute([$date_start, $date_end, $station_id]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    fputcsv($out, [
                        date('M j, Y g:i A', strtotime($row['created_at'])),
                        $row['user_name'],
                        ucfirst($row['user_role']),
                        $row['action_type'],
                        $row['entity_type'] ?: '—',
                        $row['action_details'],
                        $row['ip_address'] ?: '—',
                        $row['status'] ?: '—',
                    ]);
                }
            } catch (Exception $e) {}
            break;
    }
    fclose($out);
    exit;
}

// ============================================================
// STATION NAME
// ============================================================
$station_name = 'Station';
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id=?");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: 'Station';
} catch (Exception $e) {}

// ============================================================
// DATA QUERIES — SECTION: sales
// ============================================================
$fuel_sales_data  = [];
$merch_sales_data = [];
$daily_summary_data = [];
if ($section === 'sales') {
    // ── Fuel Sales ────────────────────────────────────────────
    try {
        $has_vt = false;
        try { $pdo->query("SELECT 1 FROM fuel_variance_reports LIMIT 1"); $has_vt = true; } catch (Exception $e) {}

        // Include all non-rejected fuel transactions (Pending, Pending Validation, verified, approved, etc.)
        $fuel_status_clause = "LOWER(TRIM(COALESCE(ft.status,''))) NOT IN ('rejected','cancelled','voided','void')";

        if ($has_vt) {
            $s = $pdo->prepare("
                SELECT
                    DATE(ft.transaction_date)             AS sale_date,
                    ft.fuel_type,
                    COUNT(ft.transaction_id)              AS txn_count,
                    COALESCE(SUM(ft.liters_sold), 0)      AS total_liters,
                    COALESCE(SUM(ft.total_amount), 0)     AS total_revenue,
                    COALESCE(AVG(fvr.variance_liters), 0) AS avg_variance_liters
                FROM fuel_transactions ft
                LEFT JOIN fuel_variance_reports fvr
                    ON  fvr.station_id = ft.station_id
                    AND DATE(fvr.report_date) = DATE(ft.transaction_date)
                    AND LOWER(TRIM(fvr.fuel_type)) = LOWER(TRIM(ft.fuel_type))
                WHERE ft.station_id = ?
                    AND $fuel_status_clause
                    AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY DATE(ft.transaction_date), ft.fuel_type
                ORDER BY sale_date DESC, ft.fuel_type
            ");
        } else {
            $s = $pdo->prepare("
                SELECT
                    DATE(ft.transaction_date)         AS sale_date,
                    ft.fuel_type,
                    COUNT(ft.transaction_id)          AS txn_count,
                    COALESCE(SUM(ft.liters_sold), 0)  AS total_liters,
                    COALESCE(SUM(ft.total_amount), 0) AS total_revenue,
                    0                                 AS avg_variance_liters
                FROM fuel_transactions ft
                WHERE ft.station_id = ?
                    AND $fuel_status_clause
                    AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY DATE(ft.transaction_date), ft.fuel_type
                ORDER BY sale_date DESC, ft.fuel_type
            ");
        }
        $s->execute([$station_id, $date_start, $date_end]);
        $fuel_sales_data = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // ── Merchandise Sales ─────────────────────────────────────
    try {
        $merch_date_expr = "CASE WHEN mt.transaction_date > '2000-01-01' THEN DATE(mt.transaction_date) ELSE DATE(mt.created_at) END";
        $s = $pdo->prepare("
            SELECT
                ($merch_date_expr)                                                                                                                                                        AS sale_date,
                COUNT(mt.id)                                                                                                                                                              AS txn_count,
                COALESCE(SUM(mt.total_amount), 0)                                                                                                                                        AS total_revenue,
                COALESCE(SUM(CASE WHEN si.id IS NOT NULL THEN si.quantity ELSE 0 END), 0)                                                                                                AS total_quantity,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('cash')                                                                                    THEN mt.total_amount ELSE 0 END), 0) AS pay_cash,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('credit card','card','debit card')                                                         THEN mt.total_amount ELSE 0 END), 0) AS pay_card,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('gcash','maya','paymaya','e-wallet','ewallet')                                             THEN mt.total_amount ELSE 0 END), 0) AS pay_ewallet,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('e-fuel card','fuel card','efuel')                                                         THEN mt.total_amount ELSE 0 END), 0) AS pay_efuel,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('account receivable','credit','utang')                                                     THEN mt.total_amount ELSE 0 END), 0) AS pay_credit
            FROM merchandise_transactions mt
            LEFT JOIN sale_items si ON si.sale_id = mt.id
            WHERE mt.station_id = ?
                AND ($merch_date_expr) BETWEEN ? AND ?
                AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
            GROUP BY ($merch_date_expr)
            ORDER BY sale_date DESC
        ");
        $s->execute([$station_id, $date_start, $date_end]);
        $merch_sales_data = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // ── Daily Summary ─────────────────────────────────────────
    try {
        $has_vt2 = false;
        try { $pdo->query("SELECT 1 FROM fuel_variance_reports LIMIT 1"); $has_vt2 = true; } catch (Exception $e) {}

        $fs_clause = "LOWER(TRIM(COALESCE(ft.status,''))) NOT IN ('rejected','cancelled','voided','void')";
        $md_expr   = "CASE WHEN mt.transaction_date > '2000-01-01' THEN DATE(mt.transaction_date) ELSE DATE(mt.created_at) END";

        if ($has_vt2) {
            $s = $pdo->prepare("
                SELECT
                    d.sale_date,
                    COALESCE(f.fuel_liters, 0)                          AS total_fuel_liters,
                    COALESCE(f.fuel_rev, 0)                             AS fuel_revenue,
                    COALESCE(m.merch_rev, 0)                            AS merch_revenue,
                    COALESCE(f.fuel_rev, 0) + COALESCE(m.merch_rev, 0) AS total_revenue,
                    COALESCE(f.avg_variance, 0)                         AS fuel_variance
                FROM (
                    SELECT DISTINCT DATE(ft.transaction_date) AS sale_date
                    FROM fuel_transactions ft
                    WHERE ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ? AND $fs_clause
                    UNION
                    SELECT DISTINCT ($md_expr)
                    FROM merchandise_transactions mt
                    WHERE mt.station_id = ? AND ($md_expr) BETWEEN ? AND ?
                        AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
                ) d
                LEFT JOIN (
                    SELECT DATE(ft.transaction_date) AS sale_date,
                           SUM(ft.liters_sold)       AS fuel_liters,
                           SUM(ft.total_amount)      AS fuel_rev,
                           AVG(fvr.variance_liters)  AS avg_variance
                    FROM fuel_transactions ft
                    LEFT JOIN fuel_variance_reports fvr
                        ON fvr.station_id = ft.station_id
                        AND DATE(fvr.report_date) = DATE(ft.transaction_date)
                        AND LOWER(TRIM(fvr.fuel_type)) = LOWER(TRIM(ft.fuel_type))
                    WHERE ft.station_id = ? AND $fs_clause
                    GROUP BY DATE(ft.transaction_date)
                ) f ON f.sale_date = d.sale_date
                LEFT JOIN (
                    SELECT ($md_expr) AS sale_date,
                           SUM(mt.total_amount) AS merch_rev
                    FROM merchandise_transactions mt
                    WHERE mt.station_id = ?
                        AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
                    GROUP BY ($md_expr)
                ) m ON m.sale_date = d.sale_date
                ORDER BY d.sale_date DESC
            ");
            $s->execute([$station_id, $date_start, $date_end, $station_id, $date_start, $date_end, $station_id, $station_id]);
        } else {
            $s = $pdo->prepare("
                SELECT
                    d.sale_date,
                    COALESCE(f.fuel_liters, 0)                          AS total_fuel_liters,
                    COALESCE(f.fuel_rev, 0)                             AS fuel_revenue,
                    COALESCE(m.merch_rev, 0)                            AS merch_revenue,
                    COALESCE(f.fuel_rev, 0) + COALESCE(m.merch_rev, 0) AS total_revenue,
                    0                                                   AS fuel_variance
                FROM (
                    SELECT DISTINCT DATE(ft.transaction_date) AS sale_date
                    FROM fuel_transactions ft
                    WHERE ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ? AND $fs_clause
                    UNION
                    SELECT DISTINCT ($md_expr)
                    FROM merchandise_transactions mt
                    WHERE mt.station_id = ? AND ($md_expr) BETWEEN ? AND ?
                        AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
                ) d
                LEFT JOIN (
                    SELECT DATE(ft.transaction_date) AS sale_date,
                           SUM(ft.liters_sold)       AS fuel_liters,
                           SUM(ft.total_amount)      AS fuel_rev
                    FROM fuel_transactions ft
                    WHERE ft.station_id = ? AND $fs_clause
                    GROUP BY DATE(ft.transaction_date)
                ) f ON f.sale_date = d.sale_date
                LEFT JOIN (
                    SELECT ($md_expr) AS sale_date,
                           SUM(mt.total_amount) AS merch_rev
                    FROM merchandise_transactions mt
                    WHERE mt.station_id = ?
                        AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
                    GROUP BY ($md_expr)
                ) m ON m.sale_date = d.sale_date
                ORDER BY d.sale_date DESC
            ");
            $s->execute([$station_id, $date_start, $date_end, $station_id, $date_start, $date_end, $station_id, $station_id]);
        }
        $daily_summary_data = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}
}

// ============================================================
// DATA QUERIES — SECTION: job_orders
// ============================================================
$jo_rows = [];
if ($section === 'job_orders') {
    try {
        $s = $pdo->prepare("
            SELECT
                COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-', jo.id)) AS jo_ref,
                COALESCE(jo.customer_name, c.name, 'Walk-in')                         AS customer,
                COALESCE(jo.vehicle_plate, '—')                                        AS vehicle_plate,
                COALESCE(jo.vehicle_type, '—')                                         AS vehicle_type,
                COALESCE(jo.service_type, jo.service_description, '—')                AS service_type,
                COALESCE(staff.name, '—')                                              AS assigned_staff,
                COALESCE(mech.name, '—')                                               AS mechanic,
                COALESCE(jo.status, '—')                                               AS jo_status,
                COALESCE(jo.validation_status, 'Pending Validation')                  AS validation_status,
                COALESCE(jo.payment_method, '—')                                       AS payment_method,
                COALESCE(jo.payment_status, '—')                                       AS payment_status,
                COALESCE(jo.estimated_cost, 0)                                         AS estimated_cost,
                COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0)             AS labor_cost,
                COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0)             AS parts_cost,
                COALESCE(jo.total_cost, jo.estimated_cost, 0)                          AS total_cost,
                COALESCE(jo.amount_paid, 0)                                            AS amount_paid,
                jo.created_at,
                jo.validated_at,
                COALESCE(validator.name, '—')                                          AS validated_by_name,
                jo.adjustment_reason
            FROM job_orders jo
            LEFT JOIN customers c       ON c.id    = jo.customer_id
            LEFT JOIN users staff       ON staff.id = jo.created_by
            LEFT JOIN users mech        ON mech.id  = jo.assigned_mechanic_id
            LEFT JOIN users validator   ON validator.id = jo.validated_by
            WHERE jo.station_id = ?
              AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC
        ");
        $s->execute([$station_id, $date_start, $date_end]);
        $jo_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}
}

// ============================================================
// DATA QUERIES — SECTION: balances
// ============================================================
$balance_rows      = [];
$balance_jo_rows   = [];   // individual credit job orders
$balance_mt_rows   = [];   // individual credit merchandise transactions
if ($section === 'balances') {

    // ── 1. Customers with current_balance > 0 (direct balance on customer record)
    try {
        $s = $pdo->prepare("
            SELECT
                c.id,
                c.name,
                COALESCE(c.credit_limit, 0)     AS credit_limit,
                COALESCE(c.current_balance, c.balance, 0) AS outstanding,
                c.type,
                c.status,
                c.contact_number,
                c.email
            FROM customers c
            WHERE c.station_id = ?
              AND COALESCE(c.current_balance, c.balance, 0) > 0
            ORDER BY outstanding DESC
        ");
        $s->execute([$station_id]);
        $balance_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // ── 2. Credit job orders (unpaid) — grouped by customer_name since customer_id may be 0
    try {
        $s = $pdo->prepare("
            SELECT
                COALESCE(jo.customer_name, 'Walk-in')                          AS customer_name,
                COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-',jo.id)) AS jo_ref,
                jo.service_type,
                COALESCE(jo.estimated_cost, jo.total_cost, 0)                  AS total_cost,
                COALESCE(jo.amount_paid, 0)                                    AS amount_paid,
                COALESCE(jo.estimated_cost, jo.total_cost, 0)
                    - COALESCE(jo.amount_paid, 0)                              AS balance_due,
                jo.payment_status,
                jo.validation_status,
                jo.created_at
            FROM job_orders jo
            WHERE jo.station_id = ?
              AND LOWER(TRIM(jo.payment_method)) IN ('credit','account receivable','utang')
              AND LOWER(TRIM(COALESCE(jo.payment_status,''))) NOT IN ('paid','fully paid')
            ORDER BY jo.created_at DESC
        ");
        $s->execute([$station_id]);
        $balance_jo_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // ── 3. Credit merchandise transactions (unpaid)
    try {
        $s = $pdo->prepare("
            SELECT
                COALESCE(mt.customer_name, 'Walk-in')  AS customer_name,
                mt.transaction_id                       AS txn_ref,
                mt.total_amount,
                mt.payment_method,
                mt.validation_status,
                COALESCE(mt.transaction_date, mt.created_at) AS txn_date
            FROM merchandise_transactions mt
            WHERE mt.station_id = ?
              AND LOWER(TRIM(mt.payment_method)) IN ('credit','account receivable','utang')
              AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','cancelled')
            ORDER BY txn_date DESC
        ");
        $s->execute([$station_id]);
        $balance_mt_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}
}

// ============================================================
// DATA QUERIES — SECTION: deliveries
// ============================================================
$delivery_rows      = [];
$fuel_delivery_rows = [];
if ($section === 'deliveries') {

    // ── deliveries_oversight (merchandise + fuel DR entries) ──
    try {
        $s = $pdo->prepare("
            SELECT
                COALESCE(d.delivery_ref, CONCAT('DEL-', d.id))  AS delivery_id,
                COALESCE(d.supplier, 'Unknown Supplier')          AS supplier_name,
                COALESCE(d.product, 'Unknown Product')            AS product_name,
                COALESCE(d.quantity, 0)                           AS quantity_delivered,
                COALESCE(d.unit, 'pcs')                           AS unit_type,
                COALESCE(d.delivery_date, DATE(d.created_at))     AS delivery_date,
                COALESCE(u.name, 'Unknown Staff')                 AS encoded_by,
                COALESCE(d.dr_number, '—')                        AS dr_number,
                d.status,
                COALESCE(d.admin_notes, d.remarks, '')            AS remarks,
                CASE
                    WHEN d.delivery_type = 'fuel'        THEN 'Fuel'
                    WHEN d.delivery_type = 'merchandise' THEN 'Merchandise'
                    ELSE COALESCE(d.delivery_type, 'General')
                END AS delivery_type,
                d.created_at
            FROM deliveries_oversight d
            LEFT JOIN users u ON u.id = d.encoded_by
            WHERE d.station_id = ?
              AND DATE(COALESCE(d.delivery_date, d.created_at)) BETWEEN ? AND ?
            ORDER BY
                CASE
                    WHEN LOWER(d.status) = 'pending'                          THEN 1
                    WHEN LOWER(d.status) IN ('confirmed','approved','validated') THEN 2
                    WHEN LOWER(d.status) IN ('rejected','flagged','returned')  THEN 3
                    ELSE 4
                END,
                COALESCE(d.delivery_date, DATE(d.created_at)) DESC
        ");
        $s->execute([$station_id, $date_start, $date_end]);
        $delivery_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // ── fuel_deliveries (tanker deliveries) — no strict date filter, show all ──
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('FD-', fd.id)                              AS delivery_id,
                COALESCE(fd.supplier, 'Petron Corporation')        AS supplier_name,
                COALESCE(fd.fuel_type, 'Fuel')                    AS product_name,
                COALESCE(fd.delivery_liters, 0)                   AS quantity_delivered,
                'Liters'                                           AS unit_type,
                fd.delivery_date,
                COALESCE(recv.name, 'Unknown Staff')               AS encoded_by,
                COALESCE(fd.invoice_no, '—')                      AS dr_number,
                fd.status,
                COALESCE(fd.notes, '')                             AS remarks,
                'Fuel Delivery'                                    AS delivery_type,
                fd.created_at
            FROM fuel_deliveries fd
            LEFT JOIN users recv ON recv.id = fd.received_by
            WHERE fd.station_id = ?
              AND fd.delivery_date BETWEEN ? AND ?
            ORDER BY fd.delivery_date DESC
        ");
        // Use wider range for fuel deliveries — show last 6 months if current range has none
        $s->execute([$station_id, $date_start, $date_end]);
        $fuel_delivery_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fallback: if no fuel deliveries in range, show all for this station
        if (empty($fuel_delivery_rows)) {
            $s2 = $pdo->prepare("
                SELECT
                    CONCAT('FD-', fd.id)                              AS delivery_id,
                    COALESCE(fd.supplier, 'Petron Corporation')        AS supplier_name,
                    COALESCE(fd.fuel_type, 'Fuel')                    AS product_name,
                    COALESCE(fd.delivery_liters, 0)                   AS quantity_delivered,
                    'Liters'                                           AS unit_type,
                    fd.delivery_date,
                    COALESCE(recv.name, 'Unknown Staff')               AS encoded_by,
                    COALESCE(fd.invoice_no, '—')                      AS dr_number,
                    fd.status,
                    COALESCE(fd.notes, '')                             AS remarks,
                    'Fuel Delivery'                                    AS delivery_type,
                    fd.created_at
                FROM fuel_deliveries fd
                LEFT JOIN users recv ON recv.id = fd.received_by
                WHERE fd.station_id = ?
                ORDER BY fd.delivery_date DESC
                LIMIT 50
            ");
            $s2->execute([$station_id]);
            $fuel_delivery_rows = $s2->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Exception $e) {}
}

// ============================================================
// DATA QUERIES — SECTION: staff
// ============================================================
$staff_performance = [];
$attendance_rows   = [];
if ($section === 'staff') {
    // ── Performance summary per staff ────────────────────────
    try {
        $s = $pdo->prepare("
            SELECT
                u.id   AS staff_id,
                u.name AS staff_name,
                u.role,
                COALESCE(ft.fuel_cnt,  0) AS fuel_transactions,
                COALESCE(mt.merch_cnt, 0) AS merch_transactions,
                COALESCE(ft.fuel_cnt, 0) + COALESCE(mt.merch_cnt, 0) AS total_transactions,
                COALESCE(jo.jo_cnt,    0) AS job_orders_encoded,
                COALESCE(dv.dv_cnt,    0) AS deliveries_encoded,
                COALESCE(ls.total_hours,    0) AS total_hours,
                COALESCE(ls.shift_count,    0) AS shift_count,
                COALESCE(ls.attendance_days,0) AS attendance_days
            FROM users u
            LEFT JOIN (
                SELECT staff_id, COUNT(*) AS fuel_cnt
                FROM fuel_transactions
                WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
                GROUP BY staff_id
            ) ft ON ft.staff_id = u.id
            LEFT JOIN (
                SELECT staff_id, COUNT(*) AS merch_cnt
                FROM merchandise_transactions
                WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
                GROUP BY staff_id
            ) mt ON mt.staff_id = u.id
            LEFT JOIN (
                SELECT created_by, COUNT(*) AS jo_cnt
                FROM job_orders
                WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?
                GROUP BY created_by
            ) jo ON jo.created_by = u.id
            LEFT JOIN (
                SELECT encoded_by, COUNT(*) AS dv_cnt
                FROM deliveries_oversight
                WHERE station_id = ? AND DATE(COALESCE(delivery_date, created_at)) BETWEEN ? AND ?
                GROUP BY encoded_by
            ) dv ON dv.encoded_by = u.id
            LEFT JOIN (
                SELECT user_id,
                       COALESCE(SUM(hours_worked), 0)          AS total_hours,
                       COUNT(*)                                 AS shift_count,
                       COUNT(DISTINCT DATE(start_time))         AS attendance_days
                FROM labor_sessions
                WHERE station_id = ? AND DATE(start_time) BETWEEN ? AND ?
                GROUP BY user_id
            ) ls ON ls.user_id = u.id
            WHERE u.station_id = ?
              AND u.status IN ('active','Active')
            ORDER BY
                (COALESCE(ft.fuel_cnt,0) + COALESCE(mt.merch_cnt,0) + COALESCE(jo.jo_cnt,0)) DESC,
                u.name ASC
        ");
        $s->execute([
            $station_id, $date_start, $date_end,   // fuel
            $station_id, $date_start, $date_end,   // merch
            $station_id, $date_start, $date_end,   // jo
            $station_id, $date_start, $date_end,   // deliveries
            $station_id, $date_start, $date_end,   // labor_sessions
            $station_id                            // users
        ]);
        $staff_performance = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // ── Attendance / shift logs ───────────────────────────────
    // labor_sessions has: user_id, station_id, start_time, end_time, hours_worked, shift_period, shift_name
    try {
        $s = $pdo->prepare("
            SELECT
                u.name                                          AS staff_name,
                u.role,
                ls.start_time,
                ls.end_time,
                COALESCE(ls.hours_worked, 0)                   AS hours_worked,
                COALESCE(ls.shift_name, ls.shift_period, '—')  AS shift_label
            FROM labor_sessions ls
            LEFT JOIN users u ON u.id = ls.user_id
            WHERE ls.station_id = ?
              AND DATE(ls.start_time) BETWEEN ? AND ?
            ORDER BY ls.start_time DESC
        ");
        $s->execute([$station_id, $date_start, $date_end]);
        $attendance_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}
}

// ============================================================
// DATA QUERIES — SECTION: validation
// ============================================================
$validation_rows = [];
if ($section === 'validation') {

    // ── 1. Job Order validations (primary source — has validated_at) ──
    try {
        $s = $pdo->prepare("
            SELECT
                jo.validated_at                                                    AS date_time,
                COALESCE(u.name, 'Unknown Manager')                                AS manager_name,
                COALESCE(u.role, 'manager')                                        AS manager_role,
                COALESCE(jo.validation_status, 'Validated')                        AS action,
                'Job Order'                                                        AS module,
                COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-',jo.id)) AS reference_id,
                CONCAT(
                    COALESCE(jo.service_type, jo.service_description, 'Service'),
                    ' — ', COALESCE(jo.customer_name, c.name, 'Walk-in')
                )                                                                  AS details,
                COALESCE(jo.adjustment_reason, jo.admin_remarks, '')               AS reason,
                COALESCE(jo.amount_paid, 0)                                        AS amount,
                COALESCE(staff.name, 'Unknown Staff')                              AS encoded_by
            FROM job_orders jo
            LEFT JOIN users u     ON u.id    = jo.validated_by
            LEFT JOIN customers c ON c.id    = jo.customer_id
            LEFT JOIN users staff ON staff.id = jo.created_by
            WHERE jo.station_id = ?
              AND jo.validated_at IS NOT NULL
              AND DATE(jo.validated_at) BETWEEN ? AND ?
            ORDER BY jo.validated_at DESC
        ");
        $s->execute([$station_id, $date_start, $date_end]);
        $jo_val = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { $jo_val = []; }

    // ── 2. Merchandise transaction validations ────────────────
    try {
        $s = $pdo->prepare("
            SELECT
                mt.validated_at                                                    AS date_time,
                COALESCE(u.name, 'Unknown Manager')                                AS manager_name,
                COALESCE(u.role, 'manager')                                        AS manager_role,
                COALESCE(mt.validation_status, 'Validated')                        AS action,
                'Merchandise'                                                      AS module,
                mt.transaction_id                                                  AS reference_id,
                CONCAT(
                    'Merchandise sale — ',
                    COALESCE(mt.customer_name, 'Walk-in'),
                    ' (', mt.payment_method, ')'
                )                                                                  AS details,
                COALESCE(mt.rejection_reason, mt.adjustment_reason, '')            AS reason,
                COALESCE(mt.total_amount, 0)                                       AS amount,
                COALESCE(staff.name, 'Unknown Staff')                              AS encoded_by
            FROM merchandise_transactions mt
            LEFT JOIN users u     ON u.id    = mt.validated_by
            LEFT JOIN users staff ON staff.id = mt.staff_id
            WHERE mt.station_id = ?
              AND mt.validated_at IS NOT NULL
              AND mt.validation_status IN ('Approved','Rejected','Adjusted')
              AND DATE(mt.validated_at) BETWEEN ? AND ?
            ORDER BY mt.validated_at DESC
        ");
        $s->execute([$station_id, $date_start, $date_end]);
        $mt_val = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { $mt_val = []; }

    // ── 3. Delivery validations ───────────────────────────────
    try {
        $s = $pdo->prepare("
            SELECT
                d.admin_action_at                                                  AS date_time,
                COALESCE(u.name, 'Unknown Manager')                                AS manager_name,
                COALESCE(u.role, 'manager')                                        AS manager_role,
                d.status                                                           AS action,
                'Delivery'                                                         AS module,
                COALESCE(d.delivery_ref, CONCAT('DEL-', d.id))                    AS reference_id,
                CONCAT(
                    COALESCE(d.product, d.delivery_type, 'Delivery'),
                    ' from ', COALESCE(d.supplier, 'Unknown Supplier')
                )                                                                  AS details,
                COALESCE(d.admin_notes, d.remarks, '')                             AS reason,
                COALESCE(d.quantity, 0)                                            AS amount,
                COALESCE(enc.name, 'Unknown Staff')                                AS encoded_by
            FROM deliveries_oversight d
            LEFT JOIN users u   ON u.id   = d.admin_id
            LEFT JOIN users enc ON enc.id = d.encoded_by
            WHERE d.station_id = ?
              AND d.admin_id IS NOT NULL
              AND d.admin_action_at IS NOT NULL
              AND DATE(d.admin_action_at) BETWEEN ? AND ?
            ORDER BY d.admin_action_at DESC
        ");
        $s->execute([$station_id, $date_start, $date_end]);
        $dv_val = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { $dv_val = []; }

    // ── Merge all sources, sort by date desc ──────────────────
    $validation_rows = array_merge($jo_val, $mt_val, $dv_val);
    usort($validation_rows, function($a, $b) {
        return strtotime($b['date_time']) - strtotime($a['date_time']);
    });

    // ── Fallback: if date range has no validated records, show ALL for station ─
    if (empty($validation_rows)) {
        try {
            $s = $pdo->prepare("
                SELECT jo.validated_at AS date_time,
                    COALESCE(u.name,'Unknown Manager') AS manager_name,
                    COALESCE(u.role,'manager') AS manager_role,
                    COALESCE(jo.validation_status,'Validated') AS action,
                    'Job Order' AS module,
                    COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-',jo.id)) AS reference_id,
                    CONCAT(COALESCE(jo.service_type,'Service'),' — ',COALESCE(jo.customer_name,'Walk-in')) AS details,
                    COALESCE(jo.adjustment_reason, jo.admin_remarks,'') AS reason,
                    COALESCE(jo.amount_paid,0) AS amount,
                    COALESCE(staff.name,'Unknown Staff') AS encoded_by
                FROM job_orders jo
                LEFT JOIN users u ON u.id=jo.validated_by
                LEFT JOIN users staff ON staff.id=jo.created_by
                WHERE jo.station_id=? AND jo.validated_at IS NOT NULL
                ORDER BY jo.validated_at DESC LIMIT 50
            ");
            $s->execute([$station_id]);
            $validation_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {}
    }
}

// ============================================================
// DATA QUERIES — SECTION: audit_trail
// ============================================================
$audit_trail_rows  = [];
$audit_trail_users = [];
if ($section === 'audit_trail') {
    // Fetch staff users for filter dropdown (exclude admin/superadmin)
    try {
        $us = $pdo->prepare(
            "SELECT id, name, role FROM users
             WHERE station_id = ?
               AND status = 'active'
               AND LOWER(TRIM(role)) NOT IN ('admin','superadmin','super admin','super_admin')
             ORDER BY role, name"
        );
        $us->execute([$station_id]);
        $audit_trail_users = $us->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Optional filters from GET
    $at_user   = isset($_GET['at_user'])   ? (int)$_GET['at_user']              : 0;
    $at_action = isset($_GET['at_action']) ? trim($_GET['at_action'])            : '';
    $at_module = isset($_GET['at_module']) ? trim($_GET['at_module'])            : '';

    // Build WHERE clauses
    $at_where  = "WHERE al.user_id IS NOT NULL
                    AND DATE(al.created_at) BETWEEN ? AND ?
                    AND LOWER(TRIM(COALESCE(u.role,''))) NOT IN ('admin','superadmin','super admin','super_admin')
                    AND u.station_id = ?";
    $at_params = [$date_start, $date_end, $station_id];

    if ($at_user > 0) {
        $at_where  .= " AND al.user_id = ?";
        $at_params[] = $at_user;
    }
    if ($at_action !== '') {
        $at_where  .= " AND al.action_type = ?";
        $at_params[] = $at_action;
    }
    if ($at_module !== '') {
        $at_where  .= " AND al.entity_type = ?";
        $at_params[] = $at_module;
    }

    try {
        $s = $pdo->prepare("
            SELECT al.id, al.created_at, al.action_type, al.action_details,
                   al.entity_type, al.entity_id, al.status, al.ip_address,
                   u.name AS user_name, u.role AS user_role
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            $at_where
            ORDER BY al.created_at DESC
            LIMIT 500
        ");
        $s->execute($at_params);
        $audit_trail_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        // audit_logs table may not exist yet — silently fall back to empty
        $audit_trail_rows = [];
    }
}

// ============================================================
// CHART DATA — JSON-encode for JS
// ============================================================
// Sales chart data removed - now displaying in table format

// JO chart data removed - now displaying in table format

// Balances chart data removed - now displaying in table format

// Deliveries chart data removed - now displaying in table format

// Staff chart data removed - now displaying in table format

// Validation line — daily approved vs rejected
$val_daily_map_appr = [];
$val_daily_map_rej  = [];
if ($section === 'validation') {
    foreach ($validation_rows as $vr) {
        $vd = date('Y-m-d', strtotime($vr['date_time'] ?? ''));
        $action_lower = strtolower($vr['action'] ?? '');
        if (in_array($action_lower, ['approved','approve','confirmed','confirm'])) {
            $val_daily_map_appr[$vd] = ($val_daily_map_appr[$vd] ?? 0) + 1;
        } elseif (in_array($action_lower, ['rejected','reject'])) {
            $val_daily_map_rej[$vd] = ($val_daily_map_rej[$vd] ?? 0) + 1;
        }
    }
}
$chart_val_labels  = [];
$chart_val_appr    = [];
$chart_val_rej     = [];
if ($section === 'validation') {
    $cur = new DateTime($date_start);
    $end_dt = new DateTime($date_end);
    while ($cur <= $end_dt) {
        $d = $cur->format('Y-m-d');
        $chart_val_labels[] = date('M j', strtotime($d));
        $chart_val_appr[]   = $val_daily_map_appr[$d] ?? 0;
        $chart_val_rej[]    = $val_daily_map_rej[$d]  ?? 0;
        $cur->modify('+1 day');
    }
}

// Helper: build export URL preserving current filters
function export_url(string $sec, string $range, string $start, string $end): string {
    $q = http_build_query(['section' => $sec, 'range' => $range, 'start' => $start, 'end' => $end, 'export' => 'csv']);
    return 'manager_reports.php?' . $q;
}

// Helper: status badge class
function status_badge_class(string $status): string {
    $s = strtolower($status);
    if (str_contains($s, 'pending'))   return 'badge-pending';
    if (str_contains($s, 'approved') || str_contains($s, 'validated') || str_contains($s, 'confirmed')) return 'badge-approved';
    if (str_contains($s, 'progress'))  return 'badge-inprog';
    if (str_contains($s, 'completed')) return 'badge-completed';
    if (str_contains($s, 'rejected') || str_contains($s, 'cancelled') || str_contains($s, 'flagged')) return 'badge-rejected';
    return 'badge-default';
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
/* ============================================================
   Reports — Styles
   ============================================================ */
:root {
    --petron-blue: #00264D;
    --petron-red:  #CC0000;
    --success:     #22c55e;
    --warning:     #f59e0b;
    --info:        #3b82f6;
    --purple:      #8b5cf6;
}

/* Page head */
.page-head { margin-bottom: 24px; }
.page-head .h1 { font-size: 26px; font-weight: 800; color: var(--petron-blue); margin: 0 0 4px; letter-spacing: -.3px; }
.page-head .sub { font-size: 13px; color: #667085; }

/* Tab navigation - REMOVED */

/* Date range filter bar */
.rpt-filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: #fff; border: 1px solid #EAEAEA; border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
.rpt-filter-bar label { font-size: 12px; font-weight: 600; color: #667085; text-transform: uppercase; letter-spacing: .4px; }
.range-btn { padding: 6px 14px; border-radius: 6px; border: 1px solid #EAEAEA; background: #f8fafc; font-size: 12px; font-weight: 600; color: #374151; cursor: pointer; text-decoration: none; transition: .15s; }
.range-btn:hover { background: #e8f0f8; border-color: var(--petron-blue); color: var(--petron-blue); }
.range-btn.active { background: var(--petron-blue); color: #fff; border-color: var(--petron-blue); }
.rpt-filter-bar input[type="date"] { padding: 6px 10px; border: 1px solid #EAEAEA; border-radius: 6px; font-size: 12px; color: #374151; background: #f8fafc; }
.rpt-filter-bar .btn-apply { padding: 6px 16px; background: var(--petron-red); color: #fff; border: none; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; transition: .15s; }
.rpt-filter-bar .btn-apply:hover { background: #a80000; }
.rpt-filter-bar .btn-export { padding: 6px 14px; background: #fff; color: var(--petron-blue); border: 1px solid var(--petron-blue); border-radius: 6px; font-size: 12px; font-weight: 700; text-decoration: none; transition: .15s; }
.rpt-filter-bar .btn-export:hover { background: var(--petron-blue); color: #fff; }
.rpt-filter-bar .export-buttons { display: flex; gap: 8px; margin-left: auto; }
#custom-range-inputs { display: flex; align-items: center; gap: 8px; }

/* Stat cards */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
.stat-card { background: #fff; border-radius: 12px; border: 1px solid #EAEAEA; padding: 16px 18px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 4px rgba(0,0,0,.04); border-left: 4px solid #EAEAEA; }
.stat-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.stat-body .stat-num  { font-size: 22px; font-weight: 800; color: #101828; line-height: 1.1; }
.stat-body .stat-label{ font-size: 11px; font-weight: 600; color: #667085; text-transform: uppercase; letter-spacing: .4px; margin-top: 2px; }
.stat-blue   { border-left-color: var(--petron-blue); } .stat-blue   .stat-icon { background: #e8f0f8; color: var(--petron-blue); }
.stat-red    { border-left-color: var(--petron-red);  } .stat-red    .stat-icon { background: #fee2e2; color: var(--petron-red); }
.stat-green  { border-left-color: #22c55e; }            .stat-green  .stat-icon { background: #dcfce7; color: #16a34a; }
.stat-orange { border-left-color: #f59e0b; }            .stat-orange .stat-icon { background: #fef3c7; color: #d97706; }
.stat-purple { border-left-color: #8b5cf6; }            .stat-purple .stat-icon { background: #ede9fe; color: #7c3aed; }
.stat-teal   { border-left-color: #14b8a6; }            .stat-teal   .stat-icon { background: #ccfbf1; color: #0d9488; }

/* Section card */
.rpt-card { background: #fff; border-radius: 14px; border: 1px solid #EAEAEA; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.04); margin-bottom: 20px; }
.rpt-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.rpt-card-head h3 { font-size: 15px; font-weight: 700; color: var(--petron-blue); margin: 0; display: flex; align-items: center; gap: 8px; }
.rpt-card-head .badge-count { background: var(--petron-blue); color: #fff; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 20px; }

/* Charts grid */
.charts-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
@media(max-width: 900px) { .charts-grid-2 { grid-template-columns: 1fr; } }
.chart-wrap    { position: relative; height: 240px; }
.chart-wrap-sm { position: relative; height: 200px; }

/* Tables */
.mgr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mgr-table thead tr { background: #f8fafc; border-bottom: 2px solid #EAEAEA; }
.mgr-table th { text-align: left; padding: 10px 12px; font-size: 11px; font-weight: 700; color: #667085; text-transform: uppercase; letter-spacing: .4px; white-space: nowrap; }
.mgr-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #374151; vertical-align: middle; }
.mgr-table tbody tr:hover { background: #f8fafc; }
.mgr-table tbody tr:last-child td { border-bottom: none; }
.table-scroll { overflow-x: auto; }

/* Badges */
.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.badge-pending   { background: #fef3c7; color: #92400e; }
.badge-approved  { background: #dcfce7; color: #166534; }
.badge-inprog    { background: #dbeafe; color: #1e40af; }
.badge-completed { background: #d1fae5; color: #065f46; }
.badge-rejected  { background: #fee2e2; color: #991b1b; }
.badge-cancelled { background: #f3f4f6; color: #374151; }
.badge-default   { background: #f3f4f6; color: #374151; }

/* Empty state */
.empty-state { text-align: center; padding: 48px 20px; color: #9ca3af; }
.empty-state i { font-size: 40px; margin-bottom: 12px; display: block; opacity: .4; }
.empty-state p { font-size: 14px; margin: 0; }

/* Progress bar (balances) */
.progress-bar-wrap { background: #f1f5f9; border-radius: 4px; height: 8px; overflow: hidden; min-width: 80px; }
.progress-bar-fill { height: 100%; border-radius: 4px; background: var(--petron-red); transition: width .3s; }

@media(max-width: 768px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="page-content">
    <div class="page-head">
        <h1 class="h1"><i class="fa-solid fa-chart-bar" style="color:var(--petron-red);margin-right:8px;"></i>REPORTS</h1>
    </div>


    <!-- DATE RANGE FILTER BAR -->
    <form method="GET" action="manager_reports.php" class="rpt-filter-bar" id="filter-form">
        <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
        <label>Period:</label>
        <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'custom' => 'Custom'] as $r => $label): ?>
        <a href="manager_reports.php?<?= http_build_query(['section' => $section, 'range' => $r, 'start' => $date_start, 'end' => $date_end]) ?>"
           class="range-btn<?= $range === $r ? ' active' : '' ?>"
           onclick="if('<?= $r ?>'==='custom'){document.getElementById('custom-range-inputs').style.display='flex';return false;}else{document.getElementById('custom-range-inputs').style.display='none';}">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
        <div id="custom-range-inputs" style="display:<?= $range === 'custom' ? 'flex' : 'none' ?>;">
            <input type="hidden" name="range" value="custom" id="range-hidden">
            <input type="date" name="start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>">
            <span style="color:#9ca3af;font-size:12px;">to</span>
            <input type="date" name="end"   value="<?= htmlspecialchars($date_end) ?>"   max="<?= $today ?>">
            <button type="submit" class="btn-apply"><i class="fa-solid fa-filter"></i> Apply</button>
        </div>
        <?php
        $exp_base = 'manager_report_export.php?' . http_build_query(['section'=>$section,'range'=>$range,'start'=>$date_start,'end'=>$date_end]);
        ?>
        <div class="export-buttons">
            <a href="<?= $exp_base ?>&format=csv" class="btn-export" style="background:#374151;color:#fff;border-color:#374151;">
                <i class="fa-solid fa-download"></i> CSV
            </a>
            <a href="<?= $exp_base ?>&format=excel" class="btn-export" style="background:#22c55e;color:#fff;border-color:#22c55e;">
                <i class="fa-solid fa-file-excel"></i> Excel
            </a>
            <a href="<?= $exp_base ?>&format=pdf" class="btn-export" style="background:#dc3545;color:#fff;border-color:#dc3545;" target="_blank">
                <i class="fa-solid fa-file-pdf"></i> PDF
            </a>
        </div>
    </form>


    <!-- ============================================================
         SECTION: SALES
         ============================================================ -->
    <?php if ($section === 'sales'): ?>
    
    <!-- ── FUEL SALES REPORT ─────────────────────────────────── -->
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-gas-pump"></i> Fuel Sales Report <span class="badge-count"><?= count($fuel_sales_data) ?></span></h3>
        </div>
        <?php if (empty($fuel_sales_data)): ?>
        <div class="empty-state"><i class="fa-solid fa-gas-pump"></i><p>No fuel sales data for this period.</p></div>
        <?php else:
            // Pre-compute totals per fuel type
            $fuel_type_totals = [];
            $grand_fuel_liters = 0;
            $grand_fuel_revenue = 0;
            foreach ($fuel_sales_data as $r) {
                $ft = $r['fuel_type'];
                if (!isset($fuel_type_totals[$ft])) $fuel_type_totals[$ft] = ['txns'=>0,'liters'=>0,'revenue'=>0,'variance'=>0];
                $fuel_type_totals[$ft]['txns']    += (int)$r['txn_count'];
                $fuel_type_totals[$ft]['liters']  += (float)$r['total_liters'];
                $fuel_type_totals[$ft]['revenue'] += (float)$r['total_revenue'];
                $fuel_type_totals[$ft]['variance']+= (float)$r['avg_variance_liters'];
                $grand_fuel_liters  += (float)$r['total_liters'];
                $grand_fuel_revenue += (float)$r['total_revenue'];
            }
        ?>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Date</th>
                    <th>Fuel Type</th>
                    <th>Transactions</th>
                    <th>Liters Sold</th>
                    <th>Revenue</th>
                    <th>Variance vs Pump Readings</th>
                </tr></thead>
                <tbody>
                <?php foreach ($fuel_sales_data as $row):
                    $variance = (float)$row['avg_variance_liters'];
                    $var_class = abs($variance) > 0.5 ? 'badge-rejected' : 'badge-approved';
                    $var_label = abs($variance) > 0.5 ? number_format($variance, 4).' L ⚠' : (abs($variance) > 0 ? number_format($variance, 4).' L' : '—');
                ?>
                <tr>
                    <td><?= htmlspecialchars(date('M j, Y', strtotime($row['sale_date']))) ?></td>
                    <td><strong><?= htmlspecialchars($row['fuel_type']) ?></strong></td>
                    <td><?= number_format((int)$row['txn_count']) ?></td>
                    <td><?= number_format((float)$row['total_liters'], 2) ?> L</td>
                    <td>&#8369;<?= number_format((float)$row['total_revenue'], 2) ?></td>
                    <td><span class="badge <?= $var_class ?>"><?= $var_label ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr style="font-weight:700;background:#f0f4f8;border-top:2px solid #EAEAEA;">
                    <td colspan="2">TOTAL</td>
                    <td><?= number_format(array_sum(array_column($fuel_sales_data,'txn_count'))) ?></td>
                    <td><?= number_format($grand_fuel_liters, 2) ?> L</td>
                    <td>&#8369;<?= number_format($grand_fuel_revenue, 2) ?></td>
                    <td>—</td>
                </tr>
                </tfoot>
            </table>
        </div>

        <!-- Fuel Type Summary -->
        <?php if (count($fuel_type_totals) > 1): ?>
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #EAEAEA;">
            <h4 style="font-size:14px;font-weight:700;color:var(--petron-blue);margin-bottom:12px;">Summary by Fuel Type</h4>
            <div class="table-scroll">
                <table class="mgr-table">
                    <thead><tr>
                        <th>Fuel Type</th>
                        <th>Transactions</th>
                        <th>Total Liters</th>
                        <th>Total Revenue</th>
                        <th>Avg Variance</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($fuel_type_totals as $ft => $t): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($ft) ?></strong></td>
                        <td><?= number_format($t['txns']) ?></td>
                        <td><?= number_format($t['liters'], 2) ?> L</td>
                        <td>&#8369;<?= number_format($t['revenue'], 2) ?></td>
                        <td><?= abs($t['variance']) > 0 ? number_format($t['variance'], 4).' L' : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ── MERCHANDISE SALES REPORT ──────────────────────────── -->
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-store"></i> Merchandise Sales Report
                <span class="badge-count"><?= count($merch_sales_data) ?></span>
            </h3>
            <div style="display:flex;gap:8px;">
                <a href="manager_reports.php?<?= http_build_query(['section'=>$section,'range'=>$range,'start'=>$date_start,'end'=>$date_end,'export'=>'csv','sub'=>'merch']) ?>"
                   class="btn-export" style="background:#22c55e;color:#fff;border-color:#22c55e;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-excel"></i> Export Excel
                </a>
                <a href="export_sales_pdf.php?type=merchandise&range=<?= urlencode($range) ?>&start=<?= urlencode($date_start) ?>&end=<?= urlencode($date_end) ?>"
                   class="btn-export" style="background:#dc3545;color:#fff;border-color:#dc3545;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                </a>
            </div>
        </div>
        <?php if (empty($merch_sales_data)): ?>
        <div class="empty-state"><i class="fa-solid fa-store"></i><p>No merchandise sales data for this period.</p></div>
        <?php else:
            $grand_merch_revenue = 0;
            $grand_merch_qty     = 0;
            $grand_merch_txns    = 0;
            $grand_pay_cash = $grand_pay_card = $grand_pay_ewallet = $grand_pay_efuel = $grand_pay_credit = 0;
            foreach ($merch_sales_data as $r) {
                $grand_merch_revenue  += (float)$r['total_revenue'];
                $grand_merch_qty      += (float)$r['total_quantity'];
                $grand_merch_txns     += (int)$r['txn_count'];
                $grand_pay_cash       += (float)$r['pay_cash'];
                $grand_pay_card       += (float)$r['pay_card'];
                $grand_pay_ewallet    += (float)$r['pay_ewallet'];
                $grand_pay_efuel      += (float)$r['pay_efuel'];
                $grand_pay_credit     += (float)$r['pay_credit'];
            }
        ?>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Date</th>
                    <th>Transactions</th>
                    <th>Qty Sold</th>
                    <th>Revenue</th>
                    <th>Cash</th>
                    <th>Card</th>
                    <th>E-Wallet</th>
                    <th>E-Fuel Card</th>
                    <th>Credit</th>
                </tr></thead>
                <tbody>
                <?php foreach ($merch_sales_data as $row): ?>
                <tr>
                    <td><?= htmlspecialchars(date('M j, Y', strtotime($row['sale_date']))) ?></td>
                    <td><?= number_format((int)$row['txn_count']) ?></td>
                    <td><?= number_format((float)$row['total_quantity'], 0) ?></td>
                    <td><strong>&#8369;<?= number_format((float)$row['total_revenue'], 2) ?></strong></td>
                    <td><?= (float)$row['pay_cash']    > 0 ? '&#8369;'.number_format((float)$row['pay_cash'],    2) : '—' ?></td>
                    <td><?= (float)$row['pay_card']    > 0 ? '&#8369;'.number_format((float)$row['pay_card'],    2) : '—' ?></td>
                    <td><?= (float)$row['pay_ewallet'] > 0 ? '&#8369;'.number_format((float)$row['pay_ewallet'], 2) : '—' ?></td>
                    <td><?= (float)$row['pay_efuel']   > 0 ? '&#8369;'.number_format((float)$row['pay_efuel'],   2) : '—' ?></td>
                    <td><?= (float)$row['pay_credit']  > 0 ? '&#8369;'.number_format((float)$row['pay_credit'],  2) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr style="font-weight:700;background:#f0f4f8;border-top:2px solid #EAEAEA;">
                    <td>TOTAL</td>
                    <td><?= number_format($grand_merch_txns) ?></td>
                    <td><?= number_format($grand_merch_qty, 0) ?></td>
                    <td>&#8369;<?= number_format($grand_merch_revenue, 2) ?></td>
                    <td>&#8369;<?= number_format($grand_pay_cash,    2) ?></td>
                    <td>&#8369;<?= number_format($grand_pay_card,    2) ?></td>
                    <td>&#8369;<?= number_format($grand_pay_ewallet, 2) ?></td>
                    <td>&#8369;<?= number_format($grand_pay_efuel,   2) ?></td>
                    <td>&#8369;<?= number_format($grand_pay_credit,  2) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>

        <!-- Payment Breakdown Summary -->
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #EAEAEA;">
            <h4 style="font-size:14px;font-weight:700;color:var(--petron-blue);margin-bottom:12px;">Payment Method Breakdown</h4>
            <div class="table-scroll">
                <table class="mgr-table">
                    <thead><tr>
                        <th>Payment Method</th>
                        <th>Total Amount</th>
                        <th>% of Revenue</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $pay_methods = [
                        'Cash'        => $grand_pay_cash,
                        'Card'        => $grand_pay_card,
                        'E-Wallet'    => $grand_pay_ewallet,
                        'E-Fuel Card' => $grand_pay_efuel,
                        'Credit'      => $grand_pay_credit,
                    ];
                    foreach ($pay_methods as $pm => $amt):
                        if ($amt <= 0) continue;
                        $pct = $grand_merch_revenue > 0 ? ($amt / $grand_merch_revenue) * 100 : 0;
                    ?>
                    <tr>
                        <td><span class="badge badge-default"><?= $pm ?></span></td>
                        <td><strong>&#8369;<?= number_format($amt, 2) ?></strong></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div class="progress-bar-wrap" style="flex:1;"><div class="progress-bar-fill" style="width:<?= round($pct) ?>%;background:var(--petron-blue);"></div></div>
                                <span style="font-size:11px;font-weight:700;"><?= number_format($pct, 1) ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:700;background:#f8fafc;">
                        <td>Total</td>
                        <td>&#8369;<?= number_format($grand_merch_revenue, 2) ?></td>
                        <td>100.0%</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── DAILY SUMMARY REPORT ───────────────────────────────── -->
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-calendar-check"></i> Daily Summary Report
                <span class="badge-count"><?= count($daily_summary_data) ?></span>
            </h3>
            <div style="display:flex;gap:8px;">
                <a href="manager_reports.php?<?= http_build_query(['section'=>$section,'range'=>$range,'start'=>$date_start,'end'=>$date_end,'export'=>'csv','sub'=>'summary']) ?>"
                   class="btn-export" style="background:#22c55e;color:#fff;border-color:#22c55e;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-excel"></i> Export Excel
                </a>
                <a href="export_sales_pdf.php?type=summary&range=<?= urlencode($range) ?>&start=<?= urlencode($date_start) ?>&end=<?= urlencode($date_end) ?>"
                   class="btn-export" style="background:#dc3545;color:#fff;border-color:#dc3545;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                </a>
            </div>
        </div>
        <?php if (empty($daily_summary_data)): ?>
        <div class="empty-state"><i class="fa-solid fa-calendar-check"></i><p>No summary data for this period.</p></div>
        <?php else:
            $grand_sum_fuel_liters  = 0;
            $grand_sum_fuel_rev     = 0;
            $grand_sum_merch_rev    = 0;
            $grand_sum_total_rev    = 0;
            foreach ($daily_summary_data as $r) {
                $grand_sum_fuel_liters += (float)$r['total_fuel_liters'];
                $grand_sum_fuel_rev    += (float)$r['fuel_revenue'];
                $grand_sum_merch_rev   += (float)$r['merch_revenue'];
                $grand_sum_total_rev   += (float)$r['total_revenue'];
            }
        ?>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Date</th>
                    <th>Total Fuel Liters Sold</th>
                    <th>Total Fuel Revenue</th>
                    <th>Total Merchandise Revenue</th>
                    <th>Combined Daily Revenue</th>
                    <th>Variance Alert</th>
                </tr></thead>
                <tbody>
                <?php foreach ($daily_summary_data as $row):
                    $variance    = (float)$row['fuel_variance'];
                    $has_alert   = abs($variance) > 0.5;
                    $alert_class = $has_alert ? 'badge-rejected' : 'badge-approved';
                    $alert_label = $has_alert ? '⚠ '.number_format($variance, 4).' L avg' : 'None';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars(date('M j, Y', strtotime($row['sale_date']))) ?></strong></td>
                    <td><?= number_format((float)$row['total_fuel_liters'], 2) ?> L</td>
                    <td>&#8369;<?= number_format((float)$row['fuel_revenue'], 2) ?></td>
                    <td>&#8369;<?= number_format((float)$row['merch_revenue'], 2) ?></td>
                    <td><strong>&#8369;<?= number_format((float)$row['total_revenue'], 2) ?></strong></td>
                    <td><span class="badge <?= $alert_class ?>"><?= $alert_label ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr style="font-weight:700;background:#f0f4f8;border-top:2px solid #EAEAEA;">
                    <td>TOTAL</td>
                    <td><?= number_format($grand_sum_fuel_liters, 2) ?> L</td>
                    <td>&#8369;<?= number_format($grand_sum_fuel_rev,  2) ?></td>
                    <td>&#8369;<?= number_format($grand_sum_merch_rev, 2) ?></td>
                    <td>&#8369;<?= number_format($grand_sum_total_rev, 2) ?></td>
                    <td>—</td>
                </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; // end sales ?>


    <!-- ============================================================
         SECTION: JOB ORDERS
         ============================================================ -->
    <?php if ($section === 'job_orders'):
        // Pre-compute status buckets and staff performance
        $status_buckets = ['Pending'=>0,'Approved'=>0,'Adjusted'=>0,'Rejected'=>0,'Completed'=>0,'Other'=>0];
        $staff_perf     = [];   // [name => [count, labor, parts, total]]
        $grand_labor = $grand_parts = $grand_total = $grand_paid = 0;
        foreach ($jo_rows as $r) {
            $vs = $r['validation_status'];
            $st = $r['jo_status'];
            // Map to display bucket
            if (in_array($vs, ['Approved'])) $bucket = 'Approved';
            elseif (in_array($vs, ['Adjusted'])) $bucket = 'Adjusted';
            elseif (in_array($vs, ['Rejected'])) $bucket = 'Rejected';
            elseif (in_array($st, ['Completed','Verified','finalized'])) $bucket = 'Completed';
            else $bucket = 'Pending';
            $status_buckets[$bucket]++;
            $grand_labor += (float)$r['labor_cost'];
            $grand_parts += (float)$r['parts_cost'];
            $grand_total += (float)$r['total_cost'];
            $grand_paid  += (float)$r['amount_paid'];
            // Staff perf
            $sname = $r['assigned_staff'] !== '—' ? $r['assigned_staff'] : ($r['mechanic'] !== '—' ? $r['mechanic'] : 'Unassigned');
            if (!isset($staff_perf[$sname])) $staff_perf[$sname] = ['count'=>0,'labor'=>0,'parts'=>0,'total'=>0];
            $staff_perf[$sname]['count']++;
            $staff_perf[$sname]['labor'] += (float)$r['labor_cost'];
            $staff_perf[$sname]['parts'] += (float)$r['parts_cost'];
            $staff_perf[$sname]['total'] += (float)$r['total_cost'];
        }
        arsort($staff_perf);
    ?>

    <!-- ── STATUS SUMMARY CARDS ──────────────────────────────── -->
    <div class="stat-grid" style="margin-bottom:20px;">
        <?php
        $card_defs = [
            'Pending'   => ['color'=>'stat-orange','icon'=>'fa-clock',        'label'=>'Pending'],
            'Approved'  => ['color'=>'stat-green', 'icon'=>'fa-circle-check', 'label'=>'Approved'],
            'Adjusted'  => ['color'=>'stat-blue',  'icon'=>'fa-pen-to-square','label'=>'Adjusted'],
            'Rejected'  => ['color'=>'stat-red',   'icon'=>'fa-circle-xmark', 'label'=>'Rejected'],
            'Completed' => ['color'=>'stat-teal',  'icon'=>'fa-flag-checkered','label'=>'Completed'],
        ];
        foreach ($card_defs as $key => $def): ?>
        <div class="stat-card <?= $def['color'] ?>">
            <div class="stat-icon"><i class="fa-solid <?= $def['icon'] ?>"></i></div>
            <div class="stat-body">
                <div class="stat-num"><?= $status_buckets[$key] ?></div>
                <div class="stat-label"><?= $def['label'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="stat-card stat-purple">
            <div class="stat-icon"><i class="fa-solid fa-list"></i></div>
            <div class="stat-body">
                <div class="stat-num"><?= count($jo_rows) ?></div>
                <div class="stat-label">Total JOs</div>
            </div>
        </div>
    </div>

    <!-- ── MAIN JOB ORDERS TABLE ─────────────────────────────── -->
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-wrench"></i> Job Orders Report
                <span class="badge-count"><?= count($jo_rows) ?></span>
            </h3>
            <div style="display:flex;gap:8px;">
                <a href="manager_reports.php?<?= http_build_query(['section'=>$section,'range'=>$range,'start'=>$date_start,'end'=>$date_end,'export'=>'csv']) ?>"
                   class="btn-export" style="background:#22c55e;color:#fff;border-color:#22c55e;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-excel"></i> Export Excel
                </a>
                <a href="export_jo_pdf.php?range=<?= urlencode($range) ?>&start=<?= urlencode($date_start) ?>&end=<?= urlencode($date_end) ?>"
                   class="btn-export" style="background:#dc3545;color:#fff;border-color:#dc3545;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                </a>
            </div>
        </div>
        <?php if (empty($jo_rows)): ?>
        <div class="empty-state"><i class="fa-solid fa-wrench"></i><p>No job orders found for this period.</p></div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>JO Reference</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Service Type</th>
                    <th>Staff / Mechanic</th>
                    <th>Validation Status</th>
                    <th>JO Status</th>
                    <th>Labor Cost</th>
                    <th>Parts Cost</th>
                    <th>Total Cost</th>
                    <th>Amount Paid</th>
                    <th>Payment</th>
                    <th>Date</th>
                </tr></thead>
                <tbody>
                <?php foreach ($jo_rows as $row):
                    $vs = $row['validation_status'];
                    $st = $row['jo_status'];
                    if (in_array($vs, ['Approved'])) $bucket = 'Approved';
                    elseif (in_array($vs, ['Adjusted'])) $bucket = 'Adjusted';
                    elseif (in_array($vs, ['Rejected'])) $bucket = 'Rejected';
                    elseif (in_array($st, ['Completed','Verified','finalized'])) $bucket = 'Completed';
                    else $bucket = 'Pending';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['jo_ref']) ?></strong></td>
                    <td><?= htmlspecialchars($row['customer']) ?></td>
                    <td>
                        <span><?= htmlspecialchars($row['vehicle_plate']) ?></span>
                        <?php if ($row['vehicle_type'] !== '—'): ?>
                        <small style="display:block;color:#667085;font-size:11px;"><?= htmlspecialchars($row['vehicle_type']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['service_type']) ?></td>
                    <td>
                        <?php if ($row['assigned_staff'] !== '—'): ?>
                        <span style="display:block;font-size:12px;"><i class="fa-solid fa-user" style="color:#667085;margin-right:4px;"></i><?= htmlspecialchars($row['assigned_staff']) ?></span>
                        <?php endif; ?>
                        <?php if ($row['mechanic'] !== '—'): ?>
                        <span style="display:block;font-size:11px;color:#667085;"><i class="fa-solid fa-wrench" style="margin-right:4px;"></i><?= htmlspecialchars($row['mechanic']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= status_badge_class($bucket) ?>"><?= htmlspecialchars($vs) ?></span></td>
                    <td><span class="badge <?= status_badge_class($st ?: 'Pending') ?>"><?= htmlspecialchars($st ?: 'Pending') ?></span></td>
                    <td>&#8369;<?= number_format((float)$row['labor_cost'], 2) ?></td>
                    <td>&#8369;<?= number_format((float)$row['parts_cost'], 2) ?></td>
                    <td><strong>&#8369;<?= number_format((float)$row['total_cost'], 2) ?></strong></td>
                    <td style="color:<?= (float)$row['amount_paid'] >= (float)$row['total_cost'] && (float)$row['total_cost'] > 0 ? '#16a34a' : '#dc3545' ?>;">
                        &#8369;<?= number_format((float)$row['amount_paid'], 2) ?>
                    </td>
                    <td><?= htmlspecialchars($row['payment_method']) ?></td>
                    <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($row['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr style="font-weight:700;background:#f0f4f8;border-top:2px solid #EAEAEA;">
                    <td colspan="7">TOTAL (<?= count($jo_rows) ?> Job Orders)</td>
                    <td>&#8369;<?= number_format($grand_labor, 2) ?></td>
                    <td>&#8369;<?= number_format($grand_parts, 2) ?></td>
                    <td>&#8369;<?= number_format($grand_total, 2) ?></td>
                    <td>&#8369;<?= number_format($grand_paid,  2) ?></td>
                    <td colspan="2"></td>
                </tr>
                </tfoot>
            </table>
        </div>

        <!-- Status Breakdown -->
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #EAEAEA;">
            <h4 style="font-size:14px;font-weight:700;color:var(--petron-blue);margin-bottom:12px;">Status Breakdown</h4>
            <div class="table-scroll">
                <table class="mgr-table">
                    <thead><tr>
                        <th>Validation Status</th>
                        <th>Count</th>
                        <th>% of Total</th>
                        <th>Total Cost</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $status_cost = [];
                    foreach ($jo_rows as $r) {
                        $vs = $r['validation_status'];
                        if (!isset($status_cost[$vs])) $status_cost[$vs] = ['count'=>0,'total'=>0];
                        $status_cost[$vs]['count']++;
                        $status_cost[$vs]['total'] += (float)$r['total_cost'];
                    }
                    arsort($status_cost);
                    $total_jos = count($jo_rows);
                    foreach ($status_cost as $vs => $d):
                        $pct = $total_jos > 0 ? ($d['count'] / $total_jos) * 100 : 0;
                    ?>
                    <tr>
                        <td><span class="badge <?= status_badge_class($vs) ?>"><?= htmlspecialchars($vs) ?></span></td>
                        <td><strong><?= $d['count'] ?></strong></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div class="progress-bar-wrap" style="flex:1;"><div class="progress-bar-fill" style="width:<?= round($pct) ?>%;background:var(--petron-blue);"></div></div>
                                <span style="font-size:11px;font-weight:700;"><?= number_format($pct,1) ?>%</span>
                            </div>
                        </td>
                        <td>&#8369;<?= number_format($d['total'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Staff Performance -->
        <?php if (!empty($staff_perf)): ?>
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #EAEAEA;">
            <h4 style="font-size:14px;font-weight:700;color:var(--petron-blue);margin-bottom:12px;">Staff / Mechanic Performance</h4>
            <div class="table-scroll">
                <table class="mgr-table">
                    <thead><tr>
                        <th>Staff / Mechanic</th>
                        <th>JOs Assigned</th>
                        <th>Labor Cost</th>
                        <th>Parts Cost</th>
                        <th>Total Cost</th>
                        <th>Avg per JO</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($staff_perf as $sname => $d):
                        $avg = $d['count'] > 0 ? $d['total'] / $d['count'] : 0;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($sname) ?></strong></td>
                        <td><?= $d['count'] ?></td>
                        <td>&#8369;<?= number_format($d['labor'], 2) ?></td>
                        <td>&#8369;<?= number_format($d['parts'], 2) ?></td>
                        <td><strong>&#8369;<?= number_format($d['total'], 2) ?></strong></td>
                        <td>&#8369;<?= number_format($avg, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; // end empty check ?>
    </div>
    <?php endif; // end job_orders ?>


    <!-- ============================================================
         SECTION: CUSTOMER BALANCES
         ============================================================ -->
    <?php if ($section === 'balances'):
        // Aggregate totals
        $grand_outstanding   = array_sum(array_column($balance_rows, 'outstanding'));
        $grand_credit_limit  = array_sum(array_column($balance_rows, 'credit_limit'));
        $grand_jo_balance    = array_sum(array_column($balance_jo_rows, 'balance_due'));
        $grand_mt_balance    = array_sum(array_column($balance_mt_rows, 'total_amount'));
        $has_any = !empty($balance_rows) || !empty($balance_jo_rows) || !empty($balance_mt_rows);
    ?>

    <!-- ── SUMMARY CARDS ─────────────────────────────────────── -->
    <div class="stat-grid" style="margin-bottom:20px;">
        <div class="stat-card stat-red">
            <div class="stat-icon"><i class="fa-solid fa-scale-balanced"></i></div>
            <div class="stat-body">
                <div class="stat-num">&#8369;<?= number_format($grand_outstanding + $grand_jo_balance + $grand_mt_balance, 2) ?></div>
                <div class="stat-label">Total Outstanding</div>
            </div>
        </div>
        <div class="stat-card stat-blue">
            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
            <div class="stat-body">
                <div class="stat-num"><?= count($balance_rows) ?></div>
                <div class="stat-label">Customers w/ Balance</div>
            </div>
        </div>
        <div class="stat-card stat-orange">
            <div class="stat-icon"><i class="fa-solid fa-wrench"></i></div>
            <div class="stat-body">
                <div class="stat-num">&#8369;<?= number_format($grand_jo_balance, 2) ?></div>
                <div class="stat-label">Unpaid Job Orders</div>
            </div>
        </div>
        <div class="stat-card stat-purple">
            <div class="stat-icon"><i class="fa-solid fa-store"></i></div>
            <div class="stat-body">
                <div class="stat-num">&#8369;<?= number_format($grand_mt_balance, 2) ?></div>
                <div class="stat-label">Unpaid Merchandise</div>
            </div>
        </div>
    </div>

    <?php if (!$has_any): ?>
    <div class="rpt-card">
        <div class="empty-state"><i class="fa-solid fa-scale-balanced"></i><p>No outstanding customer balances.</p></div>
    </div>
    <?php endif; ?>

    <!-- ── CUSTOMER CREDIT BALANCES ──────────────────────────── -->
    <?php if (!empty($balance_rows)): ?>
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-users"></i> Customer Credit Balances
                <span class="badge-count"><?= count($balance_rows) ?></span>
            </h3>
            <div style="display:flex;gap:8px;">
                <a href="manager_reports.php?<?= http_build_query(['section'=>$section,'range'=>$range,'start'=>$date_start,'end'=>$date_end,'export'=>'csv']) ?>"
                   class="btn-export" style="background:#22c55e;color:#fff;border-color:#22c55e;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-excel"></i> Export Excel
                </a>
                <a href="export_balances_pdf.php?range=<?= urlencode($range) ?>&start=<?= urlencode($date_start) ?>&end=<?= urlencode($date_end) ?>"
                   class="btn-export" style="background:#dc3545;color:#fff;border-color:#dc3545;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                </a>
            </div>
        </div>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Contact</th>
                    <th>Credit Limit</th>
                    <th>Outstanding Balance</th>
                    <th>Credit Usage</th>
                    <th>Status</th>
                </tr></thead>
                <tbody>
                <?php
                $total_outstanding = 0;
                $total_credit_limit = 0;
                foreach ($balance_rows as $row):
                    $outstanding = (float)$row['outstanding'];
                    $credit_limit = (float)$row['credit_limit'];
                    $total_outstanding  += $outstanding;
                    $total_credit_limit += $credit_limit;
                    $util = $credit_limit > 0 ? min(100, ($outstanding / $credit_limit) * 100) : 100;
                    $util_color = $util >= 90 ? '#dc3545' : ($util >= 70 ? '#f59e0b' : '#22c55e');
                    $acct_status = strtolower($row['status'] ?? 'active');
                    $status_color = $acct_status === 'suspended' ? '#dc3545' : ($acct_status === 'inactive' ? '#9ca3af' : '#22c55e');
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($row['name']) ?></strong>
                        <?php if (!empty($row['email'])): ?>
                        <small style="display:block;color:#667085;font-size:11px;"><?= htmlspecialchars($row['email']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-default"><?= ucfirst(htmlspecialchars($row['type'] ?? 'credit')) ?></span></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($row['contact_number'] ?? '—') ?></td>
                    <td>&#8369;<?= number_format($credit_limit, 2) ?></td>
                    <td><strong style="color:var(--petron-red);">&#8369;<?= number_format($outstanding, 2) ?></strong></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="progress-bar-wrap" style="flex:1;">
                                <div class="progress-bar-fill" style="width:<?= round($util) ?>%;background:<?= $util_color ?>;"></div>
                            </div>
                            <span style="font-size:11px;font-weight:700;color:<?= $util_color ?>;"><?= number_format($util, 1) ?>%</span>
                        </div>
                    </td>
                    <td><span class="badge" style="background:<?= $status_color ?>20;color:<?= $status_color ?>;"><?= ucfirst($acct_status) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr style="font-weight:700;background:#f0f4f8;border-top:2px solid #EAEAEA;">
                    <td colspan="3">TOTAL (<?= count($balance_rows) ?> customers)</td>
                    <td>&#8369;<?= number_format($total_credit_limit, 2) ?></td>
                    <td style="color:var(--petron-red);">&#8369;<?= number_format($total_outstanding, 2) ?></td>
                    <td><?= $total_credit_limit > 0 ? number_format(($total_outstanding/$total_credit_limit)*100,1).'%' : '—' ?></td>
                    <td></td>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── UNPAID CREDIT JOB ORDERS ──────────────────────────── -->
    <?php if (!empty($balance_jo_rows)): ?>
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-wrench"></i> Unpaid Credit Job Orders
                <span class="badge-count"><?= count($balance_jo_rows) ?></span>
            </h3>
        </div>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>JO Reference</th>
                    <th>Customer</th>
                    <th>Service</th>
                    <th>Total Cost</th>
                    <th>Amount Paid</th>
                    <th>Balance Due</th>
                    <th>Validation</th>
                    <th>Date</th>
                </tr></thead>
                <tbody>
                <?php foreach ($balance_jo_rows as $row):
                    $balance_due = (float)$row['balance_due'];
                    $bal_color = $balance_due > 0 ? 'var(--petron-red)' : '#22c55e';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['jo_ref']) ?></strong></td>
                    <td><?= htmlspecialchars($row['customer_name']) ?></td>
                    <td><?= htmlspecialchars($row['service_type'] ?: '—') ?></td>
                    <td>&#8369;<?= number_format((float)$row['total_cost'], 2) ?></td>
                    <td>&#8369;<?= number_format((float)$row['amount_paid'], 2) ?></td>
                    <td><strong style="color:<?= $bal_color ?>;">&#8369;<?= number_format($balance_due, 2) ?></strong></td>
                    <td><span class="badge <?= status_badge_class($row['validation_status'] ?? 'Pending') ?>"><?= htmlspecialchars($row['validation_status'] ?? 'Pending') ?></span></td>
                    <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($row['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr style="font-weight:700;background:#f0f4f8;border-top:2px solid #EAEAEA;">
                    <td colspan="5">TOTAL BALANCE DUE</td>
                    <td style="color:var(--petron-red);">&#8369;<?= number_format($grand_jo_balance, 2) ?></td>
                    <td colspan="2"></td>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── UNPAID CREDIT MERCHANDISE ─────────────────────────── -->
    <?php if (!empty($balance_mt_rows)): ?>
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-store"></i> Unpaid Credit Merchandise Transactions
                <span class="badge-count"><?= count($balance_mt_rows) ?></span>
            </h3>
        </div>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Transaction Ref</th>
                    <th>Customer</th>
                    <th>Amount</th>
                    <th>Payment Method</th>
                    <th>Validation Status</th>
                    <th>Date</th>
                </tr></thead>
                <tbody>
                <?php foreach ($balance_mt_rows as $row): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['txn_ref']) ?></strong></td>
                    <td><?= htmlspecialchars($row['customer_name']) ?></td>
                    <td><strong style="color:var(--petron-red);">&#8369;<?= number_format((float)$row['total_amount'], 2) ?></strong></td>
                    <td><?= htmlspecialchars($row['payment_method']) ?></td>
                    <td><span class="badge <?= status_badge_class($row['validation_status'] ?? 'Pending') ?>"><?= htmlspecialchars($row['validation_status'] ?? 'Pending') ?></span></td>
                    <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($row['txn_date'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr style="font-weight:700;background:#f0f4f8;border-top:2px solid #EAEAEA;">
                    <td colspan="2">TOTAL</td>
                    <td style="color:var(--petron-red);">&#8369;<?= number_format($grand_mt_balance, 2) ?></td>
                    <td colspan="3"></td>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // end balances ?>


    <!-- ============================================================
         SECTION: DELIVERIES
         ============================================================ -->
    <?php if ($section === 'deliveries'):
        $all_deliveries = array_merge($delivery_rows, $fuel_delivery_rows);
        $has_any = !empty($delivery_rows) || !empty($fuel_delivery_rows);

        // Status buckets across both tables
        $del_status_counts = [];
        $del_supplier_map  = [];
        foreach ($all_deliveries as $r) {
            $st = $r['status'] ?? 'Unknown';
            $del_status_counts[$st] = ($del_status_counts[$st] ?? 0) + 1;
            $sup = $r['supplier_name'];
            if (!isset($del_supplier_map[$sup])) $del_supplier_map[$sup] = ['count'=>0,'qty'=>0,'approved'=>0,'unit'=>''];
            $del_supplier_map[$sup]['count']++;
            $del_supplier_map[$sup]['qty'] += (float)$r['quantity_delivered'];
            $del_supplier_map[$sup]['unit'] = $r['unit_type'];
            if (in_array(strtolower($st), ['confirmed','approved','validated','verified'])) $del_supplier_map[$sup]['approved']++;
        }
    ?>

    <!-- ── STATUS SUMMARY CARDS ──────────────────────────────── -->
    <div class="stat-grid" style="margin-bottom:20px;">
        <?php
        $pending_cnt  = 0; $approved_cnt = 0; $rejected_cnt = 0;
        foreach ($del_status_counts as $st => $cnt) {
            $stl = strtolower($st);
            if (str_contains($stl,'pending'))                                          $pending_cnt  += $cnt;
            elseif (in_array($stl,['confirmed','approved','validated','verified']))    $approved_cnt += $cnt;
            elseif (in_array($stl,['rejected','flagged','returned','discrepancy']))    $rejected_cnt += $cnt;
        }
        ?>
        <div class="stat-card stat-orange">
            <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $pending_cnt ?></div><div class="stat-label">Pending</div></div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $approved_cnt ?></div><div class="stat-label">Approved / Confirmed</div></div>
        </div>
        <div class="stat-card stat-red">
            <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $rejected_cnt ?></div><div class="stat-label">Rejected</div></div>
        </div>
        <div class="stat-card stat-blue">
            <div class="stat-icon"><i class="fa-solid fa-truck"></i></div>
            <div class="stat-body"><div class="stat-num"><?= count($all_deliveries) ?></div><div class="stat-label">Total Deliveries</div></div>
        </div>
    </div>

    <?php if (!$has_any): ?>
    <div class="rpt-card">
        <div class="empty-state"><i class="fa-solid fa-truck"></i><p>No deliveries found for this period.</p></div>
    </div>
    <?php endif; ?>

    <!-- ── MERCHANDISE / GENERAL DELIVERIES ──────────────────── -->
    <?php if (!empty($delivery_rows)): ?>
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-boxes-stacked"></i> Merchandise &amp; General Deliveries
                <span class="badge-count"><?= count($delivery_rows) ?></span>
            </h3>
            <div style="display:flex;gap:8px;">
                <a href="manager_reports.php?<?= http_build_query(['section'=>$section,'range'=>$range,'start'=>$date_start,'end'=>$date_end,'export'=>'csv']) ?>"
                   class="btn-export" style="background:#22c55e;color:#fff;border-color:#22c55e;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-excel"></i> Export Excel
                </a>
                <a href="export_deliveries_pdf.php?range=<?= urlencode($range) ?>&start=<?= urlencode($date_start) ?>&end=<?= urlencode($date_end) ?>"
                   class="btn-export" style="background:#dc3545;color:#fff;border-color:#dc3545;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                </a>
            </div>
        </div>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Supplier</th>
                    <th>Product</th>
                    <th>Qty Delivered</th>
                    <th>DR Number</th>
                    <th>Delivery Date</th>
                    <th>Encoded By</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr></thead>
                <tbody>
                <?php foreach ($delivery_rows as $row):
                    $stl = strtolower($row['status'] ?? '');
                    $status_class = in_array($stl,['confirmed','approved','validated']) ? 'badge-approved'
                        : (in_array($stl,['rejected','flagged','returned']) ? 'badge-rejected'
                        : (str_contains($stl,'pending') ? 'badge-pending' : 'badge-default'));
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['delivery_id']) ?></strong></td>
                    <td><span class="badge badge-default"><?= htmlspecialchars($row['delivery_type']) ?></span></td>
                    <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                    <td><strong><?= number_format((float)$row['quantity_delivered'], 2) ?></strong> <span style="color:#667085;font-size:11px;"><?= htmlspecialchars($row['unit_type']) ?></span></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($row['dr_number']) ?></td>
                    <td style="white-space:nowrap;"><?= $row['delivery_date'] ? date('M j, Y', strtotime($row['delivery_date'])) : '—' ?></td>
                    <td><?= htmlspecialchars($row['encoded_by']) ?></td>
                    <td><span class="badge <?= $status_class ?>"><?= htmlspecialchars($row['status'] ?? '—') ?></span></td>
                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($row['remarks']) ?>"><?= htmlspecialchars($row['remarks'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── FUEL TANKER DELIVERIES ────────────────────────────── -->
    <?php if (!empty($fuel_delivery_rows)): ?>
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-gas-pump"></i> Fuel Tanker Deliveries
                <span class="badge-count"><?= count($fuel_delivery_rows) ?></span>
            </h3>
            <?php if (empty($delivery_rows)): // show export here if no merch deliveries ?>
            <div style="display:flex;gap:8px;">
                <a href="manager_reports.php?<?= http_build_query(['section'=>$section,'range'=>$range,'start'=>$date_start,'end'=>$date_end,'export'=>'csv']) ?>"
                   class="btn-export" style="background:#22c55e;color:#fff;border-color:#22c55e;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-excel"></i> Export Excel
                </a>
                <a href="export_deliveries_pdf.php?range=<?= urlencode($range) ?>&start=<?= urlencode($date_start) ?>&end=<?= urlencode($date_end) ?>"
                   class="btn-export" style="background:#dc3545;color:#fff;border-color:#dc3545;font-size:11px;padding:5px 12px;">
                    <i class="fa-solid fa-file-pdf"></i> Export PDF
                </a>
            </div>
            <?php endif; ?>
        </div>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Reference</th>
                    <th>Supplier</th>
                    <th>Fuel Type</th>
                    <th>Liters Delivered</th>
                    <th>Invoice / DR No.</th>
                    <th>Delivery Date</th>
                    <th>Received By</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr></thead>
                <tbody>
                <?php foreach ($fuel_delivery_rows as $row):
                    $stl = strtolower($row['status'] ?? '');
                    $status_class = in_array($stl,['verified','confirmed','approved']) ? 'badge-approved'
                        : (in_array($stl,['rejected','flagged']) ? 'badge-rejected'
                        : (str_contains($stl,'pending') ? 'badge-pending' : 'badge-default'));
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['delivery_id']) ?></strong></td>
                    <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                    <td><strong><?= htmlspecialchars($row['product_name']) ?></strong></td>
                    <td><strong><?= number_format((float)$row['quantity_delivered'], 2) ?></strong> <span style="color:#667085;font-size:11px;">L</span></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($row['dr_number']) ?></td>
                    <td style="white-space:nowrap;"><?= $row['delivery_date'] ? date('M j, Y', strtotime($row['delivery_date'])) : '—' ?></td>
                    <td><?= htmlspecialchars($row['encoded_by']) ?></td>
                    <td><span class="badge <?= $status_class ?>"><?= htmlspecialchars($row['status'] ?? '—') ?></span></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#667085;" title="<?= htmlspecialchars($row['remarks']) ?>"><?= htmlspecialchars($row['remarks'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── SUPPLIER SUMMARY ──────────────────────────────────── -->
    <?php if ($has_any && !empty($del_supplier_map)): ?>
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-building"></i> Supplier Summary</h3>
        </div>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Supplier</th>
                    <th>Deliveries</th>
                    <th>Total Quantity</th>
                    <th>Approved / Confirmed</th>
                    <th>Success Rate</th>
                </tr></thead>
                <tbody>
                <?php foreach ($del_supplier_map as $sup => $d):
                    $rate = $d['count'] > 0 ? ($d['approved'] / $d['count']) * 100 : 0;
                    $rate_color = $rate >= 80 ? '#22c55e' : ($rate >= 50 ? '#f59e0b' : '#dc3545');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($sup) ?></strong></td>
                    <td><?= $d['count'] ?></td>
                    <td><?= number_format($d['qty'], 2) ?> <?= htmlspecialchars($d['unit']) ?></td>
                    <td><?= $d['approved'] ?> / <?= $d['count'] ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="progress-bar-wrap" style="flex:1;"><div class="progress-bar-fill" style="width:<?= round($rate) ?>%;background:<?= $rate_color ?>;"></div></div>
                            <span style="font-size:11px;font-weight:700;color:<?= $rate_color ?>;"><?= number_format($rate,1) ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // end deliveries ?>


    <!-- ============================================================
         SECTION: STAFF PERFORMANCE
         ============================================================ -->
    <?php if ($section === 'staff'):
        // Pre-compute totals
        $grand_txns = $grand_jo = $grand_dv = $grand_hrs = 0;
        $top_txn_name = '—'; $top_txn_val = -1;
        foreach ($staff_performance as $r) {
            $grand_txns += (int)$r['total_transactions'];
            $grand_jo   += (int)$r['job_orders_encoded'];
            $grand_dv   += (int)$r['deliveries_encoded'];
            $grand_hrs  += (float)$r['total_hours'];
            if ((int)$r['total_transactions'] > $top_txn_val) {
                $top_txn_val  = (int)$r['total_transactions'];
                $top_txn_name = $r['staff_name'];
            }
        }
        $staff_count = count($staff_performance);
    ?>

    <!-- ── SUMMARY CARDS ─────────────────────────────────────── -->
    <div class="stat-grid" style="margin-bottom:20px;">
        <div class="stat-card stat-blue">
            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $staff_count ?></div><div class="stat-label">Active Staff</div></div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="stat-body"><div class="stat-num"><?= number_format($grand_txns) ?></div><div class="stat-label">Total Transactions</div></div>
        </div>
        <div class="stat-card stat-orange">
            <div class="stat-icon"><i class="fa-solid fa-wrench"></i></div>
            <div class="stat-body"><div class="stat-num"><?= number_format($grand_jo) ?></div><div class="stat-label">Job Orders</div></div>
        </div>
        <div class="stat-card stat-teal">
            <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-body"><div class="stat-num"><?= number_format($grand_hrs, 1) ?>h</div><div class="stat-label">Total Hours Logged</div></div>
        </div>
    </div>

    <!-- ── STAFF PERFORMANCE TABLE ───────────────────────────── -->
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-star"></i> Staff Performance Report
                <span class="badge-count"><?= $staff_count ?></span>
            </h3>
        </div>
        <?php if (empty($staff_performance)): ?>
        <div class="empty-state"><i class="fa-solid fa-users"></i><p>No staff performance data for this period.</p></div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Staff</th>
                    <th>Role</th>
                    <th>Fuel Txns</th>
                    <th>Merch Txns</th>
                    <th>Total Transactions</th>
                    <th>Job Orders</th>
                    <th>Deliveries</th>
                    <th>Hours Worked</th>
                    <th>Shifts</th>
                    <th>Attendance Days</th>
                    <th>Performance</th>
                </tr></thead>
                <tbody>
                <?php foreach ($staff_performance as $row):
                    $score = ((int)$row['total_transactions'] * 1) + ((int)$row['job_orders_encoded'] * 2) + ((int)$row['deliveries_encoded'] * 3);
                    $level = $score >= 30 ? 'High' : ($score >= 10 ? 'Medium' : 'Low');
                    $lc    = $level === 'High' ? '#22c55e' : ($level === 'Medium' ? '#f59e0b' : '#9ca3af');
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($row['staff_name']) ?></strong>
                        <small style="display:block;color:#667085;font-size:11px;">#<?= $row['staff_id'] ?></small>
                    </td>
                    <td><span class="badge badge-default"><?= ucfirst(htmlspecialchars($row['role'])) ?></span></td>
                    <td><?= number_format((int)$row['fuel_transactions']) ?></td>
                    <td><?= number_format((int)$row['merch_transactions']) ?></td>
                    <td><strong><?= number_format((int)$row['total_transactions']) ?></strong></td>
                    <td><?= number_format((int)$row['job_orders_encoded']) ?></td>
                    <td><?= number_format((int)$row['deliveries_encoded']) ?></td>
                    <td><strong><?= number_format((float)$row['total_hours'], 1) ?>h</strong></td>
                    <td><?= number_format((int)$row['shift_count']) ?></td>
                    <td><?= number_format((int)$row['attendance_days']) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <div class="progress-bar-wrap" style="flex:1;min-width:60px;">
                                <div class="progress-bar-fill" style="width:<?= $score > 0 ? min(100, round($score * 2)) : 0 ?>%;background:<?= $lc ?>;"></div>
                            </div>
                            <span style="font-size:11px;font-weight:700;color:<?= $lc ?>;"><?= $level ?></span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr style="font-weight:700;background:#f0f4f8;border-top:2px solid #EAEAEA;">
                    <td colspan="4">TOTAL (<?= $staff_count ?> staff)</td>
                    <td><?= number_format($grand_txns) ?></td>
                    <td><?= number_format($grand_jo) ?></td>
                    <td><?= number_format($grand_dv) ?></td>
                    <td><?= number_format($grand_hrs, 1) ?>h</td>
                    <td colspan="3"></td>
                </tr>
                </tfoot>
            </table>
        </div>

        <!-- Team Summary -->
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #EAEAEA;">
            <h4 style="font-size:14px;font-weight:700;color:var(--petron-blue);margin-bottom:12px;">Team Summary</h4>
            <div class="table-scroll">
                <table class="mgr-table">
                    <thead><tr><th>Metric</th><th>Total</th><th>Avg per Staff</th><th>Top Performer</th></tr></thead>
                    <tbody>
                    <tr>
                        <td>Transactions Encoded</td>
                        <td><strong><?= number_format($grand_txns) ?></strong></td>
                        <td><?= $staff_count > 0 ? number_format($grand_txns / $staff_count, 1) : '0' ?></td>
                        <td><?= htmlspecialchars($top_txn_name) ?></td>
                    </tr>
                    <tr>
                        <td>Job Orders Handled</td>
                        <td><strong><?= number_format($grand_jo) ?></strong></td>
                        <td><?= $staff_count > 0 ? number_format($grand_jo / $staff_count, 1) : '0' ?></td>
                        <td>—</td>
                    </tr>
                    <tr>
                        <td>Hours Worked</td>
                        <td><strong><?= number_format($grand_hrs, 1) ?>h</strong></td>
                        <td><?= $staff_count > 0 ? number_format($grand_hrs / $staff_count, 1) : '0' ?>h</td>
                        <td>—</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── ATTENDANCE & SHIFT LOGS ───────────────────────────── -->
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-clock"></i> Attendance &amp; Shift Logs
                <span class="badge-count"><?= count($attendance_rows) ?></span>
            </h3>
        </div>
        <?php if (empty($attendance_rows)): ?>
        <div class="empty-state"><i class="fa-solid fa-clock"></i><p>No attendance records for this period.</p></div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Staff</th>
                    <th>Role</th>
                    <th>Shift</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Hours Worked</th>
                    <th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($attendance_rows as $row):
                    $status = !$row['end_time'] ? 'Active' : ((float)$row['hours_worked'] < 4 ? 'Incomplete' : 'Completed');
                    $sc     = $status === 'Completed' ? 'badge-approved' : ($status === 'Active' ? 'badge-inprog' : 'badge-pending');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['staff_name'] ?? '—') ?></strong></td>
                    <td><span class="badge badge-default"><?= ucfirst(htmlspecialchars($row['role'] ?? '')) ?></span></td>
                    <td><?= htmlspecialchars($row['shift_label'] ?? '—') ?></td>
                    <td style="white-space:nowrap;"><?= $row['start_time'] ? date('M j, Y g:i A', strtotime($row['start_time'])) : '—' ?></td>
                    <td style="white-space:nowrap;"><?= $row['end_time'] ? date('M j, Y g:i A', strtotime($row['end_time'])) : '<span class="badge badge-inprog">Active</span>' ?></td>
                    <td><strong><?= number_format((float)$row['hours_worked'], 2) ?>h</strong></td>
                    <td><span class="badge <?= $sc ?>"><?= $status ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; // end staff ?>


    <!-- ============================================================
         SECTION: VALIDATION LOGS
         ============================================================ -->
    <?php if ($section === 'validation'):
        // Pre-compute action stats
        $action_counts = ['Approved'=>0,'Adjusted'=>0,'Rejected'=>0,'Confirmed'=>0,'Other'=>0];
        $manager_map   = [];
        foreach ($validation_rows as $r) {
            $a = $r['action'] ?? '';
            $al = strtolower($a);
            if (in_array($al,['approved','approve']))          $action_counts['Approved']++;
            elseif (in_array($al,['adjusted','adjust']))       $action_counts['Adjusted']++;
            elseif (in_array($al,['rejected','reject']))       $action_counts['Rejected']++;
            elseif (in_array($al,['confirmed','confirm']))     $action_counts['Confirmed']++;
            else                                               $action_counts['Other']++;
            $mn = $r['manager_name'];
            if (!isset($manager_map[$mn])) $manager_map[$mn] = ['role'=>$r['manager_role'],'total'=>0,'approved'=>0,'adjusted'=>0,'rejected'=>0];
            $manager_map[$mn]['total']++;
            if (in_array($al,['approved','approve']))          $manager_map[$mn]['approved']++;
            elseif (in_array($al,['adjusted','adjust']))       $manager_map[$mn]['adjusted']++;
            elseif (in_array($al,['rejected','reject']))       $manager_map[$mn]['rejected']++;
        }
        uasort($manager_map, fn($a,$b) => $b['total'] - $a['total']);
        $total_val = count($validation_rows);
    ?>

    <!-- ── SUMMARY CARDS ─────────────────────────────────────── -->
    <div class="stat-grid" style="margin-bottom:20px;">
        <div class="stat-card stat-blue">
            <div class="stat-icon"><i class="fa-solid fa-clipboard-list"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $total_val ?></div><div class="stat-label">Total Actions</div></div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $action_counts['Approved'] + $action_counts['Confirmed'] ?></div><div class="stat-label">Approved / Confirmed</div></div>
        </div>
        <div class="stat-card stat-orange">
            <div class="stat-icon"><i class="fa-solid fa-pen-to-square"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $action_counts['Adjusted'] ?></div><div class="stat-label">Adjusted</div></div>
        </div>
        <div class="stat-card stat-red">
            <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $action_counts['Rejected'] ?></div><div class="stat-label">Rejected</div></div>
        </div>
    </div>

    <!-- ── VALIDATION LOG TABLE ──────────────────────────────── -->
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-clipboard-list"></i> Validation Logs
                <span class="badge-count"><?= $total_val ?></span>
            </h3>
        </div>
        <?php if (empty($validation_rows)): ?>
        <div class="empty-state"><i class="fa-solid fa-clipboard-check"></i><p>No validation records found for this period.</p></div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Date &amp; Time</th>
                    <th>Manager</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Reference</th>
                    <th>Transaction Details</th>
                    <th>Reason / Notes</th>
                    <th>Encoded By</th>
                </tr></thead>
                <tbody>
                <?php foreach ($validation_rows as $row):
                    $al = strtolower($row['action'] ?? '');
                    $badge = in_array($al,['approved','approve','confirmed','confirm']) ? 'badge-approved'
                           : (in_array($al,['rejected','reject']) ? 'badge-rejected'
                           : (in_array($al,['adjusted','adjust']) ? 'badge-inprog' : 'badge-default'));
                ?>
                <tr>
                    <td style="white-space:nowrap;">
                        <strong><?= date('M j, Y', strtotime($row['date_time'])) ?></strong>
                        <small style="display:block;color:#667085;"><?= date('g:i A', strtotime($row['date_time'])) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($row['manager_name']) ?></strong>
                        <small style="display:block;color:#667085;font-size:11px;"><?= ucfirst(htmlspecialchars($row['manager_role'])) ?></small>
                    </td>
                    <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($row['action']) ?></span></td>
                    <td><span class="badge badge-default"><?= htmlspecialchars($row['module']) ?></span></td>
                    <td style="font-size:12px;font-weight:600;"><?= htmlspecialchars($row['reference_id']) ?></td>
                    <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($row['details']) ?>">
                        <?= htmlspecialchars($row['details']) ?>
                    </td>
                    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#667085;font-size:12px;" title="<?= htmlspecialchars($row['reason']) ?>">
                        <?= htmlspecialchars($row['reason'] ?: '—') ?>
                    </td>
                    <td style="font-size:12px;"><?= htmlspecialchars($row['encoded_by']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Manager Activity Summary -->
        <?php if (!empty($manager_map)): ?>
        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #EAEAEA;">
            <h4 style="font-size:14px;font-weight:700;color:var(--petron-blue);margin-bottom:12px;">Manager Activity Summary</h4>
            <div class="table-scroll">
                <table class="mgr-table">
                    <thead><tr>
                        <th>Manager</th>
                        <th>Role</th>
                        <th>Total Actions</th>
                        <th>Approved</th>
                        <th>Adjusted</th>
                        <th>Rejected</th>
                        <th>Activity</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $max_total = max(array_column($manager_map, 'total')) ?: 1;
                    foreach ($manager_map as $mgr => $ms):
                        $pct = round(($ms['total'] / $max_total) * 100);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($mgr) ?></strong></td>
                        <td><span class="badge badge-default"><?= ucfirst(htmlspecialchars($ms['role'])) ?></span></td>
                        <td><strong><?= $ms['total'] ?></strong></td>
                        <td><span class="badge badge-approved"><?= $ms['approved'] ?></span></td>
                        <td><span class="badge badge-inprog"><?= $ms['adjusted'] ?></span></td>
                        <td><span class="badge badge-rejected"><?= $ms['rejected'] ?></span></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div class="progress-bar-wrap" style="flex:1;"><div class="progress-bar-fill" style="width:<?= $pct ?>%;background:var(--petron-blue);"></div></div>
                                <span style="font-size:11px;font-weight:700;"><?= $pct ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; // end validation ?>

    <!-- ============================================================
         SECTION: AUDIT TRAIL
         ============================================================ -->
    <?php if ($section === 'audit_trail'):
        $at_total    = count($audit_trail_rows);
        $at_success  = count(array_filter($audit_trail_rows, fn($r) => strtolower($r['status'] ?? '') === 'success'));
        $at_failed   = count(array_filter($audit_trail_rows, fn($r) => strtolower($r['status'] ?? '') === 'failed'));
        $at_user_sel = isset($_GET['at_user'])   ? (int)$_GET['at_user']   : 0;
        $at_act_sel  = isset($_GET['at_action']) ? trim($_GET['at_action']) : '';
        $at_mod_sel  = isset($_GET['at_module']) ? trim($_GET['at_module']) : '';
    ?>

    <!-- ── SCOPE NOTICE ──────────────────────────────────────── -->
    <div style="display:flex;align-items:center;gap:10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 16px;margin-bottom:16px;font-size:13px;color:#1e40af;">
        <i class="fa-solid fa-circle-info" style="font-size:16px;flex-shrink:0;"></i>
        <span>
            <strong>Scope:</strong> Shows <strong>Staff</strong> and <strong>Manager</strong> actions only —
            encoding, approvals, adjustments, and system events.
            Admin is an oversight role and is not logged here.
        </span>
    </div>

    <!-- ── SUMMARY CARDS ─────────────────────────────────────── -->
    <div class="stat-grid" style="margin-bottom:20px;">
        <div class="stat-card stat-blue">
            <div class="stat-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $at_total ?></div><div class="stat-label">Total Log Entries</div></div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $at_success ?></div><div class="stat-label">Successful</div></div>
        </div>
        <div class="stat-card stat-red">
            <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
            <div class="stat-body"><div class="stat-num"><?= $at_failed ?></div><div class="stat-label">Failed / Denied</div></div>
        </div>
        <div class="stat-card stat-orange">
            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
            <div class="stat-body">
                <div class="stat-num"><?= count(array_unique(array_column($audit_trail_rows, 'user_name'))) ?></div>
                <div class="stat-label">Active Users</div>
            </div>
        </div>
    </div>

    <!-- ── FILTERS ───────────────────────────────────────────── -->
    <form method="GET" action="manager_reports.php" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;background:#fff;border:1px solid #EAEAEA;border-radius:10px;padding:12px 16px;margin-bottom:16px;">
        <input type="hidden" name="section" value="audit_trail">
        <input type="hidden" name="range"   value="<?= htmlspecialchars($range) ?>">
        <input type="hidden" name="start"   value="<?= htmlspecialchars($date_start) ?>">
        <input type="hidden" name="end"     value="<?= htmlspecialchars($date_end) ?>">

        <label style="font-size:12px;font-weight:600;color:#667085;text-transform:uppercase;letter-spacing:.4px;">User:</label>
        <select name="at_user" style="padding:6px 10px;border:1px solid #EAEAEA;border-radius:6px;font-size:13px;">
            <option value="">All Staff &amp; Managers</option>
            <?php
            $last_role_at = '';
            foreach ($audit_trail_users as $u):
                $rl = ucfirst(strtolower($u['role'] ?? ''));
                if ($rl !== $last_role_at) {
                    if ($last_role_at !== '') echo '</optgroup>';
                    echo '<optgroup label="' . htmlspecialchars($rl) . '">';
                    $last_role_at = $rl;
                }
            ?>
            <option value="<?= (int)$u['id'] ?>" <?= $at_user_sel === (int)$u['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['name']) ?>
            </option>
            <?php endforeach; if ($last_role_at !== '') echo '</optgroup>'; ?>
        </select>

        <label style="font-size:12px;font-weight:600;color:#667085;text-transform:uppercase;letter-spacing:.4px;">Action:</label>
        <select name="at_action" style="padding:6px 10px;border:1px solid #EAEAEA;border-radius:6px;font-size:13px;">
            <option value="">All Actions</option>
            <?php foreach (['Login','Logout','Create','Update','Delete','Approve','Reject','Adjust','Price Change','View'] as $act): ?>
            <option value="<?= $act ?>" <?= $at_act_sel === $act ? 'selected' : '' ?>><?= $act ?></option>
            <?php endforeach; ?>
        </select>

        <label style="font-size:12px;font-weight:600;color:#667085;text-transform:uppercase;letter-spacing:.4px;">Module:</label>
        <select name="at_module" style="padding:6px 10px;border:1px solid #EAEAEA;border-radius:6px;font-size:13px;">
            <option value="">All Modules</option>
            <?php foreach (['transactions','job_orders','deliveries','inventory','fuel','users','system'] as $mod): ?>
            <option value="<?= $mod ?>" <?= $at_mod_sel === $mod ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$mod)) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" style="padding:6px 16px;background:var(--petron-blue);color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">
            <i class="fa-solid fa-magnifying-glass"></i> Filter
        </button>
        <a href="manager_reports.php?section=audit_trail&range=<?= urlencode($range) ?>&start=<?= urlencode($date_start) ?>&end=<?= urlencode($date_end) ?>"
           style="padding:6px 14px;background:#f8fafc;color:#374151;border:1px solid #EAEAEA;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;">
            <i class="fa-solid fa-rotate-left"></i> Reset
        </a>
    </form>

    <!-- ── EXPORT BAR ────────────────────────────────────────── -->
    <?php
    $at_exp_qs = http_build_query([
        'section' => 'audit_trail', 'range' => $range,
        'start'   => $date_start,   'end'   => $date_end,
        'at_user' => $at_user_sel,  'at_action' => $at_act_sel, 'at_module' => $at_mod_sel,
    ]);
    ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
        <span style="font-size:12px;font-weight:600;color:#667085;text-transform:uppercase;letter-spacing:.4px;"><i class="fa-solid fa-download"></i> Export:</span>
        <a href="manager_reports.php?<?= $at_exp_qs ?>&export=csv&format=csv"
           style="padding:6px 14px;background:#22c55e;color:#fff;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;">
            <i class="fa-solid fa-file-csv"></i> CSV
        </a>
    </div>

    <!-- ── AUDIT LOG TABLE ───────────────────────────────────── -->
    <div class="rpt-card">
        <div class="rpt-card-head">
            <h3><i class="fa-solid fa-shield-halved"></i> Audit Trail
                <span class="badge-count"><?= $at_total ?></span>
            </h3>
        </div>
        <?php if (empty($audit_trail_rows)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-shield-halved"></i>
            <p>No audit log entries found for this period.</p>
        </div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="mgr-table">
                <thead><tr>
                    <th>Date &amp; Time</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Details</th>
                    <th>IP Address</th>
                    <th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($audit_trail_rows as $row):
                    $sl = strtolower($row['status'] ?? '');
                    $status_badge = $sl === 'success'  ? 'badge-approved'
                                  : ($sl === 'failed'  ? 'badge-rejected'
                                  : ($sl === 'pending' ? 'badge-pending' : 'badge-default'));
                    $action_lc = strtolower($row['action_type'] ?? '');
                    $action_badge = in_array($action_lc, ['approve','approved','login','create']) ? 'badge-approved'
                                  : (in_array($action_lc, ['reject','rejected','delete','rbac deny']) ? 'badge-rejected'
                                  : (in_array($action_lc, ['adjust','update','price change']) ? 'badge-inprog' : 'badge-default'));
                ?>
                <tr>
                    <td style="white-space:nowrap;">
                        <strong><?= date('M j, Y', strtotime($row['created_at'])) ?></strong>
                        <small style="display:block;color:#667085;"><?= date('g:i A', strtotime($row['created_at'])) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($row['user_name'] ?? '—') ?></strong>
                    </td>
                    <td>
                        <span class="badge badge-default"><?= ucfirst(htmlspecialchars($row['user_role'] ?? '—')) ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $action_badge ?>"><?= htmlspecialchars($row['action_type'] ?? '—') ?></span>
                    </td>
                    <td>
                        <span class="badge badge-default"><?= htmlspecialchars($row['entity_type'] ?? '—') ?></span>
                    </td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;"
                        title="<?= htmlspecialchars($row['action_details'] ?? '') ?>">
                        <?= htmlspecialchars($row['action_details'] ?? '—') ?>
                    </td>
                    <td style="font-size:12px;color:#667085;"><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
                    <td>
                        <span class="badge <?= $status_badge ?>"><?= htmlspecialchars($row['status'] ?? '—') ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($at_total >= 500): ?>
        <div style="text-align:center;padding:10px;font-size:12px;color:#667085;">
            <i class="fa-solid fa-circle-info"></i> Showing latest 500 entries. Use CSV export for the full dataset.
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; // end audit_trail ?>

</div><!-- /.page-content -->


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    'use strict';

    // ── Shared palette ──────────────────────────────────────────
    const BLUE   = '#00264D';
    const RED    = '#CC0000';
    const GREEN  = '#22c55e';
    const ORANGE = '#f59e0b';
    const PURPLE = '#8b5cf6';
    const TEAL   = '#14b8a6';
    const INDIGO = '#6366f1';
    const ROSE   = '#f43f5e';

    const STATUS_COLORS = {
        'Pending Validation': ORANGE,
        'Pending':            ORANGE,
        'Approved':           GREEN,
        'Validated':          TEAL,
        'Confirmed':          TEAL,
        'In Progress':        INDIGO,
        'Completed':          GREEN,
        'Rejected':           RED,
        'Cancelled':          '#9ca3af',
        'Flagged':            ROSE,
        'Discrepancy':        ROSE,
    };

    function statusColor(label) {
        for (const [key, color] of Object.entries(STATUS_COLORS)) {
            if (label && label.toLowerCase().includes(key.toLowerCase())) return color;
        }
        return BLUE;
    }

    function makeGradient(ctx, color) {
        const g = ctx.createLinearGradient(0, 0, 0, ctx.canvas.height);
        g.addColorStop(0, color + '33');
        g.addColorStop(1, color + '00');
        return g;
    }

    // ── Custom range toggle ──────────────────────────────────────
    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            const wrap = document.getElementById('custom-range-inputs');
            const hidden = document.getElementById('range-hidden');
            if (this.textContent.trim() === 'Custom') {
                e.preventDefault();
                wrap.style.display = 'flex';
                if (hidden) hidden.value = 'custom';
            } else {
                wrap.style.display = 'none';
            }
        });
    });

    // ── SECTION: SALES ───────────────────────────────────────────
    // Sales charts have been removed as per requirements
    // Sales data is now displayed in table format with proper fuel and merchandise breakdown

    // ── SECTION: JOB ORDERS ──────────────────────────────────────
    // Job Orders charts have been removed as per requirements
    // Job Orders data is now displayed in table format with service costs and staff performance

    // ── SECTION: BALANCES ────────────────────────────────────────
    // Customer Balances charts have been removed as per requirements
    // Customer Balances data is now displayed in table format with due dates and payment status

    // ── SECTION: DELIVERIES ──────────────────────────────────────
    // Deliveries charts have been removed as per requirements
    // Deliveries data is now displayed in table format with validation flow monitoring

    // ── SECTION: STAFF ───────────────────────────────────────────
    // Staff Performance charts have been removed as per requirements
    // Staff Performance data is now displayed in table format with comprehensive performance metrics

    // ── SECTION: VALIDATION ──────────────────────────────────────
    // Validation Logs charts have been removed as per requirements
    // Validation Logs data is now displayed in table format with comprehensive audit trail information

})();
</script>

  </main>

  <style>
    .fixed-footer {
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 40px !important;
        background-color: #ffffff !important;
        border-top: 1px solid #e0e0e0 !important;
        z-index: 990 !important;
        display: flex !important;
        align-items: center !important;
        font-size: 0.85em !important;
        color: #666666 !important;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1) !important;
    }
    
    /* Footer always attached to sidebar - full width */
    @media (max-width: 991px) {
        .fixed-footer {
            left: 0 !important;
            width: 100% !important;
        }
    }
    
    /* Ensure footer is always visible */
    .fixed-footer * {
        pointer-events: auto !important;
    }
    
    .footer-sidebar-area {
        width: 280px !important;
        height: 100% !important;
        background-color: #ffffff !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        color: #666666 !important;
        font-size: 0.85em !important;
        font-weight: 500 !important;
        transition: width 0.3s ease !important;
    }
    
    .footer-content {
        flex: 1 !important;
        display: flex !important;
        align-items: center !important;
        padding: 0 20px !important;
        height: 100% !important;
        margin-left: 0 !important;
    }
    
    .footer-left {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        flex-shrink: 0 !important;
    }
    
    .footer-center {
        flex-grow: 1 !important;
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
    }
    
    .footer-right {
        display: flex !important;
        align-items: center !important;
        gap: 20px !important;
        flex-shrink: 0 !important;
    }
    
    /* Sidebar collapsed state */
    body.sidebar-collapsed .footer-sidebar-area {
        width: 70px !important;
    }
    
    .footer-text {
        font-size: 0.85em !important;
        color: #666666 !important;
        font-weight: 500 !important;
    }
    
    .footer-clock {
        font-size: 0.85em !important;
        color: #666666 !important;
        font-weight: 500 !important;
        white-space: nowrap !important;
    }
    
    .footer-clock i {
        margin-right: 5px !important;
        color: var(--petron-blue) !important;
    }
    
    /* Footer Toggle Button Styling */
    .footer-toggle {
        background: var(--petron-blue) !important;
        border: none !important;
        color: white !important;
        font-size: 14px !important;
        cursor: pointer !important;
        padding: 8px 12px !important;
        border-radius: 6px !important;
        transition: all 0.3s ease !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin-right: 15px !important;
        min-width: 36px !important;
        height: 36px !important;
    }

    .footer-toggle:hover {
        background: #0040a0 !important;
        transform: translateY(-1px) !important;
        box-shadow: 0 2px 8px rgba(0, 47, 112, 0.3) !important;
    }

    .footer-toggle:active {
        transform: translateY(0) !important;
    }
    
    .footer-toggle i {
        font-size: 14px !important;
        margin: 0 !important;
    }
    
    /* Override any conflicting styles */
    body {
        padding-bottom: 40px !important; /* Account for fixed footer */
    }
    
    main {
        padding-bottom: 60px !important; /* Account for fixed footer */
    }
    
    /* Toggle Scroll Button Styling */
    .toggle-scroll-btn {
        position: fixed;
        bottom: 40px; /* flush against the top of the footer — out of content area */
        right: 20px;
        width: 40px;
        height: 40px;
        background: var(--petron-blue, #002F6C);
        border: 2px solid #ffffff;
        border-radius: 50%;
        color: white;
        font-size: 14px;
        cursor: pointer;
        z-index: 10001; /* Above footer (9999) */
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 14px rgba(0, 47, 112, 0.35), 0 2px 4px rgba(0,0,0,0.12);
        /* Hidden by default — shown only when scroll is needed */
        opacity: 0;
        transform: scale(0.75) translateY(8px);
        pointer-events: none;
        transition: opacity 0.25s ease, transform 0.25s ease, background 0.2s ease, box-shadow 0.2s ease;
    }

    .toggle-scroll-btn.visible {
        opacity: 1;
        transform: scale(1) translateY(0);
        pointer-events: auto;
    }

    .toggle-scroll-btn:hover {
        background: #0040a0;
        box-shadow: 0 6px 18px rgba(0, 47, 112, 0.45), 0 3px 6px rgba(0,0,0,0.15);
        transform: scale(1.08) translateY(-1px);
    }

    .toggle-scroll-btn:active {
        transform: scale(0.94) translateY(0);
        box-shadow: 0 2px 6px rgba(0, 47, 112, 0.25);
    }

    /* Red highlight while the page is scrolling */
    .toggle-scroll-btn.scrolling {
        background: var(--petron-red, #E30613) !important;
        border-color: #ffffff !important;
        box-shadow: 0 4px 16px rgba(227, 6, 19, 0.5), 0 2px 6px rgba(0,0,0,0.15) !important;
        transform: scale(1.12) translateY(-1px) !important;
    }

    /* Arrow icon — no CSS rotation needed, icon class is swapped directly in JS */
    .toggle-scroll-btn i {
        display: block;
        line-height: 1;
        transition: none;
    }

    /* .arrow-up is kept as a state marker for JS but does NOT rotate the icon */
    .toggle-scroll-btn.arrow-up i {
        /* intentionally empty — icon swap handled in JS */
    }

    /* Mobile */
    @media (max-width: 768px) {
        .toggle-scroll-btn {
            width: 36px;
            height: 36px;
            font-size: 13px;
            bottom: 40px;
            right: 12px;
        }
    }
</style>

  <!-- TOGGLE SCROLL BUTTON — fixed bottom-right, above footer -->
  <button id="toggleScrollBtn" class="toggle-scroll-btn" aria-label="Scroll to bottom" title="Scroll to bottom">
    <i class="fas fa-arrow-down"></i>
  </button>

  <!-- FIXED FOOTER -->
  <footer class="fixed-footer">
    <div class="footer-sidebar-area">
      <!-- Empty sidebar area - white background -->
    </div>
    <div class="footer-content">
      <div class="footer-left">
        <!-- Left content can be added here if needed -->
      </div>
      <div class="footer-center">
        <span class="footer-text">© 2026 Petron Management System. All rights reserved.</span>
      </div>
      <div class="footer-right">
        <span id="footer-clock" class="footer-clock"></span>
      </div>
    </div>
    
    <script>
        // Footer is now attached to sidebar - no positioning logic needed
        // Footer automatically adjusts with sidebar CSS transitions
    </script>
  </footer>

  <div class="toast" id="toast"></div>
  
  <!-- Bootstrap JavaScript -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  
  <script src="../assets/js/app.js"></script>
</main>

  <script>
    function updateFooterClock() {
        const footerClock = document.getElementById('footer-clock');
        if (!footerClock) return;
        const now = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
        footerClock.innerHTML = '<i class="far fa-clock"></i> ' + now.toLocaleDateString('en-US', options);
    }
    setInterval(updateFooterClock, 1000);
    updateFooterClock();
    
    // Toggle Scroll Button — targets .main (the real scrollable container on desktop)
    (function () {
        'use strict';

        var btn = document.getElementById('toggleScrollBtn');
        if (!btn) return;

        // The scrollable container is <main class="main"> on desktop (body overflow:hidden).
        // On mobile the page itself scrolls, so we fall back to document.documentElement.
        function getScroller() {
            var main = document.querySelector('main.main');
            if (main && main.scrollHeight > main.clientHeight) return main;
            // fallback: window / documentElement
            return null;
        }

        var isVisible  = false;
        var isAtBottom = false;

        function checkScrollNeeded(scroller) {
            if (scroller) {
                return scroller.scrollHeight > scroller.clientHeight + 4;
            }
            return document.documentElement.scrollHeight > window.innerHeight + 4;
        }

        function getScrollTop(scroller) {
            return scroller ? scroller.scrollTop
                            : (window.pageYOffset || document.documentElement.scrollTop);
        }

        function getScrollMax(scroller) {
            if (scroller) return scroller.scrollHeight - scroller.clientHeight;
            return document.documentElement.scrollHeight - window.innerHeight;
        }

        function update() {
            var scroller   = getScroller();
            var needed     = checkScrollNeeded(scroller);
            var scrollTop  = getScrollTop(scroller);
            var scrollMax  = getScrollMax(scroller);

            // Hide entirely when content fits on screen
            if (!needed) {
                if (isVisible) { btn.classList.remove('visible'); isVisible = false; }
                return;
            }

            // At bottom = within 6px of max scroll
            isAtBottom = scrollTop >= scrollMax - 6;

            // Show button whenever scroll is possible
            if (!isVisible) { btn.classList.add('visible'); isVisible = true; }

            var icon = btn.querySelector('i');

            if (isAtBottom) {
                // User is at the BOTTOM → arrow points UP → click will scroll to top
                btn.classList.add('arrow-up');
                btn.setAttribute('aria-label', 'Scroll to top');
                btn.setAttribute('title', 'Scroll to top');
                if (icon) { icon.className = 'fas fa-arrow-up'; icon.style.transform = ''; }
            } else {
                // User is at the TOP or middle → arrow points DOWN → click will scroll to bottom
                btn.classList.remove('arrow-up');
                btn.setAttribute('aria-label', 'Scroll to bottom');
                btn.setAttribute('title', 'Scroll to bottom');
                if (icon) { icon.className = 'fas fa-arrow-down'; icon.style.transform = ''; }
            }
        }

        // Red highlight while scrolling is in progress
        var scrollingTimer = null;
        function markScrolling() {
            btn.classList.add('scrolling');
            clearTimeout(scrollingTimer);
            scrollingTimer = setTimeout(function () {
                btn.classList.remove('scrolling');
            }, 600); // stays red for 600ms after scroll stops
        }

        function doScroll() {
            var scroller = getScroller();
            if (isAtBottom) {
                if (scroller) scroller.scrollTo({ top: 0, behavior: 'smooth' });
                else window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                if (scroller) scroller.scrollTo({ top: scroller.scrollHeight, behavior: 'smooth' });
                else window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
            }
            // Flip icon immediately after click so it feels responsive
            setTimeout(update, 400);
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            doScroll();
        });

        // Attach scroll listener to the right target
        function attachScrollListener() {
            var scroller = document.querySelector('main.main');
            if (scroller) {
                scroller.addEventListener('scroll', function () {
                    markScrolling();
                    update();
                }, { passive: true });
            }
            // Also listen on window for mobile fallback
            window.addEventListener('scroll', function () {
                markScrolling();
                update();
            }, { passive: true });
        }

        // Re-check on resize (content height may change)
        window.addEventListener('resize', function () { setTimeout(update, 80); }, { passive: true });

        // Init
        attachScrollListener();
        // Run after DOM + any dynamic content settles
        setTimeout(update, 150);
        setTimeout(update, 600);
    })();
  </script>
</body>
</html>

<?php include __DIR__ . '/../partials/footer.php'; ?>
