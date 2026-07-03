-- Set calibration values for ALL 17 pumps with July 2, 2026 date
-- Run this in phpMyAdmin or MySQL command line

-- Get the user ID (Edgar Eslit)
SET @user_id = (SELECT id FROM users WHERE username = 'edgar' OR CONCAT(first_name, ' ', last_name) = 'Edgar Eslit' LIMIT 1);

-- Update all pumps with their calibration values
UPDATE fuel_pumps SET calibration_value = 10.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'DIESEL 1 - 1';
UPDATE fuel_pumps SET calibration_value = 10.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'DIESEL 1 - 2';
UPDATE fuel_pumps SET calibration_value = 5.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'DIESEL 1 - 3';
UPDATE fuel_pumps SET calibration_value = 5.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'DIESEL 1 - 4';
UPDATE fuel_pumps SET calibration_value = 100.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'DIESEL 2 - 5';
UPDATE fuel_pumps SET calibration_value = 100.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'DIESEL 2 - 6';
UPDATE fuel_pumps SET calibration_value = 8.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'KEROSENE - 1';
UPDATE fuel_pumps SET calibration_value = 12.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'TURBO DIESEL - 1';
UPDATE fuel_pumps SET calibration_value = 12.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'TURBO DIESEL - 2';
UPDATE fuel_pumps SET calibration_value = 15.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'XCS PLUS - 1';
UPDATE fuel_pumps SET calibration_value = 15.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'XCS PLUS - 2';
UPDATE fuel_pumps SET calibration_value = 15.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'XCS PLUS - 3';
UPDATE fuel_pumps SET calibration_value = 15.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'XCS PLUS - 4';
UPDATE fuel_pumps SET calibration_value = 20.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'XTRA UNL 1 - 1';
UPDATE fuel_pumps SET calibration_value = 20.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'XTRA UNL 1 - 2';
UPDATE fuel_pumps SET calibration_value = 20.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'XTRA UNL 2 - 3';
UPDATE fuel_pumps SET calibration_value = 20.00, calibration_updated_at = '2026-07-02 08:00:00', calibration_updated_by = @user_id, calibration_notes = 'Initial calibration setup' WHERE pump_number = 'XTRA UNL 2 - 4';

-- Verify the updates
SELECT id, station_id, pump_number, calibration_value, calibration_updated_at, calibration_updated_by, status
FROM fuel_pumps 
ORDER BY station_id, pump_number;
