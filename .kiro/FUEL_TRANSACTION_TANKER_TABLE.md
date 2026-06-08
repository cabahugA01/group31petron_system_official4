# Fuel Transaction Tanker Table Implementation

## Overview
Updated the Staff Fuel Transaction encoding table to display separate columns for each tanker based on the fuel type. Each fuel type now shows the correct number of tanker input columns with proper numbering according to the business requirements.

## Tanker Configuration Per Fuel Type

| Fuel Type | Number of Tankers | Tanker Numbers |
|-----------|------------------|----------------|
| **Diesel 1** | 4 tankers | Tanker 1, 2, 3, 4 |
| **Diesel 2** | 2 tankers | Tanker 5, 6 |
| **Turbo Diesel** | 2 tankers | Tanker 1, 2 |
| **XCS Plus** | 4 tankers | Tanker 1, 2, 3, 4 |
| **Kerosene** | 1 tanker | Tanker 1 |
| **XTRA UNL 1** | 2 tankers | Tanker 1, 2 |
| **XTRA UNL 2** | 2 tankers | Tanker 3, 4 |

## Table Structure

### New Layout
```
┌─────────────┬─────────────────────────────────────────────────┬────────┬─────────┐
│ Fuel Type   │ Tanker Readings                                 │ Notes  │ Actions │
├─────────────┼─────────────────────────────────────────────────┼────────┼─────────┤
│ Diesel 1    │ [Tanker 1] [Tanker 2] [Tanker 3] [Tanker 4]   │        │ Submit  │
│             │ Prev Present Diff  (repeated for each tanker)  │        │ Reset   │
├─────────────┼─────────────────────────────────────────────────┼────────┼─────────┤
│ Diesel 2    │ [Tanker 5] [Tanker 6]                          │        │ Submit  │
├─────────────┼─────────────────────────────────────────────────┼────────┼─────────┤
│ Turbo Diese │ [Tanker 1] [Tanker 2]                          │        │ Submit  │
├─────────────┼─────────────────────────────────────────────────┼────────┼─────────┤
│ XCS Plus    │ [Tanker 1] [Tanker 2] [Tanker 3] [Tanker 4]   │        │ Submit  │
├─────────────┼─────────────────────────────────────────────────┼────────┼─────────┤
│ Kerosene    │ [Tanker 1]                                      │        │ Submit  │
├─────────────┼─────────────────────────────────────────────────┼────────┼─────────┤
│ XTRA UNL 1  │ [Tanker 1] [Tanker 2]                          │        │ Submit  │
├─────────────┼─────────────────────────────────────────────────┼────────┼─────────┤
│ XTRA UNL 2  │ [Tanker 3] [Tanker 4]                          │        │ Submit  │
└─────────────┴─────────────────────────────────────────────────┴────────┴─────────┘
```

### Each Tanker Card Contains
1. **Tanker Header** - Shows tanker number (e.g., "TANKER 1", "TANKER 5")
2. **Previous Reading** - Manual input for previous meter reading
3. **Present Reading** - Manual input for current meter reading (required)
4. **Difference** - Auto-calculated (Present - Previous)

All inputs are **manual** - staff must enter readings for each tanker.

## Features Implemented

### 1. **Dynamic Column Generation**
- PHP loop dynamically creates tanker columns based on fuel type
- Each row shows only the relevant tankers for that fuel type
- Proper tanker numbering according to configuration

### 2. **Visual Design**
- Each tanker is displayed in a card-style box with:
  - Colored header matching the fuel type color
  - Clear labels for each field
  - Distinct border colors for easy identification
  - Auto-calculated difference field highlighted in blue

### 3. **Real-time Calculations**
- JavaScript function `updateTankerCalc()` automatically calculates difference
- Updates as staff enters previous and present readings
- Difference = Present Reading - Previous Reading

### 4. **Form Handling**
- Each fuel row has its own hidden form
- Tanker inputs use `form="fuelForm_..."` attribute to associate with the correct form
- Input names follow pattern: `tanker_prev_{number}`, `tanker_present_{number}`, `tanker_diff_{number}`

### 5. **Validation**
- Requires at least one present reading per fuel type
- All required fields must be filled before submission
- Previous reading cannot be greater than present reading

### 6. **Reset Function**
- Updated `resetCard()` function clears all tanker inputs
- Resets all previous, present, and difference fields
- Clears notes field

## Technical Implementation

### File Modified
`public/staff_transactions_hub.php`

### Key Components

#### 1. **PHP Tanker Configuration Array**
```php
$tanker_config = [
    'diesel 1' => ['count' => 4, 'start' => 1],
    'diesel 2' => ['count' => 2, 'start' => 5],
    'turbo diesel' => ['count' => 2, 'start' => 1],
    'xcs plus' => ['count' => 4, 'start' => 1],
    'kerosene' => ['count' => 1, 'start' => 1],
    'xtra unl 1' => ['count' => 2, 'start' => 1],
    'xtra unl 2' => ['count' => 2, 'start' => 3]
];
```

#### 2. **Dynamic Loop for Tanker Columns**
```php
for ($i = 0; $i < $tanker_count; $i++):
    $tanker_num = $tanker_start + $i;
    // Generate tanker card with inputs
endfor;
```

#### 3. **JavaScript Calculation Function**
```javascript
function updateTankerCalc(ftId, tankerNum) {
    // Get input elements
    // Calculate difference
    // Update difference field
}
```

#### 4. **Input Field Naming Convention**
- Previous: `tanker_prev_{tanker_number}`
- Present: `tanker_present_{tanker_number}`
- Difference: `tanker_diff_{tanker_number}`

Example for Diesel 1:
- `tanker_prev_1`, `tanker_present_1`, `tanker_diff_1`
- `tanker_prev_2`, `tanker_present_2`, `tanker_diff_2`
- `tanker_prev_3`, `tanker_present_3`, `tanker_diff_3`
- `tanker_prev_4`, `tanker_present_4`, `tanker_diff_4`

Example for Diesel 2:
- `tanker_prev_5`, `tanker_present_5`, `tanker_diff_5`
- `tanker_prev_6`, `tanker_present_6`, `tanker_diff_6`

## User Experience

### Staff Workflow
1. **View Table** → See all fuel types with their respective tanker columns
2. **Select Fuel Row** → Choose which fuel type to encode
3. **Enter Previous Readings** → Input previous meter reading for each tanker
4. **Enter Present Readings** → Input current meter reading for each tanker
5. **View Auto-Calculated Difference** → System automatically shows the difference
6. **Add Notes (Optional)** → Add any remarks about the readings
7. **Submit** → Click submit button to save all tanker readings
8. **Reset (if needed)** → Click reset to clear all inputs and start over

### Visual Feedback
- **Color-coded fuel types** - Each fuel type has distinct colors
- **Highlighted inputs** - Present reading fields have colored borders
- **Auto-calculations** - Difference updates in real-time
- **Loading state** - Submit button shows spinner during processing
- **Success/error messages** - Clear feedback after submission

## Benefits

✅ **Accurate Tracking** - Separate readings for each tanker
✅ **Proper Numbering** - Tanker numbers follow business rules
✅ **Clear Organization** - Visual separation per tanker
✅ **Easy Data Entry** - Organized layout minimizes errors
✅ **Auto-Calculation** - Reduces manual calculation errors
✅ **Complete Audit Trail** - All tanker data is preserved
✅ **Flexible Structure** - Easily add/modify fuel types and tankers

## Data Flow

1. **Frontend (Table)**
   - Display tanker columns based on fuel type
   - Capture input for each tanker
   - Calculate differences in real-time

2. **Form Submission**
   - Collect all tanker readings via FormData
   - Submit to `api_fuel_readings.php`
   - Include tanker numbers in field names

3. **Backend Processing** (to be implemented)
   - Parse tanker-specific inputs
   - Store individual tanker readings
   - Calculate totals per fuel type
   - Update inventory
   - Create audit logs

4. **Response**
   - Success/error message
   - Updated readings confirmation
   - Refresh today's entries table

## Example Data Structure

### Diesel 1 Submission
```
fuel_type: "Diesel 1"
tanker_prev_1: 10000.00
tanker_present_1: 10500.50
tanker_diff_1: 500.50

tanker_prev_2: 9800.00
tanker_present_2: 10300.00
tanker_diff_2: 500.00

tanker_prev_3: 10100.00
tanker_present_3: 10600.25
tanker_diff_3: 500.25

tanker_prev_4: 9900.00
tanker_present_4: 10400.75
tanker_diff_4: 500.75

Total Difference: 2001.50 L
```

### Diesel 2 Submission
```
fuel_type: "Diesel 2"
tanker_prev_5: 15000.00
tanker_present_5: 15400.00
tanker_diff_5: 400.00

tanker_prev_6: 14800.00
tanker_present_6: 15200.50
tanker_diff_6: 400.50

Total Difference: 800.50 L
```

## Next Steps (Backend Integration)

To complete the implementation, the backend (`api_fuel_readings.php`) needs to:

1. **Parse Tanker Inputs**
   - Extract all `tanker_prev_*`, `tanker_present_*`, `tanker_diff_*` fields
   - Group by tanker number

2. **Calculate Totals**
   - Sum all differences to get total fuel sold
   - Calculate total amount (total liters × price per liter)

3. **Store Data**
   - Save individual tanker readings (for audit trail)
   - Save totals in fuel_transactions table
   - Link tanker readings to main transaction

4. **Update Inventory**
   - Deduct total liters from fuel_inventory
   - Update stock levels

5. **Create Logs**
   - Record individual tanker entries
   - Create comprehensive audit trail

## Status

✅ **FRONTEND COMPLETE** - Table structure, inputs, and calculations working
⏳ **BACKEND PENDING** - API endpoint needs to handle tanker-specific data

The fuel transaction table now displays separate columns for each tanker according to business requirements. Staff can manually enter readings for all tankers in an organized, easy-to-use interface.
