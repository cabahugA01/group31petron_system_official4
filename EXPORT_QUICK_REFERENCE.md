# Export Functionality - Quick Reference Card

## 🚀 Quick Access URLs

### Staff Exports
```
backend/export/export_job_order_tracker.php?format=excel&date_from=2026-01-01&date_to=2026-01-31
backend/export/export_staff_merchandise_history.php?format=csv&date_from=2026-01-01&date_to=2026-01-31
```

### Manager Exports
```
backend/export/export_pending_transactions.php?format=excel&search=customer
backend/export/export_validated_transactions.php?format=csv
backend/export/export_manager_shift_summary.php?date_from=2026-06-17&date_to=2026-06-17
```

### Admin Exports
```
backend/export/export_admin_receivables.php?format=excel&station_id=1
backend/export/export_admin_variance_alerts.php?date_from=2026-06-01&date_to=2026-06-17
backend/api/export_logs.php?format=excel
```

---

## 📋 Export Matrix

| Role | Export Type | Excel | CSV | PDF | File |
|------|-------------|-------|-----|-----|------|
| **Staff** | Job Orders | ✅ | ✅ | ✅ | `export_job_order_tracker.php` |
| **Staff** | Merchandise | ✅ | ✅ | ✅ | `export_staff_merchandise_history.php` |
| **Manager** | Pending Txns | ✅ | ✅ | ✅ | `export_pending_transactions.php` |
| **Manager** | Validated Txns | ✅ | ✅ | ✅ | `export_validated_transactions.php` |
| **Manager** | Shift Summary | — | — | ✅ | `export_manager_shift_summary.php` |
| **Admin** | Receivables | ✅ | ✅ | — | `export_admin_receivables.php` |
| **Admin** | Variance Alerts | — | — | ✅ | `export_admin_variance_alerts.php` |
| **Admin** | Audit Trail | ✅ | ✅ | ✅ | `api/export_logs.php` |

---

## 🎯 Common Parameters

| Parameter | Description | Example |
|-----------|-------------|---------|
| `format` | Export format | `excel`, `csv`, `pdf` |
| `date_from` | Start date | `2026-01-01` |
| `date_to` | End date | `2026-01-31` |
| `search` | Search filter | `customer_name` |
| `station_id` | Station filter (Admin) | `1`, `2`, `3` |

---

## 🔧 JavaScript Functions Reference

### Staff Functions
```javascript
function exportJobOrders(format) {
    const dateFrom = '<?= date('Y-m-01') ?>';
    const dateTo = '<?= date('Y-m-d') ?>';
    window.location.href = `backend/export/export_job_order_tracker.php?format=${format}&date_from=${dateFrom}&date_to=${dateTo}`;
}

function exportMerchandiseHistory(format) {
    const dateFrom = '<?= date('Y-m-01') ?>';
    const dateTo = '<?= date('Y-m-d') ?>';
    window.location.href = `backend/export/export_staff_merchandise_history.php?format=${format}&date_from=${dateFrom}&date_to=${dateTo}`;
}
```

### Manager Functions
```javascript
function exportPendingTransactions(format) {
    const search = document.querySelector('#search_input')?.value || '';
    let url = `backend/export/export_pending_transactions.php?format=${format}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    window.location.href = url;
}

function exportValidatedTransactions(format) {
    window.location.href = `backend/export/export_validated_transactions.php?format=${format}`;
}

function exportShiftSummary() {
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '<?= date('Y-m-d') ?>';
    const dateTo = document.querySelector('input[name="date_to"]')?.value || '<?= date('Y-m-d') ?>';
    window.open(`backend/export/export_manager_shift_summary.php?date_from=${dateFrom}&date_to=${dateTo}`, '_blank');
}
```

### Admin Functions
```javascript
function exportReceivables(format) {
    const stationId = document.querySelector('#station_filter')?.value || '';
    let url = `backend/export/export_admin_receivables.php?format=${format}`;
    if (stationId) url += `&station_id=${stationId}`;
    window.location.href = url;
}

function exportVarianceAlerts() {
    const dateFrom = '<?= date('Y-m-01') ?>';
    const dateTo = '<?= date('Y-m-d') ?>';
    window.open(`backend/export/export_admin_variance_alerts.php?date_from=${dateFrom}&date_to=${dateTo}`, '_blank');
}
```

---

## 🎨 Button Styling Template

```html
<!-- Excel Button -->
<button onclick="exportFunction('excel')" style="background:#16a34a;color:white;padding:6px 12px;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">
    <i class="fas fa-file-excel"></i> Export Excel
</button>

<!-- CSV Button -->
<button onclick="exportFunction('csv')" style="background:#0891b2;color:white;padding:6px 12px;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">
    <i class="fas fa-file-csv"></i> Export CSV
</button>

<!-- PDF Button -->
<button onclick="exportFunction('pdf')" style="background:#dc2626;color:white;padding:6px 12px;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">
    <i class="fas fa-file-pdf"></i> Export PDF
</button>
```

---

## 📁 File Naming Patterns

| Export Type | Filename Pattern |
|-------------|------------------|
| Job Orders | `job_order_tracker_YYYY-MM-DD_HHMMSS.{ext}` |
| Merchandise | `merchandise_history_YYYY-MM-DD_HHMMSS.{ext}` |
| Pending Txns | `pending_transactions_YYYY-MM-DD_HHMMSS.{ext}` |
| Validated Txns | `validated_transactions_YYYY-MM-DD.{ext}` |
| Shift Summary | `Manager_Shift_Summary_YYYY-MM-DD.pdf` |
| Receivables | `admin_receivables_aging_YYYY-MM-DD_HHMMSS.{ext}` |
| Variance Alerts | `variance_alerts_summary_YYYY-MM-DD.pdf` |
| Audit Trail | `audit_trail_YYYY-MM-DD.{ext}` |

---

## 🔒 Security Checklist

✅ All exports require authentication (`require_login()`)  
✅ Staff exports filtered by `staff_id`  
✅ Manager exports filtered by `station_id`  
✅ Admin exports have system-wide access  
✅ All queries use prepared statements (SQL injection protected)  
✅ Record limits enforce (500-1000 rows)  
✅ Role-based access control enforced

---

## 🐛 Troubleshooting Quick Fixes

| Issue | Solution |
|-------|----------|
| **No records found** | Check date range, verify user has data in period |
| **CSV encoding issues** | All CSVs use UTF-8 with BOM |
| **PDF won't print** | Use browser print button (Ctrl+P) |
| **Memory error** | Use shorter date range, exports limited to 500-1000 rows |
| **Download doesn't start** | Check browser pop-up blocker |
| **Excel formatting lost** | Use .xls format, not .xlsx |

---

## 📊 Data Limits

| Export Type | Record Limit | Notes |
|-------------|--------------|-------|
| Job Orders | 500 | Staff-scoped |
| Merchandise | 500 | Staff-scoped |
| Pending Txns | 5000 | Station-scoped |
| Validated Txns | 500 | Station/System-scoped |
| Shift Summary | All shifts | Date-range filtered |
| Receivables | 1000 | System-wide |
| Variance Alerts | 100 per source | High-priority only |
| Audit Trail | 10000 | Date-range filtered |

---

## 🎯 Integration Checklist

### Pages to Update
- [ ] `public/staff_transactions_hub.php` - Job Orders export buttons
- [ ] `public/staff_transactions_hub.php` - Merchandise History export buttons
- [ ] `public/manager_reports.php` - Shift Summary export button
- [ ] Admin Dashboard/Oversight - Receivables export buttons
- [ ] Admin Dashboard/Compliance - Variance Alerts export button

### Testing Checklist
- [ ] Test all Staff exports (6 combinations)
- [ ] Test all Manager exports (7 combinations)
- [ ] Test all Admin exports (5 combinations)
- [ ] Verify file downloads correctly
- [ ] Verify data accuracy
- [ ] Test with empty results
- [ ] Test with date filters

---

## 📞 Quick Help

**Documentation Files:**
1. `EXPORT_FUNCTIONALITY_COMPLETE.md` - Full technical documentation
2. `EXPORT_BUTTON_INTEGRATION_GUIDE.md` - Step-by-step integration
3. `EXPORT_IMPLEMENTATION_SUMMARY.md` - High-level overview
4. `EXPORT_QUICK_REFERENCE.md` - This file

**Common Questions:**

**Q: Where do I add export buttons?**  
A: See `EXPORT_BUTTON_INTEGRATION_GUIDE.md` for exact locations

**Q: How do I test exports?**  
A: Add buttons → Click → Verify file downloads → Check data accuracy

**Q: What if export returns "No records"?**  
A: Verify date range and that the user has created transactions in that period

**Q: Can I customize export formats?**  
A: Yes, edit the backend PHP files in `backend/export/`

---

## 🚀 Quick Start (5 Minutes)

1. **Open** `public/staff_transactions_hub.php`
2. **Find** the Job Order Tracker section
3. **Copy** export buttons from integration guide
4. **Paste** into the page
5. **Save** and reload
6. **Click** export button
7. **Verify** file downloads

**Done!** Repeat for other pages.

---

## 📅 Implementation Date
**Completed:** June 17, 2026  
**Backend Status:** ✅ 100% Complete  
**Frontend Status:** ⏳ Awaiting button integration  
**Time to Deploy:** ~30-60 minutes

---

**Print this page for quick reference during implementation! 📄**
