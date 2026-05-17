<?php
/**
 * Merchandise Products — Product Management
 * Manager/Admin view: list, add, edit, activate/deactivate merchandise products.
 * Batch IDs are auto-generated from approved deliveries (no manual batch creation here).
 */
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

try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN min_stock INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN max_stock INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN sku VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE inventory_products ADD COLUMN station_id INT NOT NULL DEFAULT 1"); } catch (Exception $e) {}

// ── Ensure merchandise_batches table exists ────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS merchandise_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL, station_id INT NOT NULL,
        batch_number VARCHAR(50) NOT NULL,
        delivery_id INT DEFAULT NULL,
        quantity_received INT NOT NULL DEFAULT 0,
        remaining_qty INT NOT NULL DEFAULT 0,
        unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
        supplier VARCHAR(200) DEFAULT NULL,
        date_received DATE NOT NULL,
        encoded_by INT DEFAULT NULL,
        validated_by INT DEFAULT NULL,
        validated_at DATETIME DEFAULT NULL,
        status ENUM('active','depleted','cancelled') NOT NULL DEFAULT 'active',
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_product (product_id), INDEX idx_station (station_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add product ──────────────────────────────────────────────────────
    if ($action === 'add_product') {
        $name       = trim($_POST['product_name'] ?? '');
        $category   = trim($_POST['category']     ?? '');
        $new_cat    = trim($_POST['new_category'] ?? '');
        $sku        = trim($_POST['sku']           ?? '');
        $unit_cost  = (float)($_POST['unit_cost']  ?? 0);
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        $min_stock  = (int)($_POST['min_stock']    ?? 0);
        $max_stock  = (int)($_POST['max_stock']    ?? 0);

        // Allow typing a new category
        if ($category === '__new__' && $new_cat !== '') $category = $new_cat;
        if ($category === '') $category = 'Merchandise';

        if ($name === '') {
            $_SESSION['error'] = 'Product name is required.';
        } elseif ($unit_price < $unit_cost) {
            $_SESSION['error'] = 'Unit price cannot be less than unit cost.';
        } else {
            try {
                // Check duplicate
                $chk = $pdo->prepare("SELECT id FROM inventory_products WHERE LOWER(TRIM(product_name))=LOWER(TRIM(?)) AND LOWER(COALESCE(category,'')) NOT IN ('fuel') LIMIT 1");
                $chk->execute([$name]);
                if ($chk->fetchColumn()) {
                    $_SESSION['error'] = "Product '$name' already exists.";
                } else {
                    $pdo->prepare("INSERT INTO inventory_products (station_id, product_name, category, sku, unit_cost, unit_price, stock, status, min_stock, max_stock, created_at) VALUES (?,?,?,?,?,?,0,'active',?,?,NOW())")
                        ->execute([$station_id, $name, $category, $sku, $unit_cost, $unit_price, $min_stock, $max_stock]);
                    log_activity($pdo, $me['id'], 'Product Added', "Merchandise product '$name' (category: $category) added by {$me['name']}");
                    $_SESSION['success'] = "Product '$name' added. Stock updates automatically when deliveries are approved.";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error adding product: ' . $e->getMessage();
            }
        }
        header('Location: manager_product_merchandise.php'); exit;
    }

    // ── Update product ───────────────────────────────────────────────────
    if ($action === 'update_product') {
        $id         = (int)($_POST['product_id']   ?? 0);
        $name       = trim($_POST['product_name']  ?? '');
        $category   = trim($_POST['category']      ?? '');
        $new_cat    = trim($_POST['new_category']  ?? '');
        $sku        = trim($_POST['sku']            ?? '');
        $unit_cost  = (float)($_POST['unit_cost']  ?? 0);
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        $min_stock  = (int)($_POST['min_stock']    ?? 0);
        $max_stock  = (int)($_POST['max_stock']    ?? 0);

        if ($category === '__new__' && $new_cat !== '') $category = $new_cat;
        if ($category === '') $category = 'Merchandise';

        if (!$id || $name === '') {
            $_SESSION['error'] = 'Product ID and name are required.';
        } elseif ($unit_price < $unit_cost) {
            $_SESSION['error'] = 'Unit price cannot be less than unit cost.';
        } else {
            try {
                $pdo->prepare("UPDATE inventory_products SET product_name=?, category=?, sku=?, unit_cost=?, unit_price=?, min_stock=?, max_stock=? WHERE id=?")
                    ->execute([$name, $category, $sku, $unit_cost, $unit_price, $min_stock, $max_stock, $id]);
                log_activity($pdo, $me['id'], 'Product Updated', "Merchandise product '$name' (ID:$id) updated by {$me['name']}");
                $_SESSION['success'] = "Product '$name' updated.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error updating: ' . $e->getMessage();
            }
        }
        header('Location: manager_product_merchandise.php'); exit;
    }

    // ── Toggle status (uses proper status column) ────────────────────────
    if ($action === 'toggle_status') {
        $id        = (int)($_POST['product_id'] ?? 0);
        $newStatus = ($_POST['new_status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        if ($id) {
            try {
                $pdo->prepare("UPDATE inventory_products SET status=? WHERE id=?")->execute([$newStatus, $id]);
                log_activity($pdo, $me['id'], 'Product Status Changed', "Product ID:$id set to '$newStatus' by {$me['name']}");
                $_SESSION['success'] = 'Product status updated.';
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_product_merchandise.php'); exit;
    }
}

// ── Load products with batch summary ──────────────────────────────────────
$products = [];
$msg      = '';
try {
    $stmt = $pdo->prepare("
        SELECT
            ip.id,
            ip.product_name,
            ip.category,
            ip.sku,
            ip.unit_cost,
            ip.unit_price,
            ip.stock,
            ip.status,
            COALESCE(ip.min_stock, 0) AS min_stock,
            COALESCE(ip.max_stock, 0) AS max_stock,
            COALESCE(ba.active_batches, 0) AS active_batches,
            COALESCE(ba.total_batches,  0) AS total_batches,
            COALESCE(ba.batch_stock,    0) AS batch_stock
        FROM inventory_products ip
        LEFT JOIN (
            SELECT
                product_id,
                COUNT(*) AS total_batches,
                SUM(CASE WHEN status='active' THEN 1    ELSE 0 END) AS active_batches,
                SUM(CASE WHEN status='active' THEN remaining_qty ELSE 0 END) AS batch_stock
            FROM merchandise_batches
            WHERE station_id = ?
            GROUP BY product_id
        ) ba ON ba.product_id = ip.id
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel')
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading products: ' . $e->getMessage();
}

// ── Load batch IDs per product (for Batch ID column) ──────────────────────
$product_batches = [];
try {
    $bStmt = $pdo->prepare("
        SELECT product_id, id, batch_number, remaining_qty, unit_cost, date_received, status
        FROM merchandise_batches
        WHERE station_id = ?
        ORDER BY product_id, date_received ASC, id ASC
    ");
    $bStmt->execute([$station_id]);
    foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $product_batches[(int)$row['product_id']][] = $row;
    }
} catch (Exception $e) {}

// ── Dynamic categories from DB ─────────────────────────────────────────────
$categories = [];
try {
    $catStmt = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE LOWER(COALESCE(category,'')) NOT IN ('fuel') AND category IS NOT NULL AND category <> '' ORDER BY category");
    $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<style>
.pm-table th { background:#f1f3f4; font-weight:600; color:#333; border-bottom:2px solid #dee2e6; white-space:nowrap; }
.pm-table td { vertical-align:middle; }
.action-col { display:flex; flex-direction:column; gap:4px; }
.action-col .btn { width:100%; font-size:12px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:flex; align-items:center; gap:5px; justify-content:center; transition:all .15s; }
.action-col .btn:hover { filter:brightness(.9); transform:translateY(-1px); }
.btn-view    { background:#28a745; color:#fff; }
.btn-edit    { background:#002F70; color:#fff; }
.btn-danger  { background:#dc3545; color:#fff; }
.btn-success { background:#28a745; color:#fff; }
.badge-status { padding:4px 10px; border-radius:4px; font-size:12px; font-weight:600; color:#fff; }
.badge-cat    { padding:3px 9px; border-radius:4px; font-size:11px; font-weight:600; color:#fff; background:#6c757d; }
/* Batch ID column */
.batch-id-list { display:flex; flex-direction:column; gap:3px; cursor:pointer; }
.batch-id-tag  { display:inline-flex; align-items:center; gap:5px; border:1px solid; border-radius:6px; padding:3px 8px; font-size:11px; font-weight:700; font-family:monospace; white-space:nowrap; transition:filter .15s; }
.batch-id-list:hover .batch-id-tag { filter:brightness(.93); }
.batch-id-qty  { font-family:sans-serif; font-weight:400; font-size:10px; color:#6c757d; border-left:1px solid #d1d5db; padding-left:5px; margin-left:2px; }
.batch-expand-hint { font-size:10px; color:#9ca3af; margin-top:1px; display:flex; align-items:center; gap:3px; }
.batch-expand-hint i { transition:transform .2s; }
.batch-id-list.expanded .batch-expand-hint i { transform:rotate(180deg); }
.batch-pill    { display:inline-flex; align-items:center; gap:4px; background:#f3f4f6; color:#9ca3af; border:1px solid #e5e7eb; border-radius:12px; padding:2px 9px; font-size:11px; font-weight:600; white-space:nowrap; }
/* Batch expand panel */
.batch-expand-row { display:none; }
.batch-expand-row.open { display:table-row; }
.batch-panel { background:#f8f7ff; border:1px solid #c4b5fd; border-radius:10px; padding:14px 16px; margin:4px 0; }
.batch-panel-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px; }
.batch-panel-title { font-size:13px; font-weight:700; color:#5b21b6; display:flex; align-items:center; gap:6px; }
.batch-summary-chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.bchip { background:#fff; border:1px solid #c4b5fd; border-radius:8px; padding:5px 12px; font-size:12px; color:#5b21b6; font-weight:600; }
.bchip span { color:#374151; font-weight:400; }
.batch-table { width:100%; border-collapse:collapse; font-size:12px; }
.batch-table th { background:#ede9fe; color:#5b21b6; padding:7px 10px; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:.3px; border-bottom:2px solid #c4b5fd; white-space:nowrap; }
.batch-table td { padding:7px 10px; border-bottom:1px solid #e9e5ff; vertical-align:middle; }
.batch-table tr:last-child td { border-bottom:none; }
.batch-table tr:hover td { background:#f0ebff; }
.batch-status-active   { color:#059669; font-weight:700; }
.batch-status-depleted { color:#9ca3af; }
.batch-status-cancelled{ color:#dc3545; }
.batch-fifo-tag { font-size:10px; background:#fef3c7; color:#92400e; border:1px solid #fcd34d; border-radius:8px; padding:1px 6px; font-weight:700; }
/* Modals */
.modal { display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.5); align-items:center; justify-content:center; }
.modal.open { display:flex; }
.modal-content { background:#fff; border-radius:12px; width:90%; max-width:560px; max-height:90vh; overflow-y:auto; box-shadow:0 8px 32px rgba(0,0,0,.25); animation:mIn .18s ease; }
@keyframes mIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
.modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 22px; border-bottom:1px solid #e9ecef; }
.modal-header h3 { margin:0; font-size:17px; font-weight:700; }
.close { background:none; border:none; font-size:26px; cursor:pointer; color:#aaa; line-height:1; }
.close:hover { color:#333; }
.modal-body { padding:22px; }
.modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:18px 22px; border-top:1px solid #e9ecef; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; margin-bottom:5px; font-weight:600; font-size:12px; color:#374151; text-transform:uppercase; letter-spacing:.3px; }
.form-control { width:100%; padding:9px 11px; border:1px solid #ddd; border-radius:6px; font-size:13px; box-sizing:border-box; font-family:inherit; }
.form-control:focus { border-color:#002F70; outline:none; box-shadow:0 0 0 3px rgba(0,47,112,.1); }
.fg2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.info-note { background:#e8f4fd; border-left:4px solid #002F70; border-radius:6px; padding:9px 13px; font-size:12px; color:#002F70; }
/* Toast */
.toast { position:fixed; bottom:24px; right:24px; padding:12px 18px; border-radius:8px; color:#fff; font-weight:600; font-size:13px; z-index:99999; box-shadow:0 4px 16px rgba(0,0,0,.2); display:none; animation:tUp .22s ease; max-width:340px; }
.toast.show { display:block; }
.toast-success { background:#28a745; }
.toast-error   { background:#dc3545; }
@keyframes tUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
.batch-loading { text-align:center; padding:20px; color:#6c757d; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-shopping-bag"></i> Merchandise Products</h1>
        <div class="sub">Product Management &mdash; Merchandise Catalog</div>
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
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="catFilter" class="form-control" style="width:180px;" onchange="filterTable()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo strtolower(htmlspecialchars($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="merchSearch" placeholder="Search name or SKU..." class="form-control" style="width:210px;" oninput="filterTable()">
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table pm-table" id="mainTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>SKU</th>
                        <th>Unit Cost</th>
                        <th>Unit Price</th>
                        <th>Stock</th>
                        <th>Batch ID</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php foreach ($products as $p):
                    $stock       = (int)($p['stock'] ?? 0);
                    $status      = $p['status'] ?? 'active';
                    $isActive    = ($status === 'active');
                    $stockColor  = $stock <= 0 ? '#dc3545' : ($stock <= (int)($p['min_stock'] ?? 10) ? '#ff9500' : '#28a745');
                    $statusColor = $isActive ? '#28a745' : '#dc3545';
                    $pid_batches = $product_batches[(int)$p['id']] ?? [];
                ?>
                <tr class="product-row"
                    data-id="<?php echo (int)$p['id']; ?>"
                    data-name="<?php echo strtolower(htmlspecialchars($p['product_name'])); ?>"
                    data-sku="<?php echo strtolower(htmlspecialchars($p['sku'] ?? '')); ?>"
                    data-cat="<?php echo strtolower(htmlspecialchars($p['category'] ?? '')); ?>">

                    <!-- 1. ID -->
                    <td style="color:#6c757d;font-size:12px;">#<?php echo (int)$p['id']; ?></td>

                    <!-- 2. Name -->
                    <td><strong><?php echo htmlspecialchars($p['product_name']); ?></strong></td>

                    <!-- 3. Category -->
                    <td><span class="badge-cat"><?php echo htmlspecialchars($p['category'] ?? 'Merchandise'); ?></span></td>

                    <!-- 4. SKU -->
                    <td style="color:#6c757d;font-size:12px;font-family:monospace;"><?php echo htmlspecialchars($p['sku'] ?? '—'); ?></td>

                    <!-- 5. Unit Cost -->
                    <td style="color:#6c757d;">₱<?php echo number_format((float)$p['unit_cost'], 2); ?></td>

                    <!-- 6. Unit Price -->
                    <td style="color:#28a745;font-weight:700;">₱<?php echo number_format((float)$p['unit_price'], 2); ?></td>

                    <!-- 7. Stock -->
                    <td>
                        <span class="badge-status" style="background:<?php echo $stockColor; ?>;"><?php echo number_format($stock); ?></span>
                    </td>

                    <!-- 8. Batch ID -->
                    <td>
                        <?php if (!empty($pid_batches)): ?>
                        <div class="batch-id-list" onclick="toggleBatchRow(<?php echo (int)$p['id']; ?>)" title="Click to expand batch details">
                            <?php foreach ($pid_batches as $pb):
                                $bActive  = $pb['status'] === 'active';
                                $bColor   = $bActive ? '#5b21b6' : '#9ca3af';
                                $bBg      = $bActive ? '#ede9fe' : '#f3f4f6';
                                $bBorder  = $bActive ? '#c4b5fd' : '#e5e7eb';
                            ?>
                            <span class="batch-id-tag" style="background:<?php echo $bBg; ?>;color:<?php echo $bColor; ?>;border-color:<?php echo $bBorder; ?>;">
                                <?php echo htmlspecialchars($pb['batch_number']); ?>
                                <span class="batch-id-qty"><?php echo number_format((int)$pb['remaining_qty']); ?> pcs</span>
                            </span>
                            <?php endforeach; ?>
                            <span class="batch-expand-hint"><i class="fas fa-chevron-down"></i> details</span>
                        </div>
                        <?php else: ?>
                        <span class="batch-pill"><i class="fas fa-truck" style="opacity:.4;"></i> Via delivery</span>
                        <?php endif; ?>
                    </td>

                    <!-- 9. Status -->
                    <td>
                        <span class="badge-status" style="background:<?php echo $statusColor; ?>;">
                            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>

                    <!-- 10. Actions -->
                    <td>
                        <div class="action-col">
                            <button class="btn btn-view" onclick="viewProduct(<?php echo (int)$p['id']; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="btn btn-edit" onclick="editProduct(<?php echo (int)$p['id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <?php if ($isActive): ?>
                            <button class="btn btn-danger" onclick="toggleStatus(<?php echo (int)$p['id']; ?>, 'inactive', '<?php echo htmlspecialchars(addslashes($p['product_name'])); ?>')">
                                <i class="fas fa-times"></i> Deactivate
                            </button>
                            <?php else: ?>
                            <button class="btn btn-success" onclick="toggleStatus(<?php echo (int)$p['id']; ?>, 'active', '<?php echo htmlspecialchars(addslashes($p['product_name'])); ?>')">
                                <i class="fas fa-check"></i> Activate
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <!-- Batch expand row -->
                <tr class="batch-expand-row" id="batch-row-<?php echo (int)$p['id']; ?>">
                    <td colspan="10" style="padding:0 12px 12px 12px;background:#faf9ff;">
                        <div class="batch-panel" id="batch-panel-<?php echo (int)$p['id']; ?>">
                            <div class="batch-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                <tr><td colspan="10" style="text-align:center;padding:40px;color:#666;">No merchandise products found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:8px;font-size:12px;color:#9ca3af;">
            <?php echo count($products); ?> product(s) &mdash; <?php echo count($categories); ?> categories
        </div>
    </div>
</div>

<!-- ══ ADD PRODUCT MODAL ══════════════════════════════════════════════════════ -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus" style="color:#28a745;"></i> Add Merchandise Product</h3>
            <button class="close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateProductForm(this)">
            <input type="hidden" name="action" value="add_product">
            <div class="modal-body">
                <div class="form-group">
                    <label>Product Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="product_name" class="form-control" required placeholder="e.g. California Scents">
                </div>
                <div class="form-group">
                    <label>Category <span style="color:#dc3545;">*</span></label>
                    <select name="category" id="addCatSelect" class="form-control" onchange="toggleNewCat('add')" required>
                        <option value="">— Select category —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">+ Add new category...</option>
                    </select>
                </div>
                <div class="form-group" id="addNewCatWrap" style="display:none;">
                    <label>New Category Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="new_category" id="addNewCat" class="form-control" placeholder="e.g. Air Fresheners">
                </div>
                <div class="form-group">
                    <label>SKU</label>
                    <input type="text" name="sku" class="form-control" placeholder="e.g. FRESH-CAL-SCENTS">
                </div>
                <div class="fg2">
                    <div class="form-group">
                        <label>Unit Cost (₱) <span style="color:#dc3545;">*</span></label>
                        <input type="number" name="unit_cost" class="form-control" step="0.01" min="0" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Unit Price (₱) <span style="color:#dc3545;">*</span></label>
                        <input type="number" name="unit_price" class="form-control" step="0.01" min="0" required placeholder="0.00">
                    </div>
                </div>
                <div class="fg2">
                    <div class="form-group">
                        <label>Min Stock</label>
                        <input type="number" name="min_stock" class="form-control" min="0" value="0" placeholder="Reorder point">
                    </div>
                    <div class="form-group">
                        <label>Max Stock</label>
                        <input type="number" name="max_stock" class="form-control" min="0" value="0" placeholder="Max capacity">
                    </div>
                </div>
                <div class="info-note">
                    <i class="fas fa-info-circle"></i> Stock is managed automatically via <strong>delivery batches</strong>. Batches are created when staff encodes a delivery and the manager approves it.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn primary"><i class="fas fa-plus"></i> Add Product</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ EDIT PRODUCT MODAL ════════════════════════════════════════════════════ -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit" style="color:#002F70;"></i> Edit Merchandise Product</h3>
            <button class="close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateProductForm(this)">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="product_id" id="editProductId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Product Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="product_name" id="editProductName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Category <span style="color:#dc3545;">*</span></label>
                    <select name="category" id="editCatSelect" class="form-control" onchange="toggleNewCat('edit')" required>
                        <option value="">— Select category —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">+ Add new category...</option>
                    </select>
                </div>
                <div class="form-group" id="editNewCatWrap" style="display:none;">
                    <label>New Category Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="new_category" id="editNewCat" class="form-control" placeholder="e.g. Air Fresheners">
                </div>
                <div class="form-group">
                    <label>SKU</label>
                    <input type="text" name="sku" id="editProductSku" class="form-control">
                </div>
                <div class="fg2">
                    <div class="form-group">
                        <label>Unit Cost (₱) <span style="color:#dc3545;">*</span></label>
                        <input type="number" name="unit_cost" id="editProductCost" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Unit Price (₱) <span style="color:#dc3545;">*</span></label>
                        <input type="number" name="unit_price" id="editProductPrice" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="fg2">
                    <div class="form-group">
                        <label>Min Stock</label>
                        <input type="number" name="min_stock" id="editMinStock" class="form-control" min="0" value="0">
                    </div>
                    <div class="form-group">
                        <label>Max Stock</label>
                        <input type="number" name="max_stock" id="editMaxStock" class="form-control" min="0" value="0">
                    </div>
                </div>
                <div class="info-note">
                    <i class="fas fa-info-circle"></i> Stock is managed automatically via <strong>delivery batches</strong>. Batches are created when staff encodes a delivery and the manager approves it.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ VIEW PRODUCT MODAL ════════════════════════════════════════════════════ -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-eye" style="color:#28a745;"></i> Product Details</h3>
            <button class="close" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:grid;gap:10px;">
                <div class="fg2">
                    <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">Product ID</label><div id="vId" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;font-family:monospace;"></div></div>
                    <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">SKU</label><div id="vSku" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;font-family:monospace;"></div></div>
                </div>
                <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">Product Name</label><div id="vName" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;font-weight:600;"></div></div>
                <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">Category</label><div id="vCat" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;"></div></div>
                <div class="fg2">
                    <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">Unit Cost</label><div id="vCost" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;color:#6c757d;font-weight:700;"></div></div>
                    <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">Unit Price</label><div id="vPrice" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;color:#28a745;font-weight:700;"></div></div>
                </div>
                <div class="fg2">
                    <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">Min Stock</label><div id="vMin" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;"></div></div>
                    <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">Max Stock</label><div id="vMax" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;"></div></div>
                </div>
                <div class="fg2">
                    <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">Total Stock</label><div id="vStock" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;font-weight:700;"></div></div>
                    <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">Status</label><div id="vStatus" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;font-weight:700;"></div></div>
                </div>
                <div><label style="font-weight:700;font-size:11px;color:#6c757d;text-transform:uppercase;">Active Batches</label><div id="vBatches" style="padding:8px 11px;background:#f8f9fa;border-radius:6px;"></div></div>
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
    <input type="hidden" name="new_status" id="tNewStatus">
</form>

<div class="toast" id="toast"></div>

<script>
// --- Modals ---
function openModal(id) {
    document.getElementById(id).classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// --- Toggle Category ---
function toggleNewCat(prefix) {
    const sel = document.getElementById(prefix + 'CatSelect');
    const wrap = document.getElementById(prefix + 'NewCatWrap');
    const input = document.getElementById(prefix + 'NewCat');
    if (sel.value === '__new__') {
        wrap.style.display = 'block';
        input.required = true;
    } else {
        wrap.style.display = 'none';
        input.required = false;
        input.value = '';
    }
}

// --- Validation ---
function validateProductForm(form) {
    const cost = parseFloat(form.unit_cost.value);
    const price = parseFloat(form.unit_price.value);
    if (price < cost) {
        showToast('Selling price cannot be less than cost price', 'error');
        return false;
    }
    return true;
}

// --- Status Toggle ---
function toggleStatus(id, newStatus, name) {
    const actionText = newStatus === 'active' ? 'activate' : 'deactivate';
    if (!confirm(`Are you sure you want to ${actionText} ${name}?`)) return;
    document.getElementById('tProductId').value = id;
    document.getElementById('tNewStatus').value = newStatus;
    document.getElementById('toggleForm').submit();
}

// --- Populate Edit Modal ---
const productData = <?php echo json_encode($products); ?>;

function editProduct(id) {
    const p = productData.find(item => item.id == id);
    if (!p) return;
    
    document.getElementById('editProductId').value = p.id;
    document.getElementById('editProductName').value = p.product_name;
    
    const catSel = document.getElementById('editCatSelect');
    // Check if category exists in dropdown, else set to new
    let foundCat = false;
    for(let i=0; i<catSel.options.length; i++) {
        if(catSel.options[i].value === p.category) {
            foundCat = true;
            break;
        }
    }
    if (foundCat) {
        catSel.value = p.category;
        document.getElementById('editNewCatWrap').style.display = 'none';
        document.getElementById('editNewCat').required = false;
    } else {
        catSel.value = '__new__';
        document.getElementById('editNewCatWrap').style.display = 'block';
        document.getElementById('editNewCat').value = p.category;
        document.getElementById('editNewCat').required = true;
    }
    
    document.getElementById('editProductSku').value = p.sku || '';
    document.getElementById('editProductCost').value = parseFloat(p.unit_cost).toFixed(2);
    document.getElementById('editProductPrice').value = parseFloat(p.unit_price).toFixed(2);
    document.getElementById('editMinStock').value = p.min_stock || 0;
    document.getElementById('editMaxStock').value = p.max_stock || 0;
    
    openModal('editModal');
}

// --- Populate View Modal ---
function viewProduct(id) {
    const p = productData.find(item => item.id == id);
    if (!p) return;
    
    document.getElementById('vId').textContent = '#' + p.id;
    document.getElementById('vSku').textContent = p.sku || 'N/A';
    document.getElementById('vName').textContent = p.product_name;
    document.getElementById('vCat').textContent = p.category || 'N/A';
    document.getElementById('vCost').textContent = '₱' + parseFloat(p.unit_cost).toFixed(2);
    document.getElementById('vPrice').textContent = '₱' + parseFloat(p.unit_price).toFixed(2);
    document.getElementById('vMin').textContent = p.min_stock || 0;
    document.getElementById('vMax').textContent = p.max_stock || 0;
    document.getElementById('vStock').textContent = p.stock || 0;
    document.getElementById('vStatus').textContent = (p.status === 'active' ? 'Active' : 'Inactive');
    document.getElementById('vBatches').textContent = (p.active_batches || 0) + ' active / ' + (p.total_batches || 0) + ' total';
    
    openModal('viewModal');
}

// --- Table Filtering ---
function filterTable() {
    const catFilter = document.getElementById('catFilter').value.toLowerCase();
    const search = document.getElementById('merchSearch').value.toLowerCase();
    
    const rows = document.querySelectorAll('.product-row');
    rows.forEach(row => {
        const rowCat = row.dataset.cat || '';
        const rowName = row.dataset.name || '';
        const rowSku = row.dataset.sku || '';
        
        const catMatch = !catFilter || rowCat === catFilter;
        const searchMatch = !search || rowName.includes(search) || rowSku.includes(search);
        
        if (catMatch && searchMatch) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// --- Toast ---
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + (type==='success' ? 'toast-success' : 'toast-error');
    setTimeout(() => { t.className = 'toast'; }, 3000);
}

// --- Close Modals on click outside or escape ---
window.addEventListener('click', e => {
    if (e.target.classList.contains('modal')) e.target.classList.remove('open');
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open'));
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
