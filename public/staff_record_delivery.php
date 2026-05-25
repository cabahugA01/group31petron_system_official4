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
        } catch (Exception $e) {}
    }
    echo json_encode(['products' => $products]);
    exit;
}

/* Bootstrap deliveries_oversight table once */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deliveries_oversight (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            delivery_type   ENUM('fuel','merchandise') NOT NULL DEFAULT 'merchandise',
            delivery_ref    VARCHAR(100) NOT NULL DEFAULT '',
            batch_id        VARCHAR(100) DEFAULT NULL,
            supplier        VARCHAR(200) NOT NULL DEFAULT '',
            product         VARCHAR(200) NOT NULL DEFAULT '',
            quantity        DECIMAL(12,3) NOT NULL DEFAULT 0,
            unit            VARCHAR(30)  NOT NULL DEFAULT 'pcs',
            delivery_date   DATE         NOT NULL,
            dr_number       VARCHAR(100) DEFAULT NULL,
            encoded_by      INT          DEFAULT NULL,
            station_id      INT          NOT NULL,
            status          VARCHAR(60)  NOT NULL DEFAULT 'Pending Manager Approval',
            source_ref      VARCHAR(100) DEFAULT NULL,
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
} catch (Exception $e) {}

foreach (['remarks TEXT DEFAULT NULL', 'dr_number VARCHAR(100) DEFAULT NULL', 'batch_id VARCHAR(100) DEFAULT NULL', 'source_ref VARCHAR(100) DEFAULT NULL'] as $col_def) {
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN {$col_def}"); } catch (Exception $e) {}
}

/* ══════════════════════════════════════════════════════════
   POST — Receive Expected Delivery (Finalized PO)
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_expected') {
    $delivery_id = (int)($_POST['delivery_id'] ?? 0);
    $actual_qty  = (float)($_POST['actual_qty'] ?? 0);
    $dr_number   = trim($_POST['dr_number'] ?? '');
    $remarks     = trim($_POST['remarks'] ?? '');

    if ($delivery_id > 0 && $actual_qty > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ? AND status = 'Expected Delivery'");
            $stmt->execute([$delivery_id, $station_id]);
            $del = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($del) {
                $expected_qty = (float)$del['quantity'];
                
                // Variance check
                $status = 'Pending Manager Approval';
                $admin_notes = null;
                $diff = abs($actual_qty - $expected_qty);
                
                if ($diff > 0.001) {
                    $status = 'Discrepancy';
                    $admin_notes = "System Flag: Expected " . number_format($expected_qty, 2) . " {$del['unit']}, but received " . number_format($actual_qty, 2) . " {$del['unit']}. Variance: " . number_format($actual_qty - $expected_qty, 2) . " {$del['unit']}.";
                }

                $pdo->prepare("
                    UPDATE deliveries_oversight 
                    SET quantity = ?, dr_number = ?, remarks = ?, encoded_by = ?, status = ?, admin_notes = ?, delivery_date = CURDATE(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$actual_qty, $dr_number, $remarks, $me['id'], $status, $admin_notes, $delivery_id]);

                log_activity($pdo, $me['id'], 'Staff Received PO Delivery', "PO: {$del['source_ref']} | Product: {$del['product']} | Expected: {$expected_qty} | Actual: {$actual_qty}");
                
                $pdo->commit();

                if ($status === 'Discrepancy') {
                    header('Location: staff_delivery_history.php?msg=discrepancy&type=error');
                } else {
                    header('Location: staff_delivery_history.php?msg=received&type=success');
                }
                exit;
            } else {
                throw new Exception("Delivery not found or already processed.");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Error receiving delivery: ' . $e->getMessage();
            $msg_type = 'error';
        }
    } else {
        $msg = 'Please enter a valid actual quantity received.';
        $msg_type = 'error';
    }
}

/* ══════════════════════════════════════════════════════════
   POST — Record Delivery (Manual / Old Flow)
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_delivery') {
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $dr_number     = trim($_POST['dr_number']     ?? '') ?: null;
    $remarks       = trim($_POST['remarks']       ?? '') ?: null;

    $categories = $_POST['category'] ?? [];
    $item_names = $_POST['item_name'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $units      = $_POST['unit'] ?? [];

    if ($supplier_name === '') {
        $msg = 'Supplier Name is required.'; $msg_type = 'error';
    } elseif ($delivery_date === '') {
        $msg = 'Date Received is required.'; $msg_type = 'error';
    } elseif (empty($item_names)) {
        $msg = 'At least one item must be added.'; $msg_type = 'error';
    } else {
        try {
            $batch_prefix = 'BATCH-' . date('Ymd', strtotime($delivery_date)) . '-';
            $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(batch_id, '-', -1) AS UNSIGNED)) FROM deliveries_oversight WHERE batch_id LIKE ?");
            $stmt->execute([$batch_prefix . '%']);
            $max_batch_num = (int)$stmt->fetchColumn();
            $batch_id = $batch_prefix . str_pad($max_batch_num + 1, 3, '0', STR_PAD_LEFT);
            
            $date_prefix = 'MDR-' . date('Ymd', strtotime($delivery_date)) . '-';
            $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(delivery_ref, '-', -1) AS UNSIGNED)) FROM deliveries_oversight WHERE delivery_ref LIKE ?");
            $stmt->execute([$date_prefix . '%']);
            $max_num = (int)$stmt->fetchColumn();

            $pdo->beginTransaction();
            $success_count = 0;

            for ($i = 0; $i < count($item_names); $i++) {
                $category = trim($categories[$i] ?? '');
                $item_name = trim($item_names[$i] ?? '');
                $quantity = (float)($quantities[$i] ?? 0);
                $unit = trim($units[$i] ?? 'pcs');

                if ($category === 'Fuel' || $item_name === '' || $category === '' || $quantity <= 0) continue;

                $max_num++;
                $delivery_ref = $date_prefix . str_pad($max_num, 4, '0', STR_PAD_LEFT);
                
                $pdo->prepare("
                    INSERT INTO deliveries_oversight
                        (delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                         delivery_date, dr_number, encoded_by, station_id, status, remarks,
                         created_at, updated_at)
                    VALUES ('merchandise', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Manager Approval', ?, NOW(), NOW())
                ")->execute([
                    $delivery_ref, $batch_id, $supplier_name, $item_name,
                    $quantity, $unit, $delivery_date, $dr_number,
                    $me['id'], $station_id, $remarks
                ]);
                $success_count++;
            }

            $pdo->commit();
            if ($success_count > 0) {
                log_activity($pdo, $me['id'], 'Staff Manual Delivery', "Batch: {$batch_id} | Items: {$success_count}");
                header('Location: staff_delivery_history.php?msg=manual_saved&type=success');
                exit;
            } else {
                $msg = 'No valid items provided.'; $msg_type = 'error';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Error recording delivery: ' . $e->getMessage(); $msg_type = 'error';
        }
    }
}

/* ══════════════════════════════════════════════════════════
   POST — Edit / Resubmit Rejected Delivery
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_delivery') {
    $delivery_id = (int)($_POST['delivery_id'] ?? 0);
    $quantity    = (float)($_POST['quantity'] ?? 0);
    $dr_number   = trim($_POST['dr_number'] ?? '');
    $remarks     = trim($_POST['remarks'] ?? '');

    if ($delivery_id > 0 && $quantity > 0) {
        try {
            $pdo->prepare("
                UPDATE deliveries_oversight
                SET quantity = ?, dr_number = ?, remarks = ?, status = 'Pending Manager Approval', admin_notes = NULL, updated_at = NOW()
                WHERE id = ? AND station_id = ? AND status IN ('Discrepancy', 'Pending Resolution')
            ")->execute([$quantity, $dr_number, $remarks, $delivery_id, $station_id]);
            
            $msg = "&#10003; Delivery record successfully updated and resubmitted for manager approval.";
            $msg_type = 'success';
            log_activity($pdo, $me['id'], 'Staff Resubmitted Delivery', "Resubmitted delivery ID: {$delivery_id} with qty: {$quantity}");
            header('Location: staff_delivery_history.php?msg=resubmitted&type=success');
            exit;
        } catch (Exception $e) {
            $msg = 'Error updating delivery: ' . $e->getMessage(); $msg_type = 'error';
        }
    } else {
        $msg = 'Please provide valid quantity details.'; $msg_type = 'error';
    }
}

/* ── Fetch Expected Deliveries (from Admin Finalized POs) ── */
$expected_deliveries = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM deliveries_oversight 
        WHERE station_id = ? AND status = 'Expected Delivery' AND delivery_type = 'merchandise'
        ORDER BY created_at ASC
    ");
    $stmt->execute([$station_id]);
    $expected_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ── Fetch Merchandise Purchase Orders for staff reference ── */
$merchandise_purchase_orders = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*, s.name as supplier_name
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.station_id = ?
        ORDER BY po.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $merchandise_purchase_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching merchandise POs: " . $e->getMessage());
}

/* ── Fetch dependencies for manual form ── */
$merch_cats = ['Accessories', 'Car Care', 'Oil & Lubricants', 'Other'];
$merch_products = [];
$merch_products_map = [];
$suppliers = ['Petron Corporation', '3rd Party Supplier'];

try {
    $mc = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE category NOT IN ('Fuel') AND category IS NOT NULL ORDER BY category");
    if ($mc) $merch_cats = $mc->fetchAll(PDO::FETCH_COLUMN) ?: $merch_cats;
    
    $mp = $pdo->query("SELECT DISTINCT product_name, category FROM inventory_products WHERE category NOT IN ('Fuel') AND product_name IS NOT NULL ORDER BY product_name");
    if ($mp) {
        foreach ($mp->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $merch_products[] = $row['product_name'];
            $merch_products_map[$row['product_name']] = $row['category'];
        }
    }
    
    $sp2 = $pdo->query("SELECT DISTINCT name FROM suppliers WHERE name IS NOT NULL ORDER BY name");
    if ($sp2) $suppliers = array_unique(array_merge($suppliers, $sp2->fetchAll(PDO::FETCH_COLUMN)));
    sort($suppliers);
} catch (Exception $e) {}

/* ── Check if editing a rejected delivery ── */
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ? AND status IN ('Discrepancy', 'Pending Resolution')");
        $stmt->execute([$edit_id, $station_id]);
        $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$edit_data) {
            $msg = "Error: Delivery record not found or cannot be edited.";
            $msg_type = "error";
        }
    } catch (Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Layout & Cards ── */
.layout-grid { display: grid; grid-template-columns: 1fr; gap: 24px; margin-bottom: 30px; }
@media (min-width: 1100px) { .layout-grid { grid-template-columns: 1fr 1fr; } }

.del-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); border: 1px solid #e9ecef; height: 100%; display: flex; flex-direction: column; }
.del-card-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #e9ecef; }
.del-card-title { font-size: 1rem; font-weight: 700; color: #002F70; display: flex; align-items: center; gap: 8px; }
.del-card-body { padding: 24px; flex-grow: 1; overflow-y: auto; }

/* ── Alert ── */
.alert-box { padding: 13px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; font-size: 14px; }
.alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

/* ── Expected Deliveries List ── */
.expected-item { background: #f8f9fa; border: 1px solid #e9ecef; border-left: 4px solid #002F70; border-radius: 8px; padding: 14px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px; transition: transform .1s, box-shadow .1s; }
.expected-item:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,.05); }
.expected-info h4 { margin: 0 0 4px 0; font-size: 14px; color: #002F70; }
.expected-meta { font-size: 12px; color: #6c757d; display: flex; gap: 12px; flex-wrap: wrap; }
.expected-meta span { display: inline-flex; align-items: center; gap: 4px; }
.po-badge { background: #e8f4fd; color: #002F70; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 11px; font-weight: bold; border: 1px solid #b8d4f0; }
.btn-receive { background: #28a745; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; }
.btn-receive:hover { background: #218838; }

/* ── Forms ── */
.form-group { margin-bottom: 15px; }
.form-label { display: block; font-size: 12px; font-weight: 700; color: #495057; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 5px; }
.form-control, .form-select { width: 100%; padding: 9px 12px; border: 1px solid #ced4da; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.form-control:focus, .form-select:focus { border-color: #002F70; outline: 0; box-shadow: 0 0 0 3px rgba(0,47,112,.15); }
.form-control[readonly] { background: #e9ecef; cursor: not-allowed; font-weight: 600; color: #495057; }

/* ── Modal ── */
.modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.6); z-index: 9999; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal-box { background: #fff; border-radius: 14px; width: 500px; max-width: 90%; padding: 25px; box-shadow: 0 24px 80px rgba(0,0,0,.3); animation: modalIn .2s ease; }
@keyframes modalIn { from{opacity:0;transform:scale(.95)} to{opacity:1;transform:scale(1)} }
.modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #e9ecef; padding-bottom: 12px; }
.variance-warning { display: none; background: #fff3cd; color: #856404; padding: 10px; border-radius: 6px; font-size: 12px; margin-top: 10px; border: 1px solid #ffeeba; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-boxes"></i> Record Merchandise Delivery</h1>
        <div class="sub">Receive expected merchandise deliveries from finalized POs management.</div>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert-box alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>" style="margin-top:2px;"></i>
    <div><?php echo $msg; ?></div>
</div>
<?php endif; ?>

<div class="layout-grid">
    <!-- LEFT: Expected Deliveries (From Admin POs) -->
    <div class="del-card">
        <div class="del-card-head">
            <div class="del-card-title">
                <i class="fas fa-clipboard-list"></i> Expected Deliveries
                <?php if(count($expected_deliveries)>0): ?>
                    <span style="background:#dc3545;color:#fff;border-radius:10px;padding:2px 8px;font-size:11px;"><?php echo count($expected_deliveries); ?></span>
                <?php endif; ?>
            </div>
            <span style="font-size:12px;color:#6c757d;">Based on Finalized POs</span>
        </div>
        <div class="del-card-body">
            <?php if (empty($expected_deliveries)): ?>
                <div style="text-align:center;padding:40px;color:#adb5bd;">
                    <i class="fas fa-box-open" style="font-size:3em;margin-bottom:15px;display:block;"></i>
                    No expected merchandise deliveries at the moment.
                </div>
            <?php else: ?>
                <?php foreach ($expected_deliveries as $ed): ?>
                <div class="expected-item">
                    <div class="expected-info">
                        <h4><?php echo htmlspecialchars($ed['product']); ?></h4>
                        <div class="expected-meta">
                            <span><i class="fas fa-hashtag"></i> PO: <span class="po-badge"><?php echo htmlspecialchars($ed['source_ref'] ?? 'N/A'); ?></span></span>
                            <span><i class="fas fa-box"></i> Exp: <strong><?php echo number_format($ed['quantity'], 2) . ' ' . $ed['unit']; ?></strong></span>
                            <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($ed['supplier']); ?></span>
                        </div>
                    </div>
                    <button class="btn-receive" onclick="openReceiveModal(
                        <?php echo $ed['id']; ?>, 
                        '<?php echo addslashes($ed['source_ref'] ?? ''); ?>',
                        '<?php echo addslashes($ed['product']); ?>',
                        '<?php echo addslashes($ed['supplier']); ?>',
                        <?php echo (float)$ed['quantity']; ?>,
                        '<?php echo addslashes($ed['unit']); ?>'
                    )">
                        <i class="fas fa-hand-holding-box"></i> Receive
                    </button>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Manual Encode (Fallback) -->
    <div class="del-card">
        <div class="del-card-head">
            <div class="del-card-title">
                <i class="fas fa-keyboard"></i> Manual Encode Delivery
            </div>
            <span style="font-size:12px;color:#6c757d;">For 3rd party or non-PO deliveries</span>
        </div>
        <div class="del-card-body">
            <form method="POST" id="manualForm">
                <input type="hidden" name="action" value="record_delivery">
                
                <div class="form-group">
                    <label class="form-label">Supplier Name <span style="color:red;">*</span></label>
                    <input type="text" name="supplier_name" class="form-control" list="supplierList" required>
                    <datalist id="supplierList">
                        <?php foreach ($suppliers as $s): ?><option value="<?php echo htmlspecialchars($s); ?>"><?php endforeach; ?>
                    </datalist>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="form-group">
                        <label class="form-label">Category <span style="color:red;">*</span></label>
                        <select name="category[]" class="form-select" required>
                            <option value="">— Select Category —</option>
                            <?php foreach ($merch_cats as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Item Name <span style="color:red;">*</span></label>
                        <input type="text" name="item_name[]" class="form-control" list="productList" required>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="form-group">
                        <label class="form-label">Quantity <span style="color:red;">*</span></label>
                        <input type="number" step="0.01" name="quantity[]" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit <span style="color:red;">*</span></label>
                        <select name="unit[]" class="form-select">
                            <option value="pcs">pcs</option><option value="kg">kg</option><option value="box">box</option><option value="L">L</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">DR Number / Remarks</label>
                    <input type="text" name="dr_number" class="form-control" placeholder="Optional notes or DR #">
                </div>

                <div style="margin-top:20px;text-align:right;">
                    <button type="submit" style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:600;cursor:pointer;">
                        <i class="fas fa-save"></i> Save Manual Record
                    </button>
                </div>
            </form>
            <datalist id="productList">
                <?php foreach ($merch_products as $p): ?><option value="<?php echo htmlspecialchars($p); ?>"><?php endforeach; ?>
            </datalist>
        </div>
    </div>
</div>

<!-- Collapsible Merchandise PO Reference Card -->
<div class="del-card" style="margin-top: 20px; margin-bottom: 20px;">
    <div class="del-card-head" style="cursor: pointer;" onclick="toggleMerchPOCard()">
        <div class="del-card-title">
            <i class="fas fa-file-invoice-dollar" style="color: #002F70;"></i> Purchase Orders Reference (Merchandise)
            <span style="background:#002F70;color:#fff;border-radius:10px;padding:2px 8px;font-size:11px;"><?php echo count($merchandise_purchase_orders); ?></span>
        </div>
        <span style="font-size:12px;color:#6c757d;"><i class="fas fa-chevron-down" id="merchPoToggleChevron"></i> Click to Toggle</span>
    </div>
    <div class="del-card-body" id="merchPoCardBody" style="display: none; max-height: 400px; overflow-y: auto; padding: 20px;">
        <div style="margin-bottom: 12px; display: flex; gap: 8px;">
            <input type="text" id="merchPoSearchInput" placeholder="Search PO#, Product, Status..." onkeyup="searchMerchPOs()" style="padding: 6px 12px; border: 1.5px solid #ced4da; border-radius: 6px; font-size: 13px; width: 100%; max-width: 300px;">
        </div>
        <div style="overflow-x: auto;">
            <table style="width:100%; border-collapse:collapse; font-size: 12px; margin-bottom: 0;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e9ecef;">
                        <th style="padding: 8px; text-align: left; font-weight: 700; color: #64748b; text-transform: uppercase;">PO Number</th>
                        <th style="padding: 8px; text-align: left; font-weight: 700; color: #64748b; text-transform: uppercase;">Product Name</th>
                        <th style="padding: 8px; text-align: right; font-weight: 700; color: #64748b; text-transform: uppercase;">Quantity</th>
                        <th style="padding: 8px; text-align: left; font-weight: 700; color: #64748b; text-transform: uppercase;">Expected Date</th>
                        <th style="padding: 8px; text-align: left; font-weight: 700; color: #64748b; text-transform: uppercase;">Supplier</th>
                        <th style="padding: 8px; text-align: left; font-weight: 700; color: #64748b; text-transform: uppercase;">Status</th>
                    </tr>
                </thead>
                <tbody id="merchPoReferenceTableBody">
                    <?php if (empty($merchandise_purchase_orders)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #adb5bd; padding: 20px;">No purchase orders found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($merchandise_purchase_orders as $po):
                            $po_status = strtolower($po['status'] ?? 'pending');
                            $badge_class = 'pending';
                            if (in_array($po_status, ['approved', 'approved po'])) {
                                $badge_class = 'verified';
                            } elseif (in_array($po_status, ['rejected', 'cancelled'])) {
                                $badge_class = 'rejected';
                            }
                        ?>
                            <tr class="merch-po-row" style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 8px;"><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                                <td style="padding: 8px;"><?php echo htmlspecialchars($po['product_name']); ?></td>
                                <td style="padding: 8px; text-align: right;"><strong><?php echo number_format($po['quantity'], 2); ?></strong></td>
                                <td style="padding: 8px;"><?php echo $po['expected_delivery_date'] ? date('M d, Y', strtotime($po['expected_delivery_date'])) : '—'; ?></td>
                                <td style="padding: 8px;"><?php echo htmlspecialchars($po['supplier_name'] ?? 'Petron Corporation'); ?></td>
                                <td style="padding: 8px;">
                                    <span class="status-badge <?php echo $badge_class; ?>">
                                        <?php echo htmlspecialchars(ucfirst($po['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     RECEIVE MODAL (Auto-filled from PO)
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="receiveModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 style="margin:0;color:#002F70;font-size:16px;"><i class="fas fa-box-open"></i> Receive PO Delivery</h3>
            <button type="button" onclick="closeReceiveModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#adb5bd;">&times;</button>
        </div>
        
        <form method="POST" id="receiveForm">
            <input type="hidden" name="action" value="receive_expected">
            <input type="hidden" name="delivery_id" id="rec_id">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group">
                    <label class="form-label">PO Number</label>
                    <input type="text" id="rec_po" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Supplier</label>
                    <input type="text" id="rec_supplier" class="form-control" readonly>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Product</label>
                <input type="text" id="rec_product" class="form-control" readonly>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;background:#f8f9fa;padding:15px;border-radius:8px;border:1px solid #e9ecef;margin-bottom:15px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="color:#002F70;">Expected Quantity</label>
                    <div style="display:flex;align-items:center;gap:5px;">
                        <input type="text" id="rec_expected" class="form-control" readonly style="background:#e8f4fd;color:#002F70;border-color:#b8d4f0;">
                        <span id="rec_unit1" style="font-weight:bold;color:#6c757d;"></span>
                    </div>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="color:#28a745;">Actual Delivered Qty <span style="color:red;">*</span></label>
                    <div style="display:flex;align-items:center;gap:5px;">
                        <input type="number" step="0.01" name="actual_qty" id="rec_actual" class="form-control" required style="border-color:#28a745;background:#f8fff9;">
                        <span id="rec_unit2" style="font-weight:bold;color:#6c757d;"></span>
                    </div>
                </div>
            </div>

            <div class="variance-warning" id="varianceWarning">
                <i class="fas fa-exclamation-triangle"></i> <strong>Variance Detected!</strong><br>
                The actual quantity received does not match the PO expected quantity. This will be flagged for Manager review as a discrepancy.
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-top:15px;">
                <div class="form-group">
                    <label class="form-label">DR Number (Optional)</label>
                    <input type="text" name="dr_number" class="form-control" placeholder="e.g. DR-10293">
                </div>
                <div class="form-group">
                    <label class="form-label">Remarks (Optional)</label>
                    <input type="text" name="remarks" class="form-control" placeholder="Any issues?">
                </div>
            </div>

            <div style="text-align:right;margin-top:20px;border-top:1px solid #e9ecef;padding-top:15px;">
                <button type="button" onclick="closeReceiveModal()" style="background:#e9ecef;color:#495057;border:none;padding:9px 15px;border-radius:6px;font-weight:600;margin-right:10px;cursor:pointer;">Cancel</button>
                <button type="submit" style="background:#28a745;color:#fff;border:none;padding:9px 20px;border-radius:6px;font-weight:600;cursor:pointer;">
                    <i class="fas fa-check"></i> Submit Delivery
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const productCategoryMap = <?php echo json_encode($merch_products_map); ?>;

document.addEventListener('input', function(e) {
    if (e.target && e.target.name === 'item_name[]') {
        const selectedItem = e.target.value;
        if (productCategoryMap[selectedItem]) {
            const row = e.target.closest('div[style*="grid-template-columns"]');
            if (row) {
                const catSelect = row.querySelector('select[name="category[]"]');
                if (catSelect) {
                    catSelect.value = productCategoryMap[selectedItem];
                }
            }
        }
    }
});

let currentExpected = 0;

function openReceiveModal(id, po, product, supplier, expected, unit) {
    document.getElementById('rec_id').value = id;
    document.getElementById('rec_po').value = po || 'N/A';
    document.getElementById('rec_product').value = product;
    document.getElementById('rec_supplier').value = supplier;
    
    currentExpected = expected;
    document.getElementById('rec_expected').value = expected;
    document.getElementById('rec_actual').value = expected; // Pre-fill with expected
    
    document.getElementById('rec_unit1').textContent = unit;
    document.getElementById('rec_unit2').textContent = unit;
    
    checkVariance(); // Initialize variance check

    document.getElementById('receiveModal').classList.add('open');
}

function closeReceiveModal() {
    document.getElementById('receiveModal').classList.remove('open');
    document.getElementById('receiveForm').reset();
    document.getElementById('varianceWarning').style.display = 'none';
}

function checkVariance() {
    const actual = parseFloat(document.getElementById('rec_actual').value) || 0;
    const warn = document.getElementById('varianceWarning');
    
    // If difference is greater than a tiny floating point margin
    if (Math.abs(actual - currentExpected) > 0.001) {
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
}

document.getElementById('rec_actual').addEventListener('input', checkVariance);

document.addEventListener('DOMContentLoaded', function() {
    var m = document.getElementById('receiveModal');
    if (m && m.parentNode !== document.body) document.body.appendChild(m);

    var em = document.getElementById('editModal');
    if (em && em.parentNode !== document.body) document.body.appendChild(em);
});

function toggleMerchPOCard() {
    var body = document.getElementById('merchPoCardBody');
    var chevron = document.getElementById('merchPoToggleChevron');
    if (body.style.display === 'none') {
        body.style.display = 'block';
        chevron.className = 'fas fa-chevron-up';
    } else {
        body.style.display = 'none';
        chevron.className = 'fas fa-chevron-down';
    }
}

function searchMerchPOs() {
    var input = document.getElementById('merchPoSearchInput');
    var filter = input.value.toLowerCase();
    var rows = document.querySelectorAll('#merchPoReferenceTableBody .merch-po-row');
    
    rows.forEach(function(row) {
        var text = row.textContent.toLowerCase();
        if (text.indexOf(filter) > -1) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<?php if ($edit_data): ?>
<!-- EDIT / RESUBMIT MODAL -->
<div class="modal-overlay open" id="editModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 style="margin:0;color:#002F70;font-size:16px;"><i class="fas fa-edit"></i> Edit &amp; Resubmit Delivery</h3>
            <a href="staff_record_delivery.php" style="background:none;border:none;font-size:20px;cursor:pointer;color:#adb5bd;text-decoration:none;">&times;</a>
        </div>

        <div style="background:#fff3cd;color:#856404;padding:12px;border-radius:6px;font-size:12px;margin-bottom:15px;border:1px solid #ffeeba;">
            <strong><i class="fas fa-exclamation-triangle"></i> Manager Note:</strong>
            <?php echo htmlspecialchars($edit_data['admin_notes'] ?? 'Delivery was flagged for discrepancy. Please review and resubmit.'); ?>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="edit_delivery">
            <input type="hidden" name="delivery_id" value="<?php echo $edit_data['id']; ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Delivery Ref</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($edit_data['delivery_ref'] ?: ($edit_data['source_ref'] ?? 'N/A')); ?>" readonly>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Product</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($edit_data['product']); ?>" readonly>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" style="color:#28a745;">Corrected Quantity <span style="color:red;">*</span></label>
                <div style="display:flex;align-items:center;gap:8px;">
                    <input type="number" step="0.01" name="quantity" class="form-control"
                           value="<?php echo (float)$edit_data['quantity']; ?>" required
                           style="border-color:#28a745;background:#f8fff9;">
                    <span style="font-weight:bold;color:#6c757d;"><?php echo htmlspecialchars($edit_data['unit']); ?></span>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-top:10px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">DR Number</label>
                    <input type="text" name="dr_number" class="form-control"
                           value="<?php echo htmlspecialchars($edit_data['dr_number'] ?? ''); ?>" placeholder="e.g. DR-10293">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Remarks</label>
                    <input type="text" name="remarks" class="form-control"
                           value="<?php echo htmlspecialchars($edit_data['remarks'] ?? ''); ?>" placeholder="Any notes...">
                </div>
            </div>

            <div style="text-align:right;margin-top:20px;border-top:1px solid #e9ecef;padding-top:15px;">
                <a href="staff_record_delivery.php"
                   style="display:inline-block;background:#e9ecef;color:#495057;text-decoration:none;padding:9px 15px;border-radius:6px;font-weight:600;margin-right:10px;">
                    Cancel
                </a>
                <button type="submit" style="background:#fd7e14;color:#fff;border:none;padding:9px 20px;border-radius:6px;font-weight:600;cursor:pointer;">
                    <i class="fas fa-paper-plane"></i> Resubmit for Approval
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
