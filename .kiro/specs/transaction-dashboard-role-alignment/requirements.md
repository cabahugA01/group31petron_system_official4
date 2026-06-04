# Requirements Document

## Introduction

This document defines the requirements for standardizing role-specific transaction module dashboards across three user roles: Staff, Manager, and Admin. Each role requires distinct summary cards, charts/views, and export capabilities tailored to their operational responsibilities. The system manages transactions from merchandise sales and job orders, with validation workflows and receivables tracking.

## Glossary

- **Staff_Dashboard**: The dashboard interface accessible to users with Staff role
- **Manager_Dashboard**: The dashboard interface accessible to users with Manager role
- **Admin_Dashboard**: The dashboard interface accessible to users with Admin role
- **Transaction**: A record of either a merchandise sale or a job order
- **Job_Order**: A service-related transaction with status tracking (Pending, Ongoing, Completed)
- **Merchandise_Transaction**: A sale transaction for physical products
- **Validation_Status**: The approval state of a transaction (Pending, Validated)
- **Variance**: A flagged anomaly or discrepancy in transaction data
- **Receivable**: An outstanding amount owed by a customer
- **Utang**: Credit-tagged receivables representing customer debt
- **Receivables_Aging**: Classification of receivables as current or overdue
- **Summary_Card**: A dashboard widget displaying a key metric
- **Export_Function**: The capability to download data in specified file formats

## Requirements

### Requirement 1: Staff Dashboard Summary Cards

**User Story:** As a Staff member, I want to see my transaction encoding metrics, so that I can track my work progress and pending items.

#### Acceptance Criteria

1. THE Staff_Dashboard SHALL display a Summary_Card showing the count of Transactions_Encoded (sum of Job_Order count and Merchandise_Transaction count)
2. THE Staff_Dashboard SHALL display a Summary_Card showing the total amount of Pending_Payments (sum of balances where payment is not fully settled)
3. THE Staff_Dashboard SHALL display a Summary_Card showing the count of Completed_Job_Orders (Job_Order records with status 'Completed')
4. WHEN a Summary_Card value changes, THE Staff_Dashboard SHALL refresh the displayed value within 5 seconds

### Requirement 2: Staff Dashboard Visualization Charts

**User Story:** As a Staff member, I want to visualize job order progress and merchandise sales patterns, so that I can understand workflow distribution and sales trends.

#### Acceptance Criteria

1. THE Staff_Dashboard SHALL display a Job_Order_Status_Chart showing the distribution of Job_Order records across statuses (Pending, Ongoing, Completed)
2. THE Staff_Dashboard SHALL display a Merchandise_Sales_Snapshot_Chart showing daily and weekly sales totals
3. WHEN transaction data is updated, THE Staff_Dashboard SHALL refresh chart data within 10 seconds

### Requirement 3: Staff Dashboard Export Functions

**User Story:** As a Staff member, I want to export my tracked job orders and merchandise history, so that I can maintain offline records and share reports with supervisors.

#### Acceptance Criteria

1. WHEN a Staff user requests Job_Order_Tracker export, THE Staff_Dashboard SHALL generate a file in the requested format (Excel, CSV, or PDF)
2. WHEN a Staff user requests Merchandise_History export, THE Staff_Dashboard SHALL generate a file in the requested format (Excel, CSV, or PDF)
3. THE Staff_Dashboard SHALL include all relevant fields in exported files (transaction ID, date, customer, amount, status, payment method)
4. WHEN export generation completes, THE Staff_Dashboard SHALL initiate file download within 3 seconds

### Requirement 4: Manager Dashboard Summary Cards

**User Story:** As a Manager, I want to see validation workflow metrics and variance alerts, so that I can monitor approval processes and catch anomalies.

#### Acceptance Criteria

1. THE Manager_Dashboard SHALL display a Summary_Card showing the count of Pending_Transactions (Transactions with Validation_Status 'Pending')
2. THE Manager_Dashboard SHALL display a Summary_Card showing the count of Validated_Today (Transactions validated on the current date)
3. THE Manager_Dashboard SHALL display a Summary_Card showing the count of Variance_Alerts (Transactions flagged as anomalies)
4. WHEN a Summary_Card value changes, THE Manager_Dashboard SHALL refresh the displayed value within 5 seconds

### Requirement 5: Manager Dashboard Visualization Charts

**User Story:** As a Manager, I want to visualize validation flow and variance trends, so that I can identify bottlenecks and recurring anomaly patterns.

#### Acceptance Criteria

1. THE Manager_Dashboard SHALL display a Validation_Flow_Chart comparing counts of Pending versus Validated Transactions
2. THE Manager_Dashboard SHALL display a Variance_Trend_Chart showing the frequency of Variance occurrences over time
3. WHEN validation or variance data is updated, THE Manager_Dashboard SHALL refresh chart data within 10 seconds

### Requirement 6: Manager Dashboard Export Functions

**User Story:** As a Manager, I want to export pending transactions, validated transactions, and variance reports, so that I can analyze data offline and maintain compliance documentation.

#### Acceptance Criteria

1. WHEN a Manager user requests Pending_Transactions export, THE Manager_Dashboard SHALL generate a file in Excel or CSV format
2. WHEN a Manager user requests Validated_Transactions export, THE Manager_Dashboard SHALL generate a file in Excel or CSV format
3. WHEN a Manager user requests Variance_Reports export, THE Manager_Dashboard SHALL generate a PDF file for compliance purposes
4. THE Manager_Dashboard SHALL include all relevant fields in exported files (transaction ID, date, customer, amount, validation status, variance type, validator name)
5. WHEN export generation completes, THE Manager_Dashboard SHALL initiate file download within 3 seconds

### Requirement 7: Admin Dashboard Summary Cards

**User Story:** As an Admin, I want to see system-wide transaction metrics, receivables status, and variance reports, so that I can maintain oversight of all operations and compliance.

#### Acceptance Criteria

1. THE Admin_Dashboard SHALL display a Summary_Card showing the count of Total_Validated_Transactions (all Transactions with Validation_Status 'Validated')
2. THE Admin_Dashboard SHALL display a Summary_Card showing the total amount of Pending_Payments (system-wide balances not fully settled)
3. THE Admin_Dashboard SHALL display a Summary_Card showing the total amount of Outstanding_Utang (Receivables tagged as credit)
4. THE Admin_Dashboard SHALL display a Summary_Card showing Receivables_Aging breakdown (current versus overdue amounts)
5. THE Admin_Dashboard SHALL display a Summary_Card showing the count of system-wide Variance_Reports
6. WHEN a Summary_Card value changes, THE Admin_Dashboard SHALL refresh the displayed value within 5 seconds

### Requirement 8: Admin Dashboard Visualization Charts

**User Story:** As an Admin, I want to visualize system-wide sales, receivables, and compliance alerts, so that I can identify trends and ensure regulatory compliance.

#### Acceptance Criteria

1. THE Admin_Dashboard SHALL display Oversight_Graphs showing system-wide sales totals and Receivables totals
2. THE Admin_Dashboard SHALL display Compliance_Alerts highlighting Variance-flagged Transactions
3. WHEN system-wide data is updated, THE Admin_Dashboard SHALL refresh chart data within 10 seconds

### Requirement 9: Admin Dashboard Export Functions

**User Story:** As an Admin, I want to export validated transactions, receivables aging, variance reports, and compliance reports, so that I can perform financial analysis and maintain audit trails.

#### Acceptance Criteria

1. WHEN an Admin user requests Validated_Transactions export, THE Admin_Dashboard SHALL generate a file in Excel or CSV format
2. WHEN an Admin user requests Receivables_Aging export, THE Admin_Dashboard SHALL generate a file in Excel or CSV format
3. WHEN an Admin user requests Variance_Reports export, THE Admin_Dashboard SHALL generate a PDF file
4. WHEN an Admin user requests Compliance_Reports export, THE Admin_Dashboard SHALL generate a PDF file
5. THE Admin_Dashboard SHALL include all relevant fields in exported files (transaction ID, date, customer, amount, validation status, payment status, aging category, variance type)
6. WHEN export generation completes, THE Admin_Dashboard SHALL initiate file download within 3 seconds

### Requirement 10: Role-Based Dashboard Access Control

**User Story:** As a system administrator, I want dashboards to enforce role-based access, so that users only see data and functions appropriate to their role.

#### Acceptance Criteria

1. WHEN a user with Staff role accesses the dashboard, THE System SHALL display the Staff_Dashboard
2. WHEN a user with Manager role accesses the dashboard, THE System SHALL display the Manager_Dashboard
3. WHEN a user with Admin role accesses the dashboard, THE System SHALL display the Admin_Dashboard
4. IF a user attempts to access a dashboard not matching their role, THEN THE System SHALL redirect them to their authorized dashboard

### Requirement 11: Dashboard Visual Consistency

**User Story:** As a user of any role, I want dashboards to follow consistent design patterns, so that I can quickly orient myself and navigate efficiently.

#### Acceptance Criteria

1. THE System SHALL apply Petron Blue (#002F70) theme to all dashboard elements
2. THE System SHALL use consistent Summary_Card styling across all role dashboards (Staff_Dashboard, Manager_Dashboard, Admin_Dashboard)
3. THE System SHALL use consistent chart styling across all role dashboards
4. THE System SHALL use consistent export button styling across all role dashboards

### Requirement 12: Dashboard Data Source Integration

**User Story:** As a developer, I want dashboards to query the correct database tables and columns, so that displayed metrics are accurate and reliable.

#### Acceptance Criteria

1. THE System SHALL retrieve Transaction data from the merchandise_transactions table
2. THE System SHALL retrieve Job_Order data from the job_orders table
3. THE System SHALL read Validation_Status values from the validation_status column
4. THE System SHALL read payment amounts from the amount_paid column
5. THE System SHALL read payment methods from the payment_method column
6. WHEN database schema changes occur, THE System SHALL continue to function with backward-compatible queries

### Requirement 13: Dashboard Performance Requirements

**User Story:** As a user of any role, I want dashboards to load quickly, so that I can access information without delays.

#### Acceptance Criteria

1. WHEN a user accesses their role dashboard, THE System SHALL load the initial page within 3 seconds
2. WHEN Summary_Card data is calculated, THE System SHALL complete computation within 2 seconds
3. WHEN chart data is rendered, THE System SHALL complete rendering within 5 seconds
4. WHEN export files are generated for datasets under 10,000 records, THE System SHALL complete generation within 10 seconds

### Requirement 14: Export File Format Standards

**User Story:** As a user of any role, I want exported files to follow standard formats, so that I can open them with common software tools.

#### Acceptance Criteria

1. WHEN an Excel export is generated, THE System SHALL produce a file in .xlsx format compliant with Office Open XML standards
2. WHEN a CSV export is generated, THE System SHALL produce a file with comma delimiters and UTF-8 encoding
3. WHEN a PDF export is generated, THE System SHALL produce a file in PDF 1.4 or higher format
4. THE System SHALL include column headers in all exported Excel and CSV files
5. THE System SHALL apply readable formatting to all exported PDF files (table borders, header row styling, page numbers)

### Requirement 15: Dashboard Error Handling

**User Story:** As a user of any role, I want dashboards to handle errors gracefully, so that I understand when data is unavailable and what actions to take.

#### Acceptance Criteria

1. IF database connection fails, THEN THE System SHALL display an error message "Unable to load dashboard data. Please contact support."
2. IF Summary_Card calculation fails, THEN THE System SHALL display "Data unavailable" in the affected Summary_Card
3. IF chart rendering fails, THEN THE System SHALL display a placeholder message in the chart area
4. IF export generation fails, THEN THE System SHALL display an error notification with the reason for failure
5. WHEN an error occurs, THE System SHALL log the error details (timestamp, user role, error type, error message) for troubleshooting
