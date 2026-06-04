# Transaction Approval Visibility Fix

## Problem Statement
When a manager approves a transaction (merchandise or job order) in the **Pending Transactions** page, it updates the `validation_status` to 'Approved'. However, these approved transactions are not properly visible to staff in their viewing interfaces:

1. **Job Order Tracker** - Staff should see approved job orders
2. **Merchandise History** - Staff should see approved merchandise transactions

Currently, approved transactions may not be displaying correctly in these staff views, causing confusion about which transactions have been validated.

## Current Flow
1. Staff encodes a transaction (merchandise or job order)
2. Transaction is created with `validation_status` = 'Pending' or 'Pending Validation'
3. Manager reviews in **Pending Transactions** page
4. Manager approves → `validation_status` = 'Approved', `validated_by` = manager ID, `validated_at` = NOW()
5. **ISSUE**: Staff cannot see these approved transactions clearly in their views

## Required Outcome
After a manager approves a transaction, it must:

✅ **Appear in Manager's "Validated Transactions" page** (already working)
✅ **Appear in Staff's "Job Order Tracker"** (needs verification/fix)
✅ **Appear in Staff's "Merchandise History"** (needs verification/fix)
✅ **Show clear validation status badges** (Approved, Validated, etc.)
✅ **Allow staff to track progress of approved job orders**
✅ **Allow staff to view payment/completion status**

## Success Criteria

### For Job Orders:
- [ ] When manager approves job order → `validation_status` = 'Approved'
- [ ] Job order appears in staff's **Job Order Tracker** tab
- [ ] Shows validation status badge: "Approved" (green)
- [ ] Staff can update workflow status (In Progress → Completed)
- [ ] Staff can process payments
- [ ] Approved job orders are distinct from pending validation ones

### For Merchandise Transactions:
- [ ] When manager approves merchandise transaction → `validation_status` = 'Approved'
- [ ] Transaction appears in staff's **Merchandise History** panel
- [ ] Shows validation status badge: "Approved" (green)
- [ ] Staff can view transaction details
- [ ] Staff can process remaining payments if partial

### UI Indicators:
- [ ] Green badge for "Approved" status
- [ ] Blue badge for "Validated" status
- [ ] Amber/yellow badge for "Pending Validation" status
- [ ] Red badge for "Rejected" status

## Technical Details

### Tables Involved:
1. **`merchandise_transactions`**
   - `validation_status` column (Pending, Approved, Rejected, Adjusted)
   - `validated_by` column (manager user ID)
   - `validated_at` column (timestamp)

2. **`job_orders`**
   - `validation_status` column (Pending Validation, Approved, Rejected, Adjusted)
   - `validated_by` column (manager user ID)
   - `validated_at` column (timestamp)
   - `status` column (Pending, In Progress, Completed, Cancelled)

### Files to Review/Update:
1. `public/staff_transactions_hub.php` - Staff view for transactions
2. `public/staff_dashboard.php` - Dashboard widgets showing job orders
3. `backend/job_order_operations.php` - Job order data fetching
4. Any AJAX endpoints that fetch job order/merchandise data for staff

## User Stories

### As a Staff Member:
1. **View Approved Job Orders**
   - GIVEN a manager has approved my job order
   - WHEN I open the Job Order Tracker tab
   - THEN I should see the job order with "Approved" badge
   - AND I can update its status to "In Progress" or "Completed"

2. **View Approved Merchandise Transactions**
   - GIVEN a manager has approved my merchandise transaction
   - WHEN I check the Merchandise History panel
   - THEN I should see the transaction with "Approved" badge
   - AND I can view all transaction details

### As a Manager:
1. **Track Approved Transactions**
   - GIVEN I have approved transactions
   - WHEN I view Validated Transactions page
   - THEN I should see all approved transactions
   - AND the staff should also be able to see them in their respective views

## Out of Scope
- Changing the approval workflow logic
- Adding new validation statuses
- Modifying the payment processing logic
- Changing the approval permissions/RBAC

## Constraints
- Must maintain existing database schema
- Must not break existing manager approval workflow
- Must preserve audit trail (validation logs)
- Must support both `merchandise_transactions` and `job_orders` tables
