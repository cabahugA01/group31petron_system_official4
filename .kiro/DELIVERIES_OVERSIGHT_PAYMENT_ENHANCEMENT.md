# Admin Deliveries Oversight - Payment Computation Enhancement

## Overview
Enhanced the **Admin Deliveries Oversight** module with comprehensive payment computation, discrepancy handling (Partial/Damaged/Rejected), and supplier payment reporting for deliveries where suppliers don't have system accounts.

---

## 🎯 Key Enhancements

### 1. **Payment Computation System**
Auto-compute payable amounts based on actual received quantities, damaged items, and unit prices.

#### Formula:
```
Expected Amount = Expected Qty × Unit Price
Actual Amount = Actual Qty × Unit Price
Damaged Amount = Damaged Qty × Unit Price
PAYABLE AMOUNT = Actual Amount - Damaged Amount
```

#### Example Scenario:
- **PO**: 100 pcs × ₱50 = ₱5,000 (Expected)
- **Actual Received**: 80 pcs × ₱50 = ₱4,000
- **Damaged**: 5 pcs × ₱50 = ₱250
- **PAYABLE**: ₱4,000 - ₱250 = **₱3,750** ✅

---

## 📊 Database Schema Updates

### New Columns in `deliveries_oversight` Table:
```sql
ALTER TABLE deliveries_oversight ADD COLUMN unit_price DECIMAL(12,2) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN expected_quantity DECIMAL(12,3) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN actual_quantity DECIMAL(12,3) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN damaged_quantity DECIMAL(12,3) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN expected_amount DECIMAL(12,2) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN payable_amount DECIMAL(12,2) DEFAULT 0;
ALTER TABLE deliveries_oversight ADD COLUMN discrepancy_type ENUM('','Partial','Damaged','Rejected','Mixed') DEFAULT '';
```

**Auto-migration**: Columns are created automatically when page loads (safe for existing installations).

---

## 🏷️ New Status Types & Badges

### Status Indicators

| Status | Badge Color | Icon | Meaning |
|--------|-------------|------|---------|
| **Approved** | Green | ✓ | Manager validated, ready for Admin processing |
| **Partial Delivery** | Orange | 📦 | Kulang ang quantity (less than expected) |
| **Damaged Items** | Red | 🔨 | May guba/defective items |
| **Rejected Delivery** | Gray | ✕ | Wrong item/batch, rejected completely |
| **Mixed** | Orange | ⚠ | Combination of partial + damaged |

---

## 🔧 New Features

### 1. **Process Delivery Modal** (Payment Computation)

**Trigger**: Click **"Process"** button on Approved deliveries

**Fields**:
- **Expected Quantity** (readonly) - From PO/Staff encoding
- **Unit Price** (₱) - Required, manual input by Admin
- **Actual Received Quantity** - Required, actual qty delivered
- **Damaged/Defective Quantity** - Optional, defaults to 0
- **Discrepancy Type** - Dropdown:
  - None (Full Delivery)
  - Partial Delivery (Kulang ang qty)
  - Damaged Items (May guba)
  - Mixed (Kulang + Guba)
  - Rejected (Wrong item/batch)
- **Admin Remarks** - Required, explanation of delivery details

**Real-time Features**:
- ✅ **Auto-calculation** as you type unit price/quantities
- ✅ **Discrepancy detection** (highlights if actual < expected)
- ✅ **Auto-suggest** discrepancy type based on inputs
- ✅ **Payment summary** shows breakdown:
  - Expected Amount (PO)
  - Actual Received Amount
  - Less: Damaged Items (if any)
  - **PAYABLE AMOUNT** (green, bold)

**Actions**:
- **Approve & Compute Payment** - Saves computation, updates status
- **Approve & Print Report** - Saves + immediately prints payment report

---

### 2. **Payable Amount Column** (Table Display)

**Table Header**: Added **"Payable Amount"** column

**Display Logic**:
- If computed: Shows amount in **green bold** (₱4,750.00)
- If not computed: Shows "Not computed" in gray

**Purpose**: Quick visibility of which deliveries have been processed for payment

---

### 3. **Print Payment Report** (Supplier Communication)

**Trigger**: Click **"Print"** button on processed deliveries (with payable amount)

**Report Contents**:

#### Header Section
- Station name
- Delivery Reference #
- DR Number
- Supplier name
- Delivery date
- Product name
- Status badge

#### Discrepancy Alert (if applicable)
- Yellow warning box
- Shows discrepancy type
- Displays admin notes

#### Quantity & Payment Computation Table
| Description | Quantity | Unit Price | Amount |
|-------------|----------|------------|--------|
| Expected Quantity (PO) | 100 pcs | ₱50.00 | ₱5,000.00 |
| **Actual Received** | **80 pcs** | ₱50.00 | **₱4,000.00** |
| Less: Damaged Items | 5 pcs | ₱50.00 | -₱250.00 |
| **TOTAL PAYABLE** | | | **₱3,750.00** |

#### Admin Notes Section
- Full remarks from Admin
- Explanation of discrepancies

#### Processing Details
- Encoded by (Staff name)
- Processed by (Admin name)
- Processing date/time
- Report generation timestamp

#### Signature Section
Three columns:
1. **Supplier Representative** - Pre-filled with supplier name
2. **Station Admin** - Pre-filled with Admin name
3. **Finance Officer** - Blank line for signature

#### Footer Note
> "This report serves as the official basis for supplier payment. Suppliers without system accounts should contact station admin/finance via phone or in-person for payment arrangements."

**Auto-print**: Opens print dialog automatically when report loads

---

## 📞 Supplier Communication Flow

### Since Suppliers Have NO System Account:

#### Option 1: Phone Call
1. Admin processes delivery with payment computation
2. Admin calls supplier
3. Inform payable amount: *"Ang bayad nimo kay ₱3,750 based sa actual nadawat"*
4. Supplier can verify against their DR copy
5. Admin emails/faxes official report if needed

#### Option 2: Face-to-Face
1. Supplier arrives at station for payment
2. Admin prints payment report
3. Show printed report with:
   - Quantity breakdown
   - Damaged items deduction (if any)
   - Final payable amount
4. Both parties sign
5. Finance processes payment

#### Option 3: Email/Fax
1. Admin processes delivery
2. Print payment report to PDF
3. Email to supplier: `supplier@example.com`
4. Supplier acknowledges receipt
5. Payment arranged based on terms

---

## 🎨 UI/UX Improvements

### Action Buttons (Per Delivery Row)

**Before Processing** (Status: Approved):
- 👁️ **View** - Blue button
- 🧮 **Process** - Dark blue button (NEW!)

**After Processing** (Payable amount computed):
- 👁️ **View** - Blue button
- 🖨️ **Print** - Purple button (NEW!)

### Payment Info Box (Inside Process Modal)
- **Green background** with border
- Shows **4 rows**:
  1. Expected Amount (PO)
  2. Actual Received (breakdown: qty × price = amount)
  3. Less: Damaged Items (only if damaged > 0)
  4. **PAYABLE AMOUNT** (large, bold, green)

### Discrepancy Alert (Inside Process Modal)
- **Yellow background** with border
- Shows automatically if:
  - Actual < Expected, OR
  - Damaged > 0
- Dynamic message:
  - *"Partial Delivery: 20 units short (Expected: 100, Received: 80)"*
  - *"Damaged Items: 5 units damaged/unusable"*

---

## 🔐 Access Control & Validation

### Frontend Validation
- ✅ Unit price must be > 0
- ✅ Actual quantity must be > 0
- ✅ Damaged quantity cannot exceed actual quantity
- ✅ Remarks are required (cannot be empty)

### Backend Validation
- ✅ Admin/SuperAdmin role required
- ✅ Delivery must exist and belong to admin's station
- ✅ All required fields validated
- ✅ Numeric fields validated as proper numbers

### Status Progression Guard
- Only **Approved** deliveries can be processed
- After processing, status changes to:
  - `Validated` (if no discrepancy)
  - `Partial Delivery` (if actual < expected)
  - `Damaged Items` (if damaged > 0)
  - `Rejected Delivery` (if marked as rejected)

---

## 📝 Audit Trail

Every payment processing action is logged:

**Entry Example**:
```
Transaction ID: 123
Actor: Admin (John Doe)
Action: Process Delivery & Compute Payment
Old Value: Approved
New Value: Partial Delivery | Payable: ₱3,750.00
Timestamp: 2026-06-07 14:30:25
Entity Type: delivery
```

**Purpose**: Full transparency and accountability for all payment computations

---

## 🧪 Testing Scenarios

### Scenario 1: Full Delivery (No Discrepancy)
- **Expected**: 100 pcs @ ₱50 = ₱5,000
- **Actual**: 100 pcs @ ₱50 = ₱5,000
- **Damaged**: 0
- **Payable**: ₱5,000 ✅
- **Status**: Validated
- **Discrepancy Type**: None

### Scenario 2: Partial Delivery (Kulang)
- **Expected**: 100 pcs @ ₱50 = ₱5,000
- **Actual**: 80 pcs @ ₱50 = ₱4,000
- **Damaged**: 0
- **Payable**: ₱4,000 ⚠️
- **Status**: Partial Delivery
- **Discrepancy Type**: Partial
- **Alert**: "20 units short"

### Scenario 3: Damaged Items (May Guba)
- **Expected**: 100 pcs @ ₱50 = ₱5,000
- **Actual**: 100 pcs @ ₱50 = ₱5,000
- **Damaged**: 10 pcs @ ₱50 = ₱500
- **Payable**: ₱4,500 ⚠️
- **Status**: Damaged Items
- **Discrepancy Type**: Damaged
- **Alert**: "10 units damaged/unusable"

### Scenario 4: Mixed (Kulang + Guba)
- **Expected**: 100 pcs @ ₱50 = ₱5,000
- **Actual**: 80 pcs @ ₱50 = ₱4,000
- **Damaged**: 5 pcs @ ₱50 = ₱250
- **Payable**: ₱3,750 ⚠️⚠️
- **Status**: Partial Delivery
- **Discrepancy Type**: Mixed
- **Alert**: "20 units short + 5 units damaged"

### Scenario 5: Rejected (Wrong Item/Batch)
- **Expected**: Product A, 100 pcs
- **Actual**: Wrong product delivered
- **Payable**: ₱0.00 ❌
- **Status**: Rejected Delivery
- **Discrepancy Type**: Rejected
- **Remarks**: "Wrong batch ID: Expected XCS-1422, received XCS-1423"

---

## 🎓 User Guide for Admin

### How to Process a Delivery & Compute Payment

1. **Navigate** to Deliveries Oversight page
2. **Filter** for "Approved" status deliveries
3. **Click "Process"** button on a delivery row
4. **Review** delivery info (auto-loaded from database)
5. **Enter Unit Price** (₱) - e.g., 50.00
6. **Enter Actual Received Quantity** - e.g., 80 (if kulang)
7. **Enter Damaged Quantity** (if may guba) - e.g., 5
8. **Watch** payment computation update in real-time:
   - Expected: ₱5,000
   - Actual: 80 × ₱50 = ₱4,000
   - Less Damaged: 5 × ₱50 = -₱250
   - **PAYABLE: ₱3,750** ✅
9. **Select Discrepancy Type** (auto-suggested):
   - Partial (if kulang)
   - Damaged (if guba)
   - Mixed (kulang + guba)
   - Rejected (wrong item)
10. **Enter Admin Remarks** - Explain the situation:
    - *"Partial delivery: Only 80 pcs received out of 100 pcs ordered. Damaged items: 5 pcs broken/unusable."*
11. **Click "Approve & Print Report"** to save and print immediately
    - OR **"Approve & Compute Payment"** to save without printing

### How to Print Payment Report for Supplier

1. **Find** the processed delivery (look for green payable amount)
2. **Click "Print"** button
3. **Review** the generated report:
   - Delivery details
   - Quantity breakdown
   - Payment computation
   - Admin notes
4. **Print** for supplier signature
5. **Communicate** with supplier:
   - **Phone**: Call and inform payable amount
   - **In-person**: Show printed report, both sign
   - **Email**: PDF and send to supplier

### How to Handle Supplier Questions

**Supplier asks: "Why is my payment less than PO amount?"**

**Admin response**:
- "Tan-awa ni ang report, sir/ma'am"
- "Ang PO kay 100 pcs × ₱50 = ₱5,000"
- "Pero ang actual nadawat ra namo kay 80 pcs"
- "Ug naay 5 pcs nga guba"
- "So ang payable nimo kay: 80 pcs × ₱50 = ₱4,000 minus ₱250 (damaged) = **₱3,750**"
- "Naa ni sa report with admin signature"

---

## 📋 API Endpoints

### `POST ?action=process_delivery`

**Request Body**:
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
  "remarks": "Partial delivery: Only 80 pcs received. 5 pcs damaged."
}
```

**Response**:
```json
{
  "success": true,
  "message": "Delivery processed successfully. Payable amount: ₱3,750.00 | Discrepancy: Mixed"
}
```

**Actions**:
1. Validates all inputs
2. Updates `deliveries_oversight` table with payment data
3. Changes status based on discrepancy type
4. Records admin action in `admin_id`, `admin_action_at`
5. Creates audit trail entry
6. Returns success message with payable amount

### `GET ?action=print_payment_report&id=123`

**Returns**: HTML payment report (auto-prints on load)

**Content**:
- Professional formatted report
- Station header
- Delivery details
- Quantity & payment table
- Admin notes
- Signature sections
- Footer with instructions

---

## 🔍 Differences from Previous Version

| Feature | Before | After |
|---------|--------|-------|
| **Payment Computation** | ❌ Manual/external | ✅ Auto-computed in system |
| **Discrepancy Statuses** | ❌ Generic "Flagged" only | ✅ Partial, Damaged, Rejected badges |
| **Unit Price** | ❌ Not stored | ✅ Stored per delivery |
| **Damaged Tracking** | ❌ Not tracked | ✅ Separate damaged quantity field |
| **Payable Amount** | ❌ Not computed | ✅ Auto-calculated & displayed |
| **Supplier Report** | ❌ No formal report | ✅ Printable payment report |
| **Real-time Calc** | ❌ N/A | ✅ Updates as you type |
| **Signature Section** | ❌ No formal sign-off | ✅ 3-party signature template |

---

## 🎯 Business Benefits

### For Admin:
- ✅ **Faster Processing** - No manual calculator needed
- ✅ **Accurate Computation** - System auto-calculates
- ✅ **Professional Reports** - Official printed documents
- ✅ **Audit Trail** - Every action logged
- ✅ **Discrepancy Tracking** - Clear status indicators

### For Suppliers:
- ✅ **Transparency** - See exact computation breakdown
- ✅ **Official Documentation** - Signed payment report
- ✅ **Dispute Resolution** - Clear audit trail if disagreement
- ✅ **No System Login Needed** - Works via phone/in-person

### For Station/Petron:
- ✅ **Accountability** - Full audit trail of payments
- ✅ **Compliance** - Proper documentation for all deliveries
- ✅ **Cost Control** - Only pay for actual goods received
- ✅ **Damage Recovery** - Track and deduct damaged items

---

## 📂 Files Modified

### Frontend:
- `public/admin_merchandise_deliveries_oversight.php`
  - Added payment columns to database schema (auto-migration)
  - Updated CSS with new badge styles (Partial, Damaged, Rejected)
  - Added "Payable Amount" column to table
  - Added "Process Delivery" modal with payment computation
  - Added real-time payment calculation JavaScript
  - Updated table row builder to show payment amounts
  - Added `openProcess()`, `recalcPayment()`, `submitProcess()`, `printDeliveryReport()` functions

### Backend API:
- `backend/api/admin_deliveries_oversight_api.php`
  - Added `process_delivery` action handler
  - Added `print_payment_report` action handler
  - Added payment computation logic
  - Added status update based on discrepancy type
  - Added audit trail logging for payment processing
  - Generated HTML payment report template

---

## 🚀 Deployment Checklist

- [x] Database columns auto-created on page load
- [x] CSS styles added for new badges
- [x] JavaScript payment computation functions
- [x] Backend API actions implemented
- [x] HTML payment report template
- [x] Audit trail logging
- [x] Access control validation
- [x] Frontend form validation
- [x] Real-time calculation
- [x] Auto-print functionality

**Status**: ✅ **READY FOR PRODUCTION**

---

**Date Implemented**: June 7, 2026  
**Module**: Admin Deliveries Oversight  
**Enhancement Type**: Payment Computation & Supplier Communication  
**User Request**: "ato na gyud tarungon ang Admin – Merchandise Deliveries Module ug i-klaro unsaon ang bayad kung supplier wala'y account sa system"
