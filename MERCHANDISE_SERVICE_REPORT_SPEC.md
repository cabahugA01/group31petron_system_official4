# DAILY MERCHANDISE & SERVICE SALES REPORT - Complete Specification

## Report Structure (24-Hour Summary)

### Section 1: Merchandise Sales
**Data Source:** `merchandise_transactions` + `merchandise_transaction_items`
**Columns:**
- Receipt No. (transaction_id)
- Customer (customer_name)
- Category (category from items)
- Product (product_name from items)
- Qty (quantity)
- Unit Price (unit_price)
- Amount (subtotal or qty * unit_price)
- Encoder (staff name from users table)

**SQL Query Pattern:**
```sql
SELECT 
    mt.transaction_id AS receipt_no,
    COALESCE(mt.customer_name, 'Walk-in') AS customer,
    mti.category,
    mti.product_name AS product,
    mti.quantity AS qty,
    mti.unit_price,
    COALESCE(mti.subtotal, mti.quantity * mti.unit_price) AS amount,
    CONCAT(u.first_name, ' ', u.last_name) AS encoder
FROM merchandise_transactions mt
JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
LEFT JOIN users u ON mt.staff_id = u.id
WHERE mt.station_id = ?
  AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
  AND LOWER(COALESCE(mti.item_type, 'merchandise')) = 'merchandise'
ORDER BY mt.transaction_date ASC, mt.id ASC
```

---

### Section 2: Job Order / Service Sales
**Data Source:** `job_orders`
**Columns:**
- JO No. (job_order_number or ID)
- Customer (customer_name)
- Vehicle (vehicle info)
- Service Type (service_type)
- Labor Fee (actual_labor_cost or estimated_labor_cost)
- Parts Cost (actual_parts_cost or estimated_parts_cost)
- Total Amount (total_cost)
- Encoder (created_by staff name)

**SQL Query Pattern:**
```sql
SELECT 
    COALESCE(jo.job_order_number, CONCAT('JO-', LPAD(jo.id, 5, '0'))) AS jo_no,
    COALESCE(jo.customer_name, 'Walk-in') AS customer,
    COALESCE(CONCAT(jo.vehicle_make, ' ', jo.vehicle_model), jo.vehicle_plate, '—') AS vehicle,
    COALESCE(jo.service_type, jo.service_description, 'Service') AS service_type,
    COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) AS labor_fee,
    COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0) AS parts_cost,
    COALESCE(jo.total_cost, jo.estimated_cost, 0) AS total_amount,
    CONCAT(u.first_name, ' ', u.last_name) AS encoder
FROM job_orders jo
LEFT JOIN users u ON jo.created_by = u.id
WHERE jo.station_id = ?
  AND DATE(jo.created_at) BETWEEN ? AND ?
  AND jo.status IN ('Completed', 'Released')
ORDER BY jo.created_at ASC
```

---

### Section 3: Parts Used in Job Orders
**Data Source:** Link `job_orders` to `merchandise_transaction_items` via job order references
**Note:** This requires checking if parts were tracked via merchandise system

**Columns:**
- JO No.
- Customer
- Product Name
- Category
- Qty Used
- Unit Price
- Total Cost

**SQL Query Pattern:**
```sql
SELECT 
    COALESCE(jo.job_order_number, CONCAT('JO-', LPAD(jo.id, 5, '0'))) AS jo_no,
    COALESCE(jo.customer_name, 'Walk-in') AS customer,
    mti.product_name,
    mti.category,
    mti.quantity AS qty_used,
    mti.unit_price,
    COALESCE(mti.subtotal, mti.quantity * mti.unit_price) AS total_cost
FROM merchandise_transaction_items mti
JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
LEFT JOIN job_orders jo ON jo.id = mt.job_order_id
WHERE mt.station_id = ?
  AND DATE(mt.created_at) BETWEEN ? AND ?
  AND mt.job_order_id IS NOT NULL
  AND LOWER(COALESCE(mti.item_type, '')) IN ('part', 'parts', 'merchandise')
ORDER BY jo.created_at ASC
```

---

### Section 4: Payment Breakdown
**Data Source:** Aggregate from `fuel_transactions`, `merchandise_transactions`, `job_orders`
**Columns:**
- Payment Method
- Transactions (count)
- Amount (sum)

**SQL Query Pattern:**
```sql
SELECT 
    CASE
        WHEN LOWER(COALESCE(payment_method,'')) LIKE '%fleet%' THEN 'Fleet'
        WHEN LOWER(COALESCE(payment_method,'')) LIKE '%card%' THEN 'Card'
        WHEN LOWER(COALESCE(payment_method,'')) LIKE '%gcash%' OR LOWER(COALESCE(payment_method,'')) LIKE '%maya%' THEN 'GCash'
        WHEN LOWER(COALESCE(payment_method,'')) LIKE '%cash%' OR COALESCE(payment_method,'') = '' THEN 'Cash'
        ELSE 'Charge Account'
    END AS payment_method,
    COUNT(*) AS transactions,
    SUM(COALESCE(total_amount, 0)) AS amount
FROM (
    SELECT payment_method, total_amount FROM merchandise_transactions 
    WHERE station_id = ? AND DATE(COALESCE(transaction_date, created_at)) BETWEEN ? AND ?
    
    UNION ALL
    
    SELECT payment_method, total_cost AS total_amount FROM job_orders
    WHERE station_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status IN ('Completed', 'Released')
) combined
GROUP BY payment_method
ORDER BY amount DESC
```

---

### Section 5: Shift Sales Summary
**Logic:** Split all sales into Shift 1 (6AM-2PM) and Shift 2 (2PM-12AM)

**For Each Shift:**
- Merchandise Sales
- Labor Income  
- Parts Sales
- Grand Total

**Time Detection:**
```sql
-- Shift 1: TIME(created_at) >= '06:00:00' AND TIME(created_at) < '14:00:00'
-- Shift 2: TIME(created_at) >= '14:00:00' OR TIME(created_at) < '06:00:00'
```

---

### Section 6: Overall Daily Summary
**Calculations:**
- Merchandise Sales = SUM(merchandise_transactions.total_amount) WHERE item_type = 'merchandise'
- Labor Income = SUM(job_orders.actual_labor_cost)
- Parts Used = SUM(merchandise_transactions.total_amount) WHERE job_order_id IS NOT NULL
- Grand Total Sales = Merchandise + Labor + Parts
- Total Transactions = COUNT(DISTINCT transactions)
- Customers Served = COUNT(DISTINCT customer_id OR customer_name)

---

## Implementation Notes:

1. **No Pre-coded Data:** All queries must fetch from database dynamically
2. **Date Range:** Use date picker values from form
3. **Station Filter:** Always filter by current station_id
4. **Shift Detection:** Use TIME() function on timestamps
5. **Validation Status:** Consider only validated/completed transactions where applicable
6. **Empty States:** Show "No data" messages when no records found
7. **Totals:** Calculate footer totals by summing the amount columns
8. **Export:** Support Excel, CSV, PDF exports with all 6 sections
