# Transaction Module - Complete Fetch Process Reference Guide

## Overview

This document clarifies the complete fetch process for the Transaction Module across all three user roles (Staff, Manager, Admin), detailing where data originates, how it flows through the system, and what operations each role can perform.

---

## 🔵 Staff Side

### 1. Job Order Tracker

**Source of Fetch:**
- Data originates from the **Job Order Form** encoded by Staff
- Direct input from Staff service encoding

**Data Reflection:**
- Auto-appears in Tracker list
- Real-time reflection upon form submission
- Database: stored in `job_orders` table with `validation_status='Pending Validation'`

**Update Options:**
- Staff can update **workflow status**:
  - `Pending` → `In Progress` → `Completed`
- Once marked as **Completed**:
  - Payment modal automatically opens
  - Staff encodes payment status:
    - `Pending Payment` (partial/downpayment)
    - `Paid` (full payment)
  - Payment data auto-reflects in **Customer Module**

**Data Flow:**
```
Job Order Form (Staff Input)
    ↓
Job Order Tracker (auto-appear)
    ↓
Status Updates (Pending → In Progress → Completed)
    ↓
Payment Modal (on Completed)
    ↓
Customer Module (payment reflection)
```

---

### 2. Merchandise History

**Source of Fetch:**
- Data originates from the **Merchandise Form** encoded by Staff
- Direct input from Staff merchandise sales

**Data Reflection:**
- Auto-appears in History list
- Real-time reflection upon form submission
- Database: stored in `merchandise_transactions` table with `validation_status='Pending'`

**Update Options:**
- Staff can update **payment status**:
  - `Partial` → `Paid`

**Data Flow:**
```
Merchandise Form (Staff Input)
    ↓
Merchandise History (auto-appear)
    ↓
Payment Status Updates (Partial → Paid)
```

---

## 🟢 Manager Side

### 1. Pending Transactions

**Source of Fetch:**
- Data originates from:
  - **Staff Job Order Tracker** (encoded job orders)
  - **Staff Merchandise History** (encoded merchandise sales)
- Aggregates all staff-submitted transactions awaiting validation

**Data Reflection:**
- Auto-appears as Pending Transactions
- **30-second auto-refresh polling** for near real-time updates
- No manual refresh button needed
- Database: queries records with `validation_status='Pending'` or `'Pending Validation'`

**Update Options:**
- Manager can perform the following actions:
  - **Approve** - validates the transaction
  - **Reject** - declines the transaction with reason
  - **Adjust** - modifies transaction details before approval
  - **View** - inspects full transaction details

**Data Flow:**
```
Staff Job Order Tracker + Merchandise History
    ↓
Manager Pending Transactions (auto-appear)
    ↓
Manager Actions (Approve/Reject/Adjust/View)
    ↓
Validated Transactions (if approved)
```

---

### 2. Validated Transactions

**Source of Fetch:**
- Data originates from **Manager-approved Pending Transactions**
- Only transactions that passed Manager validation

**Data Reflection:**
- Auto-moves to Validated list upon Manager approval
- Real-time reflection after approval action
- Database: records with `validation_status='Approved'`
- Read-only view (Manager can only View/Export, not modify)

**Update Options:**
- Manager can:
  - **View** - review validated transaction details
  - **Export** - generate reports of validated transactions

**Data Flow:**
```
Manager Pending Transactions (Approved)
    ↓
Validated Transactions (auto-move)
    ↓
Manager Actions (View/Export)
```

---

### 3. Variance Reports

**Source of Fetch:**
- Data originates from **system-flagged anomalies**:
  - Stock mismatches
  - Pump reading discrepancies
  - Service fee errors
- System automatically detects inconsistencies

**Data Reflection:**
- Auto-appears in Variance Reports tab
- Real-time flagging when anomalies are detected
- Database: stored in `fuel_variance_reports` table
- Threshold: variance >5% triggers auto-creation

**Update Options:**
- Manager can:
  - **Acknowledge** - mark variance as reviewed
  - **Export** - generate variance reports

**Data Flow:**
```
System Anomaly Detection
    ↓
Variance Reports (auto-appear)
    ↓
Manager Actions (Acknowledge/Export)
```

---

## 🔴 Admin Side

### 1. Oversight Dashboard

**Source of Fetch:**
- Data originates from **Manager Validated Transactions**
- System-wide consolidation across all stations

**Data Reflection:**
- Auto-appears in Oversight Dashboard with aggregated totals:
  - **Validated Transactions** - total count and value
  - **Pending Payments** - outstanding partial payments
  - **Outstanding Utang** - credit/debt tracking
  - **Receivables Aging** - payment timeline analysis
- **60-second auto-refresh polling** (appropriate for oversight level)
- **IMPORTANT:** Admin can ONLY see Manager-validated transactions (status='Approved')
- Raw staff 'Pending' records are NOT visible to Admin (must go through Manager first)

**Update Options:**
- Admin can:
  - **View** - inspect consolidated data
  - **Acknowledge** - mark records as reviewed
  - **Export** - generate system-wide reports

**Data Flow:**
```
Manager Validated Transactions (All Stations)
    ↓
System-wide Consolidation
    ↓
Admin Oversight Dashboard (auto-appear with totals)
    ↓
Admin Actions (View/Acknowledge/Export)
```

---

### 2. Variance Reports

**Source of Fetch:**
- Data originates from **system-wide anomalies** flagged across all stations
- Aggregates variance reports from all Manager modules

**Data Reflection:**
- Auto-appears in Admin Variance Reports tab
- Real-time flagging when system-wide anomalies are detected
- Database: consolidated view from `fuel_variance_reports` across all stations
- Shows variance status: Open, Under Investigation, Resolved

**Update Options:**
- Admin can:
  - **Acknowledge** - mark variance as reviewed at system level
  - **Export** - generate system-wide variance reports

**Data Flow:**
```
System-wide Anomaly Detection (All Stations)
    ↓
Admin Variance Reports (auto-appear)
    ↓
Admin Actions (Acknowledge/Export)
```

---

## 📊 Complete Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                          STAFF LEVEL                             │
├─────────────────────────────────────────────────────────────────┤
│  Job Order Form → Job Order Tracker (auto-appear)               │
│  Merchandise Form → Merchandise History (auto-appear)           │
└────────────────────────────┬────────────────────────────────────┘
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│                         MANAGER LEVEL                            │
├─────────────────────────────────────────────────────────────────┤
│  Staff Forms → Pending Transactions (auto-appear)               │
│       ↓                                                          │
│  Manager Approve → Validated Transactions (auto-move)           │
│                                                                  │
│  System Anomalies → Variance Reports (auto-appear)              │
└────────────────────────────┬────────────────────────────────────┘
                             ↓
┌─────────────────────────────────────────────────────────────────┐
│                          ADMIN LEVEL                             │
├─────────────────────────────────────────────────────────────────┤
│  Manager Validated → Oversight Dashboard (auto-consolidate)     │
│                                                                  │
│  System-wide Anomalies → Variance Reports (auto-appear)         │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🔑 Key Principles

1. **Auto-Refresh Mechanism** - Automatic polling ensures near real-time updates:
   - Manager Pending Transactions: 30-second refresh
   - Admin Oversight Dashboard: 60-second refresh
   - Modal-aware: pauses auto-refresh when user is interacting
2. **Validation Workflow** - Strict approval chain: Staff → Manager → Admin
3. **Hierarchical Flow** - Data flows upward from Staff → Manager → Admin
4. **Role-Based Actions** - Each role has specific operations they can perform
5. **System Automation** - Anomalies and variances are auto-detected and flagged
6. **Database-Driven** - All data stored in relational tables with audit trails

---

## 📝 Summary Table

| Role | Module | Data Source | Auto-Refresh | Database Table | Update Operations |
|------|--------|-------------|--------------|----------------|-------------------|
| **Staff** | Job Order Tracker | Job Order Form (Staff input) | Real-time | `job_orders` | Update status, Encode payment |
| **Staff** | Merchandise History | Merchandise Form (Staff input) | Real-time | `merchandise_transactions` | Update payment status |
| **Manager** | Pending Transactions | Staff Job Orders + Merchandise | 30 seconds | `job_orders` + `merchandise_transactions` (status='Pending') | Approve, Reject, Adjust, View |
| **Manager** | Validated Transactions | Manager-approved Pending | On-demand | `job_orders` + `merchandise_transactions` (status='Approved') | View, Export |
| **Manager** | Variance Reports | System-flagged anomalies | On-demand | `fuel_variance_reports` | Investigate, Resolve, Export |
| **Admin** | Oversight Dashboard | Manager Validated (system-wide) | 60 seconds | `job_orders` + `merchandise_transactions` (validated only) | View, Approve, Return, Export |
| **Admin** | Variance Reports | System-wide anomalies | On-demand | `fuel_variance_reports` (all stations) | Acknowledge, Export |

---

## 🔧 Technical Implementation Details

### Database Tables
- **`merchandise_transactions`** - Merchandise sales (validation_status: Pending/Approved/Rejected/Adjusted)
- **`job_orders`** - Service orders (validation_status: Pending Validation/Approved/Rejected)
- **`fuel_variance_reports`** - Fuel discrepancy tracking (status: Open/Under Investigation/Resolved)
- **`customers`** - Credit customer integration
- **`audit_trail`** - Transaction action history

### Payment Status Logic
```
if (amount_paid >= total_amount) → 'Paid'
elseif (amount_paid > 0) → 'Partial Payment'
else → 'Pending Payment'
```

### Auto-Refresh Implementation
- Manager Pending: `setInterval(refreshFunction, 30000)` - 30 seconds
- Admin Oversight: `setInterval(refreshFunction, 60000)` - 60 seconds
- Pauses when modal is open (prevents data loss during user interaction)

### Key Files
- Staff: `staff_transactions_hub.php`
- Manager: `pending_transactions.php`, `manager_validated_transactions.php`
- Admin: `admin_transactions_oversight.php`
- Backend: `get_transaction_details.php`, `export_validated_transactions.php`

---

**Document Version:** 1.1  
**Last Updated:** June 4, 2026  
**Verified Against:** Actual codebase implementation  
**Purpose:** Complete reference guide for Transaction Module fetch process across all roles
