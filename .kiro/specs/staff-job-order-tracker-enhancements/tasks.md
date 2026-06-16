# Implementation Tasks

## Staff Job Order Tracker — Missing Components

- [ ] 1. Database Migration — New Columns
  - Create `database/migrations/job_order_tracker_enhancements.sql`
  - Add `due_date DATE DEFAULT NULL` to `job_orders` (idempotent — ADD COLUMN IF NOT EXISTS)
  - Add `balance_due DECIMAL(12,2) NOT NULL DEFAULT 0.00` to `job_orders` (idempotent)
  - Add `staff_remarks TEXT DEFAULT NULL` to `merchandise_transactions` (idempotent)
  - Add `manager_notes TEXT DEFAULT NULL` to `merchandise_transactions` (idempotent)
  - Add `due_date DATE DEFAULT NULL` to `merchandise_transactions` (idempotent)
  - Add `inventory_deducted TINYINT(1) NOT NULL DEFAULT 0` to `merchandise_transactions` (idempotent)
  - Run migration at page load in `staff_transactions_hub.php` using existing try/catch `$pdo->exec()` pattern (same as schema safety block at top of file)
  - **Reference:** design.md §Schema Changes; requirements.md §Requirement 1–2

- [ ] 2. Inventory Impact Column — PHP Data Layer
  - In `staff_transactions_hub.php`, after the `$job_orders` merge+sort, build a `$inv_impact` lookup array keyed by `{_source}:{id}`
  - For `merchandise_transactions` rows: query `merchandise_transaction_items` joined to `station_inventory` to get per-item deduction status
  - For `job_orders` rows: parse `required_parts` JSON, look up each part name against `inventory_products` + `station_inventory` to get current stock
  - Deduction status rule: `validation_status == 'Approved' && inventory_deducted == 1` → "Yes"; Approved but not deducted → "No"; not yet Approved → "Pending"; no parts → null
  - Store result as `$inv_impact["{source}:{id}"]` = array of `['part' => ..., 'qty' => ..., 'status' => ...]`
  - **Reference:** design.md §Component 1; requirements.md §Requirement 1 AC1–6

- [ ] 3. Inventory Impact Column — HTML Render
  - In the tracker table header row (`<th>` tags), add "Inv. Impact" column after "Items/Parts"
  - In each tracker row (`<td>` cells), render the `$inv_impact` data as `"Part Name (N pc) → Deducted: Yes/No/Pending"`, each part on its own line; show "—" if null
  - Apply colour coding: green text for "Yes", orange for "No", grey for "Pending"
  - **Reference:** design.md §Component 1; requirements.md §Requirement 1 AC1–6

- [ ] 4. Receivables Tracker — due date POST handler
  - In the existing `jo_action` POST switch block in `staff_transactions_hub.php`, add case `'set_due_date'`
  - Validate `$_POST['due_date']` is a valid `Y-m-d` date; reject otherwise
  - Update `due_date` on the correct table (`job_orders` or `merchandise_transactions`) using `$jo_id` + `$station_id` guard
  - Redirect back with success flash message
  - **Reference:** design.md §Component 2; requirements.md §Requirement 2 AC5

- [ ] 5. Receivables Tracker — HTML Render
  - In the Payment column of each tracker row, below the existing payment badge:
    - Show `Due: YYYY-MM-DD` in small grey text if `due_date` is set and `payment_status` is one of `Pending Payment`, `Partial Payment`, `Unpaid`
    - If `due_date ≤ today` and payment not settled, render a red "⚠ OVERDUE" badge
    - Show running balance: `₱X.XX remaining` (computed as `total_cost − amount_paid`)
  - Add inline pencil-icon button that expands a small inline form to set/update `due_date` (posts `jo_action=set_due_date`)
  - Paid rows show no overdue indicator (requirement AC6)
  - **Reference:** design.md §Component 2; requirements.md §Requirement 2 AC1–7

- [ ] 6. Validation Notes Separation — Schema-aware display
  - In the Remarks column of each tracker row, replace the single remarks cell with a two-section layout:
    - `Staff:` label + value from `COALESCE(staff_remarks, remarks, notes, '')` (handles both tables and legacy)
    - `Manager:` label + value from `COALESCE(manager_notes, admin_remarks, '')` (handles both tables)
  - Show "—" only when both are empty (requirement AC7)
  - Staff role: render manager notes as read-only text (no edit)
  - Manager role: both sections are shown (no edit needed in this view — manager edits happen in manager validation page)
  - **Reference:** design.md §Component 3; requirements.md §Requirement 3 AC1–9

- [ ] 7. Variance Alerts — Detection PHP Logic
  - After `$job_orders` is populated (still within `if ($section === 'merchandise')`), loop through each job order
  - Skip rows with `status` IN `['Rejected', 'Cancelled', 'Completed']`
  - **Quantity check:** For each merchandise part, if encoded qty > `station_inventory.stock_level` for matching product+station → flag with type `'qty'`
  - **Amount check:** Sum `(quantity × unit_price)` across items; if `|sum − total_amount| > 0.01` → flag with type `'amount'`
  - Build `$variance_alerts` array: `[['jo_ref' => ..., 'source' => ..., 'type' => ..., 'message' => ...], ...]`
  - Set `$variance_alert_count = count($variance_alerts)`
  - **Reference:** design.md §Component 4; requirements.md §Requirement 4 AC1–8

- [ ] 8. Variance Alerts — Header Badge
  - In `partials/header.php`, locate the notification icon area
  - Add a warning bell icon (e.g. `<i class="fas fa-exclamation-triangle">`) that is shown only when `isset($variance_alert_count) && $variance_alert_count > 0`
  - Badge: small red circle with `$variance_alert_count` number, consistent with existing UI style
  - Dropdown panel: clicking the icon toggles a `<div>` listing each alert as `"JO-XXX: Variance Alert — <message>"`
  - When `$variance_alert_count == 0`, icon and dropdown are not rendered
  - **Reference:** design.md §Component 4; requirements.md §Requirement 4 AC4–7

- [ ] 9. Staff KPI Snapshot — PHP Data Query
  - After `$job_orders` is populated, run KPI queries:
    - Count of job orders encoded today by current user + station (from both `job_orders` and `merchandise_transactions`)
    - Sum of merchandise item quantities released today (from `merchandise_transaction_items` for today's JOs)
    - Sum of `total_amount` across today's JOs
  - Store results in `$kpi_jo_count`, `$kpi_merch_released`, `$kpi_total_encoded`
  - Default all to 0 on query failure
  - **Reference:** design.md §Component 5; requirements.md §Requirement 5 AC2, AC5–7

- [ ] 10. Staff KPI Snapshot — HTML Panel
  - Above the tracker table header (inside `#jo-tracker-panel`), add a collapsed `<div id="kpi-snapshot">` panel
  - Toggle button `"📊 My KPI Today"` next to the tracker heading — clicking toggles `kpi-snapshot` visibility via JS `classList.toggle('d-none')`
  - Panel contains 3 stat cards using existing Petron card style:
    - "Job Orders Encoded Today" → `$kpi_jo_count`
    - "Merchandise Released (pcs)" → `$kpi_merch_released`
    - "Total Amount Encoded" → `₱ + number_format($kpi_total_encoded, 2)`
  - Panel starts hidden (`d-none`); no server-side persistence of toggle state
  - **Reference:** design.md §Component 5; requirements.md §Requirement 5 AC1–7
