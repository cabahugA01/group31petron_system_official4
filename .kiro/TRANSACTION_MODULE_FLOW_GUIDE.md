# 📋 TRANSACTION MODULE FLOW - COMPLETE GUIDE
## Petron Station Management System

---

## 🎯 Overview

This guide documents the complete transaction flow from Staff encoding to Admin oversight, covering **Merchandise and Job Order transactions only** (NO FUEL transactions).

**Supported Payment Types:**
- Cash
- Card (Credit/Debit)
- E-Wallet (GCash, PayMaya, etc.)
- E-Fuel Card
- Credit/Utang (Accounts Receivable)
- Partial Payment (Downpayment + Balance)

**Transaction Types:**
- ✅ Merchandise
- ✅ Job Order (Services)
- ✅ JO + Merchandise (Combined)
- ❌ Fuel (handled in separate module)

---

## 🔄 Transaction Flow Stages

```
┌─────────────┐      ┌─────────────┐      ┌─────────────┐
│   STAFF     │ ───> │   MANAGER   │ ───> │    ADMIN    │
│  (Encode)   │      │ (Validate)  │      │ (Oversight) │
└─────────────┘      └─────────────┘      └─────────────┘
  Pending              Approved/            Read-Only
  Validation           Completed            View
```

---

## 1️⃣ STAFF PROCESS (Transaction Encoding)

### **Location:** Staff Dashboard → Create Transaction / Job Order

### **Input Example:**
```
Customer: Pedro Santos
Vehicle: Sedan, Plate No. ABC 1234
Service: Change Oil (₱300)
Merchandise: Engine Oil (₱500)
Total Amount: ₱800
Payment Method: Cash / Card / E-Wallet / E-Fuel Card / Credit
Payment Status: Paid / Partial / Utang
```

### **Actions:**
1. **Select Transaction Type:**
   - Merchandise Only
   - Job Order (Service) Only
   - JO + Merchandise (Combined)

2. **Enter Transaction Details:**
   - Customer name
   - Vehicle information (if Job Order)
   - Select items/services from inventory
   - Calculate total amount

3. **Select Payment Method:**
   - **Cash** - Direct payment
   - **Card** - Credit/Debit card
   - **E-Wallet** - GCash, PayMaya, etc.
   - **E-Fuel Card** - Fleet card payment
   - **Credit/Utang** - Customer account receivable

4. **Record Payment Status:**
   - **Paid** - Full payment received
   - **Partial** - Downpayment received, balance pending
     - Example: DP ₱300 (Cash), Balance ₱500 (Pending)
   - **Utang** - Full amount on credit (₱0 paid)

5. **Submit Transaction:**
   - Creates Transaction ID (e.g., #TXN008)
   - Status: **Pending Validation**
   - Saved to `merchandise_transactions` table

### **Database Fields:**
```sql
INSERT INTO merchandise_transactions (
    transaction_id,
    customer_name,
    vehicle_plate,          -- if Job Order
    job_order_service,      -- if Job Order
    total_amount,
    payment_method,         -- Cash/Card/E-Wallet/E-Fuel Card/Credit
    amount_paid,            -- Actual amount paid
    payment_status,         -- Paid/Partial/Unpaid
    validation_status,      -- 'Pending'
    staff_id,
    station_id,
    created_at
) VALUES (...)
```

### **Outputs:**
- ✅ Transaction ID created: **#TXN008**
- ✅ Status: **Pending Validation**
- ✅ Visible in **Manager's Pending Transactions** tab
- ✅ If Job Order: Tracked in **Job Order Tracker** → "In Progress"
- ✅ If Partial/Utang: Recorded in **Accounts Receivable**

---

## 2️⃣ MANAGER PROCESS (Transaction Validation)

### **Location:** Manager Dashboard → Transactions (Pending Tab)

### **Input View:**
```
Transaction ID: #TXN008
Customer: Pedro Santos
Type: JO + Merchandise
Items:
  - Change Oil Service: ₱300
  - Engine Oil (1L): ₱500
Total Amount: ₱800
Payment Method: Cash
Amount Paid: ₱300
Payment Status: Partial (Balance: ₱500)
Validation Status: Pending
Staff: Juan Dela Cruz
Date: May 24, 2026
```

### **Actions:**
1. **Review Transaction Details:**
   - Verify customer information
   - Check items/services provided
   - Validate pricing
   - Confirm payment details

2. **Decision Options:**
   - **✅ Approve** - Transaction is correct
     - Status → **Approved**
     - Records `validated_by` and `validated_at`
   
   - **❌ Reject** - Transaction has errors
     - Status → **Rejected**
     - Add rejection reason
     - Returns to Staff for correction
   
   - **🔧 Adjust** - Minor corrections needed
     - Update total amount
     - Add adjustment notes
     - Status → **Adjusted**

3. **Payment Tracking:**
   - **Fully Paid** → Transaction completed
   - **Partial Payment** → Track balance in Customer Account
   - **Utang (Credit)** → Create receivable record

4. **Job Order Tracking:**
   - Update Job Order status
   - Assign mechanic (if applicable)
   - Track completion progress

### **Database Updates:**
```sql
-- Approve Transaction
UPDATE merchandise_transactions
SET validation_status = 'Approved',
    validated_by = [manager_id],
    validated_at = NOW(),
    updated_at = NOW()
WHERE id = [transaction_id];

-- If Partial/Utang, update customer balance
UPDATE customers
SET balance_due = balance_due + [outstanding_amount]
WHERE id = [customer_id];

-- Insert audit trail
INSERT INTO audit_trail (
    transaction_id,
    manager_id,
    action_type,
    station_id,
    created_at
) VALUES (
    [transaction_id],
    [manager_id],
    'Approve',
    [station_id],
    NOW()
);
```

### **Outputs:**
- ✅ Transaction validated and committed to DB
- ✅ Status updated: **Approved** / **Adjusted** / **Rejected**
- ✅ Balance tracked in **Customer History**
- ✅ Outstanding amount in **Accounts Receivable**
- ✅ Job Order status updated in **Job Order Tracker**
- ✅ Visible in **Admin Oversight Dashboard**
- ✅ Audit log created for transparency

---

## 3️⃣ ADMIN PROCESS (Oversight & Monitoring)

### **Location:** Admin Dashboard → Transactions → Oversight Dashboard

### **Input View:**
```
Transaction ID: #TXN008
Customer: Pedro Santos
Type: JO + Merchandise
Items: Change Oil, Engine Oil
Total Amount: ₱800
Payment Method: Cash
Payment Status: Partial (Paid: ₱300, Balance: ₱500)
Validation Status: Approved
Date/Time: May 24, 2026 14:15
Staff: Juan Dela Cruz
```

### **Features:**

#### **A. Oversight Dashboard**
**Purpose:** Read-only view of validated transactions

**Displays:**
- ✅ **Merchandise** transactions only
- ✅ **Job Order** transactions only
- ✅ **JO + Merchandise** combined transactions
- ✅ **Approved** and **Completed** status only
- ❌ NO Fuel transactions
- ❌ NO Pending transactions
- ❌ NO action buttons (read-only)

**Table Columns:**
1. Transaction ID
2. Customer
3. Type (Merchandise / Job Order / JO + Merchandise)
4. Items / Service
5. Amount (₱)
6. Payment Method
7. Payment Status (Paid / Partial / Unpaid)
8. Validation Status (Approved / Completed)
9. Date / Time
10. Staff

**Filters Available:**
- Date range (start/end)
- Search (Transaction ID, customer)
- Type (All / Merchandise / Job Order / JO + Merchandise)
- Status (All / Approved / Completed)

**Export Options:**
- 📊 **Export Excel** - Full transaction data
- 🖨️ **Print** - Print-friendly report

#### **B. Variance Reports (System-Wide)**
**Purpose:** Monitor fuel variance (separate from transactions)

**Note:** Fuel variance is tracked separately and does NOT appear in Oversight Dashboard.

---

## 📊 PAYMENT TYPES HANDLING

### **1. Cash Payment**
```sql
payment_method = 'Cash'
amount_paid = total_amount
payment_status = 'Paid'
```
**Flow:** Staff receives cash → Records full amount → Manager validates → Admin oversees

---

### **2. Card Payment**
```sql
payment_method = 'Card'
amount_paid = total_amount
payment_status = 'Paid'
```
**Flow:** Staff processes card → Records full amount → Manager validates → Admin oversees

---

### **3. E-Wallet Payment**
```sql
payment_method = 'E-Wallet'
amount_paid = total_amount
payment_status = 'Paid'
```
**Flow:** Staff receives e-wallet payment → Records full amount → Manager validates → Admin oversees

---

### **4. E-Fuel Card**
```sql
payment_method = 'E-Fuel Card'
amount_paid = total_amount
payment_status = 'Paid'
```
**Flow:** Staff swipes fuel card → Records full amount → Manager validates → Admin oversees

---

### **5. Credit/Utang (Accounts Receivable)**
```sql
payment_method = 'Credit'
amount_paid = 0
payment_status = 'Unpaid'
```
**Flow:** 
1. Staff records transaction with ₱0 paid
2. Manager validates and creates receivable
3. Customer account updated with balance due
4. Admin monitors in Accounts Receivable report

**Tracking:**
- Customer balance updated
- Appears in Accounts Receivable module
- Payment reminders generated
- Can be paid later and reconciled

---

### **6. Partial Payment**
```sql
payment_method = 'Cash' (or any method)
amount_paid = 300  -- Downpayment
payment_status = 'Partial'
-- Balance: total_amount - amount_paid = 500
```
**Flow:**
1. Staff records DP amount paid
2. Balance tracked as receivable
3. Manager validates both payment and balance
4. Admin monitors outstanding balance
5. Second payment can be recorded later

**Example:**
- Total: ₱800
- Downpayment: ₱300 (Cash)
- Balance: ₱500 (Pending)
- Status: **Partial Payment**

---

## 📈 REPORTING & ANALYTICS

### **Admin Reports Available:**

#### **1. Sales Summary**
- Total Sales by Payment Type
- Cash vs Card vs E-Wallet vs Credit
- Paid vs Partial vs Unpaid breakdown

#### **2. Accounts Receivable**
- Outstanding balances by customer
- Aging report (30/60/90 days)
- Payment collection tracking

#### **3. Transaction Oversight**
- Validated transactions only
- Merchandise + Job Order breakdown
- Staff performance metrics
- Manager validation turnaround time

#### **4. Audit Trail**
- Staff encode → Manager validate flow
- All actions logged with timestamps
- User accountability tracking
- Compliance reporting

---

## 🗄️ DATABASE SCHEMA

### **Main Table: `merchandise_transactions`**
```sql
CREATE TABLE merchandise_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(50) UNIQUE,
    customer_name VARCHAR(255),
    vehicle_plate VARCHAR(20),           -- For Job Orders
    job_order_service TEXT,              -- For Job Orders
    job_order_vehicle_plate VARCHAR(20), -- For combined JO
    total_amount DECIMAL(10,2),
    amount_paid DECIMAL(10,2) DEFAULT 0,
    payment_method ENUM('Cash', 'Card', 'E-Wallet', 'E-Fuel Card', 'Credit'),
    payment_status ENUM('Paid', 'Partial', 'Unpaid') DEFAULT 'Paid',
    validation_status ENUM('Pending', 'Approved', 'Rejected', 'Adjusted', 'Completed') DEFAULT 'Pending',
    staff_id INT,
    validated_by INT,
    validated_at DATETIME,
    station_id INT,
    remarks TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME,
    FOREIGN KEY (staff_id) REFERENCES users(id),
    FOREIGN KEY (validated_by) REFERENCES users(id),
    FOREIGN KEY (station_id) REFERENCES stations(id)
);
```

### **Supporting Tables:**

#### **`merchandise_transaction_items`**
```sql
CREATE TABLE merchandise_transaction_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id INT,
    item_type ENUM('merchandise', 'service'),
    product_name VARCHAR(255),
    quantity INT,
    unit_price DECIMAL(10,2),
    total_price DECIMAL(10,2),
    FOREIGN KEY (transaction_id) REFERENCES merchandise_transactions(id)
);
```

#### **`job_orders`**
```sql
CREATE TABLE job_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_name VARCHAR(255),
    vehicle_plate VARCHAR(20),
    service_type VARCHAR(255),
    total_cost DECIMAL(10,2),
    amount_paid DECIMAL(10,2),
    payment_method VARCHAR(50),
    validation_status ENUM('Pending Validation', 'Approved', 'Rejected', 'In Progress', 'Completed'),
    status ENUM('Pending', 'In Progress', 'Completed', 'Cancelled'),
    assigned_mechanic_id INT,
    created_by INT,
    validated_by INT,
    validated_at DATETIME,
    station_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (station_id) REFERENCES stations(id)
);
```

#### **`audit_trail`**
```sql
CREATE TABLE audit_trail (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id INT,
    manager_id INT,
    action_type ENUM('Approve', 'Reject', 'Adjust', 'Return'),
    new_value TEXT,
    station_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## ✅ VALIDATION RULES

### **Staff Level:**
1. ✅ Customer name required
2. ✅ At least one item/service must be added
3. ✅ Total amount > 0
4. ✅ Payment method must be selected
5. ✅ If Partial: amount_paid < total_amount
6. ✅ If Paid: amount_paid = total_amount
7. ✅ If Credit: amount_paid = 0

### **Manager Level:**
1. ✅ Cannot approve transactions with validation_status = 'Pending' from raw staff encode
2. ✅ Must review all transaction details before approval
3. ✅ Rejection requires reason/notes
4. ✅ Adjustment requires updated amount and notes
5. ✅ Partial payments tracked in customer account
6. ✅ Credit transactions create receivable record

### **Admin Level:**
1. ✅ **Read-only access** - No approval actions
2. ✅ View only **Approved** and **Completed** transactions
3. ✅ Monitor **Merchandise** and **Job Order** only
4. ✅ NO Fuel transactions visible
5. ✅ Export and print functionality available

---

## 🚫 IMPORTANT EXCLUSIONS

### **Admin Oversight Dashboard Does NOT Show:**
- ❌ **Fuel transactions** (handled in separate Variance Reports)
- ❌ **Pending transactions** (Manager's responsibility)
- ❌ **Rejected transactions** (archived)
- ❌ **Action buttons** (read-only view)

---

## 📝 EXAMPLE TRANSACTION FLOW

### **Complete Example: Pedro Santos - Oil Change + Engine Oil**

#### **Step 1: Staff Encoding**
```
Date: May 24, 2026 10:30 AM
Staff: Juan Dela Cruz
Customer: Pedro Santos
Vehicle: Sedan, ABC 1234

Items:
- Change Oil Service: ₱300
- Engine Oil (1L): ₱500

Total: ₱800
Payment Method: Cash
Amount Paid: ₱300 (Downpayment)
Balance: ₱500 (Pending)
Payment Status: Partial

Action: Submit Transaction
Result: Transaction ID #TXN008 created
Status: Pending Validation
```

#### **Step 2: Manager Validation**
```
Date: May 24, 2026 11:45 AM
Manager: Maria Garcia

Review:
✅ Customer details correct
✅ Items/services accurate
✅ Pricing verified
✅ Downpayment recorded: ₱300
⚠️ Balance pending: ₱500

Action: Approve Transaction
Result:
- Status → Approved
- Balance ₱500 added to Pedro Santos account
- Job Order → In Progress
- Visible in Admin Oversight
```

#### **Step 3: Admin Monitoring**
```
Date: May 24, 2026 2:00 PM
Admin: Katherine Pepito

View: Oversight Dashboard
Transaction #TXN008 displayed:
- Customer: Pedro Santos
- Type: JO + Merchandise
- Amount: ₱800
- Payment: Partial (₱300 paid, ₱500 balance)
- Status: Approved
- Date: May 24, 2026 11:45

Reports:
- Accounts Receivable: Pedro Santos - ₱500 outstanding
- Sales Summary: ₱800 total (₱300 collected, ₱500 pending)
- Audit Trail: Staff encode → Manager approve
```

---

## 🎯 KEY TAKEAWAYS

1. ✅ **Merchandise and Job Order transactions only** (NO Fuel)
2. ✅ **All payment types supported** (Cash, Card, E-Wallet, E-Fuel Card, Credit)
3. ✅ **Payment status tracking** (Paid, Partial, Unpaid)
4. ✅ **3-tier validation** (Staff → Manager → Admin)
5. ✅ **Accounts Receivable integration** for credit/partial payments
6. ✅ **Admin read-only oversight** with export capabilities
7. ✅ **Complete audit trail** for transparency
8. ✅ **No action buttons in Admin view** (validated transactions only)

---

**Document Version:** 1.0  
**Last Updated:** May 24, 2026  
**System:** Petron Station Management System  
**Module:** Transaction Management (Merchandise & Job Orders)
