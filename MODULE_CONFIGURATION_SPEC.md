# Module Configuration – Developer Complete Functions

**Role:** Developer/SuperAdmin  
**Purpose:** Configure all system modules with complete control over settings, rules, and behaviors

---

## 📋 Module Configuration Structure

### 1. **Fuel Module Settings**

#### Configure Fuel Types
- Diesel
- Gasoline  
- Kerosene
- Add/Edit/Delete fuel types
- Set active/inactive status

#### Set Official Price Per Liter
- Price per fuel type
- **Audit trail** for all price changes
- Track who changed, when, old price, new price

#### Calibration Rules
- **Schedule:** Define calibration frequency (daily, weekly, monthly)
- **Variance tolerance:** Acceptable difference between expected vs actual (e.g., ±2%)
- **Reconciliation logic:** Auto-compute variance, flag anomalies

---

### 2. **Merchandise Module Settings**

#### Add/Edit Merchandise Catalog
- Product name
- Category (beverages, snacks, automotive products)
- Unit price
- Stock quantity
- SKU/Barcode

#### Set Unit Prices and Stock Rules
- Minimum stock level (low stock alert)
- Reorder point
- Maximum stock capacity

#### FIFO Inventory Logic
- **First-In-First-Out** automatic stock tracking
- Auto-update stock levels after:
  - Deliveries received
  - Sales transactions
- Audit trail for all stock movements

---

### 3. **Job Orders Module Settings**

#### Define Service Categories
- Maintenance
- Repair
- Calibration
- Custom service types

#### Configure Workflow Statuses
- Pending
- In-Progress
- Completed
- Cancelled
- On-Hold

#### Link Job Orders to Receivables
- If customer uses credit account
- Auto-create receivable entry
- Track payment status

---

### 4. **Payments Handling Settings**

#### Configure Payment Methods
- Cash
- Card (Credit/Debit)
- E-Wallet (GCash, PayMaya, etc.)
- Fleet Card / E-Fuel
- Credit Account

#### Rules for Payments
- **Partial vs Full Payments:**
  - Allow partial payments: Yes/No
  - Minimum partial payment amount
  - Maximum partial payment installments

#### Audit Trail for Payments
- Payment reference numbers
- Track all payment transactions
- Who received payment, when, amount, method

---

### 5. **Inventory Rules**

#### FIFO Logic for Merchandise
- Auto-calculate which stock batch to use first
- Track batch numbers and expiry dates
- Prevent selling expired items

#### Auto-Update Stock Levels
- **After deliveries:** Add to stock
- **After sales:** Deduct from stock
- **After returns:** Add back to stock

#### Low Stock Alerts Configuration
- Set minimum stock threshold per product
- Alert Manager/Admin when below threshold
- Option to auto-generate purchase order

---

### 6. **Calendar Module Settings**

#### Configure Shift Schedules
- Define shift times (Morning, Afternoon, Night)
- Assign staff to shifts
- Track shift changes

#### Deliveries Calendar
- Schedule fuel deliveries
- Schedule merchandise deliveries
- Notify relevant staff

#### Calibration Events
- Schedule regular calibration
- Set reminders before calibration due date
- Track calibration completion

#### Sync Across Roles
- **Staff:** View their assigned shifts
- **Manager:** View and manage all shifts
- **Admin:** Configure shift rules

---

### 7. **Reports Module Settings**

#### Define Computation Formulas
- **Variance:** Expected - Actual = Variance
- **Sales:** Total revenue per period
- **Compliance:** % of completed vs required calibrations

#### Enable/Disable Report Types Per Role
- Staff: Limited reports (their own transactions)
- Manager: Station-wide reports
- Admin: All station reports + comparisons
- SuperAdmin: System-wide reports

#### Export Rules
- **Excel:** All reports can be exported to Excel
- **PDF:** All reports can be exported to PDF
- Set permissions per role

---

### 8. **Enable/Disable Modules**

#### Module Control
Developer can **activate/deactivate** entire modules:
- ✅ **Transactions** (Point of Sale)
- ✅ **Job Orders** (Service management)
- ✅ **Fuel Management** (Readings, reconciliation)
- ✅ **Calendar** (Shift scheduling)
- ✅ **Reports** (Analytics and compliance)
- ✅ **Inventory** (Stock management)
- ✅ **Customers** (Customer database)
- ✅ **Deliveries** (Delivery tracking)

#### Adjust Per Module
- **Computation formulas:** Change how variance, sales, etc. are calculated
- **Audit rules:** What gets logged, how detailed
- **Permissions:** Who can access what within the module

---

## 🎯 Implementation Requirements

### User Interface Structure

```
MODULE CONFIGURATION
└── Control and Customize System Modules

    ┌─────────────────────────────────────┐
    │ MODULE STATUS                       │
    ├─────────────────────────────────────┤
    │                                     │
    │ [Transactions]         [ON/OFF]    │
    │ Point of Sale management            │
    │ ⚙️ Configure  📋 Audit Log         │
    │                                     │
    │ [Job Orders]           [ON/OFF]    │
    │ Service and maintenance             │
    │ ⚙️ Configure  📋 Audit Log         │
    │                                     │
    │ [Fuel Management]      [ON/OFF]    │
    │ Fuel inventory and reconciliation   │
    │ ⚙️ Configure  📋 Audit Log         │
    │                                     │
    │ [Calendar]             [ON/OFF]    │
    │ Shift scheduling                    │
    │ ⚙️ Configure  📋 Audit Log         │
    │                                     │
    │ [Reports]              [ON/OFF]    │
    │ System reports and analytics        │
    │ ⚙️ Configure  📋 Audit Log         │
    │                                     │
    │ [Inventory]            [ON/OFF]    │
    │ Merchandise and fuel inventory      │
    │ ⚙️ Configure  📋 Audit Log         │
    │                                     │
    └─────────────────────────────────────┘
```

---

### Configuration Panels

#### Example: Fuel Module Configuration

```
FUEL MODULE SETTINGS
├── Fuel Types
│   ├── Diesel         [Active ✓]  [Edit] [Delete]
│   ├── Gasoline       [Active ✓]  [Edit] [Delete]
│   ├── Kerosene       [Active ✓]  [Edit] [Delete]
│   └── [+ Add New Fuel Type]
│
├── Official Prices (with audit trail)
│   ├── Diesel:    ₱XX.XX  [Update Price]
│   ├── Gasoline:  ₱XX.XX  [Update Price]
│   └── Kerosene:  ₱XX.XX  [Update Price]
│
├── Calibration Rules
│   ├── Schedule:           [Daily ▼]
│   ├── Variance Tolerance: [±2%]
│   └── Reconciliation:     [Auto-compute ✓]
│
└── [Save Settings]
```

---

## 📊 Database Schema (Module Configuration)

### Table: `module_config`
```sql
CREATE TABLE module_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(50) NOT NULL UNIQUE,
    module_name VARCHAR(100) NOT NULL,
    module_description TEXT,
    is_enabled TINYINT(1) DEFAULT 1,
    settings JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    FOREIGN KEY (updated_by) REFERENCES users(id)
);
```

### Table: `module_settings`
```sql
CREATE TABLE module_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(50) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'integer', 'decimal', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    category VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_module_setting (module_key, setting_key),
    FOREIGN KEY (module_key) REFERENCES module_config(module_key) ON DELETE CASCADE
);
```

### Table: `module_audit_log`
```sql
CREATE TABLE module_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(50) NOT NULL,
    action_type ENUM('enable', 'disable', 'update', 'create', 'delete'),
    setting_key VARCHAR(100),
    old_value TEXT,
    new_value TEXT,
    user_id INT NOT NULL,
    user_role VARCHAR(20),
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_key) REFERENCES module_config(module_key) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_module_created (module_key, created_at DESC)
);
```

---

## ✅ Features Checklist

### Core Functions
- [ ] Enable/Disable individual modules
- [ ] Configure module-specific settings
- [ ] View audit trail per module
- [ ] Export module configuration
- [ ] Bulk update settings

### Fuel Module
- [ ] Add/Edit/Delete fuel types
- [ ] Set prices with audit trail
- [ ] Configure calibration rules
- [ ] Set variance tolerance
- [ ] Define reconciliation logic

### Merchandise Module
- [ ] Manage product catalog
- [ ] Set unit prices
- [ ] Define stock rules
- [ ] Configure FIFO logic
- [ ] Set low stock alerts

### Job Orders Module
- [ ] Define service categories
- [ ] Configure workflow statuses
- [ ] Link to receivables
- [ ] Set pricing rules

### Payments Module
- [ ] Configure payment methods
- [ ] Set partial payment rules
- [ ] Define audit trail fields

### Inventory Module
- [ ] Configure FIFO logic
- [ ] Set auto-update rules
- [ ] Define alert thresholds

### Calendar Module
- [ ] Define shift schedules
- [ ] Configure delivery calendar
- [ ] Set calibration events
- [ ] Role-based sync settings

### Reports Module
- [ ] Define formulas
- [ ] Set role permissions
- [ ] Configure export options

---

## 🔐 Security & Access Control

### Role Permissions
- **Developer/SuperAdmin:** Full access to all configuration
- **Admin:** Read-only access (can view but not modify)
- **Manager:** No access
- **Staff:** No access

### Audit Requirements
- Log all configuration changes
- Track who made the change
- Track when the change was made
- Track old vs new values
- IP address tracking

---

**Status:** Specification Complete  
**Next:** Implement UI and backend API  
**Priority:** High  
**Estimated Time:** 8-12 hours development
