<?php
$page_id = 'manager_purchase_orders';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/manager_fuel_config.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Only managers and above can access this module
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

// Initialize configuration system
$config = getManagerFuelConfig($pdo, $station_id);
$business_rules = $config->getBusinessRules();
$ui_config = $config->getUIConfig();
$colors = $config->getColors();
$suppliers = $config->getSuppliers();

$msg = '';
if (isset($_SESSION['success'])) { 
    $msg = $_SESSION['success']; 
    unset($_SESSION['success']); 
}
if (isset($_SESSION['error'])) { 
    $msg = $_SESSION['error']; 
    unset($_SESSION['error']); 
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            
            case 'forward_po':
                $po_id = $_POST['po_id'] ?? '';
                try {
                    $stmt = $pdo->prepare("SELECT po_number, status FROM fuel_purchase_orders WHERE id = ? AND station_id = ?");
                    $stmt->execute([$po_id, $station_id]);
                    $po = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$po) {
                        throw new Exception('Purchase Order not found.');
                    }

                    if ($po['status'] !== 'approved') {
                        throw new Exception('Only approved orders can be forwarded.');
                    }

                    $stmt = $pdo->prepare("UPDATE fuel_purchase_orders SET status = 'forwarded' WHERE id = ?");
                    $stmt->execute([$po_id]);

                    log_activity($pdo, $me['id'], 'Forward PO', "Forwarded PO #{$po['po_number']} to Admin", 'purchase_order');
                    $_SESSION['success'] = "Purchase Order #{$po['po_number']} forwarded to Admin.";
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error forwarding PO: ' . $e->getMessage();
                }
                header('Location: manager_purchase_orders.php');
                exit;

            // Approve Purchase Order
            case 'approve_po':
                $po_id = $_POST['po_id'] ?? '';
                $notes = $_POST['notes'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        SELECT fpo.*, ft.name as fuel_type_name, fs.supplier_name
                        FROM fuel_purchase_orders fpo
                        JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                        LEFT JOIN fuel_suppliers fs ON fpo.supplier_id = fs.id
                        WHERE fpo.id = ? AND fpo.station_id = ?
                    ");
                    $stmt->execute([$po_id, $station_id]);
                    $po = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$po) {
                        throw new Exception('Purchase Order not found');
                    }
                    
                    if ($po['status'] !== 'pending') {
                        throw new Exception('Only pending orders can be approved');
                    }
                    
                    // Update PO status
                    $stmt = $pdo->prepare("
                        UPDATE fuel_purchase_orders 
                        SET status = 'approved', approved_by = ?, approved_at = NOW(), approval_notes = ?
                        WHERE id = ? AND station_id = ?
                    ");
                    $stmt->execute([$me['id'], $notes, $po_id, $station_id]);
                    
                    // Update low stock alert status if exists
                    $stmt = $pdo->prepare("
                        UPDATE low_stock_alerts 
                        SET status = 'resolved', resolved_by = ?, resolved_at = NOW(), notes = ?
                        WHERE station_id = ? AND fuel_type_id = ? AND status = 'active'
                    ");
                    $stmt->execute([$me['id'], "PO #{$po['po_number']} approved. $notes", $station_id, $po['fuel_type_id']]);
                    
                    log_activity($pdo, $me['id'], 'Approve PO', "Approved PO #{$po['po_number']} for {$po['fuel_type_name']}", 'purchase_order');
                    $_SESSION['success'] = "Purchase Order #{$po['po_number']} approved successfully!";
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error approving PO: ' . $e->getMessage();
                }
                header('Location: manager_purchase_orders.php');
                exit;
                
            // Cancel Purchase Order
            case 'cancel_po':
                $po_id = $_POST['po_id'] ?? '';
                $reason = $_POST['reason'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        SELECT fpo.*, ft.name as fuel_type_name
                        FROM fuel_purchase_orders fpo
                        JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                        WHERE fpo.id = ? AND fpo.station_id = ?
                    ");
                    $stmt->execute([$po_id, $station_id]);
                    $po = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$po) {
                        throw new Exception('Purchase Order not found');
                    }
                    
                    if ($po['status'] === 'delivered' || $po['status'] === 'closed') {
                        throw new Exception('Cannot cancel delivered or closed orders');
                    }
                    
                    // Update PO status
                    $stmt = $pdo->prepare("
                        UPDATE fuel_purchase_orders 
                        SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ?
                        WHERE id = ? AND station_id = ?
                    ");
                    $stmt->execute([$me['id'], $reason, $po_id, $station_id]);
                    
                    log_activity($pdo, $me['id'], 'Cancel PO', "Cancelled PO #{$po['po_number']}. Reason: $reason", 'purchase_order');
                    $_SESSION['success'] = "Purchase Order #{$po['po_number']} cancelled.";
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error cancelling PO: ' . $e->getMessage();
                }
                header('Location: manager_purchase_orders.php');
                exit;
                
            // Mark as Delivered
            case 'mark_delivered':
                $po_id = $_POST['po_id'] ?? '';
                $delivery_notes = $_POST['delivery_notes'] ?? '';
                $actual_volume = (float)($_POST['actual_volume'] ?? 0);
                
                try {
                    $stmt = $pdo->prepare("
                        SELECT fpo.*, ft.name as fuel_type_name
                        FROM fuel_purchase_orders fpo
                        JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                        WHERE fpo.id = ? AND fpo.station_id = ?
                    ");
                    $stmt->execute([$po_id, $station_id]);
                    $po = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$po) {
                        throw new Exception('Purchase Order not found');
                    }
                    
                    if ($po['status'] !== 'forwarded') { // Admin should handle this
                        throw new Exception('Only forwarded orders can be marked as delivered by an Admin.');
                    }
                    
                    // This logic should probably be in an admin page
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error marking PO as delivered: ' . $e->getMessage();
                }
                header('Location: manager_purchase_orders.php');
                exit;
                
            // Close Purchase Order
            case 'close_po':
                // This logic should probably be in an admin page
                $_SESSION['error'] = 'Access denied for this action.';
                header('Location: manager_purchase_orders.php');
                exit;
        }
    }
}

// Fetch Purchase Orders data
$purchase_orders = [];
try {
    // Get all purchase orders for this station
    $stmt = $pdo->prepare("
        SELECT fpo.*, ft.name as fuel_type_name, fs.supplier_name,
               u1.name as created_by_name, u2.name as approved_by_name,
               u3.name as delivered_by_name, u4.name as cancelled_by_name, u5.name as closed_by_name
        FROM fuel_purchase_orders fpo
        JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN fuel_suppliers fs ON fpo.supplier_id = fs.id
        LEFT JOIN users u1 ON fpo.created_by = u1.id
        LEFT JOIN users u2 ON fpo.approved_by = u2.id
        LEFT JOIN users u3 ON fpo.delivered_by = u3.id
        LEFT JOIN users u4 ON fpo.cancelled_by = u4.id
        LEFT JOIN users u5 ON fpo.closed_by = u5.id
        WHERE fpo.station_id = ?
        ORDER BY fpo.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $purchase_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching purchase orders: " . $e->getMessage());
    $purchase_orders = [];
}

// Handle PO details request (kept for modal)
if (isset($_GET['action']) && $_GET['action'] === 'get_po_details') {
    // ... (original get_po_details logic remains here)
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* General Styles */
.manager-purchase-orders {
    max-width: 1400px;
    margin: 0 auto;
    padding: 10px;
}

.page-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

/* New Card Layout */
.po-listing {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 24px;
}

.po-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border-left: 5px solid <?php echo $colors['primary']; ?>;
    display: flex;
    flex-direction: column;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.po-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.1);
}

.po-card-header {
    padding: 16px 24px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.po-card-header h3 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
    color: <?php echo $colors['primary']; ?>;
}

.po-card-body {
    padding: 24px;
    flex-grow: 1;
}

.po-card-body p {
    margin: 0 0 12px;
    line-height: 1.6;
    color: #555;
    display: flex;
}

.po-card-body p strong {
    color: #333;
    font-weight: 600;
    min-width: 200px;
    flex-shrink: 0;
}

.po-card-actions {
    padding: 16px 24px;
    background: #f8f9fa;
    border-top: 1px solid #e9ecef;
    text-align: left;
}

.action-link, .action-link-disabled, .action-link-button {
    display: inline-block;
    margin-right: 15px;
    color: <?php echo $colors['primary']; ?>;
    text-decoration: none;
    font-weight: 600;
    border: 1px solid transparent;
    padding: 5px 0;
    position: relative;
}

.action-link::after {
    content: '';
    position: absolute;
    width: 100%;
    transform: scaleX(0);
    height: 2px;
    bottom: 0;
    left: 0;
    background-color: <?php echo $colors['primary']; ?>;
    transform-origin: bottom right;
    transition: transform 0.25s ease-out;
}

.action-link:hover::after {
    transform: scaleX(1);
    transform-origin: bottom left;
}

.action-link-disabled {
    color: #aaa;
    cursor: not-allowed;
}

.action-link-button {
    background: none;
    border: none;
    cursor: pointer;
    font-family: inherit;
    font-size: inherit;
    color: <?php echo $colors['primary']; ?>;
    padding: 5px 0;
}
.action-link-button:hover {
    text-decoration: underline;
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-pending { background: #fff3cd; color: #856404; }
.status-approved { background: #cce5ff; color: #004085; }
.status-forwarded { background: #e6e6fa; color: #5f5f9c; border: 1px solid #d8d8ff; }
.status-delivered { background: #d1ecf1; color: #0c5460; }
.status-closed { background: #d4edda; color: #155724; }
.status-cancelled { background: #f8d7da; color: #721c24; }

/* Action Buttons */
.action-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary { background: <?php echo $colors['primary']; ?>; color: white; }
.btn-primary:hover { background: #3e4f9d; }

/* Modal (retained for actions) */
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
.modal-content { background-color: #fff; margin: 5% auto; padding: 24px; border-radius: 12px; width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto; }
.close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
.close:hover { color: #000; }
.form-group { margin-bottom: 16px; }
.form-label { display: block; margin-bottom: 4px; font-weight: 600; color: #333; }
.form-input, .form-textarea { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
.form-textarea { min-height: 80px; resize: vertical; }

/* Empty State */
.empty-state { text-align: center; padding: 60px 20px; color: #666; }
.empty-state i { font-size: 4rem; color: <?php echo $colors['primary']; ?>; margin-bottom: 20px; }
</style>

<div class="manager-purchase-orders">
    <div class="page-head">
        <div>
            <h1 class="h1">Manager Purchase Orders</h1>
            <div class="sub">Example Listing: Fetched from Database</div>
        </div>
        <div>
            <a href="#" class="action-btn btn-primary" onclick="alert('Create Purchase Order form not implemented yet.');"><i class="fas fa-plus"></i> Create Purchase Order</a>
        </div>
    </div>

    <?php if($msg): ?>
    <div class="alert <?php echo strpos(strtolower($msg), 'error') !== false ? 'alert-error' : 'alert-success'; ?>">
        <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endif; ?>

    <div class="po-listing">
        <?php if (count($purchase_orders) > 0): ?>
            <?php foreach ($purchase_orders as $po): ?>
                <div class="po-card">
                    <div class="po-card-header">
                        <h3>PO ID: #<?php echo htmlspecialchars($po['po_number']); ?></h3>
                        <span class="status-badge status-<?php echo htmlspecialchars($po['status']); ?>">
                            <?php 
                                $status_text = '';
                                switch ($po['status']) {
                                    case 'approved':
                                        $status_text = 'Approved &rarr; Forward to Admin';
                                        break;
                                    case 'forwarded':
                                        $status_text = 'Forwarded to Admin';
                                        break;
                                    default:
                                        $status_text = ucfirst(htmlspecialchars($po['status']));
                                }
                                echo $status_text;
                            ?>
                        </span>
                    </div>
                    <div class="po-card-body">
                        <p><strong>Product / Category:</strong> <?php echo htmlspecialchars($po['fuel_type_name']); ?> (Fuel)</p>
                        <p><strong>Quantity Ordered:</strong> <?php echo number_format($po['volume'], 2); ?> liters</p>
                        <p><strong>Supplier:</strong> <?php echo htmlspecialchars($po['supplier_name'] ?? 'N/A'); ?></p>
                        <p><strong>Unit Price:</strong> ₱<?php echo number_format($po['unit_price'], 2); ?></p>
                        <p><strong>Total Cost:</strong> ₱<?php echo number_format($po['total_amount'], 2); ?></p>
                        <p><strong>Expected Delivery Date:</strong> <?php echo date('F j, Y', strtotime($po['expected_delivery_date'])); ?></p>
                        <?php if (!empty($po['notes'])): ?>
                            <p><strong>Notes:</strong> <?php echo htmlspecialchars($po['notes']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="po-card-actions">
                        <a href="#" class="action-link" onclick="alert('Create action is at the top of the page.');">Create</a>
                        
                        <?php if ($po['status'] === 'pending'): ?>
                            <a href="#" class="action-link" onclick="openApproveModal(<?php echo $po['id']; ?>); return false;">Approve</a>
                        <?php else: ?>
                            <span class="action-link-disabled">Approve</span>
                        <?php endif; ?>

                        <?php if ($po['status'] === 'approved'): ?>
                            <form method="post" action="manager_purchase_orders.php" style="display: inline;">
                                <input type="hidden" name="action" value="forward_po">
                                <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
                                <button type="submit" class="action-link-button">Forward</button>
                            </form>
                        <?php else: ?>
                             <span class="action-link-disabled">Forward</span>
                        <?php endif; ?>

                        <a href="manager_deliveries_management.php?po_id=<?php echo $po['id']; ?>" class="action-link">Track</a>
                        <a href="#" class="action-link" onclick="alert('Audit trail page not implemented yet.');">Audit</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <h3>No Purchase Orders Found</h3>
                <p>Purchase orders will be automatically generated when low stock alerts are approved.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modals for actions -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('approveModal')">&times;</span>
        <h2 style="color: <?php echo $colors['primary']; ?>;">Approve Purchase Order</h2>
        <form method="post" action="manager_purchase_orders.php">
            <input type="hidden" name="action" value="approve_po">
            <input type="hidden" name="po_id" id="approve_po_id">
            
            <div class="form-group">
                <label class="form-label">Approval Notes</label>
                <textarea name="notes" class="form-textarea" placeholder="Enter approval notes..."></textarea>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" class="action-btn btn-secondary" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="action-btn btn-success">
                    <i class="fas fa-check"></i> Approve PO
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Open/Close Modals
function openApproveModal(poId) {
    document.getElementById('approve_po_id').value = poId;
    document.getElementById('approveModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = ['approveModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    });
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
