# Stock-In Bug Fix - Testing Guide

**Bug:** "Unknown column 'updated_at'" error during stock-in submission  
**Fix Date:** June 4, 2026  
**Status:** ✅ Fixed

---

## Quick Test (2 minutes)

### Step 1: Run Migration Script
1. Open phpMyAdmin
2. Select your database
3. Click **SQL** tab
4. Paste this query:

```sql
-- Check if updated_at column exists
SHOW COLUMNS FROM stock_requests LIKE 'updated_at';
```

**Expected Result:**
- If column exists → You'll see a row with column details
- If column missing → No rows returned

### Step 2: Add Column (If Missing)
If the column doesn't exist, run this:

```sql
ALTER TABLE stock_requests 
ADD COLUMN updated_at 
TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
AFTER created_at;
```

### Step 3: Verify Fix
1. Go to **Staff Dashboard → Inventory → Stock-In**
2. Find a pending merchandise delivery
3. Click "Submit Stock-In"
4. **Expected:** Success message (no error)

---

## Detailed Test Procedure

### Pre-Test Checklist
- [ ] Backup database before testing
- [ ] Have phpMyAdmin access ready
- [ ] Have test stock request data ready
- [ ] Clear browser cache

### Test Case 1: Verify Schema
**Objective:** Confirm `updated_at` column exists and is configured correctly

**Steps:**
1. Open phpMyAdmin
2. Navigate to your database
3. Find `stock_requests` table
4. Click **Structure** tab
5. Look for `updated_at` column

**Expected Result:**
```
Field: updated_at
Type: timestamp
Null: NO
Default: CURRENT_TIMESTAMP
Extra: on update CURRENT_TIMESTAMP
```

**Pass Criteria:** Column exists with correct configuration

---

### Test Case 2: Submit Merchandise Stock-In
**Objective:** Verify stock-in submission works without errors

**Prerequisites:**
- At least one validated stock request linked to a PO
- PO must be admin-finalized and manager-validated

**Steps:**
1. Login as **Staff**
2. Navigate to **Inventory → Stock-In**
3. Click **Merchandise** tab
4. Select **Pending Stock-In** tab
5. Find a pending delivery
6. Enter actual received quantity
7. Select condition (e.g., "Good")
8. Click **Submit Stock-In**

**Expected Result:**
- ✅ Success message: "Stock-In complete. Inventory updated for [Product Name]."
- ✅ Item moves to History tab
- ✅ No database error
- ✅ Inventory quantity increases

**Pass Criteria:** No error, successful submission

---

### Test Case 3: Verify Database Update
**Objective:** Confirm `updated_at` timestamp updates automatically

**Steps:**
1. Before stock-in, run this query:
```sql
SELECT id, item_name, status, created_at, updated_at 
FROM stock_requests 
WHERE status = 'Validated' 
LIMIT 1;
```

2. Note the `updated_at` timestamp

3. Submit stock-in for that request (follow Test Case 2)

4. After stock-in, run this query:
```sql
SELECT id, item_name, status, created_at, updated_at 
FROM stock_requests 
WHERE id = [REQUEST_ID];
```

**Expected Result:**
- Status changes from "Validated" to "Received"
- `updated_at` timestamp is newer than before
- `created_at` remains unchanged

**Pass Criteria:** `updated_at` automatically updated

---

### Test Case 4: Fuel Stock-In (Optional)
**Objective:** Verify fuel deliveries also work

**Steps:**
1. Login as **Staff**
2. Navigate to **Inventory → Stock-In**
3. Click **Fuel** tab
4. Select **Pending Stock-In** tab
5. Find a pending fuel delivery
6. Enter actual received liters
7. Select condition
8. Click **Submit Fuel Stock-In**

**Expected Result:**
- ✅ Success message
- ✅ Tank level updates
- ✅ No errors

**Pass Criteria:** Successful fuel stock-in

---

## Troubleshooting

### Issue: Column Still Missing After Migration
**Symptoms:** Migration script ran but column still not there

**Solution:**
```sql
-- Drop and recreate (use with caution!)
ALTER TABLE stock_requests DROP COLUMN IF EXISTS updated_at;
ALTER TABLE stock_requests 
ADD COLUMN updated_at 
TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
```

---

### Issue: Error "Table doesn't exist"
**Symptoms:** Cannot find `stock_requests` table

**Solution:**
```sql
-- Check if table exists
SHOW TABLES LIKE 'stock_requests';

-- If missing, create from schema
-- Use: database/petron_pos_db_secure.sql lines 19883-19920
```

---

### Issue: Still Getting "Unknown column" Error
**Symptoms:** Error persists after adding column

**Solutions:**
1. **Clear PHP OpCache:**
   - Restart Apache/PHP-FPM
   - Or add to `php.ini`: `opcache.enable=0` (testing only)

2. **Verify Column in Table:**
   ```sql
   DESCRIBE stock_requests;
   ```

3. **Check File Update:**
   - Verify `backend/api/merchandise_stock_in.php` has the fix
   - Line 350 should NOT have `updated_at = NOW()`

4. **Clear Browser Cache:**
   - Hard refresh: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)

---

## Database Verification Queries

### Check All Timestamp Columns
```sql
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    EXTRA
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'stock_requests'
  AND COLUMN_NAME IN ('created_at', 'updated_at');
```

### View Recent Stock Requests
```sql
SELECT 
    id,
    item_name,
    status,
    created_at,
    updated_at,
    TIMESTAMPDIFF(SECOND, created_at, updated_at) as seconds_diff
FROM stock_requests
ORDER BY updated_at DESC
LIMIT 10;
```

### Check Stock-In History
```sql
SELECT 
    msi.id,
    msi.product_name,
    msi.qty_received,
    msi.encoded_at,
    sr.updated_at as request_updated_at
FROM merchandise_stock_in msi
LEFT JOIN purchase_orders po ON msi.po_id = po.id
LEFT JOIN stock_requests sr ON po.request_id = sr.id
WHERE DATE(msi.encoded_at) = CURDATE()
ORDER BY msi.encoded_at DESC;
```

---

## Success Criteria

✅ **Fix is successful if:**
1. `updated_at` column exists in `stock_requests` table
2. Stock-in submission completes without errors
3. `updated_at` timestamp updates automatically
4. Inventory levels update correctly
5. No console errors in browser
6. No PHP errors in server logs

---

## Rollback Procedure

If issues persist and you need to rollback:

1. **Restore Database Backup:**
   ```bash
   mysql -u root -p your_database < backup_before_fix.sql
   ```

2. **Revert Code Changes:**
   - Restore `backend/api/merchandise_stock_in.php` from git:
   ```bash
   git checkout HEAD -- backend/api/merchandise_stock_in.php
   ```

3. **Clear Cache:**
   - Restart web server
   - Clear browser cache

4. **Report Issue:**
   - Document exact error message
   - Provide database structure output
   - Share server error logs

---

## Performance Check

After applying fix, verify performance hasn't degraded:

### Query Performance Test
```sql
-- Should execute in < 100ms
SELECT COUNT(*) 
FROM stock_requests 
WHERE updated_at > DATE_SUB(NOW(), INTERVAL 7 DAY);
```

### Index Verification
```sql
-- Check if indexes exist
SHOW INDEX FROM stock_requests;
```

**Expected Indexes:**
- PRIMARY on `id`
- Optional: index on `updated_at` for performance

---

## Post-Test Checklist

- [ ] Schema verified
- [ ] Stock-in submission tested (merchandise)
- [ ] Stock-in submission tested (fuel - optional)
- [ ] Database timestamps verified
- [ ] No errors in browser console
- [ ] No errors in PHP error logs
- [ ] Performance acceptable
- [ ] Documentation updated
- [ ] Team notified of fix

---

## Test Results Template

```
## Test Execution Report

**Date:** _____________________
**Tester:** ___________________
**Environment:** Production / Staging / Local

### Test Case 1: Schema Verification
- [ ] Pass  [ ] Fail
- Notes: _________________________________

### Test Case 2: Merchandise Stock-In
- [ ] Pass  [ ] Fail
- Notes: _________________________________

### Test Case 3: Database Update Verification
- [ ] Pass  [ ] Fail
- Notes: _________________________________

### Test Case 4: Fuel Stock-In (Optional)
- [ ] Pass  [ ] Fail
- Notes: _________________________________

### Overall Result:
- [ ] All tests passed - Fix verified
- [ ] Some tests failed - See notes
- [ ] Critical failure - Rollback recommended

**Sign-off:** _____________________
```

---

## Contact for Support

If you encounter issues during testing:

1. Check this guide first
2. Review main bug fix documentation: `BUGFIX_UPDATED_AT_COLUMN.md`
3. Check testing guide: `PO_GENERATION_TESTING_GUIDE.md`
4. Contact development team with:
   - Exact error message
   - Test case that failed
   - Database schema output
   - Server logs

---

**Last Updated:** June 4, 2026  
**Next Review:** After first production deployment  
**Status:** Ready for Testing ✅
