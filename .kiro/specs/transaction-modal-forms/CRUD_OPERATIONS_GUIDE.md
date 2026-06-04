# Transaction Module CRUD Operations Guide (NO DELETE)

## Overview

The Transaction Module implements **CRU operations only** - Create, Read, Update. **NO DELETE** operations are allowed at any role level to maintain complete audit trail and data integrity.

**Key Principle:** Once created, transaction records are PERMANENT. They can be updated (adjusted, rejected, approved) but NEVER deleted.

---

## 🔐 CRUD Matrix by Role

| Operation | Staff | Manager | Admin | Notes |
|-----------|-------|---------|-------|-------|
| **Create** | ✅ | ❌ | ❌ | Staff encode new transactions |
| **Read** | ✅ | ✅ | ✅ | All roles can view transactions |
| **Update** | ✅ (Limited) | ✅ (Validation) | ✅ (Compliance) | Different update permissions |
| **Delete** | ❌ | ❌ | ❌ | NO role can delete transactions |

---

## 📝 STAFF SIDE - CRU Operations

### **CREATE (C)**

#### Staff Merchandise Transaction Modal
**Purpose:** Encode new merchandise sales

**Fields:**
- Customer Details (First Name, Last Name, Contact)
- Product Selection (Product, Quantity, Unit Price, Category)
- Payment Information (Method, Status, Amount Paid)

**Actions:**
- ✅ **Submit Transaction** → Creates new record with `validation_status='Pending'`
- ❌ **NO Delete button**

**Database Operation:**
```sql
INSERT INTO merchandise_transactions 
(transaction_id, customer_name, total_amount, payment_method, 
 payment_status, validation_status, staff_id, station_id, created_at)
VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?, NOW())
```

---

#### Staff Job Order Transaction Modal
**Purpose:** Encode new service transactions

**Fields:**
- Customer Details
- Vehicle Information (Type, Plate Number)
- Service Details (Service Type, Fee, Mechanic, Notes)
- Payment Information (Method, Status, Downpayment, Balance)

**Actions:**
- ✅ **Submit Job Order** → Creates new record with `validation_status='Pending Validation'`
- ❌ **NO Delete button**

**Database Operation:**
```sql
INSERT INTO job_orders 
(customer_name, vehicle_plate, service_type, total_cost, payment_method,
 payment_status, validation_status, status, created_by, station_id, created_at)
VALUES (?, ?, ?, ?, ?, ?, 'Pending Validation', 'Pending', ?, ?, NOW())
```

---

### **READ (R)**

#### Staff View Transaction Modal (Read-Only)
**Purpose:** View transaction details from Job Order Tracker

**Displays:**
- Transaction ID, Customer, Date
- Products/Services with quantities and prices
- Payment Method, Payment Status, Amount Paid, Balance Due
- Current Status (Pending Validation / In Progress / Completed)
- Staff Encoder Name

**Actions:**
- ❌ **NO Edit button** (read-only view)
- ❌ **NO Delete button**
- ✅ **Close button** (return to Job Order Tracker)

**Access:** Staff can only view their own encoded transactions

---

### **UPDATE (U)** - Limited

#### Staff Update Transaction Modal
**Purpose:** Correct mistakes BEFORE manager validation

**Allowed Updates:**
- ✅ Fix wrong plate number
- ✅ Correct product quantity
- ✅ Add/update downpayment
- ✅ Update Payment Status (Pending → Paid, Utang → Partial)
- ✅ Fix customer contact information

**Restrictions:**
- ❌ Cannot update AFTER manager validation (status ≠ 'Pending')
- ❌ Cannot change transaction after approval/rejection
- ❌ Cannot delete products/services (only adjust quantities)

**Actions:**
- ✅ **Update Transaction** → Updates record, keeps `validation_status='Pending'`
- ❌ **NO Delete button**

**Database Operation:**
```sql
UPDATE merchandise_transactions 
SET vehicle_plate = ?, 
    total_amount = ?, 
    payment_status = ?,
    amount_paid = ?,
    updated_at = NOW()
WHERE id = ? 
  AND staff_id = ?
  AND validation_status = 'Pending'
```

**Validation Rule:**
```php
// Staff can only update their OWN transactions that are still PENDING
if ($transaction['staff_id'] != $current_user_id) {
    throw new Exception('Cannot update other staff transactions');
}
if ($transaction['validation_status'] != 'Pending') {
    throw new Exception('Cannot update validated transactions');
}
```

---

## 👔 MANAGER SIDE - CRU Operations

### **CREATE (C)**
❌ **Managers CANNOT create transactions** - only Staff can encode

---

### **READ (R)**

#### Manager Validation Modal (Read with Actions)
**Purpose:** Review pending transactions for validation

**Displays:**
- Transaction Summary (ID, Type, Date, Status)
- Customer & Staff Information
- Transaction Details (Products/Services table)
- Payment Information (Method, Status, Amounts)

**Actions:**
- ✅ **Approve** (UPDATE operation)
- ✅ **Adjust** (UPDATE operation)
- ✅ **Reject** (UPDATE operation)
- ❌ **NO Delete button**

---

### **UPDATE (U)** - Validation Operations

#### 1. Approve Transaction
**Purpose:** Validate correct transactions

**Database Operation:**
```sql
UPDATE merchandise_transactions 
SET validation_status = 'Approved',
    validated_by = ?,
    validated_at = NOW(),
    updated_at = NOW()
WHERE id = ? AND station_id = ?
```

**Audit Trail:**
```sql
INSERT INTO audit_trail 
(transaction_id, manager_id, action_type, station_id, created_at)
VALUES (?, ?, 'Approve', ?, NOW())
```

**Note:** Original transaction data is PRESERVED - only status changes

---

#### 2. Adjust Transaction (Manager Adjust Modal)
**Purpose:** Correct pricing errors or calculation mistakes

**Allowed Updates:**
- ✅ Adjust total amount (with reason)
- ✅ Update remarks (required explanation)

**Restrictions:**
- ❌ Cannot delete transaction
- ❌ Cannot change products/services (only total amount)
- ✅ Original data is preserved in audit trail

**Database Operation:**
```sql
UPDATE merchandise_transactions 
SET total_amount = ?,
    validation_status = 'Adjusted',
    validated_by = ?,
    validated_at = NOW(),
    remarks = CONCAT('ADJUSTED: ', ?),
    updated_at = NOW()
WHERE id = ? AND station_id = ?
```

**Audit Trail:**
```sql
INSERT INTO audit_trail 
(transaction_id, manager_id, action_type, new_value, station_id, created_at)
VALUES (?, ?, 'Adjust', ?, ?, NOW())
```

---

#### 3. Reject Transaction (Manager Reject Modal)
**Purpose:** Return incorrect transactions to staff for correction

**Allowed Updates:**
- ✅ Set validation_status to 'Rejected'
- ✅ Add rejection reason (required, min 20 chars)

**Restrictions:**
- ❌ **TRANSACTION IS NOT DELETED** - remains in database
- ✅ Staff can see rejection reason and re-submit corrected transaction
- ✅ Original transaction preserved for audit trail

**Database Operation:**
```sql
UPDATE merchandise_transactions 
SET validation_status = 'Rejected',
    validated_by = ?,
    validated_at = NOW(),
    rejection_reason = ?,
    updated_at = NOW()
WHERE id = ? AND station_id = ?
```

**Important:** Rejected transactions remain in the database with status 'Rejected' - they are NOT deleted

---

## 🔍 ADMIN SIDE - CRU Operations

### **CREATE (C)**
❌ **Admins CANNOT create transactions** - only Staff can encode

---

### **READ (R)**

#### Admin Oversight Modal (Read-Only)
**Purpose:** View validated transactions with complete audit trail

**Displays:**
- Transaction Header (ID, Type, Status, Date)
- Customer & Transaction Details (full data)
- Payment Information
- Validation Information (Staff encoder, Manager validator)
- **Audit Trail Timeline** (complete history)
- Variance Report (if flagged)
- Export Options

**Actions:**
- ✅ **Export as Excel** (READ operation, generates report)
- ✅ **Export as PDF** (READ operation, generates report)
- ✅ **Export as CSV** (READ operation, generates report)
- ❌ **NO Edit button**
- ❌ **NO Delete button**

**Access:** Admin can view ALL validated transactions across all stations

---

### **UPDATE (U)** - Compliance Operations

#### Admin Compliance Updates
**Purpose:** Update administrative fields for compliance tracking

**Allowed Updates:**
- ✅ Add compliance notes
- ✅ Update receivables status (mark Paid when customer pays balance)
- ✅ Flag variance reports
- ✅ Update investigation notes

**Restrictions:**
- ❌ Cannot change transaction amounts
- ❌ Cannot change validation status
- ❌ Cannot delete transaction
- ❌ Cannot modify staff/manager data

**Database Operation:**
```sql
-- Example: Update receivables status
UPDATE merchandise_transactions 
SET payment_status = 'Paid',
    amount_paid = total_amount,
    compliance_notes = ?,
    updated_at = NOW()
WHERE id = ?
  AND validation_status IN ('Approved', 'Completed')
```

**Audit Trail:**
```sql
INSERT INTO audit_trail 
(transaction_id, admin_id, action_type, new_value, created_at)
VALUES (?, ?, 'Update Compliance', ?, NOW())
```

---

## 🚫 NO DELETE POLICY

### Why NO Delete?

1. **Audit Trail Integrity** - Complete transaction history required for compliance
2. **Financial Accountability** - All monetary transactions must be traceable
3. **Legal Compliance** - Regulatory requirements for record retention
4. **Data Forensics** - Ability to investigate discrepancies or fraud
5. **Customer History** - Complete customer transaction history for service

### Instead of Delete:

| Scenario | Solution |
|----------|----------|
| Wrong transaction entered | **Staff Update** (before validation) or **Manager Reject** |
| Duplicate transaction | **Manager Reject** with reason "Duplicate" |
| Cancelled sale | **Manager Reject** with reason "Sale Cancelled" |
| Pricing error | **Manager Adjust** with corrected amount |
| Fraudulent transaction | **Manager Reject** + **Admin Flag** for investigation |

### Status Flow (No Delete Path):

```
Staff Encode
    ↓
Pending Validation
    ↓
Manager Review
    ├─→ Approved → Completed (KEPT)
    ├─→ Adjusted → Completed (KEPT)
    └─→ Rejected (KEPT, status='Rejected')
```

**All paths KEEP the transaction record in the database.**

---

## 📋 Modal Forms - CRUD Operations Summary

### Staff Modals

| Modal | CRUD Operation | Database Action |
|-------|----------------|-----------------|
| **Create Transaction Modal** | **C** - Create | INSERT new transaction |
| **Update Transaction Modal** | **U** - Update | UPDATE (Pending only) |
| **View Transaction Modal** | **R** - Read | SELECT with details |

### Manager Modals

| Modal | CRUD Operation | Database Action |
|-------|----------------|-----------------|
| **Validation Modal** | **R** - Read | SELECT pending transactions |
| **Validation Modal - Approve** | **U** - Update | UPDATE status to 'Approved' |
| **Adjust Modal** | **U** - Update | UPDATE amount + status to 'Adjusted' |
| **Reject Modal** | **U** - Update | UPDATE status to 'Rejected' (NOT DELETE) |

### Admin Modals

| Modal | CRUD Operation | Database Action |
|-------|----------------|-----------------|
| **Oversight Modal** | **R** - Read | SELECT validated transactions |
| **Oversight Modal - Export** | **R** - Read | SELECT + generate file |
| **Oversight Modal - Compliance** | **U** - Update | UPDATE compliance fields only |

---

## 🔒 Database-Level Protection

### Soft Delete Implementation
**DO NOT USE** hard deletes. If archiving is needed:

```sql
-- Add deleted_at column (if archiving needed in future)
ALTER TABLE merchandise_transactions 
ADD COLUMN archived_at DATETIME NULL DEFAULT NULL;

-- Archive (NOT delete)
UPDATE merchandise_transactions 
SET archived_at = NOW(),
    archived_by = ?
WHERE id = ?;

-- Query active transactions
SELECT * FROM merchandise_transactions 
WHERE archived_at IS NULL;
```

### Prevent Accidental Deletes

```sql
-- Remove DELETE permissions from application user
REVOKE DELETE ON merchandise_transactions FROM 'petron_app_user'@'localhost';
REVOKE DELETE ON job_orders FROM 'petron_app_user'@'localhost';

-- Only superadmin can delete (for maintenance, not regular operations)
```

---

## ✅ Implementation Checklist

### Staff Modals
- [ ] Create Transaction Modal - INSERT operation only
- [ ] Update Transaction Modal - UPDATE only if status='Pending'
- [ ] View Transaction Modal - SELECT only (read-only)
- [ ] ❌ NO Delete button in any modal
- [ ] Validate: Cannot update after manager validation

### Manager Modals
- [ ] Validation Modal - SELECT pending transactions
- [ ] Approve action - UPDATE status to 'Approved'
- [ ] Adjust Modal - UPDATE amount + remarks (reason required)
- [ ] Reject Modal - UPDATE status to 'Rejected' (NOT DELETE)
- [ ] ❌ NO Delete button in any modal
- [ ] Validate: Rejected transactions remain in database

### Admin Modals
- [ ] Oversight Modal - SELECT validated transactions only
- [ ] Export functions - SELECT + file generation
- [ ] Compliance updates - UPDATE compliance fields only
- [ ] ❌ NO Delete button in any modal
- [ ] ❌ NO Edit transaction data allowed
- [ ] Validate: Admin cannot modify transaction amounts

### Database Protection
- [ ] Remove DELETE permissions from app database user
- [ ] Add audit_trail logging for all UPDATE operations
- [ ] Validate all UPDATE operations check proper status
- [ ] Test: Attempt to delete should fail with permission error

---

## 🎯 Key Principles

1. **Create Once** - Staff create transactions once
2. **Update Status** - Manager/Admin update status and compliance fields
3. **Never Delete** - No role can delete transactions
4. **Audit Everything** - All changes logged to audit_trail
5. **Preserve History** - Original data preserved even when adjusted
6. **Status Flow** - Pending → Approved/Adjusted/Rejected (all kept)

---

**Version:** 1.0  
**Last Updated:** June 3, 2026  
**Status:** MANDATORY - All implementations MUST follow NO DELETE policy
