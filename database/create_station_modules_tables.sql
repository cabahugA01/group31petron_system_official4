-- ============================================================
-- Station-Dependent Module Configuration
-- database/create_station_modules_tables.sql
-- Creates tables for per-station module configuration
-- ============================================================

-- ══════════════════════════════════════════════════════════════
-- TABLE 1: station_modules
-- Stores which modules are enabled/disabled per station
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    module_key VARCHAR(50) NOT NULL,
    is_enabled TINYINT(1) DEFAULT 1,
    configuration JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    
    UNIQUE KEY unique_station_module (station_id, module_key),
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id),
    INDEX idx_station_enabled (station_id, is_enabled),
    INDEX idx_module_key (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════
-- TABLE 2: station_module_audit
-- Audit trail for all module configuration changes
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_module_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    module_key VARCHAR(50) NOT NULL,
    action ENUM('enable', 'disable', 'configure') NOT NULL,
    old_value TEXT,
    new_value TEXT,
    developer_id INT NOT NULL,
    developer_name VARCHAR(100),
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (developer_id) REFERENCES users(id),
    INDEX idx_station_created (station_id, created_at DESC),
    INDEX idx_module_created (module_key, created_at DESC),
    INDEX idx_developer (developer_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ══════════════════════════════════════════════════════════════
-- POPULATE DEFAULT DATA
-- Add all 9 modules for all active stations (all enabled by default)
-- ══════════════════════════════════════════════════════════════

INSERT INTO station_modules (station_id, module_key, is_enabled)
SELECT 
    s.id as station_id,
    m.module_key,
    1 as is_enabled
FROM stations s
CROSS JOIN (
    SELECT 'transactions' as module_key UNION ALL
    SELECT 'fuel_management' UNION ALL
    SELECT 'inventory' UNION ALL
    SELECT 'job_orders' UNION ALL
    SELECT 'calendar' UNION ALL
    SELECT 'reports' UNION ALL
    SELECT 'customers' UNION ALL
    SELECT 'deliveries' UNION ALL
    SELECT 'purchase_orders'
) m
WHERE s.status = 'Active'
ON DUPLICATE KEY UPDATE is_enabled = is_enabled; -- Skip if already exists

-- ══════════════════════════════════════════════════════════════
-- VERIFICATION QUERIES
-- Run these to verify the setup
-- ══════════════════════════════════════════════════════════════

-- Check total records created
SELECT COUNT(*) as total_records FROM station_modules;

-- Check modules per station
SELECT 
    s.name as station_name,
    COUNT(sm.id) as total_modules,
    SUM(sm.is_enabled) as enabled_modules
FROM stations s
LEFT JOIN station_modules sm ON sm.station_id = s.id
GROUP BY s.id, s.name
ORDER BY s.name;

-- Check module distribution
SELECT 
    module_key,
    COUNT(*) as total_stations,
    SUM(is_enabled) as enabled_stations,
    SUM(CASE WHEN is_enabled = 0 THEN 1 ELSE 0 END) as disabled_stations
FROM station_modules
GROUP BY module_key
ORDER BY module_key;

-- Sample data from a specific station
SELECT 
    sm.module_key,
    sm.is_enabled,
    sm.updated_at
FROM station_modules sm
INNER JOIN stations s ON s.id = sm.station_id
WHERE s.id = (SELECT MIN(id) FROM stations WHERE status = 'Active')
ORDER BY sm.module_key;

-- ══════════════════════════════════════════════════════════════
-- HELPER QUERIES
-- Useful queries for management and troubleshooting
-- ══════════════════════════════════════════════════════════════

-- Find stations with specific module disabled
SELECT 
    s.id,
    s.name,
    s.region,
    sm.module_key,
    sm.is_enabled
FROM stations s
INNER JOIN station_modules sm ON sm.station_id = s.id
WHERE sm.module_key = 'fuel_management' 
  AND sm.is_enabled = 0;

-- Count modules per region
SELECT 
    s.region,
    sm.module_key,
    COUNT(*) as station_count,
    SUM(sm.is_enabled) as enabled_count
FROM stations s
INNER JOIN station_modules sm ON sm.station_id = s.id
GROUP BY s.region, sm.module_key
ORDER BY s.region, sm.module_key;

-- Recent audit trail (last 50 changes)
SELECT 
    sma.created_at,
    s.name as station_name,
    sma.module_key,
    sma.action,
    sma.developer_name,
    sma.old_value,
    sma.new_value
FROM station_module_audit sma
INNER JOIN stations s ON s.id = sma.station_id
ORDER BY sma.created_at DESC
LIMIT 50;

-- ══════════════════════════════════════════════════════════════
-- MAINTENANCE QUERIES
-- For bulk updates and maintenance
-- ══════════════════════════════════════════════════════════════

-- Enable a module for all stations
-- UPDATE station_modules SET is_enabled = 1 WHERE module_key = 'transactions';

-- Disable a module for all stations
-- UPDATE station_modules SET is_enabled = 0 WHERE module_key = 'purchase_orders';

-- Enable a module for specific region
-- UPDATE station_modules sm
-- INNER JOIN stations s ON s.id = sm.station_id
-- SET sm.is_enabled = 1
-- WHERE s.region = 'Region VII' AND sm.module_key = 'fuel_management';

-- Add a new module to all existing stations
-- INSERT INTO station_modules (station_id, module_key, is_enabled)
-- SELECT id, 'new_module_key', 1
-- FROM stations
-- WHERE status = 'Active';

-- ══════════════════════════════════════════════════════════════
-- NOTES
-- ══════════════════════════════════════════════════════════════

/*
Module Keys (9 total):
1. transactions - Point of Sale and transaction management
2. fuel_management - Fuel inventory, readings, reconciliation
3. inventory - Merchandise and fuel inventory management
4. job_orders - Service and maintenance job orders
5. calendar - Shift scheduling and calendar
6. reports - System reports and analytics
7. customers - Customer management and loyalty
8. deliveries - Fuel and merchandise delivery tracking
9. purchase_orders - Purchase order workflow

Configuration JSON Examples:
- {"allow_credit": true, "max_credit_limit": 50000}
- {"fifo_enabled": true, "low_stock_threshold": 10}
- {"auto_approve_below": 5000}

Audit Actions:
- enable: Module was enabled for station
- disable: Module was disabled for station
- configure: Module configuration was updated
*/
