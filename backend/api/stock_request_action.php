<?php
require_once '../lib.php';
require_once '../../public/db_connect.php';
header('Content-Type: application/json');

try {
    global $pdo;
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    $action = $input['action'] ?? null;
    $requestId = $input['request_id'] ?? null;
    $managerNotes = $input['manager_notes'] ?? '';
    
    if (!$action || !$requestId) {
        echo json_encode(['success' => false, 'message' => 'Action and request ID required']);
        exit;
    }
    
    // Get current user (manager)
    $managerId = current_user()['id'] ?? null;
    $stationId = user_station_id();
    
    if (!$managerId) {
        echo json_encode(['success' => false, 'message' => 'Manager authentication required']);
        exit;
    }
    
    // Get request details
    $stmt = $pdo->prepare('SELECT * FROM stock_requests WHERE id = ? AND status = "Pending"');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
        exit;
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        switch ($action) {
            case 'approve':
                $approvedQuantity = $input['approved_quantity'] ?? $request['requested_quantity'];
                
                // Update inventory - add approved quantity to stock
                $stmt = $pdo->prepare('UPDATE inventory_products SET stock = stock + ? WHERE id = ?');
                $stmt->execute([$approvedQuantity, $request['item_id']]);
                
                // Update request status
                $stmt = $pdo->prepare('
                    UPDATE stock_requests 
                    SET status = "Approved", 
                        approved_quantity = ?, 
                        manager_id = ?, 
                        manager_notes = ?, 
                        processed_at = NOW() 
                    WHERE id = ?
                ');
                $stmt->execute([$approvedQuantity, $managerId, $managerNotes, $requestId]);
                
                // Log audit trail
                logAudit($pdo, $requestId, $managerId, 'Approved', 'Pending', 'Approved', $managerNotes);
                
                $message = "Stock request approved successfully! {$approvedQuantity} units added to inventory.";
                break;
                
            case 'reject':
                // Update request status
                $stmt = $pdo->prepare('
                    UPDATE stock_requests 
                    SET status = "Rejected", 
                        manager_id = ?, 
                        manager_notes = ?, 
                        processed_at = NOW() 
                    WHERE id = ?
                ');
                $stmt->execute([$managerId, $managerNotes, $requestId]);
                
                // Log audit trail
                logAudit($pdo, $requestId, $managerId, 'Rejected', 'Pending', 'Rejected', $managerNotes);
                
                $message = "Stock request rejected successfully.";
                break;
                
            case 'edit':
                $newQuantity = $input['requested_quantity'] ?? $request['requested_quantity'];
                
                // Update request quantity
                $stmt = $pdo->prepare('
                    UPDATE stock_requests 
                    SET requested_quantity = ?, 
                        manager_id = ?, 
                        manager_notes = ?, 
                        updated_at = NOW() 
                    WHERE id = ?
                ');
                $stmt->execute([$newQuantity, $managerId, $managerNotes, $requestId]);
                
                // Log audit trail
                logAudit($pdo, $requestId, $managerId, 'Edited', 'Pending', 'Pending', $managerNotes);
                
                $message = "Stock request updated successfully.";
                break;
                
            default:
                throw new Exception("Invalid action: $action");
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

function logAudit($pdo, $requestId, $userId, $actionType, $oldStatus, $newStatus, $notes) {
    // Determine role (manager)
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn() ?: 'manager';
    $roleKey = strtolower(str_replace(' ', '_', $role));
    
    $stmt = $pdo->prepare('
        INSERT INTO stock_request_audit 
        (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $requestId,
        $actionType,
        $userId,
        $roleKey,
        $oldStatus,
        $newStatus,
        $notes
    ]);
}
?>
