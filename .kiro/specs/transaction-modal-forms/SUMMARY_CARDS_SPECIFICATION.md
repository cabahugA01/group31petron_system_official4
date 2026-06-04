# 📊 Transaction Module - Summary Cards Specification

**Purpose**: Quick snapshot metrics at the top of each Transaction dashboard  
**Placement**: Top row above main transaction table/tabs  
**Design System**: Petron Blue (#002F70) with clean card layout  

---

## 🎯 CARD PLACEMENT STRATEGY

**Rule**: Summary Cards appear ONLY on dashboard views, NOT on modal forms or secondary pages.

- ✅ Staff Transaction Dashboard (top of Job Order Tracker)
- ✅ Manager Transaction Dashboard (top of Pending/Validated tabs)
- ✅ Admin Transaction Dashboard (top of Oversight table)
- ❌ Modal forms (create, validate, adjust, reject)
- ❌ Print views or export pages
- ❌ Detail/drill-down pages

---

## 1️⃣ STAFF TRANSACTION DASHBOARD

### Page Location
`public/staff_job_order_tracker.php` (or equivalent staff transaction page)

### Placement
**Top row** above the Job Order Tracker table

### Summary Cards (4 cards)

#### Card 1: Transactions Encoded
```
┌─────────────────────────────────┐
│ 📝 TRANSACTIONS ENCODED         │
│                                 │
│        127                      │
│                                 │
│ Merchandise + Job Orders        │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-file-invoice`
- **Color**: Blue (#002F70)
- **Value**: Count of merchandise transactions + job orders encoded by current staff
- **Subtext**: "Merchandise + Job Orders"
- **Query**:
  ```sql
  SELECT 
    (SELECT COUNT(*) FROM merchandise_transactions 
     WHERE staff_id = ? AND DATE(created_at) = CURDATE()) +
    (SELECT COUNT(*) FROM job_orders 
     WHERE created_by = ? AND DATE(created_at) = CURDATE())
  ```

#### Card 2: Pending Payments
```
┌─────────────────────────────────┐
│ ⏳ PENDING PAYMENTS             │
│                                 │
│    ₱12,450.00 (8)               │
│                                 │
│ Awaiting Payment                │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-clock`
- **Color**: Yellow/Amber (#F59E0B)
- **Value**: Total value + count of unpaid/partial payments
- **Subtext**: "Awaiting Payment"
- **Query**:
  ```sql
  SELECT 
    SUM(total_amount - COALESCE(amount_paid, 0)) as balance,
    COUNT(*) as count
  FROM merchandise_transactions
  WHERE staff_id = ? 
    AND payment_status IN ('Pending', 'Partial')
    AND validation_status != 'Rejected'
  ```

#### Card 3: Utang Accounts
```
┌─────────────────────────────────┐
│ 💳 UTANG ACCOUNTS               │
│                                 │
│    ₱8,200.00 (5)                │
│                                 │
│ Credit/Receivables              │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-credit-card`
- **Color**: Red (#DC2626)
- **Value**: Total receivables + count of credit transactions
- **Subtext**: "Credit/Receivables"
- **Query**:
  ```sql
  SELECT 
    SUM(total_amount - COALESCE(amount_paid, 0)) as balance,
    COUNT(*) as count
  FROM merchandise_transactions
  WHERE staff_id = ? 
    AND payment_status = 'Utang'
    AND validation_status = 'Approved'
  ```

#### Card 4: Completed Job Orders
```
┌─────────────────────────────────┐
│ ✅ COMPLETED JOB ORDERS         │
│                                 │
│         23                      │
│                                 │
│ Services Finished               │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-check-circle`
- **Color**: Green (#059669)
- **Value**: Count of completed job orders
- **Subtext**: "Services Finished"
- **Query**:
  ```sql
  SELECT COUNT(*) 
  FROM job_orders
  WHERE created_by = ?
    AND status = 'Completed'
    AND DATE(completed_at) = CURDATE()
  ```

---

## 2️⃣ MANAGER TRANSACTION DASHBOARD

### Page Location
`public/manager_transactions.php` (with Pending/Validated tabs)

### Placement
**Top row** above the Pending/Validated Transactions tabs

### Summary Cards (4 cards)

#### Card 1: Pending Transactions
```
┌─────────────────────────────────┐
│ ⏰ PENDING TRANSACTIONS         │
│                                 │
│         18                      │
│                                 │
│ Awaiting Validation             │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-hourglass-half`
- **Color**: Amber (#F59E0B)
- **Value**: Count of staff-encoded transactions awaiting manager validation
- **Subtext**: "Awaiting Validation"
- **Query**:
  ```sql
  SELECT COUNT(*)
  FROM (
    SELECT id FROM merchandise_transactions 
    WHERE station_id = ? AND validation_status = 'Pending'
    UNION ALL
    SELECT id FROM job_orders 
    WHERE station_id = ? AND validation_status = 'Pending'
  ) AS pending
  ```
- **Action**: Click to filter Pending tab

#### Card 2: Validated Today
```
┌─────────────────────────────────┐
│ ✓ VALIDATED TODAY               │
│                                 │
│         42                      │
│                                 │
│ Approved Transactions           │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-check-double`
- **Color**: Green (#059669)
- **Value**: Count of transactions approved today by manager
- **Subtext**: "Approved Transactions"
- **Query**:
  ```sql
  SELECT COUNT(*)
  FROM (
    SELECT id FROM merchandise_transactions 
    WHERE station_id = ? 
      AND validation_status = 'Approved'
      AND DATE(validated_at) = CURDATE()
    UNION ALL
    SELECT id FROM job_orders 
    WHERE station_id = ? 
      AND validation_status = 'Approved'
      AND DATE(validated_at) = CURDATE()
  ) AS validated
  ```

#### Card 3: Variance Alerts
```
┌─────────────────────────────────┐
│ ⚠️ VARIANCE ALERTS              │
│                                 │
│          3                      │
│                                 │
│ Flagged Anomalies               │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-exclamation-triangle`
- **Color**: Red (#DC2626)
- **Value**: Count of flagged variance reports or anomalies
- **Subtext**: "Flagged Anomalies"
- **Query**:
  ```sql
  SELECT COUNT(*)
  FROM variance_reports
  WHERE station_id = ?
    AND status = 'Flagged'
    AND DATE(created_at) = CURDATE()
  ```
- **Action**: Click to view variance report details

#### Card 4: Pending Payments
```
┌─────────────────────────────────┐
│ 💰 PENDING PAYMENTS             │
│                                 │
│   ₱45,670.00 (12)               │
│                                 │
│ Validated But Unpaid            │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-money-bill-wave`
- **Color**: Blue (#002F70)
- **Value**: Total balance + count of validated but unpaid transactions
- **Subtext**: "Validated But Unpaid"
- **Query**:
  ```sql
  SELECT 
    SUM(total_amount - COALESCE(amount_paid, 0)) as balance,
    COUNT(*) as count
  FROM merchandise_transactions
  WHERE station_id = ?
    AND validation_status IN ('Approved', 'Completed')
    AND payment_status IN ('Pending', 'Partial', 'Utang')
  ```

---

## 3️⃣ ADMIN TRANSACTION DASHBOARD

### Page Location
`public/admin_transactions_oversight.php`

### Placement
**Top row** above the Validated Transactions table

### Summary Cards (5 cards)

#### Card 1: Total Validated Transactions
```
┌─────────────────────────────────┐
│ 📊 VALIDATED TRANSACTIONS       │
│                                 │
│         286                     │
│                                 │
│ System-Wide (Today)             │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-chart-line`
- **Color**: Blue (#002F70)
- **Value**: System-wide count of validated transactions today
- **Subtext**: "System-Wide (Today)"
- **Query**:
  ```sql
  SELECT COUNT(*)
  FROM (
    SELECT id FROM merchandise_transactions 
    WHERE validation_status IN ('Approved', 'Completed')
      AND DATE(validated_at) = CURDATE()
    UNION ALL
    SELECT id FROM job_orders 
    WHERE validation_status IN ('Approved', 'Completed')
      AND DATE(validated_at) = CURDATE()
  ) AS validated
  ```

#### Card 2: Pending Payments
```
┌─────────────────────────────────┐
│ 💵 PENDING PAYMENTS             │
│                                 │
│  ₱127,450.00 (34)               │
│                                 │
│ Unpaid Balances                 │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-file-invoice-dollar`
- **Color**: Amber (#F59E0B)
- **Value**: Total ₱ value + count of unpaid balances across all stations
- **Subtext**: "Unpaid Balances"
- **Query**:
  ```sql
  SELECT 
    SUM(total_amount - COALESCE(amount_paid, 0)) as balance,
    COUNT(*) as count
  FROM merchandise_transactions
  WHERE validation_status IN ('Approved', 'Completed')
    AND payment_status IN ('Pending', 'Partial')
  ```

#### Card 3: Outstanding Utang
```
┌─────────────────────────────────┐
│ 📋 OUTSTANDING UTANG            │
│                                 │
│   ₱89,230.00 (21)               │
│                                 │
│ Credit Receivables              │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-clipboard-list`
- **Color**: Red (#DC2626)
- **Value**: Total receivables + count tagged as credit/utang
- **Subtext**: "Credit Receivables"
- **Query**:
  ```sql
  SELECT 
    SUM(total_amount - COALESCE(amount_paid, 0)) as balance,
    COUNT(*) as count
  FROM merchandise_transactions
  WHERE validation_status IN ('Approved', 'Completed')
    AND payment_status = 'Utang'
  ```
- **Action**: Click to view detailed receivables report

#### Card 4: Variance Reports
```
┌─────────────────────────────────┐
│ ⚠️ VARIANCE REPORTS             │
│                                 │
│          7                      │
│                                 │
│ System-Wide Anomalies           │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-flag`
- **Color**: Orange (#EA580C)
- **Value**: Count of flagged anomalies system-wide
- **Subtext**: "System-Wide Anomalies"
- **Query**:
  ```sql
  SELECT COUNT(*)
  FROM variance_reports
  WHERE status IN ('Flagged', 'Under Investigation')
    AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  ```
- **Action**: Click to navigate to `admin_variance_reports.php`

#### Card 5: Receivables Aging
```
┌─────────────────────────────────┐
│ 📅 RECEIVABLES AGING            │
│                                 │
│  Current: ₱45K | Overdue: ₱12K  │
│                                 │
│ Aging Breakdown                 │
└─────────────────────────────────┘
```
- **Icon**: `fas fa-calendar-alt`
- **Color**: Purple (#6F42C1)
- **Value**: Breakdown of current vs overdue balances
- **Subtext**: "Aging Breakdown"
- **Query**:
  ```sql
  SELECT 
    SUM(CASE WHEN DATEDIFF(CURDATE(), transaction_date) <= 30 
        THEN total_amount - COALESCE(amount_paid, 0) ELSE 0 END) as current,
    SUM(CASE WHEN DATEDIFF(CURDATE(), transaction_date) > 30 
        THEN total_amount - COALESCE(amount_paid, 0) ELSE 0 END) as overdue
  FROM merchandise_transactions
  WHERE payment_status IN ('Pending', 'Partial', 'Utang')
    AND validation_status IN ('Approved', 'Completed')
  ```
- **Action**: Click to view detailed aging report

---

## 🎨 CARD DESIGN SPECIFICATION

### Card Structure
```html
<div class="summary-card-row">
  <div class="summary-card card-blue">
    <div class="card-icon">
      <i class="fas fa-icon-name"></i>
    </div>
    <div class="card-content">
      <div class="card-label">CARD TITLE</div>
      <div class="card-value">127</div>
      <div class="card-subtext">Subtext description</div>
    </div>
  </div>
  <!-- Repeat for other cards -->
</div>
```

### CSS Styling
```css
/* Summary Cards Container */
.summary-card-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

/* Individual Card */
.summary-card {
  background: #fff;
  border-radius: 12px;
  padding: 20px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  border: 1px solid #e9ecef;
  display: flex;
  align-items: center;
  gap: 16px;
  transition: all 0.2s ease;
  cursor: pointer;
}

.summary-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
  transform: translateY(-2px);
}

/* Card Icon */
.card-icon {
  width: 56px;
  height: 56px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  color: #fff;
  flex-shrink: 0;
}

/* Icon Colors */
.card-blue .card-icon { background: #002F70; }
.card-green .card-icon { background: #059669; }
.card-amber .card-icon { background: #F59E0B; }
.card-red .card-icon { background: #DC2626; }
.card-purple .card-icon { background: #6F42C1; }
.card-orange .card-icon { background: #EA580C; }

/* Card Content */
.card-content {
  flex: 1;
  min-width: 0;
}

.card-label {
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 8px;
}

.card-value {
  font-size: 28px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1;
  margin-bottom: 6px;
}

.card-subtext {
  font-size: 12px;
  color: #94a3b8;
  font-weight: 500;
}

/* Responsive */
@media (max-width: 768px) {
  .summary-card-row {
    grid-template-columns: 1fr;
    gap: 12px;
  }
  
  .summary-card {
    padding: 16px;
  }
  
  .card-icon {
    width: 48px;
    height: 48px;
    font-size: 20px;
  }
  
  .card-value {
    font-size: 24px;
  }
}

/* Print: Hide summary cards */
@media print {
  .summary-card-row {
    display: none;
  }
}
```

---

## 🔄 REAL-TIME UPDATES (Optional Enhancement)

For future implementation, cards can update without page refresh:

```javascript
// Auto-refresh summary cards every 30 seconds
function refreshSummaryCards() {
  fetch('/backend/api/get_summary_cards.php?role=' + userRole)
    .then(response => response.json())
    .then(data => {
      // Update card values
      document.querySelector('.card-value').textContent = data.count;
    });
}

setInterval(refreshSummaryCards, 30000);
```

---

## 📊 SUMMARY COMPARISON

| Dashboard | Cards | Primary Focus |
|-----------|-------|---------------|
| **Staff** | 4 cards | Personal workload + pending obligations |
| **Manager** | 4 cards | Validation queue + accuracy oversight |
| **Admin** | 5 cards | System-wide compliance + receivables |

---

## ✅ IMPLEMENTATION CHECKLIST

### Staff Dashboard
- [ ] Add 4 summary cards above Job Order Tracker
- [ ] Query: Transactions Encoded (merchandise + job orders)
- [ ] Query: Pending Payments (unpaid balances)
- [ ] Query: Utang Accounts (credit receivables)
- [ ] Query: Completed Job Orders (services finished)
- [ ] Add CSS styling
- [ ] Test responsive layout

### Manager Dashboard
- [ ] Add 4 summary cards above Pending/Validated tabs
- [ ] Query: Pending Transactions (awaiting validation)
- [ ] Query: Validated Today (approved count)
- [ ] Query: Variance Alerts (flagged anomalies)
- [ ] Query: Pending Payments (validated but unpaid)
- [ ] Add click actions (filter tables)
- [ ] Add CSS styling
- [ ] Test responsive layout

### Admin Dashboard
- [ ] Add 5 summary cards above Validated Transactions table
- [ ] Query: Total Validated Transactions (system-wide)
- [ ] Query: Pending Payments (₱ value + count)
- [ ] Query: Outstanding Utang (credit receivables)
- [ ] Query: Variance Reports (system-wide anomalies)
- [ ] Query: Receivables Aging (current vs overdue)
- [ ] Add click actions (navigate to reports)
- [ ] Add CSS styling
- [ ] Test responsive layout

### Styling
- [ ] Add `.summary-card-row` container styles
- [ ] Add `.summary-card` card styles with hover effects
- [ ] Add `.card-icon` with color variants
- [ ] Add responsive breakpoints
- [ ] Hide cards in print mode

---

## 🎯 DESIGN PRINCIPLES

1. **Clarity**: Each card shows ONE key metric
2. **Consistency**: Same design pattern across all dashboards
3. **Actionable**: Cards are clickable to drill down
4. **Visual Hierarchy**: Large numbers, small labels
5. **Color Coding**: Status-based colors (blue, green, amber, red)
6. **Responsive**: Grid adapts from 4/5 columns to single column on mobile

---

**Status**: Specification Complete ✅  
**Next Step**: Implementation in respective dashboard PHP files  
**Priority**: Medium (enhances UX, not blocking core functionality)
