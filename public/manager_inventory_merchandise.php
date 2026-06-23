<?php
$page_id = 'mgr_inv_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// POST: validate product
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'validate_product') {
        $id = (int)($_POST['product_id'] ?? 0);
        if ($id) {
            try {
                $stmt = $pdo->prepare("SELECT product_name FROM inventory_products WHERE id=?");
                $stmt->execute([$id]);
                $pname = $stmt->fetchColumn();
                $pdo->prepare("UPDATE inventory_products SET status = 'active' WHERE id=?")->execute([$id]);
                log_activity($pdo, $me['id'], 'Product Validated', "Merchandise product '$pname' (ID:$id) validated by {$me['name']}");
                $_SESSION['success'] = "Product '$pname' validated and is now active.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_inventory_merchandise.php'); exit;
    }
}

$merch_inventory = [];
$msg = '';

// Backfill station_inventory for products with no row yet
try {
    $pdo->prepare("
        INSERT INTO station_inventory (product_id, station_id, stock_level, status, last_updated)
        SELECT ip.id, ?, COALESCE(ip.stock, 0), 'active', NOW()
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE si.id IS NULL
          AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel')
    ")->execute([$station_id, $station_id]);
} catch (Exception $e) {}

// Main query — same source as manager_product_merchandise.php
try {
    $stmt = $pdo->prepare("
        SELECT
            ip.id,
            ip.product_name                              AS name,
            ip.category                                  AS category_name,
            ip.unit_price                                AS price,
            ip.unit_cost                                 AS cost,
            ip.sku,
            ip.status                                    AS product_status,
            COALESCE(ip.min_stock, 0)                    AS min_stock,
            COALESCE(ip.max_stock, 0)                    AS max_stock,
            COALESCE(si.stock_level, ip.stock, 0)        AS stock_level,
            COALESCE(si.capacity, ip.max_stock, 0)       AS capacity,
            COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
            si.last_updated                              AS last_updated,
            COALESCE(ba.active_batches, 0)               AS active_batches,
            COALESCE(ba.total_batches,  0)               AS total_batches,
            COALESCE(ba.batch_stock,    0)               AS batch_stock
        FROM inventory_products ip
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = ?
        LEFT JOIN (
            SELECT
                product_id,
                COUNT(*) AS total_batches,
                SUM(CASE WHEN LOWER(status) = 'active' THEN 1    ELSE 0 END) AS active_batches,
                SUM(CASE WHEN LOWER(status) = 'active' THEN remaining_qty ELSE 0 END) AS batch_stock
            FROM merchandise_batches
            WHERE station_id = ?
            GROUP BY product_id
        ) ba ON ba.product_id = ip.id
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel')
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id, $station_id]);
    $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading merchandise: ' . $e->getMessage();
}

// Build category list for filter
$categories_list = [];
foreach ($merch_inventory as $item) {
    $cat = $item['category_name'] ?? '';
    if ($cat !== '') $categories_list[$cat] = true;
}
ksort($categories_list);

include __DIR__ . '/../partials/header.php';
?>
<style>
.cat-header td {
    font-weight:700; background:#e9ecef !important; color:#495057 !important;
    text-transform:uppercase; font-size:.8em; letter-spacing:.5px;
    border-bottom:2px solid #dee2e6; padding:8px 12px;
}
.merch-row:hover { background:#f8f9fa; }
.inv-filter-bar {
    display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px;
}
.inv-filter-bar select,
.inv-filter-bar input[type="text"] {
    padding:8px 11px; border:1px solid #ced4da; border-radius:5px;
    font-size:13px; font-family:inherit; color:#1e293b;
}
.inv-filter-bar select { min-width:170px; }
.inv-filter-bar input[type="text"] { min-width:210px; }
.pstatus-badge {
    display:inline-block; padding:3px 9px; border-radius:4px;
    font-size:11px; font-weight:600;
}
.pstatus-active   { background:#d4edda; color:#155724; }
.pstatus-inactive { background:#e9ecef; color:#495057; }
.pstatus-pending  { background:#fff3cd; color:#856404; }
.inv-stock-badge {
    display:inline-block; padding:3px 9px; border-radius:4px;
    font-size:11px; font-weight:600;
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1">Merchandise Inventory</h1>
        <div class="sub">Real-time stock levels — Merchandise Catalog</div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;">
        <?php
        $export_table_id       = 'mgrMerchTable';
        $export_filename       = 'manager_merchandise_inventory_' . date('Ymd');
        $export_title          = 'Merchandise Inventory';
        $export_rows_select_id = 'mgrMerchRowsLimit';
        $export_default_rows   = 50;
        $export_back_url       = 'manager_dashboard.php';
        require __DIR__ . '/../partials/export_buttons.php';
        ?>
        <a href="manager_product_merchandise.php"
           style="background:#28a745;color:#fff;border:none;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
            <i class="fas fa-dolly"></i> Stock In / Manage Products
        </a>
    </div>
</div>

<?php if (!empty($_SESSION['success'])): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>
<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-box"></i> Merchandise Stock
        </div>
        <!-- Filter Bar -->
        <div class="inv-filter-bar">
            <select id="invCatFilter" onchange="filterInvTable()">
                <option value="">All Categories</option>
                <?php foreach (array_keys($categories_list) as $cat): ?>
                <option value="<?php echo strtolower(htmlspecialchars($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="invSearch" placeholder="Search name or SKU..." oninput="filterInvTable()">
            <select id="invStockFilter" onchange="filterInvTable()">
                <option value="">All Stock Status</option>
                <option value="available">Available</option>
                <option value="low">Low</option>
                <option value="critical">Critical</option>
                <option value="out of stock">Out of Stock</option>
            </select>
            <select id="invProdFilter" onchange="filterInvTable()">
                <option value="">All Product Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="pending">Pending</option>
            </select>
        </div>
    </div>
    <div style="padding:0;">
        <div class="table-wrap">
            <table class="table" id="mgrMerchTable">
                <thead>
                    <tr>
                        <th>Item Code / SKU</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Capacity</th>
                        <th>Current Stock</th>
                        <th>Stock Status</th>
                        <th>Product Status</th>
                        <th>Unit Price</th>
                        <th>Total Value</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php
                // Group by category
                $by_cat = [];
                foreach ($merch_inventory as $item) {
                    $cat = $item['category_name'] ?? 'Uncategorized';
                    $by_cat[$cat][] = $item;
                }
                $cat_order = [
                    'Oils / Lubes / Grease','Car Accessories','Brake System',
                    'Tire','Maintenance','Oil / Fuel Filters','Others (Snacks / Drinks)'
                ];
                $sorted = [];
                foreach ($cat_order as $k) { if (isset($by_cat[$k])) $sorted[$k] = $by_cat[$k]; }
                foreach ($by_cat as $k => $v) { if (!in_array($k, $cat_order)) $sorted[$k] = $v; }

                foreach ($sorted as $cat_label => $items):
                ?>
                    <tr class="cat-header">
                        <td colspan="10"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td>
                    </tr>
                    <?php foreach ($items as $item):
                        $stock    = (float)($item['stock_level'] ?? 0);
                        $capacity = (float)($item['capacity']    ?? 0);

                        // Category-based capacity fallback
                        if ($capacity <= 0) {
                            $cat_up = strtoupper(trim($item['category_name'] ?? ''));
                            if (strpos($cat_up, 'BEVERAGE') !== false || strpos($cat_up, 'DRINK') !== false) {
                                $capacity = 500;
                            } elseif (strpos($cat_up, 'SNACK') !== false || strpos($cat_up, 'BISCUIT') !== false || strpos($cat_up, 'COOKIE') !== false || strpos($cat_up, 'NOODLE') !== false) {
                                $capacity = 500;
                            } elseif (strpos($cat_up, 'CHOCOLATE') !== false || strpos($cat_up, 'CANDY') !== false) {
                                $capacity = 400;
                            } elseif (strpos($cat_up, 'FRESHENER') !== false) {
                                $capacity = 250;
                            } elseif (strpos($cat_up, 'ACCESSORI') !== false || strpos($cat_up, 'TIRE') !== false) {
                                $capacity = 200;
                            } elseif (strpos($cat_up, 'FILTER') !== false) {
                                $capacity = 150;
                            } elseif (strpos($cat_up, 'OIL') !== false || strpos($cat_up, 'LUBE') !== false || strpos($cat_up, 'GREASE') !== false) {
                                $capacity = 100;
                            } else {
                                $capacity = 100;
                            }
                        }

                        $fill_pct = $capacity > 0 ? ($stock / $capacity) * 100 : 0;

                        // Product status (from inventory_products.status)
                        $prod_status_raw = strtolower($item['product_status'] ?? 'active');
                        $isPending       = in_array($prod_status_raw, ['pending', 'pending_validation']);
                        $isActive        = ($prod_status_raw === 'active');

                        if ($isPending) {
                            $prod_label = 'Pending';
                            $prod_class = 'pstatus-pending';
                        } elseif ($isActive) {
                            $prod_label = 'Active';
                            $prod_class = 'pstatus-active';
                        } else {
                            $prod_label = 'Inactive';
                            $prod_class = 'pstatus-inactive';
                        }

                        // Inventory stock status (based on fill %)
                        if ($stock <= 0) {
                            $st = 'Out of Stock'; $sc = '#dc3545'; $si_cls = 'out of stock';
                        } elseif ($fill_pct <= 10) {
                            $st = 'Critical'; $sc = '#dc3545'; $si_cls = 'critical';
                        } elseif ($fill_pct <= 25) {
                            $st = 'Low'; $sc = '#fd7e14'; $si_cls = 'low';
                        } else {
                            $st = 'Available'; $sc = '#28a745'; $si_cls = 'available';
                        }

                        $unit_price  = (float)($item['price'] ?? 0);
                        $total_value = $stock * $unit_price;

                        $timestamp = '—';
                        if (!empty($item['last_updated'])) {
                            try {
                                $dt = new DateTime($item['last_updated']);
                                $timestamp = $dt->format('M d, Y g:i A');
                            } catch (Exception $e) { $timestamp = '—'; }
                        }
                    ?>
                    <tr class="merch-row"
                        data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>"
                        data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                        data-cat="<?php echo strtolower(htmlspecialchars($item['category_name'] ?? '')); ?>"
                        data-inv-status="<?php echo $si_cls; ?>"
                        data-prod-status="<?php echo $prod_status_raw; ?>">
                        <td><code style="font-size:11px;font-weight:600;"><?php echo htmlspecialchars($item['sku'] ?? '—'); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></td>
                        <td style="font-weight:600;color:#002F70;"><?php echo number_format($capacity, 0); ?></td>
                        <td style="font-weight:700;color:#002F70;">
                            <?php echo number_format($stock, 0); ?>
                            <span style="font-size:10px;color:#64748b;">(<?php echo round($fill_pct, 1); ?>%)</span>
                        </td>
                        <td>
                            <span class="inv-stock-badge" style="background:<?php echo $sc; ?>20;color:<?php echo $sc; ?>;border:1px solid <?php echo $sc; ?>40;">
                                <?php echo $st; ?>
                            </span>
                        </td>
                        <td>
                            <span class="pstatus-badge <?php echo $prod_class; ?>">
                                <?php echo $prod_label; ?>
                            </span>
                            <?php if ($isPending): ?>
                            <button onclick="validateProduct(<?php echo (int)$item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>')"
                                style="margin-left:4px;padding:2px 8px;font-size:11px;background:#28a745;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;">
                                Validate
                            </button>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:600;color:#28a745;">&#8369;<?php echo number_format($unit_price, 2); ?></td>
                        <td style="font-weight:700;color:#002F70;">&#8369;<?php echo number_format($total_value, 2); ?></td>
                        <td style="font-size:11px;color:#64748b;"><?php echo $timestamp; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <?php if (empty($merch_inventory)): ?>
                    <tr><td colspan="10" style="text-align:center;padding:28px;color:#6c757d;">No merchandise data available.</td></tr>
                <?php else:
                    $total_pcs   = array_sum(array_column($merch_inventory, 'stock_level'));
                    $total_val   = array_sum(array_map(fn($i) => (float)($i['stock_level'] ?? 0) * (float)($i['price'] ?? 0), $merch_inventory));
                    $total_items = count($merch_inventory);
                    $total_cats  = count($sorted);
                ?>
                    <tr style="background:#f8f9fa;font-weight:700;border-top:2px solid #dee2e6;">
                        <td colspan="4">TOTAL</td>
                        <td><?php echo number_format($total_pcs, 0); ?> pcs</td>
                        <td colspan="2">
                            <span style="color:#00264D;"><?php echo $total_items; ?></span>
                            <span style="font-weight:400;color:#667085;"> items &mdash; </span>
                            <span style="color:#00264D;"><?php echo $total_cats; ?></span>
                            <span style="font-weight:400;color:#667085;"> categories</span>
                        </td>
                        <td colspan="2" style="color:#28a745;">&#8369;<?php echo number_format($total_val, 2); ?></td>
                        <td></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="mgrMerchPagination" style="padding:10px 20px;"></div>
    </div>
</div>

<script>
function filterInvTable() {
    var cat   = document.getElementById('invCatFilter').value.toLowerCase();
    var srch  = document.getElementById('invSearch').value.toLowerCase();
    var stFlt = document.getElementById('invStockFilter').value.toLowerCase();
    var psFlt = document.getElementById('invProdFilter').value.toLowerCase();

    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        var rCat  = (r.dataset.cat       || '').toLowerCase();
        var rName = (r.dataset.name      || '').toLowerCase();
        var rSku  = (r.dataset.sku       || '').toLowerCase();
        var rInv  = (r.dataset.invStatus || '').toLowerCase();
        var rPrd  = (r.dataset.prodStatus|| '').toLowerCase();

        var ok = (!cat   || rCat === cat)
              && (!srch  || rName.includes(srch) || rSku.includes(srch))
              && (!stFlt || rInv.includes(stFlt))
              && (!psFlt || rPrd.includes(psFlt));

        r.style.display = ok ? '' : 'none';
    });
}

function validateProduct(id, name) {
    if (!confirm('Validate and activate "' + name + '"?')) return;
    var f = document.createElement('form');
    f.method = 'POST';
    f.style.display = 'none';
    f.innerHTML = '<input name="action" value="validate_product"><input name="product_id" value="' + id + '">';
    document.body.appendChild(f);
    f.submit();
}

document.addEventListener('DOMContentLoaded', function() {
    setupTablePagination('mgrMerchTable', 'mgrMerchRowsLimit', 'mgrMerchPagination', 50);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
