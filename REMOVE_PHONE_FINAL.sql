-- ═══════════════════════════════════════════════════════════════════════
-- REMOVE PHONE NUMBER FROM USERS TABLE - FINAL VERSION
-- ═══════════════════════════════════════════════════════════════════════
-- This will:
-- 1. Remove phone_number column from users table
-- 2. Remove phone column (if exists)
-- 3. Keep only EMAIL and USERNAME for authentication
-- ═══════════════════════════════════════════════════════════════════════

USE petron_pos_db_secure;

-- Check if phone_number column exists and drop it
SET @sql = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE users DROP COLUMN phone_number;',
        'SELECT "phone_number column does not exist" AS message;'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'petron_pos_db_secure'
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'phone_number'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if phone column exists and drop it
SET @sql = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE users DROP COLUMN phone;',
        'SELECT "phone column does not exist" AS message;'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'petron_pos_db_secure'
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'phone'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify final structure
SELECT 'Users table structure after phone removal:' AS message;
SHOW COLUMNS FROM users;

-- Show sample data
SELECT 'Sample users (first 5):' AS message;
SELECT user_id, username, email, role, status 
FROM users 
ORDER BY user_id 
LIMIT 5;

-- ═══════════════════════════════════════════════════════════════════════
-- DONE! Phone number columns removed.
-- Users can now only login/reset password with EMAIL or USERNAME
-- ═══════════════════════════════════════════════════════════════════════
