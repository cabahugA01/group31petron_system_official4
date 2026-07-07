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

/* ── Fetch Fuel Deliveries from fuel_deliveries table ── */
$deliveries = [];
$counts = [
    'pending'  => 0,
    'approved' => 0,
    'rejected' => 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT fd.*, 
               COALESCE(u.full_name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown') AS encoded_by_name, 
               COALESCE(um.full_name, CONCAT(um.first_name, ' ', um.last_name), um.username) AS manager_name
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        LEFT JOIN users um ON fd.verified_by = um.id
        WHERE fd.station_id = ?
        ORDER BY 
            FIELD(fd.status, 'Rejected', 'Pending Manager Validation', 'Validated', 'Approved'),
            fd.delivery_date DESC,
            fd.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deliveries as $d) {
        $status = $d['status'];
        if (in_array($status, ['Pending Manager Validation', 'Pending Validation', 'Pending'])) {
            $counts['pending']++;
        } elseif (in_array($status, ['Validated', 'Approved', 'Confirmed'])) {
            $counts['approved']++;
        } elseif (in_array($status, ['Rejected', 'Discrepancy'])) {
            $counts['rejected']++;
        }
    }
} catch (Exception $e) {
    error_log("Fuel deliveries history error: " . $e->getMessage());
}

include __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
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
  text-transform: uppercase; letter-spacing: 0.3px; border: none !important; font-size: 11px; }
.del-table td { padding: 12px 14px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.del-table tr:hover td { background: #f8f9fa; }
.del-table tr.row-rejected td { background: #fff8f8; }
.del-table tr.row-rejected:hover td { background: #ffeaea; }

/* Protect txn-btn from global header button overrides */
.txn-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    padding: 7px 14px !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    border: 1px solid transparent !important;
    transition: all .2s ease-in-out !important;
    text-decoration: none !important;
    white-space: nowrap !important;
    box-sizing: border-box !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
}
.txn-btn.primary {
    color: #00264D !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
    border: 1px solid #00264D !important;
}
.txn-btn.primary:hover {
    background-color: #00264D !important;
    background: #00264D !important;
    color: #ffffff !important;
}
.txn-btn.secondary {
    color: #475569 !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
    border: 1px solid #475569 !important;
}
.txn-btn.secondary:hover {
    background-color: #475569 !important;
    background: #475569 !important;
    color: #ffffff !important;
    color: #ffffff !important;
}
.txn-btn.success {
    color: #16a34a !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
    border: 1px solid #16a34a !important;
}
.txn-btn.success:hover {
    background-color: #16a34a !important;
    background: #16a34a !important;
    color: #ffffff !important;
}
.txn-btn.warning {
    color: #b45309 !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
    border: 1px solid #b45309 !important;
}
.txn-btn.warning:hover {
    background-color: #b45309 !important;
    background: #b45309 !important;
    color: #ffffff !important;
}
.txn-btn.danger {
    color: #dc2626 !important;
    background-color: #ffffff !important;
    background: #ffffff !important;
    border: 1px solid #dc2626 !important;
}
.txn-btn.danger:hover {
    background-color: #dc2626 !important;
    background: #dc2626 !important;
    color: #ffffff !important;
}

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
        <h1 class="h1"><i class="fas fa-history"></i> Fuel Deliveries History</h1>
        <div class="sub">View all fuel delivery records with manager approval status (Pending, Approved, Rejected).</div>
    </div>
    <div class="header-actions">
        <a href="staff_dashboard.php" class="txn-btn secondary">
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
            <i class="fas fa-history"></i> Fuel Deliveries History
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <span style="font-size: 13px; color: #6c757d;"><?php echo count($deliveries); ?> record(s)</span>
            <!-- Record New button removed - use "Record Fuel Delivery" menu instead -->
        </div>
    </div>
    <div class="del-card-body">
        <?php if (empty($deliveries)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No fuel delivery records found yet.</p>
                <p style="margin-top:12px;color:#6c757d;font-size:13px;">
                    Use the <strong>"Record Fuel Delivery"</strong> menu to encode new fuel deliveries.
                </p>
            </div>
        <?php else: ?>
            <div style="overflow:hidden;">
                <table class="del-table">
                    <thead>
                        <tr>
                            <th>Batch ID</th>
                            <th>Invoice/DR No.</th>
                            <th>Fuel Type</th>
                            <th>Supplier</th>
                            <th>Tanker No.</th>
                            <th>Liters Delivered</th>
                            <th>Tank Assigned</th>
                            <th>Delivery Date</th>
                            <th>Encoded By</th>
                            <th>Status</th>
                            <th>Manager Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deliveries as $d):
                        $status = $d['status'];
                        $is_pending = in_array($status, ['Pending Manager Validation', 'Pending Validation', 'Pending']);
                        $is_approved = in_array($status, ['Validated', 'Approved', 'Confirmed']);
                        $is_rejected = in_array($status, ['Rejected', 'Discrepancy']);
                        
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
                        
                        // Map fuel_deliveries columns
                        $batch_id = $d['batch_id'] ?? '—';
                        $invoice_no = $d['invoice_no'] ?? '—';
                        $fuel_type = $d['fuel_type'] ?? '—';
                        $supplier = $d['supplier'] ?? 'Petron Corporation';
                        $tanker_no = $d['tanker_number'] ?? '—';
                        $liters = (float)($d['delivery_liters'] ?? 0);
                        $tank = $d['tank_assigned'] ?? '—';
                        $delivery_date = $d['delivery_date'] ?? '';
                        $encoded_by = $d['encoded_by_name'] ?? 'Unknown';
                        $manager_notes = $d['validation_notes'] ?? $d['notes'] ?? '';
                    ?>
                        <tr class="<?php echo $row_class; ?>">
                            <!-- Batch ID -->
                            <td><strong style="font-family: monospace; font-size: 11px; color: #002F70;"><?php echo htmlspecialchars($batch_id); ?></strong></td>
                            
                            <!-- Invoice/DR No. -->
                            <td><strong style="font-family: monospace; font-size: 11px;"><?php echo htmlspecialchars($invoice_no); ?></strong></td>
                            
                            <!-- Fuel Type -->
                            <td><?php echo htmlspecialchars($fuel_type); ?></td>
                            
                            <!-- Supplier -->
                            <td><?php echo htmlspecialchars($supplier); ?></td>
                            
                            <!-- Tanker No. -->
                            <td style="font-family: monospace; font-size: 11px;"><?php echo htmlspecialchars($tanker_no); ?></td>
                            
                            <!-- Liters Delivered -->
                            <td><strong><?php echo number_format($liters, 2); ?></strong> <span style="color: #6c757d; font-size: 11px;">L</span></td>
                            
                            <!-- Tank Assigned -->
                            <td style="font-size: 11px;"><?php echo htmlspecialchars($tank); ?></td>
                            
                            <!-- Delivery Date -->
                            <td style="white-space: nowrap;"><?php echo date('M j, Y', strtotime($delivery_date)); ?></td>
                            
                            <!-- Encoded By -->
                            <td><?php echo htmlspecialchars($encoded_by); ?></td>
                            
                            <!-- Status -->
                            <td>
                                <span class="<?php echo $badge_class; ?>"><?php echo $badge_label; ?></span>
                            </td>
                            
                            <!-- Manager Remarks -->
                            <td style="max-width: 200px;">
                                <?php if (!empty($manager_notes)): ?>
                                    <div class="rejection-note">
                                        <i class="fas fa-comment-dots" style="margin-top: 1px; flex-shrink: 0;"></i>
                                        <span><?php echo htmlspecialchars(mb_strimwidth($manager_notes, 0, 60, '…')); ?></span>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #adb5bd; font-size: 12px;">—</span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Actions -->
                            <td>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <button class="txn-btn primary" onclick="viewDelivery(<?php echo (int)$d['id']; ?>)" title="View full details">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php if ($is_rejected): ?>
                                    <a href="staff_fuel_deliveries.php?edit=<?php echo (int)$d['id']; ?>" class="txn-btn warning" title="Edit and resubmit">
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
            <button class="txn-btn secondary" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
const deliveryData = <?php echo json_encode(array_column($deliveries, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const STATUS_LABELS = {
    'Pending Manager Validation':   'Pending Validation',
    'Pending Validation':           'Pending Validation',
    'Pending':                      'Pending Validation',
    'Validated':                    'Approved',
    'Approved':                     'Approved',
    'Confirmed':                    'Approved',
    'Rejected':                     'Rejected',
    'Discrepancy':                  'Rejected',
};

const STATUS_COLORS = {
    'Pending Manager Validation':   '#856404',
    'Pending Validation':           '#856404',
    'Pending':                      '#856404',
    'Validated':                    '#155724',
    'Approved':                     '#155724',
    'Confirmed':                    '#155724',
    'Rejected':                     '#721c24',
    'Discrepancy':                  '#721c24',
};

function viewDelivery(id) {
    const d = deliveryData[id];
    if (!d) return;

    const label = STATUS_LABELS[d.status] || d.status;
    const color = STATUS_COLORS[d.status] || '#333';
    const isRejected = ['Rejected', 'Discrepancy'].includes(d.status);

    // Map fuel_deliveries columns
    const batchId = d.batch_id || '—';
    const invoiceNo = d.invoice_no || '—';
    const fuelType = d.fuel_type || '—';
    const supplier = d.supplier || 'Petron Corporation';
    const tankerNo = d.tanker_number || '—';
    const liters = parseFloat(d.delivery_liters || 0).toFixed(2);
    const tank = d.tank_assigned || '—';
    const deliveryDate = d.delivery_date || '—';
    const encodedBy = d.encoded_by_name || 'Unknown';
    const managerNotes = d.validation_notes || d.notes || '';
    const managerName = d.manager_name || '';
    const validatedAt = d.validated_at || '';

    let html = '';

    if (isRejected && managerNotes) {
        html += '<div class="rejection-banner">'
              + '<i class="fas fa-exclamation-triangle" style="margin-top: 2px; flex-shrink: 0; font-size: 16px;"></i>'
              + '<div><strong>Manager Feedback:</strong><br>'
              + '<span style="margin-top: 4px; display: block;">' + escHtml(managerNotes) + '</span>'
              + '<br><span style="font-size: 12px;">Please correct the details and resubmit via "Record Fuel Delivery" page.</span>'
              + '</div></div>';
    }

    html += '<table class="detail-table">'
          + '<tr><td><strong>Batch ID</strong></td><td><strong style="font-family: monospace; color: #002F70;">' + escHtml(batchId) + '</strong></td></tr>'
          + '<tr><td><strong>Invoice/DR No.</strong></td><td><strong style="font-family: monospace;">' + escHtml(invoiceNo) + '</strong></td></tr>'
          + '<tr><td><strong>Delivery Date</strong></td><td>' + escHtml(deliveryDate) + '</td></tr>'
          + '<tr style="background: #f8f9fa;"><td colspan="2" style="padding: 8px; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #495057;">Delivery Details</td></tr>'
          + '<tr><td>Supplier</td><td>' + escHtml(supplier) + '</td></tr>'
          + '<tr><td>Fuel Type</td><td><strong>' + escHtml(fuelType) + '</strong></td></tr>'
          + '<tr><td>Liters Delivered</td><td><strong style="font-size: 15px; color: #002F70;">' + liters + ' L</strong></td></tr>'
          + '<tr><td>Tanker No.</td><td style="font-family: monospace;">' + escHtml(tankerNo) + '</td></tr>'
          + '<tr><td>Tank Assigned</td><td>' + escHtml(tank) + '</td></tr>'
          + '<tr style="background: #f8f9fa;"><td colspan="2" style="padding: 8px; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #495057;">Status & Approval</td></tr>'
          + '<tr><td>Status</td><td><strong style="color: ' + color + ';">' + escHtml(label) + '</strong></td></tr>';
    
    if (managerName) {
        html += '<tr><td>Validated By</td><td>' + escHtml(managerName) + '</td></tr>';
    }
    
    if (validatedAt) {
        html += '<tr><td>Validated At</td><td>' + escHtml(validatedAt) + '</td></tr>';
    }
    
    if (managerNotes) {
        html += '<tr><td>Manager Remarks</td><td><div style="padding: 8px; background: #fff3cd; border-left: 3px solid #ffc107; border-radius: 4px;">' + escHtml(managerNotes) + '</div></td></tr>';
    }
    
    html += '<tr style="background: #f8f9fa;"><td colspan="2" style="padding: 8px; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #495057;">Record Information</td></tr>'
          + '<tr><td>Encoded By</td><td>' + escHtml(encodedBy) + '</td></tr>'
          + '<tr><td>Encoded At</td><td>' + escHtml(d.created_at || '—') + '</td></tr>';
    
    if (d.notes) {
        html += '<tr><td>Your Remarks</td><td>' + escHtml(d.notes) + '</td></tr>';
    }
    
    html += '</table>';

    document.getElementById('viewModalContent').innerHTML = html;

    const actions = document.getElementById('viewModalActions');
    actions.innerHTML = '';
    if (isRejected) {
        actions.innerHTML += '<a href="staff_fuel_deliveries.php?edit=' + id + '" class="txn-btn warning">'
                           + '<i class="fas fa-redo"></i> Edit &amp; Resubmit</a> ';
    }
    actions.innerHTML += '<button class="txn-btn secondary" onclick="closeModal(\'viewModal\')">Close</button>';

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
