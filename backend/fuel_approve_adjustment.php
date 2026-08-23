<?php
/**
 * Backend Modal: Fuel Adjustment Approval
 * Allows managers to approve fuel adjustments recorded by staff
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Check if user is logged in and has manager role
require_login();
$me = current_user();

if (!in_array($me['role'], ['manager', 'admin', 'superadmin'])) {
    echo '<div class="alert alert-danger">Access denied. Only managers, admins, or superadmins can approve adjustments. (Role: '.htmlspecialchars($me['role']).')</div>';
}

$id = $_GET['id'] ?? 0;

if (!$id) {
    echo '<div class="alert alert-danger">Invalid adjustment ID.</div>';
    exit;
}

// Fetch adjustment details
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.name as staff_name, ap.name as approver_name
        FROM fuel_adjustments a 
        LEFT JOIN users u ON a.user_id = u.id 
        LEFT JOIN users ap ON a.approved_by = ap.id 
        WHERE a.id = ? AND a.station_id = ?
    ");
    $stmt->execute([$id, user_station_id()]);
    $adjustment = $stmt->fetch();
    
    if (!$adjustment) {
        echo '<div class="alert alert-danger">Adjustment not found or access denied.</div>';
        exit;
    }
    
    // Check if adjustment is already processed
    if ($adjustment['status'] !== 'Pending') {
        echo '<div class="alert alert-warning">This adjustment has already been ' . strtolower($adjustment['status']) . '.</div>';
        exit;
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

// Calculate impact
$impact_color = $adjustment['adjustment_type'] === 'Loss' ? 'danger' : 'success';
$impact_icon = $adjustment['adjustment_type'] === 'Loss' ? 'minus-circle' : 'plus-circle';
$impact_sign = $adjustment['adjustment_type'] === 'Loss' ? '-' : '+';
?>

<div class="modal-dialog modal-lg">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-clipboard-list"></i> Approve Fuel Adjustment</h5>
            <button type="button" class="btn-close" onclick="closeModal()"></button>
        </div>
        
        <div class="modal-body">
            <!-- Adjustment Details -->
            <div class="card mb-3">
                <div class="card-header">
                    <strong><i class="fas fa-chart-bar"></i> Adjustment Information</strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($adjustment['adjustment_date'])); ?></p>
                            <p><strong>Fuel Type:</strong> <span class="badge bg-primary"><?php echo htmlspecialchars($adjustment['fuel_type']); ?></span></p>
                            <p><strong>Type:</strong> 
                                <span class="badge bg-<?php echo $impact_color; ?>">
                                    <i class="fas fa-<?php echo $impact_icon; ?>"></i> <?php echo $adjustment['adjustment_type']; ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Staff:</strong> <?php echo htmlspecialchars($adjustment['staff_name']); ?></p>
                            <p><strong>Recorded:</strong> <?php echo date('M d, Y H:i', strtotime($adjustment['created_at'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Adjustment Volume & Reason -->
            <div class="card mb-3">
                <div class="card-header">
                    <strong><i class="fas fa-gas-pump"></i> Adjustment Details</strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="metric text-center">
                                <div class="metric-value text-<?php echo $impact_color; ?>">
                                    <?php echo $impact_sign . number_format($adjustment['liters'], 2); ?> Liters
                                </div>
                                <div class="metric-label">Volume Adjustment</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="metric">
                                <div class="metric-label">REASON</div>
                                <div class="metric-reason"><?php echo htmlspecialchars($adjustment['reason']); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($adjustment['notes']): ?>
                    <div class="mt-3">
                        <strong>Staff Notes:</strong>
                        <div class="border rounded p-3 bg-light">
                            <?php echo nl2br(htmlspecialchars($adjustment['notes'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Impact Analysis -->
            <div class="card mb-3">
                <div class="card-header">
                    <strong>📈 Impact Analysis</strong>
                </div>
                <div class="card-body">
                    <?php
                    // Get current inventory for this fuel type
                    try {
                        $stmt = $pdo->prepare("
                            SELECT p.name, i.stock_level, i.capacity, i.unit
                            FROM inventory i
                            JOIN products p ON i.product_id = p.id
                            WHERE i.station_id = ? AND p.name LIKE ?
                            LIMIT 1
                        ");
                        $stmt->execute([user_station_id(), '%' . $adjustment['fuel_type'] . '%']);
                        $inventory = $stmt->fetch();
                        
                        if ($inventory) {
                            $new_stock = $adjustment['adjustment_type'] === 'Loss' 
                                ? $inventory['stock_level'] - $adjustment['liters']
                                : $inventory['stock_level'] + $adjustment['liters'];
                            $impact_percent = ($adjustment['liters'] / $inventory['stock_level']) * 100;
                    ?>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="metric text-center">
                                <div class="metric-value"><?php echo number_format($inventory['stock_level'], 2); ?></div>
                                <div class="metric-label">Current Stock</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="metric text-center">
                                <div class="metric-value text-<?php echo $new_stock >= 0 ? 'success' : 'danger'; ?>">
                                    <?php echo number_format($new_stock, 2); ?>
                                </div>
                                <div class="metric-label">Stock After Adjustment</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="metric text-center">
                                <div class="metric-value text-<?php echo $impact_color; ?>">
                                    <?php echo number_format($impact_percent, 1); ?>%
                                </div>
                                <div class="metric-label">Impact on Current Stock</div>
                            </div>
                        </div>
                    </div>
                    
                    <?php
                            if ($new_stock < 0) {
                                echo '<div class="alert alert-danger mt-3">';
                                echo '<i class="fas fa-exclamation-triangle"></i> ';
                                echo '<strong>Warning:</strong> This adjustment would result in negative stock!';
                                echo '</div>';
                            } else if ($new_stock < $inventory['stock_level'] * 0.1) {
                                echo '<div class="alert alert-warning mt-3">';
                                echo '<i class="fas fa-exclamation-circle"></i> ';
                                echo '<strong>Low Stock Warning:</strong> Remaining stock will be very low after this adjustment.';
                                echo '</div>';
                            }
                        } else {
                            echo '<div class="alert alert-info">No inventory record found for ' . htmlspecialchars($adjustment['fuel_type']) . '</div>';
                        }
                    } catch (Exception $e) {
                        echo '<div class="alert alert-warning">Could not calculate inventory impact</div>';
                    }
                    ?>
                </div>
            </div>
            
            <!-- Approval Form -->
            <form id="approveAdjustmentForm" method="POST" action="../backend/fuel_process_verification.php">
                <input type="hidden" name="action" value="approve_adjustment">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                
                <div class="card">
                    <div class="card-header">
                        <strong><i class="fas fa-check-circle"></i> Manager Approval</strong>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label"><strong>Approval Status *</strong></label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="status" id="statusApproved" value="Approved" required>
                                    <label class="form-check-label text-success" for="statusApproved">
                                        <i class="fas fa-check-circle"></i> Approved
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
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustedAmount" class="form-label">Approved Amount (Liters)</label>
                                    <input type="number" step="0.01" class="form-control" id="adjustedAmount" name="approved_liters" 
                                           value="<?php echo $adjustment['liters']; ?>" min="0">
                                    <small class="form-text text-muted">You can adjust the amount if needed</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="priority" class="form-label">Priority Level</label>
                                    <select class="form-control" id="priority" name="priority">
                                        <option value="Normal">Normal</option>
                                        <option value="High">High - Requires immediate attention</option>
                                        <option value="Critical">Critical - Emergency adjustment</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="approvalNotes" class="form-label">Manager Notes</label>
                            <textarea class="form-control" id="approvalNotes" name="notes" rows="3" 
                                      placeholder="Enter approval notes, additional context, or reasons for rejection..."></textarea>
                        </div>
                        
                        <div id="rejectionReason" style="display: none;" class="mb-3">
                            <label for="rejectionSelect" class="form-label text-danger">Reason for Rejection *</label>
                            <select class="form-control" id="rejectionSelect" name="rejection_reason">
                                <option value="">Select reason...</option>
                                <option value="Insufficient Documentation">Insufficient documentation or justification</option>
                                <option value="Incorrect Amount">Amount seems incorrect or excessive</option>
                                <option value="Wrong Procedure">Proper procedure not followed</option>
                                <option value="Duplicate Entry">Appears to be duplicate adjustment</option>
                                <option value="Requires Investigation">Requires further investigation</option>
                                <option value="Other">Other (specify in notes)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelBtn">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="btn btn-success" id="submitBtn">
                <i class="fas fa-check"></i> Approve Adjustment
            </button>
        </div>
    </div>
</div>

<style>
.modal-dialog {
    max-width: 800px;
    margin: 2rem auto;
}

.modal-content {
    border: none;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 51, 102, 0.15);
    background: #fff;
}

.modal-header {
    background: linear-gradient(135deg, #003366 0%, #004080 100%);
    color: white;
    border-radius: 12px 12px 0 0;
    padding: 1.5rem;
    border-bottom: none;
}

.modal-header h5 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1);
    opacity: 0.8;
}

.modal-header .btn-close:hover {
    opacity: 1;
}

.modal-body {
    padding: 2rem;
}

.modal-footer {
    background: #f8f9fa;
    border-top: 1px solid #e9ecef;
    padding: 1.5rem;
    border-radius: 0 0 12px 12px;
}

.card {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    margin-bottom: 1.5rem;
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 1px solid #e9ecef;
    padding: 1rem 1.5rem;
    font-weight: 600;
    color: #003366;
}

.card-body {
    padding: 1.5rem;
}

.form-control {
    border: 1px solid #ced4da;
    border-radius: 6px;
    padding: 0.75rem;
    font-size: 0.95rem;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.form-control:focus {
    border-color: #003366;
    box-shadow: 0 0 0 0.2rem rgba(0, 51, 102, 0.25);
}

.btn {
    border-radius: 6px;
    padding: 0.75rem 1.5rem;
    font-weight: 500;
    transition: all 0.2s ease;
}

.btn-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    border: none;
    color: white;
}

.btn-success:hover {
    background: linear-gradient(135deg, #218838 0%, #1ea085 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
}

.btn-danger {
    background: linear-gradient(135deg, #dc3545 0%, #e74c3c 100%);
    border: none;
    color: white;
}

.btn-danger:hover {
    background: linear-gradient(135deg, #c82333 0%, #d62c1a 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
}

.btn-secondary {
    background: #6c757d;
    border: none;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
    transform: translateY(-1px);
}

.metric {
    padding: 1.5rem;
    border-radius: 8px;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    margin-bottom: 1rem;
    border: 1px solid #e9ecef;
    text-align: center;
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    color: #003366;
}

.metric-value.text-success {
    color: #28a745;
}

.metric-value.text-danger {
    color: #dc3545;
}

.metric-label {
    font-size: 0.75rem;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.metric-reason {
    font-size: 0.95rem;
    font-weight: 500;
    color: #495057;
    margin-top: 0.5rem;
    line-height: 1.4;
}

.badge {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-weight: 500;
    font-size: 0.875rem;
}

.badge.bg-primary {
    background: linear-gradient(135deg, #003366 0%, #004080 100%) !important;
}

.badge.bg-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
}

.badge.bg-danger {
    background: linear-gradient(135deg, #dc3545 0%, #e74c3c 100%) !important;
}

.alert {
    border-radius: 8px;
    border: none;
    padding: 1rem 1.5rem;
}

.alert-success {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    color: #155724;
}

.alert-danger {
    background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
    color: #721c24;
}

.alert-warning {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
    color: #856404;
}

.alert-info {
    background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
    color: #0c5460;
}

.form-check-input:checked {
    background-color: #003366;
    border-color: #003366;
}

.form-check-input:focus {
    border-color: #003366;
    box-shadow: 0 0 0 0.2rem rgba(0, 51, 102, 0.25);
}

.text-success {
    color: #28a745 !important;
}

.text-danger {
    color: #dc3545 !important;
}

/* Petron-specific styling */
.petron-header {
    background: linear-gradient(135deg, #003366 0%, #004080 100%);
    color: white;
    padding: 1rem;
    border-radius: 8px 8px 0 0;
    text-align: center;
}

.petron-footer {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 0 0 8px 8px;
    text-align: center;
    border-top: 1px solid #e9ecef;
}
</style>
