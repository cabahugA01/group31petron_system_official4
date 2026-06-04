# Design Document

## Overview

This document provides the technical design for implementing role-specific transaction module dashboards for Staff, Manager, and Admin roles. Each dashboard displays distinct summary cards, visualization charts, and export functionality tailored to the user's operational needs.

## Architecture

### Component Structure

```
Dashboard Layer (PHP)
├── staff_dashboard.php
│   ├── Summary Cards Section
│   ├── Charts Section  
│   └── Export Actions
├── manager_dashboard.php
│   ├── Summary Cards Section
│   ├── Charts Section
│   └── Export Actions
└── admin_dashboard.php
    ├── Summary Cards Section
    ├── Charts Section
    └── Export Actions

Backend API Layer (PHP)
├── backend/api/staff_transaction_metrics.php
├── backend/api/manager_validation_metrics.php
├── backend/api/admin_oversight_metrics.php
└── backend/export/
    ├── export_job_orders.php
    ├── export_merchandise.php
    ├── export_validated_transactions.php
    └── export_variance_reports.php
```

## Database Design

### Tables Used

**merchandise_transactions**
- `id` (PK)
- `transaction_id` (varchar)
- `customer_name` (varchar)
- `item_sku` (varchar)
- `total_amount` (decimal)
- `amount_paid` (decimal)
- `payment_method` (varchar)
- `validation_status` (enum: Pending, Approved, Rejected)
- `validated_by` (int FK → users.id)
- `validated_at` (timestamp)
- `staff_id` (int FK → users.id)
- `station_id` (int)
- `transaction_date` (datetime)
- `created_at` (timestamp)

**job_orders**
- `id` (PK)
- `customer_name` (varchar)
- `service_type` (varchar)
- `vehicle_plate` (varchar)
- `status` (enum: Pending, Ongoing, Completed, Cancelled)
- `validation_status` (enum: Pending Validation, Approved, Rejected)
- `total_cost` (decimal)
- `amount_paid` (decimal)
- `payment_method` (varchar)
- `validated_by` (int FK → users.id)
- `validated_at` (timestamp)
- `created_by` (int FK → users.id)
- `station_id` (int)
- `created_at` (timestamp)

### Query Patterns

#### Staff Metrics
```sql
-- Transactions Encoded
SELECT 
  (SELECT COUNT(*) FROM merchandise_transactions WHERE staff_id = ?) +
  (SELECT COUNT(*) FROM job_orders WHERE created_by = ?) AS transactions_encoded

-- Pending Payments
SELECT SUM(total_amount - COALESCE(amount_paid, 0)) AS pending_payments
FROM (
  SELECT total_amount, amount_paid FROM merchandise_transactions WHERE staff_id = ?
  UNION ALL
  SELECT total_cost AS total_amount, amount_paid FROM job_orders WHERE created_by = ?
) AS combined

-- Completed Job Orders
SELECT COUNT(*) AS completed_jobs
FROM job_orders
WHERE created_by = ? AND status = 'Completed'
```

#### Manager Metrics
```sql
-- Pending Transactions
SELECT COUNT(*) AS pending_count
FROM (
  SELECT id FROM merchandise_transactions 
  WHERE station_id = ? AND LOWER(validation_status) = 'pending'
  UNION ALL
  SELECT id FROM job_orders 
  WHERE station_id = ? AND LOWER(validation_status) = 'pending validation'
) AS combined

-- Validated Today
SELECT COUNT(*) AS validated_today
FROM (
  SELECT id FROM merchandise_transactions 
  WHERE station_id = ? AND DATE(validated_at) = CURDATE()
  UNION ALL
  SELECT id FROM job_orders 
  WHERE station_id = ? AND DATE(validated_at) = CURDATE()
) AS combined

-- Variance Alerts (placeholder - requires variance detection logic)
SELECT COUNT(*) AS variance_count
FROM variance_log
WHERE station_id = ? AND flagged = 1
```

#### Admin Metrics
```sql
-- Total Validated Transactions (system-wide)
SELECT COUNT(*) AS validated_total
FROM (
  SELECT id FROM merchandise_transactions 
  WHERE LOWER(validation_status) = 'approved'
  UNION ALL
  SELECT id FROM job_orders 
  WHERE LOWER(validation_status) = 'approved'
) AS combined

-- Pending Payments (system-wide)
SELECT SUM(total_amount - COALESCE(amount_paid, 0)) AS pending_payments
FROM (
  SELECT total_amount, amount_paid FROM merchandise_transactions
  UNION ALL
  SELECT total_cost AS total_amount, amount_paid FROM job_orders
) AS combined

-- Outstanding Utang (credit transactions)
SELECT SUM(total_amount - COALESCE(amount_paid, 0)) AS utang_total
FROM (
  SELECT total_amount, amount_paid FROM merchandise_transactions WHERE payment_method = 'Credit'
  UNION ALL
  SELECT total_cost AS total_amount, amount_paid FROM job_orders WHERE payment_method = 'Credit'
) AS combined

-- Receivables Aging
SELECT 
  SUM(CASE WHEN DATEDIFF(CURDATE(), transaction_date) <= 30 THEN balance ELSE 0 END) AS current,
  SUM(CASE WHEN DATEDIFF(CURDATE(), transaction_date) > 30 THEN balance ELSE 0 END) AS overdue
FROM (
  SELECT transaction_date, (total_amount - COALESCE(amount_paid, 0)) AS balance 
  FROM merchandise_transactions
  UNION ALL
  SELECT created_at AS transaction_date, (total_cost - COALESCE(amount_paid, 0)) AS balance 
  FROM job_orders
) AS combined
WHERE balance > 0
```

## UI Components

### Summary Card Component

**HTML Structure:**
```html
<div class="summary-card">
  <div class="card-icon">
    <i class="fas fa-[icon-name]"></i>
  </div>
  <div class="card-content">
    <div class="card-value">[metric-value]</div>
    <div class="card-label">[metric-label]</div>
  </div>
</div>
```

**CSS Styling:**
```css
.summary-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.card-icon {
  width: 56px;
  height: 56px;
  border-radius: 10px;
  background: linear-gradient(135deg, #002F70 0%, #004494 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 24px;
}

.card-value {
  font-size: 28px;
  font-weight: 700;
  color: #002F70;
}

.card-label {
  font-size: 13px;
  color: #64748b;
  font-weight: 600;
}
```

### Chart Component (Chart.js)

**JavaScript Configuration:**
```javascript
// Job Order Status Chart (Staff)
const jobStatusChart = new Chart(ctx, {
  type: 'doughnut',
  data: {
    labels: ['Pending', 'Ongoing', 'Completed'],
    datasets: [{
      data: [pendingCount, ongoingCount, completedCount],
      backgroundColor: ['#fbbf24', '#3b82f6', '#10b981']
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { position: 'bottom' }
    }
  }
});

// Validation Flow Chart (Manager)
const validationFlowChart = new Chart(ctx, {
  type: 'bar',
  data: {
    labels: ['Pending', 'Validated'],
    datasets: [{
      label: 'Transactions',
      data: [pendingCount, validatedCount],
      backgroundColor: ['#f59e0b', '#10b981']
    }]
  },
  options: {
    responsive: true,
    scales: {
      y: { beginAtZero: true }
    }
  }
});

// Oversight Graph (Admin)
const oversightGraph = new Chart(ctx, {
  type: 'line',
  data: {
    labels: last7Days,
    datasets: [{
      label: 'Sales',
      data: salesData,
      borderColor: '#002F70',
      fill: false
    }, {
      label: 'Receivables',
      data: receivablesData,
      borderColor: '#dc2626',
      fill: false
    }]
  },
  options: {
    responsive: true,
    scales: {
      y: { beginAtZero: true }
    }
  }
});
```

## Export Functionality

### Export Flow

1. User clicks export button with format parameter (excel/csv/pdf)
2. Frontend sends AJAX request to backend export endpoint
3. Backend queries database based on user role and filters
4. Backend generates file in requested format
5. Backend streams file to browser with appropriate headers
6. Browser triggers download

### Export Endpoints

**backend/export/export_job_orders.php** (Staff)
- Query: Job orders created by current user
- Formats: Excel, CSV, PDF
- Fields: JO ID, Customer, Service, Vehicle, Status, Amount, Payment Method, Date

**backend/export/export_merchandise.php** (Staff)
- Query: Merchandise transactions by current user
- Formats: Excel, CSV, PDF
- Fields: Transaction ID, Customer, Items, Quantity, Amount, Payment Method, Date

**backend/export/export_pending_transactions.php** (Manager)
- Query: Pending transactions at station
- Formats: Excel, CSV
- Fields: Transaction ID, Type, Customer, Amount, Date, Staff, Status

**backend/export/export_validated_transactions.php** (Manager/Admin)
- Query: Validated transactions (station-level for Manager, system-wide for Admin)
- Formats: Excel, CSV
- Fields: Transaction ID, Type, Customer, Amount, Validated By, Validated At, Payment Status

**backend/export/export_variance_reports.php** (Manager/Admin)
- Query: Variance-flagged transactions
- Formats: PDF (for compliance)
- Fields: Transaction ID, Type, Variance Type, Flagged Date, Amount, Staff, Resolution Status

## Implementation Steps

### Phase 1: Staff Dashboard
1. Add summary card queries to staff_dashboard.php
2. Implement Chart.js for job order status and merchandise sales
3. Create export endpoints for job orders and merchandise
4. Add export buttons with AJAX handlers

### Phase 2: Manager Dashboard
1. Add summary card queries to manager_dashboard.php
2. Implement Chart.js for validation flow and variance trends
3. Create export endpoints for pending/validated transactions and variance
4. Add export buttons with AJAX handlers

### Phase 3: Admin Dashboard
1. Add summary card queries to admin_dashboard.php (system-wide scope)
2. Implement Chart.js for oversight and compliance graphs
3. Create export endpoints for validated transactions, receivables, variance, compliance
4. Add export buttons with AJAX handlers

### Phase 4: Testing & Refinement
1. Test role-based access control
2. Verify query performance (<2s for calculations)
3. Test export generation (<10s for <10k records)
4. Validate visual consistency across all dashboards

## Security Considerations

- All queries filter by `staff_id` (Staff), `station_id` (Manager), or no filter (Admin system-wide)
- Export endpoints verify user role before generating files
- SQL queries use prepared statements to prevent injection
- File download uses proper Content-Disposition headers
- Session-based authentication required for all endpoints

## Performance Optimization

- Use indexed columns: `staff_id`, `station_id`, `validation_status`, `created_at`, `validated_at`
- Cache summary card metrics for 30 seconds (reduce DB load)
- Paginate chart data to last 30 days
- Limit export queries to 10,000 records with warning for larger datasets
- Use AJAX for chart data refresh (avoid full page reload)
