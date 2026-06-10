# Fuel Transaction Adjustment Table - Manager Side

## Status: 📋 SPECIFICATION

## Overview
Create a **Fuel Transaction Adjustment Table** specifically for manager-side corrections of fuel transactions that have discrepancies or have been flagged for review. This is distinct from the current validation page which handles shift approvals.

---

## User Requirements (Cebuano)

**Original Request:**
> "Fuel Transaction – Adjustment Table (Manager side)
> - Transaction ID → unique identifier sa transaction nga gi‑flag
> - Fuel Type → Diesel, Gasoline, Premium, etc.
> - Tanker Reference → specific tanker nga na‑assign
> - Encoded Liters → liters nga gi‑encode sa staff
> - Actual Liters (Manager Input) → field nga i‑input sa manager para sakto nga value
> - Variance Value (Auto‑compute) → system mo‑compute difference sa encoded ug actual liters
> - Reason (Manager Input) → manager mo‑encode ngano naay discrepancy (ex. calibration test, staff error)
> - Status (Auto‑update) → flagged → cleared or pending, depende sa adjustment
> sa fuel transaction rani ha"

**Translation:**
- Transaction ID: Unique identifier of flagged transaction
- Fuel Type: Diesel, Gasoline, Premium, etc.
- Tanker Reference: Specific tanker assigned
- Encoded Liters: Liters encoded by staff
- Actual Liters: Manager input field for correct value
- Variance: Auto-computed difference between encoded and actual
- Reason: Manager explains why there's a discrepancy (calibration test, staff error, etc.)
- Status: Auto-update from "flagged" to "cleared" or "pending" based on adjustment

---

## Implementation Options

### Option 1: Add New Tab to Fuel Adjustments Page
**File:** `public/manager_fuel_adjustments.php`

**Pros:**
- Keeps all fuel adjustments in one place
- Consistent UI and navigation
- Easy to find for managers

**Cons:**
- File is already complex with multiple tabs
- Different workflow from tank-level adjustments

### Option 2: Create Standalone Page
**File:** `public/manager_fuel_transaction_adjustments.php` (NEW)

**Pros:**
- Dedicated interface for transaction corrections
- Cleaner separation of concerns
- Can be accessed from multiple entry points

**Cons:**
- Another page to maintain
- Need to add navigation links

### **RECOMMENDED: Option 1** (Add New Tab)
- More discoverable for managers
- Maintains context (all fuel adjustments together)
- Easier to implement using existing infrastructure

---

## Database Considerations

### Current `fuel_transactions` Table
```sql
- id (Primary Key)
- transaction_id (Unique varchar(50))
- station_id
- pump_id
- fuel_type
- present_reading (Beginning)
- previous_reading (Ending)
- calibration
- liters_sold (Encoded Liters)
- total_amount
- staff_id
- status (varchar(50))
- reject_reason (text)
- notes (text)
- validated_by
- validated_at
```

### Potential New Columns Needed
```sql
ALTER TABLE fuel_transactions ADD COLUMN:
- is_flagged TINYINT(1) DEFAULT 0
- flagged_reason TEXT
- flagged_at DATETIME
- corrected_liters DECIMAL(10,2)
- variance_liters DECIMAL(10,2)
- adjustment_reason TEXT
- adjustment_by INT(11) -- references users.id
- adjustment_at DATETIME
```

### Alternative: Use Existing Columns
- Use `status` = 'Flagged' to identify flagged transactions
- Use `reject_reason` for flagged reason
- Use `notes` or `reject_reason` for adjustment reason
- Track adjustments in `fuel_adjustments` table

---

## UI/UX Design

### Table Columns (Manager View)
| Column | Type | Description | Editable |
|--------|------|-------------|----------|
| Transaction ID | Text | Unique identifier | No |
| Date | DateTime | Transaction date | No |
| Fuel Type | Text | Diesel, Kerosene, etc. | No |
| Pump/Tanker | Text | Reference number | No |
| Encoded Liters | Number | Staff input (from beginning/ending readings) | No |
| Actual Liters | Input Field | Manager correction | **Yes** |
| Variance | Auto-calc | `Actual - Encoded` | No |
| Reason | Textarea | Manager explanation | **Yes** |
| Status | Badge | Flagged/Cleared/Pending | Auto |
| Actions | Buttons | Save/Clear | - |

### Status Badge Colors
- **Flagged** (Red #dc2626): Transaction has discrepancy, awaits correction
- **Pending Review** (Orange #d97706): Manager made adjustment, pending final approval
- **Cleared** (Green #16a34a): Adjustment completed and approved

### Action Buttons
1. **Adjust Transaction** - Opens modal/inline form for manager input
2. **Clear Flag** - Mark as resolved without adjustment
3. **View Details** - Show full transaction breakdown

---

## Workflow

### 1. Transaction Gets Flagged
**Triggers:**
- Large variance between expected and actual readings
- Staff manually flags transaction
- Automated variance threshold exceeded (e.g., >5% difference)

**Action:**
- Set `status` = 'Flagged'
- Record `flagged_reason`
- Send notification to manager

### 2. Manager Reviews Flagged Transaction
**Manager Actions:**
- View original encoded values (beginning, ending, liters)
- Compare with physical verification/calibration test
- Enter corrected liters value
- Provide detailed reason for adjustment

### 3. System Auto-Computes
- **Variance** = Actual Liters - Encoded Liters
- Updates inventory impact
- Logs adjustment in audit trail

### 4. Status Update
- If adjustment made → Status = 'Cleared'
- If needs further review → Status = 'Pending Review'
- If no adjustment needed → Status = 'Verified'

---

## Form Layout (Adjustment Modal)

```
┌─────────────────────────────────────────────────┐
│ Adjust Fuel Transaction #FUEL2026125343720      │
│                                                  │
│ Transaction Details:                             │
│ • Date: May 08, 2026, 9:59 PM                   │
│ • Fuel Type: Diesel                              │
│ • Pump: Pump #3                                  │
│ • Shift: Second Shift (2PM - 12AM)              │
│ • Encoded By: Juan Dela Cruz                     │
│                                                  │
│ Readings:                                        │
│ • Beginning Reading: 49,999.00 L                 │
│ • Ending Reading: 49,999.00 L                    │
│ • Calibration: 10,000.00 L                       │
│ • Encoded Liters: 0.00 L (Calculated)           │
│                                                  │
│ Manager Adjustment:                              │
│ ┌────────────────────────────────────────────┐  │
│ │ Actual Liters (L): [___________]           │  │
│ │                                             │  │
│ │ Variance: -1,234.56 L (Auto-computed)      │  │
│ └────────────────────────────────────────────┘  │
│                                                  │
│ Adjustment Reason: (Required)                    │
│ ┌────────────────────────────────────────────┐  │
│ │ [Calibration test conducted. Physical       │  │
│ │  verification shows actual delivery was     │  │
│ │  1,234.56 L instead of encoded value...]   │  │
│ └────────────────────────────────────────────┘  │
│                                                  │
│ Reason Categories:                               │
│ ☐ Calibration Test Discrepancy                  │
│ ☐ Staff Encoding Error                          │
│ ☐ Meter Malfunction                             │
│ ☐ Spillage/Leakage                              │
│ ☐ Evaporation Loss                              │
│ ☐ Other (Specify above)                         │
│                                                  │
│ [Cancel]  [Save Adjustment & Clear Flag]        │
└─────────────────────────────────────────────────┘
```

---

## Implementation Steps

### Phase 1: Database Setup
1. Add new column `is_flagged` or use existing `status` column
2. Create SQL query to fetch flagged transactions
3. Test data insertion/retrieval

### Phase 2: Backend Logic (`manager_fuel_adjustments.php`)
1. Add POST action `adjust_flagged_transaction`
2. Validate manager inputs (actual liters, reason)
3. Calculate variance
4. Update transaction status
5. Update fuel inventory if needed
6. Log adjustment in audit trail

### Phase 3: UI Implementation
1. Add 4th tab "Flagged Transactions" after "Adjustment History"
2. Create table with all required columns
3. Build adjustment modal/form
4. Implement auto-calculation for variance
5. Add action buttons (Adjust, Clear, View Details)

### Phase 4: Testing
1. Create test flagged transactions
2. Test adjustment workflow end-to-end
3. Verify inventory updates correctly
4. Check audit logs
5. Test status transitions

---

## SQL Queries

### Fetch Flagged Transactions
```sql
SELECT 
    ft.id,
    ft.transaction_id,
    ft.transaction_date,
    ft.fuel_type,
    ft.pump_id,
    fp.pump_number,
    ft.previous_reading as beginning,
    ft.present_reading as ending,
    ft.calibration,
    ft.liters_sold as encoded_liters,
    ft.status,
    ft.reject_reason as flagged_reason,
    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as staff_name,
    u.username as staff_username,
    ft.notes
FROM fuel_transactions ft
LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
LEFT JOIN users u ON ft.staff_id = u.id
WHERE ft.station_id = ?
AND (ft.status = 'Flagged' OR ft.status LIKE '%flag%')
ORDER BY ft.transaction_date DESC, ft.created_at DESC
```

### Save Adjustment
```sql
UPDATE fuel_transactions
SET 
    liters_sold = ?,
    status = 'Cleared',
    reject_reason = ?,
    validated_by = ?,
    validated_at = NOW()
WHERE id = ? AND station_id = ?
```

### Log Adjustment
```sql
INSERT INTO fuel_adjustments 
(station_id, fuel_type_id, adjustment_type, liters, reason, user_id, adjustment_date)
VALUES (?, ?, 'transaction_correction', ?, ?, ?, NOW())
```

---

## API Endpoints (if needed)

### GET `/api/flagged_transactions.php`
**Response:**
```json
{
  "status": "success",
  "transactions": [
    {
      "id": 46,
      "transaction_id": "FUEL2026125343720",
      "date": "2026-05-08 21:59:20",
      "fuel_type": "Diesel",
      "pump": "Pump #3",
      "encoded_liters": 0.00,
      "status": "Flagged",
      "flagged_reason": "Zero liters with high calibration value"
    }
  ]
}
```

### POST `/api/adjust_transaction.php`
**Request:**
```json
{
  "transaction_id": 46,
  "actual_liters": 1234.56,
  "adjustment_reason": "Calibration test conducted. Physical verification confirms...",
  "reason_category": "calibration_test"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Transaction adjusted successfully",
  "variance": -1234.56,
  "new_status": "Cleared"
}
```

---

## Table Design Standards (Applied)

- **Headers**: Blue (#002F70) with white text
- **Content**: Clean white backgrounds
- **Status Badges**: Plain colored text (no pill backgrounds)
  - Flagged: Red (#dc2626)
  - Cleared: Green (#16a34a)
  - Pending: Orange (#d97706)
- **Hover Effect**: Light blue (#f0f5ff)
- **Numeric Columns**: Right-aligned
- **Actions**: Inline buttons matching design system

---

## Next Steps

1. **Confirm Approach** with user:
   - New tab in Fuel Adjustments page vs standalone page?
   - Database schema changes needed?
   - Flagging criteria/triggers?

2. **Implement** based on confirmation

3. **Test** with real data scenarios

4. **Deploy** and gather feedback

---

**Document Created:** June 10, 2026
**Status:** Awaiting User Confirmation for Implementation
