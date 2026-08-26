<?php
/**
 * Manager Audit Trail — Full Branch Audit Trail
 * 12 data sources, station-scoped, no redundancy.
 * Covers: Staff Txns, Job Orders, Fuel, Stock, Master Data,
 *         Adjustments, Voids, Approvals, Login/Logout, Drafts, Stock-In, POs.
 */
$page_id = 'mgr_audit_trail';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = (int)user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['manager','admin','superadmin','developer'], true)) {
    header('Location: dashboard.php'); exit;
}

function mgr_tbl_exists(PDO $pdo, string $t): bool {
    try { $s = $pdo->query("SHOW TABLES LIKE '$t'"); return $s && $s->rowCount() > 0; }
    catch (Exception $e) { return false; }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$today      = date('Y-m-d');
$thirty_ago = date('Y-m-d', strtotime('-30 days'));
$date_from       = trim($_GET['date_from']  ?? $thirty_ago);
$date_to         = trim($_GET['date_to']    ?? $today);
$filter_module   = trim($_GET['module']     ?? '');
$filter_category = trim($_GET['category']   ?? '');
$filter_staff    = (int)($_GET['staff_id']  ?? 0);
$filter_status   = trim($_GET['status']     ?? '');
$filter_search   = trim($_GET['search']     ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $thirty_ago;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $today;

// ── Staff list for dropdown ───────────────────────────────────────────────────
$staff_list = [];
try {
    $sl = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name, role FROM users WHERE station_id=? AND status='Active' ORDER BY full_name");
    $sl->execute([$station_id]);
    $staff_list = $sl->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ─────────────────────────────────────────────────────────────────────────────
// DATA COLLECTION — 12 sources, all scoped to station_id
// ─────────────────────────────────────────────────────────────────────────────
$raw = [];

// 1. Merchandise Transactions
if (mgr_tbl_exists($pdo, 'merchandise_transactions')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND mt.staff_id=?'; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT mt.created_at AS datetime,'Sales' AS module,
        CASE WHEN mt.void_reason IS NOT NULL AND TRIM(mt.void_reason)!='' THEN 'Void Request'
             WHEN mt.adjustment_reason IS NOT NULL AND TRIM(mt.adjustment_reason)!='' THEN 'Adjustment Request'
             WHEN LOWER(COALESCE(mt.transaction_type,'')) LIKE '%return%' THEN 'Processed Return'
             ELSE 'Merchandise Sale' END AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,CONCAT('Staff #',mt.staff_id)) AS actor,
        COALESCE(NULLIF(mt.transaction_id,''),CONCAT('MTX-',mt.id)) AS ref_no,
        CASE WHEN LOWER(COALESCE(mt.validation_status,'')) IN ('voided','rejected','cancelled') THEN 'Cancelled'
             WHEN LOWER(COALESCE(mt.validation_status,'')) IN ('verified','approved','completed','submitted','paid') THEN 'Success'
             ELSE 'Pending' END AS status,
        COALESCE(mt.shift_period,'') AS shift_period,
        CONCAT('Customer: ',COALESCE(mt.customer_name,'Walk-in'),' | Total: \u20b1',FORMAT(COALESCE(mt.total_amount,0),2),' | ',COALESCE(mt.payment_method,'Cash')) AS details
        FROM merchandise_transactions mt LEFT JOIN users u ON u.id=mt.staff_id
        WHERE mt.station_id=? AND DATE(mt.created_at) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 2. Fuel Transactions / Meter Readings
if (mgr_tbl_exists($pdo, 'fuel_transactions')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND ft.staff_id=?'; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT COALESCE(ft.transaction_date,ft.created_at) AS datetime,'Fuel Management' AS module,'Fuel Meter Reading' AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,CONCAT('Staff #',ft.staff_id)) AS actor,
        COALESCE(NULLIF(ft.transaction_id,''),CONCAT('FTX-',ft.id)) AS ref_no,
        CASE WHEN LOWER(COALESCE(ft.status,'')) IN ('voided','rejected','cancelled') THEN 'Cancelled'
             WHEN LOWER(COALESCE(ft.status,'')) IN ('verified','approved','completed','submitted') THEN 'Success'
             ELSE 'Pending' END AS status,
        COALESCE(ft.shift_period,'') AS shift_period,
        CONCAT('Fuel: ',COALESCE(ft.fuel_type,'N/A'),' | ',FORMAT(COALESCE(ft.liters_sold,0),2),'L | \u20b1',FORMAT(COALESCE(ft.total_amount,0),2)) AS details
        FROM fuel_transactions ft LEFT JOIN users u ON u.id=ft.staff_id
        WHERE ft.station_id=? AND DATE(COALESCE(ft.transaction_date,ft.created_at)) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 3. Job Orders
if (mgr_tbl_exists($pdo, 'job_orders')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND (jo.user_id=? OR jo.created_by=?)'; $p[] = $filter_staff; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT jo.created_at AS datetime,'Job Orders' AS module,
        CASE WHEN jo.updated_at>jo.created_at THEN 'Updated Job Order' ELSE 'Created Job Order' END AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,CONCAT('User #',jo.created_by)) AS actor,
        COALESCE(NULLIF(jo.job_order_id,''),COALESCE(NULLIF(jo.job_order_number,''),CONCAT('JO-',jo.id))) AS ref_no,
        CASE WHEN LOWER(COALESCE(jo.status,'')) IN ('cancelled','rejected') THEN 'Cancelled'
             WHEN LOWER(COALESCE(jo.status,'')) IN ('completed','released','approved','verified') THEN 'Success'
             ELSE 'Pending' END AS status,
        '' AS shift_period,
        CONCAT('Service: ',COALESCE(jo.service_type,'N/A'),' | Plate: ',COALESCE(jo.vehicle_plate,'N/A'),' | \u20b1',FORMAT(COALESCE(jo.total_cost,jo.estimated_cost,0),2)) AS details
        FROM job_orders jo LEFT JOIN users u ON u.id=COALESCE(jo.created_by,jo.user_id)
        WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 4. Stock Requests
if (mgr_tbl_exists($pdo, 'stock_requests')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND sr.staff_id=?'; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT sr.created_at AS datetime,'Inventory' AS module,'Stock Request' AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,CONCAT('Staff #',sr.staff_id)) AS actor,
        COALESCE(NULLIF(sr.request_no,''),CONCAT('SR-',sr.id)) AS ref_no,
        CASE WHEN LOWER(COALESCE(sr.status,'')) IN ('rejected','cancelled') THEN 'Cancelled'
             WHEN LOWER(COALESCE(sr.status,'')) IN ('approved','fulfilled') THEN 'Success'
             ELSE 'Pending' END AS status,
        '' AS shift_period,
        CONCAT('Item: ',COALESCE(sr.item_name,'N/A'),' | Qty: ',COALESCE(sr.requested_quantity,0)) AS details
        FROM stock_requests sr LEFT JOIN users u ON u.id=sr.staff_id
        WHERE sr.station_id=? AND DATE(sr.created_at) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 5. Master Data Requests
if (mgr_tbl_exists($pdo, 'master_data_requests')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND mdr.requested_by=?'; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT mdr.created_at AS datetime,'Master Data' AS module,'Master Data Request' AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,CONCAT('User #',mdr.requested_by)) AS actor,
        COALESCE(NULLIF(mdr.request_no,''),CONCAT('MDR-',mdr.id)) AS ref_no,
        CASE WHEN LOWER(COALESCE(mdr.status,'')) IN ('rejected','cancelled') THEN 'Cancelled'
             WHEN LOWER(COALESCE(mdr.status,'')) IN ('approved','completed') THEN 'Success'
             ELSE 'Pending' END AS status,
        '' AS shift_period,
        CONCAT('Category: ',COALESCE(mdr.category,'N/A'),' | Module: ',COALESCE(mdr.source_module,'N/A')) AS details
        FROM master_data_requests mdr LEFT JOIN users u ON u.id=mdr.requested_by
        WHERE mdr.station_id=? AND DATE(mdr.created_at) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 6. Transaction Adjustments
if (mgr_tbl_exists($pdo, 'transaction_adjustments')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND ta.adjusted_by=?'; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT COALESCE(ta.adjustment_date,NOW()) AS datetime,'Sales' AS module,'Adjustment Request' AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,CONCAT('User #',ta.adjusted_by)) AS actor,
        CONCAT('ADJ-',ta.id) AS ref_no,'Pending' AS status,'' AS shift_period,
        CONCAT('Txn: ',COALESCE(ta.transaction_id,'N/A'),' | Diff: \u20b1',FORMAT(COALESCE(ta.amount_difference,0),2),' | ',COALESCE(ta.adjustment_reason,'')) AS details
        FROM transaction_adjustments ta LEFT JOIN users u ON u.id=ta.adjusted_by
        WHERE ta.station_id=? AND DATE(COALESCE(ta.adjustment_date,NOW())) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 7. Fuel Sales Closings / Shift Reports
if (mgr_tbl_exists($pdo, 'shift_reports')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND (sr.user_id=? OR sr.created_by=? OR sr.staff_id=?)'; $p[] = $filter_staff; $p[] = $filter_staff; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT sr.created_at AS datetime,'Fuel Management' AS module,'Fuel Sales Closing' AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,'Staff') AS actor,
        CONCAT('STR-',sr.id) AS ref_no,
        CASE WHEN LOWER(COALESCE(sr.status,'')) IN ('rejected','cancelled') THEN 'Cancelled'
             WHEN LOWER(COALESCE(sr.status,'')) IN ('finalized','approved','completed') THEN 'Success'
             ELSE 'Pending' END AS status,
        COALESCE(sr.shift,'') AS shift_period,
        CONCAT('Shift: ',COALESCE(sr.shift,'N/A'),' | Date: ',COALESCE(sr.report_date,'N/A')) AS details
        FROM shift_reports sr LEFT JOIN users u ON u.id=COALESCE(sr.user_id,sr.created_by,sr.staff_id)
        WHERE sr.station_id=? AND DATE(sr.created_at) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 8. User Form Drafts
if (mgr_tbl_exists($pdo, 'user_form_drafts')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND ufd.user_id=?'; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT ufd.updated_at AS datetime,'Drafts' AS module,'Draft Saved' AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,CONCAT('User #',ufd.user_id)) AS actor,
        CONCAT('DFT-',ufd.id) AS ref_no,'Pending' AS status,'' AS shift_period,
        CONCAT('Form: ',REPLACE(ufd.module_key,'_',' ')) AS details
        FROM user_form_drafts ufd LEFT JOIN users u ON u.id=ufd.user_id
        WHERE ufd.station_id=? AND DATE(ufd.updated_at) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 9. Activity Logs — Login/Logout/Clock (INNER JOIN on station_id)
if (mgr_tbl_exists($pdo, 'activity_logs')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND al.user_id=?'; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT al.created_at AS datetime,
        CASE WHEN LOWER(COALESCE(al.action,'')) LIKE '%login%' OR LOWER(COALESCE(al.action,'')) LIKE '%logout%' OR LOWER(COALESCE(al.action,'')) LIKE '%clock%' THEN 'Auth / Session'
             WHEN LOWER(COALESCE(al.action,'')) LIKE '%fuel%' THEN 'Fuel Management'
             WHEN LOWER(COALESCE(al.action,'')) LIKE '%stock%' OR LOWER(COALESCE(al.action,'')) LIKE '%invent%' THEN 'Inventory'
             WHEN LOWER(COALESCE(al.action,'')) LIKE '%job%' THEN 'Job Orders'
             WHEN LOWER(COALESCE(al.action,'')) LIKE '%report%' THEN 'Reports'
             WHEN LOWER(COALESCE(al.action,'')) LIKE '%draft%' THEN 'Drafts'
             ELSE 'Sales' END AS module,
        COALESCE(al.action,'Action') AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,CONCAT('User #',al.user_id)) AS actor,
        CONCAT('ACT-',al.id) AS ref_no,'Success' AS status,'' AS shift_period,COALESCE(al.details,'') AS details
        FROM activity_logs al INNER JOIN users u ON u.id=al.user_id AND u.station_id=?
        WHERE DATE(al.created_at) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 10. Audit Logs — Approvals, Rejections, Status Changes, Manager Actions
if (mgr_tbl_exists($pdo, 'audit_logs')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND al.user_id=?'; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT al.created_at AS datetime,
        CASE WHEN LOWER(COALESCE(al.action_type,'')) LIKE '%login%' OR LOWER(COALESCE(al.action_type,'')) LIKE '%logout%' THEN 'Auth / Session'
             WHEN LOWER(COALESCE(al.action_type,'')) LIKE '%approv%' OR LOWER(COALESCE(al.action_type,'')) LIKE '%reject%' OR LOWER(COALESCE(al.action_type,'')) LIKE '%revis%' THEN 'Approvals'
             WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%fuel%' THEN 'Fuel Management'
             WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%job%' OR LOWER(COALESCE(al.log_type,'')) LIKE '%service%' THEN 'Job Orders'
             WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%merch%' OR LOWER(COALESCE(al.log_type,'')) LIKE '%sale%' THEN 'Sales'
             WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%invent%' OR LOWER(COALESCE(al.log_type,'')) LIKE '%stock%' THEN 'Inventory'
             WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%report%' THEN 'Reports'
             WHEN LOWER(COALESCE(al.log_type,'')) LIKE '%draft%' THEN 'Drafts'
             ELSE 'Sales' END AS module,
        COALESCE(NULLIF(al.action_type,''),'System Action') AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,CONCAT('User #',al.user_id)) AS actor,
        CONCAT('LOG-',al.id) AS ref_no,
        CASE WHEN LOWER(COALESCE(al.status,'')) IN ('failed','error','cancelled') THEN 'Cancelled'
             WHEN LOWER(COALESCE(al.status,'')) IN ('success','logged','ok') THEN 'Success'
             ELSE 'Pending' END AS status,
        '' AS shift_period,COALESCE(al.action_details,'') AS details
        FROM audit_logs al INNER JOIN users u ON u.id=al.user_id AND u.station_id=?
        WHERE DATE(al.created_at) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 11. Stock-In Records
if (mgr_tbl_exists($pdo, 'stock_in_records')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND si.received_by=?'; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT si.received_at AS datetime,'Inventory' AS module,'Stock-In Verified' AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,'Manager') AS actor,
        CONCAT('SIN-',si.id) AS ref_no,
        CASE WHEN LOWER(COALESCE(si.status,'')) IN ('rejected','cancelled') THEN 'Cancelled'
             WHEN LOWER(COALESCE(si.status,'')) IN ('verified','approved','received') THEN 'Success'
             ELSE 'Pending' END AS status,
        '' AS shift_period,
        CONCAT('Item: ',COALESCE(si.product_name,'N/A'),' | Qty: ',COALESCE(si.quantity_received,0)) AS details
        FROM stock_in_records si LEFT JOIN users u ON u.id=si.received_by
        WHERE si.station_id=? AND DATE(si.received_at) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// 12. Purchase Orders
if (mgr_tbl_exists($pdo, 'purchase_orders')) { try {
    $p = [$station_id, $date_from, $date_to]; $x = '';
    if ($filter_staff > 0) { $x .= ' AND po.created_by=?'; $p[] = $filter_staff; }
    $st = $pdo->prepare("SELECT po.created_at AS datetime,'Procurement' AS module,'Purchase Order' AS category,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username,'Manager') AS actor,
        COALESCE(NULLIF(po.po_number,''),CONCAT('PO-',po.id)) AS ref_no,
        CASE WHEN LOWER(COALESCE(po.status,'')) IN ('rejected','cancelled') THEN 'Cancelled'
             WHEN LOWER(COALESCE(po.status,'')) IN ('approved','delivered','received','completed') THEN 'Success'
             ELSE 'Pending' END AS status,
        '' AS shift_period,
        CONCAT('Supplier: ',COALESCE(po.supplier_name,'N/A'),' | Total: \u20b1',FORMAT(COALESCE(po.total_amount,0),2)) AS details
        FROM purchase_orders po LEFT JOIN users u ON u.id=po.created_by
        WHERE po.station_id=? AND DATE(po.created_at) BETWEEN ? AND ? $x");
    $st->execute($p); $raw = array_merge($raw, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {} }

// ── PHP-side Filtering ────────────────────────────────────────────────────────
$filtered = [];
foreach ($raw as $r) {
    if ($filter_module   !== '' && strtolower($r['module'])   !== strtolower($filter_module))   continue;
    if ($filter_category !== '' && strtolower($r['category']) !== strtolower($filter_category)) continue;
    if ($filter_status   !== '' && strtolower($r['status'])   !== strtolower($filter_status))   continue;
    if ($filter_search !== '') {
        $hay = strtolower($r['module'].' '.$r['category'].' '.$r['actor'].' '.$r['ref_no'].' '.$r['details']);
        if (strpos($hay, strtolower($filter_search)) === false) continue;
    }
    $filtered[] = $r;
}

// Sort DESC, deduplicate
usort($filtered, fn($a,$b) => strtotime($b['datetime']) <=> strtotime($a['datetime']));
$unique = []; $seen = [];
foreach ($filtered as $r) {
    $k = $r['module'].'|'.$r['category'].'|'.$r['ref_no'].'|'.substr($r['datetime'],0,16);
    if (!isset($seen[$k])) { $seen[$k] = true; $unique[] = $r; }
}

// ── KPIs ─────────────────────────────────────────────────────────────────────
$total      = count($unique);
$n_approve  = count(array_filter($unique, fn($r) => str_contains(strtolower($r['category']),'approv')));
$n_reject   = count(array_filter($unique, fn($r) => str_contains(strtolower($r['category']),'reject') || str_contains(strtolower($r['category']),'return')));
$n_staff_tx = count(array_filter($unique, fn($r) => $r['module'] === 'Sales'));
$n_fuel     = count(array_filter($unique, fn($r) => $r['module'] === 'Fuel Management'));

// ── Exports ───────────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'excel') {
    $fn = 'Manager_Audit_Trail_'.date('Ymd',strtotime($date_from)).'_to_'.date('Ymd',strtotime($date_to)).'.xls';
    header('Content-Type: application/vnd.ms-excel');
    header("Content-Disposition: attachment; filename=\"$fn\"");
    header('Cache-Control: max-age=0');
    echo '<table border="1"><tr><th>Date/Time</th><th>Module</th><th>Category</th><th>Actor</th><th>Reference</th><th>Status</th><th>Details</th></tr>';
    foreach ($unique as $r) {
        echo '<tr><td>'.htmlspecialchars(date('Y-m-d H:i:s',strtotime($r['datetime']))).'</td>';
        echo '<td>'.htmlspecialchars($r['module']).'</td><td>'.htmlspecialchars($r['category']).'</td>';
        echo '<td>'.htmlspecialchars($r['actor']).'</td><td>'.htmlspecialchars($r['ref_no']).'</td>';
        echo '<td>'.htmlspecialchars($r['status']).'</td><td>'.htmlspecialchars($r['details']).'</td></tr>';
    }
    echo '</table>'; exit;
}
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Manager_Audit_Trail_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Date/Time','Module','Category','Actor','Reference','Status','Details']);
    foreach ($unique as $r) fputcsv($out, [date('Y-m-d H:i:s',strtotime($r['datetime'])),$r['module'],$r['category'],$r['actor'],$r['ref_no'],$r['status'],$r['details']]);
    fclose($out); exit;
}

// ── AJAX JSON POLLING ENDPOINT FOR MANAGER AUDIT TRAIL ─────────────────────────
if (isset($_GET['ajax_mat']) && $_GET['ajax_mat'] == '1') {
    header('Content-Type: application/json');
    $count = count($unique ?? []);
    $firstRows = array_slice($unique ?? [], 0, 30);
    $signature = md5(json_encode($firstRows) . '_' . $count);
    echo json_encode([
        'success'   => true,
        'count'     => $count,
        'signature' => $signature
    ]);
    exit;
}

// ── HTML ──────────────────────────────────────────────────────────────────────
include __DIR__ . '/../partials/header.php';
?>
<style>
.aat-wrap{max-width:1600px;margin:0 auto;padding:0 16px 80px}
.aat-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px}
.aat-head h1{margin:0;font-size:20px;font-weight:800;color:#002244;display:flex;align-items:center;gap:8px}
.aat-head p{margin:4px 0 0;font-size:13px;color:#64748b}
.aat-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.aat-kpi-c{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.aat-kpi-n{font-size:26px;font-weight:900;line-height:1}
.aat-kpi-l{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-top:5px;font-weight:700}
.aat-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.aat-chead{background:#002F6C;color:#fff;padding:13px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.aat-chead h3{margin:0;font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px}
.aat-cbody{padding:16px 20px}
.aat-frow{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.aat-fgrp{display:flex;flex-direction:column;gap:4px}
.aat-flbl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.aat-inp{height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none}
.aat-inp:focus{border-color:#002F6C;box-shadow:0 0 0 3px rgba(0,47,108,.1)}
.aat-btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .15s;white-space:nowrap}
.aat-btn-blue{color:#002F6C;border-color:#002F6C;background:#fff}.aat-btn-blue:hover{background:#002F6C;color:#fff}
.aat-btn-gray{color:#4b5563;border-color:#6b7280;background:#fff}.aat-btn-gray:hover{background:#6b7280;color:#fff}
.aat-btn-grn{color:#16a34a;border-color:#16a34a;background:#fff}.aat-btn-grn:hover{background:#16a34a;color:#fff}
.aat-btn-xl{color:#15803d;border-color:#15803d;background:#fff}.aat-btn-xl:hover{background:#15803d;color:#fff}
.aat-tbl{width:100%;border-collapse:collapse;font-size:12px}
.aat-tbl thead th{background:#002F6C;color:#fff;padding:9px 8px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.aat-tbl tbody td{padding:8px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#1e293b}
.aat-tbl tbody tr:hover td{background:#f0f7ff}
.aat-tbl tbody tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap}
.badge-success{background:#dcfce7;color:#166534;border:1px solid #86efac}
.badge-pending{background:#fef9c3;color:#854d0e;border:1px solid #fde047}
.badge-cancel{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.badge-sales{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.badge-fuel{background:#faf5ff;color:#7e22ce;border:1px solid #d8b4fe}
.badge-inv{background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7}
.badge-auth{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
.badge-approval{background:#f0fdf4;color:#15803d;border:1px solid #86efac}
.badge-other{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0}
.aat-notice{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 16px;font-size:12px;color:#1d4ed8;margin-bottom:16px;display:flex;align-items:center;gap:8px}
@media(max-width:768px){.aat-frow{flex-direction:column}.aat-inp{width:100%!important}.aat-kpi{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="aat-wrap">

<!-- Page Header -->
<div class="aat-head">
    <div>
        <h1><i class="fas fa-shield-alt"></i> Manager Audit Trail</h1>
        <p>Comprehensive branch-wide activity log — all staff and manager actions within this station.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'excel'])) ?>" class="aat-btn aat-btn-xl"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="aat-btn aat-btn-grn"><i class="fas fa-file-csv"></i> CSV</a>
        <a href="manager_dashboard.php" class="aat-btn aat-btn-gray"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- KPI Strip -->
<div class="aat-kpi">
    <div class="aat-kpi-c"><div class="aat-kpi-n" style="color:#002F6C;"><?= number_format($total) ?></div><div class="aat-kpi-l">Total Records</div></div>
    <div class="aat-kpi-c"><div class="aat-kpi-n" style="color:#16a34a;"><?= number_format($n_staff_tx) ?></div><div class="aat-kpi-l">Sales Txns</div></div>
    <div class="aat-kpi-c"><div class="aat-kpi-n" style="color:#7e22ce;"><?= number_format($n_fuel) ?></div><div class="aat-kpi-l">Fuel Actions</div></div>
    <div class="aat-kpi-c"><div class="aat-kpi-n" style="color:#15803d;"><?= number_format($n_approve) ?></div><div class="aat-kpi-l">Approvals</div></div>
    <div class="aat-kpi-c"><div class="aat-kpi-n" style="color:#dc2626;"><?= number_format($n_reject) ?></div><div class="aat-kpi-l">Rejections</div></div>
</div>

<!-- Notice -->
<div class="aat-notice">
    <i class="fas fa-lock"></i>
    <span><strong>Read-only compliance log.</strong> All records are automatically captured and immutable. Covers all staff and manager actions branch-wide.</span>
</div>

<!-- Filters -->
<div class="aat-card">
    <div class="aat-chead"><h3><i class="fas fa-filter"></i> Filters</h3></div>
    <div class="aat-cbody">
        <form method="get" class="aat-frow">
            <div class="aat-fgrp"><label class="aat-flbl">Date From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="aat-inp" max="<?= date('Y-m-d') ?>"></div>
            <div class="aat-fgrp"><label class="aat-flbl">Date To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="aat-inp" max="<?= date('Y-m-d') ?>"></div>
            <div class="aat-fgrp"><label class="aat-flbl">Module</label>
                <select name="module" class="aat-inp" style="width:155px;">
                    <option value="">All Modules</option>
                    <?php foreach (['Sales','Fuel Management','Job Orders','Inventory','Master Data','Procurement','Auth / Session','Approvals','Reports','Drafts'] as $m): ?>
                    <option value="<?= $m ?>" <?= strtolower($filter_module)===strtolower($m)?'selected':'' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="aat-fgrp"><label class="aat-flbl">Category</label>
                <select name="category" class="aat-inp" style="width:190px;">
                    <option value="">All Categories</option>
                    <?php foreach ([
                        'Login','Logout',
                        'Merchandise Sale','Void Request','Adjustment Request','Processed Return',
                        'Fuel Meter Reading','Fuel Sales Closing',
                        'Created Job Order','Updated Job Order',
                        'Stock Request','Stock-In Verified',
                        'Master Data Request','Purchase Order','Draft Saved',
                    ] as $c): ?>
                    <option value="<?= $c ?>" <?= strtolower($filter_category)===strtolower($c)?'selected':'' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select></div>
            <?php if (!empty($staff_list)): ?>
            <div class="aat-fgrp"><label class="aat-flbl">Personnel</label>
                <select name="staff_id" class="aat-inp" style="width:175px;">
                    <option value="0">All Personnel</option>
                    <?php foreach ($staff_list as $sl):
                        $fn = trim($sl['full_name']) ?: ('User #'.$sl['id']); ?>
                    <option value="<?= (int)$sl['id'] ?>" <?= $filter_staff===(int)$sl['id']?'selected':'' ?>><?= htmlspecialchars($fn) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <?php endif; ?>
            <div class="aat-fgrp"><label class="aat-flbl">Status</label>
                <select name="status" class="aat-inp" style="width:115px;">
                    <option value="">All Statuses</option>
                    <option value="Success"   <?= strtolower($filter_status)==='success'  ?'selected':''?>>Success</option>
                    <option value="Pending"   <?= strtolower($filter_status)==='pending'  ?'selected':''?>>Pending</option>
                    <option value="Cancelled" <?= strtolower($filter_status)==='cancelled'?'selected':''?>>Cancelled</option>
                </select></div>
            <div class="aat-fgrp"><label class="aat-flbl">Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($filter_search) ?>" class="aat-inp" placeholder="Ref, actor, details…" style="width:175px;"></div>
            <div class="aat-fgrp"><label class="aat-flbl">&nbsp;</label>
                <div style="display:flex;gap:6px;">
                    <button type="submit" class="aat-btn aat-btn-blue"><i class="fas fa-search"></i> Apply</button>
                    <a href="manager_audit_trail.php" class="aat-btn aat-btn-gray"><i class="fas fa-rotate-left"></i> Reset</a>
                </div></div>
        </form>
    </div>
</div>

<!-- Audit Table -->
<div class="aat-card">
    <div class="aat-chead">
        <h3><i class="fas fa-list-alt"></i> Audit Records
            <span style="background:rgba(255,255,255,.2);padding:2px 10px;border-radius:8px;font-size:12px;margin-left:6px;">
                <?= number_format($total) ?> <?= $total===1?'record':'records' ?>
            </span>
        </h3>
        <span style="font-size:11px;color:rgba(255,255,255,.7);">
            <?= date('M d, Y',strtotime($date_from)) ?> — <?= date('M d, Y',strtotime($date_to)) ?>
        </span>
    </div>
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
    <table class="aat-tbl">
        <thead>
            <tr>
                <th style="width:105px;">Date / Time</th>
                <th style="width:115px;">Module</th>
                <th style="width:165px;">Category / Action</th>
                <th style="width:145px;">Performed By</th>
                <th style="width:105px;">Reference</th>
                <th style="width:85px;">Status</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($unique)): ?>
        <tr><td colspan="7" style="text-align:center;padding:50px 20px;color:#94a3b8;">
            <i class="fas fa-clipboard-list" style="font-size:32px;display:block;margin-bottom:10px;opacity:.25;"></i>
            No audit records found for the selected filters.
        </td></tr>
        <?php else: foreach ($unique as $r):
            $mod_lc = strtolower($r['module'] ?? '');
            $cat_lc = strtolower($r['category'] ?? '');
            $sts_lc = strtolower($r['status'] ?? '');

            if ($mod_lc === 'fuel management')                            $mc = 'badge-fuel';
            elseif ($mod_lc === 'auth / session')                         $mc = 'badge-auth';
            elseif ($mod_lc === 'approvals')                              $mc = 'badge-approval';
            elseif ($mod_lc === 'inventory' || $mod_lc === 'procurement') $mc = 'badge-inv';
            elseif ($mod_lc === 'sales')                                  $mc = 'badge-sales';
            else                                                          $mc = 'badge-other';

            $sc = $sts_lc === 'success' ? 'badge-success' : ($sts_lc === 'cancelled' ? 'badge-cancel' : 'badge-pending');

            if      (str_contains($cat_lc,'approv'))  $ic = '<i class="fas fa-check-circle" style="color:#16a34a;font-size:10px;"></i>';
            elseif  (str_contains($cat_lc,'reject'))  $ic = '<i class="fas fa-times-circle" style="color:#dc2626;font-size:10px;"></i>';
            elseif  (str_contains($cat_lc,'return'))  $ic = '<i class="fas fa-undo" style="color:#d97706;font-size:10px;"></i>';
            elseif  (str_contains($cat_lc,'adjust'))  $ic = '<i class="fas fa-pen" style="color:#1d4ed8;font-size:10px;"></i>';
            elseif  (str_contains($cat_lc,'void'))    $ic = '<i class="fas fa-ban" style="color:#dc2626;font-size:10px;"></i>';
            elseif  (str_contains($cat_lc,'login'))   $ic = '<i class="fas fa-sign-in-alt" style="color:#7e22ce;font-size:10px;"></i>';
            elseif  (str_contains($cat_lc,'logout'))  $ic = '<i class="fas fa-sign-out-alt" style="color:#c2410c;font-size:10px;"></i>';
            elseif  (str_contains($cat_lc,'draft'))   $ic = '<i class="fas fa-file-alt" style="color:#64748b;font-size:10px;"></i>';
            elseif  (str_contains($cat_lc,'fuel'))    $ic = '<i class="fas fa-gas-pump" style="color:#7e22ce;font-size:10px;"></i>';
            elseif  (str_contains($cat_lc,'stock'))   $ic = '<i class="fas fa-boxes" style="color:#065f46;font-size:10px;"></i>';
            else                                      $ic = '<i class="fas fa-circle" style="color:#94a3b8;font-size:7px;"></i>';
        ?>
        <tr>
            <td style="white-space:nowrap;color:#64748b;font-size:11px;">
                <?= htmlspecialchars(date('M j, Y', strtotime($r['datetime']))) ?><br>
                <span style="font-size:10px;color:#94a3b8;"><?= htmlspecialchars(date('H:i:s', strtotime($r['datetime']))) ?></span>
            </td>
            <td><span class="badge <?= $mc ?>"><?= htmlspecialchars($r['module']) ?></span></td>
            <td style="font-weight:600;font-size:12px;white-space:nowrap;">
                <?= $ic ?>&nbsp;<?= htmlspecialchars($r['category']) ?>
            </td>
            <td style="font-size:12px;" title="<?= htmlspecialchars($r['actor']) ?>">
                <?= htmlspecialchars(mb_strimwidth($r['actor'],0,24,'…')) ?>
            </td>
            <td style="font-family:monospace;font-size:11px;color:#002F6C;font-weight:700;">
                <?= htmlspecialchars($r['ref_no']) ?>
            </td>
            <td><span class="badge <?= $sc ?>"><?= htmlspecialchars($r['status']) ?></span></td>
            <td style="font-size:11px;color:#475569;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                title="<?= htmlspecialchars($r['details']) ?>">
                <?= htmlspecialchars(mb_strimwidth($r['details'],0,90,'…')) ?: '—' ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

</div><!-- /aat-wrap -->
<script>
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
let lastMatSignature = null;
let lastMatCount = null;

function autoRefreshManagerAuditTrail() {
    const openModal = Array.from(document.querySelectorAll('.modal, .modal-overlay, [id*="Modal"]')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    if (openModal) return;

    if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA' || document.activeElement.tagName === 'SELECT')) {
        return;
    }

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_mat', '1');

    fetch(currentUrl.toString(), { cache: 'no-store', credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                if (lastMatSignature !== null && (lastMatSignature !== data.signature || lastMatCount !== data.count)) {
                    window.location.reload();
                }
                lastMatSignature = data.signature;
                lastMatCount = data.count;
            }
        })
        .catch(() => {});
}

setInterval(autoRefreshManagerAuditTrail, 2000);
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
