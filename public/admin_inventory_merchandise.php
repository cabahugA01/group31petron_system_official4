<?php
$page_id = 'admin_inventory_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();
if (!in_array($role, ['admin','superadmin'])) { header('Location: dashboard.php'); exit; }
if ($station_id <= 0 && $role === 'admin') { render_no_station_page('admin_dashboard.php'); }

// ── Handle Edit Stock POST ────────────────────────────────────────────
$flash_ok  = $_SESSION['ok']  ?? null; unset($_SESSION['ok']);
$flash_err = $_SESSION['err'] ?? null; unset($_SESSION['err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_stock') {
    $pid     = (int)($_POST['product_id'] ?? 0);
    $new_qty = (int)($_POST['new_qty']    ?? 0);
    $note    = trim($_POST['note']        ?? '');
    if ($pid > 0 && $new_qty >= 0) {
        try {
            $chk = $pdo->prepare("SELECT id FROM station_inventory WHERE product_id=? AND station_id=? LIMIT 1");
            $chk->execute([$pid, $station_id]);
            $si = $chk->fetchColumn();
            if ($si) {
                $pdo->prepare("UPDATE station_inventory SET stock_level=?, last_updated=NOW() WHERE id=?")
                    ->execute([$new_qty, $si]);
            } else {
                $pdo->prepare("UPDATE inventory_products SET stock_quantity=?, stock=?, updated_at=NOW() WHERE id=?")
                    ->execute([$new_qty, $new_qty, $pid]);
            }
            if (function_exists('log_activity'))
                log_activity($pdo, $me['id'], 'Admin Edit Merchandise Stock',
                    "Product ID $pid corrected to {$new_qty} units. Note: $note");
            $_SESSION['ok'] = 'Stock level updated successfully.';
        } catch (Exception $e) { $_SESSION['err'] = $e->getMessage(); }
    }
    header('Location: admin_inventory_merchandise.php'); exit;
}

// ── Fetch merchandise (same query as manager) ─────────────────────────
$merch_inventory = [];
$msg = '';
try {
    $stmt = $pdo->prepare("
        SELECT ip.id,
               ip.product_name AS name,
               ip.category     AS category_name,
               ip.unit_price   AS price,
               ip.unit_cost    AS cost,
               ip.sku,
               COALESCE(si.status, 'Active')          AS status,
               COALESCE(si.stock_level, ip.stock, 0)  AS stock_level,
               COALESCE(si.capacity, 0)               AS capacity,
               COALESCE(si.reorder_level, 10)         AS reorder_level,
               si.last_updated AS last_updated
        FROM inventory_products ip
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category NOT IN ('Fuel')
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading merchandise: ' . $e->getMessage();
}

// ── KPI counts ────────────────────────────────────────────────────────
$cnt_avail = 0; $cnt_low = 0; $cnt_crit = 0; $total_val = 0;
foreach ($merch_inventory as $item) {
    $stock = (float)($item['stock_level'] ?? 0);
    $cap   = max((float)($item['capacity'] ?? 1), 1);
    $pct   = ($stock / $cap) * 100;
    $total_val += $stock * (float)($item['price'] ?? 0);
    if      ($stock <= 0) $cnt_crit++;
    elseif  ($pct <= 25)  $cnt_low++;
    else                  $cnt_avail++;
}

// ── Category grouping (same order as manager) ─────────────────────────
$categories = [];
foreach ($merch_inventory as $item) {
    $cat = $item['category_name'] ?? 'Uncategorized';
    $categories[$cat][] = $item;
}
$cat_order = [
    'Oils / Lubes / Grease','Car Accessories','Brake System',
    'Tire','Maintenance','Oil / Fuel Filters','Others (Snacks / Drinks)'
];
$sorted = [];
foreach ($cat_order as $k) { if (isset($categories[$k])) $sorted[$k] = $categories[$k]; }
foreach ($categories as $k => $v) { if (!in_array($k, $cat_order)) $sorted[$k] = $v; }

include __DIR__ . '/../partials/header.php';
?>
<style>
/* Page-level overrides only — table uses global manager_table_design.css */
body, html { overflow-x: hidden !important; }

.kpi-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
.kpi-box { background:#fff; border-radius:10px; padding:14px 18px; min-width:130px; flex:1; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.kpi-box .n { font-size:1.5rem; font-weight:800; color:#002F70; line-height:1.1; }
.kpi-box .l { font-size:12px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }
.kpi-box.ok   .n { color:#28a745; }
.kpi-box.warn .n { color:#fd7e14; }
.kpi-box.crit .n { color:#dc3545; }
.kpi-box.val  .n { color:#6d28d9; font-size:1.2rem; }

/* Category header rows */
.cat-header td {
    font-weight:700; background:#e9ecef !important; color:#495057 !important;
    text-transform:uppercase; font-size:.8em; letter-spacing:.5px;
    border-bottom:2px solid #dee2e6 !important; padding:8px 12px !important;
}
.merch-row:hover td { background:#f8f9fa !important; }

/* Status pills */
.st-pill { display:inline-block; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; }

/* Flash */
.flash-ok  { background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:7px; padding:11px 15px; margin-bottom:14px; font-size:13px; }
.flash-err { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; border-radius:7px; padding:11px 15px; margin-bottom:14px; font-size:13px; }

/* Edit Modal */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9000; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal-box { background:#fff; border-radius:12px; padding:26px; width:460px; max-width:95vw; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.modal-box h3 { margin:0 0 14px; font-size:15px; color:#002F70; }
.form-group-m { margin-bottom:13px; }
.form-group-m label { display:block; font-size:12px; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px; }
.form-group-m input, .form-group-m textarea { width:100%; padding:8px 10px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; box-sizing:border-box; }
.form-group-m input:focus, .form-group-m textarea:focus { outline:none; border-color:#002F70; }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:14px; }
.info-note { background:#e8f4fd; border-left:3px solid #002F70; padding:9px 13px; border-radius:5px; font-size:12px; color:#1e4080; margin-bottom:13px; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1">Merchandise Inventory Oversight</h1>
        <div class="sub">Stock monitoring &middot; Today: <?= date('F d, Y') ?></div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;">
        <?php
        $export_table_id       = 'adminMerchTable';
        $export_filename       = 'admin_merch_inventory_' . date('Ymd');
        $export_title          = 'Merchandise Inventory Oversight';
        $export_rows_select_id = 'adminMerchRowsLimit';
        $export_default_rows   = 50;
        $export_back_url       = 'admin_dashboard.php';
        require __DIR__ . '/../partials/export_buttons.php';
        ?>
    </div>
</div>

<?php if ($flash_ok): ?><div class="flash-ok"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="flash-err"><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="flash-err"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- KPI Cards -->
<div class="kpi-row">
    <div class="kpi-box"><div class="n"><?= count($merch_inventory) ?></div><div class="l">Total Products</div></div>
    <div class="kpi-box ok"><div class="n"><?= $cnt_avail ?></div><div class="l">Available</div></div>
    <div class="kpi-box warn"><div class="n"><?= $cnt_low ?></div><div class="l">Low Stock</div></div>
    <div class="kpi-box crit"><div class="n"><?= $cnt_crit ?></div><div class="l">Out of Stock</div></div>
    <div class="kpi-box val"><div class="n">&#8369;<?= number_format($total_val, 0) ?></div><div class="l">Total Value</div></div>
</div>




<!-- Table Card — uses same .card / .table classes as manager -->
<div class="card">
    <div class="card-header">
        <h3>Merchandise Stock</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-wrap">
            <table class="table" id="adminMerchTable">
                <thead>
                    <tr>
                        <th>Item Code / SKU</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Capacity / Max Stock</th>
                        <th>Current Level</th>
                        <th>Status</th>
                        <th>Variance</th>
                        <th>Unit Price</th>
                        <th>Total Value</th>
                        <th>Timestamp</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($merch_inventory)): ?>
                    <tr><td colspan="11" class="empty-state">No merchandise data available.</td></tr>
                <?php else: ?>
                    <?php foreach ($sorted as $cat_label => $items): ?>
                    <tr class="cat-header">
                        <td colspan="11"><strong><?= htmlspecialchars($cat_label) ?></strong></td>
                    </tr>
                    <?php foreach ($items as $item):
                        $stock    = (float)($item['stock_level'] ?? 0);
                        $capacity = (float)($item['capacity']    ?? 0);
                        if ($capacity <= 0) {
                            $cu = strtoupper(trim($item['category_name'] ?? ''));
                            if      (strpos($cu,'OIL')!==false && strpos($cu,'ENGINE')!==false) $capacity=100;
                            elseif  (strpos($cu,'COOLANT')!==false||strpos($cu,'FLUID')!==false) $capacity=strpos($cu,'BRAKE')!==false?50:80;
                            elseif  (strpos($cu,'GREASE')!==false||strpos($cu,'LUBE')!==false)  $capacity=100;
                            elseif  (strpos($cu,'FILTER')!==false)   $capacity=150;
                            elseif  (strpos($cu,'BEVERAGE')!==false||strpos($cu,'DRINK')!==false) $capacity=500;
                            elseif  (strpos($cu,'SNACK')!==false||strpos($cu,'NOODLE')!==false)  $capacity=500;
                            elseif  (strpos($cu,'CHOCOLATE')!==false||strpos($cu,'CANDY')!==false) $capacity=400;
                            else    $capacity=100;
                        }
                        $status    = $item['status'] ?? 'active';
                        $isPending = in_array(strtolower($status), ['pending','pending_validation']);
                        $fill_pct  = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
                        if      ($isPending)      { $st='PENDING VALIDATION'; $sc='#fd7e14'; }
                        elseif  ($stock <= 0)     { $st='Out of Stock'; $sc='#dc3545'; }
                        elseif  ($fill_pct <= 10) { $st='Critical';     $sc='#dc3545'; }
                        elseif  ($fill_pct <= 25) { $st='Low';          $sc='#fd7e14'; }
                        else                      { $st='Available';    $sc='#28a745'; }
                        $unit_price  = (float)($item['price'] ?? 0);
                        $total_value = $stock * $unit_price;
                        $ts_str      = $item['last_updated'] ? date('M d, Y g:i A', strtotime($item['last_updated'])) : '—';
                    ?>
                    <tr class="merch-row">
                        <td><code style="font-size:11px;font-weight:600;"><?= htmlspecialchars($item['sku'] ?? '') ?></code></td>
                        <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                        <td><?= htmlspecialchars($item['category_name'] ?? '') ?></td>
                        <td style="font-weight:600;color:#002F70;"><?= number_format($capacity, 0) ?></td>
                        <td style="font-weight:700;color:#002F70;"><?= number_format($stock, 0) ?> <span style="font-size:10px;color:#64748b;">(<?= round($fill_pct, 1) ?>%)</span></td>
                        <td>
                            <span class="st-pill" style="background:<?= $sc ?>20;color:<?= $sc ?>;border:1px solid <?= $sc ?>40;">
                                <?= htmlspecialchars($st) ?>
                            </span>
                        </td>
                        <td style="color:#6c757d;font-weight:700;">0.00</td>
                        <td style="font-weight:600;color:#28a745;">&#8369;<?= number_format($unit_price, 2) ?></td>
                        <td style="font-weight:700;color:#002F70;">&#8369;<?= number_format($total_value, 2) ?></td>
                        <td style="font-size:11px;color:#64748b;"><?= $ts_str ?></td>
                        <td>
                            <div class="action-col">
                                <button class="btn btn-edit"
                                    onclick="openEdit(<?= (int)$item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>', <?= (int)$stock ?>, <?= (int)$capacity ?>)">
                                    Edit
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                    <!-- Totals row -->
                    <tr style="background:#f8f9fa;font-weight:700;border-top:2px solid #dee2e6;">
                        <td colspan="4" style="padding:10px 12px;">TOTAL</td>
                        <td colspan="3">
                            <span style="font-size:15px;color:#00264D;"><?= count($merch_inventory) ?></span>
                            <span style="font-weight:500;color:#667085;"> items &mdash; </span>
                            <span style="font-size:15px;color:#00264D;"><?= count($sorted) ?></span>
                            <span style="font-weight:500;color:#667085;"> categories</span>
                        </td>
                        <td></td>
                        <td style="font-weight:700;color:#002F70;">&#8369;<?= number_format($total_val, 2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="adminMerchPagination" style="margin:10px 20px;"></div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>Edit Stock Level &mdash; Correction</h3>
        <div class="info-note">Use only to correct discrepancies. All changes are logged for audit.</div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_stock">
            <input type="hidden" name="product_id" id="editProductId">
            <div id="editProductInfo" style="background:#f8f9fa;border-radius:6px;padding:10px 12px;margin-bottom:12px;font-size:13px;"></div>
            <div class="form-group-m">
                <label>New Stock Quantity</label>
                <input type="number" name="new_qty" id="editNewQty" min="0" step="1" required>
                <div id="editCapNote" style="font-size:11px;color:#6c757d;margin-top:3px;"></div>
            </div>
            <div class="form-group-m">
                <label>Reason / Note <span style="color:#dc3545;">*</span></label>
                <textarea name="note" rows="2" placeholder="e.g. Corrected after physical count..." required></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" onclick="document.getElementById('editModal').classList.remove('show')"
                        style="padding:8px 18px;background:#6c757d;color:#fff;border:none;border-radius:5px;cursor:pointer;">Cancel</button>
                <button type="submit" class="btn btn-edit" style="padding:8px 18px;">Save Correction</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(id, name, current, cap) {
    document.getElementById('editProductId').value = id;
    document.getElementById('editNewQty').value    = current;
    document.getElementById('editNewQty').max      = cap > 0 ? cap : 99999;
    document.getElementById('editProductInfo').innerHTML =
        '<strong>' + name + '</strong> &nbsp;|&nbsp; Current: <strong>' + current + ' units</strong>' +
        (cap > 0 ? ' &nbsp;|&nbsp; Max: ' + cap + ' units' : '');
    document.getElementById('editCapNote').textContent = cap > 0 ? 'Max allowed: ' + cap + ' units' : '';
    document.getElementById('editModal').classList.add('show');
}
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('show');
});
document.addEventListener('DOMContentLoaded', function() {
    if (typeof setupTablePagination === 'function')
        setupTablePagination('adminMerchTable', 'adminMerchRowsLimit', 'adminMerchPagination', 50);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
