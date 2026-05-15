<?php
/**
 * FUEL DELIVERY FINALIZATION
 * Admin page for finalizing verified fuel deliveries
 * 
 * Workflow:
 * 1. Displays deliveries with status "Verified" (awaiting admin finalization)
 * 2. Admin can view details and finalize or reject
 * 3. On finalization, automatic stock update to fuel_inventory is triggered
 * 4. Delivery moves to "Finalized" status (workflow complete)
 */

$page_id = 'fuel_delivery_finalize';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/fuel_delivery_operations.php';

require_login();

$me = current_user();
$isSuper = ($me['role'] ?? '') === 'superadmin';
$isAdmin = in_array($me['role'], ['admin', 'superadmin']);

// Verify authorization
if (!$isAdmin) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$station_id = $isSuper ? ($_GET['station'] ?? '') : user_station_id();
$msg = '';

// Get stations for dropdown if superadmin
$stations = [];
if ($isSuper) {
    try {
        $stations = $pdo->query("SELECT id, name FROM stations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {}
}

// Initialize operations class
$fuelOps = new FuelDeliveryOperations($pdo, $me);

// Handle finalization/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'finalize_delivery') {
        $delivery_id = (int)$_POST['delivery_id'];
        $finalization_remarks = $_POST['finalization_remarks'] ?? '';
        
        if ($delivery_id) {
            $result = $fuelOps->finalize_delivery($delivery_id, $me['id'], $finalization_remarks);
            if ($result['success']) {
                $msg = "✅ Delivery #$delivery_id finalized successfully. Stock updated: {$result['quantity_before']}L → {$result['quantity_after']}L";
            } else {
                $msg = "❌ " . $result['message'];
            }
        } else {
            $msg = "❌ Invalid delivery ID.";
        }
    } elseif ($action === 'reject_delivery') {
        $delivery_id = (int)$_POST['delivery_id'];
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        if ($delivery_id && $rejection_reason) {
            $result = $fuelOps->reject_delivery($delivery_id, $me['id'], $rejection_reason);
            $msg = $result['success'] ? 
                "✅ {$result['message']}" : 
                "❌ {$result['message']}";
        } else {
            $msg = "❌ Rejection reason is required.";
        }
    }
}

// Fetch verified deliveries (status = 'Verified')
$deliveries = [];
if ($station_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                fd.*,
                s.name as supplier_name,
                u_received.name as received_by_name,
                u_verified.name as verified_by_name
            FROM fuel_deliveries fd
            LEFT JOIN suppliers s ON fd.supplier_id = s.id
            LEFT JOIN users u_received ON fd.received_by = u_received.id
            LEFT JOIN users u_verified ON fd.verified_by = u_verified.id
            WHERE fd.station_id = ? 
            AND fd.status = 'Verified'
            ORDER BY fd.verified_at DESC, fd.delivery_date DESC
        ");
        $stmt->execute([$station_id]);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $msg = "❌ Error fetching deliveries: " . $e->getMessage();
    }
}

// Get fuel products for reference
$fuel_products = [];
try {
    $fuel_products = $pdo->query("SELECT id, name FROM products WHERE type_id = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finalize Fuel Deliveries</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 14px;
        }
        
        .controls {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .controls select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
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
        
        .deliveries-list {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .delivery-card {
            border-bottom: 1px solid #eee;
            padding: 20px;
            transition: background 0.2s;
        }
        
        .delivery-card:hover {
            background: #f9f9f9;
        }
        
        .delivery-card:last-child {
            border-bottom: none;
        }
        
        .delivery-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        
        .delivery-id {
            font-weight: bold;
            color: #333;
            font-size: 16px;
        }
        
        .delivery-status {
            display: inline-block;
            padding: 4px 8px;
            background: #d1ecf1;
            color: #0c5460;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .delivery-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .detail-item {
            padding: 10px;
            background: #f5f5f5;
            border-left: 3px solid #667eea;
            border-radius: 4px;
        }
        
        .detail-label {
            font-size: 12px;
            color: #666;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .detail-value {
            font-size: 14px;
            color: #333;
            word-break: break-word;
        }
        
        .verification-info {
            padding: 15px;
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 13px;
        }
        
        .verification-info strong {
            display: block;
            margin-bottom: 5px;
            color: #1976D2;
        }
        
        .delivery-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .btn-finalize {
            background: #007bff;
            color: white;
        }
        
        .btn-finalize:hover {
            background: #0056b3;
        }
        
        .btn-reject {
            background: #dc3545;
            color: white;
        }
        
        .btn-reject:hover {
            background: #c82333;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            min-width: 400px;
            max-width: 600px;
        }
        
        .modal-header {
            font-size: 20px;
            font-weight: bold;
            color: #333;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
            font-size: 14px;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
            min-height: 100px;
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .btn-cancel {
            background: #ccc;
            color: #333;
        }
        
        .btn-cancel:hover {
            background: #bbb;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🔒 Finalize Fuel Deliveries</h1>
            <p>Complete the delivery workflow by finalizing verified deliveries and updating inventory</p>
        </div>
        
        <!-- Controls -->
        <div class="controls">
            <?php if ($isSuper): ?>
                <select id="stationFilter" onchange="location.href='?station=' + this.value">
                    <option value="">-- Select Station --</option>
                    <?php foreach ($stations as $id => $name): ?>
                        <option value="<?= htmlspecialchars($id) ?>" <?= $station_id === $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            
            <a href="fuel_management.php" style="padding: 8px 16px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px; font-size: 14px;">← Back</a>
        </div>
        
        <!-- Messages -->
        <?php if ($msg): ?>
            <div class="alert <?= strpos($msg, '✅') === 0 ? 'alert-success' : 'alert-error' ?>">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>
        
        <!-- Deliveries List -->
        <div class="deliveries-list">
            <?php if (empty($deliveries)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <h3>No Pending Deliveries for Finalization</h3>
                    <p>All verified deliveries have been finalized or there are no deliveries awaiting finalization.</p>
                </div>
            <?php else: ?>
                <?php foreach ($deliveries as $delivery): ?>
                    <div class="delivery-card">
                        <div class="delivery-header">
                            <div>
                                <div class="delivery-id">Delivery #<?= $delivery['id'] ?></div>
                                <small style="color: #999;">Created by <?= htmlspecialchars($delivery['received_by_name'] ?? 'Unknown') ?> on <?= date('M d, Y H:i', strtotime($delivery['created_at'])) ?></small>
                            </div>
                            <span class="delivery-status">AWAITING FINALIZATION</span>
                        </div>
                        
                        <!-- Verification Info -->
                        <div class="verification-info">
                            <strong>✓ Verified by <?= htmlspecialchars($delivery['verified_by_name'] ?? 'Unknown') ?></strong>
                            on <?= date('M d, Y H:i', strtotime($delivery['verified_at'])) ?>
                            <?php if ($delivery['notes']): ?>
                                <br>Notes: <?= htmlspecialchars(substr($delivery['notes'], 0, 100)) ?>...
                            <?php endif; ?>
                        </div>
                        
                        <div class="delivery-details">
                            <div class="detail-item">
                                <div class="detail-label">Supplier</div>
                                <div class="detail-value"><?= htmlspecialchars($delivery['supplier_name'] ?? 'Unknown') ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Fuel Type</div>
                                <div class="detail-value"><?= htmlspecialchars($delivery['fuel_type'] ?? 'Unknown') ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Quantity</div>
                                <div class="detail-value"><strong style="color: #28a745; font-size: 16px;"><?= number_format($delivery['delivery_liters'], 2) ?> L</strong></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Invoice No</div>
                                <div class="detail-value"><?= htmlspecialchars($delivery['invoice_no'] ?? 'N/A') ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Tanker Number</div>
                                <div class="detail-value"><?= htmlspecialchars($delivery['tanker_number'] ?? 'N/A') ?></div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Delivery Date</div>
                                <div class="detail-value"><?= date('M d, Y', strtotime($delivery['delivery_date'])) ?></div>
                            </div>
                        </div>
                        
                        <div class="delivery-actions">
                            <button class="btn btn-finalize" onclick="openFinalizeModal(<?= $delivery['id'] ?>)">
                                ✓ Finalize & Update Stock
                            </button>
                            <button class="btn btn-reject" onclick="openRejectModal(<?= $delivery['id'] ?>)">
                                ✗ Reject Delivery
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Finalize Modal -->
    <div id="finalizeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">✓ Finalize Delivery</div>
            
            <form method="POST">
                <input type="hidden" name="action" value="finalize_delivery">
                <input type="hidden" name="delivery_id" id="finalizeDeliveryId">
                
                <div class="form-group">
                    <label for="finalizationRemarks">Finalization Remarks (Optional)</label>
                    <textarea name="finalization_remarks" id="finalizationRemarks" placeholder="Enter any final remarks or notes..."></textarea>
                </div>
                
                <div style="background: #fff3cd; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 13px; color: #856404;">
                    <strong>⚠️ Important:</strong> Finalizing this delivery will immediately update the fuel inventory stock. This action is recorded in the audit trail.
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeModals()">Cancel</button>
                    <button type="submit" class="btn btn-finalize">Finalize Delivery</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">✗ Reject Delivery</div>
            
            <form method="POST">
                <input type="hidden" name="action" value="reject_delivery">
                <input type="hidden" name="delivery_id" id="rejectDeliveryId">
                
                <div class="form-group">
                    <label for="rejectionReason">Reason for Rejection <span style="color: red;">*</span></label>
                    <textarea name="rejection_reason" id="rejectionReason" placeholder="Explain why this delivery is being rejected..." required></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeModals()">Cancel</button>
                    <button type="submit" class="btn btn-reject">Reject Delivery</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openFinalizeModal(deliveryId) {
            document.getElementById('finalizeDeliveryId').value = deliveryId;
            document.getElementById('finalizeModal').classList.add('active');
        }
        
        function openRejectModal(deliveryId) {
            document.getElementById('rejectDeliveryId').value = deliveryId;
            document.getElementById('rejectModal').classList.add('active');
        }
        
        function closeModals() {
            document.getElementById('finalizeModal').classList.remove('active');
            document.getElementById('rejectModal').classList.remove('active');
            document.getElementById('finalizationRemarks').value = '';
            document.getElementById('rejectionReason').value = '';
        }
        
        // Close modals when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                closeModals();
            }
        });
    </script>
</body>
</html>
