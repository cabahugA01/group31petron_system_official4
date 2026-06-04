<?php
// ============================================================
// Admin Purchase Orders — public/purchase_orders.php
// Flow: Manager forwards PR → Admin finalizes as Approved PO → Print
// ============================================================
$page_id = 'purchase_orders_admin';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? 'staff');

if (!in_array($role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

$station_id = (int) user_station_id();

$msg     = '';
$msgType = 'success';

// ── Ensure DB schema is up to date ──────────────────────────────────────────
try { $pdo->exec("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('Draft','Pending Approval','Approved','Rejected','Pending','Confirmed','Received','Cancelled','Pending Admin Validation','Official','Approved PO') DEFAULT 'Pending Admin Validation'"); } catch (Exception $ignored) {}
try { $pdo->exec("ALTER TABLE fuel_purchase_orders MODIFY COLUMN status ENUM('Draft','Pending Approval','Approved','Rejected','Pending','Confirmed','Received','Cancelled','Pending Admin Validation','Official','Approved PO') DEFAULT 'Pending Admin Validation'"); } catch (Exception $ignored) {}
try { $pdo->exec("ALTER TABLE fuel_purchase_orders ADD COLUMN updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at"); } catch (Exception $ignored) {}

/* ── POST: Admin finalizes or rejects PO ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $po_id        = (int)($_POST['po_id'] ?? 0);
    $po_type      = $_POST['po_type'] ?? 'merch';
    $action       = $_POST['action'];
    $remarks      = trim($_POST['remarks'] ?? '');

    if ($action === 'finalize_po') {
        $final_qty    = (float)($_POST['final_quantity']  ?? 0);
        $final_price  = (float)($_POST['final_unit_price'] ?? 0);
        $batch_id     = trim($_POST['batch_id']           ?? '');
        $total_amount = round($final_qty * $final_price, 2);

        if ($po_id > 0 && $final_qty > 0 && $final_price >= 0) {
            try {
                $pdo->beginTransaction();

                if ($po_type === 'merch') {
                    $stmt_po = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ?");
                    $stmt_po->execute([$po_id]);
                    $po_record = $stmt_po->fetch(PDO::FETCH_ASSOC);
                    if (!$po_record) throw new Exception('Merchandise PO not found.');

                    // Get or create Petron supplier
                    $sup_id = $pdo->query("SELECT id FROM suppliers WHERE name LIKE '%Petron%' LIMIT 1")->fetchColumn() ?: 0;
                    if (!$sup_id) {
                        $pdo->exec("INSERT INTO suppliers (name) VALUES ('Petron Corporation')");
                        $sup_id = $pdo->lastInsertId();
                    }

                    // Update PO
                    $pdo->prepare("
                        UPDATE purchase_orders
                        SET status       = 'Approved PO',
                            quantity     = ?,
                            unit_price   = ?,
                            total_amount = ?,
                            supplier_id  = ?,
                            remarks      = ?,
                            approved_by  = ?,
                            approved_at  = NOW(),
                            updated_at   = NOW()
                        WHERE id = ?
                    ")->execute([$final_qty, $final_price, $total_amount, $sup_id, $remarks, $me['id'], $po_id]);

                    // Sync to deliveries oversight
                    $eff_batch_id = $batch_id ?: ('BATCH-' . date('Ymd') . '-' . str_pad(rand(1,999), 3, '0', STR_PAD_LEFT));
                    $pdo->prepare("
                        INSERT INTO deliveries_oversight (
                            delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                            delivery_date, station_id, status, source_ref, remarks, created_at, updated_at
                        ) VALUES (
                            'merchandise', ?, ?, 'Petron Corporation', ?, ?, 'pcs',
                            CURDATE(), ?, 'Expected Delivery', ?, ?, NOW(), NOW()
                        )
                    ")->execute([
                        'MDR-' . date('Ymd') . '-' . rand(1000, 9999),
                        $eff_batch_id,
                        $po_record['product_name'],
                        $final_qty,
                        $po_record['station_id'] ?: $station_id,
                        $po_record['po_number'],
                        $remarks
                    ]);

                } else {
                    $stmt_po = $pdo->prepare("SELECT fpo.*, ft.name as product_name FROM fuel_purchase_orders fpo LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id WHERE fpo.id = ?");
                    $stmt_po->execute([$po_id]);
                    $po_record = $stmt_po->fetch(PDO::FETCH_ASSOC);
                    if (!$po_record) throw new Exception('Fuel PO not found.');

                    $sup_id = $pdo->query("SELECT id FROM suppliers WHERE name LIKE '%Petron%' LIMIT 1")->fetchColumn() ?: 0;
                    if (!$sup_id) {
                        $pdo->exec("INSERT INTO suppliers (name) VALUES ('Petron Corporation')");
                        $sup_id = $pdo->lastInsertId();
                    }

                    $pdo->prepare("
                        UPDATE fuel_purchase_orders
                        SET status       = 'Approved PO',
                            volume       = ?,
                            unit_price   = ?,
                            total_amount = ?,
                            supplier_id  = ?,
                            notes        = ?,
                            updated_at   = NOW()
                        WHERE id = ?
                    ")->execute([$final_qty, $final_price, $total_amount, $sup_id, $remarks, $po_id]);

                    // Sync to deliveries oversight
                    $eff_batch_id = $batch_id ?: ('FBATCH-' . date('Ymd') . '-' . str_pad(rand(1,999), 3, '0', STR_PAD_LEFT));
                    $pdo->prepare("
                        INSERT INTO deliveries_oversight (
                            delivery_type, delivery_ref, batch_id, supplier, product, quantity, unit,
                            delivery_date, station_id, status, source_ref, remarks, created_at, updated_at
                        ) VALUES (
                            'fuel', ?, ?, 'Petron Corporation', ?, ?, 'L',
                            CURDATE(), ?, 'Expected Delivery', ?, ?, NOW(), NOW()
                        )
                    ")->execute([
                        'FDR-' . date('Ymd') . '-' . rand(1000, 9999),
                        $eff_batch_id,
                        $po_record['product_name'],
                        $final_qty,
                        $po_record['station_id'] ?: $station_id,
                        $po_record['po_number'],
                        $remarks
                    ]);
                }

                // Notify staff
                $stat_id = $po_record['station_id'] ?: $station_id;
                $notify_url = ($po_type === 'fuel') ? 'staff_fuel_deliveries.php' : 'staff_record_delivery.php';
                $staffs = $pdo->prepare("SELECT id FROM users WHERE role IN ('staff','manager') AND station_id=?");
                $staffs->execute([$stat_id]);
                foreach ($staffs->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, event_type, severity, source_key, redirect_url)
                        VALUES (?, 'info', 'Incoming Delivery Expected', ?, 'delivery', 'medium', ?, ?)
                    ")->execute([
                        $sid,
                        "Incoming Delivery based on PO {$po_record['po_number']} is expected. Please prepare to receive it.",
                        "expected_del_" . $po_record['po_number'] . "_" . time(),
                        $notify_url
                    ]);
                }

                log_activity($pdo, $me['id'], 'Finalize Purchase Order', "PO {$po_record['po_number']} finalized. Qty: {$final_qty}");

                $pdo->commit();
                $msg     = "&#10003; PO <strong>{$po_record['po_number']}</strong> finalized as <strong>Approved PO</strong>.";
                $msgType = 'success';

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg     = "&#10005; Error: " . $e->getMessage();
                $msgType = 'error';
            }
        } else {
            $msg     = "&#10005; Please enter valid quantity and price.";
            $msgType = 'error';
        }
    } elseif ($action === 'reject_po') {
        if ($po_id > 0) {
            try {
                if ($po_type === 'merch') {
                    $pdo->prepare("UPDATE purchase_orders SET status='Rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([$remarks, $po_id]);
                } else {
                    $pdo->prepare("UPDATE fuel_purchase_orders SET status='Rejected', notes=?, updated_at=NOW() WHERE id=?")->execute([$remarks, $po_id]);
                }
                log_activity($pdo, $me['id'], 'Reject Purchase Order', "PO ID {$po_id} rejected. Reason: {$remarks}");
                $msg = "&#10003; Purchase Order has been rejected.";
                $msgType = 'success';
            } catch(Exception $e) {
                $msg = "&#10005; Error: " . $e->getMessage();
                $msgType = 'error';
            }
        }
    }
}

/* ── FETCH MERCHANDISE POs ── */
$merch_pending   = [];
$merch_processed = [];
try {
    $sql = "
        SELECT po.*, 'merch' as po_type,
               s.name   AS station_name,
               u.name   AS created_by_name,
               ab.name  AS approved_by_name,
               sr.staff_id,
               sr.item_sku,
               sr.purchase_request_id  AS pr_id,
               st.name  AS staff_name,
               mgr.name AS manager_name
        FROM purchase_orders po
        LEFT JOIN stations s        ON po.station_id  = s.id
        LEFT JOIN users u           ON po.created_by  = u.id
        LEFT JOIN users ab          ON po.approved_by = ab.id
        LEFT JOIN stock_requests sr ON po.request_id  = sr.id
        LEFT JOIN users st          ON sr.staff_id    = st.id
        LEFT JOIN users mgr         ON sr.manager_id  = mgr.id
        ORDER BY po.created_at DESC
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $po) {
        if ($po['status'] === 'Pending Admin Validation') {
            $merch_pending[] = $po;
        } else {
            $merch_processed[] = $po;
        }
    }
} catch (Exception $e) {
    $msg = "Error loading Merch POs: " . $e->getMessage();
    $msgType = 'error';
}

/* ── FETCH FUEL POs ── */
$fuel_pending = [];
$fuel_processed = [];
try {
    $sql_f = "
        SELECT fpo.*, 'fuel' as po_type,
               s.name AS station_name,
               ft.name AS product_name,
               u.name AS created_by_name,
               fpo.volume AS quantity,
               NULL AS pr_id,
               NULL AS staff_name,
               NULL AS manager_name,
               NULL AS item_sku,
               NULL AS approved_by_name
        FROM fuel_purchase_orders fpo
        LEFT JOIN stations s ON fpo.station_id = s.id
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN users u ON fpo.created_by = u.id
        ORDER BY fpo.created_at DESC
    ";
    $rows_f = $pdo->query($sql_f)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows_f as $po) {
        if (in_array($po['status'], ['Pending Admin Validation', 'Pending'])) {
            $fuel_pending[] = $po;
        } else {
            $fuel_processed[] = $po;
        }
    }
} catch (Exception $e) {
    $msg = "Error loading Fuel POs: " . $e->getMessage();
    $msgType = 'error';
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Cards ── */
.po-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:24px; }
.po-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.po-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.po-card-body  { padding:20px; }

/* ── Status badges ── */
.sbadge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
.sbadge-pending-admin-validation { background:#fff3cd; color:#856404; }
.sbadge-approved-po  { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.sbadge-official     { background:#d4edda; color:#155724; }
.sbadge-approved     { background:#d1ecf1; color:#0c5460; }
.sbadge-pending      { background:#fff3cd; color:#856404; }
.sbadge-cancelled    { background:#f8d7da; color:#721c24; }
.sbadge-rejected     { background:#f8d7da; color:#721c24; }

/* ── Alerts ── */
.po-alert { display:flex; align-items:center; gap:10px; padding:13px 16px; border-radius:8px; margin-bottom:18px; font-size:14px; }
.po-alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.po-alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* ── Buttons ── */
.btn-finalize { background:#002F70; color:#fff; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:5px; transition:background .15s; }
.btn-finalize:hover { background:#001F4F; }

/* ── Global Tabs ── */
.global-tabs { display:flex; gap:10px; border-bottom:2px solid #e9ecef; margin-bottom:20px; }
.global-tab { background:none; border:none; padding:12px 20px; cursor:pointer; font-size:14px; font-weight:700; color:#6c757d; border-bottom:3px solid transparent; transition:all .2s; margin-bottom:-2px; display:flex; align-items:center; gap:8px; }
.global-tab:hover { color:#002F70; }
.global-tab.active { color:#002F70; border-bottom-color:#002F70; }
.main-view { display:none; animation:fadeIn .3s; }
.main-view.active { display:block; }
@keyframes fadeIn { from{opacity:0;} to{opacity:1;} }

/* ── Audit chain ── */
.audit-chain { display:flex; align-items:center; gap:5px; font-size:11px; flex-wrap:wrap; }
.audit-chain .step { background:#f0f4ff; border:1px solid #c5d3f0; border-radius:4px; padding:2px 7px; color:#002F70; font-weight:600; }
.audit-chain .arrow { color:#adb5bd; }
.pr-id-tag { font-size:10px; background:#e6e6fa; color:#5f5f9c; border:1px solid #d8d8ff; border-radius:4px; padding:1px 6px; font-family:monospace; display:inline-block; margin-top:3px; }

/* ── Modal ── */
.modal-overlay { display:none; position:fixed; top:0;left:0;right:0;bottom:0; width:100vw;height:100vh; background:rgba(0,0,0,.6); z-index:9999; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:14px; padding:28px; width:560px; max-width:calc(100vw - 32px); max-height:calc(100vh - 40px); overflow-y:auto; box-shadow:0 24px 80px rgba(0,0,0,.3); animation:modalIn .2s ease; }
@keyframes modalIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
.modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; padding-bottom:14px; border-bottom:2px solid #e9ecef; }
.modal-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#adb5bd; line-height:1; }
.field-group { margin-bottom:14px; }
.field-group label { display:block; margin-bottom:5px; font-weight:700; font-size:12px; color:#495057; text-transform:uppercase; letter-spacing:.4px; }
.field-group input, .field-group textarea { width:100%; padding:9px 11px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; box-sizing:border-box; font-family:inherit;}
.field-group input[readonly] { background:#f8f9fa; color:#6c757d; }
.field-group input:focus:not([readonly]), .field-group textarea:focus { border-color:#002F70; outline:none; box-shadow:0 0 0 3px rgba(0,47,112,.12); }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; padding-top:14px; border-top:1px solid #e9ecef; }
.total-preview { background:#e8f4fd; border-left:4px solid #002F70; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-size:15px; color:#002F70; font-weight:700; }
.info-note { background:#e8f4fd; border-left:4px solid #002F70; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-size:12px; color:#002F70; line-height:1.7; }
</style>

<!-- ── Page Header ── -->
<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-file-invoice-dollar"></i> Purchase Orders</h1>
        <div class="sub">Receives validated Purchase Requests from Manager &mdash; Admin finalizes &amp; prints for Petron Corporation</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<?php if ($msg): ?>
<div class="po-alert po-alert-<?php echo $msgType; ?>">
    <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : 'times-circle'; ?>"></i>
    <span><?php echo $msg; ?></span>
</div>
<?php endif; ?>

<!-- ── GLOBAL TABS ── -->
<div class="global-tabs">
    <button class="global-tab active" onclick="switchMainView('fuel-view', this)">
        <i class="fas fa-gas-pump"></i> Fuel
        <?php if(count($fuel_pending)>0): ?>
            <span style="background:#dc3545;color:#fff;border-radius:10px;padding:2px 7px;font-size:10px;"><?php echo count($fuel_pending); ?></span>
        <?php endif; ?>
    </button>
    <button class="global-tab" onclick="switchMainView('merch-view', this)">
        <i class="fas fa-box"></i> Merchandise
        <?php if(count($merch_pending)>0): ?>
            <span style="background:#dc3545;color:#fff;border-radius:10px;padding:2px 7px;font-size:10px;"><?php echo count($merch_pending); ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- ==========================================
     FUEL VIEW
     ========================================== -->
<div id="fuel-view" class="main-view active">
    <!-- PENDING FUEL -->
    <div class="po-card">
        <div class="po-card-head">
            <div class="po-card-title">
                <i class="fas fa-clock"></i>
                Pending Admin Validation — Fuel
                <?php if (count($fuel_pending) > 0): ?>
                    <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?php echo count($fuel_pending); ?></span>
                <?php endif; ?>
            </div>

        </div>
        <div class="po-card-body">
            <?php if (empty($fuel_pending)): ?>
                <div style="text-align:center;padding:40px;color:#6c757d;">
                    <i class="fas fa-check-circle" style="font-size:2.5em;display:block;margin-bottom:10px;color:#28a745;opacity:.5;"></i>
                    <strong>No Fuel POs pending.</strong>
                </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Station</th>
                            <th>Fuel Type</th>
                            <th style="text-align:center;">Volume (L)</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Generated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fuel_pending as $po): ?>
                    <tr>
                        <td><strong style="color:#002F70;"><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($po['station_name'] ?? '—'); ?></td>
                        <td><strong><?php echo htmlspecialchars($po['product_name'] ?? '—'); ?></strong></td>
                        <td style="text-align:center;font-weight:700;"><?php echo number_format((float)($po['quantity'] ?? 0), 2); ?> L</td>
                        <td>&#8369;<?php echo number_format((float)($po['unit_price'] ?? 0), 2); ?></td>
                        <td><strong>&#8369;<?php echo number_format((float)($po['total_amount'] ?? 0), 2); ?></strong></td>
                        <td style="font-size:12px;color:#6c757d;"><?php echo date('M d, Y H:i', strtotime($po['created_at'])); ?></td>
                        <td>
                            <button class="btn-finalize" onclick="openFinalize(
                                <?php echo (int)$po['id']; ?>, 'fuel',
                                '<?php echo addslashes(htmlspecialchars($po['po_number'])); ?>',
                                '<?php echo addslashes(htmlspecialchars($po['product_name'] ?? '')); ?>',
                                <?php echo (float)($po['quantity'] ?? 0); ?>,
                                <?php echo (float)($po['unit_price'] ?? 0); ?>,
                                ''
                            )">
                                <i class="fas fa-check-double"></i> Review
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- PROCESSED FUEL -->
    <div class="po-card">
        <div class="po-card-head">
            <div class="po-card-title"><i class="fas fa-history"></i> Processed Purchase Orders — Fuel</div>
        </div>
        <div class="po-card-body">
            <?php if (empty($fuel_processed)): ?>
                <div style="text-align:center;padding:28px;color:#6c757d;">No processed Fuel POs.</div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Station</th>
                            <th>Fuel Type</th>
                            <th style="text-align:center;">Volume (L)</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fuel_processed as $po):
                        $st = $po['status'] ?? '';
                        $st_key = strtolower(str_replace([' ', '/'], ['-', '-'], $st));
                        $is_printable = in_array($st, ['Approved PO', 'Official', 'Approved']);
                    ?>
                    <tr>
                        <td><strong style="color:#002F70;"><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($po['station_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($po['product_name'] ?? '—'); ?></td>
                        <td style="text-align:center;"><?php echo number_format((float)($po['quantity'] ?? 0), 2); ?></td>
                        <td>&#8369;<?php echo number_format((float)($po['unit_price'] ?? 0), 2); ?></td>
                        <td><strong>&#8369;<?php echo number_format((float)($po['total_amount'] ?? 0), 2); ?></strong></td>
                        <td><span class="sbadge sbadge-<?php echo $st_key; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                        <td style="font-size:12px;color:#6c757d;">
                            <?php echo date('M d, Y', strtotime($po['updated_at'] ?? $po['created_at'])); ?>
                        </td>
                        <td>
                            <?php if ($is_printable): ?>
                            <a href="print_po_new.php?id=<?php echo (int)$po['id']; ?>&type=fuel&print=1" target="_blank"
                               style="font-size:12px;color:#002F70;text-decoration:none;display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border:1px solid #c5d3f0;border-radius:5px;background:#f0f4ff;font-weight:600;">
                                <i class="fas fa-print"></i> Print
                            </a>
                            <?php else: ?>
                            <span style="font-size:11px;color:#adb5bd;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==========================================
     MERCHANDISE VIEW
     ========================================== -->
<div id="merch-view" class="main-view">
    <!-- PENDING MERCH -->
    <div class="po-card">
        <div class="po-card-head">
            <div class="po-card-title">
                <i class="fas fa-clock"></i>
                Pending Admin Validation — Merchandise
                <?php if (count($merch_pending) > 0): ?>
                    <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?php echo count($merch_pending); ?></span>
                <?php endif; ?>
            </div>

        </div>
        <div class="po-card-body">
            <?php if (empty($merch_pending)): ?>
                <div style="text-align:center;padding:40px;color:#6c757d;">
                    <i class="fas fa-check-circle" style="font-size:2.5em;display:block;margin-bottom:10px;color:#28a745;opacity:.5;"></i>
                    <strong>No Merchandise POs pending.</strong>
                </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Station</th>
                            <th>Product / PR ID</th>
                            <th style="text-align:center;">Qty</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Audit Trail</th>
                            <th>Generated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($merch_pending as $po): ?>
                    <tr>
                        <td><strong style="color:#002F70;"><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($po['station_name'] ?? '—'); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($po['product_name'] ?? '—'); ?></strong>
                            <?php if (!empty($po['item_sku'])): ?>
                                <br><code style="font-size:10px;color:#6c757d;"><?php echo htmlspecialchars($po['item_sku']); ?></code>
                            <?php endif; ?>
                            <?php if (!empty($po['pr_id'])): ?>
                                <br><span class="pr-id-tag"><?php echo htmlspecialchars($po['pr_id']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;font-weight:700;"><?php echo number_format((float)($po['quantity'] ?? 0), 0); ?></td>
                        <td>&#8369;<?php echo number_format((float)($po['unit_price'] ?? 0), 2); ?></td>
                        <td><strong>&#8369;<?php echo number_format((float)($po['total_amount'] ?? 0), 2); ?></strong></td>
                        <td>
                            <div class="audit-chain">
                                <span class="step">&#128100; <?php echo htmlspecialchars($po['staff_name'] ?? 'Staff'); ?></span>
                                <span class="arrow">&#8594;</span>
                                <span class="step">&#128101; <?php echo htmlspecialchars($po['manager_name'] ?? $po['created_by_name'] ?? 'Manager'); ?></span>
                            </div>
                        </td>
                        <td style="font-size:12px;color:#6c757d;"><?php echo date('M d, Y H:i', strtotime($po['created_at'])); ?></td>
                        <td>
                            <button class="btn-finalize" onclick="openFinalize(
                                <?php echo (int)$po['id']; ?>, 'merch',
                                '<?php echo addslashes(htmlspecialchars($po['po_number'])); ?>',
                                '<?php echo addslashes(htmlspecialchars($po['product_name'] ?? '')); ?>',
                                <?php echo (float)($po['quantity'] ?? 0); ?>,
                                <?php echo (float)($po['unit_price'] ?? 0); ?>,
                                '<?php echo addslashes(htmlspecialchars($po['pr_id'] ?? '')); ?>'
                            )">
                                <i class="fas fa-check-double"></i> Review
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- PROCESSED MERCH -->
    <div class="po-card">
        <div class="po-card-head">
            <div class="po-card-title"><i class="fas fa-history"></i> Processed Purchase Orders — Merchandise</div>
        </div>
        <div class="po-card-body">
            <?php if (empty($merch_processed)): ?>
                <div style="text-align:center;padding:28px;color:#6c757d;">No processed Merch POs.</div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Station</th>
                            <th>Product</th>
                            <th style="text-align:center;">Qty</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($merch_processed as $po):
                        $st = $po['status'] ?? '';
                        $st_key = strtolower(str_replace([' ', '/'], ['-', '-'], $st));
                        $is_printable = in_array($st, ['Approved PO', 'Official', 'Approved']);
                    ?>
                    <tr>
                        <td><strong style="color:#002F70;"><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                        <td style="font-size:12px;"><?php echo htmlspecialchars($po['station_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($po['product_name'] ?? '—'); ?></td>
                        <td style="text-align:center;"><?php echo number_format((float)($po['quantity'] ?? 0), 0); ?></td>
                        <td>&#8369;<?php echo number_format((float)($po['unit_price'] ?? 0), 2); ?></td>
                        <td><strong>&#8369;<?php echo number_format((float)($po['total_amount'] ?? 0), 2); ?></strong></td>
                        <td><span class="sbadge sbadge-<?php echo $st_key; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                        <td style="font-size:12px;color:#6c757d;">
                            <?php echo $po['approved_at'] ? date('M d, Y', strtotime($po['approved_at'])) : date('M d, Y', strtotime($po['created_at'])); ?>
                        </td>
                        <td>
                            <?php if ($is_printable): ?>
                            <a href="print_po_new.php?id=<?php echo (int)$po['id']; ?>&print=1" target="_blank"
                               style="font-size:12px;color:#002F70;text-decoration:none;display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border:1px solid #c5d3f0;border-radius:5px;background:#f0f4ff;font-weight:600;">
                                <i class="fas fa-print"></i> Print
                            </a>
                            <?php else: ?>
                            <span style="font-size:11px;color:#adb5bd;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     FINALIZE MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="finalizeModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title">
                <i class="fas fa-check-double" style="color:#28a745;"></i>
                Review Purchase Order
            </div>
            <button class="modal-close" onclick="closeFinalize()">&times;</button>
        </div>

        <form method="POST" id="finalizeForm">
            <input type="hidden" name="action" id="formAction" value="finalize_po">
            <input type="hidden" name="po_id" id="finPoId">
            <input type="hidden" name="po_type" id="finPoType">

            <div class="field-group">
                <label>PO Number</label>
                <input type="text" id="finPoNumber" readonly>
            </div>

            <div class="field-group">
                <label>Product / Type</label>
                <input type="text" id="finProduct" readonly>
            </div>

            <div id="finPrIdRow" class="field-group" style="display:none;">
                <label>Purchase Request ID</label>
                <input type="text" id="finPrId" readonly style="background:#f0f0ff;color:#5f5f9c;font-family:monospace;font-weight:700;">
            </div>

            <div class="field-group">
                <label>Supplier</label>
                <input type="text" value="Petron Corporation" readonly style="background:#f0f8ff; color:#002F70; font-weight:bold;">
            </div>

            <div class="field-group">
                <label>Batch ID <span style="color:red;">*</span>
                    <span style="font-weight:400;color:#888;font-size:0.78rem;"> — unique identifier for this delivery batch (e.g. FB-001, APR2026-DIESEL-A)</span>
                </label>
                <input type="text" name="batch_id" id="finBatchId"
                       placeholder="e.g. FB-001" maxlength="80"
                       style="font-family:monospace;font-size:0.92rem;letter-spacing:0.5px;text-transform:uppercase;"
                       oninput="this.value=this.value.toUpperCase()">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="field-group">
                    <label>Final Quantity / Volume <span style="color:red;">*</span></label>
                    <input type="number" name="final_quantity" id="finQty" min="1" step="0.01" required placeholder="e.g. 20">
                </div>
                <div class="field-group">
                    <label>Unit Price &#8369; <span style="color:red;">*</span></label>
                    <input type="number" name="final_unit_price" id="finPrice" min="0" step="0.01" required placeholder="e.g. 705.90">
                </div>
            </div>

            <div class="field-group">
                <label>Remarks / Special Instructions <span style="font-weight:normal;color:#6c757d;">(Optional)</span></label>
                <textarea name="remarks" id="finRemarks" rows="2" placeholder="e.g. Please deliver before noon..."></textarea>
            </div>

            <div class="total-preview">
                Total Amount: &#8369;<span id="finTotal">0.00</span>
            </div>

            <div class="info-note">
                <i class="fas fa-info-circle"></i> <strong>On Finalize:</strong><br>
                &bull; Status → <strong>Approved PO</strong> (ready for printing)<br>
                &bull; Auto-syncs to Staff's <strong>Deliveries Oversight</strong> module.<br>
                &bull; Staff will receive a notification of this incoming delivery.
            </div>

            <div class="modal-footer" style="justify-content:space-between;">
                <button type="button" onclick="submitReject()" style="padding:9px 18px;background:#dc3545;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                    <i class="fas fa-times"></i> Reject PO
                </button>
                <div style="display:flex;gap:10px;">
                    <button type="button" onclick="closeFinalize()" style="padding:9px 22px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">
                        Cancel
                    </button>
                    <button type="button" onclick="submitFinalize()" style="padding:9px 22px;background:#002F70;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                        <i class="fas fa-check-double"></i> Finalize
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function switchMainView(viewId, btn) {
    document.querySelectorAll('.main-view').forEach(v => v.classList.remove('active'));
    document.querySelectorAll('.global-tab').forEach(b => b.classList.remove('active'));
    
    document.getElementById(viewId).classList.add('active');
    btn.classList.add('active');

    // Remember the selected tab
    localStorage.setItem('poActiveTab', viewId);
}

document.addEventListener('DOMContentLoaded', function () {
    var m = document.getElementById('finalizeModal');
    if (m && m.parentNode !== document.body) document.body.appendChild(m);

    // Restore the selected tab if one was previously selected
    var savedTab = localStorage.getItem('poActiveTab');
    if (savedTab) {
        var btn = document.querySelector(`.global-tab[onclick*="${savedTab}"]`);
        if (btn && document.getElementById(savedTab)) {
            document.querySelectorAll('.main-view').forEach(v => v.classList.remove('active'));
            document.querySelectorAll('.global-tab').forEach(b => b.classList.remove('active'));
            document.getElementById(savedTab).classList.add('active');
            btn.classList.add('active');
        }
    }
});

function openFinalize(id, type, poNum, product, qty, price, prId) {
    document.getElementById('finPoId').value     = id;
    document.getElementById('finPoType').value   = type;
    document.getElementById('finPoNumber').value = poNum;
    document.getElementById('finProduct').value  = product;
    document.getElementById('finQty').value      = qty > 0 ? qty : '';
    document.getElementById('finPrice').value    = price > 0 ? price : '';
    document.getElementById('finRemarks').value  = '';

    var prRow = document.getElementById('finPrIdRow');
    if (prId && prId.trim() !== '') {
        document.getElementById('finPrId').value = prId;
        prRow.style.display = 'block';
    } else {
        prRow.style.display = 'none';
    }

    // Clear Batch ID for each new open
    var batchEl = document.getElementById('finBatchId');
    if (batchEl) batchEl.value = '';

    computeTotal();
    document.getElementById('finalizeModal').classList.add('open');
    setTimeout(function () {
        var first = document.getElementById('finBatchId') || document.getElementById('finQty');
        if (first) first.focus();
    }, 150);
}

function closeFinalize() {
    document.getElementById('finalizeModal').classList.remove('open');
    document.getElementById('finalizeForm').reset();
    document.getElementById('finTotal').textContent = '0.00';
    document.getElementById('finPrIdRow').style.display = 'none';
}

function computeTotal() {
    var q = parseFloat(document.getElementById('finQty').value)   || 0;
    var p = parseFloat(document.getElementById('finPrice').value) || 0;
    document.getElementById('finTotal').textContent = (q * p).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

document.getElementById('finQty').addEventListener('input', computeTotal);
document.getElementById('finPrice').addEventListener('input', computeTotal);

function submitFinalize() {
    if (!document.getElementById('finQty').value || !document.getElementById('finPrice').value) {
        alert("Quantity and Unit Price are required to finalize.");
        return;
    }
    document.getElementById('formAction').value = 'finalize_po';
    document.getElementById('finalizeForm').submit();
}

function submitReject() {
    const remarks = document.getElementById('finRemarks').value.trim();
    if (remarks === '') {
        alert("Please provide Remarks / Special Instructions before rejecting.");
        document.getElementById('finRemarks').focus();
        return;
    }
    if (confirm("Are you sure you want to reject this Purchase Order?")) {
        document.getElementById('finQty').required = false;
        document.getElementById('finPrice').required = false;
        document.getElementById('formAction').value = 'reject_po';
        document.getElementById('finalizeForm').submit();
    }
}

document.getElementById('finalizeModal').addEventListener('click', function (e) {
    if (e.target === this) closeFinalize();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeFinalize();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
