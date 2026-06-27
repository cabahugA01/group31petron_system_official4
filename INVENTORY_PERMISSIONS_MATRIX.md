# Complete Inventory Permission Matrix

**Last Updated:** <?= date('F d, Y') ?>

## Overview
This document defines the complete role-based access control (RBAC) for the Inventory Management System across all user roles: Staff, Manager, and Admin.

---

## Permission Matrix

| Function | Staff | Manager | Admin |
|----------|-------|---------|-------|
| **View Fuel Inventory** | ✅ | ✅ | ✅ |
| **View Merchandise Inventory** | ✅ | ✅ | ✅ |
| **Search & Filter** | ✅ | ✅ | ✅ |
| **View Inventory Details** | ✅ | ✅ | ✅ |
| **Low Stock Monitoring** | ✅ | ✅ | ✅ |
| **Submit Stock Request** | ✅ | ✅ | ❌ |
| **Approve Stock Request** | ❌ | ✅ | ❌ |
| **Generate Purchase Order** | ❌ | ✅ | ❌ |
| **Receive Deliveries** | ❌ | ✅ | ❌ |
| **Stock-In Inventory** | ❌ | ✅ | ❌ |
| **Inventory Adjustment** | ❌ | ✅ | Monitor/Rollback |
| **Inventory Count** | ❌ | ✅ | View Only |
| **Inventory History** | ✅ | ✅ | ✅ |
| **Inventory Reports** | View Only | ✅ Generate & Export | ✅ Full Access |
| **Export Reports** | ❌ | ✅ | ✅ |
| **Audit Trail** | ❌ | ❌ | ✅ |
| **Backup Inventory** | ❌ | ❌ | ✅ |

---

## Role Definitions

### 1. STAFF
**Primary Function:** Monitoring & Requesting

**Permissions:**
- ✅ View fuel inventory (read-only)
- ✅ View merchandise inventory (read-only)
- ✅ Search and filter inventory items
- ✅ View inventory details (stock levels, status, etc.)
- ✅ Monitor low stock items
- ✅ Submit stock requests when inventory is low
- ✅ View inventory history (their own station)
- ✅ View inventory reports (read-only, no export)

**Key Workflow:**
1. Monitor inventory levels daily
2. Identify low stock or out-of-stock items
3. Submit stock request to Manager with justification
4. Track status of submitted requests

**Access Restrictions:**
- ❌ Cannot approve stock requests
- ❌ Cannot generate purchase orders
- ❌ Cannot receive deliveries
- ❌ Cannot perform stock-in operations
- ❌ Cannot make inventory adjustments
- ❌ Cannot conduct inventory counts
- ❌ Cannot export reports
- ❌ Cannot access audit trail

---

### 2. MANAGER
**Primary Function:** Operational Inventory Management

**Permissions:**
- ✅ All Staff permissions PLUS:
- ✅ Approve/Reject stock requests from Staff
- ✅ Generate purchase orders based on approved requests
- ✅ Receive and validate deliveries
- ✅ Perform stock-in operations (add inventory)
- ✅ Request and execute inventory adjustments
- ✅ Conduct physical inventory counts
- ✅ Generate inventory reports
- ✅ Export reports (Excel, CSV, PDF)
- ✅ View inventory movement history

**Key Workflow:**
1. **Stock Requests:** Review and approve/reject staff requests
2. **Purchase Orders:** Generate PO for approved requests → Forward to Admin
3. **Deliveries:** Validate delivery quantities, flag discrepancies
4. **Stock-In:** Encode received items into system
5. **Adjustments:** Physical count → Request adjustment if variance found
6. **Inventory Count:** Regular cycle counts and spot checks
7. **Reports:** Generate and export inventory reports for analysis

**Access Restrictions:**
- ❌ Cannot directly rollback approved adjustments (requires Admin)
- ❌ Cannot access full audit trail (Admin only)
- ❌ Cannot perform system backups

---

### 3. ADMIN (Owner/Administrator)
**Primary Function:** Oversight, Audit, and System Management

**Permissions:**
- ✅ View all inventory data (read-only for operational functions)
- ✅ Monitor all inventory activities
- ✅ View inventory adjustments (Monitor only)
- ✅ Rollback approved adjustments (with reason and password)
- ✅ View inventory count results
- ✅ Full access to audit trail
- ✅ View all inventory reports
- ✅ Export all reports
- ✅ System backup and restore

**Key Workflow:**
1. **Oversight:** Monitor all inventory activities across the station
2. **Audit Review:** Review audit trail for anomalies or unauthorized actions
3. **Adjustment Review:** Monitor physical count adjustments
4. **Rollback:** If Manager adjustment is incorrect, rollback with justification
5. **Reports:** Generate comprehensive reports for ownership review
6. **Backup:** Regular system backups for data integrity

**Access Restrictions:**
- ❌ Cannot submit stock requests (oversight role)
- ❌ Cannot approve stock requests (Manager function)
- ❌ Cannot generate POs (Manager function)
- ❌ Cannot receive deliveries (Manager function)
- ❌ Cannot perform stock-in (Manager function)
- ❌ Cannot directly adjust inventory (Monitor & Rollback only)
- ❌ Cannot conduct inventory count (View results only)

**Special Powers:**
- ✅ Rollback Adjustments: If Manager makes incorrect adjustment, Admin can reverse it with proper documentation
- ✅ Full Audit Access: Complete visibility into all inventory transactions
- ✅ System Backup: Ensure data integrity and disaster recovery

---

## Permission Constants (RBAC Implementation)

### Staff Permissions
```php
VIEW_FUEL_INVENTORY
VIEW_MERCHANDISE_INVENTORY
SEARCH_FILTER_INVENTORY
VIEW_INVENTORY_DETAILS
LOW_STOCK_MONITORING
SUBMIT_STOCK_REQUEST
VIEW_INVENTORY_HISTORY
```

### Manager Permissions (Includes all Staff permissions)
```php
APPROVE_STOCK_REQUEST
GENERATE_PURCHASE_ORDER
RECEIVE_DELIVERIES
STOCK_IN_INVENTORY
INVENTORY_ADJUSTMENT
INVENTORY_COUNT
GENERATE_INVENTORY_REPORTS
EXPORT_INVENTORY_REPORTS
```

### Admin Permissions (Oversight-focused)
```php
MONITOR_INVENTORY_ADJUSTMENTS
ROLLBACK_INVENTORY_ADJUSTMENTS
VIEW_INVENTORY_COUNT
VIEW_INVENTORY_AUDIT_TRAIL
BACKUP_INVENTORY
VIEW_INVENTORY_REPORTS_ADMIN
EXPORT_INVENTORY_REPORTS_ADMIN
```

---

## Usage in Code

### Check Permission
```php
// Example 1: Check if user can approve stock requests
if (has_permission(APPROVE_STOCK_REQUEST)) {
    // Show approve button
}

// Example 2: Check if user can view audit trail
if (has_permission(VIEW_INVENTORY_AUDIT_TRAIL)) {
    // Show audit trail tab
}

// Example 3: Check if user can rollback adjustments
if (has_permission(ROLLBACK_INVENTORY_ADJUSTMENTS)) {
    // Show rollback button with password verification
}
```

### Permission Gates in Files
```php
// In staff_inventory_merchandise.php
if (!has_permission(VIEW_MERCHANDISE_INVENTORY)) {
    header('Location: dashboard.php');
    exit;
}

// In manager_inventory_merchandise.php
if (!has_permission(APPROVE_STOCK_REQUEST)) {
    die('Access Denied: You do not have permission to approve stock requests.');
}

// In admin_inventory_merchandise.php
if (!has_permission(ROLLBACK_INVENTORY_ADJUSTMENTS)) {
    die('Access Denied: Rollback requires Admin privileges.');
}
```

---

## Audit Trail Requirements

### All Actions Must Be Logged:
1. **Stock Requests:** Staff ID, Product, Quantity, Timestamp
2. **Approvals/Rejections:** Manager ID, Request ID, Decision, Reason, Timestamp
3. **Purchase Orders:** Manager ID, PO Number, Items, Amounts, Timestamp
4. **Deliveries:** Manager ID, PO Reference, Received Qty, Discrepancies, Timestamp
5. **Stock-In:** Manager ID, Product, Quantity Added, Source, Timestamp
6. **Adjustments:** Manager ID, Product, Old Qty, New Qty, Variance, Reason, Timestamp
7. **Inventory Count:** Manager ID, Products Counted, Variances, Timestamp
8. **Rollbacks:** Admin ID, Adjustment ID, Old Value, Restored Value, Reason, Timestamp

### Log Function
```php
log_activity($pdo, $user_id, $action, $details);
```

---

## File Mapping

| Role | File | Permissions Required |
|------|------|---------------------|
| Staff | `staff_inventory_merchandise.php` | VIEW_MERCHANDISE_INVENTORY, SUBMIT_STOCK_REQUEST |
| Staff | `staff_inventory_fuel.php` | VIEW_FUEL_INVENTORY, SUBMIT_STOCK_REQUEST |
| Staff | `staff_stock_requests.php` | SUBMIT_STOCK_REQUEST, VIEW_INVENTORY_HISTORY |
| Manager | `manager_inventory_merchandise.php` | All Manager Inventory Permissions |
| Manager | `manager_inventory_fuel.php` | All Manager Inventory Permissions |
| Manager | `manager_approve_stock_requests.php` | APPROVE_STOCK_REQUEST |
| Manager | `manager_deliveries_management.php` | RECEIVE_DELIVERIES |
| Manager | `manager_stock_in.php` | STOCK_IN_INVENTORY |
| Admin | `admin_inventory_merchandise.php` | All Admin Inventory Permissions |
| Admin | `admin_inventory_fuel.php` | All Admin Inventory Permissions |
| Admin | `admin_inventory_audit_trail.php` | VIEW_INVENTORY_AUDIT_TRAIL |
| Admin | `admin_inventory_rollback.php` | ROLLBACK_INVENTORY_ADJUSTMENTS |

---

## Implementation Checklist

### Phase 1: RBAC Update ✅
- [x] Define inventory permission constants
- [x] Update Staff role permissions
- [x] Update Manager role permissions
- [x] Update Admin role permissions

### Phase 2: File Permission Gates
- [ ] Add permission checks to staff inventory files
- [ ] Add permission checks to manager inventory files
- [ ] Add permission checks to admin inventory files
- [ ] Update menu visibility based on permissions

### Phase 3: UI Updates
- [ ] Hide/show buttons based on permissions
- [ ] Display permission-appropriate messages
- [ ] Add "Access Denied" pages where needed

### Phase 4: Audit Trail
- [ ] Ensure all inventory actions are logged
- [ ] Create admin audit trail viewer
- [ ] Add rollback functionality with logging

### Phase 5: Testing
- [ ] Test Staff permissions (can only view & request)
- [ ] Test Manager permissions (full operational control)
- [ ] Test Admin permissions (oversight & rollback)
- [ ] Test cross-role scenarios

---

## Security Notes

1. **Admin Rollback:** Requires password re-verification and detailed reason
2. **Audit Immutability:** Audit logs cannot be deleted or modified
3. **Session Validation:** All inventory actions validate user session and role
4. **Station Isolation:** Users can only access their assigned station's inventory
5. **Transaction Safety:** All multi-step operations use database transactions

---

**End of Document**
