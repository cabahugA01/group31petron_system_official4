# Admin Staff Oversight - Testing Guide

**Date:** June 4, 2026  
**Module:** Admin Staff Oversight  
**Status:** Ready for Testing

---

## 🎯 Testing Objective

Verify that all CRITICAL security bugs have been fixed and the module is production-ready.

---

## 🔒 Security Tests (CRITICAL)

### Test 1: XSS Protection in Remarks
**Priority:** CRITICAL  
**What to test:** Verify that malicious scripts cannot be injected through remarks field

**Steps:**
1. Login as Admin or Superadmin
2. Navigate to Admin Staff Oversight page
3. Click edit remarks (pencil icon) for any staff member
4. Enter this in remarks field:
   ```
   <script>alert('XSS Attack!')</script>
   ```
5. Click "Save changes"
6. Refresh the page

**Expected Result:**
- ✅ The text `<script>alert('XSS Attack!')</script>` should display as plain text
- ✅ NO JavaScript alert should pop up
- ✅ The script should NOT execute

**If this fails:** 🚨 CRITICAL SECURITY ISSUE - Do not deploy to production

---

### Test 2: HTML Injection in Remarks
**Priority:** CRITICAL  
**What to test:** Verify HTML tags are escaped

**Steps:**
1. Edit remarks for a staff member
2. Enter this:
   ```
   <b>Bold Text</b> <img src=x onerror=alert('XSS')>
   ```
3. Save and refresh

**Expected Result:**
- ✅ Text displays as: `<b>Bold Text</b> <img src=x onerror=alert('XSS')>`
- ✅ NO bold formatting applied
- ✅ NO alert executes

---

### Test 3: Status Validation (Database Protection)
**Priority:** CRITICAL  
**What to test:** Verify 'suspended' status is rejected until database supports it

**Steps:**
1. Open browser Developer Tools (F12)
2. Go to Console tab
3. Execute this JavaScript:
   ```javascript
   fetch('../backend/api/admin_staff_oversight_api.php', {
       method: 'POST',
       body: new URLSearchParams({
           action: 'update_status',
           staff_id: '1',
           status: 'suspended'
       })
   }).then(r => r.json()).then(console.log)
   ```

**Expected Result:**
- ✅ Response should be: `{"success":false,"error":"Invalid parameters. Status must be active or inactive."}`
- ✅ Status should NOT be updated in database

**If this fails:** 🚨 Database constraint error will occur

---

## ✅ Functionality Tests

### Test 4: View Staff List
**Priority:** HIGH  
**Steps:**
1. Login as Admin → Should see only your station's staff
2. Login as Superadmin → Should see all stations' staff
3. Verify columns display correctly:
   - Account ID / Name
   - Assigned Role (Manager/Staff)
   - Station / Branch
   - Account Status (Badge with color)
   - Recent Activity (Last login, last transaction)
   - Activity Summary (Requests count, Deliveries count)
   - Remarks

**Expected Result:**
- ✅ All data displays correctly
- ✅ Admin sees only their station
- ✅ Superadmin sees all stations

---

### Test 5: Edit User Account
**Priority:** HIGH  
**Steps:**
1. Click "Edit" button for any staff member
2. Change the name to "Test User Updated"
3. Change role from Staff → Manager (or vice versa)
4. Change status from Active → Inactive
5. Click "Save changes"

**Expected Result:**
- ✅ Modal closes
- ✅ Table refreshes automatically
- ✅ Changes are visible immediately
- ✅ If changing to Manager when one already exists → Should show error: "This station already has a manager"

---

### Test 6: Activate/Deactivate User
**Priority:** HIGH  
**Steps:**
1. Find an Active user
2. Click "Deactivate" button
3. Confirm deactivation in modal
4. Find an Inactive user
5. Click "Activate" button

**Expected Result:**
- ✅ Deactivate shows confirmation modal
- ✅ Activate works immediately without confirmation
- ✅ Button shows loading spinner during request
- ✅ Button is disabled during request (cannot click multiple times)
- ✅ Status badge updates after action
- ✅ Table refreshes automatically

---

### Test 7: Edit Remarks
**Priority:** MEDIUM  
**Steps:**
1. Click pencil icon next to remarks
2. Enter: "Flagged for performance review - June 2026"
3. Click "Save changes"
4. Verify remarks display correctly

**Expected Result:**
- ✅ Modal opens with current remarks
- ✅ Can save empty remarks
- ✅ Can save long text (up to 500 characters)
- ✅ Remarks display correctly in table

---

### Test 8: Refresh Button
**Priority:** LOW  
**Steps:**
1. Click "Refresh" button
2. Observe table reload

**Expected Result:**
- ✅ Table reloads with latest data
- ✅ No errors in console

---

## 🚨 Error Handling Tests

### Test 9: Network Error Handling
**Priority:** MEDIUM  
**Steps:**
1. Open browser Developer Tools (F12) → Network tab
2. Set throttling to "Offline"
3. Click "Refresh" button

**Expected Result:**
- ✅ Alert message: "An error occurred while fetching staff data: Failed to fetch" (or similar)
- ✅ Error logged in console with details

---

### Test 10: Invalid Station Assignment (Admin Only)
**Priority:** MEDIUM  
**Steps:**
1. Login as Admin
2. Manually update database to set your station_id to 0 or NULL:
   ```sql
   UPDATE users SET station_id = 0 WHERE id = YOUR_ADMIN_ID;
   ```
3. Refresh Admin Staff Oversight page

**Expected Result:**
- ✅ Error message: "Invalid station assignment. Please contact system administrator."
- ✅ No staff data displayed
- ✅ No SQL errors in console

---

### Test 11: Rapid Button Clicking (Race Condition)
**Priority:** LOW  
**Steps:**
1. Find an Inactive user
2. Click "Activate" button 5 times rapidly

**Expected Result:**
- ✅ Button disables immediately on first click
- ✅ Loading spinner shows
- ✅ Only ONE request sent to server (check Network tab)
- ✅ Button re-enables after response

---

### Test 12: Manager Limit Validation
**Priority:** HIGH  
**Steps:**
1. Find a station that already has 1 Manager
2. Click "Edit" on a Staff member
3. Change role to "Manager"
4. Click "Save changes"

**Expected Result:**
- ✅ Error alert: "This station already has a manager. Only one manager is allowed per station."
- ✅ Change is NOT saved
- ✅ User remains as Staff

---

### Test 13: Form Validation
**Priority:** MEDIUM  
**Steps:**
1. Click "Edit" on any user
2. Clear the Name field
3. Click "Save changes"

**Expected Result:**
- ✅ Browser shows validation error: "Please fill out this field"
- ✅ Modal does NOT close
- ✅ No request sent to server

---

## 📊 Data Accuracy Tests

### Test 14: Activity Summary Counts
**Priority:** MEDIUM  
**Steps:**
1. Pick a staff member
2. Note their Request count and Delivery count
3. Manually count in database:
   ```sql
   -- For Staff (encoded)
   SELECT COUNT(*) FROM stock_requests WHERE staff_id = STAFF_ID;
   SELECT COUNT(*) FROM fuel_deliveries WHERE received_by = STAFF_ID;
   
   -- For Manager (validated)
   SELECT COUNT(*) FROM stock_requests WHERE manager_id = MANAGER_ID AND status IN ('Approved', 'Validated');
   SELECT COUNT(*) FROM fuel_deliveries WHERE verified_by = MANAGER_ID AND status = 'Delivered';
   ```

**Expected Result:**
- ✅ Counts match database exactly

---

### Test 15: Last Login and Last Transaction
**Priority:** MEDIUM  
**Steps:**
1. Login as a Staff user
2. Perform an action (e.g., encode a request)
3. Logout
4. Login as Admin
5. Check that staff's last login and last transaction updated

**Expected Result:**
- ✅ Last login shows current date/time
- ✅ Last transaction shows recent action

---

## 🔐 Authorization Tests

### Test 16: Admin Station Isolation
**Priority:** HIGH  
**Steps:**
1. Login as Admin at Station A
2. Note all staff displayed
3. Verify all staff belong to Station A
4. Login as Admin at Station B
5. Verify you see DIFFERENT staff (Station B only)

**Expected Result:**
- ✅ Admin A sees only Station A staff
- ✅ Admin B sees only Station B staff
- ✅ No cross-station access

---

### Test 17: Superadmin Full Access
**Priority:** HIGH  
**Steps:**
1. Login as Superadmin
2. Verify staff from ALL stations are visible
3. Edit a user from Station A
4. Edit a user from Station B

**Expected Result:**
- ✅ Can see all stations
- ✅ Can edit users from any station

---

### Test 18: Role Access Control
**Priority:** CRITICAL  
**Steps:**
1. Login as Staff or Manager
2. Try to access: `http://localhost/group31petron_system_official4/public/admin_staff_oversight.php`

**Expected Result:**
- ✅ Redirected to dashboard
- ✅ Error message: "Access denied. Admin privileges required."

---

## 📝 Test Results Template

```
Date: __________
Tester: __________
Browser: __________

| Test # | Test Name | Status | Notes |
|--------|-----------|--------|-------|
| 1 | XSS Protection | ☐ PASS ☐ FAIL | |
| 2 | HTML Injection | ☐ PASS ☐ FAIL | |
| 3 | Status Validation | ☐ PASS ☐ FAIL | |
| 4 | View Staff List | ☐ PASS ☐ FAIL | |
| 5 | Edit User | ☐ PASS ☐ FAIL | |
| 6 | Activate/Deactivate | ☐ PASS ☐ FAIL | |
| 7 | Edit Remarks | ☐ PASS ☐ FAIL | |
| 8 | Refresh Button | ☐ PASS ☐ FAIL | |
| 9 | Network Error | ☐ PASS ☐ FAIL | |
| 10 | Invalid Station | ☐ PASS ☐ FAIL | |
| 11 | Rapid Clicking | ☐ PASS ☐ FAIL | |
| 12 | Manager Limit | ☐ PASS ☐ FAIL | |
| 13 | Form Validation | ☐ PASS ☐ FAIL | |
| 14 | Activity Counts | ☐ PASS ☐ FAIL | |
| 15 | Last Login/Txn | ☐ PASS ☐ FAIL | |
| 16 | Station Isolation | ☐ PASS ☐ FAIL | |
| 17 | Superadmin Access | ☐ PASS ☐ FAIL | |
| 18 | Role Access Control | ☐ PASS ☐ FAIL | |

Overall Result: ☐ PASS ☐ FAIL

Critical Issues Found: _______________
```

---

## 🚀 Deployment Checklist

Before deploying to production:

- [ ] All 18 tests pass
- [ ] No JavaScript errors in console
- [ ] No PHP errors in server logs
- [ ] XSS protection verified
- [ ] Status validation working correctly
- [ ] Manager limit enforced
- [ ] Admin station isolation confirmed
- [ ] Role access control tested
- [ ] Error handling tested
- [ ] Database backup created

---

## 🐛 If Bugs Found

### Critical Bugs (DO NOT DEPLOY):
- XSS vulnerability not fixed
- Database errors on status update
- Authorization bypass possible

### High Priority (Fix before deploy):
- Manager limit not enforced
- Station isolation broken
- Data inaccuracy

### Medium Priority (Can deploy with known issues):
- UI glitches
- Error messages unclear
- Performance issues

### Low Priority (Fix in next release):
- Cosmetic issues
- Minor UX improvements

---

**Test Status:** ⏳ Ready for Testing  
**Expected Duration:** 30-45 minutes  
**Required Roles:** Admin, Superadmin, Staff (for authorization test)

---

## 📞 Support

If tests fail, check:
1. Browser console for JavaScript errors
2. Server error logs for PHP errors
3. Network tab for failed requests
4. Database logs for SQL errors

**Good luck with testing! 🚀**
