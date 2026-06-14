# Franchise Branch Logic - Admin Management 🏢

## Overview
The Admin Management system implements a **franchise branch model** where each Petron station operates as an independent franchise branch with dedicated administrative oversight.

---

## 🏗️ Core Concept

### Station = Franchise Branch
Every station in the system represents a **franchise branch**:
- Each branch has its own location, contact details, and regional classification
- Each branch operates independently under centralized system oversight
- Developer/SuperAdmin manages all branches from a single nationwide dashboard

---

## 🔑 Key Business Rules

### 1. One Admin Per Branch Rule
**Strict Enforcement:** Each franchise branch can have **only ONE Admin account**

**Why This Rule?**
- Clear accountability per branch
- Prevents conflicting management decisions
- Simplified audit trail
- Single point of responsibility

**Implementation:**
```php
// Backend validation in create_admin action
$admChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND station_id=?");
$admChk->execute([$station_id]);
if ((int)$admChk->fetchColumn() > 0) {
    echo json_encode(['ok'=>false,'error'=>'This station already has an Admin.']);
    exit;
}
```

**User Experience:**
- If branch already has an admin → Error message: "This station already has an Admin."
- Solution: Deactivate existing admin first, then create new one (for transfers)

---

## 🏢 Branch Management Workflow

### Step 1: Developer Adds Branch
**Action:** Click "Add Station" button

**Required Information:**
- **Station Name** (required) - Franchise branch name
  - Example: "Petron Quezon City"
- **Location/Address** (required) - Complete branch address
  - Example: "123 Main Street, Quezon City, Metro Manila"
- **Region** (optional) - Geographic classification
  - Example: "NCR", "Region IV-A", "Visayas"
- **Contact Number** (optional) - Branch contact
  - Example: "(02) 1234-5678"

**Database Entry:**
```sql
INSERT INTO stations (name, location, region, contact, status, created_at) 
VALUES ('Petron Quezon City', '123 Main St...', 'NCR', '(02) 1234-5678', 'Active', NOW())
```

**Result:**
- Branch registered in system
- Available immediately for Admin assignment
- Appears in all station dropdowns

---

### Step 2: Create Admin Account for Branch
**Action:** Click "Create Admin Account" button

**Required Information:**
- **First Name** (required)
- **Last Name** (required)
- **Email Address** (required) - Login credential
- **Station Assignment** (required) - Select branch from searchable dropdown

**Auto-Generated:**
- **Password** - 10-character secure password
- Sent via email to admin
- Admin must change on first login

**Database Entry:**
```sql
INSERT INTO users (
    username, first_name, last_name, email, 
    password_hash, role, station_id, status
) VALUES (
    'admin@email.com', 'Juan', 'Dela Cruz', 'admin@email.com',
    '$2y$10$...hashed...', 'admin', 123, 'active'
)
```

**Email Sent:**
```
Subject: Your Petron Station Admin Account Credentials

Dear Juan Dela Cruz,

Your Admin account has been created for Petron Station Management System.

Station : Petron Quezon City
Email   : admin@email.com
Password: [AUTO-GENERATED]

IMPORTANT: You are required to change your password upon first login.
```

---

### Step 3: Branch Operations
Once admin is assigned:
- Admin logs in using email and temporary password
- Admin manages branch-specific operations:
  - Staff management
  - Inventory control
  - Transaction validation
  - Daily operations reporting
  - Customer management

**Admin Scope:**
- Limited to assigned branch only
- Cannot access other branches' data
- Full operational control within their branch

---

## 👥 Developer Oversight

### Nationwide Control Panel
**Location:** Admin Management page

**View:**
- All branches nationwide in single table
- Columns: # | First Name | Last Name | Email | Station | Status | Last Login | Actions

**Capabilities:**
1. **Create** - Add new Admin for any branch
2. **Edit** - Update admin details, reassign to different branch
3. **Deactivate** - Disable admin login access
4. **Activate** - Restore admin login access
5. **Monitor** - View last login, status, branch assignment

---

## 🔄 Branch Transfer Process

### Scenario: Admin transfers from Branch A to Branch B

**Current System:**
1. Edit admin account
2. Change "Station Assignment" from Branch A to Branch B
3. Save changes
4. Admin now manages Branch B instead of Branch A

**Result:**
- Branch A temporarily has no admin
- Branch B now has active admin
- All audit logs preserved
- Seamless transfer

---

## 🚦 Branch Status Management

### Activate Branch Admin
**When to Use:**
- New admin starts at branch
- Returning admin after leave
- Reactivating after temporary closure

**Process:**
1. Find inactive admin in table
2. Click "Activate" button
3. Confirm action
4. Status changes to "Active" (green badge)
5. Admin can login immediately

### Deactivate Branch Admin
**When to Use:**
- Admin resigns/terminated
- Branch temporarily closed
- Admin on extended leave
- Security concern

**Process:**
1. Find active admin in table
2. Click "Deactivate" button
3. Confirm action
4. Status changes to "Inactive" (red badge)
5. Admin cannot login
6. Records preserved for compliance

**Important:** Deactivating does NOT delete records - all data preserved for audit trail

---

## 📊 Nationwide Dashboard

### Statistics Display

**Total Admins**
- Count: All admin accounts (active + inactive)
- Icon: Blue users icon

**Active**
- Count: Currently active admins
- Icon: Green check icon
- Indicates branches currently operational

**Inactive**
- Count: Deactivated admins
- Icon: Red slash icon
- Indicates branches without active admin

**Stations Covered**
- Count: Unique branches with assigned admins
- Icon: Amber building icon
- Shows franchise network coverage

---

## 🔍 Search & Filter for Branch Management

### Find Specific Branch Admin
**Search Box:** Type branch name, admin name, or email
**Status Filter:** Filter by Active/Inactive
**Station Filter:** Select specific branch

**Use Cases:**
1. **Regional Review:** Filter by region → See all branches in NCR
2. **Status Audit:** Filter "Inactive" → Find branches needing admin
3. **Quick Lookup:** Search "Quezon" → Find all Quezon City branches

---

## 🔒 Security & Compliance

### Access Control
**Developer/SuperAdmin Only:**
- Create branches
- Create admin accounts
- Edit admin details
- Activate/deactivate admins
- View nationwide data

**Admin:**
- Limited to assigned branch
- Cannot create other admins
- Cannot access other branches
- Cannot modify own status

### Audit Trail
Every action logged:
- Branch creation → "SuperAdmin created station 'Petron Quezon City' (ID 123)"
- Admin creation → "SuperAdmin created admin 'Juan Dela Cruz' for station 'Petron Quezon City'"
- Admin update → "SuperAdmin updated admin ID 456 — station: 'Petron Manila', status: active"
- Deactivation → "SuperAdmin deactivated admin 'Juan Dela Cruz' (ID 456)"
- Activation → "SuperAdmin activated admin 'Juan Dela Cruz' (ID 456)"

**Stored In:** `activity_logs` table
**Retention:** Permanent (never deleted)
**Purpose:** Compliance, security review, management oversight

---

## 📋 Branch Lifecycle

### 1. New Franchise Branch Opening
```
1. Developer adds branch via "Add Station"
   └─> Branch: "Petron Cebu" created
   
2. Developer creates admin via "Create Admin Account"
   └─> Admin: "Maria Santos" assigned to "Petron Cebu"
   └─> Credentials sent to maria.santos@petron.com
   
3. Admin logs in and starts operations
   └─> Branch is now operational
```

### 2. Branch Transfer
```
1. Admin "Juan Dela Cruz" manages "Petron Manila"
2. Company decides to transfer Juan to "Petron Makati"
3. Developer edits admin account
4. Changes station from "Petron Manila" to "Petron Makati"
5. Juan now manages "Petron Makati"
```

### 3. Branch Temporary Closure
```
1. Branch needs to close for renovation
2. Developer deactivates admin for "Petron Quezon City"
3. Admin cannot login during closure
4. After renovation complete
5. Developer activates admin again
6. Branch resumes operations
```

### 4. Admin Resignation
```
1. Admin "Kathrine Pepito" resigns from "Petron Davao"
2. Developer deactivates admin account
3. Branch has no active admin (temporarily)
4. Developer creates new admin "Pedro Garcia" for "Petron Davao"
5. New admin takes over operations
```

---

## ✅ Business Benefits

### Centralized Control
- Developer oversees all branches from one dashboard
- Quick response to branch needs
- Consistent policies across franchise network

### Clear Accountability
- One admin per branch = clear responsibility
- Easy to identify who manages what
- Simplified performance evaluation

### Scalability
- Add new branches easily
- Support hundreds of franchise locations
- Maintain control as network grows

### Security
- Restricted access per branch
- Audit trail for all actions
- Cannot bypass one-admin-per-branch rule

### Operational Efficiency
- Quick admin reassignment
- Fast branch setup
- Minimal downtime during transitions

---

## 🎯 Franchise Model Summary

| Concept | Implementation |
|---------|----------------|
| **Branch = Station** | Each database entry represents one franchise branch |
| **One Admin Rule** | Enforced at database level, cannot be bypassed |
| **Branch Registration** | Add Station modal creates new franchise location |
| **Admin Assignment** | Link admin to branch during account creation |
| **Status Control** | Developer activates/deactivates per branch needs |
| **Nationwide View** | Single dashboard shows all branches and admins |
| **Transfer Support** | Edit station assignment for admin transfers |
| **Audit Trail** | Every action logged for compliance |
| **Security** | Role-based access, admin limited to assigned branch |

---

## 📞 Support Scenarios

### "I need to open a new franchise branch"
1. Login as Developer
2. Click "Add Station"
3. Fill in branch details
4. Click "Create Admin Account"
5. Assign admin to new branch
6. ✅ Branch operational

### "I need to transfer an admin to a different branch"
1. Find admin in table
2. Click "Edit"
3. Change "Station Assignment"
4. ✅ Admin now manages new branch

### "I need to temporarily close a branch"
1. Find branch admin
2. Click "Deactivate"
3. ✅ Admin cannot login, branch inactive

### "I need to replace a branch admin"
1. Deactivate old admin
2. Create new admin for same branch
3. ✅ New admin takes over

---

**Last Updated:** June 13, 2026  
**Model:** Franchise Branch System  
**Status:** Fully Operational ✅
