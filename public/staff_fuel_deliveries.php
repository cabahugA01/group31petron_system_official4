<?php
$page_id = 'staff_fuel_deliveries';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

$msg      = '';
$msg_type = 'success';

/* ══════════════════════════════════════════════════════════
   Bootstrap deliveries_oversight table
══════════════════════════════════════════════════════════ */
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

/* ══════════════════════════════════════════════════════════
   POST — Record Manual Fuel Delivery
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_fuel_delivery') {
    $supplier      = trim($_POST['supplier'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $fuel_type     = trim($_POST['fuel_type'] ?? '');
    $quantity      = (float)($_POST['quantity'] ?? 0);
    $invoice_no    = trim($_POST['invoice_no'] ?? '');
    $tanker_no     = trim($_POST['tanker_number'] ?? '');
    $remarks       = trim($_POST['remarks'] ?? '');

    if ($supplier === '') {
        $msg = 'Supplier Name is required.'; $msg_type = 'error';
    } elseif ($fuel_type === '') {
        $msg = 'Fuel Type is required.'; $msg_type = 'error';
    } elseif ($quantity <= 0) {
        $msg = 'Quantity must be greater than zero.'; $msg_type = 'error';
    } elseif ($invoice_no === '') {
        $msg = 'Invoice/DR Number is required.'; $msg_type = 'error';
    } else {
        try {
            $date_prefix = 'FDR-' . date('Ymd', strtotime($delivery_date)) . '-';
            $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(delivery_ref, '-', -1) AS UNSIGNED)) FROM deliveries_oversight WHERE delivery_ref LIKE ?");
            $stmt->execute([$date_prefix . '%']);
            $max_num = (int)$stmt->fetchColumn();
            $delivery_ref = $date_prefix . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);
            
            $pdo->beginTransaction();
            
            $pdo->prepare("
                INSERT INTO deliveries_oversight
                    (delivery_type, delivery_ref, supplier, product, quantity, unit,
                     delivery_date, dr_number, encoded_by, station_id, status, remarks,
                     created_at, updated_at)
                VALUES ('fuel', ?, ?, ?, ?, 'L', ?, ?, ?, ?, 'Pending Manager Approval', ?, NOW(), NOW())
            ")->execute([
                $delivery_ref, $supplier, $fuel_type, $quantity,
                $delivery_date, $invoice_no, $me['id'], $station_id,
                ($tanker_no ? "Tanker: {$tanker_no}. {$remarks}" : $remarks)
            ]);
            
            $pdo->commit();
            log_activity($pdo, $me['id'], 'Staff Manual Fuel Delivery', "Fuel: {$fuel_type} | Qty: {$quantity}L | Invoice: {$invoice_no}");
            header('Location: staff_fuel_delivery_status.php?msg=manual_saved&type=success');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Error recording delivery: ' . $e->getMessage(); $msg_type = 'error';
        }
    }
}

/* ── Fetch Expected Fuel Deliveries (from Admin Finalized POs) ── */
$expected_fuel_deliveries = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM deliveries_oversight 
        WHERE station_id = ? AND status = 'Expected Delivery' AND delivery_type = 'fuel'
        ORDER BY created_at ASC
    ");
    $stmt->execute([$station_id]);
    $expected_fuel_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ── Fetch dependencies for manual form ── */
$fuel_types = [];
$suppliers = ['Petron Corporation'];

try {
    $ft = $pdo->query("SELECT DISTINCT product_name FROM inventory_products WHERE LOWER(category) = 'fuel' ORDER BY product_name");
    if ($ft) $fuel_types = $ft->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($fuel_types)) {
        $ft2 = $pdo->query("SELECT name FROM fuel_types ORDER BY name");
        if ($ft2) $fuel_types = $ft2->fetchAll(PDO::FETCH_COLUMN);
    }
    
    $sp = $pdo->query("SELECT DISTINCT name FROM suppliers WHERE name IS NOT NULL ORDER BY name");
    if ($sp) $suppliers = array_unique(array_merge($suppliers, $sp->fetchAll(PDO::FETCH_COLUMN)));
    sort($suppliers);
} catch (Exception $e) {}

/* ── Check if coming from Expected Fuel Deliveries (PO selected) ── */
$selected_po = null;
if (isset($_GET['po_id'])) {
    $po_id = (int)$_GET['po_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ? AND status = 'Expected Delivery' AND delivery_type = 'fuel'");
        $stmt->execute([$po_id, $station_id]);
        $selected_po = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$selected_po) {
            $msg = "Error: Expected fuel delivery not found or already processed.";
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
.btn-receive { background: #28a745; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
.btn-receive:hover { background: #218838; }

/* ── Forms ── */
.form-group { margin-bottom: 15px; }
.form-label { display: block; font-size: 12px; font-weight: 700; color: #495057; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 5px; }
.form-control, .form-select { width: 100%; padding: 9px 12px; border: 1px solid #ced4da; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.form-control:focus, .form-select:focus { border-color: #002F70; outline: 0; box-shadow: 0 0 0 3px rgba(0,47,112,.15); }
.form-control[readonly] { background: #e9ecef; cursor: not-allowed; font-weight: 600; color: #495057; }
textarea.form-control { resize: vertical; font-family: inherit; min-height: 80px; }

.btn-submit { background: #002F70; color: #fff; border: none; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; width: 100%; transition: background .2s; }
.btn-submit:hover { background: #001f50; }
.btn-submit:disabled { opacity: .5; cursor: not-allowed; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-gas-pump"></i> Record Fuel Delivery</h1>
        <div class="sub">Encode actual fuel delivery details: Invoice number, fuel type, quantity (liters), and remarks.</div>
    </div>
    <div class="header-actions">
        <a href="staff_dashboard.php" style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px;transition:background .2s;">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert-box alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>" style="margin-top:2px;"></i>
    <div><?php echo $msg; ?></div>
</div>
<?php endif; ?>

<div class="layout-grid">
    <!-- LEFT: Expected Fuel Delivery Details (VIEW-ONLY - Reference for Staff) -->
    <div class="del-card">
        <div class="del-card-head">
            <div class="del-card-title">
                <i class="fas fa-clipboard-check"></i> <?php echo $selected_po ? 'Expected Fuel Delivery Details' : 'Expected Fuel Deliveries'; ?>
            </div>
            <?php if ($selected_po): ?>
                <span style="font-size:12px;color:#6c757d;">Reference Only - Use Manual Encode →</span>
            <?php else: ?>
                <span style="font-size:12px;color:#6c757d;">Based on Finalized POs</span>
            <?php endif; ?>
        </div>
        <div class="del-card-body">
            <?php if ($selected_po): ?>
                <!-- VIEW-ONLY: PO Order Details for Reference -->
                <div style="background:#e8f4fd;border:1px solid #b8d4f0;border-radius:8px;padding:20px;">
                    <h4 style="margin:0 0 16px 0;color:#002F70;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-file-invoice"></i> Purchase Order Details
                    </h4>
                    
                    <div style="display:grid;gap:12px;font-size:14px;">
                        <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #d0e7f9;">
                            <span style="color:#6c757d;font-weight:500;">PO Number:</span>
                            <strong style="color:#002F70;font-family:monospace;font-size:15px;"><?php echo htmlspecialchars($selected_po['source_ref'] ?? 'N/A'); ?></strong>
                        </div>
                        
                        <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #d0e7f9;">
                            <span style="color:#6c757d;font-weight:500;">Fuel Type:</span>
                            <strong style="color:#212529;"><?php echo htmlspecialchars($selected_po['product']); ?></strong>
                        </div>
                        
                        <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #d0e7f9;">
                            <span style="color:#6c757d;font-weight:500;">Supplier:</span>
                            <strong style="color:#212529;"><?php echo htmlspecialchars($selected_po['supplier']); ?></strong>
                        </div>
                        
                        <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #d0e7f9;">
                            <span style="color:#6c757d;font-weight:500;">Expected Quantity:</span>
                            <strong style="color:#002F70;font-size:16px;"><?php echo number_format($selected_po['quantity'], 2); ?> <span style="font-size:14px;color:#6c757d;">Liters</span></strong>
                        </div>
                        
                        <?php if (!empty($selected_po['remarks'])): ?>
                        <div style="padding:10px 0;">
                            <span style="color:#6c757d;font-weight:500;display:block;margin-bottom:6px;">Notes:</span>
                            <p style="margin:0;padding:10px;background:#fff;border-radius:6px;font-size:13px;color:#495057;"><?php echo htmlspecialchars($selected_po['remarks']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:14px;margin-top:20px;display:flex;align-items:flex-start;gap:10px;">
                    <i class="fas fa-info-circle" style="color:#856404;margin-top:2px;font-size:18px;flex-shrink:0;"></i>
                    <div style="font-size:13px;color:#856404;line-height:1.5;">
                        <strong>Instructions:</strong> Use the <strong>"Manual Encode Fuel Delivery"</strong> form on the right to record the actual delivery receipt. Fill in the actual quantity received, Invoice/DR number, tanker number, and any remarks.
                    </div>
                </div>

                <div style="margin-top:20px;text-align:center;">
                    <a href="staff_expected_fuel_deliveries.php" style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:background .2s;">
                        <i class="fas fa-arrow-left"></i> Back to Expected Deliveries
                    </a>
                </div>

            <?php elseif (empty($expected_fuel_deliveries)): ?>
                <div style="text-align:center;padding:40px;color:#adb5bd;">
                    <i class="fas fa-gas-pump" style="font-size:3em;margin-bottom:15px;display:block;"></i>
                    <p style="margin-bottom:16px;">No expected fuel deliveries at the moment.</p>
                    <a href="staff_expected_fuel_deliveries.php" style="color:#002F70;text-decoration:none;font-weight:600;">
                        <i class="fas fa-arrow-left"></i> View Expected Deliveries
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($expected_fuel_deliveries as $ed): ?>
                <div class="expected-item">
                    <div class="expected-info">
                        <h4><?php echo htmlspecialchars($ed['product']); ?> Fuel</h4>
                        <div class="expected-meta">
                            <span><i class="fas fa-hashtag"></i> PO: <span class="po-badge"><?php echo htmlspecialchars($ed['source_ref'] ?? 'N/A'); ?></span></span>
                            <span><i class="fas fa-gas-pump"></i> Exp: <strong><?php echo number_format($ed['quantity'], 2) . ' L'; ?></strong></span>
                            <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($ed['supplier']); ?></span>
                        </div>
                    </div>
                    <a href="staff_fuel_deliveries.php?po_id=<?php echo $ed['id']; ?>" class="btn-receive">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Manual Encode Fuel Delivery (Staff encodes actual receipt details) -->
    <div class="del-card">
        <div class="del-card-head">
            <div class="del-card-title">
                <i class="fas fa-keyboard"></i> Manual Encode Fuel Delivery
            </div>
            <span style="font-size:12px;color:#6c757d;"><?php echo $selected_po ? 'Based on PO (left panel)' : 'For 3rd party or non-PO deliveries'; ?></span>
        </div>
        <div class="del-card-body">
            <form method="POST" id="manualFuelForm">
                <input type="hidden" name="action" value="record_fuel_delivery">
                
                <div class="form-group">
                    <label class="form-label">Supplier Name <span style="color:red;">*</span></label>
                    <input type="text" name="supplier" class="form-control" list="supplierList" 
                           value="<?php echo $selected_po ? htmlspecialchars($selected_po['supplier']) : ''; ?>" required>
                    <datalist id="supplierList">
                        <?php foreach ($suppliers as $s): ?><option value="<?php echo htmlspecialchars($s); ?>"><?php endforeach; ?>
                    </datalist>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="form-group">
                        <label class="form-label">Fuel Type <span style="color:red;">*</span></label>
                        <select name="fuel_type" class="form-select" required>
                            <option value="">— Select Fuel Type —</option>
                            <?php foreach ($fuel_types as $ft): 
                                $selected = ($selected_po && $selected_po['product'] === $ft) ? 'selected' : '';
                            ?>
                                <option value="<?php echo htmlspecialchars($ft); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($ft); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date Received <span style="color:red;">*</span></label>
                        <input type="date" name="delivery_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="form-group">
                        <label class="form-label">Actual Quantity (Liters) <span style="color:red;">*</span></label>
                        <input type="number" step="0.01" name="quantity" class="form-control" 
                               value="<?php echo $selected_po ? $selected_po['quantity'] : ''; ?>" 
                               placeholder="Enter actual liters received" required>
                        <?php if ($selected_po): ?>
                        <small style="color:#6c757d;font-size:11px;display:block;margin-top:4px;">
                            Expected: <?php echo number_format($selected_po['quantity'], 2); ?> L
                        </small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Invoice/DR Number <span style="color:red;">*</span></label>
                        <input type="text" name="invoice_no" class="form-control" placeholder="e.g., INV-2026-001" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Tanker Number (Optional)</label>
                    <input type="text" name="tanker_number" class="form-control" placeholder="e.g., TK-123">
                </div>

                <div class="form-group">
                    <label class="form-label">Remarks / Notes (Optional)</label>
                    <textarea name="remarks" class="form-control" placeholder="Any additional notes or observations..."></textarea>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Save Fuel Delivery Record
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
