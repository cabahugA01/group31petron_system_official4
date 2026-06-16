<?php
/**
 * Service Type Management
 * Manager view: list, add, edit, activate/deactivate service types for Job Orders.
 * Changes to pricing require admin approval.
 */
$page_id = 'mgr_prod_services';
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

// ── Ensure job_order_service_types table exists ────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_order_service_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL DEFAULT 1,
        service_key VARCHAR(100) NOT NULL,
        service_name VARCHAR(200) NOT NULL,
        service_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        min_price DECIMAL(12,2) DEFAULT 0,
        max_price DECIMAL(12,2) DEFAULT 0,
        price_description TEXT DEFAULT NULL,
        pricing_notes TEXT DEFAULT NULL,
        icon_class VARCHAR(100) DEFAULT 'fa-wrench',
        color_class VARCHAR(100) DEFAULT 'text-primary',
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_service_key (service_key),
        INDEX idx_station (station_id),
        INDEX idx_active (active),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
} catch (Exception $e) {}

// ── Ensure service_type_parts table exists (required parts mapping) ─────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_type_parts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_key VARCHAR(100) NOT NULL,
        product_id INT NOT NULL,
        default_quantity INT NOT NULL DEFAULT 1,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_service (service_key),
        INDEX idx_product (product_id),
        FOREIGN KEY (product_id) REFERENCES inventory_products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── Ensure pending_price_approvals supports service_types ───────────────────
try {
    // Create table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_price_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL,
        product_type VARCHAR(50) NOT NULL DEFAULT 'merchandise',
        product_id INT NOT NULL,
        old_cost DECIMAL(12,2) DEFAULT 0,
        new_cost DECIMAL(12,2) DEFAULT 0,
        old_price DECIMAL(12,2) DEFAULT 0,
        new_price DECIMAL(12,2) DEFAULT 0,
        manager_id INT NOT NULL,
        admin_id INT DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        rejection_reason TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_station (station_id),
        INDEX idx_status (status),
        INDEX idx_product (product_type, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Alter table to support service_type if column is too narrow
    $pdo->exec("ALTER TABLE pending_price_approvals MODIFY COLUMN product_type VARCHAR(50) NOT NULL DEFAULT 'merchandise'");
} catch (Exception $e) {}

// ──POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add service type ─────────────────────────────────────────────────────
    if ($action === 'add_service') {
        $name        = trim($_POST['service_name'] ?? '');
        $price       = (float)($_POST['service_price'] ?? 0);
        $min_price   = (float)($_POST['min_price'] ?? 0);
        $max_price   = (float)($_POST['max_price'] ?? 0);
        $price_desc  = trim($_POST['price_description'] ?? '');
        $notes       = trim($_POST['pricing_notes'] ?? '');
        $icon        = trim($_POST['icon_class'] ?? 'fa-wrench');
        $color       = trim($_POST['color_class'] ?? 'text-primary');

        if ($name === '') {
            $_SESSION['error'] = 'Service name is required.';
        } elseif ($price < 0) {
            $_SESSION['error'] = 'Service price cannot be negative.';
        } else {
            try {
                // Generate service_key from name
                $service_key = strtolower(preg_replace('/[^a-z0-9]+/', '_', $name));
                $service_key = trim($service_key, '_');

                // Check duplicate
                $chk = $pdo->prepare("SELECT id FROM job_order_service_types WHERE LOWER(TRIM(service_name))=LOWER(TRIM(?)) OR service_key=? LIMIT 1");
                $chk->execute([$name, $service_key]);
                if ($chk->fetchColumn()) {
                    $_SESSION['error'] = "Service '$name' already exists.";
                } else {
                    // Get max sort_order
                    $max_sort = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM job_order_service_types")->fetchColumn();
                    $new_sort = $max_sort + 1;

                    $pdo->prepare("INSERT INTO job_order_service_types (station_id, service_key, service_name, service_price, min_price, max_price, price_description, pricing_notes, icon_class, color_class, sort_order, active, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,'active',?,NOW())")
                        ->execute([$station_id, $service_key, $name, $price, $min_price, $max_price, $price_desc, $notes, $icon, $color, $new_sort, $me['id']]);
                    
                    log_activity($pdo, $me['id'], 'Service Type Added', "Service type '$name' added by {$me['name']} (Price: ₱".number_format($price, 2).")");
                    $_SESSION['success'] = "Service type '$name' added successfully.";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error adding service type: ' . $e->getMessage();
            }
        }
        header('Location: manager_service_types.php'); exit;
    }

    // ── Update service type ──────────────────────────────────────────────────
    if ($action === 'update_service') {
        $id          = (int)($_POST['service_id'] ?? 0);
        $name        = trim($_POST['service_name'] ?? '');
        $price       = (float)($_POST['service_price'] ?? 0);
        $min_price   = (float)($_POST['min_price'] ?? 0);
        $max_price   = (float)($_POST['max_price'] ?? 0);
        $price_desc  = trim($_POST['price_description'] ?? '');
        $notes       = trim($_POST['pricing_notes'] ?? '');
        $icon        = trim($_POST['icon_class'] ?? 'fa-wrench');
        $color       = trim($_POST['color_class'] ?? 'text-primary');

        if (!$id || $name === '') {
            $_SESSION['error'] = 'Service ID and name are required.';
        } elseif ($price < 0) {
            $_SESSION['error'] = 'Service price cannot be negative.';
        } else {
            try {
                // Check if price changed
                $stmt = $pdo->prepare("SELECT service_price FROM job_order_service_types WHERE id=?");
                $stmt->execute([$id]);
                $old_price = (float)($stmt->fetchColumn() ?: 0);

                if ($old_price != $price) {
                    // Update non-pricing fields
                    $pdo->prepare("UPDATE job_order_service_types SET service_name=?, min_price=?, max_price=?, price_description=?, pricing_notes=?, icon_class=?, color_class=? WHERE id=?")
                        ->execute([$name, $min_price, $max_price, $price_desc, $notes, $icon, $color, $id]);
                    
                    // Insert into pending_price_approvals
                    $pdo->prepare("INSERT INTO pending_price_approvals (station_id, product_type, product_id, old_price, new_price, manager_id, status, created_at) VALUES (?, 'service_type', ?, ?, ?, ?, 'pending', NOW())")
                        ->execute([$station_id, $id, $old_price, $price, $me['id']]);
                    
                    $_SESSION['success'] = "Service details updated. Price change submitted for Admin approval.";
                    $log_msg = "Service '$name' updated. Price change submitted: ₱".number_format($old_price, 2)." → ₱".number_format($price, 2)." (Pending Approval)";
                } else {
                    $pdo->prepare("UPDATE job_order_service_types SET service_name=?, service_price=?, min_price=?, max_price=?, price_description=?, pricing_notes=?, icon_class=?, color_class=? WHERE id=?")
                        ->execute([$name, $price, $min_price, $max_price, $price_desc, $notes, $icon, $color, $id]);
                    $_SESSION['success'] = "Service type updated.";
                    $log_msg = "Service type '$name' updated.";
                }
                log_activity($pdo, $me['id'], 'Service Type Updated', $log_msg);

            } catch (Exception $e) {
                $_SESSION['error'] = 'Error updating: ' . $e->getMessage();
            }
        }
        header('Location: manager_service_types.php'); exit;
    }

    // ── Toggle status ────────────────────────────────────────────────────────
    if ($action === 'toggle_status') {
        $id        = (int)($_POST['service_id'] ?? 0);
        $newStatus = ($_POST['new_status'] ?? '') === 'inactive' ? 0 : 1; // Use 0/1 for active column
        if ($id) {
            try {
                $stmt = $pdo->prepare("SELECT service_name FROM job_order_service_types WHERE id=?");
                $stmt->execute([$id]);
                $sname = $stmt->fetchColumn();

                // Update active column (1 = active, 0 = inactive)
                $stmt = $pdo->prepare("UPDATE job_order_service_types SET active=? WHERE id=?");
                $stmt->execute([$newStatus, $id]);
                
                // Verify update worked
                $verify = $pdo->prepare("SELECT active FROM job_order_service_types WHERE id=?");
                $verify->execute([$id]);
                $result = $verify->fetch(PDO::FETCH_ASSOC);
                
                $statusText = $newStatus === 1 ? 'ACTIVE' : 'INACTIVE';
                log_activity($pdo, $me['id'], 'Service Type Status Changed', "Service '$sname' (ID:$id) set to '$statusText' by {$me['name']}");
                $_SESSION['success'] = "Service type '$sname' is now $statusText (Active: {$result['active']})";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        // Force page reload with cache bypass
        header('Location: manager_service_types.php?v=' . time()); 
        exit;
    }
}

// ── Load service types ──────────────────────────────────────────────────────
$services = [];
$msg      = '';
try {
    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.service_key,
            s.service_name,
            s.service_price,
            s.min_price,
            s.max_price,
            s.price_description,
            s.pricing_notes,
            s.icon_class,
            s.color_class,
            s.sort_order,
            s.active,
            s.created_at
        FROM job_order_service_types s
        ORDER BY s.sort_order, s.service_name
    ");
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading service types: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* === Service Type Management - Clean Table Design === */
.card { background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.08); border:1px solid #e9ecef; margin-bottom:20px; overflow:hidden; }
.card-header { padding:16px 20px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.card-header h3 { font-size:16px; font-weight:700; color:#002F70; margin:0; display:flex; align-items:center; gap:8px; }
.card-body { padding:20px; overflow-x:hidden; }
.st-table-wrap { overflow:hidden; width:100%; }
.st-table { min-width:100%; width:100%; border-collapse:collapse; table-layout:auto; }
.st-table thead th { background:#002F70 !important; color:#fff !important; font-weight:600; padding:14px 12px !important; text-align:left !important; text-transform:uppercase; letter-spacing:0.3px; border:none !important; font-size:11px; }
.st-table thead th:last-child { text-align:center !important; }
.st-table tbody td { vertical-align:middle; padding:12px !important; border-bottom:1px solid #e9ecef !important; font-size:13px; }
.st-table tbody td:last-child { text-align:center !important; }
.st-table tbody tr:hover td { background:#e3f2fd !important; }
.st-table tbody tr { transition:background 0.2s ease; }
.action-col { display:flex; flex-direction:column; gap:3px; min-width:90px; width:90px; align-items:center; justify-content:center; }
.action-col .btn { font-size:11px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:flex; align-items:center; gap:4px; justify-content:center; transition:all .15s; white-space:nowrap; width:100%; }
.action-col .btn:hover { filter:brightness(.9); }
.btn-view    { background:#28a745; color:#fff; }
.btn-edit    { background:#002F70; color:#fff; }
.btn-danger  { background:#dc3545; color:#fff; }
.btn-success { background:#28a745; color:#fff; }
.service-icon { width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:#e0f2fe; color:#0369a1; font-size:13px; }
.parts-badge { background:#fffbeb; color:#92400e; border:1px solid #fde68a; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
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
.info-note { background:#e8f4fd; border-left:4px solid:#002F70; border-radius:6px; padding:9px 13px; font-size:12px; color:#002F70; }
/* Toast */
.toast { position:fixed; bottom:24px; right:24px; padding:12px 18px; border-radius:8px; color:#fff; font-weight:600; font-size:13px; z-index:99999; box-shadow:0 4px 16px rgba(0,0,0,.2); display:none; animation:tUp .22s ease; max-width:340px; }
.toast.show { display:block; }
.toast-success { background:#28a745; }
.toast-error   { background:#dc3545; }
@keyframes tUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-wrench"></i> Service Types</h1>
        <div class="sub">Product Management &mdash; Service Type Catalog</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
        <button onclick="openModal('addModal')" class="btn primary"><i class="fas fa-plus"></i> Add Service Type</button>
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
        <h3><i class="fas fa-list" style="color:#002F70;"></i> Service Type List</h3>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="serviceSearch" placeholder="Search service name..." class="form-control" style="width:210px;" oninput="filterTable()">
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap st-table-wrap">
            <table class="table st-table" id="mainTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Service Name</th>
                        <th>Base Fee</th>
                        <th>Price Range</th>
                        <th>Status</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="serviceTableBody">
                <?php foreach ($services as $s):
                    // Use active column (1 or 0) instead of status
                    $isActive    = (int)($s['active'] ?? 1) === 1;
                    $statusColor = $isActive ? '#28a745' : '#dc3545';
                    $priceRange  = '';
                    if ((float)$s['min_price'] > 0 || (float)$s['max_price'] > 0) {
                        $priceRange = '₱' . number_format((float)$s['min_price'], 2) . ' - ₱' . number_format((float)$s['max_price'], 2);
                    }
                ?>
                <tr class="service-row" data-name="<?php echo strtolower(htmlspecialchars($s['service_name'])); ?>">
                    <!-- ID -->
                    <td>
                        <strong style="color:#64748b;font-family:monospace;">#<?php echo (int)$s['id']; ?></strong>
                    </td>

                    <!-- Service Name -->
                    <td><strong><?php echo htmlspecialchars($s['service_name']); ?></strong></td>

                    <!-- Base Fee -->
                    <td style="color:#28a745;font-weight:700;">₱<?php echo number_format((float)$s['service_price'], 2); ?></td>

                    <!-- Price Range -->
                    <td style="color:#6c757d;font-size:12px;">
                        <?php echo $priceRange ?: '—'; ?>
                    </td>

                    <!-- Status -->
                    <td>
                        <span style="color:<?php echo $statusColor; ?>;font-weight:700;">
                            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>

                    <!-- Actions -->
                    <td style="text-align:center;">
                        <div class="action-col">
                            <button class="btn btn-view" onclick="viewService(<?php echo (int)$s['id']; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="btn btn-edit" onclick="editService(<?php echo (int)$s['id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <?php if ($isActive): ?>
                            <button class="btn btn-danger" onclick="toggleStatus(<?php echo (int)$s['id']; ?>, 'inactive', '<?php echo htmlspecialchars(addslashes($s['service_name'])); ?>')">
                                <i class="fas fa-times"></i> Deactivate
                            </button>
                            <?php else: ?>
                            <button class="btn btn-success" onclick="toggleStatus(<?php echo (int)$s['id']; ?>, 'active', '<?php echo htmlspecialchars(addslashes($s['service_name'])); ?>')">
                                <i class="fas fa-check"></i> Activate
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($services)): ?>
                <tr><td colspan="6" style="text-align:center;padding:40px;color:#666;">No service types found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:8px;font-size:12px;color:#9ca3af;">
            <?php echo count($services); ?> service type(s)
        </div>
    </div>
</div>

<!-- ══ ADD SERVICE TYPE MODAL ═════════════════════════════════════════════════ -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus" style="color:#28a745;"></i> Add Service Type</h3>
            <button class="close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateServiceForm(this)">
            <input type="hidden" name="action" value="add_service">
            <div class="modal-body">
                <div class="form-group">
                    <label>Service Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="service_name" class="form-control" placeholder="e.g., Oil Change, Tire Repair" required>
                </div>
                <div class="fg2">
                    <div class="form-group">
                        <label>Base Fee (₱) <span style="color:#dc2626;">*</span></label>
                        <input type="number" name="service_price" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Icon Class</label>
                        <input type="text" name="icon_class" class="form-control" placeholder="fa-wrench" value="fa-wrench">
                    </div>
                </div>
                <div class="fg2">
                    <div class="form-group">
                        <label>Min Price (₱)</label>
                        <input type="number" name="min_price" class="form-control" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Max Price (₱)</label>
                        <input type="number" name="max_price" class="form-control" step="0.01" min="0" placeholder="0.00">
                    </div>
                </div>
                <div class="form-group">
                    <label>Price Description</label>
                    <input type="text" name="price_description" class="form-control" placeholder="e.g., Flat rate, Varies by vehicle type">
                </div>
                <div class="form-group">
                    <label>Pricing Notes</label>
                    <textarea name="pricing_notes" class="form-control" rows="3" placeholder="Additional pricing information for staff..."></textarea>
                </div>
                <div class="info-note">
                    <i class="fas fa-info-circle"></i> You can map required parts to this service after creation.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Service Type</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ EDIT SERVICE TYPE MODAL ════════════════════════════════════════════════ -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit" style="color:#002F70;"></i> Edit Service Type</h3>
            <button class="close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" onsubmit="return validateServiceForm(this)">
            <input type="hidden" name="action" value="update_service">
            <input type="hidden" name="service_id" id="edit_service_id">
            <div class="modal-body">
                <div class="form-group">
                    <label>Service Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="service_name" id="edit_service_name" class="form-control" required>
                </div>
                <div class="fg2">
                    <div class="form-group">
                        <label>Base Fee (₱) <span style="color:#dc2626;">*</span></label>
                        <input type="number" name="service_price" id="edit_service_price" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Icon Class</label>
                        <input type="text" name="icon_class" id="edit_icon_class" class="form-control" placeholder="fa-wrench">
                    </div>
                </div>
                <div class="fg2">
                    <div class="form-group">
                        <label>Min Price (₱)</label>
                        <input type="number" name="min_price" id="edit_min_price" class="form-control" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label>Max Price (₱)</label>
                        <input type="number" name="max_price" id="edit_max_price" class="form-control" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label>Price Description</label>
                    <input type="text" name="price_description" id="edit_price_description" class="form-control">
                </div>
                <div class="form-group">
                    <label>Pricing Notes</label>
                    <textarea name="pricing_notes" id="edit_pricing_notes" class="form-control" rows="3"></textarea>
                </div>
                <div class="info-note">
                    <i class="fas fa-info-circle"></i> Price changes require Admin approval.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Service Type</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ VIEW SERVICE TYPE MODAL ════════════════════════════════════════════════ -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-eye" style="color:#28a745;"></i> Service Type Details</h3>
            <button class="close" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <div style="text-align:center;padding:40px;color:#999;">
                <i class="fas fa-spinner fa-spin" style="font-size:24px;"></i>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
// Service types data for JavaScript
const servicesData = <?php echo json_encode($services); ?>;

// ── Filter table ────────────────────────────────────────────────────────────
function filterTable() {
    const search = document.getElementById('serviceSearch').value.toLowerCase();
    const rows = document.querySelectorAll('.service-row');
    
    rows.forEach(row => {
        const name = row.dataset.name || '';
        const match = name.includes(search);
        row.style.display = match ? '' : 'none';
    });
}

// ── Modal helpers ───────────────────────────────────────────────────────────
function openModal(id) {
    document.getElementById(id).classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// ── View service ────────────────────────────────────────────────────────────
function viewService(id) {
    const service = servicesData.find(s => s.id == id);
    if (!service) return;
    
    const priceRange = (parseFloat(service.min_price) > 0 || parseFloat(service.max_price) > 0)
        ? `₱${parseFloat(service.min_price).toFixed(2)} - ₱${parseFloat(service.max_price).toFixed(2)}`
        : 'Not specified';
    
    document.getElementById('viewModalBody').innerHTML = `
        <div style="display:grid;gap:16px;">
            <div style="text-align:center;padding:20px;background:#f8fafc;border-radius:8px;">
                <div class="service-icon" style="width:48px;height:48px;font-size:22px;margin:0 auto 12px;">
                    <i class="fas ${service.icon_class || 'fa-wrench'}"></i>
                </div>
                <h4 style="margin:0;font-size:18px;color:#1e293b;">${service.service_name}</h4>
                <div style="font-size:24px;color:#28a745;font-weight:700;margin-top:8px;">
                    ₱${parseFloat(service.service_price).toFixed(2)}
                </div>
            </div>
            <div style="display:grid;gap:10px;">
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e9ecef;">
                    <span style="color:#6c757d;font-weight:600;">Service Key:</span>
                    <span style="font-family:monospace;color:#002F70;">${service.service_key}</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e9ecef;">
                    <span style="color:#6c757d;font-weight:600;">Price Range:</span>
                    <span>${priceRange}</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e9ecef;">
                    <span style="color:#6c757d;font-weight:600;">Required Parts:</span>
                    <span>${service.parts_count || 0} part(s)</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e9ecef;">
                    <span style="color:#6c757d;font-weight:600;">Status:</span>
                    <span style="color:${service.status === 'active' ? '#28a745' : '#dc3545'};font-weight:700;">
                        ${service.status === 'active' ? 'Active' : 'Inactive'}
                    </span>
                </div>
                ${service.price_description ? `
                <div style="padding:8px 0;border-bottom:1px solid #e9ecef;">
                    <div style="color:#6c757d;font-weight:600;margin-bottom:4px;">Price Description:</div>
                    <div style="color:#374151;">${service.price_description}</div>
                </div>
                ` : ''}
                ${service.pricing_notes ? `
                <div style="padding:8px 0;">
                    <div style="color:#6c757d;font-weight:600;margin-bottom:4px;">Pricing Notes:</div>
                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px;color:#92400e;font-size:12px;">
                        ${service.pricing_notes}
                    </div>
                </div>
                ` : ''}
            </div>
        </div>
    `;
    
    openModal('viewModal');
}

// ── Edit service ────────────────────────────────────────────────────────────
function editService(id) {
    const service = servicesData.find(s => s.id == id);
    if (!service) return;
    
    document.getElementById('edit_service_id').value = service.id;
    document.getElementById('edit_service_name').value = service.service_name;
    document.getElementById('edit_service_price').value = parseFloat(service.service_price).toFixed(2);
    document.getElementById('edit_min_price').value = parseFloat(service.min_price || 0).toFixed(2);
    document.getElementById('edit_max_price').value = parseFloat(service.max_price || 0).toFixed(2);
    document.getElementById('edit_price_description').value = service.price_description || '';
    document.getElementById('edit_pricing_notes').value = service.pricing_notes || '';
    document.getElementById('edit_icon_class').value = service.icon_class || 'fa-wrench';
    
    openModal('editModal');
}

// ── Toggle status ───────────────────────────────────────────────────────────
function toggleStatus(id, newStatus, serviceName) {
    const action = newStatus === 'active' ? 'activate' : 'deactivate';
    if (!confirm(`Are you sure you want to ${action} "${serviceName}"?`)) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="service_id" value="${id}">
        <input type="hidden" name="new_status" value="${newStatus}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// ── Form validation ─────────────────────────────────────────────────────────
function validateServiceForm(form) {
    const price = parseFloat(form.service_price.value);
    const minPrice = parseFloat(form.min_price?.value || 0);
    const maxPrice = parseFloat(form.max_price?.value || 0);
    
    if (price < 0) {
        alert('Base fee cannot be negative.');
        return false;
    }
    
    if (minPrice > 0 && maxPrice > 0 && minPrice > maxPrice) {
        alert('Minimum price cannot be greater than maximum price.');
        return false;
    }
    
    return true;
}

// ── Close modals on background click ────────────────────────────────────────
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeModal(modal.id);
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
