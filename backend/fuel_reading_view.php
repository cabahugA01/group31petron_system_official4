<?php
/**
 * Backend Modal: Fuel Reading View (Staff)
 * Displays reading details in read-only mode for staff
 */

require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

require_login();
$me        = current_user();
$userRole  = role_key($me['role'] ?? '');
$isManager = in_array($userRole, ['manager', 'admin', 'superadmin']);

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
        WHERE dr.id = ?
    ");
    $stmt->execute([$id]);
    $reading = $stmt->fetch();
    
    if (!$reading) {
        echo '<div class="alert alert-danger">Reading not found.</div>';
        exit;
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

// Status badge color
$statusClass = strtolower($reading['status']);
$statusColors = [
    'pending_review' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger'
];
$badgeColor = $statusColors[$statusClass] ?? 'secondary';
?>

<style>
.modal-reading-view {
    padding: 24px;
}
.modal-reading-view .reading-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e0e0e0;
}
.modal-reading-view .reading-header h4 {
    margin: 0;
    color: #333;
}
.modal-reading-view .status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.modal-reading-view .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}
.modal-reading-view .info-card {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
}
.modal-reading-view .info-card label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 5px;
}
.modal-reading-view .info-card .value {
    font-size: 16px;
    font-weight: 600;
    color: #333;
}
.modal-reading-view .metrics-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
    margin: 20px 0;
}
.modal-reading-view .metric-box {
    background: #fff;
    border: 1px solid #e0e0e0;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    min-width: 0;
}
.modal-reading-view .metric-box .metric-value {
    font-size: clamp(28px, 2.2vw, 36px);
    font-weight: 700;
    color: #003366;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
.modal-reading-view .metric-box .metric-label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
    margin-top: 5px;
}
.modal-reading-view .metric-box.highlight {
    background: #e3f2fd;
    border-color: #1976d2;
}
.modal-reading-view .metric-box.highlight .metric-value {
    color: #1976d2;
}
.modal-reading-view .notes-section {
    background: #fff8e1;
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
    border-left: 4px solid #ffc107;
}
.modal-reading-view .notes-section label {
    font-size: 12px;
    color: #666;
    font-weight: 600;
}
.modal-reading-view .notes-section p {
    margin: 5px 0 0 0;
    color: #333;
}
.modal-reading-view .modal-actions {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

@media (max-width: 992px) {
    .modal-reading-view .metrics-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 576px) {
    .modal-reading-view {
        padding: 16px;
    }
    .modal-reading-view .info-grid,
    .modal-reading-view .metrics-row {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="modal-reading-view">
    <div class="reading-header">
        <h4><i class="fas fa-gas-pump"></i> Pump Reading Details</h4>
        <span class="status-badge badge-<?php echo $badgeColor; ?>">
            <?php echo htmlspecialchars($reading['status']); ?>
        </span>
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

    <?php if ($reading['status'] === 'Rejected' && $reading['notes']): ?>
    <div class="notes-section" style="background: #ffebee; border-left-color: #f44336;">
        <label style="color: #c62828;"><i class="fas fa-times-circle"></i> Rejection Reason</label>
        <p><?php echo nl2br(htmlspecialchars($reading['notes'])); ?></p>
    </div>
    <?php endif; ?>

    <div class="modal-actions">
        <?php if (in_array($reading['status'], ['Pending Review', 'Pending']) && $isManager): ?>
        <button type="button" class="btn primary" style="background: var(--blue);" onclick="document.getElementById('modalViewReading').classList.remove('show'); setTimeout(function(){ openReviewReadingModal(<?php echo $reading['id']; ?>); }, 150);">
            <i class="fas fa-check-circle"></i> Verify Reading
        </button>
        <?php endif; ?>
        <button type="button" class="btn" onclick="document.getElementById('modalViewReading').classList.remove('show')">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</div>
