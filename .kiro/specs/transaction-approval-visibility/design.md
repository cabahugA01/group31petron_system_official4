# Transaction Approval Visibility - Design Document

## Overview
Ensure that when a manager approves a transaction, it becomes immediately visible to staff in their respective viewing interfaces with clear validation status indicators.

## System Architecture

### Data Flow

```
[Staff] → Encode Transaction → validation_status = 'Pending Validation'
                                        ↓
[Manager] → Review in Pending Transactions → Approve
                                        ↓
                        validation_status = 'Approved'
                        validated_by = [Manager ID]
                        validated_at = [NOW()]
                                        ↓
                        ┌───────────────┴────────────────┐
                        ↓                                ↓
            [Manager View]                      [Staff View]
        Validated Transactions              Job Order Tracker
                                          Merchandise History
```

## Query Logic Updates

### 1. Job Order Tracker (Staff View)

**Current Issue**: May be filtering only by `status` column, missing `validation_status`

**Solution**: Update query to include all approved job orders:

```sql
SELECT 
    jo.id,
    jo.job_order_id,
    jo.customer_name,
    jo.service_type,
    jo.vehicle_plate,
    jo.status AS workflow_status,
    jo.validation_status,
    jo.payment_status,
    jo.total_cost,
    jo.amount_paid,
    jo.balance_due,
    jo.validated_by,
    jo.validated_at,
    jo.created_at,
    u.name AS staff_name,
    m.name AS mechanic_name
FROM job_orders jo
LEFT JOIN users u ON u.id = jo.created_by
LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id
WHERE jo.station_id = ?
  AND (
    -- Show approved/validated job orders
    jo.validation_status IN ('Approved', 'Validated', 'Adjusted')
    OR 
    -- Show pending validation job orders (staff can see their own pending)
    jo.validation_status IN ('Pending Validation', 'Pending')
    OR
    -- Also show in progress/completed (backward compatibility)
    jo.status IN ('In Progress', 'Completed', 'Pending')
  )
  AND jo.status NOT IN ('Cancelled', 'Rejected')
ORDER BY 
    FIELD(jo.validation_status, 'Pending Validation', 'Approved', 'Validated', 'Adjusted'),
    FIELD(jo.status, 'In Progress', 'Pending', 'Completed'),
    jo.created_at DESC
LIMIT 50
```

**Key Changes**:
- Include `validation_status` in SELECT
- Filter to show Approved, Validated, Adjusted job orders
- Sort by validation status priority
- Exclude Cancelled/Rejected

### 2. Merchandise History (Staff View)

**Current Issue**: May not be filtering by validation_status at all

**Solution**: Update query to include validated merchandise transactions:

```sql
SELECT 
    mt.id,
    mt.transaction_id,
    mt.customer_name,
    mt.item_sku,
    mt.total_amount,
    mt.payment_method,
    mt.validation_status,
    mt.amount_paid,
    mt.balance_due,
    mt.payment_status,
    mt.transaction_date,
    mt.validated_by,
    mt.validated_at,
    u.name AS staff_name
FROM merchandise_transactions mt
LEFT JOIN users u ON u.id = mt.staff_id
WHERE mt.station_id = ?
  AND (
    -- Show approved/validated transactions
    mt.validation_status IN ('Approved', 'Validated', 'Adjusted')
    OR
    -- Show completed transactions (backward compatibility)
    mt.payment_status = 'Paid'
  )
ORDER BY 
    mt.transaction_date DESC,
    mt.created_at DESC
LIMIT 100
```

**Key Changes**:
- Include `validation_status` in SELECT
- Filter for Approved, Validated, Adjusted
- Also show Paid transactions (backward compatibility)
- Sort by transaction date

### 3. Staff Dashboard - Job Order Widget

**Update the dashboard widget** to show validation status:

```sql
SELECT 
    COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-', jo.id)) AS jo_ref,
    COALESCE(c.name, jo.customer_name, 'Walk-in') AS customer,
    COALESCE(jo.service_type, jo.service_description, '—') AS service_type,
    COALESCE(m.full_name, m.name, '—') AS mechanic,
    jo.created_at, 
    jo.status AS workflow_status,
    COALESCE(jo.validation_status, jo.status) AS display_status,
    jo.notes
FROM job_orders jo
LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id
LEFT JOIN customers c ON c.id = jo.customer_id
WHERE jo.station_id = ?
  AND jo.validation_status IN ('Pending Validation', 'Approved', 'Validated')
  AND jo.status NOT IN ('Cancelled', 'Rejected', 'Completed')
ORDER BY 
    FIELD(jo.status, 'Pending Validation', 'In Progress', 'Approved', 'Validated'),
    jo.created_at DESC
LIMIT 20
```

## UI Component Updates

### Status Badge Component

**Badge Colors & Labels**:

| Validation Status    | Badge Color | Text Color | Icon             |
|---------------------|-------------|------------|------------------|
| Pending Validation  | #FEF3C7     | #92400E    | hourglass-half   |
| Approved            | #DCFCE7     | #166534    | check-circle     |
| Validated           | #DBEAFE     | #1E40AF    | shield-check     |
| Adjusted            | #FDE68A     | #92400E    | edit             |
| Rejected            | #FEE2E2     | #991B1B    | times-circle     |

### Example Badge HTML:

```php
<?php
function render_validation_badge($status) {
    $config = [
        'Pending Validation' => ['bg' => '#FEF3C7', 'color' => '#92400E', 'icon' => 'hourglass-half'],
        'Approved'           => ['bg' => '#DCFCE7', 'color' => '#166534', 'icon' => 'check-circle'],
        'Validated'          => ['bg' => '#DBEAFE', 'color' => '#1E40AF', 'icon' => 'shield-check'],
        'Adjusted'           => ['bg' => '#FDE68A', 'color' => '#92400E', 'icon' => 'edit'],
        'Rejected'           => ['bg' => '#FEE2E2', 'color' => '#991B1B', 'icon' => 'times-circle'],
    ];
    
    $cfg = $config[$status] ?? ['bg' => '#F1F5F9', 'color' => '#64748B', 'icon' => 'question-circle'];
    
    return sprintf(
        '<span class="txn-badge" style="background:%s;color:%s;border:1px solid %s;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-%s"></i>%s</span>',
        $cfg['bg'],
        $cfg['color'],
        $cfg['color'],
        $cfg['icon'],
        htmlspecialchars($status)
    );
}
?>
```

## Staff Transaction Hub Updates

### File: `public/staff_transactions_hub.php`

#### 1. Add Validation Status Column to Job Order Table

**Before**:
```html
<th>JO ID</th>
<th>Customer</th>
<th>Service</th>
<th>Status</th>
```

**After**:
```html
<th>JO ID</th>
<th>Customer</th>
<th>Service</th>
<th>Validation</th>
<th>Workflow Status</th>
```

#### 2. Display Validation Badge

**Add in table row**:
```php
<td>
    <?php echo render_validation_badge($job['validation_status'] ?? 'Pending'); ?>
</td>
<td>
    <?php echo render_workflow_badge($job['status'] ?? 'Pending'); ?>
</td>
```

### Merchandise History Panel Updates

**Add validation status badge**:
```html
<div class="mh-row">
    <div class="mh-id">TXN-<?= $txn['id'] ?></div>
    <div class="mh-validation">
        <?= render_validation_badge($txn['validation_status'] ?? 'N/A') ?>
    </div>
    <div class="mh-customer"><?= htmlspecialchars($txn['customer_name']) ?></div>
    <div class="mh-amount">₱<?= number_format($txn['total_amount'], 2) ?></div>
    <div class="mh-date"><?= date('M d, Y', strtotime($txn['transaction_date'])) ?></div>
</div>
```

## AJAX Endpoint Updates

### Endpoint: `?refresh_job_orders=1`

**Add to staff_transactions_hub.php**:

```php
if (isset($_GET['refresh_job_orders']) && $_GET['refresh_job_orders'] == '1') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("
            SELECT 
                jo.id,
                COALESCE(jo.job_order_id, CONCAT('JO-', jo.id)) AS jo_ref,
                COALESCE(jo.customer_name, 'Walk-in') AS customer,
                jo.service_type,
                jo.status AS workflow_status,
                jo.validation_status,
                jo.payment_status,
                COALESCE(jo.total_cost, jo.estimated_cost, 0) AS total_cost,
                COALESCE(jo.amount_paid, 0) AS amount_paid,
                COALESCE(jo.balance_due, 0) AS balance_due,
                jo.created_at,
                u.name AS staff_name
            FROM job_orders jo
            LEFT JOIN users u ON u.id = jo.created_by
            WHERE jo.station_id = ?
              AND jo.validation_status IN ('Pending Validation', 'Approved', 'Validated', 'Adjusted')
              AND jo.status NOT IN ('Cancelled', 'Rejected')
            ORDER BY 
                FIELD(jo.validation_status, 'Pending Validation', 'Approved', 'Validated'),
                FIELD(jo.status, 'In Progress', 'Pending', 'Completed'),
                jo.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$station_id]);
        $job_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'job_orders' => $job_orders,
            'count' => count($job_orders)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
```

## Testing Plan

### Test Case 1: Job Order Approval Flow
1. Staff encodes job order → validation_status = 'Pending Validation'
2. Verify: JO appears in staff's tracker with amber "Pending Validation" badge
3. Manager approves in Pending Transactions
4. Verify: JO appears in manager's Validated Transactions
5. **Verify: JO appears in staff's Job Order Tracker with green "Approved" badge**
6. Staff updates status to "In Progress"
7. Verify: Status changes but validation badge remains "Approved"

### Test Case 2: Merchandise Transaction Approval Flow
1. Staff creates merchandise transaction → validation_status = 'Pending'
2. Manager approves transaction
3. Verify: Transaction appears in manager's Validated Transactions
4. **Verify: Transaction appears in staff's Merchandise History with green "Approved" badge**
5. Verify: Staff can view transaction details

### Test Case 3: Dashboard Widget
1. Create multiple job orders with different statuses
2. Approve some via manager
3. **Verify: Staff dashboard shows approved job orders**
4. **Verify: Validation status badges display correctly**

## Performance Considerations

- **Index on validation_status**: Ensure database has index on `validation_status` column for both tables
- **Limit query results**: Use LIMIT to prevent slow queries
- **Cache query results**: Consider caching frequently accessed data (if system supports it)

## Migration Notes

### Database Index Recommendations:

```sql
-- Add indexes for better query performance
ALTER TABLE job_orders 
    ADD INDEX idx_validation_status (validation_status),
    ADD INDEX idx_station_validation (station_id, validation_status);

ALTER TABLE merchandise_transactions 
    ADD INDEX idx_validation_status (validation_status),
    ADD INDEX idx_station_validation (station_id, validation_status);
```

## Rollback Plan

If issues arise:
1. Revert query changes to original filtering logic
2. Remove validation_status badges from UI
3. Restore original table columns
4. No data is modified - only viewing logic changes

## Success Metrics

- ✅ All approved job orders visible in staff's Job Order Tracker
- ✅ All approved merchandise transactions visible in Merchandise History
- ✅ Validation status badges display correctly
- ✅ No duplicate entries
- ✅ Performance: Query executes in < 100ms
- ✅ Zero data integrity issues
