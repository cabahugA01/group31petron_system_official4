<?php
require 'public/db_connect.php';

try {
    $pdo->exec("ALTER TABLE pending_price_approvals MODIFY COLUMN product_type ENUM('fuel', 'fuel_inventory', 'merchandise') NOT NULL");
    
    // update the broken one
    $pdo->exec("UPDATE pending_price_approvals SET product_type = 'fuel_inventory' WHERE id = 1");

    echo "Enum altered and mock data fixed.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
