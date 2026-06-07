-- ============================================================================
-- DELETE PHONE FIELDS FROM USERS TABLE - PERMANENT
-- Run this in phpMyAdmin SQL tab or MySQL command line
-- ============================================================================

-- IMPORTANT: Backup your database first!
-- mysqldump -u root petron_pos_db_secure > backup_before_delete_phone.sql

-- ============================================================================
-- STEP 1: Check current structure
-- ============================================================================
DESCRIBE users;

-- ============================================================================
-- STEP 2: Delete phone_number column (if exists)
-- ============================================================================
ALTER TABLE `users` DROP COLUMN IF EXISTS `phone_number`;

-- ============================================================================
-- STEP 3: Delete phone column (if exists)
-- ============================================================================
ALTER TABLE `users` DROP COLUMN IF EXISTS `phone`;

-- ============================================================================
-- STEP 4: Verify deletion
-- ============================================================================
DESCRIBE users;

-- Expected: 11 fields, NO phone or phone_number
-- user_id, first_name, last_name, station_id, email, username, 
-- password_hash, role, status, created_at, updated_at

-- ============================================================================
-- STEP 5: Check field count
-- ============================================================================
SELECT COUNT(*) AS total_fields 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'users';

-- Expected: 11

-- ============================================================================
-- STEP 6: Verify NO phone fields remain
-- ============================================================================
SELECT COLUMN_NAME 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME IN ('phone', 'phone_number');

-- Expected: Empty result (no rows)

-- ============================================================================
-- ROLLBACK (if needed)
-- ============================================================================
-- If you need to restore, use your backup:
-- mysql -u root petron_pos_db_secure < backup_before_delete_phone.sql

-- ============================================================================
-- SUCCESS! Phone fields permanently deleted!
-- ============================================================================
