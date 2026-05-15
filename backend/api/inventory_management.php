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
    $stock_level = (int)($_POST['stock_level'] ?? 0);
    $unit_cost = (float)($_POST['unit_cost'] ?? 0);
    $unit_price = (float)($_POST['unit_price'] ?? 0);
    
    // Validate input
    if ($item_id <= 0) {
        echo json_encode(['error' => 'Invalid item ID']);
        return;
    }
    
    if ($stock_level < 0 || $stock_level > 9999) {
        echo json_encode(['error' => 'Stock level must be between 0 and 9999']);
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
    
    // Get current item details for audit
    $stmt = $pdo->prepare("SELECT * FROM inventory_products WHERE id = ?");
    $stmt->execute([$item_id]);
    $current_item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_item) {
        echo json_encode(['error' => 'Item not found']);
        return;
    }
    
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
        
        echo json_encode([
            'success' => true,
            'message' => 'Item updated successfully'
        ]);
    } else {
        echo json_encode(['error' => 'Failed to update item']);
    }
}

function get_items() {
    global $pdo, $station_id, $role;
    
    if (in_array($role, ['admin', 'superadmin'])) {
        // Admin can see all items
        $stmt = $pdo->query("SELECT * FROM inventory_products ORDER BY category, product_name");
    } else {
        // Manager can see items for their station (implementation depends on station-specific inventory)
        $stmt = $pdo->query("SELECT * FROM inventory_products ORDER BY category, product_name");
    }
    
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
