# ✅ Admin Map Functionality Checklist

## 🚀 Step-by-Step Verification Guide

Follow these steps to ensure the Admin Map Integration is fully functional.

---

## Step 1: Database Setup

### 1.1 Run Setup Test Page
```
1. Log in as SuperAdmin
2. Navigate to: http://localhost/group31petron_system_official4/public/test_map_setup.php
3. Review all test results
4. Check that database connection is successful
```

**Expected Result:** All tests should pass or show warnings with clear instructions.

### 1.2 Run Database Migration
```sql
-- Open phpMyAdmin (http://localhost/phpmyadmin)
-- Select your database
-- Go to SQL tab
-- Copy and paste contents of: database/migrations/add_station_coordinates.sql
-- Click "Go"
```

**Expected Result:** Success message. New columns added to stations table.

### 1.3 Add Sample Coordinates (Optional but Recommended)
```sql
-- In phpMyAdmin SQL tab
-- Copy and paste contents of: database/sample_station_coordinates.sql
-- Click "Go"
```

**Expected Result:** Stations now have latitude, longitude, and region data.

### 1.4 Verify Database Changes
```sql
-- Run this query in phpMyAdmin:
SHOW COLUMNS FROM stations;
```

**Expected Columns:**
- ✅ id
- ✅ name  
- ✅ location
- ✅ latitude (new)
- ✅ longitude (new)
- ✅ region (new)
- ✅ contact_number (new)
- ✅ status
- ✅ created_at
- ✅ updated_at

---

## Step 2: File Verification

### 2.1 Check New Files Exist
Navigate to your project folder and verify these files exist:

**Frontend:**
- ✅ `public/superadmin_admin_map.php`
- ✅ `public/test_map_setup.php`

**Backend:**
- ✅ `backend/api/superadmin_admin_map_api.php`

**Database:**
- ✅ `database/migrations/add_station_coordinates.sql`
- ✅ `database/sample_station_coordinates.sql`

**Documentation:**
- ✅ `ADMIN_MAP_README.md`
- ✅ `ADMIN_MAP_QUICK_START.md`
- ✅ `ADMIN_MAP_INTEGRATION_GUIDE.md`
- ✅ `ADMIN_MAP_FEATURE_SUMMARY.md`
- ✅ `ADMIN_MAP_TEST_CHECKLIST.md`
- ✅ `MAP_FUNCTIONALITY_CHECKLIST.md` (this file)

### 2.2 Verify Modified Files
Check that this file was updated:
- ✅ `public/superadmin_admin_management.php` (should have "Map View" button)

---

## Step 3: Access Testing

### 3.1 Login and Navigate
```
1. Open browser (Chrome, Firefox, or Edge)
2. Navigate to: http://localhost/group31petron_system_official4/public/index.php
3. Log in with SuperAdmin credentials
4. Go to: Admin Management page
```

**Expected Result:** Should see "Admin Management" page with "Map View" button.

### 3.2 Check Map View Button
```
1. On Admin Management page, look for header buttons
2. Should see: [Map View] [Add Station] [Create Admin Account]
```

**Expected Result:** "Map View" button visible in blue outline style.

### 3.3 Open Map View
```
1. Click "Map View" button
2. Wait for page to load
```

**Expected Result:** 
- Redirects to `superadmin_admin_map.php`
- Page loads without errors
- Map container visible

---

## Step 4: Map Loading Tests

### 4.1 Map Displays Correctly
```
✅ Map tiles load (shows Philippines map)
✅ Zoom controls (+/-) visible
✅ Map is centered on Philippines
✅ No JavaScript errors in browser console (F12 → Console tab)
```

### 4.2 Station Markers Appear
```
✅ Colored circular markers appear on map
✅ At least one marker visible
✅ Markers have correct colors:
   - Green = Station with active admin
   - Red = Station without admin
   - Yellow = Pending validation
```

### 4.3 UI Elements Visible
```
✅ Search box at top
✅ "All Regions" dropdown
✅ "All Status" dropdown
✅ Stats panel (top-left) with numbers
✅ Legend (bottom-right) with color explanations
✅ "List View" button in header
```

---

## Step 5: Functionality Tests

### 5.1 Search Functionality
```
1. Type station name in search box
2. Press Enter or wait for auto-filter

Expected Result:
✅ Only matching stations remain visible
✅ Non-matching markers disappear
✅ Filtered count updates in stats panel
✅ Clear search → all markers reappear
```

### 5.2 Region Filter
```
1. Click "All Regions" dropdown
2. Select "NCR" (or any region with stations)

Expected Result:
✅ Only stations in selected region visible
✅ Other markers hidden
✅ Filtered count updates
✅ Select "All Regions" → all markers reappear
```

### 5.3 Status Filter
```
1. Click "All Status" dropdown
2. Select "Active Admin"

Expected Result:
✅ Only green markers visible
✅ Red/yellow markers hidden
✅ Stats update correctly
```

### 5.4 Marker Click
```
1. Click any station marker on map

Expected Result:
✅ Modal dialog opens
✅ Shows station name in title
✅ Shows station address
✅ Shows contact number (or "—")
✅ Shows current admin (or "None")
✅ Shows status badge with color
✅ Shows "Assign Admin" dropdown
✅ Shows "Close" and "Assign Admin" buttons
```

### 5.5 Modal Interactions
```
1. With modal open, click outside modal (on backdrop)
Expected: ✅ Modal closes

2. With modal open, click X button
Expected: ✅ Modal closes

3. With modal open, click "Close" button
Expected: ✅ Modal closes
```

---

## Step 6: Admin Assignment Tests

### 6.1 Assign Admin to Empty Station
```
Prerequisite: Have at least 1 station with no admin, and 1 unassigned admin

1. Click red marker (station with no admin)
2. Modal opens showing "Current Admin: None"
3. Click "Assign Admin" dropdown
4. Select an admin from list
5. Click "Assign Admin" button

Expected Result:
✅ Button shows "Assigning..." with spinner
✅ Success message appears
✅ Page reloads automatically
✅ Marker color changes to green
✅ Stats panel updates (With Admin +1, Without Admin -1)

Verify in Database:
SELECT * FROM users WHERE id = [admin_id];
-- station_id should now be set to the station ID
```

### 6.2 Reassign Admin to Different Station
```
Prerequisite: Have 2 stations, Station A has an admin, Station B doesn't

1. Note which admin is on Station A (should be green marker)
2. Click marker for Station B
3. In dropdown, select the admin from Station A
4. Click "Assign Admin"

Expected Result:
✅ Success message
✅ Page reloads
✅ Station A marker turns red (no admin)
✅ Station B marker turns green (has admin)
✅ Only Station B has the admin assigned

Verify in Database:
SELECT station_id FROM users WHERE id = [admin_id];
-- Should show Station B's ID, not Station A's
```

### 6.3 Error Handling
```
Test 1: Try to assign without selecting admin
1. Click station marker
2. Leave dropdown empty
3. Click "Assign Admin"
Expected: ✅ Error message "Please select an admin"

Test 2: Network simulation (optional)
1. Open DevTools (F12) → Network tab
2. Set throttling to "Offline"
3. Try to assign admin
Expected: ✅ Error message about network
```

---

## Step 7: Browser Console Check

### 7.1 Check for JavaScript Errors
```
1. Open browser DevTools (F12)
2. Go to Console tab
3. Look for any red error messages

Expected Result:
✅ No JavaScript errors
✅ No 404 errors for Leaflet.js files
✅ No CORS errors
```

### 7.2 Check Network Requests
```
1. Open DevTools → Network tab
2. Refresh the page
3. Check that these load successfully:
   - leaflet.css
   - leaflet.js
   - superadmin_admin_map.php
   - Map tiles from OpenStreetMap

Expected Result:
✅ All requests return 200 status
✅ No failed requests (red)
```

---

## Step 8: Browser Compatibility

### 8.1 Test in Different Browsers
```
✅ Chrome: Map loads and functions correctly
✅ Firefox: Map loads and functions correctly  
✅ Edge: Map loads and functions correctly
✅ Safari (if available): Map loads and functions correctly
```

---

## Step 9: Security Verification

### 9.1 Authentication Test
```
1. Log out
2. Try to access: http://localhost/.../public/superadmin_admin_map.php directly

Expected Result:
✅ Redirects to login page
✅ Cannot access without authentication
```

### 9.2 Authorization Test
```
1. Log in as Staff or Manager (not SuperAdmin)
2. Try to access map URL directly

Expected Result:
✅ Access denied or redirected
✅ Only SuperAdmin/Developer can access
```

### 9.3 CSRF Protection
```
1. Open DevTools → Network tab
2. Assign an admin to a station
3. Look at the POST request to superadmin_admin_map_api.php
4. Check FormData includes "csrf_token"

Expected Result:
✅ CSRF token is sent with request
✅ Server validates token
```

---

## Step 10: Activity Logging

### 10.1 Check Logs After Assignment
```
1. Assign an admin to a station
2. Go to phpMyAdmin
3. Run this query:

SELECT * FROM activity_logs 
WHERE action LIKE '%Admin Assignment%' 
OR action LIKE '%Admin Unassignment%'
ORDER BY created_at DESC 
LIMIT 5;

Expected Result:
✅ Log entry exists for the assignment
✅ Contains user ID
✅ Contains timestamp
✅ Contains station name and admin name in details
```

---

## Step 11: Performance Check

### 11.1 Page Load Time
```
1. Open DevTools → Network tab
2. Refresh map page
3. Check total load time at bottom

Expected Result:
✅ Page loads in < 3 seconds
✅ Map initializes in < 2 seconds
✅ No hanging or freezing
```

### 11.2 Interaction Response
```
1. Click filters multiple times
2. Search for stations
3. Click markers

Expected Result:
✅ Filters apply instantly (< 100ms)
✅ Search updates smoothly
✅ Markers respond immediately to clicks
✅ No lag or delay
```

---

## Step 12: Data Integrity

### 12.1 Verify Station Count
```
In phpMyAdmin, run:
SELECT COUNT(*) as total FROM stations;

Compare with:
- Number shown in Stats panel on map
- Number of markers visible

Expected Result:
✅ Counts match
```

### 12.2 Verify Admin Assignments
```
In phpMyAdmin, run:
SELECT 
    s.name as station,
    CONCAT(u.first_name, ' ', u.last_name) as admin
FROM stations s
LEFT JOIN users u ON u.station_id = s.id AND u.role = 'admin'
ORDER BY s.name;

Compare with:
- What map shows for each station

Expected Result:
✅ Database and map show same assignments
```

---

## Step 13: Mobile Responsiveness (Optional)

### 13.1 Test on Mobile/Tablet
```
1. Open DevTools (F12)
2. Click device toolbar icon (or Ctrl+Shift+M)
3. Select "iPhone 12" or "iPad"
4. Test map interactions

Expected Result:
✅ Map displays correctly
✅ Touch gestures work (pinch to zoom, drag to pan)
✅ Markers are tappable
✅ Modal fits screen
✅ Controls are accessible
```

---

## 🎯 Final Verification Checklist

### ✅ All Core Features Working
- [ ] Map loads and displays Philippines
- [ ] Station markers appear with correct colors
- [ ] Search functionality works
- [ ] Region filter works
- [ ] Status filter works
- [ ] Clicking markers opens modal
- [ ] Admin assignment works
- [ ] Stats panel updates in real-time
- [ ] Legend is visible and correct

### ✅ Security Features Active
- [ ] Authentication required
- [ ] Authorization enforced (SuperAdmin only)
- [ ] CSRF protection working
- [ ] Activity logging functional

### ✅ No Errors
- [ ] No JavaScript console errors
- [ ] No PHP errors
- [ ] No 404 errors
- [ ] No database errors

### ✅ Performance Acceptable
- [ ] Page loads quickly (< 3s)
- [ ] Interactions are smooth
- [ ] No freezing or lag

---

## 🐛 Troubleshooting Common Issues

### Issue: Map tiles not loading (blank gray squares)
**Solution:** Check internet connection. Leaflet needs CDN access for tiles.

### Issue: No markers appear
**Solution:**
1. Check if stations exist: `SELECT COUNT(*) FROM stations;`
2. Check if stations have Active status
3. Run sample_station_coordinates.sql to add coordinates

### Issue: "Map View" button missing
**Solution:** Clear browser cache and refresh page. Verify superadmin_admin_management.php was updated correctly.

### Issue: Cannot assign admin - dropdown empty
**Solution:** Create admin accounts with no station assignment:
```sql
SELECT * FROM users WHERE role = 'admin' AND (station_id IS NULL OR station_id = 0);
```

### Issue: Markers all in one location
**Solution:** Stations don't have unique coordinates. Run sample_station_coordinates.sql or manually set lat/lng.

### Issue: Page not found (404)
**Solution:** Verify file exists at: `public/superadmin_admin_map.php`

---

## ✅ Success Criteria

**Feature is fully functional when:**

1. ✅ Setup test page shows all tests passing
2. ✅ Map loads without errors
3. ✅ All station markers visible with correct colors
4. ✅ Search, filters, and marker clicks work
5. ✅ Admin assignment completes successfully
6. ✅ Database updates correctly
7. ✅ Activity logs record assignments
8. ✅ No console errors
9. ✅ Performance is acceptable
10. ✅ Security features are active

---

## 📞 Need Help?

If any test fails:
1. Check the error message in browser console (F12)
2. Check PHP error logs
3. Review the documentation:
   - `ADMIN_MAP_QUICK_START.md` - Quick setup
   - `ADMIN_MAP_INTEGRATION_GUIDE.md` - Complete guide
   - `ADMIN_MAP_TEST_CHECKLIST.md` - Detailed testing

---

**Last Updated:** June 14, 2026  
**Version:** 1.0.0  
**Status:** ✅ Ready for Testing
