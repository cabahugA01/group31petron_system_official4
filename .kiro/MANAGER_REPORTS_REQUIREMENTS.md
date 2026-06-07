# Manager Reports - Complete Requirements

**Status**: 📋 REQUIREMENTS DOCUMENTED  
**Date**: 2026-06-07  
**Module**: Manager Reports  
**Total Reports**: 6

## Overview

Manager Reports module provides comprehensive operational reporting for station managers. Each report has specific functionality and data focus.

---

## 1. Transactions Reports

**Purpose**: Daily/weekly/monthly sales and transaction analysis  
**URL**: `manager_reports.php?section=sales`

### Features Required:

#### Sales Summary
- Daily sales totals with date range filter
- Weekly sales aggregation (by week)
- Monthly sales aggregation (by month)
- Year-to-date (YTD) comparison

#### Payment Method Breakdown
- **Cash transactions**: Total amount, count, percentage
- **Card transactions**: Total amount, count, percentage  
- **E-wallet transactions**: Total amount, count, percentage
- Visual breakdown (pie chart or bar chart)
- Trend analysis by payment method

#### Special Transactions
- **Discounts applied**: Total discount amount, count, average discount
- **Returns/refunds**: Total refunded, count, items returned
- **Adjustments**: Manager adjustments, reasons, amounts

#### Data Display
- Filterable by date range (from/to)
- Exportable to Excel/PDF
- Summary cards showing:
  - Total Sales
  - Total Transactions
  - Average Transaction Value
  - Most Popular Payment Method

---

## 2. Fuel Management Reports

**Purpose**: Fuel delivery validation, pump reading variance, and fuel stock tracking  
**URL**: `manager_reports.php?section=fuel`

### Features Required:

#### Fuel Deliveries Validated
- List of fuel deliveries validated by manager
- Delivery reference, date, fuel type, liters
- Validation status: Approved, Rejected, Adjusted
- Manager who validated, timestamp
- Delivery variance (expected vs actual)

#### Pump Reading Variance Analysis
- **Expected readings**: Based on deliveries and previous balance
- **Actual readings**: Staff-encoded pump readings
- **Variance**: Difference between expected and actual
- Variance alerts: Flag readings with >5% variance
- Pump-by-pump breakdown
- Trend analysis: variance over time

#### Fuel Stock-In/Out Logs
- Stock-in records: Deliveries received, batch IDs
- Stock-out records: Sales/dispensing
- Running balance per fuel type
- Low stock alerts (below threshold)
- FIFO tracking: Batch usage and depletion

#### Data Display
- Filterable by fuel type, date range, validation status
- Exportable to Excel/PDF
- Summary cards:
  - Total Deliveries Validated
  - Average Variance %
  - Current Stock Levels per Fuel Type
  - Deliveries with Issues

---

## 3. Merchandise Deliveries Reports

**Purpose**: DR validation tracking, PO vs delivery comparison, discrepancy management  
**URL**: `manager_reports.php?section=deliveries`

### Features Required:

#### DR Validation Status Breakdown
- **Full Delivery**: Complete as per PO, no issues
- **Partial Delivery**: Short quantity received
- **Damaged Items**: Items received damaged
- **Rejected Delivery**: Wrong items or quality issues
- Count and percentage per status
- Trend over time

#### PO vs Actual Delivery Comparison
- Side-by-side comparison table:
  - PO Number
  - Product Name
  - Expected Quantity (from PO)
  - Actual Quantity (received)
  - Variance (difference)
  - Status
- Highlight discrepancies in red
- Show percentage variance

#### Discrepancy Remarks Analysis
- List of deliveries with discrepancies
- Manager remarks/notes
- Action taken (approved, returned, adjusted)
- Supplier performance tracking (by supplier)
- Most common discrepancy reasons

#### Data Display
- Filterable by status, supplier, date range, product
- Exportable to Excel/PDF
- Summary cards:
  - Total Deliveries Validated
  - Full Delivery Rate %
  - Deliveries with Discrepancies
  - Total Value of Discrepancies

---

## 4. Inventory Reports

**Purpose**: Stock movement tracking, low stock monitoring, stock-in/out validation  
**URL**: `manager_reports.php?section=inventory`

### Features Required:

#### Stock Movement Summary
- **Stock-In**: Total items received, by product
- **Stock-Out**: Total items sold/dispensed, by product
- **Net Movement**: Stock-in minus stock-out
- Product-by-product breakdown
- Category-wise aggregation (Fuel, Merchandise, etc.)
- Movement trends over time (daily/weekly/monthly)

#### Low Stock Alerts
- List of products below reorder level
- Current stock vs minimum stock threshold
- Days until stock-out (based on average usage)
- Priority ranking (critical, warning, ok)
- Suggested reorder quantity

#### Stock-In/Out Validation
- **Stock-In records**: Delivery received, batch ID, date, staff who encoded
- **Stock-Out records**: Sales, wastage, adjustments
- Manager validation status for each entry
- Discrepancies flagged (stock variance)
- Audit trail: Who validated, when, notes

#### Data Display
- Filterable by product, category, date range
- Exportable to Excel/PDF
- Summary cards:
  - Total Products Tracked
  - Products with Low Stock
  - Total Stock Value
  - Stock Turnover Rate

---

## 5. Customer Reports

**Purpose**: Customer balance tracking, pending orders, complaints/returns validation  
**URL**: `manager_reports.php?section=customers`

### Features Required:

#### Customer Balances
- List of customers with outstanding balances (utang)
- Customer name, balance amount, last payment date
- Aging analysis:
  - 0-30 days
  - 31-60 days
  - 61-90 days
  - Over 90 days (overdue)
- Total outstanding receivables
- Customers approaching credit limit

#### Pending Orders
- Customer orders awaiting fulfillment
- Order number, customer name, items ordered, total value
- Order date, expected delivery date
- Order status (pending, partial, ready)
- Delayed orders flagged

#### Complaints/Returns Validated
- List of customer complaints logged
- Return requests submitted by staff
- Manager validation status: Approved, Rejected, Pending
- Complaint type/reason
- Resolution notes
- Refund/replacement status
- Customer satisfaction trends

#### Data Display
- Filterable by customer, status, date range
- Exportable to Excel/PDF
- Summary cards:
  - Total Outstanding Balance
  - Number of Customers with Balance
  - Pending Orders Value
  - Complaints Resolved Rate %

---

## 6. Staff Performance Reports

**Purpose**: Staff encoding accuracy, task completion tracking, validation error monitoring  
**URL**: `manager_reports.php?section=staff`

### Features Required:

#### Encoding Accuracy
- Staff-wise accuracy rate:
  - Total transactions encoded
  - Transactions approved by manager (accurate)
  - Transactions rejected/adjusted (errors)
  - Accuracy percentage
- Most common error types per staff
- Trend: Accuracy over time (improving or declining)

#### Task Completion Rate
- Tasks assigned to staff (deliveries, stock-ins, etc.)
- Tasks completed on time
- Tasks delayed
- Tasks pending
- Completion rate % per staff member
- Average completion time

#### Validation Errors Flagged
- List of transactions requiring manager adjustment
- Error type:
  - Quantity mismatch
  - Price error
  - Missing information
  - Wrong product/fuel type
- Staff who encoded the error
- Frequency of errors per staff
- Action taken by manager (approved, rejected, adjusted)

#### Data Display
- Filterable by staff member, date range, error type
- Staff ranking by performance (best to worst)
- Exportable to Excel/PDF
- Summary cards:
  - Overall Staff Accuracy Rate
  - Total Tasks Completed
  - Tasks Needing Manager Review
  - Top Performing Staff Member

---

## Implementation Status

| Report | Menu Item | Functionality | Status |
|--------|-----------|---------------|--------|
| Transactions Reports | ✅ Added | ⚠️ Partial | Existing sales report needs expansion |
| Fuel Management | ✅ Added | ❌ Missing | New report needed |
| Merchandise Deliveries | ✅ Added | ⚠️ Partial | Exists but needs enhancement |
| Inventory Reports | ✅ Added | ⚠️ Partial | Basic inventory exists, needs expansion |
| Customer Reports | ✅ Added | ❌ Missing | New report needed |
| Staff Performance | ✅ Added | ❌ Missing | New report needed |

---

## Common Features Across All Reports

### Date Range Filtering
- From/To date picker
- Quick filters: Today, This Week, This Month, Last Month, Custom

### Export Options
- Excel export (CSV format)
- PDF export (printable format)
- Include filters and date range in export filename

### Summary Cards
- Visual KPI cards at top of each report
- Color-coded indicators (green = good, yellow = warning, red = critical)

### Data Tables
- Sortable columns
- Searchable/filterable
- Pagination (10/25/50/100 rows per page)
- Row highlighting for important items

### Visual Charts
- Trend lines for time-series data
- Pie charts for breakdowns
- Bar charts for comparisons

---

## Technical Implementation Notes

### Database Tables Involved
- `fuel_transactions` - Fuel sales and pump readings
- `merchandise_transactions` - Merchandise sales
- `deliveries_oversight` - Delivery validation records
- `fuel_stock_in`, `merchandise_stock_in` - Stock-in logs
- `fuel_inventory`, `station_inventory` - Current stock levels
- `customers` - Customer data and balances
- `users` - Staff information
- `audit_logs` - Validation and activity logs

### API Endpoints Required
- `manager_reports.php` - Main reports router
- `backend/api/manager_reports_api.php` - Data fetching API
- Query parameters: `?section=<report_name>&from=<date>&to=<date>`

### Permissions Required
- `view_operational_reports` - For all operational reports
- `view_financial_reports` - For financial aspects (balances, sales)
- `manage_job_orders` - For staff performance reports

---

## Next Steps

1. **Priority 1 (Critical)**:
   - Implement Fuel Management Reports (pump variance tracking)
   - Expand Transactions Reports (payment method breakdown)

2. **Priority 2 (High)**:
   - Customer Reports (balance aging, complaints)
   - Staff Performance Reports (accuracy tracking)

3. **Priority 3 (Medium)**:
   - Enhance Merchandise Deliveries Reports (better discrepancy analysis)
   - Expand Inventory Reports (low stock alerts, movement trends)

---
**Document Created**: 2026-06-07  
**Author**: Kiro AI Assistant  
**Purpose**: Define complete requirements for Manager Reports module
