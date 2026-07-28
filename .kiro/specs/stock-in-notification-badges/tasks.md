# Implementation Plan: Stock-In Notification Badges

## Overview

This plan implements notification badges for the Stock-In menu item, displaying the count of pending deliveries for managers and admins. The implementation adds badge calculation logic to `partials/header.php` and extends the existing polling API in `backend/api/notifications_api.php` to support real-time updates.

## Tasks

- [ ] 1. Add badge calculation logic to header.php
  - Locate the manager deliveries badge section (around line 254-257)
  - Add stock-in badge calculation for manager role after the manager_deliveries badge
  - Add stock-in badge calculation for admin role in the admin section
  - Query `deliveries_oversight` table with station_id filter and pending statuses
  - Set badge key to `mgr_stock_in` to match menu item ID
  - Include both 'merchandise' and 'fuel' delivery types in the query
  - Use try-catch to handle database failures gracefully (set count to 0)
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 4.1, 4.2, 4.3, 4.4, 5.1, 5.2, 5.3, 5.4, 5.5, 6.1, 6.2, 6.3_

- [ ]* 1.1 Write unit tests for badge calculation
  - Test manager with pending merchandise deliveries
  - Test manager with pending fuel deliveries
  - Test manager with mixed deliveries
  - Test manager with no pending deliveries
  - Test admin with assigned station
  - Test admin without assigned station
  - Test database query failure handling
  - Test status filtering accuracy
  - _Requirements: 1.1, 1.3, 1.4, 2.1, 2.2, 2.3, 4.1, 4.2, 4.3, 4.4, 6.1, 6.2, 6.3_

- [ ] 2. Checkpoint - Verify badge displays on page load
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 3. Add badge update logic to notifications API
  - [ ] 3.1 Add stock-in count to manager section in notifications_api.php
    - Locate the manager role badge counting section (around line 242)
    - Add query for pending stock-in deliveries using `$safe_count` helper
    - Include station_id filter using `$station_where` and `$station_param`
    - Filter by delivery_type ('merchandise', 'fuel') and pending statuses
    - Add the count to `$action_count` aggregate
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 5.3, 5.4, 7.1, 7.2, 7.3_
  
  - [ ] 3.2 Add stock-in count to admin section in notifications_api.php
    - Locate the admin role badge counting section (around line 180)
    - Add query for pending stock-in deliveries using `$safe_count` helper
    - Include station_id filter using `$station_where` and `$station_param`
    - Filter by delivery_type ('merchandise', 'fuel') and pending statuses
    - Add the count to `$action_count` aggregate
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 4.1, 4.2, 4.3, 4.4, 5.3, 7.1, 7.2, 7.3_

- [ ]* 3.3 Write integration tests for API badge updates
  - Test API returns correct count for manager role
  - Test API returns correct count for admin role
  - Test API updates count after delivery status change
  - Test API handles database failures gracefully
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 7.1, 7.2, 7.3_

- [ ] 4. Checkpoint - Verify real-time badge updates
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 5. Final integration and validation
  - [ ] 5.1 Verify badge displays for manager role with pending deliveries
    - Test with merchandise deliveries only
    - Test with fuel deliveries only
    - Test with mixed deliveries
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 2.1, 2.2, 2.3_
  
  - [ ] 5.2 Verify badge displays for admin role with assigned station
    - Test with assigned station_id
    - Test without assigned station_id (should not display)
    - _Requirements: 4.1, 4.2, 4.3, 4.4_
  
  - [ ] 5.3 Verify badge count matches Stock-In page
    - Compare badge count with actual pending deliveries on manager_stock_in.php
    - Verify status filtering matches exactly
    - _Requirements: 1.1, 1.3, 5.4_
  
  - [ ] 5.4 Verify badge updates automatically
    - Process a delivery and verify badge decrements within 5 seconds
    - Change delivery status back to pending and verify badge increments
    - _Requirements: 3.1, 3.2, 7.1, 7.2, 7.3_
  
  - [ ] 5.5 Verify badge visibility rules
    - Test badge hides when sidebar collapsed
    - Test badge shows when sidebar expanded (if count > 0)
    - Test badge hides when count is 0
    - _Requirements: 8.1, 8.2, 8.3, 8.4_

- [ ]* 5.6 Write end-to-end integration tests
  - Test complete user flow: badge display, processing delivery, badge update
  - Test badge styling matches existing badges
  - Test badge persistence across page navigation
  - _Requirements: 1.5, 5.1, 5.2, 5.3, 5.5, 8.1, 8.2, 8.3, 8.4_

- [ ] 6. Final checkpoint - Ensure all functionality working
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability (e.g., 1.1 refers to Requirement 1, Acceptance Criteria 1)
- The badge key `mgr_stock_in` must match the menu item ID in `partials/rbac_menu.php`
- The pending statuses list must match `manager_stock_in.php` line 35 exactly
- Existing badge rendering and update JavaScript requires no changes
- Database errors are handled gracefully with zero counts (no error messages to users)
- Real-time updates leverage the existing 5-second polling mechanism
