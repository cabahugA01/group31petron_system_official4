<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$user = current_user();
$station_id = user_station_id();
$reading_id = (int)($_GET['id'] ?? 0);

$reading = null;
if ($reading_id > 0) {
    $stmt = $pdo->prepare("
        SELECT dr.*, fs.pump_number, fs.fuel_type, u.name as user_name
        FROM fuel_daily_readings dr
        LEFT JOIN fuel_stations fs ON dr.fuel_station_id = fs.id
        LEFT JOIN users u ON dr.user_id = u.id
        WHERE dr.id = ? AND dr.station_id = ?
    ");
    $stmt->execute([$reading_id, $station_id]);
    $reading = $stmt->fetch(PDO::FETCH_ASSOC);
}

function getStatusColor($status) {
    switch($status) {
        case 'Verified': return '#dcfce7';
        case 'Pending': return '#fef3c7';
        case 'Rejected': return '#fee2e2';
        case 'Finalized': return '#dbeafe';
        default: return '#f1f5f9';
    }
}
function getStatusTextColor($status) {
    switch($status) {
        case 'Verified': return '#15803d';
        case 'Pending': return '#92400e';
        case 'Rejected': return '#dc2626';
        case 'Finalized': return '#1e40af';
        default: return '#64748b';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Reading Details</title>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: rgba(0,0,0,0.5); 
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-container {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 700px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .modal-header {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { font-size: 18px; font-weight: 600; }
        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            opacity: 0.8;
        }
        .close-btn:hover { opacity: 1; }
        .modal-body { padding: 24px; }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .info-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
        }
        .info-card h4 {
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .info-label { color: #64748b; }
        .info-value { font-weight: 500; color: #0f172a; }
        .status-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .sales-highlight {
            background: #eff6ff;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            margin-top: 16px;
        }
        .sales-highlight .label { font-size: 12px; color: #64748b; }
        .sales-highlight .value { font-size: 24px; font-weight: 700; color: #2563eb; }
        .notes {
            background: #fef3c7;
            border-left: 3px solid #f59e0b;
            padding: 12px;
            border-radius: 0 8px 8px 0;
            margin-top: 16px;
        }
        .notes-title { font-weight: 600; color: #92400e; font-size: 13px; margin-bottom: 4px; }
        .notes-content { font-size: 13px; color: #78350f; }
        .empty-state { text-align: center; padding: 40px; color: #64748b; }
        .empty-state i { font-size: 48px; color: #ef4444; margin-bottom: 16px; }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            text-align: right;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }
        .btn-secondary { background: #f1f5f9; color: #64748b; }
        .btn-secondary:hover { background: #e2e8f0; }
    </style>
</head>
<body>
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="fas fa-gas-pump"></i> Fuel Reading Details</h3>
            <button class="close-btn" onclick="window.close()">&times;</button>
        </div>
        
        <div class="modal-body">
            <?php if ($reading): ?>
                <div class="info-grid">
                    <div class="info-card">
                        <h4><i class="fas fa-calendar"></i> Reading Info</h4>
                        <div class="info-row">
                            <span class="info-label">Date</span>
                            <span class="info-value"><?php echo date('M d, Y', strtotime($reading['reading_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Shift</span>
                            <span class="info-value"><?php echo htmlspecialchars($reading['shift']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status</span>
                            <span class="status-badge" style="background: <?php echo getStatusColor($reading['status']); ?>; color: <?php echo getStatusTextColor($reading['status']); ?>;">
                                <?php echo htmlspecialchars($reading['status']); ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Recorded By</span>
                            <span class="info-value"><?php echo htmlspecialchars($reading['user_name'] ?? 'Unknown'); ?></span>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <h4><i class="fas fa-gas-pump"></i> Pump Info</h4>
                        <div class="info-row">
                            <span class="info-label">Pump #</span>
                            <span class="info-value"><?php echo htmlspecialchars($reading['pump_number'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Fuel Type</span>
                            <span class="info-value"><?php echo htmlspecialchars($reading['fuel_type'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <h4><i class="fas fa-calculator"></i> Meter Readings</h4>
                        <div class="info-row">
                            <span class="info-label">Previous</span>
                            <span class="info-value"><?php echo number_format($reading['previous_reading'], 2); ?> L</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Current</span>
                            <span class="info-value"><?php echo number_format($reading['current_reading'], 2); ?> L</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Calibration</span>
                            <span class="info-value"><?php echo number_format($reading['calibration'] ?? 0, 2); ?> L</span>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <h4><i class="fas fa-clock"></i> Timestamps</h4>
                        <div class="info-row">
                            <span class="info-label">Created</span>
                            <span class="info-value"><?php echo $reading['created_at'] ? date('M d, H:i', strtotime($reading['created_at'])) : 'N/A'; ?></span>
                        </div>
                        <?php if ($reading['verified_at']): ?>
                        <div class="info-row">
                            <span class="info-label">Verified</span>
                            <span class="info-value" style="color: #059669;"><?php echo date('M d, H:i', strtotime($reading['verified_at'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="sales-highlight">
                    <div class="label">Total Sales</div>
                    <div class="value"><?php echo number_format($reading['sales_liters'], 2); ?> Liters</div>
                </div>
                
                <?php if ($reading['notes']): ?>
                <div class="notes">
                    <div class="notes-title">Notes</div>
                    <div class="notes-content"><?php echo nl2br(htmlspecialchars($reading['notes'])); ?></div>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Reading not found.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="window.close()">Close</button>
        </div>
    </div>
</body>
</html>
