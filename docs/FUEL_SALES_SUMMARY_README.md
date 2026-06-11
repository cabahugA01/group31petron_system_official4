# Fuel Sales Summary Report - Complete Documentation

## Overview
Comprehensive daily fuel sales summary report with complete fetch process from multiple data sources, shift summaries, A/R tracking, and PDF-optimized printing with no content cutoff.

## Data Sources & Fetch Process

### 1. Meter Readings Table
**Purpose**: Track beginning and ending values per pump/tanker

**Fields Retrieved**:
- `pump_number` / `pump_name` - Pump identifier
- `fuel_type` - Type of fuel (Diesel, Premium, etc.)
- `previous_reading` (beginning_reading) - Starting meter value
- `present_reading` (ending_reading) - Closing meter value
- `difference` (liters_sold) - Computed difference
- `calibration_adjustment` - Calibration corrections
- `shift_period` - Shift 1 or Shift 2
- `status` - Reading status
- `encoded_at` - Timestamp

**Computation**:
```
Liters Sold = ending_reading − beginning_reading ± calibration
```

**SQL Query**:
```sql
SELECT 
    fr.pump_number,
    COALESCE(fp.pump_name, CONCAT('Pump ', fr.pump_number)) AS pump_name,
    COALESCE(ft.name, fr.fuel_type) AS fuel_type,
    fr.previous_reading AS beginning_reading,
    fr.present_reading AS ending_reading,
    fr.difference AS liters_sold,
    COALESCE(fr.calibration_adjustment, 0) AS calibration,
    fr.shift_period
FROM fuel_readings fr
LEFT JOIN fuel_pumps fp ON fr.pump_number = fp.id
LEFT JOIN fuel_types ft ON fr.fuel_type = ft.id
WHERE fr.station_id = ? AND DATE(fr.encoded_at) = ?
ORDER BY fr.pump_number, fr.encoded_at
```

### 2. Fuel Transactions Table
**Purpose**: Track liters sold, unit price, and amount

**Fields Retrieved**:
- `fuel_type` - Type of fuel
- `liters_sold` - Quantity sold
- `unit_price` - Price per liter
- `total_amount` - Total transaction amount
- `payment_method` - Cash, Card, E-Wallet, Credit
- `shift` - Shift identifier
- `created_at` - Transaction timestamp

**Computation**:
```
Amount = liters_sold × unit_price
Shift Assignment = filter by shift_id (Shift 1 or Shift 2)
Totals = SUM(liters_sold) and SUM(amount) per fuel type
```

**SQL Query**:
```sql
SELECT 
    COALESCE(ftype.name, ft.fuel_type) AS fuel_type,
    ft.liters_sold,
    ft.unit_price,
    ft.total_amount,
    ft.payment_method,
    ft.shift
FROM fuel_transactions ft
LEFT JOIN fuel_types ftype ON ft.fuel_type_id = ftype.id
WHERE ft.station_id = ? AND DATE(ft.created_at) = ?
ORDER BY ft.created_at
```

### 3. Payments Table & Payment Breakdown
**Purpose**: Track cash, card, e-wallet, and suki/credit payments

**Payment Categories**:
- **Cash**: cash, cash payment
- **Card**: card, credit card, debit card
- **E-Wallet**: gcash, maya, e-wallet, ewallet
- **Credit**: suki, credit, account receivable

**Shift Summary Computation**:
```
Shift 1 Fuel Sales = SUM(fuel_transactions WHERE shift = 'Shift 1')
Shift 1 Merchandise = SUM(merchandise_transactions WHERE shift = 'Shift 1')
Shift 1 Total = Fuel Sales + Merchandise Sales
Payment Breakdown = SUM(amount) GROUP BY payment_method
```

### 4. Customer Accounts Table (A/R Summary)
**Purpose**: Track outstanding balances for suki/credit customers

**Fields Retrieved**:
- `name` - Customer name
- `contact_number` - Contact information
- `type` - Credit or Suki
- `account_balance` - Outstanding balance
- `credit_limit` - Maximum credit allowed

**SQL Query**:
```sql
SELECT 
    c.id,
    c.name,
    c.contact_number,
    COALESCE(c.account_balance, 0) AS balance,
    c.credit_limit,
    c.type
FROM customers c
WHERE c.station_id = ? 
  AND c.type IN ('credit', 'suki')
  AND COALESCE(c.account_balance, 0) > 0
ORDER BY c.account_balance DESC
```

## Report Sections

### 1. Meter Reading Table
**Displays**: Pump-by-pump meter readings with beginning, ending, difference, and liters sold

**Columns**:
- Pump Name
- Fuel Type
- Beginning Reading
- Ending Reading
- Difference
- Liters Sold
- Shift
- Status

**Total Row**: Sum of all liters sold across pumps

### 2. Volume Sales Summary
**Displays**: Total liters per fuel type with pricing

**Columns**:
- Fuel Type
- Total Liters
- Average Price/L
- Total Amount

**Computation**:
```php
$avg_price = $total_amount / $total_liters;
```

### 3. Tank Sales Summary
**Displays**: Reconciliation of tank capacity vs dispensed liters

**Columns**:
- Tank / Fuel Type
- Tank Capacity
- Dispensed Liters
- Utilization %

### 4. Shift 1 Sales & Cash Summary (6AM-2PM)
**Displays**:
- Fuel Sales (₱)
- Merchandise Sales (₱)
- **Total Sales** (bold, highlighted)
- Payment Breakdown:
  - 💵 Cash
  - 💳 Card
  - 📱 E-Wallet
  - 🤝 Credit/Suki

### 5. Shift 2 Sales & Cash Summary (2PM-12AM)
**Same format as Shift 1** for next shift

### 6. A/R Summary
**Displays**: Outstanding balances for suki/credit customers

**Columns**:
- Customer Name
- Contact Number
- Type (SUKI/CREDIT badge)
- Outstanding Balance (red)
- Credit Limit
- Available Credit (green)

**Total Row**: Total Accounts Receivable

### 7. Overall Daily Summary
**4 Summary Cards**:
1. **Total Fuel Sales** - ₱ amount
2. **Total Merchandise** - ₱ amount
3. **Total Liters Sold** - Liters
4. **Total Cash Collection** - ₱ amount

### 8. Total Cash in Bank
**Displays**:
- Cash on Hand (from collections)
- Deposits Made Today
- **TOTAL CASH IN BANK** (bold, purple gradient background)

## PDF Export Features

### Print Optimization
The PDF export is specifically optimized to prevent content cutoff:

```css
@page {
    size: A4 landscape;
    margin: 10mm;
}

@media print {
    body { margin: 0; padding: 0; }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; page-break-after: auto; }
    thead { display: table-header-group; }
}
```

**Font Sizes**:
- Body: 9pt
- Headers: 16pt
- Table headers: 8pt
- Table cells: 8pt
- Section titles: 10pt

**Layout**:
- A4 Landscape (297mm × 210mm)
- 10mm margins all sides
- Compressed table spacing
- 2-column shift summaries
- Grid-based overall summary

**No Cutoff Guarantee**:
- All tables fit on page width
- Headers repeat on page breaks
- Avoid breaking table rows
- Automatic page splitting

### How to Print PDF
1. Click "Export PDF (Print Ready)" button
2. Browser opens print dialog automatically
3. Select printer or "Save as PDF"
4. Verify preview shows all content
5. Print or save

**Print Settings**:
- Orientation: Landscape (auto-set)
- Paper: A4 (auto-set)
- Margins: Minimum
- Scale: 100%
- Headers/Footers: None

## Database Tables

### Required Tables
- `fuel_readings` - Meter readings
- `fuel_transactions` - Fuel sales
- `stations` - Station information

### Optional Tables (Graceful Fallback)
- `fuel_types` - Fuel type names
- `fuel_pumps` - Pump names
- `merchandise_transactions` - Merchandise sales
- `customers` - Customer A/R
- `payments` - Payment records

### Table Availability Check
```php
function table_exists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}
```

If optional tables don't exist:
- Uses fallback queries
- Shows "N/A" or default values
- Report still generates successfully

## Access & Security

### Role-Based Access
Allowed roles:
- Staff
- Cashier
- Pump Attendant
- Manager
- Admin
- Superadmin
- Developer

### Security Features
- Session-based authentication (`require_login()`)
- Station-specific data filtering
- SQL injection protection (prepared statements)
- Module gate check (`is_module_enabled('reports')`)

## Usage Guide

### Viewing the Report
1. Login to system
2. Navigate to **Reports** → **Fuel Sales Summary**
3. Select report date (defaults to today)
4. Click "Apply" to refresh

### Exporting to PDF
1. View report on screen
2. Click "Export PDF (Print Ready)" button
3. New tab opens with print-optimized version
4. Print dialog appears automatically
5. Save as PDF or print to printer

### Date Selection
- Date picker limited to today or earlier
- Cannot select future dates
- Defaults to current date
- Enter key applies filter

## Troubleshooting

### "No meter readings found"
**Cause**: No fuel_readings for selected date
**Solution**: 
- Verify date has meter readings in database
- Check if readings table exists
- Ensure station_id is correct

### "No volume sales data"
**Cause**: No fuel_transactions for selected date
**Solution**:
- Verify fuel transactions exist
- Check date filter
- Confirm shift assignments

### PDF Content Cut Off
**Cause**: Browser zoom or print settings
**Solution**:
- Set browser zoom to 100%
- Use "Fit to page" in print dialog
- Try different browser (Chrome recommended)
- Check paper size is A4 Landscape

### Missing Shift Data
**Cause**: Transactions not assigned to shifts
**Solution**:
- Check shift column in transactions
- Verify shift logic (time-based)
- Default to Shift 1 if null

### A/R Section Not Showing
**Cause**: No customers with balances > 0
**Solution**: This is normal if all accounts are paid

## File Locations

**Main Report**: `/public/staff_fuel_sales_summary.php`
**Navigation**: `/includes/staff_sidebar.php`
**Test Page**: `/public/test_fuel_sales_summary.php`
**Documentation**: `/docs/FUEL_SALES_SUMMARY_README.md`

## Technical Specifications

**Total Lines**: ~550 lines PHP + HTML
**File Size**: ~35KB
**Database Queries**: 4-7 (depending on available tables)
**Export Formats**: 1 (PDF/Print)
**Responsive**: Yes (mobile-friendly)
**Print-Optimized**: Yes (A4 Landscape)

## Version History

**v1.0** (2026-06-11)
- Initial release
- Complete fetch process from 4 data sources
- 8 summary sections
- PDF export with no cutoff
- Shift-based breakdowns
- A/R tracking
- Responsive design

## Support

For questions or issues:
1. Check this README
2. View test page: `/public/test_fuel_sales_summary.php`
3. Contact system administrator
4. Review database table structure

---

**Petron Station Management System**  
*Fuel Sales Summary Report - Staff Module*  
© 2026 All Rights Reserved
