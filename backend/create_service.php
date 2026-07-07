<?php
require_once __DIR__ . '/../public/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    $station_id = $data['station_id'] ?? '';
    $user_id = $data['user_id'] ?? '';
    $vehicle_plate = $data['vehicle_plate'] ?? '';
    $vehicle_type = $data['vehicle_type'] ?? '';
    $customer_name = $data['customer_name'] ?? '';
    $contact_number = $data['contact_number'] ?? '';
    $notes = $data['notes'] ?? '';
    $subtotal = $data['subtotal'] ?? 0;
    $labor_total = $data['labor_total'] ?? 0;
    $grand_total = $data['grand_total'] ?? 0;
    $items_count = $data['items_count'] ?? 0;
    $status = $data['status'] ?? 'In Progress';
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO service_entries 
            (station_id, user_id, vehicle_plate, vehicle_type, customer_name, 
             contact_number, notes, subtotal, labor_total, grand_total, 
             items_count, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $station_id, $user_id, $vehicle_plate, $vehicle_type, $customer_name,
            $contact_number, $notes, $subtotal, $labor_total, $grand_total,
            $items_count, $status
        ]);
        
        $service_id = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'service_id' => $service_id,
            'message' => 'Service created successfully'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
}
?>