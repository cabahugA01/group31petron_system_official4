# Requirements Document

## Introduction

This feature implements a complete 3-stage delivery flow for the Petron gas station management system. The flow covers: (1) Staff encoding of supplier Delivery Receipt (DR) details, (2) Manager validation of encoded deliveries, and (3) Staff Stock-In of actual received items — the only step that updates inventory. The system handles both merchandise and fuel deliveries, maintains a full audit trail, and integrates with the existing `purchase_orders`, `fuel_deliveries`, `deliveries_oversight`, `merchandise_stock_in`, and `fuel_stock_in` tables.

---

## Glossary

- **Delivery_Encoder**: A staff, cashier, or pump_attendant user who encodes supplier DR details in Stage 1.
- **Delivery_Validator**: A manager, admin, or superadmin user who reviews and approves/rejects/adjusts encoded deliveries in Stage 2.
- **Stock_In_Encoder**: A staff, cashier, or pump_attendant user who encodes actual received items in Stage 3.
- **DR**: Delivery Receipt — the physical document from the supplier accompanying a delivery.
- **Merchandise_Delivery**: A delivery of non-fuel products (accessories, lubricants, car care items, etc.).
- **Fuel_Delivery**: A delivery of fuel (e.g., Gasoline, Diesel) via tanker.
- **Delivery_Flow**: The 3-stage process: Encode → Validate → Stock-In.
- **Deliveries_Tab**: The UI page (`staff_encode_deliveries.php`) where Stage 1 occurs.
- **Validation_Tab**: The UI page (`manager_delivery_validation.php`) where Stage 2 occurs.
- **Stock_In_Tab**: The UI page (`staff_stock_in.php`) where Stage 3 occurs.
- **Pending_Manager_Validation**: The status assigned to a delivery after Stage 1 encoding, before manager action.
- **Awaiting_Stock_In**: The status assigned to a delivery after manager approval, before Stage 3.
- **Stock_In_Complete**: The status assigned after Stage 3 is completed and inventory is updated.
- **Audit_Trail**: A log of all actions (encode, approve, reject, adjust, stock-in) with user, timestamp, and details.
- **Station_Inventory**: The `station_inventory` table tracking per-station stock levels for merchandise.
- **Fuel_Inventory**: The `fuel_inventory` table tracking per-station fuel tank levels.

---

## Requirements

### Requirement 1: Staff Encode Merchandise Delivery (Stage 1)

**User Story:** As a Delivery_Encoder, I want to record supplier DR details for merchandise deliveries, so that the manager can validate the delivery before inventory is updated.

#### Acceptance Criteria

1. WHEN a Delivery_Encoder submits a merchandise delivery form, THE Delivery_Flow_System SHALL create a record in the `deliveries_oversight` table with status `Pending Manager Validation`.
2. THE Delivery_Flow_System SHALL require the following fields for a merchandise delivery: Supplier, Product, Quantity, Unit, Date, and DR Number.
3. THE Delivery_Flow_System SHALL allow an optional Remarks field for a merchandise delivery record.
4. WHEN a Delivery_Encoder submits a merchandise delivery, THE Delivery_Flow_System SHALL NOT update any inventory stock levels.
5. THE Delivery_Flow_System SHALL allow a Delivery_Encoder to encode multiple merchandise line items in a single delivery batch, each with its own Product, Quantity, and Unit.
6. WHEN a merchandise delivery is successfully encoded, THE Delivery_Flow_System SHALL display a confirmation message to the Delivery_Encoder.
7. IF a required field is missing or invalid, THEN THE Delivery_Flow_System SHALL display a descriptive validation error and retain the entered form data.
8. THE Delivery_Flow_System SHALL restrict access to the Deliveries_Tab to users with roles: staff, cashier, or pump_attendant.
9. WHEN a merchandise delivery is encoded, THE Delivery_Flow_System SHALL log the action in the Audit_Trail with the encoder's user ID, timestamp, and delivery details.
10. THE Delivery_Flow_System SHALL populate the Supplier dropdown from the `suppliers` table and allow free-text entry for unlisted suppliers.
11. THE Delivery_Flow_System SHALL populate the Product dropdown from the `inventory_products` table filtered by non-Fuel categories.

---

### Requirement 2: Staff Encode Fuel Delivery (Stage 1)

**User Story:** As a Delivery_Encoder, I want to record supplier DR details for fuel deliveries, so that the manager can validate the tanker delivery before tank levels are updated.

#### Acceptance Criteria

1. WHEN a Delivery_Encoder submits a fuel delivery form, THE Delivery_Flow_System SHALL create a record in the `fuel_deliveries` table with status `Pending`.
2. THE Delivery_Flow_System SHALL require the following fields for a fuel delivery: Supplier, Fuel Type, Liters, Invoice Number, Tanker Number, and Delivery Date.
3. THE Delivery_Flow_System SHALL allow an optional Remarks field for a fuel delivery record.
4. WHEN a Delivery_Encoder submits a fuel delivery, THE Delivery_Flow_System SHALL NOT update `fuel_inventory` tank levels.
5. THE Delivery_Flow_System SHALL populate the Fuel Type dropdown from the `fuel_types` table.
6. WHEN a fuel delivery is successfully encoded, THE Delivery_Flow_System SHALL display a confirmation message to the Delivery_Encoder.
7. IF a required field is missing or invalid, THEN THE Delivery_Flow_System SHALL display a descriptive validation error and retain the entered form data.
8. WHEN a fuel delivery is encoded, THE Delivery_Flow_System SHALL log the action in the Audit_Trail with the encoder's user ID, timestamp, and delivery details.

---

### Requirement 3: Deliveries Tab — Unified Staff Encode Page

**User Story:** As a Delivery_Encoder, I want a single Deliveries tab that lets me encode both merchandise and fuel deliveries, so that I have one consistent place to record all incoming supplier deliveries.

#### Acceptance Criteria

1. THE Delivery_Flow_System SHALL provide a Deliveries_Tab page accessible to users with roles: staff, cashier, or pump_attendant.
2. THE Delivery_Flow_System SHALL display a tab or toggle on the Deliveries_Tab to switch between Merchandise and Fuel delivery encoding forms.
3. THE Delivery_Flow_System SHALL display a list of previously encoded deliveries (pending and recent history) on the Deliveries_Tab, filtered by the current station.
4. WHEN a delivery record has status `Rejected` or `Discrepancy`, THE Delivery_Flow_System SHALL allow the Delivery_Encoder to edit and resubmit the record from the Deliveries_Tab.
5. THE Delivery_Flow_System SHALL display the current status of each encoded delivery (e.g., Pending Manager Validation, Awaiting Stock-In, Stock-In Complete, Rejected).
6. THE Delivery_Flow_System SHALL filter all displayed delivery records by the Delivery_Encoder's assigned station.

---

### Requirement 4: Manager Delivery Validation — Merchandise (Stage 2)

**User Story:** As a Delivery_Validator, I want to review encoded merchandise deliveries and approve, reject, or adjust them, so that I can ensure the actual delivery matches the DR before inventory is updated.

#### Acceptance Criteria

1. THE Delivery_Flow_System SHALL display all merchandise delivery records with status `Pending Manager Validation` on the Validation_Tab, filtered by station.
2. WHEN a Delivery_Validator approves a merchandise delivery, THE Delivery_Flow_System SHALL update the record status to `Awaiting Stock-In` and record the validator's user ID and timestamp.
3. WHEN a Delivery_Validator rejects a merchandise delivery, THE Delivery_Flow_System SHALL update the record status to `Rejected` and require a rejection reason (minimum 5 characters).
4. WHEN a Delivery_Validator adjusts a merchandise delivery, THE Delivery_Flow_System SHALL update the quantity to the adjusted value, set status to `Awaiting Stock-In`, and record the original and adjusted quantities in the notes.
5. WHEN a merchandise delivery is approved or adjusted, THE Delivery_Flow_System SHALL NOT update inventory stock levels.
6. WHEN a merchandise delivery is rejected, THE Delivery_Flow_System SHALL make the record editable by the Delivery_Encoder for correction and resubmission.
7. THE Delivery_Flow_System SHALL restrict access to the Validation_Tab to users with roles: manager, admin, or superadmin.
8. WHEN a manager action (approve/reject/adjust) is taken, THE Delivery_Flow_System SHALL log the action in the Audit_Trail with the validator's user ID, action type, timestamp, and notes.
9. THE Delivery_Flow_System SHALL display the history of validated merchandise deliveries on the Validation_Tab with their final status and validator details.

---

### Requirement 5: Manager Delivery Validation — Fuel (Stage 2)

**User Story:** As a Delivery_Validator, I want to review encoded fuel deliveries and approve, reject, or adjust them, so that I can confirm the tanker volume before tank levels are updated.

#### Acceptance Criteria

1. THE Delivery_Flow_System SHALL display all fuel delivery records with status `Pending` on the Validation_Tab (fuel section), filtered by station.
2. WHEN a Delivery_Validator approves a fuel delivery, THE Delivery_Flow_System SHALL update the `fuel_deliveries` record status to `Awaiting Stock-In` and record the validator's user ID and timestamp.
3. WHEN a Delivery_Validator rejects a fuel delivery, THE Delivery_Flow_System SHALL update the `fuel_deliveries` record status to `Rejected` and require a rejection reason.
4. WHEN a Delivery_Validator adjusts a fuel delivery, THE Delivery_Flow_System SHALL update `delivery_liters` to the adjusted value, set status to `Awaiting Stock-In`, and record the original and adjusted volumes in the notes.
5. WHEN a fuel delivery is approved or adjusted, THE Delivery_Flow_System SHALL NOT update `fuel_inventory` tank levels.
6. IF the approved or adjusted fuel volume would exceed the tank capacity for that fuel type, THEN THE Delivery_Flow_System SHALL reject the action and display the available tank space to the Delivery_Validator.
7. WHEN a fuel manager action is taken, THE Delivery_Flow_System SHALL log the action in the `audit_trail` table with the validator's user ID, action type, delivery ID, fuel type, volume, and notes.

---

### Requirement 6: Staff Stock-In — Merchandise (Stage 3)

**User Story:** As a Stock_In_Encoder, I want to encode the actual received merchandise items after manager validation, so that inventory is updated accurately to reflect what was physically received.

#### Acceptance Criteria

1. THE Delivery_Flow_System SHALL display all merchandise delivery records with status `Awaiting Stock-In` on the Stock_In_Tab, filtered by station.
2. THE Stock_In_Tab SHALL require the following fields per item: Actual Quantity Received, Condition (Good / Short / Damaged / Excess), and optional Remarks.
3. WHEN a Stock_In_Encoder submits a merchandise stock-in with Condition `Good` or `Excess`, THE Delivery_Flow_System SHALL add the received quantity to `station_inventory.stock_level` for the corresponding product and station.
4. WHEN a Stock_In_Encoder submits a merchandise stock-in with Condition `Short` or `Damaged`, THE Delivery_Flow_System SHALL record the stock-in entry without adding to inventory stock levels.
5. WHEN a merchandise stock-in is submitted, THE Delivery_Flow_System SHALL insert a record into `merchandise_stock_in` capturing: po_id, product, qty_ordered, qty_received, qty_variance, condition_flag, stock_before, stock_after, encoded_by, and encoded_at.
6. WHEN a merchandise stock-in is submitted, THE Delivery_Flow_System SHALL update the corresponding `purchase_orders` record to `stock_in_done = 1` and `status = 'Stock-In Complete'`.
7. WHEN a merchandise stock-in is submitted, THE Delivery_Flow_System SHALL log the action in `audit_logs` with the encoder's user ID, batch reference, product, quantities, and timestamp.
8. THE Delivery_Flow_System SHALL restrict access to the Stock_In_Tab to users with roles: staff, cashier, or pump_attendant only. Users with roles admin or superadmin SHALL be redirected to the dashboard and SHALL NOT be permitted to encode Stock-In records.
9. THE Delivery_Flow_System SHALL display the manager-validated quantity as a reference baseline when the Stock_In_Encoder enters the actual received quantity.

---

### Requirement 7: Staff Stock-In — Fuel (Stage 3)

**User Story:** As a Stock_In_Encoder, I want to encode the actual received fuel liters after manager validation, so that tank levels are updated accurately to reflect what was physically received.

#### Acceptance Criteria

1. THE Delivery_Flow_System SHALL display all fuel delivery records with status `Awaiting Stock-In` on the Stock_In_Tab (fuel section), filtered by station.
2. THE Stock_In_Tab SHALL require the following fields for fuel stock-in: Actual Received Liters, Condition (Good / Short / Damaged / Excess), and optional Remarks.
3. WHEN a Stock_In_Encoder submits a fuel stock-in with Condition `Good` or `Excess`, THE Delivery_Flow_System SHALL add the received liters to `fuel_inventory.current_level` and `fuel_inventory.current_stock` for the corresponding fuel type and station.
4. WHEN a Stock_In_Encoder submits a fuel stock-in with Condition `Short` or `Damaged`, THE Delivery_Flow_System SHALL record the stock-in entry without updating `fuel_inventory` levels.
5. WHEN a fuel stock-in is submitted, THE Delivery_Flow_System SHALL insert a record into `fuel_stock_in` capturing: delivery_id, fuel_type, qty_expected, qty_received, qty_variance, condition_flag, level_before, level_after, encoded_by, and encoded_at.
6. WHEN a fuel stock-in is submitted, THE Delivery_Flow_System SHALL update the `fuel_deliveries` record status to `Stock-In Complete`.
7. WHEN a fuel stock-in is submitted with Condition `Good` or `Excess`, THE Delivery_Flow_System SHALL insert a record into `fuel_adjustments` with adjustment_type `delivery`.
8. WHEN a fuel stock-in is submitted, THE Delivery_Flow_System SHALL log the action in `audit_logs` with the encoder's user ID, batch reference, fuel type, liters, and timestamp.
9. THE Delivery_Flow_System SHALL display the manager-validated liters as a reference baseline when the Stock_In_Encoder enters the actual received liters.

---

### Requirement 8: Audit Trail

**User Story:** As a manager or admin, I want a complete audit trail of all delivery flow actions, so that I can trace every encode, approval, rejection, adjustment, and stock-in event for accountability.

#### Acceptance Criteria

1. THE Delivery_Flow_System SHALL record every Stage 1 encode action in `audit_logs` with: user_id, log_type `delivery`, action_type `Encode Delivery`, action_details, entity_type, entity_id, and created_at.
2. THE Delivery_Flow_System SHALL record every Stage 2 manager action (Approve/Reject/Adjust) in `audit_logs` and `audit_trail` with: user_id, action_type, delivery reference, product or fuel type, quantity or liters, notes, and timestamp.
3. THE Delivery_Flow_System SHALL record every Stage 3 stock-in action in `audit_logs` with: user_id, log_type `inventory`, action_type `Stock-In`, batch_ref, product or fuel type, qty_received, and timestamp.
4. THE Delivery_Flow_System SHALL preserve audit records even if the associated delivery record is later modified.
5. WHEN any delivery flow action fails due to a system error, THE Delivery_Flow_System SHALL log the error details without exposing sensitive data to the end user.

---

### Requirement 9: Status Lifecycle and Flow Integrity

**User Story:** As a system administrator, I want the delivery flow to enforce strict status transitions, so that deliveries cannot skip stages or be processed out of order.

#### Acceptance Criteria

1. THE Delivery_Flow_System SHALL only allow Stage 2 validation actions on records with status `Pending Manager Validation` (merchandise) or `Pending` (fuel).
2. THE Delivery_Flow_System SHALL only allow Stage 3 stock-in actions on records with status `Awaiting Stock-In`.
3. WHEN a stock-in has already been completed for a delivery record, THE Delivery_Flow_System SHALL prevent a second stock-in submission for the same record.
4. WHEN a delivery record is rejected, THE Delivery_Flow_System SHALL prevent stock-in from being performed until the record is corrected and re-approved.
5. THE Delivery_Flow_System SHALL filter all delivery records by `station_id` to prevent cross-station data access.
6. WHEN a Delivery_Encoder attempts to access the Validation_Tab, THE Delivery_Flow_System SHALL redirect the user to the dashboard with an access-denied message.
7. WHEN a Delivery_Validator attempts to access the Deliveries_Tab encoding form, THE Delivery_Flow_System SHALL restrict encoding actions to staff-role users only.

---

### Requirement 10: Navigation and Dashboard Integration

**User Story:** As a staff or manager user, I want delivery flow actions to be accessible from the dashboard and navigation, so that I can quickly see pending tasks and navigate to the correct page.

#### Acceptance Criteria

1. THE Delivery_Flow_System SHALL display a pending delivery count badge on the staff dashboard for deliveries with status `Pending Manager Validation` at the current station.
2. THE Delivery_Flow_System SHALL display a pending validation count badge on the manager dashboard for deliveries awaiting manager action at the current station.
3. THE Delivery_Flow_System SHALL display a pending stock-in count badge on the staff dashboard for deliveries with status `Awaiting Stock-In` at the current station.
4. WHEN a delivery is approved by the manager, THE Delivery_Flow_System SHALL make the record immediately visible on the Stock_In_Tab without requiring a page reload beyond normal navigation.
5. THE Delivery_Flow_System SHALL provide navigation links between the Deliveries_Tab, Validation_Tab, and Stock_In_Tab appropriate to the user's role.
