# Manager Reports - Navigation Structure (Final)

## 📌 Sidebar Navigation

```
📊 Reports
   ├── 💰 Sales Reports
   ├── 🔧 Job Orders Reports
   ├── 🚚 Deliveries Reports
   ├── ⛽ Meter Readings
   ├── 💳 Payments Reports
   ├── 👥 Customer Reports
   └── ✅ Validation Logs ⭐ (NEW)
```

## 📄 Validation Logs Page Structure

### URL
`/public/manager_validation_logs.php`

### Page Tabs

#### For Manager Role:
```
┌─────────────────────────────────────────────────────────┐
│  VALIDATION LOGS                                         │
├─────────────────────────────────────────────────────────┤
│  [ Pending Validations ]  [ My Validation History ]     │
└─────────────────────────────────────────────────────────┘
```

#### For Admin Role:
```
┌─────────────────────────────────────────────────────────┐
│  VALIDATION LOGS                                         │
├─────────────────────────────────────────────────────────┤
│  [ Pending Validations ]  [ All Validation History ]    │
└─────────────────────────────────────────────────────────┘
```

## Tab Details

### Tab 1: Pending Validations
**Purpose:** Shows items awaiting Manager approval

**Columns:**
- Transaction Type
- Transaction ID
- Customer Name
- Staff Encoder
- Amount
- Date Created
- **Action Buttons** (Approve, Reject, Return)

**Visibility:**
- Manager: Items for their station only
- Admin: Items for all stations

---

### Tab 2: My Validation History (Manager)
**Purpose:** Audit trail of Manager's own validation actions

**Columns:**
- Date & Time
- Transaction Type
- Transaction ID
- Customer Name
- Staff Encoder
- Action Taken (✅ Approve, ❌ Reject, 🔄 Return, 📝 Adjust)
- Original Amount
- Validated Amount
- Remarks

**Visibility:**
- Manager: Only their own validation actions
- Shows historical log for compliance and accountability

---

### Tab 2: All Validation History (Admin)
**Purpose:** Full audit trail of all validation actions

**Columns:**
- Date & Time
- **Manager Name** (who performed the action)
- Transaction Type
- Transaction ID
- Customer Name
- Staff Encoder
- Action Taken
- Original Amount
- Validated Amount
- Remarks

**Visibility:**
- Admin: All managers' validation actions across all stations
- Used for oversight and compliance monitoring

## Features Available on Both Tabs

### 🔍 Filtering
- Date range (Today, This Week, This Month, Custom)
- Action type (Approve, Reject, Return, Adjust, All)
- Transaction type (Job Orders, Deliveries, Fuel Transactions, All)

### 🔎 Search
- Transaction ID
- Customer name
- Staff name
- (Admin only) Manager name

### 📊 Export
- **CSV** - All columns with data
- **Excel** - Formatted with summary statistics

### 📄 Pagination
- Options: 10, 25, 50, 100 rows per page
- Shows: "Showing 1-10 of 150 entries"

## Why Combined Page?

### ❌ Before (Redundant):
```
Reports
├── Validation Reports      ← Shows pending + some history
└── Audit Trail             ← Shows history (duplicate)
```

### ✅ After (Clean):
```
Reports
└── Validation Logs         ← Shows both via tabs
    ├── Tab: Pending        ← Action items
    └── Tab: History        ← Audit trail
```

### Benefits:
1. **No Redundancy** - Single menu item instead of two
2. **Clear Workflow** - Pending and completed actions in one place
3. **Better UX** - Related functions grouped together
4. **Compliance** - Full audit trail still available
5. **Role Separation** - Manager sees own, Admin sees all

## Data Source

**Database Table:** `validation_logs`

**Key Columns:**
```sql
- id
- manager_id           (who performed action)
- transaction_id       (what was validated)
- transaction_type     (job_order, delivery, etc)
- action_taken         (Approve, Reject, Return, Adjust)
- remarks              (justification)
- original_amount
- validated_amount
- created_at
- station_id
```

## Implementation Notes

### Query for Pending Tab:
```sql
-- Get items awaiting validation (not logged yet)
SELECT * FROM job_orders 
WHERE validation_status IN ('Pending', 'Pending Validation')
  AND station_id = ?
ORDER BY created_at DESC
```

### Query for Manager History Tab:
```sql
-- Get Manager's own validation logs
SELECT * FROM validation_logs 
WHERE manager_id = ? 
  AND station_id = ?
  AND DATE(created_at) BETWEEN ? AND ?
ORDER BY created_at DESC
```

### Query for Admin History Tab:
```sql
-- Get all validation logs (all managers)
SELECT vl.*, m.name as manager_name
FROM validation_logs vl
LEFT JOIN users m ON m.id = vl.manager_id
WHERE DATE(vl.created_at) BETWEEN ? AND ?
ORDER BY vl.created_at DESC
```

## Security

### Access Control:
- **Staff:** No access to Validation Logs
- **Manager:** Access to Validation Logs
  - Pending: See station items
  - History: See only own actions
- **Admin:** Full access to Validation Logs
  - Pending: See all stations
  - History: See all managers' actions

### Data Filtering:
- All queries filtered by `station_id` (except Admin)
- Manager history filtered by `manager_id`
- Admin history: no `manager_id` filter (sees all)

## Summary

✅ **Single Menu Item:** "Validation Logs"
✅ **Two Tabs:** Pending + History
✅ **Role-Based:** Manager sees own, Admin sees all
✅ **No Redundancy:** Combined validation + audit trail
✅ **Full Compliance:** Complete audit trail maintained
✅ **Clean Navigation:** Intuitive and organized

---

**Document Version:** 1.0
**Last Updated:** June 6, 2026
**Status:** ✅ Final Design Approved
