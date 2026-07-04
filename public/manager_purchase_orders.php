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
    "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL COMMENT 'Batch ID assigned by Manager per delivery batch'",
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
        $batch_id     = trim($_POST['batch_id'] ?? '');
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
                    expected_delivery = ?, notes = ?, batch_id = ?,
                    status = 'Pending Delivery',
                    admin_finalized = 0, updated_at = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$supplier, $quantity, $unit_price, $total, $exp_delivery, $notes, $batch_id ?: null, $po_id, $station_id]);

            log_activity($pdo, $me['id'], 'Finalize PO', "Finalized PO #{$po['po_number']} — Supplier: $supplier, Qty: $quantity, Unit Price: ₱$unit_price, Total: ₱$total, Expected: $exp_delivery" . ($batch_id ? ", Batch: $batch_id" : ''));
            $_SESSION['success'] = "Purchase Order #{$po['po_number']} finalized successfully. Status set to Pending Delivery.";
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error finalizing PO: ' . $e->getMessage();
        }
        header('Location: manager_purchase_orders.php');
        exit;
    }
}

// Fetch from stock_requests + fuel_stock_requests as primary source (same as stock review page)
// LEFT JOIN purchase_orders/fuel_purchase_orders for PO details when manager has approved
$pos = [];
try {
    // Merchandise: stock_requests LEFT JOIN purchase_orders
    $stmt = $pdo->prepare("
        SELECT 
            'merchandise' AS po_type,
            COALESCE(po.id, 0) AS id,
            COALESCE(po.po_number, CONCAT('REQ-', LPAD(sr.id,4,'0'))) AS po_number,
            sr.item_name AS product_name,
            po.batch_id,
            COALESCE(sup.name, po.supplier_name) AS supplier_name,
            COALESCE(po.quantity, sr.approved_quantity, sr.requested_quantity) AS quantity,
            po.unit_price,
            po.total_amount,
            po.expected_delivery_date AS expected_delivery,
            COALESCE(po.status, sr.status) AS status,
            sr.created_at,
            po.remarks AS notes,
            COALESCE(u_mgr.name,
                CONCAT(COALESCE(u_mgr.first_name,''), ' ', COALESCE(u_mgr.last_name,'')),
                COALESCE(u_staff.name,
                    CONCAT(COALESCE(u_staff.first_name,''), ' ', COALESCE(u_staff.last_name,'')),
                '—')
            ) AS created_by_name
        FROM stock_requests sr
        LEFT JOIN purchase_orders po ON po.request_id = sr.id AND po.type = 'merch'
        LEFT JOIN users u_staff ON sr.staff_id = u_staff.id
        LEFT JOIN users u_mgr   ON sr.manager_id = u_mgr.id
        LEFT JOIN suppliers sup  ON po.supplier_id = sup.id
        WHERE sr.station_id = ?
          AND LOWER(COALESCE(sr.item_category,'')) != 'fuel'
        ORDER BY sr.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $pos = array_merge($pos, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Fuel: fuel_stock_requests LEFT JOIN fuel_purchase_orders
    $stmt2 = $pdo->prepare("
        SELECT 
            'fuel' AS po_type,
            COALESCE(fpo.id, 0) AS id,
            COALESCE(fpo.po_number, CONCAT('FSR-', LPAD(fsr.id,4,'0'))) AS po_number,
            COALESCE(fsr.fuel_type, COALESCE(ft.name,'Fuel')) AS product_name,
            fpo.batch_id,
            'Petron Corporation' AS supplier_name,
            COALESCE(fpo.volume, fsr.requested_liters) AS quantity,
            fpo.unit_price,
            fpo.total_amount,
            fpo.expected_delivery_date AS expected_delivery,
            COALESCE(fpo.status, fsr.status) AS status,
            fsr.created_at,
            fpo.notes,
            COALESCE(u_staff.name,
                CONCAT(COALESCE(u_staff.first_name,''), ' ', COALESCE(u_staff.last_name,'')),
            '—') AS created_by_name
        FROM fuel_stock_requests fsr
        LEFT JOIN fuel_purchase_orders fpo ON fpo.station_id = fsr.station_id
            AND fpo.created_by = fsr.staff_id
            AND DATE(fpo.created_at) = DATE(fsr.created_at)
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN users u_staff ON fsr.staff_id = u_staff.id
        WHERE fsr.station_id = ?
        ORDER BY fsr.created_at DESC
        LIMIT 200
    ");
    $stmt2->execute([$station_id]);
    $pos = array_merge($pos, $stmt2->fetchAll(PDO::FETCH_ASSOC));

    // Sort combined list by created_at desc
    usort($pos, fn($a, $b) => strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0));
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
    overflow-x: hidden;
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
    text-align: center;
    font-weight: 600;
    }

.po-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.15s;
}

.po-table tbody tr:hover {
    background: #eff6ff;
}

.po-table tbody td {
    padding: 11px 14px;
    vertical-align: middle;
    color: #333;
    text-align: center;
}

/* ── Type Badges ── */
.type-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
}
.type-fuel {
    background: #fff8e1;
    color: #b78103;
    border: 1px solid #ffe082;
}
.type-merchandise {
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #a5d6a7;
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
    flex-shrink: 0;
}
.detail-value { color: #222; }
</style>

<div class="mgr-po-wrap">

    <!-- Page Header -->
    <div class="page-head">
        <div>
            <h1 class="h1">Purchase Orders</h1>
            <div class="sub">VIEW THE HISTORY OF PURCHASE ORDERS APPROVED BY THE ADMIN, INCLUDING PROPOSED MANAGER PRICES FOR BOTH FUEL AND MERCHANDISE.</div>
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
                    <th>Type</th>
                    <th>Product</th>
                    <th>Batch ID</th>
                    <th>Supplier</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                    <th>Expected Delivery</th>
                    <th>Status</th>
                    <th>Created</th>
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
                    if ($po['po_type'] === 'fuel') {
                        $qty_display = isset($po['quantity']) ? number_format((float)$po['quantity'], 2) . ' L' : '—';
                    } else {
                        $qty_display = isset($po['quantity']) ? number_format((float)$po['quantity'], 0) . ' pcs' : '—';
                    }
                    $unit_price_display = isset($po['unit_price']) && $po['unit_price'] > 0 ? '₱' . number_format((float)$po['unit_price'], 2) : '—';
                    $total_display = isset($po['total_amount']) && $po['total_amount'] > 0 ? '₱' . number_format((float)$po['total_amount'], 2) : '—';
                    $exp_delivery_display = !empty($po['expected_delivery']) ? date('M j, Y', strtotime($po['expected_delivery'])) : '—';
                    $created_display = !empty($po['created_at']) ? date('M j, Y', strtotime($po['created_at'])) : '—';
                ?>
                <tr>
                    <td>
                        <a href="#" style="font-weight:700; text-decoration:none; color:#002F70;" onclick="openViewModal(<?php
                            echo htmlspecialchars(json_encode([
                                'po_number'        => $po['po_number'] ?? '',
                                'po_type'          => ucfirst($po['po_type']),
                                'product_name'     => $po['product_name'] ?? '',
                                'supplier_name'    => $po['supplier_name'] ?? '',
                                'quantity'         => $qty_display,
                                'unit_price'       => $po['unit_price'] ?? '',
                                'total_amount'     => $po['total_amount'] ?? '',
                                'expected_delivery'=> $po['expected_delivery'] ?? '',
                                'status'           => $display_status,
                                'notes'            => $po['notes'] ?? '',
                                'created_by_name'  => $po['created_by_name'] ?? '',
                                'created_at'       => $po['created_at'] ?? '',
                            ]), ENT_QUOTES);
                        ?>); return false;">
                            <?php echo htmlspecialchars($po['po_number'] ?? '—'); ?>
                        </a>
                    </td>
                    <td><span class="type-badge <?php echo $po['po_type'] === 'fuel' ? 'type-fuel' : 'type-merchandise'; ?>"><?php echo ucfirst($po['po_type']); ?></span></td>
                    <td><?php echo $product_display; ?></td>
                    <td>
                        <?php if (!empty($po['batch_id'])): ?>
                        <span style="font-family:monospace;font-size:12px;background:#e8f4fd;color:#002F70;padding:2px 8px;border-radius:4px;font-weight:700;"><?php echo htmlspecialchars($po['batch_id']); ?></span>
                        <?php else: ?><span style="color:#adb5bd;">—</span><?php endif; ?>
                    </td>
                    <td><?php echo $supplier_display; ?></td>
                    <td><?php echo $qty_display; ?></td>
                    <td><?php echo $unit_price_display; ?></td>
                    <td><?php echo $total_display; ?></td>
                    <td><?php echo $exp_delivery_display; ?></td>
                    <td><span class="status-badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($display_status); ?></span></td>
                    <td><?php echo $created_display; ?></td>
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
        ['PO Type',            fmt(data.po_type)],
        ['Product',            fmt(data.product_name)],
        ['Supplier',           fmt(data.supplier_name)],
        ['Quantity',           fmt(data.quantity)],
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
