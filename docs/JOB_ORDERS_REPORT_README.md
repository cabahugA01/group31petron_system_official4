# Job Orders Report - Staff Side

## Overview
The Job Orders Report module provides comprehensive daily reporting for staff to track and monitor all job orders with shift summaries, payment breakdowns, and export capabilities.

## Features

### Header Section
- **Report Title**: DAILY JOB ORDER REPORTS
- **Station Name & Location**: Auto-populated from database
- **Date & Shift**: Auto-generated based on selected filters
- **Export Buttons**: Excel, CSV, PDF formats available in upper-right corner

### Job Order Table
The main table displays all job orders with the following columns:
- **Job Order ID**: Unique identifier for each job (e.g., JO-001, JO-00245)
- **Customer Name / ID**: Customer information or "Walk-in" for unregistered customers
- **Service Type**: Service category (Oil Change, Tire Replacement, Calibration, etc.)
- **Parts / Materials Used**: Items taken from inventory with details
- **Quantity**: Number of pieces/liters used
- **Unit Price**: Price per item/service
- **Total Amount**: Auto-computed (quantity × unit price)
- **Payment Mode**: Cash, Credit, or Suki
- **Status**: Pending, In Progress, Completed, Paid/Unpaid
- **Staff Encoder**: Staff member who encoded the job order
- **Remarks**: Additional notes (discounts, rejections, rescheduling)

### Shift Summaries
Three shift periods tracked:
- **Shift 1** (6:00 AM - 2:00 PM)
- **Shift 2** (2:00 PM - 10:00 PM)
- **Shift 3** (10:00 PM - 6:00 AM)

Each shift summary includes:
- Total services count
- Total amount earned
- Cash breakdown
- Credit breakdown
- Completed jobs count

### Overall Daily Summary
Combined totals across all shifts:
- Total job orders
- Total amount
- Completed jobs
- Pending/Active jobs
- Unpaid jobs
- Cancelled jobs
- Cash vs Credit breakdown

### Additional Summaries
Three specialized views:

1. **Unpaid Job Orders**
   - Lists all jobs with pending payments
   - Shows customer, service type, and amount owed

2. **Completed Job Orders**
   - Finished services with receipts
   - Payment information included

3. **Cancelled Job Orders**
   - Rejected or voided entries
   - Reason for cancellation shown

## File Locations

### Main Report File
- **Path**: `/public/staff_job_orders_report.php`
- **Access**: Staff, Cashier, Pump Attendant, Manager, Admin roles

### Backend Support
- **Export Backend**: `/backend/export_job_orders_report.php`
- **Job Order Operations**: `/backend/job_order_operations.php`

### Navigation
- **Sidebar**: Updated in `/includes/staff_sidebar.php`
- **Menu Item**: "Job Orders Reports" under Reports section

## Database Tables Used

### Primary Tables
- `job_orders` - Main job order records
- `customers` - Customer information
- `service_categories` - Service type definitions
- `mechanics` - Mechanic assignments
- `job_order_parts` - Parts/materials used
- `products` - Product catalog
- `users` - Staff encoder information

### Optional Tables
The system gracefully handles missing tables with fallback logic.

## Usage

### Viewing Reports
1. Navigate to **Reports → Job Orders Reports** in the sidebar
2. Select desired **Report Date** (defaults to today)
3. Choose **Shift** filter (All Shifts, Shift 1, 2, or 3)
4. Click **Apply** button to refresh data

### Exporting Reports
Click any export button in the upper-right:
- **Excel**: `.xls` format with full data and summaries
- **CSV**: Comma-separated values for spreadsheet import
- **PDF**: Printable PDF format (uses browser print)

### Report Filters
- **Date Selection**: Choose any past date up to today
- **Shift Filter**: View all shifts or focus on specific shift period
- **Status Filter**: (Future enhancement) Filter by job status

## Technical Details

### Shift Calculation
Jobs are automatically assigned to shifts based on creation time:
```php
function get_shift_from_time($datetime) {
    $hour = (int)date('H', strtotime($datetime));
    if ($hour >= 6 && $hour < 14) return 'Shift 1';
    elseif ($hour >= 14 && $hour < 22) return 'Shift 2';
    else return 'Shift 3';
}
```

### Dynamic Table Support
The report intelligently checks for table/column existence:
- Falls back gracefully if optional tables don't exist
- Uses COALESCE for null-safe column access
- Dynamic JOIN construction based on available tables

### Security
- Session-based authentication required
- Role-based access control (RBAC)
- Station-specific data filtering
- SQL injection protection via prepared statements

## Styling

### Design System
- **Primary Color**: Purple gradient (#667eea to #764ba2)
- **Card-based Layout**: Modern material design
- **Responsive Tables**: Horizontal scroll on mobile
- **Status Badges**: Color-coded for quick identification
  - Green: Completed/Paid
  - Yellow: Pending
  - Blue: In Progress
  - Red: Cancelled/Unpaid

### Icons
Uses Font Awesome 6.4.0 icons throughout:
- 📋 Clipboard for job orders
- ✓ Check for completed
- ⏳ Hourglass for pending
- 💰 Money for payments
- 🚫 Ban for cancelled

## Future Enhancements

### Planned Features
1. Date range selection (week, month, custom)
2. Advanced filtering (by service type, customer, mechanic)
3. Real-time refresh with AJAX
4. Print-optimized layouts
5. Email report distribution
6. Chart visualizations (pie charts, bar graphs)
7. Performance metrics (average completion time)
8. Customer satisfaction tracking

### Database Optimizations
- Add indexes on frequently queried columns
- Implement report caching for historical data
- Archive old records to improve query speed

## Troubleshooting

### Common Issues

**Issue**: "Job Orders table not found"
- **Solution**: Ensure `job_orders` table exists in database
- **Check**: Run migration scripts if needed

**Issue**: No data showing
- **Solution**: Verify station assignment for current user
- **Check**: Confirm job orders exist for selected date

**Issue**: Export not working
- **Solution**: Check file permissions on server
- **Check**: Verify PHP output buffering settings

**Issue**: Shift summaries incorrect
- **Solution**: Verify system timezone matches station timezone
- **Check**: Review shift time calculations

## Support
For issues or questions, contact the development team or refer to the main system documentation.

## Version History
- **v1.0** (2026-06-11): Initial release with full reporting capabilities
