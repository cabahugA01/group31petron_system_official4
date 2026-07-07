-- Add audit columns to customers table
-- These columns track who updated customer records and when

-- Check if columns exist before adding
SET @exist_updated_by = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'customers' 
    AND COLUMN_NAME = 'updated_by'
);

SET @exist_updated_at = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'customers' 
    AND COLUMN_NAME = 'updated_at'
);

-- Add updated_by column if it doesn't exist
SET @sql_updated_by = IF(
    @exist_updated_by = 0,
    'ALTER TABLE customers ADD COLUMN updated_by INT(11) UNSIGNED DEFAULT NULL COMMENT "Staff who last updated" AFTER registered_by',
    'SELECT "Column updated_by already exists" AS message'
);

PREPARE stmt FROM @sql_updated_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add updated_at column if it doesn't exist
SET @sql_updated_at = IF(
    @exist_updated_at = 0,
    'ALTER TABLE customers ADD COLUMN updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT "Last update timestamp" AFTER registered_at',
    'SELECT "Column updated_at already exists" AS message'
);

PREPARE stmt FROM @sql_updated_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Show final structure
DESCRIBE customers;

SELECT 'Migration completed! Audit columns added to customers table.' AS status;
