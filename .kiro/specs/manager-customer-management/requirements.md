# Requirements Document

## Introduction

The Manager Customer Management module is a dedicated section of the Manager Dashboard in the gas station management system. It gives station managers a consolidated view of all customers registered at their station, tools to monitor outstanding balances and credit lines, and a read-only audit trail of validated transactions. The module is organized into three sections: Customer List, Customer Balances, and Customer History. It sits alongside existing manager features such as Fuel Management and is scoped to the manager's assigned station.

---

## Glossary

- **Manager**: A system user with the `manager` role who oversees a single gas station.
- **Customer**: A record in the `customers` table representing an individual or business that transacts at the station. Customers may be of type `credit` (account-receivable) or `walk-in`.
- **Credit_Limit**: The maximum outstanding balance a credit customer is permitted to carry, stored in `customers.credit_limit`.
- **Outstanding_Balance**: The current unpaid amount owed by a customer, derived from `customers.balance` / `customers.current_balance` or aggregated from linked transactions.
- **Customer_List**: The section of the module that displays all customers registered at the manager's station.
- **Customer_Balances**: The section that shows each credit customer's outstanding balance, credit limit, and utilization, and allows the manager to validate (record) payments.
- **Customer_History**: The section that shows validated transactions linked to a customer within a selected date range, and allows export.
- **Payment_Validation**: The manager action of recording a payment received from a customer, which reduces the customer's outstanding balance.
- **Station**: A physical gas station identified by `station_id`, to which the manager and customers are scoped.
- **Audit_Log**: The `audit_logs` table entry created whenever a manager performs a sensitive action such as validating a payment.
- **Export**: The action of downloading a report as a CSV or PDF file.
- **Search**: A text-based filter applied to customer name, contact number, or ID number.
- **Filter**: A criteria-based restriction applied to a list (e.g., by status, balance range, or date range).

---

## Requirements

### Requirement 1: Customer List — Display All Customers

**User Story:** As a manager, I want to view a complete list of all customers registered at my station, so that I have full oversight of the customer database.

#### Acceptance Criteria

1. THE Customer_List SHALL display all customers whose `station_id` matches the manager's assigned station.
2. THE Customer_List SHALL show the following columns for each customer: full name, contact number, customer type (`credit` or `walk-in`), credit limit, outstanding balance, status (`active` / `inactive`), and registration date.
3. WHEN the Customer_List page loads, THE Customer_List SHALL retrieve and render customer records within 3 seconds for datasets of up to 1,000 customers.
4. WHEN no customers are registered at the station, THE Customer_List SHALL display a message indicating that no customer records are available.
5. THE Customer_List SHALL paginate results, displaying a maximum of 50 records per page, with navigation controls to move between pages.

---

### Requirement 2: Customer List — Search and Filter

**User Story:** As a manager, I want to search and filter the customer list, so that I can quickly locate specific customers without scrolling through the entire database.

#### Acceptance Criteria

1. WHEN the manager enters text in the search field, THE Customer_List SHALL filter displayed records to those whose name, contact number, or ID number contains the entered text (case-insensitive, partial match).
2. WHEN the manager selects a status filter value (`active`, `inactive`, or `all`), THE Customer_List SHALL display only customers matching the selected status.
3. WHEN the manager selects a customer type filter (`credit`, `walk-in`, or `all`), THE Customer_List SHALL display only customers matching the selected type.
4. WHEN the manager clears all filters, THE Customer_List SHALL restore the full unfiltered customer list.
5. WHEN a search or filter is applied and no records match, THE Customer_List SHALL display a message indicating that no customers match the current criteria.
6. WHEN the manager applies a search or filter, THE Customer_List SHALL reset pagination to page 1.

---

### Requirement 3: Customer Balances — Display Balance Overview

**User Story:** As a manager, I want to monitor each credit customer's outstanding balance and credit line, so that I can ensure collection monitoring and identify overdue accounts.

#### Acceptance Criteria

1. THE Customer_Balances SHALL display all customers at the manager's station who have a credit limit greater than 0 or an outstanding balance greater than 0.
2. THE Customer_Balances SHALL show the following columns for each customer: full name, credit limit, outstanding balance, available credit (credit limit minus outstanding balance), credit utilization percentage, and last transaction date.
3. WHEN a customer's outstanding balance equals or exceeds their credit limit, THE Customer_Balances SHALL visually flag that customer's row as over-limit.
4. WHEN a customer's credit utilization percentage is 80% or above but below 100%, THE Customer_Balances SHALL visually flag that customer's row as near-limit.
5. THE Customer_Balances SHALL display a summary row showing the total outstanding balance and total credit limit across all listed customers.
6. WHEN the Customer_Balances section loads, THE Customer_Balances SHALL retrieve and render balance data within 3 seconds for datasets of up to 500 credit customers.

---

### Requirement 4: Customer Balances — Payment Validation

**User Story:** As a manager, I want to validate (record) payments received from customers, so that outstanding balances are kept accurate and collections are properly tracked.

#### Acceptance Criteria

1. WHEN the manager selects a customer and submits a payment amount greater than 0, THE Customer_Balances SHALL reduce the customer's outstanding balance by the submitted payment amount.
2. WHEN the manager submits a payment, THE Customer_Balances SHALL require a payment amount greater than 0 and a payment reference or notes field of at least 3 characters before processing.
3. IF the submitted payment amount exceeds the customer's current outstanding balance, THEN THE Customer_Balances SHALL display a confirmation prompt before processing, stating the overpayment amount.
4. WHEN a payment is successfully validated, THE Customer_Balances SHALL create an entry in the `audit_logs` table recording the manager's user ID, the customer ID, the payment amount, the reference/notes, and the timestamp.
5. WHEN a payment is successfully validated, THE Customer_Balances SHALL display a success message confirming the payment and the updated outstanding balance.
6. IF a database error occurs during payment validation, THEN THE Customer_Balances SHALL roll back the transaction and display an error message without modifying the customer's balance.
7. WHEN a payment is successfully validated, THE Customer_Balances SHALL refresh the customer's displayed balance and utilization without requiring a full page reload.

---

### Requirement 5: Customer History — Display Validated Transactions

**User Story:** As a manager, I want to view the validated transaction history for each customer, so that I can ensure transparency and review past activity.

#### Acceptance Criteria

1. THE Customer_History SHALL display validated transactions linked to customers at the manager's station, sourced from `merchandise_transactions`, `job_orders`, and payment records.
2. THE Customer_History SHALL show the following columns for each transaction: transaction date, transaction reference number, transaction type (merchandise sale, job order, or payment), amount, payment method, and recorded-by staff name.
3. WHEN the manager selects a date range, THE Customer_History SHALL display only transactions whose transaction date falls within the selected start and end dates (inclusive).
4. WHEN the manager selects a specific customer from a dropdown or search, THE Customer_History SHALL display only transactions linked to that customer.
5. WHEN no transactions match the selected filters, THE Customer_History SHALL display a message indicating that no transaction records are available for the selected criteria.
6. THE Customer_History SHALL default to displaying transactions from the past 90 days when the section first loads.
7. WHEN the Customer_History section loads with default filters, THE Customer_History SHALL retrieve and render results within 3 seconds for datasets of up to 2,000 transactions.

---

### Requirement 6: Customer History — Export

**User Story:** As a manager, I want to export the customer transaction history, so that I can produce reports for review, auditing, or record-keeping.

#### Acceptance Criteria

1. WHEN the manager clicks the export button, THE Customer_History SHALL generate a downloadable file containing all transaction rows currently visible under the active filters.
2. THE Customer_History SHALL support export in CSV format.
3. WHERE the manager's browser supports PDF generation, THE Customer_History SHALL also offer export in PDF format.
4. THE Customer_History export file SHALL include the following columns: customer name, transaction date, transaction reference, transaction type, amount, payment method, and staff name.
5. THE Customer_History export file SHALL include a header row identifying the station name, the manager's name, the export date, and the applied date range.
6. WHEN the export file contains zero rows (no transactions match the active filters), THE Customer_History SHALL display a warning message and SHALL NOT generate an empty file.

---

### Requirement 7: Access Control and Station Scoping

**User Story:** As a system administrator, I want the Customer Management module to be restricted to authorized manager-role users and scoped to their station, so that data from other stations is never exposed.

#### Acceptance Criteria

1. WHEN a user without the `manager`, `admin`, or `superadmin` role attempts to access any section of the Customer Management module, THE Customer_Management_Module SHALL redirect the user to `dashboard.php`.
2. THE Customer_Management_Module SHALL scope all database queries to the manager's assigned `station_id`, ensuring that customers and transactions from other stations are never returned.
3. WHEN the manager's session does not contain a valid `station_id`, THE Customer_Management_Module SHALL terminate the request and display an error message.
4. THE Customer_Management_Module SHALL enforce RBAC permissions `approve_transactions` or `manage_job_orders` as required by the existing `rbac_menu.php` configuration before rendering any section.

---

### Requirement 8: Audit Logging

**User Story:** As a system administrator, I want all manager actions within the Customer Management module to be logged, so that there is a traceable record of changes for compliance and accountability.

#### Acceptance Criteria

1. WHEN the manager validates a payment, THE Audit_Log SHALL record the action type `Payment Validated`, the customer ID, the payment amount, the reference notes, the manager's user ID, the IP address, and the timestamp.
2. WHEN the manager exports a customer history report, THE Audit_Log SHALL record the action type `Export Customer History`, the applied filters (customer name or ID, date range), the manager's user ID, and the timestamp.
3. THE Audit_Log SHALL use the existing `audit_logs` table and `log_activity()` function consistent with the rest of the system.
