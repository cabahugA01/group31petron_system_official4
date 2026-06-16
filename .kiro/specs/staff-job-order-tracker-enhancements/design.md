# Design Document

## Staff Job Order Tracker — Missing Components

### Overview

This design adds five enhancements to `staff_transactions_hub.php` (merchandise section → Job Order Tracker):

1. **Inventory Impact Column** — shows deduction status per part
2. **Receivables Tracker** — adds due date + overdue indicator to pending payments
3. **Validation Notes Separation** — splits Staff remarks from Manager notes in display and storage
4. **Variance Alerts Notification** — header badge for quantity/amount mismatches
5. **Staff KPI Snapshot** — collapsible panel showing today's encoding stats

All changes are confined to `staff_transactions_hub.php` and a new migration file. No new PHP files are created unless strictly necessary. All DB alterations use `ALTER TABLE IF NOT EXISTS` / `COLUMN IF NOT EXISTS` patterns to stay idempotent.

---

## Schema Changes

### 1. `job_orders` table — already has `admin_remarks` (manager notes) and `notes` (staff notes). No new columns needed.

### 2. `merchandise_transactions` table — needs two new columns:

```sql
ALTER TABLE merchandise_transactions
  ADD COLUMN IF NOT EXISTS `staff_remarks`    TEXT DEFAULT NULL COMMENT 'Staff-entered notes',
  ADD COLUMN IF NOT EXISTS `manager_notes`    TEXT DEFAULT NULL COMMENT 'Manager validation notes',
  ADD COLUMN IF NOT EXISTS `due_date`         DATE DEFAULT NULL COMMENT 'Payment due date for receivables',
  ADD COLUMN IF NOT EXISTS `inventory_deducted` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = stock was deducted from station_inventory on approval';
```

> `remarks` already exists on `merchandise_transactions` — it will be treated as legacy and displayed alongside `staff_remarks` (whichever is non-empty) for backward compatibility. Going forward, writes go to `staff_remarks`.

### 3. `job_orders` table — needs two new columns:

```sql
ALTER TABLE job_orders
  ADD COLUMN IF NOT EXISTS `due_date`         DATE DEFAULT NULL COMMENT 'Payment due date',
  ADD COLUMN IF NOT EXISTS `balance_due`      DECIMAL(12,2) NOT NULL DEFAULT 0.00;
```

> `balance_due` may already exist on some installs; the migration uses `ADD COLUMN IF NOT EXISTS` so it's safe.

---

## Component Designs

### Component 1: Inventory Impact Column

**Data source:**
- For `job_orders` rows: parse `required_parts` JSON column (array of `{name, qty, unit_price, type}`) → for each part, look up `station_inventory` by product name match to determine if stock was deducted.
- For `merchandise_transactions` rows: query `merchandise_transaction_items` joined with `inventory_products` and `station_inventory`.
- Deduction indicator logic:
  - `validation_status = 'Approved'` **AND** `inventory_deducted = 1` → "Deducted: Yes ✓" (green)
  - `validation_status = 'Approved'` **AND** `inventory_deducted = 0` → "Deducted: No" (orange)
  - not yet Approved → "Pending" (grey)
  - no merchandise parts → "—"

**UI placement:** New column "Inv. Impact" inserted after the "Items/Parts" column in the tracker table. On narrow screens it collapses into the row detail (same pattern as existing columns).

**PHP implementation:** Inline within the `$job_orders` loop in the tracker HTML section. The stock check is a sub-query added to the existing `$stmt2` SELECT for `merchandise_transactions`.

---

### Component 2: Receivables Tracker

**Data source:**
- `payment_status` IN ('Pending Payment', 'Partial Payment', 'Unpaid')
- `due_date` column (new) on both `job_orders` and `merchandise_transactions`
- `balance_due` column on both tables

**UI placement:**
- In the Payment column of the tracker table, below the existing payment badge: show `Due: YYYY-MM-DD` in small text. If `due_date ≤ TODAY` and payment not settled → red "⚠ OVERDUE" badge.
- An inline edit icon (pencil) next to the due date lets staff set/update it via a small POST form (same pattern as `jo_action` POSTs already in the file).

**PHP implementation:**
- New `jo_action = 'set_due_date'` POST handler — updates `due_date` on the correct table row.
- Overdue detection is a simple PHP `date_diff(new DateTime($due_date), new DateTime('today'))` check during render.
- Running balance shown as `₱X.XX remaining` below the due date.

---

### Component 3: Validation Notes Separation

**Data source:**
- `job_orders`: `notes` = staff remarks, `admin_remarks` = manager notes (already exists, just not displayed separately)
- `merchandise_transactions`: `staff_remarks` (new) / `remarks` (legacy fallback) = staff, `manager_notes` (new) = manager

**UI placement:**
- Replace the single "Remarks" cell with a two-line layout:
  - `Staff: <text>` in dark grey
  - `Manager: <text>` in navy blue (only shown to roles with `manager`+ access, or read-only for staff)
- Staff can edit `staff_remarks` / `notes` inline; manager notes are read-only for staff.

**PHP implementation:**
- Existing `jo_action = 'update_remarks'` (if present) or new handler stores to the correct column based on `$role`.
- Display logic: `COALESCE(staff_remarks, remarks, '')` for staff note; `COALESCE(manager_notes, admin_remarks, '')` for manager note.

---

### Component 4: Variance Alerts Notification

**Variance detection logic (PHP, runs at page load for `$section === 'merchandise'`):**

```
For each active job order (status NOT IN ['Rejected','Cancelled','Completed']):
  A) Quantity variance:
     - Parse required_parts JSON or query merchandise_transaction_items
     - For each part with a product_id: compare encoded qty vs station_inventory.stock_level
     - If encoded_qty > stock_level → flag
  B) Amount variance:
     - Sum (quantity × unit_price) from items
     - Compare to total_amount on the record
     - If |sum - total_amount| > 0.01 → flag
```

**Storage:** Variance alerts are computed in-memory on each page load — not stored in DB. The result is a PHP array `$variance_alerts` containing `[jo_id, jo_source, type, message]`.

**UI — Header badge:**
- Count of flagged JOs is injected into the existing header partial via a PHP variable `$variance_alert_count`.
- In `partials/header.php`: add a small bell/warning icon that shows badge if `$variance_alert_count > 0`. Badge renders as a red circle with number.
- Clicking the icon opens a dropdown panel listing each flagged JO with its variance message.

**`partials/header.php` change:** Minimal — one conditional `<span>` badge added to the existing notification area.

---

### Component 5: Staff KPI Snapshot

**Data source (PHP, computed at page load):**

```sql
-- KPI Query: scoped to current user + station + today
SELECT
  COUNT(DISTINCT jo.id)  AS jo_count,
  COALESCE(SUM(
    CASE WHEN i.item_type != 'service' THEN i.quantity ELSE 0 END
  ), 0)                  AS merch_released,
  COALESCE(SUM(mt.total_amount), 0) AS total_encoded
FROM merchandise_transactions mt
LEFT JOIN merchandise_transaction_items i ON i.transaction_id = mt.id
WHERE mt.station_id = :station_id
  AND mt.staff_id   = :user_id
  AND DATE(mt.created_at) = CURDATE()
  AND mt.transaction_type IN ('job_order', 'combined')
```

Plus a second query for native `job_orders` table (where `created_by = :user_id`).

**UI placement:**
- A collapsible panel rendered above the Job Order Tracker table.
- Toggle button: "📊 My KPI Today" — clicking shows/hides via CSS class toggle (JS `classList.toggle`).
- Panel shows 3 stat cards: JOs Encoded, Merchandise Released (pcs), Total Amount Encoded (₱).
- State is not persisted — panel collapses on page reload (stateless toggle).

---

## File Change Map

| File | Change |
|---|---|
| `public/staff_transactions_hub.php` | All 5 feature additions (PHP data queries + HTML render) |
| `partials/header.php` | Add variance alert badge to notification area |
| `database/migrations/job_order_tracker_enhancements.sql` | New columns: `due_date`, `balance_due` on `job_orders`; `staff_remarks`, `manager_notes`, `due_date`, `inventory_deducted` on `merchandise_transactions` |

---

## Security & Compatibility Notes

- All new POST handlers use the existing `$jo_id` + `$station_id` WHERE guard — no row can be updated cross-station.
- Role check for manager notes write: `in_array($role, ['manager','admin','superadmin','developer'])`.
- All new columns use `ADD COLUMN IF NOT EXISTS` (MySQL 8.0+) — MariaDB equivalent fallback handled via try/catch.
- No new JavaScript dependencies. Toggle behavior uses vanilla JS already present in the file.
- `required_parts` JSON parsing uses `json_decode($val, true) ?? []` with null guard.
