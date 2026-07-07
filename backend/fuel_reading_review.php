<?php
/**
 * Backend Modal: Fuel Reading Review (Manager)
 * Allows manager to review and approve or reject pump readings
 */

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

require_login();
$me        = current_user();
$userRole  = role_key($me['role'] ?? '');
$isManager = in_array($userRole, ['manager', 'admin', 'superadmin']);

if (!$isManager) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Access denied. Only managers can review readings.</div>';
    exit;
}

$id = $_GET['id'] ?? 0;

if (!$id) {
    echo '<div class="alert alert-danger">Invalid reading ID.</div>';
    exit;
}

// Fetch reading details
try {
    $stmt = $pdo->prepare("
     SELECT dr.*,
         dr.current_reading AS present_reading,
         COALESCE(p.pump_number, fs.pump_number) AS pump_number,
               COALESCE(ft.name, fs.fuel_type, 'N/A') AS fuel_type,
         u.name as staff_name,
         (dr.current_reading - dr.previous_reading - dr.calibration) as calculated_sales
        FROM fuel_daily_readings dr 
     LEFT JOIN fuel_pumps p ON dr.pump_id = p.id
     LEFT JOIN fuel_types ft ON p.fuel_type_id = ft.id
        LEFT JOIN fuel_stations fs ON dr.fuel_station_id = fs.id 
        LEFT JOIN users u ON dr.user_id = u.id 
        WHERE dr.id = ? AND dr.station_id = ?
    ");
    $stmt->execute([$id, user_station_id()]);
    $reading = $stmt->fetch();
    
    if (!$reading) {
        echo '<div class="alert alert-danger">Reading not found or access denied.</div>';
        exit;
    }
    
    if (!in_array($reading['status'], ['Pending Review', 'Pending'])) {
        echo '<div class="alert alert-warning">This reading has already been processed: ' . htmlspecialchars($reading['status']) . '</div>';
        exit;
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
?>

<style>
.modal-reading-review {
    padding: 24px;
}
.modal-reading-review .review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e0e0e0;
}
.modal-reading-review .review-header h4 {
    margin: 0;
    color: #333;
}
.modal-reading-review .pending-badge {
    background: #fff3cd;
    color: #856404;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.modal-reading-review .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}
.modal-reading-review .info-card {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
}
.modal-reading-review .info-card label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 5px;
}
.modal-reading-review .info-card .value {
    font-size: 16px;
    font-weight: 600;
    color: #333;
}
.modal-reading-review .metrics-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
    margin: 20px 0;
}
.modal-reading-review .metric-box {
    background: #fff;
    border: 1px solid #e0e0e0;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    min-width: 0;
}
.modal-reading-review .metric-box .metric-value {
    font-size: clamp(28px, 2.2vw, 36px);
    font-weight: 700;
    color: #003366;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
.modal-reading-review .metric-box .metric-label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
    margin-top: 5px;
}
.modal-reading-review .metric-box.highlight {
    background: #e3f2fd;
    border-color: #1976d2;
}
.modal-reading-review .metric-box.highlight .metric-value {
    color: #1976d2;
}
.modal-reading-review .notes-section {
    background: #fff8e1;
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
    border-left: 4px solid #ffc107;
}
.modal-reading-review .notes-section label {
    font-size: 12px;
    color: #666;
    font-weight: 600;
}
.modal-reading-review .notes-section p {
    margin: 5px 0 0 0;
    color: #333;
}
.modal-reading-review .review-form {
    margin-top: 25px;
    padding-top: 20px;
    border-top: 2px dashed #e0e0e0;
}
.modal-reading-review .review-form .form-label {
    font-weight: 600;
    color: #333;
    margin-bottom: 10px;
    display: block;
}
.modal-reading-review .action-buttons {
    display: flex;
    gap: 15px;
    margin-top: 20px;
}
.modal-reading-review .btn-approve {
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
.modal-reading-review .btn-approve:hover {
    background: #218838;
}
.modal-reading-review .btn-reject {
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
.modal-reading-review .btn-reject:hover {
    background: #c82333;
}
.modal-reading-review .rejection-fields {
    display: none;
    margin-top: 20px;
    padding: 15px;
    background: #ffebee;
    border-radius: 8px;
    border: 1px solid #ffcdd2;
}
.modal-reading-review .rejection-fields.show {
    display: block;
}
.modal-reading-review .rejection-fields label {
    font-weight: 600;
    color: #c62828;
    display: block;
    margin-bottom: 8px;
}
.modal-reading-review .rejection-fields select,
.modal-reading-review .rejection-fields textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    font-size: 14px;
}
.modal-reading-review .rejection-fields textarea {
    min-height: 80px;
    resize: vertical;
}

@media (max-width: 576px) {
    .modal-reading-review {
        padding: 16px;
    }
    .modal-reading-review .info-grid,
    .modal-reading-review .metrics-row {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="modal-reading-review">
    <div class="review-header">
        <h4><i class="fas fa-clipboard-check"></i> Verify Pump Reading</h4>
        <span class="pending-badge"><i class="fas fa-clock"></i> Pending Review</span>
    </div>

    <div class="info-grid">
        <div class="info-card">
            <label>Date</label>
            <div class="value"><?php echo date('M d, Y', strtotime($reading['reading_date'])); ?></div>
        </div>
        <div class="info-card">
            <label>Shift</label>
            <div class="value"><?php echo htmlspecialchars($reading['shift']); ?></div>
        </div>
        <div class="info-card">
            <label>Pump Number</label>
            <div class="value"><?php echo htmlspecialchars($reading['pump_number'] ?? 'N/A'); ?></div>
        </div>
        <div class="info-card">
            <label>Fuel Type</label>
            <div class="value"><?php echo htmlspecialchars($reading['fuel_type'] ?? 'N/A'); ?></div>
        </div>
        <div class="info-card">
            <label>Recorded By</label>
            <div class="value"><?php echo htmlspecialchars($reading['staff_name'] ?? 'Unknown'); ?></div>
        </div>
        <div class="info-card">
            <label>Recorded Time</label>
            <div class="value"><?php echo date('M d, Y H:i', strtotime($reading['created_at'])); ?></div>
        </div>
    </div>

    <div class="metrics-row">
        <div class="metric-box">
            <div class="metric-value"><?php echo number_format($reading['previous_reading'], 2); ?></div>
            <div class="metric-label">Previous Reading</div>
        </div>
        <div class="metric-box">
            <div class="metric-value"><?php echo number_format($reading['present_reading'], 2); ?></div>
            <div class="metric-label">Present Reading</div>
        </div>
        <div class="metric-box">
            <div class="metric-value"><?php echo number_format($reading['calibration'], 2); ?></div>
            <div class="metric-label">Calibration (L)</div>
        </div>
        <div class="metric-box highlight">
            <div class="metric-value"><?php echo number_format($reading['sales_liters'], 2); ?> L</div>
            <div class="metric-label">Net Liters Sold (L)</div>
        </div>
    </div>

    <?php if ($reading['notes']): ?>
    <div class="notes-section">
        <label><i class="fas fa-sticky-note"></i> Staff Notes</label>
        <p><?php echo nl2br(htmlspecialchars($reading['notes'])); ?></p>
    </div>
    <?php endif; ?>

    <form id="reviewReadingForm">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        
        <div class="review-form">
            <label class="form-label"><i class="fas fa-tasks"></i> Manager Verify Action</label>
            
            <div class="action-buttons">
                <button type="button" class="btn-approve" onclick="var id=<?php echo $id; ?>; var action='Verified'; var reason=''; var notes=''; var confirmMsg='Are you sure you want to APPROVE this pump reading?'; if(confirm(confirmMsg)){ var fd=new FormData(); fd.append('action','review_reading'); fd.append('id',id); fd.append('status',action); fd.append('reason',reason); fd.append('notes',notes); fetch('../backend/fuel_process_review.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message);location.reload();}).catch(e=>alert('Error:'+e));}">
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
                    <option value="Incorrect meter reading">Incorrect meter reading</option>
                    <option value="Incorrect calibration value">Incorrect calibration value</option>
                    <option value="Negative sales calculation">Negative sales calculation</option>
                    <option value="Missing information">Missing information</option>
                    <option value="Suspected data entry error">Suspected data entry error</option>
                    <option value="Other">Other (specify below)</option>
                </select>
                
                <label style="margin-top: 15px;">Additional Notes</label>
                <textarea id="rejectionNotes" placeholder="Provide additional details about the rejection..."></textarea>
                
                <button type="button" class="btn-reject" style="margin-top: 15px; width: 100%;" onclick="var id=<?php echo $id; ?>; var action='Rejected'; var reason=document.getElementById('rejectionReason').value; var notes=document.getElementById('rejectionNotes').value; if(!reason){alert('Please select a reason for rejection.');return;} if(!confirm('Are you sure you want to REJECT this pump reading? This action cannot be undone.')){return;} var fd=new FormData(); fd.append('action','review_reading'); fd.append('id',id); fd.append('status',action); fd.append('reason',reason); fd.append('notes',notes); fetch('../backend/fuel_process_review.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{alert(d.message);location.reload();}).catch(e=>alert('Error:'+e));">
                    <i class="fas fa-times-circle"></i> Confirm Rejection
                </button>
            </div>
        </div>
    </form>
</div>
