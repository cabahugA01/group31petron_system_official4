-- ═══════════════════════════════════════════════════════════════════════════════
-- FIX DATABASE ENGINE ISSUE
-- ═══════════════════════════════════════════════════════════════════════════════
--
-- PROBLEM: Tables are using MEMORY engine and disappear on MySQL restart
-- ERROR: Table 'petron_pos_db_secure.users' doesn't exist in engine
-- 
-- SOLUTION: Convert all tables back to InnoDB (persistent storage)
--
-- ═══════════════════════════════════════════════════════════════════════════════

USE petron_pos_db_secure;

-- First, check if tables are in MEMORY engine
SELECT TABLE_NAME, ENGINE 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'petron_pos_db_secure'
ORDER BY TABLE_NAME;

-- ═══════════════════════════════════════════════════════════════════════════════
-- CONVERT ALL TABLES TO InnoDB
-- ═══════════════════════════════════════════════════════════════════════════════

-- Core tables
ALTER TABLE users ENGINE=InnoDB;
ALTER TABLE stations ENGINE=InnoDB;
ALTER TABLE customers ENGINE=InnoDB;

-- Transaction tables
ALTER TABLE merchandise_transactions ENGINE=InnoDB;
ALTER TABLE job_orders ENGINE=InnoDB;
ALTER TABLE fuel_transactions ENGINE=InnoDB;

-- Inventory tables
ALTER TABLE inventory ENGINE=InnoDB;
ALTER TABLE inventory_history ENGINE=InnoDB;

-- Service tables
ALTER TABLE services ENGINE=InnoDB;
ALTER TABLE service_categories ENGINE=InnoDB;

-- Shift & Labor tables
ALTER TABLE shift_periods ENGINE=InnoDB;
ALTER TABLE labor_sessions ENGINE=InnoDB;

-- Logging tables
ALTER TABLE activity_logs ENGINE=InnoDB;
ALTER TABLE audit_logs ENGINE=InnoDB;
ALTER TABLE login_attempts ENGINE=InnoDB;

-- Configuration tables
ALTER TABLE system_config ENGINE=InnoDB;
ALTER TABLE roles ENGINE=InnoDB;
ALTER TABLE role_permissions ENGINE=InnoDB;

-- Pending transaction tables
ALTER TABLE pending_merchandise_transactions ENGINE=InnoDB;
ALTER TABLE pending_fuel_transactions ENGINE=InnoDB;

-- Validation tables
ALTER TABLE validation_actions_log ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════════════════
-- VERIFY CONVERSION
-- ═══════════════════════════════════════════════════════════════════════════════

SELECT 
    TABLE_NAME, 
    ENGINE,
    TABLE_ROWS,
    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS "Size (MB)"
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'petron_pos_db_secure'
ORDER BY TABLE_NAME;

-- ═══════════════════════════════════════════════════════════════════════════════
-- EXPECTED RESULT: All tables should show ENGINE = 'InnoDB'
-- ═══════════════════════════════════════════════════════════════════════════════

-- If you get errors like "Table doesn't exist", the data is LOST!
-- You'll need to restore from backup:
-- mysql -u root petron_pos_db_secure < database/petron_pos_db_secure.sql

-- ═══════════════════════════════════════════════════════════════════════════════
