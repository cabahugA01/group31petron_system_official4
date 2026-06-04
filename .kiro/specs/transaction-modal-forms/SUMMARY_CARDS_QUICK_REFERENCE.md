# 📊 Summary Cards - Quick Reference Guide

**Purpose**: Quick snapshot metrics for Transaction Module dashboards  
**Design**: Clean card layout with Petron color scheme  

---

## 🎯 CARD PLACEMENT (Top Row Only)

| Dashboard | Cards | Placement |
|-----------|-------|-----------|
| **Staff** | 4 cards | Above Job Order Tracker table |
| **Manager** | 4 cards | Above Pending/Validated tabs |
| **Admin** | 5 cards | Above Validated Transactions table |

**Rule**: Cards appear ONLY on dashboard view, NOT in modals or detail pages.

---

## 1️⃣ STAFF DASHBOARD (4 Cards)

```
┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│ 📝 TRANS-    │  │ ⏳ PENDING   │  │ 💳 UTANG     │  │ ✅ COMPLETED │
│   ACTIONS    │  │   PAYMENTS   │  │   ACCOUNTS   │  │   JOB ORDERS │
│     127      │  │ ₱12,450 (8)  │  │ ₱8,200 (5)   │  │      23      │
│ Merch + JO   │  │ Awaiting Pay │  │ Receivables  │  │ Services Done│
└──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘
    BLUE              AMBER             RED              GREEN
```

### Quick Summary:
- **Card 1**: Total transactions encoded today (merchandise + job orders)
- **Card 2**: Total unpaid/partial payment amount + count
- **Card 3**: Total credit receivables amount + count
- **Card 4**: Completed job orders today

---

## 2️⃣ MANAGER DASHBOARD (4 Cards)

```
┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│ ⏰ PENDING   │  │ ✓ VALIDATED  │  │ ⚠️ VARIANCE  │  │ 💰 PENDING   │
│   TRANS      │  │   TODAY      │  │   ALERTS     │  │   PAYMENTS   │
│      18      │  │      42      │  │       3      │  │ ₱45,670 (12) │
│ Await Valid  │  │ Approved     │  │ Anomalies    │  │ Valid/Unpaid │
└──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘
    AMBER             GREEN             RED              BLUE
    (Click)                           (Click)
```

### Quick Summary:
- **Card 1**: Transactions awaiting validation (clickable → filters Pending tab)
- **Card 2**: Transactions approved today
- **Card 3**: Flagged variance anomalies (clickable → variance details)
- **Card 4**: Validated but unpaid transactions

---

## 3️⃣ ADMIN DASHBOARD (5 Cards)

```
┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────┐
│ 📊 VALID │  │ 💵 PEND  │  │ 📋 OUT-  │  │ ⚠️ VAR   │  │ 📅 RECEIV    │
│   TRANS  │  │   PAY    │  │   UTANG  │  │   REPORTS│  │   AGING      │
│    286   │  │ ₱127,450 │  │ ₱89,230  │  │     7    │  │ Curr: ₱45K   │
│          │  │   (34)   │  │   (21)   │  │          │  │ Over: ₱12K   │
│ System   │  │ Unpaid   │  │ Credit   │  │ Anomalie │  │ Aging Report │
└──────────┘  └──────────┘  └──────────┘  └──────────┘  └──────────────┘
    BLUE          AMBER         RED          ORANGE         PURPLE
                              (Click)       (Click)        (Click)
```

### Quick Summary:
- **Card 1**: System-wide validated transactions today
- **Card 2**: Total unpaid balances across all stations
- **Card 3**: Outstanding credit receivables (clickable → receivables report)
- **Card 4**: System-wide variance reports (clickable → variance page)
- **Card 5**: Receivables aging breakdown (clickable → aging modal)

---

## 🎨 DESIGN SPECS (At a Glance)

### Card Structure
```
Icon (56px circle) + Content (label + value + subtext)
Width: 240px min | Height: 100px | Padding: 20px
Border radius: 12px | Shadow: 0 2px 8px
Hover: Lift 2px + deeper shadow
```

### Typography
```
Label:   11px | 700 weight | uppercase | #64748b
Value:   28px | 700 weight | #1e293b
Subtext: 12px | 500 weight | #94a3b8
```

### Colors
```css
Blue:   #002F70  (Primary)
Green:  #059669  (Success)
Amber:  #F59E0B  (Warning)
Red:    #DC2626  (Alert)
Orange: #EA580C  (Caution)
Purple: #6F42C1  (Info)
```

### Responsive Grid
```
Desktop:  4-5 columns (auto-fit)
Tablet:   2 columns
Mobile:   1 column (full width)
```

---

## 📋 IMPLEMENTATION CHECKLIST

### CSS (Once)
- [ ] Add `.summary-card-row` grid container
- [ ] Add `.summary-card` base styles
- [ ] Add `.card-icon` with color variants
- [ ] Add `.card-content` typography
- [ ] Add hover effects
- [ ] Add responsive breakpoints
- [ ] Hide in print mode

### Backend APIs (Per Role)
**Staff (4 APIs):**
- [ ] `backend/api/get_staff_transactions_count.php`
- [ ] `backend/api/get_staff_pending_payments.php`
- [ ] `backend/api/get_staff_utang.php`
- [ ] `backend/api/get_staff_completed_jo.php`

**Manager (4 APIs):**
- [ ] `backend/api/get_manager_pending_count.php`
- [ ] `backend/api/get_manager_validated_today.php`
- [ ] `backend/api/get_manager_variance_alerts.php`
- [ ] `backend/api/get_manager_pending_payments.php`

**Admin (5 APIs):**
- [ ] `backend/api/get_admin_validated_count.php`
- [ ] `backend/api/get_admin_pending_payments.php`
- [ ] `backend/api/get_admin_outstanding_utang.php`
- [ ] `backend/api/get_admin_variance_count.php`
- [ ] `backend/api/get_admin_receivables_aging.php`

### Frontend Integration
- [ ] Add cards to `public/staff_job_order_tracker.php`
- [ ] Add cards to `public/manager_transactions.php`
- [ ] Add cards to `public/admin_transactions_oversight.php`
- [ ] Add click handlers (where applicable)
- [ ] Test responsive layout
- [ ] Test data accuracy

---

## 🔢 SQL QUERY PATTERNS

### Count Pattern
```sql
SELECT COUNT(*) as count
FROM table_name
WHERE conditions
  AND DATE(created_at) = CURDATE()
```

### Amount + Count Pattern
```sql
SELECT 
  SUM(total_amount - COALESCE(amount_paid, 0)) as balance,
  COUNT(*) as count
FROM table_name
WHERE conditions
```

### Aging Pattern
```sql
SELECT 
  SUM(CASE WHEN DATEDIFF(CURDATE(), date_field) <= 30 
      THEN amount ELSE 0 END) as current,
  SUM(CASE WHEN DATEDIFF(CURDATE(), date_field) > 30 
      THEN amount ELSE 0 END) as overdue
FROM table_name
WHERE conditions
```

---

## 📱 RESPONSIVE BEHAVIOR

### Desktop (>1024px)
All cards in one row, spacious layout

### Tablet (768-1024px)
2 columns, maintains readability

### Mobile (<768px)
Single column, vertical stack, full width

---

## ✅ TESTING CHECKLIST

### Visual Testing
- [ ] All cards display correctly on desktop
- [ ] Cards stack properly on tablet (2 columns)
- [ ] Cards stack properly on mobile (1 column)
- [ ] Icons display and colors are correct
- [ ] Hover effects work smoothly
- [ ] Typography is readable

### Data Testing
- [ ] Card values match database queries
- [ ] Staff sees only own transactions
- [ ] Manager sees only station transactions
- [ ] Admin sees system-wide data
- [ ] Date filters work (today only)
- [ ] Payment status filters work

### Interaction Testing
- [ ] Manager Card 1 filters Pending tab
- [ ] Manager Card 3 shows variance details
- [ ] Admin Card 3 navigates to receivables
- [ ] Admin Card 4 navigates to variance reports
- [ ] Admin Card 5 shows aging modal
- [ ] Cards don't break page scroll

---

## 🚀 PRIORITY

**Phase 1** (MVP):
- CSS framework
- Staff Cards 1-2
- Manager Card 1
- Admin Cards 1-2

**Phase 2** (Enhancement):
- Staff Cards 3-4
- Manager Cards 2-4
- Admin Cards 3-5
- Click actions

**Phase 3** (Polish):
- Auto-refresh functionality
- Advanced animations
- Loading states

---

**Status**: Specification Complete ✅  
**Estimated Time**: 8-12 hours (all dashboards)  
**Priority**: Medium (UX enhancement)

---

**Quick Links:**
- Full Specification: `SUMMARY_CARDS_SPECIFICATION.md`
- Visual Guide: `SUMMARY_CARDS_VISUAL_GUIDE.md`
- Implementation Tasks: `tasks.md` (Task 14)
