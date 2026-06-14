# 🗑️ Delete These 2 Stations - Quick Guide

**Stations to delete:**
1. **AEVDZVCB** (gibberish name)
2. **PETRON CDO - KAUSWAGAN** (wala nay labot)

---

## ⚡ FASTEST WAY - Web Tool

### Step 1: Open This Link
```
http://localhost/group31petron_system_official4/public/delete_specific_stations.php
```

### Step 2: Verify the Stations
You'll see a table showing:
- **AEVDZVCB** 
- **PETRON CDO - KAUSWAGAN**

### Step 3: Click Delete Button
Click the red button: **"Delete These Stations Permanently"**

### Step 4: Confirm
Click **"OK"** on the confirmation dialog

### Step 5: Done! ✅
You'll see success message: **"Successfully deleted 2 station(s) permanently!"**

---

## 💾 SQL METHOD - Direct Deletion

### Step 1: Open phpMyAdmin
```
http://localhost/phpmyadmin
```

### Step 2: Select Your Database
Click on your database name in the left sidebar

### Step 3: Go to SQL Tab
Click the **"SQL"** tab at the top

### Step 4: Copy and Paste This Command
```sql
-- Delete these 2 specific stations
DELETE FROM stations
WHERE name IN (
    'AEVDZVCB',
    'PETRON CDO - KAUSWAGAN'
);
```

### Step 5: Click "Go" Button
The query will execute

### Step 6: Check Result
Should say: **"2 rows affected"** or **"2 rows deleted"**

### Step 7: Verify Deletion
Run this query to confirm they're gone:
```sql
SELECT * FROM stations
WHERE name IN (
    'AEVDZVCB',
    'PETRON CDO - KAUSWAGAN'
);
```
**Should return 0 rows** = Successfully deleted! ✅

---

## 🔍 Verify They're Deleted

### Method 1: Search in Map
1. Open: `public/superadmin_admin_map.php`
2. Use search box
3. Search for "AEVDZVCB" - should find nothing
4. Search for "KAUSWAGAN" - should find nothing

### Method 2: Check Total Count
```sql
SELECT COUNT(*) FROM stations;
```
**Before:** 1414 stations  
**After:** 1412 stations (minus 2)

### Method 3: Try to Find Them
```sql
SELECT name FROM stations 
WHERE name LIKE '%AEVDZVCB%' 
   OR name LIKE '%KAUSWAGAN%';
```
**Should return 0 rows** = Deleted! ✅

---

## 🚨 If They Have Admins

**Error message:** "Cannot delete stations with assigned admins"

### Fix:
1. Unassign the admin first
2. Go to Admin Management
3. Find the admin assigned to these stations
4. Change their station to NULL or another station
5. Then delete the stations

---

## ✅ What Happens After Deletion

**Before:**
- Total stations: 1414
- AEVDZVCB exists
- PETRON CDO - KAUSWAGAN exists

**After:**
- Total stations: 1412 (minus 2)
- AEVDZVCB **DELETED** ✅
- PETRON CDO - KAUSWAGAN **DELETED** ✅
- Cannot be recovered (permanent)

---

## 📊 Quick Summary

| Method | Time | Difficulty | Best For |
|--------|------|------------|----------|
| **Web Tool** | 30 seconds | ⭐ Easy | Non-technical users |
| **SQL Direct** | 1 minute | ⭐⭐ Medium | Users comfortable with SQL |

---

## 🔗 Quick Links

### Delete via Web Tool:
**http://localhost/group31petron_system_official4/public/delete_specific_stations.php**

### Delete via SQL:
**http://localhost/phpmyadmin**
```sql
DELETE FROM stations WHERE name IN ('AEVDZVCB', 'PETRON CDO - KAUSWAGAN');
```

### Verify After Deletion:
**http://localhost/group31petron_system_official4/public/verify_cleanup.php**

---

## 💡 Simple Instructions (Cebuano)

**Para e-delete ni duha:**

### Dali Lang:
1. Open: `delete_specific_stations.php`
2. Tan-awa kung naa ba ang duha (AEVDZVCB ug KAUSWAGAN)
3. Click: "Delete These Stations Permanently"
4. Click: "OK"
5. Done! Nadlete na. ✅

### Or SQL:
1. Open phpMyAdmin
2. SQL tab
3. Copy-paste:
   ```sql
   DELETE FROM stations 
   WHERE name IN ('AEVDZVCB', 'PETRON CDO - KAUSWAGAN');
   ```
4. Click "Go"
5. Done! ✅

**Simple lang!** 😊

---

## ⚠️ Important Notes

1. **Permanent Deletion** - Cannot be undone
2. **Backup First** - Always backup before deleting (phpMyAdmin → Export)
3. **Admin Check** - If stations have admins, unassign first
4. **Verify After** - Use verify_cleanup.php to confirm deletion

---

**Created:** June 14, 2026  
**Target:** 2 specific stations  
**Action:** Delete permanently  
**Tools Ready:** Web tool + SQL script ✅
