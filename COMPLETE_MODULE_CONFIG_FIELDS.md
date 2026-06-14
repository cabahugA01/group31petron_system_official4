# 📑 Complete Module Configuration – Developer Role with Station Dependency

**Comprehensive field-level documentation for station-dependent module configuration**

---

## 🎯 Overview

The Module Configuration system allows Developers to:
- ✅ **Enable/Disable modules per station** (not globally)
- ✅ **Configure detailed settings per module per station**
- ✅ **Control access and rules independently for each branch**
- ✅ **Track all changes with complete audit trail**

---

## 📊 Database Structure Summary

### Core Tables Created:
1. **`station_modules`** - Enable/disable modules per station
2. **`station_fuel_config`** - Fuel management settings per station
3. **`station_merchandise_config`** - Merchandise catalog per station
4. **`station_job_order_config`** - Job order rules per station
5. **`station_payment_config`** - Payment methods per station
6. **`station_inventory_config`** - Inventory rules per station
7. **`station_calendar_config`** - Calendar settings per station
8. **`station_report_config`** - Report configuration per station
9. **`station_module_audit`** - Complete audit trail for all changes

---

## 🏢 Station Context Fields

**Every configuration is tied to a station:**

| Field | Type | Description |
|-------|------|-------------|
| **Station ID** | INT | Unique identifier for the branch |
| **Station Name** | VARCHAR | Official branch name (e.g., "PETRON - Cebu Main") |
| **Region** | VARCHAR | Geographic region (NCR, Region VII, etc.) |
| **Contact Number** | VARCHAR | Optional branch contact |
| **Assigned Admin** | VARCHAR | 1 Admin per station (linked via station.admin_id) |
| **Status** | ENUM | Active, Inactive |

---

## ⛽ FUEL MANAGEMENT Module

**Table:** `station_fuel_config`

### Fields:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| **station_id** | INT | - | Which station this config belongs to |
| **fuel_type** | VARCHAR(50) | - | Diesel, Gasoline, Kerosene |
| **official_price_per_liter** | DECIMAL(10,2) | - | Current selling price |
| **tank_capacity** | DECIMAL(10,2) | 0 | Maximum liters tank can hold |
| **calibration_schedule_days** | INT | 30 | How often to calibrate (days) |
| **variance_tolerance_percent** | DECIMAL(5,2) | 5.0 | Acceptable variance % |
| **reconciliation_formula** | VARCHAR(255) | (present - previous - calibration) * price_per_liter | Formula for variance calculation |
| **is_active** | TINYINT(1) | 1 | Is this fuel type active? |
| **created_at** | TIMESTAMP | NOW() | When created |
| **updated_at** | TIMESTAMP | NOW() | Last modified |
| **updated_by** | INT | - | Developer who made change |

### Example Use Case:
```
Cebu Station:
  - Diesel: ₱65.50/L, 10,000L tank, calibrate every 30 days, 5% tolerance
  - Gasoline: ₱70.00/L, 8,000L tank, calibrate every 30 days, 5% tolerance
  
Manila Station:
  - Diesel: ₱66.00/L, 15,000L tank, calibrate every 45 days, 3% tolerance
  (Different prices, capacities, and rules per station)
```

---

## 🛒 MERCHANDISE Module

**Table:** `station_merchandise_config`

### Fields:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| **station_id** | INT | - | Which station |
| **item_name** | VARCHAR(255) | - | Product name |
| **category** | VARCHAR(100) | - | Product category (Drinks, Snacks, Oil, etc.) |
| **unit_price** | DECIMAL(10,2) | - | Selling price |
| **stock_unit** | VARCHAR(50) | - | pcs, box, pack, bottle, etc. |
| **fifo_enabled** | TINYINT(1) | 1 | First-In-First-Out inventory logic |
| **low_stock_threshold** | INT | 10 | Alert when stock reaches this level |
| **delivery_auto_update** | TINYINT(1) | 1 | Auto update stock on delivery validation |
| **is_active** | TINYINT(1) | 1 | Is this item active in catalog? |
| **created_at** | TIMESTAMP | NOW() | When added |
| **updated_at** | TIMESTAMP | NOW() | Last modified |
| **updated_by** | INT | - | Developer who made change |

### Example Use Case:
```
Cebu Station Catalog:
  - Coke 1.5L: ₱65, pcs, FIFO enabled, alert at 10 pcs
  - Engine Oil 1L: ₱450, bottle, FIFO enabled, alert at 5 bottles
  
Manila Station Catalog:
  - Coke 1.5L: ₱70, pcs (different price)
  - Pepsi 1.5L: ₱68, pcs (different products)
```

---

## 🔧 JOB ORDERS Module

**Table:** `station_job_order_config`

### Fields:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| **station_id** | INT | - | Which station |
| **service_type** | VARCHAR(100) | - | Maintenance, Repair, Calibration, Car Wash, Oil Change |
| **default_workflow_status** | ENUM | Pending | Pending, In-progress, Completed |
| **link_to_receivables** | TINYINT(1) | 1 | Auto-link to credit accounts if payment method is credit |
| **require_manager_approval** | TINYINT(1) | 0 | Require manager approval for this service? |
| **approval_threshold_amount** | DECIMAL(10,2) | 5000 | Require approval if amount exceeds this |
| **is_active** | TINYINT(1) | 1 | Is this service type active? |
| **created_at** | TIMESTAMP | NOW() | When created |
| **updated_at** | TIMESTAMP | NOW() | Last modified |
| **updated_by** | INT | - | Developer who made change |

### Example Use Case:
```
Cebu Station Services:
  - Car Wash: Default=Pending, Link receivables=Yes, Approval required=No
  - Calibration: Default=Pending, Link receivables=Yes, Approval required=Yes (>₱5,000)
  
Manila Station Services:
  - Oil Change: Default=In-progress, Approval required=Yes (>₱3,000)
  (Different services and approval rules)
```

---

## 💳 PAYMENTS Module

**Table:** `station_payment_config`

### Fields:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| **station_id** | INT | - | Which station |
| **payment_method** | VARCHAR(50) | - | Cash, Card, E-Wallet, Fleet/E-Fuel, Credit |
| **require_reference_number** | TINYINT(1) | 0 | Require reference number for this method? |
| **allow_partial_payment** | TINYINT(1) | 1 | Allow partial payments? |
| **payment_status_default** | ENUM | Pending | Paid, Pending |
| **audit_trail_enabled** | TINYINT(1) | 1 | Log all transactions with this method? |
| **is_enabled** | TINYINT(1) | 1 | Is this payment method enabled? |
| **created_at** | TIMESTAMP | NOW() | When created |
| **updated_at** | TIMESTAMP | NOW() | Last modified |
| **updated_by** | INT | - | Developer who made change |

### Example Use Case:
```
Cebu Station Payment Methods:
  - Cash: No ref#, Allow partial=Yes, Status=Paid, Enabled
  - Card: Ref# required, Allow partial=No, Status=Pending, Enabled
  - Fleet/E-Fuel: Ref# required, Allow partial=Yes, Status=Pending, Enabled
  - Credit: No ref#, Allow partial=Yes, Status=Pending, Enabled
  
Manila Station:
  - Cash: Enabled
  - Card: Disabled (no card terminal)
```

---

## 📦 INVENTORY Module

**Table:** `station_inventory_config`

### Fields:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| **station_id** | INT | - | Which station (one config per station) |
| **stock_movement_rule** | ENUM | FIFO | FIFO (First-In-First-Out), LIFO (Last-In-First-Out), FEFO (First-Expired-First-Out) |
| **auto_update_on_delivery** | TINYINT(1) | 1 | Auto update stock when delivery validated? |
| **auto_update_on_sale** | TINYINT(1) | 1 | Auto deduct stock when transaction completed? |
| **adjustment_require_approval** | TINYINT(1) | 1 | Manual adjustments need manager approval? |
| **low_stock_alert_enabled** | TINYINT(1) | 1 | Send low stock alerts? |
| **low_stock_threshold** | INT | 10 | Default threshold for alerts |
| **audit_trail_enabled** | TINYINT(1) | 1 | Log all inventory movements? |
| **allow_negative_stock** | TINYINT(1) | 0 | Allow sales when stock is 0? |
| **created_at** | TIMESTAMP | NOW() | When created |
| **updated_at** | TIMESTAMP | NOW() | Last modified |
| **updated_by** | INT | - | Developer who made change |

### Example Use Case:
```
Cebu Station Inventory Rules:
  - Movement: FIFO
  - Auto-update on delivery: Yes
  - Auto-update on sale: Yes
  - Adjustments need approval: Yes
  - Low stock alerts: Yes (threshold: 10)
  - Allow negative stock: No
  
Manila Station:
  - Movement: FEFO (for perishable goods)
  - Allow negative stock: Yes (to prevent blocking sales)
```

---

## 📅 CALENDAR Module

**Table:** `station_calendar_config`

### Fields:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| **station_id** | INT | - | Which station (one config per station) |
| **shift_schedule_enabled** | TINYINT(1) | 1 | Enable shift scheduling? |
| **delivery_schedule_enabled** | TINYINT(1) | 1 | Enable delivery scheduling? |
| **calibration_events_enabled** | TINYINT(1) | 1 | Enable calibration event tracking? |
| **system_notifications_enabled** | TINYINT(1) | 1 | Send calendar notifications? |
| **default_shift_hours** | VARCHAR(50) | 8:00 AM - 5:00 PM | Default shift time range |
| **notification_lead_time_hours** | INT | 24 | Hours before event to send notification |
| **created_at** | TIMESTAMP | NOW() | When created |
| **updated_at** | TIMESTAMP | NOW() | Last modified |
| **updated_by** | INT | - | Developer who made change |

### Example Use Case:
```
Cebu Station Calendar:
  - Shifts: Enabled (8:00 AM - 5:00 PM default)
  - Deliveries: Enabled
  - Calibrations: Enabled
  - Notifications: Enabled (24 hours before)
  
Manila Station:
  - Shifts: Enabled (24/7 - 3 shifts)
  - Notifications: Enabled (48 hours before)
```

---

## 📊 REPORTS Module

**Table:** `station_report_config`

### Fields:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| **station_id** | INT | - | Which station |
| **report_type** | VARCHAR(100) | - | Sales, Variance, Compliance, Inventory, Transaction Summary |
| **computation_formula** | TEXT | - | Custom formulas for calculations |
| **export_format_excel** | TINYINT(1) | 1 | Allow Excel export? |
| **export_format_pdf** | TINYINT(1) | 1 | Allow PDF export? |
| **role_access_staff** | TINYINT(1) | 0 | Can Staff view this report? |
| **role_access_manager** | TINYINT(1) | 1 | Can Manager view this report? |
| **role_access_admin** | TINYINT(1) | 1 | Can Admin view this report? |
| **is_enabled** | TINYINT(1) | 1 | Is this report type enabled? |
| **created_at** | TIMESTAMP | NOW() | When created |
| **updated_at** | TIMESTAMP | NOW() | Last modified |
| **updated_by** | INT | - | Developer who made change |

### Example Use Case:
```
Cebu Station Reports:
  - Sales Report: Enabled, Excel+PDF, Manager+Admin can view
  - Variance Report: Enabled, Excel+PDF, Admin only
  - Compliance Report: Enabled, PDF only, Admin only
  
Manila Station:
  - Sales Report: Enabled, Staff+Manager+Admin can view
  - Inventory Report: Enabled, Manager+Admin only
```

---

## 🔐 AUDIT TRAIL

**Table:** `station_module_audit`

### Fields:

| Field | Type | Description |
|-------|------|-------------|
| **id** | INT | Unique log ID |
| **station_id** | INT | Which station was affected |
| **module_key** | VARCHAR(50) | Which module (fuel_management, inventory, etc.) |
| **config_table** | VARCHAR(100) | Which configuration table was modified |
| **action** | ENUM | enable, disable, configure, create, update, delete |
| **field_changed** | VARCHAR(100) | Which specific field was changed |
| **old_value** | TEXT | Value before change |
| **new_value** | TEXT | Value after change |
| **developer_id** | INT | Who made the change |
| **developer_name** | VARCHAR(100) | Developer's full name |
| **ip_address** | VARCHAR(45) | IP address of developer |
| **created_at** | TIMESTAMP | When the change was made |

### Example Log Entries:
```
2026-06-14 10:30:00 | Cebu Station | fuel_management | enable | John Developer | 192.168.1.100
2026-06-14 10:35:00 | Cebu Station | fuel_management | configure | official_price_per_liter | 65.00 → 65.50 | John Developer
2026-06-14 10:40:00 | Manila Station | inventory | disable | John Developer | 192.168.1.100
```

---

## 🔄 Data Flow Example

### Scenario: Enable Fuel Management for Cebu Station

**Step 1: Developer Opens Module Configuration**
```
GET /backend/api/station_module_api.php?action=get_stations
→ Returns list of all stations with module counts
```

**Step 2: Developer Selects Cebu Station**
```
Clicks "Configure Modules" button for Cebu
GET /backend/api/station_module_api.php?action=get_station_modules&station_id=1
→ Returns all modules for Cebu with enabled/disabled status
```

**Step 3: Developer Toggles Fuel Management ON**
```
POST /backend/api/station_module_api.php
action=toggle_module
station_id=1
module_key=fuel_management
enabled=1
csrf_token=...

→ Updates station_modules table
→ Logs to station_module_audit
→ Returns success message
```

**Step 4: Developer Configures Fuel Settings**
```
GET /backend/api/station_module_api.php?action=get_fuel_config&station_id=1
→ Returns fuel types for Cebu

Developer updates Diesel price:
POST /backend/api/station_module_api.php
action=update_fuel_config
station_id=1
id=5
field=official_price_per_liter
value=65.50

→ Updates station_fuel_config table
→ Logs to station_module_audit
→ Returns success
```

**Step 5: Changes Cascade to Users**
```
When Staff at Cebu logs in:
- System checks: hasModuleAccess($user_id, 'fuel_management')
- Query: SELECT is_enabled FROM station_modules WHERE station_id=1 AND module_key='fuel_management'
- Result: 1 (enabled)
- Action: Show "Fuel Management" menu in sidebar
```

---

## ✅ Implementation Checklist

### Phase 1: Database Setup ⏳
- [ ] Run `complete_station_module_config.sql` in phpMyAdmin
- [ ] Verify tables created (9 tables)
- [ ] Verify default data populated (all stations, all modules)
- [ ] Check foreign key constraints working

### Phase 2: Backend API ✅
- [x] Create `station_module_api.php`
- [x] Implement `get_stations` endpoint
- [x] Implement `get_station_modules` endpoint
- [x] Implement `toggle_module` endpoint
- [x] Implement `get_fuel_config` endpoint
- [x] Implement `update_fuel_config` endpoint
- [x] Implement audit logging for all changes

### Phase 3: Frontend UI ⏳
- [ ] Update `module_configuration.php` to show station list
- [ ] Add "Configure Modules" modal per station
- [ ] Create detailed configuration panels for each module:
  - [ ] Fuel Management configuration panel
  - [ ] Merchandise configuration panel
  - [ ] Job Orders configuration panel
  - [ ] Payments configuration panel
  - [ ] Inventory configuration panel
  - [ ] Calendar configuration panel
  - [ ] Reports configuration panel
- [ ] Add audit log viewer

### Phase 4: Access Control ⏳
- [ ] Create `hasModuleAccess($user_id, $module_key)` helper
- [ ] Update sidebar menu rendering
- [ ] Filter dashboard widgets by module access
- [ ] Redirect if accessing disabled module

### Phase 5: Testing ⏳
- [ ] Test enabling/disabling modules per station
- [ ] Test configuring each module type
- [ ] Verify cascade to user menus
- [ ] Test audit trail logging
- [ ] Test with multiple stations simultaneously

---

## 📂 Files Created

| File | Status | Description |
|------|--------|-------------|
| `database/complete_station_module_config.sql` | ✅ | Complete database schema with all 9 tables |
| `backend/api/station_module_api.php` | ✅ | Complete backend API with 10 endpoints |
| `COMPLETE_MODULE_CONFIG_FIELDS.md` | ✅ | This documentation file |

---

## 🎯 Key Benefits

### Station Independence
- ✅ Each station configures only what they need
- ✅ Small stations = simple config
- ✅ Large stations = full features
- ✅ Test features at pilot stations first

### Granular Control
- ✅ Per-station fuel prices
- ✅ Per-station merchandise catalogs
- ✅ Per-station payment methods
- ✅ Per-station approval workflows

### Complete Audit Trail
- ✅ Track every change (who, what, when, where)
- ✅ Compliance and accountability
- ✅ Rollback capability (see old values)
- ✅ Security monitoring

### Flexible Deployment
- ✅ Gradual rollout of new features
- ✅ Region-specific configurations
- ✅ Disable problematic modules quickly
- ✅ A/B testing at branch level

---

**Status:** Database + API Complete ✅ | Frontend UI In Progress ⏳  
**Next Step:** Build comprehensive frontend UI with detailed configuration panels  
**Priority:** High  
**Time Estimate:** 8-12 hours for complete UI implementation

**Kompletong fields na! Station-dependent na! With full audit trail! 🎯✅**
