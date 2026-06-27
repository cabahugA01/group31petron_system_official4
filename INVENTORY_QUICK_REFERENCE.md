# Inventory Permissions - Quick Reference Card

## 🚀 Quick Start

### Add to Any Inventory File:
```php
require_once __DIR__ . '/../backend/inventory_permissions.php';
require_inventory_permission(VIEW_MERCHANDISE_INVENTORY, 'View Inventory');
```

### Check Permissions in Code:
```php
// Simple check
if (can_approve_stock_request()) {
    // Show approve button
}

// Multiple checks
if (can_view_inventory('fuel') && can_export_inventory_reports()) {
    // Show export button
}
```

---

## 📋 Permission Checklist by Role

### ✅ STAFF Can:
- View fuel/merchandise inventory
- Search and filter items
- Monitor low stock
- Submit stock requests
- View history

### ❌ STAFF Cannot:
- Approve requests
- Adjust inventory
- Export reports
- View audit trail

---

### ✅ MANAGER Can:
- Everything Staff can do PLUS:
- Approve/reject stock requests
- Generate purchase orders
- Receive deliveries
- Stock-in inventory
- Make adjustments
- Conduct inventory counts
- Generate & export reports

### ❌ MANAGER Cannot:
- Rollback adjustments
- View full audit trail
- Backup system

---

### ✅ ADMIN Can:
- View all inventory (read-only)
- Monitor adjustments
- Rollback Manager adjustments
- View audit trail
- Backup inventory
- Generate all reports

### ❌ ADMIN Cannot:
- Submit stock requests
- Approve requests
- Perform operational tasks

---

## 🔧 Common Code Patterns

### Gate Entire Page:
```php
require_inventory_permission(VIEW_MERCHANDISE_INVENTORY, 'Access Inventory');
```

### Conditional Button:
```php
<?php if (can_submit_stock_request()): ?>
    <button onclick="submitRequest()">Submit Request</button>
<?php endif; ?>
```

### Multiple Permission Check:
```php
if (has_any_inventory_permission([APPROVE_STOCK_REQUEST, GENERATE_PURCHASE_ORDER])) {
    // User has at least one of these permissions
}
```

---

## 📂 File Mapping

| File | Permission Required |
|------|-------------------|
| `staff_inventory_merchandise.php` | `VIEW_MERCHANDISE_INVENTORY` |
| `manager_inventory_merchandise.php` | `APPROVE_STOCK_REQUEST` |
| `admin_inventory_audit_trail.php` | `VIEW_INVENTORY_AUDIT_TRAIL` |
| `manager_approve_stock_requests.php` | `APPROVE_STOCK_REQUEST` |

---

## 🎯 Function Reference

| Function | Returns | Description |
|----------|---------|-------------|
| `can_view_inventory($type)` | bool | Check view permission |
| `can_submit_stock_request()` | bool | Check submit permission |
| `can_approve_stock_request()` | bool | Check approval permission |
| `can_adjust_inventory()` | bool | Check adjustment permission |
| `can_rollback_adjustments()` | bool | Check rollback permission (Admin) |
| `can_view_audit_trail()` | bool | Check audit access |
| `get_allowed_inventory_actions()` | array | Get list of allowed actions |

---

## ⚡ Quick Examples

### Example 1: Staff Inventory Page
```php
<?php
require_once __DIR__ . '/../backend/inventory_permissions.php';
require_inventory_permission(VIEW_MERCHANDISE_INVENTORY, 'View Inventory');

// Show submit button only
<?php if (can_submit_stock_request()): ?>
    <button onclick="submitRequest()">Submit Request</button>
<?php endif; ?>
```

### Example 2: Manager Page
```php
<?php
require_once __DIR__ . '/../backend/inventory_permissions.php';
require_inventory_permission(APPROVE_STOCK_REQUEST, 'Approve Requests');

// Show manager controls
<?php if (can_approve_stock_request()): ?>
    <button class="approve">Approve</button>
    <button class="reject">Reject</button>
<?php endif; ?>

<?php if (can_adjust_inventory()): ?>
    <button onclick="adjustInventory()">Adjust</button>
<?php endif; ?>
```

### Example 3: Admin Oversight
```php
<?php
require_once __DIR__ . '/../backend/inventory_permissions.php';
require_inventory_permission(VIEW_INVENTORY_AUDIT_TRAIL, 'View Audit Trail');

// Show admin controls
<?php if (can_rollback_adjustments()): ?>
    <button onclick="rollback()">Rollback</button>
<?php endif; ?>

<?php if (can_backup_inventory()): ?>
    <button onclick="backup()">Backup</button>
<?php endif; ?>
```

---

## 🔐 Permission Constants

```php
// View/Monitor (All Roles)
VIEW_FUEL_INVENTORY
VIEW_MERCHANDISE_INVENTORY
SEARCH_FILTER_INVENTORY
VIEW_INVENTORY_DETAILS
LOW_STOCK_MONITORING
VIEW_INVENTORY_HISTORY

// Staff
SUBMIT_STOCK_REQUEST

// Manager Operations
APPROVE_STOCK_REQUEST
GENERATE_PURCHASE_ORDER
RECEIVE_DELIVERIES
STOCK_IN_INVENTORY
INVENTORY_ADJUSTMENT
INVENTORY_COUNT
GENERATE_INVENTORY_REPORTS
EXPORT_INVENTORY_REPORTS

// Admin Oversight
MONITOR_INVENTORY_ADJUSTMENTS
ROLLBACK_INVENTORY_ADJUSTMENTS
VIEW_INVENTORY_COUNT
VIEW_INVENTORY_AUDIT_TRAIL
BACKUP_INVENTORY
VIEW_INVENTORY_REPORTS_ADMIN
EXPORT_INVENTORY_REPORTS_ADMIN
```

---

## 📊 Testing Checklist

- [ ] Staff can view but not approve
- [ ] Manager can approve and adjust
- [ ] Admin can rollback and audit
- [ ] Unauthorized access shows proper error
- [ ] Menu items hidden based on role
- [ ] Buttons disabled/hidden appropriately

---

**For full documentation:** See `INVENTORY_PERMISSIONS_MATRIX.md`  
**For visual reference:** Open `INVENTORY_PERMISSIONS_VISUAL.html`  
**For implementation guide:** See `INVENTORY_PERMISSIONS_IMPLEMENTATION_SUMMARY.md`
