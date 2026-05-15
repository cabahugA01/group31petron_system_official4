# Implementation Plan: Staff Customer Sidebar

## Overview

Two files are modified: `partials/rbac_menu.php` (switch customer sub-item URLs from hash to `?tab=` and remove the staff/manager flat-link override) and `public/customers.php` (resolve active tab from `$_GET['tab']`, unlock balances tab for staff as read-only, rebuild transaction linkage tab, fix JS init). No new files, no new database tables.

> **Key discovery:** The sidebar is rendered by `partials/header.php` via `partials/rbac_menu.php` — not by `includes/staff_sidebar.php`. The `rbac_menu.php` already defines the four customer sub-items but overrides them to a flat link for `staff` and `manager` roles (lines ~308–354). The active sub-item highlight already works via query-param matching in `header.php` once the `?tab=` URLs are in place.

## Tasks

- [x] 1. Update `partials/rbac_menu.php`: switch customer sub-item URLs to `?tab=` and enable sub-menu for staff/manager
  - [x] 1.1 Change the four customer sub-item `href` values from hash-based (`customers.php#encode`, etc.) to query-param form (`customers.php?tab=encode`, `customers.php?tab=update`, `customers.php?tab=linkage`, `customers.php?tab=balances`)
    - Edit lines ~73–76 in `partials/rbac_menu.php`
    - _Requirements: 1.3, 1.4, 1.5, 1.6_
  - [x] 1.2 Remove the staff role override block (~lines 308–312) that replaces the customers entry with a flat direct link and clears `sub_items`
    - After removal, staff will see the full sub-menu just like other roles
    - _Requirements: 1.1, 1.2_
  - [x] 1.3 Remove the manager role override block (~lines 350–354) that similarly flattens the customers entry for managers
    - _Requirements: 1.1, 1.2_
  - [ ]* 1.4 Write unit test: assert the customers entry in the filtered menu for a staff role has exactly 4 sub-items, each with a `?tab=` URL
    - Test `customer_encode`, `customer_update`, `customer_linkage`, `customer_balances` sub-items
    - _Requirements: 1.1, 1.3, 1.4, 1.5, 1.6_

- [x] 2. Update `public/customers.php`: resolve `$active_tab` from `$_GET['tab']`
  - [x] 2.1 Add tab resolution block after the role check, before data fetching
    - Whitelist: `['encode', 'update', 'balances', 'linkage', 'transparency']`
    - Default to `'encode'` if `$_GET['tab']` is absent or not in whitelist
    - Redirect staff roles away from `transparency` to `encode`
    - Set `$sidebar_active_tab = $active_tab` before the `include header.php` line (header.php reads `$page_id`; the sidebar active-tab signal is passed via the `?tab=` URL which `header.php` already reads from `$_GET` for query-param matching — no function signature change needed)
    - _Requirements: 2.1, 2.2, 2.3_
  - [ ]* 2.2 Write property test for tab resolution (Property 1)
    - **Property 1: Tab Resolution Correctness**
    - **Validates: Requirements 2.1, 2.2, 2.3**
    - Extract `resolveTab($tab_input, $role)` as a pure function and test with arbitrary strings: result must be in valid set iff input is in valid set; otherwise `'encode'`; `transparency` always maps to `'encode'` for staff roles
    - Minimum 100 iterations over arbitrary strings

- [x] 3. Checkpoint — Ensure tab resolution is wired correctly
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. Update `public/customers.php`: unlock Outstanding Balances tab for staff
  - [x] 4.1 Remove the `<?php if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>` wrapper around the Outstanding Balances tab button in the tab bar
    - The button must now render for all roles that can access `customers.php`
    - _Requirements: 3.1, 3.2_
  - [x] 4.2 Add staff read-only data fetch in the PHP data section
    - Query: `SELECT ar.id, ar.customer_id, c.name AS customer_name, ar.amount AS balance_amount, ar.due_date FROM accounts_receivable ar LEFT JOIN customers c ON ar.customer_id = c.id WHERE ar.station_id = ? AND ar.status = 'Pending' ORDER BY ar.due_date ASC`
    - Store result in `$staff_receivables`; only execute for staff roles
    - _Requirements: 3.6_
  - [x] 4.3 Split the `balances-tab` panel into staff branch and manager branch
    - Staff branch: read-only table with columns Customer ID, Customer Name, Balance Amount, Due Date; info notice "Read-only view. Contact your manager to record payments."; no payment form, no action buttons
    - Manager branch: existing full content (table + payment form) unchanged, wrapped in `<?php else: ?>`
    - Remove the outer `<?php if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>` wrapper around the entire `balances-tab` div
    - _Requirements: 3.1, 3.3, 3.4, 3.5_
  - [ ]* 4.4 Write property test for staff role access to balances tab (Property 2)
    - **Property 2: Staff Role Access to Balances Tab**
    - **Validates: Requirements 3.1, 3.2**
    - For each role in `{staff, cashier, pump_attendant}`, assert rendered HTML contains the balances tab button and `balances-tab` div
  - [ ]* 4.5 Write property test for balances data filtering (Property 3)
    - **Property 3: Balances Data Filtering Correctness**
    - **Validates: Requirements 3.6**
    - Generate AR records with random `status` and `station_id` values; assert query returns only `status = 'Pending'` AND `station_id = $user_station_id`
    - Minimum 100 iterations

- [x] 5. Update `public/customers.php`: rebuild Transaction Linkage tab
  - [x] 5.1 Add linkage data fetch in the PHP data section
    - `$linkage_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;`
    - Job orders query (when `$linkage_customer_id` is set): `SELECT id, job_order_id, service_type, created_at, status FROM job_orders WHERE station_id = ? AND (customer_id = ? OR credit_customer_id = ?) ORDER BY created_at DESC`
    - Merchandise transactions query: `SELECT id, customer_name, total_amount, COALESCE(transaction_date, created_at) AS txn_date, status FROM merchandise_transactions WHERE station_id = ? AND customer_id = ? ORDER BY txn_date DESC`
    - Store in `$job_orders_linked` and `$merchandise_linked` (both default to `[]`)
    - _Requirements: 4.1, 4.2, 4.6_
  - [x] 5.2 Replace the existing linkage tab HTML with the new two-section layout
    - Customer selector: `<form method="get" action="customers.php">` with hidden `tab=linkage` input and a `<select name="customer_id">` populated from `$customers`, pre-selected if `$linkage_customer_id` matches; `onchange="this.form.submit()"`
    - Job Orders section: heading "Job Orders", table with columns Job Order ID, Service Type, Date, Status — or "No job orders found" if `$job_orders_linked` is empty
    - Merchandise Transactions section: heading "Merchandise Transactions", table with columns Transaction ID, Customer Name, Amount, Date, Status — or "No merchandise transactions found" if `$merchandise_linked` is empty
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6_
  - [ ]* 5.3 Write property test for transaction linkage station filtering (Property 6)
    - **Property 6: Transaction Linkage Station Filtering**
    - **Validates: Requirements 4.1, 4.2, 4.6**
    - Generate job_orders and merchandise_transactions with random `station_id` values; assert all returned records have `station_id = $user_station_id`
    - Minimum 100 iterations

- [x] 6. Checkpoint — Ensure linkage tab renders correctly for all customer selections
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. Update `public/customers.php`: fix JS tab initialization
  - [x] 7.1 Replace the `window.location.hash` logic in the `DOMContentLoaded` handler with PHP-injected active tab
    - Replace hash-reading code with: `const activeTab = <?php echo json_encode($active_tab); ?>;`
    - Call `switchTab(activeTab)` (or the equivalent call that activates the correct tab button and panel)
    - Keep the `switchTab()` function body unchanged
    - _Requirements: 2.4_
  - [ ]* 7.2 Write unit test for JS init
    - Assert rendered page HTML contains `const activeTab = ` followed by a JSON-encoded string matching `$active_tab`
    - _Requirements: 2.4_

- [x] 8. Verify active sub-item highlighting works end-to-end
  - [x] 8.1 Confirm `header.php` query-param matching already handles `?tab=` URLs
    - The existing sub-item active logic in `header.php` (~line 1470) matches `$_GET[$k]` against sub-item query params — verify the `tab` key is matched correctly for each of the four sub-items
    - If the match logic requires `$sub_query` to be non-empty and the sub-item file to match `$current_url`, confirm `customers.php?tab=encode` satisfies both conditions; adjust the match block if needed
    - _Requirements: 5.2, 5.3, 5.4, 5.5_
  - [x] 8.2 Confirm the customers parent `<li>` auto-expands when `$current_url === 'customers.php'`
    - The `$parent_active` flag in `header.php` is set when any sub-item matches; with `?tab=` URLs this will be true whenever a customer sub-page is loaded — verify the `display:block` / `expanded` class is applied to the customers sub-menu
    - _Requirements: 1.8, 5.1, 5.6_
  - [ ]* 8.3 Write property test for sub-item active highlighting (Property 4)
    - **Property 4: Sub-Item Active Highlighting**
    - **Validates: Requirements 5.2, 5.3, 5.4, 5.5**
    - For each tab value in `{encode, update, linkage, balances}`, render the sidebar with `?tab={value}` and assert exactly one sub-item has the `active` class, matching the input tab
  - [ ]* 8.4 Write property test for parent active class exclusivity (Property 5)
    - **Property 5: Parent Active Class Exclusivity**
    - **Validates: Requirements 1.8, 5.1**
    - For arbitrary page key strings, assert the customers parent `<li>` has `active` class if and only if the current page is `customers.php`

- [x] 9. Final checkpoint — Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- The sidebar rendering is done by `partials/rbac_menu.php` + `partials/header.php`, not `includes/staff_sidebar.php` — all sidebar changes go in `rbac_menu.php`
- The `header.php` query-param matching logic already supports `?tab=` style URLs; no changes to `header.php` are expected unless the match verification in Task 8.1 reveals a gap
- `includes/staff_sidebar.php` defines `getStaffSidebar()` which is never called by `customers.php` — it is not modified by this feature
- Property tests reference the design document's Correctness Properties section for traceability
