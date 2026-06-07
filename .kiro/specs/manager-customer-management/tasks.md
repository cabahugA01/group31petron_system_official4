# Implementation Plan: Manager Customer Management

## Overview

Implement `public/manager_customers.php` as a single-file, three-section manager page (Customer List, Customer Balances, Customer History) following the established pattern of `manager_delivery_validation.php` and `manager_fuel_management_complete.php`. All queries are scoped to the manager's `station_id`. Payment validation uses AJAX (`fetch()` + JSON). CSV export uses `fputcsv()`. PDF export uses `window.print()`. A redirect shim at `public/manager_customer_history.php` forwards legacy links to the new page.

## Tasks

- [x] 1. Bootstrap the page file and shared infrastructure
  - Replace the existing stub in `public/manager_customers.php` with the full page skeleton: `require_once` for `backend/lib.php` and `public/db_connect.php`, `require_login()`, `current_user()`, `user_station_id()`, role gate (redirect non-managers to `dashboard.php`), no-station guard via `render_no_station_page()`, and `$section` routing (`records` / `balances` / `history`, defaulting to `records`)
  - Set `$page_id` via `match($section)` to `mgr_cust_list`, `mgr_cust_balances`, or `mgr_cust_history`
  - Add `ALTER TABLE customers ADD COLUMN IF NOT EXISTS` guards for `contact_number`, `id_number`, `credit_limit`, `current_balance` (matching the pattern in `manager_delivery_validation.php`)
  - Include `partials/header.php` and `partials/footer.php`
  - Render flash messages (`$flash_ok` / `$flash_err` from `$_SESSION`)
  - Render the shared `.tab-nav` / `.tab-btn` bar with three tabs (`?section=records`, `?section=balances`, `?section=history`), active tab highlighted — **no `.info-box` banner anywhere on the page**
  - Add the inline `<style>` block with all CSS variables, `.dv-card`, `.dv-card-head`, `.dv-card-body`, `.tab-nav`, `.tab-btn`, `.badge-*`, `.row-over-limit`, `.row-near-limit`, `.flash-ok`, `.flash-err`, `.empty-state`, `.modal-overlay`, `.modal-box`, `.btn`, `.btn-validate`, `.btn-sm`, `.data-table` rules (mirror `manager_delivery_validation.php` conventions)
  - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [x] 2. Implement the Customer List section (`?section=records`)
  - [x] 2.1 Write the Customer List data query and PHP rendering
    - Build the parameterized, station-scoped `SELECT` query with `COALESCE` for `current_balance`/`balance` and `created_at`/`registration_date`, supporting `:search_like`, `:status_filter`, and `:type_filter` bind parameters
    - Execute a separate `COUNT(*)` query with the same `WHERE` clause for pagination; compute `$total_pages = ceil($total / 50)` and clamp `$page` to valid range
    - Render the filter bar inside a `.dv-card`: text search input (with `data-debounce="300"` attribute for JS), status `<select>` (`all` / `active` / `inactive`), type `<select>` (`all` / `credit` / `walk-in`), Clear Filters `<a>` button that resets to `?section=records`
    - Render the results `<table>` inside `.dv-card-body` with columns: Full Name, Contact, Type (badge), Credit Limit (₱), Outstanding Balance (₱), Status (badge), Registered (date)
    - Render `.empty-state` block when zero records match
    - Render pagination controls (Previous / page numbers / Next) below the table
    - Reset pagination to page 1 when any filter changes (handled by form `GET` submission)
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4, 2.5, 2.6_

  - [ ]* 2.2 Write property test for station scoping (Property 1)
    - **Property 1: Station scoping is total**
    - **Validates: Requirements 1.1, 7.2**
    - Generate customers at 2+ stations; assert query for station X returns only station-X records across 100+ random station/customer combinations

  - [ ]* 2.3 Write property test for search filter correctness (Property 2)
    - **Property 2: Search filter correctness**
    - **Validates: Requirements 2.1**
    - Generate random search terms and customer datasets; assert every returned record contains the term (case-insensitive) in `name`, `contact_number`, or `id_number`

  - [ ]* 2.4 Write property test for status and type filter exclusivity (Property 3)
    - **Property 3: Status and type filters are exclusive**
    - **Validates: Requirements 2.2, 2.3**
    - Generate random filter values; assert every returned record's `status` / `type` matches the filter value

  - [ ]* 2.5 Write property test for filter clear round-trip (Property 4)
    - **Property 4: Filter clear is a round-trip**
    - **Validates: Requirements 2.4**
    - Apply random filter combinations, clear all filters, re-query; assert result set equals unfiltered query

  - [ ]* 2.6 Write property test for pagination page size invariant (Property 5)
    - **Property 5: Pagination page size invariant**
    - **Validates: Requirements 1.5**
    - Generate N > 50 matching records; assert page 1 returns exactly 50 rows and `total_pages = ceil(N / 50)`

- [x] 3. Checkpoint — Ensure all tests pass, ask the user if questions arise.

- [x] 4. Implement the Customer Balances section (`?section=balances`)
  - [x] 4.1 Write the Customer Balances data query and PHP rendering
    - Build the station-scoped `SELECT` query with `COALESCE(credit_limit, 0)`, `COALESCE(current_balance, balance, 0)`, computed `available_credit`, `utilization_pct`, and a correlated subquery for `last_txn_date` from `merchandise_transactions`; filter to `credit_limit > 0 OR outstanding > 0`; order by `outstanding DESC`
    - Compute per-row CSS class: `row-over-limit` when `outstanding >= credit_limit`, `row-near-limit` when `utilization_pct >= 80 AND utilization_pct < 100`
    - Render the summary `.dv-card` at the top with two stat boxes: Total Outstanding (sum of all `outstanding`) and Total Credit Limit (sum of all `credit_limit`)
    - Render the results `<table>` inside `.dv-card-body` with columns: Full Name, Credit Limit (₱), Outstanding Balance (₱), Available Credit (₱), Utilization (% badge), Last Transaction (date), Action ("Record Payment" button)
    - Render the payment `.modal-overlay` / `.modal-box` with: customer name (read-only, populated by JS), hidden `customer_id` input, amount `<input type="number">`, reference/notes `<textarea>`, Submit and Cancel buttons
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

  - [ ]* 4.2 Write property test for balance view inclusion criterion (Property 6)
    - **Property 6: Balance view inclusion criterion**
    - **Validates: Requirements 3.1**
    - Generate customers with varied `credit_limit`/`balance` values; assert every included customer satisfies `credit_limit > 0 OR outstanding > 0` and every qualifying customer is included

  - [ ]* 4.3 Write property test for credit utilization flag correctness (Property 7)
    - **Property 7: Credit utilization flag correctness**
    - **Validates: Requirements 3.3, 3.4**
    - Generate random `(outstanding, credit_limit)` pairs with `credit_limit > 0`; assert flag assignment matches the three-case formula exactly (mutually exclusive and exhaustive)

  - [ ]* 4.4 Write property test for balance summary totals (Property 8)
    - **Property 8: Balance summary totals are additive**
    - **Validates: Requirements 3.5**
    - Generate random customer sets; assert summary total outstanding = arithmetic sum of individual outstandings, and summary total credit limit = arithmetic sum of individual credit limits

- [x] 5. Implement AJAX payment validation
  - [x] 5.1 Write the POST handler for `action=validate_payment`
    - Detect AJAX request (check `Content-Type: application/json` or `X_REQUESTED_WITH` header); decode JSON body
    - Validate inputs: `amount > 0`, `strlen(reference) >= 3`; return `{success: false, error: '...'}` on failure
    - Fetch customer row (`SELECT id, name, COALESCE(current_balance, balance, 0) AS outstanding FROM customers WHERE id = :id AND station_id = :station_id`)
    - If `amount > outstanding`, return `{success: false, overpayment: true, excess: X}` for JS confirmation
    - Wrap balance update (`UPDATE customers SET current_balance = outstanding - amount, balance = outstanding - amount WHERE id = :id AND station_id = :station_id`) and `write_audit_log()` call in a PDO transaction; rollback on exception
    - On success return `{success: true, new_balance: X, new_utilization: Y}`
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 8.1_

  - [x] 5.2 Write the client-side JS for payment modal and AJAX submission
    - `openPaymentModal(id, name, outstanding)` — populates modal fields and shows overlay
    - `closeModal()` — hides overlay
    - `fetch()` POST to `manager_customers.php` with `action=validate_payment` JSON body
    - On `overpayment: true` response: show `confirm()` dialog with excess amount; re-submit with `force_overpayment: true` flag if confirmed
    - On `success: true` response: update the customer's row cells (outstanding balance, available credit, utilization badge, CSS class) in-place without page reload; show inline success flash
    - On `success: false` response: display error message inside the modal
    - _Requirements: 4.3, 4.5, 4.7_

  - [ ]* 5.3 Write property test for payment reduces balance exactly (Property 9)
    - **Property 9: Payment reduces balance by exact amount**
    - **Validates: Requirements 4.1**
    - Generate random `(balance, payment)` pairs where `payment > 0`; assert `new_balance = balance - payment` after handler execution

  - [ ]* 5.4 Write property test for invalid payment rejection (Property 10)
    - **Property 10: Invalid payment inputs are rejected**
    - **Validates: Requirements 4.2**
    - Generate inputs where `amount <= 0` or `strlen(reference) < 3`; assert balance is unchanged and response contains `success: false`

  - [ ]* 5.5 Write property test for payment atomicity on failure (Property 11)
    - **Property 11: Payment atomicity on failure**
    - **Validates: Requirements 4.6**
    - Simulate mid-transaction failure (mock PDO exception after balance update, before audit log write); assert customer balance is identical to pre-attempt value

  - [ ]* 5.6 Write property test for audit log completeness (Property 12)
    - **Property 12: Audit log completeness for payments**
    - **Validates: Requirements 4.4, 8.1**
    - For any valid payment, assert `audit_logs` gains exactly one new row with `action_type = 'Payment Validated'`, `entity_type = 'customers'`, `entity_id = customer_id`, and `action_details` containing the payment amount and reference

- [x] 6. Checkpoint — Ensure all tests pass, ask the user if questions arise.

- [x] 7. Implement the Customer History section (`?section=history`)
  - [x] 7.1 Write the Customer History data query and PHP rendering
    - Build the three-way `UNION ALL` query (Merchandise Sales from `merchandise_transactions`, Job Orders from `job_orders` with `status IN ('Completed','Validated','Approved')`, Payments from `audit_logs` where `action_type = 'Payment Validated'`) with `:station_id`, `:customer_id`, `:date_start`, `:date_end_eod` bind parameters
    - Default date range: past 90 days (`date('Y-m-d', strtotime('-90 days'))` to today)
    - Populate the customer dropdown from `SELECT id, name FROM customers WHERE station_id = :station_id ORDER BY name ASC`
    - Render the filter bar inside a `.dv-card`: customer `<select>` (All Customers + per-customer options), start date `<input type="date">`, end date `<input type="date">`, Apply `<button>`
    - Render the results `<table>` inside `.dv-card-body` with columns: Date, Reference, Type (badge), Amount (₱), Payment Method, Recorded By
    - Render `.empty-state` block when zero transactions match
    - Render Export CSV and Export PDF buttons in `.dv-card-head`
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7_

  - [ ]* 7.2 Write property test for date range filter inclusivity (Property 13)
    - **Property 13: Date range filter is inclusive**
    - **Validates: Requirements 5.3**
    - Generate random date ranges and transaction datasets; assert every returned transaction has `txn_date >= start AND txn_date <= end`

  - [ ]* 7.3 Write property test for customer filter exactness (Property 14)
    - **Property 14: Customer filter is exact**
    - **Validates: Requirements 5.4**
    - Generate random customer ID filters; assert every returned transaction has `customer_id` equal to the selected ID

- [ ] 8. Implement CSV and PDF export
  - [ ] 8.1 Write the CSV export handler (`?section=history&export=csv`)
    - Re-run the same UNION ALL query without `LIMIT`, using the same active filter parameters
    - If zero rows: set `$_SESSION['flash_err']` warning and redirect back — do not emit file headers
    - Call `ob_end_clean()` if output buffering is active, then set `Content-Type: text/csv` and `Content-Disposition: attachment; filename="customer_history_YYYY-MM-DD.csv"` headers
    - Write metadata header rows via `fputcsv()`: station name, manager name, export date, applied date range
    - Write column header row, then one data row per transaction
    - Call `exit` after the last `fputcsv()` call
    - Log the export action via `write_audit_log()` with `action_type = 'Export Customer History'` and filter details in `action_details`
    - _Requirements: 6.1, 6.2, 6.4, 6.5, 6.6, 8.2_

  - [ ] 8.2 Write the PDF export handler (`?print=1`)
    - When `$_GET['print'] === '1'`, render the history table with a `<link rel="stylesheet" media="print">` print stylesheet (hide nav, tabs, filter bar, export buttons; show metadata header)
    - Inject `<script>window.onload = function(){ window.print(); }</script>` at the bottom of the page
    - _Requirements: 6.3, 6.4, 6.5_

  - [ ]* 8.3 Write property test for export rows matching displayed rows (Property 15)
    - **Property 15: Export rows match displayed rows**
    - **Validates: Requirements 6.1, 6.4**
    - For any combination of active filters, assert the set of rows in the exported CSV is identical (same records, same order) to the rows returned by the display query under those same filters

  - [ ]* 8.4 Write property test for export metadata completeness (Property 16)
    - **Property 16: Export metadata header is complete**
    - **Validates: Requirements 6.5**
    - For any export, assert the CSV metadata section contains station name, manager full name, export date, and applied date range — regardless of data row count

- [x] 9. Implement the redirect shim
  - Create `public/manager_customer_history.php` with a single `header('Location: manager_customers.php?section=history'); exit;` redirect
  - _Requirements: 7.1 (legacy URL support)_

- [ ]* 9.1 Write property test for access control (Property 17)
  - **Property 17: Access control is total**
  - **Validates: Requirements 7.1, 7.4**
  - For any HTTP request by a user whose role is not in `['manager', 'admin', 'superadmin']`, assert the response is a redirect to `dashboard.php` with no customer data in the output

- [ ] 10. Final checkpoint — Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- All queries use PDO prepared statements with named bind parameters — no string interpolation of user input
- Balance column resolution uses `COALESCE(current_balance, balance, 0)` everywhere, consistent with `manager_reports.php`
- The `.info-box` banner pattern is explicitly excluded from this page — clean layout only
- Property tests reference the numbered properties in the design document's "Correctness Properties" section
- Checkpoints at tasks 3, 6, and 10 ensure incremental validation before moving to the next phase
