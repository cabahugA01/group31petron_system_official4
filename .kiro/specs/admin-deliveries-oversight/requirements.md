# Requirements Document

## Introduction

This feature implements the **Admin – Deliveries Oversight (Compliance Monitoring)** module for the Petron Station Management System. The Admin does not encode deliveries — that is the responsibility of Staff and Manager. Instead, the Admin reviews merchandise delivery records that have already been encoded, then either Approves (marks as compliant) or Rejects (flags as non-compliant) each record. Fuel deliveries are excluded from this view entirely.

The existing page (`public/admin_deliveries_oversight.php`) and API (`backend/api/admin_deliveries_oversight_api.php`) require the following corrections:
- Remove the `encode` action and the "New Delivery" button — Admin does not encode.
- Normalize status values from `Pending Validation / Validated / Flagged` to `Pending / Approved / Rejected`.
- Restrict the view to `delivery_type = 'merchandise'` only.
- Add a summary dashboard (header cards) for Pending, Approved, Rejected counts and Total Quantity Received.
- Keep the "Sync from Existing Data" utility and the Excel/PDF export functionality.

The data source is the existing `deliveries_oversight` table, scoped to the Admin's `station_id`.

---

## Glossary

- **Admin**: A user with the `admin` or `superadmin` role who is responsible for compliance monitoring of merchandise deliveries at their assigned station.
- **Staff**: A user with the `staff`, `cashier`, or `pump_attendant` role who encodes supplier Delivery Receipts.
- **Manager**: A user with the `manager` role who may also encode delivery records.
- **Oversight_System**: The server-side PHP component (`admin_deliveries_oversight_api.php`) and the front-end page (`admin_deliveries_oversight.php`) that together implement the Admin compliance monitoring workflow.
- **Merchandise_Delivery**: A delivery record in the `deliveries_oversight` table where `delivery_type = 'merchandise'`. Fuel deliveries are excluded from this module.
- **DR (Delivery Receipt)**: The physical document from the supplier that records the delivered product, quantity, and date.
- **Pending**: The initial status of a merchandise delivery record after it has been encoded by Staff or Manager and is awaiting Admin review.
- **Approved**: The status assigned by the Admin when a delivery record is verified as compliant. Does not trigger any inventory change.
- **Rejected**: The status assigned by the Admin when a delivery record is flagged as non-compliant. Requires a rejection reason. Does not reverse any inventory change.
- **Rejection_Reason**: A mandatory text field the Admin must fill in when rejecting a delivery, stored in `admin_notes`.
- **Summary_Dashboard**: The set of four header cards displaying Pending count, Approved count, Rejected count, and Total Quantity Received for the current filter scope.
- **Audit_Trail**: The `audit_trail` table used to log all Admin actions with actor, old status, new status, and timestamp.
- **Sync_Utility**: The "Sync from Existing Data" function that pulls merchandise delivery records from the `deliveries_oversight` table into the oversight list for records not yet present.

---

## Requirements

### Requirement 1: Merchandise-Only Delivery Table

**User Story:** As an Admin, I want to see only merchandise deliveries in the oversight view so that I can focus on compliance monitoring without fuel delivery data cluttering the list.

#### Acceptance Criteria

1. WHEN the Admin loads the Deliveries Oversight page, THE Oversight_System SHALL query the `deliveries_oversight` table with `delivery_type = 'merchandise'` AND `station_id = [Admin's station_id]` so that fuel deliveries are never displayed.
2. THE Oversight_System SHALL display the following columns for every Merchandise_Delivery record: Delivery ID, Supplier Name, Product / Category, Quantity Delivered (with unit), Date & Time Received, Encoded By, Status, and Remarks.
3. THE Oversight_System SHALL display status values exclusively as `Pending`, `Approved`, or `Rejected` — the legacy values `Pending Validation`, `Validated`, and `Flagged` SHALL be mapped to the normalized values on read and on write.
4. THE Oversight_System SHALL NOT display a "New Delivery" button or any encoding form on the Admin Deliveries Oversight page.
5. WHEN the Admin loads the Deliveries Oversight page, THE Oversight_System SHALL order records with `Pending` records first, then `Rejected`, then `Approved`, and within each group by `delivery_date` descending.

---

### Requirement 2: Summary Dashboard Cards

**User Story:** As an Admin, I want a summary dashboard at the top of the page so that I can immediately see how many deliveries are pending review, approved, rejected, and the total quantity received.

#### Acceptance Criteria

1. WHEN the Admin loads the Deliveries Oversight page, THE Oversight_System SHALL display four summary cards: **Pending Deliveries Count**, **Approved Deliveries Count**, **Rejected Deliveries Count**, and **Total Quantity Received**.
2. THE Oversight_System SHALL compute all four summary card values from the same filtered dataset used to populate the delivery table (i.e., the cards reflect the current date range, status filter, and supplier filter).
3. WHEN the Admin applies or changes filters, THE Oversight_System SHALL refresh all four summary card values to match the updated filtered dataset.
4. THE Oversight_System SHALL display the Total Quantity Received as the sum of `quantity` for all Merchandise_Delivery records in the current filtered dataset, formatted with two decimal places and the unit label.

---

### Requirement 3: Admin Approves a Delivery

**User Story:** As an Admin, I want to approve a merchandise delivery record so that it is marked as compliant and the audit trail reflects my review.

#### Acceptance Criteria

1. WHILE a Merchandise_Delivery record has status `Pending`, THE Oversight_System SHALL render an **Approve** action button for that row.
2. WHEN the Admin submits an Approve action for a Merchandise_Delivery with status `Pending`, THE Oversight_System SHALL update that record's `status` to `Approved`, set `admin_id` to the Admin's user ID, and set `admin_action_at` to the current timestamp — all within a single database transaction.
3. WHEN a Merchandise_Delivery is approved, THE Oversight_System SHALL NOT modify `inventory_products.stock` or any other inventory table, because inventory was already updated by the Manager's prior approval action.
4. WHEN a Merchandise_Delivery is approved, THE Oversight_System SHALL insert a row into `audit_trail` with `action_type = 'Admin Approve'`, `transaction_id` equal to the delivery ID, `manager_id` equal to the Admin's user ID, `old_value` equal to the previous status, `new_value = 'Approved'`, `station_id`, and `entity_type = 'delivery'`.
5. WHEN a Merchandise_Delivery is approved, THE Oversight_System SHALL call `log_activity()` recording the Admin's user ID, action label `'Admin Approve Delivery'`, and details including the delivery ID, product, quantity, and supplier.
6. IF the Admin submits an Approve action for a delivery that already has status `Approved`, THEN THE Oversight_System SHALL reject the action and return the message "This delivery has already been approved."
7. IF the database transaction for approval fails, THEN THE Oversight_System SHALL call `$pdo->rollBack()` and return an error message to the Admin without modifying any data.

---

### Requirement 4: Admin Rejects a Delivery

**User Story:** As an Admin, I want to reject a merchandise delivery record so that it is flagged as non-compliant with a documented reason for the record.

#### Acceptance Criteria

1. WHILE a Merchandise_Delivery record has status `Pending`, THE Oversight_System SHALL render a **Reject** action button for that row.
2. WHEN the Admin submits a Reject action, THE Oversight_System SHALL require a non-empty Rejection_Reason before processing.
3. IF the Admin submits a Reject action with an empty Rejection_Reason, THEN THE Oversight_System SHALL reject the submission and return the message "A rejection reason is required."
4. WHEN the Admin submits a Reject action with a valid Rejection_Reason, THE Oversight_System SHALL update the delivery record's `status` to `Rejected`, set `admin_id` to the Admin's user ID, set `admin_action_at` to the current timestamp, and store the Rejection_Reason in `admin_notes` — all within a single database transaction.
5. WHEN a Merchandise_Delivery is rejected, THE Oversight_System SHALL NOT modify `inventory_products.stock` or any other inventory table.
6. WHEN a Merchandise_Delivery is rejected, THE Oversight_System SHALL insert a row into `audit_trail` with `action_type = 'Admin Reject'`, `transaction_id` equal to the delivery ID, `manager_id` equal to the Admin's user ID, `old_value` equal to the previous status, `new_value = 'Rejected: [reason]'`, `station_id`, and `entity_type = 'delivery'`.
7. WHEN a Merchandise_Delivery is rejected, THE Oversight_System SHALL call `log_activity()` recording the Admin's user ID, action label `'Admin Reject Delivery'`, the delivery ID, and the Rejection_Reason.
8. IF the Admin submits a Reject action for a delivery that does not have status `Pending`, THEN THE Oversight_System SHALL reject the action and return the message "Only Pending deliveries can be rejected."
9. IF the database transaction for rejection fails, THEN THE Oversight_System SHALL call `$pdo->rollBack()` and return an error message to the Admin without modifying any data.

---

### Requirement 5: Status Flow Enforcement

**User Story:** As a system operator, I want the delivery status to follow a strict one-way flow so that records cannot be moved to invalid states.

#### Acceptance Criteria

1. THE Oversight_System SHALL enforce the status transition: `Pending` → `Approved` or `Pending` → `Rejected` only.
2. IF the Admin attempts to Approve or Reject a delivery that already has status `Approved` or `Rejected`, THEN THE Oversight_System SHALL reject the action and return an appropriate error message without modifying any data.
3. THE Oversight_System SHALL NOT expose any UI control (button, link, or form) that would allow transitioning a delivery from `Approved` or `Rejected` back to `Pending`.
4. THE Oversight_System SHALL write the normalized status values (`Pending`, `Approved`, `Rejected`) to the `deliveries_oversight` table, replacing any legacy values on update.

---

### Requirement 6: View Delivery Details

**User Story:** As an Admin, I want to view the full details of a delivery record so that I can review all encoded information before deciding to approve or reject.

#### Acceptance Criteria

1. THE Oversight_System SHALL render a **View Details** action button for every Merchandise_Delivery row regardless of status.
2. WHEN the Admin clicks View Details for a delivery, THE Oversight_System SHALL display a detail panel or modal showing: Delivery ID, Supplier Name, DR Number, Product / Category, Quantity Delivered (with unit), Date & Time Received, Encoded By (name), current Status, and Admin Remarks / Rejection Reason.
3. WHEN the Admin clicks View Details for a delivery, THE Oversight_System SHALL display the full audit history for that delivery record, including each action taken, the actor name, and the timestamp.
4. WHEN a delivery has status `Rejected`, THE Oversight_System SHALL prominently display the Rejection_Reason in the detail view.
5. WHEN a delivery has status `Pending`, THE Oversight_System SHALL display Approve and Reject action buttons within the detail panel so the Admin can act without closing the modal.

---

### Requirement 7: Filtering

**User Story:** As an Admin, I want to filter the deliveries table by status, supplier, and date range so that I can quickly locate specific records for review.

#### Acceptance Criteria

1. THE Oversight_System SHALL provide filter controls for: Status (All / Pending / Approved / Rejected), Supplier name (text search), Date From, and Date To.
2. WHEN the Admin applies filters, THE Oversight_System SHALL re-query the database using the selected filter values scoped to `delivery_type = 'merchandise'` AND `station_id = [Admin's station_id]`, and display only matching records.
3. WHEN no filters are applied, THE Oversight_System SHALL default the date range to the last 30 days.
4. WHEN the Admin applies or changes any filter, THE Oversight_System SHALL refresh the Summary_Dashboard cards to reflect the filtered dataset.

---

### Requirement 8: Sync from Existing Data

**User Story:** As an Admin, I want to pull merchandise delivery records from the source tables into the oversight list so that records encoded by Staff or Manager appear for my review.

#### Acceptance Criteria

1. THE Oversight_System SHALL display a "Sync from Existing Data" button on the page.
2. WHEN the Admin clicks "Sync from Existing Data", THE Oversight_System SHALL query the `deliveries_oversight` table for records where `delivery_type = 'merchandise'` AND `station_id = [Admin's station_id]` AND `source_ref` is not already present in the oversight list, and insert any new records with status `Pending`.
3. WHEN the sync completes, THE Oversight_System SHALL display the count of newly pulled records (e.g., "3 new record(s) pulled from existing data.") or "No new records to sync." if none were found.
4. WHEN the sync completes, THE Oversight_System SHALL refresh the delivery table and Summary_Dashboard cards.
5. THE Oversight_System SHALL NOT insert duplicate records — a record with the same `source_ref` and `station_id` SHALL be inserted only once.

---

### Requirement 9: Export to Excel

**User Story:** As an Admin, I want to export the filtered delivery records to Excel so that I can perform internal analysis on the data.

#### Acceptance Criteria

1. THE Oversight_System SHALL provide an **Export to Excel** button on the page.
2. WHEN the Admin clicks Export to Excel, THE Oversight_System SHALL generate a downloadable `.xls` file containing the currently filtered Merchandise_Delivery records for the Admin's station.
3. THE exported Excel file SHALL include the following columns: Delivery ID, Delivery Ref, DR Number, Supplier, Product, Quantity, Unit, Delivery Date, Encoded By, Status, Admin Name, Action Date, and Remarks.
4. THE Oversight_System SHALL name the exported file using the pattern `deliveries_oversight_[YYYY-MM-DD].xls`.
5. THE Oversight_System SHALL apply the same `delivery_type = 'merchandise'` AND `station_id` scope to the export query as it does to the main table query.

---

### Requirement 10: Export to PDF (A4, Paginated)

**User Story:** As an Admin, I want to export the filtered delivery records to a paginated A4 PDF so that I have an official compliance report suitable for submission.

#### Acceptance Criteria

1. THE Oversight_System SHALL provide an **Export to PDF** button on the page.
2. WHEN the Admin clicks Export to PDF, THE Oversight_System SHALL generate an A4-formatted HTML document styled for print, containing the currently filtered Merchandise_Delivery records for the Admin's station.
3. THE PDF report SHALL include a header with: report title ("Deliveries Oversight Report"), station name, date range, generation timestamp, and the name of the Admin who generated it.
4. THE PDF report SHALL include a data table with the following columns: Delivery ID, Ref, DR No., Supplier, Product, Quantity, Unit, Date, Encoded By, Status, Admin, and Notes.
5. THE PDF report SHALL use `@page { size: A4; margin: 18mm; }` CSS so that the browser print dialog defaults to A4 format.
6. THE PDF report SHALL apply the same `delivery_type = 'merchandise'` AND `station_id` scope to the export query as it does to the main table query.
7. THE Oversight_System SHALL trigger `window.print()` automatically when the PDF report page loads so the Admin is immediately prompted to save or print.

---

### Requirement 11: Station Scoping and Access Control

**User Story:** As a system operator, I want all oversight data to be scoped to the Admin's assigned station so that Admins cannot view or act on deliveries from other stations.

#### Acceptance Criteria

1. THE Oversight_System SHALL restrict page and API access to users with the `admin` or `superadmin` role; all other roles SHALL be redirected to `dashboard.php` with an "Access denied" message.
2. THE Oversight_System SHALL scope every `SELECT` query on `deliveries_oversight` with `station_id = ?` using the authenticated Admin's `station_id`.
3. THE Oversight_System SHALL scope every `UPDATE` query on `deliveries_oversight` with `station_id = ?` using the authenticated Admin's `station_id`.
4. IF an Admin submits an Approve or Reject action for a delivery record whose `station_id` does not match the Admin's `station_id`, THEN THE Oversight_System SHALL reject the action and return an error message without modifying any data.

---

### Requirement 12: Audit Trail Completeness

**User Story:** As a compliance officer, I want every Admin action on a delivery record to be fully logged so that there is a complete, tamper-evident audit trail.

#### Acceptance Criteria

1. THE Oversight_System SHALL insert a row into `audit_trail` for every Approve and Reject action, including: `transaction_id` (delivery ID), `manager_id` (Admin's user ID), `action_type`, `old_value` (previous status), `new_value` (new status or "Rejected: [reason]"), `station_id`, and `entity_type = 'delivery'`.
2. THE Oversight_System SHALL call `log_activity()` for every Approve and Reject action with the Admin's user ID, a descriptive action label, and sufficient detail to reconstruct the event (delivery ID, product, quantity, supplier, and reason if applicable).
3. THE Oversight_System SHALL record the Admin's user ID in `deliveries_oversight.admin_id` and the action timestamp in `deliveries_oversight.admin_action_at` for every Approve and Reject action.
4. THE Oversight_System SHALL make audit trail entries visible in the View Details modal for each delivery record, ordered by timestamp descending.

---

### Requirement 13: Data Integrity and Transaction Safety

**User Story:** As a system operator, I want all Admin approval and rejection operations to be atomic so that partial failures never leave a delivery record in an inconsistent state.

#### Acceptance Criteria

1. WHEN the Admin performs an Approve action, THE Oversight_System SHALL wrap the `deliveries_oversight` status update and `audit_trail` insert in a single PDO database transaction.
2. WHEN the Admin performs a Reject action, THE Oversight_System SHALL wrap the `deliveries_oversight` status update and `audit_trail` insert in a single PDO database transaction.
3. IF any step within an Approve or Reject transaction throws an exception, THEN THE Oversight_System SHALL call `$pdo->rollBack()` and return an error message to the Admin without committing any partial changes.
4. THE Oversight_System SHALL validate that the delivery record's `station_id` matches the authenticated Admin's `station_id` before executing any Approve or Reject action.
5. THE Oversight_System SHALL validate that the delivery record exists in the `deliveries_oversight` table before executing any Approve or Reject action; IF the record is not found, THEN THE Oversight_System SHALL return the message "Delivery not found."
