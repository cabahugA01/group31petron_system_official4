-- ============================================================
-- EMPTY/TRUNCATE ALL PUMP-RELATED TABLES
-- Purpose: Clear all pump data to allow fresh pump data input
-- Date: June 27, 2026
-- ============================================================

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Truncate main fuel_pumps table
TRUNCATE TABLE `fuel_pumps`;

-- 2. Truncate pump calibration history
TRUNCATE TABLE `pump_calibration_history`;

-- 3. Truncate fuel calibration records
TRUNCATE TABLE `fuel_calibration_records`;

-- 4. Truncate calibration logs (legacy)
TRUNCATE TABLE `calibration_logs`;

-- 5. Truncate pump configuration
TRUNCATE TABLE `pump_configuration`;

-- 6. Optional: Clear fuel_calibration defaults (uncomment if needed)
-- TRUNCATE TABLE `fuel_calibration`;

-- 7. Optional: Clear fuel_calibration_defaults (uncomment if needed)
-- TRUNCATE TABLE `fuel_calibration_defaults`;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Verify tables are empty
SELECT 'fuel_pumps' as table_name, COUNT(*) as record_count FROM fuel_pumps
UNION ALL
SELECT 'pump_calibration_history', COUNT(*) FROM pump_calibration_history
UNION ALL
SELECT 'fuel_calibration_records', COUNT(*) FROM fuel_calibration_records
UNION ALL
SELECT 'calibration_logs', COUNT(*) FROM calibration_logs
UNION ALL
SELECT 'pump_configuration', COUNT(*) FROM pump_configuration;

-- ============================================================
-- COMPLETION MESSAGE
-- ============================================================
SELECT 'All pump tables have been emptied successfully!' as STATUS;
