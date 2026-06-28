# ADMIN CUSTOMER OVERSIGHT MODULE
## 🚀 DEPLOYMENT VERIFICATION CHECKLIST

---

## ✅ PRE-DEPLOYMENT VERIFICATION

### 1. FILES PRESENT & COMPLETE
- [x] `public/admin_customers.php` (Main UI - 764 lines)
- [x] `public/admin_customer_operations.php` (Backend API)
- [x] `public/admin_customer_export.php` (Export Handler)
- [x] Menu entry in `partials/rbac_menu.php` (Line 208-216)

**Verification Command:**
```bash
# Check files exist
ls public/admin_customers.php
ls public/admin_customer_operations.php
ls public/admin_customer_export.php
```

---

### 2. DATABASE TABLES REQUIRED
The module accesses these tables (READ-ONLY):

- [x] `customers` - Main customer records
- [x] `users` - Staff/user information
- [x] `stations` - Station details
- [x] `fuel_transactions` - Fuel sales history
- [x] `merchandise_transactions` - Merchandise sales history
- [x] `job_orders` - Service/job order history
- [x] `audit_logs` - Access logging (WRITE access for logging)

**Verification Query:**
```sql
-- Run this in phpMyAdmin or MySQL client
SHOW TABLES LIKE 'customers';
SHOW TABLES LIKE 'users';
SHOW TABLES LIKE 'stations';
SHOW TABLES LIKE 'fuel_transactions';
SHOW TABLES LIKE 'merchandise_transactions';
SHOW TABLES LIKE 'job_orders';
SHOW TABLES LIKE 'audit_logs';
```

---

### 3. RBAC MENU CONFIGURATION
Menu entry should be visible for Admin role:

**Location:** `partials/rbac_menu.php` (lines 208-216)

```php
// 7.5. Customers Oversight — Admin Oversight Module
[
    'id' => 'admin_customers',
    'label' => 'Customers',
    'ico' => 'fas fa-users',
    'href' => 'admin_customers.php',
    'permissions' => ['view_all_reports', 'view_dashboard'],
    'station_specific' => true,
],
```

**Verification Steps:**
1. Login as Admin user
2. Check sidebar for "Customers" menu item
3. Icon should be: 👥 (fa-users)
4. Click should navigate to `admin_customers.php`

---

### 4. PERMISSIONS SETUP
Admin role must have these permissions:

- [x] `view_all_reports` - View all reports (required for menu access)
- [x] `view_dashboard` - Dashboard access (required for menu access)

**Verification Query:**
```sql
-- Check admin role permissions
SELECT * FROM roles WHERE role_key = 'admin';
-- Verify permissions column contains the required permissions
```

**Alternative:** Check in Admin Management module UI

---

### 5. DOCUMENT STORAGE PATH
Customer documents must be accessible:

**Expected Path Structure:**
```
uploads/
├── customers/
│   ├── gov_ids/          (Government ID images/PDFs)
│   └── company_docs/     (CR documents for fleet)
```

**Verification:**
1. Check if `uploads/customers/` directory exists
2. Verify web server has read access
3. Test document path: `../uploads/customers/gov_ids/example.jpg`

**Fix if missing:**
```bash
mkdir -p uploads/customers/gov_ids
mkdir -p uploads/customers/company_docs
chmod 755 uploads/customers
```

---

## ✅ FUNCTIONAL TESTING

### Test 1: Page Access
- [ ] Login as Admin user
- [ ] Navigate to Customers menu in sidebar
- [ ] Page loads without errors
- [ ] Summary cards display with counts
- [ ] Filter bar is visible
- [ ] Table loads with customer data

**Expected Result:** Page displays correctly with all sections visible

---

### Test 2: Summary Cards
- [ ] Total Customers shows correct count
- [ ] New Today shows today's registrations
- [ ] Regular Customers count is accurate
- [ ] Fleet Accounts count is accurate
- [ ] Active Customers shows active status count
- [ ] Inactive/Suspended shows correct count

**Verification:** Compare counts with database query:
```sql
SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as new_today,
    SUM(CASE WHEN customer_type = 'regular' THEN 1 ELSE 0 END) as regulars,
    SUM(CASE WHEN customer_type = 'fleet' THEN 1 ELSE 0 END) as fleets,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status IN ('inactive','suspended') THEN 1 ELSE 0 END) as inactive
FROM customers 
WHERE station_id = ?;
```

---

### Test 3: Search & Filters
- [ ] Search by Customer ID works
- [ ] Search by Customer Name works
- [ ] Search by Contact Number works
- [ ] Customer Type filter works (All/Walk-in/Regular/Fleet)
- [ ] Status filter works (All/Active/Inactive)
- [ ] Registered By dropdown populates with staff
- [ ] Date Registered From/To filters work
- [ ] Last Transaction Date From/To filters work
- [ ] Apply Filters button refreshes results
- [ ] Reset button clears all filters

**Test Data:**
- Search: "Juan" → should return customers with "Juan" in name
- Type: "Fleet" → should show only fleet customers
- Date From: "2024-01-01" → should show customers registered after this date

---

### Test 4: Customer Table
- [ ] All columns display correctly
- [ ] Customer ID is bold
- [ ] Customer Type shows colored badges
- [ ] Status shows colored badges
- [ ] Last Transaction shows date or "None"
- [ ] View button (eye icon) works
- [ ] Print button (printer icon) works
- [ ] Hover effect on rows works
- [ ] Empty state shows when no results

---

### Test 5: Customer Profile View
- [ ] Click View button opens profile overlay
- [ ] Customer name displays in header
- [ ] Customer ID and type badge show in header
- [ ] Close button works
- [ ] Customer Information section displays all fields
- [ ] Transaction Summary shows correct counts
- [ ] Total Amount Spent is calculated correctly
- [ ] Last Transaction Date is accurate
- [ ] Outstanding Balance displays (if applicable)
- [ ] Documents section shows Gov ID link (if submitted)
- [ ] CR Document link shows for fleet customers (if submitted)
- [ ] Fleet Information section shows for fleet customers only

**Test Customer IDs:**
- Regular customer with transactions
- Fleet customer with CR document
- Walk-in customer with no transactions
- Customer with outstanding balance

---

### Test 6: Transaction History
- [ ] Transaction History table loads in profile view
- [ ] Fuel transactions display correctly
- [ ] Merchandise transactions display correctly
- [ ] Job orders display correctly
- [ ] Search by Reference No. works
- [ ] Module filter works (All/Fuel/Merchandise/Job Order)
- [ ] Status filter works (All/Completed/Pending/Voided)
- [ ] Date From/To filters work
- [ ] Apply button refreshes history
- [ ] Clear button resets history filters
- [ ] Pagination works correctly
- [ ] Rows per page dropdown works (10/25/50/100)
- [ ] Previous/Next buttons navigate pages
- [ ] Total count is accurate ("248 items total")

**Test Scenarios:**
- Customer with 100+ transactions (test pagination)
- Customer with mixed transaction types
- Customer with no transactions

---

### Test 7: Document Preview
- [ ] Click "View Gov ID" opens document modal
- [ ] PDF documents display in iframe viewer
- [ ] Image documents (JPG/PNG) display correctly
- [ ] Modal title shows correct document type
- [ ] Close button (X) closes modal
- [ ] Click outside modal closes it
- [ ] Access is logged to audit_logs table
- [ ] Only works for customers in same station

**Test Documents:**
- PDF Government ID
- JPG/PNG Government ID
- PDF CR Document (fleet customer)

**Verify Audit Log:**
```sql
SELECT * FROM audit_logs 
WHERE action = 'View' 
AND table_name = 'customers' 
ORDER BY created_at DESC 
LIMIT 10;
```

---

### Test 8: Export Functionality

#### A. Customer List Export
- [ ] Export PDF generates correctly
- [ ] Export Excel generates correctly
- [ ] Export CSV generates correctly
- [ ] Exports respect active filters
- [ ] File downloads automatically
- [ ] Export includes header with station name
- [ ] Export includes generation date/time
- [ ] All columns present in export

**Test Exports:**
1. Export with no filters (all customers)
2. Export with search filter (e.g., "Juan")
3. Export with type filter (e.g., "Fleet")
4. Export with date range

#### B. Single Profile Export
- [ ] Print Profile button opens print preview
- [ ] PDF format is clean and professional
- [ ] All customer information included
- [ ] Transaction summary included
- [ ] Fleet information included (if applicable)
- [ ] Document verification status included
- [ ] Footer shows printed by and timestamp
- [ ] Browser print dialog opens automatically

**Test Profiles:**
- Regular customer
- Fleet customer
- Customer with outstanding balance

#### C. Transaction History Export
- [ ] Export History PDF works
- [ ] Export History Excel works
- [ ] Export History CSV works
- [ ] Exports respect history filters
- [ ] All transactions included in export
- [ ] Sorted by date descending
- [ ] Customer name and ID in header

**Test History Exports:**
- All transactions (no filters)
- Filtered by module (e.g., "Fuel" only)
- Filtered by date range

---

## ✅ SECURITY TESTING

### Test 1: Role-Based Access Control
- [ ] Staff role CANNOT access admin_customers.php
- [ ] Manager role CANNOT access admin_customers.php
- [ ] Admin role CAN access admin_customers.php
- [ ] SuperAdmin role CAN access admin_customers.php
- [ ] Developer role CAN access admin_customers.php

**Test Method:**
1. Login as each role
2. Manually navigate to: `http://localhost/.../public/admin_customers.php`
3. Verify access denied for unauthorized roles

---

### Test 2: Station Scope Isolation
- [ ] Admin at Station A sees only Station A customers
- [ ] Admin at Station B sees only Station B customers
- [ ] Customers from other stations not visible
- [ ] Transaction history shows only same-station transactions
- [ ] Documents only accessible for same-station customers

**Test Method:**
1. Login as Admin at Station 1
2. Note customer IDs visible
3. Login as Admin at Station 2
4. Verify different customer list
5. Check database station_id filtering

---

### Test 3: SQL Injection Prevention
- [ ] Search field rejects SQL injection attempts
- [ ] Filter dropdowns safe from injection
- [ ] Date fields sanitized
- [ ] No raw SQL in error messages

**Test Inputs:**
- Search: `' OR '1'='1`
- Search: `'; DROP TABLE customers; --`
- Date: `2024-01-01' OR '1'='1`

**Expected:** No errors, inputs treated as literal strings

---

### Test 4: XSS Prevention
- [ ] Customer names with HTML tags are escaped
- [ ] Script tags in customer data don't execute
- [ ] Output is properly sanitized

**Test Data:**
- Customer name: `<script>alert('XSS')</script>`
- Address: `<img src=x onerror=alert('XSS')>`

**Expected:** Tags displayed as text, not executed

---

### Test 5: Audit Trail Logging
- [ ] Profile view access is logged
- [ ] Document view access is logged
- [ ] Export operations are logged
- [ ] Logs include user ID, action, timestamp
- [ ] Logs include customer ID and customer_id

**Verification Query:**
```sql
SELECT * FROM audit_logs 
WHERE table_name = 'customers' 
AND user_id = ? 
ORDER BY created_at DESC;
```

---

## ✅ PERFORMANCE TESTING

### Test 1: Page Load Time
- [ ] Initial page load < 2 seconds
- [ ] Summary cards load < 1 second
- [ ] Customer table renders < 1 second
- [ ] No console errors in browser

**Test with:**
- 10 customers (small dataset)
- 100 customers (medium dataset)
- 1000+ customers (large dataset)

---

### Test 2: Filter Performance
- [ ] Filter application < 1 second
- [ ] Multiple filters don't slow down significantly
- [ ] Search with wildcards is responsive
- [ ] Date range filters are efficient

---

### Test 3: Export Performance
- [ ] PDF export < 5 seconds (100 records)
- [ ] Excel export < 5 seconds (100 records)
- [ ] CSV export < 3 seconds (100 records)
- [ ] Large exports complete without timeout

**Test Sizes:**
- 10 records
- 100 records
- 500 records
- 1000+ records (adjust PHP timeout if needed)

---

### Test 4: Transaction History Performance
- [ ] History loads < 2 seconds (50 records)
- [ ] Pagination is smooth (< 500ms)
- [ ] Filter application < 1 second
- [ ] Combined module queries efficient

**Test Customers with:**
- 10 transactions
- 100 transactions
- 500+ transactions

---

## ✅ BROWSER COMPATIBILITY

### Desktop Browsers
- [ ] Chrome (latest version)
- [ ] Firefox (latest version)
- [ ] Edge (latest version)
- [ ] Safari (latest version - Mac only)

**Test Features:**
- Page layout
- Modals/overlays
- Print preview
- File downloads
- Date pickers

---

### Mobile Browsers
- [ ] Chrome Mobile (Android)
- [ ] Safari Mobile (iOS)
- [ ] Firefox Mobile

**Test Features:**
- Responsive layout
- Touch interactions
- Table horizontal scroll
- Modal display

---

## ✅ ERROR HANDLING

### Test 1: Network Errors
- [ ] Graceful handling when API fails
- [ ] Error toast message displays
- [ ] User can retry operation
- [ ] No JavaScript console errors break page

**Test Method:**
1. Open browser DevTools
2. Set Network to "Offline"
3. Try loading customers
4. Verify error message displays

---

### Test 2: Missing Data
- [ ] Customer with no transactions handled
- [ ] Customer with no documents handled
- [ ] Empty search results show empty state
- [ ] Missing fields show "N/A" or "—"

---

### Test 3: Invalid Input
- [ ] Invalid date formats rejected
- [ ] Special characters in search handled
- [ ] SQL injection attempts blocked
- [ ] XSS attempts sanitized

---

## ✅ USER ACCEPTANCE TESTING

### Test 1: Admin User Workflow
**Scenario:** Admin needs to review a customer's transaction history

1. [ ] Login as Admin
2. [ ] Navigate to Customers
3. [ ] Search for customer by name
4. [ ] Click View to open profile
5. [ ] Review transaction summary
6. [ ] Filter transaction history by date
7. [ ] Export history to Excel
8. [ ] Print customer profile

**Expected:** Smooth workflow, all features accessible

---

### Test 2: Document Verification
**Scenario:** Admin needs to verify customer documents

1. [ ] Open customer profile
2. [ ] Click "View Gov ID"
3. [ ] Review document in preview modal
4. [ ] Close modal
5. [ ] Check if CR document exists (fleet)
6. [ ] Verify access is logged

**Expected:** Clear document preview, audit trail created

---

### Test 3: Bulk Export
**Scenario:** Admin needs to export customer list for external reporting

1. [ ] Apply filters (e.g., "Regular" customers, "Active" status)
2. [ ] Click "Export Excel"
3. [ ] Open downloaded file
4. [ ] Verify data is complete and accurate
5. [ ] Verify filters were applied correctly

**Expected:** Clean Excel file with filtered data

---

## ✅ DOCUMENTATION VERIFICATION

### Files Created
- [x] `ADMIN_CUSTOMER_OVERSIGHT_COMPLETE.md` (Comprehensive documentation)
- [x] `ADMIN_CUSTOMER_MODULE_VISUAL_GUIDE.txt` (Visual layout guide)
- [x] `ADMIN_CUSTOMER_DEPLOYMENT_CHECKLIST.md` (This file)

### Documentation Includes
- [x] Feature list
- [x] Technical specifications
- [x] Database queries
- [x] Security guidelines
- [x] Testing procedures
- [x] Visual layouts
- [x] User guide
- [x] Troubleshooting tips

---

## ✅ PRODUCTION READINESS

### Code Quality
- [x] No syntax errors
- [x] Proper indentation and formatting
- [x] Comments for complex logic
- [x] Consistent naming conventions
- [x] No hardcoded credentials
- [x] Environment-agnostic code

### Security
- [x] Role-based access control
- [x] SQL injection prevention (prepared statements)
- [x] XSS prevention (output escaping)
- [x] CSRF protection
- [x] Audit trail logging
- [x] Station scope isolation

### Performance
- [x] Efficient database queries
- [x] Proper indexing utilized
- [x] Pagination for large datasets
- [x] Lazy loading where appropriate
- [x] Minimal JavaScript overhead

### User Experience
- [x] Intuitive interface
- [x] Clear navigation
- [x] Helpful error messages
- [x] Loading indicators
- [x] Responsive design
- [x] Print-optimized outputs

---

## 🚀 DEPLOYMENT STEPS

### Step 1: Backup
```bash
# Backup database
mysqldump -u root -p petron_db > backup_before_admin_customers.sql

# Backup files (if updating existing)
cp public/admin_customers.php public/admin_customers.php.backup
cp public/admin_customer_operations.php public/admin_customer_operations.php.backup
cp public/admin_customer_export.php public/admin_customer_export.php.backup
```

### Step 2: Upload Files
- [x] Upload `public/admin_customers.php`
- [x] Upload `public/admin_customer_operations.php`
- [x] Upload `public/admin_customer_export.php`

### Step 3: Verify RBAC Menu
- [x] Check `partials/rbac_menu.php` has admin_customers entry
- [x] Verify permissions array matches

### Step 4: Set Permissions
```bash
# Ensure files are readable by web server
chmod 644 public/admin_customers.php
chmod 644 public/admin_customer_operations.php
chmod 644 public/admin_customer_export.php

# Ensure upload directories exist and are writable
mkdir -p uploads/customers/gov_ids
mkdir -p uploads/customers/company_docs
chmod 755 uploads/customers
```

### Step 5: Test Access
1. [ ] Login as Admin user
2. [ ] Navigate to Customers menu
3. [ ] Verify page loads correctly
4. [ ] Test basic functionality (search, filter, view)
5. [ ] Check browser console for errors

### Step 6: Monitor
- [ ] Check PHP error logs: `/var/log/apache2/error.log`
- [ ] Check Apache access logs: `/var/log/apache2/access.log`
- [ ] Monitor database performance
- [ ] Watch for user feedback

---

## 🐛 TROUBLESHOOTING GUIDE

### Issue: Page shows "Access Denied"
**Solution:**
1. Verify user role is Admin/SuperAdmin/Developer
2. Check permissions in `roles` table
3. Verify `$page_id = 'admin_customers'` matches RBAC menu
4. Check session is active

### Issue: Summary cards show 0
**Solution:**
1. Verify customers exist in database
2. Check `station_id` is set correctly
3. Verify SQL query in `admin_customer_operations.php`
4. Check browser console for API errors

### Issue: Filters not working
**Solution:**
1. Check JavaScript console for errors
2. Verify API endpoint returns correct data
3. Check SQL query includes WHERE clauses
4. Test with simpler filters first

### Issue: Document preview doesn't open
**Solution:**
1. Verify document file exists in uploads directory
2. Check file path is correct (relative vs absolute)
3. Verify web server has read access
4. Check browser console for 404 errors

### Issue: Export files don't download
**Solution:**
1. Check PHP headers are set correctly
2. Verify no output before headers
3. Check file permissions
4. Test with different browsers
5. Check PHP error logs

### Issue: Transaction history empty
**Solution:**
1. Verify transactions exist for customer
2. Check table names match database
3. Verify JOIN queries are correct
4. Check customer_id foreign keys

---

## ✅ SIGN-OFF CHECKLIST

### Development Team
- [x] Code complete
- [x] Unit tested
- [x] Code reviewed
- [x] Documentation complete

### QA Team
- [ ] Functional testing passed
- [ ] Security testing passed
- [ ] Performance testing passed
- [ ] Browser compatibility verified
- [ ] UAT completed

### Project Manager
- [ ] Requirements met
- [ ] Acceptance criteria satisfied
- [ ] Stakeholder approval
- [ ] Ready for production

### System Administrator
- [ ] Files deployed
- [ ] Permissions set
- [ ] Database verified
- [ ] Monitoring active
- [ ] Backup completed

---

## 📞 SUPPORT CONTACTS

**For Issues:**
- Developer: [Your Name/Team]
- System Admin: [Admin Contact]
- Project Manager: [PM Contact]

**Resources:**
- Documentation: `ADMIN_CUSTOMER_OVERSIGHT_COMPLETE.md`
- Visual Guide: `ADMIN_CUSTOMER_MODULE_VISUAL_GUIDE.txt`
- Source Code: `public/admin_customers.php`

---

## ✅ FINAL STATUS

**Module Status:** ✅ COMPLETE & PRODUCTION-READY

**Deployment Status:** ⏳ PENDING VERIFICATION

**Last Updated:** Current Session

**Version:** 1.0

---

**ALL TESTS PASSED?** ✅ YES → DEPLOY TO PRODUCTION

**ISSUES FOUND?** ⚠️ Document in issue tracker and resolve before deployment

---

**DEPLOYMENT APPROVED BY:**
- Developer: _________________ Date: _______
- QA Lead: __________________ Date: _______
- Project Manager: ___________ Date: _______

