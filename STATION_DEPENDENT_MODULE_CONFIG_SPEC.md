# 📑 Station-Dependent Module Configuration (Developer)

**Concept:** Module Configuration is **per station**, not global. Each branch can have different active modules.

---

## 🎯 Core Concept

### Traditional (Wrong):
```
❌ Global Toggle:
   Transactions: [ON] → Enabled for ALL stations
   Fuel Management: [OFF] → Disabled for ALL stations
```

### Station-Dependent (Correct): ✅
```
✅ Per-Station Toggle:
   Station: Cebu Branch
   └── Transactions: [ON]
   └── Fuel Management: [ON]
   └── Job Orders: [OFF]

   Station: Manila Branch
   └── Transactions: [ON]
   └── Fuel Management: [OFF]
   └── Job Orders: [ON]
```

**Different stations = Different module configurations**

---

## 📊 Database Schema

### Table: `station_modules`
```sql
CREATE TABLE station_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    module_key VARCHAR(50) NOT NULL,
    is_enabled TINYINT(1) DEFAULT 1,
    configuration JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    UNIQUE KEY unique_station_module (station_id, module_key),
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id),
    INDEX idx_station_enabled (station_id, is_enabled)
);
```

### Table: `station_module_audit`
```sql
CREATE TABLE station_module_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id INT NOT NULL,
    module_key VARCHAR(50) NOT NULL,
    action ENUM('enable', 'disable', 'configure') NOT NULL,
    old_value TEXT,
    new_value TEXT,
    developer_id INT NOT NULL,
    developer_name VARCHAR(100),
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (developer_id) REFERENCES users(id),
    INDEX idx_station_created (station_id, created_at DESC),
    INDEX idx_module_created (module_key, created_at DESC)
);
```

---

## 🔄 Data Flow

### 1. **Fetch Station List**

**Query:**
```sql
SELECT 
    s.id,
    s.name,
    s.location,
    s.region,
    s.status,
    COUNT(DISTINCT sm.id) as total_modules,
    COUNT(DISTINCT CASE WHEN sm.is_enabled = 1 THEN sm.id END) as enabled_modules
FROM stations s
LEFT JOIN station_modules sm ON sm.station_id = s.id
WHERE s.status = 'Active'
GROUP BY s.id
ORDER BY s.name;
```

**Returns:**
```json
[
    {
        "id": 1,
        "name": "PETRON - Cebu Main",
        "location": "Cebu City",
        "region": "Region VII",
        "status": "Active",
        "total_modules": 9,
        "enabled_modules": 7
    },
    ...
]
```

---

### 2. **Fetch Module Status per Station**

**Query:**
```sql
SELECT 
    sm.module_key,
    sm.is_enabled,
    sm.configuration,
    sm.updated_at,
    u.first_name,
    u.last_name
FROM station_modules sm
LEFT JOIN users u ON u.id = sm.updated_by
WHERE sm.station_id = ?
ORDER BY sm.module_key;
```

**Returns:**
```json
[
    {
        "module_key": "transactions",
        "is_enabled": 1,
        "configuration": {"allow_credit": true},
        "updated_at": "2026-06-14 10:30:00",
        "updated_by": "John Developer"
    },
    {
        "module_key": "fuel_management",
        "is_enabled": 1,
        "configuration": {...},
        "updated_at": "2026-06-14 10:31:00",
        "updated_by": "John Developer"
    },
    ...
]
```

---

### 3. **Toggle Module for Specific Station**

**API Endpoint:** `POST /backend/api/station_module_api.php`

**Request:**
```json
{
    "action": "toggle_module",
    "station_id": 1,
    "module_key": "fuel_management",
    "enabled": 1,
    "csrf_token": "..."
}
```

**Process:**
1. Validate CSRF token
2. Check user role (SuperAdmin/Developer only)
3. Get old value from database
4. Update `station_modules` table
5. Log to `station_module_audit`
6. Return success response

**Response:**
```json
{
    "ok": true,
    "message": "Module 'fuel_management' enabled for station 'PETRON - Cebu Main'",
    "station_id": 1,
    "module_key": "fuel_management",
    "is_enabled": 1
}
```

---

### 4. **Cascade to Roles**

When a module is disabled for a station, it affects all users assigned to that station:

**Check Module Access:**
```php
function hasModuleAccess($user_id, $module_key) {
    global $pdo;
    
    // Get user's station
    $stmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $station_id = $stmt->fetchColumn();
    
    if (!$station_id) return false;
    
    // Check if module is enabled for that station
    $stmt = $pdo->prepare("
        SELECT is_enabled 
        FROM station_modules 
        WHERE station_id = ? AND module_key = ?
    ");
    $stmt->execute([$station_id, $module_key]);
    $is_enabled = $stmt->fetchColumn();
    
    return (bool)$is_enabled;
}
```

**Sidebar Menu Filter:**
```php
// In sidebar rendering
if (hasModuleAccess($me['id'], 'transactions')) {
    // Show Transactions menu
}

if (hasModuleAccess($me['id'], 'fuel_management')) {
    // Show Fuel Management menu
}
```

---

## 🖼️ UI Design

### Layout Structure

```
MODULE CONFIGURATION (STATION-DEPENDENT)
Developer complete functions – Configure modules per station

┌─────────────────────────────────────────────────────────────┐
│ 🔍 Search stations...    [Region Filter ▼]  [Status ▼]     │
└─────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────────────┐
│ STATION                   │ REGION      │ MODULES       │  ACTIONS         │
├────────────────────────────────────────────────────────────────────────────┤
│ 🏢 PETRON - Cebu Main     │ Region VII  │ 7/9 Enabled   │ ⚙️ Configure     │
│    Cebu City              │             │ 🟢●●●●●●●○○   │    Modules       │
├────────────────────────────────────────────────────────────────────────────┤
│ 🏢 PETRON - Manila North  │ NCR         │ 8/9 Enabled   │ ⚙️ Configure     │
│    Quezon City            │             │ 🟢●●●●●●●●○   │    Modules       │
└────────────────────────────────────────────────────────────────────────────┘
```

### Configure Modules Modal

```
┌──────────────────────────────────────────────────────────────┐
│  Configure Modules – PETRON - Cebu Main                      │
│  ─────────────────────────────────────────────────────────   │
│                                                               │
│  MODULE               │  STATUS   │ ENABLE/DISABLE │ CONFIG  │
│  ────────────────────────────────────────────────────────    │
│  🛒 Transactions      │ ENABLED   │ [ON  ✓]       │ ⚙️      │
│  ⛽ Fuel Management   │ ENABLED   │ [ON  ✓]       │ ⚙️      │
│  📦 Inventory         │ DISABLED  │ [OFF  ]       │ ⚙️      │
│  🔧 Job Orders        │ ENABLED   │ [ON  ✓]       │ ⚙️      │
│  📅 Calendar          │ ENABLED   │ [ON  ✓]       │ ⚙️      │
│  📊 Reports           │ ENABLED   │ [ON  ✓]       │ ⚙️      │
│  👥 Customers         │ ENABLED   │ [ON  ✓]       │ ⚙️      │
│  🚚 Deliveries        │ ENABLED   │ [ON  ✓]       │ ⚙️      │
│  📄 Purchase Orders   │ DISABLED  │ [OFF  ]       │ ⚙️      │
│                                                               │
│  [Close]                                    [Save Changes]   │
└──────────────────────────────────────────────────────────────┘
```

---

## 📝 Implementation Plan

### Step 1: Database Setup
```sql
-- Create tables
CREATE TABLE station_modules (...);
CREATE TABLE station_module_audit (...);

-- Populate default modules for all stations
INSERT INTO station_modules (station_id, module_key, is_enabled)
SELECT 
    s.id,
    m.module_key,
    1 as is_enabled
FROM stations s
CROSS JOIN (
    SELECT 'transactions' as module_key UNION ALL
    SELECT 'fuel_management' UNION ALL
    SELECT 'inventory' UNION ALL
    SELECT 'job_orders' UNION ALL
    SELECT 'calendar' UNION ALL
    SELECT 'reports' UNION ALL
    SELECT 'customers' UNION ALL
    SELECT 'deliveries' UNION ALL
    SELECT 'purchase_orders'
) m
WHERE s.status = 'Active';
```

---

### Step 2: Backend API (`backend/api/station_module_api.php`)

**Endpoints:**
1. **GET `?action=get_stations`** - Fetch all stations with module counts
2. **GET `?action=get_station_modules&station_id=X`** - Get modules for a station
3. **POST `action=toggle_module`** - Enable/disable module for station
4. **POST `action=configure_module`** - Update module configuration
5. **GET `?action=get_audit_log&station_id=X`** - Get audit trail

**Example:**
```php
<?php
// backend/api/station_module_api.php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');

// Only SuperAdmin/Developer
if ($role !== 'superadmin') {
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_stations':
        $stmt = $pdo->query("
            SELECT 
                s.id,
                s.name,
                s.location,
                s.region,
                COUNT(DISTINCT sm.id) as total_modules,
                COUNT(DISTINCT CASE WHEN sm.is_enabled = 1 THEN sm.id END) as enabled_modules
            FROM stations s
            LEFT JOIN station_modules sm ON sm.station_id = s.id
            WHERE s.status = 'Active'
            GROUP BY s.id
            ORDER BY s.name
        ");
        echo json_encode(['ok' => true, 'stations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    case 'get_station_modules':
        $station_id = (int)$_GET['station_id'];
        $stmt = $pdo->prepare("
            SELECT 
                sm.module_key,
                sm.is_enabled,
                sm.configuration,
                sm.updated_at
            FROM station_modules sm
            WHERE sm.station_id = ?
            ORDER BY sm.module_key
        ");
        $stmt->execute([$station_id]);
        echo json_encode(['ok' => true, 'modules' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;
        
    case 'toggle_module':
        $station_id = (int)$_POST['station_id'];
        $module_key = $_POST['module_key'];
        $enabled = (int)$_POST['enabled'];
        
        // Get old value
        $stmt = $pdo->prepare("SELECT is_enabled FROM station_modules WHERE station_id = ? AND module_key = ?");
        $stmt->execute([$station_id, $module_key]);
        $old_value = $stmt->fetchColumn();
        
        // Update
        $stmt = $pdo->prepare("
            UPDATE station_modules 
            SET is_enabled = ?, updated_by = ?, updated_at = NOW()
            WHERE station_id = ? AND module_key = ?
        ");
        $stmt->execute([$enabled, $me['id'], $station_id, $module_key]);
        
        // Log audit
        $stmt = $pdo->prepare("
            INSERT INTO station_module_audit 
            (station_id, module_key, action, old_value, new_value, developer_id, developer_name, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $station_id,
            $module_key,
            $enabled ? 'enable' : 'disable',
            $old_value,
            $enabled,
            $me['id'],
            $me['first_name'] . ' ' . $me['last_name'],
            $_SERVER['REMOTE_ADDR']
        ]);
        
        echo json_encode(['ok' => true, 'message' => 'Module updated successfully']);
        break;
}
?>
```

---

### Step 3: Frontend Implementation

**File:** `public/module_configuration.php`

**Key Changes:**
1. Show station list instead of module list
2. "Configure Modules" button per station
3. Modal shows modules for selected station
4. Toggle affects only that station

---

## 🔐 Security & Access Control

### Role Permissions
- **SuperAdmin/Developer:** Full access to configure any station
- **Admin:** View-only for their own station
- **Manager/Staff:** No access

### Validation Rules
1. Verify user is SuperAdmin/Developer
2. Validate CSRF token
3. Verify station_id exists
4. Verify module_key is valid
5. Log all changes to audit table

---

## 🎯 Benefits

### Station Flexibility
- ✅ Small stations can disable complex modules
- ✅ Large stations can enable all features
- ✅ Pilot features at specific branches first

### Maintenance
- ✅ Disable problematic modules for specific stations
- ✅ Rollout updates gradually per station
- ✅ Test configurations without affecting all branches

### Audit Trail
- ✅ Track who changed what module for which station
- ✅ See configuration history per station
- ✅ Compliance and accountability

---

## ✅ Implementation Checklist

### Database
- [ ] Create `station_modules` table
- [ ] Create `station_module_audit` table
- [ ] Populate default data for all stations
- [ ] Add foreign key constraints

### Backend API
- [ ] Create `station_module_api.php`
- [ ] Implement `get_stations` endpoint
- [ ] Implement `get_station_modules` endpoint
- [ ] Implement `toggle_module` endpoint
- [ ] Implement audit logging

### Frontend
- [ ] Update `module_configuration.php` UI
- [ ] Show station list with module counts
- [ ] Create "Configure Modules" modal
- [ ] Implement toggle functionality per station
- [ ] Add search and filter by region

### Access Control
- [ ] Create `hasModuleAccess()` helper function
- [ ] Update sidebar menu rendering
- [ ] Filter dashboard widgets by module access
- [ ] Redirect if accessing disabled module

### Testing
- [ ] Test enabling/disabling modules
- [ ] Verify cascade to user roles
- [ ] Test audit trail logging
- [ ] Test multiple stations

---

**Status:** Specification Complete  
**Next:** Database schema creation + Backend API  
**Priority:** High  
**Estimated Time:** 6-8 hours implementation
