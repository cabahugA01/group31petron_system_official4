# Admin User Management Module - Shift-Based

**Feature Type:** Admin Module Enhancement  
**Priority:** HIGH  
**Status:** Planning  
**Date:** June 4, 2026

---

## 📋 Overview

The Admin User Management module is shift-based. Managers and Staff accounts are created, edited, deactivated, and viewed by Admin. Each account is auto-bound to its station shift, removing the need for manual role permission assignment. Activity Logs track login history and account changes, with export options for compliance. Email notifications deliver credentials upon account creation. Summary Cards provide quick snapshots of active accounts and logs.

---

## 🎯 Core Requirements

### 1. Manager Accounts Management

#### 1.1 Add Manager
- **Rule:** Admin can create **1 Manager per station ONLY**
- **Auto-Binding:** Manager account auto-links to station shift schedule
- **Email Notification:** Auto-send credentials upon creation
- **Validation:**
  - Check if station already has an active Manager
  - Prevent duplicate Manager creation
  - Require valid email address

**Fields Required:**
- First Name*
- Last Name*
- Email Address* (becomes username)
- Phone Number
- Station* (auto-assigned from Admin's station)
- Shift Binding* (auto-assigned based on station schedule)

**Business Rules:**
- One Manager per station limit enforced
- Manager cannot be edited (profile/role locked)
- Manager can only be deactivated/reactivated
- Email sent with:
  - Username = Email address
  - Temporary password = auto-generated
  - Reminder = "Change password upon first login"

---

#### 1.2 Deactivate Manager
- **Action:** Disable Manager account (inactive/resigned)
- **Effect:**
  - Status changes to "Inactive"
  - Cannot login
  - Activity logs preserved
  - Shift binding maintained (for reactivation)
- **Audit:** Log who deactivated and when

---

#### 1.3 View Manager Profile
- **Read-Only Display:**
  - Full Name
  - Email/Username
  - Phone Number
  - Station Assignment
  - Shift Binding
  - Status (Active/Inactive)
  - Created Date
  - Last Login
  - Created By
  - Deactivated Date (if applicable)
  - Deactivated By (if applicable)

**No Edit Allowed:**
- Manager profile is view-only
- Cannot change name, email, role, or station
- Only deactivate/reactivate action available

---

#### 1.4 Shift Binding
- **Auto-Link Process:**
  1. Manager created → system reads station's shift schedule
  2. Auto-assign Manager to station's active shifts
  3. Manager inherits shift permissions automatically
  4. No manual configuration needed

**Shift Assignment:**
- Linked to `shifts` table
- Based on station_id
- Auto-updated if station shifts change
- Tracks shift assignment in database

---

### 2. Staff Accounts Management

#### 2.1 Add Staff
- **Rule:** **Unlimited staff accounts** per station
- **Auto-Binding:** Staff account auto-assigned to active shift on login
- **Email Notification:** Auto-send credentials upon creation
- **Validation:**
  - Require valid email address
  - Check email uniqueness across system

**Fields Required:**
- First Name*
- Last Name*
- Email Address* (becomes username)
- Phone Number
- Station* (auto-assigned from Admin's station)
- Shift Preference (optional - system auto-assigns on login)

**Business Rules:**
- No limit on Staff count per station
- Staff can be edited (name, email, phone)
- Staff can be deactivated/reactivated
- Email sent with same format as Manager

---

#### 2.2 Edit Staff
- **Editable Fields:**
  - First Name
  - Last Name
  - Phone Number
  - Email Address (with uniqueness check)

**Non-Editable:**
- Role (locked as Staff)
- Station (locked to Admin's station)
- Shift Binding (auto-managed by system)

**Validation:**
- Email uniqueness across all users
- Phone format validation
- Prevent editing own account

**Audit:**
- Log all field changes (old value → new value)
- Track who made the change
- Record timestamp

---

#### 2.3 Deactivate Staff
- Same as Manager deactivation
- Activity logs preserved
- Shift binding maintained

---

#### 2.4 View Staff Profile
- **Read-Only Display + Activity Logs:**
  - Full Name
  - Email/Username
  - Phone Number
  - Station Assignment
  - Shift Binding
  - Status (Active/Inactive)
  - Created Date
  - Last Login
  - Created By

**Activity Logs (Last 30 Days):**
- Login history (date, time, shift)
- Actions performed (transaction count, stock-in count, etc.)
- Account changes (edit history)

---

#### 2.5 Shift Binding
- **Auto-Assignment on Login:**
  1. Staff logs in
  2. System checks current time
  3. Matches to active shift period
  4. Auto-assigns Staff to that shift
  5. All transactions tagged with shift_id

**Shift Tracking:**
- Linked to `shift_periods` table
- Based on current time vs shift start/end
- Auto-updated on each login
- Tracks shift in `system_activity_logs`

---

### 3. Activity Logs

#### 3.1 Login History
**Track per Shift:**
- User ID + Name
- Login Date/Time
- Shift Assignment (Morning/Afternoon/Night)
- Station ID
- IP Address
- Login Status (Success/Failed)
- Session Duration

**Query Example:**
```sql
SELECT u.name, sal.created_at, sp.shift_name, sal.ip_address, sal.status
FROM system_activity_logs sal
JOIN users u ON sal.user_id = u.id
LEFT JOIN shift_periods sp ON TIME(sal.created_at) BETWEEN sp.start_time AND sp.end_time
WHERE sal.activity_type = 'login'
  AND sal.station_id = ?
ORDER BY sal.created_at DESC
```

---

#### 3.2 Account Changes
**Track:**
- User ID (who was changed)
- Changed By (Admin ID)
- Change Type (create, update, deactivate, reactivate)
- Field Changed (name, email, phone, status)
- Old Value → New Value
- Timestamp
- Station ID

**Log Table:** `system_activity_logs`

**Change Types:**
- User Created
- User Updated
- User Deactivated
- User Reactivated
- Password Reset
- Email Changed

---

#### 3.3 Export Logs
**Export Formats:**
- Excel (.xlsx)
- CSV (.csv)
- PDF (formatted report)

**Export Options:**
- Date Range Filter
- User Filter (Manager/Staff)
- Activity Type Filter (Login/Create/Update/Deactivate)
- Station Filter (for Superadmin)

**Export Contents:**
- Login History Report
- Account Changes Report
- Comprehensive Activity Report

**File Naming:**
```
Activity_Logs_[StationName]_[DateRange]_[Timestamp].xlsx
Login_History_[StationName]_[DateRange]_[Timestamp].csv
Account_Changes_[StationName]_[DateRange]_[Timestamp].pdf
```

---

### 4. Email Notification

#### 4.1 Account Creation Email

**Trigger:** When Admin creates Manager or Staff account

**Email Template:**
```
Subject: Welcome to Petron Station Management System

Dear [First Name] [Last Name],

Your account has been successfully created for:
Station: [Station Name]
Role: [Manager/Staff]

Login Credentials:
Username: [Email Address]
Temporary Password: [Auto-Generated Password]

Login URL: [System URL]

IMPORTANT SECURITY NOTICE:
🔐 You MUST change your password upon first login.
🔐 Do not share your credentials with anyone.
🔐 Your password must contain at least 8 characters, including uppercase, lowercase, number, and special character.

Your Shift Assignment:
- You are auto-assigned to shifts based on your station schedule.
- Login during your assigned shift time for access.

If you have any questions or did not expect this email, please contact your system administrator immediately.

Best regards,
Petron Station Management System
```

---

#### 4.2 Email Configuration

**SMTP Settings:**
- Use existing `config/email_config.php`
- PHPMailer library
- Secure SMTP connection

**Email Fields:**
- From: `noreply@petronsystem.com`
- To: User's email address
- CC: Admin's email (optional)
- Subject: Dynamic based on action
- Body: HTML formatted

**Error Handling:**
- If email fails → show warning to Admin
- Log email delivery status
- Provide manual credential sharing option

---

### 5. Summary Cards

#### 5.1 Manager Accounts Summary

**Metrics:**
- Total Managers: Count of all Manager accounts
- Active Managers: Status = 'active'
- Deactivated Managers: Status = 'inactive'

**Card Design:**
```
┌─────────────────────────────────────┐
│ 👤 Manager Accounts Summary         │
├─────────────────────────────────────┤
│ Total Managers:       1             │
│ Active:               1 (100%)      │
│ Deactivated:          0 (0%)        │
│                                     │
│ 📊 1/1 Stations have Manager        │
└─────────────────────────────────────┘
```

**Color Coding:**
- Green: Active count
- Red: Deactivated count
- Blue: Total count

---

#### 5.2 Staff Accounts Summary

**Metrics:**
- Total Staff: Count of all Staff accounts
- Active Staff: Status = 'active'
- Deactivated Staff: Status = 'inactive'
- Staff per Shift: Breakdown by shift assignment

**Card Design:**
```
┌─────────────────────────────────────┐
│ 👥 Staff Accounts Summary           │
├─────────────────────────────────────┤
│ Total Staff:          12            │
│ Active:               10 (83%)      │
│ Deactivated:          2 (17%)       │
│                                     │
│ By Shift:                           │
│ Morning:    5 staff                 │
│ Afternoon:  5 staff                 │
└─────────────────────────────────────┘
```

---

#### 5.3 Activity Logs Summary

**Metrics:**
- Total Logins Today: Count of login events today
- Accounts Changed Today: Count of create/update/deactivate today
- Exports Generated Today: Count of log exports today

**Card Design:**
```
┌─────────────────────────────────────┐
│ 📊 Activity Logs Summary (Today)    │
├─────────────────────────────────────┤
│ Total Logins:           24          │
│ Accounts Changed:       3           │
│ Exports Generated:      1           │
│                                     │
│ Latest Activity:                    │
│ 09:45 AM - Staff Login              │
│ 09:30 AM - Account Created          │
└─────────────────────────────────────┘
```

---

## 🔐 Security & Permissions

### Access Control

**Admin Role:**
- Can create Manager (1 per station limit)
- Can create unlimited Staff
- Can view all Manager/Staff in their station
- Can deactivate/reactivate accounts
- Can reset passwords
- Can export activity logs
- Cannot edit Manager profiles
- Can edit Staff profiles

**Manager Role (For Reference):**
- Cannot access User Management
- Auto-assigned to station shift
- Can view own profile only

**Staff Role (For Reference):**
- Cannot access User Management
- Auto-assigned to shift on login
- Can view own profile only

---

### Validation Rules

**Email Address:**
- Must be valid email format
- Must be unique across system
- Becomes username automatically

**Password:**
- Auto-generated on creation: 12 characters
- Must contain: uppercase, lowercase, number, symbol (_ . - ! @ #)
- Forced change on first login
- Minimum 8 characters when user sets own password

**Station Assignment:**
- Admin's station auto-assigned to created accounts
- Cannot be changed after creation
- Validated against active stations list

**Shift Binding:**
- Auto-assigned based on shift_periods table
- Based on time of login/action
- Cannot be manually overridden

---

## 📊 Database Schema

### Tables Used

**1. users**
- Standard user table
- Columns: id, name, username, email, phone, role, station_id, status, must_change_password, created_at

**2. shifts**
- Shift definitions
- Columns: id, name, start_time, end_time, is_active

**3. shift_periods**
- Detailed shift periods
- Columns: id, shift_key, shift_name, start_time, end_time, is_active

**4. system_activity_logs**
- Comprehensive activity tracking
- Columns: id, activity_type, module, action, description, user_id, station_id, ip_address, created_at

**5. activity_logs**
- Legacy activity logging
- Columns: id, user_id, action, details, created_at

---

### New Columns Needed

**users table (if not exist):**
```sql
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS current_shift_id INT NULL,
ADD COLUMN IF NOT EXISTS last_shift_login DATETIME NULL,
ADD COLUMN IF NOT EXISTS deactivated_at DATETIME NULL,
ADD COLUMN IF NOT EXISTS deactivated_by INT NULL;
```

**system_activity_logs (ensure columns):**
```sql
ALTER TABLE system_activity_logs
ADD COLUMN IF NOT EXISTS shift_id INT NULL,
ADD COLUMN IF NOT EXISTS session_duration INT NULL COMMENT 'Duration in seconds';
```

---

## 🎨 UI/UX Requirements

### Summary Cards Section (Top)
- 3 cards in a row (responsive grid)
- Gradient icons
- Real-time counts
- Clickable for detailed view

### Tabs
1. **Manager Accounts** - List of all Managers
2. **Staff Accounts** - List of all Staff
3. **Activity Logs** - Login history & account changes

### Manager Accounts Tab
**List View:**
- Table with columns: Name, Email, Phone, Status, Shift, Actions
- Actions: View Profile, Deactivate/Reactivate
- No Edit button
- Badge for Active/Inactive status
- Shift badge (Morning/Afternoon)

**View Modal:**
- Read-only profile
- Show all details
- Show last login info
- Show created by info
- No form fields

### Staff Accounts Tab
**List View:**
- Table with columns: Name, Email, Phone, Status, Shift, Actions
- Actions: View Profile, Edit, Deactivate/Reactivate
- Badge for Active/Inactive status
- Shift badge (Morning/Afternoon)

**Edit Modal:**
- Form with editable fields only
- Email uniqueness validation
- Save button
- Cancel button

**View Modal:**
- Read-only profile
- Show activity logs (last 30 days)
- Show login history
- Show account changes history

### Activity Logs Tab
**Filters:**
- Date Range (From - To)
- Activity Type (All, Login, Account Change, Export)
- User Role (All, Manager, Staff)
- User Name (dropdown)

**Export Buttons:**
- Export to Excel
- Export to CSV
- Export to PDF

**Table:**
- Columns: Date/Time, User, Activity, Details, IP Address
- Pagination
- Sort by date (newest first)

---

## 🧪 Testing Requirements

### Test Cases

**1. Manager Creation**
- ✓ Create first Manager for station → Success
- ✓ Try create second Manager → Error: "Station already has Manager"
- ✓ Email sent successfully
- ✓ Manager cannot login until password changed

**2. Staff Creation**
- ✓ Create unlimited Staff → Success
- ✓ Email sent for each
- ✓ Staff auto-assigned to shift on login

**3. Shift Binding**
- ✓ Login during Morning shift (6 AM - 2 PM) → Assigned to Morning
- ✓ Login during Afternoon shift (2 PM - 12 AM) → Assigned to Afternoon
- ✓ Shift recorded in activity logs

**4. Manager Profile**
- ✓ View Manager profile → Read-only display
- ✓ No Edit button shown
- ✓ Only Deactivate action available

**5. Staff Profile**
- ✓ View Staff profile → Shows activity logs
- ✓ Edit Staff → Name/Email/Phone editable
- ✓ Deactivate Staff → Status changes to Inactive

**6. Activity Logs**
- ✓ Login recorded with shift info
- ✓ Account creation logged
- ✓ Account changes logged with old/new values
- ✓ Export to Excel works
- ✓ Export to CSV works
- ✓ Export to PDF works

---

## 📝 Success Criteria

✅ **Functional:**
1. Admin can create 1 Manager per station only
2. Admin can create unlimited Staff
3. Shift auto-binding works on login
4. Email notifications sent successfully
5. Manager profiles are view-only (no edit)
6. Staff profiles are editable
7. Activity logs track everything
8. Export functionality works (Excel, CSV, PDF)
9. Summary cards show accurate counts

✅ **Security:**
1. Only Admin can access User Management
2. Admin can only manage users in their station
3. Passwords are hashed securely
4. Email uniqueness enforced
5. Audit trail for all actions

✅ **UX:**
1. Clear separation between Manager/Staff tabs
2. Summary cards provide quick insights
3. Modals for View/Edit actions
4. Responsive design
5. Loading states and error messages
6. Export buttons easily accessible

---

## 🚀 Implementation Priority

**Phase 1: Core Functionality (Week 1)**
1. Summary Cards component
2. Manager Accounts tab + Add Manager
3. Staff Accounts tab + Add/Edit Staff
4. Shift binding logic
5. Email notification system

**Phase 2: Activity Logs (Week 2)**
1. Login history tracking
2. Account changes tracking
3. Activity logs display tab
4. Filter functionality

**Phase 3: Export & Polish (Week 3)**
1. Export to Excel implementation
2. Export to CSV implementation
3. Export to PDF implementation
4. UI polish and responsiveness
5. Testing and bug fixes

---

**Status:** Requirements Complete ✅  
**Next Step:** Design Documentation  
**Estimated Effort:** 3 weeks development + 1 week testing

