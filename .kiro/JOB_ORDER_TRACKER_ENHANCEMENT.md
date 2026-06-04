# 📋 Job Order Tracker Enhancement - Staff Transaction Module

## 🎯 Current Status vs Requirements

### ✅ Already Implemented

| Feature | Status | Location |
|---------|--------|----------|
| **KPI Status Cards** | ✅ Complete | Lines 4422-4475 (staff_transactions_hub.php) |
| **JO ID Column** | ✅ Complete | Showing job_order_id or #id |
| **Customer Column** | ✅ Complete | customer_name field |
| **Vehicle/Service Column** | ✅ Complete | vehicle_plate + service_type |
| **Mechanic Column** | ✅ Complete | mechanic_name or "Unassigned" |
| **Workflow Status** | ✅ Complete | Pending → Ongoing → Completed badges |
| **Payment Status** | ✅ Complete | Paid/Downpayment/Unpaid/Receivables badges |
| **Remarks Column** | ✅ Complete | rejection_remarks/notes displayed |
| **Date/Time Column** | ✅ Complete | created_at timestamp |
| **Filter Buttons** | ✅ Complete | All/Pending/Approved/In Progress/Completed/Rejected |
| **Pagination** | ✅ Complete | Rows per page + page navigation |
| **Real-time Auto-refresh** | ✅ Complete | 5-second polling in dashboard |

---

### 🔧 Needs Enhancement

#### 1. **Action Buttons** (Partially Implemented)

**Current Implementation**:
```php
// Lines 4613-4670
- ❌ Re-encode (for rejected JOs)
- ⚠️ In Progress button (basic)
- ⚠️ Complete button (opens payment modal)
- ⚠️ Downpayment button (for partial payment)
```

**Required**:
```
✅ View → open full job order details modal
✅ Update Status → change workflow stage (Pending → Ongoing → Completed)
✅ Adjust → correct service details (edit JO info)
✅ Mark Paid / Settle Balance (already working)
```

---

#### 2. **Export Options** (❌ NOT IMPLEMENTED)

**Required**:
```
❌ Excel/CSV → export service records
❌ PDF → export service receipts with current status
```

---

## 📝 Enhancement Plan

### Phase 1: Enhanced Action Buttons ✅

#### A. **View Button** - View Full Details

**Implementation**:
```php
<button type="button" 
        onclick="viewJobOrderDetails(<?= $job['id'] ?>, '<?= addslashes($job['_source'] ?? 'job_orders') ?>')"
        class="txn-btn" style="padding:5px 11px;font-size:11px;">
    <i class="fas fa-eye"></i> View
</button>
```

**Modal Content**:
- JO ID, Customer Name
- Vehicle Plate + Type
- Service Type + Description
- Required Parts (detailed list)
- Mechanic Assigned
- Workflow Status + Timeline
- Payment Status + Breakdown (Total, Paid, Balance)
- Remarks/Notes
- Timestamps (Created, Updated, Validated)

---

#### B. **Update Status Button** - Workflow Management

**Implementation**:
```php
<?php if (!in_array($wf_status, ['Completed', 'Rejected'])): ?>
<button type="button"
        onclick="openUpdateStatusModal(<?= $job['id'] ?>, '<?= $wf_status ?>', '<?= addslashes($job['_source']) ?>')"
        class="txn-btn primary" style="padding:5px 11px;font-size:11px;">
    <i class="fas fa-sync-alt"></i> Update Status
</button>
<?php endif; ?>
```

**Modal Options**:
- Pending Validation → Approved (Manager only, skip for staff)
- Approved → In Progress
- In Progress → Completed (with payment settlement)
- Any → Rejected (with reason)

---

#### C. **Adjust Button** - Edit JO Details

**Implementation**:
```php
<?php if (in_array($wf_status, ['Pending Validation', 'Approved'])): ?>
<button type="button"
        onclick="openAdjustJobOrderModal(<?= $job['id'] ?>, '<?= addslashes($job['_source']) ?>')"
        class="txn-btn" style="padding:5px 11px;font-size:11px;background:#f59e0b;color:#fff;">
    <i class="fas fa-edit"></i> Adjust
</button>
<?php endif; ?>
```

**Editable Fields**:
- Customer Name
- Vehicle Plate/Type
- Service Type + Description
- Required Parts
- Mechanic Assignment
- Estimated Cost

**Note**: Cannot adjust once "In Progress" or "Completed"

---

### Phase 2: Export Functionality ✅

#### A. **Excel/CSV Export** - Service Records

**Button Placement**: Above the table (filter bar area)

```php
<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;justify-content:space-between;">
    <!-- Filter buttons (existing) -->
    <div style="display:flex;gap:10px;">
        <!-- Filter buttons here -->
    </div>
    
    <!-- Export buttons (NEW) -->
    <div style="display:flex;gap:8px;">
        <button onclick="exportJobOrders('excel')" class="txn-btn" style="background:#28a745;color:#fff;">
            <i class="fas fa-file-excel"></i> Export Excel
        </button>
        <button onclick="exportJobOrders('csv')" class="txn-btn" style="background:#28a745;color:#fff;">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
        <button onclick="exportJobOrders('pdf')" class="txn-btn" style="background:#dc3545;color:#fff;">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
    </div>
</div>
```

**Export Implementation**:
```javascript
function exportJobOrders(format) {
    const filter = joState.filter || 'all';
    const url = `export_job_orders.php?format=${format}&filter=${filter}&station_id=<?= $station_id ?>`;
    window.location.href = url;
}
```

**Backend**: Create `export_job_orders.php`
- Respects current filter (all/pending/approved/inprogress/completed/rejected)
- Exports all columns from table
- Excel: XLS format with borders
- CSV: Comma-separated values
- PDF: Formatted receipt-style layout

---

#### B. **PDF Export** - Service Receipts with Status

**Individual JO Receipt**:
```php
<button type="button"
        onclick="printJobOrderReceipt(<?= $job['id'] ?>, '<?= addslashes($job['_source']) ?>')"
        class="txn-btn" style="padding:5px 11px;font-size:11px;">
    <i class="fas fa-print"></i> Print Receipt
</button>
```

**Receipt Format**:
```
┌─────────────────────────────────────────────────────┐
│            PETRON STATION MANAGEMENT                 │
│              JOB ORDER RECEIPT                       │
├─────────────────────────────────────────────────────┤
│ JO #: JO-00123                  Date: Jun 3, 2026   │
│ Customer: Juan Dela Cruz                             │
│ Vehicle: ABC-1234 (Toyota Vios)                     │
├─────────────────────────────────────────────────────┤
│ SERVICE DETAILS                                      │
│ Type: Oil Change + Check-up                         │
│ Parts: Engine Oil 4L, Oil Filter                    │
│ Mechanic: Pedro Santos                              │
├─────────────────────────────────────────────────────┤
│ WORKFLOW STATUS                                      │
│ Current: [●] COMPLETED                               │
│ Timeline: Jun 3, 8:00 AM → 10:30 AM                │
├─────────────────────────────────────────────────────┤
│ PAYMENT DETAILS                                      │
│ Estimated Cost:        ₱1,500.00                    │
│ Total Amount:          ₱1,500.00                    │
│ Amount Paid:           ₱1,500.00                    │
│ Balance:               ₱0.00                        │
│ Payment Method: Cash                                 │
│ Status: [✓] PAID                                    │
├─────────────────────────────────────────────────────┤
│ Remarks: None                                        │
├─────────────────────────────────────────────────────┤
│ Encoded by: Maria Garcia                             │
│ Validated by: Jose Reyes (Manager)                  │
│ Timestamp: June 3, 2026 10:35 AM                   │
└─────────────────────────────────────────────────────┘
```

---

## 🎨 UI/UX Improvements

### Action Buttons Layout

**Current** (Lines 4613-4670):
```
[Re-encode] (for rejected)
[In Progress] (for approved)
[Complete] (opens payment modal)
[Downpayment] (for partial payment)
```

**Enhanced**:
```
┌─────────────────────────────────────────────────────┐
│ Actions Column (width: 200px)                       │
├─────────────────────────────────────────────────────┤
│ [👁️ View] [🔄 Update Status]                        │
│ [✏️ Adjust] [💰 Mark Paid]                           │
│ [🖨️ Print Receipt]                                   │
└─────────────────────────────────────────────────────┘
```

**Smart Button Display**:
```php
<?php
// Status-aware button display
if ($wf_status === 'Rejected'):
    // Rejected: View + Re-encode
    echo '<button>View</button> <button>Re-encode</button>';
    
elseif ($val_status === 'Pending Validation'):
    // Pending: View only (awaiting manager approval)
    echo '<button>View</button>';
    echo '<span>Awaiting approval</span>';
    
elseif ($wf_status === 'Approved'):
    // Approved: View + Adjust + Update Status
    echo '<button>View</button> <button>Adjust</button>';
    echo '<button>Update Status (→ In Progress)</button>';
    
elseif ($wf_status === 'In Progress'):
    // In Progress: View + Update Status + Downpayment
    echo '<button>View</button>';
    echo '<button>Update Status (→ Complete)</button>';
    if ($pay_status !== 'Paid'):
        echo '<button>Downpayment</button>';
    endif;
    
elseif ($wf_status === 'Completed'):
    // Completed: View + Print Receipt + Settle Balance (if unpaid)
    echo '<button>View</button> <button>Print Receipt</button>';
    if ($pay_status !== 'Paid'):
        echo '<button>Settle Balance</button>';
    endif;
endif;
?>
```

---

## 🔄 Real-Time Auto-Refresh Enhancement

### Current Implementation:
- ✅ Dashboard: 5-second auto-refresh (staff_dashboard.php lines 2036-2139)
- ✅ Job Order rows auto-update in dashboard widget

### Enhancement for Tracker Tab:
```javascript
// Add auto-refresh to tracker tab
let trackerRefreshTimer = null;

function startTrackerAutoRefresh() {
    if (trackerRefreshTimer) {
        clearInterval(trackerRefreshTimer);
    }
    
    trackerRefreshTimer = setInterval(function() {
        // Skip if modal is open
        if (document.querySelector('.modal.active')) {
            return;
        }
        
        // Silently reload tracker tab data
        refreshTrackerData();
    }, 10000); // 10-second refresh for tracker tab
}

function refreshTrackerData() {
    fetch('?section=merchandise&active_tab=tracker&refresh=1')
        .then(response => response.json())
        .then(data => {
            updateJobOrderTable(data.job_orders);
            updateKPICards(data.counts);
        });
}
```

---

## 📊 Implementation Priority

| Feature | Priority | Complexity | Estimated Time |
|---------|----------|------------|----------------|
| **View Button + Modal** | 🔴 HIGH | Medium | 2-3 hours |
| **Update Status Button** | 🔴 HIGH | Medium | 2 hours |
| **Adjust Button + Modal** | 🟡 MEDIUM | Medium | 2-3 hours |
| **Excel/CSV Export** | 🟡 MEDIUM | Low | 1 hour |
| **PDF Receipt Export** | 🟢 LOW | Medium | 2 hours |
| **Auto-refresh Tracker Tab** | 🟢 LOW | Low | 30 mins |

**Total Estimated Time**: 10-12 hours

---

## 🎯 Success Criteria

### Functional Requirements:
- [x] Display JO ID, Customer, Vehicle/Service, Mechanic, Workflow Status, Payment Status ✅
- [ ] **View button** opens modal with full JO details
- [ ] **Update Status button** allows workflow progression
- [ ] **Adjust button** allows editing JO details (before In Progress)
- [ ] **Mark Paid/Settle Balance** buttons handle payment (already working ✅)
- [ ] **Excel/CSV export** downloads service records
- [ ] **PDF export** generates formatted receipt
- [ ] **Auto-refresh** updates tracker every 10 seconds

### User Experience:
- [ ] Sub-text: "Monitor service progress and pending balances in real time." ✅ (already implemented)
- [ ] Action buttons are context-aware (show/hide based on status)
- [ ] Modals are mobile-responsive
- [ ] Export respects current filter selection
- [ ] No page refresh needed - auto-updates work seamlessly

---

## 🚀 Quick Implementation Guide

### Step 1: Add View Button

**Location**: `staff_transactions_hub.php` line ~4613 (Actions column)

```php
<!-- Add to every row -->
<button type="button" 
        onclick="viewJobOrderModal(<?= $job['id'] ?>)"
        class="txn-btn" style="padding:5px 11px;font-size:11px;">
    <i class="fas fa-eye"></i> View
</button>
```

### Step 2: Add Update Status Button

```php
<?php if (!in_array($wf_status, ['Completed', 'Rejected'])): ?>
<button type="button"
        onclick="updateJobOrderStatus(<?= $job['id'] ?>, '<?= $wf_status ?>')"
        class="txn-btn primary" style="padding:5px 11px;font-size:11px;">
    <i class="fas fa-sync-alt"></i> Update
</button>
<?php endif; ?>
```

### Step 3: Add Adjust Button

```php
<?php if (in_array($wf_status, ['Pending Validation', 'Approved'])): ?>
<button type="button"
        onclick="adjustJobOrder(<?= $job['id'] ?>)"
        class="txn-btn" style="padding:5px 11px;font-size:11px;background:#f59e0b;color:#fff;">
    <i class="fas fa-edit"></i> Adjust
</button>
<?php endif; ?>
```

### Step 4: Add Export Buttons

**Location**: Above the table (line ~4478)

```php
<div style="display:flex;gap:8px;margin-bottom:14px;">
    <button onclick="exportJobOrders('excel')" class="txn-btn" style="background:#28a745;color:#fff;">
        <i class="fas fa-file-excel"></i> Excel
    </button>
    <button onclick="exportJobOrders('csv')" class="txn-btn" style="background:#28a745;color:#fff;">
        <i class="fas fa-file-csv"></i> CSV
    </button>
    <button onclick="exportJobOrders('pdf')" class="txn-btn" style="background:#dc3545;color:#fff;">
        <i class="fas fa-file-pdf"></i> PDF
    </button>
</div>
```

---

## 📞 Support & Questions

- **Current Implementation**: 70% complete (display + basic actions)
- **Remaining Work**: 30% (enhanced actions + exports)
- **Ready for**: Phase 1 (Action Buttons) implementation
- **Pending**: Phase 2 (Export functionality)

---

**Last Updated**: June 3, 2026  
**Status**: ✅ Requirements documented, ready for implementation  
**Next Step**: Implement View/Update/Adjust buttons + Export functionality
