<?php
$page_id = 'pm_merchandise';
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

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_product') {
        $name       = trim($_POST['product_name'] ?? '');
        $category   = trim($_POST['category']     ?? 'Merchandise');
        $sku        = trim($_POST['sku']           ?? '');
        $unit_cost  = (float)($_POST['unit_cost']  ?? 0);
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        $stock      = (int)($_POST['stock_level']  ?? 0);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO inventory_products
                    (product_name, category, sku, unit_cost, unit_price, stock, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$name, $category, $sku, $unit_cost, $unit_price, $stock]);
            $stmt = $pdo->prepare("INSERT INTO audit_log (station_id, user_id, action, details, created_at) VALUES (?, ?, 'Product Added', ?, NOW())");
            $stmt->execute([$station_id, $me['id'], "Merchandise product '$name' added"]);
            $_SESSION['success'] = "Merchandise product '$name' added successfully.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error adding product: ' . $e->getMessage();
        }
        header('Location: manager_product_merchandise.php'); exit;
    }

    if ($action === 'update_product') {
        $id         = (int)($_POST['product_id']   ?? 0);
        $name       = trim($_POST['product_name']  ?? '');
        $category   = trim($_POST['category']      ?? 'Merchandise');
        $sku        = trim($_POST['sku']            ?? '');
        $unit_cost  = (float)($_POST['unit_cost']  ?? 0);
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        $stock      = (int)($_POST['stock_level']  ?? 0);
        try {
            $stmt = $pdo->prepare("
                UPDATE inventory_products
                SET product_name=?, category=?, sku=?, unit_cost=?, unit_price=?, stock=?
                WHERE id=?
            ");
            $stmt->execute([$name, $category, $sku, $unit_cost, $unit_price, $stock, $id]);
            $stmt = $pdo->prepare("INSERT INTO audit_log (station_id, user_id, action, details, created_at) VALUES (?, ?, 'Product Updated', ?, NOW())");
            $stmt->execute([$station_id, $me['id'], "Merchandise product '$name' (ID:$id) updated"]);
            $_SESSION['success'] = "Product '$name' updated.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error updating: ' . $e->getMessage();
        }
        header('Location: manager_product_merchandise.php'); exit;
    }

    if ($action === 'toggle_status') {
        // No status column — adjust stock to 0 to mark as inactive, or restore a minimum
        $id        = (int)($_POST['product_id'] ?? 0);
        $newStatus = $_POST['status'] ?? 'active';
        try {
            if ($newStatus === 'inactive') {
                // Store current stock in a note by setting stock to -1 as a soft-disable flag
                $stmt = $pdo->prepare("UPDATE inventory_products SET stock = 0 WHERE id=?");
            } else {
                // Re-activate: just ensure stock is at least 1 so it shows as active
                $stmt = $pdo->prepare("UPDATE inventory_products SET stock = GREATEST(stock, 1) WHERE id=?");
            }
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("INSERT INTO audit_log (station_id, user_id, action, details, created_at) VALUES (?, ?, 'Product Status Changed', ?, NOW())");
            $stmt->execute([$station_id, $me['id'], "Merchandise product ID:$id stock set to " . ($newStatus === 'inactive' ? '0 (deactivated)' : 'active')]);
            $_SESSION['success'] = 'Product status updated.';
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: manager_product_merchandise.php'); exit;
    }
}

// ── Load data ──────────────────────────────────────────────────────────────
$products = [];
$msg      = '';

try {
    $stmt = $pdo->prepare("
        SELECT id, product_name, category, sku, unit_cost, unit_price, stock
        FROM inventory_products
        WHERE category NOT IN ('Fuel')
        ORDER BY category, product_name
    ");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading merchandise products: ' . $e->getMessage();
}

// Distinct categories for filter dropdown
$categories = array_unique(array_column($products, 'category'));
sort($categories);

include __DIR__ . '/../partials/header.php';
?>
<style>
.pm-table th { background:#f1f3f4; font-weight:600; color:#333; border-bottom:2px solid #dee2e6; }
.pm-table td { vertical-align:middle; }
.action-col { display:flex; flex-direction:column; gap:4px; }
.action-col .btn { width:100%; font-size:12px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:flex; align-items:center; gap:5px; justify-content:center; }
.btn-view    { background:#28a745; color:#fff; }
.btn-edit    { background:#002F70; color:#fff; }
.btn-danger  { background:#dc3545; color:#fff; }
.btn-success { background:#28a745; color:#fff; }
.badge-status { padding:4px 10px; border-radius:4px; font-size:12px; font-weight:600; color:#fff; }
.badge-cat    { padding:3px 9px; border-radius:4px; font-size:11px; font-weight:600; color:#fff; background:#6c757d; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-shopping-bag"></i> Merchandise Products</h1>
        <div class="sub">Product Management &mdash; Merchandise catalog</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
        <button onclick="openModal('addModal')" class="btn primary"><i class="fas fa-plus"></i> Add Product</button>
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

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list" style="color:#002F70;"></i> Merchandise Product List</h3>
        <div class="header-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="catFilter" class="form-control" style="width:180px;" onchange="filterTable()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo strtolower(htmlspecialchars($cat)); ?>">
                        <?php echo htmlspecialchars($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="merchSearch" placeholder="Search..." class="form-control" style="width:200px;" oninput="filterTable()">
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table pm-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>SKU</th>
                        <th>Unit Cost</th>
                        <th>Unit Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php foreach ($products as $p):
                    $stock        = (int)($p['stock'] ?? 0);
                    $isActive     = $stock > 0;
                    $toggleTarget = $isActive ? 'inactive' : 'active';
                    $toggleLabel  = $isActive ? 'Deactivate' : 'Activate';
                    $toggleClass  = $isActive ? 'btn-danger' : 'btn-success';
                    $stockColor   = $stock <= 0 ? '#dc3545' : ($stock <= 10 ? '#ff9500' : '#28a745');
                    $statusLabel  = $isActive ? 'Active' : 'Inactive';
                    $statusColor  = $isActive ? '#28a745' : '#dc3545';
                ?>
                <tr data-name="<?php echo strtolower(htmlspecialchars($p['product_name'])); ?>"
                    data-cat="<?php echo strtolower(htmlspecialchars($p['category'] ?? '')); ?>">
                    <td>#<?php echo (int)$p['id']; ?></td>
                    <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                    <td><span class="badge-cat"><?php echo htmlspecialchars($p['category'] ?? 'Merchandise'); ?></span></td>
                    <td style="color:#6c757d;font-size:12px;"><?php echo htmlspecialchars($p['sku'] ?? '—'); ?></td>
                    <td style="color:#6c757d;">₱<?php echo number_format((float)$p['unit_cost'], 2); ?></td>
                    <td style="color:#28a745;font-weight:700;">₱<?php echo number_format((float)$p['unit_price'], 2); ?></td>
                    <td><span class="badge-status" style="background:<?php echo $stockColor; ?>;"><?php echo $stock; ?></span></td>
                    <td><span class="badge-status" style="background:<?php echo $statusColor; ?>;"><?php echo $statusLabel; ?></span></td>
                    <td>
                        <div class="action-col">
                            <button class="btn btn-view" onclick="viewProduct(<?php echo $p['id']; ?>)"><i class="fas fa-eye"></i> View</button>
                            <button class="btn btn-edit" onclick="editProduct(<?php echo $p['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                            <button class="btn <?php echo $toggleClass; ?>" onclick="toggleStatus(<?php echo $p['id']; ?>, '<?php echo $toggleTarget; ?>')">
                                <i class="fas <?php echo $isActive ? 'fa-times' : 'fa-check'; ?>"></i> <?php echo $toggleLabel; ?>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                    <tr><td colspan="9" style="text-align:center;padding:40px;color:#666;">No merchandise products found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus" style="color:#28a745;"></i> Add Merchandise Product</h3>
            <button class="close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_product">
            <div class="modal-body">
                <div class="form-group">
                    <label>Product Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="product_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Category <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="category" class="form-control" value="Merchandise" required>
                </div>
                <div class="form-group">
                    <label>SKU</label>
                    <input type="text" name="sku" class="form-control" placeholder="e.g. MERCH-001">
                </div>
                <div class="form-group">
                    <label>Unit Cost (₱) <span style="color:#dc3545;">*</span></label>
                    <input type="number" name="unit_cost" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Unit Price (₱) <span style="color:#dc3545;">*</span></label>
                    <input type="number" name="unit_price" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Stock Level <span style="color:#dc3545;">*</span></label>
                    <input type="number" name="stock_level" class="form-control" min="0" value="0" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn primary">Add Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Product Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit" style="color:#002F70;"></i> Edit Merchandise Product</h3>
            <button class="close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="product_id" id="editProductId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Product Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="product_name" id="editProductName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Category <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="category" id="editProductCat" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>SKU</label>
                    <input type="text" name="sku" id="editProductSku" class="form-control">
                </div>
                <div class="form-group">
                    <label>Unit Cost (₱) <span style="color:#dc3545;">*</span></label>
                    <input type="number" name="unit_cost" id="editProductCost" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Unit Price (₱) <span style="color:#dc3545;">*</span></label>
                    <input type="number" name="unit_price" id="editProductPrice" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Stock Level <span style="color:#dc3545;">*</span></label>
                    <input type="number" name="stock_level" id="editProductStock" class="form-control" min="0" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn primary">Update Product</button>
            </div>
        </form>
    </div>
</div>

<!-- View Product Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-eye" style="color:#28a745;"></i> Product Details</h3>
            <button class="close" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:grid;gap:12px;">
                <div><label style="font-weight:600;color:#666;">Product Name</label><div id="vName" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;"></div></div>
                <div><label style="font-weight:600;color:#666;">Category</label><div id="vCat" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;"></div></div>
                <div><label style="font-weight:600;color:#666;">SKU</label><div id="vSku" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;"></div></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><label style="font-weight:600;color:#666;">Unit Cost</label><div id="vCost" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;color:#6c757d;font-weight:700;"></div></div>
                    <div><label style="font-weight:600;color:#666;">Unit Price</label><div id="vPrice" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;color:#28a745;font-weight:700;"></div></div>
                </div>
                <div><label style="font-weight:600;color:#666;">Stock Level</label><div id="vStock" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;"></div></div>
                <div><label style="font-weight:600;color:#666;">Status</label><div id="vStatus" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;"></div></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn ghost" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- Toggle Status Form -->
<form id="toggleForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="product_id" id="tProductId">
    <input type="hidden" name="status" id="tProductStatus">
</form>

<style>
.modal{display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.5);align-items:center;justify-content:center;}
.modal.open{display:flex;}
.modal-content{background:#fff;border-radius:12px;width:90%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid #e9ecef;}
.modal-header h3{margin:0;font-size:18px;font-weight:600;}
.close{background:none;border:none;font-size:28px;cursor:pointer;color:#aaa;}
.close:hover{color:#000;}
.modal-body{padding:24px;}
.modal-footer{display:flex;justify-content:flex-end;gap:12px;padding:20px 24px;border-top:1px solid #e9ecef;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;margin-bottom:6px;font-weight:500;color:#333;}
.form-control{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;}
</style>

<script>
const productData = <?php echo json_encode($products); ?>;

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function viewProduct(id) {
    const p = productData.find(x => x.id == id);
    if (!p) return;
    document.getElementById('vName').textContent  = p.product_name;
    document.getElementById('vCat').textContent   = p.category || 'Merchandise';
    document.getElementById('vSku').textContent   = p.sku || '—';
    document.getElementById('vCost').textContent  = '₱' + parseFloat(p.unit_cost).toFixed(2);
    document.getElementById('vPrice').textContent = '₱' + parseFloat(p.unit_price).toFixed(2);
    document.getElementById('vStock').textContent = p.stock + ' units';
    document.getElementById('vStatus').textContent = parseInt(p.stock) > 0 ? 'Active' : 'Inactive';
    openModal('viewModal');
}

function editProduct(id) {
    const p = productData.find(x => x.id == id);
    if (!p) return;
    document.getElementById('editProductId').value    = p.id;
    document.getElementById('editProductName').value  = p.product_name;
    document.getElementById('editProductCat').value   = p.category || 'Merchandise';
    document.getElementById('editProductSku').value   = p.sku || '';
    document.getElementById('editProductCost').value  = parseFloat(p.unit_cost).toFixed(2);
    document.getElementById('editProductPrice').value = parseFloat(p.unit_price).toFixed(2);
    document.getElementById('editProductStock').value = p.stock;
    openModal('editModal');
}

function toggleStatus(id, newStatus) {
    const label = newStatus === 'inactive' ? 'deactivate' : 'activate';
    if (!confirm('Are you sure you want to ' + label + ' this product?')) return;
    document.getElementById('tProductId').value     = id;
    document.getElementById('tProductStatus').value = newStatus;
    document.getElementById('toggleForm').submit();
}

function filterTable() {
    const q   = document.getElementById('merchSearch').value.toLowerCase();
    const cat = document.getElementById('catFilter').value.toLowerCase();
    document.querySelectorAll('#merchTableBody tr').forEach(row => {
        const nameMatch = (row.dataset.name || '').includes(q);
        const catMatch  = cat === '' || (row.dataset.cat || '') === cat;
        row.style.display = (nameMatch && catMatch) ? '' : 'none';
    });
}

window.addEventListener('click', e => { if (e.target.classList.contains('modal')) e.target.classList.remove('open'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open')); });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
