# Expected Delivery Link Feature - Implementation Summary

## Overview

This feature allows **Staff** to link manual delivery encoding to **Expected Deliveries** (from Admin-finalized Purchase Orders), similar to the fuel delivery system. When staff selects an expected delivery from a dropdown, the form auto-fills and upon saving, the system updates that specific PO record instead of creating a new one. The PO is then removed from the expected deliveries list.

---

## User Story

**As a Staff member**, when I receive actual merchandise delivery:
1. I can see a list of expected deliveries (from finalized POs)
2. I can select which expected delivery I'm fulfilling (optional)
3. The form auto-fills with PO details (supplier, product, expected qty, unit, category)
4. I enter the actual quantity received and DR number
5. System detects if actual qty differs from expected qty
6. If variance exists, delivery is flagged as **Discrepancy** for Manager review
7. If no variance, delivery status is **Pending Verification**
8. The PO record is updated (not created new) and removed from expected deliveries

---

## Implementation Details

### File Modified
- **`public/staff_record_delivery.php`**

### Changes Made

#### 1. **Added Expected Delivery Dropdown Section** (HTML)
```php
<!-- ══ Link to Expected Delivery (NEW) ══ -->
<?php if (!empty($expected_deliveries)): ?>
<div style="background:#e8f4fd;border:1px solid #b8d4f0;border-radius:8px;padding:16px 18px;margin-bottom:16px;">
    <div class="form-group">
        <label class="form-label">Select Expected Delivery (Optional)</label>
        <select id="expectedDeliverySelect" class="form-select">
            <option value="">-- None - Manual Entry --</option>
            <?php foreach ($expected_deliveries as $ed): ?>
            <option value="<?php echo $ed['id']; ?>"
                    data-po="<?php echo htmlspecialchars($ed['source_ref']); ?>"
                    data-product="<?php echo htmlspecialchars($ed['product']); ?>"
                    data-supplier="<?php echo htmlspecialchars($ed['supplier']); ?>"
                    data-category="<?php echo htmlspecialchars($ed['category']); ?>"
                    data-qty="<?php echo $ed['quantity']; ?>"
                    data-unit="<?php echo htmlspecialchars($ed['unit']); ?>">
                PO: <?php echo htmlspecialchars($ed['source_ref']); ?> - 
                <?php echo htmlspecialchars($ed['product']); ?> 
                (<?php echo number_format($ed['quantity'], 2) . ' ' . $ed['unit']; ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<?php endif; ?>
```

**Key Features:**
- Only shows if there are expected deliveries
- Dropdown includes: PO Number, Product Name, Expected Quantity with Unit
- Data attributes store all PO details for auto-fill
- "None - Manual Entry" option for traditional manual entry

#### 2. **Added Hidden Field to Track Linked Delivery**
```php
<input type="hidden" name="linked_delivery_id" id="linkedDeliveryId" value="">
```

#### 3. **Added IDs to Form Fields for Auto-Fill**
- `id="supplierName"` - Supplier field
- `id="itemName"` - Product/Item name field
- `id="categoryDisplay"` - Category display field
- `id="categoryHidden"` - Category hidden input
- `id="unitSelect"` - Unit dropdown
- `id="quantityInput"` - Quantity input
- `id="expectedQtyInfo"` - Expected qty info label

#### 4. **Added Variance Warning Display**
```html
<div id="manualVarianceWarning" class="variance-warning" style="display:none;">
    <i class="fas fa-exclamation-triangle"></i>
    <div>
        <strong>Variance Detected!</strong><br>
        <span id="varianceMessage"></span>
        This will be flagged for Manager review as a <strong>Discrepancy</strong>.
    </div>
</div>
```

#### 5. **JavaScript: Expected Delivery Dropdown Handler**
```javascript
document.addEventListener('DOMContentLoaded', function() {
    const expectedDropdown = document.getElementById('expectedDeliverySelect');
    
    if (expectedDropdown) {
        expectedDropdown.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (this.value) {
                // Auto-fill form from selected expected delivery
                const deliveryId = this.value;
                const po = selectedOption.dataset.po;
                const product = selectedOption.dataset.product;
                const supplier = selectedOption.dataset.supplier;
                const category = selectedOption.dataset.category;
                const qty = parseFloat(selectedOption.dataset.qty);
                const unit = selectedOption.dataset.unit;
                
                // Set hidden field
                document.getElementById('linkedDeliveryId').value = deliveryId;
                
                // Auto-fill all fields
                document.getElementById('supplierName').value = supplier;
                document.getElementById('itemName').value = product;
                document.getElementById('categoryDisplay').value = category;
                document.getElementById('categoryHidden').value = category;
                document.getElementById('unitSelect').value = unit;
                document.getElementById('quantityInput').value = qty;
                
                // Show expected quantity info
                const expectedInfo = document.getElementById('expectedQtyInfo');
                expectedInfo.textContent = 'Expected: ' + qty.toFixed(2) + ' ' + unit + ' (from PO: ' + po + ')';
                expectedInfo.style.display = 'block';
                
                // Check variance
                checkManualVariance();
            } else {
                // Reset to manual entry
                resetToManualEntry();
            }
        });
    }
});
```

#### 6. **JavaScript: Variance Detection**
```javascript
function checkManualVariance() {
    const linkedId = document.getElementById('linkedDeliveryId').value;
    
    if (!linkedId) {
        document.getElementById('manualVarianceWarning').style.display = 'none';
        return;
    }
    
    const actualQty = parseFloat(document.getElementById('quantityInput').value) || 0;
    const diff = actualQty - selectedExpectedQty;
    
    if (Math.abs(diff) > 0.001) {
        varianceMessage.textContent = 'Expected: ' + selectedExpectedQty.toFixed(2) + ' ' + unit + 
                                      ', but you entered: ' + actualQty.toFixed(2) + ' ' + unit + 
                                      '. Variance: ' + diff.toFixed(2) + ' ' + unit;
        varianceWarning.style.display = 'flex';
    } else {
        varianceWarning.style.display = 'none';
    }
}
```

**Triggers:**
- When quantity input changes
- When expected delivery is selected
- Tolerance: 0.001 (avoids floating point precision issues)

#### 7. **PHP: Updated POST Handler - Dual Mode Support**

**LINKED MODE** (when `linked_delivery_id` is set):
```php
if ($linked_delivery_id > 0) {
    // Fetch the expected delivery record
    $stmt = $pdo->prepare("SELECT * FROM deliveries_oversight WHERE id = ? AND station_id = ? AND status = 'Expected Delivery' AND delivery_type = 'merchandise'");
    $stmt->execute([$linked_delivery_id, $station_id]);
    $expected_del = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get actual quantity entered by staff
    $actual_qty = (float)($quantities[0] ?? 0);
    $expected_qty = (float)$expected_del['quantity'];
    
    // Variance detection
    $status = 'Pending Verification';
    $admin_notes = null;
    $diff = abs($actual_qty - $expected_qty);
    
    if ($diff > 0.001) {
        $status = 'Discrepancy';
        $admin_notes = "System Flag: Expected " . number_format($expected_qty, 2) . " {$expected_del['unit']}, but received " . number_format($actual_qty, 2) . " {$unit}. Variance: " . number_format($actual_qty - $expected_qty, 2) . " {$unit}.";
    }
    
    // UPDATE existing record (not INSERT)
    $pdo->prepare("
        UPDATE deliveries_oversight 
        SET quantity = ?, unit = ?, delivery_date = ?, dr_number = ?, remarks = ?, 
            encoded_by = ?, status = ?, admin_notes = ?, 
            unit_cost = ?, expiry_date = ?, received_by_name = ?, category = ?,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([...]);
    
    // Log activity
    log_activity($pdo, $me['id'], 'Staff Linked PO Delivery', "PO: {$expected_del['source_ref']} | Product: {$item_name} | Expected: {$expected_qty} | Actual: {$actual_qty}");
    
    // Redirect based on status
    if ($status === 'Discrepancy') {
        header('Location: staff_delivery_history.php?msg=discrepancy&type=warning');
    } else {
        header('Location: staff_delivery_history.php?msg=linked&type=success');
    }
}
```

**MANUAL MODE** (when `linked_delivery_id` is empty):
- Original flow: Creates new delivery records
- Generates new batch ID and delivery reference
- Status: `Pending Verification`

---

## Database Flow

### Before (Expected Delivery):
```
Status: 'Expected Delivery'
source_ref: 'PO-20260628-001'
product: 'Brake Fluid DOT 4'
quantity: 50.00
unit: 'bottles'
supplier: 'Petron Corporation'
encoded_by: NULL
delivery_date: NULL
dr_number: NULL
```

### After (Staff Links & Encodes):
```
Status: 'Pending Verification' (or 'Discrepancy' if variance)
source_ref: 'PO-20260628-001' (unchanged)
product: 'Brake Fluid DOT 4' (unchanged)
quantity: 55.00 (UPDATED - actual received)
unit: 'bottles'
supplier: 'Petron Corporation'
encoded_by: 5 (staff user ID)
delivery_date: '2026-06-28' (actual date)
dr_number: 'DR-20260628-001' (from staff input)
admin_notes: 'System Flag: Expected 50.00 bottles, but received 55.00 bottles. Variance: 5.00 bottles.' (if variance)
```

### Result:
- **Record is UPDATED, not created**
- **Status changes from 'Expected Delivery' to 'Pending Verification' or 'Discrepancy'**
- **No longer appears in expected deliveries list**
- **Moves to Manager's validation queue**

---

## Variance Detection Logic

### Scenario 1: Exact Match
```
Expected: 50.00 bottles
Actual:   50.00 bottles
Result:   No variance
Status:   'Pending Verification'
```

### Scenario 2: Quantity Mismatch (Over)
```
Expected: 50.00 bottles
Actual:   55.00 bottles
Variance: +5.00 bottles
Status:   'Discrepancy'
Note:     "System Flag: Expected 50.00 bottles, but received 55.00 bottles. Variance: 5.00 bottles."
```

### Scenario 3: Quantity Mismatch (Under)
```
Expected: 50.00 bottles
Actual:   45.00 bottles
Variance: -5.00 bottles
Status:   'Discrepancy'
Note:     "System Flag: Expected 50.00 bottles, but received 45.00 bottles. Variance: -5.00 bottles."
```

**Tolerance:** 0.001 (to avoid floating point precision issues)

---

## User Workflow

### Option A: Link to Expected Delivery

1. **Staff navigates to:** `staff_record_delivery.php`
2. **Sees dropdown:** "Link to Expected Delivery (Optional)"
3. **Selects PO:** "PO-20260628-001 - Brake Fluid DOT 4 (50.00 bottles)"
4. **Form auto-fills:**
   - Supplier: Petron Corporation
   - Item Name: Brake Fluid DOT 4
   - Category: Car Care
   - Unit: bottles
   - Quantity: 50.00
   - Expected info shows: "Expected: 50.00 bottles (from PO: PO-20260628-001)"
5. **Staff enters actual quantity:** 55.00
6. **Variance warning shows:** "Expected 50.00 bottles, but you entered 55.00 bottles. Variance: 5.00 bottles."
7. **Staff enters DR number:** DR-20260628-001
8. **Staff clicks:** "Save Delivery Record"
9. **System:**
   - Updates PO record (not creates new)
   - Sets status to 'Discrepancy'
   - Adds variance note to admin_notes
   - Removes from expected deliveries list
   - Redirects to history with warning message
10. **Manager sees:** Delivery in validation queue with Discrepancy flag

### Option B: Manual Entry (No Link)

1. **Staff navigates to:** `staff_record_delivery.php`
2. **Leaves dropdown at:** "-- None - Manual Entry --"
3. **Manually fills all fields**
4. **Clicks:** "Save Delivery Record"
5. **System:**
   - Creates NEW delivery record
   - Generates new batch ID and delivery ref
   - Status: 'Pending Verification'
   - Traditional manual entry flow

---

## Testing Checklist

### ✅ Pre-Implementation Tests
- [x] Database structure verified (all required columns exist)
- [x] Expected deliveries query working
- [x] Variance detection logic tested
- [x] Dropdown population query verified
- [x] Test expected delivery created for testing

### 🔄 Browser Testing (To Be Done by User)
- [ ] Open `staff_record_delivery.php` in browser
- [ ] Verify dropdown shows expected deliveries
- [ ] Select an expected delivery
- [ ] Verify form auto-fills correctly:
  - [ ] Supplier name
  - [ ] Item/Product name
  - [ ] Category
  - [ ] Unit
  - [ ] Quantity
  - [ ] Expected info label shows
- [ ] Change quantity to create variance
- [ ] Verify variance warning appears
- [ ] Submit form
- [ ] Verify:
  - [ ] No new record created
  - [ ] Existing record updated
  - [ ] Status changed to 'Discrepancy' (if variance) or 'Pending Verification' (if no variance)
  - [ ] Record appears in Manager validation queue
  - [ ] Expected delivery removed from list
- [ ] Test manual entry mode (no dropdown selection)
- [ ] Verify manual entry still creates new records

---

## Files Modified

1. **`public/staff_record_delivery.php`**
   - Added expected delivery dropdown section
   - Added hidden field for linked_delivery_id
   - Added IDs to form fields for auto-fill
   - Added variance warning display
   - Added JavaScript for dropdown handler and variance detection
   - Updated POST handler to support dual mode (linked vs manual)

2. **`database/test_expected_delivery_link.php`** (NEW)
   - Comprehensive test script
   - Verifies database structure
   - Tests variance detection
   - Creates sample expected delivery
   - Validates dropdown population

3. **`EXPECTED_DELIVERY_LINK_FEATURE.md`** (NEW - this file)
   - Complete documentation
   - User workflows
   - Implementation details
   - Testing checklist

---

## Success Metrics

✅ **Feature Complete** when:
1. Staff can see expected deliveries in dropdown
2. Selecting dropdown auto-fills form fields
3. Quantity changes trigger variance detection
4. Submitting linked delivery UPDATES existing record (not creates new)
5. Variance correctly detected and flagged
6. Expected delivery removed from list after processing
7. Manual entry mode still works (creates new records)
8. Records flow to Manager validation correctly

---

## Key Benefits

1. **Eliminates Duplicate Records**: Updates PO record instead of creating new one
2. **Automatic Variance Detection**: System flags discrepancies automatically
3. **Reduced Manual Entry**: Auto-fills all PO fields
4. **Better Tracking**: Links actual delivery to original PO
5. **Similar to Fuel System**: Consistent UX across fuel and merchandise
6. **Optional Feature**: Staff can still do manual entry if needed
7. **Manager Visibility**: Discrepancies flagged for review

---

## Technical Notes

- **Variance Tolerance:** 0.001 (avoids floating point precision issues)
- **Hidden Field:** `linked_delivery_id` tracks if delivery is linked to PO
- **Data Attributes:** Store PO details in dropdown options for auto-fill
- **Dual Mode POST Handler:** Checks `linked_delivery_id` to determine mode
- **Status Mapping:**
  - Linked + No Variance → `Pending Verification`
  - Linked + Variance → `Discrepancy`
  - Manual Entry → `Pending Verification`

---

## Next Steps

1. ✅ **Implementation Complete**
2. 🔄 **Browser Testing** (User to perform)
3. 📝 **User Acceptance Testing**
4. 🚀 **Production Deployment**

---

**Last Updated:** June 28, 2026  
**Feature Status:** ✅ Implementation Complete - Pending Browser Testing  
**Modified By:** Kiro AI Assistant
