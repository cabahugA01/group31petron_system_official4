# 📋 ADMIN DAILY MERCHANDISE & SERVICE SALES REPORT - QUICK REFERENCE

## 🔗 How to Access
```
Login as Admin → Reports → Merchandise & Service Sales Tab
URL: http://localhost/group31petron_system_official4/public/admin_reports.php?section=merchandise_service
```

---

## 📊 The 8 Sections

### 1️⃣ MERCHANDISE SALES
**What:** All merchandise transactions for the period  
**Shows:** Receipt No., Customer, Category, Product, Qty, Price, Amount, Staff  
**Total:** Total Merchandise Sales

### 2️⃣ JOB ORDER / SERVICE SALES
**What:** All completed service/job orders  
**Shows:** JO No., Customer, Vehicle, Service Type, Labor, Parts, Total, Mechanic, Staff  
**Total:** Total Service Income

### 3️⃣ MERCHANDISE PRODUCTS USED AS JOB ORDER PARTS
**What:** Products from inventory used in services  
**Shows:** JO No., Customer, Product, Category, Qty Used, Price, Cost  
**Total:** Total Parts Used & Total Parts Cost

### 4️⃣ PAYMENT BREAKDOWN
**What:** Payment methods summary  
**Shows:** Method (Cash/GCash/Card/Charge), Transaction Count, Amount

### 5️⃣ 🔴 STAFF PERFORMANCE (ADMIN ONLY)
**What:** Individual staff productivity  
**Shows:** Staff Name, Merch Transactions, Job Orders, Total Sales, Collection  
**Why:** Monitor staff performance

### 6️⃣ 🔴 INVENTORY IMPACT SUMMARY (ADMIN ONLY)
**What:** Stock movement verification  
**Shows:** Product, Beginning Stock, Sold, Used in JO, Ending Stock  
**Why:** Verify inventory accuracy

### 7️⃣ DAILY COLLECTION SUMMARY
**What:** Financial summary  
**Shows:** Merchandise Sales, Labor Income, Parts, Gross, Discounts, Net Collection

### 8️⃣ 🔴 TRANSACTION AUDIT SUMMARY (ADMIN ONLY)
**What:** Transaction compliance tracking  
**Shows:** Total Transactions, Cancelled, Voided, Refunded  
**Why:** Auditing purposes

---

## 🎯 Key Features

### 🔴 Admin-Only Sections (NOT in Manager Report)
- **Section 5:** Staff Performance
- **Section 6:** Inventory Impact Summary
- **Section 8:** Transaction Audit Summary

### 📅 Date Filtering
- Single date or date range
- Defaults to current day
- Format: YYYY-MM-DD

### 📤 Export Options
- **Excel:** Multi-sheet workbook
- **CSV:** Comma-separated data
- **Print:** Optimized for legal paper

### 🎨 Visual Indicators
- 🟥 Red background = Staff Performance
- 🟦 Blue background = Inventory Impact
- 🟧 Orange background = Audit Summary
- Labels: "(ADMIN ONLY)"

---

## ⚡ Quick Actions

### View Today's Report
```
Just navigate to admin_reports.php
Default shows today's data
```

### View Specific Date Range
```
1. Select date_from
2. Select date_to
3. Click "Apply"
```

### Export to Excel
```
1. Set date range
2. Click "Export Excel"
3. File downloads automatically
```

### Print Report
```
1. Set date range
2. Click "Print Report"
3. Opens print preview
4. Click Print
```

---

## 🆚 vs Manager Report

| What Managers See | What Admins See (Extra) |
|-------------------|-------------------------|
| Merchandise Sales | ✅ Same |
| Service Sales | ✅ Same |
| Parts Used | ✅ Same |
| Payment Breakdown | ✅ Same |
| Collection Summary | ✅ Same |
| ❌ Can't see | ✅ **Staff Performance** |
| ❌ Can't see | ✅ **Inventory Impact** |
| ❌ Can't see | ✅ **Audit Summary** |

**Admin gets 3 extra oversight sections!**

---

## 🔍 What Each Section Tells You

### 📦 Merchandise Sales
"How much merchandise did we sell and who sold it?"

### 🔧 Service Sales
"How much service income did we generate?"

### ⚙️ Parts Used
"Which merchandise items were used as parts in services?"

### 💳 Payment Breakdown
"How did customers pay? (Cash, GCash, Card, Charge)"

### 👥 Staff Performance (ADMIN)
"Which staff member performed best? Who needs coaching?"

### 📊 Inventory Impact (ADMIN)
"Is our stock count correct? Any discrepancies?"

### 💰 Collection Summary
"What's our bottom line for the day?"

### 🔍 Audit Summary (ADMIN)
"Any cancelled, voided, or refunded transactions?"

---

## 📝 Common Use Cases

### Daily Operations Review
```
→ Check today's sales (default view)
→ Review staff performance
→ Verify inventory movements
→ Check for anomalies in audit section
```

### Weekly Performance Analysis
```
→ Set date range: Monday to Sunday
→ Export to Excel
→ Analyze staff productivity trends
→ Review inventory accuracy
```

### Month-End Reporting
```
→ Set date range: 1st to 30th/31st
→ Export to Excel
→ Review all 8 sections
→ Prepare management reports
```

### Audit Investigation
```
→ Check Section 8: Audit Summary
→ Note cancelled/voided counts
→ Cross-reference with Section 5: Staff Performance
→ Investigate discrepancies
```

---

## 🚨 Troubleshooting

### "Access Denied"
→ You must be logged in as Admin

### "No Data"
→ Check if date range has transactions
→ Verify station has activity

### Export Not Working
→ Refresh page
→ Check browser allows downloads

### Print Layout Off
→ Use Chrome/Edge browser
→ Set paper to Legal size
→ Set orientation to Portrait

---

## 💡 Tips

### Best Practices
✅ Review daily for oversight  
✅ Export weekly for records  
✅ Monitor staff performance trends  
✅ Check inventory accuracy regularly  
✅ Investigate audit anomalies immediately

### Time Savers
⚡ Bookmark with default params  
⚡ Use keyboard shortcuts for date picker  
⚡ Export before month-end close  
⚡ Print for physical filing

---

## 📞 Quick Help

### Files Location
```
Report: public/reports/admin_daily_merchandise_service_report.php
Main Page: public/admin_reports.php
```

### Parameters
```
?section=merchandise_service
&date_from=YYYY-MM-DD
&date_to=YYYY-MM-DD
```

### Role Required
```
Admin only (role = 'admin')
```

---

## ✅ Checklist for Daily Use

**Morning Routine:**
- [ ] Login as Admin
- [ ] Open Merchandise & Service Report
- [ ] Review yesterday's data
- [ ] Check staff performance
- [ ] Verify inventory accuracy
- [ ] Note any audit anomalies

**End of Day:**
- [ ] Review today's sales
- [ ] Export to Excel
- [ ] Save to records folder
- [ ] Flag any issues for follow-up

**Weekly:**
- [ ] Run 7-day report
- [ ] Analyze trends
- [ ] Report to management
- [ ] Archive exports

---

**Remember:**
This report gives you **3 powerful sections** that managers don't see:
1. 👥 **Staff Performance** - Who's doing well?
2. 📊 **Inventory Impact** - Is stock accurate?
3. 🔍 **Audit Summary** - Any red flags?

Use them to maintain oversight and ensure station operations are running smoothly! 🎯

---

**Last Updated:** July 4, 2026  
**Version:** 1.0  
**Status:** ✅ Ready for Use
