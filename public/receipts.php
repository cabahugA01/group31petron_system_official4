<?php
$page_id = 'staff_receipts';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['staff','manager','admin','superadmin','owner'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: staff_dashboard.php'); exit;
}

// ── Column helpers ────────────────────────────────────────────────────────────
function rc_cols(PDO $pdo, string $t): array {
    try { $r=$pdo->query("SHOW COLUMNS FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC); $m=[]; foreach($r as $c) $m[strtolower($c['Field'])]=true; return $m; } catch(Exception $e){ return []; }
}
function rc_has(array $m, string $c): bool { return isset($m[strtolower($c)]); }

$mt_cols = rc_cols($pdo,'merchandise_transactions');
$jo_cols = rc_cols($pdo,'job_orders');

// ── Filters ───────────────────────────────────────────────────────────────────
$search    = trim($_GET['search']    ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$type_f    = trim($_GET['type']      ?? '');

// ── Exports ───────────────────────────────────────────────────────────────────
function rc_pay(array $r): string {
    $t=(float)($r['amount']??0); $p=isset($r['amount_paid'])?(float)$r['amount_paid']:null;
    if($p===null){ $pm=strtolower(trim($r['payment_method']??'')); return ($pm&&$pm!=='n/a')?'Paid':'Unpaid'; }
    if($p<=0) return 'Unpaid'; if($p<$t-0.01) return 'Partial'; return 'Paid';
}

// ── Fetch Merchandise Transactions ───────────────────────────────────────────
$mt_date  = rc_has($mt_cols,'transaction_date') ? "CASE WHEN mt.transaction_date>'2000-01-01' THEN mt.transaction_date ELSE mt.created_at END" : "mt.created_at";
$mt_paid  = rc_has($mt_cols,'amount_paid') ? 'mt.amount_paid' : 'NULL';
$mt_staff = rc_has($mt_cols,'staff_id') ? "COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '),u.username,'Unknown')" : "'Unknown'";
$mt_where = "WHERE mt.station_id=? "; $mt_p=[$station_id];
if ($search!=='') { $mt_where.=" AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?)"; $mt_p[]="%$search%"; $mt_p[]="%$search%"; }
if ($date_from!=='') { $mt_where.=" AND {$mt_date}>=?"; $mt_p[]=$date_from; }
if ($date_to!=='')   { $mt_where.=" AND {$mt_date}<=?"; $mt_p[]=$date_to;   }

$mt_rows=[];
try {
    $stmt=$pdo->prepare("SELECT mt.id AS row_id, mt.transaction_id AS txn_id,
        COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') AS customer,
        'Merchandise' AS entry_type,
        mt.total_amount AS amount,
        {$mt_paid} AS amount_paid,
        COALESCE(mt.payment_method,'Cash') AS payment_method,
        {$mt_date} AS txn_date,
        COALESCE(mt.validation_status,'Pending') AS validation_status,
        {$mt_staff} AS staff_name
    FROM merchandise_transactions mt
    LEFT JOIN users u ON u.id=mt.staff_id
    {$mt_where} ORDER BY txn_date DESC LIMIT 300");
    $stmt->execute($mt_p); $mt_rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) { $mt_rows=[]; }

// ── Fetch Job Orders ──────────────────────────────────────────────────────────
$jo_status = rc_has($jo_cols,'validation_status') ? 'jo.validation_status' : 'jo.status';
$jo_cost   = rc_has($jo_cols,'total_cost') ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';
$jo_paid   = rc_has($jo_cols,'amount_paid') ? 'jo.amount_paid' : 'NULL';
$jo_pay    = rc_has($jo_cols,'payment_method') ? "COALESCE(jo.payment_method,'N/A')" : "'N/A'";
$jo_where  = "WHERE jo.station_id=? "; $jo_p=[$station_id];
if ($search!=='') { $jo_where.=" AND (jo.customer_name LIKE ? OR jo.vehicle_plate LIKE ? OR jo.service_type LIKE ?)"; $jo_p[]="%$search%"; $jo_p[]="%$search%"; $jo_p[]="%$search%"; }
if ($date_from!=='') { $jo_where.=" AND jo.created_at>=?"; $jo_p[]=$date_from; }
if ($date_to!=='')   { $jo_where.=" AND jo.created_at<=?"; $jo_p[]=$date_to;   }

$jo_rows=[];
try {
    $stmt=$pdo->prepare("SELECT jo.id AS row_id, CONCAT('JO-',jo.id) AS txn_id,
        COALESCE(NULLIF(TRIM(jo.customer_name),''),'Walk-in') AS customer,
        'Job Order' AS entry_type,
        {$jo_cost} AS amount,
        {$jo_paid} AS amount_paid,
        {$jo_pay} AS payment_method,
        jo.created_at AS txn_date,
        COALESCE(NULLIF(TRIM({$jo_status}),''),'Pending') AS validation_status,
        COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '),u.username,'Unknown') AS staff_name
    FROM job_orders jo
    LEFT JOIN users u ON u.id=COALESCE(jo.created_by,jo.user_id)
    {$jo_where} ORDER BY jo.created_at DESC LIMIT 300");
    $stmt->execute($jo_p); $jo_rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) { $jo_rows=[]; }

// ── Merge & Filter ────────────────────────────────────────────────────────────
$all = array_merge($mt_rows,$jo_rows);
if ($type_f==='merchandise') $all=array_filter($all,fn($r)=>$r['entry_type']==='Merchandise');
elseif ($type_f==='job_order') $all=array_filter($all,fn($r)=>$r['entry_type']==='Job Order');
$rows=array_values($all);
usort($rows,fn($a,$b)=>strtotime($b['txn_date'])-strtotime($a['txn_date']));

// ── Summary Cards ─────────────────────────────────────────────────────────────
$total_amt=0.0; $merch_cnt=0; $jo_cnt=0; $paid_cnt=0; $unpaid_cnt=0;
foreach($rows as $r){
    $total_amt+=(float)($r['amount']??0);
    if($r['entry_type']==='Merchandise') $merch_cnt++; else $jo_cnt++;
    rc_pay($r)==='Paid' ? $paid_cnt++ : $unpaid_cnt++;
}

// ── CSV Export ────────────────────────────────────────────────────────────────
if(($_GET['export']??'')==='csv'){
    header('Content-Type:text/csv;charset=utf-8');
    header('Content-Disposition:attachment;filename="Receipts_'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','w');
    fputcsv($out,['Txn ID','Customer','Type','Amount','Payment Method','Payment Status','Validation Status','Date','Staff']);
    foreach($rows as $r) fputcsv($out,[$r['txn_id'],$r['customer'],$r['entry_type'],number_format((float)$r['amount'],2),$r['payment_method'],rc_pay($r),$r['validation_status'],date('M d, Y H:i',strtotime($r['txn_date'])),$r['staff_name']]);
    fclose($out); exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.int-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;margin-top:-12px!important}
.int-head h1{font-size:22px!important;font-weight:700!important;color:var(--petron-blue,#002F70)!important;margin:0!important;text-transform:uppercase!important;display:flex;align-items:center;gap:8px}
.int-head .sub{font-size:13px;color:#666;margin-top:4px;text-transform:none!important}
.txn-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:0 16px;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .15s;text-decoration:none;background:white!important}
.txn-btn.success{color:#1d6f42!important;border-color:#1d6f42!important}.txn-btn.success:hover{background:#1d6f42!important;color:white!important}
.txn-btn.primary{color:#002F70!important;border-color:#002F70!important}.txn-btn.primary:hover{background:#002F70!important;color:white!important}
.txn-btn.danger{color:#dc2626!important;border-color:#dc2626!important}.txn-btn.danger:hover{background:#dc2626!important;color:white!important}
.txn-btn.secondary{color:#4b5563!important;border-color:#6b7280!important}.txn-btn.secondary:hover{background:#6b7280!important;color:white!important}
.vt-filter-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.vt-flt-grp{display:flex;flex-direction:column;gap:4px}
.vt-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.vt-inp{height:36px;padding:0 12px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;box-sizing:border-box}
.vt-inp:focus{border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1)}
.vt-btn{display:inline-flex;align-items:center;gap:6px;padding:0 18px;height:36px;border:1px solid transparent;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;background:white!important}
.vt-btn-search{color:#002F70!important;border-color:#002F70!important}.vt-btn-search:hover{background:#002F70!important;color:#fff!important}
.vt-btn-reset{color:#4b5563!important;border-color:#6b7280!important}.vt-btn-reset:hover{background:#6b7280!important;color:#fff!important}
.vt-table{width:100%;border-collapse:collapse;font-size:11px}
.vt-table thead th{background:#002F70;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:9px 10px;border-bottom:2px solid #001a3d;text-align:left;vertical-align:middle}
.vt-table tbody td{padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;background:#fff;font-size:11px}
.vt-table tbody tr:hover td{background:#eff6ff}
.vt-badge{display:inline-block;padding:3px 10px;border-radius:4px;font-size:10px;font-weight:600;white-space:nowrap}
.vt-badge-merch{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.vt-badge-jo{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe}
.vt-badge-paid{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.vt-badge-partial{background:#fef3c7;color:#92400e;border:1px solid #fde047}
.vt-badge-unpaid{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.vt-badge-pending{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
.vt-badge-approved{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.vt-badge-voided{background:#f1f5f9;color:#6b7280;border:1px solid #d1d5db}
.rc-action-btn{display:inline-flex;align-items:center;gap:5px;height:30px;padding:0 10px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .15s;background:white!important;text-decoration:none}
.rc-btn-print{color:#002F70!important;border-color:#002F70!important}.rc-btn-print:hover{background:#002F70!important;color:#fff!important}
.rc-btn-jo{color:#7c3aed!important;border-color:#7c3aed!important}.rc-btn-jo:hover{background:#7c3aed!important;color:#fff!important}
.summary-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.summary-card-dark{background:linear-gradient(135deg,#002F70 0%,#003d8a 100%);border-radius:10px;padding:14px 16px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
</style>

<div class="int-head">
    <div>
        <h1><i class="fas fa-file-invoice"></i> Receipts</h1>
        <div class="sub">Search and reprint transaction receipts &mdash; merchandise and job orders.</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="txn-btn primary" title="Export CSV">
            <i class="fas fa-file-csv"></i> CSV
        </a>
        <a href="<?= in_array($role,['admin','superadmin']) ? 'admin_dashboard.php' : (($role==='manager') ? 'manager_dashboard.php' : 'staff_dashboard.php') ?>" class="txn-btn secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<?php if(isset($_SESSION['success'])): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>
<?php if(isset($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="vt-filter-card">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-search"></i> Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="vt-inp" placeholder="Txn ID, customer, plate..." style="width:220px;">
        </div>
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-tag"></i> Type</label>
            <select name="type" class="vt-inp" style="width:160px;">
                <option value="" <?= $type_f==='' ? 'selected':'' ?>>All Types</option>
                <option value="merchandise" <?= $type_f==='merchandise' ? 'selected':'' ?>>Merchandise</option>
                <option value="job_order" <?= $type_f==='job_order' ? 'selected':'' ?>>Job Order</option>
            </select>
        </div>
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-calendar"></i> From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="vt-inp">
        </div>
        <div class="vt-flt-grp">
            <label class="vt-lbl"><i class="fas fa-calendar"></i> To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="vt-inp">
        </div>
        <div style="align-self:flex-end;display:flex;gap:8px;">
            <button type="submit" class="vt-btn vt-btn-search"><i class="fas fa-search"></i> Filter</button>
            <a href="?" class="vt-btn vt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:18px;">
    <div class="summary-card">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;"><i class="fas fa-receipt" style="margin-right:5px;"></i>Total</div>
        <div style="font-size:26px;font-weight:800;color:#002F70;line-height:1.1;margin-top:4px;"><?= count($rows) ?></div>
    </div>
    <div class="summary-card">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;"><i class="fas fa-box" style="margin-right:5px;"></i>Merchandise</div>
        <div style="font-size:26px;font-weight:800;color:#1d4ed8;line-height:1.1;margin-top:4px;"><?= $merch_cnt ?></div>
    </div>
    <div class="summary-card">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;"><i class="fas fa-tools" style="margin-right:5px;"></i>Job Orders</div>
        <div style="font-size:26px;font-weight:800;color:#7c3aed;line-height:1.1;margin-top:4px;"><?= $jo_cnt ?></div>
    </div>
    <div class="summary-card">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;"><i class="fas fa-check-circle" style="margin-right:5px;"></i>Paid</div>
        <div style="font-size:26px;font-weight:800;color:#16a34a;line-height:1.1;margin-top:4px;"><?= $paid_cnt ?></div>
    </div>
    <div class="summary-card">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;"><i class="fas fa-clock" style="margin-right:5px;"></i>Unpaid/Partial</div>
        <div style="font-size:26px;font-weight:800;color:#dc2626;line-height:1.1;margin-top:4px;"><?= $unpaid_cnt ?></div>
    </div>
    <div class="summary-card-dark">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#93c5fd;"><i class="fas fa-peso-sign" style="margin-right:5px;"></i>Total Amount</div>
        <div style="font-size:20px;font-weight:800;color:#fff;line-height:1.1;margin-top:4px;white-space:nowrap;">&#8369;<?= number_format($total_amt,2) ?></div>
    </div>
</div>

<!-- Table -->
<div class="card" style="padding:0;overflow:hidden;">
    <table class="vt-table">
        <thead>
            <tr>
                <th>Txn ID</th>
                <th>Customer</th>
                <th>Type</th>
                <th style="text-align:right;">Amount</th>
                <th>Payment</th>
                <th>Pay Status</th>
                <th>Validation</th>
                <th>Date &amp; Time</th>
                <th>Encoded By</th>
                <th style="text-align:center;">Receipt</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($rows)): ?>
            <tr><td colspan="10" style="text-align:center;padding:40px;color:#94a3b8;">
                <i class="fas fa-file-invoice" style="font-size:32px;display:block;margin-bottom:10px;opacity:.4;"></i>
                No receipts found. Adjust your filters and try again.
            </td></tr>
        <?php else: foreach($rows as $r):
            $pay_st = rc_pay($r);
            $vs     = strtolower(trim($r['validation_status']??''));
            $is_jo  = ($r['entry_type']==='Job Order');
            if($is_jo){
                $rid = str_replace('JO-','',$r['txn_id']);
                $receipt_url = "receipt.php?id=".urlencode($rid)."&type=job_order";
            } else {
                $receipt_url = "receipt.php?id=".urlencode($r['txn_id'])."&type=merchandise";
            }
            $vs_class = match(true){
                in_array($vs,['approved','official','completed','validated','adjusted']) => 'vt-badge-approved',
                in_array($vs,['voided','cancelled','rejected']) => 'vt-badge-voided',
                default => 'vt-badge-pending'
            };
            $pay_class = match($pay_st){ 'Paid'=>'vt-badge-paid', 'Partial'=>'vt-badge-partial', default=>'vt-badge-unpaid' };
        ?>
            <tr>
                <td><code style="font-size:10px;color:#002F70;"><?= htmlspecialchars($r['txn_id']) ?></code></td>
                <td><?= htmlspecialchars($r['customer']) ?></td>
                <td><span class="vt-badge <?= $is_jo ? 'vt-badge-jo' : 'vt-badge-merch' ?>"><?= htmlspecialchars($r['entry_type']) ?></span></td>
                <td style="text-align:right;font-weight:700;color:#002F70;">&#8369;<?= number_format((float)$r['amount'],2) ?></td>
                <td><?= htmlspecialchars($r['payment_method']) ?></td>
                <td><span class="vt-badge <?= $pay_class ?>"><?= $pay_st ?></span></td>
                <td><span class="vt-badge <?= $vs_class ?>"><?= htmlspecialchars(ucfirst($r['validation_status'])) ?></span></td>
                <td style="white-space:nowrap;"><?= date('M d, Y H:i',strtotime($r['txn_date'])) ?></td>
                <td><?= htmlspecialchars($r['staff_name']) ?></td>
                <td style="text-align:center;">
                    <a href="<?= htmlspecialchars($receipt_url) ?>" target="_blank" rel="noopener"
                       class="rc-action-btn <?= $is_jo ? 'rc-btn-jo' : 'rc-btn-print' ?>" title="Open Receipt">
                        <i class="fas fa-external-link-alt"></i> View
                    </a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <?php if(!empty($rows)): ?>
        <tfoot>
            <tr style="background:#f0f7ff;">
                <td colspan="3" style="font-weight:800;padding:10px;color:#002F70;">TOTAL (<?= count($rows) ?> records)</td>
                <td style="text-align:right;font-weight:800;color:#002F70;padding:10px;">&#8369;<?= number_format($total_amt,2) ?></td>
                <td colspan="6"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
