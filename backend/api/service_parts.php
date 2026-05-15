<?php
// API endpoint to get parts for a specific service type
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json');

try {
    $pdo = $GLOBALS['pdo'];
    
    // Get service type from request
    $service_key = $_GET['service_key'] ?? '';
    $service_name = $_GET['service_name'] ?? '';
    
    if (empty($service_key) && empty($service_name)) {
        echo json_encode([
            'success' => false,
            'error' => 'Service key or name is required'
        ]);
        exit;
    }
    
    // Query parts for the service type
    if (!empty($service_key)) {
        $stmt = $pdo->prepare("
            SELECT part_name, part_category, default_quantity, default_unit_price, is_required, sort_order
            FROM service_parts_mapping 
            WHERE service_key = ? 
            ORDER BY sort_order, part_name
        ");
        $stmt->execute([$service_key]);
    } else {
        $stmt = $pdo->prepare("
            SELECT part_name, part_category, default_quantity, default_unit_price, is_required, sort_order
            FROM service_parts_mapping 
            WHERE service_name = ? 
            ORDER BY sort_order, part_name
        ");
        $stmt->execute([$service_name]);
    }
    
    $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format parts for frontend
    $formatted_parts = [];
    foreach ($parts as $part) {
        $formatted_parts[] = [
            'part_id' => 'part_' . $part['sort_order'],
            'part_name' => $part['part_name'],
            'category' => $part['part_category'],
            'default_quantity' => (int)$part['default_quantity'],
            'unit_cost' => (float)$part['default_unit_price'],
            'is_required' => (bool)$part['is_required'],
            'sort_order' => (int)$part['sort_order']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $formatted_parts,
        'service_key' => $service_key,
        'service_name' => $service_name,
        'parts_count' => count($formatted_parts)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
