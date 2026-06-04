<?php
$page_id = 'mgr_inv_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'validate_product') {
        $id = (int)($_POST['product_id'] ?? 0);
        if ($id) {
            try {
                $stmt = $pdo->prepare("SELECT product_name FROM inventory_products WHERE id=?");
                $stmt->execute([$id]);
                $pname = $stmt->fetchColumn();

                $pdo->prepare("UPDATE inventory_products SET status='active' WHERE id=?")->execute([$id]);
                log_activity($pdo, $me['id'], 'Product Validated', "Pending merchandise product '$pname' (ID:$id) validated by {$me['name']}");
                $_SESSION['success'] = "Product '$pname' has been validated and is now active.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error validating product: ' . $e->getMessage();
            }
        }
        header('Location: manager_inventory_merchandise.php'); exit;
    }
}

$merch_inventory = [];
$categories      = [];
$msg = '';
try {
    $stmt = $pdo->prepare("
        SELECT ip.id,
               ip.product_name AS name,
               ip.category     AS category_name,
               ip.unit_price   AS price,
               ip.unit_cost    AS cost,
               ip.sku,
               ip.status,
               COALESCE(si.stock_level, ip.stock, 0) AS stock_level,
               COALESCE(si.reorder_level, 10)        AS reorder_level
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

include __DIR__ . '/../partials/header.php';
?>
<style>
.inv-card {
    background:#fff; border-radius:12px;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    border:1px solid #e9ecef; margin-bottom:20px;
}
.inv-card-head {
    display:flex; align-items:center; justify-content:space-between;
    padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px;
}
.inv-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.inv-card-body  { padding:20px; }
.cat-header td {
    font-weight:700; background:#e9ecef !important; color:#495057 !important;
    text-transform:uppercase; font-size:.8em; letter-spacing:.5px;
    border-bottom:2px solid #dee2e6; padding:8px 12px;
}
.merch-row:hover { background:#f8f9fa; }
.cost-col  { color:#6c757d; font-size:.9em; }
.price-col { color:#28a745; font-weight:700; }
.profit-sm { font-size:.76em; color:#17a2b8; margin-left:3px; }
#merchSearch {
    padding:8px 12px; border:1px solid #ced4da;
    border-radius:4px; font-size:14px; width:100%;
}
#merchSearch:focus { border-color:#80bdff; outline:0; box-shadow:0 0 0 .2rem rgba(0,123,255,.25); }
.search-wrap { max-width:300px; margin-bottom:14px; }
.readonly-badge {
    display:inline-flex; align-items:center; gap:5px;
    background:#e3f2fd; color:#1565c0; border:1px solid #90caf9;
    border-radius:20px; padding:3px 11px; font-size:11px; font-weight:600;
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-box"></i> Merchandise Inventory</h1>
        <div class="sub">REVIEW AND UPDATE MERCHANDISE PRICING AND PRODUCT DETAILS.</div>
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

<?php require_once __DIR__ . '/../partials/manager_inventory_summary.php'; ?>

<div class="mb-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-box"></i> Merchandise Stock</h4>
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:13px;color:#6c757d;"><?php echo count($merch_inventory); ?> products</span>
        </div>
    </div>

    <div class="search-wrap mb-3">
        <input id="merchSearch" class="form-control" placeholder="&#128269; Search products..." autocomplete="off" />
    </div>

    <div class="table-wrap">
            <table class="table" id="mgrMerchTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Cost</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php
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

                foreach ($sorted as $cat_label => $items): ?>
                    <tr class="cat-header">
                        <td colspan="7"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td>
                    </tr>
                    <?php foreach ($items as $item):
                        $stock  = (float)($item['stock_level'] ?? 0);
                        $reord  = (float)($item['reorder_level'] ?? 10);
                        $status = $item['status'] ?? 'active';
                        $isPending = ($status === 'pending' || $status === 'pending_validation');
                        $st     = $isPending ? 'PENDING VALIDATION' : ($stock <= 0 ? 'OUT OF STOCK' : ($stock <= $reord ? 'LOW STOCK' : 'AVAILABLE'));
                        $sc     = $isPending ? '#fd7e14' : ($stock <= 0 ? '#dc3545' : ($stock <= $reord ? '#fd7e14' : '#28a745'));
                        $profit = (float)($item['price'] ?? 0) - (float)($item['cost'] ?? 0);
                    ?>
                    <tr class="merch-row" data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>">
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td><?php echo htmlspecialchars($item['sku'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></td>
                        <td><?php echo number_format($stock, 0); ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="color:<?php echo $sc; ?>;font-weight:700;"><?php echo $st; ?></span>
                                <?php if ($isPending): ?>
                                    <button class="btn btn-success btn-sm" onclick="validateProduct(<?php echo (int)$item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>')" style="padding:2px 8px;font-size:11px;background:#28a745;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
                                        <i class="fas fa-check"></i> Validate
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="cost-col">&#8369;<?php echo number_format($item['cost'], 2); ?></td>
                        <td class="price-col">
                            &#8369;<?php echo number_format($item['price'], 2); ?>
                            <?php if ($profit > 0): ?>
                                <span class="profit-sm">(+<?php echo number_format($profit, 2); ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <?php if (empty($merch_inventory)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:28px;color:#6c757d;">No merchandise data available.</td></tr>
                <?php else: ?>
                    <tr style="background:#f8f9fa;font-weight:700;border-top:2px solid #dee2e6;">
                        <td colspan="4">TOTAL</td>
                        <td colspan="3">
                            <span style="font-size:15px;color:#00264D;"><?php echo count($merch_inventory); ?></span>
                            <span style="font-weight:500;color:#667085;"> items &mdash; </span>
                            <span style="font-size:15px;color:#00264D;"><?php echo count($categories); ?></span>
                            <span style="font-weight:500;color:#667085;"> categories</span>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="mgrMerchPagination" style="margin-top:10px;"></div>
</div>

<script>
document.getElementById('merchSearch').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        r.style.display = (r.getAttribute('data-name') || '').indexOf(q) !== -1 ? '' : 'none';
    });
});

function validateProduct(id, name) {
    if (!confirm('Are you sure you want to validate and approve the product "' + name + '"?')) return;
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    var actInput = document.createElement('input');
    actInput.name = 'action';
    actInput.value = 'validate_product';
    form.appendChild(actInput);
    
    var idInput = document.createElement('input');
    idInput.name = 'product_id';
    idInput.value = id;
    form.appendChild(idInput);
    
    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('DOMContentLoaded', function() {
    setupTablePagination('mgrMerchTable', 'mgrMerchRowsLimit', 'mgrMerchPagination', 50);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
