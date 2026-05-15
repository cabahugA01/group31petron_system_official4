<?php
/**
 * STAFF REPORTS MODULE - Fixed & Complete
 * All 5 staff report views: Job Orders, Deliveries, Customers, Transactions, Personal Activity
 * Uses correct column names from actual DB schema (created_by, encoded_by, etc.)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$user_id    = (int)$me['id'];
$station_id = user_station_id();

if (!in_array($role, ['staff','manager','admin','superadmin'])) {
    header('Location: dashboard.php'); exit;
}

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

// Default view per role
$view = $_GET['view'] ?? 'daily_sales_summary';

// Date filter — widen default to last 90 days so data always shows
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

// Station name
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) $station_name = $st['name'];
} catch (Exception $e) {}

// ── Helpers ───────────────────────────────────────────────────────────────────
function has_col(PDO $pdo, string $table, string $col): bool {
    try { $r = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'"); return $r && $r->rowCount() > 0; }
    catch (Exception $e) { return false; }
}
function tbl_exists(PDO $pdo, string $table): bool {
    try { $r = $pdo->query("SHOW TABLES LIKE '$table'"); return $r && $r->rowCount() > 0; }
    catch (Exception $e) { return false; }
}
$jo_enc = has_col($pdo, 'job_orders', 'created_by') ? 'created_by' : 'user_id';

// ── EARLY EXPORT HANDLER — must run before header.php outputs anything ────────
$export_param = $_GET['export'] ?? '';
if ($export_param !== '' && in_array($view, ['daily_sales_summary','jo_tracker_report'])) {

    // ── Load data needed for export ───────────────────────────────────────────
    if ($view === 'daily_sales_summary') {
        $daily_sales_merch = []; $daily_sales_jo = [];
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(NULLIF(mt.transaction_id,''),CONCAT('MT-',mt.id)) AS txn_ref,
                       COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in')      AS customer_name,
                       COALESCE(mt.payment_method,'—')                            AS payment_method,
                       COALESCE(mt.total_amount,0)                                AS total_amount,
                       COALESCE(mt.validation_status,'Pending')                   AS status,
                       COALESCE(mt.shift_name,mt.shift_period,'—')                AS shift_ref,
                       mt.created_at                                               AS txn_date,
                       COALESCE((SELECT GROUP_CONCAT(i.product_name ORDER BY i.id SEPARATOR ', ')
                                 FROM merchandise_transaction_items i WHERE i.transaction_id=mt.id),
                                mt.item_sku,mt.job_order_service,'—')             AS items_summary
                FROM merchandise_transactions mt
                WHERE mt.station_id=? AND mt.staff_id=? AND DATE(mt.created_at) BETWEEN ? AND ?
                ORDER BY mt.created_at DESC");
            $stmt->execute([$station_id,$user_id,$date_from,$date_to]);
            $daily_sales_merch = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
        try {
            $cost_col = has_col($pdo,'job_orders','total_cost')
                ? 'COALESCE(jo.total_cost,jo.estimated_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
            $jo_id_col = has_col($pdo,'job_orders','job_order_id')
                ? "COALESCE(NULLIF(jo.job_order_id,''),jo.job_order_number,CONCAT('JO-',jo.id))"
                : "COALESCE(jo.job_order_number,CONCAT('JO-',jo.id))";
            $stmt = $pdo->prepare("
                SELECT $jo_id_col AS txn_ref,
                       COALESCE(jo.customer_name,'Walk-in') AS customer_name,
                       COALESCE(jo.service_type,'—')        AS service_type,
                       COALESCE(jo.vehicle_plate,'—')       AS vehicle_plate,
                       COALESCE(jo.payment_method,'—')      AS payment_method,
                       $cost_col                            AS total_amount,
                       COALESCE(jo.validation_status,jo.status,'Pending') AS status,
                       jo.created_at                        AS txn_date
                FROM job_orders jo
                WHERE jo.station_id=? AND jo.created_by=? AND DATE(jo.created_at) BETWEEN ? AND ?
                ORDER BY jo.created_at DESC");
            $stmt->execute([$station_id,$user_id,$date_from,$date_to]);
            $daily_sales_jo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        $all_rows = [];
        foreach ($daily_sales_merch as $r)
            $all_rows[] = ["type"=>"Merchandise","ref"=>$r["txn_ref"],"customer"=>$r["customer_name"],"detail"=>$r["items_summary"],"payment"=>$r["payment_method"],"amount"=>$r["total_amount"],"status"=>$r["status"],"date"=>$r["txn_date"]];
        foreach ($daily_sales_jo as $r)
            $all_rows[] = ["type"=>"Job Order","ref"=>$r["txn_ref"],"customer"=>$r["customer_name"],"detail"=>$r["service_type"],"payment"=>$r["payment_method"],"amount"=>$r["total_amount"],"status"=>$r["status"],"date"=>$r["txn_date"]];
        usort($all_rows, fn($a,$b) => strtotime($b["date"]) - strtotime($a["date"]));
        $gt           = array_sum(array_column($all_rows,"amount"));
        $merch_total  = array_sum(array_column($daily_sales_merch,"total_amount"));
        $jo_total     = array_sum(array_column($daily_sales_jo,"total_amount"));

        if ($export_param === 'excel') {
            header("Content-Type: application/vnd.ms-excel; charset=utf-8");
            header("Content-Disposition: attachment; filename=\"daily_sales_" . date("Y-m-d") . ".xls\"");
            header("Pragma: no-cache"); header("Expires: 0");
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
body{font-family:Arial,sans-serif;font-size:11px;}
.hdr{background:#002F6C;color:#fff;font-size:14px;font-weight:bold;padding:10px 14px;}
.meta{background:#f0f4ff;font-size:10px;padding:6px 14px;color:#334155;}
.sum{background:#e8f0fe;font-size:10px;padding:6px 14px;}
table{border-collapse:collapse;width:100%;margin-top:10px;}
th{background:#002F6C;color:#fff;padding:7px 10px;font-size:10px;text-align:left;border:1px solid #001a4d;}
td{padding:6px 10px;font-size:10px;border:1px solid #e2e8f0;}
tr:nth-child(even) td{background:#f8fafc;}
.grand td{background:#002F6C;color:#fff;font-weight:bold;}
</style></head><body>';
            echo '<div class="hdr">PETRON STATION MANAGEMENT SYSTEM &mdash; Daily Sales Summary</div>';
            echo '<div class="meta">Station: '.htmlspecialchars($station_name).' &nbsp;|&nbsp; Staff: '.htmlspecialchars($me["name"]??"").' &nbsp;|&nbsp; Period: '.htmlspecialchars($date_from).' to '.htmlspecialchars($date_to).' &nbsp;|&nbsp; Generated: '.date("M j, Y h:i A").'</div>';
            echo '<div class="sum">Records: <b>'.count($all_rows).'</b> &nbsp;|&nbsp; Merchandise: <b>&#8369;'.number_format($merch_total,2).'</b> ('.count($daily_sales_merch).') &nbsp;|&nbsp; Job Orders: <b>&#8369;'.number_format($jo_total,2).'</b> ('.count($daily_sales_jo).') &nbsp;|&nbsp; Grand Total: <b>&#8369;'.number_format($gt,2).'</b></div>';
            echo '<table><thead><tr><th>#</th><th>Type</th><th>Reference / ID</th><th>Customer</th><th>Detail / Service</th><th>Payment Method</th><th>Amount (&#8369;)</th><th>Status</th><th>Date &amp; Time</th></tr></thead><tbody>';
            $n=1;
            foreach ($all_rows as $r) {
                echo '<tr><td>'.$n++.'</td><td>'.htmlspecialchars($r["type"]).'</td><td>'.htmlspecialchars($r["ref"]).'</td><td>'.htmlspecialchars($r["customer"]).'</td><td>'.htmlspecialchars($r["detail"]).'</td><td>'.htmlspecialchars($r["payment"]).'</td><td style="text-align:right;font-weight:bold;">'.number_format((float)$r["amount"],2).'</td><td>'.htmlspecialchars($r["status"]).'</td><td>'.date("M j, Y h:i A",strtotime($r["date"])).'</td></tr>';
            }
            echo '<tr class="grand"><td colspan="6" style="text-align:right;">GRAND TOTAL</td><td style="text-align:right;">&#8369;'.number_format($gt,2).'</td><td colspan="2"></td></tr>';
            echo '</tbody></table></body></html>';
            exit;
        }

        if ($export_param === 'pdf') {
            header("Content-Type: text/html; charset=utf-8");
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Daily Sales Summary</title><style>
@page{margin:12mm;}
body{font-family:Arial,sans-serif;font-size:9px;margin:0;color:#1e293b;}
.hdr{background:#002F6C;color:#fff;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;}
.hdr-title{font-size:13px;font-weight:bold;}.hdr-sub{font-size:9px;opacity:.85;}
.meta{background:#f0f4ff;padding:6px 14px;font-size:9px;color:#334155;border-bottom:2px solid #002F6C;}
.sum{display:flex;gap:18px;padding:6px 14px;background:#fff;border-bottom:1px solid #e2e8f0;font-size:9px;}
.sum span{color:#64748b;} .sum b{color:#002F6C;}
table{width:100%;border-collapse:collapse;margin-top:8px;}
th{background:#002F6C;color:#fff;padding:5px 7px;font-size:8px;text-align:left;}
td{padding:4px 7px;font-size:8px;border-bottom:1px solid #f1f5f9;}
tr:nth-child(even) td{background:#f8fafc;}
.grand td{background:#002F6C;color:#fff;font-weight:bold;padding:6px 7px;}
.footer{margin-top:10px;font-size:8px;color:#94a3b8;text-align:center;border-top:1px solid #e2e8f0;padding-top:6px;}
@media print{.no-print{display:none;}}
</style></head><body>';
            echo '<div class="hdr"><div><div class="hdr-title">PETRON STATION MANAGEMENT SYSTEM</div><div class="hdr-sub">Daily Sales Summary Report</div></div><div style="text-align:right;font-size:9px;opacity:.85;">Generated: '.date("M j, Y h:i A").'</div></div>';
            echo '<div class="meta"><b>Station:</b> '.htmlspecialchars($station_name).' &nbsp;&nbsp; <b>Staff:</b> '.htmlspecialchars($me["name"]??"").' &nbsp;&nbsp; <b>Period:</b> '.htmlspecialchars($date_from).' &mdash; '.htmlspecialchars($date_to).'</div>';
            echo '<div class="sum"><span>Records: <b>'.count($all_rows).'</b></span><span>Merchandise: <b>&#8369;'.number_format($merch_total,2).'</b> ('.count($daily_sales_merch).')</span><span>Job Orders: <b>&#8369;'.number_format($jo_total,2).'</b> ('.count($daily_sales_jo).')</span><span>Grand Total: <b>&#8369;'.number_format($gt,2).'</b></span></div>';
            echo '<table><thead><tr><th>#</th><th>Type</th><th>Reference / ID</th><th>Customer</th><th>Detail / Service</th><th>Payment</th><th>Amount (&#8369;)</th><th>Status</th><th>Date &amp; Time</th></tr></thead><tbody>';
            $n=1;
            foreach ($all_rows as $r) {
                $color = $r["type"]==="Job Order" ? "#b45309" : "#065F46";
                echo '<tr><td>'.$n++.'</td><td>'.htmlspecialchars($r["type"]).'</td><td style="font-family:monospace;">'.htmlspecialchars($r["ref"]).'</td><td>'.htmlspecialchars($r["customer"]).'</td><td>'.htmlspecialchars($r["detail"]).'</td><td>'.htmlspecialchars($r["payment"]).'</td><td style="text-align:right;font-weight:bold;color:'.$color.';">&#8369;'.number_format((float)$r["amount"],2).'</td><td>'.htmlspecialchars($r["status"]).'</td><td>'.date("M j, Y h:i A",strtotime($r["date"])).'</td></tr>';
            }
            echo '<tr class="grand"><td colspan="6" style="text-align:right;">GRAND TOTAL</td><td style="text-align:right;">&#8369;'.number_format($gt,2).'</td><td colspan="2"></td></tr>';
            echo '</tbody></table>';
            echo '<div class="footer">Petron Station Management System &mdash; '.htmlspecialchars($station_name).' &mdash; Printed: '.date("M j, Y h:i A").'</div>';
            echo '<div class="no-print" style="text-align:center;margin-top:16px;"><button onclick="window.print()" style="padding:10px 24px;background:#002F6C;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Print / Save as PDF</button></div>';
            echo '</body></html>';
            exit;
        }
    }

    if ($view === 'jo_tracker_report') {
        $filter_staff_id = (int)($_GET['staff_id'] ?? $user_id);
        if ($role === 'staff') $filter_staff_id = $user_id;
        $jo_tracker_report = [];
        try {
            $cost_col  = has_col($pdo,'job_orders','total_cost') ? 'COALESCE(jo.total_cost,jo.estimated_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
            $pay_col   = has_col($pdo,'job_orders','payment_status') ? "COALESCE(jo.payment_status,'Unpaid')" : "'Unpaid'";
            $mech_join = has_col($pdo,'job_orders','assigned_mechanic_id') ? "LEFT JOIN users mu ON mu.id=jo.assigned_mechanic_id" : "";
            $mech_col  = has_col($pdo,'job_orders','assigned_mechanic_id') ? "COALESCE(mu.name,mu.username,'Unassigned')" : "'Unassigned'";
            $parts_col = has_col($pdo,'job_orders','required_parts') ? 'jo.required_parts' : "''";
            $jo_id_col = has_col($pdo,'job_orders','job_order_id')
                ? "COALESCE(NULLIF(jo.job_order_id,''),jo.job_order_number,CONCAT('JO-',jo.id))"
                : "COALESCE(jo.job_order_number,CONCAT('JO-',jo.id))";
            $where_staff = $filter_staff_id > 0 ? "AND jo.created_by=?" : "";
            $params = [$station_id,$date_from,$date_to];
            if ($filter_staff_id > 0) $params[] = $filter_staff_id;
            $stmt = $pdo->prepare("
                SELECT jo.id, $jo_id_col AS job_order_id,
                       COALESCE(jo.customer_name,'Walk-in') AS customer_name,
                       COALESCE(jo.vehicle_plate,'—')       AS vehicle_plate,
                       COALESCE(jo.vehicle_type,'—')        AS vehicle_type,
                       COALESCE(jo.service_type,'—')        AS service_type,
                       $mech_col                            AS mechanic_name,
                       COALESCE(jo.validation_status,'Pending Validation') AS validation_status,
                       COALESCE(jo.status,'Pending')        AS workflow_status,
                       $pay_col                             AS payment_status,
                       $cost_col                            AS total_cost,
                       COALESCE(jo.payment_method,'—')      AS payment_method,
                       COALESCE(jo.additional_notes,jo.notes,jo.admin_remarks,'—') AS remarks,
                       $parts_col                           AS required_parts_raw,
                       COALESCE(su.name,su.username,'—')    AS encoded_by,
                       jo.created_at
                FROM job_orders jo $mech_join
                LEFT JOIN users su ON su.id=jo.created_by
                WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ? $where_staff
                ORDER BY jo.created_at DESC");
            $stmt->execute($params);
            $jo_tracker_report = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        // Helper: decode parts
        $decode_parts = function(string $raw, int $max=80): string {
            if (empty($raw)) return '—';
            $dec = json_decode($raw, true);
            if (is_array($dec)) $pd = implode(', ', array_filter(array_map(fn($p) => is_array($p) ? ($p['name'] ?? $p['part_name'] ?? '') : $p, $dec)));
            else $pd = $raw;
            return strlen($pd) > $max ? substr($pd, 0, $max-3).'…' : $pd;
        };
        // Helper: workflow label
        $wf_label = function(string $ws, string $vs): string {
            $ws = strtolower(trim($ws)); $vs = strtolower(trim($vs));
            if ($ws==='completed')                             return 'Completed';
            if ($ws==='in progress'||$ws==='in-progress')     return 'In Progress';
            if ($ws==='rejected'||$ws==='cancelled')          return 'Rejected';
            if ($vs==='approved')                             return 'Approved';
            return 'Pending';
        };

        if ($export_param === 'excel') {
            header("Content-Type: application/vnd.ms-excel; charset=utf-8");
            header("Content-Disposition: attachment; filename=\"jo_tracker_" . date("Y-m-d") . ".xls\"");
            header("Pragma: no-cache"); header("Expires: 0");
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
body{font-family:Arial,sans-serif;font-size:11px;}
.hdr{background:#002F6C;color:#fff;font-size:14px;font-weight:bold;padding:10px 14px;}
.meta{background:#f0f4ff;font-size:10px;padding:6px 14px;color:#334155;}
table{border-collapse:collapse;width:100%;margin-top:10px;}
th{background:#002F6C;color:#fff;padding:7px 10px;font-size:10px;text-align:left;border:1px solid #001a4d;}
td{padding:6px 10px;font-size:10px;border:1px solid #e2e8f0;}
tr:nth-child(even) td{background:#f8fafc;}
</style></head><body>';
            echo '<div class="hdr">PETRON STATION MANAGEMENT SYSTEM &mdash; Job Order Tracker Report</div>';
            echo '<div class="meta">Station: '.htmlspecialchars($station_name).' &nbsp;|&nbsp; Staff: '.htmlspecialchars($me["name"]??"").' &nbsp;|&nbsp; Period: '.htmlspecialchars($date_from).' to '.htmlspecialchars($date_to).' &nbsp;|&nbsp; Records: '.count($jo_tracker_report).' &nbsp;|&nbsp; Generated: '.date("M j, Y h:i A").'</div>';
            echo '<table><thead><tr><th>#</th><th>JO ID</th><th>Customer</th><th>Vehicle</th><th>Service Type</th><th>Items / Parts</th><th>Mechanic</th><th>Encoded By</th><th>Workflow Status</th><th>Payment Status</th><th>Amount (&#8369;)</th><th>Remarks</th><th>Date &amp; Time</th></tr></thead><tbody>';
            $n=1;
            foreach ($jo_tracker_report as $r) {
                $wl = $wf_label($r["workflow_status"], $r["validation_status"]);
                $pd = $decode_parts($r["required_parts_raw"] ?? '');
                echo '<tr><td>'.$n++.'</td><td>'.htmlspecialchars($r["job_order_id"]).'</td><td>'.htmlspecialchars($r["customer_name"]).'</td><td>'.htmlspecialchars($r["vehicle_plate"]).'</td><td>'.htmlspecialchars($r["service_type"]).'</td><td>'.htmlspecialchars($pd).'</td><td>'.htmlspecialchars($r["mechanic_name"]).'</td><td>'.htmlspecialchars($r["encoded_by"]).'</td><td>'.$wl.'</td><td>'.htmlspecialchars($r["payment_status"]).'</td><td style="text-align:right;font-weight:bold;">&#8369;'.number_format((float)$r["total_cost"],2).'</td><td>'.htmlspecialchars($r["remarks"]).'</td><td>'.date("M j, Y h:i A",strtotime($r["created_at"])).'</td></tr>';
            }
            echo '</tbody></table></body></html>';
            exit;
        }

        if ($export_param === 'pdf') {
            header("Content-Type: text/html; charset=utf-8");
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>JO Tracker Report</title><style>
@page{margin:10mm;}
body{font-family:Arial,sans-serif;font-size:8px;margin:0;color:#1e293b;}
.hdr{background:#002F6C;color:#fff;padding:8px 12px;display:flex;justify-content:space-between;align-items:center;}
.hdr-title{font-size:12px;font-weight:bold;}.hdr-sub{font-size:8px;opacity:.85;}
.meta{background:#f0f4ff;padding:5px 12px;font-size:8px;color:#334155;border-bottom:2px solid #002F6C;}
table{width:100%;border-collapse:collapse;margin-top:6px;}
th{background:#002F6C;color:#fff;padding:4px 6px;font-size:7px;text-align:left;}
td{padding:3px 6px;font-size:7px;border-bottom:1px solid #f1f5f9;}
tr:nth-child(even) td{background:#f8fafc;}
.footer{margin-top:10px;font-size:7px;color:#94a3b8;text-align:center;border-top:1px solid #e2e8f0;padding-top:5px;}
@media print{.no-print{display:none;}}
</style></head><body>';
            echo '<div class="hdr"><div><div class="hdr-title">PETRON STATION MANAGEMENT SYSTEM</div><div class="hdr-sub">Job Order Tracker Report</div></div><div style="text-align:right;font-size:8px;opacity:.85;">Generated: '.date("M j, Y h:i A").'</div></div>';
            echo '<div class="meta"><b>Station:</b> '.htmlspecialchars($station_name).' &nbsp;&nbsp; <b>Staff:</b> '.htmlspecialchars($me["name"]??"").' &nbsp;&nbsp; <b>Period:</b> '.htmlspecialchars($date_from).' &mdash; '.htmlspecialchars($date_to).' &nbsp;&nbsp; <b>Records:</b> '.count($jo_tracker_report).'</div>';
            echo '<table><thead><tr><th>#</th><th>JO ID</th><th>Customer</th><th>Vehicle</th><th>Service</th><th>Items/Parts</th><th>Mechanic</th><th>Encoded By</th><th>Workflow</th><th>Payment</th><th>Amount (&#8369;)</th><th>Remarks</th><th>Date</th></tr></thead><tbody>';
            $n=1;
            foreach ($jo_tracker_report as $r) {
                $wl = $wf_label($r["workflow_status"], $r["validation_status"]);
                $pd = $decode_parts($r["required_parts_raw"] ?? '', 50);
                echo '<tr><td>'.$n++.'</td><td style="font-family:monospace;">'.htmlspecialchars($r["job_order_id"]).'</td><td>'.htmlspecialchars($r["customer_name"]).'</td><td>'.htmlspecialchars($r["vehicle_plate"]).'</td><td>'.htmlspecialchars($r["service_type"]).'</td><td>'.htmlspecialchars($pd).'</td><td>'.htmlspecialchars($r["mechanic_name"]).'</td><td>'.htmlspecialchars($r["encoded_by"]).'</td><td>'.$wl.'</td><td>'.htmlspecialchars($r["payment_status"]).'</td><td style="text-align:right;font-weight:bold;">&#8369;'.number_format((float)$r["total_cost"],2).'</td><td>'.htmlspecialchars($r["remarks"]).'</td><td>'.date("M j, Y",strtotime($r["created_at"])).'</td></tr>';
            }
            echo '</tbody></table>';
            echo '<div class="footer">Petron Station Management System &mdash; '.htmlspecialchars($station_name).' &mdash; Printed: '.date("M j, Y h:i A").'</div>';
            echo '<div class="no-print" style="text-align:center;margin-top:14px;"><button onclick="window.print()" style="padding:8px 22px;background:#002F6C;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">Print / Save as PDF</button></div>';
            echo '</body></html>';
            exit;
        }
    }
}
// ── END EARLY EXPORT HANDLER ──────────────────────────────────────────────────

// ── 1. JOB ORDER REPORT ───────────────────────────────────────────────────────
$job_orders = [];
if ($view === 'job_order_report') {
    try {
        // validated_by may not exist — check
        $has_vby = has_col($pdo, 'job_orders', 'validated_by');
        $vby_join  = $has_vby ? "LEFT JOIN users mu ON jo.validated_by = mu.id" : "";
        $vby_sel   = $has_vby ? "COALESCE(mu.name, mu.username, 'Not yet validated') AS validated_by_name" : "'—' AS validated_by_name";
        // actual_cost / total_cost — use whichever exists
        $cost_col  = has_col($pdo, 'job_orders', 'actual_cost')   ? 'jo.actual_cost'
                   : (has_col($pdo, 'job_orders', 'total_cost')   ? 'jo.total_cost' : 'NULL');
        $ecost_col = has_col($pdo, 'job_orders', 'estimated_cost') ? 'jo.estimated_cost' : 'NULL';
        $stmt = $pdo->prepare("
            SELECT jo.id,
                   COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-',jo.id)) AS job_order_id,
                   jo.customer_name,
                   COALESCE(jo.vehicle_plate,'—') AS vehicle_plate,
                   COALESCE(jo.vehicle_type,'—')  AS vehicle_type,
                   COALESCE(jo.service_type,'—')  AS service_type,
                   COALESCE(jo.service_description,'—') AS service_description,
                   $ecost_col AS estimated_cost,
                   $cost_col  AS actual_cost,
                   COALESCE(jo.status,'—')            AS status,
                   COALESCE(jo.validation_status,'—') AS validation_status,
                   COALESCE(jo.payment_method,'—')    AS payment_method,
                   COALESCE(jo.priority_level,'—')    AS priority_level,
                   jo.created_at, jo.completed_at,
                   $vby_sel
            FROM job_orders jo
            $vby_join
            WHERE jo.station_id = ? AND jo.$jo_enc = ?
              AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $job_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $job_orders = []; }
}

// ── 2. DELIVERIES REPORT — actual table: deliveries_oversight ─────────────────
$deliveries = [];
if ($view === 'deliveries_report') {
    try {
        // deliveries_oversight columns: id, delivery_type, delivery_ref, supplier,
        // product, quantity, unit, delivery_date, dr_number, encoded_by,
        // station_id, status, admin_id, admin_notes, source_ref, created_at, remarks
        $stmt = $pdo->prepare("
            SELECT d.id,
                   d.delivery_date,
                   d.delivery_type,
                   d.supplier,
                   d.product,
                   d.quantity,
                   d.unit,
                   d.dr_number,
                   d.delivery_ref,
                   d.status,
                   COALESCE(d.remarks,'') AS delivery_notes,
                   COALESCE(d.admin_notes,'') AS admin_notes,
                   d.created_at,
                   COALESCE(au.name, au.username, '—') AS admin_name
            FROM deliveries_oversight d
            LEFT JOIN users au ON d.admin_id = au.id
            WHERE d.station_id = ? AND d.encoded_by = ?
              AND DATE(d.created_at) BETWEEN ? AND ?
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $deliveries = []; }
}

// ── 3. CUSTOMER REPORT ────────────────────────────────────────────────────────
// customers table: current_balance (not outstanding_balance), no account_type
$customer_records = [];
if ($view === 'customer_report') {
    try {
        $bal_col = has_col($pdo, 'customers', 'current_balance') ? 'c.current_balance'
                 : (has_col($pdo, 'customers', 'balance') ? 'c.balance' : 'NULL');
        $stmt = $pdo->prepare("
            SELECT jo.id,
                   COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-',jo.id)) AS job_order_id,
                   jo.customer_name,
                   jo.customer_id,
                   COALESCE(jo.vehicle_plate,'—') AS vehicle_plate,
                   COALESCE(jo.vehicle_type,'—')  AS vehicle_type,
                   COALESCE(jo.payment_method,'—') AS payment_method,
                   COALESCE(jo.estimated_cost,0)  AS estimated_cost,
                   COALESCE(jo.actual_cost, jo.total_cost, 0) AS actual_cost,
                   COALESCE(jo.status,'—')            AS status,
                   COALESCE(jo.validation_status,'—') AS validation_status,
                   jo.created_at,
                   c.credit_limit,
                   $bal_col AS outstanding_balance,
                   COALESCE(c.type, c.account_status, '—') AS account_type
            FROM job_orders jo
            LEFT JOIN customers c ON jo.customer_id = c.id
            WHERE jo.station_id = ? AND jo.$jo_enc = ?
              AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $customer_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $customer_records = []; }
}

// ── 4. TRANSACTION REPORT ─────────────────────────────────────────────────────
// merchandise_transactions: staff_id, transaction_date (can be 0000), created_at
// sales: user_id, created_at, total
$transactions = [];
if ($view === 'transaction_report') {
    try {
        // Merchandise transactions — use created_at as fallback when transaction_date is zero
        $stmt = $pdo->prepare("
            SELECT mt.id,
                   'Merchandise' AS txn_type,
                   CASE WHEN mt.transaction_date > '1970-01-01' THEN mt.transaction_date
                        ELSE mt.created_at END AS txn_date,
                   mt.total_amount AS total,
                   COALESCE(mt.payment_method,'—') AS payment_method,
                   COALESCE(mt.validation_status, 'Completed') AS status,
                   COALESCE(mt.shift_name, mt.shift_period, '') AS shift_ref,
                   COALESCE(mt.remarks,'') AS description
            FROM merchandise_transactions mt
            WHERE mt.station_id = ? AND mt.staff_id = ?
              AND (DATE(mt.transaction_date) BETWEEN ? AND ?
                   OR (mt.transaction_date <= '1970-01-01' AND DATE(mt.created_at) BETWEEN ? AND ?))
            ORDER BY txn_date DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to, $date_from, $date_to]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sales table
        if (tbl_exists($pdo, 'sales')) {
            $stmt = $pdo->prepare("
                SELECT s.id,
                       'Sale' AS txn_type,
                       s.created_at AS txn_date,
                       s.total AS total,
                       COALESCE(s.payment_method,'—') AS payment_method,
                       COALESCE(s.status,'Completed') AS status,
                       '' AS shift_ref,
                       '' AS description
                FROM sales s
                WHERE s.station_id = ? AND s.user_id = ?
                  AND DATE(s.created_at) BETWEEN ? AND ?
                ORDER BY s.created_at DESC
            ");
            $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
            $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        usort($transactions, fn($a,$b) => strtotime($b['txn_date']) - strtotime($a['txn_date']));
    } catch (Exception $e) { $transactions = []; }
}

// ── 5. PERSONAL ACTIVITY REPORT ───────────────────────────────────────────────
$activity = [];
if ($view === 'personal_activity') {
    try {
        // Job orders summary
        $cost_expr = has_col($pdo,'job_orders','actual_cost')
            ? 'COALESCE(actual_cost, estimated_cost, total_cost, 0)'
            : 'COALESCE(total_cost, estimated_cost, 0)';
        $s = $pdo->prepare("SELECT COUNT(*) AS total,
            SUM(CASE WHEN status='Completed'  THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status='Pending'    THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status IN ('In-Progress','In Progress') THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status='Cancelled'  THEN 1 ELSE 0 END) AS cancelled,
            COALESCE(SUM($cost_expr),0) AS total_value
            FROM job_orders WHERE station_id=? AND $jo_enc=?
            AND DATE(created_at) BETWEEN ? AND ?");
        $s->execute([$station_id, $user_id, $date_from, $date_to]);
        $activity['job_orders'] = $s->fetch(PDO::FETCH_ASSOC) ?: [];

        // Deliveries summary — deliveries_oversight table
        $s = $pdo->prepare("SELECT COUNT(*) AS total,
            SUM(CASE WHEN LOWER(status) IN ('validated','confirmed','closed') THEN 1 ELSE 0 END) AS closed,
            SUM(CASE WHEN LOWER(status) = 'pending validation' THEN 1 ELSE 0 END) AS encoded,
            SUM(CASE WHEN LOWER(status) = 'confirmed' THEN 1 ELSE 0 END) AS confirmed
            FROM deliveries_oversight WHERE station_id=? AND encoded_by=?
            AND DATE(created_at) BETWEEN ? AND ?");
        $s->execute([$station_id, $user_id, $date_from, $date_to]);
        $activity['deliveries'] = $s->fetch(PDO::FETCH_ASSOC) ?: [];

        // Transactions summary
        $txn_count = 0; $txn_total = 0.0;
        $s = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS val
            FROM merchandise_transactions WHERE station_id=? AND staff_id=?
            AND (DATE(transaction_date) BETWEEN ? AND ?
                 OR (transaction_date <= '1970-01-01' AND DATE(created_at) BETWEEN ? AND ?))");
        $s->execute([$station_id, $user_id, $date_from, $date_to, $date_from, $date_to]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $txn_count += (int)($r['cnt'] ?? 0);
        $txn_total += (float)($r['val'] ?? 0);

        if (tbl_exists($pdo, 'sales')) {
            $s = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS val
                FROM sales WHERE station_id=? AND user_id=? AND DATE(created_at) BETWEEN ? AND ?");
            $s->execute([$station_id, $user_id, $date_from, $date_to]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $txn_count += (int)($r['cnt'] ?? 0);
            $txn_total += (float)($r['val'] ?? 0);
        }
        $activity['transactions'] = ['count' => $txn_count, 'total' => $txn_total];

        // Customer linkages
        $s = $pdo->prepare("SELECT COUNT(*) AS total,
            SUM(CASE WHEN customer_id IS NOT NULL THEN 1 ELSE 0 END) AS linked,
            SUM(CASE WHEN payment_method='Account Receivable'
                THEN COALESCE(actual_cost, total_cost, estimated_cost, 0) ELSE 0 END) AS ar_total
            FROM job_orders WHERE station_id=? AND $jo_enc=?
            AND DATE(created_at) BETWEEN ? AND ?");
        $s->execute([$station_id, $user_id, $date_from, $date_to]);
        $activity['customers'] = $s->fetch(PDO::FETCH_ASSOC) ?: [];

    } catch (Exception $e) { $activity = []; }
}

// ── NEW A. DAILY SALES SUMMARY ────────────────────────────────────────────────
$daily_sales_merch = [];
$daily_sales_jo    = [];
$daily_sales_total_merch = 0.0;
$daily_sales_total_jo    = 0.0;
if ($view === 'daily_sales_summary') {
    // Widen default range to last 90 days so data always shows on first load
    if (!isset($_GET['date_from'])) $date_from = date('Y-m-d', strtotime('-90 days'));
    if (!isset($_GET['date_to']))   $date_to   = date('Y-m-d');

    // Merchandise transactions — transaction_date is always 0000, use created_at
    try {
        $stmt = $pdo->prepare("
            SELECT mt.id,
                   COALESCE(NULLIF(mt.transaction_id,''), CONCAT('MT-',mt.id))  AS txn_ref,
                   COALESCE(NULLIF(TRIM(mt.customer_name),''), 'Walk-in')       AS customer_name,
                   COALESCE(mt.payment_method,'—')                              AS payment_method,
                   COALESCE(mt.total_amount, 0)                                 AS total_amount,
                   COALESCE(mt.validation_status, 'Pending')                    AS status,
                   COALESCE(mt.shift_name, mt.shift_period, '—')                AS shift_ref,
                   mt.created_at                                                 AS txn_date,
                   COALESCE(
                       (SELECT GROUP_CONCAT(i.product_name ORDER BY i.id SEPARATOR ', ')
                        FROM merchandise_transaction_items i WHERE i.transaction_id = mt.id),
                       mt.item_sku,
                       mt.job_order_service,
                       '—'
                   )                                                             AS items_summary
            FROM merchandise_transactions mt
            WHERE mt.station_id = ?
              AND mt.staff_id   = ?
              AND DATE(mt.created_at) BETWEEN ? AND ?
            ORDER BY mt.created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $daily_sales_merch       = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $daily_sales_total_merch = array_sum(array_column($daily_sales_merch, 'total_amount'));
    } catch (Exception $e) { $daily_sales_merch = []; }

    // Job orders encoded by this staff
    try {
        $cost_col = has_col($pdo,'job_orders','total_cost')
            ? 'COALESCE(jo.total_cost, jo.estimated_cost, 0)'
            : 'COALESCE(jo.estimated_cost, 0)';
        $jo_id_col = has_col($pdo,'job_orders','job_order_id')
            ? "COALESCE(NULLIF(jo.job_order_id,''), jo.job_order_number, CONCAT('JO-',jo.id))"
            : "COALESCE(jo.job_order_number, CONCAT('JO-',jo.id))";
        $stmt = $pdo->prepare("
            SELECT jo.id,
                   $jo_id_col                                                    AS txn_ref,
                   COALESCE(jo.customer_name,'Walk-in')                          AS customer_name,
                   COALESCE(jo.service_type,'—')                                 AS service_type,
                   COALESCE(jo.vehicle_plate,'—')                                AS vehicle_plate,
                   COALESCE(jo.payment_method,'—')                               AS payment_method,
                   $cost_col                                                      AS total_amount,
                   COALESCE(jo.validation_status, jo.status, 'Pending')          AS status,
                   jo.created_at                                                  AS txn_date
            FROM job_orders jo
            WHERE jo.station_id = ?
              AND jo.created_by  = ?
              AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute([$station_id, $user_id, $date_from, $date_to]);
        $daily_sales_jo       = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $daily_sales_total_jo = array_sum(array_column($daily_sales_jo, 'total_amount'));
    } catch (Exception $e) { $daily_sales_jo = []; }
}

// ── NEW B. JOB ORDER TRACKER REPORT ──────────────────────────────────────────
$jo_tracker_report    = [];
$filter_staff_id      = $user_id;
$staff_list_for_filter = [];
if ($view === 'jo_tracker_report') {
    // Widen default range to last 90 days
    if (!isset($_GET['date_from'])) $date_from = date('Y-m-d', strtotime('-90 days'));
    if (!isset($_GET['date_to']))   $date_to   = date('Y-m-d');

    $filter_staff_id = (int)($_GET['staff_id'] ?? $user_id);
    if ($role === 'staff') $filter_staff_id = $user_id; // staff always sees own

    try {
        $cost_col  = has_col($pdo,'job_orders','total_cost')
            ? 'COALESCE(jo.total_cost, jo.estimated_cost, 0)'
            : 'COALESCE(jo.estimated_cost, 0)';
        $pay_col   = has_col($pdo,'job_orders','payment_status')
            ? "COALESCE(jo.payment_status,'Unpaid')"
            : "'Unpaid'";
        $mech_join = has_col($pdo,'job_orders','assigned_mechanic_id')
            ? "LEFT JOIN users mu ON mu.id = jo.assigned_mechanic_id" : "";
        $mech_col  = has_col($pdo,'job_orders','assigned_mechanic_id')
            ? "COALESCE(mu.name, mu.username, 'Unassigned')" : "'Unassigned'";
        $parts_col = has_col($pdo,'job_orders','required_parts')
            ? 'jo.required_parts' : "''";
        $jo_id_col = has_col($pdo,'job_orders','job_order_id')
            ? "COALESCE(NULLIF(jo.job_order_id,''), jo.job_order_number, CONCAT('JO-',jo.id))"
            : "COALESCE(jo.job_order_number, CONCAT('JO-',jo.id))";

        $where_staff = $filter_staff_id > 0 ? "AND jo.created_by = ?" : "";
        $params = [$station_id, $date_from, $date_to];
        if ($filter_staff_id > 0) $params[] = $filter_staff_id;

        $stmt = $pdo->prepare("
            SELECT jo.id,
                   $jo_id_col                                                    AS job_order_id,
                   COALESCE(jo.customer_name,'Walk-in')                          AS customer_name,
                   COALESCE(jo.vehicle_plate,'—')                                AS vehicle_plate,
                   COALESCE(jo.vehicle_type,'—')                                 AS vehicle_type,
                   COALESCE(jo.service_type,'—')                                 AS service_type,
                   $mech_col                                                      AS mechanic_name,
                   COALESCE(jo.validation_status,'Pending Validation')           AS validation_status,
                   COALESCE(jo.status,'Pending')                                 AS workflow_status,
                   $pay_col                                                       AS payment_status,
                   $cost_col                                                      AS total_cost,
                   COALESCE(jo.payment_method,'—')                               AS payment_method,
                   COALESCE(jo.additional_notes, jo.notes, jo.admin_remarks,'—') AS remarks,
                   $parts_col                                                     AS required_parts_raw,
                   COALESCE(su.name, su.username,'—')                            AS encoded_by,
                   jo.created_at
            FROM job_orders jo
            $mech_join
            LEFT JOIN users su ON su.id = jo.created_by
            WHERE jo.station_id = ?
              AND DATE(jo.created_at) BETWEEN ? AND ?
              $where_staff
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute($params);
        $jo_tracker_report = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $jo_tracker_report = []; }

    // Staff dropdown for manager/admin
    if ($role !== 'staff') {
        try {
            $sl = $pdo->prepare("
                SELECT DISTINCT u.id, COALESCE(u.name, u.username) AS name
                FROM job_orders jo
                JOIN users u ON u.id = jo.created_by
                WHERE jo.station_id = ?
                ORDER BY name
            ");
            $sl->execute([$station_id]);
            $staff_list_for_filter = $sl->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $staff_list_for_filter = []; }
    }
}

// ── Status badge helper ───────────────────────────────────────────────────────
function sr_badge(string $status): string {
    $map = [
        'completed'          => ['#D1FAE5','#065F46'],
        'approved'           => ['#D1FAE5','#065F46'],
        'closed'             => ['#D1FAE5','#065F46'],
        'inventory_updated'  => ['#DBEAFE','#1E40AF'],
        'confirmed'          => ['#DBEAFE','#1E40AF'],
        'in-progress'        => ['#DBEAFE','#1E40AF'],
        'in progress'        => ['#DBEAFE','#1E40AF'],
        'pending'            => ['#FFF3CD','#856404'],
        'pending validation' => ['#FFF3CD','#856404'],
        'encoded'            => ['#FFF3CD','#856404'],
        'cancelled'          => ['#FEE2E2','#991B1B'],
        'rejected'           => ['#FEE2E2','#991B1B'],
        'discrepancy_logged' => ['#FEE2E2','#991B1B'],
    ];
    $k = strtolower(trim($status));
    [$bg,$fg] = $map[$k] ?? ['#F3F4F6','#374151'];
    $label = ucwords(str_replace('_',' ',$status));
    return "<span style='background:$bg;color:$fg;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap'>$label</span>";
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Staff Reports page overrides ── */
main.main, .main-content { padding-top: 0 !important; }
.sr-wrap { padding: 0 20px 20px 20px; max-width: 1300px; }
.page-head { margin-top: 0 !important; margin-bottom: 16px; }
.sr-filter { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.sr-filter label { font-size:12px; font-weight:700; color:#667085; }
.sr-filter input[type=date] { padding:6px 10px; border:1px solid #EAEAEA; border-radius:8px; font-size:12px; outline:none; }
.sr-filter input[type=date]:focus { border-color:#00264D; }
.sr-filter-btn { background:#00264D; color:#fff; border:none; padding:6px 14px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }

.sr-card { background:#fff; border:1px solid #EAEAEA; border-radius:14px; overflow:hidden; margin-bottom:20px; }
.sr-card-head { padding:16px 20px; border-bottom:1px solid #f0f0f0; display:flex; align-items:center; justify-content:space-between; }
.sr-card-title { font-size:15px; font-weight:800; color:#101828; display:flex; align-items:center; gap:8px; }
.sr-card-title i { color:#00264D; }
.sr-count { background:#f0f4ff; color:#00264D; font-size:12px; font-weight:700; padding:3px 10px; border-radius:20px; }

.sr-table { width:100%; border-collapse:collapse; table-layout:fixed; }
.sr-table th { background:#f5f6f8; padding:8px 10px; text-align:left; font-size:10px; font-weight:700; color:#667085; text-transform:uppercase; letter-spacing:.3px; border-bottom:2px solid #EAEAEA; word-wrap:break-word; }
.sr-table td { padding:8px 10px; font-size:11px; color:#101828; border-bottom:1px solid #f5f6f8; vertical-align:middle; word-wrap:break-word; overflow-wrap:break-word; }
.sr-table tr:last-child td { border-bottom:none; }
.sr-table tr:hover td { background:#fafbff; }

.sr-empty { text-align:center; padding:48px 20px; color:#9ca3af; font-size:14px; }
.sr-empty i { font-size:32px; display:block; margin-bottom:10px; opacity:.4; }

.sr-stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; padding:18px; }
.sr-stat { background:#f5f6f8; border-radius:12px; padding:16px; text-align:center; }
.sr-stat-num { font-size:28px; font-weight:800; color:#101828; line-height:1.1; }
.sr-stat-lbl { font-size:11px; font-weight:700; color:#667085; text-transform:uppercase; letter-spacing:.4px; margin-top:4px; }
.sr-stat.green { background:#D1FAE5; }
.sr-stat.green .sr-stat-num { color:#065F46; }
.sr-stat.blue  { background:#DBEAFE; }
.sr-stat.blue  .sr-stat-num { color:#1E40AF; }
.sr-stat.amber { background:#FFF3CD; }
.sr-stat.amber .sr-stat-num { color:#856404; }
.sr-stat.red   { background:#FEE2E2; }
.sr-stat.red   .sr-stat-num { color:#991B1B; }

.sr-section-title { font-size:13px; font-weight:800; color:#344054; padding:14px 18px 6px; text-transform:uppercase; letter-spacing:.5px; border-top:1px solid #f0f0f0; margin-top:4px; }
</style>

<div class="sr-wrap">

  <?php
  $view_titles = [
    'daily_sales_summary' => ['fas fa-chart-line',    'Daily Sales Summary'],
    'jo_tracker_report'   => ['fas fa-tasks',          'Job Order Tracker Report'],
    'job_order_report'    => ['fas fa-wrench',          'Job Order Report'],
    'deliveries_report'   => ['fas fa-box',             'Deliveries Report'],
    'customer_report'     => ['fas fa-users',           'Customer Report'],
    'transaction_report'  => ['fas fa-receipt',         'Transaction Report'],
    'personal_activity'   => ['fas fa-user-check',      'Personal Activity Report'],
  ];
  [$vico, $vtitle] = $view_titles[$view] ?? ['fas fa-chart-bar','Staff Reports'];
  ?>

﻿
  <!-- ===== A. DAILY SALES SUMMARY ===== -->
  <?php if ($view === "daily_sales_summary"):
    $grand_total = $daily_sales_total_merch + $daily_sales_total_jo;
    $all_rows = [];
    foreach ($daily_sales_merch as $r) {
        $all_rows[] = ["type"=>"Merchandise","ref"=>$r["txn_ref"],"customer"=>$r["customer_name"],"detail"=>$r["items_summary"],"vehicle"=>"—","payment"=>$r["payment_method"],"amount"=>$r["total_amount"],"status"=>$r["status"],"date"=>$r["txn_date"]];
    }
    foreach ($daily_sales_jo as $r) {
        $all_rows[] = ["type"=>"Job Order","ref"=>$r["txn_ref"],"customer"=>$r["customer_name"],"detail"=>$r["service_type"],"vehicle"=>$r["vehicle_plate"],"payment"=>$r["payment_method"],"amount"=>$r["total_amount"],"status"=>$r["status"],"date"=>$r["txn_date"]];
    }
    usort($all_rows, fn($a,$b) => strtotime($b["date"]) - strtotime($a["date"]));
    $export = $_GET["export"] ?? "";

    // ── EXCEL EXPORT ─────────────────────────────────────────────────────────
    if ($export === "excel") {
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"daily_sales_" . date("Y-m-d") . ".xls\"");
        header("Pragma: no-cache"); header("Expires: 0");
        $gt = array_sum(array_column($all_rows,"amount"));
        $merch_total = array_sum(array_column($daily_sales_merch,"total_amount"));
        $jo_total    = array_sum(array_column($daily_sales_jo,"total_amount"));
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  body{font-family:Arial,sans-serif;font-size:11px;}
  .hdr{background:#002F6C;color:#fff;font-size:14px;font-weight:bold;padding:10px 14px;}
  .meta{background:#f0f4ff;font-size:10px;padding:6px 14px;color:#334155;}
  .summary{background:#e8f0fe;font-size:11px;padding:6px 14px;}
  table{border-collapse:collapse;width:100%;margin-top:10px;}
  th{background:#002F6C;color:#fff;padding:7px 10px;font-size:10px;text-align:left;border:1px solid #001a4d;}
  td{padding:6px 10px;font-size:10px;border:1px solid #e2e8f0;}
  tr:nth-child(even) td{background:#f8fafc;}
  .type-jo{background:#FFF3CD;color:#856404;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:bold;}
  .type-merch{background:#DBEAFE;color:#1E40AF;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:bold;}
  .grand{background:#002F6C;color:#fff;font-weight:bold;}
  .grand td{background:#002F6C;color:#fff;border-color:#001a4d;}
</style></head><body>';
        echo '<div class="hdr">PETRON STATION MANAGEMENT SYSTEM &mdash; Daily Sales Summary</div>';
        echo '<div class="meta">Station: ' . htmlspecialchars($station_name) . ' &nbsp;|&nbsp; Staff: ' . htmlspecialchars($me["name"] ?? "") . ' &nbsp;|&nbsp; Period: ' . htmlspecialchars($date_from) . ' to ' . htmlspecialchars($date_to) . ' &nbsp;|&nbsp; Generated: ' . date("M j, Y h:i A") . '</div>';
        echo '<div class="summary">Total Records: <b>' . count($all_rows) . '</b> &nbsp;|&nbsp; Merchandise: <b>&#8369;' . number_format($merch_total,2) . '</b> (' . count($daily_sales_merch) . ' txns) &nbsp;|&nbsp; Job Orders: <b>&#8369;' . number_format($jo_total,2) . '</b> (' . count($daily_sales_jo) . ' JOs) &nbsp;|&nbsp; Grand Total: <b>&#8369;' . number_format($gt,2) . '</b></div>';
        echo '<table><thead><tr><th>#</th><th>Type</th><th>Reference / ID</th><th>Customer</th><th>Detail / Service</th><th>Payment Method</th><th>Amount (&#8369;)</th><th>Status</th><th>Date &amp; Time</th></tr></thead><tbody>';
        $n=1;
        foreach ($all_rows as $r) {
            $type_label = $r["type"] === "Job Order" ? '<span class="type-jo">JO</span>' : '<span class="type-merch">Merch</span>';
            echo '<tr><td>' . $n++ . '</td><td>' . $type_label . '</td><td>' . htmlspecialchars($r["ref"]) . '</td><td>' . htmlspecialchars($r["customer"]) . '</td><td>' . htmlspecialchars($r["detail"]) . '</td><td>' . htmlspecialchars($r["payment"]) . '</td><td style="text-align:right;font-weight:bold;">' . number_format((float)$r["amount"],2) . '</td><td>' . htmlspecialchars($r["status"]) . '</td><td>' . date("M j, Y h:i A", strtotime($r["date"])) . '</td></tr>';
        }
        echo '<tr class="grand"><td colspan="6" style="text-align:right;">GRAND TOTAL</td><td style="text-align:right;">&#8369;' . number_format($gt,2) . '</td><td colspan="2"></td></tr>';
        echo '</tbody></table></body></html>';
        exit;
    }

    // ── PDF / PRINT EXPORT ────────────────────────────────────────────────────
    if ($export === "pdf") {
        header("Content-Type: text/html; charset=utf-8");
        $gt = array_sum(array_column($all_rows,"amount"));
        $merch_total = array_sum(array_column($daily_sales_merch,"total_amount"));
        $jo_total    = array_sum(array_column($daily_sales_jo,"total_amount"));
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Daily Sales Summary</title>
<style>
  @page{margin:15mm;}
  body{font-family:Arial,sans-serif;font-size:10px;margin:0;color:#1e293b;}
  .hdr{background:#002F6C;color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;}
  .hdr-title{font-size:15px;font-weight:bold;}
  .hdr-sub{font-size:10px;opacity:.85;}
  .meta{background:#f0f4ff;padding:8px 16px;font-size:10px;color:#334155;border-bottom:2px solid #002F6C;}
  .summary-bar{display:flex;gap:20px;padding:8px 16px;background:#fff;border-bottom:1px solid #e2e8f0;font-size:10px;}
  .summary-bar span{color:#64748b;} .summary-bar b{color:#002F6C;}
  table{width:100%;border-collapse:collapse;margin-top:10px;}
  th{background:#002F6C;color:#fff;padding:6px 8px;font-size:9px;text-align:left;}
  td{padding:5px 8px;font-size:9px;border-bottom:1px solid #f1f5f9;}
  tr:nth-child(even) td{background:#f8fafc;}
  .badge-jo{background:#FFF3CD;color:#856404;padding:1px 5px;border-radius:3px;font-size:8px;font-weight:bold;}
  .badge-merch{background:#DBEAFE;color:#1E40AF;padding:1px 5px;border-radius:3px;font-size:8px;font-weight:bold;}
  .grand td{background:#002F6C;color:#fff;font-weight:bold;padding:7px 8px;}
  .footer{margin-top:16px;font-size:9px;color:#94a3b8;text-align:center;border-top:1px solid #e2e8f0;padding-top:8px;}
  @media print{.no-print{display:none;} body{margin:0;}}
</style></head><body>';
        echo '<div class="hdr"><div><div class="hdr-title">&#9981; PETRON STATION MANAGEMENT SYSTEM</div><div class="hdr-sub">Daily Sales Summary Report</div></div><div style="text-align:right;font-size:10px;opacity:.85;">Generated: ' . date("M j, Y h:i A") . '</div></div>';
        echo '<div class="meta"><b>Station:</b> ' . htmlspecialchars($station_name) . ' &nbsp;&nbsp; <b>Staff:</b> ' . htmlspecialchars($me["name"] ?? "") . ' &nbsp;&nbsp; <b>Period:</b> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . '</div>';
        echo '<div class="summary-bar"><span>Total Records: <b>' . count($all_rows) . '</b></span><span>Merchandise: <b>&#8369;' . number_format($merch_total,2) . '</b> (' . count($daily_sales_merch) . ')</span><span>Job Orders: <b>&#8369;' . number_format($jo_total,2) . '</b> (' . count($daily_sales_jo) . ')</span><span>Grand Total: <b>&#8369;' . number_format($gt,2) . '</b></span></div>';
        echo '<table><thead><tr><th>#</th><th>Type</th><th>Reference / ID</th><th>Customer</th><th>Detail / Service</th><th>Payment</th><th>Amount (&#8369;)</th><th>Status</th><th>Date &amp; Time</th></tr></thead><tbody>';
        $n=1;
        foreach ($all_rows as $r) {
            $badge = $r["type"] === "Job Order" ? '<span class="badge-jo">JO</span>' : '<span class="badge-merch">Merch</span>';
            echo '<tr><td>' . $n++ . '</td><td>' . $badge . '</td><td style="font-family:monospace;">' . htmlspecialchars($r["ref"]) . '</td><td>' . htmlspecialchars($r["customer"]) . '</td><td>' . htmlspecialchars($r["detail"]) . '</td><td>' . htmlspecialchars($r["payment"]) . '</td><td style="text-align:right;font-weight:bold;color:' . ($r["type"]==="Job Order"?"#b45309":"#065F46") . ';">&#8369;' . number_format((float)$r["amount"],2) . '</td><td>' . htmlspecialchars($r["status"]) . '</td><td>' . date("M j, Y h:i A", strtotime($r["date"])) . '</td></tr>';
        }
        echo '<tr class="grand"><td colspan="6" style="text-align:right;">GRAND TOTAL</td><td style="text-align:right;">&#8369;' . number_format($gt,2) . '</td><td colspan="2"></td></tr>';
        echo '</tbody></table>';
        echo '<div class="footer">Petron Station Management System &mdash; ' . htmlspecialchars($station_name) . ' &mdash; Printed: ' . date("M j, Y h:i A") . '</div>';
        echo '<div class="no-print" style="text-align:center;margin-top:20px;"><button onclick="window.print()" style="padding:10px 24px;background:#002F6C;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">&#128438; Print / Save as PDF</button></div>';
        echo '</body></html>';
        exit;
    }
  ?>
  <!-- Page header -->
  <div class="page-head">
    <div>
      <h1 class="h1"><i class="fas fa-chart-line"></i> Daily Sales Summary</h1>
      <div class="sub"><?php echo htmlspecialchars($station_name); ?> &mdash; <?php echo htmlspecialchars($me["name"] ?? ""); ?></div>
    </div>
    <div style="display:inline-flex;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
      <a href="?view=daily_sales_summary&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&export=excel"
         style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#1d6f42;color:#fff;font-size:12px;font-weight:700;text-decoration:none;border-right:1px solid rgba(255,255,255,.3);">
        <i class="fas fa-file-excel"></i> Excel
      </a>
      <a href="?view=daily_sales_summary&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&export=pdf"
         target="_blank"
         style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#c0392b;color:#fff;font-size:12px;font-weight:700;text-decoration:none;">
        <i class="fas fa-file-pdf"></i> PDF
      </a>
    </div>
  </div>
  <!-- Filter -->
  <form method="get" class="sr-filter" style="margin-bottom:18px;background:#fff;border:1px solid #EAEAEA;border-radius:12px;padding:14px 18px;">
    <input type="hidden" name="view" value="daily_sales_summary">
    <label>From</label><input type="date" name="date_from" value="<?php echo $date_from; ?>">
    <label>To</label><input type="date" name="date_to" value="<?php echo $date_to; ?>">
    <button type="submit" class="sr-filter-btn"><i class="fas fa-filter"></i> Filter</button>
    <a href="?view=daily_sales_summary" style="font-size:12px;color:#667085;text-decoration:none;">Reset</a>
  </form>
  <!-- Unified table -->
  <div class="sr-card">
    <div class="sr-card-head">
      <div class="sr-card-title"><i class="fas fa-list"></i> All Transactions &amp; Job Orders</div>
      <span class="sr-count"><?php echo count($all_rows); ?> records &nbsp;&middot;&nbsp; &#8369;<?php echo number_format($grand_total,2); ?></span>
    </div>
    <?php if(empty($all_rows)): ?>
    <div class="sr-empty"><i class="fas fa-receipt"></i>No transactions found for this period.</div>
    <?php else: ?>
    <div>
    <table class="sr-table">
      <colgroup>
        <col style="width:3%">  <!-- # -->
        <col style="width:7%">  <!-- Type -->
        <col style="width:14%"> <!-- Ref/ID -->
        <col style="width:13%"> <!-- Customer -->
        <col style="width:22%"> <!-- Detail/Service -->
        <col style="width:11%"> <!-- Payment -->
        <col style="width:10%"> <!-- Amount -->
        <col style="width:10%"> <!-- Status -->
        <col style="width:10%"> <!-- Date/Time -->
      </colgroup>
      <thead><tr>
        <th>#</th><th>Type</th><th>Ref / ID</th><th>Customer</th>
        <th>Detail / Service</th><th>Payment</th>
        <th>Amount</th><th>Status</th><th>Date / Time</th>
      </tr></thead>
      <tbody>
      <?php $n=1; $gt=0; foreach($all_rows as $row): $gt+=(float)$row["amount"]; ?>
      <?php $is_jo = ($row["type"]==="Job Order"); ?>
      <tr>
        <td style="color:#9ca3af;font-size:10px;"><?php echo $n++; ?></td>
        <td>
          <?php if($is_jo): ?>
          <span style="background:#FFF3CD;color:#856404;padding:2px 6px;border-radius:6px;font-size:10px;font-weight:700;">JO</span>
          <?php else: ?>
          <span style="background:#DBEAFE;color:#1E40AF;padding:2px 6px;border-radius:6px;font-size:10px;font-weight:700;">Merch</span>
          <?php endif; ?>
        </td>
        <td style="font-size:10px;color:#00264D;font-family:monospace;word-break:break-all;"><?php echo htmlspecialchars($row["ref"]); ?></td>
        <td><?php echo htmlspecialchars($row["customer"]); ?></td>
        <td style="color:#475569;"><?php echo htmlspecialchars($row["detail"]); ?></td>
        <td style="font-size:10px;"><?php echo htmlspecialchars($row["payment"]); ?></td>
        <td style="font-weight:700;color:<?php echo $is_jo?"#b45309":"#065F46"; ?>;">&#8369;<?php echo number_format((float)$row["amount"],2); ?></td>
        <td><?php echo sr_badge($row["status"]); ?></td>
        <td style="font-size:10px;color:#667085;"><?php echo date("M j, Y",strtotime($row["date"])); ?><br><?php echo date("h:i A",strtotime($row["date"])); ?></td>
      </tr>
      <?php endforeach; ?>
      <tr style="background:#00264D;color:#fff;">
        <td colspan="6" style="text-align:right;font-weight:700;padding:10px 14px;">GRAND TOTAL</td>
        <td style="font-weight:800;font-size:13px;padding:10px 14px;">&#8369;<?php echo number_format($gt,2); ?></td>
        <td colspan="2"></td>
      </tr>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; /* daily_sales_summary */ ?>

  <!-- ===== B. JO TRACKER REPORT ===== -->
  <?php if ($view === "jo_tracker_report"):
    $export_jot = $_GET["export"] ?? "";

    // ── EXCEL EXPORT ─────────────────────────────────────────────────────────
    if ($export_jot === "excel") {
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"jo_tracker_" . date("Y-m-d") . ".xls\"");
        header("Pragma: no-cache"); header("Expires: 0");
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  body{font-family:Arial,sans-serif;font-size:11px;}
  .hdr{background:#002F6C;color:#fff;font-size:14px;font-weight:bold;padding:10px 14px;}
  .meta{background:#f0f4ff;font-size:10px;padding:6px 14px;color:#334155;}
  table{border-collapse:collapse;width:100%;margin-top:10px;}
  th{background:#002F6C;color:#fff;padding:7px 10px;font-size:10px;text-align:left;border:1px solid #001a4d;}
  td{padding:6px 10px;font-size:10px;border:1px solid #e2e8f0;}
  tr:nth-child(even) td{background:#f8fafc;}
</style></head><body>';
        echo '<div class="hdr">PETRON STATION MANAGEMENT SYSTEM &mdash; Job Order Tracker Report</div>';
        echo '<div class="meta">Station: ' . htmlspecialchars($station_name) . ' &nbsp;|&nbsp; Staff: ' . htmlspecialchars($me["name"] ?? "") . ' &nbsp;|&nbsp; Period: ' . htmlspecialchars($date_from) . ' to ' . htmlspecialchars($date_to) . ' &nbsp;|&nbsp; Total Records: ' . count($jo_tracker_report) . ' &nbsp;|&nbsp; Generated: ' . date("M j, Y h:i A") . '</div>';
        echo '<table><thead><tr><th>#</th><th>JO ID</th><th>Customer</th><th>Vehicle</th><th>Service Type</th><th>Items / Parts</th><th>Mechanic</th><th>Encoded By</th><th>Workflow Status</th><th>Payment Status</th><th>Amount (&#8369;)</th><th>Remarks</th><th>Date &amp; Time</th></tr></thead><tbody>';
        $n = 1;
        foreach ($jo_tracker_report as $r) {
            $ws = strtolower(trim($r["workflow_status"]));
            $vs = strtolower(trim($r["validation_status"]));
            if ($ws==="completed")                              $wl = "Completed";
            elseif ($ws==="in progress"||$ws==="in-progress")  $wl = "In Progress";
            elseif ($ws==="rejected"||$ws==="cancelled")       $wl = "Rejected";
            elseif ($vs==="approved")                          $wl = "Approved";
            else                                               $wl = "Pending";
            $pd = "—"; $raw = $r["required_parts_raw"] ?? "";
            if (!empty($raw)) {
                $dec = json_decode($raw, true);
                if (is_array($dec)) $pd = implode(", ", array_filter(array_map(fn($p) => is_array($p) ? ($p["name"] ?? $p["part_name"] ?? "") : $p, $dec)));
                else $pd = $raw;
                if (strlen($pd) > 80) $pd = substr($pd, 0, 77) . "…";
            }
            echo '<tr><td>' . $n++ . '</td><td>' . htmlspecialchars($r["job_order_id"]) . '</td><td>' . htmlspecialchars($r["customer_name"]) . '</td><td>' . htmlspecialchars($r["vehicle_plate"]) . '</td><td>' . htmlspecialchars($r["service_type"]) . '</td><td>' . htmlspecialchars($pd) . '</td><td>' . htmlspecialchars($r["mechanic_name"]) . '</td><td>' . htmlspecialchars($r["encoded_by"]) . '</td><td>' . $wl . '</td><td>' . htmlspecialchars($r["payment_status"]) . '</td><td style="text-align:right;font-weight:bold;">&#8369;' . number_format((float)$r["total_cost"], 2) . '</td><td>' . htmlspecialchars($r["remarks"]) . '</td><td>' . date("M j, Y h:i A", strtotime($r["created_at"])) . '</td></tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    // ── PDF / PRINT EXPORT ────────────────────────────────────────────────────
    if ($export_jot === "pdf") {
        header("Content-Type: text/html; charset=utf-8");
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>JO Tracker Report</title>
<style>
  @page{margin:12mm;}
  body{font-family:Arial,sans-serif;font-size:9px;margin:0;color:#1e293b;}
  .hdr{background:#002F6C;color:#fff;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;}
  .hdr-title{font-size:13px;font-weight:bold;}
  .hdr-sub{font-size:9px;opacity:.85;}
  .meta{background:#f0f4ff;padding:6px 14px;font-size:9px;color:#334155;border-bottom:2px solid #002F6C;}
  table{width:100%;border-collapse:collapse;margin-top:8px;}
  th{background:#002F6C;color:#fff;padding:5px 7px;font-size:8px;text-align:left;}
  td{padding:4px 7px;font-size:8px;border-bottom:1px solid #f1f5f9;}
  tr:nth-child(even) td{background:#f8fafc;}
  .footer{margin-top:12px;font-size:8px;color:#94a3b8;text-align:center;border-top:1px solid #e2e8f0;padding-top:6px;}
  @media print{.no-print{display:none;} body{margin:0;}}
</style></head><body>';
        echo '<div class="hdr"><div><div class="hdr-title">&#9981; PETRON STATION MANAGEMENT SYSTEM</div><div class="hdr-sub">Job Order Tracker Report</div></div><div style="text-align:right;font-size:9px;opacity:.85;">Generated: ' . date("M j, Y h:i A") . '</div></div>';
        echo '<div class="meta"><b>Station:</b> ' . htmlspecialchars($station_name) . ' &nbsp;&nbsp; <b>Staff:</b> ' . htmlspecialchars($me["name"] ?? "") . ' &nbsp;&nbsp; <b>Period:</b> ' . htmlspecialchars($date_from) . ' &mdash; ' . htmlspecialchars($date_to) . ' &nbsp;&nbsp; <b>Total Records:</b> ' . count($jo_tracker_report) . '</div>';
        echo '<table><thead><tr><th>#</th><th>JO ID</th><th>Customer</th><th>Vehicle</th><th>Service</th><th>Items/Parts</th><th>Mechanic</th><th>Encoded By</th><th>Workflow</th><th>Payment</th><th>Amount (&#8369;)</th><th>Remarks</th><th>Date</th></tr></thead><tbody>';
        $n = 1;
        foreach ($jo_tracker_report as $r) {
            $ws = strtolower(trim($r["workflow_status"]));
            $vs = strtolower(trim($r["validation_status"]));
            if ($ws==="completed")                              $wl = "Completed";
            elseif ($ws==="in progress"||$ws==="in-progress")  $wl = "In Progress";
            elseif ($ws==="rejected"||$ws==="cancelled")       $wl = "Rejected";
            elseif ($vs==="approved")                          $wl = "Approved";
            else                                               $wl = "Pending";
            $pd = "—"; $raw = $r["required_parts_raw"] ?? "";
            if (!empty($raw)) {
                $dec = json_decode($raw, true);
                if (is_array($dec)) $pd = implode(", ", array_filter(array_map(fn($p) => is_array($p) ? ($p["name"] ?? $p["part_name"] ?? "") : $p, $dec)));
                else $pd = $raw;
                if (strlen($pd) > 60) $pd = substr($pd, 0, 57) . "…";
            }
            echo '<tr><td>' . $n++ . '</td><td style="font-family:monospace;">' . htmlspecialchars($r["job_order_id"]) . '</td><td>' . htmlspecialchars($r["customer_name"]) . '</td><td>' . htmlspecialchars($r["vehicle_plate"]) . '</td><td>' . htmlspecialchars($r["service_type"]) . '</td><td>' . htmlspecialchars($pd) . '</td><td>' . htmlspecialchars($r["mechanic_name"]) . '</td><td>' . htmlspecialchars($r["encoded_by"]) . '</td><td>' . $wl . '</td><td>' . htmlspecialchars($r["payment_status"]) . '</td><td style="text-align:right;font-weight:bold;">&#8369;' . number_format((float)$r["total_cost"], 2) . '</td><td>' . htmlspecialchars($r["remarks"]) . '</td><td>' . date("M j, Y", strtotime($r["created_at"])) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<div class="footer">Petron Station Management System &mdash; ' . htmlspecialchars($station_name) . ' &mdash; Printed: ' . date("M j, Y h:i A") . '</div>';
        echo '<div class="no-print" style="text-align:center;margin-top:16px;"><button onclick="window.print()" style="padding:10px 24px;background:#002F6C;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">&#128438; Print / Save as PDF</button></div>';
        echo '</body></html>';
        exit;
    }
  ?>
  <!-- JO Tracker HTML -->
  <div class="page-head">
    <div>
      <h1 class="h1"><i class="fas fa-tasks"></i> Job Order Tracker Report</h1>
      <div class="sub"><?php echo htmlspecialchars($station_name); ?> &mdash; <?php echo htmlspecialchars($me["name"] ?? ""); ?></div>
    </div>
    <div style="display:inline-flex;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
      <a href="?view=jo_tracker_report&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&staff_id=<?php echo (int)$filter_staff_id; ?>&export=excel"
         style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#1d6f42;color:#fff;font-size:12px;font-weight:700;text-decoration:none;border-right:1px solid rgba(255,255,255,.3);">
        <i class="fas fa-file-excel"></i> Excel
      </a>
      <a href="?view=jo_tracker_report&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&staff_id=<?php echo (int)$filter_staff_id; ?>&export=pdf"
         target="_blank"
         style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#c0392b;color:#fff;font-size:12px;font-weight:700;text-decoration:none;">
        <i class="fas fa-file-pdf"></i> PDF
      </a>
    </div>
  </div>
  <form method="get" class="sr-filter" style="margin-bottom:18px;background:#fff;border:1px solid #EAEAEA;border-radius:12px;padding:14px 18px;">
    <input type="hidden" name="view" value="jo_tracker_report">
    <label>From</label><input type="date" name="date_from" value="<?php echo $date_from; ?>">
    <label>To</label><input type="date" name="date_to" value="<?php echo $date_to; ?>">
    <?php if($role !== "staff" && !empty($staff_list_for_filter)): ?>
    <label>Staff</label>
    <select name="staff_id" style="padding:6px 10px;border:1px solid #EAEAEA;border-radius:8px;font-size:12px;">
      <option value="0">All Staff</option>
      <?php foreach($staff_list_for_filter as $sl): ?>
      <option value="<?php echo (int)$sl["id"]; ?>" <?php echo (int)($_GET["staff_id"]??0)===(int)$sl["id"]?"selected":""; ?>><?php echo htmlspecialchars($sl["name"]); ?></option>
      <?php endforeach; ?>
    </select>
    <?php else: ?><input type="hidden" name="staff_id" value="<?php echo $user_id; ?>"><?php endif; ?>
    <button type="submit" class="sr-filter-btn"><i class="fas fa-filter"></i> Filter</button>
    <a href="?view=jo_tracker_report" style="font-size:12px;color:#667085;text-decoration:none;">Reset</a>
  </form>
  <div class="sr-card">
    <div class="sr-card-head">
      <div class="sr-card-title"><i class="fas fa-tasks"></i> Job Orders</div>
      <span class="sr-count"><?php echo count($jo_tracker_report); ?> records</span>
    </div>
    <?php if(empty($jo_tracker_report)): ?>
    <div class="sr-empty"><i class="fas fa-clipboard-list"></i>No job orders found for the selected filters.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="sr-table" style="min-width:1100px;table-layout:auto;">
      <thead><tr>
        <th style="min-width:30px;">#</th>
        <th style="min-width:140px;">JO ID</th>
        <th style="min-width:110px;">Customer</th>
        <th style="min-width:130px;">Vehicle / Service</th>
        <th style="min-width:120px;">Items / Parts</th>
        <th style="min-width:100px;">Mechanic</th>
        <th style="min-width:100px;">Encoded By</th>
        <th style="min-width:110px;">Workflow Status</th>
        <th style="min-width:100px;">Payment Status</th>
        <th style="min-width:80px;">Amount</th>
        <th style="min-width:120px;">Remarks</th>
        <th style="min-width:90px;">Date / Time</th>
      </tr></thead>
      <tbody>
      <?php $n=1; foreach($jo_tracker_report as $row):
        $ws=strtolower(trim($row["workflow_status"])); $vs=strtolower(trim($row["validation_status"]));
        if($ws==="completed"){$wf_bg="#D1FAE5";$wf_c="#065F46";$wf_lbl="Completed";}
        elseif($ws==="in progress"||$ws==="in-progress"){$wf_bg="#DBEAFE";$wf_c="#1E40AF";$wf_lbl="In Progress";}
        elseif($ws==="rejected"||$ws==="cancelled"){$wf_bg="#FEE2E2";$wf_c="#991B1B";$wf_lbl="Rejected";}
        elseif($vs==="approved"){$wf_bg="#D1FAE5";$wf_c="#065F46";$wf_lbl="Approved";}
        else{$wf_bg="#FFF3CD";$wf_c="#856404";$wf_lbl="Pending";}
        $ps=strtolower(trim($row["payment_status"]));
        if($ps==="paid"){$pay_bg="#D1FAE5";$pay_c="#065F46";$pay_lbl="Paid";}
        elseif($ps==="partial"){$pay_bg="#FFF3CD";$pay_c="#856404";$pay_lbl="Partial";}
        else{$pay_bg="#FEE2E2";$pay_c="#991B1B";$pay_lbl="Unpaid";}
        $pd="—"; $raw=$row["required_parts_raw"]??"";
        if(!empty($raw)){$dec=json_decode($raw,true);if(is_array($dec)){$pd=implode(", ",array_filter(array_map(fn($p)=>is_array($p)?($p["name"]??$p["part_name"]??""): $p,$dec)));}else{$pd=$raw;}if(strlen($pd)>60)$pd=substr($pd,0,57)."…";}
      ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;"><?php echo $n++; ?></td>
        <td><strong style="color:#00264D;font-family:monospace;font-size:11px;"><?php echo htmlspecialchars($row["job_order_id"]); ?></strong></td>
        <td><?php echo htmlspecialchars($row["customer_name"]); ?></td>
        <td style="font-size:11px;"><strong><?php echo htmlspecialchars($row["vehicle_plate"]); ?></strong><?php if($row["vehicle_type"]!=="—"): ?> <span style="color:#9ca3af;">&middot; <?php echo htmlspecialchars($row["vehicle_type"]); ?></span><?php endif; ?><br><?php echo htmlspecialchars($row["service_type"]); ?></td>
        <td style="font-size:11px;color:#475569;"><?php echo htmlspecialchars($pd); ?></td>
        <td style="font-size:11px;"><?php echo htmlspecialchars($row["mechanic_name"]); ?></td>
        <td style="font-size:11px;color:#667085;"><?php echo htmlspecialchars($row["encoded_by"]); ?></td>
        <td><span style="background:<?php echo $wf_bg; ?>;color:<?php echo $wf_c; ?>;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;"><?php echo $wf_lbl; ?></span></td>
        <td><span style="background:<?php echo $pay_bg; ?>;color:<?php echo $pay_c; ?>;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;"><?php echo $pay_lbl; ?></span></td>
        <td style="font-weight:700;color:#00264D;white-space:nowrap;">&#8369;<?php echo number_format((float)$row["total_cost"],2); ?></td>
        <td style="font-size:11px;color:#667085;"><?php echo $row["remarks"]!=="—"?htmlspecialchars($row["remarks"]):"<span style=\"color:#d1d5db;\">—</span>"; ?></td>
        <td style="font-size:11px;color:#667085;white-space:nowrap;"><?php echo date("M j, Y",strtotime($row["created_at"])); ?><br><span style="color:#9ca3af;"><?php echo date("h:i A",strtotime($row["created_at"])); ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; /* jo_tracker_report */ ?>

  <!-- ===== 1. JOB ORDER REPORT ===== -->
  <?php if($view === 'job_order_report'): ?>
  <div class="sr-card">
    <div class="sr-card-head">
      <div class="sr-card-title"><i class="fas fa-wrench"></i> Job Orders I Encoded</div>
      <form method="get" class="sr-filter">
        <input type="hidden" name="view" value="job_order_report">
        <label>From</label><input type="date" name="date_from" value="<?php echo $date_from; ?>">
        <label>To</label><input type="date" name="date_to" value="<?php echo $date_to; ?>">
        <button type="submit" class="sr-filter-btn"><i class="fas fa-filter"></i> Filter</button>
        <a href="?view=job_order_report" style="font-size:12px;color:#667085;text-decoration:none">Reset</a>
      </form>
      <span class="sr-count"><?php echo count($job_orders); ?> records</span>
    </div>
    <?php if(empty($job_orders)): ?>
      <div class="sr-empty"><i class="fas fa-clipboard-list"></i>No job orders found for the selected period.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="sr-table">
      <thead><tr><th>JO #</th><th>Customer</th><th>Vehicle</th><th>Service Type</th><th>Payment</th><th>Est. Cost</th><th>Actual Cost</th><th>Status</th><th>Validation</th><th>Validated By</th><th>Date Encoded</th></tr></thead>
      <tbody>
        <?php foreach($job_orders as $jo): ?>
        <tr>
          <td><strong style="color:#00264D"><?php echo htmlspecialchars($jo['job_order_id']); ?></strong></td>
          <td><?php echo htmlspecialchars($jo['customer_name']); ?></td>
          <td><div style="font-weight:600"><?php echo htmlspecialchars($jo['vehicle_plate']); ?></div><div style="font-size:11px;color:#667085"><?php echo htmlspecialchars($jo['vehicle_type']); ?></div></td>
          <td><?php echo htmlspecialchars($jo['service_type']); ?></td>
          <td><span style="font-size:11px;background:#f0f4ff;color:#00264D;padding:2px 8px;border-radius:6px;font-weight:600"><?php echo htmlspecialchars($jo['payment_method']); ?></span></td>
          <td style="text-align:right">&#8369;<?php echo number_format((float)$jo['estimated_cost'],2); ?></td>
          <td style="text-align:right;font-weight:700;color:#065F46"><?php echo $jo['actual_cost'] ? '&#8369;'.number_format((float)$jo['actual_cost'],2) : '<span style="color:#9ca3af">—</span>'; ?></td>
          <td><?php echo sr_badge($jo['status']); ?></td>
          <td><?php echo sr_badge($jo['validation_status']); ?></td>
          <td style="font-size:12px;color:#667085"><?php echo htmlspecialchars($jo['validated_by_name']); ?></td>
          <td style="font-size:12px;color:#667085;white-space:nowrap"><?php echo date('M j, Y g:ia', strtotime($jo['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>


  <!-- ===== 2. DELIVERIES REPORT ===== -->
  <?php elseif($view === 'deliveries_report'): ?>
  <div class="sr-card">
    <div class="sr-card-head">
      <div class="sr-card-title"><i class="fas fa-box"></i> Deliveries I Encoded</div>
      <form method="get" class="sr-filter">
        <input type="hidden" name="view" value="deliveries_report">
        <label>From</label><input type="date" name="date_from" value="<?php echo $date_from; ?>">
        <label>To</label><input type="date" name="date_to" value="<?php echo $date_to; ?>">
        <button type="submit" class="sr-filter-btn"><i class="fas fa-filter"></i> Filter</button>
        <a href="?view=deliveries_report" style="font-size:12px;color:#667085;text-decoration:none">Reset</a>
      </form>
      <span class="sr-count"><?php echo count($deliveries); ?> records</span>
    </div>
    <?php if(empty($deliveries)): ?>
      <div class="sr-empty"><i class="fas fa-truck"></i>No deliveries found for the selected period.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="sr-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Delivery Date</th>
          <th>Type</th>
          <th>Supplier</th>
          <th>Product</th>
          <th>Qty / Unit</th>
          <th>DR Number</th>
          <th>Status</th>
          <th>Admin Action</th>
          <th>Remarks</th>
          <th>Date Encoded</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($deliveries as $d): ?>
        <tr>
          <td><strong style="color:#00264D">#<?php echo $d['id']; ?></strong></td>
          <td style="white-space:nowrap"><?php echo date('M j, Y', strtotime($d['delivery_date'])); ?></td>
          <td>
            <?php $dtype = ucfirst($d['delivery_type'] ?? '—');
            $dtc = $d['delivery_type']==='fuel' ? ['#FFF3CD','#856404'] : ['#DBEAFE','#1E40AF'];
            ?>
            <span style="background:<?php echo $dtc[0]; ?>;color:<?php echo $dtc[1]; ?>;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700"><?php echo $dtype; ?></span>
          </td>
          <td><?php echo htmlspecialchars($d['supplier'] ?: '—'); ?></td>
          <td><?php echo htmlspecialchars($d['product'] ?: '—'); ?></td>
          <td style="text-align:right;font-weight:700"><?php echo number_format((float)$d['quantity'],2); ?> <?php echo htmlspecialchars($d['unit']); ?></td>
          <td style="font-size:12px;color:#667085"><?php echo htmlspecialchars($d['dr_number'] ?: '—'); ?></td>
          <td><?php echo sr_badge($d['status'] ?? '—'); ?></td>
          <td style="font-size:12px;color:#667085"><?php echo htmlspecialchars($d['admin_name'] ?: '—'); ?></td>
          <td style="font-size:12px;color:#667085;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($d['delivery_notes'] ?: '—'); ?></td>
          <td style="font-size:12px;color:#667085;white-space:nowrap"><?php echo date('M j, Y g:ia', strtotime($d['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ===== 3. CUSTOMER REPORT ===== -->
  <?php elseif($view === 'customer_report'): ?>
  <div class="sr-card">
    <div class="sr-card-head">
      <div class="sr-card-title"><i class="fas fa-users"></i> Customer Linkage — My Job Orders</div>
      <form method="get" class="sr-filter">
        <input type="hidden" name="view" value="customer_report">
        <label>From</label><input type="date" name="date_from" value="<?php echo $date_from; ?>">
        <label>To</label><input type="date" name="date_to" value="<?php echo $date_to; ?>">
        <button type="submit" class="sr-filter-btn"><i class="fas fa-filter"></i> Filter</button>
        <a href="?view=customer_report" style="font-size:12px;color:#667085;text-decoration:none">Reset</a>
      </form>
      <span class="sr-count"><?php echo count($customer_records); ?> records</span>
    </div>
    <?php if(empty($customer_records)): ?>
      <div class="sr-empty"><i class="fas fa-user-slash"></i>No customer records found for the selected period.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="sr-table">
      <thead>
        <tr>
          <th>JO #</th>
          <th>Customer Name</th>
          <th>Account Type</th>
          <th>Vehicle</th>
          <th>Payment Method</th>
          <th>Est. Cost</th>
          <th>Actual Cost</th>
          <th>Outstanding Balance</th>
          <th>Credit Limit</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($customer_records as $cr): ?>
        <tr>
          <td><strong style="color:#00264D"><?php echo htmlspecialchars($cr['job_order_id']); ?></strong></td>
          <td>
            <?php echo htmlspecialchars($cr['customer_name']); ?>
            <?php if($cr['customer_id']): ?><span style="font-size:10px;background:#D1FAE5;color:#065F46;padding:1px 6px;border-radius:4px;margin-left:4px">Linked</span><?php endif; ?>
          </td>
          <td style="font-size:12px"><?php echo htmlspecialchars($cr['account_type'] ?: '—'); ?></td>
          <td style="font-size:12px"><?php echo htmlspecialchars($cr['vehicle_plate']); ?></td>
          <td><span style="font-size:11px;background:#f0f4ff;color:#00264D;padding:2px 8px;border-radius:6px;font-weight:600"><?php echo htmlspecialchars($cr['payment_method']); ?></span></td>
          <td style="text-align:right">₱<?php echo number_format((float)$cr['estimated_cost'],2); ?></td>
          <td style="text-align:right;font-weight:700;color:#065F46"><?php echo $cr['actual_cost'] ? '₱'.number_format((float)$cr['actual_cost'],2) : '<span style="color:#9ca3af">—</span>'; ?></td>
          <td style="text-align:right;font-weight:700;color:<?php echo (float)($cr['outstanding_balance']??0)>0?'#991B1B':'#065F46'; ?>">
            <?php echo $cr['outstanding_balance'] !== null ? '₱'.number_format((float)$cr['outstanding_balance'],2) : '—'; ?>
          </td>
          <td style="text-align:right;font-size:12px;color:#667085"><?php echo $cr['credit_limit'] !== null ? '₱'.number_format((float)$cr['credit_limit'],2) : '—'; ?></td>
          <td><?php echo sr_badge($cr['status']); ?></td>
          <td style="font-size:12px;color:#667085;white-space:nowrap"><?php echo date('M j, Y', strtotime($cr['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ===== 4. TRANSACTION REPORT ===== -->
  <?php elseif($view === 'transaction_report'): ?>
  <div class="sr-card">
    <div class="sr-card-head">
      <div class="sr-card-title"><i class="fas fa-receipt"></i> Transactions I Processed</div>
      <form method="get" class="sr-filter">
        <input type="hidden" name="view" value="transaction_report">
        <label>From</label><input type="date" name="date_from" value="<?php echo $date_from; ?>">
        <label>To</label><input type="date" name="date_to" value="<?php echo $date_to; ?>">
        <button type="submit" class="sr-filter-btn"><i class="fas fa-filter"></i> Filter</button>
        <a href="?view=transaction_report" style="font-size:12px;color:#667085;text-decoration:none">Reset</a>
      </form>
      <span class="sr-count"><?php echo count($transactions); ?> records</span>
    </div>
    <?php if(empty($transactions)): ?>
      <div class="sr-empty"><i class="fas fa-file-invoice"></i>No transactions found for the selected period.</div>
    <?php else:
      $txn_grand_total = array_sum(array_column($transactions,'total'));
    ?>
    <div style="padding:14px 18px;background:#f5f6f8;border-bottom:1px solid #EAEAEA;display:flex;gap:20px;flex-wrap:wrap">
      <div><span style="font-size:12px;color:#667085;font-weight:700">TOTAL TRANSACTIONS</span><br><strong style="font-size:20px;color:#101828"><?php echo count($transactions); ?></strong></div>
      <div><span style="font-size:12px;color:#667085;font-weight:700">GRAND TOTAL</span><br><strong style="font-size:20px;color:#065F46">₱<?php echo number_format($txn_grand_total,2); ?></strong></div>
    </div>
    <div style="overflow-x:auto">
    <table class="sr-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Type</th>
          <th>Date & Time</th>
          <th>Payment Method</th>
          <th>Shift</th>
          <th>Description</th>
          <th>Status</th>
          <th style="text-align:right">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($transactions as $t): ?>
        <tr>
          <td style="font-size:12px;color:#667085"><?php echo $t['id']; ?></td>
          <td>
            <?php
            $type_colors = ['Merchandise'=>['#f0f4ff','#00264D'],'Sale'=>['#D1FAE5','#065F46']];
            [$tbg,$tfg] = $type_colors[$t['txn_type']] ?? ['#f5f6f8','#344054'];
            ?>
            <span style="background:<?php echo $tbg; ?>;color:<?php echo $tfg; ?>;padding:2px 10px;border-radius:6px;font-size:11px;font-weight:700"><?php echo htmlspecialchars($t['txn_type']); ?></span>
          </td>
          <td style="font-size:12px;white-space:nowrap"><?php echo date('M j, Y g:ia', strtotime($t['txn_date'])); ?></td>
          <td style="font-size:12px"><?php echo htmlspecialchars($t['payment_method'] ?: '—'); ?></td>
          <td style="font-size:12px;color:#667085"><?php echo htmlspecialchars($t['shift_ref'] ?: '—'); ?></td>
          <td style="font-size:12px;color:#667085;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($t['description'] ?: '—'); ?></td>
          <td><?php echo sr_badge($t['status']); ?></td>
          <td style="text-align:right;font-weight:700;color:#065F46">₱<?php echo number_format((float)$t['total'],2); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ===== 5. PERSONAL ACTIVITY REPORT ===== -->
  <?php elseif($view === 'personal_activity'): ?>

  <?php
  $jo  = $activity['job_orders']  ?? [];
  $del = $activity['deliveries']  ?? [];
  $txn = $activity['transactions'] ?? [];
  $cus = $activity['customers']   ?? [];
  ?>

  <!-- Summary Stats -->
  <div class="sr-card" style="margin-bottom:16px">
    <div class="sr-card-head">
      <div class="sr-card-title"><i class="fas fa-user-check"></i> My Workload Summary</div>
      <form method="get" class="sr-filter">
        <input type="hidden" name="view" value="personal_activity">
        <label>From</label><input type="date" name="date_from" value="<?php echo $date_from; ?>">
        <label>To</label><input type="date" name="date_to" value="<?php echo $date_to; ?>">
        <button type="submit" class="sr-filter-btn"><i class="fas fa-filter"></i> Filter</button>
        <a href="?view=personal_activity" style="font-size:12px;color:#667085;text-decoration:none">Reset</a>
      </form>
      <span style="font-size:12px;color:#667085"><?php echo date('M j', strtotime($date_from)); ?> – <?php echo date('M j, Y', strtotime($date_to)); ?></span>
    </div>
    <div class="sr-stat-grid">
      <div class="sr-stat blue">
        <div class="sr-stat-num"><?php echo (int)($jo['total']??0); ?></div>
        <div class="sr-stat-lbl">Job Orders</div>
      </div>
      <div class="sr-stat green">
        <div class="sr-stat-num"><?php echo (int)($jo['completed']??0); ?></div>
        <div class="sr-stat-lbl">JO Completed</div>
      </div>
      <div class="sr-stat amber">
        <div class="sr-stat-num"><?php echo (int)($jo['pending']??0) + (int)($jo['in_progress']??0); ?></div>
        <div class="sr-stat-lbl">JO Pending/Active</div>
      </div>
      <div class="sr-stat blue">
        <div class="sr-stat-num"><?php echo (int)($del['total']??0); ?></div>
        <div class="sr-stat-lbl">Deliveries</div>
      </div>
      <div class="sr-stat green">
        <div class="sr-stat-num"><?php echo (int)($txn['count']??0); ?></div>
        <div class="sr-stat-lbl">Transactions</div>
      </div>
      <div class="sr-stat green">
        <div class="sr-stat-num" style="font-size:18px">₱<?php echo number_format((float)($txn['total']??0),0); ?></div>
        <div class="sr-stat-lbl">Txn Value</div>
      </div>
    </div>
  </div>

  <!-- Job Orders Breakdown -->
  <div class="sr-card" style="margin-bottom:16px">
    <div class="sr-card-head">
      <div class="sr-card-title"><i class="fas fa-wrench"></i> Job Orders Breakdown</div>
    </div>
    <div class="sr-stat-grid">
      <div class="sr-stat green">
        <div class="sr-stat-num"><?php echo (int)($jo['completed']??0); ?></div>
        <div class="sr-stat-lbl">Completed</div>
      </div>
      <div class="sr-stat blue">
        <div class="sr-stat-num"><?php echo (int)($jo['in_progress']??0); ?></div>
        <div class="sr-stat-lbl">In Progress</div>
      </div>
      <div class="sr-stat amber">
        <div class="sr-stat-num"><?php echo (int)($jo['pending']??0); ?></div>
        <div class="sr-stat-lbl">Pending</div>
      </div>
      <div class="sr-stat red">
        <div class="sr-stat-num"><?php echo (int)($jo['cancelled']??0); ?></div>
        <div class="sr-stat-lbl">Cancelled</div>
      </div>
      <div class="sr-stat green">
        <div class="sr-stat-num" style="font-size:18px">₱<?php echo number_format((float)($jo['total_value']??0),0); ?></div>
        <div class="sr-stat-lbl">Total Value</div>
      </div>
      <?php if((int)($jo['total']??0) > 0): ?>
      <div class="sr-stat blue">
        <div class="sr-stat-num"><?php echo round((int)($jo['completed']??0) / (int)($jo['total']??1) * 100); ?>%</div>
        <div class="sr-stat-lbl">Completion Rate</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Deliveries Breakdown -->
  <div class="sr-card" style="margin-bottom:16px">
    <div class="sr-card-head">
      <div class="sr-card-title"><i class="fas fa-box"></i> Deliveries Breakdown</div>
    </div>
    <div class="sr-stat-grid">
      <div class="sr-stat blue">
        <div class="sr-stat-num"><?php echo (int)($del['total']??0); ?></div>
        <div class="sr-stat-lbl">Total Encoded</div>
      </div>
      <div class="sr-stat amber">
        <div class="sr-stat-num"><?php echo (int)($del['encoded']??0); ?></div>
        <div class="sr-stat-lbl">Awaiting Confirm</div>
      </div>
      <div class="sr-stat blue">
        <div class="sr-stat-num"><?php echo (int)($del['confirmed']??0); ?></div>
        <div class="sr-stat-lbl">Confirmed</div>
      </div>
      <div class="sr-stat green">
        <div class="sr-stat-num"><?php echo (int)($del['closed']??0); ?></div>
        <div class="sr-stat-lbl">Closed</div>
      </div>
    </div>
  </div>

  <!-- Customer Linkage -->
  <div class="sr-card">
    <div class="sr-card-head">
      <div class="sr-card-title"><i class="fas fa-users"></i> Customer Linkage</div>
    </div>
    <div class="sr-stat-grid">
      <div class="sr-stat blue">
        <div class="sr-stat-num"><?php echo (int)($cus['total']??0); ?></div>
        <div class="sr-stat-lbl">Total JOs</div>
      </div>
      <div class="sr-stat green">
        <div class="sr-stat-num"><?php echo (int)($cus['linked']??0); ?></div>
        <div class="sr-stat-lbl">Linked Customers</div>
      </div>
      <div class="sr-stat amber">
        <div class="sr-stat-num" style="font-size:18px">₱<?php echo number_format((float)($cus['ar_total']??0),0); ?></div>
        <div class="sr-stat-lbl">A/R Total</div>
      </div>
    </div>
  </div>

  <?php endif; ?>

</div><!-- .sr-wrap -->

<?php include __DIR__ . '/../partials/footer.php'; ?>
