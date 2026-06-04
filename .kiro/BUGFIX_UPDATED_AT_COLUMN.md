# Bug Fix: Updated_At Column Error in Stock-In Submission

## Issue Description
**Error Message:**
```
Server error: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'updated_at' in 'field list'
```

**Location:** Stock-In submission process (merchandise stock-in)

**Root Cause:** 
The `stock_requests` table in the production database was missing the `updated_at` column, or the query was explicitly setting it when the column has an automatic `ON UPDATE CURRENT_TIMESTAMP` trigger.

---

## Fix Applied

### 1. Removed Explicit `updated_at` Reference
**File:** `backend/api/merchandise_stock_in.php`

**Changed:**
```php
// OLD - Explicitly setting updated_at
$pdo->prepare("UPDATE stock_requests SET status = 'Received', updated_at = NOW() WHERE id = ?")
    ->execute([$po['request_id']]);
```

**To:**
```php
// NEW - Let the ON UPDATE trigger handle it automatically
$pdo->prepare("UPDATE stock_requests SET status = 'Received' WHERE id = ?")
    ->execute([$po['request_id']]);
```

**Rationale:**
- The `stock_requests` table has `ON UPDATE CURRENT_TIMESTAMP` trigger on `updated_at`
- Explicitly setting it is redundant and causes errors if the column doesn't exist
- Let MySQL handle the timestamp automatically

---

### 2. Added Defensive Schema Migration
**File:** `backend/api/merchandise_stock_in.php`

**Added after purchase_orders column checks:**
```php
// Ensure stock_requests table has updated_at column (defensive schema check)
try {
    $pdo->exec("ALTER TABLE stock_requests ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
} catch (Exception $e) {
    // Column may already exist or table may not exist yet
    error_log("stock_requests updated_at column check: " . $e->getMessage());
}
```

**Purpose:**
- Automatically adds the `updated_at` column if missing
- Defensive programming: prevents future errors
- Non-fatal: caught exception won't break the application

---

## Database Schema Reference

### Correct `stock_requests` Table Schema
```sql
CREATE TABLE `stock_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `station_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_sku` varchar(100) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_category` varchar(100) NOT NULL,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `requested_quantity` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('Pending','Approved','Validated') DEFAULT 'Pending',
  `approved_quantity` int(11) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `manager_notes` text DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Key Column:**
- `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
- Automatically updates when any row is modified
- Should NOT be explicitly set in UPDATE queries

---

## Testing Instructions

### 1. Verify Column Exists
Run this SQL query in phpMyAdmin or MySQL client:
```sql
SHOW COLUMNS FROM stock_requests LIKE 'updated_at';
```

**Expected Result:**
```
Field       | Type      | Null | Key | Default             | Extra
updated_at  | timestamp | NO   |     | CURRENT_TIMESTAMP   | on update CURRENT_TIMESTAMP
```

### 2. Test Stock-In Submission
1. Navigate to **Staff Dashboard → Inventory → Stock-In**
2. Select a pending merchandise delivery
3. Enter actual received quantity
4. Select condition (Good/Damaged/Short/Excess)
5. Click "Submit Stock-In"
6. **Expected:** Success message "Stock-In complete. Inventory updated for [Product Name]."
7. **Verify:** Stock request status changes to "Received" automatically

### 3. Verify Timestamp Auto-Update
Run this SQL query before and after stock-in submission:
```sql
SELECT id, status, created_at, updated_at 
FROM stock_requests 
WHERE id = [REQUEST_ID];
```

**Expected Behavior:**
- Before: `updated_at` = original timestamp
- After: `updated_at` = current timestamp (auto-updated)

---

## Related Files Modified

1. **backend/api/merchandise_stock_in.php**
   - Removed explicit `updated_at = NOW()` from UPDATE query
   - Added defensive schema migration for `updated_at` column

---

## Prevention Strategy

### Best Practices for Future Development

1. **Let Database Triggers Handle Timestamps**
   - Don't explicitly set `updated_at` in queries
   - Use `ON UPDATE CURRENT_TIMESTAMP` in table schema
   - Only set `updated_at` manually if you need a specific timestamp

2. **Defensive Schema Checks**
   - Add `ALTER TABLE ADD COLUMN IF NOT EXISTS` checks at file startup
   - Wrap in try-catch to avoid breaking on duplicate column errors
   - Log errors for debugging without breaking functionality

3. **Schema Consistency**
   - Keep `database/petron_pos_db_secure.sql` as source of truth
   - Run schema migrations when deploying to new environments
   - Test on staging database before production deployment

---

## Rollback Plan

If issues persist after this fix:

1. **Check table exists:**
   ```sql
   SHOW TABLES LIKE 'stock_requests';
   ```

2. **Verify table structure:**
   ```sql
   DESCRIBE stock_requests;
   ```

3. **If column is missing, add manually:**
   ```sql
   ALTER TABLE stock_requests 
   ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
   ```

4. **If table doesn't exist, recreate from schema:**
   - Use `database/petron_pos_db_secure.sql` lines 19883-19920
   - Run CREATE TABLE statement in phpMyAdmin

---

## Status
✅ **FIXED** - June 4, 2026

**Changes:**
- Removed explicit `updated_at` reference in UPDATE query
- Added defensive schema migration
- Documented fix and testing procedures

**Next Steps:**
- Monitor production logs for any related errors
- Verify fix on all environments (dev, staging, production)
- Update deployment checklist to include schema verification
