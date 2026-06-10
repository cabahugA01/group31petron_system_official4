<?php
$page_id = 'mgr_prod_fuel';
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
        $name        = trim($_POST['product_name'] ?? '');
        $unit_price  = (float)($_POST['unit_price']  ?? 0);
        $unit_cost   = (float)($_POST['unit_cost']   ?? $unit_price);
        $stock_level = (int)($_POST['stock_level']   ?? 0);
        try {
            $stmt = $pdo->prepare("INSERT INTO inventory_products (station_id, product_name, category, unit_cost, unit_price, stock, status) VALUES (?, ?, 'Fuel', ?, ?, ?, 'active')");
            $stmt->execute([$station_id, $name, $unit_cost, $unit_price, $stock_level]);
            $stmt = $pdo->prepare("INSERT INTO audit_log (station_id, user_id, action, details, created_at) VALUES (?, ?, 'Product Added', ?, NOW())");
            $stmt->execute([$station_id, $me['id'], "Fuel product '$name' added"]);
            $_SESSION['success'] = "Fuel product '$name' added successfully.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error adding product: ' . $e->getMessage();
        }
        header('Location: manager_product_fuel.php'); exit;
    }

    if ($action === 'update_product') {
        $id          = (int)($_POST['product_id']    ?? 0);
        $name        = trim($_POST['product_name']   ?? '');
        $unit_cost   = (float)($_POST['unit_cost']   ?? 0);
        $unit_price  = (float)($_POST['unit_price']  ?? $unit_cost);
        $stock_level = (int)($_POST['stock_level']   ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT unit_cost, unit_price FROM inventory_products WHERE id=?");
            $stmt->execute([$id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($old && ((float)$old['unit_cost'] != $unit_cost || (float)$old['unit_price'] != $unit_price)) {
                $stmt = $pdo->prepare("UPDATE inventory_products SET product_name=?, stock=? WHERE id=?");
                $stmt->execute([$name, $stock_level, $id]);
                
                $pdo->prepare("INSERT INTO pending_price_approvals (station_id, product_type, product_id, old_cost, new_cost, old_price, new_price, manager_id, status) VALUES (?, 'fuel', ?, ?, ?, ?, ?, ?, 'pending')")
                    ->execute([$station_id, $id, $old['unit_cost'], $unit_cost, $old['unit_price'], $unit_price, $me['id']]);
                
                $log_msg = "Fuel product '$name' updated. Price change submitted: Cost {$old['unit_cost']}->{$unit_cost}, Price {$old['unit_price']}->{$unit_price} (Pending Approval)";
                $_SESSION['success'] = "Product details updated. Price change submitted for Admin approval.";
            } else {
                $stmt = $pdo->prepare("UPDATE inventory_products SET product_name=?, unit_cost=?, unit_price=?, stock=? WHERE id=?");
                $stmt->execute([$name, $unit_cost, $unit_price, $stock_level, $id]);
                $log_msg = "Fuel product '$name' updated";
                $_SESSION['success'] = "Product '$name' updated.";
            }

            $stmt = $pdo->prepare("INSERT INTO audit_log (station_id, user_id, action, details, created_at) VALUES (?, ?, 'Product Updated', ?, NOW())");
            $stmt->execute([$station_id, $me['id'], $log_msg]);
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error updating: ' . $e->getMessage();
        }
        header('Location: manager_product_fuel.php'); exit;
    }

    if ($action === 'toggle_status') {
        $id     = (int)($_POST['product_id'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        try {
            $stmt = $pdo->prepare("UPDATE inventory_products SET status=? WHERE id=?");
            $stmt->execute([$status, $id]);
            $_SESSION['success'] = 'Status updated.';
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: manager_product_fuel.php'); exit;
    }

    if ($action === 'update_fuel_inventory') {
        $id              = (int)($_POST['fuel_id']        ?? 0);
        $price_per_liter = (float)($_POST['price_per_liter'] ?? 0);
        $status          = $_POST['fuel_status']          ?? 'Normal';
        try {
            $stmt = $pdo->prepare("SELECT price_per_liter FROM fuel_inventory WHERE id=?");
            $stmt->execute([$id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($old && (float)$old['price_per_liter'] != $price_per_liter) {
                $stmt = $pdo->prepare("UPDATE fuel_inventory SET status=?, last_updated=NOW(), updated_by=? WHERE id=?");
                $stmt->execute([$status, $me['id'], $id]);
                
                $pdo->prepare("INSERT INTO pending_price_approvals (station_id, product_type, product_id, old_cost, new_cost, old_price, new_price, manager_id, status) VALUES (?, 'fuel_inventory', ?, ?, ?, ?, ?, ?, 'pending')")
                    ->execute([$station_id, $id, $old['price_per_liter'], $price_per_liter, $old['price_per_liter'], $price_per_liter, $me['id']]);
                
                $log_msg = "Fuel inventory id=$id updated status=$status. Price change submitted: {$old['price_per_liter']}->{$price_per_liter} (Pending Approval)";
                $_SESSION['success'] = "Fuel status updated. Price change submitted for Admin approval.";
            } else {
                $stmt = $pdo->prepare("UPDATE fuel_inventory SET price_per_liter=?, status=?, last_updated=NOW(), updated_by=? WHERE id=?");
                $stmt->execute([$price_per_liter, $status, $me['id'], $id]);
                $log_msg = "Fuel inventory id=$id updated price=₱$price_per_liter status=$status";
                $_SESSION['success'] = 'Fuel inventory updated.';
            }

            $stmt = $pdo->prepare("INSERT INTO audit_log (station_id, user_id, action, details, created_at) VALUES (?, ?, 'Fuel Updated', ?, NOW())");
            $stmt->execute([$station_id, $me['id'], $log_msg]);
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: manager_product_fuel.php'); exit;
    }

    if ($action === 'toggle_fuel_status') {
        $id         = (int)($_POST['fuel_id'] ?? 0);
        $raw_status = $_POST['status'] ?? 'Normal';
        $status     = $raw_status === 'active' ? 'Normal' : ($raw_status === 'inactive' ? 'Inactive' : $raw_status);
        try {
            $stmt = $pdo->prepare("UPDATE fuel_inventory SET status=?, last_updated=NOW(), updated_by=? WHERE id=?");
            $stmt->execute([$status, $me['id'], $id]);
            $_SESSION['success'] = 'Fuel status updated.';
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
        header('Location: manager_product_fuel.php'); exit;
    }
}

// ── Load data ──────────────────────────────────────────────────────────────
$required_fuel_types = ['Diesel', 'Kerosene', 'Turbo Diesel', 'XCS Plus', 'XTRA UNL'];
$fuel_products = [];
$msg = '';

try {
    $stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE station_id=? ORDER BY fuel_type");
    $stmt->execute([$station_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        $stmt = $pdo->query("SELECT * FROM fuel_inventory ORDER BY fuel_type");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($required_fuel_types as $ft) {
        $found = false;
        foreach ($rows as $row) {
            if (strcasecmp(trim($row['fuel_type']), $ft) === 0) {
                $fuel_products[] = [
                    'id'             => $row['id'],
                    'product_name'   => $ft,
                    'unit_cost'      => (float)($row['price_per_liter'] ?? 0),
                    'unit_price'     => (float)($row['price_per_liter'] ?? 0),
                    'quantity'       => (float)($row['current_level']   ?? 0),
                    'status'         => strtolower($row['status'] ?? 'normal') === 'normal' ? 'active' : 'inactive',
                    'display_status' => $row['status'] ?? 'Normal',
                    'source'         => 'fuel_inventory',
                ];
                $found = true; break;
            }
        }
        if (!$found) {
            // fallback from inventory_products
            $stmt2 = $pdo->prepare("SELECT * FROM inventory_products WHERE category='Fuel' AND product_name=? LIMIT 1");
            $stmt2->execute([$ft]);
            $ip = $stmt2->fetch(PDO::FETCH_ASSOC);
            $fuel_products[] = [
                'id'             => $ip['id'] ?? null,
                'product_name'   => $ft,
                'unit_cost'      => (float)($ip['unit_cost']  ?? 0),
                'unit_price'     => (float)($ip['unit_price'] ?? 0),
                'quantity'       => (float)($ip['stock']      ?? 0),
                'status'         => $ip['status'] ?? 'inactive',
                'display_status' => 'Missing',
                'source'         => 'inventory_products',
            ];
        }
    }
} catch (Exception $e) {
    $msg = 'Error loading fuel products: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* === Fuel Product Management - Clean Table Design === */
.card { background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.08); border:1px solid #e9ecef; margin-bottom:20px; overflow:hidden; }
.card-header { padding:16px 20px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.card-header h3 { font-size:16px; font-weight:700; color:#002F70; margin:0; display:flex; align-items:center; gap:8px; }
.card-body { padding:20px; overflow-x:hidden; }
.table-wrap { overflow-x:auto; width:100%; }
.pm-table { width:100%; border-collapse:collapse; table-layout:auto; }
.pm-table thead th { background:#002F70 !important; color:#fff !important; font-weight:600; padding:14px 12px !important; text-align:left !important; text-transform:uppercase; letter-spacing:0.3px; border:none !important; font-size:11px; }
.pm-table thead th:last-child { text-align:center !important; }
.pm-table tbody td { vertical-align:middle; padding:12px !important; border-bottom:1px solid #e9ecef !important; font-size:13px; }
.pm-table tbody td:last-child { text-align:center !important; }
.pm-table tbody tr:hover td { background:#e3f2fd !important; }
.pm-table tbody tr { transition:background 0.2s ease; }
.action-col { display:flex; flex-direction:column; gap:4px; width:90px; min-width:90px; align-items:center; justify-content:center; }
.action-col .btn { width:100%; font-size:11px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:flex; align-items:center; gap:5px; justify-content:center; }
.btn-view   { background:#28a745; color:#fff; }
.btn-edit   { background:#002F70; color:#fff; }
.btn-danger { background:#dc3545; color:#fff; }
.btn-success{ background:#28a745; color:#fff; }
.badge-status { padding:4px 10px; border-radius:4px; font-size:12px; font-weight:700; background:transparent !important; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-gas-pump"></i> Fuel Products</h1>
        <div class="sub">Product Management &mdash; Fuel catalog</div>
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
        <h3><i class="fas fa-list" style="color:#002F70;"></i> Fuel Product List</h3>
        <div class="header-actions">
            <input type="text" id="fuelSearch" placeholder="Search..." class="form-control" style="width:200px;">
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
                        <th>Unit Price</th>
                        <th>Stock Level</th>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="fuelTableBody">
                <?php foreach ($fuel_products as $p):
                    $isNormal     = strtolower($p['display_status']) === 'normal';
                    $toggleTarget = $isNormal ? 'inactive' : 'active';
                    $toggleLabel  = $isNormal ? 'Deactivate' : 'Activate';
                    $toggleClass  = $isNormal ? 'btn-danger' : 'btn-success';
                    $stockLevel   = $p['quantity'];
                    $stockColor   = $stockLevel <= 500 ? '#dc3545' : ($stockLevel <= 2000 ? '#ff9500' : '#28a745');
                    $statusColor  = $isNormal ? '#28a745' : '#dc3545';
                    if (stripos($p['display_status'], 'low') !== false)      $statusColor = '#ff9500';
                    if (stripos($p['display_status'], 'critical') !== false) $statusColor = '#dc3545';
                ?>
                <tr data-name="<?php echo strtolower(htmlspecialchars($p['product_name'])); ?>">
                    <td><?php echo $p['id'] ? '#'.$p['id'] : 'N/A'; ?></td>
                    <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                    <td><span style="color:#ff6b35;font-weight:700;">Fuel</span></td>
                    <td>₱<?php echo number_format($p['unit_cost'], 2); ?></td>
                    <td><span style="color:<?php echo $stockColor; ?>;font-weight:700;"><?php echo number_format($stockLevel, 2); ?> L</span></td>
                    <td>Petron Corporation</td>
                    <td><span style="color:<?php echo $statusColor; ?>;font-weight:700;"><?php echo htmlspecialchars($p['display_status']); ?></span></td>
                    <td style="text-align:center;">
                        <?php if ($p['id']): ?>
                        <div class="action-col">
                            <button class="btn btn-view" onclick="viewFuel(<?php echo $p['id']; ?>)"><i class="fas fa-eye"></i> View</button>
                            <button class="btn btn-edit" onclick="editFuel(<?php echo $p['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                            <button class="btn <?php echo $toggleClass; ?>" onclick="toggleFuelStatus(<?php echo $p['id']; ?>, '<?php echo $toggleTarget; ?>')">
                                <i class="fas <?php echo $isNormal ? 'fa-times' : 'fa-check'; ?>"></i> <?php echo $toggleLabel; ?>
                            </button>
                        </div>
                        <?php else: ?>
                            <span style="color:#6c757d;font-size:12px;font-weight:700;">Missing Data</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($fuel_products)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:40px;color:#666;">No fuel products found.</td></tr>
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
            <h3><i class="fas fa-plus" style="color:#28a745;"></i> Add Fuel Product</h3>
            <button class="close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_product">
            <div class="modal-body">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" name="product_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <input type="text" class="form-control" value="Fuel" readonly style="background:#f8f9fa;cursor:not-allowed;">
                </div>
                <div class="form-group">
                    <label>Unit Cost (₱)</label>
                    <input type="number" name="unit_cost" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Unit Price (₱)</label>
                    <input type="number" name="unit_price" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Stock Level (L)</label>
                    <input type="number" name="stock_level" class="form-control" min="0" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn primary">Add Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Fuel Inventory Modal -->
<div id="editFuelModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit" style="color:#002F70;"></i> Edit Fuel Inventory</h3>
            <button class="close" onclick="closeModal('editFuelModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_fuel_inventory">
            <input type="hidden" name="fuel_id" id="editFuelId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Fuel Type</label>
                    <input type="text" id="editFuelType" class="form-control" readonly style="background:#f8f9fa;cursor:not-allowed;">
                </div>
                <div class="form-group">
                    <label>Price per Liter (₱)</label>
                    <input type="number" name="price_per_liter" id="editFuelPrice" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="fuel_status" id="editFuelStatusSel" class="form-control">
                        <option value="Normal">Normal</option>
                        <option value="Low Stock">Low Stock</option>
                        <option value="Critical">Critical</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Current Level (L)</label>
                    <input type="text" id="editFuelLevel" class="form-control" readonly style="background:#f8f9fa;cursor:not-allowed;">
                    <small style="color:#6c757d;font-size:11px;">Managed through fuel deliveries and sales</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('editFuelModal')">Cancel</button>
                <button type="submit" class="btn primary">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- View Fuel Modal -->
<div id="viewFuelModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-eye" style="color:#28a745;"></i> Fuel Product Details</h3>
            <button class="close" onclick="closeModal('viewFuelModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:grid;gap:12px;">
                <div><label style="font-weight:600;color:#666;">Fuel Type</label><div id="vFuelType" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;"></div></div>
                <div><label style="font-weight:600;color:#666;">Price per Liter</label><div id="vFuelPrice" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;color:#28a745;font-weight:700;"></div></div>
                <div><label style="font-weight:600;color:#666;">Current Level</label><div id="vFuelLevel" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;"></div></div>
                <div><label style="font-weight:600;color:#666;">Status</label><div id="vFuelStatus" style="padding:8px 12px;background:#f8f9fa;border-radius:6px;"></div></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn ghost" onclick="closeModal('viewFuelModal')">Close</button>
        </div>
    </div>
</div>

<!-- Toggle Fuel Status Form -->
<form id="toggleFuelForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="toggle_fuel_status">
    <input type="hidden" name="fuel_id" id="tFuelId">
    <input type="hidden" name="status" id="tFuelStatus">
</form>

<style>
.modal{display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.5);align-items:center;justify-content:center;}
.modal.open{display:flex;}
.modal-content{background:#fff;border-radius:12px;width:90%;max-width:500px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);}
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
const fuelData = <?php echo json_encode($fuel_products); ?>;

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function viewFuel(id) {
    const p = fuelData.find(f => f.id == id);
    if (!p) return;
    document.getElementById('vFuelType').textContent  = p.product_name;
    document.getElementById('vFuelPrice').textContent = '₱' + parseFloat(p.unit_cost).toFixed(2) + ' / L';
    document.getElementById('vFuelLevel').textContent = parseFloat(p.quantity).toFixed(2) + ' L';
    document.getElementById('vFuelStatus').textContent = p.display_status;
    openModal('viewFuelModal');
}

function editFuel(id) {
    const p = fuelData.find(f => f.id == id);
    if (!p) return;
    document.getElementById('editFuelId').value        = p.id;
    document.getElementById('editFuelType').value      = p.product_name;
    document.getElementById('editFuelPrice').value     = parseFloat(p.unit_cost).toFixed(2);
    document.getElementById('editFuelStatusSel').value = p.display_status || 'Normal';
    document.getElementById('editFuelLevel').value     = parseFloat(p.quantity).toFixed(2) + ' L';
    openModal('editFuelModal');
}

function toggleFuelStatus(id, newStatus) {
    const label = newStatus === 'inactive' ? 'deactivate' : 'activate';
    if (!confirm('Are you sure you want to ' + label + ' this fuel product?')) return;
    document.getElementById('tFuelId').value     = id;
    document.getElementById('tFuelStatus').value = newStatus;
    document.getElementById('toggleFuelForm').submit();
}

document.getElementById('fuelSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#fuelTableBody tr').forEach(row => {
        row.style.display = (row.dataset.name || '').includes(q) ? '' : 'none';
    });
});

window.addEventListener('click', e => { if (e.target.classList.contains('modal')) e.target.classList.remove('open'); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open')); });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
