# Requirements Document

## Introduction

This feature removes the redundant "Batches" button from the Product & Pricing Management interface. The button previously provided access to batch history for products, but this functionality is now available through the View modal, making the separate button unnecessary and cluttering the interface.

## Glossary

- **Product_Pricing_Interface**: The manager interface located in `public/manager_set_prices.php` that displays a table of merchandise products with action buttons
- **Batches_Button**: The action button labeled "Batches" with a layer-group icon that appears in the ACTIONS column for each product row
- **View_Modal**: The merchandise specification modal (`viewMerchModal`) that displays comprehensive product details including batch history
- **Action_Column**: The table column containing action buttons (View, Edit, Deactivate/Activate, Batches) for each product row

## Requirements

### Requirement 1: Remove Batches Button from Interface

**User Story:** As a manager, I want the redundant Batches button removed from the Product & Pricing Management table, so that the interface is cleaner and I only use the View modal to access batch history.

#### Acceptance Criteria

1. THE Product_Pricing_Interface SHALL NOT display the Batches_Button in the Action_Column for any product row
2. WHEN the Product_Pricing_Interface renders merchandise products, THE Product_Pricing_Interface SHALL display only View, Edit, and Deactivate/Activate buttons in the Action_Column
3. THE View_Modal SHALL continue to display batch history when accessed through the View button
4. THE Product_Pricing_Interface SHALL maintain all existing functionality except the removed Batches_Button
5. THE Product_Pricing_Interface SHALL maintain consistent spacing and layout in the Action_Column after button removal

### Requirement 2: Remove Supporting JavaScript Function

**User Story:** As a developer, I want the unused `viewProductBatches` function removed, so that the codebase remains clean and maintainable.

#### Acceptance Criteria

1. THE Product_Pricing_Interface SHALL NOT contain the `viewProductBatches` JavaScript function
2. THE Product_Pricing_Interface SHALL NOT contain the `viewBatchesModal` modal element
3. THE Product_Pricing_Interface SHALL NOT contain any references to batch-specific modal functionality that is not part of the View_Modal
4. THE Product_Pricing_Interface SHALL maintain all other modal functionality (View, Edit, Add, etc.)

### Requirement 3: Preserve View Modal Functionality

**User Story:** As a manager, I want to continue accessing batch history through the View modal, so that I can see all product details including batches in one place.

#### Acceptance Criteria

1. WHEN the View button is clicked for a product, THE View_Modal SHALL display complete product specifications
2. THE View_Modal SHALL display a Batches tab showing batch number, remaining quantity, expiration date, and status
3. THE View_Modal SHALL load batch data from the `get_merchandise_details` API endpoint
4. THE View_Modal SHALL display "No batch records" when a product has no associated batches
