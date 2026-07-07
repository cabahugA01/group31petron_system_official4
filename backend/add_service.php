<?php
require_once __DIR__ . '/../public/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    $service_entry_id = $data['service_entry_id'] ?? '';
    $service_type_id = $data['service_type_id'] ?? null;
    $item_name = $data['item_name'] ?? '';
    $description = $data['description'] ?? '';
    $quantity = $data['quantity'] ?? 1;
    $unit_price = $data['unit_price'] ?? 0;
    $labor_cost = $data['labor_cost'] ?? 0;
    $item_type = $data['item_type'] ?? 'service';
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO service_items 
            (service_entry_id, service_type_id, item_name, description, 
             quantity, unit_price, labor_cost, item_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $service_entry_id, $service_type_id, $item_name, $description,
            $quantity, $unit_price, $labor_cost, $item_type
        ]);
        
        // Update service entry totals
        $stmt = $pdo->prepare("
            UPDATE service_entries 
            SET items_count = items_count + 1,
                subtotal = subtotal + (? * ?),
                labor_total = labor_total + ?,
                grand_total = grand_total + ((? * ?) + ?)
            WHERE id = ?
        ");
        
        $stmt->execute([
            $quantity, $unit_price, $labor_cost,
            $quantity, $unit_price, $labor_cost,
            $service_entry_id
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Service item added successfully'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
}
?>