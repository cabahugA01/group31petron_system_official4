-- ============================================================
-- DELETE SPECIFIC USER ACCOUNTS
-- Date: June 4, 2026
-- WARNING: This is a DESTRUCTIVE operation. Backup first!
-- ============================================================

-- Step 1: Verify users exist before deletion
SELECT id, full_name, username, email, role, status 
FROM users 
WHERE full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.')
ORDER BY full_name;

-- Step 2: Check if users have related records
-- (Review these counts before proceeding)

-- Check transactions
SELECT u.full_name, COUNT(t.id) as transaction_count
FROM users u
LEFT JOIN merchandise_transactions t ON t.staff_id = u.id
WHERE u.full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.')
GROUP BY u.full_name;

-- Check deliveries
SELECT u.full_name, COUNT(d.id) as delivery_count
FROM users u
LEFT JOIN fuel_deliveries d ON d.encoded_by = u.id
WHERE u.full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.')
GROUP BY u.full_name;

-- Check audit logs
SELECT u.full_name, COUNT(a.id) as audit_count
FROM users u
LEFT JOIN audit_logs a ON a.user_id = u.id
WHERE u.full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.')
GROUP BY u.full_name;

-- ============================================================
-- OPTION A: SOFT DELETE (RECOMMENDED)
-- This preserves data integrity and audit trails
-- ============================================================

-- Set users to inactive/archived status
UPDATE users 
SET status = 'inactive',
    is_archived = 1,
    updated_at = NOW()
WHERE full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.');

-- Verify soft delete
SELECT id, full_name, username, status, is_archived
FROM users 
WHERE full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.');

-- ============================================================
-- OPTION B: HARD DELETE (NOT RECOMMENDED)
-- Only use if you absolutely must remove records permanently
-- This may cause referential integrity issues
-- ============================================================

-- IMPORTANT: Uncomment and run these ONLY if you understand the implications

-- Delete from users table
-- DELETE FROM users 
-- WHERE full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.');

-- Verify deletion
-- SELECT COUNT(*) as deleted_count
-- FROM users 
-- WHERE full_name IN ('AMIE D. CABAHUG', 'Airel', 'yang c.');

-- Expected result: deleted_count = 0

-- ============================================================
-- ROLLBACK PLAN (if using transactions)
-- ============================================================

-- If you want to use transactions for safety:
-- START TRANSACTION;
-- [Run your DELETE statements here]
-- If everything looks good: COMMIT;
-- If something went wrong: ROLLBACK;

-- ============================================================
-- POST-DELETION CLEANUP (if hard delete was used)
-- ============================================================

-- These may be needed if you did hard delete:
-- Update foreign key references to NULL where users were deleted
-- UPDATE merchandise_transactions SET staff_id = NULL WHERE staff_id NOT IN (SELECT id FROM users);
-- UPDATE fuel_deliveries SET encoded_by = NULL WHERE encoded_by NOT IN (SELECT id FROM users);
-- UPDATE audit_logs SET user_id = NULL WHERE user_id NOT IN (SELECT id FROM users);

-- ============================================================
-- NOTES:
-- 1. ALWAYS backup database before deletion
-- 2. SOFT DELETE (Option A) is recommended
-- 3. HARD DELETE (Option B) may break referential integrity
-- 4. Review related records counts before proceeding
-- 5. Consider archiving instead of deleting
-- ============================================================
