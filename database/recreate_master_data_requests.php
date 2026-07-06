<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $pdo->exec("DROP TABLE IF EXISTS master_data_requests");
    echo "Dropped old master_data_requests table.\n";

    $pdo->exec("CREATE TABLE master_data_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_no VARCHAR(50) UNIQUE,
        category ENUM('Vehicle', 'Merchandise Product', 'Service Type') NOT NULL,
        source_module VARCHAR(100) NOT NULL,
        requested_by INT NOT NULL,
        station_id INT NULL,
        status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
        data_payload LONGTEXT NOT NULL,
        rejection_reason TEXT NULL,
        reviewed_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_category (category),
        INDEX idx_status (status),
        INDEX idx_requested_by (requested_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Created new master_data_requests table.\n";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
