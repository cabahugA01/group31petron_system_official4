<?php
/**
 * COMPREHENSIVE DELIVERY MANAGEMENT SYSTEM
 * 
 * Flow Implementation:
 * 1. Source/Auto-Fetch: Approved POs, Supplier Docs, Tables
 * 2. Manager Actions: Encode, Confirm, Update, Log, Close
 * 3. Output/Flow: Confirm Status, Inventory Update, Audit Trail, PO Update
 */

session_start();
require_login();
require_once '../config/database_config.php';
require_once '../public/db_connect.php';
require_once '../backend/lib.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'admin', 'superadmin'])) {
    header('Location: ../login.php');
    exit;
}

$station_id = $_SESSION['station_id'] ?? 1;
$user_id = $_SESSION['user_id'];
$me = current_user();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'encode_delivery_receipt':
            handleEncodeDeliveryReceipt();
            break;
        case 'confirm_delivery':
            handleConfirmDelivery();
            break;
        case 'update_inventory':
            handleUpdateInventory();
            break;
        case 'log_discrepancy':
            handleLogDiscrepancy();
            break;
        case 'close_delivery':
            handleCloseDelivery();
            break;
    }
}

// Handle API requests for JavaScript
if (isset($_GET['get_delivery_items'])) {
    handleGetDeliveryItemsAPI();
    exit;
}

/**
 * API Handler for getting delivery items
 */
function handleGetDeliveryItemsAPI() {
    global $pdo, $station_id;
    
    $delivery_id = $_GET['delivery_id'] ?? 0;
    if (!$delivery_id) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Delivery ID required']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                di.*,
                ip.category
            FROM delivery_items di
            LEFT JOIN inventory_products ip ON di.product_id = ip.id
            WHERE di.delivery_id = ?
            ORDER BY ip.category, di.product_name
        ");
        $stmt->execute([$delivery_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode(['items' => $items]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
}

/**
 * Step 1: Encode Delivery Receipt
 * Auto-fetch from Approved POs, Supplier Documents, and Product Tables
 */
function handleEncodeDeliveryReceipt() {
    global $pdo, $station_id, $user_id;
    
    try {
        $po_id = $_POST['po_id'] ?? '';
        $delivery_date = $_POST['delivery_date'] ?? '';
        $supplier_invoice = $_POST['supplier_invoice'] ?? '';
        $delivery_notes = $_POST['delivery_notes'] ?? '';
        
        if (!$po_id || !$delivery_date) {
            throw new Exception('PO ID and Delivery Date are required');
        }
        
        // Auto-fetch PO details
        $stmt = $pdo->prepare("
            SELECT po.*, s.name as supplier_name, s.contact_person, s.contact_phone
            FROM purchase_orders po
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.id = ? AND po.station_id = ? AND po.status = 'approved'
        ");
        $stmt->execute([$po_id, $station_id]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$po) {
            throw new Exception('Approved PO not found');
        }
        
        // Create delivery record
        $stmt = $pdo->prepare("
            INSERT INTO deliveries (
                po_id, station_id, supplier_id, delivery_date, 
                supplier_invoice, delivery_notes, status, 
                encoded_by, encoded_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'encoded', ?, NOW())
        ");
        $stmt->execute([
            $po_id, $station_id, $po['supplier_id'], 
            $delivery_date, $supplier_invoice, $delivery_notes, $user_id
        ]);
        
        $delivery_id = $pdo->lastInsertId();
        
        // Auto-fetch PO items and create delivery items
        $stmt = $pdo->prepare("
            SELECT poi.*, ip.product_name, ip.category
            FROM po_items poi
            LEFT JOIN inventory_products ip ON poi.product_id = ip.id
            WHERE poi.po_id = ?
        ");
        $stmt->execute([$po_id]);
        $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($po_items as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO delivery_items (
                    delivery_id, product_id, product_name, category,
                    quantity_ordered, unit_price, total_price
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $delivery_id, $item['product_id'], $item['product_name'], 
                $item['category'], $item['quantity_ordered'], $item['unit_price'], 
                $item['quantity_ordered'] * $item['unit_price']
            ]);
        }
        
        log_activity($pdo, $user_id, 'Encode Delivery Receipt', 
            "Encoded delivery for PO #{$po['po_number']}", 'delivery_management');
        
        $_SESSION['success'] = "Delivery receipt encoded successfully! Delivery ID: {$delivery_id}";
        header("Location: delivery_management.php?delivery_id={$delivery_id}");
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error encoding delivery receipt: " . $e->getMessage();
        header("Location: delivery_management.php");
        exit;
    }
}

/**
 * Step 2: Confirm Delivery
 * Check actual vs ordered quantities
 */
function handleConfirmDelivery() {
    global $pdo, $station_id, $user_id;
    
    try {
        $delivery_id = $_POST['delivery_id'] ?? '';
        $actual_quantities = $_POST['actual_quantities'] ?? [];
        
        if (!$delivery_id) {
            throw new Exception('Delivery ID required');
        }
        
        $pdo->beginTransaction();
        
        // Update delivery items with actual quantities
        foreach ($actual_quantities as $item_id => $actual_qty) {
            $actual_qty = (float)$actual_qty;
            
            $stmt = $pdo->prepare("
                UPDATE delivery_items 
                SET quantity_actual = ?, variance = quantity_ordered - ?
                WHERE id = ? AND delivery_id = ?
            ");
            $stmt->execute([$actual_qty, $actual_qty, $item_id, $delivery_id]);
        }
        
        // Update delivery status
        $stmt = $pdo->prepare("
            UPDATE deliveries 
            SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW()
            WHERE id = ? AND station_id = ?
        ");
        $stmt->execute([$user_id, $delivery_id, $station_id]);
        
        $pdo->commit();
        
        log_activity($pdo, $user_id, 'Confirm Delivery', 
            "Confirmed delivery ID: {$delivery_id}", 'delivery_management');
        
        $_SESSION['success'] = "Delivery confirmed successfully!";
        header("Location: delivery_management.php?delivery_id={$delivery_id}");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollback();
        $_SESSION['error'] = "Error confirming delivery: " . $e->getMessage();
        header("Location: delivery_management.php");
        exit;
    }
}

/**
 * Step 3: Update Inventory
 * Automatic stock adjustment
 */
function handleUpdateInventory() {
    global $pdo, $station_id, $user_id;
    
    try {
        $delivery_id = $_POST['delivery_id'] ?? '';
        
        if (!$delivery_id) {
            throw new Exception('Delivery ID required');
        }
        
        $pdo->beginTransaction();
        
        // Get delivery items with actual quantities
        $stmt = $pdo->prepare("
            SELECT di.*, ip.category
            FROM delivery_items di
            LEFT JOIN inventory_products ip ON di.product_id = ip.id
            WHERE di.delivery_id = ? AND di.quantity_actual > 0
        ");
        $stmt->execute([$delivery_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $quantity = $item['quantity_actual'];
            $category = $item['category'];
            
            if ($category === 'Fuel') {
                // Update fuel inventory
                $stmt = $pdo->prepare("
                    INSERT INTO fuel_inventory (station_id, fuel_type_id, current_stock, last_updated)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    current_stock = current_stock + ?, last_updated = NOW()
                ");
                $stmt->execute([$station_id, $product_id, $quantity, $quantity]);
            } else {
                // Update merchandise inventory
                $stmt = $pdo->prepare("
                    INSERT INTO inventory (station_id, product_id, quantity_on_hand, last_updated)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    quantity_on_hand = quantity_on_hand + ?, last_updated = NOW()
                ");
                $stmt->execute([$station_id, $product_id, $quantity, $quantity]);
            }
        }
        
        // Update delivery status
        $stmt = $pdo->prepare("
            UPDATE deliveries 
            SET status = 'inventory_updated', inventory_updated_by = ?, inventory_updated_at = NOW()
            WHERE id = ? AND station_id = ?
        ");
        $stmt->execute([$user_id, $delivery_id, $station_id]);
        
        $pdo->commit();
        
        log_activity($pdo, $user_id, 'Update Inventory', 
            "Updated inventory for delivery ID: {$delivery_id}", 'delivery_management');
        
        $_SESSION['success'] = "Inventory updated successfully!";
        header("Location: delivery_management.php?delivery_id={$delivery_id}");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollback();
        $_SESSION['error'] = "Error updating inventory: " . $e->getMessage();
        header("Location: delivery_management.php");
        exit;
    }
}

/**
 * Step 4: Log Discrepancies
 * Record any variances for audit trail
 */
function handleLogDiscrepancy() {
    global $pdo, $station_id, $user_id;
    
    try {
        $delivery_id = $_POST['delivery_id'] ?? '';
        $discrepancy_notes = $_POST['discrepancy_notes'] ?? '';
        $discrepancy_type = $_POST['discrepancy_type'] ?? '';
        
        if (!$delivery_id) {
            throw new Exception('Delivery ID required');
        }
        
        // Log discrepancy
        $stmt = $pdo->prepare("
            INSERT INTO delivery_discrepancies (
                delivery_id, station_id, discrepancy_type, notes, 
                reported_by, reported_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$delivery_id, $station_id, $discrepancy_type, $discrepancy_notes, $user_id]);
        
        // Update delivery status to show discrepancy
        $stmt = $pdo->prepare("
            UPDATE deliveries 
            SET status = 'discrepancy_logged', discrepancy_notes = ?
            WHERE id = ? AND station_id = ?
        ");
        $stmt->execute([$discrepancy_notes, $delivery_id, $station_id]);
        
        log_activity($pdo, $user_id, 'Log Discrepancy', 
            "Logged discrepancy for delivery ID: {$delivery_id} - {$discrepancy_type}", 'delivery_management');
        
        $_SESSION['success'] = "Discrepancy logged successfully!";
        header("Location: delivery_management.php?delivery_id={$delivery_id}");
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error logging discrepancy: " . $e->getMessage();
        header("Location: delivery_management.php");
        exit;
    }
}

/**
 * Step 5: Close Delivery
 * Final step - complete the workflow
 */
function handleCloseDelivery() {
    global $pdo, $station_id, $user_id;
    
    try {
        $delivery_id = $_POST['delivery_id'] ?? '';
        $closing_notes = $_POST['closing_notes'] ?? '';
        
        if (!$delivery_id) {
            throw new Exception('Delivery ID required');
        }
        
        $pdo->beginTransaction();
        
        // Get delivery and PO information
        $stmt = $pdo->prepare("
            SELECT d.*, po.po_number, po.status as po_status
            FROM deliveries d
            LEFT JOIN purchase_orders po ON d.po_id = po.id
            WHERE d.id = ? AND d.station_id = ?
        ");
        $stmt->execute([$delivery_id, $station_id]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$delivery) {
            throw new Exception('Delivery not found');
        }
        
        // Update delivery status to closed
        $stmt = $pdo->prepare("
            UPDATE deliveries 
            SET status = 'closed', closed_by = ?, closed_at = NOW(), closing_notes = ?
            WHERE id = ? AND station_id = ?
        ");
        $stmt->execute([$user_id, $closing_notes, $delivery_id, $station_id]);
        
        // Update PO status to delivered/closed
        if ($delivery['po_id']) {
            $stmt = $pdo->prepare("
                UPDATE purchase_orders 
                SET status = 'delivered', delivered_at = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$delivery['po_id'], $station_id]);
        }
        
        $pdo->commit();
        
        log_activity($pdo, $user_id, 'Close Delivery', 
            "Closed delivery ID: {$delivery_id} for PO #{$delivery['po_number']}", 'delivery_management');
        
        $_SESSION['success'] = "Delivery closed successfully! Workflow complete.";
        header("Location: delivery_management.php");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollback();
        $_SESSION['error'] = "Error closing delivery: " . $e->getMessage();
        header("Location: delivery_management.php");
        exit;
    }
}

// Get data for the interface
$delivery_id = $_GET['delivery_id'] ?? '';
$delivery = null;
$delivery_items = [];
$approved_pos = [];

if ($delivery_id) {
    // Get specific delivery details
    $stmt = $pdo->prepare("
        SELECT d.*, po.po_number, s.name as supplier_name,
               u1.name as encoded_by_name, u2.name as confirmed_by_name,
               u3.name as inventory_updated_by_name, u4.name as closed_by_name
        FROM deliveries d
        LEFT JOIN purchase_orders po ON d.po_id = po.id
        LEFT JOIN suppliers s ON d.supplier_id = s.id
        LEFT JOIN users u1 ON d.encoded_by = u1.id
        LEFT JOIN users u2 ON d.confirmed_by = u2.id
        LEFT JOIN users u3 ON d.inventory_updated_by = u3.id
        LEFT JOIN users u4 ON d.closed_by = u4.id
        WHERE d.id = ? AND d.station_id = ?
    ");
    $stmt->execute([$delivery_id, $station_id]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($delivery) {
        // Get delivery items
        $stmt = $pdo->prepare("
            SELECT * FROM delivery_items WHERE delivery_id = ?
        ");
        $stmt->execute([$delivery_id]);
        $delivery_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get approved POs for dropdown
$stmt = $pdo->prepare("
    SELECT po.id, po.po_number, s.name as supplier_name, po.total_amount,
           COUNT(poi.id) as item_count
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN po_items poi ON po.id = poi.po_id
    WHERE po.station_id = ? AND po.status = 'approved'
    AND po.id NOT IN (SELECT po_id FROM deliveries WHERE station_id = ?)
    GROUP BY po.id
    ORDER BY po.created_at DESC
");
$stmt->execute([$station_id, $station_id]);
$approved_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent deliveries
$stmt = $pdo->prepare("
    SELECT d.*, po.po_number, s.name as supplier_name
    FROM deliveries d
    LEFT JOIN purchase_orders po ON d.po_id = po.id
    LEFT JOIN suppliers s ON d.supplier_id = s.id
    WHERE d.station_id = ?
    ORDER BY d.created_at DESC
    LIMIT 10
");
$stmt->execute([$station_id]);
$recent_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Management - Petron POS</title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        .workflow-step {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            position: relative;
        }
        
        .workflow-step::before {
            content: '';
            position: absolute;
            bottom: -20px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 20px solid transparent;
            border-right: 20px solid transparent;
            border-top: 20px solid #764ba2;
        }
        
        .workflow-step:last-child::before {
            display: none;
        }
        
        .step-completed {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        
        .step-completed::before {
            border-top-color: #20c997;
        }
        
        .step-current {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            box-shadow: 0 4px 20px rgba(255, 193, 7, 0.4);
        }
        
        .step-current::before {
            border-top-color: #fd7e14;
        }
        
        .delivery-card {
            transition: all 0.3s ease;
            border-left: 4px solid #007bff;
        }
        
        .delivery-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .status-encoded { border-left-color: #6c757d; }
        .status-confirmed { border-left-color: #17a2b8; }
        .status-inventory_updated { border-left-color: #ffc107; }
        .status-discrepancy_logged { border-left-color: #dc3545; }
        .status-closed { border-left-color: #28a745; }
        
        .variance-positive { color: #28a745; }
        .variance-negative { color: #dc3545; }
        .variance-zero { color: #6c757d; }
        
        .source-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .action-section {
            background: #e3f2fd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .output-section {
            background: #f3e5f5;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
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
                        <i class="fas fa-truck me-2"></i>Delivery Management
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#encodeDeliveryModal">
                            <i class="fas fa-plus me-2"></i>Encode New Delivery
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

                <!-- Workflow Overview -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-sitemap me-2"></i>Delivery Workflow Overview
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="source-section">
                                            <h6><i class="fas fa-database me-2"></i>1. Source/Auto-Fetch</h6>
                                            <p class="mb-0"><small>System automatically pulls data from Approved POs, Supplier Documents, and Product Tables. No manual data entry required for basic information.</small></p>
                                        </div>
                                        
                                        <div class="action-section">
                                            <h6><i class="fas fa-tasks me-2"></i>2. Manager Actions</h6>
                                            <p class="mb-0"><small>Encode Delivery Receipt ? Confirm Delivery ? Update Inventory ? Log Discrepancies ? Close Delivery</small></p>
                                        </div>
                                        
                                        <div class="output-section">
                                            <h6><i class="fas fa-chart-line me-2"></i>3. Output/Flow</h6>
                                            <p class="mb-0"><small>Confirm Status ? Inventory Update ? Audit Trail ? PO Status Update ? Workflow Complete</small></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($delivery): ?>
                    <!-- Delivery Details and Workflow Steps -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="fas fa-clipboard-list me-2"></i>Delivery #<?= $delivery['id'] ?> - <?= ucfirst(str_replace('_', ' ', $delivery['status'])) ?>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <strong>PO Number:</strong> <?= htmlspecialchars($delivery['po_number']) ?><br>
                                            <strong>Supplier:</strong> <?= htmlspecialchars($delivery['supplier_name']) ?><br>
                                            <strong>Delivery Date:</strong> <?= date('M j, Y', strtotime($delivery['delivery_date'])) ?>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Supplier Invoice:</strong> <?= htmlspecialchars($delivery['supplier_invoice']) ?><br>
                                            <strong>Encoded By:</strong> <?= htmlspecialchars($delivery['encoded_by_name']) ?><br>
                                            <strong>Encoded At:</strong> <?= date('M j, Y H:i', strtotime($delivery['encoded_at'])) ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Workflow Steps -->
                                    <div class="row mt-4">
                                        <div class="col-md-12">
                                            <h6>Workflow Progress</h6>
                                            
                                            <div class="workflow-step <?= $delivery['status'] === 'encoded' ? 'step-current' : 'step-completed' ?>">
                                                <h6><i class="fas fa-edit me-2"></i>Step 1: Encode Delivery Receipt</h6>
                                                <p class="mb-0">Auto-fetched data from PO #<?= $delivery['po_number'] ?>. Delivery receipt encoded successfully.</p>
                                                <?php if ($delivery['encoded_at']): ?>
                                                    <small><i class="fas fa-check me-1"></i>Completed by <?= $delivery['encoded_by_name'] ?> at <?= date('M j, H:i', strtotime($delivery['encoded_at'])) ?></small>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="workflow-step <?= $delivery['status'] === 'confirmed' ? 'step-current' : ($in_array($delivery['status'], ['inventory_updated', 'discrepancy_logged', 'closed']) ? 'step-completed' : '') ?>">
                                                <h6><i class="fas fa-check-double me-2"></i>Step 2: Confirm Delivery</h6>
                                                <p class="mb-0">Verify actual quantities vs ordered quantities. Check for shortages or excess.</p>
                                                <?php if ($delivery['confirmed_at']): ?>
                                                    <small><i class="fas fa-check me-1"></i>Completed by <?= $delivery['confirmed_by_name'] ?> at <?= date('M j, H:i', strtotime($delivery['confirmed_at'])) ?></small>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-light" onclick="confirmDelivery(<?= $delivery['id'] ?>)">
                                                        <i class="fas fa-check me-1"></i>Confirm Delivery
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="workflow-step <?= $delivery['status'] === 'inventory_updated' ? 'step-current' : ($delivery['status'] === 'closed' ? 'step-completed' : '') ?>">
                                                <h6><i class="fas fa-boxes me-2"></i>Step 3: Update Inventory</h6>
                                                <p class="mb-0">Automatic stock adjustment based on confirmed quantities.</p>
                                                <?php if ($delivery['inventory_updated_at']): ?>
                                                    <small><i class="fas fa-check me-1"></i>Completed by <?= $delivery['inventory_updated_by_name'] ?> at <?= date('M j, H:i', strtotime($delivery['inventory_updated_at'])) ?></small>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-light" onclick="updateInventory(<?= $delivery['id'] ?>)">
                                                        <i class="fas fa-boxes me-1"></i>Update Inventory
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="workflow-step <?= $delivery['status'] === 'discrepancy_logged' ? 'step-current' : '' ?>">
                                                <h6><i class="fas fa-exclamation-triangle me-2"></i>Step 4: Log Discrepancies (if any)</h6>
                                                <p class="mb-0">Record any variances for audit trail and supplier follow-up.</p>
                                                <?php if ($delivery['status'] === 'discrepancy_logged'): ?>
                                                    <small><i class="fas fa-check me-1"></i>Discrepancies logged</small>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-light" onclick="logDiscrepancy(<?= $delivery['id'] ?>)">
                                                        <i class="fas fa-exclamation-triangle me-1"></i>Log Discrepancy
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="workflow-step <?= $delivery['status'] === 'closed' ? 'step-current' : '' ?>">
                                                <h6><i class="fas fa-lock me-2"></i>Step 5: Close Delivery</h6>
                                                <p class="mb-0">Final step - complete workflow and update PO status.</p>
                                                <?php if ($delivery['closed_at']): ?>
                                                    <small><i class="fas fa-check me-1"></i>Completed by <?= $delivery['closed_by_name'] ?> at <?= date('M j, H:i', strtotime($delivery['closed_at'])) ?></small>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-light" onclick="closeDelivery(<?= $delivery['id'] ?>)">
                                                        <i class="fas fa-lock me-1"></i>Close Delivery
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Delivery Items -->
                                    <?php if (!empty($delivery_items)): ?>
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <h6>Delivery Items</h6>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Product</th>
                                                            <th>Category</th>
                                                            <th>Ordered</th>
                                                            <th>Actual</th>
                                                            <th>Variance</th>
                                                            <th>Unit Price</th>
                                                            <th>Total</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($delivery_items as $item): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                            <td><?= htmlspecialchars($item['category']) ?></td>
                                                            <td><?= number_format($item['quantity_ordered'], 2) ?></td>
                                                            <td>
                                                                <?php if ($delivery['status'] === 'encoded'): ?>
                                                                    <input type="number" class="form-control form-control-sm" 
                                                                           id="actual_<?= $item['id'] ?>" 
                                                                           value="<?= number_format($item['quantity_actual'] ?? $item['quantity_ordered'], 2) ?>"
                                                                           step="0.01">
                                                                <?php else: ?>
                                                                    <?= number_format($item['quantity_actual'] ?? $item['quantity_ordered'], 2) ?>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="<?= ($item['variance'] ?? 0) > 0 ? 'variance-positive' : (($item['variance'] ?? 0) < 0 ? 'variance-negative' : 'variance-zero') ?>">
                                                                <?= number_format($item['variance'] ?? 0, 2) ?>
                                                            </td>
                                                            <td>?<?= number_format($item['unit_price'], 2) ?></td>
                                                            <td>?<?= number_format(($item['quantity_actual'] ?? $item['quantity_ordered']) * $item['unit_price'], 2) ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recent Deliveries -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-history me-2"></i>Recent Deliveries
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_deliveries)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-truck fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No deliveries found. Start by encoding your first delivery.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>PO Number</th>
                                                    <th>Supplier</th>
                                                    <th>Delivery Date</th>
                                                    <th>Status</th>
                                                    <th>Encoded By</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_deliveries as $del): ?>
                                                <tr class="delivery-card status-<?= $del['status'] ?>">
                                                    <td><strong><?= $del['id'] ?></strong></td>
                                                    <td><?= htmlspecialchars($del['po_number']) ?></td>
                                                    <td><?= htmlspecialchars($del['supplier_name']) ?></td>
                                                    <td><?= date('M j, Y', strtotime($del['delivery_date'])) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= 
                                                            $del['status'] == 'closed' ? 'success' : 
                                                            ($del['status'] == 'discrepancy_logged' ? 'danger' : 
                                                            ($del['status'] == 'inventory_updated' ? 'warning' : 'secondary')) 
                                                        ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $del['status'])) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= htmlspecialchars($del['encoded_by_name']) ?></td>
                                                    <td>
                                                        <a href="delivery_management.php?delivery_id=<?= $del['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
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

    <!-- Encode Delivery Modal -->
    <div class="modal fade" id="encodeDeliveryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Encode Delivery Receipt
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="encode_delivery_receipt">
                    <div class="modal-body">
                        <div class="source-section mb-3">
                            <h6><i class="fas fa-database me-2"></i>Auto-Fetch Source</h6>
                            <p class="mb-2"><small>System will automatically pull data from approved POs, supplier documents, and product tables.</small></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Select Approved Purchase Order</label>
                            <select name="po_id" class="form-select" required>
                                <option value="">Choose Approved PO</option>
                                <?php foreach ($approved_pos as $po): ?>
                                <option value="<?= $po['id'] ?>">
                                    <?= htmlspecialchars($po['po_number']) ?> - 
                                    <?= htmlspecialchars($po['supplier_name']) ?> - 
                                    ?<?= number_format($po['total_amount'], 2) ?> 
                                    (<?= $po['item_count'] ?> items)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($approved_pos)): ?>
                                <small class="text-muted">No approved POs available for delivery encoding.</small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Delivery Date</label>
                                    <input type="date" name="delivery_date" class="form-control" 
                                           value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Supplier Invoice Number</label>
                                    <input type="text" name="supplier_invoice" class="form-control" 
                                           placeholder="e.g., INV-2024-001" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Delivery Notes</label>
                            <textarea name="delivery_notes" class="form-control" rows="3" 
                                      placeholder="Optional delivery notes..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Encode Delivery Receipt
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Confirm Delivery Modal -->
    <div class="modal fade" id="confirmDeliveryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-check-double me-2"></i>Confirm Delivery
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="confirmDeliveryForm">
                    <input type="hidden" name="action" value="confirm_delivery">
                    <input type="hidden" name="delivery_id" id="confirm_delivery_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Please verify actual quantities received vs ordered quantities. Check for any shortages or excess items.
                        </div>
                        
                        <div id="confirmItemsContainer">
                            <!-- Items will be loaded here via JavaScript -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check me-2"></i>Confirm Delivery
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Log Discrepancy Modal -->
    <div class="modal fade" id="logDiscrepancyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Log Discrepancy
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="log_discrepancy">
                    <input type="hidden" name="delivery_id" id="discrepancy_delivery_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Discrepancy Type</label>
                            <select name="discrepancy_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="shortage">Shortage</option>
                                <option value="excess">Excess</option>
                                <option value="damage">Damage</option>
                                <option value="wrong_product">Wrong Product</option>
                                <option value="quality_issue">Quality Issue</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Discrepancy Notes</label>
                            <textarea name="discrepancy_notes" class="form-control" rows="4" 
                                      placeholder="Describe the discrepancy in detail..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>Log Discrepancy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Close Delivery Modal -->
    <div class="modal fade" id="closeDeliveryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-lock me-2"></i>Close Delivery
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="close_delivery">
                    <input type="hidden" name="delivery_id" id="close_delivery_id">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> Closing this delivery will complete the workflow and update the PO status. This action cannot be undone.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Closing Notes</label>
                            <textarea name="closing_notes" class="form-control" rows="3" 
                                      placeholder="Optional closing notes..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-lock me-2"></i>Close Delivery
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDelivery(deliveryId) {
            document.getElementById('confirm_delivery_id').value = deliveryId;
            
            // Load delivery items for confirmation
            fetch(`delivery_management.php?get_delivery_items=1&delivery_id=${deliveryId}`)
                .then(response => response.json())
                .then(data => {
                    let html = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Product</th><th>Ordered</th><th>Actual</th></tr></thead><tbody>';
                    
                    data.items.forEach(item => {
                        html += `
                            <tr>
                                <td>${item.product_name}</td>
                                <td>${item.quantity_ordered}</td>
                                <td>
                                    <input type="number" class="form-control form-control-sm" 
                                           name="actual_quantities[${item.id}]" 
                                           value="${item.quantity_ordered}" step="0.01" required>
                                </td>
                            </tr>
                        `;
                    });
                    
                    html += '</tbody></table></div>';
                    document.getElementById('confirmItemsContainer').innerHTML = html;
                    
                    new bootstrap.Modal(document.getElementById('confirmDeliveryModal')).show();
                })
                .catch(error => {
                    console.error('Error loading delivery items:', error);
                    alert('Error loading delivery items');
                });
        }
        
        function updateInventory(deliveryId) {
            if (confirm('This will automatically update inventory based on confirmed quantities. Continue?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_inventory">
                    <input type="hidden" name="delivery_id" value="${deliveryId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function logDiscrepancy(deliveryId) {
            document.getElementById('discrepancy_delivery_id').value = deliveryId;
            new bootstrap.Modal(document.getElementById('logDiscrepancyModal')).show();
        }
        
        function closeDelivery(deliveryId) {
            document.getElementById('close_delivery_id').value = deliveryId;
            new bootstrap.Modal(document.getElementById('closeDeliveryModal')).show();
        }
    </script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
