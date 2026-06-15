# 🎨 System Settings - Estate Form - Quick Start

## 🚀 Access URL
```
http://localhost/group31petron_system_official4/public/superadmin_system_settings.php
```

## ✅ What's Included

### 🔹 Logo Management
- ✓ Upload new logo (JPG, PNG, GIF - max 2MB)
- ✓ Live preview before applying
- ✓ Replace existing logo
- ✓ Remove logo
- ✓ Auto-reflects on dashboard, receipts, and reports

### 🔹 Color Theme / UI Scheme
- ✓ **5 Color Pickers**: Primary, Accent, Success, Warning, Danger
- ✓ **Live Preview Panel**: See button colors before applying
- ✓ **Hex Code Input**: Manual color entry
- ✓ **Apply to All**: Dashboard, buttons, sidebar, navigation

### 🔹 Layout Settings
- ✓ **Sidebar Style**: Inline / Stacked / Compact
- ✓ **Card Arrangement**: Grid / List / Masonry
- ✓ **Font Scaling**: 12px - 18px with live preview
- ✓ **Preview Mode**: Test before saving

### 🔹 Accessibility Options
- ✓ **High Contrast Mode**: Better visibility
- ✓ **Text Scaling**: 100% - 150% (for visual impairments)
- ✓ **Enhanced Focus Indicators**: Improved keyboard navigation
- ✓ **Reduce Motion**: Disable animations

### 🔹 Station Selection
- ✓ **Global Settings**: Apply to all stations
- ✓ **Station-Specific**: Override for individual stations
- ✓ **Search & Filter**: Quick station lookup
- ✓ **Visual Banner**: Shows current configuration scope

---

## 📊 Feature Summary

| Category | Features | Status |
|----------|----------|--------|
| **Logo** | Upload, Preview, Replace, Remove | ✅ Complete |
| **Colors** | 5 pickers, Live preview, Hex input | ✅ Complete |
| **Layout** | Sidebar, Cards, Fonts, Preview | ✅ Complete |
| **Accessibility** | Contrast, Scale, Focus, Motion | ✅ Complete |
| **Scope** | Global + Station-specific | ✅ Complete |

---

## 🎯 How to Use

### Step 1: Select Scope
```
Click search field → Choose "Global" or specific station
```

### Step 2: Configure Settings
```
• Upload logo or change colors
• Adjust layout preferences
• Enable accessibility features
```

### Step 3: Apply Changes
```
Click "Apply" or "Save" button for each section
```

### Step 4: Verify
```
Changes reflect immediately across the system
```

---

## 🗄️ Database

**Table:** `system_settings`
- ✅ Auto-created via setup script
- ✅ Default settings pre-loaded
- ✅ Supports global + station overrides

---

## 📁 Files Created

```
✅ public/superadmin_system_settings.php       # Main interface
✅ backend/api/system_settings_api.php         # API handler
✅ backend/setup_system_settings.php           # Setup script
✅ database/migrations/create_system_settings_table.sql
✅ uploads/logos/                              # Auto-created directory
✅ SYSTEM_SETTINGS_ESTATE_FORM_GUIDE.md        # Full documentation
✅ SYSTEM_SETTINGS_QUICK_START.md              # This file
```

---

## 🎨 Default Color Scheme

| Color | Hex Code | Usage |
|-------|----------|-------|
| Primary | `#002F6C` | Petron Blue - Main brand color |
| Accent | `#CC0000` | Petron Red - Action buttons |
| Success | `#16a34a` | Green - Approve buttons |
| Warning | `#d97706` | Orange - Warning states |
| Danger | `#dc2626` | Red - Reject/Delete buttons |

---

## 🔒 Security

- ✅ Super Admin / Developer access only
- ✅ File upload validation (type + size)
- ✅ SQL injection protection
- ✅ XSS prevention
- ✅ Session-based authentication

---

## ✨ Live Preview Features

1. **Logo Preview**: See uploaded image before saving
2. **Color Preview**: Button colors update in real-time
3. **Font Preview**: Sample text shows selected size
4. **Accessibility Preview**: Toggle features to see effect

---

## 🧪 Quick Test

1. Access the page (login as Super Admin)
2. Upload a test logo → See preview → Apply
3. Change Primary color to `#FF0000` → See red preview → Apply
4. Adjust font size to 16px → See preview text grow
5. Enable High Contrast → Notice darker borders
6. Select a station → Make station-specific changes

---

## 📞 Need Help?

**Check:**
- Database table exists (run setup script if not)
- Uploads directory is writable (755 permissions)
- Browser console for JavaScript errors
- Network tab for API responses

**Documentation:**
See `SYSTEM_SETTINGS_ESTATE_FORM_GUIDE.md` for detailed instructions

---

## 🎉 All Features Working!

The **System Settings - Estate Form** is now fully functional with:

✅ Logo Management (upload, preview, replace, remove)  
✅ Color Theme (5 colors with live preview)  
✅ Layout Settings (sidebar, cards, fonts)  
✅ Accessibility Options (contrast, scaling, focus, motion)  
✅ Station Selection (global + per-station overrides)  
✅ Backend API (all endpoints working)  
✅ Database (table created with defaults)  
✅ File Uploads (directory auto-created)  

**Ready to use! 🚀**

---

**Created:** June 15, 2026  
**Status:** ✅ Production Ready
