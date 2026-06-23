# Design Document

## Overview

The Admin Transactions Oversight Rebuild provides administrators with comprehensive oversight capabilities through TWO SEPARATE PAGES:

1. **admin_transactions_oversight.php** - Displays only manager-validated transactions (merchandise, job orders, and fuel) with unified filtering, search, and export capabilities
2. **admin_variance_reports.php** - NEW dedicated page for system-wide fuel variance reports, providing enterprise-level monitoring of inventory discrepancies across all stations

This architecture separates transaction oversight from variance monitoring, creating focused interfaces for each distinct oversight function. Both pages share common UI patterns for consistency but operate independently with their own data queries and filtering logic.

### Key Design Principles

1. **Separation of Concerns**: Transaction oversight and variance monitoring are separate workflows requiring separate interfaces
2. **No Unnecessary Tabs**: admin_transactions_oversight.php uses simple type filtering instead of tabs
3. **Dedicated Variance Interface**: admin_variance_reports.php provides focused variance analysis without mixing with transaction data
4. **Shared UI Patterns**: Both pages use consistent filter bars, pagination, and export functionality
5. **Dynamic Data Fetching**: All queries use parameter binding with no hardcoded values
6. **Role-Based Security**: Both pages restricted to admin/superadmin roles only

### Page Responsibilities

#### admin_transactions_oversight.php
- Display manager-validated transactions ONLY (excludes Pending)
- Support filtering by transaction type (All, Merchandise, Job Order, Fuel)
- Provide unified view of validated business transactions
- Export validated transaction data to Excel
- NO variance data or variance reports

#### admin_variance_reports.php (NEW)
- Display system-wide fuel variance reports
- Show statistical aggregations across all stations
- Support filtering by station, fuel type, and status
- Export variance report data to Excel
- NO transaction data

### Menu Structure

```
Admin Navigation
│
└─── Transactions (parent menu)
     │
     ├─── Oversight Dashboard → admin_transactions_oversight.php
     │    (Validated transactions with type filtering)
     │
     └─── Variance Reports (system-wide) → admin_variance_reports.php
          (System-wide fuel variance monitoring)
```

## Architecture

### High-Level System Structure

```
┌─────────────────────────────────────────────────────────────────┐
│                    Admin Oversight System                        │
│                  (Two Independent Pages)                         │
└─────────────────────────────────────────────────────────────────┘
                                 │
                ┌────────────────┴────────────────┐
                │                                 │
    ┌───────────────────────────┐    ┌───────────────────────────┐
    │ admin_transactions_       │    │ admin_variance_           │
    │ oversight.php             │    │ reports.php (NEW)         │
    │                           │    │                           │
    │ VALIDATED TRANSACTIONS    │    │ FUEL VARIANCE REPORTS     │
    │                           │    │                           │
    │ • Type Filtering          │    │ • Station Filtering       │
    │ • Date Range              │    │ • Fuel Type Filtering     │
    │ • Search                  │    │ • Status Filtering        │
    │ • Export                  │    │ • Statistical Summary     │
    │ • Pagination              │    │ • Export                  │
    │                           │    │ • Pagination              │
    └───────────────────────────┘    └───────────────────────────┘
                │                                 │
    ┌───────────┴────────────┐         ┌────────┴─────────┐
    │                        │         │                  │
┌───────────┐  ┌─────────────┐  ┌─────────────┐  ┌──────────────┐
│Merchandise│  │Job Orders   │  │Fuel Trans.  │  │Variance      │
│(validated)│  │(validated)  │  │(verified)   │  │Reports       │
└───────────┘  └─────────────┘  └─────────────┘  └──────────────┘
      │              │                 │                  │
      ▼              ▼                 ▼                  ▼
┌───────────┐  ┌─────────────┐  ┌─────────────┐  ┌──────────────┐
│merchandise│  │job_orders   │  │fuel_        │  │fuel_variance_│
│table      │  │table        │  │transactions │  │reports       │
│           │  │             │  │table        │  │table         │
└───────────┘  └─────────────┘  └─────────────┘  └──────────────┘
```

### Page Architecture Details

#### Page 1: admin_transactions_oversight.php

**Purpose**: Display manager-validated transactions across all transaction types

**Data Sources**:
- `merchandise` table (validation_status NOT IN 'Pending')
- `job_orders` table (validation_status NOT IN 'Pending')
- `fuel_transactions` table (status NOT IN 'Pending', 'Pending Validation')

**Navigation Pattern**: Type filtering dropdown (All Types | Merchandise | Job Order | Fuel)

**Query Pattern**:
```php
// Unified query approach with type-based filtering
if ($type === 'all') {
    // UNION query combining all transaction types
} elseif ($type === 'merchandise') {
    // Query merchandise table only
} elseif ($type === 'joborder') {
    // Query job_orders table only
} elseif ($type === 'fuel') {
    // Query fuel_transactions table only
}
```

**URL Parameters**:
```
?type=all|merchandise|joborder|fuel
&start=YYYY-MM-DD
&end=YYYY-MM-DD
&search=<term>
&status=<validation_status>
&export=excel
```

#### Page 2: admin_variance_reports.php (NEW)

**Purpose**: Monitor system-wide fuel inventory discrepancies

**Data Source**:
- `fuel_variance_reports` table (all records across all stations)

**Navigation Pattern**: Filter-based exploration with statistical overview

**Query Pattern**:
```php
// Base variance query
SELECT fvr.*, s.name AS station_name 
FROM fuel_variance_reports fvr
LEFT JOIN stations s ON s.id = fvr.station_id
WHERE DATE(fvr.report_date) BETWEEN ? AND ?
  [AND station_id = ?]
  [AND fuel_type LIKE ?]
  [AND status = ?]
ORDER BY report_date DESC
LIMIT 500
```

**URL Parameters**:
```
?start=YYYY-MM-DD
&end=YYYY-MM-DD
&station=<id>
&fuel_type=<type>
&status=Open|Under Investigation|Resolved
&search=<term>
&export=excel
```

### Access Control Flow

```
┌─────────────────┐
│  User Request   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Session Check   │
└────────┬────────┘
         │
    ┌────┴────┐
    │ Valid?  │
    └────┬────┘
         │
    Yes  │  No → Redirect to Login
         ▼
┌─────────────────┐
│ Role Check      │
│ admin/superadmin│
└────────┬────────┘
         │
    ┌────┴────┐
    │ Valid?  │
    └────┬────┘
         │
    Yes  │  No → Redirect to Dashboard + Error
         ▼
┌─────────────────┐
│ Load Page Data  │
│ Execute Queries │
│ Render View     │
└─────────────────┘
```

### Data Flow Diagrams

#### admin_transactions_oversight.php Data Flow

```
┌─────────────────┐
│ GET Parameters  │
│ • type          │
│ • start/end     │
│ • search        │
│ • status        │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Type Detection  │
│ Validate Input  │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Build Query     │
│ Add Filters     │
│ Parameter Bind  │
└────────┬────────┘
         │
    ┌────┴────┐
    │  type?  │
    └────┬────┘
         │
    ┌────┼────┬───────┐
    │    │    │       │
    All  Mdse JobOrd  Fuel
    │    │    │       │
    ▼    ▼    ▼       ▼
  UNION Merch JobOrds Fuel
  Query Query Query  Query
    │    │    │       │
    └────┴────┴───────┘
         │
         ▼
┌─────────────────┐
│ Fetch Results   │
│ Apply Pagination│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Render Table    │
│ Export Option   │
└─────────────────┘
```

#### admin_variance_reports.php Data Flow

```
┌─────────────────┐
│ GET Parameters  │
│ • start/end     │
│ • station       │
│ • fuel_type     │
│ • status        │
│ • search        │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Validate Input  │
│ Build Base Query│
└────────┬────────┘
         │
    ┌────┴────┐
    │ Filters?│
    └────┬────┘
         │
    ┌────┼────┬───────┬────────┐
    │    │    │       │        │
  Station FuelT Status Search  │
   Filter Filter Filter Filter  │
    │    │    │       │        │
    └────┴────┴───────┴────────┘
         │
         ▼
┌─────────────────┐
│Execute Variance │
│Query + Stats    │
│Query            │
└────────┬────────┘
         │
    ┌────┴────┐
    │         │
 Variance   Summary
  Reports  Statistics
    │         │
    └────┬────┘
         │
         ▼
┌─────────────────┐
│ Render View     │
│ • Stats Bar     │
│ • Variance Table│
│ • Export Option │
└─────────────────┘
```



## Components and Interfaces

### Page 1: admin_transactions_oversight.php Components

#### 1.1 Transaction Type Filter Component

**Location**: Top of filter bar, before date range
**Purpose**: Allow filtering by transaction type without tab navigation

**HTML Structure**:
```html
<div class="ato-filter-bar">
    <form method="GET" action="admin_transactions_oversight.php">
        <div class="ato-filter-group">
            <label for="type-filter">Transaction Type:</label>
            <select name="type" id="type-filter" onchange="this.form.submit()">
                <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>
                    All Types
                </option>
                <option value="merchandise" <?php echo $type === 'merchandise' ? 'selected' : ''; ?>>
                    Merchandise
                </option>
                <option value="joborder" <?php echo $type === 'joborder' ? 'selected' : ''; ?>>
                    Job Order
                </option>
                <option value="fuel" <?php echo $type === 'fuel' ? 'selected' : ''; ?>>
                    Fuel Transactions
                </option>
            </select>
        </div>
        
        <div class="ato-filter-group">
            <label>Date Range:</label>
            <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>">
            <span>to</span>
            <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>">
        </div>
        
        <div class="ato-filter-group">
            <label>Search:</label>
            <input type="text" name="search" placeholder="Transaction ID, customer..." 
                   value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <div class="ato-filter-group">
            <label>Status:</label>
            <select name="status">
                <option value="">All Statuses</option>
                <option value="Approved">Approved</option>
                <option value="Adjusted">Adjusted</option>
                <option value="Verified">Verified</option>
            </select>
        </div>
        
        <div class="ato-filter-actions">
            <button type="submit" class="ato-btn ato-btn-primary">Apply Filters</button>
            <a href="admin_transactions_oversight.php" class="ato-btn ato-btn-reset">Reset</a>
        </div>
    </form>
</div>
```

**PHP Type Detection Logic**:
```php
$type = $_GET['type'] ?? 'all';
$allowed_types = ['all', 'merchandise', 'joborder', 'fuel'];
if (!in_array($type, $allowed_types, true)) {
    $type = 'all';
}
```

#### 1.2 Unified Transactions Table Component

**Purpose**: Display validated transactions with type indicator

**Columns**:
1. Transaction ID
2. Type Badge (Merchandise | Job Order | Fuel)
3. Customer Name
4. Items/Services Summary
5. Total Amount
6. Payment Method
7. Payment Status
8. Validation Status
9. Date/Time
10. Staff Name
11. Actions

**Table Structure**:
```html
<div class="ato-table-container">
    <table class="ato-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Type</th>
                <th>Customer</th>
                <th>Items/Services</th>
                <th>Amount</th>
                <th>Payment Method</th>
                <th>Payment Status</th>
                <th>Validation Status</th>
                <th>Date/Time</th>
                <th>Staff</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $txn): ?>
            <tr>
                <td><?php echo htmlspecialchars($txn['id']); ?></td>
                <td>
                    <span class="ato-type-badge ato-type-<?php echo strtolower($txn['type']); ?>">
                        <?php echo htmlspecialchars($txn['type']); ?>
                    </span>
                </td>
                <td><?php echo htmlspecialchars($txn['customer_name']); ?></td>
                <td><?php echo htmlspecialchars($txn['items_summary']); ?></td>
                <td><?php echo number_format($txn['total_amount'], 2); ?></td>
                <td><?php echo htmlspecialchars($txn['payment_method']); ?></td>
                <td>
                    <span class="ato-status-badge ato-payment-<?php echo strtolower($txn['payment_status']); ?>">
                        <?php echo htmlspecialchars($txn['payment_status']); ?>
                    </span>
                </td>
                <td>
                    <span class="ato-status-badge ato-validation-<?php echo strtolower($txn['validation_status']); ?>">
                        <?php echo htmlspecialchars($txn['validation_status']); ?>
                    </span>
                </td>
                <td><?php echo date('Y-m-d H:i', strtotime($txn['created_at'])); ?></td>
                <td><?php echo htmlspecialchars($txn['staff_name']); ?></td>
                <td>
                    <a href="view_transaction.php?id=<?php echo $txn['id']; ?>&type=<?php echo $txn['type']; ?>" 
                       class="ato-btn ato-btn-sm">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
```

**Empty State**:
```html
<?php if (empty($transactions)): ?>
<tr>
    <td colspan="11" class="ato-empty-state">
        <i class="fas fa-inbox"></i>
        <p>No validated transactions found for the selected filters.</p>
        <a href="admin_transactions_oversight.php" class="ato-btn ato-btn-reset">Reset Filters</a>
    </td>
</tr>
<?php endif; ?>
```

### Page 2: admin_variance_reports.php Components (NEW)

#### 2.1 Page Header Component

**HTML Structure**:
```html
<div class="ato-page-header">
    <h1>
        <i class="fas fa-chart-line"></i>
        System-Wide Variance Reports
    </h1>
    <p class="ato-page-subtitle">
        Monitor fuel inventory discrepancies across all stations
    </p>
</div>
```

#### 2.2 Variance Filter Bar Component

**HTML Structure**:
```html
<div class="ato-filter-bar">
    <form method="GET" action="admin_variance_reports.php">
        <div class="ato-filter-group">
            <label>Date Range:</label>
            <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" required>
            <span>to</span>
            <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" required>
        </div>
        
        <div class="ato-filter-group">
            <label for="station-filter">Station:</label>
            <select name="station" id="station-filter">
                <option value="">All Stations</option>
                <?php foreach ($stations as $s): ?>
                <option value="<?php echo $s['id']; ?>" 
                        <?php echo $station_filter == $s['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="ato-filter-group">
            <label for="fueltype-filter">Fuel Type:</label>
            <select name="fuel_type" id="fueltype-filter">
                <option value="">All Types</option>
                <?php foreach ($fuel_types as $ft): ?>
                <option value="<?php echo htmlspecialchars($ft); ?>" 
                        <?php echo $fuel_type_filter === $ft ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ft); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="ato-filter-group">
            <label for="status-filter">Status:</label>
            <select name="status" id="status-filter">
                <option value="">All Statuses</option>
                <option value="Open" <?php echo $var_status_filter === 'Open' ? 'selected' : ''; ?>>
                    Open
                </option>
                <option value="Under Investigation" 
                        <?php echo $var_status_filter === 'Under Investigation' ? 'selected' : ''; ?>>
                    Under Investigation
                </option>
                <option value="Resolved" <?php echo $var_status_filter === 'Resolved' ? 'selected' : ''; ?>>
                    Resolved
                </option>
            </select>
        </div>
        
        <div class="ato-filter-group">
            <label>Search:</label>
            <input type="text" name="search" placeholder="Station or fuel type..." 
                   value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <div class="ato-filter-actions">
            <button type="submit" class="ato-btn ato-btn-primary">
                <i class="fas fa-filter"></i> Apply Filters
            </button>
            <a href="admin_variance_reports.php" class="ato-btn ato-btn-reset">
                <i class="fas fa-times"></i> Reset
            </a>
        </div>
    </form>
</div>
```

**PHP Filter Logic**:
```php
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
$end = $_GET['end'] ?? date('Y-m-d');
$station_filter = (int)($_GET['station'] ?? 0);
$fuel_type_filter = trim($_GET['fuel_type'] ?? '');
$var_status_filter = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
```

#### 2.3 Summary Statistics Component

**HTML Structure**:
```html
<div class="ato-summary-bar">
    <div class="ato-sum-card">
        <span class="ato-sum-label">Total Variance</span>
        <span class="ato-sum-value"><?php echo number_format($stats['total_variance_liters'], 2); ?>L</span>
    </div>
    <span class="ato-sum-divider">|</span>
    <div class="ato-sum-card">
        <span class="ato-sum-label">Avg Variance %</span>
        <span class="ato-sum-value"><?php echo number_format($stats['avg_variance_percent'], 2); ?>%</span>
    </div>
    <span class="ato-sum-divider">|</span>
    <div class="ato-sum-card ato-card-open">
        <span class="ato-sum-label">Open</span>
        <span class="ato-sum-value"><?php echo $stats['open_count']; ?></span>
    </div>
    <div class="ato-sum-card ato-card-investigating">
        <span class="ato-sum-label">Investigating</span>
        <span class="ato-sum-value"><?php echo $stats['investigating_count']; ?></span>
    </div>
    <div class="ato-sum-card ato-card-resolved">
        <span class="ato-sum-label">Resolved</span>
        <span class="ato-sum-value"><?php echo $stats['resolved_count']; ?></span>
    </div>
    <span class="ato-sum-divider">|</span>
    <div class="ato-sum-card">
        <span class="ato-sum-label">Total Reports</span>
        <span class="ato-sum-value"><?php echo $stats['total_count']; ?></span>
    </div>
</div>
```

**PHP Statistics Query**:
```php
$stats_query = "
    SELECT 
        SUM(ABS(variance_liters)) AS total_variance_liters,
        AVG(ABS(variance_percent)) AS avg_variance_percent,
        SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN status = 'Under Investigation' THEN 1 ELSE 0 END) AS investigating_count,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_count,
        COUNT(*) AS total_count
    FROM fuel_variance_reports fvr
    LEFT JOIN stations s ON s.id = fvr.station_id
    WHERE DATE(fvr.report_date) BETWEEN ? AND ?
";

$stats_params = [$start, $end];

// Apply same filters as main query
if ($station_filter > 0) {
    $stats_query .= " AND fvr.station_id = ?";
    $stats_params[] = $station_filter;
}
if ($fuel_type_filter !== '') {
    $stats_query .= " AND fvr.fuel_type LIKE ?";
    $stats_params[] = "%$fuel_type_filter%";
}
if ($var_status_filter !== '') {
    $stats_query .= " AND fvr.status = ?";
    $stats_params[] = $var_status_filter;
}

$stmt = $pdo->prepare($stats_query);
$stmt->execute($stats_params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
```

#### 2.4 Variance Reports Table Component

**Columns**:
1. Report Date
2. Station Name
3. Fuel Type
4. Expected Stock (L)
5. Actual Stock (L)
6. Variance (L)
7. Variance %
8. Status Badge
9. Reason
10. Actions

**Table Structure**:
```html
<div class="ato-table-container">
    <table class="ato-table ato-variance-table">
        <thead>
            <tr>
                <th>Report Date</th>
                <th>Station</th>
                <th>Fuel Type</th>
                <th>Expected (L)</th>
                <th>Actual (L)</th>
                <th>Variance (L)</th>
                <th>Variance %</th>
                <th>Status</th>
                <th>Reason</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($variance_reports as $vr): ?>
            <tr>
                <td><?php echo date('Y-m-d', strtotime($vr['report_date'])); ?></td>
                <td><?php echo htmlspecialchars($vr['station_name']); ?></td>
                <td><?php echo htmlspecialchars($vr['fuel_type']); ?></td>
                <td class="ato-number"><?php echo number_format($vr['expected_stock'], 2); ?></td>
                <td class="ato-number"><?php echo number_format($vr['actual_stock'], 2); ?></td>
                <td class="ato-number"><?php echo number_format($vr['variance_liters'], 2); ?></td>
                <td class="ato-number">
                    <?php 
                    $abs_pct = abs($vr['variance_percent']);
                    $severity_class = '';
                    if ($abs_pct > 5) {
                        $severity_class = 'ato-variance-critical';
                    } elseif ($abs_pct >= 2) {
                        $severity_class = 'ato-variance-warning';
                    } else {
                        $severity_class = 'ato-variance-minor';
                    }
                    ?>
                    <span class="ato-variance-badge <?php echo $severity_class; ?>">
                        <?php echo number_format($vr['variance_percent'], 2); ?>%
                    </span>
                </td>
                <td>
                    <?php
                    $status_class = '';
                    switch ($vr['status']) {
                        case 'Open':
                            $status_class = 'ato-status-open';
                            break;
                        case 'Under Investigation':
                            $status_class = 'ato-status-investigating';
                            break;
                        case 'Resolved':
                            $status_class = 'ato-status-resolved';
                            break;
                    }
                    ?>
                    <span class="ato-status-badge <?php echo $status_class; ?>">
                        <?php echo htmlspecialchars($vr['status']); ?>
                    </span>
                </td>
                <td class="ato-reason-cell">
                    <?php echo htmlspecialchars(substr($vr['reason'], 0, 50)); ?>
                    <?php if (strlen($vr['reason']) > 50): ?>...<?php endif; ?>
                </td>
                <td>
                    <a href="admin_variance_details.php?id=<?php echo $vr['report_id']; ?>" 
                       class="ato-btn ato-btn-sm">
                        <i class="fas fa-eye"></i> View
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
```

**Empty State**:
```html
<?php if (empty($variance_reports)): ?>
<tr>
    <td colspan="10" class="ato-empty-state">
        <i class="fas fa-inbox"></i>
        <p>No variance reports found for the selected filters.</p>
        <a href="admin_variance_reports.php" class="ato-btn ato-btn-reset">Reset Filters</a>
    </td>
</tr>
<?php endif; ?>
```

### Shared Components (Both Pages)

#### 3.1 Export Excel Component

**Button Placement**: Top-right of table container

**HTML Structure**:
```html
<div class="ato-actions-bar">
    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" 
       class="ato-btn ato-btn-success">
        <i class="fas fa-file-excel"></i> Export to Excel
    </a>
    <button onclick="window.print()" class="ato-btn ato-btn-secondary">
        <i class="fas fa-print"></i> Print
    </button>
</div>
```

**PHP Export Logic (admin_transactions_oversight.php)**:
```php
$is_export = ($_GET['export'] ?? '') === 'excel';

if ($is_export) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="transactions_oversight_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
    
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>Type</th><th>Customer</th><th>Items/Services</th>';
    echo '<th>Amount</th><th>Payment Method</th><th>Payment Status</th>';
    echo '<th>Validation Status</th><th>Date</th><th>Staff</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($transactions as $txn) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($txn['id']) . '</td>';
        echo '<td>' . htmlspecialchars($txn['type']) . '</td>';
        echo '<td>' . htmlspecialchars($txn['customer_name']) . '</td>';
        echo '<td>' . htmlspecialchars($txn['items_summary']) . '</td>';
        echo '<td>' . number_format($txn['total_amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($txn['payment_method']) . '</td>';
        echo '<td>' . htmlspecialchars($txn['payment_status']) . '</td>';
        echo '<td>' . htmlspecialchars($txn['validation_status']) . '</td>';
        echo '<td>' . date('Y-m-d H:i', strtotime($txn['created_at'])) . '</td>';
        echo '<td>' . htmlspecialchars($txn['staff_name']) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    exit;
}
```

**PHP Export Logic (admin_variance_reports.php)**:
```php
$is_export = ($_GET['export'] ?? '') === 'excel';

if ($is_export) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="variance_reports_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
    
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>Report Date</th><th>Station</th><th>Fuel Type</th>';
    echo '<th>Expected (L)</th><th>Actual (L)</th><th>Variance (L)</th>';
    echo '<th>Variance %</th><th>Status</th><th>Reason</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($variance_reports as $vr) {
        echo '<tr>';
        echo '<td>' . date('Y-m-d', strtotime($vr['report_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($vr['station_name']) . '</td>';
        echo '<td>' . htmlspecialchars($vr['fuel_type']) . '</td>';
        echo '<td>' . number_format($vr['expected_stock'], 2) . '</td>';
        echo '<td>' . number_format($vr['actual_stock'], 2) . '</td>';
        echo '<td>' . number_format($vr['variance_liters'], 2) . '</td>';
        echo '<td>' . number_format($vr['variance_percent'], 2) . '%</td>';
        echo '<td>' . htmlspecialchars($vr['status']) . '</td>';
        echo '<td>' . htmlspecialchars($vr['reason']) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    exit;
}
```

#### 3.2 Pagination Component

**HTML Structure** (consistent across both pages):
```html
<div class="ato-pagination">
    <div class="ato-pagination-info">
        Showing <?php echo $start_row; ?>-<?php echo $end_row; ?> of <?php echo $total_rows; ?> records
    </div>
    <div class="ato-pagination-controls">
        <select id="rows-per-page" onchange="changeRowsPerPage(this.value)">
            <option value="10">10 per page</option>
            <option value="25" selected>25 per page</option>
            <option value="50">50 per page</option>
            <option value="100">100 per page</option>
        </select>
        <button onclick="previousPage()" <?php echo $current_page <= 1 ? 'disabled' : ''; ?>>
            <i class="fas fa-chevron-left"></i> Previous
        </button>
        <span>Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
        <button onclick="nextPage()" <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>>
            Next <i class="fas fa-chevron-right"></i>
        </button>
    </div>
</div>
```

**JavaScript** (client-side pagination):
```javascript
// Client-side pagination
let currentPage = 1;
let rowsPerPage = 25;
const tableRows = document.querySelectorAll('.ato-table tbody tr');
const totalRows = tableRows.length;

function paginateTable() {
    const start = (currentPage - 1) * rowsPerPage;
    const end = start + rowsPerPage;
    
    tableRows.forEach((row, index) => {
        if (index >= start && index < end) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    updatePaginationInfo();
}

function updatePaginationInfo() {
    const totalPages = Math.ceil(totalRows / rowsPerPage);
    const start = (currentPage - 1) * rowsPerPage + 1;
    const end = Math.min(currentPage * rowsPerPage, totalRows);
    
    document.querySelector('.ato-pagination-info').textContent = 
        `Showing ${start}-${end} of ${totalRows} records`;
    document.querySelector('.ato-pagination-controls span').textContent = 
        `Page ${currentPage} of ${totalPages}`;
}

function previousPage() {
    if (currentPage > 1) {
        currentPage--;
        paginateTable();
    }
}

function nextPage() {
    const totalPages = Math.ceil(totalRows / rowsPerPage);
    if (currentPage < totalPages) {
        currentPage++;
        paginateTable();
    }
}

function changeRowsPerPage(newRowsPerPage) {
    rowsPerPage = parseInt(newRowsPerPage);
    currentPage = 1;
    paginateTable();
}

// Initialize pagination on page load
document.addEventListener('DOMContentLoaded', function() {
    paginateTable();
});
```



## Data Models

### Database Schema Interaction

#### admin_transactions_oversight.php Queries

**1. Unified Transactions Query (type=all)**:
```sql
-- Combine all transaction types with UNION
SELECT 
    'Merchandise' AS type,
    m.id AS transaction_id,
    m.customer_name,
    CONCAT(COUNT(mi.id), ' items') AS items_summary,
    m.total_amount,
    m.payment_method,
    m.payment_status,
    m.validation_status,
    m.created_at,
    u.name AS staff_name
FROM merchandise m
LEFT JOIN merchandise_items mi ON mi.transaction_id = m.id
LEFT JOIN users u ON u.id = m.encoded_by
WHERE m.validation_status NOT IN ('Pending')
  AND DATE(m.created_at) BETWEEN ? AND ?
GROUP BY m.id

UNION ALL

SELECT 
    'Job Order' AS type,
    jo.id AS transaction_id,
    jo.customer_name,
    CONCAT(COUNT(jos.id), ' services') AS items_summary,
    jo.total_amount,
    jo.payment_method,
    jo.payment_status,
    jo.validation_status,
    jo.created_at,
    u.name AS staff_name
FROM job_orders jo
LEFT JOIN job_order_services jos ON jos.job_order_id = jo.id
LEFT JOIN users u ON u.id = jo.encoded_by
WHERE jo.validation_status NOT IN ('Pending')
  AND DATE(jo.created_at) BETWEEN ? AND ?
GROUP BY jo.id

UNION ALL

SELECT 
    'Fuel' AS type,
    ft.id AS transaction_id,
    COALESCE(ft.customer_name, 'Walk-in') AS customer_name,
    CONCAT(ft.liters_sold, 'L ', ft.fuel_type) AS items_summary,
    ft.total_amount,
    ft.payment_method,
    ft.payment_status,
    ft.status AS validation_status,
    ft.created_at,
    u.name AS staff_name
FROM fuel_transactions ft
LEFT JOIN users u ON u.id = ft.encoded_by
WHERE ft.status NOT IN ('Pending', 'Pending Validation')
  AND DATE(ft.created_at) BETWEEN ? AND ?

ORDER BY created_at DESC
LIMIT 500
```

**2. Merchandise-Only Query (type=merchandise)**:
```sql
SELECT 
    'Merchandise' AS type,
    m.id AS transaction_id,
    m.customer_name,
    CONCAT(COUNT(mi.id), ' items') AS items_summary,
    m.total_amount,
    m.payment_method,
    m.payment_status,
    m.validation_status,
    m.created_at,
    u.name AS staff_name
FROM merchandise m
LEFT JOIN merchandise_items mi ON mi.transaction_id = m.id
LEFT JOIN users u ON u.id = m.encoded_by
WHERE m.validation_status NOT IN ('Pending')
  AND DATE(m.created_at) BETWEEN ? AND ?
GROUP BY m.id
ORDER BY m.created_at DESC
LIMIT 500
```

**3. Job Order-Only Query (type=joborder)**:
```sql
SELECT 
    'Job Order' AS type,
    jo.id AS transaction_id,
    jo.customer_name,
    CONCAT(COUNT(jos.id), ' services') AS items_summary,
    jo.total_amount,
    jo.payment_method,
    jo.payment_status,
    jo.validation_status,
    jo.created_at,
    u.name AS staff_name
FROM job_orders jo
LEFT JOIN job_order_services jos ON jos.job_order_id = jo.id
LEFT JOIN users u ON u.id = jo.encoded_by
WHERE jo.validation_status NOT IN ('Pending')
  AND DATE(jo.created_at) BETWEEN ? AND ?
GROUP BY jo.id
ORDER BY jo.created_at DESC
LIMIT 500
```

**4. Fuel-Only Query (type=fuel)**:
```sql
SELECT 
    'Fuel' AS type,
    ft.id AS transaction_id,
    COALESCE(ft.customer_name, 'Walk-in') AS customer_name,
    CONCAT(ft.liters_sold, 'L ', ft.fuel_type) AS items_summary,
    ft.total_amount,
    ft.payment_method,
    ft.payment_status,
    ft.status AS validation_status,
    ft.created_at,
    u.name AS staff_name
FROM fuel_transactions ft
LEFT JOIN users u ON u.id = ft.encoded_by
WHERE ft.status NOT IN ('Pending', 'Pending Validation')
  AND DATE(ft.created_at) BETWEEN ? AND ?
ORDER BY ft.created_at DESC
LIMIT 500
```

**Dynamic Filter Application**:
```php
// Add search filter if provided
if ($search !== '') {
    $search_clause = "AND (
        m.customer_name LIKE ? OR
        CAST(m.id AS CHAR) LIKE ? OR
        m.vehicle_plate LIKE ?
    )";
    // Add to WHERE clause
}

// Add status filter if provided
if ($status_filter !== '') {
    $status_clause = "AND m.validation_status = ?";
    // Add to WHERE clause
}
```

#### admin_variance_reports.php Queries

**1. Base Variance Reports Query**:
```sql
SELECT 
    fvr.id AS report_id,
    fvr.report_date,
    fvr.fuel_type,
    fvr.expected_stock,
    fvr.actual_stock,
    fvr.variance_liters,
    fvr.variance_percent,
    fvr.status,
    fvr.reason,
    fvr.resolution_notes,
    COALESCE(s.name, 'Unknown Station') AS station_name,
    COALESCE(inv_user.name, NULL) AS investigated_by_name,
    COALESCE(res_user.name, NULL) AS resolved_by_name,
    fvr.created_at,
    fvr.updated_at
FROM fuel_variance_reports fvr
LEFT JOIN stations s ON s.id = fvr.station_id
LEFT JOIN users inv_user ON inv_user.id = fvr.investigated_by
LEFT JOIN users res_user ON res_user.id = fvr.resolved_by
WHERE DATE(fvr.report_date) BETWEEN ? AND ?
```

**Dynamic Filter Application**:
```php
$vr_where = "WHERE DATE(fvr.report_date) BETWEEN ? AND ?";
$vr_params = [$start, $end];

// Station filter
if ($station_filter > 0) {
    $vr_where .= " AND fvr.station_id = ?";
    $vr_params[] = $station_filter;
}

// Fuel type filter
if ($fuel_type_filter !== '') {
    $vr_where .= " AND fvr.fuel_type LIKE ?";
    $vr_params[] = "%$fuel_type_filter%";
}

// Status filter
if ($var_status_filter !== '') {
    $vr_where .= " AND fvr.status = ?";
    $vr_params[] = $var_status_filter;
}

// Search filter (station name or fuel type)
if ($search !== '') {
    $vr_where .= " AND (s.name LIKE ? OR fvr.fuel_type LIKE ?)";
    $vr_params[] = "%$search%";
    $vr_params[] = "%$search%";
}

$vr_order = "ORDER BY fvr.report_date DESC, fvr.id DESC LIMIT 500";
$final_query = $base_query . $vr_where . $vr_order;
```

**2. Variance Summary Statistics Query**:
```sql
SELECT 
    SUM(ABS(fvr.variance_liters)) AS total_variance_liters,
    AVG(ABS(fvr.variance_percent)) AS avg_variance_percent,
    SUM(CASE WHEN fvr.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
    SUM(CASE WHEN fvr.status = 'Under Investigation' THEN 1 ELSE 0 END) AS investigating_count,
    SUM(CASE WHEN fvr.status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_count,
    COUNT(*) AS total_count
FROM fuel_variance_reports fvr
LEFT JOIN stations s ON s.id = fvr.station_id
WHERE DATE(fvr.report_date) BETWEEN ? AND ?
  /* Apply same filters as main query */
```

**3. Station List Query (for filter dropdown)**:
```sql
SELECT DISTINCT 
    s.id, 
    s.name
FROM stations s
INNER JOIN fuel_variance_reports fvr ON fvr.station_id = s.id
WHERE s.status = 'active'
ORDER BY s.name ASC
```

**4. Fuel Type List Query (for filter dropdown)**:
```sql
SELECT DISTINCT fuel_type
FROM fuel_variance_reports
WHERE fuel_type IS NOT NULL AND fuel_type != ''
ORDER BY fuel_type ASC
```

### Dynamic Column Detection

**Existing Pattern (Reuse)**:
```php
// Check what columns exist in the table
function ato_cols($pdo, $table_name) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table_name`");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

// Check if a column exists
function ato_has($cols_array, $column_name) {
    return in_array($column_name, $cols_array, true);
}
```

**Application in Variance Reports**:
```php
$vr_cols = ato_cols($pdo, 'fuel_variance_reports');
$has_vr = fn($c) => ato_has($vr_cols, $c);

// Use in query construction
$inv_by_col = $has_vr('investigated_by') 
    ? 'COALESCE(inv_user.name, NULL)' 
    : 'NULL';

$res_by_col = $has_vr('resolved_by') 
    ? 'COALESCE(res_user.name, NULL)' 
    : 'NULL';

$res_notes_col = $has_vr('resolution_notes') 
    ? 'fvr.resolution_notes' 
    : 'NULL';

// Include optional joins only if columns exist
$inv_join = $has_vr('investigated_by')
    ? "LEFT JOIN users inv_user ON inv_user.id = fvr.investigated_by"
    : "";

$res_join = $has_vr('resolved_by')
    ? "LEFT JOIN users res_user ON res_user.id = fvr.resolved_by"
    : "";
```

## Error Handling

### Input Validation

**Date Range Validation**:
```php
// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $start = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $end = date('Y-m-d');
}

// Ensure start <= end
if (strtotime($start) > strtotime($end)) {
    [$start, $end] = [$end, $start]; // Swap
}

// Limit date range to 365 days for performance
$date_diff_days = (strtotime($end) - strtotime($start)) / 86400;
if ($date_diff_days > 365) {
    $_SESSION['warning'] = 'Date range limited to 365 days for performance.';
    $start = date('Y-m-d', strtotime($end . ' -365 days'));
}
```

**Type/Filter Validation**:
```php
// admin_transactions_oversight.php
$type = $_GET['type'] ?? 'all';
$allowed_types = ['all', 'merchandise', 'joborder', 'fuel'];
if (!in_array($type, $allowed_types, true)) {
    $type = 'all';
}

// admin_variance_reports.php
$station_filter = (int)($_GET['station'] ?? 0);
$fuel_type_filter = trim($_GET['fuel_type'] ?? '');
$var_status_filter = trim($_GET['status'] ?? '');
$allowed_statuses = ['', 'Open', 'Under Investigation', 'Resolved'];
if (!in_array($var_status_filter, $allowed_statuses, true)) {
    $var_status_filter = '';
}
```

### Database Query Error Handling

**Try-Catch Pattern**:
```php
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $results = [];
    error_log("Database Query Failed: " . $e->getMessage());
    error_log("Query: " . $query);
    error_log("Params: " . json_encode($params));
    $_SESSION['error'] = 'Unable to load data. Please try again or contact support.';
}
```

### Empty Result Sets

**Graceful Handling**:
```php
// Transactions
if (empty($transactions)) {
    $empty_message = "No validated transactions found for the selected filters.";
}

// Variance Reports
if (empty($variance_reports)) {
    $empty_message = "No variance reports found for the selected filters.";
}

// Statistics with zero records
$stats = [
    'total_variance_liters' => $stats['total_variance_liters'] ?? 0,
    'avg_variance_percent' => $stats['avg_variance_percent'] ?? 0,
    'open_count' => $stats['open_count'] ?? 0,
    'investigating_count' => $stats['investigating_count'] ?? 0,
    'resolved_count' => $stats['resolved_count'] ?? 0,
    'total_count' => $stats['total_count'] ?? 0,
];
```

### Missing Column Handling

**Fallback Values**:
```php
// In variance reports rendering
$inv_by = $has_vr('investigated_by') && isset($row['investigated_by_name']) 
    ? $row['investigated_by_name'] 
    : 'N/A';

$res_by = $has_vr('resolved_by') && isset($row['resolved_by_name']) 
    ? $row['resolved_by_name'] 
    : 'N/A';

$res_notes = $has_vr('resolution_notes') && isset($row['resolution_notes']) 
    ? $row['resolution_notes'] 
    : '';
```

## Testing Strategy

### Unit Testing

**admin_transactions_oversight.php Tests**:

1. **Type Filter Tests**:
   - Test type='all' returns merchandise, job orders, and fuel transactions
   - Test type='merchandise' returns only merchandise transactions
   - Test type='joborder' returns only job order transactions
   - Test type='fuel' returns only fuel transactions
   - Test invalid type defaults to 'all'

2. **Validation Status Guard Tests**:
   - Verify merchandise query excludes validation_status='Pending'
   - Verify job orders query excludes validation_status='Pending'
   - Verify fuel query excludes status IN ('Pending', 'Pending Validation')
   - Test that Pending transactions never appear in results

3. **Date Range Tests**:
   - Test valid date range returns correct records
   - Test start > end swaps dates correctly
   - Test date range > 365 days is limited
   - Test invalid date format uses defaults

4. **Search Filter Tests**:
   - Test search by transaction ID
   - Test search by customer name
   - Test search by vehicle plate (fuel)
   - Test SQL injection attempts in search field

5. **Export Tests**:
   - Test Excel export with merchandise transactions
   - Test Excel export with job order transactions
   - Test Excel export with fuel transactions
   - Test Excel export with mixed transaction types
   - Verify Excel headers and formatting

**admin_variance_reports.php Tests**:

1. **Query Construction Tests**:
   - Test base query with date range only
   - Test query with station filter
   - Test query with fuel type filter
   - Test query with status filter
   - Test query with combined filters
   - Test query with search parameter

2. **Summary Statistics Tests**:
   - Test total variance calculation with mixed positive/negative variances
   - Test average variance percentage calculation
   - Test status counts (open, investigating, resolved)
   - Test with empty result set returns zeros

3. **Filter Validation Tests**:
   - Test invalid status value is ignored
   - Test station filter with invalid ID
   - Test fuel type filter with special characters
   - Test search with SQL injection attempts

4. **Dynamic Column Detection Tests**:
   - Test `ato_cols()` with fuel_variance_reports table
   - Test `ato_has()` for existing columns
   - Test `ato_has()` for non-existent columns
   - Test query construction with missing optional columns

5. **Export Tests**:
   - Test Excel export with variance data
   - Test Excel filename format includes date
   - Test Excel headers match table columns
   - Test Excel content formatting (numbers, dates)

### Integration Testing

**Page Navigation Tests**:
1. Test menu navigation from admin dashboard to admin_transactions_oversight.php
2. Test menu navigation from admin dashboard to admin_variance_reports.php
3. Test direct URL access to both pages
4. Verify both pages enforce admin/superadmin role requirement
5. Test logout redirects correctly from both pages

**Cross-Page Consistency Tests**:
1. Verify consistent filter bar styling across both pages
2. Verify consistent pagination behavior across both pages
3. Verify consistent export functionality across both pages
4. Verify consistent print layouts across both pages

**Filter State Tests**:
1. Test filter parameters persist in URL on admin_transactions_oversight.php
2. Test filter parameters persist in URL on admin_variance_reports.php
3. Test reset button clears all filters on both pages
4. Test filter combinations don't conflict

**Pagination Integration Tests**:
1. Test client-side pagination with 10, 25, 50, 100 rows per page
2. Test pagination with filtered results
3. Test pagination page numbers update correctly
4. Test pagination row range display is accurate

**Database Integration Tests**:
1. Test admin_transactions_oversight.php with populated transaction tables
2. Test admin_transactions_oversight.php with empty transaction tables
3. Test admin_variance_reports.php with populated variance reports table
4. Test admin_variance_reports.php with empty variance reports table
5. Test with missing station references in variance reports
6. Test with missing user references (investigated_by, resolved_by)

### Browser Compatibility Testing

**Target Browsers**:
- Chrome (latest)
- Firefox (latest)
- Edge (latest)
- Safari (latest)

**Test Areas**:
- Filter bar layout and responsiveness
- Table horizontal scrolling
- Pagination controls
- Export Excel download
- Print layout
- Status badge rendering
- Summary statistics bar (variance reports page)

### Performance Testing

**Load Tests**:
1. admin_transactions_oversight.php with 100 transactions
2. admin_transactions_oversight.php with 500 transactions (query limit)
3. admin_variance_reports.php with 100 variance reports
4. admin_variance_reports.php with 500 variance reports (query limit)

**Query Performance**:
- Measure UNION query execution time (type='all')
- Measure individual transaction type query execution time
- Measure variance report query execution time
- Measure summary statistics query execution time
- Measure station/fuel type list queries execution time

**Target Benchmarks**:
- Page load < 2 seconds with 500 records
- Filter application < 1 second
- Excel export generation < 3 seconds
- Query execution < 500ms



## Implementation Details

### File Structure

```
public/
├── admin_transactions_oversight.php  (EXISTING - TO BE MODIFIED)
│   • Remove tab navigation
│   • Add type filtering dropdown
│   • Keep validation status guards
│   • Maintain existing dynamic column detection
│
└── admin_variance_reports.php        (NEW FILE - TO BE CREATED)
    • Create from scratch
    • Implement variance query logic
    • Add summary statistics
    • Implement filter bar
    • Add export functionality
```

### admin_transactions_oversight.php Modifications

**Section 1: Remove Tab Logic** (Lines ~320-325):
```php
// REMOVE:
// $active_tab = ($_GET['tab'] ?? 'transactions') === 'fuel' ? 'fuel' : 'transactions';

// REPLACE WITH:
$type = $_GET['type'] ?? 'all';
$allowed_types = ['all', 'merchandise', 'joborder', 'fuel'];
if (!in_array($type, $allowed_types, true)) {
    $type = 'all';
}
```

**Section 2: Update Data Fetching Logic** (Lines ~350-480):
```php
// Build query based on type
$transactions = [];

if ($type === 'all') {
    // UNION query combining all transaction types
    $query = "
        SELECT 'Merchandise' AS type, m.id, m.customer_name, ... FROM merchandise m ...
        UNION ALL
        SELECT 'Job Order' AS type, jo.id, jo.customer_name, ... FROM job_orders jo ...
        UNION ALL
        SELECT 'Fuel' AS type, ft.id, COALESCE(ft.customer_name, 'Walk-in'), ... FROM fuel_transactions ft ...
        ORDER BY created_at DESC LIMIT 500
    ";
    $params = [$start, $end, $start, $end, $start, $end];
} elseif ($type === 'merchandise') {
    // Merchandise-only query
} elseif ($type === 'joborder') {
    // Job order-only query
} elseif ($type === 'fuel') {
    // Fuel-only query
}

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $transactions = [];
    error_log("Transactions Query Failed: " . $e->getMessage());
    $_SESSION['error'] = 'Unable to load transactions.';
}
```

**Section 3: Remove Tab HTML** (Lines ~560-575):
```html
<!-- REMOVE entire .ato-tab-bar section -->
<!-- NO TABS - just page header and filter bar -->
```

**Section 4: Update Filter Bar** (Lines ~585-635):
```html
<!-- ADD type filter dropdown at start of filter bar -->
<div class="ato-filter-group">
    <label for="type-filter">Transaction Type:</label>
    <select name="type" id="type-filter" onchange="this.form.submit()">
        <option value="all">All Types</option>
        <option value="merchandise">Merchandise</option>
        <option value="joborder">Job Order</option>
        <option value="fuel">Fuel Transactions</option>
    </select>
</div>
```

**Section 5: Update Table Display** (Lines ~650-900):
```html
<!-- Single unified table - NO conditional tab rendering -->
<table class="ato-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Type</th> <!-- ADD Type column -->
            <th>Customer</th>
            <!-- ... rest of columns -->
        </tr>
    </thead>
    <tbody>
        <?php foreach ($transactions as $txn): ?>
        <tr>
            <td><?php echo htmlspecialchars($txn['id']); ?></td>
            <td>
                <span class="ato-type-badge ato-type-<?php echo strtolower($txn['type']); ?>">
                    <?php echo htmlspecialchars($txn['type']); ?>
                </span>
            </td>
            <!-- ... rest of row -->
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
```

### admin_variance_reports.php Implementation (NEW FILE)

**Complete File Structure**:
```php
<?php
// ═══════════════════════════════════════════════════════════════
// ADMIN VARIANCE REPORTS
// System-wide fuel inventory discrepancy monitoring
// ═══════════════════════════════════════════════════════════════

require_once '../includes/db.php';
require_once '../includes/auth.php';

// Access control
require_login();
$me = current_user();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['admin', 'superadmin'], true)) {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    header('Location: admin_dashboard.php');
    exit;
}

// ═══════════════════════════════════════════════════════════════
// FILTER PARAMETERS
// ═══════════════════════════════════════════════════════════════

$start = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
$end = $_GET['end'] ?? date('Y-m-d');
$station_filter = (int)($_GET['station'] ?? 0);
$fuel_type_filter = trim($_GET['fuel_type'] ?? '');
$var_status_filter = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$is_export = ($_GET['export'] ?? '') === 'excel';

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $start = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $end = date('Y-m-d');
}

// Ensure start <= end
if (strtotime($start) > strtotime($end)) {
    [$start, $end] = [$end, $start];
}

// Validate status
$allowed_statuses = ['', 'Open', 'Under Investigation', 'Resolved'];
if (!in_array($var_status_filter, $allowed_statuses, true)) {
    $var_status_filter = '';
}

// ═══════════════════════════════════════════════════════════════
// DYNAMIC COLUMN DETECTION
// ═══════════════════════════════════════════════════════════════

function ato_cols($pdo, $table_name) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table_name`");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

function ato_has($cols_array, $column_name) {
    return in_array($column_name, $cols_array, true);
}

$vr_cols = ato_cols($pdo, 'fuel_variance_reports');
$has_vr = fn($c) => ato_has($vr_cols, $c);

// ═══════════════════════════════════════════════════════════════
// FETCH STATION AND FUEL TYPE LISTS FOR FILTERS
// ═══════════════════════════════════════════════════════════════

$stations = [];
try {
    $stmt = $pdo->query("
        SELECT DISTINCT s.id, s.name
        FROM stations s
        INNER JOIN fuel_variance_reports fvr ON fvr.station_id = s.id
        WHERE s.status = 'active'
        ORDER BY s.name ASC
    ");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Failed to fetch stations: " . $e->getMessage());
}

$fuel_types = [];
try {
    $stmt = $pdo->query("
        SELECT DISTINCT fuel_type
        FROM fuel_variance_reports
        WHERE fuel_type IS NOT NULL AND fuel_type != ''
        ORDER BY fuel_type ASC
    ");
    $fuel_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Failed to fetch fuel types: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// BUILD VARIANCE REPORTS QUERY
// ═══════════════════════════════════════════════════════════════

$inv_by_col = $has_vr('investigated_by') ? 'COALESCE(inv_user.name, NULL)' : 'NULL';
$res_by_col = $has_vr('resolved_by') ? 'COALESCE(res_user.name, NULL)' : 'NULL';
$res_notes_col = $has_vr('resolution_notes') ? 'fvr.resolution_notes' : 'NULL';

$inv_join = $has_vr('investigated_by') 
    ? "LEFT JOIN users inv_user ON inv_user.id = fvr.investigated_by" 
    : "";
$res_join = $has_vr('resolved_by') 
    ? "LEFT JOIN users res_user ON res_user.id = fvr.resolved_by" 
    : "";

$variance_query = "
    SELECT 
        fvr.id AS report_id,
        fvr.report_date,
        fvr.fuel_type,
        fvr.expected_stock,
        fvr.actual_stock,
        fvr.variance_liters,
        fvr.variance_percent,
        fvr.status,
        fvr.reason,
        $res_notes_col AS resolution_notes,
        COALESCE(s.name, 'Unknown Station') AS station_name,
        $inv_by_col AS investigated_by_name,
        $res_by_col AS resolved_by_name,
        fvr.created_at,
        fvr.updated_at
    FROM fuel_variance_reports fvr
    LEFT JOIN stations s ON s.id = fvr.station_id
    $inv_join
    $res_join
    WHERE DATE(fvr.report_date) BETWEEN ? AND ?
";

$vr_params = [$start, $end];

// Apply filters
if ($station_filter > 0) {
    $variance_query .= " AND fvr.station_id = ?";
    $vr_params[] = $station_filter;
}

if ($fuel_type_filter !== '') {
    $variance_query .= " AND fvr.fuel_type LIKE ?";
    $vr_params[] = "%$fuel_type_filter%";
}

if ($var_status_filter !== '') {
    $variance_query .= " AND fvr.status = ?";
    $vr_params[] = $var_status_filter;
}

if ($search !== '') {
    $variance_query .= " AND (s.name LIKE ? OR fvr.fuel_type LIKE ?)";
    $vr_params[] = "%$search%";
    $vr_params[] = "%$search%";
}

$variance_query .= " ORDER BY fvr.report_date DESC, fvr.id DESC LIMIT 500";

// Execute variance query
$variance_reports = [];
try {
    $stmt = $pdo->prepare($variance_query);
    $stmt->execute($vr_params);
    $variance_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $variance_reports = [];
    error_log("Variance Reports Query Failed: " . $e->getMessage());
    $_SESSION['error'] = 'Unable to load variance reports. Please try again.';
}

// ═══════════════════════════════════════════════════════════════
// FETCH SUMMARY STATISTICS
// ═══════════════════════════════════════════════════════════════

$stats_query = "
    SELECT 
        SUM(ABS(fvr.variance_liters)) AS total_variance_liters,
        AVG(ABS(fvr.variance_percent)) AS avg_variance_percent,
        SUM(CASE WHEN fvr.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN fvr.status = 'Under Investigation' THEN 1 ELSE 0 END) AS investigating_count,
        SUM(CASE WHEN fvr.status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_count,
        COUNT(*) AS total_count
    FROM fuel_variance_reports fvr
    LEFT JOIN stations s ON s.id = fvr.station_id
    WHERE DATE(fvr.report_date) BETWEEN ? AND ?
";

$stats_params = [$start, $end];

// Apply same filters as main query
if ($station_filter > 0) {
    $stats_query .= " AND fvr.station_id = ?";
    $stats_params[] = $station_filter;
}
if ($fuel_type_filter !== '') {
    $stats_query .= " AND fvr.fuel_type LIKE ?";
    $stats_params[] = "%$fuel_type_filter%";
}
if ($var_status_filter !== '') {
    $stats_query .= " AND fvr.status = ?";
    $stats_params[] = $var_status_filter;
}
if ($search !== '') {
    $stats_query .= " AND (s.name LIKE ? OR fvr.fuel_type LIKE ?)";
    $stats_params[] = "%$search%";
    $stats_params[] = "%$search%";
}

// Execute statistics query
$stats = [
    'total_variance_liters' => 0,
    'avg_variance_percent' => 0,
    'open_count' => 0,
    'investigating_count' => 0,
    'resolved_count' => 0,
    'total_count' => 0,
];

try {
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute($stats_params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $stats = array_merge($stats, $result);
    }
} catch (PDOException $e) {
    error_log("Statistics Query Failed: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// EXCEL EXPORT
// ═══════════════════════════════════════════════════════════════

if ($is_export) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="variance_reports_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
    
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>Report Date</th><th>Station</th><th>Fuel Type</th>';
    echo '<th>Expected (L)</th><th>Actual (L)</th><th>Variance (L)</th>';
    echo '<th>Variance %</th><th>Status</th><th>Reason</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($variance_reports as $vr) {
        echo '<tr>';
        echo '<td>' . date('Y-m-d', strtotime($vr['report_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($vr['station_name']) . '</td>';
        echo '<td>' . htmlspecialchars($vr['fuel_type']) . '</td>';
        echo '<td>' . number_format($vr['expected_stock'], 2) . '</td>';
        echo '<td>' . number_format($vr['actual_stock'], 2) . '</td>';
        echo '<td>' . number_format($vr['variance_liters'], 2) . '</td>';
        echo '<td>' . number_format($vr['variance_percent'], 2) . '%</td>';
        echo '<td>' . htmlspecialchars($vr['status']) . '</td>';
        echo '<td>' . htmlspecialchars($vr['reason']) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    exit;
}

// ═══════════════════════════════════════════════════════════════
// HTML OUTPUT
// ═══════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Variance Reports | Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Variance-specific styling */
        .ato-variance-critical { background: #dc3545; color: #fff; }
        .ato-variance-warning { background: #fd7e14; color: #fff; }
        .ato-variance-minor { background: #ffc107; color: #212529; }
        .ato-status-open { background: #dc3545; color: #fff; }
        .ato-status-investigating { background: #fd7e14; color: #fff; }
        .ato-status-resolved { background: #28a745; color: #fff; }
        .ato-variance-badge, .ato-status-badge { 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 0.85em;
            font-weight: 600;
        }
        .ato-summary-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .ato-sum-card {
            display: flex;
            flex-direction: column;
            padding: 8px 12px;
        }
        .ato-sum-label {
            font-size: 0.85em;
            color: #6c757d;
        }
        .ato-sum-value {
            font-size: 1.25em;
            font-weight: 700;
            color: #212529;
        }
        .ato-card-open .ato-sum-value { color: #dc3545; }
        .ato-card-investigating .ato-sum-value { color: #fd7e14; }
        .ato-card-resolved .ato-sum-value { color: #28a745; }
        .ato-sum-divider { color: #dee2e6; }
    </style>
</head>
<body>
    <?php include '../includes/admin_sidebar.php'; ?>
    
    <div class="main-content">
        <div class="ato-page-header">
            <h1><i class="fas fa-chart-line"></i> System-Wide Variance Reports</h1>
            <p class="ato-page-subtitle">Monitor fuel inventory discrepancies across all stations</p>
        </div>
        
        <!-- Filter Bar -->
        <div class="ato-filter-bar">
            <!-- [Insert filter bar HTML from components section] -->
        </div>
        
        <!-- Summary Statistics -->
        <div class="ato-summary-bar">
            <!-- [Insert summary bar HTML from components section] -->
        </div>
        
        <!-- Actions Bar -->
        <div class="ato-actions-bar">
            <!-- [Insert actions bar HTML from components section] -->
        </div>
        
        <!-- Variance Reports Table -->
        <div class="ato-table-container">
            <!-- [Insert variance table HTML from components section] -->
        </div>
        
        <!-- Pagination -->
        <div class="ato-pagination">
            <!-- [Insert pagination HTML from components section] -->
        </div>
    </div>
    
    <script>
        // [Insert pagination JavaScript from components section]
    </script>
</body>
</html>
```

### CSS Additions

**Add to assets/css/style.css**:
```css
/* ═══════════════════════════════════════════════════════════════
   VARIANCE REPORTS STYLING
   ═══════════════════════════════════════════════════════════════ */

.ato-variance-critical {
    background: #dc3545 !important;
    color: #fff !important;
}

.ato-variance-warning {
    background: #fd7e14 !important;
    color: #fff !important;
}

.ato-variance-minor {
    background: #ffc107 !important;
    color: #212529 !important;
}

.ato-status-open {
    background: #dc3545 !important;
    color: #fff !important;
}

.ato-status-investigating {
    background: #fd7e14 !important;
    color: #fff !important;
}

.ato-status-resolved {
    background: #28a745 !important;
    color: #fff !important;
}

.ato-variance-badge,
.ato-status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: 600;
    text-align: center;
}

.ato-type-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: 600;
    text-transform: uppercase;
}

.ato-type-merchandise {
    background: #17a2b8;
    color: #fff;
}

.ato-type-joborder {
    background: #6f42c1;
    color: #fff;
}

.ato-type-fuel {
    background: #fd7e14;
    color: #fff;
}

.ato-summary-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.ato-sum-card {
    display: flex;
    flex-direction: column;
    padding: 8px 12px;
}

.ato-sum-label {
    font-size: 0.85em;
    color: #6c757d;
    margin-bottom: 4px;
}

.ato-sum-value {
    font-size: 1.25em;
    font-weight: 700;
    color: #212529;
}

.ato-card-open .ato-sum-value {
    color: #dc3545;
}

.ato-card-investigating .ato-sum-value {
    color: #fd7e14;
}

.ato-card-resolved .ato-sum-value {
    color: #28a745;
}

.ato-sum-divider {
    color: #dee2e6;
    font-weight: 300;
}

.ato-number {
    text-align: right;
    font-family: 'Courier New', monospace;
}

.ato-reason-cell {
    max-width: 200px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
```

### Menu Structure Updates

**Update includes/admin_sidebar.php**:
```php
<!-- Admin menu structure -->
<li class="menu-item">
    <a href="#" class="menu-link">
        <i class="fas fa-exchange-alt"></i>
        <span>Transactions</span>
        <i class="fas fa-chevron-down submenu-toggle"></i>
    </a>
    <ul class="submenu">
        <li><a href="../public/admin_transactions_oversight.php">
            <i class="fas fa-eye"></i> Oversight Dashboard
        </a></li>
        <li><a href="../public/admin_variance_reports.php">
            <i class="fas fa-chart-line"></i> Variance Reports (system-wide)
        </a></li>
    </ul>
</li>
```



## Security Considerations

### 1. Access Control

**Role Verification (Both Pages)**:
```php
require_login();
$me = current_user();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['admin', 'superadmin'], true)) {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    header('Location: admin_dashboard.php');
    exit;
}
```

**Activity Logging**:
```php
// Log page access
log_activity($pdo, $me['id'], 'PAGE_ACCESS', 'Accessed variance reports page');

// Log export actions
if ($is_export) {
    log_activity($pdo, $me['id'], 'DATA_EXPORT', 
        'Exported variance reports: ' . date('Y-m-d') . ' (' . count($variance_reports) . ' records)');
}
```

### 2. SQL Injection Prevention

**Prepared Statements (All Queries)**:
```php
$stmt = $pdo->prepare($query);
$stmt->execute($params);
```

**Parameter Binding**:
```php
// Good - using prepared statements with parameter binding
$vr_params = [$start, $end];
if ($station_filter > 0) {
    $variance_query .= " AND fvr.station_id = ?";
    $vr_params[] = $station_filter;
}
```

**Input Validation**:
```php
// Integer validation
$station_filter = (int)($_GET['station'] ?? 0);

// String trimming and sanitization
$search = trim($_GET['search'] ?? '');
$fuel_type_filter = trim($_GET['fuel_type'] ?? '');

// Whitelist validation
$allowed_statuses = ['', 'Open', 'Under Investigation', 'Resolved'];
if (!in_array($var_status_filter, $allowed_statuses, true)) {
    $var_status_filter = '';
}
```

### 3. XSS Prevention

**Output Escaping**:
```php
// Always escape user-generated content
echo htmlspecialchars($row['station_name']);
echo htmlspecialchars($row['fuel_type']);
echo htmlspecialchars($row['reason']);
echo htmlspecialchars($txn['customer_name']);
```

**JavaScript Context Escaping**:
```php
// Integer values in JavaScript context
onclick="viewReport(<?php echo (int)$row['report_id']; ?>)"
```

**HTML Attribute Escaping**:
```php
<input type="text" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>">
```

### 4. CSRF Protection

**Form Tokens (if implementing POST actions)**:
```php
// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// In forms
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

// Verify on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $_SESSION['error'] = 'Invalid request token. Please try again.';
        exit;
    }
}
```

### 5. Session Security

**Session Configuration**:
```php
// In includes/auth.php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1'); // if using HTTPS
ini_set('session.use_strict_mode', '1');
```

**Session Validation**:
```php
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['error'] = 'Please log in to continue.';
        header('Location: login.php');
        exit;
    }
}
```

### 6. Information Disclosure Prevention

**Error Handling**:
```php
// Don't expose database errors to users
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
} catch (PDOException $e) {
    // Log detailed error for debugging
    error_log("Query Failed: " . $e->getMessage());
    error_log("Query: " . $query);
    
    // Show generic error to user
    $_SESSION['error'] = 'Unable to load data. Please try again.';
}
```

**Debug Mode Control**:
```php
// Only show detailed errors in development
if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
```

## Performance Optimization

### 1. Database Query Optimization

**Index Recommendations**:
```sql
-- fuel_variance_reports table
ALTER TABLE fuel_variance_reports 
ADD INDEX idx_report_date (report_date);

ALTER TABLE fuel_variance_reports 
ADD INDEX idx_station_date (station_id, report_date);

ALTER TABLE fuel_variance_reports 
ADD INDEX idx_status (status);

ALTER TABLE fuel_variance_reports 
ADD INDEX idx_fuel_type (fuel_type);

-- Compound index for common filter combinations
ALTER TABLE fuel_variance_reports 
ADD INDEX idx_composite (report_date, station_id, status);
```

**Query Limits**:
```php
// Limit results to prevent memory issues
$variance_query .= " ORDER BY fvr.report_date DESC LIMIT 500";
```

**Query Optimization Tips**:
- Use LEFT JOIN instead of subqueries where possible
- Avoid SELECT * - specify only needed columns
- Use COALESCE for NULL handling instead of multiple conditions
- Use CASE statements for conditional aggregation in statistics

### 2. Caching Strategy

**Filter Dropdown Caching**:
```php
// Cache station list for the day
$cache_key = 'variance_stations_' . date('Y-m-d');
if (!isset($_SESSION[$cache_key])) {
    $stmt = $pdo->query("SELECT DISTINCT s.id, s.name FROM stations s ...");
    $_SESSION[$cache_key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$stations = $_SESSION[$cache_key];

// Cache fuel types for the day
$cache_key = 'variance_fuel_types_' . date('Y-m-d');
if (!isset($_SESSION[$cache_key])) {
    $stmt = $pdo->query("SELECT DISTINCT fuel_type FROM fuel_variance_reports ...");
    $_SESSION[$cache_key] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
$fuel_types = $_SESSION[$cache_key];
```

### 3. Client-Side Optimization

**Pagination**:
- Implement client-side pagination to avoid re-querying
- Default to 25 rows per page for balance between usability and performance

**Lazy Loading** (future enhancement):
- Load initial 50 records, fetch more on scroll
- Reduces initial page load time

**Browser Caching**:
```php
// Cache static assets
header('Cache-Control: public, max-age=86400'); // 24 hours for CSS/JS
```

### 4. Export Optimization

**Memory Management**:
```php
// For large exports, use output buffering
if ($is_export && count($variance_reports) > 1000) {
    ob_start();
    // Output Excel header
    foreach ($variance_reports as $vr) {
        // Output row
        if (ob_get_length() > 4096) {
            ob_flush();
            flush();
        }
    }
    ob_end_flush();
}
```

## User Experience Design

### Visual Design Principles

1. **Consistency**: Both pages use same filter bar, pagination, and button styles
2. **Clarity**: Type badges and status badges use distinct colors for quick recognition
3. **Hierarchy**: Page headers, summary stats, and tables have clear visual separation
4. **Responsiveness**: Tables scroll horizontally on mobile without breaking layout

### Color Coding System

**Transaction Types** (admin_transactions_oversight.php):
- **Merchandise**: Cyan (#17a2b8) - retail products
- **Job Order**: Purple (#6f42c1) - services
- **Fuel**: Orange (#fd7e14) - fuel sales

**Variance Severity** (admin_variance_reports.php):
- **Critical (>5%)**: Red (#dc3545) - immediate attention required
- **Warning (2-5%)**: Orange (#fd7e14) - monitor closely
- **Minor (<2%)**: Yellow (#ffc107) - acceptable range

**Variance Report Status**:
- **Open**: Red (#dc3545) - awaiting investigation
- **Under Investigation**: Orange (#fd7e14) - actively being reviewed
- **Resolved**: Green (#28a745) - investigation complete

### Empty State Design

Both pages use consistent empty state messaging:
- Large icon (inbox)
- Clear message explaining no results
- Reset filters button for easy recovery

### Loading States

```html
<div id="loading-spinner" style="display:none;">
    <i class="fas fa-spinner fa-spin"></i> Loading data...
</div>
```

### Responsive Design

**Mobile Considerations**:
- Filter bar stacks vertically on small screens
- Tables scroll horizontally
- Pagination controls remain accessible
- Summary statistics wrap to multiple rows

## Accessibility Considerations

### ARIA Labels

```html
<table class="ato-table" role="table" aria-label="Variance Reports">
    <thead role="rowgroup">
        <tr role="row">
            <th role="columnheader" scope="col">Report Date</th>
            <!-- ... -->
        </tr>
    </thead>
</table>
```

### Keyboard Navigation

- Tab through all interactive elements
- Enter key submits filter form
- Escape key to reset filters (future enhancement)

### Screen Reader Support

```html
<span class="sr-only">Variance percentage: </span>
<span class="ato-variance-critical">-6.5%</span>

<span class="sr-only">Status: </span>
<span class="ato-status-open">Open</span>
```

### Color Contrast

- All text/background combinations meet WCAG AA standards (4.5:1)
- Status badges tested with color blindness simulators
- Alternative text indicators (icons) supplement color coding

## Migration Strategy

### Phase 1: Update admin_transactions_oversight.php

**Steps**:
1. Remove tab detection logic
2. Add type filter parameter handling
3. Update data fetching with type-based queries
4. Remove tab HTML from page
5. Update filter bar HTML to include type dropdown
6. Update table structure to show type badges
7. Test thoroughly

**Rollback**: If issues arise, revert to previous version stored in version control

### Phase 2: Create admin_variance_reports.php

**Steps**:
1. Create new file based on template
2. Implement filter parameter handling
3. Implement variance query with dynamic columns
4. Implement summary statistics query
5. Implement station/fuel type dropdown queries
6. Create HTML layout (header, filters, stats, table)
7. Add export functionality
8. Test thoroughly

**Verification**:
- Verify page loads without errors
- Verify queries return expected data
- Verify filters work correctly
- Verify export generates valid Excel file

### Phase 3: Update Menu Structure

**Steps**:
1. Update admin_sidebar.php to add "Variance Reports" menu item
2. Verify menu links navigate correctly
3. Verify active menu highlighting works

### Phase 4: Testing and Deployment

**Steps**:
1. Cross-browser testing
2. Mobile responsive testing
3. Performance testing with large datasets
4. Security audit
5. User acceptance testing
6. Deploy to production

**Post-Deployment Monitoring**:
- Monitor error logs for issues
- Track page load times
- Gather user feedback

## Dependencies

### PHP Extensions
- PDO (existing)
- PDO MySQL driver (existing)

### Database Tables
- `fuel_variance_reports` (existing, confirmed populated)
- `stations` (existing)
- `users` (existing)
- `merchandise` (existing)
- `job_orders` (existing)
- `fuel_transactions` (existing)

### External Libraries
- Font Awesome 6.0 (existing, for icons)
- No additional JavaScript libraries required

### Browser Requirements
- Modern browser with JavaScript enabled
- CSS Flexbox support
- CSS Grid support (for responsive layouts)

## Future Enhancements

### 1. Variance Report Details Page

**Route**: `admin_variance_details.php?id={report_id}`

**Features**:
- Full variance report details
- Investigation history timeline
- Resolution workflow actions
- Related transaction links
- Attached documentation

### 2. Variance Trend Visualization

**Features**:
- Line chart: Variance over time by station
- Bar chart: Variance by fuel type
- Pie chart: Status distribution
- Library: Chart.js or similar

### 3. Real-Time Updates

**Features**:
- Auto-refresh with configurable interval
- WebSocket updates for new variance reports
- Desktop notifications for critical variances

### 4. Advanced Filtering

**Features**:
- Date range presets (Last 7 days, Last 30 days, etc.)
- Multi-station selection with checkboxes
- Saved filter presets per admin user
- Export filter presets

### 5. Variance Investigation Workflow

**Features**:
- Assign investigator to variance report
- Add investigation notes
- Mark as resolved with resolution notes
- Email notifications to stakeholders

### 6. Multi-Station Aggregation View

**Features**:
- Toggle between flat list and grouped by station
- Station-level statistics
- Drill-down to individual reports
- Comparison across stations

### 7. Scheduled Reports

**Features**:
- Daily/weekly variance summary emails
- Configurable recipients by role
- Automatic Excel attachment
- Custom report templates

## Monitoring and Logging

### Application Logging

**Log Events**:
- Page access (both pages)
- Filter application
- Export actions
- Database query failures
- Performance metrics

**Log Format**:
```php
function log_activity($pdo, $user_id, $action, $details) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_log (user_id, action, details, ip_address, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) {
        error_log("Activity log failed: " . $e->getMessage());
    }
}
```

### Performance Monitoring

**Metrics to Track**:
- Query execution time
- Page load time
- Export generation time
- Number of records fetched

**Implementation**:
```php
$query_start = microtime(true);
$stmt->execute($params);
$query_time = microtime(true) - $query_start;

if ($query_time > 1.0) {
    error_log("SLOW QUERY: Variance reports took {$query_time}s");
    error_log("Params: " . json_encode($params));
}
```

### Error Tracking

**Categories**:
- Database connection failures
- Query execution errors
- Missing column errors
- Export generation failures
- Invalid input attempts

## Conclusion

This design provides a comprehensive two-page architecture for admin oversight:

1. **admin_transactions_oversight.php**: Unified view of validated transactions with type filtering
2. **admin_variance_reports.php**: Dedicated variance monitoring with system-wide statistics

### Key Design Decisions

1. **Two-Page Architecture**: Separate concerns for transaction oversight vs. variance monitoring
2. **No Tabs on Transactions Page**: Simple type filtering dropdown instead of tab navigation
3. **Dedicated Variance Page**: Focused interface with summary statistics and advanced filtering
4. **Dynamic Queries**: Parameter binding and column detection for database resilience
5. **Consistent UI Patterns**: Shared filter bars, pagination, and export across both pages
6. **Role-Based Security**: Both pages restricted to admin/superadmin roles
7. **Performance Limits**: 500-record query limits with client-side pagination

### Success Criteria

- ✅ Transactions page displays validated merchandise, job orders, and fuel transactions
- ✅ Type filtering works without tabs on transactions page
- ✅ Variance Reports page displays data from fuel_variance_reports table
- ✅ Summary statistics calculate correctly
- ✅ All queries use dynamic column detection
- ✅ Filters work correctly on both pages
- ✅ Export to Excel includes correct data from both pages
- ✅ Print layout works for both pages
- ✅ Pagination functions on both pages
- ✅ Access control enforced (admin/superadmin only)
- ✅ Page load time < 2 seconds with 500 records
- ✅ No regression in existing functionality

This design maintains the existing patterns while cleanly separating transaction oversight from variance monitoring, providing a focused and maintainable solution.
