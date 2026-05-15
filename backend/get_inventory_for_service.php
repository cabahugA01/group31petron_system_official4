<?php
require_once __DIR__ . '/../public/db_connect.php';

$station_id = $_GET['station_id'] ?? 0;

try {
    // Get merchandise inventory for service job order parts
    // Note: Only merchandise (type_id=2), never fuel (type_id=1)
    $stmt = $pdo->prepare("
        SELECT si.id, p.name as product_name, si.stock_level, p.price as selling_price, p.id as product_id
        FROM station_inventory si
        JOIN products p ON si.product_id = p.id
        WHERE si.station_id = ? 
          AND si.stock_level > 0
          AND p.type_id = 2
        ORDER BY p.name
    ");
    $stmt->execute([$station_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($items);
} catch (Exception $e) {
    echo json_encode([]);
}
?>