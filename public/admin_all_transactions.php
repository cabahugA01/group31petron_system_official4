<?php
$page_id = 'admin_all_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

// ── Column detection ──────────────────────────────────────────────────────────
function aat_cols(PDO $p, string $t): array {
    try { $r=[]; foreach($p->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $c) $r[strtolower($c['Field'])]=true; return $r; }
    catch(Exception $e){ return []; }
}
function aat_has(array $m, string $c): bool { return isset($m[strtolower($c)]); }

$mt_cols = aat_cols($pdo,'merchandise_transactions');
$jo_cols = aat_cols($pdo,'job_orders');

$mt_date = aat_has($mt_cols,'transaction_date')
    ? "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END"
    : 'mt.created_at';
$mt_stat = aat_has($mt_cols,'validation_status') ? 'mt.validation_status' : "'Approved'";
$mt_shift = "CASE WHEN LOWER(TRIM(COALESCE(mt.shift_period, mt.shift_name, u.assigned_shift, u.shift_assignment, ''))) IN ('first', 'shift 1', 'shift1') THEN 'Shift 1' WHEN LOWER(TRIM(COALESCE(mt.shift_period, mt.shift_name, u.assigned_shift, u.shift_assignment, ''))) IN ('second', 'shift 2', 'shift2') THEN 'Shift 2' ELSE COALESCE(NULLIF(TRIM(mt.shift_period),''), NULLIF(TRIM(mt.shift_name),''), NULLIF(TRIM(u.assigned_shift),''), NULLIF(TRIM(u.shift_assignment),''), 'N/A') END";
$mt_pay   = aat_has($mt_cols,'payment_method') ? "COALESCE(mt.payment_method,'Cash')" : "'Cash'";
$mt_pstat = aat_has($mt_cols,'payment_status') ? "COALESCE(mt.payment_status,'')" : "''";

// ── Filters ───────────────────────────────────────────────────────────────────
// DEFAULT: Show last 365 days (1 year) to ensure we catch all historical staff transactions
$date_from  = trim($_GET['date_from']       ?? date('Y-m-d', strtotime('-365 days')));
$date_to    = trim($_GET['date_to']         ?? date('Y-m-d'));
$f_shift    = trim($_GET['shift']           ?? '');
$f_staff    = trim($_GET['staff']           ?? '');
$f_type     = trim($_GET['type']            ?? '');
$f_pay      = trim($_GET['payment_method']  ?? '');
$f_pstatus  = trim($_GET['payment_status']  ?? '');
$search     = trim($_GET['search']          ?? '');

// ── Fetch staff list ──────────────────────────────────────────────────────────
$staff_list = [];
try {
    $s = $pdo->prepare("SELECT DISTINCT u.id, COALESCE(NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),' '),u.username,'Unknown') as name
        FROM users u JOIN merchandise_transactions mt ON mt.staff_id=u.id WHERE mt.station_id=? ORDER BY name");
    $s->execute([$station_id]);
    $staff_list = $s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// ── Build WHERE clause ────────────────────────────────────────────────────────
// IMPORTANT: Show ALL transactions by default - only filter by date if user provides specific dates
$where  = "WHERE mt.station_id=?";
$params = [$station_id];

// Only apply date filter if dates are explicitly provided by user
if($date_from !== '' && $date_to !== '') {
    $where .= " AND DATE($mt_date) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
}

if($search!=='') {
    // Search transaction_id, customer_name, and vehicle_plate (if column exists)
    $veh_search = aat_has($mt_cols,'vehicle_plate') ? ' OR mt.vehicle_plate LIKE ?' : '';
    $where.=" AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?$veh_search)";
    $params[]="%$search%"; $params[]="%$search%";
    if(aat_has($mt_cols,'vehicle_plate')) $params[]="%$search%";
}
// Shift filter: use raw shift_period column, not a CASE expression (CASE cannot be used in WHERE)
$shift_col = aat_has($mt_cols,'shift_period') ? 'mt.shift_period' : (aat_has($mt_cols,'shift_name') ? 'mt.shift_name' : null);
if($f_shift!=='' && $shift_col) { $where.=" AND COALESCE($shift_col,'')=?"; $params[]=$f_shift; }
if($f_staff!=='') { $where.=" AND mt.staff_id=?"; $params[]=$f_staff; }
if($f_type==='merchandise') { $where.=" AND COALESCE(mt.transaction_type,'merchandise')='merchandise'"; }
elseif($f_type==='job_order') { $where.=" AND COALESCE(mt.transaction_type,'merchandise') IN ('job_order','combined')"; }
if($f_pay!=='') { $where.=" AND LOWER(TRIM($mt_pay))=LOWER(?)"; $params[]=$f_pay; }
if($f_pstatus!=='') { $where.=" AND LOWER(TRIM($mt_pstat))=LOWER(?)"; $params[]=$f_pstatus; }

// ── KPIs ──────────────────────────────────────────────────────────────────────
$today = date('Y-m-d');
$kpi_today_txn=0; $kpi_today_sales=0.0; $kpi_active_staff=0; $kpi_active_shifts=0;
try {
    $s=$pdo->prepare("SELECT COUNT(*) as c, COALESCE(SUM(total_amount),0) as s FROM merchandise_transactions mt WHERE station_id=? AND DATE($mt_date)=?");
    $s->execute([$station_id,$today]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    $kpi_today_txn=(int)$r['c']; $kpi_today_sales=(float)$r['s'];
    $s2=$pdo->prepare("SELECT COUNT(DISTINCT staff_id) FROM merchandise_transactions mt WHERE station_id=? AND DATE($mt_date)=?");
    $s2->execute([$station_id,$today]);
    $kpi_active_staff=(int)$s2->fetchColumn();
    // Count distinct shifts active today — use shift_period column
    $kpi_shift_col = aat_has($mt_cols,'shift_period') ? 'shift_period' : (aat_has($mt_cols,'shift_name') ? 'shift_name' : null);
    if($kpi_shift_col) {
        $s3=$pdo->prepare("SELECT COUNT(DISTINCT $kpi_shift_col) FROM merchandise_transactions mt WHERE station_id=? AND DATE($mt_date)=?");
        $s3->execute([$station_id,$today]);
        $kpi_active_shifts=(int)$s3->fetchColumn();
    }
} catch(Exception $e){}

// ── Fetch rows ────────────────────────────────────────────────────────────────
$rows=[];
$veh_col = aat_has($mt_cols,'vehicle_plate') ? 'COALESCE(mt.vehicle_plate,"—")' : '"—"';
$staff_col = "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '),u.username,'Unknown')";
try {
    $s=$pdo->prepare("SELECT mt.transaction_id, COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') as customer,
        COALESCE(mt.transaction_type,'Merchandise') as txn_type,
        $veh_col as vehicle,
        mt.total_amount as amount, $mt_pay as payment_method,
        $mt_shift as shift, $staff_col as staff_name,
        $mt_pstat as payment_status, $mt_date as txn_date,
        GROUP_CONCAT(CONCAT(mti.product_name, ' (x', mti.quantity, ')') ORDER BY mti.id SEPARATOR ', ') as items
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id=mt.staff_id
        LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id=mt.id
        $where GROUP BY mt.id ORDER BY $mt_date DESC LIMIT 500");
    $s->execute($params);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// ── Export ────────────────────────────────────────────────────────────────────
$export=$_GET['export']??'';
if(in_array($export,['excel','csv'])) {
    $fn='all_transactions_'.date('Ymd_His');
    if($export==='excel'){ header('Content-Type: application/vnd.ms-excel'); header("Content-Disposition: attachment; filename=\"{$fn}.xls\""); }
    else { header('Content-Type: text/csv; charset=utf-8'); header("Content-Disposition: attachment; filename=\"{$fn}.csv\""); }
    $out=fopen('php://output','w');
    fputcsv($out,['Transaction ID','Customer','Type','Items/Service','Vehicle','Amount','Payment Method','Shift','Staff Encoder','Status','Date']);
    foreach($rows as $r) fputcsv($out,[$r['transaction_id'],$r['customer'],ucwords(str_replace('_',' ',$r['txn_type'])),$r['items']?:'—',$r['vehicle'],'₱'.number_format($r['amount'],2),$r['payment_method'],$r['shift'],$r['staff_name'],$r['payment_status']?:'N/A',date('M d, Y H:i',strtotime($r['txn_date']))]);
    fclose($out); exit;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.page-head.txn-page-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;margin-top:-12px !important;}
.page-head.txn-page-head h1{font-size:22px !important;font-weight:700 !important;color:var(--petron-blue,#00264D) !important;margin:0 !important;text-transform:none !important;display:flex;align-items:center;gap:8px;}
.page-head.txn-page-head .sub{font-size:13px;color:#666;margin-top:4px;text-transform:none !important;font-weight:400 !important;}
.flt-btn{display:inline-flex;align-items:center;gap:6px;padding:0 16px;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;background:white !important;border:1px solid transparent;}
.flt-btn-reset{color:#6b7280 !important;border-color:#6b7280 !important;} .flt-btn-reset:hover{background:#6b7280 !important;color:#fff !important;}
.flt-btn-excel{color:#1d6f42 !important;border-color:#1d6f42 !important;} .flt-btn-excel:hover{background:#1d6f42 !important;color:#fff !important;}
.flt-btn-search{color:#00264D !important;border-color:#00264D !important;} .flt-btn-search:hover{background:#00264D !important;color:#fff !important;}
.flt-btn-pdf{color:#dc2626 !important;border-color:#dc2626 !important;} .flt-btn-pdf:hover{background:#dc2626 !important;color:#fff !important;}
.flt-btn-solid-primary{color:#fff !important;background:#002F70 !important;border-color:#002F70 !important;}
.txn-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px;}
.txn-kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.05);transition:transform .15s,box-shadow .15s;}
.txn-kpi-card:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(0,0,0,.09);}
.txn-kpi-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.txn-kpi-val{font-size:26px;font-weight:800;color:#002F70;line-height:1.1;}
.txn-kpi-card.total-amount-card{background:linear-gradient(135deg,#003d7a 0%,#00264D 100%);}
.txn-kpi-card.total-amount-card .txn-kpi-lbl{color:#93c5fd;} .txn-kpi-card.total-amount-card .txn-kpi-val{color:#fff;}
.filters{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:18px;}
.filters>div{display:flex;flex-direction:column;gap:3px;}
.filters label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;}
.filters .inp{height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;min-width:130px;}
.filters .inp:focus{border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1);}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border-bottom:1px solid #e9ecef;background:#f8fafc;}
.card-title{font-size:13px;font-weight:700;color:#00264D;}
.t{width:100%;border-collapse:collapse;font-size:12px;}
.t thead tr{background:#002F70;}
.t th{padding:9px 12px;text-align:left;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.4px;}
.t tbody tr{border-bottom:1px solid #f1f5f9;} .t tbody tr:hover td{background:#eff6ff;}
.t tbody td{padding:9px 12px;color:#334155;background:#fff;font-size:12px;vertical-align:middle;}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;}
.badge-green{background:#dcfce7;color:#166534;} .badge-blue{background:#dbeafe;color:#1e40af;}
.badge-orange{background:#fff7ed;color:#9a3412;} .badge-gray{background:#f1f5f9;color:#475569;}
.badge-red{background:#fee2e2;color:#991b1b;}
</style>

<div class="page-head txn-page-head">
    <div>
        <h1><i class="fas fa-list-alt"></i> All Transactions Oversight</h1>
        <div class="sub">Monitor and review all transactions system-wide across all shifts and staff encoders.</div>
    </div>
</div>

<?php
// Tab badge counts
$atab_voided=0; $atab_adj=0;
try {
    $s=$pdo->prepare("SELECT COUNT(*) FROM voided_transactions WHERE station_id=? AND DATE(void_date) BETWEEN ? AND ?");
    $s->execute([$station_id,$date_from,$date_to]); $atab_voided=(int)$s->fetchColumn();
} catch(Exception $e){}
try {
    $s=$pdo->prepare("SELECT COUNT(*) FROM transaction_adjustments WHERE station_id=? AND DATE(adjustment_date) BETWEEN ? AND ?");
    $s->execute([$station_id,$date_from,$date_to]); $atab_adj=(int)$s->fetchColumn();
} catch(Exception $e){}
?>

<!-- KPI Cards -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card"><div class="txn-kpi-lbl"><i class="fas fa-receipt"></i> Today's Transactions</div><div class="txn-kpi-val"><?=number_format($kpi_today_txn)?></div></div>
    <div class="txn-kpi-card total-amount-card"><div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Today's Sales</div><div class="txn-kpi-val">₱<?=number_format($kpi_today_sales,2)?></div></div>
    <div class="txn-kpi-card"><div class="txn-kpi-lbl"><i class="fas fa-users"></i> Active Staff Encoders</div><div class="txn-kpi-val"><?=number_format($kpi_active_staff)?></div></div>
    <div class="txn-kpi-card"><div class="txn-kpi-lbl"><i class="fas fa-clock"></i> Active Shifts</div><div class="txn-kpi-val"><?=number_format($kpi_active_shifts)?></div></div>
</div>

<!-- Filters -->
<form method="get" class="filters">
    <div><label>From</label><input type="date" name="date_from" value="<?=htmlspecialchars($date_from)?>" class="inp"></div>
    <div><label>To</label><input type="date" name="date_to" value="<?=htmlspecialchars($date_to)?>" class="inp"></div>
    <div>
        <label>Shift</label>
        <select name="shift" class="inp">
            <option value="">All Shifts</option>
            <option value="Shift 1" <?=$f_shift==='Shift 1'?'selected':''?>>Shift 1</option>
            <option value="Shift 2" <?=$f_shift==='Shift 2'?'selected':''?>>Shift 2</option>
        </select>
    </div>
    <div>
        <label>Staff Encoder</label>
        <select name="staff" class="inp">
            <option value="">All Staff</option>
            <?php foreach($staff_list as $st): ?>
            <option value="<?=(int)$st['id']?>" <?=$f_staff==(int)$st['id']?'selected':''?>><?=htmlspecialchars($st['name'])?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Type</label>
        <select name="type" class="inp">
            <option value="">All Types</option>
            <option value="job_order"   <?=$f_type==='job_order'?'selected':''?>>Job Order</option>
            <option value="merchandise" <?=$f_type==='merchandise'?'selected':''?>>Merchandise</option>
        </select>
    </div>
    <div>
        <label>Payment</label>
        <select name="payment_method" class="inp">
            <option value="">All Methods</option>
            <?php foreach(['Cash','Card','E-Wallet','Petron E-Fuel','Fleet Card','Credit'] as $pm): ?>
            <option value="<?=htmlspecialchars($pm)?>" <?=$f_pay===$pm?'selected':''?>><?=htmlspecialchars($pm)?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Pay Status</label>
        <select name="payment_status" class="inp">
            <option value="">All</option>
            <option value="Paid"    <?=$f_pstatus==='Paid'?'selected':''?>>Paid</option>
            <option value="Partial" <?=$f_pstatus==='Partial'?'selected':''?>>Partial</option>
            <option value="Unpaid"  <?=$f_pstatus==='Unpaid'?'selected':''?>>Unpaid</option>
        </select>
    </div>
    <div><label>Search</label><input type="text" name="search" value="<?=htmlspecialchars($search)?>" class="inp" placeholder="ID, Customer, Plate…"></div>
    <div style="flex-direction:row;gap:6px;">
        <button type="submit" class="flt-btn flt-btn-solid-primary"><i class="fas fa-search"></i> Filter</button>
        <a href="admin_all_transactions.php" class="flt-btn flt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
    </div>
</form>

<!-- Table -->
<div class="card">
    <div class="card-head">
        <div class="card-title"><i class="fas fa-table" style="margin-right:6px;"></i>Transactions (<?=count($rows)?> records)</div>
    </div>
    <div style="overflow-x:auto;">
    <table class="t">
        <thead>
            <tr>
                <th>Transaction ID</th><th>Customer Name</th><th>Transaction Type</th>
                <th>Items / Service</th><th>Vehicle</th><th>Amount</th><th>Payment Method</th>
                <th>Shift</th><th>Staff Encoder</th><th>Status</th><th>Date & Time</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($rows)): ?>
        <tr><td colspan="12" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No transactions found</td></tr>
        <?php else: ?>
        <?php foreach($rows as $r): ?>
        <?php
            $t=strtolower($r['txn_type']??'');
            $tLabel=str_contains($t,'job')?'Job Order':(str_contains($t,'merch')?'Merchandise':ucwords(str_replace('_',' ',$r['txn_type'])));
            $tBadge=str_contains($t,'job')?'badge-orange':'badge-blue';
            $ps=strtolower($r['payment_status']??'');
            $pBadge=$ps==='paid'?'badge-green':($ps==='partial'?'badge-orange':'badge-gray');
        ?>
        <tr>
            <td><strong><?=htmlspecialchars($r['transaction_id'])?></strong></td>
            <td><?=htmlspecialchars($r['customer'])?></td>
            <td><span class="badge <?=$tBadge?>"><?=htmlspecialchars($tLabel)?></span></td>
            <td><?=htmlspecialchars($r['items']?:'—')?></td>
            <td><?=htmlspecialchars($r['vehicle'])?></td>
            <td style="font-weight:700;">₱<?=number_format($r['amount'],2)?></td>
            <td><?=htmlspecialchars($r['payment_method'])?></td>
            <td><?=htmlspecialchars($r['shift'])?></td>
            <td><?=htmlspecialchars($r['staff_name'])?></td>
            <td><?php if($r['payment_status']): ?><span class="badge <?=$pBadge?>"><?=htmlspecialchars($r['payment_status'])?></span><?php else: ?>—<?php endif; ?></td>
            <td><?=date('M d, Y h:i A',strtotime($r['txn_date']))?></td>
            <td>
                <button class="flt-btn flt-btn-search" style="height:26px;font-size:10px;padding:0 8px;"
                    onclick="openTxnModal({
                        id:    '<?=addslashes(htmlspecialchars($r['transaction_id']))?>' ,
                        customer: '<?=addslashes(htmlspecialchars($r['customer']))?>' ,
                        type:  '<?=addslashes(htmlspecialchars($tLabel))?>' ,
                        items: '<?=addslashes(htmlspecialchars($r['items']?:'—'))?>' ,
                        vehicle: '<?=addslashes(htmlspecialchars($r['vehicle']))?>' ,
                        amount: '₱<?=number_format($r['amount'],2)?>' ,
                        payment: '<?=addslashes(htmlspecialchars($r['payment_method']))?>' ,
                        shift:  '<?=addslashes(htmlspecialchars($r['shift']))?>' ,
                        staff:  '<?=addslashes(htmlspecialchars($r['staff_name']))?>' ,
                        status: '<?=addslashes(htmlspecialchars($r['payment_status']))?:' — '?>' ,
                        date:   '<?=date('M d, Y h:i A',strtotime($r['txn_date']))?>'
                    })"><i class="fas fa-eye"></i> View</button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- View Transaction Modal -->
<div id="txnModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:92%;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;animation:modalIn .2s ease;">
    <div style="display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid #e2e8f0;">
      <div style="width:36px;height:36px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-receipt" style="color:#0284c7;font-size:15px;"></i>
      </div>
      <div>
        <div style="font-size:14px;font-weight:700;color:#1e293b;">Transaction Details</div>
      </div>
    </div>
    <div style="padding:22px 24px;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <tbody id="txnModalBody"></tbody>
      </table>
    </div>
    <div style="padding:12px 24px 18px;text-align:right;border-top:1px solid #f1f5f9;">
      <button onclick="closeTxnModal()" class="flt-btn flt-btn-reset" style="height:34px;"><i class="fas fa-times"></i> Close</button>
    </div>
  </div>
</div>
<style>
@keyframes modalIn{from{opacity:0;transform:translateY(-16px)}to{opacity:1;transform:none}}
#txnModalBody tr{border-bottom:1px solid #f1f5f9;}
#txnModalBody td{padding:9px 8px;vertical-align:top;}
#txnModalBody td:first-child{font-weight:700;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.4px;width:140px;white-space:nowrap;}
#txnModalBody td:last-child{color:#1e293b;font-weight:500;}
</style>
<script>
function openTxnModal(d){
  var rows=[
    ['Transaction ID', '<strong>'+d.id+'</strong>'],
    ['Customer Name',  d.customer],
    ['Transaction Type',d.type],
    ['Items / Service', d.items||'—'],
    ['Vehicle',        d.vehicle||'—'],
    ['Amount',         '<strong style="color:#002F70;font-size:15px;">'+d.amount+'</strong>'],
    ['Payment Method', d.payment],
    ['Shift',          d.shift||'—'],
    ['Staff Encoder',  d.staff],
    ['Payment Status', d.status],
    ['Date & Time',    d.date]
  ];
  var html='';
  rows.forEach(function(r){ html+='<tr><td>'+r[0]+'</td><td>'+r[1]+'</td></tr>'; });
  document.getElementById('txnModalBody').innerHTML=html;
  var m=document.getElementById('txnModal');
  m.style.display='flex';
}
function closeTxnModal(){
  document.getElementById('txnModal').style.display='none';
}
document.getElementById('txnModal').addEventListener('click',function(e){
  if(e.target===this) closeTxnModal();
});
</script>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
