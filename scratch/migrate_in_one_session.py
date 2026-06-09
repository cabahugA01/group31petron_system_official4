import subprocess, sys
sys.stdout.reconfigure(encoding='utf-8')

mysql = r"C:\xampp\mysql\bin\mysql.exe"
DB = "petron_pos_db_secure"

sql_commands = """
SET FOREIGN_KEY_CHECKS=0;

-- 1. Update fuel_types names
UPDATE fuel_types SET name = 'XCS Plus', description = 'Petron Fuel: XCS Plus' WHERE id = 12;
UPDATE fuel_types SET name = 'XTRA UNL', description = 'Petron Fuel: XTRA UNL' WHERE id = 13;

-- 2. Delete E10 (15) and Blaze 100 (16) records from referencing tables first
DELETE FROM fuel_inventory WHERE fuel_type_id IN (15, 16) OR fuel_type IN ('Petron E10', 'Petron Blaze 100');
DELETE FROM fuel_pumps WHERE fuel_type_id IN (15, 16);
DELETE FROM fuel_adjustments WHERE fuel_type_id IN (15, 16) OR fuel_type IN ('Petron E10', 'Petron Blaze 100');
DELETE FROM low_stock_alerts WHERE fuel_type_id IN (15, 16) OR fuel_type IN ('Petron E10', 'Petron Blaze 100');

-- 3. Delete from fuel_types
DELETE FROM fuel_types WHERE id IN (15, 16);

-- 4. Map station 1253 pumps to correct IDs
UPDATE fuel_pumps SET fuel_type_id = 12 WHERE station_id = 1253 AND pump_number = '1';
UPDATE fuel_pumps SET fuel_type_id = 11 WHERE station_id = 1253 AND pump_number = '2';
UPDATE fuel_pumps SET fuel_type_id = 10 WHERE station_id = 1253 AND pump_number = '3';
UPDATE fuel_pumps SET fuel_type_id = 14 WHERE station_id = 1253 AND pump_number = '4';
UPDATE fuel_pumps SET fuel_type_id = 13 WHERE station_id = 1253 AND pump_number = '5';

-- Map station 1250 pumps to correct IDs
UPDATE fuel_pumps SET fuel_type_id = 11 WHERE station_id = 1250 AND pump_number = 'Pump 1';
UPDATE fuel_pumps SET fuel_type_id = 10 WHERE station_id = 1250 AND pump_number = 'Pump 2';
UPDATE fuel_pumps SET fuel_type_id = 12 WHERE station_id = 1250 AND pump_number = 'Pump 3';

-- Map station 1268 pumps to correct IDs
UPDATE fuel_pumps SET fuel_type_id = 10 WHERE station_id = 1268;

-- 5. Update fuel_type names in other tables (excluding views like station_pump_assignment/fuel_stations)
UPDATE `accounts_receivable` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `accounts_receivable` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `accounts_receivable` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `accounts_receivable` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `calibration_logs` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `calibration_logs` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `calibration_logs` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `calibration_logs` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_adjustments` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_adjustments` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_adjustments` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_adjustments` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_calibration_defaults` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_calibration_defaults` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_calibration_defaults` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_calibration_defaults` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_calibration_records` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_calibration_records` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_calibration_records` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_calibration_records` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_daily_readings` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_daily_readings` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_daily_readings` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_daily_readings` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_deliveries` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_deliveries` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_deliveries` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_deliveries` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_readings` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_readings` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_readings` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_readings` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_reconciliation` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_reconciliation` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_reconciliation` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_reconciliation` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_sales_summary` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_sales_summary` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_sales_summary` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_sales_summary` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_stock_requests` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_stock_requests` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_stock_requests` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_stock_requests` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_transactions` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_transactions` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_transactions` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
UPDATE `fuel_transactions` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS Gold';
DELETE FROM `fuel_transactions` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_transaction_audit` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_transaction_audit` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_transaction_audit` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_transaction_audit` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `fuel_variance_reports` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `fuel_variance_reports` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `fuel_variance_reports` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `fuel_variance_reports` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `low_stock_alerts` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `low_stock_alerts` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `low_stock_alerts` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `low_stock_alerts` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

UPDATE `pump_configuration` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';
UPDATE `pump_configuration` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';
UPDATE `pump_configuration` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';
DELETE FROM `pump_configuration` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');

SET FOREIGN_KEY_CHECKS=1;
"""

r = subprocess.run([mysql, "-u", "root", DB], input=sql_commands.encode('utf-8'), capture_output=True)
out = r.stdout.decode('utf-8', errors='replace').strip()
err = r.stderr.decode('utf-8', errors='replace').strip()

if r.returncode != 0:
    print(f"ERR:\n{err}")
else:
    print("Success! Database changes applied in a single session.")
