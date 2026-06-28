# FUEL INVENTORY FORMULAS - OFFICIAL REFERENCE

## Complete & Exact Formulas for Petron System

---

## 1. FUEL TRANSACTION FORMULA (Dispensed Liters)

**Purpose:** Calculate actual fuel dispensed from pump  
**Source:** Deduction from inventory

### Formula:
```
Dispensed Liters = (Ending Reading - Beginning Reading) - Calibration
```

### Example:
```
Beginning Reading:  2,380,437 L
Ending Reading:     2,380,621 L
Calibration:        4 L

Dispensed Liters = (2,380,621 - 2,380,437) - 4
                 = 184 - 4
                 = 180 Liters
```

### Database Implementation:
```sql
dispensed_liters = (ending_reading - beginning_reading) - calibration
```

---

## 2. FUEL SALES FORMULA (Halin)

**Purpose:** Calculate total sales amount from fuel dispensed

### Formula:
```
Sales Amount = Dispensed Liters × Price per Liter
```

### Example:
```
Dispensed Liters:   180 L
Price per Liter:    ₱64.35

Sales Amount = 180 × ₱64.35
             = ₱11,583.00
```

### Database Implementation:
```sql
sales_amount = dispensed_liters × price_per_liter
```

---

## 3. FUEL INVENTORY FORMULA (Current Level)

### 3.1 Without Fuel Delivery (Normal Operation)

**Purpose:** Update inventory after fuel transaction

### Formula:
```
Current Fuel Level = Previous Current Fuel Level - Dispensed Liters
```

### Example:
```
Previous Level:     50,000 L
Dispensed:          180 L

Current Level = 50,000 - 180
              = 49,820 L
```

### Database Implementation:
```sql
current_level = current_level - dispensed_liters
```

---

### 3.2 With Fuel Delivery (Delivery Day)

**Purpose:** Update inventory with new delivery and transaction

### Formula:
```
Current Fuel Level = Previous Current Level + Verified Fuel Delivery - Dispensed Liters
```

### Example:
```
Previous Level:     49,820 L
Delivery:           10,000 L
Dispensed:          180 L

Current Level = 49,820 + 10,000 - 180
              = 59,640 L
```

### Database Implementation:
```sql
current_level = current_level + delivered_liters - dispensed_liters
```

---

## 4. FINAL MASTER FORMULA

### Complete Formula (All Operations):
```
Current Fuel Level = Previous Current Fuel Level 
                   + Verified Fuel Deliveries 
                   - Validated Fuel Transactions
```

### Where:
```
Validated Fuel Transactions = (Ending Reading - Beginning Reading) - Calibration
```

### Expanded Master Formula:
```
Current Fuel Level = Previous Current Fuel Level 
                   + Verified Fuel Deliveries 
                   - [(Ending Reading - Beginning Reading) - Calibration]
```

---

## 5. DATABASE FLOW

```
┌──────────────────────┐
│  Fuel Delivery       │
│  (Manager Verified)  │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────────────┐
│  Current Fuel Level          │
│  (Inventory Updated)         │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│  Fuel Transaction            │
│  - Beginning Reading         │
│  - Ending Reading            │
│  - Calibration               │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│  Calculate Dispensed Liters  │
│  = (End - Begin) - Calib     │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│  Manager Validation          │
│  (Approve/Reject)            │
└──────────┬───────────────────┘
           │
           ▼
┌──────────────────────────────┐
│  Update Fuel Inventory       │
│  Current = Current - Disp    │
└──────────────────────────────┘
```

---

## 6. FORMULA COMPONENTS

| Component | Description | Example |
|-----------|-------------|---------|
| **Beginning Reading** | Pump meter reading at shift start | 2,380,437 L |
| **Ending Reading** | Pump meter reading at shift end | 2,380,621 L |
| **Calibration** | Pump calibration/test amount | 4 L |
| **Dispensed Liters** | Actual fuel sold to customers | 180 L |
| **Price per Liter** | Current fuel price | ₱64.35 |
| **Sales Amount** | Total sales revenue | ₱11,583.00 |
| **Previous Level** | Tank level before transaction | 50,000 L |
| **Current Level** | Tank level after transaction | 49,820 L |
| **Delivered Liters** | Fuel received from supplier | 10,000 L |

---

## 7. CALCULATION SEQUENCE

### Step-by-Step Process:

**Step 1: Calculate Dispensed Liters**
```
Dispensed = (Ending - Beginning) - Calibration
```

**Step 2: Calculate Sales Amount**
```
Sales = Dispensed × Price per Liter
```

**Step 3: Update Current Fuel Level**
```
If NO delivery:
    Current Level = Previous Level - Dispensed

If WITH delivery:
    Current Level = Previous Level + Delivery - Dispensed
```

---

## 8. SQL IMPLEMENTATION

### Transaction Validation:
```sql
-- Calculate dispensed liters
UPDATE fuel_transactions
SET dispensed_liters = (ending_reading - beginning_reading) - calibration
WHERE transaction_id = ?;

-- Calculate sales amount
UPDATE fuel_transactions
SET sales_amount = dispensed_liters × price_per_liter
WHERE transaction_id = ?;
```

### Inventory Update (No Delivery):
```sql
UPDATE fuel_inventory
SET current_level = current_level - dispensed_liters,
    updated_at = NOW()
WHERE tank_id = ? AND fuel_type = ?;
```

### Inventory Update (With Delivery):
```sql
UPDATE fuel_inventory
SET current_level = current_level + delivered_liters - dispensed_liters,
    updated_at = NOW()
WHERE tank_id = ? AND fuel_type = ?;
```

---

## 9. VALIDATION RULES

### Business Rules:

1. **Dispensed Liters cannot be negative**
   ```
   IF (Ending - Beginning - Calibration) < 0 THEN
       Flag as discrepancy
   ```

2. **Ending Reading must be greater than Beginning Reading**
   ```
   IF Ending_Reading <= Beginning_Reading THEN
       Reject transaction
   ```

3. **Current Level cannot be negative**
   ```
   IF (Current_Level - Dispensed) < 0 THEN
       Flag as "Out of Stock" or discrepancy
   ```

4. **Calibration is typically small**
   ```
   IF Calibration > 10 THEN
       Flag for manager review
   ```

---

## 10. EXAMPLE SCENARIOS

### Scenario A: Normal Day (No Delivery)

**Initial State:**
- Current Level: 50,000 L

**Transaction:**
- Beginning: 2,380,437
- Ending: 2,380,621
- Calibration: 4

**Calculation:**
```
Dispensed = (2,380,621 - 2,380,437) - 4 = 180 L
Current Level = 50,000 - 180 = 49,820 L
```

**Result:** Inventory reduced by 180 L

---

### Scenario B: Delivery Day

**Initial State:**
- Current Level: 49,820 L

**Delivery:**
- Delivered: 10,000 L

**Transaction:**
- Beginning: 2,380,621
- Ending: 2,380,801
- Calibration: 4

**Calculation:**
```
Dispensed = (2,380,801 - 2,380,621) - 4 = 176 L
Current Level = 49,820 + 10,000 - 176 = 59,644 L
```

**Result:** Inventory increased by delivery, reduced by sales

---

### Scenario C: Multiple Transactions Same Day

**Initial State:**
- Current Level: 59,644 L

**Transaction 1 (Morning):**
- Dispensed: 180 L
- Current Level = 59,644 - 180 = 59,464 L

**Transaction 2 (Afternoon):**
- Dispensed: 156 L
- Current Level = 59,464 - 156 = 59,308 L

**Transaction 3 (Evening):**
- Dispensed: 198 L
- Current Level = 59,308 - 198 = 59,110 L

**Result:** Total dispensed today: 534 L

---

## 11. SYSTEM TABLES INVOLVED

### Primary Tables:

**1. `fuel_inventory`**
```sql
- tank_id
- fuel_type
- current_level        ← UPDATED by formula
- beginning_reading
- ending_reading
- capacity
- updated_at
```

**2. `fuel_transactions`**
```sql
- transaction_id
- tank_id
- beginning_reading
- ending_reading
- calibration
- dispensed_liters     ← CALCULATED
- price_per_liter
- sales_amount         ← CALCULATED
- status
```

**3. `fuel_deliveries`**
```sql
- delivery_id
- tank_id
- fuel_type
- delivered_liters
- verified_by
- status
```

---

## 12. QUICK REFERENCE CARD

```
╔═══════════════════════════════════════════════════════════╗
║         FUEL INVENTORY FORMULAS - QUICK REFERENCE         ║
╠═══════════════════════════════════════════════════════════╣
║                                                           ║
║  1. DISPENSED LITERS                                      ║
║     = (Ending - Beginning) - Calibration                  ║
║                                                           ║
║  2. SALES AMOUNT                                          ║
║     = Dispensed Liters × Price per Liter                  ║
║                                                           ║
║  3. CURRENT FUEL LEVEL (No Delivery)                      ║
║     = Previous Level - Dispensed                          ║
║                                                           ║
║  4. CURRENT FUEL LEVEL (With Delivery)                    ║
║     = Previous Level + Delivery - Dispensed               ║
║                                                           ║
║  5. MASTER FORMULA                                        ║
║     = Previous + Deliveries - Transactions                ║
║                                                           ║
╚═══════════════════════════════════════════════════════════╝
```

---

## 13. NOTES & REMINDERS

### Important Points:

1. **Always calculate dispensed liters FIRST** before updating inventory
2. **Manager validation is REQUIRED** before inventory update
3. **Deliveries must be verified** before adding to current level
4. **Calibration is ALWAYS subtracted** (it's test fuel, not sold)
5. **Negative values indicate discrepancy** - flag for review
6. **Order matters:** Delivery first, then deduct transactions
7. **Each transaction independently** updates inventory
8. **Audit trail required** for all inventory changes

---

## 14. FORMULA VALIDATION CHECKLIST

**Before Processing:**
- [ ] Ending Reading > Beginning Reading
- [ ] Calibration is reasonable (< 10L typically)
- [ ] Current Level sufficient for dispensed amount
- [ ] Manager approval obtained
- [ ] Price per liter is current/correct

**After Processing:**
- [ ] Dispensed Liters is positive
- [ ] Current Level is not negative
- [ ] Sales Amount matches expected revenue
- [ ] Audit log created
- [ ] Inventory history updated

---

**Document Version:** 1.0  
**Last Updated:** June 28, 2026  
**Status:** Official Reference  
**Author:** Petron Station Management System

---

## END OF FUEL INVENTORY FORMULAS
