-- Master Data Requests Table
-- For Vehicle Type and Service Type requests from staff

CREATE TABLE IF NOT EXISTS master_data_requests (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    request_type        ENUM('vehicle_type', 'service_type', 'product') NOT NULL,
    request_data        JSON NOT NULL COMMENT 'Stores all request fields as JSON',
    
    -- Request metadata
    requested_by        INT NOT NULL COMMENT 'User ID who submitted',
    station_id          INT NULL COMMENT 'Station context if applicable',
    request_reason      TEXT NULL COMMENT 'Why staff needs this added',
    
    -- Status tracking
    status              ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by         INT NULL COMMENT 'Manager/Admin who reviewed',
    review_note         VARCHAR(500) NULL COMMENT 'Feedback from reviewer',
    
    -- Timestamps
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at         DATETIME NULL,
    
    -- Indexes
    INDEX idx_status (status),
    INDEX idx_request_type (request_type),
    INDEX idx_requested_by (requested_by),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
