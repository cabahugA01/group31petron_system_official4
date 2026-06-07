# How to Update Users Table Structure

## 🎯 Quick Start

### Step 1: Access the Update Script
Open your browser and go to:
```
http://localhost/group31petron_system_official4/database/update_users_table.php
```

### Step 2: Review Output
The script will show:
- Current structure
- Changes being made
- Final structure
- Success/Error messages

### Step 3: Verify
After update, check the table in phpMyAdmin:
```
http://localhost/phpmyadmin
→ Select 'petron_pos_db_secure' database
→ Click 'users' table
→ Click 'Structure' tab
```

---

## 📋 What Will Be Updated

### Fields Added (if missing):
- ✅ `status` - ENUM('Active', 'Locked', 'Disabled')
- ✅ `created_at` - Timestamp of creation
- ✅ `updated_at` - Timestamp of last update
- ✅ `station_id` - Foreign key to stations (if missing)
- ✅ `email` - Login identifier (if missing)

### Fields Renamed:
- ✅ `phone` → `phone_number`
- ✅ `password` → `password_hash`

### Fields Kept As-Is:
- ✅ `id` (NOT renamed to user_id - to avoid breaking code)
- ✅ `first_name`
- ✅ `last_name`
- ✅ `username`
- ✅ `role`

### Indexes Added:
- ✅ Index on `email`
- ✅ Index on `phone_number`
- ✅ Index on `status`
- ✅ Index on `station_id`

---

## ⚠️ Important Notes

1. **Backup Automatic**: Script uses transaction (auto-rollback on error)
2. **No Data Loss**: All existing data is preserved
3. **Zero Downtime**: Changes happen instantly
4. **Safe to Run**: Can run multiple times (idempotent)

---

## 🔍 Before Running

### Optional: Check Current Structure First
```
http://localhost/group31petron_system_official4/database/check_users_structure.php
```

This will show you what changes are needed without making any changes.

---

## ✅ After Update

### Verify Structure:
1. Go to phpMyAdmin
2. Select `petron_pos_db_secure` database
3. Click `users` table
4. Click `Structure` tab
5. Verify fields match:

**Expected Fields:**
```
id              INT(11) PRIMARY KEY AUTO_INCREMENT
first_name      VARCHAR(100) NOT NULL
last_name       VARCHAR(100) NOT NULL
station_id      INT(11) NULL
email           VARCHAR(255) NULL UNIQUE
username        VARCHAR(100) NOT NULL UNIQUE
phone_number    VARCHAR(20) NULL UNIQUE
password_hash   VARCHAR(255) NOT NULL
role            ENUM('SuperAdmin','Admin','Manager','Staff')
status          ENUM('Active','Locked','Disabled')
created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at      TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### Test Functionality:
- ✅ Login (email/phone/username)
- ✅ User management
- ✅ Password reset
- ✅ User creation

---

## 🚀 Ready to Update?

**Just open this URL in your browser:**
```
http://localhost/group31petron_system_official4/database/update_users_table.php
```

The script will handle everything automatically! 🎉
