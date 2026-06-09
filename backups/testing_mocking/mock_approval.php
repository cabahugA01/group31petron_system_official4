<?php
require 'public/db_connect.php';

try {
    // Insert mock pending approval for Fuel
    $pdo->exec("INSERT INTO pending_price_approvals (station_id, product_type, product_id, old_cost, new_cost, old_price, new_price, manager_id, status) VALUES (1, 'fuel_inventory', 1, 85.00, 85.00, 85.00, 88.50, 1, 'pending')");
    
    // Check if there's merchandise to add a mock for
    $stmt = $pdo->query("SELECT id, unit_cost, unit_price FROM inventory_products WHERE category != 'Fuel' LIMIT 1");
    $merch = $stmt->fetch();
    if ($merch) {
        $stmt2 = $pdo->prepare("INSERT INTO pending_price_approvals (station_id, product_type, product_id, old_cost, new_cost, old_price, new_price, manager_id, status) VALUES (1, 'merchandise', ?, ?, ?, ?, ?, 1, 'pending')");
        $stmt2->execute([$merch['id'], $merch['unit_cost'], $merch['unit_cost'] + 10, $merch['unit_price'], $merch['unit_price'] + 20]);
    }

    echo "Mock data inserted.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
