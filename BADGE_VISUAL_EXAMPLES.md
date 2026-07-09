# Sidebar Badge Visual Examples

## Staff Sidebar with Badges

```
┌─────────────────────────────────────────────────┐
│  📊 Dashboard                                    │
├─────────────────────────────────────────────────┤
│  🛒 Transactions                            [8]  │
│    ├─ 🆕 New Transaction                   [5]  │
│    ├─ 📜 Transaction History                    │
│    └─ 🧾 Receipts                               │
├─────────────────────────────────────────────────┤
│  ⛽ Fuel Management                         [3]  │
│    ├─ 📝 Record Fuel Delivery                   │
│    ├─ 📋 Fuel Deliveries History          [3]  │
│    └─ 🚗 Fuel Transactions                      │
├─────────────────────────────────────────────────┤
│  🚚 Merchandise Deliveries                 [7]  │
│    ├─ 📝 Record Delivery Receipt                │
│    └─ 📋 Deliveries History                [7]  │
├─────────────────────────────────────────────────┤
│  📦 Inventory                               [12] │
│    ├─ 📦 Merchandise Inventory                  │
│    ├─ ⛽ Fuel Inventory                          │
│    ├─ 🔔 Stock Request                     [12] │
│    └─ 📜 Inventory History                      │
├─────────────────────────────────────────────────┤
│  👥 Customers                                    │
├─────────────────────────────────────────────────┤
│  📅 Calendar                                     │
├─────────────────────────────────────────────────┤
│  📊 Reports                                      │
│    ├─ 💰 Sales Reports                          │
│    ├─ 🚚 Deliveries Reports                     │
│    ├─ 💳 Payments Reports                       │
│    ├─ 👥 Customer Reports                       │
│    └─ 📋 Activity Reports                       │
└─────────────────────────────────────────────────┘
```

**Badge Breakdown for Staff:**
- **Transactions [8]**: 5 pending merchandise transactions + 3 pending from sub-items
- **Fuel Management [3]**: 3 pending fuel deliveries awaiting manager approval
- **Merchandise Deliveries [7]**: 7 pending delivery receipts awaiting validation
- **Inventory [12]**: 12 pending stock requests from system

---

## Manager Sidebar with Badges

```
┌─────────────────────────────────────────────────┐
│  📊 Dashboard                                    │
├─────────────────────────────────────────────────┤
│  🛒 Transactions                            [23] │
│    ├─ ✅ All Transactions                  [15] │
│    ├─ 🔧 Transaction Adjustments                │
│    ├─ ⛔ Voided Transactions               [3]  │
│    ├─ 📋 Request Data Management           [5]  │
│    └─ 🔧 Mechanics Management                   │
├─────────────────────────────────────────────────┤
│  ⛽ Fuel Management                         [18] │
│    ├─ ✅ Fuel Transaction Validation       [8]  │
│    ├─ ✅ Fuel Deliveries Validation        [7]  │
│    ├─ 🔧 Adjustments                            │
│    └─ 🎯 Calibration Review                [3]  │
├─────────────────────────────────────────────────┤
│  🚚 Merchandise Deliveries Validation      [11] │
├─────────────────────────────────────────────────┤
│  📦 Inventory                               [25] │
│    ├─ 📦 Merchandise Inventory                  │
│    ├─ ⛽ Fuel Inventory                          │
│    ├─ 🔔 Purchase Request                  [25] │
│    └─ 📋 Inventory Movement History             │
├─────────────────────────────────────────────────┤
│  👥 Customers                                    │
├─────────────────────────────────────────────────┤
│  🏷️ Product & Pricing Management                │
├─────────────────────────────────────────────────┤
│  📅 Calendar                                     │
├─────────────────────────────────────────────────┤
│  📊 Reports                                      │
│    ├─ 📈 Operations Reports                     │
│    ├─ 💰 Finance Reports                        │
│    └─ 📋 Compliance Reports                     │
└─────────────────────────────────────────────────┘
```

**Badge Breakdown for Manager:**
- **Transactions [23]**: 15 pending validation + 3 voided + 5 data requests
- **Fuel Management [18]**: 8 transactions + 7 deliveries + 3 variance reports
- **Merchandise Deliveries [11]**: 11 delivery receipts awaiting approval
- **Inventory [25]**: 25 stock requests from staff (persistent until approved/rejected)

---

## Admin Sidebar with Badges

```
┌─────────────────────────────────────────────────┐
│  📊 Dashboard                                    │
├─────────────────────────────────────────────────┤
│  👥 User Management                              │
├─────────────────────────────────────────────────┤
│  👨‍💼 Staff Oversight                              │
├─────────────────────────────────────────────────┤
│  🛒 Transactions                            [14] │
│    ├─ 📋 All Transactions                       │
│    ├─ 🔧 Transaction Adjustments                │
│    ├─ ⛔ Voided Transactions               [6]  │
│    └─ 📋 Request Data Management           [8]  │
├─────────────────────────────────────────────────┤
│  ⛽ Fuel Management                         [12] │
│    ├─ ✅ Fuel Transaction Oversight        [5]  │
│    ├─ 🚚 Fuel Deliveries Oversight         [4]  │
│    ├─ 🔧 Adjustments Oversight                  │
│    └─ 🎯 Calibration Oversight             [3]  │
├─────────────────────────────────────────────────┤
│  🚚 Merchandise Deliveries Oversight       [9]  │
├─────────────────────────────────────────────────┤
│  📦 Inventory                               [18] │
│    ├─ 📦 Merchandise Inventory                  │
│    ├─ ⛽ Fuel Inventory                          │
│    ├─ 📋 Purchase Orders Oversight         [18] │
│    └─ 📜 Inventory History                      │
├─────────────────────────────────────────────────┤
│  👥 Customers                                    │
├─────────────────────────────────────────────────┤
│  🏷️ Product & Pricing Management                │
├─────────────────────────────────────────────────┤
│  📅 Calendar                                     │
├─────────────────────────────────────────────────┤
│  📊 Reports                                      │
│    ├─ 📈 Operations Reports                     │
│    ├─ 💰 Finance Reports                        │
│    └─ 📋 Compliance Reports                     │
└─────────────────────────────────────────────────┘
```

**Badge Breakdown for Admin:**
- **Transactions [14]**: 6 voided + 8 data management requests
- **Fuel Management [12]**: 5 pending transactions + 4 pending deliveries + 3 calibration issues
- **Merchandise Deliveries [9]**: 9 delivery receipts requiring oversight
- **Inventory [18]**: 18 purchase orders awaiting validation/approval

---

## Badge Color & Style

### Visual Representation

```
┌──────────────────────────────────────────────────────┐
│  Regular Navigation Item (no badge)                  │
└──────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────┐
│  Navigation Item with Badge                    [15]  │
│                                                  ↑↑   │
│  Red pill: #E30613 (Petron Red)               ┌──┐  │
│  White text, bold                              │15│  │
│  Rounded corners                               └──┘  │
└──────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────┐
│  Parent Item with Sub-Items                    [23]  │
│    ├─ Sub-Item 1                              [10]  │
│    ├─ Sub-Item 2                               [8]  │
│    └─ Sub-Item 3                               [5]  │
│                                                       │
│  Parent badge = sum of sub-badges (10+8+5=23)        │
└──────────────────────────────────────────────────────┘
```

### CSS Styling

**Parent & Regular Items:**
```css
data-badge {
    background: #E30613;              /* Petron Red */
    color: white;
    padding: 0 6px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: bold;
    min-width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 6px;                /* For parent items */
    margin-left: 10px;                /* For regular items */
}
```

**Sub-Items:**
```css
data-badge {
    background: #E30613;              /* Petron Red */
    color: white;
    padding: 0 5px;
    border-radius: 10px;
    font-size: 10px;                  /* Smaller than parent */
    font-weight: bold;
    min-width: 18px;                  /* Smaller than parent */
    height: 18px;                     /* Smaller than parent */
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
```

---

## Badge Behavior Timeline

```
1. User logs in
   ↓
2. Badge appears: [15] ← Shows 15 pending items
   ↓
3. User hovers over navigation item
   ├─ Tooltip shows item name
   └─ Badge remains visible
   ↓
4. User clicks navigation item
   ├─ Badge IMMEDIATELY disappears (instant visual feedback)
   └─ Page starts loading
   ↓
5. Page loads (800ms delay)
   ├─ JavaScript calls badge_seen.php API
   └─ Timestamp stored in user_preferences table
   ↓
6. User returns to menu
   └─ Badge is gone
   ↓
7. New items arrive (staff submits new transaction)
   ├─ created_at > last_seen timestamp
   └─ Badge reappears: [1] ← Shows 1 new pending item
   ↓
8. Process repeats from step 3
```

---

## Real-World Examples

### Example 1: Staff Encodes Fuel Delivery
```
Before encoding:
┌─────────────────────────────────────────┐
│  ⛽ Fuel Management                      │
│    ├─ 📝 Record Fuel Delivery           │
│    ├─ 📋 Fuel Deliveries History        │
│    └─ 🚗 Fuel Transactions              │
└─────────────────────────────────────────┘

After encoding (awaiting manager approval):
┌─────────────────────────────────────────┐
│  ⛽ Fuel Management                 [1]  │  ← Badge appears
│    ├─ 📝 Record Fuel Delivery           │
│    ├─ 📋 Fuel Deliveries History   [1]  │  ← Sub-badge shows source
│    └─ 🚗 Fuel Transactions              │
└─────────────────────────────────────────┘

Manager sees:
┌─────────────────────────────────────────┐
│  ⛽ Fuel Management                 [1]  │  ← Badge on manager's sidebar
│    ├─ ✅ Fuel Transaction Validation    │
│    ├─ ✅ Fuel Deliveries Validation [1] │  ← Badge for validation queue
│    ├─ 🔧 Adjustments                    │
│    └─ 🎯 Calibration Review             │
└─────────────────────────────────────────┘
```

### Example 2: Manager Approves Stock Request
```
Before approval:
┌─────────────────────────────────────────┐
│  📦 Inventory                      [25] │
│    ├─ 📦 Merchandise Inventory          │
│    ├─ ⛽ Fuel Inventory                  │
│    ├─ 🔔 Purchase Request          [25] │
│    └─ 📋 Inventory Movement History     │
└─────────────────────────────────────────┘

Manager clicks "Purchase Request":
┌─────────────────────────────────────────┐
│  📦 Inventory                           │  ← Badge removed immediately
│    ├─ 📦 Merchandise Inventory          │
│    ├─ ⛽ Fuel Inventory                  │
│    ├─ 🔔 Purchase Request               │  ← No badge
│    └─ 📋 Inventory Movement History     │
└─────────────────────────────────────────┘

Manager approves 5 requests, leaves page:
┌─────────────────────────────────────────┐
│  📦 Inventory                      [20] │  ← Badge returns with updated count
│    ├─ 📦 Merchandise Inventory          │
│    ├─ ⛽ Fuel Inventory                  │
│    ├─ 🔔 Purchase Request          [20] │  ← 25 - 5 = 20 remaining
│    └─ 📋 Inventory Movement History     │
└─────────────────────────────────────────┘
```

### Example 3: Admin Reviews Multiple Modules
```
Admin logs in:
┌─────────────────────────────────────────┐
│  🛒 Transactions                   [14] │
│  ⛽ Fuel Management                 [12] │
│  🚚 Merchandise Deliveries          [9] │
│  📦 Inventory                      [18] │
└─────────────────────────────────────────┘
              Total: 53 pending items

Admin visits Transactions page → [14] disappears
Admin visits Fuel Management → [12] disappears
Admin visits Deliveries → [9] disappears
Admin visits Inventory → [18] disappears

Result:
┌─────────────────────────────────────────┐
│  🛒 Transactions                        │  ← All badges gone
│  ⛽ Fuel Management                     │
│  🚚 Merchandise Deliveries             │
│  📦 Inventory                          │
└─────────────────────────────────────────┘

Staff submits 3 new transactions → [3] reappears on Transactions
```

---

## Badge Persistence Rules

### Temporary Badges (disappear after visit)
- Staff: Transaction history, delivery history
- Manager: Voided transactions, variance reports
- Admin: Voided transactions oversight

### Persistent Badges (remain until action taken)
- Manager: Stock requests (until approved/rejected)
- Manager: Merchandise deliveries (until validated)
- Manager: Fuel validation queues (until processed)
- Admin: Purchase orders (until validated/approved)

---

## Accessibility Features

1. **Color Independence**: Badge count is visible as text, not just color
2. **Tooltip Support**: Navigation items have data-tooltip attributes
3. **Keyboard Navigation**: Badges don't interfere with keyboard nav
4. **Screen Reader Friendly**: Badge text is readable by screen readers
5. **High Contrast Mode**: Badges maintain visibility in dark/light themes

---

## Browser Compatibility

✅ Chrome/Edge (tested)
✅ Firefox (tested)
✅ Safari (CSS-compatible)
✅ Mobile browsers (responsive design)

---

## Performance Notes

- Badge counts calculated on every page load
- Uses prepared statements for security
- Single query per badge type (optimized)
- No AJAX polling (badges update on page refresh only)
- Minimal JavaScript (auto-remove only)
