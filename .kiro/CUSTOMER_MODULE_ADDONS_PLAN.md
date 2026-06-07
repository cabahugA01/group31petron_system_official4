# Customer Module – Functional Add-Ons Implementation Plan

**Date**: June 6, 2026  
**Status**: 📋 Planning Phase

---

## Overview

Adding three critical functional components to the Customer Module across all roles (Staff, Manager, Admin):

1. **Back Button** - Consistent navigation
2. **Export Options** - Excel/CSV/PDF for reporting
3. **Summary Cards** - Quick KPI snapshots

---

## 1. Back Button Implementation

### Purpose
Improve usability and navigation consistency across all customer module pages.

### Implementation by Role

#### Staff (`customers.php`)
**Locations**:
- ✅ Add section → Back to List
- ✅ Edit section → Back to List
- ✅ History section → Back to List

**Design**:
```html
<a href="customers.php?section=list" class="btn-back">
    <i class="fas fa-arrow-left"></i> Back to List
</a>
```

#### Manager (`manager_customers.php`)
**Locations**:
- ✅ Add section → Back to Records
- ✅ Edit section (validation flow) → Back to Records
- ✅ History section → Back to Records
- ✅ Balances section → Back to Records

**Design**: Same button style, different href

#### Admin (`admin_customer_management.php`)
**Locations**:
- ✅ Customer History (when customer selected) → Clear selection
- ✅ Oversight section → Back to List

**Design**: Consistent with other roles

---

## 2. Export Options Implementation

### Purpose
Enable transparency, reporting, and compliance through data export capabilities.

### Export Formats
- **CSV** - Quick data export for spreadsheets
- **Excel** - Formatted reports with styling (optional advanced feature)
- **PDF** - Print-ready reports via browser print

### Implementation by Role

#### Staff (`customers.php`)
**Sections with Export**:

1. **Customer List** (section=list)
   - CSV: Basic customer info (name, contact, address, status)
   - PDF: Browser print (already styled)
   - Fields: NO credit_limit, NO balance (staff restricted)

2. **Customer History** (section=history)
   - CSV: Transaction linkage (customer name, transaction type, date, amount)
   - PDF: Browser print
   - Limited to staff's own station

**Button Placement**: Card header (top-right)

**CSV Handler**:
```php
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $section === 'list') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="customers_' . date('Y-m-d') . '.csv"');
    // Output CSV data
    exit;
}
```

#### Manager (`manager_customers.php`)
**Sections with Export**:

1. **Customer Records** (section=records)
   - CSV: Full customer info INCLUDING credit_limit, balance, suki_status, payment_terms
   - PDF: Browser print (already implemented)

2. **Customer Balances** (section=balances)
   - CSV: Customer credit usage (name, credit_limit, balance, utilization%, last_payment)
   - PDF: Browser print

3. **Customer History** (section=history)
   - CSV: Full transaction history with amounts
   - PDF: Browser print (already implemented)

**Button Placement**: Card header with existing print button

#### Admin (`admin_customer_management.php`)
**Sections with Export**:

1. **Customer List** (section=list)
   - CSV: Global customer list with station assignment
   - Fields: name, station_name, contact, balance, credit_limit, status

2. **Customer Balances** (section=balances)
   - CSV: Receivables report (customer, station, outstanding, credit_limit, overdue_flag)

3. **Customer History** (section=history)
   - CSV: Full audit trail (date, customer, transaction_type, amount, station)

4. **Customer Oversight** (section=oversight)
   - CSV: Station assignment report (customer, current_station, balance, status, created_date)

**Button Placement**: Card header

---

## 3. Summary Cards Implementation

### Purpose
Provide quick monitoring snapshots and motivate staff through visible metrics.

### Design Specifications
- **Layout**: Grid (3-4 cards per row, responsive)
- **Style**: Card with icon, value (large), label (small)
- **Colors**: Role-specific (staff=blue, manager=purple, admin=navy)
- **Placement**: Top of each main section (before search/filters)

### Implementation by Role

#### Staff (`customers.php`)

**Customer List Section**:
```
┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐
│  📝 Customers       │ │  ✅ Active          │ │  🔗 With Trans.     │
│     Encoded         │ │     Customers       │ │     Linked          │
│                     │ │                     │ │                     │
│      45             │ │      42             │ │      38             │
└─────────────────────┘ └─────────────────────┘ └─────────────────────┘
```

**Metrics**:
- Total Customers Encoded (by this staff or station)
- Active Customers
- Customers with Transactions Linked

**Customer History Section**:
```
┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐
│  📋 Job Orders      │ │  🛍️ Merchandise     │ │  💰 Total Value     │
│     Linked          │ │     Trans.          │ │                     │
│                     │ │                     │ │                     │
│      12             │ │      26             │ │   ₱45,230.00        │
└─────────────────────┘ └─────────────────────┘ └─────────────────────┘
```

#### Manager (`manager_customers.php`)

**Customer Records Section**:
```
┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐
│  👥 Total           │ │  ✅ Validated       │ │  💳 With Credit     │ │  ⭐ Suki/VIP        │
│     Customers       │ │     Customers       │ │     Line            │ │     Customers       │
│                     │ │                     │ │                     │ │                     │
│      82             │ │      75             │ │      28             │ │      15             │
└─────────────────────┘ └─────────────────────┘ └─────────────────────┘ └─────────────────────┘
```

**Customer Balances Section** (already has summary cards - verify completeness):
```
┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐
│  💰 Total Credit    │ │  📊 Outstanding     │ │  ✅ Available       │
│     Limit           │ │     Balances        │ │     Credit          │
│                     │ │                     │ │                     │
│  ₱250,000.00        │ │  ₱45,230.00         │ │  ₱204,770.00        │
└─────────────────────┘ └─────────────────────┘ └─────────────────────┘
```

**Customer History Section**:
```
┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐
│  📜 Total Trans.    │ │  💵 Total Value     │ │  📅 Date Range      │
│                     │ │                     │ │                     │
│                     │ │                     │ │                     │
│      234            │ │  ₱1,245,890.00      │ │   Jan-Dec 2026      │
└─────────────────────┘ └─────────────────────┘ └─────────────────────┘
```

#### Admin (`admin_customer_management.php`)

**Customer List Section** (already implemented - verify):
```
┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐
│  👥 Total           │ │  ✅ Active          │ │  ❌ Inactive        │ │  ⚠️ With Balances   │
│     Customers       │ │                     │ │                     │ │                     │
│                     │ │                     │ │                     │ │                     │
│      482            │ │      445            │ │      37             │ │      68             │
└─────────────────────┘ └─────────────────────┘ └─────────────────────┘ └─────────────────────┘
```

**Customer Balances Section** (already implemented - verify):
```
┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐
│  💰 Total           │ │  🚨 Overdue         │ │  💵 Total           │ │  📊 Clear           │
│     Outstanding     │ │     Accounts        │ │     Collected       │ │     Accounts        │
│                     │ │                     │ │                     │ │                     │
│  ₱845,230.00        │ │      12             │ │  ₱2,145,890.00      │ │      414            │
└─────────────────────┘ └─────────────────────┘ └─────────────────────┘ └─────────────────────┘
```

**Customer Oversight Section** (already implemented - verify):
```
┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐ ┌─────────────────────┐
│  👥 Total           │ │  ✅ Active          │ │  ⚠️ Inactive        │ │  📦 Archived        │
│     Customers       │ │                     │ │                     │ │                     │
│                     │ │                     │ │                     │ │                     │
│      482            │ │      445            │ │      28             │ │      9              │
└─────────────────────┘ └─────────────────────┘ └─────────────────────┘ └─────────────────────┘
```

---

## Implementation Priority

### Phase 1: Back Buttons (Quick Win)
- Add to all 3 modules
- Consistent design across roles
- Estimated time: 30 minutes

### Phase 2: Summary Cards (High Impact)
- Staff module: Add cards to list and history sections
- Manager module: Verify balances cards exist, add to records and history
- Admin module: Verify all cards exist
- Estimated time: 2 hours

### Phase 3: CSV Export (Medium Impact)
- Implement CSV handlers for each role
- Add export buttons to card headers
- Test with sample data
- Estimated time: 1.5 hours

### Phase 4: Testing
- Verify all back buttons navigate correctly
- Test CSV downloads with actual data
- Verify summary card calculations
- Estimated time: 1 hour

---

## Technical Implementation Notes

### Back Button CSS
```css
.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: #6c757d;
    color: #fff;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s;
}
.btn-back:hover {
    background: #5a6268;
    color: #fff;
}
```

### Summary Card CSS (already exists in admin, replicate for staff/manager)
```css
.summary-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.summary-kpi {
    background: #fff;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border-left: 4px solid #002F6C;
}
.summary-kpi-value {
    font-size: 26px;
    font-weight: 800;
    color: #002F6C;
}
.summary-kpi-label {
    font-size: 11px;
    font-weight: 600;
    color: #888;
    text-transform: uppercase;
}
```

### CSV Export Helper Function
```php
function export_csv($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
```

---

## Files to Modify

1. ✅ `public/customers.php` - Staff module (back buttons, summary cards, CSV export)
2. ✅ `public/manager_customers.php` - Manager module (back buttons, summary cards, CSV export)
3. ✅ `public/admin_customer_management.php` - Verify existing cards, add any missing

---

## Success Criteria

- ✅ All sections have visible back buttons that work correctly
- ✅ All sections display relevant summary cards with accurate calculations
- ✅ CSV export downloads properly formatted files
- ✅ PDF export uses browser print functionality (already styled)
- ✅ Consistent design and behavior across all three roles
- ✅ No duplicate code - use shared CSS classes where possible

---

**Next Step**: Begin Phase 1 implementation with back buttons across all modules.
