<?php
/**
 * Admin Purchase Orders Handler
 * Handles: Get PO details for editing, Update PO
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

header('Content-Type: application/json');

$me = current_user();
$role = role_key($me['role'] ?? '');

// Access control - only admin and above
if (!in_array($role, ['admin', 'superadmin', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'get_po_details') {
        $po_no = trim($_GET['po_no'] ?? '');
        $po_type = trim($_GET['po_type'] ?? '');
        
        if (empty($po_no) || empty($po_type)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }
        
        try {
            if ($po_type === 'merch' || $po_type === 'Merchandise') {
                // Get PO header
                $stmt = $pdo->prepare("
                    SELECT po.*, u.name as generated_by_name
                    FROM purchase_orders po
                    LEFT JOIN users u ON po.created_by = u.id
                    WHERE po.batch_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$po_no]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$po) {
                    echo json_encode(['success' => false, 'message' => 'PO not found']);
                    exit;
                }
                
                // Get PO items
                $stmt = $pdo->prepare("
                    SELECT id, product_name, quantity, unit_price
                    FROM purchase_orders
                    WHERE batch_id = ?
                    ORDER BY id
                ");
                $stmt->execute([$po_no]);
                $raw_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $items = [];
                foreach ($raw_items as $raw_item) {
                    $stmt_items = $pdo->prepare("
                        SELECT id, item_name AS product_name, quantity, unit_price
                        FROM purchase_order_items
                        WHERE po_id = ?
                        ORDER BY id
                    ");
                    $stmt_items->execute([$raw_item['id']]);
                    $po_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($po_items)) {
                        foreach ($po_items as $item) {
                            $items[] = [
                                'id' => 'poi_' . $item['id'],
                                'product_name' => $item['product_name'],
                                'quantity' => $item['quantity'],
                                'unit_price' => $item['unit_price']
                            ];
                        }
                    } else {
                        $items[] = [
                            'id' => 'po_' . $raw_item['id'],
                            'product_name' => $raw_item['product_name'],
                            'quantity' => $raw_item['quantity'],
                            'unit_price' => $raw_item['unit_price']
                        ];
                    }
                }
                
            } else {
                // Fuel PO
                $stmt = $pdo->prepare("
                    SELECT fpo.*, u.name as generated_by_name
                    FROM fuel_purchase_orders fpo
                    LEFT JOIN users u ON fpo.approved_by = u.id
                    WHERE fpo.batch_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$po_no]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$po) {
                    echo json_encode(['success' => false, 'message' => 'PO not found']);
                    exit;
                }
                
                // Get PO items
                $stmt = $pdo->prepare("
                    SELECT id, fuel_type as product_name, quantity, unit_price
                    FROM fuel_purchase_orders
                    WHERE batch_id = ?
                    ORDER BY id
                ");
                $stmt->execute([$po_no]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                'success' => true,
                'po' => [
                    'po_no' => $po_no,
                    'supplier' => $po['supplier'] ?? '',
                    'type' => $po_type
                ],
                'items' => $items
            ]);
            exit;
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Handle POST requests
$action = $_POST['action'] ?? '';

try {
    if ($action === 'edit_po') {
        $po_no = trim($_POST['po_no'] ?? '');
        $po_type = trim($_POST['po_type'] ?? '');
        $supplier = trim($_POST['supplier'] ?? '');
        $items = $_POST['items'] ?? [];
        
        if (empty($po_no) || empty($po_type) || empty($supplier)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        if ($po_type === 'merch' || $po_type === 'Merchandise') {
            // Update merchandise PO
            $stmt = $pdo->prepare("
                UPDATE purchase_orders
                SET supplier = ?, updated_at = NOW()
                WHERE batch_id = ?
            ");
            $stmt->execute([$supplier, $po_no]);
            
            // Update items
            foreach ($items as $item) {
                $id_str = trim($item['id'] ?? '');
                $qty = (float)($item['qty'] ?? 0);
                $price = (float)($item['price'] ?? 0);
                
                if (empty($id_str)) continue;

                if (strpos($id_str, 'poi_') === 0) {
                    $poi_id = (int)substr($id_str, 4);
                    // Update purchase_order_items
                    $stmt = $pdo->prepare("
                        UPDATE purchase_order_items
                        SET quantity = ?, quantity_ordered = ?, unit_price = ?, total_price = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$qty, $qty, $price, $qty * $price, $poi_id]);
                    
                    // Also recalculate parent purchase_orders total amount
                    $stmt_poi = $pdo->prepare("SELECT po_id FROM purchase_order_items WHERE id = ?");
                    $stmt_poi->execute([$poi_id]);
                    $po_id_parent = $stmt_poi->fetchColumn();
                    if ($po_id_parent) {
                        $pdo->prepare("
                            UPDATE purchase_orders
                            SET total_amount = (SELECT SUM(total_price) FROM purchase_order_items WHERE po_id = ?),
                                updated_at = NOW()
                            WHERE id = ?
                        ")->execute([$po_id_parent, $po_id_parent]);
                    }
                } else {
                    if (strpos($id_str, 'po_') === 0) {
                        $po_id = (int)substr($id_str, 3);
                    } else {
                        $po_id = (int)$id_str;
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE purchase_orders
                        SET quantity = ?, unit_price = ?, total_amount = ?, updated_at = NOW()
                        WHERE id = ? AND batch_id = ?
                    ");
                    $stmt->execute([$qty, $price, $qty * $price, $po_id, $po_no]);
                }
            }
        } else {
            // Update fuel PO
            $stmt = $pdo->prepare("
                UPDATE fuel_purchase_orders
                SET supplier = ?, updated_at = NOW()
                WHERE batch_id = ?
            ");
            $stmt->execute([$supplier, $po_no]);
            
            // Update items
            foreach ($items as $item) {
                $id = (int)($item['id'] ?? 0);
                $qty = (float)($item['qty'] ?? 0);
                $price = (float)($item['price'] ?? 0);
                
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE fuel_purchase_orders
                        SET quantity = ?, unit_price = ?, updated_at = NOW()
                        WHERE id = ? AND batch_id = ?
                    ");
                    $stmt->execute([$qty, $price, $id, $po_no]);
                }
            }
        }
        
        log_activity($pdo, $me['id'], 'Edit Purchase Order', "Admin edited PO: {$po_no}");
        
        $pdo->commit();
        
        $_SESSION['ok'] = 'Purchase Order updated successfully!';
        echo json_encode(['success' => true, 'message' => 'PO updated successfully']);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Admin PO Handler Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
