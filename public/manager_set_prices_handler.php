<?php
/**
 * Manager Set Prices Handler
 * Handles: Add fuel product, Edit fuel price, Deactivate fuel product
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

header('Content-Type: application/json');

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Access control
if ($role !== 'manager') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if ((int)$station_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'No station assigned']);
    exit;
}

// Handle GET requests for data retrieval
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_fuel_details') {
        $fuel_id = (int)($_GET['id'] ?? 0);
        if ($fuel_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT f.*, u.username as updated_by_name FROM fuel_inventory f LEFT JOIN users u ON f.updated_by=u.id WHERE f.id=? AND f.station_id=? LIMIT 1");
            $stmt->execute([$fuel_id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fuel) { echo json_encode(['success'=>false,'message'=>'Fuel not found']); exit; }
            if ($fuel['last_updated']) $fuel['last_updated'] = date('M d, Y g:i A', strtotime($fuel['last_updated']));
            $history = [];
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'fuel_price_history'")->fetch();
            if ($tableCheck) {
                $stmt = $pdo->prepare("SELECT h.*, u.username as updated_by_name FROM fuel_price_history h LEFT JOIN users u ON h.updated_by=u.id WHERE h.fuel_id=? ORDER BY h.created_at DESC LIMIT 10");
                $stmt->execute([$fuel_id]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($history as &$h) { if ($h['created_at']) $h['created_at'] = date('M d, Y g:i A', strtotime($h['created_at'])); }
            }
            echo json_encode(['success'=>true,'fuel'=>$fuel,'history'=>$history]); exit;
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }

    if ($action === 'get_merch_details') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT id,product_name,sku,category,size,unit_cost,unit_price,supplier FROM inventory_products WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
            echo json_encode(['success'=>true,'item'=>$row]); exit;
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }

    if ($action === 'get_service_details') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT id,service_name,service_key,service_price,active,status FROM job_order_service_types WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
            echo json_encode(['success'=>true,'service'=>$row]); exit;
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // ══════════════════════════════════════════════════════════════════════
        // ADD FUEL PRODUCT
        // ══════════════════════════════════════════════════════════════════════
        case 'add_fuel_product':
            $fuel_type      = trim($_POST['fuel_type'] ?? '');
            $price          = (float)($_POST['price'] ?? 0);
            $capacity       = (float)($_POST['capacity'] ?? 0);
            $critical_level = (float)($_POST['critical_level'] ?? 0);
            
            if (empty($fuel_type)) {
                echo json_encode(['success' => false, 'message' => 'Fuel type is required']);
                exit;
            }
            
            if ($price < 0 || $capacity < 0 || $critical_level < 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid values provided']);
                exit;
            }
            
            // Check if fuel type already exists for this station
            $stmt = $pdo->prepare("
                SELECT id FROM fuel_inventory 
                WHERE station_id = ? AND LOWER(fuel_type) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$station_id, $fuel_type]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'This fuel type already exists for your station']);
                exit;
            }
            
            // Insert new fuel product
            $stmt = $pdo->prepare("
                INSERT INTO fuel_inventory 
                (station_id, fuel_type, price_per_liter, capacity, critical_level, 
                 current_level, status, updated_by, last_updated)
                VALUES (?, ?, ?, ?, ?, 0, 'active', ?, NOW())
            ");
            $stmt->execute([
                $station_id,
                $fuel_type,
                $price,
                $capacity,
                $critical_level,
                $me['id']
            ]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Add Fuel Product',
                "Manager added new fuel product: {$fuel_type} at station {$station_id}");
            
            echo json_encode(['success' => true, 'message' => 'Fuel product added successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // EDIT FUEL PRICE
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_fuel_price':
            $id             = (int)($_POST['id'] ?? 0);
            $new_price      = (float)($_POST['price'] ?? 0);
            $reason         = trim($_POST['reason'] ?? '');
            $effective_date = trim($_POST['effective_date'] ?? date('Y-m-d'));
            
            if ($id <= 0 || $new_price < 0 || empty($reason)) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }
            
            // Verify fuel belongs to manager's station
            $stmt = $pdo->prepare("
                SELECT fuel_type, price_per_liter 
                FROM fuel_inventory 
                WHERE id = ? AND station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found']);
                exit;
            }
            
            $old_price = (float)$fuel['price_per_liter'];
            
            // Create price history table if not exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS fuel_price_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    fuel_id INT NOT NULL,
                    old_price DECIMAL(10,2) NOT NULL,
                    new_price DECIMAL(10,2) NOT NULL,
                    reason VARCHAR(500),
                    effective_date DATE,
                    updated_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_fuel_id (fuel_id),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Insert price history record
            $stmt = $pdo->prepare("
                INSERT INTO fuel_price_history 
                (fuel_id, old_price, new_price, reason, effective_date, updated_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$id, $old_price, $new_price, $reason, $effective_date, $me['id']]);
            
            // Update price
            $stmt = $pdo->prepare("
                UPDATE fuel_inventory 
                SET price_per_liter = ?,
                    updated_by = ?,
                    last_updated = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$new_price, $me['id'], $id, $station_id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Edit Fuel Price',
                "Manager updated {$fuel['fuel_type']} price from ₱{$old_price} to ₱{$new_price}. Reason: {$reason}");
            
            echo json_encode(['success' => true, 'message' => 'Price updated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ROLLBACK PRICE
        // ══════════════════════════════════════════════════════════════════════
        case 'rollback_price':
            $fuel_id    = (int)($_POST['fuel_id'] ?? 0);
            $history_id = (int)($_POST['history_id'] ?? 0);
            $reason     = trim($_POST['reason'] ?? '');
            
            if ($fuel_id <= 0 || $history_id <= 0 || empty($reason)) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }
            
            // Get the history record
            $stmt = $pdo->prepare("
                SELECT h.*, f.fuel_type, f.price_per_liter as current_price
                FROM fuel_price_history h
                JOIN fuel_inventory f ON h.fuel_id = f.id
                WHERE h.id = ? AND h.fuel_id = ? AND f.station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$history_id, $fuel_id, $station_id]);
            $history = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$history) {
                echo json_encode(['success' => false, 'message' => 'History record not found']);
                exit;
            }
            
            $rollback_price = (float)$history['old_price'];
            $current_price  = (float)$history['current_price'];
            
            // Insert new history record for the rollback
            $stmt = $pdo->prepare("
                INSERT INTO fuel_price_history 
                (fuel_id, old_price, new_price, reason, effective_date, updated_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $fuel_id,
                $current_price,
                $rollback_price,
                "ROLLBACK: " . $reason,
                date('Y-m-d'),
                $me['id']
            ]);
            
            // Update fuel price
            $stmt = $pdo->prepare("
                UPDATE fuel_inventory 
                SET price_per_liter = ?,
                    updated_by = ?,
                    last_updated = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$rollback_price, $me['id'], $fuel_id, $station_id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Rollback Fuel Price',
                "Manager rolled back {$history['fuel_type']} price from ₱{$current_price} to ₱{$rollback_price}. Reason: {$reason}");
            
            echo json_encode(['success' => true, 'message' => 'Price rolled back successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // DEACTIVATE FUEL
        // ══════════════════════════════════════════════════════════════════════
        case 'deactivate_fuel':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Verify fuel belongs to manager's station
            $stmt = $pdo->prepare("
                SELECT fuel_type 
                FROM fuel_inventory 
                WHERE id = ? AND station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found']);
                exit;
            }
            
            // Update status to inactive
            $stmt = $pdo->prepare("
                UPDATE fuel_inventory 
                SET status = 'inactive',
                    updated_by = ?,
                    last_updated = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$me['id'], $id, $station_id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Deactivate Fuel Product',
                "Manager deactivated fuel product: {$fuel['fuel_type']}");
            
            echo json_encode(['success' => true, 'message' => 'Fuel product deactivated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        case 'add_merchandise':
            $product_name = trim($_POST['product_name'] ?? '');
            $category     = trim($_POST['category'] ?? '');
            $unit_cost    = (float)($_POST['unit_cost'] ?? 0);
            $unit_price   = (float)($_POST['unit_price'] ?? 0);
            $sku          = trim($_POST['sku'] ?? '');
            $size         = trim($_POST['size'] ?? '');
            
            if (empty($product_name) || empty($category)) {
                echo json_encode(['success' => false, 'message' => 'Product name and category are required']);
                exit;
            }
            
            if ($unit_cost < 0 || $unit_price < 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid values provided']);
                exit;
            }
            
            // Insert new merchandise
            $stmt = $pdo->prepare("
                INSERT INTO inventory_products 
                (product_name, category, unit_cost, unit_price, sku, size, stock_quantity, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, 'active', NOW())
            ");
            $stmt->execute([$product_name, $category, $unit_cost, $unit_price, $sku, $size]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Add Merchandise',
                "Manager added new merchandise: {$product_name}");
            
            echo json_encode(['success' => true, 'message' => 'Merchandise added successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // EDIT FUEL — FULL (name, price, capacity, critical level, status)
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_fuel_full':
            $id             = (int)($_POST['id'] ?? 0);
            $fuel_type      = trim($_POST['fuel_type'] ?? '');
            $new_price      = (float)($_POST['price'] ?? 0);
            $capacity       = (float)($_POST['capacity'] ?? 0);
            $critical_level = (float)($_POST['critical_level'] ?? 0);
            $status         = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

            if ($id <= 0 || empty($fuel_type) || $new_price < 0 || $capacity < 0 || $critical_level < 0) {
                echo json_encode(['success'=>false,'message'=>'Invalid parameters']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT fuel_type, price_per_liter FROM fuel_inventory WHERE id=? AND station_id=? LIMIT 1");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fuel) { echo json_encode(['success'=>false,'message'=>'Fuel not found']); exit; }

            $old_price = (float)$fuel['price_per_liter'];

            if ($old_price != $new_price) {
                // Update non-pricing fields immediately (exclude status — fuel_inventory uses 'Low Stock'/'Normal')
                $stmt = $pdo->prepare("UPDATE fuel_inventory SET fuel_type=?, capacity=?, critical_level=?, updated_by=?, last_updated=NOW() WHERE id=? AND station_id=?");
                $stmt->execute([$fuel_type, $capacity, $critical_level, $me['id'], $id, $station_id]);

                // Create pending price approval
                $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type='fuel_inventory' AND product_id=? AND status='pending'")
                    ->execute([$station_id, $id]);

                $stmt = $pdo->prepare("
                    INSERT INTO pending_price_approvals 
                    (station_id, product_type, product_id, old_cost, new_cost, old_price, new_price, manager_id, status, created_at)
                    VALUES (?, 'fuel_inventory', ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $station_id,
                    $id,
                    $old_price,
                    $new_price,
                    $old_price,
                    $new_price,
                    $me['id']
                ]);

                log_activity($pdo, $me['id'], 'Edit Fuel Product', "Manager requested price change for {$fuel_type} (₱{$old_price} -> ₱{$new_price})");
                echo json_encode(['success'=>true,'message'=>'Fuel details updated. Price change submitted for Admin approval.']);
            } else {
                // No price change — update fuel_type, capacity, critical_level directly
                $stmt = $pdo->prepare("UPDATE fuel_inventory SET fuel_type=?, capacity=?, critical_level=?, updated_by=?, last_updated=NOW() WHERE id=? AND station_id=?");
                $stmt->execute([$fuel_type, $capacity, $critical_level, $me['id'], $id, $station_id]);

                log_activity($pdo, $me['id'], 'Edit Fuel Product', "Manager updated fuel: {$fuel_type}");
                echo json_encode(['success'=>true,'message'=>'Fuel product updated successfully']);
            }
            break;

        // ══════════════════════════════════════════════════════════════════════
        // EDIT MERCHANDISE — FULL (name, sku, category, size, cost, price)
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_merchandise_full':
            $id           = (int)($_POST['id'] ?? 0);
            $product_name = trim($_POST['product_name'] ?? '');
            $sku          = trim($_POST['sku'] ?? '');
            $category     = trim($_POST['category'] ?? '');
            $size         = trim($_POST['size'] ?? '');
            $unit_cost    = (float)($_POST['unit_cost'] ?? 0);
            $unit_price   = (float)($_POST['unit_price'] ?? 0);

            if ($id <= 0 || empty($product_name) || empty($category)) {
                echo json_encode(['success'=>false,'message'=>'Name and category are required']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT product_name, sku, category, size, unit_cost, unit_price FROM inventory_products WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$old) { echo json_encode(['success'=>false,'message'=>'Merchandise not found']); exit; }

            $old_cost = (float)$old['unit_cost'];
            $old_price = (float)$old['unit_price'];

            if ($old_cost != $unit_cost || $old_price != $unit_price) {
                // Update non-pricing fields immediately
                $stmt = $pdo->prepare("UPDATE inventory_products SET product_name=?, sku=?, category=?, size=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$product_name, $sku, $category, $size, $id]);

                // Create pending price approval
                $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type='merchandise' AND product_id=? AND status='pending'")
                    ->execute([$station_id, $id]);

                $stmt = $pdo->prepare("
                    INSERT INTO pending_price_approvals 
                    (station_id, product_type, product_id, old_cost, new_cost, old_price, new_price, manager_id, status, created_at)
                    VALUES (?, 'merchandise', ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $station_id,
                    $id,
                    $old_cost,
                    $unit_cost,
                    $old_price,
                    $unit_price,
                    $me['id']
                ]);

                log_activity($pdo, $me['id'], 'Edit Merchandise', "Manager requested price change for {$product_name}");
                echo json_encode(['success'=>true,'message'=>'Product details updated. Price/cost change submitted for Admin approval.']);
            } else {
                // No cost or price change, update everything immediately
                $stmt = $pdo->prepare("UPDATE inventory_products SET product_name=?, sku=?, category=?, size=?, unit_cost=?, unit_price=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$product_name, $sku, $category, $size, $unit_cost, $unit_price, $id]);

                log_activity($pdo, $me['id'], 'Edit Merchandise', "Manager updated merchandise: {$product_name}");
                echo json_encode(['success'=>true,'message'=>'Merchandise updated successfully']);
            }
            break;

        // (Legacy - price only, kept for backward compat)
        case 'edit_merchandise_price':
            $id        = (int)($_POST['id'] ?? 0);
            $new_price = (float)($_POST['price'] ?? 0);
            if ($id <= 0 || $new_price < 0) { echo json_encode(['success'=>false,'message'=>'Invalid parameters']); exit; }
            $stmt = $pdo->prepare("SELECT product_name, unit_price FROM inventory_products WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $merch = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$merch) { echo json_encode(['success'=>false,'message'=>'Merchandise not found']); exit; }
            $pdo->prepare("UPDATE inventory_products SET unit_price=? WHERE id=?")->execute([$new_price, $id]);
            log_activity($pdo, $me['id'], 'Edit Merchandise Price', "Price updated for {$merch['product_name']}");
            echo json_encode(['success'=>true,'message'=>'Price updated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // DEACTIVATE MERCHANDISE
        // ══════════════════════════════════════════════════════════════════════
        case 'deactivate_merchandise':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Get merchandise name
            $stmt = $pdo->prepare("
                SELECT product_name 
                FROM inventory_products 
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $merch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$merch) {
                echo json_encode(['success' => false, 'message' => 'Merchandise not found']);
                exit;
            }
            
            // Update status to inactive
            $stmt = $pdo->prepare("
                UPDATE inventory_products 
                SET status = 'inactive'
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Deactivate Merchandise',
                "Manager deactivated merchandise: {$merch['product_name']}");
            
            echo json_encode(['success' => true, 'message' => 'Merchandise deactivated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ADD SERVICE
        // ══════════════════════════════════════════════════════════════════════
        case 'add_service':
            $service_name  = trim($_POST['service_name'] ?? '');
            $service_key   = trim($_POST['service_key'] ?? '');
            $service_price = (float)($_POST['service_price'] ?? 0);
            
            if (empty($service_name) || empty($service_key)) {
                echo json_encode(['success' => false, 'message' => 'Service name and key are required']);
                exit;
            }
            
            if ($service_price < 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid price']);
                exit;
            }
            
            // Check if service key already exists
            $stmt = $pdo->prepare("
                SELECT id FROM job_order_service_types 
                WHERE service_key = ?
                LIMIT 1
            ");
            $stmt->execute([$service_key]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Service key already exists']);
                exit;
            }
            
            // Insert new service
            $stmt = $pdo->prepare("
                INSERT INTO job_order_service_types 
                (service_name, service_key, service_price, status, active)
                VALUES (?, ?, ?, 'active', 1)
            ");
            $stmt->execute([$service_name, $service_key, $service_price]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Add Service Type',
                "Manager added new service type: {$service_name}");
            
            echo json_encode(['success' => true, 'message' => 'Service type added successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // EDIT SERVICE — FULL (name, key, price, status)
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_service_full':
            $id            = (int)($_POST['id'] ?? 0);
            $service_name  = trim($_POST['service_name'] ?? '');
            $service_key   = trim($_POST['service_key'] ?? '');
            $service_price = (float)($_POST['service_price'] ?? 0);
            $active        = (int)($_POST['active'] ?? 1);
            // NOTE: job_order_service_types.status is enum('approved','pending','rejected') — don't set it to active/inactive

            if ($id <= 0 || empty($service_name) || empty($service_key) || $service_price < 0) {
                echo json_encode(['success'=>false,'message'=>'Name, key and price are required']);
                exit;
            }

            // Check key uniqueness (excluding self)
            $stmt = $pdo->prepare("SELECT id, service_price FROM job_order_service_types WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $svc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$svc) { echo json_encode(['success'=>false,'message'=>'Service type not found']); exit; }

            $stmt = $pdo->prepare("SELECT id FROM job_order_service_types WHERE service_key=? AND id!=? LIMIT 1");
            $stmt->execute([$service_key, $id]);
            if ($stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'Service key already in use by another service']); exit; }

            $old_price = (float)$svc['service_price'];

            if ($old_price != $service_price) {
                // Update non-pricing fields immediately
                $stmt = $pdo->prepare("UPDATE job_order_service_types SET service_name=?, service_key=?, active=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$service_name, $service_key, $active, $id]);

                // Create pending price approval
                $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type='service_type' AND product_id=? AND status='pending'")
                    ->execute([$station_id, $id]);

                $stmt = $pdo->prepare("
                    INSERT INTO pending_price_approvals 
                    (station_id, product_type, product_id, old_cost, new_cost, old_price, new_price, manager_id, status, created_at)
                    VALUES (?, 'service_type', ?, 0, 0, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $station_id,
                    $id,
                    $old_price,
                    $service_price,
                    $me['id']
                ]);

                log_activity($pdo, $me['id'], 'Edit Service Type', "Manager requested price change for service: {$service_name}");
                echo json_encode(['success'=>true,'message'=>'Service details updated. Price change submitted for Admin approval.']);
            } else {
                // No price change, update everything immediately
                $stmt = $pdo->prepare("UPDATE job_order_service_types SET service_name=?, service_key=?, service_price=?, active=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$service_name, $service_key, $service_price, $active, $id]);

                log_activity($pdo, $me['id'], 'Edit Service Type', "Manager updated service: {$service_name}");
                echo json_encode(['success'=>true,'message'=>'Service type updated successfully']);
            }
            break;

        // (Legacy - price only, kept for backward compat)
        case 'edit_service_price':
            $id        = (int)($_POST['id'] ?? 0);
            $new_price = (float)($_POST['price'] ?? 0);
            
            if ($id <= 0 || $new_price < 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }
            
            // Get current service info
            $stmt = $pdo->prepare("
                SELECT service_name, service_price 
                FROM job_order_service_types 
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$service) {
                echo json_encode(['success' => false, 'message' => 'Service not found']);
                exit;
            }
            
            $old_price = (float)$service['service_price'];
            
            // Update price
            $stmt = $pdo->prepare("
                UPDATE job_order_service_types 
                SET service_price = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_price, $id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Edit Service Price',
                "Manager updated {$service['service_name']} price from ₱{$old_price} to ₱{$new_price}");
            
            echo json_encode(['success' => true, 'message' => 'Service price updated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // DEACTIVATE SERVICE
        // ══════════════════════════════════════════════════════════════════════
        case 'deactivate_service':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Get service name
            $stmt = $pdo->prepare("
                SELECT service_name 
                FROM job_order_service_types 
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$service) {
                echo json_encode(['success' => false, 'message' => 'Service not found']);
                exit;
            }
            
            // Update status to inactive
            $stmt = $pdo->prepare("
                UPDATE job_order_service_types 
                SET active = 0, status = 'inactive'
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Deactivate Service',
                "Manager deactivated service type: {$service['service_name']}");
            
            echo json_encode(['success' => true, 'message' => 'Service deactivated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ACTIVATE FUEL
        // ══════════════════════════════════════════════════════════════════════
        case 'activate_fuel':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Verify fuel belongs to manager's station
            $stmt = $pdo->prepare("
                SELECT fuel_type 
                FROM fuel_inventory 
                WHERE id = ? AND station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found']);
                exit;
            }
            
            // Update status to active
            $stmt = $pdo->prepare("
                UPDATE fuel_inventory 
                SET status = 'active',
                    updated_by = ?,
                    last_updated = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$me['id'], $id, $station_id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Activate Fuel Product',
                "Manager activated fuel product: {$fuel['fuel_type']}");
            
            echo json_encode(['success' => true, 'message' => 'Fuel product activated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ACTIVATE MERCHANDISE
        // ══════════════════════════════════════════════════════════════════════
        case 'activate_merchandise':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Get merchandise name
            $stmt = $pdo->prepare("
                SELECT product_name 
                FROM inventory_products 
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $merch = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$merch) {
                echo json_encode(['success' => false, 'message' => 'Merchandise not found']);
                exit;
            }
            
            // Update status to active
            $stmt = $pdo->prepare("
                UPDATE inventory_products 
                SET status = 'active'
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Activate Merchandise',
                "Manager activated merchandise: {$merch['product_name']}");
            
            echo json_encode(['success' => true, 'message' => 'Merchandise activated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ACTIVATE SERVICE
        // ══════════════════════════════════════════════════════════════════════
        case 'activate_service':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Get service name
            $stmt = $pdo->prepare("
                SELECT service_name 
                FROM job_order_service_types 
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$service) {
                echo json_encode(['success' => false, 'message' => 'Service not found']);
                exit;
            }
            
            // Update status to active
            $stmt = $pdo->prepare("
                UPDATE job_order_service_types 
                SET active = 1, status = 'active'
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Activate Service',
                "Manager activated service type: {$service['service_name']}");
            
            echo json_encode(['success' => true, 'message' => 'Service activated successfully']);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Manager Set Prices Handler Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
