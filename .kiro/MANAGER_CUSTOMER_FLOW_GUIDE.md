# Manager Customer Management - Flow Guide

## 📌 End-to-End Flow (Manager Role)

```
┌─────────────────────────────────────────────────────────────────┐
│                    MANAGER CUSTOMER MODULE                       │
│                http://localhost/.../manager_customers.php        │
└─────────────────────────────────────────────────────────────────┘

┌──────────────┬──────────────┬──────────────┬──────────────┐
│ Add New      │ Customer     │ Customer     │ Customer     │
│ Customer     │ List         │ Balances     │ History      │
└──────────────┴──────────────┴──────────────┴──────────────┘
```

---

## 1️⃣ ADD NEW CUSTOMER
**URL:** `?section=add`  
**Purpose:** Encode private and confidential customer information

### Manager mo-input sa:
- ✅ **Customer Name** (First + Last)
- ✅ **Contact Number** (private data)
- ✅ **Government ID Type** (Driver's License, Passport, etc.)
- ✅ **ID Image Upload** (front/copy of selected ID)
- ✅ **Credit Limit** (₱ amount - financial data)
- ✅ **CR Upload** (Certificate of Registration for business)

### Why Manager-only?
- 🔒 **Security ug Confidentiality** — Credit line details are sensitive
- 🔒 **Suki Account Setup** — Special terms for loyal customers
- 🔒 **Private Information** — Contact numbers, ID images, financial limits
- 🔒 **Business Documents** — CR is confidential

### Result:
- Customer saved to database with `mgr_status = 'approved'`
- Audit log created: "New customer encoded: [Name] | Credit Limit: ₱X"
- Redirects to Customer List with success message

---

## 2️⃣ CUSTOMER LIST
**URL:** `?section=records`  
**Purpose:** Review and validate customer profiles

### Manager makakita sa:
| Column | Description |
|--------|-------------|
| **ID** | Customer reference number |
| **Name** | Full name of customer |
| **Contact** | Phone number |
| **ID Type** | Government ID selected |
| **Credit Limit** | Total credit line (₱) |
| **Remaining Balance** | Available credit (₱) |
| **Status** | Active / Inactive |
| **Action** | Edit button |

### Features:
- 🔍 **Real-time Search** — Filter by customer name
- ✏️ **Click to Edit** — Update customer details
- 🎨 **Color-Coded Balance:**
  - 🔴 Red = No credit remaining (maxed out)
  - 🟠 Orange = Low credit (< 20% remaining)
  - 🟢 Green = Healthy credit balance

### Manager Actions:
1. **Validate Authenticity** — Check if ID, contact info are legitimate
2. **Edit Details** — Update name, contact, ID type, credit limit
3. **Upload Documents** — Replace ID image or CR if needed
4. **Adjust Credit Terms** — Increase or decrease credit limit

### Result:
- Manager can ensure completeness and legitimacy of customer data
- Changes logged to audit trail

---

## 3️⃣ CUSTOMER BALANCES
**URL:** `?section=balances`  
**Purpose:** Monitor outstanding balances and credit usage vs limits

### Summary Cards (Top):
```
┌─────────────────────┬─────────────────────┬─────────────────────┐
│ Total Credit Limit  │ Total Outstanding   │ Available Credit    │
│ ₱ XXX,XXX.XX        │ ₱ XX,XXX.XX         │ ₱ XXX,XXX.XX        │
└─────────────────────┴─────────────────────┴─────────────────────┘
```

### Balance Table:
| Column | Description |
|--------|-------------|
| **Name** | Customer name |
| **Contact** | Phone number |
| **Credit Limit** | Total credit line (₱) |
| **Outstanding Balance** | Current debt (₱) - RED if > 0 |
| **Available Credit** | Remaining capacity (₱) |
| **Utilization** | Visual bar + percentage |
| **Last Transaction** | Date of most recent activity |
| **Action** | 💰 Record Payment button |

### Visual Alerts:
- 🔴 **Red Row Background** = Customer over-limit (outstanding ≥ credit limit)
- 🟠 **Orange Row Background** = Customer near-limit (utilization ≥ 80%)
- 🟢 **Normal** = Healthy credit usage

### Record Payment Flow:
1. Click **"Record Payment"** button
2. Modal opens showing:
   - Customer name
   - Current outstanding balance
3. Manager enters:
   - **Payment Amount** (₱)
   - **Reference** (e.g., "Cash payment", "OR #12345")
4. System validates:
   - Amount > 0
   - Reference ≥ 3 characters
5. If **overpayment** detected:
   - Prompt: "Amount exceeds balance by ₱X. Continue?"
   - Manager can confirm or cancel
6. Payment processed:
   - Balance updated: `new_balance = outstanding - payment`
   - Audit log created
   - Success message shown
7. Page refreshes with updated balance

### Manager Actions:
- 📊 **Track Payments** — Monitor when customers pay
- 🚩 **Flag Over-Limit** — Identify customers who exceeded credit
- ⚠️ **Watch Near-Limit** — Customers approaching their limit
- 💰 **Record Payment** — Update balance when payment received
- 🔄 **Adjust Terms** — If needed, edit customer to change credit limit

### Result:
- Financial monitoring and control maintained
- Payment records tracked in audit log
- Balances updated in real-time

---

## 4️⃣ CUSTOMER HISTORY
**URL:** `?section=history`  
**Purpose:** View transaction history linked to each customer

### Filter Options:
```
┌──────────────┬──────────────┬──────────────┬──────────────┐
│ Customer     │ Start Date   │ End Date     │ Apply        │
│ [All/Select] │ [YYYY-MM-DD] │ [YYYY-MM-DD] │ [Button]     │
└──────────────┴──────────────┴──────────────┴──────────────┘
```
- Default: **Last 90 days**
- Can filter by specific customer or show all

### Transaction Table:
| Column | Description |
|--------|-------------|
| **Date** | Transaction date & time |
| **Reference** | TXN-XXX / JO-XXX / PAY-XXX |
| **Type** | Merchandise Sale / Job Order / Payment |
| **Amount** | Transaction amount (₱) |
| **Payment Method** | Cash / Credit / Check / GCash |
| **Recorded By** | Staff member name |

### Transaction Sources:
1. **Merchandise Sales** (from `merchandise_transactions`)
   - Products bought by customer
   - Badge: 🟢 Green "Merchandise Sale"

2. **Job Orders** (from `job_orders`)
   - Services provided (oil change, wash, etc.)
   - Only Completed/Validated/Approved shown
   - Badge: 🟠 Orange "Job Order"

3. **Payments** (from `audit_logs`)
   - Payment validations recorded by Manager
   - Badge: 🟢 Green "Payment"
   - Amount shown in green to indicate credit

### Manager Actions:
- 📜 **View Transaction History** — See all activity for a customer
- 🔍 **Validate Linkage** — Ensure transactions properly linked to customer
- 🚫 **Prevent Duplication** — Catch if same transaction recorded twice
- 🛡️ **Detect Fraud** — Identify suspicious patterns
- 📊 **Transparency** — Full audit trail visible

### Result:
- Complete oversight of customer transactions
- Transparency in all financial activity
- Easy to spot anomalies or issues

---

## 🔄 Complete Workflow Example

### Scenario: New Suki Customer na gusto ug credit line

#### Step 1: Add Customer (Manager)
```
Manager → Add New Customer
├─ Name: Juan Dela Cruz
├─ Contact: 09171234567
├─ ID Type: Driver's License
├─ Upload ID: [DL_image.jpg]
├─ Credit Limit: ₱50,000.00
└─ Save → ✅ Customer created
```

#### Step 2: Monitor Balance
```
Manager → Customer Balances
└─ Juan Dela Cruz
   ├─ Credit Limit: ₱50,000.00
   ├─ Outstanding: ₱0.00
   ├─ Available: ₱50,000.00
   └─ Utilization: 0%
```

#### Step 3: Customer Makes Purchase (Staff encodes)
```
Staff → Merchandise Transaction
├─ Customer: Juan Dela Cruz
├─ Items: Gasoline + Oil
├─ Total: ₱5,250.00
└─ Payment Method: Credit
```

#### Step 4: Check Updated Balance (Manager)
```
Manager → Customer Balances
└─ Juan Dela Cruz
   ├─ Credit Limit: ₱50,000.00
   ├─ Outstanding: ₱5,250.00 (RED)
   ├─ Available: ₱44,750.00
   └─ Utilization: 10.5%
```

#### Step 5: View Transaction History (Manager)
```
Manager → Customer History
└─ Juan Dela Cruz transactions:
   ├─ May 15, 2024 — TXN-12345 — Merchandise Sale — ₱5,250.00
   └─ Recorded by: Maria Santos (Staff)
```

#### Step 6: Customer Pays (Manager records)
```
Manager → Customer Balances → Record Payment
├─ Customer: Juan Dela Cruz
├─ Amount: ₱5,250.00
├─ Reference: "Cash payment, OR #4567"
└─ Confirm → ✅ Balance updated to ₱0.00
```

#### Step 7: Verify Payment in History
```
Manager → Customer History
└─ Juan Dela Cruz transactions:
   ├─ May 15 — TXN-12345 — Merchandise Sale — ₱5,250.00
   ├─ May 22 — PAY-89 — Payment — ₱5,250.00 (GREEN)
   └─ Recorded by: Manager Name
```

---

## 🎯 Key Takeaways

### Manager = 4 Powers:
1. **Encode** → Private customer info (Add New Customer)
2. **Validate** → Check authenticity (Customer List)
3. **Monitor** → Track credits & payments (Customer Balances)
4. **Oversee** → View transaction history (Customer History)

### System Behavior:
- ✅ Manager-only access to confidential data
- ✅ Real-time balance updates
- ✅ Complete audit trail
- ✅ Financial monitoring & control
- ✅ Transparency in transactions

### Staff Cannot:
- ❌ Set credit limits
- ❌ View ID images or CR
- ❌ Record payments
- ❌ Access full transaction history

---

## 🚀 Quick Access

- **Add New:** `manager_customers.php?section=add`
- **Customer List:** `manager_customers.php?section=records`
- **Balances:** `manager_customers.php?section=balances`
- **History:** `manager_customers.php?section=history`

**Navigation:** Clean tab interface at the top — click to switch sections instantly!
