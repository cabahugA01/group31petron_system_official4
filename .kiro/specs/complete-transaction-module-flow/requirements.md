# Requirements Document

## Introduction

This document defines requirements for the Complete Transaction Module Flow — a comprehensive transaction management system spanning three user roles (Staff, Manager, Admin) with full audit trail capabilities. The system enables transaction encoding, validation workflows, shift summary reporting, and executive oversight for a petrol station management system. It covers Job Order services, Merchandise sales, and combined transactions with immediate inventory reflection, multi-level approval workflows, and export capabilities.

## Glossary

- **Staff_Transaction_Encoder**: The Staff role responsible for encoding transactions (Job Order services, Merchandise, or combined)
- **Manager_Validator**: The Manager role responsible for approving, rejecting, or adjusting pending transactions and generating shift summary reports
- **Admin_Overseer**: The Admin role responsible for oversight, performance metrics, variance alerts, and compliance reporting
- **Transaction_System**: The software system managing transaction workflows across all roles
- **Job_Order**: A service transaction record (e.g., oil change, tire rotation) with optional parts/items
- **Merchandise_Transaction**: A merchandise-only sales transaction record
- **Combined_Transaction**: A transaction containing both Job Order service and Merchandise items
- **Job_Order_Tracker**: The system interface displaying all service transactions
- **Merchandise_History**: The system interface displaying all merchandise transactions
- **Shift_Period**: A defined work period (Shift 1, Shift 2) with start and end times
- **Shift_Summary_Report**: A report aggregating sales, services, and payment status per shift
- **Pending_Transaction**: A transaction awaiting Manager validation (Approve/Reject/Adjust)
- **Validated_Transaction**: A transaction approved or adjusted by Manager
- **Audit_Trail**: A chronological log of all transaction actions with user ID and timestamp
- **Inventory_System**: The stock management system for merchandise and fuel items
- **Variance_Alert**: A notification of discrepancies between encoded and expected values
- **Receivables_Aging**: A report showing payment due dates and overdue days for credit transactions
- **Export_Module**: The system component generating Excel, CSV, and PDF reports

## Requirements

### Requirement 1: Staff Transaction Encoding

**User Story:** As a Staff_Transaction_Encoder, I want to encode transactions for services, merchandise, or both, so that all sales are recorded and inventory is immediately updated.

#### Acceptance Criteria

1. WHEN the Staff_Transaction_Encoder selects "Job Order (Service Only)", THE Transaction_System SHALL create a Job_Order record with service details, optional parts, customer information, and payment method
2. WHEN the Staff_Transaction_Encoder selects "Merchandise Only", THE Transaction_System SHALL create a Merchandise_Transaction record with product items, quantities, pricing, customer information, and payment method
3. WHEN the Staff_Transaction_Encoder selects "Job Order + Merchandise", THE Transaction_System SHALL create a Combined_Transaction record linking both service and merchandise items
4. WHEN a transaction is saved, THE Transaction_System SHALL record the Staff ID and creation timestamp in the transaction record
5. WHEN a Job_Order is created, THE Job_Order_Tracker SHALL display the new service transaction within 2 seconds
6. WHEN a Merchandise_Transaction is created, THE Merchandise_History SHALL display the new sales transaction within 2 seconds
7. THE Transaction_System SHALL validate that all required fields (customer name, payment method, items/services) are present before saving
8. THE Transaction_System SHALL NOT display "Pending" or "Approved" counters to Staff_Transaction_Encoder (validation role is Manager/Admin only)
9. THE Transaction_System SHALL display only "In Progress", "Completed", and "Rejected" status counters to Staff_Transaction_Encoder

### Requirement 2: Inventory Deduction on Transaction Encoding

**User Story:** As a Staff_Transaction_Encoder, I want inventory to automatically update when I encode a transaction, so that stock levels remain accurate in real-time.

#### Acceptance Criteria

1. WHEN a Merchandise_Transaction is saved, THE Inventory_System SHALL deduct the encoded quantities from the station stock levels within 2 seconds
2. WHEN a Job_Order with parts is saved, THE Inventory_System SHALL deduct the parts quantities from the station stock levels within 2 seconds
3. WHEN a Combined_Transaction is saved, THE Inventory_System SHALL deduct both merchandise and parts quantities from the station stock levels within 2 seconds
4. WHEN fuel items are included in a transaction, THE Inventory_System SHALL deduct the fuel quantity from the fuel inventory current level
5. IF stock level after deduction becomes negative, THE Transaction_System SHALL log a Variance_Alert for Manager review
6. THE Transaction_System SHALL record inventory deduction status in the transaction record

### Requirement 3: Staff Transaction Audit Trail Entry

**User Story:** As a Staff_Transaction_Encoder, I want my actions logged automatically, so that there is a compliance record of who encoded each transaction.

#### Acceptance Criteria

1. WHEN a transaction is created, THE Audit_Trail SHALL log the action with Staff ID, transaction type, transaction ID, and timestamp
2. WHEN a transaction is edited, THE Audit_Trail SHALL log the modification with Staff ID, changed fields, old values, new values, and timestamp
3. THE Audit_Trail SHALL store the IP address of the Staff_Transaction_Encoder for each action
4. THE Audit_Trail SHALL remain immutable after creation (no deletions or edits)

### Requirement 4: Staff Export Options

**User Story:** As a Staff_Transaction_Encoder, I want to export service records and generate individual transaction receipts, so that I can provide documentation and summaries.

#### Acceptance Criteria

1. WHEN the Staff_Transaction_Encoder views the Job Order Tracker and clicks "Export to Excel", THE Export_Module SHALL generate an Excel file containing all service (Job Order) records for the selected date range within 5 seconds
2. WHEN the Staff_Transaction_Encoder views the Job Order Tracker and clicks "Export to CSV", THE Export_Module SHALL generate a CSV file containing all service (Job Order) records for the selected date range within 5 seconds
3. WHEN the Staff_Transaction_Encoder selects a specific transaction and clicks "Print Receipt", THE Export_Module SHALL generate a PDF receipt for that individual transaction with transaction details, items, amounts, and station branding within 3 seconds
4. THE Job Order Tracker export SHALL include column headers in Excel and CSV exports (Transaction ID, Customer, Date, Vehicle, Service, Items/Parts, Amount, Payment Method, Status)
5. THE Export_Module SHALL format currency values with 2 decimal places in all exports
6. THE Job Order Tracker SHALL provide ONLY Excel and CSV export options (no bulk PDF export)

### Requirement 5: Manager Pending Transactions Table

**User Story:** As a Manager_Validator, I want to view all pending transactions in a table with action buttons, so that I can approve, reject, or adjust them efficiently.

#### Acceptance Criteria

1. WHEN the Manager_Validator navigates to the Pending Transactions page, THE Transaction_System SHALL display a table with all Pending_Transaction records for the manager's station within 3 seconds
2. THE Pending_Transaction table SHALL display columns: Transaction ID, Customer Name, Transaction Type, Vehicle (if applicable), Items/Parts, Service Name (if applicable), Total Amount, Payment Method, Status, Transaction Date, Staff Name
3. WHEN the Manager_Validator clicks "Approve" on a transaction row, THE Transaction_System SHALL display a confirmation dialog with optional approval notes field
4. WHEN the Manager_Validator clicks "Reject" on a transaction row, THE Transaction_System SHALL display a rejection dialog requiring a reason field
5. WHEN the Manager_Validator clicks "Adjust" on a transaction row, THE Transaction_System SHALL display an adjustment form with editable fields for quantities, prices, and an adjustment reason field
6. THE Pending_Transaction table SHALL support bulk selection with checkboxes for batch approve or reject actions
7. THE Pending_Transaction table SHALL refresh automatically every 30 seconds to show newly encoded transactions

### Requirement 6: Manager Transaction Validation Actions

**User Story:** As a Manager_Validator, I want to approve, reject, or adjust transactions, so that only verified records proceed to final reporting.

#### Acceptance Criteria

1. WHEN the Manager_Validator approves a transaction, THE Transaction_System SHALL update the transaction status to "Validated", set the validated_by field to the Manager ID, and set validated_at to the current timestamp within 2 seconds
2. WHEN the Manager_Validator rejects a transaction, THE Transaction_System SHALL update the transaction status to "Rejected", set the validated_by field to the Manager ID, set validated_at to the current timestamp, store the rejection reason, and reverse any inventory deductions within 3 seconds
3. WHEN the Manager_Validator adjusts a transaction, THE Transaction_System SHALL update the transaction record with new values, update the transaction status to "Validated", set the validated_by field to the Manager ID, set validated_at to the current timestamp, store the adjustment reason, and recalculate inventory deductions based on the difference between old and new quantities within 3 seconds
4. WHEN a rejection is processed, THE Inventory_System SHALL restore the deducted quantities back to the station stock levels
5. WHEN an adjustment is processed, THE Inventory_System SHALL adjust the stock levels by the delta (old quantity minus new quantity)
6. THE Transaction_System SHALL validate that rejection reason is not empty before processing rejection
7. THE Transaction_System SHALL validate that adjustment reason is not empty before processing adjustment

### Requirement 7: Manager and Admin Validation Notes Storage

**User Story:** As a Manager_Validator or Admin_Overseer, I want to record notes when I approve, reject, or adjust transactions, so that validation decisions are documented and traceable.

#### Acceptance Criteria

1. WHEN the Manager_Validator or Admin_Overseer submits an approval with notes, THE Transaction_System SHALL store the notes in the transaction record validation_notes field
2. WHEN the Manager_Validator or Admin_Overseer submits a rejection, THE Transaction_System SHALL store the rejection reason in the transaction record reject_reason field (REQUIRED)
3. WHEN the Manager_Validator or Admin_Overseer submits an adjustment, THE Transaction_System SHALL store the adjustment reason in the transaction record adjustment_notes field (REQUIRED)
4. THE Transaction_System SHALL allow Staff_Transaction_Encoder to add optional general remarks/comments on transactions
5. THE Transaction_System SHALL NOT allow Staff_Transaction_Encoder to add approval/rejection/adjustment reasons (Manager/Admin only)
6. THE Transaction_System SHALL display validation notes, rejection reasons, and adjustment notes in the Admin_Overseer's Validated Transactions table
7. THE Audit_Trail SHALL include the notes/reason text in the audit log entry for approve/reject/adjust actions

### Requirement 8: Manager Shift Summary Reports

**User Story:** As a Manager_Validator, I want to generate shift summary reports, so that I can review daily performance for Shift 1 and Shift 2 separately.

#### Acceptance Criteria

1. WHEN the Manager_Validator navigates to Shift Summary Reports, THE Transaction_System SHALL display a report selection interface with date picker and shift selector (Shift 1, Shift 2, or All Shifts)
2. WHEN the Manager_Validator selects a date and shift, THE Transaction_System SHALL generate a Shift_Summary_Report displaying total sales, total services, top items sold, and payment status breakdown (Paid, Pending, Utang/Credit) within 4 seconds
3. THE Shift_Summary_Report SHALL display total sales amount for the selected shift with 2 decimal places
4. THE Shift_Summary_Report SHALL display total services count for the selected shift
5. THE Shift_Summary_Report SHALL display top 5 items sold (by quantity) for the selected shift with product names and quantities
6. THE Shift_Summary_Report SHALL display payment status breakdown showing count and total amount for Paid, Pending, and Utang categories
7. THE Shift_Summary_Report SHALL include only Validated_Transaction records (exclude Pending_Transaction and Rejected transactions)

### Requirement 9: Manager System Updates on Validation

**User Story:** As a Manager_Validator, I want the system to update reports, audit trail, and dashboard automatically when I validate transactions, so that all data remains consistent across modules.

#### Acceptance Criteria

1. WHEN a transaction is validated, THE Transaction_System SHALL trigger a recalculation of sales reports within 3 seconds
2. WHEN a transaction is validated, THE Transaction_System SHALL trigger a recalculation of service reports within 3 seconds
3. WHEN a transaction is validated, THE Transaction_System SHALL trigger a recalculation of inventory reports within 3 seconds
4. WHEN a transaction is validated, THE Audit_Trail SHALL log the validation action with Manager ID, transaction ID, action type (Approve/Reject/Adjust), notes/reason, and timestamp
5. WHEN a transaction is rejected or adjusted, THE Transaction_System SHALL trigger a Variance_Alert visible to Admin_Overseer

### Requirement 10: Manager Export Options

**User Story:** As a Manager_Validator, I want to export pending transactions, validated records, and shift summaries, so that I can archive reports or share them with stakeholders.

#### Acceptance Criteria

1. WHEN the Manager_Validator clicks "Export Pending List to Excel", THE Export_Module SHALL generate an Excel file containing all Pending_Transaction records with all table columns within 5 seconds
2. WHEN the Manager_Validator clicks "Export Pending List to CSV", THE Export_Module SHALL generate a CSV file containing all Pending_Transaction records with all table columns within 5 seconds
3. WHEN the Manager_Validator clicks "Export Validated Records to Excel", THE Export_Module SHALL generate an Excel file containing all Validated_Transaction records for the selected date range within 5 seconds
4. WHEN the Manager_Validator clicks "Export Validated Records to CSV", THE Export_Module SHALL generate a CSV file containing all Validated_Transaction records for the selected date range within 5 seconds
5. WHEN the Manager_Validator clicks "Export Shift Summary to PDF", THE Export_Module SHALL generate a PDF document containing the Shift_Summary_Report with all sections (sales, services, top items, payment breakdown) and station branding within 5 seconds
6. THE Export_Module SHALL include the export date and time in the file footer or metadata

### Requirement 11: Admin Validated Transactions Table

**User Story:** As an Admin_Overseer, I want to view all validated transactions in a comprehensive table, so that I can monitor completed transactions and spot issues.

#### Acceptance Criteria

1. WHEN the Admin_Overseer navigates to the Oversight Dashboard, THE Transaction_System SHALL display a table with all Validated_Transaction records for all stations within 4 seconds
2. THE Validated_Transaction table SHALL display columns: Transaction ID, Station Name, Customer Name, Transaction Type, Items/Parts, Service Name (if applicable), Total Amount, Payment Method, Status, Transaction Date, Staff Name, Manager Name, Validation Notes, Inventory Impact
3. THE Validated_Transaction table SHALL support filtering by date range, station, transaction type, and payment method
4. THE Validated_Transaction table SHALL support sorting by any column
5. THE Validated_Transaction table SHALL display pagination controls with rows per page options (10, 25, 50, 100)
6. THE Inventory Impact column SHALL display a summary (e.g., "-5 Oil Filter, -2L Fuel") showing items deducted from inventory

### Requirement 12: Admin Variance Alerts Summary

**User Story:** As an Admin_Overseer, I want to see variance alerts for transactions with discrepancies, so that I can investigate potential errors or fraud.

#### Acceptance Criteria

1. WHEN the Admin_Overseer navigates to the Variance Alerts panel, THE Transaction_System SHALL display a list of all Variance_Alert records within 3 seconds
2. WHEN a transaction has a negative stock level after deduction, THE Transaction_System SHALL create a Variance_Alert with type "Stock Shortage" and message describing the item and shortage amount
3. WHEN a Manager adjusts a transaction, THE Transaction_System SHALL create a Variance_Alert with type "Manager Adjustment" and message describing the old and new values
4. WHEN a transaction is rejected, THE Transaction_System SHALL create a Variance_Alert with type "Rejection" and message including the rejection reason
5. THE Variance_Alert SHALL display transaction ID, alert type, message, date, and station name
6. THE Admin_Overseer SHALL be able to mark a Variance_Alert as "Reviewed" to hide it from the active alerts list

### Requirement 13: Admin Receivables Aging Report

**User Story:** As an Admin_Overseer, I want to see receivables aging information, so that I can monitor overdue credit transactions and take collection actions.

#### Acceptance Criteria

1. WHEN the Admin_Overseer navigates to the Receivables Aging panel, THE Transaction_System SHALL display a table of all credit transactions (Utang) with payment status "Pending" or "Partial" within 4 seconds
2. THE Receivables Aging table SHALL display columns: Transaction ID, Customer Name, Total Amount, Amount Paid, Balance Due, Due Date, Overdue Days, Transaction Date, Staff Name
3. THE Transaction_System SHALL calculate Overdue Days as the difference between current date and due date (0 if not yet due)
4. THE Receivables Aging table SHALL highlight rows with Overdue Days > 0 in yellow
5. THE Receivables Aging table SHALL highlight rows with Overdue Days > 30 in red
6. THE Receivables Aging table SHALL support filtering by customer name, station, and overdue status (All, Not Yet Due, 1-30 Days, 31-60 Days, Over 60 Days)
7. THE Receivables Aging table SHALL display total balance due at the bottom of the table

### Requirement 14: Admin Performance Metrics Panel

**User Story:** As an Admin_Overseer, I want to view performance metrics, so that I can assess overall system activity and identify top performers.

#### Acceptance Criteria

1. WHEN the Admin_Overseer navigates to the Performance Metrics panel, THE Transaction_System SHALL display summary cards showing Total Sales, Total Services, Top Items Sold, and Staff Top Encoder within 3 seconds
2. THE Total Sales card SHALL display the sum of all Validated_Transaction amounts for the selected date range with 2 decimal places
3. THE Total Services card SHALL display the count of all Job_Order records with status "Validated" for the selected date range
4. THE Top Items Sold card SHALL display the top 5 products by quantity sold across all Validated_Transaction records for the selected date range with product names and quantities
5. THE Staff Top Encoder card SHALL display the staff member with the highest transaction count for the selected date range with staff name and transaction count
6. THE Performance Metrics panel SHALL support date range selection (Today, This Week, This Month, Custom Range)

### Requirement 15: Admin Audit Trail Access

**User Story:** As an Admin_Overseer, I want to access the full audit trail log, so that I can ensure compliance and investigate suspicious activity.

#### Acceptance Criteria

1. WHEN the Admin_Overseer navigates to Compliance Reports and selects the Audit Trail tab, THE Transaction_System SHALL display the Audit_Trail log in chronological order (newest first) within 4 seconds
2. THE Audit_Trail log SHALL display columns: Timestamp, User Name, User Role, Action Type, Entity Type, Entity ID, Details, Station Name, IP Address
3. THE Audit_Trail log SHALL support filtering by date range, user, action type (Create, Edit, Approve, Reject, Adjust, Delete), and station
4. THE Audit_Trail log SHALL support full-text search on the Details field
5. THE Audit_Trail log SHALL display pagination controls with rows per page options (25, 50, 100, 200)
6. THE Audit_Trail SHALL be read-only (Admin_Overseer cannot edit or delete entries)

### Requirement 16: Admin Export Options

**User Story:** As an Admin_Overseer, I want to export validated transactions and audit trail logs, so that I can archive compliance records and generate executive reports.

#### Acceptance Criteria

1. WHEN the Admin_Overseer clicks "Export Validated Transactions to Excel", THE Export_Module SHALL generate an Excel file containing all Validated_Transaction records for the selected filters within 6 seconds
2. WHEN the Admin_Overseer clicks "Export Validated Transactions to CSV", THE Export_Module SHALL generate a CSV file containing all Validated_Transaction records for the selected filters within 6 seconds
3. WHEN the Admin_Overseer clicks "Export Audit Trail to Excel", THE Export_Module SHALL generate an Excel file containing all Audit_Trail records for the selected filters within 8 seconds
4. WHEN the Admin_Overseer clicks "Export Audit Trail to CSV", THE Export_Module SHALL generate a CSV file containing all Audit_Trail records for the selected filters within 8 seconds
5. WHEN the Admin_Overseer clicks "Export Audit Trail to PDF", THE Export_Module SHALL generate a PDF document containing all Audit_Trail records for the selected filters with all columns within 10 seconds
6. THE Export_Module SHALL include the Admin name, export date, and filters applied in the file header or metadata

### Requirement 17: Transaction Payment Status Tracking

**User Story:** As a Manager_Validator or Admin_Overseer, I want to track payment status (Paid, Pending, Utang), so that I can differentiate between fully paid, partial, and credit transactions.

#### Acceptance Criteria

1. WHEN a transaction is created with payment method "Cash" and amount_paid equals total_amount, THE Transaction_System SHALL set payment_status to "Paid"
2. WHEN a transaction is created with payment method "Credit" or "Utang" and amount_paid is less than total_amount, THE Transaction_System SHALL set payment_status to "Pending" and calculate balance_due as total_amount minus amount_paid
3. WHEN a transaction payment is updated, THE Transaction_System SHALL recalculate payment_status and balance_due within 2 seconds
4. THE Transaction_System SHALL display payment_status as a badge (green for "Paid", yellow for "Pending", red for "Overdue") in all transaction tables
5. THE Transaction_System SHALL include payment_status in all export files

### Requirement 18: Transaction Type Classification

**User Story:** As the Transaction_System, I want to classify transactions by type (Job Order, Merchandise, Combined), so that reports can be segmented correctly.

#### Acceptance Criteria

1. WHEN a transaction contains only a Job_Order service with no merchandise items, THE Transaction_System SHALL set transaction_type to "Job Order"
2. WHEN a transaction contains only merchandise items with no Job_Order service, THE Transaction_System SHALL set transaction_type to "Merchandise"
3. WHEN a transaction contains both a Job_Order service and merchandise items, THE Transaction_System SHALL set transaction_type to "Combined"
4. THE Transaction_System SHALL store transaction_type in the transaction record for filtering and reporting
5. THE Pending_Transaction table SHALL display transaction_type in the Transaction Type column
6. THE Validated_Transaction table SHALL display transaction_type in the Transaction Type column

### Requirement 19: Shift Period Association

**User Story:** As the Transaction_System, I want to associate each transaction with a shift period, so that shift summary reports are accurate.

#### Acceptance Criteria

1. WHEN a Staff_Transaction_Encoder creates a transaction, THE Transaction_System SHALL detect the current Shift_Period based on the staff member's active labor session or system time within 1 second
2. WHEN a Shift_Period is detected, THE Transaction_System SHALL store the shift_period (e.g., "shift_1", "shift_2") and shift_name (e.g., "First Shift", "Second Shift") in the transaction record
3. IF no Shift_Period is detected, THE Transaction_System SHALL default to "general" shift_period and empty shift_name
4. THE Transaction_System SHALL use shift_period for grouping transactions in Shift_Summary_Report
5. THE Pending_Transaction table SHALL display shift_name in a Shift column

### Requirement 20: Multi-Station Support

**User Story:** As the Transaction_System, I want to isolate transactions by station, so that each station's data remains separate and secure.

#### Acceptance Criteria

1. WHEN a Staff_Transaction_Encoder creates a transaction, THE Transaction_System SHALL store the staff member's station_id in the transaction record
2. WHEN a Manager_Validator views pending transactions, THE Transaction_System SHALL display only transactions where station_id matches the manager's assigned station
3. WHEN an Admin_Overseer views validated transactions, THE Transaction_System SHALL display transactions from all stations with station name visible
4. THE Transaction_System SHALL include station_id in all database queries to prevent cross-station data leakage
5. THE Export_Module SHALL include station name in all exported files

### Requirement 21: Transaction Editing Restrictions

**User Story:** As the Transaction_System, I want to prevent editing of validated or rejected transactions, so that historical records remain immutable.

#### Acceptance Criteria

1. WHEN a transaction status is "Validated", THE Transaction_System SHALL disable all edit buttons and form fields for Staff_Transaction_Encoder
2. WHEN a transaction status is "Rejected", THE Transaction_System SHALL disable all edit buttons and form fields for Staff_Transaction_Encoder
3. WHEN a transaction status is "Pending", THE Transaction_System SHALL allow Staff_Transaction_Encoder to edit the transaction before Manager validation
4. THE Transaction_System SHALL log all edit attempts in the Audit_Trail regardless of success or failure

### Requirement 22: Real-Time Dashboard Sync

**User Story:** As a Manager_Validator, I want the dashboard to refresh automatically after I validate a transaction, so that data remains current.

#### Acceptance Criteria

1. WHEN a transaction is validated (approved, rejected, or adjusted), THE Transaction_System SHALL refresh the pending transactions table within 2 seconds
2. WHEN a transaction is validated, THE Transaction_System SHALL trigger recalculation of shift summary reports within 3 seconds
3. THE Transaction_System SHALL use AJAX polling every 30 seconds to refresh the pending transactions table
4. THE Transaction_System SHALL display a visual notification (toast message) when a validation action completes successfully

### Requirement 23: Customer Information Capture

**User Story:** As a Staff_Transaction_Encoder, I want to capture customer information (name, contact, vehicle) for each transaction, so that records are traceable and customer history is available.

#### Acceptance Criteria

1. WHEN encoding a transaction, THE Transaction_System SHALL display fields for customer name, contact number, and vehicle details (if applicable)
2. THE Transaction_System SHALL validate that customer name is not empty before saving
3. WHEN a customer has existing credit transactions, THE Transaction_System SHALL display a warning showing the customer's current balance due
4. THE Transaction_System SHALL support customer name autocomplete based on existing customers in the database
5. THE Transaction_System SHALL store customer_name, contact_number, and vehicle information in the transaction record

### Requirement 24: Transaction Date and Time Accuracy

**User Story:** As the Transaction_System, I want to record accurate transaction timestamps, so that reports reflect the actual time of transaction encoding.

#### Acceptance Criteria

1. WHEN a transaction is created, THE Transaction_System SHALL set transaction_date to the current server date and created_at to the current server timestamp
2. WHEN a transaction is validated, THE Transaction_System SHALL set validated_at to the current server timestamp
3. THE Transaction_System SHALL use server time (not client browser time) for all timestamps
4. THE Transaction_System SHALL display transaction_date and created_at in all transaction tables using format "YYYY-MM-DD HH:MM:SS"
5. THE Transaction_System SHALL include transaction_date and created_at in all export files

### Requirement 25: Error Handling and User Feedback

**User Story:** As a Staff_Transaction_Encoder, Manager_Validator, or Admin_Overseer, I want to see clear error messages when operations fail, so that I can take corrective action.

#### Acceptance Criteria

1. WHEN a transaction save fails due to missing required fields, THE Transaction_System SHALL display an error message listing the missing fields
2. WHEN a validation action fails due to a database error, THE Transaction_System SHALL display an error message "Validation failed. Please try again or contact support."
3. WHEN an export operation fails, THE Transaction_System SHALL display an error message "Export failed. Please check your filters and try again."
4. WHEN a transaction save succeeds, THE Transaction_System SHALL display a success message "Transaction saved successfully."
5. WHEN a validation action succeeds, THE Transaction_System SHALL display a success message "Transaction validated successfully."
6. THE Transaction_System SHALL log all errors to the server error log with timestamp, user ID, and error details
7. THE Transaction_System SHALL display user-friendly messages (not raw SQL errors or stack traces)
