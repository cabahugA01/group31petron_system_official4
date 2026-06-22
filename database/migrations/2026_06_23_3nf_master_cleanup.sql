-- ============================================================================
-- MASTER 3NF CLEANUP MIGRATION
-- Database: petron_pos_db_secure
-- Date: 2026-06-23
--
-- Strategy:
--   DROP  - Backup clones, dead/never-used config tables (confirmed no PHP refs)
--   LINK  - Tables with user_id/station_id columns that are missing FK constraints
--   KEEP  - Pure enum/lookup tables with no linkable parent columns (correct as-is)
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- SECTION A: DROP DEAD / REDUNDANT TABLES (no PHP usage, or superseded)
-- ============================================================================

-- Stale product and inventory snapshot tables
DROP TABLE IF EXISTS `products_backup_20250217`;
DROP TABLE IF EXISTS `products_backup_20260217`;
DROP TABLE IF EXISTS `station_inventory_backup_20250217`;
DROP TABLE IF EXISTS `station_inventory_backup_20260217`;

-- UI / config tables with zero PHP references
DROP TABLE IF EXISTS `db_management_tabs`;
DROP TABLE IF EXISTS `db_management_config`;
DROP TABLE IF EXISTS `reconciliation_status_config`;
DROP TABLE IF EXISTS `search_scope_config`;
DROP TABLE IF EXISTS `system_logs_audit_tabs`;
DROP TABLE IF EXISTS `system_logs_statistics_config`;
DROP TABLE IF EXISTS `system_module_access`;
DROP TABLE IF EXISTS `staff_event_status_config`;
DROP TABLE IF EXISTS `ui_ux_settings`;
DROP TABLE IF EXISTS `discount_type_config`;

-- PO config tables with zero PHP references
DROP TABLE IF EXISTS `po_types`;
DROP TABLE IF EXISTS `po_config`;
DROP TABLE IF EXISTS `po_status_workflow`;

-- Superseded/dead scaffold tables
DROP TABLE IF EXISTS `fuel_calibration`;
DROP TABLE IF EXISTS `database_restores`;
DROP TABLE IF EXISTS `schema_migrations`;
DROP TABLE IF EXISTS `soft_delete_tables_config`;

-- ============================================================================
-- SECTION B: SAFE FK PROCEDURE
-- Adds a FK only when: both table and column exist, no FK already exists,
-- and there are zero orphan rows.
-- ============================================================================

DROP PROCEDURE IF EXISTS safe_add_fk;
DELIMITER $$
CREATE PROCEDURE safe_add_fk(
    IN p_table      VARCHAR(64),
    IN p_column     VARCHAR(64),
    IN p_ref_table  VARCHAR(64),
    IN p_ref_col    VARCHAR(64),
    IN p_constraint VARCHAR(64),
    IN p_on_delete  VARCHAR(20)
)
proc_block: BEGIN
    DECLARE v_tbl  INT DEFAULT 0;
    DECLARE v_col  INT DEFAULT 0;
    DECLARE v_ref  INT DEFAULT 0;
    DECLARE v_fk   INT DEFAULT 0;

    SELECT COUNT(*) INTO v_tbl FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = p_table AND table_type = 'BASE TABLE';

    SELECT COUNT(*) INTO v_col FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column;

    SELECT COUNT(*) INTO v_ref FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = p_ref_table AND column_name = p_ref_col;

    SELECT COUNT(*) INTO v_fk FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND constraint_name = p_constraint
      AND constraint_type = 'FOREIGN KEY';

    IF v_tbl = 0 OR v_col = 0 OR v_ref = 0 OR v_fk > 0 THEN
        LEAVE proc_block;
    END IF;

    -- Count orphan rows
    SET @__chk = CONCAT(
        'SELECT COUNT(*) INTO @__orphans FROM `', p_table, '` c ',
        'LEFT JOIN `', p_ref_table, '` p ON c.`', p_column, '` = p.`', p_ref_col, '` ',
        'WHERE c.`', p_column, '` IS NOT NULL AND p.`', p_ref_col, '` IS NULL'
    );
    PREPARE __s FROM @__chk; EXECUTE __s; DEALLOCATE PREPARE __s;

    IF @__orphans > 0 THEN
        LEAVE proc_block;
    END IF;

    SET @__sql = CONCAT(
        'ALTER TABLE `', p_table, '` ',
        'ADD CONSTRAINT `', p_constraint, '` ',
        'FOREIGN KEY (`', p_column, '`) ',
        'REFERENCES `', p_ref_table, '` (`', p_ref_col, '`) ',
        'ON UPDATE CASCADE ON DELETE ', p_on_delete
    );
    PREPARE __s FROM @__sql; EXECUTE __s; DEALLOCATE PREPARE __s;
END proc_block$$
DELIMITER ;

-- ============================================================================
-- SECTION C: LINK TABLES THAT HAVE user/station COLUMNS BUT LACK FKs
-- ============================================================================

-- ── backup_logs ──────────────────────────────────────────────────────────────
-- backup_logs.backup_id → system_backups.id
CALL safe_add_fk('backup_logs','backup_id','system_backups','id',
                 'fk_backup_logs_backup_id','SET NULL');

-- ── restore_logs ─────────────────────────────────────────────────────────────
-- restore_logs.restored_by → users.id
CALL safe_add_fk('restore_logs','restored_by','users','id',
                 'fk_restore_logs_restored_by','SET NULL');

-- ── migration_history ────────────────────────────────────────────────────────
-- migration_history.executed_by → users.id
CALL safe_add_fk('migration_history','executed_by','users','id',
                 'fk_migration_history_executed_by','SET NULL');

-- ── schema_versions ──────────────────────────────────────────────────────────
-- schema_versions.applied_by → users.id
CALL safe_add_fk('schema_versions','applied_by','users','id',
                 'fk_schema_versions_applied_by','SET NULL');

-- ── manager_meetings ─────────────────────────────────────────────────────────
-- manager_meetings.station_id → stations.id
CALL safe_add_fk('manager_meetings','station_id','stations','id',
                 'fk_manager_meetings_station','RESTRICT');
-- manager_meetings.created_by → users.id
CALL safe_add_fk('manager_meetings','created_by','users','id',
                 'fk_manager_meetings_created_by','RESTRICT');

-- ── adjustment_types ─────────────────────────────────────────────────────────
-- adjustment_types is a lookup parent referenced by fuel_adjustments.adjustment_type (VARCHAR).
-- Ensure unique key on adjustment_types.name so it can be a FK target.
DROP PROCEDURE IF EXISTS add_unique_if_missing;
DELIMITER $$
CREATE PROCEDURE add_unique_if_missing(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_idx VARCHAR(64))
BEGIN
    DECLARE v INT DEFAULT 0;
    SELECT COUNT(*) INTO v FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = p_table
      AND index_name = p_idx AND non_unique = 0;
    IF v = 0 THEN
        SET @__si = CONCAT('ALTER TABLE `', p_table, '` ADD UNIQUE KEY `', p_idx, '` (`', p_col, '`)');
        PREPARE __ss FROM @__si; EXECUTE __ss; DEALLOCATE PREPARE __ss;
    END IF;
END$$
DELIMITER ;
CALL add_unique_if_missing('adjustment_types','name','uq_adjustment_types_name');
DROP PROCEDURE IF EXISTS add_unique_if_missing;

-- fuel_adjustments.adjustment_type → adjustment_types.name
CALL safe_add_fk('fuel_adjustments','adjustment_type','adjustment_types','name',
                 'fk_fuel_adjustments_adjustment_type','SET NULL');

-- ── service_fees ─────────────────────────────────────────────────────────────
-- service_fees.service_key has utf8mb4_unicode_ci; parent uses utf8mb4_general_ci.
-- Normalise collation first so FK can be created.
ALTER TABLE `service_fees`
    MODIFY COLUMN `service_key` VARCHAR(50)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;

-- Reconcile orphan service keys before applying foreign key constraint
UPDATE `service_fees` SET `service_key` = 'transmission' WHERE `service_key` = 'transmission_service';
UPDATE `service_fees` SET `service_key` = 'other_manual_input' WHERE `service_key` = 'other';

-- service_fees.service_key → job_order_service_types.service_key
-- service_fees also has a redundant service_name (transitive dependency via service_key).
CALL safe_add_fk('service_fees','service_key','job_order_service_types','service_key',
                 'fk_service_fees_service_key','CASCADE');

-- Rename the physical table to service_fees_base to decouple schema
RENAME TABLE `service_fees` TO `service_fees_base`;

-- Create 3NF view for service_fees to resolve service_name without storing it
CREATE OR REPLACE VIEW `service_fees` AS
SELECT
    sf.id,
    sf.service_key,
    COALESCE(jost.service_name, sf.service_name) AS service_name,
    sf.base_fee,
    sf.labor_cost_per_hour,
    sf.estimated_hours,
    sf.is_active,
    sf.created_at,
    sf.updated_at
FROM `service_fees_base` sf
LEFT JOIN `job_order_service_types` jost ON sf.service_key = jost.service_key;

-- ── service_type_inventory_mapping ───────────────────────────────────────────
-- Uses service_type (VARCHAR) column. Link via service_type → service_key.
CALL safe_add_fk('service_type_inventory_mapping','service_type',
                 'job_order_service_types','service_key',
                 'fk_svc_type_inv_map_service','CASCADE');

-- ── login_attempts_security ───────────────────────────────────────────────────
-- Uses username (VARCHAR), no user_id. Cannot add strict FK (failed logins
-- don't always match a valid user). KEEP as standalone security log.

-- ── fuel_management_config ───────────────────────────────────────────────────
-- Pure key-value config (config_key, config_value). No station_id/user columns.
-- KEEP standalone.

-- ── payment_method_config ────────────────────────────────────────────────────
-- Pure lookup (method_key, method_name). No station_id/user columns.
-- KEEP standalone.

-- ── calendar_event_type_config ───────────────────────────────────────────────
-- Pure lookup (type_key, type_name). No station_id/user columns.
-- KEEP standalone.

-- ── shift_periods ────────────────────────────────────────────────────────────
-- Pure lookup (shift_key, shift_name, times). No station_id/user columns.
-- KEEP standalone.

-- ── shift_period_config ──────────────────────────────────────────────────────
-- Pure lookup (shift_name, start/end hour). No station_id/user columns.
-- KEEP standalone.

-- ── staff_role_config ────────────────────────────────────────────────────────
-- Pure lookup (role_key, permissions JSON). No station_id/user columns.
-- KEEP standalone.

-- ── transaction_type_config ──────────────────────────────────────────────────
-- Pure lookup (type_key). No station_id/user columns.
-- KEEP standalone.

-- ── ui_config ────────────────────────────────────────────────────────────────
-- Pure key-value config (config_key, config_value). No station_id/user columns.
-- KEEP standalone.

-- ── role_permissions ─────────────────────────────────────────────────────────
-- Stores role → permission mapping as raw data. No user/station FK column.
-- KEEP standalone (RBAC seed table).

-- ── db_maintenance_scripts ───────────────────────────────────────────────────
-- Config-only (script definitions). No user/station columns.
-- KEEP standalone.

-- ── db_tables_config ─────────────────────────────────────────────────────────
-- Table display config (115 rows). No user/station columns.
-- KEEP standalone.

-- ── mechanics_config ─────────────────────────────────────────────────────────
-- Lookup table for mechanic names/specializations. No user/station FK column.
-- KEEP standalone.

-- ── fuel_calibration_defaults ────────────────────────────────────────────────
-- Default calibration presets. No user/station FK column.
-- KEEP standalone.

-- ── payment_methods ──────────────────────────────────────────────────────────
-- Lookup list (Cash, Card, GCash…). No user/station FK column.
-- KEEP standalone.

-- ── ph_regions ───────────────────────────────────────────────────────────────
-- Geographic lookup. No user/station FK column. KEEP standalone.

-- ── system_events ────────────────────────────────────────────────────────────
-- System-generated event log (no user_id column). KEEP standalone.

-- ── system_health_metrics ────────────────────────────────────────────────────
-- System metrics (no user/station column). KEEP standalone.

-- ── system_performance_logs ──────────────────────────────────────────────────
-- Performance log (no user/station column). KEEP standalone.

-- ── module_health_logs ───────────────────────────────────────────────────────
-- Module health log (no user/station column). KEEP standalone.

-- ── error_tracking_logs ──────────────────────────────────────────────────────
-- Error log (no user/station column). KEEP standalone.

-- ── system_configuration ─────────────────────────────────────────────────────
-- Key-value config store (no user/station column). KEEP standalone.

-- ── schema_migrations (already dropped) ──────────────────────────────────────

-- ============================================================================
-- CLEANUP
-- ============================================================================
DROP PROCEDURE IF EXISTS safe_add_fk;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VERIFY: List remaining isolated tables after migration
-- ============================================================================
SELECT t.TABLE_NAME, t.TABLE_ROWS,
    'ISOLATED - no FK in or out' AS status
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_SCHEMA = 'petron_pos_db_secure'
  AND t.TABLE_TYPE = 'BASE TABLE'
  AND t.TABLE_NAME NOT IN (
      SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = 'petron_pos_db_secure' AND REFERENCED_TABLE_NAME IS NOT NULL
  )
  AND t.TABLE_NAME NOT IN (
      SELECT DISTINCT REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
      WHERE REFERENCED_TABLE_SCHEMA = 'petron_pos_db_secure' AND REFERENCED_TABLE_NAME IS NOT NULL
  )
ORDER BY t.TABLE_NAME;
