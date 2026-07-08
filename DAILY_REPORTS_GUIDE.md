# Daily Reports Feature Guide

## Overview
The Daily Reports feature provides comprehensive shift-based and daily sales reporting for Petron Station operations. This feature is accessible under **Reports → Daily Reports** in the sidebar navigation.

## Report Types

### 1. Shift 1 Fuel Sales Report (6:00 AM – 2:00 PM)
**File:** `public/reports/staff_shift_fuel_report.php?shift=shift1`

**Sections:**
- **Report Header** - Station info, shift details, cashier name, date/time stamps
- **Fuel Sales Income** - Summary by fuel type (Diesel, XCS Plus, Xtra Unleaded, Turbo Diesel, Kerosene)
- **Meter Reading Report** - UGT number, beginning/ending meter, calibration, liters sold, price, amount
- **Volume Sales Summary** - Total liters and amount by fuel type
- **Payment Breakdown** - Cash, Credit Card, Debit Card, GCash, Maya, Fleet Card, Credit Account
- **Fuel Inventory Movement** - Beginning stock, deliveries, fuel sold, ending stock

**Features:**
- Print-optimized layout
- Real-time data from `fuel_transactions` table
- Automatic calculation: `Liters Sold = Ending Meter - Beginning Meter - Calibration`
- Payment method aggregation
- Inventory reconciliation

### 2. Shift 2 Fuel Sales Report (2:00 PM – 10:00 PM)
**File:** `public/reports/staff_shift_fuel_report.php?shift=shift2`

**Same structure as Shift 1** but filtered for afternoon/evening transactions.

### 3. 24-Hour Fuel Summary
**File:** `public/reports/staff_shift_fuel_report.php?shift=24hour`

**Consolidated Report:**
- Combines all shifts for the entire day
- Full-day fuel sales analysis
- Complete payment breakdown
- Total inventory movement
- Grand totals across all fuel types

### 4. Daily Merchandise & Service Sales Report
**File:** `public/reports/staff_daily_merchandise_service_report.php`

**Sections:**
- **KPI Cards** - Merchandise Sales, Service Income, Total Revenue
- **Merchandise Sales** - Transaction ID, product name, category, quantity, unit price, subtotal, payment method, shift
- **Service Income** - Transaction ID, service type, description, labor fee, payment method, shift
- **Grand Total Summary** - Combined revenue breakdown

**Data Sources:**
- `merchandise_transactions` table
- `merchandise_transaction_items` table (filtered by `item_type`)
- Service items: `item_type = 'service'`
- Merchandise items: `item_type = 'merchandise'`

## Navigation Structure

### Sidebar Menu Hierarchy
```
Reports
├── Daily Reports (NEW)
│   ├── Shift 1 Fuel Sales
│   ├── Shift 2 Fuel Sales
│   ├── 24-Hour Fuel Summary
│   └── Merchandise & Service Sales
├── Sales Reports
├── Deliveries Reports
├── Payments Reports
├── Customer Reports
└── Activity Reports
```

### Files Modified
1. **includes/staff_sidebar.php**
   - Added nested submenu support
   - Added "Daily Reports" submenu with 4 report links
   - Added CSS for nested submenu styling (`.nested-submenu`)

2. **partials/rbac_menu.php**
   - Added "Daily Reports" sub_items array
   - Integrated with RBAC permissions: `view_personal_reports`, `view_operational_reports`

## Access Control

### Roles with Access
- Staff
- Cashier
- Pump Attendant
- Manager
- Admin
- SuperAdmin
- Developer

### Required Permissions
- `view_personal_reports` - View own shift reports
- `view_operational_reports` - View all station reports

### Module Gate
Reports are protected by the `reports` module configuration. If the module is disabled, users see a "Module Disabled" page.

## Usage Instructions

### Accessing Reports
1. **Via Sidebar Navigation:**
   - Click **Reports** in the sidebar
   - Click **Daily Reports** to expand submenu
   - Select desired report type

2. **Via Direct URL:**
   - Shift 1: `/reports/staff_shift_fuel_report.php?shift=shift1&report_date=2026-07-04`
   - Shift 2: `/reports/staff_shift_fuel_report.php?shift=shift2&report_date=2026-07-04`
   - 24-Hour: `/reports/staff_shift_fuel_report.php?shift=24hour&report_date=2026-07-04`
   - Merchandise: `/reports/staff_daily_merchandise_service_report.php?report_date=2026-07-04`

3. **Via Index Page:**
   - Navigate to `/reports/daily_reports_index.php`
   - Select date using date picker
   - Click on any report card to open

### Printing Reports
1. Open desired report
2. Click the **Print Report** button (top-right corner)
3. Browser print dialog opens
4. Select printer and print settings
5. Click Print

**Print Features:**
- Navigation buttons hidden in print view
- Optimized page breaks
- Clean, professional layout
- All tables fit within printable area

### Date Selection
- **Default:** Most recent date with fuel transaction data
- **Custom:** Use date picker or URL parameter `?report_date=YYYY-MM-DD`
- **Validation:** Date must be in `YYYY-MM-DD` format
- **Max Date:** Today (cannot select future dates)

## Database Schema Requirements

### Required Tables
1. **fuel_transactions**
   - `id`, `station_id`, `pump_id`, `fuel_type`
   - `beginning_reading`, `present_reading`, `calibration`
   - `liters_sold`, `unit_price`, `total_amount`
   - `payment_method`, `shift_period`, `transaction_date`

2. **fuel_pumps**
   - `id`, `station_id`, `pump_number`

3. **fuel_inventory**
   - `id`, `station_id`, `fuel_type`
   - `current_level` / `current_stock`
   - `price_per_liter`

4. **merchandise_transactions**
   - `id`, `station_id`, `transaction_id`
   - `total_amount`, `payment_method`
   - `shift_period`, `transaction_date`

5. **merchandise_transaction_items**
   - `id`, `transaction_id`, `product_id`
   - `product_name`, `category`, `item_type`
   - `quantity`, `unit_price`, `subtotal`

6. **users**
   - `id`, `first_name`, `last_name`, `username`

7. **stations**
   - `id`, `name`, `location`

### Key Relationships
- `fuel_transactions.pump_id` → `fuel_pumps.id`
- `fuel_transactions.station_id` → `stations.id`
- `merchandise_transaction_items.transaction_id` → `merchandise_transactions.id`

## Shift Detection Logic

### Shift Keys
- **first** / **shift1** / **Shift 1** → Morning shift (6:00 AM – 2:00 PM)
- **second** / **shift2** / **Shift 2** → Afternoon shift (2:00 PM – 10:00 PM)

### Shift Filtering
```sql
WHERE ft.shift_period = :shift_key
  AND DATE(ft.transaction_date) = :report_date
```

### 24-Hour Mode
When `shift=24hour`, no shift filtering is applied:
```sql
WHERE DATE(ft.transaction_date) = :report_date
```

## Calculations

### Fuel Sales
```
Liters Sold = Ending Meter - Beginning Meter - Calibration
Amount = Liters Sold × Price Per Liter
```

### Payment Totals
```sql
SUM(total_amount) GROUP BY payment_method
```

### Inventory Movement
```
Beginning Stock = Ending Stock + Fuel Sold - Fuel Delivery
Ending Stock = From fuel_inventory.current_level
```

### Merchandise/Service Totals
```
Subtotal = Quantity × Unit Price
Total = SUM(all subtotals)
```

## Styling & Design

### Color Scheme
- **Primary Blue:** `#00264D` (Petron Blue)
- **Accent Red:** `#CC0000` (Petron Red)
- **Background:** `#f8f9fa` (Light Gray)
- **Text:** `#333` (Dark Gray)

### Typography
- **Font Family:** 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif
- **Headings:** Bold, 18-24px
- **Body Text:** Regular, 13-14px
- **Tables:** 13px headers, 14px cells

### Print Styles
```css
@media print {
    .print-button, .back-button, .shift-tabs { display: none; }
    .container { max-width: 100%; padding: 0; }
    .section { page-break-inside: avoid; }
}
```

## Troubleshooting

### Issue: No Data Showing
**Check:**
1. Verify `fuel_transactions` table has data for selected date
2. Confirm `station_id` matches user's assigned station
3. Check shift_period values match expected keys ('first', 'second')
4. Verify transaction_date is properly formatted

### Issue: Incorrect Totals
**Check:**
1. Calibration values in fuel_transactions
2. Meter reading sequence (beginning < ending)
3. Price per liter from fuel_inventory
4. Payment method not null

### Issue: Report Won't Print
**Check:**
1. Browser popup blocker settings
2. Print CSS media query loading
3. Page content not exceeding print margins

### Issue: Nested Submenu Not Showing
**Check:**
1. JavaScript `toggleSubmenu()` function loaded
2. CSS `.nested-submenu` styles applied
3. RBAC permissions granted for user role

## Future Enhancements

### Planned Features
- [ ] Export to PDF (server-side generation)
- [ ] Export to Excel/CSV
- [ ] Email report scheduling
- [ ] Comparison view (day-over-day, week-over-week)
- [ ] Graphical charts (fuel sales trends, payment method pie charts)
- [ ] Outstanding credit accounts section
- [ ] Fuel tank status monitoring
- [ ] Shift variance alerts
- [ ] Manager approval workflow

### Suggested Improvements
- Add filters: fuel type, payment method, cashier
- Include customer credit account details
- Add signature lines for cashier/manager
- Include promotional discounts/adjustments
- Add notes/remarks section

## Support & Maintenance

### Error Logging
All database errors are logged using `error_log()`:
```php
error_log("Fuel report error: " . $e->getMessage());
```

### Performance Optimization
- Use indexed columns: `station_id`, `transaction_date`, `shift_period`
- Consider caching for frequently accessed reports
- Implement pagination for large datasets

### Security
- SQL injection prevention: PDO prepared statements
- XSS prevention: `htmlspecialchars()` on all output
- Access control: Session-based authentication + RBAC
- Input validation: Date format regex, shift type whitelist

---

**Version:** 1.0  
**Last Updated:** July 8, 2026  
**Author:** Kiro Development Team  
**Related Files:** See files list in Implementation section
