# Fuel Transaction Table - Paper Form Format

## Overview
Redesigned the fuel transaction encoding table to match the exact format of the paper form, with columns for **Beginning Reading**, **Ending Reading**, and **Amount** for each tanker.

## Table Structure (Matches Paper Form)

```
┌─────────────┬──────────────────────────────────────────────────────────────────────────────────┬──────────────┬────────┬─────────┐
│             │                    TANKER 1                TANKER 2                TANKER 3       │              │        │         │
│   Product   │ Beginning │ Ending │ Amount │ Beginning │ Ending │ Amount │ Beginning │ Ending  │ Total Liters │ Notes  │ Actions │
├─────────────┼───────────┼────────┼────────┼───────────┼────────┼────────┼───────────┼─────────┼──────────────┼────────┼─────────┤
│ Diesel 1    │  10000.00 │10500.50│ ₱500.50│  9800.00  │10300.00│ ₱500.00│  10100.00 │10600.25 │   2001.50 L  │        │ Submit  │
│             │ (4 tankers: Tanker 1, 2, 3, 4)                                                    │              │        │ Reset   │
├─────────────┼───────────┼────────┼────────┼───────────┼────────┼────────┼───────────┼─────────┼──────────────┼────────┼─────────┤
│ Diesel 2    │  15000.00 │15400.00│ ₱400.00│  14800.00 │15200.50│ ₱400.50│    ---    │   ---   │    800.50 L  │        │ Submit  │
│             │ (2 tankers: Tanker 5, 6) → shown in columns for Tanker 1 & 2                     │              │        │ Reset   │
├─────────────┼───────────┼────────┼────────┼───────────┼────────┼────────┼───────────┼─────────┼──────────────┼────────┼─────────┤
│ Turbo Diesel│  12000.00 │12350.00│ ₱350.00│  11900.00 │12250.00│ ₱350.00│    ---    │   ---   │    700.00 L  │        │ Submit  │
│             │ (2 tankers: Tanker 1, 2)                                                          │              │        │ Reset   │
└─────────────┴───────────┴────────┴────────┴───────────┴────────┴────────┴───────────┴─────────┴──────────────┴────────┴─────────┘
```

## Column Structure

### Fixed 4 Tanker Columns
The table always displays 4 tanker column groups, but only shows input fields for applicable tankers:

| Fuel Type | Uses Tanker Columns | Actual Tankers |
|-----------|-------------------|----------------|
| **Diesel 1** | 1, 2, 3, 4 | Tanker 1-4 |
| **Diesel 2** | 1, 2 (empty 3,4) | Tanker 5-6 |
| **Turbo Diesel** | 1, 2 (empty 3,4) | Tanker 1-2 |
| **XCS Plus** | 1, 2, 3, 4 | Tanker 1-4 |
| **Kerosene** | 1 (empty 2,3,4) | Tanker 1 |
| **XTRA UNL 1** | 1, 2 (empty 3,4) | Tanker 1-2 |
| **XTRA UNL 2** | 1, 2 (empty 3,4) | Tanker 3-4 |

### Per Tanker - 3 Sub-columns

1. **Beginning Reading** (Manual Input)
   - Previous/starting meter reading
   - Number input, 2 decimal places
   - Default: blank/0.00

2. **Ending Reading** (Manual Input, Required)
   - Current/ending meter reading
   - Number input, 2 decimal places
   - Required field with colored border
   - Must be ≥ Beginning Reading

3. **Amount** (Auto-Calculated, Read-only)
   - Formula: `(Ending - Beginning) × Price per Liter`
   - Displayed in Philippine Peso format: ₱XXX.XX
   - Blue background highlight
   - Read-only field

### Additional Columns

4. **Total Liters** (Auto-Calculated)
   - Sum of all tanker liters for this fuel type
   - Formula: `Sum of (Ending - Beginning) for all tankers`
   - Displayed as: `XXX.XX L`
   - Yellow background highlight
   - Updates in real-time

5. **Notes** (Optional)
   - Text input for remarks
   - Max 255 characters

6. **Actions**
   - Submit button (submits this fuel row)
   - Reset button (clears all inputs for this row)

## Tanker Number Mapping

### Logic Implementation
```php
if ($ft_lower === 'diesel 1' && $t >= 1 && $t <= 4) {
    $tanker_num = $t; // Shows as Tanker 1-4
}
elseif ($ft_lower === 'diesel 2' && $t >= 1 && $t <= 2) {
    $tanker_num = 4 + $t; // Shows as Tanker 5-6 (in columns 1-2)
}
elseif ($ft_lower === 'turbo diesel' && $t >= 1 && $t <= 2) {
    $tanker_num = $t; // Shows as Tanker 1-2
}
elseif ($ft_lower === 'xcs plus' && $t >= 1 && $t <= 4) {
    $tanker_num = $t; // Shows as Tanker 1-4
}
elseif ($ft_lower === 'kerosene' && $t === 1) {
    $tanker_num = 1; // Shows as Tanker 1
}
elseif ($ft_lower === 'xtra unl 1' && $t >= 1 && $t <= 2) {
    $tanker_num = $t; // Shows as Tanker 1-2
}
elseif ($ft_lower === 'xtra unl 2' && $t >= 1 && $t <= 2) {
    $tanker_num = 2 + $t; // Shows as Tanker 3-4 (in columns 1-2)
}
```

## Real-time Calculations

### JavaScript Functions

#### 1. `updateTankerCalc(ftId, tankerNum, pricePerLiter)`
**Calculates per tanker:**
- Liters = Ending Reading - Beginning Reading
- Amount = Liters × Price per Liter
- Updates display fields
- Triggers total update

#### 2. `updateFuelTotal(ftId)`
**Calculates fuel type total:**
- Sums all tanker liters for the fuel type
- Updates "Total Liters" column
- Runs after each tanker calculation

### Calculation Flow
```
User enters Beginning → User enters Ending → Auto-calculate Liters
     ↓                        ↓                       ↓
   10000.00               10500.50                 500.50 L
                                                      ↓
                                          Calculate Amount (500.50 × Price)
                                                      ↓
                                                   ₱500.50
                                                      ↓
                                              Update Fuel Total
                                              (sum all tankers)
                                                      ↓
                                                  2001.50 L
```

## Form Data Structure

### Input Field Names
```
Product: Diesel 1 (4 tankers)
┌─────────────────────────────────────────┐
│ tanker_beginning_1: 10000.00            │
│ tanker_ending_1: 10500.50               │
│ tanker_liters_1: 500.50 (hidden)       │
│                                         │
│ tanker_beginning_2: 9800.00             │
│ tanker_ending_2: 10300.00               │
│ tanker_liters_2: 500.00 (hidden)       │
│                                         │
│ tanker_beginning_3: 10100.00            │
│ tanker_ending_3: 10600.25               │
│ tanker_liters_3: 500.25 (hidden)       │
│                                         │
│ tanker_beginning_4: 9900.00             │
│ tanker_ending_4: 10400.75               │
│ tanker_liters_4: 500.75 (hidden)       │
│                                         │
│ total_liters: 2001.50 (hidden)         │
│ price_per_liter: 1.00 (hidden)         │
│ calibration: 0.000 (hidden)            │
│ notes: "All readings verified"          │
└─────────────────────────────────────────┘
```

### Example Submission Data
```javascript
FormData {
  action: "encode_reading"
  fuel_type: "Diesel 1"
  station_id: 1
  staff_id: 21
  shift_period: "morning"
  
  tanker_beginning_1: "10000.00"
  tanker_ending_1: "10500.50"
  tanker_liters_1: "500.50"
  
  tanker_beginning_2: "9800.00"
  tanker_ending_2: "10300.00"
  tanker_liters_2: "500.00"
  
  tanker_beginning_3: "10100.00"
  tanker_ending_3: "10600.25"
  tanker_liters_3: "500.25"
  
  tanker_beginning_4: "9900.00"
  tanker_ending_4: "10400.75"
  tanker_liters_4: "500.75"
  
  total_liters: "2001.50"
  price_per_liter: "1.00"
  calibration: "0.000"
  notes: "All readings verified"
}
```

## Visual Design Features

### Color Coding
- **Product Name** - Color-coded icon and text matching fuel type
- **Ending Reading** - Border color matches fuel type (emphasizes required field)
- **Amount** - Blue background (`#e0f2fe`) with blue text
- **Total Liters** - Yellow background (`#fef08a`) with dark text
- **Table Borders** - Light gray (`#e2e8f0`) for clean separation

### Input Sizing
- **Beginning/Ending** - 80px width (compact for meter readings)
- **Amount** - 90px width (fits currency format)
- **Total Liters** - 90px width
- **Notes** - 150px width (flexible for text)

### Responsive Layout
- Horizontal scrolling enabled for small screens
- Fixed column structure maintains alignment
- Compact sizing optimizes space usage

## User Experience

### Data Entry Flow
1. **Select Fuel Row** - Staff identifies which fuel to encode
2. **Enter Beginning Readings** - Input starting meter values for each applicable tanker
3. **Enter Ending Readings** - Input current meter values (required)
4. **View Calculations** - System automatically shows:
   - Amount per tanker (₱ format)
   - Total liters for fuel type
5. **Add Notes** - Optional remarks
6. **Submit** - Click submit to save
7. **Verify** - Check success message and review totals

### Visual Feedback
- **Real-time updates** - Amounts and totals update as you type
- **Required field indicators** - Colored borders on ending readings
- **Auto-formatting** - Currency displays with ₱ symbol
- **Clear separation** - Table borders organize data visually
- **Color-coded products** - Easy fuel type identification

## Benefits

✅ **Exact Paper Form Match** - Digital version mirrors physical form  
✅ **Familiar Layout** - Staff recognize the structure immediately  
✅ **Clear Column Headers** - Beginning, Ending, Amount clearly labeled  
✅ **Auto-Calculations** - Reduces manual calculation errors  
✅ **Proper Tanker Numbering** - Follows business requirements exactly  
✅ **Real-time Totals** - See cumulative liters instantly  
✅ **Complete Audit Trail** - All tanker data preserved  
✅ **Easy Data Entry** - Compact inputs, logical flow  

## Technical Implementation

### Files Modified
- `public/staff_transactions_hub.php`

### Key Components
1. **PHP** - Dynamic table generation with tanker mapping logic
2. **HTML** - Structured table with fixed 4-tanker column layout
3. **JavaScript** - Real-time calculation functions
4. **CSS** - Inline styling for compact, organized appearance

### Validation Rules
- Ending reading must be provided (required)
- Ending must be ≥ Beginning (prevents negative liters)
- At least one tanker must have ending reading per fuel type
- Hidden fields pass calculated values to backend

## Status
✅ **COMPLETE** - Table structure matches paper form exactly  
✅ **CALCULATIONS WORKING** - Real-time amount and total updates  
✅ **TANKER MAPPING CORRECT** - Proper numbering per fuel type  
⏳ **BACKEND PENDING** - API needs to process tanker array data

The fuel transaction table now exactly matches the paper form structure with Beginning, Ending, and Amount columns for each tanker!
