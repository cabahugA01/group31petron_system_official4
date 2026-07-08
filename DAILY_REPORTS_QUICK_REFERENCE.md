# Daily Reports - Quick Reference Card

## 🚀 Quick Start (3 Steps)

### Method 1: Via Sidebar
1. Click **Reports** in sidebar
2. Click **Daily Reports** to expand
3. Select your report type

### Method 2: Via Index Page
1. Go to `/reports/daily_reports_index.php`
2. Pick a date
3. Click a report card

---

## 📊 Available Reports

| Report | Time Range | What's Included |
|--------|-----------|----------------|
| **Shift 1 Fuel** | 6 AM - 2 PM | Fuel sales, meter readings, payments, inventory |
| **Shift 2 Fuel** | 2 PM - 10 PM | Fuel sales, meter readings, payments, inventory |
| **24-Hour Fuel** | Full Day | Combined fuel sales for entire day |
| **Merchandise/Service** | Full Day | Product sales + service income |

---

## 🔗 Direct Links

```
Shift 1:     /reports/staff_shift_fuel_report.php?shift=shift1
Shift 2:     /reports/staff_shift_fuel_report.php?shift=shift2
24-Hour:     /reports/staff_shift_fuel_report.php?shift=24hour
Merch/Svc:   /reports/staff_daily_merchandise_service_report.php
```

---

## 🖨️ How to Print

1. Open any report
2. Click **Print Report** button (top-right corner)
3. Select printer
4. Click Print

**Tip:** To save as PDF, choose "Save as PDF" in print dialog

---

## 📅 Change Report Date

### In URL
Add `?report_date=YYYY-MM-DD` to any report URL

**Example:**
```
/reports/staff_shift_fuel_report.php?shift=shift1&report_date=2026-07-04
```

### In Index Page
Use the date picker and click "Load Reports"

---

## 🔢 Understanding the Numbers

### Fuel Report Formula
```
Liters Sold = Ending Meter - Beginning Meter - Calibration
Amount = Liters Sold × Price Per Liter
```

### Payment Total
Sum of all payment methods should equal total sales

### Inventory
```
Beginning Stock = Ending Stock + Fuel Sold - Deliveries
```

---

## ⚠️ Common Issues

| Problem | Solution |
|---------|----------|
| **No data showing** | Check if transactions exist for selected date |
| **Wrong station data** | Verify you're logged into correct station |
| **Print cuts off** | Use landscape orientation in print settings |
| **Can't access** | Contact manager to check your permissions |

---

## 💡 Pro Tips

✅ **Generate reports at end of shift** for accuracy  
✅ **Print to PDF** to keep digital records  
✅ **Use 24-Hour report** for daily summaries  
✅ **Compare shifts** to identify peak hours  
✅ **Check payment totals** match physical cash  

---

## 📞 Need Help?

- **Manager:** Ask your station manager
- **IT Support:** Call IT helpdesk
- **Guide:** Read `DAILY_REPORTS_GUIDE.md` for detailed instructions

---

## 🎯 Checklist: End of Shift

- [ ] Open your shift report (Shift 1 or Shift 2)
- [ ] Verify fuel sales match meter readings
- [ ] Check payment breakdown totals
- [ ] Print report for manager review
- [ ] File printed report in shift folder
- [ ] Note any discrepancies in remarks

---

**Quick Reference v1.0** | Last Updated: July 8, 2026
