# 🧪 Integration Settings – Testing Guide

## 📋 Pre-Testing Checklist

### Requirements:
- ✅ User logged in as **SuperAdmin** or **Developer**
- ✅ Database tables created (auto-created on first visit)
- ✅ Browser with JavaScript enabled
- ✅ Network access for external API testing (optional)

### Access URL:
```
http://localhost/group31petron_system_official4/public/superadmin_integration_settings.php
```

---

## 🔹 Test Suite 1: API Connections

### Test 1.1: Add Fleet Card API Configuration
**Steps:**
1. Navigate to: Integration Settings → API Connections
2. Click: `[+ Add API Config]`
3. Fill in form:
   - Config Name: `Test Fleet Card API`
   - Endpoint URL: `https://api.example.com/fleet`
   - Auth Type: Select `API_KEY`
   - API Key: `test_key_12345`
4. Click: `[Save Config]`

**Expected Results:**
- ✅ Success message: "API Config saved"
- ✅ New entry appears in Fleet Card API table
- ✅ Status shows "UNTESTED" badge (gray)
- ✅ Audit log entry created

**Database Verification:**
```sql
SELECT * FROM api_config ORDER BY id DESC LIMIT 1;
```

---

### Test 1.2: Test API Connection
**Steps:**
1. Locate the newly added API config
2. Click: `[Test]` button
3. Wait for response

**Expected Results:**
- ✅ Alert or notification shows test result
- ✅ Status badge updates to "OK" (green) or "FAIL" (red)
- ✅ "Last Test" column shows current timestamp
- ✅ Audit log entry for test action

**Database Verification:**
```sql
SELECT * FROM api_config WHERE id = [your_id];
-- Check: test_status, last_tested_at
```

---

### Test 1.3: Add ERP Connection
**Steps:**
1. Scroll to "ERP System Connections" section
2. Click: `[+ Add ERP Connection]`
3. Fill in form:
   - Connection Name: `Test SAP ERP`
   - Endpoint URL: `https://erp.example.com/api`
   - Auth Keys: `{"username":"admin","password":"secret"}`
4. Click: `[Save]`

**Expected Results:**
- ✅ Success message displayed
- ✅ New ERP connection in table
- ✅ Status shows "DISCONNECTED" (gray)

**Database Verification:**
```sql
SELECT * FROM erp_connections ORDER BY id DESC LIMIT 1;
```

---

### Test 1.4: Edit API Configuration
**Steps:**
1. Click: `[Edit]` button on existing config
2. Modify: Config Name to `Updated Fleet API`
3. Click: `[Save]`

**Expected Results:**
- ✅ Configuration updated
- ✅ "Updated at" timestamp refreshed
- ✅ Audit log shows "update" action

---

### Test 1.5: Delete API Configuration
**Steps:**
1. Click: `[Delete]` button
2. Confirm deletion in popup

**Expected Results:**
- ✅ Confirmation dialog appears
- ✅ Entry removed from table
- ✅ Audit log shows "delete" action

**Database Verification:**
```sql
SELECT * FROM api_config WHERE id = [deleted_id];
-- Should return 0 rows
```

---

## 🔹 Test Suite 2: Git Workflow

### Test 2.1: Add Git Repository
**Steps:**
1. Navigate to: Integration Settings → Git Workflow
2. Click: `[+ Add Repository]`
3. Fill in form:
   - Repo Name: `Petron System Main`
   - Repo URL: `https://github.com/organization/petron-system.git`
   - Current Branch: `main`
   - Merge Rules: `Require PR approval`
4. Click: `[Save]`

**Expected Results:**
- ✅ Repository added to table
- ✅ Current branch displayed as badge
- ✅ Last Push/Pull show "Never"

**Database Verification:**
```sql
SELECT * FROM git_repos ORDER BY id DESC LIMIT 1;
```

---

### Test 2.2: Simulate Git Push
**Steps:**
1. Click: `[↑ Push]` button on repository
2. Confirm action

**Expected Results:**
- ✅ Alert: "Git Push initiated"
- ✅ "Last Push" timestamp updated
- ✅ Audit log entry created

**Database Verification:**
```sql
SELECT * FROM git_repos WHERE id = [repo_id];
-- Check: last_push_at
```

---

### Test 2.3: Simulate Git Pull
**Steps:**
1. Click: `[↓ Pull]` button on repository
2. Confirm action

**Expected Results:**
- ✅ Alert: "Git Pull initiated"
- ✅ "Last Pull" timestamp updated

---

### Test 2.4: View Commit Log
**Steps:**
1. Scroll to "Recent Commits" section
2. Manually insert test commit data:

```sql
INSERT INTO git_commits (repo_id, commit_hash, author, commit_message, branch_name)
VALUES (1, 'a3f5d8b2c1e4f7a9', 'Developer1', 'Fixed login bug', 'main');
```

3. Refresh page

**Expected Results:**
- ✅ Commit appears in table
- ✅ Hash displayed (8 chars)
- ✅ Author, message, branch shown
- ✅ Timestamp formatted correctly

---

### Test 2.5: Trigger Deployment
**Steps:**
1. Scroll to "Deployment Pipeline" section
2. Click: `[🚀 Trigger Deployment]`
3. Fill deployment form (if modal opens)
4. Confirm deployment

**Expected Results:**
- ✅ Deployment entry created
- ✅ Status shows "PENDING" or "SUCCESS"
- ✅ Deployed by shows current user
- ✅ Timestamp recorded

**Database Verification:**
```sql
SELECT * FROM deployment_history ORDER BY id DESC LIMIT 1;
```

---

## 🔹 Test Suite 3: External System Sync

### Test 3.1: Create Sync Job
**Steps:**
1. Navigate to: Integration Settings → External System Sync
2. Click: `[+ Add Sync Job]`
3. Fill in form:
   - Job Name: `Daily Inventory Sync`
   - Sync Frequency: Select `Daily`
   - External Feed URL: `https://external-system.com/api/inventory`
   - Conflict Resolution: Select `Merge`
4. Click: `[Save]`

**Expected Results:**
- ✅ Sync job added to table
- ✅ Status shows "PENDING"
- ✅ Last Synced shows "Never"

**Database Verification:**
```sql
SELECT * FROM sync_jobs ORDER BY id DESC LIMIT 1;
```

---

### Test 3.2: Execute Sync Now
**Steps:**
1. Click: `[Sync Now]` button on sync job
2. Confirm action

**Expected Results:**
- ✅ Alert: "Sync initiated"
- ✅ "Last Synced" timestamp updated
- ✅ New entry in Sync Logs section

**Database Verification:**
```sql
SELECT * FROM sync_logs ORDER BY id DESC LIMIT 1;
-- Check: sync_job_id, sync_status, synced_at
```

---

### Test 3.3: View Sync Logs
**Steps:**
1. Scroll to "Sync Logs" section
2. Manually insert test log:

```sql
INSERT INTO sync_logs (sync_job_id, sync_status, records_synced, error_message)
VALUES (1, 'success', 1234, NULL);
```

3. Refresh page

**Expected Results:**
- ✅ Log entry displays
- ✅ Status badge shows "SUCCESS" (green)
- ✅ Records synced: 1,234
- ✅ Error message: "-" (if no error)

---

### Test 3.4: Edit Sync Job
**Steps:**
1. Click: `[Edit]` on sync job
2. Change Frequency to `Weekly`
3. Save changes

**Expected Results:**
- ✅ Frequency updated in table
- ✅ Updated timestamp refreshed

---

### Test 3.5: Delete Sync Job
**Steps:**
1. Click: `[Delete]` on sync job
2. Confirm deletion

**Expected Results:**
- ✅ Job removed from table
- ✅ Related sync logs cascade deleted (or remain based on FK settings)

---

## 🔹 Test Suite 4: Navigation & UI

### Test 4.1: Sidebar Navigation
**Steps:**
1. Click each menu item in sidebar:
   - POS Import Config
   - API Connections
   - Git Workflow
   - External System Sync

**Expected Results:**
- ✅ Each section loads correctly
- ✅ URL parameter changes (?section=...)
- ✅ Active menu item highlighted
- ✅ Content displays for each section

---

### Test 4.2: Statistics Cards
**Steps:**
1. View statistics at top of page
2. Add entries to different tables
3. Refresh page

**Expected Results:**
- ✅ Counts update correctly:
  - POS Parsers
  - API Configs (Fleet + ERP)
  - Git Repos
  - Sync Jobs
  - Audit Logs

---

### Test 4.3: Responsive Design
**Steps:**
1. Resize browser window
2. Test on mobile viewport (DevTools)
3. Check tablet viewport

**Expected Results:**
- ✅ Tables scroll horizontally if needed
- ✅ Cards stack on small screens
- ✅ Buttons remain clickable
- ✅ No layout breaks

---

## 🔹 Test Suite 5: Security & Access Control

### Test 5.1: SuperAdmin Access
**Steps:**
1. Login as SuperAdmin
2. Navigate to Integration Settings

**Expected Results:**
- ✅ All sections visible
- ✅ All action buttons enabled
- ✅ Can create/edit/delete entries

---

### Test 5.2: Developer Access
**Steps:**
1. Login as Developer role
2. Navigate to Integration Settings

**Expected Results:**
- ✅ All sections visible
- ✅ All action buttons enabled
- ✅ Full CRUD permissions

---

### Test 5.3: Admin/Manager Access (View Only)
**Steps:**
1. Login as Admin or Manager
2. Try to access Integration Settings

**Expected Results:**
- ✅ Can view data (if allowed)
- ❌ Cannot create/edit/delete
- ❌ Action buttons disabled or hidden

---

### Test 5.4: Staff Access (No Access)
**Steps:**
1. Login as Staff
2. Try to access Integration Settings

**Expected Results:**
- ❌ Menu item not visible in sidebar
- ❌ Direct URL access redirected
- ❌ Error message: "Unauthorized"

---

## 🔹 Test Suite 6: Data Integrity

### Test 6.1: Audit Trail
**Steps:**
1. Perform any CRUD operation
2. Check `integration_audit` table

**Expected Results:**
- ✅ Entry logged with:
  - User ID
  - Action type
  - Target type and ID
  - Details
  - IP address
  - Timestamp

**Database Verification:**
```sql
SELECT * FROM integration_audit ORDER BY created_at DESC LIMIT 10;
```

---

### Test 6.2: Foreign Key Constraints
**Steps:**
1. Create Git repo (get ID)
2. Add commits for that repo
3. Try to delete repo

**Expected Results:**
- ✅ Related commits cascade deleted (or prevent deletion based on FK)
- ✅ Data integrity maintained

---

### Test 6.3: JSON Validation
**Steps:**
1. Try to save API config with invalid JSON in auth_keys
2. Submit form

**Expected Results:**
- ❌ Error message: "Invalid JSON"
- ✅ Form not submitted

---

## 🔹 Test Suite 7: Error Handling

### Test 7.1: Empty Required Fields
**Steps:**
1. Open any "Add" modal
2. Leave required fields empty
3. Click Save

**Expected Results:**
- ❌ Error: "Field is required"
- ✅ Form not submitted

---

### Test 7.2: Invalid URL Format
**Steps:**
1. Enter invalid URL in Endpoint URL field: `not-a-valid-url`
2. Click Save

**Expected Results:**
- ❌ Error: "Valid URL required"
- ✅ Form not submitted

---

### Test 7.3: Database Connection Failure
**Steps:**
1. Simulate DB connection failure
2. Try to load page

**Expected Results:**
- ✅ Error message displayed
- ✅ Graceful degradation (no PHP errors)

---

## 🔹 Test Suite 8: Performance

### Test 8.1: Page Load Time
**Steps:**
1. Open browser DevTools (Network tab)
2. Load Integration Settings page
3. Measure load time

**Expected Results:**
- ✅ Page loads in < 2 seconds
- ✅ No JavaScript errors in console

---

### Test 8.2: Large Dataset Handling
**Steps:**
1. Insert 100+ entries into each table
2. Load page
3. Check table rendering

**Expected Results:**
- ✅ Tables render without lag
- ✅ Pagination or scroll implemented
- ✅ No browser freezing

---

## ✅ Final Validation Checklist

### Functionality:
- [ ] All CRUD operations work correctly
- [ ] Navigation between sections smooth
- [ ] Action buttons trigger correct operations
- [ ] Status badges display correctly
- [ ] Timestamps formatted properly

### Data:
- [ ] All tables created successfully
- [ ] Data persists after operations
- [ ] Foreign keys enforced correctly
- [ ] Audit logs capture all actions
- [ ] No orphaned records

### Security:
- [ ] Role-based access enforced
- [ ] CSRF protection active
- [ ] Sensitive data encrypted
- [ ] Unauthorized access blocked
- [ ] SQL injection prevented

### UI/UX:
- [ ] Responsive design works
- [ ] Icons display correctly
- [ ] Buttons styled properly
- [ ] Tables scrollable on small screens
- [ ] Error messages clear

### Performance:
- [ ] Page loads quickly
- [ ] No console errors
- [ ] Database queries optimized
- [ ] Large datasets handled well

---

## 🐛 Known Issues & Workarounds

### Issue 1: JavaScript Modals Not Implemented
**Status:** Placeholder alerts used
**Workaround:** Implement full modal forms in future update

### Issue 2: Real Git Integration Missing
**Status:** Simulated operations only
**Workaround:** Logs are saved, but no actual Git commands executed

### Issue 3: External API Calls Simulated
**Status:** Test connection may not work with real APIs
**Workaround:** Update API handler with actual HTTP library (cURL/Guzzle)

---

## 📝 Test Report Template

```
==============================================
INTEGRATION SETTINGS - TEST REPORT
==============================================

Tested By: _______________________________
Date: ____________________________________
Environment: _____________________________

Test Results:
✅ Passed: _____ / _____
❌ Failed: _____ / _____
⚠️ Warnings: _____ / _____

Critical Issues:
1. _____________________________________
2. _____________________________________

Minor Issues:
1. _____________________________________
2. _____________________________________

Recommendations:
1. _____________________________________
2. _____________________________________

Overall Status: [PASS / FAIL / CONDITIONAL PASS]

Tester Signature: _______________________
==============================================
```

---

## 🚀 Next Steps After Testing

1. **Fix Issues:** Address any bugs found during testing
2. **Real Integration:** Implement actual Git/API operations
3. **Modal Forms:** Replace alerts with proper modals
4. **Validation:** Add client-side and server-side validation
5. **Documentation:** Update user manual with screenshots
6. **Training:** Train developers on using the system

---

**Testing Guide Complete** ✅
**Last Updated:** June 14, 2026
**Version:** 1.0
