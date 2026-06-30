-- ═══════════════════════════════════════════════════════════════════════════════
-- DATABASE STATUS CHECK
-- ═══════════════════════════════════════════════════════════════════════════════

USE petron_pos_db_secure;

-- 1. Check if database exists and is accessible
SELECT DATABASE() AS current_database;

-- 2. List all tables and their engines
SELECT 
    TABLE_NAME, 
    ENGINE,
    TABLE_ROWS,
    CREATE_TIME,
    UPDATE_TIME
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'petron_pos_db_secure'
ORDER BY TABLE_NAME;

-- 3. Check if critical tables exist and have data
SELECT 'users' AS table_name, COUNT(*) AS row_count FROM users
UNION ALL
SELECT 'customers', COUNT(*) FROM customers
UNION ALL
SELECT 'stations', COUNT(*) FROM stations
UNION ALL
SELECT 'merchandise_transactions', COUNT(*) FROM merchandise_transactions
UNION ALL
SELECT 'job_orders', COUNT(*) FROM job_orders
UNION ALL
SELECT 'fuel_transactions', COUNT(*) FROM fuel_transactions;

-- 4. Check for MEMORY engine tables (these will disappear on restart!)
SELECT 
    TABLE_NAME, 
    ENGINE
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'petron_pos_db_secure'
  AND ENGINE = 'MEMORY'
ORDER BY TABLE_NAME;

-- ═══════════════════════════════════════════════════════════════════════════════
-- INTERPRETATION:
-- ═══════════════════════════════════════════════════════════════════════════════
--
-- If ENGINE = 'MEMORY':
--   ❌ PROBLEM! Table data is lost on MySQL restart
--   ✅ FIX: Run FIX_DATABASE_ENGINE.sql to convert to InnoDB
--
-- If ENGINE = 'InnoDB':
--   ✅ GOOD! Data is persistent
--
-- If TABLE_ROWS = 0 for critical tables:
--   ❌ PROBLEM! Data was lost
--   ✅ FIX: Restore from backup file
--
-- If "Table doesn't exist" error:
--   ❌ CRITICAL! Database is corrupted
--   ✅ FIX: Drop database and restore from backup
--
-- ═══════════════════════════════════════════════════════════════════════════════
