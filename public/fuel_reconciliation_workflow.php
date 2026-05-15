<?php
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

// Check if user is logged in and has appropriate role
require_login();
$u = current_user();
$role = role_key($u['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
    die("Access Denied");
}

$page_title = 'Fuel Reconciliation Workflow';
include __DIR__ . '/../partials/header.php';

// Get current step from URL parameter
$current_step = $_GET['step'] ?? '1';
$reconciliation_id = $_GET['reconciliation_id'] ?? null;

// Handle form submissions
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['action']) {
        case 'start_reconciliation':
            // Start new reconciliation
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO fuel_reconciliation_sessions 
                    (station_id, created_by, created_at, status, current_step) 
                    VALUES (?, ?, NOW(), 'active', '1')
                ");
                $stmt->execute([$station_id, $u['id']]);
                $reconciliation_id = $pdo->lastInsertId();
                
                log_activity($pdo, $u['id'], 'Reconciliation Started', 
                    "Started fuel reconciliation session #$reconciliation_id");
                
                header("Location: fuel_reconciliation_workflow.php?step=2&reconciliation_id=$reconciliation_id");
                exit;
            } catch (Exception $e) {
                $error = "Error starting reconciliation: " . $e->getMessage();
            }
            break;
            
        case 'save_pump_readings':
            // Save pump meter readings
            $reconciliation_id = $_POST['reconciliation_id'];
            $pump_readings = $_POST['pump_readings'] ?? [];
            
            try {
                // Clear previous readings for this session
                $stmt = $pdo->prepare("DELETE FROM fuel_pump_readings_temp WHERE reconciliation_id = ?");
                $stmt->execute([$reconciliation_id]);
                
                // Insert new readings
                foreach ($pump_readings as $pump_id => $reading) {
                    if (!empty($reading)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO fuel_pump_readings_temp 
                            (reconciliation_id, pump_id, reading_liters, recorded_by, recorded_at) 
                            VALUES (?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$reconciliation_id, $pump_id, $reading, $u['id']]);
                    }
                }
                
                // Update session step
                $stmt = $pdo->prepare("
                    UPDATE fuel_reconciliation_sessions 
                    SET current_step = '3', updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$reconciliation_id]);
                
                log_activity($pdo, $u['id'], 'Pump Readings Recorded', 
                    "Recorded pump readings for reconciliation #$reconciliation_id");
                
                $msg = "✅ Pump readings saved successfully!";
                $current_step = '3';
            } catch (Exception $e) {
                $error = "Error saving pump readings: " . $e->getMessage();
            }
            break;
            
        case 'save_delivery_comparison':
            // Save delivery comparison
            $reconciliation_id = $_POST['reconciliation_id'];
            $delivery_comparisons = $_POST['delivery_comparisons'] ?? [];
            
            try {
                // Clear previous comparisons
                $stmt = $pdo->prepare("DELETE FROM fuel_delivery_comparisons_temp WHERE reconciliation_id = ?");
                $stmt->execute([$reconciliation_id]);
                
                // Insert new comparisons
                foreach ($delivery_comparisons as $delivery_id => $comparison) {
                    $actual_liters = $comparison['actual_liters'] ?? 0;
                    $system_liters = $comparison['system_liters'] ?? 0;
                    $variance = $actual_liters - $system_liters;
                    
                    if (!empty($actual_liters)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO fuel_delivery_comparisons_temp 
                            (reconciliation_id, delivery_id, actual_liters, system_liters, variance, recorded_by, recorded_at) 
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$reconciliation_id, $delivery_id, $actual_liters, $system_liters, $variance, $u['id']]);
                    }
                }
                
                // Update session step
                $stmt = $pdo->prepare("
                    UPDATE fuel_reconciliation_sessions 
                    SET current_step = '4', updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$reconciliation_id]);
                
                log_activity($pdo, $u['id'], 'Delivery Comparison Completed', 
                    "Completed delivery comparison for reconciliation #$reconciliation_id");
                
                $msg = "✅ Delivery comparison saved successfully!";
                $current_step = '4';
            } catch (Exception $e) {
                $error = "Error saving delivery comparison: " . $e->getMessage();
            }
            break;
            
        case 'save_variance_analysis':
            // Save variance analysis
            $reconciliation_id = $_POST['reconciliation_id'];
            $variance_notes = $_POST['variance_notes'] ?? '';
            $requires_adjustment = $_POST['requires_adjustment'] ?? 'no';
            
            try {
                $stmt = $pdo->prepare("
                    UPDATE fuel_reconciliation_sessions 
                    SET variance_notes = ?, requires_adjustment = ?, current_step = '5', updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$variance_notes, $requires_adjustment, $reconciliation_id]);
                
                log_activity($pdo, $u['id'], 'Variance Analysis Completed', 
                    "Completed variance analysis for reconciliation #$reconciliation_id");
                
                $msg = "✅ Variance analysis saved successfully!";
                $current_step = '5';
            } catch (Exception $e) {
                $error = "Error saving variance analysis: " . $e->getMessage();
            }
            break;
            
        case 'submit_for_approval':
            // Submit for manager approval
            $reconciliation_id = $_POST['reconciliation_id'];
            
            try {
                $stmt = $pdo->prepare("
                    UPDATE fuel_reconciliation_sessions 
                    SET status = 'pending_approval', submitted_at = NOW(), current_step = '6', updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$reconciliation_id]);
                
                log_activity($pdo, $u['id'], 'Reconciliation Submitted for Approval', 
                    "Submitted reconciliation #$reconciliation_id for manager approval");
                
                $msg = "✅ Reconciliation submitted for manager approval!";
                $current_step = '6';
            } catch (Exception $e) {
                $error = "Error submitting for approval: " . $e->getMessage();
            }
            break;
            
        case 'manager_approve':
            // Manager approval
            if ($role !== 'manager' && $role !== 'admin' && $role !== 'superadmin') {
                $error = "Access Denied: Only managers can approve reconciliations";
            } else {
                $reconciliation_id = $_POST['reconciliation_id'];
                $approval_notes = $_POST['approval_notes'] ?? '';
                $manager_password = $_POST['manager_password'] ?? '';
                
                // Verify manager password
                if (!password_verify($manager_password, $u['password'])) {
                    $error = "Invalid manager password";
                } else {
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE fuel_reconciliation_sessions 
                            SET status = 'approved', approved_by = ?, approved_at = NOW(), 
                                approval_notes = ?, current_step = '7', updated_at = NOW() 
                            WHERE id = ?
                        ");
                        $stmt->execute([$u['id'], $approval_notes, $reconciliation_id]);
                        
                        log_activity($pdo, $u['id'], 'Reconciliation Approved', 
                            "Manager approved reconciliation #$reconciliation_id");
                        
                        $msg = "✅ Reconciliation approved and locked!";
                        $current_step = '7';
                    } catch (Exception $e) {
                        $error = "Error approving reconciliation: " . $e->getMessage();
                    }
                }
            }
            break;
    }
}

// Get session data if reconciliation_id is provided
$session_data = null;
if ($reconciliation_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT frs.*, u.name as created_by_name 
            FROM fuel_reconciliation_sessions frs 
            LEFT JOIN users u ON frs.created_by = u.id 
            WHERE frs.id = ? AND frs.station_id = ?
        ");
        $stmt->execute([$reconciliation_id, $station_id]);
        $session_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session_data) {
            $current_step = $session_data['current_step'];
        }
    } catch (Exception $e) {
        $error = "Error loading session data: " . $e->getMessage();
    }
}

// Helper function to get step status
function getStepStatus($step, $current_step) {
    if ($step < $current_step) return 'completed';
    if ($step == $current_step) return 'active';
    return 'pending';
}

// Helper function to get step icon
function getStepIcon($step, $current_step) {
    $status = getStepStatus($step, $current_step);
    switch ($status) {
        case 'completed': return '✅';
        case 'active': return '🔄';
        default: return '⭕';
    }
}
?>

<style>
.workflow-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    background: var(--bg);
    min-height: calc(100vh - 110px);
}

.workflow-header {
    text-align: center;
    margin-bottom: 30px;
}

.workflow-title {
    font-size: 28px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 10px;
}

.workflow-subtitle {
    color: var(--muted);
    font-size: 14px;
}

.progress-steps {
    display: flex;
    justify-content: space-between;
    margin-bottom: 40px;
    position: relative;
}

.progress-line {
    position: absolute;
    top: 25px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--border);
    z-index: 1;
}

.progress-line-fill {
    height: 100%;
    background: var(--blue);
    transition: width 0.3s ease;
}

.step-item {
    position: relative;
    z-index: 2;
    text-align: center;
    flex: 1;
}

.step-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: var(--card);
    border: 2px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px;
    font-size: 20px;
    transition: all 0.3s ease;
}

.step-item.active .step-icon {
    border-color: var(--blue);
    background: var(--blue);
    color: white;
}

.step-item.completed .step-icon {
    border-color: #28A745;
    background: #28A745;
    color: white;
}

.step-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    margin-bottom: 5px;
}

.step-item.active .step-title {
    color: var(--blue);
}

.step-item.completed .step-title {
    color: #28A745;
}

.step-description {
    font-size: 11px;
    color: var(--muted);
}

.step-content {
    background: var(--card);
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 8px;
}

.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
}

.form-textarea {
    min-height: 100px;
    resize: vertical;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: var(--blue);
    color: white;
}

.btn-primary:hover {
    background: #003366;
}

.btn-success {
    background: #28A745;
    color: white;
}

.btn-success:hover {
    background: #218838;
}

.btn-secondary {
    background: var(--muted);
    color: var(--text);
}

.btn-secondary:hover {
    background: #6c757d;
}

.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

.data-table th {
    background: var(--bg);
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: var(--muted);
    border-bottom: 2px solid var(--border);
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
}

.data-table input {
    width: 120px;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 4px;
}

.variance-positive {
    color: #28A745;
    font-weight: 600;
}

.variance-negative {
    color: #dc3545;
    font-weight: 600;
}

.audit-badge {
    background: #17a2b8;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.locked-badge {
    background: #dc3545;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

@media (max-width: 768px) {
    .progress-steps {
        flex-direction: column;
        gap: 20px;
    }
    
    .progress-line {
        display: none;
    }
    
    .step-item {
        flex: none;
    }
}
</style>

<div class="workflow-container">
    <div class="workflow-header">
        <h1 class="workflow-title">Fuel Reconciliation Workflow</h1>
        <p class="workflow-subtitle">Complete fuel inventory reconciliation with audit compliance</p>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Progress Steps -->
    <div class="progress-steps">
        <div class="progress-line">
            <div class="progress-line-fill" style="width: <?php echo (($current_step - 1) / 6) * 100; ?>%;"></div>
        </div>
        
        <div class="step-item <?php echo getStepStatus(1, $current_step); ?>">
            <div class="step-icon"><?php echo getStepIcon(1, $current_step); ?></div>
            <div class="step-title">Step 1</div>
            <div class="step-description">Collect Data Sources</div>
        </div>
        
        <div class="step-item <?php echo getStepStatus(2, $current_step); ?>">
            <div class="step-icon"><?php echo getStepIcon(2, $current_step); ?></div>
            <div class="step-title">Step 2</div>
            <div class="step-description">Encode & Compare</div>
        </div>
        
        <div class="step-item <?php echo getStepStatus(3, $current_step); ?>">
            <div class="step-icon"><?php echo getStepIcon(3, $current_step); ?></div>
            <div class="step-title">Step 3</div>
            <div class="step-description">Identify Variance</div>
        </div>
        
        <div class="step-item <?php echo getStepStatus(4, $current_step); ?>">
            <div class="step-icon"><?php echo getStepIcon(4, $current_step); ?></div>
            <div class="step-title">Step 4</div>
            <div class="step-description">Approval & Locking</div>
        </div>
        
        <div class="step-item <?php echo getStepStatus(5, $current_step); ?>">
            <div class="step-icon"><?php echo getStepIcon(5, $current_step); ?></div>
            <div class="step-title">Step 5</div>
            <div class="step-description">Defense Justification</div>
        </div>
    </div>

    <!-- Step Content -->
    <?php if ($current_step == '1'): ?>
        <!-- Step 1: Collect Data Sources -->
        <div class="step-content">
            <h2>Step 1: Collect Data Sources</h2>
            <p>Gather all necessary data for fuel reconciliation:</p>
            
            <ul>
                <li><strong>Pump meter readings</strong> - Actual liters dispensed from each pump</li>
                <li><strong>Delivery receipts</strong> - Batch receiving records</li>
                <li><strong>Sales transactions</strong> - POS or encoded sales data</li>
                <li><strong>Stock requests/issuances</strong> - Staff stock movements</li>
            </ul>

            <form method="post">
                <input type="hidden" name="action" value="start_reconciliation">
                <button type="submit" class="btn btn-primary">Start New Reconciliation Session</button>
            </form>
        </div>

    <?php elseif ($current_step == '2'): ?>
        <!-- Step 2: Encode & Compare -->
        <div class="step-content">
            <h2>Step 2: Encode & Compare</h2>
            <p>Enter actual measurements and compare with system records:</p>
            
            <?php
            // Get pumps for this station
            try {
                $stmt = $pdo->prepare("
                    SELECT fp.*, ft.name as fuel_type 
                    FROM fuel_pumps fp 
                    JOIN fuel_types ft ON fp.fuel_type_id = ft.id 
                    WHERE fp.station_id = ? AND fp.status = 'active'
                ");
                $stmt->execute([$station_id]);
                $pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // If no pumps found, create default pumps
                if (empty($pumps)) {
                    $default_fuel_types = ['Diesel', 'Gasoline 95', 'Gasoline 91'];
                    $pump_number = 1;
                    
                    foreach ($default_fuel_types as $fuel_type_name) {
                        // Get fuel type ID
                        $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE name = ?");
                        $stmt->execute([$fuel_type_name]);
                        $fuel_type = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($fuel_type) {
                            $stmt = $pdo->prepare("
                                INSERT INTO fuel_pumps 
                                (station_id, pump_number, fuel_type_id, status, current_reading, calibration_value) 
                                VALUES (?, ?, ?, 'active', 0, 0)
                            ");
                            $stmt->execute([$station_id, $pump_number, $fuel_type['id']]);
                            
                            $pumps[] = [
                                'id' => $pdo->lastInsertId(),
                                'pump_number' => $pump_number,
                                'fuel_type' => $fuel_type_name,
                                'fuel_type_id' => $fuel_type['id'],
                                'current_reading' => 0,
                                'calibration_value' => 0,
                                'status' => 'active'
                            ];
                            $pump_number++;
                        }
                    }
                    
                    $msg .= " ✅ Created default pumps for this station.";
                }
            } catch (Exception $e) {
                $pumps = [];
                $error = "Error loading pumps: " . $e->getMessage();
            }
            ?>

            <?php if (!empty($pumps)): ?>
                <form method="post">
                    <input type="hidden" name="action" value="save_pump_readings">
                    <input type="hidden" name="reconciliation_id" value="<?php echo $reconciliation_id; ?>">
                    
                    <h3>Pump Meter Readings</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Pump ID</th>
                                <th>Fuel Type</th>
                                <th>System Reading (L)</th>
                                <th>Actual Reading (L)</th>
                                <th>Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pumps as $pump): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pump['pump_number']); ?></td>
                                    <td><?php echo htmlspecialchars($pump['fuel_type']); ?></td>
                                    <td><?php echo number_format($pump['current_reading'] ?? 0, 2); ?></td>
                                    <td>
                                        <input type="number" step="0.01" name="pump_readings[<?php echo $pump['id']; ?>]" 
                                               placeholder="Enter actual reading" required>
                                    </td>
                                    <td id="diff_<?php echo $pump['id']; ?>">-</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="form-group">
                        <label class="form-label">Physical Dipstick Measurement (Total Station)</label>
                        <input type="number" step="0.01" name="physical_dipstick" class="form-input" 
                               placeholder="Enter total physical dipstick reading" required>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Readings & Continue</button>
                </form>
            <?php else: ?>
                <p>No active pumps found for this station.</p>
            <?php endif; ?>
        </div>

    <?php elseif ($current_step == '3'): ?>
        <!-- Step 3: Identify Variance -->
        <div class="step-content">
            <h2>Step 3: Identify Variance</h2>
            <p>Review differences and log variances for audit trail:</p>
            
            <?php
            // Get pump readings for this session
            try {
                $stmt = $pdo->prepare("
                    SELECT fprt.*, fp.pump_number, ft.name as fuel_type 
                    FROM fuel_pump_readings_temp fprt 
                    JOIN fuel_pumps fp ON fprt.pump_id = fp.id 
                    JOIN fuel_types ft ON fp.fuel_type_id = ft.id 
                    WHERE fprt.reconciliation_id = ?
                ");
                $stmt->execute([$reconciliation_id]);
                $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $readings = [];
            }
            ?>

            <?php if (!empty($readings)): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Pump</th>
                            <th>Fuel Type</th>
                            <th>System Reading</th>
                            <th>Actual Reading</th>
                            <th>Variance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($readings as $reading): ?>
                            <?php
                            $system_reading = $reading['system_reading'] ?? 0;
                            $actual_reading = $reading['reading_liters'];
                            $variance = $actual_reading - $system_reading;
                            $variance_percent = $system_reading > 0 ? ($variance / $system_reading) * 100 : 0;
                            $status = abs($variance_percent) > 5 ? 'Variance Alert' : 'Within Tolerance';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($reading['pump_number']); ?></td>
                                <td><?php echo htmlspecialchars($reading['fuel_type']); ?></td>
                                <td><?php echo number_format($system_reading, 2); ?></td>
                                <td><?php echo number_format($actual_reading, 2); ?></td>
                                <td class="<?php echo $variance >= 0 ? 'variance-positive' : 'variance-negative'; ?>">
                                    <?php echo number_format($variance, 2); ?> L
                                    (<?php echo number_format($variance_percent, 2); ?>%)
                                </td>
                                <td>
                                    <span class="audit-badge"><?php echo htmlspecialchars($status); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="post">
                    <input type="hidden" name="action" value="save_variance_analysis">
                    <input type="hidden" name="reconciliation_id" value="<?php echo $reconciliation_id; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Variance Analysis Notes</label>
                        <textarea name="variance_notes" class="form-textarea" required
                                  placeholder="Explain any variances found..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Requires Inventory Adjustment?</label>
                        <select name="requires_adjustment" class="form-select" required>
                            <option value="no">No - Within acceptable tolerance</option>
                            <option value="yes">Yes - Adjustment needed</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">Continue to Approval</button>
                </form>
            <?php else: ?>
                <p>No pump readings found. Please complete Step 2 first.</p>
            <?php endif; ?>
        </div>

    <?php elseif ($current_step == '4'): ?>
        <!-- Step 4: Approval & Locking -->
        <div class="step-content">
            <h2>Step 4: Approval & Locking</h2>
            <p>Submit reconciliation for manager approval and audit compliance:</p>
            
            <?php if ($session_data): ?>
                <div class="alert alert-success">
                    <h4>Reconciliation Summary</h4>
                    <p><strong>Session ID:</strong> #<?php echo $session_data['id']; ?></p>
                    <p><strong>Created by:</strong> <?php echo htmlspecialchars($session_data['created_by_name']); ?></p>
                    <p><strong>Created at:</strong> <?php echo date('M d, Y H:i', strtotime($session_data['created_at'])); ?></p>
                    <p><strong>Status:</strong> <?php echo htmlspecialchars($session_data['status']); ?></p>
                    <?php if (!empty($session_data['variance_notes'])): ?>
                        <p><strong>Variance Notes:</strong> <?php echo htmlspecialchars($session_data['variance_notes']); ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($session_data['status'] === 'pending_approval'): ?>
                    <div class="alert alert-success">
                        <h4>🔄 Pending Manager Approval</h4>
                        <p>This reconciliation has been submitted and is waiting for manager approval.</p>
                        <p><span class="audit-badge">Audit Trail Active</span></p>
                    </div>
                <?php elseif ($session_data['status'] === 'approved'): ?>
                    <div class="alert alert-success">
                        <h4>✅ Approved & Locked</h4>
                        <p>This reconciliation has been approved by manager and is now locked.</p>
                        <p><span class="locked-badge">Audit Trail Locked</span></p>
                        <p><strong>Approved by:</strong> <?php echo htmlspecialchars($session_data['approved_by_name'] ?? 'Manager'); ?></p>
                        <p><strong>Approved at:</strong> <?php echo date('M d, Y H:i', strtotime($session_data['approved_at'])); ?></p>
                    </div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="action" value="submit_for_approval">
                        <input type="hidden" name="reconciliation_id" value="<?php echo $reconciliation_id; ?>">
                        
                        <div class="form-group">
                            <label class="form-label">Submit for Manager Approval</label>
                            <p>By submitting, you confirm that all data has been accurately recorded and variances have been properly documented.</p>
                        </div>

                        <button type="submit" class="btn btn-primary">Submit for Manager Approval</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p>No session data found.</p>
            <?php endif; ?>
        </div>

    <?php elseif ($current_step == '5'): ?>
        <!-- Step 5: Defense Justification -->
        <div class="step-content">
            <h2>Step 5: Defense Justification</h2>
            <p>Audit compliance and operational transparency documentation:</p>
            
            <div class="alert alert-success">
                <h4>🛡️ Fuel Reconciliation Audit Compliance</h4>
                <p><strong>Defense Justification:</strong></p>
                <blockquote style="margin: 20px 0; padding: 15px; background: var(--bg); border-left: 4px solid var(--blue);">
                    "Fuel reconciliation ensures operational transparency by matching physical stock with encoded transactions. Variances are logged and approved by Manager role for audit compliance."
                </blockquote>
            </div>

            <?php if ($session_data && $session_data['status'] === 'approved'): ?>
                <h3>✅ Audit Trail Screenshot Ready</h3>
                <p>This reconciliation report has been validated and locked for audit compliance:</p>
                
                <table class="data-table">
                    <tr>
                        <th>Reconciliation ID</th>
                        <td>#<?php echo $session_data['id']; ?></td>
                    </tr>
                    <tr>
                        <th>Station</th>
                        <td><?php echo htmlspecialchars($station_id); ?></td>
                    </tr>
                    <tr>
                        <th>Created By</th>
                        <td><?php echo htmlspecialchars($session_data['created_by_name']); ?></td>
                    </tr>
                    <tr>
                        <th>Created At</th>
                        <td><?php echo date('M d, Y H:i', strtotime($session_data['created_at'])); ?></td>
                    </tr>
                    <tr>
                        <th>Approved By</th>
                        <td><?php echo htmlspecialchars($session_data['approved_by_name'] ?? 'Manager'); ?></td>
                    </tr>
                    <tr>
                        <th>Approved At</th>
                        <td><?php echo date('M d, Y H:i', strtotime($session_data['approved_at'])); ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td><span class="locked-badge">LOCKED & APPROVED</span></td>
                    </tr>
                </table>

                <div style="text-align: center; margin-top: 30px;">
                    <button onclick="window.print()" class="btn btn-secondary">🖨️ Print Audit Report</button>
                    <button onclick="takeScreenshot()" class="btn btn-primary">📸 Screenshot for Audit</button>
                </div>
            <?php else: ?>
                <p>Reconciliation must be approved first before generating audit compliance documentation.</p>
            <?php endif; ?>
        </div>

    <?php elseif ($current_step == '6'): ?>
        <!-- Manager Approval Step -->
        <div class="step-content">
            <h2>Manager Approval Required</h2>
            <p>Manager approval needed for this reconciliation:</p>
            
            <?php if ($role === 'manager' || $role === 'admin' || $role === 'superadmin'): ?>
                <form method="post">
                    <input type="hidden" name="action" value="manager_approve">
                    <input type="hidden" name="reconciliation_id" value="<?php echo $reconciliation_id; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Manager Approval Notes</label>
                        <textarea name="approval_notes" class="form-textarea" required
                                  placeholder="Enter approval notes or justification..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Manager Password (for verification)</label>
                        <input type="password" name="manager_password" class="form-input" required
                               placeholder="Enter your manager password">
                    </div>

                    <button type="submit" class="btn btn-success">✅ Approve & Lock Reconciliation</button>
                </form>
            <?php else: ?>
                <div class="alert alert-error">
                    <p>Access Denied: Only managers can approve reconciliations.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Calculate pump reading differences in real-time
document.addEventListener('DOMContentLoaded', function() {
    const pumpInputs = document.querySelectorAll('input[name^="pump_readings"]');
    
    pumpInputs.forEach(input => {
        input.addEventListener('input', function() {
            const pumpId = this.name.match(/\[(\d+)\]/)[1];
            const actualReading = parseFloat(this.value) || 0;
            const systemReading = parseFloat(this.closest('tr').cells[2].textContent) || 0;
            const difference = actualReading - systemReading;
            
            const diffCell = document.getElementById('diff_' + pumpId);
            if (diffCell) {
                diffCell.textContent = difference.toFixed(2) + ' L';
                diffCell.className = difference >= 0 ? 'variance-positive' : 'variance-negative';
            }
        });
    });
});

function takeScreenshot() {
    alert('📸 Screenshot functionality: Use your system screenshot tool (Windows + Shift + S) to capture this audit report for compliance records.');
}

// Auto-refresh for pending approvals
<?php if ($current_step == '4' && $session_data && $session_data['status'] === 'pending_approval'): ?>
setTimeout(function() {
    window.location.reload();
}, 30000); // Refresh every 30 seconds
<?php endif; ?>
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
