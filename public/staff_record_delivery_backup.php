<?php
$page_id = 'staff_record_delivery';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

$msg = '';
$msg_type = 'success';

// Get active tab
$active_tab = $_GET['tab'] ?? 'merchandise';
if (!in_array($active_tab, ['merchandise', 'fuel'])) {
    $active_tab = 'merchandise';
}

/* ══════════════════════════════════════════════════════════
   POST — Record Delivery Receipt
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_delivery') {
    $po_id = (int)($_POST['pr_id'] ?? 0);
    $dr_number = trim($_POST['dr_number'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? date('Y-m-d'));
    $actual_qty = (float)($_POST['actual_qty'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $po_type = trim($_POST['po_type'] ?? 'merchandise');
    
    if ($po_id > 0 && $actual_qty > 0) {
        try {
            $pdo->beginTransaction();
            
            // Get PO details based on type
            if ($po_type === 'fuel') {
                $stmt = $pdo->prepare("
                    SELECT fpo.*, ft.name as fuel_type, s.name as supplier_name 
                    FROM fuel_purchase_orders fpo
                    LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                    LEFT JOIN suppliers s ON fpo.supplier_id = s.id
                    WHERE fpo.id = ? AND fpo.station_id = ? AND LOWER(fpo.status) = 'waiting for delivery'
                ");
                $stmt->execute([$po_id, $station_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                $product_name = $po['fuel_type'] ?? 'Unknown';
            } else {
                $stmt = $pdo->prepare("
                    SELECT po.*, s.name as supplier_name 
                    FROM purchase_orders po
                    LEFT JOIN suppliers s ON po.supplier_id = s.id
                    WHERE po.id = ? AND po.station_id = ? AND LOWER(po.status) = 'waiting for delivery' AND po.type = 'merch'
                ");
                $stmt->execute([$po_id, $station_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                $product_name = $po['product_name'] ?? 'Unknown';
            }
            
            if (!$po) {
                throw new Exception("Purchase Order not found or not in 'Waiting for Delivery' status");
            }
            
            // Create delivery record in deliveries_oversight table
            $delivery_ref = 'DR-' . date('Ymd') . '-' . str_pad($po_id, 4, '0', STR_PAD_LEFT);
            $delivery_type = $po_type === 'fuel' ? 'fuel' : 'merchandise';
            
            $stmt = $pdo->prepare("
                INSERT INTO deliveries_oversight
                    (delivery_type, delivery_ref, supplier, product, quantity, unit, 
                     delivery_date, dr_number, encoded_by, station_id, status, remarks, 
                     source_ref, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Verification', ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $delivery_type,
                $delivery_ref,
                $po['supplier_name'] ?? 'Unknown',
                $product_name,
                $actual_qty,
                $po['unit'] ?? ($po_type === 'fuel' ? 'L' : 'pcs'),
                $delivery_date,
                $dr_number,
                $me['id'],
                $station_id,
                $remarks,
                'PO-' . $po_id
            ]);
            
            // Update PO status to 'Delivered'
            if ($po_type === 'fuel') {
                $stmt = $pdo->prepare("UPDATE fuel_purchase_orders SET status = 'Delivered', updated_at = NOW() WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE purchase_orders SET status = 'Delivered', updated_at = NOW() WHERE id = ?");
            }
            $stmt->execute([$po_id]);
            
            log_activity($pdo, $me['id'], 'Record Delivery', "PO #{$po_id} | DR: {$dr_number} | Qty: {$actual_qty}");
            
            $pdo->commit();
            
            $_SESSION['flash_msg'] = "✓ Delivery recorded successfully! DR: {$dr_number}";
            $_SESSION['flash_type'] = 'success';
            header("Location: staff_record_delivery.php?tab={$active_tab}");
            exit;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Error: ' . $e->getMessage();
            $msg_type = 'error';
        }
    } else {
        $msg = 'Please provide valid delivery details';
        $msg_type = 'error';
    }
}

// Check for flash messages
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

/* ══════════════════════════════════════════════════════════
   Fetch Purchase Orders - Waiting for Delivery
══════════════════════════════════════════════════════════ */
$merchandise_prs = [];
$fuel_prs = [];

try {
    // Merchandise POs with Waiting for Delivery status
    $stmt = $pdo->prepare("
        SELECT po.*, s.name as supplier_name, s.contact_person, s.phone
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.station_id = ? 
          AND LOWER(po.status) = 'waiting for delivery'
          AND po.type = 'merch'
        ORDER BY po.expected_delivery_date ASC, po.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $merchandise_prs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fuel POs with Waiting for Delivery status
    $stmt = $pdo->prepare("
        SELECT fpo.*, ft.name as fuel_type, s.name as supplier_name, s.contact_person, s.phone
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
        LEFT JOIN suppliers s ON fpo.supplier_id = s.id
        WHERE fpo.station_id = ? 
          AND LOWER(fpo.status) = 'waiting for delivery'
        ORDER BY fpo.expected_delivery_date ASC, fpo.created_at ASC
    ");
    $stmt->execute([$station_id]);
    $fuel_prs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching POs: " . $e->getMessage());
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* Header */
.page-header { 
    display: flex; 
    justify-content: space-between; 
    align-items: flex-start; 
    margin-bottom: 24px; 
    flex-wrap: wrap; 
    gap: 16px;
}
.page-header h1 { 
    font-size: 26px; 
    font-weight: 700; 
    color: #002F70; 
    margin: 0; 
    display: flex; 
    align-items: center; 
    gap: 10px;
}
.page-header .subtitle { 
    font-size: 14px; 
    color: #6c757d; 
    margin-top: 6px;
}

/* Alert */
.alert-box {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
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

/* Tabs */
.tabs-container {
    background: transparent;
    margin-bottom: 20px;
}
.tabs-header {
    display: flex;
    gap: 12px;
    background: transparent;
    border: none;
    margin-bottom: 20px;
}
.tab-btn {
    padding: 10px 24px;
    background: #ffffff;
    border: 2px solid #002F70;
    font-size: 14px;
    font-weight: 600;
    color: #002F70;
    cursor: pointer;
    transition: all 0.2s;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    position: relative;
    white-space: nowrap;
}
.tab-btn:hover {
    background: #002F70;
    color: #ffffff;
}
.tab-btn.active {
    background: #002F70;
    color: #ffffff;
    border-color: #002F70;
}
.tab-btn i {
    font-size: 16px;
}
.tab-btn .badge {
    background: #002F70;
    color: #ffffff;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    min-width: 26px;
    height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 6px;
}
.tab-btn.active .badge {
    background: #ffffff;
    color: #002F70;
}

/* Tab Content Container */
.tab-content-wrapper {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    overflow: hidden;
}
.tab-content {
    display: none;
    padding: 20px;
    min-height: 400px;
}
.tab-content.active {
    display: block;
}

/* Table */
.pr-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
}
.pr-table thead {
    background: #f8f9fa;
}
.pr-table th {
    padding: 12px 16px;
    text-align: left;
    font-size: 12px;
    font-weight: 700;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #dee2e6;
}
.pr-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
    color: #212529;
}
.pr-table tbody tr:hover {
    background: #f8f9fa;
}
.pr-table tbody tr:last-child td {
    border-bottom: none;
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.status-waiting {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}

/* Buttons */
.btn {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-primary {
    background: #002F70;
    color: #fff;
}
.btn-primary:hover {
    background: #001f4d;
}
.btn-secondary {
    background: #6c757d;
    color: #fff;
}
.btn-secondary:hover {
    background: #5a6268;
}
.btn-success {
    background: #28a745;
    color: #fff;
}
.btn-success:hover {
    background: #218838;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}
.empty-state i {
    font-size: 64px;
    color: #dee2e6;
    margin-bottom: 16px;
}
.empty-state h3 {
    font-size: 18px;
    font-weight: 600;
    color: #495057;
    margin: 0 0 8px 0;
}
.empty-state p {
    font-size: 14px;
    margin: 0;
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.modal.open {
    display: flex;
}
.modal-content {
    background: #fff;
    border-radius: 12px;
    width: 600px;
    max-width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: #002F70;
}
.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: #6c757d;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
}
.modal-close:hover {
    background: #f8f9fa;
    color: #212529;
}
.modal-body {
    padding: 24px;
}
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #dee2e6;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Form */
.form-group {
    margin-bottom: 20px;
}
.form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #495057;
    margin-bottom: 6px;
}
.form-label .required {
    color: #dc3545;
}
.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
}
.form-control:focus {
    border-color: #002F70;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,47,112,0.1);
}
.form-control[readonly] {
    background: #e9ecef;
    cursor: not-allowed;
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

/* Info Box */
.info-box {
    background: #f0f4ff;
    border: 1px solid #d0d9f7;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}
.info-box h4 {
    margin: 0 0 10px 0;
    font-size: 13px;
    font-weight: 700;
    color: #002F70;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 13px;
}
.info-row:last-child {
    margin-bottom: 0;
}
.info-label {
    color: #6c757d;
    font-weight: 600;
}
.info-value {
    color: #212529;
    font-weight: 500;
}
</style>

<div class="page-header">
    <div>
        <h1><i class="fas fa-truck-loading"></i> Record Delivery</h1>
        <div class="subtitle">View Purchase Requests waiting for delivery and record actual deliveries received</div>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert-box alert-<?php echo $msg_type === 'success' ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
    <span><?php echo htmlspecialchars($msg); ?></span>
</div>
<?php endif; ?>

<div class="tabs-container">
    <div class="tabs-header">
        <button class="tab-btn <?php echo $active_tab === 'merchandise' ? 'active' : ''; ?>" 
                onclick="switchTab('merchandise')">
            <i class="fas fa-boxes"></i>
            <span>Merchandise</span>
            <span class="badge"><?php echo count($merchandise_prs); ?></span>
        </button>
        <button class="tab-btn <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>" 
                onclick="switchTab('fuel')">
            <i class="fas fa-gas-pump"></i>
            <span>Fuel</span>
            <span class="badge"><?php echo count($fuel_prs); ?></span>
        </button>
    </div>
</div>

<div class="tab-content-wrapper">
    <!-- Merchandise Tab -->
    <div id="merchandise-tab" class="tab-content <?php echo $active_tab === 'merchandise' ? 'active' : ''; ?>">
        <?php if (empty($merchandise_prs)): ?>
        <div class="empty-state">
            <i class="fas fa-box-open"></i>
            <h3>No Pending Merchandise Deliveries</h3>
            <p>All merchandise purchase requests have been delivered</p>
        </div>
        <?php else: ?>
        <table class="pr-table">
            <thead>
                <tr>
                    <th>PO No.</th>
                    <th>Supplier</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Expected Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($merchandise_prs as $pr): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($pr['po_number'] ?? 'PO-' . str_pad($pr['id'], 4, '0', STR_PAD_LEFT)); ?></strong></td>
                    <td><?php echo htmlspecialchars($pr['supplier_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo htmlspecialchars($pr['product_name'] ?? '-'); ?></td>
                    <td><?php echo number_format($pr['quantity'] ?? 0, 2); ?> <?php echo htmlspecialchars($pr['unit'] ?? 'pcs'); ?></td>
                    <td><?php echo $pr['expected_delivery_date'] ? date('M d, Y', strtotime($pr['expected_delivery_date'])) : '-'; ?></td>
                    <td><span class="status-badge status-waiting">Waiting for Delivery</span></td>
                    <td>
                        <button class="btn btn-success" onclick="openRecordModal(<?php echo $pr['id']; ?>, 'merchandise')">
                            <i class="fas fa-clipboard-check"></i> Record Delivery
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <!-- Fuel Tab -->
    <div id="fuel-tab" class="tab-content <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>">
        <?php if (empty($fuel_prs)): ?>
        <div class="empty-state">
            <i class="fas fa-gas-pump"></i>
            <h3>No Pending Fuel Deliveries</h3>
            <p>All fuel purchase requests have been delivered</p>
        </div>
        <?php else: ?>
        <table class="pr-table">
            <thead>
                <tr>
                    <th>PO No.</th>
                    <th>Supplier</th>
                    <th>Fuel Type</th>
                    <th>Quantity</th>
                    <th>Expected Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fuel_prs as $pr): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($pr['po_number'] ?? 'FPO-' . str_pad($pr['id'], 4, '0', STR_PAD_LEFT)); ?></strong></td>
                    <td><?php echo htmlspecialchars($pr['supplier_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo htmlspecialchars($pr['fuel_type'] ?? '-'); ?></td>
                    <td><?php echo number_format($pr['quantity'] ?? 0, 2); ?> <?php echo htmlspecialchars($pr['unit'] ?? 'L'); ?></td>
                    <td><?php echo $pr['expected_delivery_date'] ? date('M d, Y', strtotime($pr['expected_delivery_date'])) : '-'; ?></td>
                    <td><span class="status-badge status-waiting">Waiting for Delivery</span></td>
                    <td>
                        <button class="btn btn-success" onclick="openRecordModal(<?php echo $pr['id']; ?>, 'fuel')">
                            <i class="fas fa-clipboard-check"></i> Record Delivery
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<!-- End tab-content-wrapper -->

<!-- Record Delivery Modal -->
<div id="recordModal" class="modal">
    <div class="modal-content">
        <form method="POST" id="recordForm">
            <input type="hidden" name="action" value="record_delivery">
            <input type="hidden" name="pr_id" id="modal_pr_id">
            
            <div class="modal-header">
                <h3><i class="fas fa-clipboard-check"></i> Record Delivery Receipt</h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="info-box" id="prInfoBox">
                    <h4><i class="fas fa-info-circle"></i> Purchase Request Details</h4>
                    <div id="prDetailsContent"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">DR Number / Invoice Number <span class="required">*</span></label>
                    <input type="text" name="dr_number" class="form-control" required placeholder="e.g., DR-2024-001">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Delivery Date <span class="required">*</span></label>
                        <input type="date" name="delivery_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Actual Quantity Received <span class="required">*</span></label>
                        <input type="number" name="actual_qty" class="form-control" step="0.01" min="0.01" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Remarks / Notes</label>
                    <textarea name="remarks" class="form-control" rows="3" placeholder="Optional notes about this delivery"></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Record Delivery
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Tab switching
function switchTab(tab) {
    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.pushState({}, '', url);
    
    // Update tabs
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    
    document.querySelector(`.tab-btn[onclick="switchTab('${tab}')"]`).classList.add('active');
    document.getElementById(`${tab}-tab`).classList.add('active');
}

// PR data
const prData = {
    merchandise: <?php echo json_encode($merchandise_prs); ?>,
    fuel: <?php echo json_encode($fuel_prs); ?>
};

// Open record modal
function openRecordModal(prId, type) {
    const pr = prData[type].find(p => p.id == prId);
    if (!pr) return;
    
    document.getElementById('modal_pr_id').value = prId;
    
    // Add hidden input for po_type
    let existingTypeInput = document.querySelector('input[name="po_type"]');
    if (existingTypeInput) {
        existingTypeInput.remove();
    }
    const typeInput = document.createElement('input');
    typeInput.type = 'hidden';
    typeInput.name = 'po_type';
    typeInput.value = type;
    document.getElementById('recordForm').appendChild(typeInput);
    
    const poNumber = pr.po_number || (type === 'fuel' ? 'FPO-' + String(pr.id).padStart(4, '0') : 'PO-' + String(pr.id).padStart(4, '0'));
    
    let detailsHtml = `
        <div class="info-row">
            <span class="info-label">PO Number:</span>
            <span class="info-value">${poNumber}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Supplier:</span>
            <span class="info-value">${pr.supplier_name || 'Unknown'}</span>
        </div>
        <div class="info-row">
            <span class="info-label">${type === 'fuel' ? 'Fuel Type' : 'Product'}:</span>
            <span class="info-value">${pr.fuel_type || pr.product_name || '-'}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Expected Quantity:</span>
            <span class="info-value">${parseFloat(pr.quantity || pr.volume || 0).toLocaleString()} ${pr.unit || (type === 'fuel' ? 'L' : 'pcs')}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Expected Date:</span>
            <span class="info-value">${pr.expected_delivery_date ? new Date(pr.expected_delivery_date).toLocaleDateString() : '-'}</span>
        </div>
    `;
    
    document.getElementById('prDetailsContent').innerHTML = detailsHtml;
    document.getElementById('recordModal').classList.add('open');
}

// Close modal
function closeModal() {
    document.getElementById('recordModal').classList.remove('open');
    document.getElementById('recordForm').reset();
}

// Close modal on overlay click
document.getElementById('recordModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
