# Implementation Plan: Staff Dashboard (Final Dynamic Layout)

## Overview

Rebuild `public/staff_dashboard.php` from scratch as a single-file PHP page that consolidates sevenen dynamic widgets for authenticated staff. All data is fetched via PDO prepared statements scoped to `station_id`. No hardcoded values. AJAX auto-refresh every 60 seconds. The file follows the request lifecycle defined in the design: access gate → range parse → POST handler → AJAX endpoints → PHP data queries → HTML render → Chart.js init.

---

## Tasks

- [x] 1. Scaffold the file skeleton and access control gate
  - Create `public/staff_dashboard.php` as a new empty file
  - Set `$page_id = 'dashboard'` at the very top (before any include) so `partials/header.php` highlights the Dashboard nav item as active in the sidebar
  - Add `session_start()`, `require_once` for `db_connect.php` and `backend/lib.php`
  - Implement the access control gate: `require_login()`, `current_user()`, `role_key()` check redirecting non-staff roles to `dashboard.php`, `user_station_id()` check calling `die()` if null
  - Prepare the welcome greeting variables: `$display_name = htmlspecialchars($me['full_name'] ?? trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: 'Staff')` and `$station_label = htmlspecialchars($me['station_name'] ?? 'Station #' . $station_id)`
  - Add the range parameter parser with allowlist validation defaulting to `'today'` and the `$date_cond` match expression
  - Add the flash message read-and-unset block (`$_SESSION['success']` / `$_SESSION['error']`)
  - _Requirements: 0.1, 0.2, 0.3, 0.4, 1.1, 1.2, 1.3, 1.4, 2.1, 2.3, 2.4, 13.1, 13.2, 13.3_

- [ ] 2. Implement the Clock-in / Clock-out POST handler
  - [ ] 2.1 Implement the `clock_in` POST action
    - Check for an existing active `labor_sessions` row (`end_time IS NULL`) for the current user; set `$_SESSION['error']` if found
    - Query `shift_periods` to auto-detect the current shift by time window; fall back to last active shift by `sort_order DESC`
    - Insert a new `labor_sessions` row with `user_id`, `station_id`, `start_time = NOW()`, `shift_period`, `shift_name`
    - Call `log_activity()` and set `$_SESSION['success']`
    - _Requirements: 8.4, 8.5, 8.7, 8.10_
  - [ ] 2.2 Implement the `clock_out` POST action
    - Execute the UPDATE on `labor_sessions` setting `end_time = NOW()` and `hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60, 2)` where `user_id = ?` and `end_time IS NULL`
    - Check `rowCount()` to distinguish success from "not clocked in"; set appropriate session flash
    - Call `log_activity()` on success
    - Redirect to `staff_dashboard.php?range={$range}` after both actions
    - _Requirements: 8.6, 8.8, 8.10_
  - [ ]* 2.3 Write unit tests for clock-in / clock-out logic
    - Test double-clock-in guard returns error message
    - Test clock-out when not clocked in returns error message
    - Test `hours_worked` formula: `ROUND(TIMESTAMPDIFF(MINUTE, start, end) / 60, 2)` matches Property 8
    - _Requirements: 8.6, 8.7, 8.8_

- [ ] 3. Implement the AJAX refresh endpoints
  - [ ] 3.1 Implement `?refresh=1` full metrics endpoint
    - Handle the request before any HTML output; set `Content-Type: application/json`
    - Re-run all metric queries (merchandise totals, job order payment subtotals, job order status counts, fuel variance count, low stock count, fuel by type array)
    - Return the complete JSON payload defined in the design (all 23+ keys including `fuel_by_type` with `labels`, `liters`, `revenue`, `flags`)
    - Wrap in `try/catch`; return `{"success": false, "error": "..."}` on exception; call `exit`
    - _Requirements: 12.1, 12.3, 12.4_
  - [ ] 3.2 Implement `?refresh_charts=1` chart-data endpoint
    - Handle before HTML output; return the chart-only JSON payload (merchandise payment breakdown, fuel by type, job order status counts)
    - Wrap in `try/catch`; call `exit`
    - _Requirements: 12.2, 12.3, 12.4_
  - [ ]* 3.3 Write unit tests for AJAX endpoint response shape
    - Verify `?refresh=1` response contains all required keys (Property 11)
    - Verify `?refresh_charts=1` response contains the chart subset keys
    - Verify exception path returns `{"success": false, "error": "..."}` (Requirement 12.3)
    - _Requirements: 12.1, 12.2, 12.3_

- [ ] 4. Checkpoint — Ensure access gate, POST handler, and AJAX endpoints are wired correctly
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 5. Implement PHP data queries for all seven widgets
  - [ ] 5.1 Query merchandise sales summary
    - Execute the single-query merchandise aggregation (total, count, five payment-method CASE sums) with `station_id` and `$date_cond`
    - Execute the completed job orders payment aggregation (five CASE sums) with `station_id`, `status = 'Completed'`, and `$date_cond`
    - Assign results to `$today_merch`, `$merch_txns`, `$merch_cash/card/ewallet/efuel/credit`, `$jo_cash/card/ewallet/efuel/credit`
    - Wrap in `try/catch`; default all scalars to `0` on failure
    - _Requirements: 3.1, 3.2, 3.3_
  - [ ] 5.2 Query fuel sales summary
    - Execute the `fuel_transactions` GROUP BY `fuel_type` query with `station_id`, `$date_cond`, and `liters_sold > 0`
    - Compute `total_liters`, `total_revenue`, `avg_price`, `txn_count`, `total_variance_liters`, `has_discrepancy` per fuel type
    - Assign to `$fuel_by_type` array; default to `[]` on failure
    - _Requirements: 4.1, 4.2, 4.3_
  - [ ] 5.3 Query job orders status counts
    - Execute five separate prepared statements (one per status group) scoped to `station_id`
    - Assign to `$pending_validation`, `$approved_validated`, `$in_progress`, `$completed`, `$rejected`
    - Default to `0` on failure
    - _Requirements: 5.1, 5.5_
  - [ ] 5.4 Query fuel monitoring data (stock levels, dispensed today, variance alerts)
    - Execute the `fuel_inventory LEFT JOIN fuel_types` query for stock levels scoped to `station_id`, including a correlated subquery for `liters_dispensed_today` (SUM of `liters_sold` from `fuel_transactions` for today per fuel type)
    - Compute `stock_status` inline in SQL: `'Critical'` when `current_stock <= 500`, `'Low Stock'` when `current_stock <= 2000`, `'Normal'` otherwise
    - Execute the variance alerts query (today only, `ABS(...) >= 2`, LIMIT 10) scoped to `station_id`
    - Assign to `$fuel_stock_levels` (includes `liters_dispensed_today` and `stock_status` per row) and `$fuel_variance_alerts`; default to `[]` on failure
    - Compute `$fuel_variance_count = count($fuel_variance_alerts)` for the banner message
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7_
  - [ ] 5.5 Query merchandise low stock alerts
    - Execute the `station_inventory` query with `status = 'active'`, `stock_level <= reorder_level`, ORDER BY shortage DESC, LIMIT 10
    - Include the CASE expression for `priority` (Critical / High / Medium) in the SQL
    - Assign to `$low_stock_items`; default to `[]` on failure
    - _Requirements: 7.1, 7.2, 7.3_
  - [ ] 5.6 Query clock-in / clock-out data
    - Execute the active session query (`end_time IS NULL`) for the current user; assign to `$current_session`
    - Execute the today timeline query (`DATE(start_time) = CURDATE()`) for the current user; assign to `$my_shifts_today`
    - Query `shift_periods` to detect the current shift for the clock-in button label; assign to `$current_shift_name`
    - Default to `null` / `[]` on failure
    - _Requirements: 8.1, 8.2, 8.3, 8.9_
  - [ ] 5.7 Query calendar widget data
    - Execute today job orders query (`DATE(created_at) = CURDATE()`, LIMIT 20) scoped to `station_id`
    - Execute today deliveries query (`DATE(COALESCE(delivery_date, created_at)) = CURDATE()`, LIMIT 20) scoped to `station_id`
    - Execute upcoming job orders query (next 3 days date range, LIMIT 15) scoped to `station_id`
    - Execute upcoming deliveries query (next 3 days date range, LIMIT 15) scoped to `station_id`
    - Merge and assign to `$calendar_today` and `$calendar_upcoming`; default to `[]` on failure
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5_
  - [ ]* 5.8 Write property tests for data query correctness
    - **Property 2: Station data isolation** — assert no row in any widget result set has a `station_id` different from the authenticated user's station
    - **Property 3: Invalid range defaults to today** — assert that any `$range` value outside `['today','week','month']` produces the `DATE(transaction_date) = CURDATE()` condition
    - **Property 4: Merchandise payment subtotals sum to total** — assert `merch_cash + merch_card + merch_ewallet + merch_efuel + merch_credit == today_merch` for any transaction set
    - **Property 5: Fuel discrepancy flag correctness** — assert `has_discrepancy = 1` iff at least one transaction in the group has `ABS((present - previous) - liters) >= 2`
    - **Validates: Requirements 1.4, 2.3, 3.1–3.3, 4.3**

- [ ] 6. Checkpoint — Ensure all data queries return correct results
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 7. Render the HTML page structure and CSS
  - Include `partials/header.php` using `require_once __DIR__ . '/../partials/header.php'` — this renders the full top header bar AND the role-filtered sidebar navigation (staff role automatically gets the correct staff sidebar via `rbac_menu.php`'s `filter_menu_by_permissions()`)
  - The sidebar will show: Dashboard (active), Transactions, Job Orders, Fuel Management, Inventory, Deliveries Management, Customers, Calendar, Reports — all filtered by `role_key = 'staff'` automatically
  - Wrap all dashboard content in `<div class="main" id="mainContent">` so it sits correctly to the right of the fixed sidebar and below the fixed top header (matching the layout used by `manager_dashboard.php`)
  - Add an inline `<style>` block defining `.dashboard-grid` (2-column CSS grid, gap 20px), `.widget-full` (full-width span), responsive single-column breakpoint at 900px, widget card styles, flash card styles, progress bar styles, badge styles (Critical/High/Medium/Low Stock), colour-coded calendar dot styles (blue for staff, red for manager), and `.welcome-banner` gradient styles
  - Render the **Welcome Banner** as the first element inside `.main`: `"Welcome back, {$display_name}!"` with `$station_label` and today's date — output via `htmlspecialchars()` already applied at scaffold time
  - Render flash message cards (success and error) below the welcome banner, reading from `$flash_success` / `$flash_error`
  - Render the range selector bar (Today / This Week / This Month) with the active range highlighted
  - Close the `.main` div at the bottom of the file after all widgets and the footer include
  - _Requirements: 0.1, 0.2, 0.3, 0.4, 0.5, 2.1, 2.2, 13.4_

- [ ] 8. Render Widget 1 — Sales Summary (Merchandise + Job Orders)
  - Render a widget card with a `<canvas id="salesChart">` for the doughnut chart
  - Render five labelled rows (Cash, Card, E-Wallet, E-Fuel Card, Credit) each showing the combined merchandise + job order subtotal with a colour-coded dot
  - Display total merchandise revenue and transaction count
  - Render the "No sales recorded" empty state when `$today_merch == 0 && $merch_txns == 0`
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

- [ ] 9. Render Widget 2 — Fuel Sales Summary
  - Render a widget card with a `<canvas id="fuelChart">` for the bar chart
  - For each row in `$fuel_by_type`, render a card showing: fuel type name, total liters, total revenue (₱), avg price/L, transaction count
  - Render a "⚠️ Variance" badge when `has_discrepancy = 1`; render "✓ Readings OK" otherwise
  - Render the "No fuel readings recorded" empty state with a link to `fuel_readings_encoding.php` when `$fuel_by_type` is empty
  - _Requirements: 4.1, 4.2, 4.4, 4.5, 4.6, 4.7, 4.8_

- [ ] 10. Render Widget 3 — Job Orders Status
  - Render a widget card with a `<canvas id="jobOrdersChart">` for the doughnut chart
  - Render five clickable status cards (Pending, Approved, In Progress, Completed, Rejected) each showing the count and linking to `joborder.php?status={status}`
  - Always render all five cards even when counts are zero
  - _Requirements: 5.1, 5.2, 5.3, 5.4_

- [ ] 11. Render Widget 4 — Fuel Monitoring (View Only)
  - Render a widget card with the title "Fuel Monitoring"
  - When `$fuel_variance_count > 0`, render a visible alert banner at the top of the widget: "⚠️ Variance detected — {N} reading(s) require review." (Variance_Alert banner)
  - Render a table (NOT a chart, NOT a progress bar) with columns: Fuel Type, Exact Liters Available, Capacity (L), Dispensed Today (L), Status
  - For each row in `$fuel_stock_levels`, display the numeric values and an inline status badge:
    - `stock_status = 'Critical'`: highlight row and show "🔴 Critical — Low Stock" badge
    - `stock_status = 'Low Stock'`: highlight row and show "🟡 Low Stock" badge
    - `stock_status = 'Normal'`: show "✅ Normal" badge
  - When `$fuel_variance_alerts` is not empty, render a "Variance Details" sub-section as a table showing: transaction ID, fuel type, liters sold, pump delta, variance liters
  - Render "Stock data unavailable" empty state when `$fuel_stock_levels` is empty
  - Do NOT render any calibration controls, adjustment forms, replenishment forms, or edit buttons
  - Do NOT render any charts, graphs, or progress bars — all values are plain numbers
  - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9, 6.10, 6.11_

- [ ] 12. Render Widget 5 — Merchandise Low Stock Alerts
  - Render a widget card listing each item from `$low_stock_items`
  - For each item, display: product name, current stock level, reorder level, and a priority badge (Critical / High / Medium) colour-coded accordingly
  - Render "All stock levels OK" empty state when `$low_stock_items` is empty
  - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [ ] 13. Render Widget 6 — Clock-in / Clock-out Shift Tracker
  - When `$current_session` is not null (clocked in): display shift name, clock-in time, elapsed duration (computed from `start_time` to `NOW()`), and a "Clock Out" form button (POST `action=clock_out`)
  - When `$current_session` is null (not clocked in): display the detected `$current_shift_name` and a "Clock In" form button (POST `action=clock_in`)
  - Render the today shift timeline from `$my_shifts_today` as a list showing start time, end time (or "Active"), shift name, and hours worked
  - _Requirements: 8.1, 8.2, 8.3, 8.9_

- [ ] 14. Render Widget 7 — Calendar Widget
  - Render a full-width widget card with two sections: "Today" and "Upcoming (Next 3 Days)"
  - For each item in `$calendar_today`, display: type label (Job Order / Delivery), reference number, customer/supplier name, status, task date; apply blue dot for `color_role = 'staff'`, red dot for `color_role = 'manager'`
  - For each item in `$calendar_upcoming`, display the same fields grouped or labelled by date
  - Render "No tasks scheduled for today." when `$calendar_today` is empty
  - Render "No upcoming tasks in the next 3 days." when `$calendar_upcoming` is empty
  - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6, 10.7_

- [ ] 15. Render Quick Actions panel
  - Render a full-width panel with six `<a>` cards, each with a Font Awesome icon and label:
    - POS (`fa-cash-register`) → `transactions.php`
    - Credit Sale (`fa-file-invoice-dollar`) → `transactions.php?type=credit`
    - Create Job Order (`fa-wrench`) → `joborder.php?action=create`
    - Receive Items (`fa-box-open`) → `staff_deliveries.php`
    - Fuel Transactions (`fa-gas-pump`) → `fuel_readings_encoding.php`
    - My Shift (`fa-clock`) → `staff_shift_history.php`
  - Do NOT include any calibration or inventory adjustment links
  - _Requirements: 9.1, 9.2, 9.3_

- [ ] 16. Render Reports Shortcuts panel
  - Render a full-width panel with five `<a>` links, each with a Font Awesome icon and label:
    - Job Orders Report (`fa-clipboard-list`) → `reports.php?type=job_orders`
    - Deliveries Report (`fa-truck`) → `reports.php?type=deliveries`
    - Customer Report (`fa-users`) → `reports.php?type=customers&scope=limited`
    - Transaction Report (`fa-receipt`) → `reports.php?type=transactions`
    - Personal Activity Report (`fa-user-clock`) → `reports.php?type=personal&user_id=<?= $me['id'] ?>`
  - Do NOT include manager/admin-only report links
  - _Requirements: 11.1, 11.2, 11.3_

- [ ] 17. Initialise Chart.js charts and wire AJAX polling
  - Add an inline `<script>` block at the bottom of the page (after all HTML widgets)
  - Initialise the Merchandise Payment doughnut chart on `#salesChart` using PHP-encoded values for the five combined (merch + JO) payment subtotals
  - Initialise the Fuel Sales bar chart on `#fuelChart` using `json_encode(array_column($fuel_by_type, ...))` for labels and liters
  - Initialise the Job Orders Status doughnut chart on `#jobOrdersChart` using the five status count variables
  - Implement `updateMetricCards(data)` JS function that updates DOM elements for all metric values when called from the AJAX poller
  - Implement `setInterval` polling every 60 000 ms calling `?refresh=1&range=<?= $range ?>` and invoking `updateMetricCards` on success
  - _Requirements: 3.4, 4.6, 5.3, 12.1_
  - [ ]* 17.1 Write property tests for Chart.js data binding and fuel monitoring display
    - **Property 4: Merchandise payment subtotals sum to total** — assert that the sum of the five dataset values passed to `salesChart` equals `$today_merch + total JO revenue`
    - **Property 6: Fuel stock status label correctness** — assert badge label matches the `current_stock` thresholds (Critical ≤ 500, Low Stock 501–2000, Normal > 2000); assert no progress bar or chart element is rendered for fuel stock
    - **Property 6a: Fuel variance banner threshold** — assert the variance banner is shown iff `count($fuel_variance_alerts) > 0`, and the count in the message matches `count($fuel_variance_alerts)`
    - **Property 7: Merchandise low-stock priority correctness** — assert badge label matches the `stock_level` vs `reorder_level` thresholds
    - **Validates: Requirements 3.4, 6.4, 6.5, 6.7, 6.9, 7.3**

- [ ] 18. Final checkpoint — Full integration review
  - Ensure all tests pass, ask the user if questions arise.
  - Verify the page renders without PHP errors or warnings
  - Verify all seven widgets appear in the correct grid positions
  - Verify AJAX polling fires and updates metric cards without a full page reload
  - Verify clock-in and clock-out POST actions redirect correctly and display flash messages
  - Verify no calibration controls, adjustment forms, or manager-only links are present anywhere on the page

---

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP build
- Every SQL query must use PDO prepared statements with `station_id` bound as a parameter — never string-interpolated
- The `$date_cond` string (structural SQL clause) is safe to embed directly because it is built from a validated allowlist, never from raw user input
- Chart.js and Font Awesome are loaded via CDN in `partials/header.php` (already used project-wide — do not add duplicate CDN links)
- All widget data queries are individually wrapped in `try/catch` so a single failing query does not break the entire page
- Property tests (tasks marked `*`) validate the correctness properties defined in `design.md` sections Property 1–12
