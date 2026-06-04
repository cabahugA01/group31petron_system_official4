# Implementation Plan: Staff Inventory Module

## Overview

This implementation plan outlines the complete development tasks for the Staff Inventory Module, organized into 12 phases covering database setup, core infrastructure, feature implementation, testing, and deployment. The plan includes 33 major task groups with 280+ individual tasks, estimated timeline of 43 days for sequential development (25-30 days with parallel work), and clear dependencies between phases.

## Tasks

## Phase 1: Database Setup and Core Infrastructure

### 1. Database Schema Implementation
- [ ] 1.1 Create merchandise_deliveries table with all specified columns
- [ ] 1.2 Create merchandise_delivery_items table with foreign key constraints
- [ ] 1.3 Add indexes for performance optimization (stock_requests, station_inventory, fuel_inventory, deliveries)
- [ ] 1.4 Verify foreign key relationships and constraints
- [ ] 1.5 Create database migration script for deployment
- [ ] 1.6 Test rollback procedures for database changes

### 2. Core PHP Classes and Functions
- [ ] 2.1 Create DataLayerManager class with all database operation methods
- [ ] 2.2 Create ValidationEngine class with all validation methods
- [ ] 2.3 Create InputSanitizer class for input sanitization
- [ ] 2.4 Implement CSRF token generation and validation functions
- [ ] 2.5 Create audit logging function (logInventoryAction)
- [ ] 2.6 Implement session management functions (checkStaffAccess, validateStationAccess)
- [ ] 2.7 Write unit tests for ValidationEngine
- [ ] 2.8 Write unit tests for DataLayerManager

## Phase 2: Navigation and UI Framework

### 3. Main Dashboard and Navigation
- [ ] 3.1 Create staff_inventory_dashboard.php with tab navigation structure
- [ ] 3.2 Implement back button functionality on all tabs
- [ ] 3.3 Create staff_inventory_navigation.js for tab switching logic
- [ ] 3.4 Create staff_inventory.css with responsive design
- [ ] 3.5 Implement navigation timing requirement (< 1 second)
- [ ] 3.6 Add confirmation prompt for back button when unsaved data exists
- [ ] 3.7 Test navigation flow across all tabs and modals

### 4. Summary Cards Component
- [ ] 4.1 Create summary_cards.js with auto-refresh logic (30-second intervals)
- [ ] 4.2 Create get_summary_stats.php backend endpoint
- [ ] 4.3 Implement summary card generation for Merchandise Inventory tab
- [ ] 4.4 Implement summary card generation for Fuel Inventory tab
- [ ] 4.5 Implement summary card generation for Stock Requests tab
- [ ] 4.6 Implement summary card generation for Stock-In tab
- [ ] 4.7 Implement summary card generation for Inventory History tab
- [ ] 4.8 Add error handling for failed summary card loads (block tab access per Requirement 6.3)
- [ ] 4.9 Test summary card refresh on inventory changes
- [ ] 4.10 Test summary card loading failure behavior

## Phase 3: Merchandise Inventory View

### 5. Merchandise Inventory Interface
- [ ] 5.1 Create staff_merchandise_inventory.php page with item list table (read-only)
- [ ] 5.2 Implement searchable and sortable merchandise table
- [ ] 5.3 Create staff_merchandise_manager.js for client-side display logic
- [ ] 5.4 Implement color-coded stock status indicators (Green=OK, Yellow=Low, Red=Out)
- [ ] 5.5 Display all merchandise items with SKU, name, category, stock level, and status
- [ ] 5.6 Implement pagination for merchandise list (50 items per page)
- [ ] 5.7 Implement auto-refresh every 30 seconds
- [ ] 5.8 Test merchandise item display and search functionality
- [ ] 5.9 Ensure NO encode/edit functionality is available to staff

### 6. Merchandise Display Backend
- [ ] 6.1 Create get_merchandise_inventory.php endpoint with pagination
- [ ] 6.2 Implement stock status calculation (OK/Low/Out)
- [ ] 6.3 Implement search and filter logic
- [ ] 6.4 Test merchandise read-only display
- [ ] 6.5 Verify color-coding matches stock levels correctly

## Phase 4: Fuel Inventory Management

### 7. Fuel Inventory Interface
- [ ] 7.1 Create staff_fuel_inventory.php page with fuel levels display
- [ ] 7.2 Create add_fuel_reading_modal.php with all form fields
- [ ] 7.3 Create staff_fuel_manager.js for client-side logic
- [ ] 7.4 Auto-populate previous reading from fuel_inventory.last_reading
- [ ] 7.5 Implement real-time fuel usage calculation (current - previous)
- [ ] 7.6 Display warning when current reading < previous reading
- [ ] 7.7 Require confirmation for lower current reading
- [ ] 7.8 Validate delivery date (cannot be future date)
- [ ] 7.9 Test fuel reading form and calculations

### 8. Fuel Reading Backend
- [ ] 8.1 Create save_fuel_reading.php endpoint
- [ ] 8.2 Implement fuel reading validation
- [ ] 8.3 Calculate fuel usage and update fuel_inventory.stock_level
- [ ] 8.4 Update last_reading and last_reading_date
- [ ] 8.5 Create get_fuel_inventory.php endpoint
- [ ] 8.6 Implement auto-update of fuel levels display (< 2 seconds)
- [ ] 8.7 Log all fuel reading actions
- [ ] 8.8 Test fuel reading save and stock level updates
- [ ] 8.9 Test warning confirmation for lower readings

## Phase 5: Stock Request Generation

### 9. Stock Request Interface
- [ ] 9.1 Implement Stock Request button display logic (show only when low/out-of-stock items exist)
- [ ] 9.2 Display count of items needing attention on button
- [ ] 9.3 Create generate_stock_request_modal.php with filtered item list
- [ ] 9.4 Create stock_request_generator.js for client-side logic
- [ ] 9.5 Display current stock, reorder level, and suggested quantity
- [ ] 9.6 Disable manual quantity editing (auto-calculated only)
- [ ] 9.7 Allow remarks editing
- [ ] 9.8 Hide Stock Request button when all items adequately stocked
- [ ] 9.9 Test Stock Request button visibility logic

### 10. Stock Request Backend
- [ ] 10.1 Create get_low_stock_items.php endpoint for merchandise
- [ ] 10.2 Create get_low_stock_items.php endpoint for fuel
- [ ] 10.3 Implement suggested quantity calculation logic
- [ ] 10.4 Create generate_stock_request.php endpoint
- [ ] 10.5 Implement duplicate request check (same item, same day per Requirement 3.6)
- [ ] 10.6 Set status='Pending' only after successful submission (Requirement 3.4)
- [ ] 10.7 Retain phantom pending requests for manual review (Requirement 3.8)
- [ ] 10.8 Route requests to Manager_Review_Interface
- [ ] 10.9 Log all stock request generation actions
- [ ] 10.10 Test duplicate request prevention
- [ ] 10.11 Test stock request routing to Manager module

### 11. Stock Requests Tab
- [ ] 11.1 Create staff_stock_requests.php page
- [ ] 11.2 Create get_stock_requests.php endpoint with status filtering
- [ ] 11.3 Implement request list display with pagination
- [ ] 11.4 Display status progression (Pending → Validated → Completed)
- [ ] 11.5 Implement request details view (expandable)
- [ ] 11.6 Show timestamps for each status transition
- [ ] 11.7 Display requesting staff and validating manager names
- [ ] 11.8 Implement filtering by status, date range, and item type
- [ ] 11.9 Test request list display and filtering
- [ ] 11.10 Test status progression display

## Phase 6: Delivery Encoding (Stock-In)

### 12. Stock-In Interface
- [ ] 12.1 Create staff_stock_in.php page
- [ ] 12.2 Create fuel_delivery_modal.php with all form fields
- [ ] 12.3 Create merchandise_delivery_modal.php with repeatable items section
- [ ] 12.4 Create stock_in_recorder.js for client-side logic
- [ ] 12.5 Implement add/remove item rows for merchandise delivery
- [ ] 12.6 Block submit button when negative quantities detected (Requirement 8.2)
- [ ] 12.7 Validate delivery date (cannot be future date)
- [ ] 12.8 Display recent deliveries list with status
- [ ] 12.9 Test delivery form validation
- [ ] 12.10 Test submit button blocking for negative quantities

### 13. Fuel Delivery Backend
- [ ] 13.1 Create save_fuel_delivery.php endpoint
- [ ] 13.2 Validate all required fields (supplier, fuel type, liters, tanker, date)
- [ ] 13.3 Validate delivered liters (must be positive)
- [ ] 13.4 Create Delivery_Record in fuel_deliveries only if valid (Requirement 4.3)
- [ ] 13.5 Update fuel_inventory.stock_level only if Delivery_Record created successfully (Requirement 4.4)
- [ ] 13.6 Set status='Pending' for manager verification
- [ ] 13.7 Prevent inventory update if Delivery_Record creation fails
- [ ] 13.8 Preserve user input on validation failure
- [ ] 13.9 Log all fuel delivery actions
- [ ] 13.10 Test fuel delivery save with inventory update
- [ ] 13.11 Test inventory update prevention on failed Delivery_Record

### 14. Merchandise Delivery Backend
- [ ] 14.1 Create save_merchandise_delivery.php endpoint
- [ ] 14.2 Validate all required fields and items
- [ ] 14.3 Validate delivered quantities (must be positive for all items)
- [ ] 14.4 Implement database transaction for multi-table insert
- [ ] 14.5 Create record in merchandise_deliveries
- [ ] 14.6 Create records in merchandise_delivery_items for each item
- [ ] 14.7 Update station_inventory.stock_level for each item only if all records created
- [ ] 14.8 Rollback transaction if any step fails
- [ ] 14.9 Set status='Pending' for manager verification
- [ ] 14.10 Log all merchandise delivery actions
- [ ] 14.11 Test merchandise delivery with multiple items
- [ ] 14.12 Test transaction rollback on failure

## Phase 7: Inventory History and Audit Trail

### 15. Inventory History Interface
- [ ] 15.1 Create staff_inventory_history.php page
- [ ] 15.2 Create inventory_history.js for client-side logic
- [ ] 15.3 Implement filter controls (status, type, date range, staff)
- [ ] 15.4 Display transaction list with pagination (100 per page)
- [ ] 15.5 Implement expandable detail view for transactions
- [ ] 15.6 Display status lifecycle with timestamps
- [ ] 15.7 Show user names for each action (staff, manager, admin)
- [ ] 15.8 Implement sort by date (newest first default)
- [ ] 15.9 Test history display with various filters
- [ ] 15.10 Test expandable detail views

### 16. Inventory History Backend
- [ ] 16.1 Create get_inventory_history.php endpoint
- [ ] 16.2 Query stock_requests, fuel_deliveries, merchandise_deliveries
- [ ] 16.3 Implement filtering by status, date range, item type, staff
- [ ] 16.4 Join with users table for staff/manager names
- [ ] 16.5 Implement pagination with total count
- [ ] 16.6 Return status transition data with timestamps
- [ ] 16.7 Implement read-only access for Staff, Manager, Admin (Requirement 5.4)
- [ ] 16.8 Test history query with various filter combinations
- [ ] 16.9 Test role-based access control

### 17. Export Functionality
- [ ] 17.1 Create export_history.php endpoint
- [ ] 17.2 Implement CSV export with all visible columns
- [ ] 17.3 Implement PDF export with formatted report
- [ ] 17.4 Include export metadata (user, date, filters applied)
- [ ] 17.5 Apply active filters to export data
- [ ] 17.6 Add summary totals to export header
- [ ] 17.7 Test CSV export generation
- [ ] 17.8 Test PDF export generation
- [ ] 17.9 Test export with various filters

## Phase 8: Security and Access Control

### 18. Authentication and Authorization
- [ ] 18.1 Implement checkStaffAccess function on all pages
- [ ] 18.2 Implement validateStationAccess for data operations
- [ ] 18.3 Restrict Staff to own station data only
- [ ] 18.4 Allow Manager to access own station data only
- [ ] 18.5 Allow Admin to access all stations
- [ ] 18.6 Test role-based access restrictions
- [ ] 18.7 Test cross-station access prevention

### 19. Input Security
- [ ] 19.1 Implement input sanitization for all form inputs
- [ ] 19.2 Use prepared statements for all database queries
- [ ] 19.3 Parameterize all user inputs in SQL
- [ ] 19.4 Implement CSRF token validation on all forms
- [ ] 19.5 Add CSRF token to all POST requests
- [ ] 19.6 Test SQL injection prevention
- [ ] 19.7 Test CSRF protection
- [ ] 19.8 Test XSS prevention

### 20. Audit Logging
- [ ] 20.1 Create inventory_audit_log table (if not exists)
- [ ] 20.2 Log all stock request generation actions
- [ ] 20.3 Log all delivery encoding actions
- [ ] 20.4 Log all inventory updates
- [ ] 20.5 Log failed access attempts
- [ ] 20.6 Log validation failures
- [ ] 20.7 Include user_id, station_id, IP address, timestamp
- [ ] 20.8 Test audit log entries for all critical actions

## Phase 9: Integration with Manager Module

### 21. Manager Integration Points
- [ ] 21.1 Verify stock_requests table schema matches Manager module expectations
- [ ] 21.2 Test stock request routing to Manager's Staff Stock Requests tab
- [ ] 21.3 Test Manager validation updating status from Pending to Validated
- [ ] 21.4 Test timing requirement: status update within 2 seconds (Requirement 5.5)
- [ ] 21.5 Test approval failure if timing requirement not met
- [ ] 21.6 Test Manager rejection updating status to Rejected
- [ ] 21.7 Verify Manager can only approve with explicit action (Requirement 7.5, 7.9)
- [ ] 21.8 Test delivery verification flow (Staff → Manager → Admin)
- [ ] 21.9 Test Expected Deliveries visibility after Admin prints PO
- [ ] 21.10 Test integration with Manager_Review_Interface

### 22. Status Transition Workflow
- [ ] 22.1 Implement status progression: Pending → Validated → Completed
- [ ] 22.2 Update request status when Manager validates (Validated)
- [ ] 22.3 Update request status when delivery received (Completed)
- [ ] 22.4 Record manager_id and processed_at on validation
- [ ] 22.5 Test status transition logging
- [ ] 22.6 Test status history display in Inventory History
- [ ] 22.7 Verify status updates propagate to all views

## Phase 10: Error Handling and User Experience

### 23. Error Handling Implementation
- [ ] 23.1 Implement user-friendly error messages for all validation failures
- [ ] 23.2 Preserve user input on all error conditions
- [ ] 23.3 Display inline error messages next to form fields
- [ ] 23.4 Highlight invalid fields in red
- [ ] 23.5 Implement database error handling with friendly messages
- [ ] 23.6 Implement network error handling with retry mechanism
- [ ] 23.7 Log all errors to server log (technical details)
- [ ] 23.8 Display generic error message to users (hide technical details)
- [ ] 23.9 Test error handling for all failure scenarios
- [ ] 23.10 Test input preservation on retry

### 24. Performance Optimization
- [ ] 24.1 Implement summary card caching (30-second cache)
- [ ] 24.2 Implement inventory list caching (60-second cache)
- [ ] 24.3 Add cache invalidation on inventory changes
- [ ] 24.4 Implement debouncing on search input (500ms delay)
- [ ] 24.5 Implement debouncing on validation checks (300ms delay)
- [ ] 24.6 Prevent duplicate form submissions
- [ ] 24.7 Implement lazy loading for tab content
- [ ] 24.8 Load history details on demand (expand/collapse)
- [ ] 24.9 Test caching behavior
- [ ] 24.10 Test performance under load

### 25. User Experience Enhancements
- [ ] 25.1 Add loading indicators for all AJAX operations
- [ ] 25.2 Add success messages for all successful operations
- [ ] 25.3 Implement smooth transitions between tabs
- [ ] 25.4 Add tooltips for complex fields
- [ ] 25.5 Implement keyboard shortcuts for navigation
- [ ] 25.6 Add confirmation dialogs for destructive actions
- [ ] 25.7 Implement auto-save for long forms
- [ ] 25.8 Test user experience flow end-to-end

## Phase 11: Testing and Quality Assurance

### 26. Unit Testing
- [ ] 26.1 Write tests for ValidationEngine.validateMerchandiseData
- [ ] 26.2 Write tests for ValidationEngine.validateFuelReadingData
- [ ] 26.3 Write tests for ValidationEngine.validateDeliveryData
- [ ] 26.4 Write tests for ValidationEngine.checkSKUUniqueness
- [ ] 26.5 Write tests for DataLayerManager.getStationInventory
- [ ] 26.6 Write tests for DataLayerManager.createStockRequest
- [ ] 26.7 Write tests for DataLayerManager.createDeliveryRecord
- [ ] 26.8 Write tests for StockRequestGenerator.calculateSuggestedQuantity
- [ ] 26.9 Run all unit tests and verify passing
- [ ] 26.10 Achieve minimum 80% code coverage

### 27. Integration Testing
- [ ] 27.1 Test end-to-end stock request flow (Staff → Manager → Admin)
- [ ] 27.2 Test end-to-end fuel delivery flow (Staff encodes → Manager verifies → Admin finalizes)
- [ ] 27.3 Test end-to-end merchandise delivery flow
- [ ] 27.4 Test inventory level updates after deliveries
- [ ] 27.5 Test status transition logging across modules
- [ ] 27.6 Test summary card updates after inventory changes
- [ ] 27.7 Test duplicate request prevention
- [ ] 27.8 Test role-based access across modules
- [ ] 27.9 Test cross-station access prevention
- [ ] 27.10 Verify all integration points with Manager module

### 28. User Acceptance Testing
- [ ] 28.1 Staff can VIEW merchandise items with correct stock status
- [ ] 28.2 Stock Request button appears only when items are low stock
- [ ] 28.3 Auto-generated stock request has correct quantity
- [ ] 28.4 Delivery encoding updates inventory levels correctly
- [ ] 28.5 History shows complete status transitions
- [ ] 28.6 Back button navigates correctly from all tabs
- [ ] 28.7 Summary cards display accurate statistics
- [ ] 28.8 Summary card failure blocks tab access
- [ ] 28.9 Validation errors are clear and actionable
- [ ] 28.10 User input is preserved on validation failure
- [ ] 28.11 Staff CANNOT encode or edit merchandise items
- [ ] 28.12 Get user sign-off on all acceptance criteria

### 29. Browser and Device Testing
- [ ] 29.1 Test on Chrome (latest version)
- [ ] 29.2 Test on Firefox (latest version)
- [ ] 29.3 Test on Safari (latest version)
- [ ] 29.4 Test on Edge (latest version)
- [ ] 29.5 Test on mobile devices (responsive design)
- [ ] 29.6 Test on tablets (responsive design)
- [ ] 29.7 Verify consistent behavior across browsers
- [ ] 29.8 Fix any browser-specific issues

## Phase 12: Documentation and Deployment

### 30. Documentation
- [ ] 30.1 Write user manual for Staff Inventory Module
- [ ] 30.2 Document all API endpoints with request/response examples
- [ ] 30.3 Create database schema documentation
- [ ] 30.4 Document integration points with Manager module
- [ ] 30.5 Create troubleshooting guide for common issues
- [ ] 30.6 Document configuration requirements
- [ ] 30.7 Write developer guide for future enhancements
- [ ] 30.8 Create video tutorials for key workflows
- [ ] 30.9 Review and approve all documentation

### 31. Deployment Preparation
- [ ] 31.1 Create database migration script
- [ ] 31.2 Create rollback script
- [ ] 31.3 Backup existing database
- [ ] 31.4 Backup existing code
- [ ] 31.5 Verify PHP and MySQL requirements
- [ ] 31.6 Test migration script on staging environment
- [ ] 31.7 Test rollback procedures on staging
- [ ] 31.8 Prepare deployment checklist
- [ ] 31.9 Schedule deployment window
- [ ] 31.10 Notify users of upcoming deployment

### 32. Production Deployment
- [ ] 32.1 Execute pre-deployment backup
- [ ] 32.2 Run database migration script
- [ ] 32.3 Deploy new code to production server
- [ ] 32.4 Clear server cache
- [ ] 32.5 Verify database schema updates
- [ ] 32.6 Test basic inventory operations in production
- [ ] 32.7 Verify integration with Manager module
- [ ] 32.8 Monitor error logs for issues
- [ ] 32.9 Monitor performance metrics
- [ ] 32.10 Notify users of successful deployment

### 33. Post-Deployment Monitoring
- [ ] 33.1 Monitor application logs for 48 hours
- [ ] 33.2 Monitor database performance
- [ ] 33.3 Monitor user feedback and issues
- [ ] 33.4 Address any critical issues immediately
- [ ] 33.5 Track error rates and performance metrics
- [ ] 33.6 Conduct post-deployment review meeting
- [ ] 33.7 Document lessons learned
- [ ] 33.8 Update documentation based on production findings
- [ ] 33.9 Prepare maintenance plan
- [ ] 33.10 Close deployment phase

## Task Dependencies

## Task Dependency Graph

```json
{
  "waves": [
    {
      "name": "Wave 1: Foundation",
      "tasks": ["1.1", "1.2", "1.3", "1.4", "1.5", "1.6", "2.1", "2.2", "2.3", "2.4", "2.5", "2.6", "2.7", "2.8"]
    },
    {
      "name": "Wave 2: UI Framework",
      "tasks": ["3.1", "3.2", "3.3", "3.4", "3.5", "3.6", "3.7", "4.1", "4.2", "4.3", "4.4", "4.5", "4.6", "4.7", "4.8", "4.9", "4.10"]
    },
    {
      "name": "Wave 3: Feature Implementation (Parallel)",
      "tasks": ["5.1", "5.2", "5.3", "5.4", "5.5", "5.6", "5.7", "5.8", "5.9", "6.1", "6.2", "6.3", "6.4", "6.5", "6.6", "6.7", "6.8", "6.9", "6.10", "6.11", "7.1", "7.2", "7.3", "7.4", "7.5", "7.6", "7.7", "7.8", "7.9", "8.1", "8.2", "8.3", "8.4", "8.5", "8.6", "8.7", "8.8", "8.9", "9.1", "9.2", "9.3", "9.4", "9.5", "9.6", "9.7", "9.8", "9.9", "10.1", "10.2", "10.3", "10.4", "10.5", "10.6", "10.7", "10.8", "10.9", "10.10", "10.11", "11.1", "11.2", "11.3", "11.4", "11.5", "11.6", "11.7", "11.8", "11.9", "11.10", "12.1", "12.2", "12.3", "12.4", "12.5", "12.6", "12.7", "12.8", "12.9", "12.10", "13.1", "13.2", "13.3", "13.4", "13.5", "13.6", "13.7", "13.8", "13.9", "13.10", "13.11", "14.1", "14.2", "14.3", "14.4", "14.5", "14.6", "14.7", "14.8", "14.9", "14.10", "14.11", "14.12", "15.1", "15.2", "15.3", "15.4", "15.5", "15.6", "15.7", "15.8", "15.9", "15.10", "16.1", "16.2", "16.3", "16.4", "16.5", "16.6", "16.7", "16.8", "16.9", "17.1", "17.2", "17.3", "17.4", "17.5", "17.6", "17.7", "17.8", "17.9"]
    },
    {
      "name": "Wave 4: Security & Error Handling",
      "tasks": ["18.1", "18.2", "18.3", "18.4", "18.5", "18.6", "18.7", "19.1", "19.2", "19.3", "19.4", "19.5", "19.6", "19.7", "19.8", "20.1", "20.2", "20.3", "20.4", "20.5", "20.6", "20.7", "20.8", "23.1", "23.2", "23.3", "23.4", "23.5", "23.6", "23.7", "23.8", "23.9", "23.10", "24.1", "24.2", "24.3", "24.4", "24.5", "24.6", "24.7", "24.8", "24.9", "24.10", "25.1", "25.2", "25.3", "25.4", "25.5", "25.6", "25.7", "25.8"]
    },
    {
      "name": "Wave 5: Integration",
      "tasks": ["21.1", "21.2", "21.3", "21.4", "21.5", "21.6", "21.7", "21.8", "21.9", "21.10", "22.1", "22.2", "22.3", "22.4", "22.5", "22.6", "22.7"]
    },
    {
      "name": "Wave 6: Testing",
      "tasks": ["26.1", "26.2", "26.3", "26.4", "26.5", "26.6", "26.7", "26.8", "26.9", "26.10", "27.1", "27.2", "27.3", "27.4", "27.5", "27.6", "27.7", "27.8", "27.9", "27.10", "28.1", "28.2", "28.3", "28.4", "28.5", "28.6", "28.7", "28.8", "28.9", "28.10", "28.11", "29.1", "29.2", "29.3", "29.4", "29.5", "29.6", "29.7", "29.8"]
    },
    {
      "name": "Wave 7: Deployment",
      "tasks": ["30.1", "30.2", "30.3", "30.4", "30.5", "30.6", "30.7", "30.8", "30.9", "31.1", "31.2", "31.3", "31.4", "31.5", "31.6", "31.7", "31.8", "31.9", "31.10", "32.1", "32.2", "32.3", "32.4", "32.5", "32.6", "32.7", "32.8", "32.9", "32.10", "33.1", "33.2", "33.3", "33.4", "33.5", "33.6", "33.7", "33.8", "33.9", "33.10"]
    }
  ]
}
```

### Critical Path
1. Phase 1 (Database Setup) → Must complete before all other phases
2. Phase 2 (Navigation/UI) → Must complete before Phases 3-7
3. Phases 3-7 (Feature Implementation) → Can be done in parallel after Phase 2
4. Phase 8 (Security) → Must complete before Phase 12
5. Phase 9 (Integration) → Requires Phases 3-7 complete
6. Phase 10 (Error Handling) → Can overlap with Phases 3-7
7. Phase 11 (Testing) → Requires Phases 1-10 complete
8. Phase 12 (Deployment) → Requires Phase 11 complete

### Parallel Work Opportunities
- Phases 3, 4, 5, 6, 7 can be developed in parallel after Phase 2
- Phase 8 and Phase 10 can overlap with feature development
- Unit tests can be written alongside feature implementation

## Estimated Timeline

| Phase | Estimated Duration | Priority |
|-------|-------------------|----------|
| Phase 1: Database Setup | 2 days | Critical |
| Phase 2: Navigation/UI | 3 days | Critical |
| Phase 3: Merchandise Inventory | 4 days | High |
| Phase 4: Fuel Inventory | 4 days | High |
| Phase 5: Stock Requests | 5 days | High |
| Phase 6: Delivery Encoding | 5 days | High |
| Phase 7: Inventory History | 3 days | Medium |
| Phase 8: Security | 3 days | Critical |
| Phase 9: Manager Integration | 4 days | High |
| Phase 10: Error Handling | 3 days | High |
| Phase 11: Testing | 5 days | Critical |
| Phase 12: Deployment | 2 days | Critical |
| **Total** | **43 days** | |

*Note: Timeline assumes single developer working sequentially. With parallel development, timeline can be reduced to approximately 25-30 days.*

## Success Criteria

- [ ] All acceptance criteria from requirements document are met
- [ ] All unit tests pass with minimum 80% code coverage
- [ ] All integration tests pass
- [ ] User acceptance testing completed and signed off
- [ ] Performance requirements met (< 2 second response times)
- [ ] Security audit passed
- [ ] Documentation complete and reviewed
- [ ] Successful production deployment with no critical issues
- [ ] Manager module integration verified and working
- [ ] Zero data loss during deployment

## Notes

**Key Design Decisions:**
- Use existing tables (stock_requests, fuel_deliveries, station_inventory, fuel_inventory) wherever possible
- Create new tables only for merchandise deliveries to match fuel delivery pattern
- Implement SMART Stock Request button that only shows when items need replenishment
- Auto-calculate quantities - no manual input for stock requests
- Separate tabs for Fuel and Merchandise with unified Stock Request view
- Complete audit trail via Inventory History with status progression
- Integration with Manager module for validation workflow

**Technology Choices:**
- PHP 8.x with MySQLi for backend
- Vanilla JavaScript for frontend (no framework dependencies)
- AJAX for async operations
- Prepared statements for SQL injection prevention
- Session-based authentication with role-based access control

**Performance Considerations:**
- Summary cards cached for 30 seconds
- Inventory lists paginated (50-100 items per page)
- Database indexes on frequently queried columns
- Debouncing on search and validation inputs
- Lazy loading for tab content

**Security Measures:**
- Input sanitization on all user inputs
- Prepared statements for all queries
- CSRF token validation on all forms
- Role-based access control enforced at page and API level
- Audit logging for all critical actions
- Station-level data isolation (staff/manager see only own station)

**Integration Points:**
- Manager module validates stock requests (Pending → Validated)
- Admin module approves POs and finalizes deliveries
- Expected deliveries visible after Admin prints PO
- Real-time status updates via polling or WebSocket
