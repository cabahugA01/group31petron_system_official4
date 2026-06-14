-- ============================================================
-- QUICK DELETE: Remove Invalid Stations NOW
-- database/delete_invalid_stations_now.sql
-- ============================================================

-- ⚠️ WARNING: This will PERMANENTLY delete data!
-- BACKUP your database first: phpMyAdmin → Export

-- ══════════════════════════════════════════════════════════════
-- STEP 1: Preview what will be deleted (RUN THIS FIRST)
-- ══════════════════════════════════════════════════════════════

SELECT 
    id, 
    name, 
    location,
    status,
    'Will be deleted' as action
FROM stations 
WHERE (
    -- Not a PETRON station
    (LOWER(name) NOT LIKE '%petron%' AND LOWER(name) NOT LIKE '%cdo%')
    
    -- Or has gibberish name (only lowercase letters, short length)
    OR (name REGEXP '^[a-z]+$' AND CHAR_LENGTH(name) < 15)
    
    -- Or has no location
    OR location IS NULL 
    OR location = ''
    OR location = 'NULL'
    
    -- Or is test data
    OR LOWER(name) LIKE '%test%'
    OR LOWER(name) LIKE '%dummy%'
    OR LOWER(name) LIKE '%sample%'
)
-- Don't delete if has admin assigned
AND id NOT IN (
    SELECT DISTINCT station_id 
    FROM users 
    WHERE station_id IS NOT NULL 
    AND role = 'admin'
)
ORDER BY id;

-- ══════════════════════════════════════════════════════════════
-- STEP 2: Count how many will be deleted
-- ══════════════════════════════════════════════════════════════

SELECT 
    COUNT(*) as will_be_deleted,
    (SELECT COUNT(*) FROM stations) as total_stations,
    (SELECT COUNT(*) FROM stations WHERE LOWER(name) LIKE '%petron%' OR LOWER(name) LIKE '%cdo%') as petron_stations
FROM stations 
WHERE (
    (LOWER(name) NOT LIKE '%petron%' AND LOWER(name) NOT LIKE '%cdo%')
    OR (name REGEXP '^[a-z]+$' AND CHAR_LENGTH(name) < 15)
    OR location IS NULL 
    OR location = ''
    OR location = 'NULL'
    OR LOWER(name) LIKE '%test%'
    OR LOWER(name) LIKE '%dummy%'
    OR LOWER(name) LIKE '%sample%'
)
AND id NOT IN (
    SELECT DISTINCT station_id 
    FROM users 
    WHERE station_id IS NOT NULL 
);

-- ══════════════════════════════════════════════════════════════
-- STEP 3: DELETE NOW (Run this after verifying above)
-- ══════════════════════════════════════════════════════════════

-- Delete related inventory first (prevents foreign key errors)
DELETE FROM inventory 
WHERE station_id IN (
    SELECT id FROM stations 
    WHERE (
        (LOWER(name) NOT LIKE '%petron%' AND LOWER(name) NOT LIKE '%cdo%')
        OR (name REGEXP '^[a-z]+$' AND CHAR_LENGTH(name) < 15)
        OR location IS NULL 
        OR location = ''
        OR location = 'NULL'
        OR LOWER(name) LIKE '%test%'
        OR LOWER(name) LIKE '%dummy%'
        OR LOWER(name) LIKE '%sample%'
    )
    AND id NOT IN (
        SELECT DISTINCT station_id 
        FROM users 
        WHERE station_id IS NOT NULL 
    )
);

-- Now delete the stations
DELETE FROM stations 
WHERE (
    -- Not a PETRON or CDO station
    (LOWER(name) NOT LIKE '%petron%' AND LOWER(name) NOT LIKE '%cdo%')
    
    -- Or has gibberish name
    OR (name REGEXP '^[a-z]+$' AND CHAR_LENGTH(name) < 15)
    
    -- Or has no location
    OR location IS NULL 
    OR location = ''
    OR location = 'NULL'
    
    -- Or is test data
    OR LOWER(name) LIKE '%test%'
    OR LOWER(name) LIKE '%dummy%'
    OR LOWER(name) LIKE '%sample%'
)
-- Safety: Don't delete if has admin
AND id NOT IN (
    SELECT DISTINCT station_id 
    FROM users 
    WHERE station_id IS NOT NULL 
    AND role = 'admin'
);

-- ══════════════════════════════════════════════════════════════
-- STEP 4: Verify deletion was successful
-- ══════════════════════════════════════════════════════════════

SELECT 
    COUNT(*) as remaining_stations,
    SUM(CASE WHEN LOWER(name) LIKE '%petron%' OR LOWER(name) LIKE '%cdo%' THEN 1 ELSE 0 END) as petron_stations,
    SUM(CASE WHEN (LOWER(name) NOT LIKE '%petron%' AND LOWER(name) NOT LIKE '%cdo%') THEN 1 ELSE 0 END) as still_invalid
FROM stations;

-- Show what's left
SELECT id, name, location, status 
FROM stations 
ORDER BY name 
LIMIT 20;

-- ══════════════════════════════════════════════════════════════
-- STEP 5: Reset auto increment (optional, for clean IDs)
-- ══════════════════════════════════════════════════════════════

ALTER TABLE stations AUTO_INCREMENT = 1;

-- ══════════════════════════════════════════════════════════════
-- ALTERNATIVE: Delete EVERYTHING except valid PETRON stations
-- ══════════════════════════════════════════════════════════════

-- Uncomment to use this more aggressive approach:
/*
-- Backup first!
CREATE TABLE stations_backup AS SELECT * FROM stations;

-- Delete everything that's not clearly a valid Petron station
DELETE FROM stations 
WHERE id NOT IN (
    SELECT id FROM (
        SELECT id FROM stations 
        WHERE (
            LOWER(name) LIKE '%petron%' 
            OR LOWER(name) LIKE '%cdo%'
        )
        AND location IS NOT NULL 
        AND location != ''
        AND location != 'NULL'
        AND status = 'Active'
    ) as valid_stations
);

-- Verify
SELECT COUNT(*) as valid_stations_remaining FROM stations;
*/

-- ══════════════════════════════════════════════════════════════
-- RESTORE FROM BACKUP (if something went wrong)
-- ══════════════════════════════════════════════════════════════

/*
-- Only use if you created backup and need to restore:
TRUNCATE TABLE stations;
INSERT INTO stations SELECT * FROM stations_backup;
DROP TABLE stations_backup;
*/
