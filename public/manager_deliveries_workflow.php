<?php
// Manager Deliveries Workflow System
session_start();
require_once '../backend/lib.php';
require_once '../config/database_config.php';
require_once '../public/db_connect.php';

// Check if user is logged in and has appropriate role
require_login();
$_wf_user = current_user();
$_wf_role = role_key($_wf_user['role'] ?? '');
if (!in_array($_wf_role, ['manager', 'admin', 'superadmin'])) {
    header('Location: ../public/login.php');
    exit;
}

$station_id = user_station_id() ?? ($_SESSION['station_id'] ?? 1);
$user_id    = $_wf_user['id'];
$user_name  = $_wf_user['name'] ?? 'Manager';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($json_data['action'])) {
        switch ($json_data['action']) {
            case 'encode_delivery':
                handleEncodeDelivery($json_data, $pdo, $station_id, $user_id, $user_name);
                break;
            case 'confirm_delivery':
                handleConfirmDelivery($json_data, $pdo, $station_id, $user_id, $user_name);
                break;
            case 'get_delivery_stats':
                getDeliveryStats($pdo, $station_id);
                break;
            case 'get_pending_pos':
                getPendingPOs($pdo, $station_id);
                break;
        }
    }
}

// Handle Encode Delivery
function handleEncodeDelivery($data, $pdo, $station_id, $user_id, $user_name) {
    try {
        $pdo->beginTransaction();
        
        // Generate unique transaction ID
        $transaction_id = generateTransactionID();
        
        // Insert delivery record
        $stmt = $pdo->prepare("
            INSERT INTO deliveries 
            (po_ref, supplier_id, status, created_by, created_at, transaction_id, station_id)
            VALUES (?, ?, 'encoded', ?, NOW(), ?, ?)
        ");
        $stmt->execute([
            $data['po_number'],
            $data['supplier_id'],
            $user_id,
            $transaction_id,
            $station_id
        ]);
        $delivery_id = $pdo->lastInsertId();
        
        // Insert delivery items
        foreach ($data['items'] as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO delivery_items 
                (delivery_id, product_id, qty_ordered, qty_received, variance, remarks)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $delivery_id,
                $item['product_id'],
                $item['qty_ordered'],
                $item['qty_received'],
                $item['variance'],
                $item['remarks'] ?? ''
            ]);
        }
        
        $pdo->commit();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'delivery_id' => $delivery_id,
            'transaction_id' => $transaction_id,
            'message' => 'Delivery encoded successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Failed to encode delivery: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Handle Confirm Delivery
function handleConfirmDelivery($data, $pdo, $station_id, $user_id, $user_name) {
    try {
        $pdo->beginTransaction();
        
        // Get delivery details
        $stmt = $pdo->prepare("
            SELECT d.*, po.supplier_id 
            FROM deliveries d
            LEFT JOIN purchase_orders po ON d.po_ref = po.po_number
            WHERE d.id = ? AND d.station_id = ?
        ");
        $stmt->execute([$data['delivery_id'], $station_id]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$delivery) {
            throw new Exception('Delivery not found');
        }
        
        // Update delivery status
        $stmt = $pdo->prepare("
            UPDATE deliveries 
            SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW()
            WHERE id = ? AND station_id = ?
        ");
        $stmt->execute([$user_id, $data['delivery_id'], $station_id]);
        
        // Update inventory
        $stmt = $pdo->prepare("
            SELECT di.product_id, di.qty_received, ip.category
            FROM delivery_items di
            JOIN inventory_products ip ON di.product_id = ip.id
            WHERE di.delivery_id = ?
        ");
        $stmt->execute([$data['delivery_id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as $item) {
            if ($item['category'] === 'Fuel') {
                // Update fuel inventory
                $stmt = $pdo->prepare("
                    UPDATE fuel_inventory 
                    SET current_stock = current_stock + ?, last_updated = NOW()
                    WHERE station_id = ? AND fuel_type_id = ?
                ");
                $stmt->execute([$item['qty_received'], $station_id, $item['product_id']]);
            } else {
                // Update merchandise inventory
                $stmt = $pdo->prepare("
                    UPDATE inventory_products 
                    SET stock_quantity = stock_quantity + ?, last_updated = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$item['qty_received'], $item['product_id']]);
            }
            
            // Log inventory change
            $stmt = $pdo->prepare("
                INSERT INTO inventory_logs 
                (product_id, change_qty, reason, user_id, station_id, transaction_id, created_at)
                VALUES (?, ?, 'delivery_receipt', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $item['product_id'],
                $item['qty_received'],
                $user_id,
                $station_id,
                $delivery['transaction_id']
            ]);
        }
        
        // Log variances
        $stmt = $pdo->prepare("
            SELECT di.product_id, di.variance, ip.product_name
            FROM delivery_items di
            JOIN inventory_products ip ON di.product_id = ip.id
            WHERE di.delivery_id = ? AND ABS(di.variance) > 0.01
        ");
        $stmt->execute([$data['delivery_id']]);
        $variances = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($variances as $variance) {
            $stmt = $pdo->prepare("
                INSERT INTO variances 
                (delivery_id, product_id, variance_qty, remarks, user_id, station_id, created_at)
                VALUES (?, ?, ?, 'confirmed_delivery', ?, ?, NOW())
            ");
            $stmt->execute([
                $data['delivery_id'],
                $variance['product_id'],
                $variance['variance'],
                $variance['variance'] < 0 ? 'Shortage detected' : 'Excess received',
                $user_id,
                $station_id
            ]);
        }
        
        // Update PO status
        $stmt = $pdo->prepare("
            UPDATE purchase_orders 
            SET status = 'delivered', delivered_by = ?, delivered_at = NOW()
            WHERE po_number = ? AND station_id = ?
        ");
        $stmt->execute([$user_id, $delivery['po_ref'], $station_id]);
        
        $pdo->commit();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Delivery confirmed and inventory updated'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Failed to confirm delivery: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Get Delivery Statistics
function getDeliveryStats($pdo, $station_id) {
    try {
        // Pending Deliveries (Approved POs not yet delivered)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as pending
            FROM purchase_orders 
            WHERE station_id = ? AND status = 'approved'
        ");
        $stmt->execute([$station_id]);
        $pending = $stmt->fetchColumn();
        
        // Today's Arrivals (Expected deliveries today)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as today_arrivals
            FROM purchase_orders 
            WHERE station_id = ? AND status = 'approved' 
            AND DATE(expected_delivery_date) = CURDATE()
        ");
        $stmt->execute([$station_id]);
        $today_arrivals = $stmt->fetchColumn();
        
        // Discrepancy Alerts (Deliveries with variance)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT d.id) as discrepancies
            FROM deliveries d
            JOIN delivery_items di ON d.id = di.delivery_id
            WHERE d.station_id = ? AND ABS(di.variance) > 0.01
        ");
        $stmt->execute([$station_id]);
        $discrepancies = $stmt->fetchColumn();
        
        header('Content-Type: application/json');
        echo json_encode([
            'pending' => $pending,
            'today_arrivals' => $today_arrivals,
            'discrepancies' => $discrepancies
        ]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Get Pending POs
function getPendingPOs($pdo, $station_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT po.id, po.po_number, s.name as supplier_name, po.expected_delivery_date,
                   COUNT(poi.id) as item_count
            FROM purchase_orders po
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            LEFT JOIN po_items poi ON po.id = poi.po_id
            WHERE po.station_id = ? AND po.status = 'approved'
            GROUP BY po.id
            ORDER BY po.expected_delivery_date ASC
        ");
        $stmt->execute([$station_id]);
        $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode($pos);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Generate Transaction ID
function generateTransactionID() {
    $timestamp = date('YmdHis');
    $random = mt_rand(1000, 9999);
    return "DEL-{$timestamp}-{$random}";
}

// Get initial data
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT * FROM inventory_products ORDER BY category, product_name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Deliveries Workflow - Petron POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .delivery-dashboard { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .stat-card { background: rgba(255,255,255,0.1); border-radius: 10px; padding: 20px; margin: 10px 0; text-align: center; }
        .stat-number { font-size: 2.5rem; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.9rem; opacity: 0.9; }
        .workflow-section { background: white; border-radius: 10px; padding: 25px; margin: 20px 0; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .step-header { border-left: 4px solid #667eea; padding-left: 15px; margin-bottom: 20px; }
        .step-title { color: #667eea; font-weight: bold; font-size: 1.2rem; margin-bottom: 5px; }
        .variance-negative { background-color: #ffebee !important; color: #c62828; font-weight: 600; }
        .variance-positive { background-color: #e8f5e8 !important; color: #2e7d32; font-weight: 600; }
        .quick-actions { position: fixed; right: 20px; top: 100px; z-index: 1000; }
        .quick-action-btn { display: block; width: 200px; margin: 10px 0; padding: 15px; border-radius: 8px; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-decoration: none; color: #333; transition: all 0.3s; }
        .quick-action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
        .delivery-table { margin-top: 20px; }
        .delivery-table th { background: #f8f9fa; font-weight: 600; }
        .form-section { background: #f8f9fa; border-radius: 8px; padding: 20px; margin: 15px 0; }
    </style>
</head>
<body>
    <?php include '../partials/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../partials/staff_sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <!-- Quick Actions Sidebar -->
                <div class="quick-actions">
                    <a href="#" class="quick-action-btn" onclick="openEncodeModal()">
                        <i class="fas fa-plus me-2"></i>[+] New Delivery
                    </a>
                    <a href="#" class="quick-action-btn" onclick="generateHistoryReport()">
                        <i class="fas fa-file-download me-2"></i>📄 History Report
                    </a>
                    <a href="#" class="quick-action-btn" onclick="openVarianceReview()">
                        <i class="fas fa-exclamation-triangle me-2"></i>⚠️ Variance Review
                    </a>
                </div>
                
                <!-- Delivery Dashboard -->
                <div class="delivery-dashboard p-4 mb-4">
                    <h2 class="mb-4"><i class="fas fa-tachometer-alt me-2"></i>Deliveries Dashboard (Overview)</h2>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="stat-number" id="pending-count">0</div>
                                <div class="stat-label">Pending Deliveries</div>
                                <small>Approved POs awaiting delivery</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="stat-number" id="today-arrivals">0</div>
                                <div class="stat-label">Today's Arrivals</div>
                                <small>Expected deliveries today</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="stat-number" id="discrepancy-alerts">0</div>
                                <div class="stat-label">Discrepancy Alerts</div>
                                <small>Deliveries with variance</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Delivery Management -->
                <div class="workflow-section">
                    <div class="step-header">
                        <div class="step-title">Delivery Management System</div>
                        <p class="text-muted">Complete delivery management with automated inventory integration and audit trail</p>
                    </div>
                    
                    <!-- Delivery Receipt Encoding -->
                    <div class="form-section">
                        <h5><i class="fas fa-edit me-2"></i>Encode Delivery Receipt</h5>
                        <p class="text-muted">Select PO and enter delivery details</p>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">PO Number</label>
                                <select id="poSelect" class="form-select" onchange="loadPODetails()">
                                    <option value="">Select PO Number</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Supplier Name</label>
                                <input type="text" id="supplierName" class="form-control" readonly placeholder="Auto-populated">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Reference No.</label>
                                <input type="text" id="referenceNo" class="form-control" placeholder="From receipt">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date Received</label>
                                <input type="date" id="dateReceived" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        
                        <!-- Delivery Items Table -->
                        <div class="mt-4">
                            <h6>Delivery Items (Reconciliation)</h6>
                            <div class="table-responsive">
                                <table class="table table-bordered delivery-table" id="deliveryItemsTable">
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
                                    <tbody id="deliveryItemsBody">
                                        <!-- Items will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="text-end mt-3">
                            <button type="button" class="btn btn-primary" onclick="encodeDelivery()">
                                <i class="fas fa-save me-2"></i>Encode Delivery
                            </button>
                        </div>
                    </div>
                    
                    <!-- Delivery Confirmation -->
                    <div class="form-section">
                        <h5><i class="fas fa-check-double me-2"></i>Confirm Delivery (The Reconciliation)</h5>
                        <p class="text-muted">Review and confirm delivery with inventory integration</p>
                        
                        <div id="confirmationSection" style="display: none;">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle me-2"></i>Ready for Confirmation</h6>
                                <p id="confirmationDetails"></p>
                            </div>
                            
                            <div class="text-end">
                                <button type="button" class="btn btn-success btn-lg" onclick="confirmDelivery()">
                                    <i class="fas fa-check-circle me-2"></i>Confirm & Update Inventory
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Hidden data for JavaScript -->
    <script>
        let deliveryData = {
            poNumber: '',
            supplierId: '',
            items: []
        };
        
        let suppliers = <?= json_encode($suppliers) ?>;
        let products = <?= json_encode($products) ?>;
        
        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            loadDeliveryStats();
            loadPendingPOs();
        });
        
        // Load delivery statistics
        async function loadDeliveryStats() {
            try {
                const response = await fetch('manager_deliveries_workflow.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_delivery_stats' })
                });
                const stats = await response.json();
                
                document.getElementById('pending-count').textContent = stats.pending || 0;
                document.getElementById('today-arrivals').textContent = stats.today_arrivals || 0;
                document.getElementById('discrepancy-alerts').textContent = stats.discrepancies || 0;
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }
        
        // Load pending POs
        async function loadPendingPOs() {
            try {
                const response = await fetch('manager_deliveries_workflow.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_pending_pos' })
                });
                const pos = await response.json();
                
                const poSelect = document.getElementById('poSelect');
                poSelect.innerHTML = '<option value="">Select PO Number</option>';
                
                pos.forEach(po => {
                    const option = document.createElement('option');
                    option.value = po.id;
                    option.textContent = `${po.po_number} - ${po.supplier_name} (${po.item_count} items)`;
                    option.dataset.supplier = po.supplier_name;
                    option.dataset.supplierId = po.supplier_id || '';
                    poSelect.appendChild(option);
                });
            } catch (error) {
                console.error('Error loading POs:', error);
            }
        }
        
        // Load PO details
        function loadPODetails() {
            const poSelect = document.getElementById('poSelect');
            const selectedOption = poSelect.options[poSelect.selectedIndex];
            
            if (selectedOption.value) {
                deliveryData.poNumber = selectedOption.textContent.split(' - ')[0];
                deliveryData.supplierId = selectedOption.dataset.supplierId;
                
                document.getElementById('supplierName').value = selectedOption.dataset.supplier;
                
                // Load PO items (this would come from database)
                loadPOItems(selectedOption.value);
            } else {
                clearDeliveryForm();
            }
        }
        
        // Load PO items (mock data - replace with actual API call)
        function loadPOItems(poId) {
            // Mock data - replace with actual database query
            const mockItems = [
                { product_id: 1, product_name: 'Diesel', category: 'Fuel', qty_ordered: 10000 },
                { product_id: 2, product_name: 'Premium', category: 'Fuel', qty_ordered: 5000 },
                { product_id: 3, product_name: 'Motor Oil 1L', category: 'Merchandise', qty_ordered: 24 }
            ];
            
            deliveryData.items = mockItems;
            populateDeliveryTable(mockItems);
        }
        
        // Populate delivery table
        function populateDeliveryTable(items) {
            const tbody = document.getElementById('deliveryItemsBody');
            tbody.innerHTML = '';
            
            items.forEach((item, index) => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${item.product_name}</td>
                    <td><span class="badge bg-info">${item.category}</span></td>
                    <td>${item.qty_ordered}</td>
                    <td>
                        <input type="number" class="form-control actual-received" 
                               data-index="${index}" 
                               data-ordered="${item.qty_ordered}"
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
        }
        
        // Calculate variance
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
        
        // Encode delivery
        async function encodeDelivery() {
            if (!validateDeliveryForm()) return;
            
            const items = collectDeliveryItems();
            
            try {
                const response = await fetch('manager_deliveries_workflow.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'encode_delivery',
                        po_number: deliveryData.poNumber,
                        supplier_id: deliveryData.supplierId,
                        reference_no: document.getElementById('referenceNo').value,
                        date_received: document.getElementById('dateReceived').value,
                        items: items
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Delivery encoded successfully!\\nTransaction ID: ' + result.transaction_id);
                    showConfirmationSection(items);
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                console.error('Error encoding delivery:', error);
                alert('Error encoding delivery');
            }
        }
        
        // Confirm delivery
        async function confirmDelivery() {
            if (!confirm('Are you sure you want to confirm this delivery?\\n\\nThis will:\\n• Update inventory levels\\n• Record audit trail\\n• Mark PO as completed')) {
                return;
            }
            
            try {
                const response = await fetch('manager_deliveries_workflow.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'confirm_delivery',
                        delivery_id: deliveryData.deliveryId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Delivery confirmed and inventory updated!');
                    clearDeliveryForm();
                    loadDeliveryStats();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                console.error('Error confirming delivery:', error);
                alert('Error confirming delivery');
            }
        }
        
        // Show confirmation section
        function showConfirmationSection(items) {
            const confirmationSection = document.getElementById('confirmationSection');
            const confirmationDetails = document.getElementById('confirmationDetails');
            
            const totalItems = items.length;
            const totalOrdered = items.reduce((sum, item) => sum + parseFloat(item.qty_ordered), 0);
            const totalReceived = items.reduce((sum, item) => sum + parseFloat(item.qty_received), 0);
            const totalVariance = totalReceived - totalOrdered;
            
            confirmationDetails.innerHTML = `
                <strong>Delivery Summary:</strong><br>
                • Total Items: ${totalItems}<br>
                • Total Ordered: ${totalOrdered}<br>
                • Total Received: ${totalReceived}<br>
                • Total Variance: ${totalVariance > 0 ? '+' : ''}${totalVariance}<br>
                • Status: Ready for inventory update and audit logging
            `;
            
            confirmationSection.style.display = 'block';
        }
        
        // Collect delivery items
        function collectDeliveryItems() {
            const items = [];
            document.querySelectorAll('.actual-received').forEach(input => {
                const index = parseInt(input.dataset.index);
                const remarks = document.querySelector(`input.remarks[data-index="${index}"]`).value;
                
                items.push({
                    product_id: deliveryData.items[index].product_id,
                    qty_ordered: parseFloat(input.dataset.ordered),
                    qty_received: parseFloat(input.value) || 0,
                    variance: (parseFloat(input.value) || 0) - parseFloat(input.dataset.ordered),
                    remarks: remarks
                });
            });
            
            return items;
        }
        
        // Validate delivery form
        function validateDeliveryForm() {
            const poNumber = document.getElementById('poSelect').value;
            const referenceNo = document.getElementById('referenceNo').value;
            const actualReceivedInputs = document.querySelectorAll('.actual-received');
            
            let hasActualReceived = false;
            actualReceivedInputs.forEach(input => {
                if (input.value && parseFloat(input.value) > 0) {
                    hasActualReceived = true;
                }
            });
            
            if (!poNumber) {
                alert('Please select a PO Number');
                return false;
            }
            
            if (!referenceNo) {
                alert('Please enter a Reference Number');
                return false;
            }
            
            if (!hasActualReceived) {
                alert('Please enter actual received quantities');
                return false;
            }
            
            return true;
        }
        
        // Clear delivery form
        function clearDeliveryForm() {
            document.getElementById('poSelect').value = '';
            document.getElementById('supplierName').value = '';
            document.getElementById('referenceNo').value = '';
            document.getElementById('deliveryItemsBody').innerHTML = '';
            document.getElementById('confirmationSection').style.display = 'none';
            deliveryData = { poNumber: '', supplierId: '', items: [] };
        }
        
        // Quick action functions
        function openEncodeModal() {
            window.location.href = '#delivery-form';
            document.getElementById('poSelect').focus();
        }
        
        function generateHistoryReport() {
            alert('History report download feature - would generate PDF/Excel of all deliveries');
        }
        
        function openVarianceReview() {
            alert('Variance review feature - would show list of all deliveries with variances for audit');
        }
    </script>
<?php include __DIR__ . '/../partials/footer.php'; ?>