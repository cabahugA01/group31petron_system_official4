<?php
$page_id = 'mgr_inv_po_gen';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/manager_fuel_config.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Only managers and above can access this module
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

// Ensure required columns exist
foreach ([
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS supplier_name VARCHAR(200) NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS expected_delivery DATE NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS notes TEXT NULL",
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS type VARCHAR(20) DEFAULT 'merch'",
] as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) {}
}

$msg = '';
if (isset($_SESSION['success'])) {
    $msg = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $msg = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Finalize PO: set supplier, qty, price, expected delivery → status = 'Pending Delivery'
    if ($action === 'finalize_po') {
        $po_id        = (int)($_POST['po_id'] ?? 0);
        $supplier     = trim($_POST['supplier_name'] ?? '');
        $quantity     = (float)($_POST['quantity'] ?? 0);
        $unit_price   = (float)($_POST['unit_price'] ?? 0);
        $exp_delivery = trim($_POST['expected_delivery'] ?? '');
        $notes        = trim($_POST['notes'] ?? '');
        $total        = $quantity * $unit_price;

        try {
            // Verify PO belongs to this station and is still Pending
            $stmt = $pdo->prepare("SELECT id, po_number, status FROM purchase_orders WHERE id = ? AND station_id = ?");
            $stmt->execute([$po_id, $station_id]);
            $po = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$po) {
                throw new Exception('Purchase Order not found.');
            }
            if (!in_array($po['status'], ['Pending', 'pending', 'pending_supplier'])) {
                throw new Exception('Only Pending orders can be finalized.');
            }
            if (empty($supplier)) {
                throw new Exception('Supplier name is required.');
            }
            if ($quantity <= 0) {
                throw new Exception('Quantity must be greater than zero.');
            }
            if ($unit_price <= 0) {
                throw new Exception('Unit price must be greater than zero.');
            }
            if (empty($exp_delivery)) {
                throw new Exception('Expected delivery date is required.');
            }

            $stmt = $pdo->prepare("
                UPDATE purchase_orders
                SET supplier_name = ?, quantity = ?, unit_price = ?, total_amount = ?,
                    expected_delivery = ?, notes = ?, status = 'Pending Delivery',
                    admin_finalized = 0, updated_at = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$supplier, $quantity, $unit_price, $total, $exp_delivery, $notes, $po_id, $station_id]);

            log_activity($pdo, $me['id'], 'Finalize PO', "Finalized PO #{$po['po_number']} — Supplier: $supplier, Qty: $quantity, Unit Price: ₱$unit_price, Total: ₱$total, Expected: $exp_delivery");
            $_SESSION['success'] = "Purchase Order #{$po['po_number']} finalized successfully. Status set to Pending Delivery.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error finalizing PO: ' . $e->getMessage();
        }
        header('Location: manager_purchase_orders.php');
        exit;
    }
}

// Fetch Purchase Orders from purchase_orders table (merch, scoped to station)
$pos = [];
try {
    $stmt = $pdo->prepare("
        SELECT po.*,
               u.name AS created_by_name,
               sr.item_name AS request_item_name,
               sr.item_sku
        FROM purchase_orders po
        LEFT JOIN users u ON po.created_by = u.id
        LEFT JOIN stock_requests sr ON po.request_id = sr.id
        WHERE po.station_id = ?
          AND po.type = 'merch'
        ORDER BY
            CASE po.status
                WHEN 'Pending' THEN 1
                WHEN 'pending' THEN 1
                WHEN 'pending_supplier' THEN 1
                WHEN 'Pending Delivery' THEN 2
                WHEN 'Approved' THEN 3
                ELSE 4
            END,
            po.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pos = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Page Layout ─────────────────────────────────────────── */
.mgr-po-wrap {
    max-width: 1400px;
    margin: 0 auto;
    padding: 10px;
}

.page-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 6px;
}

.page-head h1 {
    margin: 0 0 2px;
    font-size: 1.4rem;
    font-weight: 700;
    color: #002F70;
}

.page-head .sub {
    font-size: 0.8rem;
    color: #6c757d;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ── Alert ───────────────────────────────────────────────── */
.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 0.9rem;
    font-weight: 500;
}
.alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

/* ── Table ───────────────────────────────────────────────── */
.po-table-wrap {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.07);
    overflow-x: auto;
}

.po-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}

.po-table thead th {
    background: #002F70;
    color: #fff;
    padding: 12px 14px;
    text-align: left;
    font-weight: 600;
    white-space: nowrap;
}

.po-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.15s;
}

.po-table tbody tr:hover {
    background: #f5f8ff;
}

.po-table tbody td {
    padding: 11px 14px;
    vertical-align: middle;
    color: #333;
}

/* ── Status Badges — plain text, no background color ────── */
.status-badge {
    display: inline-block;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    color: #333;
}

.badge-pending          { color: #002F70; }
.badge-pending-delivery { color: #002F70; }
.badge-approved         { color: #28a745; }
.badge-rejected         { color: #dc3545; }
.badge-other            { color: #6c757d; }

/* ── Action Buttons ──────────────────────────────────────── */
.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.82rem;
    font-weight: 600;
    text-decoration: none;
    transition: opacity 0.2s;
    white-space: nowrap;
}
.btn-action:hover { opacity: 0.85; }

.btn-finalize { background: #002F70; color: #fff; }
.btn-view     { background: #6c757d; color: #fff; }

/* ── Empty State ─────────────────────────────────────────── */
.empty-state {
    text-align: center;
    padding: 70px 20px;
    color: #666;
}
.empty-state i {
    font-size: 3.5rem;
    color: #002F70;
    margin-bottom: 18px;
    display: block;
    opacity: 0.5;
}
.empty-state h3 {
    font-size: 1.2rem;
    font-weight: 700;
    color: #333;
    margin: 0 0 8px;
}
.empty-state p {
    font-size: 0.9rem;
    max-width: 420px;
    margin: 0 auto;
    line-height: 1.6;
}

/* ── Modal ───────────────────────────────────────────────── */
.modal-overlay {
    display: none;
    position: fixed;
    z-index: 1050;
    inset: 0;
    background: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }

.modal-box {
    background: #fff;
    border-radius: 12px;
    width: 90%;
    max-width: 540px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 24px;
    border-bottom: 1px solid #e9ecef;
}
.modal-header h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: #002F70;
}
.modal-close {
    background: none;
    border: none;
    font-size: 1.4rem;
    cursor: pointer;
    color: #888;
    line-height: 1;
    padding: 0 4px;
}
.modal-close:hover { color: #333; }

.modal-body { padding: 20px 24px; }

.form-group { margin-bottom: 16px; }
.form-label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    font-size: 0.88rem;
    color: #333;
}
.form-label .req { color: #dc3545; }
.form-control {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 0.9rem;
    box-sizing: border-box;
    transition: border-color 0.2s;
}
.form-control:focus {
    outline: none;
    border-color: #002F70;
    box-shadow: 0 0 0 3px rgba(0,47,112,0.1);
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 16px 24px;
    border-top: 1px solid #e9ecef;
}

.btn-cancel-modal {
    padding: 9px 20px;
    background: #e9ecef;
    color: #333;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.9rem;
}
.btn-submit-finalize {
    padding: 9px 20px;
    background: #002F70;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-submit-finalize:hover { background: #001f50; }

/* ── View Detail Modal ───────────────────────────────────── */
.detail-row {
    display: flex;
    gap: 8px;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 0.9rem;
}
.detail-row:last-child { border-bottom: none; }
.detail-label {
    font-weight: 600;
    color: #555;
    min-width: 170px;
    flex-shrink: 0;
}
.detail-value { color: #222; }
</style>

<div class="mgr-po-wrap">

    <!-- Page Header -->
    <div class="page-head">
        <div>
            <h1 class="h1">Purchase Orders</h1>
            <div class="sub">Auto-generated from approved stock requests — Finalize supplier, qty, price &amp; expected delivery</div>
        </div>
    </div>

    <!-- Flash Message -->
    <?php if ($msg): ?>
    <div class="alert <?php echo (strpos($msg, '❌') !== false || stripos($msg, 'error') !== false) ? 'alert-error' : 'alert-success'; ?>">
        <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endif; ?>

    <!-- PO Table -->
    <div class="po-table-wrap">
        <?php if (count($pos) > 0): ?>
        <table class="po-table">
            <thead>
                <tr>
                    <th>PO Number</th>
                    <th>Product</th>
                    <th>Supplier</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                    <th>Expected Delivery</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pos as $po):
                    // Normalise status for display
                    $raw_status = $po['status'] ?? '';
                    $display_status = $raw_status;
                    $badge_class = 'badge-other';
                    $raw_lower = strtolower(trim($raw_status));

                    if (in_array($raw_lower, ['pending', 'pending_supplier', 'pending supplier'])) {
                        $display_status = 'Pending';
                        $badge_class    = 'badge-pending';
                    } elseif (in_array($raw_lower, ['pending delivery', 'pending_delivery'])) {
                        $display_status = 'Pending Delivery';
                        $badge_class    = 'badge-pending';
                    } elseif (str_contains($raw_lower, 'pending admin') || str_contains($raw_lower, 'pending validation') || str_contains($raw_lower, 'awaiting')) {
                        $display_status = 'Pending Admin';
                        $badge_class    = 'badge-pending';
                    } elseif (in_array($raw_lower, ['approved', 'approved po', 'admin finalized', 'finalized', 'confirmed', 'validated', 'verified', 'complete', 'completed'])) {
                        $display_status = str_contains($raw_lower, 'approved po') ? 'Approved PO' : 'Approved';
                        $badge_class    = 'badge-approved';
                    } elseif (in_array($raw_lower, ['rejected', 'cancelled', 'returned'])) {
                        $display_status = 'Rejected';
                        $badge_class    = 'badge-rejected';
                    }

                    $is_pending = in_array($raw_lower, ['pending', 'pending_supplier', 'pending supplier']);

                    $product_display = htmlspecialchars($po['product_name'] ?? '—');
                    $supplier_display = !empty($po['supplier_name']) ? htmlspecialchars($po['supplier_name']) : '—';
                    $qty_display = isset($po['quantity']) ? number_format((float)$po['quantity'], 0) : '—';
                    $unit_price_display = isset($po['unit_price']) && $po['unit_price'] > 0 ? '₱' . number_format((float)$po['unit_price'], 2) : '—';
                    $total_display = isset($po['total_amount']) && $po['total_amount'] > 0 ? '₱' . number_format((float)$po['total_amount'], 2) : '—';
                    $exp_delivery_display = !empty($po['expected_delivery']) ? date('M j, Y', strtotime($po['expected_delivery'])) : '—';
                    $created_display = !empty($po['created_at']) ? date('M j, Y', strtotime($po['created_at'])) : '—';
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($po['po_number'] ?? '—'); ?></strong></td>
                    <td><?php echo $product_display; ?></td>
                    <td><?php echo $supplier_display; ?></td>
                    <td><?php echo $qty_display; ?></td>
                    <td><?php echo $unit_price_display; ?></td>
                    <td><?php echo $total_display; ?></td>
                    <td><?php echo $exp_delivery_display; ?></td>
                    <td><span class="status-badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($display_status); ?></span></td>
                    <td><?php echo $created_display; ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ($is_pending): ?>
                        <button type="button"
                                class="btn-action btn-finalize"
                                onclick="openFinalizeModal(<?php
                                    echo htmlspecialchars(json_encode([
                                        'id'               => $po['id'],
                                        'po_number'        => $po['po_number'] ?? '',
                                        'product_name'     => $po['product_name'] ?? '',
                                        'supplier_name'    => $po['supplier_name'] ?? '',
                                        'quantity'         => $po['quantity'] ?? '',
                                        'unit_price'       => $po['unit_price'] ?? '',
                                        'expected_delivery'=> $po['expected_delivery'] ?? '',
                                        'notes'            => $po['notes'] ?? '',
                                    ]), ENT_QUOTES);
                                ?>)">
                            <i class="fas fa-edit"></i> Finalize
                        </button>
                        <?php endif; ?>
                        <button type="button"
                                class="btn-action btn-view"
                                onclick="openViewModal(<?php
                                    echo htmlspecialchars(json_encode([
                                        'po_number'        => $po['po_number'] ?? '',
                                        'product_name'     => $po['product_name'] ?? '',
                                        'supplier_name'    => $po['supplier_name'] ?? '',
                                        'quantity'         => $po['quantity'] ?? '',
                                        'unit_price'       => $po['unit_price'] ?? '',
                                        'total_amount'     => $po['total_amount'] ?? '',
                                        'expected_delivery'=> $po['expected_delivery'] ?? '',
                                        'status'           => $display_status,
                                        'notes'            => $po['notes'] ?? '',
                                        'created_by_name'  => $po['created_by_name'] ?? '',
                                        'created_at'       => $po['created_at'] ?? '',
                                    ]), ENT_QUOTES);
                                ?>)">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-clipboard-list"></i>
            <h3>No Purchase Orders Found</h3>
            <p>Purchase orders are automatically generated when stock requests are approved.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Finalize PO Modal ──────────────────────────────────── -->
<div id="finalizeModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="finalizeModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="finalizeModalTitle"><i class="fas fa-edit"></i> Finalize Purchase Order</h2>
            <button class="modal-close" onclick="closeModal('finalizeModal')" aria-label="Close">&times;</button>
        </div>
        <form method="post" action="manager_purchase_orders.php" id="finalizeForm">
            <input type="hidden" name="action" value="finalize_po">
            <input type="hidden" name="po_id" id="finalize_po_id">
            <div class="modal-body">
                <p style="font-size:0.85rem;color:#555;margin:0 0 16px;">
                    PO: <strong id="finalize_po_number"></strong> &mdash; Product: <strong id="finalize_product_name"></strong>
                </p>

                <div class="form-group">
                    <label class="form-label" for="finalize_supplier">Supplier Name <span class="req">*</span></label>
                    <input type="text" id="finalize_supplier" name="supplier_name" class="form-control"
                           placeholder="e.g. Petron Corporation" required maxlength="200">
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label" for="finalize_qty">Confirmed Quantity <span class="req">*</span></label>
                        <input type="number" id="finalize_qty" name="quantity" class="form-control"
                               min="1" step="1" required placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="finalize_unit_price">Unit Price (₱) <span class="req">*</span></label>
                        <input type="number" id="finalize_unit_price" name="unit_price" class="form-control"
                               min="0.01" step="0.01" required placeholder="0.00">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="finalize_delivery">Expected Delivery Date <span class="req">*</span></label>
                    <input type="date" id="finalize_delivery" name="expected_delivery" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="finalize_notes">Notes <span style="font-weight:400;color:#888;">(optional)</span></label>
                    <textarea id="finalize_notes" name="notes" class="form-control" rows="3"
                              placeholder="Any additional notes for this PO..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel-modal" onclick="closeModal('finalizeModal')">Cancel</button>
                <button type="submit" class="btn-submit-finalize">
                    <i class="fas fa-check"></i> Finalize PO
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── View PO Modal ──────────────────────────────────────── -->
<div id="viewModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="viewModalTitle"><i class="fas fa-eye"></i> Purchase Order Details</h2>
            <button class="modal-close" onclick="closeModal('viewModal')" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <!-- Populated by JS -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel-modal" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
// ── Modal helpers ─────────────────────────────────────────
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Close on backdrop click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal(overlay.id);
    });
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(function(m) {
            m.classList.remove('active');
        });
    }
});

// ── Finalize Modal ────────────────────────────────────────
function openFinalizeModal(data) {
    document.getElementById('finalize_po_id').value        = data.id;
    document.getElementById('finalize_po_number').textContent  = data.po_number;
    document.getElementById('finalize_product_name').textContent = data.product_name;
    document.getElementById('finalize_supplier').value     = data.supplier_name || '';
    document.getElementById('finalize_qty').value          = data.quantity || '';
    document.getElementById('finalize_unit_price').value   = data.unit_price || '';
    document.getElementById('finalize_delivery').value     = data.expected_delivery || '';
    document.getElementById('finalize_notes').value        = data.notes || '';

    // Set min date to today
    var today = new Date().toISOString().split('T')[0];
    document.getElementById('finalize_delivery').min = today;

    document.getElementById('finalizeModal').classList.add('active');
    document.getElementById('finalize_supplier').focus();
}

// ── View Modal ────────────────────────────────────────────
function openViewModal(data) {
    var fmt = function(v) { return v ? v : '—'; };
    var fmtMoney = function(v) {
        if (!v || parseFloat(v) === 0) return '—';
        return '₱' + parseFloat(v).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    };
    var fmtDate = function(v) {
        if (!v) return '—';
        var d = new Date(v);
        if (isNaN(d)) return v;
        return d.toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'});
    };

    var rows = [
        ['PO Number',          fmt(data.po_number)],
        ['Product',            fmt(data.product_name)],
        ['Supplier',           fmt(data.supplier_name)],
        ['Quantity',           data.quantity ? Number(data.quantity).toLocaleString() : '—'],
        ['Unit Price',         fmtMoney(data.unit_price)],
        ['Total Amount',       fmtMoney(data.total_amount)],
        ['Expected Delivery',  fmtDate(data.expected_delivery)],
        ['Status',             fmt(data.status)],
        ['Notes',              fmt(data.notes)],
        ['Created By',         fmt(data.created_by_name)],
        ['Created At',         fmtDate(data.created_at)],
    ];

    var html = rows.map(function(r) {
        return '<div class="detail-row"><span class="detail-label">' + r[0] + '</span><span class="detail-value">' + escHtml(String(r[1])) + '</span></div>';
    }).join('');

    document.getElementById('viewModalBody').innerHTML = html;
    document.getElementById('viewModal').classList.add('active');
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
