# Users Table Migration Plan

## ⚠️ CRITICAL WARNING
This is a **MAJOR DATABASE MIGRATION** that will affect **100+ PHP files** across the system.

## Current Schema (OLD)
```sql
users (
  id INT PRIMARY KEY
  username VARCHAR
  email VARCHAR
  phone VARCHAR
  password VARCHAR
  role VARCHAR
  status VARCHAR
  ...
)
```

## New Schema (PROPOSED)
```sql
users (
  user_id INT PRIMARY KEY AUTO_INCREMENT
  first_name VARCHAR(100) NOT NULL
  last_name VARCHAR(100) NOT NULL
  station_id INT
  email VARCHAR(255) UNIQUE
  username VARCHAR(100) UNIQUE
  phone_number VARCHAR(15) UNIQUE
  password_hash VARCHAR(255) NOT NULL
  role ENUM('SuperAdmin','Admin','Manager','Staff')
  status ENUM('Active','Locked','Disabled')
  created_at TIMESTAMP
  updated_at TIMESTAMP
  is_deleted TINYINT(1)
)
```

## Breaking Changes
1. **`id` → `user_id`** - ALL queries need updating
2. **`phone` → `phone_number`** - Field name change
3. **`password` → `password_hash`** - Field name change
4. **`role` → ENUM** - String to ENUM conversion
5. **`status` → ENUM** - String to ENUM conversion

## Files Affected (Estimated 100+)
- All dashboard files
- All transaction files
- All user management files
- All authentication files
- All API endpoints
- All reports

## Recommendation: **DO NOT MIGRATE YET**

### Why Not?
1. **Too many code changes** - 100+ files need updating
2. **High risk of breaking** - Any missed reference will cause errors
3. **Testing complexity** - Need to test entire system
4. **Downtime required** - Cannot do live migration

### Alternative: **Use Current Schema**
The current schema is working fine! Instead of migrating:

1. ✅ **Keep `id` field** - Already working
2. ✅ **Keep `phone` field** - Login already supports it
3. ✅ **Keep `password` field** - Already hashed with bcrypt
4. ✅ **Add missing fields** - `first_name`, `last_name` if needed
5. ✅ **Keep flexible role/status** - VARCHAR more flexible than ENUM

### Minimal Changes Needed
```sql
-- Just add missing fields without breaking existing code
ALTER TABLE users 
  ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) AFTER id,
  ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) AFTER first_name;

-- Update existing users with default names
UPDATE users SET 
  first_name = SUBSTRING_INDEX(COALESCE(name, username, email), ' ', 1),
  last_name = SUBSTRING_INDEX(COALESCE(name, username, email), ' ', -1)
WHERE first_name IS NULL OR last_name IS NULL;
```

## If You MUST Migrate

### Step 1: Full Backup
```bash
mysqldump -u root petron_pos_db_secure > backup_before_migration.sql
```

### Step 2: Run Migration
```bash
php database/migrate_users_safe.php
```

### Step 3: Update ALL Code References
- Search and replace `users.id` → `users.user_id`
- Search and replace `user.id` → `user.user_id`  
- Search and replace `u.id` → `u.user_id`
- Update all `phone` references to `phone_number`
- Update all `password` references to `password_hash`

### Step 4: Test EVERYTHING
- [ ] Login (email/phone/username)
- [ ] Password reset
- [ ] User management
- [ ] All transactions
- [ ] All reports
- [ ] All dashboards
- [ ] All API endpoints

### Step 5: Rollback if Issues
```sql
DROP TABLE users;
ALTER TABLE users_backup_old RENAME TO users;
```

## My Strong Recommendation

**DON'T MIGRATE!** 

The current database structure is working perfectly:
- ✅ Login with email/phone/username works
- ✅ Password reset works
- ✅ CAPTCHA works
- ✅ All features functional

Adding `first_name` and `last_name` columns is safe and non-breaking!
