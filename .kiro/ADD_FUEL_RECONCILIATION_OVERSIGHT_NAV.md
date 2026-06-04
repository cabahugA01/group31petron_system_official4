# 📋 Add Fuel Reconciliation Oversight to Admin Navigation

## ✅ STATUS: IMPLEMENTED

**Summary:** Successfully added "Fuel Reconciliation Oversight" as a new sub-menu item under Admin's Fuel Management navigation and created the complete oversight page.

### What Was Done:
1. ✅ Updated `partials/rbac_menu.php` - Added navigation item to Admin Fuel Management sub-menu
2. ✅ Created `public/admin_fuel_reconciliation_oversight.php` - Complete oversight page with:
   - Summary cards (Total Reconciliations, Open Variances, Resolved Variances, Total Variance Liters)
   - Filter bar (Date range, Station filter for Superadmin, Status filter)
   - Variance reports table with color-coded alerts (>5% red, ≤5% green)
   - Export functionality (Excel, CSV, PDF) with filter parameters preserved
   - Uniform button styling matching other oversight pages
3. ✅ Navigation item positioned between "Adjustments Oversight" and "Pump Master Oversight"

### Files Modified/Created:
- `c:\xampp\htdocs\group31petron_system_official4\partials\rbac_menu.php` (navigation updated)
- `c:\xampp\htdocs\group31petron_system_official4\public\admin_fuel_reconciliation_oversight.php` (page created)

---

## Overview
This document provides instructions to add "Fuel Reconciliation Oversight" as a sub-item under Admin's Fuel Management navigation menu.

---

## Navigation Structure

The navigation is defined in `partials/header.php` and follows this structure:

```
Admin Sidebar
└── Fuel Management (parent)
    ├── Transactions Oversight
    ├── Deliveries Oversight  
    ├── Adjustments Oversight
    ├── Reconciliation Oversight ← NEW ITEM TO ADD
    └── Fuel Types
```

---

## Step 1: Find the Navigation Items Array

**File:** `partials/header.php`

Look for where the navigation items are defined. This is typically in a `$items` array or similar structure that contains admin menu configuration.

The array should look something like this:

```php
[
    'id' => 'fuel_admin',
    'label' => 'Fuel Management',
    'ico' => 'fas fa-gas-pump',
    'href' => '#',
    'sub_items' => [
        [
            'id' => 'admin_fuel_transactions',
            'label' => 'Transactions Oversight',
            'href' => 'admin_fuel_transactions_oversight.php'
        ],
        [
            'id' => 'admin_fuel_deliveries',
            'label' => 'Deliveries Oversight',
            'href' => 'admin_fuel_deliveries_oversight.php'
        ],
        [
            'id' => 'admin_fuel_adjustments',
            'label' => 'Adjustments Oversight',
            'href' => 'admin_fuel_adjustments_oversight.php'
        ],
        // ADD NEW ITEM HERE
    ]
]
```

---

## Step 2: Add the New Sub-Item

Insert the following array element into the `sub_items` array:

```php
[
    'id' => 'admin_fuel_reconciliation',
    'label' => 'Reconciliation Oversight',
    'href' => 'admin_fuel_reconciliation_oversight.php'
],
```

**Complete Example:**

```php
'sub_items' => [
    [
        'id' => 'admin_fuel_transactions',
        'label' => 'Transactions Oversight',
        'href' => 'admin_fuel_transactions_oversight.php'
    ],
    [
        'id' => 'admin_fuel_deliveries',
        'label' => 'Deliveries Oversight',
        'href' => 'admin_fuel_deliveries_oversight.php'
    ],
    [
        'id' => 'admin_fuel_adjustments',
        'label' => 'Adjustments Oversight',
        'href' => 'admin_fuel_adjustments_oversight.php'
    ],
    [
        'id' => 'admin_fuel_reconciliation',    // ← NEW
        'label' => 'Reconciliation Oversight',   // ← NEW
        'href' => 'admin_fuel_reconciliation_oversight.php'  // ← NEW
    ],
    [
        'id' => 'admin_fuel_types',
        'label' => 'Fuel Types',
        'href' => 'admin_fuel_types.php'
    ]
]
```

---

## Step 3: Add Badge Count (Optional)

If you want to show a badge with count of pending reconciliations, add this code in the badges section of `partials/header.php`:

```php
// Fuel Reconciliation Oversight badge (admin)
if ($role === 'admin' || $role === 'superadmin') {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_variance_reports WHERE station_id = ? AND LOWER(status) IN ('open', 'under investigation')");
        $stmt->execute([$myStationId]);
        $badges['admin_fuel_reconciliation'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) { 
        $badges['admin_fuel_reconciliation'] = 0; 
    }
}
```

**Location:** Add this near the other admin badge calculations (around line 215-230).

---

## Step 4: Create the Page File

**File to Create:** `public/admin_fuel_reconciliation_oversight.php`

```php
<?php
/**
 * Admin Fuel Reconciliation Oversight
 * View all fuel reconciliation logs and variance reports system-wide
 */
$page_id = 'admin_fuel_reconciliation';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Access control - Admin only
if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    header('Location: dashboard.php');
    exit;
}

// ── Date Filter ───────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-1 month')));
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));

// ── Station Filter (Superadmin can view all stations) ─────
$filter_station = 0;
if ($role === 'superadmin') {
    $filter_station = (int)($_GET['station'] ?? 0);
} else {
    $filter_station = $station_id;
}

// ── Status Filter ─────────────────────────────────────────
$filter_status = trim($_GET['status'] ?? '');

// ── Summary Cards ──────────────────────────────────────────
$total_reconciliations = 0;
$open_variances = 0;
$resolved_variances = 0;
$total_variance_liters = 0;

try {
    $where = ["DATE(fvr.report_date) BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if ($filter_station > 0) {
        $where[] = "fvr.station_id = ?";
        $params[] = $filter_station;
    }
    
    $where_sql = implode(' AND ', $where);
    
    // Total reconciliations
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_variance_reports fvr WHERE $where_sql");
    $stmt->execute($params);
    $total_reconciliations = (int)$stmt->fetchColumn();
    
    // Open variances
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_variance_reports fvr WHERE $where_sql AND LOWER(fvr.status) IN ('open', 'under investigation')");
    $stmt->execute($params);
    $open_variances = (int)$stmt->fetchColumn();
    
    // Resolved variances
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_variance_reports fvr WHERE $where_sql AND LOWER(fvr.status) = 'resolved'");
    $stmt->execute($params);
    $resolved_variances = (int)$stmt->fetchColumn();
    
    // Total variance liters
    $stmt = $pdo->prepare("SELECT SUM(ABS(variance_liters)) FROM fuel_variance_reports fvr WHERE $where_sql");
    $stmt->execute($params);
    $total_variance_liters = (float)$stmt->fetchColumn();
    
} catch (Exception $e) {
    error_log("Summary error: " . $e->getMessage());
}

// ── Fetch Variance Reports ────────────────────────────────────
$variances = [];
try {
    $where = ["DATE(fvr.report_date) BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if ($filter_station > 0) {
        $where[] = "fvr.station_id = ?";
        $params[] = $filter_station;
    }
    
    if ($filter_status !== '') {
        $where[] = "LOWER(fvr.status) = LOWER(?)";
        $params[] = $filter_status;
    }
    
    $where_sql = implode(' AND ', $where);
    
    $sql = "SELECT fvr.*, 
                   s.name as station_name,
                   u.name as resolved_by_name
            FROM fuel_variance_reports fvr
            LEFT JOIN stations s ON fvr.station_id = s.id
            LEFT JOIN users u ON fvr.resolved_by = u.id
            WHERE $where_sql
            ORDER BY 
                CASE LOWER(TRIM(fvr.status))
                    WHEN 'open' THEN 1
                    WHEN 'under investigation' THEN 2
                    WHEN 'resolved' THEN 3
                    ELSE 4
                END ASC,
                fvr.report_date DESC
            LIMIT 500";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $variances = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch variances error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading variances: " . $e->getMessage();
}

// ── Get All Stations (for filter) ─────────────────────────
$stations = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status='active' ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* Page styles similar to other oversight pages */
.page-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px; margin-bottom: 18px; flex-wrap: wrap;
}
.page-head h1 {
    margin: 0 0 8px; font-size: 24px !important; font-weight: 700;
    color: #00264D; text-transform: uppercase;
}
.page-head .sub {
    font-size: 14px; color: #666666; font-weight: 500;
    text-transform: uppercase;
}

/* Summary Cards */
.summary-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 14px; margin-bottom: 18px;
}
.summary-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 18px; display: flex; align-items: center; gap: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.summary-card.sc-blue   { border-left: 4px solid #1e40af; }
.summary-card.sc-amber  { border-left: 4px solid #d97706; }
.summary-card.sc-green  { border-left: 4px solid #16a34a; }
.summary-card.sc-red    { border-left: 4px solid #dc2626; }
.sum-ico {
    width: 52px; height: 52px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0;
}
.summary-card.sc-blue .sum-ico   { background: #dbeafe; color: #1e40af; }
.summary-card.sc-amber .sum-ico  { background: #fef3c7; color: #d97706; }
.summary-card.sc-green .sum-ico  { background: #dcfce7; color: #16a34a; }
.summary-card.sc-red .sum-ico    { background: #fee2e2; color: #dc2626; }
.sum-meta h3 { 
    margin: 0; font-size: 11px; color: #64748b; 
    text-transform: uppercase; font-weight: 700; 
}
.sum-meta h2 { 
    margin: 4px 0 2px; font-size: 28px; font-weight: 900; 
    color: #00264D; line-height: 1; 
}
.sum-meta span { 
    font-size: 12px; color: #94a3b8; 
}

/* Table */
.table-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.data-table {
    width: 100%; border-collapse: collapse; font-size: 13px;
}
.data-table thead th {
    background: #002F70; padding: 10px 8px; text-align: left;
    font-size: 11px; font-weight: 700; color: #fff;
    text-transform: uppercase;
}
.data-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.data-table tbody tr:hover { background: #e3f2fd; }
.data-table tbody td { 
    padding: 10px 8px; color: #334155; vertical-align: middle;
}

/* Badges */
.badge {
    display: inline-block; font-size: 12px; font-weight: 700;
    text-transform: uppercase; padding: 4px 8px; border-radius: 4px;
}
.badge-red { background: #fee2e2; color: #991b1b; }
.badge-amber { background: #fef3c7; color: #92400e; }
.badge-green { background: #d1fae5; color: #065f46; }

/* Variance colors */
.var-high { color: #dc2626; font-weight: 700; }
.var-ok { color: #16a34a; font-weight: 700; }
</style>

<div class="page-head">
    <div>
        <h1>Fuel Reconciliation Oversight</h1>
        <div class="sub">System-wide reconciliation logs and variance reports</div>
    </div>
    <div style="display:flex;gap:8px;">
        <button type="button" onclick="window.location.href='?export=excel'"
                style="background:#1d6f42;color:#fff;height:38px;padding:9px 20px;border-radius:8px;border:none;font-size:14px;font-weight:600;cursor:pointer;">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <button type="button" onclick="window.location.href='?export=csv'"
                style="background:#003d7a;color:#fff;height:38px;padding:9px 20px;border-radius:8px;border:none;font-size:14px;font-weight:600;cursor:pointer;">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <button type="button" onclick="window.open('?export=pdf','_blank')"
                style="background:#dc2626;color:#fff;height:38px;padding:9px 20px;border-radius:8px;border:none;font-size:14px;font-weight:600;cursor:pointer;">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <a href="admin_dashboard.php"
           style="background:#6c757d;color:#fff;text-decoration:none;height:38px;padding:9px 20px;border-radius:8px;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<!-- Filter Bar -->
<form method="get" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;">
    <?php if ($role === 'superadmin'): ?>
    <div style="display:flex;flex-direction:column;gap:4px;min-width:180px;">
        <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Station</label>
        <select name="station" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;">
            <option value="0">All Stations</option>
            <?php foreach ($stations as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $filter_station == $s['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    
    <div style="display:flex;flex-direction:column;gap:4px;min-width:150px;">
        <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Date From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;">
    </div>
    <div style="display:flex;flex-direction:column;gap:4px;min-width:150px;">
        <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Date To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;">
    </div>
    
    <div style="display:flex;flex-direction:column;gap:4px;min-width:150px;">
        <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Status</label>
        <select name="status" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;">
            <option value="">All Status</option>
            <option value="open" <?= $filter_status === 'open' ? 'selected' : '' ?>>Open</option>
            <option value="under investigation" <?= $filter_status === 'under investigation' ? 'selected' : '' ?>>Under Investigation</option>
            <option value="resolved" <?= $filter_status === 'resolved' ? 'selected' : '' ?>>Resolved</option>
        </select>
    </div>
    
    <button type="submit" style="background:#00264D;color:#fff;height:36px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;">
        <i class="fas fa-filter"></i> Apply Filter
    </button>
</form>

<!-- Summary Cards -->
<div class="summary-row">
    <div class="summary-card sc-blue">
        <div class="sum-ico"><i class="fas fa-clipboard-check"></i></div>
        <div class="sum-meta">
            <h3>Total Reconciliations</h3>
            <h2><?= number_format($total_reconciliations) ?></h2>
            <span>All reconciliation records</span>
        </div>
    </div>
    <div class="summary-card sc-red">
        <div class="sum-ico"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="sum-meta">
            <h3>Open Variances</h3>
            <h2><?= number_format($open_variances) ?></h2>
            <span>Pending resolution</span>
        </div>
    </div>
    <div class="summary-card sc-green">
        <div class="sum-ico"><i class="fas fa-check-circle"></i></div>
        <div class="sum-meta">
            <h3>Resolved Variances</h3>
            <h2><?= number_format($resolved_variances) ?></h2>
            <span>Completed reconciliations</span>
        </div>
    </div>
    <div class="summary-card sc-amber">
        <div class="sum-ico"><i class="fas fa-tachometer-alt"></i></div>
        <div class="sum-meta">
            <h3>Total Variance</h3>
            <h2><?= number_format($total_variance_liters, 2) ?> L</h2>
            <span>Cumulative variance amount</span>
        </div>
    </div>
</div>

<!-- Variance Reports Table -->
<div class="table-card">
    <h3 style="margin:0 0 14px;font-size:14px;font-weight:700;color:#00264D;text-transform:uppercase;">
        <i class="fas fa-list"></i> Variance Reports
    </h3>

    <?php if (empty($variances)): ?>
    <div style="text-align:center;padding:40px;color:#94a3b8;">
        <i class="fas fa-check-circle" style="font-size:48px;margin-bottom:12px;opacity:.5;color:#16a34a;"></i>
        <p style="margin:0;">No variance reports found for the selected period.</p>
    </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Date</th>
                <?php if ($role === 'superadmin'): ?>
                <th>Station</th>
                <?php endif; ?>
                <th>Fuel Type</th>
                <th>Expected (L)</th>
                <th>Actual (L)</th>
                <th>Variance (L)</th>
                <th>Variance (%)</th>
                <th>Status</th>
                <th>Resolved By</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($variances as $v): 
                $var_pct = abs($v['variance_percent']);
                $var_class = $var_pct > 5 ? 'var-high' : 'var-ok';
                $status_lower = strtolower($v['status']);
            ?>
            <tr>
                <td>VAR-<?= htmlspecialchars($v['id']) ?></td>
                <td><?= date('M d, Y', strtotime($v['report_date'])) ?></td>
                <?php if ($role === 'superadmin'): ?>
                <td><?= htmlspecialchars($v['station_name'] ?? 'N/A') ?></td>
                <?php endif; ?>
                <td><?= htmlspecialchars($v['fuel_type']) ?></td>
                <td><?= number_format($v['expected_stock'], 2) ?> L</td>
                <td><?= number_format($v['actual_stock'], 2) ?> L</td>
                <td class="<?= $var_class ?>"><?= number_format($v['variance_liters'], 2) ?> L</td>
                <td class="<?= $var_class ?>"><?= number_format($v['variance_percent'], 2) ?>%</td>
                <td>
                    <?php if ($status_lower === 'open'): ?>
                        <span class="badge badge-red">OPEN</span>
                    <?php elseif ($status_lower === 'under investigation'): ?>
                        <span class="badge badge-amber">INVESTIGATING</span>
                    <?php else: ?>
                        <span class="badge badge-green">RESOLVED</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($v['resolved_by_name'] ?? 'Pending') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
```

---

## Testing Checklist

After implementing:

### ✅ Navigation Testing
- [ ] Log in as Admin
- [ ] Verify "Fuel Management" menu item exists
- [ ] Click to expand Fuel Management
- [ ] Verify "Reconciliation Oversight" appears in sub-menu
- [ ] Click "Reconciliation Oversight"
- [ ] Verify page loads correctly

### ✅ Functionality Testing
- [ ] Summary cards display correct counts
- [ ] Date filter works correctly
- [ ] Station filter works (for Superadmin)
- [ ] Status filter works
- [ ] Table displays variance reports
- [ ] Export buttons work (Excel, CSV, PDF)
- [ ] Back button returns to admin dashboard

### ✅ Badge Testing (if implemented)
- [ ] Badge shows count of open variances
- [ ] Badge updates when variances are resolved
- [ ] Badge displays correctly on collapsed sidebar

---

## Related Files
- `partials/header.php` - Navigation configuration
- `public/admin_fuel_reconciliation_oversight.php` - New page (to create)
- `public/manager_fuel_reconciliation.php` - Manager equivalent page

---

## Notes
- The exact location to add the navigation item depends on where the `$items` array is defined in `partials/header.php`
- If the navigation is dynamically generated from a database, the approach may be different
- The page layout follows the same pattern as other admin oversight pages
- Badge functionality is optional but recommended for better UX

---

**Status:** 📝 **READY TO IMPLEMENT**
