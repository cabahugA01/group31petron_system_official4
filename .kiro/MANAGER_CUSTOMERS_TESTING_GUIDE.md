# Manager Customer Management - Testing Guide

## Quick Test Scenarios

### 🧪 Test 1: Add New Customer (Happy Path)
**Steps:**
1. Login as Manager
2. Navigate to Customers → Add New Customer (from sidebar)
3. Fill in:
   - First Name: "Juan"
   - Last Name: "Dela Cruz"
   - Contact: "09171234567"
   - Address: "123 Main St, Quezon City"
   - Credit Limit: "5000"
   - Suki Status: "Suki"
   - Payment Terms: "15days"
   - ID Type: "Driver's License"
   - Upload a sample ID image
4. Click "Save Customer"

**Expected:**
- ✅ Success message: "Customer 'Juan Dela Cruz' added successfully"
- ✅ Redirected to Customer List
- ✅ New customer appears in list with all fields populated
- ✅ Suki status shows as "Suki" in orange
- ✅ Audit log created

---

### 🧪 Test 2: Add Minimal Customer
**Steps:**
1. Navigate to Add New Customer
2. Fill ONLY:
   - First Name: "Maria"
   - Last Name: "Santos"
3. Leave all other fields empty/default
4. Click "Save Customer"

**Expected:**
- ✅ Customer saved successfully
- ✅ Defaults applied:
  - Credit Limit: ₱0.00
  - Suki Status: Regular (gray)
  - Payment Terms: Cash
  - Balance: ₱0.00

---

### 🧪 Test 3: Edit Customer
**Steps:**
1. Go to Customer List
2. Click "Edit" on any customer
3. Change:
   - Suki Status to "VIP"
   - Credit Limit to "10000"
4. Click "Save Changes"

**Expected:**
- ✅ Success message
- ✅ Customer list updated
- ✅ Suki status now shows "VIP" in purple
- ✅ Credit limit updated to ₱10,000.00

---

### 🧪 Test 4: Search Functionality
**Steps:**
1. Go to Customer List
2. Type customer name in search box
3. Type contact number
4. Type ID type

**Expected:**
- ✅ Table filters in real-time
- ✅ Shows matching rows only
- ✅ Case-insensitive search

---

### 🧪 Test 5: View Customer Balances
**Steps:**
1. Navigate to Customer Balances (from sidebar)
2. Review summary cards
3. Review customer table

**Expected:**
- ✅ Summary cards show:
  - Total Credit Limit
  - Total Outstanding
  - Available Credit
- ✅ Table shows utilization bars
- ✅ Over-limit customers highlighted in red
- ✅ Near-limit (80%+) highlighted in orange

---

### 🧪 Test 6: Record Payment (Normal)
**Steps:**
1. In Customer Balances section
2. Click "Record Payment" on a customer with balance
3. Enter:
   - Amount: Less than outstanding balance
   - Reference: "Cash payment OR-12345"
4. Click "Record Payment"

**Expected:**
- ✅ Success message with new balance
- ✅ Page reloads
- ✅ Balance updated in table
- ✅ Utilization percentage updated
- ✅ Audit log created

---

### 🧪 Test 7: Record Payment (Overpayment)
**Steps:**
1. Click "Record Payment"
2. Enter amount GREATER than outstanding balance
3. Enter reference
4. Click "Record Payment"

**Expected:**
- ✅ Alert: "Overpayment detected! Amount exceeds..."
- ✅ Shows excess amount
- ✅ Asks for confirmation
- ✅ If confirmed: Payment processed, balance set to 0
- ✅ If cancelled: Modal stays open

---

### 🧪 Test 8: Transaction History Filtering
**Steps:**
1. Navigate to Customer History
2. Select a specific customer from dropdown
3. Set date range (e.g., last 30 days)
4. Click "Apply Filters"

**Expected:**
- ✅ Table updates with filtered transactions
- ✅ Shows transaction count
- ✅ All transaction types displayed:
  - Merchandise Sale
  - Job Order
  - Payment
- ✅ Transaction details correct

---

### 🧪 Test 9: Export to CSV
**Steps:**
1. In Customer History
2. Apply filters (optional)
3. Click "Export CSV"

**Expected:**
- ✅ CSV file downloads
- ✅ Filename: `customer_history_YYYY-MM-DD.csv`
- ✅ Contains metadata header:
  - Station name
  - Manager name
  - Export date
  - Date range
  - Customer filter
  - Total transactions
- ✅ Data rows match table display

---

### 🧪 Test 10: File Upload (ID & CR)
**Steps:**
1. Add/Edit customer
2. Upload valid image for ID (JPG, PNG, PDF)
3. Upload valid CR document
4. Save

**Expected:**
- ✅ Files uploaded to `/uploads/customer_ids/`
- ✅ Unique filenames generated
- ✅ "View ID" and "View CR" links appear
- ✅ Links open uploaded files in new tab

---

### 🧪 Test 11: Invalid File Upload
**Steps:**
1. Try to upload .exe file
2. Try to upload .txt file
3. Save customer

**Expected:**
- ✅ Invalid files rejected silently
- ✅ Customer saved without file
- ✅ No error message (by design)

---

### 🧪 Test 12: Form Validation
**Steps:**
1. Try to save customer without First Name
2. Try to save customer without Last Name
3. Try negative credit limit

**Expected:**
- ✅ Browser validation prevents submission
- ✅ Required field messages shown
- ✅ Min value enforced for credit limit

---

### 🧪 Test 13: Empty States
**Steps:**
1. View Customer List with no customers
2. View Customer Balances with no credit customers
3. View History with no transactions

**Expected:**
- ✅ Clean empty state message
- ✅ Icon displayed
- ✅ Helpful text (e.g., "Start adding customers...")

---

### 🧪 Test 14: Color Coding Verification
**Steps:**
1. Check Suki Status colors:
   - Regular customer
   - Suki customer
   - VIP customer
2. Check Balance colors:
   - Customer with positive balance
   - Customer with zero balance
3. Check Credit Usage:
   - Over 100% (over-limit)
   - 80-99% (near-limit)
   - Below 80% (healthy)

**Expected:**
- ✅ VIP: Purple (#9c27b0)
- ✅ Suki: Orange (#ff9800)
- ✅ Regular: Gray (#6c757d)
- ✅ Positive Balance: Red
- ✅ Zero Balance: Green
- ✅ Over-limit Row: Red background
- ✅ Near-limit Row: Orange background

---

### 🧪 Test 15: Navigation Flow
**Steps:**
1. Access each section from sidebar:
   - Add New Customer
   - Customer List
   - Customer Balances
   - Customer History
2. Use "Back to List" button in Edit form
3. Click "Cancel" in Add form

**Expected:**
- ✅ All navigation works correctly
- ✅ No horizontal tabs visible
- ✅ Active section highlighted in sidebar
- ✅ Back/Cancel buttons return to correct page

---

## 🔒 Security Testing

### Test S1: SQL Injection
**Steps:**
1. Try entering: `'; DROP TABLE customers; --` in name field
2. Try in search box

**Expected:**
- ✅ Input treated as literal string
- ✅ No SQL execution
- ✅ Prepared statements prevent injection

### Test S2: XSS Attack
**Steps:**
1. Try entering: `<script>alert('XSS')</script>` in customer name
2. Save and view in list

**Expected:**
- ✅ Script tags displayed as text
- ✅ No JavaScript execution
- ✅ htmlspecialchars() escapes output

### Test S3: File Upload Attack
**Steps:**
1. Try uploading PHP file disguised as image
2. Try uploading file with double extension (.jpg.php)

**Expected:**
- ✅ Only whitelisted extensions accepted
- ✅ Malicious files rejected

---

## 🐛 Edge Case Testing

### Edge 1: Very Long Names
**Input:** 255 character name
**Expected:** ✅ Truncates or handles gracefully

### Edge 2: Special Characters
**Input:** Name with ñ, é, ü, etc.
**Expected:** ✅ Saves and displays correctly (UTF-8)

### Edge 3: Large Credit Limit
**Input:** ₱999,999,999.99
**Expected:** ✅ Handles correctly (DECIMAL 12,2)

### Edge 4: Zero Credit Limit
**Input:** ₱0.00
**Expected:** ✅ Customer won't appear in Balances section

### Edge 5: Duplicate Names
**Input:** Two customers named "Juan Dela Cruz"
**Expected:** ✅ Both saved (no unique constraint)

---

## ✅ Pass/Fail Criteria

**PASS if:**
- All 15 main tests pass
- All security tests pass
- No PHP errors in error log
- No JavaScript console errors
- Data persists correctly in database
- Audit logs created properly

**FAIL if:**
- Any critical functionality broken
- Security vulnerability found
- Data loss occurs
- System crashes or freezes

---

## 📊 Test Report Template

```
Test Date: ____________
Tester: ____________
Browser: ____________
PHP Version: ____________

| Test # | Test Name | Status | Notes |
|--------|-----------|--------|-------|
| 1 | Add Customer (Happy) | ☐ Pass ☐ Fail | |
| 2 | Add Minimal | ☐ Pass ☐ Fail | |
| 3 | Edit Customer | ☐ Pass ☐ Fail | |
| 4 | Search | ☐ Pass ☐ Fail | |
| 5 | View Balances | ☐ Pass ☐ Fail | |
| 6 | Record Payment | ☐ Pass ☐ Fail | |
| 7 | Overpayment | ☐ Pass ☐ Fail | |
| 8 | Filter History | ☐ Pass ☐ Fail | |
| 9 | Export CSV | ☐ Pass ☐ Fail | |
| 10 | File Upload | ☐ Pass ☐ Fail | |
| 11 | Invalid Upload | ☐ Pass ☐ Fail | |
| 12 | Form Validation | ☐ Pass ☐ Fail | |
| 13 | Empty States | ☐ Pass ☐ Fail | |
| 14 | Color Coding | ☐ Pass ☐ Fail | |
| 15 | Navigation | ☐ Pass ☐ Fail | |

Security Tests:
- SQL Injection: ☐ Pass ☐ Fail
- XSS Attack: ☐ Pass ☐ Fail
- File Upload Attack: ☐ Pass ☐ Fail

Overall Result: ☐ PASS ☐ FAIL

Comments:
________________________________
________________________________
________________________________
```

---

**Ready for Testing!** 🚀
