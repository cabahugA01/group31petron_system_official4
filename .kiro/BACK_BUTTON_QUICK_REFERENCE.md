# Back Button Navigation - Quick Reference

**Last Updated**: June 3, 2026

---

## 📍 STAFF ROLE

```
┌─────────────────────┐
│  Staff Dashboard    │
└──────────┬──────────┘
           │
           ├─→ Fuel Transaction ───────────→ [Back (Gray)] ─→ Staff Dashboard
           │
           ├─→ Transactions (Merch/JO) ────→ [Back (Gray)] ─→ Staff Dashboard
           │
           ├─→ Shift History ──────────────→ [Back (Gray)] ─→ Staff Dashboard
           │
           └─→ Fuel Transaction History ───→ [Back (Gray)] ─→ Staff Dashboard
```

**File**: `public/staff_transactions_hub.php`

---

## 📍 MANAGER ROLE

```
┌─────────────────────┐
│ Manager Dashboard   │
└──────────┬──────────┘
           │
           └─→ Validated Transactions ─────→ [Back (Gray)] ─→ Manager Dashboard
                      │
                      │  [Excel (Green)] [CSV (Green)] [PDF (Red)] [Back (Gray)]
                      │
                      └─→ Pending Transactions ──→ [Back (Gray)] ─→ Validated Transactions
```

**Files**: 
- `public/manager_validated_transactions.php`
- `public/pending_transactions.php`

---

## 📍 ADMIN ROLE

```
┌─────────────────────┐
│  Admin Dashboard    │
└──────────┬──────────┘
           │
           ├─→ Oversight Dashboard ─→ [Excel (Green)] [CSV (Green)] [PDF (Red)] [Back (Gray)] ─→ Admin Dashboard
           │
           └─→ Variance Reports ────→ [Back (Gray)] ─→ Admin Dashboard (future)
```

**File**: `public/admin_transactions_oversight.php`

---

## 🎨 Button Appearance

```
┌──────────────────────┐
│  ←  Back             │  Gray (#6c757d) | 110×36px
└──────────────────────┘
```

**Consistent Across All Roles**:
- Position: Page header (right side)
- Color: Gray (#6c757d)
- Size: 110px × 36px
- Icon: ← (fa-arrow-left)
- Text: "Back"

---

## 🔑 Key Rules

1. **Staff**: All Back buttons → Staff Dashboard
2. **Manager**: 
   - Pending Transactions → Validated Transactions
   - Validated Transactions → Manager Dashboard
3. **Admin**: All Back buttons → Admin Dashboard

**NO MORE**:
- ❌ `window.history.back()` (browser back)
- ❌ Section-specific back links ("Back to Fuel", "Back to Transactions")
- ❌ Inconsistent button sizes or colors

**ALWAYS**:
- ✅ Gray color (#6c757d)
- ✅ 110×36px size
- ✅ Right side of page header
- ✅ Direct navigation to specified destination
