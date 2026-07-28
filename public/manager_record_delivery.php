<?php
$page_id = 'mgr_record_delivery';
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

$msg      = '';
$msg_type = 'success';

/* ══════════════════════════════════════════════════════════
   POST — Record Delivery
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_delivery') {
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $product_name  = trim($_POST['product_name']  ?? '');
    $category      = trim($_POST['category']      ?? '');
    $quantity      = (float)($_POST['quantity']   ?? 0);
    $unit          = trim($_POST['unit']          ?? 'L');
    $delivery_date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $dr_number     = trim($_POST['dr_number']     ?? '') ?: null;
    $remarks       = trim($_POST['remarks']       ?? '') ?: null;

    if ($supplier_name === '' || $product_name === '' || $category === '' || $quantity <= 0 || $delivery_date === '') {
        $msg      = 'Please fill in all required fields with valid values.';
        $msg_type = 'error';
    } else {
        try {
            /* Auto-generate delivery reference */
            $delivery_ref  = 'DR-' . date('Ymd', strtotime($delivery_date)) . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
            $delivery_type = ($category === 'Fuel') ? 'fuel' : 'merchandise';

            /* Bootstrap deliveries_oversight table */
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS deliveries_oversight (
                    id              INT AUTO_INCREMENT PRIMARY KEY,
                    delivery_type   ENUM('fuel','merchandise') NOT NULL DEFAULT 'fuel',
                    delivery_ref    VARCHAR(100) NOT NULL DEFAULT '',
                    supplier        VARCHAR(200) NOT NULL DEFAULT '',
                    product         VARCHAR(200) NOT NULL DEFAULT '',
                    quantity        DECIMAL(12,3) NOT NULL DEFAULT 0,
                    unit            VARCHAR(30)  NOT NULL DEFAULT 'L',
                    delivery_date   DATE         NOT NULL,
                    dr_number       VARCHAR(100) DEFAULT NULL,
                    encoded_by      INT          DEFAULT NULL,
                    station_id      INT          NOT NULL,
                    status          VARCHAR(60)  NOT NULL DEFAULT 'Pending Manager Approval',
                    manager_id      INT          DEFAULT NULL,
                    manager_action_at DATETIME   DEFAULT NULL,
                    manager_notes   TEXT         DEFAULT NULL,
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
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN remarks TEXT DEFAULT NULL"); } catch (Exception $ae) {}
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_id INT DEFAULT NULL"); } catch (Exception $ae) {}
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_action_at DATETIME DEFAULT NULL"); } catch (Exception $ae) {}
            try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_notes TEXT DEFAULT NULL"); } catch (Exception $ae) {}

            // Validate user ID against users table to prevent FK constraint violations
            $encoded_by_fk = null;
            if (!empty($me['id'])) {
                try {
                    $chk_u = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
                    $chk_u->execute([(int)$me['id']]);
                    $found_u = $chk_u->fetchColumn();
                    if ($found_u) {
                        $encoded_by_fk = (int)$found_u;
                    }
                } catch (Exception $e) {}
            }

            /* INSERT delivery record */
            $pdo->prepare("
                INSERT INTO deliveries_oversight
                    (delivery_type, delivery_ref, supplier, product, quantity, unit,
                     delivery_date, dr_number, encoded_by, station_id, status, remarks,
                     created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Manager Confirmation', ?, NOW(), NOW())
            ")->execute([
                $delivery_type, $delivery_ref, $supplier_name, $product_name,
                $quantity, $unit, $delivery_date, $dr_number,
                $encoded_by_fk, $station_id, $remarks
            ]);

            /* Bootstrap and INSERT into audit_trail */
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
                $pdo->prepare("
                    INSERT INTO audit_trail
                        (transaction_id, manager_id, action_type, old_value, new_value, station_id, entity_type)
                    VALUES (?, ?, 'Record Delivery', NULL, ?, ?, 'delivery')
                ")->execute([
                    $delivery_ref,
                    $me['id'],
                    json_encode([
                        'supplier'      => $supplier_name,
                        'product'       => $product_name,
                        'category'      => $category,
                        'quantity'      => $quantity,
                        'unit'          => $unit,
                        'delivery_date' => $delivery_date,
                        'dr_number'     => $dr_number,
                        'status'        => 'Pending Manager Confirmation',
                    ]),
                    $station_id
                ]);
            } catch (Exception $ae) {}

            /* Activity log */
            log_activity($pdo, $me['id'], 'Record Delivery',
                "Recorded delivery {$delivery_ref} | Supplier: {$supplier_name} | Product: {$product_name} | Qty: {$quantity} {$unit} | Date: {$delivery_date}");

            $msg      = "&#10003; Delivery <strong>{$delivery_ref}</strong> recorded successfully. Status: <strong>Pending Manager Confirmation</strong>.";
            $msg_type = 'success';

        } catch (Exception $e) {
            $msg      = 'Error recording delivery: ' . $e->getMessage();
            $msg_type = 'error';
        }
    }
}

/* ── Fetch fuel types ── */
$fuel_types = [];
try {
    $ft = $pdo->query("SELECT name FROM fuel_types ORDER BY name");
    $fuel_types = $ft ? $ft->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Exception $e) {
    $fuel_types = ['Gasoline', 'Diesel', 'Premium Gasoline', 'Kerosene'];
}

/* ── Fetch merchandise categories ── */
$merch_cats = [];
try {
    $mc = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE category NOT IN ('fuel', 'fuel products') ORDER BY category");
    $merch_cats = $mc ? $mc->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Exception $e) {
    $merch_cats = [];
}

/* ── Fetch suppliers ── */
$suppliers = ['Petron Corporation', 'Petron Fuel Depot'];
try {
    $sp = $pdo->query("SELECT DISTINCT supplier_name FROM fuel_suppliers ORDER BY supplier_name");
    $db_suppliers = $sp ? $sp->fetchAll(PDO::FETCH_COLUMN) : [];
    $suppliers = array_unique(array_merge($suppliers, $db_suppliers));
    sort($suppliers);
} catch (Exception $e) {
    // keep defaults
}

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
    flex: 1; position: relative;
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

            <!-- Row 1: Delivery ID preview + Supplier -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Delivery ID <span style="font-size:10px;color:#6c757d;font-weight:400;text-transform:none;">(auto-generated)</span></label>
                    <div class="del-id-preview" id="deliveryIdPreview">DR-<?php echo date('Ymd'); ?>-XXXXXX</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="supplier_name">Supplier Name <span class="req">*</span></label>
                    <input type="text" id="supplier_name" name="supplier_name" class="form-control"
                           list="supplierList" placeholder="Type or select supplier..."
                           value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? ''); ?>" required autocomplete="off">
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
                           value="<?php echo htmlspecialchars($_POST['delivery_date'] ?? date('Y-m-d')); ?>"
                           max="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="category">Category <span class="req">*</span></label>
                    <select id="category" name="category" class="form-select" required onchange="updateProductOptions()">
                        <option value="">— Select Category —</option>
                        <option value="Fuel" <?php echo (($_POST['category'] ?? '') === 'Fuel') ? 'selected' : ''; ?>>Fuel</option>
                        <?php foreach ($merch_cats as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"
                                <?php echo (($_POST['category'] ?? '') === $cat) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="Other" <?php echo (($_POST['category'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
            </div>

            <!-- Row 3: Product Name + Quantity + Unit -->
            <div class="form-row-3">
                <div class="form-group" style="grid-column: span 1;">
                    <label class="form-label" for="product_name">Product Name <span class="req">*</span></label>
                    <input type="text" id="product_name" name="product_name" class="form-control"
                           list="productList" placeholder="Type or select product..."
                           value="<?php echo htmlspecialchars($_POST['product_name'] ?? ''); ?>" required autocomplete="off">
                    <datalist id="productList">
                        <?php foreach ($fuel_types as $ft): ?>
                            <option value="<?php echo htmlspecialchars($ft); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label" for="quantity">Quantity Delivered <span class="req">*</span></label>
                    <input type="number" id="quantity" name="quantity" class="form-control"
                           step="0.001" min="0.001" placeholder="0.000"
                           value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="unit">Unit <span class="req">*</span></label>
                    <select id="unit" name="unit" class="form-select" required>
                        <option value="L"   <?php echo (($_POST['unit'] ?? 'L') === 'L')   ? 'selected' : ''; ?>>L (Liters)</option>
                        <option value="pcs" <?php echo (($_POST['unit'] ?? '') === 'pcs') ? 'selected' : ''; ?>>pcs (Pieces)</option>
                        <option value="kg"  <?php echo (($_POST['unit'] ?? '') === 'kg')  ? 'selected' : ''; ?>>kg (Kilograms)</option>
                        <option value="box" <?php echo (($_POST['unit'] ?? '') === 'box') ? 'selected' : ''; ?>>box</option>
                        <option value="set" <?php echo (($_POST['unit'] ?? '') === 'set') ? 'selected' : ''; ?>>set</option>
                    </select>
                </div>
            </div>

            <!-- Row 4: DR Number + Remarks -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="dr_number">DR Number <span style="font-size:10px;color:#6c757d;font-weight:400;text-transform:none;">(optional)</span></label>
                    <input type="text" id="dr_number" name="dr_number" class="form-control"
                           placeholder="e.g. DR-2024-00123"
                           value="<?php echo htmlspecialchars($_POST['dr_number'] ?? ''); ?>">
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
                <button type="reset" class="btn-secondary-del" onclick="resetForm()">
                    <i class="fas fa-undo"></i> Reset
                </button>

            </div>
        </form>
    </div>
</div>

<script>
/* ── Fuel types for datalist switching ── */
const fuelTypes  = <?php echo json_encode(array_values($fuel_types)); ?>;
const merchCats  = <?php echo json_encode(array_values($merch_cats)); ?>;

function updateProductOptions() {
    const cat      = document.getElementById('category').value;
    const unitSel  = document.getElementById('unit');
    const prodList = document.getElementById('productList');

    /* Clear existing options */
    prodList.innerHTML = '';

    if (cat === 'Fuel') {
        /* Populate with fuel types */
        fuelTypes.forEach(function(ft) {
            const opt = document.createElement('option');
            opt.value = ft;
            prodList.appendChild(opt);
        });
        /* Auto-select Liters */
        unitSel.value = 'L';
    } else {
        /* For merchandise, clear datalist (free text) */
        unitSel.value = 'pcs';
    }
}

/* ── Delivery ID preview updates on date change ── */
document.getElementById('delivery_date').addEventListener('change', function() {
    const d = this.value;
    if (!d) return;
    const parts = d.split('-');
    if (parts.length !== 3) return;
    const dateStr = parts[0] + parts[1] + parts[2];
    document.getElementById('deliveryIdPreview').textContent = 'DR-' + dateStr + '-XXXXXX';
});

/* ── Form validation ── */
document.getElementById('deliveryForm').addEventListener('submit', function(e) {
    const supplier = document.getElementById('supplier_name').value.trim();
    const product  = document.getElementById('product_name').value.trim();
    const category = document.getElementById('category').value;
    const quantity = parseFloat(document.getElementById('quantity').value);
    const date     = document.getElementById('delivery_date').value;

    let errors = [];
    if (!supplier)          errors.push('Supplier Name is required.');
    if (!product)           errors.push('Product Name is required.');
    if (!category)          errors.push('Category is required.');
    if (!quantity || quantity <= 0) errors.push('Quantity must be greater than 0.');
    if (!date)              errors.push('Date Received is required.');

    if (errors.length > 0) {
        e.preventDefault();
        alert('Please fix the following:\n\n' + errors.join('\n'));
        return false;
    }

    /* Disable submit to prevent double-submit */
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
});

/* ── Reset form helper ── */
function resetForm() {
    document.getElementById('deliveryIdPreview').textContent = 'DR-<?php echo date('Ymd'); ?>-XXXXXX';
    document.getElementById('unit').value = 'L';
    document.getElementById('submitBtn').disabled = false;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Save Delivery Record';
}

/* ── Init: set unit based on pre-selected category (e.g. after POST error) ── */
(function() {
    const cat = document.getElementById('category').value;
    if (cat) updateProductOptions();
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
