# Service Management Module Specification

## Overview
Complete service management system for Manager role with dashboard cards, search/filters, CRUD operations (except Delete), and inventory integration.

## Page Structure

### 1. Dashboard Cards (Top Section)
- **Total Services** - Count of all services
- **Active Services** - Count of active services  
- **Inactive Services** - Count of inactive services
- **Categories** - Count of unique service categories

### 2. Search & Filters
- **Search Service** - Text input to search by service name or code
- **Category Filter** - Dropdown to filter by service category
- **Status Filter** - Dropdown (All, Active, Inactive)
- **Reset Button** - Clear all filters

### 3. Service Categories
Pre-defined comprehensive categories for gas station services:

#### Preventive Maintenance
- Scheduled maintenance services
- Regular check-ups
- Routine inspections

#### Oil & Lubrication Services
- Oil Change (Engine Oil)
- Oil Filter Replacement
- Transmission Oil Change
- Differential Oil Change
- Power Steering Fluid Service

#### Engine Services
- Tune-up
- Engine Diagnostic
- Spark Plug Replacement
- Air Filter Replacement
- Fuel Filter Replacement
- Engine Overhaul
- Timing Belt Replacement

#### Brake Services
- Brake Inspection
- Brake Pad Replacement
- Brake Fluid Change
- Brake Rotor Resurfacing
- Brake System Bleeding
- Hand Brake Adjustment

#### Tire Services
- Tire Replacement
- Tire Rotation
- Tire Balancing
- Wheel Alignment
- Tire Pressure Check
- Tire Repair/Patching
- Nitrogen Filling

#### Battery Services
- Battery Replacement
- Battery Testing
- Battery Cleaning
- Battery Terminal Service
- Alternator Testing

#### Cooling System
- Radiator Flush
- Coolant Replacement
- Radiator Repair
- Thermostat Replacement
- Water Pump Replacement

#### Electrical Services
- Electrical Diagnostic
- Wiper Blade Replacement
- Headlight Bulb Replacement
- Fuse Replacement
- Alternator Repair
- Starter Motor Service

#### Air Conditioning
- A/C System Check
- A/C Gas Refill
- A/C Compressor Service
- A/C Filter Replacement

#### Undercarriage Services
- Undercoating
- Rust Protection
- Suspension Check
- Shock Absorber Replacement

#### Cleaning Services
- Aircon Cleaning
- Engine Cleaning
- Undercarriage Cleaning
- Full Car Wash
- Interior Detailing

#### Emergency Services
- Jump Start
- Tire Change Assistance
- Towing Service
- Fuel Delivery

#### Custom Services
- Custom/Other Services
- Special Requests
- Package Deals

**Total Categories:** 13 main categories with 60+ specific service types

### 4. Main Table Columns
| Column | Description | Width | Align |
|--------|-------------|-------|-------|
| Service Code | Auto-generated unique code (SRV-XXXX) | 10% | Left |
| Service Name | Name of the service | 25% | Left |
| **Category** | Service category (bold/prominent) | 20% | Left |
| Standard Fee | Base price for the service | 12% | Right |
| Estimated Time | Duration in minutes | 12% | Center |
| Status | Active/Inactive badge | 8% | Center |
| Actions | View, Edit, Archive buttons | 13% | Center |

**Note:** The Category column should be prominently displayed with:
- Bold text or colored badge
- Icon representing the category type
- Truncated with ellipsis if too long (show tooltip on hover)

**Example Table Row:**
```
| SRV-0015 | Engine Oil Change | 🔧 Oil & Lubrication Services | ₱3,800.00 | 45 min | 🟢 Active | [👁 View] [✏ Edit] [📦 Archive] |
```

### 5. Actions
- **View** (👁) - View service details in modal
- **Edit** (✏) - Edit service information
- **Archive** (📦) - Soft delete/archive service
- **No Delete** - Hard delete is not allowed

### 6. Add/Edit Service Form Fields

#### Basic Information
- **Service Code** - Auto-generated, read-only (Format: SRV-0001, SRV-0002, etc.)
- **Service Name** - Required text input (Max 255 characters)
- **Category** - Required dropdown with searchable options from the comprehensive categories list above
  - Should support autocomplete/search functionality
  - Display category name with optional icon
  - Grouped by main category for better UX

#### Service Details
- **Standard Price** - Required number input (PHP currency format: ₱0.00)
  - Min: ₱0.00, Max: ₱99,999.99
- **Estimated Duration** - Required number input (minutes)
  - Min: 5 minutes, Max: 480 minutes (8 hours)
  - Display format: "XX minutes" or "X hours XX minutes"
- **Description** - Optional textarea (Max 1000 characters)
  - Placeholder: "Describe the service, what's included, any special notes..."
- **Materials Required** - Optional multi-select with inventory integration
  - Can link to existing inventory items (products table)
  - Display: Product name, SKU, current stock
  - Examples from inventory:
    - Oil Filter (SKU: OF-001)
    - Engine Oil 5W-30 (SKU: EO-530)
    - Brake Fluid DOT 4 (SKU: BF-D4)
    - Spark Plugs NGK (SKU: SP-NGK)
  - Quantity per service can be specified

#### Status
- **Active** - Service is available for selection in transactions
- **Inactive** - Service is hidden/archived but data is preserved

#### Action Buttons
- **Save Service** - Primary button (Petron blue)
- **Cancel** - Secondary button (Gray)

### 7. View Service Modal
Read-only display of:
- Service Information
- Price
- Estimated Time
- Description
- Materials Used (if any)
- Service History (transactions using this service)

## Manager Permissions
✅ Add Service  
✅ Edit Service  
✅ Archive Service  
✅ View Service  
❌ Delete Service (no hard delete)

## Database Schema

### Table: `services`
```sql
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_code VARCHAR(20) UNIQUE NOT NULL,
    service_name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    standard_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estimated_duration INT NOT NULL DEFAULT 30 COMMENT 'Duration in minutes',
    description TEXT,
    materials_required TEXT COMMENT 'JSON array of inventory item IDs',
    status ENUM('active', 'inactive') DEFAULT 'active',
    station_id INT NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL,
    archived_by INT NULL,
    FOREIGN KEY (station_id) REFERENCES stations(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (archived_by) REFERENCES users(id)
);
```

### Table: `service_materials` (Optional linking table)
```sql
CREATE TABLE IF NOT EXISTS service_materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(10,2) DEFAULT 1.00,
    FOREIGN KEY (service_id) REFERENCES services(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

## File Structure
- **File:** `public/manager_service_management.php`
- **Backend:** `backend/api/service_operations.php` (CRUD operations)
- **Permissions:** Manager role only

## Design Guidelines
- Use Petron blue (#002F70) for primary actions
- Status badges: Green for Active, Gray for Inactive
- Archive button: Orange/warning color
- Responsive table with fixed layout
- Modal for Add/Edit/View operations
- Confirmation prompt for Archive action

## Implementation Notes
1. Service codes are auto-generated: `SRV-0001`, `SRV-0002`, etc.
2. Archive sets `archived_at` timestamp and `status='inactive'`
3. Materials linking is optional (can be added later)
4. Search filters by service name, code, and category
5. Table pagination: 10 items per page
6. All timestamps use server timezone
