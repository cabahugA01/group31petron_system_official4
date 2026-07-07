<?php
require_once __DIR__ . '/../db_connect.php';

$station_id = $_GET['station_id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        SELECT id, product_name, stock_level, selling_price
        FROM inventory 
        WHERE station_id = ? 
          AND stock_level > 0
          AND type = 'merch'
        ORDER BY product_name
    ");
    $stmt->execute([$station_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($items);
} catch (Exception $e) {
    echo json_encode([]);
}
?>