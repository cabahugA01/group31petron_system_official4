<?php
require_once __DIR__ . '/../public/db_connect.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $service_entry_id = $data['service_entry_id'] ?? '';
    $item_name = $data['item_name'] ?? '';
    $description = $data['description'] ?? '';
    $parts_cost = $data['parts_cost'] ?? 0;
    $labor_cost = $data['labor_cost'] ?? 0;
    $staff_name = $data['staff_name'] ?? '';
    
    if (empty($service_entry_id) || empty($item_name)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // 1. Add the new item
        $stmt = $pdo->prepare("
            INSERT INTO service_items 
            (service_entry_id, item_name, description, quantity, 
             unit_price, labor_cost, item_type, total_cost, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $total_cost = $parts_cost + $labor_cost;
        
        $stmt->execute([
            $service_entry_id,
            $item_name,
            $description,
            1,
            $parts_cost,
            $labor_cost,
            'service',
            $total_cost
        ]);
        
        // 2. Update service entry totals
        $stmt = $pdo->prepare("
            UPDATE service_entries 
            SET items_count = items_count + 1,
                parts_total = parts_total + ?,
                labor_total = labor_total + ?,
                grand_total = grand_total + ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$parts_cost, $labor_cost, $total_cost, $service_entry_id]);
        
        // 3. Add to service history log
        $stmt = $pdo->prepare("
            INSERT INTO service_history 
            (service_entry_id, action, details, staff_name, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $action = "Item Added";
        $details = "Added: {$item_name} (Parts: ₱{$parts_cost}, Labor: ₱{$labor_cost})";
        
        $stmt->execute([$service_entry_id, $action, $details, $staff_name]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Item added successfully',
            'new_total' => $total_cost
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}
?>