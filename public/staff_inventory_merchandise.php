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

// ── Backfill station_inventory for inventory_products ────────
try {
    $pdo->prepare("
        INSERT INTO station_inventory (product_id, station_id, stock_level, status, last_updated)
        SELECT ip.id, ?, COALESCE(ip.stock, 0), 'active', NOW()
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE si.id IS NULL
          AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
    ")->execute([$station_id, $station_id]);
} catch (Exception $e) {}
// ── Backfill station_inventory for products table ────────────
try {
    $pdo->prepare("
        INSERT INTO station_inventory (product_id, station_id, stock_level, status, last_updated)
        SELECT p.id, ?, COALESCE(p.current_stock, 0), 'active', NOW()
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN station_inventory si ON si.product_id = p.id AND si.station_id = ?
        WHERE si.id IS NULL
          AND LOWER(COALESCE(pc.name,'')) NOT IN ('fuel','fuel products','services','service')
          AND LOWER(COALESCE(p.status,'active')) NOT IN ('deleted','archived')
    ")->execute([$station_id, $station_id]);
} catch (Exception $e) {}

// ── Main catalog — UNION of inventory_products + products (same as Manager/Admin) ──
try {
    $stmt = $pdo->prepare("
        SELECT
            ip.id,
            ip.product_name                              AS name,
            COALESCE(ip.category,'Merchandise')          AS category_name,
            ip.description,
            COALESCE(ip.unit_price, 0)                   AS price,
            COALESCE(NULLIF(ip.sku,''), CONCAT('P', LPAD(ip.id,4,'0'))) AS sku,
            COALESCE(ip.status,'active')                 AS status,
            COALESCE(si.unit, ip.size, 'pcs')            AS unit,
            COALESCE(si.stock_level, ip.stock, 0)        AS stock_level,
            COALESCE(si.capacity, ip.max_stock, 480)     AS capacity,
            COALESCE(si.reorder_level, ip.min_stock, 24) AS reorder_level,
            COALESCE(si.critical_level, 10)              AS critical_level,
            si.physical_count,
            si.variance,
            COALESCE(si.last_updated, ip.updated_at, ip.created_at) AS last_updated
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND (si.station_id = ? OR si.station_id = 0 OR si.station_id IS NULL)
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')

        UNION

        SELECT
            p.id,
            p.name                                       AS name,
            COALESCE(pc.name,'General')                  AS category_name,
            p.description,
            COALESCE(si2.price, p.price, 0)              AS price,
            COALESCE(NULLIF(p.sku,''), CONCAT('P', LPAD(p.id,4,'0'))) AS sku,
            COALESCE(NULLIF(si2.status,''), NULLIF(p.status,''), 'active') AS status,
            COALESCE(NULLIF(p.unit,''), NULLIF(si2.unit,''), 'pcs') AS unit,
            COALESCE(si2.stock_level, p.current_stock, 0) AS stock_level,
            COALESCE(NULLIF(si2.capacity,0), NULLIF(p.capacity,0), NULLIF(p.max_stock_level,0), 480) AS capacity,
            COALESCE(NULLIF(si2.reorder_level,0), NULLIF(p.min_stock_level,0), 24) AS reorder_level,
            COALESCE(NULLIF(si2.critical_level,0), 10)   AS critical_level,
            si2.physical_count,
            si2.variance,
            COALESCE(si2.last_updated, p.updated_at, p.created_at) AS last_updated
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN station_inventory si2 ON si2.product_id = p.id AND (si2.station_id = ? OR si2.station_id = 0 OR si2.station_id IS NULL)
        WHERE LOWER(COALESCE(pc.name,'')) NOT IN ('fuel','fuel products','services','service')
          AND LOWER(COALESCE(p.status,'active')) NOT IN ('deleted','archived')
          AND p.id NOT IN (SELECT id FROM inventory_products WHERE LOWER(COALESCE(category,'')) NOT IN ('fuel', 'fuel products'))

        ORDER BY category_name, name
    ");
    $stmt->execute([$station_id, $station_id]);
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

// ── Batches per product (FIFO) ─────────────────────────────────
$product_batches = [];
try {
    $bStmt = $pdo->prepare("
        SELECT id, batch_ref, product_id, qty_received, selling_price, encoded_at
        FROM merchandise_stock_in
        WHERE station_id = ? AND product_id IS NOT NULL
        ORDER BY encoded_at DESC
    ");
    $bStmt->execute([$station_id]);
    foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $pid = (int)$b['product_id'];
        $product_batches[$pid][] = [
            'batch_id'      => $b['batch_ref'] ?: ('BATCH-' . str_pad((string)$b['id'], 4, '0', STR_PAD_LEFT)),
            'remaining_qty' => (int)$b['qty_received'],
            'selling_price' => (float)($b['selling_price'] ?: 0),
            'status'        => 'Active',
            'date'          => $b['encoded_at']
        ];
    }
} catch (Exception $e) {}

// ── Build js_items + summary stats ────────────────────────────
$all_categories = [];
$all_brands = [];
$all_units = [];
$stats = ['total'=>0,'available'=>0,'low'=>0,'out'=>0];
$js_items = [];

foreach ($merch_inventory as $item) {
    $raw_status = strtolower(trim((string)($item['status'] ?? 'active')));
    if (in_array($raw_status, ['inactive', 'disabled', 'archived'], true)) continue;

    $stock    = (float)($item['stock_level'] ?? 0);
    // Capacity default = 480, reorder (Low Stock) threshold = 24
    $capacity = max(480.0, (float)($item['capacity'] ?? 480));
    $reorder  = (float)($item['reorder_level'] ?? 24);
    if ($reorder <= 0) $reorder = 24;   // safety: never zero
    $critical = (float)($item['critical_level'] ?? 10);
    if ($critical <= 0) $critical = 10; // safety: never zero

    $fill_pct = $capacity > 0 ? min(100, ($stock / $capacity) * 100) : 0;
    // Status: stock=0 → Out of Stock | ≤critical → Critical Stock | ≤reorder(24) → Low Stock | else → Available
    if      ($stock <= 0)         { $st='OUT OF STOCK';  $sc='#dc3545'; $st_cls='out'; }
    elseif  ($stock <= $critical) { $st='CRITICAL STOCK'; $sc='#dc3545'; $st_cls='critical'; }
    elseif  ($stock <= $reorder)  { $st='LOW STOCK';     $sc='#fd7e14'; $st_cls='low'; }
    else                          { $st='AVAILABLE';     $sc='#28a745'; $st_cls='ok'; }

    $stats['total']++;
    if ($st_cls==='ok') $stats['available']++;
    elseif ($st_cls==='low' || $st_cls==='critical') $stats['low']++;
    else $stats['out']++;

    $pid = (int)$item['id'];
    $mv  = $last_movements[$pid] ?? null;

    $cat_label = format_product_category_display($item['category_name'] ?? 'Uncategorized', $item['name'] ?? '', $item['description'] ?? '');
    if (!in_array($cat_label,$all_categories)) $all_categories[]=$cat_label;

    $brand = get_product_brand($item['name'], $cat_label, $item['description'] ?? '');
    if (!in_array($brand,$all_brands)) $all_brands[]=$brand;

    $uom = format_product_unit_display($item['unit'] ?? 'pcs', $item['name'] ?? '', $cat_label);
    if (!in_array($uom,$all_units)) $all_units[]=$uom;

    $batches = $product_batches[$pid] ?? [
        [
            'batch_id'      => 'BATCH-MAIN-' . str_pad((string)$pid, 3, '0', STR_PAD_LEFT),
            'remaining_qty' => (int)$stock,
            'selling_price' => (float)($item['price'] ?? 0),
            'status'        => 'Active',
            'date'          => $item['last_updated'] ?? date('Y-m-d')
        ]
    ];

    $js_items[] = [
        'id'           => $pid,
        'name'         => $item['name'],
        'sku'          => (!empty($item['sku']) && $item['sku'] !== '-') ? $item['sku'] : ('P' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT)),
        'barcode'      => !empty($item['barcode']) ? $item['barcode'] : ('480' . str_pad((string)$pid, 9, '0', STR_PAD_LEFT)),
        'supplier'     => 'Petron Main Depot / Authorized Supplier',
        'category'     => $cat_label,
        'brand'        => $brand,
        'unit'         => $uom,
        'stock'        => (int)$stock,
        'capacity'     => (int)$capacity,
        'reorder'      => (int)$reorder,
        'critical'     => (int)$critical,
        'fill_pct'     => round($fill_pct, 1),
        'physical_count' => $item['physical_count'] !== null ? (float)$item['physical_count'] : null,
        'variance'     => $item['variance'] !== null ? (float)$item['variance'] : null,
        'status'       => $st,
        'status_key'   => $st_cls,
        'color'        => $sc,
        'mv_label'     => $mv ? ($mv['sign'].$mv['qty'].' '.$mv['type']) : '',
        'mv_sign'      => $mv ? $mv['sign'] : '',
        'price'        => (float)($item['price'] ?? 0),
        'last_updated' => $item['last_updated'] ?? '',
        'batches'      => $batches,
    ];
}
sort($all_categories);
sort($all_brands);
sort($all_units);

// ── Fetch approved Stock-In records for Stock In tab (Read Only) ──
$stock_in_list = [];
try {
    $stIn = $pdo->prepare("
        SELECT 
            msi.id,
            CONCAT('SI-', LPAD(msi.id, 5, '0')) AS stock_in_no,
            msi.product_name,
            msi.qty_received,
            COALESCE(NULLIF(msi.batch_ref, ''), CONCAT('BATCH-', LPAD(msi.id, 4, '0'))) AS batch_no,
            msi.encoded_at AS date_received,
            COALESCE(u.name, u.username, 'Staff') AS received_by
        FROM merchandise_stock_in msi
        LEFT JOIN users u ON msi.encoded_by = u.id
        WHERE msi.station_id = ?
        ORDER BY msi.encoded_at DESC, msi.id DESC
        LIMIT 200
    ");
    $stIn->execute([$station_id]);
    $stock_in_list = $stIn->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

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
.main, .main-content { padding-top: 0 !important; }

/* ── Summary Cards ── */
.inv-stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;}
.inv-stat-card{background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;padding:16px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 3px rgba(0,0,0,.05);position:relative;overflow:hidden;transition:all .18s ease;}
.inv-stat-card[data-filter]{cursor:pointer;user-select:none;}
.inv-stat-card[data-filter]:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.12);border-color:#94a3b8;}
.inv-stat-card.card-active{border-width:2px!important;box-shadow:0 4px 14px rgba(0,0,0,.15)!important;}
.inv-stat-card.card-active .inv-stat-label::after{content:' ✕ (click to reset)';font-size:9px;opacity:.75;}
.inv-stat-info{display:flex;flex-direction:column;}
.inv-stat-label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;}
.inv-stat-val{font-size:20px;font-weight:700;color:#1e293b;}
.inv-stat-icon{font-size:24px;opacity:0.8;}
@media(max-width:768px){.inv-stats-row{grid-template-columns:repeat(2,1fr);}}

/* ── Filter Bar ── */
.inv-filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px;}
.inv-filter-bar input[type=text]{padding:8px 10px;border:1px solid #ced4da;border-radius:6px;font-size:13px;color:#374151;background:#fff;height:36px;outline:none;}
.inv-filter-bar input[type=text]:focus{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.inv-filter-bar input[type=text]{min-width:220px;}
.inv-filter-bar select{height:36px;min-width:130px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:inherit;color:#1e293b;background:#fff;outline:none;}
.inv-filter-bar select:focus{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.inv-filter-bar select:hover{border-color:#94a3b8;}
#cdd-sort{display:none!important;}

.fd-select-source{display:none!important;}
.fd-select{position:relative;display:inline-block;min-width:130px;}
.fd-select-trigger{display:flex;align-items:center;gap:8px;width:100%;height:36px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#1e293b;font-size:13px;font-family:inherit;cursor:pointer;box-sizing:border-box;white-space:nowrap;}
.fd-select-trigger:hover{border-color:#94a3b8;}
.fd-select.fd-open .fd-select-trigger{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.fd-select-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;text-align:left;}
.fd-select-arrow{font-size:10px;color:#94a3b8;margin-left:auto;transition:transform .15s;flex-shrink:0;}
.fd-select.fd-open .fd-select-arrow{transform:rotate(180deg);}
.fd-select-menu{display:none;position:absolute;top:calc(100% + 4px);left:0;min-width:100%;max-height:280px;overflow-y:auto;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 8px 24px rgba(15,23,42,.16);z-index:10000;}
.fd-select.fd-open .fd-select-menu{display:block;}
.fd-select-option{padding:9px 14px;font-size:13px;color:#1e293b;cursor:pointer;white-space:nowrap;}
.fd-select-option:hover{background:#f1f5f9;}
.fd-select-option.fd-active{font-weight:700;color:#002F70;background:#f0f4ff;}

/* ── Custom Dropdown (always opens downward) ── */
.cdd-wrap{position:relative;display:inline-block;}
.cdd-trigger{display:flex;align-items:center;gap:8px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#374151;background:#fff;height:36px;cursor:pointer;user-select:none;min-width:130px;white-space:nowrap;}
.cdd-trigger:hover{border-color:#94a3b8;}
.cdd-wrap.cdd-open .cdd-trigger{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.cdd-arrow{font-size:10px;color:#94a3b8;margin-left:auto;transition:transform .15s;}
.cdd-wrap.cdd-open .cdd-arrow{transform:rotate(180deg);}
.cdd-menu{display:none;position:absolute;top:calc(100% + 4px);left:0;min-width:100%;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.13);z-index:9999;max-height:280px;overflow-y:auto;overflow-x:hidden;}
.cdd-menu-right{left:auto;right:0;}
.cdd-wrap.cdd-open .cdd-menu{display:block;}
.cdd-item{padding:9px 14px;font-size:13px;color:#374151;cursor:pointer;white-space:nowrap;}
.cdd-item:hover{background:#f1f5f9;}
.cdd-item.cdd-active{font-weight:700;color:#002F70;background:#f0f4ff;}

/* ── Main card ── */
.inv-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;overflow:visible;}
.inv-card-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e9ecef;flex-wrap:wrap;gap:8px;}
.inv-card-title{font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;}
.inv-card-body{padding:16px 20px;}
/* Ensure dropdowns open downward */
.inv-filter-bar select { position: relative; }
.stock-page { min-height: 100vh; }
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
#merchTable{width:100%;border-collapse:collapse;table-layout:auto;}
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
.flt-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:36px;padding:0 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .2s;background:#fff !important;}
.flt-btn-reset{color:#475569 !important;border-color:#cbd5e1 !important;background:#fff !important;}
.flt-btn-reset:hover{background:#f1f5f9 !important;border-color:#94a3b8 !important;}

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

<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;padding-top:0;padding-bottom:10px;border-bottom:2px solid #e9ecef;margin-bottom:12px;">
    <div>
        <h1 class="h1"><i class="fas fa-boxes"></i> Merchandise Inventory</h1>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>


<!-- ══ SUMMARY CARDS (4 CARDS) ══ -->
<div class="inv-stats-row" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div class="inv-stat-card" id="card-total" onclick="filterByCard('', this)">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Total Products</span>
            <span class="inv-stat-val"><?php echo $stats['total']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#2563eb;"><i class="fas fa-box"></i></div>
    </div>
    <div class="inv-stat-card" id="card-available" data-filter="ok" onclick="filterByCard('available', this)" title="Click to filter available products">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Available Products</span>
            <span class="inv-stat-val"><?php echo $stats['available']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#28a745;"><i class="fas fa-check-circle"></i></div>
    </div>
    <div class="inv-stat-card" id="card-low" data-filter="low" onclick="filterByCard('low', this)" title="Click to filter low stock items">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Low Stock</span>
            <span class="inv-stat-val"><?php echo $stats['low']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#fd7e14;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <div class="inv-stat-card" id="card-out" data-filter="out" onclick="filterByCard('out', this)" title="Click to filter out of stock items">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Out of Stock</span>
            <span class="inv-stat-val"><?php echo $stats['out']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#dc2626;"><i class="fas fa-times-circle"></i></div>
    </div>
</div>

<!-- ══ TABS NAVIGATION ══ -->
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" class="inv-tab-btn active" id="tab-overview" onclick="switchInvTab('overview')" style="padding:9px 18px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; border:1px solid #002F70; background:#002F70; color:#fff; transition:all .15s;">
            <i class="fas fa-list"></i> Inventory Overview
        </button>
        <button type="button" class="inv-tab-btn" id="tab-stockin" onclick="switchInvTab('stockin')" style="padding:9px 18px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; border:1px solid #cbd5e1; background:#fff; color:#475569; transition:all .15s;">
            <i class="fas fa-arrow-down"></i> Stock In (Read Only)
        </button>
        <button type="button" class="inv-tab-btn" id="tab-alerts" onclick="switchInvTab('alerts')" style="padding:9px 18px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; border:1px solid #cbd5e1; background:#fff; color:#475569; transition:all .15s;">
            <i class="fas fa-bell"></i> Stock Alerts
            <span style="background:#dc2626; color:#fff; font-size:11px; padding:2px 7px; border-radius:12px; margin-left:6px;"><?php echo ($stats['low'] + $stats['out']); ?></span>
        </button>
    </div>
</div>

<!-- ══ TAB 1: INVENTORY OVERVIEW ══ -->
<div class="inv-card" id="section-overview">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-box"></i> Merchandise Stock Overview</div>
        <button type="button" onclick="openSrModal()" style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:6px; font-weight:600; font-size:13px; cursor:pointer; background:transparent !important; color:#334155 !important; border:1px solid #cbd5e1 !important; transition:all 0.15s ease;" onmouseover="this.style.borderColor='#94a3b8';this.style.background='#f8fafc';" onmouseout="this.style.borderColor='#cbd5e1';this.style.background='transparent';">
            <i class="fas fa-paper-plane" style="color:#002F70;"></i> Stock Request
        </button>
    </div>


    <div class="inv-card-body">

        <!-- Filter Bar -->
        <div class="inv-filter-bar" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
            <div style="position:relative;">
                <i class="fas fa-search" style="position:absolute; left:10px; top:11px; color:#94a3b8; font-size:12px;"></i>
                <input type="text" id="merchSearch" placeholder="Search Product / SKU..." autocomplete="off" oninput="applyFilters()" onkeydown="if(event.key==='Enter'){ applyFilters(); }" style="padding-left:28px; width:240px;">
            </div>
            <select id="filterCategory" onchange="applyFilters()">
                <option value="">All Categories</option>
                <?php foreach ($all_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars(strtolower($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterBrand" onchange="applyFilters()" style="display:none;">
                <option value="">All Brands</option>
                <?php foreach ($all_brands as $b): ?>
                <option value="<?php echo htmlspecialchars(strtolower($b)); ?>"><?php echo htmlspecialchars($b); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterUnit" onchange="applyFilters()" style="display:none;">
                <option value="">All Units</option>
                <?php foreach ($all_units as $u): ?>
                <option value="<?php echo htmlspecialchars(strtolower($u)); ?>"><?php echo htmlspecialchars($u); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterStatus" onchange="applyFilters()">
                <option value="">All Statuses</option>
                <option value="available">Available</option>
                <option value="low">Low Stock</option>
                <option value="out">Out of Stock</option>
            </select>
            <select id="sortBy" onchange="applyFilters()">
                <option value="default">Default Sort</option>
                <option value="newest">Newest Updated</option>
                <option value="name_asc">Name A–Z</option>
                <option value="name_desc">Name Z–A</option>
                <option value="stock_asc">Stock Low–High</option>
                <option value="stock_desc">Stock High–Low</option>
            </select>
            <button type="button" class="flt-btn" onclick="applyFilters()" style="border:1px solid #002F70; color:#002F70; background:#fff; font-weight:600; padding:6px 14px; border-radius:6px; cursor:pointer;">
                <i class="fas fa-filter"></i> Filter
            </button>
            <button type="button" class="flt-btn flt-btn-reset" onclick="resetFilters()" title="Reset All Filters" style="border:1px solid #64748b; color:#64748b; background:#fff; font-weight:600; padding:6px 14px; border-radius:6px; cursor:pointer;">
                <i class="fas fa-undo"></i> Reset
            </button>
        </div>


        <!-- Table -->
        <div class="table-wrap">
            <table id="merchTable">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th style="text-align:center;">Category</th>
                        <th style="text-align:center;">UOM</th>
                        <th>Current Stock</th>
                        <th style="text-align:center;">Status</th>
                        <th>Last Updated</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php if (empty($js_items)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:32px;color:#6c757d;">No merchandise data available.</td></tr>
                <?php else: ?>
                    <?php
                    // Group by category from $js_items (already filtered to active only)
                    $grouped = [];
                    foreach ($js_items as $it) { $grouped[$it['category']][] = $it; }
                    ksort($grouped);
                    foreach ($grouped as $cat_label => $items):
                    ?>
                    <tr class="cat-header"><td colspan="8" style="font-weight:700; background:#e9ecef!important; color:#495057!important; text-transform:uppercase; font-size:11px; letter-spacing:.5px; border-bottom:2px solid #dee2e6; padding:8px 12px; text-align:center;"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td></tr>
                    <?php foreach ($items as $it):
                        $ts = $it['last_updated'] ? (new DateTime($it['last_updated']))->format('M d, Y') : '-';
                        $has_variance = ($it['variance'] !== null && (float)$it['variance'] != 0);
                        $display_status = $has_variance ? 'VARIANCE DETECTED' : $it['status'];
                        $display_color = $has_variance ? '#fd7e14' : $it['color'];
                    ?>
                    <tr class="merch-row"
                        data-name="<?php echo strtolower(htmlspecialchars($it['name'])); ?>"
                        data-sku="<?php echo strtolower(htmlspecialchars($it['sku'] ?: '')); ?>"
                        data-category="<?php echo strtolower(htmlspecialchars($it['category'])); ?>"
                        data-brand="<?php echo strtolower(htmlspecialchars($it['brand'])); ?>"
                        data-unit="<?php echo strtolower(htmlspecialchars($it['unit'])); ?>"
                        data-status="<?php echo htmlspecialchars($it['status_key']); ?>"
                        data-filter-status="<?php echo $has_variance ? 'variance detected' : htmlspecialchars($it['status_key']); ?>"
                        data-stock="<?php echo $it['stock']; ?>"
                        data-updated="<?php echo htmlspecialchars($it['last_updated']); ?>"
                        data-idx="<?php echo htmlspecialchars(json_encode($it)); ?>">
                        <td><code style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($it['sku']); ?></code></td>
                        <td style="white-space:normal;"><strong><?php echo htmlspecialchars($it['name']); ?></strong></td>
                        <td style="text-align:center;"><?php echo htmlspecialchars($it['category']); ?></td>
                        <td style="text-align:center;font-weight:600;color:#475569;"><?php echo htmlspecialchars($it['unit']); ?></td>
                        <td>
                            <div class="fill-bar-wrap">
                                <div class="fill-bar-inner" style="width:<?php echo min(100, round($it['fill_pct'])); ?>%;background:<?php echo $display_color; ?>;"></div>
                            </div>
                            <span style="font-size:11px;font-weight:600;color:#334155;"><?php echo number_format($it['stock']); ?> <?php echo htmlspecialchars($it['unit']); ?></span>
                        </td>
                        <td style="text-align:center;">
                            <span class="status-badge" style="background:<?php echo $display_color; ?>20;color:<?php echo $display_color; ?>;border:1px solid <?php echo $display_color; ?>40;">
                                <?php echo htmlspecialchars($display_status); ?>
                            </span>
                        </td>
                        <td style="font-size:11px;color:#64748b;"><?php echo $ts; ?></td>
                        <td style="text-align:center;">
                            <button type="button" class="txn-btn primary sm" onclick='viewDetails(<?php echo htmlspecialchars(json_encode($it), ENT_QUOTES); ?>)'><i class="fas fa-eye"></i> View</button>
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

<!-- ══ TAB 2: STOCK ALERTS ══ -->
<div class="inv-card" id="section-alerts" style="display:none;">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-bell"></i> Stock Alerts</div>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="cust-table" style="width:100%;">
                <thead>
                    <tr style="background:#002F70; color:#fff;">
                        <th style="padding:10px 12px;">Product</th>
                        <th style="padding:10px 12px; text-align:center;">Current Stock</th>
                        <th style="padding:10px 12px; text-align:center;">Reorder Level</th>
                        <th style="padding:10px 12px; text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $alert_items = array_filter($js_items, function($it) {
                    return in_array($it['status_key'], ['low', 'critical', 'out'], true);
                });
                if (empty($alert_items)):
                ?>
                    <tr><td colspan="4" style="text-align:center; padding:32px; color:#64748b;"><i class="fas fa-check-circle" style="color:#16a34a; font-size:1.8em; display:block; margin-bottom:8px;"></i> No stock alerts found. All items are currently at optimal stock levels.</td></tr>
                <?php else: ?>
                    <?php foreach ($alert_items as $ait):
                        $ait_status = ($ait['status_key'] === 'out') ? 'Out of Stock' : (($ait['status_key'] === 'low' || $ait['status_key'] === 'critical') ? 'Low Stock' : 'Available');
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 12px;">
                            <strong><?php echo htmlspecialchars($ait['name']); ?></strong>
                            <div style="font-size:11px; color:#64748b;">SKU: <?php echo htmlspecialchars($ait['sku']); ?> &middot; <?php echo htmlspecialchars($ait['category']); ?></div>
                        </td>
                        <td style="padding:10px 12px; text-align:center; font-weight:700; font-size:13px; color:#0f172a;">
                            <?php echo number_format($ait['stock']); ?> <?php echo htmlspecialchars($ait['unit']); ?>
                        </td>
                        <td style="padding:10px 12px; text-align:center; font-weight:700; color:#dc2626;">
                            <?php echo number_format($ait['reorder']); ?> <?php echo htmlspecialchars($ait['unit']); ?>
                        </td>
                        <td style="padding:10px 12px; text-align:center;">
                            <span class="status-badge" style="background:<?php echo $ait['color']; ?>20; color:<?php echo $ait['color']; ?>; border:1px solid <?php echo $ait['color']; ?>40;">
                                <?php echo htmlspecialchars($ait_status); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══ TAB: STOCK IN (READ ONLY) ══ -->
<div class="inv-card" id="section-stockin" style="display:none;">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-arrow-down"></i> Approved Stock-In Records (Read Only)</div>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="cust-table" style="width:100%;">
                <thead>
                    <tr style="background:#002F70; color:#fff;">
                        <th style="padding:10px 12px;">Stock-In No.</th>
                        <th style="padding:10px 12px;">Product</th>
                        <th style="padding:10px 12px; text-align:center;">Qty Received</th>
                        <th style="padding:10px 12px; text-align:center;">Batch</th>
                        <th style="padding:10px 12px; text-align:center;">Date</th>
                        <th style="padding:10px 12px; text-align:center;">Received By</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($stock_in_list)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:32px; color:#64748b;"><i class="fas fa-info-circle" style="font-size:1.8em; display:block; margin-bottom:8px;"></i> No approved stock-in records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($stock_in_list as $sin):
                        $sdate = !empty($sin['date_received']) ? (new DateTime($sin['date_received']))->format('M d, Y h:i A') : '—';
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 12px;"><code style="font-size:11px; font-weight:700; color:#002F70;"><?php echo htmlspecialchars($sin['stock_in_no']); ?></code></td>
                        <td style="padding:10px 12px;"><strong><?php echo htmlspecialchars($sin['product_name']); ?></strong></td>
                        <td style="padding:10px 12px; text-align:center; font-weight:700; color:#16a34a; font-size:13px;">+<?php echo number_format($sin['qty_received']); ?></td>
                        <td style="padding:10px 12px; text-align:center;"><span style="background:#f1f5f9; color:#475569; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;"><?php echo htmlspecialchars($sin['batch_no']); ?></span></td>
                        <td style="padding:10px 12px; text-align:center; font-size:11px; color:#64748b;"><?php echo $sdate; ?></td>
                        <td style="padding:10px 12px; text-align:center; font-weight:600; color:#334155;"><?php echo htmlspecialchars($sin['received_by']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
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
            <button type="button" class="txn-btn primary" onclick="printProductDetails()"><i class="fas fa-print"></i> Print</button>
            <button type="button" class="txn-btn muted" onclick="closeVd()">Close</button>
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
    <div id="srPopupIcon" style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-check" id="srPopupIconI" style="color:#fff;font-size:22px;"></i>
    </div>
    <h3 id="srPopupTitle" style="margin:0 0 6px;color:#28a745;">REQUEST SUBMITTED!</h3>
    <p style="margin:0 0 6px;color:#333;font-size:13px;" id="srSuccessMsg">Your stock request is now <strong>Pending</strong> Manager review.</p>
    <p id="srPopupStatusRow" style="margin:0 0 18px;font-size:12px;color:#6c757d;">Status: <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-weight:700;">PENDING</span></p>
    <button onclick="closeSrSuccess()" class="txn-btn primary">OK</button>
</div>

<script>
var allMerchData = <?php echo json_encode(array_values($js_items), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var stockInListData = <?php echo json_encode(array_values($stock_in_list), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var _srPreselect = null;

// ── Filter / Sort ─────────────────────────────────────────────
function applyFilters() {
    var q      = (document.getElementById('merchSearch')?.value || '').toLowerCase().trim();
    var cat    = (document.getElementById('filterCategory')?.value || '').toLowerCase().trim();
    var brand  = (document.getElementById('filterBrand')?.value || '').toLowerCase().trim();
    var unit   = (document.getElementById('filterUnit')?.value || '').toLowerCase().trim();
    var stat   = (document.getElementById('filterStatus')?.value || '').toLowerCase().trim();
    var sortBy = document.getElementById('sortBy')?.value || 'default';

    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        var name    = (r.dataset.name || '').toLowerCase();
        var sku     = (r.dataset.sku || '').toLowerCase();
        var rcat    = (r.dataset.category || '').toLowerCase();
        var rbrand  = (r.dataset.brand || '').toLowerCase();
        var runit   = (r.dataset.unit || '').toLowerCase();
        var rstat   = (r.dataset.status || '').toLowerCase();
        var rfilter = (r.dataset.filterStatus || rstat).toLowerCase();

        var matchC = !cat || rcat === cat;
        var matchB = !brand || rbrand === brand;
        var matchU = !unit || runit === unit;

        var matchS = true;
        if (stat) {
            if (stat === 'available' || stat === 'ok') {
                matchS = (rstat === 'ok' || rstat === 'available' || rfilter === 'available');
            } else if (stat === 'low') {
                matchS = (rstat === 'low' || rstat === 'critical');
            } else if (stat === 'out' || stat === 'out of stock') {
                matchS = (rstat === 'out');
            } else if (stat === 'warning') {
                matchS = (rstat === 'low' || rstat === 'critical' || rstat === 'out');
            } else {
                matchS = (rstat === stat || rfilter === stat);
            }
        }

        var matchQ = true;
        if (q) {
            matchQ = (name.indexOf(q) !== -1 || sku.indexOf(q) !== -1 || rcat.indexOf(q) !== -1 || rbrand.indexOf(q) !== -1 || runit.indexOf(q) !== -1 || rstat.indexOf(q) !== -1);
        }

        var visible = matchQ && matchC && matchB && matchU && matchS;
        if (visible) {
            r.classList.remove('search-hidden');
            r.style.display = '';
        } else {
            r.classList.add('search-hidden');
            r.style.display = 'none';
        }
    });

    // Handle Category headers & sorting
    var tbody = document.getElementById('merchTableBody');
    if (!tbody) return;

    if (sortBy === 'default') {
        var rows = Array.from(tbody.querySelectorAll('tr'));
        var currentHeader = null;
        var hasVisibleItems = false;

        rows.forEach(function(r) {
            if (r.classList.contains('cat-header')) {
                if (currentHeader) {
                    currentHeader.style.display = hasVisibleItems ? '' : 'none';
                    if (!hasVisibleItems) currentHeader.classList.add('search-hidden');
                    else currentHeader.classList.remove('search-hidden');
                }
                currentHeader = r;
                hasVisibleItems = false;
            } else if (r.classList.contains('merch-row')) {
                if (!r.classList.contains('search-hidden')) {
                    hasVisibleItems = true;
                }
            }
        });
        if (currentHeader) {
            currentHeader.style.display = hasVisibleItems ? '' : 'none';
            if (!hasVisibleItems) currentHeader.classList.add('search-hidden');
            else currentHeader.classList.remove('search-hidden');
        }
    } else {
        // Global sort mode — hide category headers
        Array.from(tbody.querySelectorAll('.cat-header')).forEach(function(h) {
            h.style.display = 'none';
            h.classList.add('search-hidden');
        });

        // Perform in-place sorting of merch-rows
        var merchRows = Array.from(tbody.querySelectorAll('.merch-row'));
        merchRows.sort(function(a, b) {
            if (sortBy === 'name_asc') {
                return (a.dataset.name || '').localeCompare(b.dataset.name || '');
            } else if (sortBy === 'name_desc') {
                return (b.dataset.name || '').localeCompare(a.dataset.name || '');
            } else if (sortBy === 'stock_asc') {
                return parseFloat(a.dataset.stock || 0) - parseFloat(b.dataset.stock || 0);
            } else if (sortBy === 'stock_desc') {
                return parseFloat(b.dataset.stock || 0) - parseFloat(a.dataset.stock || 0);
            } else if (sortBy === 'newest') {
                return (b.dataset.updated || '').localeCompare(a.dataset.updated || '');
            }
            return 0;
        });
        merchRows.forEach(function(r) { tbody.appendChild(r); });
    }

    // Trigger pagination refresh
    if (window.tablePaginationTriggers && window.tablePaginationTriggers['merchTable']) {
        window.tablePaginationTriggers['merchTable']();
    } else if (window.setTablePage) {
        window.setTablePage('merchTable', 1);
    }
}


function resetFilters() {
    document.getElementById('merchSearch').value = '';
    // Reset hidden selects
    document.getElementById('filterCategory').value = '';
    document.getElementById('filterBrand').value = '';
    document.getElementById('filterUnit').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('sortBy').value = 'default';
    // Reset custom dropdowns
    cddSet('cdd-category', '', 'All Categories');
    cddSet('cdd-brand', '', 'All Brands');
    cddSet('cdd-unit', '', 'All Units');
    cddSet('cdd-status', '', 'All Statuses');
    cddSet('cdd-sort', 'default', 'Default Sort');
    ['filterCategory','filterBrand','filterUnit','filterStatus','sortBy'].forEach(function(id) {
        var select = document.getElementById(id);
        if (select) select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    // Clear card highlights too
    document.querySelectorAll('.inv-stat-card').forEach(function(c){ c.classList.remove('card-active'); });
    // Trigger sort reset
    applyFilters();
}

// ── Card filter shortcut ───────────────────────────────────────
function filterByCard(statusKey, cardEl) {
    var select = document.getElementById('filterStatus');
    var isActive = cardEl.classList.contains('card-active');

    // Remove active state from all cards
    document.querySelectorAll('.inv-stat-card').forEach(function(c){
        c.classList.remove('card-active');
        c.style.borderColor = '';
    });

    if (isActive || statusKey === '') {
        select.value = '';
        cddSet('cdd-status', '', 'All Statuses');
    } else {
        cardEl.classList.add('card-active');
        if (statusKey === 'warning') {
            ['card-low','card-critical','card-out'].forEach(function(id) {
                var c = document.getElementById(id);
                if (c) c.classList.add('card-active');
            });
        }
        select.value = statusKey;
        cddSet('cdd-status', statusKey, statusKey === 'warning' ? 'Stock Alerts' : statusKey);
    }

    select.dispatchEvent(new Event('change', { bubbles: true }));
    applyFilters();

    var card = document.querySelector('.inv-card');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

// ── Tabs Navigation Switcher ─────────────────────────────────
function switchInvTab(tab) {
    var btnOv  = document.getElementById('tab-overview');
    var btnStk = document.getElementById('tab-stockin');
    var btnAlt = document.getElementById('tab-alerts');

    var secOv  = document.getElementById('section-overview');
    var secStk = document.getElementById('section-stockin');
    var secAlt = document.getElementById('section-alerts');

    [btnOv, btnStk, btnAlt].forEach(function(b) {
        if (!b) return;
        b.classList.remove('active');
        b.style.setProperty('background', '#ffffff', 'important');
        b.style.setProperty('background-color', '#ffffff', 'important');
        b.style.setProperty('color', '#334155', 'important');
        b.style.setProperty('border', '1px solid #cbd5e1', 'important');
    });
    [secOv, secStk, secAlt].forEach(function(s) {
        if (!s) return;
        s.style.display = 'none';
    });

    if (tab === 'overview') {
        if (btnOv) {
            btnOv.classList.add('active');
            btnOv.style.setProperty('background', '#002F70', 'important');
            btnOv.style.setProperty('background-color', '#002F70', 'important');
            btnOv.style.setProperty('color', '#ffffff', 'important');
            btnOv.style.setProperty('border', '1px solid #002F70', 'important');
        }
        if (secOv) secOv.style.display = 'block';
    } else if (tab === 'stockin') {
        if (btnStk) {
            btnStk.classList.add('active');
            btnStk.style.setProperty('background', '#002F70', 'important');
            btnStk.style.setProperty('background-color', '#002F70', 'important');
            btnStk.style.setProperty('color', '#ffffff', 'important');
            btnStk.style.setProperty('border', '1px solid #002F70', 'important');
        }
        if (secStk) secStk.style.display = 'block';
    } else {
        if (btnAlt) {
            btnAlt.classList.add('active');
            btnAlt.style.setProperty('background', '#002F70', 'important');
            btnAlt.style.setProperty('background-color', '#002F70', 'important');
            btnAlt.style.setProperty('color', '#ffffff', 'important');
            btnAlt.style.setProperty('border', '1px solid #002F70', 'important');
        }
        if (secAlt) secAlt.style.display = 'block';
    }
}


// ── View Details ──────────────────────────────────────────────
function viewDetails(it) {
    var lastUpdatedStr = it.last_updated ? (new Date(it.last_updated)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    var matchingStockIns = (window.stockInListData || []).filter(function(sin) {
        return parseInt(sin.product_id || 0) === parseInt(it.id) || (sin.product_name && sin.product_name.toLowerCase() === (it.name || '').toLowerCase());
    });
    var stockInRows = '';
    if (matchingStockIns.length > 0) {
        stockInRows = matchingStockIns.slice(0, 10).map(function(sin) {
            var dStr = sin.date_received ? (new Date(sin.date_received)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
            return '<tr>' +
                '<td style="padding:6px 8px; font-weight:700; color:#002F70;">' + escHtml(sin.stock_in_no || ('SI-' + String(sin.id).padStart(5, '0'))) + '</td>' +
                '<td style="padding:6px 8px; text-align:center; font-size:11px; color:#64748b;">' + escHtml(dStr) + '</td>' +
                '<td style="padding:6px 8px; text-align:right; font-weight:700; color:#16a34a;">+' + Number(sin.qty_received || 0).toLocaleString() + '</td>' +
                '</tr>';
        }).join('');
    } else {
        stockInRows = '<tr><td colspan="3" style="text-align:center; color:#94a3b8; padding:12px;">No recent stock-in history available.</td></tr>';
    }

    document.getElementById('vdContent').innerHTML =
        '<h4 style="margin:0 0 10px; color:#002F70; font-size:12px; font-weight:700; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px;"><i class="fas fa-info-circle"></i> Product Information</h4>' +
        '<div class="vd-grid">' +
        vdRow('SKU', '<code>' + escHtml(it.sku || '—') + '</code>') +
        vdRow('Product Name', '<strong>' + escHtml(it.name) + '</strong>') +
        vdRow('Category', escHtml(it.category)) +
        vdRow('Brand', escHtml(it.brand || 'Petron')) +
        vdRow('Unit of Measure (UOM)', escHtml(it.unit)) +
        vdRow('Barcode', '<code>' + escHtml(it.barcode || '—') + '</code>') +
        '</div>' +
        '<h4 style="margin:16px 0 10px; color:#002F70; font-size:12px; font-weight:700; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px;"><i class="fas fa-cubes"></i> Stock Information</h4>' +
        '<div class="vd-grid">' +
        vdRow('Current Stock', '<strong style="font-size:16px; color:#0f172a;">' + Number(it.stock).toLocaleString() + '</strong> ' + escHtml(it.unit)) +
        vdRow('Reorder Level', '<strong style="color:#dc2626;">' + Number(it.reorder).toLocaleString() + '</strong> ' + escHtml(it.unit)) +
        vdRow('Status', '<span class="status-badge" style="background:' + it.color + '20; color:' + it.color + '; border:1px solid ' + it.color + '40;">' + escHtml(it.status) + '</span>') +
        '</div>' +
        '<h4 style="margin:16px 0 8px; color:#002F70; font-size:12px; font-weight:700; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px;"><i class="fas fa-arrow-down"></i> Recent Stock-In (Read-Only Reference)</h4>' +
        '<table style="width:100%; border-collapse:collapse; font-size:11.5px; border:1px solid #cbd5e1; border-radius:6px; overflow:hidden;">' +
        '<thead><tr style="background:#002F70; color:#fff;">' +
        '<th style="padding:6px 8px; text-align:left;">Stock-In No.</th>' +
        '<th style="padding:6px 8px; text-align:center;">Date Received</th>' +
        '<th style="padding:6px 8px; text-align:right;">Quantity Received</th>' +
        '</tr></thead>' +
        '<tbody>' + stockInRows + '</tbody>' +
        '</table>';

    document.getElementById('vdModal').classList.add('open');
    document.body.classList.add('modal-open');
}

function printProductDetails() {
    var content = document.getElementById('vdContent').innerHTML;
    var printWin = window.open('', '_blank', 'width=750,height=600');
    printWin.document.write(
        '<html><head><title>Product Details Printout</title>' +
        '<style>' +
        'body { font-family: Arial, sans-serif; padding: 24px; color: #1e293b; }' +
        'h2 { color: #002F70; border-bottom: 2px solid #002F70; padding-bottom: 8px; margin-bottom: 16px; font-size: 18px; }' +
        '.vd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }' +
        '.vd-row { display: flex; flex-direction: column; }' +
        '.vd-label { font-size: 10px; font-weight: bold; color: #64748b; text-transform: uppercase; }' +
        '.vd-val { font-size: 13px; font-weight: 600; margin-top: 2px; }' +
        'table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 11px; }' +
        'th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }' +
        'th { background: #f1f5f9; color: #002F70; font-weight: bold; }' +
        '.status-badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }' +
        '</style></head><body>' +
        '<h2>PETRON STATION MANAGEMENT SYSTEM — PRODUCT DETAILS</h2>' +
        content +
        '<div style="margin-top:30px; font-size:11px; color:#64748b; text-align:right;">Printed on: ' + (new Date()).toLocaleString() + '</div>' +
        '</body></html>'
    );
    printWin.document.close();
    printWin.focus();
    setTimeout(function() { printWin.print(); printWin.close(); }, 300);
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

        var popupIcon = document.getElementById('srPopupIcon');
        var popupTitle = document.getElementById('srPopupTitle');
        var popupStatus = document.getElementById('srPopupStatusRow');

        if (res.success) {
            if (popupIcon) {
                popupIcon.style.background = 'linear-gradient(135deg,#28a745,#20c997)';
                popupIcon.innerHTML = '<i class="fas fa-check" style="color:#fff;font-size:22px;"></i>';
            }
            if (popupTitle) {
                popupTitle.style.color = '#28a745';
                popupTitle.innerText = 'REQUEST SUBMITTED!';
            }
            if (popupStatus) popupStatus.style.display = 'block';

            var srNo = res.request_no || '';
            var cnt  = res.inserted_count || items.length;
            var msg  = 'Successfully submitted stock requests for <strong>' + cnt + '</strong> item' + (cnt !== 1 ? 's' : '') + '.';
            if (srNo) msg += '<br><span style="font-size:12px;color:#64748b;">Request No: <strong>' + escHtml(srNo) + '</strong></span>';
            if (res.message && res.message.indexOf('skipped') !== -1) {
                msg += '<br><small style="color:#d97706;">' + escHtml(res.message.split('Note:')[1] || '') + '</small>';
            }
            document.getElementById('srSuccessMsg').innerHTML = msg;
        } else {
            if (popupIcon) {
                popupIcon.style.background = 'linear-gradient(135deg,#dc2626,#ef4444)';
                popupIcon.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#fff;font-size:22px;"></i>';
            }
            if (popupTitle) {
                popupTitle.style.color = '#dc2626';
                popupTitle.innerText = 'SUBMISSION ERROR';
            }
            if (popupStatus) popupStatus.style.display = 'none';

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

function setupDownwardFilterSelects(selectors) {
    var selects = [];
    selectors.forEach(function(selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) selects.push(el);
    });

    selects.forEach(function(select) {
        if (!select || select.dataset.forceDownReady === '1') return;
        select.dataset.forceDownReady = '1';

        var wrap = document.createElement('div');
        wrap.className = 'fd-select';
        var computed = window.getComputedStyle(select);
        if (computed.minWidth && computed.minWidth !== '0px') wrap.style.minWidth = computed.minWidth;
        if (select.style.width) wrap.style.width = select.style.width;
        if (select.style.marginLeft) {
            wrap.style.marginLeft = select.style.marginLeft;
            select.style.marginLeft = '';
        }

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'fd-select-trigger';
        var label = document.createElement('span');
        label.className = 'fd-select-label';
        var arrow = document.createElement('i');
        arrow.className = 'fas fa-chevron-down fd-select-arrow';
        trigger.appendChild(label);
        trigger.appendChild(arrow);

        var menu = document.createElement('div');
        menu.className = 'fd-select-menu';
        Array.from(select.options).forEach(function(option) {
            var item = document.createElement('div');
            item.className = 'fd-select-option';
            item.dataset.value = option.value;
            item.textContent = option.textContent;
            item.addEventListener('click', function() {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                syncLabel();
                wrap.classList.remove('fd-open');
            });
            menu.appendChild(item);
        });

        function syncLabel() {
            var selected = select.options[select.selectedIndex];
            label.textContent = selected ? selected.textContent.trim() : '';
            Array.from(menu.querySelectorAll('.fd-select-option')).forEach(function(item) {
                item.classList.toggle('fd-active', item.dataset.value === select.value);
            });
        }

        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            document.querySelectorAll('.fd-select.fd-open').forEach(function(openWrap) {
                if (openWrap !== wrap) openWrap.classList.remove('fd-open');
            });
            wrap.classList.toggle('fd-open');
        });

        select.addEventListener('change', syncLabel);
        select.classList.add('fd-select-source');
        select.parentNode.insertBefore(wrap, select.nextSibling);
        wrap.appendChild(trigger);
        wrap.appendChild(menu);
        syncLabel();
    });

    if (!window.__forceDownSelectCloseBound) {
        window.__forceDownSelectCloseBound = true;
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.fd-select')) {
                document.querySelectorAll('.fd-select.fd-open').forEach(function(wrap) {
                    wrap.classList.remove('fd-open');
                });
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    ['srModal','vdModal','srSuccessOverlay','srSuccessPopup'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    setupDownwardFilterSelects([
        '#filterCategory',
        '#filterBrand',
        '#filterUnit',
        '#filterStatus',
        '#sortBy'
    ]);
    setupTablePagination('merchTable', 'merchRowsLimit', 'merchPagination', 50);
    applyFilters();
});

// ── Custom Dropdown (CDD) Logic ────────────────────────────────
// Maps cdd id → hidden select id + change handler
var cddMap = {
    'cdd-category': { selectId: 'filterCategory', onChange: applyFilters },
    'cdd-brand':    { selectId: 'filterBrand',    onChange: applyFilters },
    'cdd-unit':     { selectId: 'filterUnit',     onChange: applyFilters },
    'cdd-status':   { selectId: 'filterStatus',   onChange: applyFilters },
    'cdd-sort':     { selectId: 'sortBy',         onChange: function(){ document.getElementById('sortBy').dispatchEvent(new Event('change')); } }
};

function cddToggle(id) {
    var wrap = document.getElementById(id);
    var isOpen = wrap.classList.contains('cdd-open');
    // Close all
    document.querySelectorAll('.cdd-wrap.cdd-open').forEach(function(w){ w.classList.remove('cdd-open'); });
    if (!isOpen) wrap.classList.add('cdd-open');
}

function cddSet(cddId, val, label) {
    var wrap = document.getElementById(cddId);
    if (!wrap) return;
    wrap.querySelector('.cdd-label').textContent = label;
    wrap.querySelectorAll('.cdd-item').forEach(function(item){
        item.classList.toggle('cdd-active', item.dataset.val === val);
    });
    var cfg = cddMap[cddId];
    if (cfg) {
        var sel = document.getElementById(cfg.selectId);
        if (sel) sel.value = val;
    }
}

// Wire up cdd item clicks
document.querySelectorAll('.cdd-wrap').forEach(function(wrap) {
    var id = wrap.id;
    wrap.querySelectorAll('.cdd-item').forEach(function(item) {
        item.addEventListener('click', function() {
            var val = item.dataset.val;
            var label = item.textContent.trim();
            cddSet(id, val, label || (id === 'cdd-sort' ? 'Default Sort' :
                id === 'cdd-category' ? 'All Categories' :
                id === 'cdd-brand' ? 'All Brands' :
                id === 'cdd-unit' ? 'All Units' :
                id === 'cdd-status' ? 'All Statuses' : label));
            wrap.classList.remove('cdd-open');
            var cfg = cddMap[id];
            if (cfg && cfg.onChange) cfg.onChange();
        });
    });
});

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.cdd-wrap')) {
        document.querySelectorAll('.cdd-wrap.cdd-open').forEach(function(w){ w.classList.remove('cdd-open'); });
    }
});
</script>
</div> <!-- /stock-page -->
<?php include __DIR__ . '/../partials/footer.php'; ?>

