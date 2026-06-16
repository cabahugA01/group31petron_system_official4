# Transaction Flow — Soft Validation (Staff / Manager / Admin)

## Overview

This feature enforces and completes the **three-tier soft validation flow** for all transaction types (Merchandise, Job Orders, Fuel) in the Petron Station Management System.

**Core principle:** Staff encoding is never blocked. Entries appear immediately in Staff views with a "Pending Validation" status. Manager can approve/reject anytime. Admin only sees validated data in consolidated reports and dashboards, with a complete, unified audit trail.

---

## Requirements

### REQ-1: Staff Soft Flow — Immediate Visibility After Encoding

**User Story:** As a Staff member, I want to see my encoded Job Orders and Merchandise transactions immediately in my Tracker/History, regardless of validation status, so my workflow is never blocked.

#### Acceptance Criteria

- [ ] 1.1 — When Staff encodes a Merchandise transaction, it immediately appears in Merchandise History (`staff_transactions_hub.php` History tab) with `validation_status = 'Pending'`.
- [ ] 1.2 — When Staff encodes a Job Order, it immediately appears in the Job Order Tracker with `validation_status = 'Pending Validation'`.
- [ ] 1.3 — Staff can see the current validation status badge (Pending / Approved / Rejected / Adjusted) on every row in their history/tracker.
- [ ] 1.4 — Staff cannot approve, reject, or change the validation status of their own transactions.
- [ ] 1.5 — Inventory stock is **not** deducted at the time of encoding — only on Manager approval.
- [ ] 1.6 — Staff history/tracker query must NOT filter out any validation status (all statuses visible to the encoding staff member).

---

### REQ-2: Manager Approval — Anytime, Non-Blocking

**User Story:** As a Manager, I want to see all pending Merchandise and Job Order transactions from my station's staff, and be able to approve, reject, or adjust them at any time, without disrupting staff operations.

#### Acceptance Criteria

- [ ] 2.1 — `pending_transactions.php` lists all Merchandise and Job Order records with `validation_status = 'Pending'` or `'Pending Validation'` for the Manager's station.
- [ ] 2.2 — Manager can **Approve** a group; on approval:
  - `validation_status` is set to `'Approved'`
  - `validated_by` (Manager's user ID) and `validated_at` (timestamp) are recorded
  - Merchandise stock is deducted from `station_inventory` per line item
  - Credit customer balance is updated in `customers` table and logged in `customer_credit_transactions` (if credit transaction)
  - An entry is written to **both** `audit_trail` and `audit_logs` (see REQ-5)
- [ ] 2.3 — Manager can **Reject** a group; on rejection:
  - `validation_status` is set to `'Rejected'`
  - `rejection_reason` is stored (or `remarks` prefixed with `REJECTED:` as fallback)
  - `validated_by` and `validated_at` are recorded
  - No stock changes (stock was not yet deducted)
  - Audit entry written
- [ ] 2.4 — Manager can **Adjust** items; on adjustment:
  - Line-item `quantity`, `unit_price`, `subtotal` in `merchandise_transaction_items` are updated
  - `total_amount` on the parent record is recalculated
  - `validation_status` is set to `'Adjusted'` (treated as approved for downstream purposes)
  - Audit entry written
- [ ] 2.5 — After any approval action, the Staff's tracker/history must reflect the updated status without requiring a page reload on the Manager's side.
- [ ] 2.6 — Manager **cannot** act on a transaction that is already `Approved`, `Adjusted`, or `Rejected` (guard must be in place).
- [ ] 2.7 — Fuel transactions remain handled separately via `manager_fuel_transaction_validation.php` (no change to fuel flow).

---

### REQ-3: Admin Oversight — Validated Data Only

**User Story:** As an Admin, I want to see only Manager-validated transactions in my reports and dashboard, so my KPIs reflect confirmed sales and services only.

#### Acceptance Criteria

- [ ] 3.1 — `admin_dashboard.php` KPI queries for `$merch_sales` and `$fuel_sales` must filter by validated statuses:
  - Merchandise: `validation_status IN ('Approved', 'Adjusted', 'Completed')`
  - Fuel: `status IN ('Verified', 'Completed')`
- [ ] 3.2 — `admin_transactions_oversight.php` already correctly filters by `IN ('approved', 'completed')` for merchandise — this must be extended to also include `'adjusted'` as a valid display status.
- [ ] 3.3 — Admin **cannot** approve a Merchandise transaction that is still `'Pending'` (existing guard) — this must be enforced for Job Orders as well (currently missing).
- [ ] 3.4 — Admin reports (`manager_reports.php` and related includes) must pass a `validation_status` filter so that unvalidated entries are excluded from sales totals.
- [ ] 3.5 — Admin dashboard KPI for merchandise items sold (`$merch_items_sold`) must count only validated transactions.

---

### REQ-4: Status Badge Display — All Views

**User Story:** As any user (Staff, Manager, Admin), I want every transaction row to clearly show the current validation and payment status so I always know where a transaction stands.

#### Acceptance Criteria

- [ ] 4.1 — Every transaction table row in Staff, Manager, and Admin views must display a `validation_status` badge with correct color coding:
  - `Pending` / `Pending Validation` → orange/amber (`#d97706`)
  - `Approved` / `Verified` → green (`#16a34a`)
  - `Adjusted` → purple (`#7c3aed`)
  - `Rejected` → red (`#dc2626`)
  - `Completed` → green (`#16a34a`)
- [ ] 4.2 — Staff Merchandise History `status` column must show `validation_status`, not `workflow_status`.
- [ ] 4.3 — Job Order Tracker must show both the operational status (`status`) and the validation status (`validation_status`) for each row.

---

### REQ-5: Unified Audit Trail

**User Story:** As an Admin/Manager, I want a complete audit trail showing both the Staff encoding event and the Manager approval/rejection event for every transaction, in a single place.

#### Acceptance Criteria

- [ ] 5.1 — All approval/rejection actions (Manager on Merchandise, Job Orders) must write to **`audit_logs`** with:
  - `user_id` = Manager's ID
  - `action_type` = `'Approve'` / `'Reject'` / `'Adjust'`
  - `entity_type` = `'merchandise_transaction'` or `'job_order'`
  - `entity_id` = record's numeric `id`
  - `action_details` = descriptive text including transaction reference, amount, reason
  - `station_id`
  - `created_at` = NOW()
- [ ] 5.2 — `approval_history.php` must be updated to query the correct table (`audit_logs`) filtering `action_type IN ('Approve', 'Reject', 'Adjust')` and `entity_type IN ('merchandise_transaction', 'job_order', 'fuel_transaction')` — removing the broken `log_type = 'approval'` filter.
- [ ] 5.3 — Each audit log entry in `approval_history.php` must display:
  - Transaction reference ID
  - Entity type (Merchandise / Job Order / Fuel)
  - Action (Approved / Rejected / Adjusted)
  - Manager name (from `users` using `first_name + last_name` or `username`)
  - Station name
  - Timestamp
  - Notes/reason (if rejection or adjustment)
- [ ] 5.4 — The existing `audit_trail` table inserts in `pending_transactions.php` and `admin_transactions_oversight.php` may be retained for backward compatibility, but `audit_logs` is the canonical source for `approval_history.php`.
- [ ] 5.5 — Staff encoding events must also be logged to `audit_logs` with `action_type = 'Encode'` at the time of INSERT (if not already present).

---

### REQ-6: Inventory Deduction Timing

**User Story:** As an Admin, I want inventory to only be deducted when a transaction is approved by a Manager, so rejected or pending transactions never affect stock levels.

#### Acceptance Criteria

- [ ] 6.1 — Merchandise transaction encoding (staff side) must NOT deduct `station_inventory.stock_level`.
- [ ] 6.2 — Merchandise approval (Manager side) must deduct `station_inventory.stock_level` per line item in `merchandise_transaction_items` where `item_type != 'service'`.
- [ ] 6.3 — Merchandise rejection must NOT change `station_inventory` (stock was never deducted).
- [ ] 6.4 — If a Manager adjusts quantities downward before approving, deduct only the adjusted (new) quantities, not the original.
- [ ] 6.5 — Fuel transaction inventory (`fuel_inventory`) deduction behavior is unchanged — fuel is deducted on encoding and restored on rejection (existing behavior).

---

### REQ-7: Reports Auto-Update on Approval

**User Story:** As an Admin, I want Reports and the Dashboard to automatically reflect new data whenever a Manager approves transactions, without manual refresh or recomputation.

#### Acceptance Criteria

- [ ] 7.1 — After Manager approval, the Admin Dashboard KPI numbers must change on next page load (no caching of stale query results).
- [ ] 7.2 — Manager Reports (shift reports, sales summaries) must exclude `Pending` and `Rejected` entries from totals.
- [ ] 7.3 — Admin Transactions Oversight must include `validation_status = 'Adjusted'` in its display filter alongside `'Approved'` and `'Completed'`.
- [ ] 7.4 — The `Cache-Control: no-store` headers already present in `staff_transactions_hub.php` must be applied to all pages that display transaction data (dashboard, oversight, reports).

---

## Out of Scope

- Real-time push notifications to Staff when their transaction is approved (deferred)
- Manager bulk-approving fuel transactions from the same page as Merchandise/JO (separate pages retained)
- Multi-station admin views (per-station scoping is sufficient)
