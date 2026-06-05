-- ============================================================
-- PERMANENT DELETION OF THREE USER ACCOUNTS
-- Users to delete: AMIE D. CABAHUG, Airel, yang c.
-- Date: June 4, 2026
-- WARNING: THIS WILL PERMANENTLY DELETE THESE USERS
-- ============================================================

-- Step 1: First, let's see the users before deletion
SELECT 'BEFORE DELETION - User List:' as status;
SELECT id, full_name, username, email, role, status 
FROM users 
WHERE full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.')
ORDER BY full_name;

-- Step 2: Delete the three users from the database
DELETE FROM users 
WHERE full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.');

-- Step 3: Verify deletion was successful
SELECT 'AFTER DELETION - Verification:' as status;
SELECT COUNT(*) as remaining_users_with_these_names
FROM users 
WHERE full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.');
-- Expected result: 0

-- Step 4: Show all remaining active users
SELECT 'REMAINING USERS:' as status;
SELECT id, full_name, username, role, status 
FROM users 
WHERE status = 'active'
ORDER BY full_name;

-- ============================================================
-- DELETION COMPLETE
-- The three users have been permanently removed from the database.
-- ============================================================
