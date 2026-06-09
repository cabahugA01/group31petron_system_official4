<?php
// ============================================================
// Admin Dashboard – public/admin_dashboard.php
// Complete redesign: Centralized station oversight, compliance monitoring, and analytical reporting.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin access required.';
    header('Location: staff_dashboard.php'); exit;
}
if ($station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

// ── Station Name ──────────────────────────────────────────
$station_name = 'Station';
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: 'Station';
} catch (Exception $e) {}

// ── Date Range ────────────────────────────────────────────
$quick     = trim($_GET['quick']     ?? 'month');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
switch ($quick) {
    case 'today':      $date_from = $date_to = date('Y-m-d'); break;
    case 'week':       $date_from = date('Y-m-d', strtotime('monday this week')); $date_to = date('Y-m-d'); break;
    case 'month':      $date_from = date('Y-m-01'); $date_to = date('Y-m-d'); break;
    case 'last_month': $date_from = date('Y-m-01', strtotime('first day of last month')); $date_to = date('Y-m-t', strtotime('last day of last month')); break;
    default:           if (empty($date_from)) $date_from = date('Y-m-01'); if (empty($date_to)) $date_to = date('Y-m-d'); break;
}

// ── Helpers ───────────────────────────────────────────────
function adm_val(PDO $pdo, string $sql, array $p = [], $default = 0) {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn() ?? $default; }
    catch (Exception $e) { return $default; }
}
function adm_rows(PDO $pdo, string $sql, array $p = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Exception $e) { return []; }
}

// ══════════════════════════════════════════════════════════
// 1. SUMMARY METRICS (KPIs)
// ══════════════════════════════════════════════════════════
// Total Sales
$fuel_sales = (float) adm_val($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?", [$station_id,$date_from,$date_to]);
$merch_sales = (float) adm_val($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?", [$station_id,$date_from,$date_to]);
$total_sales_val = $fuel_sales + $merch_sales;

// Fuel Stock (Liters)
$fuel_stock_val = (float) adm_val($pdo, "SELECT COALESCE(SUM(current_stock),0) FROM fuel_inventory WHERE station_id=?", [$station_id]);

// Merchandise Stock
$merch_stock_val = (float) adm_val($pdo, "SELECT COALESCE(SUM(stock_level),0) FROM inventory WHERE station_id=?", [$station_id]);

// Pending Deliveries
$pending_deliveries_val = (int) adm_val($pdo, "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status LIKE 'Pending%'", [$station_id]);

// Active Users (Staff, Manager, Customers)
$active_staff = (int) adm_val($pdo, "SELECT COUNT(*) FROM users WHERE station_id=? AND status = 'Active' AND role='Staff'", [$station_id]);
$active_managers = (int) adm_val($pdo, "SELECT COUNT(*) FROM users WHERE station_id=? AND status = 'Active' AND role='Manager'", [$station_id]);
$active_customers = (int) adm_val($pdo, "SELECT COUNT(*) FROM customers WHERE station_id=? AND account_status = 'Active'", [$station_id]);
$total_active_users = $active_staff + $active_managers + $active_customers;


// ══════════════════════════════════════════════════════════
// 2. TRANSACTION CHART DATA
// ══════════════════════════════════════════════════════════
// Daily Sales Totals: cash vs card vs e-wallet vs e-fuel card
$daily_sales_data = adm_rows($pdo, "
    SELECT DATE(transaction_date) AS date,
           SUM(CASE WHEN LOWER(payment_method) = 'cash' THEN total_amount ELSE 0 END) AS cash,
           SUM(CASE WHEN LOWER(payment_method) IN ('card', 'credit', 'credit_card', 'debit_card') THEN total_amount ELSE 0 END) AS card,
           SUM(CASE WHEN LOWER(payment_method) IN ('e-wallet', 'ewallet', 'gcash', 'paymaya', 'grabpay') THEN total_amount ELSE 0 END) AS ewallet,
           SUM(CASE WHEN LOWER(payment_method) IN ('e-fuel card', 'efuel', 'e-fuel', 'efuel card') OR (efuel_card_number IS NOT NULL AND efuel_card_number != '') THEN total_amount ELSE 0 END) AS efuel
    FROM (
        SELECT transaction_date, payment_method, total_amount, NULL AS efuel_card_number FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
        UNION ALL
        SELECT transaction_date, payment_method, total_amount, efuel_card_number FROM merchandise_transactions WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
    ) combined
    GROUP BY DATE(transaction_date)
    ORDER BY DATE(transaction_date) ASC
", [$station_id, $date_from, $date_to, $station_id, $date_from, $date_to]);

// Category distribution
$category_sales_data = adm_rows($pdo, "
    SELECT COALESCE(c.name, 'Uncategorized') AS category, SUM(total_amount) AS total
    FROM merchandise_transactions mt
    LEFT JOIN products p ON mt.item_sku = p.sku AND mt.station_id = p.station_id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE mt.station_id = ? AND DATE(mt.transaction_date) BETWEEN ? AND ?
    GROUP BY category
    UNION ALL
    SELECT 'Fuel' AS category, SUM(total_amount) AS total
    FROM fuel_transactions
    WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
", [$station_id, $date_from, $date_to, $station_id, $date_from, $date_to]);

// Monthly revenue trend (last 6 months)
$monthly_revenue_data = adm_rows($pdo, "
    SELECT DATE_FORMAT(transaction_date, '%b %Y') AS month, SUM(total_amount) AS total, DATE_FORMAT(transaction_date, '%Y-%m') AS order_month
    FROM (
        SELECT transaction_date, total_amount FROM fuel_transactions WHERE station_id = ? AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        UNION ALL
        SELECT transaction_date, total_amount FROM merchandise_transactions WHERE station_id = ? AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    ) combined
    GROUP BY DATE_FORMAT(transaction_date, '%b %Y'), DATE_FORMAT(transaction_date, '%Y-%m')
    ORDER BY order_month ASC
", [$station_id, $station_id]);


// ══════════════════════════════════════════════════════════
// 3. FUEL MANAGEMENT CHART DATA
// ══════════════════════════════════════════════════════════
// Tank Stock levels
$tank_levels_data = adm_rows($pdo, "
    SELECT fuel_type, current_stock, capacity 
    FROM fuel_inventory 
    WHERE station_id = ?
", [$station_id]);

// Liters sold per fuel type
$fuel_sold_data = adm_rows($pdo, "
    SELECT fuel_type, SUM(liters_sold) AS liters
    FROM fuel_transactions
    WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
    GROUP BY fuel_type
", [$station_id, $date_from, $date_to]);

// Variance expected vs actual
$fuel_variance_data = adm_rows($pdo, "
    SELECT report_date AS date, fuel_type, expected_stock, actual_stock, variance_liters AS variance
    FROM fuel_variance_reports
    WHERE station_id = ? AND report_date BETWEEN ? AND ?
    ORDER BY report_date ASC
", [$station_id, $date_from, $date_to]);


// ══════════════════════════════════════════════════════════
// 4. DELIVERIES CHART DATA
// ══════════════════════════════════════════════════════════
// Delivery status breakdown
$delivery_status_data = adm_rows($pdo, "
    SELECT status, COUNT(*) AS count
    FROM deliveries_oversight
    WHERE station_id = ? AND delivery_date BETWEEN ? AND ?
    GROUP BY status
", [$station_id, $date_from, $date_to]);

// PO vs Actual Quantity (stacked bar)
$po_vs_actual_data = adm_rows($pdo, "
    SELECT delivery_ref, expected_quantity, actual_quantity
    FROM deliveries_oversight
    WHERE station_id = ? AND delivery_date BETWEEN ? AND ?
    ORDER BY delivery_date DESC
    LIMIT 8
", [$station_id, $date_from, $date_to]);

// Supplier performance
$supplier_perf_data = adm_rows($pdo, "
    SELECT supplier_name,
           SUM(CASE WHEN DATE(delivery_validated_at) <= expected_delivery THEN 1 ELSE 0 END) AS on_time,
           SUM(CASE WHEN DATE(delivery_validated_at) > expected_delivery THEN 1 ELSE 0 END) AS delayed
    FROM purchase_orders
    WHERE station_id = ? AND delivery_validated = 1 AND expected_delivery BETWEEN ? AND ?
    GROUP BY supplier_name
", [$station_id, $date_from, $date_to]);


// ══════════════════════════════════════════════════════════
// 5. INVENTORY CHART DATA
// ══════════════════════════════════════════════════════════
// Stock-in vs stock-out
$stock_in_out_data = adm_rows($pdo, "
    SELECT p.name AS product_name,
           SUM(CASE WHEN it.transaction_type IN ('stock-in', 'receiving', 'delivery', 'adjustment-in') THEN it.quantity ELSE 0 END) AS stock_in,
           SUM(CASE WHEN it.transaction_type IN ('stock-out', 'sale', 'sold', 'adjustment-out') THEN it.quantity ELSE 0 END) AS stock_out
    FROM inventory_transactions it
    JOIN products p ON it.product_id = p.id
    WHERE it.station_id = ? AND DATE(it.created_at) BETWEEN ? AND ?
    GROUP BY p.id, p.name
    ORDER BY (stock_in + stock_out) DESC
    LIMIT 10
", [$station_id, $date_from, $date_to]);

// Inventory trend line
$inventory_trend_data = adm_rows($pdo, "
    SELECT DATE(created_at) AS date,
           SUM(CASE WHEN transaction_type IN ('stock-in', 'receiving', 'delivery', 'adjustment-in') THEN quantity ELSE -quantity END) AS net_change
    FROM inventory_transactions
    WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC
", [$station_id, $date_from, $date_to]);

// Low Stock Alerts List
$low_stock_alerts_data = adm_rows($pdo, "
    SELECT fuel_type AS item_name, current_stock, threshold AS min_level, 'Fuel' AS category
    FROM low_stock_alerts
    WHERE station_id = ? AND status = 'Active'
    UNION ALL
    SELECT name AS item_name, current_stock, min_stock_level AS min_level, 'Merchandise' AS category
    FROM products
    WHERE station_id = ? AND current_stock <= min_stock_level AND status = 'Active'
", [$station_id, $station_id]);


// ══════════════════════════════════════════════════════════
// 6. CUSTOMER CHART DATA
// ══════════════════════════════════════════════════════════
// Purchase distribution
$customer_purchase_data = adm_rows($pdo, "
    SELECT 'Fuel' AS category, COALESCE(SUM(total_amount), 0) AS total
    FROM fuel_transactions
    WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
    UNION ALL
    SELECT 'Merchandise' AS category, COALESCE(SUM(total_amount), 0) AS total
    FROM merchandise_transactions
    WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
", [$station_id, $date_from, $date_to, $station_id, $date_from, $date_to]);

// Top customers by purchase volume
$top_customers_data = adm_rows($pdo, "
    SELECT c.name AS customer_name, COALESCE(SUM(mt.total_amount), 0) AS total_purchases
    FROM customers c
    JOIN merchandise_transactions mt ON c.id = mt.credit_customer_id
    WHERE mt.station_id = ? AND DATE(mt.transaction_date) BETWEEN ? AND ?
    GROUP BY c.id, c.name
    ORDER BY total_purchases DESC
    LIMIT 10
", [$station_id, $date_from, $date_to]);

// Complaints/returns trend
$complaints_trend_data = adm_rows($pdo, "
    SELECT DATE(n.created_at) AS date, COUNT(*) AS count
    FROM notifications n
    JOIN users u ON n.user_id = u.id
    WHERE u.station_id = ? AND n.title = 'Customer Issue' AND DATE(n.created_at) BETWEEN ? AND ?
    GROUP BY DATE(n.created_at)
    ORDER BY date ASC
", [$station_id, $date_from, $date_to]);


// ══════════════════════════════════════════════════════════
// 7. CALENDAR & WEEKLY SCHEDULING (Sunday to Saturday format)
// ══════════════════════════════════════════════════════════
$week_offset   = (int)($_GET['week'] ?? 0);
$start_of_week = date('Y-m-d', strtotime("sunday this week +{$week_offset} weeks"));
$end_of_week   = date('Y-m-d', strtotime("saturday this week +{$week_offset} weeks"));

$cal_events = [];

// Green = Deliveries
$del_events = adm_rows($pdo, "
    SELECT id, supplier, product, quantity, status, DATE(COALESCE(delivery_date, created_at)) AS edate 
    FROM deliveries_oversight 
    WHERE station_id=? AND DATE(COALESCE(delivery_date, created_at)) BETWEEN ? AND ?
", [$station_id, $start_of_week, $end_of_week]);
foreach ($del_events as $x) {
    $cal_events[] = [
        'type' => 'deliveries',
        'title' => "Del: " . ($x['product'] ?? '') . " – " . ($x['supplier'] ?? ''),
        'description' => "Qty: " . number_format($x['quantity'], 2) . " Status: " . ($x['status'] ?? 'Pending'),
        'date' => $x['edate'],
        'color_class' => 'evt-green'
    ];
}

// Blue = Staff Shifts & Tasks
$shift_events = adm_rows($pdo, "
    SELECT ss.id, ss.shift, ss.status, ss.scheduled_date AS edate, u.name AS sname 
    FROM staff_schedules ss 
    JOIN users u ON u.user_id = ss.user_id 
    WHERE u.station_id = ? AND ss.scheduled_date BETWEEN ? AND ?
", [$station_id, $start_of_week, $end_of_week]);
foreach ($shift_events as $x) {
    $cal_events[] = [
        'type' => 'staff',
        'title' => "Shift: " . ($x['sname'] ?? '') . " (" . ($x['shift'] ?? '') . ")",
        'description' => "Status: " . ($x['status'] ?? 'Scheduled'),
        'date' => $x['edate'],
        'color_class' => 'evt-blue'
    ];
}

$task_events = adm_rows($pdo, "
    SELECT st.id, st.task, st.priority, st.status, st.due_date AS edate, u.name AS sname 
    FROM staff_tasks st 
    JOIN users u ON u.user_id = st.user_id 
    WHERE u.station_id = ? AND st.due_date BETWEEN ? AND ?
", [$station_id, $start_of_week, $end_of_week]);
foreach ($task_events as $x) {
    $cal_events[] = [
        'type' => 'staff',
        'title' => "Task: " . (strlen($x['task']) > 20 ? substr($x['task'], 0, 20) . '...' : $x['task']) . " (" . ($x['sname'] ?? '') . ")",
        'description' => "Priority: " . ($x['priority'] ?? 'Normal') . " Status: " . ($x['status'] ?? 'Pending'),
        'date' => $x['edate'],
        'color_class' => 'evt-blue'
    ];
}

// Yellow = Meetings & Calibrations
$calib_events = adm_rows($pdo, "
    SELECT id, pump_number, fuel_type, DATE(encoded_at) AS edate 
    FROM calibration_logs 
    WHERE station_id = ? AND DATE(encoded_at) BETWEEN ? AND ?
", [$station_id, $start_of_week, $end_of_week]);
foreach ($calib_events as $x) {
    $cal_events[] = [
        'type' => 'meetings',
        'title' => "Calib: Pump #" . ($x['pump_number'] ?? '') . " (" . ($x['fuel_type'] ?? '') . ")",
        'description' => "Calibration session completed",
        'date' => $x['edate'],
        'color_class' => 'evt-yellow'
    ];
}

// Red = Manager Actions
$manager_actions = adm_rows($pdo, "
    SELECT id, service_type, customer_name, status, DATE(created_at) AS edate 
    FROM job_orders 
    WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ?
", [$station_id, $start_of_week, $end_of_week]);
foreach ($manager_actions as $x) {
    $cal_events[] = [
        'type' => 'manager',
        'title' => "JO: " . ($x['service_type'] ?? '') . " – " . ($x['customer_name'] ?? ''),
        'description' => "Status: " . ($x['status'] ?? 'Pending'),
        'date' => $x['edate'],
        'color_class' => 'evt-red'
    ];
}


// ══════════════════════════════════════════════════════════
// 8. AUDIT TRAIL DATA (Managers, Staff, Admins. Exclude SuperAdmin)
// ══════════════════════════════════════════════════════════
$audit_trail_data = adm_rows($pdo, "
    SELECT al.id, al.created_at, al.user_id, u.username, u.role, u.name AS user_name, al.action_type, al.entity_type AS module, al.action_details AS remarks, al.ip_address, al.user_agent
    FROM audit_logs al
    JOIN users u ON al.user_id = u.id
    WHERE u.station_id = ? AND u.role IN ('Manager', 'Staff', 'Admin')
    ORDER BY al.created_at DESC
    LIMIT 100
", [$station_id]);


// ══════════════════════════════════════════════════════════
// 9. LIVE SYSTEM NOTIFICATIONS & ALERTS
// ══════════════════════════════════════════════════════════
$variance_alerts_open = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM fuel_variance_reports WHERE station_id=? AND status IN ('open','flagged','pending')",
    [$station_id]);

$active_notifications = [];
if ($variance_alerts_open > 0) {
    $active_notifications[] = [
        'level' => 'critical',
        'title' => 'Variance Alerts Active',
        'message' => "There are {$variance_alerts_open} unresolved variance alerts requiring immediate investigation."
    ];
}
if ($pending_deliveries_val > 0) {
    $active_notifications[] = [
        'level' => 'warning',
        'title' => 'Pending Deliveries awaiting Validation',
        'message' => "{$pending_deliveries_val} delivery records need manager/admin validation and final stock-in approval."
    ];
}
foreach ($low_stock_alerts_data as $alert) {
    $active_notifications[] = [
        'level' => 'info',
        'title' => "Low Stock: {$alert['item_name']}",
        'message' => "Current stock of {$alert['item_name']} ({$alert['current_stock']}) is below minimum threshold ({$alert['min_level']})."
    ];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Global Styles ── */
:root {
  --transition-speed: 0.25s;
  --font-family: 'Outfit', 'Inter', sans-serif;
}

body {
  font-family: var(--font-family);
  background-color: var(--bg-body, #f4f6f9);
  color: var(--text-primary, #1e293b);
  margin: 0;
}

.adm-wrap {
  padding: 24px;
  max-width: 1600px;
  margin: 0 auto;
}

/* ── Typography & Header ── */
.adm-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  flex-wrap: wrap;
  gap: 16px;
}

.adm-title h1 {
  font-size: 28px;
  font-weight: 700;
  color: var(--petron-blue, #00264D);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}

.adm-title p {
  margin: 4px 0 0 0;
  font-size: 14px;
  color: var(--text-secondary, #64748b);
}

.adm-filter-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.adm-btn-group {
  display: flex;
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  padding: 4px;
}

.adm-btn-group a {
  padding: 6px 12px;
  font-size: 13px;
  font-weight: 500;
  color: var(--text-secondary, #64748b);
  text-decoration: none;
  border-radius: 6px;
  transition: all var(--transition-speed);
}

.adm-btn-group a.active {
  background-color: var(--petron-blue, #00264D);
  color: #fff;
}

.adm-date-form {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--bg-card, #ffffff);
  padding: 4px 12px;
  border-radius: 8px;
  border: 1px solid var(--border-color, #e2e8f0);
}

.adm-date-form input[type="date"] {
  border: none;
  background: transparent;
  color: var(--text-primary);
  font-family: inherit;
  font-size: 13px;
  outline: none;
}

.adm-date-form button {
  background: var(--petron-blue, #00264D);
  color: #fff;
  border: none;
  padding: 6px 12px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  transition: background var(--transition-speed);
}

.adm-date-form button:hover {
  background: var(--petron-red, #CC0000);
}

/* ── KPI Cards ── */
.adm-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 20px;
  margin-bottom: 28px;
}

.adm-card {
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 12px;
  padding: 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
  transition: transform var(--transition-speed), box-shadow var(--transition-speed);
  position: relative;
  overflow: hidden;
}

.adm-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 4px;
  height: 100%;
}

.adm-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
}

.adm-card.blue::before { background-color: var(--petron-blue, #00264D); }
.adm-card.red::before { background-color: var(--petron-red, #CC0000); }
.adm-card.green::before { background-color: #22c55e; }
.adm-card.orange::before { background-color: #f59e0b; }
.adm-card.purple::before { background-color: #8b5cf6; }

.adm-card-details h3 {
  font-size: 13px;
  color: var(--text-secondary, #64748b);
  margin: 0 0 6px 0;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.adm-card-val {
  font-size: 24px;
  font-weight: 700;
  margin: 0;
  color: var(--text-primary);
}

.adm-card-sub {
  font-size: 12px;
  color: var(--text-secondary, #64748b);
  margin: 4px 0 0 0;
}

.adm-card-icon {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  opacity: 0.85;
}

.adm-card.blue .adm-card-icon { background: rgba(0,38,77,0.1); color: var(--petron-blue); }
.adm-card.red .adm-card-icon { background: rgba(204,0,0,0.1); color: var(--petron-red); }
.adm-card.green .adm-card-icon { background: rgba(34,197,94,0.1); color: #22c55e; }
.adm-card.orange .adm-card-icon { background: rgba(245,158,11,0.1); color: #f59e0b; }
.adm-card.purple .adm-card-icon { background: rgba(139,92,246,0.1); color: #8b5cf6; }

/* ── Layout Grid ── */
.adm-grid {
  display: grid;
  grid-template-columns: 3fr 1fr;
  gap: 24px;
  margin-bottom: 28px;
}

@media (max-width: 1200px) {
  .adm-grid { grid-template-columns: 1fr; }
}

/* ── Tabs & Charts Area ── */
.adm-main-panel {
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}

.adm-tabs-container {
  display: flex;
  border-bottom: 2px solid var(--border-color, #e2e8f0);
  margin-bottom: 24px;
  gap: 8px;
  overflow-x: auto;
}

.adm-tab-btn {
  background: none;
  border: none;
  padding: 12px 18px;
  font-size: 14px;
  font-weight: 600;
  color: var(--text-secondary, #64748b);
  cursor: pointer;
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
  transition: all var(--transition-speed);
  display: flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
}

.adm-tab-btn:hover {
  color: var(--petron-blue, #00264D);
}

.adm-tab-btn.active {
  color: var(--petron-blue, #00264D);
  border-bottom-color: var(--petron-blue, #00264D);
}

.adm-chart-panel {
  display: none;
  animation: fadeIn 0.4s ease;
}

.adm-chart-panel.active {
  display: block;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(6px); }
  to { opacity: 1; transform: translateY(0); }
}

.adm-charts-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 20px;
}

.adm-chart-box {
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  padding: 16px;
  position: relative;
}

.adm-chart-box h4 {
  margin: 0 0 16px 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--text-secondary);
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.adm-chart-canvas-wrap {
  height: 240px;
  position: relative;
}

/* ── Live Alerts Feed ── */
.adm-side-panel {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.adm-alerts-card {
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 12px;
  padding: 20px;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}

.adm-alerts-card h2 {
  font-size: 16px;
  font-weight: 700;
  margin: 0 0 16px 0;
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--petron-blue);
}

.adm-alerts-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-height: 520px;
  overflow-y: auto;
}

.adm-alert-item {
  padding: 12px;
  border-radius: 8px;
  border-left: 4px solid;
  background: var(--bg-body, #f8fafc);
  font-size: 13px;
  line-height: 1.4;
}

.adm-alert-item.critical { border-left-color: var(--petron-red); background: rgba(204,0,0,0.03); }
.adm-alert-item.warning { border-left-color: #f59e0b; background: rgba(245,158,11,0.03); }
.adm-alert-item.info { border-left-color: #3b82f6; background: rgba(59,130,246,0.03); }

.adm-alert-title {
  font-weight: 600;
  margin-bottom: 4px;
}

.adm-alert-msg {
  color: var(--text-secondary);
}

/* ── Weekly Calendar ── */
.adm-calendar-sec {
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 28px;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}

.adm-cal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 20px;
}

.adm-cal-nav {
  display: flex;
  align-items: center;
  gap: 12px;
}

.adm-cal-nav button, .adm-cal-nav a {
  background: var(--bg-body, #f1f5f9);
  border: 1px solid var(--border-color, #e2e8f0);
  color: var(--text-primary);
  padding: 8px 14px;
  border-radius: 8px;
  text-decoration: none;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all var(--transition-speed);
}

.adm-cal-nav button:hover, .adm-cal-nav a:hover {
  background: var(--petron-blue);
  color: #fff;
}

.adm-cal-legend {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.adm-legend-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 500;
}

.adm-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
}

.evt-blue { background-color: #3b82f6; color: #fff; }
.evt-red { background-color: var(--petron-red, #CC0000); color: #fff; }
.evt-green { background-color: #22c55e; color: #fff; }
.evt-yellow { background-color: #eab308; color: #000; }

.adm-cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  overflow: hidden;
}

@media (max-width: 900px) {
  .adm-cal-grid { grid-template-columns: 1fr; }
}

.adm-cal-day {
  border-right: 1px solid var(--border-color, #e2e8f0);
  background: var(--bg-card);
  min-height: 180px;
  display: flex;
  flex-direction: column;
}

.adm-cal-day:last-child { border-right: none; }

.adm-cal-day-hdr {
  background: var(--bg-body, #f8fafc);
  padding: 8px;
  font-size: 12px;
  font-weight: 700;
  border-bottom: 1px solid var(--border-color, #e2e8f0);
  text-align: center;
}

.adm-cal-day.today .adm-cal-day-hdr {
  background-color: var(--petron-blue);
  color: #fff;
}

.adm-cal-events-list {
  padding: 8px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex-grow: 1;
  overflow-y: auto;
  max-height: 240px;
}

.adm-cal-event {
  padding: 6px 8px;
  border-radius: 4px;
  font-size: 11px;
  line-height: 1.3;
  font-weight: 500;
  cursor: pointer;
  transition: transform 0.15s;
}

.adm-cal-event:hover {
  transform: scale(1.02);
}

/* ── Reports Quick Access & Shortcuts ── */
.adm-shortcuts-sec {
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 28px;
}

.adm-shortcuts-sec h2 {
  font-size: 18px;
  font-weight: 700;
  margin: 0 0 16px 0;
  color: var(--petron-blue);
  display: flex;
  align-items: center;
  gap: 8px;
}

.adm-shortcut-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
}

.adm-shortcut-card {
  background: var(--bg-body, #f8fafc);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  padding: 16px;
  text-align: center;
  text-decoration: none;
  color: var(--text-primary);
  font-weight: 600;
  font-size: 13px;
  transition: all var(--transition-speed);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.adm-shortcut-card i {
  font-size: 22px;
  color: var(--petron-blue);
  transition: transform var(--transition-speed);
}

.adm-shortcut-card:hover {
  background-color: var(--petron-blue);
  color: #fff;
  border-color: var(--petron-blue);
}

.adm-shortcut-card:hover i {
  color: #fff;
  transform: translateY(-2px);
}

/* ── Audit Trail Snapshot ── */
.adm-audit-sec {
  background: var(--bg-card, #ffffff);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}

.adm-audit-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  flex-wrap: wrap;
  gap: 16px;
}

.adm-audit-header h2 {
  font-size: 18px;
  font-weight: 700;
  margin: 0;
  color: var(--petron-blue);
  display: flex;
  align-items: center;
  gap: 8px;
}

.adm-audit-filters {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.adm-audit-filters input, .adm-audit-filters select {
  padding: 8px 12px;
  border-radius: 8px;
  border: 1px solid var(--border-color, #e2e8f0);
  background: var(--bg-card);
  color: var(--text-primary);
  font-size: 13px;
  outline: none;
}

.adm-table-wrap {
  overflow-x: auto;
}

.adm-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 13px;
}

.adm-table th, .adm-table td {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border-color, #e2e8f0);
}

.adm-table th {
  font-weight: 700;
  background: var(--bg-body, #f8fafc);
  color: var(--text-secondary);
  text-transform: uppercase;
  font-size: 11px;
  letter-spacing: 0.5px;
}

.adm-table tr:hover td {
  background-color: rgba(0,38,77,0.02);
}

/* Anomalies highlighting */
.adm-table tr.anomaly {
  background-color: rgba(239, 68, 68, 0.05);
}

.adm-table tr.anomaly td {
  border-bottom: 1px solid rgba(239, 68, 68, 0.2);
}

.badge {
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
}

.badge.admin { background: rgba(0,38,77,0.1); color: var(--petron-blue); }
.badge.manager { background: rgba(204,0,0,0.1); color: var(--petron-red); }
.badge.staff { background: rgba(59,130,246,0.1); color: #3b82f6; }
.badge.anomaly-tag { background: #ef4444; color: #fff; }

.export-btn {
  background: var(--petron-blue);
  color: #fff;
  border: none;
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
}

.export-btn:hover {
  background: var(--petron-red);
}

.low-stock-box {
  border: 1px solid var(--border-color);
  border-radius: 8px;
  padding: 16px;
  background: rgba(204,0,0,0.02);
  margin-top: 16px;
}

.low-stock-box h5 {
  margin: 0 0 12px 0;
  color: var(--petron-red);
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 6px;
}
</style>

<div class="adm-wrap">

  <!-- HEADER -->
  <div class="adm-header">
    <div class="adm-title">
      <h1><i class="fas fa-shield-halved"></i> Admin Oversight Hub</h1>
      <p>Petron Station Managerial Auditing, Reporting, &amp; Visual Analytics – <strong><?php echo htmlspecialchars($station_name); ?></strong></p>
    </div>
    
    <div class="adm-filter-bar">
      <div class="adm-btn-group">
        <a href="?quick=today" class="<?php echo $quick === 'today' ? 'active' : ''; ?>">Today</a>
        <a href="?quick=week" class="<?php echo $quick === 'week' ? 'active' : ''; ?>">This Week</a>
        <a href="?quick=month" class="<?php echo $quick === 'month' ? 'active' : ''; ?>">This Month</a>
        <a href="?quick=last_month" class="<?php echo $quick === 'last_month' ? 'active' : ''; ?>">Last Month</a>
      </div>
      
      <form class="adm-date-form" method="GET">
        <input type="hidden" name="quick" value="custom">
        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" required>
        <span style="color:var(--text-secondary); font-size:12px;">to</span>
        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" required>
        <button type="submit"><i class="fas fa-arrow-right"></i></button>
      </form>
    </div>
  </div>

  <!-- SUMMARY CARDS -->
  <div class="adm-kpi-grid">
    <div class="adm-card blue">
      <div class="adm-card-details">
        <h3>Total Sales (₱)</h3>
        <div class="adm-card-val">₱<?php echo number_format($total_sales_val, 2); ?></div>
        <div class="adm-card-sub">Fuel: ₱<?php echo number_format($fuel_sales, 2); ?> | Merch: ₱<?php echo number_format($merch_sales, 2); ?></div>
      </div>
      <div class="adm-card-icon"><i class="fas fa-wallet"></i></div>
    </div>
    
    <div class="adm-card green">
      <div class="adm-card-details">
        <h3>Fuel Stock (Liters)</h3>
        <div class="adm-card-val"><?php echo number_format($fuel_stock_val, 2); ?> L</div>
        <div class="adm-card-sub">Aggregated Tank Inventories</div>
      </div>
      <div class="adm-card-icon"><i class="fas fa-gas-pump"></i></div>
    </div>
    
    <div class="adm-card purple">
      <div class="adm-card-details">
        <h3>Merchandise Inventory</h3>
        <div class="adm-card-val"><?php echo number_format($merch_stock_val, 0); ?> pcs</div>
        <div class="adm-card-sub">Products Stock Level</div>
      </div>
      <div class="adm-card-icon"><i class="fas fa-boxes-stacked"></i></div>
    </div>
    
    <div class="adm-card orange">
      <div class="adm-card-details">
        <h3>Pending Deliveries</h3>
        <div class="adm-card-val"><?php echo $pending_deliveries_val; ?> Records</div>
        <div class="adm-card-sub">Awaiting Manager validation</div>
      </div>
      <div class="adm-card-icon"><i class="fas fa-truck-fast"></i></div>
    </div>
    
    <div class="adm-card red">
      <div class="adm-card-details">
        <h3>Active Users</h3>
        <div class="adm-card-val"><?php echo $total_active_users; ?></div>
        <div class="adm-card-sub">Staff: <?php echo $active_staff; ?> | Mgr: <?php echo $active_managers; ?> | Cust: <?php echo $active_customers; ?></div>
      </div>
      <div class="adm-card-icon"><i class="fas fa-users-viewfinder"></i></div>
    </div>
  </div>

  <!-- MAIN ANALYTICS ROW -->
  <div class="adm-grid">
    <!-- Charts Main Area -->
    <div class="adm-main-panel">
      <div class="adm-tabs-container">
        <button class="adm-tab-btn active" onclick="switchTab('transactions', this)"><i class="fas fa-cash-register"></i> Transactions</button>
        <button class="adm-tab-btn" onclick="switchTab('fuel', this)"><i class="fas fa-gauge-high"></i> Fuel Management</button>
        <button class="adm-tab-btn" onclick="switchTab('deliveries', this)"><i class="fas fa-dolly"></i> Merchandise Deliveries</button>
        <button class="adm-tab-btn" onclick="switchTab('inventory', this)"><i class="fas fa-warehouse"></i> Inventory</button>
        <button class="adm-tab-btn" onclick="switchTab('customers', this)"><i class="fas fa-user-group"></i> Customers</button>
      </div>

      <!-- Tab 1: Transactions -->
      <div id="tab-transactions" class="adm-chart-panel active">
        <div class="adm-charts-grid">
          <div class="adm-chart-box">
            <h4>Daily Sales Breakdown <span style="font-size:11px; font-weight:normal;">Cash vs Card vs E-Wallet vs E-Fuel Card</span></h4>
            <div class="adm-chart-canvas-wrap"><canvas id="dailySalesChart"></canvas></div>
          </div>
          <div class="adm-chart-box">
            <h4>Sales Distribution by Category</h4>
            <div class="adm-chart-canvas-wrap"><canvas id="salesCategoryPie"></canvas></div>
          </div>
          <div class="adm-chart-box">
            <h4>Monthly Revenue Trend <span style="font-size:11px; font-weight:normal;">Last 6 Months</span></h4>
            <div class="adm-chart-canvas-wrap"><canvas id="monthlyRevenueLine"></canvas></div>
          </div>
        </div>
      </div>

      <!-- Tab 2: Fuel Management -->
      <div id="tab-fuel" class="adm-chart-panel">
        <div class="adm-charts-grid">
          <div class="adm-chart-box">
            <h4>Fuel Tank Stock Levels <span style="font-size:11px; font-weight:normal;">Current Levels vs Capacity</span></h4>
            <div class="adm-chart-canvas-wrap"><canvas id="fuelTankGauge"></canvas></div>
          </div>
          <div class="adm-chart-box">
            <h4>Liters Sold per Fuel Type</h4>
            <div class="adm-chart-canvas-wrap"><canvas id="fuelTypeLitersBar"></canvas></div>
          </div>
          <div class="adm-chart-box">
            <h4>Expected vs Actual Pump Variance <span style="font-size:11px; font-weight:normal;">Liters Diff</span></h4>
            <div class="adm-chart-canvas-wrap"><canvas id="fuelPumpVarianceLine"></canvas></div>
          </div>
        </div>
      </div>

      <!-- Tab 3: Merchandise Deliveries -->
      <div id="tab-deliveries" class="adm-chart-panel">
        <div class="adm-charts-grid">
          <div class="adm-chart-box">
            <h4>Delivery Status Breakdown</h4>
            <div class="adm-chart-canvas-wrap"><canvas id="deliveryStatusPie"></canvas></div>
          </div>
          <div class="adm-chart-box">
            <h4>PO vs Actual Quantities Received</h4>
            <div class="adm-chart-canvas-wrap"><canvas id="poVsActualQtyBar"></canvas></div>
          </div>
          <div class="adm-chart-box">
            <h4>Supplier Delivery Performance <span style="font-size:11px; font-weight:normal;">On-Time vs Delayed</span></h4>
            <div class="adm-chart-canvas-wrap"><canvas id="supplierPerformanceLine"></canvas></div>
          </div>
        </div>
      </div>

      <!-- Tab 4: Inventory -->
      <div id="tab-inventory" class="adm-chart-panel">
        <div class="adm-charts-grid">
          <div class="adm-chart-box">
            <h4>Stock-In vs Stock-Out Volumes <span style="font-size:11px; font-weight:normal;">Top 10 Products</span></h4>
            <div class="adm-chart-canvas-wrap"><canvas id="stockInOutBar"></canvas></div>
          </div>
          <div class="adm-chart-box">
            <h4>Inventory Net Flow Trend <span style="font-size:11px; font-weight:normal;">Daily stock change</span></h4>
            <div class="adm-chart-canvas-wrap"><canvas id="inventoryTrendLine"></canvas></div>
          </div>
          <div class="adm-chart-box">
            <h4>Low Stock Flagged Items</h4>
            <div class="adm-chart-canvas-wrap" style="height: auto; max-height:240px; overflow-y:auto;">
              <?php if (empty($low_stock_alerts_data)): ?>
                <div style="text-align:center; padding: 40px 0; color:var(--text-secondary);">
                  <i class="fas fa-check-circle" style="font-size:32px; color:#22c55e; margin-bottom:8px;"></i>
                  <p style="margin:0; font-size:13px;">All inventories are in healthy status.</p>
                </div>
              <?php else: ?>
                <table style="width:100%; border-collapse:collapse; font-size:12px; text-align:left;">
                  <thead>
                    <tr style="border-bottom:1px solid var(--border-color); color:var(--text-secondary);">
                      <th style="padding:6px;">Item</th>
                      <th style="padding:6px;">Category</th>
                      <th style="padding:6px; text-align:right;">Stock</th>
                      <th style="padding:6px; text-align:right;">Min level</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($low_stock_alerts_data as $alert): ?>
                      <tr style="border-bottom:1px solid var(--border-color);">
                        <td style="padding:8px 6px; font-weight:600;"><?php echo htmlspecialchars($alert['item_name']); ?></td>
                        <td style="padding:8px 6px;"><?php echo htmlspecialchars($alert['category']); ?></td>
                        <td style="padding:8px 6px; text-align:right; color:var(--petron-red); font-weight:700;"><?php echo number_format($alert['current_stock'], 2); ?></td>
                        <td style="padding:8px 6px; text-align:right;"><?php echo number_format($alert['min_level'], 2); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Tab 5: Customers -->
      <div id="tab-customers" class="adm-chart-panel">
        <div class="adm-charts-grid">
          <div class="adm-chart-box">
            <h4>Purchase Distribution <span style="font-size:11px; font-weight:normal;">Fuel vs Merchandise</span></h4>
            <div class="adm-chart-canvas-wrap"><canvas id="customerPurchasePie"></canvas></div>
          </div>
          <div class="adm-chart-box">
            <h4>Top Customers by Purchase Volume</h4>
            <div class="adm-chart-canvas-wrap"><canvas id="topCustomersBar"></canvas></div>
          </div>
          <div class="adm-chart-box">
            <h4>Customer Complaints &amp; Issues Trend</h4>
            <div class="adm-chart-canvas-wrap"><canvas id="complaintsTrendLine"></canvas></div>
          </div>
        </div>
      </div>

    </div>

    <!-- Live Alerts Side Panel -->
    <div class="adm-side-panel">
      <div class="adm-alerts-card">
        <h2><i class="fas fa-bell"></i> Station Alerts</h2>
        <div class="adm-alerts-list">
          <?php if (empty($active_notifications)): ?>
            <div style="text-align:center; padding: 20px 0; color:var(--text-secondary);">
              <i class="fas fa-circle-check" style="font-size:24px; color:#22c55e; margin-bottom:6px;"></i>
              <p style="margin:0; font-size:12px;">No active alerts or anomalies detected.</p>
            </div>
          <?php else: ?>
            <?php foreach ($active_notifications as $notif): ?>
              <div class="adm-alert-item <?php echo htmlspecialchars($notif['level']); ?>">
                <div class="adm-alert-title">
                  <i class="fas <?php echo $notif['level'] === 'critical' ? 'fa-circle-exclamation' : ($notif['level'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-info-circle'); ?>" style="margin-right:6px;"></i>
                  <?php echo htmlspecialchars($notif['title']); ?>
                </div>
                <div class="adm-alert-msg"><?php echo htmlspecialchars($notif['message']); ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- CALENDAR & SCHEDULING SECTION -->
  <div class="adm-calendar-sec">
    <div class="adm-cal-header">
      <div>
        <h2 style="margin:0; font-size:18px; color:var(--petron-blue); display:flex; align-items:center; gap:8px;">
          <i class="fas fa-calendar-week"></i> Weekly Operations Schedule
        </h2>
        <p style="margin:4px 0 0 0; font-size:12px; color:var(--text-secondary);">
          Viewing week of <?php echo date('M d, Y', strtotime($start_of_week)); ?> to <?php echo date('M d, Y', strtotime($end_of_week)); ?>
        </p>
      </div>

      <div class="adm-cal-nav">
        <a href="?quick=<?php echo $quick; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&week=<?php echo $week_offset - 1; ?>"><i class="fas fa-chevron-left"></i> Previous Week</a>
        <a href="?quick=<?php echo $quick; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&week=0">Current Week</a>
        <a href="?quick=<?php echo $quick; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&week=<?php echo $week_offset + 1; ?>">Next Week <i class="fas fa-chevron-right"></i></a>
      </div>
    </div>

    <!-- Calendar Category Filters -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
      <div style="display:flex; gap:8px;">
        <button class="adm-cal-filter-btn" style="padding:6px 12px; border-radius:6px; border:1px solid var(--border-color); font-size:12px; cursor:pointer;" onclick="filterCalendar('all', this)">All Schedule</button>
        <button class="adm-cal-filter-btn" style="padding:6px 12px; border-radius:6px; border:1px solid var(--border-color); font-size:12px; cursor:pointer;" onclick="filterCalendar('staff', this)">Staff Shift/Tasks</button>
        <button class="adm-cal-filter-btn" style="padding:6px 12px; border-radius:6px; border:1px solid var(--border-color); font-size:12px; cursor:pointer;" onclick="filterCalendar('manager', this)">Manager Actions</button>
        <button class="adm-cal-filter-btn" style="padding:6px 12px; border-radius:6px; border:1px solid var(--border-color); font-size:12px; cursor:pointer;" onclick="filterCalendar('deliveries', this)">Deliveries</button>
        <button class="adm-cal-filter-btn" style="padding:6px 12px; border-radius:6px; border:1px solid var(--border-color); font-size:12px; cursor:pointer;" onclick="filterCalendar('meetings', this)">Calibrations</button>
      </div>

      <div class="adm-cal-legend">
        <div class="adm-legend-item"><span class="adm-dot evt-blue"></span> Staff</div>
        <div class="adm-legend-item"><span class="adm-dot evt-red"></span> Manager</div>
        <div class="adm-legend-item"><span class="adm-dot evt-green"></span> Deliveries</div>
        <div class="adm-legend-item"><span class="adm-dot evt-yellow"></span> Calibrations</div>
      </div>
    </div>

    <!-- Weekly Grid (Sunday to Saturday format) -->
    <div class="adm-cal-grid">
      <?php
      $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
      for ($i = 0; $i < 7; $i++) {
          $current_day_date = date('Y-m-d', strtotime($start_of_week . " +$i days"));
          $is_today = ($current_day_date === date('Y-m-d'));
          
          // Filter events for this specific day
          $day_events = array_filter($cal_events, function($e) use ($current_day_date) {
              return $e['date'] === $current_day_date;
          });
          
          echo "<div class='adm-cal-day " . ($is_today ? "today" : "") . "'>";
          echo "<div class='adm-cal-day-hdr'>" . $days[$i] . "<br><span style='font-size:10px; opacity:0.8;'>" . date('M d', strtotime($current_day_date)) . "</span></div>";
          echo "<div class='adm-cal-events-list' id='events-day-{$i}'>";
          if (empty($day_events)) {
              echo "<div style='text-align:center; color:var(--text-secondary); font-size:10px; margin: auto 0; padding:16px 0; opacity:0.5;'>No events</div>";
          } else {
              foreach ($day_events as $event) {
                  echo "<div class='adm-cal-event " . $event['color_class'] . "' data-cal-type='" . $event['type'] . "' title='" . htmlspecialchars($event['description']) . "'>";
                  echo htmlspecialchars($event['title']);
                  echo "</div>";
              }
          }
          echo "</div>";
          echo "</div>";
      }
      ?>
    </div>
  </div>

  <!-- REPORTS QUICK ACCESS -->
  <div class="adm-shortcuts-sec">
    <h2><i class="fas fa-file-invoice"></i> Reports Quick Access &amp; Compliance Center</h2>
    <div class="adm-shortcut-grid">
      <button class="adm-shortcut-card" onclick="triggerExport('sales')">
        <i class="fas fa-file-invoice-dollar"></i> Export Sales Summary (Excel/CSV)
      </button>
      <button class="adm-shortcut-card" onclick="triggerExport('fuel')">
        <i class="fas fa-gas-pump"></i> Fuel stock variance logs (PDF)
      </button>
      <button class="adm-shortcut-card" onclick="triggerExport('deliveries')">
        <i class="fas fa-truck-loading"></i> Merchandise Delivery Reports
      </button>
      <button class="adm-shortcut-card" onclick="triggerExport('inventory')">
        <i class="fas fa-boxes-packing"></i> Inventory Movement Audit
      </button>
      <button class="adm-shortcut-card" onclick="window.print()">
        <i class="fas fa-print"></i> Generate Station Compliance Report
      </button>
    </div>
  </div>

  <!-- AUDIT TRAIL SNAPSHOT -->
  <div class="adm-audit-sec">
    <div class="adm-audit-header">
      <h2><i class="fas fa-user-shield"></i> Audit Trail Oversight Snapshot</h2>
      <div class="adm-audit-filters">
        <input type="text" id="auditSearch" placeholder="Search audit trail..." onkeyup="filterAuditTrail()">
        
        <select id="auditRoleFilter" onchange="filterAuditTrail()">
          <option value="">All Roles</option>
          <option value="Admin">Admin</option>
          <option value="Manager">Manager</option>
          <option value="Staff">Staff</option>
        </select>

        <select id="auditModuleFilter" onchange="filterAuditTrail()">
          <option value="">All Modules</option>
          <option value="Fuel">Fuel</option>
          <option value="Merchandise">Merchandise</option>
          <option value="Delivery">Delivery</option>
          <option value="User">User</option>
          <option value="Job Order">Job Order</option>
        </select>
        
        <button class="export-btn" onclick="exportAuditTrail()"><i class="fas fa-file-excel"></i> Export Snapshot</button>
      </div>
    </div>

    <div class="adm-table-wrap">
      <table class="adm-table" id="auditTrailTable">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>User ID</th>
            <th>User</th>
            <th>Role</th>
            <th>Action</th>
            <th>Module</th>
            <th>Remarks</th>
            <th>Device/IP</th>
            <th>Audit Rating</th>
          </tr>
        </thead>
        <tbody id="auditTrailBody">
          <?php if (empty($audit_trail_data)): ?>
            <tr>
              <td colspan="9" style="text-align:center; color:var(--text-secondary);">No station actions logged in audit trail.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($audit_trail_data as $row): ?>
              <tr class="audit-row" 
                  data-user="<?php echo htmlspecialchars($row['user_name'] . ' ' . $row['username']); ?>"
                  data-role="<?php echo htmlspecialchars($row['role']); ?>"
                  data-module="<?php echo htmlspecialchars($row['module']); ?>"
                  data-action="<?php echo htmlspecialchars($row['action_type']); ?>"
                  data-remarks="<?php echo htmlspecialchars($row['remarks']); ?>"
                  data-time="<?php echo htmlspecialchars($row['created_at']); ?>">
                <td><?php echo date('M d, Y H:i:s', strtotime($row['created_at'])); ?></td>
                <td>#<?php echo htmlspecialchars($row['user_id']); ?></td>
                <td style="font-weight:600;"><?php echo htmlspecialchars($row['user_name'] ?: $row['username']); ?></td>
                <td>
                  <span class="badge <?php echo strtolower($row['role']); ?>">
                    <?php echo htmlspecialchars($row['role']); ?>
                  </span>
                </td>
                <td style="font-weight:500;"><?php echo htmlspecialchars($row['action_type']); ?></td>
                <td><?php echo htmlspecialchars($row['module'] ?: 'System'); ?></td>
                <td style="color:var(--text-secondary); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($row['remarks']); ?>">
                  <?php echo htmlspecialchars($row['remarks']); ?>
                </td>
                <td style="font-size:11px;"><?php echo htmlspecialchars($row['ip_address']); ?></td>
                <td class="audit-status-cell">
                  <span class="badge" style="background:rgba(34,197,94,0.1); color:#22c55e;">Normal</span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- CHART.JS INTEGRATION -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
  'use strict';

  // ─── THEME HANDLING FOR CHARTS ─────────────────────────────
  function getChartColors() {
    const isDark = document.body.classList.contains('dark-theme');
    return {
      textColor: isDark ? '#cbd5e1' : '#475569',
      gridColor: isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)',
      blue: '#00264D',
      red: '#CC0000',
      green: '#22c55e',
      yellow: '#f59e0b',
      purple: '#8b5cf6',
      cyan: '#06b6d4',
      bgCard: isDark ? '#1e293b' : '#ffffff'
    };
  }

  // ─── DATA LOADER ───────────────────────────────────────────
  const dailySalesRaw = <?php echo json_encode($daily_sales_data); ?>;
  const categorySalesRaw = <?php echo json_encode($category_sales_data); ?>;
  const monthlyRevenueRaw = <?php echo json_encode($monthly_revenue_data); ?>;
  
  const tankLevelsRaw = <?php echo json_encode($tank_levels_data); ?>;
  const fuelSoldRaw = <?php echo json_encode($fuel_sold_data); ?>;
  const fuelVarianceRaw = <?php echo json_encode($fuel_variance_data); ?>;

  const deliveryStatusRaw = <?php echo json_encode($delivery_status_data); ?>;
  const poVsActualRaw = <?php echo json_encode($po_vs_actual_data); ?>;
  const supplierPerfRaw = <?php echo json_encode($supplier_perf_data); ?>;

  const stockInOutRaw = <?php echo json_encode($stock_in_out_data); ?>;
  const inventoryTrendRaw = <?php echo json_encode($inventory_trend_data); ?>;

  const customerPurchaseRaw = <?php echo json_encode($customer_purchase_data); ?>;
  const topCustomersRaw = <?php echo json_encode($top_customers_data); ?>;
  const complaintsTrendRaw = <?php echo json_encode($complaints_trend_data); ?>;

  const chartRegistry = {};

  function destroyChart(id) {
    if (chartRegistry[id]) {
      chartRegistry[id].destroy();
      delete chartRegistry[id];
    }
  }

  // ─── CHART BUILDERS ────────────────────────────────────────
  function initTransactionsCharts() {
    const C = getChartColors();
    Chart.defaults.color = C.textColor;
    Chart.defaults.scale.grid.color = C.gridColor;

    // 1. Daily Sales Bar Chart
    const dsLabels = dailySalesRaw.map(d => new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
    destroyChart('dailySalesChart');
    chartRegistry['dailySalesChart'] = new Chart(document.getElementById('dailySalesChart'), {
      type: 'bar',
      data: {
        labels: dsLabels,
        datasets: [
          { label: 'Cash', data: dailySalesRaw.map(d => parseFloat(d.cash)), backgroundColor: C.blue },
          { label: 'Card', data: dailySalesRaw.map(d => parseFloat(d.card)), backgroundColor: C.red },
          { label: 'E-Wallet', data: dailySalesRaw.map(d => parseFloat(d.ewallet)), backgroundColor: C.green },
          { label: 'E-Fuel Card', data: dailySalesRaw.map(d => parseFloat(d.efuel)), backgroundColor: C.yellow }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: { stacked: true },
          y: { stacked: true, beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } }
        }
      }
    });

    // 2. Sales Distribution Pie Chart
    destroyChart('salesCategoryPie');
    chartRegistry['salesCategoryPie'] = new Chart(document.getElementById('salesCategoryPie'), {
      type: 'pie',
      data: {
        labels: categorySalesRaw.map(c => c.category),
        datasets: [{
          data: categorySalesRaw.map(c => parseFloat(c.total)),
          backgroundColor: [C.blue, C.red, C.green, C.yellow, C.purple, C.cyan]
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'right' } }
      }
    });

    // 3. Monthly Revenue Trend Line
    destroyChart('monthlyRevenueLine');
    chartRegistry['monthlyRevenueLine'] = new Chart(document.getElementById('monthlyRevenueLine'), {
      type: 'line',
      data: {
        labels: monthlyRevenueRaw.map(m => m.month),
        datasets: [{
          label: 'Total Revenue',
          data: monthlyRevenueRaw.map(m => parseFloat(m.total)),
          borderColor: C.blue,
          backgroundColor: C.blue + '10',
          fill: true,
          tension: 0.3,
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } }
        }
      }
    });
  }

  function initFuelCharts() {
    const C = getChartColors();
    
    // 1. Tank Stock Levels (Gauge/Bar Chart combo since Chart.js gauge is complex)
    // We render multiple bar/doughnut charts representing current levels vs capacity
    destroyChart('fuelTankGauge');
    chartRegistry['fuelTankGauge'] = new Chart(document.getElementById('fuelTankGauge'), {
      type: 'bar',
      data: {
        labels: tankLevelsRaw.map(t => t.fuel_type),
        datasets: [
          { label: 'Current Level', data: tankLevelsRaw.map(t => parseFloat(t.current_stock)), backgroundColor: C.green },
          { label: 'Capacity', data: tankLevelsRaw.map(t => parseFloat(t.capacity) - parseFloat(t.current_stock)), backgroundColor: C.gridColor }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        scales: {
          x: { stacked: true, beginAtZero: true },
          y: { stacked: true }
        }
      }
    });

    // 2. Liters Sold per Fuel Type (Bar)
    destroyChart('fuelTypeLitersBar');
    chartRegistry['fuelTypeLitersBar'] = new Chart(document.getElementById('fuelTypeLitersBar'), {
      type: 'bar',
      data: {
        labels: fuelSoldRaw.map(f => f.fuel_type),
        datasets: [{
          label: 'Liters Sold',
          data: fuelSoldRaw.map(f => parseFloat(f.liters)),
          backgroundColor: C.blue,
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } }
      }
    });

    // 3. Expected vs Actual Pump Readings Variance (Line)
    const varDates = [...new Set(fuelVarianceRaw.map(v => v.date))];
    const fuelTypes = [...new Set(fuelVarianceRaw.map(v => v.fuel_type))];
    const datasets = fuelTypes.map((ft, idx) => {
      const colorsList = [C.blue, C.red, C.green];
      return {
        label: ft,
        data: varDates.map(d => {
          const matched = fuelVarianceRaw.find(v => v.date === d && v.fuel_type === ft);
          return matched ? parseFloat(matched.variance) : 0;
        }),
        borderColor: colorsList[idx % colorsList.length],
        fill: false,
        tension: 0.2
      };
    });

    destroyChart('fuelPumpVarianceLine');
    chartRegistry['fuelPumpVarianceLine'] = new Chart(document.getElementById('fuelPumpVarianceLine'), {
      type: 'line',
      data: { labels: varDates, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } }
      }
    });
  }

  function initDeliveriesCharts() {
    const C = getChartColors();

    // 1. Delivery Status Pie
    destroyChart('deliveryStatusPie');
    chartRegistry['deliveryStatusPie'] = new Chart(document.getElementById('deliveryStatusPie'), {
      type: 'pie',
      data: {
        labels: deliveryStatusRaw.map(d => d.status),
        datasets: [{
          data: deliveryStatusRaw.map(d => parseInt(d.count)),
          backgroundColor: [C.green, C.blue, C.yellow, C.red]
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false
      }
    });

    // 2. PO vs Actual Quantities Received (Stacked Bar)
    destroyChart('poVsActualQtyBar');
    chartRegistry['poVsActualQtyBar'] = new Chart(document.getElementById('poVsActualQtyBar'), {
      type: 'bar',
      data: {
        labels: poVsActualRaw.map(d => d.delivery_ref),
        datasets: [
          { label: 'Expected (PO)', data: poVsActualRaw.map(d => parseFloat(d.expected_quantity)), backgroundColor: C.blue },
          { label: 'Actual Received', data: poVsActualRaw.map(d => parseFloat(d.actual_quantity)), backgroundColor: C.green }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: { beginAtZero: true }
        }
      }
    });

    // 3. Supplier Performance (Trend Line)
    destroyChart('supplierPerformanceLine');
    chartRegistry['supplierPerformanceLine'] = new Chart(document.getElementById('supplierPerformanceLine'), {
      type: 'line',
      data: {
        labels: supplierPerfRaw.map(s => s.supplier_name),
        datasets: [
          { label: 'On-Time', data: supplierPerfRaw.map(s => parseInt(s.on_time)), borderColor: C.green, backgroundColor: C.green + '10', fill: true },
          { label: 'Delayed', data: supplierPerfRaw.map(s => parseInt(s.delayed)), borderColor: C.red, backgroundColor: C.red + '10', fill: true }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false
      }
    });
  }

  function initInventoryCharts() {
    const C = getChartColors();

    // 1. Stock-In vs Stock-Out per item (Bar)
    destroyChart('stockInOutBar');
    chartRegistry['stockInOutBar'] = new Chart(document.getElementById('stockInOutBar'), {
      type: 'bar',
      data: {
        labels: stockInOutRaw.map(s => s.product_name),
        datasets: [
          { label: 'Stock In', data: stockInOutRaw.map(s => parseFloat(s.stock_in)), backgroundColor: C.green },
          { label: 'Stock Out', data: stockInOutRaw.map(s => parseFloat(s.stock_out)), backgroundColor: C.red }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } }
      }
    });

    // 2. Inventory Trend (Line)
    destroyChart('inventoryTrendLine');
    chartRegistry['inventoryTrendLine'] = new Chart(document.getElementById('inventoryTrendLine'), {
      type: 'line',
      data: {
        labels: inventoryTrendRaw.map(i => i.date),
        datasets: [{
          label: 'Net Flow (L/pcs)',
          data: inventoryTrendRaw.map(i => parseFloat(i.net_change)),
          borderColor: C.purple,
          tension: 0.3,
          fill: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } }
      }
    });
  }

  function initCustomerCharts() {
    const C = getChartColors();

    // 1. Purchase Distribution (Pie)
    destroyChart('customerPurchasePie');
    chartRegistry['customerPurchasePie'] = new Chart(document.getElementById('customerPurchasePie'), {
      type: 'pie',
      data: {
        labels: customerPurchaseRaw.map(c => c.category),
        datasets: [{
          data: customerPurchaseRaw.map(c => parseFloat(c.total)),
          backgroundColor: [C.blue, C.cyan]
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false
      }
    });

    // 2. Top Customers (Bar)
    destroyChart('topCustomersBar');
    chartRegistry['topCustomersBar'] = new Chart(document.getElementById('topCustomersBar'), {
      type: 'bar',
      data: {
        labels: topCustomersRaw.map(c => c.customer_name),
        datasets: [{
          label: 'Purchases (₱)',
          data: topCustomersRaw.map(c => parseFloat(c.total_purchases)),
          backgroundColor: C.blue,
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } } }
      }
    });

    // 3. Complaints Trend (Line)
    destroyChart('complaintsTrendLine');
    chartRegistry['complaintsTrendLine'] = new Chart(document.getElementById('complaintsTrendLine'), {
      type: 'line',
      data: {
        labels: complaintsTrendRaw.map(c => c.date),
        datasets: [{
          label: 'Issues/Returns Count',
          data: complaintsTrendRaw.map(c => parseInt(c.count)),
          borderColor: C.red,
          backgroundColor: C.red + '10',
          fill: true,
          tension: 0.3
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } }
      }
    });
  }

  // ─── TAB SWITCHER ──────────────────────────────────────────
  window.switchTab = function(tabName, btn) {
    document.querySelectorAll('.adm-chart-panel').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.adm-tab-btn').forEach(el => el.classList.remove('active'));
    
    document.getElementById('tab-' + tabName).classList.add('active');
    btn.classList.add('active');

    // Lazy load / redraw charts for performance
    if (tabName === 'transactions') initTransactionsCharts();
    if (tabName === 'fuel') initFuelCharts();
    if (tabName === 'deliveries') initDeliveriesCharts();
    if (tabName === 'inventory') initInventoryCharts();
    if (tabName === 'customers') initCustomerCharts();
  };

  // ─── CALENDAR FILTERS ──────────────────────────────────────
  window.filterCalendar = function(category, btn) {
    document.querySelectorAll('.adm-cal-filter-btn').forEach(el => {
      el.style.backgroundColor = '';
      el.style.color = '';
    });
    
    const C = getChartColors();
    btn.style.backgroundColor = C.blue;
    btn.style.color = '#fff';

    document.querySelectorAll('.adm-cal-event').forEach(evt => {
      if (category === 'all' || evt.dataset.calType === category) {
        evt.style.display = 'block';
      } else {
        evt.style.display = 'none';
      }
    });
  };

  // ─── AUDIT TRAIL SEARCH & FILTERS ──────────────────────────
  window.filterAuditTrail = function() {
    const searchVal = document.getElementById('auditSearch').value.toLowerCase();
    const roleVal = document.getElementById('auditRoleFilter').value;
    const moduleVal = document.getElementById('auditModuleFilter').value;

    document.querySelectorAll('.audit-row').forEach(row => {
      const user = row.dataset.user.toLowerCase();
      const role = row.dataset.role;
      const module = row.dataset.module;
      const action = row.dataset.action.toLowerCase();
      const remarks = row.dataset.remarks.toLowerCase();

      const matchesSearch = user.includes(searchVal) || action.includes(searchVal) || remarks.includes(searchVal);
      const matchesRole = !roleVal || role === roleVal;
      const matchesModule = !moduleVal || module.toLowerCase().includes(moduleVal.toLowerCase());

      if (matchesSearch && matchesRole && matchesModule) {
        row.style.display = '';
      } else {
        row.style.display = 'none';
      }
    });
  };

  // Anomaly detection in audit trails
  function performAuditAnomalyCheck() {
    document.querySelectorAll('.audit-row').forEach(row => {
      const timeStr = row.dataset.time;
      const action = row.dataset.action.toLowerCase();
      const remarks = row.dataset.remarks.toLowerCase();
      const ratingCell = row.querySelector('.audit-status-cell');
      
      const hour = new Date(timeStr).getHours();
      let isAnomaly = false;
      let reasons = [];

      // Anomaly 1: Actions performed outside 6am-10pm
      if (hour < 6 || hour >= 22) {
        isAnomaly = true;
        reasons.push('Outside Business Hours');
      }

      // Anomaly 2: Failed login/lockout
      if (action.includes('failed') || action.includes('locked') || action.includes('unauthorized') || remarks.includes('incorrect password')) {
        isAnomaly = true;
        reasons.push('Security Failure');
      }

      // Anomaly 3: High value variance adjustments
      if (remarks.includes('variance') || remarks.includes('adjust') || remarks.includes('delete')) {
        if (remarks.includes('large') || remarks.includes('high') || remarks.includes('bulk')) {
          isAnomaly = true;
          reasons.push('High Risk Action');
        }
      }

      if (isAnomaly) {
        row.classList.add('anomaly');
        ratingCell.innerHTML = `<span class="badge anomaly-tag" title="${reasons.join(', ')}">Anomaly</span>`;
      }
    });
  }

  // Export audit trail snapshot to CSV
  window.exportAuditTrail = function() {
    let csv = 'Timestamp,User ID,User,Role,Action,Module,Remarks,IP Address\n';
    document.querySelectorAll('.audit-row').forEach(row => {
      if (row.style.display !== 'none') {
        const cols = row.querySelectorAll('td');
        const rowData = Array.from(cols).slice(0, 8).map(col => {
          let text = col.innerText.replace(/"/g, '""');
          return `"${text}"`;
        });
        csv += rowData.join(',') + '\n';
      }
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.setAttribute('download', `Audit_Trail_Snapshot_${new Date().toISOString().slice(0,10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  // ─── REPORTS EXPORTS ───────────────────────────────────────
  window.triggerExport = function(type) {
    alert(`Exporting ${type.toUpperCase()} Consolidated Report. This may take a few seconds...`);
    // Direct CSV exporter mockup based on PHP loaded datasets
    let data = [];
    let headers = '';
    
    if (type === 'sales') {
      data = dailySalesRaw;
      headers = 'Date,Cash,Card,E-Wallet,E-Fuel Card\n';
    } else if (type === 'fuel') {
      data = fuelVarianceRaw;
      headers = 'Date,Fuel Type,Expected Stock,Actual Stock,Variance Liters\n';
    } else if (type === 'deliveries') {
      data = poVsActualRaw;
      headers = 'Delivery Ref,Expected Quantity,Actual QuantityReceived\n';
    } else if (type === 'inventory') {
      data = stockInOutRaw;
      headers = 'Product Name,Stock-In,Stock-Out\n';
    }

    if (data.length > 0) {
      let csv = headers;
      data.forEach(row => {
        csv += Object.values(row).map(val => `"${val}"`).join(',') + '\n';
      });
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.setAttribute('download', `Consolidated_${type}_Report_${new Date().toISOString().slice(0,10)}.csv`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    } else {
      alert('No data available in current date range to export.');
    }
  };

  // ─── ON INITIAL LOAD ───────────────────────────────────────
  window.addEventListener('load', () => {
    initTransactionsCharts();
    performAuditAnomalyCheck();
    
    // Set active filter calendar button
    const firstCalBtn = document.querySelector('.adm-cal-filter-btn');
    if (firstCalBtn) {
      const C = getChartColors();
      firstCalBtn.style.backgroundColor = C.blue;
      firstCalBtn.style.color = '#fff';
    }
  });

  // Watch for theme classes changes on the body
  const themeObserver = new MutationObserver(() => {
    const activeTabBtn = document.querySelector('.adm-tab-btn.active');
    if (activeTabBtn) {
      const tabName = activeTabBtn.innerText.trim().toLowerCase().split(' ')[0];
      if (tabName.includes('transaction')) initTransactionsCharts();
      if (tabName.includes('fuel')) initFuelCharts();
      if (tabName.includes('deliveries')) initDeliveriesCharts();
      if (tabName.includes('inventory')) initInventoryCharts();
      if (tabName.includes('customer')) initCustomerCharts();
    }
  });
  themeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });

})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
