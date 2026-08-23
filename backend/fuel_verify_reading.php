<?php
/**
 * Backend Modal: Fuel Reading Verification
 * Allows managers to verify pump readings recorded by staff
 */

require_once __DIR__ . './lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Check if user is logged in and has manager role
require_login();
$me = current_user();

if (!in_array(strtolower($me['role']), ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Access denied. Only managers, admins, or superadmins can verify readings.</div>';
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
    
    // Check if reading is already verified
    if ($reading['status'] !== 'Pending') {
        echo '<div class="alert alert-warning">This reading has already been ' . strtolower($reading['status']) . '.</div>';
        exit;
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
?>

<div class="modal-dialog modal-lg">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-search"></i> Verify Pump Reading</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        
        <div class="modal-body">
            <!-- Reading Details -->
            <div class="card mb-3">
                <div class="card-header">
                    <strong><i class="fas fa-chart-bar"></i> Reading Details</strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($reading['reading_date'])); ?></p>
                            <p><strong>Pump:</strong> <?php echo htmlspecialchars($reading['pump_number']); ?></p>
                            <p><strong>Fuel Type:</strong> <?php echo htmlspecialchars($reading['fuel_type']); ?></p>
                            <p><strong>Shift:</strong> <?php echo $reading['shift']; ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Staff:</strong> <?php echo htmlspecialchars($reading['staff_name']); ?></p>
                            <p><strong>Recorded:</strong> <?php echo date('M d, Y H:i', strtotime($reading['created_at'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Reading Values -->
            <div class="card mb-3">
                <div class="card-header">
                    <strong><i class="fas fa-gas-pump"></i> Meter Readings</strong>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <div class="metric">
                                <div class="metric-value"><?php echo number_format($reading['previous_reading'], 2); ?></div>
                                <div class="metric-label">Previous Reading</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric">
                                <div class="metric-value"><?php echo number_format($reading['present_reading'], 2); ?></div>
                                <div class="metric-label">Present Reading</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric">
                                <div class="metric-value"><?php echo number_format($reading['calibration'], 2); ?></div>
                                <div class="metric-label">Calibration</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric <?php echo $reading['calculated_sales'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <div class="metric-value"><?php echo number_format($reading['calculated_sales'], 2); ?>L</div>
                                <div class="metric-label">Net Liters Sold (L)</div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($reading['notes']): ?>
                    <div class="mt-3">
                        <strong>Staff Notes:</strong>
                        <p class="text-muted"><?php echo htmlspecialchars($reading['notes']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Verification Form -->
            <form id="verifyReadingForm" method="POST" action="../backend/fuel_process_verification.php">
                <input type="hidden" name="action" value="verify_reading">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                
                <div class="card">
                    <div class="card-header">
                        <strong><i class="fas fa-check-circle"></i> Manager Verification</strong>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label"><strong>Verification Status *</strong></label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="status" id="statusVerified" value="Verified" required>
                                    <label class="form-check-label text-success" for="statusVerified">
                                        <i class="fas fa-check-circle"></i> Verified
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="status" id="statusRejected" value="Rejected" required>
                                    <label class="form-check-label text-danger" for="statusRejected">
                                        <i class="fas fa-times-circle"></i> Rejected
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="verificationNotes" class="form-label">Manager Notes</label>
                            <textarea class="form-control" id="verificationNotes" name="notes" rows="3" 
                                      placeholder="Enter any comments or reasons for rejection..."></textarea>
                        </div>
                        
                        <div id="rejectionReason" style="display: none;" class="mb-3">
                            <label for="rejectionSelect" class="form-label text-danger">Reason for Rejection *</label>
                            <select class="form-control" id="rejectionSelect" name="rejection_reason">
                                <option value="">Select reason...</option>
                                <option value="Incorrect Reading">Incorrect meter reading</option>
                                <option value="Missing Calibration">Missing or incorrect calibration</option>
                                <option value="Negative Sales">Negative sales calculation</option>
                                <option value="Incomplete Information">Incomplete information provided</option>
                                <option value="Other">Other (specify in notes)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="submit" form="verifyReadingForm" class="btn btn-success" id="submitBtn">
                <i class="fas fa-check"></i> Submit Verification
            </button>
        </div>
    </div>
</div>

<style>
.metric {
    padding: 15px;
    border-radius: 8px;
    background: #f8f9fa;
    margin-bottom: 10px;
}
.metric-value {
    font-size: 20px;
    font-weight: bold;
    margin-bottom: 5px;
}
.metric-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
}
.form-check-label {
    font-weight: 500;
}
</style>

<script>
// Show/hide rejection reason field
document.querySelectorAll('input[name="status"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const rejectionDiv = document.getElementById('rejectionReason');
        const rejectionSelect = document.getElementById('rejectionSelect');
        const submitBtn = document.getElementById('submitBtn');
        
        if (this.value === 'Rejected') {
            rejectionDiv.style.display = 'block';
            rejectionSelect.required = true;
            submitBtn.innerHTML = '<i class="fas fa-times"></i> Reject Reading';
            submitBtn.className = 'btn btn-danger';
        } else {
            rejectionDiv.style.display = 'none';
            rejectionSelect.required = false;
            rejectionSelect.value = '';
            submitBtn.innerHTML = '<i class="fas fa-check"></i> Verify Reading';
            submitBtn.className = 'btn btn-success';
        }
    });
});

// Form submission with validation
document.getElementById('verifyReadingForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const status = document.querySelector('input[name="status"]:checked')?.value;
    const rejectionReason = document.getElementById('rejectionSelect').value;
    
    if (status === 'Rejected' && !rejectionReason) {
        alert('Please select a reason for rejection.');
        return;
    }
    
    // Confirm action
    const action = status === 'Verified' ? 'verify' : 'reject';
    const confirmMsg = `Are you sure you want to ${action} this pump reading?`;
    
    if (confirm(confirmMsg)) {
        // Submit via AJAX
        const formData = new FormData(this);
        
        fetch('../backend/fuel_process_verification.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Reading ${action}ed successfully!`);
                location.reload(); // Reload the fuel management page
            } else {
                alert('Error: ' + (data.message || 'Unknown error occurred'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Network error occurred. Please try again.');
        });
    }
});
</script>