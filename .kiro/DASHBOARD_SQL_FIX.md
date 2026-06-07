# Manager Dashboard - SQL Reserved Keyword Fix

## Issue: SQL Syntax Error on Line 236

### Error Message
```
Fatal error: Uncaught PDOException: SQLSTATE[42000]: Syntax error or access violation: 
1064 You have an error in your SQL syntax near 'delayed FROM deliveries_oversight...'
```

### Root Cause
The word `delayed` is a **reserved keyword** in MySQL/MariaDB and cannot be used as a column alias without escaping.

### Location
- **File**: `public/manager_dashboard.php`
- **Line**: 236 (in supplier performance query)

### Problem Code
```php
SUM(CASE WHEN DATEDIFF(delivery_date, expected_date) > 0 THEN 1 ELSE 0 END) AS delayed
```

### Fixed Code
```php
SUM(CASE WHEN DATEDIFF(delivery_date, expected_date) > 0 THEN 1 ELSE 0 END) AS `delayed`
```

### Solution
Wrapped the `delayed` alias with backticks (`) to escape the reserved keyword.

---

## MySQL/MariaDB Reserved Keywords

Common reserved keywords that need escaping:
- `delayed`
- `order`
- `key`
- `check`
- `option`
- `group`
- `table`
- `index`
- `alter`
- `drop`

**Best Practice**: Always use backticks for column aliases to avoid reserved keyword conflicts.

---

## Fix Applied

✅ **Status**: FIXED  
✅ **Date**: June 7, 2026  
✅ **Verification**: PHP syntax check passed  

The dashboard should now load without SQL errors.

---

## Related Queries Checked

All other queries in the dashboard have been verified and do not have reserved keyword issues:
- ✅ `AS date` - Safe (using DATE() function)
- ✅ `AS ontime` - Safe (not a reserved keyword)
- ✅ `AS delayed` - FIXED (now escaped)
- ✅ All other aliases verified

---

## Testing Required

Please test the dashboard:
1. Access: `http://localhost/group31petron_system_official4/public/manager_dashboard.php`
2. Verify all charts load without errors
3. Check browser console for JavaScript errors
4. Verify supplier performance data displays correctly

---

**Status**: ✅ FIXED & READY FOR TESTING
