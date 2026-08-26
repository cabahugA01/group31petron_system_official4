<?php
$page_id = 'manager_voided_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: manager_dashboard.php'); exit;
}

$_SESSION['success'] = 'Voiding and transaction review are now handled inside All Transactions.';
header('Location: manager_validated_transactions.php');
exit;

// â”€â”€ Ensure table exists â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
try { $pdo->exec("CREATE TABLE IF NOT EXISTS voided_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY, transaction_id VARCHAR(50) NOT NULL,
    transaction_type ENUM('job_order','merchandise','combined') NOT NULL,
    customer_name VARCHAR(255) DEFAULT NULL, amount DECIMAL(10,2) NOT NULL,
    void_reason VARCHAR(255) NOT NULL, manager_remarks TEXT,
    voided_by INT NOT NULL, void_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    station_id INT NOT NULL,
    INDEX idx_vt_date (void_date), INDEX idx_vt_station (station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS fields_changed JSON DEFAULT NULL"); } catch(Exception $e2){}
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS merchandise_txn_id INT DEFAULT NULL"); } catch(Exception $e2){}

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$f_manager = trim($_GET['manager']   ?? '');
$f_type    = trim($_GET['type']      ?? '');
$search    = trim($_GET['search']    ?? '');

// â”€â”€ KPIs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kpi_total=0; $kpi_month=0; $kpi_amount=0.0;
try {
    // KPI cards show ALL voided transactions (no date filter)
    $s=$pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount),0) FROM voided_transactions WHERE station_id=?");
    $s->execute([$station_id]); [$kpi_total,$kpi_amount]=$s->fetch(PDO::FETCH_NUM);
    $kpi_total=(int)$kpi_total; $kpi_amount=(float)$kpi_amount;
    
    // Period count: show current month by default for "This Period"
    $kpi_start = date('Y-m-01');
    $kpi_end = date('Y-m-d');
    $s2=$pdo->prepare("SELECT COUNT(*) FROM voided_transactions WHERE station_id=? AND DATE(void_date) BETWEEN ? AND ?");
    $s2->execute([$station_id,$kpi_start,$kpi_end]); 
    $kpi_month=(int)$s2->fetchColumn();
} catch(Exception $e){}

// â”€â”€ Manager list â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$mgr_list=[];
try {
    $s=$pdo->prepare("SELECT DISTINCT u.id, COALESCE(NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),' '),u.username,'Unknown') as name
        FROM users u JOIN voided_transactions vt ON vt.voided_by=u.id WHERE vt.station_id=? ORDER BY name");
    $s->execute([$station_id]); $mgr_list=$s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// â”€â”€ Fetch rows â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$where="WHERE vt.station_id=?";
$params=[$station_id];

// Only apply date filter if BOTH dates are provided
if($date_from !== '' && $date_to !== '') { 
    $where.=" AND DATE(vt.void_date) BETWEEN ? AND ?"; 
    $params[]=$date_from; 
    $params[]=$date_to; 
}

if($f_manager!=='') { $where.=" AND vt.voided_by=?"; $params[]=$f_manager; }
if($f_type!=='')    { $where.=" AND vt.transaction_type=?"; $params[]=$f_type; }
if($search!=='')    { $where.=" AND (vt.transaction_id LIKE ? OR vt.customer_name LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }

$rows=[];
try {
    $s=$pdo->prepare("SELECT vt.id as void_id, vt.transaction_id, vt.transaction_type,
        COALESCE(vt.customer_name,'Walk-in') as customer,
        vt.amount, vt.void_reason, vt.manager_remarks, vt.void_date,
        vt.fields_changed,
        COALESCE(NULLIF(vt.job_order_no,''), NULLIF(mt.job_order_id,'')) AS job_order_no,
        COALESCE(NULLIF(vt.vehicle_plate,''), NULLIF(mt.job_order_vehicle_plate,'')) AS vehicle_plate,
        COALESCE(NULLIF(vt.payment_method,''), NULLIF(mt.payment_method,''), 'Cash') AS payment_method,
        COALESCE(NULLIF(vt.voided_by_name,''), NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '), u.username, 'Unknown') as voided_by_name,
        (SELECT GROUP_CONCAT(mti.product_name SEPARATOR ', ') FROM merchandise_transactions mt2
         INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt2.id
         WHERE mt2.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci) AS item_names
        FROM voided_transactions vt 
        LEFT JOIN merchandise_transactions mt ON mt.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN users u ON u.id=vt.voided_by
        $where ORDER BY vt.void_date DESC LIMIT 500");
    $s->execute($params); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// â”€â”€ Pre-fetch items â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$void_items_map = [];
try {
    if (!empty($rows)) {
        $void_txn_ids = array_filter(array_unique(array_column($rows, 'transaction_id')));
        if (!empty($void_txn_ids)) {
            $ids_str = implode("','", array_map(fn($id)=>str_replace("'","''",$id), $void_txn_ids));
            $stmt = $pdo->query("SELECT mt.transaction_id AS txn_id, mti.product_name, mti.quantity, mti.unit_price, mti.subtotal, COALESCE(mti.item_type,'merchandise') AS item_type
                FROM merchandise_transactions mt INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
                WHERE mt.transaction_id IN ('$ids_str') ORDER BY mt.transaction_id, mti.id ASC");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) { $void_items_map[$item['txn_id']][] = $item; }
        }
    }
} catch(Exception $e){}

// â”€â”€ Export â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$export=$_GET['export']??'';
if(in_array($export,['excel','csv'])) {
    $fn='voided_transactions_'.date('Ymd_His');
    if($export==='excel'){ header('Content-Type: application/vnd.ms-excel'); header("Content-Disposition: attachment; filename=\"{$fn}.xls\""); }
    else { header('Content-Type: text/csv; charset=utf-8'); header("Content-Disposition: attachment; filename=\"{$fn}.csv\""); }
    $out=fopen('php://output','w');
    fputcsv($out,['Void ID','Transaction ID','Customer','Type','Amount','Void Reason','Voided By','Void Date']);
    foreach($rows as $r) fputcsv($out,['VOID-'.$r['void_id'],$r['transaction_id'],$r['customer'],ucwords(str_replace('_',' ',$r['transaction_type'])),'₱'.number_format($r['amount'],2),$r['void_reason'],$r['voided_by_name'],date('M d, Y H:i',strtotime($r['void_date']))]);
    fclose($out); exit;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.page-head.txn-page-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;}
.page-head.txn-page-head h1{font-size:22px !important;font-weight:700 !important;color:var(--petron-blue,#00264D) !important;margin:0 !important;display:flex;align-items:center;gap:8px;}
.page-head.txn-page-head .sub{font-size:13px;color:#666;margin-top:4px;font-weight:400 !important;}
.flt-btn{display:inline-flex;align-items:center;gap:6px;padding:0 16px;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;background:white !important;border:1px solid transparent;}
.flt-btn-reset{color:#6b7280 !important;border-color:#6b7280 !important;} .flt-btn-reset:hover{background:#6b7280 !important;color:#fff !important;}
.flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; } .flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-search{color:#00264D !important;border-color:#00264D !important;} .flt-btn-search:hover{background:#00264D !important;color:#fff !important;}
.flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; } .flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-solid-primary{color:#fff !important;background:#002F70 !important;border-color:#002F70 !important;}
/* Tab Navigation */
.txn-tabs{display:flex;gap:4px;margin-bottom:18px;background:#f1f5f9;border-radius:10px;padding:5px;}
.txn-tab{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;color:#64748b;border:none;background:transparent;transition:all .15s;white-space:nowrap;}
.txn-tab:hover{background:#e2e8f0;color:#334155;}
.txn-tab.active{background:#fff;color:#002F70;box-shadow:0 1px 4px rgba(0,0,0,.1);}
.txn-tab .tab-count{background:#e2e8f0;color:#475569;border-radius:999px;padding:1px 7px;font-size:10px;font-weight:700;}
.txn-tab.active .tab-count{background:#fee2e2;color:#991b1b;}
/* KPI */
.txn-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:18px;}
.txn-kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.txn-kpi-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.txn-kpi-val{font-size:26px;font-weight:800;color:#002F70;line-height:1.1;}
.txn-kpi-card.red-card{background:linear-gradient(135deg,#7f1d1d,#991b1b);}
.txn-kpi-card.red-card .txn-kpi-lbl{color:#fca5a5;} .txn-kpi-card.red-card .txn-kpi-val{color:#fff;}
/* Filters */
.filters{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:18px;}
.filters>div{display:flex;flex-direction:column;gap:3px;}
.filters label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;}
.filters .inp{height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;color:#1e293b;background:#fff;outline:none;min-width:130px;}
.filters .inp:focus{border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.1);}
/* Table */
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border-bottom:1px solid #e9ecef;background:#f8fafc;}
.card-title{font-size:13px;font-weight:700;color:#00264D;}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;}
.badge-red{background:#fee2e2;color:#991b1b;} .badge-blue{background:#dbeafe;color:#1e40af;} .badge-orange{background:#fff7ed;color:#9a3412;}
.card-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.t-void { width: 100%; border-collapse: collapse; table-layout: fixed; }
.t-void thead tr { background: #7f1d1d; }
.t-void th { padding: 8px 5px; font-size: 11px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: .2px; border: none; text-align: left; white-space: normal; word-wrap: break-word; line-height: 1.3; }
.t-void tbody tr { border: none; }
.t-void tbody tr:hover td { background: #fff1f2; }
.t-void td { padding: 8px 5px; color: #334155; background: #fff; font-size: 12px; vertical-align: middle; border: none; white-space: normal; word-wrap: break-word; line-height: 1.4; }
</style>

<!-- Page Header -->
<div class="page-head txn-page-head">
    <div>
        <h1><i class="fas fa-ban"></i> Voided Transactions</h1>
        <div class="sub">Review all voided transactions processed at your station for audit and compliance.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a href="manager_voided_transactions.php" class="flt-btn flt-btn-search" title="View All Records"><i class="fas fa-list"></i> View All</a>
        <a href="?<?=http_build_query(array_merge($_GET,['export'=>'excel']))?>" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="?<?=http_build_query(array_merge($_GET,['export'=>'csv']))?>"   class="flt-btn flt-btn-search"><i class="fas fa-file-csv"></i> CSV</a>
        <button class="flt-btn flt-btn-pdf" onclick="exportPrintableAreaToPDF('.card-table-wrap','Manager Voided Transactions','manager_voided_transactions_<?=htmlspecialchars($date_from ?: 'all')?>_to_<?=htmlspecialchars($date_to ?: 'all')?>',this)"><i class="fas fa-file-pdf"></i> Export PDF</button>
        <button class="flt-btn flt-btn-print" onclick="printReportArea()"><i class="fas fa-print"></i> Print</button>
    </div>
</div>


<!-- KPI Cards -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card"><div class="txn-kpi-lbl"><i class="fas fa-ban"></i> Total Voided</div><div class="txn-kpi-val"><?=number_format($kpi_total)?></div></div>
    <div class="txn-kpi-card"><div class="txn-kpi-lbl"><i class="fas fa-calendar-alt"></i> Voided This Period</div><div class="txn-kpi-val"><?=number_format($kpi_month)?></div></div>
    <div class="txn-kpi-card red-card"><div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Voided Amount</div><div class="txn-kpi-val">₱<?=number_format($kpi_amount,2)?></div></div>
</div>

<!-- Filters -->
<form method="get" class="filters">
    <div><label>From <span style="font-weight:400;font-size:10px;color:#94a3b8;">(Optional)</span></label><input type="date" name="date_from" value="<?=htmlspecialchars($date_from)?>" class="inp" placeholder="All dates"></div>
    <div><label>To <span style="font-weight:400;font-size:10px;color:#94a3b8;">(Optional)</span></label><input type="date" name="date_to" value="<?=htmlspecialchars($date_to)?>" class="inp" placeholder="All dates"></div>
    <div>
        <label>Voided By</label>
        <select name="manager" class="inp">
            <option value="">All</option>
            <?php foreach($mgr_list as $m): ?>
            <option value="<?=(int)$m['id']?>" <?=$f_manager==(int)$m['id']?'selected':''?>><?=htmlspecialchars($m['name'])?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Type</label>
        <select name="type" class="inp">
            <option value="">All Types</option>
            <option value="merchandise" <?=$f_type==='merchandise'?'selected':''?>>Merchandise</option>
            <option value="job_order"   <?=$f_type==='job_order'?'selected':''?>>Job Order</option>
            <option value="combined"    <?=$f_type==='combined'?'selected':''?>>Combined</option>
        </select>
    </div>
    <div><label>Search</label><input type="text" name="search" value="<?=htmlspecialchars($search)?>" class="inp" placeholder="TXN ID, Customer—¦"></div>
    <div style="flex-direction:row;gap:6px;">
        <button type="submit" class="flt-btn flt-btn-solid-primary"><i class="fas fa-search"></i> Filter</button>
        <a href="manager_voided_transactions.php" class="flt-btn flt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
    </div>
</form>

<!-- Table -->
<div class="card">
    <div class="card-head">
        <div class="card-title">
            <i class="fas fa-table" style="margin-right:6px;"></i>Voided Records (<?=count($rows)?> records)
        </div>
    </div>
    <div class="card-table-wrap">
    <table class="t-void">
        <thead>
            <tr>
                <th style="width:5%;">Void ID</th>
                <th style="width:7%;">Transaction ID</th>
                <th style="width:5%;">Job Order No.</th>
                <th style="width:8%;">Customer Name</th>
                <th style="width:6%;">Vehicle Plate No.</th>
                <th style="width:6%;">Transaction Type</th>
                <th style="width:13%;">Items / Service</th>
                <th style="width:6%;">Amount</th>
                <th style="width:6%;">Payment Method</th>
                <th style="width:10%;">Void Reason</th>
                <th style="width:8%;">Manager Remarks</th>
                <th style="width:7%;">Voided By</th>
                <th style="width:8%;">Date & Time</th>
                <th style="width:5%;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($rows)): ?>
        <tr><td colspan="14" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No voided transactions found</td></tr>
        <?php else: ?>
        <?php foreach($rows as $r): ?>
        <?php
            $t = strtolower($r['transaction_type'] ?? '');
            $fields = !empty($r['fields_changed']) ? json_decode($r['fields_changed'], true) : [];

            $jo_raw  = !empty($r['job_order_no']) ? $r['job_order_no'] : ($fields['job_order_no'] ?? '');
            $jo_no   = !empty($jo_raw) ? (str_starts_with($jo_raw, 'JO-') ? htmlspecialchars($jo_raw) : 'JO-' . htmlspecialchars($jo_raw)) : '—';
            $v_plate = !empty($r['vehicle_plate']) ? htmlspecialchars($r['vehicle_plate']) : (!empty($fields['vehicle_plate']) ? htmlspecialchars($fields['vehicle_plate']) : '—');
            $pay_raw = !empty($r['payment_method']) ? $r['payment_method'] : ($fields['payment_method'] ?? 'Cash');
            if (empty($pay_raw) || $pay_raw === 'N/A') $pay_raw = 'Cash';
            $pay_method = htmlspecialchars($pay_raw);

            // Items / Service — from subquery column, then fields_changed voided_items
            $items_parts = [];
            if (!empty($fields['voided_items']) && is_array($fields['voided_items'])) {
                foreach ($fields['voided_items'] as $vi) {
                    $qty = (int)($vi['quantity'] ?? 1);
                    $items_parts[] = htmlspecialchars($vi['product_name'] ?? '') . ' x' . $qty;
                }
            }
            if (empty($items_parts) && !empty($r['item_names'])) {
                $items_parts[] = htmlspecialchars($r['item_names']);
            }
            $items_display = !empty($items_parts) ? implode(', ', $items_parts) : '<em style="color:#94a3b8;">—</em>';

            // Transaction type label
            if (str_contains($t,'job')) $type_label = '<span class="badge badge-orange">Job Order</span>';
            elseif (str_contains($t,'combined')) $type_label = '<span class="badge" style="background:#ede9fe;color:#6d28d9;">Combined</span>';
            else $type_label = '<span class="badge badge-blue">Merchandise</span>';
        ?>
        <tr>
            <td><strong style="color:#991b1b;">VOID-<?=(int)$r['void_id']?></strong></td>
            <td><?=htmlspecialchars($r['transaction_id'])?></td>
            <td><?=$jo_no?></td>
            <td><?=htmlspecialchars($r['customer'])?></td>
            <td><?=$v_plate?></td>
            <td><?=$type_label?></td>
            <td><?=$items_display?></td>
            <td style="font-weight:700;color:#dc2626;">₱<?=number_format($r['amount'],2)?></td>
            <td><?=$pay_method?></td>
            <td style="color:#dc2626;font-style:italic;"><?=htmlspecialchars($r['void_reason'])?></td>
            <td style="color:#94a3b8;"><?=!empty($r['manager_remarks']) ? htmlspecialchars($r['manager_remarks']) : '—'?></td>
            <td><?=htmlspecialchars($r['voided_by_name'])?></td>
            <td><?=date('M d, Y h:i A',strtotime($r['void_date']))?></td>
            <td>
                <?php
                $void_modal_data = [
                    'voidId'   => 'VOID-' . (int)$r['void_id'],
                    'txnId'    => $r['transaction_id'],
                    'customer' => $r['customer'] ?? 'Walk-in',
                    'type'     => ucwords(str_replace('_',' ',$r['transaction_type'])),
                    'amount'   => '₱' . number_format($r['amount'],2),
                    'reason'   => $r['void_reason'],
                    'remarks'  => $r['manager_remarks'] ?? '',
                    'by'       => $r['voided_by_name'] ?? 'Unknown',
                    'date'     => date('M d, Y h:i A', strtotime($r['void_date'])),
                    'payment'  => $pay_method,
                    'items'    => $r['item_names'] ?? '—',
                    'vehicle'  => $v_plate,
                    'joNo'     => $jo_no,
                    'fields_changed' => !empty($r['fields_changed']) ? json_decode($r['fields_changed'], true) : null
                ];
                ?>
                <button class="flt-btn flt-btn-search" style="height:22px;font-size:9px;padding:0 4px;margin:0;"
                    onclick="openVoidModal(<?= htmlspecialchars(json_encode($void_modal_data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-eye"></i> View</button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Void Detail Modal -->
<div id="voidModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:92%;max-width:540px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;">
    <div style="display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid #e2e8f0;">
      <div style="width:36px;height:36px;background:#fef2f2;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-ban" style="color:#dc2626;font-size:15px;"></i>
      </div>
      <div>
        <div style="font-size:14px;font-weight:700;color:#1e293b;">Void Record Details</div>
      </div>
    </div>
    <div style="padding:22px 24px;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <tbody id="voidModalBody"></tbody>
      </table>
    </div>
    <div style="padding:12px 24px 18px;text-align:right;border-top:1px solid #f1f5f9;">
      <button onclick="closeVoidModal()" class="flt-btn flt-btn-reset" style="height:34px;"><i class="fas fa-times"></i> Close</button>
    </div>
  </div>
</div>
<style>
@keyframes voidModalIn{from{opacity:0;transform:translateY(-16px)}to{opacity:1;transform:none}}
#voidModalBody tr{border-bottom:1px solid #f1f5f9;}
#voidModalBody td{padding:9px 8px;vertical-align:top;}
#voidModalBody td:first-child{font-weight:700;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.4px;width:150px;}
#voidModalBody td:last-child{color:#1e293b;font-weight:500;}
</style>
<script>
function openVoidModal(d){
  var pm = d.payment || '—';
  var itemsHtml = '';
  
  if (d.fields_changed) {
    if (d.fields_changed.payment_method) {
      pm = '<strong>' + d.fields_changed.payment_method + '</strong> (' + (d.fields_changed.payment_status || 'Paid') + ')';
    }
    if (d.fields_changed.voided_items && d.fields_changed.voided_items.length > 0) {
      itemsHtml = '<div style="margin-top: 6px; border: 1px solid #fca5a5; border-radius: 8px; overflow: hidden; background: #fff5f5;">' +
        '<table style="width: 100%; border-collapse: collapse; font-size: 11px; text-align: left;">' +
        '<tr style="background: #fee2e2; border-bottom: 1px solid #fca5a5; color: #991b1b;">' +
        '<th style="padding: 6px 8px; font-weight: 700;">Item / Service</th>' +
        '<th style="padding: 6px 8px; font-weight: 700;">Quantity & Price</th>' +
        '<th style="padding: 6px 8px; font-weight: 700;">Subtotal</th>' +
        '</tr>';
      d.fields_changed.voided_items.forEach(function(item) {
        itemsHtml += '<tr style="border-bottom: 1px solid #fecaca;">' +
          '<td style="padding: 6px 8px;"><strong>' + item.product_name + '</strong></td>' +
          '<td style="padding: 6px 8px; color: #64748b;">' + item.quantity + ' x ₱' + Number(item.unit_price).toFixed(2) + '</td>' +
          '<td style="padding: 6px 8px; font-weight: bold; color: #dc2626;">₱' + Number(item.subtotal).toFixed(2) + '</td>' +
          '</tr>';
      });
      itemsHtml += '</table></div>';
    }
  }
  
  if (!itemsHtml) {
    itemsHtml = d.items ? d.items : '<em style="color: #94a3b8; font-size: 12px;">No items logged</em>';
  }

  var rows=[
    ['Void ID',          '<strong style="color:#991b1b;">'+d.voidId+'</strong>'],
    ['Transaction ID',   '<span style="font-family:monospace;">'+d.txnId+'</span>'],
    ['Job Order No.',    d.joNo || '—'],
    ['Customer',         d.customer],
    ['Vehicle Plate',    d.vehicle || '—'],
    ['Type',             d.type],
    ['Items / Service',  itemsHtml],
    ['Payment Method',   pm],
    ['Original Amount',  '<strong style="color:#dc2626;font-size:15px;">'+d.amount+'</strong>'],
    ['Void Reason',      d.reason],
    ['Manager Remarks',  d.remarks || '—'],
    ['Voided By',        d.by],
    ['Void Date',        d.date]
  ];
  var html='';
  rows.forEach(function(r){ html+='<tr><td>'+r[0]+'</td><td>'+r[1]+'</td></tr>'; });
  document.getElementById('voidModalBody').innerHTML=html;
  document.getElementById('voidModal').style.display='flex';
}
function closeVoidModal(){
  document.getElementById('voidModal').style.display='none';
}
document.getElementById('voidModal').addEventListener('click',function(e){
  if(e.target===this) closeVoidModal();
});
</script>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
