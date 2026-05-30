<?php
$page_id = 'staff_delivery_history';
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

/* ── Flash messages from redirect ── */
$flash_messages = [
    'received'     => '&#10003; Delivery received and submitted for Manager Approval. View it below.',
    'discrepancy'  => '&#9888; Variance detected! Delivery was flagged as Discrepancy. Please review below.',
    'manual_saved' => '&#10003; Manual delivery saved successfully. Status: Pending Manager Approval.',
    'resubmitted'  => '&#10003; Delivery resubmitted successfully and is now pending Manager Approval.',
];
$msg_key  = trim($_GET['msg'] ?? '');
$msg      = $flash_messages[$msg_key] ?? '';
$msg_type = trim($_GET['type'] ?? 'success');

/* ── Filters from GET ── */
$filter_status   = trim($_GET['status']   ?? '');
$filter_supplier = trim($_GET['supplier'] ?? '');
$filter_start    = trim($_GET['start']    ?? date('Y-m-d', strtotime('-30 days')));
$filter_end      = trim($_GET['end']      ?? date('Y-m-d'));

/* ── Fetch deliveries ── */
$deliveries = [];
$counts = [
    'Pending Manager Approval' => 0,
    'Confirmed'                => 0,
    'Discrepancy'              => 0,
    'Closed'                   => 0,
];

try {
    /* Bootstrap table */
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
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN remarks TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN dr_number VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_id INT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_action_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE deliveries_oversight ADD COLUMN manager_notes TEXT DEFAULT NULL"); } catch (Exception $e) {}

    $where  = "WHERE do2.station_id = ? AND do2.delivery_type = 'merchandise' AND do2.status != 'Expected Delivery' AND do2.delivery_date BETWEEN ? AND ?";
    $params = [$station_id, $filter_start, $filter_end];

    /* Allow all staff in the station to see the delivery history for that station */
    // if ($role === 'staff') {
    //     $where   .= " AND do2.encoded_by = ?";
    //     $params[] = $me['id'];
    // }

    /* Map filter value to DB values */
    if ($filter_status !== '') {
        if ($filter_status === 'Pending Manager Approval') {
            $where .= " AND do2.status IN ('Pending Manager Approval','Pending Manager Confirmation')";
        } elseif ($filter_status === 'Confirmed') {
            $where .= " AND do2.status IN ('Confirmed','Approved','Adjusted')";
        } elseif ($filter_status === 'Discrepancy') {
            $where .= " AND do2.status IN ('Discrepancy','Pending Resolution','Awaiting Replacement','Returned to Supplier')";
        } elseif ($filter_status === 'Closed') {
            $where .= " AND do2.status = 'Closed'";
        }
    }
    if ($filter_supplier !== '') {
        $where   .= " AND do2.supplier LIKE ?";
        $params[] = '%' . $filter_supplier . '%';
    }

    $stmt = $pdo->prepare("
        SELECT do2.*, u_enc.name AS encoded_by_name, u_act.name AS action_by_name
        FROM deliveries_oversight do2
        LEFT JOIN users u_enc ON do2.encoded_by  = u_enc.id
        LEFT JOIN users u_act ON do2.manager_id  = u_act.id
        {$where}
        ORDER BY
            FIELD(do2.status,
                'Discrepancy',
                'Pending Manager Approval',
                'Pending Manager Confirmation',
                'Confirmed',
                'Closed'
            ),
            do2.delivery_date DESC
    ");
    $stmt->execute($params);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deliveries as $r) {
        $s = $r['status'];
        if (in_array($s, ['Pending Manager Approval', 'Pending Manager Confirmation'])) {
            $counts['Pending Manager Approval']++;
        } elseif (isset($counts[$s])) {
            $counts[$s]++;
        }
    }

} catch (Exception $e) {
    $msg      = 'Error loading deliveries: ' . $e->getMessage();
    $msg_type = 'error';
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.del-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:24px; }
.del-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; }
.del-card-title { font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.del-card-body  { padding:20px; }

/* Status badges */
.badge-pending  { background:#fff3cd; color:#856404; border:1px solid #ffc107; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; white-space:nowrap; }
.badge-approved { background:#d4edda; color:#155724; border:1px solid #28a745; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; white-space:nowrap; }
.badge-rejected { background:#f8d7da; color:#721c24; border:1px solid #dc3545; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; white-space:nowrap; }
.badge-closed   { background:#e2e3e5; color:#383d41; border:1px solid #6c757d; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; white-space:nowrap; }

/* Summary cards */
.summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:24px; }
.summary-card { background:#fff; border-radius:10px; padding:16px 20px; box-shadow:0 2px 6px rgba(0,0,0,.05); border:1px solid #e9ecef; display:flex; flex-direction:column; gap:4px; }
.summary-card .sc-num   { font-size:2rem; font-weight:700; line-height:1; }
.summary-card .sc-label { font-size:12px; color:#6c757d; font-weight:500; }
.sc-pending  .sc-num { color:#856404; }
.sc-approved .sc-num { color:#155724; }
.sc-rejected .sc-num { color:#721c24; }
.sc-closed   .sc-num { color:#383d41; }

/* Filter bar */
.filter-bar { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
.filter-bar .form-group { display:flex; flex-direction:column; gap:4px; }
.filter-bar label { font-size:12px; font-weight:600; color:#495057; }
.filter-bar input, .filter-bar select { border:1px solid #ced4da; border-radius:6px; padding:7px 10px; font-size:13px; }
.filter-bar input:focus, .filter-bar select:focus { border-color:#002F70; outline:0; box-shadow:0 0 0 .15rem rgba(0,47,112,.15); }

/* Table */
.del-table { width:100%; border-collapse:collapse; font-size:13px; }
.del-table th { background:#f8f9fa; color:#495057; font-weight:700; padding:10px 12px; text-align:left; border-bottom:2px solid #dee2e6; white-space:nowrap; }
.del-table td { padding:10px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.del-table tr:hover td { background:#f8f9fa; }
.del-table tr.row-rejected td { background:#fff8f8; }
.del-table tr.row-rejected:hover td { background:#ffeaea; }

/* Buttons */
.btn-sm-view     { background:#002F70; color:#fff; border:none; padding:5px 12px; border-radius:5px; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.btn-sm-resubmit { background:#fd7e14; color:#fff; border:none; padding:5px 12px; border-radius:5px; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }

/* Alerts */
.alert-success-del { background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:8px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:flex-start; gap:10px; }
.alert-error-del   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; border-radius:8px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:flex-start; gap:10px; }

/* Rejection note inline in table */
.rejection-note { background:#fff3cd; border:1px solid #ffc107; border-radius:5px; padding:3px 8px; font-size:11px; color:#856404; margin-top:4px; display:flex; align-items:flex-start; gap:5px; line-height:1.4; }

/* Modal */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9000; align-items:center; justify-content:center; }
.modal-overlay.show { display:flex; }
.modal-box { background:#fff; border-radius:12px; padding:28px; max-width:540px; width:90%; box-shadow:0 8px 32px rgba(0,0,0,.2); max-height:90vh; overflow-y:auto; }
.modal-title { font-size:1.1rem; font-weight:700; color:#002F70; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.modal-actions { display:flex; gap:10px; margin-top:20px; justify-content:flex-end; flex-wrap:wrap; }
.btn-cancel-del { background:#6c757d; color:#fff; border:none; padding:10px 18px; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; }
.btn-resubmit-modal { background:#fd7e14; color:#fff; border:none; padding:10px 20px; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:7px; }

/* Detail table in modal */
.detail-table { width:100%; border-collapse:collapse; font-size:13px; }
.detail-table tr td:first-child { color:#6c757d; width:140px; padding:7px 0; vertical-align:top; font-weight:500; }
.detail-table tr td:last-child  { padding:7px 0; color:#212529; }
.detail-table tr + tr td { border-top:1px solid #f0f0f0; }

/* Rejection banner in modal */
.rejection-banner { background:#f8d7da; border:1px solid #f5c6cb; border-radius:8px; padding:12px 14px; margin-bottom:16px; display:flex; align-items:flex-start; gap:10px; font-size:13px; color:#721c24; }

@media (max-width:640px) {
    .summary-grid { grid-template-columns:1fr 1fr; }
    .del-table { font-size:12px; }
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-history"></i> Merchandise Delivery History</h1>
        <div class="sub">All merchandise deliveries for this station &mdash; Pending, Approved, Discrepancy, and Closed records.</div>
    </div>
    <div class="header-actions"></div>
</div>

<?php if ($msg): ?>
<div class="alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>-del">
    <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <div><?php echo $msg; ?></div>
</div>
<?php endif; ?>

<!-- Filters + Table -->
<div class="del-card">
    <div class="del-card-head">
        <div class="del-card-title"><i class="fas fa-boxes"></i> Merchandise Delivery Records</div>
        <span style="font-size:12px;color:#6c757d;"><?php echo count($deliveries); ?> record(s) &mdash; <span style="color:#002F70;font-weight:600;">Merchandise Only</span></span>
    </div>
    <div class="del-card-body">

        <form method="GET">
            <div class="filter-bar">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="Pending Manager Approval" <?php echo $filter_status === 'Pending Manager Approval' ? 'selected' : ''; ?>>Pending Approval</option>
                        <option value="Confirmed"   <?php echo $filter_status === 'Confirmed'   ? 'selected' : ''; ?>>Approved</option>
                        <option value="Discrepancy" <?php echo $filter_status === 'Discrepancy' ? 'selected' : ''; ?>>Discrepancy / Rejected</option>
                        <option value="Closed"      <?php echo $filter_status === 'Closed'      ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Supplier</label>
                    <input type="text" name="supplier" placeholder="Search supplier..." value="<?php echo htmlspecialchars($filter_supplier); ?>">
                </div>
                <div class="form-group">
                    <label>From</label>
                    <input type="date" name="start" value="<?php echo htmlspecialchars($filter_start); ?>">
                </div>
                <div class="form-group">
                    <label>To</label>
                    <input type="date" name="end" value="<?php echo htmlspecialchars($filter_end); ?>">
                </div>
                <div class="form-group" style="justify-content:flex-end;">
                    <button type="submit" style="background:#002F70;color:#fff;border:none;padding:7px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
                <div class="form-group" style="justify-content:flex-end;">
                    <a href="staff_delivery_history.php" style="background:#6c757d;color:#fff;padding:7px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;display:inline-block;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>

        <?php if (empty($deliveries)): ?>
        <div style="text-align:center;padding:48px 20px;color:#6c757d;">
            <i class="fas fa-truck" style="font-size:3rem;margin-bottom:12px;opacity:.3;display:block;"></i>
            <p style="font-size:15px;margin:0;">No delivery records found.</p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="del-table">
                <thead>
                    <tr>
                        <th>Delivery ID</th>
                        <th>Batch ID</th>
                        <th>Supplier</th>
                        <th>Item / Product</th>
                        <th>Qty</th>
                        <th>Date Received</th>
                        <th>DR #</th>
                        <th>Status</th>
                        <th>Remarks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deliveries as $d):
                    $status      = $d['status'];
                    $is_pending  = in_array($status, ['Pending Manager Approval', 'Pending Manager Confirmation']);
                    $is_discrepancy = in_array($status, ['Discrepancy', 'Pending Resolution', 'Awaiting Replacement', 'Returned to Supplier']);
                    $is_approved = in_array($status, ['Confirmed', 'Approved', 'Adjusted']);
                    $is_closed   = ($status === 'Closed');
                    $is_rejected = in_array($status, ['Rejected', 'Discrepancy']);

                    // Badge class
                    if ($is_pending) {
                        $badge_class = 'badge-pending';
                        $badge_label = 'Pending Approval';
                    } elseif ($status === 'Pending Resolution') {
                        $badge_class = 'badge-rejected';
                        $badge_label = 'Pending Resolution';
                    } elseif ($status === 'Awaiting Replacement') {
                        $badge_class = 'badge-pending';
                        $badge_label = 'Awaiting Replacement';
                    } elseif ($status === 'Returned to Supplier') {
                        $badge_class = 'badge-closed';
                        $badge_label = 'Returned to Supplier';
                    } elseif ($status === 'Adjusted') {
                        $badge_class = 'badge-approved';
                        $badge_label = 'Adjusted';
                    } elseif ($is_approved) {
                        $badge_class = 'badge-approved';
                        $badge_label = 'Approved';
                    } elseif ($status === 'Discrepancy') {
                        $badge_class = 'badge-rejected';
                        $badge_label = 'Rejected';
                    } else {
                        $badge_class = 'badge-closed';
                        $badge_label = 'Closed';
                    }

                    $row_class = $is_discrepancy ? 'row-rejected' : '';
                ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td><strong style="font-family:monospace;font-size:12px;"><?php echo htmlspecialchars($d['delivery_ref']); ?></strong></td>
                        <td><strong style="font-family:monospace;font-size:11px;color:#002F70;"><?php echo htmlspecialchars($d['batch_id'] ?? '—'); ?></strong></td>
                        <td><?php echo htmlspecialchars($d['supplier']); ?></td>
                        <td><?php echo htmlspecialchars($d['product']); ?></td>
                        <td><?php echo number_format((float)$d['quantity'], 2); ?> <span style="color:#6c757d;font-size:11px;"><?php echo htmlspecialchars($d['unit']); ?></span></td>
                        <td><?php echo date('M j, Y', strtotime($d['delivery_date'])); ?></td>
                        <td style="font-size:12px;color:#6c757d;"><?php echo $d['dr_number'] ? htmlspecialchars($d['dr_number']) : '—'; ?></td>
                        <td>
                            <span class="<?php echo $badge_class; ?>"><?php echo $badge_label; ?></span>
                            <?php if ($is_discrepancy && !empty($d['admin_notes'])): ?>
                            <div class="rejection-note">
                                <i class="fas fa-exclamation-circle" style="margin-top:1px;flex-shrink:0;"></i>
                                <span><?php echo htmlspecialchars(mb_strimwidth($d['admin_notes'], 0, 60, '…')); ?></span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:#6c757d;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="<?php echo htmlspecialchars($d['remarks'] ?? ''); ?>">
                            <?php echo $d['remarks'] ? htmlspecialchars($d['remarks']) : '—'; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                <button class="btn-sm-view" onclick="viewDelivery(<?php echo (int)$d['id']; ?>)" title="View details">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if ($is_rejected): ?>
                                <a href="staff_record_delivery.php?edit=<?php echo (int)$d['id']; ?>"
                                   class="btn-sm-resubmit" title="Edit and resubmit">
                                    <i class="fas fa-redo"></i> Resubmit
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- View Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-box">
        <div class="modal-title"><i class="fas fa-file-invoice"></i> Delivery Details</div>
        <div id="viewModalContent"></div>
        <div class="modal-actions" id="viewModalActions">
            <button class="btn-cancel-del" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
const deliveryData = <?php echo json_encode(array_column($deliveries, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const STATUS_LABELS = {
    'Pending Manager Approval':     'Pending Approval',
    'Pending Manager Confirmation': 'Pending Approval',
    'Confirmed':   'Approved',
    'Approved':    'Approved',
    'Adjusted':    'Adjusted',
    'Discrepancy': 'Rejected',
    'Pending Resolution':   'Pending Resolution',
    'Awaiting Replacement': 'Awaiting Replacement',
    'Returned to Supplier': 'Returned to Supplier',
    'Closed':      'Closed',
};
const STATUS_COLORS = {
    'Pending Manager Approval':     '#856404',
    'Pending Manager Confirmation': '#856404',
    'Confirmed':   '#155724',
    'Approved':    '#155724',
    'Adjusted':    '#0c5460',
    'Discrepancy': '#721c24',
    'Pending Resolution':   '#7d4e00',
    'Awaiting Replacement': '#004085',
    'Returned to Supplier': '#383d41',
    'Closed':      '#383d41',
};

function viewDelivery(id) {
    const d = deliveryData[id];
    if (!d) return;

    const label      = STATUS_LABELS[d.status] || d.status;
    const color      = STATUS_COLORS[d.status] || '#333';
    const isRejected = (d.status === 'Discrepancy');
    const isDiscrepancy = ['Discrepancy','Pending Resolution','Awaiting Replacement','Returned to Supplier'].includes(d.status);

    let html = '';

    if (isDiscrepancy) {
        const discMsg = {
            'Discrepancy':          'This delivery was Rejected by the Manager.',
            'Pending Resolution':   'Discrepancy flagged — Pending Resolution. Manager is deciding the action.',
            'Awaiting Replacement': 'Awaiting replacement delivery from supplier.',
            'Returned to Supplier': 'Items returned to supplier.',
        }[d.status] || 'Discrepancy flagged.';
        html += '<div class="rejection-banner">'
              + '<i class="fas fa-exclamation-triangle" style="margin-top:2px;flex-shrink:0;font-size:16px;"></i>'
              + '<div><strong>' + escHtml(discMsg) + '</strong>'
              + (d.admin_notes ? '<br><span style="margin-top:4px;display:block;">Manager note: ' + escHtml(d.admin_notes) + '</span>' : '')
              + (d.status === 'Discrepancy' ? '<br><span style="font-size:12px;">Please correct the details and resubmit.</span>' : '')
              + '</div></div>';
    }

    html += '<table class="detail-table">'
          + '<tr><td>Delivery ID</td><td><strong style="font-family:monospace;">' + escHtml(d.delivery_ref) + '</strong></td></tr>'
          + '<tr><td>Batch ID</td><td><strong style="font-family:monospace;color:#002F70;">' + escHtml(d.batch_id || '—') + '</strong></td></tr>'
          + '<tr><td>Supplier</td><td>' + escHtml(d.supplier) + '</td></tr>'
          + '<tr><td>Item / Product</td><td>' + escHtml(d.product) + '</td></tr>'
          + '<tr><td>Quantity</td><td>' + parseFloat(d.quantity).toFixed(2) + ' ' + escHtml(d.unit) + '</td></tr>'
          + '<tr><td>Date Received</td><td>' + escHtml(d.delivery_date) + '</td></tr>'
          + '<tr><td>DR Number</td><td>' + (d.dr_number ? escHtml(d.dr_number) : '—') + '</td></tr>'
          + '<tr><td>Status</td><td><strong style="color:' + color + ';">' + escHtml(label) + '</strong></td></tr>'
          + '<tr><td>Remarks</td><td>' + (d.remarks ? escHtml(d.remarks) : '—') + '</td></tr>'
          + '<tr><td>Encoded By</td><td>' + (d.encoded_by_name ? escHtml(d.encoded_by_name) : '—') + '</td></tr>'
          + '<tr><td>Recorded At</td><td>' + escHtml(d.created_at) + '</td></tr>'
          + '</table>';

    document.getElementById('viewModalContent').innerHTML = html;

    const actions = document.getElementById('viewModalActions');
    actions.innerHTML = '';
    if (isRejected) {
        actions.innerHTML += '<a href="staff_record_delivery.php?edit=' + id + '" class="btn-resubmit-modal">'
                           + '<i class="fas fa-redo"></i> Edit &amp; Resubmit</a>';
    }
    actions.innerHTML += '<button class="btn-cancel-del" onclick="closeModal(\'viewModal\')">Close</button>';

    document.getElementById('viewModal').classList.add('show');
}

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
