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
               COALESCE(si.capacity, 480)            AS capacity,
               COALESCE(si.reorder_level, 24)         AS reorder_level,
               COALESCE(si.critical_level, 10)         AS critical_level,
               si.physical_count,
               si.variance,
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
        FROM merchandise_transaction_items ti JOIN merchandise_transactions t ON t.id=ti.transaction_id
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
$all_brands = [];
$all_units = [];
$stats = ['total'=>0,'available'=>0,'low'=>0,'critical'=>0,'out'=>0];
$js_items = [];

function get_product_brand($product_name) {
    $name = strtolower($product_name);
    if (strpos($name, 'hardex') !== false) return 'Hardex';
    if (strpos($name, 'petron') !== false) return 'Petron';
    if (strpos($name, 'petromate') !== false) return 'Petron';
    if (strpos($name, 'rev-x') !== false) return 'Rev-X';
    if (strpos($name, 'revx') !== false) return 'Rev-X';
    if (strpos($name, 'ultron') !== false) return 'Ultron';
    if (strpos($name, 'sprint') !== false) return 'Sprint';
    if (strpos($name, 'blaze') !== false) return 'Blaze';
    if (strpos($name, 'wd') !== false) return 'WD-40';
    if (strpos($name, 'whiz') !== false) return 'Whiz';
    if (strpos($name, 'sakura') !== false) return 'Sakura';
    if (strpos($name, 'vic') !== false) return 'VIC';
    if (strpos($name, 'toyota') !== false) return 'Toyota';
    if (strpos($name, 'falcon') !== false) return 'Falcon';
    if (strpos($name, 'yokohama') !== false) return 'Yokohama';
    if (strpos($name, '3m') !== false) return '3M';
    if (strpos($name, 'shell') !== false) return 'Shell';
    if (strpos($name, 'mobil') !== false) return 'Mobil';
    if (strpos($name, 'castrol') !== false) return 'Castrol';
    
    $words = explode(' ', trim($product_name));
    $first = $words[0] ?? '';
    $first = preg_replace('/[^A-Za-z0-9\-]/', '', $first);
    if (strlen($first) > 2) {
        return ucfirst(strtolower($first));
    }
    return 'Petron';
}

foreach ($merch_inventory as $item) {
    if (strtolower($item['status'] ?? 'active') !== 'active') continue;

    $stock    = (float)($item['stock_level'] ?? 0);
    $capacity = (float)($item['capacity'] ?? 0);
    $reorder  = (float)($item['reorder_level'] ?? 24);
    $critical = (float)($item['critical_level'] ?? 10);

    $fill_pct = $capacity > 0 ? ($stock/$capacity)*100 : 0;
    // Status driven by DB thresholds — no hardcoded numbers
    if      ($stock <= 0)         { $st='OUT OF STOCK'; $sc='#dc3545'; $st_cls='out'; }
    elseif  ($stock <= $critical) { $st='CRITICAL';     $sc='#dc3545'; $st_cls='critical'; }
    elseif  ($stock <= $reorder)  { $st='LOW STOCK';    $sc='#fd7e14'; $st_cls='low'; }
    else                          { $st='AVAILABLE';    $sc='#28a745'; $st_cls='ok'; }

    $stats['total']++;
    if ($st_cls==='ok') $stats['available']++;
    elseif ($st_cls==='low') $stats['low']++;
    elseif ($st_cls==='critical') $stats['critical']++;
    else $stats['out']++;

    $pid = (int)$item['id'];
    $mv  = $last_movements[$pid] ?? null;

    $cat_label = $item['category_name'] ?? 'Uncategorized';
    if (!in_array($cat_label,$all_categories)) $all_categories[]=$cat_label;

    $brand = get_product_brand($item['name']);
    if (!in_array($brand,$all_brands)) $all_brands[]=$brand;

    $uom = format_merch_unit($item['unit'] ?? 'pcs');
    if (!in_array($uom,$all_units)) $all_units[]=$uom;

    $js_items[] = [
        'id'         => $pid,
        'name'       => $item['name'],
        'sku'        => $item['sku'] ?? '',
        'category'   => $cat_label,
        'brand'      => $brand,
        'unit'       => $uom,
        'stock'      => (int)$stock,
        'capacity'   => (int)$capacity,
        'reorder'    => (int)$reorder,
        'critical'   => (int)$critical,
        'fill_pct'   => round($fill_pct, 1),
        'physical_count' => $item['physical_count'] !== null ? (float)$item['physical_count'] : null,
        'variance'   => $item['variance'] !== null ? (float)$item['variance'] : null,
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
sort($all_brands);
sort($all_units);

include __DIR__ . '/../partials/header.php';

// Display redirected notice from staff_stock_in.php access attempt
$inv_notice = $_SESSION['inv_notice'] ?? null;
unset($_SESSION['inv_notice']);
?>
<div class="stock-page">
<?php if ($inv_notice): ?>
<div style="background:#e8f4fd; border-left:4px solid #002F70; border-radius:8px; padding:13px 18px; margin-bottom:18px; display:flex; align-items:flex-start; gap:12px; font-size:13px; color:#002F70; line-height:1.5;">
    <i class="fas fa-info-circle" style="font-size:18px; margin-top:1px; flex-shrink:0;"></i>
    <div><strong>Note:</strong> <?php echo htmlspecialchars($inv_notice); ?></div>
</div>
<?php endif; ?>
<style>
body,html{overflow-x:hidden;max-width:100%;}
.stock-page{overflow-x:hidden;max-width:100%;}
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
/* Ensure dropdowns open downward by giving enough space */
.inv-filter-bar select { position: relative; }
.page { min-height: 200vh; }
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
.table-wrap{overflow-x:auto;width:100%;-webkit-overflow-scrolling:touch;}
#merchTable{width:100%;border-collapse:collapse;table-layout:fixed;}
#merchTable thead th{background:#002F70;color:#fff;padding:10px 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
#merchTable tbody td{padding:8px;font-size:12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;}
#merchTable tbody tr:hover td{background:#f8faff;}
@media(max-width:768px){
  .inv-card-body{padding:12px;}
}
.mi-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:13000;align-items:center;justify-content:center;padding:40px 16px;overflow-y:auto;-webkit-overflow-scrolling:touch;}
.mi-overlay.open{display:flex !important;}
.mi-box{background:#fff;border-radius:14px;padding:0;width:600px;max-width:calc(100vw - 32px);display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:miIn .2s ease;overflow:hidden;position:relative;max-height:90vh;}
.mi-box.wide{width:700px;max-width:calc(100vw - 32px);}
@keyframes miIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
.mi-head{display:flex;justify-content:space-between;align-items:center;padding:20px 28px;border-bottom:2px solid #e9ecef;flex-shrink:0;background:#fff;position:relative;z-index:1;}
.mi-title{font-size:1.05rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;}
.mi-close{background:none;border:none;font-size:22px;cursor:pointer;color:#adb5bd;padding:0;line-height:1;}
.mi-close:hover{color:#333;}
.mi-body{padding:28px;overflow-y:auto;flex:1;position:relative;min-height:0;}
.mi-foot{display:flex;gap:10px;justify-content:flex-end;align-items:center;padding:16px 28px;border-top:1px solid #e9ecef;flex-shrink:0;background:#fff;position:relative;z-index:2;pointer-events:auto;}
.mi-foot button{pointer-events:auto;cursor:pointer;}
.mi-info{background:#e8f4fd;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#002F70;line-height:1.6;}

/* ── Stock Request Product List Wrapper ── */
.sr-products-wrapper{
  overflow-y:auto;
  overflow-x:hidden;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  max-height:260px;
  border:1px solid #cbd5e1;
  border-radius:8px;
  background:#f8fafc;
}
/* tbody itself should NOT have overflow/height */
#srProductsList{display:table-row-group;}

/* ── Modal mobile adjustments ── */
@media(max-height:600px){
  .mi-overlay{padding:20px 10px;}
  .mi-box{margin-top:10px;margin-bottom:20px;}
  .mi-head{padding:14px 20px;}
  .mi-body{padding:20px;}
  .mi-foot{padding:12px 20px;}
  .sr-products-wrapper{max-height:150px;}
}
@media(max-width:500px){
  .mi-box,.mi-box.wide{width:100%;max-width:calc(100vw - 20px);}
  .mi-head{padding:14px 16px;}
  .mi-body{padding:16px;}
  .mi-foot{padding:12px 16px;flex-wrap:wrap;}
  .mi-foot .txn-btn{flex:1;min-width:120px;}
  .sr-products-wrapper{max-height:180px;}
}

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

/* ── Stock Request checkbox custom styling ── */
.sr-cb{width:17px;height:17px;accent-color:#002F70;cursor:pointer;flex-shrink:0;}


/* ── Success popup ── */
.sr-success-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:14000;}
.sr-success-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:14001;background:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);text-align:center;min-width:300px;}

/* ── txn-btn override ── */
.txn-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:6px!important;padding:7px 14px!important;border-radius:4px!important;font-size:11px!important;font-weight:600!important;cursor:pointer!important;border:1px solid transparent!important;transition:all .2s!important;text-decoration:none!important;white-space:nowrap!important;background:#fff!important;}
.txn-btn.primary{color:#00264D!important;border-color:#00264D!important;}
.txn-btn.primary:hover{background:#00264D!important;color:#fff!important;}
.txn-btn.secondary{color:#475569!important;border-color:#475569!important;}
.txn-btn.secondary:hover{background:#475569!important;color:#fff!important;}
.txn-btn.success{color:#16a34a!important;border-color:#16a34a!important;}
.txn-btn.success:hover{background:#16a34a!important;color:#fff!important;}
.txn-btn.info{color:#002F70!important;border-color:#002F70!important;}
.txn-btn.info:hover{background:#002F70!important;color:#fff!important;}
.txn-btn.sm{padding:4px 9px!important;font-size:10px!important;}
body.modal-open {
  overflow: hidden !important;
}
body.modal-open .main {
  overflow-y: hidden !important;
}

/* ── Filter reset button ── */
.flt-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:36px;padding:0 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .2s;background:#fff;}
.flt-btn-reset{color:#475569;border-color:#cbd5e1;}
.flt-btn-reset:hover{background:#f1f5f9;border-color:#94a3b8;}

/* ── Outline button (for Select All / Clear) ── */
.int-btn-outline{display:inline-flex !important;align-items:center !important;justify-content:center !important;border:1px solid #00264D !important;background:#fff !important;color:#00264D !important;border-radius:5px !important;cursor:pointer !important;font-size:11px !important;font-weight:600 !important;padding:0 8px !important;height:28px !important;transition:all .15s !important;}
.int-btn-outline:hover{background:#00264D !important;color:#fff !important;border-color:#00264D !important;}

/* ── SR Modal 2-col grid and wide box ── */
.mi-box.wide{width:900px;max-width:calc(100vw - 32px);}
.sr-grid{display:grid;grid-template-columns:240px 1fr;gap:20px;}
.sr-grid > div{min-width:0;}
@media(max-width:680px){.sr-grid{grid-template-columns:1fr;}.mi-box.wide{width:100%;}}

/* ── Stock Request Table Styles ── */
.sr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    table-layout: fixed;
}
.sr-table th, .sr-table td {
    padding: 7px 5px;
    vertical-align: middle;
    box-sizing: border-box;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
/* Status cell — let badge display fully */
.sr-table td.sr-td-status {
    overflow: visible;
    text-align: center;
}
.sr-tbl-row {
    border-bottom: 1px solid #e2e8f0;
    cursor: pointer;
    transition: background 0.15s ease;
}
.sr-tbl-row:hover {
    background: #f1f5f9;
}
</style>

<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;padding-top:16px;padding-bottom:16px;border-bottom:2px solid #e9ecef;margin-bottom:20px;">
    <div>
        <h1 class="h1"><i class="fas fa-boxes"></i> Merchandise Inventory</h1>
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
        <div class="inv-stat-icon" style="color:#2563eb;"><i class="fas fa-box"></i></div>
    </div>
    <div class="inv-stat-card">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Low Stock</span>
            <span class="inv-stat-val"><?php echo $stats['low']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#fd7e14;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <div class="inv-stat-card">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Critical Stock</span>
            <span class="inv-stat-val"><?php echo $stats['critical']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#dc2626;"><i class="fas fa-bell"></i></div>
    </div>
    <div class="inv-stat-card">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Out of Stock</span>
            <span class="inv-stat-val"><?php echo $stats['out']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#7f1d1d;"><i class="fas fa-times-circle"></i></div>
    </div>
</div>

<!-- ══ MERCHANDISE TABLE CARD ══ -->
<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-box"></i> Merchandise Stock</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button onclick="openSrModal()" class="txn-btn primary">
                <i class="fas fa-plus"></i> Stock Request
            </button>
        </div>
    </div>
    <div class="inv-card-body">

        <!-- Filter Bar -->
        <div class="inv-filter-bar" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
            <div style="position:relative;">
                <i class="fas fa-search" style="position:absolute; left:10px; top:11px; color:#94a3b8; font-size:12px;"></i>
                <input type="text" id="merchSearch" placeholder="Search Product..." autocomplete="off" style="padding-left:28px;">
            </div>
            <select id="filterCategory">
                <option value="">All Categories</option>
                <?php foreach ($all_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars(strtolower($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterBrand">
                <option value="">All Brands</option>
                <?php foreach ($all_brands as $b): ?>
                <option value="<?php echo htmlspecialchars(strtolower($b)); ?>"><?php echo htmlspecialchars($b); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterUnit">
                <option value="">All Units</option>
                <?php foreach ($all_units as $u): ?>
                <option value="<?php echo htmlspecialchars(strtolower($u)); ?>"><?php echo htmlspecialchars($u); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterStatus">
                <option value="">All Statuses</option>
                <option value="ok">Available</option>
                <option value="low">Low Stock</option>
                <option value="critical">Critical</option>
                <option value="out">Out of Stock</option>
            </select>
            <select id="sortBy" style="margin-left:auto;">
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
                <thead>
                    <tr>
                        <th style="width:90px;">SKU</th>
                        <th style="width:160px;">Product Name</th>
                        <th style="text-align:center;width:110px;">Category</th>
                        <th style="text-align:center;width:55px;">UOM</th>
                        <th style="text-align:center;width:70px;">Cap.</th>
                        <th style="width:150px;">Stock / Reorder</th>
                        <th style="text-align:right;width:80px;">Phys. Count</th>
                        <th style="text-align:right;width:70px;">Variance</th>
                        <th style="text-align:center;width:100px;">Status</th>
                        <th style="text-align:center;width:90px;">Last Mvmt</th>
                        <th style="width:90px;">Updated</th>
                        <th style="text-align:center;width:75px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php if (empty($js_items)): ?>
                    <tr><td colspan="12" style="text-align:center;padding:32px;color:#6c757d;">No merchandise data available.</td></tr>
                <?php else: ?>
                    <?php
                    // Group by category from $js_items (already filtered to active only)
                    $grouped = [];
                    foreach ($js_items as $it) { $grouped[$it['category']][] = $it; }
                    ksort($grouped);
                    foreach ($grouped as $cat_label => $items):
                    ?>
                    <tr class="cat-header"><td colspan="12" style="font-weight:700; background:#e9ecef!important; color:#495057!important; text-transform:uppercase; font-size:11px; letter-spacing:.5px; border-bottom:2px solid #dee2e6; padding:8px 12px; text-align:center;"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td></tr>
                    <?php foreach ($items as $it):
                        $ts = $it['last_updated'] ? (new DateTime($it['last_updated']))->format('M d, Y') : '—';
                    ?>
                    <?php
                        $ts = $it['last_updated'] ? (new DateTime($it['last_updated']))->format('M d, Y') : '-';
                        $has_variance = ($it['variance'] !== null && (float)$it['variance'] != 0);
                        $display_status = $has_variance ? 'VARIANCE DETECTED' : $it['status'];
                        $display_color = $has_variance ? '#fd7e14' : $it['color'];
                        $phys_text = $it['physical_count'] !== null ? number_format((float)$it['physical_count'], 0) : '-';
                        $var_text = '-';
                        $var_style = 'color:#64748b;';
                        if ($it['variance'] !== null) {
                            $v_val = (float)$it['variance'];
                            if ($v_val > 0) {
                                $var_text = '+' . number_format($v_val, 0);
                                $var_style = 'color:#28a745;font-weight:700;';
                            } elseif ($v_val < 0) {
                                $var_text = number_format($v_val, 0);
                                $var_style = 'color:#dc3545;font-weight:700;';
                            } else {
                                $var_text = '0';
                                $var_style = 'color:#64748b;font-weight:600;';
                            }
                        }
                        $mv_cls = $it['mv_sign'] === '+' ? 'mv-pos' : ($it['mv_sign'] === '-' ? 'mv-neg' : 'mv-none');
                    ?>
                    <tr class="merch-row"
                        data-name="<?php echo strtolower(htmlspecialchars($it['name'])); ?>"
                        data-category="<?php echo strtolower(htmlspecialchars($it['category'])); ?>"
                        data-brand="<?php echo strtolower(htmlspecialchars($it['brand'])); ?>"
                        data-unit="<?php echo strtolower(htmlspecialchars($it['unit'])); ?>"
                        data-status="<?php echo htmlspecialchars($it['status_key']); ?>"
                        data-stock="<?php echo $it['stock']; ?>"
                        data-updated="<?php echo htmlspecialchars($it['last_updated']); ?>"
                        data-idx="<?php echo htmlspecialchars(json_encode($it)); ?>">
                        <td><code style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($it['sku'] ?: '-'); ?></code></td>
                        <td style="white-space:normal;"><strong><?php echo htmlspecialchars($it['name']); ?></strong></td>
                        <td style="text-align:center;"><?php echo htmlspecialchars($it['category']); ?></td>
                        <td style="text-align:center;font-weight:600;color:#475569;"><?php echo htmlspecialchars($it['unit']); ?></td>
                        <td style="text-align:center;font-weight:600;color:#334155;"><?php echo number_format($it['capacity']); ?></td>
                        <td>
                            <div class="fill-bar-wrap">
                                <div class="fill-bar-inner" style="width:<?php echo min(100, round($it['fill_pct'])); ?>%;background:<?php echo $display_color; ?>;"></div>
                            </div>
                            <span style="font-size:11px;font-weight:600;color:#334155;"><?php echo number_format($it['stock']); ?> <?php echo htmlspecialchars($it['unit']); ?></span>
                            <span style="font-size:10px;color:#94a3b8;margin-left:4px;">&middot; Reorder: <?php echo number_format($it['reorder']); ?></span>
                        </td>
                        <td style="text-align:right;font-weight:700;color:#0f172a;"><?php echo $phys_text; ?></td>
                        <td style="text-align:right;<?php echo $var_style; ?>"><?php echo $var_text; ?></td>
                        <td style="text-align:center;">
                            <span class="status-badge" style="background:<?php echo $display_color; ?>20;color:<?php echo $display_color; ?>;border:1px solid <?php echo $display_color; ?>40;">
                                <?php if ($has_variance): ?><i class="fas fa-exclamation-triangle"></i> <?php endif; ?><?php echo htmlspecialchars($display_status); ?>
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($it['mv_label']): ?>
                                <span class="<?php echo $mv_cls; ?>" style="font-size:11px;"><?php echo htmlspecialchars($it['mv_label']); ?></span>
                            <?php else: ?>
                                <span class="mv-none" style="font-size:11px;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px;color:#64748b;"><?php echo $ts; ?></td>
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
        </div>
        <div class="mi-body">
            <div id="vdContent"></div>
        </div>
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
        </div>
        <div class="mi-body">

            <div class="sr-grid">
                <!-- Left Column: Request Info -->
                <div>
                    <div style="background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:12px;">
                        <h4 style="margin:0 0 10px; font-size:12px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:5px;"><i class="fas fa-file-alt"></i> Request Info</h4>
                        <div style="margin-bottom:7px; font-size:11.5px;">
                            <div style="color:#64748b; font-weight:600; margin-bottom:2px;">Request No:</div>
                            <div style="font-weight:700; color:#1e293b;">Auto-Assigned</div>
                        </div>
                        <div style="margin-bottom:7px; font-size:11.5px;">
                            <div style="color:#64748b; font-weight:600; margin-bottom:2px;">Request Date:</div>
                            <div style="font-weight:700; color:#1e293b;"><?= date('M d, Y') ?></div>
                        </div>
                        <div style="font-size:11.5px;">
                            <div style="color:#64748b; font-weight:600; margin-bottom:2px;">Requested By:</div>
                            <div style="font-weight:700; color:#1e293b;"><?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?></div>
                        </div>
                    </div>
                    <div class="sr-field">
                        <label for="srReason" style="font-size:11.5px;"><i class="fas fa-comment-alt"></i> Remarks / Reason</label>
                        <textarea id="srReason" style="min-height:90px; font-size:12px;" placeholder="e.g. Running low, expected high demand this weekend..."></textarea>
                    </div>
                </div>
                
                <!-- Right Column: Products Checklist Table -->
                <div style="display:flex; flex-direction:column; max-height:360px;">
                    <label style="display:block;font-size:12.5px;font-weight:700;color:#374151;margin-bottom:8px;">
                        <i class="fas fa-exclamation-triangle" style="color:#eab308;margin-right:4px;"></i> Products Needing Replenishment <span style="color:#dc2626;">*</span>
                    </label>
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <button type="button" class="int-btn-outline" onclick="srSelectAll()">Select All</button>
                        <button type="button" class="int-btn-outline" onclick="srClearSelection()">Clear Selection</button>
                    </div>
                    <div class="sr-products-wrapper">
                        <table class="sr-table">
                            <thead>
                                <tr style="background:#002F70; color:#fff; position:sticky; top:0; z-index:10;">
                                    <th style="width:6%; text-align:center;">Select</th>
                                    <th style="width:12%; text-align:left;">Product ID</th>
                                    <th style="width:15%; text-align:left;">Product Code</th>
                                    <th style="width:25%; text-align:left;">Product Name</th>
                                    <th style="width:13%; text-align:center;">Current Stock</th>
                                    <th style="width:13%; text-align:center;">Reorder Level</th>
                                    <th style="width:16%; text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="srProductsList">
                                <!-- Populated via JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div id="srError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px 14px;border-radius:6px;font-size:13px;margin-top:10px;margin-bottom:10px;"></div>
        </div>
        <div class="mi-foot">
            <button class="txn-btn secondary" id="srCancelBtn" onclick="closeSrModal()" type="button">Cancel</button>
            <button class="txn-btn primary" id="srSubmitBtn" onclick="srHandleSubmit(this)" type="button"><i class="fas fa-paper-plane"></i> Submit Stock Request</button>
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
    var q     = (document.getElementById('merchSearch').value || '').toLowerCase();
    var cat   = (document.getElementById('filterCategory').value || '').toLowerCase();
    var brand = (document.getElementById('filterBrand').value || '').toLowerCase();
    var unit  = (document.getElementById('filterUnit').value || '').toLowerCase();
    var stat  = (document.getElementById('filterStatus').value || '');
    var sortBy = document.getElementById('sortBy').value;

    // 1. Filter each data row — use search-hidden class (works with pagination)
    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        var name    = (r.dataset.name || '').toLowerCase();
        var rcat    = (r.dataset.category || '').toLowerCase();
        var rbrand  = (r.dataset.brand || '').toLowerCase();
        var runit   = (r.dataset.unit || '').toLowerCase();
        var rstat   = (r.dataset.status || '');
        
        var matchQ  = !q      || name.indexOf(q) !== -1;
        var matchC  = !cat    || rcat === cat;
        var matchB  = !brand  || rbrand === brand;
        var matchU  = !unit   || runit === unit;
        var matchS  = !stat   || rstat === stat; // Exact match only
        
        var visible = matchQ && matchC && matchB && matchU && matchS;
        if (visible) {
            r.classList.remove('search-hidden');
            r.style.display = ''; // ensure visible
        } else {
            r.classList.add('search-hidden');
            r.style.display = 'none';
        }
    });

    // 2. Update category header visibility
    var tbody = document.getElementById('merchTableBody');

    if (sortBy === 'default') {
        // Show/hide category headers based on whether they have ≥1 visible item underneath
        var rows = Array.from(tbody.querySelectorAll('tr'));
        var currentHeader = null;
        var hasVisibleItems = false;

        rows.forEach(function(r) {
            if (r.classList.contains('cat-header')) {
                if (currentHeader) {
                    currentHeader.style.display = hasVisibleItems ? '' : 'none';
                    if (!hasVisibleItems) {
                        currentHeader.classList.add('search-hidden');
                    } else {
                        currentHeader.classList.remove('search-hidden');
                    }
                }
                currentHeader = r;
                hasVisibleItems = false;
            } else if (r.classList.contains('merch-row')) {
                if (!r.classList.contains('search-hidden')) {
                    hasVisibleItems = true;
                }
            }
        });
        // Handle last group
        if (currentHeader) {
            currentHeader.style.display = hasVisibleItems ? '' : 'none';
            if (!hasVisibleItems) {
                currentHeader.classList.add('search-hidden');
            } else {
                currentHeader.classList.remove('search-hidden');
            }
        }
    } else {
        // Global sort mode — always hide category headers
        Array.from(tbody.querySelectorAll('.cat-header')).forEach(function(h) {
            h.style.display = 'none';
            h.classList.add('search-hidden');
        });
    }

    // 3. Trigger pagination reset (go to page 1)
    if (window.tablePaginationTriggers && window.tablePaginationTriggers['merchTable']) {
        window.tablePaginationTriggers['merchTable']();
    } else if (window.setTablePage) {
        window.setTablePage('merchTable', 1);
    }
}

function resetFilters() {
    document.getElementById('merchSearch').value = '';
    document.getElementById('filterCategory').value = '';
    document.getElementById('filterBrand').value = '';
    document.getElementById('filterUnit').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('sortBy').value = 'default';
    // Trigger change event to sort
    var event = new Event('change');
    document.getElementById('sortBy').dispatchEvent(event);
    applyFilters();
}

['input','change'].forEach(function(ev) {
    document.getElementById('merchSearch').addEventListener(ev, applyFilters);
    document.getElementById('filterCategory').addEventListener(ev, applyFilters);
    document.getElementById('filterBrand').addEventListener(ev, applyFilters);
    document.getElementById('filterUnit').addEventListener(ev, applyFilters);
    document.getElementById('filterStatus').addEventListener(ev, applyFilters);
});

document.getElementById('sortBy').addEventListener('change', function() {
    var val = this.value;
    var tbody = document.getElementById('merchTableBody');
    var rows  = Array.from(tbody.querySelectorAll('.merch-row'));
    var headers = Array.from(tbody.querySelectorAll('.cat-header'));

    if (val === 'default') {
        // Show all category headers initially
        headers.forEach(function(h) { h.style.display = ''; });

        // Sort rows by category first, then by name
        rows.sort(function(a, b) {
            var catA = (a.dataset.category || '').toLowerCase();
            var catB = (b.dataset.category || '').toLowerCase();
            if (catA !== catB) {
                return catA.localeCompare(catB);
            }
            var nameA = (a.dataset.name || '').toLowerCase();
            var nameB = (b.dataset.name || '').toLowerCase();
            return nameA.localeCompare(nameB);
        });

        // Re-append in grouped category order
        headers.forEach(function(h) {
            tbody.appendChild(h);
            var hCat = h.textContent.trim().toLowerCase();
            rows.forEach(function(r) {
                var rCat = (r.dataset.category || '').toLowerCase();
                if (rCat === hCat) {
                    tbody.appendChild(r);
                }
            });
        });
    } else {
        // Hide all category headers because it's a global sort
        headers.forEach(function(h) { h.style.display = 'none'; });

        // Sort rows globally
        if (val === 'name_asc')    rows.sort(function(a,b){ return (a.dataset.name||'').localeCompare(b.dataset.name||''); });
        if (val === 'name_desc')   rows.sort(function(a,b){ return (b.dataset.name||'').localeCompare(a.dataset.name||''); });
        if (val === 'stock_asc')   rows.sort(function(a,b){ return parseInt(a.dataset.stock||0)-parseInt(b.dataset.stock||0); });
        if (val === 'stock_desc')  rows.sort(function(a,b){ return parseInt(b.dataset.stock||0)-parseInt(a.dataset.stock||0); });
        if (val === 'newest')      rows.sort(function(a,b){ return (b.dataset.updated||'').localeCompare(a.dataset.updated||''); });

        rows.forEach(function(r){ tbody.appendChild(r); });
    }

    // Re-apply filters and update pagination
    applyFilters();
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
    document.body.classList.add('modal-open');
}
function vdRow(label, val) {
    return '<div class="vd-row"><div class="vd-label">'+label+'</div><div class="vd-val">'+val+'</div></div>';
}
function closeVd() {
    document.getElementById('vdModal').classList.remove('open');
    document.body.classList.remove('modal-open');
}
function closeVdOpenSr() {
    var it = JSON.parse(document.getElementById('vdSrBtn').dataset.item || '{}');
    closeVd();
    openSrModal(it.id);
}
document.getElementById('vdModal').addEventListener('click', function(e){ if(e.target===this) closeVd(); });

// ── Stock Request Modal ───────────────────────────────────────
function toggleSrRowClass(cb) {
    var tr = cb.closest('tr');
    if (cb.checked) {
        tr.style.background = '#f0fdf4';
    } else {
        tr.style.background = '';
    }
}
function srSelectAll() {
    document.querySelectorAll('#srProductsList .sr-cb').forEach(function(cb) {
        cb.checked = true;
        toggleSrRowClass(cb);
    });
}
function srClearSelection() {
    document.querySelectorAll('#srProductsList .sr-cb').forEach(function(cb) {
        cb.checked = false;
        toggleSrRowClass(cb);
    });
}

function openSrModal(preselect) {
    var listEl = document.getElementById('srProductsList');
    listEl.innerHTML = '';
    document.getElementById('srReason').value = '';
    document.getElementById('srError').style.display = 'none';
    document.getElementById('srSubmitBtn').disabled = false;
    document.getElementById('srSubmitBtn').innerHTML = '<i class="fas fa-paper-plane"></i> Submit Stock Request';

    // Filter items that are low/critical/out of stock, or if preselected
    var needy = allMerchData.filter(function(it) {
        return it.status_key === 'low' || it.status_key === 'critical' || it.status_key === 'out' || (preselect && parseInt(it.id) === parseInt(preselect));
    });

    if (needy.length === 0) {
        listEl.innerHTML = '<tr><td colspan="7" style="padding:16px;text-align:center;color:#64748b;font-size:13px;">' +
            '<i class="fas fa-check-circle" style="color:#16a34a;font-size:1.5em;display:block;margin-bottom:8px;"></i>' +
            'All products are currently at optimal stock levels. No low or critical items found.</td></tr>';
    } else {
        needy.forEach(function(it) {
            var isChecked = (preselect && parseInt(it.id) === parseInt(preselect)) ? 'checked' : '';
            var tr = document.createElement('tr');
            if (isChecked) tr.style.background = '#f0fdf4';
            tr.className = 'sr-tbl-row';
            tr.innerHTML = 
                '<td style="text-align:center;"><input type="checkbox" class="sr-cb" value="' + it.id + '" ' + isChecked + ' onclick="event.stopPropagation(); toggleSrRowClass(this);"></td>' +
                '<td style="font-family:monospace; font-weight:700;">P' + String(it.id).padStart(4, '0') + '</td>' +
                '<td style="font-family:monospace; font-weight:600;" title="' + escHtml(it.sku || '') + '">' + escHtml(it.sku || '—') + '</td>' +
                '<td style="font-weight:600;" title="' + escHtml(it.name) + '">' + escHtml(it.name) + '</td>' +
                '<td style="text-align:center; font-weight:700;">' + it.stock + '</td>' +
                '<td style="text-align:center; color:#dc2626; font-weight:700;">' + it.reorder + '</td>' +
                '<td class="sr-td-status"><span style="display:inline-block; padding:1px 7px; border-radius:10px; font-size:9.5px; font-weight:700; white-space:nowrap; background:' + it.color + '20; color:' + it.color + '; border:1px solid ' + it.color + '40;">' + escHtml(it.status) + '</span></td>';
            
            tr.addEventListener('click', function() {
                var cb = this.querySelector('.sr-cb');
                cb.checked = !cb.checked;
                toggleSrRowClass(cb);
            });
            listEl.appendChild(tr);
        });
    }
    
    // Force display and scrolling
    var modal = document.getElementById('srModal');
    modal.classList.add('open');
    document.body.classList.add('modal-open');
    
    setTimeout(function() {
        var modalBody = modal.querySelector('.mi-body');
        if (modalBody) {
            modalBody.style.overflowY = 'auto';
        }
    }, 50);
}
function closeSrModal() {
    var m = document.getElementById('srModal');
    if (m) m.classList.remove('open');
    document.body.classList.remove('modal-open');
}

// Safe null-checked listeners (buttons also have onclick attributes as primary handler)
(function() {
    var closeBtn = document.getElementById('srModalClose');
    if (closeBtn) closeBtn.addEventListener('click', closeSrModal);
    var cancelBtn = document.getElementById('srCancelBtn');
    if (cancelBtn) cancelBtn.addEventListener('click', closeSrModal);
    var srModalEl = document.getElementById('srModal');
    if (srModalEl) srModalEl.addEventListener('click', function(e){ if(e.target===this) closeSrModal(); });
})();

function srHandleSubmit(btn) {
    var checked = Array.from(document.querySelectorAll('#srProductsList .sr-cb:checked'));
    var reason = document.getElementById('srReason').value.trim();
    var errEl = document.getElementById('srError');

    if (checked.length === 0) {
        errEl.textContent = 'Please check at least one product to submit a stock request.';
        errEl.style.display = 'block';
        return;
    }
    errEl.style.display = 'none';

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    var items = checked.map(function(cb) {
        var id = parseInt(cb.value);
        var it = allMerchData.find(function(x) { return x.id === id; });
        if (!it) return null;
        return {
            item_id:            it.id,
            sku:                it.sku,
            item_name:          it.name,
            item_category:      it.category,
            current_stock:      it.stock,
            requested_quantity: 0
        };
    }).filter(Boolean);

    fetch('../backend/api/stock_request.php?action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            items:   items,
            remarks: reason || 'Bulk stock request — low/critical stock'
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Stock Request';
        closeSrModal();

        if (res.success) {
            var srNo = res.request_no || '';
            var cnt  = res.inserted_count || items.length;
            var msg  = 'Successfully submitted stock requests for <strong>' + cnt + '</strong> item' + (cnt !== 1 ? 's' : '') + '.';
            if (srNo) msg += '<br><span style="font-size:12px;color:#64748b;">Request No: <strong>' + escHtml(srNo) + '</strong></span>';
            if (res.message && res.message.indexOf('skipped') !== -1) {
                msg += '<br><small style="color:#d97706;">' + escHtml(res.message.split('Note:')[1] || '') + '</small>';
            }
            document.getElementById('srSuccessMsg').innerHTML = msg;
        } else {
            document.getElementById('srSuccessMsg').innerHTML =
                '<span style="color:#dc2626;">' + escHtml(res.message || 'Submission failed. Please try again.') + '</span>';
        }

        document.getElementById('srSuccessPopup').style.display = 'block';
        document.getElementById('srSuccessOverlay').style.display = 'block';
        setTimeout(closeSrSuccess, 7000);
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        errEl.textContent = 'Network error. Please check your connection and try again.';
        errEl.style.display = 'block';
    });
}

// Legacy addEventListener for Submit (safety net — onclick on button is the primary handler)
(function() {
    var sb = document.getElementById('srSubmitBtn');
    if (sb && !sb.dataset.listenerBound) {
        sb.dataset.listenerBound = '1';
        // noop — handled by onclick attribute
    }
})();




function closeSrSuccess() {
    document.getElementById('srSuccessPopup').style.display = 'none';
    document.getElementById('srSuccessOverlay').style.display = 'none';
    // Stay on current page - reload to refresh inventory
    window.location.reload();
}
function escHtml(str) { var d=document.createElement('div'); d.appendChild(document.createTextNode(str||'')); return d.innerHTML; }
document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closeVd(); closeSrModal(); } });

document.addEventListener('DOMContentLoaded', function() {
    ['srModal','vdModal','srSuccessOverlay','srSuccessPopup'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    setupTablePagination('merchTable', 'merchRowsLimit', 'merchPagination', 50);
    applyFilters();
});
</script>
</div> <!-- /stock-page -->
<?php include __DIR__ . '/../partials/footer.php'; ?>

