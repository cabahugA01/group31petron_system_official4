# Requirements Document

## Introduction

The Admin Transactions Oversight Rebuild feature provides system administrators and superadmins with comprehensive oversight capabilities for validated transactions and system-wide variance monitoring across all stations. This feature consists of TWO SEPARATE PAGES:

1. **admin_transactions_oversight.php** - A unified dashboard showing ONLY manager-validated transactions (merchandise, job orders, and fuel) with filtering, search, and export capabilities. This page displays transactions that have already passed through manager validation.

2. **admin_variance_reports.php** - A NEW separate page dedicated to system-wide fuel variance reports, providing enterprise-level monitoring of inventory discrepancies across all stations.

The menu structure includes a new "Variance Reports" menu item under the Transactions Oversight parent menu, linking to the dedicated variance reports page.

The system ensures that admins only view transactions that have already passed through manager validation (Approved, Adjusted, Rejected status), maintaining proper role separation where raw Pending staff encodings remain in the manager validation layer.

## Glossary

- **System**: The Petron Station Management System
- **Transactions_Oversight_Page**: The admin_transactions_oversight.php page showing only validated transactions
- **Variance_Reports_Page**: The NEW admin_variance_reports.php page showing system-wide variance reports
- **Transaction_Record**: A validated business transaction including merchandise, job orders, or fuel sales
- **Variance_Report**: A fuel inventory discrepancy record comparing system calculations to physical measurements
- **Manager_Validated_Transaction**: A transaction with status Approved, Adjusted, Rejected, In Progress, Completed, or Verified
- **Pending_Transaction**: A staff-encoded transaction awaiting manager validation
- **Station**: A physical Petron service station location
- **Database_Query**: A dynamic SQL statement that fetches data without hardcoded values

## Requirements

### Requirement 1: Access Control for Both Pages

**User Story:** As a system administrator, I want access restricted to admin and superadmin roles only for both the Transactions Oversight Page and Variance Reports Page, so that oversight capabilities remain properly secured.

#### Acceptance Criteria

1. WHEN a user accesses THE Transactions_Oversight_Page, THE System SHALL verify the user role is admin or superadmin
2. WHEN a user accesses THE Variance_Reports_Page, THE System SHALL verify the user role is admin or superadmin
3. IF the user role is not admin or superadmin, THEN THE System SHALL redirect to the appropriate dashboard with an access denied message
4. THE System SHALL log all access attempts to both pages with user ID, role, timestamp, and IP address

### Requirement 2: Validated Transactions Dashboard (admin_transactions_oversight.php)

**User Story:** As an admin, I want to view only manager-validated transactions on the Transactions Oversight Page, so that I can oversee completed validation workflows without seeing raw staff encodings.

#### Acceptance Criteria

1. THE Transactions_Oversight_Page SHALL display only Transaction_Records with validation_status in (Approved, Adjusted, Rejected, In Progress, Completed, Verified)
2. THE Transactions_Oversight_Page SHALL exclude all Pending_Transactions from all views
3. WHEN displaying transactions, THE System SHALL fetch data using Database_Queries that filter by validation status dynamically
4. THE Transactions_Oversight_Page SHALL display merchandise transactions, job orders, and fuel transactions in THREE SEPARATE SECTIONS with filtering capability by type
5. FOR ALL transactions displayed, THE System SHALL show transaction ID, customer name, type, items/services, amount, payment method, payment status, validation status, date/time, and staff name

### Requirement 3: Fuel Transactions Section (admin_transactions_oversight.php)

**User Story:** As an admin, I want to view manager-verified fuel transactions on the Transactions Oversight Page, so that I can monitor fuel sales alongside other transactions without interfering with manager validation workflows.

#### Acceptance Criteria

1. THE Transactions_Oversight_Page SHALL include a section displaying only fuel transactions with status not equal to Pending or Pending Validation
2. WHEN displaying fuel transactions, THE System SHALL fetch data using Database_Queries without hardcoded status filters
3. FOR ALL fuel transactions displayed, THE System SHALL show transaction ID, fuel type, liters sold, amount, payment method, payment status, validation status, date/time, and staff name
4. THE System SHALL support filtering fuel transactions by date range, search text, and validation status

### Requirement 4: Variance Reports Page (admin_variance_reports.php)

**User Story:** As an admin, I want a dedicated page for viewing fuel variance reports across all stations, so that I can monitor inventory discrepancies at the enterprise level separately from transaction oversight.

#### Acceptance Criteria

1. THE Variance_Reports_Page SHALL be accessible via a new menu item "Variance Reports" under the Transactions Oversight parent menu
2. THE Variance_Reports_Page SHALL display records from the fuel_variance_reports table
3. WHEN fetching variance reports, THE System SHALL retrieve data across all stations dynamically without hardcoded station filters
4. FOR ALL Variance_Reports displayed, THE System SHALL show report date, station name, fuel type, expected stock, actual stock, variance liters, variance percentage, status, and resolution notes
5. THE System SHALL support filtering variance reports by date range, station, fuel type, and status
6. THE System SHALL calculate aggregate statistics including total variance liters, average variance percentage, and count by status across all stations
7. WHERE the user is an admin (not superadmin), THE System SHALL optionally support filtering to show only stations within the admin's region or assigned stations

### Requirement 5: Dynamic Data Fetching

**User Story:** As a system maintainer, I want all data fetching to use dynamic queries with parameter binding, so that the system remains secure, maintainable, and free from hardcoded data.

#### Acceptance Criteria

1. THE System SHALL use prepared statements with parameter binding for all database queries
2. THE System SHALL NOT contain hardcoded transaction IDs, customer names, amounts, or dates in query logic
3. THE System SHALL detect available database columns dynamically using SHOW COLUMNS queries
4. WHEN a required column does not exist, THE System SHALL use fallback logic or safe defaults without failing
5. THE System SHALL construct filter WHERE clauses dynamically based on user input parameters

### Requirement 6: Transaction Filtering and Search (admin_transactions_oversight.php)

**User Story:** As an admin, I want to filter and search transactions by multiple criteria on the Transactions Oversight Page, so that I can quickly find specific records for oversight review.

#### Acceptance Criteria

1. THE Transactions_Oversight_Page SHALL provide date range filtering with start date and end date inputs
2. THE Transactions_Oversight_Page SHALL provide search capability matching transaction ID, customer name, or vehicle plate
3. THE Transactions_Oversight_Page SHALL provide validation status filtering with options corresponding to manager-validated states
4. THE Transactions_Oversight_Page SHALL provide transaction type filtering (All Types, Merchandise, Job Order, Fuel)
5. WHEN applying filters, THE System SHALL preserve filter state across page navigation and pagination

### Requirement 7: Variance Report Filtering and Search (admin_variance_reports.php)

**User Story:** As an admin, I want to filter and search variance reports by multiple criteria on the Variance Reports Page, so that I can quickly identify specific discrepancies or patterns.

#### Acceptance Criteria

1. THE Variance_Reports_Page SHALL provide date range filtering with start date and end date inputs
2. THE Variance_Reports_Page SHALL provide station filtering with dropdown showing all stations
3. THE Variance_Reports_Page SHALL provide fuel type filtering with options for all available fuel types
4. THE Variance_Reports_Page SHALL provide status filtering with options (All, Open, Under Investigation, Resolved)
5. THE Variance_Reports_Page SHALL provide search capability matching report ID or notes
6. WHEN applying filters, THE System SHALL preserve filter state across page navigation and pagination

### Requirement 8: Variance Report Visualization (admin_variance_reports.php)

**User Story:** As an admin, I want to see variance report trends and statistics on the Variance Reports Page, so that I can identify patterns and systemic issues across stations.

#### Acceptance Criteria

1. THE Variance_Reports_Page SHALL display summary statistics including total variance liters, average variance percentage, and open vs resolved counts
2. THE Variance_Reports_Page SHALL provide a tabular view of variance reports sorted by report date descending by default
3. THE Variance_Reports_Page SHALL highlight variance reports exceeding threshold values (e.g., variance percentage > 5%) with visual indicators
4. THE Variance_Reports_Page SHALL support sorting variance reports by report date, variance liters, variance percentage, or status
5. WHEN a Variance_Report status is Open, THE System SHALL display it with distinct visual styling

### Requirement 9: Data Export Capabilities

**User Story:** As an admin, I want to export transaction and variance data to Excel from both pages, so that I can perform offline analysis and generate executive reports.

#### Acceptance Criteria

1. THE Transactions_Oversight_Page SHALL provide an Export Excel button
2. THE Variance_Reports_Page SHALL provide an Export Excel button
3. WHEN exporting to Excel, THE System SHALL include all visible columns and respect current filter selections
4. THE System SHALL format exported files with proper column headers, cell formatting, and filename including export date and page name
5. THE System SHALL generate Excel files using content type application/vnd.ms-excel and proper headers

### Requirement 10: Print-Friendly Views

**User Story:** As an admin, I want to print transaction oversight and variance reports from both pages, so that I can provide physical documentation for audits and management review.

#### Acceptance Criteria

1. THE Transactions_Oversight_Page SHALL provide a Print button
2. THE Variance_Reports_Page SHALL provide a Print button
3. WHEN printing from either page, THE System SHALL hide non-essential UI elements including sidebar, filters, and action buttons
4. THE System SHALL optimize table layouts for standard paper sizes
5. THE System SHALL include print metadata such as date range, filters applied, and report generation timestamp in the printout
6. THE System SHALL preserve data, validation status badges, and summary statistics in printed output

### Requirement 11: Pagination and Performance

**User Story:** As an admin viewing large datasets on both pages, I want efficient pagination and data loading, so that the interfaces remain responsive with thousands of records.

#### Acceptance Criteria

1. THE Transactions_Oversight_Page SHALL implement client-side pagination with configurable rows per page (10, 25, 50, 100)
2. THE Variance_Reports_Page SHALL implement client-side pagination with configurable rows per page (10, 25, 50, 100)
3. THE System SHALL limit initial database queries to 500 records per page with filters applied
4. THE System SHALL display current page number, total pages, and record range (e.g., "1-25 of 100")
5. THE System SHALL provide Previous Page and Next Page navigation controls
6. WHEN changing rows per page, THE System SHALL reset to page 1 and recalculate pagination

### Requirement 12: Validation Status Guards (admin_transactions_oversight.php)

**User Story:** As a system architect, I want explicit guards preventing admin access to pending transactions on the Transactions Oversight Page, so that role separation between manager validation and admin oversight is enforced at the code level.

#### Acceptance Criteria

1. WHEN fetching transactions for admin view on THE Transactions_Oversight_Page, THE System SHALL include WHERE clause filtering out validation_status = 'Pending'
2. WHEN fetching fuel transactions for admin view on THE Transactions_Oversight_Page, THE System SHALL include WHERE clause filtering out status IN ('Pending', 'Pending Validation')
3. IF an admin attempts to approve/reject a Pending transaction via direct POST, THE System SHALL verify the current status and reject the action with an error message
4. THE System SHALL log any attempts to bypass validation status guards with user ID, transaction ID, and attempted action

### Requirement 13: Audit Trail Integration

**User Story:** As a compliance officer, I want all admin actions logged to the audit trail from both pages, so that oversight activities are traceable for regulatory review.

#### Acceptance Criteria

1. WHEN an admin approves a transaction on THE Transactions_Oversight_Page, THE System SHALL insert a record into the audit_trail table with action type Approve
2. WHEN an admin rejects a transaction on THE Transactions_Oversight_Page, THE System SHALL insert a record into the audit_trail table with action type Reject and include rejection reason
3. WHEN an admin adjusts a transaction on THE Transactions_Oversight_Page, THE System SHALL insert a record into the audit_trail table with action type Adjust and include new total amount
4. WHEN an admin views or exports variance reports on THE Variance_Reports_Page, THE System SHALL log the action to the audit trail
5. FOR ALL audit trail entries, THE System SHALL record transaction ID or report ID, admin user ID, action timestamp, and station ID
6. THE System SHALL write audit records using safe database insert operations that handle missing columns gracefully

### Requirement 14: Variance Report Details Navigation (admin_variance_reports.php)

**User Story:** As an admin, I want to click on a variance report to view detailed information on the Variance Reports Page, so that I can investigate discrepancies thoroughly.

#### Acceptance Criteria

1. WHEN a Variance_Report row is clicked on THE Variance_Reports_Page, THE System SHALL navigate to a details view showing full report information
2. THE System SHALL display variance report details including report date, station, fuel type, expected vs actual stock, variance calculations, investigation notes, resolution notes, and status history
3. WHERE investigation notes exist, THE System SHALL display the investigator name and investigation timestamp
4. WHERE resolution notes exist, THE System SHALL display the resolver name and resolution timestamp
5. THE System SHALL provide a back navigation link returning to the variance reports list with filters preserved

### Requirement 15: Multi-Station Variance Aggregation (admin_variance_reports.php)

**User Story:** As a regional admin, I want to see variance reports aggregated across multiple stations on the Variance Reports Page, so that I can compare performance and identify outlier locations.

#### Acceptance Criteria

1. THE Variance_Reports_Page SHALL support grouping variance reports by station
2. THE Variance_Reports_Page SHALL calculate aggregate statistics per station including total variance liters, average variance percentage, and open report count
3. THE Variance_Reports_Page SHALL display station-level aggregates in a summary table with drill-down capability to individual reports
4. WHEN viewing aggregated data, THE System SHALL sort stations by total variance liters descending by default
5. THE Variance_Reports_Page SHALL provide a toggle to switch between aggregated station view and flat list view

### Requirement 16: Real-Time Status Updates

**User Story:** As an admin monitoring data on both pages, I want to see real-time status updates without manual page refresh, so that I can react quickly to newly validated transactions and variance reports.

#### Acceptance Criteria

1. THE Transactions_Oversight_Page SHALL implement auto-refresh with configurable interval (default 30 seconds)
2. THE Variance_Reports_Page SHALL implement auto-refresh with configurable interval (default 60 seconds)
3. WHEN auto-refresh is triggered, THE System SHALL fetch updated data via AJAX without full page reload
4. THE System SHALL preserve current filters, pagination state, and scroll position during auto-refresh
5. THE System SHALL provide a manual refresh button and a toggle to enable/disable auto-refresh on both pages

### Requirement 17: Error Handling and User Feedback

**User Story:** As an admin user on both pages, I want clear error messages and success confirmations, so that I understand the results of my actions and can troubleshoot issues.

#### Acceptance Criteria

1. WHEN a database query fails on either page, THE System SHALL display a user-friendly error message without exposing SQL details
2. WHEN a transaction action succeeds on THE Transactions_Oversight_Page, THE System SHALL display a success notification with transaction ID and action taken
3. WHEN filter parameters are invalid on either page, THE System SHALL display validation errors and retain valid filter values
4. THE System SHALL use session-based flash messages for success and error notifications that clear after display
5. WHEN no records match filter criteria on either page, THE System SHALL display an empty state message with filter reset suggestion

### Requirement 18: Menu Structure and Navigation

**User Story:** As an admin, I want clear menu navigation to access both the Transactions Oversight Page and the Variance Reports Page, so that I can easily switch between oversight views.

#### Acceptance Criteria

1. THE System SHALL display a parent menu item "Transactions" in the admin navigation
2. UNDER the Transactions parent menu, THE System SHALL display TWO submenu items: "Oversight Dashboard" and "Variance Reports (system-wide)"
3. WHEN "Oversight Dashboard" is clicked, THE System SHALL navigate to admin_transactions_oversight.php showing all validated transactions
4. WHEN "Variance Reports (system-wide)" is clicked, THE System SHALL navigate to admin_variance_reports.php
5. THE System SHALL highlight the active menu item based on the current page

## Parser and Serializer Requirements

This feature does not require custom parsing or serialization beyond standard PHP/MySQL operations. All data exchange uses native PDO parameter binding and JSON encoding for AJAX responses, which are handled by PHP built-in functions.

## Notes for Design Phase

- The existing admin_transactions_oversight.php file already implements proper validation status guards and dynamic column detection for VALIDATED transactions only
- admin_transactions_oversight.php should remain focused on displaying validated merchandise, job orders, and fuel transactions - NO tabs needed, just unified filtering by type
- The variance reports functionality exists in the database (fuel_variance_reports table) but requires a NEW separate page admin_variance_reports.php
- The NEW admin_variance_reports.php page will handle all variance report display, filtering, statistics, and export functionality
- The menu structure should add "Variance Reports" as a new menu item under Transactions Oversight, linking to the new page
- Consider whether admins should only view variance reports or also act on them (mark as resolved, add investigation notes) - this should be clarified in the design phase
- Both pages should share common UI patterns (filters, pagination, export) but maintain separate codebases
