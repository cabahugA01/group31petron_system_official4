<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    $pdo->exec("ALTER TABLE stock_requests MODIFY COLUMN status ENUM('Pending','Approved','Validated','Rejected') DEFAULT 'Pending'");
    echo "Successfully updated stock_requests status enum!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
