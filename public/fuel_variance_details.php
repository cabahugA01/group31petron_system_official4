<?php
/**
 * Fuel Variance Details Modal
 * Displays detailed information about a variance report
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$userRole  = role_key($me['role'] ?? '');
$isManager = in_array($userRole, ['manager', 'admin', 'superadmin']);
$isAdmin   = in_array($userRole, ['admin', 'superadmin']);
$station_id = user_station_id();

$id = $_GET['id'] ?? 0;

if (!$id) {
    echo '<div class="alert alert-danger">Invalid variance report ID.</div>';
    exit;
}

// Fetch variance report details
try {
    $stmt = $pdo->prepare("
        SELECT vr.*, i.name as investigator_name, s.name as station_name
        FROM fuel_variance_reports vr 
        LEFT JOIN users i ON vr.investigated_by = i.id 
        LEFT JOIN stations s ON vr.station_id = s.id
        WHERE vr.id = ?
    ");
    $stmt->execute([$id]);
    $variance = $stmt->fetch();
    
    if (!$variance) {
        echo '<div class="alert alert-danger">Variance report not found.</div>';
        exit;
    }
    
    // Check access for non-superadmin
    if ($userRole !== 'superadmin' && $variance['station_id'] != $station_id) {
        echo '<div class="alert alert-danger">Access denied for this station\'s variance report.</div>';
        exit;
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

$variance_severity = abs($variance['variance_percent']);
$severity_class = $variance_severity > 5 ? 'danger' : ($variance_severity > 2 ? 'warning' : 'info');
$severity_label = $variance_severity > 5 ? 'Critical' : ($variance_severity > 2 ? 'Significant' : 'Minor');
?>

<div class="modal-dialog modal-xl">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">📊 Fuel Variance Report Details</h5>
            <button type="button" class="btn-close" onclick="this.closest('.modal').classList.remove('show')"></button>
        </div>
        
        <div class="modal-body">
            <!-- Variance Overview -->
            <div class="card mb-3">
                <div class="card-header">
                    <strong>📊 Variance Overview</strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="metric text-center">
                                <div class="metric-value"><?php echo date('M d, Y', strtotime($variance['report_date'])); ?></div>
                                <div class="metric-label">Report Date</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric text-center">
                                <div class="metric-value"><?php echo htmlspecialchars($variance['station_name'] ?: 'Station ' . $variance['station_id']); ?></div>
                                <div class="metric-label">Station</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric text-center">
                                <div class="metric-value"><?php echo htmlspecialchars($variance['fuel_type']); ?></div>
                                <div class="metric-label">Fuel Type</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric text-center">
                                <div class="metric-value">
                                    <span class="badge bg-<?php echo $severity_class; ?> fs-6"><?php echo $severity_label; ?></span>
                                </div>
                                <div class="metric-label">Severity Level</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Variance Numbers -->
            <div class="card mb-3">
                <div class="card-header">
                    <strong>⛽ Variance Analysis</strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="metric text-center">
                                <div class="metric-value text-primary"><?php echo number_format($variance['expected_stock'], 2); ?>L</div>
                                <div class="metric-label">Expected Stock</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric text-center">
                                <div class="metric-value text-info"><?php echo number_format($variance['actual_stock'], 2); ?>L</div>
                                <div class="metric-label">Actual Stock</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric text-center">
                                <div class="metric-value text-<?php echo $variance['variance_liters'] >= 0 ? 'success' : 'danger'; ?>">
                                    <?php echo ($variance['variance_liters'] >= 0 ? '+' : '') . number_format($variance['variance_liters'], 2); ?>L
                                </div>
                                <div class="metric-label">Variance (Liters)</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric text-center">
                                <div class="metric-value text-<?php echo $variance['variance_percent'] >= 0 ? 'success' : 'danger'; ?>">
                                    <?php echo ($variance['variance_percent'] >= 0 ? '+' : '') . number_format($variance['variance_percent'], 2); ?>%
                                </div>
                                <div class="metric-label">Variance (%)</div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($variance['reason']): ?>
                    <div class="mt-3">
                        <strong>Initial Reason/Notes:</strong>
                        <div class="border rounded p-3 bg-light">
                            <?php echo nl2br(htmlspecialchars($variance['reason'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Investigation Status -->
            <div class="card mb-3">
                <div class="card-header">
                    <strong>🕵️ Investigation Status</strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Status:</strong> 
                                <span class="badge bg-<?php echo strtolower($variance['status']) == 'resolved' ? 'success' : (strtolower($variance['status']) == 'under investigation' ? 'warning' : 'danger'); ?>">
                                    <?php echo $variance['status']; ?>
                                </span>
                            </p>
                            <p><strong>Investigated by:</strong> <?php echo $variance['investigator_name'] ? htmlspecialchars($variance['investigator_name']) : '<span class="text-muted">Not investigated</span>'; ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Created:</strong> <?php echo date('M d, Y H:i', strtotime($variance['created_at'] ?? $variance['report_date'])); ?></p>
                            <p><strong>Last Updated:</strong> <?php echo $variance['updated_at'] ? date('M d, Y H:i', strtotime($variance['updated_at'])) : '<span class="text-muted">Never</span>'; ?></p>
                        </div>
                    </div>
                    
                    <?php if ($variance['resolution_notes']): ?>
                    <div class="mt-3">
                        <strong>Investigation Notes:</strong>
                        <div class="border rounded p-3 bg-light">
                            <?php echo nl2br(htmlspecialchars($variance['resolution_notes'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Related Data -->
            <div class="card mb-3">
                <div class="card-header">
                    <strong>📋 Related Transaction Data</strong>
                    <small class="text-muted ms-2">(For <?php echo date('M d, Y', strtotime($variance['report_date'])); ?>)</small>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Daily Readings -->
                        <div class="col-md-6">
                            <h6>📊 Daily Readings</h6>
                            <?php
                            try {
                                $stmt = $pdo->prepare("
                                    SELECT dr.*, fs.pump_number, u.name as staff_name
                                    FROM fuel_daily_readings dr
                                    LEFT JOIN fuel_stations fs ON dr.fuel_station_id = fs.id
                                    LEFT JOIN users u ON dr.user_id = u.id
                                    WHERE dr.station_id = ? AND dr.reading_date = ? 
                                    AND fs.fuel_type = ?
                                    ORDER BY dr.shift, fs.pump_number
                                ");
                                $stmt->execute([$variance['station_id'], $variance['report_date'], $variance['fuel_type']]);
                                $readings = $stmt->fetchAll();
                                
                                if ($readings):
                            ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Pump</th>
                                            <th>Shift</th>
                                            <th>Sales</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($readings as $reading): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($reading['pump_number']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo $reading['shift']; ?></span></td>
                                            <td><?php echo number_format($reading['sales_liters'], 2); ?>L</td>
                                            <td><span class="badge bg-<?php echo strtolower($reading['status']) == 'verified' ? 'success' : 'warning'; ?>"><?php echo $reading['status']; ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <p class="text-muted">No readings found for this date.</p>
                            <?php endif; ?>
                            <?php } catch (Exception $e) { echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>'; } ?>
                        </div>
                        
                        <!-- Deliveries -->
                        <div class="col-md-6">
                            <h6>🚛 Deliveries</h6>
                            <?php
                                $stmt = $pdo->prepare("
                                    SELECT * FROM fuel_deliveries 
                                    WHERE station_id = ? AND delivery_date = ? 
                                    AND fuel_type = ?
                                    ORDER BY created_at
                                ");
                                $stmt->execute([$variance['station_id'], $variance['report_date'], $variance['fuel_type']]);
                                $deliveries = $stmt->fetchAll();
                                
                                if ($deliveries):
                            ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Supplier</th>
                                            <th>Liters</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($deliveries as $delivery): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($delivery['supplier']); ?></td>
                                            <td><?php echo number_format($delivery['delivery_liters'], 2); ?>L</td>
                                            <td><span class="badge bg-<?php echo strtolower($delivery['status']) == 'verified' ? 'success' : 'warning'; ?>"><?php echo $delivery['status']; ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <p class="text-muted">No deliveries on this date.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Adjustments -->
                    <?php
                        $stmt = $pdo->prepare("
                            SELECT a.*, u.name as staff_name
                            FROM fuel_adjustments a
                            LEFT JOIN users u ON a.user_id = u.id
                            WHERE a.station_id = ? AND a.adjustment_date = ? 
                            AND a.fuel_type = ?
                            ORDER BY a.created_at
                        ");
                        $stmt->execute([$variance['station_id'], $variance['report_date'], $variance['fuel_type']]);
                        $adjustments = $stmt->fetchAll();
                        
                        if ($adjustments):
                    ?>
                    <div class="mt-3">
                        <h6>⚖️ Adjustments</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($adjustments as $adj): ?>
                                    <tr>
                                        <td><span class="badge bg-<?php echo $adj['adjustment_type'] == 'Loss' ? 'danger' : 'info'; ?>"><?php echo $adj['adjustment_type']; ?></span></td>
                                        <td class="<?php echo $adj['adjustment_type'] == 'Loss' ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo ($adj['adjustment_type'] == 'Loss' ? '-' : '+') . number_format($adj['liters'], 2); ?>L
                                        </td>
                                        <td><?php echo htmlspecialchars($adj['reason']); ?></td>
                                        <td><span class="badge bg-<?php echo strtolower($adj['status']) == 'approved' ? 'success' : 'warning'; ?>"><?php echo $adj['status']; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="modal-footer">
            <?php if (in_array($variance['status'], ['Open', 'Under Investigation']) && $isAdmin): ?>
                <button type="button" class="btn btn-primary" onclick="openInvestigateVarianceModal(<?php echo $variance['id']; ?>)">
                    <i class="fas fa-search"></i> Investigate
                </button>
            <?php endif; ?>
            <?php if ($variance['status'] === 'Under Investigation' && $isAdmin): ?>
                <button type="button" class="btn btn-success" onclick="approveVarianceReport(<?php echo $variance['id']; ?>)">
                    <i class="fas fa-check"></i> Approve/Close
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').classList.remove('show')">
                <i class="fas fa-times"></i> Close
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
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 5px;
}
.metric-label {
    font-size: 11px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
}
</style>

<script>
// Make closeModal globally accessible
window.closeModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
    }
};

// Alternative close modal function
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
    }
}

// Initialize event listeners when modal loads
document.addEventListener('DOMContentLoaded', function() {
    // Bind close buttons
    const closeBtns = document.querySelectorAll('.btn-close');
    closeBtns.forEach(btn => {
        btn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            const modal = this.closest('.modal');
            if (modal) {
                modal.classList.remove('show');
            }
        };
    });
    
    // Bind footer close button
    const footerCloseBtn = document.querySelector('button[onclick*="closeModal"]');
    if (footerCloseBtn) {
        footerCloseBtn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            const modal = this.closest('.modal');
            if (modal) {
                modal.classList.remove('show');
            }
        };
    }
});

function approveVarianceReport(id) {
    if (confirm('Are you sure you want to approve/close this variance report? This will mark it as resolved.')) {
        const formData = new FormData();
        formData.append('action', 'approve_variance');
        formData.append('id', id);
        formData.append('status', 'Resolved');
        formData.append('notes', 'Variance report approved and closed by ' + <?php echo json_encode($me['name']); ?>);
        
        fetch('../backend/fuel_process_verification.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Variance report approved and closed successfully!');
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Unknown error occurred'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Network error occurred. Please try again.');
        });
    }
}
</script>
