# Requirements Document

## Introduction

This feature modifies the admin fuel deliveries deletion behavior in the Petron Station Management System. Currently, when fuel deliveries are removed/deleted from the admin inventory interface, they disappear from the main view. The new behavior will implement soft deletion, where removed deliveries are hidden from the main table but remain accessible through a dedicated "Fuel Deliveries History" view modal.

This change ensures audit trail preservation, allows administrators to review historical deletion decisions, and provides a mechanism to track removed fuel deliveries without cluttering the active deliveries list.

## Glossary

- **System**: The Petron Station Management System
- **Admin_Fuel_Inventory_Module**: The admin interface for monitoring fuel inventory, deliveries, and adjustments (admin_inventory_fuel.php)
- **Fuel_Deliveries_Table**: The database table storing fuel delivery records (fuel_deliveries)
- **Soft_Delete**: A deletion pattern where records are marked as deleted (is_deleted=1) instead of being permanently removed from the database
- **Deliveries_History_Modal**: A modal dialog that displays soft-deleted fuel delivery records
- **Active_Deliveries_View**: The main fuel deliveries tab showing only non-deleted delivery records
- **Admin**: A user with the admin role who can view fuel inventory and deliveries
- **Superadmin**: A user with elevated privileges who can perform deletion and restoration operations

## Requirements

### Requirement 1: Soft Delete Fuel Deliveries

**User Story:** As an admin, I want to remove fuel deliveries from the active view without permanently deleting them, so that I can maintain a clean working list while preserving historical records.

#### Acceptance Criteria

1. WHEN an admin clicks the remove/delete action on a fuel delivery record, THE Fuel_Deliveries_Table SHALL mark the record with is_deleted=1 and deleted_at=NOW()
2. THE Fuel_Deliveries_Table SHALL include an is_deleted column (TINYINT(1), default 0) if it does not already exist
3. THE Fuel_Deliveries_Table SHALL include a deleted_at column (DATETIME, nullable) if it does not already exist
4. THE Fuel_Deliveries_Table SHALL include a deleted_by column (INT, nullable, foreign key to users.id) if it does not already exist
5. WHEN a fuel delivery is soft-deleted, THE System SHALL record the user ID of the admin who performed the deletion in the deleted_by column
6. WHEN a fuel delivery is soft-deleted, THE System SHALL log the deletion action in the audit trail with details including delivery ID, fuel type, liters, and deletion timestamp

### Requirement 2: Active Deliveries View Filtering

**User Story:** As an admin, I want to see only active (non-deleted) fuel deliveries in the main deliveries tab, so that my working view is not cluttered with removed records.

#### Acceptance Criteria

1. WHEN the admin navigates to the Fuel Deliveries tab in Admin_Fuel_Inventory_Module, THE Active_Deliveries_View SHALL display only records where is_deleted=0
2. THE Active_Deliveries_View SHALL exclude all records where is_deleted=1
3. WHEN fetching delivery records for display, THE System SHALL apply the WHERE is_deleted=0 filter to the SQL query
4. THE Active_Deliveries_View SHALL maintain all existing columns and sorting functionality for non-deleted records

### Requirement 3: Fuel Deliveries History Modal

**User Story:** As an admin, I want to access a history view of removed fuel deliveries, so that I can review past deletion decisions and verify data integrity.

#### Acceptance Criteria

1. THE Admin_Fuel_Inventory_Module SHALL provide a "View Deliveries History" button on the Fuel Deliveries tab
2. WHEN the admin clicks "View Deliveries History", THE Deliveries_History_Modal SHALL open and display all records where is_deleted=1
3. THE Deliveries_History_Modal SHALL fetch soft-deleted records using a WHERE is_deleted=1 filter
4. THE Deliveries_History_Modal SHALL display the following columns: Delivery No., PO No., Supplier, Fuel Type, Liters Received, Unit Cost, Delivery Date, Recorded By, Deleted Date, Deleted By, Status
5. THE Deliveries_History_Modal SHALL sort records by deleted_at DESC by default (most recently deleted first)
6. THE Deliveries_History_Modal SHALL provide a close button to dismiss the modal
7. THE Deliveries_History_Modal SHALL be implemented as a centered modal overlay with responsive design

### Requirement 4: Restore Soft-Deleted Deliveries (Optional Feature)

**User Story:** As a superadmin, I want to restore accidentally deleted fuel deliveries, so that I can recover records that were removed by mistake.

#### Acceptance Criteria

1. WHERE the user role is superadmin, THE Deliveries_History_Modal SHALL display a "Restore" action button for each soft-deleted delivery record
2. WHEN a superadmin clicks "Restore" on a soft-deleted delivery, THE System SHALL set is_deleted=0, deleted_at=NULL, and deleted_by=NULL for that record
3. WHEN a delivery is restored, THE System SHALL log the restoration action in the audit trail with details including delivery ID, fuel type, liters, and restoration timestamp
4. WHEN a delivery is restored, THE System SHALL immediately remove it from the Deliveries_History_Modal and add it back to the Active_Deliveries_View
5. WHERE the user role is admin (not superadmin), THE Deliveries_History_Modal SHALL display soft-deleted records in read-only mode without restore actions

### Requirement 5: Database Schema Validation and Migration

**User Story:** As a developer, I want the system to automatically add soft delete columns to the fuel_deliveries table if they do not exist, so that the feature can be deployed without manual database modifications.

#### Acceptance Criteria

1. WHEN the Admin_Fuel_Inventory_Module loads, THE System SHALL check if the is_deleted, deleted_at, and deleted_by columns exist in the Fuel_Deliveries_Table
2. IF the is_deleted column does not exist, THEN THE System SHALL execute ALTER TABLE fuel_deliveries ADD COLUMN is_deleted TINYINT(1) DEFAULT 0 NOT NULL
3. IF the deleted_at column does not exist, THEN THE System SHALL execute ALTER TABLE fuel_deliveries ADD COLUMN deleted_at DATETIME NULL
4. IF the deleted_by column does not exist, THEN THE System SHALL execute ALTER TABLE fuel_deliveries ADD COLUMN deleted_by INT NULL, ADD FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
5. WHEN schema validation fails, THE System SHALL log the error and display a warning message to the admin
6. THE System SHALL create an index on is_deleted for query performance: CREATE INDEX idx_is_deleted ON fuel_deliveries(is_deleted)

### Requirement 6: Audit Trail Integration

**User Story:** As a compliance officer, I want all fuel delivery deletion and restoration actions to be logged, so that I can audit data management activities.

#### Acceptance Criteria

1. WHEN a fuel delivery is soft-deleted, THE System SHALL call log_activity() with action='Admin Delete Fuel Delivery' and details including delivery_no, fuel_type, liters, supplier, and deleted_by
2. WHEN a fuel delivery is restored, THE System SHALL call log_activity() with action='Admin Restore Fuel Delivery' and details including delivery_no, fuel_type, liters, supplier, and restored_by
3. THE System SHALL ensure all soft delete and restore operations are logged before the database transaction is committed
4. WHEN the audit log is viewed, THE System SHALL display soft delete and restore actions with full context including timestamp, user, and affected record details

### Requirement 7: User Interface Enhancements

**User Story:** As an admin, I want clear visual indicators for the deliveries history feature, so that I can easily understand which deliveries are active and which are archived.

#### Acceptance Criteria

1. THE Active_Deliveries_View SHALL display a badge or counter showing the total number of soft-deleted deliveries (e.g., "Deleted: 12")
2. THE "View Deliveries History" button SHALL use a distinct icon (fas fa-history) and styling to differentiate it from other actions
3. THE Deliveries_History_Modal SHALL display a header with the title "Fuel Deliveries History" and a subtitle indicating these are deleted records
4. WHEN the Deliveries_History_Modal is empty (no deleted records), THE System SHALL display a message "No deleted fuel deliveries found"
5. THE Deliveries_History_Modal SHALL use consistent styling with existing modal components in the system (matching admin_all_transactions.php modal patterns)

### Requirement 8: Performance Optimization

**User Story:** As a system administrator, I want the soft delete feature to maintain fast query performance, so that page load times do not degrade as deleted records accumulate.

#### Acceptance Criteria

1. THE System SHALL create a composite index on (station_id, is_deleted, delivery_date) for the Fuel_Deliveries_Table
2. WHEN querying active deliveries, THE System SHALL use index hints to force use of the is_deleted index where beneficial
3. THE Deliveries_History_Modal query SHALL include a LIMIT 500 clause to prevent excessive data loading
4. WHEN the deleted records count exceeds 1000 for a station, THE System SHALL implement pagination in the Deliveries_History_Modal with 50 records per page
5. THE System SHALL ensure all queries involving is_deleted filtering complete within 200ms for datasets up to 10,000 delivery records

### Requirement 9: Permission and Role Enforcement

**User Story:** As a security administrator, I want soft delete operations to be restricted by role, so that only authorized users can delete or restore fuel deliveries.

#### Acceptance Criteria

1. WHEN a user with role='admin' attempts to soft-delete a fuel delivery, THE System SHALL allow the operation
2. WHEN a user with role='superadmin' attempts to soft-delete or restore a fuel delivery, THE System SHALL allow the operation
3. WHEN a user with role='staff' or role='manager' attempts to access the delete action, THE System SHALL return a 403 Forbidden error with message "Access denied"
4. THE System SHALL verify user role using the existing role_key() function before processing any soft delete or restore request
5. WHEN an unauthorized user attempts a soft delete operation via direct API call, THE System SHALL log the attempt as a security event

### Requirement 10: Data Integrity and Validation

**User Story:** As a data analyst, I want the system to prevent accidental data corruption during soft delete operations, so that fuel inventory calculations remain accurate.

#### Acceptance Criteria

1. WHEN a fuel delivery is soft-deleted, THE System SHALL NOT alter the delivery_liters, cost_per_liter, or other quantitative fields
2. THE System SHALL ensure that soft-deleted deliveries do NOT contribute to current fuel inventory calculations in the Active_Deliveries_View
3. WHEN generating fuel inventory reports, THE System SHALL exclude soft-deleted deliveries from Beginning Balance and Purchases calculations
4. THE System SHALL validate that is_deleted is either 0 or 1 (no other values allowed)
5. WHEN a delivery is restored, THE System SHALL recalculate affected fuel inventory totals and display a warning if discrepancies are detected

