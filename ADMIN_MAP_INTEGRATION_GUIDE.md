# Admin Management with Map Integration - Complete Guide

## Overview
The Admin Management Map Integration provides an interactive, visual interface for SuperAdmins to manage station-admin assignments across all Petron stations nationwide using an interactive map.

---

## Features

### 🗺️ Interactive Map View
- **Visual Station Locator**: Interactive map showing all stations nationwide
- **Color-Coded Pins**: Each station pin has a color indicating its admin status
  - 🟢 **Green**: Active Admin assigned
  - 🔴 **Red**: No Admin / Inactive
  - 🟡 **Yellow**: Pending validation
- **Clickable Markers**: Click any station pin to view details and manage admin assignments

### 📊 Real-Time Statistics
- Live dashboard showing:
  - Total number of stations
  - Stations with active admins
  - Stations without admins
  - Currently filtered station count

### 🔍 Advanced Search & Filtering
- **Search by**: Station name, city, region, or admin name
- **Filter by Region**: All Philippine regions (NCR, Region I-XIII, CAR, BARMM)
- **Filter by Status**: 
  - Active Admin
  - No Admin / Inactive
  - Pending validation

### 👤 Admin Assignment
- **Click-to-Assign**: Click station pin → Select admin from dropdown → Assign
- **One Admin Per Station Rule**: System automatically enforces 1:1 relationship
- **Auto-Reassignment**: If assigning an admin to a new station, they're automatically unassigned from their previous station
- **Unassigned Admin Pool**: See all admins without station assignments

### 📍 Station Details Modal
When you click a station pin, you see:
- Station name
- Full address
- Contact number
- Currently assigned admin (if any)
- Current status with badge
- Admin assignment dropdown
- Quick assign button

---

## File Structure

```
group31petron_system_official4/
├── public/
│   ├── superadmin_admin_management.php   # List view (existing)
│   └── superadmin_admin_map.php          # NEW: Map view
├── backend/
│   └── api/
│       └── superadmin_admin_map_api.php  # NEW: Map API
├── database/
│   └── migrations/
│       └── add_station_coordinates.sql   # NEW: DB migration
└── ADMIN_MAP_INTEGRATION_GUIDE.md        # This file
```

---

## Installation & Setup

### Step 1: Database Migration
Run the SQL migration to add coordinates to your stations table:

```sql
-- Navigate to phpMyAdmin or your MySQL client
-- Select your database
-- Execute: database/migrations/add_station_coordinates.sql
```

This adds the following columns to `stations` table:
- `latitude` (DECIMAL 10,8) - Station latitude coordinate
- `longitude` (DECIMAL 11,8) - Station longitude coordinate
- `region` (VARCHAR 100) - Philippine region (NCR, Region I, etc.)
- `contact_number` (VARCHAR 50) - Station contact number

### Step 2: Update Station Data
You need to add coordinates for your stations. You can:

**Option A: Manual Entry via phpMyAdmin**
```sql
UPDATE stations 
SET 
    latitude = 14.5995, 
    longitude = 120.9842, 
    region = 'NCR',
    contact_number = '(02) 1234-5678'
WHERE id = 1;
```

**Option B: Use Google Maps API / Geocoding Service**
- Get coordinates from Google Maps
- Use a geocoding API to convert addresses to coordinates

**Option C: Use the Auto-Generation (Temporary)**
The map currently includes a fallback that generates coordinates based on region if not set in database.

### Step 3: Access the Map
1. Log in as SuperAdmin
2. Navigate to: **Admin Management** page
3. Click the **"Map View"** button in the top right
4. You'll see the interactive map with all stations

---

## How to Use

### Viewing Stations on Map
1. **Initial Load**: Map centers on Philippines with all active stations displayed
2. **Zoom In/Out**: Use mouse wheel or +/- buttons
3. **Pan**: Click and drag the map
4. **Click Pin**: Click any station marker to see details

### Searching for Stations
1. Use the **search box** at the top
2. Type station name, city, or admin name
3. Map automatically filters to show matching stations only

### Filtering by Region
1. Click the **"All Regions"** dropdown
2. Select a specific region (e.g., NCR, Region IV-A)
3. Map shows only stations in that region

### Filtering by Status
1. Click the **"All Status"** dropdown
2. Select:
   - **Active Admin**: Shows only stations with active admins
   - **No Admin / Inactive**: Shows stations without admins
   - **Pending Validation**: Shows pending assignments

### Assigning an Admin to a Station
1. **Click the station marker** on the map
2. A modal opens showing station details
3. In the **"Assign Admin"** dropdown, select an admin
   - Only unassigned admins appear in the list
4. Click **"Assign Admin"** button
5. System confirms assignment and refreshes the map
6. Pin color changes to green (active)

### Rules & Constraints
- ✅ **1 Admin per Station**: Each station can have only ONE admin
- ✅ **Auto-Unassignment**: If you assign an admin to a new station, they're automatically unassigned from their previous station
- ✅ **Real-Time Updates**: Map updates immediately after assignment
- ✅ **Activity Logging**: All assignments/unassignments are logged

---

## API Endpoints

### `superadmin_admin_map_api.php`

#### 1. Assign Admin to Station
```
POST /backend/api/superadmin_admin_map_api.php
action: assign_admin_to_station
station_id: <station_id>
admin_id: <admin_id>
csrf_token: <token>
```

**Response:**
```json
{
  "ok": true,
  "message": "Admin successfully assigned to Station Name."
}
```

#### 2. Unassign Admin from Station
```
POST /backend/api/superadmin_admin_map_api.php
action: unassign_admin_from_station
station_id: <station_id>
csrf_token: <token>
```

**Response:**
```json
{
  "ok": true,
  "message": "Admin successfully unassigned from Station Name."
}
```

#### 3. Get Station Details
```
POST /backend/api/superadmin_admin_map_api.php
action: get_station_details
station_id: <station_id>
```

**Response:**
```json
{
  "ok": true,
  "station": {
    "id": 1,
    "name": "Petron Quezon City",
    "location": "123 Main St, Quezon City",
    "status": "Active",
    "admin_id": 5,
    "admin_name": "Juan Dela Cruz",
    "admin_email": "juan@petron.com",
    "admin_phone": "09123456789",
    "admin_status": "active"
  }
}
```

---

## Map Technology

### Leaflet.js
The map uses **Leaflet.js**, a leading open-source JavaScript library for interactive maps.

**CDN Used:**
```html
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
```

**Tile Provider:** OpenStreetMap (free, no API key required)

### Custom Markers
- Circular pins with custom colors
- 24x24px size
- White border with shadow for visibility
- Color changes based on admin status

---

## Troubleshooting

### Map Not Loading
**Problem:** Map container is blank
**Solution:**
- Check browser console for errors
- Ensure Leaflet CSS and JS are loading from CDN
- Verify internet connection (CDN access required)

### Stations Not Appearing
**Problem:** No markers on map
**Solution:**
- Check if stations have `status = 'Active'` in database
- Verify stations query in PHP (line 42-55 of `superadmin_admin_map.php`)
- Check browser console for JavaScript errors

### Incorrect Pin Colors
**Problem:** Pins showing wrong color
**Solution:**
- Verify `getStationStatus()` function logic
- Check admin status values in database (`'active'` vs `'Active'`)
- Ensure users table has correct `status` values

### Coordinates Not Showing Correctly
**Problem:** Stations appearing in wrong locations
**Solution:**
- Verify latitude/longitude values in database
- Latitude range: -90 to 90 (Philippines: ~4 to 21)
- Longitude range: -180 to 180 (Philippines: ~116 to 127)
- Use Google Maps to get accurate coordinates

### Assignment Not Working
**Problem:** "Assign Admin" button doesn't work
**Solution:**
- Check browser console for errors
- Verify CSRF token is set in session
- Check `superadmin_admin_map_api.php` for errors
- Verify database permissions for UPDATE operations

---

## Future Enhancements

### Planned Features
- [ ] **Geocoding Integration**: Auto-convert addresses to coordinates
- [ ] **Route Planning**: Calculate distance between stations
- [ ] **Station Clustering**: Group nearby stations at low zoom levels
- [ ] **Heatmap View**: Visualize station density
- [ ] **Export Map**: Download map as image/PDF
- [ ] **Mobile Optimization**: Touch-friendly controls
- [ ] **Bulk Assignment**: Assign multiple admins at once
- [ ] **Historical View**: See past admin assignments on timeline

### Optional Integrations
- **Google Maps API**: More detailed maps and places
- **Mapbox**: Custom map styling
- **HERE Maps**: Traffic and routing data

---

## Security Considerations

### Authentication & Authorization
- ✅ Only SuperAdmin and Developer roles can access
- ✅ Session-based authentication required
- ✅ CSRF token validation on all API calls

### Data Validation
- ✅ Station ID and Admin ID validated before assignment
- ✅ SQL injection prevention via prepared statements
- ✅ XSS prevention via `htmlspecialchars()` in output

### Activity Logging
- ✅ All admin assignments logged to `activity_logs` table
- ✅ Includes user ID, timestamp, and action details
- ✅ Audit trail for compliance and security

---

## Browser Compatibility

### Tested Browsers
- ✅ Google Chrome 90+
- ✅ Mozilla Firefox 88+
- ✅ Microsoft Edge 90+
- ✅ Safari 14+
- ✅ Opera 76+

### Mobile Browsers
- ✅ Chrome Mobile
- ✅ Safari iOS
- ✅ Samsung Internet

### Known Issues
- ❌ Internet Explorer 11: Not supported (Leaflet.js requires modern JavaScript)

---

## Performance Tips

### For Large Datasets (100+ stations)
1. **Enable Marker Clustering**: Group nearby markers at low zoom
2. **Lazy Loading**: Load markers as user pans/zooms
3. **Server-Side Filtering**: Filter stations on backend before sending to map
4. **Caching**: Cache station data in session or localStorage

### Optimization
- Map loads asynchronously (doesn't block page render)
- Markers update only when filter changes
- Lightweight SVG icons instead of PNG images

---

## Support & Contact

### Getting Help
- Check this documentation first
- Review browser console for error messages
- Check PHP error logs (`/logs` or server logs)
- Verify database structure and data

### Reporting Issues
When reporting issues, include:
1. Browser and version
2. Steps to reproduce
3. Error messages from console
4. Screenshots if applicable

---

## Changelog

### Version 1.0.0 (Initial Release)
- Interactive map with Leaflet.js
- Station markers with color-coded status
- Search and filter by region/status
- Click-to-assign admin functionality
- One admin per station rule enforcement
- Real-time statistics panel
- Activity logging for all assignments

---

## Credits

**Developed by:** Petron Station Management System Team
**Map Library:** Leaflet.js (https://leafletjs.com/)
**Tile Provider:** OpenStreetMap (https://www.openstreetmap.org/)
**Icons:** Font Awesome 6

---

**Last Updated:** June 14, 2026
**Version:** 1.0.0
