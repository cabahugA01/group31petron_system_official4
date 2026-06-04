# 📌 Staff Transaction Module – Action Buttons Specification

**Date**: June 3, 2026  
**Module**: Staff Transaction Hub  
**Location**: `public/staff_transactions_hub.php`  
**Section**: Merchandise Tab (2 Sub-tabs)  

---

## 🎯 OVERVIEW

The Staff Transaction Module has **2 sub-tabs** under the **Merchandise** section:
1. **Job Order Tracker** - Track job order workflow from Pending → Completed
2. **Merchandise History** - View past merchandise transactions and settle payments

Each sub-tab has specific action buttons based on transaction state and workflow stage.

---

## 📋 SUB-TAB 1: JOB ORDER TRACKER

**Purpose**: Track and manage job order workflow progress  
**Access**: Staff role (Staff, Cashier, Pump Attendant)  
**Table Location**: `staff_transactions_hub.php?section=merchandise` → Job Order Tracker tab  

### ✅ ACTION BUTTONS (Job Order Tracker)

#### **1. VIEW Button** 👁️
- **Icon**: `<i class="fas fa-eye"></i>`
- **Label**: "View"
- **Color**: Blue (#3b82f6)
- **Function**: Opens job order details modal
- **Visibility**: **Always visible** (all statuses)
- **What It Shows**:
  - Customer name
  - Vehicle plate & type
  - Service type & description
  - Mechanic assigned
  - Required parts list
  - Estimated cost vs actual cost
  - Payment details
  - Workflow status
  - Validation status
  - Created date & staff

**Implementation**:
```html
<button type="button"
        onclick="openViewJobOrderModal(<?= $job_data ?>)"
        class="txn-btn" 
        style="padding:5px 10px; font-size:10px; background:#3b82f6; color:#fff;">
    <i class="fas fa-eye"></i> View
</button>
```

---

#### **2. UPDATE STATUS Button** 🔄
- **Icon**: `<i class="fas fa-sync-alt"></i>`
- **Label**: "Update"
- **Color**: Primary Blue (#002F70)
- **Function**: Opens status change modal to update workflow stage
- **Visibility**: **NOT visible when** status is Completed, Rejected, or Cancelled
- **Available Status Transitions**:
  - **Pending Validation** → (Manager must approve first)
  - **Approved** → In Progress
  - **In Progress** → Completed
  - **Completed** → (Final state, no further updates)
  - **Rejected** → (Must re-encode)

**Modal Options**:
- Select new status from dropdown
- Add remarks/notes
- Confirm status change

**Implementation**:
```html
<?php if (!in_array($workflow_status, ['Completed', 'Rejected', 'Cancelled'])): ?>
<button type="button"
        onclick="openUpdateStatusModal(<?= $job_id ?>,'<?= $workflow_status ?>','<?= $source ?>')"
        class="txn-btn primary" 
        style="padding:5px 10px; font-size:10px;">
    <i class="fas fa-sync-alt"></i> Update
</button>
<?php endif; ?>
```

---

#### **3. ADJUST Button** ✏️
- **Icon**: `<i class="fas fa-edit"></i>`
- **Label**: "Adjust"
- **Color**: Orange (#f59e0b)
- **Function**: Opens adjustment modal to correct job order details
- **Visibility**: Only visible **BEFORE** job order is marked "In Progress"
- **Conditions**: 
  - Validation Status: Pending Validation OR Approved
  - Workflow Status: NOT In Progress, NOT Completed, NOT Rejected
- **What Can Be Adjusted**:
  - Mechanic assignment (change assigned mechanic)
  - Service description/notes
  - Required parts list
  - Estimated cost
  - Remarks

**Implementation**:
```html
<?php if (in_array($validation_status, ['Pending Validation', 'Approved']) 
       && !in_array($workflow_status, ['In Progress', 'Completed', 'Rejected'])): ?>
<button type="button"
        onclick="openAdjustJobOrderModal(<?= $job_data ?>)"
        class="txn-btn" 
        style="padding:5px 10px; font-size:10px; background:#f59e0b; color:#fff;">
    <i class="fas fa-edit"></i> Adjust
</button>
<?php endif; ?>
```

---

#### **4. START IN PROGRESS Button** ▶️
- **Icon**: `<i class="fas fa-play"></i>`
- **Label**: "Start In Progress"
- **Color**: Primary Blue (#002F70)
- **Function**: Marks job order as actively being worked on
- **Visibility**: Only when status is **Approved** (after Manager validation)
- **Action**: 
  - Changes workflow_status from "Approved" → "In Progress"
  - Records timestamp
  - Shows "In Progress" badge in table

**Implementation**:
```html
<?php if ($workflow_status !== 'In Progress' && $validation_status === 'Approved'): ?>
<form method="POST" action="staff_transactions_hub.php?section=merchandise&active_tab=tracker">
    <input type="hidden" name="jo_action" value="set_in_progress">
    <input type="hidden" name="jo_id" value="<?= $job_id ?>">
    <input type="hidden" name="jo_source" value="<?= $source ?>">
    <button type="submit" class="txn-btn primary" style="padding:5px 11px; font-size:10px;">
        <i class="fas fa-play"></i> Start In Progress
    </button>
</form>
<?php endif; ?>
```

---

#### **5. COMPLETE & SETTLE Button** ✅💰
- **Icon**: `<i class="fas fa-check"></i>`
- **Label**: "Complete & Settle"
- **Color**: Success Green (#16a34a)
- **Function**: Completes the job order AND opens payment modal
- **Visibility**: When job is **In Progress** or **Approved**
- **Action**:
  1. Opens payment modal with job order total
  2. Staff encodes payment amount
  3. Selects payment method (Cash/Card/E-Wallet/Credit)
  4. On submit:
     - Marks job order as **Completed**
     - Records payment details
     - Updates balance due
     - Sets payment_status (Paid/Partial/Pending)

**Payment Modal Fields**:
- Customer name (read-only)
- Total amount (read-only)
- Amount paid (input)
- Payment method (dropdown)
- Balance due (auto-calculated)
- Remarks (optional)
- Checkbox: "Mark as Completed"

**Implementation**:
```html
<button type="button"
        onclick="openPaymentModal(<?= $job_id ?>,'<?= $source ?>',<?= $total ?>,<?= $paid ?>,<?= $balance ?>,'<?= $customer ?>',true,'tracker')"
        class="txn-btn success" 
        style="padding:5px 11px; font-size:10px;">
    <i class="fas fa-check"></i> Complete & Settle
</button>
```

**Parameters**:
- `$job_id` - Job order ID
- `$source` - 'job_orders' or 'merchandise_transactions'
- `$total` - Total amount
- `$paid` - Amount already paid
- `$balance` - Remaining balance
- `$customer` - Customer name
- `true` - Auto-mark as Completed when payment submitted
- `'tracker'` - Redirect back to tracker tab

---

#### **6. ACCEPT DOWNPAYMENT Button** 💵
- **Icon**: `<i class="fas fa-coins"></i>`
- **Label**: "Accept Downpayment"
- **Color**: Yellow (#fef9c3) with brown text (#92400e)
- **Function**: Allows partial payment (downpayment) without completing job
- **Visibility**: Only when:
  - Payment status is: Partial Payment, Partial, Unpaid, Pending Payment, or Pending
  - Workflow status is: **In Progress**
- **Action**:
  - Opens same payment modal as "Complete & Settle"
  - BUT checkbox "Mark as Completed" is **unchecked**
  - Staff can accept partial payment
  - Job remains "In Progress"
  - Can continue work and settle balance later

**Use Case**: Customer pays downpayment upfront, full settlement after service completed.

**Implementation**:
```html
<?php if (in_array($payment_status, ['Partial Payment','Partial','Unpaid','Pending Payment','Pending']) 
       && $workflow_status === 'In Progress'): ?>
<button type="button"
        onclick="openPaymentModal(<?= $job_id ?>,'<?= $source ?>',<?= $total ?>,<?= $paid ?>,<?= $balance ?>,'<?= $customer ?>',false,'tracker')"
        class="txn-btn" 
        style="padding:5px 11px; font-size:10px; background:#fef9c3; color:#92400e; border:1px solid #fde68a;">
    <i class="fas fa-coins"></i> Accept Downpayment
</button>
<?php endif; ?>
```

---

#### **7. SETTLE BALANCE Button** 💰
- **Icon**: `<i class="fas fa-money-bill-wave"></i>`
- **Label**: "Settle Balance"
- **Color**: Success Green (#16a34a)
- **Function**: Complete remaining payment after job is completed
- **Visibility**: Only when:
  - Workflow status is: **Completed**
  - Payment status is: **Partial Payment** (has outstanding balance)
- **Action**:
  - Opens payment modal
  - Shows remaining balance
  - Staff collects final payment
  - Updates to "Paid" status when fully settled

**Implementation**:
```html
<?php if ($workflow_status === 'Completed' && $payment_status === 'Partial Payment'): ?>
<button type="button"
        onclick="openPaymentModal(<?= $job_id ?>,'<?= $source ?>',<?= $total ?>,<?= $paid ?>,<?= $balance ?>,'<?= $customer ?>',false,'tracker')"
        class="txn-btn success" 
        style="padding:5px 11px; font-size:10px;">
    <i class="fas fa-money-bill-wave"></i> Settle Balance
</button>
<?php endif; ?>
```

---

#### **8. MARK PAID Button** 💵
- **Icon**: `<i class="fas fa-money-bill-wave"></i>`
- **Label**: "Mark Paid"
- **Color**: Success Green (#16a34a)
- **Function**: Record payment for completed job
- **Visibility**: Only when:
  - Workflow status is: **Completed**
  - Payment status is: **Pending Payment** or **Unpaid**
- **Action**: Opens payment modal to record first/full payment

**Implementation**:
```html
<?php if ($workflow_status === 'Completed' && in_array($payment_status, ['Pending Payment','Unpaid'])): ?>
<button type="button"
        onclick="openPaymentModal(<?= $job_id ?>,'<?= $source ?>',<?= $total ?>,<?= $paid ?>,<?= $balance ?>,'<?= $customer ?>',false,'tracker')"
        class="txn-btn success" 
        style="padding:5px 11px; font-size:10px;">
    <i class="fas fa-money-bill-wave"></i> Mark Paid
</button>
<?php endif; ?>
```

---

#### **9. PRINT RECEIPT Button** 🖨️
- **Icon**: `<i class="fas fa-print"></i>`
- **Label**: "Print Receipt"
- **Color**: Gray (#6b7280)
- **Function**: Generate and print job order receipt
- **Visibility**: Only when:
  - Workflow status is: **Completed**
  - Payment status is: **Paid** (fully settled)
- **Action**: Opens print-friendly receipt in new window

**Implementation**:
```html
<?php if ($workflow_status === 'Completed' && $payment_status === 'Paid'): ?>
<button type="button"
        onclick="printJobOrderReceipt(<?= $job_id ?>,'<?= $source ?>')"
        class="txn-btn" 
        style="padding:5px 11px; font-size:10px; background:#6b7280; color:#fff;">
    <i class="fas fa-print"></i> Print Receipt
</button>
<?php endif; ?>
```

---

#### **10. RE-ENCODE Button** 🔄
- **Icon**: `<i class="fas fa-redo"></i>`
- **Label**: "Re-encode"
- **Color**: Secondary Gray (#6b7280)
- **Function**: Redirects to Job Order form to create new corrected entry
- **Visibility**: Only when workflow status is: **Rejected**
- **Action**: 
  - Navigates to `joborder.php`
  - Staff can create new job order with correct details
  - Original rejected entry remains for audit trail

**Implementation**:
```html
<?php if ($workflow_status === 'Rejected'): ?>
<a href="joborder.php" 
   class="txn-btn secondary" 
   style="padding:5px 11px; font-size:10px; text-decoration:none;">
    <i class="fas fa-redo"></i> Re-encode
</a>
<?php endif; ?>
```

---

#### **11. Awaiting Manager Approval (Status Message)** ⏰
- **Icon**: `<i class="fas fa-clock"></i>`
- **Label**: "Awaiting manager approval"
- **Color**: Gray (#94a3b8) italic text
- **Type**: **Information message** (not a button)
- **Visibility**: Only when validation_status is: **Pending Validation**
- **Action**: None - just informative

**Implementation**:
```html
<?php if ($validation_status === 'Pending Validation'): ?>
<span style="font-size:10px; color:#94a3b8; font-style:italic; text-align:center; padding:4px 0;">
    <i class="fas fa-clock"></i> Awaiting manager approval
</span>
<?php endif; ?>
```

---

### 🔄 JOB ORDER WORKFLOW & BUTTON STATES

```
WORKFLOW STAGES:
┌──────────────────────────────────────────────────────────────────┐
│ 1. PENDING VALIDATION (Staff encoded, waiting Manager approval) │
│    Buttons: [View] [Awaiting approval message]                  │
├──────────────────────────────────────────────────────────────────┤
│ 2. APPROVED (Manager validated, ready to start work)            │
│    Buttons: [View] [Update] [Adjust] [Start In Progress]        │
│             [Complete & Settle]                                  │
├──────────────────────────────────────────────────────────────────┤
│ 3. IN PROGRESS (Work actively ongoing)                          │
│    Buttons: [View] [Update] [Complete & Settle]                 │
│             [Accept Downpayment] (if unpaid/partial)            │
├──────────────────────────────────────────────────────────────────┤
│ 4. COMPLETED (Work done, payment may be pending)                │
│    Buttons: [View]                                              │
│             [Settle Balance] (if partial payment)                │
│             [Mark Paid] (if unpaid)                             │
│             [Print Receipt] (if fully paid)                      │
├──────────────────────────────────────────────────────────────────┤
│ 5. REJECTED (Manager rejected, needs correction)                │
│    Buttons: [View] [Re-encode]                                  │
└──────────────────────────────────────────────────────────────────┘
```

---

### 💰 PAYMENT STATUS & BUTTON LOGIC

```
PAYMENT STATES:
┌──────────────────────────────────────────────────────────────────┐
│ • Unpaid / Pending Payment (₱0 paid)                            │
│   → Shows: [Complete & Settle] or [Mark Paid]                   │
├──────────────────────────────────────────────────────────────────┤
│ • Partial Payment (some paid, balance remains)                  │
│   → Shows: [Accept Downpayment] (if In Progress)                │
│   → Shows: [Settle Balance] (if Completed)                      │
├──────────────────────────────────────────────────────────────────┤
│ • Paid (fully settled, balance = ₱0)                            │
│   → Shows: [Print Receipt]                                      │
└──────────────────────────────────────────────────────────────────┘
```

---

## 📋 SUB-TAB 2: MERCHANDISE HISTORY

**Purpose**: View past merchandise transactions and settle outstanding payments  
**Access**: Staff role (Staff, Cashier, Pump Attendant)  
**Table Location**: `staff_transactions_hub.php?section=merchandise&mh_open=1` → Merchandise History tab  

### ✅ ACTION BUTTONS (Merchandise History)

#### **1. SETTLE Button** 💰 (for Partial Payment)
- **Icon**: `<i class="fas fa-coins"></i>`
- **Label**: "Settle"
- **Color**: Light Blue (#e0f2fe) with blue text (#0369a1)
- **Function**: Opens payment modal to complete remaining balance
- **Visibility**: Only when:
  - Payment status is: **Partial Payment**
  - Balance due > ₱0.01
- **Action**:
  - Opens payment modal
  - Shows remaining balance
  - Staff collects final payment
  - Updates payment_status to "Paid" when fully settled
  - Records payment in audit log

**Implementation**:
```html
<?php if (strtolower($payment_status) === 'partial payment' && $balance_due > 0.009): ?>
<button type="button"
        onclick="openPaymentModal(<?= $txn_id ?>,'merchandise_transactions',<?= $total ?>,<?= $paid ?>,<?= $balance ?>,'<?= $customer ?>',false,'merchandise')"
        style="padding:3px 7px; font-size:10px; border:1px solid #7dd3fc; background:#e0f2fe; color:#0369a1; border-radius:6px; cursor:pointer; font-weight:600;">
    <i class="fas fa-coins"></i> Settle
</button>
<?php endif; ?>
```

---

#### **2. PAID Button** 💵 (for Unpaid/Pending)
- **Icon**: `<i class="fas fa-coins"></i>`
- **Label**: "Paid"
- **Color**: Light Blue (#e0f2fe) with blue text (#0369a1)
- **Function**: Opens payment modal to mark transaction as paid
- **Visibility**: Only when:
  - Payment status is: **Pending Payment**, **Unpaid**, or other non-paid states
  - Balance due > ₱0.01
- **Action**:
  - Opens payment modal
  - Staff encodes payment amount
  - Selects payment method
  - Updates payment_status
  - Records transaction

**Implementation**:
```html
<?php if (!in_array(strtolower($payment_status), ['paid', 'partial payment']) && $balance_due > 0.009): ?>
<button type="button"
        onclick="openPaymentModal(<?= $txn_id ?>,'merchandise_transactions',<?= $total ?>,<?= $paid ?>,<?= $balance ?>,'<?= $customer ?>',false,'merchandise')"
        style="padding:3px 7px; font-size:10px; border:1px solid #7dd3fc; background:#e0f2fe; color:#0369a1; border-radius:6px; cursor:pointer; font-weight:600;">
    <i class="fas fa-coins"></i> Paid
</button>
<?php endif; ?>
```

---

#### **3. No Action (Fully Paid)** ✅
- **Display**: `—` (em dash)
- **Color**: Gray (#94a3b8)
- **Visibility**: When payment_status is: **Paid** (fully settled)
- **Action**: None - transaction is complete

**Implementation**:
```html
<?php if (strtolower($payment_status) === 'paid'): ?>
<span style="font-size:11px; color:#94a3b8;">—</span>
<?php endif; ?>
```

---

### 🔍 MERCHANDISE HISTORY - ADDITIONAL FEATURES

#### **View Transaction Details** (Planned Feature)
- **Button**: "View" (currently not implemented)
- **Function**: Would open modal showing:
  - Transaction ID
  - Item details (SKU, name, quantity, unit price)
  - Customer name
  - VAT breakdown
  - Total amount
  - Payment details
  - Shift & staff info

#### **Adjust Transaction** (Planned Feature)
- **Button**: "Adjust" (currently not implemented)
- **Function**: Would allow correction of:
  - Quantity
  - Price
  - Remarks/notes
- **Note**: Should only be allowed for recent transactions (same shift)

---

### 💰 PAYMENT MODAL (Shared by Both Tabs)

**Modal Fields**:
```
Payment Settlement Modal
┌─────────────────────────────────────────────────┐
│ Customer: [Customer Name] (read-only)          │
│ Total Amount: ₱[total] (read-only)              │
│ Already Paid: ₱[amount_paid] (read-only)        │
│ Balance Due: ₱[balance] (read-only)             │
│                                                  │
│ Amount to Pay: [____] (input)                   │
│ Payment Method: [Dropdown]                      │
│   • Cash                                         │
│   • Credit Card / Debit Card                     │
│   • E-Wallet (GCash/Maya)                        │
│   • E-Fuel Card                                  │
│   • Credit / Account Receivable                  │
│                                                  │
│ Remarks: [____________] (optional)              │
│                                                  │
│ [✓] Mark as Completed (checkbox, JO only)      │
│                                                  │
│ [Cancel]  [Submit Payment]                      │
└─────────────────────────────────────────────────┘
```

**Payment Logic**:
1. Staff enters amount
2. Modal calculates new balance: `new_balance = total - (amount_paid + amount_now)`
3. Sets payment_status:
   - If `new_balance <= 0`: **Paid**
   - If `new_balance > 0`: **Partial Payment**
4. Records in `payment_audit_log` table
5. Updates transaction record
6. Shows success message with new balance

---

## 📊 BUTTON VISIBILITY SUMMARY

### Job Order Tracker Buttons by Status:

| Button | Pending Validation | Approved | In Progress | Completed (Unpaid) | Completed (Partial) | Completed (Paid) | Rejected |
|--------|-------------------|----------|-------------|-------------------|-------------------|----------------|----------|
| **View** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Update** | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Adjust** | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Start In Progress** | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Complete & Settle** | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Accept Downpayment** | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Mark Paid** | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ |
| **Settle Balance** | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| **Print Receipt** | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ |
| **Re-encode** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| **Awaiting (msg)** | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

### Merchandise History Buttons by Payment Status:

| Button | Unpaid | Pending Payment | Partial Payment | Paid |
|--------|--------|----------------|----------------|------|
| **Settle** | ❌ | ❌ | ✅ | ❌ |
| **Paid** | ✅ | ✅ | ❌ | ❌ |
| **No Action (—)** | ❌ | ❌ | ❌ | ✅ |

---

## 🎨 BUTTON STYLING STANDARDS

All action buttons follow consistent design:

```css
/* Base Action Button */
.txn-btn {
    padding: 5px 10px;
    font-size: 10px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s;
}

/* Primary (Blue) */
.txn-btn.primary {
    background: #002F70;
    color: #fff;
}

/* Success (Green) */
.txn-btn.success {
    background: #16a34a;
    color: #fff;
}

/* Secondary (Gray) */
.txn-btn.secondary {
    background: #6b7280;
    color: #fff;
}

/* Specific Button Colors */
.txn-btn-view { background: #3b82f6; }  /* Blue */
.txn-btn-adjust { background: #f59e0b; }  /* Orange */
.txn-btn-downpayment { background: #fef9c3; color: #92400e; }  /* Yellow */
.txn-btn-settle { background: #e0f2fe; color: #0369a1; }  /* Light Blue */
```

---

## ✅ IMPLEMENTATION CHECKLIST

### Job Order Tracker:
- [x] View button (modal implementation)
- [x] Update Status button (modal implementation)
- [x] Adjust button (modal implementation)
- [x] Start In Progress button
- [x] Complete & Settle button (payment modal)
- [x] Accept Downpayment button (payment modal)
- [x] Mark Paid button (payment modal)
- [x] Settle Balance button (payment modal)
- [x] Print Receipt button
- [x] Re-encode button (link)
- [x] Awaiting approval message

### Merchandise History:
- [x] Settle button (partial payment)
- [x] Paid button (unpaid/pending)
- [x] No action display (fully paid)
- [ ] View button (planned)
- [ ] Adjust button (planned)

### Payment Modal:
- [x] Modal structure
- [x] Amount calculation
- [x] Payment method dropdown
- [x] Balance calculation
- [x] Status update logic
- [x] Audit log recording
- [x] Mark as Completed checkbox (JO only)

---

## 🚀 USAGE EXAMPLES

### Example 1: Complete Job Order with Full Payment
```
1. Job is "In Progress"
2. Staff clicks [Complete & Settle]
3. Payment modal opens
4. Staff enters: Amount = ₱1,500, Method = Cash
5. Checkbox "Mark as Completed" is checked
6. Staff clicks [Submit Payment]
7. System updates:
   - workflow_status = 'Completed'
   - payment_status = 'Paid'
   - amount_paid = 1500
   - balance_due = 0
8. Success message shown
9. [Print Receipt] button now visible
```

### Example 2: Accept Downpayment on Job Order
```
1. Job is "In Progress"
2. Customer wants to pay ₱500 downpayment (Total: ₱2,000)
3. Staff clicks [Accept Downpayment]
4. Payment modal opens
5. Staff enters: Amount = ₱500, Method = Cash
6. Checkbox "Mark as Completed" is UNCHECKED
7. Staff clicks [Submit Payment]
8. System updates:
   - workflow_status = 'In Progress' (unchanged)
   - payment_status = 'Partial Payment'
   - amount_paid = 500
   - balance_due = 1500
9. Job continues, staff can settle balance later
```

### Example 3: Settle Merchandise Balance
```
1. Merch transaction shows: Total ₱800, Paid ₱300, Balance ₱500
2. Payment status: "Partial Payment"
3. Staff clicks [Settle] in Merchandise History
4. Payment modal opens
5. Staff enters: Amount = ₱500, Method = GCash
6. Staff clicks [Submit Payment]
7. System updates:
   - payment_status = 'Paid'
   - amount_paid = 800
   - balance_due = 0
8. Button changes to "—" (no action needed)
```

---

## 📚 RELATED FILES

- **Main File**: `public/staff_transactions_hub.php`
- **Backend**: `backend/export_staff_transactions.php`
- **Audit Log**: `payment_audit_log` table
- **Database Tables**:
  - `job_orders`
  - `merchandise_transactions`
  - `payment_audit_log`
  - `labor_sessions`
  - `shift_periods`

---

**Documentation Version**: 1.0  
**Last Updated**: June 3, 2026  
**Status**: Complete  
**Module**: Staff Transaction Hub - Action Buttons  

---

**Cebuano Summary**:
✅ **Klaro na ang tanan!** Ang Job Order Tracker naay 11 ka action buttons depende sa status, ug ang Merchandise History naay 3 ka actions para sa payment settlement. Kompleto na ang documentation! 🎉
