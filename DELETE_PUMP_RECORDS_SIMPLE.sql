-- ============================================================
-- SIMPLE SQL TO DELETE ALL PUMP RECORDS
-- Copy and paste this entire file into phpMyAdmin SQL tab
-- ============================================================

-- Disable foreign key checks
SET FOREIGN_KEY_CHECKS = 0;

-- Delete all pump records
DELETE FROM fuel_pumps;

-- Delete calibration history
DELETE FROM pump_calibration_history;

-- Delete calibration records
DELETE FROM fuel_calibration_records;

-- Delete calibration logs
DELETE FROM calibration_logs;

-- Delete pump configuration
DELETE FROM pump_configuration;

-- Reset AUTO_INCREMENT
ALTER TABLE fuel_pumps AUTO_INCREMENT = 1;
ALTER TABLE pump_calibration_history AUTO_INCREMENT = 1;
ALTER TABLE fuel_calibration_records AUTO_INCREMENT = 1;
ALTER TABLE calibration_logs AUTO_INCREMENT = 1;
ALTER TABLE pump_configuration AUTO_INCREMENT = 1;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Show results
SELECT 'DELETED!' as Status;
SELECT COUNT(*) as fuel_pumps_count FROM fuel_pumps;
SELECT COUNT(*) as calibration_history_count FROM pump_calibration_history;
