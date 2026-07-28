# Design Document: Remove Audit Trail & Calendar Reports from Compliance Reports Page

## Overview

This design describes the technical changes required to remove the Audit Trail Report and Calendar & Schedule Report from the Compliance Reports page, leaving Activity Logs as the sole report. The change affects two front-end pages (`admin_compliance_reports.php` and `manager_compliance_reports.php`), one backend API (`backend/api/admin_reports_audit_api.php`), and the navigation menu descriptor (`partials/rbac_menu.php`).

The removal is purely subtractive — no new database tables, schemas, routes, or external dependencies are introduced. All removed code is dead weight from the perspective of the kept feature (Activity Logs). The Superadmin Audit Trail page and all standalone Calendar pages are completely out of scope and must not be touched.

### Rationale Summary

- **Audit Trail Report**: duplicates information already visible in Activity Logs (same `audit_logs` + `activity_logs` sources).
- **Calendar & Schedule Report**: calendar functionality is already available as a dedicated sidebar page for all roles, making its presence in Compliance Reports redundant and out of scope for a compliance-focused page.

---

## Architecture

The system is a PHP web application with a traditional server-rendered page model. Each Compliance Reports page:

1. Performs PHP server-side data fetching (SQL queries via PDO)
2. Renders HTML with embedded PHP
3. Loads a client-side XLSX library for Excel export
4. Uses a small amount of inline JavaScript for tab switching and export

The changes follow the existing architecture and introduce no new patterns.

```
Browser  →  public/admin_compliance_reports.php  →  MySQL (activity_logs, audit_logs)
             public/manager_compliance_reports.php
             backend/api/admin_reports_audit_api.php
             partials/rbac_menu.php
```

### Data Flow After Change

```
Browser
  └─ GET /admin_compliance_reports.php
       ├─ section=audit    → PHP redirect → section=activity (HTTP redirect or forced)
       ├─ section=calendar → PHP redirect → section=activity
       └─ section=activity (or any other value) → render Activity Logs only
```

---

## Components and Interfaces

### 1. `public/admin_compliance_reports.php`

**Current state:** Three tabs (Activity Logs, Audit Trail, Calendar & Schedule), three data-fetch blocks, three HTML panels, a `crTab()` JS function referencing all three sections, and a `crExport()` function with a `sheetNames` object containing `audit` and `calendar` keys.

**Target state:** One tab (Activity Logs), one data-fetch block (preserved), one HTML panel, simplified `crTab()` and `crExport()` functions.

**Changes required:**

| Area | Change |
|------|--------|
| `$section` validation | Replace `in_array(['activity','audit','calendar'])` with a redirect guard: if `$section` is `'audit'` or `'calendar'`, redirect to `?section=activity` (preserving `date_from`/`date_to`). Default fallback to `'activity'` for any unknown value. |
| `$valid_sections` array | Remove `'audit'` and `'calendar'`; only `'activity'` remains |
| `crAdminTrackerServiceWhere()` helper | Remove — only used by the calendar data-fetch block |
| `$audit_rows` data fetch | Remove both Source 1 (`audit_logs`) and Source 2 (`audit_trail`) queries |
| `$calendar_tasks` data fetch | Remove all three sub-queries (job orders, service tracker, fuel deliveries) |
| `$calendar_by_date` grouping | Remove |
| HTML tab bar | Remove Audit Trail and Calendar & Schedule `<button>` elements; keep only Activity Logs tab |
| HTML panels | Remove `#cr-panel-audit` div and all its contents; remove `#cr-panel-calendar` div and all its contents |
| JavaScript `crTab()` | Simplify — no longer needs to handle `'audit'` or `'calendar'` keys (function can remain for future use or be removed if only one tab exists) |
| JavaScript `crExport()` `sheetNames` | Remove `audit: 'Audit Trail'` and `calendar: 'Task List'` entries |
| Top-of-file comment | Update to state: "Activity Logs only. Audit Trail and Calendar & Schedule reports intentionally removed." |

### 2. `public/manager_compliance_reports.php`

**Current state:** Three tabs (Activity Logs, Audit Trail, Calendar & Schedule), three data-fetch blocks, helper function `crManagerTrackerServiceWhere()`, three HTML panels, `crSwitchSection()` JS function, `exportReport()` function.

**Target state:** One tab (Activity Logs), one data-fetch block, simplified JavaScript.

**Changes required:**

| Area | Change |
|------|--------|
| `$section` validation | Redirect to `?section=activity_logs` (preserving date params) when `$section` is `'audit_trail'` or `'calendar'`. Default fallback to `'activity_logs'` for any unknown value. |
| `$valid_sections` array | Remove `'audit_trail'` and `'calendar'`; only `'activity_logs'` remains |
| `crManagerTrackerServiceWhere()` helper | Remove — only used by the calendar data-fetch block |
| `$audit_rows` data fetch | Remove all three source queries (audit_logs, audit_trail, activity_logs grouped) |
| `$calendar_rows` data fetch | Remove all three sub-queries (job orders, service tracker, fuel deliveries) |
| HTML tab bar | Remove Audit Trail and Calendar & Schedule `<button>` elements |
| HTML panels | Remove `#cr-panel-audit_trail` div; remove `#cr-panel-calendar` div |
| Hidden input `#managerComplianceSection` | Update default `value` to `"activity_logs"` |
| JavaScript `crSwitchSection()` | Remove handling of `'audit_trail'` and `'calendar'` section keys |
| JavaScript `exportReport()` `sheetNames` | Remove `audit_trail` and `calendar` entries if present |
| Top-of-file comment | Update to state: "Activity Logs only. Audit Trail and Calendar & Schedule reports intentionally removed." |

### 3. `backend/api/admin_reports_audit_api.php`

**Current state:** Large `switch($action)` statement. Three cases related to compliance reports: `audit_trail`, `audit_summary`, `anomaly_detection`.

**Target state:** Same file, same structure — only those three `case` blocks removed.

**Changes required:**

| Case | Change |
|------|--------|
| `case 'audit_trail':` | Remove entire case block (~70 lines) |
| `case 'audit_summary':` | Remove entire case block (~8 lines) |
| `case 'anomaly_detection':` | Remove entire case block (~35 lines) |
| All other cases | Preserved exactly as-is |

The file's remaining cases (`sales_fuel`, `sales_merch`, `sales_daily_summary`, `job_orders`, `customer_balances`, `deliveries`, `staff_performance`, `variance_reports`, and any others) must be kept. No helper functions in this file are exclusive to the removed cases — the `safe_rows()`, `safe_val()`, `api_ok()`, `api_err()` helpers serve other cases and must stay.

### 4. `partials/rbac_menu.php`

**Current state:** Two description strings referencing the removed reports:
- Admin: `'desc' => 'Activity Logs, Audit Trail, Calendar & Schedule.'`
- Manager: `'desc' => 'Activity Logs, Audit Trail, Calendar & Schedule monitoring with validation and compliance tracking.'`

**Target state:**
- Admin: `'desc' => 'Activity Logs.'`
- Manager: `'desc' => 'Activity Logs monitoring with compliance tracking.'`

No other changes to menu structure, item IDs, hrefs, permissions, or any other menu configuration.

---

## Data Models

No database schema changes are required. The tables `audit_logs`, `audit_trail`, and `activity_logs` remain fully intact. Only the read queries that render the Audit Trail and Calendar tabs in the Compliance Reports pages are removed.

**Tables read by Activity Logs (preserved):**
- `audit_logs` — API-level actions (columns: `user_id`, `action_type`, `action_details`, `log_type`, `entity_type`, `entity_id`, `ip_address`, `status`, `created_at`)
- `activity_logs` — lib.php `log_activity()` calls (columns: `user_id`, `action`, `details`, `created_at`)
- `users` — JOIN for staff name and role

**Tables previously read by Audit Trail tab (removed from this page only):**
- `audit_logs` (also used by Activity Logs — not dropped)
- `audit_trail` (validation events)

**Tables previously read by Calendar tab (removed from this page only):**
- `job_orders`
- `merchandise_transactions`
- `fuel_deliveries`

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

This feature is primarily a removal/simplification of UI and server-side rendering logic. Most acceptance criteria are specific examples (presence/absence of HTML elements, redirect behavior for specific URLs, code cleanup verification). Two criteria are genuinely universal: the section validation fallback behavior for any possible `section` query parameter, and the date parameter preservation during redirects.

### Property 1: Invalid section defaults to Activity Logs (Admin)

*For any* string value passed as the `section` query parameter to `admin_compliance_reports.php` that is not `'activity'`, the page SHALL render the Activity Logs section as the active panel (never the Audit Trail or Calendar panel).

**Validates: Requirements 10.1, 10.3, 12.1, 12.2**

### Property 2: Invalid section defaults to Activity Logs (Manager)

*For any* string value passed as the `section` query parameter to `manager_compliance_reports.php` that is not `'activity_logs'`, the page SHALL render the Activity Logs section as the active panel (never the Audit Trail or Calendar panel).

**Validates: Requirements 10.2, 10.4, 12.3, 12.4**

### Property 3: Date parameters preserved during redirect

*For any* valid date range pair (`date_from`, `date_to`) and any deprecated section value, when `admin_compliance_reports.php` or `manager_compliance_reports.php` redirects to the Activity Logs section, the redirect target URL SHALL contain the original `date_from` and `date_to` values unchanged.

**Validates: Requirements 12.5**

---

## Error Handling

### Deprecated Section URL Parameters

When a request arrives with `section=audit`, `section=audit_trail`, or `section=calendar`, the server SHALL issue an HTTP redirect (302) to the same page with `section=activity` (admin) or `section=activity_logs` (manager), carrying through any `date_from` and `date_to` parameters. No error message is displayed to the user.

Implementation pattern (admin example):
```php
$deprecated_sections = ['audit', 'calendar'];
$section = $_GET['section'] ?? 'activity';
if (in_array($section, $deprecated_sections, true)) {
    $qs = http_build_query([
        'section'   => 'activity',
        'date_from' => $_GET['date_from'] ?? date('Y-m-01'),
        'date_to'   => $_GET['date_to']   ?? date('Y-m-d'),
    ]);
    header('Location: admin_compliance_reports.php?' . $qs);
    exit;
}
if ($section !== 'activity') {
    $section = 'activity';
}
```

Manager version uses `['audit_trail', 'calendar']` and redirects to `section=activity_logs`.

### Removed API Actions

If any client-side code (bookmarks, third-party scripts) calls `admin_reports_audit_api.php?action=audit_trail`, `audit_summary`, or `anomaly_detection` after these cases are removed, the `switch` falls through to the default case. The file currently does not have a default case, so PHP will return an empty response with HTTP 200. This is acceptable — the removed actions are only called from the audit trail tab which is being removed simultaneously. For defensive programming, consider adding a default case to the switch that returns a proper `api_err('Unknown action', 400)` if one does not already exist.

---

## Testing Strategy

This feature involves UI removal (tabs, panels), server-side PHP logic changes (section validation, data fetch removal), backend API case removal, and text updates. It does not involve complex business logic transformation, parsers, or serializers.

**Property-based testing is not the primary strategy here.** The feature is a targeted removal of known code blocks. The testable properties (Properties 1–3 above) are the exception: they cover the correctness of the section validation guard across all possible inputs.

### Unit / Example Tests

For each page (`admin_compliance_reports.php`, `manager_compliance_reports.php`):

| Test | What to verify |
|------|---------------|
| Default load | Page renders with Activity Logs panel active |
| `section=activity` (admin) / `section=activity_logs` (manager) | Activity Logs panel active, no other panels visible |
| `section=audit` / `section=audit_trail` | HTTP 302 redirect to Activity Logs URL |
| `section=calendar` | HTTP 302 redirect to Activity Logs URL |
| `section=<random_string>` | Activity Logs panel active (default fallback) |
| Audit Trail tab button | Not present in rendered HTML |
| Calendar tab button | Not present in rendered HTML |
| `#cr-panel-audit` / `#cr-panel-audit_trail` | Not present in rendered HTML |
| `#cr-panel-calendar` | Not present in rendered HTML |
| Activity Logs table | Renders rows correctly; count shown in footer |
| Export function | `sheetNames` object does not contain `audit` or `calendar` keys |
| Date parameters in redirect | `date_from` and `date_to` carried through redirect URL |

For `backend/api/admin_reports_audit_api.php`:

| Test | What to verify |
|------|---------------|
| `action=audit_trail` | Returns error (non-ok response or falls through switch) |
| `action=audit_summary` | Returns error |
| `action=anomaly_detection` | Returns error |
| `action=sales_fuel` | Still returns `ok: true` with data |
| `action=job_orders` | Still returns `ok: true` with data |

For `partials/rbac_menu.php`:

| Test | What to verify |
|------|---------------|
| Admin compliance reports sub-item desc | Equals `"Activity Logs."` |
| Manager compliance reports sub-item desc | Equals `"Activity Logs monitoring with compliance tracking."` |
| Superadmin Audit Trail sidebar item | Still present and intact |
| Calendar sidebar items (admin, manager) | Still present and intact |

### Property-Based Tests

**Property 1 & 2 — Section validation fallback**

Using a PHP property-based testing library (e.g., [eris/eris](https://github.com/giorgiosironi/eris) or a simple generator loop), generate arbitrary strings as `section` values and verify that the resolved `$section` variable is always `'activity'` (admin) or `'activity_logs'` (manager) unless the exact valid value is passed.

```php
// Feature: remove-audit-trail, Property 1 & 2: Invalid section defaults to Activity Logs
// Run 100+ iterations with random section strings
$valid = 'activity'; // or 'activity_logs' for manager
foreach (generate_random_strings(100) as $input) {
    $resolved = resolve_section($input, $valid);
    assert($resolved === $valid, "Expected '$valid', got '$resolved' for input '$input'");
}
```

**Property 3 — Date parameters preserved during redirect**

Generate random valid date pairs (date_from ≤ date_to, format YYYY-MM-DD) and deprecated section values, then verify the redirect URL contains both dates unchanged.

```php
// Feature: remove-audit-trail, Property 3: Date parameters preserved during redirect
foreach (generate_random_date_pairs(100) as [$from, $to]) {
    foreach (['audit', 'calendar'] as $deprecated_section) {
        $redirect_url = compute_redirect_url($deprecated_section, $from, $to);
        assert(str_contains($redirect_url, 'date_from=' . $from));
        assert(str_contains($redirect_url, 'date_to=' . $to));
    }
}
```

Minimum 100 iterations per property test.
Tag format: `// Feature: remove-audit-trail, Property {N}: {property_text}`

### Regression Checks

- Load `superadmin_audit_trail.php` — must render without change.
- Load `admin_calendar.php` and `manager_calendar.php` — must render without change.
- Verify sidebar menu for admin and manager roles — Calendar and Compliance Reports items still present; Compliance Reports description updated.
- Run existing export tests for Activity Logs (Excel, CSV, PDF) — must still work.
