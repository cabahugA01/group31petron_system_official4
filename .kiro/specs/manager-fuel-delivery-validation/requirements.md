# Requirements Document

## Introduction

This feature implements the Manager Fuel Delivery Validation workflow for the Petron Station Management System. Currently, the system auto-updates fuel inventory the moment a delivery is recorded — before any manager review. This feature corrects that flow: Staff encode supplier Delivery Receipts (DRs) with status `Pending Review` and no inventory change. The Manager then reviews each DR and takes one of three actions — Approve, Reject, or Adjust. Only on Approval does the fuel inventory update. Rejection returns the record to Staff for correction. Adjustment lets the Manager modify the delivery volume before approving. All actions are logged in the audit trail.

---

## Glossary

- **Manager**: A user with the `manager` role who is responsible for validating fuel deliveries at a station.
- **Staff**: A user with the `staff`, `cashier`, or `pump_attendant` role who records supplier DRs.
- **DR (Delivery Receipt)**: The physical document from the fuel supplier that records the delivered fuel volume, invoice number, tanker number, and date.
- **Validation_System**: The server-side PHP component in `manager_fuel_management_complete.php` that processes Manager validation actions.
- **Delivery_Record**: A row in the `fuel_deliveries` table representing one supplier DR encoded by Staff.
- **Fuel_Inventory**: The `fuel_inventory` table row for a given station and fuel type, tracking `current_stock`.
- **Audit_Trail**: The `fuel_adjustments` table used to log all stock changes with actor, reason, and timestamp.
- **Pending Review**: The initial status of a Delivery_Record after Staff encoding — no inventory update has occurred.
- **Verified**: The status of a Delivery_Record after Manager approval — inventory has been updated.
- **Rejected**: The status of a Delivery_Record after Manager rejection — flagged for Staff correction, no inventory change.
- **Adjusted Volume**: The corrected delivery volume entered by the Manager before approving a Delivery_Record.

---

## Requirements

### Requirement 1: Staff Encodes Delivery Receipt Without Inventory Update

**User Story:** As a Staff member, I want to record a supplier DR so that the Manager can review it before it affects fuel inventory.

#### Acceptance Criteria

1. WHEN a Staff member submits the delivery recording form with valid fields (delivery date, fuel type, invoice number, delivery liters > 0), THE Validation_System SHALL insert a Delivery_Record into `fuel_deliveries` with status `Pending Review`.
2. WHEN a Delivery_Record is created with status `Pending Review`, THE Validation_System SHALL NOT update `fuel_inventory.current_stock`.
3. WHEN a Staff member submits the delivery recording form with missing required fields (delivery date, fuel type, invoice number, or delivery liters ≤ 0), THE Validation_System SHALL reject the submission and return a descriptive validation error message.
4. WHEN a Delivery_Record is successfully created, THE Validation_System SHALL call `log_activity()` recording the Staff user ID, action `Record Fuel Delivery`, and the delivery details.
5. THE Validation_System SHALL set `received_by` to the authenticated Staff user's ID on every new Delivery_Record.

---

### Requirement 2: Manager Views Pending Deliveries

**User Story:** As a Manager, I want to see all pending supplier DRs so that I can identify which ones need my review.

#### Acceptance Criteria

1. WHEN the Manager loads the Fuel Deliveries section, THE Validation_System SHALL query `fuel_deliveries` for all records belonging to the Manager's `station_id` and display them in a table ordered by `created_at` descending.
2. WHEN there are Delivery_Records with status `Pending Review`, THE Validation_System SHALL display a badge showing the count of pending records in the section header.
3. WHEN a Delivery_Record has status `Pending Review`, THE Validation_System SHALL visually highlight that row (e.g., yellow background) to distinguish it from already-validated records.
4. THE Validation_System SHALL display the following columns for each Delivery_Record: date, fuel type, volume (L), invoice number, tanker number, notes, recorded-by name, status badge, validated-by name, and action buttons.
5. WHILE a Delivery_Record has status `Pending Review`, THE Validation_System SHALL render Approve, Reject, and Adjust action controls for that row.
6. WHILE a Delivery_Record has status `Verified` or `Rejected`, THE Validation_System SHALL NOT render editable action controls for that row.

---

### Requirement 3: Manager Approves a Delivery

**User Story:** As a Manager, I want to approve a supplier DR so that the delivered fuel volume is added to the station's inventory.

#### Acceptance Criteria

1. WHEN the Manager submits an Approve action for a Delivery_Record with status `Pending Review`, THE Validation_System SHALL update that record's status to `Verified`, set `verified_by` to the Manager's user ID, and set `verified_at` to the current timestamp — all within a single database transaction.
2. WHEN a Delivery_Record is approved, THE Validation_System SHALL execute `UPDATE fuel_inventory SET current_stock = current_stock + delivery_liters WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))` using the record's `delivery_liters` and `fuel_type`.
3. WHEN a Delivery_Record is approved, THE Validation_System SHALL insert a row into `fuel_adjustments` with `adjustment_type = 'delivery_approved'`, `liters` equal to the approved delivery volume, `user_id` equal to the Manager's ID, and a `reason` referencing the delivery ID and invoice number.
4. WHEN a Delivery_Record is approved, THE Validation_System SHALL call `log_activity()` recording the Manager user ID, action `Approve Delivery`, and the delivery ID, fuel type, volume, and invoice number.
5. IF the database transaction for approval fails, THEN THE Validation_System SHALL roll back all changes and return an error message without modifying `fuel_inventory` or `fuel_adjustments`.
6. IF the Manager submits an Approve action for a Delivery_Record that does not have status `Pending Review`, THEN THE Validation_System SHALL reject the action and return the message "This delivery has already been processed."

---

### Requirement 4: Manager Rejects a Delivery

**User Story:** As a Manager, I want to reject a supplier DR so that Staff can correct the entry before it affects inventory.

#### Acceptance Criteria

1. WHEN the Manager submits a Reject action for a Delivery_Record with status `Pending Review`, THE Validation_System SHALL require a non-empty rejection reason before processing.
2. WHEN the Manager submits a Reject action with a valid rejection reason, THE Validation_System SHALL update the Delivery_Record's status to `Rejected`, set `verified_by` to the Manager's user ID, and set `verified_at` to the current timestamp.
3. WHEN a Delivery_Record is rejected, THE Validation_System SHALL NOT modify `fuel_inventory.current_stock`.
4. WHEN a Delivery_Record is rejected, THE Validation_System SHALL insert a row into `fuel_adjustments` with `adjustment_type = 'delivery_rejected'`, `liters = 0`, `user_id` equal to the Manager's ID, and a `reason` containing the rejection reason and delivery ID.
5. WHEN a Delivery_Record is rejected, THE Validation_System SHALL call `log_activity()` recording the Manager user ID, action `Reject Delivery`, the delivery ID, and the rejection reason.
6. WHEN a Delivery_Record is rejected, THE Validation_System SHALL store the Manager's rejection reason so that Staff can view it when reviewing their delivery history.

---

### Requirement 5: Manager Adjusts and Approves a Delivery

**User Story:** As a Manager, I want to adjust the delivery volume on a supplier DR before approving it so that the correct volume is added to inventory when the physical receipt differs from what Staff encoded.

#### Acceptance Criteria

1. WHEN the Manager submits an Adjust action for a Delivery_Record with status `Pending Review`, THE Validation_System SHALL require both an adjusted volume (> 0 liters) and a non-empty reason for the adjustment.
2. WHEN the Manager submits a valid Adjust action, THE Validation_System SHALL update `fuel_deliveries.delivery_liters` to the Adjusted Volume, update the status to `Verified`, set `verified_by` to the Manager's user ID, and set `verified_at` to the current timestamp — all within a single database transaction.
3. WHEN a Delivery_Record is adjusted and approved, THE Validation_System SHALL update `fuel_inventory.current_stock` using the Adjusted Volume, not the original encoded volume.
4. WHEN a Delivery_Record is adjusted and approved, THE Validation_System SHALL insert a row into `fuel_adjustments` with `adjustment_type = 'delivery_adjusted'`, `liters` equal to the Adjusted Volume, `user_id` equal to the Manager's ID, and a `reason` that includes both the original volume and the Adjusted Volume.
5. WHEN a Delivery_Record is adjusted and approved, THE Validation_System SHALL call `log_activity()` recording the Manager user ID, action `Adjust Delivery`, the delivery ID, original volume, Adjusted Volume, and adjustment reason.
6. IF the Adjusted Volume submitted by the Manager is ≤ 0, THEN THE Validation_System SHALL reject the action and return the message "Adjusted volume must be greater than 0 liters."

---

### Requirement 6: Audit Trail Completeness

**User Story:** As a Manager, I want every delivery validation action to be fully logged so that there is a complete audit trail for compliance and dispute resolution.

#### Acceptance Criteria

1. THE Validation_System SHALL record a `fuel_adjustments` entry for every Approve, Reject, and Adjust action, including: `station_id`, `fuel_type_id`, `fuel_type`, `adjustment_type`, `liters`, `reason`, `user_id`, and `adjustment_date`.
2. WHEN a `fuel_adjustments` entry is inserted for a delivery action, THE Validation_System SHALL resolve `fuel_type_id` from `fuel_inventory` using the matching `station_id` and `fuel_type` name.
3. IF `fuel_type_id` cannot be resolved from `fuel_inventory` for a given delivery action, THEN THE Validation_System SHALL still complete the approval or rejection and log the error to the PHP error log without blocking the transaction.
4. THE Validation_System SHALL call `log_activity()` for every Approve, Reject, and Adjust action with the actor's user ID, a descriptive action label, and sufficient detail to reconstruct the event.
5. THE Validation_System SHALL record `verified_by` (Manager user ID) and `verified_at` (timestamp) on the `fuel_deliveries` row for every Approve, Reject, and Adjust action.

---

### Requirement 7: Data Integrity and Transaction Safety

**User Story:** As a system operator, I want all delivery validation operations to be atomic so that partial failures never leave inventory in an inconsistent state.

#### Acceptance Criteria

1. WHEN the Manager performs an Approve or Adjust action, THE Validation_System SHALL wrap the `fuel_deliveries` status update, `fuel_inventory` stock update, and `fuel_adjustments` insert in a single PDO database transaction.
2. IF any step within the approval transaction throws an exception, THEN THE Validation_System SHALL call `$pdo->rollBack()` and return an error message to the Manager without committing any partial changes.
3. WHEN the Manager performs a Reject action, THE Validation_System SHALL wrap the `fuel_deliveries` status update and `fuel_adjustments` insert in a single PDO database transaction.
4. THE Validation_System SHALL scope all `fuel_deliveries` queries with `station_id = ?` using the authenticated Manager's station ID to prevent cross-station data access.
5. THE Validation_System SHALL scope all `fuel_inventory` updates with `station_id = ?` using the authenticated Manager's station ID.
6. IF a Manager attempts to validate a Delivery_Record belonging to a different station, THEN THE Validation_System SHALL reject the action and return an error message.

---

### Requirement 8: Staff Visibility of Rejected Deliveries

**User Story:** As a Staff member, I want to see which of my deliveries were rejected and why so that I can correct and re-submit them.

#### Acceptance Criteria

1. WHEN a Staff member views their delivery history in `staff_fuel_deliveries.php`, THE Validation_System SHALL display the `Rejected` status badge on any Delivery_Record that was rejected by the Manager.
2. WHEN a Delivery_Record has status `Rejected`, THE Validation_System SHALL display the Manager's rejection reason (stored in the `notes` field or a dedicated rejection reason field) alongside that record.
3. THE Validation_System SHALL display the name of the Manager who validated (or rejected) each Delivery_Record in the `Verified By` column of the Staff delivery history table.
