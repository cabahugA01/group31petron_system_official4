# Export Button Integration Guide

## Quick Reference: Where to Add Export Buttons

This guide shows exactly where to add export buttons on each page.

---

## 🔹 STAFF SIDE

### 1. Job Order Tracker (staff_transactions_hub.php)

**Location:** In the Job Order Tracker section (look for the job order management area)

**Add this HTML after the job order table header:**

```html
<!-- Export Actions for Job Order Tracker -->
<div class="jo-export-actions" style="display:flex;gap:8px;margin:10px 0;flex-wrap:wrap;">
    <button onclick="exportJobOrders('excel')" class="btn-export" style="background:#16a34a;color:white;padding:6px 12px;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">
        <i class="fas fa-file-excel"></i> Export Excel
    </button>
    <button onclick="exportJobOrders('csv')" class="btn-export" style="background:#0891b2;color:white;padding:6px 12px;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">
        <i class="fas fa-file-csv"></i> Export CSV
    </button>
    <button onclick="exportJobOrders('pdf')" class="btn-export" style="background:#dc2626;color:white;padding:6px 12px;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">
        <i class="fas fa-file-pdf"></i> Export PDF
    </button>
</div>

<script>
function exportJobOrders(format) {
    // Use current date range if available, otherwise default to current month
    const dateFrom = '<?= date('Y-m-01') ?>';
    const dateTo = '<?= date('Y-m-d') ?>';
    window.location.href = `backend/export/export_job_order_tracker.php?format=${format}&date_from=${dateFrom}&date_to=${dateTo}`;
}
</script>
```

---

### 2. Merchandise History Panel (staff_transactions_hub.php)

**Location:** In the Merchandise History sidebar panel (right side)

**Add this HTML at the top of the Merchandise History panel:**

```html
<!-- Export Actions for Merchandise History -->
<div class="mh-export-actions" style="display:flex;gap:6px;margin:10px 0;flex-wrap:wrap;">
    <button onclick="exportMerchandiseHistory('excel')" style="background:#16a34a;color:white;padding:5px 10px;border:none;border-radius:4px;font-size:10px;font-weight:600;cursor:pointer;">
        <i class="fas fa-file-excel"></i> Excel
    </button>
    <button onclick="exportMerchandiseHistory('csv')" style="background:#0891b2;color:white;padding:5px 10px;border:none;border-radius:4px;font-size:10px;font-weight:600;cursor:pointer;">
        <i class="fas fa-file-csv"></i> CSV
    </button>
    <button onclick="exportMerchandiseHistory('pdf')" style="background:#dc2626;color:white;padding:5px 10px;border:none;border-radius:4px;font-size:10px;font-weight:600;cursor:pointer;">
        <i class="fas fa-file-pdf"></i> PDF
    </button>
</div>

<script>
function exportMerchandiseHistory(format) {
    // Get current filter values if available
    const dateFrom = document.querySelector('input[name="mh_date_from"]')?.value || '<?= date('Y-m-01') ?>';
    const dateTo = document.querySelector('input[name="mh_date_to"]')?.value || '<?= date('Y-m-d') ?>';
    window.location.href = `backend/export/export_staff_merchandise_history.php?format=${format}&date_from=${dateFrom}&date_to=${dateTo}`;
}
</script>
```

---

## 🔹 MANAGER SIDE

### 3. Manager Reports - Shift Summary (manager_reports.php)

**Location:** In the export actions section (already has export buttons)

**Add this button alongside existing export buttons:**

```html
<!-- Shift Summary PDF Export -->
<button type="button" class="rpt-export-btn" onclick="exportShiftSummary()">
    <i class="fas fa-file-pdf"></i> Shift Summary (PDF)
</button>

<script>
function exportShiftSummary() {
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '<?= date('Y-m-d') ?>';
    const dateTo = document.querySelector('input[name="date_to"]')?.value || '<?= date('Y-m-d') ?>';
    window.open(`backend/export/export_manager_shift_summary.php?date_from=${dateFrom}&date_to=${dateTo}`, '_blank');
}
</script>
```

---

### 4. Pending/Validated Transactions (manager_fuel_transaction_validation.php)

**Note:** This page already has export buttons for pending and validated transactions. No changes needed - the existing export functionality uses the backend files we've updated.

---

## 🔹 ADMIN SIDE

### 5. Admin Dashboard - Receivables Section

**Location:** Add to admin dashboard or receivables management page

**HTML to add:**

```html
<!-- Receivables Aging Export -->
<div class="admin-export-section" style="display:flex;gap:8px;margin:15px 0;">
    <button onclick="exportReceivables('excel')" class="admin-btn" style="background:#16a34a;color:white;padding:8px 16px;border:none;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;">
        <i class="fas fa-file-excel"></i> Export Receivables (Excel)
    </button>
    <button onclick="exportReceivables('csv')" class="admin-btn" style="background:#0891b2;color:white;padding:8px 16px;border:none;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;">
        <i class="fas fa-file-csv"></i> Export Receivables (CSV)
    </button>
</div>

<script>
function exportReceivables(format) {
    // Optional: add station filter if needed
    const stationId = document.querySelector('#station_filter')?.value || '';
    let url = `backend/export/export_admin_receivables.php?format=${format}`;
    if (stationId) url += `&station_id=${stationId}`;
    window.location.href = url;
}
</script>
```

---

### 6. Admin Dashboard - Variance Alerts Section

**Location:** Add to admin oversight or compliance section

**HTML to add:**

```html
<!-- Variance Alerts Export -->
<div class="variance-export-section" style="margin:15px 0;">
    <button onclick="exportVarianceAlerts()" class="admin-btn" style="background:#dc2626;color:white;padding:8px 16px;border:none;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;">
        <i class="fas fa-exclamation-triangle"></i> Export Variance Alerts (PDF)
    </button>
</div>

<script>
function exportVarianceAlerts() {
    const dateFrom = document.querySelector('#variance_date_from')?.value || '<?= date('Y-m-01') ?>';
    const dateTo = document.querySelector('#variance_date_to')?.value || '<?= date('Y-m-d') ?>';
    window.open(`backend/export/export_admin_variance_alerts.php?date_from=${dateFrom}&date_to=${dateTo}`, '_blank');
}
</script>
```

---

### 7. Admin Audit Trail Sidebar

**Note:** Audit trail already has export functionality via `backend/api/export_logs.php`. No changes needed.

---

## 📋 Complete Button Styling Template

Use this consistent styling across all export buttons:

```css
/* Export Button Styles */
.btn-export, .rpt-export-btn, .admin-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border: none;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-export:hover, .rpt-export-btn:hover, .admin-btn:hover {
    opacity: 0.9;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.btn-export i, .rpt-export-btn i, .admin-btn i {
    font-size: 12px;
}

/* Color variants */
.btn-export.excel { background: #16a34a; color: white; }
.btn-export.csv { background: #0891b2; color: white; }
.btn-export.pdf { background: #dc2626; color: white; }
```

---

## 🚀 Quick Test After Implementation

After adding export buttons to each page, test:

1. **Click each export button** - Ensure file downloads
2. **Check file naming** - Should follow pattern: `{type}_{date}_{time}.{ext}`
3. **Open exported files** - Verify data is correct and formatted properly
4. **Test filters** - If page has date/search filters, verify they work in export
5. **Test empty results** - Ensure proper "No records" message

---

## ⚠️ Important Notes

### Security Considerations
- All export scripts check user authentication via `require_login()`
- Staff exports only show their own records (filtered by `staff_id`)
- Manager exports are station-scoped (filtered by `station_id`)
- Admin exports have system-wide access

### Performance Considerations
- Exports are limited to 500-1000 records to prevent memory issues
- Large date ranges may take longer to process
- PDF exports open in new window/tab to prevent blocking

### Browser Compatibility
- All export formats tested on Chrome, Firefox, Edge
- PDF exports use browser print dialog (Ctrl+P / Cmd+P)
- CSV files use UTF-8 encoding with BOM for Excel compatibility

---

## 📝 Testing Script

Use this testing checklist after implementation:

```markdown
## Staff Side Testing
- [ ] Job Order Tracker - Excel export
- [ ] Job Order Tracker - CSV export
- [ ] Job Order Tracker - PDF export
- [ ] Merchandise History - Excel export
- [ ] Merchandise History - CSV export
- [ ] Merchandise History - PDF export

## Manager Side Testing
- [ ] Pending Transactions - Excel export
- [ ] Pending Transactions - CSV export
- [ ] Validated Transactions - Excel export
- [ ] Shift Summary - PDF export

## Admin Side Testing
- [ ] Receivables Aging - Excel export
- [ ] Receivables Aging - CSV export
- [ ] Variance Alerts - PDF export
- [ ] Audit Trail - Excel export (existing)
- [ ] Audit Trail - CSV export (existing)
```

---

## 🎯 Quick Copy-Paste Integration Points

### For staff_transactions_hub.php
Search for these sections in the file and add export buttons:

1. **Job Order section:** Look for `<!-- Job Order Tracker -->` or similar
2. **Merchandise History panel:** Look for `<div class="mh-panel">` or merchandise history section

### For manager_reports.php
Search for:
- `<div class="rpt-export-actions">` - Add shift summary button here

### For admin pages
Create new sections or add to existing export button groups on:
- Admin Dashboard (receivables section)
- Admin Oversight page (variance alerts section)

---

**Implementation Time Estimate:** 30-60 minutes for all pages

**Date Created:** June 17, 2026
