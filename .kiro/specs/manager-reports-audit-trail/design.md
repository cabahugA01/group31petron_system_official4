# Manager Reports & Audit Trail - Design Document

## System Architecture

### Component Overview
```
┌─────────────────────────────────────────────────────────────┐
│                    Manager Dashboard                         │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │ Summary Card │  │ Summary Card │  │ Summary Card │      │
│  │ Validated    │  │ Active Credit│  │ Outstanding  │      │
│  │ Customers    │  │ Accounts     │  │ Balances     │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    Manager Reports Hub                       │
│                                                               │
│  ┌────────────────────────────────────────────────────┐    │
│  │  Date Range Filter: [Today][Week][Month][Custom]   │    │
│  └────────────────────────────────────────────────────┘    │
│                                                               │
│  ┌─────────────┬─────────────┬──────────────┬─────────┐   │
│  │ Sales       │ Job Orders  │ Deliveries   │ Meter   │   │
│  │ Reports     │ Reports     │ Reports      │ Readings│   │
│  ├─────────────┼─────────────┼──────────────┼─────────┤   │
│  │ Payments    │ Customer    │ Validation   │ Audit   │   │
│  │ Reports     │ Reports     │ Reports      │ Trail   │   │
│  └─────────────┴─────────────┴──────────────┴─────────┘   │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│              Individual Report View                          │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  [← Back]                        [📊 Excel][📄 CSV]  │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Sub-tabs: [Fuel Sales][Merch Sales][Summary]       │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │                  Data Table                           │  │
│  │  ┌────────┬────────┬────────┬────────┬────────┐     │  │
│  │  │ Header │ Header │ Header │ Header │ Header │     │  │
│  │  ├────────┼────────┼────────┼────────┼────────┤     │  │
│  │  │ Data   │ Data   │ Data   │ Data   │ Data   │     │  │
│  │  └────────┴────────┴────────┴────────┴────────┘     │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

## Database Design

### Existing Tables (Used)
1. **sales** - Fuel and merchandise sales
2. **fuel_transactions** - Fuel-specific transactions
3. **merchandise_transactions** - Merchandise sales
4. **job_orders** - Service job orders
5. **deliveries_oversight** - Delivery tracking
6. **fuel_deliveries** - Fuel deliveries
7. **fuel_readings** / **meter_readings** - Pump readings
8. **customers** - Customer profiles
9. **balances** - Customer credit balances
10. **payments** - Payment tracking

### New Table: validation_logs

**Purpose:** Track all Manager validation actions for audit compliance

**Schema:**
```sql
CREATE TABLE IF NOT EXISTS validation_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Who & When
    manager_id INT NOT NULL COMMENT 'Manager who performed validation',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When validation occurred',
    station_id INT NOT NULL COMMENT 'Which station',
    
    -- What was validated
    transaction_id VARCHAR(100) NOT NULL COMMENT 'Transaction reference (JO-123, MT-456, etc)',
    transaction_type ENUM('job_order', 'delivery', 'fuel_transaction', 'merchandise_transaction', 'customer_profile') NOT NULL,
    
    -- Related entities
    customer_id INT NULL COMMENT 'Customer involved (if applicable)',
    staff_id INT NULL COMMENT 'Staff who encoded the original entry',
    
    -- Financial details
    original_amount DECIMAL(10,2) NULL COMMENT 'Amount before validation',
    validated_amount DECIMAL(10,2) NULL COMMENT 'Amount after adjustment',
    
    -- Action & Reasoning
    action_taken ENUM('Approve', 'Reject', 'Return', 'Adjust') NOT NULL,
    remarks TEXT NULL COMMENT 'Manager notes/justification',
    
    -- Metadata
    ip_address VARCHAR(45) NULL COMMENT 'IP for security audit',
    user_agent TEXT NULL COMMENT 'Browser info',
    
    -- Indexes
    INDEX idx_manager (manager_id),
    INDEX idx_transaction (transaction_id, transaction_type),
    INDEX idx_station_date (station_id, created_at),
    INDEX idx_action (action_taken),
    INDEX idx_staff (staff_id),
    
    -- Foreign Keys
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE RESTRICT,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE SET NULL
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Query Patterns

#### 1. Manager's Own Validation Logs
```sql
SELECT 
    vl.id,
    vl.transaction_id,
    vl.transaction_type,
    vl.action_taken,
    vl.remarks,
    vl.original_amount,
    vl.validated_amount,
    vl.created_at,
    COALESCE(c.name, 'N/A') AS customer_name,
    COALESCE(s.name, 'N/A') AS staff_name,
    COALESCE(m.name, 'N/A') AS manager_name
FROM validation_logs vl
LEFT JOIN customers c ON c.id = vl.customer_id
LEFT JOIN users s ON s.id = vl.staff_id
LEFT JOIN users m ON m.id = vl.manager_id
WHERE vl.manager_id = ?
    AND vl.station_id = ?
    AND DATE(vl.created_at) BETWEEN ? AND ?
ORDER BY vl.created_at DESC;
```

#### 2. Admin Full Audit Trail
```sql
SELECT 
    vl.id,
    vl.transaction_id,
    vl.transaction_type,
    vl.action_taken,
    vl.remarks,
    vl.original_amount,
    vl.validated_amount,
    vl.created_at,
    COALESCE(c.name, 'N/A') AS customer_name,
    COALESCE(s.name, 'N/A') AS staff_name,
    m.name AS manager_name
FROM validation_logs vl
LEFT JOIN customers c ON c.id = vl.customer_id
LEFT JOIN users s ON s.id = vl.staff_id
INNER JOIN users m ON m.id = vl.manager_id
WHERE vl.station_id = ?
    AND DATE(vl.created_at) BETWEEN ? AND ?
ORDER BY vl.created_at DESC;
```

#### 3. Pending Validations Count
```sql
SELECT COUNT(*) AS pending_count
FROM job_orders jo
WHERE jo.station_id = ?
    AND jo.validation_status IN ('Pending', 'Pending Validation')
    AND jo.status NOT IN ('Rejected', 'Cancelled');
```

## File Structure

```
public/
├── manager_reports.php          (Main reports hub - EXISTING, ENHANCE)
└── manager_validation_logs.php  (NEW - Combined: Pending Validations + Audit Trail)

backend/
├── manager_reports_data.php     (API endpoints for report data)
└── validation_logger.php        (Helper functions for logging)

assets/
├── css/
│   └── manager_reports.css      (Styling)
└── js/
    └── manager_reports.js       (Interactive features)
```

**Note:** No separate audit_trail.php - combined into validation_logs.php to avoid redundancy.

## UI Design Specifications

### Color Scheme (Petron Brand)
```css
--petron-blue: #002F70;
--petron-red: #CC0000;
--success: #22c55e;
--warning: #f59e0b;
--danger: #ef4444;
--info: #3b82f6;
--gray-50: #f9fafb;
--gray-100: #f3f4f6;
--gray-200: #e5e7eb;
--gray-600: #4b5563;
--gray-900: #111827;
```

### Navigation Structure
**Sidebar Menu:**
```
Reports
├── Sales Reports
├── Job Orders Reports
├── Deliveries Reports
├── Meter Readings
├── Payments Reports
├── Customer Reports
└── Validation Logs ⭐ (Combined: Pending + Audit Trail)
```

**Page Tabs (within Validation Logs):**
```
┌────────────────────────────────────────────────┐
│ Validation Logs                                │
├────────────────────────────────────────────────┤
│ [Pending Validations] [My Validation History]  │
│                                                 │
│ Admin sees: [All Validation History]           │
└────────────────────────────────────────────────┘
```

**Rationale:** Single menu item avoids redundancy while providing clear separation of pending items vs historical logs via tabs.

### Summary Cards
```css
.summary-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    border-left: 4px solid var(--petron-blue);
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.card-value {
    font-size: 32px;
    font-weight: 800;
    color: var(--gray-900);
}

.card-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--gray-600);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
```

### Report Table
```css
.report-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 8px;
    overflow: hidden;
}

.report-table thead {
    background: var(--petron-blue);
    color: white;
}

.report-table th {
    padding: 14px 16px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-align: left;
}

.report-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--gray-200);
    font-size: 13px;
}

.report-table tbody tr:hover {
    background: #e3f2fd;
}
```

### Action Badges
```css
.badge-approve {
    background: #dcfce7;
    color: #16a34a;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.badge-reject {
    background: #fee2e2;
    color: #dc2626;
}

.badge-return {
    background: #fef3c7;
    color: #ca8a04;
}

.badge-adjust {
    background: #dbeafe;
    color: #2563eb;
}
```

## API Endpoints

### GET /backend/manager_reports_data.php
**Parameters:**
- `section` - Report section (sales, job_orders, etc.)
- `sub_tab` - Sub-section within report
- `date_start` - Start date (YYYY-MM-DD)
- `date_end` - End date (YYYY-MM-DD)
- `station_id` - Station ID (from session)

**Response:**
```json
{
    "success": true,
    "data": [...],
    "summary": {
        "total_records": 150,
        "total_amount": 125000.50,
        "date_range": "2026-06-01 to 2026-06-30"
    }
}
```

### POST /backend/validation_logger.php
**Purpose:** Log validation action

**Payload:**
```json
{
    "transaction_id": "JO-12345",
    "transaction_type": "job_order",
    "action_taken": "Approve",
    "remarks": "Verified all service details",
    "original_amount": 1500.00,
    "validated_amount": 1500.00,
    "customer_id": 45,
    "staff_id": 12
}
```

**Response:**
```json
{
    "success": true,
    "validation_log_id": 789,
    "message": "Validation logged successfully"
}
```

## Frontend JavaScript Logic

### Date Range Filter
```javascript
// Handle date range selection
document.querySelectorAll('.range-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const range = this.dataset.range;
        
        if (range === 'custom') {
            document.getElementById('custom-range-inputs').style.display = 'flex';
        } else {
            // Auto-submit with range parameter
            window.location.href = `manager_reports.php?section=${section}&range=${range}`;
        }
    });
});
```

### Export Functionality
```javascript
function exportReport(format) {
    const section = new URLSearchParams(window.location.search).get('section');
    const range = new URLSearchParams(window.location.search).get('range');
    const start = document.querySelector('[name="start"]').value;
    const end = document.querySelector('[name="end"]').value;
    
    const url = `manager_reports.php?section=${section}&range=${range}&start=${start}&end=${end}&export=${format}`;
    window.location.href = url;
}
```

### Validation Action Handler
```javascript
function logValidationAction(transactionId, action) {
    const remarks = prompt(`Enter remarks for ${action} action:`);
    
    if (!remarks) return;
    
    fetch('/backend/validation_logger.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            transaction_id: transactionId,
            action_taken: action,
            remarks: remarks
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Validation logged successfully');
            location.reload();
        }
    });
}
```

## Security Considerations

### Access Control
```php
// Check Manager role
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Manager access required';
    header('Location: dashboard.php');
    exit;
}

// Verify station assignment
if (!$station_id) {
    die('Error: No station assigned');
}
```

### SQL Injection Prevention
- Use prepared statements for all queries
- Validate and sanitize all user inputs
- Use parameterized queries

### Data Visibility
- Filter all queries by `station_id`
- Manager sees only their station's data
- Audit trail filtered by `manager_id` for managers
- Admin sees all logs (no `manager_id` filter)

## Error Handling Strategy

### Pattern for All Queries
```php
try {
    // Check if table exists
    $tables = $pdo->query("SHOW TABLES LIKE 'table_name'")->fetchAll();
    
    if (empty($tables)) {
        // Return empty data gracefully
        $report_data = [];
        $summary_cards = [
            ['label' => 'Total Records', 'value' => 0, 'icon' => 'fa-database']
        ];
    } else {
        // Execute query
        $stmt = $pdo->prepare("SELECT ...");
        $stmt->execute([...]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Calculate summary
        $summary_cards = [
            ['label' => 'Total Records', 'value' => count($report_data), 'icon' => 'fa-database']
        ];
    }
} catch (Exception $e) {
    // Log error
    error_log("Manager Reports Error: " . $e->getMessage());
    
    // Graceful fallback
    $report_data = [];
    $summary_cards = [
        ['label' => 'Total Records', 'value' => 0, 'icon' => 'fa-database']
    ];
    $error_message = 'Unable to load report. Please try again.';
}
```

## Performance Optimization

### Query Optimization
- Add indexes on frequently queried columns
- Use LIMIT for large result sets
- Implement pagination for tables with 100+ rows
- Cache summary calculations

### Frontend Optimization
- Lazy load report data on tab switch
- Use skeleton loaders during data fetch
- Debounce search/filter inputs
- Virtualize large tables (>500 rows)

## Testing Strategy

### Unit Tests
- [ ] Test all database queries with missing tables
- [ ] Test column existence checks
- [ ] Test date range calculations
- [ ] Test export file generation

### Integration Tests
- [ ] Test full report generation flow
- [ ] Test validation log creation
- [ ] Test export with large datasets
- [ ] Test permission checks

### Manual Testing Checklist
- [ ] All 8 report sections load without errors
- [ ] Summary cards display correct values
- [ ] Date range filter works correctly
- [ ] Export to CSV generates valid file
- [ ] Export to Excel generates valid file
- [ ] Back button returns to correct view
- [ ] Validation logs appear in audit trail
- [ ] Manager sees only their own logs
- [ ] Admin sees all logs
- [ ] Confidential data displays correctly

## Deployment Checklist

### Database
- [ ] Run validation_logs table creation script
- [ ] Add indexes for performance
- [ ] Verify foreign key constraints
- [ ] Test with sample data

### Code Deployment
- [ ] Deploy manager_audit_trail.php
- [ ] Deploy validation_logger.php backend
- [ ] Update manager_reports.php with new features
- [ ] Deploy CSS/JS assets

### Configuration
- [ ] Update navigation menu with Audit Trail link
- [ ] Set proper file permissions
- [ ] Configure error logging
- [ ] Enable audit trail module

### Post-Deployment
- [ ] Test all report sections
- [ ] Verify export functionality
- [ ] Check validation log creation
- [ ] Monitor error logs
- [ ] Gather manager feedback
