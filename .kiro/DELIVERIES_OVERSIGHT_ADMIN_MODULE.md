# Deliveries Oversight — Admin Module Documentation

## Overview
**Deliveries Oversight** is the Admin-level monitoring and validation module for all delivery records (both Fuel and Merchandise) that have been encoded by Staff and validated by Managers. This is the final approval stage in the 3-tier delivery workflow.

---

## 🎯 Module Purpose

Admin monitors **all deliveries** across the station to:
- ✅ **Final validation** of Manager-approved deliveries
- 🚩 **Flag discrepancies** (partial delivery, damaged items, wrong batch)
- 📋 **Audit trail** transparency (Staff → Manager → Admin)
- 📊 **Generate reports** for accountability and Petron Corporation records

---

## 📂 File Structure

### Frontend
- **File**: `public/admin_merchandise_deliveries_oversight.php`
- **URL**: `/public/admin_merchandise_deliveries_oversight.php`
- **Access**: Admin, SuperAdmin roles only

### Backend API
- **File**: `backend/api/admin_deliveries_oversight_api.php`
- **Actions**: `list`, `detail`, `validate`, `flag`, `export_excel`, `export_pdf`, `pending_count`

### Database
- **Table**: `deliveries_oversight`
- **Key Columns**:
  - `delivery_type` → `'fuel'` or `'merchandise'`
  - `status` → Status progression through workflow
  - `admin_id` → Admin who performed final oversight
  - `admin_action_at` → Timestamp of admin action
  - `admin_notes` → Admin remarks/reason for flag

---

## 🔄 3-Tier Workflow

```
┌──────────────┐      ┌──────────────┐      ┌──────────────┐
│   STAFF      │ ───► │   MANAGER    │ ───► │    ADMIN     │
│   Encodes    │      │  Validates   │      │  Oversights  │
└──────────────┘      └──────────────┘      └──────────────┘
     Record              Approve/Reject       Finalize/Flag
```

### Status Flow:
1. **Staff encodes** → `Pending Manager Approval`
2. **Manager validates** → `Confirmed` / `Validated`
3. **Admin oversights** → `Validated` (finalized) / `Flagged` (discrepancy)

**Guard**: Admin **cannot** validate deliveries still in `Pending Manager Approval` status — they must wait for Manager to act first.

---

## ✨ Features Implemented

### 1. **Delivery Records Table**
- 📋 **Columns**: Delivery ID, Type, DR Number, Supplier, Product, Quantity, Date, Encoded By, Status, Remarks, Actions
- 🔍 **Filters**:
  - Date range (From/To)
  - Status: All, Approved, Flagged, Expected, Pending
  - Type: All, Fuel, Merchandise
  - Supplier: Text search
- 🎨 **Status Badges**:
  - **Expected** → Blue badge (`badge-expected`)
  - **Pending Validation** → Yellow badge (`badge-pending`)
  - **Approved** → Green badge (`badge-validated`)
  - **Flagged** → Red badge (`badge-flagged`)

### 2. **Action Buttons**
Each delivery row has:
- 👁️ **View** → Shows full delivery details modal
- ✅ **Finalize** → Final validation (for Approved records)
- 🚩 **Flag** → Mark as discrepancy (requires reason)

### 3. **Detail Modal**
Shows comprehensive delivery information:
- Reference number (`delivery_ref`)
- Status with badge
- Type (Fuel/Merchandise)
- DR Number
- Supplier & Product
- Quantity with unit
- Delivery date
- Encoded by (Staff name)
- Admin name (if actioned)
- Action timestamp
- Notes/Remarks

### 4. **Validate/Finalize Modal**
- Displays delivery summary
- Optional notes field
- Updates status to `Validated`
- Records admin action in `admin_id` and `admin_action_at`
- Creates audit trail entry

### 5. **Flag Modal**
- **Required field**: Reason for flagging
- Use cases:
  - ❌ **Partial Delivery** → Kulang ang quantity
  - 🛠️ **Damaged Items** → May guba
  - 📦 **Wrong Batch** → Mali ang Batch ID or item
- Updates status to `Flagged`
- Stores reason in `admin_notes`
- Creates audit trail entry

### 6. **Compliance Alerts Panel**
- Auto-loads alerts for deliveries needing Admin attention
- Shows alert count
- Each alert has:
  - Warning icon
  - Title & description
  - "View" button to open detail modal
- Panel hidden if no alerts

### 7. **Stock-In Tracker Panel** (Merchandise)
- Shows finalized POs awaiting physical stock-in
- Displays:
  - PO number
  - Product name
  - Quantity ordered
  - Finalized by (Admin name)
  - "Go to Stock-In" link
- Panel hidden if no pending stock-ins

### 8. **Export & Reports**
- **Excel Export** → `.xls` file with delivery records
- **PDF Export** → Printable report with station name and date range
- Columns included: ID, Ref, Type, Supplier, Product, Qty, Date, Status, Notes
- Filters apply to exports (date range, status)

### 9. **Audit Trail**
- Every Admin action logged in `audit_trail` table:
  - Entity type: `'delivery'`
  - Action: `'Validate'` or `'Flag'`
  - Old value → New value
  - Timestamp and actor (Admin)
- Enables full transparency and accountability

---

## 🎨 UI/UX Design

### Color Scheme (Aligned with Petron Branding)
- **Primary Blue**: `#002F70` (Petron brand color)
- **Success Green**: `#28a745`
- **Danger Red**: `#dc3545`
- **Warning Orange**: `#fd7e14`
- **Gray**: `#6c757d`
- **Light**: `#f8f9fa`

### Components
- **Cards**: Clean white background with shadow
- **Buttons**: Rounded, icon + text, color-coded by action
- **Badges**: Pill-shaped, icon + text, status-specific colors
- **Modals**: Centered overlay with backdrop blur
- **Toast Notifications**: Bottom-right, auto-dismiss after 3.8s

### Responsive Design
- Mobile-friendly filter bar (stacks vertically)
- Detail grid adapts to single column on small screens
- Table horizontal scroll on overflow

---

## 🔒 Access Control

### Role Requirements
- **Allowed**: `admin`, `superadmin`
- **Blocked**: All other roles → Redirect to dashboard

### Station Validation
- Admin must have `station_id > 0`
- If `station_id <= 0` → Show "No Station Assigned" page

### Status Guards
- Admin **cannot** validate deliveries with status `Pending Manager Approval`
- Error message: *"This delivery is still pending Manager approval. Admin cannot validate it until the Manager has reviewed it first."*

---

## 📊 Status Indicators

| Status in DB | Display Label | Badge Color | Meaning |
|---|---|---|---|
| `Expected Delivery` | Expected | Blue | PO finalized, awaiting physical delivery |
| `Pending Manager Approval` | Pending Validation | Yellow | Staff encoded, waiting Manager review |
| `Pending Manager Confirmation` | Pending Validation | Yellow | Manager-queue |
| `Pending Validation` | Pending Validation | Yellow | Awaiting Admin oversight |
| `Confirmed` | Approved | Green | Manager approved |
| `Validated` | Approved | Green | Admin finalized |
| `Discrepancy` | Flagged | Red | Issue detected |
| `Flagged` | Flagged | Red | Admin flagged for review |

---

## 🛠️ Discrepancy Handling

### Common Scenarios

#### 1. **Partial Delivery** (Kulang ang quantity)
- **Action**: Flag with reason
- **Example**: "DR shows 5,000L but only 4,800L was actually delivered."
- **Admin Notes**: Stored in `admin_notes` field

#### 2. **Damaged Items** (May guba)
- **Action**: Flag with reason
- **Example**: "10 boxes received but 2 boxes are damaged and unusable."
- **Follow-up**: Manager/Staff can resubmit adjusted quantity

#### 3. **Wrong Batch/Item** (Mali ang item or Batch ID)
- **Action**: Flag with reason
- **Example**: "DR shows XCS-1422 but staff encoded XCS-1423 (wrong batch)."
- **Follow-up**: Staff can edit and resubmit

#### 4. **DR Mismatch** (DR number not matching)
- **Action**: Flag with reason
- **Example**: "Physical DR number is DR-98765 but encoded as DR-98755."

---

## 📈 Reports & Analytics

### Export Options
1. **Excel (.xls)**
   - Downloadable spreadsheet
   - Includes all filtered records
   - Ready for further analysis in Excel/Google Sheets

2. **PDF (Printable)**
   - Professional formatted report
   - Station name and date range header
   - Auto-prints on open
   - Suitable for physical filing

### Report Columns
- Delivery ID
- Reference Number
- Type (Fuel/Merchandise)
- DR Number
- Supplier
- Product
- Quantity + Unit
- Delivery Date
- Encoded By (Staff name)
- Status
- Admin Notes

### Use Cases
- **Monthly Reports** → All deliveries for accounting
- **Discrepancy Reports** → Flagged deliveries only
- **Supplier Analysis** → Filter by supplier name
- **Fuel vs Merchandise** → Separate reports by type

---

## 🔄 API Actions

### `list`
- **Method**: GET
- **Params**: `start`, `end`, `status`, `type`, `supplier`
- **Returns**: Array of delivery records with encoded/admin names
- **Default Filter**: Excludes `Pending Manager Approval` (Manager queue, not Admin queue)

### `detail`
- **Method**: GET
- **Params**: `id`
- **Returns**: Full delivery record + audit trail entries
- **Includes**: Staff encoder name, Admin name, Manager name

### `validate`
- **Method**: POST
- **Params**: `id`, `notes` (optional)
- **Action**: Sets status to `Validated`, records admin action
- **Guard**: Cannot validate if status is `Pending Manager Approval`
- **Audit**: Creates audit trail entry

### `flag`
- **Method**: POST
- **Params**: `id`, `reason` (required)
- **Action**: Sets status to `Flagged`, stores reason in `admin_notes`
- **Audit**: Creates audit trail entry with reason

### `export_excel`
- **Method**: GET
- **Params**: `start`, `end`, `status`
- **Returns**: `.xls` file download
- **Content-Type**: `application/vnd.ms-excel`

### `export_pdf`
- **Method**: GET
- **Params**: `start`, `end`, `status`
- **Returns**: HTML document with auto-print JavaScript
- **Content-Type**: `text/html`

### `pending_count`
- **Method**: GET
- **Returns**: Count of deliveries awaiting Admin oversight
- **Filter**: Statuses in `('Pending Admin Oversight', 'Pending Validation', 'Pending Manager Confirmation')`

---

## 🧪 Testing Checklist

### Basic Functionality
- [ ] Admin can view all deliveries (fuel + merchandise)
- [ ] Filters work correctly (date, status, type, supplier)
- [ ] View button opens detail modal with correct data
- [ ] Finalize button updates status to `Validated`
- [ ] Flag button requires reason and updates status to `Flagged`

### Access Control
- [ ] Non-admin roles are blocked from accessing page
- [ ] Admin without station_id sees "No Station" page
- [ ] Admin cannot validate `Pending Manager Approval` deliveries

### Reports
- [ ] Excel export downloads with correct data
- [ ] PDF export opens in new tab and auto-prints
- [ ] Exports respect filter selections

### UI/UX
- [ ] Status badges show correct colors
- [ ] Toast notifications appear and auto-dismiss
- [ ] Modals close on backdrop click or Cancel button
- [ ] Responsive design works on mobile devices

### Audit Trail
- [ ] Validate action creates audit entry
- [ ] Flag action creates audit entry with reason
- [ ] Audit entries show in detail view (if implemented)

---

## 🚀 Future Enhancements (Possible)

### 1. **Email Notifications**
- Notify Staff/Manager when Admin flags a delivery
- Email includes reason for flag and link to edit

### 2. **Batch Actions**
- Select multiple deliveries
- Bulk validate or bulk flag

### 3. **Dashboard Metrics**
- Total deliveries this month
- Flagged delivery percentage
- Average time from Staff encode to Admin finalize

### 4. **Advanced Filters**
- Date shortcuts: "This Week", "Last Month", "This Quarter"
- Multi-select for suppliers
- Quantity range filter

### 5. **Print Individual Delivery Receipt**
- Button on detail modal
- Formatted receipt with QR code
- Includes audit trail timeline

---

## 📝 User Instructions (Admin)

### How to Validate a Delivery
1. Open **Deliveries Oversight** from sidebar
2. Use filters to find the delivery (usually `Status: Approved`)
3. Click **"Finalize"** button on the row
4. Review delivery details in modal
5. Add optional notes (e.g., "Verified against physical DR")
6. Click **"Validate"** button
7. Status changes to `Validated` (green badge)

### How to Flag a Delivery
1. Open **Deliveries Oversight** from sidebar
2. Find the delivery with an issue
3. Click **"Flag"** button on the row
4. Enter reason for flagging (required field)
   - Example: "Quantity mismatch: Expected 5000L but received 4800L"
5. Click **"Flag Delivery"** button
6. Status changes to `Flagged` (red badge)
7. Manager/Staff can see the flag and take corrective action

### How to Export Reports
1. Set date range filters (From/To)
2. Select status filter if needed
3. Click **"Excel"** or **"PDF"** button (top-right corner)
4. File downloads or opens in new tab
5. For PDF: Auto-print dialog appears

---

## 🎓 Key Concepts

### Audit Trail Transparency
Every action is logged:
- **Who** performed the action (Staff/Manager/Admin)
- **What** changed (status before/after)
- **When** it happened (timestamp)
- **Why** (notes/reason field)

This ensures **accountability** and **transparency** across all delivery records.

### Manager-First Validation
Admin **cannot** skip the Manager validation step. This enforces proper workflow:
1. Staff encodes
2. Manager validates first
3. Admin performs final oversight

If Manager hasn't reviewed yet, Admin must wait.

### Separation of Concerns
- **Staff**: Record keeping (encode deliveries)
- **Manager**: First-line validation (approve/reject)
- **Admin**: Final oversight (finalize/flag)

Each role has distinct responsibilities and cannot bypass the hierarchy.

---

## 📌 Summary

The **Deliveries Oversight** module provides Admin with:
- ✅ Complete visibility of all delivery records
- ✅ Final validation and discrepancy flagging
- ✅ Full audit trail transparency
- ✅ Excel/PDF report generation
- ✅ Compliance alerts for attention-needed items
- ✅ Clean, professional UI aligned with Petron branding

**Status**: ✅ **Fully Implemented and Functional**

---

**Last Updated**: June 7, 2026  
**Module**: Admin Deliveries Oversight  
**Developer Notes**: This module is production-ready and follows the 3-tier workflow pattern established for both Fuel and Merchandise deliveries.
