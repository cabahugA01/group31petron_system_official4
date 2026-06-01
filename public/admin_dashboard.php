<?php
// ============================================================
// Admin Dashboard – public/admin_dashboard.php
// Complete redesign: all components, charts, panels, calendar
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
// 1. SUMMARY METRICS
// ══════════════════════════════════════════════════════════
$fuel_revenue  = (float) adm_val($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?", [$station_id,$date_from,$date_to]);
$fuel_liters   = (float) adm_val($pdo, "SELECT COALESCE(SUM(liters_sold),0) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?", [$station_id,$date_from,$date_to]);
$merch_revenue = (float) adm_val($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?", [$station_id,$date_from,$date_to]);
$merch_credit  = (float) adm_val($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND payment_method='Credit' AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?", [$station_id,$date_from,$date_to]);

$upcoming_deliveries    = (int) adm_val($pdo, "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND DATE(COALESCE(delivery_date,created_at)) >= CURDATE() AND status NOT IN ('Validated','Confirmed','Flagged','Discrepancy')", [$station_id]);
$scheduled_calibrations = (int) adm_val($pdo, "SELECT COUNT(DISTINCT pump_number) FROM calibration_logs WHERE station_id=? AND DATE(encoded_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", [$station_id]);
// Admin JO KPI: manager-approved JOs (oversight view), NOT raw Pending Validation staff encodings
$pending_job_orders     = (int) adm_val($pdo, "SELECT COUNT(*) FROM job_orders WHERE station_id=? AND validation_status='Approved' AND status NOT IN ('Completed','Cancelled','Rejected')", [$station_id]);
$active_shifts_today    = (int) adm_val($pdo, "SELECT COUNT(*) FROM labor_sessions WHERE station_id=? AND DATE(start_time)=CURDATE()", [$station_id]);
$variance_alerts_open   = (int) adm_val($pdo, "SELECT COUNT(*) FROM variance_alerts WHERE station_id=? AND status='open'", [$station_id]);
$variance_liters        = (float) adm_val($pdo, "SELECT COALESCE(SUM(ABS(variance_liters)),0) FROM fuel_variance_reports WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ?", [$station_id,$date_from,$date_to]);

// ══════════════════════════════════════════════════════════
// 2. COMPLIANCE ALERTS
// ══════════════════════════════════════════════════════════
$compliance_alerts = [];
if ($variance_alerts_open > 0)
    $compliance_alerts[] = ['type'=>'danger','icon'=>'fa-triangle-exclamation','msg'=>"{$variance_alerts_open} unresolved variance alert(s) requiring calibration review."];
$flagged_del = (int) adm_val($pdo, "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Rejected','Flagged','Discrepancy')", [$station_id]);
if ($flagged_del > 0)
    $compliance_alerts[] = ['type'=>'danger','icon'=>'fa-circle-xmark','msg'=>"{$flagged_del} delivery(ies) flagged with discrepancies."];
// Admin compliance: show deliveries awaiting Admin oversight (already passed Manager), NOT raw Manager-queue items
$pending_admin_oversight = (int) adm_val($pdo, "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status='Pending Admin Oversight'", [$station_id]);
if ($pending_admin_oversight > 0)
    $compliance_alerts[] = ['type'=>'warning','icon'=>'fa-truck','msg'=>"{$pending_admin_oversight} delivery(ies) awaiting your final oversight (Manager-validated)."];

// Inventory flow alerts
$pending_po_admin = (int) adm_val($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status='Pending Admin Validation' AND type='merch' AND admin_finalized=0", [$station_id]);
if ($pending_po_admin > 0)
    $compliance_alerts[] = ['type'=>'warning','icon'=>'fa-file-invoice-dollar','msg'=>"{$pending_po_admin} Purchase Order(s) awaiting your finalization. <a href='admin_purchase_orders.php' style='color:inherit;font-weight:700;text-decoration:underline;'>Review POs &rarr;</a>"];

$pending_stock_in = (int) adm_val($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND admin_finalized=1 AND delivery_validated=1 AND stock_in_done=0 AND type='merch'", [$station_id]);
if ($pending_stock_in > 0)
    $compliance_alerts[] = ['type'=>'info','icon'=>'fa-dolly','msg'=>"{$pending_stock_in} manager-validated PO(s) awaiting Stock-In encoding. <a href='staff_stock_in.php' style='color:inherit;font-weight:700;text-decoration:underline;'>Go to Stock-In &rarr;</a>"];
// Admin compliance: show manager-validated transactions needing oversight, NOT raw Pending staff encodings
$admin_tx_oversight = (int) adm_val($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND validation_status IN ('Approved','Adjusted') AND DATE(COALESCE(validated_at,created_at))=CURDATE()", [$station_id]);
if ($admin_tx_oversight > 0)
    $compliance_alerts[] = ['type'=>'info','icon'=>'fa-eye','msg'=>"{$admin_tx_oversight} manager-validated transaction(s) today available for your oversight review."];
// Admin compliance: show manager-approved JOs needing oversight, NOT raw Pending Validation
$admin_jo_oversight = (int) adm_val($pdo, "SELECT COUNT(*) FROM job_orders WHERE station_id=? AND validation_status='Approved' AND DATE(COALESCE(validated_at,created_at))=CURDATE()", [$station_id]);
if ($admin_jo_oversight > 0)
    $compliance_alerts[] = ['type'=>'info','icon'=>'fa-wrench','msg'=>"{$admin_jo_oversight} manager-approved job order(s) today available for your oversight review."];

// ══════════════════════════════════════════════════════════
// 3. CALENDAR OVERSIGHT (Weekly)
// ══════════════════════════════════════════════════════════
$week_offset   = (int)($_GET['week'] ?? 0);
$start_of_week = date('Y-m-d', strtotime("monday this week +{$week_offset} weeks"));
$end_of_week   = date('Y-m-d', strtotime("sunday this week +{$week_offset} weeks"));
$cal_events    = [];

foreach (adm_rows($pdo, "SELECT id,service_type,customer_name,status,DATE(created_at) AS edate FROM job_orders WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ?", [$station_id,$start_of_week,$end_of_week]) as $x)
    $cal_events[] = ['type'=>'job_orders','title'=>"JO: ".($x['service_type']??'')." (".($x['customer_name']??'').")",'date'=>$x['edate'],'status'=>strtolower($x['status']?:'pending')];

foreach (adm_rows($pdo, "SELECT id,supplier,product,quantity,status,DATE(COALESCE(delivery_date,created_at)) AS edate FROM deliveries_oversight WHERE station_id=? AND DATE(COALESCE(delivery_date,created_at)) BETWEEN ? AND ?", [$station_id,$start_of_week,$end_of_week]) as $x)
    $cal_events[] = ['type'=>'deliveries','title'=>"Del: ".($x['product']??'')." – ".($x['supplier']??'')." (".($x['quantity']??'')."L)",'date'=>$x['edate'],'status'=>strtolower($x['status']?:'pending')];

foreach (adm_rows($pdo, "SELECT id,product_name,quantity,status,DATE(COALESCE(expected_delivery_date,created_at)) AS edate FROM purchase_orders WHERE station_id=? AND DATE(COALESCE(expected_delivery_date,created_at)) BETWEEN ? AND ?", [$station_id,$start_of_week,$end_of_week]) as $x)
    $cal_events[] = ['type'=>'purchase_orders','title'=>"PO: ".($x['product_name']??'')." (".($x['quantity']??'').")",'date'=>$x['edate'],'status'=>strtolower($x['status']?:'pending')];

foreach (adm_rows($pdo, "SELECT id,pump_number,fuel_type,DATE(encoded_at) AS edate FROM calibration_logs WHERE station_id=? AND DATE(encoded_at) BETWEEN ? AND ?", [$station_id,$start_of_week,$end_of_week]) as $x)
    $cal_events[] = ['type'=>'fuel_calibration','title'=>"Calib: Pump #".($x['pump_number']??'')." (".($x['fuel_type']??'').")",'date'=>$x['edate'],'status'=>'completed'];

foreach (adm_rows($pdo, "SELECT ss.id,ss.shift,ss.status,ss.scheduled_date AS edate,u.name AS sname FROM staff_schedules ss JOIN users u ON u.id=ss.user_id WHERE u.station_id=? AND ss.scheduled_date BETWEEN ? AND ?", [$station_id,$start_of_week,$end_of_week]) as $x)
    $cal_events[] = ['type'=>'staff_shift','title'=>"Shift: ".($x['sname']??'')." (".($x['shift']??'').")",'date'=>$x['edate'],'status'=>strtolower($x['status']?:'approved')];

// ══════════════════════════════════════════════════════════
// 4. CHARTS DATA
// ══════════════════════════════════════════════════════════
// Sales trend 30 days
$fuel_map  = array_column(adm_rows($pdo, "SELECT DATE(transaction_date) AS d, SUM(total_amount) AS rev FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY d", [$station_id]), 'rev', 'd');
$merch_map = array_column(adm_rows($pdo, "SELECT DATE(COALESCE(transaction_date,created_at)) AS d, SUM(total_amount) AS rev FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(transaction_date,created_at))>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY d", [$station_id]), 'rev', 'd');
$trend_labels = $trend_fuel = $trend_merch = [];
for ($i=29;$i>=0;$i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trend_labels[] = date('M j', strtotime($d));
    $trend_fuel[]   = (float)($fuel_map[$d]  ?? 0);
    $trend_merch[]  = (float)($merch_map[$d] ?? 0);
}

// Fuel sales per day (bar) – last 7 days
$fuel_bar_map = array_column(adm_rows($pdo, "SELECT DATE(transaction_date) AS d, SUM(liters_sold) AS ltr FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY d", [$station_id]), 'ltr', 'd');
$fuel_bar_labels = $fuel_bar_data = [];
for ($i=6;$i>=0;$i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $fuel_bar_labels[] = date('D', strtotime($d));
    $fuel_bar_data[]   = (float)($fuel_bar_map[$d] ?? 0);
}

// Merchandise trend line – last 14 days
$merch_trend_map = array_column(adm_rows($pdo, "SELECT DATE(COALESCE(transaction_date,created_at)) AS d, SUM(total_amount) AS rev FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(transaction_date,created_at))>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) GROUP BY d", [$station_id]), 'rev', 'd');
$merch_trend_labels = $merch_trend_data = [];
for ($i=13;$i>=0;$i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $merch_trend_labels[] = date('M j', strtotime($d));
    $merch_trend_data[]   = (float)($merch_trend_map[$d] ?? 0);
}

// Deliveries
$del_rows       = adm_rows($pdo, "SELECT supplier, COUNT(*) AS cnt FROM deliveries_oversight WHERE station_id=? GROUP BY supplier ORDER BY cnt DESC LIMIT 6", [$station_id]);
$del_sup_labels = !empty($del_rows) ? array_column($del_rows,'supplier') : ['No Supplier'];
$del_sup_data   = !empty($del_rows) ? array_column($del_rows,'cnt')      : [0];
$del_pending    = (int) adm_val($pdo, "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Pending','Pending Validation')", [$station_id]);
$del_approved   = (int) adm_val($pdo, "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Approved','Validated','Confirmed')", [$station_id]);
$del_flagged    = (int) adm_val($pdo, "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Flagged','Rejected','Discrepancy')", [$station_id]);

// Job Orders
$jo_pending     = (int) adm_val($pdo, "SELECT COUNT(*) FROM job_orders WHERE station_id=? AND LOWER(status)='pending'", [$station_id]);
$jo_in_progress = (int) adm_val($pdo, "SELECT COUNT(*) FROM job_orders WHERE station_id=? AND LOWER(status) IN ('in progress','in-progress')", [$station_id]);
$jo_completed   = (int) adm_val($pdo, "SELECT COUNT(*) FROM job_orders WHERE station_id=? AND LOWER(status)='completed'", [$station_id]);

// Job orders weekly volume – last 8 weeks
$jo_week_map = array_column(adm_rows($pdo, "SELECT YEARWEEK(created_at,1) AS wk, COUNT(*) AS cnt FROM job_orders WHERE station_id=? AND created_at>=DATE_SUB(CURDATE(),INTERVAL 8 WEEK) GROUP BY wk ORDER BY wk", [$station_id]), 'cnt', 'wk');
$jo_week_labels = $jo_week_data = [];
for ($i=7;$i>=0;$i--) {
    $wk = date('oW', strtotime("-{$i} weeks"));
    $jo_week_labels[] = 'Wk '.date('W', strtotime("-{$i} weeks"));
    $jo_week_data[]   = (int)($jo_week_map[$wk] ?? 0);
}

// Staff performance
$staff_perf  = adm_rows($pdo, "SELECT u.name, COUNT(DISTINCT mt.id)+COUNT(DISTINCT jo.id) AS total_activity FROM users u LEFT JOIN merchandise_transactions mt ON mt.staff_id=u.id AND mt.station_id=? LEFT JOIN job_orders jo ON jo.user_id=u.id AND jo.station_id=? WHERE u.station_id=? AND u.role NOT IN ('admin','manager','superadmin') GROUP BY u.id,u.name ORDER BY total_activity DESC LIMIT 6", [$station_id,$station_id,$station_id]);
$staff_names = !empty($staff_perf) ? array_column($staff_perf,'name')           : ['No Staff'];
$staff_act   = !empty($staff_perf) ? array_column($staff_perf,'total_activity') : [0];

// Staff attendance – shifts per staff last 30 days
$att_rows   = adm_rows($pdo, "SELECT u.name, COUNT(ls.id) AS shifts FROM users u LEFT JOIN labor_sessions ls ON ls.user_id=u.id AND ls.station_id=? AND DATE(ls.start_time)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) WHERE u.station_id=? AND u.role NOT IN ('admin','manager','superadmin') GROUP BY u.id,u.name ORDER BY shifts DESC LIMIT 6", [$station_id,$station_id]);
$att_names  = !empty($att_rows) ? array_column($att_rows,'name')   : ['No Staff'];
$att_shifts = !empty($att_rows) ? array_column($att_rows,'shifts') : [0];

// Variance – pump readings vs sales vs deliveries (last 7 days)
$var_pump_map  = array_column(adm_rows($pdo, "SELECT DATE(reading_date) AS d, SUM(closing_reading-opening_reading) AS ltr FROM fuel_readings WHERE station_id=? AND DATE(reading_date)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY d", [$station_id]), 'ltr', 'd');
$var_sales_map = array_column(adm_rows($pdo, "SELECT DATE(transaction_date) AS d, SUM(liters_sold) AS ltr FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY d", [$station_id]), 'ltr', 'd');
$var_del_map   = array_column(adm_rows($pdo, "SELECT DATE(COALESCE(delivery_date,created_at)) AS d, SUM(quantity) AS ltr FROM deliveries_oversight WHERE station_id=? AND DATE(COALESCE(delivery_date,created_at))>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY d", [$station_id]), 'ltr', 'd');
$var_labels = $var_pump_data = $var_sales_data = $var_del_data = [];
for ($i=6;$i>=0;$i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $var_labels[]     = date('D M j', strtotime($d));
    $var_pump_data[]  = (float)($var_pump_map[$d]  ?? 0);
    $var_sales_data[] = (float)($var_sales_map[$d] ?? 0);
    $var_del_data[]   = (float)($var_del_map[$d]   ?? 0);
}

// Variance by tank
$var_tank_rows   = adm_rows($pdo, "SELECT fuel_type, SUM(variance_liters) AS total_var FROM fuel_variance_reports WHERE station_id=? GROUP BY fuel_type", [$station_id]);
$var_tank_labels = !empty($var_tank_rows) ? array_column($var_tank_rows,'fuel_type')  : ['No Data'];
$var_tank_values = !empty($var_tank_rows) ? array_column($var_tank_rows,'total_var')  : [0];

// Accounts Receivable
$ar_rows      = adm_rows($pdo, "SELECT name, outstanding_balance FROM customers WHERE station_id=? AND outstanding_balance>0 ORDER BY outstanding_balance DESC LIMIT 6", [$station_id]);
$ar_names     = !empty($ar_rows) ? array_column($ar_rows,'name')                : ['All Clear'];
$ar_balances  = !empty($ar_rows) ? array_column($ar_rows,'outstanding_balance') : [0];
$ar_total     = (float) adm_val($pdo, "SELECT COALESCE(SUM(outstanding_balance),0) FROM customers WHERE station_id=?", [$station_id]);
$ar_collected = (float) adm_val($pdo, "SELECT COALESCE(SUM(amount_paid),0) FROM credit_payments WHERE station_id=? AND DATE(payment_date) BETWEEN ? AND ?", [$station_id,$date_from,$date_to]);

// ══════════════════════════════════════════════════════════
// 5. QUICK PANELS DATA
// ══════════════════════════════════════════════════════════
$staff_active     = (int) adm_val($pdo, "SELECT COUNT(*) FROM users WHERE station_id=? AND status='active' AND role NOT IN ('admin','manager','superadmin')", [$station_id]);
$staff_inactive   = (int) adm_val($pdo, "SELECT COUNT(*) FROM users WHERE station_id=? AND status='inactive' AND role NOT IN ('admin','manager','superadmin')", [$station_id]);
$mgr_count        = (int) adm_val($pdo, "SELECT COUNT(*) FROM users WHERE station_id=? AND status='active' AND role IN ('manager','supervisor')", [$station_id]);
$po_pending       = (int) adm_val($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Pending','Pending Approval','Pending Admin Validation')", [$station_id]);
$po_finalized     = (int) adm_val($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Official','Received','Approved PO','Approved')", [$station_id]);
// Admin KPIs: show manager-validated transactions (oversight view), NOT raw Pending staff encodings
$tx_pending       = (int) adm_val($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND validation_status='Pending Admin Oversight'", [$station_id]);
$tx_approved      = (int) adm_val($pdo, "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND validation_status='Approved'", [$station_id]);
// Admin delivery KPIs: pending = awaiting Admin oversight; validated = confirmed by Admin
$del_flagged_snap = (int) adm_val($pdo, "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Flagged','Discrepancy')", [$station_id]);
$del_validated    = (int) adm_val($pdo, "SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Validated','Confirmed')", [$station_id]);
$recent_audit     = adm_rows($pdo, "SELECT al.created_at, u.name, al.action_type, al.entity_type, al.status FROM audit_logs al JOIN users u ON u.id=al.user_id WHERE u.station_id=? ORDER BY al.created_at DESC LIMIT 8", [$station_id]);

$display_name = htmlspecialchars($me['full_name'] ?? trim(($me['first_name']??'').' '.($me['last_name']??'')) ?: ($me['name'] ?? 'Admin'));

require_once __DIR__ . '/../partials/header.php';
?>
<style>
/* ═══════════════════════════════════════════════════════
   ADMIN DASHBOARD – SCOPED STYLES
   ═══════════════════════════════════════════════════════ */
.adm-wrap { padding: 0; }

/* ── Page header ── */
.adm-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px; margin-bottom: 18px; flex-wrap: wrap;
}
.adm-head h1 {
    margin: 0 0 3px; font-size: 20px !important; font-weight: 800;
    color: #00264D; display: flex; align-items: center; gap: 9px;
}
.adm-subtitle { font-size: 12px; color: #64748b; }
.adm-actions  { display: flex; gap: 7px; flex-wrap: wrap; align-items: center; }
.adm-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 13px; border-radius: 7px; font-size: 11px; font-weight: 600;
    cursor: pointer; border: 1px solid #e2e8f0; text-decoration: none; transition: all .13s;
}
.adm-btn-primary { background: #00264D; color: #fff; border-color: #00264D; }
.adm-btn-primary:hover { background: #003a73; color: #fff; }
.adm-btn-outline { background: #fff; color: #00264D; }
.adm-btn-outline:hover { background: #f1f5f9; color: #00264D; }
.adm-btn-sm { padding: 5px 11px; font-size: 11px; }

/* ── Filter bar ── */
.adm-filter-bar {
    display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 11px 15px; margin-bottom: 16px;
}
.adm-fg { display: flex; flex-direction: column; gap: 3px; }
.adm-fg label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.adm-fg input[type=date] { padding: 5px 9px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; }
.adm-qf-wrap { display: flex; gap: 4px; }
.adm-qf-btn {
    padding: 5px 11px; border-radius: 6px; font-size: 11px; font-weight: 600;
    border: 1px solid #e2e8f0; background: #fff; color: #64748b; cursor: pointer; transition: all .12s;
}
.adm-qf-btn.active, .adm-qf-btn:hover { background: #00264D; color: #fff; border-color: #00264D; }

/* ── KPI row ── */
.adm-kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
    gap: 11px; margin-bottom: 16px;
}
.adm-kpi-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 11px;
    padding: 14px 15px; display: flex; align-items: center; gap: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,.04); transition: box-shadow .14s;
}
.adm-kpi-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.08); }
.adm-kpi-card.kc-danger  { border-left: 4px solid #CC0000; }
.adm-kpi-card.kc-warning { border-left: 4px solid #f59e0b; }
.adm-kpi-card.kc-success { border-left: 4px solid #22c55e; }
.adm-kpi-ico {
    width: 44px; height: 44px; border-radius: 10px; background: #f1f5f9; color: #00264D;
    display: inline-flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0;
}
.adm-kpi-card.kc-danger  .adm-kpi-ico { background: #fef2f2; color: #CC0000; }
.adm-kpi-card.kc-warning .adm-kpi-ico { background: #fffbeb; color: #d97706; }
.adm-kpi-card.kc-success .adm-kpi-ico { background: #f0fdf4; color: #16a34a; }
.adm-kpi-meta h3 { margin: 0; font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; font-weight: 700; }
.adm-kpi-meta h2 { margin: 3px 0 2px; font-size: 24px; font-weight: 900; color: #00264D; line-height: 1; }
.adm-kpi-meta span { font-size: 10px; color: #94a3b8; }

/* ── Section card ── */
.adm-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 11px;
    padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.adm-card-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 13px; flex-wrap: wrap; gap: 8px;
}
.adm-card-title {
    font-size: 12px; font-weight: 700; color: #00264D;
    display: flex; align-items: center; gap: 7px;
    text-transform: uppercase !important; letter-spacing: .3px; margin: 0;
}

/* ── Chart tabs ── */
.adm-chart-tabs {
    display: flex; gap: 5px; flex-wrap: wrap;
    border-bottom: 2px solid #e2e8f0; padding-bottom: 9px; margin-bottom: 14px;
}
.adm-tab-btn {
    padding: 5px 13px; border-radius: 20px; font-size: 10px; font-weight: 700;
    border: 1px solid #e2e8f0; background: #fff; color: #64748b; cursor: pointer;
    transition: all .12s; text-transform: uppercase; letter-spacing: .3px;
}
.adm-tab-btn.active { background: #00264D; color: #fff; border-color: #00264D; }
.adm-tab-btn:hover:not(.active) { background: #f1f5f9; }

/* ── Chart panels – visibility trick so canvas always has size ── */
.adm-panels-wrap { position: relative; min-height: 280px; }
.adm-chart-panel {
    visibility: hidden; position: absolute; top: 0; left: 0;
    width: 100%; opacity: 0; pointer-events: none; transition: opacity .18s;
}
.adm-chart-panel.active {
    visibility: visible; position: relative;
    opacity: 1; pointer-events: auto;
}
.adm-chart-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 14px;
}
.adm-chart-box {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 13px;
}
/* Override global h1-h3 uppercase bleed */
.adm-chart-box h4 {
    font-size: 10px !important; font-weight: 700; color: #00264D; margin: 0 0 9px;
    text-transform: none !important; letter-spacing: .2px;
}
.adm-chart-holder { height: 230px; position: relative; }

/* ── Snapshots grid ── */
.adm-snap-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
    gap: 13px; margin-bottom: 16px;
}
.adm-snap-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 11px;
    padding: 15px; box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.adm-snap-title {
    font-size: 11px; font-weight: 700; color: #00264D; margin-bottom: 10px;
    display: flex; align-items: center; gap: 7px;
    text-transform: uppercase !important; letter-spacing: .3px;
    border-bottom: 1px solid #f1f5f9; padding-bottom: 7px;
}
.adm-snap-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 5px 0; border-bottom: 1px solid #f8fafc; font-size: 11px;
}
.adm-snap-row:last-of-type { border-bottom: none; }
.adm-snap-row span { color: #64748b; }
.adm-snap-row strong { color: #00264D; font-weight: 700; }
.adm-badge {
    display: inline-flex; align-items: center; padding: 2px 7px;
    border-radius: 20px; font-size: 10px; font-weight: 700;
}
.adm-badge.bg-green  { background: #dcfce7; color: #16a34a; }
.adm-badge.bg-red    { background: #fee2e2; color: #dc2626; }
.adm-badge.bg-amber  { background: #fef9c3; color: #ca8a04; }
.adm-badge.bg-blue   { background: #dbeafe; color: #1e40af; }
.adm-snap-link {
    display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 600;
    color: #00264D; text-decoration: none; margin-top: 9px; padding: 4px 9px;
    border: 1px solid #e2e8f0; border-radius: 6px; transition: all .12s;
}
.adm-snap-link:hover { background: #00264D; color: #fff; border-color: #00264D; }

/* ── Audit list ── */
.adm-audit-list { display: flex; flex-direction: column; gap: 5px; }
.adm-audit-item {
    display: flex; align-items: center; gap: 8px; padding: 6px 9px;
    background: #f8fafc; border-radius: 7px; font-size: 10px;
}
.adm-audit-dot { width: 7px; height: 7px; border-radius: 50%; background: #00264D; flex-shrink: 0; }
.adm-audit-name   { font-weight: 700; color: #00264D; min-width: 80px; }
.adm-audit-action { color: #475569; flex: 1; }
.adm-audit-time   { color: #94a3b8; font-size: 9px; white-space: nowrap; }

/* ── Staff KPI strip ── */
.adm-staff-kpi {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 9px; margin-bottom: 14px;
}
.adm-staff-kpi-item {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px;
    padding: 11px; text-align: center;
}
.adm-staff-kpi-item .sk-rank  { font-size: 9px; color: #94a3b8; font-weight: 700; text-transform: uppercase; }
.adm-staff-kpi-item .sk-name  { font-size: 11px; font-weight: 700; color: #00264D; margin: 3px 0 2px; }
.adm-staff-kpi-item .sk-val   { font-size: 20px; font-weight: 900; color: #00264D; }
.adm-staff-kpi-item .sk-label { font-size: 9px; color: #64748b; }

/* ── Reports snapshot ── */
.adm-reports-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 14px;
}
.adm-report-item {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 13px; text-align: center;
}
.adm-report-item .ri-val   { font-size: 20px; font-weight: 900; color: #00264D; }
.adm-report-item .ri-label { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: .4px; font-weight: 700; margin-top: 3px; }

/* ── Export buttons ── */
.adm-export-row { display: flex; gap: 7px; flex-wrap: wrap; }
.adm-export-btn {
    display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px;
    border-radius: 6px; font-size: 10px; font-weight: 700; text-decoration: none;
    border: 1px solid #e2e8f0; transition: all .12s; color: #00264D; background: #fff;
}
.adm-export-btn:hover { background: #00264D; color: #fff; border-color: #00264D; }
.adm-export-btn.xls { border-color: #16a34a; color: #16a34a; }
.adm-export-btn.xls:hover { background: #16a34a; color: #fff; }
.adm-export-btn.pdf { border-color: #CC0000; color: #CC0000; }
.adm-export-btn.pdf:hover { background: #CC0000; color: #fff; }

/* ── Quick access grid (bottom) ── */
.adm-ql-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 9px;
}
.adm-ql-card {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px;
    padding: 13px 8px; text-align: center; text-decoration: none; color: #00264D;
    font-size: 10px; font-weight: 700; transition: all .14s;
    display: flex; flex-direction: column; align-items: center; gap: 5px;
}
.adm-ql-card i { font-size: 20px; }
.adm-ql-card:hover {
    background: #00264D; color: #fff; border-color: #00264D;
    transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,38,77,.18);
}

/* ── Calendar ── */
.adm-cal {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 11px;
    overflow: hidden; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.adm-cal-head {
    background: #00264D; color: #fff; padding: 13px 16px;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 9px;
}
.adm-cal-head h3 { margin: 0; font-size: 12px; font-weight: 700; color: #fff !important; display: flex; align-items: center; gap: 7px; }
.adm-cal-nav { display: flex; align-items: center; gap: 5px; }
.adm-cal-nav-btn {
    padding: 4px 11px; border-radius: 6px; font-size: 10px; font-weight: 600;
    background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.25);
    cursor: pointer; text-decoration: none; transition: background .12s;
}
.adm-cal-nav-btn:hover { background: rgba(255,255,255,.28); color: #fff; }
.adm-cal-filters {
    display: flex; gap: 5px; padding: 9px 14px;
    background: #f8fafc; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap;
}
.adm-cal-filter-btn {
    padding: 3px 9px; border-radius: 20px; font-size: 9px; font-weight: 700;
    border: 1px solid #e2e8f0; background: #fff; color: #64748b; cursor: pointer;
    text-transform: uppercase; letter-spacing: .3px; transition: all .12s;
}
.adm-cal-filter-btn.active { background: #00264D; color: #fff; border-color: #00264D; }
.adm-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); }
@media(max-width:860px) { .adm-cal-grid { grid-template-columns: 1fr; } }
.adm-cal-cell { border-right: 1px solid #e2e8f0; min-height: 130px; padding: 9px; }
.adm-cal-cell:last-child { border-right: none; }
.adm-cal-day-title {
    font-size: 9px; font-weight: 700; text-transform: uppercase; color: #94a3b8;
    margin-bottom: 7px; padding-bottom: 4px; border-bottom: 1px solid #f1f5f9;
    display: flex; justify-content: space-between; letter-spacing: .3px;
}
.adm-cal-day-title.today { color: #CC0000; }
.adm-cal-event {
    padding: 3px 6px; border-radius: 4px; font-size: 8px; font-weight: 600;
    margin-bottom: 3px; line-height: 1.4; cursor: default; overflow: hidden;
    white-space: nowrap; text-overflow: ellipsis;
}
.adm-cal-event.pending    { background: #fef9c3; color: #ca8a04; border-left: 3px solid #eab308; }
.adm-cal-event.approved   { background: #eff6ff; color: #1e40af; border-left: 3px solid #3b82f6; }
.adm-cal-event.completed  { background: #dcfce7; color: #16a34a; border-left: 3px solid #22c55e; }
.adm-cal-event.rejected,
.adm-cal-event.cancelled  { background: #fee2e2; color: #dc2626; border-left: 3px solid #ef4444; }
.adm-cal-empty { font-size: 8px; color: #cbd5e1; text-align: center; margin-top: 12px; }
</style>

<div class="dashboard-content adm-wrap">

<!-- PAGE HEADER -->
<div class="adm-head">
  <div>
    <h1><i class="fas fa-shield-halved"></i> Admin Oversight Hub</h1>
    <div class="adm-subtitle">Welcome back, <?php echo $display_name; ?> &mdash; <?php echo htmlspecialchars($station_name); ?> &bull; <?php echo date('l, F j, Y'); ?></div>
  </div>
  <div class="adm-actions">
    <a href="admin_reports.php" class="adm-btn adm-btn-outline"><i class="fas fa-chart-bar"></i> Reports</a>
    <a href="admin_audit_trail.php" class="adm-btn adm-btn-outline"><i class="fas fa-list-check"></i> Audit Trail</a>
    <a href="admin_export_center.php" class="adm-btn adm-btn-primary"><i class="fas fa-file-export"></i> Export</a>
  </div>
</div>

<!-- DATE FILTER -->
<form method="GET" class="adm-filter-bar">
  <div class="adm-fg">
    <label>Quick Range</label>
    <div class="adm-qf-wrap">
      <?php foreach(['today'=>'Today','week'=>'This Week','month'=>'This Month','last_month'=>'Last Month'] as $k=>$lbl): ?>
      <button type="submit" name="quick" value="<?php echo $k; ?>" class="adm-qf-btn<?php echo ($quick===$k)?' active':''; ?>"><?php echo $lbl; ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="adm-fg"><label>From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"></div>
  <div class="adm-fg"><label>To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"></div>
  <button type="submit" name="quick" value="custom" class="adm-btn adm-btn-primary adm-btn-sm"><i class="fas fa-filter"></i> Apply</button>
</form>

<!-- KPI SUMMARY CARDS -->
<div class="adm-kpi-row">
  <div class="adm-kpi-card">
    <div class="adm-kpi-ico"><i class="fas fa-truck"></i></div>
    <div class="adm-kpi-meta"><h3>Upcoming Deliveries</h3><h2><?php echo $upcoming_deliveries; ?></h2><span>scheduled</span></div>
  </div>
  <div class="adm-kpi-card">
    <div class="adm-kpi-ico"><i class="fas fa-gauge-high"></i></div>
    <div class="adm-kpi-meta"><h3>Scheduled Calibrations</h3><h2><?php echo $scheduled_calibrations; ?></h2><span>pumps (7d)</span></div>
  </div>
  <div class="adm-kpi-card<?php echo $pending_job_orders>0?' kc-warning':''; ?>">
    <div class="adm-kpi-ico"><i class="fas fa-wrench"></i></div>
    <div class="adm-kpi-meta"><h3>Pending Job Orders</h3><h2><?php echo $pending_job_orders; ?></h2><span>awaiting action</span></div>
  </div>
  <div class="adm-kpi-card<?php echo $active_shifts_today>0?' kc-success':''; ?>">
    <div class="adm-kpi-ico"><i class="fas fa-user-clock"></i></div>
    <div class="adm-kpi-meta"><h3>Active Shifts Today</h3><h2><?php echo $active_shifts_today; ?></h2><span>on duty</span></div>
  </div>
  <div class="adm-kpi-card<?php echo $variance_alerts_open>0?' kc-danger':''; ?>">
    <div class="adm-kpi-ico"><i class="fas fa-circle-exclamation"></i></div>
    <div class="adm-kpi-meta"><h3>Variance Alerts</h3><h2><?php echo $variance_alerts_open; ?></h2><span><?php echo number_format($variance_liters,1); ?>L total</span></div>
  </div>
  <div class="adm-kpi-card">
    <div class="adm-kpi-ico"><i class="fas fa-gas-pump"></i></div>
    <div class="adm-kpi-meta"><h3>Fuel Revenue</h3><h2>&#8369;<?php echo number_format($fuel_revenue,0); ?></h2><span><?php echo number_format($fuel_liters,0); ?>L sold</span></div>
  </div>
  <div class="adm-kpi-card">
    <div class="adm-kpi-ico"><i class="fas fa-store"></i></div>
    <div class="adm-kpi-meta"><h3>Merch Revenue</h3><h2>&#8369;<?php echo number_format($merch_revenue,0); ?></h2><span>&#8369;<?php echo number_format($merch_credit,0); ?> credit</span></div>
  </div>
  <div class="adm-kpi-card<?php echo $ar_total>0?' kc-warning':''; ?>">
    <div class="adm-kpi-ico"><i class="fas fa-file-invoice-dollar"></i></div>
    <div class="adm-kpi-meta"><h3>Accounts Receivable</h3><h2>&#8369;<?php echo number_format($ar_total,0); ?></h2><span>&#8369;<?php echo number_format($ar_collected,0); ?> collected</span></div>
  </div>
</div>

<!-- ANALYTICS CHARTS -->
<div class="adm-card">
  <div class="adm-card-head">
    <div class="adm-card-title"><i class="fas fa-chart-mixed"></i> Real-time Analytics</div>
    <button onclick="admRefreshChart()" class="adm-btn adm-btn-outline adm-btn-sm"><i class="fas fa-sync-alt"></i> Refresh</button>
  </div>
  <div class="adm-chart-tabs">
    <button class="adm-tab-btn active" onclick="admSwitch('sales',this)"><i class="fas fa-chart-line"></i> Sales</button>
    <button class="adm-tab-btn" onclick="admSwitch('deliveries',this)"><i class="fas fa-truck"></i> Deliveries</button>
    <button class="adm-tab-btn" onclick="admSwitch('jobs',this)"><i class="fas fa-wrench"></i> Job Orders</button>
    <button class="adm-tab-btn" onclick="admSwitch('staff',this)"><i class="fas fa-users"></i> Staff</button>
    <button class="adm-tab-btn" onclick="admSwitch('variance',this)"><i class="fas fa-triangle-exclamation"></i> Variance</button>
    <button class="adm-tab-btn" onclick="admSwitch('ar',this)"><i class="fas fa-file-invoice-dollar"></i> Receivable</button>
  </div>
  <div class="adm-panels-wrap">

    <!-- SALES -->
    <div class="adm-chart-panel active" id="adm-panel-sales">
      <div class="adm-chart-grid">
        <div class="adm-chart-box"><h4><i class="fas fa-gas-pump"></i> Fuel Liters Sold – Last 7 Days</h4><div class="adm-chart-holder"><canvas id="admFuelBar"></canvas></div></div>
        <div class="adm-chart-box"><h4><i class="fas fa-store"></i> Merchandise Revenue – Last 14 Days</h4><div class="adm-chart-holder"><canvas id="admMerchLine"></canvas></div></div>
        <div class="adm-chart-box"><h4><i class="fas fa-chart-pie"></i> Fuel vs Merchandise Revenue Split</h4><div class="adm-chart-holder"><canvas id="admSalesPie"></canvas></div></div>
        <div class="adm-chart-box"><h4><i class="fas fa-chart-line"></i> 30-Day Consolidated Revenue Trend</h4><div class="adm-chart-holder"><canvas id="admSalesTrend"></canvas></div></div>
      </div>
    </div>

    <!-- DELIVERIES -->
    <div class="adm-chart-panel" id="adm-panel-deliveries">
      <div class="adm-chart-grid">
        <div class="adm-chart-box"><h4><i class="fas fa-building"></i> Deliveries by Supplier</h4><div class="adm-chart-holder"><canvas id="admDelSupplier"></canvas></div></div>
        <div class="adm-chart-box"><h4><i class="fas fa-circle-half-stroke"></i> Expected vs Approved vs Flagged</h4><div class="adm-chart-holder"><canvas id="admDelStatus"></canvas></div></div>
      </div>
    </div>

    <!-- JOB ORDERS -->
    <div class="adm-chart-panel" id="adm-panel-jobs">
      <div class="adm-chart-grid">
        <div class="adm-chart-box"><h4><i class="fas fa-list-ol"></i> Pending vs In-Progress vs Completed</h4><div class="adm-chart-holder"><canvas id="admJoStatus"></canvas></div></div>
        <div class="adm-chart-box"><h4><i class="fas fa-chart-line"></i> Weekly Job Order Volume (8 Weeks)</h4><div class="adm-chart-holder"><canvas id="admJoWeekly"></canvas></div></div>
      </div>
    </div>

    <!-- STAFF -->
    <div class="adm-chart-panel" id="adm-panel-staff">
      <div class="adm-staff-kpi">
        <?php foreach(array_slice($staff_perf,0,5) as $idx=>$sp): ?>
        <div class="adm-staff-kpi-item">
          <div class="sk-rank">#<?php echo $idx+1; ?> Performer</div>
          <div class="sk-name"><?php echo htmlspecialchars($sp['name']); ?></div>
          <div class="sk-val"><?php echo (int)$sp['total_activity']; ?></div>
          <div class="sk-label">activities</div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($staff_perf)): ?><div class="adm-staff-kpi-item"><div class="sk-name">No staff data</div></div><?php endif; ?>
      </div>
      <div class="adm-chart-grid">
        <div class="adm-chart-box"><h4><i class="fas fa-ranking-star"></i> Transactions per Staff</h4><div class="adm-chart-holder"><canvas id="admStaffActivity"></canvas></div></div>
        <div class="adm-chart-box"><h4><i class="fas fa-calendar-check"></i> Attendance – Shifts per Staff (30d)</h4><div class="adm-chart-holder"><canvas id="admStaffAttend"></canvas></div></div>
      </div>
    </div>

    <!-- VARIANCE -->
    <div class="adm-chart-panel" id="adm-panel-variance">
      <div class="adm-chart-grid">
        <div class="adm-chart-box"><h4><i class="fas fa-chart-line"></i> Pump Readings vs Sales vs Deliveries (7 Days)</h4><div class="adm-chart-holder"><canvas id="admVarLine"></canvas></div></div>
        <div class="adm-chart-box"><h4><i class="fas fa-oil-can"></i> Variance by Tank / Fuel Type</h4><div class="adm-chart-holder"><canvas id="admVarTank"></canvas></div></div>
      </div>
    </div>

    <!-- ACCOUNTS RECEIVABLE -->
    <div class="adm-chart-panel" id="adm-panel-ar">
      <div class="adm-chart-grid">
        <div class="adm-chart-box"><h4><i class="fas fa-chart-pie"></i> Outstanding Balances per Customer</h4><div class="adm-chart-holder"><canvas id="admArPie"></canvas></div></div>
        <div class="adm-chart-box"><h4><i class="fas fa-chart-bar"></i> Collections vs Pending Balance</h4><div class="adm-chart-holder"><canvas id="admArBar"></canvas></div></div>
      </div>
    </div>

  </div><!-- /.adm-panels-wrap -->
</div><!-- /.adm-card analytics -->

<!-- OVERSIGHT SNAPSHOTS -->
<div class="adm-snap-grid">

  <!-- User Management -->
  <div class="adm-snap-card">
    <div class="adm-snap-title"><i class="fas fa-users-gear"></i> User Management</div>
    <div class="adm-snap-row"><span>Active Staff</span><span class="adm-badge bg-green"><?php echo $staff_active; ?> Active</span></div>
    <div class="adm-snap-row"><span>Inactive Staff</span><span class="adm-badge bg-red"><?php echo $staff_inactive; ?> Inactive</span></div>
    <div class="adm-snap-row"><span>Managers / Supervisors</span><span class="adm-badge bg-blue"><?php echo $mgr_count; ?></span></div>
    <a href="users.php" class="adm-snap-link"><i class="fas fa-arrow-right"></i> Manage Users</a>
  </div>

  <!-- Staff Oversight -->
  <div class="adm-snap-card">
    <div class="adm-snap-title"><i class="fas fa-id-badge"></i> Staff Oversight</div>
    <div class="adm-snap-row"><span>Active Shifts Today</span><span class="adm-badge <?php echo $active_shifts_today>0?'bg-green':'bg-amber'; ?>"><?php echo $active_shifts_today; ?></span></div>
    <div class="adm-snap-row"><span>Top Performer</span><strong><?php echo !empty($staff_perf)?htmlspecialchars($staff_perf[0]['name']):'N/A'; ?></strong></div>
    <div class="adm-snap-row"><span>Total Staff Tracked</span><strong><?php echo $staff_active+$staff_inactive; ?></strong></div>
    <a href="admin_staff_oversight.php" class="adm-snap-link"><i class="fas fa-arrow-right"></i> View Staff</a>
  </div>

  <!-- Transactions Oversight -->
  <div class="adm-snap-card">
    <div class="adm-snap-title"><i class="fas fa-receipt"></i> Transactions Oversight</div>
    <div class="adm-snap-row"><span>Pending Validation</span><span class="adm-badge <?php echo $tx_pending>0?'bg-amber':'bg-green'; ?>"><?php echo $tx_pending; ?> Pending</span></div>
    <div class="adm-snap-row"><span>Approved</span><span class="adm-badge bg-green"><?php echo $tx_approved; ?> Approved</span></div>
    <div class="adm-snap-row"><span>Merch Revenue</span><strong>&#8369;<?php echo number_format($merch_revenue,0); ?></strong></div>
    <a href="admin_transactions_oversight.php" class="adm-snap-link"><i class="fas fa-arrow-right"></i> View Transactions</a>
  </div>

  <!-- Deliveries Oversight -->
  <div class="adm-snap-card">
    <div class="adm-snap-title"><i class="fas fa-truck-ramp-box"></i> Deliveries Oversight</div>
    <div class="adm-snap-row"><span>Flagged / Discrepancy</span><span class="adm-badge <?php echo $del_flagged_snap>0?'bg-red':'bg-green'; ?>"><?php echo $del_flagged_snap; ?> Flagged</span></div>
    <div class="adm-snap-row"><span>Validated / Approved</span><span class="adm-badge bg-green"><?php echo $del_validated; ?> Validated</span></div>
    <div class="adm-snap-row"><span>Upcoming</span><strong><?php echo $upcoming_deliveries; ?></strong></div>
    <a href="admin_deliveries_oversight.php" class="adm-snap-link"><i class="fas fa-arrow-right"></i> View Deliveries</a>
  </div>

  <!-- Purchase Orders -->
  <div class="adm-snap-card">
    <div class="adm-snap-title"><i class="fas fa-file-circle-plus"></i> Purchase Orders</div>
    <div class="adm-snap-row"><span>Pending Approval</span><span class="adm-badge <?php echo $po_pending>0?'bg-amber':'bg-green'; ?>"><?php echo $po_pending; ?> Pending</span></div>
    <div class="adm-snap-row"><span>Finalized / Received</span><span class="adm-badge bg-green"><?php echo $po_finalized; ?> Done</span></div>
    <div class="adm-snap-row"><span>Job Orders Pending</span><span class="adm-badge <?php echo $jo_pending>0?'bg-amber':'bg-green'; ?>"><?php echo $jo_pending; ?></span></div>
    <a href="purchase_orders.php" class="adm-snap-link"><i class="fas fa-arrow-right"></i> View POs</a>
  </div>

  <!-- Audit Trail -->
  <div class="adm-snap-card">
    <div class="adm-snap-title"><i class="fas fa-list-check"></i> Recent Audit Trail</div>
    <?php if(!empty($recent_audit)): ?>
    <div class="adm-audit-list">
      <?php foreach(array_slice($recent_audit,0,5) as $au): ?>
      <div class="adm-audit-item">
        <div class="adm-audit-dot"></div>
        <div class="adm-audit-name"><?php echo htmlspecialchars($au['name']??''); ?></div>
        <div class="adm-audit-action"><?php echo htmlspecialchars(($au['action_type']??'').' '.($au['entity_type']??'')); ?></div>
        <div class="adm-audit-time"><?php echo date('M j H:i',strtotime($au['created_at']??'now')); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?><p style="font-size:11px;color:#94a3b8;padding:8px 0;">No recent entries.</p><?php endif; ?>
    <a href="admin_audit_trail.php" class="adm-snap-link"><i class="fas fa-arrow-right"></i> Full Audit Trail</a>
  </div>

</div><!-- /.adm-snap-grid -->

<!-- REPORTS SNAPSHOT + EXPORT -->
<div class="adm-card">
  <div class="adm-card-head">
    <div class="adm-card-title"><i class="fas fa-chart-bar"></i> Reports Snapshot</div>
  </div>
  <div class="adm-reports-grid">
    <div class="adm-report-item"><div class="ri-val">&#8369;<?php echo number_format($fuel_revenue,0); ?></div><div class="ri-label">Fuel Revenue</div></div>
    <div class="adm-report-item"><div class="ri-val">&#8369;<?php echo number_format($merch_revenue,0); ?></div><div class="ri-label">Merch Revenue</div></div>
    <div class="adm-report-item"><div class="ri-val">&#8369;<?php echo number_format($fuel_revenue+$merch_revenue,0); ?></div><div class="ri-label">Total Revenue</div></div>
    <div class="adm-report-item"><div class="ri-val">&#8369;<?php echo number_format($ar_total,0); ?></div><div class="ri-label">AR Outstanding</div></div>
    <div class="adm-report-item"><div class="ri-val"><?php echo number_format($variance_liters,1); ?>L</div><div class="ri-label">Variance (Liters)</div></div>
    <div class="adm-report-item"><div class="ri-val"><?php echo $variance_alerts_open; ?></div><div class="ri-label">Open Variance Alerts</div></div>
  </div>
  <div class="adm-export-row">
    <a href="admin_export_center.php?type=sales&format=excel" class="adm-export-btn xls"><i class="fas fa-file-excel"></i> Sales Excel</a>
    <a href="admin_export_center.php?type=sales&format=pdf"   class="adm-export-btn pdf"><i class="fas fa-file-pdf"></i> Sales PDF</a>
    <a href="admin_export_center.php?type=ar&format=excel"    class="adm-export-btn xls"><i class="fas fa-file-excel"></i> AR Excel</a>
    <a href="admin_export_center.php?type=ar&format=pdf"      class="adm-export-btn pdf"><i class="fas fa-file-pdf"></i> AR PDF</a>
    <a href="admin_export_center.php?type=variance&format=excel" class="adm-export-btn xls"><i class="fas fa-file-excel"></i> Variance Excel</a>
    <a href="admin_export_center.php?type=variance&format=pdf"   class="adm-export-btn pdf"><i class="fas fa-file-pdf"></i> Variance PDF</a>
    <a href="admin_reports.php" class="adm-export-btn"><i class="fas fa-chart-bar"></i> Full Reports</a>
  </div>
</div>

<!-- CALENDAR OVERSIGHT -->
<?php
$today_str  = date('Y-m-d');
$prev_week  = $week_offset - 1;
$next_week  = $week_offset + 1;
$base_url   = '?quick='.urlencode($quick).'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to);
$week_label = date('M j',strtotime($start_of_week)).' – '.date('M j, Y',strtotime($end_of_week));
?>
<div class="adm-cal">
  <div class="adm-cal-head">
    <h3><i class="fas fa-calendar-week"></i> Calendar Oversight &mdash; <?php echo $week_label; ?></h3>
    <div class="adm-cal-nav">
      <a href="<?php echo $base_url.'&week='.$prev_week; ?>" class="adm-cal-nav-btn"><i class="fas fa-chevron-left"></i> Prev</a>
      <a href="<?php echo $base_url.'&week=0'; ?>" class="adm-cal-nav-btn">This Week</a>
      <a href="<?php echo $base_url.'&week='.$next_week; ?>" class="adm-cal-nav-btn">Next <i class="fas fa-chevron-right"></i></a>
    </div>
  </div>
  <div class="adm-cal-filters">
    <button class="adm-cal-filter-btn active" onclick="admCalFilter('all',this)">All</button>
    <button class="adm-cal-filter-btn" onclick="admCalFilter('job_orders',this)">Job Orders</button>
    <button class="adm-cal-filter-btn" onclick="admCalFilter('deliveries',this)">Deliveries</button>
    <button class="adm-cal-filter-btn" onclick="admCalFilter('purchase_orders',this)">Purchase Orders</button>
    <button class="adm-cal-filter-btn" onclick="admCalFilter('fuel_calibration',this)">Calibrations</button>
    <button class="adm-cal-filter-btn" onclick="admCalFilter('staff_shift',this)">Staff Shifts</button>
  </div>
  <div class="adm-cal-grid">
    <?php
    $days_short = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    for($di=0;$di<7;$di++):
      $cdate  = date('Y-m-d',strtotime($start_of_week." +{$di} days"));
      $clabel = $days_short[$di].' '.date('j',strtotime($cdate));
      $is_today = ($cdate===$today_str);
      $devents  = array_filter($cal_events,fn($e)=>$e['date']===$cdate);
    ?>
    <div class="adm-cal-cell">
      <div class="adm-cal-day-title<?php echo $is_today?' today':''; ?>">
        <span><?php echo $clabel; ?></span>
        <?php if($is_today): ?><span style="font-size:7px;background:#CC0000;color:#fff;padding:1px 4px;border-radius:8px;">TODAY</span><?php endif; ?>
      </div>
      <?php if(empty($devents)): ?>
        <div class="adm-cal-empty">No events</div>
      <?php else: foreach($devents as $ev):
        $sc = in_array($ev['status'],['pending','approved','completed','rejected','cancelled'])?$ev['status']:'pending';
      ?>
        <div class="adm-cal-event <?php echo $sc; ?>" data-cal-type="<?php echo htmlspecialchars($ev['type']); ?>"
             title="<?php echo htmlspecialchars($ev['title']); ?>">
          <?php echo htmlspecialchars(mb_strimwidth($ev['title'],0,35,'…')); ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endfor; ?>
  </div>
</div>

<!-- QUICK ACCESS (bottom) -->
<div class="adm-card">
  <div class="adm-card-title" style="margin-bottom:13px;"><i class="fas fa-th"></i> Quick Access</div>
  <div class="adm-ql-grid">
    <a href="users.php"                       class="adm-ql-card"><i class="fas fa-users-gear"></i>User Management</a>
    <a href="admin_staff_oversight.php"       class="adm-ql-card"><i class="fas fa-id-badge"></i>Staff Oversight</a>
    <a href="admin_transactions_oversight.php" class="adm-ql-card"><i class="fas fa-receipt"></i>Transactions</a>
    <a href="admin_deliveries_oversight.php"  class="adm-ql-card"><i class="fas fa-truck-ramp-box"></i>Deliveries</a>
    <a href="purchase_orders.php"             class="adm-ql-card"><i class="fas fa-file-circle-plus"></i>Purchase Orders</a>
    <a href="admin_reports.php"               class="adm-ql-card"><i class="fas fa-chart-line"></i>Sales Reports</a>
    <a href="admin_reports.php?tab=receivable" class="adm-ql-card"><i class="fas fa-hand-holding-dollar"></i>Receivables</a>
    <a href="admin_reports.php?tab=variance"  class="adm-ql-card"><i class="fas fa-triangle-exclamation"></i>Variance Log</a>
    <a href="joborder.php"                    class="adm-ql-card"><i class="fas fa-screwdriver-wrench"></i>Job Orders</a>
    <a href="admin_audit_trail.php"           class="adm-ql-card"><i class="fas fa-list-check"></i>Audit Logs</a>
    <a href="admin_export_center.php"         class="adm-ql-card"><i class="fas fa-file-export"></i>Export Center</a>
    <a href="admin_calendar.php"              class="adm-ql-card"><i class="fas fa-calendar-days"></i>Calendar</a>
  </div>
</div>

</div><!-- /.adm-wrap -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
'use strict';

/* ── PHP data ── */
const fuelRev    = <?php echo json_encode((float)$fuel_revenue); ?>;
const merchRev   = <?php echo json_encode((float)$merch_revenue); ?>;
const trendLbl   = <?php echo json_encode($trend_labels); ?>;
const trendFuel  = <?php echo json_encode($trend_fuel); ?>;
const trendMerch = <?php echo json_encode($trend_merch); ?>;
const fuelBarLbl = <?php echo json_encode($fuel_bar_labels); ?>;
const fuelBarDat = <?php echo json_encode($fuel_bar_data); ?>;
const mTrendLbl  = <?php echo json_encode($merch_trend_labels); ?>;
const mTrendDat  = <?php echo json_encode($merch_trend_data); ?>;
const delSupLbl  = <?php echo json_encode($del_sup_labels); ?>;
const delSupDat  = <?php echo json_encode($del_sup_data); ?>;
const delPend    = <?php echo json_encode((int)$del_pending); ?>;
const delAppr    = <?php echo json_encode((int)$del_approved); ?>;
const delFlag    = <?php echo json_encode((int)$del_flagged); ?>;
const joPend     = <?php echo json_encode((int)$jo_pending); ?>;
const joInProg   = <?php echo json_encode((int)$jo_in_progress); ?>;
const joComp     = <?php echo json_encode((int)$jo_completed); ?>;
const joWkLbl    = <?php echo json_encode($jo_week_labels); ?>;
const joWkDat    = <?php echo json_encode($jo_week_data); ?>;
const stNames    = <?php echo json_encode($staff_names); ?>;
const stAct      = <?php echo json_encode($staff_act); ?>;
const attNames   = <?php echo json_encode($att_names); ?>;
const attShifts  = <?php echo json_encode($att_shifts); ?>;
const varLbl     = <?php echo json_encode($var_labels); ?>;
const varPump    = <?php echo json_encode($var_pump_data); ?>;
const varSales   = <?php echo json_encode($var_sales_data); ?>;
const varDel     = <?php echo json_encode($var_del_data); ?>;
const varTkLbl   = <?php echo json_encode($var_tank_labels); ?>;
const varTkVal   = <?php echo json_encode($var_tank_values); ?>;
const arNames    = <?php echo json_encode($ar_names); ?>;
const arBal      = <?php echo json_encode($ar_balances); ?>;
const arTotal    = <?php echo json_encode((float)$ar_total); ?>;
const arColl     = <?php echo json_encode((float)$ar_collected); ?>;

/* ── Palette ── */
const P = {
  blue:'#00264D', red:'#CC0000', green:'#22c55e',
  amber:'#f59e0b', purple:'#7c3aed', teal:'#0891b2', sky:'#38bdf8'
};
const BASE = { responsive:true, maintainAspectRatio:false, animation:{duration:350} };

/* ── Instance registry ── */
const CI = {};
let activeCat = 'sales';

function destroy(id){ if(CI[id]){ CI[id].destroy(); delete CI[id]; } }
function mk(id, cfg){
  destroy(id);
  const el = document.getElementById(id);
  if(el) CI[id] = new Chart(el, cfg);
}

/* ── Builders ── */
function buildSales(){
  mk('admFuelBar',{type:'bar',data:{labels:fuelBarLbl,datasets:[{label:'Liters',data:fuelBarDat,backgroundColor:P.blue+'cc',borderColor:P.blue,borderWidth:1,borderRadius:4}]},
    options:{...BASE,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:10}}}}}});

  mk('admMerchLine',{type:'line',data:{labels:mTrendLbl,datasets:[{label:'Merch ₱',data:mTrendDat,borderColor:P.teal,backgroundColor:P.teal+'22',fill:true,tension:0.4,pointRadius:3}]},
    options:{...BASE,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:9},maxRotation:45}}}}});

  mk('admSalesPie',{type:'pie',data:{labels:['Fuel Revenue','Merch Revenue'],datasets:[{data:[fuelRev,merchRev],backgroundColor:[P.blue+'dd',P.teal+'dd'],borderColor:'#fff',borderWidth:2}]},
    options:{...BASE,plugins:{legend:{position:'bottom',labels:{font:{size:10},boxWidth:12}}}}});

  mk('admSalesTrend',{type:'line',data:{labels:trendLbl,datasets:[
    {label:'Fuel ₱',data:trendFuel,borderColor:P.blue,backgroundColor:P.blue+'18',fill:true,tension:0.4,pointRadius:2},
    {label:'Merch ₱',data:trendMerch,borderColor:P.teal,backgroundColor:P.teal+'18',fill:true,tension:0.4,pointRadius:2}]},
    options:{...BASE,plugins:{legend:{position:'top',labels:{font:{size:10},boxWidth:12}}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:9},maxTicksLimit:10,maxRotation:45}}}}});
}

function buildDeliveries(){
  mk('admDelSupplier',{type:'bar',data:{labels:delSupLbl,datasets:[{label:'Deliveries',data:delSupDat,backgroundColor:P.sky+'cc',borderColor:P.sky,borderWidth:1,borderRadius:4}]},
    options:{...BASE,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:10}}}}}});

  mk('admDelStatus',{type:'bar',data:{labels:['Pending','Approved','Flagged'],datasets:[{label:'Count',data:[delPend,delAppr,delFlag],backgroundColor:[P.amber+'cc',P.green+'cc',P.red+'cc'],borderColor:[P.amber,P.green,P.red],borderWidth:1,borderRadius:4}]},
    options:{...BASE,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:10}}}}}});
}

function buildJobs(){
  mk('admJoStatus',{type:'bar',data:{labels:['Pending','In Progress','Completed'],datasets:[{label:'JOs',data:[joPend,joInProg,joComp],backgroundColor:[P.amber+'cc',P.sky+'cc',P.green+'cc'],borderColor:[P.amber,P.sky,P.green],borderWidth:1,borderRadius:4}]},
    options:{...BASE,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:10}}}}}});

  mk('admJoWeekly',{type:'line',data:{labels:joWkLbl,datasets:[{label:'Job Orders',data:joWkDat,borderColor:P.purple,backgroundColor:P.purple+'22',fill:true,tension:0.4,pointRadius:4}]},
    options:{...BASE,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:10}}}}}});
}

function buildStaff(){
  mk('admStaffActivity',{type:'bar',data:{labels:stNames,datasets:[{label:'Activities',data:stAct,backgroundColor:P.blue+'cc',borderColor:P.blue,borderWidth:1,borderRadius:4}]},
    options:{...BASE,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{font:{size:10}}},y:{ticks:{font:{size:10}}}}}});

  mk('admStaffAttend',{type:'bar',data:{labels:attNames,datasets:[{label:'Shifts',data:attShifts,backgroundColor:P.teal+'cc',borderColor:P.teal,borderWidth:1,borderRadius:4}]},
    options:{...BASE,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{font:{size:10}}},y:{ticks:{font:{size:10}}}}}});
}

function buildVariance(){
  mk('admVarLine',{type:'line',data:{labels:varLbl,datasets:[
    {label:'Pump Readings (L)',data:varPump,borderColor:P.blue,fill:false,tension:0.4,pointRadius:3},
    {label:'Sales (L)',data:varSales,borderColor:P.green,fill:false,tension:0.4,pointRadius:3},
    {label:'Deliveries (L)',data:varDel,borderColor:P.amber,fill:false,tension:0.4,pointRadius:3}]},
    options:{...BASE,plugins:{legend:{position:'top',labels:{font:{size:10},boxWidth:12}}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:9},maxRotation:30}}}}});

  mk('admVarTank',{type:'bar',data:{labels:varTkLbl,datasets:[{label:'Variance (L)',data:varTkVal,backgroundColor:P.red+'cc',borderColor:P.red,borderWidth:1,borderRadius:4}]},
    options:{...BASE,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:10}}}}}});
}

function buildAR(){
  mk('admArPie',{type:'pie',data:{labels:arNames,datasets:[{data:arBal,backgroundColor:[P.blue+'dd',P.teal+'dd',P.amber+'dd',P.purple+'dd',P.red+'dd',P.sky+'dd'],borderColor:'#fff',borderWidth:2}]},
    options:{...BASE,plugins:{legend:{position:'bottom',labels:{font:{size:10},boxWidth:12}}}}});

  mk('admArBar',{type:'bar',data:{labels:['Collected','Outstanding'],datasets:[{label:'₱',data:[arColl,arTotal],backgroundColor:[P.green+'cc',P.red+'cc'],borderColor:[P.green,P.red],borderWidth:1,borderRadius:4}]},
    options:{...BASE,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font:{size:10}}},x:{ticks:{font:{size:10}}}}}});
}

function buildCharts(cat){
  switch(cat){
    case 'sales':      buildSales();      break;
    case 'deliveries': buildDeliveries(); break;
    case 'jobs':       buildJobs();       break;
    case 'staff':      buildStaff();      break;
    case 'variance':   buildVariance();   break;
    case 'ar':         buildAR();         break;
  }
}

/* ── Public API ── */
window.admSwitch = function(cat, btn){
  document.querySelectorAll('.adm-chart-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.adm-tab-btn').forEach(b=>b.classList.remove('active'));
  const panel = document.getElementById('adm-panel-'+cat);
  if(panel) panel.classList.add('active');
  if(btn)   btn.classList.add('active');
  activeCat = cat;
  buildCharts(cat);
};

window.admRefreshChart = function(){
  buildCharts(activeCat);
};

window.admCalFilter = function(cat, btn){
  document.querySelectorAll('.adm-cal-filter-btn').forEach(b=>b.classList.remove('active'));
  if(btn) btn.classList.add('active');
  document.querySelectorAll('.adm-cal-event').forEach(el=>{
    el.style.display = (cat==='all' || el.dataset.calType===cat) ? '' : 'none';
  });
};

window.addEventListener('load', function(){ buildCharts('sales'); });

})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
