<?php
require_once __DIR__ . '/public/db_connect.php';

try {
    // Get first product
    $stmt = $pdo->query("SELECT id, unit_cost, unit_price FROM inventory_products LIMIT 1");
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($prod) {
        $id = $prod['id'];
        $old_cost = $prod['unit_cost'];
        $old_price = $prod['unit_price'];
        $new_cost = $old_cost + 10;
        $new_price = $old_price + 20;
        
        $details = "PROPOSED: Product ID $id | Old Cost: $old_cost → New Cost: $new_cost | Old Price: $old_price → New Price: $new_price";
        
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (17, 'Propose Price', ?, '127.0.0.1', NOW())");
        $stmt->execute([$details]);
        
        echo "SUCCESS: Created proposal for Product ID $id";
    } else {
        echo "ERROR: No products found in inventory_products table";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
