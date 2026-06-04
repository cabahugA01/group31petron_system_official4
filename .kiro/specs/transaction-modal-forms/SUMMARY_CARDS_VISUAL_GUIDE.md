# 📊 Transaction Module - Summary Cards Visual Guide

**Visual Reference**: Card layouts and placement for each dashboard

---

## 1️⃣ STAFF TRANSACTION DASHBOARD

### Page View
```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     STAFF TRANSACTION DASHBOARD                              │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  SUMMARY CARDS (Top Row)                                                     │
└─────────────────────────────────────────────────────────────────────────────┘

  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐
  │ 📝  TRANS-     │  │ ⏳  PENDING    │  │ 💳  UTANG      │  │ ✅  COMPLETED  │
  │    ACTIONS     │  │    PAYMENTS    │  │    ACCOUNTS    │  │    JOB ORDERS  │
  │                │  │                │  │                │  │                │
  │      127       │  │ ₱12,450.00 (8) │  │ ₱8,200.00 (5)  │  │       23       │
  │                │  │                │  │                │  │                │
  │ Merch + JO     │  │ Awaiting Pay   │  │ Receivables    │  │ Services Done  │
  └────────────────┘  └────────────────┘  └────────────────┘  └────────────────┘
     Blue                  Amber               Red                 Green

┌─────────────────────────────────────────────────────────────────────────────┐
│  JOB ORDER TRACKER TABLE                                                     │
│  ─────────────────────────────────────────────────────────────────────────  │
│  | Transaction ID | Customer | Service | Vehicle | Status | Actions |       │
│  | JO-001         | Juan     | Change  | ABC123  | Pend   | View    |       │
│  | JO-002         | Maria    | Repair  | XYZ789  | Done   | View    |       │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Card Details

**Card 1: Transactions Encoded** (Blue)
- Icon: 📝 (fas fa-file-invoice)
- Value: 127 (total count)
- Label: "TRANSACTIONS ENCODED"
- Subtext: "Merchandise + Job Orders"
- Purpose: Shows total workload for the day

**Card 2: Pending Payments** (Amber/Yellow)
- Icon: ⏳ (fas fa-clock)
- Value: ₱12,450.00 (8) (amount + count)
- Label: "PENDING PAYMENTS"
- Subtext: "Awaiting Payment"
- Purpose: Highlights unpaid transactions

**Card 3: Utang Accounts** (Red)
- Icon: 💳 (fas fa-credit-card)
- Value: ₱8,200.00 (5) (receivables + count)
- Label: "UTANG ACCOUNTS"
- Subtext: "Credit/Receivables"
- Purpose: Tracks credit transactions

**Card 4: Completed Job Orders** (Green)
- Icon: ✅ (fas fa-check-circle)
- Value: 23 (count)
- Label: "COMPLETED JOB ORDERS"
- Subtext: "Services Finished"
- Purpose: Shows completed work

---

## 2️⃣ MANAGER TRANSACTION DASHBOARD

### Page View
```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    MANAGER TRANSACTION DASHBOARD                             │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  SUMMARY CARDS (Top Row)                                                     │
└─────────────────────────────────────────────────────────────────────────────┘

  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐
  │ ⏰  PENDING    │  │ ✓  VALIDATED   │  │ ⚠️  VARIANCE   │  │ 💰  PENDING    │
  │    TRANS-      │  │    TODAY       │  │    ALERTS      │  │    PAYMENTS    │
  │    ACTIONS     │  │                │  │                │  │                │
  │       18       │  │       42       │  │        3       │  │ ₱45,670.00 (12)│
  │                │  │                │  │                │  │                │
  │ Await Valid    │  │ Approved       │  │ Anomalies      │  │ Valid/Unpaid   │
  └────────────────┘  └────────────────┘  └────────────────┘  └────────────────┘
     Amber               Green               Red                 Blue

┌─────────────────────────────────────────────────────────────────────────────┐
│  TRANSACTION TABS: [Pending] [Validated]                                    │
│  ─────────────────────────────────────────────────────────────────────────  │
│  | Txn ID | Customer | Service | Staff | Amount | Status | Actions |        │
│  | MT-001 | Juan     | Oil     | Pedro | ₱500   | Pend   | Approve |        │
│  | JO-002 | Maria    | Repair  | Ana   | ₱1200  | Pend   | Approve |        │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Card Details

**Card 1: Pending Transactions** (Amber/Yellow)
- Icon: ⏰ (fas fa-hourglass-half)
- Value: 18 (count awaiting validation)
- Label: "PENDING TRANSACTIONS"
- Subtext: "Awaiting Validation"
- Purpose: Validation queue size
- Action: Click to filter Pending tab

**Card 2: Validated Today** (Green)
- Icon: ✓ (fas fa-check-double)
- Value: 42 (approved count)
- Label: "VALIDATED TODAY"
- Subtext: "Approved Transactions"
- Purpose: Daily validation productivity

**Card 3: Variance Alerts** (Red)
- Icon: ⚠️ (fas fa-exclamation-triangle)
- Value: 3 (flagged anomalies)
- Label: "VARIANCE ALERTS"
- Subtext: "Flagged Anomalies"
- Purpose: Encoding accuracy oversight
- Action: Click to view variance details

**Card 4: Pending Payments** (Blue)
- Icon: 💰 (fas fa-money-bill-wave)
- Value: ₱45,670.00 (12) (amount + count)
- Label: "PENDING PAYMENTS"
- Subtext: "Validated But Unpaid"
- Purpose: Tracks receivables after validation

---

## 3️⃣ ADMIN TRANSACTION DASHBOARD

### Page View
```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     ADMIN OVERSIGHT DASHBOARD                                │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  SUMMARY CARDS (Top Row)                                                     │
└─────────────────────────────────────────────────────────────────────────────┘

┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────────┐
│ 📊  VALID- │ │ 💵  PENDING│ │ 📋  OUT-   │ │ ⚠️  VAR-   │ │ 📅  RECEIV-    │
│    ATED    │ │    PAY-    │ │    STANDING│ │    IANCE   │ │    ABLES       │
│    TRANS   │ │    MENTS   │ │    UTANG   │ │    REPORTS │ │    AGING       │
│            │ │            │ │            │ │            │ │                │
│     286    │ │ ₱127,450   │ │ ₱89,230    │ │      7     │ │ Curr: ₱45K     │
│            │ │    (34)    │ │    (21)    │ │            │ │ Over: ₱12K     │
│            │ │            │ │            │ │            │ │                │
│ System-Wide│ │ Unpaid Bal │ │ Credit Rec │ │ Anomalies  │ │ Aging Report   │
└────────────┘ └────────────┘ └────────────┘ └────────────┘ └────────────────┘
    Blue           Amber           Red           Orange          Purple

┌─────────────────────────────────────────────────────────────────────────────┐
│  VALIDATED TRANSACTIONS TABLE                                                │
│  ─────────────────────────────────────────────────────────────────────────  │
│  | Txn ID | Customer | Type | Service | Staff | Total | Status | Actions |  │
│  | MT-001 | Juan     | Merch| Oil     | Pedro | ₱500  | Appr   | View    |  │
│  | JO-002 | Maria    | JO   | Repair  | Ana   | ₱1200 | Compl  | View    |  │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Card Details

**Card 1: Total Validated Transactions** (Blue)
- Icon: 📊 (fas fa-chart-line)
- Value: 286 (system-wide count)
- Label: "VALIDATED TRANSACTIONS"
- Subtext: "System-Wide (Today)"
- Purpose: Overall system activity

**Card 2: Pending Payments** (Amber/Yellow)
- Icon: 💵 (fas fa-file-invoice-dollar)
- Value: ₱127,450.00 (34) (total + count)
- Label: "PENDING PAYMENTS"
- Subtext: "Unpaid Balances"
- Purpose: System-wide receivables

**Card 3: Outstanding Utang** (Red)
- Icon: 📋 (fas fa-clipboard-list)
- Value: ₱89,230.00 (21) (receivables + count)
- Label: "OUTSTANDING UTANG"
- Subtext: "Credit Receivables"
- Purpose: Credit tracking
- Action: Click to view receivables report

**Card 4: Variance Reports** (Orange)
- Icon: ⚠️ (fas fa-flag)
- Value: 7 (flagged count)
- Label: "VARIANCE REPORTS"
- Subtext: "System-Wide Anomalies"
- Purpose: Compliance monitoring
- Action: Click to navigate to variance reports

**Card 5: Receivables Aging** (Purple)
- Icon: 📅 (fas fa-calendar-alt)
- Value: Current: ₱45K | Overdue: ₱12K
- Label: "RECEIVABLES AGING"
- Subtext: "Aging Breakdown"
- Purpose: Aging analysis
- Action: Click to view detailed aging report

---

## 🎨 VISUAL DESIGN SPECIFICATIONS

### Card Dimensions
```
Desktop (>1024px):
┌─────────────────────┐
│  Icon  │  Content   │  Width: 280px min
│  56px  │  Flex      │  Height: 100px
│        │            │  Padding: 20px
└─────────────────────┘

Tablet (768px-1024px):
┌──────────────────┐
│  Icon │ Content  │  Width: 240px min
│  48px │ Flex     │  Height: 90px
│       │          │  Padding: 16px
└──────────────────┘

Mobile (<768px):
┌─────────────────────────┐
│  Icon │   Content       │  Width: 100%
│  48px │   Flex          │  Height: 80px
│       │                 │  Padding: 16px
└─────────────────────────┘
```

### Typography Scale
```
Card Label:    11px, 700 weight, uppercase, #64748b
Card Value:    28px, 700 weight, #1e293b
Card Subtext:  12px, 500 weight, #94a3b8
```

### Icon Sizes
```
Desktop:  24px font-size in 56px × 56px circle
Tablet:   22px font-size in 52px × 52px circle
Mobile:   20px font-size in 48px × 48px circle
```

### Color Palette
```css
Blue:    #002F70  (Primary, Professional)
Green:   #059669  (Success, Completed)
Amber:   #F59E0B  (Warning, Pending)
Red:     #DC2626  (Alert, Urgent)
Orange:  #EA580C  (Caution, Review)
Purple:  #6F42C1  (Info, Analysis)
```

### Spacing
```
Gap between cards:        16px
Margin below card row:    24px
Card border radius:       12px
Icon border radius:       12px
```

### Shadow & Borders
```css
Default:  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
          border: 1px solid #e9ecef;

Hover:    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
          transform: translateY(-2px);
```

---

## 📱 RESPONSIVE GRID BEHAVIOR

### Desktop (>1024px)
```
Staff/Manager:   [Card 1] [Card 2] [Card 3] [Card 4]
Admin:           [Card 1] [Card 2] [Card 3] [Card 4] [Card 5]
```

### Tablet (768px-1024px)
```
Staff/Manager:   [Card 1] [Card 2]
                 [Card 3] [Card 4]

Admin:           [Card 1] [Card 2] [Card 3]
                 [Card 4] [Card 5]
```

### Mobile (<768px)
```
All Dashboards:  [Card 1]
                 [Card 2]
                 [Card 3]
                 [Card 4]
                 [Card 5] (admin only)
```

---

## 🔄 INTERACTIVE STATES

### Hover Effect
```
┌────────────────┐          ┌────────────────┐
│ 📝  CARD       │   -->    │ 📝  CARD       │ ↑ -2px
│                │          │                │
│      127       │          │      127       │
│                │          │                │
│ Description    │          │ Description    │
└────────────────┘          └────────────────┘
   Normal                      Hover (lifted)
```

### Click Action
```
Staff Cards:      No action (display only)
Manager Cards:    Card 1 → Filter Pending tab
                  Card 3 → Show variance details
Admin Cards:      Card 3 → Navigate to receivables report
                  Card 4 → Navigate to variance reports
                  Card 5 → Show aging report modal
```

### Loading State
```
┌────────────────┐
│ 📝  LOADING... │
│                │
│      ---       │ ← Skeleton loader
│                │
│ Please wait... │
└────────────────┘
```

---

## ✅ IMPLEMENTATION PRIORITY

**High Priority** (Must have for MVP):
- ✅ Staff: Card 1 (Transactions Encoded)
- ✅ Staff: Card 2 (Pending Payments)
- ✅ Manager: Card 1 (Pending Transactions)
- ✅ Admin: Card 1 (Total Validated)
- ✅ Admin: Card 2 (Pending Payments)

**Medium Priority** (Important for UX):
- ⭐ Staff: Card 3 (Utang Accounts)
- ⭐ Manager: Card 2 (Validated Today)
- ⭐ Manager: Card 4 (Pending Payments)
- ⭐ Admin: Card 3 (Outstanding Utang)

**Low Priority** (Nice to have):
- 🔵 Staff: Card 4 (Completed Job Orders)
- 🔵 Manager: Card 3 (Variance Alerts)
- 🔵 Admin: Card 4 (Variance Reports)
- 🔵 Admin: Card 5 (Receivables Aging)

---

## 📊 DATA UPDATE FREQUENCY

**Real-time** (every page load):
- All card values refresh on dashboard load
- Queries run against live database

**Auto-refresh** (optional enhancement):
- Every 30 seconds via AJAX
- Only if user is actively viewing dashboard
- Smooth fade-in animation on value change

**Manual refresh**:
- User can click refresh icon
- Page reload triggers full data refresh

---

**Status**: Visual Guide Complete ✅  
**Next**: Backend API + Frontend Implementation  
**Files to Create**: 
- `backend/api/get_staff_summary_cards.php`
- `backend/api/get_manager_summary_cards.php`
- `backend/api/get_admin_summary_cards.php`
- Add HTML/CSS to respective dashboard pages
