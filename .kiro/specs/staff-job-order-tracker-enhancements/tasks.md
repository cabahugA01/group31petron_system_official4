# Implementation Tasks

## Staff Job Order Tracker — Missing Components

- [x] 1. Database Migration — New Columns
  - Added idempotent ALTER TABLE at page load in `staff_transactions_hub.php`
  - `due_date`, `balance_due` on `job_orders` ✓
  - `staff_remarks`, `manager_notes`, `due_date`, `inventory_deducted` on `merchandise_transactions` ✓

- [x] 2. Inventory Impact Column — PHP Data Layer
  - `$inv_impact` built in `staff_transactions_hub.php` (keyed by `{source}:{id}`)
  - Merchandise items: queries `merchandise_transaction_items` + `station_inventory`
  - Job orders: parses `required_parts` JSON + looks up `station_inventory`
  - Status: yes/no/pending/na based on `inventory_deducted` flag ✓

- [x] 3. Inventory Impact Column — HTML Render
  - Rendered as color-coded pill badges: ✓ Deducted (green), ✗ Not Yet (amber), ⏳ Pending (blue), — N/A (gray) ✓

- [x] 4. Receivables Tracker — due date POST handler
  - `jo_action = 'set_due_date'` case handled for both `job_orders` and `merchandise_transactions` ✓

- [x] 5. Receivables Tracker — HTML Render
  - Balance due, due date inline picker, ⚠ OVERDUE badge, overdue row highlight (red left border) ✓

- [x] 6. Validation Notes Separation — Schema-aware display
  - 📝 Staff box (gray) + ✅ Manager box (blue) rendered separately in tracker and merchandise history ✓
  - `manager_notes` written by `pending_transactions.php` on all 3 actions (approve/reject/adjust) ✓

- [x] 7. Variance Alerts — Detection PHP Logic
  - `$variance_alerts` built after `$job_orders` merge for both qty and amount mismatches ✓

- [x] 8. Variance Alerts — Header Badge
  - Red banner above tracker table + ⚠ N badge on Job Order Tracker tab button ✓

- [x] 9. Staff KPI Snapshot — PHP Data Query
  - `$kpi_jo_count`, `$kpi_merch_released`, `$kpi_total_encoded` computed from both tables ✓

- [x] 10. Staff KPI Snapshot — HTML Panel
  - Auto-expands when data exists today, toggle button with txn count badge ✓
