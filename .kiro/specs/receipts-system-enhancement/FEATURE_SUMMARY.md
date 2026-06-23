# Manager Transaction Features - Summary

## Overview
This document summarizes all the manager-level transaction viewing and monitoring features for the Petron Station Management System.

---

## Feature 1: ALL TRANSACTIONS
**Purpose:** View tanan completed transactions gikan sa Staff
**Status:** 📋 Documented (Not Yet Implemented)

**Key Features:**
- 4 KPI Cards: Total Transactions, Total Sales, Job Order Transactions, Merchandise Transactions
- Advanced Filters: Date Range, Transaction Type, Payment Method, Payment Status, Staff Encoder, Shift, Search
- 10-Column Table: Transaction ID, Customer Name, Transaction Type, Vehicle Plate Number, Amount, Payment Method, Shift, Staff Encoder, Date & Time, Status, Actions
- Export Options: Excel, CSV, PDF
- Actions: View Details, View Receipt

---

## Feature 2: SHIFT TRANSACTIONS
**Purpose:** Monitor transactions per shift
**Status:** 📋 Documented (Not Yet Implemented)

**Key Features:**
- 4 KPI Cards: Shift 1 Sales, Shift 2 Sales, Shift 1 Transactions, Shift 2 Transactions
- Shift Filters: Date Range, Shift Selection
- 8-Column Table: Transaction ID, Customer Name, Transaction Type, Amount, Payment Method, Staff Encoder, Date & Time (with shift indicator 🌤/🌙), Actions
- Export Options: Excel, CSV, PDF

**Current File:** `transactions_shift.php` - Currently shows shift log view, needs complete rebuild

---

## Feature 3: TRANSACTION ADJUSTMENTS  
**Purpose:** Correct transaction errors without deleting records
**Status:** ⚠️ Partially Implemented (Needs Enhancement)

**Key Features:**
- 3 KPI Cards: Total Adjustments, Adjustments Today, Adjusted Amount
- Filters: Date Range, Staff Encoder, Transaction Type
- 8-Column Table: Adjustment ID, Transaction ID, Customer Name, Original Amount, Updated Amount, Adjustment Reason, Adjusted By, Adjustment Date
- Adjustment Modal: Edit transaction details, require reason and manager remarks
- Export Options: Excel, CSV, PDF

**Current File:** `manager_transaction_monitoring.php` - Exists but may need enhancement to match specifications

**Database Table Needed:** `transaction_adjustments` (may need to be created)

---

## Feature 4: VOIDED TRANSACTIONS (Future)
**Purpose:** View history of voided/cancelled transactions
**Status:** 🔮 Future Enhancement

---

## Common Features Across All Views

### Consistent UI Elements:
1. **Search Bar** - Transaction ID, Customer Name, Plate Number
2. **Filter Section** - Date range, dropdowns, checkboxes
3. **KPI Dashboard Cards** - Summary metrics at the top
4. **Data Table** - Sortable columns, pagination
5. **Export Buttons** - Excel, CSV, PDF
6. **Back Button** - Return to previous page

### Consistent Table Behavior:
- Sortable columns (click header to sort)
- Pagination (10, 25, 50, 100 rows per page)
- Row hover effect for readability
- Empty state message when no results
- Loading indicator during data fetch

### Consistent Export Format:
- File naming: `[Feature]_YYYY-MM-DD_HHMMSS.ext`
- Headers included in all exports
- Summary section (total transactions, total amount)
- Generation timestamp and user info
- Respects active filters

### Consistent Visual Design:
- Petron blue (#002F70) for primary actions
- Color-coded status badges (green = approved, yellow = pending, red = rejected)
- Transaction type badges (purple = job order, blue = merchandise, green = combined)
- Responsive grid layout for KPI cards
- Mobile-responsive table (auto-adjust or horizontal scroll)

---

## Page Navigation Structure

```
Manager Dashboard
│
├── Transactions Menu (Dropdown)
│   │
│   ├── All Transactions          ← Feature 1
│   ├── Shift Transactions        ← Feature 2
│   ├── Transaction Adjustments   ← Future
│   └── Voided Transactions       ← Future
│
├── Reports Menu
│   ├── Sales Report
│   ├── Inventory Report
│   └── Staff Performance Report
│
└── Validation Queue
    └── Pending Validations
```

---

## Technical Implementation Notes

### Database Tables Used:
- `merchandise_transactions` - Product sales
- `job_orders` - Service transactions
- `merchandise_transaction_items` - Line items for merchandise
- `users` - Staff information
- `shift_periods` - Shift definitions
- `labor_sessions` - Staff clock-in/out records

### Key Database Queries:

**All Transactions:**
```sql
SELECT 
    mt.transaction_id,
    mt.customer_name,
    mt.total_amount,
    mt.payment_method,
    mt.created_at,
    u.name AS staff_name,
    -- Determine shift based on time
    CASE 
        WHEN HOUR(mt.created_at) >= 6 AND HOUR(mt.created_at) < 14 THEN 'Shift 1'
        WHEN HOUR(mt.created_at) >= 14 AND HOUR(mt.created_at) < 22 THEN 'Shift 2'
        ELSE 'Shift 3'
    END AS shift
FROM merchandise_transactions mt
LEFT JOIN users u ON u.id = mt.staff_id
WHERE mt.station_id = ?
ORDER BY mt.created_at DESC
```

**Shift Transactions (Shift 1 Only):**
```sql
SELECT * FROM merchandise_transactions
WHERE station_id = ?
  AND HOUR(created_at) >= 6 
  AND HOUR(created_at) < 14
  AND DATE(created_at) BETWEEN ? AND ?
ORDER BY created_at DESC
```

**Shift KPI (Total Sales per Shift):**
```sql
SELECT 
    CASE 
        WHEN HOUR(created_at) >= 6 AND HOUR(created_at) < 14 THEN 'Shift 1'
        WHEN HOUR(created_at) >= 14 AND HOUR(created_at) < 22 THEN 'Shift 2'
        ELSE 'Shift 3'
    END AS shift,
    COUNT(*) AS transaction_count,
    SUM(total_amount) AS total_sales
FROM merchandise_transactions
WHERE station_id = ?
  AND DATE(created_at) BETWEEN ? AND ?
GROUP BY shift
```

---

## User Roles and Permissions

### Manager Role:
- ✅ View All Transactions
- ✅ View Shift Transactions
- ✅ Export transaction data
- ✅ View transaction details
- ✅ View receipts
- ❌ Edit validated transactions (requires Admin)
- ❌ Void transactions (requires Admin approval)

### Admin Role:
- ✅ All Manager permissions
- ✅ Edit/Adjust transactions
- ✅ Void transactions
- ✅ View audit logs
- ✅ Manage staff access

### Staff Role:
- ✅ View own transaction history only
- ❌ View other staff transactions
- ❌ View shift summaries
- ❌ Export data
- ❌ View all transactions

---

## Success Metrics

### Quantitative:
- **Page Load Time:** < 2 seconds for 1000 transactions
- **Search Speed:** < 1 second for results
- **Export Time:** < 5 seconds for 5000 records
- **Filter Apply Time:** < 1 second
- **User Adoption:** 80% of managers use these features weekly

### Qualitative:
- Managers can find any transaction within 30 seconds
- Shift performance comparison is clear and actionable
- Export reports are used in monthly meetings
- Transaction disputes resolved faster
- Staff accountability improved

---

## Future Enhancements

### Phase 2 (Planned):
1. **Real-time Updates** - Live transaction updates without page refresh
2. **Transaction Annotations** - Add notes/comments to transactions
3. **Bulk Operations** - Approve/void multiple transactions at once
4. **Saved Filter Presets** - Save frequently used filter combinations
5. **Transaction Trends Chart** - Visual graphs of sales over time
6. **Staff Performance Comparison** - Side-by-side staff metrics

### Phase 3 (Future):
1. **Mobile App** - Native mobile app for managers
2. **Push Notifications** - Alert managers of unusual transactions
3. **AI Anomaly Detection** - Automatically flag suspicious transactions
4. **Advanced Analytics** - Predictive analytics and forecasting
5. **Integration with Accounting Software** - Export to QuickBooks, Xero, etc.

---

**Document Version:** 1.0
**Created:** June 23, 2026
**Last Updated:** June 23, 2026
**Status:** In Progress - Requirements Gathering Phase
