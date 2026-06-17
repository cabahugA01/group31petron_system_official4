# Export Functionality - Complete Implementation Guide

## Overview
Comprehensive export functionality has been implemented for all three user roles (Staff, Manager, Admin) to support record keeping, transparency, oversight, and documentation requirements.

---

## 🔹 STAFF SIDE EXPORTS

### 1. Job Order Tracker Export
**File:** `backend/export/export_job_order_tracker.php`

**Purpose:** Export service records for personal record keeping and transparency

**Features:**
- Service records with itemized details
- Vehicle information and service types
- Payment status tracking
- Balance due calculations
- Staff remarks and notes

**Formats Supported:**
- ✅ **Excel** (.xls) - Full formatted spreadsheet
- ✅ **CSV** (.csv) - Compatible with all spreadsheet software
- ✅ **PDF** - Printable receipts and reports

**Usage:**
```
GET /backend/export/export_job_order_tracker.php?format=excel&date_from=2026-01-01&date_to=2026-01-31
```

**Parameters:**
- `format` - excel | csv | pdf (default: excel)
- `date_from` - Start date (default: first day of current month)
- `date_to` - End date (default: today)

---

### 2. Merchandise History Export
**File:** `backend/export/export_staff_merchandise_history.php`

**Purpose:** Export itemized sales records and payment receipts

**Features:**
- Itemized product sales with quantities and prices
- Customer information
- Payment method and status
- Shift period tracking
- Staff remarks
- **Excludes job-order-linked transactions** (shown in Job Order Tracker instead)

**Formats Supported:**
- ✅ **Excel** (.xls) - Full formatted spreadsheet with green theme
- ✅ **CSV** (.csv) - Compatible with all spreadsheet software
- ✅ **PDF** - Printable payment receipts

**Usage:**
```
GET /backend/export/export_staff_merchandise_history.php?format=excel&date_from=2026-01-01&date_to=2026-01-31
```

**Parameters:**
- `format` - excel | csv | pdf (default: excel)
- `date_from` - Start date (default: first day of current month)
- `date_to` - End date (default: today)

**Security:**
- Only shows transactions created by the logged-in staff member
- Filtered by staff_id automatically

---

## 🔹 MANAGER SIDE EXPORTS

### 3. Pending Transactions Export
**File:** `backend/export/export_pending_transactions.php`

**Purpose:** Export list of all pending transactions awaiting validation

**Features:**
- Combined view of merchandise and job order transactions
- Pending validation status
- Customer and staff information
- Payment method tracking
- Search filtering support

**Formats Supported:**
- ✅ **Excel** (.xls) - Manager blue theme
- ✅ **CSV** (.csv) - Standard format
- ✅ **PDF** - Printable validation list

**Usage:**
```
GET /backend/export/export_pending_transactions.php?format=excel&search=customer_name
```

**Parameters:**
- `format` - excel | csv | pdf (default: excel)
- `search` - Optional filter by customer name, transaction ID, or vehicle plate

---

### 4. Validated Transactions Export
**File:** `backend/export/export_validated_transactions.php`

**Purpose:** Export approved/rejected transaction records

**Features:**
- Validated and approved transactions only
- Validator information (who approved)
- Payment tracking (amount, paid, balance)
- Validation timestamp
- Combined merchandise and job orders

**Formats Supported:**
- ✅ **Excel** (.xls) - Manager blue theme
- ✅ **CSV** (.csv) - Standard format
- ✅ **PDF** - Official validation records

**Usage:**
```
GET /backend/export/export_validated_transactions.php?format=excel
```

**Parameters:**
- `format` - excel | csv | pdf (default: excel)

---

### 5. Manager Shift Summary Reports (NEW)
**File:** `backend/export/export_manager_shift_summary.php`

**Purpose:** Daily/shift summary reports with Shift 1 & Shift 2 totals

**Features:**
- **Per-shift breakdown:**
  - Total fuel sales
  - Total merchandise sales
  - Service counts
  - Shift revenue totals
- **Top Services:** Most popular services by count and revenue
- **Top Items Sold:** Best-selling merchandise items
- **Payment Status Breakdown:** Paid vs Pending vs Utang
- **Overall Summary:** Grand totals across all shifts

**Format:**
- ✅ **PDF Only** - Official daily report format

**Usage:**
```
GET /backend/export/export_manager_shift_summary.php?date_from=2026-06-17&date_to=2026-06-17
```

**Parameters:**
- `date_from` - Report start date (default: today)
- `date_to` - Report end date (default: today)

**Report Sections:**
- Shift 1 Summary
- Shift 2 Summary
- (Additional shifts if configured)
- Overall Day Summary

---

## 🔹 ADMIN SIDE EXPORTS

### 6. Validated Transactions (Admin Oversight)
**File:** `backend/export/export_validated_transactions.php`

**Purpose:** System-wide validated transactions for admin oversight

**Features:**
- All stations' validated transactions
- Cross-station analysis capability
- Manager validation tracking
- Payment and balance tracking

**Formats Supported:**
- ✅ **Excel** (.xls)
- ✅ **CSV** (.csv)

**Usage:** Same as Manager side but with system-wide access

---

### 7. Receivables Aging Summary (NEW)
**File:** `backend/export/export_admin_receivables.php`

**Purpose:** Export all station receivables with aging analysis

**Features:**
- **Aging Buckets:**
  - 0-30 days
  - 31-60 days
  - Over 60 days (high priority)
- Customer credit limits
- Total outstanding balances
- Pending transaction counts
- Station-wise breakdown
- Contact information for collection follow-up

**Formats Supported:**
- ✅ **Excel** (.xls) - Full formatted report
- ✅ **CSV** (.csv) - Data export for analysis

**Usage:**
```
GET /backend/export/export_admin_receivables.php?format=excel&station_id=1
```

**Parameters:**
- `format` - excel | csv (default: excel)
- `station_id` - Optional filter by specific station

---

### 8. Variance Alerts Summary (NEW)
**File:** `backend/export/export_admin_variance_alerts.php`

**Purpose:** Export compliance alerts for audit and oversight

**Features:**
- **Alert Sources:**
  - Merchandise transaction variances (stock/amount mismatches)
  - Fuel delivery variances (quantity discrepancies)
  - Job order validation pending items
- **Alert Prioritization:**
  - 🔴 High Priority (red) - Variance exceeds thresholds
  - 🟡 Medium Priority (yellow) - Pending validation
  - 🔵 Low Priority (blue) - Standard review needed
- Station-wise breakdown
- Alert type categorization
- Compliance notes and recommendations

**Format:**
- ✅ **PDF Only** - Official compliance report

**Usage:**
```
GET /backend/export/export_admin_variance_alerts.php?date_from=2026-06-01&date_to=2026-06-17
```

**Parameters:**
- `date_from` - Report start date (default: first day of current month)
- `date_to` - Report end date (default: today)

---

### 9. Audit Trail Export
**File:** `backend/api/export_logs.php` (already exists)

**Purpose:** Export full chronological log for compliance audits

**Features:**
- Complete system activity log
- User actions tracking
- Timestamp and IP address
- Module and action details
- Security events

**Formats Supported:**
- ✅ **Excel** (.xls)
- ✅ **CSV** (.csv)
- ✅ **PDF** - Audit report format

**Access:** Available in Admin sidebar under "Compliance Reports"

---

## 📊 Export File Naming Conventions

All export files follow consistent naming patterns:

- **Staff Exports:**
  - `job_order_tracker_YYYY-MM-DD_HHMMSS.{ext}`
  - `merchandise_history_YYYY-MM-DD_HHMMSS.{ext}`

- **Manager Exports:**
  - `pending_transactions_YYYY-MM-DD_HHMMSS.{ext}`
  - `validated_transactions_YYYY-MM-DD.{ext}`
  - `Manager_Shift_Summary_YYYY-MM-DD.pdf`

- **Admin Exports:**
  - `admin_receivables_aging_YYYY-MM-DD_HHMMSS.{ext}`
  - `variance_alerts_summary_YYYY-MM-DD.pdf`
  - `audit_trail_YYYY-MM-DD.{ext}`

---

## 🔒 Security & Access Control

### Authentication
All export scripts enforce:
```php
require_login(); // Session-based authentication
```

### Role-Based Access
- **Staff exports:** Only show data created by the logged-in staff member
- **Manager exports:** Station-scoped (station_id from user session)
- **Admin exports:** System-wide access across all stations

### Data Filtering
- All queries use prepared statements (PDO)
- SQL injection protection
- Parameter validation
- Record limits (500-1000 rows) to prevent memory issues

---

## 🎨 Export Styling & Themes

### Staff Exports
- **Color Theme:** Green (#16a34a)
- **Purpose:** Personal records and transparency

### Manager Exports
- **Color Theme:** Blue (#002F70)
- **Purpose:** Station management and oversight

### Admin Exports
- **Color Theme:** Navy Blue (#002F70) / Red for alerts (#dc2626)
- **Purpose:** System-wide compliance and audit

---

## 📋 Implementation Checklist

### ✅ Completed Backend Files
- [x] `backend/export/export_job_order_tracker.php`
- [x] `backend/export/export_staff_merchandise_history.php`
- [x] `backend/export/export_pending_transactions.php`
- [x] `backend/export/export_validated_transactions.php`
- [x] `backend/export/export_manager_shift_summary.php`
- [x] `backend/export/export_admin_variance_alerts.php`
- [x] `backend/export/export_admin_receivables.php`

### 🔄 Frontend Integration (Next Steps)

**Staff Pages to Update:**
1. `public/staff_transactions_hub.php`
   - Add export buttons to Job Order Tracker section
   - Add export buttons to Merchandise History panel

**Manager Pages to Update:**
2. `public/manager_fuel_transaction_validation.php`
   - Export buttons already exist (pending/validated)
   
3. `public/manager_reports.php`
   - Add Shift Summary PDF export button

**Admin Pages to Update:**
4. Admin Dashboard/Oversight pages
   - Add Receivables export buttons
   - Add Variance Alerts export button
   - Audit Trail already has export functionality

---

## 🚀 Frontend Button Integration Examples

### Staff - Job Order Tracker Export Buttons
```html
<div class="export-actions" style="display:flex;gap:8px;margin:10px 0;">
    <button onclick="exportJobOrders('excel')" class="btn-export">
        <i class="fas fa-file-excel"></i> Export Excel
    </button>
    <button onclick="exportJobOrders('csv')" class="btn-export">
        <i class="fas fa-file-csv"></i> Export CSV
    </button>
    <button onclick="exportJobOrders('pdf')" class="btn-export">
        <i class="fas fa-file-pdf"></i> Export PDF
    </button>
</div>

<script>
function exportJobOrders(format) {
    const dateFrom = document.querySelector('#date_from')?.value || '<?= date('Y-m-01') ?>';
    const dateTo = document.querySelector('#date_to')?.value || '<?= date('Y-m-d') ?>';
    window.location.href = `backend/export/export_job_order_tracker.php?format=${format}&date_from=${dateFrom}&date_to=${dateTo}`;
}
</script>
```

### Manager - Shift Summary Export Button
```html
<button onclick="exportShiftSummary()" class="rpt-export-btn">
    <i class="fas fa-file-pdf"></i> Export Shift Summary (PDF)
</button>

<script>
function exportShiftSummary() {
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '<?= date('Y-m-d') ?>';
    const dateTo = document.querySelector('input[name="date_to"]')?.value || '<?= date('Y-m-d') ?>';
    window.open(`backend/export/export_manager_shift_summary.php?date_from=${dateFrom}&date_to=${dateTo}`, '_blank');
}
</script>
```

### Admin - Variance Alerts Export Button
```html
<button onclick="exportVarianceAlerts()" class="admin-export-btn">
    <i class="fas fa-exclamation-triangle"></i> Export Variance Alerts (PDF)
</button>

<script>
function exportVarianceAlerts() {
    const dateFrom = '<?= date('Y-m-01') ?>';
    const dateTo = '<?= date('Y-m-d') ?>';
    window.open(`backend/export/export_admin_variance_alerts.php?date_from=${dateFrom}&date_to=${dateTo}`, '_blank');
}
</script>
```

---

## 📝 Testing Checklist

### Staff Exports
- [ ] Test Job Order Tracker export with date range filters
- [ ] Verify Excel, CSV, and PDF formats
- [ ] Confirm only staff's own records are exported
- [ ] Test with empty result set (no data)
- [ ] Test Merchandise History with various filters
- [ ] Verify job-order-linked transactions are excluded

### Manager Exports
- [ ] Test Pending Transactions export with search filter
- [ ] Test Validated Transactions export
- [ ] Test Shift Summary report with multiple shifts
- [ ] Verify station-scoped data only
- [ ] Test with different date ranges
- [ ] Verify payment breakdown accuracy

### Admin Exports
- [ ] Test Receivables Aging across multiple stations
- [ ] Verify aging bucket calculations (0-30, 31-60, >60 days)
- [ ] Test Variance Alerts with different alert types
- [ ] Test station filter on receivables export
- [ ] Verify system-wide access works correctly
- [ ] Test Audit Trail export (existing functionality)

---

## 🐛 Troubleshooting

### Common Issues

**1. "No records found" error**
- Verify date range parameters are correct
- Check that the user has created/validated transactions in the period
- Ensure station_id is properly set in user session

**2. Memory limit errors on large exports**
- Exports are limited to 500-1000 records
- For larger datasets, use date range filters
- Consider chunking for very large reports

**3. CSV encoding issues (special characters)**
- All CSV exports use UTF-8 with BOM (`\uFEFF`)
- Properly opens in Excel with correct character encoding

**4. PDF not printing correctly**
- Use the "Print / Save as PDF" button in the browser
- Ensure print styles are loaded (@media print)
- Legal paper size is default (can be changed in @page CSS)

---

## 📚 Additional Documentation

Related documentation files:
- `ADMIN_REPORTS_DOCUMENTATION.md` - Admin reporting system
- `ADMIN_OVERSIGHT_FEATURES_COMPLETE.md` - Admin oversight features
- `MANAGER_REPORTS_COMPLETE.md` - Manager reporting system

---

## ✅ Summary

All export functionality has been successfully implemented:

**Staff Side:** ✅ Complete
- Job Order Tracker exports (Excel/CSV/PDF)
- Merchandise History exports (Excel/CSV/PDF)

**Manager Side:** ✅ Complete
- Pending Transactions exports (Excel/CSV/PDF)
- Validated Transactions exports (Excel/CSV/PDF)
- Shift Summary Reports (PDF) - NEW

**Admin Side:** ✅ Complete
- Validated Transactions (Excel/CSV)
- Receivables Aging (Excel/CSV) - NEW
- Variance Alerts Summary (PDF) - NEW
- Audit Trail (Excel/CSV/PDF) - Already exists

---

**Next Step:** Integrate export buttons into the frontend pages listed in the Implementation Checklist section.

**Date Completed:** June 17, 2026
**Implemented By:** Kiro AI Development Assistant
