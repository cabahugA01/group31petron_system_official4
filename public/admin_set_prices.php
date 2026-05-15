<?php
$page_id = 'admin_set_prices';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// ── Access control ──────────────────────────────────────────────────────────
if (!in_array($role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}
if ((int)$station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

// ── Block POST for admin (read-only role) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'admin') {
    http_response_code(403);
    die('Access denied: Admins have read-only access to product pricing.');
}

// ── CSV Export ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Fetch merchandise for export
    $export_rows = [];
    try {
        $stmt = $pdo->query("
            SELECT product_name, sku, category, size, unit_cost, unit_price,
                   stock_quantity, stock
            FROM inventory_products
            WHERE category != 'Fuel'
            ORDER BY category, product_name
        ");
        $export_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $export_rows = [];
    }

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="product_pricing_' . date('Y-m-d') . '.xls"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product Name', 'SKU', 'Category', 'Size', 'Cost (PHP)', 'Price (PHP)', 'Stock', 'Margin (PHP)', 'Status']);
    foreach ($export_rows as $r) {
        $stock  = (int)($r['stock_quantity'] ?? $r['stock'] ?? 0);
        $cost   = (float)($r['unit_cost']  ?? 0);
        $price  = (float)($r['unit_price'] ?? 0);
        $margin = $price - $cost;
        $status = $stock <= 0 ? 'Out of Stock' : ($stock <= 10 ? 'Low Stock' : 'Available');
        fputcsv($out, [
            $r['product_name'],
            $r['sku'] ?? '',
            $r['category'] ?? '',
            $r['size'] ?? '',
            number_format($cost, 2, '.', ''),
            number_format($price, 2, '.', ''),
            $stock,
            number_format($margin, 2, '.', ''),
            $status,
        ]);
    }
    fclose($out);
    exit;
}

// ── Fetch station name ───────────────────────────────────────────────────────
$station_name = 'Unknown Station';
try {
    $stmt = $pdo->prepare('SELECT name FROM stations WHERE id = ? LIMIT 1');
    $stmt->execute([$station_id]);
    $station_name = $stmt->fetchColumn() ?: 'Unknown Station';
} catch (Exception $e) { /* silent */ }

// ── Fetch fuel inventory ─────────────────────────────────────────────────────
$fuel_products = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, fuel_type, price_per_liter, current_level, capacity,
               critical_level, status, last_updated, updated_by
        FROM fuel_inventory
        WHERE station_id = ?
        ORDER BY fuel_type
    ");
    $stmt->execute([$station_id]);
    $fuel_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fuel_products = [];
}

// ── Fetch merchandise grouped by category ───────────────────────────────────
$merch_by_cat   = [];
$merch_all      = [];
$merch_stats    = ['total' => 0, 'valid_price' => 0, 'below_cost' => 0, 'unpriced' => 0];
$all_categories = [];

try {
    $stmt = $pdo->query("
        SELECT id, category, product_name, sku, size, unit_cost, supplier,
               unit_price, stock_quantity, stock, created_at
        FROM inventory_products
        WHERE category != 'Fuel'
        ORDER BY category, product_name
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $cat    = $row['category'] ?? 'Uncategorized';
        $cost   = (float)($row['unit_cost']  ?? 0);
        $price  = (float)($row['unit_price'] ?? 0);
        $stock  = (int)($row['stock_quantity'] ?? $row['stock'] ?? 0);

        // Pricing stats
        $merch_stats['total']++;
        if ($price <= 0) {
            $merch_stats['unpriced']++;
        } elseif ($price < $cost) {
            $merch_stats['below_cost']++;
        } else {
            $merch_stats['valid_price']++;
        }

        $row['_cost']  = $cost;
        $row['_price'] = $price;
        $row['_stock'] = $stock;

        $merch_by_cat[$cat][] = $row;
        $merch_all[]          = $row;
        $all_categories[$cat] = true;
    }
} catch (Exception $e) {
    $merch_by_cat = [];
}

$all_categories = array_keys($all_categories);
sort($all_categories);

// ── Log page view ────────────────────────────────────────────────────────────
try {
    log_activity($pdo, $me['id'], 'View Product Pricing',
        "Admin viewed pricing for station {$station_id}");
} catch (Exception $e) { /* silent */ }

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Page-level styles ─────────────────────────────────────────────────────── */
.pricing-tabs { display: none; } /* replaced by dropdown */
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* Summary cards */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 16px 18px; text-align: center;
}
.summary-card .s-num  { font-size: 28px; font-weight: 700; line-height: 1; }
.summary-card .s-lbl  { font-size: 12px; color: #64748b; margin-top: 4px; font-weight: 500; }
.summary-card.s-total  .s-num { color: #002F6C; }
.summary-card.s-valid  .s-num { color: #16a34a; }
.summary-card.s-below  .s-num { color: #dc2626; }
.summary-card.s-unpriced .s-num { color: #d97706; }

/* Toolbar */
.toolbar {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
    margin-bottom: 16px;
}
.toolbar input[type="text"],
.toolbar select {
    padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; color: #334155; background: #fff;
}
.toolbar input[type="text"] { min-width: 220px; }
.toolbar input[type="text"]:focus,
.toolbar select:focus { outline: none; border-color: #002F6C; box-shadow: 0 0 0 2px rgba(0,47,108,.12); }

/* Readonly notice */
.readonly-notice {
    display: inline-flex; align-items: center; gap: 6px;
    background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;
    border-radius: 20px; padding: 4px 14px; font-size: 12px; font-weight: 600;
}

/* Table tweaks */
.pricing-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pricing-table th {
    background: #f8fafc; padding: 10px 12px; text-align: left;
    font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;
    letter-spacing: .4px; border-bottom: 2px solid #e2e8f0; white-space: nowrap;
}
.pricing-table td { padding: 11px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.pricing-table tbody tr:hover { background: #f8fafc; }

/* Category header row */
.cat-row td {
    background: #f1f5f9 !important; font-weight: 700; font-size: 11px;
    text-transform: uppercase; letter-spacing: .5px; color: #475569;
    padding: 7px 12px; border-bottom: 1px solid #e2e8f0;
}

/* Row highlight for price-below-cost */
.row-below-cost { background: #fff5f5 !important; }
.row-below-cost:hover { background: #fee2e2 !important; }

/* Badges */
.badge-normal    { background: #dcfce7; color: #166534; }
.badge-low       { background: #fef9c3; color: #854d0e; }
.badge-critical  { background: #fee2e2; color: #991b1b; }
.badge-out       { background: #fee2e2; color: #991b1b; }
.badge-available { background: #dcfce7; color: #166534; }
.badge-noprice   { background: #fef3c7; color: #92400e; }
.badge-warn      { background: #fee2e2; color: #991b1b; }
.badge-ok        { background: #dcfce7; color: #166534; }

.badge {
    display: inline-block; padding: 3px 9px; border-radius: 999px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
}

/* Export buttons */
.btn-export-csv { background: #16a34a; color: #fff; border: none; }
.btn-export-csv:hover { background: #15803d; }
.btn-export-pdf { background: #7c3aed; color: #fff; border: none; }
.btn-export-pdf:hover { background: #6d28d9; }

@media (max-width: 768px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .toolbar { flex-direction: column; align-items: stretch; }
    .toolbar input[type="text"] { min-width: unset; width: 100%; }
}
</style>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="page-head" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="h1"><i class="fas fa-tags"></i> Product &amp; Pricing Overview</h1>
        <div class="sub">Product &amp; Pricing Overview</div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <a href="?export=excel" class="btn btn-export-csv" style="padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="export_product_pricing_final.php" class="btn btn-export-pdf" style="padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;background:#dc2626;color:#fff;" onclick="this.href='export_product_pricing_final.php?section=' + document.getElementById('sectionDropdown').value">
            <i class="fas fa-file-pdf"></i> Print / PDF
        </a>
    </div>
</div>


<!-- ── Section Dropdown ──────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <label style="font-size:13px;font-weight:600;color:#374151;">View Section:</label>
    <select id="sectionDropdown" onchange="switchTab(this.value)" style="padding:9px 36px 9px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;font-weight:600;color:#002F6C;background:#fff url('data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'8\'><path d=\'M1 1l5 5 5-5\' stroke=\'%23002F6C\' stroke-width=\'2\' fill=\'none\' stroke-linecap=\'round\'/></svg>') no-repeat right 12px center;appearance:none;cursor:pointer;min-width:240px;box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <option value="fuel">Fuel Products</option>
        <option value="merch">Merchandise</option>
    </select>
    <span id="sectionLabel" style="font-size:12px;color:#64748b;"></span>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 1 — FUEL PRODUCTS
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-fuel" class="tab-panel active">
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
            <strong style="font-size:15px;color:#002F6C;"><i class="fas fa-gas-pump"></i> Fuel Inventory &amp; Pricing</strong>
            <span class="muted" style="font-size:12px;">Station-scoped &bull; Read-only</span>
        </div>
        <div class="table-wrap" style="overflow-x:auto;">
            <table class="pricing-table">
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th>Price / Liter (&#8369;)</th>
                        <th>Stock Level (L)</th>
                        <th>Capacity (L)</th>
                        <th>Critical Level (L)</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($fuel_products)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:28px;color:#94a3b8;">
                            <i class="fas fa-info-circle"></i> No fuel inventory records found for this station.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($fuel_products as $f):
                        $level    = (float)($f['current_level'] ?? 0);
                        $critical = (float)($f['critical_level'] ?? 0);
                        $capacity = (float)($f['capacity'] ?? 0);
                        
                        // Determine status based on stock level
                        
                        if ($level <= 0) {
                            $status_label = 'Critical';
                            $status_class = 'badge-critical';
                            $bar_color = '#dc2626';
                        } elseif ($level <= $critical * 0.5) {
                            $status_label = 'Critical';
                            $status_class = 'badge-critical';
                            $bar_color = '#dc2626';
                        } elseif ($level <= $critical) {
                            $status_label = 'Low Stock';
                            $status_class = 'badge-low';
                            $bar_color = '#ef4444';
                        } else {
                            $status_label = 'Normal';
                            $status_class = 'badge-normal';
                            $bar_color = '#16a34a';
                        }
                        
                        $pct = $capacity > 0 ? min(100, round($level / $capacity * 100)) : 0;
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($f['fuel_type']); ?></strong>
                        </td>
                        <td>
                            <strong style="color:#002F6C;">&#8369;<?php echo number_format((float)($f['price_per_liter'] ?? 0), 2); ?></strong>
                        </td>
                        <td>
                            <?php echo number_format($level, 2); ?>
                            <div style="margin-top:4px;height:4px;background:#e2e8f0;border-radius:2px;width:80px;">
                                <div style="height:4px;background:<?php echo $bar_color; ?>;border-radius:2px;width:<?php echo $pct; ?>%;"></div>
                            </div>
                        </td>
                        <td><?php echo number_format($capacity, 2); ?></td>
                        <td><?php echo number_format($critical, 2); ?></td>
                        <td>
                            <?php if ($status_label === 'Critical'): ?>
                                <span class="badge <?php echo $status_class; ?>">&#9888; Critical</span>
                            <?php elseif ($status_label === 'Low Stock'): ?>
                                <span class="badge <?php echo $status_class; ?>">&#9888; Low Stock</span>
                            <?php else: ?>
                                <span class="badge <?php echo $status_class; ?>">&#10003; Normal</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted" style="font-size:12px;">
                            <?php echo $f['last_updated'] ? htmlspecialchars(date('M d, Y H:i', strtotime($f['last_updated']))) : '&mdash;'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 2 — MERCHANDISE PRODUCTS
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-merch" class="tab-panel">

    <!-- Toolbar: search + filters -->
    <div class="toolbar">
        <input type="text" id="searchInput" placeholder="&#128269; Search by product name or SKU&hellip;" oninput="filterTable()">
        <select id="catFilter" onchange="filterTable()">
            <option value="">All Categories</option>
            <?php foreach ($all_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="statusFilter" onchange="filterTable()">
            <option value="">All Statuses</option>
            <option value="available">Available</option>
            <option value="low">Low Stock</option>
            <option value="out">Out of Stock</option>
            <option value="noprice">No Price Set</option>
            <option value="belowcost">Price Below Cost</option>
        </select>
        <span class="muted" style="font-size:12px;margin-left:auto;" id="visibleCount">
            Showing <?php echo $merch_stats['total']; ?> products
        </span>
    </div>

    <?php if (empty($merch_by_cat)): ?>
        <div class="card" style="padding:28px;text-align:center;color:#94a3b8;">
            <i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>
            No merchandise products found.
        </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="table-wrap" style="overflow-x:auto;">
            <table class="pricing-table" id="merchTable">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Size</th>
                        <th>Cost (&#8369;)</th>
                        <th>Price (&#8369;)</th>
                        <th>Stock</th>
                        <th>Margin (&#8369;)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="merchBody">
                <?php foreach ($merch_by_cat as $cat_label => $items): ?>
                    <tr class="cat-row" data-cat-header="<?php echo htmlspecialchars($cat_label); ?>">
                        <td colspan="9">
                            <i class="fas fa-folder"></i>
                            <?php echo htmlspecialchars($cat_label); ?>
                            <span class="muted" style="font-weight:400;margin-left:6px;">(<?php echo count($items); ?> items)</span>
                        </td>
                    </tr>
                    <?php foreach ($items as $item):
                        $cost   = $item['_cost'];
                        $price  = $item['_price'];
                        $stock  = $item['_stock'];
                        $margin = $price - $cost;

                        $below_cost = ($price > 0 && $price < $cost);
                        $no_price   = ($price <= 0);

                        if ($stock <= 0)       { $st_label = 'Out of Stock'; $st_class = 'badge-out';       $st_key = 'out'; }
                        elseif ($stock <= 10)  { $st_label = 'Low Stock';    $st_class = 'badge-low';       $st_key = 'low'; }
                        else                   { $st_label = 'Available';    $st_class = 'badge-available'; $st_key = 'available'; }

                        $row_class = $below_cost ? 'row-below-cost' : '';
                    ?>
                    <tr class="merch-row <?php echo $row_class; ?>"
                        data-name="<?php echo strtolower(htmlspecialchars($item['product_name'] ?? '')); ?>"
                        data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                        data-cat="<?php echo htmlspecialchars($cat_label); ?>"
                        data-status="<?php echo $st_key; ?>"
                        data-noprice="<?php echo $no_price ? '1' : '0'; ?>"
                        data-belowcost="<?php echo $below_cost ? '1' : '0'; ?>">
                        <td>
                            <strong><?php echo htmlspecialchars($item['product_name'] ?? ''); ?></strong>
                            <?php if ($below_cost): ?>
                                <span class="badge badge-warn" style="margin-left:6px;font-size:10px;">&#9888; Price Below Cost</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?php echo htmlspecialchars($item['sku'] ?? '&mdash;'); ?></td>
                        <td><?php echo htmlspecialchars($cat_label); ?></td>
                        <td class="muted"><?php echo htmlspecialchars($item['size'] ?? '&mdash;'); ?></td>
                        <td style="color:#64748b;">&#8369;<?php echo number_format($cost, 2); ?></td>
                        <td>
                            <?php if ($no_price): ?>
                                <span class="badge badge-noprice">No Price Set</span>
                            <?php else: ?>
                                <strong style="color:<?php echo $below_cost ? '#dc2626' : '#002F6C'; ?>">
                                    &#8369;<?php echo number_format($price, 2); ?>
                                </strong>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($stock, 0); ?></td>
                        <td>
                            <?php if (!$no_price): ?>
                                <span style="color:<?php echo $margin >= 0 ? '#16a34a' : '#dc2626'; ?>;font-weight:600;">
                                    <?php echo ($margin >= 0 ? '+' : '') . '&#8369;' . number_format($margin, 2); ?>
                                </span>
                            <?php else: ?>
                                <span class="muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $st_class; ?>"><?php echo $st_label; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

                            
<script>
// ── Section dropdown switching ───────────────────────────────────────────────
function switchTab(name) {
    // Remove active class from all tab panels
    document.querySelectorAll('.tab-panel').forEach(function(p) { 
        if (p) p.classList.remove('active'); 
    });
    
    // Add active class to selected tab
    var targetTab = document.getElementById('tab-' + name);
    if (targetTab) {
        targetTab.classList.add('active');
    }
    
    // Update dropdown value
    var dropdown = document.getElementById('sectionDropdown');
    if (dropdown) {
        dropdown.value = name;
    }
}

// Show first section on load
document.addEventListener('DOMContentLoaded', function() {
    switchTab('fuel');
});

// ── Merchandise filter ───────────────────────────────────────────────────────
function filterTable() {
    // Check if merchandise tab is active to prevent console errors
    var merchTab = document.getElementById('tab-merch');
    if (!merchTab || !merchTab.classList.contains('active')) {
        return;
    }
    
    var q          = document.getElementById('searchInput').value.toLowerCase().trim();
    var catFilter  = document.getElementById('catFilter').value;
    var stFilter   = document.getElementById('statusFilter').value;
    var rows       = document.querySelectorAll('#merchBody .merch-row');
    var catHeaders = document.querySelectorAll('#merchBody .cat-row');
    var visible    = 0;

    // Track which categories have visible rows
    var catVisible = {};

    rows.forEach(function(row) {
        var name      = row.getAttribute('data-name') || '';
        var sku       = row.getAttribute('data-sku')  || '';
        var cat       = row.getAttribute('data-cat')  || '';
        var status    = row.getAttribute('data-status') || '';
        var noprice   = row.getAttribute('data-noprice') === '1';
        var belowcost = row.getAttribute('data-belowcost') === '1';

        var matchQ   = !q || name.indexOf(q) !== -1 || sku.indexOf(q) !== -1;
        var matchCat = !catFilter || cat === catFilter;
        var matchSt  = true;
        if (stFilter === 'available') matchSt = status === 'available';
        else if (stFilter === 'low')  matchSt = status === 'low';
        else if (stFilter === 'out')  matchSt = status === 'out';
        else if (stFilter === 'noprice')   matchSt = noprice;
        else if (stFilter === 'belowcost') matchSt = belowcost;

        var show = matchQ && matchCat && matchSt;
        row.style.display = show ? '' : 'none';
        if (show) {
            visible++;
            catVisible[cat] = true;
        }
    });

    // Show/hide category header rows
    catHeaders.forEach(function(hdr) {
        var cat = hdr.getAttribute('data-cat-header') || '';
        hdr.style.display = catVisible[cat] ? '' : 'none';
    });

    var countEl = document.getElementById('visibleCount');
    if (countEl) countEl.textContent = 'Showing ' + visible + ' product' + (visible !== 1 ? 's' : '');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
