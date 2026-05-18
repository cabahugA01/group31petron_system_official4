<?php
require 'public/db_connect.php';
try {
    $stmt = $pdo->query("SELECT id, unit_cost, unit_price FROM inventory_products WHERE category != 'Fuel' ORDER BY category, product_name LIMIT 5");
    $merchs = $stmt->fetchAll();
    foreach ($merchs as $merch) {
        // check if already pending
        $check = $pdo->prepare("SELECT id FROM pending_price_approvals WHERE product_id = ? AND product_type='merchandise' AND station_id=1253");
        $check->execute([$merch['id']]);
        if (!$check->fetch()) {
            $stmt2 = $pdo->prepare("INSERT INTO pending_price_approvals (station_id, product_type, product_id, old_cost, new_cost, old_price, new_price, manager_id, status) VALUES (1253, 'merchandise', ?, ?, ?, ?, ?, 1, 'pending')");
            $stmt2->execute([$merch['id'], $merch['unit_cost'], $merch['unit_cost'] + 5, $merch['unit_price'], $merch['unit_price'] + 15]);
        }
    }
    echo "Done";
} catch (Exception $e) {
    echo $e->getMessage();
}
