# STAFF CUSTOMER MODULE - COMPLETE SPECIFICATION

**Date:** June 28, 2026  
**Module:** Customer Management (Staff Level)  
**System:** Petron Station Management System

---

## 📂 MODULE STRUCTURE

```
Customers
├── Add New Customer
├── Customer List
└── Customer History
```

---

## 1. ADD NEW CUSTOMER

### Purpose
Register new customers with basic information and optional document uploads.

### Form Fields

#### Basic Information (Required)
- **Customer ID:** Auto-generated (Format: `CUST-YYYYMMDD-####`)
- **First Name:** Text input (required) *
- **Middle Name:** Text input (optional)
- **Last Name:** Text input (required) *
- **Contact Number:** Text input (required) * (Format: 09XX-XXX-XXXX)
- **Address:** Textarea (required) *

#### Customer Type (Required)
- Radio buttons / Dropdown:
  - ☐ Walk-in
  - ☐ Regular
  - ☐ Fleet / Company

#### Optional Document Uploads
1. **Government ID**
   - ID Type: Dropdown (Driver's License, SSS, UMID, Passport, Voter's ID, etc.)
   - ID Image: File upload (JPG, PNG, PDF - max 5MB)
   
2. **Certificate of Registration (CR)**
   - File upload (PDF - max 5MB)
   - Only for Fleet/Company customers

### Security Rules
- ✅ Staff CAN upload documents
- ❌ Staff CANNOT view/open uploaded documents after saving
- ❌ Staff CANNOT download uploaded documents
- ✅ Documents stored in secure folder with encrypted filenames
- ✅ Access logged in `customer_documents_access_log`

### Buttons
- 💾 **Save Customer** - Submit and redirect to Customer List
- ❌ **Cancel** - Clear form
- 🔙 **Back to Customer List** - Return without saving

### Validation Rules
- First Name: Required, 2-100 characters
- Last Name: Required, 2-100 characters
- Contact Number: Required, valid phone format
- Address: Required, minimum 10 characters
- Customer Type: Required selection
- ID Image: Optional, max 5MB, JPG/PNG/PDF only
- CR Document: Optional, max 5MB, PDF only

---

## 2. CUSTOMER LIST

### Summary Cards

```
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│ 👥 TOTAL         │  │ 🆕 NEW TODAY     │  │ ⭐ REGULAR       │  │ 🏢 FLEET         │
│    CUSTOMERS     │  │                  │  │    CUSTOMERS     │  │    CUSTOMERS     │
│    1,234         │  │    15            │  │    456           │  │    89            │
└──────────────────┘  └──────────────────┘  └──────────────────┘  └──────────────────┘
```

### Filters

| Search Customer | Customer Type | Status | Date Registered |
|---|---|---|---|
| 🔍 Search by name/contact/ID | All Types / Walk-in / Regular / Fleet | Active / Inactive | From: [date] To: [date] |

**Action Buttons:**
- 🔍 **Search**
- 🔄 **Reset Filters**
- 📥 **Export** (PDF / Excel / CSV)

### Customer Table

| Customer ID | Customer Name | Contact | Type | Last Visit | Status | Actions |
|---|---|---|---|---|---|---|
| CUST-20260628-0001 | Juan Dela Cruz | 0917-123-4567 | Regular | 2026-06-27 | 🟢 Active | 👁 ✏ 🖨 |
| CUST-20260627-0045 | Maria Santos | 0918-234-5678 | Walk-in | 2026-06-26 | 🟢 Active | 👁 ✏ 🖨 |
| CUST-20260625-0032 | ABC Transport Co. | 0919-345-6789 | Fleet | 2026-06-25 | 🟢 Active | 👁 ✏ 🖨 |

### Actions
- 👁 **View** - View customer profile (basic info + transactions)
- ✏ **Edit** - Edit basic information only (name, contact, address)
- 🖨 **Print** - Generate printable customer information

### Export Options
- **PDF** - Formatted customer list with company header
- **Excel** - Spreadsheet with all customer data
- **CSV** - Raw data for import to other systems

---

## 3. CUSTOMER PROFILE

### Basic Information Card

```
╔════════════════════════════════════════════════════════════╗
║  CUSTOMER PROFILE                                          ║
╠════════════════════════════════════════════════════════════╣
║  Customer ID:      CUST-20260628-0001                      ║
║  Customer Name:    Juan Dela Cruz                          ║
║  Contact Number:   0917-123-4567                           ║
║  Address:          123 Main St, Cagayan de Oro City        ║
║  Customer Type:    ⭐ Regular                              ║
║  Date Registered:  June 28, 2026                           ║
║  Status:           🟢 Active                               ║
╚════════════════════════════════════════════════════════════╝
```

### Transaction Summary

```
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│ ⛽ FUEL          │  │ 🛒 MERCHANDISE   │  │ 🔧 SERVICE       │  │ 📅 LAST          │
│    TRANSACTIONS  │  │    TRANSACTIONS  │  │    TRANSACTIONS  │  │    TRANSACTION   │
│    45            │  │    23            │  │    12            │  │    June 27, 2026 │
└──────────────────┘  └──────────────────┘  └──────────────────┘  └──────────────────┘
```

### Recent Transactions

| Date | Transaction No. | Module | Amount | Status |
|---|---|---|---|---|
| 2026-06-27 | FUEL20260627-001 | ⛽ Fuel | ₱1,234.50 | ✓ Completed |
| 2026-06-26 | SALE20260626-045 | 🛒 Merchandise | ₱456.00 | ✓ Completed |
| 2026-06-25 | SERV20260625-012 | 🔧 Service | ₱2,500.00 | ✓ Completed |

### Buttons
- 🔙 **Back to Customer List**
- ✏ **Edit Customer**
- 🖨 **Print Profile**

---

## 4. CUSTOMER HISTORY

### Purpose
View all customer transactions across modules (Fuel, Merchandise, Service).

### Summary Cards

```
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│ 📊 TOTAL         │  │ ⛽ FUEL          │  │ 🛒 MERCHANDISE   │  │ 🔧 SERVICE       │
│    TRANSACTIONS  │  │    TRANSACTIONS  │  │    TRANSACTIONS  │  │    TRANSACTIONS  │
│    1,456         │  │    678           │  │    456           │  │    322           │
└──────────────────┘  └──────────────────┘  └──────────────────┘  └──────────────────┘
```

### Filters

| Customer | Transaction Type | Date Range |
|---|---|---|
| 🔍 Search by name/ID | All / Fuel / Merchandise / Service | From: [date] To: [date] |

### Transaction Table

| Date | Customer | Module | Reference No. | Amount | Processed By | Actions |
|---|---|---|---|---|---|---|
| 2026-06-27 | Juan Dela Cruz | ⛽ Fuel | FUEL20260627-001 | ₱1,234.50 | Maria Santos | 👁 🖨 |
| 2026-06-26 | Maria Santos | 🛒 Merch | SALE20260626-045 | ₱456.00 | Pedro Garcia | 👁 🖨 |
| 2026-06-25 | ABC Transport | 🔧 Service | SERV20260625-012 | ₱2,500.00 | Juan Reyes | 👁 🖨 |

### Actions
- 👁 **View Transaction** - View full transaction details
- 🖨 **Print** - Print individual transaction

### Export Options
- **PDF** - Transaction history report
- **Excel** - Spreadsheet with transaction data
- **CSV** - Raw transaction data

---

## 🔐 STAFF PERMISSIONS

### ✅ ALLOWED ACTIONS

| Permission | Description |
|---|---|
| ✅ Register Customer | Create new customer record |
| ✅ Edit Basic Information | Update name, contact, address |
| ✅ View Basic Information | View customer profile |
| ✅ View Customer Transactions | View transaction history |
| ✅ Print Customer Information | Generate printable reports |
| ✅ Upload Documents | Upload ID and CR during registration |
| ✅ Search Customers | Search and filter customer list |
| ✅ Export Customer List | Export to PDF/Excel/CSV |

### ❌ RESTRICTED ACTIONS

| Permission | Restricted To |
|---|---|
| ❌ Delete Customer | Manager / Admin only |
| ❌ View Uploaded ID | Manager / Admin only |
| ❌ Download CR | Manager / Admin only |
| ❌ View Document Files | Manager / Admin only |
| ❌ Balance Adjustment | Manager / Admin only |
| ❌ Credit Approval | Manager / Admin only |
| ❌ Change Customer Status | Manager / Admin only |
| ❌ Merge Customer Records | Admin only |

---

## 📁 FILE UPLOAD SPECIFICATIONS

### Storage Location
```
/uploads/customer_documents/
├── gov_ids/
│   └── {customer_id}_{timestamp}_{hash}.{ext}
└── cr_documents/
    └── {customer_id}_{timestamp}_{hash}.pdf
```

### Security Measures
1. **Encrypted Filenames:** Original filename + timestamp + hash
2. **Access Control:** Staff upload-only, Manager/Admin can view
3. **Audit Log:** All document access logged
4. **Secure Storage:** Outside web root or protected by .htaccess
5. **File Validation:** Check file type, size, and content

### Example Filename
```
Original: drivers_license.jpg
Stored as: CUST-20260628-0001_1719561600_a3f8d2e1.jpg
```

---

## 🎨 UI/UX GUIDELINES

### Color Scheme
- **Primary:** #002F70 (Petron Blue)
- **Success:** #16a34a (Green)
- **Warning:** #f59e0b (Orange)
- **Danger:** #dc2626 (Red)
- **Info:** #0284c7 (Light Blue)

### Icons
- 👥 Customer
- 🆕 New
- ⭐ Regular
- 🏢 Fleet/Company
- 👁 View
- ✏ Edit
- 🖨 Print
- 💾 Save
- ❌ Cancel
- 🔙 Back
- 🔍 Search
- 🔄 Reset
- 📥 Export
- ⛽ Fuel
- 🛒 Merchandise
- 🔧 Service

### Responsive Design
- Mobile-first approach
- Collapsible sidebar on mobile
- Touch-friendly buttons (min 44x44px)
- Horizontal scroll for tables on mobile

---

## 🔧 TECHNICAL IMPLEMENTATION

### Database Tables
1. **customers** - Main customer data
2. **customer_transactions** - Transaction tracking
3. **customer_documents_access_log** - Document access audit

### PHP Files to Create
1. `staff_customer_add.php` - Add new customer
2. `staff_customer_list.php` - Customer list with filters
3. `staff_customer_profile.php` - View customer profile
4. `staff_customer_history.php` - Transaction history
5. `staff_customer_operations.php` - Backend operations (add/edit/delete)
6. `api/customers.php` - API endpoints for AJAX operations

### JavaScript Files
1. `customer_management.js` - Main customer module JS
2. `customer_validation.js` - Form validation
3. `customer_filters.js` - List filtering and search

---

## 📊 REPORTS & EXPORTS

### Customer List Report
- Header: Company logo, report title, date range
- Content: Customer table with all fields
- Footer: Total count, generated by, timestamp

### Customer Profile Report
- Customer information card
- Transaction summary
- Recent transaction list
- QR code for customer ID (optional)

### Transaction History Report
- Date range filter applied
- Customer information
- Transaction list grouped by type
- Total amounts per type
- Grand total

---

## 🚀 IMPLEMENTATION CHECKLIST

- [x] Database schema created
- [ ] SQL migration file created
- [ ] Add New Customer page
- [ ] Customer List page
- [ ] Customer Profile page
- [ ] Customer History page
- [ ] Backend operations file
- [ ] API endpoints
- [ ] JavaScript modules
- [ ] File upload handler
- [ ] Document security implementation
- [ ] Permission checks
- [ ] Audit logging
- [ ] Export functions (PDF/Excel/CSV)
- [ ] Print templates
- [ ] Form validation
- [ ] Error handling
- [ ] Testing with sample data
- [ ] User acceptance testing
- [ ] Documentation

---

**Document Version:** 1.0  
**Last Updated:** June 28, 2026  
**Status:** Ready for Implementation
