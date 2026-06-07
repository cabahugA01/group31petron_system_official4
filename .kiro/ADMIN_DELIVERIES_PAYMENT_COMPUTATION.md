# Admin Deliveries Oversight — Payment Computation Enhancement

## Overview
Enhanced the **Admin Deliveries Oversight** module to include **automated payment computation** for supplier payments, **discrepancy handling** (Partial, Damaged, Rejected), and **printable payment reports** for phone/face-to-face supplier communication.

---

## 🎯 Key Features Added

### 1. **Payment Computation System**
- ✅ Auto-compute **payable amount** based on actual received items
- ✅ Deduct damaged/defective items from payment
- ✅ Display **Expected vs Actual vs Payable** breakdown
- ✅ Real-time calculation as Admin enters quantities

### 2. **Discrepancy Types**
- **Partial Delivery** → Kulang ang quantity
- **Damaged Items** → May guba/unusable
- **Rejected Delivery** → Wrong item/batch
- **Mixed** → Both partial + damaged

### 3. **Supplier Communication**
Since suppliers have **no account** in the system:
- 📞 **Phone call** → Admin verbally communicates payable amount
- 🤝 **Face-to-face** → Print payment report and give to supplier
- 📄 **Printed report** = Official basis for payment

---

## 📊 Payment Computation Logic

### Formula:
```
Expected Amount  = Expected Quantity × Unit Price
Actual Amount    = Actual Received Quantity × Unit Price
Damaged Amount   = Damaged Quantity × Unit Price
PAYABLE AMOUNT   = Actual Amount - Damaged Amount
```

### Example:
```
PO: 100 pcs × ₱50 = ₱5,000 (Expected)

Actual Delivery:
- Received: 80 pcs × ₱50 = ₱4,000
- Damaged: 5 pcs × ₱50 = ₱250
- PAYABLE: ₱4,000 - ₱250 = ₱3,750

Discrepancy:
- Type: Mixed (Partial + Damaged)
- Remarks: "Only 80 pcs received (20 short). 5 pcs broken/unusable."
```

---

## 🗄️ Database Changes

### New Columns Added to `deliveries_oversight`:
```sql
ALTER TABLE deliveries_oversight ADD COLUMN unit_price DECIMAL(12,2) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN expected_quantity DECIMAL(12,3) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN actual_quantity DECIMAL(12,3) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN damaged_quantity DECIMAL(12,3) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN expected_amount DECIMAL(12,2) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN payable_amount DECIMAL(12,2) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN discrepancy_type ENUM('','Partial','Damaged','Rejected','Mixed') DEFAULT '';
```

### Purpose:
- **unit_price** → Price per unit (entered by Admin)
- **expected_quantity** → Original PO/Staff quantity
- **actual_quantity** → Actually received quantity
- **damaged_quantity** → Damaged/defective items
- **expected_amount** → Expected payment (PO amount)
- **payable_amount** → Final amount to pay supplier
- **discrepancy_type** → Type of delivery issue

---

## 🎨 UI/UX Changes

### 1. **Updated Table Columns**
| Before | After |
|---|---|
| Delivery ID, Type, DR#, Supplier, Product, Qty, Date, Encoded By, Status, Remarks, Actions | Delivery ID, Type, DR#, Supplier, Product, Qty, **Payable Amount**, Date, Status, Actions |

**Payable Amount Column**:
- Shows computed payment if processed
- Shows "Not computed" if not yet processed
- Color-coded: Green for computed amounts

### 2. **New Action Buttons**
- 🧮 **Process** → Opens payment computation modal (for Approved deliveries)
- 🖨️ **Print** → Opens printable payment report (if already processed)

### 3. **Process Delivery Modal**
**Sections**:
1. **Delivery Info** (read-only reference)
   - Delivery Ref, DR Number, Supplier, Product, Unit, Date
   
2. **Payment Form**:
   - Expected Quantity (read-only, from PO/Staff)
   - **Unit Price** (₱) ← Required input
   - **Actual Received Quantity** ← Required input
   - **Damaged Quantity** ← Optional input
   - **Discrepancy Type** dropdown
   - **Admin Remarks** ← Required

3. **Payment Summary** (auto-updates):
   ```
   Expected Amount (PO):       ₱5,000.00
   Actual Received:  80 × ₱50 = ₱4,000.00
   Less: Damaged:     5 × ₱50 = -₱250.00
   ─────────────────────────────────────
   PAYABLE AMOUNT:             ₱3,750.00
   ```

4. **Discrepancy Alert** (shows if detected):
   - "Partial Delivery: 20.00 units short"
   - "Damaged Items: 5.00 units damaged/unusable"

5. **Action Buttons**:
   - ✅ **Approve & Compute Payment** → Save and close
   - 🖨️ **Approve & Print Report** → Save and open print window

### 4. **Status Badges**
New badges added:
- **Partial** → Orange badge with box-open icon
- **Damaged** → Red badge with hammer icon
- **Rejected** → Gray badge with times-circle icon

---

## 📄 Printable Payment Report

### Report Sections:
1. **Header**
   - "DELIVERY PAYMENT REPORT"
   - Station name and generation timestamp

2. **Delivery Information**
   - Delivery Ref, DR Number, Supplier, Date
   - Product, Encoded By

3. **Payment Computation Table**
   | Description | Quantity | Unit Price | Amount |
   |---|---|---|---|
   | Expected Quantity | 100 pcs | ₱50.00 | ₱5,000.00 |
   | **Actual Received** | **80 pcs** | **₱50.00** | **₱4,000.00** |
   | Less: Damaged | -5 pcs | ₱50.00 | -₱250.00 |
   | **TOTAL PAYABLE** | | | **₱3,750.00** |

4. **Admin Remarks** (if any)
   - Shows discrepancy explanation

5. **Authorization**
   - Processed By (Admin name)
   - Processing Date and Time

6. **Footer Note**
   - "This is an official payment report for supplier communication"

### Report Features:
- **Auto-prints** on window load
- **Print-friendly** CSS (removes buttons when printing)
- **Professional formatting** with Petron branding colors
- **Discrepancy badges** if applicable

---

## 🔄 Workflow

### Full Process Flow:
```
1. STAFF encodes delivery → Status: "Pending Manager Approval"
   ↓
2. MANAGER validates → Status: "Approved" / "Confirmed"
   ↓
3. ADMIN processes delivery:
   a. Click "Process" button
   b. Enter unit price
   c. Enter actual received quantity
   d. Enter damaged quantity (if any)
   e. Select discrepancy type
   f. Enter remarks explaining situation
   g. Click "Approve & Compute Payment"
   ↓
4. System computes payable amount
   ↓
5. Status updated:
   - No discrepancy → "Validated"
   - Partial → "Partial Delivery"
   - Damaged → "Damaged Items"
   - Rejected → "Rejected Delivery"
   ↓
6. ADMIN communicates payment to supplier:
   Option A: Phone call (verbal communication)
   Option B: Print report and give face-to-face
   ↓
7. SUPPLIER receives payment details
```

---

## 🛠️ API Changes

### New Actions:

#### 1. `process_delivery` (POST)
**Parameters**:
```json
{
  "id": 123,
  "expected_quantity": 100.00,
  "actual_quantity": 80.00,
  "damaged_quantity": 5.00,
  "unit_price": 50.00,
  "expected_amount": 5000.00,
  "payable_amount": 3750.00,
  "discrepancy_type": "Mixed",
  "remarks": "Only 80 pcs received. 5 pcs damaged."
}
```

**Actions**:
- Updates delivery record with payment data
- Sets status based on discrepancy type
- Records admin action timestamp
- Creates audit trail entry

**Response**:
```json
{
  "success": true,
  "message": "Delivery processed successfully. Payable amount: ₱3,750.00 (Discrepancy type: Mixed)"
}
```

#### 2. `print_payment_report` (GET)
**Parameters**: `id` (delivery ID)

**Returns**: HTML document (printable payment report)

**Features**:
- Professional formatted report
- All payment computation details
- Admin remarks and authorization
- Auto-prints on load

---

## 📝 JavaScript Functions Added

### 1. `openProcess(id)`
- Fetches delivery details
- Pre-fills form with delivery data
- Opens process modal

### 2. `recalcPayment()`
- Real-time payment calculation
- Updates payment summary display
- Shows/hides damaged row
- Displays discrepancy alerts
- Auto-suggests discrepancy type

### 3. `submitProcess(mode)`
- Validates form inputs
- Sends data to API
- Handles success/error responses
- Opens print window if mode='print'

### 4. `printDeliveryReport(id)`
- Opens payment report in new window
- Triggers browser print dialog

---

## 🎓 User Instructions (Admin)

### How to Process a Delivery with Payment Computation:

1. **Open Deliveries Oversight**
   - Navigate from sidebar → "Deliveries Oversight"

2. **Find Approved Delivery**
   - Look for deliveries with status **"Approved"** (green badge)
   - These are Manager-validated deliveries ready for Admin processing

3. **Click "Process" Button**
   - Opens payment computation modal

4. **Enter Payment Details**:
   - **Unit Price (₱)** → Enter price per unit (required)
   - **Actual Received Qty** → Enter actually received quantity
   - **Damaged Qty** → Enter damaged/unusable items (if any)
   - System auto-calculates payable amount in real-time

5. **Select Discrepancy Type** (if applicable):
   - **None** → Full delivery, no issues
   - **Partial** → Kulang ang quantity
   - **Damaged** → May guba
   - **Mixed** → Both kulang + guba
   - **Rejected** → Wrong item/batch

6. **Enter Remarks** (required):
   - Explain the delivery details
   - Example: "Only 80 pcs received out of 100 ordered. 5 pcs broken/unusable."

7. **Review Payment Summary**:
   - Check Expected Amount
   - Check Actual Amount
   - Check Damaged deduction (if any)
   - Verify PAYABLE AMOUNT

8. **Choose Action**:
   - **"Approve & Compute Payment"** → Save and close
   - **"Approve & Print Report"** → Save and open printable report

9. **Communicate with Supplier**:
   - **Option A**: Call supplier and inform payable amount
   - **Option B**: Print report and give face-to-face

---

## 🧪 Testing Scenarios

### Scenario 1: Full Delivery (No Discrepancy)
- Expected: 100 pcs × ₱50 = ₱5,000
- Actual: 100 pcs × ₱50 = ₱5,000
- Damaged: 0
- **Payable: ₱5,000**
- Type: None
- Status: "Validated"

### Scenario 2: Partial Delivery
- Expected: 100 pcs × ₱50 = ₱5,000
- Actual: 80 pcs × ₱50 = ₱4,000
- Damaged: 0
- **Payable: ₱4,000**
- Type: Partial
- Status: "Partial Delivery"

### Scenario 3: Damaged Items
- Expected: 100 pcs × ₱50 = ₱5,000
- Actual: 100 pcs × ₱50 = ₱5,000
- Damaged: 10 pcs × ₱50 = ₱500
- **Payable: ₱4,500**
- Type: Damaged
- Status: "Damaged Items"

### Scenario 4: Mixed Discrepancy
- Expected: 100 pcs × ₱50 = ₱5,000
- Actual: 80 pcs × ₱50 = ₱4,000
- Damaged: 5 pcs × ₱50 = ₱250
- **Payable: ₱3,750**
- Type: Mixed
- Status: "Partial Delivery"

### Scenario 5: Rejected Delivery
- Expected: 100 pcs "Product A" × ₱50 = ₱5,000
- Actual: 100 pcs "Product B" (wrong item)
- **Payable: ₱0** (or actual if accepting wrong product)
- Type: Rejected
- Status: "Rejected Delivery"

---

## 🔒 Business Rules

1. **Payable amount = Actual received - Damaged**
   - Only usable items are paid for
   - Damaged items deducted from payment

2. **Suppliers have no system account**
   - Cannot log in to view deliveries
   - Admin must communicate payment externally

3. **Printed report is official basis**
   - Legal document for payment
   - Includes Admin authorization

4. **Discrepancy types affect status**
   - Changes delivery status for tracking
   - Appears in audit trail

5. **Admin remarks are required**
   - Transparency and documentation
   - Explains any discrepancies

---

## 📊 Audit Trail

Every processed delivery creates an audit trail entry:
```
Action: "Process Delivery"
Old Value: "Approved"
New Value: "Partial Delivery | Payable: ₱3,750.00"
Actor: Admin Name
Timestamp: June 7, 2026 3:45 PM
```

This ensures **full transparency** and **accountability**.

---

## 🚀 Benefits

### For Admin:
- ✅ **Automated calculations** → No manual math errors
- ✅ **Clear discrepancy tracking** → Know exactly what happened
- ✅ **Professional reports** → Credible supplier communication
- ✅ **Audit trail** → Full accountability

### For Suppliers:
- ✅ **Clear payment breakdown** → Understand what they're being paid for
- ✅ **Professional documentation** → Official payment report
- ✅ **Transparent deductions** → See why damaged items were deducted

### For Business:
- ✅ **Accurate payments** → Pay only for usable items
- ✅ **Documented discrepancies** → Legal protection
- ✅ **Financial accuracy** → Correct accounting records
- ✅ **Supplier relations** → Professional communication

---

## 📌 Summary

The enhanced **Deliveries Oversight** module now provides:
1. ✅ **Auto-computed payable amounts** with damaged item deductions
2. ✅ **Discrepancy handling** (Partial, Damaged, Rejected, Mixed)
3. ✅ **Printable payment reports** for supplier communication
4. ✅ **Real-time calculation** as Admin enters data
5. ✅ **Professional UI/UX** with payment summary displays
6. ✅ **Full audit trail** for accountability

**Status**: ✅ **Fully Implemented and Tested**

---

**Date**: June 7, 2026  
**Module**: Admin Deliveries Oversight → Payment Computation  
**Enhancement**: Payment automation for supplier communication (phone/face-to-face)  
**Developer Notes**: Since suppliers have no system account, Admin prints reports for physical delivery or communicates amounts via phone.
