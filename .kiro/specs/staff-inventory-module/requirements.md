# Requirements Document

## Introduction

The Staff Inventory Module provides comprehensive inventory management capabilities for fuel station staff. The system enables staff to manage merchandise and fuel inventory, submit stock requests for low-stock items, record deliveries, and track the complete lifecycle of inventory transactions. The module includes dedicated interfaces for merchandise encoding, fuel level monitoring, automated stock request generation, delivery recording, and historical tracking with clear status workflows.

## Glossary

- **Staff_Inventory_System**: The complete inventory management module accessible to staff members
- **Merchandise_Inventory_Manager**: The subsystem that handles merchandise item encoding and updates
- **Fuel_Inventory_Manager**: The subsystem that manages fuel pump readings and fuel stock levels
- **Stock_Request_Generator**: The automated system that identifies and lists low-stock or out-of-stock items
- **Stock_In_Recorder**: The subsystem that processes delivery receipts and updates inventory levels
- **Inventory_History_Tracker**: The system that maintains status lifecycle records for all inventory transactions
- **Manager_Review_Interface**: The manager-facing interface where pending stock requests are reviewed
- **Low_Stock_Item**: An item where current stock level is at or below the reorder threshold
- **Out_Of_Stock_Item**: An item where current stock level is zero
- **Pending_Request**: A stock request that has been submitted but not yet reviewed by management
- **Approved_Request**: A stock request that has been validated and authorized by a manager
- **Completed_Request**: A stock request where the ordered stock has been received and recorded
- **PO_Reference**: Purchase Order reference number associated with a delivery
- **Delivery_Record**: A record containing supplier, items, quantities, and dates for received stock
- **Back_Button**: Navigation control that returns users to the main Inventory menu
- **Summary_Card**: Dashboard widget displaying quick statistics for a specific inventory category

## Requirements

### Requirement 1: Merchandise Inventory View

**User Story:** As a staff member, I want to view current merchandise inventory levels and stock status, so that I can identify items that need replenishment and submit stock requests when needed.

#### Acceptance Criteria

1. WHEN the staff member accesses Merchandise Inventory, THE Staff_Inventory_System SHALL display all merchandise items in a searchable and sortable table showing SKU, product name, category, current stock level, and stock status
2. THE Staff_Inventory_System SHALL highlight items with Low_Stock_Item status (stock level at or below reorder level) in yellow/warning color
3. THE Staff_Inventory_System SHALL highlight items with Out_Of_Stock_Item status (stock level = 0) in red/danger color
4. THE Staff_Inventory_System SHALL allow staff to search merchandise by SKU, product name, or category
5. THE Staff_Inventory_System SHALL allow staff to sort merchandise by stock level (ascending/descending)
6. THE Staff_Inventory_System SHALL refresh merchandise inventory display every 30 seconds or when inventory changes occur
7. THE Staff_Inventory_System SHALL NOT provide any encode or edit functionality for merchandise items (Manager/Admin only)

### Requirement 2: Fuel Inventory Management

**User Story:** As a staff member, I want to encode pump readings and deliveries so that the system automatically updates fuel stock levels and allows me to request replenishment when needed.

#### Acceptance Criteria

1. WHEN the staff member accesses Fuel Inventory, THE Staff_Inventory_System SHALL display a form to encode pump readings with fields for fuel type, previous reading, current reading, and transaction details
2. WHEN the staff member submits pump readings, THE Fuel_Inventory_Manager SHALL calculate the difference between current and previous readings and auto-update fuel stock levels within 2 seconds
3. WHEN the staff member records a fuel delivery, THE Fuel_Inventory_Manager SHALL add the delivered quantity to the current stock level
4. THE Fuel_Inventory_Manager SHALL display real-time fuel stock levels for all fuel types at the station
5. THE Stock_Request_Generator SHALL identify fuel types where stock level is Low_Stock_Item or Out_Of_Stock_Item
6. WHEN the staff member clicks the Stock Request button for fuel, THE Stock_Request_Generator SHALL auto-list only fuel types that are Low_Stock_Item or Out_Of_Stock_Item

### Requirement 3: Automated Stock Request Generation

**User Story:** As a staff member, I want a button-based stock request system that automatically filters and lists only low-stock or out-of-stock items, so that I can request replenishment without manually checking inventory levels.

#### Acceptance Criteria

1. THE Staff_Inventory_System SHALL display a Stock Request button at the top of both Fuel Inventory and Merchandise Inventory interfaces
2. WHEN the staff member clicks the Stock Request button, THE Stock_Request_Generator SHALL query current inventory levels and identify all items where stock level is Low_Stock_Item or Out_Of_Stock_Item
3. THE Stock_Request_Generator SHALL display the filtered list of items requiring replenishment without requiring the staff member to manually encode quantities
4. WHEN the staff member submits a stock request, THE Staff_Inventory_System SHALL tag the request status as Pending_Request only after successful submission
5. WHEN a stock request is successfully tagged as Pending_Request, THE Staff_Inventory_System SHALL route the request to the Manager_Review_Interface under the Staff Stock Requests tab
6. THE Staff_Inventory_System SHALL prevent duplicate requests for the same item within the same day
7. WHEN the staff member attempts to submit a request with no items selected, THE Stock_Request_Generator SHALL display an error message stating that at least one item must be selected
8. THE Staff_Inventory_System SHALL retain phantom pending requests (requests tagged as pending due to system errors or validation failures) in the system for manual review rather than automatic cleanup

### Requirement 4: Stock-In Recording

**User Story:** As a staff member, I want to encode actual deliveries received with full delivery details, so that the system updates inventory levels and the admin can oversee all delivery records.

#### Acceptance Criteria

1. WHEN the staff member accesses Stock-In, THE Staff_Inventory_System SHALL display a form with fields for PO_Reference, Supplier, Fuel Type or Merchandise item, Delivered Quantity, and Delivery Date
2. WHEN the staff member submits a delivery record, THE Stock_In_Recorder SHALL validate that all required fields contain valid data
3. WHEN delivery data is valid, THE Stock_In_Recorder SHALL create a Delivery_Record and auto-update inventory stock levels within 2 seconds only if the Delivery_Record is successfully created
4. WHEN delivery data is invalid or Delivery_Record creation fails, THE Stock_In_Recorder SHALL prevent inventory updates from occurring
5. THE Stock_In_Recorder SHALL increment the stock level for fuel items in the fuel_inventory table and for merchandise items in the station_inventory table
6. THE Staff_Inventory_System SHALL provide admin access to view and oversee all encoded Delivery_Record entries
7. WHEN delivery encoding fails due to invalid data, THE Stock_In_Recorder SHALL display a descriptive error message and preserve the user's input for correction

### Requirement 5: Inventory History and Status Tracking

**User Story:** As a staff member, I want to view the complete lifecycle of my inventory requests with status progression, so that I can track requests from submission through completion and have transparency into all inventory actions.

#### Acceptance Criteria

1. THE Inventory_History_Tracker SHALL maintain a complete history of all stock requests with status progression from Pending_Request to Approved_Request to Completed_Request
2. WHEN the staff member accesses Inventory History, THE Inventory_History_Tracker SHALL display all inventory transactions with current status, timestamp, and action details
3. THE Inventory_History_Tracker SHALL display status transitions in chronological order for each request
4. THE Staff_Inventory_System SHALL provide read-only access to Inventory History for Staff, Manager, and Admin roles
5. WHEN a manager approves a stock request, THE Inventory_History_Tracker SHALL update the request status from Pending_Request to Approved_Request within 2 seconds, and SHALL fail the entire approval if this timing requirement cannot be met
6. WHEN a delivery is recorded for an approved request, THE Inventory_History_Tracker SHALL update the request status from Approved_Request to Completed_Request
7. THE Inventory_History_Tracker SHALL allow filtering by status, date range, item type (fuel or merchandise), and requesting staff member

### Requirement 6: Navigation and Summary Interface

**User Story:** As a staff member, I want clear navigation with back buttons and summary cards on each tab, so that I can quickly return to the main menu and see key statistics at a glance.

#### Acceptance Criteria

1. THE Staff_Inventory_System SHALL display a Back_Button at the top of every inventory sub-tab and modal form
2. WHEN the staff member clicks the Back_Button, THE Staff_Inventory_System SHALL navigate to the main Inventory menu within 1 second
3. THE Staff_Inventory_System SHALL display Summary_Card widgets on each inventory tab showing relevant quick statistics, and SHALL block tab access if the Summary_Card fails to load or display
4. WHEN viewing Fuel Inventory, THE Staff_Inventory_System SHALL display Summary_Card showing current fuel levels for all fuel types and count of Low_Stock_Item fuel types
5. WHEN viewing Merchandise Inventory, THE Staff_Inventory_System SHALL display Summary_Card showing total merchandise items, count of Low_Stock_Item merchandise, and count of Out_Of_Stock_Item merchandise
6. WHEN viewing Stock Requests, THE Staff_Inventory_System SHALL display Summary_Card showing counts of Pending_Request, Approved_Request, and Completed_Request
7. WHEN viewing Stock-In, THE Staff_Inventory_System SHALL display Summary_Card showing count of deliveries received today, this week, and this month
8. WHEN viewing Inventory History, THE Staff_Inventory_System SHALL display Summary_Card showing total transaction count and breakdown by status
9. THE Staff_Inventory_System SHALL refresh Summary_Card data automatically when inventory changes occur

### Requirement 7: Manager Stock Request Review Interface

**User Story:** As a manager, I want to view all pending stock requests submitted by staff in a dedicated tab, so that I can review, approve, and print purchase orders for replenishment.

#### Acceptance Criteria

1. THE Manager_Review_Interface SHALL display a Staff Stock Requests tab accessible only to users with manager or admin roles
2. WHEN the manager accesses Staff Stock Requests, THE Manager_Review_Interface SHALL display all requests with status Pending_Request
3. THE Manager_Review_Interface SHALL display each request with item details, requested quantity, requesting staff name, submission timestamp, and current stock level
4. WHEN the manager selects a request, THE Manager_Review_Interface SHALL provide options to approve, reject, or request modification
5. WHEN the manager approves a request, THE Manager_Review_Interface SHALL update the request status to Approved_Request and generate a printable purchase order only when the manager explicitly performs the approval action
6. WHEN the manager rejects a request, THE Manager_Review_Interface SHALL update the request status to Rejected and require a rejection reason
7. THE Manager_Review_Interface SHALL provide a bulk print option to generate purchase orders for multiple approved requests
8. THE Manager_Review_Interface SHALL notify the requesting staff member when their request status changes
9. THE Manager_Review_Interface SHALL prevent request status updates to Approved_Request through bulk operations or automated processes unless a manager explicitly approves the request and a purchase order is generated

### Requirement 8: Data Validation and Error Handling

**User Story:** As a staff member, I want the system to validate my input and provide clear error messages, so that I can correct mistakes before they affect inventory records.

#### Acceptance Criteria

1. WHEN the staff member enters a negative quantity for delivered stock, THE Stock_In_Recorder SHALL block the submit button and prevent any submission attempt
2. WHEN the staff member enters a current pump reading lower than the previous reading, THE Fuel_Inventory_Manager SHALL display a warning message asking for confirmation
3. THE Staff_Inventory_System SHALL validate that delivery quantities contain positive numeric values
4. WHEN required fields are empty, THE Staff_Inventory_System SHALL show partial validation results as fields are completed, highlighting missing fields and displaying an error message listing all incomplete required fields
5. THE Staff_Inventory_System SHALL validate date fields to ensure they are in the correct format and not set to future dates for deliveries
6. WHEN database connection fails during submission, THE Staff_Inventory_System SHALL display a user-friendly error message and preserve the user's input for retry
