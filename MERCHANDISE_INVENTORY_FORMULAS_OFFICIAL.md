# MERCHANDISE INVENTORY FORMULAS - OFFICIAL REFERENCE

**System:** Petron Station Management System  
**Date:** June 28, 2026  
**Module:** Merchandise Inventory Management

---

## 📦 OFFICIAL FORMULAS

### 1. Stock-In (Verified Delivery)

**Formula:**
```
New Stock = Previous Stock + Verified Delivered Quantity
```

**Example:**
- Previous Stock = 50
- Delivery = 20
- **New Stock = 70**

**Database Implementation:**
```sql
UPDATE inventory_products
SET current_stock = current_stock + delivered_quantity,
    last_updated = NOW()
WHERE product_id = ? AND station_id = ?;
```

**Business Rule:**
- Stock ONLY updates after **Manager Verification**
- Delivery status must be `Verified` or `Approved`
- Records stored in `deliveries_oversight` or `received_items` tables

---

### 2. Merchandise Sales

**Formula:**
```
Remaining Stock = Previous Stock - Quantity Sold
```

**Example:**
- Current Stock = 70
- Customer bought = 5
- **Remaining Stock = 65**

**Database Implementation:**
```sql
UPDATE inventory_products
SET current_stock = GREATEST(0, current_stock - quantity_sold),
    last_updated = NOW()
WHERE product_id = ? AND station_id = ?;
```

**Business Rule:**
- Stock deduction happens during **successful transaction**
- Transaction must be completed (payment received)
- Use `GREATEST(0, ...)` to prevent negative stock
- Records stored in `sales_transactions` or `pos_transactions` tables

---

### 3. Inventory Adjustment

**Formula:**
```
Remaining Stock = Current Stock ± Adjustment Quantity
```

**Example (Damaged):**
- Current Stock = 65
- Damaged = 2
- **Remaining Stock = 63** (subtract)

**Example (Correction):**
- Current Stock = 63
- Found Missing Item = +3
- **Remaining Stock = 66** (add)

**Database Implementation:**
```sql
-- For damaged/lost (negative adjustment)
UPDATE inventory_products
SET current_stock = GREATEST(0, current_stock - adjustment_quantity),
    last_updated = NOW()
WHERE product_id = ? AND station_id = ?;

-- For found/correction (positive adjustment)
UPDATE inventory_products
SET current_stock = current_stock + adjustment_quantity,
    last_updated = NOW()
WHERE product_id = ? AND station_id = ?;
```

**Business Rule:**
- Adjustment types: Damaged, Expired, Lost, Correction, Found
- Requires manager approval
- Must include reason/notes
- Records stored in `inventory_adjustments` table

---

### 4. Master Merchandise Inventory Formula

**Formula:**
```
Current Stock = Previous Stock + Verified Deliveries - Sales Quantity ± Inventory Adjustments
```

**Breakdown:**
- **Previous Stock**: Starting stock level
- **Verified Deliveries**: Sum of all manager-verified deliveries
- **Sales Quantity**: Sum of all completed sales transactions
- **Inventory Adjustments**: Sum of approved adjustments (positive or negative)

**Database Implementation:**
```sql
SELECT 
    ip.current_stock,
    COALESCE(SUM(CASE WHEN do.status = 'Verified' THEN do.quantity ELSE 0 END), 0) as total_deliveries,
    COALESCE(SUM(st.quantity), 0) as total_sales,
    COALESCE(SUM(ia.adjustment_quantity), 0) as total_adjustments
FROM inventory_products ip
LEFT JOIN deliveries_oversight do ON ip.product_id = do.product_id
LEFT JOIN sales_transactions st ON ip.product_id = st.product_id
LEFT JOIN inventory_adjustments ia ON ip.product_id = ia.product_id
WHERE ip.station_id = ?
GROUP BY ip.product_id;
```

**Validation:**
```sql
-- Verify formula accuracy
calculated_stock = previous_stock + verified_deliveries - sales + adjustments
variance = current_stock - calculated_stock
-- Variance should be 0 or minimal (< 0.01)
```

---

### 5. Merchandise Sales Amount Formula

**Formula:**
```
Sales Amount = Quantity Sold × Unit Selling Price
```

**Example:**
- Quantity = 5
- Price = ₱320
- **Sales = 5 × 320 = ₱1,600**

**Database Implementation:**
```sql
-- Calculate sales amount
total_amount = quantity_sold * unit_price;

-- Store in transaction
INSERT INTO sales_transactions (product_id, quantity, unit_price, total_amount, ...)
VALUES (?, ?, ?, ?, ...);
```

**Business Rule:**
- Price fetched from `inventory_products.price` or `inventory_products.cost`
- May include discount calculation
- VAT/tax calculation may apply
- Records stored in `sales_transactions` or `pos_sales` tables

---

## 📦 COMPLETE WORKFLOW

```
1. Admin Creates Purchase Order
   └─> Status: Pending
   
2. Supplier Delivers
   └─> Delivery arrives at station
   
3. Staff Records Delivery
   ├─> Records to: deliveries_oversight / received_items
   ├─> Status: Pending Manager Approval
   └─> NO STOCK UPDATE YET
   
4. Manager Verifies Delivery
   ├─> Reviews quantity, quality, invoice
   ├─> Status: Verified / Approved
   └─> ✓ STOCK UPDATE HAPPENS HERE
       UPDATE inventory_products
       SET current_stock = current_stock + delivered_quantity
   
5. Customer Purchase
   ├─> Staff processes sale
   ├─> Status: Completed
   └─> ✓ STOCK DEDUCTION HAPPENS HERE
       UPDATE inventory_products
       SET current_stock = current_stock - quantity_sold
   
6. If Needed: Inventory Adjustment
   ├─> Manager creates adjustment (Damaged/Lost/Found)
   ├─> Status: Approved
   └─> ✓ STOCK ADJUSTMENT HAPPENS HERE
       UPDATE inventory_products
       SET current_stock = current_stock ± adjustment_quantity
   
7. Inventory History
   └─> All changes logged in audit tables
       - deliveries_oversight
       - sales_transactions
       - inventory_adjustments
```

---

## 🔍 VALIDATION RULES

### Stock-In Validation:
- ✓ Delivery status must be `Verified` or `Approved`
- ✓ Quantity must be > 0
- ✓ Product must exist in `inventory_products`
- ✓ Manager approval required
- ✓ Invoice number required

### Sales Validation:
- ✓ Current stock must be >= quantity sold
- ✓ Cannot sell negative quantities
- ✓ Price must be > 0
- ✓ Transaction must be completed
- ✓ Use `GREATEST(0, ...)` to prevent negative stock

### Adjustment Validation:
- ✓ Adjustment reason required
- ✓ Manager approval required
- ✓ Adjustment type required (Damaged/Lost/Found/Correction)
- ✓ Cannot adjust to negative stock (for negative adjustments)
- ✓ Audit trail required

---

## 📊 EXAMPLE SCENARIOS

### Scenario 1: Normal Workflow

**Initial Stock:** 50 units

**Delivery Recorded by Staff:**
- Quantity: 20 units
- Status: `Pending Manager Approval`
- Stock: **50** (unchanged)

**Manager Verifies Delivery:**
- Status → `Verified`
- **Stock Update:** 50 + 20 = **70 units**

**Customer Purchase:**
- Quantity: 5 units
- **Stock Update:** 70 - 5 = **65 units**

**Damaged Items:**
- Quantity: 2 units (damaged)
- **Stock Update:** 65 - 2 = **63 units**

**Found Missing:**
- Quantity: 3 units (found during audit)
- **Stock Update:** 63 + 3 = **66 units**

**Final Stock:** 66 units

**Verification:**
```
Current Stock = Previous + Deliveries - Sales ± Adjustments
66 = 50 + 20 - 5 - 2 + 3
66 = 66 ✓ CORRECT
```

---

### Scenario 2: Multiple Deliveries

**Initial Stock:** 100 units

**Delivery 1 (Verified):** +50 → Stock: **150**  
**Sales 1:** -30 → Stock: **120**  
**Delivery 2 (Verified):** +25 → Stock: **145**  
**Sales 2:** -15 → Stock: **130**  
**Damaged:** -5 → Stock: **125**

**Verification:**
```
Current Stock = 100 + (50 + 25) - (30 + 15) - 5
125 = 100 + 75 - 45 - 5
125 = 125 ✓ CORRECT
```

---

## 🎯 DATABASE TABLES

### Primary Tables:

1. **inventory_products**
   - `product_id` (PK)
   - `station_id`
   - `product_name`
   - `sku`
   - `current_stock` ← MAIN STOCK COLUMN
   - `price`
   - `cost`
   - `reorder_level`
   - `last_updated`

2. **deliveries_oversight**
   - `id` (PK)
   - `product_id`
   - `quantity` ← Delivered quantity
   - `status` ← Must be `Verified` for stock update
   - `delivery_type` = `merchandise`
   - `verified_by`
   - `verified_at`

3. **received_items** (Alternative delivery table)
   - `id` (PK)
   - `product_id`
   - `quantity_received`
   - `status`
   - `receiving_date`

4. **sales_transactions** / **pos_transactions**
   - `id` (PK)
   - `product_id`
   - `quantity` ← Sold quantity
   - `unit_price`
   - `total_amount`
   - `transaction_date`
   - `status` = `Completed`

5. **inventory_adjustments**
   - `id` (PK)
   - `product_id`
   - `adjustment_type` (Damaged/Lost/Found/Correction)
   - `adjustment_quantity` (can be positive or negative)
   - `reason`
   - `approved_by`
   - `approved_at`

---

## 🚀 IMPLEMENTATION CHECKLIST

### Must Be Database-Driven:
- ✓ Product names from `inventory_products.product_name`
- ✓ Current stock from `inventory_products.current_stock`
- ✓ Prices from `inventory_products.price` or `cost`
- ✓ SKU from `inventory_products.sku`
- ✓ Deliveries from `deliveries_oversight` or `received_items`
- ✓ Sales from `sales_transactions` or `pos_transactions`
- ✓ Adjustments from `inventory_adjustments`

### Must NOT Have:
- ✗ Hardcoded product arrays
- ✗ Hardcoded prices
- ✗ Hardcoded stock quantities
- ✗ Hardcoded SKUs
- ✗ Manual calculations outside database

### Code Verification:
- ✓ All SELECT queries use prepared statements
- ✓ All UPDATE queries use prepared statements
- ✓ No SQL injection vulnerabilities
- ✓ Proper error handling
- ✓ Transaction rollback on error
- ✓ Audit trail logging

---

## 📝 SQL EXAMPLES

### Stock-In After Delivery Verification:
```sql
-- Update stock
UPDATE inventory_products
SET current_stock = current_stock + ?,
    last_updated = NOW()
WHERE product_id = ? AND station_id = ?;

-- Update delivery status
UPDATE deliveries_oversight
SET status = 'Verified',
    verified_by = ?,
    verified_at = NOW()
WHERE id = ?;

-- Audit trail
INSERT INTO inventory_adjustments (product_id, adjustment_type, adjustment_quantity, reason, approved_by)
VALUES (?, 'delivery_verified', ?, 'Verified delivery #?', ?);
```

### Sales Transaction:
```sql
-- Check stock availability
SELECT current_stock FROM inventory_products
WHERE product_id = ? AND station_id = ?;

-- Deduct stock
UPDATE inventory_products
SET current_stock = GREATEST(0, current_stock - ?),
    last_updated = NOW()
WHERE product_id = ? AND station_id = ?;

-- Record sale
INSERT INTO sales_transactions (product_id, quantity, unit_price, total_amount, transaction_date)
VALUES (?, ?, ?, ?, NOW());
```

### Inventory Adjustment:
```sql
-- Create adjustment
INSERT INTO inventory_adjustments (product_id, adjustment_type, adjustment_quantity, reason, approved_by)
VALUES (?, 'damaged', ?, 'Damaged during storage', ?);

-- Update stock
UPDATE inventory_products
SET current_stock = GREATEST(0, current_stock - ?),
    last_updated = NOW()
WHERE product_id = ? AND station_id = ?;
```

---

## 🔐 SECURITY & SAFETY

### Prevent Negative Stock:
```sql
UPDATE inventory_products
SET current_stock = GREATEST(0, current_stock - ?)
WHERE product_id = ?;
```

### Prevent NULL Values:
```sql
UPDATE inventory_products
SET current_stock = COALESCE(current_stock, 0) + ?
WHERE product_id = ?;
```

### Transaction Safety:
```sql
BEGIN;
  -- Update stock
  -- Record transaction
  -- Update delivery status
  -- Log audit trail
COMMIT;
-- On error: ROLLBACK;
```

---

**Document Version:** 1.0  
**Last Updated:** June 28, 2026  
**Status:** Official Reference for Implementation Audit
