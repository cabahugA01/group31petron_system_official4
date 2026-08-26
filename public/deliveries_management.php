<?php
// Deliveries Management System
session_start();
require_login();
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
    if (isset($_POST['create_delivery'])) {
        // Create new delivery record
        try {
            $pdo->beginTransaction();
            
            // Generate delivery number
            $delivery_number = 'DEL-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Insert delivery header
            $stmt = $pdo->prepare("
                INSERT INTO merchandise_deliveries 
                (delivery_number, po_id, station_id, supplier_id, delivery_date, received_by, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $delivery_number,
                $_POST['po_id'],
                $station_id,
                $_POST['supplier_id'],
                $_POST['delivery_date'],
                $user_id
            ]);
            $delivery_id = $pdo->lastInsertId();
            
            // Insert delivery items
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO delivery_items 
                    (delivery_id, po_item_id, product_id, quantity_received, quantity_ordered, unit_price, quality_status, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($_POST['items'] as $item) {
                    if (!empty($item['po_item_id']) && !empty($item['quantity_received'])) {
                        $stmt->execute([
                            $delivery_id,
                            $item['po_item_id'],
                            $item['product_id'],
                            $item['quantity_received'],
                            $item['quantity_ordered'],
                            $item['unit_price'],
                            $item['quality_status'] ?? 'good',
                            $item['notes'] ?? ''
                        ]);
                        
                        // Update PO item received quantity
                        $updateStmt = $pdo->prepare("
                            UPDATE po_items 
                            SET quantity_received = quantity_received + ?, 
                                status = CASE 
                                    WHEN quantity_received + ? >= quantity_ordered THEN 'fully_received'
                                    WHEN quantity_received + ? > 0 THEN 'partially_received'
                                    ELSE 'pending'
                                END
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$item['quantity_received'], $item['quantity_received'], $item['quantity_received'], $item['po_item_id']]);
                        
                        // Update inventory
                        updateInventory($pdo, $station_id, $item['product_id'], $item['quantity_received']);
                        
                        // Log discrepancy if variance exists
                        $variance = $item['quantity_received'] - $item['quantity_ordered'];
                        if (abs($variance) > 0.01) { // Small tolerance for decimal precision
                            logDiscrepancy($pdo, $station_id, $delivery_id, $item, $variance, $user_id);
                        }
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Delivery recorded successfully!";
            header("Location: deliveries_management.php");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollback();
            $_SESSION['error'] = "Error creating delivery: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['confirm_delivery'])) {
        // Confirm delivery
        try {
            $stmt = $pdo->prepare("
                UPDATE merchandise_deliveries 
                SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() 
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$user_id, $_POST['delivery_id'], $station_id]);
            
            $_SESSION['success'] = "Delivery confirmed!";
            header("Location: deliveries_management.php");
            exit;
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Error confirming delivery: " . $e->getMessage();
        }
    }
}

// Helper functions
function updateInventory($pdo, $station_id, $product_id, $quantity) {
    // Check if inventory record exists
    $stmt = $pdo->prepare("SELECT id, quantity_on_hand FROM inventory WHERE station_id = ? AND product_id = ?");
    $stmt->execute([$station_id, $product_id]);
    $inventory = $stmt->fetch();
    
    if ($inventory) {
        // Update existing inventory
        $stmt = $pdo->prepare("
            UPDATE inventory 
            SET quantity_on_hand = quantity_on_hand + ?, last_updated = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$quantity, $inventory['id']]);
    } else {
        // Create new inventory record
        $stmt = $pdo->prepare("
            INSERT INTO inventory (station_id, product_id, quantity_on_hand, last_updated) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$station_id, $product_id, $quantity]);
    }
}

function logDiscrepancy($pdo, $station_id, $delivery_id, $item, $variance, $user_id) {
    $stmt = $pdo->prepare("
        INSERT INTO delivery_discrepancies 
        (station_id, delivery_id, product_name, category, ordered_quantity, actual_quantity, variance, variance_percentage, discrepancy_type, notes, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $product_name = $item['product_name'] ?? '';
    $category = $item['category'] ?? '';
    $variance_percentage = ($item['quantity_ordered'] > 0) ? ($variance / $item['quantity_ordered'] * 100) : 0;
    $discrepancy_type = ($variance > 0) ? 'over_delivery' : 'short_delivery';
    $notes = "Variance: " . number_format(abs($variance), 2) . " units";
    
    $stmt->execute([
        $station_id,
        $delivery_id,
        $product_name,
        $category,
        $item['quantity_ordered'],
        $item['quantity_received'],
        $variance,
        $variance_percentage,
        $discrepancy_type,
        $notes,
        $user_id
    ]);
}

// Get data for dropdowns
$approved_pos = $pdo->query("
    SELECT po.*, s.name as supplier_name 
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.station_id = ? AND po.status = 'approved' AND po.delivery_status != 'fully_delivered'
    ORDER BY po.created_at DESC
", [$station_id])->fetchAll();

$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();

// Get existing deliveries
$deliveries = $pdo->query("
    SELECT md.*, po.po_number, s.name as supplier_name,
           COUNT(di.id) as item_count,
           SUM(di.total_amount) as total_amount,
           u.name as received_by_name
    FROM merchandise_deliveries md
    LEFT JOIN purchase_orders po ON md.po_id = po.id
    LEFT JOIN suppliers s ON md.supplier_id = s.id
    LEFT JOIN users u ON md.received_by = u.id
    LEFT JOIN delivery_items di ON md.id = di.delivery_id
    WHERE md.station_id = ?
    GROUP BY md.id
    ORDER BY md.delivery_date DESC
", [$station_id])->fetchAll();

// Get pending discrepancies
$pending_discrepancies = $pdo->query("
    SELECT dd.*, md.delivery_number, s.name as station_name
    FROM delivery_discrepancies dd
    LEFT JOIN merchandise_deliveries md ON dd.delivery_id = md.id
    LEFT JOIN stations s ON dd.station_id = s.id
    WHERE dd.station_id = ? AND dd.status = 'open'
    ORDER BY dd.created_at DESC
", [$station_id])->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deliveries Management - Petron POS</title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        .delivery-card { transition: all 0.3s ease; border-left: 4px solid #007bff; }
        .delivery-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .status-pending { border-left-color: #ffc107; }
        .status-confirmed { border-left-color: #28a745; }
        .status-discrepancy { border-left-color: #dc3545; }
        .discrepancy-item { background: #f8d7da; border: 1px solid #f5c6cb; }
        .item-row { border-bottom: 1px solid #eee; padding: 8px 0; }
        .variance-positive { color: #28a745; }
        .variance-negative { color: #dc3545; }
        .add-delivery-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .discrepancy-alert { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); color: white; }
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
                        <i class="fas fa-truck me-2"></i>Deliveries Management
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary add-delivery-btn" data-bs-toggle="modal" data-bs-target="#createDeliveryModal">
                            <i class="fas fa-plus me-2"></i>Record Delivery
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

                <!-- Pending Discrepancies Alert -->
                <?php if (!empty($pending_discrepancies)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card discrepancy-alert">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Pending Discrepancies
                                </h5>
                                <p class="card-text">The following deliveries have discrepancies that need attention:</p>
                                <div class="row">
                                    <?php foreach ($pending_discrepancies as $discrepancy): ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="discrepancy-item p-2 rounded text-dark">
                                            <strong><?= htmlspecialchars($discrepancy['product_name']) ?></strong>
                                            <span class="float-end">
                                                <?= $discrepancy['variance'] > 0 ? '+' : '' ?><?= number_format($discrepancy['variance'], 2) ?>
                                            </span>
                                            <br>
                                            <small>Delivery: <?= htmlspecialchars($discrepancy['delivery_number']) ?></small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Deliveries List -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-list me-2"></i>Recent Deliveries
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($deliveries)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-truck fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No deliveries recorded yet. Record your first delivery to get started.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Delivery #</th>
                                                    <th>PO Number</th>
                                                    <th>Supplier</th>
                                                    <th>Delivery Date</th>
                                                    <th>Items</th>
                                                    <th>Total Amount</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($deliveries as $delivery): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($delivery['delivery_number']) ?></strong>
                                                    </td>
                                                    <td><?= htmlspecialchars($delivery['po_number']) ?></td>
                                                    <td><?= htmlspecialchars($delivery['supplier_name']) ?></td>
                                                    <td><?= date('M j, Y', strtotime($delivery['delivery_date'])) ?></td>
                                                    <td><?= number_format($delivery['item_count']) ?> items</td>
                                                    <td>?<?= number_format($delivery['total_amount'], 2) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= 
                                                            $delivery['status'] == 'confirmed' ? 'success' : 
                                                            ($delivery['status'] == 'discrepancy' ? 'danger' : 'warning') 
                                                        ?>">
                                                            <?= ucfirst($delivery['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <button type="button" class="btn btn-outline-primary" onclick="viewDelivery(<?= $delivery['id'] ?>)">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <?php if ($delivery['status'] == 'pending'): ?>
                                                            <button type="button" class="btn btn-outline-success" onclick="confirmDelivery(<?= $delivery['id'] ?>)">
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

    <!-- Create Delivery Modal -->
    <div class="modal fade" id="createDeliveryModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Record New Delivery
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Purchase Order</label>
                                <select name="po_id" class="form-select" id="poSelect" onchange="loadPOItems()" required>
                                    <option value="">Select PO</option>
                                    <?php foreach ($approved_pos as $po): ?>
                                    <option value="<?= $po['id'] ?>" data-supplier="<?= $po['supplier_id'] ?>">
                                        <?= htmlspecialchars($po['po_number']) ?> - <?= htmlspecialchars($po['supplier_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Supplier</label>
                                <input type="text" class="form-control" id="supplierDisplay" readonly>
                                <input type="hidden" name="supplier_id" id="supplierId">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Delivery Date</label>
                                <input type="date" name="delivery_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Delivery Items</label>
                            <div id="itemsContainer">
                                <!-- Items will be loaded here based on selected PO -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_delivery" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Record Delivery
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        function loadPOItems() {
            const poSelect = document.getElementById('poSelect');
            const selectedOption = poSelect.options[poSelect.selectedIndex];
            const supplierDisplay = document.getElementById('supplierDisplay');
            const supplierId = document.getElementById('supplierId');
            const itemsContainer = document.getElementById('itemsContainer');
            
            // Set supplier info
            supplierId.value = selectedOption.dataset.supplier || '';
            
            // Load PO items via AJAX
            if (poSelect.value) {
                fetch(`../api/get_po_items.php?po_id=${poSelect.value}`)
                    .then(response => response.json())
                    .then(data => {
                        itemsContainer.innerHTML = '';
                        data.items.forEach((item, index) => {
                            const itemRow = document.createElement('div');
                            itemRow.className = 'item-row';
                            itemRow.innerHTML = `
                                <div class="row">
                                    <div class="col-md-4">
                                        <label class="form-label">${item.product_name}</label>
                                        <small class="text-muted d-block">Ordered: ${item.quantity_ordered} @ ?${item.unit_price}</small>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Quantity Received</label>
                                        <input type="number" name="items[${index}][quantity_received]" class="form-control" 
                                               value="${item.quantity_ordered}" step="0.01" required
                                               onchange="calculateVariance(${index}, ${item.quantity_ordered})">
                                        <input type="hidden" name="items[${index}][po_item_id]" value="${item.id}">
                                        <input type="hidden" name="items[${index}][product_id]" value="${item.product_id}">
                                        <input type="hidden" name="items[${index}][quantity_ordered]" value="${item.quantity_ordered}">
                                        <input type="hidden" name="items[${index}][unit_price]" value="${item.unit_price}">
                                        <input type="hidden" name="items[${index}][product_name]" value="${item.product_name}">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Variance</label>
                                        <div id="variance_${index}" class="form-control-plaintext">0.00</div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Quality</label>
                                        <select name="items[${index}][quality_status]" class="form-select">
                                            <option value="good">Good</option>
                                            <option value="acceptable">Acceptable</option>
                                            <option value="poor">Poor</option>
                                            <option value="damaged">Damaged</option>
                                        </select>
                                    </div>
                                </div>
                            `;
                            itemsContainer.appendChild(itemRow);
                        });
                    })
                    .catch(error => console.error('Error loading PO items:', error));
            } else {
                itemsContainer.innerHTML = '<p class="text-muted">Please select a Purchase Order first.</p>';
            }
        }

        function calculateVariance(index, orderedQty) {
            const receivedQty = parseFloat(document.querySelector(`input[name="items[${index}][quantity_received]"]`).value) || 0;
            const variance = receivedQty - orderedQty;
            const varianceElement = document.getElementById(`variance_${index}`);
            
            varianceElement.textContent = (variance >= 0 ? '+' : '') + variance.toFixed(2);
            varianceElement.className = variance >= 0 ? 'variance-positive' : 'variance-negative';
        }

        function viewDelivery(deliveryId) {
            window.location.href = `delivery_details.php?id=${deliveryId}`;
        }

        function confirmDelivery(deliveryId) {
            if (confirm('Are you sure you want to confirm this delivery? This will finalize the inventory updates.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="delivery_id" value="${deliveryId}">
                    <input type="hidden" name="confirm_delivery" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
