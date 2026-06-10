# Fuel Adjustments - Complete Final Implementation

## Status: ✅ COMPLETED

## Overview
Comprehensive fuel adjustment system for both **Fuel Deliveries** and **Fuel Transactions** (Meter Readings) with admin oversight integration.

---

## Implementation Summary

### ✅ Tab 1: Fuel Deliveries Adjustments
**Purpose:** Correct delivery quantity discrepancies (DR vs Actual)

**Features:**
- Displays all 17 physical fuel tanks automatically
- Shows latest flagged delivery per fuel type
- Columns: Sel., Tank/Fuel Type, Supplier, Invoice/DR No., DR Quantity, Actual Quantity (input), Variance, Status
- Live variance calculation (color-coded: green +, red -)
- Auto-checkbox selection on variance detected
- Single "Save Adjustments" button (no per-row actions)
- Bulk adjustment capability
- Shared adjustment reason for all changes

**Database Updates:**
- `fuel_deliveries.delivery_liters` updated to actual value
- `fuel_deliveries.status` changed from "Pending"/"Flagged" → "Cleared"
- `fuel_deliveries.verified_by` = manager user_id
- `fuel_deliveries.verified_at` = NOW()
- `fuel_deliveries.notes` appended with adjustment reason
- `fuel_inventory.current_level` adjusted (actual - old)
- `audit_logs` entry created

---

### ✅ Tab 2: Fuel Transactions Adjustments
**Purpose:** Correct meter reading discrepancies (Computed vs Actual)

**Features:**
- Displays all 17 physical fuel tanks automatically
- Shows latest flagged transaction per fuel type
- Columns: Sel., Tank/Fuel Type, Transaction ID, Previous, Present, Calibration, Liters (Computed), Actual Liters (input), Variance, Status
- Live variance calculation (color-coded: green +, red -)
- Auto-checkbox selection on variance detected
- Single "Save Adjustments" button (no per-row actions)
- Bulk adjustment capability
- Shared adjustment reason for all changes

**Database Updates:**
- `fuel_transactions.liters_sold` updated to actual value
- `fuel_transactions.total_amount` recalculated (actual × price_per_liter)
- `fuel_transactions.status` changed from "Pending"/"Flagged" → "Cleared"
- `fuel_transactions.validated_by` = manager user_id
- `fuel_transactions.validated_at` = NOW()
- `fuel_transactions.reject_reason` = adjustment reason
- `fuel_inventory.current_level` adjusted (old - actual)
- `audit_logs` entry created

---

## Status Workflow

### Fuel Deliveries
```
Pending/Flagged → Manager Adjusts → Cleared → Admin Oversight
```

### Fuel Transactions
```
Pending/Flagged → Manager Adjusts → Cleared → Admin Oversight
```

### Status Definitions
- **Pending/Flagged**: Needs manager review and adjustment
- **Cleared**: Manager has adjusted, ready for admin oversight
- **Admin Verified**: (Future) Admin has reviewed the adjustment

---

## Admin Oversight Integration

### Requirements
All adjustments with status = "Cleared" must appear in **Admin Fuel Adjustments Oversight** page.

### Data to Display
1. **Date/Time** - When adjustment was made
2. **Station** - Which station
3. **Adjustment Type** - "Delivery" or "Transaction"
4. **Fuel Type** - Diesel, Kerosene, etc.
5. **Manager** - Who made the adjustment
6. **Original Value** - Before adjustment
7. **Adjusted Value** - After adjustment
8. **Variance** - Difference (± X L)
9. **Reason** - Manager's explanation
10. **Status** - Cleared

### SQL Query for Admin Oversight
```sql
-- Get all cleared delivery adjustments
SELECT 
    'Delivery' as adjustment_type,
    fd.id,
    fd.delivery_date as adjustment_date,
    s.name as station_name,
    fd.fuel_type,
    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as manager_name,
    u.username as manager_username,
    al.details, -- Contains old → new values
    fd.status,
    fd.verified_at as adjustment_timestamp,
    fd.notes as reason
FROM fuel_deliveries fd
JOIN stations s ON fd.station_id = s.id
LEFT JOIN users u ON fd.verified_by = u.id
LEFT JOIN audit_logs al ON al.entity_id = fd.id 
    AND al.entity_type = 'fuel_delivery' 
    AND al.action_type = 'Adjust'
WHERE fd.status = 'Cleared'
AND al.created_at IS NOT NULL

UNION ALL

-- Get all cleared transaction adjustments
SELECT 
    'Transaction' as adjustment_type,
    ft.id,
    ft.transaction_date as adjustment_date,
    s.name as station_name,
    ft.fuel_type,
    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as manager_name,
    u.username as manager_username,
    al.details, -- Contains old → new values
    ft.status,
    ft.validated_at as adjustment_timestamp,
    ft.reject_reason as reason
FROM fuel_transactions ft
JOIN stations s ON ft.station_id = s.id
LEFT JOIN users u ON ft.validated_by = u.id
LEFT JOIN audit_logs al ON al.entity_id = ft.id 
    AND al.entity_type = 'fuel_transaction' 
    AND al.action_type = 'Adjust'
WHERE ft.status = 'Cleared'
AND al.created_at IS NOT NULL

ORDER BY adjustment_timestamp DESC
```

### Audit Log Details Format
```
Delivery Adjusted for Diesel - Invoice: INV-2024-123 - Supplier: Petron Corporation - Liters: 10000.00 L → 9950.00 L - Variance: -50.00 L - Reason: DR variance, actual dipstick reading confirmed

Meter Reading Adjusted for Kerosene - Transaction: FUEL2026125343720 - Liters: 500.00 L → 450.00 L - Variance: -50.00 L - Reason: Calibration test error, physical verification confirms 450 L
```

---

## POST Actions Implemented

### 1. `bulk_adjust_deliveries`
**Handles:** Multiple delivery adjustments at once

**Process:**
1. Validates input (delivery_ids, actual_qty[], adjustment_reason)
2. Loops through selected deliveries
3. Fetches current delivery data
4. Calculates variance
5. Skips if variance < 0.01
6. Updates delivery_liters, status, verified_by, verified_at, notes
7. Adjusts inventory (actual - old)
8. Logs to audit_logs
9. Returns count of adjustments made

### 2. `bulk_adjust_transactions`
**Handles:** Multiple transaction adjustments at once

**Process:**
1. Validates input (transaction_ids, actual_liters[], adjustment_reason)
2. Loops through selected transactions
3. Fetches current transaction data
4. Calculates variance
5. Skips if variance < 0.01
6. Updates liters_sold, total_amount, status, validated_by, validated_at, reject_reason
7. Adjusts inventory (old - actual)
8. Logs to audit_logs
9. Returns count of adjustments made

---

## JavaScript Functions

### Delivery Functions
- `selectDeliveryRow(idx, drQty)` - Highlights selected row
- `calculateDeliveryVariance(idx, drQty)` - Calculates and displays variance, auto-checks checkbox

### Transaction Functions
- `selectTransactionRow(idx, computedLiters)` - Highlights selected row
- `calculateTransactionVariance(idx, computedLiters)` - Calculates and displays variance, auto-checks checkbox

### Variance Display Logic
```javascript
if (variance > 0) {
    // Green, positive
    display = '<span style="color:#16a34a;">+X.XX L</span>';
} else if (variance < 0) {
    // Red, negative
    display = '<span style="color:#dc2626;">X.XX L</span>';
} else {
    // Gray, no change
    display = '<span style="color:#94a3b8;">—</span>';
    checkbox.checked = false; // Auto-uncheck
}
```

---

## User Workflow

### Manager Side
1. Navigate to **Fuel Management** > **Adjustments**
2. Select tab: **Fuel Deliveries** or **Fuel Transactions**
3. View all 17 fuel types with flagged entries highlighted
4. Review discrepancies (DR vs Actual, Computed vs Actual)
5. Enter corrected values in "Actual Quantity" or "Actual Liters" columns
6. Variance auto-calculates, checkboxes auto-select
7. Enter adjustment reason (applies to all selected)
8. Click **"Save Adjustments"** button
9. System processes all checked rows
10. Status changes to "Cleared"
11. Inventory updated automatically
12. Audit log created

### Admin Side (Future/To Implement)
1. Navigate to **Admin Fuel Adjustments Oversight**
2. View all "Cleared" adjustments from all stations
3. Filter by: Date Range, Station, Fuel Type, Adjustment Type, Manager
4. Review adjustment details (original, adjusted, variance, reason)
5. Approve/Flag for further review (optional future feature)
6. Export to Excel/PDF for records

---

## Sample Data

### Delivery Adjustment Example
```
Tank: DIESEL 1 - 1 (Underground Tank #1)
Supplier: Petron Corporation
Invoice: INV-2024-123
DR Quantity: 10,000.00 L
Actual Quantity: 9,950.00 L (manager input)
Variance: -50.00 L (auto-calculated)
Reason: DR variance, actual dipstick reading confirmed at 9,950 L
Status: Pending → Cleared
```

### Transaction Adjustment Example
```
Tank: KEROSENE - 1 (Underground Tank #7)
Transaction ID: FUEL2026125343720
Previous Reading: 5,000.00 L
Present Reading: 5,500.00 L
Calibration: 100.00 L (incorrect)
Liters (Computed): 400.00 L (5,500 - 5,000 - 100)
Actual Liters: 450.00 L (manager input - correct calibration was 50 L)
Variance: +50.00 L (auto-calculated)
Reason: Calibration test error, correct value is 50 L not 100 L
Status: Pending → Cleared
```

---

## Testing Checklist

### Fuel Deliveries
- [x] Displays all 17 fuel types
- [x] Pulls latest flagged delivery per type
- [x] Disabled inputs for types without deliveries
- [x] Active inputs for types with deliveries
- [x] Variance calculation works
- [x] Checkbox auto-selects on variance
- [x] Bulk save processes multiple rows
- [x] Inventory updates correctly
- [x] Audit logs created
- [x] Status changes to Cleared

### Fuel Transactions
- [x] Displays all 17 fuel types
- [x] Pulls latest flagged transaction per type
- [x] Disabled inputs for types without transactions
- [x] Active inputs for types with transactions
- [x] Variance calculation works
- [x] Checkbox auto-selects on variance
- [x] Bulk save processes multiple rows
- [x] Inventory updates correctly
- [x] Audit logs created
- [x] Status changes to Cleared

### Integration
- [ ] Admin oversight page displays cleared adjustments
- [ ] Filtering works (date, station, type, manager)
- [ ] Export to Excel/PDF functions
- [ ] Historical tracking complete

---

## Next Steps

1. **Create Admin Oversight Page** - `public/admin_fuel_adjustments_oversight.php`
2. **Implement Filters** - Date range, station, fuel type, manager
3. **Add Export Functions** - Excel, PDF download
4. **Testing** - End-to-end workflow validation
5. **Documentation** - User training materials

---

## Files Modified

- ✅ `public/manager_fuel_adjustments.php` - Main implementation
  - Added `bulk_adjust_deliveries` POST action
  - Added `bulk_adjust_transactions` POST action
  - Replaced Tab 1 (Fuel Deliveries) content
  - Replaced Tab 2 (Fuel Transactions) content
  - Added JavaScript functions for both tabs
  - Updated SQL queries for flagged entries

---

## Database Schema Notes

### No Schema Changes Required
All functionality uses existing columns:
- `fuel_deliveries.status` - "Pending"/"Flagged" → "Cleared"
- `fuel_transactions.status` - "Pending"/"Flagged" → "Cleared"
- `audit_logs` - Tracking all adjustments

### Optional Future Enhancement
Add dedicated adjustment tracking table:
```sql
CREATE TABLE fuel_adjustment_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('delivery', 'transaction'),
    entity_id INT NOT NULL,
    station_id INT NOT NULL,
    fuel_type VARCHAR(100),
    original_value DECIMAL(10,2),
    adjusted_value DECIMAL(10,2),
    variance DECIMAL(10,2),
    adjustment_reason TEXT,
    adjusted_by INT,
    adjusted_at DATETIME,
    admin_verified_by INT,
    admin_verified_at DATETIME,
    admin_status ENUM('pending', 'approved', 'flagged'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

**Implementation Completed:** June 10, 2026  
**Status:** Manager adjustments functional, Admin oversight pending  
**Ready for:** Testing and Admin page creation
