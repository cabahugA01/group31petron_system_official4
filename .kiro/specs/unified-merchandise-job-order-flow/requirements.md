# Requirements Document

## Introduction

The Unified Merchandise + Job Order Flow feature consolidates three previously separate transaction types (merchandise-only sales, job order services-only, and combination transactions) into a single streamlined workflow. This unified system enables managers to encode any transaction type through one form, route all transactions through one validation queue, and generate one unified receipt regardless of whether the customer purchases merchandise, requests services, or both.

This feature enhances operational efficiency by eliminating duplicate encoding processes, provides flexibility for customers to combine products and services in one transaction, and ensures consistent validation and receipt generation across all transaction types.

## Glossary

- **Transaction_Form**: The unified web interface where managers encode merchandise sales, job order services, or combined transactions
- **Validation_Queue**: The centralized queue containing all pending transactions (fuel, merchandise, job orders) awaiting manager approval
- **Unified_Receipt**: A single receipt document that contains both merchandise items and job order services
- **Merchandise_Transaction**: A sale of physical products or parts from station inventory
- **Job_Order**: A service request that may include labor charges and optional merchandise parts
- **Combination_Transaction**: A single transaction containing both merchandise sales and job order services
- **Inventory_System**: The system managing station_inventory table and stock levels
- **Payment_Method**: The method used for payment (Cash, Card, E-Wallet, E-Fuel Card, Credit/Utang)
- **Manager_Dashboard**: The manager's web interface displaying validation queues, sales metrics, and transaction monitoring
- **Admin**: The administrative role that receives validated transactions and oversees station operations
- **Customer_Balance**: The accounts receivable record for customers with credit/utang transactions
- **Audit_Trail**: System logs recording all transaction encoding, validation, approval, and modification activities
- **Variance_Alert**: A notification triggered when transaction anomalies are detected
- **Sales_Today_Card**: Dashboard widget displaying combined revenue from fuel, merchandise, and job orders
- **Validated_Records_Chart**: Dashboard widget showing approved vs pending transaction counts

## Requirements

### Requirement 1: Unified Transaction Form

**User Story:** As a manager, I want to encode merchandise, job orders, or both in a single form, so that I can efficiently handle all customer transaction types without switching between different interfaces.

#### Acceptance Criteria

1. THE Transaction_Form SHALL display fields for merchandise items, job order services, and customer information in one unified interface
2. WHEN a manager selects merchandise items, THE Transaction_Form SHALL retrieve current prices and stock levels from the Inventory_System
3. WHEN a manager selects job order services, THE Transaction_Form SHALL display available service types and allow entry of service details
4. THE Transaction_Form SHALL allow managers to add both merchandise items and job order services within the same transaction
5. WHEN a manager adds merchandise to a transaction, THE Transaction_Form SHALL validate that sufficient stock exists in station_inventory
6. THE Transaction_Form SHALL support all payment methods (Cash, Card, E-Wallet, E-Fuel Card, Credit/Utang) for any transaction type
7. WHEN a manager selects Credit/Utang payment, THE Transaction_Form SHALL associate the transaction with the customer's balance record
8. THE Transaction_Form SHALL calculate the total amount including merchandise subtotal and job order service charges
9. WHEN a manager submits the form, THE Transaction_Form SHALL create a pending transaction record and add it to the Validation_Queue

### Requirement 2: Transaction Type Handling

**User Story:** As a manager, I want the system to correctly process merchandise-only, service-only, and combination transactions, so that all transaction types are handled consistently through the unified flow.

#### Acceptance Criteria

1. WHEN a transaction contains only merchandise items, THE Transaction_Form SHALL create a merchandise_transaction record with service fields null
2. WHEN a transaction contains only job order services, THE Transaction_Form SHALL create a job_orders record with merchandise fields empty
3. WHEN a transaction contains both merchandise and services, THE Transaction_Form SHALL create linked records in both merchandise_transactions and job_orders tables
4. FOR ALL combination transactions, THE Transaction_Form SHALL maintain referential integrity between merchandise_transactions and job_orders through a common transaction_id
5. THE Transaction_Form SHALL preserve all transaction metadata (timestamp, manager_id, customer_id, payment_method) regardless of transaction type

### Requirement 3: Validation Queue Integration

**User Story:** As a manager, I want all three transaction types to appear in one validation queue, so that I can review and approve all pending transactions in a single workflow.

#### Acceptance Criteria

1. WHEN a transaction is submitted, THE Validation_Queue SHALL display the transaction with its type indicator (Merchandise, Job Order, or Combination)
2. THE Validation_Queue SHALL display transaction details including items/services, total amount, payment method, and customer information
3. WHEN a manager reviews a transaction, THE Validation_Queue SHALL provide options to approve, reject, or request modifications
4. WHEN a manager approves a transaction, THE Validation_Queue SHALL update the transaction status to validated and forward it to Admin
5. WHEN a manager rejects a transaction, THE Validation_Queue SHALL record the rejection reason in the Audit_Trail and remove it from the queue
6. THE Validation_Queue SHALL sort transactions by submission timestamp with oldest transactions first
7. WHEN a combination transaction is approved, THE Validation_Queue SHALL ensure both merchandise and job order components are validated together

### Requirement 4: Unified Receipt Generation

**User Story:** As a manager, I want to generate one receipt for transactions containing merchandise, services, or both, so that customers receive a single comprehensive document.

#### Acceptance Criteria

1. WHEN a transaction is validated, THE Unified_Receipt SHALL generate a receipt document containing all transaction items
2. THE Unified_Receipt SHALL display merchandise items with SKU, description, quantity, unit price, and subtotal
3. THE Unified_Receipt SHALL display job order services with service name, description, labor charges, and parts used
4. WHEN a combination transaction is processed, THE Unified_Receipt SHALL separate merchandise and service sections while showing one grand total
5. THE Unified_Receipt SHALL display customer information, transaction date, payment method, and transaction ID
6. THE Unified_Receipt SHALL display manager name and station information
7. WHEN payment method is Credit/Utang, THE Unified_Receipt SHALL display the outstanding balance after the transaction
8. THE Unified_Receipt SHALL be printable and stored for audit purposes

### Requirement 5: Automatic Inventory Updates

**User Story:** As a manager, I want merchandise quantities to be automatically deducted from inventory when job orders use parts, so that inventory levels remain accurate without manual adjustments.

#### Acceptance Criteria

1. WHEN a job order includes merchandise parts, THE Inventory_System SHALL deduct the parts quantity from station_inventory upon validation
2. WHEN a merchandise-only transaction is validated, THE Inventory_System SHALL deduct the sold quantities from station_inventory
3. WHEN a combination transaction is validated, THE Inventory_System SHALL deduct both direct merchandise sales and parts used in services
4. IF inventory deduction would result in negative stock, THEN THE Inventory_System SHALL reject the transaction and return an error message
5. THE Inventory_System SHALL record all inventory changes in inventory_history with transaction_id reference
6. WHEN a transaction is rejected after inventory deduction, THE Inventory_System SHALL restore the deducted quantities to station_inventory

### Requirement 6: Dashboard Integration - Sales Today Card

**User Story:** As a manager, I want the Sales Today card to show combined revenue from fuel, merchandise, and job orders, so that I can monitor total daily sales at a glance.

#### Acceptance Criteria

1. THE Sales_Today_Card SHALL calculate total revenue from validated fuel transactions, merchandise transactions, and job orders for the current day
2. THE Sales_Today_Card SHALL update in real-time when new transactions are validated
3. THE Sales_Today_Card SHALL display the combined total amount with currency formatting
4. THE Sales_Today_Card SHALL provide a breakdown showing fuel revenue, merchandise revenue, and job order revenue separately
5. THE Sales_Today_Card SHALL exclude rejected and pending transactions from the total

### Requirement 7: Dashboard Integration - Validation Queue Chart

**User Story:** As a manager, I want the validation queue chart to show pending transactions categorized by type, so that I can see at a glance which transaction types need attention.

#### Acceptance Criteria

1. THE Validated_Records_Chart SHALL display a pie chart with three segments: Fuel, Merchandise, and Job Orders
2. THE Validated_Records_Chart SHALL include combination transactions in the Job Orders segment
3. THE Validated_Records_Chart SHALL update when transactions are added to or removed from the Validation_Queue
4. WHEN a manager clicks on a chart segment, THE Validated_Records_Chart SHALL filter the Validation_Queue to show only that transaction type
5. THE Validated_Records_Chart SHALL display the count of pending transactions for each type

### Requirement 8: Dashboard Integration - Validated Records Tracking

**User Story:** As a manager, I want to see a bar chart comparing approved vs pending transactions, so that I can monitor validation workflow progress.

#### Acceptance Criteria

1. THE Validated_Records_Chart SHALL display a bar chart with two bars: Approved and Pending transaction counts
2. THE Validated_Records_Chart SHALL include all transaction types (fuel, merchandise, job orders) in the counts
3. THE Validated_Records_Chart SHALL update in real-time when transactions change status
4. THE Validated_Records_Chart SHALL show counts for the current day by default
5. THE Validated_Records_Chart SHALL allow filtering by date range

### Requirement 9: Customer Balance Management

**User Story:** As a manager, I want customer balances to automatically update for credit transactions, so that accounts receivable remain accurate across all transaction types.

#### Acceptance Criteria

1. WHEN a transaction uses Credit/Utang payment method, THE Customer_Balance SHALL increase by the transaction total amount
2. THE Customer_Balance SHALL track balances separately for fuel, merchandise, and job order transactions
3. WHEN a manager views customer information, THE Customer_Balance SHALL display total outstanding balance and transaction history
4. THE Customer_Balance SHALL update immediately upon transaction validation
5. WHEN a customer makes a payment, THE Customer_Balance SHALL decrease by the payment amount and record the payment transaction
6. THE Customer_Balance SHALL display individual unpaid transactions with dates, types, and amounts

### Requirement 10: Variance Detection and Alerts

**User Story:** As a manager, I want the system to detect transaction anomalies, so that I can investigate unusual patterns or potential errors.

#### Acceptance Criteria

1. WHEN a transaction total exceeds a configurable threshold, THE Variance_Alert SHALL flag the transaction for review
2. WHEN a merchandise item is sold at a price different from the current price in the system, THE Variance_Alert SHALL flag the transaction
3. WHEN inventory deduction fails due to insufficient stock, THE Variance_Alert SHALL generate an alert with item details
4. THE Variance_Alert SHALL display flagged transactions prominently in the Manager_Dashboard
5. WHEN a manager reviews a flagged transaction, THE Variance_Alert SHALL display the specific anomaly detected and allow the manager to approve or reject
6. THE Variance_Alert SHALL log all anomaly detections in the Audit_Trail

### Requirement 11: Audit Trail and Transaction Logging

**User Story:** As a manager, I want all transaction activities to be logged, so that there is a complete audit trail for compliance and troubleshooting.

#### Acceptance Criteria

1. WHEN a transaction is created, THE Audit_Trail SHALL log the transaction details, manager_id, timestamp, and transaction type
2. WHEN a transaction is validated, THE Audit_Trail SHALL log the validation action, approving manager_id, and timestamp
3. WHEN a transaction is rejected, THE Audit_Trail SHALL log the rejection reason, manager_id, and timestamp
4. WHEN a transaction is modified, THE Audit_Trail SHALL log the original values, new values, and modifying manager_id
5. WHEN inventory is updated, THE Audit_Trail SHALL log the inventory changes with transaction_id reference
6. THE Audit_Trail SHALL be immutable and prevent deletion or modification of log entries
7. WHEN Admin views transaction logs, THE Audit_Trail SHALL provide filtering by date, transaction type, manager, and action type

### Requirement 12: Job Order Service Details

**User Story:** As a manager, I want to capture detailed service information in job orders, so that service records are complete and traceable.

#### Acceptance Criteria

1. WHEN a manager creates a job order, THE Transaction_Form SHALL require entry of service type, description, and labor charges
2. THE Transaction_Form SHALL allow managers to add multiple services to one job order
3. WHEN a manager adds parts to a job order, THE Transaction_Form SHALL link the parts to the specific service requiring them
4. THE Transaction_Form SHALL allow entry of vehicle information (make, model, plate number) for automotive job orders
5. THE Transaction_Form SHALL allow entry of estimated completion time for job orders
6. WHEN a job order is saved, THE Transaction_Form SHALL assign a unique job order number for tracking

### Requirement 13: Transaction Modification Before Validation

**User Story:** As a manager, I want to modify pending transactions before validation, so that I can correct errors without rejecting and re-creating transactions.

#### Acceptance Criteria

1. WHEN a manager views a pending transaction in the Validation_Queue, THE Validation_Queue SHALL provide an edit option
2. WHEN a manager edits a pending transaction, THE Transaction_Form SHALL load the transaction data for modification
3. THE Transaction_Form SHALL allow modification of quantities, services, and payment details for pending transactions
4. WHEN a manager saves modifications, THE Audit_Trail SHALL log the changes with before and after values
5. THE Transaction_Form SHALL prevent modification of validated transactions
6. WHEN a transaction is modified, THE Validation_Queue SHALL update the timestamp to reflect the last modification time

### Requirement 14: Payment Method Validation

**User Story:** As a manager, I want the system to validate payment details, so that payment records are accurate and complete.

#### Acceptance Criteria

1. WHEN payment method is Card, THE Transaction_Form SHALL require entry of card type and last 4 digits
2. WHEN payment method is E-Wallet, THE Transaction_Form SHALL require entry of e-wallet provider and transaction reference
3. WHEN payment method is E-Fuel Card, THE Transaction_Form SHALL require entry of card number and validate against registered cards
4. WHEN payment method is Credit/Utang, THE Transaction_Form SHALL require customer selection and verify customer is eligible for credit
5. THE Transaction_Form SHALL validate that the payment amount matches the transaction total
6. WHEN multiple payment methods are used, THE Transaction_Form SHALL allow split payment entry and validate that split amounts sum to transaction total

### Requirement 15: Integration with Existing Systems

**User Story:** As a developer, I want the unified flow to integrate with existing database tables, so that the system maintains data consistency with fuel transactions and other modules.

#### Acceptance Criteria

1. THE Transaction_Form SHALL write merchandise records to the existing merchandise_transactions table with all required fields populated
2. THE Transaction_Form SHALL write job order records to the existing job_orders table with all required fields populated
3. THE Inventory_System SHALL update the existing station_inventory table using the same mechanism as fuel transactions
4. THE Validation_Queue SHALL use the same validation status codes as existing fuel validation workflows
5. THE Unified_Receipt SHALL use the same receipt template structure as existing fuel and merchandise receipts
6. THE Audit_Trail SHALL write to the same audit log tables used by other system modules
7. THE Customer_Balance SHALL update the existing customers table with balance information

## Notes

**Critical Integration Points:**
- The unified flow must maintain backward compatibility with existing fuel transaction workflows
- Database schema changes should be additive (new fields/tables) rather than modifying existing structures
- Existing fuel validation queue must coexist with the unified validation queue during transition period

**Performance Considerations:**
- Transaction form should load inventory data efficiently to prevent delays when adding multiple items
- Dashboard widgets should use cached data with periodic refresh to avoid performance impact from real-time queries
- Validation queue should paginate results when transaction volume is high

**Security Requirements:**
- Only managers with appropriate permissions should access the unified transaction form
- Audit trail logs must be tamper-proof and restricted to admin view-only access
- Customer payment information must be stored securely with sensitive data encrypted

**Future Enhancements:**
- Mobile-responsive transaction form for tablet-based encoding
- Barcode scanning for merchandise item selection
- Service package templates for common job order combinations
- Customer notification system for job order completion
