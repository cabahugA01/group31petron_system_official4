# Requirements Document

## Introduction

The Unified Transaction Cart feature formalizes the correct functional flow for the Staff Transactions module in the Petron Station Management System. It establishes a clear separation between Job Order (service-only) encoding and Merchandise (parts/products) encoding, while binding both under a single Transaction ID. The feature ensures that: (1) the Job Order section captures service details without an "Add to Cart" button, (2) the Merchandise section owns the single "Add to Cart" button and auto-fetches required parts for service types that need them, (3) every transaction — whether service-only, parts-only, or combined — is saved under one Transaction ID with inventory deduction, staff attribution, and a unified receipt output.

## Glossary

- **Transaction_Hub**: The `staff_transactions_hub.php` page where staff encode all transactions.
- **Job_Order_Section**: The UI panel in the Transaction Hub where staff encode service details (Service Type, Service Fee, Vehicle Type, Plate Number, Mechanic). Contains no "Add to Cart" button.
- **Merchandise_Section**: The UI panel in the Transaction Hub where staff encode merchandise items (SKU, Qty, Unit Price). Contains the single "Add to Cart" button.
- **Cart**: The in-memory list of line items (merchandise and/or service fee) accumulated before checkout.
- **Transaction_ID**: A unique identifier (e.g., `MERCH2025001XXXXX`) that binds all items — service fee and merchandise — under one record.
- **Service_Type**: A named service offered at the station (e.g., Oil Change, Diagnostic Check, Tire Repair). Defined in the `job_order_service_types` table.
- **Required_Parts**: Merchandise items that a Service Type mandates (e.g., Oil Change requires Engine Oil + Oil Filter). Stored in a service-parts mapping table.
- **Merchandise_Transaction**: A record in the `merchandise_transactions` table representing one completed checkout.
- **Transaction_Item**: A row in `merchandise_transaction_items` representing one line item (merchandise or service fee) within a Merchandise_Transaction.
- **Inventory**: The `station_inventory` table tracking stock levels per product per station.
- **Mechanic**: A person assigned to perform a Job Order service, stored in the `mechanics` table.
- **Staff**: An authenticated user with role `staff`, `cashier`, or `pump_attendant` who encodes transactions.
- **Manager**: An authenticated user with role `manager` or `admin` who validates pending transactions.
- **Validation_Status**: The approval state of a transaction: `Pending`, `Verified`, or `Rejected`.
- **Receipt**: The `receipt.php` output page displaying the full transaction details for printing.
- **VAT**: Value-Added Tax at 12%, applied to the subtotal of all items in the cart.
- **Pretty_Printer**: The receipt rendering logic that formats a Merchandise_Transaction into a printable receipt.

---

## Requirements

### Requirement 1: Job Order Section — Service Details Encoding

**User Story:** As a Staff member, I want to encode service details (Service Type, Service Fee, Vehicle Type, Plate Number, Mechanic) in a dedicated Job Order section, so that service information is captured separately from merchandise items.

#### Acceptance Criteria

1. THE Transaction_Hub SHALL display a Job Order section that contains input fields for Service Type, Service Fee, Vehicle Type, Plate Number, and Mechanic.
2. THE Job_Order_Section SHALL NOT contain an "Add to Cart" button.
3. WHEN a Staff member selects a Service Type, THE Transaction_Hub SHALL auto-populate the Service Fee field with the `service_price` value from the `job_order_service_types` table for that Service Type, and the Staff member SHALL be permitted to override the auto-populated value.
4. WHEN a Staff member selects a Service Type that has no Required_Parts (e.g., Diagnostic Check), THE Transaction_Hub SHALL compute the transaction total as equal to the Service Fee with zero additional parts cost.
5. WHEN a Staff member selects a Service Type that has Required_Parts (e.g., Oil Change), THE Transaction_Hub SHALL auto-fetch the associated Required_Parts from Inventory and display them as pre-populated line items in the Cart; IF any Required_Part has a `stock_level` of 0, THEN that part SHALL be shown as unavailable and excluded from the Cart.
6. IF a Staff member attempts to submit a transaction with a Service Type selected but without providing both Plate Number and Vehicle Type, THEN THE Transaction_Hub SHALL display a field-level error indicator on the missing field(s) and prevent submission.
7. IF a Staff member submits a Job Order without selecting a Mechanic, THEN THE Transaction_Hub SHALL display a validation error and prevent submission.
8. IF a Staff member enters a Plate Number, THEN THE Transaction_Hub SHALL accept only values containing 2 to 10 alphanumeric characters (letters, digits, and spaces) and SHALL display a validation error for any value outside this range.

---

### Requirement 2: Merchandise Section — Parts and Add to Cart

**User Story:** As a Staff member, I want to add merchandise items to a cart using a single "Add to Cart" button in the Merchandise section, so that all parts and products are collected before checkout.

#### Acceptance Criteria

1. THE Merchandise_Section SHALL contain exactly one "Add to Cart" button.
2. WHEN a Staff member clicks "Add to Cart" with a product selected, THE Cart SHALL append the selected merchandise item as a new line item (even if the same SKU already exists in the Cart), recording SKU, Qty, Unit Price, and computed Subtotal.
3. WHEN a Staff member selects a Service Type in the Job Order section that has Required_Parts, THE Merchandise_Section SHALL automatically display those Required_Parts as pre-populated line items in the Cart without requiring the Staff member to click "Add to Cart".
4. THE Cart SHALL allow a Staff member to remove any individual line item before checkout.
5. WHEN a Staff member changes the quantity of a Cart line item, THE Cart SHALL recompute the line item subtotal and the Cart grand total within 500 milliseconds.
6. IF a Staff member attempts to add a merchandise item with a quantity exceeding the available Inventory stock level, THEN THE Transaction_Hub SHALL display a stock-exceeded warning, preserve the previously entered quantity value, and prevent the item from being added.
7. THE Merchandise_Section SHALL allow adding merchandise items independently of whether a Job Order is present.
8. IF the auto-fetch of Required_Parts fails (e.g., network error or API timeout), THEN THE Transaction_Hub SHALL display an error message indicating that parts could not be loaded and prompt the Staff member to retry or add parts manually.

---

### Requirement 3: Unified Transaction ID

**User Story:** As a Staff member, I want every transaction — whether service-only, parts-only, or combined — to be saved under one Transaction ID, so that all related items are traceable as a single record.

#### Acceptance Criteria

1. WHEN a Staff member submits a transaction, THE Transaction_Hub SHALL generate exactly one Transaction_ID that covers all Cart items (service fee and/or merchandise).
2. THE Transaction_ID SHALL follow the format `MERCH{YEAR}{STATION_ID_PADDED}{RANDOM_5_DIGITS}` where `STATION_ID_PADDED` is the station ID zero-padded to 3 digits and `RANDOM_5_DIGITS` is a randomly generated 5-digit numeric string; IF a generated Transaction_ID already exists in the `merchandise_transactions` table, THEN THE system SHALL regenerate until a unique value is produced.
3. WHEN a transaction contains only a service fee item (no merchandise), THE Merchandise_Transaction SHALL be classified with `transaction_type = 'job_order'`.
4. WHEN a transaction contains only merchandise items (no service fee), THE Merchandise_Transaction SHALL be classified with `transaction_type = 'merchandise'`.
5. WHEN a transaction contains both a service fee item and merchandise items, THE Merchandise_Transaction SHALL be classified with `transaction_type = 'combined'`.
6. WHEN a transaction is submitted, THE Merchandise_Transaction SHALL store the Staff member's user ID (`staff_id`) and the server timestamp (`created_at`) at the moment of submission.
7. WHEN a transaction is created, THE Merchandise_Transaction SHALL be saved with `validation_status = 'Pending'`.
8. IF a Staff member attempts to submit a transaction with an empty Cart (no line items), THEN THE Transaction_Hub SHALL display a validation error and prevent submission.

---

### Requirement 4: Automatic Inventory Deduction

**User Story:** As a Manager, I want the system to automatically deduct consumed parts from inventory when a transaction is submitted, so that stock levels remain accurate without manual adjustment.

#### Acceptance Criteria

1. WHEN a transaction is submitted, THE Transaction_Hub SHALL deduct the quantity of each merchandise line item from the corresponding product's `stock_level` in the `station_inventory` table for the current station.
2. WHEN a Job Order service has Required_Parts and those Required_Parts are explicitly included as line items in the Cart at submission time, THE Transaction_Hub SHALL deduct those Required_Parts quantities from Inventory; Required_Parts that were not added to the Cart SHALL NOT be deducted.
3. IF any merchandise line item's `stock_level` in Inventory is zero, or IF the product has no record in `station_inventory` for the current station, THEN THE Transaction_Hub SHALL reject the entire transaction and return an error message that identifies all failing product names.
4. THE inventory deduction and the transaction record creation SHALL be executed within a single database transaction so that a failure in either step rolls back both operations completely.
5. WHEN a Manager rejects a transaction, THE system SHALL NOT reverse the inventory deduction; the deduction applied at staff submission time is permanent and is not affected by the Manager's validation decision.

---

### Requirement 5: Staff Attribution and Timestamp

**User Story:** As a Manager, I want every transaction to record the Staff member's name and the exact submission timestamp, so that accountability and audit trails are maintained.

#### Acceptance Criteria

1. THE Merchandise_Transaction SHALL store the authenticated Staff member's user ID in the `staff_id` column.
2. WHEN a transaction is submitted, THE Merchandise_Transaction SHALL store the server-side submission timestamp in the `created_at` column in `YYYY-MM-DD HH:MM:SS` format using Philippine Time (PHT, UTC+8).
3. WHEN a receipt is generated for a transaction, THE Receipt SHALL display the Staff member's full name resolved from the `users` table using the stored `staff_id`; IF the `staff_id` cannot be resolved to a user record, THEN THE Receipt SHALL display "Unknown Staff" in place of the name.
4. WHEN a transaction is submitted, THE Merchandise_Transaction SHALL store the active shift period key in `shift_period` and the shift display name in `shift_name` as they exist at the moment of submission; these stored values SHALL NOT be updated if the shift configuration changes after submission; IF no active shift session exists at submission time, THEN THE system SHALL store `'unassigned'` in `shift_period` and `'No Active Shift'` in `shift_name`.

---

### Requirement 6: Unified Receipt Output

**User Story:** As a Staff member, I want to print a single receipt that covers both the service fee and merchandise items in one document, so that the customer receives one clear record of the entire transaction.

#### Acceptance Criteria

1. WHEN a receipt is requested for a valid Transaction_ID, THE Receipt SHALL display a header containing: Station Name, Station Address, VAT TIN, Transaction ID, Date, Time, and Staff Name.
2. WHEN a receipt is requested for a transaction that has a Job Order service, THE Receipt SHALL display a "Job Order Details" section containing: Service Type, Vehicle Plate, Vehicle Type, and Mechanic Name.
3. IF the transaction record contains a non-null Job Order ID, THEN THE Receipt SHALL also display the Job Order ID within the "Job Order Details" section.
4. WHEN a receipt is requested for a transaction with merchandise items, THE Receipt SHALL display an "Items Purchased" table with columns: Item Name, Quantity, Unit Price, and Subtotal.
5. WHEN a receipt is requested for a valid transaction, THE Receipt SHALL display a totals section containing: Vatable Sales (subtotal before VAT), VAT amount (12% of Vatable Sales), and Grand Total (Vatable Sales + VAT).
6. WHEN a receipt is requested for a valid transaction, THE Receipt SHALL display a payment section containing: Payment Method and Amount Tendered; IF the payment method is cash, THEN THE Receipt SHALL also display the Change amount (Amount Tendered minus Grand Total).
7. THE Receipt SHALL display a QR code encoding the Transaction ID, customer name, date/time, grand total, and VAT TIN for verification.
8. WHEN a receipt is requested for a transaction that does not exist, THE Receipt SHALL display a "Receipt Not Found" message.
9. WHEN a receipt is rendered, THE Receipt page SHALL produce output sized for 80mm thermal printer paper such that all content fits within the 80mm width without horizontal scrolling or clipping.
10. FOR ALL valid Merchandise_Transactions, re-requesting the same receipt URL SHALL return a page displaying the same Transaction_ID, customer name, grand total, VAT amount, and line item list as the first request.

---

### Requirement 7: Manager Validation Workflow

**User Story:** As a Manager, I want to review and validate or reject pending transactions submitted by staff, so that I can ensure accuracy before transactions are finalized.

#### Acceptance Criteria

1. WHEN a transaction is submitted by Staff, THE Merchandise_Transaction SHALL appear in the Manager's pending transactions list scoped to the Manager's assigned station, with `validation_status = 'Pending'`.
2. THE Manager's pending transactions list SHALL only display transactions belonging to the same `station_id` as the authenticated Manager.
3. WHEN a Manager approves a transaction, THE Transaction_Hub SHALL update `validation_status` to `'Verified'`, record the Manager's user ID in `validated_by`, and store the approval timestamp in `validated_at`.
4. WHEN a Manager rejects a transaction, THE Transaction_Hub SHALL update `validation_status` to `'Rejected'` and store a rejection reason of 10 to 500 characters in `rejection_reason`.
5. IF a user with role `staff`, `cashier`, or `pump_attendant` attempts to perform a validation action (approve or reject), THEN THE Transaction_Hub SHALL deny the action and display an access-denied message.
6. WHEN a receipt is generated for a transaction, THE Receipt SHALL display the current `validation_status` label of the transaction.
