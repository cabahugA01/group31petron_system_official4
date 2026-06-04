# Requirements Document: Transaction Module Modal Forms

## Introduction

This specification defines standardized modal forms across the Transaction Module for Staff, Manager, and Admin roles. All modals will share a unified design system with consistent colors, spacing, typography, and interaction patterns to provide a cohesive user experience.

**Key Principle:** ONE unified design system - same colors, same styles, same behavior across all roles.

## Glossary

- **Modal**: A pop-up dialog overlay that displays transaction forms or details
- **Staff_Merchandise_Modal**: Modal for staff to encode merchandise transactions
- **Staff_JobOrder_Modal**: Modal for staff to encode job order (service) transactions
- **Manager_Validation_Modal**: Modal for manager to review and approve/reject/adjust transactions
- **Manager_Adjust_Modal**: Modal for manager to adjust transaction amounts with reason
- **Manager_Reject_Modal**: Modal for manager to reject transactions with required reason
- **Admin_Oversight_Modal**: Modal for admin to view validated transaction details with audit trail
- **Uniform_Design**: Consistent visual styling (colors, fonts, spacing) across all modals
- **Back_Button**: Navigation control that returns user to the previous list/table view

## Requirements

### Requirement 1: Unified Modal Design System

**User Story:** As a system user across all roles, I want all modal forms to have the same visual design, so that the interface feels consistent and professional.

#### Acceptance Criteria

1. THE System SHALL use a single modal CSS class `.tm-modal` for all transaction modals
2. ALL modals SHALL use the color scheme:
   - Primary Blue: `#002F70` (headers, primary buttons)
   - White: `#FFFFFF` (modal background, text on blue)
   - Light Gray: `#F8FAFC` (section backgrounds)
   - Border Gray: `#E2E8F0` (dividers, borders)
   - Text Dark: `#1E293B` (body text)
   - Text Muted: `#64748B` (labels, secondary text)
   - Success Green: `#059669` (approve buttons)
   - Danger Red: `#DC2626` (reject buttons)
   - Warning Yellow: `#F59E0B` (adjust buttons)
3. ALL modal headers SHALL have:
   - Background color: `#002F70`
   - Text color: `#FFFFFF`
   - Font size: `18px`
   - Font weight: `700` (bold)
   - Padding: `20px 24px`
4. ALL modal bodies SHALL have:
   - Background color: `#FFFFFF`
   - Padding: `24px`
   - Max width: `800px` for standard forms, `1000px` for wide modals
5. ALL form labels SHALL have:
   - Font size: `13px`
   - Font weight: `600`
   - Color: `#475569`
   - Margin bottom: `6px`
6. ALL input fields SHALL have:
   - Border: `1px solid #CBD5E1`
   - Border radius: `6px`
   - Padding: `8px 12px`
   - Font size: `14px`
   - Focus state: blue border `#002F70` with subtle shadow
7. ALL buttons SHALL follow consistent sizing and styling with icons from Font Awesome
8. THE System SHALL use consistent modal overlay with 50% opacity black background

### Requirement 2: Staff Merchandise Transaction Modal

**User Story:** As a staff member, I want to encode merchandise transactions using a clean modal form, so that I can quickly record sales with all required payment details.

#### Acceptance Criteria

1. THE Staff_Merchandise_Modal SHALL contain sections:
   - Customer Details
   - Product Selection
   - Payment Information
2. THE Customer Details section SHALL include fields:
   - First Name (required, text input)
   - Last Name (required, text input)
   - Contact Number (optional, text input with phone format)
3. THE Product Selection section SHALL include:
   - Product dropdown (searchable, populated from inventory)
   - Quantity input (number, min: 1, with stock validation)
   - Unit Price (auto-filled from product, read-only display)
   - Category (auto-filled from product, read-only badge)
   - Stock Available (auto-display, updates on product selection)
   - Add Item button to include multiple products
   - Item list table showing added products with remove option
4. THE Payment Information section SHALL include:
   - Payment Method dropdown (Cash, Card, E-Wallet, E-Fuel Card, Credit/Utang)
   - Payment Status radio buttons (Paid, Partial, Utang)
   - Amount Paid input (required if status is Paid or Partial)
   - Total Amount display (auto-calculated from products)
5. WHEN Payment Status is Partial, THE System SHALL display:
   - Amount Paid input
   - Balance Due (auto-calculated: Total - Amount Paid)
6. WHEN Payment Status is Utang, THE System SHALL:
   - Set Amount Paid to ₱0
   - Display full Total as Balance Due
   - Require customer contact information
7. THE Staff_Merchandise_Modal SHALL have action buttons:
   - Submit Transaction (primary blue button, icon: fas fa-check)
   - Back (secondary gray button, icon: fas fa-arrow-left, returns to Job Order Tracker list)
8. THE System SHALL validate all required fields before submission
9. WHEN submission succeeds, THE System SHALL close modal, refresh transaction list, and show success message
10. WHEN user clicks Back, THE System SHALL close modal without saving and return to Job Order Tracker list

### Requirement 3: Staff Job Order Transaction Modal

**User Story:** As a staff member, I want to encode job order (service) transactions with vehicle and mechanic details, so that I can properly track service work with payment information.

#### Acceptance Criteria

1. THE Staff_JobOrder_Modal SHALL contain sections:
   - Customer Details
   - Vehicle Information
   - Service Details
   - Payment Information
2. THE Customer Details section SHALL include fields:
   - First Name (required, text input)
   - Last Name (required, text input)
   - Contact Number (optional, text input with phone format)
3. THE Vehicle Information section SHALL include:
   - Vehicle Type dropdown (Sedan, SUV, Truck, Motorcycle, Van)
   - Plate Number (required, text input with uppercase format)
4. THE Service Details section SHALL include:
   - Service Type dropdown (populated from service_fees table: Change Oil, Tire Rotation, Brake Service, etc.)
   - Service Fee (auto-filled from service type, editable number input)
   - Assigned Mechanic dropdown (populated from users with mechanic role)
   - Notes/Remarks (optional, textarea for additional information)
5. THE Payment Information section SHALL include:
   - Payment Method dropdown (Cash, Card, E-Wallet, E-Fuel Card, Credit/Utang)
   - Payment Status radio buttons (Paid, Partial, Utang)
   - Downpayment input (visible when Partial selected)
   - Balance Due display (auto-calculated: Service Fee - Downpayment)
   - Total Amount display (equals Service Fee)
6. WHEN Payment Status is Partial, THE System SHALL:
   - Show Downpayment input field
   - Calculate and display Balance (Service Fee - Downpayment)
   - Validate that Downpayment < Service Fee
7. WHEN Payment Status is Utang, THE System SHALL:
   - Set Downpayment to ₱0
   - Display full Service Fee as Balance Due
   - Require customer contact information
8. THE Staff_JobOrder_Modal SHALL have action buttons:
   - Submit Job Order (primary blue button, icon: fas fa-check)
   - Back (secondary gray button, icon: fas fa-arrow-left, returns to Job Order Tracker table)
9. THE System SHALL validate all required fields before submission
10. WHEN submission succeeds, THE System SHALL close modal, refresh Job Order Tracker table, and show success message
11. WHEN user clicks Back, THE System SHALL close modal without saving and return to Job Order Tracker table

### Requirement 4: Manager Validation Modal

**User Story:** As a manager, I want to review pending transactions in a clear modal with all details visible, so that I can approve, adjust, or reject them with proper documentation.

#### Acceptance Criteria

1. THE Manager_Validation_Modal SHALL contain sections:
   - Transaction Summary
   - Customer & Staff Information
   - Transaction Details
   - Payment Information
   - Manager Actions
2. THE Transaction Summary section SHALL display:
   - Transaction ID (large, prominent display)
   - Transaction Type badge (Merchandise / Job Order / JO + Merchandise)
   - Transaction Date and Time
   - Current Status badge (Pending Validation)
3. THE Customer & Staff Information section SHALL display:
   - Customer Name (full name)
   - Customer Contact (if available)
   - Staff Encoder Name
   - Encoding Date/Time
4. THE Transaction Details section SHALL display:
   - FOR Merchandise: Product list table (Product Name, Quantity, Unit Price, Subtotal)
   - FOR Job Order: Service Type, Vehicle Plate, Assigned Mechanic, Service Fee
   - FOR JO + Merchandise: Both service and product tables
   - Total Amount (prominent display)
5. THE Payment Information section SHALL display:
   - Payment Method badge
   - Payment Status badge (Paid / Partial / Utang)
   - Amount Paid (if applicable)
   - Balance Due (if Partial or Utang)
6. THE Manager Actions section SHALL provide:
   - Approve button (green, icon: fas fa-check-circle, action: approve transaction)
   - Adjust button (yellow, icon: fas fa-edit, action: open Adjust Modal)
   - Reject button (red, icon: fas fa-times-circle, action: open Reject Modal)
   - Remarks textarea (required for Reject, optional for Approve/Adjust)
7. WHEN manager clicks Approve, THE System SHALL:
   - Validate remarks if required
   - Update transaction validation_status to 'Approved'
   - Record validated_by and validated_at
   - Insert audit trail entry
   - Close modal and refresh Pending Transactions list
   - Show success notification
8. WHEN manager clicks Adjust, THE System SHALL:
   - Close Validation Modal
   - Open Adjust Modal with transaction details pre-filled
9. WHEN manager clicks Reject, THE System SHALL:
   - Close Validation Modal
   - Open Reject Modal with transaction details pre-filled
10. THE Manager_Validation_Modal SHALL have action buttons:
    - Close (top-right X button, closes modal)
    - Back (bottom-left button, icon: fas fa-arrow-left, returns to Pending Transactions list)

### Requirement 5: Manager Adjust Modal

**User Story:** As a manager, I want to adjust transaction amounts when there are pricing errors, so that I can correct mistakes without rejecting the entire transaction.

#### Acceptance Criteria

1. THE Manager_Adjust_Modal SHALL contain sections:
   - Transaction Summary (compact)
   - Adjustment Details
   - Transaction Preview
2. THE Transaction Summary section SHALL display:
   - Transaction ID
   - Customer Name
   - Staff Encoder
   - Transaction Type badge
3. THE Adjustment Details section SHALL include:
   - Old Total Amount (read-only, prominent display with label "Original Amount")
   - New Total Amount (required, number input, min: 0, label "Adjusted Amount")
   - Difference display (auto-calculated: New - Old, color-coded red/green)
   - Adjustment Reason (required, textarea, placeholder: "Explain reason for adjustment...")
4. THE Transaction Preview section SHALL display:
   - Original transaction items/services
   - Original payment method and status
   - Note indicating this will update total_amount field
5. THE Manager_Adjust_Modal SHALL validate:
   - New Total Amount must be greater than 0
   - New Total Amount must be different from Old Total Amount
   - Adjustment Reason must not be empty (minimum 10 characters)
6. WHEN manager submits adjustment, THE System SHALL:
   - Update transaction total_amount to New Total Amount
   - Set validation_status to 'Adjusted'
   - Record validated_by and validated_at
   - Store Adjustment Reason in remarks field (prefixed with "ADJUSTED: ")
   - Insert audit trail entry with action_type 'Adjust' and new_value
   - Close modal and refresh Pending Transactions list
   - Show success notification with adjusted amount
7. THE Manager_Adjust_Modal SHALL have action buttons:
   - Confirm Adjustment (primary yellow button, icon: fas fa-check)
   - Cancel (secondary gray button, icon: fas fa-times)
   - Back (bottom-left button, icon: fas fa-arrow-left, returns to Validation queue)
8. WHEN user clicks Cancel or Back, THE System SHALL close Adjust Modal without saving and return to Validation queue

### Requirement 6: Manager Reject Modal

**User Story:** As a manager, I want to reject incorrect transactions with a clear reason, so that staff can understand what needs to be corrected.

#### Acceptance Criteria

1. THE Manager_Reject_Modal SHALL contain sections:
   - Transaction Summary (compact)
   - Rejection Details
   - Transaction Preview
2. THE Transaction Summary section SHALL display:
   - Transaction ID (prominent)
   - Customer Name
   - Staff Encoder
   - Transaction Type badge
   - Transaction Date
3. THE Rejection Details section SHALL include:
   - Rejection Reason (required, textarea, minimum 20 characters)
   - Reason placeholder: "Provide detailed reason for rejection so staff can make corrections..."
   - Character counter (e.g., "0 / 20 minimum")
4. THE Transaction Preview section SHALL display:
   - Transaction items/services summary
   - Total amount
   - Payment method and status
   - Note: "This transaction will be returned to staff for correction"
5. THE Manager_Reject_Modal SHALL validate:
   - Rejection Reason must not be empty
   - Rejection Reason must be at least 20 characters
6. WHEN manager submits rejection, THE System SHALL:
   - Update transaction validation_status to 'Rejected'
   - Record validated_by and validated_at
   - Store Rejection Reason in rejection_reason field OR remarks field (prefixed with "REJECTED: ")
   - Insert audit trail entry with action_type 'Reject' and reason in new_value
   - Close modal and refresh Pending Transactions list
   - Show notification: "Transaction rejected and returned to staff"
7. THE Manager_Reject_Modal SHALL have action buttons:
   - Confirm Rejection (primary red button, icon: fas fa-ban)
   - Cancel (secondary gray button, icon: fas fa-times)
   - Back (bottom-left button, icon: fas fa-arrow-left, returns to Pending Transactions list)
8. WHEN user clicks Cancel or Back, THE System SHALL close Reject Modal without saving and return to Pending Transactions list

### Requirement 7: Admin Oversight Modal

**User Story:** As an admin, I want to view complete validated transaction details with audit trail, so that I can oversee the entire transaction lifecycle and export records for compliance.

#### Acceptance Criteria

1. THE Admin_Oversight_Modal SHALL contain sections:
   - Transaction Header
   - Customer & Transaction Details
   - Payment Information
   - Validation Information
   - Audit Trail
   - Variance Report (if flagged)
   - Export Options
2. THE Transaction Header section SHALL display:
   - Transaction ID (large, prominent)
   - Transaction Type badge (Merchandise / Job Order / JO + Merchandise)
   - Validation Status badge (Approved / Completed / Adjusted)
   - Transaction Date and Time
3. THE Customer & Transaction Details section SHALL display:
   - Customer Name
   - Customer Contact (if available)
   - FOR Merchandise: Product list table with quantities and prices
   - FOR Job Order: Service type, vehicle plate, mechanic, service fee
   - FOR JO + Merchandise: Both product and service details
   - Total Amount (prominent display)
4. THE Payment Information section SHALL display:
   - Payment Method badge
   - Payment Status badge (Paid / Partial / Unpaid)
   - Amount Paid
   - Balance Due (if applicable)
5. THE Validation Information section SHALL display:
   - Staff Encoder Name with encoding timestamp
   - Manager Validator Name with validation timestamp
   - Manager Action (Approved / Adjusted / Rejected)
   - Manager Remarks (if any)
6. THE Audit Trail section SHALL display:
   - Timeline view with:
     - Staff encoding event (user, timestamp, action: "Transaction Encoded")
     - Manager validation event (user, timestamp, action: "Approved" / "Adjusted" / "Rejected")
     - Admin viewing event (current user, current timestamp, action: "Viewed")
   - Each event shows user name, role badge, timestamp, and action description
7. THE Variance Report section SHALL display ONLY IF transaction is flagged with variance:
   - Variance Type (e.g., "Stock Deduction Variance")
   - Variance Amount or Percentage
   - Variance Status badge (Open / Investigating / Resolved)
   - Investigation Notes (if any)
   - Link to full variance report: "View Full Variance Report →"
8. THE Export Options section SHALL provide:
   - Export as Excel button (green, icon: fas fa-file-excel)
   - Export as PDF button (red, icon: fas fa-file-pdf)
   - Export as CSV button (blue, icon: fas fa-file-csv)
9. WHEN admin clicks Export Excel, THE System SHALL:
   - Generate Excel file with transaction details, payment info, validation info, audit trail
   - Trigger file download with filename: `Transaction_[ID]_[Date].xlsx`
10. WHEN admin clicks Export PDF, THE System SHALL:
    - Generate formatted PDF report with all transaction details
    - Trigger file download with filename: `Transaction_[ID]_[Date].pdf`
11. WHEN admin clicks Export CSV, THE System SHALL:
    - Generate CSV file with transaction data
    - Trigger file download with filename: `Transaction_[ID]_[Date].csv`
12. THE Admin_Oversight_Modal SHALL have action buttons:
    - Close (top-right X button)
    - Back (bottom-left button, icon: fas fa-arrow-left, returns to Oversight Dashboard)
13. THE System SHALL log admin access to audit trail when modal is opened
14. THE Admin_Oversight_Modal SHALL be read-only (no edit or action buttons for transaction status)

### Requirement 8: Modal Interaction Patterns

**User Story:** As a system user, I want consistent modal behavior across all forms, so that I know how to open, close, and navigate modals predictably.

#### Acceptance Criteria

1. ALL modals SHALL open with:
   - Fade-in animation (300ms ease-in-out)
   - Overlay background appearing behind modal
   - Modal sliding down slightly during fade-in
2. ALL modals SHALL close when:
   - User clicks Close (X) button in top-right corner
   - User clicks Cancel or Back button
   - User clicks outside modal on overlay background
   - User presses ESC key on keyboard
3. WHEN modal closes, THE System SHALL:
   - Fade-out animation (200ms ease-in-out)
   - Return focus to the element that opened the modal
   - Clear any temporary form data (if modal was cancelled)
4. THE System SHALL prevent body scroll when modal is open
5. THE System SHALL restore body scroll when modal closes
6. ALL modals SHALL trap keyboard focus within modal (Tab cycles through modal elements only)
7. THE System SHALL display loading spinner during form submission
8. THE System SHALL disable submit button after first click to prevent double-submission
9. WHEN form validation fails, THE System SHALL:
   - Display error messages inline below invalid fields
   - Highlight invalid fields with red border
   - Focus first invalid field
   - NOT close modal
10. WHEN form submission succeeds, THE System SHALL:
    - Close modal automatically
    - Display success notification (toast or banner)
    - Refresh the source list/table
    - Log action to activity log

### Requirement 9: Responsive Modal Design

**User Story:** As a mobile user, I want modals to display properly on small screens, so that I can use the transaction system on tablets and phones.

#### Acceptance Criteria

1. ON DESKTOP (>1024px), modals SHALL:
   - Center horizontally and vertically
   - Use fixed max-width (800px standard, 1000px wide)
   - Display in overlay with margin around edges
2. ON TABLET (768px - 1024px), modals SHALL:
   - Center with reduced max-width (90% of viewport)
   - Adjust padding to 20px
   - Stack multi-column layouts into single column
3. ON MOBILE (<768px), modals SHALL:
   - Take full width (100% viewport width minus 16px padding)
   - Adjust padding to 16px
   - Reduce font sizes slightly (16px body, 14px labels)
   - Stack all form elements vertically
   - Increase touch target size for buttons (min 44px height)
4. ALL modals SHALL use CSS media queries for breakpoints
5. THE System SHALL ensure all interactive elements are touch-friendly on mobile (minimum 44x44px)
6. FORM inputs on mobile SHALL:
   - Use appropriate input types (tel for phone, number for amounts)
   - Trigger correct mobile keyboard (numeric for amount fields)
7. LONG content in modals SHALL be scrollable within modal body on small screens

### Requirement 10: Accessibility Compliance

**User Story:** As a user with disabilities, I want modals to be accessible via keyboard and screen readers, so that I can use the transaction system effectively.

#### Acceptance Criteria

1. ALL modals SHALL have proper ARIA attributes:
   - `role="dialog"`
   - `aria-modal="true"`
   - `aria-labelledby` pointing to modal title
   - `aria-describedby` pointing to modal description (if present)
2. WHEN modal opens, THE System SHALL:
   - Move focus to first interactive element in modal
   - Announce modal title to screen readers
3. ALL form fields SHALL have:
   - Associated `<label>` elements with proper `for` attribute
   - Clear, descriptive label text
   - Error messages with `aria-describedby` linking to error text
4. ALL buttons SHALL have:
   - Clear, descriptive text labels
   - Icon-only buttons must have `aria-label` attribute
5. THE System SHALL support full keyboard navigation:
   - Tab/Shift+Tab to navigate between fields
   - Enter to submit forms
   - ESC to close modals
   - Space/Enter to activate buttons
6. FOCUS indicators SHALL be clearly visible (blue outline, 2px)
7. COLOR contrast SHALL meet WCAG AA standards:
   - Text on backgrounds: minimum 4.5:1 ratio
   - Buttons and interactive elements: minimum 3:1 ratio
8. ERROR messages SHALL be:
   - Announced to screen readers via `aria-live="assertive"`
   - Clearly associated with invalid fields
   - Visible and descriptive
9. THE System SHALL not rely solely on color to convey information (use icons + text)
10. LOADING states SHALL be announced to screen readers ("Submitting transaction, please wait...")

## Parser and Serializer Requirements

This feature does not require custom parsing beyond standard PHP form processing. All form data will be submitted via POST and processed using:
- PHP native `$_POST` superglobal
- PDO prepared statements for database operations
- `json_encode()` for AJAX responses
- `htmlspecialchars()` for XSS prevention

## Notes for Design Phase

- All modals should share a single CSS file: `modal_forms.css` for consistency
- Consider extracting modal HTML into reusable PHP template functions
- Use JavaScript modal library or create custom modal handler in `modal_forms.js`
- Payment status calculations should be handled via JavaScript for real-time updates
- Stock availability checks should use AJAX to validate quantities before submission
- Manager modals should load transaction data via AJAX for fresh data
- Admin audit trail should be generated server-side with proper timestamp formatting
- Export functions should generate files server-side (use PHPExcel or similar for Excel, TCPDF for PDF)
- Consider lazy-loading modal content to improve initial page load performance
- Test all modals on actual mobile devices for touch interaction
- Ensure modals work without JavaScript (progressive enhancement) where possible
- Use consistent animation timing functions across all modals
- Test keyboard navigation flow thoroughly on all modals
- Validate color contrast ratios using accessibility tools

