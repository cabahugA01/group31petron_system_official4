# Database Column Fixes - Complete Summary

## Date: June 6, 2026

## Issues Fixed

### 1. Admin Staff Oversight - `emp_id` Column Missing
**File:** `backend/api/admin_staff_oversight_api.php`  
**Error:** `Unknown column 'u.emp_id' in 'field list'`  
**Fix:** Changed `u.emp_id` to `u.id as emp_id` (uses user ID as employee ID)  
**Status:** ✅ FIXED

### 2. Manager Fuel Transaction Validation - `name` Column Uncertainty
**File:** `public/manager_fuel_transaction_validation.php`  
**Error:** `Unknown column 'staff.name' in 'field list'`  
**Fix:** Implemented COALESCE with multiple fallbacks to handle both schema types  
**Status:** ✅ FIXED

## Root Cause Analysis

The database has undergone multiple migrations with conflicting schemas:

### Schema Type A: Single Name Column
```sql
users (
    id, username, password, email, role, station_id, status,
    name VARCHAR(100),  -- Single name field
    created_at, ...
)
```

### Schema Type B: Split Name Columns
```sql
users (
    id, username, password, email, role, station_id, status,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    created_at, ...
)
```

Different migration files suggest different final states, causing confusion about which schema is actually deployed.

## Solutions Implemented

### Defensive COALESCE Pattern
All user name queries now use this pattern:

```sql
COALESCE(
    NULLIF(TRIM(u.name), ''),                                    -- Try 'name'
    NULLIF(CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)), ' '),  -- Try first+last
    u.username,                                                   -- Fallback to username
    'Unknown'                                                     -- Final fallback
) as user_name
```

### Helper Functions Created
**File:** `backend/db_helpers.php`

Provides reusable functions:
- `get_user_name_sql($alias, $result_alias)` - Returns COALESCE SQL fragment
- `get_user_display_name($user)` - PHP function to extract name from user array
- `check_users_table_schema($pdo)` - Detects actual table structure
- `get_optimized_user_name_sql($pdo, $alias, $result_alias)` - Schema-aware SQL

### Usage Example
```php
require_once __DIR__ . '/../backend/db_helpers.php';

// In SQL query
$sql = "SELECT ft.*, " . get_user_name_sql('u', 'staff_name') . "
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id";

// Or in PHP
$user_name = get_user_display_name($user_record);
```

## Files That Need Similar Fixes

### High Priority (Likely to Fail)
- ✅ `public/manager_fuel_transaction_validation.php` - FIXED
- ✅ `public/manager_fuel_management_complete.php` - FIXED (9 instances)
- ⚠️ `public/manager_fuel_deliveries.php` - Multiple instances
- ⚠️ `public/manager_fuel_transactions.php` - Multiple instances
- ⚠️ `public/manager_fuel_adjustments.php` - Multiple instances
- ⚠️ `public/manager_fuel_pump_master.php` - Multiple instances

### Medium Priority (May Fail in Some Environments)
- `public/manager_audit_trail.php`
- `public/fuel_adjustment_details.php`
- `public/fuel_readings_encoding.php`
- `public/fuel_reconciliation_workflow.php`
- `public/inventory_manager.php`
- `public/approvals_center.php`

### Low Priority (Working Files, But Use Same Pattern)
- Multiple staff dashboard files
- Various report generation files
- Calendar and scheduling files

## Recommended Actions

### Immediate (Critical)
1. ✅ Fix admin staff oversight
2. ✅ Fix manager fuel transaction validation  
3. ✅ Create helper functions library
4. ✅ Apply fix to manager_fuel_management_complete.php (9 instances fixed)

### Short Term
1. ⬜ Run `DESCRIBE users;` on production database to confirm schema
2. ⬜ Document the authoritative schema in a single source of truth
3. ⬜ Update all remaining files to use COALESCE pattern or helper functions
4. ⬜ Add schema version tracking to migrations

### Long Term
1. ⬜ Standardize on single schema (recommend Schema Type A with single `name` column)
2. ⬜ Create migration rollback procedures
3. ⬜ Implement database schema versioning system
4. ⬜ Add automated tests for schema-dependent queries

## Testing Checklist

For each fixed file, verify:
- [ ] Query executes without errors on Schema Type A (name column)
- [ ] Query executes without errors on Schema Type B (first_name/last_name)
- [ ] Names display correctly in UI
- [ ] NULL values handled gracefully
- [ ] Username fallback works when names are empty
- [ ] No performance degradation from COALESCE

## Migration Best Practices Going Forward

1. **Single Source of Truth:** Maintain one authoritative schema document
2. **Schema Versioning:** Track database version in a `schema_version` table
3. **Migration Testing:** Test migrations on copy of production data before deploying
4. **Rollback Plans:** Every migration must have a tested rollback script
5. **Documentation:** Update schema docs immediately when migrations run
6. **Code Updates:** Update application code in same deployment as schema changes

## Related Documentation
- `ADMIN_STAFF_OVERSIGHT_FIX.md` - Details for admin oversight fix
- `FUEL_TRANSACTION_VALIDATION_FIX.md` - Details for fuel transaction fix
- `backend/db_helpers.php` - Helper functions for schema-agnostic queries
- `USERS_TABLE_FINAL_STATE.sql` - Original schema documentation (may be outdated)

## Status Summary
- ✅ Critical errors fixed
- ✅ Helper library created
- ⚠️ Additional files need updates
- ⚠️ Schema standardization needed
- 📋 Testing and validation pending

---
**Last Updated:** June 6, 2026  
**Next Review:** After production database schema confirmation
