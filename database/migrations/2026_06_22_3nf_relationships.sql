-- ============================================================================
-- 3NF relationship cleanup and foreign-key coverage
-- Database: petron_pos_db_secure
--
-- Goals:
--   1. Remove transitive name duplication from variance and validation logs.
--   2. Add missing foreign keys only where existing data is clean.
--   3. Keep the migration safe to rerun.
-- ============================================================================

DROP PROCEDURE IF EXISTS add_fk_if_clean;
DROP PROCEDURE IF EXISTS drop_column_if_exists;
DROP PROCEDURE IF EXISTS modify_unsigned_column_if_exists;

DELIMITER $$

CREATE PROCEDURE add_fk_if_clean(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_ref_table VARCHAR(64),
    IN p_ref_column VARCHAR(64),
    IN p_constraint VARCHAR(64),
    IN p_on_delete VARCHAR(20)
)
BEGIN
    DECLARE v_table_count INT DEFAULT 0;
    DECLARE v_column_count INT DEFAULT 0;
    DECLARE v_ref_column_count INT DEFAULT 0;
    DECLARE v_column_fk_count INT DEFAULT 0;
    DECLARE v_constraint_count INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_table_count
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = p_table
       AND table_type = 'BASE TABLE';

    SELECT COUNT(*)
      INTO v_column_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = p_table
       AND column_name = p_column;

    SELECT COUNT(*)
      INTO v_ref_column_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = p_ref_table
       AND column_name = p_ref_column;

    SELECT COUNT(*)
      INTO v_column_fk_count
      FROM information_schema.key_column_usage
     WHERE table_schema = DATABASE()
       AND table_name = p_table
       AND column_name = p_column
       AND referenced_table_name IS NOT NULL;

    SELECT COUNT(*)
      INTO v_constraint_count
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND constraint_name = p_constraint
       AND constraint_type = 'FOREIGN KEY';

    IF v_table_count = 1
       AND v_column_count = 1
       AND v_ref_column_count = 1
       AND v_column_fk_count = 0
       AND v_constraint_count = 0 THEN

        SET @fk_orphan_count = 0;
        SET @fk_orphan_sql = CONCAT(
            'SELECT COUNT(*) INTO @fk_orphan_count ',
            'FROM `', p_table, '` child ',
            'LEFT JOIN `', p_ref_table, '` parent ',
            'ON child.`', p_column, '` = parent.`', p_ref_column, '` ',
            'WHERE child.`', p_column, '` IS NOT NULL ',
            'AND parent.`', p_ref_column, '` IS NULL'
        );
        PREPARE fk_orphan_stmt FROM @fk_orphan_sql;
        EXECUTE fk_orphan_stmt;
        DEALLOCATE PREPARE fk_orphan_stmt;

        IF @fk_orphan_count = 0 THEN
            SET @fk_alter_sql = CONCAT(
                'ALTER TABLE `', p_table, '` ',
                'ADD CONSTRAINT `', p_constraint, '` ',
                'FOREIGN KEY (`', p_column, '`) ',
                'REFERENCES `', p_ref_table, '` (`', p_ref_column, '`) ',
                'ON UPDATE CASCADE ON DELETE ', p_on_delete
            );
            PREPARE fk_alter_stmt FROM @fk_alter_sql;
            EXECUTE fk_alter_stmt;
            DEALLOCATE PREPARE fk_alter_stmt;
        END IF;
    END IF;
END$$

CREATE PROCEDURE drop_column_if_exists(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64)
)
BEGIN
    DECLARE v_column_count INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_column_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = p_table
       AND column_name = p_column;

    IF v_column_count = 1 THEN
        SET @drop_column_sql = CONCAT(
            'ALTER TABLE `', p_table, '` DROP COLUMN `', p_column, '`'
        );
        PREPARE drop_column_stmt FROM @drop_column_sql;
        EXECUTE drop_column_stmt;
        DEALLOCATE PREPARE drop_column_stmt;
    END IF;
END$$

CREATE PROCEDURE modify_unsigned_column_if_exists(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    DECLARE v_unsigned_count INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_unsigned_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = p_table
       AND column_name = p_column
       AND column_type LIKE '%unsigned%';

    IF v_unsigned_count = 1 THEN
        SET @modify_column_sql = CONCAT(
            'ALTER TABLE `', p_table, '` MODIFY COLUMN `', p_column, '` ', p_definition
        );
        PREPARE modify_column_stmt FROM @modify_column_sql;
        EXECUTE modify_column_stmt;
        DEALLOCATE PREPARE modify_column_stmt;
    END IF;
END$$

DELIMITER ;

-- Normalize ID column signedness before adding relationships to signed parent IDs.
CALL modify_unsigned_column_if_exists('variance_reports', 'encoder_id', 'INT(11) NULL');
CALL modify_unsigned_column_if_exists('variance_reports', 'manager_id', 'INT(11) NULL');
CALL modify_unsigned_column_if_exists('variance_reports', 'station_id', 'INT(11) NOT NULL');

-- Screenshot tables: keep IDs only, derive names through users/stations in queries.
CALL drop_column_if_exists('validation_actions_log', 'manager_name');
CALL drop_column_if_exists('validation_actions_log', 'staff_name');
CALL drop_column_if_exists('variance_reports', 'encoder_name');
CALL drop_column_if_exists('variance_reports', 'manager_name');

-- User relationships.
CALL add_fk_if_clean('access_violations_log', 'user_id', 'users', 'id', 'fk_access_violations_log_user', 'SET NULL');
CALL add_fk_if_clean('api_config', 'created_by', 'users', 'id', 'fk_api_config_created_by', 'RESTRICT');
CALL add_fk_if_clean('audit_trail', 'staff_id', 'users', 'id', 'fk_audit_trail_staff', 'SET NULL');
CALL add_fk_if_clean('code_changes_audit', 'author_id', 'users', 'id', 'fk_code_changes_audit_author', 'SET NULL');
CALL add_fk_if_clean('config_updates_audit', 'user_id', 'users', 'id', 'fk_config_updates_audit_user', 'RESTRICT');
CALL add_fk_if_clean('database_backups', 'created_by', 'users', 'id', 'fk_database_backups_created_by', 'RESTRICT');
CALL add_fk_if_clean('deliveries_oversight', 'manager_id', 'users', 'id', 'fk_deliveries_oversight_manager', 'SET NULL');
CALL add_fk_if_clean('deployment_logs', 'deployed_by_id', 'users', 'id', 'fk_deployment_logs_deployed_by', 'SET NULL');
CALL add_fk_if_clean('erp_connections', 'created_by', 'users', 'id', 'fk_erp_connections_created_by', 'RESTRICT');
CALL add_fk_if_clean('fuel_pricing', 'created_by', 'users', 'id', 'fk_fuel_pricing_created_by', 'SET NULL');
CALL add_fk_if_clean('fuel_purchase_orders', 'approved_by', 'users', 'id', 'fk_fuel_purchase_orders_approved_by', 'SET NULL');
CALL add_fk_if_clean('fuel_reconciliation', 'approved_by', 'users', 'id', 'fk_fuel_reconciliation_approved_by', 'SET NULL');
CALL add_fk_if_clean('fuel_transactions', 'validated_by', 'users', 'id', 'fk_fuel_transactions_validated_by', 'SET NULL');
CALL add_fk_if_clean('fuel_transactions', 'manager_id', 'users', 'id', 'fk_fuel_transactions_manager', 'SET NULL');
CALL add_fk_if_clean('git_repos', 'created_by', 'users', 'id', 'fk_git_repos_created_by', 'RESTRICT');
CALL add_fk_if_clean('integration_changes_audit', 'user_id', 'users', 'id', 'fk_integration_changes_audit_user', 'RESTRICT');
CALL add_fk_if_clean('inventory_transactions', 'created_by', 'users', 'id', 'fk_inventory_transactions_created_by', 'SET NULL');
CALL add_fk_if_clean('job_orders', 'created_by', 'users', 'id', 'fk_job_orders_created_by', 'RESTRICT');
CALL add_fk_if_clean('job_orders', 'validated_by', 'users', 'id', 'fk_job_orders_validated_by', 'SET NULL');
CALL add_fk_if_clean('low_stock_alerts', 'created_by', 'users', 'id', 'fk_low_stock_alerts_created_by', 'SET NULL');
CALL add_fk_if_clean('merchandise_batches', 'validated_by', 'users', 'id', 'fk_merchandise_batches_validated_by', 'SET NULL');
CALL add_fk_if_clean('password_reset_logs', 'user_id', 'users', 'id', 'fk_password_reset_logs_user', 'RESTRICT');
CALL add_fk_if_clean('payment_audit_log', 'staff_id', 'users', 'id', 'fk_payment_audit_log_staff', 'SET NULL');
CALL add_fk_if_clean('pending_price_approvals', 'manager_id', 'users', 'id', 'fk_pending_price_approvals_manager', 'RESTRICT');
CALL add_fk_if_clean('pending_price_approvals', 'admin_id', 'users', 'id', 'fk_pending_price_approvals_admin', 'SET NULL');
CALL add_fk_if_clean('purchase_orders', 'admin_id', 'users', 'id', 'fk_purchase_orders_admin', 'SET NULL');
CALL add_fk_if_clean('report_access_audit', 'user_id', 'users', 'id', 'fk_report_access_audit_user', 'RESTRICT');
CALL add_fk_if_clean('service_entries', 'verified_by', 'users', 'id', 'fk_service_entries_verified_by', 'SET NULL');
CALL add_fk_if_clean('shift_reports', 'created_by', 'users', 'id', 'fk_shift_reports_created_by', 'RESTRICT');
CALL add_fk_if_clean('station_items', 'updated_by', 'users', 'id', 'fk_station_items_updated_by', 'SET NULL');
CALL add_fk_if_clean('suspicious_activity_alerts', 'user_id', 'users', 'id', 'fk_suspicious_activity_alerts_user', 'SET NULL');
CALL add_fk_if_clean('sync_jobs', 'created_by', 'users', 'id', 'fk_sync_jobs_created_by', 'RESTRICT');
CALL add_fk_if_clean('system_config', 'updated_by', 'users', 'id', 'fk_system_config_updated_by', 'SET NULL');
CALL add_fk_if_clean('user_activity_logs', 'user_id', 'users', 'id', 'fk_user_activity_logs_user', 'SET NULL');
CALL add_fk_if_clean('variance_reports', 'encoder_id', 'users', 'id', 'fk_variance_reports_encoder', 'SET NULL');
CALL add_fk_if_clean('variance_reports', 'manager_id', 'users', 'id', 'fk_variance_reports_manager', 'SET NULL');

-- Station relationships.
CALL add_fk_if_clean('fuel_batches', 'station_id', 'stations', 'id', 'fk_fuel_batches_station', 'RESTRICT');
CALL add_fk_if_clean('fuel_price_log', 'station_id', 'stations', 'id', 'fk_fuel_price_log_station', 'RESTRICT');
CALL add_fk_if_clean('fuel_stock_in', 'station_id', 'stations', 'id', 'fk_fuel_stock_in_station', 'RESTRICT');
CALL add_fk_if_clean('inventory_products', 'station_id', 'stations', 'id', 'fk_inventory_products_station', 'RESTRICT');
CALL add_fk_if_clean('merchandise_batches', 'station_id', 'stations', 'id', 'fk_merchandise_batches_station', 'RESTRICT');
CALL add_fk_if_clean('merchandise_stock_in', 'station_id', 'stations', 'id', 'fk_merchandise_stock_in_station', 'RESTRICT');
CALL add_fk_if_clean('payment_audit_log', 'station_id', 'stations', 'id', 'fk_payment_audit_log_station', 'RESTRICT');
CALL add_fk_if_clean('pending_price_approvals', 'station_id', 'stations', 'id', 'fk_pending_price_approvals_station', 'RESTRICT');
CALL add_fk_if_clean('replication_status', 'station_id', 'stations', 'id', 'fk_replication_status_station', 'RESTRICT');
CALL add_fk_if_clean('sync_logs', 'station_id', 'stations', 'id', 'fk_sync_logs_station', 'RESTRICT');
CALL add_fk_if_clean('system_settings_audit', 'station_id', 'stations', 'id', 'fk_system_settings_audit_station', 'RESTRICT');
CALL add_fk_if_clean('variance_reports', 'station_id', 'stations', 'id', 'fk_variance_reports_station', 'RESTRICT');

-- Fuel/product relationships.
CALL add_fk_if_clean('fuel_batches', 'fuel_type_id', 'fuel_types', 'id', 'fk_fuel_batches_fuel_type', 'RESTRICT');
CALL add_fk_if_clean('fuel_price_log', 'fuel_type_id', 'fuel_types', 'id', 'fk_fuel_price_log_fuel_type', 'SET NULL');
CALL add_fk_if_clean('merchandise_batches', 'product_id', 'inventory_products', 'id', 'fk_merchandise_batches_product', 'RESTRICT');
CALL add_fk_if_clean('merchandise_stock_in', 'product_id', 'inventory_products', 'id', 'fk_merchandise_stock_in_product', 'RESTRICT');
CALL add_fk_if_clean('merchandise_stock_in', 'po_id', 'purchase_orders', 'id', 'fk_merchandise_stock_in_po', 'SET NULL');
CALL add_fk_if_clean('pending_price_approvals', 'product_id', 'inventory_products', 'id', 'fk_pending_price_approvals_product', 'RESTRICT');

CREATE OR REPLACE VIEW validation_actions_log_3nf AS
SELECT
    val.*,
    COALESCE(
        NULLIF(TRIM(mgr.name), ''),
        NULLIF(CONCAT(TRIM(mgr.first_name), ' ', TRIM(mgr.last_name)), ' '),
        mgr.username,
        'Unassigned'
    ) AS manager_name,
    COALESCE(
        NULLIF(TRIM(staff.name), ''),
        NULLIF(CONCAT(TRIM(staff.first_name), ' ', TRIM(staff.last_name)), ' '),
        staff.username,
        'Unassigned'
    ) AS staff_name
FROM validation_actions_log val
LEFT JOIN users mgr ON val.manager_id = mgr.id
LEFT JOIN users staff ON val.staff_id = staff.id;

CREATE OR REPLACE VIEW variance_reports_3nf AS
SELECT
    vr.*,
    COALESCE(
        NULLIF(TRIM(enc.name), ''),
        NULLIF(CONCAT(TRIM(enc.first_name), ' ', TRIM(enc.last_name)), ' '),
        enc.username,
        'Unassigned'
    ) AS encoder_name,
    COALESCE(
        NULLIF(TRIM(mgr.name), ''),
        NULLIF(CONCAT(TRIM(mgr.first_name), ' ', TRIM(mgr.last_name)), ' '),
        mgr.username,
        'Unassigned'
    ) AS manager_name,
    s.name AS station_name
FROM variance_reports vr
LEFT JOIN users enc ON vr.encoder_id = enc.id
LEFT JOIN users mgr ON vr.manager_id = mgr.id
LEFT JOIN stations s ON vr.station_id = s.id;

DROP PROCEDURE IF EXISTS add_fk_if_clean;
DROP PROCEDURE IF EXISTS drop_column_if_exists;
DROP PROCEDURE IF EXISTS modify_unsigned_column_if_exists;
