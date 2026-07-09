# Badge System Quick Reference Guide

## For Developers: Adding New Badges

### Step 1: Add Badge Calculation Logic
**File**: `partials/header.php` (lines 2815-3125)

```php
// Example: Add a new badge for staff "pending approvals"
if ($role === 'staff' && $myStationId) {
    $__k = 'staff_pending_approvals'; // Unique badge key
    $__n = $__badge_count(
        "SELECT COUNT(*) FROM approvals
         WHERE station_id=? AND status='Pending' AND created_at > ?",
        [$myStationId, $__badge_since($__k)]
    );
    if ($__n > 0) {
        $fuel_sub_badges['staff_pending_approvals'] = $__n;
    }
}
```

**Key Variables:**
- `$fuel_sub_badges`: Array for sub-item badges (keyed by sub-item ID)
- `$badges`: Array for top-level item badges (keyed by parent item ID)
- `$__badge_count`: Helper function that safely executes COUNT queries
- `$__badge_since`: Helper function that returns last-seen timestamp

### Step 2: Map Badge Key to Sub-Item ID
**File**: `partials/rbac_menu.php` (menu definition)

Ensure your sub-item has a unique ID that matches your badge key:

```php
[
    'id' => 'staff_pending_approvals',  // Must match badge key
    'label' => 'Pending Approvals',
    'href' => 'staff_approvals.php',
    'permissions' => ['view_approvals'],
    'desc' => 'View and track pending approval requests.'
]
```

### Step 3: Add Badge Auto-Clear Mapping
**File**: `partials/header.php` (lines 3365-3395)

```php
$badge_page_map = [
    // ... existing mappings ...
    'staff_approvals' => ['staff_pending_approvals'],  // page_id => [badge_keys]
];
```

### Step 4: Test
1. Create test data with `status='Pending'`
2. Log in as staff user
3. Verify badge appears in sidebar
4. Click the navigation item
5. Verify badge disappears immediately
6. Wait 800ms, check `user_preferences` table for `badge_seen_staff_pending_approvals`
7. Reload page, verify badge stays gone
8. Add new pending item, verify badge reappears

---

## Badge Key Naming Conventions

### Format: `{role}_{module}_{action?}`

**Examples:**
- `staff_fuel_del_history` - Staff > Fuel Management > Deliveries History
- `mgr_stock_review` - Manager > Inventory > Purchase Request
- `admin_purchase_orders` - Admin > Inventory > Purchase Orders Oversight

**Rules:**
1. Use lowercase
2. Use underscores, no hyphens or spaces
3. Alphanumeric only (no special chars)
4. Maximum 60 characters
5. Must match navigation sub-item ID exactly

---

## Common Badge Queries

### Pending Items Since Last Visit
```php
$__k = 'badge_key_name';
$__n = $__badge_count(
    "SELECT COUNT(*) FROM table_name
     WHERE station_id=? AND status='Pending' AND created_at > ?",
    [$myStationId, $__badge_since($__k)]
);
if ($__n > 0) { $fuel_sub_badges['badge_key_name'] = $__n; }
```

### ALL Pending Items (Persistent Badge)
```php
$__n = $__badge_count(
    "SELECT COUNT(*) FROM table_name
     WHERE station_id=? AND status='Pending'",
    [$myStationId]
);
if ($__n > 0) { $fuel_sub_badges['badge_key_name'] = $__n; }
```

### Multiple Status Conditions
```php
$__k = 'badge_key_name';
$__n = $__badge_count(
    "SELECT COUNT(*) FROM table_name
     WHERE station_id=? 
     AND status IN ('Pending','Pending Review','Submitted')
     AND created_at > ?",
    [$myStationId, $__badge_since($__k)]
);
if ($__n > 0) { $fuel_sub_badges['badge_key_name'] = $__n; }
```

### Combining Multiple Tables
```php
$__sr = $__badge_count(
    "SELECT COUNT(*) FROM stock_requests WHERE station_id=? AND status='Pending'",
    [$myStationId]
);
$__fsr = $__badge_count(
    "SELECT COUNT(*) FROM fuel_stock_requests WHERE station_id=? AND status='Pending'",
    [$myStationId]
);
$__total = $__sr + $__fsr;
if ($__total > 0) { $fuel_sub_badges['mgr_stock_review'] = $__total; }
```

---

## Troubleshooting

### Badge Not Appearing

**Check 1: Badge Calculation**
```php
// Add debug output temporarily
if ($role === 'staff' && $myStationId) {
    error_log("Station ID: " . $myStationId);
    error_log("Badge count: " . $__n);
}
```

**Check 2: Sub-Item ID Match**
- Verify `$fuel_sub_badges` key matches sub-item `id` in `rbac_menu.php`
- Case-sensitive match required

**Check 3: User Permissions**
- User must have permission to see the navigation item
- Badge won't show if navigation item is hidden

**Check 4: Database Query**
- Test query directly in phpMyAdmin
- Verify table/column names are correct
- Check station_id filter is working

### Badge Not Disappearing

**Check 1: Page Mapping**
```php
// Verify page_id is in $badge_page_map
error_log("Current page_id: " . $page_id);
error_log("Mapped badges: " . print_r($badge_page_map[$page_id] ?? [], true));
```

**Check 2: API Endpoint**
- Check browser console for fetch errors
- Verify `badge_seen.php` is accessible
- Check API response: should be `{"ok":true,...}`

**Check 3: Database Update**
```sql
-- Check if preference was saved
SELECT * FROM user_preferences 
WHERE user_id=1 AND preference_key='badge_seen_badge_key_name';
```

**Check 4: JavaScript Timing**
- 800ms delay might be too short on slow connections
- Increase delay in `header.php` (line ~3404)

### Badge Count Wrong

**Check 1: Query Filters**
- Verify `station_id` filter if station-specific
- Check `created_at > ?` timestamp filter
- Verify status conditions match database values

**Check 2: Last Seen Timestamp**
```php
// Debug last-seen value
error_log("Last seen for badge_key: " . $__badge_since('badge_key'));
```

**Check 3: Multiple Badge Keys**
- Verify not accidentally creating duplicate badges
- Check both `$fuel_sub_badges` and `$badges` arrays

---

## Badge System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Page Load (header.php)                                     │
│  ├─ Load user_preferences (badge_seen_* timestamps)         │
│  ├─ Calculate badge counts (queries with timestamp filter)  │
│  └─ Store in $fuel_sub_badges / $badges arrays              │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│  Sidebar Rendering (header.php lines 3230-3350)             │
│  ├─ Loop through navigation items                           │
│  ├─ Check if badge exists in array                          │
│  ├─ Render badge pill HTML                                  │
│  └─ Calculate parent badge = sum of sub-badges              │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│  User Clicks Navigation Item                                │
│  ├─ JavaScript: Remove badge pill immediately (visual)      │
│  ├─ Navigate to page                                        │
│  └─ Page loads with page_id                                 │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│  Auto-Mark Badge as Seen (header.php lines 3395-3404)       │
│  ├─ Check if page_id in $badge_page_map                     │
│  ├─ Wait 800ms                                              │
│  ├─ Fetch POST to badge_seen.php API                        │
│  └─ API: UPDATE user_preferences SET value=NOW()            │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│  Next Page Load                                              │
│  ├─ Query: WHERE created_at > last_seen_timestamp           │
│  ├─ Badge count = 0 (no new items)                          │
│  └─ Badge not rendered                                       │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│  New Item Created (e.g., staff submits transaction)         │
│  ├─ INSERT INTO table (..., created_at=NOW())               │
│  └─ created_at > last_seen_timestamp = TRUE                 │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│  Next Page Load                                              │
│  ├─ Query: WHERE created_at > last_seen_timestamp           │
│  ├─ Badge count = 1 (new item found)                        │
│  └─ Badge rendered: [1]                                      │
└─────────────────────────────────────────────────────────────┘
```

---

## Database Schema Reference

### user_preferences Table
```sql
CREATE TABLE user_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_pref (user_id, preference_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Sample Data
```sql
-- User ID 5 (staff) has seen stock requests module
INSERT INTO user_preferences 
(user_id, preference_key, preference_value) 
VALUES 
(5, 'badge_seen_inv_stock_request', '2026-07-09 10:30:00');

-- Query to check all badge preferences for a user
SELECT * FROM user_preferences 
WHERE user_id=5 AND preference_key LIKE 'badge_seen_%'
ORDER BY updated_at DESC;
```

---

## CSS Customization

### Changing Badge Color
```php
// In header.php, replace #E30613 with your color
echo '<span data-badge style="background:#YOUR_COLOR;...
```

### Changing Badge Size
```php
// Parent/Regular: font-size:11px; height:20px; min-width:20px;
// Sub-item: font-size:10px; height:18px; min-width:18px;
```

### Adding Pulse Animation
```php
echo '<span data-badge style="...;animation:pulse 2s infinite;">'.$badge.'</span>';
```

```css
@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}
```

---

## Best Practices

### DO:
✅ Use meaningful badge keys that match navigation IDs
✅ Filter by station_id for station-specific roles
✅ Use prepared statements with placeholders
✅ Test with multiple users and stations
✅ Document new badges in this guide
✅ Use `created_at > last_seen` for most badges
✅ Use persistent badges (no timestamp filter) for critical approvals

### DON'T:
❌ Hard-code user IDs or station IDs
❌ Use raw SQL concatenation (security risk)
❌ Create badges without corresponding navigation items
❌ Forget to add page-to-badge mapping
❌ Use overly complex queries (performance)
❌ Show badges to users without proper permissions
❌ Create duplicate badge keys

---

## Performance Tips

1. **Index Database Columns**
```sql
CREATE INDEX idx_created_at ON table_name(created_at);
CREATE INDEX idx_station_status ON table_name(station_id, status);
```

2. **Cache Badge Counts** (for high-traffic sites)
```php
$cache_key = "badges_user_{$user_id}_station_{$myStationId}";
$badges = apcu_fetch($cache_key);
if ($badges === false) {
    // Calculate badges...
    apcu_store($cache_key, $badges, 60); // Cache for 60 seconds
}
```

3. **Batch Badge Queries** (combine multiple counts)
```sql
SELECT 
    SUM(CASE WHEN type='fuel' THEN 1 ELSE 0 END) as fuel_count,
    SUM(CASE WHEN type='merch' THEN 1 ELSE 0 END) as merch_count
FROM deliveries 
WHERE station_id=? AND status='Pending';
```

---

## Testing Checklist for New Badges

- [ ] Badge appears when conditions are met
- [ ] Badge shows correct count
- [ ] Badge appears for correct role(s)
- [ ] Badge respects station_id filter
- [ ] Badge disappears immediately on click
- [ ] API endpoint updates database (check user_preferences)
- [ ] Badge stays gone after page reload
- [ ] Badge reappears when new items added
- [ ] Parent badge = sum of sub-badges
- [ ] Badge doesn't appear for users without permission
- [ ] SQL query uses prepared statements
- [ ] Performance is acceptable (<100ms query time)

---

## Contact & Support

For questions about the badge system:
1. Check this guide first
2. Review `SIDEBAR_BADGE_SYSTEM.md` for detailed documentation
3. Check `BADGE_VISUAL_EXAMPLES.md` for visual references
4. Review existing badge implementations in `header.php`
5. Test changes in development environment before production

**Key Files:**
- `partials/header.php` - Badge calculation & rendering
- `partials/rbac_menu.php` - Navigation structure
- `backend/api/badge_seen.php` - Badge clear API
- `public/db_connect.php` - Database connection
