# Design Document: Staff Customer Sidebar

## Overview

This feature converts the flat "Customers" sidebar entry in `includes/staff_sidebar.php` into a collapsible sub-menu with four direct-navigation links, each pointing to `customers.php?tab={tab_name}`. It also modifies `public/customers.php` to:

1. Read the active tab from `$_GET['tab']` instead of `window.location.hash`
2. Unlock the Outstanding Balances tab for staff roles as a read-only view
3. Rebuild the Transaction Linkage tab to show both Job Orders and Merchandise transactions in two labelled sections, filtered by `station_id`
4. Highlight the correct sidebar sub-item based on the active tab

No new pages, no new database tables, and no changes to existing business logic beyond the role gate on the Outstanding Balances tab.

---

## Architecture

The feature touches two files only:

```
includes/staff_sidebar.php   ← PHP array config + rendering loop + JS
public/customers.php         ← PHP data fetching + HTML tabs + JS init
```

The sidebar is rendered server-side by `getStaffSidebar($current_page)`. The `$current_page` value is set by each page that includes the sidebar (e.g. `$page_id = 'customers'`). The sub-item active state requires a second signal — the `?tab=` query parameter — which must be passed into the sidebar function or read from `$_GET` inside it.

Tab activation in `customers.php` is currently driven by `window.location.hash` in a `DOMContentLoaded` handler. This will be replaced by a PHP-resolved tab name injected into the JS as a literal string.

```
Request: customers.php?tab=balances
  │
  ├─ PHP resolves $active_tab = 'balances'
  ├─ PHP fetches data appropriate for role + tab
  ├─ PHP renders HTML (tab buttons, tab panels, sidebar)
  │    └─ sidebar receives ($current_page='customers', $active_tab='balances')
  │         └─ highlights balances sub-item, auto-expands submenu
  └─ PHP injects $active_tab into JS: switchTab('balances')
```

---

## Components and Interfaces

### 1. `includes/staff_sidebar.php` — Sidebar Array and Rendering

**Change: customers entry → sub-menu entry**

The `customers` key in the `$sidebar` array changes from a flat link to a sub-menu entry matching the pattern of `fuel_management` and `inventory`:

```php
'customers' => [
    'icon' => 'fas fa-users',
    'title' => 'Customers',
    'url' => '#',
    'description' => 'Encode, update, track balances, link transactions',
    'submenu' => [
        'customer_encode' => [
            'icon' => 'fas fa-user-plus',
            'title' => 'Encode Customer Details',
            'url' => 'customers.php?tab=encode',
        ],
        'customer_update' => [
            'icon' => 'fas fa-user-edit',
            'title' => 'Update Customer Details',
            'url' => 'customers.php?tab=update',
        ],
        'customer_linkage' => [
            'icon' => 'fas fa-link',
            'title' => 'Transaction Linkage',
            'url' => 'customers.php?tab=linkage',
        ],
        'customer_balances' => [
            'icon' => 'fas fa-wallet',
            'title' => 'Outstanding Balances',
            'url' => 'customers.php?tab=balances',
        ],
    ]
],
```

**Change: `getStaffSidebar` signature**

The function gains an optional second parameter for the active tab:

```php
function getStaffSidebar($current_page = 'dashboard', $active_tab = '')
```

**Change: sub-item active highlighting**

Inside the submenu rendering loop, each sub-item link gets an `active` class when the sub-item's tab key matches `$active_tab`:

```php
// Map sub-item keys to their tab names
$tabMap = [
    'customer_encode'   => 'encode',
    'customer_update'   => 'update',
    'customer_linkage'  => 'linkage',
    'customer_balances' => 'balances',
];
$subActive = (isset($tabMap[$subKey]) && $tabMap[$subKey] === $active_tab) ? ' active' : '';
echo '<a href="' . $subItem['url'] . '" class="' . trim($subActive) . '">';
```

**Change: auto-expand customers submenu**

In the JS `DOMContentLoaded` block, after the existing toggle handler is set up, add auto-expansion logic:

```javascript
// Auto-expand the customers submenu when on customers page
const customersSubmenu = document.getElementById('submenu-customers');
if (customersSubmenu) {
    const isCustomersPage = <?php echo json_encode($current_page === 'customers'); ?>;
    if (isCustomersPage) {
        customersSubmenu.style.display = 'block';
        const customersLi = customersSubmenu.closest('li');
        if (customersLi) customersLi.classList.add('submenu-open');
    }
}
```

The `$current_page` variable is already in scope because the JS block is inside the PHP function.

---

### 2. `public/customers.php` — Tab Resolution (PHP)

**Change: resolve `$active_tab` from `$_GET['tab']`**

Add this block after the role check, before data fetching:

```php
$valid_tabs = ['encode', 'update', 'balances', 'linkage', 'transparency'];
$active_tab = isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs)
    ? $_GET['tab']
    : 'encode';

// Staff roles cannot access transparency tab
if (in_array($role, ['staff', 'cashier', 'pump_attendant']) && $active_tab === 'transparency') {
    $active_tab = 'encode';
}
```

The resolved `$active_tab` is passed to the sidebar include and injected into JS.

---

### 3. `public/customers.php` — Outstanding Balances Tab (PHP)

**Change: unlock tab button for staff roles**

Remove the `<?php if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>` wrapper around the balances tab button. The button is now always rendered for all roles that can access `customers.php`.

**Change: role-conditional tab panel rendering**

The `balances-tab` div is split into two branches:

- **Staff branch** (read-only): fetches from `accounts_receivable` joined with `customers`, displays a table with Customer ID, Name, Balance Amount, Due Date. No payment form, no action buttons.
- **Manager branch** (existing): unchanged — full table with payment buttons and the hidden payment form.

**Staff read-only query:**

```php
if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    $stmt = $pdo->prepare("
        SELECT ar.id, ar.customer_id, c.name AS customer_name,
               ar.amount AS balance_amount, ar.due_date
        FROM accounts_receivable ar
        LEFT JOIN customers c ON ar.customer_id = c.id
        WHERE ar.station_id = ? AND ar.status = 'Pending'
        ORDER BY ar.due_date ASC
    ");
    $stmt->execute([$station_id]);
    $staff_receivables = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

**Staff read-only HTML structure:**

```html
<div id="balances-tab" class="tab-content">
  <div class="customer-card">
    <h2>Outstanding Balances</h2>
    <!-- Staff view: read-only notice -->
    <div class="alert-info">Read-only view. Contact your manager to record payments.</div>
    <table class="customer-table">
      <thead>
        <tr>
          <th>Customer ID</th>
          <th>Customer Name</th>
          <th>Balance Amount</th>
          <th>Due Date</th>
        </tr>
      </thead>
      <tbody>
        <!-- rows from $staff_receivables -->
      </tbody>
    </table>
  </div>
</div>
```

The manager branch retains the existing full table and payment form exactly as-is.

---

### 4. `public/customers.php` — Transaction Linkage Tab (PHP)

**Change: rebuild linkage tab with customer selector and two sections**

The current linkage tab shows a static info grid and the `$receivables` data (manager-only). This is replaced with:

1. A customer selector `<select>` populated from `$customers` (already fetched for all roles)
2. Two data sections loaded via AJAX when a customer is selected, or pre-loaded server-side on page load if `$_GET['customer_id']` is present

**Design decision: server-side pre-load + AJAX refresh**

To keep the implementation simple and consistent with the existing page (no separate API files are used by `customers.php`), the linkage data is fetched server-side when `$_GET['customer_id']` is set, and the customer selector triggers a page reload with `?tab=linkage&customer_id={id}`. This avoids introducing a new AJAX endpoint while still providing the two-section display.

```php
$linkage_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;
$job_orders_linked = [];
$merchandise_linked = [];

if ($linkage_customer_id) {
    // Job Orders
    $stmt = $pdo->prepare("
        SELECT id, job_order_id, service_type, created_at, status
        FROM job_orders
        WHERE station_id = ?
          AND (customer_id = ? OR credit_customer_id = ?)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$station_id, $linkage_customer_id, $linkage_customer_id]);
    $job_orders_linked = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Merchandise Transactions
    $stmt = $pdo->prepare("
        SELECT id, customer_name, total_amount,
               COALESCE(transaction_date, created_at) AS txn_date, status
        FROM merchandise_transactions
        WHERE station_id = ? AND customer_id = ?
        ORDER BY txn_date DESC
    ");
    $stmt->execute([$station_id, $linkage_customer_id]);
    $merchandise_linked = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

**Linkage tab HTML structure:**

```html
<div id="linkage-tab" class="tab-content">
  <div class="customer-card">
    <h2>Transaction Linkage</h2>

    <!-- Customer selector -->
    <form method="get" action="customers.php">
      <input type="hidden" name="tab" value="linkage">
      <div class="form-group">
        <label class="form-label">Select Customer</label>
        <select name="customer_id" class="form-select" onchange="this.form.submit()">
          <option value="">-- Select a customer --</option>
          <!-- options from $customers, selected if $linkage_customer_id matches -->
        </select>
      </div>
    </form>

    <!-- Section 1: Job Orders -->
    <h3>Job Orders</h3>
    <!-- table or "No job orders found" message -->

    <!-- Section 2: Merchandise Transactions -->
    <h3>Merchandise Transactions</h3>
    <!-- table or "No merchandise transactions found" message -->
  </div>
</div>
```

---

### 5. `public/customers.php` — JS Tab Initialization

**Change: replace hash-based init with PHP-injected tab**

Remove the `window.location.hash` logic from `DOMContentLoaded`. Replace with:

```javascript
document.addEventListener('DOMContentLoaded', function () {
    const activeTab = <?php echo json_encode($active_tab); ?>;
    const buttons = Array.from(document.querySelectorAll('.customer-tabs .tab-btn'));
    const activeButton = buttons.find(btn =>
        btn.getAttribute('onclick')?.includes("'" + activeTab + "'")
    );
    switchTab(activeTab, activeButton || buttons[0] || null);
});
```

The `switchTab` function itself does not need to change. The `history.replaceState` call inside it that currently writes a hash can be removed or left harmless — it will write `#encode` etc. but the page no longer reads it on load, so it has no effect.

**Change: sidebar include call**

On the line where `partials/header.php` is included (which in turn includes the sidebar), pass `$active_tab` to the sidebar. Since `header.php` calls `getStaffSidebar($page_id)`, the call site in `header.php` (or the direct include in `customers.php`) must be updated to pass the second argument. The cleanest approach is to set a global before the include:

```php
// In customers.php, before include header.php:
$sidebar_active_tab = $active_tab;
```

Then in `includes/staff_sidebar.php`, read `$sidebar_active_tab` from the calling scope, or change `header.php` to pass it. The preferred approach is to update `getStaffSidebar` to accept the second parameter and update the call in `header.php` to pass `$sidebar_active_tab ?? ''`.

---

## Data Models

No new tables. Existing tables used:

| Table | Columns used | Purpose |
|---|---|---|
| `customers` | `id`, `name`, `contact_number`, `id_number`, `credit_limit`, `balance`, `station_id`, `status` | Customer selector, update form |
| `accounts_receivable` | `id`, `customer_id`, `amount`, `due_date`, `status`, `station_id` | Outstanding Balances (both roles) |
| `job_orders` | `id`, `job_order_id`, `service_type`, `created_at`, `status`, `station_id`, `customer_id`, `credit_customer_id` | Transaction Linkage — Job Orders section |
| `merchandise_transactions` | `id`, `customer_id`, `customer_name`, `total_amount`, `transaction_date`/`created_at`, `status`, `station_id` | Transaction Linkage — Merchandise section |

**Note on `job_orders` customer column:** The requirements summary notes the column may be `customer_id` or `credit_customer_id`. The query uses `OR` across both to handle either schema variant without a migration.

**Note on `merchandise_transactions` date column:** The query uses `COALESCE(transaction_date, created_at)` to handle either column name being present.

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Tab Resolution Correctness

*For any* string value passed as the `tab` query parameter, the resolved `$active_tab` SHALL equal that value if it is a member of the valid tab set `{encode, update, balances, linkage, transparency}`, and SHALL equal `'encode'` for any other string (including empty string and null).

**Validates: Requirements 2.1, 2.2, 2.3**

---

### Property 2: Staff Role Access to Balances Tab

*For any* role value in `{staff, cashier, pump_attendant}`, the rendered HTML of `customers.php` SHALL contain both the Outstanding Balances tab button in the tab bar and the `balances-tab` content panel.

**Validates: Requirements 3.1, 3.2**

---

### Property 3: Balances Data Filtering Correctness

*For any* collection of `accounts_receivable` records with varying `status` values and `station_id` values, the staff Outstanding Balances query SHALL return only records where `status = 'Pending'` AND `station_id` matches the authenticated user's station.

**Validates: Requirements 3.6**

---

### Property 4: Sub-Item Active Highlighting

*For any* valid tab value in `{encode, update, linkage, balances}`, when `getStaffSidebar('customers', $tab)` is called, the rendered HTML SHALL apply the `active` CSS class to exactly the sub-item whose URL contains `?tab={$tab}`, and SHALL NOT apply it to any other sub-item.

**Validates: Requirements 5.2, 5.3, 5.4, 5.5**

---

### Property 5: Parent Active Class Exclusivity

*For any* page key value, `getStaffSidebar($page_key)` SHALL apply the `active` CSS class to the customers parent `<li>` if and only if `$page_key === 'customers'`.

**Validates: Requirements 1.8, 5.1**

---

### Property 6: Transaction Linkage Station Filtering

*For any* collection of `job_orders` and `merchandise_transactions` records with varying `station_id` values, the Transaction Linkage queries SHALL return only records where `station_id` matches the authenticated user's station, regardless of which `customer_id` is selected.

**Validates: Requirements 4.1, 4.2, 4.6**

---

## Error Handling

| Scenario | Handling |
|---|---|
| `?tab=` value not in valid set | Silently fall back to `encode` tab (Requirement 2.3) |
| `?tab=transparency` for staff role | Silently fall back to `encode` tab |
| `?customer_id=` not an integer | Cast to `(int)` — results in 0, queries return empty sets, empty-state messages shown |
| `?customer_id=` belongs to a different station | `station_id` filter in both queries prevents cross-station data leakage |
| `job_orders` table missing `credit_customer_id` column | The `OR` condition degrades gracefully — only `customer_id` matches; no error |
| `merchandise_transactions` missing `transaction_date` column | `COALESCE(transaction_date, created_at)` falls back to `created_at` |
| `accounts_receivable` empty for station | Empty `$staff_receivables` array — "No outstanding balances found" message shown |
| PDO exception during data fetch | Existing `try/catch` block logs the error and leaves arrays empty; page renders with empty states |

---

## Testing Strategy

This feature involves PHP conditional rendering, SQL query filtering, and JavaScript initialization. Property-based testing applies to the tab resolution logic and the SQL filtering logic (both are pure functions over their inputs). UI rendering checks are example-based.

### Unit / Example Tests

- **Sidebar array structure**: assert the `customers` entry has a `submenu` key with exactly 4 children, each with the correct `url`, `icon`, and `title`.
- **Sub-item URLs**: assert each sub-item href equals the expected `customers.php?tab={name}` value.
- **Auto-expand**: render `getStaffSidebar('customers')` and assert the customers submenu has `display:block` or the `submenu-open` class in the output.
- **Staff read-only balances**: render `customers.php` with a staff role and assert the payment form div and "Record Payment" buttons are absent from the balances tab.
- **Manager full balances**: render with manager role and assert the payment form is present.
- **Linkage empty states**: render with `$job_orders_linked = []` and assert "No job orders found" is present; same for merchandise.
- **Linkage two sections**: render with a selected customer and assert both "Job Orders" and "Merchandise Transactions" section headings are present.
- **Default tab**: assert `resolveTab(null)` returns `'encode'`.
- **JS init**: assert the rendered page contains `switchTab(<?php echo json_encode($active_tab); ?>)` in the DOMContentLoaded handler.

### Property-Based Tests

Use a PHP property-based testing library (e.g. [eris](https://github.com/giorgiosironi/eris)) or a simple generator loop for the PHP logic, and [fast-check](https://github.com/dubzzz/fast-check) for the JS tab resolution.

**Property 1 — Tab resolution** (`resolveTab` function extracted from the PHP logic):
- Generator: arbitrary strings (including empty, whitespace, SQL injection attempts, valid tab names)
- Assertion: result is in valid set iff input is in valid set; otherwise result is `'encode'`
- Minimum 100 iterations
- Tag: `Feature: staff-customer-sidebar, Property 1: tab resolution correctness`

**Property 2 — Staff role access** (render function or template output):
- Generator: role values from `{staff, cashier, pump_attendant}`
- Assertion: rendered HTML contains balances tab button and balances-tab div
- Tag: `Feature: staff-customer-sidebar, Property 2: staff role access to balances tab`

**Property 3 — Balances data filtering** (SQL query or repository function):
- Generator: collections of AR records with random `status` (`Pending`, `Paid`, `Overdue`) and random `station_id` values
- Assertion: returned records all have `status = 'Pending'` and `station_id = $user_station_id`
- Minimum 100 iterations
- Tag: `Feature: staff-customer-sidebar, Property 3: balances data filtering correctness`

**Property 4 — Sub-item active highlighting** (sidebar render function):
- Generator: tab values from `{encode, update, linkage, balances}`
- Assertion: exactly one sub-item has the `active` class, and it is the one matching the input tab
- Minimum 100 iterations (over the 4-value set with random selection)
- Tag: `Feature: staff-customer-sidebar, Property 4: sub-item active highlighting`

**Property 5 — Parent active class** (sidebar render function):
- Generator: arbitrary page key strings
- Assertion: customers parent `<li>` has `active` class iff input equals `'customers'`
- Minimum 100 iterations
- Tag: `Feature: staff-customer-sidebar, Property 5: parent active class exclusivity`

**Property 6 — Transaction linkage station filtering** (SQL query or repository function):
- Generator: collections of job_orders and merchandise_transactions with random `station_id` values; a fixed `$user_station_id`
- Assertion: all returned records have `station_id = $user_station_id`
- Minimum 100 iterations
- Tag: `Feature: staff-customer-sidebar, Property 6: transaction linkage station filtering`
