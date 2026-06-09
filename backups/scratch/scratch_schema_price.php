<?php
require 'public/db_connect.php';

$sql = "
CREATE TABLE IF NOT EXISTS pending_price_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    product_type ENUM('fuel', 'merchandise') NOT NULL,
    product_id INT NOT NULL,
    old_cost DECIMAL(12,4) DEFAULT 0,
    new_cost DECIMAL(12,4) DEFAULT 0,
    old_price DECIMAL(12,4) DEFAULT 0,
    new_price DECIMAL(12,4) DEFAULT 0,
    manager_id INT NOT NULL,
    admin_id INT DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    remarks TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_station_status (station_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    $pdo->exec($sql);
    echo "Table created successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
