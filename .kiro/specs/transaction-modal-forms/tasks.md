# Implementation Tasks: Transaction Module Modal Forms

## Overview

This task list implements a unified modal system for Staff, Manager, and Admin transaction operations with consistent design (Petron Blue #002F70 color scheme) across all forms.

---

## Tasks

- [ ] 1. Create unified modal CSS framework
  - [ ] 1.1 Create base modal CSS file (`assets/css/modal_forms.css`)
    - Define CSS custom properties for color system
    - Create `.tm-modal` base class with overlay and container
    - Style modal header with Petron Blue (#002F70) background
    - Style modal body with white background and padding
    - Create `.tm-modal-footer` with button layouts
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8_
  
  - [ ] 1.2 Create form element styles
    - Style input fields (border, padding, focus states)
    - Style labels (font size, weight, color)
    - Style textareas with consistent sizing
    - Style select dropdowns with custom arrow
    - Create radio button and checkbox styles
    - _Requirements: 1.5, 1.6_
  
  - [ ] 1.3 Create button variants
    - Primary blue button style
    - Success green button style
    - Danger red button style
    - Warning yellow button style
    - Secondary gray button style
    - Add icon support (Font Awesome integration)
    - _Requirements: 1.7_
  
  - [ ] 1.4 Create status badge styles
    - Paid badge (green background, dark green text)
    - Partial badge (yellow background, dark yellow text)
    - Utang badge (red background, dark red text)
    - Approved, Completed, Rejected badges
    - Type badges (Merchandise, Job Order, JO + Merchandise)
    - _Requirements: 1.2_
  
  - [ ] 1.5 Add responsive styles
    - Desktop styles (>1024px): centered, fixed max-width
    - Tablet styles (768px-1024px): 90% width, reduced padding
    - Mobile styles (<768px): full width, vertical stack, larger touch targets
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.7_
  
  - [ ] 1.6 Add animation and transition styles
    - Fade-in animation for modal open (300ms)
    - Fade-out animation for modal close (200ms)
    - Slide-down animation on modal open
    - Button hover and active states with transitions
    - _Requirements: 8.1, 8.2_

- [ ] 2. Create JavaScript modal framework
  - [ ] 2.1 Create modal controller (`assets/js/modal_forms.js`)
    - Implement openModal(modalId) function
    - Implement closeModal(modalId) function
    - Add overlay click-to-close functionality
    - Add ESC key to close functionality
    - Prevent body scroll when modal open
    - Restore body scroll when modal closes
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_
  
  - [ ] 2.2 Implement keyboard focus management
    - Trap focus within modal (Tab cycle)
    - Focus first interactive element on open
    - Return focus to trigger element on close
    - Support Enter key to submit forms
    - _Requirements: 8.6, 10.2, 10.5_
  
  - [ ] 2.3 Create form validation handler
    - Client-side validation for required fields
    - Real-time validation on blur
    - Display inline error messages
    - Highlight invalid fields with red border
    - Prevent form submission if validation fails
    - _Requirements: 8.9_
  
  - [ ] 2.4 Create form submission handler
    - Show loading spinner on submit
    - Disable submit button to prevent double-submission
    - Handle AJAX form submission
    - Display success notification on success
    - Keep modal open and show errors on failure
    - Close modal and refresh list on success
    - _Requirements: 8.7, 8.8, 8.9, 8.10_

- [ ] 3. Create payment calculator JavaScript
  - [ ] 3.1 Create payment calculator (`assets/js/payment_calculator.js`)
    - Calculate total amount from product list
    - Calculate balance due (Total - Amount Paid)
    - Update balance in real-time on amount change
    - Calculate adjustment difference (New - Old)
    - Color-code difference (red for decrease, green for increase)
    - _Requirements: 2.5, 2.6, 3.5, 3.6, 5.3_
  
  - [ ] 3.2 Add payment status conditional logic
    - Show/hide Amount Paid field based on status
    - Set Amount Paid to 0 when Utang selected
    - Validate Amount Paid < Total when Partial selected
    - Update Balance Due display dynamically
    - _Requirements: 2.5, 2.6, 3.5, 3.6_

- [ ] 4. Implement Staff Merchandise Transaction Modal (CREATE operation)
  - [ ] 4.1 Create HTML template (`partials/modals/staff_merchandise_modal.php`)
    - Create modal structure with header, body, footer
    - Add Customer Details section (First Name, Last Name, Contact)
    - Add Product Selection section with dropdown and quantity input
    - Add product list table for added items
    - Add Payment Information section
    - Add action buttons (Submit, Back)
    - ❌ **NO Delete button** - transactions cannot be deleted
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.7_
  
  - [ ] 4.2 Implement product selection logic
    - Populate product dropdown from inventory (AJAX)
    - Auto-fill Unit Price on product selection
    - Auto-fill Category badge on product selection
    - Display Stock Available on product selection
    - Validate quantity against stock
    - _Requirements: 2.3_
  
  - [ ] 4.3 Implement Add Item functionality
    - Add selected product to product list table
    - Display product name, quantity, unit price, subtotal
    - Add Remove button for each product row
    - Calculate and update Total Amount on add/remove
    - Clear product selection fields after add
    - _Requirements: 2.3_
  
  - [ ] 4.4 Implement payment information logic
    - Show/hide Amount Paid based on Payment Status
    - Calculate Balance Due for Partial payments
    - Set Amount Paid to 0 for Utang
    - Require customer contact for Utang
    - _Requirements: 2.4, 2.5, 2.6_
  
  - [ ] 4.5 Create form submission handler (`backend/staff_submit_merchandise.php`) - CREATE operation
    - Validate all required fields server-side
    - **INSERT** transaction into merchandise_transactions table
    - **INSERT** products into merchandise_transaction_items table
    - Set validation_status to 'Pending'
    - ❌ **NO DELETE operations** - use INSERT only
    - Log activity to activity log (action: 'Create Transaction')
    - Return JSON response with success/error
    - _Requirements: 2.8, 2.9_
  
  - [ ] 4.6 Create Staff Update Transaction Modal (UPDATE operation - Pending only)
    - Create modal for editing transactions BEFORE manager validation
    - Load existing transaction data
    - Allow updates ONLY if validation_status = 'Pending'
    - Allow updates ONLY if transaction belongs to current staff
    - Show "Cannot edit validated transactions" message if status ≠ 'Pending'
    - Add action buttons (Update, Cancel)
    - ❌ **NO Delete button**
  
  - [ ] 4.7 Create update handler (`backend/staff_update_merchandise.php`) - UPDATE operation
    - Validate staff_id matches current user
    - Validate validation_status = 'Pending'
    - **UPDATE** transaction fields (customer, payment, etc.)
    - **UPDATE** updated_at timestamp
    - ❌ **NO DELETE operations**
    - Log activity (action: 'Update Transaction')
    - Return JSON response

- [ ] 5. Implement Staff Job Order Transaction Modal (CREATE operation)
  - [ ] 5.1 Create HTML template (`partials/modals/staff_joborder_modal.php`)
    - Create modal structure with header, body, footer
    - Add Customer Details section
    - Add Vehicle Information section (Type, Plate)
    - Add Service Details section (Type, Fee, Mechanic, Notes)
    - Add Payment Information section
    - Add action buttons (Submit Job Order, Back)
    - ❌ **NO Delete button** - job orders cannot be deleted
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.7_
  
  - [ ] 5.2 Implement service type selection logic
    - Populate Service Type dropdown from service_fees table (AJAX)
    - Auto-fill Service Fee on service type selection
    - Allow manual editing of Service Fee
    - Populate Assigned Mechanic dropdown from users (role=mechanic)
    - _Requirements: 3.4_
  
  - [ ] 5.3 Implement vehicle and customer validation
    - Validate Plate Number format (uppercase)
    - Auto-uppercase plate number on input
    - Require customer contact for Partial or Utang
    - _Requirements: 3.3_
  
  - [ ] 5.4 Implement payment calculation for job orders
    - Calculate Balance Due (Service Fee - Downpayment)
    - Validate Downpayment < Service Fee for Partial
    - Set Downpayment to 0 for Utang
    - Update Balance Due in real-time
    - _Requirements: 3.5, 3.6, 3.7_
  
  - [ ] 5.5 Create form submission handler (`backend/staff_submit_joborder.php`) - CREATE operation
    - Validate all required fields server-side
    - **INSERT** job order into job_orders table
    - Set validation_status to 'Pending Validation'
    - Set status to 'Pending'
    - ❌ **NO DELETE operations** - use INSERT only
    - Log activity to activity log (action: 'Create Job Order')
    - Return JSON response with success/error
    - _Requirements: 3.8, 3.9, 3.10_
  
  - [ ] 5.6 Create Staff Update Job Order Modal (UPDATE operation - Pending only)
    - Create modal for editing job orders BEFORE manager validation
    - Load existing job order data
    - Allow updates ONLY if validation_status = 'Pending Validation'
    - Allow updates ONLY if created_by = current staff
    - Show "Cannot edit validated job orders" message if status ≠ 'Pending Validation'
    - Add action buttons (Update, Cancel)
    - ❌ **NO Delete button**
  
  - [ ] 5.7 Create update handler (`backend/staff_update_joborder.php`) - UPDATE operation
    - Validate created_by matches current user
    - Validate validation_status = 'Pending Validation'
    - **UPDATE** job order fields
    - **UPDATE** updated_at timestamp
    - ❌ **NO DELETE operations**
    - Log activity (action: 'Update Job Order')
    - Return JSON response

- [ ] 6. Implement Manager Validation Modal (READ + UPDATE operations)
  - [ ] 6.1 Create HTML template (`partials/modals/manager_validation_modal.php`)
    - Create modal structure (wider: 1000px)
    - Add Transaction Summary section
    - Add Customer & Staff Information section
    - Add Transaction Details section (table for products/services)
    - Add Payment Information section
    - Add Manager Actions section with remarks textarea
    - Add action buttons (Approve, Adjust, Reject, Back)
    - ❌ **NO Delete button** - transactions cannot be deleted
    - ❌ **NO Edit transaction data** - only status updates allowed
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.10_
  
  - [ ] 6.2 Create AJAX data loader for validation modal
    - Load transaction data via AJAX when modal opens
    - Populate all sections with transaction details
    - Display product list table for Merchandise
    - Display service details for Job Order
    - Display both for JO + Merchandise
    - _Requirements: 4.3, 4.4_
  
  - [ ] 6.3 Implement manager action handlers
    - Approve button: validate remarks, submit approval
    - Adjust button: close validation modal, open adjust modal
    - Reject button: close validation modal, open reject modal
    - Back button: close modal, return to pending list
    - _Requirements: 4.7, 4.8, 4.9_
  
  - [ ] 6.4 Create approval handler (`backend/manager_approve_transaction.php`) - UPDATE operation
    - **UPDATE** validation_status to 'Approved'
    - **UPDATE** validated_by to manager ID
    - **UPDATE** validated_at to current timestamp
    - ❌ **NO DELETE operations** - transaction remains in database
    - **INSERT** audit trail entry (action: 'Approve')
    - Log activity to activity log
    - Return JSON response with success/error
    - _Requirements: 4.7_

- [ ] 7. Implement Manager Adjust Modal (UPDATE operation)
  - [ ] 7.1 Create HTML template (`partials/modals/manager_adjust_modal.php`)
    - Create modal structure with compact transaction summary
    - Add Adjustment Details section
    - Display Original Amount (read-only, prominent)
    - Add Adjusted Amount input (number, required)
    - Add Difference display (auto-calculated, color-coded)
    - Add Adjustment Reason textarea (required, min 10 chars)
    - Add Transaction Preview section
    - Add action buttons (Confirm Adjustment, Cancel, Back)
    - ❌ **NO Delete button** - original transaction preserved
    - Note: Original data preserved in audit trail
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.7_
  
  - [ ] 7.2 Implement adjustment calculation logic
    - Calculate Difference: New Amount - Old Amount
    - Color-code difference (red if negative, green if positive)
    - Validate New Amount > 0
    - Validate New Amount ≠ Old Amount
    - Real-time update on amount input change
    - _Requirements: 5.3, 5.5_
  
  - [ ] 7.3 Implement adjustment reason validation
    - Require minimum 10 characters
    - Show character count
    - Validate on blur and submit
    - _Requirements: 5.5_
  
  - [ ] 7.4 Create adjustment handler (`backend/manager_adjust_transaction.php`) - UPDATE operation
    - Validate all fields server-side
    - **UPDATE** transaction total_amount to New Amount
    - **UPDATE** validation_status to 'Adjusted'
    - **UPDATE** validated_by and validated_at
    - **UPDATE** remarks field (prefix: "ADJUSTED: " + reason)
    - ❌ **NO DELETE operations** - original data preserved
    - **INSERT** audit trail entry with new_value (old amount stored)
    - Log activity to activity log (action: 'Adjust Transaction')
    - Return JSON response with success/error
    - _Requirements: 5.6, 5.8_

- [ ] 8. Implement Manager Reject Modal (UPDATE operation - NOT DELETE)
  - [ ] 8.1 Create HTML template (`partials/modals/manager_reject_modal.php`)
    - Create modal structure with compact transaction summary
    - Add Rejection Details section
    - Add Rejection Reason textarea (required, min 20 chars)
    - Add character counter
    - Add Transaction Preview section
    - Display note: "Transaction will be returned to staff (NOT DELETED)"
    - Add action buttons (Confirm Rejection, Cancel, Back)
    - ❌ **NO Delete button** - rejected transactions remain in database
    - **IMPORTANT:** Clarify that rejection DOES NOT delete the transaction
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.7_
  
  - [ ] 8.2 Implement rejection reason validation
    - Require minimum 20 characters
    - Show character count ("X / 20 minimum")
    - Update counter in real-time
    - Validate on blur and submit
    - _Requirements: 6.5_
  
  - [ ] 8.3 Create rejection handler (`backend/manager_reject_transaction.php`) - UPDATE operation (NOT DELETE)
    - Validate rejection reason (min 20 chars)
    - **UPDATE** validation_status to 'Rejected'
    - **UPDATE** validated_by and validated_at
    - **UPDATE** rejection_reason or remarks field (prefix: "REJECTED: ")
    - ❌ **NO DELETE operation** - transaction remains in database with status='Rejected'
    - **INSERT** audit trail entry (action: 'Reject', reason in new_value)
    - Log activity to activity log (action: 'Reject Transaction')
    - Return JSON response with success/error
    - **VERIFY:** Transaction still exists in database after rejection
    - _Requirements: 6.6, 6.8_

- [ ] 9. Implement Admin Oversight Modal (READ-ONLY + Limited UPDATE)
  - [ ] 9.1 Create HTML template (`partials/modals/admin_oversight_modal.php`)
    - Create modal structure (wide: 1000px)
    - Add Transaction Header section
    - Add Customer & Transaction Details section
    - Add Payment Information section
    - Add Validation Information section
    - Add Audit Trail section (timeline view)
    - Add Variance Report section (conditional)
    - Add Export Options section
    - Add Compliance Notes section (optional UPDATE field)
    - Add action buttons (Close, Back)
    - ❌ **NO Delete button** - admin cannot delete transactions
    - ❌ **NO Edit transaction data** - read-only except compliance notes
    - ✅ **Export buttons** - READ operation generates files
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 7.12_
  
  - [ ] 9.2 Create AJAX data loader for oversight modal
    - Load transaction data via AJAX
    - Load audit trail entries
    - Check for variance report flag
    - Load variance details if flagged
    - Populate all sections with data
    - _Requirements: 7.2, 7.3, 7.4, 7.5, 7.6, 7.7_
  
  - [ ] 9.3 Implement audit trail timeline display
    - Display staff encoding event (user, timestamp)
    - Display manager validation event (user, timestamp, action)
    - Display admin viewing event (current user, current timestamp)
    - Use icon indicators for each event type
    - Format timestamps consistently
    - _Requirements: 7.6_
  
  - [ ] 9.4 Implement variance report section
    - Only display if transaction is flagged
    - Show variance type, amount/percentage
    - Show variance status badge
    - Show investigation notes
    - Add link to full variance report
    - _Requirements: 7.7_
  
  - [ ] 9.5 Implement export functionality
    - Export as Excel button with handler
    - Export as PDF button with handler
    - Export as CSV button with handler
    - _Requirements: 7.8, 7.9, 7.10, 7.11_
  
  - [ ] 9.6 Create Excel export handler (`backend/admin_export_transaction_excel.php`)
    - Generate Excel file with transaction details
    - Include all sections: customer, items, payment, validation, audit trail
    - Use PHPExcel or PhpSpreadsheet library
    - Set filename: Transaction_[ID]_[Date].xlsx
    - Trigger file download
    - _Requirements: 7.9_
  
  - [ ] 9.7 Create PDF export handler (`backend/admin_export_transaction_pdf.php`)
    - Generate formatted PDF report
    - Include all transaction details with proper formatting
    - Use TCPDF or similar library
    - Set filename: Transaction_[ID]_[Date].pdf
    - Trigger file download
    - _Requirements: 7.10_
  
  - [ ] 9.8 Create CSV export handler (`backend/admin_export_transaction_csv.php`)
    - Generate CSV file with transaction data
    - Include flattened transaction details
    - Set filename: Transaction_[ID]_[Date].csv
    - Trigger file download
    - _Requirements: 7.11_
  
  - [ ] 9.9 Log admin access to audit trail
    - Insert audit trail entry when modal opens
    - Record admin ID, transaction ID, action: "Viewed", timestamp
    - _Requirements: 7.13_

- [ ] 10. Add accessibility features to all modals
  - [ ] 10.1 Add ARIA attributes to modal elements
    - Add role="dialog" to all modals
    - Add aria-modal="true"
    - Add aria-labelledby pointing to modal title
    - Add aria-describedby for descriptions
    - _Requirements: 10.1_
  
  - [ ] 10.2 Implement keyboard navigation
    - Tab/Shift+Tab to navigate fields
    - Enter key to submit forms
    - ESC key to close modals
    - Space/Enter to activate buttons
    - _Requirements: 10.5_
  
  - [ ] 10.3 Add screen reader support
    - Associate all labels with inputs (for attribute)
    - Add aria-label to icon-only buttons
    - Add aria-live="assertive" to error messages
    - Announce loading states to screen readers
    - _Requirements: 10.2, 10.3, 10.4, 10.10_
  
  - [ ] 10.4 Ensure color contrast compliance
    - Verify text on backgrounds: 4.5:1 ratio minimum
    - Verify buttons: 3:1 ratio minimum
    - Use accessibility checker tools
    - _Requirements: 10.7_
  
  - [ ] 10.5 Add visible focus indicators
    - Add blue outline (2px) on focus
    - Ensure focus indicators on all interactive elements
    - Test keyboard navigation thoroughly
    - _Requirements: 10.6_
  
  - [ ] 10.6 Ensure error messages are accessible
    - Link errors to fields via aria-describedby
    - Display errors below fields
    - Use icons + text (not color alone)
    - Announce errors to screen readers
    - _Requirements: 10.8, 10.9_

- [ ] 11. Testing and verification
  - [ ] 11.1 Test all modals on desktop browsers
    - Test in Chrome, Firefox, Edge
    - Verify layout, styling, interactions
    - Test form validation
    - Test form submission (success and error cases)
    - _Requirements: All_
  
  - [ ] 11.2 Test all modals on tablet
    - Test on iPad or Android tablet
    - Verify responsive layout
    - Test touch interactions
    - Verify all buttons are touch-friendly
    - _Requirements: 9.2_
  
  - [ ] 11.3 Test all modals on mobile
    - Test on iPhone and Android phone
    - Verify full-width layout
    - Verify vertical stacking
    - Test mobile keyboard types (numeric, tel)
    - Verify touch target sizes (44px minimum)
    - _Requirements: 9.3, 9.4, 9.5, 9.6_
  
  - [ ] 11.4 Test keyboard navigation on all modals
    - Tab through all fields
    - Test Enter to submit
    - Test ESC to close
    - Verify focus trap
    - Verify focus indicators are visible
    - _Requirements: 10.5_
  
  - [ ] 11.5 Test screen reader compatibility
    - Test with NVDA (Windows) or VoiceOver (Mac)
    - Verify modal announcements
    - Verify field labels are read
    - Verify error messages are announced
    - _Requirements: 10.2, 10.3, 10.4_
  
  - [ ] 11.6 Verify visual consistency across all modals
    - Same colors used everywhere
    - Same fonts and sizes
    - Same spacing
    - Same button styles
    - Same badge styles
    - _Requirements: 1.1 through 1.8_
  
  - [ ] 11.7 Test real-time calculations
    - Test total amount calculation in merchandise modal
    - Test balance due calculation in job order modal
    - Test adjustment difference calculation
    - Verify calculations update instantly
    - _Requirements: 2.5, 3.6, 5.3_
  
  - [ ] 11.8 Test conditional field display
    - Test Amount Paid shows/hides based on Payment Status
    - Test Downpayment shows for Partial
    - Test Balance Due shows for Partial/Utang
    - Test customer contact requirement for Utang
    - _Requirements: 2.5, 2.6, 3.5, 3.6_
  
  - [ ] 11.9 Test back button functionality
    - Verify Back button returns to correct list/table
    - Verify modal closes without saving
    - Verify list is not refreshed (no data loss)
    - _Requirements: 2.10, 3.11, 4.10, 5.8, 6.8, 7.12_

- [ ] 12. Documentation and final review
  - [ ] 12.1 Document modal usage for developers
    - Create README for modal system
    - Document JavaScript API (openModal, closeModal)
    - Document CSS classes and variables
    - Provide code examples for each modal
    - **Document CRUD restrictions** (NO DELETE policy)
    - _Requirements: All_
  
  - [ ] 12.2 Create user guide for staff
    - How to encode merchandise transactions (CREATE)
    - How to encode job orders (CREATE)
    - How to update pending transactions (UPDATE - before validation)
    - How to interpret validation status (READ)
    - **Clarify: Cannot delete transactions**
    - _Requirements: 2, 3_
  
  - [ ] 12.3 Create user guide for managers
    - How to validate transactions (READ + UPDATE status)
    - How to adjust transaction amounts (UPDATE with audit trail)
    - How to reject transactions with proper reasons (UPDATE, NOT DELETE)
    - **Clarify: Rejected transactions remain in database**
    - _Requirements: 4, 5, 6_
  
  - [ ] 12.4 Create user guide for admins
    - How to view transaction details (READ)
    - How to interpret audit trail (READ)
    - How to export transaction records (READ operation)
    - How to update compliance notes (LIMITED UPDATE)
    - **Clarify: Cannot delete or modify transaction data**
    - _Requirements: 7_
  
  - [ ] 12.5 Final visual review
    - Compare all modals side-by-side
    - Verify uniform appearance
    - Check for any inconsistencies
    - Verify NO Delete buttons present in any modal
    - Review with stakeholders
    - _Requirements: 1.1_

- [ ] 13. Database protection and CRUD enforcement
  - [ ] 13.1 Revoke DELETE permissions from application database user
    - Connect as database admin
    - Execute: `REVOKE DELETE ON merchandise_transactions FROM 'petron_app_user'@'localhost';`
    - Execute: `REVOKE DELETE ON job_orders FROM 'petron_app_user'@'localhost';`
    - Execute: `REVOKE DELETE ON merchandise_transaction_items FROM 'petron_app_user'@'localhost';`
    - Verify: Test DELETE should fail with permission error
  
  - [ ] 13.2 Add audit trail for all UPDATE operations
    - Ensure all UPDATE operations log to audit_trail table
    - Include: transaction_id, user_id, action_type, old_value, new_value, timestamp
    - Test: Verify audit trail entries created for Approve, Adjust, Reject
  
  - [ ] 13.3 Validate UPDATE operations enforce proper status checks
    - Staff updates: Only allowed if validation_status = 'Pending'
    - Manager updates: Only allowed if validation_status != 'Rejected'
    - Admin updates: Only allowed for compliance fields
    - Test: Attempt to update validated transaction as staff should fail
  
  - [ ] 13.4 Test NO DELETE policy
    - Attempt to delete transaction via SQL - should fail (permission denied)
    - Attempt to delete via application - no delete option available
    - Verify rejected transactions remain in database with status='Rejected'
    - Verify adjusted transactions show original values in audit trail
  
  - [ ] 13.5 Create database maintenance procedures (superadmin only)
    - Document procedure for archiving old transactions (if needed)
    - Use soft delete (archived_at column) instead of hard delete
    - Create archived_transactions view for historical data
    - **NEVER** hard delete transactions except for data cleanup with approval

- [ ] 14. Implement Summary Cards for Transaction Dashboards
  - [ ] 14.1 Create summary cards CSS
    - Create `.summary-card-row` grid container (responsive grid)
    - Create `.summary-card` base styles (white background, rounded, shadow)
    - Create `.card-icon` with size variants (56px desktop, 48px mobile)
    - Create icon color variants (blue, green, amber, red, orange, purple)
    - Create `.card-content` with label, value, subtext styles
    - Add hover effects (lift + shadow)
    - Add responsive breakpoints (4/5 columns → 2 columns → 1 column)
    - Add print styles (hide cards in print mode)
    - _Reference: SUMMARY_CARDS_SPECIFICATION.md, SUMMARY_CARDS_VISUAL_GUIDE.md_
  
  - [ ] 14.2 Create Staff Dashboard Summary Cards (4 cards)
    - **Card 1: Transactions Encoded** (Blue, fas fa-file-invoice)
      - Display count of merchandise + job orders encoded today by current staff
      - Create backend API: `backend/api/get_staff_transactions_count.php`
      - SQL query: COUNT from merchandise_transactions + job_orders WHERE staff_id = current AND DATE = today
    
    - **Card 2: Pending Payments** (Amber, fas fa-clock)
      - Display ₱ amount + count of unpaid/partial payments
      - Create backend API: `backend/api/get_staff_pending_payments.php`
      - SQL query: SUM and COUNT WHERE payment_status IN ('Pending', 'Partial')
    
    - **Card 3: Utang Accounts** (Red, fas fa-credit-card)
      - Display ₱ amount + count of credit receivables
      - Create backend API: `backend/api/get_staff_utang.php`
      - SQL query: SUM and COUNT WHERE payment_status = 'Utang'
    
    - **Card 4: Completed Job Orders** (Green, fas fa-check-circle)
      - Display count of completed job orders today
      - Create backend API: `backend/api/get_staff_completed_jo.php`
      - SQL query: COUNT WHERE status = 'Completed' AND DATE = today
    
    - Add cards above Job Order Tracker table in `public/staff_job_order_tracker.php`
    - _Reference: SUMMARY_CARDS_SPECIFICATION.md section 1_
  
  - [ ] 14.3 Create Manager Dashboard Summary Cards (4 cards)
    - **Card 1: Pending Transactions** (Amber, fas fa-hourglass-half)
      - Display count of staff-encoded transactions awaiting validation
      - Create backend API: `backend/api/get_manager_pending_count.php`
      - SQL query: COUNT WHERE validation_status = 'Pending' AND station_id = current
      - Add click action: filter Pending tab
    
    - **Card 2: Validated Today** (Green, fas fa-check-double)
      - Display count of transactions approved today by manager
      - Create backend API: `backend/api/get_manager_validated_today.php`
      - SQL query: COUNT WHERE validation_status = 'Approved' AND DATE(validated_at) = today
    
    - **Card 3: Variance Alerts** (Red, fas fa-exclamation-triangle)
      - Display count of flagged anomalies
      - Create backend API: `backend/api/get_manager_variance_alerts.php`
      - SQL query: COUNT FROM variance_reports WHERE status = 'Flagged'
      - Add click action: show variance details
    
    - **Card 4: Pending Payments** (Blue, fas fa-money-bill-wave)
      - Display ₱ amount + count of validated but unpaid transactions
      - Create backend API: `backend/api/get_manager_pending_payments.php`
      - SQL query: SUM and COUNT WHERE validation_status IN ('Approved', 'Completed') AND payment_status IN ('Pending', 'Partial', 'Utang')
    
    - Add cards above Pending/Validated tabs in `public/manager_transactions.php`
    - _Reference: SUMMARY_CARDS_SPECIFICATION.md section 2_
  
  - [ ] 14.4 Create Admin Dashboard Summary Cards (5 cards)
    - **Card 1: Total Validated Transactions** (Blue, fas fa-chart-line)
      - Display system-wide count of validated transactions today
      - Create backend API: `backend/api/get_admin_validated_count.php`
      - SQL query: COUNT WHERE validation_status IN ('Approved', 'Completed') AND DATE(validated_at) = today
    
    - **Card 2: Pending Payments** (Amber, fas fa-file-invoice-dollar)
      - Display ₱ amount + count of unpaid balances across all stations
      - Create backend API: `backend/api/get_admin_pending_payments.php`
      - SQL query: SUM and COUNT WHERE payment_status IN ('Pending', 'Partial')
    
    - **Card 3: Outstanding Utang** (Red, fas fa-clipboard-list)
      - Display ₱ amount + count of credit receivables
      - Create backend API: `backend/api/get_admin_outstanding_utang.php`
      - SQL query: SUM and COUNT WHERE payment_status = 'Utang'
      - Add click action: navigate to receivables report
    
    - **Card 4: Variance Reports** (Orange, fas fa-flag)
      - Display count of flagged anomalies system-wide
      - Create backend API: `backend/api/get_admin_variance_count.php`
      - SQL query: COUNT FROM variance_reports WHERE status IN ('Flagged', 'Under Investigation')
      - Add click action: navigate to admin_variance_reports.php
    
    - **Card 5: Receivables Aging** (Purple, fas fa-calendar-alt)
      - Display breakdown: Current vs Overdue balances
      - Create backend API: `backend/api/get_admin_receivables_aging.php`
      - SQL query: SUM with CASE for current (≤30 days) vs overdue (>30 days)
      - Add click action: show detailed aging report modal
    
    - Add cards above Validated Transactions table in `public/admin_transactions_oversight.php`
    - _Reference: SUMMARY_CARDS_SPECIFICATION.md section 3_
  
  - [ ] 14.5 Add optional auto-refresh functionality
    - Create JavaScript function: `refreshSummaryCards()`
    - Fetch updated card data via AJAX every 30 seconds
    - Update card values without full page reload
    - Add smooth fade transition on value change
    - Only refresh if user is actively viewing dashboard (use visibility API)
  
  - [ ] 14.6 Test summary cards responsiveness
    - Test on desktop (1920px, 1366px, 1024px)
    - Test on tablet (iPad 768px, iPad Pro 1024px)
    - Test on mobile (iPhone 375px, Android 360px)
    - Verify grid collapses: 4/5 columns → 2 columns → 1 column
    - Verify icon sizes adjust: 56px → 52px → 48px
    - Verify touch targets are 44px minimum on mobile
  
  - [ ] 14.7 Test summary cards click actions (where applicable)
    - Manager Card 1: Click filters Pending tab
    - Manager Card 3: Click shows variance details
    - Admin Card 3: Click navigates to receivables report
    - Admin Card 4: Click navigates to variance reports page
    - Admin Card 5: Click shows aging report modal
  
  - [ ] 14.8 Verify summary cards data accuracy
    - Test with sample data: verify counts match database
    - Test with multiple staff: verify staff sees only own data
    - Test with date filters: verify "today" calculations are correct
    - Test with different payment statuses: verify filtering logic
    - Test with station filters: verify manager sees only station data

---

## Task Dependency Notes

- Task 1 (CSS framework) must be completed before any modal HTML creation
- Task 2 (JavaScript framework) must be completed before modal interactions
- Task 3 (payment calculator) required for Staff modals (tasks 4, 5)
- Manager modals (tasks 6, 7, 8) can be developed in parallel after tasks 1-2
- Admin modal (task 9) requires understanding of audit trail structure
- Accessibility (task 10) should be integrated throughout, not bolted on at end
- Testing (task 11) should be done incrementally, not just at the end

---

## Notes

- All backend handlers must use prepared statements for SQL injection prevention
- All output must use htmlspecialchars() for XSS prevention
- All AJAX responses must return JSON with consistent structure: `{success: true/false, message: '', data: {}}`
- Use existing database tables (merchandise_transactions, job_orders, audit_trail)
- Ensure backwards compatibility with existing transaction pages
- Test thoroughly with real transaction data
- Get user feedback from Staff, Manager, Admin before finalizing
- Consider creating a style guide document showing all modal components
- Ensure all PHP files include proper session checks and role validation
- Log all transaction actions to activity_log table for compliance

---

**Status:** Ready for Implementation  
**Estimated Effort:** 40-60 hours (depending on team size)  
**Priority:** High (improves user experience across entire Transaction Module)
