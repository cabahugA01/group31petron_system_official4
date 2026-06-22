-- ============================================================================
-- Clear fuel-management encoded/recorded data for a clean operational start.
--
-- Preserved setup/master data:
--   users, stations, fuel_types, fuel_pumps, pump_configuration, fuel_config,
--   fuel_management_config, station_fuel_config, fuel_calibration_defaults,
--   fuel_suppliers.
--
-- Cleared/reset data:
--   fuel transactions, readings, deliveries, stock-in records, reconciliations,
--   adjustments, purchase orders, batches, stock requests, audit/history rows,
--   pricing history, and current inventory/calibration state.
-- ============================================================================

START TRANSACTION;

DELETE val
FROM validation_actions_log val
JOIN fuel_transactions ft ON val.transaction_id = ft.id;

DELETE FROM fuel_stock_request_audit;
DELETE FROM fuel_audit_trail;
DELETE FROM fuel_transaction_audit;
DELETE FROM fuel_variance_reports;
DELETE FROM fuel_reconciliation;
DELETE FROM fuel_sales_summary;
DELETE FROM fuel_stock_in;
DELETE FROM fuel_deliveries;
DELETE FROM fuel_batches;
DELETE FROM fuel_adjustments;
DELETE FROM fuel_purchase_orders;
DELETE FROM fuel_price_log;
DELETE FROM fuel_pricing;
DELETE FROM fuel_calibration_records;
DELETE FROM pump_calibration_history;
DELETE FROM fuel_stock_requests;
DELETE FROM fuel_readings;
DELETE FROM fuel_daily_readings;
DELETE FROM fuel_transactions;
DELETE FROM fuel_calibration;
DELETE FROM low_stock_alerts;

DELETE FROM activity_logs
WHERE LOWER(action) LIKE '%fuel%'
   OR LOWER(action) LIKE '%pump%'
   OR LOWER(action) LIKE '%reading%'
   OR LOWER(action) LIKE '%calibration%'
   OR LOWER(details) LIKE '%fuel%'
   OR LOWER(details) LIKE '%pump%'
   OR LOWER(details) LIKE '%reading%'
   OR LOWER(details) LIKE '%calibration%';

DELETE FROM audit_trail
WHERE source_table IN (
        'fuel_transactions',
        'fuel_readings',
        'fuel_daily_readings',
        'fuel_deliveries',
        'fuel_stock_in',
        'fuel_reconciliation',
        'fuel_adjustments',
        'fuel_purchase_orders'
    )
   OR LOWER(action_type) LIKE '%fuel%'
   OR LOWER(action_type) LIKE '%pump%'
   OR LOWER(action_type) LIKE '%reading%'
   OR LOWER(action_type) LIKE '%calibration%';

UPDATE fuel_inventory
   SET current_stock = 0,
       current_level = 0,
       price_per_liter = 0,
       latest_calibration = 0,
       calibration_date = NULL,
       calibration_staff = NULL,
       status = 'Normal',
       updated_by = NULL,
       last_updated = CURRENT_TIMESTAMP;

UPDATE fuel_pumps
   SET calibration_value = 0,
       calibration_updated_by = NULL,
       calibration_updated_at = NULL,
       calibration_notes = NULL;

COMMIT;

ALTER TABLE fuel_stock_request_audit AUTO_INCREMENT = 1;
ALTER TABLE fuel_audit_trail AUTO_INCREMENT = 1;
ALTER TABLE fuel_transaction_audit AUTO_INCREMENT = 1;
ALTER TABLE fuel_variance_reports AUTO_INCREMENT = 1;
ALTER TABLE fuel_reconciliation AUTO_INCREMENT = 1;
ALTER TABLE fuel_sales_summary AUTO_INCREMENT = 1;
ALTER TABLE fuel_stock_in AUTO_INCREMENT = 1;
ALTER TABLE fuel_deliveries AUTO_INCREMENT = 1;
ALTER TABLE fuel_batches AUTO_INCREMENT = 1;
ALTER TABLE fuel_adjustments AUTO_INCREMENT = 1;
ALTER TABLE fuel_purchase_orders AUTO_INCREMENT = 1;
ALTER TABLE fuel_price_log AUTO_INCREMENT = 1;
ALTER TABLE fuel_pricing AUTO_INCREMENT = 1;
ALTER TABLE fuel_calibration_records AUTO_INCREMENT = 1;
ALTER TABLE pump_calibration_history AUTO_INCREMENT = 1;
ALTER TABLE fuel_stock_requests AUTO_INCREMENT = 1;
ALTER TABLE fuel_readings AUTO_INCREMENT = 1;
ALTER TABLE fuel_daily_readings AUTO_INCREMENT = 1;
ALTER TABLE fuel_transactions AUTO_INCREMENT = 1;
ALTER TABLE fuel_calibration AUTO_INCREMENT = 1;
ALTER TABLE low_stock_alerts AUTO_INCREMENT = 1;
