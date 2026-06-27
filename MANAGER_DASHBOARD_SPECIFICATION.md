# Manager Dashboard - Complete Specification

## Overview
Complete operational dashboard for station managers with real-time insights, approvals, and management tools.

---

## 1. Summary Cards (Top Row)

### Card 1: Today's Transactions
**Data:**
- Fuel transactions count
- Merchandise transactions count  
- Service transactions count
- **Total:** Sum of all three

**Example:** 156 Transactions

---

### Card 2: Today's Revenue
**Data:**
- Fuel sales (₱)
- Merchandise sales (₱)
- Service income (₱)
- **Total:** ₱185,650.50

**Display:** Current day sales

---

### Card 3: Fuel Sold Today
**Data:**
- Sum of all fuel liters sold today
- **Example:** 2,845.80 Liters

---

### Card 4: Pending Approvals
**Includes:**
- Stock Requests (count)
- Customer Registration Requests (count)
- Price Change Requests (count)
- **Total:** 8 Pending Approvals

**Action:** Click to view approval queue

---

### Card 5: Inventory Alerts
**Includes:**
- Low Fuel (count)
- Low Merchandise (count)
- Out of Stock (count)
- **Total:** 12 Alerts

**Action:** Click to view inventory alerts

---

### Card 6: Pending Deliveries
**Data:**
- Count of unprocessed deliveries
- **Example:** 3 Deliveries

**Action:** Click to receive deliveries

---

### Card 7: Active Services (Job Orders)
**Data:**
- Job orders in progress
- **Example:** 18 Active Services

**Status breakdown:**
- Pending
- In Progress
- Ready

---

### Card 8: Active Staff
**Data:**
- Staff count by shift
- **Example:**
  - Shift 1: 2 Staff
  - Shift 2: 2 Staff

---

## 2. Charts Section

### A. Revenue Breakdown (DONUT)
**Type:** Donut Chart  
**Data:**
- Fuel (₱ and %)
- Merchandise (₱ and %)
- Service (₱ and %)

**Colors:**
- Fuel: #dc2626 (Red)
- Merchandise: #16a34a (Green)
- Service: #3b82f6 (Blue)

---

### B. Hourly Sales Trend (LINE)
**Type:** Line Chart  
**X-Axis:** Hours (6AM - 11PM)  
**Y-Axis:** Revenue (₱)  
**Data:** Today's revenue by hour

---

### C. Fuel Sales by Product (BAR)
**Type:** Bar Chart  
**X-Axis:** Fuel Products
- Diesel
- XCS
- Turbo Diesel
- XTRA Unleaded
- Kerosene

**Y-Axis:** Liters Sold  
**Color:** #ea580c (Orange)

---

### D. Merchandise Sales by Category (BAR)
**Type:** Bar Chart  
**X-Axis:** Categories
- Lubricants
- Drinks
- Snacks
- Accessories
- Engine Oil

**Y-Axis:** Sales Amount (₱)  
**Color:** #16a34a (Green)

---

### E. Weekly Revenue Trend (LINE)
**Type:** Line Chart  
**X-Axis:** Days (Monday → Sunday)  
**Y-Axis:** Revenue (₱)  
**Data:** Last 7 days revenue

---

### F. Inventory Status (BAR)
**Type:** Stacked Bar Chart  
**Categories:**
- Fuel Tanks (by type)
- Merchandise Products (by category)

**Metrics:**
- Current Stock
- Capacity
- Fill % (color-coded)

**Colors:**
- Normal: #22c55e (Green)
- Low: #f59e0b (Orange)
- Critical: #dc2626 (Red)

---

## 3. Manager Action Panels

### A. Pending Stock Requests
**Table Columns:**
| Request No | Type | Requested By | Status | Action |

**Actions:**
- Review (modal with details)
- Approve (with quantity)
- Reject (with reason)
- Generate PO (after approval)

**Filter:**
- All / Fuel / Merchandise
- Pending / Approved / Rejected

---

### B. Pending Customer Registration
**Table Columns:**
| Customer | Contact | Requested By | Action |

**Actions:**
- Approve (activate customer)
- Reject (with reason)
- View Details (modal)

---

### C. Pending Deliveries
**Table Columns:**
| Delivery No | Supplier | Status | Action |

**Actions:**
- Receive Delivery (validate quantity)
- View (delivery details)
- Stock-In (after receiving)

**Statuses:**
- Expected
- Partial
- Received
- Stock-In Complete

---

### D. Price Update Summary
**Display:**
- Recent fuel price updates (last 5)
- Recent merchandise price updates (last 5)

**Columns:**
| Product | Old Price | New Price | Updated By | Date |

**Button:** Manage Pricing

---

### E. Recent Transactions
**Display:** Latest 10 transactions  
**Types:** Fuel, Merchandise, Service

**Columns:**
| Time | Type | Customer | Amount | Status |

---

### F. Low Inventory
**Display:** Items below reorder level

**Columns:**
| Product | Type | Current | Reorder Level | Status |

**Status Colors:**
- Normal: Green
- Low: Orange  
- Critical: Red

---

### G. Service Queue
**Display:** Active job orders

**Columns:**
| Service No | Customer | Status | Actions |

**Statuses:**
- Pending
- In Progress
- Ready
- Released

---

## 4. Quick Actions (Fixed Bottom Right)

**Buttons:**
- 🔵 New Fuel Transaction
- 🟢 New Merchandise Transaction
- 🟡 New Service Transaction
- 📋 Review Stock Requests
- 📦 Receive Deliveries
- 💰 Pricing Management
- 📊 Inventory Management
- 📈 Reports

**Style:** Floating action button group

---

## 5. Manager Calendar (Right Sidebar)

**Shows:**
- Upcoming deliveries
- Inventory count schedule
- Staff meetings
- Scheduled maintenance

**View:** Mini calendar with event indicators

---

## Layout Structure

```
┌─────────────────────────────────────────────────────────────────┐
│ Header: Manager Dashboard | Welcome, [Name] | Station | Date    │
├─────────────────────────────────────────────────────────────────┤
│ Summary Cards (8 cards in 4x2 grid)                            │
├─────────────────────────────────────────────────────────────────┤
│ Charts (6 charts in 2 rows x 3 columns grid)                   │
├─────────────────────────────────────────────────────────────────┤
│ Action Panels (Tabbed Interface)                                │
│ ┌─────┬───────┬──────────┬────────┬─────────┬──────────┐       │
│ │Stock│Customer│Deliveries│Pricing│Transact.│Inventory│       │
│ └─────┴───────┴──────────┴────────┴─────────┴──────────┘       │
│                                                                  │
│ [Table/List View for selected tab]                             │
├─────────────────────────────────────────────────────────────────┤
│ Quick Actions (Floating)          │ Calendar (Sidebar)          │
└─────────────────────────────────────────────────────────────────┘
```

---

## Color Scheme

**Primary:** #002F70 (Petron Blue)  
**Success:** #16a34a (Green)  
**Warning:** #f59e0b (Orange)  
**Danger:** #dc2626 (Red)  
**Info:** #3b82f6 (Blue)  
**Background:** #f8fafc  
**Card:** #ffffff  
**Border:** #e2e8f0  

---

## Responsive Design

**Desktop (1920px+):** 4 cards per row  
**Laptop (1024px-1919px):** 4 cards per row  
**Tablet (768px-1023px):** 2 cards per row  
**Mobile (<768px):** 1 card per row  

Charts stack vertically on mobile.

---

## Implementation Priority

1. ✅ Summary Cards (Phase 1)
2. ✅ Charts (Phase 2)
3. ✅ Action Panels (Phase 3)
4. ✅ Quick Actions (Phase 4)
5. ✅ Calendar (Phase 5)

---

**Total Estimated Lines:** ~2500 lines  
**Implementation Time:** 8-12 hours  
**Testing Time:** 2-4 hours

---

End of Specification
