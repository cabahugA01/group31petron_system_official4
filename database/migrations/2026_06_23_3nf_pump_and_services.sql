-- ============================================================================
-- 3NF relationship cleanup and foreign-key coverage for Services
-- Database: petron_pos_db_secure
--
-- Goals:
--   1. Ensure master key values are present in parent tables.
--   2. Drop redundant name columns violating 3NF (transitive dependency).
--   3. Add missing foreign keys and primary keys.
--   4. Reconstruct views for backward compatibility with 3NF structures.
-- ============================================================================

-- 1. Ensure 'other_manual_input' exists in job_order_service_types
INSERT INTO `job_order_service_types` 
(service_key, service_name, base_rate_per_hour, active, status) 
VALUES 
('other_manual_input', 'Other (Manual Input)', 0.00, 1, 'approved')
ON DUPLICATE KEY UPDATE service_name = VALUES(service_name);

-- 2. Standardize 'transmission_service' to 'transmission' in service_parts_mapping
UPDATE `service_parts_mapping` 
SET service_key = 'transmission' 
WHERE service_key = 'transmission_service';

-- 3. Normalize service_parts_mapping table
-- Drop redundant service_name column (transitive dependency on service_key)
DROP PROCEDURE IF EXISTS normalize_service_parts_mapping;
DELIMITER $$
CREATE PROCEDURE normalize_service_parts_mapping()
BEGIN
    DECLARE col_exists INT;
    
    -- Check if service_name exists
    SELECT COUNT(*) INTO col_exists 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'service_parts_mapping' 
      AND column_name = 'service_name';
      
    IF col_exists = 1 THEN
        ALTER TABLE `service_parts_mapping` DROP COLUMN `service_name`;
    END IF;
    
    -- Add Foreign Key if not present
    IF NOT EXISTS (
        SELECT 1 
        FROM information_schema.table_constraints 
        WHERE constraint_schema = DATABASE() 
          AND table_name = 'service_parts_mapping' 
          AND constraint_name = 'fk_service_parts_mapping_service'
    ) THEN
        ALTER TABLE `service_parts_mapping` 
        ADD CONSTRAINT `fk_service_parts_mapping_service` 
        FOREIGN KEY (`service_key`) REFERENCES `job_order_service_types` (`service_key`) 
        ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;
END$$
DELIMITER ;
CALL normalize_service_parts_mapping();
DROP PROCEDURE IF EXISTS normalize_service_parts_mapping;

-- 4. Add Foreign Key for service_parts_map table
DROP PROCEDURE IF EXISTS normalize_service_parts_map;
DELIMITER $$
CREATE PROCEDURE normalize_service_parts_map()
BEGIN
    IF NOT EXISTS (
        SELECT 1 
        FROM information_schema.table_constraints 
        WHERE constraint_schema = DATABASE() 
          AND table_name = 'service_parts_map' 
          AND constraint_name = 'fk_service_parts_map_service'
    ) THEN
        ALTER TABLE `service_parts_map` 
        ADD CONSTRAINT `fk_service_parts_map_service` 
        FOREIGN KEY (`service_key`) REFERENCES `job_order_service_types` (`service_key`) 
        ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;
END$$
DELIMITER ;
CALL normalize_service_parts_map();
DROP PROCEDURE IF EXISTS normalize_service_parts_map;

-- 5. Add Foreign Keys for service_type_parts and service_type_required_parts
DROP PROCEDURE IF EXISTS normalize_additional_service_parts;
DELIMITER $$
CREATE PROCEDURE normalize_additional_service_parts()
BEGIN
    IF NOT EXISTS (
        SELECT 1 
        FROM information_schema.table_constraints 
        WHERE constraint_schema = DATABASE() 
          AND table_name = 'service_type_parts' 
          AND constraint_name = 'fk_service_type_parts_service'
    ) THEN
        ALTER TABLE `service_type_parts` 
        ADD CONSTRAINT `fk_service_type_parts_service` 
        FOREIGN KEY (`service_key`) REFERENCES `job_order_service_types` (`service_key`) 
        ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1 
        FROM information_schema.table_constraints 
        WHERE constraint_schema = DATABASE() 
          AND table_name = 'service_type_required_parts' 
          AND constraint_name = 'fk_service_type_required_parts_service'
    ) THEN
        ALTER TABLE `service_type_required_parts` 
        ADD CONSTRAINT `fk_service_type_required_parts_service` 
        FOREIGN KEY (`service_type_key`) REFERENCES `job_order_service_types` (`service_key`) 
        ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;
END$$
DELIMITER ;
CALL normalize_additional_service_parts();
DROP PROCEDURE IF EXISTS normalize_additional_service_parts;

-- 6. Create or replace backward-compatible 3NF view for service_parts_mapping
CREATE OR REPLACE VIEW `service_parts_mapping_3nf` AS
SELECT
    spm.*,
    COALESCE(jost.service_name, 'Unknown Service') AS service_name
FROM `service_parts_mapping` spm
LEFT JOIN `job_order_service_types` jost ON spm.service_key = jost.service_key;
