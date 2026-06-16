# Requirements Document

## Introduction

This feature formalizes the **Soft Transaction Approval Flow** for the Petron Station Management System. The core principle is that a Staff member's encoded entry (Job Order or Merchandise) becomes **immediately visible** in the Staff tracker/history with a "Pending Validation" status — no blocking wait required. The Manager can approve or reject entries **anytime**, and upon approval the system updates Reports, Dashboard totals, Inventory, and the Audit Trail. The Admin sees only validated/consolidated data in their oversight views.

Tech stack: PHP (no framework), MySQL via PDO, Vanilla JS, XAMPP.

---

## Glossary

- **Staff**: A user with role `staff`, `cashier`, or `pump_attendant` who encodes transactions.
- **Manager**: A user with role `manager` or `supervisor` responsible for validating entries.
- **Admin / SuperAdmin**: A user with role `admin` or `superadmin` who oversees consolidated reports and the audit trail.
- **Transaction**: A Job Order (service-linked) or Merchandise sale record encoded by Staff.
- **Soft Flow**: The pattern where a newly encoded transaction is immediately visible to the Staff who created it, even while it is still awaiting Manager approval.
- **Pending Validation**: The initial status of every Staff-encoded transaction.
- **Approved / Validated**: The status after a Manager approves a transaction.
- **Rejected**: The status after a Manager rejects a transaction.
- **Tracker**: The Staff-facing Job Order Tracker or Merchandise History panel inside `staff_transactions_hub.php`.
- **Audit Trail**: The `audit_logs` / `audit_trail` tables that record every encode, approval, rejection, and adjustment event with actor, timestamp, and station ID.
- **Dashboard**: The role-specific summary panels (Staff Dashboard, Manager Dashboard, Admin Dashboard) showing sales totals, service logs, and inventory counts.
- **Inventory_System**: The `station_inventory` table and supporting deduction logic.
- **Report_Engine**: The backend that aggregates validated transactions for Admin reports (`manager_reports.php`, `reports_operations.php`).
- **Validation_Queue**: The Manager-facing pending-transaction list (`pending_transactions.php`, `manager_fuel_transaction_validation.php`).

---

## Requirements

### Requirement 1: Immediate Staff Visibility on Encode

**User Story:** As a Staff member, I want my encoded Job Order or Merchandise transaction to appear in my Tracker/History immediately after submission, so that I can confirm the entry was recorded without waiting for Manager approval.

#### Acceptance Criteria

1. WHEN a Staff member submits a Job Order or Merchandise transaction, THE Transaction SHALL be inserted into `merchandise_transactions` (or `job_orders`) with `validation_status = 'Pending'` within the same database transaction that saves the record.
2. WHEN a Staff member views the Job Order Tracker or Merchandise History panel, THE Tracker SHALL display all transactions encoded by that Staff member for the current station, including records with `validation_status = 'Pending'`.
3. WHILE a transaction has `validation_status = 'Pending'`, THE Tracker SHALL render the transaction row with a visible "Pending Validation" status badge (amber color `#d97706`).
4. THE Transaction SHALL be visible in the Staff Tracker within 3 seconds of a successful form submission, without requiring a page reload beyond the redirect that follows the POST.
5. IF a Staff member submits a transaction and a database error occurs, THEN THE Transaction SHALL NOT be inserted, and THE System SHALL redirect the Staff member to the encode form with a descriptive error message stored in `$_SESSION['error']`.

---

### Requirement 2: Non-Blocking Staff Workflow

**User Story:** As a Staff member, I want to continue encoding new transactions even when previous entries are still pending approval, so that my work is never blocked by the Manager's review schedule.

#### Acceptance Criteria

1. THE Transaction encode form (Merchandise and Fuel) SHALL remain accessible and submittable regardless of the count of existing `Pending` transactions for that Staff member or station.
2. WHILE one or more transactions have `validation_status = 'Pending'`, THE Staff Dashboard SHALL display the pending count as an informational badge without blocking navigation or form access.
3. THE System SHALL accept a new transaction submission from a Staff member even when the previous transaction for the same product is still in `Pending` status.
4. IF a Staff member encodes a Fuel transaction and the `present_reading` is less than or equal to `previous_reading + calibration`, THEN THE System SHALL reject the submission with a validation error and SHALL NOT create a `pending` record.

---

### Requirement 3: Manager Validation Queue

**User Story:** As a Manager, I want to see all pending transactions from my station in one queue, so that I can review and approve or reject them anytime without disrupting Staff operations.

#### Acceptance Criteria

1. WHEN a Manager accesses the Validation Queue, THE Validation_Queue SHALL display all transactions from the Manager's station where `validation_status = 'Pending'` or `LOWER(status) LIKE '%pending%'`, ordered by `created_at DESC`.
2. THE Validation_Queue SHALL include both Merchandise transactions (from `merchandise_transactions`) and Job Orders (from `job_orders`) in a unified list grouped by customer name and date.
3. WHEN a Manager selects one or more transactions and clicks Approve, THE System SHALL update `validation_status = 'Approved'` and set `validated_by = manager_id` and `validated_at = NOW()` for each selected record within a single database transaction.
4. WHEN a Manager clicks Reject, THE System SHALL require a non-empty rejection reason before processing, and SHALL update `validation_status = 'Rejected'` and store the reason in `rejection_reason` (or `remarks` if the column is absent).
5. WHEN a Manager approves a Merchandise transaction, THE Inventory_System SHALL deduct the quantity of each approved line item from `station_inventory.stock_level` using `GREATEST(stock_level - qty, 0)` within the same approval database transaction.
6. WHEN a Manager approves a credit-customer Merchandise transaction, THE System SHALL update `customers.balance` and insert a record into `customer_credit_transactions` within the same approval database transaction.
7. IF a Manager attempts to approve a transaction for a customer whose `customers.status = 'locked'` or `'inactive'`, THEN THE System SHALL abort the approval, roll back the database transaction, and display a descriptive error message to the Manager.
8. WHEN a Manager approves or rejects a transaction, THE Audit_Trail SHALL record an entry containing: transaction ID, manager ID, station ID, action type (`Approve` / `Reject` / `Adjust`), and a timestamp.

---

### Requirement 4: Manager Adjustment Action

**User Story:** As a Manager, I want to adjust the quantity or price of a pending transaction before approving it, so that I can correct encoding errors without rejecting and requiring re-entry.

#### Acceptance Criteria

1. WHEN a Manager submits an adjustment on a Merchandise transaction, THE System SHALL update `merchandise_transaction_items.quantity`, `unit_price`, and `subtotal` for each adjusted line item and recalculate `merchandise_transactions.total_amount` within a single database transaction.
2. WHEN a Manager submits an adjustment on a Fuel transaction, THE System SHALL update `fuel_transactions.previous_reading`, `present_reading`, `calibration`, `liters_sold`, and `total_amount` and recompute the fuel inventory variance within the same database transaction.
3. WHEN an adjustment is saved, THE System SHALL set `validation_status = 'Adjusted'` and log the change in the Audit Trail with `action_type = 'Adjust'` and the adjustment reason.
4. IF a Manager submits an adjustment but provides no adjustment reason, THEN THE System SHALL reject the request and display an error without persisting any changes.

---

### Requirement 5: Staff Visibility of Approval/Rejection Status

**User Story:** As a Staff member, I want to see the updated status of my submitted transactions (Approved, Rejected, or Adjusted) in my Tracker, so that I know which entries were validated and which need attention.

#### Acceptance Criteria

1. WHEN a Manager approves, rejects, or adjusts a transaction, THE Tracker SHALL reflect the updated `validation_status` badge for that transaction on the Staff member's next page load or tracker refresh.
2. THE Tracker SHALL render the status badges with the following colors: `Approved` → green (`#16a34a`), `Rejected` → red (`#dc2626`), `Adjusted` → blue (`#2563eb`), `Pending` → amber (`#d97706`).
3. WHEN a transaction's `validation_status = 'Rejected'`, THE Tracker SHALL display the rejection reason alongside the status badge if `rejection_reason` or `remarks` contains a `REJECTED:` prefix.
4. THE Tracker SHALL show the `validated_at` timestamp next to the approval/rejection badge when `validated_at` is not NULL.

---

### Requirement 6: Admin Oversight and Consolidated Reports

**User Story:** As an Admin, I want to view only validated transactions in my reports and dashboards, so that sales totals, service logs, and inventory deductions reflect only approved data.

#### Acceptance Criteria

1. THE Report_Engine SHALL include only transactions where `validation_status IN ('Approved', 'Adjusted', 'Verified')` when computing sales totals, service logs, and inventory deduction summaries for Admin reports.
2. WHEN an Admin views the Dashboard, THE Dashboard SHALL display sales totals and inventory counts that reflect only validated transactions from all stations the Admin oversees.
3. THE Report_Engine SHALL support filtering by date range, station, and validation status when generating Admin reports.
4. WHEN a Manager approves a transaction, THE Dashboard totals for the Admin SHALL reflect the update within 60 seconds (next page load or auto-refresh cycle).
5. THE Report_Engine SHALL expose a combined view of Merchandise transactions and Job Orders for Admin reports, joining on `station_id` and filtering by validated status.

---

### Requirement 7: Audit Trail Completeness

**User Story:** As an Admin or Manager, I want a complete audit trail covering every step of a transaction's lifecycle, so that I can trace who encoded it, when, who approved or rejected it, and any adjustments made.

#### Acceptance Criteria

1. WHEN a Staff member encodes a transaction, THE Audit_Trail SHALL record: `action_type = 'Create'`, `entity_type = 'merchandise_transaction'` or `'fuel_transaction'`, `entity_id = transaction.id`, `user_id = staff_id`, `station_id`, and `created_at = NOW()`.
2. WHEN a Manager approves, rejects, or adjusts a transaction, THE Audit_Trail SHALL record: `action_type = 'Approve'` / `'Reject'` / `'Adjust'`, `entity_id = transaction.id`, `user_id = manager_id`, `station_id`, `new_value` (new status or adjusted amount), and `created_at = NOW()`.
3. THE Audit Trail page (`approval_history.php`) SHALL display both Staff encode events and Manager decision events for each transaction, ordered by `created_at DESC`.
4. THE Audit_Trail SHALL be append-only: THE System SHALL NOT delete or overwrite existing audit records when a transaction status changes.
5. WHERE a station has the `audit_trail` table present, THE System SHALL write audit records to both `audit_logs` and `audit_trail` tables to maintain backward compatibility.

---

### Requirement 8: Fuel Transaction Soft Flow

**User Story:** As a Staff member, I want my encoded pump readings to appear immediately in the Fuel Transaction history with a pending status, so that my shift data is recorded instantly and the Manager can validate it later.

#### Acceptance Criteria

1. WHEN a Staff member submits a Fuel transaction (pump reading), THE System SHALL insert a record into `fuel_transactions` with `status = 'pending'` and deduct `liters_sold` from `fuel_inventory.current_level` within the same database transaction.
2. WHILE a Fuel transaction has `status = 'pending'`, THE Staff Fuel History panel SHALL display the record with a "Pending Validation" badge.
3. WHEN a Manager approves a Fuel transaction, THE System SHALL update `fuel_transactions.status = 'Verified'`, set `validated_by` and `validated_at`, and insert an entry into `audit_logs`.
4. WHEN a Manager rejects a Fuel transaction, THE System SHALL update `fuel_transactions.status = 'Rejected'` and SHALL restore `fuel_inventory.current_level` by adding back `liters_sold` within the same database transaction.
5. IF a Fuel transaction's `liters_sold` exceeds `fuel_inventory.current_level` at the time of submission, THEN THE System SHALL reject the submission and display a variance alert to the Staff member without creating a transaction record.

---

### Requirement 9: Dashboard Auto-Update After Approval

**User Story:** As an Admin or Manager, I want the Dashboard summaries to automatically reflect newly approved transactions, so that I always see current sales and inventory data without manual refresh.

#### Acceptance Criteria

1. WHEN the Manager Dashboard is loaded, THE Dashboard SHALL query `merchandise_transactions` and `fuel_transactions` where `validation_status IN ('Approved', 'Adjusted', 'Verified')` or `status IN ('Verified', 'Adjusted')` for today's totals.
2. WHEN the Admin Dashboard is loaded, THE Dashboard SHALL aggregate validated transaction totals across all stations the Admin oversees, using the same status filter as Requirement 9.1.
3. THE Dashboard query for sales totals SHALL complete within 2 seconds for a dataset of up to 10,000 validated transactions per station per month.
4. WHERE real-time polling is configured, THE Dashboard SHALL refresh summary cards every 60 seconds using a lightweight AJAX endpoint that returns only aggregated totals (not full transaction rows).
