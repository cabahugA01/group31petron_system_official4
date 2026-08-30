<?php
require_once __DIR__ . '/../../../config/database_config.php';
require_once __DIR__ . '/../../../backend/lib.php';
require_once __DIR__ . '/../../../backend/security_helpers.php';
require_once __DIR__ . '/../../../public/db_connect.php';

// Authoritative Security Enforcement
$me = enforce_server_security(null, null, false);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields (station_id and user_id are supplied by server session)
    $required = ['vehicle_plate', 'vehicle_type', 'customer_name', 'items'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
            exit;
        }
    }
    
    // Server-side authoritative overrides: NEVER trust client-submitted station_id or user_id
    $station_id = (int)($me['station_id'] ?? 1);
    $user_id = (int)($me['id'] ?? 0);
    $user_name = $me['name'] ?? $me['username'] ?? '';

    // Calculate totals server-side
    $parts_total = 0.00;
    $labor_total = 0.00;
    $items = is_array($data['items']) ? $data['items'] : [];

    foreach ($items as $item) {
        $p_cost = floatval($item['parts_cost'] ?? $item['unitPrice'] ?? 0);
        $l_cost = floatval($item['labor_cost'] ?? $item['laborCost'] ?? 0);
        $parts_total += $p_cost;
        $labor_total += $l_cost;
    }
    $grand_total = round($parts_total + $labor_total, 2);
    $items_count = count($items);

    try {
        global $pdo;
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
            $station_id,
            $user_id,
            $user_name,
            $data['vehicle_plate'],
            $data['vehicle_type'],
            $data['customer_name'],
            $data['contact_number'] ?? '',
            $data['notes'] ?? '',
            $data['status'] ?? 'Pending',
            $data['priority'] ?? 'Normal',
            $parts_total,
            $labor_total,
            $grand_total,
            $items_count
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