# 📌 TRANSACTION MODULE FLOW - FINAL SPECIFICATION

**System**: Petron Station Management System  
**Module**: Transaction Management (Staff → Manager)  
**Last Updated**: June 3, 2026  
**Status**: ✅ FINALIZED

---

## 🔄 WORKFLOW OVERVIEW

```
[STAFF] → Encode Transaction → Pending Validation
            ↓
[MANAGER] → Review & Validate → Approved/Rejected
            ↓
        (Transaction Complete - Manager has final authority)
```

**Note**: Admin does NOT handle transaction validation/approval. Admin has NO transaction module access.

---

## 👤 1. STAFF SIDE - Transaction Encoding & Job Order Tracker

### **A. Transaction Encoding Form**

#### **Inputs**:
- ✅ **Customer Information**: Name, Contact, Vehicle Plate
- ✅ **Transaction Type**: Merchandise, Job Order/Service
- ✅ **Items/Services**: Product selection, Quantity, Unit Price
- ✅ **Payment Method**: 
  - Cash
  - Credit Card
  - E-Wallet (GCash/Maya)
  - E-Fuel Card
  - Credit/Utang (Account Receivable)
- ✅ **Payment Details**:
  - Downpayment amount
  - Balance due
  - Payment status (Paid/Partial/Unpaid)

#### **Actions**:
1. **Encode Transaction**
   - Fill out customer details
   - Select merchandise/service items
   - Add to cart
   - Set payment method
   - Record downpayment (if partial)
   - Mark as Utang/Credit (if unpaid)

2. **Submit for Validation**
   - Transaction saved with `validation_status` = 'Pending Validation'
   - Payment status tracked: Paid / Partial Payment / Unpaid
   - Transaction ID generated (e.g., TXN-12345, JO-67)

3. **Track in Job Order Tracker**
   - View all encoded transactions
   - Monitor status: Pending → In Progress → Completed
   - Update workflow status
   - Process payments

#### **Back Button Navigation**:
```
Transaction Form → [Back] → Job Order Tracker List
Job Order Tracker → [Back] → Staff Dashboard
Merchandise History → [Back] → Staff Transactions Hub
```

#### **Auto-Refresh Behavior**:
- ✅ Dashboard auto-refreshes every 30 seconds
- ✅ Job Order Tracker updates on status change
- ✅ No manual Refresh button needed
- ✅ Page reload returns to same tab/section

#### **Outputs**:
- ✅ Transaction ID created
- ✅ Status: `Pending Validation`
- ✅ Payment Status: `Paid` / `Partial Payment` / `Unpaid` / `Credit`
- ✅ Appears in Staff's Job Order Tracker
- ✅ Appears in Manager's Pending Transactions

---

## 👔 2. MANAGER SIDE - Validation & Oversight

### **A. Pending Transactions (Validation Queue)**

#### **Page**: `pending_transactions.php`

#### **Inputs** (What Manager Sees):
- ✅ Transaction ID
- ✅ Customer Name
- ✅ Transaction Type (Merchandise / Job Order)
- ✅ Items/Service description
- ✅ Total Amount
- ✅ Payment Method
- ✅ Payment Status (Paid/Partial/Unpaid)
- ✅ Date & Time
- ✅ Staff who encoded
- ✅ Validation Status badge

#### **Actions**:
1. **Review Transaction**
   - View detailed transaction breakdown
   - Check items, quantities, prices
   - Verify payment method and status
   - Review customer information

2. **Approve Transaction**
   - Click **[Approve]** button
   - Sets `validation_status` = 'Approved'
   - Sets `validated_by` = Manager ID
   - Sets `validated_at` = NOW()
   - Transaction moves to Validated Transactions

3. **Reject Transaction**
   - Click **[Reject]** button
   - Enter rejection reason
   - Sets `validation_status` = 'Rejected'
   - Transaction removed from pending queue
   - Staff notified

4. **Adjust Transaction**
   - Click **[Adjust]** button
   - Modify: Quantity, Price, Service Fee
   - Enter adjustment reason
   - Sets `validation_status` = 'Adjusted'
   - Transaction moves to Validated Transactions

#### **Back Button Navigation**:
```
Pending Transactions → [Back] → Manager Dashboard
Transaction Details Modal → [Close/Back] → Pending Transactions List
Validated Transactions → [Back] → Manager Dashboard
```

#### **Outputs**:
- ✅ Transaction validated and committed to DB
- ✅ Status: `Approved` / `Rejected` / `Adjusted`
- ✅ Appears in Manager's **Validated Transactions** page
- ✅ Appears in Staff's **Job Order Tracker** (if approved)
- ✅ Balance tracked in **Accounts Receivable** (if Credit/Utang)
- ✅ Audit trail created

---

### **B. Validated Transactions (Post-Approval View)**

#### **Page**: `manager_validated_transactions.php`

#### **Features**:
- ✅ View all approved/validated transactions
- ✅ Filter by: Date range, Customer, Transaction type
- ✅ Search by: Transaction ID, Customer name
- ✅ Export options (see Export section below)

#### **Export Options**:

##### **🟢 Excel Button** (Green)
- **Icon**: `fa-file-excel`
- **Action**: `exportTable('excel')`
- **Output**: `.xls` file download
- **Includes**: All validated transactions with filters applied
- **Columns**: Transaction ID, Customer, Type, Items/Service, Amount, Payment Method, Date/Time, Staff, Validated By

##### **🟢 CSV Button** (Green)
- **Icon**: `fa-file-csv`
- **Action**: `exportTable('csv')`
- **Output**: `.csv` file download
- **Use Case**: Import to spreadsheet for analysis
- **Format**: Comma-separated values

##### **🔴 PDF Button** (Red)
- **Icon**: `fa-file-pdf`
- **Action**: `exportTable('pdf')`
- **Output**: Print dialog → Save as PDF
- **Use Case**: Official compliance reports
- **Includes**: Header, total amount, timestamp

##### **⚪ Back Button** (Gray)
- **Icon**: `fa-arrow-left`
- **Action**: `window.history.back()`
- **Destination**: Manager Dashboard

#### **Button Specifications**:
- Size: `110px × 36px` (compact)
- Border-radius: `8px`
- Font size: `13px`
- Icon size: `16px`
- Gap between buttons: `8px`
- **NO REFRESH BUTTON** (system auto-refreshes)

#### **Back Button Navigation**:
```
Validated Transactions → [Back] → Manager Dashboard
Export Dialog → [Cancel] → Validated Transactions
Filter Results → [Reset] → Validated Transactions (all records)
```

---

## ✅ ADMIN ROLE - NO TRANSACTION MODULE ACCESS

### **Admin Has NO Transaction Module Access**

Admin role does **NOT** have access to any transaction-related features:

#### **Admin Does NOT Have Access To**:
- ❌ Transaction validation/approval
- ❌ Pending transactions queue
- ❌ Validated transactions view
- ❌ Transaction oversight dashboard
- ❌ Transaction export functions
- ❌ ANY financial or payment tracking features

#### **Admin Responsibilities** (Non-Transaction):
1. ✅ User Management (Create/Edit/Archive users)
2. ✅ Staff Oversight (Monitor staff activities)
3. ✅ System Configuration (Settings, permissions)
4. ✅ Fuel Management (Prices, deliveries, reconciliation)
5. ✅ Inventory Management (Stock levels, products)
6. ✅ Reports (Operational, not transaction-specific)

**Transaction Management**: Staff → Manager (Final Authority)  
**Admin Role**: System administration only - NO transaction involvement

---

## 🔄 STATUS FLOW & VALIDATION BADGES

### **Validation Status Values**:

| Status              | Badge Color | Icon            | Meaning                           |
|---------------------|-------------|-----------------|-----------------------------------|
| Pending Validation  | 🟡 Amber    | hourglass-half  | Awaiting manager review           |
| Approved            | 🟢 Green    | check-circle    | Manager validated & approved      |
| Validated           | 🔵 Blue     | shield-check    | Secondary validation complete     |
| Adjusted            | 🟠 Orange   | edit            | Manager adjusted transaction      |
| Rejected            | 🔴 Red      | times-circle    | Manager rejected transaction      |

### **Payment Status Values**:

| Payment Status  | Badge Color | Meaning                               |
|-----------------|-------------|---------------------------------------|
| Paid            | 🟢 Green    | Fully paid (balance = 0)              |
| Partial Payment | 🟡 Yellow   | Downpayment made, balance remaining   |
| Unpaid          | 🔴 Red      | No payment made yet                   |
| Credit/Utang    | 🟠 Orange   | Account receivable (customer owes)    |

### **Workflow Status Values** (Job Orders):

| Workflow Status     | Badge Color | Icon         | Meaning                      |
|---------------------|-------------|--------------|------------------------------|
| Pending             | 🟡 Amber    | clock        | Job order created, not started|
| In Progress         | 🔵 Blue     | tools        | Work is ongoing               |
| Completed           | 🟢 Green    | check        | Job finished                  |
| Cancelled           | 🔴 Red      | times        | Job order cancelled           |

---

## 🔙 BACK BUTTON NAVIGATION MAP

### **Staff Navigation**:
```
Staff Dashboard
    ↓
[Transactions Hub]
    ↓
Transaction Form ──[Back]──→ Job Order Tracker
    ↓
Job Order Tracker ──[Back]──→ Staff Dashboard
    ↓
Merchandise History ──[Back]──→ Transactions Hub
```

### **Manager Navigation**:
```
Manager Dashboard
    ↓
[Pending Transactions] ──[Back]──→ Manager Dashboard
    ↓
Transaction Details ──[Close]──→ Pending Transactions
    ↓
[Validated Transactions] ──[Back]──→ Manager Dashboard
    ↓
Export Dialog ──[Cancel]──→ Validated Transactions
```

### **Admin Navigation** (No Transaction Access):
```
Admin Dashboard
    ↓
[User Management] ──[Back]──→ Admin Dashboard
    ↓
[Staff Oversight] ──[Back]──→ Admin Dashboard
    ↓
[System Settings] ──[Back]──→ Admin Dashboard
```

**Note**: Admin has NO access to transaction-related pages.

---

## 📊 EXPORT FUNCTIONALITY SPECIFICATION

### **Backend File**: `backend/export_validated_transactions.php`

### **Supported Formats**:
1. **Excel (.xls)**
2. **CSV (.csv)**
3. **PDF (Print/Save)**

### **Export Parameters**:
- `format` - Required: 'excel', 'csv', or 'pdf'
- `search` - Optional: Filter by transaction ID or customer name
- `date_from` - Optional: Start date for filtering
- `date_to` - Optional: End date for filtering
- `station_id` - Automatic: Current user's station

### **Export Includes**:
- Transaction ID
- Customer Name
- Transaction Type (Merchandise / Job Order)
- Items/Service description
- Total Amount
- Payment Method
- Payment Status
- Date & Time
- Staff Name (who encoded)
- Validated By (manager name)

### **Export Button Layout** (Validated Transactions Page):

```
┌─────────────────────────────────────────────────────────┐
│  Validated Transactions                                 │
│  ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐          │
│  │ Excel  │ │  CSV   │ │  PDF   │ │  Back  │          │
│  │  🟢    │ │  🟢    │ │  🔴    │ │  ⚪    │          │
│  └────────┘ └────────┘ └────────┘ └────────┘          │
└─────────────────────────────────────────────────────────┘
```

**Button Properties**:
- Width: 110px
- Height: 36px
- Border-radius: 8px
- Font-size: 13px
- Icon size: 16px
- Gap: 8px between buttons

---

## ✅ AUTO-REFRESH BEHAVIOR

### **NO Manual Refresh Button**:
- ❌ Refresh button **REMOVED** from all transaction pages
- ✅ System auto-refreshes data every 30 seconds (dashboard widgets)
- ✅ Transaction lists update on status change (real-time via AJAX)
- ✅ Page reload returns to same tab/section (preserves state)

### **When Auto-Refresh Triggers**:
1. **Dashboard Widgets**: Every 30 seconds
2. **After Transaction Submit**: Immediate refresh
3. **After Manager Approval**: Redirect to pending list (refreshed)
4. **After Status Change**: AJAX update (no full page reload)

### **Manual Refresh Alternative**:
- User can press **F5** or browser refresh button
- System preserves current filter/search state via URL parameters
- Returns to same tab if using tab-based navigation

---

## 🎯 VALIDATION RULES

### **Staff Encoding Rules**:
- ✅ Customer name required (or default to "Walk-in")
- ✅ At least 1 item/service must be selected
- ✅ Payment method must be selected
- ✅ If partial payment: downpayment > 0 and < total amount
- ✅ If Credit/Utang: customer name REQUIRED (not Walk-in)

### **Manager Validation Rules**:
- ✅ Can only approve/reject transactions from own station
- ✅ Cannot modify transaction after approval (only adjust before)
- ✅ Rejection reason REQUIRED when rejecting
- ✅ Adjustment reason REQUIRED when adjusting
- ✅ Approved transactions immediately visible to staff

### **Admin Oversight Rules**:
- ✅ Can view transactions from ALL stations
- ✅ Cannot approve/reject (view-only for compliance)
- ✅ Can generate reports and exports
- ✅ Can flag variances for manager review

---

## 📁 KEY FILES

### **Staff Pages**:
- `public/staff_transactions_hub.php` - Main transaction encoding + Job Order Tracker
- `public/staff_dashboard.php` - Dashboard with transaction widgets

### **Manager Pages**:
- `public/pending_transactions.php` - Validation queue (Approve/Reject/Adjust)
- `public/manager_validated_transactions.php` - Post-approval view + Export

### **Admin Pages** (No Transaction Access):
- `public/admin_dashboard.php` - Main admin dashboard
- `public/admin_user_management.php` - User administration
- `public/admin_staff_oversight.php` - Staff monitoring
- ❌ NO transaction-related pages

### **Backend**:
- `backend/export_validated_transactions.php` - Export handler (Manager only)
- `backend/transaction_schema_fix.php` - Schema validation helpers

---

## 🔐 SECURITY & PERMISSIONS

### **Role-Based Access**:

| Feature                  | Staff | Manager | Admin |
|--------------------------|-------|---------|-------|
| Encode Transactions      | ✅    | ❌      | ❌    |
| View Job Order Tracker   | ✅    | ✅      | ❌    |
| Approve Transactions     | ❌    | ✅      | ❌    |
| View Validated Txns      | ✅*   | ✅      | ❌    |
| Export Reports           | ❌    | ✅      | ❌    |
| User Management          | ❌    | ❌      | ✅    |
| Staff Oversight          | ❌    | ❌      | ✅    |
| System Settings          | ❌    | ❌      | ✅    |

*Staff can only view their own station's validated transactions

**Admin Role**: System administration ONLY - NO transaction module access whatsoever

---

## 📌 FINALIZATION CHECKLIST

- ✅ Staff can encode transactions with all payment methods
- ✅ Staff can track transactions in Job Order Tracker
- ✅ Manager can approve/reject/adjust transactions
- ✅ Manager can export validated transactions (Excel/CSV/PDF)
- ❌ Admin has NO access to transaction module
- ❌ Admin has NO access to ANY financial transaction features
- ✅ Back buttons navigate correctly at all levels
- ✅ No manual Refresh button (system auto-refreshes)
- ✅ Export buttons are compact and color-coded
- ✅ Validation status badges display correctly
- ✅ Payment status tracked accurately
- ✅ Accounts Receivable updated for Credit/Utang transactions
- ✅ Audit trail maintained for all manager actions

---

## 🎉 STATUS: FINALIZED & READY FOR DEPLOYMENT

**Transaction Module Flow is now complete and production-ready!**

**Workflow**: Staff → Manager (Final Authority)  
**Admin Role**: System Administration ONLY - NO transaction access  

All navigation, export, and validation flows have been verified and documented.
