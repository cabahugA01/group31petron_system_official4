<?php
$page_id = 'staff_fuel_del_history';
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
    'received'     => '✓ Fuel delivery received and submitted for Manager Validation. Check status below.',
    'discrepancy'  => '⚠ Variance detected! Delivery was flagged for Manager review. Please monitor status.',
    'manual_saved' => '✓ Manual fuel delivery saved successfully and submitted for Manager Validation.',
    'resubmitted'  => '✓ Fuel delivery resubmitted successfully. Awaiting Manager Validation.',
];
$msg_key  = trim($_GET['msg'] ?? '');
$msg      = $flash_messages[$msg_key] ?? '';
$msg_type = trim($_GET['type'] ?? 'success');

/* ── Fetch Fuel Deliveries from fuel_deliveries table ── */
$deliveries = [];
$counts = [
    'pending'  => 0,
    'approved' => 0,
    'rejected' => 0,
];

try {
    // Query fuel_deliveries table with proper column names
    $stmt = $pdo->prepare("
        SELECT fd.*, 
               u.username AS encoded_by_name,
               um.username AS manager_name
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        LEFT JOIN users um ON fd.verified_by = um.id
        WHERE fd.station_id = ?
        ORDER BY 
            FIELD(fd.status, 'Rejected', 'Pending', 'Pending Validation', 'Pending Manager Validation', 'Verified', 'Validated', 'Approved'),
            fd.delivery_date DESC,
            fd.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // DEBUG: Log the query and results
    error_log("STAFF FUEL DELIVERIES: Station ID = {$station_id}, Found " . count($deliveries) . " deliveries");

    foreach ($deliveries as $d) {
        $status = strtolower(trim($d['status']));
        // DEBUG: Log each delivery status
        error_log("Delivery ID {$d['id']}: status = '{$d['status']}' (normalized: '{$status}')");
        
        if (in_array($status, ['pending manager validation', 'pending validation', 'pending'])) {
            $counts['pending']++;
        } elseif (in_array($status, ['validated', 'approved', 'confirmed', 'verified'])) {
            $counts['approved']++;
        } elseif (in_array($status, ['rejected', 'discrepancy'])) {
            $counts['rejected']++;
        }
    }
    
    // DEBUG: Log final counts
    error_log("COUNTS: Pending={$counts['pending']}, Approved={$counts['approved']}, Rejected={$counts['rejected']}");
    
} catch (Exception $e) {
    error_log("Fuel Deliveries History Error: " . $e->getMessage());
}

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
.del-table { width: 100%; border-collapse: collapse; font-size: 12px; table-layout: fixed; }
.del-table thead th { background: #002F70 !important; color: #fff !important; font-weight: 600; padding: 10px 8px; text-align: left; 
  text-transform: uppercase; letter-spacing: 0.3px; border: none !important; font-size: 10px; }
.del-table td { padding: 10px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
.del-table tr:hover td { background: #f8f9fa; }
.del-table tr.row-rejected td { background: #fff8f8; }
.del-table tr.row-rejected:hover td { background: #ffeaea; }

/* Column widths */
.del-table th:nth-child(1), .del-table td:nth-child(1) { width: 10%; } /* Batch ID */
.del-table th:nth-child(2), .del-table td:nth-child(2) { width: 8%; } /* Invoice */
.del-table th:nth-child(3), .del-table td:nth-child(3) { width: 8%; } /* Date */
.del-table th:nth-child(4), .del-table td:nth-child(4) { width: 9%; } /* Supplier */
.del-table th:nth-child(5), .del-table td:nth-child(5) { width: 8%; } /* Fuel Type */
.del-table th:nth-child(6), .del-table td:nth-child(6) { width: 7%; text-align: right; } /* Liters */
.del-table th:nth-child(7), .del-table td:nth-child(7) { width: 7%; } /* Tanker */
.del-table th:nth-child(8), .del-table td:nth-child(8) { width: 7%; } /* Tank */
.del-table th:nth-child(9), .del-table td:nth-child(9) { width: 8%; } /* Encoded By */
.del-table th:nth-child(10), .del-table td:nth-child(10) { width: 9%; } /* Status */
.del-table th:nth-child(11), .del-table td:nth-child(11) { width: 12%; } /* Remarks */
.del-table th:nth-child(12), .del-table td:nth-child(12) { width: 7%; text-align: center; } /* Actions */

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
        <h1 class="h1"><i class="fas fa-history"></i> Fuel Deliveries History</h1>
        <div class="sub">View all fuel delivery records with manager approval status (Pending, Approved, Rejected).</div>
    </div>
    <div class="header-actions">
        <a href="staff_fuel_deliveries.php" class="btn" style="background: #002F70; color: #fff;">
            <i class="fas fa-plus-circle"></i> Record New Delivery
        </a>
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
            <i class="fas fa-truck"></i> Fuel Deliveries History
            <span style="background: #002F70; color: #fff; border-radius: 12px; padding: 3px 10px; font-size: 12px;"><?php echo count($deliveries); ?></span>
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
            <div>
                <table class="del-table">
                    <thead>
                        <tr>
                            <th>Batch ID</th>
                            <th>Invoice/DR No.</th>
                            <th>Delivery Date</th>
                            <th>Supplier</th>
                            <th>Fuel Type</th>
                            <th>Liters</th>
                            <th>Tanker No.</th>
                            <th>Tank</th>
                            <th>Encoded By</th>
                            <th>Status</th>
                            <th>Manager Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deliveries as $d):
                        $status = trim($d['status']);
                        $status_lower = strtolower($status);
                        $is_pending = in_array($status_lower, ['pending manager validation', 'pending validation', 'pending']);
                        $is_approved = in_array($status_lower, ['validated', 'approved', 'confirmed', 'verified']);
                        $is_rejected = in_array($status_lower, ['rejected', 'discrepancy']);
                        
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
                        
                        // Generate Batch ID if not exists
                        $batch_id = !empty($d['batch_id']) ? $d['batch_id'] : 'BATCH-' . date('Ymd', strtotime($d['delivery_date'])) . '-' . str_pad($d['id'], 3, '0', STR_PAD_LEFT);
                        $invoice_no = $d['invoice_no'] ?? '—';
                        $fuel_type = $d['fuel_type'] ?? '—';
                        $supplier = $d['supplier'] ?? 'Petron Corporation';
                        $tanker_no = $d['tanker_number'] ?? '—';
                        $liters = (float)($d['delivery_liters'] ?? 0);
                        $tank = $d['tank_assigned'] ?? '—';
                        $delivery_date = $d['delivery_date'] ?? '';
                        $encoded_by = $d['encoded_by_name'] ?? 'Unknown';
                        $manager_notes = $d['notes'] ?? '';
                    ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td><strong style="font-family: monospace; font-size: 10px; color: #002F70; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($batch_id); ?></strong></td>
                            <td><span style="font-family: monospace; font-size: 10px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($invoice_no); ?></span></td>
                            <td style="white-space: nowrap; font-size: 11px;"><?php echo date('M j, Y', strtotime($delivery_date)); ?></td>
                            <td style="font-size: 11px;"><?php echo htmlspecialchars(substr($supplier, 0, 15)); ?></td>
                            <td><strong style="font-size: 11px;"><?php echo htmlspecialchars($fuel_type); ?></strong></td>
                            <td style="text-align: right;"><strong style="font-size: 11px;"><?php echo number_format($liters, 0); ?></strong> <span style="color: #6c757d; font-size: 10px;">L</span></td>
                            <td style="font-family: monospace; font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($tanker_no); ?></td>
                            <td style="font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($tank); ?></td>
                            <td style="font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($encoded_by); ?></td>
                            <td><span class="<?php echo $badge_class; ?>" style="font-size: 10px; padding: 3px 8px;"><?php echo $badge_label; ?></span></td>
                            <td style="max-width: 150px; font-size: 10px;">
                                <?php if (!empty($manager_notes) && stripos($manager_notes, 'Manager') !== false): ?>
                                    <div class="rejection-note" style="font-size: 10px; padding: 3px 8px;">
                                        <i class="fas fa-comment-dots" style="margin-top: 1px; flex-shrink: 0; font-size: 9px;"></i>
                                        <span><?php echo htmlspecialchars(mb_strimwidth($manager_notes, 0, 40, '…')); ?></span>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #adb5bd; font-size: 11px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <button class="btn-view" onclick="viewDelivery(<?php echo (int)$d['id']; ?>)" title="View full details" style="padding: 5px 10px; font-size: 11px;">
                                    <i class="fas fa-eye"></i> View
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

<!-- View Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-box">
        <div class="modal-title"><i class="fas fa-file-invoice"></i> Fuel Delivery Details</div>
        <div id="viewModalContent"></div>
        <div class="modal-actions">
            <button class="btn-cancel-modal" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
const deliveryData = <?php echo json_encode(array_column($deliveries, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function viewDelivery(id) {
    const d = deliveryData[id];
    if (!d) return;

    const status = d.status || 'Unknown';
    const isApproved = ['Validated', 'Approved', 'Confirmed', 'Verified'].includes(status);
    const isPending = ['Pending Manager Validation', 'Pending Validation', 'Pending'].includes(status);
    const isRejected = ['Rejected', 'Discrepancy'].includes(status);

    const statusColor = isApproved ? '#155724' : (isPending ? '#856404' : '#721c24');
    const statusLabel = isApproved ? 'Approved' : (isPending ? 'Pending Validation' : 'Rejected');

    const batchId = d.batch_id || ('BATCH-' + d.delivery_date.replace(/-/g, '') + '-' + String(d.id).padStart(3, '0'));
    const invoiceNo = d.invoice_no || '—';
    const fuelType = d.fuel_type || '—';
    const supplier = d.supplier || 'Petron Corporation';
    const tankerNo = d.tanker_number || '—';
    const liters = parseFloat(d.delivery_liters || 0).toFixed(2);
    const tank = d.tank_assigned || '—';
    const deliveryDate = d.delivery_date || '—';
    const encodedBy = d.encoded_by_name || 'Unknown';
    const managerNotes = d.notes || '';
    const managerName = d.manager_name || '';
    const verifiedAt = d.verified_at || '';

    let html = '';

    if (isRejected && managerNotes) {
        html += '<div class="rejection-banner">'
              + '<i class="fas fa-exclamation-triangle" style="margin-top: 2px; flex-shrink: 0; font-size: 16px;"></i>'
              + '<div><strong>Manager Feedback:</strong><br>'
              + '<span style="margin-top: 4px; display: block;">' + escHtml(managerNotes) + '</span></div></div>';
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
          + '<tr><td>Status</td><td><strong style="color: ' + statusColor + ';">' + statusLabel + '</strong></td></tr>';
    
    if (managerName) {
        html += '<tr><td>Validated By</td><td>' + escHtml(managerName) + '</td></tr>';
    }
    
    if (verifiedAt) {
        html += '<tr><td>Validated At</td><td>' + escHtml(verifiedAt) + '</td></tr>';
    }
    
    if (managerNotes && managerNotes.indexOf('Manager') >= 0) {
        html += '<tr><td>Manager Remarks</td><td><div style="padding: 8px; background: #fff3cd; border-left: 3px solid #ffc107; border-radius: 4px;">' + escHtml(managerNotes) + '</div></td></tr>';
    }
    
    html += '<tr style="background: #f8f9fa;"><td colspan="2" style="padding: 8px; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #495057;">Record Information</td></tr>'
          + '<tr><td>Encoded By</td><td>' + escHtml(encodedBy) + '</td></tr>'
          + '<tr><td>Encoded At</td><td>' + escHtml(d.created_at || '—') + '</td></tr></table>';

    document.getElementById('viewModalContent').innerHTML = html;
    document.getElementById('viewModal').classList.add('show');
}

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
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
