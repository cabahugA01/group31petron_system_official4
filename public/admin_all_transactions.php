<?php
$page_id = 'admin_all_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me           = current_user();
$role         = role_key($me['role'] ?? '');
$station_id   = (int) user_station_id();
$station_name = user_station_name();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

// Fetch station location for print header
$station_location = '';
try {
    $sl = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(address),''), NULLIF(TRIM(location),''), '') as loc FROM stations WHERE id=? LIMIT 1");
    $sl->execute([$station_id]);
    $station_location = (string)($sl->fetchColumn() ?? '');
} catch(Exception $e) { $station_location = ''; }

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
$void_reason_col = aat_has($mt_cols,'void_reason') ? 'mt.void_reason' : 'NULL';
$adj_reason_col = aat_has($mt_cols,'adjustment_reason') ? 'mt.adjustment_reason' : 'NULL';
$mgr_remarks_col = aat_has($mt_cols,'manager_remarks') ? 'mt.manager_remarks' : 'NULL';

// ── Filters ───────────────────────────────────────────────────────────────────
// DEFAULT: Show last 365 days (1 year) to ensure we catch all historical staff transactions
$date_from  = trim($_GET['date_from']       ?? date('Y-m-d', strtotime('-365 days')));
$date_to    = trim($_GET['date_to']         ?? date('Y-m-d'));
$f_shift    = trim($_GET['shift']           ?? '');
$f_staff    = trim($_GET['staff']           ?? '');
$f_type     = trim($_GET['type']            ?? '');
$f_pay      = trim($_GET['payment_method']  ?? '');
$f_status   = trim($_GET['status']          ?? '');
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
    // Search OR No. (derived from txn_db_id), transaction_id, customer_name, and vehicle_plate
    // OR No. format: OR-YYYY-XXXXXX — extract numeric part for matching
    $orSearchNum = null;
    if(preg_match('/OR-\d{4}-(\d+)/i', $search, $orMatch)) {
        $orSearchNum = (int)$orMatch[1]; // the zero-padded number
    }
    $veh_search = aat_has($mt_cols,'vehicle_plate') ? ' OR mt.vehicle_plate LIKE ?' : '';
    if($orSearchNum !== null) {
        $where.=" AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ? OR mt.id=?$veh_search)";
        $params[]="%$search%"; $params[]="%$search%"; $params[]=$orSearchNum;
    } else {
        $where.=" AND (mt.customer_name LIKE ? OR mt.transaction_id LIKE ?$veh_search)";
        $params[]="%$search%"; $params[]="%$search%";
    }
    if(aat_has($mt_cols,'vehicle_plate')) $params[]="%$search%";
}
// Shift filter: use raw shift_period column, not a CASE expression (CASE cannot be used in WHERE)
$shift_col = aat_has($mt_cols,'shift_period') ? 'mt.shift_period' : (aat_has($mt_cols,'shift_name') ? 'mt.shift_name' : null);
if($f_shift!=='' && $shift_col) { $where.=" AND COALESCE($shift_col,'')=?"; $params[]=$f_shift; }
if($f_staff!=='') { $where.=" AND mt.staff_id=?"; $params[]=$f_staff; }
if($f_type==='merchandise') { $where.=" AND COALESCE(mt.transaction_type,'merchandise')='merchandise'"; }
elseif($f_type==='job_order') { $where.=" AND COALESCE(mt.transaction_type,'merchandise')='job_order'"; }
elseif($f_type==='combined') { $where.=" AND COALESCE(mt.transaction_type,'merchandise')='combined'"; }
if($f_pay!=='') { $where.=" AND LOWER(TRIM($mt_pay))=LOWER(?)"; $params[]=$f_pay; }
if($f_status==='Completed') {
    $where.=" AND (COALESCE($mt_stat, '') NOT IN ('Voided', 'Adjusted'))";
} elseif($f_status==='Voided') {
    $where.=" AND ($mt_stat='Voided')";
} elseif($f_status==='Adjusted') {
    $where.=" AND ($mt_stat='Adjusted')";
}

// ── KPIs — always reflect the active filter (same WHERE as the table) ──────────
$kpi_txn_count=0; $kpi_total_sales=0.0; $kpi_merch_count=0; $kpi_jo_count=0;
try {
    $kpi_sql = "SELECT
        COUNT(mt.id) as c,
        COALESCE(SUM(mt.total_amount),0) as s,
        SUM(CASE WHEN COALESCE(LOWER(mt.transaction_type),'merchandise')='merchandise' THEN 1 ELSE 0 END) as merch_cnt,
        SUM(CASE WHEN COALESCE(LOWER(mt.transaction_type),'merchandise') IN ('job_order','combined') THEN 1 ELSE 0 END) as jo_cnt
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id=mt.staff_id
        $where";
    $ks = $pdo->prepare($kpi_sql);
    $ks->execute($params);
    $kr = $ks->fetch(PDO::FETCH_ASSOC);
    $kpi_txn_count   = (int)($kr['c'] ?? 0);
    $kpi_total_sales = (float)($kr['s'] ?? 0);
    $kpi_merch_count = (int)($kr['merch_cnt'] ?? 0);
    $kpi_jo_count    = (int)($kr['jo_cnt'] ?? 0);
} catch(Exception $e){}

// ── Items Format Helper ───────────────────────────────────────────────────────
function format_transaction_items($raw_items_str, $htmlMode = true) {
    $raw = trim($raw_items_str ?? '');
    if ($raw === '' || $raw === '—') return '—';
    // Determine the unit label based on product name
    $resolveUnit = function(string $nameLower, float $qty): string {
        if (strpos($nameLower, 'refrigerant') !== false || strpos($nameLower, 'r134a') !== false)
            return $qty > 1 ? 'Cans' : 'Can';
        if (strpos($nameLower, 'oil') !== false || strpos($nameLower, 'coolant') !== false ||
            strpos($nameLower, 'fluid') !== false || strpos($nameLower, 'cleaning') !== false ||
            strpos($nameLower, 'cleaner') !== false || strpos($nameLower, 'lubricant') !== false)
            return $qty > 1 ? 'Bottles' : 'Bottle';
        if (strpos($nameLower, 'liter') !== false || strpos($nameLower, 'litre') !== false)
            return $qty > 1 ? 'Liters' : 'Liter';
        if (strpos($nameLower, 'tire') !== false || strpos($nameLower, 'tyre') !== false)
            return $qty > 1 ? 'pcs' : 'pc';
        return $qty > 1 ? 'pcs' : 'pc';
    };
    $parts = explode('||', $raw);
    $formatted = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $subparts = explode('::', $part);
        if (count($subparts) >= 3) {
            $name    = trim($subparts[0]);
            $variant = trim($subparts[1]);
            $qtyVal  = (float)($subparts[2]);
            $qtyNum  = ($qtyVal == (int)$qtyVal) ? (int)$qtyVal : number_format($qtyVal, 2);
            $unit    = $resolveUnit(strtolower($name), $qtyVal);
            $variantStr = ($variant !== '') ? ' [' . $variant . ']' : '';
            if ($htmlMode) {
                $formatted[] = '<strong>' . htmlspecialchars($name . $variantStr) . '</strong><br>'
                    . '<span style="color:#64748b;font-size:10px;">Qty: ' . $qtyNum . ' ' . $unit . '</span>';
            } else {
                $formatted[] = $name . $variantStr . ' x ' . $qtyNum . ' ' . $unit;
            }
        } else {
            // Fallback: plain text (no structured data)
            if ($htmlMode) {
                $formatted[] = htmlspecialchars($part);
            } else {
                $formatted[] = $part;
            }
        }
    }
    if (empty($formatted)) return '—';
    return implode($htmlMode ? '<br><br>' : '; ', $formatted);
}

// ── Fetch rows ────────────────────────────────────────────────────────────────
$rows=[];
$veh_col = aat_has($mt_cols,'vehicle_plate') ? 'COALESCE(mt.vehicle_plate,"—")' : '"—"';
$staff_col = "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '),u.username,'Unknown')";
try {
    $s=$pdo->prepare("SELECT mt.id as txn_db_id, mt.transaction_id, COALESCE(NULLIF(TRIM(mt.customer_name),''),'Walk-in') as customer,
        COALESCE(mt.transaction_type,'Merchandise') as txn_type,
        $veh_col as vehicle,
        mt.total_amount as amount, $mt_pay as payment_method,
        $mt_shift as shift, $staff_col as staff_name,
        $mt_pstat as payment_status, $mt_date as txn_date,
        $mt_stat as validation_status,
        $void_reason_col as void_reason,
        $adj_reason_col as adjustment_reason,
        $mgr_remarks_col as manager_remarks,
        GROUP_CONCAT(CONCAT(mti.product_name, '::', COALESCE(mti.size_variant,''), '::', mti.quantity) ORDER BY mti.id SEPARATOR '||') as items,
        COALESCE(NULLIF(TRIM(mt.job_order_service),''), '') as service_type
        FROM merchandise_transactions mt
        LEFT JOIN users u ON u.id=mt.staff_id
        LEFT JOIN merchandise_transaction_items mti ON mti.transaction_id=mt.id AND COALESCE(mti.item_type,'') != 'service' AND COALESCE(mti.category,'') NOT LIKE '%Service%'
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
    fputcsv($out,['OR No.','Transaction ID','Customer','Type','Products','Service Type','Vehicle','Amount','Payment Method','Shift','Staff Encoder','Status','Date']);
    foreach($rows as $r) {
        $t=strtolower($r['txn_type']??'');
        $has_items   = !empty(trim($r['items'] ?? ''));
        $has_service = !empty(trim($r['service_type'] ?? ''));
        if ($has_items && $has_service) {
            $tLabel = 'Job Order + Merchandise';
        } elseif ($t === 'combined') {
            $tLabel = 'Job Order + Merchandise';
        } elseif ($t === 'job_order' || $has_service) {
            $tLabel = 'Job Order';
        } else {
            $tLabel = 'Merchandise';
        }
        $vs=strtolower($r['validation_status']??'');
        $statusLabel = ($vs === 'voided') ? 'Voided' : (($vs === 'adjusted') ? 'Adjusted' : 'Completed');
        
        $or_no = 'OR-' . date('Y', strtotime($r['txn_date'])) . '-' . str_pad($r['txn_db_id'], 6, '0', STR_PAD_LEFT);
        $exportItems = format_transaction_items($r['items'], false);
        
        $veh = trim($r['vehicle'] ?? '');
        $exportVeh = ($veh === '' || $veh === '—') ? 'N/A' : $veh;
        
        fputcsv($out,[
            $or_no,
            $r['transaction_id'],
            $r['customer'],
            $tLabel,
            $exportItems,
            $r['service_type'] ?: '—',
            $exportVeh,
            '₱'.number_format($r['amount'],2),
            $r['payment_method'],
            $r['shift'],
            $r['staff_name'],
            $statusLabel,
            date('M d, Y H:i',strtotime($r['txn_date']))
        ]);
    }
    fclose($out); exit;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>

.flt-btn{display:inline-flex;align-items:center;gap:6px;padding:0 16px;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;background:white !important;border:1px solid transparent;}
.flt-btn-reset{color:#6b7280 !important;border-color:#6b7280 !important;} .flt-btn-reset:hover{background:#6b7280 !important;color:#fff !important;}
.flt-btn-excel{color:#1d6f42 !important;border-color:#1d6f42 !important;} .flt-btn-excel:hover{background:#1d6f42 !important;color:#fff !important;}
.flt-btn-search{color:#00264D !important;border-color:#00264D !important;} .flt-btn-search:hover{background:#00264D !important;color:#fff !important;}
.flt-btn-pdf{color:#dc2626 !important;border-color:#dc2626 !important;} .flt-btn-pdf:hover{background:#dc2626 !important;color:#fff !important;}
.flt-btn-solid-primary{color:#fff !important;background:#002F70 !important;border-color:#002F70 !important;}
.txn-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px;}
.txn-kpi-card{background:transparent;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;box-shadow:none;transition:transform .15s,box-shadow .15s;}
.txn-kpi-card:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(0,0,0,.09);}
.txn-kpi-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.txn-kpi-val{font-size:26px;font-weight:800;color:#002F70;line-height:1.1;}
.txn-kpi-card.total-amount-card{}
.txn-kpi-card.total-amount-card .txn-kpi-lbl{color:#64748b;} .txn-kpi-card.total-amount-card .txn-kpi-val{color:#002F70;}
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
.badge-red{background:#fee2e2;color:#991b1b;} .badge-purple{background:#f3e8ff;color:#6b21a8;}
.modal-section-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#002F70;background:#f0f7ff;padding:6px 12px;margin:0;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;}
.stock-page{padding-top:0;}

/* ═══════════════════════════════════════════════════════════
   PRINT STYLES — matches admin_reports.php clean-room output
   ═══════════════════════════════════════════════════════════ */
@media print {
    @page { size: A4 portrait; margin: 0.3in 0.4in; }

    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

    html, body {
        background: white !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        font-family: Arial, sans-serif !important;
    }

    /* Hide EVERYTHING — only .rpt-printable is shown */
    body > * { display: none !important; }
    .rpt-printable { display: block !important; }

    /* Centered print header */
    .txn-print-header {
        display: block !important;
        text-align: center;
        padding: 20px 0 14px;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 16px;
    }

    /* Table — clean plain style matching sr-table */
    .t {
        width: 100% !important;
        border-collapse: collapse !important;
        table-layout: auto !important;
        font-size: 9.5px !important;
        page-break-inside: auto !important;
    }
    .t thead { display: table-header-group !important; }
    .t tfoot { display: table-footer-group !important; }
    .t thead tr {
        border-top: 2px solid #00264D !important;
        border-bottom: 1px solid #e2e8f0 !important;
        background: #f8f9fa !important;
    }
    .t thead th {
        padding: 7px 6px !important;
        font-size: 8.8px !important;
        color: #00264D !important;
        text-align: left !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        border-bottom: 1px solid #000 !important;
        white-space: nowrap !important;
    }
    .t tbody td {
        padding: 6px !important;
        font-size: 9.5px !important;
        border-bottom: 1px solid #ddd !important;
        vertical-align: top !important;
        white-space: normal !important;
        word-break: break-word !important;
    }
    .t tbody tr:hover td { background: #fff !important; }
    .t tr {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }
    /* Badges — strip color, show text only */
    .badge {
        background: transparent !important;
        color: #000 !important;
        padding: 0 !important;
        font-size: 9px !important;
        border-radius: 0 !important;
        font-weight: 700 !important;
    }
    /* Icons inside badges and anywhere — hide completely */
    .badge i, i.fas, i.far, i.fab { display: none !important; }
    /* Actions column — hide */
    .t th:last-child, .t td:last-child { display: none !important; }
    /* Overflow containers — no scroll */
    .card { border: none !important; box-shadow: none !important; overflow: visible !important; }
    div[style*="overflow-x"] { overflow: visible !important; }
}
</style>

<div class="stock-page">

    <!-- ── Screen-only toolbar ── -->
    <div class="stock-head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <h1 class="stock-title"><i class="fas fa-list-alt"></i> All Transactions Oversight</h1>
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

<?php
// Human-readable period label for KPI cards
$kpi_period = date('M j, Y', strtotime($date_from)) . ' – ' . date('M j, Y', strtotime($date_to));
?>
<!-- KPI Cards -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-receipt"></i> Total Transactions</div>
        <div class="txn-kpi-val"><?=number_format($kpi_txn_count)?></div>
        <div style="font-size:10px;color:#94a3b8;margin-top:4px;"><?=htmlspecialchars($kpi_period)?></div>
    </div>
    <div class="txn-kpi-card total-amount-card">
        <div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Sales</div>
        <div class="txn-kpi-val">&#8369;<?=number_format($kpi_total_sales,2)?></div>
        <div style="font-size:10px;color:#93c5fd;margin-top:4px;"><?=htmlspecialchars($kpi_period)?></div>
    </div>
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-shopping-cart"></i> Merchandise Transactions</div>
        <div class="txn-kpi-val" style="color:#15803d;"><?=number_format($kpi_merch_count)?></div>
        <div style="font-size:10px;color:#94a3b8;margin-top:4px;">Products only</div>
    </div>
    <div class="txn-kpi-card">
        <div class="txn-kpi-lbl"><i class="fas fa-wrench"></i> Job Order Transactions</div>
        <div class="txn-kpi-val" style="color:#b45309;"><?=number_format($kpi_jo_count)?></div>
        <div style="font-size:10px;color:#94a3b8;margin-top:4px;">JO &amp; combined</div>
    </div>
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
            <option value="merchandise" <?=$f_type==='merchandise'?'selected':''?>>Merchandise Only</option>
            <option value="job_order"   <?=$f_type==='job_order'?'selected':''?>>Job Order Only</option>
            <option value="combined"    <?=$f_type==='combined'?'selected':''?>>Job Order + Merchandise</option>
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
        <label>Status</label>
        <select name="status" class="inp">
            <option value="">All Statuses</option>
            <option value="Completed" <?=$f_status==='Completed'?'selected':''?>>Completed</option>
            <option value="Voided"    <?=$f_status==='Voided'?'selected':''?>>Voided</option>
            <option value="Adjusted"  <?=$f_status==='Adjusted'?'selected':''?>>Adjusted</option>
        </select>
    </div>
    <div><label>Search</label><input type="text" name="search" value="<?=htmlspecialchars($search)?>" class="inp" placeholder="Search by OR No., Transaction ID, Customer Name, or Plate No."></div>
    <div style="flex-direction:row;gap:6px;">
        <button type="submit" class="flt-btn flt-btn-solid-primary"><i class="fas fa-search"></i> Filter</button>
        <a href="admin_all_transactions.php" class="flt-btn flt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
    </div>
</form>


<!-- ── Printable wrapper: only this div shows on @media print ── -->
<div class="rpt-printable">

<!-- Centered print header — hidden on screen, shown by print CSS -->
<div class="txn-print-header" style="display:none;">
    <div style="font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">ALL TRANSACTIONS OVERSIGHT</div>
    <div style="font-size:15px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">TRANSACTION AUDIT REPORT</div>
    <div style="font-size:12px;color:#64748b;margin-bottom:2px;"><?=htmlspecialchars($station_name)?><?=($station_location ? ' — ' . htmlspecialchars($station_location) : '')?></div>
    <div style="font-size:12px;color:#334155;"><strong>Period:</strong> <?=htmlspecialchars($kpi_period)?></div>
    <div style="font-size:11px;color:#64748b;margin-top:2px;">Printed: <?=date('F j, Y h:i A')?></div>
</div>

<!-- Table -->
<div class="card">
    <div style="overflow-x:auto;">
    <table class="t">
        <thead>
            <tr>
                <th>OR No.</th><th>Transaction ID</th><th>Customer Name</th><th>Transaction Type</th>
                <th>Products</th><th>Service Type</th><th>Vehicle</th><th>Amount</th><th>Payment Method</th>
                <th>Shift</th><th>Staff Encoder</th><th>Status</th><th>Date & Time</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($rows)): ?>
        <tr><td colspan="14" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No transactions found</td></tr>
        <?php else: ?>
        <?php foreach($rows as $r): ?>
        <?php
            $t=strtolower($r['txn_type']??'');
            $has_items   = !empty(trim($r['items'] ?? ''));
            $has_service = !empty(trim($r['service_type'] ?? ''));
            // Override: if both products AND a service type exist, label as combined
            if ($has_items && $has_service) {
                $tLabel = 'Job Order + Merchandise'; $tIcon = 'fa-wrench'; $tBadge = 'badge-purple';
            } elseif ($t === 'combined') {
                $tLabel = 'Job Order + Merchandise'; $tIcon = 'fa-wrench'; $tBadge = 'badge-purple';
            } elseif ($t === 'job_order' || $has_service) {
                $tLabel = 'Job Order'; $tIcon = 'fa-wrench'; $tBadge = 'badge-orange';
            } else {
                $tLabel = 'Merchandise'; $tIcon = 'fa-shopping-cart'; $tBadge = 'badge-blue';
            }
            $vs=strtolower($r['validation_status']??'');
            if ($vs === 'voided') {
                $statusLabel = 'Voided';
                $statusBadge = 'badge-red';
            } elseif ($vs === 'adjusted') {
                $statusLabel = 'Adjusted';
                $statusBadge = 'badge-orange';
            } else {
                $statusLabel = 'Completed';
                $statusBadge = 'badge-green';
            }
            $or_no = 'OR-' . date('Y', strtotime($r['txn_date'])) . '-' . str_pad($r['txn_db_id'], 6, '0', STR_PAD_LEFT);
        ?>
        <tr>
            <td><strong><?=htmlspecialchars($or_no)?></strong></td>
            <td><span style="font-size:11px;color:#64748b;"><?=htmlspecialchars($r['transaction_id'])?></span></td>
            <td><?=htmlspecialchars($r['customer'])?></td>
            <td><span class="badge <?=$tBadge?>"><i class="fas <?=$tIcon?>"></i> <?=htmlspecialchars($tLabel)?></span></td>
            <td style="font-size:11px;line-height:1.4;"><?=format_transaction_items($r['items'])?></td>
            <td style="font-size:11px;color:#475569;"><?=htmlspecialchars(!empty(trim($r['service_type']??'')) ? $r['service_type'] : '—')?></td>
            <td><?php
                $veh = trim($r['vehicle'] ?? '');
                if ($veh === '' || $veh === '—') {
                    echo $has_service ? '<span style="color:#94a3b8;font-size:11px;">N/A</span>' : '<span style="color:#94a3b8;font-size:11px;">N/A</span>';
                } else { echo htmlspecialchars($veh); }
            ?></td>
            <td style="font-weight:700;">₱<?=number_format($r['amount'],2)?></td>
            <td><?=htmlspecialchars($r['payment_method'])?></td>
            <td><?=htmlspecialchars($r['shift'])?></td>
            <td><?=htmlspecialchars($r['staff_name'])?></td>
            <td><?php
                if ($vs === 'voided') {
                    echo '<span class="badge badge-red"><i class="fas fa-circle" style="font-size:7px;vertical-align:middle;margin-right:3px;"></i>Voided</span>';
                } elseif ($vs === 'adjusted') {
                    echo '<span class="badge badge-orange"><i class="fas fa-circle" style="font-size:7px;vertical-align:middle;margin-right:3px;"></i>Adjusted</span>';
                } else {
                    echo '<span class="badge badge-green"><i class="fas fa-circle" style="font-size:7px;vertical-align:middle;margin-right:3px;"></i>Completed</span>';
                }
            ?></td>
            <td><?=date('M d, Y h:i A',strtotime($r['txn_date']))?></td>
            <td>
                <button class="flt-btn flt-btn-search" style="height:26px;font-size:10px;padding:0 8px;"
                    onclick="openTxnModal({
                        id:    '<?=addslashes(htmlspecialchars($r['transaction_id']))?>' ,
                        or_no: '<?=addslashes(htmlspecialchars($or_no))?>' ,
                        customer: '<?=addslashes(htmlspecialchars($r['customer']))?>' ,
                        type:  '<?=addslashes(htmlspecialchars($tLabel))?>' ,
                        items: '<?=addslashes(format_transaction_items($r['items'], false))?>' ,
                        service_type: '<?=addslashes(htmlspecialchars(!empty(trim($r['service_type']??'')) ? $r['service_type'] : 'N/A'))?>' ,
                        vehicle: '<?=addslashes(htmlspecialchars((trim($r['vehicle']??'')==''||trim($r['vehicle']??'')=='—') ? 'N/A' : $r['vehicle']))?>' ,
                        amount: '₱<?=number_format($r['amount'],2)?>' ,
                        payment: '<?=addslashes(htmlspecialchars($r['payment_method']))?>' ,
                        pstatus: '<?=addslashes(htmlspecialchars($r['payment_status']??''))?>' ,
                        shift:  '<?=addslashes(htmlspecialchars($r['shift']))?>' ,
                        staff:  '<?=addslashes(htmlspecialchars($r['staff_name']))?>' ,
                        status: '<?=addslashes(htmlspecialchars($statusLabel))?>' ,
                        txn_date: '<?=date('M d, Y',strtotime($r['txn_date']))?>' ,
                        txn_time: '<?=date('h:i A',strtotime($r['txn_date']))?>' ,
                        void_reason: '<?=addslashes(htmlspecialchars($r['void_reason'] ?? ''))?>' ,
                        adjustment_reason: '<?=addslashes(htmlspecialchars($r['adjustment_reason'] ?? ''))?>' ,
                        manager_remarks: '<?=addslashes(htmlspecialchars($r['manager_remarks'] ?? ''))?>'
                    })"><i class="fas fa-eye"></i> View</button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div><!-- /.card -->
</div><!-- /.rpt-printable -->

<!-- View Transaction Modal -->
<div id="txnModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:94%;max-width:640px;max-height:90vh;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;animation:modalIn .2s ease;display:flex;flex-direction:column;">
    <!-- Modal Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e2e8f0;flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:36px;height:36px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-receipt" style="color:#0284c7;font-size:15px;"></i>
        </div>
        <div>
          <div style="font-size:14px;font-weight:700;color:#1e293b;">Transaction Details</div>
          <div id="modalTxnId" style="font-size:11px;color:#64748b;"></div>
        </div>
      </div>
      <button onclick="closeTxnModal()" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;line-height:1;padding:4px 8px;border-radius:6px;" title="Close" onmouseover="this.style.background='#f1f5f9';this.style.color='#1e293b';" onmouseout="this.style.background='none';this.style.color='#94a3b8';">&times;</button>
    </div>
    <!-- Modal Scrollable Body -->
    <div style="overflow-y:auto;flex:1;">
      <!-- Section 1: Transaction Information -->
      <div class="modal-section-title"><i class="fas fa-info-circle" style="margin-right:6px;"></i>Transaction Information</div>
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <tbody id="modalTxnInfo"></tbody>
      </table>
      <!-- Section 2: Merchandise / Job Order Details -->
      <div class="modal-section-title" id="modalDetailsTitle"><i class="fas fa-box" style="margin-right:6px;"></i>Merchandise Details</div>
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <tbody id="modalTxnDetails"></tbody>
      </table>
      <!-- Section 3: Payment Details -->
      <div class="modal-section-title"><i class="fas fa-credit-card" style="margin-right:6px;"></i>Payment Details</div>
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <tbody id="modalTxnPayment"></tbody>
      </table>
      <!-- Section 4: Activity / Remarks -->
      <div class="modal-section-title" id="modalActivityTitle" style="display:none;"><i class="fas fa-history" style="margin-right:6px;"></i>Transaction Activity</div>
      <table style="width:100%;border-collapse:collapse;font-size:12px;" id="modalActivityTable" style="display:none;">
        <tbody id="modalTxnActivity"></tbody>
      </table>
    </div>
    <!-- Modal Footer -->
    <div style="padding:12px 20px;text-align:right;border-top:1px solid #f1f5f9;flex-shrink:0;">
      <button onclick="closeTxnModal()" class="flt-btn flt-btn-reset" style="height:34px;"><i class="fas fa-times"></i> Close</button>
    </div>
  </div>
</div>
<style>
@keyframes modalIn{from{opacity:0;transform:translateY(-16px)}to{opacity:1;transform:none}}
#modalTxnInfo tr,#modalTxnDetails tr,#modalTxnPayment tr,#modalTxnActivity tr{border-bottom:1px solid #f1f5f9;}
#modalTxnInfo td,#modalTxnDetails td,#modalTxnPayment td,#modalTxnActivity td{padding:8px 16px;vertical-align:top;}
#modalTxnInfo td:first-child,#modalTxnDetails td:first-child,#modalTxnPayment td:first-child,#modalTxnActivity td:first-child
  {font-weight:700;color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.4px;width:140px;white-space:nowrap;}
#modalTxnInfo td:last-child,#modalTxnDetails td:last-child,#modalTxnPayment td:last-child,#modalTxnActivity td:last-child{color:#1e293b;font-weight:500;font-size:12px;}
</style>
<script>
function _mrow(label,val){
  return '<tr><td>'+label+'</td><td>'+val+'</td></tr>';
}
function openTxnModal(d){
  // Header
  document.getElementById('modalTxnId').textContent = d.or_no + ' (' + d.id + ')';
  // Section 1: Transaction Info
  var info = '';
  info += _mrow('OR Number','<strong>'+d.or_no+'</strong>');
  info += _mrow('Transaction ID','<strong>'+d.id+'</strong>');
  info += _mrow('Date', d.txn_date || d.date || '—');
  info += _mrow('Time', d.txn_time || '—');
  info += _mrow('Shift', d.shift || '—');
  info += _mrow('Staff Encoder', d.staff || '—');
  info += _mrow('Customer', d.customer || 'Walk-in');
  info += _mrow('Transaction Type', d.type || '—');
  info += _mrow('Status', d.status || '—');
  document.getElementById('modalTxnInfo').innerHTML = info;
  // Section 2: Merchandise / JO Details
  var isJO = d.type && (d.type.toLowerCase().indexOf('job') !== -1);
  document.getElementById('modalDetailsTitle').innerHTML =
    (isJO ? '<i class="fas fa-wrench" style="margin-right:6px;"></i>Job Order / Merchandise Details'
           : '<i class="fas fa-box" style="margin-right:6px;"></i>Merchandise Details');
  var det = '';
  if (d.items && d.items !== '—') det += _mrow('Products', '<span style="white-space:pre-wrap;">'+d.items+'</span>');
  if (d.service_type && d.service_type !== 'N/A' && d.service_type !== '—') det += _mrow('Service Type', d.service_type);
  if (d.vehicle && d.vehicle !== 'N/A') det += _mrow('Vehicle', d.vehicle);
  if (!det) det = _mrow('Details','—');
  document.getElementById('modalTxnDetails').innerHTML = det;
  // Section 3: Payment
  var pay = '';
  pay += _mrow('Amount', '<strong style="color:#002F70;font-size:14px;">'+d.amount+'</strong>');
  pay += _mrow('Payment Method', d.payment || '—');
  if (d.pstatus) pay += _mrow('Payment Status', d.pstatus);
  document.getElementById('modalTxnPayment').innerHTML = pay;
  // Section 4: Activity
  var actRows = '';
  if (d.status === 'Voided' && d.void_reason) {
    actRows += _mrow('Void Reason','<span style="color:#dc2626;font-weight:bold;">'+d.void_reason+'</span>');
    if (d.manager_remarks) actRows += _mrow('Manager Remarks', d.manager_remarks);
  } else if (d.status === 'Adjusted' && d.adjustment_reason) {
    actRows += _mrow('Adjustment Reason','<span style="color:#ea580c;font-weight:bold;">'+d.adjustment_reason+'</span>');
    if (d.manager_remarks) actRows += _mrow('Manager Remarks', d.manager_remarks);
  }
  var actSection = document.getElementById('modalActivityTitle');
  var actTable   = document.getElementById('modalActivityTable');
  if (actRows) {
    document.getElementById('modalTxnActivity').innerHTML = actRows;
    actSection.style.display = ''; actTable.style.display = '';
  } else {
    actSection.style.display = 'none'; actTable.style.display = 'none';
  }
  document.getElementById('txnModal').style.display = 'flex';
}
function closeTxnModal(){
  document.getElementById('txnModal').style.display='none';
}
document.getElementById('txnModal').addEventListener('click',function(e){
  if(e.target===this) closeTxnModal();
});
</script>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
