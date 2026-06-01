# Bugfix Requirements Document

## Introduction

The Admin role currently has a "Stock-In" tab visible in the sidebar navigation, pointing to `staff_stock_in.php`. This violates the intended role separation for the delivery flow. The Stock-In tab is the encoding step that directly updates inventory — it must be restricted to operational staff roles only (staff, cashier, pump_attendant). Admin's role is oversight and compliance (read-only), not encoding. Additionally, the access control guard in `staff_stock_in.php` incorrectly permits `admin` and `superadmin` to access the encoding page, and Requirement 6.8 in `requirements.md` incorrectly lists those roles as allowed.

The fix must remove the Stock-In sidebar link from the Admin dashboard, correct the server-side access guard in `staff_stock_in.php`, and update Requirement 6.8 to reflect the correct role set. Admin's read-only view of Stock-In records (for compliance/audit) must remain available through the existing Deliveries Oversight page — it is a separate concern from the encoding tab.

---

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN a user with role `admin` logs in THEN the system displays a "Stock-In" sidebar link pointing to `staff_stock_in.php` in the Admin dashboard navigation.

1.2 WHEN a user with role `admin` navigates to `staff_stock_in.php` THEN the system grants access and allows the admin to encode actual received items and update inventory.

1.3 WHEN a user with role `superadmin` navigates to `staff_stock_in.php` THEN the system grants access and allows the superadmin to encode actual received items and update inventory.

1.4 WHEN the access control list for the Stock_In_Tab is evaluated THEN the system includes `admin` and `superadmin` as permitted roles, contradicting the role separation design.

### Expected Behavior (Correct)

2.1 WHEN a user with role `admin` logs in THEN the system SHALL NOT display a "Stock-In" sidebar link in the Admin dashboard navigation.

2.2 WHEN a user with role `admin` navigates directly to `staff_stock_in.php` THEN the system SHALL redirect the user to `dashboard.php` with an access-denied response, preventing any encoding action.

2.3 WHEN a user with role `superadmin` navigates directly to `staff_stock_in.php` THEN the system SHALL redirect the user to `dashboard.php` with an access-denied response, preventing any encoding action.

2.4 WHEN the access control list for the Stock_In_Tab is evaluated THEN the system SHALL permit only users with roles `staff`, `cashier`, or `pump_attendant` to access the encoding page.

2.5 WHEN a user with role `admin` needs to view Stock-In records for compliance or audit THEN the system SHALL provide that read-only view through the existing Deliveries Oversight page (`admin_deliveries_oversight.php`), not through the Stock-In encoding tab.

### Unchanged Behavior (Regression Prevention)

3.1 WHEN a user with role `staff` navigates to `staff_stock_in.php` THEN the system SHALL CONTINUE TO grant access and allow encoding of actual received items.

3.2 WHEN a user with role `cashier` navigates to `staff_stock_in.php` THEN the system SHALL CONTINUE TO grant access and allow encoding of actual received items.

3.3 WHEN a user with role `pump_attendant` navigates to `staff_stock_in.php` THEN the system SHALL CONTINUE TO grant access and allow encoding of actual received items.

3.4 WHEN a user with role `manager` navigates to `staff_stock_in.php` THEN the system SHALL CONTINUE TO redirect the user to `dashboard.php` (manager does not encode Stock-In).

3.5 WHEN a user with role `admin` views the Deliveries Oversight page THEN the system SHALL CONTINUE TO display validated delivery and Stock-In records in read-only mode for compliance and audit purposes.

3.6 WHEN a Stock_In_Encoder submits a merchandise or fuel stock-in THEN the system SHALL CONTINUE TO update inventory and log the audit trail as before.

3.7 WHEN the Admin sidebar navigation is rendered THEN the system SHALL CONTINUE TO display all other Admin menu items (Dashboard, User Management, Staff Oversight, Transactions Oversight, Product & Pricing Management, Purchase Orders, Deliveries Oversight, Calendar, Reports, Audit Trail) without change.

---

## Bug Condition

**Bug Condition Function** — identifies inputs that trigger the bug:

```pascal
FUNCTION isBugCondition(X)
  INPUT: X of type UserSession
  OUTPUT: boolean

  // Returns true when the user is admin or superadmin attempting to access the Stock-In encoding tab
  RETURN X.role IN ('admin', 'superadmin')
END FUNCTION
```

**Property: Fix Checking** — correct behavior for buggy inputs:

```pascal
// Property: Fix Checking — Admin/Superadmin Stock-In Access Denied
FOR ALL X WHERE isBugCondition(X) DO
  result ← accessStockInTab'(X)
  ASSERT result.redirected = true
  ASSERT result.destination = 'dashboard.php'
  ASSERT result.sidebarContainsStockInLink = false
END FOR
```

**Property: Preservation Checking** — non-buggy inputs must be unaffected:

```pascal
// Property: Preservation Checking
FOR ALL X WHERE NOT isBugCondition(X) DO
  ASSERT accessStockInTab(X) = accessStockInTab'(X)
END FOR
```

Where `NOT isBugCondition(X)` covers roles: `staff`, `cashier`, `pump_attendant` (access granted) and `manager` (already redirected — unchanged).
