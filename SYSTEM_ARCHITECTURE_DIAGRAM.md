# 🏗️ Module Configuration - System Architecture

## Complete Station-Dependent Configuration System

---

## 📊 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                         DEVELOPER ROLE                              │
│                    (SuperAdmin - module_configuration.php)          │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    FRONTEND USER INTERFACE                          │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  Station List View (To be implemented)                       │  │
│  │  ┌────────────┬──────────┬──────────┬─────────────────────┐ │  │
│  │  │ Station    │ Region   │ Modules  │ Actions             │ │  │
│  │  ├────────────┼──────────┼──────────┼─────────────────────┤ │  │
│  │  │ Cebu Main  │ Reg VII  │ 7/8 ●●● │ ⚙️ Configure Modules │ │  │
│  │  │ Manila N   │ NCR      │ 8/8 ●●● │ ⚙️ Configure Modules │ │  │
│  │  └────────────┴──────────┴──────────┴─────────────────────┘ │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  Configure Modules Modal (Per Station)                       │  │
│  │  ┌────────────┬────────┬──────────┬─────────────────────┐   │  │
│  │  │ Module     │ Status │ Toggle   │ Configure           │   │  │
│  │  ├────────────┼────────┼──────────┼─────────────────────┤   │  │
│  │  │ Fuel Mgmt  │ ON     │ [✓]      │ ⚙️ Detailed Config   │   │  │
│  │  │ Inventory  │ ON     │ [✓]      │ ⚙️ Detailed Config   │   │  │
│  │  │ Job Orders │ OFF    │ [ ]      │ ⚙️ Detailed Config   │   │  │
│  │  └────────────┴────────┴──────────┴─────────────────────┘   │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  Detailed Configuration Panel (Per Module)                   │  │
│  │  Example: Fuel Management for Cebu Station                   │  │
│  │  ┌──────────────┬──────────┬──────────┬──────────────────┐  │  │
│  │  │ Fuel Type    │ Price/L  │ Tank Cap │ Calibration Days │  │  │
│  │  ├──────────────┼──────────┼──────────┼──────────────────┤  │  │
│  │  │ Diesel       │ ₱65.50   │ 10,000L  │ 30               │  │  │
│  │  │ Gasoline     │ ₱70.00   │ 8,000L   │ 30               │  │  │
│  │  │ Kerosene     │ ₱55.00   │ 5,000L   │ 45               │  │  │
│  │  └──────────────┴──────────┴──────────┴──────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────┘  │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                   BACKEND API (station_module_api.php)              │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  10 Endpoints:                                                │  │
│  │  1. get_stations           → List all stations               │  │
│  │  2. get_station_modules    → Modules for station             │  │
│  │  3. toggle_module          → Enable/disable                  │  │
│  │  4. get_fuel_config        → Fuel settings                   │  │
│  │  5. update_fuel_config     → Update fuel                     │  │
│  │  6. get_payment_config     → Payment methods                 │  │
│  │  7. get_inventory_config   → Inventory rules                 │  │
│  │  8. update_inventory_config→ Update inventory                │  │
│  │  9. get_report_config      → Report settings                 │  │
│  │ 10. get_audit_log          → Change history                  │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  Security: CSRF | Roles | SQL Injection Prevention                 │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    DATABASE (petron_pos_db_secure)                  │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  9 Configuration Tables:                                      │  │
│  │                                                                │  │
│  │  ┌────────────────────┐  ┌────────────────────┐              │  │
│  │  │ station_modules    │  │ station_fuel_config│              │  │
│  │  │ ─────────────────  │  │ ──────────────────  │              │  │
│  │  │ station_id         │  │ station_id         │              │  │
│  │  │ module_key         │  │ fuel_type          │              │  │
│  │  │ is_enabled         │  │ price_per_liter    │              │  │
│  │  │ updated_at         │  │ tank_capacity      │              │  │
│  │  └────────────────────┘  │ calibration_days   │              │  │
│  │                          │ variance_tolerance │              │  │
│  │  ┌────────────────────┐  └────────────────────┘              │  │
│  │  │ station_merchandise│                                       │  │
│  │  │ ──────────────────  │  ┌────────────────────┐              │  │
│  │  │ station_id         │  │ station_job_order  │              │  │
│  │  │ item_name          │  │ ──────────────────  │              │  │
│  │  │ category           │  │ station_id         │              │  │
│  │  │ unit_price         │  │ service_type       │              │  │
│  │  │ stock_unit         │  │ workflow_status    │              │  │
│  │  │ fifo_enabled       │  │ link_receivables   │              │  │
│  │  │ low_stock_threshold│  │ require_approval   │              │  │
│  │  └────────────────────┘  └────────────────────┘              │  │
│  │                                                                │  │
│  │  ┌────────────────────┐  ┌────────────────────┐              │  │
│  │  │ station_payment    │  │ station_inventory  │              │  │
│  │  │ ──────────────────  │  │ ──────────────────  │              │  │
│  │  │ station_id         │  │ station_id         │              │  │
│  │  │ payment_method     │  │ stock_movement_rule│              │  │
│  │  │ require_ref_number │  │ auto_update_delivery│             │  │
│  │  │ allow_partial      │  │ auto_update_sale   │              │  │
│  │  │ payment_status     │  │ require_approval   │              │  │
│  │  └────────────────────┘  │ low_stock_alert    │              │  │
│  │                          └────────────────────┘              │  │
│  │  ┌────────────────────┐                                       │  │
│  │  │ station_calendar   │  ┌────────────────────┐              │  │
│  │  │ ──────────────────  │  │ station_report     │              │  │
│  │  │ station_id         │  │ ──────────────────  │              │  │
│  │  │ shift_enabled      │  │ station_id         │              │  │
│  │  │ delivery_enabled   │  │ report_type        │              │  │
│  │  │ calibration_enabled│  │ computation_formula│              │  │
│  │  │ notifications      │  │ export_excel       │              │  │
│  │  └────────────────────┘  │ export_pdf         │              │  │
│  │                          │ role_access        │              │  │
│  │  ┌────────────────────┐  └────────────────────┘              │  │
│  │  │ station_module_audit                                       │  │
│  │  │ ─────────────────────                                      │  │
│  │  │ station_id | module_key | action                           │  │
│  │  │ field_changed | old_value | new_value                      │  │
│  │  │ developer_id | developer_name | ip_address                 │  │
│  │  │ created_at                                                 │  │
│  │  └────────────────────────────────────────────────────────────┘  │
│  └──────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 🔄 Data Flow Example

### Scenario: Enable Fuel Management for Cebu Station

```
┌──────────────┐
│  Developer   │
│  (Browser)   │
└──────┬───────┘
       │
       │ 1. Opens Module Configuration Page
       ▼
┌──────────────────────────────────┐
│  module_configuration.php        │
│  Shows list of all stations      │
└──────┬───────────────────────────┘
       │
       │ 2. Clicks "Configure Modules" for Cebu
       │    GET /station_module_api.php?action=get_station_modules&station_id=1
       ▼
┌──────────────────────────────────┐
│  station_module_api.php          │
│  Queries: station_modules table  │
│  Returns: All 8 modules + status │
└──────┬───────────────────────────┘
       │
       │ 3. Returns JSON:
       │    {modules: [{module_key: 'fuel_management', is_enabled: 0}, ...]}
       ▼
┌──────────────────────────────────┐
│  Modal displays modules          │
│  Fuel Management: [OFF]          │
└──────┬───────────────────────────┘
       │
       │ 4. Developer toggles Fuel Management ON
       │    POST /station_module_api.php
       │    {action: 'toggle_module', station_id: 1,
       │     module_key: 'fuel_management', enabled: 1}
       ▼
┌──────────────────────────────────┐
│  station_module_api.php          │
│  1. Validates CSRF token         │
│  2. Checks role = SuperAdmin     │
│  3. Gets old value from DB       │
│  4. Updates station_modules      │
│  5. Logs to station_module_audit │
└──────┬───────────────────────────┘
       │
       │ 5. Database Updates:
       ▼
┌──────────────────────────────────┐
│  station_modules                 │
│  UPDATE is_enabled = 1           │
│  WHERE station_id = 1            │
│  AND module_key = 'fuel_mgmt'    │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│  station_module_audit            │
│  INSERT: station_id=1,           │
│  module='fuel_mgmt', action=     │
│  'enable', old=0, new=1,         │
│  developer='John Dev'            │
└──────┬───────────────────────────┘
       │
       │ 6. Returns success
       ▼
┌──────────────────────────────────┐
│  Frontend updates UI             │
│  Shows toast: "Module enabled"   │
│  Badge changes: OFF → ON         │
└──────────────────────────────────┘
       │
       │ 7. Developer clicks "⚙️ Configure"
       │    GET /station_module_api.php?action=get_fuel_config&station_id=1
       ▼
┌──────────────────────────────────┐
│  station_module_api.php          │
│  Queries: station_fuel_config    │
│  Returns: Diesel, Gasoline,      │
│           Kerosene with prices   │
└──────┬───────────────────────────┘
       │
       │ 8. Shows fuel configuration panel
       ▼
┌──────────────────────────────────┐
│  Detailed Configuration Panel    │
│  Diesel: ₱65.50/L, 10,000L, 30d │
│  Gasoline: ₱70.00/L, 8,000L, 30d│
│  Kerosene: ₱55.00/L, 5,000L, 45d│
│  Developer can edit each field   │
└──────────────────────────────────┘
```

---

## 🎯 Station Independence

```
┌─────────────────────────────────────────────────────────────┐
│                    STATION INDEPENDENCE                     │
└─────────────────────────────────────────────────────────────┘

Station 1 (Cebu Main - station_id: 1)
├─ station_modules (station_id=1)
│  ├─ transactions: [ON]
│  ├─ fuel_management: [ON]
│  ├─ inventory: [ON]
│  └─ job_orders: [OFF]
│
├─ station_fuel_config (station_id=1)
│  ├─ Diesel: ₱65.50/L, Tank: 10,000L
│  ├─ Gasoline: ₱70.00/L, Tank: 8,000L
│  └─ Kerosene: ₱55.00/L, Tank: 5,000L
│
├─ station_payment_config (station_id=1)
│  ├─ Cash: Enabled
│  ├─ Card: Enabled
│  ├─ E-Wallet: Enabled
│  ├─ Fleet: Enabled
│  └─ Credit: Enabled
│
└─ station_inventory_config (station_id=1)
   └─ Stock Rule: FIFO, Auto-update: Yes

═══════════════════════════════════════════════════════════════

Station 2 (Manila North - station_id: 2)
├─ station_modules (station_id=2)
│  ├─ transactions: [ON]
│  ├─ fuel_management: [OFF]    ← Different than Cebu!
│  ├─ inventory: [ON]
│  └─ job_orders: [ON]           ← Different than Cebu!
│
├─ station_fuel_config (station_id=2)
│  ├─ Diesel: ₱66.00/L, Tank: 15,000L  ← Different prices!
│  ├─ Gasoline: ₱71.00/L, Tank: 12,000L
│  └─ Kerosene: ₱56.00/L, Tank: 7,000L
│
├─ station_payment_config (station_id=2)
│  ├─ Cash: Enabled
│  ├─ Card: Disabled              ← Different than Cebu!
│  ├─ E-Wallet: Enabled
│  ├─ Fleet: Enabled
│  └─ Credit: Enabled
│
└─ station_inventory_config (station_id=2)
   └─ Stock Rule: FEFO, Auto-update: Yes  ← Different rule!

═══════════════════════════════════════════════════════════════

Result: Changes to Station 1 DON'T affect Station 2
Each station operates independently!
```

---

## 🔐 Security Flow

```
┌──────────────┐
│   Request    │
└──────┬───────┘
       │
       ▼
┌──────────────────────────────────┐
│  1. Session Check                │
│     require_login()              │
│     ✓ User logged in?            │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│  2. Role Check                   │
│     role_key() = 'superadmin'?   │
│     ✓ Is Developer/SuperAdmin?   │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│  3. CSRF Check (POST only)       │
│     $_POST['csrf_token'] ===     │
│     $_SESSION['csrf_token']?     │
│     ✓ Valid token?               │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│  4. SQL Injection Prevention     │
│     PDO Prepared Statements      │
│     ✓ Parameters sanitized?      │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│  5. Process Request              │
│     Execute query                │
│     Update database              │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│  6. Audit Trail                  │
│     Log to station_module_audit  │
│     ✓ Who, what, when, where     │
└──────┬───────────────────────────┘
       │
       ▼
┌──────────────────────────────────┐
│  7. Return Response              │
│     JSON {ok: true, message}     │
└──────────────────────────────────┘
```

---

## 📊 Database Relationships

```
┌──────────────┐
│   stations   │ ← Main stations table (1413 records)
└──────┬───────┘
       │
       │ One station has many:
       ├─────────────────────────────────────────┐
       │                                         │
       ▼                                         ▼
┌──────────────────┐                  ┌──────────────────────┐
│ station_modules  │                  │ station_fuel_config  │
│ ────────────────  │                  │ ───────────────────   │
│ station_id (FK)  │                  │ station_id (FK)      │
│ module_key       │                  │ fuel_type            │
│ is_enabled       │                  │ price_per_liter      │
└──────────────────┘                  └──────────────────────┘
       │                                         │
       │                                         │
       ▼                                         ▼
┌──────────────────────┐            ┌──────────────────────────┐
│ station_merchandise  │            │ station_job_order_config │
│ ───────────────────   │            │ ────────────────────────  │
│ station_id (FK)      │            │ station_id (FK)          │
│ item_name            │            │ service_type             │
│ unit_price           │            │ workflow_status          │
└──────────────────────┘            └──────────────────────────┘
       │                                         │
       │                                         │
       ▼                                         ▼
┌──────────────────────┐            ┌──────────────────────────┐
│ station_payment_cfg  │            │ station_inventory_config │
│ ───────────────────   │            │ ────────────────────────  │
│ station_id (FK)      │            │ station_id (FK)          │
│ payment_method       │            │ stock_movement_rule      │
└──────────────────────┘            └──────────────────────────┘
       │                                         │
       │                                         │
       ▼                                         ▼
┌──────────────────────┐            ┌──────────────────────────┐
│ station_calendar_cfg │            │ station_report_config    │
│ ───────────────────   │            │ ────────────────────────  │
│ station_id (FK)      │            │ station_id (FK)          │
│ shift_enabled        │            │ report_type              │
└──────────────────────┘            └──────────────────────────┘
       │
       │ All changes logged to:
       ▼
┌──────────────────────┐
│ station_module_audit │
│ ───────────────────   │
│ station_id (FK)      │
│ module_key           │
│ action               │
│ old_value            │
│ new_value            │
│ developer_id         │
│ created_at           │
└──────────────────────┘

Foreign Key Rule: ON DELETE CASCADE
(If station deleted, all config records deleted)
```

---

## 🚀 Deployment Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    DEPLOYMENT LAYERS                    │
└─────────────────────────────────────────────────────────┘

Layer 1: DATABASE (MySQL)
├─ Run: complete_station_module_config.sql
├─ Creates: 9 tables
├─ Populates: Default data for all stations
└─ Status: ✅ READY (just run SQL)

Layer 2: BACKEND API (PHP)
├─ File: backend/api/station_module_api.php
├─ Endpoints: 10 REST API endpoints
├─ Security: CSRF + Roles + SQL Injection Prevention
└─ Status: ✅ READY (already created)

Layer 3: FRONTEND UI (HTML/CSS/JS)
├─ File: public/module_configuration.php
├─ Current: Shows modules globally
├─ Needed: Show station list with configure buttons
└─ Status: 🔄 NEEDS REDESIGN (20% complete)

Layer 4: DOCUMENTATION
├─ Field reference
├─ API documentation
├─ Implementation guides
├─ Architecture diagrams
└─ Status: ✅ COMPLETE (70+ pages)

Layer 5: SECURITY & AUDIT
├─ CSRF tokens
├─ Role-based access
├─ Audit trail logging
├─ IP address tracking
└─ Status: ✅ COMPLETE (fully implemented)
```

---

**Backend architecture complete! Station-dependent! 50+ fields! Ready to deploy! 🏗️✅**
