# Database Management - Quick Start Guide

## 🚀 INSTANT ACCESS

**URL:** `http://localhost/group31petron_system_official4/public/database_management.php`

**Required Role:** SuperAdmin only

---

## 📑 5 TABS - QUICK REFERENCE

### 1️⃣ BACKUP TAB (Default)
**Para sa:** Creating database backups

**Actions:**
- **Backup Now** → Instant backup creation
- **Configure** → Set automatic schedule
- **View Backups** → See backup history

**Quick Setup:**
```
1. Click "Configure"
2. Set frequency: Daily/Weekly/Monthly
3. Set storage: Local/Cloud
4. Set retention: 30 days
5. Click "Save Settings"
```

---

### 2️⃣ RESTORE TAB
**Para sa:** Restoring from backup

**Actions:**
- **Restore Point** → Select backup to restore
- **Restore History** → View past restores

**Quick Restore:**
```
1. Click "Restore Point"
2. Select backup file from dropdown
3. Choose "Full Database" or "Specific Tables"
4. Confirm (2 confirmations required)
5. Wait for completion
```

**⚠️ WARNING:** Restoring overwrites current data!

---

### 3️⃣ SCHEMA & MIGRATIONS TAB
**Para sa:** Database structure changes

**Actions:**
- **Update Schema** → Modify table structure
- **Migration History** → View changes
- **Optimize Database** → Performance tuning

**Quick Optimize:**
```
1. Click "Optimize Database"
2. Confirm action
3. Wait for completion (all tables optimized)
```

**Schema Update:**
```
1. Click "Update Schema"
2. Select table from dropdown
3. Choose action: Add/Modify/Remove column
4. Fill in details
5. Click "Apply Changes"
```

---

### 4️⃣ REPLICATION TAB
**Para sa:** Multi-station database syncing

**Actions:**
- **Enable Sync** → Start replication
- **Disable Sync** → Stop replication
- **Configure** → Setup sync rules
- **Sync Status** → Monitor replication

**Quick Setup:**
```
1. Click "Configure"
2. Select station (or All Stations)
3. Set frequency: Real-time/5min/15min/Hourly/Daily
4. Set conflict rule: Overwrite/Merge/Manual
5. Click "Save Settings"
6. Click "Enable Sync"
```

---

### 5️⃣ SECURITY LOGS TAB
**Para sa:** Monitoring database access

**Actions:**
- **View Logs** → Display audit trail
- **Export Logs** → Download Excel
- **Alert Setup** → Configure alerts

**Quick View:**
```
1. Click "View Logs"
2. Use filters:
   - Date range
   - User ID
   - Station
3. Click "Filter"
4. Click "Export" to download
```

---

## ⌨️ KEYBOARD SHORTCUTS

- **Tab** → Navigate between tabs
- **Enter** → Confirm action in modal
- **Esc** → Close modal (if focus is on modal)
- **Ctrl+Click** → Open action in new context

---

## 🎯 COMMON TASKS

### Daily Backup:
1. Open page → Backup tab (default)
2. Click "Backup Now"
3. Wait for confirmation toast
4. Done! ✓

### Emergency Restore:
1. Click "Restore" tab
2. Click "Restore Point"
3. Select latest backup
4. Choose "Full Database"
5. Double-confirm
6. Wait for restore
7. Done! ✓

### Database Optimization:
1. Click "Schema & Migrations" tab
2. Click "Optimize Database"
3. Confirm
4. Wait for completion
5. Done! ✓

### Enable Multi-Station Sync:
1. Click "Replication" tab
2. Click "Configure"
3. Set frequency: Real-time
4. Set conflict: Overwrite
5. Click "Save Settings"
6. Click "Enable Sync"
7. Done! ✓

### Export Security Logs:
1. Click "Security Logs" tab
2. Click "View Logs"
3. Set date filters
4. Click "Export"
5. Save Excel file
6. Done! ✓

---

## 🎨 VISUAL INDICATORS

### Status Colors:
- 🔵 **Blue** → Primary action (safe to click)
- 🟢 **Green** → Success/Enable
- 🟠 **Orange** → Warning/Caution
- 🔴 **Red** → Danger/Disable
- ⚫ **Gray** → Secondary/View

### Toast Messages:
- 🟢 **Green Toast** → Success! ✓
- 🔴 **Red Toast** → Error! ✗
- 🟠 **Orange Toast** → Warning/Processing...

### Tab States:
- **Blue Underline** → Active tab
- **Gray Text** → Inactive tab
- **Light Blue Background** → Hover state

---

## 📊 DATABASE STATS (Always Visible)

Top of page shows 4 cards:
1. **Database Size** → Current storage used
2. **Total Tables** → Number of tables
3. **Total Records** → Approximate row count
4. **Last Backup** → Timestamp of last backup

---

## ⚠️ IMPORTANT WARNINGS

### Before Restore:
- ✅ Create fresh backup first!
- ✅ Notify all users (system will be affected)
- ✅ Double-check backup file selection
- ✅ Understand full vs partial restore

### Before Schema Changes:
- ✅ Test changes in dev environment first
- ✅ Backup before modifying structure
- ✅ Check for data loss risks
- ✅ Review foreign key dependencies

### Before Replication:
- ✅ Ensure network connectivity
- ✅ Test sync on single station first
- ✅ Understand conflict resolution rules
- ✅ Monitor first sync completion

---

## 🔧 TROUBLESHOOTING

### Tab not switching?
- Check JavaScript console for errors
- Hard refresh: Ctrl+F5
- Clear browser cache

### Backup fails?
- Check `/backups/` folder permissions
- Verify mysqldump is accessible
- Check disk space

### Restore stuck?
- Backup file too large (increase PHP limits)
- Check MySQL connection
- Review server error logs

### Logs not loading?
- Check `activity_logs` table exists
- Verify API connection
- Check user permissions

### Modal not opening?
- JavaScript error (check console)
- Clear browser cache
- Try different browser

---

## 📂 FILE LOCATIONS

**Main Page:**
```
public/database_management.php
```

**API Endpoint:**
```
backend/api/database_api.php
```

**Backup Storage:**
```
backups/
├── backup_2026_06_14_103045.sql
├── backup_2026_06_13_103045.sql
└── backup_2026_06_12_103045.sql
```

**Database Tables:**
- `database_backups` → Backup history
- `database_restores` → Restore history
- `system_config` → Settings
- `schema_migrations` → Schema changes
- `activity_logs` → Security logs

---

## 🎯 SUCCESS CHECKLIST

Before closing page, verify:
- [ ] Backup created successfully
- [ ] Configuration saved
- [ ] Toast notification appeared
- [ ] Database stats updated
- [ ] Logs show recent activity

---

## 📱 MOBILE ACCESS

**Tab Navigation:**
- Tabs scroll horizontally if screen narrow
- Stats cards stack vertically
- Modals adjust to viewport
- Buttons wrap to multiple rows

---

## 💡 PRO TIPS

1. **Daily Backups:** Set frequency to "Daily" in Configure
2. **Quick Access:** Bookmark the page for easy access
3. **Monitor Logs:** Check Security Logs weekly
4. **Optimize Monthly:** Run optimization once per month
5. **Test Restores:** Practice restore process with test data

---

## 📞 SUPPORT

**Error Messages:**
- Check toast notification for details
- Review browser console (F12)
- Check PHP error log

**Database Issues:**
- Verify MySQL is running
- Check user permissions
- Review connection settings

**Access Denied:**
- Ensure SuperAdmin role
- Check session status
- Re-login if needed

---

## ✅ FINAL NOTES

**Page Status:** ✅ Ready for production use

**Features Working:**
- ✅ All 5 tabs functional
- ✅ Tab switching smooth
- ✅ All buttons working
- ✅ Modals opening/closing
- ✅ API endpoints responding
- ✅ Toast notifications showing

**Security:**
- ✅ SuperAdmin access only
- ✅ CSRF protection enabled
- ✅ SQL injection prevented
- ✅ XSS protection active

---

**READY TO USE!** 🚀

Navigate to Database Management page and click tabs to access features. Default tab (Backup) loads first. All functions are operational!

---

**Quick Start:** Click "Backup Now" to test immediately! ✓
