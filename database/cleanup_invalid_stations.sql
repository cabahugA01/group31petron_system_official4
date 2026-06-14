-- ============================================================
-- Cleanup Invalid/Test Stations
-- database/cleanup_invalid_stations.sql
-- Permanently delete dummy/test station data
-- ============================================================

-- IMPORTANT: BACKUP YOUR DATABASE FIRST!
-- This will permanently delete stations

-- ══════════════════════════════════════════════════════════════
-- OPTION 1: Delete stations with gibberish/random names
-- ══════════════════════════════════════════════════════════════

-- Delete stations with names that don't contain "PETRON" or common words
DELETE FROM stations 
WHERE LOWER(name) NOT LIKE '%petron%'
AND LOWER(name) NOT LIKE '%station%'
AND LOWER(name) NOT LIKE '%gasoline%'
AND LOWER(name) NOT LIKE '%service%'
AND LENGTH(name) < 30
AND name REGEXP '^[a-z]+$'  -- Only lowercase letters (likely random)
AND status != 'Active';

-- ══════════════════════════════════════════════════════════════
-- OPTION 2: Delete specific test stations by ID range
-- ══════════════════════════════════════════════════════════════

-- If you know test stations are in a specific ID range:
-- DELETE FROM stations WHERE id BETWEEN 100 AND 1400 AND name NOT LIKE '%PETRON%';

-- ══════════════════════════════════════════════════════════════
-- OPTION 3: Keep only valid PETRON stations
-- ══════════════════════════════════════════════════════════════

-- Create a backup table first
CREATE TABLE stations_backup AS SELECT * FROM stations;

-- Delete everything that doesn't look like a real Petron station
DELETE FROM stations 
WHERE (
    -- Not a PETRON station
    LOWER(name) NOT LIKE '%petron%'
    
    -- Or has gibberish name (no spaces, all lowercase, less than 10 chars)
    OR (name REGEXP '^[a-z]+$' AND LENGTH(name) < 15)
    
    -- Or has no location data
    OR location IS NULL 
    OR location = ''
    OR location = 'NULL'
    
    -- Or is clearly test data
    OR LOWER(name) LIKE '%test%'
    OR LOWER(name) LIKE '%dummy%'
    OR LOWER(name) LIKE '%sample%'
)
-- Safety check: don't delete if it has an admin assigned
AND id NOT IN (
    SELECT DISTINCT station_id 
    FROM users 
    WHERE station_id IS NOT NULL 
    AND role = 'admin'
);

-- ══════════════════════════════════════════════════════════════
-- OPTION 4: Safe mode - Mark as inactive instead of delete
-- ══════════════════════════════════════════════════════════════

-- If you're not sure, just mark them as inactive first:
UPDATE stations 
SET status = 'Inactive'
WHERE (
    LOWER(name) NOT LIKE '%petron%'
    OR (name REGEXP '^[a-z]+$' AND LENGTH(name) < 15)
    OR location IS NULL 
    OR location = ''
)
AND id NOT IN (
    SELECT DISTINCT station_id 
    FROM users 
    WHERE station_id IS NOT NULL 
    AND role = 'admin'
);

-- ══════════════════════════════════════════════════════════════
-- Verification Queries
-- ══════════════════════════════════════════════════════════════

-- Check what will be deleted (run BEFORE delete):
SELECT id, name, location, status
FROM stations 
WHERE (
    LOWER(name) NOT LIKE '%petron%'
    OR (name REGEXP '^[a-z]+$' AND LENGTH(name) < 15)
    OR location IS NULL 
    OR location = ''
)
AND id NOT IN (
    SELECT DISTINCT station_id 
    FROM users 
    WHERE station_id IS NOT NULL 
);

-- Count before cleanup:
SELECT 
    COUNT(*) as total_stations,
    SUM(CASE WHEN LOWER(name) LIKE '%petron%' THEN 1 ELSE 0 END) as petron_stations,
    SUM(CASE WHEN LOWER(name) NOT LIKE '%petron%' THEN 1 ELSE 0 END) as non_petron_stations
FROM stations;

-- Count after cleanup:
SELECT COUNT(*) as remaining_stations FROM stations;

-- ══════════════════════════════════════════════════════════════
-- Reset Auto Increment (optional, after delete)
-- ══════════════════════════════════════════════════════════════

-- Reset the auto increment to avoid huge gaps in IDs
SET @max_id = (SELECT MAX(id) FROM stations);
SET @sql = CONCAT('ALTER TABLE stations AUTO_INCREMENT = ', @max_id + 1);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ══════════════════════════════════════════════════════════════
-- Restore from backup (if something went wrong)
-- ══════════════════════════════════════════════════════════════

-- If you made a backup and need to restore:
-- TRUNCATE TABLE stations;
-- INSERT INTO stations SELECT * FROM stations_backup;
