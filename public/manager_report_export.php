<?php
// manager_report_export.php — handles CSV, Excel, PDF for all manager report sections
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
$me = current_user();
$role = strtolower(trim($me['role'] ?? ''));
$station_id = user_station_id();
if (!in_array($role, ['manager','admin','superadmin'])) { header('Location: dashboard.php'); exit; }
if (!$station_id) die('No station assigned.');

$section    = trim($_GET['section'] ?? 'sales');
$range      = trim($_GET['range']   ?? 'month');
$date_start = trim($_GET['start']   ?? date('Y-m-01'));
$date_end   = trim($_GET['end']     ?? date('Y-m-d'));
$format     = strtolower(trim($_GET['format'] ?? 'csv')); // csv | excel | pdf

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = date('Y-m-d');

$label_map = ['sales'=>'Sales Reports','job_orders'=>'Job Orders','balances'=>'Customer Balances',
              'deliveries'=>'Deliveries','staff'=>'Staff Performance','validation'=>'Validation Logs'];
$section_label = $label_map[$section] ?? ucfirst($section);

// ── helpers ──────────────────────────────────────────────────────────────────
$fsc = "LOWER(TRIM(COALESCE(ft.status,''))) NOT IN ('rejected','cancelled','voided','void')";

function q($pdo, $sql, $params) {
    $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ── fetch data ────────────────────────────────────────────────────────────────
$datasets = [];

if ($section === 'sales') {
    $has_vt = false;
    try { $pdo->query("SELECT 1 FROM fuel_variance_reports LIMIT 1"); $has_vt = true; } catch(Exception $e){}
    global $fsc;
    $fsc_local = "LOWER(TRIM(COALESCE(ft.status,''))) NOT IN ('rejected','cancelled','voided','void')";
    if ($has_vt) {
        $fuel_rows = q($pdo,"SELECT DATE(ft.transaction_date) AS sale_date, ft.fuel_type,
            COUNT(ft.transaction_id) AS txn_count, COALESCE(SUM(ft.liters_sold),0) AS total_liters,
            COALESCE(SUM(ft.total_amount),0) AS total_revenue, COALESCE(AVG(fvr.variance_liters),0) AS avg_variance_liters
            FROM fuel_transactions ft LEFT JOIN fuel_variance_reports fvr ON fvr.station_id=ft.station_id
            AND DATE(fvr.report_date)=DATE(ft.transaction_date) AND LOWER(TRIM(fvr.fuel_type))=LOWER(TRIM(ft.fuel_type))
            WHERE ft.station_id=? AND $fsc_local AND DATE(ft.transaction_date) BETWEEN ? AND ?
            GROUP BY DATE(ft.transaction_date),ft.fuel_type ORDER BY sale_date DESC,ft.fuel_type",
            [$station_id,$date_start,$date_end]);
    } else {
        $fuel_rows = q($pdo,"SELECT DATE(ft.transaction_date) AS sale_date, ft.fuel_type,
            COUNT(ft.transaction_id) AS txn_count, COALESCE(SUM(ft.liters_sold),0) AS total_liters,
            COALESCE(SUM(ft.total_amount),0) AS total_revenue, 0 AS avg_variance_liters
            FROM fuel_transactions ft WHERE ft.station_id=? AND $fsc_local AND DATE(ft.transaction_date) BETWEEN ? AND ?
            GROUP BY DATE(ft.transaction_date),ft.fuel_type ORDER BY sale_date DESC,ft.fuel_type",
            [$station_id,$date_start,$date_end]);
    }
    $mde = "CASE WHEN mt.transaction_date > '2000-01-01' THEN DATE(mt.transaction_date) ELSE DATE(mt.created_at) END";
    $merch_rows = q($pdo,"SELECT ($mde) AS sale_date, COUNT(mt.id) AS txn_count,
        COALESCE(SUM(mt.total_amount),0) AS total_revenue,
        COALESCE(SUM(CASE WHEN si.id IS NOT NULL THEN si.quantity ELSE 0 END),0) AS total_quantity,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('cash') THEN mt.total_amount ELSE 0 END),0) AS pay_cash,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('credit card','card','debit card') THEN mt.total_amount ELSE 0 END),0) AS pay_card,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('gcash','maya','paymaya','e-wallet','ewallet') THEN mt.total_amount ELSE 0 END),0) AS pay_ewallet,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('e-fuel card','fuel card','efuel') THEN mt.total_amount ELSE 0 END),0) AS pay_efuel,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(mt.payment_method)) IN ('account receivable','credit','utang') THEN mt.total_amount ELSE 0 END),0) AS pay_credit
        FROM merchandise_transactions mt LEFT JOIN sale_items si ON si.sale_id=mt.id
        WHERE mt.station_id=? AND ($mde) BETWEEN ? AND ?
        AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','returned','cancelled')
        GROUP BY ($mde) ORDER BY sale_date DESC",[$station_id,$date_start,$date_end]);
    $datasets['fuel']  = ['title'=>'Fuel Sales Report','headers'=>['Date','Fuel Type','Transactions','Liters Sold','Revenue (PHP)','Variance vs Pump'],'rows'=>$fuel_rows,'map'=>function($r){return [date('M j, Y',strtotime($r['sale_date'])),$r['fuel_type'],$r['txn_count'],number_format($r['total_liters'],2).' L',number_format($r['total_revenue'],2),number_format($r['avg_variance_liters'],4).' L'];}];
    $datasets['merch'] = ['title'=>'Merchandise Sales Report','headers'=>['Date','Transactions','Qty Sold','Revenue (PHP)','Cash','Card','E-Wallet','E-Fuel Card','Credit'],'rows'=>$merch_rows,'map'=>function($r){return [date('M j, Y',strtotime($r['sale_date'])),$r['txn_count'],$r['total_quantity'],number_format($r['total_revenue'],2),number_format($r['pay_cash'],2),number_format($r['pay_card'],2),number_format($r['pay_ewallet'],2),number_format($r['pay_efuel'],2),number_format($r['pay_credit'],2)];}];
}

if ($section === 'job_orders') {
    $jo_rows = q($pdo,"SELECT COALESCE(jo.job_order_id,jo.job_order_number,CONCAT('JO-',jo.id)) AS jo_ref,
        COALESCE(jo.customer_name,c.name,'Walk-in') AS customer,
        COALESCE(jo.vehicle_plate,'') AS vehicle_plate, COALESCE(jo.vehicle_type,'') AS vehicle_type,
        COALESCE(jo.service_type,jo.service_description,'') AS service_type,
        COALESCE(staff.name,'') AS assigned_staff, COALESCE(mech.name,'') AS mechanic,
        COALESCE(jo.validation_status,'Pending') AS validation_status, COALESCE(jo.status,'') AS jo_status,
        COALESCE(jo.actual_labor_cost,jo.estimated_labor_cost,0) AS labor_cost,
        COALESCE(jo.actual_parts_cost,jo.estimated_parts_cost,0) AS parts_cost,
        COALESCE(jo.total_cost,jo.estimated_cost,0) AS total_cost,
        COALESCE(jo.amount_paid,0) AS amount_paid, COALESCE(jo.payment_method,'') AS payment_method, jo.created_at
        FROM job_orders jo LEFT JOIN customers c ON c.id=jo.customer_id
        LEFT JOIN users staff ON staff.id=jo.created_by LEFT JOIN users mech ON mech.id=jo.assigned_mechanic_id
        WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ? ORDER BY jo.created_at DESC",
        [$station_id,$date_start,$date_end]);
    $datasets['jo'] = ['title'=>'Job Orders Report','headers'=>['JO Reference','Customer','Vehicle Plate','Vehicle Type','Service Type','Staff','Mechanic','Validation Status','JO Status','Labor Cost','Parts Cost','Total Cost','Amount Paid','Payment Method','Date'],'rows'=>$jo_rows,'map'=>function($r){return [$r['jo_ref'],$r['customer'],$r['vehicle_plate'],$r['vehicle_type'],$r['service_type'],$r['assigned_staff'],$r['mechanic'],$r['validation_status'],$r['jo_status'],number_format($r['labor_cost'],2),number_format($r['parts_cost'],2),number_format($r['total_cost'],2),number_format($r['amount_paid'],2),$r['payment_method'],date('M j, Y',strtotime($r['created_at']))];}];
}

if ($section === 'balances') {
    $cust_rows = q($pdo,"SELECT c.name, COALESCE(c.type,'credit') AS type, COALESCE(c.contact_number,'') AS contact,
        COALESCE(c.credit_limit,0) AS credit_limit, COALESCE(c.current_balance,c.balance,0) AS outstanding, c.status
        FROM customers c WHERE c.station_id=? AND COALESCE(c.current_balance,c.balance,0)>0 ORDER BY outstanding DESC",[$station_id]);
    $jo_credit = q($pdo,"SELECT COALESCE(jo.job_order_id,jo.job_order_number,CONCAT('JO-',jo.id)) AS jo_ref,
        COALESCE(jo.customer_name,'Walk-in') AS customer_name, jo.service_type,
        COALESCE(jo.estimated_cost,jo.total_cost,0) AS total_cost, COALESCE(jo.amount_paid,0) AS amount_paid,
        COALESCE(jo.estimated_cost,jo.total_cost,0)-COALESCE(jo.amount_paid,0) AS balance_due,
        jo.validation_status, jo.created_at FROM job_orders jo WHERE jo.station_id=?
        AND LOWER(TRIM(jo.payment_method)) IN ('credit','account receivable','utang')
        AND LOWER(TRIM(COALESCE(jo.payment_status,''))) NOT IN ('paid','fully paid') ORDER BY jo.created_at DESC",[$station_id]);
    $mt_credit = q($pdo,"SELECT mt.transaction_id, COALESCE(mt.customer_name,'Walk-in') AS customer_name,
        mt.total_amount, mt.payment_method, mt.validation_status,
        COALESCE(mt.transaction_date,mt.created_at) AS txn_date FROM merchandise_transactions mt
        WHERE mt.station_id=? AND LOWER(TRIM(mt.payment_method)) IN ('credit','account receivable','utang')
        AND LOWER(TRIM(COALESCE(mt.validation_status,''))) NOT IN ('rejected','cancelled') ORDER BY txn_date DESC",[$station_id]);
    $datasets['cust']  = ['title'=>'Customer Credit Balances','headers'=>['Customer','Type','Contact','Credit Limit (PHP)','Outstanding (PHP)','Usage %','Status'],'rows'=>$cust_rows,'map'=>function($r){$u=$r['credit_limit']>0?($r['outstanding']/$r['credit_limit'])*100:100;return [$r['name'],ucfirst($r['type']),$r['contact'],number_format($r['credit_limit'],2),number_format($r['outstanding'],2),number_format($u,1).'%',ucfirst($r['status'])];}];
    $datasets['jo_cr'] = ['title'=>'Unpaid Credit Job Orders','headers'=>['JO Reference','Customer','Service','Total Cost','Amount Paid','Balance Due','Validation Status','Date'],'rows'=>$jo_credit,'map'=>function($r){return [$r['jo_ref'],$r['customer_name'],$r['service_type'],number_format($r['total_cost'],2),number_format($r['amount_paid'],2),number_format($r['balance_due'],2),$r['validation_status'],date('M j, Y',strtotime($r['created_at']))];}];
    $datasets['mt_cr'] = ['title'=>'Unpaid Credit Merchandise','headers'=>['Transaction Ref','Customer','Amount (PHP)','Payment Method','Validation Status','Date'],'rows'=>$mt_credit,'map'=>function($r){return [$r['transaction_id'],$r['customer_name'],number_format($r['total_amount'],2),$r['payment_method'],$r['validation_status'],date('M j, Y',strtotime($r['txn_date']))];}];
}

if ($section === 'deliveries') {
    $do_rows = q($pdo,"SELECT COALESCE(d.delivery_ref,CONCAT('DEL-',d.id)) AS delivery_id,
        CASE WHEN d.delivery_type='fuel' THEN 'Fuel' WHEN d.delivery_type='merchandise' THEN 'Merchandise' ELSE COALESCE(d.delivery_type,'General') END AS delivery_type,
        COALESCE(d.supplier,'Unknown') AS supplier_name, COALESCE(d.product,'Unknown') AS product_name,
        COALESCE(d.quantity,0) AS quantity_delivered, COALESCE(d.unit,'pcs') AS unit_type,
        COALESCE(d.dr_number,'') AS dr_number, COALESCE(d.delivery_date,DATE(d.created_at)) AS delivery_date,
        COALESCE(u.name,'Unknown') AS encoded_by, d.status, COALESCE(d.admin_notes,d.remarks,'') AS remarks
        FROM deliveries_oversight d LEFT JOIN users u ON u.id=d.encoded_by
        WHERE d.station_id=? AND DATE(COALESCE(d.delivery_date,d.created_at)) BETWEEN ? AND ?
        ORDER BY COALESCE(d.delivery_date,DATE(d.created_at)) DESC",[$station_id,$date_start,$date_end]);
    $fd_rows = q($pdo,"SELECT CONCAT('FD-',fd.id) AS delivery_id, COALESCE(fd.supplier,'Petron Corporation') AS supplier_name,
        COALESCE(fd.fuel_type,'Fuel') AS fuel_type, COALESCE(fd.delivery_liters,0) AS delivery_liters,
        COALESCE(fd.invoice_no,'') AS invoice_no, fd.delivery_date,
        COALESCE(u.name,'Unknown') AS received_by, fd.status, COALESCE(fd.notes,'') AS notes
        FROM fuel_deliveries fd LEFT JOIN users u ON u.id=fd.received_by
        WHERE fd.station_id=? ORDER BY fd.delivery_date DESC LIMIT 100",[$station_id]);
    $datasets['do'] = ['title'=>'Merchandise & General Deliveries','headers'=>['Reference','Type','Supplier','Product','Qty','Unit','DR Number','Delivery Date','Encoded By','Status','Remarks'],'rows'=>$do_rows,'map'=>function($r){return [$r['delivery_id'],$r['delivery_type'],$r['supplier_name'],$r['product_name'],number_format($r['quantity_delivered'],2),$r['unit_type'],$r['dr_number'],$r['delivery_date']?date('M j, Y',strtotime($r['delivery_date'])):'—',$r['encoded_by'],$r['status'],$r['remarks']?:'—'];}];
    $datasets['fd'] = ['title'=>'Fuel Tanker Deliveries','headers'=>['Reference','Supplier','Fuel Type','Liters','Invoice/DR No.','Delivery Date','Received By','Status','Notes'],'rows'=>$fd_rows,'map'=>function($r){return [$r['delivery_id'],$r['supplier_name'],$r['fuel_type'],number_format($r['delivery_liters'],2),$r['invoice_no'],$r['delivery_date']?date('M j, Y',strtotime($r['delivery_date'])):'—',$r['received_by'],$r['status'],$r['notes']?:'—'];}];
}

if ($section === 'staff') {
    $staff_rows = q($pdo,"SELECT u.id AS staff_id, u.name AS staff_name, u.role,
        COALESCE(ft.cnt,0) AS fuel_transactions, COALESCE(mt.cnt,0) AS merch_transactions,
        COALESCE(ft.cnt,0)+COALESCE(mt.cnt,0) AS total_transactions,
        COALESCE(jo.cnt,0) AS job_orders_encoded, COALESCE(dv.cnt,0) AS deliveries_encoded,
        COALESCE(ls.total_hours,0) AS total_hours, COALESCE(ls.shift_count,0) AS shift_count,
        COALESCE(ls.attendance_days,0) AS attendance_days
        FROM users u
        LEFT JOIN (SELECT staff_id,COUNT(*) AS cnt FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ? GROUP BY staff_id) ft ON ft.staff_id=u.id
        LEFT JOIN (SELECT staff_id,COUNT(*) AS cnt FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ? GROUP BY staff_id) mt ON mt.staff_id=u.id
        LEFT JOIN (SELECT created_by,COUNT(*) AS cnt FROM job_orders WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ? GROUP BY created_by) jo ON jo.created_by=u.id
        LEFT JOIN (SELECT encoded_by,COUNT(*) AS cnt FROM deliveries_oversight WHERE station_id=? AND DATE(COALESCE(delivery_date,created_at)) BETWEEN ? AND ? GROUP BY encoded_by) dv ON dv.encoded_by=u.id
        LEFT JOIN (SELECT user_id,SUM(hours_worked) AS total_hours,COUNT(*) AS shift_count,COUNT(DISTINCT DATE(start_time)) AS attendance_days FROM labor_sessions WHERE station_id=? AND DATE(start_time) BETWEEN ? AND ? GROUP BY user_id) ls ON ls.user_id=u.id
        WHERE u.station_id=? AND u.status='active'
        ORDER BY (COALESCE(ft.cnt,0)+COALESCE(mt.cnt,0)+COALESCE(jo.cnt,0)) DESC, u.name ASC",
        [$station_id,$date_start,$date_end,$station_id,$date_start,$date_end,$station_id,$date_start,$date_end,$station_id,$date_start,$date_end,$station_id,$date_start,$date_end,$station_id]);
    $datasets['staff'] = ['title'=>'Staff Performance Report','headers'=>['Staff ID','Name','Role','Fuel Txns','Merch Txns','Total Txns','Job Orders','Deliveries','Total Hours','Shifts','Attendance Days','Performance Score'],'rows'=>$staff_rows,'map'=>function($r){$s=($r['total_transactions']*1)+($r['job_orders_encoded']*2)+($r['deliveries_encoded']*3);return ['#'.$r['staff_id'],$r['staff_name'],ucfirst($r['role']),$r['fuel_transactions'],$r['merch_transactions'],$r['total_transactions'],$r['job_orders_encoded'],$r['deliveries_encoded'],number_format($r['total_hours'],1).'h',$r['shift_count'],$r['attendance_days'],$s];}];
}

if ($section === 'validation') {
    $val_rows = [];
    try {
        $s = $pdo->prepare("SELECT al.action_date AS date_time, COALESCE(u.name,'Unknown') AS manager_name,
            COALESCE(u.role,'Unknown') AS role, al.action_type AS action, al.description AS details,
            COALESCE(al.ip_address,'N/A') AS ip_address, al.module_name, al.record_id
            FROM audit_log al LEFT JOIN users u ON u.id=al.user_id
            WHERE al.station_id=? AND DATE(al.action_date) BETWEEN ? AND ? ORDER BY al.action_date DESC");
        $s->execute([$station_id,$date_start,$date_end]); $val_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Exception $e){}
    if (empty($val_rows)) {
        try {
            $s = $pdo->prepare("SELECT jo.validated_at AS date_time, COALESCE(u.name,'Unknown') AS manager_name,
                COALESCE(u.role,'Unknown') AS role, COALESCE(jo.validation_status,'Validated') AS action,
                CONCAT('Job Order ',COALESCE(jo.job_order_id,jo.job_order_number,CONCAT('JO-',jo.id)),' - ',COALESCE(jo.customer_name,'Walk-in')) AS details,
                'N/A' AS ip_address, 'Job Orders' AS module_name, jo.id AS record_id
                FROM job_orders jo LEFT JOIN users u ON u.id=jo.validated_by
                WHERE jo.station_id=? AND jo.validated_at IS NOT NULL AND DATE(jo.validated_at) BETWEEN ? AND ?
                ORDER BY jo.validated_at DESC");
            $s->execute([$station_id,$date_start,$date_end]); $val_rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch(Exception $e){}
    }
    $datasets['val'] = ['title'=>'Validation Logs','headers'=>['Date & Time','Manager','Role','Action','Details','IP Address','Module','Record ID'],'rows'=>$val_rows,'map'=>function($r){return [date('M j, Y g:i A',strtotime($r['date_time'])),$r['manager_name'],ucfirst($r['role']),$r['action'],$r['details'],$r['ip_address'],$r['module_name']?:'—',$r['record_id']?:'—'];}];
}

// ── OUTPUT ────────────────────────────────────────────────────────────────────
$station_name = '';
try { $sn=$pdo->prepare("SELECT name FROM stations WHERE id=?"); $sn->execute([$station_id]); $station_name=$sn->fetchColumn()?:''; } catch(Exception $e){}

if ($format === 'pdf') {
    // HTML print page
    header('Content-Type: text/html; charset=utf-8');
    $period_label = date('M j, Y', strtotime($date_start)) . ' – ' . date('M j, Y', strtotime($date_end));
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.$section_label.' – '.$station_name.'</title>';
    echo '<style>
    body{font-family:Arial,sans-serif;font-size:12px;color:#111;margin:20px;}
    h1{font-size:18px;color:#00264D;margin:0 0 4px;}
    .sub{font-size:11px;color:#555;margin-bottom:16px;}
    h2{font-size:14px;color:#00264D;margin:20px 0 8px;border-bottom:2px solid #00264D;padding-bottom:4px;}
    table{width:100%;border-collapse:collapse;margin-bottom:20px;font-size:11px;}
    th{background:#00264D;color:#fff;padding:6px 8px;text-align:left;white-space:nowrap;}
    td{padding:5px 8px;border-bottom:1px solid #e5e7eb;}
    tr:nth-child(even) td{background:#f8fafc;}
    .empty{color:#9ca3af;font-style:italic;padding:12px;}
    @media print{body{margin:10px;} .no-print{display:none;}}
    </style></head><body>';
    echo '<div class="no-print" style="margin-bottom:16px;"><button onclick="window.print()" style="background:#00264D;color:#fff;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-size:13px;">🖨 Print / Save as PDF</button>&nbsp;<a href="javascript:history.back()" style="color:#00264D;font-size:13px;">← Back</a></div>';
    echo '<h1>'.htmlspecialchars($section_label).'</h1>';
    echo '<div class="sub">'.htmlspecialchars($station_name).' &nbsp;|&nbsp; Period: '.$period_label.'</div>';
    foreach ($datasets as $ds) {
        echo '<h2>'.htmlspecialchars($ds['title']).'</h2>';
        if (empty($ds['rows'])) { echo '<p class="empty">No data for this period.</p>'; continue; }
        echo '<table><thead><tr>';
        foreach ($ds['headers'] as $h) echo '<th>'.htmlspecialchars($h).'</th>';
        echo '</tr></thead><tbody>';
        foreach ($ds['rows'] as $row) {
            $cells = ($ds['map'])($row);
            echo '<tr>';
            foreach ($cells as $cell) echo '<td>'.htmlspecialchars((string)$cell).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</body></html>';
    exit;
}

// CSV / Excel
$is_excel = ($format === 'excel');
$ext = $is_excel ? 'xls' : 'csv';
$mime = $is_excel ? 'application/vnd.ms-excel' : 'text/csv';
$filename = 'report_'.$section.'_'.$date_start.'_to_'.$date_end.'.'.$ext;
header('Content-Type: '.$mime.'; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
$out = fopen('php://output','w');
if ($is_excel) fwrite($out, "\xEF\xBB\xBF"); // BOM

foreach ($datasets as $ds) {
    fputcsv($out, [$ds['title']]);
    fputcsv($out, $ds['headers']);
    if (empty($ds['rows'])) { fputcsv($out, ['No data for this period.']); }
    else { foreach ($ds['rows'] as $row) fputcsv($out, ($ds['map'])($row)); }
    fputcsv($out, []);
}
fclose($out);
exit;
