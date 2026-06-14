# Admin Map Integration - Quick Start Guide

## 🚀 Quick Setup (5 Minutes)

### Step 1: Run Database Migration
1. Open **phpMyAdmin** (http://localhost/phpmyadmin)
2. Select your database (e.g., `petron_system`)
3. Go to **SQL** tab
4. Copy and paste the contents of `database/migrations/add_station_coordinates.sql`
5. Click **Go** to execute

### Step 2: Add Sample Coordinates (Optional)
If you want to test immediately, run this SQL:

```sql
-- Update existing stations with sample coordinates
UPDATE stations SET 
    latitude = 14.5995, 
    longitude = 120.9842, 
    region = 'NCR',
    contact_number = '(02) 8888-8888'
WHERE id = 1;

UPDATE stations SET 
    latitude = 14.6091, 
    longitude = 121.0223, 
    region = 'NCR',
    contact_number = '(02) 7777-7777'
WHERE id = 2;
```

### Step 3: Access the Map
1. Log in as **SuperAdmin**
2. Go to: **Admin Management** page
3. Click **"Map View"** button (top right)
4. Done! 🎉

---

## 📍 Quick Actions

### Assign Admin to Station
1. Click station marker (pin) on map
2. Select admin from dropdown
3. Click "Assign Admin"

### Search Stations
- Type in search box: station name, city, or admin name

### Filter by Region
- Select from "All Regions" dropdown (NCR, Region I, etc.)

### Filter by Status
- Select from "All Status" dropdown (Active, Inactive, Pending)

---

## 🎨 Pin Colors

| Color | Meaning |
|-------|---------|
| 🟢 Green | Active Admin assigned |
| 🔴 Red | No Admin / Inactive |
| 🟡 Yellow | Pending validation |

---

## ⚠️ Important Rules

✅ **1 Admin per Station ONLY**
- System automatically enforces this
- Reassigning an admin removes them from previous station

✅ **Active Stations Only**
- Only stations with `status = 'Active'` appear on map

✅ **Real-Time Updates**
- Map refreshes after each assignment

---

## 🔧 Troubleshooting

### Map is blank?
- Check internet connection (needs CDN access)
- Verify stations have `status = 'Active'` in database

### No stations showing?
- Run the database migration first
- Check if stations exist in database

### Can't assign admin?
- Verify admin has no current station assignment
- Check browser console for errors

---

## 📚 Need More Help?

See the full guide: `ADMIN_MAP_INTEGRATION_GUIDE.md`

---

**Quick Links:**
- List View: `superadmin_admin_management.php`
- Map View: `superadmin_admin_map.php`
- API: `backend/api/superadmin_admin_map_api.php`
