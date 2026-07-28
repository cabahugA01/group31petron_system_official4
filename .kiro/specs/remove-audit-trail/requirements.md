# Requirements Document

## Introduction

This specification defines the requirements for removing the Audit Trail Report **and** the Calendar & Schedule Report from the Compliance Reports page of the Petron Management System. After this change, the Compliance Reports page (`admin_compliance_reports.php` and `manager_compliance_reports.php`) will display **only the Activity Logs** report.

The Audit Trail report is redundant because Activity Logs already covers the same information with identical data sources. The Calendar & Schedule report is being removed from the Compliance Reports page because calendar functionality is already available as a dedicated standalone page (accessible from the main sidebar navigation), making its presence in Compliance Reports redundant and out of scope for a compliance-oriented report page.

This change does **not** affect:
- The Activity Logs report, which remains the sole report on the Compliance Reports page
- The Superadmin Audit Trail page (`superadmin_audit_trail.php`), which serves a different developer/superadmin purpose
- The standalone Calendar pages (`admin_calendar.php`, `manager_calendar.php`, `staff_calendar.php`), which remain fully accessible from the sidebar

## Glossary

- **System**: The Petron Management System web application
- **Audit_Trail_Report**: The redundant report section currently shown as a tab in the Compliance Reports page
- **Calendar_Schedule_Report**: The Calendar & Schedule report section currently shown as a tab in the Compliance Reports page
- **Activity_Logs_Report**: The existing report section accessible via Compliance Reports → Activity Logs tab (to be retained as the only report)
- **Compliance_Reports_Page**: The admin and manager compliance reports interface (`admin_compliance_reports.php` and `manager_compliance_reports.php`)
- **API_Endpoint**: The backend audit API endpoint at `backend/api/admin_reports_audit_api.php`
- **Navigation_Menu**: The sidebar menu system defined in `partials/rbac_menu.php` and `partials/header.php`
- **Superadmin_Audit_Trail**: The separate superadmin audit trail page (`superadmin_audit_trail.php`) which must be preserved as it serves a different purpose
- **Standalone_Calendar**: The dedicated calendar pages (`admin_calendar.php`, `manager_calendar.php`, `staff_calendar.php`) accessible from the sidebar navigation, which must be preserved

## Requirements

### Requirement 1: Remove Audit Trail UI Tab from Admin Compliance Reports

**User Story:** As an administrator, I no longer want to see the Audit Trail tab in the Compliance Reports page, so that I can focus on the Activity Logs which provides the same information.

#### Acceptance Criteria

1. WHEN an administrator navigates to `admin_compliance_reports.php`, THE System SHALL NOT display the "Audit Trail" tab button
2. WHEN an administrator attempts to access `admin_compliance_reports.php?section=audit` via direct URL, THE System SHALL redirect to the Activity Logs section (`section=activity`)
3. THE System SHALL remove all HTML code related to the Audit Trail tab button in `admin_compliance_reports.php`
4. THE System SHALL remove all HTML code related to the Audit Trail panel content (`cr-panel-audit`) in `admin_compliance_reports.php`

### Requirement 2: Remove Audit Trail UI Tab from Manager Compliance Reports

**User Story:** As a manager, I no longer want to see the Audit Trail tab in the Compliance Reports page, so that I can focus on the Activity Logs which provides the same information.

#### Acceptance Criteria

1. WHEN a manager navigates to `manager_compliance_reports.php`, THE System SHALL NOT display the "Audit Trail" tab button
2. WHEN a manager attempts to access `manager_compliance_reports.php?section=audit_trail` via direct URL, THE System SHALL redirect to the Activity Logs section (`section=activity_logs`)
3. THE System SHALL remove all HTML code related to the Audit Trail tab button in `manager_compliance_reports.php`
4. THE System SHALL remove all HTML code related to the Audit Trail panel content (`cr-panel-audit_trail`) in `manager_compliance_reports.php`

### Requirement 3: Remove Calendar & Schedule UI Tab from Admin Compliance Reports

**User Story:** As an administrator, I no longer want to see the Calendar & Schedule tab in the Compliance Reports page, so that the Compliance Reports page shows only Activity Logs.

#### Acceptance Criteria

1. WHEN an administrator navigates to `admin_compliance_reports.php`, THE System SHALL NOT display the "Calendar & Schedule" tab button
2. WHEN an administrator attempts to access `admin_compliance_reports.php?section=calendar` via direct URL, THE System SHALL redirect to the Activity Logs section (`section=activity`)
3. THE System SHALL remove all HTML code related to the Calendar & Schedule tab button in `admin_compliance_reports.php`
4. THE System SHALL remove all HTML code related to the Calendar & Schedule panel content (`cr-panel-calendar`) in `admin_compliance_reports.php`

### Requirement 4: Remove Calendar & Schedule UI Tab from Manager Compliance Reports

**User Story:** As a manager, I no longer want to see the Calendar & Schedule tab in the Compliance Reports page, so that the Compliance Reports page shows only Activity Logs.

#### Acceptance Criteria

1. WHEN a manager navigates to `manager_compliance_reports.php`, THE System SHALL NOT display the "Calendar & Schedule" tab button
2. WHEN a manager attempts to access `manager_compliance_reports.php?section=calendar` via direct URL, THE System SHALL redirect to the Activity Logs section (`section=activity_logs`)
3. THE System SHALL remove all HTML code related to the Calendar & Schedule tab button in `manager_compliance_reports.php`
4. THE System SHALL remove all HTML code related to the Calendar & Schedule panel content (`cr-panel-calendar`) in `manager_compliance_reports.php`

### Requirement 5: Remove Audit Trail Backend API Actions

**User Story:** As a system maintainer, I want to remove the unused Audit Trail API actions, so that the codebase remains clean and maintainable.

#### Acceptance Criteria

1. THE System SHALL remove the `audit_trail` action case from `backend/api/admin_reports_audit_api.php`
2. THE System SHALL remove the `audit_summary` action case from `backend/api/admin_reports_audit_api.php`
3. THE System SHALL remove the `anomaly_detection` action case from `backend/api/admin_reports_audit_api.php`
4. THE System SHALL preserve all other API action cases in `admin_reports_audit_api.php` that are used by other reports
5. THE System SHALL continue to support Activity Logs data fetching through existing API endpoints

### Requirement 6: Remove Calendar & Schedule Data Fetching from Compliance Reports

**User Story:** As a system maintainer, I want to remove the server-side data fetching logic for Calendar & Schedule from the Compliance Reports pages, so that the system does not perform unnecessary database queries.

#### Acceptance Criteria

1. THE System SHALL remove the `$calendar_tasks` data fetching logic (Job Orders + Fuel Deliveries queries) from `admin_compliance_reports.php`
2. THE System SHALL remove the `$calendar_tasks` data fetching logic from `manager_compliance_reports.php`
3. THE System SHALL remove the `$calendar_by_date` grouping array and associated calendar grid rendering logic from `admin_compliance_reports.php`
4. THE System SHALL remove the `crAdminTrackerServiceWhere()` helper function from `admin_compliance_reports.php` if it is only used for calendar data fetching
5. THE System SHALL remove the `crManagerTrackerServiceWhere()` helper function from `manager_compliance_reports.php` if it is only used for calendar data fetching
6. THE System SHALL preserve the `$activity_rows` data fetching logic without modification
7. THE System SHALL not impact any database tables or schema

### Requirement 7: Remove Audit Trail Data Fetching from Compliance Reports

**User Story:** As a system maintainer, I want to remove the server-side data fetching logic for the Audit Trail report, so that the system does not perform unnecessary database queries.

#### Acceptance Criteria

1. THE System SHALL remove the `$audit_rows` data fetching block (from `audit_logs` and `audit_trail` sources) in `admin_compliance_reports.php`
2. THE System SHALL remove the `$audit_rows` data fetching block in `manager_compliance_reports.php`
3. THE System SHALL preserve the `$activity_rows` data fetching logic without modification
4. THE System SHALL not impact any database tables or schema

### Requirement 8: Remove Export Functionality for Audit Trail and Calendar & Schedule

**User Story:** As an administrator or manager, I no longer want export options for Audit Trail or Calendar & Schedule, so that export operations apply only to Activity Logs.

#### Acceptance Criteria

1. THE System SHALL remove the Audit Trail sheet mapping (`audit: 'Audit Trail'`) from the `sheetNames` object in the `crExport()` JavaScript function in `admin_compliance_reports.php`
2. THE System SHALL remove the Calendar sheet mapping (`calendar: 'Task List'`) from the `sheetNames` object in the `crExport()` JavaScript function in `admin_compliance_reports.php`
3. THE System SHALL remove any export logic in `manager_compliance_reports.php` that references the `audit_trail` or `calendar` sections
4. THE System SHALL continue to support Activity Logs export in Excel, CSV, and PDF formats
5. WHEN a user exports Compliance Reports, THE System SHALL only include Activity Logs data

### Requirement 9: Update Navigation Menu Descriptions

**User Story:** As an administrator or manager, I want the Compliance Reports menu description to reflect only Activity Logs, so that I have accurate expectations when navigating to the page.

#### Acceptance Criteria

1. THE System SHALL update the admin Compliance Reports sub-menu description in `partials/rbac_menu.php` from `"Activity Logs, Audit Trail, Calendar & Schedule"` to `"Activity Logs"`
2. THE System SHALL update the manager Compliance Reports sub-menu description in `partials/rbac_menu.php` from `"Activity Logs, Audit Trail, Calendar & Schedule monitoring with validation and compliance tracking"` to `"Activity Logs monitoring with compliance tracking"`
3. THE System SHALL preserve all other menu item configurations
4. THE System SHALL not modify any menu structure or navigation behavior other than the description text

### Requirement 10: Clean Up Valid Section References

**User Story:** As a developer, I want all references to the Audit Trail and Calendar sections removed from validation arrays, so that the code correctly reflects the only available section.

#### Acceptance Criteria

1. THE System SHALL remove `'audit'` and `'calendar'` from the `$valid_sections` array in `admin_compliance_reports.php`
2. THE System SHALL remove `'audit_trail'` and `'calendar'` from the `$valid_sections` array in `manager_compliance_reports.php`
3. THE System SHALL update the `$section` validation in `admin_compliance_reports.php` so that only `'activity'` is accepted; all other values default to `'activity'`
4. THE System SHALL update the `$section` validation in `manager_compliance_reports.php` so that only `'activity_logs'` is accepted; all other values default to `'activity_logs'`
5. WHEN an invalid or deprecated section is requested, THE System SHALL default to the Activity Logs section without displaying an error

### Requirement 11: Remove Client-Side JavaScript for Audit Trail and Calendar Tabs

**User Story:** As a system maintainer, I want to remove client-side JavaScript code specific to the Audit Trail and Calendar tabs, so that the JavaScript remains clean and maintainable.

#### Acceptance Criteria

1. THE System SHALL remove all references to `'audit'` and `'audit_trail'` from the `crTab()` JavaScript function in `admin_compliance_reports.php` and `manager_compliance_reports.php`
2. THE System SHALL remove all references to `'calendar'` from the `crTab()` JavaScript function in `admin_compliance_reports.php`
3. THE System SHALL update the `crSwitchSection()` function in `manager_compliance_reports.php` to exclude `audit_trail` and `calendar` sections
4. THE System SHALL remove the `sheetNames` mappings for `audit` and `calendar` sections from JavaScript export logic
5. THE System SHALL preserve all JavaScript functionality for the Activity Logs tab

### Requirement 12: Handle Deprecated Section URLs with Redirect

**User Story:** As a user, I want graceful handling when I access deprecated Audit Trail or Calendar URLs, so that I am redirected to Activity Logs without errors.

#### Acceptance Criteria

1. WHEN a user navigates to `admin_compliance_reports.php?section=audit`, THE System SHALL redirect to `admin_compliance_reports.php?section=activity`
2. WHEN a user navigates to `admin_compliance_reports.php?section=calendar`, THE System SHALL redirect to `admin_compliance_reports.php?section=activity`
3. WHEN a user navigates to `manager_compliance_reports.php?section=audit_trail`, THE System SHALL redirect to `manager_compliance_reports.php?section=activity_logs`
4. WHEN a user navigates to `manager_compliance_reports.php?section=calendar`, THE System SHALL redirect to `manager_compliance_reports.php?section=activity_logs`
5. THE System SHALL preserve all date range parameters (`date_from`, `date_to`) during redirect
6. THE System SHALL not display error messages for deprecated section parameters

### Requirement 13: Preserve Activity Logs Fully Intact

**User Story:** As an administrator or manager, I want the Activity Logs report to remain fully functional and unchanged after the removal of Audit Trail and Calendar sections, so that compliance monitoring is not impacted.

#### Acceptance Criteria

1. THE System SHALL preserve all Activity Logs data fetching logic in both `admin_compliance_reports.php` and `manager_compliance_reports.php`
2. THE System SHALL preserve the Activity Logs tab as the default and only visible tab on the Compliance Reports page
3. THE System SHALL preserve all Activity Logs table rendering, badge styling, and display logic
4. THE System SHALL preserve Activity Logs export functionality in Excel, CSV, and PDF formats
5. THE System SHALL preserve all database tables used by Activity Logs, including `audit_logs` and `activity_logs`
6. THE System SHALL continue to write activity records to database tables as designed
7. THE System SHALL preserve all existing audit logging functionality in `backend/api/audit_logging.php`

### Requirement 14: Preserve Superadmin Audit Trail Page

**User Story:** As a superadmin or developer, I want the Superadmin Audit Trail page to remain fully functional, so that I can continue to access system-wide audit information for development and oversight purposes.

#### Acceptance Criteria

1. THE System SHALL preserve `superadmin_audit_trail.php` without modification
2. THE System SHALL preserve the navigation menu item for Superadmin Audit Trail in `partials/rbac_menu.php`
3. THE System SHALL preserve all API endpoints used by `superadmin_audit_trail.php`
4. THE System SHALL continue to display the Superadmin Audit Trail in the superadmin sidebar menu
5. WHEN a superadmin accesses `superadmin_audit_trail.php`, THE System SHALL display the full audit trail interface without any impact from this removal

### Requirement 15: Preserve the Standalone Calendar Pages

**User Story:** As a user, I want the standalone Calendar pages to remain accessible from the sidebar, so that I can continue to view calendar and schedule information from the dedicated calendar feature.

#### Acceptance Criteria

1. THE System SHALL preserve `admin_calendar.php`, `manager_calendar.php`, and `staff_calendar.php` without modification
2. THE System SHALL preserve the Calendar navigation menu item in `partials/rbac_menu.php` for all applicable roles
3. WHEN a user clicks Calendar from the sidebar, THE System SHALL navigate to the appropriate standalone calendar page as before
4. THE System SHALL not modify any calendar-related backend files or data fetching logic outside the Compliance Reports pages

### Requirement 16: Add Code Comments Documenting the Removal Decision

**User Story:** As a developer, I want clear documentation in the code indicating that the Audit Trail and Calendar & Schedule reports were intentionally removed from the Compliance Reports page, so that future maintainers understand the decision.

#### Acceptance Criteria

1. THE System SHALL add a code comment in `admin_compliance_reports.php` explaining that the Audit Trail and Calendar & Schedule reports were removed, and that Activity Logs is now the only report on this page
2. THE System SHALL add a code comment in `manager_compliance_reports.php` explaining that the Audit Trail and Calendar & Schedule reports were removed, and that Activity Logs is now the only report on this page
3. THE System SHALL preserve all existing code comments for Activity Logs sections
4. THE System SHALL include a note in comments that the standalone Calendar pages remain available via sidebar navigation, and that the Superadmin Audit Trail remains at `superadmin_audit_trail.php`
