# Fuel Inventory Summary Cards Fix

## Problem

The summary cards at the top of the Fuel Inventory page were showing incorrect totals because:

1. **Database Query Issue**: The fuel inventory was queried by `fuel_type` only, but there are multiple tanks per fuel type (e.g., Diesel 1, Diesel 2)
2. **Incorrect Division**: The code was dividing the current_level by the number of same-type tanks, assuming the database stored totals
3. **Wrong Aggregation**: This caused the summary card to show incorrect "Total Fuel Available"

## What Was Fixed

### 1. Enhanced Database Query ✅
**File**: `public/admin_inventory_fuel.php`

**Before**:
```php
// Only queried by fuel_type
SELECT fuel_type, current_level FROM fuel_inventory WHERE station_id=?
```

**After**:
```php
// Now includes tank identification
SELECT fuel_type, tank_number, tank_name, current_level FROM fuel_inventory WHERE station_id=?
// Creates composite keys: fuel_type_tank_1, diesel_tank_2, etc.
```

### 2. Improved Tank Lookup Logic ✅
**Priority order for finding each tank's data**:
1. Try `fuel_type + tank_number` (e.g., "diesel_tank_1")
2. Try `fuel_type + tank_name` (e.g., "diesel_underground tank #1")
3. Try numbered variants (e.g., "diesel 1", "diesel 2")
4. Fall back to `fuel_type` only

### 3. Fixed Current Level Calculation ✅
**Before**:
```php
$beginning = $same_n > 0 ? round($cur_level / $same_n, 2) : 0;
// ❌ Divided by number of same-type tanks
```

**After**:
```php
$beginning = $cur_level;
// ✅ Uses individual tank's level directly
```

### 4. Removed Capacity Cap on Ending ✅
**Before**:
```php
$ending = min(max(0, $total_avail - $sales - $calibration), $capacity);
// ❌ Capped at capacity, hiding over-fill issues
```

**After**:
```php
$ending = max(0, $total_avail - $sales - $calibration);
// ✅ Shows actual level, even if over capacity
```

## Summary Card Calculations

### Total Tanks
```php
$total_tanks = count($rows);
```
✅ **Correct** - Counts all 7 tank rows

### Total Fuel Available
```php
$total_fuel_available = array_sum(array_column($rows, 'current_level'));
```
✅ **Now Correct** - Sums each tank's individual current_level

### Low Fuel Tanks
```php
$total_low_fuel_tanks = count(array_filter($rows, fn($r) => $r['status'] === 'Low'));
```
✅ **Correct** - Counts tanks with "Low" status

### Critical Fuel Tanks
```php
$total_critical_fuel_tanks = count(array_filter($rows, fn($r) => in_array($r['status'], ['Critical','Out of Stock'])));
```
✅ **Correct** - Counts tanks with "Critical" or "Out of Stock" status

## Expected Results

Based on your screenshot showing 7 tanks:

| Tank No. | Fuel Type    | Current Level | Status   |
|----------|--------------|---------------|----------|
| 1        | Diesel       | 600.0 L       | Critical |
| 2        | Diesel       | 14,000.00 L   | Normal   |
| 3        | XCS Plus     | 14,000.00 L   | Normal   |
| 4        | Xtra UNL     | 7,000.00 L    | Normal   |
| 5        | Turbo Diesel | 7,000.00 L    | Normal   |
| 6        | Xtra UNL     | 14,000.00 L   | Normal   |
| 7        | Kerosene     | 14,000.00 L   | Normal   |

**Expected Totals**:
- **Total Tanks**: 7 ✅
- **Total Fuel Available**: 600 + 14,000 + 14,000 + 7,000 + 7,000 + 14,000 + 14,000 = **70,600.00 L** ✅
- **Low Fuel Tanks**: 0 ✅ (unless thresholds define some as "Low")
- **Critical Fuel Tanks**: 1 ✅ (Tank #1 Diesel at 600 L)

## Testing Instructions

### 1. Clear All Cache
```
Hard refresh: Ctrl + Shift + R (Windows) or Cmd + Shift + R (Mac)
```

### 2. Reload the Page
Navigate to:
```
http://localhost/group31petron_system_official4/public/admin_inventory_fuel.php
```

### 3. Verify Summary Cards

**Check each card shows**:
- Total Tanks: **7**
- Total Fuel Available: **Sum of all Current Level values**
- Low Fuel Tanks: **Count of tanks with "Low" status**
- Critical Fuel Tanks: **Count of tanks with "Critical" or "Out of Stock" status**

### 4. Manual Verification

Add up all the "Current Level" values from the table manually:
```
Tank 1: 600.00
Tank 2: 14,000.00
Tank 3: 14,000.00
Tank 4: 7,000.00
Tank 5: 7,000.00
Tank 6: 14,000.00
Tank 7: 14,000.00
─────────────────
Total: 70,600.00 L
```

Compare with "Total Fuel Available" card - they should match exactly!

### 5. Check Individual Tank Calculations

For each tank, verify:
- **Current Level** = Beginning + Purchases - Sales - Calibration
- **Available %** = (Current Level / Capacity) × 100
- **Status** matches the level thresholds:
  - Out of Stock: 0 L
  - Critical: ≤ 10% of capacity (or custom thresholds)
  - Low: ≤ 20% of capacity (or custom thresholds)
  - Normal: > 20% of capacity

## Database Structure Notes

### Required Columns
The `fuel_inventory` table should have:
```sql
- id (primary key)
- station_id
- fuel_type (e.g., "Diesel", "Kerosene")
- tank_number (optional, e.g., 1, 2, 3)
- tank_name (optional, e.g., "Underground Tank #1")
- current_level (liters)
- current_stock (fallback if current_level is null)
- capacity (liters)
- price_per_liter
- status
- last_updated
```

### Data Requirements
- Each tank should have its **own row** in the database
- `current_level` should be the **individual tank's level**, not a total
- If you have 2 Diesel tanks, there should be 2 rows:
  - Row 1: fuel_type="Diesel", tank_number=1, current_level=600
  - Row 2: fuel_type="Diesel", tank_number=2, current_level=14000

### Migration Note
If your database currently stores **totals per fuel type** instead of **individual tank levels**, you'll need to:

1. Add `tank_number` column if it doesn't exist
2. Split total values into individual tank records
3. Update your data entry processes to record per-tank levels

## Troubleshooting

### Issue: Total Still Wrong After Fix

**Check 1: Database Structure**
```sql
SELECT fuel_type, tank_number, tank_name, current_level 
FROM fuel_inventory 
WHERE station_id = YOUR_STATION_ID;
```

**Expected**: Should see 7 rows (one per tank)
**If not**: You might need to restructure your database

**Check 2: Console Errors**
Open F12 → Console tab
Look for PHP errors or warnings

**Check 3: Cached Data**
- Clear browser cache completely
- Try in incognito/private window
- Hard refresh (Ctrl+Shift+R)

### Issue: Some Tanks Show 0.00 L

**Possible causes**:
1. Database has NULL values → Fix: Set default values
2. Tank lookup key doesn't match → Fix: Check tank_number/tank_name fields
3. Wrong fuel_type spelling → Fix: Standardize fuel type names

**Debug**:
Add this after the $rows array is built:
```php
echo '<pre style="background:#f0f0f0;padding:10px;margin:10px;border-radius:5px;">';
echo "DEBUG: Fuel Inventory Lookup\n";
print_r($fi_lookup);
echo "\nDEBUG: Calculated Rows\n";
print_r($rows);
echo '</pre>';
```

## Files Modified

- `public/admin_inventory_fuel.php` - Enhanced tank lookup and calculations

## Success Criteria

✅ Summary card "Total Fuel Available" matches sum of all tank levels
✅ Each tank shows its individual current level correctly
✅ Status badges match the actual fuel levels
✅ Low/Critical tank counts are accurate
✅ No division errors in calculations
✅ No PHP errors in console or logs

## Related Documentation

- See `FUEL_INVENTORY_FIX.md` for export function fixes
- See `HEADER_NAVIGATION_FIX.md` for header button fixes
