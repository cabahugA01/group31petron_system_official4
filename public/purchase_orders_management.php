<?php
// Purchase Orders Management System
session_start();
require_once '../config/database_config.php';
require_once '../public/db_connect.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager', 'superadmin'])) {
    header('Location: ../login.php');
    exit;
}

$station_id = $_SESSION['station_id'] ?? 1;
$user_id = $_SESSION['user_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_po'])) {
        // Create new purchase order
        try {
            $pdo->beginTransaction();
            
            // Insert PO header
            $stmt = $pdo->prepare("
                INSERT INTO purchase_orders (station_id, supplier_id, status, created_by) 
                VALUES (?, ?, 'pending', ?)
            ");
            $stmt->execute([$station_id, $_POST['supplier_id'], $user_id]);
            $po_id = $pdo->lastInsertId();
            
            // Insert PO items
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO po_items (po_id, product_id, quantity_ordered, unit_price) 
                    VALUES (?, ?, ?, ?)
                ");
                
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['product_id']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                        $stmt->execute([$po_id, $item['product_id'], $item['quantity'], $item['unit_price']]);
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Purchase Order created successfully!";
            header("Location: purchase_orders_management.php");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollback();
            $_SESSION['error'] = "Error creating PO: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['approve_po'])) {
        // Approve purchase order
        try {
            $stmt = $pdo->prepare("
                UPDATE purchase_orders 
                SET status = 'approved', approved_by = ?, approved_at = NOW() 
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$user_id, $_POST['po_id'], $station_id]);
            
            $_SESSION['success'] = "Purchase Order approved!";
            header("Location: purchase_orders_management.php");
            exit;
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Error approving PO: " . $e->getMessage();
        }
    }
    
    // Handle Receive Delivery Form AJAX requests
    if (isset($_POST['action'])) {
        $json_data = json_decode(file_get_contents('php://input'), true);
        
        if ($_POST['action'] === 'confirm_delivery') {
            // Confirm & Close Delivery - Atomic Transaction
            try {
                $pdo->beginTransaction();
                
                // Generate unique transaction ID
                $transaction_id = generateTransactionID();
                
                // Insert delivery record
                $stmt = $pdo->prepare("
                    INSERT INTO deliveries 
                    (po_id, station_id, receipt_no, date_received, status, 
                     created_by, created_at, transaction_id) 
                    VALUES (?, ?, ?, ?, 'completed', ?, NOW(), ?)
                ");
                $stmt->execute([
                    $json_data['po_number'], 
                    $json_data['station_id'], 
                    $json_data['receipt_no'], 
                    $json_data['date_received'], 
                    $json_data['user_id'], 
                    $transaction_id
                ]);
                $delivery_id = $pdo->lastInsertId();
                
                // Insert delivery items
                foreach ($json_data['items'] as $item) {
                    $stmt = $pdo->prepare("
                        INSERT INTO delivery_items 
                        (delivery_id, product_name, category, ordered_qty, 
                         actual_received, variance, remarks) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $delivery_id,
                        $item['product_name'],
                        $item['category'],
                        $item['ordered_qty'],
                        $item['actual_received'],
                        $item['variance'],
                        $item['remarks']
                    ]);
                    
                    // Update inventory (Atomic operation)
                    if ($item['category'] === 'Fuel') {
                        // Update fuel inventory
                        $stmt = $pdo->prepare("
                            UPDATE fuel_inventory 
                            SET current_stock = current_stock + ?, last_updated = NOW() 
                            WHERE station_id = ? AND fuel_type_id = ?
                        ");
                        $stmt->execute([
                            $item['actual_received'], 
                            $json_data['station_id'], 
                            $item['product_id']
                        ]);
                    } else {
                        // Update merchandise inventory
                        $stmt = $pdo->prepare("
                            UPDATE inventory_products 
                            SET stock_quantity = stock_quantity + ?, last_updated = NOW() 
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $item['actual_received'], 
                            $item['product_id']
                        ]);
                    }
                }
                
                // Update PO status to completed
                $stmt = $pdo->prepare("
                    UPDATE purchase_orders 
                    SET status = 'completed', completed_by = ?, completed_at = NOW() 
                    WHERE id = ? AND station_id = ?
                ");
                $stmt->execute([$json_data['user_id'], $json_data['po_number'], $json_data['station_id']]);
                
                // Record audit trail
                $stmt = $pdo->prepare("
                    INSERT INTO audit_log 
                    (user_id, action, details, category, station_id, transaction_id, created_at) 
                    VALUES (?, 'Confirm Delivery', ?, 'delivery', ?, ?, NOW())
                ");
                $audit_details = "Delivery confirmed and closed for PO #{$json_data['po_number']}. Transaction ID: {$transaction_id}";
                $stmt->execute([
                    $json_data['user_id'], 
                    $audit_details, 
                    $json_data['station_id'], 
                    $transaction_id
                ]);
                
                $pdo->commit();
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true, 
                    'message' => 'Delivery confirmed and closed successfully',
                    'transaction_id' => $transaction_id
                ]);
                exit;
                
            } catch (Exception $e) {
                $pdo->rollback();
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'error' => 'Failed to confirm delivery: ' . $e->getMessage()
                ]);
                exit;
            }
        }
        
        if ($_POST['action'] === 'log_discrepancy') {
            // Log Discrepancy Report
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO delivery_discrepancies 
                    (po_number, receipt_no, date_received, supplier, 
                     items, reported_by, station_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $json_data['po_number'],
                    $json_data['receipt_no'],
                    $json_data['date_received'],
                    $json_data['supplier'],
                    json_encode($json_data['items']),
                    $json_data['reported_by'],
                    $json_data['station_id']
                ]);
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true, 
                    'message' => 'Discrepancy report submitted successfully'
                ]);
                exit;
                
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'error' => 'Failed to submit discrepancy report: ' . $e->getMessage()
                ]);
                exit;
            }
        }
    }
}

// Generate Transaction ID
function generateTransactionID() {
    $timestamp = date('YmdHis');
    $random = mt_rand(1000, 9999);
    return "DEL-{$timestamp}-{$random}";
}

// Get data for dropdowns
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT * FROM inventory_products WHERE category != 'Fuel' ORDER BY category, product_name")->fetchAll();

// Get existing POs
$purchase_orders = $pdo->query("
    SELECT po.*, s.name as supplier_name, 
           COUNT(pi.id) as item_count,
           SUM(pi.quantity_ordered * pi.unit_price) as total_amount
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN po_items pi ON po.id = pi.po_id
    WHERE po.station_id = ?
    GROUP BY po.id
    ORDER BY po.created_at DESC
", [$station_id])->fetchAll();

// Get low stock suggestions
$low_stock_suggestions = $pdo->query("
    SELECT ip.*, COALESCE(i.quantity_on_hand, 0) as current_stock
    FROM inventory_products ip
    LEFT JOIN inventory i ON ip.id = i.product_id AND i.station_id = ?
    WHERE ip.category != 'Fuel' 
    AND (i.quantity_on_hand IS NULL OR i.quantity_on_hand < 50)
    ORDER BY i.quantity_on_hand ASC, ip.product_name
    LIMIT 10
", [$station_id])->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders Management - Petron POS</title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        .po-card { transition: all 0.3s ease; border-left: 4px solid #007bff; }
        .po-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .status-pending { border-left-color: #ffc107; }
        .status-approved { border-left-color: #28a745; }
        .status-rejected { border-left-color: #dc3545; }
        .low-stock-item { background: #fff3cd; border: 1px solid #ffeaa7; }
        .item-row { border-bottom: 1px solid #eee; padding: 8px 0; }
        .add-item-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .suggestion-card { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; }
        .receive-delivery-modal .modal-dialog { max-width: 1200px; }
        .variance-negative { background-color: #ffebee !important; color: #c62828; font-weight: 600; }
        .variance-positive { background-color: #e8f5e8 !important; color: #2e7d32; font-weight: 600; }
        .variance-zero { background-color: #f5f5f5 !important; color: #666; }
        .reconciliation-table th { background: #f8f9fa; font-weight: 600; }
        .reconciliation-table td { vertical-align: middle; }
        .action-buttons { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
    </style>
</head>
<body>
    <?php include '../partials/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../partials/staff_sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-shopping-cart me-2"></i>Purchase Orders Management
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary add-item-btn" data-bs-toggle="modal" data-bs-target="#createPOModal">
                            <i class="fas fa-plus me-2"></i>Create New PO
                        </button>
                        <button type="button" class="btn btn-success add-item-btn" data-bs-toggle="modal" data-bs-target="#receiveDeliveryModal">
                            <i class="fas fa-truck me-2"></i>+ Encode Delivery
                        </button>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Low Stock Suggestions -->
                <?php if (!empty($low_stock_suggestions)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card suggestion-card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Low Stock Suggestions
                                </h5>
                                <p class="card-text">Consider creating POs for these items that are running low:</p>
                                <div class="row">
                                    <?php foreach ($low_stock_suggestions as $item): ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="low-stock-item p-2 rounded">
                                            <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                            <span class="float-end text-danger">
                                                Stock: <?= number_format($item['current_stock']) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Purchase Orders List -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-list me-2"></i>Purchase Orders
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($purchase_orders)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No purchase orders found. Create your first PO to get started.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>PO Number</th>
                                                    <th>Supplier</th>
                                                    <th>Items</th>
                                                    <th>Total Amount</th>
                                                    <th>Status</th>
                                                    <th>Created</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($purchase_orders as $po): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($po['po_number']) ?></strong>
                                                    </td>
                                                    <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                                                    <td><?= number_format($po['item_count']) ?> items</td>
                                                    <td>₱<?= number_format($po['total_amount'], 2) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= 
                                                            $po['status'] == 'approved' ? 'success' : 
                                                            ($po['status'] == 'rejected' ? 'danger' : 'warning') 
                                                        ?>">
                                                            <?= ucfirst($po['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= date('M j, Y', strtotime($po['created_at'])) ?></td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <button type="button" class="btn btn-outline-primary" onclick="viewPO(<?= $po['id'] ?>)">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <?php if ($po['status'] == 'pending'): ?>
                                                            <button type="button" class="btn btn-outline-success" onclick="approvePO(<?= $po['id'] ?>)">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Create PO Modal -->
    <div class="modal fade" id="createPOModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Create New Purchase Order
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Supplier</label>
                                <select name="supplier_id" class="form-select" required>
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= $supplier['id'] ?>"><?= htmlspecialchars($supplier['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Order Items</label>
                            <div id="itemsContainer">
                                <div class="item-row">
                                    <div class="row">
                                        <div class="col-md-5">
                                            <select name="items[0][product_id]" class="form-select product-select" required>
                                                <option value="">Select Product</option>
                                                <?php foreach ($products as $product): ?>
                                                <option value="<?= $product['id'] ?>" data-price="<?= $product['unit_cost'] ?>">
                                                    <?= htmlspecialchars($product['product_name']) ?> - ₱<?= number_format($product['unit_cost'], 2) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" name="items[0][quantity]" class="form-control" placeholder="Quantity" step="0.01" required>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" name="items[0][unit_price]" class="form-control unit-price" placeholder="Unit Price" step="0.01" required>
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-outline-danger" onclick="removeItem(this)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="addNewItem()">
                                <i class="fas fa-plus me-1"></i>Add Item
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_po" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create PO
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Receive Delivery Form Modal -->
    <div class="modal fade receive-delivery-modal" id="receiveDeliveryModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-truck me-2"></i>Receive Delivery Form
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="receiveDeliveryForm">
                    <div class="modal-body">
                        <!-- Header: Reference Data -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Reference Data
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <label class="form-label">PO Number</label>
                                        <div class="input-group">
                                            <select name="po_number" id="poNumberSelect" class="form-select" required>
                                                <option value="">Select PO Number</option>
                                                <?php 
                                                // Get approved POs for dropdown
                                                $approved_pos = $pdo->query("
                                                    SELECT po.id, po.po_number, s.name as supplier_name 
                                                    FROM purchase_orders po 
                                                    LEFT JOIN suppliers s ON po.supplier_id = s.id 
                                                    WHERE po.station_id = ? AND po.status = 'approved' 
                                                    ORDER BY po.created_at DESC
                                                ", [$station_id])->fetchAll();
                                                foreach ($approved_pos as $po): ?>
                                                <option value="<?= $po['id'] ?>" data-supplier="<?= htmlspecialchars($po['supplier_name']) ?>">
                                                    <?= htmlspecialchars($po['po_number']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-secondary" onclick="loadPODetails()">
                                                <i class="fas fa-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Supplier</label>
                                        <input type="text" name="supplier" id="supplierName" class="form-control" readonly placeholder="Auto-filled">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Receipt No</label>
                                        <input type="text" name="receipt_no" class="form-control" placeholder="From supplier's paper" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Date Received</label>
                                        <input type="date" name="date_received" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Body: Reconciliation Table -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="fas fa-table me-2"></i>Reconciliation Table (Actual vs Ordered)
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered reconciliation-table" id="reconciliationTable">
                                        <thead>
                                            <tr>
                                                <th>Product Name</th>
                                                <th>Category</th>
                                                <th>Ordered Qty</th>
                                                <th>Actual Received</th>
                                                <th>Variance</th>
                                                <th>Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody id="reconciliationTableBody">
                                            <!-- Items will be loaded here via JavaScript -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Hidden fields for form processing -->
                        <input type="hidden" name="delivery_items" id="deliveryItemsJson">
                        <input type="hidden" name="action" value="receive_delivery">
                    </div>
                    <div class="modal-footer">
                        <div class="action-buttons">
                            <button type="button" class="btn btn-secondary" onclick="saveDraft()">
                                <i class="fas fa-save me-2"></i>Save Draft
                            </button>
                            <button type="button" class="btn btn-warning" onclick="logDiscrepancy()">
                                <i class="fas fa-exclamation-triangle me-2"></i>Log Discrepancy
                            </button>
                            <button type="button" class="btn btn-success" id="confirmCloseBtn" onclick="confirmAndCloseDelivery()" disabled>
                                <i class="fas fa-check-circle me-2"></i>Confirm & Close Delivery
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemCount = 1;
        let currentPOItems = [];
        let deliveryData = {};

        // PO Number Auto-fill functionality
        document.getElementById('poNumberSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const supplierName = selectedOption.dataset.supplier;
            document.getElementById('supplierName').value = supplierName || '';
            
            // Auto-load PO details
            if (this.value) {
                loadPODetails();
            }
        });

        // Load PO Details
        async function loadPODetails() {
            const poId = document.getElementById('poNumberSelect').value;
            
            if (!poId) {
                clearReconciliationTable();
                return;
            }

            try {
                const response = await fetch(`../backend/api/delivery_management_enhanced.php?action=get_po_details&po_id=${poId}&station_id=<?= $station_id ?>`);
                const data = await response.json();
                
                if (data.po) {
                    currentPOItems = data.items || [];
                    populateReconciliationTable(data.items);
                } else {
                    alert('PO details not found');
                    clearReconciliationTable();
                }
            } catch (error) {
                console.error('Error loading PO details:', error);
                alert('Error loading PO details');
            }
        }

        // Populate Reconciliation Table
        function populateReconciliationTable(items) {
            const tbody = document.getElementById('reconciliationTableBody');
            tbody.innerHTML = '';
            
            items.forEach((item, index) => {
                const row = document.createElement('tr');
                row.dataset.index = index;
                row.innerHTML = `
                    <td>${item.product_name || 'N/A'}</td>
                    <td>${item.category || 'N/A'}</td>
                    <td>${item.quantity_ordered || 0} ${getUnitLabel(item.category)}</td>
                    <td>
                        <input type="number" class="form-control actual-received" 
                               data-index="${index}" 
                               data-ordered="${item.quantity_ordered || 0}"
                               step="0.01" min="0" 
                               placeholder="0.00" 
                               oninput="calculateVariance(${index})">
                    </td>
                    <td class="variance-cell" id="variance-${index}">
                        <span class="badge variance-zero">0</span>
                    </td>
                    <td>
                        <input type="text" class="form-control remarks" 
                               data-index="${index}" 
                               placeholder="Enter remarks...">
                    </td>
                `;
                tbody.appendChild(row);
            });
            
            // Add event listeners to actual received inputs
            document.querySelectorAll('.actual-received').forEach(input => {
                input.addEventListener('input', function() {
                    validateForm();
                });
            });
        }

        // Clear Reconciliation Table
        function clearReconciliationTable() {
            document.getElementById('reconciliationTableBody').innerHTML = '';
            currentPOItems = [];
            validateForm();
        }

        // Calculate Variance
        function calculateVariance(index) {
            const input = document.querySelector(`input[data-index="${index}"]`);
            const actualReceived = parseFloat(input.value) || 0;
            const orderedQty = parseFloat(input.dataset.ordered) || 0;
            const variance = actualReceived - orderedQty;
            
            const varianceCell = document.getElementById(`variance-${index}`);
            const row = input.closest('tr');
            
            // Remove existing variance classes
            row.classList.remove('variance-negative', 'variance-positive', 'variance-zero');
            
            let varianceClass = 'variance-zero';
            let varianceText = '0';
            
            if (variance < 0) {
                varianceClass = 'variance-negative';
                varianceText = `${variance} (SHORTAGE)`;
                row.classList.add('variance-negative');
            } else if (variance > 0) {
                varianceClass = 'variance-positive';
                varianceText = `+${variance} (EXCESS)`;
                row.classList.add('variance-positive');
            } else {
                row.classList.add('variance-zero');
            }
            
            varianceCell.innerHTML = `<span class="badge ${varianceClass}">${varianceText}</span>`;
        }

        // Get Unit Label
        function getUnitLabel(category) {
            return category === 'Fuel' ? 'L' : 'Units';
        }

        // Validate Form
        function validateForm() {
            const poNumber = document.getElementById('poNumberSelect').value;
            const receiptNo = document.querySelector('input[name="receipt_no"]').value;
            const actualReceivedInputs = document.querySelectorAll('.actual-received');
            
            let hasActualReceived = false;
            let allValid = true;
            
            actualReceivedInputs.forEach(input => {
                if (input.value && parseFloat(input.value) > 0) {
                    hasActualReceived = true;
                }
            });
            
            const isValid = poNumber && receiptNo && hasActualReceived;
            document.getElementById('confirmCloseBtn').disabled = !isValid;
        }

        // Save Draft
        function saveDraft() {
            if (!validateBasicFields()) return;
            
            const deliveryData = collectDeliveryData();
            deliveryData.status = 'draft';
            
            // Save to localStorage or send to server
            localStorage.setItem('deliveryDraft', JSON.stringify(deliveryData));
            alert('Draft saved successfully! You can return to complete this delivery later.');
        }

        // Log Discrepancy
        function logDiscrepancy() {
            if (!validateBasicFields()) return;
            
            const deliveryData = collectDeliveryData();
            const hasDiscrepancy = deliveryData.items.some(item => item.variance !== 0);
            
            if (!hasDiscrepancy) {
                alert('No discrepancies found. All items match ordered quantities.');
                return;
            }
            
            // Create discrepancy report
            const discrepancyReport = {
                po_number: deliveryData.po_number,
                receipt_no: deliveryData.receipt_no,
                date_received: deliveryData.date_received,
                supplier: deliveryData.supplier,
                items: deliveryData.items.filter(item => item.variance !== 0),
                reported_by: '<?= $user_id ?>',
                station_id: '<?= $station_id ?>',
                created_at: new Date().toISOString()
            };
            
            // Send discrepancy to server
            submitDiscrepancyReport(discrepancyReport);
        }

        // Confirm and Close Delivery
        async function confirmAndCloseDelivery() {
            if (!validateBasicFields()) return;
            
            const deliveryData = collectDeliveryData();
            
            if (!confirm(`Are you sure you want to confirm and close this delivery?\n\nThis will:\n• Update inventory levels\n• Record audit trail\n• Mark PO as completed\n\nThis action cannot be undone.`)) {
                return;
            }
            
            try {
                const response = await fetch('../backend/api/delivery_management_enhanced.php?action=confirm_delivery', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(deliveryData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Delivery confirmed and closed successfully!\nTransaction ID: ' + result.transaction_id);
                    bootstrap.Modal.getInstance(document.getElementById('receiveDeliveryModal')).hide();
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Failed to confirm delivery'));
                }
            } catch (error) {
                console.error('Error confirming delivery:', error);
                alert('Error confirming delivery. Please try again.');
            }
        }

        // Collect Delivery Data
        function collectDeliveryData() {
            const items = [];
            document.querySelectorAll('.actual-received').forEach(input => {
                const index = parseInt(input.dataset.index);
                const orderedQty = parseFloat(input.dataset.ordered) || 0;
                const actualReceived = parseFloat(input.value) || 0;
                const variance = actualReceived - orderedQty;
                const remarks = document.querySelector(`input.remarks[data-index="${index}"]`).value;
                
                items.push({
                    product_name: currentPOItems[index]?.product_name || '',
                    category: currentPOItems[index]?.category || '',
                    ordered_qty: orderedQty,
                    actual_received: actualReceived,
                    variance: variance,
                    remarks: remarks
                });
            });
            
            return {
                po_number: document.getElementById('poNumberSelect').value,
                supplier: document.getElementById('supplierName').value,
                receipt_no: document.querySelector('input[name="receipt_no"]').value,
                date_received: document.querySelector('input[name="date_received"]').value,
                items: items,
                user_id: '<?= $user_id ?>',
                station_id: '<?= $station_id ?>'
            };
        }

        // Validate Basic Fields
        function validateBasicFields() {
            const poNumber = document.getElementById('poNumberSelect').value;
            const receiptNo = document.querySelector('input[name="receipt_no"]').value;
            
            if (!poNumber) {
                alert('Please select a PO Number');
                return false;
            }
            
            if (!receiptNo) {
                alert('Please enter a Receipt Number');
                return false;
            }
            
            return true;
        }

        // Submit Discrepancy Report
        async function submitDiscrepancyReport(report) {
            try {
                const response = await fetch('../backend/api/delivery_management_enhanced.php?action=log_discrepancy', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(report)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Discrepancy report submitted successfully! Management has been notified.');
                } else {
                    alert('Error submitting discrepancy report: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error submitting discrepancy:', error);
                alert('Error submitting discrepancy report. Please try again.');
            }
        }

        // Original functions
        function addNewItem() {
            const container = document.getElementById('itemsContainer');
            const newItem = document.createElement('div');
            newItem.className = 'item-row';
            newItem.innerHTML = `
                <div class="row">
                    <div class="col-md-5">
                        <select name="items[${itemCount}][product_id]" class="form-select product-select" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $product): ?>
                            <option value="<?= $product['id'] ?>" data-price="<?= $product['unit_cost'] ?>">
                                <?= htmlspecialchars($product['product_name']) ?> - ₱<?= number_format($product['unit_cost'], 2) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="number" name="items[${itemCount}][quantity]" class="form-control" placeholder="Quantity" step="0.01" required>
                    </div>
                    <div class="col-md-3">
                        <input type="number" name="items[${itemCount}][unit_price]" class="form-control unit-price" placeholder="Unit Price" step="0.01" required>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-outline-danger" onclick="removeItem(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newItem);
            itemCount++;
            
            // Add event listener to new product select
            newItem.querySelector('.product-select').addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const priceInput = this.closest('.item-row').querySelector('.unit-price');
                priceInput.value = selectedOption.dataset.price || '';
            });
        }

        function removeItem(button) {
            button.closest('.item-row').remove();
        }

        function viewPO(poId) {
            // Implementation for viewing PO details
            window.location.href = `purchase_order_details.php?id=${poId}`;
        }

        function approvePO(poId) {
            if (confirm('Are you sure you want to approve this Purchase Order?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="po_id" value="${poId}">
                    <input type="hidden" name="approve_po" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Auto-fill price when product is selected
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.product-select').forEach(select => {
                select.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const priceInput = this.closest('.item-row').querySelector('.unit-price');
                    priceInput.value = selectedOption.dataset.price || '';
                });
            });
            
            // Load draft if exists
            const savedDraft = localStorage.getItem('deliveryDraft');
            if (savedDraft) {
                // You can implement draft restoration here
                console.log('Draft found:', savedDraft);
            }
        });
    </script>
<?php include __DIR__ . '/../partials/footer.php'; ?>