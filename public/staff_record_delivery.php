<?php
$page_id = 'staff_record_delivery';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

$msg      = '';
$msg_type = 'success';

/* ══════════════════════════════════════════════════════════
   AJAX — fetch products by category
══════════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'products_by_category') {
    header('Content-Type: application/json');
    $cat = trim($_GET['category'] ?? '');
    $products = [];
    if ($cat !== '' && $cat !== 'Fuel') {
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT product_name
                FROM inventory_products
                WHERE category = ? AND category NOT IN ('Fuel')
                ORDER BY product_name
            ");
            $stmt->execute([$cat]);
            $products = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $products = [];
        }
    }
    echo json_encode(['products' => $products]);
    exit;
}

/* ══════════════════════════════════════════════════════════
   Bootstrap deliveries_oversight table once
══════════════════════════════════════════════════════════ */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deliveries_oversight (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            delivery_type   ENUM('fuel','merchandise') NOT NULL DEFAULT 'merchandise',
            delivery_ref    VARCHAR(100) NOT NULL DEFAULT '',
            supplier        VARCHAR(200) NOT NULL DEFAULT '',
            product         VARCHAR(200) NOT NULL DEFAULT '',
            quantity        DECIMAL(12,3) NOT NULL DEFAULT 0,
            unit            VARCHAR(30)  NOT NULL DEFAULT 'pcs',
            delivery_date   DATE         NOT NULL,
            dr_number       VARCHAR(100) DEFAULT NULL,
            encoded_by      INT          DEFAULT NULL,
            station_id      INT          NOT NULL,
            status          VARCHAR(60)  NOT NULL DEFAULT 'Pending Manager Approval',
            admin_id        INT          DEFAULT NULL,
            admin_action_at DATETIME     DEFAULT NULL,
            admin_notes     TEXT         DEFAULT NULL,
            remarks         TEXT         DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_station (station_id),
            INDEX idx_status  (status),
            INDEX idx_date    (delivery_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) { /* table already exists */ }

/* Add columns that may be missing in older installs */
foreach (['remarks TEXT DEFAULT NULL', 'dr_number VARCHAR(100) DEFAULT NULL'] as $col_def) {
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN {$col_def}"); } catch (Exception $e) {}
}

/* Bootstrap audit_trail table */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_trail (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(255) NOT NULL DEFAULT '',
            manager_id     INT          DEFAULT NULL,
            action_type    VARCHAR(50)  NOT NULL DEFAULT '',
            timestamp      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            old_value      TEXT         DEFAULT NULL,
            new_value      TEXT         DEFAULT NULL,
            station_id     INT          NOT NULL DEFAULT 0,
            entity_type    VARCHAR(50)  NOT NULL DEFAULT 'delivery',
            INDEX idx_at_station (station_id),
            INDEX idx_at_entity  (entity_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

/* ══════════════════════════════════════════════════════════
   EDIT MODE — load rejected delivery for resubmit
══════════════════════════════════════════════════════════ */
$edit_id       = (int)($_GET['edit'] ?? 0);
$edit_delivery = null;
if ($edit_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM deliveries_oversight
            WHERE id = ? AND station_id = ? AND encoded_by = ? AND status = 'Discrepancy'
        ");
        $stmt->execute([$edit_id, $station_id, $me['id']]);
        $edit_delivery = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

/* ══════════════════════════════════════════════════════════
   POST — Record Delivery (new) OR Resubmit (edit)
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_delivery') {
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $item_name     = trim($_POST['item_name']     ?? '');
    $category      = trim($_POST['category']      ?? '');
    $quantity      = (float)($_POST['quantity']   ?? 0);
    $unit          = trim($_POST['unit']          ?? 'pcs');
    $delivery_date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $dr_number     = trim($_POST['dr_number']     ?? '') ?: null;
    $remarks       = trim($_POST['remarks']       ?? '') ?: null;

    /* Reject Fuel category */
    if ($category === 'Fuel') {
        $msg      = 'Fuel deliveries are handled in Fuel Management. Please select a merchandise category.';
        $msg_type = 'error';
    } elseif ($supplier_name === '') {
        $msg = 'Supplier Name is required.'; $msg_type = 'error';
    } elseif ($item_name === '') {
        $msg = 'Item / Product Name is required.'; $msg_type = 'error';
    } elseif ($category === '') {
        $msg = 'Category is required.'; $msg_type = 'error';
    } elseif ($quantity <= 0) {
        $msg = 'Quantity must be greater than zero.'; $msg_type = 'error';
    } elseif ($delivery_date === '') {
        $msg = 'Date Received is required.'; $msg_type = 'error';
    } else {
        try {
            /* Auto-generate delivery reference (MDR = Merchandise Delivery Receipt) */
            $delivery_ref = 'MDR-' . date('Ymd', strtotime($delivery_date))
                          . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

            $resubmit_id = (int)($_POST['resubmit_id'] ?? 0);

            if ($resubmit_id > 0) {
                /* RESUBMIT — update existing rejected record back to Pending */
                $pdo->prepare("
                    UPDATE deliveries_oversight
                    SET supplier = ?, product = ?, quantity = ?, unit = ?,
                        delivery_date = ?, dr_number = ?, remarks = ?,
                        status = 'Pending Manager Approval',
                        admin_notes = NULL, admin_id = NULL, admin_action_at = NULL,
                        updated_at = NOW()
                    WHERE id = ? AND station_id = ? AND encoded_by = ?
                ")->execute([
                    $supplier_name, $item_name, $quantity, $unit,
                    $delivery_date, $dr_number, $remarks,
                    $resubmit_id, $station_id, $me['id']
                ]);
                /* Fetch delivery_ref for audit/message */
                $ref_row = $pdo->prepare("SELECT delivery_ref FROM deliveries_oversight WHERE id = ?");
                $ref_row->execute([$resubmit_id]);
                $delivery_ref = $ref_row->fetchColumn() ?: $delivery_ref;
                $msg = "&#10003; Delivery <strong>{$delivery_ref}</strong> resubmitted. Status: <strong>Pending Manager Approval</strong>.";
            } else {
                /* NEW delivery — INSERT */
                $pdo->prepare("
                    INSERT INTO deliveries_oversight
                        (delivery_type, delivery_ref, supplier, product, quantity, unit,
                         delivery_date, dr_number, encoded_by, station_id, status, remarks,
                         created_at, updated_at)
                    VALUES ('merchandise', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Manager Approval', ?, NOW(), NOW())
                ")->execute([
                    $delivery_ref, $supplier_name, $item_name,
                    $quantity, $unit, $delivery_date, $dr_number,
                    $me['id'], $station_id, $remarks
                ]);
                $msg = "&#10003; Delivery <strong>{$delivery_ref}</strong> recorded. Status: <strong>Pending Manager Approval</strong>.";
            }

            $msg_type = 'success';

            /* Audit trail — write to audit_logs so it shows in Audit Trail report */
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $action_label = $resubmit_id > 0 ? 'Update' : 'Create';
                $detail = ($resubmit_id > 0 ? 'Delivery resubmitted' : 'Delivery recorded')
                        . " | Ref: {$delivery_ref} | Supplier: {$supplier_name} | Item: {$item_name}"
                        . " | Qty: {$quantity} {$unit} | Date: {$delivery_date}"
                        . ($dr_number ? " | DR#: {$dr_number}" : '');
                $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', ?, ?, 'deliveries', ?, 'Success', ?, ?, NOW())")
                    ->execute([$me['id'], $action_label, $detail, null, $ip, $ua]);
            } catch (Exception $ae) {}

            /* Audit trail — legacy table */
            try {
                $pdo->prepare("
                    INSERT INTO audit_trail
                        (transaction_id, manager_id, action_type, new_value, station_id, entity_type)
                    VALUES (?, ?, 'Staff Record Delivery', ?, ?, 'delivery')
                ")->execute([
                    $delivery_ref, $me['id'],
                    json_encode([
                        'supplier' => $supplier_name, 'product' => $item_name,
                        'category' => $category, 'quantity' => $quantity,
                        'unit' => $unit, 'delivery_date' => $delivery_date,
                        'dr_number' => $dr_number, 'status' => 'Pending Manager Approval',
                        'resubmit' => $resubmit_id > 0,
                    ]),
                    $station_id
                ]);
            } catch (Exception $ae) {}

            /* Activity log */
            log_activity($pdo, $me['id'], $resubmit_id > 0 ? 'Staff Resubmit Delivery' : 'Staff Record Delivery',
                "DR: {$delivery_ref} | Supplier: {$supplier_name} | Item: {$item_name} | Qty: {$quantity} {$unit} | Date: {$delivery_date}");

            /* Clear POST data on success so form resets */
            $_POST    = [];
            $edit_id  = 0;
            $edit_delivery = null;

        } catch (Exception $e) {
            $msg      = 'Error recording delivery: ' . $e->getMessage();
            $msg_type = 'error';
        }
    }
}

/* ── Fetch merchandise categories (no Fuel) ── */
$merch_cats = [];
try {
    $mc = $pdo->query("
        SELECT DISTINCT category
        FROM inventory_products
        WHERE category NOT IN ('Fuel') AND category IS NOT NULL AND category <> ''
        ORDER BY category
    ");
    $merch_cats = $mc ? $mc->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Exception $e) {}
if (empty($merch_cats)) {
    $merch_cats = ['Accessories', 'Car Care', 'Oil & Lubricants', 'Other'];
}

/* ── Fetch ALL merchandise products for initial datalist ── */
$merch_products = [];
try {
    $mp = $pdo->query("
        SELECT DISTINCT product_name
        FROM inventory_products
        WHERE category NOT IN ('Fuel') AND product_name IS NOT NULL AND product_name <> ''
        ORDER BY product_name
    ");
    $merch_products = $mp ? $mp->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Exception $e) {}

/* ── Fetch suppliers ── */
$suppliers = ['Petron Corporation', '3rd Party Supplier'];
try {
    /* Try fuel_suppliers first */
    $sp = $pdo->query("SELECT DISTINCT supplier_name FROM fuel_suppliers WHERE supplier_name IS NOT NULL ORDER BY supplier_name");
    if ($sp) $suppliers = array_unique(array_merge($suppliers, $sp->fetchAll(PDO::FETCH_COLUMN)));
} catch (Exception $e) {}
try {
    /* Also try generic suppliers table */
    $sp2 = $pdo->query("SELECT DISTINCT name FROM suppliers WHERE name IS NOT NULL ORDER BY name");
    if ($sp2) $suppliers = array_unique(array_merge($suppliers, $sp2->fetchAll(PDO::FETCH_COLUMN)));
} catch (Exception $e) {}
sort($suppliers);

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Delivery card ── */
.del-card {
    background: #fff; border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border: 1px solid #e9ecef; margin-bottom: 24px;
}
.del-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid #e9ecef; flex-wrap: wrap; gap: 8px;
}
.del-card-title { font-size: 1rem; font-weight: 700; color: #002F70; display: flex; align-items: center; gap: 8px; }
.del-card-body  { padding: 24px; }

/* ── Form layout ── */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-bottom: 18px;
}
.form-row-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 18px;
    margin-bottom: 18px;
}
.form-row-full { margin-bottom: 18px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-label {
    font-size: 12px; font-weight: 700; color: #495057;
    text-transform: uppercase; letter-spacing: .4px;
}
.form-label .req { color: #dc3545; margin-left: 2px; }
.form-control, .form-select {
    padding: 9px 12px; border: 1px solid #ced4da; border-radius: 6px;
    font-size: 14px; color: #212529; background: #fff;
    transition: border-color .15s, box-shadow .15s;
    width: 100%; box-sizing: border-box;
}
.form-control:focus, .form-select:focus {
    border-color: #002F70; outline: 0;
    box-shadow: 0 0 0 .2rem rgba(0,47,112,.15);
}
.form-control[readonly] { background: #f8f9fa; color: #6c757d; cursor: default; }

/* ── Buttons ── */
.btn-primary-del {
    background: #002F70; color: #fff; border: none;
    padding: 11px 28px; border-radius: 7px; font-size: 14px; font-weight: 700;
    cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
    transition: background .15s;
}
.btn-primary-del:hover { background: #001F4F; }
.btn-secondary-del {
    background: #6c757d; color: #fff; border: none;
    padding: 11px 22px; border-radius: 7px; font-size: 14px; font-weight: 600;
    cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
    transition: background .15s; text-decoration: none;
}
.btn-secondary-del:hover { background: #5a6268; color: #fff; }

/* ── Alerts ── */
.alert-success-del {
    background: #d4edda; color: #155724; border: 1px solid #c3e6cb;
    border-radius: 8px; padding: 13px 16px; margin-bottom: 20px;
    display: flex; align-items: flex-start; gap: 10px; font-size: 14px;
}
.alert-error-del {
    background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;
    border-radius: 8px; padding: 13px 16px; margin-bottom: 20px;
    display: flex; align-items: flex-start; gap: 10px; font-size: 14px;
}

/* ── Info box ── */
.info-box {
    background: #e8f4fd; border-left: 4px solid #002F70;
    border-radius: 6px; padding: 11px 15px; margin-bottom: 20px;
    font-size: 12px; color: #002F70; line-height: 1.7;
}

/* ── Workflow steps ── */
.workflow-steps {
    display: flex; align-items: flex-start; gap: 0;
    margin-bottom: 28px; flex-wrap: wrap;
}
.workflow-step {
    display: flex; flex-direction: column; align-items: center;
    flex: 1; min-width: 120px; position: relative;
}
.workflow-step:not(:last-child)::after {
    content: '';
    position: absolute; top: 18px; left: calc(50% + 18px);
    width: calc(100% - 36px); height: 2px;
    background: #dee2e6;
}
.workflow-step.step-active:not(:last-child)::after { background: #002F70; }
.step-num {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; margin-bottom: 7px;
    position: relative; z-index: 1;
}
.step-active .step-num { background: #002F70; color: #fff; }
.step-todo   .step-num { background: #e9ecef; color: #adb5bd; }
.step-label { font-size: 11px; font-weight: 600; color: #6c757d; text-align: center; line-height: 1.3; }
.step-active .step-label { color: #002F70; }

/* ── Delivery ID preview ── */
.del-id-preview {
    font-family: monospace; font-size: 13px; font-weight: 700;
    color: #002F70; background: #e8f4fd; border: 1px solid #b8d4f0;
    border-radius: 6px; padding: 9px 12px; letter-spacing: .5px;
}

/* ── Form actions bar ── */
.form-actions {
    display: flex; align-items: center; gap: 12px;
    padding-top: 20px; border-top: 1px solid #e9ecef; flex-wrap: wrap;
    justify-content: flex-end;
}

@media (max-width: 640px) {
    .form-row, .form-row-3 { grid-template-columns: 1fr; }
    .workflow-steps { gap: 12px; }
    .workflow-step::after { display: none; }
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-truck-loading"></i> Record Delivery</h1>
        <div class="sub">Merchandise deliveries only &mdash; Fuel deliveries are in Fuel Management</div>
    </div>
    <div class="header-actions"></div>
</div>

<?php if ($msg): ?>
<div class="alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>-del">
    <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>" style="margin-top:2px;flex-shrink:0;"></i>
    <div><?php echo $msg; ?></div>
</div>
<?php endif; ?>



<div class="del-card">
    <div class="del-card-head">
        <div class="del-card-title">
            <i class="fas fa-file-invoice"></i> Delivery Receipt Details
        </div>
        <span style="font-size:12px;color:#6c757d;">Fields marked <span style="color:#dc3545;">*</span> are required</span>
    </div>
    <div class="del-card-body">



        <form method="POST" id="deliveryForm" novalidate>
            <input type="hidden" name="action" value="record_delivery">
            <input type="hidden" name="resubmit_id" value="<?php echo $edit_delivery ? (int)$edit_delivery['id'] : 0; ?>">

            <?php if ($edit_delivery): ?>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:11px 15px;margin-bottom:18px;font-size:13px;color:#856404;display:flex;align-items:flex-start;gap:10px;">
                <i class="fas fa-redo" style="margin-top:2px;flex-shrink:0;"></i>
                <div>
                    <strong>Editing rejected delivery <?php echo htmlspecialchars($edit_delivery['delivery_ref']); ?></strong>
                    <?php if (!empty($edit_delivery['admin_notes'])): ?>
                    <br>Manager note: <?php echo htmlspecialchars($edit_delivery['admin_notes']); ?>
                    <?php endif; ?>
                    <br><span style="font-size:12px;">Correct the details below and save to resubmit for approval.</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Row 1: Delivery ID preview + Supplier -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Delivery ID <span style="font-size:10px;color:#6c757d;font-weight:400;text-transform:none;">(auto-generated)</span></label>
                    <div class="del-id-preview" id="deliveryIdPreview">MDR-<?php echo date('Ymd'); ?>-XXXXXX</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="supplier_name">Supplier Name <span class="req">*</span></label>
                    <input type="text" id="supplier_name" name="supplier_name" class="form-control"
                           list="supplierList" placeholder="Type or select supplier..."
                           value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? $edit_delivery['supplier'] ?? ''); ?>" required autocomplete="off">
                    <datalist id="supplierList">
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <!-- Row 2: Date Received + Category -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="delivery_date">Date Received <span class="req">*</span></label>
                    <input type="date" id="delivery_date" name="delivery_date" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['delivery_date'] ?? $edit_delivery['delivery_date'] ?? date('Y-m-d')); ?>"
                           max="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="category">Category <span class="req">*</span></label>
                    <select id="category" name="category" class="form-select" required onchange="loadProductsByCategory()">
                        <option value="">— Select Category —</option>
                        <?php foreach ($merch_cats as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"
                                <?php echo (($_POST['category'] ?? '') === $cat) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Row 3: Item Name + Quantity + Unit -->
            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label" for="item_name">Item / Product Name <span class="req">*</span></label>
                    <input type="text" id="item_name" name="item_name" class="form-control"
                           list="productList" placeholder="Select category first, then type item..."
                           value="<?php echo htmlspecialchars($_POST['item_name'] ?? $edit_delivery['product'] ?? ''); ?>" required autocomplete="off">
                    <datalist id="productList">
                        <?php foreach ($merch_products as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <span id="productLoadingHint" style="font-size:11px;color:#6c757d;display:none;">
                        <i class="fas fa-spinner fa-spin"></i> Loading products...
                    </span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="quantity">Quantity <span class="req">*</span></label>
                    <input type="number" id="quantity" name="quantity" class="form-control"
                           step="0.001" min="0.001" placeholder="0.000"
                           value="<?php echo htmlspecialchars($_POST['quantity'] ?? $edit_delivery['quantity'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="unit">Unit <span class="req">*</span></label>
                    <select id="unit" name="unit" class="form-select" required>
                        <option value="pcs" <?php echo (($_POST['unit'] ?? 'pcs') === 'pcs') ? 'selected' : ''; ?>>pcs (Pieces)</option>
                        <option value="kg"  <?php echo (($_POST['unit'] ?? '') === 'kg')  ? 'selected' : ''; ?>>kg (Kilograms)</option>
                        <option value="box" <?php echo (($_POST['unit'] ?? '') === 'box') ? 'selected' : ''; ?>>box</option>
                        <option value="set" <?php echo (($_POST['unit'] ?? '') === 'set') ? 'selected' : ''; ?>>set</option>
                        <option value="L"   <?php echo (($_POST['unit'] ?? '') === 'L')   ? 'selected' : ''; ?>>L (Liters)</option>
                    </select>
                </div>
            </div>

            <!-- Row 4: DR Number + Remarks -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="dr_number">DR Number <span style="font-size:10px;color:#6c757d;font-weight:400;text-transform:none;">(optional)</span></label>
                    <input type="text" id="dr_number" name="dr_number" class="form-control"
                           placeholder="e.g. DR-2024-00123"
                           value="<?php echo htmlspecialchars($_POST['dr_number'] ?? $edit_delivery['dr_number'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="remarks">Remarks <span style="font-size:10px;color:#6c757d;font-weight:400;text-transform:none;">(optional)</span></label>
                    <input type="text" id="remarks" name="remarks" class="form-control"
                           placeholder="Any notes about this delivery..."
                           value="<?php echo htmlspecialchars($_POST['remarks'] ?? ''); ?>">
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn-primary-del" id="submitBtn">
                    <i class="fas fa-save"></i> Save Delivery Record
                </button>
                <button type="button" class="btn-secondary-del" onclick="resetForm()">
                    <i class="fas fa-undo"></i> Reset
                </button>

            </div>
        </form>
    </div>
</div>

<script>
/* ── AJAX: load products filtered by category ── */
function loadProductsByCategory() {
    const cat     = document.getElementById('category').value;
    const input   = document.getElementById('item_name');
    const list    = document.getElementById('productList');
    const hint    = document.getElementById('productLoadingHint');

    /* Clear current item value and datalist */
    input.value   = '';
    list.innerHTML = '';

    if (!cat) return;

    hint.style.display = 'inline';
    input.placeholder  = 'Loading products...';

    fetch('staff_record_delivery.php?ajax=products_by_category&category=' + encodeURIComponent(cat))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hint.style.display = 'none';
            input.placeholder  = 'Type or select item...';
            (data.products || []).forEach(function(p) {
                const opt   = document.createElement('option');
                opt.value   = p;
                list.appendChild(opt);
            });
            if ((data.products || []).length === 0) {
                input.placeholder = 'No products found — type manually';
            }
        })
        .catch(function() {
            hint.style.display = 'none';
            input.placeholder  = 'Type item name manually';
        });
}

/* ── Delivery ID preview updates on date change (MDR prefix) ── */
document.getElementById('delivery_date').addEventListener('change', function () {
    const d = this.value;
    if (!d) return;
    const parts = d.split('-');
    if (parts.length !== 3) return;
    document.getElementById('deliveryIdPreview').textContent =
        'MDR-' + parts[0] + parts[1] + parts[2] + '-XXXXXX';
});

/* ── Form validation ── */
document.getElementById('deliveryForm').addEventListener('submit', function (e) {
    const supplier = document.getElementById('supplier_name').value.trim();
    const item     = document.getElementById('item_name').value.trim();
    const category = document.getElementById('category').value;
    const quantity = parseFloat(document.getElementById('quantity').value);
    const date     = document.getElementById('delivery_date').value;

    const errors = [];

    if (category === 'Fuel') {
        errors.push('Fuel deliveries are handled in Fuel Management. Please select a merchandise category.');
    }
    if (!supplier)                  errors.push('Supplier Name is required.');
    if (!category)                  errors.push('Category is required.');
    if (!item)                      errors.push('Item / Product Name is required.');
    if (!quantity || quantity <= 0) errors.push('Quantity must be greater than 0.');
    if (!date)                      errors.push('Date Received is required.');

    if (errors.length > 0) {
        e.preventDefault();
        alert('Please fix the following:\n\n' + errors.join('\n'));
        return false;
    }

    /* Prevent double-submit */
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
});

/* ── Reset form helper ── */
function resetForm() {
    document.getElementById('deliveryForm').reset();
    document.getElementById('deliveryIdPreview').textContent =
        'MDR-<?php echo date('Ymd'); ?>-XXXXXX';
    document.getElementById('productList').innerHTML = '';
    document.getElementById('item_name').placeholder = 'Select category first, then type item...';
    const btn = document.getElementById('submitBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> Save Delivery Record';
}

/* ── On page load: if category already selected (POST error repopulate), reload products ── */
(function () {
    const cat = document.getElementById('category').value;
    if (cat) loadProductsByCategory();
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
