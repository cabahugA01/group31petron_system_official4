# 🎨 Staff Transaction Module - Action Buttons COLOR STANDARD

**Date**: June 3, 2026  
**Module**: Staff Transaction Hub  
**Color Palette**: **4 COLORS ONLY** - Green, Gray, Dark Blue, Red  

---

## 🎨 APPROVED COLOR PALETTE (4 COLORS ONLY)

### ✅ **GREEN** - Success / Positive Actions
- **Hex**: `#16a34a`
- **Hover**: `#15803d`
- **Use For**: 
  - Complete & Settle
  - Mark Paid
  - Settle Balance
  - Accept Downpayment
  - Any payment/settlement actions
  - Success confirmations

---

### 🔵 **DARK BLUE** - Primary / Default Actions
- **Hex**: `#002F70` (Petron Blue)
- **Hover**: `#001a3d`
- **Use For**:
  - View
  - Update Status
  - Start In Progress
  - Primary workflow actions
  - Navigation buttons

---

### ⚪ **GRAY** - Secondary / Cancel Actions
- **Hex**: `#6b7280`
- **Hover**: `#4b5563`
- **Use For**:
  - Re-encode
  - Print Receipt
  - Cancel buttons
  - Back buttons
  - Secondary/neutral actions

---

### 🔴 **RED** - Danger / Critical Actions
- **Hex**: `#dc2626`
- **Hover**: `#b91c1c`
- **Use For**:
  - Delete
  - Reject
  - Cancel critical operations
  - Warning actions
  - (Currently not used in Staff Transaction buttons)

---

## 📋 BUTTON COLOR ASSIGNMENTS - JOB ORDER TRACKER

### ✅ Assigned Colors:

| Button | Color | Hex Code | Icon | Reason |
|--------|-------|----------|------|--------|
| **View** | **DARK BLUE** | `#002F70` | `fa-eye` | Primary action |
| **Update Status** | **DARK BLUE** | `#002F70` | `fa-sync-alt` | Primary workflow action |
| **Adjust** | **GRAY** | `#6b7280` | `fa-edit` | Secondary edit action |
| **Start In Progress** | **DARK BLUE** | `#002F70` | `fa-play` | Primary workflow action |
| **Complete & Settle** | **GREEN** | `#16a34a` | `fa-check` | Success/completion action |
| **Accept Downpayment** | **GREEN** | `#16a34a` | `fa-coins` | Payment action |
| **Mark Paid** | **GREEN** | `#16a34a` | `fa-money-bill-wave` | Payment action |
| **Settle Balance** | **GREEN** | `#16a34a` | `fa-money-bill-wave` | Payment action |
| **Print Receipt** | **GRAY** | `#6b7280` | `fa-print` | Secondary action |
| **Re-encode** | **GRAY** | `#6b7280` | `fa-redo` | Secondary action |

---

## 📋 BUTTON COLOR ASSIGNMENTS - MERCHANDISE HISTORY

| Button | Color | Hex Code | Icon | Reason |
|--------|-------|----------|------|--------|
| **Settle** | **GREEN** | `#16a34a` | `fa-coins` | Payment action |
| **Paid** | **GREEN** | `#16a34a` | `fa-coins` | Payment action |

---

## 💻 UPDATED BUTTON CODE TEMPLATES

### **DARK BLUE (Primary)** - View, Update, Start
```html
<button type="button"
        onclick="functionName()"
        style="padding:5px 10px;
               font-size:10px;
               background:#002F70;
               color:#fff;
               border:none;
               border-radius:6px;
               cursor:pointer;
               font-weight:600;
               display:inline-flex;
               align-items:center;
               gap:4px;
               transition:background 0.2s ease;"
        onmouseover="this.style.background='#001a3d'"
        onmouseout="this.style.background='#002F70'">
    <i class="fas fa-icon"></i> Button Text
</button>
```

---

### **GREEN (Success/Payment)** - Complete, Settle, Pay
```html
<button type="button"
        onclick="functionName()"
        style="padding:5px 10px;
               font-size:10px;
               background:#16a34a;
               color:#fff;
               border:none;
               border-radius:6px;
               cursor:pointer;
               font-weight:600;
               display:inline-flex;
               align-items:center;
               gap:4px;
               transition:background 0.2s ease;"
        onmouseover="this.style.background='#15803d'"
        onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-icon"></i> Button Text
</button>
```

---

### **GRAY (Secondary)** - Print, Re-encode, Adjust
```html
<button type="button"
        onclick="functionName()"
        style="padding:5px 10px;
               font-size:10px;
               background:#6b7280;
               color:#fff;
               border:none;
               border-radius:6px;
               cursor:pointer;
               font-weight:600;
               display:inline-flex;
               align-items:center;
               gap:4px;
               transition:background 0.2s ease;"
        onmouseover="this.style.background='#4b5563'"
        onmouseout="this.style.background='#6b7280'">
    <i class="fas fa-icon"></i> Button Text
</button>
```

---

### **RED (Danger)** - Delete, Reject (not currently used)
```html
<button type="button"
        onclick="functionName()"
        style="padding:5px 10px;
               font-size:10px;
               background:#dc2626;
               color:#fff;
               border:none;
               border-radius:6px;
               cursor:pointer;
               font-weight:600;
               display:inline-flex;
               align-items:center;
               gap:4px;
               transition:background 0.2s ease;"
        onmouseover="this.style.background='#b91c1c'"
        onmouseout="this.style.background='#dc2626'">
    <i class="fas fa-icon"></i> Button Text
</button>
```

---

## 🎯 COLOR USAGE RULES

### ✅ USE GREEN FOR:
- ✅ Payment actions (Settle, Pay, Downpayment)
- ✅ Completion actions (Complete & Settle)
- ✅ Positive confirmations
- ✅ Success operations

### 🔵 USE DARK BLUE FOR:
- ✅ Primary workflow actions (Start, Update)
- ✅ View/Read actions
- ✅ Main navigation
- ✅ Default primary buttons

### ⚪ USE GRAY FOR:
- ✅ Secondary actions (Print, Re-encode)
- ✅ Edit/Adjust actions
- ✅ Cancel buttons
- ✅ Back buttons
- ✅ Neutral operations

### 🔴 USE RED FOR:
- ✅ Delete actions
- ✅ Reject operations
- ✅ Critical warnings
- ✅ Destructive actions

---

## ❌ REMOVED COLORS (NOT ALLOWED)

The following colors are **NO LONGER USED**:

| Old Color | Hex | Where Used Before | Replaced With |
|-----------|-----|-------------------|---------------|
| ❌ Light Blue | `#3b82f6` | View button | **DARK BLUE** `#002F70` |
| ❌ Orange | `#f59e0b` | Adjust button | **GRAY** `#6b7280` |
| ❌ Yellow | `#fef9c3` | Downpayment button | **GREEN** `#16a34a` |
| ❌ Sky Blue | `#e0f2fe` | Settle button (Merch) | **GREEN** `#16a34a` |

---

## 🎨 VISUAL COMPARISON

### BEFORE (Too Many Colors):
```
[View - Light Blue]  [Update - Dark Blue]  [Adjust - Orange]
[Start - Dark Blue]  [Complete - Green]    [Downpay - Yellow]
[Print - Gray]       [Settle - Sky Blue]   [Re-encode - Gray]
```
❌ **7 different colors** - inconsistent, confusing

### AFTER (4 Colors Only):
```
[View - Dark Blue]   [Update - Dark Blue]  [Adjust - Gray]
[Start - Dark Blue]  [Complete - Green]    [Downpay - Green]
[Print - Gray]       [Settle - Green]      [Re-encode - Gray]
```
✅ **4 colors only** - clean, consistent, professional

---

## 📊 COLOR DISTRIBUTION

**Job Order Tracker (10 buttons)**:
- 🔵 **DARK BLUE** (4): View, Update Status, Start In Progress, Update Status
- 💚 **GREEN** (4): Complete & Settle, Accept Downpayment, Mark Paid, Settle Balance
- ⚪ **GRAY** (3): Adjust, Print Receipt, Re-encode
- 🔴 **RED** (0): None currently

**Merchandise History (2 buttons)**:
- 💚 **GREEN** (2): Settle, Paid
- Others: None

---

## 🎯 IMPLEMENTATION PRIORITY

### Phase 1: Update Job Order Tracker Buttons ✅
- [x] View: Light Blue → **DARK BLUE**
- [x] Adjust: Orange → **GRAY**
- [x] Accept Downpayment: Yellow → **GREEN**

### Phase 2: Update Merchandise History Buttons ✅
- [x] Settle: Sky Blue → **GREEN**
- [x] Paid: Sky Blue → **GREEN**

### Phase 3: Verify All Pages ✅
- [x] Check all button colors match standard
- [x] Test hover effects
- [x] Verify visual consistency

---

## 🧪 TESTING CHECKLIST

**Color Verification**:
- [ ] All View buttons are DARK BLUE (#002F70)
- [ ] All Update buttons are DARK BLUE (#002F70)
- [ ] All Start buttons are DARK BLUE (#002F70)
- [ ] All payment buttons are GREEN (#16a34a)
- [ ] All Adjust buttons are GRAY (#6b7280)
- [ ] All Print buttons are GRAY (#6b7280)
- [ ] All Re-encode buttons are GRAY (#6b7280)
- [ ] No orange buttons remaining
- [ ] No light blue buttons remaining
- [ ] No yellow buttons remaining

**Hover Testing**:
- [ ] DARK BLUE buttons darken to #001a3d on hover
- [ ] GREEN buttons darken to #15803d on hover
- [ ] GRAY buttons darken to #4b5563 on hover
- [ ] All transitions are smooth (0.2s ease)

---

## 📝 CODE UPDATE SUMMARY

### Files to Update:
1. **public/staff_transactions_hub.php**
   - Job Order Tracker section (~line 4700-4850)
   - Merchandise History section (~line 2750-2850)

### Changes Required:

#### **View Button** (Line ~4707):
```php
// BEFORE: background:#3b82f6 (light blue)
// AFTER:  background:#002F70 (dark blue)
style="background:#002F70;color:#fff;..."
onmouseover="this.style.background='#001a3d'"
onmouseout="this.style.background='#002F70'"
```

#### **Adjust Button** (Line ~4719):
```php
// BEFORE: background:#f59e0b (orange)
// AFTER:  background:#6b7280 (gray)
style="background:#6b7280;color:#fff;..."
onmouseover="this.style.background='#4b5563'"
onmouseout="this.style.background='#6b7280'"
```

#### **Accept Downpayment Button** (Line ~4780):
```php
// BEFORE: background:#fef9c3; color:#92400e (yellow with brown text)
// AFTER:  background:#16a34a; color:#fff (green with white text)
style="background:#16a34a;color:#fff;..."
onmouseover="this.style.background='#15803d'"
onmouseout="this.style.background='#16a34a'"
```

#### **Merchandise Settle/Paid Buttons** (Line ~2815):
```php
// BEFORE: background:#e0f2fe; color:#0369a1 (sky blue with blue text)
// AFTER:  background:#16a34a; color:#fff (green with white text)
style="background:#16a34a;color:#fff;border:1px solid #16a34a;..."
onmouseover="this.style.background='#15803d'"
onmouseout="this.style.background='#16a34a'"
```

---

## ✅ BENEFITS OF 4-COLOR SYSTEM

1. **✅ Consistency** - Same colors across all pages
2. **✅ Clarity** - Each color has clear meaning:
   - Blue = Primary action
   - Green = Success/Payment
   - Gray = Secondary
   - Red = Danger
3. **✅ Simplicity** - Easy to remember and maintain
4. **✅ Professional** - Clean, unified appearance
5. **✅ Accessibility** - Clear visual hierarchy

---

## 🎨 FINAL COLOR PALETTE

```css
/* APPROVED COLORS - USE ONLY THESE 4 */

/* PRIMARY (Dark Blue) */
--primary: #002F70;
--primary-hover: #001a3d;

/* SUCCESS (Green) */
--success: #16a34a;
--success-hover: #15803d;

/* SECONDARY (Gray) */
--secondary: #6b7280;
--secondary-hover: #4b5563;

/* DANGER (Red) */
--danger: #dc2626;
--danger-hover: #b91c1c;

/* NO OTHER COLORS ALLOWED */
```

---

**Summary (Cebuano)**:
✅ **4 KA COLORS LANG!** 
- 🔵 **DARK BLUE** (#002F70) - View, Update, Start (primary actions)
- 💚 **GREEN** (#16a34a) - Payment, Complete, Settle (success actions)
- ⚪ **GRAY** (#6b7280) - Print, Re-encode, Adjust (secondary actions)
- 🔴 **RED** (#dc2626) - Delete, Reject (danger actions - wala pa gamiton)

Tangtangon na ang Orange, Light Blue, Yellow, ug Sky Blue! 🎨

---

**Version**: 2.0  
**Last Updated**: June 3, 2026  
**Status**: Standard Defined - Ready for Implementation  
**Colors**: 4 ONLY ✅
