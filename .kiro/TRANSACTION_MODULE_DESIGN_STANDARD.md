# Transaction Module Design Standard

**Date**: June 3, 2026  
**Applies To**: Staff, Manager, Admin Transaction Modules

---

## 🎨 Design Specifications

### 1. Table Headers
```css
thead th {
    background: #002F70;  /* Petron Blue */
    color: #ffffff;       /* White text */
    padding: 12px 10px;
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    text-align: left;
    border-bottom: 2px solid #001a3d;
}
```

### 2. Table Body (Clean, No Colored Backgrounds)
```css
tbody td {
    padding: 10px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    background: #ffffff;  /* Always white */
    font-size: 13px;
    color: #1e293b;
}
```

### 3. Table Row Hover (Light Blue)
```css
tbody tr:hover td {
    background: #eff6ff;  /* Light blue on hover */
}
```

### 4. Plain Text Badges (No Colored Backgrounds)
```css
.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    background: transparent;  /* NO background */
    color: #1e293b;          /* Dark text */
    border: 1px solid #e2e8f0;  /* Light border only */
}

/* Status-specific text colors (NO backgrounds) */
.badge-paid { color: #166534; border-color: #166534; }
.badge-partial { color: #854d0e; border-color: #854d0e; }
.badge-unpaid { color: #991b1b; border-color: #991b1b; }
.badge-pending { color: #64748b; border-color: #64748b; }
.badge-approved { color: #065f46; border-color: #065f46; }
.badge-rejected { color: #991b1b; border-color: #991b1b; }
.badge-completed { color: #166534; border-color: #166534; }
.badge-inprogress { color: #1d4ed8; border-color: #1d4ed8; }
```

### 5. Standardized Action Buttons
```css
.action-button {
    padding: 6px 12px;
    font-size: 13px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transition: background 0.2s ease;
}

/* 4-Color Standard */
.btn-primary   { background: #002F70; color: #fff; }  /* Dark Blue */
.btn-success   { background: #16a34a; color: #fff; }  /* Green */
.btn-secondary { background: #6b7280; color: #fff; }  /* Gray */
.btn-danger    { background: #dc2626; color: #fff; }  /* Red */

/* Hover States */
.btn-primary:hover   { background: #001a3d; }
.btn-success:hover   { background: #15803d; }
.btn-secondary:hover { background: #4b5563; }
.btn-danger:hover    { background: #b91c1c; }
```

### 6. No Horizontal Scrolling
```css
.table-container {
    width: 100%;
    overflow-x: visible;  /* NO horizontal scroll */
}

table {
    width: 100%;
    table-layout: fixed;  /* Fixed layout to prevent overflow */
}

/* Responsive column widths */
td, th {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Allow wrapping on specific columns if needed */
.wrap-text {
    white-space: normal;
    word-wrap: break-word;
}
```

### 7. Light Blue Hover Effects
```css
/* Table rows */
tbody tr:hover td {
    background: #eff6ff;  /* Light blue */
}

/* Buttons (non-primary) */
.btn-view:hover {
    background: #dbeafe;
    color: #1e40af;
}

/* Filter cards */
.filter-card:hover {
    background: #f8fafc;
}
```

---

## 📋 Implementation Checklist

### For Each Transaction Module File:

- [ ] ✅ Update table header background to #002F70 with white text
- [ ] ✅ Remove all colored backgrounds from table body cells
- [ ] ✅ Set all `<td>` backgrounds to white (#ffffff)
- [ ] ✅ Change hover background to light blue (#eff6ff)
- [ ] ✅ Convert all status badges to plain text with border only
- [ ] ✅ Remove background colors from badges (use transparent or white)
- [ ] ✅ Keep only text color and border for badges
- [ ] ✅ Standardize all action buttons to 4-color system
- [ ] ✅ Remove horizontal scroll (overflow-x: visible)
- [ ] ✅ Use table-layout: fixed to prevent overflow
- [ ] ✅ Add text-overflow: ellipsis for long text
- [ ] ✅ Ensure light blue hover on all interactive elements

---

## 🎯 Files to Update

1. **`public/staff_transactions_hub.php`**
   - Job Order Tracker table
   - Merchandise History table
   - Status badges
   - Action buttons

2. **`public/manager_validated_transactions.php`**
   - Main transactions table
   - Status badges
   - Action buttons

3. **`public/pending_transactions.php`**
   - Pending transactions table
   - Status badges
   - Action buttons (Approve/Reject/Adjust/View)

4. **`public/admin_transactions_oversight.php`**
   - Overview table
   - Status badges
   - Action buttons

---

## ❌ What to REMOVE

1. **Colored Table Row Backgrounds**:
   - ❌ Remove: `background:#fff8f8` (rejected rows)
   - ❌ Remove: `background:#f0f7ff` (info rows)
   - ❌ Remove: `background:#fef9c3` (pending rows)
   - ✅ Keep: White background only

2. **Colored Badge Backgrounds**:
   - ❌ Remove: `background:#dcfce7` (paid - green bg)
   - ❌ Remove: `background:#fef3c7` (partial - yellow bg)
   - ❌ Remove: `background:#fee2e2` (unpaid - red bg)
   - ❌ Remove: `background:#dbeafe` (in progress - blue bg)
   - ✅ Replace with: Transparent/white + colored text + border

3. **Horizontal Scroll**:
   - ❌ Remove: `overflow-x:auto`
   - ❌ Remove: `min-width:1000px` on tables
   - ✅ Replace with: `table-layout:fixed` + proper column widths

---

## ✅ What to KEEP

1. **Blue Headers**: #002F70 with white text
2. **White Backgrounds**: Clean, no colors on table cells
3. **Light Blue Hover**: #eff6ff on row hover
4. **4-Color Buttons**: Dark Blue, Green, Gray, Red
5. **Text Colors for Status**: Colored text only, no backgrounds
6. **Fixed Table Layout**: No horizontal scrolling

---

## 📊 Color Palette

### Primary Colors
- **Petron Blue**: #002F70 (headers, primary buttons)
- **White**: #ffffff (table backgrounds)
- **Light Blue**: #eff6ff (hover states)

### Status Text Colors (NO backgrounds)
- **Paid/Completed**: #166534 (green text)
- **Partial/Pending**: #854d0e (amber text)
- **Unpaid/Rejected**: #991b1b (red text)
- **In Progress**: #1d4ed8 (blue text)
- **Approved**: #065f46 (dark green text)

### Button Colors
- **Primary**: #002F70 (dark blue)
- **Success**: #16a34a (green)
- **Secondary**: #6b7280 (gray)
- **Danger**: #dc2626 (red)

### Border Colors
- **Light**: #f1f5f9 (table borders)
- **Medium**: #e2e8f0 (card borders)
- **Dark**: #cbd5e1 (input borders)

---

## 🔧 CSS Template

```css
/* === TRANSACTION MODULE STANDARD === */

/* Table Container - No Scroll */
.txn-container {
    width: 100%;
    overflow-x: visible;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
}

/* Table Base */
.txn-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    table-layout: fixed;
}

/* Blue Headers with White Text */
.txn-table thead th {
    background: #002F70;
    color: #ffffff;
    padding: 12px 10px;
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    text-align: left;
    border-bottom: 2px solid #001a3d;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Clean White Backgrounds */
.txn-table tbody td {
    padding: 10px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    background: #ffffff;
    font-size: 13px;
    color: #1e293b;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Light Blue Hover */
.txn-table tbody tr:hover td {
    background: #eff6ff;
}

/* Plain Text Badges */
.txn-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    background: transparent;
    border: 1px solid;
}

.txn-badge-paid       { color: #166534; border-color: #166534; }
.txn-badge-partial    { color: #854d0e; border-color: #854d0e; }
.txn-badge-unpaid     { color: #991b1b; border-color: #991b1b; }
.txn-badge-pending    { color: #64748b; border-color: #64748b; }
.txn-badge-approved   { color: #065f46; border-color: #065f46; }
.txn-badge-rejected   { color: #991b1b; border-color: #991b1b; }
.txn-badge-completed  { color: #166534; border-color: #166534; }
.txn-badge-inprogress { color: #1d4ed8; border-color: #1d4ed8; }

/* Standardized Action Buttons */
.txn-btn {
    padding: 6px 12px;
    font-size: 13px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transition: background 0.2s ease;
}

.txn-btn-primary   { background: #002F70; color: #fff; }
.txn-btn-success   { background: #16a34a; color: #fff; }
.txn-btn-secondary { background: #6b7280; color: #fff; }
.txn-btn-danger    { background: #dc2626; color: #fff; }

.txn-btn-primary:hover   { background: #001a3d; }
.txn-btn-success:hover   { background: #15803d; }
.txn-btn-secondary:hover { background: #4b5563; }
.txn-btn-danger:hover    { background: #b91c1c; }
```

---

**Status**: Ready for implementation across all transaction modules.
