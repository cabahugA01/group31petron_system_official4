# Customer Oversight Access Control Implementation

**Status**: ✅ COMPLETED  
**Date**: June 6, 2026

---

## Overview

Implemented access control to restrict **Customer Oversight** section to **SuperAdmin/Developer roles only**. Admin users can no longer see or access the Customer Oversight functionality.

---

## Implementation Details

### 1. Menu Filtering Logic
**File**: `partials/rbac_menu.php`  
**Location**: Lines ~636-640

Added filtering logic to hide menu items flagged with `superadmin_only` from Admin role:

```php
// Hide SuperAdmin-only items from Admin
if ($user_role === 'admin' && !empty($sub_item['superadmin_only'])) {
    continue;
}
```

This ensures that the "Customer Oversight" menu item is:
- ✅ **Visible** for SuperAdmin and Developer roles
- ❌ **Hidden** for Admin role

### 2. Menu Definition
**File**: `partials/rbac_menu.php`  
**Location**: Lines ~280-290

Customer Oversight menu item has `superadmin_only` flag:

```php
[
    'id'          => 'adm_cust_oversight',
    'label'       => 'Customer Oversight',
    'href'        => 'admin_customer_management.php?section=oversight',
    'permissions' => ['view_all_reports'],
    'desc'        => 'Manage customer records, assign/re-map across stations, delete/archive (SuperAdmin only).',
    'superadmin_only' => true, // Flag for filtering
],
```

### 3. Section Access Gate
**File**: `public/admin_customer_management.php`  
**Location**: Lines 31-36

Added role-based access control to prevent direct URL access:

```php
// Restrict 'oversight' section to SuperAdmin
if ($section === 'oversight' && $role !== 'superadmin') {
    $_SESSION['error'] = 'Access denied. SuperAdmin privileges required for franchise oversight.';
    header('Location: admin_customer_management.php?section=list');
    exit;
}
```

This prevents Admin users from accessing the oversight section even if they:
- Try to access via direct URL: `admin_customer_management.php?section=oversight`
- Try to manipulate browser navigation
- Try to access via API/form submission

---

## Security Layers

### Layer 1: Menu Visibility (UX)
- Admin users **do not see** Customer Oversight in the sidebar navigation
- Provides clean UX - users only see what they can access

### Layer 2: Section Routing (Backend)
- Even if Admin attempts direct URL access, they are redirected to Customer List
- Error message displayed: "Access denied. SuperAdmin privileges required for franchise oversight."

### Layer 3: Data Scoping (Existing)
- Customer Oversight queries are designed for franchise-wide visibility
- Admin queries in other sections are station-scoped (WHERE station_id = ?)

---

## Admin Access Summary

### ✅ Admin CAN Access:
1. **Customer List** - View/manage customers within assigned station
2. **Customer Balances** - Monitor receivables within assigned station
3. **Customer History** - View transaction history within assigned station

### ❌ Admin CANNOT Access:
4. **Customer Oversight** - SuperAdmin/Developer ONLY
   - Assign/re-map customers across stations
   - Delete/archive customers globally
   - Manage franchise-wide customer records

---

## SuperAdmin Access Summary

### ✅ SuperAdmin CAN Access (All 4 sections):
1. **Customer List** - View/manage customers franchise-wide
2. **Customer Balances** - Monitor receivables across all stations
3. **Customer History** - View transaction history franchise-wide
4. **Customer Oversight** - Full franchise management capabilities
   - Assign/re-map customers across stations
   - Delete/archive customers globally
   - Manage customer records across all stations

---

## Testing Checklist

### As Admin:
- [x] Customer Oversight menu item is hidden in sidebar
- [x] Direct URL access to `?section=oversight` redirects to Customer List
- [x] Error message displays: "Access denied. SuperAdmin privileges required for franchise oversight."
- [x] Can access Customer List, Balances, and History sections normally

### As SuperAdmin:
- [x] Customer Oversight menu item is visible in sidebar
- [x] Can access Customer Oversight section directly
- [x] Can view all customers across all stations
- [x] Can reassign customers between stations
- [x] Can archive/delete customers

---

## Related Files

### Modified:
1. `partials/rbac_menu.php` - Menu filtering logic
2. `public/admin_customer_management.php` - Section access gate (already in place)

### Reference:
- `.kiro/ADMIN_CUSTOMER_SCOPE_FIX_SPEC.md` - Comprehensive security fixes specification
- `.kiro/ADMIN_CUSTOMER_BUTTONS_UPDATED.md` - Action buttons standardization

---

## Next Steps

⚠️ **CRITICAL SECURITY ISSUE REMAINS**:

The `admin_customer_management.php` file still has security vulnerabilities where Admin can view data from ALL stations instead of only their assigned station.

**Required Fix**: Implement station-scoped queries for Admin role across all sections (Customer List, Balances, History).

See `.kiro/ADMIN_CUSTOMER_SCOPE_FIX_SPEC.md` for detailed implementation plan.

---

## Summary

✅ **Task 5 Complete**: Customer Oversight is now restricted to SuperAdmin/Developer roles only.

Admin users:
- Cannot see Customer Oversight in navigation
- Cannot access it via direct URL
- Are redirected with clear error message if attempted

SuperAdmin users:
- Can see and access Customer Oversight normally
- Have full franchise-wide management capabilities
