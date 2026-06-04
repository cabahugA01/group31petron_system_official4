# Design Document

## Overview

The Staff Inventory Module provides a comprehensive interface for staff members to manage merchandise and fuel inventory, submit automated stock requests for low-stock items, record deliveries, and track the complete lifecycle of inventory transactions. The module integrates with the Manager Inventory Module for request validation and Admin oversight, maintaining a clear separation of concerns and role-based workflows.

## Data Models

### Stock Request Model
```typescript
interface StockRequest {
  id: number;
  staff_id: number;
  station_id: number;
  item_id: number;
  item_sku: string;
  item_name: string;
  item_category: string;
  current_stock: number;
  requested_quantity: number;
  approved_quantity?: number;
  remarks?: string;
  status: 'Pending' | 'Validated' | 'Approved' | 'Rejected';
  manager_id?: number;
  manager_notes?: string;
  processed_at?: Date;
  created_at: Date;
  updated_at: Date;
}
```

### Merchandise Item Model
```typescript
interface MerchandiseItem {
  id: number;
  station_id: number;
  product_id: number;
  sku: string;
  name: string;
  category: string;
  stock_level: number;
  cost: number;
  price: number;
  reorder_level: number;
  capacity: number;
  unit: string;
  status: 'active' | 'inactive';
}
```

### Fuel Inventory Model
```typescript
interface FuelInventory {
  id: number;
  station_id: number;
  fuel_type_id: number;
  fuel_type_name: string;
  stock_level: number;
  reorder_level: number;
  capacity: number;
  last_reading: number;
  last_reading_date: Date;
  status: 'active' | 'inactive';
}
```

### Delivery Model
```typescript
interface Delivery {
  id: number;
  station_id: number;
  supplier: string;
  po_reference?: string;
  invoice_no?: string;
  delivery_date: Date;
  received_by: number;
  verified_by?: number;
  verified_at?: Date;
  notes?: string;
  status: 'Pending' | 'Verified' | 'Finalized' | 'Rejected';
  created_at: Date;
}

interface DeliveryItem {
  id: number;
  delivery_id: number;
  product_id: number;
  delivered_quantity: number;
  unit_price?: number;
  line_total?: number;
}
```

## Architecture

### System Components

```
┌─────────────────────────────────────────────────────────────────┐
│                    Staff Inventory System                        │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌──────────────────┐  ┌────────────────┐ │
│  │   Navigation    │  │  Summary Cards   │  │  Data Layer    │ │
│  │   Controller    │  │   Generator      │  │   Manager      │ │
│  └─────────────────┘  └──────────────────┘  └────────────────┘ │
│                                                                  │
│  ┌─────────────────┐  ┌──────────────────┐  ┌────────────────┐ │
│  │  Merchandise    │  │  Fuel Inventory  │  │ Stock Request  │ │
│  │  Inventory Mgr  │  │    Manager       │  │   Generator    │ │
│  └─────────────────┘  └──────────────────┘  └────────────────┘ │
│                                                                  │
│  ┌─────────────────┐  ┌──────────────────┐  ┌────────────────┐ │
│  │  Stock-In       │  │  Inventory       │  │  Validation    │ │
│  │  Recorder       │  │  History Tracker │  │  Engine        │ │
│  └─────────────────┘  └──────────────────┘  └────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│                       Database Layer                             │
├─────────────────────────────────────────────────────────────────┤
│  • station_inventory (merchandise stock)                        │
│  • fuel_inventory (fuel stock levels)                           │
│  • stock_requests (pending/approved/completed requests)         │
│  • fuel_deliveries (fuel delivery records)                      │
│  • merchandise_deliveries (merchandise delivery records)        │
│  • inventory_transactions (audit trail)                         │
└─────────────────────────────────────────────────────────────────┘
```

### Technology Stack

- **Frontend**: HTML5, CSS3, JavaScript (ES6+), AJAX for async operations
- **Backend**: PHP 8.x with MySQLi
- **Database**: MySQL/MariaDB
- **Session Management**: PHP sessions with role-based access control
- **UI Framework**: Custom CSS with responsive design patterns


## Database Schema

### Existing Tables (No Modifications Required)

#### stock_requests
```sql
CREATE TABLE stock_requests (
  id INT(11) PRIMARY KEY AUTO_INCREMENT,
  staff_id INT(11) NOT NULL,
  station_id INT(11) NOT NULL,
  item_id INT(11) DEFAULT NULL,
  item_sku VARCHAR(100) NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  item_category VARCHAR(100) NOT NULL,
  current_stock INT(11) NOT NULL DEFAULT 0,
  requested_quantity INT(11) NOT NULL,
  remarks TEXT DEFAULT NULL,
  status ENUM('Pending','Approved','Validated') DEFAULT 'Pending',
  approved_quantity INT(11) DEFAULT NULL,
  manager_id INT(11) DEFAULT NULL,
  manager_notes TEXT DEFAULT NULL,
  processed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  approved_price DECIMAL(10,2) DEFAULT NULL
);
```

#### fuel_deliveries
```sql
CREATE TABLE fuel_deliveries (
  id INT(11) PRIMARY KEY AUTO_INCREMENT,
  station_id INT(11) DEFAULT NULL,
  delivery_date DATE DEFAULT NULL,
  fuel_type VARCHAR(50) DEFAULT NULL,
  supplier VARCHAR(100) DEFAULT NULL,
  invoice_no VARCHAR(50) DEFAULT NULL,
  delivery_liters DECIMAL(10,2) DEFAULT NULL,
  tanker_number VARCHAR(50) DEFAULT NULL,
  received_by INT(11) DEFAULT NULL,
  verified_by INT(11) DEFAULT NULL,
  verified_at DATETIME DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  status VARCHAR(20) DEFAULT 'Pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

#### station_inventory
```sql
CREATE TABLE station_inventory (
  id INT(11) PRIMARY KEY AUTO_INCREMENT,
  station_id INT(11) NOT NULL,
  product_id INT(11) NOT NULL,
  stock_level DECIMAL(12,2) DEFAULT 0.00,
  cost DECIMAL(10,2) DEFAULT NULL,
  price DECIMAL(10,2) DEFAULT NULL,
  closing_stock DECIMAL(12,2) DEFAULT NULL,
  closing_date DATE DEFAULT NULL,
  closing_shift VARCHAR(20) DEFAULT NULL,
  reorder_level INT(11) DEFAULT 0,
  capacity DECIMAL(12,2) DEFAULT 10000.00,
  unit VARCHAR(50) DEFAULT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### fuel_inventory
```sql
CREATE TABLE fuel_inventory (
  id INT(11) PRIMARY KEY AUTO_INCREMENT,
  station_id INT(11) NOT NULL,
  fuel_type_id INT(11) NOT NULL,
  stock_level DECIMAL(12,2) DEFAULT 0.00,
  reorder_level DECIMAL(12,2) DEFAULT 5000.00,
  capacity DECIMAL(12,2) DEFAULT 20000.00,
  last_reading DECIMAL(12,2) DEFAULT 0.00,
  last_reading_date DATETIME DEFAULT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### New Tables Required

#### merchandise_deliveries
```sql
CREATE TABLE merchandise_deliveries (
  id INT(11) PRIMARY KEY AUTO_INCREMENT,
  station_id INT(11) NOT NULL,
  supplier VARCHAR(100) DEFAULT NULL,
  po_reference VARCHAR(50) DEFAULT NULL,
  invoice_no VARCHAR(50) DEFAULT NULL,
  delivery_date DATE DEFAULT NULL,
  received_by INT(11) DEFAULT NULL COMMENT 'Staff user ID',
  verified_by INT(11) DEFAULT NULL COMMENT 'Manager user ID',
  verified_at DATETIME DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  status VARCHAR(20) DEFAULT 'Pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (station_id) REFERENCES stations(id),
  FOREIGN KEY (received_by) REFERENCES users(id),
  FOREIGN KEY (verified_by) REFERENCES users(id)
);
```

#### merchandise_delivery_items
```sql
CREATE TABLE merchandise_delivery_items (
  id INT(11) PRIMARY KEY AUTO_INCREMENT,
  delivery_id INT(11) NOT NULL,
  product_id INT(11) NOT NULL,
  delivered_quantity DECIMAL(12,2) NOT NULL,
  unit_price DECIMAL(10,2) DEFAULT NULL,
  line_total DECIMAL(12,2) DEFAULT NULL,
  FOREIGN KEY (delivery_id) REFERENCES merchandise_deliveries(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
);
```

## Component Design

## Components and Interfaces

### 1. Navigation Controller

**Purpose**: Manages navigation between inventory tabs and handles back button functionality

**Key Methods**:
- `showTab(tabName)`: Displays the specified inventory tab
- `navigateBack()`: Returns to main inventory menu
- `initializeTabNavigation()`: Sets up tab click handlers

**Navigation Flow**:
```
Main Inventory Menu
├── Merchandise Inventory Tab
│   └── Add/Edit Merchandise Form
├── Fuel Inventory Tab
│   └── Add Fuel Reading Form
├── Stock Requests Tab
│   └── Request Details View
├── Stock-In Tab
│   ├── Fuel Delivery Form
│   └── Merchandise Delivery Form
└── Inventory History Tab
    └── Filtered History View
```

**Back Button Behavior**:
- Present on all sub-tabs and modal forms
- Returns to main inventory menu within 1 second
- Clears any unsaved form data with confirmation prompt


### 2. Summary Cards Generator

**Purpose**: Generates and updates dashboard summary statistics for each inventory tab

**Summary Card Structure**:
```php
class SummaryCard {
    string $title;
    string $value;
    string $subtitle;
    string $iconClass;
    string $colorClass;
}
```

**Summary Cards by Tab**:

**Merchandise Inventory**:
- Total merchandise items
- Low stock items count (stock_level <= reorder_level AND stock_level > 0)
- Out of stock items count (stock_level = 0)
- Items requiring attention (low + out of stock)

**Fuel Inventory**:
- Total fuel types
- Current fuel levels per type
- Low stock fuel types count
- Last reading timestamp

**Stock Requests**:
- Pending requests count (status = 'Pending')
- Approved requests count (status = 'Approved')
- Validated requests count (status = 'Validated')
- Completed requests count (linked to deliveries)

**Stock-In**:
- Deliveries today count
- Deliveries this week count
- Deliveries this month count
- Pending verification count

**Inventory History**:
- Total transactions count
- Transactions by status breakdown
- Recent activity count (last 7 days)

**Auto-Refresh Logic**:
- Poll server every 30 seconds for updated statistics
- Immediately refresh when inventory changes occur (after form submission)
- Display loading indicator during refresh
- Show error message if refresh fails, block tab access per Requirement 6.3


### 3. Merchandise Inventory Manager

**Purpose**: Handles displaying merchandise items with stock status highlighting

**Key Methods**:

```php
class MerchandiseInventoryManager {
    // Display all merchandise items in sortable table
    public function displayMerchandiseList(int $stationId): array;
    
    // Search merchandise by SKU or name
    public function searchMerchandise(string $query, int $stationId): array;
    
    // Get low stock items count
    public function getLowStockCount(int $stationId): int;
    
    // Get out of stock items count
    public function getOutOfStockCount(int $stationId): int;
}
```

**Display Features**:
- Read-only view of all merchandise items
- SKU, Product Name, Category, Current Stock, Stock Status
- Color-coded stock status:
  - Green: Stock OK (stock_level > reorder_level)
  - Yellow: Low Stock (stock_level <= reorder_level AND stock_level > 0)
  - Red: Out of Stock (stock_level = 0)
- Search by SKU, name, or category
- Sort by stock level, name, or category
- NO encode or edit functionality for staff

**Data Flow**:
1. Staff accesses Merchandise Inventory → System fetches current stock levels
2. System highlights low/out-of-stock items → Display in sortable table
3. Auto-refresh every 30 seconds → Update display

### 4. Fuel Inventory Manager

**Purpose**: Manages fuel pump readings and fuel stock levels

**Key Methods**:

```php
class FuelInventoryManager {
    // Display fuel reading encoding form
    public function showFuelReadingForm(): void;
    
    // Save fuel pump reading and update stock
    public function saveFuelReading(array $readingData): bool;
    
    // Calculate fuel usage between readings
    public function calculateFuelUsage(float $previousReading, float $currentReading): float;
    
    // Display current fuel stock levels for all types
    public function displayFuelLevels(int $stationId): array;
    
    // Get low stock fuel types
    public function getLowStockFuel(int $stationId): array;
    
    // Validate fuel reading data
    private function validateFuelReading(array $data): array;
}
```

**Form Fields**:
- Fuel Type (required, dropdown from fuel_inventory)
- Previous Reading (auto-populated from last_reading, DECIMAL(12,2))
- Current Reading (required, DECIMAL(12,2))
- Reading Date (required, DATE, cannot be future date)
- Transaction Reference (optional, TEXT)

**Validation Rules**:
- Current reading must be numeric
- If current reading < previous reading, display warning and require confirmation
- Reading date cannot be in the future
- All required fields must be filled

**Stock Level Calculation**:
```
fuel_usage = current_reading - previous_reading
new_stock_level = current_stock_level - fuel_usage
```

**Data Flow**:
1. Staff selects fuel type → System auto-populates previous reading
2. Staff enters current reading → System calculates usage
3. Staff submits → Server validates → Update fuel_inventory.stock_level
4. Update last_reading and last_reading_date → Refresh display

### 5. Stock Request Generator

**Purpose**: Automatically identifies low-stock items and generates stock requests

**Key Methods**:

```php
class StockRequestGenerator {
    // Identify low/out of stock merchandise items
    public function identifyLowStockMerchandise(int $stationId): array;
    
    // Identify low/out of stock fuel types
    public function identifyLowStockFuel(int $stationId): array;
    
    // Generate stock request for selected items
    public function generateStockRequest(array $items, int $staffId, int $stationId): bool;
    
    // Check for duplicate requests (same item, same day)
    private function checkDuplicateRequest(int $itemId, int $stationId, string $date): bool;
    
    // Calculate suggested quantity based on reorder level and capacity
    private function calculateSuggestedQuantity(float $currentStock, float $reorderLevel, float $capacity): int;
}
```

**Low Stock Detection Logic**:

**Merchandise**:
```sql
SELECT si.*, p.name, p.sku, p.category 
FROM station_inventory si
JOIN products p ON si.product_id = p.id
WHERE si.station_id = ? 
  AND si.stock_level <= si.reorder_level
  AND si.status = 'active'
ORDER BY si.stock_level ASC
```

**Fuel**:
```sql
SELECT fi.*, ft.name as fuel_type_name
FROM fuel_inventory fi
JOIN fuel_types ft ON fi.fuel_type_id = ft.id
WHERE fi.station_id = ?
  AND fi.stock_level <= fi.reorder_level
  AND fi.status = 'active'
ORDER BY fi.stock_level ASC
```

**Stock Request Button Display Logic**:
- Show button ONLY when low-stock or out-of-stock items exist
- Display count of items requiring replenishment next to button
- Hide button when all items are adequately stocked

**Request Generation Flow**:
1. Staff clicks Stock Request button
2. System queries low-stock items (merchandise or fuel depending on tab)
3. Display filtered list with current stock and suggested quantity
4. Staff can adjust remarks but NOT quantity (auto-calculated)
5. Staff confirms → System creates stock_requests records
6. Set status = 'Pending' ONLY after successful submission per Requirement 3.4
7. Duplicate check: Prevent multiple requests for same item on same day per Requirement 3.6
8. Route to Manager_Review_Interface

**Suggested Quantity Calculation**:
```php
function calculateSuggestedQuantity($currentStock, $reorderLevel, $capacity) {
    // Calculate the amount needed to reach optimal stock level (80% of capacity)
    $optimalStock = $capacity * 0.8;
    $suggestedQuantity = $optimalStock - $currentStock;
    
    // Round up to nearest whole number
    return max(1, ceil($suggestedQuantity));
}
```

**Phantom Request Handling**:
- Retain requests tagged as Pending due to system errors for manual review per Requirement 3.8
- Admin or Manager can identify phantom requests by checking created_at vs processed_at timestamps
- Provide manual cleanup interface in Manager module

### 6. Stock-In Recorder

**Purpose**: Records actual deliveries received and updates inventory stock levels

**Key Methods**:

```php
class StockInRecorder {
    // Display fuel delivery form
    public function showFuelDeliveryForm(): void;
    
    // Display merchandise delivery form
    public function showMerchandiseDeliveryForm(): void;
    
    // Save fuel delivery record
    public function saveFuelDelivery(array $deliveryData): bool;
    
    // Save merchandise delivery record
    public function saveMerchandiseDelivery(array $deliveryData, array $items): bool;
    
    // Update inventory stock levels after delivery
    private function updateInventoryStock(int $itemId, float $quantity, string $itemType): bool;
    
    // Validate delivery data
    private function validateDeliveryData(array $data): array;
}
```

**Fuel Delivery Form Fields**:
- PO Reference (optional, VARCHAR(50))
- Supplier (required, VARCHAR(100))
- Fuel Type (required, dropdown from fuel_types)
- Delivered Liters (required, DECIMAL(10,2), must be positive)
- Tanker Number (required, VARCHAR(50))
- Invoice Number (optional, VARCHAR(50))
- Delivery Date (required, DATE, cannot be future date)
- Notes (optional, TEXT)

**Merchandise Delivery Form Fields**:
- PO Reference (optional, VARCHAR(50))
- Supplier (required, VARCHAR(100))
- Invoice Number (optional, VARCHAR(50))
- Delivery Date (required, DATE, cannot be future date)
- Items (repeatable section):
  - Product (required, dropdown from products)
  - Delivered Quantity (required, DECIMAL(12,2), must be positive)
  - Unit Price (optional, DECIMAL(10,2))
- Notes (optional, TEXT)

**Validation Rules**:
- Delivered quantity must be positive (block submit button if negative per Requirement 8.2)
- Delivery date cannot be in the future
- All required fields must be filled
- Display partial validation as fields are completed
- Preserve user input on validation failure

**Data Flow - Fuel Delivery**:
1. Staff fills fuel delivery form → Client-side validation
2. AJAX POST to save endpoint → Server validates
3. Create Delivery_Record in fuel_deliveries ONLY if valid per Requirement 4.3
4. If Delivery_Record created successfully → Update fuel_inventory.stock_level
5. If Delivery_Record fails → Prevent inventory update per Requirement 4.4
6. Set status = 'Pending' (awaits Manager verification)
7. Refresh display and summary cards

**Data Flow - Merchandise Delivery**:
1. Staff fills merchandise delivery form with multiple items
2. AJAX POST to save endpoint → Server validates all items
3. Begin transaction → Create record in merchandise_deliveries
4. For each item → Create record in merchandise_delivery_items
5. If all records created successfully → Update station_inventory.stock_level for each item
6. Commit transaction → Set status = 'Pending'
7. If any step fails → Rollback transaction, prevent inventory update
8. Refresh display and summary cards

### 7. Inventory History Tracker

**Purpose**: Maintains complete audit trail of all inventory transactions and status transitions

**Key Methods**:

```php
class InventoryHistoryTracker {
    // Display inventory history with filters
    public function displayHistory(int $stationId, array $filters): array;
    
    // Get status transitions for a specific request
    public function getStatusTransitions(int $requestId): array;
    
    // Filter history by date range
    public function filterByDateRange(string $startDate, string $endDate, int $stationId): array;
    
    // Filter history by status
    public function filterByStatus(string $status, int $stationId): array;
    
    // Filter history by item type (fuel or merchandise)
    public function filterByItemType(string $itemType, int $stationId): array;
    
    // Export history to CSV or PDF
    public function exportHistory(array $filters, string $format): string;
}
```

**History Data Sources**:
- stock_requests (status transitions: Pending → Validated → Completed)
- fuel_deliveries (delivery records with verification status)
- merchandise_deliveries (delivery records with verification status)
- purchase_orders (if linked via PO reference)
- inventory_transactions (stock level changes)

**Display Columns**:
- Transaction ID
- Date/Time
- Transaction Type (Stock Request, Fuel Delivery, Merchandise Delivery, Stock Update)
- Item Name/SKU
- Quantity/Liters
- Status (Pending, Approved, Validated, Completed, Verified)
- Staff Member (requesting staff)
- Manager (if validated)
- Notes/Remarks

**Status Lifecycle Display**:
```
Stock Request:
Pending → [Manager validates] → Validated → [Admin approves PO] → Approved → [Delivery received] → Completed

Delivery:
Pending → [Manager verifies] → Verified → [Admin finalizes] → Finalized
```

**Filtering Options**:
- Date Range: Start Date to End Date
- Status: All, Pending, Approved, Validated, Completed, Verified
- Item Type: All, Fuel, Merchandise
- Requesting Staff: Dropdown of staff members at station

**Sort Options**:
- Date (newest first - default)
- Date (oldest first)
- Status
- Item Name
- Quantity

**Read-Only Access**:
- Staff, Manager, and Admin can view history per Requirement 5.4
- No edit or delete functionality in this interface
- Direct links to original records for authorized users

### 8. Data Layer Manager

**Purpose**: Centralized database operations and query management

**Key Methods**:

```php
class DataLayerManager {
    private $conn; // MySQLi connection
    
    // Get station inventory items
    public function getStationInventory(int $stationId): array;
    
    // Get fuel inventory levels
    public function getFuelInventory(int $stationId): array;
    
    // Get stock requests by status
    public function getStockRequestsByStatus(int $stationId, string $status): array;
    
    // Get deliveries by date range
    public function getDeliveriesByDateRange(int $stationId, string $startDate, string $endDate): array;
    
    // Update stock level
    public function updateStockLevel(int $itemId, float $quantity, string $itemType): bool;
    
    // Create stock request
    public function createStockRequest(array $requestData): int;
    
    // Create delivery record
    public function createDeliveryRecord(array $deliveryData, string $deliveryType): int;
    
    // Log inventory transaction
    public function logInventoryTransaction(array $transactionData): bool;
}
```

**Database Connection**:
- Use existing db_connect.php for connection management
- Use prepared statements for all queries
- Implement transaction support for multi-step operations
- Handle connection failures gracefully with user-friendly error messages

**Error Handling**:
- Catch all database exceptions
- Log errors to system log
- Display user-friendly error messages (hide technical details)
- Preserve user input on error for retry per Requirement 8.7

### 9. Validation Engine

**Purpose**: Centralized validation logic for all input data

**Key Methods**:

```php
class ValidationEngine {
    // Validate merchandise data
    public function validateMerchandiseData(array $data): ValidationResult;
    
    // Validate fuel reading data
    public function validateFuelReadingData(array $data): ValidationResult;
    
    // Validate delivery data
    public function validateDeliveryData(array $data): ValidationResult;
    
    // Check SKU uniqueness
    public function checkSKUUniqueness(string $sku, int $stationId, ?int $excludeItemId = null): bool;
    
    // Validate date format and range
    public function validateDate(string $date): ValidationResult;
    
    // Validate positive numeric value
    public function validatePositiveNumber(mixed $value): ValidationResult;
    
    // Validate required field
    public function validateRequired(mixed $value, string $fieldName): ValidationResult;
}

class ValidationResult {
    public bool $isValid;
    public array $errors;
    public array $warnings;
}
```

**Validation Rules Matrix**:

| Field Type | Required | Format | Range | Special Rules |
|------------|----------|---------|-------|---------------|
| SKU | Yes | VARCHAR(100) | - | Must be unique per station |
| Product Name | Yes | VARCHAR(255) | - | - |
| Category | Yes | VARCHAR(100) | - | Must exist in categories list |
| Cost | Yes | DECIMAL(10,2) | >= 0 | Can be zero |
| Price | Yes | DECIMAL(10,2) | >= 0 | Can be zero |
| Fuel Reading | Yes | DECIMAL(12,2) | >= 0 | Warning if < previous reading |
| Delivered Quantity | Yes | DECIMAL(12,2) | > 0 | Block submit if negative |
| Delivery Date | Yes | DATE | <= today | Cannot be future date |
| PO Reference | No | VARCHAR(50) | - | - |
| Invoice Number | No | VARCHAR(50) | - | - |

**Client-Side Validation**:
- Validate fields as user types (partial validation per Requirement 8.5)
- Show inline error messages below each field
- Disable submit button if any validation errors exist
- Show field-specific validation icons (✓ or ✗)

**Server-Side Validation**:
- Always validate on server even if client-side passed
- Return structured error response with field-specific messages
- Preserve all user input for retry
- Log validation failures for security monitoring

## User Interface Design

### Main Inventory Menu

**Layout**:
```
┌─────────────────────────────────────────────────────────────┐
│  Staff Inventory Management                    [Back Button] │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐         │
│  │ Merchandise │  │    Fuel     │  │   Stock     │         │
│  │  Inventory  │  │  Inventory  │  │  Requests   │         │
│  └─────────────┘  └─────────────┘  └─────────────┘         │
│                                                              │
│  ┌─────────────┐  ┌─────────────┐                          │
│  │  Stock-In   │  │  Inventory  │                          │
│  │  (Delivery) │  │   History   │                          │
│  └─────────────┘  └─────────────┘                          │
└─────────────────────────────────────────────────────────────┘
```

### Merchandise Inventory Tab

**Layout**:
```
┌────────────────────────────────────────────────────────────────┐
│  Merchandise Inventory                         [Back Button]   │
├────────────────────────────────────────────────────────────────┤
│  Summary Cards:                                                │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐             │
│  │ Total Items │ │ Low Stock   │ │ Out of Stock│             │
│  │    250      │ │     12      │ │      3      │             │
│  └─────────────┘ └─────────────┘ └─────────────┘             │
│                                                                │
│  [Stock Request Button (15 items need attention)]             │
│                                                                │
│  [Search: _____________]                                       │
│                                                                │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │ SKU      │ Name     │ Category │ Stock │ Status         │ │
│  ├──────────────────────────────────────────────────────────┤ │
│  │ OIL-001  │ Engine   │ Oils     │ 5     │ ⚠️ Low Stock  │ │
│  │          │ Oil      │          │       │                │ │
│  │ TIRE-001 │ Tire     │ Tires    │ 0     │ ❌ Out        │ │
│  │ BATT-001 │ Battery  │ Batteries│ 50    │ ✅ OK         │ │
│  └──────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────┘
```

**Features**:
- **READ-ONLY VIEW**: No Add/Edit buttons for staff
- Stock Request button shows when low/out-of-stock items exist
- Color-coded status indicators
- Search and sort functionality
- Auto-refresh every 30 seconds

### Fuel Inventory Tab

**Layout**:
```
┌────────────────────────────────────────────────────────────────┐
│  Fuel Inventory                                [Back Button]   │
├────────────────────────────────────────────────────────────────┤
│  Summary Cards:                                                │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐             │
│  │ Total Fuel  │ │ Low Stock   │ │ Last Reading│             │
│  │   Types: 5  │ │     2       │ │   Today     │             │
│  └─────────────┘ └─────────────┘ └─────────────┘             │
│                                                                │
│  [Stock Request Button (2 fuel types need attention)]         │
│                                                                │
│  [+ Add Pump Reading]                                         │
│                                                                │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │ Fuel Type    │ Current Stock │ Reorder Level │ Status   │ │
│  ├──────────────────────────────────────────────────────────┤ │
│  │ Diesel       │ 4,500 L       │ 5,000 L       │ Low      │ │
│  │ Gasoline 91  │ 12,000 L      │ 5,000 L       │ OK       │ │
│  │ Kerosene     │ 800 L         │ 1,000 L       │ Low      │ │
│  └──────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────┘
```

### Stock Requests Tab

**Layout**:
```
┌────────────────────────────────────────────────────────────────┐
│  Stock Requests                                [Back Button]   │
├────────────────────────────────────────────────────────────────┤
│  Summary Cards:                                                │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐             │
│  │ Pending     │ │ Validated   │ │ Completed   │             │
│  │     8       │ │     15      │ │     42      │             │
│  └─────────────┘ └─────────────┘ └─────────────┘             │
│                                                                │
│  Filter: [All Status ▼] [All Types ▼] [Date Range]           │
│                                                                │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │ Request # │ Item      │ Qty │ Status    │ Date    │ View│ │
│  ├──────────────────────────────────────────────────────────┤ │
│  │ SR-001    │ Engine Oil│ 20  │ Pending   │ 05/10   │ [▼]│ │
│  │ SR-002    │ Diesel    │5000L│ Validated │ 05/09   │ [▼]│ │
│  │ SR-003    │ Filter    │ 15  │ Completed │ 05/08   │ [▼]│ │
│  └──────────────────────────────────────────────────────────┘ │
│                                                                │
│  Request Details:                                              │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │ Request ID: SR-001                                       │ │
│  │ Item: Engine Oil (SKU: OIL-001)                          │ │
│  │ Current Stock: 5 units                                   │ │
│  │ Requested Quantity: 20 units (auto-calculated)           │ │
│  │ Status: Pending → Awaiting Manager Validation            │ │
│  │ Requested By: John Doe (Staff)                           │ │
│  │ Date: 2024-05-10 09:30 AM                                │ │
│  │ Remarks: Stock level below reorder threshold             │ │
│  └──────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────┘
```

**Status Progression Display**:
- Visual indicator showing current status in lifecycle
- Timeline view: Pending → Validated → Completed
- Show timestamps for each transition
- Display user who performed each action

### Stock-In (Delivery Encoding) Tab

**Layout**:
```
┌────────────────────────────────────────────────────────────────┐
│  Stock-In (Deliveries)                         [Back Button]   │
├────────────────────────────────────────────────────────────────┤
│  Summary Cards:                                                │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐             │
│  │ Today       │ │ This Week   │ │ This Month  │             │
│  │     3       │ │     12      │ │     45      │             │
│  └─────────────┘ └─────────────┘ └─────────────┘             │
│                                                                │
│  [+ Encode Fuel Delivery]  [+ Encode Merchandise Delivery]    │
│                                                                │
│  Recent Deliveries:                                            │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │ Date    │ Type        │ Supplier  │ Status   │ View     │ │
│  ├──────────────────────────────────────────────────────────┤ │
│  │ 05/10   │ Fuel        │ Petron    │ Pending  │ [View]   │ │
│  │ 05/10   │ Merchandise │ Supplier A│ Verified │ [View]   │ │
│  │ 05/09   │ Fuel        │ Petron    │ Verified │ [View]   │ │
│  └──────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────┘
```

**Fuel Delivery Form**:
```
┌────────────────────────────────────────────────────────────────┐
│  Encode Fuel Delivery                          [Back] [Cancel] │
├────────────────────────────────────────────────────────────────┤
│  PO Reference:    [______________] (Optional)                  │
│  Supplier:        [_________________________] * Required       │
│  Fuel Type:       [Select Fuel Type ▼] * Required             │
│  Delivered Liters:[____________] L * Required (must be > 0)    │
│  Tanker Number:   [______________] * Required                  │
│  Invoice Number:  [______________] (Optional)                  │
│  Delivery Date:   [YYYY-MM-DD] * Required (cannot be future)   │
│  Notes:           [________________________________]            │
│                   [________________________________]            │
│                                                                │
│                          [Submit Delivery]                     │
└────────────────────────────────────────────────────────────────┘
```

**Merchandise Delivery Form**:
```
┌────────────────────────────────────────────────────────────────┐
│  Encode Merchandise Delivery                   [Back] [Cancel] │
├────────────────────────────────────────────────────────────────┤
│  PO Reference:    [______________] (Optional)                  │
│  Supplier:        [_________________________] * Required       │
│  Invoice Number:  [______________] (Optional)                  │
│  Delivery Date:   [YYYY-MM-DD] * Required (cannot be future)   │
│                                                                │
│  Delivered Items:                          [+ Add Item]        │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │ Product: [Select Product ▼] Qty: [____] Price: [_____] │ │
│  │ Product: [Select Product ▼] Qty: [____] Price: [_____] │ │
│  └──────────────────────────────────────────────────────────┘ │
│                                                                │
│  Notes:           [________________________________]            │
│                   [________________________________]            │
│                                                                │
│                          [Submit Delivery]                     │
└────────────────────────────────────────────────────────────────┘
```

### Inventory History Tab

**Layout**:
```
┌────────────────────────────────────────────────────────────────┐
│  Inventory History                             [Back Button]   │
├────────────────────────────────────────────────────────────────┤
│  Summary Cards:                                                │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐             │
│  │ Total Txns  │ │ This Week   │ │ This Month  │             │
│  │    1,245    │ │     87      │ │     342     │             │
│  └─────────────┘ └─────────────┘ └─────────────┘             │
│                                                                │
│  Filters:                                                      │
│  Status: [All ▼] Type: [All ▼] Date: [From] to [To]          │
│  Staff: [All Staff ▼]                     [Apply] [Reset]     │
│                                           [Export CSV] [PDF]   │
│                                                                │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │ Date/Time │ Type     │ Item      │ Qty  │ Status │ Staff│ │
│  ├──────────────────────────────────────────────────────────┤ │
│  │ 05/10 9AM │ Request  │ Oil       │ 20   │ Pending│ John │ │
│  │ 05/10 8AM │ Delivery │ Diesel    │5000L │Verified│ John │ │
│  │ 05/09 5PM │ Request  │ Filter    │ 15   │Complete│ Jane │ │
│  │ [View Details ▼]                                         │ │
│  └──────────────────────────────────────────────────────────┘ │
│                                                                │
│  Transaction Detail View:                                      │
│  ┌──────────────────────────────────────────────────────────┐ │
│  │ Transaction ID: TXN-12345                                │ │
│  │ Type: Stock Request                                      │ │
│  │ Item: Engine Oil (SKU: OIL-001)                          │ │
│  │ Quantity: 20 units                                       │ │
│  │ Status Lifecycle:                                        │ │
│  │   • Pending (05/10/2024 09:00 AM) - Requested by John   │ │
│  │   • Validated (05/10/2024 11:30 AM) - By Manager Mary   │ │
│  │   • [Awaiting Delivery]                                  │ │
│  │ Remarks: Stock level below reorder threshold             │ │
│  │ Manager Notes: Approved for procurement                  │ │
│  └──────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────┘
```

**Export Functionality**:
- CSV: Comma-separated values with all visible columns
- PDF: Formatted report with filters applied and summary totals
- Include current user, date exported, and filters applied in export header

## Integration Points

### Manager Inventory Module Integration

**Stock Request Validation Flow**:
1. Staff generates stock request (status = 'Pending')
2. Request appears in Manager's "Staff Stock Requests" tab
3. Manager reviews, adjusts quantity if needed, sets status to 'Validated'
4. Manager can reject request with reason (status = 'Rejected')
5. Validated requests become available for PO generation

**Delivery Verification Flow**:
1. Staff encodes delivery (status = 'Pending')
2. Delivery appears in Manager's "Deliveries" tab
3. Manager verifies delivery against PO
4. Manager marks delivery as 'Verified' with notes
5. Admin finalizes delivery and updates inventory

**Data Synchronization**:
- Real-time updates via AJAX polling (30-second intervals)
- WebSocket notifications for status changes (if available)
- Database triggers for status transition logging

### Admin Oversight Integration

**Purchase Order Integration**:
- Manager generates PO from validated requests
- Admin reviews and approves PO
- Admin prints PO (status = 'Official')
- Expected deliveries visible to Staff

**Delivery Finalization**:
- Manager verifies delivery
- Admin finalizes delivery
- System updates inventory stock levels
- Status transition logged in inventory_transactions

### Existing Table Usage

**stock_requests Table**:
- Used for all stock requests (fuel and merchandise)
- Status values: 'Pending', 'Validated', 'Approved'
- Links to staff_id, station_id, item_id
- Manager updates approved_quantity, manager_id, processed_at

**fuel_deliveries Table**:
- Used for all fuel delivery records
- Links to fuel_inventory for stock updates
- Status values: 'Pending', 'Verified', 'Finalized'

**station_inventory Table**:
- Used for merchandise stock levels
- Updated after verified merchandise deliveries
- Links to products table via product_id

**fuel_inventory Table**:
- Used for fuel stock levels
- Updated after fuel pump readings and fuel deliveries
- Links to fuel_types table via fuel_type_id

## Security & Access Control

### Role-Based Access

**Staff Role Permissions**:
- ✓ View own station's inventory
- ✓ Encode merchandise items
- ✓ Encode fuel readings
- ✓ Generate stock requests
- ✓ Encode deliveries (Pending status)
- ✓ View inventory history
- ✗ Validate stock requests
- ✗ Verify deliveries
- ✗ Finalize deliveries
- ✗ View other stations' data

**Manager Role Permissions**:
- ✓ All Staff permissions
- ✓ Validate stock requests
- ✓ Adjust requested quantities
- ✓ Verify deliveries
- ✓ Add variance notes
- ✗ Finalize deliveries (Admin only)

**Admin Role Permissions**:
- ✓ All Manager permissions
- ✓ Finalize deliveries
- ✓ Approve Purchase Orders
- ✓ View all stations' data
- ✓ Override status transitions

### Session Management

```php
// Check user session and role
function checkStaffAccess() {
    require_login(); // From lib.php
    
    if (!in_array($_SESSION['role'], ['staff', 'manager', 'admin'])) {
        header('Location: unauthorized.php');
        exit;
    }
}

// Validate station access
function validateStationAccess($stationId) {
    if ($_SESSION['role'] === 'staff' || $_SESSION['role'] === 'manager') {
        if ($_SESSION['station_id'] != $stationId) {
            throw new Exception('Access denied: Invalid station');
        }
    }
    // Admin can access all stations
}
```

### Input Sanitization

```php
class InputSanitizer {
    // Sanitize string input
    public static function sanitizeString($input): string {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    // Sanitize numeric input
    public static function sanitizeNumeric($input): float {
        return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, 
                         FILTER_FLAG_ALLOW_FRACTION);
    }
    
    // Sanitize date input
    public static function sanitizeDate($input): string {
        $date = DateTime::createFromFormat('Y-m-d', $input);
        return $date ? $date->format('Y-m-d') : '';
    }
}
```

### SQL Injection Prevention

- Use prepared statements for ALL database queries
- Parameterize all user inputs
- Never concatenate user input into SQL strings
- Use type-casting for numeric parameters

```php
// Example: Safe query with prepared statement
$stmt = $conn->prepare("
    INSERT INTO stock_requests 
    (staff_id, station_id, item_id, item_sku, item_name, 
     current_stock, requested_quantity, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param("iiisssis", 
    $staffId, $stationId, $itemId, $sku, $itemName, 
    $currentStock, $requestedQty, $status
);

$stmt->execute();
```

### CSRF Protection

```php
// Generate CSRF token
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $token)) {
        throw new Exception('Invalid CSRF token');
    }
}
```

### Audit Logging

**Log All Critical Actions**:
- Stock request creation
- Delivery encoding
- Inventory updates
- Status transitions
- Failed access attempts

```php
function logInventoryAction($action, $details) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO inventory_audit_log 
        (user_id, station_id, action, details, ip_address, timestamp) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->bind_param("iisss", 
        $_SESSION['user_id'], 
        $_SESSION['station_id'], 
        $action, 
        json_encode($details), 
        $_SERVER['REMOTE_ADDR']
    );
    
    $stmt->execute();
}
```

## Error Handling Strategy

## Error Handling

### Error Types

**Validation Errors**:
- Display inline next to form fields
- Highlight invalid fields in red
- Show specific error message per field
- Preserve user input for correction

**Database Errors**:
- Log technical details to server log
- Display user-friendly message to user
- Preserve user input for retry
- Provide retry mechanism

**Network Errors**:
- Display connection error message
- Provide retry button
- Cache form data locally
- Resume from where user left off

**Permission Errors**:
- Redirect to unauthorized page
- Log access attempt
- Display reason for denial
- Provide link to appropriate page

### Error Response Format (JSON)

```json
{
  "success": false,
  "error": {
    "type": "validation",
    "message": "Please correct the errors below",
    "fields": {
      "sku": "SKU already exists for this station",
      "delivered_quantity": "Quantity must be a positive number"
    }
  },
  "preservedData": {
    "sku": "OIL-001",
    "product_name": "Engine Oil",
    "category": "Oils"
  }
}
```

### User-Friendly Error Messages

| Technical Error | User Message |
|----------------|--------------|
| Duplicate key violation | "This SKU already exists. Please use a unique SKU." |
| Foreign key violation | "The selected item is no longer available. Please refresh and try again." |
| Connection timeout | "Connection lost. Your data has been saved. Please try again." |
| Invalid date format | "Please enter a valid date in YYYY-MM-DD format." |
| Negative quantity | "Quantity must be a positive number." |
| Future date | "Delivery date cannot be in the future." |

## Performance Optimization

### Database Query Optimization

**Indexing Strategy**:
```sql
-- Stock requests indexes
CREATE INDEX idx_stock_requests_station_status 
  ON stock_requests(station_id, status);
CREATE INDEX idx_stock_requests_staff 
  ON stock_requests(staff_id);
CREATE INDEX idx_stock_requests_created 
  ON stock_requests(created_at);

-- Station inventory indexes
CREATE INDEX idx_station_inventory_station 
  ON station_inventory(station_id, status);
CREATE INDEX idx_station_inventory_stock_level 
  ON station_inventory(station_id, stock_level, reorder_level);

-- Fuel inventory indexes
CREATE INDEX idx_fuel_inventory_station 
  ON fuel_inventory(station_id, status);
CREATE INDEX idx_fuel_inventory_stock_level 
  ON fuel_inventory(station_id, stock_level, reorder_level);

-- Deliveries indexes
CREATE INDEX idx_fuel_deliveries_station_date 
  ON fuel_deliveries(station_id, delivery_date, status);
CREATE INDEX idx_merchandise_deliveries_station_date 
  ON merchandise_deliveries(station_id, delivery_date, status);
```

### Caching Strategy

**Summary Cards Cache**:
- Cache summary statistics for 30 seconds
- Invalidate cache on inventory changes
- Use in-memory cache (PHP APCu or Redis if available)
- Fallback to database query if cache miss

**Inventory List Cache**:
- Cache full inventory list for 60 seconds
- Invalidate on create/update/delete operations
- Use session-based cache for user-specific views

### Pagination

**Large Dataset Handling**:
- Paginate inventory lists (50 items per page)
- Paginate history view (100 transactions per page)
- Use LIMIT and OFFSET in SQL queries
- Provide page navigation controls
- Display total count and current page info

```php
function getInventoryPaginated($stationId, $page = 1, $perPage = 50) {
    $offset = ($page - 1) * $perPage;
    
    $stmt = $conn->prepare("
        SELECT SQL_CALC_FOUND_ROWS 
               si.*, p.name, p.sku, p.category
        FROM station_inventory si
        JOIN products p ON si.product_id = p.id
        WHERE si.station_id = ? AND si.status = 'active'
        ORDER BY p.name ASC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->bind_param("iii", $stationId, $perPage, $offset);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    
    // Get total count
    $totalCount = $conn->query("SELECT FOUND_ROWS()")->fetch_row()[0];
    
    return [
        'items' => $items,
        'total' => $totalCount,
        'page' => $page,
        'perPage' => $perPage,
        'totalPages' => ceil($totalCount / $perPage)
    ];
}
```

### AJAX Request Optimization

**Debouncing**:
- Debounce search input (500ms delay)
- Debounce validation checks (300ms delay)
- Prevent duplicate form submissions

**Request Batching**:
- Batch multiple summary card updates into single request
- Combine related data fetches

**Lazy Loading**:
- Load tab content only when tab is activated
- Load history details on demand (expand/collapse)
- Load large datasets progressively

## Testing Strategy

### Unit Testing

**Components to Test**:
- ValidationEngine: All validation methods
- DataLayerManager: All database operations
- StockRequestGenerator: Quantity calculation logic
- SummaryCardsGenerator: Statistics calculation

**Example Test Cases**:
```php
class ValidationEngineTest extends PHPUnit\Framework\TestCase {
    public function testValidateSKUUniqueness() {
        $validator = new ValidationEngine($conn);
        
        // Test duplicate SKU
        $result = $validator->checkSKUUniqueness('OIL-001', 1253);
        $this->assertFalse($result);
        
        // Test unique SKU
        $result = $validator->checkSKUUniqueness('NEW-SKU-999', 1253);
        $this->assertTrue($result);
    }
    
    public function testValidatePositiveNumber() {
        $validator = new ValidationEngine($conn);
        
        // Test positive number
        $result = $validator->validatePositiveNumber(25.50);
        $this->assertTrue($result->isValid);
        
        // Test negative number
        $result = $validator->validatePositiveNumber(-10);
        $this->assertFalse($result->isValid);
        
        // Test zero (allowed per requirements)
        $result = $validator->validatePositiveNumber(0);
        $this->assertTrue($result->isValid);
    }
}
```

### Integration Testing

**Test Scenarios**:
1. End-to-end stock request flow (Staff → Manager → Admin)
2. Delivery encoding and verification workflow
3. Inventory level updates after deliveries
4. Status transition logging
5. Summary card updates after inventory changes

### User Acceptance Testing

**Test Cases**:
1. Staff encodes merchandise item successfully
2. Stock Request button appears only when items are low stock
3. Auto-generated stock request has correct quantity
4. Delivery encoding updates inventory levels
5. History shows complete status transitions
6. Back button navigates correctly from all tabs
7. Summary cards display accurate statistics

## File Structure

```
public/
├── staff_inventory_dashboard.php       # Main dashboard with tab navigation
├── staff_merchandise_inventory.php     # Merchandise inventory VIEW ONLY (read-only)
├── staff_fuel_inventory.php            # Fuel inventory management
├── staff_stock_requests.php            # Stock requests list and details
├── staff_stock_in.php                  # Delivery encoding interface
├── staff_inventory_history.php         # Inventory history and audit trail
└── modals/
    ├── add_fuel_reading_modal.php      # Fuel reading form
    ├── generate_stock_request_modal.php # Stock request generation
    ├── fuel_delivery_modal.php         # Fuel delivery form
    └── merchandise_delivery_modal.php  # Merchandise delivery form

backend/
├── staff_inventory/
│   ├── get_merchandise_inventory.php   # Fetch merchandise items (read-only)
│   ├── get_fuel_inventory.php          # Fetch fuel levels
│   ├── save_fuel_reading.php           # Save fuel reading
│   ├── get_low_stock_items.php         # Identify low stock items
│   ├── generate_stock_request.php      # Create stock request
│   ├── save_fuel_delivery.php          # Save fuel delivery
│   ├── save_merchandise_delivery.php   # Save merchandise delivery
│   ├── get_stock_requests.php          # Fetch stock requests
│   ├── get_inventory_history.php       # Fetch history with filters
│   ├── get_summary_stats.php           # Fetch summary card data
│   └── export_history.php              # Export history to CSV/PDF

includes/
├── staff_inventory_functions.php       # Core inventory functions
├── inventory_validation.php            # Validation logic
└── inventory_data_layer.php            # Database operations

assets/
├── css/
│   └── staff_inventory.css             # Inventory module styles
└── js/
    ├── staff_inventory_navigation.js   # Tab navigation and back button
    ├── staff_merchandise_manager.js    # Merchandise VIEW logic (read-only)
    ├── staff_fuel_manager.js           # Fuel inventory logic
    ├── stock_request_generator.js      # Stock request generation
    ├── stock_in_recorder.js            # Delivery encoding
    ├── inventory_history.js            # History filtering and display
    └── summary_cards.js                # Summary cards auto-refresh
```

## API Endpoints

### GET Endpoints

| Endpoint | Parameters | Response | Description |
|----------|------------|----------|-------------|
| `/backend/staff_inventory/get_merchandise_inventory.php` | station_id, page, per_page | JSON: items array, pagination | Get merchandise inventory |
| `/backend/staff_inventory/get_fuel_inventory.php` | station_id | JSON: fuel types array | Get fuel inventory levels |
| `/backend/staff_inventory/get_low_stock_items.php` | station_id, item_type | JSON: items array | Get low/out-of-stock items |
| `/backend/staff_inventory/get_stock_requests.php` | station_id, status, page | JSON: requests array, pagination | Get stock requests |
| `/backend/staff_inventory/get_inventory_history.php` | station_id, filters, page | JSON: history array, pagination | Get filtered history |
| `/backend/staff_inventory/get_summary_stats.php` | station_id, tab | JSON: statistics object | Get summary card data |

### POST Endpoints

| Endpoint | Parameters | Response | Description |
|----------|------------|----------|-------------|
| `/backend/staff_inventory/save_merchandise_item.php` | item_data (JSON) | JSON: success, item_id | Create/update merchandise item |
| `/backend/staff_inventory/save_fuel_reading.php` | reading_data (JSON) | JSON: success, new_stock_level | Save fuel reading and update stock |
| `/backend/staff_inventory/generate_stock_request.php` | items (array), staff_id, station_id | JSON: success, request_ids | Generate stock requests |
| `/backend/staff_inventory/save_fuel_delivery.php` | delivery_data (JSON) | JSON: success, delivery_id | Save fuel delivery record |
| `/backend/staff_inventory/save_merchandise_delivery.php` | delivery_data (JSON), items (array) | JSON: success, delivery_id | Save merchandise delivery |
| `/backend/staff_inventory/export_history.php` | filters, format (csv/pdf) | File download | Export history report |

### Request/Response Examples

**Generate Stock Request**:
```json
// Request
POST /backend/staff_inventory/generate_stock_request.php
{
  "staff_id": 21,
  "station_id": 1253,
  "item_type": "merchandise",
  "items": [
    {
      "item_id": 960,
      "item_sku": "OLG004",
      "item_name": "Engine Oil MO30",
      "item_category": "Oils / Lubes / Grease",
      "current_stock": 5,
      "reorder_level": 10,
      "capacity": 50,
      "requested_quantity": 35,
      "remarks": "Stock level below reorder threshold"
    }
  ]
}

// Response
{
  "success": true,
  "request_ids": [123],
  "message": "Stock request generated successfully",
  "requests_count": 1
}
```

**Save Merchandise Delivery**:
```json
// Request
POST /backend/staff_inventory/save_merchandise_delivery.php
{
  "station_id": 1253,
  "supplier": "Supplier A",
  "po_reference": "PO-2024-001",
  "invoice_no": "INV-12345",
  "delivery_date": "2024-05-10",
  "received_by": 21,
  "notes": "Delivery complete",
  "items": [
    {
      "product_id": 960,
      "delivered_quantity": 30,
      "unit_price": 250.00
    },
    {
      "product_id": 974,
      "delivered_quantity": 20,
      "unit_price": 180.00
    }
  ]
}

// Response
{
  "success": true,
  "delivery_id": 456,
  "message": "Delivery recorded successfully",
  "inventory_updated": true,
  "items_count": 2
}
```

## Deployment Considerations

### Database Migration Script

```sql
-- Create merchandise_deliveries table
CREATE TABLE IF NOT EXISTS merchandise_deliveries (
  id INT(11) PRIMARY KEY AUTO_INCREMENT,
  station_id INT(11) NOT NULL,
  supplier VARCHAR(100) DEFAULT NULL,
  po_reference VARCHAR(50) DEFAULT NULL,
  invoice_no VARCHAR(50) DEFAULT NULL,
  delivery_date DATE DEFAULT NULL,
  received_by INT(11) DEFAULT NULL COMMENT 'Staff user ID',
  verified_by INT(11) DEFAULT NULL COMMENT 'Manager user ID',
  verified_at DATETIME DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  status VARCHAR(20) DEFAULT 'Pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (station_id) REFERENCES stations(id),
  FOREIGN KEY (received_by) REFERENCES users(id),
  FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create merchandise_delivery_items table
CREATE TABLE IF NOT EXISTS merchandise_delivery_items (
  id INT(11) PRIMARY KEY AUTO_INCREMENT,
  delivery_id INT(11) NOT NULL,
  product_id INT(11) NOT NULL,
  delivered_quantity DECIMAL(12,2) NOT NULL,
  unit_price DECIMAL(10,2) DEFAULT NULL,
  line_total DECIMAL(12,2) DEFAULT NULL,
  FOREIGN KEY (delivery_id) REFERENCES merchandise_deliveries(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create indexes for performance
CREATE INDEX idx_stock_requests_station_status 
  ON stock_requests(station_id, status);
CREATE INDEX idx_stock_requests_staff 
  ON stock_requests(staff_id);
CREATE INDEX idx_stock_requests_created 
  ON stock_requests(created_at);

CREATE INDEX idx_station_inventory_station 
  ON station_inventory(station_id, status);
CREATE INDEX idx_station_inventory_stock_level 
  ON station_inventory(station_id, stock_level, reorder_level);

CREATE INDEX idx_fuel_inventory_station 
  ON fuel_inventory(station_id, status);
CREATE INDEX idx_fuel_inventory_stock_level 
  ON fuel_inventory(station_id, stock_level, reorder_level);

CREATE INDEX idx_fuel_deliveries_station_date 
  ON fuel_deliveries(station_id, delivery_date, status);
CREATE INDEX idx_merchandise_deliveries_station_date 
  ON merchandise_deliveries(station_id, delivery_date, status);
```

### Configuration Requirements

**PHP Requirements**:
- PHP >= 8.0
- MySQLi extension enabled
- JSON extension enabled
- Session support enabled
- File upload limit: 10MB (for export functionality)

**Database Requirements**:
- MySQL >= 5.7 or MariaDB >= 10.2
- InnoDB storage engine
- UTF-8 character set support

**Server Requirements**:
- Apache/Nginx with mod_rewrite or equivalent
- HTTPS enabled (recommended)
- Minimum 512MB PHP memory limit
- Maximum execution time: 60 seconds

### Rollback Plan

**Pre-Deployment Backup**:
1. Backup all existing inventory tables
2. Backup related configuration files
3. Document current state

**Rollback Steps** (if deployment fails):
1. Restore database from backup
2. Restore previous code version
3. Clear any cached data
4. Test basic inventory operations
5. Notify users of rollback

**Rollback SQL**:
```sql
-- Drop new tables if rollback needed
DROP TABLE IF EXISTS merchandise_delivery_items;
DROP TABLE IF EXISTS merchandise_deliveries;

-- Restore from backup
-- (Execute backup restoration scripts)
```

### Monitoring & Logging

**Application Logs**:
- Log all inventory operations
- Log validation failures
- Log database errors
- Log access attempts

**Performance Metrics**:
- Track page load times
- Monitor database query performance
- Track AJAX request latency
- Monitor error rates

**Alerts**:
- Alert on repeated validation failures (potential security issue)
- Alert on database connection failures
- Alert on high error rates
- Alert on slow query performance

## Future Enhancements

### Phase 2 Enhancements

1. **Barcode Scanning**:
   - Add barcode scanner support for SKU entry
   - Mobile app integration for inventory scanning
   - Auto-populate item details from barcode

2. **Automated Reorder**:
   - Auto-generate stock requests when items reach reorder level
   - Schedule automatic reorder for critical items
   - Smart quantity calculation based on usage patterns

3. **Analytics Dashboard**:
   - Inventory turnover reports
   - Stock movement trends
   - Supplier performance metrics
   - Cost analysis and forecasting

4. **Mobile Optimization**:
   - Responsive mobile interface
   - Offline capability with sync
   - Push notifications for status changes

5. **Advanced Reporting**:
   - Customizable report templates
   - Scheduled report generation
   - Data visualization with charts
   - Export to multiple formats (Excel, JSON)

6. **Integration Enhancements**:
   - Supplier portal integration
   - Automated PO generation based on AI predictions
   - Integration with accounting systems
   - Real-time inventory sync across stations

## Conclusion

This design document provides a comprehensive blueprint for implementing the Staff Inventory Module. The modular architecture ensures maintainability, the security controls protect sensitive data, and the integration points enable seamless workflow with Manager and Admin modules. The design follows established patterns in the existing codebase while introducing modern best practices for validation, error handling, and performance optimization.
