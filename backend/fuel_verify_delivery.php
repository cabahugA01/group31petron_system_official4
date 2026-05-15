<?php
/**
 * Backend Modal: Fuel Delivery Verification
 * Allows managers to verify fuel deliveries recorded by staff
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Check if user is logged in and has manager role
require_login();
$me = current_user();

if (!in_array($me['role'], ['manager', 'admin', 'superadmin'])) {
    echo '<div class="alert alert-danger">Access denied. Only managers, admins, or superadmins can verify deliveries. (Role: '.htmlspecialchars($me['role']).')</div>';
    exit;
}

$id = $_GET['id'] ?? 0;
$station_id = $_GET['station_id'] ?? user_station_id();

if (!$id) {
    echo '<div class="alert alert-danger">Invalid delivery ID.</div>';
    exit;
}

// Fetch delivery details
try {
    $stmt = $pdo->prepare("
        SELECT d.*, u.name as receiver_name, v.name as verifier_name, ft.name as fuel_type_name
        FROM fuel_deliveries d 
        LEFT JOIN users u ON d.received_by = u.id 
        LEFT JOIN users v ON d.verified_by = v.id 
        LEFT JOIN fuel_types ft ON d.fuel_type = ft.id
        WHERE d.id = ?
    ");
    $stmt->execute([$id]);
    $delivery = $stmt->fetch();
    
    if (!$delivery) {
        echo '<div class="alert alert-danger">Delivery not found or access denied.</div>';
        exit;
    }
    
    // Check if delivery is already verified
    if ($delivery['status'] !== 'Pending Review' && $delivery['status'] !== 'Pending' && $delivery['status'] !== 'Encoded') {
        echo '<div class="alert alert-warning">This delivery has already been ' . strtolower($delivery['status']) . '.</div>';
        exit;
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
?>

<style>
.modal-delivery-review {
    padding: 20px;
}
.modal-delivery-review .review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e0e0e0;
}
.modal-delivery-review .review-header h4 {
    margin: 0;
    color: #333;
}
.modal-delivery-review .pending-badge {
    background: #fff3cd;
    color: #856404;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.modal-delivery-review .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}
.modal-delivery-review .info-card {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
}
.modal-delivery-review .info-card label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 5px;
}
.modal-delivery-review .info-card .value {
    font-size: 16px;
    font-weight: 600;
    color: #333;
}
.modal-delivery-review .metrics-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin: 20px 0;
}
.modal-delivery-review .metric-box {
    background: #fff;
    border: 1px solid #e0e0e0;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
}
.modal-delivery-review .metric-box .metric-value {
    font-size: 22px;
    font-weight: 700;
    color: #003366;
}
.modal-delivery-review .metric-box .metric-label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
    margin-top: 5px;
}
.modal-delivery-review .metric-box.highlight {
    background: #e3f2fd;
    border-color: #1976d2;
}
.modal-delivery-review .metric-box.highlight .metric-value {
    color: #1976d2;
}
.modal-delivery-review .notes-section {
    background: #fff8e1;
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
    border-left: 4px solid #ffc107;
}
.modal-delivery-review .notes-section label {
    font-size: 12px;
    color: #666;
    font-weight: 600;
}
.modal-delivery-review .notes-section p {
    margin: 5px 0 0 0;
    color: #333;
}
.modal-delivery-review .review-form {
    margin-top: 25px;
    padding-top: 20px;
    border-top: 2px dashed #e0e0e0;
}
.modal-delivery-review .review-form .form-label {
    font-weight: 600;
    color: #333;
    margin-bottom: 10px;
    display: block;
}
.modal-delivery-review .action-buttons {
    display: flex;
    gap: 15px;
    margin-top: 20px;
}
.modal-delivery-review .btn-approve {
    flex: 1;
    background: #28a745;
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.modal-delivery-review .btn-approve:hover {
    background: #218838;
}
.modal-delivery-review .btn-reject {
    flex: 1;
    background: #dc3545;
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
.modal-delivery-review .btn-reject:hover {
    background: #c82333;
}
.modal-delivery-review .rejection-fields {
    display: none;
    margin-top: 20px;
    padding: 15px;
    background: #ffebee;
    border-radius: 8px;
    border: 1px solid #ffcdd2;
}
.modal-delivery-review .rejection-fields.show {
    display: block;
}
.modal-delivery-review .rejection-fields label {
    font-weight: 600;
    color: #c62828;
    display: block;
    margin-bottom: 8px;
}
.modal-delivery-review .rejection-fields select,
.modal-delivery-review .rejection-fields textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    font-size: 14px;
}
.modal-delivery-review .rejection-fields textarea {
    min-height: 80px;
    resize: vertical;
}
.modal-delivery-review .verification-inputs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-top: 15px;
}
.modal-delivery-review .verification-inputs label {
    font-size: 12px;
    color: #666;
    font-weight: 600;
    display: block;
    margin-bottom: 5px;
}
.modal-delivery-review .verification-inputs input,
.modal-delivery-review .verification-inputs select {
    width: 100%;
    padding: 10px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    font-size: 14px;
}
</style>

<div class="modal-delivery-review">
    <div class="review-header">
        <h4><i class="fas fa-truck"></i> Verify Fuel Delivery</h4>
        <span class="pending-badge"><i class="fas fa-clock"></i> Pending Review</span>
    </div>

    <div class="info-grid">
        <div class="info-card">
            <label>Delivery Date</label>
            <div class="value"><?php echo date('M d, Y', strtotime($delivery['delivery_date'])); ?></div>
        </div>
        <div class="info-card">
            <label>Fuel Type</label>
            <div class="value"><?php echo htmlspecialchars($delivery['fuel_type_name'] ?? $delivery['fuel_type']); ?></div>
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
            <label>Recorded By</label>
            <div class="value"><?php echo htmlspecialchars($delivery['receiver_name']); ?></div>
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

    <form id="verifyDeliveryForm">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="station_id" value="<?php echo $station_id; ?>">
        
        <div class="review-form">
            <label class="form-label"><i class="fas fa-tasks"></i> Manager Verify Action</label>
            
            <div class="verification-inputs">
                <div>
                    <label>Actual Liters Received</label>
                    <input type="number" step="0.01" id="actualLiters" name="actual_liters" value="<?php echo $delivery['delivery_liters']; ?>" min="0">
                </div>
                <div>
                    <label>Fuel Quality</label>
                    <select id="deliveryQuality" name="quality">
                        <option value="Good">Good - No issues</option>
                        <option value="Fair">Fair - Minor concerns</option>
                        <option value="Poor">Poor - Quality issues</option>
                    </select>
                </div>
            </div>
            
            <div class="action-buttons">
                <button type="button" class="btn-approve" onclick="var id=<?php echo $id; ?>; var stationId=<?php echo $station_id; ?>; var action='Verified'; var actualLiters=document.getElementById('actualLiters').value; var quality=document.getElementById('deliveryQuality').value; var reason=''; var notes=''; if(!actualLiters || actualLiters<=0){alert('Please enter valid actual liters.');return;} if(!confirm('Are you sure you want to VERIFY this fuel delivery?')){return;} var fd=new FormData(); fd.append('action','verify_delivery'); fd.append('id',id); fd.append('station_id',stationId); fd.append('status',action); fd.append('actual_liters',actualLiters); fd.append('quality',quality); fd.append('reason',reason); fd.append('notes',notes); fetch('../backend/fuel_process_verification.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message);location.reload();}).catch(e=>alert('Error:'+e));">
                    <i class="fas fa-check-circle"></i> Approve
                </button>
                <button type="button" class="btn-reject" onclick="document.getElementById('rejectionFields').classList.add('show');">
                    <i class="fas fa-times-circle"></i> Reject
                </button>
            </div>

            <div class="rejection-fields" id="rejectionFields">
                <label><i class="fas fa-exclamation-triangle"></i> Reason for Rejection *</label>
                <select id="rejectionReason" required>
                    <option value="">Select a reason...</option>
                    <option value="Quantity Mismatch">Quantity mismatch with invoice</option>
                    <option value="Quality Issues">Fuel quality problems</option>
                    <option value="Documentation Issues">Missing or incorrect documentation</option>
                    <option value="Delivery Issues">Delivery procedure not followed</option>
                    <option value="Tank Overflow">Would exceed tank capacity</option>
                    <option value="Other">Other (specify below)</option>
                </select>
                
                <label style="margin-top: 15px;">Additional Notes</label>
                <textarea id="verificationNotes" placeholder="Provide additional details about the rejection..."></textarea>
                
                <button type="button" class="btn-reject" style="margin-top: 15px; width: 100%;" onclick="var id=<?php echo $id; ?>; var stationId=<?php echo $station_id; ?>; var action='Rejected'; var actualLiters=document.getElementById('actualLiters').value; var quality=document.getElementById('deliveryQuality').value; var reason=document.getElementById('rejectionReason').value; var notes=document.getElementById('verificationNotes').value; if(!reason){alert('Please select a reason for rejection.');return;} if(!confirm('Are you sure you want to REJECT this fuel delivery? This action cannot be undone.')){return;} var fd=new FormData(); fd.append('action','verify_delivery'); fd.append('id',id); fd.append('station_id',stationId); fd.append('status',action); fd.append('actual_liters',actualLiters); fd.append('quality',quality); fd.append('reason',reason); fd.append('notes',notes); fetch('../backend/fuel_process_verification.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message);location.reload();}).catch(e=>alert('Error:'+e));">
                    <i class="fas fa-times-circle"></i> Confirm Rejection
                </button>
            </div>
        </div>
    </form>
</div>
