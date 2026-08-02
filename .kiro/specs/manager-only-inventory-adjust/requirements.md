# Requirements Document

## Introduction

This document specifies the requirements for restricting the inventory adjustment functionality to manager-role users only in the Petron Station Management System. Currently, the "Adjust" button is visible to all users who can access the merchandise inventory page. This feature will implement role-based access control (RBAC) to ensure only managers can view and use the adjustment functionality, preventing unauthorized inventory modifications.

## Glossary

- **Inventory_Page**: The merchandise inventory view page (manager_inventory_merchandise.php) displaying the inventory table with actions column
- **Adjust_Button**: The action button in the actions column that opens the adjustment modal for modifying inventory stock levels
- **Manager_Role**: A user role with elevated permissions for inventory management operations, defined in the RBAC system
- **RBAC_System**: The Role-Based Access Control system that manages permissions based on user roles (staff, manager, admin, superadmin)
- **Actions_Column**: The rightmost column in the inventory table containing action buttons (View, Adjust)
- **Adjustment_Modal**: The UI dialog opened by the Adjust button for entering physical count and adjustment details
- **Staff_Role**: A user role with basic permissions, restricted from making direct inventory adjustments

## Requirements

### Requirement 1: Hide Adjust Button for Non-Manager Users

**User Story:** As a system administrator, I want the Adjust button hidden from non-manager users, so that only authorized personnel can access inventory adjustment functionality.

#### Acceptance Criteria

1. WHEN a user with staff role views the merchandise inventory table, THE Inventory_Page SHALL NOT display the Adjust_Button in the Actions_Column
2. WHEN a user with manager role views the merchandise inventory table, THE Inventory_Page SHALL display the Adjust_Button in the Actions_Column
3. WHEN a user with admin role views the merchandise inventory table, THE Inventory_Page SHALL display the Adjust_Button in the Actions_Column
4. WHEN a user with superadmin role views the merchandise inventory table, THE Inventory_Page SHALL display the Adjust_Button in the Actions_Column
5. THE Inventory_Page SHALL display the View button in the Actions_Column for all user roles

### Requirement 2: Block Direct Access to Adjustment Functionality

**User Story:** As a security administrator, I want direct access to adjustment operations blocked for non-manager users, so that staff cannot bypass UI restrictions through direct form submission or URL manipulation.

#### Acceptance Criteria

1. WHEN a non-manager user submits an adjustment request via POST, THE RBAC_System SHALL reject the request with an error response
2. WHEN a manager user submits an adjustment request via POST, THE RBAC_System SHALL process the adjustment request normally
3. IF a non-manager user attempts to submit the adjustment form action, THEN THE Inventory_Page SHALL return an error message and log the unauthorized access attempt
4. THE RBAC_System SHALL verify user role permissions before processing any adjustment action with POST method

### Requirement 3: Maintain Existing RBAC Permission Structure

**User Story:** As a developer, I want the implementation to use the existing RBAC permission system, so that the access control is consistent with other system features.

#### Acceptance Criteria

1. THE RBAC_System SHALL use the existing INVENTORY_ADJUSTMENT permission constant for authorization checks
2. WHEN checking adjustment permissions, THE RBAC_System SHALL use the has_permission() function with INVENTORY_ADJUSTMENT parameter
3. THE Implementation SHALL NOT create new permission constants if INVENTORY_ADJUSTMENT permission already exists in rbac.php
4. THE Implementation SHALL maintain the existing role_permissions mapping structure in rbac.php

### Requirement 4: Preserve View Button Functionality for All Roles

**User Story:** As a staff member, I want to retain access to the View button, so that I can still monitor inventory details and history without making changes.

#### Acceptance Criteria

1. THE Inventory_Page SHALL display the View button for users with staff role
2. WHEN a staff user clicks the View button, THE Inventory_Page SHALL open the product details modal showing inventory information
3. THE View button SHALL maintain its existing functionality including display of movements, deliveries, and requests
4. THE Inventory_Page SHALL NOT restrict access to read-only inventory information based on role

### Requirement 5: Display Appropriate UI State for Different Roles

**User Story:** As a user interface designer, I want the actions column to maintain proper layout when the Adjust button is hidden, so that the interface remains visually consistent across different user roles.

#### Acceptance Criteria

1. WHEN the Adjust_Button is hidden for staff users, THE Actions_Column SHALL display only the View button with proper centering
2. WHEN the Adjust_Button is visible for managers, THE Actions_Column SHALL display both View and Adjust buttons with proper spacing
3. THE Actions_Column SHALL maintain consistent height and alignment regardless of button visibility
4. THE Inventory_Page SHALL NOT display empty space or layout shifts when the Adjust_Button is conditionally hidden

### Requirement 6: Maintain Adjustment Modal Security

**User Story:** As a security administrator, I want the adjustment modal to be inaccessible to non-manager users, so that the JavaScript functions cannot be exploited to open restricted functionality.

#### Acceptance Criteria

1. WHEN a non-manager user attempts to call openStockAdjustmentModal() JavaScript function, THE Inventory_Page SHALL prevent the modal from opening
2. WHEN a manager user calls openStockAdjustmentModal() JavaScript function, THE Inventory_Page SHALL open the adjustment modal normally
3. IF the adjustment modal is opened through browser console by non-manager user, THEN THE backend SHALL reject any form submission with authorization error
4. THE Adjustment_Modal SHALL include role-based validation on both frontend and backend

### Requirement 7: Preserve Manager Adjustment Workflow

**User Story:** As a manager, I want my existing adjustment workflow to remain unchanged, so that I can continue to perform inventory adjustments efficiently without disruption.

#### Acceptance Criteria

1. WHEN a manager clicks the Adjust button, THE Inventory_Page SHALL open the adjustment modal with current stock pre-filled
2. WHEN a manager submits the adjustment form with action='request_adjustment', THE RBAC_System SHALL process the physical count update
3. THE Inventory_Page SHALL update station_inventory stock_level with the physical count value
4. THE Inventory_Page SHALL log the adjustment in inventory_logs with the variance calculation
5. THE Inventory_Page SHALL display success message confirming the adjustment was logged

