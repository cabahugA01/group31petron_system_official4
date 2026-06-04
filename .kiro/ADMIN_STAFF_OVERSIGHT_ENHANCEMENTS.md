# Admin Staff Oversight Module - Enhancements

**Date:** June 4, 2026  
**Status:** Implementation Ready  
**Priority:** HIGH

---

## ✅ Current Implementation (Existing Features)

### 1. **Manager + Staff Accounts View** ✅
- Consolidated view of all Manager and Staff accounts
- Shows status (Active, Inactive)
- Activity summary (requests, deliveries)

### 2. **Recent Activity** ✅
- Last login per account
- Last transaction (encoded/validated)

### 3. **Actions** ✅
- Edit user details
- Deactivate/Activate accounts
- Add/edit remarks

---

## 🚀 Required Enhancements

### 1. **Summary Cards (Dashboard Top)** 
**Status:** TO IMPLEMENT

**Cards Needed:**
```
┌─────────────────────────┐  ┌─────────────────────────┐  ┌─────────────────────────┐
│ 👥 Total Active         │  │ 🚫 Inactive/Suspended   │  │ 📊 Recent Encoders      │
│    Accounts             │  │    Accounts             │  │    (Last 7 Days)        │
│    Manager + Staff      │  │    Count                │  │    Count                │
└─────────────────────────┘  └─────────────────────────┘  └─────────────────────────┘

┌─────────────────────────┐  ┌─────────────────────────┐  ┌─────────────────────────┐
│ 🏆 Top Staff by         │  │ ⏳ Pending Requests     │  │ 🚚 Deliveries Count     │
│    Requests Encoded     │  │    Count                │  │    Summary              │
│    (Last 7 Days)        │  │                         │  │                         │
└─────────────────────────┘  └─────────────────────────┘  └─────────────────────────┘
```

**Implementation:**
- Add summary section above table
- Query database for metrics
- Display in card format with icons
- Color coded (blue/green/red/orange)

---

### 2. **Add "Suspended" Status**
**Status:** TO IMPLEMENT

**Current:** Active, Inactive  
**Required:** Active, Inactive, **Suspended**

**Changes:**
- Update badge colors (Suspended = Red/Danger)
- Add Suspended option to edit modal
- Differentiate in summary cards

---

### 3. **Enhanced Activity Tracking**
**Status:** TO ENHANCE

**Required Metrics:**
- Total Requests Encoded (last 7 days) ✅ (already counting all time, need to filter by date)
- Total Deliveries Encoded (last 7 days) ✅ (already counting all time, need to filter by date)
- Last validation performed ✅
- Workload distribution

**Changes:**
- Add date filter to queries (last 7 days)
- Add column headers to clarify time period
- Show trend indicators (up/down arrows)

---

### 4. **Top Encoders Leaderboard**
**Status:** TO IMPLEMENT

**Display in Summary Card:**
```
🏆 Top Staff by Requests Encoded (Last 7 Days)
1. Juan Dela Cruz - 45 requests
2. Maria Santos - 38 requests
3. Pedro Garcia - 32 requests
```

**Implementation:**
- Query top 3-5 staff by request count (last 7 days)
- Display in dedicated card
- Show count + rank
- Link to detailed view (optional)

---

### 5. **Export Functionality**
**Status:** TO ENHANCE

**Current:** Basic Excel export ✅  
**Required:** Excel, CSV, PDF

**Export Columns:**
- Account ID
- Name
- Role
- Station
- Status
- Last Login
- Last Transaction
- Requests Encoded (Last 7 Days)
- Deliveries Encoded (Last 7 Days)
- Remarks

**Implementation:**
- Add Export dropdown button
- Excel (existing - enhance with new columns)
- CSV (new - comma-separated format)
- PDF (new - formatted report with logo)

---

## 📊 Database Queries Needed

### Summary Cards Queries:

#### 1. Total Active Accounts
```sql
SELECT COUNT(*) 
FROM users 
WHERE role IN ('staff', 'manager') 
  AND status = 'active' 
  AND is_deleted = 0
  AND station_id = ?  -- if admin
```

#### 2. Inactive/Suspended Accounts
```sql
SELECT COUNT(*) 
FROM users 
WHERE role IN ('staff', 'manager') 
  AND status IN ('inactive', 'suspended')
  AND is_deleted = 0
  AND station_id = ?  -- if admin
```

#### 3. Recent Encoders (Last 7 Days)
```sql
SELECT COUNT(DISTINCT user_id) 
FROM activity_logs 
WHERE action LIKE '%Encod%' 
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
  AND user_id IN (SELECT id FROM users WHERE station_id = ?)  -- if admin
```

#### 4. Top Staff by Requests (Last 7 Days)
```sql
SELECT u.name, COUNT(sr.id) as request_count
FROM users u
LEFT JOIN stock_requests sr ON sr.staff_id = u.id 
  AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
WHERE u.role IN ('staff', 'manager')
  AND u.is_deleted = 0
  AND u.station_id = ?  -- if admin
GROUP BY u.id
ORDER BY request_count DESC
LIMIT 5
```

#### 5. Pending Requests Count
```sql
SELECT COUNT(*) 
FROM stock_requests 
WHERE status = 'Pending'
  AND station_id = ?  -- if admin
```

#### 6. Deliveries Count (Last 7 Days)
```sql
SELECT COUNT(*) 
FROM fuel_deliveries 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
  AND station_id = ?  -- if admin
```

---

## 🎨 UI Layout Enhancement

### Before (Current):
```
[ Title ] [ Refresh Button ]
[ Table ]
```

### After (Enhanced):
```
[ Title ] [ Export Dropdown ▼ ] [ Refresh Button ]

┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│ Summary     │ │ Summary     │ │ Summary     │
│ Card 1      │ │ Card 2      │ │ Card 3      │
└─────────────┘ └─────────────┘ └─────────────┘

┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│ Summary     │ │ Summary     │ │ Summary     │
│ Card 4      │ │ Card 5      │ │ Card 6      │
└─────────────┘ └─────────────┘ └─────────────┘

[ Table with enhanced columns ]
```

---

## ✅ Implementation Checklist

### Phase 1: Summary Cards (Priority 1)
- [ ] Add summary cards section to HTML
- [ ] Create API endpoint for summary metrics
- [ ] Query database for all 6 metrics
- [ ] Display cards with icons and colors
- [ ] Make cards responsive

### Phase 2: Enhanced Activity Tracking (Priority 2)
- [ ] Update queries to filter by last 7 days
- [ ] Add date range indicator to table header
- [ ] Show trend indicators (optional)
- [ ] Update activity summary display

### Phase 3: Export Enhancements (Priority 3)
- [ ] Add Export dropdown button
- [ ] Implement CSV export function
- [ ] Implement PDF export function (using jsPDF or server-side)
- [ ] Test all export formats

### Phase 4: Top Encoders Leaderboard (Priority 4)
- [ ] Query top 5 staff by requests (last 7 days)
- [ ] Display in dedicated summary card
- [ ] Add visual ranking (medals/numbers)
- [ ] Link to detailed view (optional)

### Phase 5: "Suspended" Status (Priority 5)
- [ ] Update database enum to include 'suspended'
- [ ] Add to edit modal dropdown
- [ ] Update badge colors
- [ ] Update summary card count

---

## 🧪 Testing Requirements

### Test Cases:
1. ✅ Summary cards display correct counts
2. ✅ Last 7 days filter works correctly
3. ✅ Top encoders shows accurate rankings
4. ✅ Export to Excel works
5. ✅ Export to CSV works
6. ✅ Export to PDF works
7. ✅ Suspended status works
8. ✅ Admin only sees own station data
9. ✅ Superadmin sees all stations
10. ✅ Cards update on refresh

---

## 🚀 Deployment Notes

**Database Changes:**
```sql
-- Add 'suspended' to status enum (if not exists)
ALTER TABLE users 
MODIFY COLUMN status ENUM('active', 'inactive', 'suspended') DEFAULT 'active';
```

**Files to Update:**
1. `public/admin_staff_oversight.php` - Add summary cards section
2. `backend/api/admin_staff_oversight_api.php` - Add summary metrics endpoint
3. CSS - Add summary card styles

**No Breaking Changes:** ✅ Backwards compatible

---

**Status:** Ready for Implementation  
**Estimated Time:** 3-4 hours  
**Priority:** HIGH
