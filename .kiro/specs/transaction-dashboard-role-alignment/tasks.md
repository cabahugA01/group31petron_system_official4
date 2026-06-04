# Tasks

## Task 1: Create Backend API for Staff Transaction Metrics
**Status:** ✅ completed  
**Dependencies:** None  
**Description:** Create PHP API endpoint to calculate and return Staff dashboard metrics (transactions encoded, pending payments, completed job orders)

### Subtasks
- [x] Create `backend/api/staff_transaction_metrics.php`
- [x] Implement query for transactions encoded count (merchandise + job orders)
- [x] Implement query for pending payments sum
- [x] Implement query for completed job orders count
- [x] Return JSON response with metrics
- [x] Add error handling and logging

---

## Task 2: Update Staff Dashboard Summary Cards
**Status:** pending  
**Dependencies:** Task 1  
**Description:** Add summary cards to staff_dashboard.php displaying transactions encoded, pending payments, and completed job orders

### Subtasks
- [ ] Add summary cards HTML structure to staff_dashboard.php
- [ ] Add AJAX call to fetch metrics from `staff_transaction_metrics.php`
- [ ] Display "Transactions Encoded" card with count
- [ ] Display "Pending Payments" card with amount (₱ format)
- [ ] Display "Completed Job Orders" card with count
- [ ] Add icons (fa-list, fa-wallet, fa-check-circle)
- [ ] Apply Petron Blue (#002F70) styling

---

## Task 3: Implement Staff Dashboard Charts
**Status:** pending  
**Dependencies:** Task 1  
**Description:** Add Chart.js visualizations for job order status and merchandise sales snapshot

### Subtasks
- [ ] Include Chart.js library in staff_dashboard.php
- [ ] Create API endpoint `backend/api/staff_chart_data.php` for chart data
- [ ] Implement job order status distribution query (Pending, Ongoing, Completed)
- [ ] Implement merchandise sales snapshot query (daily/weekly totals)
- [ ] Add canvas elements for charts in staff_dashboard.php
- [ ] Initialize doughnut chart for job order status
- [ ] Initialize bar chart for merchandise sales
- [ ] Add chart refresh on data update

---

## Task 4: Create Staff Export Endpoints
**Status:** pending  
**Dependencies:** None  
**Description:** Create backend export scripts for job orders and merchandise history

### Subtasks
- [ ] Create `backend/export/export_job_orders.php`
- [ ] Implement Excel export for job orders (PHPSpreadsheet)
- [ ] Implement CSV export for job orders
- [ ] Implement PDF export for job orders (TCPDF/FPDF)
- [ ] Create `backend/export/export_merchandise.php`
- [ ] Implement Excel export for merchandise
- [ ] Implement CSV export for merchandise
- [ ] Implement PDF export for merchandise
- [ ] Add proper headers for file download
- [ ] Filter by staff_id (current user only)

---

## Task 5: Add Staff Export Buttons
**Status:** pending  
**Dependencies:** Task 4  
**Description:** Add export buttons to staff dashboard with download handlers

### Subtasks
- [ ] Add export buttons section to staff_dashboard.php
- [ ] Add "Export Job Orders" button with format dropdown (Excel/CSV/PDF)
- [ ] Add "Export Merchandise History" button with format dropdown
- [ ] Implement AJAX handlers for export downloads
- [ ] Add loading indicators during export generation
- [ ] Add success/error notifications

---

## Task 6: Create Backend API for Manager Validation Metrics
**Status:** pending  
**Dependencies:** None  
**Description:** Create PHP API endpoint to calculate and return Manager dashboard metrics (pending transactions, validated today, variance alerts)

### Subtasks
- [ ] Create `backend/api/manager_validation_metrics.php`
- [ ] Implement query for pending transactions count (station-level)
- [ ] Implement query for validated today count
- [ ] Implement query for variance alerts count
- [ ] Return JSON response with metrics
- [ ] Add error handling and logging

---

## Task 7: Update Manager Dashboard Summary Cards
**Status:** pending  
**Dependencies:** Task 6  
**Description:** Add summary cards to manager_dashboard.php displaying pending transactions, validated today, and variance alerts

### Subtasks
- [ ] Add summary cards HTML structure to manager_dashboard.php
- [ ] Add AJAX call to fetch metrics from `manager_validation_metrics.php`
- [ ] Display "Pending Transactions" card with count
- [ ] Display "Validated Today" card with count
- [ ] Display "Variance Alerts" card with count
- [ ] Add icons (fa-clock, fa-check, fa-exclamation-triangle)
- [ ] Apply Petron Blue (#002F70) styling

---

## Task 8: Implement Manager Dashboard Charts
**Status:** pending  
**Dependencies:** Task 6  
**Description:** Add Chart.js visualizations for validation flow and variance trends

### Subtasks
- [ ] Include Chart.js library in manager_dashboard.php
- [ ] Create API endpoint `backend/api/manager_chart_data.php` for chart data
- [ ] Implement validation flow query (Pending vs Validated counts)
- [ ] Implement variance trend query (variance frequency over time)
- [ ] Add canvas elements for charts in manager_dashboard.php
- [ ] Initialize bar chart for validation flow
- [ ] Initialize line chart for variance trends
- [ ] Add chart refresh on data update

---

## Task 9: Create Manager Export Endpoints
**Status:** pending  
**Dependencies:** None  
**Description:** Create backend export scripts for pending/validated transactions and variance reports

### Subtasks
- [ ] Create `backend/export/export_pending_transactions.php`
- [ ] Implement Excel/CSV export for pending transactions
- [ ] Create `backend/export/export_validated_transactions.php`
- [ ] Implement Excel/CSV export for validated transactions
- [ ] Create `backend/export/export_variance_reports.php`
- [ ] Implement PDF export for variance reports (compliance format)
- [ ] Add proper headers for file download
- [ ] Filter by station_id (Manager scope)

---

## Task 10: Add Manager Export Buttons
**Status:** pending  
**Dependencies:** Task 9  
**Description:** Add export buttons to manager dashboard with download handlers

### Subtasks
- [ ] Add export buttons section to manager_dashboard.php
- [ ] Add "Export Pending Transactions" button (Excel/CSV)
- [ ] Add "Export Validated Transactions" button (Excel/CSV)
- [ ] Add "Export Variance Reports" button (PDF)
- [ ] Implement AJAX handlers for export downloads
- [ ] Add loading indicators during export generation
- [ ] Add success/error notifications

---

## Task 11: Create Backend API for Admin Oversight Metrics
**Status:** pending  
**Dependencies:** None  
**Description:** Create PHP API endpoint to calculate and return Admin dashboard metrics (system-wide validated transactions, pending payments, utang, receivables aging, variance reports)

### Subtasks
- [ ] Create `backend/api/admin_oversight_metrics.php`
- [ ] Implement query for total validated transactions (system-wide)
- [ ] Implement query for system-wide pending payments
- [ ] Implement query for outstanding utang (credit transactions)
- [ ] Implement query for receivables aging (current vs overdue)
- [ ] Implement query for system-wide variance count
- [ ] Return JSON response with metrics
- [ ] Add error handling and logging

---

## Task 12: Update Admin Dashboard Summary Cards
**Status:** pending  
**Dependencies:** Task 11  
**Description:** Add summary cards to admin_dashboard.php displaying system-wide transaction metrics

### Subtasks
- [ ] Add summary cards HTML structure to admin_dashboard.php
- [ ] Add AJAX call to fetch metrics from `admin_oversight_metrics.php`
- [ ] Display "Total Validated Transactions" card
- [ ] Display "Pending Payments" card (₱ format)
- [ ] Display "Outstanding Utang" card (₱ format)
- [ ] Display "Receivables Aging" card (current/overdue breakdown)
- [ ] Display "Variance Reports" card
- [ ] Add icons (fa-check-double, fa-wallet, fa-credit-card, fa-clock, fa-flag)
- [ ] Apply Petron Blue (#002F70) styling

---

## Task 13: Implement Admin Dashboard Charts
**Status:** pending  
**Dependencies:** Task 11  
**Description:** Add Chart.js visualizations for oversight graphs and compliance alerts

### Subtasks
- [ ] Include Chart.js library in admin_dashboard.php
- [ ] Create API endpoint `backend/api/admin_chart_data.php` for chart data
- [ ] Implement oversight graphs query (system-wide sales + receivables trends)
- [ ] Implement compliance alerts query (variance-flagged transactions)
- [ ] Add canvas elements for charts in admin_dashboard.php
- [ ] Initialize line chart for oversight graphs (sales & receivables)
- [ ] Initialize bar chart for compliance alerts
- [ ] Add chart refresh on data update

---

## Task 14: Create Admin Export Endpoints
**Status:** pending  
**Dependencies:** None  
**Description:** Create backend export scripts for validated transactions, receivables aging, variance reports, and compliance reports

### Subtasks
- [ ] Update `backend/export/export_validated_transactions.php` for system-wide scope
- [ ] Create `backend/export/export_receivables_aging.php`
- [ ] Implement Excel/CSV export for receivables aging
- [ ] Update `backend/export/export_variance_reports.php` for system-wide scope
- [ ] Create `backend/export/export_compliance_reports.php`
- [ ] Implement PDF export for compliance reports
- [ ] Add proper headers for file download
- [ ] Remove station_id filter (system-wide scope for Admin)

---

## Task 15: Add Admin Export Buttons
**Status:** pending  
**Dependencies:** Task 14  
**Description:** Add export buttons to admin dashboard with download handlers

### Subtasks
- [ ] Add export buttons section to admin_dashboard.php
- [ ] Add "Export Validated Transactions" button (Excel/CSV)
- [ ] Add "Export Receivables Aging" button (Excel/CSV)
- [ ] Add "Export Variance Reports" button (PDF)
- [ ] Add "Export Compliance Reports" button (PDF)
- [ ] Implement AJAX handlers for export downloads
- [ ] Add loading indicators during export generation
- [ ] Add success/error notifications

---

## Task 16: Implement Role-Based Access Control Verification
**Status:** pending  
**Dependencies:** Tasks 2, 7, 12  
**Description:** Ensure dashboards enforce role-based access and redirect unauthorized users

### Subtasks
- [ ] Verify staff_dashboard.php checks for Staff role
- [ ] Verify manager_dashboard.php checks for Manager role
- [ ] Verify admin_dashboard.php checks for Admin role
- [ ] Add role-based redirects for unauthorized access
- [ ] Test cross-role access attempts
- [ ] Verify API endpoints check user role before returning data

---

## Task 17: Apply Visual Consistency Across All Dashboards
**Status:** pending  
**Dependencies:** Tasks 2, 7, 12  
**Description:** Standardize styling for summary cards, charts, and export buttons across all three dashboards

### Subtasks
- [ ] Create shared CSS file for dashboard components (`assets/css/dashboard_common.css`)
- [ ] Define `.summary-card` class with Petron Blue theme
- [ ] Define `.chart-container` class with consistent dimensions
- [ ] Define `.export-button` class with consistent styling
- [ ] Apply shared styles to staff_dashboard.php
- [ ] Apply shared styles to manager_dashboard.php
- [ ] Apply shared styles to admin_dashboard.php
- [ ] Verify visual consistency across all dashboards

---

## Task 18: Performance Testing and Optimization
**Status:** pending  
**Dependencies:** All previous tasks  
**Description:** Test dashboard load times, query performance, and export generation speed

### Subtasks
- [ ] Test staff dashboard load time (target: <3s)
- [ ] Test manager dashboard load time (target: <3s)
- [ ] Test admin dashboard load time (target: <3s)
- [ ] Test summary card calculation time (target: <2s)
- [ ] Test chart rendering time (target: <5s)
- [ ] Test export generation for <10k records (target: <10s)
- [ ] Add database indexes if queries are slow
- [ ] Implement caching for summary metrics (30s TTL)
- [ ] Optimize SQL queries for performance

---

## Task 19: Error Handling and User Feedback
**Status:** pending  
**Dependencies:** All previous tasks  
**Description:** Implement error handling for database failures, export errors, and display user-friendly messages

### Subtasks
- [ ] Add try-catch blocks to all API endpoints
- [ ] Display "Data unavailable" in summary cards on error
- [ ] Display placeholder message in charts on rendering error
- [ ] Show error notification when export fails
- [ ] Log errors to backend error log (timestamp, user, error type, message)
- [ ] Test error scenarios (DB disconnect, invalid data, export failure)
- [ ] Verify user-friendly error messages display correctly

---

## Task 20: Documentation and Deployment
**Status:** pending  
**Dependencies:** All previous tasks  
**Description:** Document the implementation and prepare for deployment

### Subtasks
- [ ] Update README with dashboard features documentation
- [ ] Document API endpoints and response formats
- [ ] Document export endpoint parameters
- [ ] Create user guide for dashboard features (per role)
- [ ] Test deployment on staging environment
- [ ] Verify all features work after deployment
- [ ] Notify users of new dashboard features
