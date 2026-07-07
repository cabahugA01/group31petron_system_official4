<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$user = current_user();
$station_id = user_station_id();
$delivery_id = (int)($_GET['id'] ?? 0);

$delivery = null;
if ($delivery_id > 0) {
    $stmt = $pdo->prepare("
        SELECT d.*, u.name as receiver_name, v.name as verifier_name
        FROM fuel_deliveries d
        LEFT JOIN users u ON d.received_by = u.id
        LEFT JOIN users v ON d.verified_by = v.id
        WHERE d.id = ? AND d.station_id = ?
    ");
    $stmt->execute([$delivery_id, $station_id]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
}

function getStatusColor($status) {
    switch($status) {
        case 'Verified': return '#dcfce7';
        case 'Pending Review': return '#fef3c7';
        case 'Pending': return '#fef3c7';
        case 'Rejected': return '#fee2e2';
        case 'Finalized': return '#dbeafe';
        default: return '#f1f5f9';
    }
}
function getStatusTextColor($status) {
    switch($status) {
        case 'Verified': return '#15803d';
        case 'Pending Review': return '#92400e';
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
    <title>Fuel Delivery Details</title>
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
        .volume-highlight {
            background: #eff6ff;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            margin-top: 16px;
        }
        .volume-highlight .label { font-size: 12px; color: #64748b; }
        .volume-highlight .value { font-size: 24px; font-weight: 700; color: #2563eb; }
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
            <h3><i class="fas fa-truck"></i> Fuel Delivery Details</h3>
            <button class="close-btn" onclick="window.close()">&times;</button>
        </div>
        
        <div class="modal-body">
            <?php if ($delivery): ?>
                <div class="info-grid">
                    <div class="info-card">
                        <h4><i class="fas fa-calendar"></i> Delivery Info</h4>
                        <div class="info-row">
                            <span class="info-label">Date</span>
                            <span class="info-value"><?php echo date('M d, Y', strtotime($delivery['delivery_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Fuel Type</span>
                            <span class="info-value"><?php echo htmlspecialchars($delivery['fuel_type']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status</span>
                            <span class="status-badge" style="background: <?php echo getStatusColor($delivery['status']); ?>; color: <?php echo getStatusTextColor($delivery['status']); ?>;">
                                <?php echo htmlspecialchars($delivery['status']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <h4><i class="fas fa-building"></i> Supplier Info</h4>
                        <div class="info-row">
                            <span class="info-label">Supplier</span>
                            <span class="info-value"><?php echo htmlspecialchars($delivery['supplier']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Invoice No.</span>
                            <span class="info-value"><?php echo htmlspecialchars($delivery['invoice_no'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Tanker No.</span>
                            <span class="info-value"><?php echo htmlspecialchars($delivery['tanker_number'] ?: 'N/A'); ?></span>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <h4><i class="fas fa-user"></i> Personnel</h4>
                        <div class="info-row">
                            <span class="info-label">Received By</span>
                            <span class="info-value"><?php echo htmlspecialchars($delivery['receiver_name'] ?? 'Unknown'); ?></span>
                        </div>
                        <?php if ($delivery['verified_by']): ?>
                        <div class="info-row">
                            <span class="info-label">Verified By</span>
                            <span class="info-value"><?php echo htmlspecialchars($delivery['verifier_name']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="info-card">
                        <h4><i class="fas fa-clock"></i> Timestamps</h4>
                        <div class="info-row">
                            <span class="info-label">Recorded</span>
                            <span class="info-value"><?php echo $delivery['created_at'] ? date('M d, H:i', strtotime($delivery['created_at'])) : 'N/A'; ?></span>
                        </div>
                        <?php if ($delivery['verified_at']): ?>
                        <div class="info-row">
                            <span class="info-label">Verified</span>
                            <span class="info-value" style="color: #059669;"><?php echo date('M d, H:i', strtotime($delivery['verified_at'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="volume-highlight">
                    <div class="label">Delivery Volume</div>
                    <div class="value"><?php echo number_format($delivery['delivery_liters'], 2); ?> Liters</div>
                </div>
                
                <?php if ($delivery['notes']): ?>
                <div class="notes">
                    <div class="notes-title">Notes</div>
                    <div class="notes-content"><?php echo nl2br(htmlspecialchars($delivery['notes'])); ?></div>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Delivery not found.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="window.close()">Close</button>
        </div>
    </div>
</body>
</html>
