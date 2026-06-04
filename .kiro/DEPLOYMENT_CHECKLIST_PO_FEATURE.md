# Deployment Checklist - PO Generation Feature

**Feature:** Purchase Order Generation from Stock Requests  
**Date:** June 4, 2026  
**Status:** Ready for Testing

---

## Pre-Deployment Checks

### 1. Database Verification

#### Check Required Tables Exist:
```sql
-- Verify tables exist
SHOW TABLES LIKE 'stock_requests';
SHOW TABLES LIKE 'purchase_orders';
SHOW TABLES LIKE 'purchase_order_items';
SHOW TABLES LIKE 'station_inventory';

-- Expected: All 4 tables should exist
```

#### Verify Table Structures:
```sql
-- Check stock_requests has required columns
DESCRIBE stock_requests;
-- Must have: id, status (enum with 'Validated'), item_name, 
--            approved_quantity, requested_quantity, item_id, 
--            item_sku, station_id

-- Check purchase_orders has request_id column
DESCRIBE purchase_orders;
-- Must have: id, request_id, po_number, status, 
--            product_name, quantity, unit_price, 
--            total_amount, type, created_by, station_id

-- Check purchase_order_items structure
DESCRIBE purchase_order_items;
-- Must have: id, po_id, item_name, quantity, 
--            product_id, unit_price, total_price
```

#### Check Indexes:
```sql
-- Check for index on request_id (performance)
SHOW INDEX FROM purchase_orders WHERE Column_name = 'request_id';

-- If not exists, create:
-- CREATE INDEX idx_request_id ON purchase_orders(request_id);
```

### 2. File Integrity

#### Verify Files Exist:
- [ ] `public/manager_fuel_stock_requests.php`
- [ ] `public/manager_fuel_management_complete.php`
- [ ] `backend/lib.php`
- [ ] `public/db_connect.php`
- [ ] `partials/header.php`
- [ ] `partials/footer.php`

#### Check File Permissions:
```bash
# Windows (PowerShell)
Get-Acl "public\manager_fuel_stock_requests.php"
Get-Acl "public\manager_fuel_management_complete.php"

# Should have read/execute permissions for web server user
```

### 3. PHP Environment

#### Check PHP Version:
```bash
php -v
# Must be PHP 8.0 or higher (uses match expression)
```

#### Check Required Extensions:
```bash
php -m | findstr pdo
php -m | findstr mysqli
# Both PDO and MySQLi should be installed
```

### 4. User Permissions

#### Verify RBAC Configuration:
```sql
-- Check manager has required permissions
SELECT p.permission_key 
FROM user_permissions up
JOIN permissions p ON up.permission_id = p.id
JOIN users u ON up.user_id = u.id
WHERE u.role = 'manager'
  AND p.permission_key IN ('manage_inventory', 'view_inventory');

-- Expected: Both permissions should exist
```

#### Check Manager Menu Access:
- [ ] Navigate to `partials/rbac_menu.php`
- [ ] Verify manager has 'inventory' menu item
- [ ] Verify sub-item `mgr_inv_stock_request` exists

---

## Deployment Steps

### Step 1: Backup Current Files
```bash
# Backup modified files
copy public\manager_fuel_stock_requests.php public\manager_fuel_stock_requests.php.backup
copy public\manager_fuel_management_complete.php public\manager_fuel_management_complete.php.backup
```

### Step 2: Database Backup
```sql
-- Backup affected tables
mysqldump -u root -p petron_pos_db stock_requests > stock_requests_backup.sql
mysqldump -u root -p petron_pos_db purchase_orders > purchase_orders_backup.sql
mysqldump -u root -p petron_pos_db purchase_order_items > purchase_order_items_backup.sql
```

### Step 3: Deploy Code Changes
- [ ] Upload `manager_fuel_stock_requests.php` to server
- [ ] Upload `manager_fuel_management_complete.php` to server
- [ ] Verify file upload success
- [ ] Check file permissions (read/execute)

### Step 4: Clear Cache
```bash
# Clear PHP opcache (if enabled)
# Restart PHP-FPM or Apache

# Windows XAMPP
# Stop and start Apache service
```

### Step 5: Verify Access
- [ ] Access URL: `http://localhost/group31petron_system_official4/public/manager_fuel_stock_requests.php`
- [ ] Should load without errors
- [ ] Check browser console for JavaScript errors

---

## Testing Phase

### Test 1: Page Load
- [ ] Login as Manager
- [ ] Navigate to Inventory → Stock Request Validation
- [ ] Page loads successfully
- [ ] No PHP errors
- [ ] No JavaScript errors in console
- [ ] Summary cards display correctly
- [ ] Both sections visible (Merchandise & Fuel)

### Test 2: View Validated Requests
- [ ] Create/use existing validated stock request
- [ ] Request appears in Merchandise section
- [ ] Status badge shows "Validated"
- [ ] Item details display correctly
- [ ] "Generate PO" button is visible

### Test 3: Generate PO - Happy Path
- [ ] Click "Generate PO" button
- [ ] Modal opens with correct details
- [ ] Item name displays correctly
- [ ] Approved quantity displays correctly
- [ ] Click "Generate PO" in modal
- [ ] Form submits
- [ ] Success message appears
- [ ] PO number displays in format: PO-YYYYMMDD-SR####
- [ ] Page refreshes/updates
- [ ] Button replaced with PO number display

### Test 4: Database Verification
```sql
-- Check PO was created
SELECT * FROM purchase_orders 
WHERE created_at >= CURDATE()
ORDER BY created_at DESC LIMIT 1;

-- Verify fields:
-- - request_id is not NULL
-- - po_number matches format
-- - status = 'Pending Admin Validation'
-- - type = 'merch'
-- - created_by = Manager user ID
-- - product_name matches request
-- - quantity matches approved_quantity

-- Check PO items created
SELECT * FROM purchase_order_items
WHERE po_id = [po_id_from_above];

-- Verify fields:
-- - item_name matches
-- - quantity matches
-- - unit_price > 0
-- - total_price = quantity × unit_price
```

### Test 5: Duplicate Prevention
- [ ] Refresh page
- [ ] Same request should show PO number (not button)
- [ ] Try to generate PO again via direct POST
- [ ] Should return error: "Purchase Order already exists"

### Test 6: Error Handling
- [ ] Try to generate PO for non-existent request
- [ ] Should show error message
- [ ] Try to generate PO for non-validated request
- [ ] Should show error message
- [ ] Try to generate PO without proper permissions
- [ ] Should redirect or show access denied

### Test 7: Multi-Station Testing
- [ ] Login as Manager for Station A
- [ ] Validate request for Station A
- [ ] Generate PO
- [ ] Login as Manager for Station B
- [ ] Should NOT see Station A's requests
- [ ] Should NOT be able to generate PO for Station A

### Test 8: Admin Workflow
- [ ] Login as Admin
- [ ] Navigate to Purchase Orders
- [ ] Should see new PO with status "Pending Admin Validation"
- [ ] Should see request_id linked
- [ ] Approve PO
- [ ] Print PO
- [ ] Status should change to "Official"

---

## Performance Testing

### Load Time
- [ ] Page load time < 3 seconds
- [ ] Modal open time < 500ms
- [ ] PO generation time < 2 seconds

### Database Performance
```sql
-- Check query execution time
EXPLAIN SELECT sr.*, u.name AS staff_name, m.name AS manager_name,
               po.id as po_id, po.po_number
FROM stock_requests sr
JOIN users u ON sr.staff_id = u.id
LEFT JOIN users m ON sr.manager_id = m.id
LEFT JOIN purchase_orders po ON po.request_id = sr.id
WHERE sr.station_id = 1253;

-- Should use indexes, execution time < 100ms
```

### Concurrent Users
- [ ] Test with 5 concurrent managers
- [ ] Each generates PO for different request
- [ ] No deadlocks or race conditions
- [ ] All POs created successfully

---

## Security Testing

### Authentication
- [ ] Logged out user cannot access page
- [ ] Redirects to login
- [ ] Session timeout works correctly

### Authorization
- [ ] Staff cannot access Manager page
- [ ] Manager can only see own station requests
- [ ] Cannot generate PO for other station

### Input Validation
- [ ] Invalid request_id rejected
- [ ] SQL injection attempts blocked (prepared statements)
- [ ] XSS attempts sanitized (htmlspecialchars)

### CSRF Protection
- [ ] Check if CSRF tokens used (if implemented)
- [ ] Direct POST without proper session fails

---

## Browser Compatibility

### Desktop Browsers
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Edge (latest)
- [ ] Safari (if available)

### Mobile Browsers
- [ ] Chrome Mobile
- [ ] Safari iOS
- [ ] Responsive design works

### Test Items:
- [ ] Modal displays correctly
- [ ] Buttons clickable
- [ ] Forms submit properly
- [ ] Tables scroll/responsive
- [ ] Icons display correctly

---

## Rollback Plan

### If Critical Issues Found:

#### 1. Restore Files
```bash
# Restore from backup
copy public\manager_fuel_stock_requests.php.backup public\manager_fuel_stock_requests.php
copy public\manager_fuel_management_complete.php.backup public\manager_fuel_management_complete.php
```

#### 2. Rollback Database (if needed)
```sql
-- Delete test POs created
DELETE FROM purchase_order_items
WHERE po_id IN (
    SELECT id FROM purchase_orders 
    WHERE created_at >= '2026-06-04' AND type='merch'
);

DELETE FROM purchase_orders
WHERE created_at >= '2026-06-04' AND type='merch';

-- Or restore from backup
-- mysql -u root -p petron_pos_db < purchase_orders_backup.sql
```

#### 3. Clear Cache
```bash
# Restart web server
# Clear browser cache
```

#### 4. Notify Users
- [ ] Inform managers of rollback
- [ ] Provide timeline for fix
- [ ] Document issues found

---

## Post-Deployment Monitoring

### First 24 Hours

#### Monitor Error Logs
```bash
# Check PHP error log
type C:\xampp\php\logs\php_error_log

# Check Apache error log
type C:\xampp\apache\logs\error.log

# Look for:
# - SQL errors
# - PHP warnings/notices
# - Permission errors
```

#### Monitor Database
```sql
-- Check PO creation rate
SELECT DATE(created_at) as date, COUNT(*) as po_count
FROM purchase_orders
WHERE type='merch' AND created_at >= CURDATE() - INTERVAL 7 DAY
GROUP BY DATE(created_at);

-- Check for orphaned POs (no request_id)
SELECT COUNT(*) FROM purchase_orders 
WHERE request_id IS NULL AND type='merch';

-- Check average PO generation time
-- (requires logging table or monitoring tool)
```

#### User Feedback
- [ ] Collect feedback from managers
- [ ] Document any usability issues
- [ ] Track feature usage metrics

### First Week

#### Performance Metrics
- [ ] Average page load time
- [ ] PO generation success rate
- [ ] Error rate
- [ ] User satisfaction score

#### Issue Tracking
- [ ] Log all reported bugs
- [ ] Prioritize by severity
- [ ] Track resolution time

---

## Success Criteria

### Must Pass:
✅ All critical tests pass (Tests 1-8)  
✅ No security vulnerabilities found  
✅ Performance within acceptable limits  
✅ No data corruption  
✅ Rollback plan tested and works  

### Should Pass:
✅ All browsers supported  
✅ Mobile responsive  
✅ User feedback positive  
✅ Error rate < 1%  

### Nice to Have:
✅ Load time < 2 seconds  
✅ Zero bugs reported in first week  
✅ High user adoption rate  

---

## Sign-Off Checklist

### Development Team:
- [ ] Code review complete
- [ ] Unit tests passed
- [ ] Documentation complete
- [ ] Deployment guide created

### QA Team:
- [ ] All test cases executed
- [ ] Bugs logged and prioritized
- [ ] Test report generated
- [ ] Signed off for staging

### Manager/Stakeholder:
- [ ] Feature demo completed
- [ ] Acceptance criteria met
- [ ] Training materials provided
- [ ] Signed off for production

### DevOps:
- [ ] Backup verified
- [ ] Rollback plan tested
- [ ] Monitoring configured
- [ ] Signed off for deployment

---

## Contact Information

### In Case of Issues:

**Development Team:**
- Contact: [Your contact info]
- Available: [Hours]

**Database Admin:**
- Contact: [DBA contact]
- Available: [Hours]

**System Admin:**
- Contact: [Sysadmin contact]
- Available: [Hours]

**Escalation:**
- Level 1: Development Team
- Level 2: Technical Lead
- Level 3: CTO/System Owner

---

## Notes

### Known Limitations:
- Can only generate one PO at a time
- No PO editing after generation
- No price override during generation
- Merchandise requests only (fuel separate)

### Future Enhancements:
- Bulk PO generation
- PO editing capability
- Email notifications
- Supplier selection
- Mobile app integration

---

## Deployment History

| Date | Version | Changes | Deployed By | Status |
|------|---------|---------|-------------|--------|
| 2026-06-04 | 1.0 | Initial PO generation feature | [Name] | Pending |

---

**Last Updated:** June 4, 2026  
**Document Version:** 1.0  
**Status:** Ready for Deployment
