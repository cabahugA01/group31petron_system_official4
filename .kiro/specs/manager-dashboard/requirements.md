# Requirements Document

## Introduction

The Manager Dashboard is a comprehensive operational command center for the Petron Station Management System, implemented in `public/manager_dashboard.php`. It expands the existing basic summary counters into a full-featured oversight hub that gives the station manager real-time visibility across all operational domains: job orders, fuel inventory, sales performance, staff attendance, deliveries, customer balances, and staff performance.

The dashboard is organized into three vertical sections:

1. **Top Section** — Summary counters and analytical charts (job order distribution, sales trend, payment breakdown, validation trend).
2. **Middle Section** — Operational oversight panels (job orders table with manager actions, fuel monitoring with gauges and variance, staff clock-in/out logs).
3. **Bottom Section** — Additional analytics (deliveries summary, customer balance overview, staff performance chart).

All data is scoped to the authenticated manager's `station_id`. The dashboard draws from the `job_orders`, `fuel_transactions`, `fuel_inventory`, `merchandise_transactions`, `staff_logs` / `labor_sessions`, `deliveries_oversight`, `fuel_deliveries`, `station_inventory`, `inventory_products`, `customers`, and `users` tables.

---

## Glossary

- **Dashboard**: The `public/manager_dashboard.php` page that renders all sections and widgets for an authenticated manager.
- **Manager**: An authenticated user whose `role_key` resolves to `manager`, `admin`, or `superadmin`.
- **Station_ID**: The `station_id` value bound to the authenticated user's session, used to scope all database queries.
- **Summary_Counter**: A KPI card in the top section displaying a single numeric metric with a label and icon.
- **Job_Order**: A row in the `job_orders` table representing a vehicle service request created by staff.
- **Validation_Status**: The `validation_status` column on `job_orders` — one of: `Pending Validation`, `Approved`, `Rejected`, `Adjusted`.
- **Job_Order_Status**: The `status` column on `job_orders` — one of: `Pending Validation`, `Pending`, `In Progress`, `Completed`, `Rejected`, `Cancelled`.
- **Fuel_Transaction**: A row in the `fuel_transactions` table representing a fuel dispensing event with `liters_sold`, `total_amount`, `fuel_type`, and `payment_method`.
- **Merchandise_Transaction**: A row in the `merchandise_transactions` table representing a completed POS sale.
- **Fuel_Inventory**: A row in the `fuel_inventory` table tracking `current_stock`, `capacity`, `price_per_liter`, and `fuel_type` per station.
- **Tank_Level**: The `current_stock` value in `fuel_inventory` for a given fuel type, expressed in litres.
- **Variance**: The absolute difference between the pump reading delta (`present_reading − previous_reading`) and `liters_sold` for a Fuel_Transaction. A Variance ≥ 2 litres triggers an alert.
- **Low_Stock_Alert**: A condition where a fuel type's `current_stock` ≤ 2 000 L (warning) or ≤ 500 L (critical), or a merchandise item's `stock_level` ≤ `reorder_level`.
- **Staff_Log**: A row in `staff_logs` (or `labor_sessions`) recording a staff member's clock-in and clock-out times for a shift.
- **Delivery**: A row in `deliveries_oversight` or `fuel_deliveries` representing an incoming stock or fuel delivery.
- **Customer_Balance**: The outstanding receivable amount owed by a credit customer, derived from unpaid `job_orders` or `merchandise_transactions` with `payment_method = 'Credit'`.
- **Credit_Limit**: The maximum credit amount allowed for a customer, stored in the `customers` table.
- **Staff_Performance**: A metric computed per staff member representing the number of completed transactions or job orders within a selected time range.
- **Payment_Method**: The payment channel recorded on a transaction — one of: Cash, Card (Credit Card), E-Wallet, E-Fuel Card, or Credit (Account Receivable).
- **Validation_Trend**: A time-series dataset showing the daily count of approved and rejected job orders over the past 7 days.
- **Sales_Trend**: A time-series dataset showing daily fuel and merchandise revenue over the past 30 days.
- **Selected_Range**: The time window applied to trend charts — one of `today`, `week`, or `month`.
- **Gauge_Chart**: A semi-circular chart rendered via Chart.js displaying a tank's current fill level as a percentage of its capacity.
- **Variance_Chart**: A bar chart comparing pump-recorded dispensed litres against tank-recorded deductions per fuel type.

---

## Requirements

### Requirement 1: Dashboard Access Control

**User Story:** As a manager, I want the dashboard to be accessible only to authorised roles, so that sensitive operational and financial data is not exposed to staff or unauthenticated users.

#### Acceptance Criteria

1. WHEN an unauthenticated user requests `manager_dashboard.php`, THE Dashboard SHALL redirect the user to the login page via `require_login()`.
2. WHEN an authenticated user whose `role_key` is not `manager`, `admin`, or `superadmin` requests `manager_dashboard.php`, THE Dashboard SHALL redirect the user to `dashboard.php` with the session error "Access denied. Manager privileges required."
3. WHEN an authenticated manager has no `station_id` assigned, THE Dashboard SHALL terminate with the message "Error: You are not assigned to a station."
4. THE Dashboard SHALL scope every database query with `station_id = ?` bound to the authenticated user's `station_id` to prevent cross-station data access.

---

### Requirement 2: Summary Counters (Top Section)

**User Story:** As a manager, I want to see at-a-glance KPI counters for job orders by status, so that I can immediately identify how many orders need my attention.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `job_orders` filtered by `station_id` and display six Summary_Counters:
   - **Total Job Orders**: `COUNT(*)` regardless of status.
   - **Pending Validations**: `COUNT(*) WHERE status = 'Pending Validation' OR validation_status = 'Pending Validation'`.
   - **Approved / Validated**: `COUNT(*) WHERE status IN ('Approved', 'Validated') OR validation_status = 'Approved'`.
   - **In Progress**: `COUNT(*) WHERE status = 'In Progress'`.
   - **Completed**: `COUNT(*) WHERE status = 'Completed'`.
   - **Rejected**: `COUNT(*) WHERE status IN ('Rejected', 'Cancelled')`.
2. THE Dashboard SHALL render each Summary_Counter as a card with a numeric value, a descriptive label, a colour-coded border or background, and a Font Awesome icon.
3. WHEN a Summary_Counter card is clicked, THE Dashboard SHALL navigate to the job orders table filtered to that status.
4. THE Dashboard SHALL also display the following operational KPI counters in the same top section:
   - **Today's Total Sales** (fuel + merchandise combined, in ₱).
   - **Staff Clocked In** (count of staff with an active session today).
   - **Low Stock Alerts** (count of items below reorder level).
   - **Pending Deliveries** (count of deliveries with status `Pending Validation` or `Pending Manager Approval`).
5. IF a database query for any counter fails, THE Dashboard SHALL display `0` for that counter and log the error without crashing the page.

---

### Requirement 3: Job Orders Distribution Chart

**User Story:** As a manager, I want a pie chart showing the distribution of job orders by status, so that I can visually assess the workload balance at a glance.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL render a pie chart (using Chart.js) in the top section showing the proportion of job orders per status: Pending Validation, Approved/Validated, In Progress, Completed, and Rejected.
2. THE Dashboard SHALL use the same status counts computed for Requirement 2 as the data source for the chart — no additional database query is required.
3. THE Dashboard SHALL assign a distinct colour to each status slice: Pending = amber, Approved = blue, In Progress = orange, Completed = green, Rejected = red.
4. THE Dashboard SHALL render a legend beneath or beside the chart mapping each colour to its status label and count.
5. WHEN all status counts are zero, THE Dashboard SHALL render the chart with a single grey "No Data" slice and display the message "No job orders recorded yet."

---

### Requirement 4: Sales Trend Chart

**User Story:** As a manager, I want a line or bar chart showing daily fuel and merchandise sales over the past 30 days, so that I can identify revenue trends and anomalies.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `fuel_transactions` and `merchandise_transactions` filtered by `station_id` and `DATE(transaction_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)`, grouping results by `DATE(transaction_date)`.
2. THE Dashboard SHALL render a combined line chart (using Chart.js) with two datasets: one for daily fuel revenue (`SUM(total_amount)` from `fuel_transactions`) and one for daily merchandise revenue (`SUM(total_amount)` from `merchandise_transactions`).
3. THE Dashboard SHALL use the date as the X-axis label (formatted as `M j`, e.g., "Jun 5") and revenue in ₱ as the Y-axis.
4. THE Dashboard SHALL colour the fuel dataset in blue and the merchandise dataset in green.
5. WHEN a date has no transactions, THE Dashboard SHALL plot that date with a value of `0` to maintain a continuous X-axis.
6. IF fewer than 2 days of data exist, THE Dashboard SHALL display the message "Not enough data to display a trend."

---

### Requirement 5: Payment Breakdown Chart

**User Story:** As a manager, I want a pie chart showing the breakdown of all sales by payment method, so that I can understand the payment channel mix for reconciliation.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `fuel_transactions` and `merchandise_transactions` filtered by `station_id` and `DATE(transaction_date) = CURDATE()`, computing `SUM(total_amount)` grouped by `payment_method`.
2. THE Dashboard SHALL aggregate payment methods into five canonical buckets:
   - **Cash**: `payment_method IN ('Cash', 'cash')`
   - **Card**: `payment_method IN ('Credit Card', 'Card', 'card', 'Debit Card')`
   - **E-Wallet**: `payment_method IN ('E-Wallet', 'GCash', 'Maya', 'ewallet')`
   - **E-Fuel Card**: `payment_method IN ('E-Fuel Card', 'Fuel Card', 'efuel')`
   - **Credit**: `payment_method IN ('Credit', 'Account Receivable', 'utang', 'Utang')`
3. THE Dashboard SHALL render a pie chart (using Chart.js) with one slice per payment bucket, labelled with the bucket name and total amount.
4. THE Dashboard SHALL display a summary table beneath the chart listing each payment method, its total amount, and its percentage of the day's total.
5. WHEN a payment bucket has a total of ₱0, THE Dashboard SHALL still include it in the table with a value of ₱0.00 but MAY omit it from the chart to avoid zero-value slices.

---

### Requirement 6: Validation Trend Chart

**User Story:** As a manager, I want a line chart showing daily approved and rejected job orders over the past 7 days, so that I can monitor my validation throughput and identify backlogs.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `job_orders` filtered by `station_id` and `DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)`, grouping by `DATE(created_at)`.
2. THE Dashboard SHALL render a line chart (using Chart.js) with two datasets: daily approved count (`status IN ('Approved', 'Validated')`) and daily rejected count (`status = 'Rejected'`).
3. THE Dashboard SHALL use the date as the X-axis label (formatted as `M j`) and count as the Y-axis.
4. THE Dashboard SHALL colour the approved dataset in green and the rejected dataset in red.
5. WHEN a date has no approvals or rejections, THE Dashboard SHALL plot that date with a value of `0`.

---

### Requirement 7: Job Orders Table (Middle Section)

**User Story:** As a manager, I want a paginated, filterable table of all job orders with inline Approve and Reject actions, so that I can validate staff-submitted job orders without leaving the dashboard.

#### Acceptance Criteria

1. WHEN the Dashboard loads with `?view=job_orders` (or as the default middle-section tab), THE Dashboard SHALL query `job_orders` joined with `users` (staff name) and `customers` (customer name), filtered by `station_id`, and display results in a table.
2. THE Dashboard SHALL render the following columns: Job ID, Customer Name, Vehicle Plate, Service Type, Technician (assigned mechanic name), Status, Credit (payment method = Credit indicator), and Created At.
3. THE Dashboard SHALL render an Actions column with three buttons per row: **View** (opens a detail modal), **Approve** (submits `action=approve_reject_job_order` with `approval_action=approve`), and **Reject** (submits `action=approve_reject_job_order` with `approval_action=reject`).
4. THE Dashboard SHALL show the Approve and Reject buttons only for job orders whose `validation_status = 'Pending Validation'`; for all other statuses, THE Dashboard SHALL show only the View button.
5. THE Dashboard SHALL support filtering by status via a dropdown (`?status=`) and by customer name via a text search input (`?customer=`).
6. THE Dashboard SHALL paginate results at 20 rows per page using `LIMIT` and `OFFSET` with `?page=` query parameter.
7. WHEN the manager clicks Approve, THE Dashboard SHALL set `validation_status = 'Approved'` and `status = 'Pending'` on the job order, insert an audit row into `job_order_audit`, call `log_activity()`, and redirect back with a success flash message.
8. WHEN the manager clicks Reject, THE Dashboard SHALL set `validation_status = 'Rejected'` and `status = 'Cancelled'` on the job order, insert an audit row into `job_order_audit`, call `log_activity()`, and redirect back with a success flash message.
9. IF the job order is already `In Progress` or `Completed`, THE Dashboard SHALL reject the approve/reject action with the error "Cannot modify a job order that is already In Progress or Completed."
10. THE Dashboard SHALL display the total count of job orders matching the current filter above the table.

---

### Requirement 8: Fuel Monitoring Panel (Middle Section)

**User Story:** As a manager, I want to see gauge charts for current tank levels, a variance chart comparing pump vs. tank readings, and low-stock alerts, so that I can monitor fuel inventory health and investigate discrepancies.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `fuel_inventory` filtered by `station_id` and render one Gauge_Chart per fuel type showing `current_stock / capacity * 100` as the fill percentage.
2. THE Dashboard SHALL label each gauge with the fuel type name, the exact litres available (e.g., "4 250 L"), and the capacity (e.g., "/ 10 000 L").
3. WHEN a fuel type's `current_stock` ≤ 500 L, THE Dashboard SHALL colour the gauge red and display a "🔴 Critical" badge.
4. WHEN a fuel type's `current_stock` is between 501 L and 2 000 L, THE Dashboard SHALL colour the gauge amber and display a "🟡 Low Stock" badge.
5. WHEN a fuel type's `current_stock` > 2 000 L, THE Dashboard SHALL colour the gauge green and display a "✅ Normal" badge.
6. THE Dashboard SHALL render a Variance_Chart (bar chart using Chart.js) comparing, per fuel type: the sum of `liters_sold` from `fuel_transactions` for today vs. the sum of tank deductions recorded in `fuel_adjustments` for today.
7. WHEN the absolute difference between pump-recorded litres and tank-recorded deductions exceeds 2 L for any fuel type, THE Dashboard SHALL display a Low_Stock_Alert banner above the Variance_Chart with the message "⚠️ Variance detected — {N} fuel type(s) require review."
8. THE Dashboard SHALL fetch Low_Stock_Alerts for merchandise from `station_inventory` (or `inventory_products`) where `stock_level <= reorder_level` and display them as a list of item names and current stock levels below the fuel gauges.
9. IF no fuel inventory data exists for the station, THE Dashboard SHALL display "No fuel inventory data available."

---

### Requirement 9: Staff Clock-in / Clock-out Logs (Middle Section)

**User Story:** As a manager, I want to see a real-time attendance log showing which staff members are currently clocked in and their shift history for today, so that I can monitor attendance without accessing a separate module.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `staff_logs` (or `labor_sessions`) filtered by `station_id` and `DATE(clock_in_time) = CURDATE()` (or `DATE(start_time) = CURDATE()`) and display a table of today's attendance records.
2. THE Dashboard SHALL render the following columns: Staff Name, Role, Clock-In Time, Clock-Out Time (or "Active" if `clock_out_time IS NULL`), and Duration (computed as `TIMESTAMPDIFF(MINUTE, clock_in_time, COALESCE(clock_out_time, NOW())) / 60` hours).
3. THE Dashboard SHALL display a summary row at the top of the panel showing: total staff clocked in today, total staff currently active (no clock-out), and total staff clocked out.
4. WHEN a staff member is currently clocked in (no clock-out), THE Dashboard SHALL highlight that row with a green "Active" badge.
5. WHEN a staff member has clocked out, THE Dashboard SHALL display the clock-out time and computed duration.
6. THE Dashboard SHALL sort the attendance table with currently active staff first, then by clock-in time descending.
7. IF no staff have clocked in today, THE Dashboard SHALL display "No attendance records for today."
8. THE Dashboard SHALL NOT provide clock-in or clock-out controls on the Manager Dashboard; attendance management is performed by staff on their own dashboard.

---

### Requirement 10: Deliveries Summary (Bottom Section)

**User Story:** As a manager, I want to see delivery totals by status and a trend chart, so that I can track incoming stock and fuel deliveries without navigating to a separate module.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `deliveries_oversight` filtered by `station_id` and display three summary cards: **Pending** (status IN `Pending Validation`, `Pending Manager Approval`), **Approved / Validated** (status IN `Validated`, `Confirmed`), and **Rejected / Flagged** (status IN `Rejected`, `Flagged`, `Discrepancy`).
2. THE Dashboard SHALL also query `fuel_deliveries` filtered by `station_id` and include fuel delivery counts in the same three status cards.
3. THE Dashboard SHALL render a line chart (using Chart.js) showing the daily count of deliveries (all types combined) over the past 14 days, grouped by `DATE(delivery_date)`.
4. THE Dashboard SHALL colour the Pending card amber, the Approved card green, and the Rejected card red.
5. WHEN a delivery card is clicked, THE Dashboard SHALL navigate to the relevant deliveries management page filtered by that status.
6. IF no delivery data exists, THE Dashboard SHALL display "No delivery records found" and render an empty chart.

---

### Requirement 11: Customer Balances Overview (Bottom Section)

**User Story:** As a manager, I want a bar chart showing outstanding customer balances versus their credit limits, so that I can identify customers who are near or over their credit limit.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `customers` joined with unpaid `job_orders` and `merchandise_transactions` (where `payment_method = 'Credit'` and `payment_status != 'Paid'`) filtered by `station_id`, computing the outstanding balance per customer as `SUM(total_amount - COALESCE(amount_paid, 0))`.
2. THE Dashboard SHALL render a horizontal bar chart (using Chart.js) with one bar per customer showing their outstanding balance, overlaid with a marker or second bar for their `credit_limit`.
3. THE Dashboard SHALL display a maximum of 10 customers on the chart, ordered by outstanding balance descending.
4. WHEN a customer's outstanding balance ≥ 90% of their `credit_limit`, THE Dashboard SHALL colour that customer's bar red.
5. WHEN a customer's outstanding balance is between 50% and 89% of their `credit_limit`, THE Dashboard SHALL colour that customer's bar amber.
6. WHEN a customer's outstanding balance is < 50% of their `credit_limit`, THE Dashboard SHALL colour that customer's bar green.
7. THE Dashboard SHALL display a summary table beneath the chart listing: Customer Name, Outstanding Balance, Credit Limit, and Utilisation % for all customers with a balance > ₱0.
8. IF no customers have outstanding balances, THE Dashboard SHALL display "No outstanding customer balances."

---

### Requirement 12: Staff Performance Chart (Bottom Section)

**User Story:** As a manager, I want a bar chart showing the number of completed transactions and job orders per staff member, so that I can assess individual productivity.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `merchandise_transactions` and `job_orders` filtered by `station_id` and the current month (`YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())`), grouping by `created_by` (or `staff_id`) joined with `users.name`.
2. THE Dashboard SHALL compute per staff member: count of completed merchandise transactions (`merchandise_transactions WHERE validation_status = 'Approved'`) and count of completed job orders (`job_orders WHERE status = 'Completed'`).
3. THE Dashboard SHALL render a grouped bar chart (using Chart.js) with one group per staff member, showing two bars: one for transactions and one for job orders.
4. THE Dashboard SHALL display a maximum of 10 staff members on the chart, ordered by total activity (transactions + job orders) descending.
5. THE Dashboard SHALL colour the transactions bar blue and the job orders bar green.
6. THE Dashboard SHALL display a summary table beneath the chart listing: Staff Name, Transactions Count, Job Orders Count, and Total Activity for all staff with at least one record.
7. IF no staff performance data exists for the current month, THE Dashboard SHALL display "No performance data for this month."

---

### Requirement 13: Manager Job Order Actions (Approve / Reject)

**User Story:** As a manager, I want to approve or reject job orders directly from the dashboard with required remarks, so that staff can proceed with or correct their work orders without delay.

#### Acceptance Criteria

1. WHEN the manager submits an approve action for a job order, THE Dashboard SHALL require a non-empty `remarks` field; IF `remarks` is empty, THE Dashboard SHALL return the error "Remarks are required for approval."
2. WHEN the manager submits a reject action for a job order, THE Dashboard SHALL require a non-empty `remarks` field; IF `remarks` is empty, THE Dashboard SHALL return the error "Rejection reason is required."
3. WHEN an approve action succeeds, THE Dashboard SHALL set `validation_status = 'Approved'`, `status = 'Pending'`, `validated_by = {manager_id}`, and `validated_at = NOW()` on the `job_orders` row.
4. WHEN a reject action succeeds, THE Dashboard SHALL set `validation_status = 'Rejected'`, `status = 'Cancelled'`, `validated_by = {manager_id}`, and `validated_at = NOW()` on the `job_orders` row.
5. THE Dashboard SHALL insert a row into `job_order_audit` for every approve or reject action, recording: `job_order_id`, `action` (`APPROVE` or `REJECT`), `before_status`, `after_status`, `performed_by`, `performed_at`, `notes` (remarks), `ip_address`, and `user_agent`.
6. THE Dashboard SHALL call `log_activity($pdo, $me['id'], 'JOB_ORDER_APPROVED' | 'JOB_ORDER_REJECTED', ...)` after every successful action.
7. WHEN an approve or reject action completes, THE Dashboard SHALL redirect to `manager_dashboard.php?view=job_orders` with a session flash message confirming the action.
8. IF a database error occurs during an approve or reject action, THE Dashboard SHALL roll back the transaction and display the error message without corrupting the job order record.

---

### Requirement 14: AJAX Data Refresh

**User Story:** As a manager, I want the dashboard KPI counters and charts to refresh periodically without a full page reload, so that I always see current operational data during my shift.

#### Acceptance Criteria

1. WHEN the Dashboard receives a request with `?refresh=1`, THE Dashboard SHALL return a JSON response containing: all six job order status counts, `today_sales`, `staff_clocked_in`, `low_stock_alerts`, `pending_deliveries`, fuel tank levels per fuel type, and pending delivery count.
2. WHEN the Dashboard receives a request with `?refresh_charts=1`, THE Dashboard SHALL return a JSON response containing: job order distribution data, payment breakdown data, sales trend data (last 30 days), and validation trend data (last 7 days).
3. IF an exception occurs during an AJAX refresh query, THE Dashboard SHALL return `{"success": false, "error": "<sanitised message>"}` with HTTP 200.
4. THE Dashboard SHALL output the JSON response and call `exit` before rendering any HTML when handling an AJAX refresh request.
5. THE Dashboard SHALL set the `Content-Type: application/json` header before outputting any AJAX response.

---

### Requirement 15: Session Flash Messages

**User Story:** As a manager, I want to see success and error messages after performing actions on the dashboard, so that I know whether my approvals, rejections, or adjustments succeeded.

#### Acceptance Criteria

1. WHEN any POST action (approve, reject, adjust, stock request) completes successfully, THE Dashboard SHALL set a success message in `$_SESSION['success']` and display it on the next page load.
2. WHEN any POST action fails, THE Dashboard SHALL set an error message in `$_SESSION['error']` and display it on the next page load.
3. WHEN a flash message is displayed, THE Dashboard SHALL unset the corresponding session key so the message does not persist across subsequent page loads.
4. THE Dashboard SHALL render flash messages in a visible, dismissible alert element above the main dashboard content.
5. THE Dashboard SHALL sanitise all flash message content using `htmlspecialchars()` before rendering to prevent XSS.

---

### Requirement 16: Merchandise Product Overview Panel

**User Story:** As a manager, I want a read-only overview panel of all merchandise products grouped by category with stock status indicators, so that I can monitor merchandise inventory health directly from the dashboard without navigating to a separate page.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `inventory_products` filtered by `category NOT IN ('Fuel')` and render a Merchandise Product Overview panel in the Middle or Bottom section displaying all non-fuel products.
2. THE Dashboard SHALL group products by category and render them in the following order: Oils / Lubes / Grease, Car Accessories, Brake System, Tire, Maintenance, Oil / Fuel Filters, Others — with any unlisted categories appended after.
3. THE Dashboard SHALL render the following columns for each product row: Product Name, SKU, Category, Stock, Status, Cost, and Price (with profit margin shown as `price − cost`).
4. WHEN a product's `stock` ≤ 0, THE Dashboard SHALL display the status badge "OUT OF STOCK" in red.
5. WHEN a product's `stock` > 0 AND `stock` ≤ `reorder_level` (defaulting to 10 if not set), THE Dashboard SHALL display the status badge "LOW STOCK" in amber/orange.
6. WHEN a product's `stock` > `reorder_level`, THE Dashboard SHALL display the status badge "AVAILABLE" in green.
7. THE Dashboard SHALL display four summary counters above the product table: **Total Products** (COUNT of all non-fuel products), **Available** (count with status AVAILABLE), **Low Stock** (count with status LOW STOCK), and **Out of Stock** (count with status OUT OF STOCK).
8. THE Dashboard SHALL provide a text search input that filters the product table client-side by product name as the user types.
9. THE Dashboard SHALL render the panel as read-only; THE Dashboard SHALL NOT provide controls to edit product prices, costs, or stock levels — those operations are reserved for admin roles.
10. THE Dashboard SHALL use the Low Stock and Out of Stock counts from this panel as the source for the **Low Stock Alerts** counter in Requirement 2, Acceptance Criterion 4, replacing or supplementing the `station_inventory` source so that merchandise items from `inventory_products` are included in the alert count.
11. WHEN the AJAX refresh endpoint (`?refresh=1`) is called per Requirement 14, THE Dashboard SHALL include `merchandise_low_stock_count` and `merchandise_out_of_stock_count` in the JSON response.
12. IF no non-fuel products exist in `inventory_products`, THE Dashboard SHALL display "No merchandise data available." inside the panel.
13. IF the database query for merchandise products fails, THE Dashboard SHALL display an inline error message within the panel and log the error, without crashing the rest of the dashboard.
