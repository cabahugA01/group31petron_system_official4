<?php
/**
 * Merchandise Products — Product Management
 * Manager/Admin view: list, add, edit, activate/deactivate merchandise products.
 * Batch IDs are auto-generated from approved deliveries (no manual batch creation here).
 */
$page_id = 'mgr_prod_merchandise';
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

// ── Backfill station_inventory for existing products ──────────────────────
// Any product with ip.stock > 0 but no station_inventory row gets a row inserted
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
                    $initial_stock = (int)($_POST['initial_stock'] ?? 0);
                    $initial_batch = trim($_POST['initial_batch'] ?? '');

                    $pdo->beginTransaction();

                    // Insert product
                    $pdo->prepare("INSERT INTO inventory_products (station_id, product_name, category, sku, unit_cost, unit_price, stock, status, min_stock, max_stock, created_at) VALUES (?,?,?,?,?,?,?, 'active',?,?,NOW())")
                        ->execute([$station_id, $name, $category, $sku, $unit_cost, $unit_price, $initial_stock, $min_stock, $max_stock]);
                    $product_id = (int)$pdo->lastInsertId();

                    // Insert into station_inventory
                    $pdo->prepare("INSERT INTO station_inventory (product_id, station_id, stock_level, status, last_updated) VALUES (?, ?, ?, 'active', NOW())")
                        ->execute([$product_id, $station_id, $initial_stock]);

                    if ($initial_stock > 0) {
                        $batch_num = $initial_batch !== '' ? $initial_batch : 'B-INIT-' . str_pad($product_id, 4, '0', STR_PAD_LEFT);
                        
                        // Insert batch
                        $pdo->prepare("INSERT INTO merchandise_batches (product_id, station_id, batch_number, quantity_received, remaining_qty, unit_cost, supplier, date_received, encoded_by, status, notes) VALUES (?, ?, ?, ?, ?, ?, 'Initial Stock', CURDATE(), ?, 'active', 'Initial Stock Added')")
                            ->execute([$product_id, $station_id, $batch_num, $initial_stock, $initial_stock, $unit_cost, $me['id']]);
                        
                        // Insert stock_in history
                        $pdo->prepare("INSERT INTO merchandise_stock_in (po_number, station_id, product_id, product_name, sku, category, qty_ordered, qty_received, qty_variance, unit_cost, total_cost, condition_flag, remarks, stock_before, stock_after, encoded_by, encoded_at, batch_ref) VALUES ('INITIAL', ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'Good', 'Initial Stock Added', 0, ?, ?, NOW(), ?)")
                            ->execute([$station_id, $product_id, $name, $sku, $category, $initial_stock, $initial_stock, $unit_cost, round($unit_cost * $initial_stock, 2), $initial_stock, $me['id'], $batch_num]);
                    }

                    log_activity($pdo, $me['id'], 'Product Added', "Merchandise product '$name' (category: $category, initial stock: $initial_stock) added by {$me['name']}");
                    
                    $pdo->commit();
                    $_SESSION['success'] = "Product '$name' added with stock of $initial_stock.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
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
                // Check if price or cost changed
                $stmt = $pdo->prepare("SELECT unit_cost, unit_price FROM inventory_products WHERE id=?");
                $stmt->execute([$id]);
                $old = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($old && ((float)$old['unit_cost'] != $unit_cost || (float)$old['unit_price'] != $unit_price)) {
                    // Update non-pricing fields
                    $pdo->prepare("UPDATE inventory_products SET product_name=?, category=?, sku=?, min_stock=?, max_stock=? WHERE id=?")
                        ->execute([$name, $category, $sku, $min_stock, $max_stock, $id]);
                    
                    // Insert into pending_price_approvals
                    $pdo->prepare("INSERT INTO pending_price_approvals (station_id, product_type, product_id, old_cost, new_cost, old_price, new_price, manager_id, status) VALUES (?, 'merchandise', ?, ?, ?, ?, ?, ?, 'pending')")
                        ->execute([$station_id, $id, $old['unit_cost'], $unit_cost, $old['unit_price'], $unit_price, $me['id']]);
                    
                    $_SESSION['success'] = "Product details updated. Price change submitted for Admin approval.";
                    $log_msg = "Product '$name' updated. Price change submitted: Cost {$old['unit_cost']}->{$unit_cost}, Price {$old['unit_price']}->{$unit_price} (Pending Approval)";
                } else {
                    $pdo->prepare("UPDATE inventory_products SET product_name=?, category=?, sku=?, unit_cost=?, unit_price=?, min_stock=?, max_stock=? WHERE id=?")
                        ->execute([$name, $category, $sku, $unit_cost, $unit_price, $min_stock, $max_stock, $id]);
                    $_SESSION['success'] = "Product updated.";
                    $log_msg = "Merchandise product '$name' updated.";
                }
                log_activity($pdo, $me['id'], 'Product Updated', $log_msg);

            } catch (Exception $e) {
                $_SESSION['error'] = 'Error updating: ' . $e->getMessage();
            }
        }
        header('Location: manager_product_merchandise.php'); exit;
    }

    // ── Manual Stock In ──────────────────────────────────────────────────
    if ($action === 'manual_stock_in') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $qty_to_add = (int)($_POST['qty_to_add'] ?? 0);
        $unit_cost  = (float)($_POST['unit_cost'] ?? 0);
        $batch_num  = trim($_POST['batch_number'] ?? '');
        $remarks    = trim($_POST['remarks'] ?? '');

        if (!$product_id || $qty_to_add <= 0) {
            $_SESSION['error'] = 'Product ID and a valid quantity greater than 0 are required.';
        } else {
            try {
                // Fetch product details
                $stmt = $pdo->prepare("SELECT product_name, sku, category FROM inventory_products WHERE id = ?");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    $_SESSION['error'] = 'Product not found.';
                } else {
                    $pdo->beginTransaction();

                    // Get current stock before
                    $stock_before = 0;
                    $si_stmt = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE product_id = ? AND station_id = ?");
                    $si_stmt->execute([$product_id, $station_id]);
                    $si_row = $si_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($si_row) {
                        $stock_before = (int)$si_row['stock_level'];
                    }

                    $stock_after = $stock_before + $qty_to_add;

                    // Update station_inventory
                    if ($si_row) {
                        $pdo->prepare("UPDATE station_inventory SET stock_level = stock_level + ?, last_updated = NOW() WHERE product_id = ? AND station_id = ?")
                            ->execute([$qty_to_add, $product_id, $station_id]);
                    } else {
                        $pdo->prepare("INSERT INTO station_inventory (product_id, station_id, stock_level, status, last_updated) VALUES (?, ?, ?, 'active', NOW())")
                            ->execute([$product_id, $station_id, $qty_to_add]);
                    }

                    // Update inventory_products stock
                    $pdo->prepare("UPDATE inventory_products SET stock = stock + ? WHERE id = ?")
                        ->execute([$qty_to_add, $product_id]);

                    // Generate batch_number if empty
                    if ($batch_num === '') {
                        $batch_num = 'B-MAN-' . date('YmdHis') . '-' . str_pad($product_id, 4, '0', STR_PAD_LEFT);
                    }

                    // Check if batch number already exists for product/station
                    $batch_check = $pdo->prepare("SELECT id FROM merchandise_batches WHERE product_id = ? AND station_id = ? AND batch_number = ? LIMIT 1");
                    $batch_check->execute([$product_id, $station_id, $batch_num]);
                    if ($batch_check->fetchColumn()) {
                        // Update existing batch
                        $pdo->prepare("UPDATE merchandise_batches SET remaining_qty = remaining_qty + ?, quantity_received = quantity_received + ?, updated_at = NOW() WHERE product_id = ? AND station_id = ? AND batch_number = ?")
                            ->execute([$qty_to_add, $qty_to_add, $product_id, $station_id, $batch_num]);
                    } else {
                        // Insert new batch
                        $pdo->prepare("INSERT INTO merchandise_batches (product_id, station_id, batch_number, quantity_received, remaining_qty, unit_cost, supplier, date_received, encoded_by, status, notes) VALUES (?, ?, ?, ?, ?, ?, 'Manual Stock-In', CURDATE(), ?, 'active', ?)")
                            ->execute([$product_id, $station_id, $batch_num, $qty_to_add, $qty_to_add, $unit_cost, $me['id'], $remarks ?: 'Manual Stock-In']);
                    }

                    // Insert stock_in history
                    $pdo->prepare("INSERT INTO merchandise_stock_in (po_number, station_id, product_id, product_name, sku, category, qty_ordered, qty_received, qty_variance, unit_cost, total_cost, condition_flag, remarks, stock_before, stock_after, encoded_by, encoded_at, batch_ref) VALUES ('MANUAL', ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'Good', ?, ?, ?, ?, NOW(), ?)")
                        ->execute([$station_id, $product_id, $product['product_name'], $product['sku'], $product['category'], $qty_to_add, $qty_to_add, $unit_cost, round($unit_cost * $qty_to_add, 2), $remarks ?: 'Manual Stock-In', $stock_before, $stock_after, $me['id'], $batch_num]);

                    log_activity($pdo, $me['id'], 'Manual Stock-In', "Stocked in $qty_to_add pcs of '{$product['product_name']}' (cost: $unit_cost, batch: $batch_num)");

                    $pdo->commit();
                    $_SESSION['success'] = "Successfully stocked in $qty_to_add pcs of '{$product['product_name']}'.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['error'] = 'Error stocking in product: ' . $e->getMessage();
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

    // ── Validate product ────────────────────────────────────────────────
    if ($action === 'validate_product') {
        $id = (int)($_POST['product_id'] ?? 0);
        if ($id) {
            try {
                // Fetch product name
                $stmt = $pdo->prepare("SELECT product_name FROM inventory_products WHERE id=?");
                $stmt->execute([$id]);
                $pname = $stmt->fetchColumn();

                $pdo->prepare("UPDATE inventory_products SET status = 'Active' WHERE id=?")->execute([$id]);
                log_activity($pdo, $me['id'], 'Product Validated', "Pending merchandise product '$pname' (ID:$id) validated by {$me['name']}");
                $_SESSION['success'] = "Product '$pname' has been validated and is now active.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error validating product: ' . $e->getMessage();
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
            COALESCE(si.stock_level, ip.stock, 0) AS stock,
            ip.status,
            COALESCE(ip.min_stock, 0) AS min_stock,
            COALESCE(ip.max_stock, 0) AS max_stock,
            COALESCE(ba.active_batches, 0) AS active_batches,
            COALESCE(ba.total_batches,  0) AS total_batches,
            COALESCE(ba.batch_stock,    0) AS batch_stock
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
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading products: ' . $e->getMessage();
}

// ── Load batch IDs per product (for Batch ID column) ──────────────────────
$product_batches = [];
try {
    $bStmt = $pdo->prepare("
        SELECT product_id, id, batch_number, quantity_received, remaining_qty, unit_cost, date_received, status
        FROM merchandise_batches
        WHERE station_id = ? AND LOWER(status) IN ('active','depleted')
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
/* === Merchandise Product Management - Clean Table Design === */
.card { background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.08); border:1px solid #e9ecef; margin-bottom:20px; overflow:hidden; }
.card-header { padding:16px 20px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.card-header h3 { font-size:16px; font-weight:700; color:#002F70; margin:0; display:flex; align-items:center; gap:8px; }
.card-body { padding:20px; overflow-x:hidden; }
.pm-table-wrap { overflow:hidden; width:100%; }
.pm-table { min-width:100%; width:100%; border-collapse:collapse; table-layout:auto; }
.pm-table thead th { background:#002F70 !important; color:#fff !important; font-weight:600; padding:14px 12px !important; text-align:left !important; text-transform:uppercase; letter-spacing:0.3px; border:none !important; font-size:11px; }
.pm-table thead th:last-child { text-align:center !important; }
.pm-table tbody td { vertical-align:middle; padding:12px !important; border-bottom:1px solid #e9ecef !important; font-size:13px; }
.pm-table tbody td:last-child { text-align:center !important; }
.pm-table tbody tr:hover td { background:#e3f2fd !important; }
.pm-table tbody tr { transition:background 0.2s ease; }
.action-col { display:flex; flex-direction:column; gap:3px; min-width:90px; width:90px; align-items:center; justify-content:center; }
.action-col .btn { font-size:11px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:flex; align-items:center; gap:4px; justify-content:center; transition:all .15s; white-space:nowrap; width:100%; }
.action-col .btn:hover { filter:brightness(.9); }
.btn-view    { background:#28a745; color:#fff; }
.btn-edit    { background:#002F70; color:#fff; }
.btn-danger  { background:#dc3545; color:#fff; }
.btn-success { background:#28a745; color:#fff; }
.badge-status { padding:4px 10px; border-radius:4px; font-size:12px; font-weight:700; background:transparent !important; color:#28a745; }
.badge-cat    { padding:3px 9px; border-radius:4px; font-size:11px; font-weight:600; color:#6c757d !important; background:transparent !important; border:1px solid #e9ecef; }
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
.batch-fifo-tag { font-size:10px; background:#002F70; color:#fff; border:1px solid #001a4d; border-radius:8px; padding:1px 6px; font-weight:700; }
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

/* Print Styles - Hide Action Buttons and ACTION Column */
.no-print {
    /* For screen - visible normally */
}

@media print {
    @page {
        margin: 0.5in;
    }
    
    /* CRITICAL: Hide anything with no-print class */
    .no-print,
    th.no-print,
    td.no-print {
        display: none !important;
        visibility: hidden !important;
        width: 0 !important;
        max-width: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
        overflow: hidden !important;
    }
    
    /* CRITICAL: Hide the ACTION column completely using multiple methods */
    table th:last-child,
    table td:last-child,
    .pm-table th:last-child,
    .pm-table td:last-child,
    th:nth-last-child(1),
    td:nth-last-child(1) {
        display: none !important;
        visibility: hidden !important;
        width: 0 !important;
        max-width: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
    }
    
    /* Hide all buttons and action elements */
    button,
    .btn, .btn-view, .btn-edit, .btn-danger, .btn-success,
    .action-col,
    .header-actions,
    .page-head button,
    [class*="btn-"],
    input[type="button"],
    input[type="submit"] {
        display: none !important;
        visibility: hidden !important;
    }
    
    /* Hide all icons */
    i, svg, .fas, .far, .fab, .fa, .icon,
    [class*="fa-"] {
        display: none !important;
        visibility: hidden !important;
    }
    
    /* Hide navigation and UI elements */
    .sidebar, nav, .header-actions, .page-head .header-actions,
    #sidebar, .menu-toggle, .hamburger {
        display: none !important;
    }
    
    /* Clean table layout for print */
    body, html {
        margin: 0 !important;
        padding: 0 !important;
        overflow-x: hidden !important;
    }
    
    .pm-table {
        width: 100% !important;
        font-size: 9px !important;
        table-layout: fixed !important;
    }
    
    .pm-table th,
    .pm-table td {
        padding: 6px 4px !important;
        font-size: 8px !important;
        border: 1px solid #000 !important;
    }
    
    .pm-table thead th {
        background: #fff !important;
        color: #000 !important;
        font-weight: bold !important;
    }
}
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
        <div class="table-wrap pm-table-wrap">
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
                        <th class="no-print" style="">Actions</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php foreach ($products as $p):
                    $stock       = (int)($p['stock'] ?? 0);
                    $status      = $p['status'] ?? 'active';
                    $isActive    = ($status === 'active');
                    $isPending   = ($status === 'pending' || $status === 'pending_validation');
                    $stockColor  = $stock <= 0 ? '#dc3545' : ($stock <= (int)($p['min_stock'] ?? 10) ? '#ff9500' : '#28a745');
                    $statusColor = $isActive ? '#28a745' : ($isPending ? '#fd7e14' : '#dc3545');
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
                        <span style="color:<?php echo $stockColor; ?>;font-weight:700;"><?php echo number_format($stock); ?></span>
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
                        <?php if ($isPending): ?>
                            <span style="color:#fd7e14;font-weight:700;">Pending Validation</span>
                        <?php else: ?>
                            <span style="color:<?php echo $statusColor; ?>;font-weight:700;">
                                <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- 10. Actions -->
                    <td class="no-print">
                        <div class="action-col">
                            <button class="btn btn-view" onclick="viewProduct(<?php echo (int)$p['id']; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="btn btn-edit" onclick="editProduct(<?php echo (int)$p['id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn btn-success" onclick="openStockInModal(<?php echo (int)$p['id']; ?>)" style="background:#28a745;color:#fff;">
                                <i class="fas fa-dolly"></i> Stock In
                            </button>
                            <?php if ($isPending): ?>
                            <button class="btn btn-success" onclick="validateProduct(<?php echo (int)$p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['product_name'])); ?>')" style="background:#28a745;color:#fff;">
                                <i class="fas fa-check-double"></i> Validate
                            </button>
                            <?php elseif ($isActive): ?>
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
                            <div class="batch-panel-head">
                                <div class="batch-panel-title">
                                    <i class="fas fa-cubes"></i> Active Batches (FIFO Tracking)
                                </div>
                            </div>
                            <?php if (!empty($pid_batches)): ?>
                            <table class="batch-table">
                                <thead>
                                    <tr>
                                        <th>Batch ID</th>
                                        <th>Date Received</th>
                                        <th>Cost Price</th>
                                        <th>Qty Received</th>
                                        <th>Remaining Qty</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pid_batches as $pb):
                                        $bStatus = strtolower($pb['status'] ?? 'active');
                                        $statusClass = 'batch-status-' . $bStatus;
                                    ?>
                                    <tr>
                                        <td><strong style="font-family:monospace;"><?php echo htmlspecialchars($pb['batch_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($pb['date_received']); ?></td>
                                        <td>₱<?php echo number_format((float)$pb['unit_cost'], 2); ?></td>
                                        <td><?php echo number_format((int)$pb['remaining_qty']); ?> pcs</td>
                                        <td><strong><?php echo number_format((int)$pb['remaining_qty']); ?> pcs</strong></td>
                                        <td><span class="<?php echo $statusClass; ?>"><?php echo ucfirst($bStatus); ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                            <div style="font-size:12px;color:#6c757d;">No batch records for this product.</div>
                            <?php endif; ?>
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
                <div class="fg2">
                    <div class="form-group">
                        <label>Initial Stock</label>
                        <input type="number" name="initial_stock" class="form-control" min="0" value="0" placeholder="e.g. 50">
                    </div>
                    <div class="form-group">
                        <label>Initial Batch ID</label>
                        <input type="text" name="initial_batch" class="form-control" placeholder="e.g. B-INIT-001 (Optional)">
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

<!-- ══ MANUAL STOCK IN MODAL ══════════════════════════════════════════════════ -->
<div id="stockInModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-dolly" style="color:#28a745;"></i> Manual Stock In</h3>
            <button class="close" onclick="closeModal('stockInModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="manual_stock_in">
            <input type="hidden" name="product_id" id="siProductId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" id="siProductName" class="form-control" readonly style="background:#f8f9fa;">
                </div>
                <div class="fg2">
                    <div class="form-group">
                        <label>Qty to Add <span style="color:#dc3545;">*</span></label>
                        <input type="number" name="qty_to_add" id="siQty" class="form-control" min="1" required placeholder="e.g. 10">
                    </div>
                    <div class="form-group">
                        <label>Unit Cost (₱) <span style="color:#dc3545;">*</span></label>
                        <input type="number" name="unit_cost" id="siProductCost" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Batch Number</label>
                    <input type="text" name="batch_number" id="siBatch" class="form-control" placeholder="e.g. B-MAN-123 (Optional)">
                </div>
                <div class="form-group">
                    <label>Remarks</label>
                    <textarea name="remarks" id="siRemarks" class="form-control" placeholder="Optional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('stockInModal')">Cancel</button>
                <button type="submit" class="btn primary"><i class="fas fa-check"></i> Submit Stock In</button>
            </div>
        </form>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
// --- Toggle Batch Row ---
function toggleBatchRow(pid) {
    const row = document.getElementById('batch-row-' + pid);
    if (row) {
        row.classList.toggle('open');
    }
}

// --- Open Stock In Modal ---
function openStockInModal(id) {
    const p = productData.find(item => item.id == id);
    if (!p) return;
    document.getElementById('siProductId').value = p.id;
    document.getElementById('siProductName').value = p.product_name;
    document.getElementById('siProductCost').value = parseFloat(p.unit_cost).toFixed(2);
    document.getElementById('siQty').value = '';
    document.getElementById('siBatch').value = 'B-MAN-' + Math.floor(Date.now() / 1000) + '-' + p.id;
    document.getElementById('siRemarks').value = '';
    openModal('stockInModal');
}

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

function validateProduct(id, name) {
    if (!confirm(`Are you sure you want to validate and approve the product "${name}"?`)) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    const actInput = document.createElement('input');
    actInput.name = 'action';
    actInput.value = 'validate_product';
    form.appendChild(actInput);
    
    const idInput = document.createElement('input');
    idInput.name = 'product_id';
    idInput.value = id;
    form.appendChild(idInput);
    
    document.body.appendChild(form);
    form.submit();
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
    if (window.showPetronFlash) {
        window.showPetronFlash(msg, type);
        return;
    }
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
