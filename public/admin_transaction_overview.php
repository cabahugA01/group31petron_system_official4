<?php
$page_id = 'admin_transaction_overview';
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

// ── Date range ────────────────────────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

// ── Helper: detect columns ────────────────────────────────────────────────────
function atov_cols(PDO $p, string $t): array {
    try { $r=[]; foreach($p->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $c) $r[strtolower($c['Field'])]=true; return $r; }
    catch(Exception $e){ return []; }
}
function atov_has(array $m, string $c): bool { return isset($m[strtolower($c)]); }

$mt_cols = atov_cols($pdo,'merchandise_transactions');
$jo_cols = atov_cols($pdo,'job_orders');

$mt_date = atov_has($mt_cols,'transaction_date')
    ? "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END"
    : 'mt.created_at';
$mt_stat = atov_has($mt_cols,'validation_status') ? 'mt.validation_status' : "'Approved'";
$jo_stat = atov_has($jo_cols,'validation_status') ? 'jo.validation_status' : 'jo.status';
$jo_cost = atov_has($jo_cols,'total_cost') ? 'COALESCE(jo.total_cost,0)' : 'COALESCE(jo.estimated_cost,0)';

// ── KPI: Total Transactions ───────────────────────────────────────────────────
$kpi_total = 0; $kpi_sales = 0.0;
$kpi_jo = 0;    $kpi_merch = 0;

try {
    $s = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(mt.total_amount),0) as total
        FROM merchandise_transactions mt
        WHERE mt.station_id=? AND DATE($mt_date) BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE($mt_stat,''))) IN ('approved','completed','adjusted','official','validated')");
    $s->execute([$station_id,$date_from,$date_to]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    $kpi_total += (int)$r['cnt'];
    $kpi_sales += (float)$r['total'];
    // Count types
    $s2 = $pdo->prepare("SELECT
        SUM(CASE WHEN COALESCE(transaction_type,'merchandise')='job_order' OR COALESCE(transaction_type,'')='combined' THEN 1 ELSE 0 END) as jo_cnt,
        SUM(CASE WHEN COALESCE(transaction_type,'merchandise')='merchandise' THEN 1 ELSE 0 END) as m_cnt
        FROM merchandise_transactions WHERE station_id=? AND DATE($mt_date) BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE($mt_stat,''))) IN ('approved','completed','adjusted','official','validated')");
    $s2->execute([$station_id,$date_from,$date_to]);
    $r2 = $s2->fetch(PDO::FETCH_ASSOC);
    $kpi_jo    = (int)($r2['jo_cnt'] ?? 0);
    $kpi_merch = (int)($r2['m_cnt'] ?? 0);
} catch(Exception $e){}

try {
    $s = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM($jo_cost),0) as total
        FROM job_orders jo WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE($jo_stat,''))) IN ('approved','completed','in progress')");
    $s->execute([$station_id,$date_from,$date_to]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    $kpi_total += (int)$r['cnt'];
    $kpi_sales += (float)$r['total'];
    $kpi_jo    += (int)$r['cnt'];
} catch(Exception $e){}

// ── Chart: Transaction Type Distribution ──────────────────────────────────────
$chart_types = ['Job Order'=>$kpi_jo, 'Merchandise'=>$kpi_merch, 'Combined'=>0];
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions mt
        WHERE station_id=? AND DATE($mt_date) BETWEEN ? AND ?
          AND COALESCE(transaction_type,'merchandise')='combined'
          AND LOWER(TRIM(COALESCE($mt_stat,''))) IN ('approved','completed','adjusted','official','validated')");
    $s->execute([$station_id,$date_from,$date_to]);
    $chart_types['Combined'] = (int)$s->fetchColumn();
} catch(Exception $e){}

// ── Chart: Payment Method Distribution ───────────────────────────────────────
$chart_pay = [];
try {
    $s = $pdo->prepare("SELECT COALESCE(payment_method,'Cash') as pm, COUNT(*) as cnt
        FROM merchandise_transactions mt WHERE station_id=? AND DATE($mt_date) BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE($mt_stat,''))) IN ('approved','completed','adjusted','official','validated')
        GROUP BY pm");
    $s->execute([$station_id,$date_from,$date_to]);
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $chart_pay[$r['pm']] = ($chart_pay[$r['pm']] ?? 0) + (int)$r['cnt'];
    }
} catch(Exception $e){}
$pay_methods = ['Cash','Card','E-Wallet','Petron E-Fuel','Fleet Card','Credit'];
foreach($pay_methods as $pm) { if(!isset($chart_pay[$pm])) $chart_pay[$pm]=0; }

// ── Chart: Transactions Per Shift ─────────────────────────────────────────────
$chart_shift = ['Shift 1'=>0,'Shift 2'=>0];
try {
    if(atov_has($mt_cols,'shift')) {
        $s = $pdo->prepare("SELECT COALESCE(shift,'Shift 1') as sh, COUNT(*) as cnt
            FROM merchandise_transactions mt WHERE station_id=? AND DATE($mt_date) BETWEEN ? AND ?
              AND LOWER(TRIM(COALESCE($mt_stat,''))) IN ('approved','completed','adjusted','official','validated')
            GROUP BY sh");
        $s->execute([$station_id,$date_from,$date_to]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $chart_shift[$r['sh']] = (int)$r['cnt'];
        }
    }
} catch(Exception $e){}

// ── Recent Transactions (last 20) ─────────────────────────────────────────────
$recent = [];
try {
    $shift_col = atov_has($mt_cols,'shift') ? 'mt.shift' : "'N/A'";
    $s = $pdo->prepare("SELECT mt.transaction_id, COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') as customer,
        COALESCE(mt.transaction_type,'Merchandise') as txn_type,
        mt.total_amount as amount, $shift_col as shift, $mt_date as txn_date
        FROM merchandise_transactions mt
        WHERE mt.station_id=? AND DATE($mt_date) BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE($mt_stat,''))) IN ('approved','completed','adjusted','official','validated')
        ORDER BY $mt_date DESC LIMIT 20");
    $s->execute([$station_id,$date_from,$date_to]);
    $recent = $s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// ── Export ────────────────────────────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if(in_array($export,['excel','csv'])) {
    if($export==='excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="transaction_overview_'.date('Ymd').'.xls"');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="transaction_overview_'.date('Ymd_His').'.csv"');
    }
    $out=fopen('php://output','w');
    fputcsv($out,['Transaction ID','Customer','Type','Amount','Shift','Date']);
    foreach($recent as $r) fputcsv($out,[$r['transaction_id'],$r['customer'],ucwords(str_replace('_',' ',$r['txn_type'])),'₱'.number_format($r['amount'],2),$r['shift'],date('M d, Y H:i',strtotime($r['txn_date']))]);
    fclose($out); exit;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.page-head.txn-page-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }
.page-head.txn-page-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:none !important; display:flex; align-items:center; gap:8px; }
.page-head.txn-page-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; font-weight:400 !important; }
.flt-btn { display:inline-flex; align-items:center; gap:6px; padding:0 16px; height:36px; border-radius:7px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; white-space:nowrap; transition:all .15s; background:white !important; border:1px solid transparent; }
.flt-btn-reset  { color:#6b7280 !important; border-color:#6b7280 !important; }
.flt-btn-reset:hover  { background:#6b7280 !important; color:#fff !important; }
.flt-btn-excel  { color:#1d6f42 !important; border-color:#1d6f42 !important; }
.flt-btn-excel:hover  { background:#1d6f42 !important; color:#fff !important; }
.flt-btn-search { color:#00264D !important; border-color:#00264D !important; }
.flt-btn-search:hover { background:#00264D !important; color:#fff !important; }
.flt-btn-pdf    { color:#dc2626 !important; border-color:#dc2626 !important; }
.flt-btn-pdf:hover    { background:#dc2626 !important; color:#fff !important; }
.flt-btn-solid-primary { color:#fff !important; background:#002F70 !important; border-color:#002F70 !important; }
.txn-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:20px; }
.txn-kpi-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; box-shadow:0 1px 4px rgba(0,0,0,.05); transition:transform .15s,box-shadow .15s; }
.txn-kpi-card:hover { transform:translateY(-2px); box-shadow:0 4px 10px rgba(0,0,0,.09); }
.txn-kpi-lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#64748b; margin-bottom:6px; display:flex; align-items:center; gap:6px; }
.txn-kpi-val { font-size:26px; font-weight:800; color:#002F70; line-height:1.1; }
.txn-kpi-card.total-amount-card { background:linear-gradient(135deg,#003d7a 0%,#00264D 100%); }
.txn-kpi-card.total-amount-card .txn-kpi-lbl { color:#93c5fd; }
.txn-kpi-card.total-amount-card .txn-kpi-val { color:#fff; }
.charts-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:16px; margin-bottom:20px; }
.chart-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.chart-title { font-size:13px; font-weight:700; color:#00264D; margin-bottom:12px; display:flex; align-items:center; gap:6px; }
.card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.card-head { display:flex; align-items:center; justify-content:space-between; padding:13px 16px; border-bottom:1px solid #e9ecef; background:#f8fafc; }
.card-title { font-size:13px; font-weight:700; color:#00264D; }
.ov-table { width:100%; border-collapse:collapse; font-size:12px; }
.ov-table thead tr { background:#002F70; }
.ov-table th { padding:9px 12px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; }
.ov-table tbody tr { border-bottom:1px solid #f1f5f9; }
.ov-table tbody tr:hover td { background:#eff6ff; }
.ov-table tbody td { padding:9px 12px; color:#334155; font-size:12px; background:#fff; }
.filters { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:18px; }
.filters > div { display:flex; flex-direction:column; gap:3px; }
.filters label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.filters .input { height:36px; padding:0 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; color:#1e293b; background:#fff; outline:none; }
.filters .input:focus { border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700; text-transform:uppercase; }
.badge-green { background:#dcfce7; color:#166534; }
.badge-blue  { background:#dbeafe; color:#1e40af; }
.badge-orange { background:#fff7ed; color:#9a3412; }
</style>

<div class="page-head txn-page-head">
    <div>
        <h1 class="h1"><i class="fas fa-chart-line"></i> Transaction Overview</h1>
        <div class="sub">Executive dashboard — transaction summaries, charts, and recent activity across the station.</div>
    </div>
    <div class="actions txn-head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a href="admin_dashboard.php" class="flt-btn flt-btn-reset"><i class="fas fa-arrow-left"></i> Back</a>
        <a href="?date_from=<?=urlencode($date_from)?>&date_to=<?=urlencode($date_to)?>&export=excel" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="?date_from=<?=urlencode($date_from)?>&date_to=<?=urlencode($date_to)?>&export=csv"   class="flt-btn flt-btn-search"><i class="fas fa-file-csv"></i> CSV</a>
        <button class="flt-btn flt-btn-pdf" onclick="window.print()"><i class="fas fa-file-pdf"></i> PDF</button>
    </div>
</div>

<!-- Date Filter -->
<form method="get" class="filters" style="margin-bottom:18px;">
    <div>
        <label>From</label>
        <input type="date" name="date_from" value="<?=htmlspecialchars($date_from)?>" class="input">
    </div>
    <div>
        <label>To</label>
        <input type="date" name="date_to" value="<?=htmlspecialchars($date_to)?>" class="input">
    </div>
    <div>
        <button type="submit" class="flt-btn flt-btn-solid-primary" style="height:36px;"><i class="fas fa-filter"></i> Apply</button>
    </div>
    <div>
        <a href="admin_transaction_overview.php" class="flt-btn flt-btn-reset" style="height:36px;"><i class="fas fa-rotate-left"></i> Reset</a>
    </div>
</form>

<!-- KPI Cards -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-receipt"></i> Total Transactions</div>
        <div class="txn-kpi-val"><?=number_format($kpi_total)?></div>
    </div>
    <div class="txn-kpi-card total-amount-card">
        <div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Sales</div>
        <div class="txn-kpi-val">₱<?=number_format($kpi_sales,2)?></div>
    </div>
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-wrench"></i> Total Job Orders</div>
        <div class="txn-kpi-val"><?=number_format($kpi_jo)?></div>
    </div>
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-box"></i> Merchandise Transactions</div>
        <div class="txn-kpi-val"><?=number_format($kpi_merch)?></div>
    </div>
</div>

<!-- Charts -->
<div class="charts-grid">
    <!-- Type Distribution -->
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-chart-pie"></i> Transaction Type Distribution</div>
        <canvas id="chartTypes" height="200"></canvas>
    </div>
    <!-- Payment Method Distribution -->
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-wallet"></i> Payment Method Distribution</div>
        <canvas id="chartPay" height="200"></canvas>
    </div>
    <!-- Transactions Per Shift -->
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-clock"></i> Transactions Per Shift</div>
        <canvas id="chartShift" height="200"></canvas>
    </div>
</div>

<!-- Recent Transactions -->
<div class="card">
    <div class="card-head">
        <div class="card-title"><i class="fas fa-history" style="margin-right:6px;"></i>Recent Transactions</div>
        <a href="admin_all_transactions.php" class="flt-btn flt-btn-search" style="height:28px;font-size:11px;padding:0 10px;">View All <i class="fas fa-arrow-right"></i></a>
    </div>
    <table class="ov-table">
        <thead>
            <tr>
                <th>Transaction ID</th>
                <th>Customer Name</th>
                <th>Transaction Type</th>
                <th>Amount</th>
                <th>Shift</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($recent)): ?>
            <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No transactions found</td></tr>
            <?php else: ?>
            <?php foreach($recent as $r): ?>
            <tr>
                <td><strong><?=htmlspecialchars($r['transaction_id'])?></strong></td>
                <td><?=htmlspecialchars($r['customer'])?></td>
                <td>
                    <?php $t=strtolower($r['txn_type']??'');
                    if(str_contains($t,'job')) echo '<span class="badge badge-orange">Job Order</span>';
                    elseif(str_contains($t,'merch')) echo '<span class="badge badge-blue">Merchandise</span>';
                    else echo '<span class="badge badge-green">'.htmlspecialchars(ucwords(str_replace('_',' ',$r['txn_type']))).'</span>'; ?>
                </td>
                <td style="font-weight:700;">₱<?=number_format($r['amount'],2)?></td>
                <td><?=htmlspecialchars($r['shift'])?></td>
                <td><?=date('M d, Y h:i A',strtotime($r['txn_date']))?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const typeData  = <?=json_encode(array_values($chart_types))?>;
const typeLabels= <?=json_encode(array_keys($chart_types))?>;
const payData   = <?=json_encode(array_map(fn($pm) => $chart_pay[$pm] ?? 0, $pay_methods))?>;
const payLabels = <?=json_encode($pay_methods)?>;
const shiftData = <?=json_encode(array_values($chart_shift))?>;
const shiftLabels=<?=json_encode(array_keys($chart_shift))?>;

const palette = ['#002F70','#0369a1','#16a34a','#ea580c','#dc2626','#7c3aed'];

new Chart(document.getElementById('chartTypes'),{
    type:'doughnut',
    data:{ labels:typeLabels, datasets:[{ data:typeData, backgroundColor:palette, borderWidth:2, borderColor:'#fff' }] },
    options:{ plugins:{ legend:{ position:'bottom', labels:{ font:{size:11} } } }, cutout:'60%' }
});
new Chart(document.getElementById('chartPay'),{
    type:'bar',
    data:{ labels:payLabels, datasets:[{ data:payData, backgroundColor:palette, borderRadius:6 }] },
    options:{ plugins:{ legend:{ display:false } }, scales:{ x:{ ticks:{font:{size:10}} }, y:{ beginAtZero:true, ticks:{font:{size:10}} } } }
});
new Chart(document.getElementById('chartShift'),{
    type:'bar',
    data:{ labels:shiftLabels, datasets:[{ label:'Transactions', data:shiftData, backgroundColor:['#002F70','#0369a1'], borderRadius:6 }] },
    options:{ plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{font:{size:10}} } } }
});
</script>

<?php
$pay_methods = ['Cash','Card','E-Wallet','Petron E-Fuel','Fleet Card','Credit'];
require_once __DIR__ . '/../partials/footer.php';
?>
