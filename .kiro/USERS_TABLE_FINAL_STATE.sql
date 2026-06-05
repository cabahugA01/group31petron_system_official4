-- ============================================
-- USERS TABLE - FINAL CLEAN STATE
-- Date: June 5, 2026
-- Status: PRODUCTION READY
-- ============================================

-- This file documents the final clean state of the users table
-- after redundant field removal and data cleanup

-- ============================================
-- REMOVED COLUMNS (Redundant)
-- ============================================
-- first_name (varchar 100) - DROPPED
-- last_name (varchar 100)  - DROPPED
--
-- Reason: Redundant with 'name' field
-- All data was preserved in the 'name' field before removal

-- ============================================
-- CURRENT USERS TABLE STRUCTURE
-- ============================================

/*
+----------------------+-------------------------------------------------------+------+-----+---------------------+----------------+
| Field                | Type                                                  | Null | Key | Default             | Extra          |
+----------------------+-------------------------------------------------------+------+-----+---------------------+----------------+
| id                   | int(11)                                               | NO   | PRI | NULL                | auto_increment |
| emp_id               | varchar(50)                                           | YES  | UNI | NULL                |                |
| username             | varchar(50)                                           | NO   | UNI | NULL                |                |
| password             | varchar(255)                                          | NO   |     | NULL                |                |
| role                 | enum('superadmin','admin','manager','operations_staff','staff') | NO |     | staff     |                |
| hourly_rate          | decimal(10,2)                                         | YES  |     | 150.00              |                |
| email                | varchar(150)                                          | YES  | UNI | NULL                |                |
| phone                | varchar(20)                                           | YES  |     | NULL                |                |
| name                 | varchar(100)                                          | YES  |     | NULL                |                |
| station_id           | int(11)                                               | YES  | MUL | NULL                |                |
| status               | enum('active','inactive')                             | YES  |     | active              |                |
| must_change_password | tinyint(1)                                            | NO   |     | 0                   |                |
| force_password_reset | tinyint(1)                                            | NO   |     | 0                   |                |
| is_deleted           | tinyint(1)                                            | NO   |     | 0                   |                |
| deleted_at           | datetime                                              | YES  |     | NULL                |                |
| deleted_by           | int(11)                                               | YES  |     | NULL                |                |
| created_at           | datetime                                              | YES  |     | current_timestamp() |                |
| remarks              | text                                                  | YES  |     | NULL                |                |
| profile_picture      | varchar(255)                                          | YES  |     | NULL                |                |
+----------------------+-------------------------------------------------------+------+-----+---------------------+----------------+
*/

-- ============================================
-- CURRENT USER DATA (4 Users)
-- ============================================

/*
+----+-----------------+---------------------------+------------+------------+
| id | name            | username                  | role       | station_id |
+----+-----------------+---------------------------+------------+------------+
| 17 | Yang C.         | testsuperadmin@petron.com | superadmin |       1253 |
| 21 | Judy Lastimosa  | stafftest@gmail.com       | staff      |       1253 |
| 22 | Edgar Eslit     | manager@gmail.com         | manager    |       1253 |
| 23 | Kathrine Pepito | pepito@gmail.com          | admin      |       1253 |
+----+-----------------+---------------------------+------------+------------+

Station 1253: VAMENTA BLVD., CARMEN, CITY OF CAGAYAN DE ORO, MISAMIS ORIENTAL
All 4 users assigned to the same station
*/

-- ============================================
-- CODE CHANGES MADE
-- ============================================

-- Files updated to remove first_name/last_name references:
-- 1. partials/header.php - Simplified name display logic
-- 2. backend/api/system_settings_api.php - Removed concatenation fallback

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Check users table has no redundant name fields
SELECT 'Checking for redundant name columns...' as check_type;
SHOW COLUMNS FROM users WHERE Field LIKE '%name%';
-- Expected: Only 'username' and 'name' (no first_name, no last_name)

-- Verify all users have valid names
SELECT 'Verifying all users have names...' as check_type;
SELECT id, name, role, 
       CASE WHEN name IS NULL OR name = '' THEN 'MISSING' ELSE 'OK' END as name_status
FROM users 
ORDER BY id;

-- Verify all users on same station
SELECT 'Verifying station assignment...' as check_type;
SELECT station_id, COUNT(*) as user_count
FROM users
GROUP BY station_id;
-- Expected: station_id=1253, user_count=4

-- Check for any NULL names
SELECT 'Checking for NULL names...' as check_type;
SELECT COUNT(*) as null_names FROM users WHERE name IS NULL OR name = '';
-- Expected: 0

-- ============================================
-- ROLLBACK PLAN (Emergency Only)
-- ============================================

-- If you need to restore first_name/last_name columns:
/*
ALTER TABLE users 
ADD COLUMN first_name VARCHAR(100) NULL AFTER id,
ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name;

-- Then populate from name field (manual split required)
UPDATE users SET 
  first_name = SUBSTRING_INDEX(name, ' ', 1),
  last_name = SUBSTRING_INDEX(name, ' ', -1)
WHERE name IS NOT NULL;
*/

-- ⚠️ WARNING: Rollback not recommended - system is optimized for single 'name' field

-- ============================================
-- BENEFITS OF CLEAN SCHEMA
-- ============================================

-- ✅ Single source of truth for user names
-- ✅ No data duplication
-- ✅ Simpler code maintenance
-- ✅ Reduced storage overhead
-- ✅ Eliminates sync issues between first/last/name fields
-- ✅ Cleaner database schema
-- ✅ Faster queries (fewer columns to scan)

-- ============================================
-- END OF DOCUMENTATION
-- ============================================
