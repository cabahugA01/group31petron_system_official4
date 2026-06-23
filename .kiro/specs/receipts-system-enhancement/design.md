# Design: Transaction History Simplification

## Overview
This design document outlines the technical implementation for simplifying the Staff Transaction History page by removing the Shift History/Shift Log section and streamlining the transaction display.

## Files to Modify

### Primary File
- **File:** `c:\xampp\htdocs\group31petron_system_official4\public\staff_transactions_hub.php`
- **Lines:** Approximately 7960-8250 (history section)
- **Purpose:** Remove Shift History section, rename page, keep only Transaction History

## Current Implementation Analysis

### Section Structure (history section)
```
1. Page Header
   - Title: "Shift History"
   - Subtitle: "Your shift log history"
   - Back button

2. Shift Log Card (TO BE REMOVED)
   - Table with: Shift, Clock In, Clock Out, Duration
   - Pagination controls
   - JavaScript for client-side pagination
   - Query: labor_sessions table

3. Transaction History Card (KEEP)
   - Filter tabs: All / Job Order Only / Merchandise Only / Combined
   - Table with 6 columns
   - Transaction data from merchandise_transactions
```

### Database Queries (history section)

**Shift Log Query (lines ~667-700) - TO BE REMOVED:**
```php
$ls_where  = "WHERE ls.user_id = ? AND ls.station_id = ?";
$ls_params = [$me['id'], $station_id];

if ($filter_shift !== '') {
    $ls_where  .= " AND ls.shift_period = ?";
    $ls_params[] = $filter_shift;
}
if ($filter_date !== '') {
    $ls_where  .= " AND DATE(ls.start_time) = ?";
    $ls_params[] = $filter_date;
}

$stmt = $pdo->prepare("
    SELECT ls.*, 
           COALESCE(ls.shift_name, sp.shift_name, ls.shift_period) AS shift_label,
           TIMESTAMPDIFF(MINUTE, ls.start_time, COALESCE(ls.end_time, NOW())) AS duration_minutes
    FROM labor_sessions ls
    LEFT JOIN shift_periods sp ON sp.shift_key = ls.shift_period
    $ls_where
    ORDER BY ls.start_time DESC
");
$stmt->execute($ls_params);
$shift_log = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

**Transaction History Query (lines ~8093+) - KEEP:**
```php
// Merge merchandise transactions with job order data
foreach ($recent_merch as $mt) {
    $txn_type = 'Merchandise Only';
    $jo_svc = $mt['job_order_service'] ?? '';
    if (!empty($jo_svc) && trim($jo_svc) !== '') {
        $has_items = !empty($mt['products']) && $mt['products'] !== '—';
        $txn_type = $has_items ? 'Combined' : 'Job Order Only';
    }
    $txn_history[] = [
        'id'             => $mt['id'],
        'transaction_id' => $mt['transaction_id'] ?? ('#MT-'.$mt['id']),
        'type'           => $txn_type,
        'customer'       => $mt['customer_name'] ?? 'Walk-in Customer',
        'amount'         => (float)($mt['total_amount'] ?? 0),
        'payment_method' => $mt['payment_method'] ?? '—',
        'date'           => $mt['transaction_date'] ?? '',
        'status'         => $mt['status'] ?? '—',
        'source'         => 'merchandise',
    ];
}
```

## Implementation Plan

### Step 1: Update Page Title and Header
**Location:** Lines ~7967-7980

**Current:**
```php
<h1>Shift History</h1>
<p>Your shift log history</p>
```

**New:**
```php
<h1>Transaction History</h1>
<p>Your transaction records</p>
```

### Step 2: Remove Shift Log Section
**Location:** Lines ~7984-8090

**Remove entirely:**
- Shift Log card container
- Shift Log table (thead, tbody with foreach loop)
- Pagination controls (Rows per page, Page navigation)
- JavaScript pagination code (slState, slRender, slGoPage, slChangePerPage)
- Empty state message

**Code to Delete:**
```php
<!-- Shift Log -->
<?php if (!empty($shift_log)): ?>
<div class="txn-card">
    ... [entire shift log card] ...
</div>
<script>
    ... [shift log pagination JavaScript] ...
</script>
<?php else: ?>
<div style="text-align:center...">
    ... [empty shift log message] ...
</div>
<?php endif; ?>
```

### Step 3: Remove Shift Log Database Query
**Location:** Lines ~667-700 (in PHP section before HTML)

**Remove:**
```php
// ── Shift log from labor_sessions ─────────────────────────────────────
$shift_log = [];
try {
    $ls_where  = "WHERE ls.user_id = ? AND ls.station_id = ?";
    ... [entire shift log query] ...
} catch (Exception $e) { $shift_log = []; }
```

### Step 4: Keep Transaction History Section
**Location:** Lines ~8093+ (after removed shift log)

**No changes needed** - this section already has:
- ✅ Filter tabs (All, Job Order Only, Merchandise Only, Combined)
- ✅ Transaction table with 6 columns
- ✅ Proper transaction type logic
- ✅ Pagination (if implemented)

**Transaction History Structure (KEEP AS-IS):**
```php
<div class="txn-card" style="margin-top:20px;">
    <div class="txn-card-header">
        <i class="fas fa-history" style="color:#003d7a;"></i>
        <h3>Transaction History</h3>
    </div>
    <div class="txn-card-body" style="padding:0;">
        <!-- Filter Tabs -->
        <div style="display:flex;gap:0;...">
            <button onclick="filterTxnHistory('all')" ...>All</button>
            <button onclick="filterTxnHistory('job')" ...>Job Order Only</button>
            <button onclick="filterTxnHistory('merch')" ...>Merchandise Only</button>
            <button onclick="filterTxnHistory('combined')" ...>Combined</button>
        </div>
        
        <!-- Transaction Table -->
        <table class="txn-table">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Transaction Type</th>
                    <th>Customer</th>
                    <th>Amount</th>
                    <th>Payment Method</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($txn_history as $txn): ?>
                <tr data-type="<?= $txn['type'] ?>">
                    ... [transaction row] ...
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
```

### Step 5: Update Navigation/Menu (If Needed)
**Check:** Sidebar navigation label

If the sidebar menu or navigation has "Shift History" link, update it to "Transaction History"

**File to check:** `partials/rbac_menu.php` or sidebar navigation section

**Change:**
```php
// FROM:
<a href="staff_transactions_hub.php?section=history">
    <i class="fas fa-clock"></i> Shift History
</a>

// TO:
<a href="staff_transactions_hub.php?section=history">
    <i class="fas fa-history"></i> Transaction History
</a>
```

## Visual Layout Changes

### Before (Current Layout):
```
┌─────────────────────────────────────────────┐
│  SHIFT HISTORY                      [Back]  │
│  Your shift log history                     │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│  🕒 Shift Log                               │
├─────────────────────────────────────────────┤
│  Shift  │  Clock In  │  Clock Out │ Duration│
│  ──────────────────────────────────────────│
│  First   Jun 25 6AM   Jun 25 2PM   8h      │
│  Second  Jun 23 2PM   Active       5h 22m  │
│                                             │
│  Rows per page: [10▼]     Page 1 of 1 ◀ ▶ │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│  📜 Transaction History                     │
├─────────────────────────────────────────────┤
│  [All] [Job Order] [Merchandise] [Combined]│
│                                             │
│  Txn ID │ Type │ Customer │ Amount │ ...   │
│  ────────────────────────────────────────── │
│  ...transaction data...                     │
└─────────────────────────────────────────────┘
```

### After (Simplified Layout):
```
┌─────────────────────────────────────────────┐
│  TRANSACTION HISTORY            [Back]      │
│  Your transaction records                   │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│  📜 Transaction History                     │
├─────────────────────────────────────────────┤
│  [All] [Job Order] [Merchandise] [Combined]│
│                                             │
│  Txn ID │ Type │ Customer │ Amount │ ...   │
│  ────────────────────────────────────────── │
│  ...transaction data...                     │
└─────────────────────────────────────────────┘
```

## Code Changes Summary

### Deletions:
1. **Lines ~7984-8090:** Entire Shift Log card HTML structure
2. **Lines ~667-700:** Shift log database query and variable initialization
3. **Variables to remove:** `$shift_log`, `$ls_where`, `$ls_params`, `$available_shifts` (if only used for shift log)

### Modifications:
1. **Line ~7970:** Change `<h1>Shift History</h1>` → `<h1>Transaction History</h1>`
2. **Line ~7971:** Change `<p>Your shift log history</p>` → `<p>Your transaction records</p>`
3. **Navigation/Sidebar:** Update "Shift History" link label to "Transaction History" (if applicable)

### Keep As-Is:
1. **Transaction History card** (lines ~8093+)
2. **Filter tabs** (All, Job Order Only, Merchandise Only, Combined)
3. **Transaction table** with 6 columns
4. **filterTxnHistory() JavaScript function**
5. **$txn_history array** and its population logic

## Testing Checklist

### Functional Testing:
- [ ] Page loads without errors after removing shift log code
- [ ] Page title shows "Transaction History"
- [ ] No shift log table is displayed
- [ ] Transaction History card displays correctly
- [ ] All 4 filter tabs work (All, Job Order, Merchandise, Combined)
- [ ] Transaction data displays with correct columns
- [ ] Back button navigates correctly
- [ ] No JavaScript errors in browser console
- [ ] No PHP errors in server logs

### Visual Testing:
- [ ] Page layout is clean without empty spaces
- [ ] Transaction History card is prominent
- [ ] Card styling matches existing design
- [ ] Mobile-responsive layout works

### Data Integrity Testing:
- [ ] All transaction types display correctly
- [ ] Job Order Only transactions show only service transactions
- [ ] Merchandise Only transactions show only product transactions
- [ ] Combined transactions show correctly in both trackers
- [ ] Transaction amounts are accurate
- [ ] Dates are formatted correctly

## Rollback Plan

If issues occur:
1. **Backup:** Keep original `staff_transactions_hub.php` as `staff_transactions_hub.php.backup`
2. **Git:** Commit changes with clear message: "Remove shift history from staff transaction page"
3. **Restore:** Simply copy backup file back if critical issues arise

## Performance Impact

**Expected Improvements:**
- ✅ Faster page load (no shift log query execution)
- ✅ Reduced database load (one less query per page load)
- ✅ Smaller HTML payload (no shift log table markup)
- ✅ Less JavaScript execution (no shift log pagination code)

**Estimated Performance Gain:**
- Page load time: ~15-20% faster
- Database queries: 1 fewer query per request
- HTML size: ~2-3KB reduction
- JavaScript execution: Minimal improvement

## Dependencies

**No external dependencies affected.**

Changes are isolated to:
- `staff_transactions_hub.php` (main file)
- Potentially `rbac_menu.php` or sidebar navigation (label update)

**No database schema changes required.**

## Security Considerations

**No security implications.**

Changes are purely presentational (removing UI elements). The shift log data remains in the database and can still be accessed by managers/admins through other interfaces if needed.

## Accessibility

**Improvements:**
- Simpler page structure = better screen reader navigation
- Single focus area (transactions) = reduced cognitive load
- Clearer page title = better context for users

## Browser Compatibility

**No compatibility issues expected.**

Changes involve:
- Removing HTML elements (universally supported)
- Removing JavaScript functions (no new features)
- Standard CSS (existing styles remain)

---

**Document Version:** 1.0
**Created:** June 23, 2026
**Status:** Ready for Implementation
