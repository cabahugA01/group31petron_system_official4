# 🎨 Transaction Module Modal Forms - Unified Design System

## Overview
All transaction modals (Staff, Manager, Admin) share ONE consistent design system with Petron Blue (#002F70) as the primary brand color.

---

## 🎨 COLOR PALETTE

### Primary Colors
- **Petron Blue:** `#002F70` - Headers, primary buttons
- **White:** `#FFFFFF` - Modal backgrounds
- **Light Gray:** `#F8FAFC` - Section backgrounds
- **Border Gray:** `#E2E8F0` - Borders, dividers

### Action Colors
- **Success Green:** `#059669` - Approve buttons
- **Danger Red:** `#DC2626` - Reject buttons  
- **Warning Yellow:** `#F59E0B` - Adjust buttons

### Status Badges
- **Paid:** Green background `#D1FAE5` with dark green text `#065F46`
- **Partial:** Yellow background `#FEF3C7` with dark yellow text `#92400E`
- **Utang:** Red background `#FEE2E2` with dark red text `#991B1B`

---

## 📐 MODAL STRUCTURE

### Base Modal Layout
```
┌─────────────────────────────────────────┐
│ Header (#002F70 background)             │ ← 20px padding, white text, 18px bold
├─────────────────────────────────────────┤
│                                          │
│ Body (White background)                  │ ← 24px padding
│                                          │
│   [Form sections with light gray bg]    │
│                                          │
├─────────────────────────────────────────┤
│ Footer (White background)                │ ← Action buttons
│ [Back Button]    [Primary Action Button]│
└─────────────────────────────────────────┘
```

### Modal Sizes
- **Standard:** 800px max-width (Staff forms, Manager actions)
- **Wide:** 1000px max-width (Admin oversight with audit trail)
- **Mobile:** 100% width minus 16px padding

---

## 📝 FORM ELEMENTS

### Input Fields
```css
Border: 1px solid #CBD5E1
Border Radius: 6px
Padding: 8px 12px
Font Size: 14px
Focus: Blue border (#002F70) with subtle shadow
```

### Labels
```css
Font Size: 13px
Font Weight: 600 (semibold)
Color: #475569
Margin Bottom: 6px
```

### Buttons
```css
Height: 40px
Padding: 8px 16px
Border Radius: 6px
Font Size: 14px
Font Weight: 600
Icon + Text (Font Awesome icons)
```

#### Button Variants
- **Primary (Blue):** Submit, Confirm - `#002F70` background
- **Success (Green):** Approve - `#059669` background
- **Danger (Red):** Reject - `#DC2626` background
- **Warning (Yellow):** Adjust - `#F59E0B` background
- **Secondary (Gray):** Cancel, Back - `#64748B` background

---

## 📋 STAFF MODALS

### 1. Merchandise Transaction Modal

**Sections:**
1. **Customer Details**
   - First Name, Last Name, Contact Number

2. **Product Selection**
   - Product dropdown (searchable)
   - Quantity, Unit Price (auto-filled), Category badge
   - Stock Available indicator
   - Add Item button → builds product list table
   - Product list with Remove option

3. **Payment Information**
   - Payment Method dropdown
   - Payment Status radio buttons (Paid / Partial / Utang)
   - Amount Paid field (conditional)
   - Total Amount (auto-calculated, prominent display)
   - Balance Due (auto-calculated for Partial/Utang)

**Actions:**
- ✅ Submit Transaction (Blue button, `fas fa-check`)
- ← Back (Gray button, `fas fa-arrow-left`)

---

### 2. Job Order Transaction Modal

**Sections:**
1. **Customer Details**
   - First Name, Last Name, Contact Number

2. **Vehicle Information**
   - Vehicle Type dropdown
   - Plate Number (uppercase format)

3. **Service Details**
   - Service Type dropdown (from service_fees table)
   - Service Fee (auto-filled, editable)
   - Assigned Mechanic dropdown
   - Notes/Remarks textarea

4. **Payment Information**
   - Payment Method dropdown
   - Payment Status radio (Paid / Partial / Utang)
   - Downpayment field (for Partial)
   - Balance Due (auto-calculated)

**Actions:**
- ✅ Submit Job Order (Blue button, `fas fa-check`)
- ← Back (Gray button, `fas fa-arrow-left`)

---

## 📋 MANAGER MODALS

### 3. Validation Modal

**Sections:**
1. **Transaction Summary**
   - Transaction ID (large display)
   - Type badge, Date/Time, Status badge

2. **Customer & Staff Info**
   - Customer Name, Contact
   - Staff Encoder Name, Encoding Time

3. **Transaction Details**
   - **Product table (for Merchandise):**
     - Columns: Product Name | Quantity | Unit Price | Subtotal
     - Min column width: 150px per column
   - **Service details table (for Job Order):**
     - Columns: Service / Merchandise | Vehicle | Mechanic / Staff | Total
     - **Mechanic / Staff column:** Min width 150px (ensure full text visible)
     - **Total column:** Min width 120px, right-aligned
   - Total Amount (prominent display, bold, large font)

4. **Payment Information**
   - Payment Method badge
   - Payment Status badge
   - Amount Paid, Balance Due

5. **Manager Actions**
   - Remarks textarea (required for Reject)

**Actions:**
- ✅ Approve (Green button, `fas fa-check-circle`)
- ✏️ Adjust (Yellow button, `fas fa-edit`)
- ❌ Reject (Red button, `fas fa-times-circle`)
- ← Back (Gray button, `fas fa-arrow-left`)

**Table Column Widths:**
```css
/* Job Order table columns */
.validation-table .col-service { min-width: 200px; }
.validation-table .col-vehicle { min-width: 120px; }
.validation-table .col-mechanic { min-width: 150px; } /* INCREASED to prevent cutoff */
.validation-table .col-total { min-width: 120px; text-align: right; }
```

---

### 4. Adjust Modal

**Sections:**
1. **Transaction Summary** (compact)
   - Transaction ID, Customer, Staff, Type badge

2. **Adjustment Details**
   - Original Amount (read-only display)
   - Adjusted Amount (number input, required)
   - Difference (auto-calculated, color-coded)
   - Adjustment Reason (textarea, required, min 10 chars)

3. **Transaction Preview**
   - Items/services summary
   - Payment method display

**Actions:**
- ✅ Confirm Adjustment (Yellow button, `fas fa-check`)
- ❌ Cancel (Gray button, `fas fa-times`)
- ← Back (Gray button, `fas fa-arrow-left`)

---

### 5. Reject Modal

**Sections:**
1. **Transaction Summary** (compact)
   - Transaction ID, Customer, Staff, Type badge, Date

2. **Rejection Details**
   - Rejection Reason (textarea, required, min 20 chars)
   - Character counter display

3. **Transaction Preview**
   - Items/services summary
   - Total amount, Payment info
   - Note: "Transaction will be returned to staff"

**Actions:**
- ❌ Confirm Rejection (Red button, `fas fa-ban`)
- Cancel (Gray button, `fas fa-times`)
- ← Back (Gray button, `fas fa-arrow-left`)

---

## 📋 ADMIN MODAL

### 6. Oversight Modal (Read-Only)

**Sections:**
1. **Transaction Header**
   - Transaction ID (large, prominent)
   - Type badge, Status badge, Date/Time

2. **Customer & Transaction Details**
   - Customer Name, Contact
   - Product table (Merchandise) / Service details (Job Order)
   - Total Amount (prominent)

3. **Payment Information**
   - Payment Method badge, Status badge
   - Amount Paid, Balance Due

4. **Validation Information**
   - Staff Encoder (name, timestamp)
   - Manager Validator (name, timestamp, action)
   - Manager Remarks (if any)

5. **Audit Trail Timeline**
   - Staff encoding event (user, time, action)
   - Manager validation event (user, time, action)
   - Admin viewing event (current user, time)

6. **Variance Report** (if flagged)
   - Variance Type, Amount/Percentage
   - Status badge, Investigation Notes
   - Link to full variance report

7. **Export Options**
   - 📊 Export as Excel (Green button, `fas fa-file-excel`)
   - 📄 Export as PDF (Red button, `fas fa-file-pdf`)
   - 📋 Export as CSV (Blue button, `fas fa-file-csv`)

**Actions:**
- ❌ Close (top-right X button)
- ← Back (Gray button, `fas fa-arrow-left`)

---

## 🎯 INTERACTION PATTERNS

### Modal Opening
- Fade-in animation (300ms)
- Slide down slightly
- Overlay background (50% black)
- Focus first interactive element
- Prevent body scroll

### Modal Closing
- Triggered by: X button, Cancel, Back, ESC key, click outside
- Fade-out animation (200ms)
- Restore body scroll
- Return focus to trigger element

### Form Submission
- Show loading spinner
- Disable submit button (prevent double-submit)
- On success: Close modal, refresh list, show toast notification
- On error: Show inline errors, highlight invalid fields, keep modal open

### Real-Time Calculations
- Total Amount updates as products are added
- Balance Due = Total - Amount Paid (for Partial)
- Difference = New Amount - Old Amount (for Adjust)
- Stock availability checks on quantity change

---

## 📱 RESPONSIVE BREAKPOINTS

### Desktop (>1024px)
- Center modal with fixed max-width
- Full feature set
- Side-by-side layouts for related fields

### Tablet (768px - 1024px)
- 90% viewport width
- Reduced padding (20px)
- Stack multi-column layouts

### Mobile (<768px)
- Full width (minus 16px padding)
- Reduced padding (16px)
- All fields vertical stack
- Larger touch targets (44px min height)
- Appropriate mobile keyboards (numeric, tel)

---

## ♿ ACCESSIBILITY

### ARIA Attributes
- `role="dialog"`
- `aria-modal="true"`
- `aria-labelledby` (modal title)
- `aria-describedby` (modal description)

### Keyboard Navigation
- Tab/Shift+Tab: Navigate fields
- Enter: Submit forms
- ESC: Close modal
- Focus trap within modal

### Screen Reader Support
- Announce modal title on open
- Error messages with `aria-live="assertive"`
- All fields have associated labels
- Loading states announced

### Visual Accessibility
- Color contrast: WCAG AA (4.5:1 for text)
- Visible focus indicators (2px blue outline)
- Don't rely on color alone (use icons + text)
- Clear error messages

---

## 🛠️ TECHNICAL IMPLEMENTATION

### CSS Architecture
```
assets/css/modal_forms.css       ← All modal styles
```

### JavaScript
```
assets/js/modal_forms.js         ← Modal open/close, validation
assets/js/payment_calculator.js  ← Real-time payment calculations
```

### PHP Templates
```
partials/modals/staff_merchandise_modal.php
partials/modals/staff_joborder_modal.php
partials/modals/manager_validation_modal.php
partials/modals/manager_adjust_modal.php
partials/modals/manager_reject_modal.php
partials/modals/admin_oversight_modal.php
```

### Form Processing
```
backend/staff_submit_merchandise.php
backend/staff_submit_joborder.php
backend/manager_approve_transaction.php
backend/manager_adjust_transaction.php
backend/manager_reject_transaction.php
backend/admin_export_transaction.php
```

---

## ✅ DESIGN CHECKLIST

### Visual Consistency
- ✅ Same color scheme across all modals
- ✅ Same typography (font sizes, weights)
- ✅ Same spacing system
- ✅ Same button styles
- ✅ Same badge styles
- ✅ Same input field styles

### Functional Consistency
- ✅ Same open/close behavior
- ✅ Same validation patterns
- ✅ Same error display
- ✅ Same loading states
- ✅ Same success notifications

### Responsive Design
- ✅ Works on desktop, tablet, mobile
- ✅ Touch-friendly on mobile
- ✅ Appropriate mobile keyboards
- ✅ Readable text sizes on small screens

### Accessibility
- ✅ Keyboard navigable
- ✅ Screen reader compatible
- ✅ WCAG AA color contrast
- ✅ Clear error messages
- ✅ Visible focus indicators

---

**Design Version:** 1.0  
**Last Updated:** June 3, 2026  
**Status:** Ready for Implementation


---

## 📊 TABLE COLUMN WIDTH GUIDELINES

### Transaction Tables (All Modals)

To prevent text cutoff and ensure readability, all transaction tables must follow these minimum column widths:

#### Merchandise Transaction Table
```css
.tm-table-merchandise th,
.tm-table-merchandise td {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tm-table-merchandise .col-product { min-width: 200px; max-width: 300px; }
.tm-table-merchandise .col-quantity { min-width: 80px; text-align: center; }
.tm-table-merchandise .col-price { min-width: 100px; text-align: right; }
.tm-table-merchandise .col-subtotal { min-width: 120px; text-align: right; font-weight: 600; }
```

#### Job Order Transaction Table
```css
.tm-table-joborder th,
.tm-table-joborder td {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tm-table-joborder .col-service { min-width: 220px; max-width: 350px; }
.tm-table-joborder .col-vehicle { min-width: 130px; }
.tm-table-joborder .col-mechanic { min-width: 160px; } /* CRITICAL: Prevent "Mechanic / Staff" cutoff */
.tm-table-joborder .col-staff { min-width: 140px; } /* Alternative column name */
.tm-table-joborder .col-total { min-width: 130px; text-align: right; font-weight: 700; } /* CRITICAL: Prevent "Total" cutoff */
```

#### Validated Transactions Overview Table (Manager/Admin)
```css
.tm-table-validated th,
.tm-table-validated td {
  padding: 12px 10px;
  vertical-align: middle;
}

.tm-table-validated .col-id { min-width: 120px; }
.tm-table-validated .col-type { min-width: 140px; }
.tm-table-validated .col-customer { min-width: 180px; }
.tm-table-validated .col-service { min-width: 200px; }
.tm-table-validated .col-mechanic { min-width: 150px; font-weight: 500; } /* ENSURE VISIBILITY */
.tm-table-validated .col-staff { min-width: 140px; }
.tm-table-validated .col-vat { min-width: 100px; text-align: right; }
.tm-table-validated .col-total { min-width: 130px; text-align: right; font-weight: 700; color: #002F70; } /* ENSURE VISIBILITY */
.tm-table-validated .col-payment { min-width: 120px; }
.tm-table-validated .col-datetime { min-width: 140px; }
.tm-table-validated .col-validation { min-width: 130px; }
.tm-table-validated .col-status { min-width: 110px; }
.tm-table-validated .col-actions { min-width: 200px; text-align: center; }
```

### Responsive Table Behavior

For tables with many columns that may overflow:

```css
/* Enable horizontal scroll on smaller screens */
.tm-table-container {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

/* Tablet and below: Allow horizontal scroll */
@media (max-width: 1024px) {
  .tm-table-validated {
    min-width: 1200px; /* Force horizontal scroll instead of cramping columns */
  }
}

/* Mobile: Stack some columns or hide less important ones */
@media (max-width: 768px) {
  .tm-table-validated .col-vat,
  .tm-table-validated .col-datetime {
    display: none; /* Hide less critical columns on mobile */
  }
  
  /* Ensure critical columns remain visible */
  .tm-table-validated .col-mechanic,
  .tm-table-validated .col-total {
    font-weight: 700;
    background: #F8FAFC; /* Highlight important columns */
  }
}
```

### Column Header Text Wrapping

For long column headers like "Mechanic / Staff" and "Service / Merchandise":

```css
.tm-table th {
  white-space: normal; /* Allow header text to wrap */
  line-height: 1.3;
  padding: 10px 8px;
  vertical-align: top;
}

/* Specific styling for multi-word headers */
.tm-table th.col-mechanic,
.tm-table th.col-staff {
  min-width: 160px;
  font-size: 12px;
  line-height: 1.4;
}

.tm-table th.col-total {
  min-width: 130px;
  font-size: 13px;
  font-weight: 700;
}
```

### Important Column Visibility Rules

**CRITICAL COLUMNS (Never hide or truncate):**
1. **Mechanic / Staff** - Min width: 150px-160px
2. **Total** - Min width: 120px-130px, right-aligned, bold
3. **Transaction ID** - Min width: 120px
4. **Customer** - Min width: 180px
5. **Actions** - Min width: 200px

**OPTIONAL COLUMNS (Can hide on mobile):**
- VAT
- Date/Time (can show date only)
- Vehicle (for Job Orders, can abbreviate)

---

## 🔧 Implementation Notes for Developers

### Fixing Cutoff Text Issues

If column text is cut off:

1. **Check minimum width:** Ensure `min-width` is set in CSS
2. **Check table container:** Ensure `.tm-table-container` has `overflow-x: auto`
3. **Check responsive breakpoints:** Verify columns don't shrink below minimum on tablet/mobile
4. **Test with long text:** Test with long mechanic names (e.g., "Juan Dela Cruz Gonzales")
5. **Verify right-aligned numbers:** Total amounts should be right-aligned and fully visible

### Example Fix for Current Issue

```css
/* Add these styles to fix "Mechanic / Staff" and "Total" cutoff */
.transactions-table .col-mechanic-staff {
  min-width: 160px !important;
  max-width: 200px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.transactions-table .col-total {
  min-width: 130px !important;
  text-align: right;
  font-weight: 700;
  color: #002F70;
  padding-right: 12px;
}

/* Ensure table allows horizontal scroll if needed */
.table-wrapper {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
```

