# Purchase Request Title & Button Fix

## Changes Made

### 1. Removed "REVIEW" word from title
**"Purchase Request Review"** → **"Purchase Request"**

### 2. Removed "Review" button from action column
The "Review" button has been removed from the ACTION column in the table.

## Reason
- The word "Review" in the title is redundant
- The "Review" button functionality is not needed

## Files Modified

### 1. ✅ `public/manager_stock_request_review.php`

**Page Title (Line ~833):**
```php
// BEFORE:
<h1><i class="fas fa-clipboard-check"></i> Purchase Request Review</h1>

// AFTER:
<h1><i class="fas fa-clipboard-check"></i> Purchase Request</h1>
```

**Action Buttons (Line ~1136-1160):**
```php
// BEFORE:
<button class="txn-btn txn-btn-info" onclick="viewReqDetails(...)">
    <i class="fas fa-eye"></i> View
</button>

<!-- Review button (REMOVED) -->
<button class="txn-btn txn-btn-secondary" onclick="openRemarksModal(...)">
    <i class="fas fa-comment-dots"></i> Review
</button>

<button class="txn-btn txn-btn-approve" onclick="openApprovePOModal(...)">
    <i class="fas fa-file-invoice"></i> Generate PO
</button>

// AFTER:
<button class="txn-btn txn-btn-info" onclick="viewReqDetails(...)">
    <i class="fas fa-eye"></i> View
</button>

<!-- Review button REMOVED -->

<button class="txn-btn txn-btn-approve" onclick="openApprovePOModal(...)">
    <i class="fas fa-file-invoice"></i> Generate PO
</button>
```

**Export Functions (Lines ~1833-1851):**
```javascript
// BEFORE:
exportTableToPDF('mgrReqTable', 'Purchase Requests Review Report');
exportTableToExcel('mgrReqTable', 'purchase_requests_review.xls');
exportTableToCSV('mgrReqTable', 'purchase_requests_review.csv');

// AFTER:
exportTableToPDF('mgrReqTable', 'Purchase Requests Report');
exportTableToExcel('mgrReqTable', 'purchase_requests.xls');
exportTableToCSV('mgrReqTable', 'purchase_requests.csv');
```

### 2. ✅ `partials/rbac_menu.php`

**Sidebar Navigation (Line ~419):**
```php
// BEFORE:
['id' => 'mgr_stock_review', 'label' => 'Purchase Request Review', ...]

// AFTER:
['id' => 'mgr_stock_review', 'label' => 'Purchase Request', ...]
```

## Impact

### What Changed:
- ✅ Page title: "Purchase Request Review" → **"Purchase Request"**
- ✅ Sidebar menu: "Purchase Request Review" → **"Purchase Request"**
- ✅ **Action column: "Review" button REMOVED**
- ✅ Export filenames: Removed "_review" from filenames
- ✅ Export PDF title: "Purchase Requests Review Report" → "Purchase Requests Report"

### Buttons Remaining in Action Column:
1. ✅ **View** - View request details
2. ✅ **Generate PO** - Approve and generate purchase order (for pending requests)
3. ✅ **Reject** - Reject the request (for pending requests)

### Button Removed:
- ❌ **Review** - Removed (was between View and Generate PO)

### What Stayed the Same:
- ✅ File name: `manager_stock_request_review.php` (unchanged)
- ✅ Page ID: `mgr_stock_review` (unchanged)
- ✅ All other functionality works exactly the same
- ✅ Database: No database changes
- ✅ Permissions: Same permissions apply

## Testing

After refresh, you should see:
- ✅ Page header shows "Purchase Request" (without "Review")
- ✅ Sidebar shows "Purchase Request" (without "Review")
- ✅ Action column has only 3 buttons: View, Generate PO (if pending), Reject (if pending)
- ✅ **No "Review" button in the action column**
- ✅ Exports generate with cleaned-up filenames

## Action Column Layout

### Before:
```
┌─────────────┐
│ View        │
│ Review      │ ← REMOVED
│ Generate PO │
│ Reject      │
└─────────────┘
```

### After:
```
┌─────────────┐
│ View        │
│ Generate PO │
│ Reject      │
└─────────────┘
```

## No Breaking Changes

- File paths unchanged
- URLs unchanged
- Database unchanged
- Permissions unchanged
- Only display elements updated (title text and button removed)

## Date Fixed
January 2025

---

**Simple changes - immediately visible after browser refresh!** ✅
- Title updated
- Review button removed from action column
