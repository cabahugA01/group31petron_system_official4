<?php
// Stock Requests API
// Handles staff stock requests, manager approval, and admin oversight

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
        case 'create_request':
            if ($method === 'POST' && in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                create_stock_request();
            } else {
                echo json_encode(['error' => 'Unauthorized']);
            }
            break;
            
        case 'get_requests':
            if (in_array($role, ['manager', 'admin', 'superadmin'])) {
                get_stock_requests();
            } else {
                echo json_encode(['error' => 'Unauthorized']);
            }
            break;
            
        case 'approve_request':
            if ($method === 'POST' && in_array($role, ['manager'])) {
                approve_stock_request();
            } else {
                echo json_encode(['error' => 'Unauthorized']);
            }
            break;
            
        case 'reject_request':
            if ($method === 'POST' && in_array($role, ['manager'])) {
                reject_stock_request();
            } else {
                echo json_encode(['error' => 'Unauthorized']);
            }
            break;
            
        case 'get_my_requests':
            if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                get_my_requests();
            } else {
                echo json_encode(['error' => 'Unauthorized']);
            }
            break;
            
        case 'get_audit_trail':
            if (in_array($role, ['admin', 'superadmin'])) {
                get_audit_trail();
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

function create_stock_request() {
    global $pdo, $me, $station_id;
    
    $item_id = (int)($_POST['item_id'] ?? 0);
    $requested_quantity = (int)($_POST['requested_quantity'] ?? 1);
    $remarks = $_POST['remarks'] ?? '';
    
    // Validate input
    if ($item_id <= 0 || $requested_quantity <= 0) {
        echo json_encode(['error' => 'Invalid item or quantity']);
        return;
    }
    
    // Get item details
    $stmt = $pdo->prepare("SELECT * FROM inventory_products WHERE id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        echo json_encode(['error' => 'Item not found']);
        return;
    }
    
    // Validate staff_id against users table
    $safe_staff_id = null;
    if (!empty($me['id'])) {
        try {
            $chk_u = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $chk_u->execute([(int)$me['id']]);
            $found_u = $chk_u->fetchColumn();
            if ($found_u) {
                $safe_staff_id = (int)$found_u;
            }
        } catch (Exception $e) {}
    }
    if (!$safe_staff_id) {
        try {
            $chk_alt = $pdo->prepare("SELECT id FROM users WHERE station_id = ? ORDER BY id ASC LIMIT 1");
            $chk_alt->execute([$station_id]);
            $safe_staff_id = $chk_alt->fetchColumn() ? (int)$chk_alt->fetchColumn() : null;
        } catch (Exception $e) {}
    }

    // Check if there's already a pending request for this item
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests 
                           WHERE staff_id = ? AND item_id = ? AND status = 'Pending'");
    $stmt->execute([$safe_staff_id, $item_id]);
    $pending_count = $stmt->fetchColumn();
    
    if ($pending_count > 0) {
        echo json_encode(['error' => 'You already have a pending request for this item']);
        return;
    }
    
    // Create stock request
    $stmt = $pdo->prepare("INSERT INTO stock_requests 
                          (staff_id, station_id, item_id, item_sku, item_name, item_category, 
                           current_stock, requested_quantity, remarks, status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
    
    $result = $stmt->execute([
        $safe_staff_id,
        $station_id,
        $item['id'],
        $item['sku'] ?? '',
        $item['product_name'],
        $item['category'],
        $item['stock_quantity'] ?? 0,
        $requested_quantity,
        $remarks
    ]);
    
    if ($result) {
        $request_id = $pdo->lastInsertId();
        
        // Log audit trail
        log_stock_request_action($request_id, 'Created', $me['id'], role_key($me['role']), null, 'Pending', $remarks);
        
        echo json_encode([
            'success' => true,
            'message' => 'Stock request submitted successfully',
            'request_id' => $request_id
        ]);
    } else {
        echo json_encode(['error' => 'Failed to create stock request']);
    }
}

function get_stock_requests() {
    global $pdo, $station_id, $role;
    
    if (in_array($role, ['admin', 'superadmin'])) {
        // Admin can see all requests
        $stmt = $pdo->prepare("SELECT sr.*, s.name as station_name, u.name as staff_name, 
                               m.name as manager_name
                               FROM stock_requests sr
                               JOIN stations s ON sr.station_id = s.id
                               JOIN users u ON sr.staff_id = u.id
                               LEFT JOIN users m ON sr.manager_id = m.id
                               ORDER BY sr.created_at DESC");
        $stmt->execute();
    } else {
        // Manager can see requests for their station
        $stmt = $pdo->prepare("SELECT sr.*, u.name as staff_name, m.name as manager_name
                               FROM stock_requests sr
                               JOIN users u ON sr.staff_id = u.id
                               LEFT JOIN users m ON sr.manager_id = m.id
                               WHERE sr.station_id = ?
                               ORDER BY sr.created_at DESC");
        $stmt->execute([$station_id]);
    }
    
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['requests' => $requests]);
}

function get_my_requests() {
    global $pdo, $me;
    
    $stmt = $pdo->prepare("SELECT * FROM stock_requests 
                           WHERE staff_id = ? 
                           ORDER BY created_at DESC");
    $stmt->execute([$me['id']]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['requests' => $requests]);
}

function approve_stock_request() {
    global $pdo, $me, $station_id;
    
    $request_id = (int)($_POST['request_id'] ?? 0);
    $approved_quantity = (float)($_POST['approved_quantity'] ?? 0);
    $approved_price = (float)($_POST['approved_price'] ?? 0);
    $manager_notes = $_POST['manager_notes'] ?? '';
    
    if ($request_id <= 0 || $approved_quantity <= 0 || $approved_price < 0) {
        echo json_encode(['error' => 'Invalid request, quantity, or price']);
        return;
    }
    
    // Get request details
    $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ?");
    $stmt->execute([$request_id, $station_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        echo json_encode(['error' => 'Request not found']);
        return;
    }
    
    if ($request['status'] !== 'Pending') {
        echo json_encode(['error' => 'Request is not pending']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        // Update request
        $stmt = $pdo->prepare("UPDATE stock_requests 
                              SET approved_quantity = ?, approved_price = ?, manager_id = ?, manager_notes = ?, 
                                  status = 'Approved', processed_at = NOW()
                              WHERE id = ?");
        
        $stmt->execute([$approved_quantity, $approved_price, $me['id'], $manager_notes, $request_id]);
        
        // Auto-generate Purchase Order
        $po_number = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
        $total_amount = $approved_quantity * $approved_price;
        
        $stmt_po = $pdo->prepare("INSERT INTO purchase_orders 
            (po_number, request_id, station_id, product_name, quantity, unit_price, total_amount, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending Approval', ?)");
            
        $stmt_po->execute([
            $po_number,
            $request_id,
            $station_id,
            $request['item_name'], // map stock_requests.item_name to purchase_orders.product_name
            $approved_quantity,
            $approved_price,
            $total_amount,
            $me['id']
        ]);
        
        // Log audit trail
        log_stock_request_action($request_id, 'Approved', $me['id'], role_key($me['role']), 
                                'Pending', 'Approved', $manager_notes . " (Generated PO: $po_number)");
                                
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Stock request approved and Purchase Order generated successfully'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Failed to approve request: ' . $e->getMessage()]);
    }
}

function reject_stock_request() {
    global $pdo, $me, $station_id;
    
    $request_id = (int)($_POST['request_id'] ?? 0);
    $manager_notes = $_POST['manager_notes'] ?? '';
    
    if ($request_id <= 0) {
        echo json_encode(['error' => 'Invalid request']);
        return;
    }
    
    // Get request details
    $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ?");
    $stmt->execute([$request_id, $station_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        echo json_encode(['error' => 'Request not found']);
        return;
    }
    
    if ($request['status'] !== 'Pending') {
        echo json_encode(['error' => 'Request is not pending']);
        return;
    }
    
    // Update request
    $stmt = $pdo->prepare("UPDATE stock_requests 
                          SET manager_id = ?, manager_notes = ?, status = 'Rejected'
                          WHERE id = ?");
    
    $result = $stmt->execute([$me['id'], $manager_notes, $request_id]);
    
    if ($result) {
        // Log audit trail
        log_stock_request_action($request_id, 'Rejected', $me['id'], role_key($me['role']), 
                                'Pending', 'Rejected', $manager_notes);
        
        echo json_encode([
            'success' => true,
            'message' => 'Stock request rejected successfully'
        ]);
    } else {
        echo json_encode(['error' => 'Failed to reject request']);
    }
}

function get_audit_trail() {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT sra.*, sr.item_name, sr.item_sku, u.name as performed_by_name
                           FROM stock_request_audit sra
                           JOIN stock_requests sr ON sra.stock_request_id = sr.id
                           JOIN users u ON sra.performed_by = u.id
                           ORDER BY sra.created_at DESC");
    $stmt->execute();
    $audit_trail = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['audit_trail' => $audit_trail]);
}

function log_stock_request_action($request_id, $action_type, $performed_by, $performed_by_role, $old_status, $new_status, $notes) {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO stock_request_audit 
                          (stock_request_id, action_type, performed_by, performed_by_role, 
                           old_status, new_status, notes)
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->execute([$request_id, $action_type, $performed_by, $performed_by_role, $old_status, $new_status, $notes]);
}
?>
