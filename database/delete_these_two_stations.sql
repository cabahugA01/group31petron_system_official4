-- ============================================================
-- Delete Specific Stations: AEVDZVCB and PETRON CDO - KAUSWAGAN
-- database/delete_these_two_stations.sql
-- ============================================================

-- ⚠️ WARNING: This will PERMANENTLY delete these stations!
-- BACKUP your database first: phpMyAdmin → Export

-- ══════════════════════════════════════════════════════════════
-- STEP 1: Preview which stations will be deleted
-- ══════════════════════════════════════════════════════════════

SELECT 
    id,
    name,
    location,
    status,
    'Will be deleted' as action
FROM stations
WHERE name IN (
    'AEVDZVCB',
    'PETRON CDO - KAUSWAGAN'
)
ORDER BY name;

-- ══════════════════════════════════════════════════════════════
-- STEP 2: Check if they have assigned admins
-- ══════════════════════════════════════════════════════════════

SELECT 
    s.id,
    s.name,
    COUNT(u.id) as admin_count,
    GROUP_CONCAT(CONCAT(u.first_name, ' ', u.last_name)) as admins
FROM stations s
LEFT JOIN users u ON u.station_id = s.id AND u.role = 'admin'
WHERE s.name IN (
    'AEVDZVCB',
    'PETRON CDO - KAUSWAGAN'
)
GROUP BY s.id, s.name;

-- If admin_count > 0, you need to unassign admins first!

-- ══════════════════════════════════════════════════════════════
-- STEP 3: DELETE NOW (Run after verifying above)
-- ══════════════════════════════════════════════════════════════

-- Delete related inventory first
DELETE FROM inventory 
WHERE station_id IN (
    SELECT id FROM stations 
    WHERE name IN (
        'AEVDZVCB',
        'PETRON CDO - KAUSWAGAN'
    )
);

-- Delete the stations
DELETE FROM stations
WHERE name IN (
    'AEVDZVCB',
    'PETRON CDO - KAUSWAGAN'
);

-- Check how many were deleted (should show "2 rows affected")

-- ══════════════════════════════════════════════════════════════
-- STEP 4: Verify deletion
-- ══════════════════════════════════════════════════════════════

-- Try to find these stations (should return 0 rows)
SELECT * FROM stations
WHERE name IN (
    'AEVDZVCB',
    'PETRON CDO - KAUSWAGAN'
);

-- If 0 rows = Successfully deleted! ✅

-- ══════════════════════════════════════════════════════════════
-- STEP 5: Check remaining stations
-- ══════════════════════════════════════════════════════════════

SELECT COUNT(*) as total_remaining_stations FROM stations;

-- ══════════════════════════════════════════════════════════════
-- ALTERNATIVE: Delete by ID if you know the IDs
-- ══════════════════════════════════════════════════════════════

-- First find the IDs
/*
SELECT id, name FROM stations
WHERE name IN (
    'AEVDZVCB',
    'PETRON CDO - KAUSWAGAN'
);
*/

-- Then delete by ID (replace XXX and YYY with actual IDs)
/*
DELETE FROM stations WHERE id IN (XXX, YYY);
*/

-- ══════════════════════════════════════════════════════════════
-- NOTES
-- ══════════════════════════════════════════════════════════════

/*
Station 1: AEVDZVCB
- Gibberish name
- Invalid address "b nbcnb"
- Should be deleted ✓

Station 2: PETRON CDO - KAUSWAGAN
- User says "wala nay labot" (not relevant)
- Should be deleted ✓

Both stations will be permanently removed from database.
*/
