# Requirements: Manager Transaction Monitoring System

## Overview
Create comprehensive transaction monitoring features for Managers to view, filter, analyze, and export all staff transactions. This includes two main views: All Transactions (complete oversight) and Shift Transactions (shift-based performance monitoring).

## Scope
This requirements document covers:
- **All Transactions page** - Comprehensive view of all staff-encoded transactions with advanced filtering
- **Shift Transactions page** - Shift-based monitoring and comparison
- **KPI Dashboard Cards** - Summary metrics for quick insights
- **Advanced Filtering** - Multiple filter criteria for precise transaction lookup
- **Export Capabilities** - Excel, CSV, and PDF export options
- **Transaction Details View** - Detailed view modal/page
- **Receipt Access** - Quick access to transaction receipts

## Business Logic: Transaction Types and Visibility

The system supports **three transaction types**, each with specific visibility rules:

### **Transaction Type 1: Job Order Only**
- **Definition:** Service-only transaction (e.g., car wash, oil change, repair)
- **Has:** Service/labor details, vehicle information
- **No:** Merchandise items

### **Transaction Type 2: Merchandise Only**
- **Definition:** Product-only transaction (e.g., snacks, drinks, car accessories)
- **Has:** Product items, inventory impact
- **No:** Service/labor or vehicle details

### **Transaction Type 3: Combined Transaction**
- **Definition:** Service + Merchandise in one transaction
- **Has:** Both service details AND product items
- **Example:** Oil change (service) + air freshener (product) in single transaction

## Current State Analysis

### Existing Page (Screenshot Analysis)
The current "ALL TRANSACTIONS" page at `/manager_validated_transactions.php` shows:

**Current Elements:**
- ✅ Page title: "ALL TRANSACTIONS"
- ✅ Search box (Transaction ID, customer...)
- ✅ Type filter dropdown (All Types)
- ✅ Date range filters (FROM/TO)
- ✅ Filter and Reset buttons
- ✅ Summary cards: Total Transactions, Merchandise, Job Orders, Paid, Unpaid/Partial, Total Amount
- ✅ Table with columns: Txn ID, Customer, Type, Items/Service, Amount, Method, Status, Date, Staff, Validated, Validation Remarks, Actions
- ✅ Export buttons: Excel, CSV, PDF
- ✅ Back button

**Current Limitations:**
- ❌ Limited filter options (no payment method, payment status, shift, staff filter)
- ❌ No KPI cards showing breakdown metrics
- ❌ Table has too many columns (cluttered)
- ❌ No vehicle plate number column for job orders
- ❌ Search is basic text search only
- ❌ No "View Details" action (only receipt view)

## User Needs ("Purpose")
> "View tanan completed transactions gikan sa Staff"

**Key Requirements:**
1. See ALL transactions from all staff members
2. Filter by multiple criteria (date, type, payment, staff, shift)
3. Quick KPI overview (total sales, transaction counts)
4. Export data in multiple formats
5. Access transaction details and receipts
6. Search by transaction ID, customer name, or plate number

## Requirements

### 1. KPI Dashboard Cards (CRITICAL)
**Priority:** Critical
**Description:** Display summary metrics at the top of the page for quick insights into transaction volume and revenue.

**KPI Cards:**
1. **Total Transactions** - Count of all transactions in current filter
2. **Total Sales** - Sum of all transaction amounts (₱)
3. **Job Order Transactions** - Count of job order + combined transactions
4. **Merchandise Transactions** - Count of merchandise + combined transactions

**Acceptance Criteria:**
- [ ] 4 cards displayed in a responsive grid layout
- [ ] Card values update based on active filters
- [ ] Total Sales displays in peso format (₱X,XXX.XX)
- [ ] Cards use consistent styling with icons
- [ ] Mobile-responsive (2x2 grid on mobile, 4 columns on desktop)
- [ ] Cards show real-time data (no caching)

**Business Value:** Quick decision-making, instant visibility into sales performance.

---

### 2. Advanced Filtering System (CRITICAL)
**Priority:** Critical
**Description:** Comprehensive filtering options to help managers find specific transactions quickly.

**Filter Criteria:**

**A. Date Range**
- From Date (date picker)
- To Date (date picker)
- Quick presets: Today, Yesterday, Last 7 Days, Last 30 Days, This Month

**B. Transaction Type**
- All Types (default)
- Job Order
- Merchandise
- Combined

**C. Payment Method**
- All Methods (default)
- Cash
- GCash
- Credit/Debit Card
- Bank Transfer
- Receivable/Credit

**D. Payment Status**
- All Status (default)
- Paid
- Unpaid
- Partial

**E. Staff Encoder**
- All Staff (default)
- Dropdown list of active staff members
- Shows: Staff Name (Username)

**F. Shift**
- All Shifts (default)
- Dropdown list of shift periods
- Shows: First Shift, Second Shift, Third Shift, etc.

**G. Search**
- Transaction ID (exact or partial match)
- Customer Name (case-insensitive)
- Vehicle Plate Number (for job orders)

**Acceptance Criteria:**
- [ ] All filter fields work independently and in combination
- [ ] Filter state persists in URL query parameters (shareable links)
- [ ] "Apply Filters" button triggers filter execution
- [ ] "Reset" button clears all filters and returns to default view
- [ ] Filter count badge shows active filters (e.g., "3 filters active")
- [ ] Filters work with both AJAX (no page reload) and form submission
- [ ] Date range validates (FROM must be <= TO)
- [ ] Filters update KPI cards and table simultaneously

**Business Value:** Precise transaction lookup, better audit capabilities, faster troubleshooting.

---

### 3. Simplified Transaction Table (CRITICAL)
**Priority:** Critical
**Description:** Clean, focused table showing essential transaction information only.

**Table Columns (10 columns):**
1. **Transaction ID** - Unique identifier (e.g., MERCH...)
2. **Customer Name** - Customer or "Walk-in Customer"
3. **Transaction Type** - Badge (Job Order / Merchandise / Combined)
4. **Vehicle Plate Number** - For job orders (empty for merchandise-only)
5. **Amount** - Total amount (₱X,XXX.XX)
6. **Payment Method** - Cash, GCash, etc.
7. **Shift** - First Shift, Second Shift, etc.
8. **Staff Encoder** - Staff member who created transaction
9. **Date & Time** - Transaction timestamp (MMM DD, YYYY HH:MM AM/PM)
10. **Status** - Validation status badge (Approved, Pending, Voided, etc.)
11. **Actions** - View Details, View Receipt buttons

**Column Details:**

**Transaction ID:**
- Format: `MERCH...` for merchandise, `JO-XXX` for job orders
- Monospace font, clickable (opens details)
- Color-coded by type

**Transaction Type Badge:**
- Job Order: Purple badge
- Merchandise: Blue badge
- Combined: Green badge
- Clear visual distinction

**Vehicle Plate Number:**
- Shows plate number for job orders and combined transactions
- Shows "—" or empty for merchandise-only
- Searchable field

**Status Badge:**
- Approved: Green background
- Pending: Yellow/Orange background
- Voided/Cancelled: Gray background
- Rejected: Red background

**Actions:**
- **View Details** button - Opens modal/page with full transaction details
- **View Receipt** button - Opens receipt in new tab for printing

**Acceptance Criteria:**
- [ ] Table shows exactly 10 columns (no more, no less)
- [ ] Columns are properly aligned (left for text, right for amounts)
- [ ] Table is sortable by clicking column headers
- [ ] Table has pagination (10, 25, 50, 100 rows per page)
- [ ] Table is responsive (horizontal scroll on mobile if needed)
- [ ] Row hover effect for better readability
- [ ] Empty state message when no results found
- [ ] Loading indicator while fetching data

**Business Value:** Clean data presentation, faster information scanning, reduced cognitive load.

---

### 4. Export Functionality (HIGH)
**Priority:** High
**Description:** Allow managers to export filtered transaction data in multiple formats.

**Export Formats:**
1. **Excel (.xlsx)** - Formatted spreadsheet with headers, filters applied
2. **CSV (.csv)** - Plain text format for data processing
3. **PDF (.pdf)** - Printable report with company header and summary

**Export Content:**
- Applies current active filters
- Includes all visible table columns
- Adds summary section (total transactions, total amount)
- Adds generation timestamp and user info
- Respects pagination (exports ALL filtered results, not just current page)

**Acceptance Criteria:**
- [ ] Three export buttons in header: Excel, CSV, PDF
- [ ] Export respects active filters
- [ ] Excel file has proper column formatting (currency, dates)
- [ ] CSV uses proper escaping for commas in text
- [ ] PDF has professional layout with company logo
- [ ] File naming convention: `AllTransactions_YYYY-MM-DD_HHMMSS.ext`
- [ ] Progress indicator for large exports
- [ ] Export limit: 10,000 records max (with warning if exceeded)

**Business Value:** Reporting, compliance, data analysis, record-keeping.

---

### 5. View Details Modal/Page (MEDIUM)
**Priority:** Medium
**Description:** Provide detailed view of individual transactions with complete information.

**Details to Show:**

**Transaction Header:**
- Transaction ID
- Transaction Type (with badge)
- Customer Name
- Contact Number (if available)
- Transaction Date & Time
- Payment Method
- Payment Status
- Shift Period
- Staff Encoder
- Validation Status
- Validated By (manager name)
- Validation Date & Time

**Job Order Details (if applicable):**
- Service Category
- Service Description
- Vehicle Type
- Vehicle Plate Number
- Vehicle Brand/Model
- Labor Cost
- Parts Cost (if any)

**Merchandise Details (if applicable):**
- Product Name
- Quantity
- Unit Price
- Subtotal
- Total per item

**Financial Summary:**
- Subtotal
- VAT Amount (if applicable)
- Discount (if applicable)
- **Grand Total**

**Remarks/Notes:**
- Staff Remarks
- Manager Notes
- Validation Remarks

**Acceptance Criteria:**
- [ ] Opens in modal or dedicated page
- [ ] All relevant information displayed
- [ ] Print button for transaction details
- [ ] "View Receipt" button links to receipt page
- [ ] Close/Back button to return to list
- [ ] Responsive design for mobile viewing

**Business Value:** Complete transaction audit trail, dispute resolution, customer service.

---

### 6. Receipt Quick Access (MEDIUM)
**Priority:** Medium
**Description:** Quick access to transaction receipts from the actions column.

**Acceptance Criteria:**
- [ ] "View Receipt" button in each table row
- [ ] Opens receipt in new browser tab (target="_blank")
- [ ] Receipt URL format: `receipt.php?id=XXX&type=YYY`
- [ ] Receipt loads correctly with all transaction details
- [ ] Receipt is print-ready (browser print dialog)

**Business Value:** Fast receipt reprinting, customer service, verification.

---

### 7. Search Functionality (MEDIUM)
**Priority:** Medium
**Description:** Global search across multiple transaction fields.

**Search Fields:**
- Transaction ID (exact or partial match)
- Customer Name (case-insensitive, partial match)
- Vehicle Plate Number (case-insensitive, partial match)
**Search Behavior:**
- Real-time search (as-you-type) OR search button
- Case-insensitive matching
- Partial match support (e.g., "ABC" matches "ABC-1234")
- Search works in combination with other filters
- Clear button (X icon) to reset search

**Acceptance Criteria:**
- [ ] Search box prominently displayed in filter section
- [ ] Searches across Transaction ID, Customer Name, Plate Number
- [ ] Results update within 1 second
- [ ] No results message displayed when search returns empty
- [ ] Search term persists in URL for sharing
- [ ] Search highlights matching text in results (optional enhancement)

**Business Value:** Fast transaction lookup, better customer service.

---

### 8. Shift Transactions View (MEDIUM)
**Priority:** Medium
**Description:** Dedicated view to monitor and compare transactions per shift period, helping managers track shift performance and collections.

**Purpose:** 
> "Monitor transactions per shift" - Track which shift generated what sales/transactions

**KPI Cards (4 cards):**
1. **Shift 1 Sales** - Total revenue from Shift 1 (₱)
2. **Shift 2 Sales** - Total revenue from Shift 2 (₱)
3. **Shift 1 Transactions** - Count of transactions in Shift 1
4. **Shift 2 Transactions** - Count of transactions in Shift 2

**Table Columns (8 columns):**
1. Transaction ID, 2. Customer Name, 3. Transaction Type, 4. Amount, 5. Payment Method, 6. Staff Encoder, 7. Date & Time, 8. Actions

**Business Value:** Shift accountability, performance comparison, better shift planning.

---

### 9. Transaction Adjustments View (HIGH)
**Priority:** High
**Description:** Allow managers to correct transaction errors without deleting records, maintaining audit trail and data integrity.

**Purpose:**
> "Correct transaction errors without deleting records" - Fix mistakes while preserving history

**KPI Cards (3 cards):**
1. **Total Adjustments** - Count of all adjustment records
2. **Adjustments Today** - Count of adjustments made today
3. **Adjusted Amount** - Total amount difference from adjustments (₱)

**Filters:**
- **Date Range** - From Date / To Date
- **Staff Encoder** - Dropdown of staff members
- **Transaction Type** - Job Order / Merchandise / Combined

**Table Columns (8 columns):**
1. **Adjustment ID** - Unique adjustment record ID
2. **Transaction ID** - Original transaction reference
3. **Customer Name** - Customer name
4. **Original Amount** - Amount before adjustment
5. **Updated Amount** - Amount after adjustment
6. **Adjustment Reason** - Why the adjustment was made
7. **Adjusted By** - Manager who made the adjustment
8. **Adjustment Date** - When the adjustment was made

**Adjustment Modal (for creating/editing adjustments):**

**Transaction Information (Read-Only):**
- Transaction ID
- Customer Name
- Transaction Type (badge)

**Editable Fields:**
- **Quantity** - Item/service quantity (can be adjusted)
- **Unit Price** - Price per unit (can be adjusted)
- **Service Fee** - Service charge (can be adjusted for job orders)
- **Payment Method** - Cash, GCash, Card, etc. (can be changed)
- **Payment Status** - Paid, Unpaid, Partial (can be updated)

**Required Fields:**
- **Adjustment Reason** - Dropdown + text field
  - Options: Pricing Error, Quantity Mismatch, Payment Method Change, Customer Request, Other
- **Manager Remarks** - Free text field for additional notes

**System Generated (Auto-filled):**
- **Adjustment ID** - Auto-incremented ID
- **Adjusted By** - Current logged-in manager
- **Adjustment Date** - Current timestamp

**Modal Actions:**
- **Save Adjustment** button (primary blue) - Saves changes and creates adjustment record
- **Cancel** button (secondary gray) - Closes modal without saving

**Acceptance Criteria:**
- [ ] KPI cards display correct counts and totals
- [ ] Filters work independently and in combination
- [ ] Table displays all 8 columns clearly
- [ ] "Adjust" button opens adjustment modal
- [ ] Modal pre-fills current transaction data
- [ ] Editable fields can be modified
- [ ] Adjustment reason is required
- [ ] Original transaction is NOT modified (remains intact)
- [ ] New adjustment record is created in `transaction_adjustments` table
- [ ] Adjustment history is viewable
- [ ] Export buttons work (Excel, CSV, PDF)
- [ ] Adjustment audit trail is maintained
- [ ] Only managers/admins can access
- [ ] Adjusted amount calculation is correct (Updated - Original)

**Database Schema:**

```sql
CREATE TABLE IF NOT EXISTS transaction_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(50) NOT NULL,
    transaction_type ENUM('job_order', 'merchandise', 'combined') NOT NULL,
    original_amount DECIMAL(10,2) NOT NULL,
    updated_amount DECIMAL(10,2) NOT NULL,
    amount_difference DECIMAL(10,2) NOT NULL, -- calculated: updated - original
    adjustment_reason VARCHAR(255) NOT NULL,
    manager_remarks TEXT,
    adjusted_by INT NOT NULL, -- user_id of manager
    adjustment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    station_id INT NOT NULL,
    -- Fields that were changed (JSON or individual columns)
    fields_changed JSON, -- e.g., {"quantity": {"old": 2, "new": 3}, "unit_price": {"old": 100, "new": 120}}
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_adjustment_date (adjustment_date),
    INDEX idx_station_id (station_id),
    FOREIGN KEY (adjusted_by) REFERENCES users(id),
    FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Business Value:**
- Error correction without data loss
- Complete audit trail
- Compliance with accounting standards
- Dispute resolution
- Manager accountability

**Header Buttons:**
- **Back** - Return to transactions menu
- **Export Excel** - Export adjustment records to Excel
- **Export CSV** - Export adjustment records to CSV
- **Export PDF** - Export adjustment summary report to PDF

---

---

## Non-Functional Requirements

### Performance
- Page load time: < 2 seconds for 1000 records
- Filter application: < 1 second
- Export generation: < 5 seconds for 5000 records
- Search results: < 1 second

### Usability
- Mobile-responsive design (works on tablets)
- Clear visual hierarchy - important data stands out
- Consistent styling with existing Petron system
- Accessible keyboard navigation
- Touch-friendly buttons on tablets

### Security
- Manager/Admin role required to access page
- Staff cannot access this page (role-based access control)
- Export logs who exported what data and when
- No sensitive data exposure in URLs (use POST for exports)

### Data Integrity
- All amounts are accurate (2 decimal places)
- Transaction counts match database reality
- Exports contain complete, unmodified data
- No data loss during filtering

---

## Dependencies
- Existing database tables: `merchandise_transactions`, `job_orders`, `merchandise_transaction_items`
- User authentication and RBAC system
- Existing receipt generation system
- Export libraries: PHPExcel or PhpSpreadsheet (Excel), TCPDF or mPDF (PDF)

---

## Success Metrics
- **Usability:** Managers can find any transaction within 30 seconds
- **Adoption:** 80% of managers use filters regularly
- **Efficiency:** 50% reduction in time spent looking for transactions
- **Export Usage:** 30% of manager sessions include an export
- **Error Rate:** 0% incorrect data in exports

---

## Open Questions
1. Should the page auto-refresh to show new transactions in real-time?
2. What's the default date range on page load (Today? Last 7 days? All time?)?
3. Should exports be limited to a specific date range to prevent huge files?
4. Should there be a "Quick Stats" widget showing trends (up/down arrows)?
5. Should managers be able to save favorite filter combinations?
6. Should the table support multi-column sorting (e.g., sort by date then by amount)?

---

## Out of Scope (For Now)
- Transaction editing/modification (view-only page)
- Bulk transaction approval/rejection (separate workflow)
- Transaction voiding from this page (use dedicated void page)
- Advanced analytics/charts (use Reports page)
- Email notifications for new transactions
- Real-time updates (polling/WebSocket)
- Transaction annotations/comments
- Audit log viewing from this page

---

**Document Version:** 3.0 (Manager All Transactions Focus)
**Created:** June 23, 2026  
**Updated:** June 23, 2026
**Status:** Draft - Ready for Review
