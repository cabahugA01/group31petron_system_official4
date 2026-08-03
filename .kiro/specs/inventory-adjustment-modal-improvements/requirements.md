# Requirements Document

## Introduction

This document specifies the requirements for improving the user interface of the "Approve Inventory Adjustment" modal in the Merchandise Inventory section of the Petron Station Management System. The improvements focus on enhancing usability by removing the close (X) button from the modal header to prevent accidental dismissal, and ensuring the cancel button is clearly visible with proper text labeling. These changes will create a more deliberate user interaction pattern where users must explicitly choose to approve or cancel the adjustment action.

## Glossary

- **Approve_Adjustment_Modal**: The modal dialog (id="approveAdjModal") displayed when a manager approves a merchandise inventory adjustment request
- **Close_Button**: The X icon button located in the modal header that dismisses the modal when clicked
- **Cancel_Button**: The button in the modal footer that allows users to dismiss the modal without approving the adjustment
- **Modal_Header**: The green gradient header section of the Approve_Adjustment_Modal containing the title and close button
- **Modal_Footer**: The bottom section of the Approve_Adjustment_Modal containing action buttons (Cancel and Confirm Approve)
- **Manager_Role**: A user role with permissions to approve or reject inventory adjustment requests
- **Merchandise_Inventory_Page**: The page (manager_inventory_merchandise.php) displaying merchandise inventory with adjustment approval functionality

## Requirements

### Requirement 1: Remove Close Button from Modal Header

**User Story:** As a manager, I want the X close button removed from the modal header, so that I cannot accidentally dismiss the modal without making an explicit approval or cancellation decision.

#### Acceptance Criteria

1. THE Approve_Adjustment_Modal SHALL NOT display the Close_Button (X icon) in the Modal_Header
2. WHEN the Approve_Adjustment_Modal is displayed, THE Modal_Header SHALL contain only the title text and icon
3. THE Modal_Header SHALL maintain its visual styling (green gradient background, white text) after the Close_Button is removed
4. THE Modal_Header SHALL maintain proper spacing and alignment with only the title element present

### Requirement 2: Ensure Cancel Button Visibility and Text

**User Story:** As a manager, I want the cancel button to be clearly visible with descriptive text, so that I can easily identify and use it to dismiss the modal without approving the adjustment.

#### Acceptance Criteria

1. THE Cancel_Button SHALL display the text "Cancel" in visible font
2. THE Cancel_Button SHALL be positioned in the Modal_Footer alongside the Confirm Approve button
3. THE Cancel_Button SHALL have a distinct visual style (border, lighter background) to differentiate it from the Confirm Approve button
4. WHEN the Approve_Adjustment_Modal is displayed, THE Cancel_Button SHALL be immediately visible without scrolling

### Requirement 3: Maintain Modal Dismissal Functionality

**User Story:** As a manager, I want to be able to dismiss the modal using the cancel button, so that I can exit the approval workflow without approving the adjustment.

#### Acceptance Criteria

1. WHEN a manager clicks the Cancel_Button, THE Approve_Adjustment_Modal SHALL close and return to the Merchandise_Inventory_Page
2. WHEN the Approve_Adjustment_Modal is closed via the Cancel_Button, THE system SHALL NOT process any approval action
3. THE Cancel_Button SHALL call the closeApproveAdjModal() JavaScript function when clicked
4. WHEN the modal is dismissed via Cancel_Button, THE form data SHALL be reset to its initial state

### Requirement 4: Preserve Approval Workflow

**User Story:** As a manager, I want the approval workflow to remain unchanged, so that I can continue to approve inventory adjustments with the same process.

#### Acceptance Criteria

1. WHEN a manager clicks the "Confirm Approve" button, THE system SHALL submit the approval form with action='approve_merchandise_adjustment'
2. THE Approve_Adjustment_Modal SHALL continue to display product information (name, adjustment details) in the form body
3. THE Approve_Adjustment_Modal SHALL maintain the current stock level update behavior after approval confirmation
4. THE system SHALL display the success message after successful approval as it currently does

### Requirement 5: Maintain Modal Accessibility

**User Story:** As a user interface designer, I want the modal to remain accessible via keyboard navigation, so that users can interact with it using keyboard controls.

#### Acceptance Criteria

1. WHEN the Approve_Adjustment_Modal is open, THE Cancel_Button SHALL be accessible via keyboard tab navigation
2. WHEN the Approve_Adjustment_Modal is open, THE Confirm Approve button SHALL be accessible via keyboard tab navigation
3. WHEN a user presses the Escape key while the modal is open, THE modal SHALL close (calling closeApproveAdjModal())
4. THE keyboard focus order SHALL be: Cancel_Button → Confirm Approve button

### Requirement 6: Maintain Consistent Modal Styling

**User Story:** As a user interface designer, I want the modal styling to remain consistent with the existing design system, so that the user experience is cohesive across the application.

#### Acceptance Criteria

1. THE Modal_Header SHALL maintain the green gradient background (linear-gradient(135deg,#16a34a,#15803d))
2. THE Cancel_Button SHALL maintain the existing button styling (light background, border, gray text)
3. THE Confirm Approve button SHALL maintain the existing styling (green background, white text, check icon)
4. THE modal SHALL maintain its current width (max-width:440px), border-radius, and shadow properties

### Requirement 7: Prevent Unintended Modal Dismissal

**User Story:** As a system administrator, I want users to be unable to dismiss the modal by clicking outside of it, so that approval decisions are made deliberately through the provided buttons.

#### Acceptance Criteria

1. WHEN a user clicks on the modal overlay (background) outside the modal content, THE Approve_Adjustment_Modal SHALL remain open
2. THE modal SHALL only be dismissed through the Cancel_Button or by completing the approval action
3. IF the modal overlay click handler exists, THEN THE handler SHALL prevent modal dismissal on overlay clicks
4. THE modal behavior SHALL require explicit user action (Cancel or Confirm) to dismiss

