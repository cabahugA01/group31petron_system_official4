<?php
/**
 * Manager Dashboard - Rebuilt with Correct Data Fetching Flow
 * 
 * Top Layer: Summary Cards (Quick KPIs)
 * Middle Layer: Comparative Shift Reports & Validation
 * Operational Tables: Shift Compare, Deliveries, Inventory (Fuel & Merchandise), Job Orders
 * Planning & Oversight: Calendar, Financial Snapshot, Suppliers List
 */

if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'manager_dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = (int)user_station_id();
$role = role_key($me['role'] ?? '');

// Access control
if (!in_array($role, ['manager', 'supervisor'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php'); exit;
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

$display_name = htmlspecialchars($me['full_name'] ?? trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? 'Manager'));

// ============================================================
// FUNCTION: Fetch All Dashboard Data
// ============================================================
function fetchDashboardData($pdo, $station_id) {
    $data = [];
    
    // 1. Fuel Stock (Liters) → fuel_inventory after validation
    $fuel_stock_stmt = $pdo->prepare("SELECT 
        COALESCE(ft.name, fi.fuel_type) AS fuel_type,
        COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
        COALESCE(fi.capacity, 10000) AS capacity
    FROM fuel_inventory fi
    LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
    WHERE fi.station_id = ?
    ORDER BY fuel_type");
    $fuel_stock_stmt->execute([$station_id]);
    $data['fuel_stock'] = $fuel_stock_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Low Stock Alerts
    $low_stock_stmt = $pdo->prepare("SELECT 
        ip.product_name,
        si.stock_level,
        si.reorder_level,
        'Merchandise' AS item_type
    FROM station_inventory si
    JOIN inventory_products ip ON si.product_id = ip.id
    WHERE si.station_id = ? AND si.status = 'active' AND si.stock_level <= si.reorder_level
    UNION
    SELECT 
        COALESCE(ft.name, fi.fuel_type) AS product_name,
        COALESCE(fi.current_level, fi.current_stock, 0) AS stock_level,
        2000 AS reorder_level,
        'Fuel' AS item_type
    FROM fuel_inventory fi
    LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
    WHERE fi.station_id = ? AND COALESCE(fi.current_level, fi.current_stock, 0) <= 2000
    ORDER BY stock_level ASC");
    $low_stock_stmt->execute([$station_id, $station_id]);
    $data['low_stock_alerts'] = $low_stock_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $data;
}

// INITIAL PHP DATA LOAD
$dashboard_data = fetchDashboardData($pdo, $station_id);

$today = date('Y-m-d');
$month_start = date('Y-m-01');

// Shift KPIs for today
$shift_kpis = [];
foreach ([1 => ['06:00:00','14:00:00'], 2 => ['14:00:00','23:59:59']] as $snum => $stime) {
    $ds = "$today {$stime[0]}"; $de = "$today {$stime[1]}";
    try {
        $q = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS fuel_rev, COALESCE(SUM(liters_sold),0) AS liters FROM fuel_transactions WHERE station_id=? AND transaction_date BETWEEN ? AND ?");
        $q->execute([$station_id,$ds,$de]); $f=$q->fetch(PDO::FETCH_ASSOC);
        
        $q2 = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS merch_rev, COALESCE(SUM(quantity),0) AS cnt FROM merchandise_transactions WHERE station_id=? AND created_at BETWEEN ? AND ?");
        $q2->execute([$station_id,$ds,$de]); $m=$q2->fetch(PDO::FETCH_ASSOC);
        
        $q3 = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) AS svc_rev, COUNT(*) AS jo_cnt FROM job_orders WHERE station_id=? AND created_at BETWEEN ? AND ?");
        $q3->execute([$station_id,$ds,$de]); $s=$q3->fetch(PDO::FETCH_ASSOC);
        
        $q4 = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE station_id=? AND created_at BETWEEN ? AND ?");
        $q4->execute([$station_id,$ds,$de]);
        $new_cust = (int)$q4->fetchColumn();
        
        $q5=$pdo->prepare("SELECT 
            COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%cash%' THEN total_amount ELSE 0 END),0) AS cash, 
            COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%card%' AND LOWER(payment_method) NOT LIKE '%fleet%' AND LOWER(payment_method) NOT LIKE '%fuel%' THEN total_amount ELSE 0 END),0) AS card, 
            COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%wallet%' OR LOWER(payment_method) LIKE '%gcash%' OR LOWER(payment_method) LIKE '%maya%' THEN total_amount ELSE 0 END),0) AS ewallet, 
            COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%fleet%' OR LOWER(payment_method) LIKE '%credit%' OR LOWER(payment_method) LIKE '%internal%' THEN total_amount ELSE 0 END),0) AS fleet, 
            COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%efuel%' OR LOWER(payment_method) LIKE '%fuel card%' THEN total_amount ELSE 0 END),0) AS efuel 
            FROM fuel_transactions WHERE station_id=? AND transaction_date BETWEEN ? AND ?");
        $q5->execute([$station_id,$ds,$de]); $pay=$q5->fetch(PDO::FETCH_ASSOC);
        
        $shift_kpis[$snum]=[
            'fuel_rev'=>(float)$f['fuel_rev'],
            'liters'=>(float)$f['liters'],
            'merch_rev'=>(float)$m['merch_rev'],
            'merch_cnt'=>(int)$m['cnt'],
            'svc_rev'=>(float)$s['svc_rev'],
            'jo_cnt'=>(int)$s['jo_cnt'],
            'new_cust'=>$new_cust,
            'total_pay'=>(float)$f['fuel_rev']+(float)$m['merch_rev']+(float)$s['svc_rev'],
            'cash'=>(float)($pay['cash']??0),
            'card'=>(float)($pay['card']??0),
            'ewallet'=>(float)($pay['ewallet']??0),
            'fleet'=>(float)($pay['fleet']??0),
            'efuel'=>(float)($pay['efuel']??0)
        ];
    } catch(Exception $e) { 
        $shift_kpis[$snum]=[
            'fuel_rev'=>0,'liters'=>0,'merch_rev'=>0,'merch_cnt'=>0,'svc_rev'=>0,'jo_cnt'=>0,'new_cust'=>0,
            'total_pay'=>0,'cash'=>0,'card'=>0,'ewallet'=>0,'fleet'=>0,'efuel'=>0
        ]; 
    }
}

// Activity logs — from activity_logs (lib-level) + audit_logs (API-level)
$activity_logs = [];
try {
    // Source A: activity_logs
    $q = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                         u.username,'Unknown')                             AS staff_name,
               COALESCE(al.action,'—')                                     AS action_type,
               al.created_at                                               AS ts,
               COALESCE(al.details,'—')                                    AS module_affected
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE u.station_id = ?
          AND DATE(al.created_at) = ?
        ORDER BY al.created_at DESC LIMIT 8
    ");
    $q->execute([$station_id, $today]);
    $activity_logs = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Exception $e) { $activity_logs=[]; }

// Source B: audit_logs (merge if activity_logs is empty)
if (empty($activity_logs)) {
    try {
        $q2 = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                             u.username,'Unknown')                         AS staff_name,
                   al.action_type                                          AS action_type,
                   al.created_at                                           AS ts,
                   UPPER(COALESCE(al.log_type,'SYSTEM'))                   AS module_affected
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE u.station_id = ?
              AND DATE(al.created_at) = ?
            ORDER BY al.created_at DESC LIMIT 8
        ");
        $q2->execute([$station_id, $today]);
        $activity_logs = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Exception $e) { $activity_logs=[]; }
}

// Staff performance
$staff_perf = [];
try {
    $q = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS staff_name, COUNT(*) AS txn_count, SUM(COALESCE(total_amount,0)) AS total_encoded FROM fuel_transactions ft JOIN users u ON ft.user_id=u.id WHERE ft.station_id=? AND DATE(ft.transaction_date) BETWEEN ? AND ? GROUP BY u.id,u.first_name,u.last_name ORDER BY txn_count DESC LIMIT 8");
    $q->execute([$station_id,$month_start,$today]); $staff_perf=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Exception $e) { $staff_perf=[]; }

// Calendar tasks
$cal_tasks = [];
try {
    $q = $pdo->prepare("SELECT 'Job Order' AS task_type, COALESCE(job_order_id,CONCAT('JO-',id)) AS ref, COALESCE(customer_name,'—') AS assigned_to, created_at AS scheduled_date, COALESCE(status,'Pending') AS status, COALESCE(service_type,'—') AS description FROM job_orders WHERE station_id=? AND DATE(created_at) = ? ORDER BY created_at DESC LIMIT 10");
    $q->execute([$station_id,$today]); $jo=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
    
    $q2 = $pdo->prepare("SELECT 'Delivery' AS task_type, COALESCE(batch_id,CONCAT('FD-',id)) AS ref, COALESCE(supplier,'—') AS assigned_to, COALESCE(delivery_date,created_at) AS scheduled_date, COALESCE(status,'Pending') AS status, COALESCE(fuel_type,'—') AS description FROM fuel_deliveries WHERE station_id=? AND DATE(COALESCE(delivery_date,created_at)) = ? ORDER BY scheduled_date LIMIT 10");
    $q2->execute([$station_id,$today]); $fd=$q2->fetchAll(PDO::FETCH_ASSOC)?:[];
    
    $cal_tasks = array_merge($jo,$fd);
    usort($cal_tasks, fn($a,$b)=>strtotime($a['scheduled_date'])<=>strtotime($b['scheduled_date']));
} catch(Exception $e) { $cal_tasks=[]; }

// Financial snapshot this month (fuel + merch + job orders)
$fin_snap=['total_collected'=>0,'total_payable'=>0,'variance'=>0];
try {
    $qa=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?");
    $qa->execute([$station_id,$month_start,$today]); $fin_snap['total_collected']=(float)$qa->fetchColumn();
    
    $qb=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?");
    $qb->execute([$station_id,$month_start,$today]); $fin_snap['total_collected']+=(float)$qb->fetchColumn();
    
    $qc=$pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM job_orders WHERE station_id=? AND status='Completed' AND DATE(COALESCE(completed_at,created_at)) BETWEEN ? AND ?");
    $qc->execute([$station_id,$month_start,$today]); $fin_snap['total_collected']+=(float)$qc->fetchColumn();
    
    $qd=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE station_id=? AND status NOT IN ('Received','Cancelled','Rejected by Admin','Admin Finalized') AND DATE(created_at) BETWEEN ? AND ?");
    $qd->execute([$station_id,$month_start,$today]); $fin_snap['total_payable']=(float)$qd->fetchColumn();
    
    $fin_snap['variance']=$fin_snap['total_collected']-$fin_snap['total_payable'];
} catch(Exception $e) {}

// Suppliers - last 30 days of deliveries and POs
$suppliers_data=[];
try {
    $q=$pdo->prepare("
        SELECT 
            supplier,
            SUM(CASE WHEN po_id IS NOT NULL THEN 1 ELSE 0 END) AS total_deliveries,
            SUM(delivery_liters) AS total_liters,
            SUM(total_amount) AS total_amount,
            SUM(outstanding_balance) AS outstanding_balance,
            MAX(last_date) AS last_delivery
        FROM (
            SELECT 
                COALESCE(s.name, po.supplier_name, 'Unknown') AS supplier,
                po.id AS po_id,
                0 AS delivery_liters,
                COALESCE(po.total_amount, 0) AS total_amount,
                COALESCE(CASE WHEN po.status NOT IN ('Received','Cancelled','Rejected by Admin','Admin Finalized') THEN po.total_amount ELSE 0 END, 0) AS outstanding_balance,
                po.created_at AS last_date
            FROM purchase_orders po
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.station_id = ?
            
            UNION ALL
            
            SELECT 
                supplier AS supplier,
                NULL AS po_id,
                delivery_liters AS delivery_liters,
                0 AS total_amount,
                0 AS outstanding_balance,
                delivery_date AS last_date
            FROM fuel_deliveries
            WHERE station_id = ?
        ) t
        GROUP BY supplier
        ORDER BY total_deliveries DESC
        LIMIT 10
    ");
    $q->execute([$station_id, $station_id]); $suppliers_data=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Exception $e) { $suppliers_data=[]; }

// Merchandise inventory (products table)
$merch_inv_summary=['total_products'=>0,'low_stock'=>0,'out_of_stock'=>0,'total_value'=>0];
$merch_low_stock=[];
try {
    $q=$pdo->prepare("SELECT COUNT(*) AS total_products, SUM(CASE WHEN current_stock>0 AND current_stock<=min_stock_level THEN 1 ELSE 0 END) AS low_stock, SUM(CASE WHEN current_stock<=0 THEN 1 ELSE 0 END) AS out_of_stock, COALESCE(SUM(current_stock*price),0) AS total_value FROM products WHERE station_id=?");
    $q->execute([$station_id]); $merch_inv_summary=$q->fetch(PDO::FETCH_ASSOC)?:$merch_inv_summary;
    
    $q2=$pdo->prepare("SELECT name AS product_name, current_stock, min_stock_level AS reorder_level, status FROM products WHERE station_id=? AND current_stock<=min_stock_level ORDER BY current_stock ASC LIMIT 10");
    $q2->execute([$station_id]); $merch_low_stock=$q2->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Exception $e) {}

// Validation errors today
$validation_errors = [];
try {
    $q = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS staff_name, action AS action_type, remarks AS action_details, created_at FROM validation_actions_log WHERE station_id=? AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY created_at DESC LIMIT 10");
    $q->execute([$station_id]); $validation_errors=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Exception $e) { $validation_errors = []; }

// Customers outstanding balance
$customer_balances = ['new_customers' => 0, 'outstanding_balance' => 0];
try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE station_id = ? AND DATE(created_at) = ?");
    $q->execute([$station_id, $today]);
    $customer_balances['new_customers'] = (int)$q->fetchColumn();
    
    $q2 = $pdo->prepare("SELECT COALESCE(SUM(balance_due), 0) FROM customers WHERE station_id = ?");
    $q2->execute([$station_id]);
    $customer_balances['outstanding_balance'] = (float)$q2->fetchColumn();
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Manager Dashboard Styles ─────────────────────────────── */
.mgr-section-title {
    font-size: 14px;
    font-weight: 700;
    color: #00264D;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 8px 0 10px;
    border-bottom: 2px solid #e2e8f0;
    margin: 28px 0 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
/* KPI Cards */
.mgr-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.mgr-stat-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 6px rgba(0,0,0,.05);
    transition: transform .2s, box-shadow .2s;
    min-height: 110px;
}
.mgr-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 14px rgba(0,0,0,.1);
}
.mgr-stat-card .details .label {
    font-size: 11px;
    color: #64748b;
    margin: 0 0 5px;
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 600;
}
.mgr-stat-card .details .value {
    font-size: 22px;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}
.mgr-stat-card .details small {
    font-size: 11px;
    color: #64748b;
    margin-top: 4px;
    display: block;
}
.mgr-stat-card .icon {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: rgba(0,38,77,.08);
    color: #00264D;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    margin-left: 14px;
}
/* Panels */
.mgr-grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(440px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}
.mgr-panel {
    background: white;
    border-radius: 10px;
    padding: 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 6px rgba(0,0,0,.05);
}
.mgr-panel-title {
    font-size: 13px;
    font-weight: 700;
    color: #00264D;
    text-transform: uppercase;
    letter-spacing: .3px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 10px;
}
.mgr-panel-title span {
    font-size: 11px;
    font-weight: 500;
    text-transform: none;
    color: #64748b;
}
/* Tables */
.mgr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.mgr-table thead tr {
    border-top: 2px solid #00264D;
    border-bottom: 1px solid #e2e8f0;
    background: #f8f9fa;
}
.mgr-table thead th {
    padding: 9px 8px;
    text-align: left;
    font-weight: 700;
    color: #00264D;
    font-size: 11px;
    text-transform: uppercase;
}
.mgr-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
}
.mgr-table tbody tr:hover {
    background: #f8fafc;
}
.mgr-table tbody td {
    padding: 9px 8px;
    color: #334155;
}
.mgr-table tfoot tr {
    border-top: 2px solid #00264D;
    background: #f0f4ff;
}
.mgr-table tfoot td {
    padding: 9px 8px;
    font-weight: 700;
    color: #00264D;
    font-size: 12px;
}
.mgr-empty {
    text-align: center;
    padding: 24px;
    color: #94a3b8;
    font-size: 12px;
}
/* Badges */
.mgr-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
}
.b-pending { background: #fef9c3; color: #854d0e; }
.b-progress { background: #dbeafe; color: #1d4ed8; }
.b-done { background: #dcfce7; color: #15803d; }
.b-cancel { background: #fee2e2; color: #dc2626; }
.b-verified { background: #dcfce7; color: #15803d; }
.b-rejected { background: #fee2e2; color: #dc2626; }
.b-adjusted { background: #e0f2fe; color: #0369a1; }
.b-delivery { background: #f0fdf4; color: #15803d; }
.b-jo { background: #dbeafe; color: #1d4ed8; }

/* Validation buttons */
.val-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all .15s;
}
.val-btn-approve {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #86efac;
}
.val-btn-approve:hover {
    background: #16a34a;
    color: white;
}
.val-btn-reject {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fca5a5;
}
.val-btn-reject:hover {
    background: #dc2626;
    color: white;
}
/* Shift compare */
.shift-compare {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.shift-box {
    border-radius: 8px;
    padding: 16px;
    border: 1px solid #e2e8f0;
    background: #f8f9fa;
}
.shift-box h4 {
    font-size: 12px;
    font-weight: 700;
    color: #00264D;
    text-transform: uppercase;
    margin: 0 0 12px;
}
.shift-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    border-bottom: 1px solid #e2e8f0;
    font-size: 12px;
}
.shift-row:last-child {
    border-bottom: none;
    font-weight: 700;
    padding-top: 8px;
}
.shift-row .sk { color: #64748b; }
.shift-row .sv { font-weight: 600; color: #1e293b; }
/* Financial */
.fin-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #f1f5f9;
}
.fin-row:last-child {
    border-bottom: none;
    border-top: 2px solid #e2e8f0;
    padding-top: 12px;
    margin-top: 4px;
}
.fin-row .fk {
    font-size: 12px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 6px;
}
.fin-row .fv {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
}
/* Chart containers */
.mgr-chart-wrap {
    margin-top: 14px;
    position: relative;
    height: 180px;
}
/* Flash */
.mgr-flash {
    padding: 10px 14px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 13px;
    font-weight: 500;
}
.mgr-flash.success {
    background: #dcfce7;
    color: #15803d;
    border-left: 4px solid #22c55e;
}
.mgr-flash.error {
    background: #fee2e2;
    color: #dc2626;
    border-left: 4px solid #dc2626;
}
</style>

<?php
$flash_s = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_e = $_SESSION['error']   ?? null; unset($_SESSION['error']);
?>

<!-- Page Header -->
<div style="margin-bottom:20px;">
    <h1 style="margin:0 0 5px;font-size:26px;font-weight:700;color:#003366;"><i class="fas fa-chart-line"></i> Manager Dashboard</h1>
    <p style="margin:0 0-8px;font-size:18px;color:#555;">Welcome, <?= htmlspecialchars($me['first_name'] ?? $display_name) ?>!</p>
    <p style="margin:0;font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.3px;">Real-time Operational Insights &amp; Performance Metrics</p>
</div>

<?php if($flash_s): ?><div class="mgr-flash success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($flash_s) ?></div><?php endif; ?>
<?php if($flash_e): ?><div class="mgr-flash error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($flash_e) ?></div><?php endif; ?>

<!-- ══════════════════════════════════════════════
     TOP: SUMMARY CARDS — Today's KPIs
     ══════════════════════════════════════════════ -->
<div class="mgr-section-title"><i class="fas fa-chart-line"></i> Summary Cards — Today's KPIs</div>
<div class="mgr-cards-grid">
<?php
$fuel_total  = array_sum(array_column($shift_kpis,'fuel_rev'));
$fuel_liters = array_sum(array_column($shift_kpis,'liters'));
$merch_total = array_sum(array_column($shift_kpis,'merch_rev'));
$merch_cnt   = array_sum(array_column($shift_kpis,'merch_cnt'));
$svc_total   = array_sum(array_column($shift_kpis,'svc_rev'));
$jo_total    = array_sum(array_column($shift_kpis,'jo_cnt'));
$pay_total   = array_sum(array_column($shift_kpis,'total_pay'));
$cust_total  = $customer_balances['new_customers'];
$cust_bal    = $customer_balances['outstanding_balance'];

// Payments breakdown
$pay_breakdown = [];
try {
    $q = $pdo->prepare("SELECT 
        CASE 
            WHEN LOWER(COALESCE(payment_method,'')) LIKE '%fleet%' THEN 'Fleet' 
            WHEN LOWER(COALESCE(payment_method,'')) LIKE '%fuel card%' OR LOWER(COALESCE(payment_method,'')) LIKE '%efuel%' THEN 'E-Fuel' 
            WHEN LOWER(COALESCE(payment_method,'')) LIKE '%card%' THEN 'Card' 
            WHEN LOWER(COALESCE(payment_method,'')) LIKE '%wallet%' OR LOWER(COALESCE(payment_method,'')) LIKE '%gcash%' OR LOWER(COALESCE(payment_method,'')) LIKE '%maya%' THEN 'E-Wallet' 
            ELSE 'Cash' 
        END AS mode, 
        SUM(COALESCE(total_amount,0)) AS amt 
        FROM fuel_transactions 
        WHERE station_id=? AND DATE(transaction_date)=? 
        GROUP BY mode 
        ORDER BY amt DESC");
    $q->execute([$station_id,$today]); $pay_breakdown=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Exception $e){}

$cards=[
    ['Fuel Sales','₱'.number_format($fuel_total,2),number_format($fuel_liters,2).' L sold today','fas fa-gas-pump'],
    ['Merchandise Sales','₱'.number_format($merch_total,2),$merch_cnt.' items sold today','fas fa-shopping-cart'],
    ['Service Income','₱'.number_format($svc_total,2),$jo_total.' JOs completed','fas fa-wrench'],
    ['Payments Collected','₱'.number_format($pay_total,2),(!empty($pay_breakdown)?$pay_breakdown[0]['mode'].': ₱'.number_format($pay_breakdown[0]['amt'],2):'All modes'),'fas fa-money-bill-wave'],
    ['Customers',number_format($cust_total).' registered','Bal: ₱'.number_format($cust_bal,2),'fas fa-users'],
];
foreach($cards as [$lbl,$val,$sub,$ico]):
?>
<div class="mgr-stat-card">
    <div class="details">
        <div class="label"><?=$lbl?></div>
        <div class="value"><?=$val?></div>
        <small><?=$sub?></small>
    </div>
    <div class="icon"><i class="<?=$ico?>"></i></div>
</div>
<?php endforeach; ?>
</div>

<!-- Payments breakdown sub-row -->
<?php if(!empty($pay_breakdown)): ?>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px;">
<?php foreach($pay_breakdown as $pb): ?>
<div style="background:white;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;font-size:12px;">
    <span style="color:#64748b;text-transform:uppercase;font-weight:600;"><?=htmlspecialchars($pb['mode'])?></span>
    <span style="font-weight:700;color:#00264D;margin-left:8px;">₱<?=number_format($pb['amt'],2)?></span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════
     MIDDLE: OPERATIONAL MONITORING + SHIFT COMPARE
     ══════════════════════════════════════════════ -->
<div class="mgr-section-title"><i class="fas fa-desktop"></i> Operational Monitoring &amp; Oversight</div>

<!-- Shift Reports & Consolidation -->
<div class="mgr-panel" style="margin-bottom:20px;">
    <div class="mgr-panel-title">
        <div><i class="fas fa-clock"></i> Shift Reports — Shift 1 vs Shift 2 (Today) &amp; Daily Consolidation</div>
    </div>
    <div class="shift-compare">
        <?php foreach([1=>'Shift 1 (6AM–2PM)',2=>'Shift 2 (2PM–12AM)'] as $snum=>$slabel):
            $sk=$shift_kpis[$snum]??['fuel_rev'=>0,'liters'=>0,'merch_rev'=>0,'merch_cnt'=>0,'svc_rev'=>0,'jo_cnt'=>0,'new_cust'=>0,'total_pay'=>0]; ?>
        <div class="shift-box">
            <h4><?=$slabel?></h4>
            <div class="shift-row"><span class="sk">Fuel Revenue</span><span class="sv">₱<?=number_format($sk['fuel_rev'],2)?> (<?=$sk['liters']?> L)</span></div>
            <div class="shift-row"><span class="sk">Merchandise Revenue</span><span class="sv">₱<?=number_format($sk['merch_rev'],2)?></span></div>
            <div class="shift-row"><span class="sk">Service Income</span><span class="sv">₱<?=number_format($sk['svc_rev'],2)?></span></div>
            <div class="shift-row"><span class="sk">Job Orders</span><span class="sv"><?=$sk['jo_cnt']?></span></div>
            <div class="shift-row"><span class="sk">New Customers</span><span class="sv"><?=$sk['new_cust']?></span></div>
            <div class="shift-row"><span class="sk">Total Payments</span><span class="sv">₱<?=number_format($sk['total_pay'],2)?></span></div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Daily Consolidation Totals Row -->
    <div style="margin-top:20px; background:#f0f4ff; border:1px solid #d0e0fc; border-radius:8px; padding:15px;">
        <h4 style="margin:0 0 10px; font-size:13px; color:#00264D; text-transform:uppercase;"><i class="fas fa-calculator"></i> Daily Consolidation (Grand Totals)</h4>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:12px; text-align:center;">
            <div>
                <span style="font-size:11px; color:#64748b; display:block;">Grand Total Fuel</span>
                <strong style="font-size:14px; color:#1e293b;">₱<?=number_format($fuel_total,2)?> (<?=number_format($fuel_liters,2)?> L)</strong>
            </div>
            <div>
                <span style="font-size:11px; color:#64748b; display:block;">Grand Total Merch</span>
                <strong style="font-size:14px; color:#1e293b;">₱<?=number_format($merch_total,2)?></strong>
            </div>
            <div>
                <span style="font-size:11px; color:#64748b; display:block;">Grand Total Service</span>
                <strong style="font-size:14px; color:#1e293b;">₱<?=number_format($svc_total,2)?></strong>
            </div>
            <div>
                <span style="font-size:11px; color:#64748b; display:block;">Grand Total Payments</span>
                <strong style="font-size:16px; color:#00264D;">₱<?=number_format($pay_total,2)?></strong>
            </div>
        </div>
    </div>
    
    <div class="mgr-chart-wrap"><canvas id="shiftCompareChart"></canvas></div>
</div>

<?php
// Pending fuel transactions for validation
$pending_fuel = [];
try {
    $q = $pdo->prepare("SELECT ft.id, COALESCE(ft.pump_id,'—') AS pump_id, ft.fuel_type, COALESCE(ft.liters_sold,0) AS liters, COALESCE(ft.total_amount,0) AS amount, ft.transaction_date, COALESCE(ft.status,'Pending') AS status, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS encoder FROM fuel_transactions ft LEFT JOIN users u ON ft.user_id=u.id WHERE ft.station_id=? AND LOWER(ft.status) LIKE '%pending%' ORDER BY ft.transaction_date DESC LIMIT 10");
    $q->execute([$station_id]); $pending_fuel=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Exception $e){}

// Recent merchandise transactions for correction monitoring
$pending_merch = [];
try {
    $q = $pdo->prepare("SELECT mt.id, COALESCE(mt.transaction_id,CONCAT('MT-',mt.id)) AS ref, COALESCE(mt.total_amount,0) AS amount, mt.created_at, COALESCE(mt.validation_status,'Official') AS status, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS encoder FROM merchandise_transactions mt LEFT JOIN users u ON mt.staff_id=u.id WHERE mt.station_id=? AND LOWER(COALESCE(mt.validation_status,'official')) <> 'voided' ORDER BY mt.created_at DESC LIMIT 10");
    $q->execute([$station_id]); $pending_merch=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Exception $e){}
?>

<div class="mgr-grid-2">
    <!-- Pending Fuel Transactions — Validation -->
    <div class="mgr-panel">
        <div class="mgr-panel-title">
            <div><i class="fas fa-gas-pump"></i> Fuel Transactions — Pending Validation (<?=count($pending_fuel)?>)</div>
            <span><a href="manager_fuel_transaction_validation.php" style="color:#00264D;text-decoration:none;font-size:11px;font-weight:600;">View All</a></span>
        </div>
        <?php if(empty($pending_fuel)): ?>
            <div class="mgr-empty"><i class="fas fa-check-circle" style="color:#22c55e;"></i> All fuel transactions validated.</div>
        <?php else: ?>
            <table class="mgr-table">
                <thead><tr><th>Pump</th><th>Fuel Type</th><th>Liters</th><th>Amount</th><th>Encoder</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach($pending_fuel as $r): ?>
                <tr>
                    <td><?=htmlspecialchars($r['pump_id'])?></td>
                    <td><?=htmlspecialchars($r['fuel_type'])?></td>
                    <td><?=number_format($r['liters'],2)?> L</td>
                    <td>₱<?=number_format($r['amount'],2)?></td>
                    <td><?=htmlspecialchars(trim($r['encoder']))?:'-'?></td>
                    <td style="white-space:nowrap;">
                        <form method="POST" action="manager_fuel_transaction_validation.php" style="display:inline;">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="transaction_id" value="<?=$r['id']?>">
                            <button type="submit" class="val-btn val-btn-approve" onclick="return confirm('Approve this transaction?')"><i class="fas fa-check"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Merchandise Transaction Monitoring -->
    <div class="mgr-panel">
        <div class="mgr-panel-title">
            <div><i class="fas fa-shopping-cart"></i> Merchandise — Transaction Monitoring (<?=count($pending_merch)?>)</div>
            <span><a href="manager_transaction_monitoring.php" style="color:#00264D;text-decoration:none;font-size:11px;font-weight:600;">View All</a></span>
        </div>
        <?php if(empty($pending_merch)): ?>
            <div class="mgr-empty"><i class="fas fa-check-circle" style="color:#22c55e;"></i> No merchandise transactions to monitor.</div>
        <?php else: ?>
            <table class="mgr-table">
                <thead><tr><th>Reference</th><th>Amount</th><th>Encoder</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach($pending_merch as $r): ?>
                <tr>
                    <td><?=htmlspecialchars($r['ref'])?></td>
                    <td>₱<?=number_format($r['amount'],2)?></td>
                    <td><?=htmlspecialchars(trim($r['encoder']))?:'-'?></td>
                    <td><?=htmlspecialchars($r['status'])?></td>
                    <td style="white-space:nowrap;">
                        <a class="val-btn val-btn-approve" href="manager_transaction_monitoring.php?search=<?=urlencode($r['ref'])?>" title="Adjust, void, or correct"><i class="fas fa-edit"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Fuel Inventory + Merchandise Low Stock Alerts -->
<div class="mgr-grid-2" style="margin-bottom:20px;">
    <!-- Fuel Inventory (Utilization & Variance) -->
    <div class="mgr-panel">
        <div class="mgr-panel-title">
            <div><i class="fas fa-gas-pump"></i> Fuel Inventory &amp; Variance</div>
        </div>
        <?php $fuel_tanks=$dashboard_data['fuel_stock']??[]; ?>
        <?php if(empty($fuel_tanks)): ?>
            <div class="mgr-empty">No fuel inventory data.</div>
        <?php else: ?>
            <table class="mgr-table">
                <thead><tr><th>Fuel Type</th><th>Current (L)</th><th>Capacity</th><th>Utilization</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach($fuel_tanks as $t):
                    $cap=max(1,(float)($t['capacity']??0));
                    $cur=(float)($t['current_stock']??$t['current_level']??0);
                    $pct=min(100,round($cur/$cap*100));
                    $col=$pct<25?'#dc2626':($pct<50?'#f59e0b':'#22c55e');
                    $label=$pct<25?'Critical':($pct<50?'Low':'OK');
                ?>
                <tr>
                    <td><?=htmlspecialchars($t['fuel_type']??$t['fuel_type_name']??'—')?></td>
                    <td><?=number_format($cur,0)?></td>
                    <td><?=number_format($cap,0)?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <div style="flex:1;background:#e2e8f0;height:6px;border-radius:3px;overflow:hidden;"><div style="background:<?=$col?>;height:100%;width:<?=$pct?>%;"></div></div>
                            <span style="font-size:10px;font-weight:700;color:<?=$col?>;"><?=$pct?>%</span>
                        </div>
                    </td>
                    <td><span class="mgr-badge" style="background:<?=$col?>22;color:<?=$col?>;"><?=$label?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mgr-chart-wrap"><canvas id="tankChart"></canvas></div>
        <?php endif; ?>
    </div>

    <!-- Merchandise Inventory & Alerts -->
    <div class="mgr-panel">
        <div class="mgr-panel-title">
            <div><i class="fas fa-boxes"></i> Merchandise Inventory &amp; Alerts</div>
        </div>
        
        <!-- Inventory Value & Stock Balances summary -->
        <div style="display:flex; gap:10px; margin-bottom:12px;">
            <div style="flex:1; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px; text-align:center;">
                <span style="font-size:10px; color:#64748b; display:block; text-transform:uppercase;">Total Value</span>
                <strong style="font-size:13px; color:#1e293b;">₱<?=number_format($merch_inv_summary['total_value'],2)?></strong>
            </div>
            <div style="flex:1; background:#fff9e6; border:1px solid #fde047; border-radius:6px; padding:10px; text-align:center;">
                <span style="font-size:10px; color:#854d0e; display:block; text-transform:uppercase;">Low Stock</span>
                <strong style="font-size:13px; color:#854d0e;"><?=$merch_inv_summary['low_stock']?> products</strong>
            </div>
            <div style="flex:1; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:10px; text-align:center;">
                <span style="font-size:10px; color:#991b1b; display:block; text-transform:uppercase;">Out of Stock</span>
                <strong style="font-size:13px; color:#991b1b;"><?=$merch_inv_summary['out_of_stock']?> products</strong>
            </div>
        </div>

        <?php $low_stock=$dashboard_data['low_stock_alerts']??[]; ?>
        <?php if(empty($low_stock)): ?>
            <div class="mgr-empty"><i class="fas fa-check-circle" style="color:#22c55e;"></i> Stock levels are healthy.</div>
        <?php else: ?>
            <table class="mgr-table">
                <thead><tr><th>Product</th><th>Current</th><th>Reorder</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach(array_slice($low_stock,0,5) as $item):
                    $pct=max(0,round(($item['stock_level']??0)/max(1,$item['reorder_level']??1)*100));
                    $bc=$pct<=25?'b-cancel':($pct<=50?'b-pending':'b-progress');
                ?>
                <tr>
                    <td><?=htmlspecialchars($item['product_name']??'—')?></td>
                    <td><?=number_format($item['stock_level']??0)?></td>
                    <td><?=number_format($item['reorder_level']??0)?></td>
                    <td><span class="mgr-badge <?=$bc?>"><?=$pct?>%</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mgr-chart-wrap"><canvas id="stockPieChart"></canvas></div>
        <?php endif; ?>
    </div>
</div>

<!-- Job Orders Today -->
<?php
$jo_rows=[];
try {
    $q=$pdo->prepare("SELECT jo.id,COALESCE(jo.job_order_id,CONCAT('JO-',jo.id)) AS ref,COALESCE(jo.service_type,'—') AS service_type,COALESCE(jo.customer_name,'—') AS customer,COALESCE(jo.status,'Pending') AS status,COALESCE(jo.total_cost,0) AS amount,TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS encoder,COALESCE(jo.validation_status,'') AS val_status FROM job_orders jo LEFT JOIN users u ON jo.created_by=u.id WHERE jo.station_id=? AND DATE(jo.created_at)=? ORDER BY FIELD(jo.status,'Pending','In Progress','Completed','Cancelled'),jo.created_at DESC LIMIT 20");
    $q->execute([$station_id,$today]); $jo_rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch(Exception $e){}
$jo_status_map=['Pending'=>'b-pending','In Progress'=>'b-progress','Completed'=>'b-done','Cancelled'=>'b-cancel'];
$jo_counts=['Pending'=>0,'In Progress'=>0,'Completed'=>0,'Cancelled'=>0];
foreach($jo_rows as $r) { $s=$r['status']; if(isset($jo_counts[$s])) $jo_counts[$s]++; }
$jo_total_amt=array_sum(array_column($jo_rows,'amount'));
?>
<div class="mgr-panel" style="margin-bottom:20px;">
    <div class="mgr-panel-title">
        <div><i class="fas fa-clipboard-list"></i> Job Orders — Today (<?=count($jo_rows)?>) &nbsp;
            <span style="font-size:10px;font-weight:500;text-transform:none;color:#64748b;">
                Pending:<?=$jo_counts['Pending']?> &bull; In Progress:<?=$jo_counts['In Progress']?> &bull; Done:<?=$jo_counts['Completed']?> &bull; Cancelled:<?=$jo_counts['Cancelled']?>
            </span>
        </div>
    </div>
    <?php if(empty($jo_rows)): ?>
        <div class="mgr-empty">No job orders for today.</div>
    <?php else: ?>
        <div style="overflow:hidden;">
        <table class="mgr-table">
            <thead><tr><th>Reference</th><th>Service</th><th>Customer</th><th>Status</th><th>Amount</th><th>Encoder</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach($jo_rows as $r):
                $is_pending = in_array(strtolower($r['status']),['pending','in-progress','in progress']) && strtolower($r['val_status'])!='approved';
            ?>
            <tr>
                <td><?=htmlspecialchars($r['ref'])?></td>
                <td><?=htmlspecialchars($r['service_type'])?></td>
                <td><?=htmlspecialchars($r['customer'])?></td>
                <td><span class="mgr-badge <?=$jo_status_map[$r['status']]??'b-pending'?>"><?=htmlspecialchars($r['status'])?></span></td>
                <td>₱<?=number_format($r['amount'],2)?></td>
                <td><?=htmlspecialchars(trim($r['encoder']))?:'-'?></td>
                <td style="white-space:nowrap;">
                    <a class="val-btn val-btn-approve" href="manager_transaction_monitoring.php?search=<?=urlencode($r['ref'])?>" title="Review transaction corrections"><i class="fas fa-edit"></i> Review</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="4">TOTAL</td><td>₱<?=number_format($jo_total_amt,2)?></td><td colspan="2"></td></tr></tfoot>
        </table>
        </div>
        <div class="mgr-chart-wrap" style="height:160px;"><canvas id="joTrendChart"></canvas></div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════
     CENTER: STAFF & PERFORMANCE
     ══════════════════════════════════════════════ -->
<div class="mgr-section-title"><i class="fas fa-users-cog"></i> Staff &amp; Performance</div>
<div class="mgr-grid-2">
    <!-- Activity Logs -->
    <div class="mgr-panel">
        <div class="mgr-panel-title"><div><i class="fas fa-history"></i> Activity Logs — Today</div></div>
        <?php if(empty($activity_logs)): ?>
            <div class="mgr-empty">No activity logs for today.</div>
        <?php else: ?>
            <table class="mgr-table">
                <thead><tr><th>Staff</th><th>Action</th><th>Module</th><th>Time</th></tr></thead>
                <tbody>
                <?php foreach($activity_logs as $r):
                    $action=strtolower($r['action_type']);
                    $bc=str_contains($action,'login')?'b-done':(str_contains($action,'delete')?'b-cancel':(str_contains($action,'edit')||str_contains($action,'update')?'b-pending':'b-progress'));
                ?>
                <tr>
                    <td><?=htmlspecialchars(trim($r['staff_name']))?:'-'?></td>
                    <td><span class="mgr-badge <?=$bc?>"><?=htmlspecialchars($r['action_type'])?></span></td>
                    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($r['module_affected'])?></td>
                    <td><?=$r['ts']?date('H:i',strtotime($r['ts'])):'—'?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4">Total: <?=count($activity_logs)?> records today &nbsp; <a href="approval_history.php" style="font-size:11px;color:#00264D;">View Full Audit →</a></td></tr></tfoot>
            </table>
        <?php endif; ?>
    </div>

    <!-- Audit Trail -->
    <div class="mgr-panel">
        <div class="mgr-panel-title"><div><i class="fas fa-shield-alt"></i> Audit Trail — Today</div></div>
        <?php if(empty($activity_logs)): ?>
            <div class="mgr-empty">No audit records for today.</div>
        <?php else: ?>
            <table class="mgr-table">
                <thead><tr><th>Staff</th><th>Action</th><th>Shift</th><th>Time</th></tr></thead>
                <tbody>
                <?php foreach($activity_logs as $r):
                    $t=$r['ts']?date('H:i:s',strtotime($r['ts'])):'00:00:00';
                    $shift=($t>='06:00:00'&&$t<'14:00:00')?'S1':($t>='14:00:00'?'S2':'—');
                    $action=strtolower($r['action_type']);
                    $bc=str_contains($action,'delete')?'b-cancel':(str_contains($action,'approve')||str_contains($action,'validate')?'b-done':(str_contains($action,'edit')?'b-pending':'b-progress'));
                ?>
                <tr>
                    <td><?=htmlspecialchars(trim($r['staff_name']))?:'-'?></td>
                    <td><span class="mgr-badge <?=$bc?>"><?=htmlspecialchars($r['action_type'])?></span></td>
                    <td><span class="mgr-badge b-progress"><?=$shift?></span></td>
                    <td><?=$r['ts']?date('H:i',strtotime($r['ts'])):'—'?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Staff Performance -->
<div class="mgr-panel" style="margin-bottom:20px;">
    <div class="mgr-panel-title"><div><i class="fas fa-chart-line"></i> Staff Performance — This Month</div></div>
    <?php if(empty($staff_perf)): ?>
        <div class="mgr-empty">No staff performance data for this month.</div>
    <?php else: ?>
        <div style="overflow:hidden;">
        <table class="mgr-table">
            <thead><tr><th>#</th><th>Staff Name</th><th>Transactions</th><th>Total Encoded</th><th>Performance Bar</th></tr></thead>
            <tbody>
            <?php
            $max_txn = max(array_column($staff_perf,'txn_count'));
            $i=0; $t_txn=0; $t_amt=0;
            foreach($staff_perf as $r): $i++; $t_txn+=(int)$r['txn_count']; $t_amt+=(float)$r['total_encoded'];
            $pct=$max_txn>0?round((int)$r['txn_count']/$max_txn*100):0;
            ?>
            <tr>
                <td><?=$i?></td>
                <td><?=htmlspecialchars(trim($r['staff_name']))?:'-'?></td>
                <td><?=number_format($r['txn_count'])?></td>
                <td>₱<?=number_format($r['total_encoded'],2)?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <div style="flex:1;background:#e2e8f0;height:8px;border-radius:4px;overflow:hidden;min-width:80px;"><div style="background:#00264D;height:100%;width:<?=$pct?>%;border-radius:4px;"></div></div>
                        <span style="font-size:10px;color:#64748b;"><?=$pct?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="2">TOTAL</td><td><?=number_format($t_txn)?></td><td>₱<?=number_format($t_amt,2)?></td><td></td></tr></tfoot>
        </table>
        </div>
        <div class="mgr-chart-wrap" style="height:180px;"><canvas id="staffPerfChart"></canvas></div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════
     BOTTOM: PLANNING & OVERSIGHT
     ══════════════════════════════════════════════ -->
<div class="mgr-section-title"><i class="fas fa-calendar-alt"></i> Planning &amp; Oversight</div>
<div class="mgr-grid-2">
    <!-- Calendar & Schedule -->
    <div class="mgr-panel">
        <div class="mgr-panel-title"><div><i class="fas fa-calendar-check"></i> Calendar &amp; Schedule — Today</div></div>
        <?php if(empty($cal_tasks)): ?>
            <div class="mgr-empty">No scheduled tasks for today.</div>
        <?php else: ?>
            <table class="mgr-table">
                <thead><tr><th>Type</th><th>Reference</th><th>Assigned</th><th>Time</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach($cal_tasks as $t):
                    $sl=strtolower($t['status']);
                    $sc=str_contains($sl,'complet')?'b-done':(str_contains($sl,'cancel')?'b-cancel':(str_contains($sl,'progress')?'b-progress':'b-pending'));
                    $tc=$t['task_type']==='Job Order'?'b-jo':'b-delivery';
                ?>
                <tr>
                    <td><span class="mgr-badge <?=$tc?>"><?=htmlspecialchars($t['task_type'])?></span></td>
                    <td><?=htmlspecialchars($t['ref'])?></td>
                    <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($t['assigned_to'])?></td>
                    <td><?=$t['scheduled_date']?date('H:i',strtotime($t['scheduled_date'])):'—'?></td>
                    <td><span class="mgr-badge <?=$sc?>"><?=htmlspecialchars($t['status'])?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Financial Snapshot -->
    <div class="mgr-panel">
        <div class="mgr-panel-title"><div><i class="fas fa-calculator"></i> Financial Snapshot — This Month</div></div>
        <?php $var_color=$fin_snap['variance']>=0?'#22c55e':'#dc2626'; $var_sign=$fin_snap['variance']>=0?'+':''; ?>
        <div class="fin-row">
            <span class="fk"><i class="fas fa-hand-holding-usd" style="color:#22c55e;"></i>Total Collections</span>
            <span class="fv" style="color:#22c55e;">₱<?=number_format($fin_snap['total_collected'],2)?></span>
        </div>
        <div class="fin-row">
            <span class="fk"><i class="fas fa-file-invoice-dollar" style="color:#dc2626;"></i>Total Payables</span>
            <span class="fv" style="color:#dc2626;">₱<?=number_format($fin_snap['total_payable'],2)?></span>
        </div>
        <div class="fin-row">
            <span class="fk" style="font-weight:700;color:#00264D;"><i class="fas fa-balance-scale"></i>Variance</span>
            <span class="fv" style="color:<?=$var_color?>;font-size:18px;"><?=$var_sign?>₱<?=number_format(abs($fin_snap['variance']),2)?></span>
        </div>
        <div class="mgr-chart-wrap" style="height:140px;margin-top:16px;"><canvas id="finVarianceChart"></canvas></div>
        <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
            <a href="manager_deliveries.php" style="font-size:12px;color:#00264D;text-decoration:none;padding:5px 10px;border:1px solid #00264D;border-radius:4px;"><i class="fas fa-truck"></i> Deliveries</a>
            <a href="pending_transactions.php" style="font-size:12px;color:#00264D;text-decoration:none;padding:5px 10px;border:1px solid #00264D;border-radius:4px;"><i class="fas fa-clock"></i> Pending Txns</a>
            <a href="approval_history.php" style="font-size:12px;color:#00264D;text-decoration:none;padding:5px 10px;border:1px solid #00264D;border-radius:4px;"><i class="fas fa-check-circle"></i> Approvals</a>
            <a href="manager_customers.php" style="font-size:12px;color:#00264D;text-decoration:none;padding:5px 10px;border:1px solid #00264D;border-radius:4px;"><i class="fas fa-users"></i> Customers</a>
        </div>
    </div>
</div>

<!-- Suppliers oversight list -->
<div class="mgr-panel" style="margin-top:20px; margin-bottom:20px;">
    <div class="mgr-panel-title">
        <div><i class="fas fa-truck-loading"></i> Suppliers — Oversight (Last 30 Days)</div>
    </div>
    <?php if(empty($suppliers_data)): ?>
        <div class="mgr-empty">No supplier delivery/PO records found.</div>
    <?php else: ?>
        <table class="mgr-table">
            <thead>
                <tr>
                    <th>Supplier Name</th>
                    <th>Deliveries (PO Count)</th>
                    <th>Liters Received</th>
                    <th>Total Amount</th>
                    <th>Outstanding PO Balance</th>
                    <th>Last Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($suppliers_data as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['supplier']) ?></strong></td>
                    <td><?= number_format($s['total_deliveries']) ?></td>
                    <td><?= number_format($s['total_liters'], 2) ?> L</td>
                    <td>₱<?= number_format($s['total_amount'], 2) ?></td>
                    <td><span style="color:<?= $s['outstanding_balance'] > 0 ? '#dc2626' : '#22c55e' ?>; font-weight:700;">₱<?= number_format($s['outstanding_balance'], 2) ?></span></td>
                    <td><?= $s['last_delivery'] ? date('M d, Y', strtotime($s['last_delivery'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Colors mirroring system style
const colors = {
    blue: '#00264D',
    red: '#CC0000',
    green: '#28A745',
    yellow: '#FFC107',
    info: '#17A2B8',
    gray: '#6c757d'
};

const shiftKpis = <?= json_encode($shift_kpis) ?>;
const fuelTanks = <?= json_encode($fuel_tanks) ?>;
const merchLowStock = <?= json_encode($merch_low_stock) ?>;
const joCounts = <?= json_encode($jo_counts) ?>;
const staffPerf = <?= json_encode($staff_perf) ?>;
const finSnap = <?= json_encode($fin_snap) ?>;

// 1. Shift Compare Chart
new Chart(document.getElementById('shiftCompareChart'), {
    type: 'bar',
    data: {
        labels: ['Shift 1 (6AM–2PM)', 'Shift 2 (2PM–12AM)'],
        datasets: [
            { label: 'Fuel Rev (₱)', data: [shiftKpis[1].fuel_rev, shiftKpis[2].fuel_rev], backgroundColor: colors.blue },
            { label: 'Merch Rev (₱)', data: [shiftKpis[1].merch_rev, shiftKpis[2].merch_rev], backgroundColor: colors.yellow },
            { label: 'Service Income (₱)', data: [shiftKpis[1].svc_rev, shiftKpis[2].svc_rev], backgroundColor: colors.green }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } }
    }
});

// 2. Fuel Tank Chart
if (fuelTanks.length > 0) {
    new Chart(document.getElementById('tankChart'), {
        type: 'bar',
        data: {
            labels: fuelTanks.map(t => t.fuel_type),
            datasets: [
                { label: 'Current Level (L)', data: fuelTanks.map(t => parseFloat(t.current_stock)), backgroundColor: colors.blue },
                { label: 'Capacity (L)', data: fuelTanks.map(t => parseFloat(t.capacity)), backgroundColor: colors.green + '40', borderColor: colors.green, borderWidth: 1 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true } }
        }
    });
}

// 3. Stock Pie Chart
new Chart(document.getElementById('stockPieChart'), {
    type: 'doughnut',
    data: {
        labels: ['Healthy Stock', 'Low Stock', 'Out of Stock'],
        datasets: [{
            data: [
                <?= $merch_inv_summary['total_products'] - $merch_inv_summary['low_stock'] - $merch_inv_summary['out_of_stock'] ?>,
                <?= $merch_inv_summary['low_stock'] ?>,
                <?= $merch_inv_summary['out_of_stock'] ?>
            ],
            backgroundColor: [colors.green, colors.yellow, colors.red]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false
    }
});

// 4. Job Order Trend/Status Chart
new Chart(document.getElementById('joTrendChart'), {
    type: 'bar',
    data: {
        labels: ['Pending', 'In Progress', 'Completed', 'Cancelled'],
        datasets: [{
            label: 'Today\'s JOs',
            data: [joCounts['Pending'], joCounts['In Progress'], joCounts['Completed'], joCounts['Cancelled']],
            backgroundColor: [colors.yellow, colors.info, colors.green, colors.red]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

// 5. Staff Performance Chart
if (staffPerf.length > 0) {
    new Chart(document.getElementById('staffPerfChart'), {
        type: 'bar',
        indexAxis: 'y',
        data: {
            labels: staffPerf.map(s => s.staff_name),
            datasets: [{
                label: 'Transactions Encoded',
                data: staffPerf.map(s => parseInt(s.txn_count)),
                backgroundColor: colors.blue
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
}

// 6. Financial Variance Chart
new Chart(document.getElementById('finVarianceChart'), {
    type: 'bar',
    data: {
        labels: ['Total Collections', 'Total Payables', 'Net Variance'],
        datasets: [{
            label: 'Amount (₱)',
            data: [finSnap.total_collected, finSnap.total_payable, finSnap.variance],
            backgroundColor: [colors.green, colors.red, finSnap.variance >= 0 ? colors.blue : colors.red]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true } }
    }
});
// Auto-refresh every 30 seconds
setInterval(() => {
    location.reload();
}, 30000);
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
