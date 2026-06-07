# 🚀 DEPLOY NEW LOGIN SYSTEM

## Quick Deployment Guide (5 Minutes)

---

## 📋 WHAT'S NEW

**New Login Requirements:**
1. ✅ Station ID (required)
2. ✅ Email OR Username (auto-detect)
3. ✅ Password
4. ✅ Math CAPTCHA

**Removed:**
- ❌ Phone number login
- ❌ 2FA OTP system

**Database:**
- 11 fields (NO phone_number)
- Field renamed: `id` → `user_id`
- Field renamed: `password` → `password_hash`

---

## 🎯 STEP 1: UPDATE DATABASE (2 minutes)

### Run this URL:
```
http://localhost/group31petron_system_official4/database/update_users_final.php
```

### What it does:
1. ✅ Renames `id` → `user_id`
2. ✅ Renames `password` → `password_hash`
3. ✅ Removes `phone_number` field
4. ✅ Removes `phone` field (if exists)
5. ✅ Removes all extra fields
6. ✅ Ensures exactly 11 fields

### Expected Result:
```
✅ PERFECT! Users table has exactly 11 fields (NO phone)!

Final Fields:
✓ user_id
✓ first_name
✓ last_name
✓ station_id
✓ email
✓ username
✓ password_hash
✓ role
✓ status
✓ created_at
✓ updated_at
```

---

## 🎯 STEP 2: REPLACE LOGIN PAGE (1 minute)

### Option A: Via File Manager
```
1. Delete:  public/login.php
2. Rename:  public/login_new.php → public/login.php
```

### Option B: Via Command Line
```bash
cd c:\xampp\htdocs\group31petron_system_official4\public
del login.php
ren login_new.php login.php
```

### Option C: Via PHP Script
Create `deploy_login.php`:
```php
<?php
// Backup old login
copy('public/login.php', 'public/login_old_backup.php');

// Replace with new login
unlink('public/login.php');
rename('public/login_new.php', 'public/login.php');

echo "✅ Login page updated!";
?>
```

---

## 🎯 STEP 3: TEST LOGIN (2 minutes)

### Test URL:
```
http://localhost/group31petron_system_official4/public/login.php
```

### Test Credentials:

**Test 1: Email Login**
```
Station ID: 1
Email/Username: admin@petron.com
Password: your_password
CAPTCHA: (solve the math)
```

**Test 2: Username Login**
```
Station ID: 1
Email/Username: admin
Password: your_password
CAPTCHA: (solve the math)
```

**Test 3: Wrong Station ID (should fail)**
```
Station ID: 999
Email/Username: admin@petron.com
Password: your_password
CAPTCHA: (solve the math)

Expected: ❌ "Invalid Station ID or account not found"
```

**Test 4: Wrong CAPTCHA (should fail)**
```
Station ID: 1
Email/Username: admin@petron.com
Password: your_password
CAPTCHA: 0

Expected: ❌ "Incorrect CAPTCHA answer"
```

**Test 5: Wrong Password (should fail)**
```
Station ID: 1
Email/Username: admin@petron.com
Password: wrongpassword
CAPTCHA: (solve the math)

Expected: ❌ "Invalid password"
```

---

## ✅ VERIFICATION CHECKLIST

### After Deployment:

#### Database:
- [ ] Open phpMyAdmin
- [ ] Select `petron_pos_db_secure` database
- [ ] Click `users` table
- [ ] Check structure has **exactly 11 fields**
- [ ] Verify NO `phone_number` or `phone` field
- [ ] Verify `user_id` field exists
- [ ] Verify `password_hash` field exists

#### Login Page:
- [ ] Open login page in browser
- [ ] See "Station ID" field (first)
- [ ] See "Email or Username" field (second)
- [ ] See "Password" field (third)
- [ ] See Math CAPTCHA (e.g., "3 + 5 = ?")
- [ ] NO phone-related fields visible

#### Functionality:
- [ ] Login with email works
- [ ] Login with username works
- [ ] Wrong station ID fails
- [ ] Wrong CAPTCHA fails
- [ ] Wrong password fails
- [ ] Correct credentials redirect to dashboard
- [ ] Audit logs record login attempts

---

## 🔧 TROUBLESHOOTING

### Problem: "Column 'phone_number' not found"
**Fix:** Run database update script again

### Problem: "Column 'user_id' not found"
**Fix:** Run database update script to rename `id` → `user_id`

### Problem: Login page not found
**Fix:** Make sure you renamed `login_new.php` to `login.php`

### Problem: Login success but no redirect
**Fix:** Check that dashboard files exist for each role

### Problem: All users have NULL station_id
**Fix:** Run this SQL:
```sql
UPDATE users SET station_id = 1 WHERE station_id IS NULL;
```

---

## 📊 QUICK STATUS CHECK

### Check Database Structure:
```sql
-- Via phpMyAdmin or MySQL command line
DESCRIBE users;

-- Should show exactly 11 fields:
-- user_id, first_name, last_name, station_id, email, 
-- username, password_hash, role, status, created_at, updated_at
```

### Check Users:
```sql
-- Make sure all users have station_id
SELECT user_id, username, email, station_id, status 
FROM users;

-- If any NULL station_id, update them:
UPDATE users SET station_id = 1 WHERE station_id IS NULL;
```

### Check Audit Logs:
```sql
-- View recent login attempts
SELECT * FROM audit_logs 
WHERE action_type LIKE '%Login%' 
ORDER BY created_at DESC 
LIMIT 10;
```

---

## 🎯 ROLLBACK (If Needed)

If something goes wrong, rollback:

### Restore Database:
```bash
# Use your backup SQL file
mysql -u root petron_pos_db_secure < backup_before_login_update.sql
```

### Restore Login Page:
```bash
cd public
del login.php
ren login_old_backup.php login.php
```

---

## 📞 SUMMARY

**Deployment Steps:**
1. ✅ Run: `database/update_users_final.php`
2. ✅ Replace: `login.php` with `login_new.php`
3. ✅ Test: Login with Station ID + Email/Username

**Time:** 5 minutes total

**Changes:**
- Database: 11 fields (NO phone)
- Login: Station ID required
- Removed: Phone login, 2FA OTP

**Result:** Simple, secure login system! 🎉

---

**Dali ra! 5 minutes lang then ready na ang new login! 🚀**

**Step 1:** Run database update script  
**Step 2:** Replace login.php  
**Step 3:** Test!  

