# Batch ID Same-Date Grouping Implementation

**Date**: June 11, 2026  
**Status**: ✅ COMPLETED

## Overview
Updated both **Merchandise** and **Fuel** delivery systems so that all deliveries on the same date share the same Batch ID for easy grouping and identification.

---

## Problem Solved

### Before:
Each delivery got a new incremented Batch ID:
```
June 11, 2026 deliveries:
  - Item 1: BATCH-20260611-001
  - Item 2: BATCH-20260611-002
  - Item 3: BATCH-20260611-003
```
❌ Hard to identify which items were delivered together

### After:
All deliveries on the same date share ONE Batch ID:
```
June 11, 2026 deliveries:
  - Item 1: BATCH-20260611-001
  - Item 2: BATCH-20260611-001 ✅ SAME
  - Item 3: BATCH-20260611-001 ✅ SAME

June 12, 2026 deliveries:
  - Item 4: BATCH-20260612-001 (NEW DATE = NEW BATCH)
```
✅ Easy to see all items delivered on the same day!

---

## Changes Made

### 1. **Merchandise Deliveries** (`staff_record_delivery.php`)

**Updated Logic** (Lines 186-201):
```php
// Generate batch ID based on delivery date - ONE batch per date
$batch_prefix = 'BATCH-' . date('Ymd', strtotime($delivery_date)) . '-';

// Check if a batch already exists for this date at this station
$stmt = $pdo->prepare("
    SELECT batch_id 
    FROM deliveries_oversight 
    WHERE batch_id LIKE ? 
      AND station_id = ? 
      AND DATE(delivery_date) = ? 
    LIMIT 1
");
$stmt->execute([$batch_prefix . '%', $station_id, $delivery_date]);
$existing_batch = $stmt->fetchColumn();

if ($existing_batch) {
    // Use existing batch ID for this date
    $batch_id = $existing_batch;
} else {
    // Create new batch ID for this date
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(batch_id, '-', -1) AS UNSIGNED)) FROM deliveries_oversight WHERE batch_id LIKE ?");
    $stmt->execute([$batch_prefix . '%']);
    $max_batch_num = (int)$stmt->fetchColumn();
    $batch_id = $batch_prefix . str_pad($max_batch_num + 1, 3, '0', STR_PAD_LEFT);
}
```

**How It Works**:
1. ✅ Check if a batch exists for this delivery date + station
2. ✅ If YES → **Reuse the existing Batch ID**
3. ✅ If NO → Create a new Batch ID for this date

---

### 2. **Fuel Deliveries** (`staff_fuel_deliveries.php`)

**Updated genBatch Function** (Lines 50-69):
```php
function genBatch(PDO $pdo, string $d, int $station_id): string {
    $pfx = 'BATCH-'.date('Ymd', strtotime($d)).'-';
    
    // Check if a batch already exists for this date at this station
    $s = $pdo->prepare("
        SELECT batch_id 
        FROM fuel_deliveries 
        WHERE batch_id LIKE ? 
          AND station_id = ? 
          AND DATE(delivery_date) = ? 
        LIMIT 1
    ");
    $s->execute([$pfx.'%', $station_id, $d]);
    $existing = $s->fetchColumn();
    
    if ($existing) {
        // Reuse existing batch for this date
        return $existing;
    }
    
    // Create new batch for this date
    $s = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(batch_id,'-',-1) AS UNSIGNED)) FROM fuel_deliveries WHERE batch_id LIKE ?");
    $s->execute([$pfx.'%']);
    return $pfx.str_pad((int)$s->fetchColumn()+1,3,'0',STR_PAD_LEFT);
}
```

**Function Signature Updated**:
```php
// Before:
function genBatch(PDO $pdo, string $d): string

// After:
function genBatch(PDO $pdo, string $d, int $station_id): string
```

**Function Call Updated** (Line 105):
```php
// Before:
$batch_id = genBatch($pdo, $delivery_date);

// After:
$batch_id = genBatch($pdo, $delivery_date, $station_id);
```

---

## Benefits

### 1. **Easy Identification**
All items delivered on the same date share the same Batch ID → easy to group and track

### 2. **Simplified Reporting**
```sql
-- Get all items from a specific delivery date
SELECT * FROM deliveries_oversight WHERE batch_id = 'BATCH-20260611-001';
```

### 3. **Better Organization**
Staff and managers can quickly identify which deliveries happened together

### 4. **Consistent Across System**
Both fuel and merchandise use the same logic

---

## Example Scenarios

### Scenario 1: Merchandise Delivery (Same Day, Multiple Items)
```
Date: June 11, 2026
Staff encodes:
  1. Oil Filter (500 pcs) → BATCH-20260611-001
  2. Air Filter (300 pcs) → BATCH-20260611-001 ✅
  3. Spark Plug (1000 pcs) → BATCH-20260611-001 ✅

All 3 items share: BATCH-20260611-001
```

### Scenario 2: Fuel Delivery (Same Day, Multiple Fuel Types)
```
Date: June 11, 2026
Staff encodes:
  1. Diesel (10,000 L) → BATCH-20260611-001
  2. Unleaded (8,000 L) → BATCH-20260611-001 ✅
  3. Premium 95 (5,000 L) → BATCH-20260611-001 ✅

All 3 fuel types share: BATCH-20260611-001
```

### Scenario 3: Next Day Delivery (New Date = New Batch)
```
Date: June 12, 2026
Staff encodes:
  1. Brake Pads (200 pcs) → BATCH-20260612-001 (NEW BATCH)
  2. Brake Fluid (100 L) → BATCH-20260612-001 ✅

New date = New batch number
```

### Scenario 4: Multiple Stations (Different Stations = Different Batches)
```
Date: June 11, 2026

Station A:
  - Item 1 → BATCH-20260611-001
  - Item 2 → BATCH-20260611-001 ✅

Station B (same date, different station):
  - Item 3 → BATCH-20260611-002 (Different station, different batch)
  - Item 4 → BATCH-20260611-002 ✅
```

---

## Technical Details

### Query Logic
```sql
-- Step 1: Check if batch exists for this date + station
SELECT batch_id 
FROM [table]
WHERE batch_id LIKE 'BATCH-20260611-%'
  AND station_id = ?
  AND DATE(delivery_date) = '2026-06-11'
LIMIT 1
```

**Result**:
- **Found**: Reuse existing Batch ID
- **Not Found**: Create new Batch ID

### Tables Affected
1. **`deliveries_oversight`** (Merchandise)
2. **`fuel_deliveries`** (Fuel)

### Columns Used
- `batch_id` (VARCHAR) - The Batch ID itself
- `station_id` (INT) - Station identifier
- `delivery_date` (DATE) - Delivery date for grouping

---

## Files Modified

1. **`public/staff_record_delivery.php`**
   - Updated Batch ID generation logic (lines 186-201)
   - Added existing batch check
   - Added station_id + date filtering

2. **`public/staff_fuel_deliveries.php`**
   - Updated `genBatch()` function (lines 50-69)
   - Added `station_id` parameter
   - Updated function call (line 105)

3. **Deleted Obsolete Files**:
   - ❌ `public/staff_expected_deliveries.php`
   - ❌ `public/staff_delivery_status.php`

---

## Testing Checklist

- [x] Same date merchandise deliveries share Batch ID
- [x] Same date fuel deliveries share Batch ID
- [x] Different dates get different Batch IDs
- [x] Different stations get different Batch IDs
- [x] Existing batch detection works correctly
- [x] New batch creation works when no batch exists
- [x] Batch ID format remains: `BATCH-YYYYMMDD-###`
- [x] Zero-padding works (001, 002, etc.)
- [x] Database queries are efficient

---

## User Impact

### Staff Workflow:
1. Staff encodes multiple deliveries on the same day
2. System automatically assigns same Batch ID
3. Staff sees grouped deliveries in history

### Manager Workflow:
1. Manager approves deliveries
2. Can see all items from same delivery batch
3. Easier to track and validate

### Reporting:
1. Reports can group by Batch ID
2. Easy to generate daily delivery summaries
3. Clear audit trail of what arrived together

---

## Benefits Summary

✅ **Grouping**: All items delivered on same date share Batch ID  
✅ **Clarity**: Easy to identify delivery groups  
✅ **Consistency**: Same logic for fuel & merchandise  
✅ **Efficiency**: Simplified queries and reports  
✅ **Traceability**: Better audit trail  
✅ **User-Friendly**: Intuitive for staff and managers

---

**Implementation Complete** ✅

**Key Principle**: One Date = One Batch ID (per station)
