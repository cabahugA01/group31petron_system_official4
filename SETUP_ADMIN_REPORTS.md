# 🚀 Admin Reports - Setup & Deployment Guide

## ✅ Files Created - Complete Checklist

### Backend Files (1 file)
- ✅ `backend/api/admin_reports_api.php` - Main API with 12 endpoints

### Report Section Files (12 files in `public/reports/`)
- ✅ `public/reports/admin_shift_reports.php`
- ✅ `public/reports/admin_daily_consolidation.php`
- ✅ `public/reports/admin_fuel_inventory.php`
- ✅ `public/reports/admin_merchandise_inventory.php`
- ✅ `public/reports/admin_job_orders.php`
- ✅ `public/reports/admin_payments.php`
- ✅ `public/reports/admin_customers.php`
- ✅ `public/reports/admin_suppliers.php`
- ✅ `public/reports/admin_financial.php`
- ✅ `public/reports/admin_activity_log.php`
- ✅ `public/reports/admin_audit_trail.php`
- ✅ `public/reports/admin_calendar_schedule.php`

### Testing & Documentation Files (4 files)
- ✅ `test_admin_reports.php` - Test suite
- ✅ `ADMIN_REPORTS_DOCUMENTATION.md` - Complete documentation
- ✅ `ADMIN_REPORTS_SUMMARY.md` - Implementation summary
- ✅ `SETUP_ADMIN_REPORTS.md` - This file

### Existing File (Already exists)
- ✅ `public/admin_reports.php` - Main reports page

**Total Files: 18**

---

## 📋 Pre-Deployment Checklist

### 1. Database Requirements
Check if these tables exist in your database:

```sql
-- Core tables needed
SHOW TABLES LIKE 'fuel_shifts';
SHOW TABLES LIKE 'fuel_readings';
SHOW TABLES LIKE 'fuel_inventory';
SHOW TABLES LIKE 'fuel_pumps';
SHOW TABLES LIKE 'fuel_types';
SHOW TABLES LIKE 'products';
SHOW TABLES LIKE 'inventory';
SHOW TABLES LIKE 'stock_in';
SHOW TABLES LIKE 'merchandise_transactions';
SHOW TABLES LIKE 'job_orders';
SHOW TABLES LIKE 'service_types';
SHOW TABLES LIKE 'customers';
SHOW TABLES LIKE 'transactions';
SHOW TABLES LIKE 'suppliers';
SHOW TABLES LIKE 'deliveries';
SHOW TABLES LIKE 'audit_logs';
SHOW TABLES LIKE 'users';
SHOW TABLES LIKE 'stations';
```

### 2. PHP Extensions
Ensure these PHP extensions are enabled:

- ✅ PDO
- ✅ PDO_MySQL
- ✅ JSON
- ✅ Session

### 3. File Permissions
Set correct permissions:

```bash
chmod 755 backend/api/admin_reports_api.php
chmod 755 public/admin_reports.php
chmod 755 public/reports/admin_*.php
chmod 755 test_admin_reports.php
```

### 4. Dependencies
- ✅ Chart.js (loaded via CDN in admin_reports.php)
- ✅ Font Awesome (loaded via CDN)
- ✅ jQuery (if needed, loaded via CDN)

---

## 🔧 Installation Steps

### Step 1: Verify Files
Run the test suite to verify all files are in place:

```
http://localhost/group31petron_system_official4/test_admin_reports.php
```

This will check:
- All 12 report files exist
- All 12 API endpoints are responding
- File permissions are correct

### Step 2: Database Check
Ensure your database has sample data:

```sql
-- Check if you have shift data
SELECT COUNT(*) FROM fuel_shifts;

-- Check if you have inventory data
SELECT COUNT(*) FROM fuel_inventory;
SELECT COUNT(*) FROM inventory;

-- Check if you have job orders
SELECT COUNT(*) FROM job_orders;

-- Check if you have audit logs
SELECT COUNT(*) FROM audit_logs;
```

### Step 3: Configure Access
Update your navigation/menu to include the Admin Reports link:

```php
// In your admin sidebar or menu
<a href="admin_reports.php">
    <i class="fas fa-chart-bar"></i> Reports & Analytics
</a>
```

### Step 4: Test User Access
1. Login as Admin or SuperAdmin
2. Navigate to: `http://localhost/group31petron_system_official4/public/admin_reports.php`
3. Click through each of the 12 report tabs
4. Verify data loads correctly

---

## 🧪 Testing Guide

### Manual Testing

#### Test 1: Basic Access
1. Login as Admin
2. Navigate to Reports
3. Verify page loads without errors

#### Test 2: Date Filtering
1. Select "Today" - verify data loads
2. Select "This Week" - verify data loads
3. Select "This Month" - verify data loads
4. Select "Custom Range" - pick dates - verify data loads

#### Test 3: Each Report Section
Test each of the 12 reports:

| # | Report | What to Check |
|---|--------|---------------|
| 1 | Shift Reports | Shift 1 & 2 data appears, totals calculate |
| 2 | Daily Consolidation | Charts render, totals match shifts |
| 3 | Fuel Inventory | Readings show, variance calculates |
| 4 | Merchandise Inventory | Stock levels, low stock alerts |
| 5 | Job Orders | Status breakdown works, totals correct |
| 6 | Payments | Payment modes show, variance calculates |
| 7 | Customers | Customer list, balances display |
| 8 | Suppliers | Supplier list, payables calculate |
| 9 | Financial | Payables and receivables separate |
| 10 | Activity Log | Actions log, filterable |
| 11 | Audit Trail | Changes tracked, old/new values |
| 12 | Calendar | Events display, timeline renders |

#### Test 4: Charts
1. Go to Daily Consolidation
2. Verify all 4 charts render:
   - Fuel Sales (Bar Chart)
   - Merchandise Sales (Pie Chart)
   - Job Orders (Line Chart)
   - Payments (Stacked Bar Chart)

#### Test 5: Export
1. Click Export button on any report
2. Verify export process initiates

#### Test 6: Security
1. Logout
2. Login as Staff user
3. Try to access admin_reports.php
4. Verify access is denied

---

## 🐛 Troubleshooting

### Issue: "Reports not loading"

**Solution:**
1. Check browser console for JavaScript errors
2. Verify API endpoint is accessible:
   ```
   http://localhost/group31petron_system_official4/backend/api/admin_reports_api.php?action=get_shift_reports&date_start=2026-06-12&date_end=2026-06-12
   ```
3. Check PHP error logs
4. Verify database connection in `db_connect.php`

### Issue: "No data found"

**Solution:**
1. Verify database has data:
   ```sql
   SELECT * FROM fuel_shifts LIMIT 5;
   ```
2. Check date range matches your data dates
3. Verify station_id filter matches your data

### Issue: "Charts not displaying"

**Solution:**
1. Check browser console for Chart.js errors
2. Verify Chart.js CDN is loading:
   ```html
   <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
   ```
3. Clear browser cache
4. Verify canvas elements exist in HTML

### Issue: "API returns empty data"

**Solution:**
1. Check API endpoint directly in browser
2. Verify SQL queries in `admin_reports_api.php`
3. Check table names match your database schema
4. Verify station_id and user permissions

### Issue: "Access Denied"

**Solution:**
1. Verify user role is 'admin' or 'superadmin'
2. Check session is active
3. Verify `require_login()` function works
4. Check `role_key()` function returns correct role

---

## 🎯 Quick Start Commands

### Test Everything at Once
```bash
# Navigate to project
cd c:\xampp\htdocs\group31petron_system_official4

# Start XAMPP (if not running)
# Open browser to:
http://localhost/group31petron_system_official4/test_admin_reports.php
```

### Check File Integrity
```bash
# Count report files (should be 12)
dir public\reports\admin_*.php | Measure-Object

# Check API file exists
dir backend\api\admin_reports_api.php
```

### Verify Database
```sql
-- Run these queries to check data
SELECT COUNT(*) as shift_count FROM fuel_shifts;
SELECT COUNT(*) as inventory_count FROM inventory;
SELECT COUNT(*) as jobs_count FROM job_orders;
SELECT COUNT(*) as audit_count FROM audit_logs;
```

---

## 📊 Sample Data (For Testing)

If you need sample data for testing, run this SQL:

```sql
-- Sample Fuel Shift
INSERT INTO fuel_shifts (station_id, shift_date, shift_number, staff_id, fuel_sales_total, merchandise_sales_total, service_income_total, job_orders_count, cash_payments, card_payments, ewallet_payments, fleet_card_payments, efuel_card_payments, customers_added, status, created_at)
VALUES (1, '2026-06-12', 1, 1, 25000.00, 5000.00, 3000.00, 5, 15000.00, 10000.00, 5000.00, 2000.00, 1000.00, 3, 'Validated', NOW());

-- Sample Job Order
INSERT INTO job_orders (station_id, job_order_number, customer_name, vehicle_plate, service_type, status, total_cost, payment_status, created_by, created_at)
VALUES (1, 'JO-20260612-001', 'John Doe', 'ABC-1234', 'Oil Change', 'Completed', 1500.00, 'Paid', 1, NOW());

-- Sample Audit Log
INSERT INTO audit_logs (user_id, action_type, entity_type, action_details, ip_address, status, created_at)
VALUES (1, 'view_report', 'admin_reports', 'Viewed Shift Reports', '127.0.0.1', 'success', NOW());
```

---

## 🔗 Useful Links

### Documentation
- **Full Documentation:** `ADMIN_REPORTS_DOCUMENTATION.md`
- **Implementation Summary:** `ADMIN_REPORTS_SUMMARY.md`
- **This Setup Guide:** `SETUP_ADMIN_REPORTS.md`

### Test Pages
- **Test Suite:** `http://localhost/.../test_admin_reports.php`
- **Main Reports:** `http://localhost/.../public/admin_reports.php`

### API Testing
- **API Endpoint:** `http://localhost/.../backend/api/admin_reports_api.php`
- **Example:** `?action=get_shift_reports&date_start=2026-06-12&date_end=2026-06-12`

---

## 📞 Support Checklist

Before asking for help, verify:

- [ ] All 18 files were created successfully
- [ ] Database tables exist and have data
- [ ] XAMPP/Apache is running
- [ ] MySQL is running
- [ ] User is logged in as Admin
- [ ] Browser console shows no JavaScript errors
- [ ] API endpoint returns valid JSON
- [ ] File permissions are correct
- [ ] Chart.js is loading from CDN
- [ ] Session is active

---

## ✨ Next Steps

After successful deployment:

1. **Populate with real data**
   - Import historical shift data
   - Add inventory records
   - Create job orders

2. **Train users**
   - Show admins how to access reports
   - Explain date filtering
   - Demonstrate export functionality

3. **Monitor performance**
   - Watch API response times
   - Check database query performance
   - Monitor user feedback

4. **Plan enhancements**
   - Schedule automated reports
   - Add more chart types
   - Implement PDF export

---

## 🎉 Success Indicators

You'll know the system is working when:

✅ All 12 report tabs are clickable  
✅ Data loads within 3 seconds  
✅ Charts render correctly  
✅ Date filters update data  
✅ Summary cards show correct totals  
✅ Tables display data with proper formatting  
✅ Color-coded indicators appear  
✅ No console errors  
✅ Export button initiates download  
✅ Access control works (staff denied)  

---

**Ready to Deploy!** 🚀

All systems are in place. Run the test suite at `test_admin_reports.php` to validate everything, then open `admin_reports.php` to start using the comprehensive reporting system.

---

**Version:** 1.0.0  
**Status:** Production Ready ✅  
**Last Updated:** June 12, 2026
