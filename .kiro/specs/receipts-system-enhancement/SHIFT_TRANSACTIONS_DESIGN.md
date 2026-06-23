# Design: Shift Transactions Page Redesign

## Overview
Redesign the Shift Transactions page (`transactions_shift.php`) to display **individual transaction records** grouped/filtered by shift, instead of showing shift log summary data.

## Current vs. Desired State

### Current State (What exists now):
**Focus:** Shift log monitoring
**Data shown:** Labor sessions (shifts) with aggregated totals
**Table Columns:**
- Shift ID, Staff, Shift Period, Start Time, End Time, Duration, Status
- Merch Sales (total), JO Sales (total), Total Sales, Transaction Count, Variances

**Use Case:** Manager reviews shift attendance and aggregated sales per shift

---

### Desired State (What you want):
**Focus:** Transaction-level monitoring per shift
**Data shown:** Individual transactions, filterable by shift
**Table Columns:**
- Transaction ID, Customer Name, Transaction Type, Amount, Payment Method, Staff Encoder, Date & Time, Actions

**Use Case:** Manager reviews individual transactions that occurred during specific shifts

---

## New Page Structure

### 1. Page Header
```
┌─────────────────────────────────────────────┐
│  🕒 SHIFT TRANSACTIONS              [Back]  │
│  Monitor transactions per shift              │
└─────────────────────────────────────────────┘
```

**Elements:**
- Title: "Shift Transactions"
- Subtitle: "Monitor transactions per shift"
- Back button → returns to previous page or transactions menu

---

### 2. KPI Cards (4 cards in responsive grid)

```
┌─────────────┬─────────────┬─────────────┬─────────────┐
│ 🌤 Shift 1  │ 🌙 Shift 2  │ 📊 Shift 1  │ 📊 Shift 2  │
│ Sales       │ Sales       │ Transactions│ Transactions│
│ ₱15,240.50  │ ₱18,650.00  │ 45 txns     │ 52 txns     │
└─────────────┴─────────────┴─────────────┴─────────────┘
```

**KPI Card 1: Shift 1 Sales**
- Icon: 🌤 (daytime sun)
- Label: "Shift 1 Sales"
- Value: Total amount (₱)
- Color: Orange/Yellow theme
- Subtext: Transaction count (e.g., "45 transactions")

**KPI Card 2: Shift 2 Sales**
- Icon: 🌙 (nighttime moon)
- Label: "Shift 2 Sales"
- Value: Total amount (₱)
- Color: Blue/Purple theme
- Subtext: Transaction count (e.g., "52 transactions")

**KPI Card 3: Shift 1 Transactions**
- Icon: 📊 (bar chart)
- Label: "Shift 1 Transactions"
- Value: Count
- Color: Green theme

**KPI Card 4: Shift 2 Transactions**
- Icon: 📊 (bar chart)
- Label: "Shift 2 Transactions"
- Value: Count
- Color: Blue theme

**Optional KPI Card 5 & 6:** Shift 3 (if 3-shift system is used)

---

### 3. Filters Section

```
┌─────────────────────────────────────────────┐
│ 📅 DATE RANGE                               │
│ From: [05/24/2026] To: [06/23/2026]        │
│                                             │
│ 🕐 SHIFT                                    │
│ [All Shifts ▼]                              │
│                                             │
│ [🔍 Search] [↻ Reset]                      │
└─────────────────────────────────────────────┘
```

**Filter A: Date Range**
- From Date (date picker)
- To Date (date picker)
- Default: Last 7 days

**Filter B: Shift Selection**
- Dropdown with options:
  - All Shifts (default - shows all transactions)
  - Shift 1 / First Shift (6:00 AM - 2:00 PM)
  - Shift 2 / Second Shift (2:00 PM - 10:00 PM)
  - Shift 3 / Third Shift (10:00 PM - 6:00 AM) - if applicable

**Buttons:**
- Search button (primary blue) - Apply filters
- Reset button (secondary gray) - Clear all filters

---

### 4. Export Buttons

```
[📤 Excel] [📄 CSV] [📋 PDF]
```

**Located:** Top right, next to Back button

**Export behavior:**
- Respects active filters (date range, shift selection)
- Includes KPI summary at top of export
- Filename format: `ShiftTransactions_YYYY-MM-DD_HHMMSS.ext`

---

### 5. Transaction Table (8 columns)

```
┌──────────────┬───────────┬───────────────┬─────────┬────────────┬──────────────┬────────────┬─────────┐
│ Transaction  │ Customer  │ Transaction   │ Amount  │ Payment    │ Staff        │ Date &     │ Actions │
│ ID           │ Name      │ Type          │         │ Method     │ Encoder      │ Time       │         │
├──────────────┼───────────┼───────────────┼─────────┼────────────┼──────────────┼────────────┼─────────┤
│ MERCH...6055 │ Walk-in   │ Merchandise   │ ₱224.00 │ Cash       │ Judy L.      │ Jun 23     │ [View]  │
│              │ Customer  │ Only          │         │            │              │ 11:41 AM   │         │
│              │           │               │         │            │              │ 🌤 Shift 1 │         │
├──────────────┼───────────┼───────────────┼─────────┼────────────┼──────────────┼────────────┼─────────┤
│ JO-5         │ AMIE      │ Job Order     │ ₱560.00 │ Cash       │ Judy L.      │ Jun 23     │ [View]  │
│              │ CABANIUS  │ Only          │         │            │              │ 02:46 PM   │         │
│              │           │               │         │            │              │ 🌙 Shift 2 │         │
└──────────────┴───────────┴───────────────┴─────────┴────────────┴──────────────┴────────────┴─────────┘
```

**Column 1: Transaction ID**
- Format: `MERCH...` for merchandise, `JO-XXX` for job orders
- Monospace font
- Clickable (opens details modal)
- Color: Petron blue

**Column 2: Customer Name**
- Shows customer name or "Walk-in Customer"
- Text truncation if too long (with tooltip)

**Column 3: Transaction Type**
- Badge with color coding:
  - **Job Order Only** - Purple badge
  - **Merchandise Only** - Blue badge
  - **Combined** - Green badge

**Column 4: Amount**
- Right-aligned
- Peso sign (₱) prefix
- Two decimal places
- Bold font

**Column 5: Payment Method**
- Cash, GCash, Card, Bank Transfer, Credit, etc.
- Plain text

**Column 6: Staff Encoder**
- Name of staff who created the transaction
- Shortened if too long (e.g., "Judy Lastimosa" → "Judy L.")

**Column 7: Date & Time**
- Format: MMM DD, YYYY HH:MM AM/PM
- Example: "Jun 23, 2026 11:41 AM"
- **Shift indicator below:**
  - 🌤 Shift 1 (orange/yellow background)
  - 🌙 Shift 2 (blue/purple background)
  - 🌃 Shift 3 (dark blue background) - if applicable

**Column 8: Actions**
- **View Details** button - Opens transaction details modal
- Icon: 👁 (eye) or text "View"
- Color: Blue

---

## Database Queries

### Query 1: Get All Transactions with Shift Assignment

```sql
-- Unified query to get both merchandise and job order transactions
SELECT 
    -- Common fields
    txn.id,
    txn.transaction_id,
    txn.customer_name,
    txn.total_amount AS amount,
    txn.payment_method,
    txn.created_at,
    txn.staff_id,
    u.name AS staff_name,
    
    -- Transaction type determination
    CASE 
        WHEN txn.source = 'job_orders' THEN 'Job Order'
        WHEN txn.source = 'merchandise_transactions' AND txn.has_service = 1 THEN 'Combined'
        ELSE 'Merchandise Only'
    END AS transaction_type,
    
    -- Shift assignment based on time
    CASE 
        WHEN HOUR(txn.created_at) >= 6 AND HOUR(txn.created_at) < 14 THEN 'Shift 1'
        WHEN HOUR(txn.created_at) >= 14 AND HOUR(txn.created_at) < 22 THEN 'Shift 2'
        ELSE 'Shift 3'
    END AS shift,
    
    CASE 
        WHEN HOUR(txn.created_at) >= 6 AND HOUR(txn.created_at) < 14 THEN 'shift1'
        WHEN HOUR(txn.created_at) >= 14 AND HOUR(txn.created_at) < 22 THEN 'shift2'
        ELSE 'shift3'
    END AS shift_key

FROM (
    -- Merchandise transactions
    SELECT 
        id,
        transaction_id,
        customer_name,
        total_amount,
        payment_method,
        COALESCE(transaction_date, created_at) AS created_at,
        staff_id,
        'merchandise_transactions' AS source,
        CASE WHEN job_order_service IS NOT NULL AND TRIM(job_order_service) != '' THEN 1 ELSE 0 END AS has_service
    FROM merchandise_transactions
    WHERE station_id = ?
    
    UNION ALL
    
    -- Job orders
    SELECT 
        id,
        CONCAT('JO-', id) AS transaction_id,
        customer_name,
        COALESCE(total_cost, estimated_cost, 0) AS total_amount,
        payment_method,
        created_at,
        COALESCE(created_by, user_id) AS staff_id,
        'job_orders' AS source,
        1 AS has_service
    FROM job_orders
    WHERE station_id = ?
) AS txn
LEFT JOIN users u ON u.id = txn.staff_id
WHERE DATE(txn.created_at) BETWEEN ? AND ?
  -- Optional shift filter
  AND (
      ? = '' -- If "All Shifts" selected
      OR CASE 
          WHEN HOUR(txn.created_at) >= 6 AND HOUR(txn.created_at) < 14 THEN 'shift1'
          WHEN HOUR(txn.created_at) >= 14 AND HOUR(txn.created_at) < 22 THEN 'shift2'
          ELSE 'shift3'
      END = ?
  )
ORDER BY txn.created_at DESC
LIMIT 500
```

**Parameters:**
1. `$station_id` (for merchandise)
2. `$station_id` (for job orders)
3. `$date_from`
4. `$date_to`
5. `$shift_filter` (empty string or 'shift1', 'shift2', 'shift3')
6. `$shift_filter` (repeated for CASE comparison)

---

### Query 2: Get KPI Totals per Shift

```sql
SELECT 
    shift_key,
    COUNT(*) AS transaction_count,
    SUM(amount) AS total_sales
FROM (
    SELECT 
        COALESCE(total_amount, 0) AS amount,
        CASE 
            WHEN HOUR(COALESCE(transaction_date, created_at)) >= 6 AND HOUR(COALESCE(transaction_date, created_at)) < 14 THEN 'shift1'
            WHEN HOUR(COALESCE(transaction_date, created_at)) >= 14 AND HOUR(COALESCE(transaction_date, created_at)) < 22 THEN 'shift2'
            ELSE 'shift3'
        END AS shift_key
    FROM merchandise_transactions
    WHERE station_id = ?
      AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
    
    UNION ALL
    
    SELECT 
        COALESCE(total_cost, estimated_cost, 0) AS amount,
        CASE 
            WHEN HOUR(created_at) >= 6 AND HOUR(created_at) < 14 THEN 'shift1'
            WHEN HOUR(created_at) >= 14 AND HOUR(created_at) < 22 THEN 'shift2'
            ELSE 'shift3'
        END AS shift_key
    FROM job_orders
    WHERE station_id = ?
      AND DATE(created_at) BETWEEN ? AND ?
) AS combined_transactions
GROUP BY shift_key
```

**Result:**
```
shift_key | transaction_count | total_sales
----------|-------------------|-----------
shift1    | 45                | 15240.50
shift2    | 52                | 18650.00
shift3    | 8                 | 2100.00
```

---

## PHP Implementation Structure

### File: `transactions_shift.php`

```php
<?php
$page_id = 'manager_shift_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Access control
if (!in_array($role, ['manager','admin','superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard.php'); 
    exit;
}

// ── Filters ────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$shift_filter = $_GET['shift'] ?? ''; // '', 'shift1', 'shift2', 'shift3'

// ── Fetch KPI Data ─────────────────────────────────────
$kpi = [
    'shift1' => ['transactions' => 0, 'sales' => 0.00],
    'shift2' => ['transactions' => 0, 'sales' => 0.00],
    'shift3' => ['transactions' => 0, 'sales' => 0.00],
];

try {
    $stmt = $pdo->prepare("/* KPI Query from above */");
    $stmt->execute([$station_id, $date_from, $date_to, $station_id, $date_from, $date_to]);
    $kpi_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($kpi_rows as $row) {
        $key = $row['shift_key'];
        $kpi[$key] = [
            'transactions' => (int)$row['transaction_count'],
            'sales' => (float)$row['total_sales']
        ];
    }
} catch (Exception $e) {
    error_log("KPI fetch error: " . $e->getMessage());
}

// ── Fetch Transaction List ─────────────────────────────
$transactions = [];
try {
    $stmt = $pdo->prepare("/* Transaction List Query from above */");
    $stmt->execute([
        $station_id,
        $station_id,
        $date_from,
        $date_to,
        $shift_filter,
        $shift_filter
    ]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Transaction fetch error: " . $e->getMessage());
    $transactions = [];
}

// ── CSV Export ─────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ShiftTransactions_'.date('Ymd_His').'.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, ['Transaction ID', 'Customer Name', 'Transaction Type', 'Amount', 'Payment Method', 'Staff Encoder', 'Date & Time', 'Shift']);
    
    // Data rows
    foreach ($transactions as $txn) {
        fputcsv($output, [
            $txn['transaction_id'],
            $txn['customer_name'],
            $txn['transaction_type'],
            number_format($txn['amount'], 2),
            $txn['payment_method'],
            $txn['staff_name'],
            date('M d, Y h:i A', strtotime($txn['created_at'])),
            $txn['shift']
        ]);
    }
    
    fclose($output);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<!-- HTML TEMPLATE HERE -->

<?php include __DIR__ . '/../partials/footer.php'; ?>
```

---

## CSS Styling

```css
/* KPI Cards */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.kpi-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.kpi-card.shift1 {
    border-left: 4px solid #f59e0b;
}

.kpi-card.shift2 {
    border-left: 4px solid #4f46e5;
}

.kpi-icon {
    font-size: 24px;
    margin-bottom: 8px;
}

.kpi-label {
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.kpi-value {
    font-size: 28px;
    font-weight: 800;
    color: #002F70;
    margin-top: 4px;
}

.kpi-subtext {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 4px;
}

/* Shift Indicator Badge */
.shift-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 600;
    margin-top: 4px;
}

.shift-badge.shift1 {
    background: #fef3c7;
    color: #92400e;
}

.shift-badge.shift2 {
    background: #e0e7ff;
    color: #3730a3;
}

.shift-badge.shift3 {
    background: #f3f4f6;
    color: #1f2937;
}

/* Transaction Type Badge */
.txn-type-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}

.txn-type-badge.job-order {
    background: #f5f3ff;
    color: #6d28d9;
    border: 1px solid #ddd6fe;
}

.txn-type-badge.merchandise {
    background: #eff6ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}

.txn-type-badge.combined {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}
```

---

## Implementation Checklist

### Phase 1: Backend (Database & PHP)
- [ ] Create unified transaction query (merchandise + job orders)
- [ ] Implement shift assignment logic based on timestamp
- [ ] Create KPI aggregation query
- [ ] Add date range filter handling
- [ ] Add shift selection filter handling
- [ ] Implement CSV export functionality
- [ ] Test with sample data

### Phase 2: Frontend (HTML & CSS)
- [ ] Create page header with title and back button
- [ ] Build KPI cards grid (4 cards)
- [ ] Build filters section (date range + shift dropdown)
- [ ] Create transaction table (8 columns)
- [ ] Add shift indicator badges to Date & Time column
- [ ] Style transaction type badges
- [ ] Add export buttons (Excel, CSV, PDF)
- [ ] Ensure mobile responsiveness

### Phase 3: Actions & Modals
- [ ] Implement "View Details" button functionality
- [ ] Create transaction details modal
- [ ] Load transaction details via AJAX
- [ ] Display complete transaction information in modal
- [ ] Add print button to modal
- [ ] Add close button to modal

### Phase 4: Testing
- [ ] Test with Shift 1 transactions only
- [ ] Test with Shift 2 transactions only
- [ ] Test with "All Shifts" filter
- [ ] Test date range filtering
- [ ] Test combined filters (date + shift)
- [ ] Test export functionality
- [ ] Test on mobile devices
- [ ] Test with large datasets (500+ transactions)

### Phase 5: Polish
- [ ] Add loading indicators
- [ ] Add empty state messages
- [ ] Add error handling
- [ ] Add success/error notifications
- [ ] Add pagination (if needed)
- [ ] Optimize database queries
- [ ] Add caching (if needed)

---

## Success Criteria

### Functional:
- ✅ KPI cards display correct totals per shift
- ✅ Shift filter accurately separates transactions
- ✅ Date range filter works correctly
- ✅ Table displays all 8 columns without horizontal scroll
- ✅ Shift indicator visible in each transaction row
- ✅ Export buttons generate correct files
- ✅ View Details modal shows complete transaction info

### Performance:
- ✅ Page loads within 2 seconds
- ✅ Filter application takes < 1 second
- ✅ Export generates within 5 seconds for 1000 records

### UX:
- ✅ Mobile-responsive design
- ✅ Clear visual hierarchy
- ✅ Intuitive filter controls
- ✅ Helpful empty states
- ✅ Consistent styling with rest of system

---

**Document Version:** 1.0
**Created:** June 23, 2026
**Status:** Ready for Implementation
**Estimated Effort:** 8-12 hours
