<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $station_id = 1253;
    $date = '2026-07-10';
    
    // Check what is in the purchase_orders table for this date
    $stmt = $pdo->prepare("
        SELECT id, created_at, type, status, admin_finalized, product_name, quantity, unit_price, total_amount 
        FROM purchase_orders 
        WHERE station_id = ? AND DATE(created_at) = ?
    ");
    $stmt->execute([$station_id, $date]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "--- Purchase Orders on $date for Station $station_id ---\n";
    print_r($items);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
