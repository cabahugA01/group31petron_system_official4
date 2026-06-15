# Transaction Display Fixes - Implementation Summary

**Date:** June 15, 2026
**File Modified:** `public/staff_transactions_hub.php`

---

## 🎯 Issues Fixed

### 1. **Column Overlap Issue (STATUS & PAYMENT columns)**
   - **Problem:** Text "COMPLETED / PENDING PAYMENT" was overlapping between STATUS and PAYMENT columns
   - **Root Cause:** Insufficient column width + `white-space:nowrap` preventing text wrapping
   - **Solution Applied:**
     - Increased STATUS column width: 9% → 13%
     - Increased PAYMENT column width: 10% → 11%
     - Removed `white-space:nowrap` and added proper text wrapping CSS
     - Reduced font size from 12px to 11px for better fit
     - Added `word-break:break-word` and `overflow-wrap:break-word`

### 2. **Missing Product Column in Merchandise History**
   - **Problem:** No visibility of purchased products in Merchandise History table
   - **Solution Applied:**
     - Added new **PRODUCT** column (14% width)
     - Modified SQL query to fetch products from `merchandise_transaction_items` table
     - Format: "Product Name (Quantity), Product 2 (Quantity)"
     - Example: "Engine Oil (2), Air Filter (1)"

### 3. **Transaction Type Separation (Proper Filtering)**
   - **Problem:** Merchandise History was showing ALL transactions including Job Orders
   - **Requirement:** 
     - **Service + Merchandise** → Job Order Tracker ONLY
     - **Merchandise Only** → Merchandise History ONLY
   - **Solution Applied:**
     - Added filter: `COALESCE(mt.transaction_type, 'merchandise') = 'merchandise'`
     - Merchandise History now excludes `transaction_type IN ('job_order', 'combined')`

### 4. **Job Order Tracker - Products Display**
   - **Problem:** Items/Parts column was empty for merchandise transactions
   - **Solution Applied:**
     - Modified query to fetch merchandise items from `merchandise_transaction_items`
     - Added `GROUP_CONCAT` with product names and quantities
     - Format: "Product Name (Qty), Product Name 2 (Qty 2)"

---

## 📋 Code Changes Details

### A. Job Order Tracker Table (All Job Orders)

**Location:** Line ~5477

**Column Width Adjustments:**
```php
// BEFORE:
<col style="width:6%;"  ><!-- JO ID -->
<col style="width:9%;"  ><!-- Customer -->
<col style="width:14%;"><!-- Vehicle / Service -->
<col style="width:10%;"><!-- Items / Parts -->
<col style="width:9%;" ><!-- Mechanic -->
<col style="width:9%;" ><!-- Workflow Status -->
<col style="width:10%;"><!-- Payment Status -->
<col style="width:8%;" ><!-- Remarks -->
<col style="width:9%;" ><!-- Date/Time -->
<col style="width:16%;"><!-- Actions -->

// AFTER:
<col style="width:5%;"  ><!-- JO ID -->
<col style="width:8%;"  ><!-- Customer -->
<col style="width:13%;"><!-- Vehicle / Service -->
<col style="width:9%;"><!-- Items / Parts -->
<col style="width:8%;" ><!-- Mechanic -->
<col style="width:13%;" ><!-- Workflow Status (INCREASED) -->
<col style="width:11%;"><!-- Payment Status (INCREASED) -->
<col style="width:7%;" ><!-- Remarks -->
<col style="width:8%;" ><!-- Date/Time -->
<col style="width:18%;"><!-- Actions (INCREASED) -->
```

**Text Wrapping Fix:**
```php
// BEFORE:
<td>
    <span style="color:<?= $wf_color ?>;font-size:12px;font-weight:600;white-space:nowrap;">
        <?= $wf_label ?>
    </span>
</td>

// AFTER:
<td style="word-break:break-word;overflow-wrap:break-word;">
    <span style="color:<?= $wf_color ?>;font-size:11px;font-weight:600;line-height:1.3;">
        <?= $wf_label ?>
    </span>
</td>
```

---

### B. Merchandise History Table

**Location:** Line ~3414

**Added PRODUCT Column:**
```php
// Column Structure:
<col style="width:7%;"><!-- Txn ID -->
<col style="width:10%;"><!-- Customer -->
<col style="width:14%;"><!-- Product (NEW) -->
<col style="width:6%;"><!-- Total -->
<col style="width:6%;"><!-- Method -->
<col style="width:7%;"><!-- Balance Due -->
<col style="width:10%;"><!-- Shift -->
<col style="width:10%;"><!-- Date -->
<col style="width:12%;"><!-- Payment Status -->
<col style="width:18%;"><!-- Actions -->
```

**Query Modification (Line ~300):**
```sql
-- Added to SELECT statement:
COALESCE(
    NULLIF(
        (SELECT GROUP_CONCAT(CONCAT(i.product_name, ' (', i.quantity, ')') 
                             ORDER BY i.id SEPARATOR ', ')
         FROM merchandise_transaction_items i 
         WHERE i.transaction_id = mt.id), ''
    ),
    '—'
) AS products
```

**Table Display:**
```php
<?php
    $mh_products = $txn['products'] ?? '—';
?>
<td style="font-size:12px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" 
    title="<?= htmlspecialchars($mh_products) ?>">
    <?= htmlspecialchars($mh_products) ?>
</td>
```

---

### C. Transaction Type Filtering

**Location:** Line ~293

**Merchandise History Filter:**
```php
// BEFORE:
$mh_where = "WHERE mt.station_id = ? AND mt.staff_id = ?";

// AFTER:
$mh_where = "WHERE mt.station_id = ? AND mt.staff_id = ? 
             AND COALESCE(mt.transaction_type, 'merchandise') = 'merchandise'";
```

**Job Order Tracker Query (Line ~625):**
```sql
-- Part 2: merchandise_transactions with job_order/combined type
WHERE mt.station_id = ?
  AND mt.transaction_type IN ('job_order', 'combined')
```

---

### D. Merchandise Items in Job Orders

**Location:** Line ~625

**Query Enhancement:**
```sql
-- Added to SELECT for merchandise_transactions:
COALESCE(
    NULLIF(
        (SELECT GROUP_CONCAT(CONCAT(i.product_name, ' (', i.quantity, ')') 
                             ORDER BY i.id SEPARATOR ', ')
         FROM merchandise_transaction_items i 
         WHERE i.transaction_id = mt.id), ''
    ),
    NULL
) AS required_parts
```

---

## 🔍 Transaction Flow Verification

### **Staff View (staff_transactions_hub.php):**

#### Merchandise History Tab
- **Shows:** Pure merchandise transactions only
- **Filter:** `transaction_type = 'merchandise'`
- **Columns:** Txn ID, Customer, **Product**, Total, Method, Balance, Shift, Date, Payment Status, Actions
- **Products Display:** "Engine Oil (2), Brake Fluid (1)"

#### Job Order Tracker Tab
- **Shows:** Job orders + combined transactions
- **Filter:** `transaction_type IN ('job_order', 'combined')`
- **Columns:** JO ID, Customer, Vehicle/Service, **Items/Parts**, Mechanic, Status, Payment, Remarks, Date, Actions
- **Items Display:** Shows both service parts and merchandise items

### **Manager View (pending_transactions.php):**
- **Shows:** ALL pending transactions (unified view)
- **Type Column:** Displays "Merchandise", "Job Order", or "JO + Merchandise"
- **Filter:** `validation_status = 'pending'` (no transaction_type filter needed)
- ✅ Already properly configured

### **Admin View (admin_transactions_oversight.php):**
- **Shows:** ALL approved/completed transactions
- **Type Detection:** Auto-detects based on items and service presence
- **Filter:** `validation_status IN ('approved', 'completed')`
- ✅ Already properly configured

---

## ✅ Testing Checklist

- [x] Job Order Tracker: STATUS and PAYMENT columns no longer overlap
- [x] Job Order Tracker: Long status text wraps properly
- [x] Merchandise History: PRODUCT column displays items correctly
- [x] Merchandise History: Only shows pure merchandise transactions
- [x] Job Order Tracker: Shows service + merchandise transactions
- [x] Job Order Tracker: Displays merchandise items in Items/Parts column
- [x] Manager view: Shows all pending transactions with proper type labels
- [x] Admin view: Shows all validated transactions with auto-detection

---

## 📊 Business Rules Implemented

### Transaction Type Matrix

| **Transaction Type** | **Staff - Merch History** | **Staff - JO Tracker** | **Manager View** | **Admin View** |
|---------------------|---------------------------|------------------------|------------------|----------------|
| Pure Merchandise    | ✅ Shows                   | ❌ Hidden              | ✅ Shows (labeled) | ✅ Shows (auto-detect) |
| Job Order Only      | ❌ Hidden                  | ✅ Shows               | ✅ Shows (labeled) | ✅ Shows (auto-detect) |
| JO + Merchandise    | ❌ Hidden                  | ✅ Shows               | ✅ Shows (labeled) | ✅ Shows (auto-detect) |

### Stock Deduction Rules
- ✅ All merchandise items automatically deduct from inventory
- ✅ Deduction happens regardless of transaction type
- ✅ Service-only transactions don't trigger inventory deduction

---

## 🔧 Database Schema Dependencies

### Tables Used:
1. **merchandise_transactions**
   - Columns: `id`, `transaction_id`, `customer_name`, `total_amount`, `transaction_type`, `payment_status`, etc.
   
2. **merchandise_transaction_items**
   - Columns: `transaction_id`, `product_name`, `quantity`, `item_type`
   - Links items to transactions (1-to-many relationship)

3. **job_orders**
   - Columns: `id`, `job_order_id`, `customer_name`, `service_type`, `required_parts`, etc.

### Key Columns:
- **transaction_type**: `'merchandise'` | `'job_order'` | `'combined'`
- **validation_status**: `'pending'` | `'approved'` | `'completed'` | `'rejected'`
- **payment_status**: `'Pending Payment'` | `'Partial Payment'` | `'Paid'` | `'Credit Transaction'`

---

## 📝 Notes

1. All column width percentages must sum to 100% for `table-layout:fixed`
2. Text wrapping enabled for long status labels prevents overflow
3. Product display uses tooltip for full text when truncated
4. Transaction type filtering ensures no duplication between views
5. Manager and Admin views intentionally show all types for oversight purposes

---

## 🚀 Future Enhancements (Optional)

- [ ] Add export functionality for Merchandise History with Product column
- [ ] Add search/filter by product name
- [ ] Add product category grouping in display
- [ ] Add product image thumbnails (if product images are available)
- [ ] Add quantity summary (total items per transaction)

---

**Status:** ✅ All fixes successfully implemented and tested
**Impact:** Staff, Manager, and Admin transaction views now properly segregated and displayed
