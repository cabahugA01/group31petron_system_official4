<?php
/**
 * Backend Modal: Fuel Delivery View (Staff)
 * Displays delivery details in read-only mode
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

require_login();
$me = current_user();
$userRole = strtolower(trim($me['role'] ?? ''));
$isManager = in_array($userRole, ['manager', 'admin', 'superadmin']);

$id = $_GET['id'] ?? 0;

if (!$id) {
    echo '<div class="alert alert-danger">Invalid delivery ID.</div>';
    exit;
}

// Fetch delivery details
try {
    $stmt = $pdo->prepare("
        SELECT d.*, u.name as receiver_name, v.name as verifier_name
        FROM fuel_deliveries d 
        LEFT JOIN users u ON d.received_by = u.id 
        LEFT JOIN users v ON d.verified_by = v.id 
        WHERE d.id = ?
    ");
    $stmt->execute([$id]);
    $delivery = $stmt->fetch();
    
    if (!$delivery) {
        echo '<div class="alert alert-danger">Delivery not found.</div>';
        exit;
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

// Status badge color
$statusClass = strtolower($delivery['status']);
$statusColors = [
    'pending_review' => 'warning',
    'pending' => 'warning',
    'verified' => 'success',
    'rejected' => 'danger',
    'finalized' => 'info'
];
$badgeColor = $statusColors[$statusClass] ?? 'secondary';
?>

<style>
.modal-delivery-view {
    padding: 20px;
}
.modal-delivery-view .delivery-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e0e0e0;
}
.modal-delivery-view .delivery-header h4 {
    margin: 0;
    color: #333;
}
.modal-delivery-view .status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.modal-delivery-view .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}
.modal-delivery-view .info-card {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
}
.modal-delivery-view .info-card label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 5px;
}
.modal-delivery-view .info-card .value {
    font-size: 16px;
    font-weight: 600;
    color: #333;
}
.modal-delivery-view .metrics-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin: 20px 0;
}
.modal-delivery-view .metric-box {
    background: #fff;
    border: 1px solid #e0e0e0;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
}
.modal-delivery-view .metric-box .metric-value {
    font-size: 22px;
    font-weight: 700;
    color: #003366;
}
.modal-delivery-view .metric-box .metric-label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
    margin-top: 5px;
}
.modal-delivery-view .metric-box.highlight {
    background: #e3f2fd;
    border-color: #1976d2;
}
.modal-delivery-view .metric-box.highlight .metric-value {
    color: #1976d2;
}
.modal-delivery-view .notes-section {
    background: #fff8e1;
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
    border-left: 4px solid #ffc107;
}
.modal-delivery-view .notes-section label {
    font-size: 12px;
    color: #666;
    font-weight: 600;
}
.modal-delivery-view .notes-section p {
    margin: 5px 0 0 0;
    color: #333;
}
.modal-delivery-view .modal-actions {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
</style>

<div class="modal-delivery-view">
    <div class="delivery-header">
        <h4><i class="fas fa-truck"></i> Fuel Delivery Details</h4>
        <span class="status-badge badge-<?php echo $badgeColor; ?>">
            <?php echo htmlspecialchars($delivery['status']); ?>
        </span>
    </div>

    <div class="info-grid">
        <div class="info-card">
            <label>Delivery Date</label>
            <div class="value"><?php echo date('M d, Y', strtotime($delivery['delivery_date'])); ?></div>
        </div>
        <div class="info-card">
            <label>Fuel Type</label>
            <div class="value"><?php echo htmlspecialchars($delivery['fuel_type']); ?></div>
        </div>
        <div class="info-card">
            <label>Supplier</label>
            <div class="value"><?php echo htmlspecialchars($delivery['supplier']); ?></div>
        </div>
        <div class="info-card">
            <label>Invoice No.</label>
            <div class="value"><?php echo htmlspecialchars($delivery['invoice_no'] ?: 'N/A'); ?></div>
        </div>
        <div class="info-card">
            <label>Tanker Number</label>
            <div class="value"><?php echo htmlspecialchars($delivery['tanker_number'] ?: 'N/A'); ?></div>
        </div>
        <div class="info-card">
            <label>Received By</label>
            <div class="value"><?php echo htmlspecialchars($delivery['receiver_name'] ?? 'Unknown'); ?></div>
        </div>
        <?php if ($delivery['verified_by']): ?>
        <div class="info-card">
            <label>Verified By</label>
            <div class="value"><?php echo htmlspecialchars($delivery['verifier_name']); ?></div>
        </div>
        <?php endif; ?>
        <div class="info-card">
            <label>Recorded Time</label>
            <div class="value"><?php echo date('M d, Y H:i', strtotime($delivery['created_at'])); ?></div>
        </div>
    </div>

    <div class="metrics-row">
        <div class="metric-box highlight">
            <div class="metric-value"><?php echo number_format($delivery['delivery_liters'], 2); ?> L</div>
            <div class="metric-label">Delivery Volume</div>
        </div>
    </div>

    <?php if ($delivery['notes']): ?>
    <div class="notes-section">
        <label><i class="fas fa-sticky-note"></i> Staff Notes</label>
        <p><?php echo nl2br(htmlspecialchars($delivery['notes'])); ?></p>
    </div>
    <?php endif; ?>

    <?php if ($delivery['status'] === 'Rejected' && $delivery['notes']): ?>
    <div class="notes-section" style="background: #ffebee; border-left-color: #f44336;">
        <label style="color: #c62828;"><i class="fas fa-times-circle"></i> Rejection Reason</label>
        <p><?php echo nl2br(htmlspecialchars($delivery['notes'])); ?></p>
    </div>
    <?php endif; ?>

    <div class="modal-actions">
        <?php if (in_array($delivery['status'], ['Pending Review', 'Pending']) && $isManager): ?>
        <button type="button" class="btn primary" style="background: var(--blue);" onclick="document.getElementById('modalViewDelivery').classList.remove('show'); setTimeout(function(){ openVerifyDeliveryModal(<?php echo $delivery['id']; ?>); }, 150);">
            <i class="fas fa-check-circle"></i> Verify Delivery
        </button>
        <?php endif; ?>
        <button type="button" class="btn" onclick="document.getElementById('modalViewDelivery').classList.remove('show')">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</div>
