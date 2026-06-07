# Admin Customer Module - Quick Reference Card

**Access**: Admin/SuperAdmin only  
**Location**: Admin Sidebar → Customers  
**Status**: ✅ LIVE & FUNCTIONAL

---

## 4 Sections at a Glance

### 1️⃣ Customer List (`?section=list`)
**What**: Consolidated customer directory  
**Actions**: Search, filter, adjust credit, toggle status  
**URL**: `admin_customer_management.php?section=list`

### 2️⃣ Customer Balances (`?section=balances`)
**What**: Financial oversight & receivables  
**Actions**: Monitor balances, flag overdue, adjust limits  
**URL**: `admin_customer_management.php?section=balances`

### 3️⃣ Customer History (`?section=history`)
**What**: Transaction audit trail  
**Actions**: View all transactions, select customer  
**URL**: `admin_customer_management.php?section=history`

### 4️⃣ Customer Oversight (`?section=oversight`)
**What**: Administrative operations  
**Actions**: Re-assign to station, archive customer  
**URL**: `admin_customer_management.php?section=oversight`

---

## Quick Actions

| Action | Section | Button/Modal |
|--------|---------|--------------|
| Search customer | List | Search box |
| Adjust credit limit | List, Balances | 🔧 Slider icon |
| Activate/Deactivate | List | ✅ / ❌ User icon |
| Re-assign station | Oversight | 🔄 Exchange icon |
| Archive customer | Oversight | 📦 Archive icon |
| View history | All | 🕐 History icon |

---

## Status Badges

| Badge | Color | Meaning |
|-------|-------|---------|
| ACTIVE | Green | Customer is active |
| INACTIVE | Red | Customer is inactive |
| ARCHIVED | Red | Customer is archived |

---

## Balance Flags

| Flag | Color | Condition |
|------|-------|-----------|
| Overdue | Red | Balance ≥ Credit Limit |
| Has Balance | Yellow | Balance > 0 but < Limit |
| Clear | Green | Balance = 0 |

---

## Utilization Bar Colors

| Color | Range | Risk Level |
|-------|-------|------------|
| 🟢 Green | 0-79% | Safe |
| 🟡 Yellow | 80-99% | Warning |
| 🔴 Red | 100%+ | Overdue |

---

## Transaction Types

| Type | Color | Source |
|------|-------|--------|
| Merchandise | Blue | `merchandise_transactions` |
| Job Order | Purple | `job_orders` |
| Payment | Green | `credit_payments` |

---

## POST Actions (AJAX)

```javascript
// Adjust credit limit
POST: action=adjust_credit_limit
Data: customer_id, credit_limit, note

// Toggle status
POST: action=toggle_status
Data: customer_id, status

// Re-assign station
POST: action=reassign_station
Data: customer_id, new_station_id

// Archive customer
POST: action=archive_customer
Data: customer_id
```

---

## Database Tables

- `customers` - Main customer data
- `stations` - Station assignments
- `merchandise_transactions` - Sales history
- `job_orders` - Service history
- `credit_payments` - Payment history

---

## Permissions Required

```php
$permissions = [
    'view_all_reports',
    'view_dashboard',
    'manage_all_users' // optional
];

$roles = ['admin', 'superadmin'];
```

---

## Keyboard Shortcuts

None currently - all actions via mouse/touch

---

## Mobile Support

✅ Responsive tables  
✅ Touch-friendly buttons  
✅ Modal dialogs work  
✅ Horizontal scroll on tables

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Sidebar not showing | Check role permissions |
| Section won't load | Clear browser cache |
| AJAX errors | Check browser console |
| Data not updating | Verify database connection |
| Archived customers showing | Filter by status |

---

## Common Workflows

### Adjust Customer Credit Limit
1. Go to Customer List or Customer Balances
2. Click 🔧 slider icon
3. Enter new limit and note
4. Click "Save"

### Re-assign Customer to Station
1. Go to Customer Oversight
2. Find customer
3. Click "Re-assign" button
4. Select new station
5. Confirm

### Archive Inactive Customer
1. Go to Customer Oversight
2. Find customer
3. Click "Archive" button
4. Confirm action

### View Customer Transaction History
1. Go to Customer History
2. Select customer from dropdown
3. Review transactions

---

## Support & Documentation

| Resource | Location |
|----------|----------|
| Full Documentation | `.kiro/ADMIN_CUSTOMERS_COMPLETE.md` |
| Implementation Summary | `.kiro/ADMIN_CUSTOMERS_IMPLEMENTATION_SUMMARY.md` |
| This Quick Reference | `.kiro/ADMIN_CUSTOMERS_QUICK_REFERENCE.md` |

---

## Version Info

**Version**: 1.0.0  
**Release Date**: June 6, 2026  
**Last Updated**: June 6, 2026  
**Status**: Production Ready

---

**Quick Start**: Login as Admin → Click "Customers" in sidebar → Select any of 4 sections → Start managing customers! 🚀
