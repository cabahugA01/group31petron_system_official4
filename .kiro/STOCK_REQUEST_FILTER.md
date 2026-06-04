# Stock Request Smart Filtering

## Overview
Enhanced the Stock Request modal to only display LOW STOCK and OUT OF STOCK items, automatically filtering out items that are already in sufficient supply (AVAILABLE status). This focuses staff attention on items that actually need restocking.

## Implementation Date
June 4, 2026

## Feature Description

### Stock Request Modal Filtering Logic

When staff clicks the "Stock Request" button, the modal now intelligently filters items based on their stock status:

#### Items Shown in Modal:
- ❌ **OUT OF STOCK** items (stock = 0)
- ⚠️ **LOW STOCK** items (stock ≤ reorder level)

#### Items Hidden from Modal:
- ✅ **AVAILABLE** items (stock > reorder level)

## Benefits

### 1. Improved Efficiency
- Staff don't waste time scrolling through items that don't need restocking
- Faster request submission process
- Reduced cognitive load

### 2. Better Focus
- Clear visibility of problematic inventory
- Prioritizes urgent stock needs
- Prevents unnecessary requests for well-stocked items

### 3. Reduced Manager Workload
- Fewer unnecessary stock requests to review
- Requests are more relevant and actionable
- Better resource allocation

### 4. Inventory Optimization
- Encourages proactive restocking of low items
- Prevents stockouts by highlighting at-risk items
- Aligns with just-in-time inventory principles

## User Experience

### Opening Stock Request Modal

**Scenario 1: Items Need Restocking**
```
┌─────────────────────────────────────────┐
│ Stock Request                      × │
├─────────────────────────────────────────┤
│ ℹ️ Only LOW STOCK and OUT OF STOCK     │
│   items are shown                       │
├─────────────────────────────────────────┤
│ 🔍 Filter items...                      │
│                                         │
│ ☐ Select All                    5 items │
│                                         │
│ OILS / LUBES / GREASE                   │
│ ☐ Engine Oil HD40    OUT OF STOCK      │
│ ☐ MP Grease          LOW STOCK         │
│                                         │
│ CAR ACCESSORIES                         │
│ ☐ AC Filter - Nomis  OUT OF STOCK      │
│ ☐ Coolant Green      LOW STOCK         │
│ ☐ Tire Black Small   LOW STOCK         │
└─────────────────────────────────────────┘
```

**Scenario 2: All Items in Stock**
```
┌─────────────────────────────────────────┐
│ Stock Request                      × │
├─────────────────────────────────────────┤
│ ℹ️ Only LOW STOCK and OUT OF STOCK     │
│   items are shown                       │
├─────────────────────────────────────────┤
│                                         │
│        ✓ All items are in stock!       │
│   No LOW STOCK or OUT OF STOCK items    │
│          to request.                    │
│                                         │
└─────────────────────────────────────────┘
```

## Technical Implementation

### JavaScript Filter Logic

```javascript
function renderSrCheckList(filter) {
    var q = (filter || '').toLowerCase();
    var cats = {};
    
    allMerchData.forEach(function(it, idx) {
        // FILTER: Skip items with 'ok' status (AVAILABLE)
        if (it.stCls === 'ok') return;
        
        // Only process 'out' (OUT OF STOCK) and 'low' (LOW STOCK)
        if (q && it.name.toLowerCase().indexOf(q) === -1 &&
                 it.sku.toLowerCase().indexOf(q) === -1 &&
                 it.category.toLowerCase().indexOf(q) === -1) return;
        
        if (!cats[it.category]) cats[it.category] = [];
        cats[it.category].push({ it: it, idx: idx });
    });
    
    // ... render logic
}
```

### Stock Status Classification

Items are classified based on the `stCls` property:

| Status Class | Condition | Display Label | Shown in Modal? |
|--------------|-----------|---------------|-----------------|
| `'out'` | stock = 0 | OUT OF STOCK | ✅ Yes |
| `'low'` | stock ≤ reorder_level | LOW STOCK | ✅ Yes |
| `'ok'` | stock > reorder_level | AVAILABLE | ❌ No |

### Reorder Level Logic

The system uses configurable reorder levels per product:
```sql
COALESCE(si.reorder_level, 10) AS reorder_level
```

Default reorder level is **10 units** if not specified.

## UI Updates

### Info Box Message
**Before:**
```
• Manager will review and set the approved quantity
• Audit trail logged: Staff ID, Item, Timestamp
```

**After:**
```
• Only LOW STOCK and OUT OF STOCK items are shown
• Manager will review and set the approved quantity
• Audit trail logged: Staff ID, Item, Timestamp
```

### Empty State Message
**Before:**
```
No items match your search.
```

**After (when all items in stock):**
```
✓ All items are in stock!
No LOW STOCK or OUT OF STOCK items to request.
```

## Search Functionality

The search filter still works within the LOW/OUT OF STOCK subset:
- Search by product name
- Search by SKU
- Search by category
- Only searches within items that are LOW or OUT OF STOCK

## Edge Cases Handled

### Case 1: All Items in Stock
- Modal shows success message
- No checkboxes displayed
- Clear indication that no action needed

### Case 2: Search Returns No Results
- "No items match your search" message
- Filtered within LOW/OUT items only
- User can clear search to see all requestable items

### Case 3: Items Become Available During Session
- Page refresh required to update modal contents
- Modal data refreshed on each open
- Real-time stock levels reflected

## Testing Checklist

- [x] OUT OF STOCK items appear in modal
- [x] LOW STOCK items appear in modal
- [x] AVAILABLE items do NOT appear in modal
- [x] Empty state shows when all items in stock
- [x] Search works within filtered items
- [x] Select All only selects filtered items
- [x] Submission works correctly for filtered items
- [x] Modal refreshes correctly on reopen

## Business Rules

### Stock Status Thresholds

1. **OUT OF STOCK:** `stock = 0`
   - Highest priority
   - Critical restock needed
   - Red badge color (#dc3545)

2. **LOW STOCK:** `stock ≤ reorder_level`
   - Medium priority
   - Proactive restocking recommended
   - Orange badge color (#fd7e14)

3. **AVAILABLE:** `stock > reorder_level`
   - Normal inventory level
   - No immediate action needed
   - Green badge color (#28a745)

## Related Files

- `public/staff_inventory_merchandise.php` - Main implementation
- `backend/api/stock_request.php` - Backend request handler (unchanged)
- Database: `station_inventory.reorder_level` - Threshold configuration

## Future Enhancements

### Potential Improvements
1. **Priority Sorting:** Show OUT OF STOCK items first, then LOW STOCK
2. **Badge Count:** Display count of requestable items on "Stock Request" button
3. **Quick Request:** One-click to request all LOW/OUT items
4. **Smart Suggestions:** AI-based quantity recommendations
5. **Batch Actions:** Request by category or supplier

---

**Status:** ✅ Implemented and Tested  
**Filter Logic:** Only LOW STOCK and OUT OF STOCK items shown in modal  
**User Impact:** Faster, more focused stock request workflow  
**Business Impact:** Reduced unnecessary requests, better inventory management
