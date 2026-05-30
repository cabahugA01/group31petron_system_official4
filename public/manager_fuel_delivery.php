<?php
/**
 * MANAGER FUEL DELIVERY INTERFACE
 * 
 * Streamlined fuel delivery recording for managers with:
 * - Immediate inventory updates
 * - Complete audit trail
 * - No approval workflow needed
 * - Direct stock addition upon recording
 */

session_start();
require_once 'db_connect.php';
require_once '../backend/lib.php';
require_once '../backend/fuel_audit_logging.php';

require_login();
$me = current_user();

if (!$me) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Check if user is manager or superadmin using canonical role_key()
$_mfd_role = role_key($me['role'] ?? '');
$isManager = in_array($_mfd_role, ['manager', 'admin', 'superadmin']);
if (!$isManager) {
    header("Location: dashboard.php");
    exit;
}

// Get user's station
$station_id = user_station_id();

// Handle form submissions
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'record_delivery') {
        $delivery_date = $_POST['delivery_date'] ?? '';
        $fuel_type = $_POST['fuel_type'] ?? '';
        $supplier_name = $_POST['supplier_name'] ?? '';
        $invoice_no = $_POST['invoice_no'] ?? '';
        $delivery_liters = (float)($_POST['delivery_liters'] ?? 0);
        $tanker_number = $_POST['tanker_number'] ?? '';
        $delivery_notes = $_POST['delivery_notes'] ?? '';
        
        if ($delivery_date && $fuel_type && $supplier_name && $delivery_liters > 0) {
            try {
                // Find the fuel product by name/SKU
                $stmt = $pdo->prepare("
                    SELECT p.id, p.name, p.sku 
                    FROM products p 
                    WHERE p.type_id = 1 
                    AND (p.name = ? OR p.sku = ? OR p.name LIKE ? OR p.sku LIKE ?)
                    LIMIT 1
                ");
                $stmt->execute([$fuel_type, $fuel_type, "%$fuel_type%", "%$fuel_type%"]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    throw new Exception("Fuel product not found for type: $fuel_type");
                }
                
                // Get current stock before update
                $stmt = $pdo->prepare("
                    SELECT stock_level FROM station_inventory 
                    WHERE station_id = ? AND product_id = ?
                ");
                $stmt->execute([$station_id, $product['id']]);
                $current_inv = $stmt->fetch(PDO::FETCH_ASSOC);
                $quantity_before = $current_inv['stock_level'] ?? 0;
                
                // BEGIN TRANSACTION for atomic operations
                $pdo->beginTransaction();
                
                try {
                    // Record delivery in fuel_deliveries table
                    $stmt = $pdo->prepare("
                        INSERT INTO fuel_deliveries (
                            station_id, delivery_date, fuel_type, supplier, 
                            invoice_no, delivery_liters, tanker_number, 
                            received_by, notes, status, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Manager_Direct', NOW())
                    ");
                    
                    $stmt->execute([
                        $station_id,
                        $delivery_date,
                        $fuel_type,
                        $supplier_name,
                        $invoice_no,
                        $delivery_liters,
                        $tanker_number,
                        $me['id'],
                        $delivery_notes
                    ]);
                    
                    $delivery_id = $pdo->lastInsertId();
                    
                    // Update station_inventory - ADD delivery liters to stock
                    $stmt = $pdo->prepare("
                        UPDATE station_inventory 
                        SET stock_level = stock_level + ?, last_updated = NOW()
                        WHERE station_id = ? AND product_id = ?
                    ");
                    
                    $affected = $stmt->execute([
                        $delivery_liters,
                        $station_id,
                        $product['id']
                    ]);
                    
                    if ($stmt->rowCount() == 0) {
                        // No existing inventory record, create one
                        $stmt = $pdo->prepare("
                            INSERT INTO station_inventory (station_id, product_id, stock_level, unit, status, last_updated)
                            VALUES (?, ?, ?, 'liters', 'active', NOW())
                        ");
                        $stmt->execute([$station_id, $product['id'], $delivery_liters]);
                    }
                    
                    // Get new stock level
                    $stmt = $pdo->prepare("
                        SELECT stock_level FROM station_inventory 
                        WHERE station_id = ? AND product_id = ?
                    ");
                    $stmt->execute([$station_id, $product['id']]);
                    $updated_inv = $stmt->fetch(PDO::FETCH_ASSOC);
                    $quantity_after = $updated_inv['stock_level'] ?? 0;
                    
                    // Log to activity logs
                    log_activity(
                        $pdo,
                        $me['id'],
                        'Manager Direct Delivery',
                        "Recorded fuel delivery: {$delivery_liters}L of {$fuel_type}. Stock: {$quantity_before}L → {$quantity_after}L (Invoice: {$invoice_no})",
                        'fuel_management'
                    );
                    
                    // Log to fuel_inventory_logs via audit logging module
                    log_fuel_inventory_action(
                        $pdo,
                        $me['id'],
                        'manager_direct_delivery',
                        'fuel_delivery',
                        $delivery_id,
                        $station_id,
                        $product['id'],
                        [
                            'fuel_type' => $fuel_type,
                            'delivery_liters' => $delivery_liters,
                            'supplier_name' => $supplier_name,
                            'invoice_no' => $invoice_no,
                            'tanker_number' => $tanker_number,
                            'quantity_before' => $quantity_before,
                            'quantity_after' => $quantity_after,
                            'quantity_change' => $delivery_liters,
                            'delivery_notes' => $delivery_notes,
                            'status' => 'Manager_Direct'
                        ]
                    );
                    
                    $pdo->commit();
                    
                    $msg = "✅ <strong>Fuel delivery recorded successfully!</strong><br>
                            📦 Delivery ID: {$delivery_id}<br>
                            ⛽ {$fuel_type}: +{$delivery_liters}L<br>
                            📊 Stock Updated: {$quantity_before}L → <strong>{$quantity_after}L</strong><br>
                            📄 Invoice: {$invoice_no}";
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                
            } catch (Exception $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Error: Please fill all required fields.";
        }
    }
}

// Get available fuel types from products
$stmt = $pdo->prepare("
    SELECT DISTINCT p.name, p.sku
    FROM products p
    WHERE p.type_id = 1
    ORDER BY p.name
");
$stmt->execute();
$fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent deliveries for this station
$stmt = $pdo->prepare("
    SELECT 
        fd.*,
        p.name as product_name,
        u.name as recorded_by_name
    FROM fuel_deliveries fd
    LEFT JOIN products p ON p.name = fd.fuel_type OR p.sku = fd.fuel_type
    LEFT JOIN users u ON fd.received_by = u.id
    WHERE fd.station_id = ? AND fd.status = 'Manager_Direct'
    ORDER BY fd.created_at DESC
    LIMIT 10
");
$stmt->execute([$station_id]);
$recent_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current fuel inventory
$stmt = $pdo->prepare("
    SELECT 
        si.*, 
        p.name as product_name,
        p.sku as product_sku,
        CASE 
            WHEN si.stock_level <= si.reorder_level THEN 'low'
            WHEN si.stock_level <= (si.reorder_level * 1.5) THEN 'medium'
            ELSE 'good'
        END as stock_status
    FROM station_inventory si
    JOIN products p ON si.product_id = p.id
    WHERE si.station_id = ? AND p.type_id = 1 AND si.status = 'active'
    ORDER BY p.name
");
$stmt->execute([$station_id]);
$current_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Fuel Delivery - Petron POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .stock-good { color: #28a745; }
        .stock-medium { color: #ffc107; }
        .stock-low { color: #dc3545; font-weight: bold; }
        .delivery-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .inventory-card {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        .recent-card {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="bi bi-fuel-pump-fill"></i> Manager Fuel Delivery
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="inventory.php">
                    <i class="bi bi-box-seam"></i> Inventory
                </a>
                <a class="nav-link" href="dashboard.php">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Message Alert -->
        <?php if ($msg): ?>
        <div class="alert alert-<?= strpos($msg, '❌') !== false ? 'danger' : 'success' ?> alert-dismissible fade show">
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Record New Delivery -->
            <div class="col-lg-6">
                <div class="card delivery-card shadow-lg">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-plus-circle"></i> Record Fuel Delivery
                        </h5>
                        <small>Immediate inventory update upon recording</small>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="record_delivery">
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-calendar3"></i> Delivery Date
                                    </label>
                                    <input type="date" name="delivery_date" class="form-control" 
                                           value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-fuel-pump"></i> Fuel Type
                                    </label>
                                    <select name="fuel_type" class="form-select" required>
                                        <option value="">Select Fuel Type</option>
                                        <?php foreach ($fuel_types as $fuel): ?>
                                        <option value="<?= htmlspecialchars($fuel['name']) ?>">
                                            <?= htmlspecialchars($fuel['name']) ?>
                                            <?php if ($fuel['sku']): ?>
                                                (<?= htmlspecialchars($fuel['sku']) ?>)
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-building"></i> Supplier Name
                                    </label>
                                    <input type="text" name="supplier_name" class="form-control" 
                                           placeholder="e.g., Petron Corporation" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-receipt"></i> Invoice Number
                                    </label>
                                    <input type="text" name="invoice_no" class="form-control" 
                                           placeholder="e.g., INV-2024-001" required>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-droplet"></i> Delivery Liters
                                    </label>
                                    <input type="number" name="delivery_liters" class="form-control" 
                                           step="0.01" min="0.01" placeholder="e.g., 10000.00" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">
                                        <i class="bi bi-truck"></i> Tanker Number
                                    </label>
                                    <input type="text" name="tanker_number" class="form-control" 
                                           placeholder="e.g., TK-001">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-sticky"></i> Delivery Notes
                                </label>
                                <textarea name="delivery_notes" class="form-control" rows="2" 
                                          placeholder="Optional delivery notes..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-light btn-lg w-100">
                                <i class="bi bi-check-circle"></i> Record Delivery & Update Stock
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Current Fuel Inventory -->
            <div class="col-lg-6">
                <div class="card inventory-card shadow-lg">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-bar-chart-fill"></i> Current Fuel Inventory
                        </h5>
                        <small>Real-time stock levels</small>
                    </div>
                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($current_inventory)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-inbox display-4 opacity-50"></i>
                            <p class="mt-2 mb-0">No fuel inventory found</p>
                            <small>Start by recording your first delivery</small>
                        </div>
                        <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($current_inventory as $item): ?>
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center p-2 border-bottom border-light border-opacity-25">
                                    <div>
                                        <strong><?= htmlspecialchars($item['product_name']) ?></strong>
                                        <?php if ($item['product_sku']): ?>
                                            <br><small class="opacity-75"><?= htmlspecialchars($item['product_sku']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <span class="stock-<?= $item['stock_status'] ?>">
                                            <strong><?= number_format($item['stock_level'], 2) ?>L</strong>
                                        </span>
                                        <?php if ($item['reorder_level'] > 0): ?>
                                            <br><small class="opacity-75">Min: <?= number_format($item['reorder_level'], 0) ?>L</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Deliveries -->
        <?php if (!empty($recent_deliveries)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card recent-card shadow-lg">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history"></i> Recent Deliveries
                        </h5>
                        <small>Last 10 deliveries recorded via manager interface</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm text-light">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Fuel Type</th>
                                        <th>Liters</th>
                                        <th>Supplier</th>
                                        <th>Invoice</th>
                                        <th>Recorded By</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_deliveries as $delivery): ?>
                                    <tr>
                                        <td><?= date('M j', strtotime($delivery['delivery_date'])) ?></td>
                                        <td><?= htmlspecialchars($delivery['fuel_type']) ?></td>
                                        <td><strong><?= number_format($delivery['delivery_liters'], 0) ?>L</strong></td>
                                        <td><?= htmlspecialchars($delivery['supplier']) ?></td>
                                        <td><small><?= htmlspecialchars($delivery['invoice_no']) ?></small></td>
                                        <td><?= htmlspecialchars($delivery['recorded_by_name']) ?></td>
                                        <td><small><?= date('M j, g:i A', strtotime($delivery['created_at'])) ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php include __DIR__ . '/../partials/footer.php'; ?>