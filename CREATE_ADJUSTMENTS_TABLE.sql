-- Transaction Adjustments Table
-- Run this SQL in your database before using the Transaction Adjustments page

CREATE TABLE IF NOT EXISTS transaction_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(50) NOT NULL COMMENT 'Original transaction reference',
    transaction_type ENUM('job_order', 'merchandise', 'combined') NOT NULL,
    customer_name VARCHAR(255) DEFAULT NULL,
    original_amount DECIMAL(10,2) NOT NULL,
    updated_amount DECIMAL(10,2) NOT NULL,
    amount_difference DECIMAL(10,2) NOT NULL COMMENT 'updated - original',
    adjustment_reason VARCHAR(255) NOT NULL,
    manager_remarks TEXT,
    adjusted_by INT NOT NULL COMMENT 'user_id of manager who made adjustment',
    adjustment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    station_id INT NOT NULL,
    fields_changed JSON COMMENT 'Track which fields were modified',
    
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_adjustment_date (adjustment_date),
    INDEX idx_station_id (station_id),
    INDEX idx_adjusted_by (adjusted_by),
    
    FOREIGN KEY (adjusted_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores transaction adjustment history for audit trail';
