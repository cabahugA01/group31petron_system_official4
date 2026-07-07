<?php
// Staff Requests API
// Handles staff stock requests operations

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Start session for user authentication
session_start();

// Get current user
$me = current_user();
if (!$me) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$role = role_key($me['role'] ?? '');
$station_id = user_station_id();
$user_id = (int)$me['id'];

// Only staff can access this API
if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_my_requests':
            // Get staff's own stock requests
            $stmt = $pdo->prepare("
                SELECT 
                    sr.id,
                    sr.item_sku,
                    sr.item_name,
                    sr.item_category,
                    sr.current_stock,
                    sr.requested_quantity,
                    sr.approved_quantity,
                    sr.status,
                    sr.remarks,
                    sr.manager_notes,
                    sr.created_at,
                    sr.approved_at
                FROM stock_requests sr
                WHERE sr.staff_id = ? AND sr.station_id = ?
                ORDER BY sr.created_at DESC
            ");
            $stmt->execute([$user_id, $station_id]);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'requests' => $requests,
                'count' => count($requests)
            ]);
            break;
            
        case 'create_request':
            // Create a new stock request
            $item_id = (int)($_POST['item_id'] ?? 0);
            $item_name = trim($_POST['item_name'] ?? '');
            $item_category = trim($_POST['item_category'] ?? '');
            $requested_quantity = (int)($_POST['requested_quantity'] ?? 0);
            $remarks = trim($_POST['remarks'] ?? '');
            
            if (!$item_id || !$item_name || $requested_quantity <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid request data']);
                break;
            }
            
            // Get current stock
            $stmt = $pdo->prepare("
                SELECT stock_level FROM station_inventory 
                WHERE station_id = ? AND product_id = ?
            ");
            $stmt->execute([$station_id, $item_id]);
            $stock_info = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_stock = $stock_info ? (int)$stock_info['stock_level'] : 0;
            
            // Get item SKU
            $stmt = $pdo->prepare("
                SELECT sku FROM inventory_products WHERE id = ?
            ");
            $stmt->execute([$item_id]);
            $item_info = $stmt->fetch(PDO::FETCH_ASSOC);
            $item_sku = $item_info ? $item_info['sku'] : 'N/A';
            
            // Insert stock request
            $stmt = $pdo->prepare("
                INSERT INTO stock_requests (
                    staff_id, station_id, item_id, item_sku, item_name, 
                    item_category, current_stock, requested_quantity, 
                    remarks, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
            ");
            $stmt->execute([
                $user_id, $station_id, $item_id, $item_sku, $item_name,
                $item_category, $current_stock, $requested_quantity, $remarks
            ]);
            
            $request_id = $pdo->lastInsertId();
            
            // Log activity
            log_activity($pdo, $user_id, 'Create Stock Request', 
                "Stock Request #$request_id | Item: $item_name | Qty: $requested_quantity");
            
            echo json_encode([
                'success' => true,
                'message' => 'Stock request submitted successfully',
                'request_id' => $request_id
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
