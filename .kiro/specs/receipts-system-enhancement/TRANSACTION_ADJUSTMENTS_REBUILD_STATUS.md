# Transaction Adjustments Page - Rebuild Status

## Current Status: READY TO IMPLEMENT

### Files to Rebuild:
1. `c:\xampp\htdocs\group31petron_system_official4\public\manager_transaction_monitoring.php`

### Implementation Plan:

#### Phase 1: Database Schema (CRITICAL - Do First)
Create the `transaction_adjustments` table to store adjustment history:

```sql
CREATE TABLE IF NOT EXISTS transaction_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(50) NOT NULL,
    transaction_type ENUM('job_order', 'merchandise', 'combined') NOT NULL,
    customer_name VARCHAR(255),
    original_amount DECIMAL(10,2) NOT NULL,
    updated_amount DECIMAL(10,2) NOT NULL,
    amount_difference DECIMAL(10,2) NOT NULL,
    adjustment_reason VARCHAR(255) NOT NULL,
    manager_remarks TEXT,
    adjusted_by INT NOT NULL,
    adjustment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    station_id INT NOT NULL,
    fields_changed JSON,
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_adjustment_date (adjustment_date),
    INDEX idx_station_id (station_id),
    FOREIGN KEY (adjusted_by) REFERENCES users(id),
    FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Phase 2: New Page Features

**A. KPI Cards (3 cards)**
- Total Adjustments (count)
- Adjustments Today (count)
- Adjusted Amount (total difference in ₱)

**B. Filters**
- Date Range (From/To)
- Staff Encoder (dropdown)
- Transaction Type (Job Order/Merchandise/Combined)

**C. Table (8 columns)**
- Adjustment ID
- Transaction ID
- Customer Name
- Original Amount
- Updated Amount
- Adjustment Reason
- Adjusted By
- Adjustment Date

**D. Export Buttons**
- Excel
- CSV
- PDF

#### Phase 3: Implementation Steps

1. **Backup current file**
   ```bash
   copy manager_transaction_monitoring.php manager_transaction_monitoring_OLD.php
   ```

2. **Create transaction_adjustments table** (see Phase 1)

3. **Rebuild PHP file** with:
   - KPI calculation queries
   - Adjustment history query
   - Export functionality
   - Adjustment modal

4. **Test with real data**

### Key Changes from Current Version:

| Feature | Current | New (Spec) |
|---------|---------|------------|
| **Data Source** | Direct transaction table | `transaction_adjustments` history table |
| **KPI Cards** | None | 3 cards (Total, Today, Amount) |
| **Table Focus** | All transactions | Adjustment records only |
| **Columns** | 10 columns (transaction-focused) | 8 columns (adjustment-focused) |
| **Export** | None | Excel, CSV, PDF |
| **Purpose** | Adjust transactions inline | View adjustment history + create new |

### User Request:
> "e rebuild na kani dapat e follow" (This should be rebuilt, must follow the specification)

The user showed a screenshot with the complete structure needed. This document tracks the rebuild progress.

**Next Action**: Execute implementation starting with Phase 1 (database schema).

---

**Document Version:** 1.0  
**Created:** June 23, 2026  
**Status:** Ready for Implementation

