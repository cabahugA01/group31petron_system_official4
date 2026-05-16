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
try {
    // Add purchase_request_id to stock_requests if missing
    $sr_cols = array_column($pdo->query('SHOW COLUMNS FROM stock_requests')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('purchase_request_id', $sr_cols)) {
        $pdo->exec("ALTER TABLE stock_requests ADD COLUMN purchase_request_id VARCHAR(50) NULL DEFAULT NULL");
    }
    // Expand stock_requests.status enum
    $pdo->exec("ALTER TABLE stock_requests MODIFY COLUMN status ENUM('Pending','Approved','Validated','Forwarded to Admin','Approved PO','Rejected') DEFAULT 'Pending'");
    // Expand purchase_orders.status enum
    $pdo->exec("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('Draft','Pending Approval','Approved','Rejected','Pending','Confirmed','Received','Cancelled','Pending Admin Validation','Official','Approved PO') DEFAULT 'Pending Admin Validation'");
} catch (Exception $ignored) {}

/* ── POST: Admin finalizes PO → Approved PO ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finalize_po') {
    $po_id        = (int)($_POST['po_id']            ?? 0);
    $final_qty    = (float)($_POST['final_quantity']  ?? 0);
    $final_price  = (float)($_POST['final_unit_price'] ?? 0);
    $total_amount = round($final_qty * $final_price, 2);

    if ($po_id > 0 && $final_qty > 0 && $final_price >= 0) {
        try {
            $stmt_po = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ?");
            $stmt_po->execute([$po_id]);
            $po_record = $stmt_po->fetch(PDO::FETCH_ASSOC);
            if (!$po_record) throw new Exception('PO not found.');

            $pdo->beginTransaction();

            // Update PO → Approved PO
            $pdo->prepare("
                UPDATE purchase_orders
                SET status       = 'Approved PO',
                    quantity     = ?,
                    unit_price   = ?,
                    total_amount = ?,
                    approved_by  = ?,
                    approved_at  = NOW(),
                    updated_at   = NOW()
                WHERE id = ?
            ")->execute([$final_qty, $final_price, $total_amount, $me['id'], $po_id]);

            // Audit trail + update linked stock request
            if (!empty($po_record['request_id'])) {
                $pdo->prepare("
                    INSERT INTO stock_request_audit
                        (stock_request_id, action_type, performed_by, performed_by_role,
                         old_status, new_status, notes)
                    VALUES (?, 'PO Finalized', ?, ?, 'Forwarded to Admin', 'Approved PO', ?)
                ")->execute([
                    $po_record['request_id'], $me['id'], $role,
                    "Admin finalized PO: {$po_record['po_number']}. Supplier: Petron Corporation. Qty: {$final_qty}, Unit Price: ₱{$final_price}, Total: ₱{$total_amount}. Admin: {$me['name']}"
                ]);
                $pdo->prepare("UPDATE stock_requests SET status='Approved PO', updated_at=NOW() WHERE id=?")
                    ->execute([$po_record['request_id']]);
            }

            log_activity($pdo, $me['id'], 'Finalize Purchase Order',
                "PO {$po_record['po_number']} finalized as Approved PO. Product: {$po_record['product_name']} | Qty: {$final_qty} | Total: ₱{$total_amount} | Supplier: Petron Corporation | Admin: {$me['name']}");

            $pdo->commit();
            $msg     = "&#10003; PO <strong>{$po_record['po_number']}</strong> finalized as <strong>Approved PO</strong>. Ready to print &amp; coordinate with Petron Corporation.";
            $msgType = 'success';

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg     = "&#10005; Error: " . $e->getMessage();
            $msgType = 'error';
        }
    } else {
        $msg     = "&#10005; Please enter a valid quantity and unit price.";
        $msgType = 'error';
    }
}

/* ── FETCH POs ── */
$pos_pending   = [];
$pos_processed = [];
try {
    $sql = "
        SELECT po.*,
               s.name   AS station_name,
               u.name   AS created_by_name,
               ab.name  AS approved_by_name,
               sr.staff_id,
               sr.item_sku,
               sr.purchase_request_id  AS pr_id,
               sr.requested_quantity   AS sr_requested_qty,
               sr.approved_quantity    AS sr_approved_qty,
               sr.manager_notes        AS sr_manager_notes,
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
            $pos_pending[] = $po;
        } else {
            $pos_processed[] = $po;
        }
    }
} catch (Exception $e) {
    $msg     = "Error loading POs: " . $e->getMessage();
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

/* ── Finalize button ── */
.btn-finalize { background:#002F70; color:#fff; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:5px; transition:background .15s; }
.btn-finalize:hover { background:#001F4F; }

/* ── Audit chain ── */
.audit-chain { display:flex; align-items:center; gap:5px; font-size:11px; flex-wrap:wrap; }
.audit-chain .step { background:#f0f4ff; border:1px solid #c5d3f0; border-radius:4px; padding:2px 7px; color:#002F70; font-weight:600; }
.audit-chain .arrow { color:#adb5bd; }

/* ── PR ID badge ── */
.pr-id-tag { font-size:10px; background:#e6e6fa; color:#5f5f9c; border:1px solid #d8d8ff; border-radius:4px; padding:1px 6px; font-family:monospace; display:inline-block; margin-top:3px; }

/* ── Modal ── */
.modal-overlay { display:none; position:fixed; top:0;left:0;right:0;bottom:0; width:100vw;height:100vh; background:rgba(0,0,0,.6); z-index:9999; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:14px; padding:28px; width:560px; max-width:calc(100vw - 32px); max-height:calc(100vh - 40px); overflow-y:auto; box-shadow:0 24px 80px rgba(0,0,0,.3); position:relative; z-index:10000; animation:modalIn .2s ease; }
@keyframes modalIn { from{opacity:0;transform:scale(.96)} to{opacity:1;transform:scale(1)} }
.modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; padding-bottom:14px; border-bottom:2px solid #e9ecef; }
.modal-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#adb5bd; line-height:1; }
.modal-close:hover { color:#333; }
.field-group { margin-bottom:14px; }
.field-group label { display:block; margin-bottom:5px; font-weight:700; font-size:12px; color:#495057; text-transform:uppercase; letter-spacing:.4px; }
.field-group input { width:100%; padding:9px 11px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; box-sizing:border-box; }
.field-group input[readonly] { background:#f8f9fa; color:#6c757d; }
.field-group input:focus:not([readonly]) { border-color:#002F70; outline:none; box-shadow:0 0 0 3px rgba(0,47,112,.12); }
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

<!-- ══════════════════════════════════════════════════════════
     SECTION 1 — PENDING ADMIN VALIDATION
     ══════════════════════════════════════════════════════════ -->
<div class="po-card">
    <div class="po-card-head">
        <div class="po-card-title">
            <i class="fas fa-clock"></i>
            Pending Admin Validation
            <?php if (count($pos_pending) > 0): ?>
                <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?php echo count($pos_pending); ?></span>
            <?php endif; ?>
        </div>
        <span style="font-size:12px;color:#6c757d;">
            Encode supplier details, set final qty &amp; price, then finalize as <strong>Approved PO</strong>.
        </span>
    </div>
    <div class="po-card-body">
        <?php if (empty($pos_pending)): ?>
            <div style="text-align:center;padding:40px;color:#6c757d;">
                <i class="fas fa-check-circle" style="font-size:2.5em;display:block;margin-bottom:10px;color:#28a745;opacity:.5;"></i>
                <strong>No POs pending validation.</strong><br>
                <span style="font-size:13px;">All purchase requests have been processed.</span>
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
                        <th>Supplier</th>
                        <th>Audit Trail</th>
                        <th>Generated</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pos_pending as $po): ?>
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
                    <td style="font-size:12px;font-weight:700;color:#155724;">Petron Corporation</td>
                    <td>
                        <div class="audit-chain">
                            <span class="step">&#128100; <?php echo htmlspecialchars($po['staff_name'] ?? 'Staff'); ?></span>
                            <span class="arrow">&#8594;</span>
                            <span class="step">&#128101; <?php echo htmlspecialchars($po['manager_name'] ?? $po['created_by_name'] ?? 'Manager'); ?></span>
                            <span class="arrow">&#8594;</span>
                            <span class="step" style="background:#fff3cd;color:#856404;">&#128203; Admin</span>
                        </div>
                    </td>
                    <td style="font-size:12px;color:#6c757d;"><?php echo date('M d, Y H:i', strtotime($po['created_at'])); ?></td>
                    <td>
                        <button class="btn-finalize" onclick="openFinalize(
                            <?php echo (int)$po['id']; ?>,
                            '<?php echo addslashes(htmlspecialchars($po['po_number'])); ?>',
                            '<?php echo addslashes(htmlspecialchars($po['product_name'] ?? '')); ?>',
                            <?php echo (float)($po['quantity'] ?? 0); ?>,
                            <?php echo (float)($po['unit_price'] ?? 0); ?>,
                            '<?php echo addslashes(htmlspecialchars($po['pr_id'] ?? '')); ?>'
                        )">
                            <i class="fas fa-check-double"></i> Finalize
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

<!-- ══════════════════════════════════════════════════════════
     SECTION 2 — FINALIZED PURCHASE ORDERS
     ══════════════════════════════════════════════════════════ -->
<div class="po-card">
    <div class="po-card-head">
        <div class="po-card-title"><i class="fas fa-history"></i> Finalized Purchase Orders</div>
        <span style="font-size:12px;color:#6c757d;"><?php echo count($pos_processed); ?> record(s)</span>
    </div>
    <div class="po-card-body">
        <?php if (empty($pos_processed)): ?>
            <div style="text-align:center;padding:28px;color:#6c757d;">No finalized POs yet.</div>
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
                        <th>Finalized By</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pos_processed as $po):
                    $st     = $po['status'] ?? '';
                    $st_key = strtolower(str_replace([' ', '/'], ['-', '-'], $st));
                    $cls    = 'sbadge sbadge-' . $st_key;
                    $is_printable = in_array($st, ['Approved PO', 'Official', 'Approved']);
                ?>
                <tr>
                    <td><strong style="color:#002F70;"><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                    <td style="font-size:12px;"><?php echo htmlspecialchars($po['station_name'] ?? '—'); ?></td>
                    <td>
                        <?php echo htmlspecialchars($po['product_name'] ?? '—'); ?>
                        <?php if (!empty($po['pr_id'])): ?>
                            <br><span class="pr-id-tag"><?php echo htmlspecialchars($po['pr_id']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;"><?php echo number_format((float)($po['quantity'] ?? 0), 0); ?></td>
                    <td>&#8369;<?php echo number_format((float)($po['unit_price'] ?? 0), 2); ?></td>
                    <td><strong>&#8369;<?php echo number_format((float)($po['total_amount'] ?? 0), 2); ?></strong></td>
                    <td><span class="<?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                    <td style="font-size:12px;"><?php echo htmlspecialchars($po['approved_by_name'] ?? '—'); ?></td>
                    <td style="font-size:12px;color:#6c757d;">
                        <?php echo $po['approved_at']
                            ? date('M d, Y', strtotime($po['approved_at']))
                            : date('M d, Y', strtotime($po['created_at'])); ?>
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

<!-- ══════════════════════════════════════════════════════════
     FINALIZE MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="finalizeModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title">
                <i class="fas fa-check-double" style="color:#28a745;"></i>
                Finalize Purchase Order
            </div>
            <button class="modal-close" onclick="closeFinalize()">&times;</button>
        </div>

        <form method="POST" id="finalizeForm">
            <input type="hidden" name="action" value="finalize_po">
            <input type="hidden" name="po_id" id="finPoId">

            <div class="field-group">
                <label>PO Number</label>
                <input type="text" id="finPoNumber" readonly>
            </div>

            <div class="field-group">
                <label>Product</label>
                <input type="text" id="finProduct" readonly>
            </div>

            <div id="finPrIdRow" class="field-group" style="display:none;">
                <label>Purchase Request ID</label>
                <input type="text" id="finPrId" readonly style="background:#f0f0ff;color:#5f5f9c;font-family:monospace;font-weight:700;">
            </div>

            <div class="field-group">
                <label>Supplier</label>
                <input type="text" value="Petron Corporation" readonly
                       style="background:#f0fdf4;color:#155724;font-weight:700;border-color:#86efac;">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="field-group">
                    <label>Final Quantity <span style="color:red;">*</span></label>
                    <input type="number" name="final_quantity" id="finQty" min="1" step="1" required
                           placeholder="e.g. 20">
                </div>
                <div class="field-group">
                    <label>Unit Price &#8369; <span style="color:red;">*</span></label>
                    <input type="number" name="final_unit_price" id="finPrice" min="0" step="0.01" required
                           placeholder="e.g. 705.90">
                </div>
            </div>

            <div class="total-preview">
                Total Amount: &#8369;<span id="finTotal">0.00</span>
            </div>

            <div class="info-note">
                <i class="fas fa-info-circle"></i> <strong>On Finalize:</strong><br>
                &bull; Status → <strong>Approved PO</strong> (ready for printing)<br>
                &bull; Supplier: <strong>Petron Corporation</strong> (fixed — no delivery schedule needed)<br>
                &bull; Audit trail logged: Admin name, action, timestamp<br>
                &bull; Print the official PO document for supplier coordination
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeFinalize()"
                        style="padding:9px 22px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="padding:9px 22px;background:#002F70;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">
                    <i class="fas fa-check-double"></i> Finalize as Approved PO
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Move modal to body to avoid overflow clipping
document.addEventListener('DOMContentLoaded', function () {
    var m = document.getElementById('finalizeModal');
    if (m && m.parentNode !== document.body) document.body.appendChild(m);
});

function openFinalize(id, poNum, product, qty, price, prId) {
    document.getElementById('finPoId').value     = id;
    document.getElementById('finPoNumber').value = poNum;
    document.getElementById('finProduct').value  = product;
    document.getElementById('finQty').value      = qty > 0 ? qty : '';
    document.getElementById('finPrice').value    = price > 0 ? price : '';

    // Show PR ID row if available
    var prRow = document.getElementById('finPrIdRow');
    if (prId && prId.trim() !== '') {
        document.getElementById('finPrId').value = prId;
        prRow.style.display = 'block';
    } else {
        prRow.style.display = 'none';
    }

    computeTotal();
    document.getElementById('finalizeModal').classList.add('open');
    // Focus first editable field
    setTimeout(function () {
        var qtyEl = document.getElementById('finQty');
        if (!qtyEl.value) qtyEl.focus();
        else document.getElementById('finPrice').focus();
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

document.getElementById('finalizeModal').addEventListener('click', function (e) {
    if (e.target === this) closeFinalize();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeFinalize();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
