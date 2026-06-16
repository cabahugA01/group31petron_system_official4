# Requirements Document

## Introduction

This feature enhances the existing Staff Job Order Tracker within `staff_transactions_hub.php` (merchandise section) of the Petron Station Management System. The system currently supports four roles — Staff, Manager, Admin, and Superadmin — and tracks job orders across two backing tables: `job_orders` and `merchandise_transactions` (with `transaction_type` of `job_order` or `combined`).

Five missing components have been identified: an Inventory Impact Column, a Receivables Tracker with due date and overdue indicator, separated Validation Notes from Staff Remarks, a Variance Alert notification icon, and a Staff KPI Snapshot panel. These enhancements improve operational visibility and accountability for staff and managers at each station.

---

## Glossary

- **Job_Order_Tracker**: The table-based UI panel within `staff_transactions_hub.php` (merchandise section) that lists all job orders for the current station.
- **Job_Order**: A record in either the `job_orders` table or the `merchandise_transactions` table with `transaction_type` of `job_order` or `combined`.
- **Inventory_Impact**: A computed indicator showing whether stock was deducted from `station_inventory` for merchandise parts used in a job order, and the quantity deducted per product.
- **Receivables_Tracker**: The set of job orders with `payment_status` of `Pending Payment`, `Partial Payment`, or `Unpaid`, together with their due dates and overdue flags.
- **Staff_Remark**: Free-text notes entered by a staff member (role: `staff`, `cashier`, `pump_attendant`) when encoding or updating a job order.
- **Manager_Validation_Note**: Free-text notes entered by a Manager (role: `manager`, `admin`, `superadmin`) when approving, rejecting, or adjusting a job order.
- **Variance_Alert**: A system-generated flag raised when encoded quantity or amount data for a job order item is inconsistent with available stock or expected values.
- **KPI_Snapshot**: An optional summary panel visible to the currently logged-in staff member showing their encoding activity for the current calendar day.
- **Station**: A Petron station record identified by `station_id`, scoping all queries and display.
- **Overdue_Threshold**: The number of calendar days after a due date before a receivable is considered overdue (default: 0 days, i.e., overdue on and after the due date).
- **Header_Notification_Icon**: The bell or alert icon rendered in the system header/navbar area, consistent with the existing UI pattern.

---

## Requirements

### Requirement 1: Inventory Impact Column

**User Story:** As a Staff member, I want to see whether stock was deducted for each merchandise part in a job order, so that I can confirm inventory changes were applied correctly after Manager approval.

#### Acceptance Criteria

1. THE Job_Order_Tracker SHALL display an "Inventory Impact" column alongside existing columns (JO ID, Customer, Vehicle/Service, Items/Parts, Mechanic, Status, Payment, Remarks, Date, Actions).
2. WHEN a job order contains one or more merchandise parts linked to `merchandise_transaction_items`, THE Inventory_Impact column SHALL display each part name, quantity, and a deduction status label (e.g., "Tire Valve Steel (1 pc) → Deducted: Yes").
3. WHEN a job order's `validation_status` is `Approved` and a corresponding stock deduction exists in `station_inventory` for the part, THE Inventory_Impact column SHALL display "Deducted: Yes" for that part.
4. WHEN a job order's `validation_status` is not `Approved` or no stock deduction has been recorded, THE Inventory_Impact column SHALL display "Deducted: No" for that part.
5. WHEN a job order has no merchandise parts (service-only), THE Inventory_Impact column SHALL display "—" for that row.
6. IF a `station_inventory` record for a given `product_id` and `station_id` cannot be found, THEN THE Inventory_Impact column SHALL display "Deducted: N/A" for that part.

---

### Requirement 2: Receivables Tracker

**User Story:** As a Staff member, I want to see due dates and overdue indicators for unsettled job order payments, so that I can proactively follow up on outstanding balances.

#### Acceptance Criteria

1. THE Job_Order_Tracker SHALL display a "Due Date" sub-field within or adjacent to the Payment column for each job order with `payment_status` of `Pending Payment`, `Partial Payment`, or `Unpaid`.
2. WHEN a due date is set for a job order, THE Receivables_Tracker SHALL display the due date in `YYYY-MM-DD` format next to the payment status.
3. WHEN the current server date is on or after a job order's due date and the `payment_status` is not `Paid`, THE Receivables_Tracker SHALL render an "Overdue" indicator (distinct red label or icon) on that row.
4. WHEN the current server date is before a job order's due date, THE Receivables_Tracker SHALL render no overdue indicator on that row.
5. THE Job_Order_Tracker SHALL allow a Staff member to set or update the due date for a job order through an inline edit control on the tracker row.
6. IF a job order has `payment_status` of `Paid` or `Completed`, THEN THE Receivables_Tracker SHALL not display an overdue indicator for that row.
7. THE Receivables_Tracker SHALL display the running balance due (total amount minus amount paid) alongside the due date for each unsettled row.

---

### Requirement 3: Validation Notes Separation

**User Story:** As a Staff member and as a Manager, I want Staff remarks and Manager validation notes to be stored and displayed separately, so that there is a clear audit trail distinguishing operational notes from approval decisions.

#### Acceptance Criteria

1. THE Job_Order_Tracker SHALL display a "Staff Remarks" sub-field and a "Manager Notes" sub-field as two distinct, labelled sections within the Remarks column for each job order.
2. WHEN a Staff member saves a remark on a job order, THE System SHALL store the remark in a designated staff remarks field (e.g., `staff_remarks` or equivalent column) without overwriting Manager validation notes.
3. WHEN a Manager approves, rejects, or adjusts a job order, THE System SHALL store the Manager's note in a designated manager notes field (e.g., `admin_remarks` or equivalent column) without overwriting Staff remarks.
4. WHEN both a Staff remark and a Manager note exist for a job order, THE Job_Order_Tracker SHALL display both, labelled as "Staff:" and "Manager:" respectively.
5. WHEN only a Staff remark exists, THE Job_Order_Tracker SHALL display only the Staff remark, labelled "Staff:", and leave the Manager Notes sub-field blank.
6. WHEN only a Manager note exists, THE Job_Order_Tracker SHALL display only the Manager note, labelled "Manager:", and leave the Staff Remarks sub-field blank.
7. WHEN neither remark nor note exists, THE Job_Order_Tracker SHALL display "—" in the Remarks column.
8. THE System SHALL restrict writing to the manager notes field to users with roles `manager`, `admin`, or `superadmin`.
9. THE System SHALL restrict writing to the staff remarks field to users with roles `staff`, `cashier`, or `pump_attendant`.

---

### Requirement 4: Variance Alerts Notification

**User Story:** As a Staff member, I want to see a notification icon in the system header when there are quantity or amount mismatches in encoded job order data, so that I can detect and resolve data errors before Manager validation.

#### Acceptance Criteria

1. THE System SHALL evaluate each active (non-rejected, non-completed) job order for the current station when the page is loaded to detect quantity or amount variances.
2. WHEN the encoded quantity of a merchandise part in a job order exceeds the available `stock_level` in `station_inventory` for that `product_id` and `station_id`, THE System SHALL flag that job order with a variance alert.
3. WHEN the `total_amount` of a job order differs from the sum of `(quantity × unit_price)` across all its `merchandise_transaction_items` by more than ₱0.01, THE System SHALL flag that job order with a variance alert.
4. WHEN one or more variance alerts exist for the current station, THE Header_Notification_Icon SHALL display a visible badge showing the count of flagged job orders.
5. WHEN no variance alerts exist, THE Header_Notification_Icon SHALL not display a badge.
6. WHEN a Staff member clicks the Header_Notification_Icon, THE System SHALL display a summary list of flagged job orders with the variance type (e.g., "Quantity mismatch: Tire Valve Steel") and the JO reference.
7. WHEN a flagged job order is resolved (variance condition no longer holds), THE System SHALL remove the flag from the badge count on the next page load.
8. IF a `station_inventory` record does not exist for a part, THEN THE System SHALL not raise a stock-quantity variance alert for that part.

---

### Requirement 5: Staff KPI Snapshot

**User Story:** As a Staff member, I want an optional panel showing my encoding performance for the current day, so that I can track my productivity during a shift.

#### Acceptance Criteria

1. WHERE the KPI Snapshot feature is enabled (i.e., the panel is toggled visible by the staff member), THE KPI_Snapshot panel SHALL be displayed within the Staff Job Order Tracker page.
2. THE KPI_Snapshot panel SHALL display the following metrics scoped to the currently logged-in staff member and the current calendar day (server date): (a) total job orders encoded, (b) total merchandise items released (sum of quantities across all merchandise parts in job orders), (c) total amount encoded (sum of `total_amount` across all job orders encoded today).
3. WHEN the Staff member clicks a "Show KPI" toggle, THE KPI_Snapshot panel SHALL become visible.
4. WHEN the Staff member clicks the same toggle again, THE KPI_Snapshot panel SHALL become hidden.
5. THE KPI_Snapshot panel SHALL source data only from job orders and merchandise transactions where `staff_id` or `created_by` matches the current user's `id` and `station_id` matches the current station.
6. THE KPI_Snapshot panel SHALL refresh its values on each full page load or manual refresh; real-time auto-refresh is not required.
7. IF no job orders have been encoded by the staff member for the current day, THEN THE KPI_Snapshot panel SHALL display zero for all three metrics without showing an error.
