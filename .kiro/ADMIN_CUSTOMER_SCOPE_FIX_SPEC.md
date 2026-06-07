# Admin Customer Management - Scope Correction Spec

**Date**: June 6, 2026  
**Priority**: 🔴 CRITICAL - Security & Data Access Issue  
**Status**: 📋 Spec Created - Awaiting Implementation

---

## Problem Statement

The `admin_customer_management.php` module is currently querying **ALL customers across all stations** (global scope) for both Admin and SuperAdmin roles. This is **incorrect**.

### Current Incorrect Behavior:
```php
// ❌ WRONG - No station_id filter for Admin
SELECT * FROM customers WHERE 1=1  // Returns ALL customers from ALL stations
```

### Expected Correct Behavior:
```php
// ✅ Admin - Station-scoped
SELECT * FROM customers WHERE station_id = ?  // Only Admin's station

// ✅ SuperAdmin - Franchise-wide
SELECT * FROM customers  // All stations (no filter)
```

---

## Role Definitions

### Admin Role
- **Scope**: Station-specific ONLY
- **Data Access**: Customers assigned to Admin's station (`station_id = ?`)
- **Query Pattern**: `WHERE station_id = {admin_station_id}`
- **Purpose**: Manage customer profiles within assigned station
- **Actions**: 
  - View customers in own station
  - Edit customer info within own station
  - Monitor balances within own station
  - Re-assign customers within own station (NOT cross-station)

### SuperAdmin/Developer Role
- **Scope**: Franchise-wide (global)
- **Data Access**: ALL customers across ALL stations
- **Query Pattern**: No `station_id` filter
- **Purpose**: Franchise-wide oversight and configuration
- **Actions**:
  - View all customers across all stations
  - Assign/re-map customers across stations
  - Global reporting and analytics
  - Audit trail across franchise

---

## Required Code Changes

### File: `public/admin_customer_management.php`

#### 1. Add Role Detection Logic (Top of File)
```php
// Determine if user is SuperAdmin (global access) or Admin (station-scoped)
$is_superadmin = ($role === 'superadmin');
$is_admin = ($role === 'admin');
```

#### 2. Fix Customer List Query (Line ~145)
**Current (WRONG)**:
```php
if ($section === 'list') {
    $where  = "WHERE 1=1";
    $params = [];
    // ... filters ...
    $customers = adm_cust_rows($pdo,
        "SELECT c.id, c.name, ... FROM customers c ... $where ORDER BY c.name ASC", $params);
}
```

**Corrected**:
```php
if ($section === 'list') {
    // Admin: station-scoped | SuperAdmin: global
    $where  = $is_admin ? "WHERE c.station_id = :station_id" : "WHERE 1=1";
    $params = $is_admin ? [':station_id' => $station_id] : [];
    
    if ($search !== '') {
        $where .= " AND (c.name LIKE :q OR c.contact_number LIKE :q ...)";
        $params[':q'] = "%$search%";
    }
    if ($status_filter !== 'all') {
        $where .= " AND c.status = :status";
        $params[':status'] = $status_filter;
    }
    // Station filter only for SuperAdmin (Admin is already station-scoped)
    if ($is_superadmin && $station_filter > 0) {
        $where .= " AND c.station_id = :stn";
        $params[':stn'] = $station_filter;
    }
    
    $customers = adm_cust_rows($pdo, "SELECT c.id, ... FROM customers c ... $where ORDER BY c.name ASC", $params);
}
```

#### 3. Fix Customer Balances Query (Line ~178)
**Current (WRONG)**:
```php
$balance_customers = adm_cust_rows($pdo,
    "SELECT c.id, ... FROM customers c ... ORDER BY outstanding_balance DESC", []);
```

**Corrected**:
```php
$where_balance = $is_admin ? "WHERE c.station_id = ?" : "WHERE 1=1";
$params_balance = $is_admin ? [$station_id] : [];

$balance_customers = adm_cust_rows($pdo,
    "SELECT c.id, c.name, ... FROM customers c LEFT JOIN stations s ON s.id = c.station_id 
     $where_balance ORDER BY outstanding_balance DESC", $params_balance);
```

#### 4. Fix Accounts Receivable Query
Apply same pattern as balances.

#### 5. Fix Customer History Query
**Corrected**:
```php
if ($section === 'history') {
    // Admin: only customers from own station | SuperAdmin: all stations
    $where_hist = $is_admin ? "WHERE c.station_id = ?" : "WHERE c.status != 'archived'";
    $params_hist = $is_admin ? [$station_id] : [];
    
    $hist_customers = adm_cust_rows($pdo,
        "SELECT c.id, c.name, s.name AS station_name FROM customers c 
         LEFT JOIN stations s ON s.id = c.station_id 
         $where_hist ORDER BY c.name ASC", $params_hist);
}
```

#### 6. Fix Customer Oversight Query
**Corrected**:
```php
if ($section === 'oversight') {
    $where_oversight = $is_admin ? "WHERE c.station_id = ?" : "WHERE 1=1";
    $params_oversight = $is_admin ? [$station_id] : [];
    
    $oversight_customers = adm_cust_rows($pdo,
        "SELECT c.id, c.name, ... FROM customers c 
         LEFT JOIN stations s ON s.id = c.station_id
         $where_oversight ORDER BY s.name ASC, c.name ASC", $params_oversight);
}
```

#### 7. Fix POST Handlers (Lines ~56-115)
**Current (WRONG)**:
```php
// ❌ No station_id check - Admin can modify ANY customer
$stmt = $pdo->prepare("UPDATE customers SET credit_limit=? WHERE id=?");
```

**Corrected**:
```php
// Admin: can only modify customers in own station
// SuperAdmin: can modify any customer
if ($is_admin) {
    $stmt = $pdo->prepare("UPDATE customers SET credit_limit=? WHERE id=? AND station_id=?");
    $stmt->execute([$limit, $cid, $station_id]);
} else {
    $stmt = $pdo->prepare("UPDATE customers SET credit_limit=? WHERE id=?");
    $stmt->execute([$limit, $cid]);
}
```

Apply this pattern to:
- `adjust_credit_limit`
- `toggle_status`
- `reassign_station` (Admin should NOT be able to reassign - SuperAdmin only)
- `archive_customer`

---

## Subtitle Updates

### Admin (Station-Scoped):
```php
$section_descriptions = [
    'list'       => 'View and manage customer profiles within your station.',
    'balances'   => 'Monitor receivables and outstanding balances within your station.',
    'history'    => 'View transaction history within your station.',
    'oversight'  => 'Manage customer records within your station.',
];
```

### SuperAdmin (Franchise-Wide):
```php
$section_descriptions = [
    'list'       => 'Global access to all customer profiles across stations.',
    'balances'   => 'Monitor receivables and outstanding balances across the franchise.',
    'history'    => 'View full transaction history across all stations.',
    'oversight'  => 'Manage customer records (assign/re-map across stations, archive inactive).',
];
```

**Implementation**: Use conditional logic based on `$is_superadmin` to display correct subtitle.

---

## Station Filter Dropdown Behavior

### Admin:
- **Hide station filter** - Admin is already scoped to own station
- Display: "Station #X" (read-only, informational)

### SuperAdmin:
- **Show station filter dropdown** - Allow filtering by specific station
- Default: "All Stations"
- Options: List of all active stations

---

## Security Implications

### Current Risk (Before Fix):
- ❌ Admin can view customers from OTHER stations (data leak)
- ❌ Admin can modify credit limits for customers NOT in their station
- ❌ Admin can archive/deactivate customers from other stations
- ❌ Violates principle of least privilege

### After Fix:
- ✅ Admin restricted to own station data only
- ✅ SuperAdmin retains franchise-wide access
- ✅ Clear separation of duties
- ✅ Audit trail shows proper scoping

---

## Testing Checklist

### Test as Admin (Station-Scoped):
- ✅ Customer List shows only customers from Admin's station
- ✅ Customer Balances shows only balances from Admin's station
- ✅ Customer History selector shows only customers from Admin's station
- ✅ Customer Oversight shows only customers from Admin's station
- ✅ Station filter dropdown is HIDDEN
- ✅ Cannot edit customers from other stations (POST handlers check station_id)
- ✅ Cannot re-assign customers to other stations (action disabled for Admin)

### Test as SuperAdmin (Franchise-Wide):
- ✅ Customer List shows ALL customers from ALL stations
- ✅ Customer Balances shows franchise-wide balances
- ✅ Customer History selector shows customers from ALL stations
- ✅ Customer Oversight shows ALL customers
- ✅ Station filter dropdown is VISIBLE and functional
- ✅ Can edit ANY customer from ANY station
- ✅ Can re-assign customers across stations

---

## Migration Notes

### Database Changes:
- None required (schema is correct)

### Backward Compatibility:
- SuperAdmin behavior unchanged (still global)
- Admin behavior **CHANGES** from global to station-scoped (this is a fix, not a break)

### Deployment Steps:
1. Backup database
2. Deploy updated `admin_customer_management.php`
3. Test with Admin account (verify station scoping)
4. Test with SuperAdmin account (verify global access)
5. Update documentation to clarify Admin vs SuperAdmin roles

---

## Related Files

- ✅ `public/admin_customer_management.php` - PRIMARY FILE (needs fixes)
- ✅ Subtitles need conditional logic based on role
- ✅ POST handlers need station_id validation for Admin
- ✅ Station filter dropdown needs conditional visibility

---

## Summary

**Admin** = Station-specific management (like Manager, pero with oversight tools)  
**SuperAdmin** = Franchise-wide orchestrator (global visibility ug control)

Current implementation is **insecure** - Admin has unauthorized global access. This spec defines the fix to properly scope Admin to station-level while preserving SuperAdmin's franchise-wide capabilities.

---

**Priority**: 🔴 **CRITICAL** - Security issue, data leak risk  
**Estimated Effort**: 2-3 hours (comprehensive fix + testing)  
**Next Step**: Implement fixes outlined in this spec
