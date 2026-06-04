# Transaction Module - Export Button Design Standards

## 🎨 STANDARDIZED BUTTON SPECIFICATIONS

All Transaction Module export buttons must follow these consistent standards across Staff, Manager, and Admin dashboards.

---

## 📏 BUTTON DIMENSIONS

```css
Width: Auto (min-width: 140px)
Height: 38px (padding: 9px vertical)
Padding: 9px 20px
Border-radius: 8px
Font-size: 12px
Font-weight: 700
Gap (icon-text): 6px
```

---

## 🎨 COLOR STANDARDS

### **Excel Buttons**
- Background: `#16a34a` (Green)
- Text: `#ffffff` (White)
- Icon: `fa-file-excel`
- Hover: `#15803d` (Darker green)

### **CSV Buttons**
- Background: `#16a34a` (Green - same as Excel)
- Text: `#ffffff` (White)
- Icon: `fa-file-csv`
- Hover: `#15803d` (Darker green)

### **PDF Buttons**
- Background: `#dc2626` (Red)
- Text: `#ffffff` (White)
- Icon: `fa-file-pdf`
- Hover: `#b91c1c` (Darker red)

### **Back Buttons** (if needed)
- Background: `#6b7280` (Gray)
- Text: `#ffffff` (White)
- Icon: `fa-arrow-left`
- Hover: `#4b5563` (Darker gray)

---

## 💻 STANDARD BUTTON CODE TEMPLATE

### **Base Button Style**
```html
<button 
  onclick="exportFunction('type','format')" 
  style="
    background:#16a34a;
    color:#fff;
    padding:9px 20px;
    border-radius:8px;
    border:none;
    font-size:12px;
    font-weight:700;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:6px;
    min-width:140px;
    transition:background 0.2s ease;
  "
  onmouseover="this.style.background='#15803d'"
  onmouseout="this.style.background='#16a34a'"
>
  <i class="fas fa-file-excel"></i> Export Excel
</button>
```

---

## 📋 BUTTON LAYOUTS BY MODULE

### **STAFF DASHBOARD - Transaction Module**

**Export Buttons Row**:
- 5 buttons total
- Layout: Horizontal flex row
- Gap: 10px between buttons

**Buttons**:
1. Export Job Orders (Excel) - Green
2. Export Job Orders (CSV) - Green
3. Export Job Orders (PDF) - Red
4. Export Merchandise (Excel) - Green
5. Export Merchandise (CSV) - Green

```html
<!-- Export Buttons Row - STAFF -->
<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;padding-top:18px;border-top:1px solid#e2e8f0">
  <button onclick="exportStaffTransactionData('job_orders','excel')" style="background:#16a34a;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-file-excel"></i> Export JO (Excel)
  </button>
  <button onclick="exportStaffTransactionData('job_orders','csv')" style="background:#16a34a;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-file-csv"></i> Export JO (CSV)
  </button>
  <button onclick="exportStaffTransactionData('job_orders','pdf')" style="background:#dc2626;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
    <i class="fas fa-file-pdf"></i> Export JO (PDF)
  </button>
  <button onclick="exportStaffTransactionData('merchandise','excel')" style="background:#16a34a;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-boxes"></i> Export Merch (Excel)
  </button>
  <button onclick="exportStaffTransactionData('merchandise','csv')" style="background:#16a34a;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-boxes"></i> Export Merch (CSV)
  </button>
</div>
```

---

### **MANAGER DASHBOARD - Transaction Module**

**3 Tabs with Export Buttons per Tab**:

#### **Tab 1: Pending Transactions**
**Buttons**:
1. Export Pending (Excel) - Green
2. Export Pending (CSV) - Green
3. Export Pending (PDF) - Red

```html
<!-- Export Buttons - MANAGER: Pending Transactions Tab -->
<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;padding-top:18px;border-top:1px solid#e2e8f0">
  <button onclick="exportManagerPending('excel')" style="background:#16a34a;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-file-excel"></i> Export Excel
  </button>
  <button onclick="exportManagerPending('csv')" style="background:#16a34a;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-file-csv"></i> Export CSV
  </button>
  <button onclick="exportManagerPending('pdf')" style="background:#dc2626;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
    <i class="fas fa-file-pdf"></i> Export PDF
  </button>
</div>
```

#### **Tab 2: Validated Transactions**
**Buttons**:
1. Export Validated (Excel) - Green
2. Export Validated (CSV) - Green
3. Export Validated (PDF) - Red

```html
<!-- Export Buttons - MANAGER: Validated Transactions Tab -->
<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;padding-top:18px;border-top:1px solid#e2e8f0">
  <button onclick="exportManagerValidated('excel')" style="background:#16a34a;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-file-excel"></i> Export Excel
  </button>
  <button onclick="exportManagerValidated('csv')" style="background:#16a34a;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-file-csv"></i> Export CSV
  </button>
  <button onclick="exportManagerValidated('pdf')" style="background:#dc2626;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
    <i class="fas fa-file-pdf"></i> Export PDF
  </button>
</div>
```

#### **Tab 3: Variance Reports**
**Buttons**:
1. Export Variance (Excel) - Green
2. Export Variance (CSV) - Green
3. Export Compliance (PDF) - Red ⭐ (Special compliance format)

```html
<!-- Export Buttons - MANAGER: Variance Reports Tab -->
<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;padding-top:18px;border-top:1px solid#e2e8f0">
  <button onclick="exportManagerVariance('excel')" style="background:#16a34a;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-file-excel"></i> Export Excel
  </button>
  <button onclick="exportManagerVariance('csv')" style="background:#16a34a;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
    <i class="fas fa-file-csv"></i> Export CSV
  </button>
  <button onclick="exportManagerVariance('pdf')" style="background:#dc2626;color:#fff;padding:9px 20px;border-radius:8px;border:none;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;min-width:140px" onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
    <i class="fas fa-file-pdf"></i> Export Compliance PDF
  </button>
</div>
```

---

### **ADMIN DASHBOARD - Transaction Module** (Phase 3 - Future)

**3 Tabs with Export Buttons per Tab**:

#### **Tab 1: System Overview**
**Buttons**:
1. Export System Report (Excel) - Green
2. Export System Report (CSV) - Green
3. Export Executive Summary (PDF) - Red

#### **Tab 2: Receivables Aging**
**Buttons**:
1. Export Receivables (Excel) - Green
2. Export Receivables (CSV) - Green
3. Export Aging Report (PDF) - Red

#### **Tab 3: Compliance Reports**
**Buttons**:
1. Export Compliance (Excel) - Green
2. Export Compliance (CSV) - Green
3. Export Audit Report (PDF) - Red

---

## 📱 RESPONSIVE BEHAVIOR

### **Desktop (>1024px)**:
- Buttons display in horizontal row
- All buttons visible
- No wrapping

### **Tablet (768px - 1024px)**:
- Buttons wrap to 2-3 rows
- Maintain same size
- Use `flex-wrap: wrap`

### **Mobile (<768px)**:
- Buttons stack vertically or wrap to 2 columns
- Full width buttons (optional)
- Reduced padding: `8px 16px`

```css
/* Responsive Export Buttons */
@media (max-width: 768px) {
  .export-buttons-row button {
    padding: 8px 16px !important;
    font-size: 11px !important;
    min-width: 120px !important;
  }
}
```

---

## ✨ HOVER EFFECTS

All buttons must have hover effects:

```javascript
// On Hover
onmouseover="this.style.background='[DARKER_COLOR]'"
onmouseout="this.style.background='[ORIGINAL_COLOR]'"

// Or using CSS (preferred)
transition: background 0.2s ease;
```

**Color Transitions**:
- Green: `#16a34a` → `#15803d`
- Red: `#dc2626` → `#b91c1c`
- Gray: `#6b7280` → `#4b5563`

---

## 🎯 ICON USAGE

### **File Format Icons**:
- Excel: `<i class="fas fa-file-excel"></i>`
- CSV: `<i class="fas fa-file-csv"></i>`
- PDF: `<i class="fas fa-file-pdf"></i>`

### **Context Icons** (optional):
- Job Orders: `<i class="fas fa-wrench"></i>`
- Merchandise: `<i class="fas fa-boxes"></i>`
- Compliance: `<i class="fas fa-file-contract"></i>`
- Download: `<i class="fas fa-download"></i>`

---

## 📝 BUTTON TEXT STANDARDS

### **Keep It Short & Clear**:
- ✅ Good: "Export Excel", "Export JO (Excel)", "Export Compliance PDF"
- ❌ Avoid: "Download Excel Spreadsheet File", "Export Job Orders to Excel Format"

### **Abbreviations**:
- JO = Job Orders
- Merch = Merchandise
- Txn = Transactions

---

## 🔄 JAVASCRIPT FUNCTION NAMING

### **Consistent Function Names**:
```javascript
// Staff Dashboard
exportStaffTransactionData(type, format)

// Manager Dashboard
exportManagerPending(format)
exportManagerValidated(format)
exportManagerVariance(format)

// Admin Dashboard
exportAdminSystemReport(format)
exportAdminReceivables(format)
exportAdminCompliance(format)
```

### **Function Implementation Template**:
```javascript
function exportManagerPending(format) {
  const endpoint = '../backend/export/export_pending_transactions.php';
  window.location.href = `${endpoint}?format=${format}`;
}
```

---

## ✅ BUTTON CHECKLIST

Use this checklist when adding export buttons:

- [ ] Same size as other buttons (`padding: 9px 20px`)
- [ ] Correct color (Green for Excel/CSV, Red for PDF)
- [ ] Icon included (`fa-file-excel`, `fa-file-csv`, `fa-file-pdf`)
- [ ] Hover effect implemented
- [ ] Min-width set (`min-width: 140px`)
- [ ] Font size 12px, font-weight 700
- [ ] Border-radius 8px
- [ ] Gap between icon and text (6px)
- [ ] Flex layout with gap (10px between buttons)
- [ ] Function name follows convention
- [ ] Text is short and clear
- [ ] Responsive behavior tested

---

## 🎨 VISUAL COMPARISON

```
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│  📊 Export      │ │  📊 Export      │ │  📄 Export      │
│     Excel       │ │     CSV         │ │     PDF         │
│  (Green)        │ │  (Green)        │ │  (Red)          │
└─────────────────┘ └─────────────────┘ └─────────────────┘
     Same Size          Same Size          Same Size
     140px min          140px min          140px min
     38px height        38px height        38px height
```

---

## 🚀 IMPLEMENTATION PRIORITY

1. ✅ **Staff Dashboard** - Already implemented, needs standardization
2. ⏳ **Manager Dashboard** - Backend ready, needs frontend with standardized buttons
3. ⏳ **Admin Dashboard** - Phase 3, will follow same standards

---

**Version**: 1.0  
**Last Updated**: June 3, 2026  
**Status**: Standard Defined  
**Apply To**: All Transaction Module Export Buttons

