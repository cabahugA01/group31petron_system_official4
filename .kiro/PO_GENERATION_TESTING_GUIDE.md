# Purchase Order Generation Testing Guide

**Feature:** Generate PO from Validated Stock Requests  
**Updated:** June 4, 2026  
**File:** `manager_fuel_stock_requests.php`

---

## Quick Test Steps

### Prerequisites:
1. Have a Staff account and Manager account ready
2. Have at least one merchandise item with low stock (stock_level <= reorder_level)

---

## Test Scenario: Complete Workflow

### 1. STAFF - Create Stock Request

**Login as:** Staff  
**Navigate to:** Inventory → Merchandise Inventory

1. Check if any items show "LOW STOCK" or "OUT OF STOCK"
2. If yes, the "Stock Request" button should appear at top
3. Click **"Stock Request"** button
4. Modal opens showing all low/out-of-stock items
5. Check the items you want to request (or Select All)
6. Click **"Submit Requests"**
7. Success message appears
8. Verify in: Inventory → Stock Request
   - Status should be **"Pending"**

**Expected Result:**
- ✅ New record in `stock_requests` table with status='Pending'
- ✅ Request visible in Staff's Stock Request page

---

### 2. MANAGER - Validate Stock Request

**Login as:** Manager  
**Navigate to:** Inventory → Stock Request Validation  
**OR:** Direct link: `manager_fuel_stock_requests.php`

1. You should see the pending request in the **Merchandise Stock Requests** section
2. Review the item details:
   - Item name, SKU, Category
   - Current stock level
   - Requested quantity
   - Status: "Pending"
3. You can either:
   - **Approve** (validates the request)
   - **Reject** (with reason)

**To Approve:**
1. Scroll to the **Fuel Stock Requests** section (for fuel, not merch)
2. Click **"Approve"** button
3. Enter approved quantity (can adjust)
4. Add manager notes (optional)
5. Click **"Confirm Approve"**

**WAIT!** For merchandise requests:
- They appear in the TOP section (Merchandise Stock Requests)
- But approval is done elsewhere (need to verify this flow)

**Expected Result:**
- ✅ Status changes from 'Pending' to **'Validated'**
- ✅ `manager_id` and `processed_at` fields populated
- ✅ Request now shows in "Ready for PO" card

---

### 3. MANAGER - Generate Purchase Order

**Stay as:** Manager  
**Same page:** `manager_fuel_stock_requests.php`

1. Look at the summary cards at top - you should see:
   - **"Ready for PO"** card showing count: 1 (or more)

2. Scroll to **Merchandise Stock Requests** section
3. Find the validated request
4. Status should show: **"Validated"** badge (blue)
5. In the Action column, you should see: **"Generate PO"** button (purple)

6. Click **"Generate PO"** button
7. Modal opens showing:
   - Item name
   - Approved quantity (big number in yellow box)
   - Info message about Admin validation
8. Click **"Generate PO"** button in modal
9. Form submits

**Expected Result:**
- ✅ Success message: "✓ Purchase Order PO-YYYYMMDD-SR#### generated successfully! Pending Admin validation."
- ✅ New record in `purchase_orders` table:
  - `request_id` = stock request ID
  - `po_number` = PO-YYYYMMDD-SR####
  - `status` = 'Pending Admin Validation'
  - `type` = 'merch'
  - `created_by` = Manager ID
- ✅ New record in `purchase_order_items` table
- ✅ Request now shows PO number instead of "Generate PO" button

---

### 4. Verify PO Creation

**Check Database:**
```sql
-- Check the PO was created
SELECT * FROM purchase_orders 
WHERE request_id = [your_request_id]
ORDER BY created_at DESC LIMIT 1;

-- Expected:
-- po_number: PO-20260604-SR0001 (or similar)
-- status: Pending Admin Validation
-- request_id: (matches stock request)
-- product_name: (matches requested item)
-- quantity: (matches approved quantity)

-- Check PO items
SELECT * FROM purchase_order_items
WHERE po_id = [po_id_from_above];

-- Expected:
-- item_name: (matches requested item)
-- quantity: (matches approved quantity)
-- unit_price: (item cost)
-- total_price: (quantity × unit_price)
```

**Check Manager Page:**
1. Refresh `manager_fuel_stock_requests.php`
2. Find the same request in Merchandise section
3. Action column should now show:
   - ✅ "PO: PO-YYYYMMDD-SR####" (green check icon)
   - ❌ NOT "Generate PO" button (button is gone)

---

### 5. Try to Generate Duplicate (Should Fail)

**Test duplicate prevention:**

1. Try to generate PO again for same request
2. You can't - the button is already replaced with PO number

**If you try via direct POST (using browser tools):**
```
POST to manager_fuel_stock_requests.php
Data: action=generate_po&request_id=[same_id]

Expected Error:
"✗ Error generating PO: Purchase Order already exists for this stock request."
```

---

### 6. ADMIN - Review PO (Next Step)

**Login as:** Admin  
**Navigate to:** Purchase Orders (or Admin Oversight)

1. Should see the new PO with status "Pending Admin Validation"
2. Admin can review details
3. Admin approves → Status changes to "Approved"
4. Admin prints → Status changes to "Official"
5. Expected Deliveries created for Staff

---

## Common Issues & Solutions

### Issue: "Stock Request" button doesn't appear
**Solution:**
- Check if any items have stock_level <= reorder_level
- Check browser console for JavaScript errors
- Verify item exists in `station_inventory` with proper station_id

### Issue: "Generate PO" button doesn't appear
**Solution:**
- Verify request status is exactly 'Validated' (not 'Pending' or 'Approved')
- Check no existing PO has same request_id
- Clear browser cache and refresh

### Issue: PO generation fails with "Invalid stock request ID"
**Solution:**
- Verify request exists and status = 'Validated'
- Check request.station_id matches manager's station_id
- Verify request_id is correct integer

### Issue: PO generated but request_id is NULL
**Solution:**
- Check POST data includes request_id
- Verify SQL INSERT statement includes request_id field
- Check database column allows request_id

### Issue: Unit price is 0.00 in PO
**Solution:**
- Check `station_inventory` has cost field populated
- Check `approved_price` field in stock_requests
- Verify price_stmt query is finding the item
- Fallback to default price if needed

---

## SQL Queries for Debugging

### Check Stock Request Status:
```sql
SELECT sr.id, sr.item_name, sr.status, sr.requested_quantity, 
       sr.approved_quantity, sr.manager_id, sr.processed_at,
       u.name as staff_name, m.name as manager_name
FROM stock_requests sr
LEFT JOIN users u ON sr.staff_id = u.id
LEFT JOIN users m ON sr.manager_id = m.id
WHERE sr.station_id = [your_station_id]
ORDER BY sr.created_at DESC;
```

### Check if PO exists for request:
```sql
SELECT po.*, poi.*
FROM purchase_orders po
LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
WHERE po.request_id = [your_request_id];
```

### Check validated requests without POs:
```sql
SELECT sr.*
FROM stock_requests sr
LEFT JOIN purchase_orders po ON po.request_id = sr.id
WHERE sr.status = 'Validated'
  AND sr.station_id = [your_station_id]
  AND po.id IS NULL;
```

### Manually set request to Validated (for testing):
```sql
UPDATE stock_requests
SET status = 'Validated',
    approved_quantity = requested_quantity,
    manager_id = [manager_user_id],
    processed_at = NOW()
WHERE id = [request_id];
```

---

## Test Data Setup (Optional)

### Create Low Stock Item:
```sql
-- Set an item to low stock
UPDATE station_inventory
SET stock_level = 5,
    reorder_level = 20
WHERE station_id = [your_station_id]
  AND sku = 'TEST-SKU-001'
LIMIT 1;
```

### Create Pending Request Manually:
```sql
INSERT INTO stock_requests 
    (staff_id, station_id, item_id, item_sku, item_name, item_category,
     current_stock, requested_quantity, status, created_at)
VALUES 
    ([staff_user_id], [station_id], [product_id], 'TEST-SKU', 'Test Item', 'Test Category',
     5, 15, 'Pending', NOW());
```

### Set Request to Validated:
```sql
UPDATE stock_requests
SET status = 'Validated',
    approved_quantity = 15,
    manager_id = [manager_user_id],
    processed_at = NOW()
WHERE id = LAST_INSERT_ID();
```

---

## Success Criteria

✅ **Test Passes If:**
1. Staff can create stock request for low-stock items
2. Manager can see request in "Merchandise Stock Requests" section
3. After validation, request appears in "Ready for PO" card
4. "Generate PO" button appears for validated requests
5. Clicking button opens modal with correct details
6. PO is created with correct:
   - PO number format (PO-YYYYMMDD-SR####)
   - Status (Pending Admin Validation)
   - Link to request (request_id field)
   - Item details and quantities
7. PO appears in database tables correctly
8. Button changes to show PO number after generation
9. Cannot generate duplicate PO for same request
10. Admin can see PO for final approval

---

## Rollback (If Needed)

### Delete Test PO:
```sql
-- Delete PO items first (foreign key)
DELETE FROM purchase_order_items
WHERE po_id = [po_id];

-- Delete PO
DELETE FROM purchase_orders
WHERE id = [po_id];

-- Reset request to Validated (so button appears again)
UPDATE stock_requests
SET updated_at = NOW()
WHERE id = [request_id];
```

---

**Happy Testing!** 🎉

If you find any issues, check:
1. Browser console for JavaScript errors
2. PHP error logs
3. Database query logs
4. POST data being submitted
