<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_stock_monitoring';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['admin','superadmin'])) { header('Location: admin_dashboard.php'); exit; }

// ── AJAX Endpoint for Details ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_details') {
    header('Content-Type: application/json');
    $pid = (int)($_GET['product_id'] ?? 0);
    try {
        // Fetch Total Deliveries
        $s1 = $pdo->prepare("SELECT COALESCE(SUM(qty_received), 0) FROM merchandise_stock_in WHERE product_id = ? AND station_id = ?");
        $s1->execute([$pid, $station_id]);
        $total_deliveries = (float)$s1->fetchColumn();

        // Fetch Total Released/Sold
        $s2 = $pdo->prepare("
            SELECT COALESCE(SUM(mti.quantity), 0) 
            FROM merchandise_transaction_items mti 
            JOIN merchandise_transactions mt ON mti.transaction_id = mt.id 
            WHERE mti.product_id = ? AND mt.station_id = ? 
              AND mt.validation_status IN ('Official','Completed','Approved','Adjusted') 
              AND mti.item_type = 'merchandise'
        ");
        $s2->execute([$pid, $station_id]);
        $total_released = (float)$s2->fetchColumn();

        // Fetch Last Delivery Date
        $s3 = $pdo->prepare("SELECT MAX(encoded_at) FROM merchandise_stock_in WHERE product_id = ? AND station_id = ?");
        $s3->execute([$pid, $station_id]);
        $last_delivery_date = $s3->fetchColumn() ?: '—';

        // Fetch Last Stock Movement Purpose
        $s4 = $pdo->prepare("
            SELECT 'Delivery' AS type, encoded_at AS date, condition_flag AS notes
            FROM merchandise_stock_in
            WHERE product_id = ? AND station_id = ?
            UNION ALL
            SELECT 'Sales' AS type, mt.transaction_date AS date, mt.transaction_id AS notes
            FROM merchandise_transaction_items mti
            JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
            WHERE mti.product_id = ? AND mt.station_id = ?
            ORDER BY date DESC
            LIMIT 1
        ");
        $s4->execute([$pid, $station_id, $pid, $station_id]);
        $last_movement = $s4->fetch(PDO::FETCH_ASSOC);
        $last_purpose = '—';
        if ($last_movement) {
            $last_purpose = $last_movement['type'] . ' (' . ($last_movement['notes'] ?: 'No details') . ')';
        }

        echo json_encode([
            'success' => true,
            'total_deliveries' => $total_deliveries,
            'total_released' => $total_released,
            'last_delivery_date' => $last_delivery_date !== '—' ? date('M d, Y H:i', strtotime($last_delivery_date)) : '—',
            'last_purpose' => $last_purpose
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Filters ──────────────────────────────────────────────────────
$search   = trim($_GET['search']   ?? '');
$cat_f    = trim($_GET['category'] ?? '');
$status_f = trim($_GET['status']   ?? '');
$supp_f   = trim($_GET['supplier'] ?? '');
$date_f   = trim($_GET['date_updated'] ?? '');

// ── Categories & Suppliers for dropdowns ────────────────────────
$all_cats  = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE category NOT IN ('Fuel','') AND category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$all_supps = $pdo->query("SELECT DISTINCT supplier FROM inventory_products WHERE supplier IS NOT NULL AND supplier != '' ORDER BY supplier")->fetchAll(PDO::FETCH_COLUMN);

// ── Fetch all items ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT ip.id, ip.sku, ip.product_name, ip.category, ip.supplier,
           COALESCE(si.unit, 'pcs') AS unit,
           COALESCE(si.stock_level, ip.stock, 0) AS current_stock,
           COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
           COALESCE(si.capacity, ip.max_stock, 100) AS max_stock,
           COALESCE(ip.unit_cost, 0) AS unit_cost,
           COALESCE(ip.unit_price, 0) AS unit_price,
           COALESCE(si.status, 'active') AS item_status,
           COALESCE(si.last_updated, ip.updated_at, ip.created_at) AS last_updated
    FROM inventory_products ip
    LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
    WHERE ip.category NOT IN ('Fuel')
    ORDER BY ip.category, ip.product_name
");
$stmt->execute([$station_id]);
$all_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── KPIs & filtering ─────────────────────────────────────────────
$kpi = ['total'=>0,'available'=>0,'low'=>0,'out'=>0,'critical'=>0,'value'=>0.0];
$rows = [];

foreach ($all_items as $item) {
    $stock   = (float)$item['current_stock'];
    $reorder = (float)$item['reorder_level'];
    $max     = (float)$item['max_stock'];

    if ($stock <= 0)            $st = 'out';
    elseif ($stock <= $reorder * 0.5) $st = 'critical';
    elseif ($stock <= $reorder) $st = 'low';
    else                         $st = 'available';

    if (strtolower($item['item_status']) === 'inactive') $st = 'inactive';

    $kpi['total']++;
    if ($st === 'available') $kpi['available']++;
    elseif ($st === 'low')   $kpi['low']++;
    elseif ($st === 'out')   $kpi['out']++;
    elseif ($st === 'critical') $kpi['critical']++;
    $kpi['value'] += $stock * (float)$item['unit_price'];

    // Apply filters
    if ($search !== '') {
        $sl = strtolower($search);
        if (strpos(strtolower($item['product_name']), $sl) === false
         && strpos(strtolower($item['sku'] ?? ''), $sl) === false
         && strpos(strtolower($item['supplier'] ?? ''), $sl) === false) continue;
    }
    if ($cat_f  !== '' && $item['category'] !== $cat_f)  continue;
    if ($supp_f !== '' && $item['supplier'] !== $supp_f)  continue;
    if ($status_f !== '' && $st !== $status_f)             continue;
    if ($date_f !== '') {
        $upd = date('Y-m-d', strtotime($item['last_updated']));
        if ($upd !== $date_f) continue;
    }

    $item['_status'] = $st;
    $rows[] = $item;
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.int-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; margin-top: 0 !important; padding-top: 16px; padding-bottom: 16px; border-bottom: 2px solid #e9ecef; }
.int-head h1{font-size:22px!important;font-weight:700!important;color:#00264D!important;margin:0!important;text-transform:uppercase!important;display:flex;align-items:center;gap:8px}
.int-head .sub{font-size:13px;color:#666;margin-top:4px}
.sm-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:22px}
.sm-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 3px rgba(0,0,0,.05);position:relative;overflow:hidden}
.sm-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%}
.sm-card.blue::before{background:#2563eb}.sm-card.blue .sm-icon{color:#2563eb}
.sm-card.green::before{background:#16a34a}.sm-card.green .sm-icon{color:#16a34a}
.sm-card.yellow::before{background:#d97706}.sm-card.yellow .sm-icon{color:#d97706}
.sm-card.red::before{background:#dc2626}.sm-card.red .sm-icon{color:#dc2626}
.sm-card.orange::before{background:#ea580c}.sm-card.orange .sm-icon{color:#ea580c}
.sm-card.purple::before{background:#7c3aed}.sm-card.purple .sm-icon{color:#7c3aed}
.sm-lbl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px}
.sm-val{font-size:20px;font-weight:800;color:#1e293b}
.sm-icon{font-size:22px;opacity:.85}
.flt-bar{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:18px}
.flt-grp{display:flex;flex-direction:column;gap:4px}
.flt-grp label{font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px}
.flt-grp input,.flt-grp select{height:35px;padding:0 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#1e293b;background:#fff}
.flt-btn{display:inline-flex;align-items:center;gap:6px;padding:0 14px;height:35px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;border:1px solid transparent;background:#fff;transition:all .15s}
.flt-btn-search{color:#002F70;border-color:#002F70}.flt-btn-search:hover{background:#002F70;color:#fff}
.flt-btn-reset{color:#6b7280;border-color:#6b7280}.flt-btn-reset:hover{background:#6b7280;color:#fff}
.flt-btn-excel{color:#1d6f42;border-color:#1d6f42}.flt-btn-excel:hover{background:#1d6f42;color:#fff}
.flt-btn-pdf{color:#dc2626;border-color:#dc2626}.flt-btn-pdf:hover{background:#dc2626;color:#fff}
.flt-btn-csv{color:#002F70;border-color:#002F70}.flt-btn-csv:hover{background:#002F70;color:#fff}
.tbl-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);margin-bottom:22px}
.tbl-hd{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e9ecef;background:#f8fafc;gap:8px;flex-wrap:wrap}
.tbl-title{font-size:14px;font-weight:700;color:#00264D;display:flex;align-items:center;gap:7px}
.sm-tbl{width:100%;border-collapse:collapse;font-size:12px}
.sm-tbl thead tr{background:#002F70}
.sm-tbl thead th{padding:9px 11px;font-weight:700;color:#fff;text-transform:uppercase;font-size:11px;letter-spacing:.4px}
.sm-tbl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s}
.sm-tbl tbody tr:hover td{background:#f8fafc}
.sm-tbl tbody td{padding:9px 11px;color:#334155;vertical-align:middle}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.badge-ok{background:#dcfce7;color:#15803d}
.badge-low{background:#fef3c7;color:#b45309}
.badge-crit{background:#ffedd5;color:#9a3412}
.badge-out{background:#fee2e2;color:#b91c1c}
.badge-na{background:#f1f5f9;color:#475569}
.txn-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;text-decoration:none;border:1px solid transparent;background:#fff;transition:all .18s;white-space:nowrap}
.txn-btn-view{color:#0284c7;border-color:#0284c7}.txn-btn-view:hover{background:#0284c7;color:#fff}
.txn-btn-hist{color:#6b7280;border-color:#6b7280}.txn-btn-hist:hover{background:#6b7280;color:#fff}
.txn-btn-print{color:#00264D;border-color:#00264D}.txn-btn-print:hover{background:#00264D;color:#fff}
.modal-ov{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;z-index:9000}
.modal-ov.show{display:flex}
.modal-box{background:#fff;border-radius:12px;padding:24px;width:520px;max-width:96vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 40px rgba(0,0,0,.15)}
.modal-box h3{margin:0 0 14px;font-size:16px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;padding-bottom:8px}
.info-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f1f5f9;font-size:13px}
.info-row strong{color:#475569;font-size:11px;font-weight:600;text-transform:uppercase}
.info-row span{color:#0f172a;font-weight:600;text-align:right}
.info-sec{font-size:11px;font-weight:700;text-transform:uppercase;color:#002F70;margin:12px 0 6px;letter-spacing:.5px;border-top:1px solid #e2e8f0;padding-top:10px}
.modal-foot{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:12px;border-top:1px solid #e2e8f0}
</style>

<!-- Page Header -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-chart-bar"></i> Stock Monitoring</h1>
        <div class="sub">Read-only stock monitoring &middot; All products &middot; <?= date('F d, Y') ?></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <button onclick="exportTableToExcel('smTbl','stock_monitoring_<?= date('Ymd') ?>')" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
        <button onclick="exportTableToCSV('smTbl','stock_monitoring_<?= date('Ymd') ?>.csv')" class="flt-btn flt-btn-csv"><i class="fas fa-file-csv"></i> CSV</button>
        <button onclick="exportTableToPDF('smTbl','Stock Monitoring Report','stock_monitoring_<?= date('Ymd') ?>')" class="flt-btn flt-btn-pdf"><i class="fas fa-file-pdf"></i> Export PDF</button>
        <button onclick="printReportArea()" class="flt-btn flt-btn-print"><i class="fas fa-print"></i> Print</button>
        <a href="admin_dashboard.php" class="flt-btn flt-btn-reset"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- Summary Cards -->
<div class="sm-cards">
    <div class="sm-card blue">
        <div><div class="sm-lbl">Total Products</div><div class="sm-val"><?= number_format($kpi['total']) ?></div></div>
        <div class="sm-icon"><i class="fas fa-box"></i></div>
    </div>
    <div class="sm-card green">
        <div><div class="sm-lbl">Available Stocks</div><div class="sm-val"><?= number_format($kpi['available']) ?></div></div>
        <div class="sm-icon"><i class="fas fa-check-circle"></i></div>
    </div>
    <div class="sm-card yellow">
        <div><div class="sm-lbl">Low Stock Items</div><div class="sm-val"><?= number_format($kpi['low']) ?></div></div>
        <div class="sm-icon"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <div class="sm-card orange">
        <div><div class="sm-lbl">Critical Stock</div><div class="sm-val"><?= number_format($kpi['critical']) ?></div></div>
        <div class="sm-icon"><i class="fas fa-fire"></i></div>
    </div>
    <div class="sm-card red">
        <div><div class="sm-lbl">Out of Stock</div><div class="sm-val"><?= number_format($kpi['out']) ?></div></div>
        <div class="sm-icon"><i class="fas fa-times-circle"></i></div>
    </div>
    <div class="sm-card purple">
        <div><div class="sm-lbl">Total Inv. Value</div><div class="sm-val" style="font-size:15px;">&#8369;<?= number_format($kpi['value'],2) ?></div></div>
        <div class="sm-icon"><i class="fas fa-coins"></i></div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" class="flt-bar">
    <div class="flt-grp" style="flex:2;min-width:170px;">
        <label>Search Product</label>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, SKU, supplier...">
    </div>
    <div class="flt-grp" style="min-width:140px;">
        <label>Category</label>
        <select name="category">
            <option value="">All Categories</option>
            <?php foreach ($all_cats as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $cat_f === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="flt-grp" style="min-width:130px;">
        <label>Stock Status</label>
        <select name="status">
            <option value="">All Statuses</option>
            <option value="available" <?= $status_f === 'available' ? 'selected' : '' ?>>Available</option>
            <option value="low"       <?= $status_f === 'low'       ? 'selected' : '' ?>>Low Stock</option>
            <option value="critical"  <?= $status_f === 'critical'  ? 'selected' : '' ?>>Critical</option>
            <option value="out"       <?= $status_f === 'out'       ? 'selected' : '' ?>>Out of Stock</option>
        </select>
    </div>
    <div class="flt-grp" style="min-width:140px;">
        <label>Supplier</label>
        <select name="supplier">
            <option value="">All Suppliers</option>
            <?php foreach ($all_supps as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $supp_f === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="flt-grp" style="min-width:130px;">
        <label>Date Updated</label>
        <input type="date" name="date_updated" value="<?= htmlspecialchars($date_f) ?>">
    </div>
    <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-search"></i> Search</button>
    <?php if ($search || $cat_f || $status_f || $supp_f || $date_f): ?>
        <a href="admin_stock_monitoring.php" class="flt-btn flt-btn-reset"><i class="fas fa-times"></i> Clear</a>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="tbl-card">
    <div class="tbl-hd">
        <div class="tbl-title"><i class="fas fa-list"></i> Stock Inventory (<?= count($rows) ?> item<?= count($rows) !== 1 ? 's' : '' ?>)</div>
    </div>
    <div style="overflow-x:auto;">
        <table class="sm-tbl" id="smTbl">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th style="text-align:right;">Current Stock</th>
                    <th style="text-align:right;">Reorder Level</th>
                    <th style="text-align:right;">Max Stock</th>
                    <th>Stock Status</th>
                    <th>Last Updated</th>
                    <th style="width:200px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="10" style="text-align:center;padding:36px;color:#94a3b8;"><i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.3;"></i>No products found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r):
                    $st  = $r['_status'];
                    $stLbl = match($st) { 'available' => 'Available', 'low' => 'Low Stock', 'critical' => 'Critical', 'out' => 'Out of Stock', default => ucfirst($st) };
                    $stCls = match($st) { 'available' => 'badge-ok', 'low' => 'badge-low', 'critical' => 'badge-crit', 'out' => 'badge-out', default => 'badge-na' };
                    $json = htmlspecialchars(json_encode([
                        'id'            => $r['id'],
                        'sku'           => $r['sku'] ?? 'N/A',
                        'product_name'  => $r['product_name'],
                        'category'      => $r['category'],
                        'unit'          => $r['unit'] ?? 'pcs',
                        'supplier'      => $r['supplier'] ?? '—',
                        'current_stock' => $r['current_stock'],
                        'reorder_level' => $r['reorder_level'],
                        'max_stock'     => $r['max_stock'],
                        'unit_cost'     => number_format((float)$r['unit_cost'],2),
                        'unit_price'    => number_format((float)$r['unit_price'],2),
                        'inv_value'     => number_format((float)$r['current_stock'] * (float)$r['unit_price'],2),
                        'status'        => $stLbl,
                        'last_updated'  => $r['last_updated'] ? date('M d, Y g:i A', strtotime($r['last_updated'])) : '—',
                    ]), ENT_QUOTES,'UTF-8');
                ?>
                <tr>
                    <td><code style="font-size:11px;font-weight:700;color:#002F70;"><?= htmlspecialchars($r['sku'] ?? 'N/A') ?></code></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['product_name']) ?></td>
                    <td style="font-size:11px;"><?= htmlspecialchars($r['category']) ?></td>
                    <td><?= htmlspecialchars($r['unit'] ?? 'pcs') ?></td>
                    <td style="text-align:right;font-weight:700;<?= $st === 'out' ? 'color:#dc2626;' : ($st === 'critical' ? 'color:#ea580c;' : '') ?>">
                        <?= number_format((float)$r['current_stock']) ?>
                    </td>
                    <td style="text-align:right;color:#64748b;"><?= number_format((float)$r['reorder_level']) ?></td>
                    <td style="text-align:right;color:#64748b;"><?= number_format((float)$r['max_stock']) ?></td>
                    <td><span class="badge <?= $stCls ?>"><?= $stLbl ?></span></td>
                    <td style="font-size:11px;color:#64748b;white-space:nowrap;"><?= $r['last_updated'] ? date('M d, Y', strtotime($r['last_updated'])) : '—' ?></td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <button class="txn-btn txn-btn-view" onclick='openDetails(<?= $json ?>)' title="View Details">
                                <i class="fas fa-eye"></i> Details
                            </button>
                            <a href="admin_inventory_history.php?product_id=<?= $r['id'] ?>" class="txn-btn txn-btn-hist" title="Stock History">
                                <i class="fas fa-history"></i> History
                            </a>
                            <a href="admin_inventory_merchandise.php?print_id=<?= $r['id'] ?>" target="_blank" class="txn-btn txn-btn-print" title="Print Stock Report">
                                <i class="fas fa-print"></i> Print
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Details Modal -->
<div id="detailsModal" class="modal-ov">
    <div class="modal-box">
        <h3><i class="fas fa-box"></i> <span id="dmTitle">Product Details</span></h3>

        <div class="info-sec">Product Information</div>
        <div class="info-row"><strong>SKU</strong><span id="dmSku">—</span></div>
        <div class="info-row"><strong>Product Name</strong><span id="dmName">—</span></div>
        <div class="info-row"><strong>Category</strong><span id="dmCat">—</span></div>
        <div class="info-row"><strong>Supplier</strong><span id="dmSupplier">—</span></div>
        <div class="info-row"><strong>Unit</strong><span id="dmUnit">—</span></div>

        <div class="info-sec">Inventory Information</div>
        <div class="info-row"><strong>Current Stock</strong><span id="dmStock">—</span></div>
        <div class="info-row"><strong>Reorder Level</strong><span id="dmReorder">—</span></div>
        <div class="info-row"><strong>Maximum Stock</strong><span id="dmMax">—</span></div>
        <div class="info-row"><strong>Unit Cost</strong><span id="dmCost">—</span></div>
        <div class="info-row"><strong>Unit Price</strong><span id="dmPrice">—</span></div>
        <div class="info-row"><strong>Inventory Value</strong><span id="dmValue" style="font-weight:800;color:#002F70;">—</span></div>
        <div class="info-row"><strong>Status</strong><span id="dmStatus">—</span></div>

        <div class="info-sec">Movement Summary</div>
        <div class="info-row"><strong>Total Deliveries</strong><span id="dmTotalDeliveries" style="font-weight:700;color:#16a34a;">Loading...</span></div>
        <div class="info-row"><strong>Total Released/Sold</strong><span id="dmTotalReleased" style="font-weight:700;color:#dc2626;">Loading...</span></div>
        <div class="info-row"><strong>Last Delivery Date</strong><span id="dmLastDelivery">Loading...</span></div>
        <div class="info-row"><strong>Last Stock Movement Purpose</strong><span id="dmLastPurpose">Loading...</span></div>

        <div class="info-sec">Timestamps</div>
        <div class="info-row"><strong>Last Updated</strong><span id="dmUpdated">—</span></div>

        <div class="modal-foot">
            <button onclick="document.getElementById('detailsModal').classList.remove('show')" class="flt-btn flt-btn-reset">Close</button>
        </div>
    </div>
</div>

<script>
function openDetails(d) {
    document.getElementById('dmTitle').textContent   = d.product_name;
    document.getElementById('dmSku').textContent     = d.sku;
    document.getElementById('dmName').textContent    = d.product_name;
    document.getElementById('dmCat').textContent     = d.category;
    document.getElementById('dmSupplier').textContent = d.supplier;
    document.getElementById('dmUnit').textContent    = d.unit;
    document.getElementById('dmStock').textContent   = d.current_stock + ' ' + d.unit;
    document.getElementById('dmReorder').textContent = d.reorder_level + ' ' + d.unit;
    document.getElementById('dmMax').textContent     = d.max_stock + ' ' + d.unit;
    document.getElementById('dmCost').textContent    = '₱' + d.unit_cost;
    document.getElementById('dmPrice').textContent   = '₱' + d.unit_price;
    document.getElementById('dmValue').textContent   = '₱' + d.inv_value;
    document.getElementById('dmStatus').textContent  = d.status;
    document.getElementById('dmUpdated').textContent = d.last_updated;

    // Reset movement details loader
    document.getElementById('dmTotalDeliveries').textContent = 'Loading...';
    document.getElementById('dmTotalReleased').textContent   = 'Loading...';
    document.getElementById('dmLastDelivery').textContent   = 'Loading...';
    document.getElementById('dmLastPurpose').textContent    = 'Loading...';

    // Show modal
    document.getElementById('detailsModal').classList.add('show');

    // Fetch dynamic movement metrics
    fetch('admin_stock_monitoring.php?action=get_details&product_id=' + d.id)
        .then(response => response.json())
        .then(res => {
            if (res.success) {
                document.getElementById('dmTotalDeliveries').textContent = res.total_deliveries + ' ' + d.unit;
                document.getElementById('dmTotalReleased').textContent   = res.total_released + ' ' + d.unit;
                document.getElementById('dmLastDelivery').textContent   = res.last_delivery_date;
                document.getElementById('dmLastPurpose').textContent    = res.last_purpose;
            } else {
                console.error(res.error);
                setErrorFields();
            }
        })
        .catch(err => {
            console.error(err);
            setErrorFields();
        });
}

function setErrorFields() {
    document.getElementById('dmTotalDeliveries').textContent = 'Error';
    document.getElementById('dmTotalReleased').textContent   = 'Error';
    document.getElementById('dmLastDelivery').textContent   = 'Error';
    document.getElementById('dmLastPurpose').textContent    = 'Error';
}

document.getElementById('detailsModal').addEventListener('click', function(e){
    if(e.target === this) this.classList.remove('show');
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
