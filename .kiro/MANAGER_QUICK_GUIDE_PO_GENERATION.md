# Manager Quick Guide - Purchase Order Generation

**For:** Station Managers  
**Feature:** Generate Purchase Orders from Stock Requests  
**Updated:** June 4, 2026

---

## 🎯 What's New?

You can now generate Purchase Orders directly from validated stock requests with just 2 clicks!

**Before:** Request → Validate → Manual PO creation → Submit  
**Now:** Request → Validate → Click "Generate PO" → Done! ✨

---

## 📍 Where to Find It

1. Login as Manager
2. Go to **Inventory** menu
3. Click **"Stock Request Validation"**
4. Look for the **"Merchandise Stock Requests"** section (purple header)

**Direct URL:** `manager_fuel_stock_requests.php`

---

## 📊 Dashboard Overview

When you open the page, you'll see:

```
┌─────────────────────────────────────────────────┐
│  📦 Summary Cards                                │
│  ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐  │
│  │ Total  │ │Pending │ │Approved│ │ Ready  │  │
│  │   15   │ │   3    │ │   10   │ │ for PO │  │
│  └────────┘ └────────┘ └────────┘ │   2    │  │
│                                    └────────┘  │
└─────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────┐
│  📦 MERCHANDISE STOCK REQUESTS (Purple)          │
│  • Validated requests ready for PO               │
│  • Shows [Generate PO] button                    │
└─────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────┐
│  ⛽ FUEL STOCK REQUESTS (Red)                    │
│  • Pending fuel requests                         │
│  • Shows [Approve] / [Reject] buttons            │
└─────────────────────────────────────────────────┘
```

---

## 🔄 Complete Workflow

### Step 1: Staff Submits Request

Staff sees low stock items and clicks "Stock Request" button.

**What happens:**
- Request created with status: **Pending**
- You get notified
- Request appears in your "Pending" list

---

### Step 2: You Validate Request

1. Review the request details:
   - Item name and SKU
   - Current stock level
   - Requested quantity
   
2. Decide:
   - ✅ **Approve** - Set status to "Validated"
   - ❌ **Reject** - Provide reason

**To Approve:**
- Click **[Approve]** button
- Enter approved quantity (can adjust)
- Add notes (optional)
- Click **"Confirm Approve"**

**Result:**
- Status changes to: **Validated**
- Request moves to "Ready for PO" section
- "Generate PO" button appears

---

### Step 3: Generate Purchase Order (NEW!)

Once validated, the request shows in the **Merchandise Stock Requests** section.

**To Generate PO:**

1. Find the validated request (blue "Validated" badge)
2. Click the **[Generate PO]** button (purple)
3. Modal popup opens showing:
   - Item name
   - Approved quantity
   - Info about Admin validation
4. Review the details
5. Click **"Generate PO"** button in the modal
6. Wait 2-3 seconds

**Success!** 🎉

You'll see:
- Success message: "✓ Purchase Order PO-YYYYMMDD-SR#### generated successfully!"
- Button changes to: "✓ PO: PO-YYYYMMDD-SR####"
- Request is now linked to the PO

---

### Step 4: What Happens Next?

**Automatically:**
- PO is created in the system
- Status: "Pending Admin Validation"
- Linked to the stock request
- Admin gets notification

**Admin Reviews:**
- Checks PO details
- Approves the PO
- Prints the PO
- Status becomes "Official"

**Staff Sees:**
- Expected Deliveries tab updated
- Can see incoming stock

**Delivery Arrives:**
- Staff encodes actual delivery
- You validate the delivery
- Admin finalizes
- Inventory updated automatically

---

## 💡 Tips & Best Practices

### ✅ DO:

1. **Review Before Approving**
   - Check current stock levels
   - Verify requested quantity makes sense
   - Consider budget constraints
   
2. **Adjust Quantities If Needed**
   - You can change the approved quantity
   - System logs both requested and approved amounts
   
3. **Add Notes**
   - Helps Admin understand your decision
   - Useful for audit trail
   
4. **Generate PO Promptly**
   - Once validated, generate PO soon
   - Faster PO = Faster delivery
   
5. **Check PO Number**
   - Format should be: PO-YYYYMMDD-SR####
   - Example: PO-20260604-SR0101

### ❌ DON'T:

1. **Don't Click "Generate PO" Twice**
   - System prevents duplicates
   - Button disappears after generation
   
2. **Don't Approve Excessive Quantities**
   - Check budget and storage space
   - Consult with Admin if unsure
   
3. **Don't Reject Without Reason**
   - Always provide clear rejection notes
   - Helps Staff understand why
   
4. **Don't Generate PO for Rejected Requests**
   - Only validated requests show the button
   - Rejected requests don't create POs

---

## 🔍 What You'll See

### Validated Request (Ready for PO):
```
┌────────────────────────────────────────────────────┐
│ #101 │ Engine Oil │ SKU: OIL-001 │ Category: Oils │
│      │ Stock: 5   │ Status: Validated ✓            │
│      │ Requested: 15 │ Approved: 15                │
│      │ [Generate PO] ← Click this!                 │
└────────────────────────────────────────────────────┘
```

### After PO Generated:
```
┌────────────────────────────────────────────────────┐
│ #101 │ Engine Oil │ SKU: OIL-001 │ Category: Oils │
│      │ Stock: 5   │ Status: Validated ✓            │
│      │ Requested: 15 │ Approved: 15                │
│      │ ✓ PO: PO-20260604-SR0101 ← PO Created!     │
└────────────────────────────────────────────────────┘
```

---

## 📱 Modal Details

When you click "Generate PO", you'll see:

```
╔════════════════════════════════════════════╗
║  📄 Generate Purchase Order                ║
╠════════════════════════════════════════════╣
║                                            ║
║  Item Name: Engine Oil                     ║
║                                            ║
║  ┌──────────────────────────────────────┐ ║
║  │   Approved Quantity                   │ ║
║  │         15                            │ ║
║  └──────────────────────────────────────┘ ║
║                                            ║
║  ℹ️  This will create a Purchase Order     ║
║     with status "Pending Admin             ║
║     Validation"                            ║
║                                            ║
║  [Generate PO]  [Cancel]                   ║
╚════════════════════════════════════════════╝
```

**Note:** You CANNOT edit the quantity here. If you need to change it:
1. Close the modal
2. Cancel the validation
3. Re-approve with correct quantity

---

## ⚠️ Common Issues & Solutions

### Issue: "Generate PO" button doesn't appear
**Solutions:**
- Check if request status is "Validated" (not "Pending")
- Refresh the page
- Check if PO already exists for this request

### Issue: "Purchase Order already exists" error
**Solution:**
- This request already has a PO
- Look for the PO number instead of button
- Cannot create duplicate POs

### Issue: Modal doesn't open
**Solutions:**
- Check browser console for errors
- Try different browser (Chrome, Firefox, Edge)
- Clear browser cache
- Contact IT support

### Issue: PO number not showing after generation
**Solution:**
- Refresh the page
- Check database directly
- Contact IT support

### Issue: Wrong quantity approved
**Solution:**
- Cannot edit PO after generation
- Contact Admin to reject the PO
- Create new stock request with correct quantity

---

## 📊 Tracking & Reporting

### View Your PO History:
1. Go to **Purchase Orders** page
2. Filter by "Created By: Your Name"
3. See all POs you generated

### Check Request Status:
1. Each request shows current status
2. Color-coded badges:
   - 🟡 Yellow = Pending
   - 🔵 Blue = Validated
   - 🟢 Green = Completed
   - 🔴 Red = Rejected

### Summary Cards:
- **Total Requests** - All requests for your station
- **Pending** - Awaiting your validation
- **Approved** - Validated by you
- **Ready for PO** - Validated, no PO yet

---

## 🎓 Training Resources

### Video Tutorials:
- [ ] "Stock Request Validation" (5 min)
- [ ] "Generating Purchase Orders" (3 min)
- [ ] "Delivery Validation" (7 min)

### Documentation:
- Complete Inventory Module Guide
- PO Generation Testing Guide
- Visual Workflow Diagrams

### Support:
- Help Desk: [Contact Info]
- Email: [Support Email]
- Phone: [Support Number]

---

## 📝 Quick Checklist

Daily tasks:

- [ ] Check "Pending" count in summary card
- [ ] Review new stock requests
- [ ] Validate requests (approve/reject)
- [ ] Generate POs for validated requests
- [ ] Review pending deliveries
- [ ] Validate received deliveries

Weekly tasks:

- [ ] Review PO history
- [ ] Check completed requests
- [ ] Analyze stock patterns
- [ ] Report issues to Admin

---

## 🚀 Benefits for You

### Time Savings:
- ⏰ **Before:** 10-15 minutes per PO (manual)
- ⏰ **Now:** 30 seconds per PO (automated)
- 💪 **Result:** 95% faster!

### Accuracy:
- ✅ Auto-populated data (no typos)
- ✅ Linked to request (full traceability)
- ✅ Duplicate prevention (no mistakes)

### Visibility:
- 👁️ See all requests in one place
- 👁️ Track status in real-time
- 👁️ Complete audit trail

---

## 📞 Need Help?

### Quick Support:
- **In-app Help:** Click "?" icon
- **Email:** support@example.com
- **Phone:** 1-800-SUPPORT
- **Chat:** Available 9 AM - 5 PM

### Emergency:
- **Critical Issues:** Call extension 911
- **After Hours:** Email with "URGENT" in subject

---

## 🎯 Remember:

1. ✅ Validate requests promptly
2. ✅ Generate POs for validated requests
3. ✅ Add notes for clarity
4. ✅ Review before generating
5. ✅ Check PO number after generation

**You're the key link between Staff requests and Admin approval!**

---

**Last Updated:** June 4, 2026  
**Version:** 1.0  
**For Questions:** Contact IT Support
