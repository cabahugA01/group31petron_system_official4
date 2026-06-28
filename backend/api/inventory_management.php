<?php
// Inventory Management API
// Handles CRUD operations for inventory items

header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

// Get current user
$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// Validate station access
if (empty($station_id)) {
    echo json_encode(['error' => 'Station access required']);
    exit;
}

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'update_item':
            if ($method === 'POST' && in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                update_item();
            } else {
                echo json_encode(['error' => 'Unauthorized']);
            }
            break;
            
        case 'get_items':
            if (in_array($role, ['manager', 'admin', 'superadmin'])) {
                get_items();
            } else {
                echo json_encode(['error' => 'Unauthorized']);
            }
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

function update_item() {
    global $pdo, $me, $station_id;
    
    $item_id = (int)($_POST['item_id'] ?? 0);
    $stock_level = (float)($_POST['stock_level'] ?? 0);
    $unit_cost = (float)($_POST['unit_cost'] ?? 0);
    $unit_price = (float)($_POST['unit_price'] ?? 0);
    
    // Validate input
    if ($item_id <= 0) {
        echo json_encode(['error' => 'Invalid item ID']);
        return;
    }
    
    if ($stock_level < 0 || $stock_level > 999999) {
        echo json_encode(['error' => 'Stock level must be between 0 and 999999']);
        return;
    }
    
    if ($unit_cost < 0 || $unit_price < 0) {
        echo json_encode(['error' => 'Costs and prices must be positive']);
        return;
    }
    
    if ($unit_price < $unit_cost) {
        echo json_encode(['error' => 'Selling price cannot be less than unit cost']);
        return;
    }
    
    // Get current item details for audit and the station-specific stock row.
    $stmt = $pdo->prepare("
        SELECT
            ip.*,
            si.id AS station_inventory_id,
            si.stock_level AS station_stock_level,
            si.cost AS station_cost,
            si.price AS station_price,
            si.reorder_level AS station_reorder_level
        FROM inventory_products ip
        LEFT JOIN station_inventory si
            ON si.product_id = ip.id
           AND si.station_id = ?
        WHERE ip.id = ?
          AND LOWER(COALESCE(ip.category, '')) <> 'fuel'
        LIMIT 1
    ");
    $stmt->execute([$station_id, $item_id]);
    $current_item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_item) {
        echo json_encode(['error' => 'Item not found']);
        return;
    }

    $old_stock_level = (float)($current_item['station_stock_level'] ?? $current_item['stock_quantity'] ?? 0);
    $old_cost = (float)($current_item['station_cost'] ?? $current_item['unit_cost'] ?? 0);
    $old_price = (float)($current_item['station_price'] ?? $current_item['unit_price'] ?? 0);
    $old_for_audit = $current_item;
    $old_for_audit['stock_quantity'] = $old_stock_level;
    $old_for_audit['unit_cost'] = $old_cost;
    $old_for_audit['unit_price'] = $old_price;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE inventory_products
            SET stock_quantity = ?, stock = ?, unit_cost = ?, unit_price = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$stock_level, $stock_level, $unit_cost, $unit_price, $item_id]);

        if (!empty($current_item['station_inventory_id'])) {
            $stmt = $pdo->prepare("
                UPDATE station_inventory
                SET stock_level = ?,
                    cost = ?,
                    price = ?,
                    status = 'active',
                    last_updated = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$stock_level, $unit_cost, $unit_price, $current_item['station_inventory_id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO station_inventory
                    (station_id, product_id, stock_level, cost, price, reorder_level, unit, status, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
            ");
            $stmt->execute([
                $station_id,
                $item_id,
                $stock_level,
                $unit_cost,
                $unit_price,
                (int)($current_item['min_stock'] ?? 10),
                $current_item['unit'] ?? 'pcs'
            ]);
            $current_item['station_inventory_id'] = $pdo->lastInsertId();
        }

        $pdo->prepare("
            INSERT INTO inventory_logs
                (station_id, product_id, user_id, action, quantity_before, quantity_after,
                 quantity_change, reference_type, reference_id, notes, created_at)
            VALUES (?, ?, ?, 'Manual Update', ?, ?, ?, 'inventory_management', ?, ?, NOW())
        ")->execute([
            $station_id,
            $item_id,
            $me['id'],
            $old_stock_level,
            $stock_level,
            $stock_level - $old_stock_level,
            $current_item['station_inventory_id'],
            'Manual inventory update via inventory module'
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['error' => 'Failed to update item: ' . $e->getMessage()]);
        return;
    }

    log_inventory_change($item_id, $old_for_audit, [
        'stock_quantity' => $stock_level,
        'unit_cost' => $unit_cost,
        'unit_price' => $unit_price
    ], $me['id']);

    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $detail = "Inventory updated | Item: {$current_item['product_name']} (ID #{$item_id})"
                . " | Stock: {$old_stock_level} -> {$stock_level}"
                . " | Cost: PHP " . number_format($unit_cost, 2)
                . " | Price: PHP " . number_format($unit_price, 2);
        $pdo->prepare("
            INSERT INTO audit_logs
                (user_id, log_type, action_type, action_details, entity_type, entity_id,
                 old_values, new_values, status, ip_address, user_agent, created_at)
            VALUES (?, 'inventory', 'Update', ?, 'station_inventory', ?, ?, ?, 'Success', ?, ?, NOW())
        ")->execute([
            $me['id'],
            $detail,
            $current_item['station_inventory_id'],
            json_encode(['stock_level' => $old_stock_level, 'cost' => $old_cost, 'price' => $old_price]),
            json_encode(['stock_level' => $stock_level, 'cost' => $unit_cost, 'price' => $unit_price]),
            $ip,
            $ua
        ]);
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'message' => 'Item updated successfully'
    ]);
    return;
    
    // Check if item belongs to staff's station (if applicable)
    if (!in_array(role_key($me['role']), ['admin', 'superadmin'])) {
        // For staff, we'll allow editing of merchandise items regardless of station
        // as this is for operational corrections
    }
    
    // Update the item
    $stmt = $pdo->prepare("UPDATE inventory_products 
                          SET stock_quantity = ?, unit_cost = ?, unit_price = ?, updated_at = NOW()
                          WHERE id = ?");
    
    $result = $stmt->execute([$stock_level, $unit_cost, $unit_price, $item_id]);
    
    if ($result) {
        // Log the change for audit purposes
        log_inventory_change($item_id, $current_item, [
            'stock_quantity' => $stock_level,
            'unit_cost' => $unit_cost,
            'unit_price' => $unit_price
        ], $me['id']);

        // ── Audit log ──
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $detail = "Inventory updated | Item: {$current_item['product_name']} (ID #{$item_id})"
                    . " | Stock: {$current_item['stock_quantity']} → {$stock_level}"
                    . " | Cost: ₱" . number_format($unit_cost, 2)
                    . " | Price: ₱" . number_format($unit_price, 2);
            $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'inventory', 'Update', ?, 'inventory', ?, 'Success', ?, ?, NOW())")
                ->execute([$me['id'], $detail, $item_id, $ip, $ua]);
        } catch (Exception $e) {}

        echo json_encode([
            'success' => true,
            'message' => 'Item updated successfully'
        ]);
    } else {
        echo json_encode(['error' => 'Failed to update item']);
    }
}

function get_items() {
    global $pdo, $station_id;

    $stmt = $pdo->prepare("
        SELECT
            ip.*,
            si.id AS station_inventory_id,
            si.stock_level,
            si.cost,
            si.price,
            si.reorder_level,
            si.status AS station_status,
            si.last_updated
        FROM station_inventory si
        INNER JOIN inventory_products ip ON ip.id = si.product_id
        WHERE si.station_id = ?
          AND LOWER(si.status) = 'active'
          AND LOWER(COALESCE(ip.status, 'active')) <> 'inactive'
          AND LOWER(COALESCE(ip.category, '')) <> 'fuel'
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['items' => $items]);
}

function log_inventory_change($item_id, $old_data, $new_data, $user_id) {
    global $pdo;
    
    $changes = [];
    foreach ($new_data as $field => $new_value) {
        if ($old_data[$field] != $new_value) {
            $changes[] = "$field: '{$old_data[$field]}' -> '$new_value'";
        }
    }
    
    if (!empty($changes)) {
        $change_log = implode(', ', $changes);
        $stmt = $pdo->prepare("INSERT INTO inventory_audit_log 
                              (item_id, user_id, action, old_values, new_values, created_at)
                              VALUES (?, ?, 'update', ?, ?, NOW())");
        
        $old_values_json = json_encode([
            'stock_quantity' => $old_data['stock_quantity'],
            'unit_cost' => $old_data['unit_cost'],
            'unit_price' => $old_data['unit_price']
        ]);
        
        $new_values_json = json_encode($new_data);
        
        $stmt->execute([$item_id, $user_id, $old_values_json, $new_values_json]);
    }
}

// Create inventory audit log table if it doesn't exist
$create_audit_table = "
CREATE TABLE IF NOT EXISTS inventory_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    old_values JSON,
    new_values JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_products(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
)";

try {
    $pdo->exec($create_audit_table);
} catch (Exception $e) {
    // Table might already exist, ignore error
}
?>
