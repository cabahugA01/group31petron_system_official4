================================================================================
STAFF DELIVERIES REPORT - COMPLETE DOCUMENTATION
================================================================================
Created: June 11, 2026
File: staff_deliveries_report.php
Status: ✓ COMPLETE - SINGLE PAGE (NO TABS)

================================================================================
OVERVIEW:
================================================================================
Comprehensive deliveries tracking report showing BOTH fuel and merchandise
deliveries in a SINGLE PAGE VIEW (no tabs).

Sections included:
1. Fuel Deliveries (complete records table + shift summary + remarks)
2. Merchandise Deliveries (complete records table + shift summary + remarks)

All displayed on one scrollable page.

================================================================================
FEATURES:
================================================================================

TAB 1: FUEL DELIVERIES
-----------------------
Columns:
✓ Delivery ID - Unique identifier
✓ Supplier - Supplier name
✓ Fuel Type - Type of fuel (Diesel, Gasoline 91, 95, 97, etc.)
✓ Quantity (L) - Liters delivered
✓ Unit Price - Price per liter
✓ Total Amount - Auto-calculated total
✓ Date - Delivery date
✓ PO Reference - Purchase order reference number
✓ Expected - Expected quantity from PO
✓ Actual - Actual quantity received
✓ Variance - Difference (Expected - Actual)
✓ Status - Delivery status (Pending, Completed, Rejected, etc.)
✓ Shift - Which shift received (Shift 1 or Shift 2)
✓ Encoder - Staff who recorded the delivery
✓ Remarks - Issues like damaged drums, shortages, delays

Shift Summary:
✓ Shift 1 (6AM-2PM) - Total deliveries count & total amount
✓ Shift 2 (2PM-10PM) - Total deliveries count & total amount

Remarks Section:
✓ Lists all deliveries with remarks/issues
✓ Shows delivery ID, remark text, and date
✓ Highlights: damaged drums, shortages, delayed deliveries

TAB 2: MERCHANDISE DELIVERIES
------------------------------
Columns:
✓ Delivery ID - Unique identifier
✓ Supplier - Supplier name
✓ Product Name - Merchandise item name
✓ Quantity - Items delivered
✓ Unit Price - Price per item
✓ Total Amount - Auto-calculated total
✓ Date - Delivery date
✓ PO Reference - Purchase order reference number
✓ Expected - Expected quantity from PO
✓ Actual - Actual quantity received
✓ Variance - Difference (Expected - Actual)
✓ Status - Delivery status (Pending, Completed, Rejected, etc.)
✓ Shift - Which shift received (Shift 1 or Shift 2)
✓ Encoder - Staff who recorded the delivery
✓ Remarks - Issues like damaged items, wrong sizes, shortages

Shift Summary:
✓ Shift 1 (6AM-2PM) - Total deliveries count & total amount
✓ Shift 2 (2PM-10PM) - Total deliveries count & total amount

Remarks Section:
✓ Lists all deliveries with remarks/issues
✓ Shows delivery ID, remark text, and date
✓ Highlights: damaged items, wrong sizes, shortages

================================================================================
DESIGN:
================================================================================
✓ Plain black & white only (no colors or icons)
✓ Clean tabbed interface
✓ Black borders throughout
✓ Professional table layout
✓ Print-ready format
✓ Responsive design

================================================================================
FUNCTIONALITY:
================================================================================
Date Range Filter:
✓ Select start date and end date
✓ Apply button to filter results
✓ Defaults to current month

Tab Switching:
✓ Click tabs to switch between Fuel and Merchandise
✓ Each tab loads separately with its own data
✓ Maintains date range when switching

Export Options:
✓ Print Report - Browser print dialog
✓ Export Excel - Download as spreadsheet

Data Sources:
✓ fuel_deliveries table (for fuel tab)
✓ merchandise_deliveries table (for merchandise tab)
✓ suppliers table (joined for supplier names)
✓ fuel_types table (joined for fuel type names)
✓ products table (joined for product names)

Calculations:
✓ Variance = Expected - Actual
✓ Shift totals = SUM(total_amount) per shift
✓ Delivery counts per shift

================================================================================
DATABASE REQUIREMENTS:
================================================================================

Required Tables:
1. fuel_deliveries
   - id, delivery_id, station_id, supplier, supplier_id
   - fuel_type, fuel_type_id, quantity, unit_price, total_amount
   - delivery_date, po_reference
   - expected_quantity, actual_quantity, variance
   - status, shift, remarks, encoder, created_at

2. merchandise_deliveries
   - id, delivery_id, station_id, supplier, supplier_id
   - product_name, product_id, quantity, unit_price, total_amount
   - delivery_date, po_reference
   - expected_quantity, actual_quantity, variance
   - status, shift, remarks, encoder, created_at

Optional Tables (for joins):
3. suppliers - name field
4. fuel_types - name field
5. products - name field

================================================================================
NAVIGATION:
================================================================================
Access: Reports → Deliveries Reports
URL: staff_deliveries_report.php
Sidebar: Updated in includes/staff_sidebar.php

================================================================================
SHIFT DEFINITIONS:
================================================================================
Shift 1: 6:00 AM - 2:00 PM (8 hours)
Shift 2: 2:00 PM - 10:00 PM (8 hours)

Note: System identifies shifts by checking if 'shift 1' or '1' 
appears in the shift field (case-insensitive)

================================================================================
REMARKS CATEGORIES:
================================================================================
Fuel Deliveries:
- Damaged drums
- Quantity shortages
- Delayed deliveries
- Quality issues
- Temperature problems
- Contamination

Merchandise Deliveries:
- Damaged items
- Wrong sizes
- Quantity shortages
- Expired products
- Wrong items delivered
- Packaging issues

================================================================================
STATUS VALUES:
================================================================================
- PENDING - Delivery scheduled/in transit
- COMPLETED - Successfully received and verified
- PARTIAL - Partially delivered
- REJECTED - Rejected due to quality/damage
- CANCELLED - Delivery cancelled

================================================================================
VALIDATION PROCESS:
================================================================================
1. Compare PO Reference expected quantity vs actual
2. Calculate variance (positive = over, negative = shortage)
3. Record status based on validation result
4. Add remarks for any issues found
5. Assign to shift that received the delivery

================================================================================
USAGE INSTRUCTIONS:
================================================================================
1. Navigate to Reports → Deliveries Reports
2. Select date range (start and end dates)
3. Click "Apply" to filter results
4. Click tabs to switch between Fuel and Merchandise
5. Review delivery records in the table
6. Check shift summaries for totals
7. Review remarks section for issues
8. Use "Print Report" or "Export Excel" as needed

================================================================================
TROUBLESHOOTING:
================================================================================
No data showing:
- Check if tables exist in database
- Verify station_id is set correctly
- Check date range selected
- Ensure deliveries are recorded for the period

Wrong shift totals:
- Verify shift field contains 'Shift 1', 'Shift 2', '1', or '2'
- Check that total_amount field has numeric values

Missing supplier/product names:
- System uses fallback to ID if join tables don't exist
- Ensure foreign keys are set correctly

================================================================================
TECHNICAL NOTES:
================================================================================
✓ Uses prepared statements for SQL injection protection
✓ Dynamic table detection with graceful fallbacks
✓ Session-based authentication
✓ RBAC permission checking
✓ Station-specific data filtering
✓ Timezone-aware date handling
✓ All styling in pure black & white

================================================================================
