-- ============================================================================
-- Migration: Add updated_at column to stock_requests table
-- Date: June 4, 2026
-- Purpose: Fix "Unknown column 'updated_at'" error in stock-in submission
-- ============================================================================

-- Add updated_at column if it doesn't exist
-- This is a defensive migration - it will not fail if column already exists
ALTER TABLE stock_requests 
ADD COLUMN IF NOT EXISTS updated_at 
TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
AFTER created_at;

-- Verify the column was added
SELECT 'Migration completed. Verifying column...' AS status;

SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    EXTRA
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'stock_requests'
  AND COLUMN_NAME = 'updated_at';

-- Expected output:
-- COLUMN_NAME  | COLUMN_TYPE | IS_NULLABLE | COLUMN_DEFAULT       | EXTRA
-- updated_at   | timestamp   | NO          | CURRENT_TIMESTAMP    | on update CURRENT_TIMESTAMP

SELECT 'If you see the updated_at column above, the migration was successful!' AS result;
