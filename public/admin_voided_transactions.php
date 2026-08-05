<?php
$page_id = 'admin_voided_transactions';
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

// â”€â”€ Ensure voided_transactions table exists + columns â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
try { $pdo->exec("CREATE TABLE IF NOT EXISTS voided_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY, transaction_id VARCHAR(50) NOT NULL,
    transaction_type ENUM('job_order','merchandise','combined') NOT NULL,
    customer_name VARCHAR(255) DEFAULT NULL, amount DECIMAL(10,2) NOT NULL,
    void_reason VARCHAR(255) NOT NULL, manager_remarks TEXT,
    voided_by INT NOT NULL, void_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    station_id INT NOT NULL,
    INDEX idx_vt_date (void_date), INDEX idx_vt_station (station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch(Exception $e){}
// Add fields_changed column if not yet present (added in later version)
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS fields_changed JSON DEFAULT NULL"); } catch(Exception $e2){}
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS merchandise_txn_id INT DEFAULT NULL"); } catch(Exception $e2){}
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS job_order_no VARCHAR(100) DEFAULT NULL"); } catch(Exception $e2){}
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS vehicle_plate VARCHAR(50) DEFAULT NULL"); } catch(Exception $e2){}
try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT NULL"); } catch(Exception $e2){}

// â”€â”€ Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$f_manager = trim($_GET['manager']   ?? '');
$f_type    = trim($_GET['type']      ?? '');

// â”€â”€ KPIs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kpi_total=0; $kpi_month=0; $kpi_amount=0.0;
try {
    $s=$pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount),0) FROM voided_transactions WHERE station_id=?");
    $s->execute([$station_id]); [$kpi_total,$kpi_amount]=$s->fetch(PDO::FETCH_NUM);
    $kpi_total=(int)$kpi_total; $kpi_amount=(float)$kpi_amount;
    
    // For "this period", only apply filter if dates are provided
    if($date_from && $date_to) {
        $s2=$pdo->prepare("SELECT COUNT(*) FROM voided_transactions WHERE station_id=? AND DATE(void_date) BETWEEN ? AND ?");
        $s2->execute([$station_id,$date_from,$date_to]); $kpi_month=(int)$s2->fetchColumn();
    } else {
        $kpi_month = $kpi_total; // Show all if no date filter
    }
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
if($date_from && $date_to) { $where.=" AND DATE(vt.void_date) BETWEEN ? AND ?"; $params[]=$date_from; $params[]=$date_to; }
if($f_manager!=='') { $where.=" AND vt.voided_by=?"; $params[]=$f_manager; }
if($f_type!=='') { $where.=" AND vt.transaction_type=?"; $params[]=$f_type; }

$rows=[];
try {
    $s=$pdo->prepare("SELECT vt.id as void_id, vt.transaction_id, vt.transaction_type,
        COALESCE(vt.customer_name,'Walk-in') as customer,
        vt.amount, vt.void_reason, vt.manager_remarks, vt.void_date,
        vt.fields_changed,
        COALESCE(NULLIF(vt.job_order_no,''), NULLIF(mt.job_order_id,'')) AS job_order_no,
        COALESCE(NULLIF(vt.vehicle_plate,''), NULLIF(mt.job_order_vehicle_plate,'')) AS vehicle_plate,
        COALESCE(NULLIF(vt.payment_method,''), NULLIF(mt.payment_method,''), 'Cash') AS payment_method,
        COALESCE(
            NULLIF(vt.voided_by_name,''),
            NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),' '),
            u.username, 'Unknown'
        ) as voided_by_name,
        (SELECT GROUP_CONCAT(mti.product_name SEPARATOR ', ')
         FROM merchandise_transactions mt2
         INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt2.id
         WHERE mt2.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci
        ) AS item_names
        FROM voided_transactions vt 
        LEFT JOIN merchandise_transactions mt ON mt.transaction_id COLLATE utf8mb4_unicode_ci = vt.transaction_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN users u ON u.id=vt.voided_by
        $where ORDER BY vt.void_date DESC LIMIT 500");
    $s->execute($params); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){ error_log('admin voided fetch: '.$e->getMessage()); }

// â”€â”€ Pre-fetch items for voided transactions (same as manager pages) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$void_items_map = [];
try {
    if (!empty($rows)) {
        $void_txn_ids = array_filter(array_unique(array_column($rows, 'transaction_id')));
        if (!empty($void_txn_ids)) {
            $void_txn_ids_str = implode("','", array_map(function($id) {
                return str_replace("'", "''", $id);
            }, $void_txn_ids));
            
            $void_stmt = $pdo->query("
                SELECT mt.transaction_id AS txn_id, mti.product_name, mti.quantity, mti.unit_price, mti.subtotal,
                       COALESCE(mti.item_type,'merchandise') AS item_type
                FROM merchandise_transactions mt
                INNER JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
                WHERE mt.transaction_id IN ('$void_txn_ids_str')
                ORDER BY mt.transaction_id, mti.id ASC
            ");
            foreach ($void_stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $void_items_map[$item['txn_id']][] = $item;
            }
        }
    }
} catch(Exception $e) {
    $void_items_map = [];
}

// â”€â”€ Export â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$export=$_GET['export']??'';
if(in_array($export,['excel','csv'])) {
    $fn='voided_transactions_'.date('Ymd_His');
    if($export==='excel'){ header('Content-Type: application/vnd.ms-excel'); header("Content-Disposition: attachment; filename=\"{$fn}.xls\""); }
    else { header('Content-Type: text/csv; charset=utf-8'); header("Content-Disposition: attachment; filename=\"{$fn}.csv\""); }
    $out=fopen('php://output','w');
    fputcsv($out,['Void ID','Transaction ID','Job Order No.','Customer','Vehicle Plate','Type','Items/Service','Amount','Payment Method','Void Reason','Voided By','Void Date & Time','Status']);
    foreach($rows as $r) {
        // Get items summary
        $items_summary = '';
        $r_fields = !empty($r['fields_changed']) ? json_decode($r['fields_changed'], true) : null;
        if (!empty($r_fields['voided_items'])) {
            $items_list = array_map(function($item) {
                return $item['product_name'] . ' (x' . $item['quantity'] . ')';
            }, $r_fields['voided_items']);
            $items_summary = implode(', ', $items_list);
        } else {
            $items_summary = 'Items not available';
        }
        
        fputcsv($out,[
            'VOID-'.$r['void_id'],
            $r['transaction_id'],
            $r['job_order_no'] ?? '—',
            $r['customer'],
            $r['vehicle_plate'] ?? '—',
            ucwords(str_replace('_',' ',$r['transaction_type'])),
            $items_summary,
            '₱'.number_format($r['amount'],2),
            $r['payment_method'] ?? 'N/A',
            $r['void_reason'],
            $r['voided_by_name'],
            date('M d, Y h:i A',strtotime($r['void_date'])),
            'VOIDED'
        ]);
    }
    fclose($out); exit;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.page-head.txn-page-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;margin-top:16px !important;}
.page-head.txn-page-head h1{font-size:22px !important;font-weight:700 !important;color:var(--petron-blue,#00264D) !important;margin:0 !important;text-transform:none !important;display:flex;align-items:center;gap:8px;}
.page-head.txn-page-head .sub{font-size:13px;color:#666;margin-top:4px;text-transform:none !important;font-weight:400 !important;}
.flt-btn{display:inline-flex;align-items:center;gap:6px;padding:0 16px;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;background:white !important;border:1px solid transparent;}
.flt-btn-reset{color:#6b7280 !important;border-color:#6b7280 !important;} .flt-btn-reset:hover{background:#6b7280 !important;color:#fff !important;}
.flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; } .flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-search{color:#00264D !important;border-color:#00264D !important;} .flt-btn-search:hover{background:#00264D !important;color:#fff !important;}
.flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; } .flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-solid-primary{color:#fff !important;background:#002F70 !important;border-color:#002F70 !important;}
.txn-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:20px;}
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
.t{width:100%;border-collapse:collapse;table-layout:fixed;}
.t thead tr{background:#003d7a;}
.t th{padding:8px 6px;text-align:left;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.3px;line-height:1.3;border:none;word-wrap:break-word;}
.t tbody tr{border-bottom:1px solid #f1f5f9;} .t tbody tr:hover td{background:#fff1f2;}
.t tbody td{padding:8px 6px;color:#334155;background:#fff;font-size:12px;vertical-align:middle;line-height:1.4;word-wrap:break-word;overflow-wrap:break-word;border:none;border-bottom:1px solid #f1f5f9;}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;}
.badge-red{background:#fee2e2;color:#991b1b;} .badge-blue{background:#dbeafe;color:#1e40af;} .badge-orange{background:#fff7ed;color:#9a3412;}
</style>

<div class="page-head txn-page-head">
    <div>
        <h1><i class="fas fa-ban"></i> Voided Transactions Oversight</h1>
        <div class="sub">Monitor and review all voided transactions for compliance monitoring and audit purposes.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a href="admin_all_transactions.php" class="flt-btn flt-btn-reset"><i class="fas fa-arrow-left"></i> Back</a>
        <a href="?<?=http_build_query(array_merge($_GET,['export'=>'excel']))?>" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</a>
        <button class="flt-btn flt-btn-pdf" onclick="exportPrintableAreaToPDF('.card','Voided Transactions Oversight','voided_transactions_<?=htmlspecialchars($date_from ?: 'all')?>_to_<?=htmlspecialchars($date_to ?: 'all')?>',this)"><i class="fas fa-file-pdf"></i> Export PDF</button>
        <button class="flt-btn flt-btn-print" onclick="printReportArea()"><i class="fas fa-print"></i> Print</button>
    </div>
</div>


<!-- KPI Cards -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card"><div class="txn-kpi-lbl"><i class="fas fa-ban"></i> Total Voided Transactions</div><div class="txn-kpi-val"><?=number_format($kpi_total)?></div></div>
    <div class="txn-kpi-card"><div class="txn-kpi-lbl"><i class="fas fa-calendar-alt"></i> Voided This Period</div><div class="txn-kpi-val"><?=number_format($kpi_month)?></div></div>
    <div class="txn-kpi-card total-amount-card"><div class="txn-kpi-lbl"><i class="fas fa-peso-sign"></i> Total Voided Amount</div><div class="txn-kpi-val">₱<?=number_format($kpi_amount,2)?></div></div>
</div>

<!-- Filters -->
<form method="get" class="filters">
    <div><label>From <span style="font-weight:400;font-size:10px;color:#94a3b8;">(Optional)</span></label><input type="date" name="date_from" value="<?=htmlspecialchars($date_from)?>" class="inp" placeholder="All dates"></div>
    <div><label>To <span style="font-weight:400;font-size:10px;color:#94a3b8;">(Optional)</span></label><input type="date" name="date_to" value="<?=htmlspecialchars($date_to)?>" class="inp" placeholder="All dates"></div>
    <div>
        <label>Manager</label>
        <select name="manager" class="inp">
            <option value="">All Managers</option>
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
    <div style="flex-direction:row;gap:6px;">
        <button type="submit" class="flt-btn flt-btn-solid-primary"><i class="fas fa-search"></i> Filter</button>
        <a href="admin_voided_transactions.php" class="flt-btn flt-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
    </div>
</form>

<!-- Table -->
<div class="card">
    <div class="card-head">
        <div class="card-title"><i class="fas fa-table" style="margin-right:6px;"></i>Voided Records (<?=count($rows)?> records)</div>
    </div>
    <div style="width:100%;overflow:hidden;">
    <table class="t">
        <thead>
            <tr>
                <th style="width:4%;">Void ID</th>
                <th style="width:8%;">Transaction ID</th>
                <th style="width:6%;">Job Order</th>
                <th style="width:9%;">Customer</th>
                <th style="width:6%;">Plate No.</th>
                <th style="width:6%;">Type</th>
                <th style="width:14%;">Items / Service</th>
                <th style="width:6%;">Amount</th>
                <th style="width:7%;">Payment</th>
                <th style="width:12%;">Void Reason</th>
                <th style="width:7%;">Voided By</th>
                <th style="width:9%;">Date & Time</th>
                <th style="width:5%;">Status</th>
                <th style="width:5%;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($rows)): ?>
        <tr><td colspan="14" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No voided transactions found</td></tr>
        <?php else: ?>
        <?php foreach($rows as $r): ?>
        <?php 
            $t = strtolower($r['transaction_type']??''); 
            $fields     = !empty($r['fields_changed']) ? json_decode($r['fields_changed'], true) : [];
            $jo_raw     = !empty($r['job_order_no']) ? $r['job_order_no'] : ($fields['job_order_no'] ?? '');
            $jo_disp    = !empty($jo_raw) ? (str_starts_with($jo_raw, 'JO-') ? htmlspecialchars($jo_raw) : 'JO-' . htmlspecialchars($jo_raw)) : '—';
            $plate_disp = !empty($r['vehicle_plate']) ? htmlspecialchars($r['vehicle_plate']) : (!empty($fields['vehicle_plate']) ? htmlspecialchars($fields['vehicle_plate']) : '—');
            $pay_raw    = !empty($r['payment_method']) ? $r['payment_method'] : ($fields['payment_method'] ?? 'Cash');
            if (empty($pay_raw) || $pay_raw === 'N/A') $pay_raw = 'Cash';
        ?>
        <tr>
            <td><strong>VOID-<?=(int)$r['void_id']?></strong></td>
            <td><?=htmlspecialchars($r['transaction_id'])?></td>
            <td><?=$jo_disp?></td>
            <td><?=htmlspecialchars($r['customer'])?></td>
            <td><?=$plate_disp?></td>
            <td>
                <?php 
                $type_short = 'Other';
                $type_color = '#64748b';
                if(str_contains($t,'job')) { $type_short = 'Job Order'; $type_color = '#f59e0b'; }
                elseif(str_contains($t,'merch')) { $type_short = 'Merch'; $type_color = '#3b82f6'; }
                elseif(str_contains($t,'combined')) { $type_short = 'Combined'; $type_color = '#8b5cf6'; }
                ?>
                <span style="display:inline-block;padding:2px 4px;background:<?=$type_color?>1a;color:<?=$type_color?>;border-radius:3px;font-size:8px;font-weight:700;line-height:1.2;">
                    <?=$type_short?>
                </span>
            </td>
            <td style="font-size:9px;line-height:1.3;">
                <?php
                $fields  = !empty($r['fields_changed']) ? json_decode($r['fields_changed'], true) : [];
                $txn_id  = $r['transaction_id'];
                if (!empty($fields['voided_items'])) {
                    foreach ($fields['voided_items'] as $item) {
                        $qty = (float)($item['quantity'] ?? 1);
                        $sub = (float)($item['subtotal'] ?? 0);
                        echo '<div style="margin-bottom:2px;padding:2px 4px;border:1px solid #fca5a5;border-radius:3px;background:#fff5f5;font-size:8px;line-height:1.3;">';
                        echo '<strong>' . htmlspecialchars(substr($item['product_name'] ?? '', 0, 28)) . (strlen($item['product_name'] ?? '') > 28 ? '..' : '') . '</strong><br>';
                        echo '<span style="color:#64748b;">Qty: ' . $qty . ' | ₱' . number_format($sub, 2) . '</span>';
                        echo '</div>';
                    }
                } elseif (!empty($void_items_map[$txn_id])) {
                    foreach ($void_items_map[$txn_id] as $item) {
                        $qty = (float)($item['quantity'] ?? 1);
                        $sub = (float)($item['subtotal'] ?? 0);
                        echo '<div style="margin-bottom:2px;padding:2px 4px;border:1px solid #cbd5e1;border-radius:3px;background:#f8fafc;font-size:8px;line-height:1.3;">';
                        echo '<strong>' . htmlspecialchars(substr($item['product_name'] ?? '', 0, 28)) . (strlen($item['product_name'] ?? '') > 28 ? '..' : '') . '</strong><br>';
                        echo '<span style="color:#64748b;">Qty: ' . $qty . ' | ₱' . number_format($sub, 2) . '</span>';
                        echo '</div>';
                    }
                } elseif (!empty($r['item_names'])) {
                    echo '<span style="font-size:8px;color:#334155;">' . htmlspecialchars($r['item_names']) . '</span>';
                } else {
                    echo '<span style="font-size:8px;color:#94a3b8;font-style:italic;">— (legacy record)</span>';
                }
                ?>
            </td>
            <td style="font-weight:700;color:#dc2626;">₱<?=number_format($r['amount'],2)?></td>
            <td>
                <?php 
                $payment = $pay_raw;
                $payment_short = $payment;
                if (stripos($payment, 'cash') !== false) $payment_short = 'Cash';
                elseif (stripos($payment, 'credit') !== false || stripos($payment, 'card') !== false) $payment_short = 'Card';
                elseif (stripos($payment, 'gcash') !== false) $payment_short = 'GCash';
                elseif (stripos($payment, 'online') !== false) $payment_short = 'Online';
                
                $payment_color = '#64748b';
                if (stripos($payment, 'cash') !== false) $payment_color = '#10b981';
                elseif (stripos($payment, 'card') !== false || stripos($payment, 'credit') !== false) $payment_color = '#3b82f6';
                elseif (stripos($payment, 'gcash') !== false || stripos($payment, 'online') !== false) $payment_color = '#f59e0b';
                ?>
                <span style="display:inline-block;padding:2px 4px;background:<?=$payment_color?>1a;color:<?=$payment_color?>;border-radius:3px;font-size:8px;font-weight:600;line-height:1.2;">
                    <?=htmlspecialchars($payment_short)?>
                </span>
            </td>
            <td><?=htmlspecialchars($r['void_reason'])?><?php if($r['manager_remarks']): ?><br><small style="color:#94a3b8;"><?=htmlspecialchars($r['manager_remarks'])?></small><?php endif; ?></td>
            <td><?=htmlspecialchars($r['voided_by_name'])?></td>
            <td><?=date('M d, Y',strtotime($r['void_date']))?><br><span style="color:#64748b;font-size:11px;"><?=date('h:i A',strtotime($r['void_date']))?></span></td>
            <td>
                <span style="display:inline-block;padding:2px 4px;background:#fee2e2;color:#dc2626;border-radius:3px;font-size:8px;font-weight:700;line-height:1.2;">
                    VOID
                </span>
            </td>
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
                    'payment'  => $pay_raw,
                    'items'    => $r['item_names'] ?? '—',
                    'vehicle'  => $plate_disp,
                    'joNo'     => $jo_disp,
                    'fields_changed' => !empty($r['fields_changed']) ? json_decode($r['fields_changed'], true) : null
                ];
                ?>
                <button class="flt-btn flt-btn-search" style="height:22px;font-size:8px;padding:0 6px;display:inline-flex;align-items:center;gap:3px;"
                    onclick="openVoidModal(<?= htmlspecialchars(json_encode($void_modal_data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)"><i class="fas fa-eye"></i> View</button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Voided Transaction Detail Modal -->
<div id="voidModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:92%;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;animation:voidModalIn .2s ease;">
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
#voidModalBody td:first-child{font-weight:700;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.4px;width:150px;white-space:nowrap;}
#voidModalBody td:last-child{color:#1e293b;font-weight:500;}
</style>
<script>
function openVoidModal(d){
  var pm = d.payment || '—';
  var items = d.items || '—';
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
    itemsHtml = items !== '—' ? items : '<em style="color: #94a3b8; font-size: 12px;">No items logged</em>';
  }

  var rows=[
    ['Void ID',          '<strong>'+d.voidId+'</strong>'],
    ['Transaction ID',   d.txnId],
    ['Job Order No.',    d.joNo || '—'],
    ['Customer',         d.customer],
    ['Vehicle Plate',    d.vehicle || '—'],
    ['Type',             d.type],
    ['Items / Service',  itemsHtml],
    ['Payment Method',   pm],
    ['Original Amount',  '<strong style="color:#002F70;font-size:15px;">'+d.amount+'</strong>'],
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
