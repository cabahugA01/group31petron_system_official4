# 🎨 Button Color Quick Reference - 4 COLORS ONLY

**Module**: All Transaction Modules  
**Standard**: 4-Color System  

---

## 🎨 THE 4 APPROVED COLORS

```
┌─────────────────────────────────────────────────────────┐
│                  DARK BLUE (Primary)                    │
│  #002F70 → #001a3d (hover)                             │
│  Use: View, Update, Start, Primary Actions             │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│                  GREEN (Success/Payment)                │
│  #16a34a → #15803d (hover)                             │
│  Use: Pay, Settle, Complete, All Payment Actions       │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│                  GRAY (Secondary)                       │
│  #6b7280 → #4b5563 (hover)                             │
│  Use: Print, Re-encode, Adjust, Cancel, Back           │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│                  RED (Danger)                           │
│  #dc2626 → #b91c1c (hover)                             │
│  Use: Delete, Reject, Critical Warnings                │
└─────────────────────────────────────────────────────────┘
```

---

## 📋 BUTTON → COLOR MAPPING

### Job Order Tracker:
| Button | Color | Hex |
|--------|-------|-----|
| 👁️ View | DARK BLUE | `#002F70` |
| 🔄 Update Status | DARK BLUE | `#002F70` |
| ✏️ Adjust | GRAY | `#6b7280` |
| ▶️ Start In Progress | DARK BLUE | `#002F70` |
| ✅ Complete & Settle | GREEN | `#16a34a` |
| 💵 Accept Downpayment | GREEN | `#16a34a` |
| 💰 Mark Paid | GREEN | `#16a34a` |
| 💰 Settle Balance | GREEN | `#16a34a` |
| 🖨️ Print Receipt | GRAY | `#6b7280` |
| 🔄 Re-encode | GRAY | `#6b7280` |

### Merchandise History:
| Button | Color | Hex |
|--------|-------|-----|
| 💰 Settle | GREEN | `#16a34a` |
| 💵 Paid | GREEN | `#16a34a` |

### Export Buttons (All Pages):
| Button | Color | Hex |
|--------|-------|-----|
| 📊 Excel | GREEN | `#16a34a` |
| 📊 CSV | GREEN | `#16a34a` |
| 📄 PDF | RED | `#dc2626` |
| ← Back | GRAY | `#6b7280` |

---

## ❌ COLORS TO REMOVE

| Old Color | Hex | Replace With |
|-----------|-----|--------------|
| ❌ Light Blue | `#3b82f6` | DARK BLUE `#002F70` |
| ❌ Orange | `#f59e0b` | GRAY `#6b7280` |
| ❌ Yellow | `#fef9c3` | GREEN `#16a34a` |
| ❌ Sky Blue | `#e0f2fe` | GREEN `#16a34a` |

---

## 💻 COPY-PASTE TEMPLATES

### Dark Blue Button:
```html
style="background:#002F70;color:#fff;padding:5px 10px;border-radius:6px;border:none;font-size:10px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:background 0.2s ease"
onmouseover="this.style.background='#001a3d'"
onmouseout="this.style.background='#002F70'"
```

### Green Button:
```html
style="background:#16a34a;color:#fff;padding:5px 10px;border-radius:6px;border:none;font-size:10px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:background 0.2s ease"
onmouseover="this.style.background='#15803d'"
onmouseout="this.style.background='#16a34a'"
```

### Gray Button:
```html
style="background:#6b7280;color:#fff;padding:5px 10px;border-radius:6px;border:none;font-size:10px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:background 0.2s ease"
onmouseover="this.style.background='#4b5563'"
onmouseout="this.style.background='#6b7280'"
```

### Red Button:
```html
style="background:#dc2626;color:#fff;padding:5px 10px;border-radius:6px;border:none;font-size:10px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:background 0.2s ease"
onmouseover="this.style.background='#b91c1c'"
onmouseout="this.style.background='#dc2626'"
```

---

## ✅ CHECKLIST

When adding/updating buttons, verify:
- [ ] Color is one of the 4 approved colors
- [ ] Hover effect matches the color
- [ ] Transition is set to 0.2s ease
- [ ] Font size is consistent (10px for action buttons, 12px for export buttons)
- [ ] Font weight is 600 or 700
- [ ] Border-radius is 6px or 8px
- [ ] No old colors (orange, light blue, yellow, sky blue) used

---

**Quick Rules**:
- 🔵 **Primary action?** → DARK BLUE
- 💚 **Payment/Success?** → GREEN
- ⚪ **Secondary/Edit?** → GRAY
- 🔴 **Delete/Danger?** → RED

**Cebuano**: 4 ka colors lang gamiton - Dark Blue, Green, Gray, Red! 🎨
