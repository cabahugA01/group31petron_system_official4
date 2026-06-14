-- ============================================================
-- COMPLETE Station-Dependent Module Configuration
-- database/complete_station_module_config.sql
-- All fields for Developer module configuration per station
-- ============================================================

-- ══════════════════════════════════════════════════════════════
-- TABLE 1: station_modules (Enable/Disable per Station)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    module_key VARCHAR(50) NOT NULL,
    is_enabled TINYINT(1) DEFAULT 1,
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
-- TABLE 2: station_fuel_config (Fuel Management Settings)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_fuel_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    fuel_type VARCHAR(50) NOT NULL COMMENT 'Diesel, Gasoline, Kerosene',
    official_price_per_liter DECIMAL(10,2) NOT NULL,
    tank_capacity DECIMAL(10,2) DEFAULT 0 COMMENT 'Maximum liters tank can hold',
    calibration_schedule_days INT DEFAULT 30 COMMENT 'How often to calibrate (days)',
    variance_tolerance_percent DECIMAL(5,2) DEFAULT 5.0 COMMENT 'Acceptable variance %',
    reconciliation_formula VARCHAR(255) DEFAULT '(present - previous - calibration) * price_per_liter',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    
    UNIQUE KEY unique_station_fuel (station_id, fuel_type),
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id),
    INDEX idx_station_active (station_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- TABLE 3: station_merchandise_config (Merchandise Settings)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_merchandise_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    category VARCHAR(100),
    unit_price DECIMAL(10,2) NOT NULL,
    stock_unit VARCHAR(50) COMMENT 'pcs, box, pack, etc',
    fifo_enabled TINYINT(1) DEFAULT 1 COMMENT 'First-In-First-Out inventory',
    low_stock_threshold INT DEFAULT 10,
    delivery_auto_update TINYINT(1) DEFAULT 1 COMMENT 'Auto update stock on delivery',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id),
    INDEX idx_station_active (station_id, is_active),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- TABLE 4: station_job_order_config (Job Orders Settings)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_job_order_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    service_type VARCHAR(100) NOT NULL COMMENT 'Maintenance, Repair, Calibration',
    default_workflow_status ENUM('Pending', 'In-progress', 'Completed') DEFAULT 'Pending',
    link_to_receivables TINYINT(1) DEFAULT 1 COMMENT 'Auto-link credit accounts',
    require_manager_approval TINYINT(1) DEFAULT 0,
    approval_threshold_amount DECIMAL(10,2) DEFAULT 5000,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    
    UNIQUE KEY unique_station_service (station_id, service_type),
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id),
    INDEX idx_station_active (station_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- TABLE 5: station_payment_config (Payment Handling Settings)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_payment_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    payment_method VARCHAR(50) NOT NULL COMMENT 'Cash, Card, E-Wallet, Fleet/E-Fuel, Credit',
    require_reference_number TINYINT(1) DEFAULT 0,
    allow_partial_payment TINYINT(1) DEFAULT 1,
    payment_status_default ENUM('Paid', 'Pending') DEFAULT 'Pending',
    audit_trail_enabled TINYINT(1) DEFAULT 1,
    is_enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    
    UNIQUE KEY unique_station_payment (station_id, payment_method),
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id),
    INDEX idx_station_enabled (station_id, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- TABLE 6: station_inventory_config (Inventory Settings)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_inventory_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    stock_movement_rule ENUM('FIFO', 'LIFO', 'FEFO') DEFAULT 'FIFO',
    auto_update_on_delivery TINYINT(1) DEFAULT 1,
    auto_update_on_sale TINYINT(1) DEFAULT 1,
    adjustment_require_approval TINYINT(1) DEFAULT 1,
    low_stock_alert_enabled TINYINT(1) DEFAULT 1,
    low_stock_threshold INT DEFAULT 10,
    audit_trail_enabled TINYINT(1) DEFAULT 1,
    allow_negative_stock TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    
    UNIQUE KEY unique_station_inventory (station_id),
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- TABLE 7: station_calendar_config (Calendar Settings)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_calendar_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    shift_schedule_enabled TINYINT(1) DEFAULT 1,
    delivery_schedule_enabled TINYINT(1) DEFAULT 1,
    calibration_events_enabled TINYINT(1) DEFAULT 1,
    system_notifications_enabled TINYINT(1) DEFAULT 1,
    default_shift_hours VARCHAR(50) DEFAULT '8:00 AM - 5:00 PM',
    notification_lead_time_hours INT DEFAULT 24 COMMENT 'Hours before event to notify',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    
    UNIQUE KEY unique_station_calendar (station_id),
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- TABLE 8: station_report_config (Reports Settings)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_report_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    report_type VARCHAR(100) NOT NULL COMMENT 'Sales, Variance, Compliance, etc.',
    computation_formula TEXT COMMENT 'Custom formulas for calculations',
    export_format_excel TINYINT(1) DEFAULT 1,
    export_format_pdf TINYINT(1) DEFAULT 1,
    role_access_staff TINYINT(1) DEFAULT 0,
    role_access_manager TINYINT(1) DEFAULT 1,
    role_access_admin TINYINT(1) DEFAULT 1,
    is_enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    
    UNIQUE KEY unique_station_report (station_id, report_type),
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id),
    INDEX idx_station_enabled (station_id, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ══════════════════════════════════════════════════════════════
-- TABLE 9: station_module_audit (Complete Audit Trail)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS station_module_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    module_key VARCHAR(50) NOT NULL,
    config_table VARCHAR(100) COMMENT 'Which config table was modified',
    action ENUM('enable', 'disable', 'configure', 'create', 'update', 'delete') NOT NULL,
    field_changed VARCHAR(100),
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
-- ══════════════════════════════════════════════════════════════

-- 1. Enable all modules for all active stations by default
INSERT INTO station_modules (station_id, module_key, is_enabled)
SELECT 
    s.id as station_id,
    m.module_key,
    1 as is_enabled
FROM stations s
CROSS JOIN (
    SELECT 'transactions' as module_key UNION ALL
    SELECT 'fuel_management' UNION ALL
    SELECT 'merchandise' UNION ALL
    SELECT 'job_orders' UNION ALL
    SELECT 'payments' UNION ALL
    SELECT 'inventory' UNION ALL
    SELECT 'calendar' UNION ALL
    SELECT 'reports'
) m
WHERE s.status = 'Active'
ON DUPLICATE KEY UPDATE is_enabled = is_enabled;


-- 2. Default fuel types for all stations
INSERT INTO station_fuel_config (station_id, fuel_type, official_price_per_liter, tank_capacity)
SELECT 
    s.id,
    ft.fuel_type,
    ft.default_price,
    10000 as tank_capacity
FROM stations s
CROSS JOIN (
    SELECT 'Diesel' as fuel_type, 65.50 as default_price UNION ALL
    SELECT 'Gasoline', 70.00 UNION ALL
    SELECT 'Kerosene', 55.00
) ft
WHERE s.status = 'Active'
ON DUPLICATE KEY UPDATE official_price_per_liter = official_price_per_liter;


-- 3. Default payment methods for all stations
INSERT INTO station_payment_config (station_id, payment_method, require_reference_number, is_enabled)
SELECT 
    s.id,
    pm.method,
    pm.needs_ref,
    1
FROM stations s
CROSS JOIN (
    SELECT 'Cash' as method, 0 as needs_ref UNION ALL
    SELECT 'Card', 1 UNION ALL
    SELECT 'E-Wallet', 1 UNION ALL
    SELECT 'Fleet/E-Fuel', 1 UNION ALL
    SELECT 'Credit', 0
) pm
WHERE s.status = 'Active'
ON DUPLICATE KEY UPDATE is_enabled = is_enabled;


-- 4. Default inventory config for all stations
INSERT INTO station_inventory_config (station_id, stock_movement_rule, auto_update_on_delivery, auto_update_on_sale)
SELECT 
    s.id,
    'FIFO',
    1,
    1
FROM stations s
WHERE s.status = 'Active'
ON DUPLICATE KEY UPDATE stock_movement_rule = stock_movement_rule;


-- 5. Default calendar config for all stations
INSERT INTO station_calendar_config (station_id)
SELECT s.id
FROM stations s
WHERE s.status = 'Active'
ON DUPLICATE KEY UPDATE shift_schedule_enabled = shift_schedule_enabled;


-- 6. Default report types for all stations
INSERT INTO station_report_config (station_id, report_type, role_access_manager, role_access_admin, is_enabled)
SELECT 
    s.id,
    rt.type,
    1,
    1,
    1
FROM stations s
CROSS JOIN (
    SELECT 'Sales Report' as type UNION ALL
    SELECT 'Variance Report' UNION ALL
    SELECT 'Compliance Report' UNION ALL
    SELECT 'Inventory Report' UNION ALL
    SELECT 'Transaction Summary'
) rt
WHERE s.status = 'Active'
ON DUPLICATE KEY UPDATE is_enabled = is_enabled;


-- ══════════════════════════════════════════════════════════════
-- VERIFICATION QUERIES
-- ══════════════════════════════════════════════════════════════

-- Check modules per station
SELECT 
    s.name,
    COUNT(sm.id) as total_modules,
    SUM(sm.is_enabled) as enabled_modules
FROM stations s
LEFT JOIN station_modules sm ON sm.station_id = s.id
GROUP BY s.id
ORDER BY s.name;

-- Check fuel config per station
SELECT 
    s.name,
    sfc.fuel_type,
    sfc.official_price_per_liter,
    sfc.tank_capacity,
    sfc.is_active
FROM stations s
INNER JOIN station_fuel_config sfc ON sfc.station_id = s.id
ORDER BY s.name, sfc.fuel_type;

-- Check payment methods per station
SELECT 
    s.name,
    COUNT(spc.id) as payment_methods
FROM stations s
LEFT JOIN station_payment_config spc ON spc.station_id = s.id
WHERE spc.is_enabled = 1
GROUP BY s.id
ORDER BY s.name;

