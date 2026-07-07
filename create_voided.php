<?php
require_once __DIR__ . '/public/db_connect.php';

try {
    echo "Creating voided_transactions table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS voided_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY, transaction_id VARCHAR(50) NOT NULL,
        transaction_type ENUM('job_order','merchandise','combined') NOT NULL,
        customer_name VARCHAR(255) DEFAULT NULL, amount DECIMAL(10,2) NOT NULL,
        void_reason VARCHAR(255) NOT NULL, manager_remarks TEXT,
        voided_by INT NOT NULL, void_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        station_id INT NOT NULL,
        INDEX idx_vt_date (void_date), INDEX idx_vt_station (station_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "Adding columns to voided_transactions...\n";
    try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS fields_changed JSON DEFAULT NULL"); } catch(Exception $e2){}
    try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS merchandise_txn_id INT DEFAULT NULL"); } catch(Exception $e2){}
    try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS job_order_no VARCHAR(100) DEFAULT NULL"); } catch(Exception $e2){}
    try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS vehicle_plate VARCHAR(50) DEFAULT NULL"); } catch(Exception $e2){}
    try { $pdo->exec("ALTER TABLE voided_transactions ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT NULL"); } catch(Exception $e2){}

    echo "voided_transactions table ensured successfully!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
