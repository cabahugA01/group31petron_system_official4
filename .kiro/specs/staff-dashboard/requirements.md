# Requirements Document

## Introduction

The Staff Dashboard (Final Dynamic Layout) provides authenticated staff members with a single-page operational hub for the fuel station management system. The dashboard consolidates seven functional widgets: a Sales Summary with payment-method breakdown, a Job Orders Status snapshot, a Fuel & Stock Monitoring panel, a Clock-in / Clock-out shift tracker, a Quick Actions menu, a Calendar Widget showing today's and upcoming tasks, and a Reports Shortcut panel. All data is fetched dynamically from the live database — no static or hardcoded values are permitted. Staff have read-only access to fuel and stock data; calibration and inventory adjustments remain restricted to managers. The dashboard is implemented in `public/staff_dashboard.php` and draws from the `fuel_transactions`, `merchandise_transactions`, `job_orders`, `fuel_inventory`, `station_inventory`, `labor_sessions`, `deliveries_oversight`, and `attendance_logs` tables.

---

## Glossary

- **Dashboard**: The `public/staff_dashboard.php` page that renders all seven widgets for an authenticated staff user.
- **Staff**: An authenticated user whose `role_key` resolves to `staff`, `cashier`, or `pump_attendant`.
- **Station_ID**: The `station_id` value bound to the authenticated user's session, used to scope all database queries.
- **Sales_Summary**: The widget displaying merchandise revenue broken down by payment method and fuel revenue broken down by fuel type.
- **Merchandise_Transaction**: A row in the `merchandise_transactions` table representing a completed POS sale.
- **Fuel_Transaction**: A row in the `fuel_transactions` table representing a completed fuel dispensing event, including `present_reading`, `previous_reading`, `liters_sold`, and `total_amount`.
- **Payment_Method**: The payment channel recorded on a transaction — one of: Cash, Card (Credit Card), E-Wallet (GCash / Maya), E-Fuel Card, or Credit (Account Receivable / Utang).
- **Job_Order**: A row in the `job_orders` table representing a vehicle service request.
- **Fuel_Inventory**: A row in the `fuel_inventory` table tracking `current_stock` (or `current_level`) and `capacity` per fuel type per station.
- **Station_Inventory**: A row in the `station_inventory` table tracking `stock_level` and `reorder_level` for merchandise items.
- **Pump_Reading**: A value recorded in `fuel_transactions` as `present_reading` or `previous_reading`, representing the odometer-style counter on a physical fuel pump.
- **Exact_Liters_Available**: The current tank stock for a fuel type, computed from `fuel_inventory.current_level` (or `current_stock`) and cross-referenced against cumulative `fuel_transactions.liters_sold` for reconciliation.
- **Variance**: The absolute difference between the pump reading delta (`present_reading − previous_reading`) and `liters_sold` for a Fuel_Transaction. A Variance ≥ 2 litres triggers an alert.
- **Variance_Alert**: A banner or notification displayed inside the Fuel Monitoring widget when a Variance condition is detected for any fuel type today.
- **Fuel_Low_Stock**: A condition where a fuel type's `current_stock` falls below a configurable threshold (default: 2 000 L warning, 500 L critical). This is NOT a separate widget — it is an integrated condition check inside the Fuel Monitoring widget.
- **Labor_Session**: A row in the `labor_sessions` table representing one clock-in / clock-out event for a staff user.
- **Shift_Period**: A row in the `shift_periods` table defining a named shift window (e.g., First Shift: 6:00 AM – 2:00 PM).
- **Quick_Action**: A button or link on the dashboard that navigates the staff member directly to a specific system module.
- **Calendar_Widget**: The dashboard panel that lists today's and upcoming (next 3 days) Job_Orders and deliveries from `deliveries_oversight`.
- **Report_Shortcut**: A link on the dashboard that navigates to a pre-filtered report page.
- **Low_Stock_Alert**: A condition where a merchandise item's `stock_level` ≤ `reorder_level` in `station_inventory`. Merchandise low stock alerts have been removed from the dashboard; fuel low stock conditions are integrated into the Fuel Monitoring widget (Requirement 6).
- **Selected_Range**: The time window chosen by the staff member — one of `today`, `week`, or `month` — applied to all sales and fuel queries.
- **Welcome_Greeting**: A personalised header message displayed immediately after login, showing the authenticated user's full name and station name.

---

## Requirements

### Requirement 0: Welcome Greeting

**User Story:** As a staff member, I want to see a personalised welcome message when I open the dashboard, so that I know I am logged in as the correct user at the correct station.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL display the message "Welcome back, {full_name}!" where `{full_name}` is the authenticated user's `full_name` (or `first_name last_name`) from the `users` table.
2. THE Dashboard SHALL display the station name and station number beneath the greeting (e.g., "Station #1253 — Carmen, Cagayan de Oro").
3. THE Dashboard SHALL read the user's name from `$me['full_name']` (or equivalent field) returned by `current_user()` — never from raw `$_SESSION` without sanitisation.
4. THE Welcome_Greeting SHALL be rendered using `htmlspecialchars()` to prevent XSS.
5. THE Welcome_Greeting SHALL be visible above the range selector and all widgets on every page load.

---

### Requirement 1: Dashboard Access Control

**User Story:** As a staff member, I want the dashboard to be accessible only to authorised roles, so that sensitive operational data is not exposed to unauthenticated or unauthorised users.

#### Acceptance Criteria

1. WHEN an unauthenticated user requests `staff_dashboard.php`, THE Dashboard SHALL redirect the user to the login page via `require_login()`.
2. WHEN an authenticated user whose `role_key` is not `staff`, `cashier`, or `pump_attendant` requests `staff_dashboard.php`, THE Dashboard SHALL redirect the user to `dashboard.php`.
3. WHEN an authenticated staff user has no `station_id` assigned, THE Dashboard SHALL terminate with the message "Error: You are not assigned to a station."
4. THE Dashboard SHALL scope every database query with `station_id = ?` bound to the authenticated user's `station_id` to prevent cross-station data access.

---

### Requirement 2: Time Range Selector

**User Story:** As a staff member, I want to filter dashboard data by Today, This Week, or This Month, so that I can review performance across different time windows.

#### Acceptance Criteria

1. THE Dashboard SHALL render a range selector with three options: `today`, `week`, and `month`.
2. WHEN a staff member selects a range, THE Dashboard SHALL reload the page with the `range` query parameter set to the selected value and apply the corresponding date filter to all sales and fuel queries.
3. WHEN the `range` query parameter is absent or contains an unrecognised value, THE Dashboard SHALL default to `today`.
4. THE Dashboard SHALL apply the following date conditions per range:
   - `today`: `DATE(transaction_date) = CURDATE()`
   - `week`: `YEARWEEK(transaction_date, 1) = YEARWEEK(CURDATE(), 1)`
   - `month`: `YEAR(transaction_date) = YEAR(CURDATE()) AND MONTH(transaction_date) = MONTH(CURDATE())`

---

### Requirement 3: Merchandise Sales Summary

**User Story:** As a staff member, I want to see merchandise revenue broken down by payment method, so that I can reconcile cash, card, e-wallet, e-fuel card, and credit sales at a glance.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Sales_Summary SHALL query `merchandise_transactions` filtered by `station_id` and the Selected_Range and compute the total revenue and transaction count.
2. THE Sales_Summary SHALL compute and display five payment-method subtotals from `merchandise_transactions`:
   - Cash: `payment_method IN ('Cash', 'cash')`
   - Card: `payment_method IN ('Credit Card', 'Card', 'card')`
   - E-Wallet: `payment_method IN ('E-Wallet', 'GCash', 'Maya', 'ewallet')`
   - E-Fuel Card: `payment_method IN ('E-Fuel Card', 'Fuel Card', 'efuel')`
   - Credit: `payment_method IN ('Credit', 'Account Receivable', 'utang', 'Utang')`
3. THE Sales_Summary SHALL also include completed Job Order payment totals (from `job_orders WHERE status = 'Completed'`) in the same five payment-method subtotals.
4. THE Sales_Summary SHALL render a bar or doughnut chart (using Chart.js) visualising the five payment-method amounts.
5. THE Sales_Summary SHALL display each payment-method subtotal as a labelled row beneath the chart with a colour-coded dot matching the chart legend.
6. IF no merchandise transactions exist for the Selected_Range, THE Sales_Summary SHALL display a "No sales recorded" empty state message.

---

### Requirement 4: Fuel Sales Summary

**User Story:** As a staff member, I want to see fuel sales broken down by fuel type with liters sold, computed revenue, and variance flags, so that I can monitor fuel dispensing and identify discrepancies for internal reconciliation.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Sales_Summary SHALL query `fuel_transactions` filtered by `station_id`, the Selected_Range, and `liters_sold > 0`, grouping results by `fuel_type`.
2. FOR EACH fuel type, THE Sales_Summary SHALL display: fuel type name, total liters sold, total revenue (₱), average price per liter, transaction count, and a variance flag if `has_discrepancy = 1`.
3. THE Sales_Summary SHALL compute `has_discrepancy` as `MAX(CASE WHEN ABS((present_reading - previous_reading) - liters_sold) >= 2 THEN 1 ELSE 0 END)` per fuel type.
4. WHEN a fuel type has `has_discrepancy = 1`, THE Sales_Summary SHALL render a visible "Variance" badge on that fuel type card and display a note "⚠️ Variance — check readings".
5. WHEN a fuel type has no discrepancy, THE Sales_Summary SHALL display a "✓ Readings OK" note.
6. THE Sales_Summary SHALL render a bar chart (using Chart.js) showing liters sold per fuel type.
7. THE Sales_Summary SHALL NOT display payment method breakdown for fuel sales; fuel is for internal reconciliation only.
8. IF no fuel transactions exist for the Selected_Range, THE Sales_Summary SHALL display a "No fuel readings recorded" empty state with a link to `fuel_readings_encoding.php`.

---

### Requirement 5: Job Orders Status Widget

**User Story:** As a staff member, I want to see a live count of job orders by status, so that I can track the progress of vehicle service requests at my station.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `job_orders` filtered by `station_id` and compute counts for five statuses: Pending Validation, Approved/Validated, In Progress, Completed, and Rejected.
2. THE Dashboard SHALL display each status count as a clickable card that navigates to `joborder.php?status={status}`.
3. THE Dashboard SHALL render a doughnut chart (using Chart.js) visualising the five status counts.
4. WHEN all status counts are zero, THE Dashboard SHALL still render the five status cards with a count of 0.
5. THE Dashboard SHALL use the following status groupings:
   - Pending: `status = 'Pending Validation'`
   - Approved: `status IN ('Approved', 'Validated')`
   - In Progress: `status = 'In Progress'`
   - Completed: `status = 'Completed'`
   - Rejected: `status = 'Rejected'`

---

### Requirement 6: Fuel Monitoring (View Only)

**User Story:** As a staff member, I want to see current tank levels per fuel type, integrated low-stock conditions, and variance alerts — all in one panel — so that I can report issues to the manager without performing any calibration myself.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `fuel_inventory` filtered by `station_id` and display each fuel type's Exact_Liters_Available (sourced from `fuel_inventory.current_level` or `current_stock`) alongside the fuel type name.
2. THE Dashboard SHALL cross-reference `fuel_inventory` stock values against cumulative `fuel_transactions.liters_sold` (grouped by `fuel_type` for `DATE(transaction_date) = CURDATE()`) and display both the inventory-recorded level and today's dispensed liters as separate numeric columns — NO progress bars, NO charts, NO graphs.
3. THE Dashboard SHALL display tank level data in a table or card layout showing numbers only: fuel type name, exact liters available, capacity, and today's liters dispensed.
4. WHEN a fuel type's `current_stock` ≤ 500 L, THE Dashboard SHALL highlight that fuel type row and display a "🔴 Critical — Low Stock" inline badge within the Fuel Monitoring table/card.
5. WHEN a fuel type's `current_stock` is between 501 L and 2 000 L, THE Dashboard SHALL highlight that fuel type row and display a "🟡 Low Stock" inline badge within the Fuel Monitoring table/card.
6. THE Dashboard SHALL display Variance_Alerts as banners or notification rows inside the Fuel Monitoring widget for all `fuel_transactions` where `ABS((present_reading - previous_reading) - liters_sold) >= 2` and `DATE(transaction_date) = CURDATE()`, showing: transaction ID, fuel type, liters sold, pump delta (Pump_Reading delta), and variance liters.
7. WHEN one or more Variance_Alerts exist, THE Dashboard SHALL render a visible alert banner at the top of the Fuel Monitoring widget with the message "⚠️ Variance detected — {N} reading(s) require review."
8. THE Dashboard SHALL NOT render any calibration controls, adjustment forms, replenishment forms, or edit buttons for fuel data — these actions are restricted to Manager and Admin roles only.
9. THE Dashboard SHALL NOT render any charts, graphs, or progress bars inside the Fuel Monitoring widget; all fuel levels SHALL be displayed as plain numeric values.
10. IF no fuel stock data is available, THE Dashboard SHALL display "Stock data unavailable" in the Fuel Monitoring panel.
11. WHILE the authenticated user's role is `staff`, `cashier`, or `pump_attendant`, THE Dashboard SHALL render the Fuel Monitoring widget in view-only mode with no action controls.
12. WHERE the authenticated user's role is `manager` or `admin`, THE Dashboard SHALL display action controls (calibration, replenishment, adjustment) alongside the fuel data — these controls are outside the scope of the Staff Dashboard and are handled by the Manager Dashboard.

---

### Requirement 8: Clock-in / Clock-out Shift Tracker

**User Story:** As a staff member, I want to clock in and out directly from the dashboard and see my shift history for today, so that my attendance is recorded accurately.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `labor_sessions` for the authenticated user's active session (`end_time IS NULL`) and display the current clock-in status.
2. WHEN the staff member is clocked in, THE Dashboard SHALL display: the shift name, the clock-in time, the elapsed duration, and a "Clock Out" button.
3. WHEN the staff member is not clocked in, THE Dashboard SHALL display the detected current Shift_Period name and a "Clock In" button.
4. WHEN the staff member submits the Clock In form, THE Dashboard SHALL insert a row into `labor_sessions` with `user_id`, `station_id`, `start_time = NOW()`, and the auto-detected `shift_key` and `shift_name` from `shift_periods WHERE is_active = 1 AND start_time <= NOW() AND end_time >= NOW()`.
5. IF no matching Shift_Period is found at the current time, THE Dashboard SHALL fall back to the last active shift ordered by `sort_order DESC`.
6. WHEN the staff member submits the Clock Out form, THE Dashboard SHALL update the active `labor_sessions` row setting `end_time = NOW()` and `hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60, 2)`.
7. IF the staff member attempts to clock in while already clocked in, THE Dashboard SHALL display the error "You are already clocked in."
8. IF the staff member attempts to clock out while not clocked in, THE Dashboard SHALL display the error "You are not clocked in."
9. THE Dashboard SHALL display a timeline of today's shift sessions for the authenticated user from `labor_sessions WHERE DATE(start_time) = CURDATE()`, showing start time, end time, shift name, and hours worked.
10. WHEN a clock-in or clock-out action succeeds, THE Dashboard SHALL call `log_activity()` recording the user ID, action label, and station ID.

---

### Requirement 9: Quick Actions Menu

**User Story:** As a staff member, I want a set of quick-action buttons on the dashboard, so that I can navigate directly to the most common tasks without searching through the sidebar.

#### Acceptance Criteria

1. THE Dashboard SHALL render the following Quick_Action buttons, each linking to the specified target:
   - POS (Merchandise Transaction) → `transactions.php`
   - Credit Sale (Utang Encoding) → `transactions.php?type=credit`
   - Create Job Order → `joborder.php?action=create`
   - Receive Items (Deliveries) → `staff_deliveries.php`
   - Fuel Transactions (Internal Reconciliation) → `fuel_readings_encoding.php`
   - My Shift (View Shift History) → `staff_shift_history.php`
2. THE Dashboard SHALL render each Quick_Action as a visually distinct button or card with a Font Awesome icon and a label.
3. THE Dashboard SHALL NOT render any Quick_Action that performs calibration or inventory adjustment.

---

### Requirement 10: Calendar Widget

**User Story:** As a staff member, I want to see today's and upcoming tasks from job orders and deliveries on a calendar widget, so that I can plan my workday without switching between modules.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard SHALL query `job_orders` filtered by `station_id` and `DATE(created_at) = CURDATE()` and display up to 20 results as today's tasks.
2. WHEN the Dashboard loads, THE Dashboard SHALL query `deliveries_oversight` filtered by `station_id` and `DATE(COALESCE(delivery_date, created_at)) = CURDATE()` and include results in today's tasks.
3. THE Dashboard SHALL query `job_orders` and `deliveries_oversight` for tasks where the task date falls between `DATE_ADD(CURDATE(), INTERVAL 1 DAY)` and `DATE_ADD(CURDATE(), INTERVAL 3 DAY)` and display them as upcoming tasks (up to 15 results each).
4. THE Dashboard SHALL display each task with: type label (Job Order / Delivery), reference number, customer or supplier name, status, and task date.
5. THE Dashboard SHALL colour-code staff-created tasks with blue and manager-created tasks with red.
6. IF no tasks exist for today, THE Dashboard SHALL display "No tasks scheduled for today."
7. IF no upcoming tasks exist, THE Dashboard SHALL display "No upcoming tasks in the next 3 days."

---

### Requirement 11: Reports Shortcuts

**User Story:** As a staff member, I want shortcut links to key reports, so that I can access operational reports without navigating through multiple menus.

#### Acceptance Criteria

1. THE Dashboard SHALL render the following Report_Shortcut links:
   - Job Orders Report → `reports.php?type=job_orders`
   - Deliveries Report → `reports.php?type=deliveries`
   - Customer Report (limited view) → `reports.php?type=customers&scope=limited`
   - Transaction Report → `reports.php?type=transactions`
   - Personal Activity Report → `reports.php?type=personal&user_id={authenticated_user_id}`
2. THE Dashboard SHALL render each Report_Shortcut as a labelled link or button with a Font Awesome icon.
3. THE Dashboard SHALL NOT expose report links that require manager or admin privileges (e.g., full financial summaries, user management reports).

---

### Requirement 12: AJAX Data Refresh

**User Story:** As a staff member, I want the dashboard data to refresh without a full page reload, so that I can see up-to-date figures during my shift.

#### Acceptance Criteria

1. WHEN the Dashboard receives a request with `?refresh=1`, THE Dashboard SHALL return a JSON response containing: `today_sales`, `today_fuel`, `today_merch`, all five merchandise payment subtotals, all five job order payment subtotals, `credit_sales`, `txn_today`, all five job order status counts, `fuel_variance_count`, and `fuel_by_type` (labels, liters, revenue, flags).
2. WHEN the Dashboard receives a request with `?refresh_charts=1`, THE Dashboard SHALL return a JSON response containing the merchandise payment breakdown, fuel-by-type breakdown, and job order status counts.
3. IF an exception occurs during an AJAX refresh query, THE Dashboard SHALL return `{"success": false, "error": "<message>"}` with HTTP 200.
4. THE Dashboard SHALL output the JSON response and call `exit` before rendering any HTML when handling an AJAX refresh request.

---

### Requirement 13: Session Flash Messages

**User Story:** As a staff member, I want to see success and error messages after performing actions on the dashboard, so that I know whether my clock-in, clock-out, or other actions succeeded.

#### Acceptance Criteria

1. WHEN a clock-in or clock-out action completes successfully, THE Dashboard SHALL set a success message in `$_SESSION['success']` and display it on the next page load.
2. WHEN a clock-in or clock-out action fails, THE Dashboard SHALL set an error message in `$_SESSION['error']` and display it on the next page load.
3. WHEN a flash message is displayed, THE Dashboard SHALL unset the corresponding session key so the message does not persist across subsequent page loads.
4. THE Dashboard SHALL render flash messages in a visible card element above the main dashboard content.
