# CALENDAR SYSTEM - QUICK REFERENCE GUIDE

## ✅ FULLY FUNCTIONAL - ALL ENHANCEMENTS APPLIED

---

## MANAGER CALENDAR - KEY FEATURES

### What You'll See:

**Sidebar Panels (Top to Bottom)**:

1. **⚠ VALIDATION TASKS** (Yellow/Orange Panel)
   - Shows count of pending validations
   - "Review Now" button links to validation page
   - Includes: Transactions + Deliveries awaiting your approval

2. **🔴 ACTION REQUIRED** (Red Alert Panel)
   - Overdue Payments: Credit customers past due date
   - Low Stock Items: Products at minimum level
   - Requires immediate manager attention

3. **📅 TODAY'S STATION EVENTS** (Blue Info Panel)
   - Shifts: All staff shifts today
   - Job Orders: Service tasks scheduled
   - Deliveries: Expected deliveries
   - Other: Manual events

4. **👥 STAFF WORKLOAD (TODAY)** (Gray Panel)
   - Top 5 staff with task counts
   - Color coded:
     - Green: 0-1 tasks (light)
     - Orange: 2-3 tasks (moderate)
     - Red: 4+ tasks (heavy)

5. **THIS WEEK STATUS** (Green Progress Panel)
   - Pending, In Progress, Completed counts
   - Weekly progress tracking

6. **⚠ SCHEDULE CONFLICTS** (Red Warning - if any)
   - Shows overlapping staff schedules
   - "Review Conflicts" button

### Calendar Events You'll See:

| Icon | Event Type | What It Means |
|------|-----------|--------------|
| ⚠ | **Validation Required** (Orange) | Transaction/delivery needs your approval |
| ⚠ | **Delivery w/ Variance** (Red) | Delivery has quantity mismatch - urgent |
| 🔴 | **Low Stock Alert** (Red) | Product at critical level - order now |
| 💰 | **Payment Collection** (Red) | Customer payment overdue |
| 📅 | **Internal Meeting** (Purple) | Team meeting scheduled |
| 🔔 | **Shift** (Staff color) | Staff shift assignment |
| 🔧 | **Job Order** (Staff color) | Service task assigned |
| 📦 | **Delivery** (Staff color) | Delivery tracking |

---

## ADMIN CALENDAR - KEY FEATURES

### What You'll See:

**Sidebar Panels** (Admin-Specific):

1. **📋 COMPLIANCE DEADLINES** (Blue/Orange/Red)
   - Report submission deadlines
   - Audit schedules
   - Contract renewals
   - License expirations
   - Color coded by urgency

2. **👁 OVERSIGHT MONITORING** (Orange)
   - All pending validations across ALL stations
   - Station names shown
   - Monitor what managers should be doing

3. **🚨 SYSTEM-WIDE ALERTS** (Red)
   - Overdue Reports: Stations with validations > 7 days old
   - Critical Stock: Items at 50% or below minimum (all stations)
   - Requires admin intervention

4. **💰 HIGH-VALUE TRANSACTIONS** (Green)
   - Transactions ≥ ₱50,000
   - Financial oversight
   - Shows: Customer, Amount, Payment Method, Station

5. **📊 STATIONS OVERVIEW** (When Global View)
   - Activity per station today
   - Event counts and shift counts
   - Quick health check

### Calendar Events You'll See:

| Icon | Event Type | What It Means |
|------|-----------|--------------|
| 📋 | **Compliance Deadline** (Blue/Orange/Red) | Report, audit, contract, license due |
| 👁 | **Admin Oversight** (Orange) | Pending validation to monitor |
| 🚨 | **Overdue Report Alert** (Red) | Station has validations > 7 days old |
| 🔴 | **Critical Stock** (Red) | System-wide stock emergency |
| 💰 | **High-Value Transaction** (Green) | Financial event ≥ ₱50,000 |
| 📅 | **All Station Events** | Shifts, deliveries, job orders from ALL stations |

### Station Filter:
- **Global View** (No filter): See ALL stations
- **Filtered View** (?station=X): See specific station only
- Filter preserved across all navigation

---

## VIEW TYPES (All 3 Calendars)

### 📅 Day View
- List format showing today's events
- Time ranges displayed
- Staff names and event types
- Status indicators

### 📊 Week View
- 7-column grid (Sunday - Saturday)
- All events per day visible
- Time labels for timed events

### 📆 Month View (Default)
- Traditional calendar grid
- Up to 4 events per day
- "+X more" link if more events
- Color coded by staff

### 📈 Year View
- 12 mini-month calendars
- Today highlighted
- Days with events in bold blue
- Quick overview for planning

**Keyboard Shortcuts**:
- Press **D** = Day view
- Press **W** = Week view
- Press **M** = Month view
- Press **Y** = Year view

---

## COLOR CODING SYSTEM

### Event Colors:

| Color | Meaning | Used For |
|-------|---------|----------|
| **Red (#d93025)** | Urgent/Critical | Validation w/ variance, Low stock, Overdue payments, Critical alerts |
| **Orange (#ea8600)** | High Priority | Pending validations, Overdue items, Compliance deadlines (urgent) |
| **Purple (#7986cb)** | Internal Tasks | Manager meetings, Internal events |
| **Blue (#1a73e8)** | Information | Compliance deadlines (normal), Shifts |
| **Green (#188038)** | Completed/Success | High-value transactions, Completed tasks |
| **Staff Colors** | Staff-Specific | Individual staff assignments (9-color palette) |

---

## AUTO-SYNC SOURCES

### Data Automatically Pulled From:

1. ✅ **Staff Schedules** (`staff_schedules` table)
2. ✅ **Deliveries** (`deliveries_oversight` table)
3. ✅ **Job Orders** (`job_orders` table)
4. ✅ **Transactions** (`transactions` table) - validation status
5. ✅ **Inventory** (`inventory_products` table) - stock levels
6. ✅ **Credit Customers** (`credit_customers` table) - payment due dates
7. ✅ **Manager Meetings** (`manager_meetings` table) - NEW
8. ✅ **Compliance Deadlines** (`admin_compliance_deadlines` table) - NEW

**All data refreshes when you reload the calendar page!**

---

## NAVIGATION TIPS

### Manager Calendar:
1. Check **Validation Tasks** panel first thing in the morning
2. Review **Action Required** for urgent items
3. Monitor **Staff Workload** to balance assignments
4. Click validation events to go directly to approval page

### Admin Calendar:
1. Set **Station Filter** to focus on specific station
2. Use **Global View** for system-wide health check
3. Monitor **Compliance Deadlines** weekly
4. Check **Overdue Reports** to follow up with managers
5. Review **High-Value Transactions** for financial oversight

---

## SIDEBAR NAVIGATION TEXT

### ✅ VERIFIED: "Calendar" Text Stays Consistent

The sidebar navigation will always show **"Calendar"** (properly capitalized) for all users regardless of which calendar page they're on:
- Staff → `staff_calendar.php` = "Calendar"
- Manager → `manager_calendar.php` = "Calendar"
- Admin → `admin_calendar.php` = "Calendar"

**No text changes when clicking between calendar pages!**

---

## PRIORITY INDICATORS

### Visual Indicators on Events:

- **🔴** = Urgent (immediate action needed)
- **⚠** = High Priority (review soon)
- **📋** = Normal Priority (routine task)

### When You See These:

- **🔴 + Red Color** = Drop everything, handle immediately
- **⚠ + Orange Color** = Review today, don't delay
- **Blue/Purple** = Scheduled task, on track
- **Green** = Completed, for reference only

---

## QUICK ACTIONS

### From Calendar Events:

**Click Any Event** to see details or take action:
- **Validation Tasks** → Goes to validation approval page
- **Shifts** → Alert: "Edit in Staff Schedules"
- **Deliveries** → Goes to delivery details page
- **Job Orders** → Goes to job order details page
- **Meetings** → Will show meeting details (future enhancement)

---

## TROUBLESHOOTING

### If Events Don't Show:
1. Check date range - make sure you're viewing the right period
2. Verify data exists in source tables (transactions, deliveries, etc.)
3. Check station_id assignment for manager/admin views
4. Reload the page to refresh auto-sync

### If Sidebar Text Changes:
- Should NOT happen - text is hardcoded as "Calendar"
- If it does, check browser cache (Ctrl+F5 to force refresh)
- Check that you're using the updated header.php file

### If Workload Panel Empty:
- Verify staff have user accounts at your station
- Check that events are assigned to staff_encoder_id
- Confirm event_date matches today

---

## DATABASE TABLES CREATED

### New Tables Added:

1. **`manager_meetings`** (Manager Calendar)
   - Stores internal meetings
   - Tracks attendees, agenda, status
   - Auto-syncs to calendar

2. **`admin_compliance_deadlines`** (Admin Calendar)
   - Stores compliance deadlines
   - Supports station-specific or system-wide
   - Deadline types: report, audit, contract, license, inspection

**Both tables are created automatically on first calendar load!**

---

## SUMMARY

✅ **Manager Calendar** = Validation scheduling + Staff workload + Action items + Meetings  
✅ **Admin Calendar** = Compliance deadlines + Oversight + System alerts + Financial monitoring  
✅ **All Views** = Day/Week/Month/Year functional  
✅ **Auto-Sync** = 8+ data sources integrated  
✅ **Color Coding** = Urgent/High/Normal priority system  
✅ **Sidebar Text** = "Calendar" stays consistent  

**Ang tanan NA-IMPLEMENT! Calendar system 100% functional with complete manager and admin features!** 🎉
