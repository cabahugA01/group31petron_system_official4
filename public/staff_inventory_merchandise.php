<?php
/**
 * Staff Merchandise Inventory — Enhanced
 * Filters: Category, Status, Sort | Summary Cards | Last Movement | View Details
 */
$page_id = 'inv_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

$merch_inventory = [];
$msg = '';
try {
    $stmt = $pdo->prepare("
        SELECT ip.id,
               ip.product_name AS name,
               ip.category     AS category_name,
               ip.unit_price   AS price,
               ip.sku,
               ip.status,
               COALESCE(si.unit, ip.size, 'pcs')     AS unit,
               COALESCE(si.stock_level, ip.stock, 0) AS stock_level,
               COALESCE(si.capacity, 0)              AS capacity,
               COALESCE(si.reorder_level, 10)        AS reorder_level,
               si.last_updated AS last_updated
        FROM inventory_products ip
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel')
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading merchandise: ' . $e->getMessage();
}

// ── Last movement per product ─────────────────────────────────
$last_movements = [];
try {
    $mvStmt = $pdo->prepare("
        SELECT product_id, qty_received AS qty, 'Delivery' AS mtype, encoded_at AS mdate
        FROM merchandise_stock_in WHERE station_id = ? AND product_id IS NOT NULL
    ");
    $mvStmt->execute([$station_id]);
    foreach ($mvStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($last_movements[$pid]) || $r['mdate'] > $last_movements[$pid]['date'])
            $last_movements[$pid] = ['qty'=>(int)$r['qty'],'type'=>$r['mtype'],'sign'=>'+','date'=>$r['mdate']];
    }
} catch (Exception $e) {}
try {
    $slStmt = $pdo->prepare("
        SELECT ti.product_id, SUM(ti.quantity) AS qty, MAX(t.created_at) AS mdate
        FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id
        WHERE t.station_id=? AND ti.product_id IS NOT NULL GROUP BY ti.product_id
    ");
    $slStmt->execute([$station_id]);
    foreach ($slStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($last_movements[$pid]) || $r['mdate'] > $last_movements[$pid]['date'])
            $last_movements[$pid] = ['qty'=>(int)$r['qty'],'type'=>'Sales','sign'=>'-','date'=>$r['mdate']];
    }
} catch (Exception $e) {}

// ── Build js_items + summary stats ────────────────────────────
$all_categories = [];
$stats = ['total'=>0,'available'=>0,'low'=>0,'out'=>0];
$js_items = [];

foreach ($merch_inventory as $item) {
    if (strtolower($item['status'] ?? 'active') !== 'active') continue;

    $stock    = (float)($item['stock_level'] ?? 0);
    $capacity = (float)($item['capacity'] ?? 0);
    $reorder  = (float)($item['reorder_level'] ?? 10);
    $cat      = strtoupper(trim($item['category_name'] ?? ''));

    if ($capacity <= 0) {
        if      (strpos($cat,'OIL')!==false&&strpos($cat,'ENGINE')!==false)    $capacity=100;
        elseif  (strpos($cat,'COOLANT')!==false||strpos($cat,'FLUID')!==false) $capacity=strpos($cat,'BRAKE')!==false?50:80;
        elseif  (strpos($cat,'GREASE')!==false||strpos($cat,'LUBE')!==false)   $capacity=100;
        elseif  (strpos($cat,'FILTER')!==false)                                 $capacity=150;
        elseif  (strpos($cat,'ACCESSORI')!==false||strpos($cat,'TIRE')!==false||strpos($cat,'WAX')!==false) $capacity=200;
        elseif  (strpos($cat,'FRESHENER')!==false)                              $capacity=250;
        elseif  (strpos($cat,'BEVERAGE')!==false||strpos($cat,'DRINK')!==false) $capacity=500;
        elseif  (strpos($cat,'SNACK')!==false||strpos($cat,'CHIP')!==false||strpos($cat,'BISCUIT')!==false||strpos($cat,'NOODLE')!==false) $capacity=500;
        elseif  (strpos($cat,'CHOCOLATE')!==false||strpos($cat,'CANDY')!==false) $capacity=400;
        elseif  (strpos($cat,'CHEMICAL')!==false||strpos($cat,'ADDITIVE')!==false) $capacity=80;
        else    $capacity=100;
    }

    $fill_pct = $capacity > 0 ? ($stock/$capacity)*100 : 0;
    if      ($stock<=0)                               { $st='OUT OF STOCK'; $sc='#dc3545'; $st_cls='out'; }
    elseif  ($fill_pct<=10)                           { $st='CRITICAL';     $sc='#dc3545'; $st_cls='critical'; }
    elseif  ($stock<=$reorder||$fill_pct<=25)         { $st='LOW STOCK';   $sc='#fd7e14'; $st_cls='low'; }
    else                                              { $st='AVAILABLE';   $sc='#28a745'; $st_cls='ok'; }

    $stats['total']++;
    if ($st_cls==='ok') $stats['available']++;
    elseif ($st_cls==='low') $stats['low']++;
    else $stats['out']++;

    $pid = (int)$item['id'];
    $mv  = $last_movements[$pid] ?? null;

    $cat_label = $item['category_name'] ?? 'Uncategorized';
    if (!in_array($cat_label,$all_categories)) $all_categories[]=$cat_label;

    $js_items[] = [
        'id'         => $pid,
        'name'       => $item['name'],
        'sku'        => $item['sku'] ?? '',
        'category'   => $cat_label,
        'unit'       => $item['unit'] ?? 'pcs',
        'stock'      => (int)$stock,
        'capacity'   => (int)$capacity,
        'reorder'    => (int)$reorder,
        'fill_pct'   => round($fill_pct, 1),
        'status'     => $st,
        'status_key' => $st_cls,
        'color'      => $sc,
        'mv_label'   => $mv ? ($mv['sign'].$mv['qty'].' '.$mv['type']) : '',
        'mv_sign'    => $mv ? $mv['sign'] : '',
        'price'      => (float)($item['price'] ?? 0),
        'last_updated'=> $item['last_updated'] ?? '',
    ];
}
sort($all_categories);

include __DIR__ . '/../partials/header.php';

// Display redirected notice from staff_stock_in.php access attempt
$inv_notice = $_SESSION['inv_notice'] ?? null;
unset($_SESSION['inv_notice']);
?>
<?php if ($inv_notice): ?>
<div style="background:#e8f4fd; border-left:4px solid #002F70; border-radius:8px; padding:13px 18px; margin-bottom:18px; display:flex; align-items:flex-start; gap:12px; font-size:13px; color:#002F70; line-height:1.5;">
    <i class="fas fa-info-circle" style="font-size:18px; margin-top:1px; flex-shrink:0;"></i>
    <div><strong>Note:</strong> <?php echo htmlspecialchars($inv_notice); ?></div>
</div>
<?php endif; ?>
<style>
body,html{overflow-x:hidden;max-width:100%;}
.page-head{max-width:100%;overflow:hidden;}

/* ── Summary Cards ── */
.inv-stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;}
.inv-stat-card{background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;padding:16px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 3px rgba(0,0,0,.05);position:relative;overflow:hidden;}
.inv-stat-info{display:flex;flex-direction:column;}
.inv-stat-label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;}
.inv-stat-val{font-size:20px;font-weight:700;color:#1e293b;}
.inv-stat-icon{font-size:24px;opacity:0.8;}
@media(max-width:768px){.inv-stats-row{grid-template-columns:repeat(2,1fr);}}

/* ── Filter Bar ── */
.inv-filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px;}
.inv-filter-bar select,.inv-filter-bar input[type=text]{padding:8px 10px;border:1px solid #ced4da;border-radius:6px;font-size:13px;color:#374151;background:#fff;height:36px;outline:none;}
.inv-filter-bar select:focus,.inv-filter-bar input[type=text]:focus{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.inv-filter-bar input[type=text]{min-width:220px;}

/* ── Main card ── */
.inv-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;overflow:hidden;}
.inv-card-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e9ecef;flex-wrap:wrap;gap:8px;}
.inv-card-title{font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;}
.inv-card-body{padding:16px 20px;}
.cat-header td{font-weight:700;background:#e9ecef!important;color:#495057!important;text-transform:uppercase;font-size:.8em;letter-spacing:.5px;border-bottom:2px solid #dee2e6;padding:8px 12px;text-align:center;}

/* ── Fill bar ── */
.fill-bar-wrap{background:#e9ecef;border-radius:3px;height:5px;overflow:hidden;margin-bottom:2px;width:100%;}
.fill-bar-inner{height:100%;border-radius:3px;}

/* ── Status badge ── */
.status-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}

/* ── Last Movement ── */
.mv-pos{color:#16a34a;font-weight:700;}
.mv-neg{color:#dc2626;font-weight:700;}
.mv-none{color:#94a3b8;}

/* ── Table ── */
.table-wrap{overflow:hidden;width:100%;}
#merchTable{width:100%;table-layout:fixed;border-collapse:collapse;}
#merchTable thead th{background:#002F70;color:#fff;padding:10px 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
#merchTable tbody td{padding:9px 8px;font-size:12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
#merchTable tbody tr:hover td{background:#f8faff;}
/* 10 columns: SKU|Name|Category|Unit|Capacity|Current+Reorder|Status|Last Movement|Timestamp|Actions */
#merchTable th:nth-child(1),#merchTable td:nth-child(1){width:8%;}
#merchTable th:nth-child(2),#merchTable td:nth-child(2){width:18%;white-space:normal;}
#merchTable th:nth-child(3),#merchTable td:nth-child(3){width:13%;text-align:center;}
#merchTable th:nth-child(4),#merchTable td:nth-child(4){width:6%;text-align:center;}
#merchTable th:nth-child(5),#merchTable td:nth-child(5){width:8%;text-align:center;}
#merchTable th:nth-child(6),#merchTable td:nth-child(6){width:14%;}
#merchTable th:nth-child(7),#merchTable td:nth-child(7){width:10%;text-align:center;}
#merchTable th:nth-child(8),#merchTable td:nth-child(8){width:10%;text-align:center;}
#merchTable th:nth-child(9),#merchTable td:nth-child(9){width:10%;text-align:center;}
#merchTable th:nth-child(10),#merchTable td:nth-child(10){width:9%;text-align:center;}
@media(max-width:1024px){
  #merchTable th:nth-child(9),#merchTable td:nth-child(9){display:none;}
}
@media(max-width:768px){
  #merchTable th:nth-child(1),#merchTable td:nth-child(1),
  #merchTable th:nth-child(4),#merchTable td:nth-child(4),
  #merchTable th:nth-child(5),#merchTable td:nth-child(5),
  #merchTable th:nth-child(8),#merchTable td:nth-child(8),
  #merchTable th:nth-child(9),#merchTable td:nth-child(9){display:none;}
  #merchTable th:nth-child(2),#merchTable td:nth-child(2){width:30%;}
  #merchTable th:nth-child(3),#merchTable td:nth-child(3){width:22%;}
  #merchTable th:nth-child(6),#merchTable td:nth-child(6){width:22%;}
  #merchTable th:nth-child(7),#merchTable td:nth-child(7){width:16%;}
  #merchTable th:nth-child(10),#merchTable td:nth-child(10){width:10%;}
  .inv-card-body{padding:12px;}
}

/* ── Modal base ── */
.mi-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;}
.mi-overlay.open{display:flex;}
.mi-box{background:#fff;border-radius:14px;padding:28px;width:600px;max-width:calc(100vw - 32px);max-height:calc(100vh - 40px);overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:miIn .2s ease;}
.mi-box.wide{width:700px;}
@keyframes miIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
.mi-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid #e9ecef;}
.mi-title{font-size:1.05rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;}
.mi-close{background:none;border:none;font-size:22px;cursor:pointer;color:#adb5bd;}
.mi-close:hover{color:#333;}
.mi-foot{display:flex;gap:10px;justify-content:flex-end;align-items:center;margin-top:16px;padding-top:14px;border-top:1px solid #e9ecef;}
.mi-info{background:#e8f4fd;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#002F70;line-height:1.6;}

/* ── View Details modal ── */
.vd-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;margin-bottom:14px;}
.vd-row{display:flex;flex-direction:column;gap:2px;}
.vd-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;color:#64748b;}
.vd-val{font-size:14px;font-weight:600;color:#1e293b;}

/* ── Stock Request modal inputs ── */
.sr-field{margin-bottom:14px;}
.sr-field label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;}
.sr-field select,.sr-field input,.sr-field textarea{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;color:#374151;box-sizing:border-box;outline:none;}
.sr-field select:focus,.sr-field input:focus,.sr-field textarea:focus{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.sr-field textarea{resize:vertical;min-height:70px;}

/* ── Checkbox rows (fallback for item list) ── */
.sr-cb-row{display:flex;align-items:center;gap:12px;padding:9px 14px;border-radius:8px;border:1px solid #dee2e6;margin-bottom:6px;cursor:pointer;transition:background .1s;user-select:none;}
.sr-cb-row:hover{background:#f0f4ff;}
.sr-cb-row.checked{background:#eef2ff;border-color:#90a8e0;}
.sr-cb-row.out,.sr-cb-row.critical{border-left:4px solid #dc3545;}
.sr-cb-row.low{border-left:4px solid #fd7e14;}
.sr-cb-row.ok{border-left:4px solid #28a745;}
.sr-cb{width:17px;height:17px;accent-color:#002F70;cursor:pointer;flex-shrink:0;}
.sr-cb-info{flex:1;min-width:0;}
.sr-cb-name{font-weight:700;font-size:13px;color:#212529;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sr-cb-meta{font-size:11px;color:#6c757d;margin-top:1px;}
.sr-cat-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;padding:6px 4px 3px;}

/* ── Success popup ── */
.sr-success-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10998;}
.sr-success-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10999;background:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);text-align:center;min-width:300px;}

/* ── txn-btn override ── */
.txn-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:6px!important;padding:7px 14px!important;border-radius:4px!important;font-size:11px!important;font-weight:600!important;cursor:pointer!important;border:1px solid transparent!important;transition:all .2s!important;text-decoration:none!important;white-space:nowrap!important;background:#fff!important;}
.txn-btn.primary{color:#00264D!important;border-color:#00264D!important;}
.txn-btn.primary:hover{background:#00264D!important;color:#fff!important;}
.txn-btn.secondary{color:#475569!important;border-color:#475569!important;}
.txn-btn.secondary:hover{background:#475569!important;color:#fff!important;}
.txn-btn.success{color:#16a34a!important;border-color:#16a34a!important;}
.txn-btn.success:hover{background:#16a34a!important;color:#fff!important;}
.txn-btn.info{color:#0284c7!important;border-color:#0284c7!important;}
.txn-btn.info:hover{background:#0284c7!important;color:#fff!important;}
.txn-btn.sm{padding:4px 9px!important;font-size:10px!important;}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-boxes"></i> Merchandise Inventory</h1>
        <div class="sub">MANAGE MERCHANDISE ITEMS AND MONITOR STOCK LEVELS.</div>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>


<!-- ══ SUMMARY CARDS ══ -->
<div class="inv-stats-row">
    <div class="inv-stat-card">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Total Products</span>
            <span class="inv-stat-val"><?php echo $stats['total']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#2563eb;"><i class="fas fa-boxes"></i></div>
    </div>
    <div class="inv-stat-card">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Available Products</span>
            <span class="inv-stat-val"><?php echo $stats['available']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#16a34a;"><i class="fas fa-check-circle"></i></div>
    </div>
    <div class="inv-stat-card">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Low Stock Products</span>
            <span class="inv-stat-val"><?php echo $stats['low']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#d97706;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <div class="inv-stat-card">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Out of Stock Products</span>
            <span class="inv-stat-val"><?php echo $stats['out']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#dc2626;"><i class="fas fa-times-circle"></i></div>
    </div>
</div>

<!-- ══ MERCHANDISE TABLE CARD ══ -->
<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-box"></i> Merchandise Stock</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?php
            $export_table_id       = 'merchTable';
            $export_filename       = 'merch_inventory_' . date('Ymd');
            $export_title          = 'Merchandise Inventory';
            $export_rows_select_id = 'merchRowsLimit';
            $export_default_rows   = 25;
            require __DIR__ . '/../partials/export_buttons.php';
            ?>
            <button onclick="openSrModal()" class="txn-btn primary" style="height:36px;">
                <i class="fas fa-box"></i> Stock Request
            </button>
        </div>
    </div>
    <div class="inv-card-body">

        <!-- Filter Bar -->
        <div class="inv-filter-bar">
            <input type="text" id="merchSearch" placeholder="&#128269; Search products..." autocomplete="off">
            <select id="filterCategory">
                <option value="">All Categories</option>
                <?php foreach ($all_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars(strtolower($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterStatus">
                <option value="">All Status</option>
                <option value="ok">Available</option>
                <option value="low">Low Stock</option>
                <option value="out">Out of Stock</option>
                <option value="critical">Critical</option>
            </select>
            <select id="sortBy">
                <option value="default">Default Sort</option>
                <option value="newest">Newest Updated</option>
                <option value="name_asc">Name A–Z</option>
                <option value="name_desc">Name Z–A</option>
                <option value="stock_asc">Stock Low–High</option>
                <option value="stock_desc">Stock High–Low</option>
            </select>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table id="merchTable">
                <colgroup>
                    <col style="width:8%"><col style="width:18%"><col style="width:13%">
                    <col style="width:6%"><col style="width:8%"><col style="width:15%">
                    <col style="width:10%"><col style="width:10%"><col style="width:9%"><col style="width:9%">
                </colgroup>
                <thead>
                    <tr>
                        <th>SKU</th><th>Product Name</th><th style="text-align:center;">Category</th>
                        <th>Unit</th><th>Capacity</th><th>Current Stock / Reorder</th>
                        <th>Status</th><th>Last Movement</th><th>Updated</th><th>Action</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php if (empty($js_items)): ?>
                    <tr><td colspan="10" style="text-align:center;padding:32px;color:#6c757d;">No merchandise data available.</td></tr>
                <?php else: ?>
                    <?php
                    // Group by category from $js_items (already filtered to active only)
                    $grouped = [];
                    foreach ($js_items as $it) { $grouped[$it['category']][] = $it; }
                    ksort($grouped);
                    foreach ($grouped as $cat_label => $items):
                    ?>
                    <tr class="cat-header"><td colspan="10"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td></tr>
                    <?php foreach ($items as $it):
                        $mv_class = $it['mv_sign']==='+'?'mv-pos':($it['mv_sign']==='-'?'mv-neg':'mv-none');
                        $ts = $it['last_updated'] ? (new DateTime($it['last_updated']))->format('M d, Y') : '—';
                    ?>
                    <tr class="merch-row"
                        data-name="<?php echo strtolower(htmlspecialchars($it['name'])); ?>"
                        data-category="<?php echo strtolower(htmlspecialchars($it['category'])); ?>"
                        data-status="<?php echo htmlspecialchars($it['status_key']); ?>"
                        data-stock="<?php echo $it['stock']; ?>"
                        data-updated="<?php echo htmlspecialchars($it['last_updated']); ?>"
                        data-idx="<?php echo htmlspecialchars(json_encode($it)); ?>">
                        <td><code style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($it['sku']); ?></code></td>
                        <td style="white-space:normal;"><strong><?php echo htmlspecialchars($it['name']); ?></strong></td>
                        <td style="text-align:center;"><?php echo htmlspecialchars($it['category']); ?></td>
                        <td style="text-align:center;font-size:11px;color:#64748b;"><?php echo htmlspecialchars($it['unit']); ?></td>
                        <td style="text-align:center;font-weight:600;color:#334155;"><?php echo number_format($it['capacity']); ?></td>
                        <td>
                            <div class="fill-bar-wrap">
                                <div class="fill-bar-inner" style="width:<?php echo min(100,round($it['fill_pct'])); ?>%;background:<?php echo $it['color']; ?>;"></div>
                            </div>
                            <span style="font-size:11px;font-weight:600;color:#334155;"><?php echo number_format($it['stock']); ?> <?php echo htmlspecialchars($it['unit']); ?></span>
                            <span style="font-size:10px;color:#94a3b8;margin-left:4px;">· Reorder: <?php echo number_format($it['reorder']); ?></span>
                        </td>
                        <td style="text-align:center;">
                            <span class="status-badge" style="background:<?php echo $it['color']; ?>20;color:<?php echo $it['color']; ?>;border:1px solid <?php echo $it['color']; ?>40;">
                                <?php echo htmlspecialchars($it['status']); ?>
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($it['mv_label']): ?>
                            <span class="<?php echo $mv_class; ?>" style="font-size:12px;"><?php echo htmlspecialchars($it['mv_label']); ?></span>
                            <?php else: ?>
                            <span class="mv-none" style="font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;font-size:11px;color:#64748b;"><?php echo $ts; ?></td>
                        <td style="text-align:center;">
                            <button class="txn-btn info sm" onclick='viewDetails(<?php echo htmlspecialchars(json_encode($it),ENT_QUOTES); ?>)'>
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="merchPagination"></div>
    </div>
</div>

<!-- ══ VIEW DETAILS MODAL ══ -->
<div class="mi-overlay" id="vdModal">
    <div class="mi-box">
        <div class="mi-head">
            <div class="mi-title"><i class="fas fa-eye"></i> Product Details</div>
            <button class="mi-close" onclick="closeVd()">&times;</button>
        </div>
        <div id="vdContent"></div>
        <div class="mi-foot">
            <button class="txn-btn secondary" onclick="closeVd()">Close</button>
            <button class="txn-btn primary" id="vdSrBtn" onclick="closeVdOpenSr()"><i class="fas fa-box"></i> Request Stock</button>
        </div>
    </div>
</div>

<!-- ══ STOCK REQUEST MODAL ══ -->
<div class="mi-overlay" id="srModal">
    <div class="mi-box wide">
        <div class="mi-head">
            <div class="mi-title"><i class="fas fa-box"></i> Stock Request</div>
            <button class="mi-close" id="srModalClose">&times;</button>
        </div>
        <div class="mi-info">
            <i class="fas fa-info-circle"></i>
            <strong>Fill in the details below and submit your request.</strong><br>
            &bull; Manager will review and approve/reject with a quantity<br>
            &bull; Audit trail logged: Staff ID, Item, Timestamp<br>
            &bull; You can track status under <em>Stock Request</em> in the sidebar
        </div>

        <div style="margin-bottom: 16px;">
            <label style="display:block;font-size:12.5px;font-weight:700;color:#374151;margin-bottom:8px;">
                <i class="fas fa-exclamation-triangle" style="color:#eab308;margin-right:4px;"></i> Select Products Needing Replenishment <span style="color:#dc2626;">*</span>
            </label>
            <div id="srProductsList" style="max-height: 280px; overflow-y: auto; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; background: #f8fafc; display:flex; flex-direction:column; gap:6px;">
                <!-- Populated via JavaScript with checkboxes for low/critical/out of stock items -->
            </div>
        </div>
        <div class="sr-field">
            <label for="srReason"><i class="fas fa-comment-alt"></i> Reason / Remarks</label>
            <textarea id="srReason" placeholder="e.g. Running low, expected high demand this weekend..."></textarea>
        </div>
        <div id="srError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:10px;"></div>
        <div class="mi-foot">
            <button class="txn-btn secondary" id="srCancelBtn">Cancel</button>
            <button class="txn-btn primary" id="srSubmitBtn"><i class="fas fa-paper-plane"></i> Submit Request</button>
        </div>
    </div>
</div>

<!-- ── Success popup ── -->
<div class="sr-success-overlay" id="srSuccessOverlay"></div>
<div class="sr-success-popup" id="srSuccessPopup">
    <div style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-check" style="color:#fff;font-size:22px;"></i>
    </div>
    <h3 style="margin:0 0 6px;color:#28a745;">Request Submitted!</h3>
    <p style="margin:0 0 6px;color:#333;font-size:13px;" id="srSuccessMsg">Your stock request is now <strong>Pending</strong> Manager review.</p>
    <p style="margin:0 0 18px;font-size:12px;color:#6c757d;">Status: <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-weight:700;">PENDING</span></p>
    <button onclick="closeSrSuccess()" class="txn-btn primary">OK</button>
</div>

<script>
var allMerchData = <?php echo json_encode(array_values($js_items)); ?>;
var _srPreselect = null;

// ── Filter / Sort ─────────────────────────────────────────────
function applyFilters() {
    var q    = (document.getElementById('merchSearch').value || '').toLowerCase();
    var cat  = (document.getElementById('filterCategory').value || '').toLowerCase();
    var stat = (document.getElementById('filterStatus').value || '');
    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        var name    = (r.dataset.name || '').toLowerCase();
        var rcat    = (r.dataset.category || '').toLowerCase();
        var rstat   = (r.dataset.status || '');
        var matchQ  = !q    || name.indexOf(q) !== -1;
        var matchC  = !cat  || rcat === cat;
        var matchS  = !stat || rstat === stat || (stat==='out' && (rstat==='out'||rstat==='critical'));
        r.style.display = (matchQ && matchC && matchS) ? '' : 'none';
    });
}
['input','change'].forEach(function(ev) {
    document.getElementById('merchSearch').addEventListener(ev, applyFilters);
    document.getElementById('filterCategory').addEventListener(ev, applyFilters);
    document.getElementById('filterStatus').addEventListener(ev, applyFilters);
});
document.getElementById('sortBy').addEventListener('change', function() {
    var val = this.value;
    var tbody = document.getElementById('merchTableBody');
    var rows  = Array.from(tbody.querySelectorAll('.merch-row'));
    if (val === 'name_asc')    rows.sort(function(a,b){ return (a.dataset.name||'').localeCompare(b.dataset.name||''); });
    if (val === 'name_desc')   rows.sort(function(a,b){ return (b.dataset.name||'').localeCompare(a.dataset.name||''); });
    if (val === 'stock_asc')   rows.sort(function(a,b){ return parseInt(a.dataset.stock||0)-parseInt(b.dataset.stock||0); });
    if (val === 'stock_desc')  rows.sort(function(a,b){ return parseInt(b.dataset.stock||0)-parseInt(a.dataset.stock||0); });
    if (val === 'newest')      rows.sort(function(a,b){ return (b.dataset.updated||'').localeCompare(a.dataset.updated||''); });
    if (val !== 'default') rows.forEach(function(r){ tbody.appendChild(r); });
});

// ── View Details ──────────────────────────────────────────────
function viewDetails(it) {
    var mvCls = it.mv_sign==='+'?'mv-pos':(it.mv_sign==='-'?'mv-neg':'mv-none');
    var mvHtml = it.mv_label ? '<span class="'+mvCls+'">'+escHtml(it.mv_label)+'</span>' : '<span class="mv-none">No movements recorded</span>';
    document.getElementById('vdContent').innerHTML =
        '<div class="vd-grid">' +
        vdRow('Product Name', '<strong>'+escHtml(it.name)+'</strong>') +
        vdRow('SKU', '<code>'+escHtml(it.sku||'—')+'</code>') +
        vdRow('Category', escHtml(it.category)) +
        vdRow('Unit', escHtml(it.unit)) +
        vdRow('Capacity (Max)', it.capacity+' '+escHtml(it.unit)) +
        vdRow('Current Stock', '<strong style="font-size:16px;">'+it.stock+'</strong> '+escHtml(it.unit)+
              ' <span style="font-size:11px;color:#94a3b8;">('+it.fill_pct+'%)</span>') +
        vdRow('Reorder Level', it.reorder+' '+escHtml(it.unit)) +
        vdRow('Status', '<span class="status-badge" style="background:'+it.color+'20;color:'+it.color+';border:1px solid '+it.color+'40;">'+escHtml(it.status)+'</span>') +
        '</div>' +
        '<div style="background:#f8faff;border-radius:8px;padding:12px 14px;margin-top:4px;">' +
        '<div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;margin-bottom:6px;">Last Movement</div>' +
        '<div style="font-size:14px;">'+mvHtml+'</div>' +
        '</div>';
    document.getElementById('vdSrBtn').dataset.item = JSON.stringify(it);
    document.getElementById('vdModal').classList.add('open');
}
function vdRow(label, val) {
    return '<div class="vd-row"><div class="vd-label">'+label+'</div><div class="vd-val">'+val+'</div></div>';
}
function closeVd() { document.getElementById('vdModal').classList.remove('open'); }
function closeVdOpenSr() {
    var it = JSON.parse(document.getElementById('vdSrBtn').dataset.item || '{}');
    closeVd();
    openSrModal(it.id);
}
document.getElementById('vdModal').addEventListener('click', function(e){ if(e.target===this) closeVd(); });

// ── Stock Request Modal ───────────────────────────────────────
function toggleSrRowClass(cb) {
    var row = cb.closest('.sr-cb-row');
    if (cb.checked) row.classList.add('checked');
    else row.classList.remove('checked');
}
function toggleSrRowClick(infoEl) {
    var row = infoEl.closest('.sr-cb-row');
    var cb = row.querySelector('.sr-cb');
    cb.checked = !cb.checked;
    toggleSrRowClass(cb);
}

function openSrModal(preselect) {
    var listEl = document.getElementById('srProductsList');
    listEl.innerHTML = '';
    document.getElementById('srReason').value = '';
    document.getElementById('srError').style.display = 'none';
    document.getElementById('srSubmitBtn').disabled = false;
    document.getElementById('srSubmitBtn').innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';

    // Filter items that are low/critical/out of stock, or if preselected
    var needy = allMerchData.filter(function(it) {
        return it.status_key === 'low' || it.status_key === 'critical' || it.status_key === 'out' || (preselect && parseInt(it.id) === parseInt(preselect));
    });

    if (needy.length === 0) {
        listEl.innerHTML = '<div style="padding:16px;text-align:center;color:#64748b;font-size:13px;">' +
            '<i class="fas fa-check-circle" style="color:#16a34a;font-size:1.5em;display:block;margin-bottom:8px;"></i>' +
            'All products are currently at optimal stock levels. No low or critical items found.</div>';
    } else {
        needy.forEach(function(it) {
            var isChecked = (preselect && parseInt(it.id) === parseInt(preselect)) ? 'checked' : '';
            var checkedClass = isChecked ? ' checked' : '';
            var row = document.createElement('div');
            row.className = 'sr-cb-row ' + it.status_key + checkedClass;
            row.innerHTML = 
                '<input type="checkbox" class="sr-cb" value="' + it.id + '" ' + isChecked + ' onclick="event.stopPropagation(); toggleSrRowClass(this);">' +
                '<div class="sr-cb-info" onclick="toggleSrRowClick(this);">' +
                    '<div class="sr-cb-name">' + escHtml(it.name) + '</div>' +
                    '<div class="sr-cb-meta">' +
                        '<span>SKU: <code>' + escHtml(it.sku||'—') + '</code></span> | ' +
                        '<span>Stock: <strong>' + it.stock + '</strong> / ' + it.capacity + ' ' + escHtml(it.unit) + '</span> | ' +
                        '<span style="color:' + it.color + ';font-weight:700;">' + escHtml(it.status) + '</span>' +
                    '</div>' +
                '</div>';
            listEl.appendChild(row);
        });
    }
    document.getElementById('srModal').classList.add('open');
}
function closeSrModal() { document.getElementById('srModal').classList.remove('open'); }
document.getElementById('srModalClose').addEventListener('click', closeSrModal);
document.getElementById('srCancelBtn').addEventListener('click', closeSrModal);
document.getElementById('srModal').addEventListener('click', function(e){ if(e.target===this) closeSrModal(); });

document.getElementById('srSubmitBtn').addEventListener('click', function() {
    var checked = Array.from(document.querySelectorAll('#srProductsList .sr-cb:checked'));
    var reason = document.getElementById('srReason').value.trim();
    var errEl = document.getElementById('srError');

    if (checked.length === 0) {
        errEl.textContent = 'Please check at least one product to submit a stock request.';
        errEl.style.display = 'block';
        return;
    }
    errEl.style.display = 'none';

    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    // Process items one by one
    var items = checked.map(function(cb) {
        var id = parseInt(cb.value);
        var it = allMerchData.find(function(x) { return x.id === id; });
        return it;
    }).filter(Boolean);

    var results = { ok: 0, fail: 0, errors: [] };
    var currentIndex = 0;

    function submitNext() {
        if (currentIndex >= items.length) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
            closeSrModal();

            if (results.fail === 0) {
                document.getElementById('srSuccessMsg').innerHTML = 
                    'Successfully submitted stock requests for <strong>' + results.ok + '</strong> items.';
            } else {
                document.getElementById('srSuccessMsg').innerHTML = 
                    'Submitted: <strong>' + results.ok + '</strong> succeeded, <strong>' + results.fail + '</strong> failed.<br>' +
                    '<small style="color:#dc2626;">Errors: ' + escHtml(results.errors.join(', ')) + '</small>';
            }

            document.getElementById('srSuccessPopup').style.display = 'block';
            document.getElementById('srSuccessOverlay').style.display = 'block';
            setTimeout(closeSrSuccess, 6000);
            return;
        }

        var it = items[currentIndex];
        currentIndex++;

        // Calculate auto requested qty: capacity - stock (at least 10 or 1)
        var requestedQty = Math.max(1, it.capacity - it.stock);
        if (requestedQty <= 0) requestedQty = 10;

        fetch('../backend/api/stock_request.php?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                item_id: it.id,
                sku: it.sku,
                item_name: it.name,
                item_category: it.category,
                current_stock: it.stock,
                requested_quantity: requestedQty,
                remarks: reason || 'Low stock automatic request'
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                results.ok++;
            } else {
                results.fail++;
                results.errors.push(it.name + ': ' + (res.message || 'error'));
            }
            submitNext();
        })
        .catch(function() {
            results.fail++;
            results.errors.push(it.name + ': network error');
            submitNext();
        });
    }

    submitNext();
});

function closeSrSuccess() {
    document.getElementById('srSuccessPopup').style.display = 'none';
    document.getElementById('srSuccessOverlay').style.display = 'none';
    window.location.href = 'staff_stock_requests.php#tab-merch';
}
function escHtml(str) { var d=document.createElement('div'); d.appendChild(document.createTextNode(str||'')); return d.innerHTML; }
document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closeVd(); closeSrModal(); } });

document.addEventListener('DOMContentLoaded', function() {
    ['srModal','vdModal','srSuccessOverlay','srSuccessPopup'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    setupTablePagination('merchTable', 'merchRowsLimit', 'merchPagination', 50);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
