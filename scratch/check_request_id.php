<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    echo "--- Tables containing request or purchase ---\n";
    $tables = $pdo->query("SHOW TABLES LIKE '%request%'")->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables);
    
    $tables2 = $pdo->query("SHOW TABLES LIKE '%purchase%'")->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables2);

    // Let's check purchase_orders with id = 17 and find out what is in request_id
    $stmt = $pdo->query("SELECT * FROM purchase_orders WHERE id = 17");
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\n--- purchase_orders row 17 ---\n";
    print_r($po);
    
    // If request_id is present, check where it points
    if ($po && $po['request_id']) {
        echo "\n--- Checking database for request_id = " . $po['request_id'] . " ---\n";
        // Check in stock_requests
        $tables_to_check = ['stock_requests', 'purchase_requests', 'request_items', 'merchandise_requests', 'fuel_purchase_requests'];
        foreach ($tables_to_check as $tbl) {
            try {
                $check = $pdo->prepare("SELECT * FROM `$tbl` WHERE id = ?");
                $check->execute([$po['request_id']]);
                $row = $check->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    echo "Found in table `$tbl`:\n";
                    print_r($row);
                }
            } catch (Exception $e) {
                // table might not exist
            }
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
