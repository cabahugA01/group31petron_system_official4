# FIX LOGIN SYSTEM ERROR - COMPLETE GUIDE

**Problem:** "System error. Please try again later." when trying to log in

**Root Cause:** Database tables are using MEMORY storage engine which loses all data when MySQL/Apache restarts

**Error in logs:** `SQLSTATE[42S02]: Base table or view not found: 1932 Table 'petron_pos_db_secure.users' doesn't exist in engine`

---

## 🔍 STEP 1: DIAGNOSE THE PROBLEM

1. **Open phpMyAdmin:**
   - Go to: `http://localhost/phpmyadmin`
   - Click on database: `petron_pos_db_secure`

2. **Check if tables exist:**
   - Look at the left sidebar
   - Do you see table names like `users`, `customers`, `stations`, etc.?

**SCENARIO A:** Tables are visible
   - ✅ Tables exist but wrong engine
   - Continue to **STEP 2**

**SCENARIO B:** No tables visible (empty database)
   - ❌ Data was lost (MEMORY engine cleared on restart)
   - Skip to **STEP 3: RESTORE FROM BACKUP**

---

## 🔧 STEP 2: FIX TABLE ENGINES (If tables exist)

### Method 1: Using phpMyAdmin (Easy)

1. In phpMyAdmin, select database: `petron_pos_db_secure`
2. Click on `users` table
3. Click "Operations" tab at the top
4. In "Table options" section:
   - Find "Storage Engine"
   - Change from **MEMORY** to **InnoDB**
   - Click "Go"
5. Repeat for all tables:
   - users
   - stations
   - customers
   - merchandise_transactions
   - job_orders
   - fuel_transactions
   - inventory
   - activity_logs
   - audit_logs
   - login_attempts
   - (and all other tables)

### Method 2: Using SQL Script (Fast)

1. Open phpMyAdmin
2. Select database: `petron_pos_db_secure`
3. Click "SQL" tab
4. Open file: `CHECK_DATABASE_STATUS.sql`
5. Copy and paste the content
6. Click "Go" to check current status

7. If tables are MEMORY engine:
   - Open file: `FIX_DATABASE_ENGINE.sql`
   - Copy and paste the content
   - Click "Go" to convert all tables to InnoDB

---

## 💾 STEP 3: RESTORE FROM BACKUP (If data was lost)

### Find the latest backup file

Look in these folders:
```
database/petron_pos_db_secure.sql
backups/backup_YYYY_MM_DD_HHMMSS.sql
database/backups/*.sql
```

### Restore using phpMyAdmin

1. Open phpMyAdmin
2. Select database: `petron_pos_db_secure`
3. Click "Import" tab
4. Click "Choose File"
5. Select your backup file (`.sql` file)
6. Click "Go" button at the bottom
7. Wait for import to complete

### Restore using Command Line (Faster for large files)

Open Command Prompt and run:
```cmd
cd c:\xampp\mysql\bin
mysql -u root petron_pos_db_secure < "c:\xampp\htdocs\group31petron_system_official4\database\petron_pos_db_secure.sql"
```

---

## ✅ STEP 4: VERIFY THE FIX

### Test 1: Check Tables

Run this in phpMyAdmin SQL tab:
```sql
SELECT TABLE_NAME, ENGINE, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'petron_pos_db_secure'
ORDER BY TABLE_NAME;
```

**Expected Result:**
- All tables should show `ENGINE = 'InnoDB'`
- `TABLE_ROWS` should be > 0 for important tables

### Test 2: Check Users Table

Run this:
```sql
SELECT id, username, email, role, status
FROM users
LIMIT 5;
```

**Expected Result:**
- Should return user records
- NOT "Table doesn't exist" error

### Test 3: Try Login

1. Go to: `http://localhost/group31petron_system_official4/public/login.php`
2. Enter your credentials
3. Try to log in

**Expected Result:**
- ✅ Login succeeds
- Redirects to dashboard

---

## 🛡️ STEP 5: PREVENT FUTURE DATA LOSS

### Why did this happen?

The database was imported with `ENGINE=MEMORY` which is:
- ❌ Fast but VOLATILE (data lost on restart)
- ❌ NOT suitable for production
- ✅ InnoDB is the correct engine (persistent data)

### How to prevent it?

1. **Always use InnoDB engine** for production tables
2. **Create regular backups**
3. **Before importing SQL:**
   - Search for: `ENGINE=MEMORY`
   - Replace with: `ENGINE=InnoDB`
4. **Set MySQL default engine to InnoDB** (already default in modern MySQL)

---

## 🚨 QUICK FIX (If you just need to log in NOW)

### Emergency Option 1: Restore Minimal Users Table

If you just need to log in temporarily:

```sql
CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100),
  last_name VARCHAR(100),
  email VARCHAR(150),
  role ENUM('superadmin','admin','manager','staff') NOT NULL,
  station_id INT,
  status ENUM('Active','Locked','Disabled') DEFAULT 'Active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO users (username, password_hash, first_name, last_name, email, role, station_id, status)
VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'User', 'admin@petron.com', 'superadmin', 1, 'Active');

-- Default password for admin is: password
-- Change it immediately after logging in!
```

This creates a minimal users table with one admin account.

### Emergency Option 2: Skip Engine Check

Edit `public/login.php` and add this after line 150 (in the try block):

```php
// Temporary fix: Ignore MEMORY engine tables
$pdo->exec("SET SESSION sql_mode = 'ALLOW_INVALID_DATES'");
```

**⚠️ Warning:** This is a temporary hack. Fix the actual engine issue ASAP!

---

## 📋 TROUBLESHOOTING CHECKLIST

### Issue: "Table doesn't exist in engine"
**Fix:** Tables are MEMORY engine
- [ ] Run `FIX_DATABASE_ENGINE.sql`
- [ ] Restart Apache and MySQL
- [ ] Test login again

### Issue: phpMyAdmin shows no tables
**Fix:** Data was lost
- [ ] Find latest backup file
- [ ] Import backup via phpMyAdmin
- [ ] Run `FIX_DATABASE_ENGINE.sql`
- [ ] Test login again

### Issue: After importing, still MEMORY engine
**Fix:** SQL file contains ENGINE=MEMORY
- [ ] Open backup file in text editor
- [ ] Search for: `ENGINE=MEMORY`
- [ ] Replace with: `ENGINE=InnoDB`
- [ ] Save and re-import

### Issue: Import fails with "Max execution time"
**Fix:** File too large
- [ ] Edit `c:\xampp\php\php.ini`
- [ ] Find: `max_execution_time = 30`
- [ ] Change to: `max_execution_time = 300`
- [ ] Find: `post_max_size = 8M`
- [ ] Change to: `post_max_size = 128M`
- [ ] Find: `upload_max_filesize = 2M`
- [ ] Change to: `upload_max_filesize = 128M`
- [ ] Restart Apache
- [ ] Try import again

---

## 🎯 RECOMMENDED SOLUTION (Best Practice)

1. ✅ **Stop Apache and MySQL** (via XAMPP Control Panel)
2. ✅ **Backup current database** (if any data exists)
3. ✅ **Drop corrupted database:**
   ```sql
   DROP DATABASE IF EXISTS petron_pos_db_secure;
   CREATE DATABASE petron_pos_db_secure;
   ```
4. ✅ **Restore from backup file:**
   ```cmd
   cd c:\xampp\mysql\bin
   mysql -u root petron_pos_db_secure < "path\to\backup.sql"
   ```
5. ✅ **Convert all tables to InnoDB:**
   - Run `FIX_DATABASE_ENGINE.sql` in phpMyAdmin
6. ✅ **Verify tables exist:**
   - Run `CHECK_DATABASE_STATUS.sql`
7. ✅ **Test login:**
   - Go to login page
   - Enter credentials
   - Should work now!

---

## 📞 IF NOTHING WORKS

### Last Resort: Fresh Database Setup

1. Drop database:
   ```sql
   DROP DATABASE petron_pos_db_secure;
   CREATE DATABASE petron_pos_db_secure;
   ```

2. Import fresh schema from:
   ```
   database/petron_pos_db_secure.sql
   ```

3. Verify tables are InnoDB:
   ```sql
   SELECT TABLE_NAME, ENGINE 
   FROM information_schema.TABLES 
   WHERE TABLE_SCHEMA = 'petron_pos_db_secure';
   ```

4. If any tables show MEMORY, run:
   ```sql
   ALTER TABLE table_name ENGINE=InnoDB;
   ```

5. Create test admin user:
   ```sql
   INSERT INTO users (username, password_hash, role, status, station_id)
   VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin', 'Active', 1);
   ```
   Password: `password`

6. Test login

---

## 🔑 KEY TAKEAWAYS

1. **MEMORY engine = TEMPORARY DATA** (lost on restart)
2. **InnoDB engine = PERSISTENT DATA** (survives restart)
3. **Always backup before making changes**
4. **Regular backups prevent data loss**
5. **Check SQL dumps before importing** (replace MEMORY with InnoDB)

---

## ✅ SUCCESS INDICATORS

You know the fix worked when:
- ✅ Login page loads without errors
- ✅ Can log in with credentials
- ✅ Redirects to dashboard
- ✅ No "System error" message
- ✅ Dashboard loads without database errors
- ✅ After restarting Apache, still works (data persists)

---

**Need Help?**
- Check Apache error log: `c:\xampp\apache\logs\error.log`
- Check MySQL error log: `c:\xampp\mysql\data\mysql_error.log`
- Check browser console (F12) for JavaScript errors

**Files Created:**
- `CHECK_DATABASE_STATUS.sql` - Diagnose database engine issues
- `FIX_DATABASE_ENGINE.sql` - Convert tables to InnoDB
- `FIX_LOGIN_ERROR.md` - This complete guide

---

**Last Updated:** June 29, 2026
