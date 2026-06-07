# Manager Reports & Audit Trail - Implementation Tasks

## Phase 1: Database Setup ✅

### Task 1.1: Create validation_logs Table
**Priority:** High
**Estimated Time:** 30 minutes

**Subtasks:**
- [ ] Create migration script for validation_logs table
- [ ] Add all required columns (manager_id, transaction_id, action_taken, etc.)
- [ ] Create indexes for performance (manager_id, transaction_id, station_id, created_at)
- [ ] Add foreign key constraints
- [ ] Test table creation on dev database
- [ ] Verify indexes are working

**SQL Script:**
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
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    INDEX idx_manager (manager_id),
    INDEX idx_transaction (transaction_id, transaction_type),
    INDEX idx_station_date (station_id, created_at),
    INDEX idx_action (action_taken),
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Acceptance Criteria:**
- ✅ Table created successfully
- ✅ All indexes working
- ✅ Foreign keys enforced
- ✅ Can insert test records

---

## Phase 2: Backend - Validation Logger ✅

### Task 2.1: Create validation_logger.php Helper
**Priority:** High
**Estimated Time:** 1 hour

**Location:** `backend/validation_logger.php`

**Subtasks:**
- [ ] Create helper function `log_validation_action()`
- [ ] Validate required parameters
- [ ] Insert record into validation_logs table
- [ ] Capture IP address and user agent
- [ ] Return success/error response
- [ ] Add error handling and logging

**Function Signature:**
```php
function log_validation_action(
    PDO $pdo,
    int $manager_id,
    string $transaction_id,
    string $transaction_type,
    string $action_taken,
    ?string $remarks = null,
    ?int $customer_id = null,
    ?int $staff_id = null,
    ?float $original_amount = null,
    ?float $validated_amount = null,
    int $station_id
): array
```

**Acceptance Criteria:**
- ✅ Function successfully logs validation actions
- ✅ All required fields captured
- ✅ Returns proper success/error responses
- ✅ Error handling works correctly

---

### Task 2.2: Create manager_reports_data.php API
**Priority:** Medium
**Estimated Time:** 2 hours

**Location:** `backend/manager_reports_data.php`

**Subtasks:**
- [ ] Create API endpoint for report data
- [ ] Implement section-based routing (sales, job_orders, etc.)
- [ ] Add date range filtering
- [ ] Implement error handling with try-catch
- [ ] Return JSON formatted data
- [ ] Add CORS headers if needed

**Endpoints:**
```
GET /backend/manager_reports_data.php?section=sales&date_start=...&date_end=...
GET /backend/manager_reports_data.php?section=audit_trail&date_start=...&date_end=...
```

**Acceptance Criteria:**
- ✅ API returns correct data format
- ✅ Date filtering works
- ✅ Error responses are proper JSON
- ✅ Performance is acceptable (<3 sec)

---

## Phase 3: Frontend - Audit Trail Page 🆕

### Task 3.1: Create manager_validation_logs.php (Combined Page)
**Priority:** High
**Estimated Time:** 3 hours

**Location:** `public/manager_validation_logs.php`

**Purpose:** Single page that serves both Validation Reports AND Audit Trail (avoid redundancy)

**Subtasks:**
- [ ] Create page structure with header/navigation
- [ ] Add date range filter UI
- [ ] Add sub-tabs for different views:
  - **My Validations** (Manager's audit trail)
  - **Pending Validations** (items needing approval)
  - **Validation Summary** (statistics)
  - **All Validations** (Admin-only: all managers)
- [ ] Create validation logs table with proper columns
- [ ] Implement query to fetch validation logs
- [ ] Add filtering by action type and transaction type
- [ ] Add search functionality (transaction ID, customer name)
- [ ] Implement pagination (10, 25, 50, 100 per page)
- [ ] Add export buttons (CSV, Excel)
- [ ] Style with Petron brand colors
- [ ] Add back button

**Table Columns:**
1. Date & Time
2. Transaction Type
3. Transaction ID
4. Customer Name
5. Staff Encoder
6. Action Taken (with badge colors)
7. Original Amount
8. Validated Amount
9. Remarks
10. Status

**Sub-tab Logic:**
- **My Validations (Audit Trail):** Shows Manager's historical actions
  - Query: `WHERE manager_id = $user_id`
  - All approved, rejected, returned, adjusted items
  
- **Pending Validations:** Shows items awaiting approval
  - Query: Job orders, deliveries, transactions with status = 'Pending Validation'
  - Not yet in validation_logs table
  
- **Validation Summary:** Statistics
  - Total Approved, Rejected, Returned, Adjusted
  - Approval rate percentage
  - Charts/graphs (optional)
  
- **All Validations (Admin Only):** Full audit trail
  - Query: No manager_id filter
  - Shows all managers' actions across all stations

**Acceptance Criteria:**
- ✅ Page loads without errors
- ✅ Manager sees only their own logs in "My Validations"
- ✅ Admin sees all logs in "All Validations"
- ✅ Pending tab shows items needing approval
- ✅ Filtering works correctly
- ✅ Search returns relevant results
- ✅ Export generates valid files
- ✅ Pagination works smoothly
- ✅ No redundant pages/menu items

---

### Task 3.2: Enhance manager_reports.php
**Priority:** High
**Estimated Time:** 4 hours

**Location:** `public/manager_reports.php` (EXISTING FILE)

**Subtasks:**
- [ ] Review current implementation
- [ ] Add Validation Logs section to navigation (replaces separate validation + audit trail)
- [ ] Ensure all queries are error-proof (try-catch, table checks)
- [ ] Implement validation_logs section with sub-tabs:
  - My Validations (audit trail)
  - Pending Validations
  - Validation Summary
  - All Validations (Admin only)
- [ ] Implement summary cards for dashboard
- [ ] Add back button to all report views
- [ ] Enhance export functionality for all reports
- [ ] Test all 7 report sections
- [ ] Fix any SQL errors

**Report Sections (Total: 7)**
1. Sales Reports
2. Job Orders Reports
3. Deliveries Reports
4. Meter Readings
5. Payments Reports
6. Customer Reports
7. Validation Logs (NEW - combines validation + audit trail)

**Summary Cards to Add:**
```php
$summary_cards = [
    ['label' => 'Validated Customers', 'value' => $validated_count, 'icon' => 'fa-user-check', 'class' => 'stat-blue'],
    ['label' => 'Active Credit Accounts', 'value' => $credit_count, 'icon' => 'fa-credit-card', 'class' => 'stat-green'],
    ['label' => 'Outstanding Balances', 'value' => '₱' . number_format($outstanding, 2), 'icon' => 'fa-money-bill-wave', 'class' => 'stat-red'],
    ['label' => 'Pending Validations', 'value' => $pending_count, 'icon' => 'fa-clock', 'class' => 'stat-orange'],
];
```

**Acceptance Criteria:**
- ✅ All 7 sections load without SQL errors
- ✅ Summary cards display correct data
- ✅ Export works for all sections
- ✅ Back button implemented
- ✅ Validation Logs section functional
- ✅ Responsive design maintained
- ✅ No redundant sections

---

## Phase 4: Integration - Hook Validation Actions 🔗

### Task 4.1: Integrate Logger into Validation Screens
**Priority:** High
**Estimated Time:** 2 hours

**Files to Modify:**
- `public/manager_fuel_transaction_validation.php`
- `public/pending_transactions.php`
- `public/manager_deliveries_validation.php`
- Any other validation screens

**Subtasks:**
- [ ] Add validation_logger.php include
- [ ] Call `log_validation_action()` on Approve
- [ ] Call `log_validation_action()` on Reject
- [ ] Call `log_validation_action()` on Return
- [ ] Call `log_validation_action()` on Adjust
- [ ] Pass all required parameters (transaction_id, amounts, etc.)
- [ ] Test each action type

**Example Integration:**
```php
// After approving a transaction
if ($validation_success) {
    require_once __DIR__ . '/../backend/validation_logger.php';
    
    log_validation_action(
        $pdo,
        $manager_id,
        $transaction_id,
        'job_order',
        'Approve',
        $_POST['remarks'] ?? null,
        $customer_id,
        $staff_id,
        $original_amount,
        $validated_amount,
        $station_id
    );
}
```

**Acceptance Criteria:**
- ✅ All validation actions are logged
- ✅ Logs contain accurate data
- ✅ No performance degradation
- ✅ Errors don't break validation flow

---

## Phase 5: Navigation & UI Polish 🎨

### Task 5.1: Update Sidebar Navigation
**Priority:** Medium
**Estimated Time:** 1 hour

**Files to Modify:**
- `partials/header.php` or navigation include

**Subtasks:**
- [ ] Add "Validation Logs" link under Reports section (combines validation + audit trail)
- [ ] Add appropriate icon (fa-list-check or fa-clipboard-check)
- [ ] Ensure proper active state styling
- [ ] Test navigation from all pages
- [ ] Remove any separate "Audit Trail" menu item (avoid redundancy)

**Navigation Structure:**
```
Reports
├── Sales Reports
├── Job Orders Reports
├── Deliveries Reports
├── Meter Readings
├── Payments Reports
├── Customer Reports
└── Validation Logs ⭐ NEW (Combined: Pending Validations + Audit Trail)
```

**Note:** Validation Logs serves dual purpose:
1. Shows pending items needing validation
2. Shows historical audit trail of validation actions
3. No need for separate "Audit Trail" menu item

**Acceptance Criteria:**
- ✅ Link appears in sidebar for Managers and Admins
- ✅ Not visible to Staff
- ✅ Active state works correctly
- ✅ Navigation is intuitive
- ✅ No redundant "Audit Trail" link

---

### Task 5.2: Add Summary Cards to Manager Dashboard
**Priority:** Medium
**Estimated Time:** 2 hours

**Location:** `public/manager_dashboard.php`

**Subtasks:**
- [ ] Create summary card component
- [ ] Query data for each card
- [ ] Add error handling for queries
- [ ] Style cards with Petron colors
- [ ] Make cards clickable (link to relevant reports)
- [ ] Add loading states

**Cards:**
1. Validated Customers
2. Active Credit Accounts
3. Outstanding Balances
4. Pending Validations
5. Today's Sales
6. Variance Alerts

**Acceptance Criteria:**
- ✅ All cards display accurate data
- ✅ Cards are responsive
- ✅ Clicking navigates to correct report
- ✅ Loading states work properly

---

## Phase 6: Export Functionality 📊

### Task 6.1: Implement CSV Export for Audit Trail
**Priority:** Medium
**Estimated Time:** 1.5 hours

**Subtasks:**
- [ ] Add export handler in manager_audit_trail.php
- [ ] Query validation logs with current filters
- [ ] Generate CSV with proper headers
- [ ] Include all columns
- [ ] Add summary statistics at top
- [ ] Test with various date ranges
- [ ] Test with large datasets

**CSV Format:**
```
VALIDATION AUDIT TRAIL
Manager: John Doe
Station: Vamenta Blvd Station
Period: June 1-30, 2026
Total Approvals: 150
Total Rejections: 5
Total Returns: 3

Date & Time,Transaction Type,Transaction ID,Customer,Staff,Action,Original Amount,Validated Amount,Remarks
...
```

**Acceptance Criteria:**
- ✅ CSV downloads successfully
- ✅ Data is properly formatted
- ✅ Special characters handled correctly
- ✅ File opens in Excel without issues

---

### Task 6.2: Implement Excel Export for Audit Trail
**Priority:** Low
**Estimated Time:** 2 hours

**Library:** PHPSpreadsheet or similar

**Subtasks:**
- [ ] Install PHPSpreadsheet if not available
- [ ] Create Excel export handler
- [ ] Add formatting (headers, colors, borders)
- [ ] Include summary sheet
- [ ] Add data validation
- [ ] Test file generation
- [ ] Optimize for large datasets

**Acceptance Criteria:**
- ✅ Excel file downloads successfully
- ✅ Formatting looks professional
- ✅ Summary sheet displays correctly
- ✅ Compatible with Excel 2016+

---

## Phase 7: Testing & Quality Assurance ✅

### Task 7.1: Unit Testing
**Priority:** High
**Estimated Time:** 3 hours

**Subtasks:**
- [ ] Test validation_logger.php function
- [ ] Test all report queries with missing tables
- [ ] Test date range calculations
- [ ] Test export file generation
- [ ] Test permission checks
- [ ] Test error handling paths

**Test Cases:**
1. Log validation action with all parameters
2. Log validation action with minimal parameters
3. Query non-existent table gracefully
4. Export with 0 records
5. Export with 10,000+ records
6. Manager accessing admin-only data (should fail)
7. Invalid date ranges
8. SQL injection attempts

**Acceptance Criteria:**
- ✅ All test cases pass
- ✅ No SQL errors
- ✅ Proper error messages
- ✅ Security holds

---

### Task 7.2: Integration Testing
**Priority:** High
**Estimated Time:** 2 hours

**Subtasks:**
- [ ] Test full validation flow (encoding → approval → log)
- [ ] Test audit trail visibility (Manager vs Admin)
- [ ] Test export with applied filters
- [ ] Test navigation between reports
- [ ] Test back button functionality
- [ ] Test on different browsers (Chrome, Firefox, Edge)
- [ ] Test on mobile devices

**Acceptance Criteria:**
- ✅ End-to-end flows work correctly
- ✅ No broken links
- ✅ Consistent behavior across browsers
- ✅ Mobile responsiveness acceptable

---

### Task 7.3: Performance Testing
**Priority:** Medium
**Estimated Time:** 2 hours

**Subtasks:**
- [ ] Test report loading with large datasets
- [ ] Test export generation time
- [ ] Test pagination performance
- [ ] Identify slow queries
- [ ] Optimize queries if needed
- [ ] Add database indexes if needed

**Performance Targets:**
- Report page load: < 3 seconds
- Export generation: < 10 seconds (up to 10,000 records)
- Pagination: < 1 second
- Search: < 2 seconds

**Acceptance Criteria:**
- ✅ All targets met
- ✅ No timeout errors
- ✅ Smooth user experience

---

## Phase 8: Documentation & Deployment 📚

### Task 8.1: Create User Guide
**Priority:** Medium
**Estimated Time:** 2 hours

**Subtasks:**
- [ ] Document how to access reports
- [ ] Document how to use date filters
- [ ] Document how to export reports
- [ ] Document audit trail interpretation
- [ ] Create screenshots
- [ ] Document troubleshooting steps

**Acceptance Criteria:**
- ✅ Guide is clear and comprehensive
- ✅ Screenshots are up-to-date
- ✅ Covers all manager scenarios

---

### Task 8.2: Create Admin Guide
**Priority:** Low
**Estimated Time:** 1 hour

**Subtasks:**
- [ ] Document admin-specific features
- [ ] Document how to view full audit trail
- [ ] Document system monitoring
- [ ] Document maintenance tasks

**Acceptance Criteria:**
- ✅ Admin guide covers unique features
- ✅ Maintenance procedures documented

---

### Task 8.3: Deployment to Production
**Priority:** High
**Estimated Time:** 1 hour

**Subtasks:**
- [ ] Run database migration on production
- [ ] Deploy backend files
- [ ] Deploy frontend files
- [ ] Deploy CSS/JS assets
- [ ] Update navigation includes
- [ ] Clear application cache
- [ ] Test in production environment
- [ ] Monitor error logs for 24 hours
- [ ] Gather initial user feedback

**Acceptance Criteria:**
- ✅ No deployment errors
- ✅ All features working in production
- ✅ No error spikes in logs
- ✅ Positive initial feedback

---

## Phase 9: Post-Deployment Monitoring 📊

### Task 9.1: Monitor System Performance
**Priority:** High
**Estimated Time:** Ongoing (1 week)

**Subtasks:**
- [ ] Monitor database query performance
- [ ] Check error logs daily
- [ ] Track validation log growth
- [ ] Monitor export usage
- [ ] Gather user feedback
- [ ] Identify pain points

**Acceptance Criteria:**
- ✅ No critical errors
- ✅ Performance within targets
- ✅ User satisfaction high

---

### Task 9.2: Iterative Improvements
**Priority:** Low
**Estimated Time:** Ongoing

**Subtasks:**
- [ ] Address user feedback
- [ ] Optimize slow queries
- [ ] Add requested features
- [ ] Improve UI/UX based on usage
- [ ] Update documentation

**Acceptance Criteria:**
- ✅ Major issues resolved within 48 hours
- ✅ Feature requests prioritized
- ✅ Continuous improvement cycle established

---

## Summary

**Total Estimated Time:** 30-35 hours
**Total Tasks:** 21 tasks across 9 phases

**Critical Path:**
1. Database Setup (Task 1.1)
2. Validation Logger (Task 2.1)
3. Audit Trail Page (Task 3.1)
4. Integration (Task 4.1)
5. Testing (Tasks 7.1, 7.2)
6. Deployment (Task 8.3)

**Priority Breakdown:**
- **High Priority:** 12 tasks (core functionality)
- **Medium Priority:** 7 tasks (enhancements)
- **Low Priority:** 2 tasks (nice-to-have)

**Team Allocation Recommendation:**
- 1 Backend Developer: Tasks 1.1, 2.1, 2.2, 4.1
- 1 Frontend Developer: Tasks 3.1, 3.2, 5.1, 5.2
- 1 Full-Stack Developer: Tasks 6.1, 6.2, 7.x, 8.x
