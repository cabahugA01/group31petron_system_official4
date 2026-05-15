<?php
// ============================================================
// Admin Dashboard – public/admin_dashboard.php
// All queries verified against actual DB schema
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

// Guard: admin must have a station assigned
if ($station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

// ── Date range ────────────────────────────────────────────────────────────────
$quick     = trim($_GET['quick']     ?? 'today');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');

switch ($quick) {
    case 'week':
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to   = date('Y-m-d');
        break;
    case 'month':
        $date_from = date('Y-m-01');
        $date_to   = date('Y-m-d');
        break;
    case 'last_month':
        $date_from = date('Y-m-01', strtotime('first day of last month'));
        $date_to   = date('Y-m-t',  strtotime('last day of last month'));
        break;
    default:
        if (empty($date_from)) $date_from = date('Y-m-d');
        if (empty($date_to))   $date_to   = date('Y-m-d');
        if ($quick === 'today') { $date_from = $date_to = date('Y-m-d'); }
        break;
}

// ── Station name ──────────────────────────────────────────────────────────────
$station_name = 'Station';
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: 'Station';
} catch (Exception $e) {}

// ── Safe query helpers ────────────────────────────────────────────────────────
function adm_val(PDO $pdo, string $sql, array $p = [], $default = 0) {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn() ?? $default; }
    catch (Exception $e) { return $default; }
}
function adm_rows(PDO $pdo, string $sql, array $p = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Exception $e) { return []; }
}

// ══════════════════════════════════════════════════════════════════════════════
// SUMMARY CARDS
// ══════════════════════════════════════════════════════════════════════════════

// 1. Total Fuel Sales — liters + revenue
$fuel_revenue = (float) adm_val($pdo,
    "SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions
     WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
$fuel_liters = (float) adm_val($pdo,
    "SELECT COALESCE(SUM(liters_sold),0) FROM fuel_transactions
     WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);

// 2. Total Merchandise Sales — revenue + payment mix
$merch_revenue = (float) adm_val($pdo,
    "SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions
     WHERE station_id=? AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
$merch_cash = (float) adm_val($pdo,
    "SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions
     WHERE station_id=? AND payment_method='Cash'
     AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
$merch_credit = (float) adm_val($pdo,
    "SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions
     WHERE station_id=? AND payment_method='Credit'
     AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
$merch_card = $merch_revenue - $merch_cash - $merch_credit;

// 3. Variance Alerts — from fuel_variance_reports (variance_liters column)
$variance_open = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM fuel_variance_reports
     WHERE station_id=? AND status IN ('Open','Under Investigation')
     AND DATE(created_at) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
$variance_liters = (float) adm_val($pdo,
    "SELECT COALESCE(SUM(ABS(variance_liters)),0) FROM fuel_variance_reports
     WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
// Also check variance_alerts table
$var_alerts_open = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM variance_alerts
     WHERE station_id=? AND status='open'
     AND DATE(created_at) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
$total_variance_alerts = $variance_open + $var_alerts_open;

// 4. Deliveries Status
$del_pending  = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM deliveries_oversight
     WHERE station_id=? AND status IN ('Pending Validation','Pending Manager Approval','Pending Manager Confirmation')
     AND DATE(COALESCE(delivery_date,created_at)) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
$del_approved = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM deliveries_oversight
     WHERE station_id=? AND status IN ('Validated','Confirmed','Approved')
     AND DATE(COALESCE(delivery_date,created_at)) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
$del_rejected = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM deliveries_oversight
     WHERE station_id=? AND status IN ('Rejected','Flagged','Discrepancy')
     AND DATE(COALESCE(delivery_date,created_at)) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
$del_total = $del_pending + $del_approved + $del_rejected;

// 5. Staff Accounts Snapshot — active vs inactive (exclude admin/manager/superadmin)
$staff_active   = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM users WHERE station_id=? AND status='active'
     AND role NOT IN ('admin','manager','superadmin','Admin','Manager','Super Admin')",
    [$station_id]);
$staff_inactive = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM users WHERE station_id=? AND status='inactive'
     AND role NOT IN ('admin','manager','superadmin','Admin','Manager','Super Admin')",
    [$station_id]);
$mgr_count = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM users WHERE station_id=? AND status='active'
     AND role IN ('manager','Manager','supervisor')",
    [$station_id]);

// ══════════════════════════════════════════════════════════════════════════════
// COMPLIANCE ALERTS
// ══════════════════════════════════════════════════════════════════════════════
$compliance_alerts = [];
// Rejected transactions
$rej_txn = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM merchandise_transactions
     WHERE station_id=? AND validation_status='Rejected'
     AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
if ($rej_txn > 0)
    $compliance_alerts[] = ['type'=>'danger','icon'=>'fa-times-circle','msg'=>"{$rej_txn} rejected merchandise transaction(s)"];
if ($total_variance_alerts > 0)
    $compliance_alerts[] = ['type'=>'danger','icon'=>'fa-exclamation-triangle','msg'=>"{$total_variance_alerts} open variance alert(s)"];
if ($del_pending > 0)
    $compliance_alerts[] = ['type'=>'warning','icon'=>'fa-truck','msg'=>"{$del_pending} delivery(ies) pending validation"];
// Price changes from audit_logs (join users for station scope)
$price_changes = (int) adm_val($pdo,
    "SELECT COUNT(*) FROM audit_logs al
     JOIN users u ON u.id=al.user_id
     WHERE u.station_id=? AND al.action_type LIKE '%price%'
     AND DATE(al.created_at) BETWEEN ? AND ?",
    [$station_id, $date_from, $date_to]);
if ($price_changes > 0)
    $compliance_alerts[] = ['type'=>'warning','icon'=>'fa-tag','msg'=>"{$price_changes} price change(s) in audit trail"];

// ══════════════════════════════════════════════════════════════════════════════
// CHARTS DATA
// ══════════════════════════════════════════════════════════════════════════════

// Sales Trend — 30 days
$trend_labels = $trend_fuel = $trend_merch = [];
$fuel_map  = array_column(adm_rows($pdo,
    "SELECT DATE(transaction_date) AS d, COALESCE(SUM(total_amount),0) AS rev
     FROM fuel_transactions WHERE station_id=?
     AND DATE(transaction_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY DATE(transaction_date)", [$station_id]), 'rev', 'd');
$merch_map = array_column(adm_rows($pdo,
    "SELECT DATE(COALESCE(transaction_date,created_at)) AS d, COALESCE(SUM(total_amount),0) AS rev
     FROM merchandise_transactions WHERE station_id=?
     AND DATE(COALESCE(transaction_date,created_at)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY DATE(COALESCE(transaction_date,created_at))", [$station_id]), 'rev', 'd');
for ($i=29; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trend_labels[] = date('M j', strtotime($d));
    $trend_fuel[]   = (float)($fuel_map[$d]  ?? 0);
    $trend_merch[]  = (float)($merch_map[$d] ?? 0);
}

// Deliveries Trend — 14 days
$del_labels = $del_counts = [];
$del_map = array_column(adm_rows($pdo,
    "SELECT DATE(COALESCE(delivery_date,created_at)) AS d, COUNT(*) AS cnt
     FROM deliveries_oversight WHERE station_id=?
     AND DATE(COALESCE(delivery_date,created_at)) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
     GROUP BY DATE(COALESCE(delivery_date,created_at))", [$station_id]), 'cnt', 'd');
for ($i=13; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $del_labels[] = date('M j', strtotime($d));
    $del_counts[] = (int)($del_map[$d] ?? 0);
}

// Variance Distribution — by fuel_type from fuel_variance_reports
$var_rows = adm_rows($pdo,
    "SELECT fuel_type, COALESCE(SUM(ABS(variance_liters)),0) AS total
     FROM fuel_variance_reports WHERE station_id=?
     AND DATE(created_at) BETWEEN ? AND ?
     GROUP BY fuel_type ORDER BY total DESC LIMIT 6",
    [$station_id, $date_from, $date_to]);
$var_labels  = !empty($var_rows) ? array_column($var_rows,'fuel_type') : ['No Data'];
$var_amounts = !empty($var_rows) ? array_column($var_rows,'total')     : [0];

// Staff Performance Chart — top 8 by activity
$perf_rows = adm_rows($pdo,
    "SELECT u.name,
            COUNT(DISTINCT mt.id) AS txn_count,
            COUNT(DISTINCT jo.id) AS jo_count
     FROM users u
     LEFT JOIN merchandise_transactions mt
        ON mt.staff_id=u.id AND mt.station_id=?
        AND DATE(COALESCE(mt.transaction_date,mt.created_at)) BETWEEN ? AND ?
     LEFT JOIN job_orders jo
        ON jo.user_id=u.id AND jo.station_id=?
        AND DATE(jo.created_at) BETWEEN ? AND ?
     WHERE u.station_id=? AND u.status='active'
       AND u.role NOT IN ('manager','admin','superadmin','Admin','Manager','Super Admin')
     GROUP BY u.id, u.name
     ORDER BY (COUNT(DISTINCT mt.id)+COUNT(DISTINCT jo.id)) DESC LIMIT 8",
    [$station_id,$date_from,$date_to,$station_id,$date_from,$date_to,$station_id]);
$perf_labels = !empty($perf_rows) ? array_column($perf_rows,'name')      : ['No Data'];
$perf_txn    = !empty($perf_rows) ? array_column($perf_rows,'txn_count') : [0];
$perf_jo     = !empty($perf_rows) ? array_column($perf_rows,'jo_count')  : [0];

// ══════════════════════════════════════════════════════════════════════════════
// OVERSIGHT PANELS
// ══════════════════════════════════════════════════════════════════════════════

// User Management Snapshot — all roles
$users_all = adm_rows($pdo,
    "SELECT role, status, COUNT(*) AS cnt FROM users
     WHERE station_id=? GROUP BY role, status ORDER BY role, status",
    [$station_id]);

// Product & Pricing — fuel prices from fuel_pricing (active) + fuel_types
$fuel_prices = adm_rows($pdo,
    "SELECT ft.name AS fuel_type, fp.price_per_liter, fp.effective_date
     FROM fuel_pricing fp
     JOIN fuel_types ft ON ft.id=fp.fuel_type_id
     WHERE fp.station_id=? AND fp.is_active=1
     ORDER BY ft.name",
    [$station_id]);
// Fallback to fuel_inventory if fuel_pricing empty
if (empty($fuel_prices)) {
    $fuel_prices = adm_rows($pdo,
        "SELECT COALESCE(ft.name, fi.fuel_type) AS fuel_type,
                fi.price_per_liter, fi.last_updated AS effective_date
         FROM fuel_inventory fi
         LEFT JOIN fuel_types ft ON ft.id=fi.fuel_type_id
         WHERE fi.station_id=? ORDER BY fuel_type",
        [$station_id]);
}

// Merchandise catalog — join station_inventory + products
$merch_catalog = adm_rows($pdo,
    "SELECT p.name AS product_name, si.price, si.stock_level, si.unit
     FROM station_inventory si
     JOIN products p ON p.id=si.product_id
     WHERE si.station_id=? AND si.status='active'
     ORDER BY si.price DESC LIMIT 8",
    [$station_id]);

// Recent Audit Trail — Staff and Manager actions only (Admin is oversight, not logged here)
$audit_rows = adm_rows($pdo,
    "SELECT al.action_type, al.action_details, al.status, al.created_at,
            u.name AS user_name, u.role AS user_role
     FROM audit_logs al
     INNER JOIN users u ON u.id = al.user_id
     WHERE u.station_id = ?
       AND LOWER(TRIM(u.role)) NOT IN ('admin','superadmin','super admin','super_admin')
     ORDER BY al.created_at DESC LIMIT 10",
    [$station_id]);

// Top staff performer
$top_staff = adm_rows($pdo,
    "SELECT u.name,
            COUNT(DISTINCT mt.id) + COUNT(DISTINCT jo.id) AS total_activity
     FROM users u
     LEFT JOIN merchandise_transactions mt
        ON mt.staff_id=u.id AND mt.station_id=?
        AND DATE(COALESCE(mt.transaction_date,mt.created_at)) BETWEEN ? AND ?
     LEFT JOIN job_orders jo
        ON jo.user_id=u.id AND jo.station_id=?
        AND DATE(jo.created_at) BETWEEN ? AND ?
     WHERE u.station_id=? AND u.status='active'
       AND u.role NOT IN ('manager','admin','superadmin','Admin','Manager','Super Admin')
     GROUP BY u.id, u.name
     ORDER BY total_activity DESC LIMIT 1",
    [$station_id,$date_from,$date_to,$station_id,$date_from,$date_to,$station_id]);
$top_staff = $top_staff[0] ?? null;

$display_name = htmlspecialchars(
    $me['full_name'] ?? trim(($me['first_name']??'').' '.($me['last_name']??'')) ?: ($me['name'] ?? 'Admin')
);

require_once __DIR__ . '/../partials/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root{--adm-blue:#00264D;--adm-red:#CC0000;--adm-green:#28A745;--adm-orange:#FFC107;--adm-purple:#6f42c1;--adm-gray:#6c757d;}
.adm-page{max-width:100%;box-sizing:border-box;overflow-x:hidden;}
.adm-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.adm-head h1{margin:0;font-size:1.4rem;color:var(--adm-blue);display:flex;align-items:center;gap:8px;}
.adm-subtitle{font-size:12px;color:var(--adm-gray);margin:3px 0 0;}
.adm-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.adm-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all .2s;}
.adm-btn-success{background:#28A745;color:#fff;}.adm-btn-success:hover{background:#218838;}
.adm-btn-danger{background:#CC0000;color:#fff;}.adm-btn-danger:hover{background:#a00000;}
.adm-btn-primary{background:#00264D;color:#fff;}.adm-btn-primary:hover{background:#001a38;}
.adm-btn-outline{background:#fff;color:#00264D;border:1px solid #00264D;}.adm-btn-outline:hover{background:#e8f0fe;}
.adm-btn-sm{padding:5px 10px;font-size:11px;}
.filter-bar{background:#fff;border-radius:10px;border:1px solid #e9ecef;padding:12px 16px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;}
.filter-bar .fg{display:flex;flex-direction:column;gap:3px;}
.filter-bar label{font-size:10px;font-weight:700;color:var(--adm-gray);text-transform:uppercase;letter-spacing:.4px;}
.filter-bar input{padding:6px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:12px;min-width:130px;}
.filter-bar input:focus{border-color:#00264D;outline:none;box-shadow:0 0 0 3px rgba(0,38,77,.1);}
.qf-wrap{display:flex;gap:5px;flex-wrap:wrap;}
.qf-btn{padding:5px 12px;border-radius:20px;border:1px solid #dee2e6;background:#fff;font-size:11px;font-weight:600;cursor:pointer;color:var(--adm-gray);transition:all .2s;}
.qf-btn.active,.qf-btn:hover{background:#00264D;color:#fff;border-color:#00264D;}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(195px,1fr));gap:12px;margin-bottom:20px;}
.kpi-card{background:#fff;border-radius:12px;border:1px solid #EAEAEA;border-top:4px solid #EAEAEA;padding:16px 14px 12px;display:flex;flex-direction:column;gap:8px;box-shadow:0 1px 5px rgba(0,0,0,.05);transition:transform .15s,box-shadow .15s;}
.kpi-card:hover{box-shadow:0 5px 18px rgba(0,0,0,.09);transform:translateY(-2px);}
.kpi-card.c-blue{border-top-color:#00264D;}.kpi-card.c-green{border-top-color:#28A745;}.kpi-card.c-orange{border-top-color:#FFC107;}.kpi-card.c-red{border-top-color:#CC0000;}.kpi-card.c-purple{border-top-color:#6f42c1;}.kpi-card.c-teal{border-top-color:#17a2b8;}
.kpi-top{display:flex;align-items:center;justify-content:space-between;}
.kpi-ico{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.kpi-ico.c-blue{background:rgba(0,38,77,.1);color:#00264D;}.kpi-ico.c-green{background:rgba(40,167,69,.1);color:#28A745;}.kpi-ico.c-orange{background:rgba(255,193,7,.15);color:#b8860b;}.kpi-ico.c-red{background:rgba(204,0,0,.1);color:#CC0000;}.kpi-ico.c-purple{background:rgba(111,66,193,.1);color:#6f42c1;}.kpi-ico.c-teal{background:rgba(23,162,184,.1);color:#17a2b8;}
.kpi-num{font-size:24px;font-weight:800;color:#101828;line-height:1;letter-spacing:-.5px;}
.kpi-lbl{font-size:11px;font-weight:700;color:var(--adm-gray);text-transform:uppercase;letter-spacing:.4px;}
.kpi-sub{font-size:11px;color:var(--adm-gray);line-height:1.4;}
.adm-card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:18px;overflow:hidden;}
.adm-card-hd{padding:12px 18px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.adm-card-title{font-size:13px;font-weight:700;color:#00264D;display:flex;align-items:center;gap:7px;}
.adm-card-bd{padding:18px;}
.charts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(400px,1fr));gap:18px;margin-bottom:18px;}
.chart-wrap{position:relative;height:230px;}
.panels-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px;margin-bottom:18px;}
.alert-list{display:flex;flex-direction:column;gap:7px;}
.alert-item{display:flex;align-items:center;gap:9px;padding:9px 13px;border-radius:7px;font-size:12px;}
.alert-item.warning{background:#fff8e1;color:#856404;border-left:3px solid #FFC107;}
.alert-item.danger{background:#fdf0f0;color:#721c24;border-left:3px solid #CC0000;}
.alert-item.success{background:#f0fff4;color:#155724;border-left:3px solid #28A745;}
.adm-table{width:100%;border-collapse:collapse;font-size:12px;}
.adm-table th{background:#f8f9fa;padding:8px 11px;text-align:left;font-size:10px;font-weight:700;color:var(--adm-gray);text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e9ecef;}
.adm-table td{padding:8px 11px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.adm-table tr:last-child td{border-bottom:none;}.adm-table tr:hover td{background:#fafafa;}
.badge{display:inline-flex;align-items:center;padding:2px 7px;border-radius:20px;font-size:10px;font-weight:600;}
.badge-success{background:#d4edda;color:#155724;}.badge-warning{background:#fff3cd;color:#856404;}.badge-danger{background:#f8d7da;color:#721c24;}.badge-info{background:#d1ecf1;color:#0c5460;}.badge-secondary{background:#e2e3e5;color:#383d41;}
.price-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f0f0f0;font-size:12px;}
.price-row:last-child{border-bottom:none;}.price-val{font-weight:700;color:#00264D;}
.user-snap{display:flex;gap:16px;justify-content:center;padding:8px 0;}
.user-snap-item{text-align:center;}.user-snap-num{font-size:28px;font-weight:800;line-height:1;}.user-snap-lbl{font-size:11px;color:var(--adm-gray);font-weight:600;text-transform:uppercase;}
.ql-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:9px;}
.ql-item{display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 8px;border-radius:9px;border:1px solid #e9ecef;background:#fff;text-decoration:none;color:#344054;font-size:11px;font-weight:600;text-align:center;transition:all .2s;}
.ql-item:hover{background:#00264D;color:#fff;border-color:#00264D;}.ql-item i{font-size:18px;}
@media(max-width:768px){.charts-grid,.panels-grid{grid-template-columns:1fr;}.kpi-grid{grid-template-columns:repeat(2,1fr);}.adm-head{flex-direction:column;}}
</style>

<div class="dashboard-content adm-page">

<div class="adm-head">
  <div>
    <h1><i class="fas fa-tachometer-alt"></i> MY DASHBOARD</h1>
    <div class="sub" style="font-size:13px;opacity:.85;color:#6c757d;font-weight:bold;">WELCOME BACK, <?php echo $display_name; ?></div>
      </div>
  <div class="adm-actions">
    <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'excel'])); ?>" class="adm-btn adm-btn-success adm-btn-sm"><i class="fas fa-file-excel"></i> Export Excel</a>
    <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'pdf'])); ?>" class="adm-btn adm-btn-danger adm-btn-sm"><i class="fas fa-file-pdf"></i> Export PDF</a>
  </div>
</div>

<form method="GET" action="" class="filter-bar">
  <div class="fg">
    <label>Quick Filter</label>
    <div class="qf-wrap">
      <?php foreach(['today'=>'Today','week'=>'This Week','month'=>'This Month','last_month'=>'Last Month'] as $k=>$v): ?>
        <button type="submit" name="quick" value="<?php echo $k; ?>" class="qf-btn <?php echo ($quick===$k)?'active':''; ?>"><?php echo $v; ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="fg"><label>From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"></div>
  <div class="fg"><label>To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"></div>
  <div class="fg" style="justify-content:flex-end;"><button type="submit" name="quick" value="custom" class="adm-btn adm-btn-primary adm-btn-sm"><i class="fas fa-filter"></i> Apply</button></div>
</form>

<!-- KPI CARDS -->
<div class="kpi-grid">

  <div class="kpi-card c-blue">
    <div class="kpi-top">
      <div><div class="kpi-lbl">Total Fuel Sales</div><div class="kpi-num">&#8369;<?php echo number_format($fuel_revenue,0); ?></div></div>
      <div class="kpi-ico c-blue"><i class="fas fa-gas-pump"></i></div>
    </div>
    <div class="kpi-sub"><?php echo number_format($fuel_liters,2); ?> liters sold</div>
  </div>

  <div class="kpi-card c-teal">
    <div class="kpi-top">
      <div><div class="kpi-lbl">Merchandise Sales</div><div class="kpi-num">&#8369;<?php echo number_format($merch_revenue,0); ?></div></div>
      <div class="kpi-ico c-teal"><i class="fas fa-shopping-bag"></i></div>
    </div>
    <div class="kpi-sub">Cash &#8369;<?php echo number_format($merch_cash,0); ?> &bull; Credit &#8369;<?php echo number_format($merch_credit,0); ?></div>
  </div>

  <div class="kpi-card <?php echo $total_variance_alerts>0?'c-red':'c-green'; ?>">
    <div class="kpi-top">
      <div><div class="kpi-lbl">Variance Alerts</div><div class="kpi-num"><?php echo $total_variance_alerts; ?></div></div>
      <div class="kpi-ico <?php echo $total_variance_alerts>0?'c-red':'c-green'; ?>"><i class="fas fa-balance-scale"></i></div>
    </div>
    <div class="kpi-sub"><?php echo number_format($variance_liters,2); ?> liters discrepancy</div>
  </div>

  <div class="kpi-card c-orange">
    <div class="kpi-top">
      <div><div class="kpi-lbl">Deliveries</div><div class="kpi-num"><?php echo $del_total; ?></div></div>
      <div class="kpi-ico c-orange"><i class="fas fa-truck"></i></div>
    </div>
    <div class="kpi-sub">
      <span style="color:#FFC107;"><?php echo $del_pending; ?> pending</span> &bull;
      <span style="color:#28A745;"><?php echo $del_approved; ?> approved</span> &bull;
      <span style="color:#CC0000;"><?php echo $del_rejected; ?> rejected</span>
    </div>
  </div>

  <div class="kpi-card c-purple">
    <div class="kpi-top">
      <div><div class="kpi-lbl">Staff Accounts</div><div class="kpi-num"><?php echo $staff_active; ?></div></div>
      <div class="kpi-ico c-purple"><i class="fas fa-users"></i></div>
    </div>
    <div class="kpi-sub"><?php echo $staff_inactive; ?> inactive &bull; <?php echo $mgr_count; ?> manager(s)</div>
  </div>

  <div class="kpi-card <?php echo count($compliance_alerts)>0?'c-red':'c-green'; ?>">
    <div class="kpi-top">
      <div><div class="kpi-lbl">Compliance Alerts</div><div class="kpi-num"><?php echo count($compliance_alerts); ?></div></div>
      <div class="kpi-ico <?php echo count($compliance_alerts)>0?'c-red':'c-green'; ?>"><i class="fas fa-shield-alt"></i></div>
    </div>
    <div class="kpi-sub"><?php echo count($compliance_alerts)===0?'All clear':'Action required'; ?></div>
  </div>

</div>

<?php if (!empty($compliance_alerts)): ?>
<div class="adm-card" style="margin-bottom:18px;">
  <div class="adm-card-hd">
    <span class="adm-card-title"><i class="fas fa-exclamation-circle" style="color:#CC0000;"></i> Compliance Alerts</span>
    <a href="audit_logs.php" class="adm-btn adm-btn-outline adm-btn-sm"><i class="fas fa-external-link-alt"></i> Audit Logs</a>
  </div>
  <div class="adm-card-bd">
    <div class="alert-list">
      <?php foreach($compliance_alerts as $ca): ?>
        <div class="alert-item <?php echo htmlspecialchars($ca['type']); ?>"><i class="fas <?php echo htmlspecialchars($ca['icon']); ?>"></i><?php echo htmlspecialchars($ca['msg']); ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- CHARTS ROW 1 -->
<div class="charts-grid">
  <div class="adm-card">
    <div class="adm-card-hd"><span class="adm-card-title"><i class="fas fa-chart-line"></i> Sales Trend (Last 30 Days)</span></div>
    <div class="adm-card-bd"><div class="chart-wrap"><canvas id="chartSales"></canvas></div></div>
  </div>
  <div class="adm-card">
    <div class="adm-card-hd"><span class="adm-card-title"><i class="fas fa-truck-loading"></i> Deliveries Trend (Last 14 Days)</span></div>
    <div class="adm-card-bd"><div class="chart-wrap"><canvas id="chartDeliv"></canvas></div></div>
  </div>
</div>

<!-- CHARTS ROW 2 -->
<div class="charts-grid">
  <div class="adm-card">
    <div class="adm-card-hd"><span class="adm-card-title"><i class="fas fa-chart-pie"></i> Variance Distribution by Fuel Type</span></div>
    <div class="adm-card-bd"><div class="chart-wrap"><canvas id="chartVar"></canvas></div></div>
  </div>
  <div class="adm-card">
    <div class="adm-card-hd"><span class="adm-card-title"><i class="fas fa-chart-bar"></i> Staff Performance (Transactions + Job Orders)</span></div>
    <div class="adm-card-bd"><div class="chart-wrap"><canvas id="chartPerf"></canvas></div></div>
  </div>
</div>

<!-- OVERSIGHT PANELS -->
<div class="panels-grid">

  <div class="adm-card">
    <div class="adm-card-hd">
      <span class="adm-card-title"><i class="fas fa-user-cog"></i> User Management Snapshot</span>
      <a href="users.php" class="adm-btn adm-btn-outline adm-btn-sm"><i class="fas fa-arrow-right"></i> Manage</a>
    </div>
    <div class="adm-card-bd">
      <div class="user-snap">
        <div class="user-snap-item"><div class="user-snap-num" style="color:#28A745;"><?php echo $staff_active; ?></div><div class="user-snap-lbl">Active Staff</div></div>
        <div class="user-snap-item"><div class="user-snap-num" style="color:#CC0000;"><?php echo $staff_inactive; ?></div><div class="user-snap-lbl">Inactive</div></div>
        <div class="user-snap-item"><div class="user-snap-num" style="color:#6f42c1;"><?php echo $mgr_count; ?></div><div class="user-snap-lbl">Managers</div></div>
      </div>
      <?php if (!empty($users_all)): ?>
      <table class="adm-table" style="margin-top:10px;">
        <thead><tr><th>Role</th><th>Status</th><th>Count</th></tr></thead>
        <tbody>
          <?php foreach($users_all as $ua): ?>
          <tr>
            <td><?php echo htmlspecialchars(ucfirst($ua['role'])); ?></td>
            <td><span class="badge <?php echo $ua['status']==='active'?'badge-success':'badge-secondary'; ?>"><?php echo htmlspecialchars($ua['status']); ?></span></td>
            <td><strong><?php echo (int)$ua['cnt']; ?></strong></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="adm-card">
    <div class="adm-card-hd">
      <span class="adm-card-title"><i class="fas fa-tags"></i> Product &amp; Pricing Overview</span>
      <a href="admin_set_prices.php" class="adm-btn adm-btn-outline adm-btn-sm"><i class="fas fa-arrow-right"></i> View</a>
    </div>
    <div class="adm-card-bd">
      <?php if (!empty($fuel_prices)): ?>
        <div style="font-size:10px;font-weight:700;color:#6c757d;text-transform:uppercase;margin-bottom:6px;">Official Fuel Prices</div>
        <?php foreach($fuel_prices as $fp): ?>
          <div class="price-row">
            <span><?php echo htmlspecialchars($fp['fuel_type']); ?></span>
            <span class="price-val">&#8369;<?php echo number_format((float)$fp['price_per_liter'],2); ?>/L</span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!empty($merch_catalog)): ?>
        <div style="font-size:10px;font-weight:700;color:#6c757d;text-transform:uppercase;margin:10px 0 6px;">Merchandise Catalog</div>
        <?php foreach($merch_catalog as $mc): ?>
          <div class="price-row">
            <span><?php echo htmlspecialchars($mc['product_name']); ?></span>
            <span class="price-val">&#8369;<?php echo number_format((float)$mc['price'],2); ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (empty($fuel_prices) && empty($merch_catalog)): ?>
        <p style="color:#6c757d;font-size:12px;text-align:center;padding:16px 0;">No pricing data available.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="adm-card">
    <div class="adm-card-hd"><span class="adm-card-title"><i class="fas fa-file-alt"></i> Reports &amp; Audit Quick Links</span></div>
    <div class="adm-card-bd">
      <div class="ql-grid">
        <a href="admin_reports_audit.php?tab=sales" class="ql-item"><i class="fas fa-chart-line"></i>Sales Report</a>
        <a href="reports.php?section=deliveries" class="ql-item"><i class="fas fa-truck"></i>Deliveries</a>
        <a href="reports.php?section=staff" class="ql-item"><i class="fas fa-users"></i>Staff Report</a>
        <a href="admin_reports_audit.php?tab=audit" class="ql-item"><i class="fas fa-history"></i>Audit Logs</a>
        <a href="admin_anomaly_monitoring.php" class="ql-item"><i class="fas fa-exclamation-triangle"></i>Anomalies</a>
        <a href="admin_deliveries_oversight.php" class="ql-item"><i class="fas fa-truck-loading"></i>Deliveries</a>
        <a href="admin_staff_oversight.php" class="ql-item"><i class="fas fa-users-cog"></i>Staff Oversight</a>
        <a href="admin_export_center.php" class="ql-item"><i class="fas fa-download"></i>Export Center</a>
      </div>
    </div>
  </div>

</div>

<!-- RECENT AUDIT TRAIL -->
<div class="adm-card">
  <div class="adm-card-hd">
    <span class="adm-card-title"><i class="fas fa-shield-alt"></i> Recent Audit Trail</span>
    <a href="audit_logs.php" class="adm-btn adm-btn-outline adm-btn-sm"><i class="fas fa-external-link-alt"></i> Full Log</a>
  </div>
  <div class="adm-card-bd" style="padding:0;">
    <?php if (!empty($audit_rows)): ?>
    <table class="adm-table">
      <thead><tr><th>Action</th><th>Details</th><th>User</th><th>Role</th><th>Status</th><th>Time</th></tr></thead>
      <tbody>
        <?php foreach($audit_rows as $ar): ?>
        <tr>
          <td><?php echo htmlspecialchars($ar['action_type']??'—'); ?></td>
          <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($ar['action_details']??'—'); ?></td>
          <td><?php echo htmlspecialchars($ar['user_name']??'System'); ?></td>
          <td><?php echo htmlspecialchars(ucfirst($ar['user_role']??'')); ?></td>
          <td><?php $as=strtolower($ar['status']??''); $ab=match($as){'success','approved','completed'=>'badge-success','failed','rejected','error'=>'badge-danger','pending'=>'badge-warning',default=>'badge-secondary'}; ?><span class="badge <?php echo $ab; ?>"><?php echo htmlspecialchars(ucfirst($ar['status']??'N/A')); ?></span></td>
          <td style="white-space:nowrap;color:#6c757d;"><?php echo date('M j, g:i a', strtotime($ar['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p style="padding:18px;color:#6c757d;text-align:center;font-size:12px;">No audit records found.</p>
    <?php endif; ?>
  </div>
</div>

</div><!-- /page-content adm-page -->

<script>
(function(){
  const B='#00264D',R='#CC0000',G='#28A745',O='#FFC107',P='#6f42c1',T='#17a2b8';
  const opts={responsive:true,maintainAspectRatio:false};

  const c1=document.getElementById('chartSales');
  if(c1) new Chart(c1,{type:'line',data:{labels:<?php echo json_encode($trend_labels);?>,datasets:[{label:'Fuel',data:<?php echo json_encode($trend_fuel);?>,borderColor:B,backgroundColor:'rgba(0,38,77,.07)',fill:true,tension:.4,pointRadius:2,borderWidth:2},{label:'Merchandise',data:<?php echo json_encode($trend_merch);?>,borderColor:R,backgroundColor:'rgba(204,0,0,.05)',fill:true,tension:.4,pointRadius:2,borderWidth:2}]},options:{...opts,plugins:{legend:{position:'top',labels:{font:{size:11}}}},scales:{x:{ticks:{font:{size:10},maxTicksLimit:10}},y:{ticks:{font:{size:10},callback:v=>'₱'+v.toLocaleString()}}}}});

  const c2=document.getElementById('chartDeliv');
  if(c2) new Chart(c2,{type:'bar',data:{labels:<?php echo json_encode($del_labels);?>,datasets:[{label:'Deliveries',data:<?php echo json_encode($del_counts);?>,backgroundColor:O,borderRadius:4}]},options:{...opts,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:10}}},y:{ticks:{font:{size:10},stepSize:1}}}}});

  const c3=document.getElementById('chartVar');
  if(c3) new Chart(c3,{type:'doughnut',data:{labels:<?php echo json_encode($var_labels);?>,datasets:[{data:<?php echo json_encode($var_amounts);?>,backgroundColor:[R,O,B,G,P,T],borderWidth:2,borderColor:'#fff'}]},options:{...opts,plugins:{legend:{position:'right',labels:{font:{size:11},boxWidth:12}}}}});

  const c4=document.getElementById('chartPerf');
  if(c4) new Chart(c4,{type:'bar',data:{labels:<?php echo json_encode($perf_labels);?>,datasets:[{label:'Transactions',data:<?php echo json_encode($perf_txn);?>,backgroundColor:B,borderRadius:4},{label:'Job Orders',data:<?php echo json_encode($perf_jo);?>,backgroundColor:G,borderRadius:4}]},options:{...opts,plugins:{legend:{position:'top',labels:{font:{size:11}}}},scales:{x:{ticks:{font:{size:10}}},y:{ticks:{font:{size:10},stepSize:1}}}}});
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
