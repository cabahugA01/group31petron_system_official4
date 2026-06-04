
<?php
// ============================================================
// Manager Dashboard â€” public/manager_dashboard.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'manager_dashboard';
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
if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

$display_name  = htmlspecialchars($me['full_name'] ?? trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? 'Manager'));
$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error   = $_SESSION['error']   ?? null; unset($_SESSION['error']);

// ============================================================
// POST: Approve / Reject Job Order
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $jo_id   = (int)($_POST['jo_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $act     = $_POST['action'] ?? '';

    if (in_array($act, ['approve_jo', 'reject_jo'])) {
        if (empty($remarks)) {
            $_SESSION['error'] = $act === 'approve_jo' ? 'Remarks are required for approval.' : 'Rejection reason is required.';
        } else {
            try {
                $chk = $pdo->prepare("SELECT id, status, validation_status FROM job_orders WHERE id=? AND station_id=?");
                $chk->execute([$jo_id, $station_id]);
                $jo = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$jo) throw new Exception('Job order not found.');
                if (in_array($jo['status'], ['In Progress','Completed'])) throw new Exception('Cannot modify a job order that is already In Progress or Completed.');

                $pdo->beginTransaction();
                if ($act === 'approve_jo') {
                    $pdo->prepare("UPDATE job_orders SET validation_status='Approved', status='Pending', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")->execute([$me['id'], $jo_id, $station_id]);
                    $audit_action = 'APPROVE';
                    $after = 'Pending';
                } else {
                    $pdo->prepare("UPDATE job_orders SET validation_status='Rejected', status='Cancelled', validated_by=?, validated_at=NOW() WHERE id=? AND station_id=?")->execute([$me['id'], $jo_id, $station_id]);
                    $audit_action = 'REJECT';
                    $after = 'Cancelled';
                }
                // Audit trail
                try {
                    $pdo->prepare("INSERT INTO job_order_audit (job_order_id, action, before_status, after_status, performed_by, performed_at, notes, ip_address, user_agent) VALUES (?,?,?,?,?,NOW(),?,?,?)")
                        ->execute([$jo_id, $audit_action, $jo['status'], $after, $me['id'], $remarks, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
                } catch (Exception $ae) {}
                log_activity($pdo, $me['id'], 'JOB_ORDER_' . $audit_action . 'D', "JO #{$jo_id} {$audit_action}D. Remarks: {$remarks}");
                $pdo->commit();
                $_SESSION['success'] = "Job Order #{$jo_id} " . ($act === 'approve_jo' ? 'approved' : 'rejected') . ' successfully.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = $e->getMessage();
            }
        }
        header('Location: manager_dashboard.php'); exit;
    }
}

// ============================================================
// AJAX: ?refresh=1
// ============================================================
if (isset($_GET['refresh']) && $_GET['refresh'] == '1') {
    header('Content-Type: application/json');
    try {
        $s = function($sql) use ($pdo, $station_id) {
            $st = $pdo->prepare($sql);
            $st->execute([$station_id]);
            return (int)$st->fetchColumn();
        };
        // JO counts — all use prepared statements
        $jo_total    = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=?");
        $jo_pending  = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND (status='Pending Validation' OR validation_status='Pending Validation')");
        $jo_approved = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status IN ('Approved','Validated')");
        $jo_inprog   = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='In Progress'");
        $jo_done     = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Completed'");
        $jo_rejected = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status IN ('Rejected','Cancelled')");
        // Today sales — use created_at fallbacks
        $fs = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END)=CURDATE()");
        $fs->execute([$station_id]); $fuel_sales = (float)$fs->fetchColumn();
        $ms = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND DATE(created_at)=CURDATE()");
        $ms->execute([$station_id]); $merch_sales = (float)$ms->fetchColumn();
        $js = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM job_orders WHERE station_id=? AND DATE(created_at)=CURDATE() AND status='Completed'");
        $js->execute([$station_id]); $jo_sales = (float)$js->fetchColumn();
        // Staff clocked in — with fallback
        $sc = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM labor_sessions WHERE station_id=? AND DATE(start_time)=CURDATE() AND end_time IS NULL");
        $sc->execute([$station_id]); $staff_in = (int)$sc->fetchColumn();
        if ($staff_in === 0) {
            $sc2 = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM labor_sessions WHERE station_id=? AND end_time IS NULL");
            $sc2->execute([$station_id]); $staff_in = (int)$sc2->fetchColumn();
        }
        // Low stock — fuel + merchandise combined (optimized single query per type)
        $ls_merch = $pdo->prepare("SELECT COUNT(*) FROM station_inventory WHERE station_id=? AND status='active' AND stock_level<=reorder_level");
        $ls_merch->execute([$station_id]); $low_merch = (int)$ls_merch->fetchColumn();
        $ls_fuel  = $pdo->prepare("SELECT COUNT(*) FROM fuel_inventory WHERE station_id=? AND COALESCE(current_level,current_stock,0)<=2000");
        $ls_fuel  ->execute([$station_id]); $low_fuel = (int)$ls_fuel->fetchColumn();
        $low_stock = $low_merch + $low_fuel;
        // Deliveries — pending Manager approval, approved by Manager, flagged
        $pd = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status='Pending Manager Approval'");
        $pd->execute([$station_id]); $pend_del = (int)$pd->fetchColumn();
        $da = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Validated','Confirmed','Pending Admin Oversight')");
        $da->execute([$station_id]); $del_approved = (int)$da->fetchColumn();
        $dr = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Rejected','Flagged','Discrepancy')");
        $dr->execute([$station_id]); $del_rejected = (int)$dr->fetchColumn();
        // Fuel levels
        $fl = $pdo->prepare("SELECT COALESCE(ft.name,fi.fuel_type) AS fuel_type, COALESCE(fi.current_level,fi.current_stock,0) AS current_stock, COALESCE(fi.capacity,10000) AS capacity FROM fuel_inventory fi LEFT JOIN fuel_types ft ON fi.fuel_type_id=ft.id WHERE fi.station_id=? ORDER BY fuel_type");
        $fl->execute([$station_id]); $fuel_levels = $fl->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success'          => true,
            'jo_total'         => $jo_total,
            'jo_pending'       => $jo_pending,
            'jo_approved'      => $jo_approved,
            'jo_inprog'        => $jo_inprog,
            'jo_done'          => $jo_done,
            'jo_rejected'      => $jo_rejected,
            'today_sales'      => $fuel_sales + $merch_sales + $jo_sales,
            'fuel_sales'       => $fuel_sales,
            'merch_sales'      => $merch_sales,
            'staff_clocked_in' => $staff_in,
            'low_stock_count'  => $low_stock,
            'pending_deliveries'=> $pend_del,
            'del_approved'     => $del_approved,
            'del_rejected'     => $del_rejected,
            'fuel_levels'      => $fuel_levels,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// AJAX: ?refresh_charts=1
// ============================================================
if (isset($_GET['refresh_charts']) && $_GET['refresh_charts'] == '1') {
    header('Content-Type: application/json');
    try {
        // JO distribution
        $s = function($sql) use ($pdo, $station_id) { $st=$pdo->prepare($sql); $st->execute([$station_id]); return (int)$st->fetchColumn(); };
        $jo_pending  = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND (status='Pending Validation' OR validation_status='Pending Validation')");
        $jo_approved = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status IN ('Approved','Validated')");
        $jo_inprog   = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='In Progress'");
        $jo_done     = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Completed'");
        $jo_rejected = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status IN ('Rejected','Cancelled')");
        // Payment breakdown today — Job Orders + Merchandise ONLY (fuel is internal)
        $pay_sql = "SELECT
            COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END),0) AS cash,
            COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card','Debit Card') THEN total_amount ELSE 0 END),0) AS card,
            COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet') THEN total_amount ELSE 0 END),0) AS ewallet,
            COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card','efuel') THEN total_amount ELSE 0 END),0) AS efuel,
            COALESCE(SUM(CASE WHEN payment_method IN ('Credit','Account Receivable','utang','Utang') THEN total_amount ELSE 0 END),0) AS credit";
        $mp = $pdo->prepare($pay_sql . " FROM merchandise_transactions WHERE station_id=? AND DATE(created_at)=CURDATE()"); $mp->execute([$station_id]); $mpr=$mp->fetch(PDO::FETCH_ASSOC)?:[];
        $jp = $pdo->prepare($pay_sql . " FROM job_orders WHERE station_id=? AND status='Completed' AND DATE(created_at)=CURDATE()"); $jp->execute([$station_id]); $jpr=$jp->fetch(PDO::FETCH_ASSOC)?:[];
        $pay = [
            'Cash'        => (float)($mpr['cash']??0)   + (float)($jpr['cash']??0),
            'Card'        => (float)($mpr['card']??0)   + (float)($jpr['card']??0),
            'E-Wallet'    => (float)($mpr['ewallet']??0)+ (float)($jpr['ewallet']??0),
            'E-Fuel Card' => (float)($mpr['efuel']??0)  + (float)($jpr['efuel']??0),
            'Credit'      => (float)($mpr['credit']??0) + (float)($jpr['credit']??0),
        ];
        // Sales trend 30 days
        $trend_dates=[]; $trend_fuel=[]; $trend_merch=[];
        $td = $pdo->prepare("SELECT DATE(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END) AS d, COALESCE(SUM(total_amount),0) AS rev FROM fuel_transactions WHERE station_id=? AND DATE(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY d"); $td->execute([$station_id]); $fuel_trend=array_column($td->fetchAll(PDO::FETCH_ASSOC),'rev','d');
        $tm = $pdo->prepare("SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS rev FROM merchandise_transactions WHERE station_id=? AND DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY DATE(created_at)"); $tm->execute([$station_id]); $merch_trend=array_column($tm->fetchAll(PDO::FETCH_ASSOC),'rev','d');
        for ($i=29;$i>=0;$i--) { $d=date('Y-m-d',strtotime("-{$i} days")); $trend_dates[]=date('M j',strtotime($d)); $trend_fuel[]=(float)($fuel_trend[$d]??0); $trend_merch[]=(float)($merch_trend[$d]??0); }
        // Validation trend 7 days — include verified/approved/validated
        $val_dates=[]; $val_approved=[]; $val_rejected=[];
        $va = $pdo->prepare("SELECT DATE(COALESCE(validated_at, updated_at)) AS d, SUM(CASE WHEN LOWER(validation_status) IN ('approved','validated') THEN 1 ELSE 0 END) AS appr, SUM(CASE WHEN LOWER(validation_status) = 'rejected' THEN 1 ELSE 0 END) AS rej FROM job_orders WHERE station_id=? AND DATE(COALESCE(validated_at, updated_at))>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY DATE(COALESCE(validated_at, updated_at))"); $va->execute([$station_id]); $va_data=array_column($va->fetchAll(PDO::FETCH_ASSOC),null,'d');
        for ($i=6;$i>=0;$i--) { $d=date('Y-m-d',strtotime("-{$i} days")); $val_dates[]=date('M j',strtotime($d)); $val_approved[]=(int)($va_data[$d]['appr']??0); $val_rejected[]=(int)($va_data[$d]['rej']??0); }
        echo json_encode(['success'=>true,'jo_dist'=>['Pending Validation'=>$jo_pending,'Approved'=>$jo_approved,'In Progress'=>$jo_inprog,'Completed'=>$jo_done,'Rejected'=>$jo_rejected],'payment'=>$pay,'trend_dates'=>$trend_dates,'trend_fuel'=>$trend_fuel,'trend_merch'=>$trend_merch,'val_dates'=>$val_dates,'val_approved'=>$val_approved,'val_rejected'=>$val_rejected]);
    } catch (Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ============================================================
// PHP DATA QUERIES
// ============================================================

// --- JO Counts ---
$jo_total = $jo_pending = $jo_approved = $jo_inprog = $jo_done = $jo_rejected = 0;
try {
    $s = function($sql) use ($pdo, $station_id) { $st=$pdo->prepare($sql); $st->execute([$station_id]); return (int)$st->fetchColumn(); };
    $jo_total    = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=?");
    $jo_pending  = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND (status='Pending Validation' OR validation_status='Pending Validation')");
    $jo_approved = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status IN ('Approved','Validated')");
    $jo_inprog   = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='In Progress'");
    $jo_done     = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Completed'");
    $jo_rejected = $s("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status IN ('Rejected','Cancelled')");
} catch (Exception $e) {}

// --- Today Sales --- (uses created_at as fallback when transaction_date is null/zero)
$today_fuel_sales = $today_merch_sales = $today_jo_sales_direct = 0;
$week_fuel_sales = $week_merch_sales = $week_jo_sales = 0;
try {
    // Fuel: use created_at if transaction_date is zero/null
    $fs = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END)=CURDATE()");
    $fs->execute([$station_id]); $today_fuel_sales=(float)$fs->fetchColumn();
    // Merch: prefer created_at since transaction_date is often 0000-00-00
    $ms = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND DATE(created_at)=CURDATE()");
    $ms->execute([$station_id]); $today_merch_sales=(float)$ms->fetchColumn();
    // Job Orders completed today
    $js = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM job_orders WHERE station_id=? AND DATE(created_at)=CURDATE() AND status='Completed'");
    $js->execute([$station_id]); $today_jo_sales_direct=(float)$js->fetchColumn();
    // Weekly totals for context
    $wf = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)");
    $wf->execute([$station_id]); $week_fuel_sales=(float)$wf->fetchColumn();
    $wm = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)");
    $wm->execute([$station_id]); $week_merch_sales=(float)$wm->fetchColumn();
    $wj = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM job_orders WHERE station_id=? AND DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) AND status='Completed'");
    $wj->execute([$station_id]); $week_jo_sales=(float)$wj->fetchColumn();
} catch (Exception $e) {}
$today_total_sales = $today_fuel_sales + $today_merch_sales + $today_jo_sales_direct;
$week_total_sales  = $week_fuel_sales  + $week_merch_sales  + $week_jo_sales;

// --- Staff Clocked In --- (includes any open session, not just today)
$staff_clocked_in = 0;
try {
    // First try today's sessions
    $sc=$pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM labor_sessions WHERE station_id=? AND DATE(start_time)=CURDATE() AND end_time IS NULL");
    $sc->execute([$station_id]); $staff_clocked_in=(int)$sc->fetchColumn();
    // Fallback: any open session (end_time IS NULL) if none found today
    if ($staff_clocked_in === 0) {
        $sc2=$pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM labor_sessions WHERE station_id=? AND end_time IS NULL");
        $sc2->execute([$station_id]); $staff_clocked_in=(int)$sc2->fetchColumn();
    }
} catch (Exception $e) {}

// --- Pending Deliveries ---
// Manager sees only records in the Manager queue (Pending Manager Approval)
$pending_deliveries = 0;
try { $pd=$pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status='Pending Manager Approval'"); $pd->execute([$station_id]); $pending_deliveries=(int)$pd->fetchColumn(); } catch (Exception $e) {}

// --- Fuel Inventory / Tank Levels ---
$fuel_stock_levels = [];
try {
    $fl=$pdo->prepare("SELECT COALESCE(ft.name,fi.fuel_type) AS fuel_type_name, COALESCE(fi.current_level,fi.current_stock,0) AS current_stock, COALESCE(fi.capacity,10000) AS capacity, COALESCE(fi.price_per_liter,0) AS price_per_liter FROM fuel_inventory fi LEFT JOIN fuel_types ft ON fi.fuel_type_id=ft.id WHERE fi.station_id=? ORDER BY fuel_type_name");
    $fl->execute([$station_id]); $fuel_stock_levels=$fl->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch (Exception $e) {}

// --- Fuel Variance (today) ---
$fuel_variance_rows = [];
$variance_window_label = "Today";
try {
    // Calculate variance: difference between meter reading (present - previous) and actual liters sold
    // Include all transactions for today, even with zero sales, to detect discrepancies
    $fv=$pdo->prepare("
        SELECT fuel_type, 
               ROUND(SUM(present_reading - previous_reading),2) AS meter_reading,
               ROUND(SUM(liters_sold),2) AS pump_liters, 
               ROUND(SUM(ABS((present_reading - previous_reading) - liters_sold)),2) AS variance 
        FROM fuel_transactions 
        WHERE station_id=? 
          AND DATE(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END)=CURDATE() 
        GROUP BY fuel_type
        HAVING variance > 0.5
    ");
    $fv->execute([$station_id]); 
    $fuel_variance_rows=$fv->fetchAll(PDO::FETCH_ASSOC)?:[];
    
    // Fallback: if no variance rows today, look at the last 7 days to give active manager feedback
    if (empty($fuel_variance_rows)) {
        $fv2=$pdo->prepare("
            SELECT fuel_type, 
                   ROUND(SUM(present_reading - previous_reading),2) AS meter_reading,
                   ROUND(SUM(liters_sold),2) AS pump_liters, 
                   ROUND(SUM(ABS((present_reading - previous_reading) - liters_sold)),2) AS variance 
            FROM fuel_transactions 
            WHERE station_id=? 
              AND DATE(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY fuel_type
            HAVING variance > 0.5
        ");
        $fv2->execute([$station_id]);
        $fuel_variance_rows=$fv2->fetchAll(PDO::FETCH_ASSOC)?:[];
        if (!empty($fuel_variance_rows)) {
            $variance_window_label = "Last 7 Days";
        }
    }
} catch (Exception $e) {}

// --- Low Stock Items (Fuel + Merchandise combined) ---
$low_stock_items = [];
$low_stock_count = 0;
try {
    // Merchandise low stock from station_inventory
    $lsi = $pdo->prepare("
        SELECT product_name, CAST(stock_level AS SIGNED) AS stock_level, CAST(reorder_level AS SIGNED) AS reorder_level, 'Merchandise' AS item_type
        FROM station_inventory
        WHERE station_id=? AND status='active' AND stock_level <= reorder_level
        ORDER BY stock_level ASC
        LIMIT 15
    ");
    $lsi->execute([$station_id]);
    $merch_low = $lsi->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Fuel low stock from fuel_inventory (critical ≤500L, low ≤2000L)
    $fli = $pdo->prepare("
        SELECT COALESCE(ft.name, fi.fuel_type) AS product_name,
               CAST(COALESCE(fi.current_level, fi.current_stock, 0) AS SIGNED) AS stock_level,
               2000 AS reorder_level,
               'Fuel' AS item_type
        FROM fuel_inventory fi
        LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
        WHERE fi.station_id = ? AND COALESCE(fi.current_level, fi.current_stock, 0) <= 2000
        ORDER BY stock_level ASC
    ");
    $fli->execute([$station_id]);
    $fuel_low = $fli->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Merge: fuel first (more critical), then merchandise
    $low_stock_items = array_merge($fuel_low, $merch_low);
    $low_stock_count = count($low_stock_items);
} catch (Exception $e) {}

// --- Job Orders Table ---
$jo_filter_status   = trim($_GET['jo_status'] ?? '');
$jo_filter_customer = trim($_GET['jo_customer'] ?? '');
$jo_page = max(1, (int)($_GET['jo_page'] ?? 1));
$jo_per_page = 20;
$jo_offset = ($jo_page - 1) * $jo_per_page;
$job_order_rows = []; $jo_total_filtered = 0;
try {
    $where = "WHERE jo.station_id=?"; $params = [$station_id];
    
    // Status filter - check both status and validation_status fields
    if ($jo_filter_status) { 
        if ($jo_filter_status === 'Pending Validation') {
            $where .= " AND (jo.status='Pending Validation' OR jo.validation_status='Pending Validation')";
        } else {
            $where .= " AND jo.status=?"; 
            $params[] = $jo_filter_status; 
        }
    }
    
    // Customer filter - search in both customer_name and linked customer table
    if ($jo_filter_customer) { 
        $where .= " AND (jo.customer_name LIKE ? OR c.name LIKE ?)"; 
        $params[] = "%{$jo_filter_customer}%"; 
        $params[] = "%{$jo_filter_customer}%"; 
    }
    
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM job_orders jo LEFT JOIN customers c ON c.id=jo.customer_id {$where}"); 
    $cnt->execute($params); 
    $jo_total_filtered=(int)$cnt->fetchColumn();
    
    $jod=$pdo->prepare("SELECT COALESCE(jo.job_order_id,jo.job_order_number,CONCAT('JO-',jo.id)) AS jo_ref, COALESCE(c.name,jo.customer_name,'Walk-in') AS customer, COALESCE(jo.vehicle_plate,'—') AS vehicle_plate, COALESCE(jo.service_type,jo.service_description,'—') AS service_type, COALESCE(m.full_name,'—') AS mechanic, jo.status, COALESCE(jo.validation_status,jo.status) AS display_status, jo.payment_method, jo.created_at, jo.id FROM job_orders jo LEFT JOIN mechanics m ON m.id=jo.assigned_mechanic_id LEFT JOIN customers c ON c.id=jo.customer_id {$where} ORDER BY FIELD(jo.status,'Pending Validation','In Progress','Approved','Validated','Completed','Rejected','Cancelled'), jo.created_at DESC LIMIT {$jo_per_page} OFFSET {$jo_offset}");
    $jod->execute($params); 
    $job_order_rows=$jod->fetchAll(PDO::FETCH_ASSOC)?:[];
} catch (Exception $e) {}

// --- Staff Attendance — show today's sessions, fallback to recent open sessions ---
$attendance_rows = [];
try {
    $att = $pdo->prepare("
        SELECT u.name AS staff_name,
               u.role,
               ls.start_time,
               ls.end_time,
               ls.shift_name,
               CASE
                   WHEN ls.end_time IS NULL
                       THEN ROUND(TIMESTAMPDIFF(MINUTE, ls.start_time, NOW()) / 60, 2)
                   ELSE COALESCE(ls.hours_worked, ROUND(TIMESTAMPDIFF(MINUTE, ls.start_time, ls.end_time) / 60, 2))
               END AS hours
        FROM labor_sessions ls
        JOIN users u ON u.id = ls.user_id
        WHERE ls.station_id = ?
          AND DATE(ls.start_time) = CURDATE()
        ORDER BY (ls.end_time IS NULL) DESC, ls.start_time DESC
    ");
    $att->execute([$station_id]);
    $attendance_rows = $att->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Fallback: show last 3 days if no attendance today
    if (empty($attendance_rows)) {
        $att2 = $pdo->prepare("
            SELECT u.name AS staff_name,
                   u.role,
                   ls.start_time,
                   ls.end_time,
                   ls.shift_name,
                   CASE
                       WHEN ls.end_time IS NULL
                           THEN ROUND(TIMESTAMPDIFF(MINUTE, ls.start_time, NOW()) / 60, 2)
                       ELSE COALESCE(ls.hours_worked, ROUND(TIMESTAMPDIFF(MINUTE, ls.start_time, ls.end_time) / 60, 2))
                   END AS hours
            FROM labor_sessions ls
            JOIN users u ON u.id = ls.user_id
            WHERE ls.station_id = ?
              AND ls.start_time >= DATE_SUB(NOW(), INTERVAL 3 DAY)
            ORDER BY (ls.end_time IS NULL) DESC, ls.start_time DESC
            LIMIT 20
        ");
        $att2->execute([$station_id]);
        $attendance_rows = $att2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Exception $e) {}
$staff_active_count = count(array_filter($attendance_rows, fn($r) => empty($r['end_time'])));
$staff_total_today  = count($attendance_rows);

// --- Deliveries Summary ---
// Manager sees: pending = their queue only; approved = forwarded to Admin or validated; rejected = flagged/discrepancy
$del_pending = $del_approved = $del_rejected = 0;
try {
    $dp=$pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status='Pending Manager Approval'"); $dp->execute([$station_id]); $del_pending=(int)$dp->fetchColumn();
    $da=$pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Validated','Confirmed','Pending Admin Oversight')"); $da->execute([$station_id]); $del_approved=(int)$da->fetchColumn();
    $dr=$pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Rejected','Flagged','Discrepancy')"); $dr->execute([$station_id]); $del_rejected=(int)$dr->fetchColumn();
} catch (Exception $e) {}
// Deliveries trend 14 days
$del_trend_dates = []; $del_trend_counts = [];
try {
    $dt=$pdo->prepare("SELECT DATE(COALESCE(delivery_date,created_at)) AS d, COUNT(*) AS cnt FROM deliveries_oversight WHERE station_id=? AND DATE(COALESCE(delivery_date,created_at))>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) GROUP BY DATE(COALESCE(delivery_date,created_at))"); $dt->execute([$station_id]); $del_data=array_column($dt->fetchAll(PDO::FETCH_ASSOC),'cnt','d');
    for ($i=13;$i>=0;$i--) { $d=date('Y-m-d',strtotime("-{$i} days")); $del_trend_dates[]=date('M j',strtotime($d)); $del_trend_counts[]=(int)($del_data[$d]??0); }
} catch (Exception $e) { for ($i=13;$i>=0;$i--) { $del_trend_dates[]=date('M j',strtotime("-{$i} days")); $del_trend_counts[]=0; } }

// --- Customer Balances — from both job_orders AND merchandise_transactions ---
$customer_balances = [];
try {
    $cb = $pdo->prepare("
        SELECT c.name,
               COALESCE(c.credit_limit, 0) AS credit_limit,
               COALESCE(
                   (SELECT SUM(jo.total_cost - COALESCE(jo.amount_paid, 0))
                    FROM job_orders jo
                    WHERE jo.customer_id = c.id
                      AND jo.payment_method IN ('Credit','Account Receivable','utang','Utang')
                      AND jo.payment_status != 'Paid'
                      AND jo.station_id = ?), 0
               ) +
               COALESCE(
                   (SELECT SUM(mt.total_amount - COALESCE(mt.amount_tendered, 0))
                    FROM merchandise_transactions mt
                    WHERE mt.customer_id = c.id
                      AND mt.payment_method IN ('Credit','Account Receivable','utang','Utang')
                      AND mt.station_id = ?), 0
               ) AS outstanding
        FROM customers c
        WHERE c.station_id = ?
        HAVING outstanding > 0
        ORDER BY outstanding DESC
        LIMIT 10
    ");
    $cb->execute([$station_id, $station_id, $station_id]);
    $customer_balances = $cb->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    // Fallback: job_orders only
    try {
        $cb2 = $pdo->prepare("SELECT c.name, COALESCE(c.credit_limit,0) AS credit_limit, COALESCE(SUM(jo.total_cost - COALESCE(jo.amount_paid,0)),0) AS outstanding FROM customers c LEFT JOIN job_orders jo ON jo.customer_id=c.id AND jo.payment_method IN ('Credit','Account Receivable','utang','Utang') AND jo.payment_status != 'Paid' AND jo.station_id=? WHERE c.station_id=? GROUP BY c.id,c.name,c.credit_limit HAVING outstanding>0 ORDER BY outstanding DESC LIMIT 10");
        $cb2->execute([$station_id, $station_id]);
        $customer_balances = $cb2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e2) {}
}

// --- Staff Performance (current month) — ALL active staff, not just those with activity ---
$staff_performance = [];
try {
    $sp = $pdo->prepare("
        SELECT u.name AS staff_name, u.role,
               COUNT(DISTINCT mt.id) AS txn_count,
               COUNT(DISTINCT jo.id) AS jo_count
        FROM users u
        LEFT JOIN merchandise_transactions mt
            ON mt.staff_id = u.id
            AND mt.station_id = ?
            AND YEAR(COALESCE(mt.transaction_date, mt.created_at)) = YEAR(CURDATE())
            AND MONTH(COALESCE(mt.transaction_date, mt.created_at)) = MONTH(CURDATE())
            AND mt.validation_status = 'Approved'
        LEFT JOIN job_orders jo
            ON jo.user_id = u.id
            AND jo.station_id = ?
            AND YEAR(jo.created_at) = YEAR(CURDATE())
            AND MONTH(jo.created_at) = MONTH(CURDATE())
            AND jo.status = 'Completed'
        WHERE u.station_id = ?
          AND u.status = 'active'
          AND u.role NOT IN ('manager', 'admin', 'superadmin')
        GROUP BY u.id, u.name, u.role
        ORDER BY (txn_count + jo_count) DESC, u.name ASC
        LIMIT 15
    ");
    $sp->execute([$station_id, $station_id, $station_id]);
    $staff_performance = $sp->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// --- Audit Trail Quick View (Last 5 Manager Actions) ---
$audit_trail = [];
try {
    $at = $pdo->prepare("SELECT action, details, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $at->execute([$me['id']]);
    $audit_trail = $at->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// --- Inventory Snapshot ---
$inv_fuel_total = 0;
$inv_merch_total = 0;
try {
    $ift = $pdo->prepare("SELECT COALESCE(SUM(current_stock),0) FROM fuel_inventory WHERE station_id = ?");
    $ift->execute([$station_id]);
    $inv_fuel_total = (float)$ift->fetchColumn();

    $imt = $pdo->prepare("SELECT COALESCE(SUM(stock_level),0) FROM station_inventory WHERE station_id = ? AND status = 'active'");
    $imt->execute([$station_id]);
    $inv_merch_total = (int)$imt->fetchColumn();
} catch (Exception $e) {}

// --- Price Change Snapshot (Pending / Approved) ---
$price_changes = [];
try {
    $pc = $pdo->prepare("SELECT action, details, created_at FROM activity_logs WHERE action IN ('Propose Price', 'Approve Price', 'Reject Price', 'Hold Price') ORDER BY created_at DESC LIMIT 5");
    $pc->execute();
    $price_changes = $pc->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// --- Payment Breakdown (today) — Job Orders + Merchandise ONLY, fuel is internal ---
$pay_cash=$pay_card=$pay_ewallet=$pay_efuel=$pay_credit=0;
try {
    $pay_sql="SELECT COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END),0) AS cash, COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card','Debit Card') THEN total_amount ELSE 0 END),0) AS card, COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet') THEN total_amount ELSE 0 END),0) AS ewallet, COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card','efuel') THEN total_amount ELSE 0 END),0) AS efuel, COALESCE(SUM(CASE WHEN payment_method IN ('Credit','Account Receivable','utang','Utang') THEN total_amount ELSE 0 END),0) AS credit";
    // Merchandise transactions — use created_at (transaction_date often 0000-00-00)
    $mp=$pdo->prepare($pay_sql." FROM merchandise_transactions WHERE station_id=? AND DATE(created_at)=CURDATE()"); $mp->execute([$station_id]); $mpr=$mp->fetch(PDO::FETCH_ASSOC)?:[];
    // Job orders (completed today)
    $jp=$pdo->prepare($pay_sql." FROM job_orders WHERE station_id=? AND status='Completed' AND DATE(created_at)=CURDATE()"); $jp->execute([$station_id]); $jpr=$jp->fetch(PDO::FETCH_ASSOC)?:[];
    $pay_cash   =(float)($mpr['cash']??0)   +(float)($jpr['cash']??0);
    $pay_card   =(float)($mpr['card']??0)   +(float)($jpr['card']??0);
    $pay_ewallet=(float)($mpr['ewallet']??0)+(float)($jpr['ewallet']??0);
    $pay_efuel  =(float)($mpr['efuel']??0)  +(float)($jpr['efuel']??0);
    $pay_credit =(float)($mpr['credit']??0) +(float)($jpr['credit']??0);
} catch (Exception $e) {}

// --- Sales Trend 30 days --- (use created_at fallback for fuel when transaction_date is zero)
$trend_dates=$trend_fuel=$trend_merch=[];
try {
    $td=$pdo->prepare("SELECT DATE(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END) AS d, COALESCE(SUM(total_amount),0) AS rev FROM fuel_transactions WHERE station_id=? AND DATE(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY d"); $td->execute([$station_id]); $fuel_trend=array_column($td->fetchAll(PDO::FETCH_ASSOC),'rev','d');
    $tm=$pdo->prepare("SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS rev FROM merchandise_transactions WHERE station_id=? AND DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY DATE(created_at)"); $tm->execute([$station_id]); $merch_trend=array_column($tm->fetchAll(PDO::FETCH_ASSOC),'rev','d');
    for ($i=29;$i>=0;$i--) { $d=date('Y-m-d',strtotime("-{$i} days")); $trend_dates[]=date('M j',strtotime($d)); $trend_fuel[]=(float)($fuel_trend[$d]??0); $trend_merch[]=(float)($merch_trend[$d]??0); }
} catch (Exception $e) {}

// --- Validation Trend 7 days ---
$val_dates=$val_approved_arr=$val_rejected_arr=[];
try {
    $va=$pdo->prepare("SELECT DATE(validated_at) AS d, SUM(CASE WHEN validation_status='Approved' THEN 1 ELSE 0 END) AS appr, SUM(CASE WHEN validation_status='Rejected' THEN 1 ELSE 0 END) AS rej FROM job_orders WHERE station_id=? AND DATE(validated_at)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY DATE(validated_at)"); $va->execute([$station_id]); $va_data=array_column($va->fetchAll(PDO::FETCH_ASSOC),null,'d');
    for ($i=6;$i>=0;$i--) { $d=date('Y-m-d',strtotime("-{$i} days")); $val_dates[]=date('M j',strtotime($d)); $val_approved_arr[]=(int)($va_data[$d]['appr']??0); $val_rejected_arr[]=(int)($va_data[$d]['rej']??0); }
} catch (Exception $e) {}

// --- Station name ---
$station_name = 'Station';
try { $sn=$pdo->prepare("SELECT name FROM stations WHERE id=?"); $sn->execute([$station_id]); $station_name=$sn->fetchColumn()?:'Station'; } catch (Exception $e) {}

// ============================================================
// COMPLETE MANAGER DASHBOARD DATA QUERIES
// ============================================================
// 
// DATA SOURCES VERIFIED - ALL MODULES INCLUDED:
// ✓ FUEL: fuel_transactions, fuel_inventory, fuel_types
// ✓ MERCHANDISE: merchandise_transactions, merchandise_transaction_items, station_inventory, products
// ✓ JOB ORDERS/SERVICES: job_orders (includes service_type, service_description, required_parts)
// ✓ PRODUCTS: Shown via station_inventory (merchandise products) and job_order required_parts
// ✓ DELIVERIES: deliveries_oversight (fuel & merchandise deliveries)
// ✓ STAFF: users, labor_sessions (staff activity and attendance)
// ✓ CUSTOMERS: customers (credit balances and payment tracking)
//
// ────────────────────────────────────────────────────────────
// 1. VALIDATION QUEUE - Pending items needing manager action
// ────────────────────────────────────────────────────────────
$validation_queue = [
    'pending_transactions' => 0,
    'pending_deliveries' => 0,
    'pending_stock_requests' => 0,
    'pending_fuel_tx' => 0,
    'pending_merch_tx' => 0,
    'pending_jo' => 0,
];

try {
    // Pending Fuel Transactions — covers both 'Pending Validation' and 'pending' status values
    $pft = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id=? AND LOWER(status) IN ('pending validation','pending')");
    $pft->execute([$station_id]); 
    $validation_queue['pending_fuel_tx'] = (int)$pft->fetchColumn();
    
    // Pending Merchandise Transactions — covers 'Pending Validation', 'Pending', and NULL
    $pmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND (LOWER(COALESCE(validation_status,'')) IN ('pending validation','pending') OR validation_status IS NULL OR validation_status='')");
    $pmt->execute([$station_id]); 
    $validation_queue['pending_merch_tx'] = (int)$pmt->fetchColumn();
    
    // Pending Job Orders
    $pjo = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND (LOWER(status)='pending validation' OR LOWER(COALESCE(validation_status,''))='pending validation')");
    $pjo->execute([$station_id]); 
    $validation_queue['pending_jo'] = (int)$pjo->fetchColumn();
    
    $validation_queue['pending_transactions'] = $validation_queue['pending_fuel_tx'] + $validation_queue['pending_merch_tx'] + $validation_queue['pending_jo'];
    
    // Pending Deliveries
    $pdel = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status='Pending Manager Approval'");
    $pdel->execute([$station_id]); 
    $validation_queue['pending_deliveries'] = (int)$pdel->fetchColumn();
    
    // Pending Stock Requests
    $psr = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id=? AND LOWER(status)='pending'");
    $psr->execute([$station_id]); 
    $validation_queue['pending_stock_requests'] = (int)$psr->fetchColumn();
} catch (Exception $e) {}

// ────────────────────────────────────────────────────────────
// 2. VALIDATED RECORDS - Recently approved items
// ────────────────────────────────────────────────────────────
$validated_records = [];
$validated_counts = ['today' => 0, 'week' => 0, 'month' => 0];

try {
    // Get validated records from all sources — include 'verified'/'Approved'/'Validated' statuses
    $vr = $pdo->prepare("
        SELECT 'Fuel' AS type, 
               CONCAT('FUEL-', id) AS ref,
               CONCAT(COALESCE(liters_sold,0), 'L ', COALESCE(fuel_type,'')) AS description,
               total_amount,
               COALESCE(validated_at, created_at) AS validated_at,
               validated_by
        FROM fuel_transactions
        WHERE station_id = ? AND LOWER(status) IN ('verified','approved','validated') AND total_amount > 0
        
        UNION ALL
        
        SELECT 'Merchandise' AS type,
               CONCAT('MERCH-', id) AS ref,
               CONCAT('Merch Sale ₱', COALESCE(total_amount,0)) AS description,
               total_amount,
               COALESCE(validated_at, updated_at, created_at) AS validated_at,
               validated_by
        FROM merchandise_transactions
        WHERE station_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('approved','validated') AND total_amount > 0
        
        UNION ALL
        
        SELECT 'Job Order' AS type,
               COALESCE(job_order_id, CONCAT('JO-', id)) AS ref,
               COALESCE(service_type, service_description, 'Service') AS description,
               total_cost AS total_amount,
               COALESCE(validated_at, updated_at, created_at) AS validated_at,
               validated_by
        FROM job_orders
        WHERE station_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('approved','validated') AND total_cost > 0
        
        UNION ALL
        
        SELECT 'Delivery' AS type,
               CONCAT('DEL-', id) AS ref,
               COALESCE(delivery_type, 'Delivery') AS description,
               0 AS total_amount,
               COALESCE(updated_at, created_at) AS validated_at,
               validated_by
        FROM deliveries_oversight
        WHERE station_id = ? AND LOWER(status) IN ('validated','confirmed','pending admin oversight')
        
        ORDER BY validated_at DESC
        LIMIT 20
    ");
    $vr->execute([$station_id, $station_id, $station_id, $station_id]);
    $validated_records = $vr->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Count validations by period — include 'verified' status for fuel
    $vc = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN DATE(validated_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
            SUM(CASE WHEN validated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
            SUM(CASE WHEN MONTH(validated_at) = MONTH(CURDATE()) AND YEAR(validated_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS month
        FROM (
            SELECT COALESCE(validated_at, created_at) AS validated_at FROM fuel_transactions WHERE station_id = ? AND LOWER(status) IN ('verified','approved','validated') AND COALESCE(validated_at, created_at) IS NOT NULL
            UNION ALL
            SELECT COALESCE(validated_at, updated_at) AS validated_at FROM merchandise_transactions WHERE station_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('approved','validated') AND COALESCE(validated_at, updated_at) IS NOT NULL
            UNION ALL
            SELECT COALESCE(validated_at, updated_at) AS validated_at FROM job_orders WHERE station_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('approved','validated') AND COALESCE(validated_at, updated_at) IS NOT NULL
        ) AS all_validations
    ");
    $vc->execute([$station_id, $station_id, $station_id]);
    $validated_counts = $vc->fetch(PDO::FETCH_ASSOC) ?: ['today' => 0, 'week' => 0, 'month' => 0];
} catch (Exception $e) {}

// Approved Transactions vs Deliveries per day (Last 7 days)
$approved_trend_dates = [];
$approved_trend_transactions = [];
$approved_trend_deliveries = [];
try {
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $approved_trend_dates[] = date('M j', strtotime($date));
        
        // Count approved transactions — include 'verified' for fuel
        $tc = $pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT id FROM fuel_transactions WHERE station_id = ? AND DATE(COALESCE(validated_at, created_at)) = ? AND LOWER(status) IN ('verified','approved','validated')
                UNION ALL
                SELECT id FROM merchandise_transactions WHERE station_id = ? AND DATE(COALESCE(validated_at, updated_at)) = ? AND LOWER(COALESCE(validation_status,'')) IN ('approved','validated')
                UNION ALL
                SELECT id FROM job_orders WHERE station_id = ? AND DATE(COALESCE(validated_at, updated_at)) = ? AND LOWER(COALESCE(validation_status,'')) IN ('approved','validated')
            ) AS daily_txns
        ");
        $tc->execute([$station_id, $date, $station_id, $date, $station_id, $date]);
        $approved_trend_transactions[] = (int)$tc->fetchColumn();
        
        // Count deliveries
        $dc = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND DATE(updated_at) = ? AND LOWER(status) IN ('validated','confirmed','pending admin oversight')");
        $dc->execute([$station_id, $date]);
        $approved_trend_deliveries[] = (int)$dc->fetchColumn();
    }
} catch (Exception $e) {
    for ($i = 0; $i < 7; $i++) {
        $approved_trend_dates[] = date('M j', strtotime("-" . (6 - $i) . " days"));
        $approved_trend_transactions[] = 0;
        $approved_trend_deliveries[] = 0;
    }
}

// ────────────────────────────────────────────────────────────
// 3. VARIANCE PANEL - Fuel & Merchandise discrepancies
// ────────────────────────────────────────────────────────────
$fuel_variance = [];
$merch_variance = [];

try {
    // Fuel Variance: Sales vs Tank vs Deliveries
    $fv = $pdo->prepare("
        SELECT ft.name AS fuel_type,
               COALESCE(sales.total_liters, 0) AS sales_liters,
               COALESCE(inv.current_stock, 0) AS tank_level,
               COALESCE(del.quantity, 0) AS delivered_liters,
               (COALESCE(inv.current_stock, 0) + COALESCE(sales.total_liters, 0) - COALESCE(del.quantity, 0)) AS calculated_variance
        FROM fuel_types ft
        LEFT JOIN fuel_inventory inv ON inv.fuel_type_id = ft.id AND inv.station_id = ?
        LEFT JOIN (
            SELECT fuel_type, SUM(liters_sold) AS total_liters
            FROM fuel_transactions
            WHERE station_id = ? AND DATE(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY fuel_type
        ) sales ON sales.fuel_type = ft.name
        LEFT JOIN (
            SELECT product AS fuel_type, SUM(quantity) AS quantity
            FROM deliveries_oversight
            WHERE station_id = ? AND delivery_type = 'fuel' AND DATE(delivery_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY product
        ) del ON del.fuel_type = ft.name
        GROUP BY ft.id, ft.name, inv.current_stock, sales.total_liters, del.quantity
    ");
    $fv->execute([$station_id, $station_id, $station_id]);
    $fuel_variance = $fv->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Merchandise Variance: Stock vs Sales (using mt.id to join station_id)
    $mv = $pdo->prepare("
        SELECT si.product_name,
               si.stock_level AS current_stock,
               COALESCE(SUM(sales.quantity), 0) AS sales_quantity,
               (si.stock_level - COALESCE(SUM(sales.quantity), 0)) AS variance
        FROM station_inventory si
        LEFT JOIN (
            SELECT mti.product_id, SUM(mti.quantity) AS quantity
            FROM merchandise_transaction_items mti
            JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
            WHERE mt.station_id = ? AND DATE(mti.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY mti.product_id
        ) sales ON sales.product_id = si.product_id
        WHERE si.station_id = ? AND si.status = 'active'
        GROUP BY si.id, si.product_name, si.stock_level
        HAVING ABS(si.stock_level - COALESCE(SUM(sales.quantity), 0)) > 5
        ORDER BY ABS(si.stock_level - COALESCE(SUM(sales.quantity), 0)) DESC
        LIMIT 10
    ");
    $mv->execute([$station_id, $station_id]);
    $merch_variance = $mv->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// ────────────────────────────────────────────────────────────
// 4. STAFF ACTIVITY SUMMARY
// ────────────────────────────────────────────────────────────
$staff_activity = [];
$staff_encoding_trend = [];

try {
    // Encoding count per staff (this month)
    $sa = $pdo->prepare("
        SELECT u.name AS staff_name,
               u.role,
               COALESCE(fuel_count, 0) AS fuel_txn,
               COALESCE(merch_count, 0) AS merch_txn,
               COALESCE(jo_count, 0) AS jo_created,
               (COALESCE(fuel_count, 0) + COALESCE(merch_count, 0) + COALESCE(jo_count, 0)) AS total_encodings
        FROM users u
        LEFT JOIN (
            SELECT staff_id, COUNT(*) AS fuel_count
            FROM fuel_transactions
            WHERE station_id = ? AND MONTH(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' THEN created_at ELSE transaction_date END) = MONTH(CURDATE()) AND YEAR(CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' THEN created_at ELSE transaction_date END) = YEAR(CURDATE())
            GROUP BY staff_id
        ) ft ON ft.staff_id = u.id
        LEFT JOIN (
            SELECT staff_id, COUNT(*) AS merch_count
            FROM merchandise_transactions
            WHERE station_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
            GROUP BY staff_id
        ) mt ON mt.staff_id = u.id
        LEFT JOIN (
            SELECT user_id, COUNT(*) AS jo_count
            FROM job_orders
            WHERE station_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
            GROUP BY user_id
        ) jo ON jo.user_id = u.id
        WHERE u.station_id = ? AND u.status = 'active' AND u.role NOT IN ('manager', 'admin', 'superadmin')
        HAVING total_encodings > 0
        ORDER BY total_encodings DESC
        LIMIT 10
    ");
    $sa->execute([$station_id, $station_id, $station_id, $station_id]);
    $staff_activity = $sa->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Validation trend (last 7 days) - Manager's validation activity
    $staff_encoding_trend_dates = [];
    $staff_encoding_trend_counts = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $staff_encoding_trend_dates[] = date('M j', strtotime($date));
        
        $vtc = $pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT id FROM fuel_transactions WHERE station_id = ? AND DATE(validated_at) = ? AND validated_by = ?
                UNION ALL
                SELECT id FROM merchandise_transactions WHERE station_id = ? AND DATE(validated_at) = ? AND validated_by = ?
                UNION ALL
                SELECT id FROM job_orders WHERE station_id = ? AND DATE(validated_at) = ? AND validated_by = ?
            ) AS daily_validations
        ");
        $vtc->execute([$station_id, $date, $me['id'], $station_id, $date, $me['id'], $station_id, $date, $me['id']]);
        $staff_encoding_trend_counts[] = (int)$vtc->fetchColumn();
    }
    $staff_encoding_trend = [
        'dates' => $staff_encoding_trend_dates,
        'counts' => $staff_encoding_trend_counts
    ];
} catch (Exception $e) {}

// ────────────────────────────────────────────────────────────
// 5. CUSTOMER BALANCES
// ────────────────────────────────────────────────────────────
$customer_balances = [];
$customer_balance_summary = ['total_outstanding' => 0, 'overdue_count' => 0, 'current_count' => 0];

try {
    $cb = $pdo->prepare("
        SELECT c.name,
               c.credit_limit,
               COALESCE(
                   (SELECT SUM(jo.total_cost - COALESCE(jo.amount_paid, 0))
                    FROM job_orders jo
                    WHERE jo.customer_id = c.id
                      AND jo.payment_method IN ('Credit','Account Receivable','utang','Utang')
                      AND jo.payment_status != 'Paid'
                      AND jo.station_id = ?), 0
               ) +
               COALESCE(
                   (SELECT SUM(mt.total_amount - COALESCE(mt.amount_tendered, 0))
                    FROM merchandise_transactions mt
                    WHERE mt.credit_customer_id = c.id
                      AND mt.payment_method IN ('Credit','Account Receivable','utang','Utang')
                      AND mt.station_id = ?), 0
               ) AS outstanding,
               30 AS payment_terms,
               (SELECT MAX(created_at) 
                FROM job_orders 
                WHERE customer_id = c.id AND payment_method IN ('Credit','Account Receivable','utang','Utang')
               ) AS last_credit_date
        FROM customers c
        WHERE c.station_id = ?
        HAVING outstanding > 0
        ORDER BY outstanding DESC
        LIMIT 15
    ");
    $cb->execute([$station_id, $station_id, $station_id]);
    $customer_balances = $cb->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Calculate summary
    foreach ($customer_balances as $cust) {
        $customer_balance_summary['total_outstanding'] += (float)$cust['outstanding'];
        if ($cust['last_credit_date']) {
            $days_overdue = (strtotime('now') - strtotime($cust['last_credit_date'])) / (60 * 60 * 24);
            if ($days_overdue > (int)$cust['payment_terms']) {
                $customer_balance_summary['overdue_count']++;
            } else {
                $customer_balance_summary['current_count']++;
            }
        }
    }
} catch (Exception $e) {}

// ────────────────────────────────────────────────────────────
// 6. QUICK REPORTS SNAPSHOT - KPI Tiles
// ────────────────────────────────────────────────────────────
$kpi_snapshot = [
    'today_sales_total' => 0,
    'today_fuel_sales'  => 0,
    'today_merch_sales' => 0,
    'today_jo_sales'    => 0,
    'low_stock_count'   => 0,
    'pending_deliveries'=> 0,
    'total_variance_count' => 0,
    'staff_on_duty'     => 0,
];

try {
    // Reuse today's sales already computed above
    $kpi_snapshot['today_fuel_sales']  = $today_fuel_sales;
    $kpi_snapshot['today_merch_sales'] = $today_merch_sales;
    $kpi_snapshot['today_jo_sales']    = $today_jo_sales_direct;
    $kpi_snapshot['today_sales_total'] = $today_total_sales;

    // Low Stock Count — use $low_stock_count already computed above
    $kpi_snapshot['low_stock_count'] = $low_stock_count;

    // Pending Deliveries — use already computed value
    $kpi_snapshot['pending_deliveries'] = $pending_deliveries;

    // Variance Count — count active alerts in variance_alerts table
    $vac = $pdo->prepare("SELECT COUNT(*) FROM variance_alerts WHERE station_id=? AND status IN ('open','investigating','escalated')");
    $vac->execute([$station_id]);
    $kpi_snapshot['total_variance_count'] = (int)$vac->fetchColumn();
    // Fallback: if table is empty, count significant variances calculated above
    if ($kpi_snapshot['total_variance_count'] === 0) {
        $sig_fuel_vars = 0;
        foreach ($fuel_variance as $fv_item) {
            if (abs((float)($fv_item['calculated_variance'] ?? 0)) > 10.0) {
                $sig_fuel_vars++;
            }
        }
        $kpi_snapshot['total_variance_count'] = $sig_fuel_vars + count($merch_variance);
    }

    // Staff on Duty — with fallback to any open session
    $sod_kpi = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM labor_sessions WHERE station_id=? AND DATE(start_time)=CURDATE() AND end_time IS NULL");
    $sod_kpi->execute([$station_id]);
    $kpi_snapshot['staff_on_duty'] = (int)$sod_kpi->fetchColumn();
    if ($kpi_snapshot['staff_on_duty'] === 0) {
        $sod2_kpi = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM labor_sessions WHERE station_id=? AND end_time IS NULL");
        $sod2_kpi->execute([$station_id]);
        $kpi_snapshot['staff_on_duty'] = (int)$sod2_kpi->fetchColumn();
    }
} catch (Exception $e) {}

// ────────────────────────────────────────────────────────────
// Station Name
// ────────────────────────────────────────────────────────────
$station_name = 'Station';
try { 
    $sn=$pdo->prepare("SELECT name FROM stations WHERE id=?"); 
    $sn->execute([$station_id]); 
    $station_name=$sn->fetchColumn()?:'Station'; 
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
// --- Recent Transactions from ALL SOURCES ---
// Includes: Fuel, Merchandise Products, Job Orders/Services
$recent_transactions = [];
try {
    $rt = $pdo->prepare("
        SELECT 'Fuel' AS type, 
               CONCAT('FUEL-', id) AS ref,
               CONCAT(COALESCE(liters_sold,0), 'L ', COALESCE(fuel_type,'')) AS description,
               total_amount,
               CASE WHEN transaction_date IS NULL OR transaction_date='0000-00-00' OR transaction_date='0000-00-00 00:00:00' THEN created_at ELSE transaction_date END AS trans_date,
               CASE LOWER(status) WHEN 'verified' THEN 'Verified' WHEN 'pending validation' THEN 'Pending Validation' WHEN 'pending' THEN 'Pending' ELSE COALESCE(status,'') END AS status
        FROM fuel_transactions 
        WHERE station_id = ? AND total_amount > 0
        
        UNION ALL
        
        SELECT 'Merchandise' AS type,
               CONCAT('MERCH-', id) AS ref,
               CONCAT('Merch Sale ₱', COALESCE(total_amount,0)) AS description,
               total_amount,
               created_at AS trans_date,
               COALESCE(validation_status, 'Pending') AS status
        FROM merchandise_transactions
        WHERE station_id = ? AND total_amount > 0
        
        UNION ALL
        
        SELECT 'Job Order/Service' AS type,
               COALESCE(job_order_id, job_order_number, CONCAT('JO-', id)) AS ref,
               COALESCE(service_type, service_description, 'Service') AS description,
               COALESCE(total_cost,0) AS total_amount,
               created_at AS trans_date,
               COALESCE(status,'') AS status
        FROM job_orders
        WHERE station_id = ? AND COALESCE(total_cost,0) > 0
        
        ORDER BY trans_date DESC
        LIMIT 10
    ");
    $rt->execute([$station_id, $station_id, $station_id]);
    $recent_transactions = $rt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// --- Pending Items Requiring Manager Action ---
$pending_actions = [];
try {
    // Count pending validations
    $pv = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND (status='Pending Validation' OR validation_status='Pending Validation')");
    $pv->execute([$station_id]); 
    $pending_validations = (int)$pv->fetchColumn();
    
    // Count pending deliveries
    $pd = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status='Pending Manager Approval'");
    $pd->execute([$station_id]); 
    $pending_deliveries_action = (int)$pd->fetchColumn();
    
    // Count pending stock requests
    $psr = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id=? AND status='Pending'");
    $psr->execute([$station_id]); 
    $pending_stock_requests = (int)$psr->fetchColumn();
    
    // Count pending price proposals (if any)
    $ppp = $pdo->prepare("SELECT COUNT(*) FROM price_proposals WHERE station_id=? AND status='Pending' AND role_required='manager'");
    $ppp->execute([$station_id]); 
    $pending_price_proposals = (int)$ppp->fetchColumn();
    
    $pending_actions = [
        'validations' => $pending_validations,
        'deliveries' => $pending_deliveries_action,
        'stock_requests' => $pending_stock_requests,
        'price_proposals' => $pending_price_proposals,
    ];
} catch (Exception $e) {
    $pending_actions = ['validations' => 0, 'deliveries' => 0, 'stock_requests' => 0, 'price_proposals' => 0];
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>

/* ============================================================
   Manager Dashboard – View Layer Styles
   ============================================================ */
:root {
  --petron-blue: #00264D;
  --petron-red:  #CC0000;
  --success:     #22c55e;
  --warning:     #f59e0b;
  --info:        #3b82f6;
  --purple:      #8b5cf6;
}
.mgr-page { max-width:100%; box-sizing:border-box; overflow-x:hidden; }
.dashboard-content { max-width:100%; box-sizing:border-box; overflow-x:hidden; }

/* KPI Cards — Clean Design with Minimal Colors */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 14px;
  margin-bottom: 24px;
}
@media(max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
@media(max-width: 768px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

.kpi-card {
  background: #fff;
  border-radius: 12px;
  border: 1px solid #e9ecef;
  border-left: 4px solid #002F70;
  padding: 18px 16px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 8px;
  box-shadow: 0 2px 8px rgba(0,0,0,.05);
  transition: transform .15s, box-shadow .15s;
  cursor: default;
  min-height: 100px;
}
.kpi-card:hover { box-shadow: 0 4px 16px rgba(0,47,112,.12); transform: translateY(-2px); }
.kpi-card-top { display: flex; align-items: center; justify-content: flex-start; gap: 12px; width: 100%; }
.kpi-icon {
  width: 36px; height: 36px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
  background: rgba(0,47,112,.08);
  color: #002F70;
}
.kpi-num {
  font-size: 28px;
  font-weight: 800;
  color: #002F70;
  line-height: 1;
  letter-spacing: -0.5px;
}
.kpi-label {
  font-size: 13px;
  font-weight: 600;
  color: #475569;
  line-height: 1.3;
  text-align: left;
  width: 100%;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.kpi-sub {
  font-size: 11px;
  color: #94a3b8;
  margin-top: 2px;
  text-align: left;
  width: 100%;
}

/* Remove all color variants - use single clean design */

/* Section cards */
.mgr-card { background:#fff; border-radius:14px; border:1px solid #EAEAEA; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,.04); margin-bottom:20px; }
.mgr-card h3 { font-size:15px; font-weight:700; color:var(--petron-blue); margin:0 0 16px; display:flex; align-items:center; gap:8px; }
.mgr-card h3 .badge-count { background:var(--petron-blue); color:#fff; font-size:10px; font-weight:800; padding:2px 7px; border-radius:20px; }

/* Charts grid */
.charts-grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
@media(max-width:1200px){ .charts-grid-4{ grid-template-columns:repeat(2,1fr); } }
@media(max-width:600px) { .charts-grid-4{ grid-template-columns:1fr; } }
.charts-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px; }
@media(max-width:900px) { .charts-grid-2{ grid-template-columns:1fr; } }
.charts-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:20px; }
@media(max-width:1100px){ .charts-grid-3{ grid-template-columns:1fr 1fr; } }
@media(max-width:700px) { .charts-grid-3{ grid-template-columns:1fr; } }
.chart-wrap { position:relative; height:220px; }
.chart-wrap-lg { position:relative; height:280px; }
.chart-wrap-sm { position:relative; height:180px; }

/* Status badges - Plain Text Only */
.badge { 
    display: inline-block; 
    padding: 0 !important; 
    margin: 0 !important;
    background: transparent !important; 
    border: none !important;
    font-size: 12px; 
    font-weight: 600; 
    text-transform: uppercase; 
    letter-spacing: .3px; 
}
.badge-pending   { color: #4338ca !important; }
.badge-approved  { color: #0d7d3e !important; }
.badge-inprog    { color: #1976d2 !important; }
.badge-completed { color: #0d7d3e !important; }
.badge-rejected  { color: #c62828 !important; }
.badge-cancelled { color: #616161 !important; }
.badge-default   { color: #616161 !important; }

/* Attendance badges */
.att-active { color: #0d7d3e !important; background: transparent !important; font-weight: 600; }
.att-done   { color: #616161 !important; background: transparent !important; font-weight: 600; }

/* Tables - Standardized Blue Header Design */
.mgr-table { width:100%; border-collapse:collapse; font-size:13px; background: #fff; }
.mgr-table thead tr { background:#002F70 !important; border:none !important; }
.mgr-table th { 
    text-align:left; 
    padding:14px 12px !important; 
    color:#fff !important; 
    font-weight:600; 
    font-size:11px; 
    text-transform:uppercase; 
    letter-spacing:.3px; 
    white-space:nowrap; 
    background:#002F70 !important;
    border:none !important;
}
.mgr-table th:last-child { text-align:center !important; }
.mgr-table td { 
    padding:12px !important; 
    border-bottom:1px solid #e9ecef !important; 
    color:#212529; 
    vertical-align:middle; 
}
.mgr-table td:last-child { text-align:center !important; }
.mgr-table tbody tr:hover td { background:#e3f2fd !important; }
.mgr-table tbody tr { transition:background 0.2s ease; }
.mgr-table tbody tr:last-child td { border-bottom:1px solid #e9ecef !important; }

/* Fuel gauge cards */
.gauge-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; margin-bottom:16px; }
.gauge-card { background:#f8fafc; border-radius:12px; border:1px solid #EAEAEA; padding:14px; text-align:center; }
.gauge-card canvas { display:block; margin:0 auto; }
.gauge-label { font-size:12px; font-weight:700; color:#344054; margin-top:6px; }
.gauge-val   { font-size:11px; color:#667085; margin-top:2px; }

/* Variance bar */
.variance-row { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid #f5f5f5; font-size:13px; }
.variance-bar-wrap { flex:1; background:#e5e7eb; border-radius:20px; height:8px; overflow:hidden; }
.variance-bar-fill { height:100%; border-radius:20px; background:#f59e0b; }

/* Delivery status cards - Clean Design */
.del-status-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:16px; }
@media(max-width:600px){ .del-status-grid{ grid-template-columns:1fr; } }
.del-status-card { 
    background: #fff;
    border-radius:12px; 
    border: 1px solid #e9ecef;
    border-left: 4px solid #002F70;
    padding:18px; 
    text-align:left;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}
.del-status-card .del-num  { font-size:32px; font-weight:800; color: #002F70; }
.del-status-card .del-lbl  { font-size:12px; font-weight:600; color: #475569; text-transform:uppercase; letter-spacing:.4px; margin-top:4px; }

/* Modals */
.mgr-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; }
.mgr-modal-overlay.active { display:flex; }
.mgr-modal { background:#fff; border-radius:16px; padding:28px; width:min(480px,92vw); box-shadow:0 20px 60px rgba(0,0,0,.2); }
.mgr-modal h4 { font-size:17px; font-weight:800; color:var(--petron-blue); margin:0 0 6px; }
.mgr-modal p  { font-size:13px; color:#667085; margin:0 0 18px; }
.mgr-modal label { font-size:12px; font-weight:600; color:#344054; display:block; margin-bottom:4px; }
.mgr-modal textarea { width:100%; padding:10px 12px; border-radius:10px; border:1px solid #EAEAEA; font:inherit; font-size:13px; resize:vertical; min-height:90px; outline:none; }
.mgr-modal textarea:focus { border-color:#8099b3; box-shadow:0 0 0 4px rgba(0,51,102,.1); }
.mgr-modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; }
.btn { padding:9px 20px; border-radius:10px; border:none; font:inherit; font-size:13px; font-weight:700; cursor:pointer; transition:.2s; }
.btn-primary { background:var(--petron-blue); color:#fff; }
.btn-primary:hover { background:#003d7a; }
.btn-success { background:#22c55e; color:#fff; }
.btn-success:hover { background:#16a34a; }
.btn-danger  { background:var(--petron-red); color:#fff; }
.btn-danger:hover  { background:#a00000; }
.btn-ghost   { background:#f3f4f6; color:#374151; }
.btn-ghost:hover   { background:#e5e7eb; }
.btn-sm { padding:5px 12px; font-size:12px; border-radius:8px; }

/* Pagination */
.pagination { display:flex; gap:6px; align-items:center; margin-top:14px; flex-wrap:wrap; }
.page-btn { padding:5px 12px; border-radius:8px; border:1px solid #EAEAEA; background:#f8fafc; color:#344054; font-size:12px; font-weight:600; text-decoration:none; transition:.2s; }
.page-btn.active, .page-btn:hover { background:var(--petron-blue); color:#fff; border-color:var(--petron-blue); }
.page-btn.disabled { opacity:.4; pointer-events:none; }

/* Filter bar */
.filter-bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
.filter-bar input, .filter-bar select { padding:7px 12px; border-radius:9px; border:1px solid #EAEAEA; font:inherit; font-size:13px; background:#fff; outline:none; }
.filter-bar input:focus, .filter-bar select:focus { border-color:#8099b3; }

/* Flash */
.flash-card { padding:12px 16px; border-radius:10px; margin-bottom:14px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:8px; }
.flash-success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; }
.flash-error   { background:#FEE2E2; color:#991B1B; border:1px solid #FECACA; }

/* Refresh indicator */
.refresh-dot { width:8px; height:8px; border-radius:50%; background:#22c55e; display:inline-block; margin-left:6px; animation:pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

/* Attendance badge */
.att-active { background:#dcfce7; color:#166534; }
.att-done   { background:#f3f4f6; color:#374151; }

/* Horizontal bar chart container */
.hbar-wrap { display:flex; flex-direction:column; gap:8px; }
.hbar-row  { display:flex; align-items:center; gap:10px; font-size:12px; }
.hbar-label{ width:120px; flex-shrink:0; color:#344054; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.hbar-track{ flex:1; background:#e5e7eb; border-radius:20px; height:10px; overflow:hidden; }
.hbar-fill { height:100%; border-radius:20px; background:var(--petron-blue); transition:width .4s; }
.hbar-val  { width:80px; text-align:right; color:#667085; flex-shrink:0; }
</style>
<div class="dashboard-content dashboard-wrapper">

<!-- Page Header -->
<div class="page-head">
  <div>
    <h1 class="h1" style="font-size:20px;font-weight:bold;color:#00264D;"><i class="fas fa-gauge" style="margin-right:8px"></i>MY DASHBOARD<span class="refresh-dot" id="refreshDot" title="Auto-refresh active"></span></h1>
    <div class="sub" style="font-size:13px;opacity:.85;color:#6c757d;font-weight:bold;">WELCOME BACK, <?php echo $display_name; ?></div>
  </div>
  <div class="header-actions" style="display:flex;gap:8px;align-items:center;">
    <span id="lastRefreshLabel" style="font-size:11px;color:#9ca3af;"></span>
  </div>
</div>

<!-- Quick Links Panel -->
<?php if ($flash_success): ?>
<div class="flash-card flash-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="flash-card flash-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<?php
// ── Manager inventory flow alerts ─────────────────────────────────────────────
$mgr_pending_sr = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id=? AND status='Pending'");
    $s->execute([$station_id]);
    $mgr_pending_sr = (int)$s->fetchColumn();
} catch (Exception $e) {}
$mgr_pending_po = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status='Pending Admin Validation' AND type='merch' AND admin_finalized=0");
    $s->execute([$station_id]);
    $mgr_pending_po = (int)$s->fetchColumn();
} catch (Exception $e) {}
?>
<!-- ============================================================
     SECTION 6: QUICK REPORTS SNAPSHOT - KPI Tiles
     ============================================================ -->
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px;">
  
  <!-- Sales Total -->
  <div class="kpi-card">
    <div class="kpi-card-top">
      <div class="kpi-icon" style="background:rgba(0,47,112,0.08);color:#002F70;"><i class="fas fa-peso-sign"></i></div>
      <div class="kpi-num" style="color:#002F70;">&#8369;<?= number_format($kpi_snapshot['today_sales_total'], 0) ?></div>
    </div>
    <div class="kpi-label">Sales Today</div>
    <div class="kpi-sub">
      <?php if ($kpi_snapshot['today_sales_total'] > 0): ?>
        Fuel: &#8369;<?= number_format($kpi_snapshot['today_fuel_sales'],0) ?> |
        Merch: &#8369;<?= number_format($kpi_snapshot['today_merch_sales'],0) ?> |
        Svc: &#8369;<?= number_format($kpi_snapshot['today_jo_sales'],0) ?>
      <?php else: ?>
        <span style="color:#f59e0b;">No txn today</span> &bull; 7d: &#8369;<?= number_format($week_total_sales, 0) ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Inventory Status -->
  <div class="kpi-card">
    <div class="kpi-card-top">
      <div class="kpi-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="fas fa-boxes"></i></div>
      <div class="kpi-num" style="color:#f59e0b;"><?= number_format($kpi_snapshot['low_stock_count']) ?></div>
    </div>
    <div class="kpi-label">Low Stock Items</div>
    <div class="kpi-sub">Fuel + Products</div>
  </div>

  <!-- Deliveries -->
  <div class="kpi-card">
    <div class="kpi-card-top">
      <div class="kpi-icon" style="background:rgba(0,47,112,0.08);color:#002F70;"><i class="fas fa-truck"></i></div>
      <div class="kpi-num" style="color:#002F70;"><?= number_format($kpi_snapshot['pending_deliveries']) ?></div>
    </div>
    <div class="kpi-label">Pending Deliveries</div>
    <div class="kpi-sub">For approval</div>
  </div>

  <!-- Variance Count -->
  <div class="kpi-card">
    <div class="kpi-card-top">
      <div class="kpi-icon" style="background:rgba(220,38,38,0.1);color:#dc2626;"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="kpi-num" style="color:#dc2626;"><?= number_format($kpi_snapshot['total_variance_count']) ?></div>
    </div>
    <div class="kpi-label">Variance Alerts</div>
    <div class="kpi-sub">Review needed</div>
  </div>

  <!-- Staff on Duty -->
  <div class="kpi-card">
    <div class="kpi-card-top">
      <div class="kpi-icon" style="background:rgba(0,47,112,0.08);color:#002F70;"><i class="fas fa-user-clock"></i></div>
      <div class="kpi-num" style="color:#002F70;"><?= number_format($kpi_snapshot['staff_on_duty']) ?></div>
    </div>
    <div class="kpi-label">Staff on Duty</div>
    <div class="kpi-sub">Currently active</div>
  </div>

  <!-- Validations Today -->
  <div class="kpi-card">
    <div class="kpi-card-top">
      <div class="kpi-icon" style="background:rgba(34,197,94,0.1);color:#22c55e;"><i class="fas fa-clipboard-check"></i></div>
      <div class="kpi-num" style="color:#22c55e;"><?= number_format($validated_counts['today']) ?></div>
    </div>
    <div class="kpi-label">Validated Today</div>
    <div class="kpi-sub">Week: <?= number_format($validated_counts['week']) ?></div>
  </div>

</div><!-- /quick-reports-snapshot -->

<!-- ============================================================
     SECTION 1: VALIDATION QUEUE
     ============================================================ -->
<div class="mgr-card">
  <h3><i class="fas fa-clipboard-list"></i> Validation Queue 
    <span class="badge-count" style="background:#002F70;">
      <?= $validation_queue['pending_transactions'] + $validation_queue['pending_deliveries'] + $validation_queue['pending_stock_requests'] ?>
    </span>
  </h3>
  
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:16px;">
    
    <!-- Left: 3 Tiles -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
      
      <!-- Pending Transactions -->
      <a href="#transactions-section" style="text-decoration:none;background:#fff;border:3px solid #002F70;border-radius:16px;padding:24px;display:flex;align-items:center;gap:20px;transition:all 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,47,112,0.15)'" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='none'">
        <div style="width:60px;height:60px;border-radius:12px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fas fa-file-invoice-dollar" style="font-size:28px;color:#002F70;"></i>
        </div>
        <div style="flex:1;">
          <div style="font-size:36px;font-weight:800;color:#002F70;line-height:1;"><?= $validation_queue['pending_transactions'] ?></div>
          <div style="font-size:11px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:0.5px;margin-top:6px;">Transactions</div>
          <div style="font-size:10px;color:#64748b;margin-top:2px;font-weight:500;">
            F: <?= $validation_queue['pending_fuel_tx'] ?> | 
            M: <?= $validation_queue['pending_merch_tx'] ?> | 
            JO: <?= $validation_queue['pending_jo'] ?>
          </div>
        </div>
      </a>

      <!-- Pending Deliveries -->
      <a href="manager_merchandise_deliveries.php" style="text-decoration:none;background:#fff;border:3px solid #002F70;border-radius:16px;padding:24px;display:flex;align-items:center;gap:20px;transition:all 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,47,112,0.15)'" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='none'">
        <div style="width:60px;height:60px;border-radius:12px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fas fa-truck-loading" style="font-size:28px;color:#002F70;"></i>
        </div>
        <div style="flex:1;">
          <div style="font-size:36px;font-weight:800;color:#002F70;line-height:1;"><?= $validation_queue['pending_deliveries'] ?></div>
          <div style="font-size:11px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:0.5px;margin-top:6px;">Deliveries</div>
          <div style="font-size:10px;color:#64748b;margin-top:2px;font-weight:500;">Awaiting approval</div>
        </div>
      </a>

      <!-- Pending Stock Requests -->
      <a href="manager_inventory_merchandise.php" style="text-decoration:none;background:#fff;border:3px solid #002F70;border-radius:16px;padding:24px;display:flex;align-items:center;gap:20px;transition:all 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,47,112,0.15)'" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='none'">
        <div style="width:60px;height:60px;border-radius:12px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fas fa-boxes" style="font-size:28px;color:#002F70;"></i>
        </div>
        <div style="flex:1;">
          <div style="font-size:36px;font-weight:800;color:#002F70;line-height:1;"><?= $validation_queue['pending_stock_requests'] ?></div>
          <div style="font-size:11px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:0.5px;margin-top:6px;">Stock Requests</div>
          <div style="font-size:10px;color:#64748b;margin-top:2px;font-weight:500;">Need review</div>
        </div>
      </a>
    
    </div>
    
    <!-- Right: Pie Chart -->
    <div style="background:#fff;border:3px solid #002F70;border-radius:16px;padding:20px;">
      <h4 style="font-size:13px;font-weight:700;color:#002F70;margin:0 0 12px;text-align:center;">Validation Queue Distribution</h4>
      <div class="chart-wrap-sm">
        <canvas id="chartValidationQueue"></canvas>
      </div>
    </div>

  </div>
</div>

<!-- ============================================================
     SECTION 2: VALIDATED RECORDS
     ============================================================ -->
<div class="mgr-card">
  <h3><i class="fas fa-check-double"></i> Validated Records 
    <span style="font-size:12px;font-weight:400;color:#667085;margin-left:auto;">
      Today: <?= $validated_counts['today'] ?> | Week: <?= $validated_counts['week'] ?> | Month: <?= $validated_counts['month'] ?>
    </span>
  </h3>
  
  <!-- Chart: Approved Transactions vs Deliveries -->
  <div class="chart-wrap-lg" style="margin-bottom:16px;">
    <canvas id="chartValidatedTrend"></canvas>
  </div>

  <!-- Recent Validated Records Table -->
  <?php if (empty($validated_records)): ?>
    <p style="color:#9ca3af;text-align:center;padding:24px 0;font-size:13px;">
      <i class="fas fa-check-circle"></i> No validated records yet.
    </p>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <table class="mgr-table">
      <thead>
        <tr>
          <th>Type</th>
          <th>Reference</th>
          <th>Description</th>
          <th>Amount</th>
          <th>Validated At</th>
          <th>Validated By</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (array_slice($validated_records, 0, 10) as $vr): ?>
      <?php
        $type_color = match($vr['type']) {
          'Transaction' => '#3b82f6',
          'Job Order' => '#059669',
          'Delivery' => '#8b5cf6',
          default => '#64748b'
        };
        $type_bg = match($vr['type']) {
          'Transaction' => '#dbeafe',
          'Job Order' => '#d1fae5',
          'Delivery' => '#f3e8ff',
          default => '#f1f5f9'
        };
      ?>
      <tr>
        <td>
          <span style="font-size:10px;font-weight:700;color:<?= $type_color ?>;background:<?= $type_bg ?>;padding:3px 8px;border-radius:20px;">
            <?= htmlspecialchars($vr['type']) ?>
          </span>
        </td>
        <td><strong><?= htmlspecialchars($vr['ref']) ?></strong></td>
        <td><?= htmlspecialchars($vr['description']) ?></td>
        <td style="font-weight:600;color:#002F70;">
          <?= $vr['total_amount'] > 0 ? '&#8369;' . number_format((float)$vr['total_amount'], 2) : '—' ?>
        </td>
        <td style="font-size:12px;"><?= date('M j, Y h:i A', strtotime($vr['validated_at'])) ?></td>
        <td style="font-size:12px;">
          <?php
          try {
            $vu = $pdo->prepare("SELECT name FROM users WHERE id=?");
            $vu->execute([$vr['validated_by']]);
            echo htmlspecialchars($vu->fetchColumn() ?: 'System');
          } catch (Exception $e) {
            echo 'System';
          }
          ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ============================================================
     SECTION 3: VARIANCE PANEL
     ============================================================ -->
<div id="variance-section" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
  
  <!-- Fuel Variance -->
  <div class="mgr-card" style="margin-bottom:0;">
    <h3><i class="fas fa-gas-pump"></i> Fuel Variance Analysis (Last 7 Days)</h3>
    <?php if (empty($fuel_variance)): ?>
      <p style="color:#22c55e;text-align:center;padding:24px 0;font-size:13px;">
        <i class="fas fa-check-circle"></i> No fuel variance detected.
      </p>
    <?php else: ?>
    <div class="chart-wrap" style="margin-bottom:14px;">
      <canvas id="chartFuelVariance"></canvas>
    </div>
    <div style="overflow-x:auto;">
      <table class="mgr-table">
        <thead>
          <tr>
            <th>Fuel Type</th>
            <th>Sales (L)</th>
            <th>Tank (L)</th>
            <th>Delivered (L)</th>
            <th>Variance</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($fuel_variance as $fv): ?>
        <?php
          $variance = (float)$fv['calculated_variance'];
          $variance_class = abs($variance) > 100 ? 'badge-rejected' : (abs($variance) > 50 ? 'badge-pending' : 'badge-approved');
        ?>
        <tr>
          <td><strong><?= htmlspecialchars($fv['fuel_type']) ?></strong></td>
          <td><?= number_format((float)$fv['sales_liters'], 2) ?></td>
          <td><?= number_format((float)$fv['tank_level'], 2) ?></td>
          <td><?= number_format((float)$fv['delivered_liters'], 2) ?></td>
          <td><span class="badge <?= $variance_class ?>"><?= number_format($variance, 2) ?> L</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Merchandise Variance -->
  <div class="mgr-card" style="margin-bottom:0;">
    <h3><i class="fas fa-boxes"></i> Merchandise Variance (Last 7 Days)</h3>
    <?php if (empty($merch_variance)): ?>
      <p style="color:#22c55e;text-align:center;padding:24px 0;font-size:13px;">
        <i class="fas fa-check-circle"></i> No significant variance.
      </p>
    <?php else: ?>
    <div class="chart-wrap" style="margin-bottom:14px;">
      <canvas id="chartMerchVariance"></canvas>
    </div>
    <div style="overflow-x:auto;">
      <table class="mgr-table">
        <thead>
          <tr>
            <th>Product</th>
            <th>Stock</th>
            <th>Sales (7d)</th>
            <th>Variance</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($merch_variance as $mv): ?>
        <?php
          $variance = (float)$mv['variance'];
          $variance_class = abs($variance) > 20 ? 'badge-rejected' : (abs($variance) > 10 ? 'badge-pending' : 'badge-default');
        ?>
        <tr>
          <td><strong><?= htmlspecialchars($mv['product_name']) ?></strong></td>
          <td><?= number_format((float)$mv['current_stock'], 0) ?></td>
          <td><?= number_format((float)$mv['sales_quantity'], 0) ?></td>
          <td><span class="badge <?= $variance_class ?>"><?= number_format($variance, 0) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- ============================================================
     SECTION 4: STAFF ACTIVITY SUMMARY
     ============================================================ -->
<div class="mgr-card">
  <h3><i class="fas fa-users-cog"></i> Staff Activity Summary (This Month)</h3>
  
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;">
    
    <div>
      <h4 style="font-size:13px;font-weight:700;color:#475569;margin-bottom:12px;">Transactions per Staff</h4>
      <?php if (empty($staff_activity)): ?>
        <p style="color:#9ca3af;text-align:center;padding:24px 0;font-size:13px;">
          <i class="fas fa-users"></i> No staff activity data.
        </p>
      <?php else: ?>
      <div class="chart-wrap-sm" style="margin-bottom:14px;">
        <canvas id="chartStaffActivity"></canvas>
      </div>
      <div style="overflow-x:auto;">
        <table class="mgr-table">
          <thead>
            <tr>
              <th>Staff</th>
              <th>Fuel</th>
              <th>Merch</th>
              <th>JO</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($staff_activity as $sa): ?>
          <tr>
            <td><strong><?= htmlspecialchars($sa['staff_name']) ?></strong></td>
            <td><?= number_format((int)$sa['fuel_txn']) ?></td>
            <td><?= number_format((int)$sa['merch_txn']) ?></td>
            <td><?= number_format((int)$sa['jo_created']) ?></td>
            <td><strong><?= number_format((int)$sa['total_encodings']) ?></strong></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div>
      <h4 style="font-size:13px;font-weight:700;color:#475569;margin-bottom:12px;">Your Validations (7 Days)</h4>
      <div class="chart-wrap-sm">
        <canvas id="chartValidationTrend"></canvas>
      </div>
      <div style="text-align:center;margin-top:12px;padding:12px;background:#f8fafc;border-radius:8px;">
        <div style="font-size:20px;font-weight:800;color:#002F70;">
          <?= array_sum($staff_encoding_trend['counts']) ?>
        </div>
        <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;">
          Total Validations
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ============================================================
     SECTION 5: CUSTOMER BALANCES
     ============================================================ -->
<div class="mgr-card">
  <h3><i class="fas fa-user-tag"></i> Customer Outstanding Balances
    <span style="font-size:12px;font-weight:400;color:#667085;margin-left:auto;">
      Total: &#8369;<?= number_format($customer_balance_summary['total_outstanding'], 2) ?> | 
      Overdue: <?= $customer_balance_summary['overdue_count'] ?> | 
      Current: <?= $customer_balance_summary['current_count'] ?>
    </span>
  </h3>
  
  <?php if (empty($customer_balances)): ?>
    <p style="color:#22c55e;text-align:center;padding:24px 0;font-size:13px;">
      <i class="fas fa-check-circle"></i> No outstanding balances.
    </p>
  <?php else: ?>
  
  <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px;margin-bottom:16px;">
    
    <div>
      <h4 style="font-size:13px;font-weight:700;color:#475569;margin-bottom:12px;text-align:center;">Balance Status</h4>
      <div class="chart-wrap-sm">
        <canvas id="chartCustomerStatus"></canvas>
      </div>
    </div>

    <div>
      <h4 style="font-size:13px;font-weight:700;color:#475569;margin-bottom:12px;">Top Customer Balances</h4>
      <div class="chart-wrap-sm">
        <canvas id="chartCustomerBalances"></canvas>
      </div>
    </div>

  </div>

  <div style="overflow-x:auto;">
    <table class="mgr-table">
      <thead>
        <tr>
          <th>Customer</th>
          <th>Outstanding</th>
          <th>Credit Limit</th>
          <th>Utilization</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($customer_balances as $cb): ?>
      <?php
        $outstanding = (float)$cb['outstanding'];
        $limit = (float)$cb['credit_limit'];
        $util = $limit > 0 ? min(100, round($outstanding / $limit * 100)) : 100;
        $util_color = $util >= 90 ? '#dc3545' : ($util >= 70 ? '#f59e0b' : '#22c55e');
        
        $is_overdue = false;
        if ($cb['last_credit_date']) {
          $days_since = (strtotime('now') - strtotime($cb['last_credit_date'])) / (60 * 60 * 24);
          $is_overdue = $days_since > (int)$cb['payment_terms'];
        }
        $status_class = $is_overdue ? 'badge-rejected' : 'badge-approved';
        $status_text = $is_overdue ? 'Overdue' : 'Current';
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($cb['name']) ?></strong></td>
        <td style="color:#dc3545;font-weight:700;">&#8369;<?= number_format($outstanding, 2) ?></td>
        <td>&#8369;<?= number_format($limit, 2) ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:6px;">
            <div style="flex:1;background:#e5e7eb;border-radius:20px;height:6px;overflow:hidden;">
              <div style="width:<?= $util ?>%;height:100%;background:<?= $util_color ?>;border-radius:20px;"></div>
            </div>
            <span style="font-size:11px;color:<?= $util_color ?>;font-weight:700;"><?= $util ?>%</span>
          </div>
        </td>
        <td><span class="badge <?= $status_class ?>"><?= $status_text ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ============================================================
     SECTION 7: AUDIT TRAIL
     ============================================================ -->
<div class="mgr-card">
  <h3><i class="fas fa-history"></i> Audit Trail - Recent Manager Actions
    <a href="manager_audit_trail.php" style="float:right;font-size:11px;font-weight:600;color:#002F70;text-decoration:none;text-transform:uppercase;letter-spacing:0.3px;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
      <i class="fas fa-external-link-alt"></i> View Full Log
    </a>
  </h3>
    
    <?php if (empty($audit_trail)): ?>
      <p style="color:#9ca3af;text-align:center;padding:24px 0;font-size:13px;">
        <i class="fas fa-clipboard-list"></i> No recent audit entries.
      </p>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="mgr-table">
        <thead>
          <tr>
            <th>Action</th>
            <th>Details</th>
            <th>Date & Time</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($audit_trail as $log): ?>
        <?php
          $action_badge_color = match(true) {
            str_contains(strtoupper($log['action']), 'APPROVE') => 'badge-approved',
            str_contains(strtoupper($log['action']), 'REJECT') => 'badge-rejected',
            str_contains(strtoupper($log['action']), 'VALIDATE') => 'badge-approved',
            str_contains(strtoupper($log['action']), 'CREATE') => 'badge-inprog',
            default => 'badge-default'
          };
        ?>
        <tr>
          <td><span class="badge <?= $action_badge_color ?>"><?= htmlspecialchars($log['action']) ?></span></td>
          <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($log['details']) ?>">
            <?= htmlspecialchars($log['details']) ?>
          </td>
          <td style="white-space:nowrap;font-size:12px;"><?= date('M j, Y h:i A', strtotime($log['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    
    <div style="margin-top:16px;padding:12px;background:#f8fafc;border-radius:8px;text-align:center;">
      <p style="font-size:11px;color:#64748b;margin:0;">
        <i class="fas fa-info-circle"></i> 
        Showing last 5 actions. View full audit trail for complete history including staff encoding logs.
      </p>
    </div>
</div>

</div><!-- /dashboard-wrapper -->

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "system-ui,-apple-system,Segoe UI,Roboto,Arial";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#667085';

const C = {blue:'#002F70',red:'#dc3545',green:'#22c55e',yellow:'#f59e0b',gray:'#6b7280'};
const D = {
  appTrendDates:<?=json_encode($approved_trend_dates)?>,
  appTrendTxns:<?=json_encode($approved_trend_transactions)?>,
  appTrendDels:<?=json_encode($approved_trend_deliveries)?>,
  fuelTypes:<?=json_encode(array_column($fuel_variance,'fuel_type'))?>,
  fuelSales:<?=json_encode(array_map('floatval',array_column($fuel_variance,'sales_liters')))?>,
  fuelTank:<?=json_encode(array_map('floatval',array_column($fuel_variance,'tank_level')))?>,
  fuelDel:<?=json_encode(array_map('floatval',array_column($fuel_variance,'delivered_liters')))?>,
  merchProds:<?=json_encode(array_column($merch_variance,'product_name'))?>,
  merchStock:<?=json_encode(array_map('floatval',array_column($merch_variance,'current_stock')))?>,
  merchSales:<?=json_encode(array_map('floatval',array_column($merch_variance,'sales_quantity')))?>,
  staffNames:<?=json_encode(array_column($staff_activity,'staff_name'))?>,
  staffFuel:<?=json_encode(array_map('intval',array_column($staff_activity,'fuel_txn')))?>,
  staffMerch:<?=json_encode(array_map('intval',array_column($staff_activity,'merch_txn')))?>,
  staffJO:<?=json_encode(array_map('intval',array_column($staff_activity,'jo_created')))?>,
  valDates:<?=json_encode($staff_encoding_trend['dates'])?>,
  valCounts:<?=json_encode($staff_encoding_trend['counts'])?>,
  custNames:<?=json_encode(array_column($customer_balances,'name'))?>,
  custOut:<?=json_encode(array_map('floatval',array_column($customer_balances,'outstanding')))?>,
  custOver:<?=$customer_balance_summary['overdue_count']?>,
  custCurr:<?=$customer_balance_summary['current_count']?>,
  // Validation Queue data
  valQueueFuel:<?=$validation_queue['pending_fuel_tx']?>,
  valQueueMerch:<?=$validation_queue['pending_merch_tx']?>,
  valQueueJO:<?=$validation_queue['pending_jo']?>,
};

// 0. Validation Queue Pie Chart
if(document.getElementById('chartValidationQueue')){
new Chart(document.getElementById('chartValidationQueue'),{type:'doughnut',data:{labels:['Fuel Transactions','Merchandise','Job Orders'],datasets:[{data:[D.valQueueFuel,D.valQueueMerch,D.valQueueJO],backgroundColor:[C.blue,C.yellow,C.green],borderWidth:2,borderColor:'#fff'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:12,padding:10,font:{size:10}}}},cutout:'55%'}});
}

// 1. Validated Trend
if(document.getElementById('chartValidatedTrend')){
new Chart(document.getElementById('chartValidatedTrend'),{type:'bar',data:{labels:D.appTrendDates,datasets:[{label:'Transactions',data:D.appTrendTxns,backgroundColor:C.blue,borderRadius:6},{label:'Deliveries',data:D.appTrendDels,backgroundColor:C.gray,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:10,padding:8}}},scales:{x:{grid:{display:false}},y:{grid:{color:'#f0f0f0'},ticks:{stepSize:1}}}}});
}

// 2. Fuel Variance
if(document.getElementById('chartFuelVariance')&&D.fuelTypes.length>0){
new Chart(document.getElementById('chartFuelVariance'),{type:'line',data:{labels:D.fuelTypes,datasets:[{label:'Sales (L)',data:D.fuelSales,borderColor:C.blue,backgroundColor:'rgba(0,47,112,0.1)',fill:true,tension:0.4},{label:'Tank (L)',data:D.fuelTank,borderColor:C.green,backgroundColor:'rgba(34,197,94,0.1)',fill:true,tension:0.4},{label:'Delivered (L)',data:D.fuelDel,borderColor:C.yellow,backgroundColor:'rgba(245,158,11,0.1)',fill:true,tension:0.4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:10,padding:8}}},scales:{x:{grid:{display:false}},y:{grid:{color:'#f0f0f0'}}}}});
}

// 3. Merch Variance
if(document.getElementById('chartMerchVariance')&&D.merchProds.length>0){
new Chart(document.getElementById('chartMerchVariance'),{type:'bar',data:{labels:D.merchProds,datasets:[{label:'Stock',data:D.merchStock,backgroundColor:C.blue,borderRadius:4},{label:'Sales',data:D.merchSales,backgroundColor:C.yellow,borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:10,padding:8}}},scales:{x:{grid:{display:false}},y:{grid:{color:'#f0f0f0'}}}}});
}

// 4. Staff Activity
if(document.getElementById('chartStaffActivity')&&D.staffNames.length>0){
new Chart(document.getElementById('chartStaffActivity'),{type:'bar',data:{labels:D.staffNames,datasets:[{label:'Fuel',data:D.staffFuel,backgroundColor:C.blue,borderRadius:4},{label:'Merch',data:D.staffMerch,backgroundColor:C.gray,borderRadius:4},{label:'JO',data:D.staffJO,backgroundColor:C.green,borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:10,padding:8}}},scales:{x:{grid:{display:false}},y:{grid:{color:'#f0f0f0'},ticks:{stepSize:1}}}}});
}

// 5. Validation Trend
if(document.getElementById('chartValidationTrend')){
new Chart(document.getElementById('chartValidationTrend'),{type:'line',data:{labels:D.valDates,datasets:[{label:'Validations',data:D.valCounts,borderColor:C.green,backgroundColor:'rgba(34,197,94,0.1)',fill:true,tension:0.4,pointRadius:3,borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{grid:{color:'#f0f0f0'},ticks:{stepSize:1}}}}});
}

// 6. Customer Status
if(document.getElementById('chartCustomerStatus')){
new Chart(document.getElementById('chartCustomerStatus'),{type:'doughnut',data:{labels:['Overdue','Current'],datasets:[{data:[D.custOver,D.custCurr],backgroundColor:[C.red,C.green],borderWidth:2,borderColor:'#fff'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{boxWidth:10,padding:10}}},cutout:'60%'}});
}

// 7. Customer Balances
if(document.getElementById('chartCustomerBalances')&&D.custNames.length>0){
new Chart(document.getElementById('chartCustomerBalances'),{type:'bar',data:{labels:D.custNames.slice(0,10),datasets:[{label:'Outstanding',data:D.custOut.slice(0,10),backgroundColor:C.red,borderRadius:4}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:'#f0f0f0'}},y:{grid:{display:false}}}}});
}


</script>

<div style="height:80px;"></div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>  <form method="GET" action="manager_dashboard.php" class="filter-bar">
    <label style="font-size:12px;font-weight:600;color:#475569;">Status:</label>
    <select name="jo_status" style="min-width:160px;">
      <option value="">All Statuses</option>
      <?php
      $jo_statuses = ['Pending Validation','Approved','Validated','In Progress','Completed','Rejected','Cancelled'];
      foreach ($jo_statuses as $st):
        $sel = ($jo_filter_status === $st) ? 'selected' : '';
      ?>
      <option value="<?= htmlspecialchars($st) ?>" <?= $sel ?>><?= htmlspecialchars($st) ?></option>
      <?php endforeach; ?>
    </select>
    
    <label style="font-size:12px;font-weight:600;color:#475569;">Customer:</label>
    <input type="text" name="jo_customer" placeholder="Search customer name..." value="<?= htmlspecialchars($jo_filter_customer) ?>" style="min-width:200px;">
    
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
    <?php if ($jo_filter_status || $jo_filter_customer): ?>
      <a href="manager_dashboard.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Clear Filters</a>
    <?php endif; ?>
  </form>

  <?php if ($jo_filter_status || $jo_filter_customer): ?>
    <div style="padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-bottom:12px;font-size:12px;color:#1e40af;">
      <i class="fas fa-info-circle"></i> 
      Showing <strong><?= number_format($jo_total_filtered) ?></strong> result<?= $jo_total_filtered != 1 ? 's' : '' ?>
      <?php if ($jo_filter_status): ?>
        with status "<strong><?= htmlspecialchars($jo_filter_status) ?></strong>"
      <?php endif; ?>
      <?php if ($jo_filter_customer): ?>
        <?= $jo_filter_status ? 'and' : 'for' ?> customer matching "<strong><?= htmlspecialchars($jo_filter_customer) ?></strong>"
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div style="overflow-x:auto">
  <table class="mgr-table">
    <thead>
      <tr>
        <th>JO Ref</th>
        <th>Customer</th>
        <th>Vehicle</th>
        <th>Service</th>
        <th>Mechanic</th>
        <th>Status</th>
        <th>Payment</th>
        <th>Date</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($job_order_rows)): ?>
      <tr><td colspan="8" style="text-align:center;padding:24px;color:#9ca3af;font-size:13px;">
        <i class="fas fa-clipboard-list"></i> No job orders found.
      </td></tr>
    <?php else: ?>
      <?php foreach ($job_order_rows as $jo): ?>
      <?php
        $ds = $jo['display_status'] ?? $jo['status'] ?? '';
        $badge_class = match(true) {
          str_contains($ds,'Pending')   => 'badge-pending',
          str_contains($ds,'Approved') || str_contains($ds,'Validated') => 'badge-approved',
          str_contains($ds,'Progress') => 'badge-inprog',
          str_contains($ds,'Completed')=> 'badge-completed',
          str_contains($ds,'Rejected') => 'badge-rejected',
          str_contains($ds,'Cancelled')=> 'badge-cancelled',
          default => 'badge-default',
        };
        $can_act = !in_array($jo['status'], ['In Progress','Completed','Cancelled']);
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($jo['jo_ref']) ?></strong></td>
        <td><?= htmlspecialchars($jo['customer']) ?></td>
        <td><?= htmlspecialchars($jo['vehicle_plate']) ?></td>
        <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($jo['service_type']) ?>"><?= htmlspecialchars($jo['service_type']) ?></td>
        <td><?= htmlspecialchars($jo['mechanic']) ?></td>
        <td><span class="badge <?= $badge_class ?>"><?= htmlspecialchars($ds) ?></span></td>
        <td><?= htmlspecialchars($jo['payment_method'] ?? '—') ?></td>
        <td style="white-space:nowrap"><?= date('M j, Y', strtotime($jo['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <!-- Pagination -->
  <?php
    $jo_total_pages = max(1, (int)ceil($jo_total_filtered / $jo_per_page));
    $base_url = 'manager_dashboard.php?jo_status=' . urlencode($jo_filter_status) . '&jo_customer=' . urlencode($jo_filter_customer);
  ?>
  <?php if ($jo_total_pages > 1): ?>
  <div class="pagination">
    <a href="<?= $base_url ?>&jo_page=<?= max(1,$jo_page-1) ?>" class="page-btn <?= $jo_page<=1?'disabled':'' ?>"><i class="fas fa-chevron-left"></i></a>
    <?php for ($p=1;$p<=$jo_total_pages;$p++): ?>
      <?php if ($p==1 || $p==$jo_total_pages || abs($p-$jo_page)<=2): ?>
        <a href="<?= $base_url ?>&jo_page=<?= $p ?>" class="page-btn <?= $p==$jo_page?'active':'' ?>"><?= $p ?></a>
      <?php elseif (abs($p-$jo_page)==3): ?>
        <span class="page-btn disabled">…</span>
      <?php endif; ?>
    <?php endfor; ?>
    <a href="<?= $base_url ?>&jo_page=<?= min($jo_total_pages,$jo_page+1) ?>" class="page-btn <?= $jo_page>=$jo_total_pages?'disabled':'' ?>"><i class="fas fa-chevron-right"></i></a>
    <span style="font-size:12px;color:#9ca3af;margin-left:6px">Page <?= $jo_page ?> of <?= $jo_total_pages ?> (<?= $jo_total_filtered ?> records)</span>
  </div>
  <?php endif; ?>
</div><!-- /job orders card -->

<!-- Manager Action Items Alert -->
<?php 
$total_pending_actions = $pending_actions['validations'] + $pending_actions['deliveries'] + $pending_actions['stock_requests'] + $pending_actions['price_proposals'];
if ($total_pending_actions > 0): 
?>
<div class="mgr-card" style="background:#fef3c7;border:2px solid #f59e0b;margin-bottom:20px;">
  <h3 style="color:#92400e;margin-bottom:14px;">
    <i class="fas fa-exclamation-circle" style="color:#f59e0b;"></i> 
    Action Required
    <span class="badge-count" style="background:#f59e0b;"><?= $total_pending_actions ?></span>
  </h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
    
    <?php if ($pending_actions['validations'] > 0): ?>
    <a href="manager_dashboard.php?jo_status=Pending+Validation#job-orders" style="text-decoration:none;background:#fff;border:1px solid #fed7aa;border-radius:10px;padding:14px;display:flex;align-items:center;gap:12px;transition:all 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(245,158,11,0.2)'" onmouseout="this.style.boxShadow='none'">
      <div style="width:40px;height:40px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-clipboard-check" style="font-size:18px;color:#f59e0b;"></i>
      </div>
      <div>
        <div style="font-size:22px;font-weight:800;color:#92400e;"><?= $pending_actions['validations'] ?></div>
        <div style="font-size:11px;font-weight:600;color:#78350f;text-transform:uppercase;">JO Validations</div>
      </div>
    </a>
    <?php endif; ?>
    
    <?php if ($pending_actions['deliveries'] > 0): ?>
    <a href="manager_merchandise_deliveries.php" style="text-decoration:none;background:#fff;border:1px solid #fed7aa;border-radius:10px;padding:14px;display:flex;align-items:center;gap:12px;transition:all 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(245,158,11,0.2)'" onmouseout="this.style.boxShadow='none'">
      <div style="width:40px;height:40px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-truck-loading" style="font-size:18px;color:#f59e0b;"></i>
      </div>
      <div>
        <div style="font-size:22px;font-weight:800;color:#92400e;"><?= $pending_actions['deliveries'] ?></div>
        <div style="font-size:11px;font-weight:600;color:#78350f;text-transform:uppercase;">Delivery Approvals</div>
      </div>
    </a>
    <?php endif; ?>
    
    <?php if ($pending_actions['stock_requests'] > 0): ?>
    <a href="manager_inventory_merchandise.php" style="text-decoration:none;background:#fff;border:1px solid #fed7aa;border-radius:10px;padding:14px;display:flex;align-items:center;gap:12px;transition:all 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(245,158,11,0.2)'" onmouseout="this.style.boxShadow='none'">
      <div style="width:40px;height:40px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-boxes" style="font-size:18px;color:#f59e0b;"></i>
      </div>
      <div>
        <div style="font-size:22px;font-weight:800;color:#92400e;"><?= $pending_actions['stock_requests'] ?></div>
        <div style="font-size:11px;font-weight:600;color:#78350f;text-transform:uppercase;">Stock Requests</div>
      </div>
    </a>
    <?php endif; ?>
    
    <?php if ($pending_actions['price_proposals'] > 0): ?>
    <a href="manager_approve_prices.php" style="text-decoration:none;background:#fff;border:1px solid #fed7aa;border-radius:10px;padding:14px;display:flex;align-items:center;gap:12px;transition:all 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(245,158,11,0.2)'" onmouseout="this.style.boxShadow='none'">
      <div style="width:40px;height:40px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-tags" style="font-size:18px;color:#f59e0b;"></i>
      </div>
      <div>
        <div style="font-size:22px;font-weight:800;color:#92400e;"><?= $pending_actions['price_proposals'] ?></div>
        <div style="font-size:11px;font-weight:600;color:#78350f;text-transform:uppercase;">Price Approvals</div>
      </div>
    </a>
    <?php endif; ?>
    
  </div>
</div>
<?php endif; ?>

<!-- Recent Transactions -->
<div class="mgr-card">
  <h3><i class="fas fa-receipt"></i> Recent Transactions (Fuel + Merchandise + Services)</h3>
  <?php if (empty($recent_transactions)): ?>
    <p style="color:#9ca3af;text-align:center;padding:24px 0;font-size:13px;"><i class="fas fa-receipt"></i> No recent transactions found.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table class="mgr-table">
      <thead>
        <tr>
          <th>Type</th>
          <th>Reference</th>
          <th>Description</th>
          <th>Amount</th>
          <th>Date & Time</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($recent_transactions as $tx): ?>
      <?php
        $type_badge_color = match($tx['type']) {
          'Fuel' => '#0284c7',
          'Merchandise' => '#7c3aed',
          'Job Order/Service' => '#059669',
          'Job Order' => '#059669',
          default => '#64748b'
        };
        $type_bg_color = match($tx['type']) {
          'Fuel' => '#e0f2fe',
          'Merchandise' => '#ede9fe',
          'Job Order/Service' => '#d1fae5',
          'Job Order' => '#d1fae5',
          default => '#f1f5f9'
        };
        $status_class = match(true) {
          str_contains(strtolower($tx['status'] ?? ''), 'pending') => 'badge-pending',
          str_contains(strtolower($tx['status'] ?? ''), 'approved') || str_contains(strtolower($tx['status'] ?? ''), 'validated') || str_contains(strtolower($tx['status'] ?? ''), 'completed') => 'badge-approved',
          str_contains(strtolower($tx['status'] ?? ''), 'rejected') || str_contains(strtolower($tx['status'] ?? ''), 'cancelled') => 'badge-rejected',
          str_contains(strtolower($tx['status'] ?? ''), 'progress') => 'badge-inprog',
          default => 'badge-default'
        };
      ?>
      <tr>
        <td>
          <span style="font-size:10px;font-weight:700;color:<?= $type_badge_color ?>;background:<?= $type_bg_color ?>;padding:3px 8px;border-radius:20px;text-transform:uppercase;">
            <?= htmlspecialchars($tx['type']) ?>
          </span>
        </td>
        <td><strong><?= htmlspecialchars($tx['ref']) ?></strong></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($tx['description']) ?>">
          <?= htmlspecialchars($tx['description']) ?>
        </td>
        <td style="font-weight:700;color:#002F70;">&#8369;<?= number_format((float)$tx['total_amount'], 2) ?></td>
        <td style="white-space:nowrap;font-size:12px;"><?= date('M j, Y h:i A', strtotime($tx['trans_date'])) ?></td>
        <td><span class="badge <?= $status_class ?>"><?= htmlspecialchars($tx['status'] ?? 'N/A') ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Fuel Gauges + Variance -->
<div class="charts-grid-2">

  <!-- Fuel Tank Gauges -->
  <div class="mgr-card" style="margin-bottom:0">
    <h3><i class="fas fa-gas-pump"></i> Fuel Tank Levels</h3>
    <?php if (empty($fuel_stock_levels)): ?>
      <p style="color:#9ca3af;text-align:center;padding:24px 0;font-size:13px;"><i class="fas fa-gas-pump"></i> No fuel inventory data.</p>
    <?php else: ?>
    <div class="gauge-grid" id="gaugeGrid">
      <?php foreach ($fuel_stock_levels as $i => $fuel): ?>
      <?php
        $cap = max(1, (float)$fuel['capacity']);
        $cur = max(0, (float)$fuel['current_stock']);
        $pct = min(100, round($cur / $cap * 100));
      ?>
      <div class="gauge-card">
        <canvas id="gauge_<?= $i ?>" width="120" height="70" data-pct="<?= $pct ?>"></canvas>
        <div class="gauge-label"><?= htmlspecialchars($fuel['fuel_type_name']) ?></div>
        <div class="gauge-val"><?= number_format($cur,0) ?> / <?= number_format($cap,0) ?> L</div>
        <div class="gauge-val"><?= $pct ?>% full</div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Fuel Variance Bar Chart -->
  <div class="mgr-card" style="margin-bottom:0">
    <h3><i class="fas fa-balance-scale"></i> Fuel Variance <?= htmlspecialchars($variance_window_label) ?></h3>
    <?php if (empty($fuel_variance_rows)): ?>
      <p style="color:#22c55e;text-align:center;padding:24px 0;font-size:13px;"><i class="fas fa-check-circle"></i> No significant variance detected (threshold: 0.5L).</p>
    <?php else: ?>
    <div style="margin-top:12px">
      <!-- Display variance with meter reading vs actual sold liters -->
      <?php
        $max_var = max(array_column($fuel_variance_rows,'variance') ?: [1]);
        $max_var = max(1, $max_var);
      ?>
      <?php foreach ($fuel_variance_rows as $vr): ?>
      <div class="variance-row">
        <span style="width:100px;flex-shrink:0;font-weight:600;font-size:11px"><?= htmlspecialchars($vr['fuel_type']) ?></span>
        <div style="flex:1;font-size:10px;color:#64748b;">
          <div>Meter: <?= number_format((float)($vr['meter_reading']??0),2) ?>L</div>
          <div>Sold: <?= number_format((float)($vr['pump_liters']??0),2) ?>L</div>
        </div>
        <div class="variance-bar-wrap" style="width:120px;">
          <div class="variance-bar-fill" style="width:<?= min(100,round((float)$vr['variance']/$max_var*100)) ?>%"></div>
        </div>
        <span style="width:70px;text-align:right;color:#d97706;font-weight:700;font-size:12px"><?= number_format((float)$vr['variance'],2) ?> L</span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /charts-grid-2 fuel -->

<!-- Low Stock + Staff Attendance -->
<div class="charts-grid-2">

  <!-- Low Stock Items — Fuel + Merchandise -->
  <div class="mgr-card" style="margin-bottom:0">
    <h3><i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i> Low Stock Alerts <span class="badge-count" style="background:#f59e0b"><?= count($low_stock_items) ?></span></h3>
    <?php if (empty($low_stock_items)): ?>
      <p style="color:#22c55e;text-align:center;padding:24px 0;font-size:13px;"><i class="fas fa-check-circle"></i> All items are adequately stocked.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="mgr-table">
      <thead><tr><th>Item</th><th>Type</th><th>Stock</th><th>Reorder Level</th><th>Severity</th></tr></thead>
      <tbody>
      <?php foreach ($low_stock_items as $item): ?>
      <?php
        $sl   = (float)$item['stock_level'];
        $rl   = (float)$item['reorder_level'];
        $type = $item['item_type'] ?? 'Merchandise';
        $unit = $type === 'Fuel' ? 'L' : 'pcs';
        $sev  = $sl <= 0 ? 'Out of Stock' : ($sl <= $rl * 0.5 ? 'Critical' : 'Low');
        $sev_class = match($sev) {
            'Out of Stock' => 'badge-rejected',
            'Critical'     => 'badge-pending',
            default        => 'badge-default'
        };
        $type_color = $type === 'Fuel' ? '#0284c7' : '#7c3aed';
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($item['product_name']) ?></strong></td>
        <td><span style="font-size:11px;font-weight:700;color:<?= $type_color ?>;background:<?= $type==='Fuel'?'#e0f2fe':'#ede9fe' ?>;padding:2px 8px;border-radius:20px;"><?= $type ?></span></td>
        <td><strong style="color:<?= $sl<=0?'#CC0000':($sl<=$rl*0.5?'#d97706':'#344054') ?>"><?= $type==='Fuel'?number_format($sl,0):number_format($sl,0) ?> <?= $unit ?></strong></td>
        <td><?= $type==='Fuel'?number_format($rl,0):number_format($rl,0) ?> <?= $unit ?></td>
        <td><span class="badge <?= $sev_class ?>"><?= $sev ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Staff Attendance -->
  <div class="mgr-card" style="margin-bottom:0">
    <h3><i class="fas fa-users"></i> Staff Attendance Today
      <span style="font-size:12px;font-weight:400;color:#667085;margin-left:auto"><?= $staff_active_count ?> active / <?= $staff_total_today ?> total</span>
    </h3>
    <?php if (empty($attendance_rows)): ?>
      <p style="color:#9ca3af;text-align:center;padding:24px 0;font-size:13px;"><i class="fas fa-clock"></i> No clock-in records for today.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="mgr-table">
      <thead><tr><th>Staff</th><th>Role</th><th>Shift</th><th>Clock In</th><th>Clock Out</th><th>Hours</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($attendance_rows as $att): ?>
      <?php $is_active = empty($att['end_time']); ?>
      <tr>
        <td><strong><?= htmlspecialchars($att['staff_name']) ?></strong></td>
        <td style="text-transform:capitalize"><?= htmlspecialchars($att['role'] ?? '—') ?></td>
        <td><?= htmlspecialchars($att['shift_name'] ?? '—') ?></td>
        <td><?= $att['start_time'] ? date('h:i A', strtotime($att['start_time'])) : '—' ?></td>
        <td><?= $att['end_time']   ? date('h:i A', strtotime($att['end_time']))   : '—' ?></td>
        <td><?= number_format((float)($att['hours'] ?? 0), 2) ?> h</td>
        <td><span class="badge <?= $is_active ? 'att-active' : 'att-done' ?>"><?= $is_active ? 'Active' : 'Done' ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /charts-grid-2 low-stock + attendance -->

<!-- ============================================================
     BOTTOM SECTION
     ============================================================ -->

<!-- Additional Snapshots (Price, Inventory, Audit) -->
<div class="charts-grid-3">

  <!-- Price Change Snapshot -->
  <div class="mgr-card" style="margin-bottom:0; display:flex; flex-direction:column; height: 100%;">
    <h3 style="margin-bottom: 16px;"><i class="fas fa-tags"></i> Price Change Snapshot</h3>
    <?php if (empty($price_changes)): ?>
      <div style="flex:1;display:flex;align-items:center;justify-content:center;">
          <p style="color:#9ca3af;text-align:center;font-size:13px;"><i class="fas fa-tag"></i> No recent price adjustments.</p>
      </div>
    <?php else: ?>
      <ul style="list-style:none;padding:0;margin:0;flex:1;">
      <?php foreach ($price_changes as $pc): ?>
        <?php
          $pc_badge = match($pc['action']) {
            'Approve Price' => 'badge-approved',
            'Reject Price' => 'badge-rejected',
            'Hold Price' => 'badge-pending',
            default => 'badge-inprog'
          };
          
          $details = htmlspecialchars($pc['details']);
          $parts = explode('|', $details);
          $details_html = '';
          foreach ($parts as $part) {
              $part = trim($part);
              if (!empty($part)) {
                  $details_html .= '<div style="margin-top:3px; display:flex; gap:6px; align-items:flex-start;">
                                      <i class="fas fa-arrow-right" style="color:#cbd5e1;font-size:9px;margin-top:4px;"></i>
                                      <span style="flex:1;">' . $part . '</span>
                                    </div>';
              }
          }
        ?>
        <li style="border-bottom:1px solid #f1f5f9;padding:12px 0;font-size:12px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <span class="badge <?= $pc_badge ?>" style="font-size:10px;padding:3px 8px;letter-spacing:0.5px;text-transform:uppercase;border-radius:4px;"><?= htmlspecialchars($pc['action']) ?></span>
            <span style="color:#94a3b8;font-size:11px;font-weight:500;"><i class="far fa-clock" style="margin-right:3px;"></i><?= date('M j, h:i A', strtotime($pc['created_at'])) ?></span>
          </div>
          <div style="color:#64748b;line-height:1.4;font-size:11px;"><?= $details_html ?></div>
        </li>
      <?php endforeach; ?>
      </ul>
      <div style="text-align:center;margin-top:16px;padding-top:12px;border-top:1px solid #e2e8f0;">
        <a href="manager_approve_prices.php" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#2563eb;text-decoration:none;font-weight:700;padding:6px 12px;border-radius:6px;transition:all 0.2s;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='transparent'">
          Manage Prices <i class="fas fa-arrow-right"></i>
        </a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Inventory Snapshot -->
  <div class="mgr-card" style="margin-bottom:0">
    <h3><i class="fas fa-boxes"></i> Inventory Snapshot</h3>
    <div style="display:flex;flex-direction:column;gap:16px;margin-top:12px;">
      <div style="background:#f0f9ff;border:1px solid #bae6fd;padding:16px;border-radius:10px;text-align:center;">
        <div style="font-size:24px;font-weight:800;color:#0369a1;"><?= number_format($inv_fuel_total, 2) ?> L</div>
        <div style="font-size:12px;font-weight:700;color:#0284c7;text-transform:uppercase;">Total Fuel Stock</div>
      </div>
      <div style="background:#f5f3ff;border:1px solid #ddd6fe;padding:16px;border-radius:10px;text-align:center;">
        <div style="font-size:24px;font-weight:800;color:#6d28d9;"><?= number_format($inv_merch_total) ?> Items</div>
        <div style="font-size:12px;font-weight:700;color:#7c3aed;text-transform:uppercase;">Total Merchandise Stock</div>
      </div>
    </div>
  </div>

  <!-- Audit Trail Quick View -->
  <div class="mgr-card" style="margin-bottom:0">
    <h3><i class="fas fa-history"></i> Audit Trail (My Last 5 Actions)</h3>
    <?php if (empty($audit_trail)): ?>
      <p style="color:#9ca3af;text-align:center;padding:24px 0;font-size:13px;"><i class="fas fa-shoe-prints"></i> No recent activity found.</p>
    <?php else: ?>
      <ul style="list-style:none;padding:0;margin:0;">
      <?php foreach ($audit_trail as $at): ?>
        <li style="border-bottom:1px solid #f5f5f5;padding:8px 0;font-size:12px;">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
            <strong style="color:var(--petron-blue);"><?= htmlspecialchars($at['action']) ?></strong>
            <span style="color:#9ca3af;font-size:11px;"><?= date('M j, h:i A', strtotime($at['created_at'])) ?></span>
          </div>
          <div style="color:#667085;line-height:1.4;"><?= htmlspecialchars($at['details']) ?></div>
        </li>
      <?php endforeach; ?>
      </ul>
      <div style="text-align:center;margin-top:12px;">
        <a href="manager_reports.php?tab=audit" style="font-size:12px;color:#3b82f6;text-decoration:none;font-weight:600;">View Full Audit Trail &rarr;</a>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Delivery Status Cards + Trend -->
<div class="mgr-card">
  <h3><i class="fas fa-truck-loading"></i> Delivery Overview</h3>
  <div class="del-status-grid">
    <div class="del-status-card">
      <div class="del-num" id="del-pending"><?= $del_pending ?></div>
      <div class="del-lbl"><i class="fas fa-hourglass-half"></i> Pending</div>
    </div>
    <div class="del-status-card">
      <div class="del-num" id="del-approved"><?= $del_approved ?></div>
      <div class="del-lbl"><i class="fas fa-check-circle"></i> Approved</div>
    </div>
    <div class="del-status-card">
      <div class="del-num" id="del-rejected"><?= $del_rejected ?></div>
      <div class="del-lbl"><i class="fas fa-times-circle"></i> Rejected</div>
    </div>
  </div>
  <div class="chart-wrap-sm"><canvas id="chartDelTrend"></canvas></div>
</div>

<!-- Customer Balances + Staff Performance -->
<div class="charts-grid-2">

  <!-- Customer Balances -->
  <div class="mgr-card" style="margin-bottom:0">
    <h3><i class="fas fa-user-tag"></i> Customer Outstanding Balances</h3>
    <?php if (empty($customer_balances)): ?>
      <p style="color:#22c55e;text-align:center;padding:24px 0;font-size:13px;"><i class="fas fa-check-circle"></i> No outstanding balances.</p>
    <?php else: ?>
    <div class="chart-wrap-sm" style="margin-bottom:14px"><canvas id="chartCustBalances"></canvas></div>
    <div style="overflow-x:auto">
    <table class="mgr-table">
      <thead><tr><th>Customer</th><th>Outstanding</th><th>Credit Limit</th><th>Utilization</th></tr></thead>
      <tbody>
      <?php foreach ($customer_balances as $cb): ?>
      <?php
        $out = (float)$cb['outstanding'];
        $lim = (float)$cb['credit_limit'];
        $util = $lim > 0 ? min(100, round($out / $lim * 100)) : 100;
        $util_color = $util >= 90 ? '#CC0000' : ($util >= 70 ? '#f59e0b' : '#22c55e');
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($cb['name']) ?></strong></td>
        <td style="color:#CC0000;font-weight:700">&#8369;<?= number_format($out,2) ?></td>
        <td>&#8369;<?= number_format($lim,2) ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:6px">
            <div style="flex:1;background:#e5e7eb;border-radius:20px;height:6px;overflow:hidden">
              <div style="width:<?= $util ?>%;height:100%;background:<?= $util_color ?>;border-radius:20px"></div>
            </div>
            <span style="font-size:11px;color:<?= $util_color ?>;font-weight:700;width:32px"><?= $util ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Staff Performance -->
  <div class="mgr-card" style="margin-bottom:0">
    <h3><i class="fas fa-medal"></i> Staff Performance (This Month)</h3>
    <?php if (empty($staff_performance)): ?>
      <p style="color:#9ca3af;text-align:center;padding:24px 0;font-size:13px;"><i class="fas fa-users"></i> No performance data for this month.</p>
    <?php else: ?>
    <div class="chart-wrap-sm" style="margin-bottom:14px"><canvas id="chartStaffPerf"></canvas></div>
    <div style="overflow-x:auto">
    <table class="mgr-table">
      <thead><tr><th>Staff</th><th>Transactions</th><th>JOs Completed</th><th>Total</th></tr></thead>
      <tbody>
      <?php foreach ($staff_performance as $sp): ?>
      <tr>
        <td><strong><?= htmlspecialchars($sp['staff_name']) ?></strong></td>
        <td><?= (int)$sp['txn_count'] ?></td>
        <td><?= (int)$sp['jo_count'] ?></td>
        <td><strong><?= (int)$sp['txn_count'] + (int)$sp['jo_count'] ?></strong></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /charts-grid-2 balances + perf -->

</div><!-- /page-content -->

<!-- ============================================================
     APPROVE MODAL
     ============================================================ -->
<div class="mgr-modal-overlay" id="approveModal">
  <div class="mgr-modal">
    <h4><i class="fas fa-check-circle" style="color:#22c55e;margin-right:8px"></i>Approve Job Order</h4>
    <p id="approveModalDesc">Approving job order <strong id="approveJoRef"></strong>. Please provide remarks.</p>
    <form method="POST" action="manager_dashboard.php">
      <input type="hidden" name="action" value="approve_jo">
      <input type="hidden" name="jo_id" id="approveJoId">
      <label for="approveRemarks">Remarks <span style="color:#CC0000">*</span></label>
      <textarea id="approveRemarks" name="remarks" placeholder="Enter approval remarks..." required></textarea>
      <div class="mgr-modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('approveModal')">Cancel</button>
        <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirm Approval</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     REJECT MODAL
     ============================================================ -->
<div class="mgr-modal-overlay" id="rejectModal">
  <div class="mgr-modal">
    <h4><i class="fas fa-times-circle" style="color:#CC0000;margin-right:8px"></i>Reject Job Order</h4>
    <p id="rejectModalDesc">Rejecting job order <strong id="rejectJoRef"></strong>. Please provide a reason.</p>
    <form method="POST" action="manager_dashboard.php">
      <input type="hidden" name="action" value="reject_jo">
      <input type="hidden" name="jo_id" id="rejectJoId">
      <label for="rejectRemarks">Rejection Reason <span style="color:#CC0000">*</span></label>
      <textarea id="rejectRemarks" name="remarks" placeholder="Enter rejection reason..." required></textarea>
      <div class="mgr-modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('rejectModal')">Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>

<script>
// ============================================================
// PHP DATA → JS
// ============================================================
const PHP = {
  jo_total:    <?= (int)$jo_total ?>,
  jo_pending:  <?= (int)$jo_pending ?>,
  jo_approved: <?= (int)$jo_approved ?>,
  jo_inprog:   <?= (int)$jo_inprog ?>,
  jo_done:     <?= (int)$jo_done ?>,
  jo_rejected: <?= (int)$jo_rejected ?>,
  today_sales: <?= (float)$today_total_sales ?>,
  fuel_sales:  <?= (float)$today_fuel_sales ?>,
  merch_sales: <?= (float)$today_merch_sales ?>,
  staff_in:    <?= (int)$staff_clocked_in ?>,
  low_stock:   <?= (int)$low_stock_count ?>,
  pend_del:    <?= (int)$pending_deliveries ?>,
  del_pending: <?= (int)$del_pending ?>,
  del_approved:<?= (int)$del_approved ?>,
  del_rejected:<?= (int)$del_rejected ?>,
  pay_cash:    <?= (float)$pay_cash ?>,
  pay_card:    <?= (float)$pay_card ?>,
  pay_ewallet: <?= (float)$pay_ewallet ?>,
  pay_efuel:   <?= (float)$pay_efuel ?>,
  pay_credit:  <?= (float)$pay_credit ?>,
  trend_dates: <?= json_encode($trend_dates) ?>,
  trend_fuel:  <?= json_encode($trend_fuel) ?>,
  trend_merch: <?= json_encode($trend_merch) ?>,
  val_dates:   <?= json_encode($val_dates) ?>,
  val_approved:<?= json_encode($val_approved_arr) ?>,
  val_rejected:<?= json_encode($val_rejected_arr) ?>,
  del_trend_dates: <?= json_encode($del_trend_dates) ?>,
  del_trend_counts:<?= json_encode($del_trend_counts) ?>,
  cust_names:  <?= json_encode(array_column($customer_balances,'name')) ?>,
  cust_out:    <?= json_encode(array_map('floatval', array_column($customer_balances,'outstanding'))) ?>,
  staff_names: <?= json_encode(array_column($staff_performance,'staff_name')) ?>,
  staff_txns:  <?= json_encode(array_map('intval', array_column($staff_performance,'txn_count'))) ?>,
  staff_jos:   <?= json_encode(array_map('intval', array_column($staff_performance,'jo_count'))) ?>,
  variance_types: <?= json_encode(array_column($fuel_variance_rows,'fuel_type')) ?>,
  variance_vals:  <?= json_encode(array_map('floatval', array_column($fuel_variance_rows,'variance'))) ?>,
};

// ============================================================
// CHART DEFAULTS
// ============================================================
Chart.defaults.font.family = "ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#667085';

const COLORS = {
  blue:   '#002F70',
  red:    '#dc3545',
  green:  '#22c55e',
  yellow: '#f59e0b',
  gray:   '#6b7280',
};

// ============================================================
// 1. JO DISTRIBUTION PIE
// ============================================================
const ctxJoDist = document.getElementById('chartJoDist');
let chartJoDist = null;
if (ctxJoDist) {
  chartJoDist = new Chart(ctxJoDist, {
    type: 'doughnut',
    data: {
      labels: ['Pending', 'Approved', 'In Progress', 'Completed', 'Rejected'],
      datasets: [{
        data: [PHP.jo_pending, PHP.jo_approved, PHP.jo_inprog, PHP.jo_done, PHP.jo_rejected],
        backgroundColor: [COLORS.yellow, COLORS.green, COLORS.blue, COLORS.gray, COLORS.red],
        borderWidth: 2,
        borderColor: '#fff',
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10 } },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.label}: ${ctx.parsed} JOs`
          }
        }
      },
      cutout: '60%',
    }
  });
}

// ============================================================
// 2. SALES TREND 30-DAY LINE
// ============================================================
const ctxSalesTrend = document.getElementById('chartSalesTrend');
let chartSalesTrend = null;
if (ctxSalesTrend) {
  chartSalesTrend = new Chart(ctxSalesTrend, {
    type: 'line',
    data: {
      labels: PHP.trend_dates,
      datasets: [
        {
          label: 'Fuel',
          data: PHP.trend_fuel,
          borderColor: COLORS.blue,
          backgroundColor: 'rgba(0,38,77,.08)',
          fill: true,
          tension: 0.4,
          pointRadius: 2,
          borderWidth: 2,
        },
        {
          label: 'Merchandise',
          data: PHP.trend_merch,
          borderColor: COLORS.gray,
          backgroundColor: 'rgba(107,114,128,.08)',
          fill: true,
          tension: 0.4,
          pointRadius: 2,
          borderWidth: 2,
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10 } },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.dataset.label}: \u20B1${ctx.parsed.y.toLocaleString('en-PH',{minimumFractionDigits:2})}`
          }
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
        y: {
          grid: { color: '#f0f0f0' },
          ticks: {
            callback: v => '\u20B1' + (v >= 1000 ? (v/1000).toFixed(1)+'k' : v)
          }
        }
      }
    }
  });
}

// ============================================================
// 3. PAYMENT BREAKDOWN PIE
// ============================================================
const ctxPayBreakdown = document.getElementById('chartPayBreakdown');
let chartPayBreakdown = null;
if (ctxPayBreakdown) {
  chartPayBreakdown = new Chart(ctxPayBreakdown, {
    type: 'doughnut',
    data: {
      labels: ['Cash', 'Card', 'E-Wallet', 'E-Fuel Card', 'Credit'],
      datasets: [{
        data: [PHP.pay_cash, PHP.pay_card, PHP.pay_ewallet, PHP.pay_efuel, PHP.pay_credit],
        backgroundColor: [COLORS.green, COLORS.blue, COLORS.gray, COLORS.yellow, COLORS.red],
        borderWidth: 2,
        borderColor: '#fff',
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10 } },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.label}: \u20B1${ctx.parsed.toLocaleString('en-PH',{minimumFractionDigits:2})}`
          }
        }
      },
      cutout: '60%',
    }
  });
}

// ============================================================
// 4. VALIDATION TREND 7-DAY LINE
// ============================================================
const ctxValTrend = document.getElementById('chartValTrend');
let chartValTrend = null;
if (ctxValTrend) {
  chartValTrend = new Chart(ctxValTrend, {
    type: 'line',
    data: {
      labels: PHP.val_dates,
      datasets: [
        {
          label: 'Approved',
          data: PHP.val_approved,
          borderColor: COLORS.green,
          backgroundColor: 'rgba(34,197,94,.1)',
          fill: true,
          tension: 0.4,
          pointRadius: 3,
          borderWidth: 2,
        },
        {
          label: 'Rejected',
          data: PHP.val_rejected,
          borderColor: COLORS.red,
          backgroundColor: 'rgba(204,0,0,.08)',
          fill: true,
          tension: 0.4,
          pointRadius: 3,
          borderWidth: 2,
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10 } },
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: '#f0f0f0' }, ticks: { stepSize: 1 } }
      }
    }
  });
}

// ============================================================
// 5. DELIVERIES TREND LINE
// ============================================================
const ctxDelTrend = document.getElementById('chartDelTrend');
let chartDelTrend = null;
if (ctxDelTrend) {
  chartDelTrend = new Chart(ctxDelTrend, {
    type: 'line',
    data: {
      labels: PHP.del_trend_dates,
      datasets: [{
        label: 'Deliveries',
        data: PHP.del_trend_counts,
        borderColor: COLORS.blue,
        backgroundColor: 'rgba(0,38,77,.08)',
        fill: true,
        tension: 0.4,
        pointRadius: 3,
        borderWidth: 2,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 7 } },
        y: { grid: { color: '#f0f0f0' }, ticks: { stepSize: 1 } }
      }
    }
  });
}

// ============================================================
// 6. CUSTOMER BALANCES HORIZONTAL BAR
// ============================================================
const ctxCustBal = document.getElementById('chartCustBalances');
if (ctxCustBal && PHP.cust_names.length > 0) {
  new Chart(ctxCustBal, {
    type: 'bar',
    data: {
      labels: PHP.cust_names,
      datasets: [{
        label: 'Outstanding (\u20B1)',
        data: PHP.cust_out,
        backgroundColor: COLORS.red,
        borderRadius: 4,
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ` \u20B1${ctx.parsed.x.toLocaleString('en-PH',{minimumFractionDigits:2})}`
          }
        }
      },
      scales: {
        x: {
          grid: { color: '#f0f0f0' },
          ticks: { callback: v => '\u20B1' + (v >= 1000 ? (v/1000).toFixed(1)+'k' : v) }
        },
        y: { grid: { display: false } }
      }
    }
  });
}

// ============================================================
// 7. STAFF PERFORMANCE GROUPED BAR
// ============================================================
const ctxStaffPerf = document.getElementById('chartStaffPerf');
if (ctxStaffPerf && PHP.staff_names.length > 0) {
  new Chart(ctxStaffPerf, {
    type: 'bar',
    data: {
      labels: PHP.staff_names,
      datasets: [
        {
          label: 'Transactions',
          data: PHP.staff_txns,
          backgroundColor: COLORS.blue,
          borderRadius: 4,
        },
        {
          label: 'JOs Completed',
          data: PHP.staff_jos,
          backgroundColor: COLORS.green,
          borderRadius: 4,
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10 } },
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: '#f0f0f0' }, ticks: { stepSize: 1 } }
      }
    }
  });
}

// ============================================================
// SEMI-CIRCLE GAUGE (Canvas 2D API)
// ============================================================
function drawGauge(canvas, pct) {
  const ctx = canvas.getContext('2d');
  const W = canvas.width, H = canvas.height;
  const cx = W / 2, cy = H - 4;
  const r = Math.min(W, H * 2) / 2 - 8;
  const startAngle = Math.PI;
  const endAngle   = 2 * Math.PI;
  const fillAngle  = startAngle + (pct / 100) * Math.PI;

  ctx.clearRect(0, 0, W, H);

  // Track
  ctx.beginPath();
  ctx.arc(cx, cy, r, startAngle, endAngle);
  ctx.strokeStyle = '#e5e7eb';
  ctx.lineWidth = 10;
  ctx.lineCap = 'round';
  ctx.stroke();

  // Fill
  const fillColor = pct <= 20 ? '#CC0000' : pct <= 40 ? '#f59e0b' : '#22c55e';
  ctx.beginPath();
  ctx.arc(cx, cy, r, startAngle, fillAngle);
  ctx.strokeStyle = fillColor;
  ctx.lineWidth = 10;
  ctx.lineCap = 'round';
  ctx.stroke();

  // Percentage text
  ctx.fillStyle = '#101828';
  ctx.font = 'bold 14px ui-sans-serif,system-ui,Arial';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'bottom';
  ctx.fillText(pct + '%', cx, cy - 2);
}

document.querySelectorAll('[id^="gauge_"]').forEach(canvas => {
  const pct = parseInt(canvas.dataset.pct || '0', 10);
  drawGauge(canvas, pct);
});

// ============================================================
// MODALS
// ============================================================
function openApproveModal(joId, joRef) {
  document.getElementById('approveJoId').value  = joId;
  document.getElementById('approveJoRef').textContent = joRef;
  document.getElementById('approveRemarks').value = '';
  document.getElementById('approveModal').classList.add('active');
}
function openRejectModal(joId, joRef) {
  document.getElementById('rejectJoId').value  = joId;
  document.getElementById('rejectJoRef').textContent = joRef;
  document.getElementById('rejectRemarks').value = '';
  document.getElementById('rejectModal').classList.add('active');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('active');
}
// Close on overlay click
document.querySelectorAll('.mgr-modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', e => {
    if (e.target === overlay) overlay.classList.remove('active');
  });
});
// Close on Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.mgr-modal-overlay.active').forEach(m => m.classList.remove('active'));
  }
});

// ============================================================
// AUTO-REFRESH (60 seconds)
// ============================================================
let refreshTimer = null;
const REFRESH_INTERVAL = 60000;

function updateKpiEl(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

async function doRefresh() {
  try {
    const res = await fetch('manager_dashboard.php?refresh=1');
    if (!res.ok) return;
    const d = await res.json();
    if (!d.success) return;

    // KPI cards
    updateKpiEl('kpi-jo-total',    d.jo_total);
    updateKpiEl('kpi-jo-pending',  d.jo_pending);
    updateKpiEl('kpi-jo-approved', d.jo_approved);
    updateKpiEl('kpi-jo-inprog',   d.jo_inprog);
    updateKpiEl('kpi-jo-done',     d.jo_done);
    updateKpiEl('kpi-jo-rejected', d.jo_rejected);
    updateKpiEl('kpi-today-sales', '\u20B1' + Math.round(d.today_sales).toLocaleString('en-PH'));
    updateKpiEl('kpi-staff-in',    d.staff_clocked_in);
    updateKpiEl('kpi-low-stock',   d.low_stock_count);
    updateKpiEl('kpi-pend-del',    d.pending_deliveries);

    // Today's Sales sub-label (Fuel • Merch breakdown)
    const salesSub = document.getElementById('kpi-today-sales-sub');
    if (salesSub) {
      salesSub.textContent = 'Fuel \u20B1' + Math.round(d.fuel_sales).toLocaleString('en-PH')
                           + ' \u2022 Merch \u20B1' + Math.round(d.merch_sales).toLocaleString('en-PH');
    }

    // Delivery cards
    updateKpiEl('del-pending',  d.pending_deliveries);
    updateKpiEl('del-approved', d.del_approved ?? 0);
    updateKpiEl('del-rejected', d.del_rejected ?? 0);

    // Fuel gauges
    if (d.fuel_levels && Array.isArray(d.fuel_levels)) {
      d.fuel_levels.forEach((f, i) => {
        const canvas = document.getElementById('gauge_' + i);
        if (canvas) {
          const cap = Math.max(1, parseFloat(f.capacity));
          const cur = Math.max(0, parseFloat(f.current_stock));
          const pct = Math.min(100, Math.round(cur / cap * 100));
          canvas.dataset.pct = pct;
          drawGauge(canvas, pct);
        }
      });
    }

    // Update last refresh label
    const lbl = document.getElementById('lastRefreshLabel');
    if (lbl) {
      const now = new Date();
      lbl.textContent = 'Updated ' + now.toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit'});
    }
  } catch (e) {
    console.warn('Dashboard refresh error:', e);
  }
}

async function doChartRefresh() {
  try {
    const res = await fetch('manager_dashboard.php?refresh_charts=1');
    if (!res.ok) return;
    const d = await res.json();
    if (!d.success) return;

    // JO Distribution
    if (chartJoDist && d.jo_dist) {
      const vals = Object.values(d.jo_dist);
      chartJoDist.data.datasets[0].data = vals;
      chartJoDist.update('none');
    }

    // Payment Breakdown
    if (chartPayBreakdown && d.payment) {
      chartPayBreakdown.data.datasets[0].data = [
        d.payment['Cash'] || 0,
        d.payment['Card'] || 0,
        d.payment['E-Wallet'] || 0,
        d.payment['E-Fuel Card'] || 0,
        d.payment['Credit'] || 0,
      ];
      chartPayBreakdown.update('none');
    }

    // Sales Trend
    if (chartSalesTrend && d.trend_dates) {
      chartSalesTrend.data.labels = d.trend_dates;
      chartSalesTrend.data.datasets[0].data = d.trend_fuel;
      chartSalesTrend.data.datasets[1].data = d.trend_merch;
      chartSalesTrend.update('none');
    }

    // Validation Trend
    if (chartValTrend && d.val_dates) {
      chartValTrend.data.labels = d.val_dates;
      chartValTrend.data.datasets[0].data = d.val_approved;
      chartValTrend.data.datasets[1].data = d.val_rejected;
      chartValTrend.update('none');
    }
  } catch (e) {
    console.warn('Chart refresh error:', e);
  }
}

// Initial refresh label removed - no longer display loading timestamp

// Schedule auto-refresh
refreshTimer = setInterval(() => {
  doRefresh();
  doChartRefresh();
}, REFRESH_INTERVAL);

// Visibility API: pause when tab hidden, resume when visible
document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    clearInterval(refreshTimer);
  } else {
    doRefresh();
    doChartRefresh();
    refreshTimer = setInterval(() => {
      doRefresh();
      doChartRefresh();
    }, REFRESH_INTERVAL);
  }
});

// ============================================================
// FILTER ENHANCEMENTS
// ============================================================
// Submit filter form on Enter key in customer search
const customerInput = document.querySelector('input[name="jo_customer"]');
if (customerInput) {
  customerInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      customerInput.closest('form').submit();
    }
  });
}

// Focus customer input when Ctrl+F is pressed
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'f' && customerInput) {
    e.preventDefault();
    customerInput.focus();
    customerInput.select();
  }
});

</script>

<div style="height: 80px;"></div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
