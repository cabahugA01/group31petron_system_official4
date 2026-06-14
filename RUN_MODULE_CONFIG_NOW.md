# 🚀 Run Module Configuration Setup NOW

**Quick guide to implement the complete station-dependent module configuration**

---

## ✅ STEP 1: Run Database Setup (REQUIRED)

### Option A: Using phpMyAdmin (Recommended)
1. Open browser: `http://localhost/phpmyadmin`
2. Click database: **`petron_pos_db_secure`** (left sidebar)
3. Click **"SQL"** tab (top navigation)
4. Click **"Choose File"** button
5. Navigate to: `c:\xampp\htdocs\group31petron_system_official4\database\complete_station_module_config.sql`
6. Click **"Go"** button (bottom right)
7. Wait for success message: **"9 tables created successfully"**

### Option B: Using Command Line
```cmd
cd c:\xampp\mysql\bin
mysql -u root -p petron_pos_db_secure < "c:\xampp\htdocs\group31petron_system_official4\database\complete_station_module_config.sql"
```

---

## ✅ STEP 2: Verify Tables Created

Run this SQL query in phpMyAdmin to check:

```sql
-- Check all tables exist
SHOW TABLES LIKE 'station_%';

-- Expected output (9 tables):
-- station_modules
-- station_fuel_config
-- station_merchandise_config
-- station_job_order_config
-- station_payment_config
-- station_inventory_config
-- station_calendar_config
-- station_report_config
-- station_module_audit
```

---

## ✅ STEP 3: Verify Default Data Populated

```sql
-- Check modules per station
SELECT 
    s.name as station,
    COUNT(sm.id) as modules,
    SUM(sm.is_enabled) as enabled
FROM stations s
LEFT JOIN station_modules sm ON sm.station_id = s.id
GROUP BY s.id
ORDER BY s.name;

-- Expected: Each station should have 8 modules, all enabled
```

```sql
-- Check fuel types populated
SELECT 
    s.name as station,
    sfc.fuel_type,
    sfc.official_price_per_liter as price
FROM stations s
INNER JOIN station_fuel_config sfc ON sfc.station_id = s.id
ORDER BY s.name, sfc.fuel_type;

-- Expected: Each station should have Diesel, Gasoline, Kerosene
```

```sql
-- Check payment methods populated
SELECT 
    s.name as station,
    COUNT(spc.id) as payment_methods
FROM stations s
INNER JOIN station_payment_config spc ON spc.station_id = s.id
GROUP BY s.id
ORDER BY s.name;

-- Expected: Each station should have 5 payment methods
```

---

## ✅ STEP 4: Test Backend API

### Open browser console (F12), then test endpoints:

**Test 1: Get Station List**
```javascript
fetch('/backend/api/station_module_api.php?action=get_stations')
    .then(r => r.json())
    .then(d => console.log(d));

// Expected: List of all active stations with module counts
```

**Test 2: Get Modules for Station 1**
```javascript
fetch('/backend/api/station_module_api.php?action=get_station_modules&station_id=1')
    .then(r => r.json())
    .then(d => console.log(d));

// Expected: 8 modules with enabled/disabled status
```

**Test 3: Get Fuel Config for Station 1**
```javascript
fetch('/backend/api/station_module_api.php?action=get_fuel_config&station_id=1')
    .then(r => r.json())
    .then(d => console.log(d));

// Expected: Diesel, Gasoline, Kerosene with prices and settings
```

---

## ✅ STEP 5: Access Module Configuration Page

1. Login as SuperAdmin/Developer
2. Navigate to: `http://localhost/group31petron_system_official4/public/module_configuration.php`
3. You should see:
   - ✅ Toast notification appears at TOP CENTER (fixed!)
   - ✅ Search bar and filter dropdown
   - ✅ Table of modules (current view - will be changed to station list)

---

## 🎯 What's Ready NOW:

### ✅ Backend Complete
- [x] Database schema with 9 tables
- [x] Default data for all stations
- [x] Complete API with 10 endpoints:
  - `get_stations` - List stations with module counts
  - `get_station_modules` - Modules for specific station
  - `toggle_module` - Enable/disable module
  - `get_fuel_config` - Fuel settings per station
  - `update_fuel_config` - Update fuel settings
  - `get_payment_config` - Payment methods per station
  - `get_inventory_config` - Inventory rules per station
  - `update_inventory_config` - Update inventory rules
  - `get_report_config` - Report settings per station
  - `get_audit_log` - Complete change history
- [x] Full audit trail logging
- [x] CSRF protection
- [x] Role-based access control

### ⏳ Frontend In Progress
The current `module_configuration.php` shows modules globally. Next step is to redesign it to:
1. Show **station list** instead of module list
2. Add "Configure Modules" button per station
3. Open modal showing modules for THAT station only
4. Detailed configuration panels for each module type

---

## 📊 Database Table Summary

| Table | Records | Purpose |
|-------|---------|---------|
| **station_modules** | ~1413 stations × 8 modules = ~11,304 | Enable/disable per station |
| **station_fuel_config** | ~1413 × 3 fuel types = ~4,239 | Fuel prices and settings |
| **station_payment_config** | ~1413 × 5 methods = ~7,065 | Payment method configuration |
| **station_inventory_config** | ~1413 | Inventory rules per station |
| **station_calendar_config** | ~1413 | Calendar settings per station |
| **station_report_config** | ~1413 × 5 reports = ~7,065 | Report access per station |
| **station_merchandise_config** | 0 (empty) | Will populate as items added |
| **station_job_order_config** | 0 (empty) | Will populate as services added |
| **station_module_audit** | 0 (empty) | Will populate as changes made |

---

## 🔍 Troubleshooting

### Issue: Tables not created
**Fix:** Make sure you selected the correct database (`petron_pos_db_secure`) before running SQL

### Issue: Foreign key errors
**Fix:** Verify `stations` table exists and has `id` column as primary key

### Issue: No data populated
**Fix:** Check if `stations` table has records with `status = 'Active'`

### Issue: API returns 403 Access Denied
**Fix:** Login as SuperAdmin role (role_key must be 'superadmin')

### Issue: CSRF token error
**Fix:** Make sure session is active and CSRF token is passed in POST requests

---

## 📖 Documentation Files

| File | Purpose |
|------|---------|
| `complete_station_module_config.sql` | Complete database schema (RUN THIS FIRST) |
| `station_module_api.php` | Backend API (ALREADY CREATED) |
| `COMPLETE_MODULE_CONFIG_FIELDS.md` | Complete field documentation |
| `RUN_MODULE_CONFIG_NOW.md` | This quick start guide |
| `STATION_DEPENDENT_MODULE_CONFIG_SPEC.md` | Technical specification |
| `STATION_MODULE_CONFIG_SUMMARY.md` | Overview summary |

---

## 🎯 Next Steps

### Immediate
1. **RUN THE SQL FILE** (Step 1 above) ⭐ MOST IMPORTANT
2. Verify tables created (Step 2)
3. Test API endpoints (Step 4)

### Frontend UI (Next Phase)
4. Redesign `module_configuration.php` to show station list
5. Create detailed configuration modals for each module
6. Add visual indicators for module status per station
7. Implement audit log viewer

---

## ✅ Success Criteria

After running the SQL, you should be able to:
- ✅ See 9 new tables in database starting with `station_`
- ✅ Each active station has 8 modules enabled by default
- ✅ Each station has 3 fuel types configured
- ✅ Each station has 5 payment methods configured
- ✅ API endpoints return data without errors
- ✅ Audit trail table is ready to log changes

---

**STATUS:** Backend Ready ✅ | Database Schema Ready ✅ | API Ready ✅  
**NEXT:** Run SQL file, then build frontend UI  
**TIME:** 2 minutes to run SQL, then 8-12 hours for complete UI

**Run ang SQL file karon para ma-setup tanan! 🚀✅**
