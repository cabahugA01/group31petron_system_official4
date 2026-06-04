# Implementation Plan: Admin Transactions Oversight Rebuild

## Overview

This implementation creates a two-page system for admin oversight:

1. **admin_transactions_oversight.php (MODIFY)** - Remove tab navigation, add type filtering dropdown, display only validated transactions
2. **admin_variance_reports.php (CREATE NEW)** - Build from scratch for fuel variance monitoring with filtering, statistics, and export

The implementation maintains existing code patterns for dynamic column detection, prepared statements, and validation status guards. The menu structure adds a new "Variance Reports" menu item under Transactions Oversight parent menu.

## Tasks

- [ ] 1. Modify admin_transactions_oversight.php - Remove tab navigation
  - [-] 1.1 Remove existing tab detection and navigation logic
    - Remove $active_tab variable and tab validation code
    - Remove tab-based conditional rendering blocks
    - Remove tab navigation HTML from the page header
    - _Requirements: 2.1, 2.2, 2.3_
  
  - [-] 1.2 Add transaction type filtering dropdown
    - Add $type variable: $_GET['type'] ?? 'all'
    - Validate against allowed_types: ['all', 'merchandise', 'joborder', 'fuel']
    - Default to 'all' if invalid type provided
    - _Requirements: 2.4, 6.4_
  
  - [-] 1.3 Update transaction query to support type filtering
    - Modify main transactions query to accept type parameter
    - Implement UNION query for type='all' combining all transaction types
    - Implement single-table queries for specific types (merchandise, joborder, fuel)
    - Ensure validation_status guards remain intact (exclude Pending)
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 12.1, 12.2_

- [ ] 2. Update admin_transactions_oversight.php filter UI
  - [-] 2.1 Add type filter dropdown to filter bar
    - Create dropdown with options: All Types, Merchandise, Job Order, Fuel Transactions
    - Add onchange="this.form.submit()" for auto-submit
    - Set selected attribute based on $type variable
    - Position as first filter before date range
    - _Requirements: 2.4, 6.4_
  
  - [~] 2.2 Update filter form action and preserve parameters
    - Ensure form submits to admin_transactions_oversight.php
    - Preserve type, start, end, search, status parameters across submissions
    - Update Reset button link to admin_transactions_oversight.php without parameters
    - _Requirements: 6.5_

- [ ] 3. Update admin_transactions_oversight.php table display
  - [~] 3.1 Add Type Badge column to unified transactions table
    - Insert Type column after Transaction ID column
    - Display badge with transaction type: Merchandise | Job Order | Fuel
    - Apply CSS classes: ato-type-badge, ato-type-merchandise, ato-type-joborder, ato-type-fuel
    - _Requirements: 2.4, 2.5_
  
  - [~] 3.2 Update table columns for unified view
    - Ensure columns display: ID, Type, Customer, Items/Services, Amount, Payment Method, Payment Status, Validation Status, Date/Time, Staff, Actions
    - Standardize data formatting across all transaction types
    - _Requirements: 2.5, 3.5_

- [ ] 4. Update admin_transactions_oversight.php export functionality
  - [~] 4.1 Modify Excel export to include Type column
    - Add Type column header in Excel export
    - Include type badge data in export rows
    - Update filename to reflect filtered type if applicable
    - _Requirements: 9.1, 9.2, 9.3, 9.4_

- [~] 5. Checkpoint - Verify modified admin_transactions_oversight.php
  - Ensure all tests pass, ask the user if questions arise.
  - Manually verify: type filtering, data display, validation guards, export
  - Check that no tab references remain in code or UI
  - Verify filter state preservation

- [ ] 6. Create new admin_variance_reports.php file
  - [x] 6.1 Set up file structure and access control
    - Create new PHP file: public/admin_variance_reports.php
    - Add session_start() at top
    - Include database connection file
    - Implement role check (admin/superadmin only)
    - Add redirect logic for unauthorized access
    - _Requirements: 1.1, 1.2, 1.3, 1.4_
  
  - [ ] 6.2 Initialize filter variables for variance reports
    - Add $start: $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'))
    - Add $end: $_GET['end'] ?? date('Y-m-d')
    - Add $station_filter: (int)($_GET['station'] ?? 0)
    - Add $fuel_type_filter: trim($_GET['fuel_type'] ?? '')
    - Add $var_status_filter: trim($_GET['status'] ?? '')
    - Add $search: trim($_GET['search'] ?? '')
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

- [ ] 7. Implement variance reports data fetching
  - [~] 7.1 Build base variance reports SQL query
    - Create SELECT query joining fuel_variance_reports and stations tables
    - Include columns: report_id, report_date, fuel_type, expected_stock, actual_stock, variance_liters, variance_percent, status, reason, station_name
    - Add LEFT JOIN stations ON fvr.station_id = s.id
    - Add WHERE clause for date range
    - _Requirements: 4.2, 4.3, 5.1, 5.2_
  
  - [~] 7.2 Add dynamic WHERE clause construction for filters
    - Add conditional station_id filter when station_filter > 0
    - Add conditional fuel_type LIKE filter when fuel_type_filter not empty
    - Add conditional status = filter when var_status_filter not empty
    - Add conditional search filter (station name OR fuel_type)
    - Use parameter binding for all filter values
    - _Requirements: 4.4, 5.1, 5.5, 7.1, 7.2, 7.3, 7.4, 7.5_
  
  - [~] 7.3 Execute variance reports query with error handling
    - Prepare statement with dynamic query string
    - Execute with parameter array
    - Fetch all results as associative array
    - Add try-catch block with error logging
    - Set empty array fallback on query failure
    - _Requirements: 5.1, 17.1, 17.3_

- [ ] 8. Implement variance summary statistics
  - [~] 8.1 Create summary statistics SQL query
    - Add SUM(ABS(variance_liters)) for total variance
    - Add AVG(ABS(variance_percent)) for average variance percentage
    - Add COUNT for status='Open' as open_count
    - Add COUNT for status='Under Investigation' as investigating_count
    - Add COUNT for status='Resolved' as resolved_count
    - Add COUNT(*) for total_count
    - Apply same WHERE clause as main query
    - _Requirements: 4.6, 8.1, 8.2_
  
  - [~] 8.2 Execute summary query and format results
    - Prepare and execute summary statistics query
    - Extract all count and aggregate values
    - Add error handling with fallback to zero values
    - Format numbers for display (2 decimal places)
    - _Requirements: 4.6, 8.1, 17.1_

- [ ] 9. Fetch dropdown data for variance filters
  - [~] 9.1 Fetch station list for filter dropdown
    - Query stations table for active stations
    - ORDER BY name ascending
    - Store results in $stations array
    - _Requirements: 4.4, 7.2_
  
  - [~] 9.2 Fetch fuel type list for filter dropdown
    - Query DISTINCT fuel_type from fuel_variance_reports
    - Filter out NULL and empty values
    - ORDER BY fuel_type ascending
    - Store results in $fuel_types array
    - _Requirements: 4.4, 7.3_

- [ ] 10. Build variance reports HTML page structure
  - [~] 10.1 Create page header with navigation
    - Include common admin header file or navigation
    - Add page title: "System-Wide Variance Reports"
    - Add page subtitle explaining the purpose
    - Include Font Awesome icon: fas fa-chart-line
    - _Requirements: 4.1_
  
  - [~] 10.2 Build variance filter bar HTML
    - Create form with method="GET" action="admin_variance_reports.php"
    - Add date range inputs (start, end) with values from filter variables
    - Add station dropdown populated from $stations array
    - Add fuel type dropdown populated from $fuel_types array
    - Add status dropdown with options: "", Open, Under Investigation, Resolved
    - Add search text input for station/fuel type search
    - Add "Apply Filters" and "Reset" buttons
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

- [ ] 11. Build variance summary statistics bar
  - [~] 11.1 Create summary statistics HTML component
    - Add div.ato-summary-bar above variance table
    - Display "Total Variance" with $stats['total_variance_liters'] formatted
    - Display "Avg Variance %" with $stats['avg_variance_percent'] formatted
    - Display "Open" count with ato-card-open styling
    - Display "Investigating" count with ato-card-investigating styling
    - Display "Resolved" count with ato-card-resolved styling
    - Display "Total Reports" count
    - Add pipe separators between metrics
    - _Requirements: 4.6, 8.1, 8.2_

- [ ] 12. Build variance reports table
  - [~] 12.1 Create variance reports table HTML structure
    - Create table with class ato-table ato-variance-table
    - Add thead with columns: Report Date, Station, Fuel Type, Expected (L), Actual (L), Variance (L), Variance %, Status, Reason, Actions
    - Add tbody for dynamic row rendering
    - _Requirements: 4.4, 8.2_
  
  - [~] 12.2 Implement variance report row rendering logic
    - Loop through $variance_reports array
    - Output each row with htmlspecialchars on text fields
    - Format dates: date('Y-m-d', strtotime($vr['report_date']))
    - Format numbers: number_format for stock and variance values
    - _Requirements: 4.4, 8.2_
  
  - [~] 12.3 Implement variance percentage badges with severity styling
    - Calculate variance severity: critical (>5%), warning (2-5%), minor (<2%)
    - Apply CSS classes: ato-variance-critical, ato-variance-warning, ato-variance-minor
    - Display variance percentage inside styled badge
    - _Requirements: 8.3_
  
  - [~] 12.4 Implement status badges for variance reports
    - Create status badge with conditional CSS classes
    - Apply ato-status-open (red) for "Open"
    - Apply ato-status-investigating (orange) for "Under Investigation"
    - Apply ato-status-resolved (green) for "Resolved"
    - Display status text inside badge
    - _Requirements: 4.4, 8.3_
  
  - [~] 12.5 Add empty state for variance reports
    - Add conditional block when empty($variance_reports)
    - Display centered message with inbox icon
    - Add "No variance reports found for the selected filters" text
    - Add Reset Filters link to admin_variance_reports.php
    - _Requirements: 17.5_

- [ ] 13. Implement variance reports Excel export
  - [~] 13.1 Add export detection and header logic
    - Add $is_export: ($_GET['export'] ?? '') === 'excel'
    - Add conditional block at top of file for export handling
    - Set headers: Content-Type, Content-Disposition with filename variance_reports_{date}.xls, Pragma
    - _Requirements: 9.1, 9.2, 9.3_
  
  - [~] 13.2 Build Excel table with variance data
    - Create HTML table with border="1"
    - Add thead with all variance column headers
    - Loop through $variance_reports and output rows
    - Apply htmlspecialchars and number formatting
    - Include summary row with aggregate statistics
    - Call exit() after table output
    - _Requirements: 9.3, 9.4_

- [ ] 14. Add variance-specific CSS styling
  - [~] 14.1 Create CSS file or add inline styles for variance badges
    - Define .ato-variance-critical (red #dc3545, white text)
    - Define .ato-variance-warning (orange #fd7e14, white text)
    - Define .ato-variance-minor (yellow #ffc107, dark text)
    - Define .ato-status-open (red background)
    - Define .ato-status-investigating (orange background)
    - Define .ato-status-resolved (green background)
    - Add padding, border-radius, font-weight for all badges
    - _Requirements: 8.3, 8.4_

- [ ] 15. Add pagination to variance reports page
  - [~] 15.1 Implement client-side pagination for variance table
    - Add pagination controls below variance table
    - Support rows per page: 10, 25, 50, 100
    - Add Previous/Next navigation buttons
    - Display page info: "Showing X to Y of Z records"
    - Reuse existing pagination JavaScript pattern from admin_transactions_oversight.php
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6_

- [ ] 16. Add print functionality to variance reports page
  - [~] 16.1 Add Print button to actions bar
    - Add button with onclick="window.print()"
    - Include print icon
    - _Requirements: 10.1, 10.2_
  
  - [~] 16.2 Add print-specific CSS styles
    - Hide sidebar, filters, and action buttons in print view
    - Optimize table layout for standard paper size
    - Include print metadata: date range, filters applied, timestamp
    - _Requirements: 10.3, 10.4, 10.5_

- [ ] 17. Update menu structure to include both admin transaction pages
  - [~] 17.1 Rename parent menu and update submenu items
    - Locate admin navigation menu file (e.g., admin_sidebar.php or admin_menu.php)
    - Rename "Transactions Oversight" parent menu to "Transactions"
    - Update icon to fas fa-exchange-alt
    - Replace all submenu items with TWO items only:
      1. "Oversight Dashboard" → admin_transactions_oversight.php (icon: fas fa-eye)
      2. "Variance Reports (system-wide)" → admin_variance_reports.php (icon: fas fa-chart-line)
    - _Requirements: 4.1, 18.1, 18.2, 18.3, 18.4_
  
  - [~] 17.2 Update menu highlighting logic
    - Ensure active menu item is highlighted when on admin_variance_reports.php
    - Update parent menu expand logic if needed
    - _Requirements: 18.5_

- [~] 18. Checkpoint - Test variance reports page functionality
  - Ensure all tests pass, ask the user if questions arise.
  - Manually verify: page access, filters, data display, statistics, export
  - Check for console errors and SQL query failures
  - Verify responsive layout on mobile/tablet
  - Test menu navigation between Overview and Variance Reports

- [ ] 19. Add audit trail logging for both pages
  - [~] 19.1 Add audit logging to admin_transactions_oversight.php actions
    - Log Approve, Reject, Adjust actions to audit_trail table
    - Include transaction_id, user_id, action_type, timestamp, station_id
    - Use safe insert with column detection
    - _Requirements: 13.1, 13.2, 13.3, 13.5, 13.6_
  
  - [~] 19.2 Add audit logging to admin_variance_reports.php access
    - Log view and export actions to audit_trail table
    - Include report_id, user_id, action_type, timestamp
    - _Requirements: 13.4, 13.5, 13.6_

- [ ] 20. Add database indexing recommendations (documentation only)
  - [~] 20.1 Document recommended indexes for fuel_variance_reports
    - Create SQL comment block with CREATE INDEX statements
    - Recommend idx_report_date on (report_date)
    - Recommend idx_station_date on (station_id, report_date)
    - Recommend idx_status on (status)
    - Recommend idx_fuel_type on (fuel_type)
    - Include performance notes explaining benefits
    - _Requirements: 11.3_

- [ ] 21. Final verification and security testing
  - [~] 21.1 Verify all requirements are implemented
    - Check Requirements 1.1-1.4 (Access Control)
    - Check Requirements 2.1-2.5 (Transactions Dashboard)
    - Check Requirements 3.1-3.5 (Fuel Transactions)
    - Check Requirements 4.1-4.7 (Variance Reports Page)
    - Check Requirements 5.1-5.5 (Dynamic Data Fetching)
    - Check Requirements 6.1-6.5 & 7.1-7.5 (Filtering)
    - Check Requirements 8.1-8.5 (Variance Visualization)
    - Check Requirements 9.1-9.5 (Export)
    - Check Requirements 10.1-10.6 (Print)
    - Check Requirements 11.1-11.6 (Pagination)
    - Check Requirements 12.1-12.4 (Validation Guards)
    - Check Requirements 13.1-13.6 (Audit Trail)
    - _Requirements: All_
  
  - [~] 21.2 Test cross-browser compatibility
    - Test both pages in Chrome, Firefox, Edge
    - Verify filter interactions and table display
    - Check horizontal scrolling behavior
    - Verify print layouts
    - _Requirements: 10.1-10.6_
  
  - [~] 21.3 Verify security measures
    - Confirm all queries use prepared statements with parameter binding
    - Verify htmlspecialchars applied to all output
    - Check SQL injection prevention in all inputs
    - Verify role-based access control on both pages
    - Test with malicious input patterns
    - _Requirements: 1.1, 1.2, 1.3, 5.1, 12.1, 12.2, 12.3, 12.4_

- [~] 22. Final checkpoint - Complete feature verification
  - Ensure all tests pass, ask the user if questions arise.
  - Review all code changes for quality and maintainability
  - Confirm no hardcoded data in queries
  - Verify graceful handling of missing database columns
  - Test with empty fuel_variance_reports table
  - Test with 100+ variance reports for performance
  - Verify both pages work independently and menu navigation is seamless

## Notes

- **Two-page architecture**: One file modified (admin_transactions_oversight.php), one file created (admin_variance_reports.php)
- **No tabs**: admin_transactions_oversight.php uses type filtering dropdown instead of tab navigation
- **Dedicated variance page**: admin_variance_reports.php is completely new and separate
- **Menu update**: Add "Variance Reports" submenu item under Transactions Oversight parent menu
- The implementation maintains existing code patterns (ato_cols, ato_has, dynamic queries, prepared statements)
- Testing tasks are included but not marked optional (critical for data integrity)
- The implementation preserves backward compatibility with existing transaction oversight functionality
- Dynamic column detection ensures features work even if optional database columns are missing
- All SQL queries use prepared statements with parameter binding to prevent SQL injection
- XSS prevention is ensured through htmlspecialchars on all user-facing output
- Both pages follow consistent visual design patterns for unified admin experience
- Pagination is handled by existing client-side JavaScript (can be reused)
- Export functionality follows existing Excel export pattern with page-specific headers

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "6.1"] },
    { "id": 1, "tasks": ["1.3", "2.1", "6.2"] },
    { "id": 2, "tasks": ["2.2", "7.1", "9.1"] },
    { "id": 3, "tasks": ["3.1", "3.2", "7.2", "9.2"] },
    { "id": 4, "tasks": ["4.1", "7.3", "8.1"] },
    { "id": 5, "tasks": ["8.2", "10.1", "10.2"] },
    { "id": 6, "tasks": ["11.1", "12.1", "14.1"] },
    { "id": 7, "tasks": ["12.2", "12.3", "12.4", "12.5", "15.1", "16.1"] },
    { "id": 8, "tasks": ["13.1", "13.2", "16.2"] },
    { "id": 9, "tasks": ["17.1", "17.2", "19.1", "19.2"] },
    { "id": 10, "tasks": ["20.1"] },
    { "id": 11, "tasks": ["21.1", "21.2", "21.3"] }
  ]
}
```
