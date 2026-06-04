# Staff Transaction Module - Visual Action Button Guide

**Quick Reference** for Staff Members

---

## 🎨 Button Colors & Meanings

```
🔵 DARK BLUE  = View/Check Information
💚 GREEN      = Money/Payment Actions  
⚪ GRAY       = Print/Adjust/Secondary Actions
🔴 RED        = Export to PDF only
```

---

## 📋 Job Order Tracker - What Buttons You'll See

### 1️⃣ When Order is PENDING VALIDATION
```
┌─────────────────────────────────┐
│ 🔵 View                         │
└─────────────────────────────────┘
│ ⏰ Awaiting manager approval    │
└─────────────────────────────────┘
```

### 2️⃣ When Order is APPROVED (Not Started Yet)
```
┌──────────┬──────────┬──────────┐
│ 🔵 View  │ 🔵 Update│ ⚪ Adjust│
└──────────┴──────────┴──────────┘
┌──────────────────────────────────┐
│ 🔵 Start In Progress             │
└──────────────────────────────────┘
┌──────────────────────────────────┐
│ 💚 Complete & Settle             │
└──────────────────────────────────┘
```

### 3️⃣ When Order is IN PROGRESS
```
┌──────────┬──────────┐
│ 🔵 View  │ 🔵 Update│
└──────────┴──────────┘
┌──────────────────────────────────┐
│ 💚 Complete & Settle             │
└──────────────────────────────────┘
```

### 4️⃣ When Order is COMPLETED but NOT PAID
```
┌─────────────────────────────────┐
│ 🔵 View                         │
└─────────────────────────────────┘
┌─────────────────────────────────┐
│ 💚 Mark Paid / Settle Balance   │
└─────────────────────────────────┘
```

### 5️⃣ When Order is COMPLETED and PAID
```
┌─────────────────────────────────┐
│ 🔵 View                         │
└─────────────────────────────────┘
┌─────────────────────────────────┐
│ ⚪ Print Receipt                │
└─────────────────────────────────┘
```

### 6️⃣ When Order is REJECTED
```
┌─────────────────────────────────┐
│ 🔵 View                         │
└─────────────────────────────────┘
┌─────────────────────────────────┐
│ ⚪ Re-encode                    │
└─────────────────────────────────┘
```

---

## 🛒 Merchandise History - What Buttons You'll See

### 1️⃣ Transaction with UNPAID or PARTIAL PAYMENT
```
┌─────────────────────────────────┐
│ 🔵 View                         │ ← Always visible
└─────────────────────────────────┘
┌─────────────────────────────────┐
│ 💚 Paid / Settle                │ ← Click to pay
└─────────────────────────────────┘
```

### 2️⃣ Transaction FULLY PAID
```
┌─────────────────────────────────┐
│ 🔵 View                         │ ← Check details
└─────────────────────────────────┘
┌─────────────────────────────────┐
│ ⚪ Print Receipt                │ ← Print copy
└─────────────────────────────────┘
```

---

## 🎯 What Each Button Does

### 🔵 DARK BLUE Buttons

| Button | What It Does |
|--------|-------------|
| **View** | Shows complete details (customer, items, prices, mechanic, etc.) |
| **Update Status** | Change workflow stage (Pending → Approved → In Progress → Completed) |
| **Start In Progress** | Mark job as started (mechanic begins work) |

### 💚 GREEN Buttons

| Button | What It Does |
|--------|-------------|
| **Complete & Settle** | Mark job as done AND record payment |
| **Mark Paid** | Record full payment for completed job |
| **Settle Balance** | Pay remaining amount (when downpayment was made) |
| **Paid** | Mark merchandise as fully paid |
| **Settle** | Pay remaining balance for merchandise |

### ⚪ GRAY Buttons

| Button | What It Does |
|--------|-------------|
| **Adjust** | Change service details (mechanic, parts, remarks) - only before In Progress |
| **Print Receipt** | Open printable receipt in new window |
| **Re-encode** | Create new job order (for rejected orders) |

---

## 💡 Common Tasks - Step by Step

### ✅ Processing a New Job Order
1. Order appears with **"Awaiting manager approval"**
2. Wait for manager to approve
3. Once approved, click **🔵 Start In Progress**
4. Mechanic does the work
5. When done, click **💚 Complete & Settle**
6. Enter payment details
7. Click **⚪ Print Receipt** to give customer a copy

### ✅ Settling an Unpaid Job Order
1. Find the completed job with unpaid status
2. Click **💚 Mark Paid** or **💚 Settle Balance**
3. Enter payment information
4. Click **⚪ Print Receipt**

### ✅ Printing Merchandise Receipt
1. Find the paid transaction in Merchandise History
2. Click **🔵 View** to check details (optional)
3. Click **⚪ Print Receipt**
4. Print dialog will open

### ✅ Checking Transaction Details
1. Click **🔵 View** button (available on any transaction)
2. See full details (customer, items, prices, payment)
3. Close window when done

---

## 📊 Payment Status Colors

```
🟢 PAID              = Fully settled, can print receipt
🟡 DOWNPAYMENT       = Partial payment made, balance due
🟡 PENDING PAYMENT   = Not paid yet, waiting
🔵 RECEIVABLES/CREDIT = Customer has credit account
🔴 UNPAID            = No payment made
```

---

## 🚫 Common Questions

**Q: Why can't I adjust a job order anymore?**  
A: Once a job is **In Progress** or **Completed**, you can't adjust it. Adjust must be done while still **Approved**.

**Q: Where is the Print Receipt button?**  
A: Print Receipt only appears when the transaction is **fully paid** (payment_status = Paid).

**Q: Can I view a transaction that's not paid yet?**  
A: Yes! The **🔵 View** button is always available for all transactions.

**Q: What's the difference between "Mark Paid" and "Settle Balance"?**  
A: **Mark Paid** = full payment. **Settle Balance** = paying the remaining amount after downpayment.

---

## 🎓 Training Tips

1. **Look at the color**: Blue = look, Green = money, Gray = other actions
2. **Read the label**: Button text tells you exactly what it does
3. **Check the status**: Different statuses show different buttons
4. **Always View first**: Use View button to check details before taking action
5. **Print after payment**: Receipt button appears only after full payment

---

**Created**: June 3, 2026  
**For**: Staff Transaction Module Users  
**Language**: Cebuano/English Mixed (Common terms in English)
