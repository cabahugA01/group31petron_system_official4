# Complete Transaction Module Flow - Final Scope

## ✅ Confirmed Requirements

### 🔹 Staff Side

#### Transaction Encoding
- **Job Order (service only)** - Service transactions with optional parts
- **Merchandise only** - Product sales transactions  
- **Job Order + Merchandise combo** - Combined transactions

#### Immediate Reflection
- **Job Order Tracker** - Real-time service transaction list (kung service)
- **Merchandise History** - Real-time merchandise transaction list (kung merchandise only)

#### Automatic Updates
- **Inventory Deduction** - Auto-update stock/fuel items upon encoding
- **Audit Trail Entry** - Staff ID + timestamp logged automatically

#### Export Options (CONFIRMED SCOPE)
✅ **Service records** (Excel/CSV) - For Job Order transactions
✅ **Receipts** (PDF) - Individual transaction receipts

#### Status Visibility (STAFF VIEW)
✅ **In Progress** - Transactions currently being encoded or pending validation
✅ **Completed** - Validated/approved transactions
✅ **Rejected** - Rejected transactions

❌ **NO Pending counter** (validation role - Manager only)
❌ **NO Approved counter** (validation role - Manager only)

#### Remarks Field
✅ **Optional remarks** - Staff can add general notes/comments
❌ **NO approval/rejection reasons** (Manager/Admin only)

---

### 🔹 Manager Side

#### Pending Transactions Table
- **Columns**: TXN ID, Customer, Type, Vehicle, Items/Parts, Service, Amount, Payment, Status, Date, Staff
- **Actions**: Approve / Reject / Adjust buttons
- **Bulk Actions**: Multi-select for batch approve/reject

#### Shift Summary Reports (NEW)
- **Daily totals** per Shift 1 and Shift 2
- **Breakdown**: Sales, Services, Top Items, Paid vs Pending vs Utang

#### Validation Notes (MANAGER/ADMIN ONLY)
✅ **Approval remarks** - Optional notes when approving
✅ **Rejection reason** - Required when rejecting  
✅ **Adjustment notes** - Required when adjusting

#### System Updates
- **Reports updated** (sales, service, inventory)
- **Audit Trail log** with Manager ID + timestamp
- **Dashboard sync** - Real-time refresh after validation

#### Export Options (CONFIRMED SCOPE)
✅ **Pending list** (Excel/CSV) - All pending transactions
✅ **Validated records** (Excel/CSV) - Approved/adjusted transactions
✅ **Summary reports** (PDF) - Shift summary reports

---

### 🔹 Admin Side

#### Oversight Dashboard
- **Validated Transactions table** - All approved/adjusted transactions across stations
- **Variance Alerts summary** - Integrated alerts for discrepancies
- **Inventory Impact column** - Shows items deducted per transaction
- **Receivables Aging** - Due date + overdue days for credit transactions
- **Validation Notes** - Displays Manager's approval/rejection/adjustment notes

#### Performance Metrics Panel
- **Total Sales** - Sum of validated transactions
- **Total Services** - Count of completed Job Orders
- **Top Items Sold** - Most sold products by quantity
- **Staff Top Encoder** (optional) - Highest transaction encoder

#### Audit Trail Sidebar (Standalone)
- **Full chronological compliance log**
- **Accessible via**: Compliance Reports → Audit Trail tab
- **Filters**: Date range, user, action type, station
- **Search**: Full-text search on details

#### Export Options (CONFIRMED SCOPE)
✅ **Validated transactions** (Excel/CSV) - Approved/adjusted transaction records
✅ **Audit Trail log** (Excel/CSV/PDF) - Full compliance audit log

❌ **REMOVED: Receivables summary** (Excel/CSV) - NOT in export scope
❌ **REMOVED: Variance alerts** (PDF) - NOT in export scope

---

## 📋 Summary of Changes

### ✅ What's INCLUDED:
1. Staff export limited to **Service records (Excel/CSV)** and **Receipts (PDF)**
2. Manager export includes **Pending list**, **Validated records** (Excel/CSV), and **Summary reports (PDF)**
3. Admin export limited to **Validated transactions (Excel/CSV)** and **Audit Trail (Excel/CSV/PDF)**
4. Remarks field **optional for Staff**, **required for Manager/Admin** on approve/reject/adjust
5. Staff view shows **In Progress, Completed, Rejected** only

### ❌ What's REMOVED:
1. ~~Pending/Approved counters on Staff side~~ (validation role - Manager only)
2. ~~Receivables summary export~~ (no compliance exports)
3. ~~Variance alerts export~~ (no compliance exports)
4. ~~Dashboard summary cards with counts~~ (no counters)

---

## 🎯 Role Separation Summary

| Feature | Staff | Manager | Admin |
|---------|-------|---------|-------|
| **Encode Transactions** | ✅ | ❌ | ❌ |
| **View Pending** | ❌ | ✅ | ✅ |
| **Approve/Reject/Adjust** | ❌ | ✅ | ✅ |
| **Approval/Rejection Reasons** | ❌ | ✅ | ✅ |
| **Export Service Records** | ✅ (Excel/CSV) | ✅ | ✅ |
| **Export Receipts** | ✅ (PDF) | ✅ | ✅ |
| **Export Pending List** | ❌ | ✅ (Excel/CSV) | ✅ |
| **Export Validated Records** | ❌ | ✅ (Excel/CSV) | ✅ (Excel/CSV) |
| **Export Shift Summary** | ❌ | ✅ (PDF) | ✅ |
| **Export Audit Trail** | ❌ | ❌ | ✅ (Excel/CSV/PDF) |
| **View In Progress** | ✅ | ✅ | ✅ |
| **View Completed** | ✅ | ✅ | ✅ |
| **View Rejected** | ✅ | ✅ | ✅ |
| **View Pending (Counter)** | ❌ | ✅ | ✅ |
| **View Approved (Counter)** | ❌ | ✅ | ✅ |

---

## ✅ Final Export Matrix

### Staff Exports
- Service Records (Excel) ✅
- Service Records (CSV) ✅
- Receipt (PDF) ✅

### Manager Exports  
- Pending List (Excel) ✅
- Pending List (CSV) ✅
- Validated Records (Excel) ✅
- Validated Records (CSV) ✅
- Shift Summary (PDF) ✅

### Admin Exports
- Validated Transactions (Excel) ✅
- Validated Transactions (CSV) ✅
- Audit Trail (Excel) ✅
- Audit Trail (CSV) ✅
- Audit Trail (PDF) ✅

---

**Document Created:** June 17, 2026  
**Spec Location:** `.kiro/specs/complete-transaction-module-flow/requirements.md`
