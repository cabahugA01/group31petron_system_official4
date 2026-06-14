# Quick Test Guide - Searchable Station Dropdown

## 🚀 QUICK START

### Open the Page
```
http://localhost/group31petron_system_official4/public/module_configuration.php
```

---

## ✅ 5-MINUTE TEST CHECKLIST

### ✓ Test 1: Can Type in Input Box
1. Click the "Search Station" input box
2. **Type:** "petron"
3. **Expected:** Input accepts text, dropdown shows filtered stations

### ✓ Test 2: Shows All 1413 Stations
1. **Clear the input** (delete all text)
2. **Click the input** or **click the dropdown arrow** (▼)
3. **Expected:** Dropdown shows ALL stations
4. **Check console** (F12): Should say `✅ Loaded 1413 stations`
5. **Scroll to bottom:** Should see "Total: 1413 stations loaded"

### ✓ Test 3: Filter by Typing
1. **Type:** "Manila"
2. **Expected:** Only Manila stations shown
3. **Check:** "Manila" highlighted in yellow
4. **Check:** Shows count "Showing X matching stations"

### ✓ Test 4: Search by Location
1. **Type:** "valencia"
2. **Expected:** Stations with "valencia" in name OR location
3. **Check:** Matching text highlighted yellow

### ✓ Test 5: Search by Region
1. **Type:** "NCR"
2. **Expected:** All stations in NCR region
3. **Check:** "Region: NCR" highlighted yellow

### ✓ Test 6: Select Station
1. **Type:** "Station 1"
2. **Click any station** from dropdown
3. **Expected:**
   - Input shows selected station name
   - Dropdown closes
   - Status bar shows "Selected: [Station Name] | Region: [Region]"
   - Module table appears below with 8 modules

### ✓ Test 7: Toggle Modules
1. After selecting station, find the module table
2. **Click toggle switch** for any module
3. **Expected:** Toast message at top: "Module updated successfully"
4. **Check:** Status badge changes Enabled ↔ Disabled

---

## 🎯 VISUAL CONFIRMATION

### What You Should See:

```
┌─────────────────────────────────────────────────────┐
│ 🔍 Search Station                                   │
│ ┌───────────────────────────────────────────────┐   │
│ │ Type station name or address...        🔍  ▼ │   │
│ └───────────────────────────────────────────────┘   │
│                                                     │
│ When you click input or arrow (▼):                 │
│ ┌───────────────────────────────────────────────┐   │
│ │ Petron Station 1                              │   │
│ │ Manila Branch 1 | Region: NCR                 │   │
│ ├───────────────────────────────────────────────┤   │
│ │ Petron Station 2                              │   │
│ │ Quezon City Branch 2 | Region: Luzon          │   │
│ ├───────────────────────────────────────────────┤   │
│ │ ... (all 1413 stations)                       │   │
│ └───────────────────────────────────────────────┘   │
│                                                     │
│ When you type "Manila":                            │
│ ┌───────────────────────────────────────────────┐   │
│ │ Petron Station Manila                         │   │
│ │ Manila Branch 1 | Region: NCR                 │ ← "Manila" in yellow
│ ├───────────────────────────────────────────────┤   │
│ │ Showing 15 matching stations                  │   │
│ └───────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────┘
```

---

## 🔍 CHECK BROWSER CONSOLE

### Open Console (F12)
You should see:
```javascript
✅ Loaded 1413 stations
```

### If You See Errors:
1. **Error: "Failed to load stations"**
   - Check if API endpoint exists: `backend/api/module_config_api.php`
   - Check database connection

2. **Error: "allStations is not defined"**
   - Hard refresh: Ctrl+Shift+R (clear cache)
   - Check JavaScript loaded properly

3. **No console message**
   - Wait 2 seconds for API call to complete
   - Check Network tab for API response

---

## 🐛 TROUBLESHOOTING

### Input Not Accepting Text
**Problem:** Cannot type in search box  
**Check:**
1. Is it a `<input type="text">` element?
2. Does it have `id="stationSearchInput"`?
3. Is there `readonly` or `disabled` attribute?

**Fix:** Inspect element (Right-click → Inspect)

---

### Dropdown Not Showing
**Problem:** Click input but no dropdown appears  
**Check:**
1. Console for JavaScript errors
2. Network tab for API call completion
3. Element has `id="stationDropdown"`

**Fix:** Hard refresh page (Ctrl+Shift+R)

---

### Filtering Not Working
**Problem:** Type text but stations not filtered  
**Check:**
1. Console shows "Loaded 1413 stations"
2. `input` event listener attached
3. `allStations` array has data

**Fix:** Check `allStations` in console:
```javascript
// Paste in console:
console.log(allStations.length); // Should be 1413
console.log(allStations[0]);     // Should show station object
```

---

### No Highlighting
**Problem:** Matching text not highlighted yellow  
**Check:**
1. `highlightMatch()` function exists
2. No CSS overriding background color
3. Text actually matches search query

**Fix:** Check console for regex errors

---

## 📸 SCREENSHOT LOCATIONS

### Before Typing
- Input box is empty
- No dropdown visible
- Clean interface

### After Clicking Input
- Dropdown appears
- Shows all 1413 stations
- Scroll bar visible (if needed)

### After Typing "Manila"
- Filtered list appears
- "Manila" text has yellow background
- Count shows "Showing X matching stations"

### After Selecting Station
- Input shows station name
- Dropdown closes
- Status bar shows selection
- Module table appears with 8 rows

---

## ✨ SUCCESS CRITERIA

All these should work:
- [x] Can click input box
- [x] Can type text in input
- [x] Dropdown appears when clicking
- [x] Shows all 1413 stations initially
- [x] Filters as you type
- [x] Highlights matching text in yellow
- [x] Can select a station by clicking
- [x] Modules load after selection
- [x] Toggle switches work
- [x] Toast messages appear at top

---

## 🎉 IF ALL TESTS PASS

**CONGRATULATIONS!** The searchable station dropdown is fully functional!

Next steps:
1. Test cascade functionality (disable module → check sidebar)
2. Test module configuration (click Configure button)
3. Deploy to production environment

---

## 📞 NEED HELP?

### Check These Files:
1. `public/module_configuration.php` - Main page with dropdown
2. `backend/api/module_config_api.php` - API endpoint for stations
3. `MODULE_CONFIG_SEARCHABLE_FINAL_STATUS.md` - Full documentation

### Test the Dropdown Independently:
Open this test file in browser:
```
http://localhost/group31petron_system_official4/test_station_dropdown.html
```

This test file has:
- Standalone dropdown (no database needed)
- Mock 1413 stations
- Same functionality as main page
- Easier to debug

---

## 🔧 DEVELOPER TOOLS

### Check API Response
```
http://localhost/group31petron_system_official4/backend/api/module_config_api.php?action=get_stations
```

Should return:
```json
{
  "ok": true,
  "stations": [
    {
      "id": 1,
      "name": "Petron Station 1",
      "location": "Manila Branch",
      "region": "NCR"
    },
    ... (1413 total)
  ]
}
```

### Check Console Commands
```javascript
// Check stations loaded
console.log(allStations.length);

// Check first station
console.log(allStations[0]);

// Test filter function
const filtered = allStations.filter(s => s.name.includes('Manila'));
console.log(filtered.length);

// Check dropdown element
console.log(document.getElementById('stationDropdown'));
```

---

## ⏱️ ESTIMATED TEST TIME

- **Basic functionality:** 2 minutes
- **All features:** 5 minutes
- **Full testing + screenshots:** 10 minutes

**TOTAL TIME:** 5-10 minutes

---

**Last Updated:** June 14, 2026  
**Status:** ✅ Ready for Testing
