<?php
require_once '../db_connect.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['station_id', 'user_id', 'vehicle_plate', 'vehicle_type', 'customer_name', 'items'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            exit;
        }
    }
    
    try {
        $pdo->beginTransaction();
        
        // 1. Create service entry
        $stmt = $pdo->prepare("
            INSERT INTO service_entries 
            (station_id, user_id, user_name, vehicle_plate, vehicle_type, customer_name, 
             contact_number, notes, status, priority, parts_total, labor_total, 
             grand_total, items_count, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $data['station_id'],
            $data['user_id'],
            $data['user_name'] ?? '',
            $data['vehicle_plate'],
            $data['vehicle_type'],
            $data['customer_name'],
            $data['contact_number'] ?? '',
            $data['notes'] ?? '',
            $data['status'] ?? 'Pending',
            $data['priority'] ?? 'Normal',
            $data['parts_total'] ?? 0,
            $data['labor_total'] ?? 0,
            $data['grand_total'] ?? 0,
            $data['items_count'] ?? 0
        ]);
        
        $entry_id = $pdo->lastInsertId();
        
        // 2. Add service items
        foreach ($data['items'] as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO service_items 
                (service_entry_id, item_name, description, quantity, 
                 unit_price, labor_cost, item_type, total_cost, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $entry_id,
                $item['service_name'] ?? $item['name'] ?? '',
                $item['description'] ?? '',
                1, // quantity
                $item['parts_cost'] ?? $item['unitPrice'] ?? 0,
                $item['labor_cost'] ?? $item['laborCost'] ?? 0,
                'service',
                $item['total'] ?? (($item['parts_cost'] ?? 0) + ($item['labor_cost'] ?? 0))
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'entry_id' => $entry_id,
            'message' => 'Service entry saved successfully'
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