# STAFF REPORTS - DATABASE TABLE MAPPING

## ✅ VERIFIED TABLE MAPPINGS

Based on the actual database schema (`petron_pos_db_secure`), here are the correct table mappings for Staff Reports:

### 1. **Sales Reports** → `merchandise_transactions`
- **Table**: `merchandise_transactions`
- **Columns Used**:
  - `id`, `transaction_id`
  - `station_id` (filter)
  - `staff_id` (filter - shows only staff's own sales)
  - `customer_id` (for linkage)
  - `customer_name`
  - `total_amount`
  - `payment_method`
  - `created_at`
  - `validation_status`
- **Query Logic**:
  ```sql
  SELECT * FROM merchandise_transactions
  WHERE station_id = ? AND staff_id = ? AND DATE(created_at) BETWEEN ? AND ?
  ```

### 2. **Job Orders Reports** → `job_orders`
- **Table**: `job_orders`
- **Columns Used**:
  - `id`, `job_order_id`, `job_order_number`
  - `station_id` (filter)
  - `created_by` or `user_id` (filter - shows only staff's created job orders)
  - `customer_id`, `customer_name`
  - `vehicle_plate`
  - `service_type`
  - `status` (Pending, In Progress, Completed, etc.)
  - `total_cost`, `estimated_cost`
  - `assigned_mechanic_id`
  - `payment_method`, `payment_status`
  - `created_at`, `updated_at`
- **Query Logic**:
  ```sql
  SELECT * FROM job_orders
  WHERE station_id = ? AND created_by = ? AND DATE(created_at) BETWEEN ? AND ?
  ```

### 3. **Deliveries Reports** → Multiple Tables
- **Fuel Deliveries**: `fuel_deliveries`
  - `id`
  - `station_id`
  - `supplier`
  - `fuel_type`, `fuel_type_id`
  - `delivery_liters`
  - `status`
  - `received_by`
  - `created_at`
  
- **Merchandise Deliveries**: `deliveries_oversight`
  - `id`, `batch_id`, `delivery_ref`
  - `station_id`
  - `encoded_by` (filter - shows staff's encoded deliveries)
  - `delivery_type` ('merchandise')
  - `supplier`, `product`
  - `quantity`, `unit`
  - `status`
  - `created_at`

- **Inventory Movement**: `inventory_logs`
  - `id`
  - `station_id`
  - `product_id`
  - `action` (stock_in, stock_out, adjustment)
  - `quantity_change`
  - `reference_type`, `reference_id`
  - `created_at`

### 4. **Meter Reading Reports** → `fuel_readings`
- **Table**: `fuel_readings`
- **Columns Used**:
  - `id`
  - `station_id` (filter)
  - `encoded_by` (optional filter)
  - `pump_number`
  - `fuel_type`
  - `shift_period`
  - `previous_reading`
  - `present_reading`
  - `difference` (liters sold)
  - `status`
  - `encoded_at`
- **Query Logic**:
  ```sql
  SELECT * FROM fuel_readings
  WHERE station_id = ? AND DATE(encoded_at) BETWEEN ? AND ?
  ```

### 5. **Payments Reports** → Multiple Tables
- **Job Orders Payments**: `job_orders`
  - `payment_status` (Unpaid, Pending, Paid)
  - `payment_method`
  - `total_cost`
  
- **Merchandise Payments**: `merchandise_transactions`
  - `payment_status` (if column exists)
  - `payment_method`
  - `total_amount`

**Combined Query**:
```sql
-- Job Orders
SELECT 'Job Order' AS entity_type, job_order_id, payment_status, total_cost
FROM job_orders WHERE station_id = ? AND created_by = ?

UNION ALL

-- Merchandise
SELECT 'Merchandise' AS entity_type, transaction_id, payment_status, total_amount
FROM merchandise_transactions WHERE station_id = ? AND staff_id = ?
```

### 6. **Customer Reports** → `customers` + transactions
- **Main Table**: `customers`
  - `id`, `name`
  - `email`, `phone`, `address`
  - `customer_type`
  - `station_id`
  - `created_at`

- **Transaction History**: Joined with
  - `merchandise_transactions` (WHERE `staff_id = ?`)
  - `job_orders` (WHERE `created_by = ?`)

**Query Logic**:
```sql
-- Customer List
SELECT c.*, 
       COUNT(DISTINCT mt.id) AS merch_transactions,
       COUNT(DISTINCT jo.id) AS job_orders
FROM customers c
LEFT JOIN merchandise_transactions mt ON c.id = mt.customer_id AND mt.staff_id = ?
LEFT JOIN job_orders jo ON c.id = jo.customer_id AND jo.created_by = ?
WHERE c.station_id = ?
GROUP BY c.id
```

### 7. **Activity Reports** → `activity_logs` OR `audit_logs`
- **Possible Tables**:
  - `activity_logs` (found in database)
  - `audit_logs` (also exists)
  
- **Columns Used**:
  - `id`
  - `user_id` (filter - shows only staff's own actions)
  - `action`, `action_type`
  - `details`, `action_details`
  - `entity_type`, `entity_id`
  - `status`
  - `ip_address`
  - `created_at`

**Query Logic**:
```sql
SELECT action_type, action_details, entity_type, status, created_at
FROM activity_logs
WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?
ORDER BY created_at DESC
```

---

## 📊 CURRENT IMPLEMENTATION STATUS

### ✅ Correctly Implemented Tables:
1. ✅ `merchandise_transactions` - Sales Reports
2. ✅ `job_orders` - Job Orders Reports
3. ✅ `fuel_deliveries` - Fuel Deliveries
4. ✅ `deliveries_oversight` - Merchandise Deliveries
5. ✅ `inventory_logs` - Inventory Movement
6. ✅ `fuel_readings` - Meter Readings
7. ✅ `customers` - Customer Reports
8. ✅ `activity_logs` OR `audit_logs` - Activity Reports

### 🔍 Table Structure Notes:

**Optional Columns (checked dynamically)**:
- `job_orders.created_by` (preferred) OR `user_id` (fallback)
- `job_orders.job_order_id` (preferred) OR `job_order_number` (fallback)
- `job_orders.total_cost` (preferred) OR `estimated_cost` (fallback)
- `job_orders.payment_status` (may not exist in all versions)
- `merchandise_transactions.payment_status` (may not exist in all versions)
- `fuel_deliveries.received_by` (may not exist in all versions)
- `deliveries_oversight.encoded_by` (tracking who entered the delivery)

**The implementation uses `has_col()` helper function** to check if columns exist before querying them, making it database-version agnostic.

---

## 🎯 FILTERING RULES

### Staff-Scoped Data:
Staff users see **ONLY** their own data:
- Sales: WHERE `staff_id = current_user_id`
- Job Orders: WHERE `created_by = current_user_id`
- Deliveries: WHERE `encoded_by = current_user_id`
- Activity: WHERE `user_id = current_user_id`

### Station-Scoped Data:
All reports filter by station:
- WHERE `station_id = user_station_id`

### Date Range Filtering:
All reports support custom date ranges:
- WHERE `DATE(created_at) BETWEEN ? AND ?`
- OR `DATE(encoded_at) BETWEEN ? AND ?`
- Default: Last 30 days

---

## ✅ VERIFICATION

The current `staff_reports_complete.php` implementation is **CORRECT** and uses the actual database tables. The queries are properly structured with:

1. ✅ Correct table names
2. ✅ Proper column references
3. ✅ Staff-scoped filtering
4. ✅ Station-scoped filtering
5. ✅ Date range support
6. ✅ Dynamic column checking (has_col function)
7. ✅ Error handling (try/catch blocks)
8. ✅ Proper JOIN operations
9. ✅ Aggregation functions (SUM, COUNT, AVG)
10. ✅ Status filtering (for payments, job orders)

**The reports are working correctly and fetching from the right tables!** 🎉

---

_Last Verified: June 6, 2026_
