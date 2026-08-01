<?php
/**
 * Manager Set Prices Handler
 * Handles: Add fuel product, Edit fuel price, Deactivate fuel product
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

// Schema safety: widen fuel_inventory status column to VARCHAR(50) so 'active' and 'inactive' persist across page refreshes
try {
    $pdo->exec("ALTER TABLE fuel_inventory MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
} catch (Exception $e) {}

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
            $stmt = $pdo->prepare("SELECT f.*, COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'System') as updated_by_name FROM fuel_inventory f LEFT JOIN users u ON f.updated_by=u.id WHERE f.id=? AND f.station_id=? LIMIT 1");
            $stmt->execute([$fuel_id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fuel) { echo json_encode(['success'=>false,'message'=>'Fuel not found']); exit; }
            if ($fuel['last_updated']) $fuel['last_updated'] = date('M d, Y g:i A', strtotime($fuel['last_updated']));
            
            // Calculate Available Capacity
            $cap = (float)($fuel['capacity'] ?? 0);
            $stock = (float)($fuel['current_stock'] ?? ($fuel['current_level'] ?? 0));
            $fuel['current_stock'] = $stock;
            $fuel['available_capacity'] = max(0, $cap - $stock);

            // Check Price Request Status in pending_price_approvals
            $fuel['price_request_status'] = 'No Pending Request';
            try {
                $pa_stmt = $pdo->prepare("SELECT new_price, status, created_at FROM pending_price_approvals WHERE station_id = ? AND (product_id = ? OR product_name = ?) AND status = 'pending' ORDER BY id DESC LIMIT 1");
                $pa_stmt->execute([$station_id, $fuel_id, $fuel['fuel_type']]);
                $pa = $pa_stmt->fetch(PDO::FETCH_ASSOC);
                if ($pa) {
                    $fuel['price_request_status'] = 'Pending Admin Approval (₱' . number_format((float)$pa['new_price'], 2) . ')';
                }
            } catch (Exception $e) {}

            // 1. Price History
            $history = [];
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_price_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL DEFAULT 0,
                  `fuel_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NULL,
                  `old_price` DECIMAL(12,2) DEFAULT 0,
                  `new_price` DECIMAL(12,2) DEFAULT 0,
                  `difference` DECIMAL(12,2) DEFAULT 0,
                  `reason` VARCHAR(255) NULL,
                  `requested_by` INT NULL,
                  `requested_by_name` VARCHAR(255) NULL,
                  `approved_by` INT NULL,
                  `approved_by_name` VARCHAR(255) NULL,
                  `updated_by` INT NULL,
                  `status` VARCHAR(50) DEFAULT 'Approved',
                  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`fuel_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmt = $pdo->prepare("SELECT h.*, 
                                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, h.requested_by_name, 'Manager') as requested_by_name,
                                       COALESCE(CONCAT(a.first_name, ' ', a.last_name), a.name, a.username, h.approved_by_name, 'System') as approved_by_name
                                FROM fuel_price_history h 
                                LEFT JOIN users u ON h.requested_by=u.id OR h.updated_by=u.id 
                                LEFT JOIN users a ON h.approved_by=a.id 
                                WHERE h.fuel_id=? ORDER BY h.created_at DESC LIMIT 30");
                $stmt->execute([$fuel_id]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($history as &$h) { 
                    if ($h['created_at']) $h['created_at'] = date('M d, Y g:i A', strtotime($h['created_at'])); 
                    $h['difference'] = (float)($h['new_price'] ?? 0) - (float)($h['old_price'] ?? 0);
                    $h['status'] = !empty($h['status']) ? ucfirst($h['status']) : 'Approved';
                }
            } catch (Exception $e) {}

            // 2. Configuration History
            $config_history = [];
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_config_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL,
                  `fuel_inventory_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `field_name` VARCHAR(100) NOT NULL,
                  `old_value` VARCHAR(255) NULL,
                  `new_value` VARCHAR(255) NULL,
                  `updated_by` INT NULL,
                  `updated_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmt = $pdo->prepare("SELECT ch.*, COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, ch.updated_by_name, 'System') as updated_by_name FROM fuel_config_history ch LEFT JOIN users u ON ch.updated_by=u.id WHERE ch.fuel_inventory_id=? ORDER BY ch.created_at DESC LIMIT 30");
                $stmt->execute([$fuel_id]);
                $config_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($config_history as &$ch) {
                    if ($ch['created_at']) $ch['created_at'] = date('M d, Y g:i A', strtotime($ch['created_at']));
                }
            } catch (Exception $e) {}

            // 3. Status History
            $status_history = [];
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_status_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL,
                  `fuel_inventory_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `status` VARCHAR(50) NOT NULL,
                  `changed_by` INT NULL,
                  `changed_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmt = $pdo->prepare("SELECT sh.*, COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, sh.changed_by_name, 'System') as changed_by_name FROM fuel_status_history sh LEFT JOIN users u ON sh.changed_by=u.id WHERE sh.fuel_inventory_id=? ORDER BY sh.created_at DESC LIMIT 30");
                $stmt->execute([$fuel_id]);
                $status_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($status_history as &$sh) {
                    if ($sh['created_at']) $sh['created_at'] = date('M d, Y g:i A', strtotime($sh['created_at']));
                }
            } catch (Exception $e) {}

            echo json_encode(['success'=>true,'fuel'=>$fuel,'history'=>$history,'config_history'=>$config_history,'status_history'=>$status_history]); exit;
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }

    if ($action === 'get_merch_details') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
            $row = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
            $row['size'] = $row['unit'] ?? 'Piece (pc)';
            echo json_encode(['success'=>true,'item'=>$row]); exit;
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }

    if ($action === 'get_product_batches') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
            // 1. Try merchandise_batches
            $stmt = $pdo->prepare("
                SELECT mb.id,
                       COALESCE(NULLIF(mb.batch_number, ''), CONCAT('BT-', DATE_FORMAT(mb.created_at, '%Y%m%d'), '-', LPAD(mb.id, 4, '0'))) AS batch_number,
                       mb.quantity_received,
                       mb.remaining_qty,
                       mb.unit_cost,
                       mb.selling_price,
                       mb.date_received,
                       mb.status,
                       mb.supplier,
                       mb.notes,
                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'System') AS encoded_by
                FROM merchandise_batches mb
                LEFT JOIN users u ON u.id = mb.encoded_by
                WHERE mb.product_id = ?
                ORDER BY mb.date_received ASC, mb.id ASC
            ");
            $stmt->execute([$id]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Fallback to merchandise_stock_in if no records in merchandise_batches
            if (empty($batches)) {
                $stmt2 = $pdo->prepare("
                    SELECT msi.id,
                           COALESCE(NULLIF(msi.batch_ref, ''), CONCAT('BT-', DATE_FORMAT(msi.encoded_at, '%Y%m%d'), '-', LPAD(msi.id, 4, '0'))) AS batch_number,
                           msi.qty_received AS quantity_received,
                           msi.qty_received AS remaining_qty,
                           msi.unit_cost,
                           msi.selling_price,
                           DATE(msi.encoded_at) AS date_received,
                           'active' AS status,
                           'Petron Corporation' AS supplier,
                           msi.remarks AS notes,
                           COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'System') AS encoded_by
                    FROM merchandise_stock_in msi
                    LEFT JOIN users u ON u.id = msi.encoded_by
                    WHERE msi.product_id = ?
                    ORDER BY msi.encoded_at ASC, msi.id ASC
                ");
                $stmt2->execute([$id]);
                $batches = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success'=>true,'batches'=>$batches]); exit;
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
        }
    }

    if ($action === 'get_service_details') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT id,service_name,category,service_key,service_price,active,status FROM job_order_service_types WHERE id=? LIMIT 1");
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
            $ugt_no         = trim($_POST['ugt_no'] ?? '');
            $price          = (float)($_POST['price'] ?? 0);
            $capacity       = (float)($_POST['capacity'] ?? 0);
            $critical_level = (float)($_POST['critical_level'] ?? 0);
            $reorder_level  = (float)($_POST['reorder_level'] ?? 0);
            $status         = strtolower(trim($_POST['status'] ?? 'active'));
            $remarks        = trim($_POST['remarks'] ?? '');

            if ($status !== 'inactive') $status = 'active';

            // Validation rules
            if (empty($fuel_type)) {
                echo json_encode(['success' => false, 'message' => 'Fuel Name is required.']);
                exit;
            }
            if (mb_strlen($fuel_type) > 50) {
                echo json_encode(['success' => false, 'message' => 'Fuel Name must not exceed 50 characters.']);
                exit;
            }
            if (empty($ugt_no)) {
                echo json_encode(['success' => false, 'message' => 'UGT Number is required.']);
                exit;
            }
            if ($price <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid Selling Price.']);
                exit;
            }
            if ($capacity <= 0) {
                echo json_encode(['success' => false, 'message' => 'Tank Capacity must be greater than 0.']);
                exit;
            }
            if ($capacity <= $reorder_level) {
                echo json_encode(['success' => false, 'message' => 'Tank Capacity must be greater than Reorder Level.']);
                exit;
            }
            if ($reorder_level <= $critical_level) {
                echo json_encode(['success' => false, 'message' => 'Reorder Level must be greater than Critical Level.']);
                exit;
            }

            // 1. Check if Fuel Name already exists for this station
            $stmt = $pdo->prepare("SELECT id FROM fuel_inventory WHERE station_id = ? AND LOWER(fuel_type) = LOWER(?) LIMIT 1");
            $stmt->execute([$station_id, $fuel_type]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Fuel Name already exists.']);
                exit;
            }

            // 2. Check if selected UGT is already assigned for this station
            $stmt = $pdo->prepare("SELECT id FROM fuel_inventory WHERE station_id = ? AND LOWER(ugt_no) = LOWER(?) LIMIT 1");
            $stmt->execute([$station_id, $ugt_no]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Selected UGT is already assigned.']);
                exit;
            }

            // Resolve fuel_type_id from fuel_types table
            $fuel_type_id = 0;
            $ft = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $ft->execute([$fuel_type]);
            $ft_row = $ft->fetch(PDO::FETCH_ASSOC);
            if ($ft_row) {
                $fuel_type_id = (int)$ft_row['id'];
            } else {
                $ins_ft = $pdo->prepare("INSERT INTO fuel_types (name) VALUES (?)");
                $ins_ft->execute([$fuel_type]);
                $fuel_type_id = (int)$pdo->lastInsertId();
            }

            // Direct Save to fuel_inventory (Manager direct save - no Admin approval required)
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO fuel_inventory
                    (station_id, fuel_type_id, fuel_type, ugt_no, price_per_liter, capacity, critical_level, reorder_level,
                     current_level, current_stock, status, updated_by, last_updated)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, NOW())
                ");
                $stmt->execute([
                    $station_id,
                    $fuel_type_id,
                    $fuel_type,
                    $ugt_no,
                    $price,
                    $capacity,
                    $critical_level,
                    $reorder_level,
                    $status,
                    $me['id']
                ]);
                $new_fuel_id = (int)$pdo->lastInsertId();
            } catch (PDOException $pdoe) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $pdoe->getMessage()]);
                exit;
            }

            // Ensure table fuel_config_history exists
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_config_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL,
                  `fuel_inventory_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `field_name` VARCHAR(100) NOT NULL,
                  `old_value` VARCHAR(255) NULL,
                  `new_value` VARCHAR(255) NULL,
                  `updated_by` INT NULL,
                  `updated_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $user_name = $me['username'] ?? ($me['first_name'] ?? 'Manager');
                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Product Created', '-', ?, ?, ?, NOW())")
                    ->execute([$station_id, $new_fuel_id, $fuel_type, "UGT: {$ugt_no}, Price: ₱{$price}, Capacity: {$capacity}L, Critical: {$critical_level}L, Reorder: {$reorder_level}L", $me['id'], $user_name]);
            } catch (Exception $e) {}

            // Log Audit Trail
            log_activity($pdo, $me['id'], 'Add Fuel Product',
                "Manager added new fuel product: {$fuel_type} ({$ugt_no}) at ₱{$price}/L. Status: {$status}. Remarks: {$remarks}");

            echo json_encode(['success' => true, 'message' => 'Fuel product added successfully.']);
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

            if ($old_price == $new_price) {
                echo json_encode(['success' => true, 'message' => 'Price is unchanged.']);
                exit;
            }
            
            // Clear any previous pending approval for this fuel
            $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id=? AND status='pending'")
                ->execute([$station_id, $id]);

            // Submit new pending price approval (Requires Admin Approval)
            $stmt = $pdo->prepare("
                INSERT INTO pending_price_approvals
                (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_cost, new_cost, old_price, new_price, requested_by, manager_id, status, created_at)
                VALUES (?, 'fuel', ?, ?, 'price', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $station_id, $id, $fuel['fuel_type'],
                $old_price, $new_price,
                $old_price, $new_price,
                $old_price, $new_price,
                $me['id'], $me['id']
            ]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Edit Fuel Price Request',
                "Manager requested price change for {$fuel['fuel_type']} from ₱{$old_price} to ₱{$new_price}. Reason: {$reason}");
            
            echo json_encode(['success' => true, 'message' => 'Price change submitted for Admin approval.']);
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
            $product_name  = trim($_POST['product_name'] ?? '');
            $category      = trim($_POST['category'] ?? '');
            $brand         = trim($_POST['brand'] ?? '');
            $unit_cost     = (float)($_POST['unit_cost'] ?? 0);
            $unit_price    = (float)($_POST['unit_price'] ?? 0);
            $sku           = trim($_POST['sku'] ?? '');
            $size          = trim($_POST['size'] ?? '');
            $barcode       = trim($_POST['barcode'] ?? '');
            $reorder_level = (int)($_POST['reorder_level'] ?? 24);
            $critical_level= (int)($_POST['critical_level'] ?? 10);

            if (empty($product_name) || empty($category)) {
                echo json_encode(['success' => false, 'message' => 'Product name and category are required']);
                exit;
            }

            if ($unit_price < 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid price value']);
                exit;
            }

            $new_id = 0;
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_products
                    (product_name, category, brand, unit_cost, unit_price, sku, barcode, size,
                     reorder_level, critical_level, stock_quantity, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'active', NOW())
                ");
                $stmt->execute([$product_name, $category, $brand, $unit_cost, $unit_price,
                                $sku, $barcode ?: null, $size, $reorder_level, $critical_level]);
                $new_id = (int)$pdo->lastInsertId();
            } catch (Exception $legacy_error) {
                $category_id = ensure_product_category_id($pdo, $category);
                $unit_value = $size !== '' ? $size : 'pcs';
                $stmt = $pdo->prepare("
                    INSERT INTO products
                    (sku, name, description, category_id, cost, price, created_at, updated_at, min_stock_level, max_stock_level, station_id, current_stock, unit, capacity, status)
                    VALUES (?, ?, '', ?, ?, ?, NOW(), NOW(), ?, ?, ?, 0, ?, 480, 'active')
                ");
                $stmt->execute([$sku, $product_name, $category_id ?: null, $unit_cost, $unit_price,
                                $reorder_level, $reorder_level * 20, $station_id, $unit_value]);
                $new_id = (int)$pdo->lastInsertId();
            }

            if ($new_id > 0) {
                try {
                    $unit_value = $size !== '' ? $size : 'pcs';
                    $stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                    $stmt->execute([$station_id, $new_id]);
                    if (!$stmt->fetchColumn()) {
                        $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, unit, cost, price, reorder_level, critical_level, status, last_updated) VALUES (?, ?, 0, ?, ?, ?, ?, ?, 'active', NOW())")
                            ->execute([$station_id, $new_id, $unit_value, $unit_cost, $unit_price, $reorder_level, $critical_level]);
                    }
                } catch (Exception $e) {}
            }

            log_activity($pdo, $me['id'], 'Add Merchandise',
                "Manager added new merchandise: {$product_name}");

            echo json_encode(['success' => true, 'message' => 'Product added successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // EDIT FUEL — FULL (name, price, capacity, critical level, reorder level, status, remarks)
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_fuel_full':
            $id             = (int)($_POST['id'] ?? 0);
            $new_fuel_name  = trim($_POST['fuel_name'] ?? ($_POST['fuel_type'] ?? ''));
            $new_price      = (float)($_POST['price'] ?? 0);
            $capacity       = (float)($_POST['capacity'] ?? 0);
            $critical_level = (float)($_POST['critical_level'] ?? 0);
            $reorder_level  = (float)($_POST['reorder_level'] ?? 0);
            $new_status     = strtolower(trim($_POST['status'] ?? 'active'));
            $remarks        = trim($_POST['remarks'] ?? '');

            if (!in_array($new_status, ['active', 'inactive'])) $new_status = 'active';

            if ($id <= 0 || $new_price < 0 || $capacity < 0 || $critical_level < 0 || $reorder_level < 0) {
                echo json_encode(['success'=>false,'message'=>'Invalid parameters']);
                exit;
            }

            // Ensure table fuel_config_history exists
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_config_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL,
                  `fuel_inventory_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `field_name` VARCHAR(100) NOT NULL,
                  `old_value` VARCHAR(255) NULL,
                  `new_value` VARCHAR(255) NULL,
                  `updated_by` INT NULL,
                  `updated_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Exception $e) {}

            // Fetch current record
            $stmt = $pdo->prepare("SELECT fuel_type, price_per_liter, capacity, critical_level, reorder_level, status FROM fuel_inventory WHERE id=? AND station_id=? LIMIT 1");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fuel) { echo json_encode(['success'=>false,'message'=>'Fuel product not found']); exit; }

            $old_fuel_name = $fuel['fuel_type'];
            $old_price     = (float)$fuel['price_per_liter'];
            $old_cap       = (float)$fuel['capacity'];
            $old_crit      = (float)$fuel['critical_level'];
            $old_reorder   = (float)($fuel['reorder_level'] ?? 0);
            $old_status    = strtolower($fuel['status'] ?? 'active');
            $user_name     = $me['username'] ?? ($me['first_name'] ?? 'Manager');

            $target_fuel_name = !empty($new_fuel_name) ? $new_fuel_name : $old_fuel_name;

            try {
                // Log configuration history for changed fields
                if (!empty($new_fuel_name) && strcasecmp($old_fuel_name, $new_fuel_name) !== 0) {
                    $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Fuel Name', ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $target_fuel_name, $old_fuel_name, $new_fuel_name, $me['id'], $user_name]);
                }
                if (abs($old_cap - $capacity) > 0.001) {
                    $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Capacity', ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $target_fuel_name, number_format($old_cap, 2) . ' L', number_format($capacity, 2) . ' L', $me['id'], $user_name]);
                }
                if (abs($old_crit - $critical_level) > 0.001) {
                    $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Critical Level', ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $target_fuel_name, number_format($old_crit, 2) . ' L', number_format($critical_level, 2) . ' L', $me['id'], $user_name]);
                }
                if (abs($old_reorder - $reorder_level) > 0.001) {
                    $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Reorder Level', ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $target_fuel_name, number_format($old_reorder, 2) . ' L', number_format($reorder_level, 2) . ' L', $me['id'], $user_name]);
                }
                if ($old_status !== $new_status) {
                    $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Product Status', ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $target_fuel_name, ucfirst($old_status), ucfirst($new_status), $me['id'], $user_name]);

                    // Also log in fuel_status_history
                    try {
                        $status_label = ($new_status === 'active') ? 'Activated' : 'Deactivated';
                        $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, status, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                            ->execute([$station_id, $id, $target_fuel_name, $status_label, $me['id'], $user_name]);
                    } catch (Exception $e) {}
                }

                // Update fuel_inventory
                $stmt = $pdo->prepare("UPDATE fuel_inventory SET fuel_type=?, capacity=?, critical_level=?, reorder_level=?, status=?, updated_by=?, last_updated=NOW() WHERE id=? AND station_id=?");
                $stmt->execute([$target_fuel_name, $capacity, $critical_level, $reorder_level, $new_status, $me['id'], $id, $station_id]);

                if (abs($old_price - $new_price) > 0.001) {
                    // Clear previous pending approval
                    $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id=? AND status='pending'")
                        ->execute([$station_id, $id]);

                    // Submit pending price approval request
                    $stmt = $pdo->prepare("
                        INSERT INTO pending_price_approvals
                        (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_cost, new_cost, old_price, new_price, requested_by, manager_id, status, created_at)
                        VALUES (?, 'fuel', ?, ?, 'price', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                    ");
                    $stmt->execute([
                        $station_id, $id, $target_fuel_name,
                        $old_price, $new_price,
                        $old_price, $new_price,
                        $old_price, $new_price,
                        $me['id'], $me['id']
                    ]);

                    log_activity($pdo, $me['id'], 'Edit Fuel Product', "Manager requested price change for {$target_fuel_name} (₱{$old_price} -> ₱{$new_price}). Capacity: {$capacity}L, Status: {$new_status}");
                    echo json_encode(['success'=>true,'message'=>'Fuel details updated. Price change submitted for Admin approval.']);
                } else {
                    log_activity($pdo, $me['id'], 'Edit Fuel Product', "Manager updated {$target_fuel_name}: Capacity={$capacity}L, Critical Level={$critical_level}L, Reorder Level={$reorder_level}L, Status={$new_status}");
                    echo json_encode(['success'=>true,'message'=>'Fuel product updated successfully']);
                }
            } catch (PDOException $pdoe) {
                echo json_encode(['success'=>false,'message'=>'Database error: ' . $pdoe->getMessage()]);
            }
            break;

        // ══════════════════════════════════════════════════════════════════════
        // TOGGLE FUEL STATUS (Activate / Deactivate with Confirmation Dialog)
        // ══════════════════════════════════════════════════════════════════════
        case 'toggle_fuel_status':
            $id            = (int)($_POST['id'] ?? 0);
            $target_status = strtolower(trim($_POST['target_status'] ?? ''));

            if ($id <= 0 || !in_array($target_status, ['active', 'inactive'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }

            // Verify fuel belongs to manager's station
            $stmt = $pdo->prepare("SELECT id, fuel_type, ugt_no, status FROM fuel_inventory WHERE id = ? AND station_id = ? LIMIT 1");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found']);
                exit;
            }

            $old_status = strtolower($fuel['status'] ?? 'active');
            if ($old_status === $target_status) {
                echo json_encode(['success' => true, 'message' => "Fuel product is already {$target_status}."]);
                exit;
            }

            // Update status in fuel_inventory
            $stmt = $pdo->prepare("UPDATE fuel_inventory SET status = ?, updated_by = ?, last_updated = NOW() WHERE id = ? AND station_id = ?");
            $stmt->execute([$target_status, $me['id'], $id, $station_id]);

            $user_name = $me['username'] ?? ($me['first_name'] ?? 'Manager');

            // Log in fuel_status_history
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_status_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL,
                  `fuel_inventory_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `status` VARCHAR(50) NOT NULL,
                  `changed_by` INT NULL,
                  `changed_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $status_label = ($target_status === 'active') ? 'Activated' : 'Deactivated';
                $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, status, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$station_id, $id, $fuel['fuel_type'], $status_label, $me['id'], $user_name]);
            } catch (Exception $e) {}

            // Log in fuel_config_history
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_config_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL,
                  `fuel_inventory_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `field_name` VARCHAR(100) NOT NULL,
                  `old_value` VARCHAR(255) NULL,
                  `new_value` VARCHAR(255) NULL,
                  `updated_by` INT NULL,
                  `updated_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Product Status', ?, ?, ?, ?, NOW())")
                    ->execute([$station_id, $id, $fuel['fuel_type'], ucfirst($old_status), ucfirst($target_status), $me['id'], $user_name]);
            } catch (Exception $e) {}

            // Log activity
            log_activity($pdo, $me['id'], 'Toggle Fuel Status',
                "Manager changed status of fuel product: {$fuel['fuel_type']} ({$fuel['ugt_no']}) to {$target_status}");

            echo json_encode([
                'success' => true,
                'message' => "Fuel product {$fuel['fuel_type']} has been " . ($target_status === 'active' ? 'activated' : 'deactivated') . " successfully."
            ]);
            break;

        case 'edit_merchandise_full':
            $id             = (int)($_POST['id'] ?? 0);
            $product_name   = trim($_POST['product_name'] ?? '');
            $sku            = trim($_POST['sku'] ?? '');
            $category       = trim($_POST['category'] ?? '');
            $brand          = trim($_POST['brand'] ?? '');
            $size           = trim($_POST['size'] ?? '');
            $barcode        = trim($_POST['barcode'] ?? '');
            $unit_cost      = (float)($_POST['unit_cost'] ?? 0);
            $unit_price     = (float)($_POST['unit_price'] ?? 0);
            $reorder_level  = (int)($_POST['reorder_level'] ?? 24);
            $critical_level = (int)($_POST['critical_level'] ?? 10);
            $prod_status    = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

            if ($id <= 0 || empty($product_name) || empty($category)) {
                echo json_encode(['success'=>false,'message'=>'Name and category are required']);
                exit;
            }

            $old = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            if (!$old) { echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

            $old_price   = (float)$old['unit_price'];
            $old_cost    = (float)($old['unit_cost'] ?? 0);
            $category_id = ensure_product_category_id($pdo, $category);
            $unit_value  = $size !== '' ? $size : ($old['unit'] ?? 'pcs');

            // Price change requires Admin approval — keep old_price in live tables if price changed!
            $live_price = ($old_price > 0 && $old_price != $unit_price) ? $old_price : $unit_price;

            // 1. Update inventory_products (primary source)
            try {
                $stmt = $pdo->prepare("
                    UPDATE inventory_products
                    SET product_name=?, sku=?, barcode=?, category=?, brand=?, size=?,
                        unit_cost=?, unit_price=?, reorder_level=?, critical_level=?, status=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([$product_name, $sku, $barcode ?: null, $category, $brand,
                                $size, $unit_cost, $live_price, $reorder_level, $critical_level, $prod_status, $id]);
            } catch (Exception $e) {}

            // 2. Update products table (legacy source)
            try {
                if ($category_id > 0) {
                    $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, category_id=?, unit=?, cost=?, price=?, updated_at=NOW() WHERE id=?");
                    $stmt->execute([$product_name, $sku, $category_id, $unit_value, $unit_cost, $live_price, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, unit=?, cost=?, price=?, updated_at=NOW() WHERE id=?");
                    $stmt->execute([$product_name, $sku, $unit_value, $unit_cost, $live_price, $id]);
                }
            } catch (Exception $e) {}

            // 3. Upsert station_inventory
            try {
                $stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                $stmt->execute([$station_id, $id]);
                $si_id = (int)($stmt->fetchColumn() ?: 0);
                if ($si_id > 0) {
                    $pdo->prepare("UPDATE station_inventory SET unit=?, cost=?, price=?, reorder_level=?, critical_level=?, status=?, last_updated=NOW() WHERE id=?")
                        ->execute([$unit_value, $unit_cost, $live_price, $reorder_level, $critical_level, $prod_status, $si_id]);
                } else {
                    $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, unit, cost, price, reorder_level, critical_level, status, last_updated) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $unit_value, $unit_cost, $live_price, $reorder_level, $critical_level, $prod_status]);
                }
            } catch (Exception $e) {}

            if ($old_price != $unit_price && $old_price > 0) {
                // Clear existing pending request for this product
                $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type IN ('merchandise','product','inventory_product') AND product_id=? AND status='pending'")
                    ->execute([$station_id, $id]);

                // Create pending price request for Admin Approval
                $stmt = $pdo->prepare("
                    INSERT INTO pending_price_approvals
                    (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_cost, new_cost, old_price, new_price, requested_by, manager_id, status, created_at)
                    VALUES (?, 'merchandise', ?, ?, 'price', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $station_id, $id, $product_name,
                    $old_price, $unit_price,
                    $old_cost, $unit_cost,
                    $old_price, $unit_price,
                    $me['id'], $me['id']
                ]);

                log_activity($pdo, $me['id'], 'Edit Merchandise Price Request', "Manager requested price change for {$product_name} (₱{$old_price} -> ₱{$unit_price})");
                echo json_encode(['success'=>true, 'message'=>'Product details updated. Price change submitted for Admin approval.']);
            } else {
                log_activity($pdo, $me['id'], 'Edit Merchandise', "Manager updated product: {$product_name}");
                echo json_encode(['success'=>true, 'message'=>'Product updated successfully']);
            }
            break;

        case 'edit_merchandise_price':
            $id        = (int)($_POST['id'] ?? 0);
            $new_price = (float)($_POST['price'] ?? 0);
            if ($id <= 0 || $new_price < 0) { echo json_encode(['success'=>false,'message'=>'Invalid parameters']); exit; }
            $merch = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            if (!$merch) { echo json_encode(['success'=>false,'message'=>'Merchandise not found']); exit; }
            $old_price = (float)$merch['unit_price'];

            if ($old_price == $new_price) {
                echo json_encode(['success'=>true, 'message'=>'Price is unchanged.']);
                exit;
            }

            // Clear existing pending request
            $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type IN ('merchandise','product','inventory_product') AND product_id=? AND status='pending'")
                ->execute([$station_id, $id]);

            // Submit price change request for Admin approval
            $stmt = $pdo->prepare("
                INSERT INTO pending_price_approvals
                (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_cost, new_cost, old_price, new_price, requested_by, manager_id, status, created_at)
                VALUES (?, 'merchandise', ?, ?, 'price', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $station_id, $id, $merch['product_name'],
                $old_price, $new_price,
                (float)($merch['unit_cost']??0), (float)($merch['unit_cost']??0),
                $old_price, $new_price,
                $me['id'], $me['id']
            ]);

            log_activity($pdo, $me['id'], 'Edit Merchandise Price Request', "Manager requested price change for {$merch['product_name']} (₱{$old_price} -> ₱{$new_price})");
            echo json_encode(['success'=>true, 'message'=>'Price change submitted for Admin approval.']);
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
            
            $merch = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            
            if (!$merch) {
                echo json_encode(['success' => false, 'message' => 'Merchandise not found']);
                exit;
            }
            
            try {
                $stmt = $pdo->prepare("UPDATE inventory_products SET status = 'inactive' WHERE id = ?");
                $stmt->execute([$id]);
            } catch (Exception $legacy_error) {
                // Current inventory source uses products + station_inventory.
            }
            $pdo->prepare("UPDATE products SET status = 'inactive', updated_at = NOW() WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE station_inventory SET status = 'inactive', last_updated = NOW() WHERE station_id = ? AND product_id = ?")->execute([$station_id, $id]);
            
            log_activity($pdo, $me['id'], 'Deactivate Merchandise',
                "Manager deactivated merchandise: {$merch['product_name']}");
            
            echo json_encode(['success' => true, 'message' => 'Merchandise deactivated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ADD SERVICE
        // ══════════════════════════════════════════════════════════════════════
        case 'add_service':
            $service_name  = trim($_POST['service_name'] ?? '');
            $category      = trim($_POST['category'] ?? '');
            $service_key   = trim($_POST['service_key'] ?? '');
            $service_price = (float)($_POST['service_price'] ?? 0);
            
            if (empty($service_name) || empty($category) || empty($service_key)) {
                echo json_encode(['success' => false, 'message' => 'Service name, category, and key are required']);
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
            
            // Insert new service with category
            $stmt = $pdo->prepare("
                INSERT INTO job_order_service_types 
                (service_name, category, service_key, service_price, status, active)
                VALUES (?, ?, ?, ?, 'active', 1)
            ");
            $stmt->execute([$service_name, $category, $service_key, $service_price]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Add Service Type',
                "Manager added new service type: {$service_name} ({$category})");
            
            echo json_encode(['success' => true, 'message' => 'Service type added successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // EDIT SERVICE — FULL (name, category, key, price, status)
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_service_full':
            $id            = (int)($_POST['id'] ?? 0);
            $service_name  = trim($_POST['service_name'] ?? '');
            $category      = trim($_POST['category'] ?? '');
            $service_key   = trim($_POST['service_key'] ?? '');
            $service_price = (float)($_POST['service_price'] ?? 0);
            $active        = (int)($_POST['active'] ?? 1);
            // NOTE: job_order_service_types.status is enum('approved','pending','rejected') — don't set it to active/inactive

            if ($id <= 0 || empty($service_name) || empty($category) || empty($service_key) || $service_price < 0) {
                echo json_encode(['success'=>false,'message'=>'Name, category, key and price are required']);
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
                // Update non-pricing fields immediately (including category)
                $stmt = $pdo->prepare("UPDATE job_order_service_types SET service_name=?, category=?, service_key=?, active=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$service_name, $category, $service_key, $active, $id]);

                // Create pending price approval
                $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type='service_type' AND product_id=? AND status='pending'")
                    ->execute([$station_id, $id]);

                $stmt = $pdo->prepare("
                    INSERT INTO pending_price_approvals 
                    (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_cost, new_cost, old_price, new_price, requested_by, manager_id, status, created_at)
                    VALUES (?, 'service', ?, ?, 'price', ?, ?, 0, 0, ?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $station_id,
                    $id,
                    $service_name,
                    $old_price,
                    $service_price,
                    $old_price,
                    $service_price,
                    $me['id'],
                    $me['id']
                ]);

                log_activity($pdo, $me['id'], 'Edit Service Type', "Manager requested price change for service: {$service_name} ({$category})");
                echo json_encode(['success'=>true,'message'=>'Service details updated. Price change submitted for Admin approval.']);
            } else {
                // No price change, update everything immediately (including category)
                $stmt = $pdo->prepare("UPDATE job_order_service_types SET service_name=?, category=?, service_key=?, service_price=?, active=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$service_name, $category, $service_key, $service_price, $active, $id]);

                log_activity($pdo, $me['id'], 'Edit Service Type', "Manager updated service: {$service_name} ({$category})");
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
            
            $merch = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            
            if (!$merch) {
                echo json_encode(['success' => false, 'message' => 'Merchandise not found']);
                exit;
            }
            
            try {
                $stmt = $pdo->prepare("UPDATE inventory_products SET status = 'active' WHERE id = ?");
                $stmt->execute([$id]);
            } catch (Exception $legacy_error) {
                // Current inventory source uses products + station_inventory.
            }
            $pdo->prepare("UPDATE products SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE station_inventory SET status = 'active', last_updated = NOW() WHERE station_id = ? AND product_id = ?")->execute([$station_id, $id]);
            
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
