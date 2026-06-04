# 📌 Admin Fuel Adjustment Records – Complete Process

## Overview
The Admin Fuel Adjustment Records system provides oversight and accountability for all fuel inventory adjustments made by station managers. This ensures compliance, transparency, and proper documentation of all fuel-related modifications.

---

## Table Columns and Meanings

### Core Identification
- **ID** → Unique adjustment entry number (auto-generated)
- **Date** → When the adjustment was encoded by the manager
- **Station** → Station name (e.g., Vamenta Blvd., Carmen Station)

### Adjustment Details
- **Fuel Type** → Type of fuel being adjusted:
  - Diesel
  - Kerosene
  - Turbo Diesel
  - XCS
  - Xtra Advance

- **Adj. Type** → Category of adjustment:
  - **Tank Level Correction** → Physical tank reading adjustment
  - **Stock Discrepancy** → Variance between system and actual stock
  - **Price Update** → Price rollback or adjustment
  - **Delivery Adjustment** → Post-delivery corrections

- **Liters** → Quantity added (+) or deducted (-)
  - Positive values: Addition to inventory
  - Negative values: Deduction from inventory

- **Reason** → Detailed explanation for the adjustment
  - Example: "Delivery shortage noted during tank dipping"
  - Example: "Price rollback per DOE directive"
  - Example: "System discrepancy after pump calibration"

### Accountability Trail
- **Logged By** → Manager nga nag-encode sa adjustment
  - Shows manager name and user ID
  - Timestamp of when adjustment was created

- **Approved By** → Admin nga nag-validate/acknowledge sa adjustment
  - Initially NULL (pending approval)
  - Populated with admin name after approval

- **Approved At** → Timestamp sa admin validation
  - Date and time when admin approved the adjustment
  - NULL if still pending

---

## System Flow – Aha Mapadulok

### 1️⃣ Manager Encode Adjustment

**Location:** Manager Fuel Management Module

**Process:**
1. Manager navigates to Fuel Adjustments section
2. Selects fuel type needing adjustment
3. Chooses adjustment type from dropdown
4. Enters liters (+ for addition, - for deduction)
5. Provides detailed reason/explanation
6. Clicks "Submit Adjustment"

**System Action:**
- Record is created in `fuel_adjustments` table
- `logged_by` field = Current manager's user ID
- `approved_by` = NULL (pending)
- `approved_at` = NULL (pending)
- Status = "Pending Admin Approval"

**Business Rule:**
- Adjustment is recorded but **NOT yet applied to inventory**
- Inventory only updates after admin approval

---

### 2️⃣ Admin Oversight

**Location:** Admin Dashboard → Fuel Adjustment Records

**Process:**
1. Admin views all manager-encoded adjustments
2. Reviews adjustment details:
   - Station and fuel type
   - Adjustment type and amount
   - Manager's reason/explanation
   - Who logged it and when

3. Admin can:
   - **Approve** → Validates adjustment as legitimate
   - **Reject** → Denies adjustment with reason
   - **Query** → Request more information from manager

**System Action on Approval:**
- `approved_by` field = Current admin's user ID
- `approved_at` = Current timestamp
- Status = "Approved"
- **Inventory is updated** with the adjustment amount
- Audit log entry created

**System Action on Rejection:**
- Status = "Rejected"
- Rejection reason logged
- Manager notified
- Inventory remains unchanged

---

## Database Schema

### Table: `fuel_adjustments`

```sql
CREATE TABLE IF NOT EXISTS fuel_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    adjustment_date DATE NOT NULL,
    fuel_type VARCHAR(50) NOT NULL,
    adjustment_type VARCHAR(100) NOT NULL,
    liters DECIMAL(12,3) NOT NULL,
    reason TEXT NOT NULL,
    logged_by INT NOT NULL,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'Pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_station (station_id),
    INDEX idx_status (status),
    INDEX idx_date (adjustment_date),
    
    FOREIGN KEY (station_id) REFERENCES stations(id),
    FOREIGN KEY (logged_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Purpose: Compliance ug Accountability

### ✅ Compliance Benefits
1. **Full Audit Trail**
   - Every adjustment is documented
   - Who, what, when, why nga clear
   - Compliance with fuel management regulations

2. **Dual Authorization**
   - Manager proposes adjustment
   - Admin validates and approves
   - Prevents unauthorized inventory changes

3. **Historical Record**
   - All adjustments are permanently logged
   - Can be reviewed during audits
   - Supports regulatory reporting

### ✅ Accountability Benefits
1. **Clear Responsibility**
   - Logged By = Manager who initiated
   - Approved By = Admin who validated
   - No anonymous adjustments

2. **Timestamp Tracking**
   - When adjustment was requested
   - When it was approved
   - Duration of approval process

3. **Reason Documentation**
   - Every adjustment requires explanation
   - Helps identify patterns or issues
   - Supports decision-making

---

## User Interface Components

### Manager View
**Screen:** Manager Fuel Management → Adjustments Tab

**Features:**
- Adjustment entry form
- My pending adjustments list
- Adjustment history
- Status tracking (Pending/Approved/Rejected)

**Restrictions:**
- Can only create adjustments
- Cannot approve own adjustments
- Cannot edit after submission

---

### Admin View
**Screen:** Admin Dashboard → Fuel Adjustment Records

**Features:**
- Complete list of all adjustments (all stations)
- Filter by:
  - Station
  - Fuel type
  - Adjustment type
  - Status
  - Date range
- Approve/Reject actions
- Detailed view with full audit trail
- Export functionality (Excel, CSV, PDF)

**Capabilities:**
- View all adjustments system-wide
- Approve or reject with notes
- View complete history
- Generate compliance reports

---

## Export Formats

All three export formats include:
- Adjustment ID
- Date
- Station name
- Fuel type
- Adjustment type
- Liters (signed: +/-)
- Reason
- Logged by (Manager name)
- Approved by (Admin name or "Pending")
- Approved at (timestamp or "Pending")
- Current status

### Excel/CSV
- Suitable for further analysis
- Can be imported to accounting systems
- Supports filtering and pivot tables

### PDF
- Print-ready format
- Official documentation
- Suitable for physical filing

---

## Workflow Diagram

```
┌─────────────────────────────────────────────────────────┐
│  MANAGER: Fuel Adjustment Request                       │
├─────────────────────────────────────────────────────────┤
│  1. Select Fuel Type                                    │
│  2. Choose Adjustment Type                              │
│  3. Enter Liters (+/-)                                  │
│  4. Provide Reason                                      │
│  5. Submit                                              │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│  SYSTEM: Record Created                                 │
├─────────────────────────────────────────────────────────┤
│  - Status: Pending                                      │
│  - Logged By: Manager ID                                │
│  - Approved By: NULL                                    │
│  - Inventory: UNCHANGED                                 │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│  ADMIN: Review Adjustment                               │
├─────────────────────────────────────────────────────────┤
│  Option A: APPROVE                                      │
│    - Set Approved By = Admin ID                         │
│    - Set Approved At = NOW()                            │
│    - Update Status = "Approved"                         │
│    - Apply adjustment to inventory                      │
│    - Create audit log entry                             │
│                                                          │
│  Option B: REJECT                                       │
│    - Set Status = "Rejected"                            │
│    - Log rejection reason                               │
│    - Notify manager                                     │
│    - NO inventory change                                │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────┐
│  FINAL STATE: Adjustment Complete                       │
├─────────────────────────────────────────────────────────┤
│  - Full audit trail preserved                           │
│  - Inventory updated (if approved)                      │
│  - Compliance record maintained                         │
│  - Available for reporting                              │
└─────────────────────────────────────────────────────────┘
```

---

## Business Rules

### ✅ Required Fields
- Station ID (auto-populated)
- Adjustment Date (defaults to today)
- Fuel Type (must match available types)
- Adjustment Type (from predefined list)
- Liters (cannot be zero)
- Reason (minimum 10 characters)

### ✅ Validation Rules
1. **Authorization**
   - Only managers can create adjustments
   - Only admins can approve/reject
   - Cannot approve own adjustments

2. **Data Integrity**
   - Liters must be numeric
   - Positive/negative values allowed
   - Date cannot be in future

3. **Status Flow**
   - Pending → Approved ✅
   - Pending → Rejected ✅
   - Approved → Cannot change ❌
   - Rejected → Cannot change ❌

### ✅ Inventory Impact
- **Pending adjustments:** No inventory change
- **Approved adjustments:** Inventory updated immediately
- **Rejected adjustments:** No inventory change
- **Deleted adjustments:** Not allowed (audit trail)

---

## Security Considerations

### Access Control
- **Managers:** Create adjustments only
- **Admins:** View all, approve/reject
- **Staff:** No access to adjustments
- **Auditors:** Read-only access

### Audit Trail
- Every action is logged
- IP address recorded
- User agent captured
- Cannot be deleted or modified

### Data Integrity
- Foreign key constraints
- Transaction-based updates
- Rollback on failure
- Duplicate prevention

---

## Reporting Capabilities

### Available Reports
1. **Adjustment Summary**
   - Total adjustments by period
   - Breakdown by type
   - Pending vs approved count

2. **Station Performance**
   - Adjustments per station
   - Frequency analysis
   - Trend identification

3. **Compliance Report**
   - All adjustments with full details
   - Approval timeline metrics
   - Outstanding pending items

4. **Manager Activity**
   - Adjustments by manager
   - Approval rate
   - Common reasons

---

## Integration Points

### Fuel Inventory System
- Adjustments update `fuel_inventory` table
- Stock levels recalculated
- Triggers cascade to reports

### Audit Logs
- All actions recorded in `audit_logs`
- Includes before/after values
- Permanent record

### Manager Dashboard
- Pending adjustment count badge
- Quick access to adjustment history
- Status notifications

### Admin Dashboard
- Pending approvals count
- System-wide oversight
- Alert for overdue approvals

---

## Maintenance and Support

### Database Maintenance
- Regular backup of `fuel_adjustments` table
- Archive old records (retain forever)
- Index optimization monthly

### User Training
- Managers: How to create proper adjustments
- Admins: Approval criteria and best practices
- Auditors: How to generate reports

### Common Issues
1. **Pending too long** → Admin notification system
2. **Duplicate adjustments** → Validation rules
3. **Missing reasons** → Mandatory field enforcement

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 1.0 | 2026-06-04 | Initial documentation | System Team |

---

## Related Documents
- `DEPLOYMENT_STATUS_FINAL.md` - Overall system deployment
- `manager_fuel_management_complete.php` - Manager interface
- Admin dashboard specifications
- Fuel inventory management guide

---

## Contact and Support
For questions about fuel adjustment records:
- **Manager Issues:** Contact Admin team
- **Admin Access:** Contact System Administrator
- **Technical Issues:** Submit support ticket
- **Audit Requests:** Contact Compliance Officer

---

**End of Documentation**
