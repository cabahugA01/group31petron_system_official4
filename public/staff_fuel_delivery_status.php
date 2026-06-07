<?php
$page_id = 'staff_fuel_delivery_status';
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
    'received'     => '&#10003; Fuel delivery received and submitted for Manager Validation. Check status below.',
    'discrepancy'  => '&#9888; Variance detected! Delivery was flagged for Manager review. Please monitor status.',
    'manual_saved' => '&#10003; Manual fuel delivery saved successfully and submitted for Manager Validation.',
    'resubmitted'  => '&#10003; Fuel delivery resubmitted successfully. Awaiting Manager Validation.',
];
$msg_key  = trim($_GET['msg'] ?? '');
$msg      = $flash_messages[$msg_key] ?? '';
$msg_type = trim($_GET['type'] ?? 'success');

/* ── Bootstrap deliveries_oversight table ── */
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
            manager_id      INT          DEFAULT NULL,
            manager_action_at DATETIME   DEFAULT NULL,
            manager_notes   TEXT         DEFAULT NULL,
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

/* ── Fetch Fuel Deliveries Encoded by Staff ── */
$deliveries = [];
$counts = [
    'pending'  => 0,
    'approved' => 0,
    'rejected' => 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT do2.*, u_enc.name AS encoded_by_name, u_mgr.name AS manager_name
        FROM deliveries_oversight do2
        LEFT JOIN users u_enc ON do2.encoded_by = u_enc.id
        LEFT JOIN users u_mgr ON do2.manager_id = u_mgr.id
        WHERE do2.station_id = ? 
          AND do2.delivery_type = 'fuel' 
          AND do2.status != 'Expected Delivery'
          AND do2.encoded_by = ?
        ORDER BY 
            FIELD(do2.status, 'Discrepancy', 'Pending Manager Approval', 'Confirmed', 'Closed'),
            do2.delivery_date DESC
    ");
    $stmt->execute([$station_id, $me['id']]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deliveries as $d) {
        $status = $d['status'];
        if (in_array($status, ['Pending Manager Approval', 'Pending Manager Confirmation'])) {
            $counts['pending']++;
        } elseif (in_array($status, ['Confirmed', 'Approved', 'Adjusted', 'Closed'])) {
            $counts['approved']++;
        } elseif (in_array($status, ['Discrepancy', 'Pending Resolution', 'Rejected'])) {
            $counts['rejected']++;
        }
    }
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Summary Cards ── */
.summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
.summary-card { background: #fff; border-radius: 12px; padding: 20px 24px; box-shadow: 0 2px 8px rgba(0,0,0,.06); 
  border: 1px solid #e9ecef; display: flex; flex-direction: column; gap: 8px; position: relative; transition: transform .2s, box-shadow .2s; }
.summary-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.1); }
.summary-card .sc-num { font-size: 2.5rem; font-weight: 700; line-height: 1; }
.summary-card .sc-label { font-size: 13px; color: #6c757d; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.summary-card .sc-icon { font-size: 2rem; opacity: 0.15; position: absolute; right: 20px; top: 20px; }
.sc-pending .sc-num { color: #856404; }
.sc-approved .sc-num { color: #155724; }
.sc-rejected .sc-num { color: #721c24; }

/* ── Page Layout ── */
.del-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); border: 1px solid #e9ecef; margin-bottom: 24px; }
.del-card-head { display: flex; align-items: center; justify-content: space-between; padding: 18px 24px; border-bottom: 1px solid #e9ecef; flex-wrap: wrap; gap: 10px; }
.del-card-title { font-size: 1.1rem; font-weight: 700; color: #002F70; display: flex; align-items: center; gap: 10px; }
.del-card-body { padding: 24px; }

/* ── Status Badges ── */
.badge-pending  { background: #fff3cd; color: #856404; border: 1px solid #ffc107; border-radius: 20px; padding: 4px 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge-approved { background: #d4edda; color: #155724; border: 1px solid #28a745; border-radius: 20px; padding: 4px 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge-rejected { background: #f8d7da; color: #721c24; border: 1px solid #dc3545; border-radius: 20px; padding: 4px 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }

/* ── Table ── */
.del-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.del-table thead th { background: #002F70 !important; color: #fff !important; font-weight: 600; padding: 14px 16px; text-align: left; 
  text-transform: uppercase; letter-spacing: 0.3px; border: none !important; white-space: nowrap; font-size: 11px; }
.del-table td { padding: 12px 14px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.del-table tr:hover td { background: #f8f9fa; }
.del-table tr.row-rejected td { background: #fff8f8; }
.del-table tr.row-rejected:hover td { background: #ffeaea; }

/* ── Buttons ── */
.btn-view { background: #002F70; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 12px; 
  font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: background .2s; }
.btn-view:hover { background: #001f50; }
.btn-resubmit { background: #fd7e14; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 12px; 
  font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: background .2s; }
.btn-resubmit:hover { background: #e8690a; }

/* ── Back Button ── */
.btn-back { background: #6c757d; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-size: 14px; 
  font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: background .2s; }
.btn-back:hover { background: #5a6268; }

/* ── Empty State ── */
.empty-state { text-align: center; padding: 60px 20px; color: #adb5bd; }
.empty-state i { font-size: 4rem; margin-bottom: 20px; display: block; opacity: 0.3; }
.empty-state p { font-size: 15px; margin: 0; }

/* ── Rejection Note ── */
.rejection-note { background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 4px 10px; font-size: 11px; 
  color: #856404; margin-top: 6px; display: flex; align-items: flex-start; gap: 6px; line-height: 1.4; }

/* ── Modal ── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9000; align-items: center; justify-content: center; }
.modal-overlay.show { display: flex; }
.modal-box { background: #fff; border-radius: 12px; padding: 28px; max-width: 540px; width: 90%; box-shadow: 0 8px 32px rgba(0,0,0,.2); max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.1rem; font-weight: 700; color: #002F70; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.modal-actions { display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end; flex-wrap: wrap; }
.btn-cancel-modal { background: #6c757d; color: #fff; border: none; padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
.detail-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.detail-table tr td:first-child { color: #6c757d; width: 140px; padding: 7px 0; vertical-align: top; font-weight: 500; }
.detail-table tr td:last-child { padding: 7px 0; color: #212529; }
.detail-table tr + tr td { border-top: 1px solid #f0f0f0; }
.rejection-banner { background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; 
  display: flex; align-items: flex-start; gap: 10px; font-size: 13px; color: #721c24; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-clipboard-check"></i> Fuel Delivery Status</h1>
        <div class="sub">Monitor encoded fuel deliveries: Pending Validation, Approved, or Rejected status with Manager feedback.</div>
    </div>
    <div class="header-actions">
        <a href="staff_dashboard.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if ($msg): ?>
<div style="padding:13px 16px;border-radius:8px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;font-size:14px;<?php echo $msg_type === 'success' ? 'background:#d4edda;color:#155724;border:1px solid #c3e6cb;' : ($msg_type === 'warning' ? 'background:#fff3cd;color:#856404;border:1px solid #ffeeba;' : 'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;'); ?>">
    <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : ($msg_type === 'warning' ? 'exclamation-triangle' : 'times-circle'); ?>" style="margin-top:2px;"></i>
    <div><?php echo $msg; ?></div>
</div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="summary-grid">
    <div class="summary-card sc-pending">
        <i class="fas fa-clock sc-icon"></i>
        <div class="sc-num"><?php echo $counts['pending']; ?></div>
        <div class="sc-label">Pending Validation</div>
    </div>
    <div class="summary-card sc-approved">
        <i class="fas fa-check-circle sc-icon"></i>
        <div class="sc-num"><?php echo $counts['approved']; ?></div>
        <div class="sc-label">Approved</div>
    </div>
    <div class="summary-card sc-rejected">
        <i class="fas fa-times-circle sc-icon"></i>
        <div class="sc-num"><?php echo $counts['rejected']; ?></div>
        <div class="sc-label">Rejected</div>
    </div>
</div>

<!-- Deliveries Table -->
<div class="del-card">
    <div class="del-card-head">
        <div class="del-card-title">
            <i class="fas fa-list"></i> My Fuel Delivery Records
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <span style="font-size: 13px; color: #6c757d;"><?php echo count($deliveries); ?> record(s)</span>
            <a href="staff_fuel_deliveries.php" style="background:#002F70;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-plus"></i> Record New
            </a>
        </div>
    </div>
    <div class="del-card-body">
        <?php if (empty($deliveries)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No fuel delivery records found. Start by recording a fuel delivery receipt.</p>
                <a href="staff_fuel_deliveries.php" style="display:inline-block;margin-top:20px;background:#002F70;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">
                    <i class="fas fa-plus-circle"></i> Record New Delivery
                </a>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="del-table">
                    <thead>
                        <tr>
                            <th>Delivery ID</th>
                            <th>Invoice/DR</th>
                            <th>Fuel Type</th>
                            <th>Supplier</th>
                            <th>Quantity (Liters)</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Manager Feedback</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deliveries as $d):
                        $status = $d['status'];
                        $is_pending = in_array($status, ['Pending Manager Approval', 'Pending Manager Confirmation']);
                        $is_approved = in_array($status, ['Confirmed', 'Approved', 'Adjusted', 'Closed']);
                        $is_rejected = in_array($status, ['Discrepancy', 'Pending Resolution', 'Rejected']);
                        
                        if ($is_pending) {
                            $badge_class = 'badge-pending';
                            $badge_label = 'Pending Validation';
                        } elseif ($is_approved) {
                            $badge_class = 'badge-approved';
                            $badge_label = 'Approved';
                        } else {
                            $badge_class = 'badge-rejected';
                            $badge_label = 'Rejected';
                        }
                        
                        $row_class = $is_rejected ? 'row-rejected' : '';
                    ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td><strong style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($d['delivery_ref']); ?></strong></td>
                            <td><strong style="font-family: monospace; font-size: 11px; color: #002F70;"><?php echo htmlspecialchars($d['dr_number'] ?? '—'); ?></strong></td>
                            <td><?php echo htmlspecialchars($d['product']); ?></td>
                            <td><?php echo htmlspecialchars($d['supplier']); ?></td>
                            <td><?php echo number_format((float)$d['quantity'], 2); ?> <span style="color: #6c757d; font-size: 11px;">L</span></td>
                            <td><?php echo date('M j, Y', strtotime($d['delivery_date'])); ?></td>
                            <td>
                                <span class="<?php echo $badge_class; ?>"><?php echo $badge_label; ?></span>
                            </td>
                            <td style="max-width: 180px;">
                                <?php if (!empty($d['manager_notes'])): ?>
                                    <div class="rejection-note">
                                        <i class="fas fa-comment-dots" style="margin-top: 1px; flex-shrink: 0;"></i>
                                        <span><?php echo htmlspecialchars(mb_strimwidth($d['manager_notes'], 0, 50, '…')); ?></span>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #adb5bd; font-size: 12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <button class="btn-view" onclick="viewDelivery(<?php echo (int)$d['id']; ?>)" title="View details">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php if ($is_rejected): ?>
                                    <a href="staff_fuel_deliveries.php?edit=<?php echo (int)$d['id']; ?>" class="btn-resubmit" title="Edit and resubmit">
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
        <div class="modal-title"><i class="fas fa-file-invoice"></i> Fuel Delivery Details</div>
        <div id="viewModalContent"></div>
        <div class="modal-actions" id="viewModalActions">
            <button class="btn-cancel-modal" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
const deliveryData = <?php echo json_encode(array_column($deliveries, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const STATUS_LABELS = {
    'Pending Manager Approval':     'Pending Validation',
    'Pending Manager Confirmation': 'Pending Validation',
    'Confirmed':   'Approved',
    'Approved':    'Approved',
    'Adjusted':    'Approved',
    'Closed':      'Closed',
    'Discrepancy': 'Rejected',
    'Pending Resolution': 'Rejected',
    'Rejected':    'Rejected',
};

const STATUS_COLORS = {
    'Pending Manager Approval':     '#856404',
    'Pending Manager Confirmation': '#856404',
    'Confirmed':   '#155724',
    'Approved':    '#155724',
    'Adjusted':    '#155724',
    'Closed':      '#6c757d',
    'Discrepancy': '#721c24',
    'Pending Resolution': '#721c24',
    'Rejected':    '#721c24',
};

function viewDelivery(id) {
    const d = deliveryData[id];
    if (!d) return;

    const label = STATUS_LABELS[d.status] || d.status;
    const color = STATUS_COLORS[d.status] || '#333';
    const isRejected = ['Discrepancy', 'Pending Resolution', 'Rejected'].includes(d.status);

    let html = '';

    if (isRejected && d.manager_notes) {
        html += '<div class="rejection-banner">'
              + '<i class="fas fa-exclamation-triangle" style="margin-top: 2px; flex-shrink: 0; font-size: 16px;"></i>'
              + '<div><strong>Manager Feedback:</strong><br>'
              + '<span style="margin-top: 4px; display: block;">' + escHtml(d.manager_notes) + '</span>'
              + '<br><span style="font-size: 12px;">Please correct the details and resubmit via the "Fuel Deliveries" page.</span>'
              + '</div></div>';
    }

    html += '<table class="detail-table">'
          + '<tr><td>Delivery ID</td><td><strong style="font-family: monospace;">' + escHtml(d.delivery_ref) + '</strong></td></tr>'
          + '<tr><td>Invoice/DR</td><td><strong style="font-family: monospace; color: #002F70;">' + escHtml(d.dr_number || '—') + '</strong></td></tr>'
          + '<tr><td>Supplier</td><td>' + escHtml(d.supplier) + '</td></tr>'
          + '<tr><td>Fuel Type</td><td>' + escHtml(d.product) + '</td></tr>'
          + '<tr><td>Quantity</td><td>' + parseFloat(d.quantity).toFixed(2) + ' L</td></tr>'
          + '<tr><td>Date Received</td><td>' + escHtml(d.delivery_date) + '</td></tr>'
          + '<tr><td>Status</td><td><strong style="color: ' + color + ';">' + escHtml(label) + '</strong></td></tr>';
    
    if (d.manager_name) {
        html += '<tr><td>Validated By</td><td>' + escHtml(d.manager_name) + '</td></tr>';
    }
    
    html += '<tr><td>Your Remarks</td><td>' + (d.remarks ? escHtml(d.remarks) : '—') + '</td></tr>'
          + '<tr><td>Encoded At</td><td>' + escHtml(d.created_at) + '</td></tr>'
          + '</table>';

    document.getElementById('viewModalContent').innerHTML = html;

    const actions = document.getElementById('viewModalActions');
    actions.innerHTML = '';
    if (isRejected) {
        actions.innerHTML += '<a href="staff_fuel_deliveries.php?edit=' + id + '" class="btn-resubmit" style="padding: 10px 20px;">'
                           + '<i class="fas fa-redo"></i> Edit &amp; Resubmit</a>';
    }
    actions.innerHTML += '<button class="btn-cancel-modal" onclick="closeModal(\'viewModal\')">Close</button>';

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
