<?php
require_once '../db_connect.php';
header('Content-Type: application/json');

$service_id = $_GET['service_id'] ?? '';

try {
    $stmt = $pdo->prepare("
        SELECT item_name as service_name, description, 
               unit_price as parts_cost, labor_cost, total_cost,
               created_at
        FROM service_items 
        WHERE service_entry_id = ?
        ORDER BY created_at DESC
    ");
    
    $stmt->execute([$service_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'items' => $items
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>