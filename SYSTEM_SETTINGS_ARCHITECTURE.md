# 🏗️ System Settings - Estate Form Architecture

## 📐 System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     USER INTERFACE LAYER                         │
│  (public/superadmin_system_settings.php)                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌──────────┐ │
│  │  Station   │  │    Logo    │  │   Color    │  │  Layout  │ │
│  │  Selector  │  │ Management │  │   Theme    │  │ Settings │ │
│  └────────────┘  └────────────┘  └────────────┘  └──────────┘ │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │           Accessibility Options                             │ │
│  │  • High Contrast  • Text Scaling  • Focus  • Motion        │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       │ AJAX Calls
                       │
┌──────────────────────▼──────────────────────────────────────────┐
│                     API LAYER                                    │
│  (backend/api/system_settings_api.php)                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Actions:                                                        │
│  • upload_logo        → Handle file uploads                     │
│  • remove_logo        → Delete logo files                       │
│  • save_colors        → Store color scheme                      │
│  • save_layout        → Store layout preferences                │
│  • save_accessibility → Store accessibility settings            │
│  • get_settings       → Retrieve all settings                   │
│                                                                  │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       │ PDO Queries
                       │
┌──────────────────────▼──────────────────────────────────────────┐
│                   DATABASE LAYER                                 │
│  (MySQL - system_settings table)                                │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Table: system_settings                                          │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ id | setting_key | setting_value | station_id | category   │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │ 1  | global_color_primary | #002F6C | 0 | theme          │ │
│  │ 2  | global_color_accent  | #CC0000 | 0 | theme          │ │
│  │ 3  | station_5_logo       | path... | 5 | branding       │ │
│  │ 4  | global_layout_...    | inline  | 0 | layout         │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🔄 Data Flow Diagram

### Upload Logo Flow
```
User selects file
     │
     ▼
JavaScript validates (type, size)
     │
     ▼
Shows preview (FileReader)
     │
     ▼
User clicks "Apply Logo"
     │
     ▼
FormData sent to API
     │
     ▼
API validates & saves to /uploads/logos/
     │
     ▼
Path saved to database
     │
     ▼
Success response → Toast notification
```

### Save Colors Flow
```
User adjusts color picker
     │
     ▼
Live preview updates (JavaScript)
     │
     ▼
User clicks "Apply Color Scheme"
     │
     ▼
JSON data sent to API
     │
     ▼
API saves all colors to database
     │
     ▼
Page reloads with new colors
```

### Station Selection Flow
```
User clicks search field
     │
     ▼
Dropdown shows all stations
     │
     ▼
User selects station or global
     │
     ▼
currentStationId updated
     │
     ▼
loadAllSettings() called
     │
     ▼
Settings loaded for selected scope
```

---

## 🗂️ File Organization

```
group31petron_system_official4/
│
├── public/
│   └── superadmin_system_settings.php ──────┐ Main UI
│                                             │
├── backend/
│   ├── api/
│   │   └── system_settings_api.php ─────────┤ API Handler
│   └── setup_system_settings.php ───────────┤ Setup Script
│                                             │
├── database/
│   └── migrations/
│       └── create_system_settings_table.sql ┤ DB Schema
│                                             │
├── uploads/
│   └── logos/ ──────────────────────────────┤ Logo Storage
│       ├── logo_global_1718449200.png       │
│       └── logo_station_5_1718449300.jpg    │
│                                             │
└── Documentation/
    ├── SYSTEM_SETTINGS_ESTATE_FORM_GUIDE.md ┤ Full Guide
    ├── SYSTEM_SETTINGS_QUICK_START.md ──────┤ Quick Start
    └── SYSTEM_SETTINGS_ARCHITECTURE.md ─────┘ This File
```

---

## 🔌 API Endpoint Map

```
Backend API: backend/api/system_settings_api.php
├── POST /upload_logo
│   ├── Input: FormData (logo file, station_id)
│   ├── Validation: Type, Size (2MB max)
│   ├── Action: Save to uploads/logos/, Insert DB
│   └── Output: { success, logo_url }
│
├── GET /remove_logo?station_id=X
│   ├── Action: Delete file & DB record
│   └── Output: { success, message }
│
├── POST /save_colors
│   ├── Input: JSON { station_id, colors: {...} }
│   ├── Action: Insert/Update 5 color settings
│   └── Output: { success, message }
│
├── POST /save_layout
│   ├── Input: JSON { station_id, settings: {...} }
│   ├── Action: Insert/Update layout settings
│   └── Output: { success, message }
│
├── POST /save_accessibility
│   ├── Input: JSON { station_id, settings: {...} }
│   ├── Action: Insert/Update accessibility settings
│   └── Output: { success, message }
│
└── GET /get_settings?station_id=X
    ├── Action: Fetch all settings for scope
    └── Output: { success, settings: { colors, layout, accessibility, logo } }
```

---

## 🎨 Setting Key Convention

### Pattern:
```
[scope]_[category]_[name]

Where:
  scope    = "global" | "station_{id}"
  category = "color" | "layout" | "accessibility" | "logo"
  name     = specific setting name
```

### Examples:
```
✓ global_color_primary          → #002F6C
✓ global_color_accent           → #CC0000
✓ global_layout_sidebar_style   → inline
✓ global_accessibility_font_scale → 120
✓ station_5_color_primary       → #FF0000 (overrides global)
✓ station_5_logo                → ../uploads/logos/station_5.png
```

---

## 🔐 Security Layers

```
┌─────────────────────────────────────────┐
│  Layer 1: Authentication                │
│  • require_login()                      │
│  • Role check (superadmin/developer)    │
└────────────┬────────────────────────────┘
             │
┌────────────▼────────────────────────────┐
│  Layer 2: Input Validation              │
│  • File type check (upload)             │
│  • File size limit (2MB)                │
│  • Hex color validation                 │
│  • Integer station_id validation        │
└────────────┬────────────────────────────┘
             │
┌────────────▼────────────────────────────┐
│  Layer 3: Database Protection           │
│  • Prepared statements (SQL injection)  │
│  • Parameterized queries                │
│  • Unique constraints                   │
└────────────┬────────────────────────────┘
             │
┌────────────▼────────────────────────────┐
│  Layer 4: Output Sanitization           │
│  • HTML escaping (XSS prevention)       │
│  • JSON encoding                        │
│  • Content-Type headers                 │
└─────────────────────────────────────────┘
```

---

## 🧩 Component Interaction

```
┌──────────────────────────────────────────────────────────────┐
│                    Station Selector                          │
│  Manages: currentStationId (0 = global)                      │
└────────┬─────────────────────────────────────────────────────┘
         │ Triggers: loadAllSettings()
         │
┌────────▼─────────────┐  ┌──────────────┐  ┌───────────────┐
│   Logo Management    │  │ Color Theme  │  │ Layout Config │
│  • Upload            │  │ • 5 pickers  │  │ • Sidebar     │
│  • Preview           │  │ • Live view  │  │ • Cards       │
│  • Remove            │  │ • Hex input  │  │ • Font size   │
└──────────────────────┘  └──────────────┘  └───────────────┘
         │                       │                    │
         └───────────┬───────────┴────────────────────┘
                     │
         ┌───────────▼───────────────────────────────────────┐
         │        Accessibility Options                      │
         │  • High Contrast  • Text Scale                    │
         │  • Focus Indicators  • Reduce Motion              │
         └───────────────────────────────────────────────────┘
```

---

## 📊 Database Schema Detail

```sql
system_settings
├── id (PK, AUTO_INCREMENT)
├── setting_key (VARCHAR 100, UNIQUE with station_id)
│   └── Pattern: {scope}_{category}_{name}
├── setting_value (TEXT)
│   └── Stores: hex codes, paths, integers, booleans
├── station_id (INT, DEFAULT 0)
│   └── 0 = global, >0 = specific station
├── category (VARCHAR 50)
│   └── Values: branding, theme, layout, accessibility
├── updated_by (INT, FK to users)
├── updated_at (TIMESTAMP, AUTO_UPDATE)
└── created_at (TIMESTAMP, DEFAULT NOW)

Indexes:
├── PRIMARY KEY (id)
├── UNIQUE KEY (setting_key, station_id)
├── INDEX (station_id)
├── INDEX (category)
└── INDEX (setting_key)
```

---

## 🚀 Performance Optimization

### Frontend:
- **Debounced inputs**: Color picker updates throttled
- **Lazy loading**: Settings loaded on demand
- **Local preview**: Changes shown before API call
- **Minimal reloads**: Only when necessary

### Backend:
- **Indexed queries**: Fast lookups by station_id
- **Prepared statements**: Query compilation reuse
- **Bulk inserts**: Multiple settings in one transaction
- **Optimized SELECT**: Only fetches needed columns

### Storage:
- **Unique filenames**: Timestamp + station_id
- **Directory structure**: Organized by type
- **Size limits**: 2MB max prevents large uploads
- **Cleanup**: Old files deleted on replace/remove

---

## 🔄 State Management

```javascript
// Global State Variables
const STATIONS = [...];        // All stations (loaded from PHP)
let currentStationId = 0;      // Active scope (0 = global)
let currentLogoFile = null;    // Pending logo upload

// State Updates Trigger:
selectStation(id) 
  → Updates currentStationId
  → Reloads all settings via loadAllSettings()
  → Updates UI indicators

handleLogoUpload(file)
  → Stores in currentLogoFile
  → Shows preview
  → Waits for user to click "Apply"

updateColorHex(name, value)
  → Updates both picker & hex field
  → Triggers live preview
  → Doesn't save until "Apply" clicked
```

---

## 📈 Scalability Considerations

### Current Design:
- ✅ Supports unlimited stations
- ✅ Handles global + per-station overrides
- ✅ Extensible setting categories
- ✅ No hardcoded limits

### Future Expansion:
- Add more color options (sidebar, cards, etc.)
- Support theme presets (Petron Blue, Dark Mode, etc.)
- Add font family selection
- Support multiple logo slots (header, footer, mobile)
- Add setting history/audit trail
- Support setting templates/import-export

---

## 🎯 Design Principles

1. **Single Page Interface**: All settings in one view
2. **Live Previews**: See changes before applying
3. **Scope Flexibility**: Global or station-specific
4. **Non-Destructive**: Changes require explicit "Apply"
5. **Accessible**: Works with keyboard & screen readers
6. **Responsive**: Mobile-friendly design
7. **Secure**: Multi-layer validation & sanitization
8. **Performant**: Optimized queries & minimal JS

---

**Architecture Version:** 1.0  
**Last Updated:** June 15, 2026  
**Status:** ✅ Production Ready
