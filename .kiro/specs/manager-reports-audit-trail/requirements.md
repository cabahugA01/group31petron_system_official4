# Manager Reports & Audit Trail - Requirements

## Overview
Comprehensive reporting system for Managers with validation logs (audit trail) showing Manager's approval/rejection actions on staff encodings.

## User Story
**As a Manager**, I need access to comprehensive reports with confidential data (discounts, credit usage, balances) and an audit trail of my validation actions to ensure transparency, accountability, and compliance.

## Scope

### In Scope
✅ Manager Reports (all station data with confidential fields)
✅ Validation Audit Trail (Manager's validation actions only)
✅ Export functionality (Excel/CSV) with full station scope
✅ Summary cards for key metrics
✅ Back button implementation
✅ Error-proof queries with graceful fallbacks
✅ Date range filtering (Today, This Week, This Month, Custom)

### Out of Scope
❌ Admin-level audit trail (separate implementation)
❌ Staff-level reports (already implemented)
❌ Real-time notifications
❌ Automated scheduled reports

## Report Sections

### 1. Sales Reports
**Data Source:** `sales` table, `fuel_transactions` table, `merchandise_transactions` table

**Sub-tabs:**
- **Fuel Sales** - Daily fuel sales by type with variance alerts
- **Merchandise Sales** - Daily merchandise transactions with payment breakdown
- **Sales Summary** - Combined daily revenue (fuel + merchandise)
- **Payment Methods** - Breakdown by cash, card, e-wallet, credit

**Confidential Fields Included:**
- Discount amounts
- Credit usage per customer
- Payment method details
- Actual vs expected variance

### 2. Job Orders Reports
**Data Source:** `job_orders` table

**Sub-tabs:**
- **Job Orders List** - All job orders with validation status
- **Validation Status** - Approved, Rejected, Returned, Pending
- **Revenue Analysis** - Labor cost, parts cost, total revenue

**Confidential Fields Included:**
- Labor cost breakdown
- Parts cost details
- Mechanic assignments
- Customer credit usage

**Validation Status:**
- ✅ Approved (Manager validated)
- ❌ Rejected (Manager declined)
- 🔄 Returned (Sent back to staff for revision)
- ⏳ Pending (Awaiting Manager validation)

### 3. Deliveries Reports
**Data Source:** `deliveries_oversight` table, `fuel_deliveries` table

**Sub-tabs:**
- **Fuel Deliveries** - Fuel stock replenishments with validation logs
- **Merchandise Deliveries** - Merchandise stock with supplier details
- **Delivery Validation** - Manager approval/rejection of deliveries

**Confidential Fields Included:**
- Supplier details
- Cost per unit
- Delivery discrepancies
- Validation remarks

### 4. Meter Reading Reports
**Data Source:** `fuel_readings` table, `meter_readings` table

**Sub-tabs:**
- **Daily Readings** - Pump meter readings by shift
- **Variance Analysis** - Discrepancies between pump readings and sales
- **Pump Performance** - Individual pump efficiency

**Confidential Fields Included:**
- Actual vs expected liters
- Variance amounts
- Pump-specific issues

### 5. Payments Reports
**Data Source:** `payments` table, `job_orders` table (payment_status), `customers` table (credit_limit, balance)

**Sub-tabs:**
- **Credit Monitoring** - Outstanding balances per customer
- **Overdue Accounts** - Past due payments with aging analysis
- **Payment History** - Customer payment patterns
- **Credit Utilization** - Credit line usage percentage

**Confidential Fields Included:**
- Customer credit limits
- Outstanding balances
- Payment history
- Overdue amounts with aging (30, 60, 90+ days)

### 6. Customer Reports
**Data Source:** `customers` table, `balances` table, `transactions` table

**Sub-tabs:**
- **Customer Profiles** - Full customer details with credit status
- **Suki Accounts** - Loyal customers with special terms
- **Transaction History** - Per-customer station history
- **Credit Line Management** - Credit limits and utilization

**Confidential Fields Included:**
- Full contact information
- Credit limits and terms
- Balance details
- Transaction history with amounts

### 7. Validation Logs (Combined Validation Reports + Audit Trail) ⭐ NEW
**Data Source:** `validation_logs` table

**Sub-tabs:**
- **My Validations** - Manager's own validation actions (audit trail)
- **Pending Validations** - Items awaiting Manager approval
- **Validation Summary** - Approval/rejection statistics
- **All Validations** - Admin-only: sees all managers' actions

**Purpose:**
- **Validation Reports** - Track pending items needing approval
- **Audit Trail** - Historical log of all Manager validation actions
- **Combined View** - No need for separate menu items (avoid redundancy)

**Fields:**
- Transaction ID / Customer ID
- Transaction Type (Job Order, Delivery, Transaction)
- Staff who encoded
- Manager action (Approve, Reject, Return, Adjust)
- Remarks / Reason for decision
- Timestamp (date & time)
- Original Amount
- Validated Amount

**Visibility:**
- **Manager:** Sees only their own validation logs + pending items for their station
- **Admin:** Sees all validation logs (all managers, all stations) + all pending items

## Summary Cards (Manager Dashboard)

### Key Metrics:
1. **Validated Customers** - from `customers` table where `validation_status = 'validated'`
2. **Active Credit Accounts** - from `balances` table where `balance > 0`
3. **Outstanding Balances** - Sum of unpaid balances from `payments` table
4. **Pending Validations** - Count of items awaiting Manager approval
5. **Today's Sales** - Combined fuel + merchandise revenue
6. **Variance Alerts** - Count of transactions with discrepancies

## Technical Requirements

### Database Schema

#### validation_logs table
```sql
CREATE TABLE IF NOT EXISTS validation_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manager_id INT NOT NULL,
    transaction_id VARCHAR(100) NOT NULL,
    transaction_type ENUM('job_order', 'delivery', 'fuel_transaction', 'merchandise_transaction', 'customer_profile') NOT NULL,
    customer_id INT NULL,
    staff_id INT NULL,
    original_amount DECIMAL(10,2) NULL,
    validated_amount DECIMAL(10,2) NULL,
    action_taken ENUM('Approve', 'Reject', 'Return', 'Adjust') NOT NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    station_id INT NOT NULL,
    INDEX idx_manager (manager_id),
    INDEX idx_transaction (transaction_id, transaction_type),
    INDEX idx_station_date (station_id, created_at),
    FOREIGN KEY (manager_id) REFERENCES users(id),
    FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Error Handling

**Defensive Queries:**
- Check table existence before querying
- Check column existence using `has_col()` helper
- Wrap all queries in try-catch blocks
- Provide graceful fallbacks with empty data arrays
- Display meaningful error messages

**Pattern:**
```php
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'table_name'")->fetchAll();
    if (empty($tables)) {
        $report_data = [];
        $summary_cards = [/* default values */];
    } else {
        // Execute query
    }
} catch (Exception $e) {
    $report_data = [];
    $summary_cards = [/* default values */];
    $error_message = 'Unable to load report data';
}
```

## UI/UX Requirements

### Navigation
**Sidebar:**
```
Reports
├── Sales Reports
├── Job Orders Reports
├── Deliveries Reports
├── Meter Readings
├── Payments Reports
├── Customer Reports
└── Validation Logs ⭐ NEW (Combined validation + audit trail)
```

**Note:** Validation Logs serves as both validation reports AND audit trail - no separate menu item needed to avoid redundancy.

### Back Button
- Implement on all report detail views
- Returns to report list/dashboard
- Preserves date range filters

### Export Buttons
**Location:** Top-right of each report table
**Formats:**
- 📊 Excel (XLSX)
- 📄 CSV
- 📑 PDF (optional)

**Scope:** Full station data (not filtered by user)

### Date Range Filter
**Options:**
- Today
- This Week
- This Month
- Custom (date picker)

**Behavior:**
- Persistent across tabs
- Applied to all reports
- URL parameter based

### Loading States
- Skeleton loaders for tables
- Spinner for export operations
- Empty state messages

## Security & Permissions

### Access Control
- Manager role required
- Station-specific data only
- No cross-station data access

### Data Visibility
- Manager sees full station data
- Includes confidential fields (discounts, credit, balances)
- Audit trail shows only Manager's own actions (Admin sees all)

### Audit Trail Separation
- **Staff:** Cannot see validation logs
- **Manager:** Sees only their own validation actions
- **Admin:** Sees all validation logs (Staff + Manager + Admin)

## Acceptance Criteria

✅ Manager can view all 7 report sections (Sales, Job Orders, Deliveries, Meter, Payments, Customers, Validation Logs)
✅ Reports display confidential data correctly
✅ Validation Logs shows both pending validations AND audit trail (Manager's actions)
✅ Export functionality works for all reports (CSV, Excel)
✅ Summary cards display accurate real-time metrics
✅ Back button returns to appropriate views
✅ Date range filtering works across all reports
✅ Zero SQL errors (all queries protected)
✅ Graceful fallbacks for missing data
✅ Validation logs capture all required fields
✅ Validation Logs section is filterable and searchable
✅ Admin can see full validation logs, Manager sees only their own
✅ No redundant menu items (Validation Logs = Validation Reports + Audit Trail combined)

## Dependencies
- Existing database tables (sales, job_orders, deliveries, etc.)
- New validation_logs table
- User authentication system
- Station assignment system
- Role-based access control

## Success Metrics
- Zero SQL errors in production
- All reports load within 3 seconds
- Export files generate successfully
- Validation logs capture 100% of Manager actions
- Manager satisfaction with data visibility
- Compliance with audit requirements
