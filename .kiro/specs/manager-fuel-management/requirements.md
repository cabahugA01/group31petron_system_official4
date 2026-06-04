# Manager Fuel Management Module - Requirements

## Overview
Complete fuel management system for Station Managers with validation, adjustments, calibration, and reconciliation capabilities.

---

## 5 Core Pages

### 1. Fuel Transaction Validation (`manager_fuel_transaction_validation.php`)
**Purpose:** Validate staff-encoded pump readings

**Features:**
- Fetch all pending staff-encoded pump readings
- Action buttons: Approve, Reject, Adjust, Back
- Export: Excel/CSV (validated readings), PDF (validation report)
- Summary Cards:
  - Validated Transactions
  - Pending Transactions

**Workflow:**
1. Staff encodes pump readings
2. Manager reviews readings
3. Manager can: Approve (mark as validated), Reject (return to staff with reason), Adjust (modify values with notes)

---

### 2. Fuel Deliveries Validation (`manager_fuel_deliveries_validation.php`)
**Purpose:** Validate staff-encoded supplier delivery receipts

**Features:**
- Fetch all pending staff-encoded delivery receipts
- Action buttons: Approve, Return, Back
- Export: Excel/CSV (validated deliveries), PDF (delivery validation report)
- Summary Cards:
  - Validated Deliveries
  - Pending Deliveries

**Workflow:**
1. Staff encodes supplier delivery receipt (liters received, invoice #, supplier)
2. Manager reviews delivery data
3. Manager can: Approve (stock updated), Return (send back to staff with reason)

---

### 3. Adjustments (`manager_fuel_adjustments.php`)
**Purpose:** Encode corrections for tank levels, stock discrepancies, price rollbacks

**Features:**
- Form to add adjustment:
  - Adjustment Type (Tank Level, Stock Discrepancy, Price Rollback)
  - Fuel Type
  - Old Value
  - New Value
  - Remarks (required)
- View all adjustments made by manager
- Action buttons: Add Adjustment, Back
- Export: Excel/CSV (adjustment logs), PDF (adjustment report)
- Summary Cards:
  - Adjustments Made

**Workflow:**
1. Manager identifies discrepancy (e.g., physical tank dip shows different level than system)
2. Manager encodes adjustment with type, old/new values, and justification
3. System logs adjustment with timestamp and manager ID
4. Admin can view all adjustments in oversight module

---

### 4. Pump Master (`manager_pump_master.php`)
**Purpose:** Update calibration values per pump and fuel type

**Features:**
- List all pumps with current calibration values
- Form to update calibration:
  - Pump Number
  - Fuel Type
  - New Calibration Value
  - Variance Percentage
  - Remarks
- View calibration history
- Action buttons: Update Calibration, Back
- Export: Excel/CSV (calibration table), PDF (calibration report)
- Summary Cards:
  - Calibration Updates

**Workflow:**
1. Manager schedules pump calibration (weekly/monthly)
2. Technician performs physical calibration test
3. Manager encodes new calibration value into system
4. System applies new calibration to future readings
5. Audit trail records all calibration changes

---

### 5. Fuel Reconciliation (`manager_fuel_reconciliation.php`)
**Purpose:** Compare Daily Pump Sales vs Tank Levels, detect and resolve discrepancies

**Features:**
- Daily reconciliation dashboard:
  - Tank opening level
  - Deliveries received
  - Pump sales (liters sold)
  - Tank closing level (physical dip)
  - Expected closing level (calculated)
  - Variance (expected vs actual)
- Auto-flag variances exceeding threshold (e.g., ±50 liters)
- Resolution form:
  - Variance ID
  - Resolution Notes
  - Root Cause (dropdown: Calibration Issue, Delivery Shortage, Theft/Loss, Meter Error, Other)
  - Action Taken
- Action buttons: Resolve, Back, Export
- Export: Excel/CSV (reconciliation logs), PDF (variance resolution report)
- Summary Cards:
  - Reconciliations Completed
  - Variances Detected

**Workflow:**
1. End of shift/day: Manager performs physical tank dip
2. System calculates expected tank level: Opening + Deliveries - Sales = Expected Closing
3. Manager encodes actual closing level
4. System compares: Actual vs Expected
5. If variance > threshold → system auto-flags as "Variance Detected"
6. Manager investigates and encodes resolution notes
7. Admin can view all variances in oversight module

---

## Common Features Across All Pages

### Button Styling (Match Transactions Module)
```html
<!-- Excel Button -->
<button style="background:#1d6f42;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
    <i class="fas fa-file-excel"></i> Excel
</button>

<!-- CSV Button -->
<button style="background:#003d7a;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
    <i class="fas fa-file-csv"></i> CSV
</button>

<!-- PDF Button -->
<button style="background:#dc2626;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
    <i class="fas fa-file-pdf"></i> PDF
</button>

<!-- Back Button -->
<a href="manager_dashboard.php" style="background:#6c757d;color:#fff;text-decoration:none;height:36px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
    <i class="fas fa-arrow-left"></i> Back
</a>
```

### Page Header Styling
```css
h1 {
    font-size: 24px;
    font-weight: 700;
    color: #00264D;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 8px;
}

.subtitle {
    font-size: 14px;
    font-weight: 500;
    color: #666666;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
```

### Summary Cards
- 4 cards per page (except Adjustments & Pump Master: 2 cards)
- Color-coded borders (blue, green, amber, red)
- Icons for visual clarity
- Responsive grid layout

### Data Tables
- Sortable columns
- Pagination
- Search/filter
- Action buttons per row

### Access Control
- Role: Manager only
- Station-specific data
- Audit trail for all actions

---

## Database Tables Required

### `fuel_transactions`
- Stores pump readings
- Fields: `validation_status` (Pending, Approved, Rejected, Adjusted)

### `fuel_deliveries`
- Stores supplier delivery receipts
- Fields: `validation_status` (Pending, Approved, Returned)

### `fuel_adjustments`
- Stores manager adjustments
- Fields: `adjustment_type`, `fuel_type`, `old_value`, `new_value`, `remarks`, `adjusted_by`, `created_at`

### `pump_calibrations`
- Stores pump calibration values
- Fields: `pump_number`, `fuel_type`, `calibration_value`, `variance_percentage`, `status`, `calibrated_by`, `calibration_date`

### `fuel_reconciliations`
- Stores daily reconciliation records
- Fields: `recon_date`, `opening_level`, `deliveries`, `sales`, `expected_closing`, `actual_closing`, `variance`, `status`, `resolution_notes`

### `fuel_variance_logs`
- Stores variance detection and resolution
- Fields: `recon_id`, `variance_amount`, `root_cause`, `action_taken`, `resolved_by`, `resolved_at`

---

## Navigation Menu Structure

### Manager Sidebar - Fuel Management
```
⛽ Fuel Management
├── Fuel Transaction Validation
├── Fuel Deliveries Validation
├── Adjustments
├── Pump Master
└── Fuel Reconciliation
```

---

## Export Functionality

All pages support 3 export formats:
1. **Excel/CSV** - Raw data for analysis
2. **PDF** - Formatted report for printing

Export includes:
- Filtered/searched records
- Summary statistics
- Date range
- Station info
- Manager signature line (PDF only)

---

## Audit Trail

All manager actions logged:
- User ID + Name
- Action type (Approve, Reject, Adjust, Encode)
- Entity ID (transaction ID, delivery ID, etc.)
- Old/new values
- Timestamp
- IP address

Admin can view full audit trail in oversight module.

---

## Success Criteria

✅ Manager can validate staff encodings efficiently
✅ Manager can make corrections with full audit trail
✅ Manager can track fuel variances and resolve them
✅ Export functionality works for all reports
✅ All actions logged for admin oversight
✅ UI matches Transactions module styling
✅ Responsive design for tablet/desktop use
