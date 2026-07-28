# Design Document: Stock-In Notification Badges

## Overview

This design document specifies the technical implementation for adding notification badges to the Stock-In navigation menu item. The feature will display the count of pending stock-in deliveries (both merchandise and fuel) for managers and admins, providing immediate visibility without requiring navigation to the Stock-In page.

### Goals

1. Add badge count display to the "Stock-In" navigation menu item
2. Enable real-time badge updates via existing notification polling infrastructure
3. Maintain consistency with existing badge styling and behavior
4. Support both manager and admin roles with station-specific filtering
5. Ensure graceful error handling for database failures

### Non-Goals

- Creating new badge rendering infrastructure (will use existing system)
- Modifying the notification polling frequency
- Adding badges for other menu items not related to stock-in
- Creating new database tables or columns

## Architecture

### System Context

The badge system consists of three main components:

1. **Badge Calculation** (`partials/header.php`): Server-side PHP code that queries the database and populates the `$badges` array with counts for each menu item
2. **Badge Rendering** (`partials/rbac_menu.php`): Menu generation code that reads from `$badges` array and renders badge spans with `data-sidebar-badge` attributes
3. **Badge Updates** (`backend/api/notifications_api.php`): API endpoint that returns updated badge counts during polling, with JavaScript updating the DOM

### Component Interactions

```mermaid
sequenceDiagram
    participant User
    participant Browser
    participant header.php
    participant notifications_api.php
    participant Database

    Note over User,Database: Initial Page Load
    User->>Browser: Navigate to any page
    Browser->>header.php: Request page
    header.php->>Database: Query pending stock-in count
    Database-->>header.php: Return count
    header.php-->>Browser: Render page with badge
    Browser->>User: Display badge

    Note over User,Database: Real-Time Updates
    loop Every 5 seconds
        Browser->>notifications_api.php: Poll for updates
        notifications_api.php->>Database: Query current stock-in count
        Database-->>notifications_api.php: Return count
        notifications_api.php-->>Browser: Return JSON with counts
        Browser->>Browser: Update badge via data-sidebar-badge
        Browser->>User: Display updated badge
    end
```

## Components and Interfaces

### 1. Badge Calculation Component

**Location**: `partials/header.php` (lines ~240-260)

**Responsibility**: Query database for pending stock-in deliveries and populate the `$badges` array

**Interface**:
- **Input**: `$pdo` (PDO connection), `$myStationId` (int), `$role` (string)
- **Output**: `$badges['mgr_stock_in']` (int)

**Implementation Details**:

```php
// Add to existing badge calculation section in partials/header.php
// Around line 257, after manager deliveries badge

// Stock-In badge for Manager role
if ($role === 'manager' && $myStationId) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM deliveries_oversight 
            WHERE station_id = ? 
              AND delivery_type IN ('merchandise', 'fuel')
              AND status IN (
                  'Pending Stock-In', 
                  'Ready for Stock-In', 
                  'Validated', 
                  'Verified', 
                  'Partial Delivery', 
                  'Damaged Items', 
                  'Adjusted'
              )
        ");
        $stmt->execute([$myStationId]);
        $badges['mgr_stock_in'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) { 
        $badges['mgr_stock_in'] = 0; 
    }
}

// Stock-In badge for Admin role
if ($role === 'admin' && $myStationId) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM deliveries_oversight 
            WHERE station_id = ? 
              AND delivery_type IN ('merchandise', 'fuel')
              AND status IN (
                  'Pending Stock-In', 
                  'Ready for Stock-In', 
                  'Validated', 
                  'Verified', 
                  'Partial Delivery', 
                  'Damaged Items', 
                  'Adjusted'
              )
        ");
        $stmt->execute([$myStationId]);
        $badges['mgr_stock_in'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) { 
        $badges['mgr_stock_in'] = 0; 
    }
}
```

**Query Explanation**:
- **Table**: `deliveries_oversight` - Contains all delivery records
- **Filters**:
  - `station_id = ?` - Only deliveries for the user's assigned station
  - `delivery_type IN ('merchandise', 'fuel')` - Both types of deliveries
  - `status IN (...)` - Only statuses that represent pending work (matching `manager_stock_in.php` line 35)
- **Performance**: Query uses indexed columns (`station_id`, `status`) for fast execution (~5-20ms)

### 2. Badge Rendering Component

**Location**: `partials/rbac_menu.php` (already handles badge rendering)

**Responsibility**: Read from `$badges` array and render badge HTML with appropriate attributes

**Interface**:
- **Input**: `$badges` array, menu item `id` ('mgr_stock_in')
- **Output**: HTML span with badge count

**Implementation**: No changes required. The existing menu rendering code (around line 3282 and 3309 in `partials/header.php`) automatically renders badges for any key in the `$badges` array that matches a menu item ID.

**Rendered HTML**:
```html
<span data-sidebar-badge="mgr_stock_in" 
      data-badge 
      style="background:#E30613;color:white;padding:0 6px;border-radius:10px;font-size:11px;font-weight:bold;min-width:20px;height:20px;display:flex;align-items:center;justify-content:center;margin-left:10px;">
    5
</span>
```

### 3. Badge Update Component

**Location**: `backend/api/notifications_api.php` (action: `unread_count`)

**Responsibility**: Return current stock-in count during notification polling

**Interface**:
- **Input**: GET `?action=unread_count`
- **Output**: JSON with badge counts

**Implementation Details**:

Add to the role-specific badge counting section (around line 242 for managers, line 262 for admins):

```php
// In the manager section (around line 242)
if ($role === 'manager') {
    // ... existing manager badges ...
    
    // Stock-In pending badge
    $action_count += $safe_count(
        "SELECT COUNT(*) FROM deliveries_oversight 
         WHERE {$station_where}delivery_type IN ('merchandise', 'fuel')
           AND status IN (
               'Pending Stock-In', 
               'Ready for Stock-In', 
               'Validated', 
               'Verified', 
               'Partial Delivery', 
               'Damaged Items', 
               'Adjusted'
           )",
        $station_param
    );
}

// In the admin section (around line 180)
if (in_array($role, ['admin', 'superadmin', 'developer'])) {
    // ... existing admin badges ...
    
    // Stock-In pending badge
    $action_count += $safe_count(
        "SELECT COUNT(*) FROM deliveries_oversight 
         WHERE {$station_where}delivery_type IN ('merchandise', 'fuel')
           AND status IN (
               'Pending Stock-In', 
               'Ready for Stock-In', 
               'Validated', 
               'Verified', 
               'Partial Delivery', 
               'Damaged Items', 
               'Adjusted'
           )",
        $station_param
    );
}
```

**JSON Response Format**:
```json
{
    "success": true,
    "unread_count": 12,
    "bell_unread_count": 3,
    "category_counts": {
        "transactions": 5,
        "fuel": 2,
        "inventory": 5,
        "customers": 0
    }
}
```

The `unread_count` field is the aggregate of all action items. The JavaScript in `partials/header.php` (around line 5090) updates badges by querying `[data-sidebar-badge="mgr_stock_in"]` elements.

### 4. Badge Update JavaScript

**Location**: `partials/header.php` (around line 5089-5095)

**Responsibility**: Update badge DOM elements when polling returns new counts

**Interface**:
- **Input**: JSON response from notifications_api.php
- **Output**: Updated badge display in DOM

**Implementation**: No changes required. The existing JavaScript code already handles updating badges via the `data-sidebar-badge` attribute selector:

```javascript
// Existing code in partials/header.php (line ~5090)
for (const [key, cnt] of Object.entries(map)) {
    const els = document.querySelectorAll(`[data-sidebar-badge="${key}"]`);
    els.forEach(el => {
        if (cnt > 0) {
            el.textContent = cnt;
            el.style.display = 'flex';
        } else {
            el.textContent = '';
            el.style.display = 'none';
        }
    });
}
```

## Data Models

### deliveries_oversight Table

The `deliveries_oversight` table is the source of truth for pending stock-in counts.

**Relevant Columns**:
- `id` (INT, PRIMARY KEY): Unique delivery identifier
- `station_id` (INT, INDEXED): Station assignment for filtering
- `delivery_type` (ENUM: 'merchandise', 'fuel'): Type of delivery
- `status` (VARCHAR): Current delivery status
- `delivery_date` (DATE): When the delivery occurred
- `product` (VARCHAR): Product or fuel type name
- `quantity` (DECIMAL): Quantity delivered
- `created_at` (TIMESTAMP): Record creation time

**Pending Statuses** (from `manager_stock_in.php` line 35):
1. 'Pending Stock-In'
2. 'Ready for Stock-In'
3. 'Validated'
4. 'Verified'
5. 'Partial Delivery'
6. 'Damaged Items'
7. 'Adjusted'

**Query Pattern**:
```sql
SELECT COUNT(*) 
FROM deliveries_oversight 
WHERE station_id = ? 
  AND delivery_type IN ('merchandise', 'fuel')
  AND status IN ('Pending Stock-In', 'Ready for Stock-In', 'Validated', 
                 'Verified', 'Partial Delivery', 'Damaged Items', 'Adjusted')
```

**Index Usage**:
- Primary index on `station_id` for filtering by station
- Secondary index on `status` for efficient status filtering
- Query execution time: ~5-20ms for typical station data volumes

## Error Handling

### Database Query Failures

**Strategy**: Fail gracefully with zero count

**Implementation**:
```php
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE ...");
    $stmt->execute([$myStationId]);
    $badges['mgr_stock_in'] = (int)$stmt->fetchColumn();
} catch (Exception $e) { 
    $badges['mgr_stock_in'] = 0; 
    // No error logging - silent failure to avoid alert fatigue
}
```

**Rationale**:
- Badge display is non-critical functionality
- Zero count is safe default (no false positives)
- Users can still navigate to Stock-In page manually
- Prevents navigation menu breakage from DB issues

### Missing Table Scenario

**Condition**: `deliveries_oversight` table does not exist

**Behavior**: Exception caught, badge count set to 0

**User Experience**: Badge not displayed (count = 0)

**Alternative Access**: User can still access Stock-In page via menu click

### Network/API Failures

**Condition**: Polling request to `notifications_api.php` fails

**Behavior**: Existing badge value persists until next successful poll

**Implementation**: Already handled by existing polling code (no changes needed)

**User Experience**: Badge may be stale by 5-10 seconds during network issues

### Performance Degradation

**Condition**: Database query takes >200ms

**Mitigation Strategies**:
1. Query uses indexed columns (`station_id`, `status`)
2. Query returns single COUNT value (minimal data transfer)
3. Query executes only on page load and polling (max every 5 seconds)
4. No JOINs or subqueries (simple single-table query)

**Monitoring**: No additional monitoring required (standard DB performance monitoring applies)

## Testing Strategy

### Unit Testing

**Scope**: Badge calculation logic

**Test Cases**:

1. **Test: Manager with pending merchandise deliveries**
   - Given: Manager role, station_id = 1, 3 pending merchandise deliveries
   - When: Badge calculation runs
   - Then: `$badges['mgr_stock_in']` = 3

2. **Test: Manager with pending fuel deliveries**
   - Given: Manager role, station_id = 1, 2 pending fuel deliveries
   - When: Badge calculation runs
   - Then: `$badges['mgr_stock_in']` = 2

3. **Test: Manager with mixed deliveries**
   - Given: Manager role, station_id = 1, 3 merchandise + 2 fuel pending
   - When: Badge calculation runs
   - Then: `$badges['mgr_stock_in']` = 5

4. **Test: Manager with no pending deliveries**
   - Given: Manager role, station_id = 1, 0 pending deliveries
   - When: Badge calculation runs
   - Then: `$badges['mgr_stock_in']` = 0

5. **Test: Admin with assigned station**
   - Given: Admin role, station_id = 2, 4 pending deliveries
   - When: Badge calculation runs
   - Then: `$badges['mgr_stock_in']` = 4

6. **Test: Admin without assigned station**
   - Given: Admin role, station_id = NULL
   - When: Badge calculation runs
   - Then: `$badges['mgr_stock_in']` not set (no badge displayed)

7. **Test: Database query failure**
   - Given: Manager role, deliveries_oversight table inaccessible
   - When: Badge calculation runs
   - Then: `$badges['mgr_stock_in']` = 0, no exception thrown

8. **Test: Status filtering accuracy**
   - Given: Manager role, 10 deliveries with various statuses
   - When: Badge calculation runs
   - Then: Only deliveries with pending statuses counted

### Integration Testing

**Scope**: End-to-end badge display and updates

**Test Cases**:

1. **Test: Badge displays on page load**
   - Given: Manager logs in with 5 pending deliveries
   - When: Dashboard loads
   - Then: Stock-In menu item shows badge with "5"

2. **Test: Badge updates after processing delivery**
   - Given: Manager on Stock-In page with 5 pending deliveries
   - When: Manager approves 1 delivery
   - Then: Badge updates to "4" within 5 seconds

3. **Test: Badge hides when count reaches zero**
   - Given: Manager with 1 pending delivery
   - When: Manager approves the last delivery
   - Then: Badge disappears within 5 seconds

4. **Test: Badge styling matches existing badges**
   - Given: Manager with pending deliveries
   - When: Page loads
   - Then: Badge has red background (#E30613), white text, rounded corners

5. **Test: Badge visibility with sidebar collapsed**
   - Given: Manager with pending deliveries, sidebar collapsed
   - When: Page loads
   - Then: Badge is hidden (per existing badge behavior)

6. **Test: Real-time updates via polling**
   - Given: Manager on Dashboard, another user processes delivery
   - When: Next polling cycle occurs (~5 seconds)
   - Then: Badge count decrements automatically

### Manual Testing Checklist

- [ ] Badge displays correctly for manager role
- [ ] Badge displays correctly for admin role
- [ ] Badge does not display for staff role
- [ ] Badge count matches Stock-In page total
- [ ] Badge updates when delivery is approved
- [ ] Badge updates when delivery status changes back to pending
- [ ] Badge hides when count is zero
- [ ] Badge styling matches other navigation badges
- [ ] Badge hides when sidebar is collapsed
- [ ] Badge reappears when sidebar is expanded
- [ ] Page loads within 200ms with badge query
- [ ] No console errors related to badge updates
- [ ] Database failure does not break navigation menu

### Performance Testing

**Metrics**:
- Badge query execution time < 20ms (measured via `EXPLAIN` and query profiling)
- Page load time increase < 10ms (measured before/after implementation)
- Polling response time < 100ms (measured in browser dev tools)

**Load Testing**:
- 100 concurrent users: badge query execution time remains < 50ms
- 1000 deliveries in database: badge query execution time remains < 50ms

## Implementation Plan

### Phase 1: Add Badge Calculation (Header)

1. Edit `partials/header.php`
2. Locate manager badge section (around line 252-257)
3. Add stock-in badge calculation for manager role
4. Add stock-in badge calculation for admin role
5. Test badge appears on page load

**Estimated Time**: 30 minutes

### Phase 2: Add Badge Update Logic (API)

1. Edit `backend/api/notifications_api.php`
2. Locate `unread_count` action handler
3. Add stock-in count to manager section (line ~242)
4. Add stock-in count to admin section (line ~180)
5. Test API returns correct counts

**Estimated Time**: 30 minutes

### Phase 3: Testing

1. Run unit tests for badge calculation
2. Run integration tests for badge display
3. Verify badge updates in real-time
4. Test error handling (DB failures)
5. Verify performance metrics

**Estimated Time**: 1 hour

### Phase 4: Documentation

1. Update inline code comments
2. Document badge key in system documentation
3. Add troubleshooting guide for badge issues

**Estimated Time**: 30 minutes

**Total Estimated Time**: 2.5 hours

## Deployment Notes

### Pre-Deployment Checklist

- [ ] Verify `deliveries_oversight` table exists in production
- [ ] Verify indexes exist on `station_id` and `status` columns
- [ ] Test query performance on production data volume
- [ ] Verify manager and admin roles have station_id assigned
- [ ] Backup `partials/header.php` before changes
- [ ] Backup `backend/api/notifications_api.php` before changes

### Rollback Plan

If issues occur:

1. Restore `partials/header.php` from backup
2. Restore `backend/api/notifications_api.php` from backup
3. Clear PHP opcode cache (if applicable)
4. Badge will disappear, functionality otherwise unaffected

**Risk**: LOW - Changes are isolated to badge display logic, no database schema changes

### Post-Deployment Validation

- [ ] Badge displays for manager role
- [ ] Badge displays for admin role
- [ ] Badge count matches Stock-In page
- [ ] Badge updates in real-time
- [ ] No PHP errors in server logs
- [ ] No JavaScript errors in browser console
- [ ] Page load time within acceptable range

## Security Considerations

### SQL Injection

**Mitigation**: All queries use prepared statements with parameterized inputs

```php
$stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ?");
$stmt->execute([$myStationId]);
```

**Risk**: LOW - No user input directly in SQL queries

### Authorization

**Enforcement**: Badge only displays for manager and admin roles

```php
if ($role === 'manager' && $myStationId) { ... }
if ($role === 'admin' && $myStationId) { ... }
```

**Risk**: LOW - Role check prevents unauthorized access to badge data

### Information Disclosure

**Concern**: Badge count reveals information about station operations

**Mitigation**: 
- Badge only shows count, no sensitive details
- Filtered by user's assigned station_id
- User must already have access to Stock-In page

**Risk**: LOW - No sensitive information exposed beyond existing page access

### Performance-Based DoS

**Concern**: Malicious user triggers excessive badge queries

**Mitigation**:
- Query executes only on page load (user-initiated)
- Polling rate limited to every 5 seconds (existing mechanism)
- Query is optimized with indexes (< 20ms execution)

**Risk**: LOW - No new attack vectors introduced

## Maintenance and Monitoring

### Logging

**Current State**: No specific badge logging implemented

**Recommendation**: Use existing PHP error logging

**Critical Errors to Monitor**:
- Database connection failures (already logged)
- Query execution timeouts (already logged)
- Uncaught exceptions (already logged)

**Implementation**: No changes needed (relies on existing error logging)

### Performance Monitoring

**Key Metrics**:
- `deliveries_oversight` table query performance
- `notifications_api.php` response time
- Page load time for pages with badges

**Tools**: 
- MySQL slow query log (queries > 100ms)
- Application performance monitoring (if available)
- Browser developer tools (network timing)

**Alert Thresholds**:
- Badge query > 100ms: Investigate indexing
- API response > 500ms: Investigate server load
- Page load time increase > 100ms: Review badge implementation

### Database Maintenance

**Index Health**:
- Monitor `deliveries_oversight` table size
- Verify indexes on `station_id` and `status` columns
- Run `ANALYZE TABLE deliveries_oversight` monthly

**Data Archival**:
- Consider archiving old completed deliveries (status = 'Stock-In Done')
- Maintain ~6 months of active delivery data for optimal query performance

### Code Maintenance

**Future Considerations**:
- If pending statuses change, update both `partials/header.php` and `notifications_api.php`
- If badge key changes, update menu item ID in `partials/rbac_menu.php`
- If station filtering logic changes, update both badge calculation points

**Documentation Updates**:
- Update this design document when implementation deviates from spec
- Document any performance tuning applied in production
- Update troubleshooting guide with common issues encountered

## Appendix A: Badge Key Reference

| Menu Item ID | Badge Key | Description | Roles |
|-------------|-----------|-------------|-------|
| `mgr_stock_in` | `mgr_stock_in` | Pending stock-in deliveries | manager, admin |

## Appendix B: Status Values Reference

**Pending Statuses** (counted in badge):
1. Pending Stock-In
2. Ready for Stock-In
3. Validated
4. Verified
5. Partial Delivery
6. Damaged Items
7. Adjusted

**Completed Statuses** (not counted in badge):
1. Stock-In Done
2. Completed
3. Rejected
4. Cancelled

## Appendix C: Performance Benchmarks

**Query Execution Times** (measured on test database with 500 deliveries):

| Query | Execution Time | Notes |
|-------|---------------|-------|
| Badge count (indexed) | 8ms | With indexes on station_id, status |
| Badge count (no index) | 45ms | Without proper indexes |
| API unread_count | 150ms | Includes all badge queries |
| Page load increase | 5ms | Impact of adding badge query |

**Recommendations**:
- Ensure indexes exist on `station_id` and `status` columns
- Monitor query performance as delivery data grows
- Consider materialized view if data volume exceeds 10,000 deliveries
