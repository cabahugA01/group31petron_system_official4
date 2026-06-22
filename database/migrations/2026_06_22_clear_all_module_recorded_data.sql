-- ============================================================================
-- Clear all encoded/recorded operational data across modules.
--
-- Preserved master/setup data:
--   users, stations, fuel_types, fuel_pumps, pump_configuration,
--   products, product_types, product_categories, categories,
--   inventory_products, station_items, job_order_service_types,
--   service_categories, service_fees, service_rates, service parts mappings,
--   vehicle_types, mechanics, suppliers, fuel_suppliers, module/config tables.
--
-- Cleared data:
--   transactions, sales, job orders, service entries, customers and ledgers,
--   deliveries, purchase orders, receiving/stock requests, operational logs,
--   notifications, calendar events, sessions, audit trails, variances,
--   reconciliations, and recorded stock movement/history.
--
-- Product rows are not deleted. Stock/quantity/current-balance fields are reset
-- so new data can start clean.
-- ============================================================================

DROP PROCEDURE IF EXISTS delete_table_if_exists;
DROP PROCEDURE IF EXISTS reset_ai_if_exists;

DELIMITER $$

CREATE PROCEDURE delete_table_if_exists(IN p_table VARCHAR(64))
BEGIN
    DECLARE v_exists INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_exists
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = p_table
       AND table_type = 'BASE TABLE';

    IF v_exists = 1 THEN
        SET @delete_sql = CONCAT('DELETE FROM `', p_table, '`');
        PREPARE delete_stmt FROM @delete_sql;
        EXECUTE delete_stmt;
        DEALLOCATE PREPARE delete_stmt;
    END IF;
END$$

CREATE PROCEDURE reset_ai_if_exists(IN p_table VARCHAR(64))
BEGIN
    DECLARE v_exists INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_exists
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = p_table
       AND extra LIKE '%auto_increment%';

    IF v_exists > 0 THEN
        SET @ai_sql = CONCAT('ALTER TABLE `', p_table, '` AUTO_INCREMENT = 1');
        PREPARE ai_stmt FROM @ai_sql;
        EXECUTE ai_stmt;
        DEALLOCATE PREPARE ai_stmt;
    END IF;
END$$

DELIMITER ;

SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

-- Access, audit, activity, and system/user logs.
CALL delete_table_if_exists('access_violations_log');
CALL delete_table_if_exists('activity_logs');
CALL delete_table_if_exists('audit_log');
CALL delete_table_if_exists('audit_logs');
CALL delete_table_if_exists('audit_trail');
CALL delete_table_if_exists('backup_logs');
CALL delete_table_if_exists('calibration_logs');
CALL delete_table_if_exists('code_changes_audit');
CALL delete_table_if_exists('config_updates_audit');
CALL delete_table_if_exists('database_backups');
CALL delete_table_if_exists('database_restores');
CALL delete_table_if_exists('deployment_history');
CALL delete_table_if_exists('deployment_logs');
CALL delete_table_if_exists('error_events');
CALL delete_table_if_exists('error_tracking_logs');
CALL delete_table_if_exists('export_logs');
CALL delete_table_if_exists('integration_audit');
CALL delete_table_if_exists('integration_changes_audit');
CALL delete_table_if_exists('login_attempts');
CALL delete_table_if_exists('login_attempts_security');
CALL delete_table_if_exists('migration_history');
CALL delete_table_if_exists('module_config_audit');
CALL delete_table_if_exists('module_health_logs');
CALL delete_table_if_exists('password_reset_logs');
CALL delete_table_if_exists('password_reset_tokens');
CALL delete_table_if_exists('procurement_audit');
CALL delete_table_if_exists('report_access_audit');
CALL delete_table_if_exists('reports_cache');
CALL delete_table_if_exists('restore_logs');
CALL delete_table_if_exists('staff_audit_log');
CALL delete_table_if_exists('staff_performance_log');
CALL delete_table_if_exists('station_module_audit');
CALL delete_table_if_exists('superadmin_search_history');
CALL delete_table_if_exists('suspicious_activity_alerts');
CALL delete_table_if_exists('sync_jobs');
CALL delete_table_if_exists('sync_logs');
CALL delete_table_if_exists('system_activity_logs');
CALL delete_table_if_exists('system_alerts');
CALL delete_table_if_exists('system_backups');
CALL delete_table_if_exists('system_error_logs');
CALL delete_table_if_exists('system_events');
CALL delete_table_if_exists('system_logs_audit_tabs');
CALL delete_table_if_exists('system_maintenance_log');
CALL delete_table_if_exists('system_performance_logs');
CALL delete_table_if_exists('system_settings_audit');
CALL delete_table_if_exists('user_activity_logs');
CALL delete_table_if_exists('user_notifications');
CALL delete_table_if_exists('user_sessions');

-- Notifications and calendar/scheduling records.
CALL delete_table_if_exists('notifications');
CALL delete_table_if_exists('superadmin_notifications');
CALL delete_table_if_exists('superadmin_notification_preferences');
CALL delete_table_if_exists('calendar_event_conflicts');
CALL delete_table_if_exists('calendar_event_history');
CALL delete_table_if_exists('calendar_event_notifications');
CALL delete_table_if_exists('calendar_events');
CALL delete_table_if_exists('staff_calendar_event_history');
CALL delete_table_if_exists('staff_calendar_events');
CALL delete_table_if_exists('staff_schedules');
CALL delete_table_if_exists('staff_tasks');
CALL delete_table_if_exists('labor_sessions');
CALL delete_table_if_exists('shifts');
CALL delete_table_if_exists('shift_reports');

-- Customer, credit, receivable, and loyalty records.
CALL delete_table_if_exists('accounts_receivable');
CALL delete_table_if_exists('customer_credit_transactions');
CALL delete_table_if_exists('customer_ledger');
CALL delete_table_if_exists('customer_statements');
CALL delete_table_if_exists('customer_update_requests');
CALL delete_table_if_exists('loyalty_transactions');
CALL delete_table_if_exists('credit_payments');
CALL delete_table_if_exists('customers');

-- Sales, payment audit, and transaction records.
CALL delete_table_if_exists('sale_items');
CALL delete_table_if_exists('sales');
CALL delete_table_if_exists('payment_audit_log');
CALL delete_table_if_exists('validation_actions_log');
CALL delete_table_if_exists('variance_alerts');
CALL delete_table_if_exists('variance_reports');
CALL delete_table_if_exists('daily_reconciliation');

-- Merchandise transactions, deliveries, stock-in, and batches.
CALL delete_table_if_exists('pending_merchandise_transactions');
CALL delete_table_if_exists('merchandise_transaction_items');
CALL delete_table_if_exists('merchandise_transactions');
CALL delete_table_if_exists('merchandise_transaction_audit');
CALL delete_table_if_exists('merchandise_stock_in');
CALL delete_table_if_exists('merchandise_deliveries');
CALL delete_table_if_exists('merchandise_batches');
CALL delete_table_if_exists('deliveries_oversight');

-- Job order and service operational records.
CALL delete_table_if_exists('service_parts_used');
CALL delete_table_if_exists('service_items');
CALL delete_table_if_exists('service_history');
CALL delete_table_if_exists('service_entries');
CALL delete_table_if_exists('manual_service_parts');
CALL delete_table_if_exists('manual_service_types');
CALL delete_table_if_exists('job_order_audit');
CALL delete_table_if_exists('job_order_item_links');
CALL delete_table_if_exists('job_order_parts');
CALL delete_table_if_exists('job_order_receipts');
CALL delete_table_if_exists('job_order_sequence');
CALL delete_table_if_exists('job_orders');

-- Purchase orders, receiving, stock requests, inventory movements.
CALL delete_table_if_exists('supplier_confirmations');
CALL delete_table_if_exists('received_items');
CALL delete_table_if_exists('receiving_batches');
CALL delete_table_if_exists('po_activity_log');
CALL delete_table_if_exists('po_items');
CALL delete_table_if_exists('po_request_link');
CALL delete_table_if_exists('purchase_order_items');
CALL delete_table_if_exists('purchase_orders');
CALL delete_table_if_exists('stock_request_audit');
CALL delete_table_if_exists('stock_request_items');
CALL delete_table_if_exists('stock_requests');
CALL delete_table_if_exists('simple_stock_requests');
CALL delete_table_if_exists('inventory_logs');
CALL delete_table_if_exists('inventory_transactions');
CALL delete_table_if_exists('pending_price_approvals');
CALL delete_table_if_exists('low_stock_alerts');

-- Fuel operational records and pricing history.
CALL delete_table_if_exists('fuel_stock_request_audit');
CALL delete_table_if_exists('fuel_audit_trail');
CALL delete_table_if_exists('fuel_transaction_audit');
CALL delete_table_if_exists('fuel_variance_reports');
CALL delete_table_if_exists('fuel_reconciliation');
CALL delete_table_if_exists('fuel_sales_summary');
CALL delete_table_if_exists('fuel_stock_in');
CALL delete_table_if_exists('fuel_deliveries');
CALL delete_table_if_exists('fuel_batches');
CALL delete_table_if_exists('fuel_adjustments');
CALL delete_table_if_exists('fuel_purchase_orders');
CALL delete_table_if_exists('fuel_price_log');
CALL delete_table_if_exists('fuel_pricing');
CALL delete_table_if_exists('fuel_calibration_records');
CALL delete_table_if_exists('pump_calibration_history');
CALL delete_table_if_exists('fuel_stock_requests');
CALL delete_table_if_exists('fuel_readings');
CALL delete_table_if_exists('fuel_daily_readings');
CALL delete_table_if_exists('fuel_transactions');
CALL delete_table_if_exists('fuel_calibration');

-- Keep product/master rows, but reset recorded stock/current values.
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

UPDATE inventory_products
   SET stock_quantity = 0,
       stock = 0,
       updated_at = CURRENT_TIMESTAMP;

UPDATE products
   SET current_stock = 0,
       updated_at = CURRENT_TIMESTAMP;

UPDATE inventory
   SET stock_level = 0,
       last_updated = CURRENT_TIMESTAMP;

UPDATE station_inventory
   SET stock_level = 0,
       closing_stock = 0,
       closing_date = NULL,
       closing_shift = NULL,
       last_updated = CURRENT_TIMESTAMP;

-- Remove or neutralize invalid preserved setup references that can cause FK errors.
DELETE inv
FROM inventory inv
LEFT JOIN inventory_products ip ON inv.product_id = ip.id
WHERE ip.id IS NULL;

DELETE si
FROM station_inventory si
LEFT JOIN inventory_products ip ON si.product_id = ip.id
WHERE ip.id IS NULL;

DELETE sti
FROM station_items sti
LEFT JOIN inventory_products ip ON sti.product_id = ip.id
WHERE sti.product_id IS NOT NULL
  AND ip.id IS NULL;

DELETE sti
FROM station_items sti
LEFT JOIN categories c ON sti.category_id = c.id
WHERE sti.category_id IS NOT NULL
  AND c.id IS NULL;

DELETE mcc
FROM manager_color_config mcc
LEFT JOIN users u ON mcc.user_id = u.id
WHERE u.id IS NULL;

DELETE scc
FROM staff_color_config scc
LEFT JOIN users u ON scc.user_id = u.id
WHERE u.id IS NULL;

UPDATE products p
LEFT JOIN product_types pt ON p.type_id = pt.id
   SET p.type_id = NULL
WHERE p.type_id IS NOT NULL
  AND pt.id IS NULL;

-- Reset-generated low-stock notifications are recorded data too.
CALL delete_table_if_exists('notifications');
CALL delete_table_if_exists('user_notifications');
CALL delete_table_if_exists('superadmin_notifications');

COMMIT;

SET FOREIGN_KEY_CHECKS = 1;

CALL reset_ai_if_exists('access_violations_log');
CALL reset_ai_if_exists('accounts_receivable');
CALL reset_ai_if_exists('activity_logs');
CALL reset_ai_if_exists('audit_log');
CALL reset_ai_if_exists('audit_logs');
CALL reset_ai_if_exists('audit_trail');
CALL reset_ai_if_exists('backup_logs');
CALL reset_ai_if_exists('calendar_events');
CALL reset_ai_if_exists('calendar_event_conflicts');
CALL reset_ai_if_exists('calendar_event_history');
CALL reset_ai_if_exists('calendar_event_notifications');
CALL reset_ai_if_exists('calibration_logs');
CALL reset_ai_if_exists('code_changes_audit');
CALL reset_ai_if_exists('config_updates_audit');
CALL reset_ai_if_exists('customers');
CALL reset_ai_if_exists('customer_credit_transactions');
CALL reset_ai_if_exists('customer_ledger');
CALL reset_ai_if_exists('customer_statements');
CALL reset_ai_if_exists('customer_update_requests');
CALL reset_ai_if_exists('daily_reconciliation');
CALL reset_ai_if_exists('database_backups');
CALL reset_ai_if_exists('database_restores');
CALL reset_ai_if_exists('deliveries_oversight');
CALL reset_ai_if_exists('deployment_history');
CALL reset_ai_if_exists('deployment_logs');
CALL reset_ai_if_exists('error_events');
CALL reset_ai_if_exists('error_tracking_logs');
CALL reset_ai_if_exists('export_logs');
CALL reset_ai_if_exists('fuel_adjustments');
CALL reset_ai_if_exists('fuel_audit_trail');
CALL reset_ai_if_exists('fuel_batches');
CALL reset_ai_if_exists('fuel_calibration');
CALL reset_ai_if_exists('fuel_calibration_records');
CALL reset_ai_if_exists('fuel_daily_readings');
CALL reset_ai_if_exists('fuel_deliveries');
CALL reset_ai_if_exists('fuel_price_log');
CALL reset_ai_if_exists('fuel_pricing');
CALL reset_ai_if_exists('fuel_purchase_orders');
CALL reset_ai_if_exists('fuel_readings');
CALL reset_ai_if_exists('fuel_reconciliation');
CALL reset_ai_if_exists('fuel_sales_summary');
CALL reset_ai_if_exists('fuel_stock_in');
CALL reset_ai_if_exists('fuel_stock_requests');
CALL reset_ai_if_exists('fuel_stock_request_audit');
CALL reset_ai_if_exists('fuel_transactions');
CALL reset_ai_if_exists('fuel_transaction_audit');
CALL reset_ai_if_exists('fuel_variance_reports');
CALL reset_ai_if_exists('integration_audit');
CALL reset_ai_if_exists('integration_changes_audit');
CALL reset_ai_if_exists('inventory_logs');
CALL reset_ai_if_exists('inventory_transactions');
CALL reset_ai_if_exists('job_orders');
CALL reset_ai_if_exists('job_order_audit');
CALL reset_ai_if_exists('job_order_item_links');
CALL reset_ai_if_exists('job_order_parts');
CALL reset_ai_if_exists('job_order_receipts');
CALL reset_ai_if_exists('job_order_sequence');
CALL reset_ai_if_exists('labor_sessions');
CALL reset_ai_if_exists('login_attempts');
CALL reset_ai_if_exists('login_attempts_security');
CALL reset_ai_if_exists('low_stock_alerts');
CALL reset_ai_if_exists('loyalty_transactions');
CALL reset_ai_if_exists('manual_service_parts');
CALL reset_ai_if_exists('manual_service_types');
CALL reset_ai_if_exists('merchandise_batches');
CALL reset_ai_if_exists('merchandise_deliveries');
CALL reset_ai_if_exists('merchandise_stock_in');
CALL reset_ai_if_exists('merchandise_transactions');
CALL reset_ai_if_exists('merchandise_transaction_audit');
CALL reset_ai_if_exists('merchandise_transaction_items');
CALL reset_ai_if_exists('module_config_audit');
CALL reset_ai_if_exists('module_health_logs');
CALL reset_ai_if_exists('notifications');
CALL reset_ai_if_exists('password_reset_logs');
CALL reset_ai_if_exists('password_reset_tokens');
CALL reset_ai_if_exists('payment_audit_log');
CALL reset_ai_if_exists('pending_merchandise_transactions');
CALL reset_ai_if_exists('pending_price_approvals');
CALL reset_ai_if_exists('po_activity_log');
CALL reset_ai_if_exists('po_items');
CALL reset_ai_if_exists('po_request_link');
CALL reset_ai_if_exists('procurement_audit');
CALL reset_ai_if_exists('pump_calibration_history');
CALL reset_ai_if_exists('purchase_orders');
CALL reset_ai_if_exists('purchase_order_items');
CALL reset_ai_if_exists('received_items');
CALL reset_ai_if_exists('receiving_batches');
CALL reset_ai_if_exists('reports_cache');
CALL reset_ai_if_exists('report_access_audit');
CALL reset_ai_if_exists('restore_logs');
CALL reset_ai_if_exists('sales');
CALL reset_ai_if_exists('sale_items');
CALL reset_ai_if_exists('service_entries');
CALL reset_ai_if_exists('service_history');
CALL reset_ai_if_exists('service_items');
CALL reset_ai_if_exists('service_parts_used');
CALL reset_ai_if_exists('shifts');
CALL reset_ai_if_exists('shift_reports');
CALL reset_ai_if_exists('simple_stock_requests');
CALL reset_ai_if_exists('staff_audit_log');
CALL reset_ai_if_exists('staff_calendar_events');
CALL reset_ai_if_exists('staff_calendar_event_history');
CALL reset_ai_if_exists('staff_performance_log');
CALL reset_ai_if_exists('staff_schedules');
CALL reset_ai_if_exists('staff_tasks');
CALL reset_ai_if_exists('station_module_audit');
CALL reset_ai_if_exists('stock_requests');
CALL reset_ai_if_exists('stock_request_audit');
CALL reset_ai_if_exists('stock_request_items');
CALL reset_ai_if_exists('superadmin_notifications');
CALL reset_ai_if_exists('superadmin_notification_preferences');
CALL reset_ai_if_exists('superadmin_search_history');
CALL reset_ai_if_exists('supplier_confirmations');
CALL reset_ai_if_exists('suspicious_activity_alerts');
CALL reset_ai_if_exists('sync_jobs');
CALL reset_ai_if_exists('sync_logs');
CALL reset_ai_if_exists('system_activity_logs');
CALL reset_ai_if_exists('system_alerts');
CALL reset_ai_if_exists('system_backups');
CALL reset_ai_if_exists('system_error_logs');
CALL reset_ai_if_exists('system_events');
CALL reset_ai_if_exists('system_logs_audit_tabs');
CALL reset_ai_if_exists('system_maintenance_log');
CALL reset_ai_if_exists('system_performance_logs');
CALL reset_ai_if_exists('system_settings_audit');
CALL reset_ai_if_exists('user_activity_logs');
CALL reset_ai_if_exists('user_notifications');
CALL reset_ai_if_exists('user_sessions');
CALL reset_ai_if_exists('validation_actions_log');
CALL reset_ai_if_exists('variance_alerts');
CALL reset_ai_if_exists('variance_reports');

DROP PROCEDURE IF EXISTS delete_table_if_exists;
DROP PROCEDURE IF EXISTS reset_ai_if_exists;
