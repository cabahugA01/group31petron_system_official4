# Inventory Module - Visual Workflow

**Complete workflow from Stock Request to Inventory Update**  
**Date:** June 4, 2026

---

## Role-Based Access Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          INVENTORY MODULE                                │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  ┌──────────────┐      ┌──────────────┐      ┌──────────────┐          │
│  │    STAFF     │      │   MANAGER    │      │    ADMIN     │          │
│  │              │      │              │      │              │          │
│  │ • View Only  │  →   │ • Validate   │  →   │ • Approve    │          │
│  │ • Request    │      │ • Encode     │      │ • Finalize   │          │
│  │ • Encode Del │      │ • Generate PO│      │ • Print PO   │          │
│  └──────────────┘      └──────────────┘      └──────────────┘          │
│                                                                           │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Complete Workflow Diagram

```
                    ╔════════════════════════════════════╗
                    ║  INVENTORY MANAGEMENT WORKFLOW     ║
                    ╚════════════════════════════════════╝

┌─────────────────────────────────────────────────────────────────────────┐
│ PHASE 1: DETECTION & REQUEST                                            │
└─────────────────────────────────────────────────────────────────────────┘

    ┌──────────────────────────────────────────┐
    │  Merchandise / Fuel Inventory             │
    │  ┌─────────────────────────────────────┐ │
    │  │ Item A: Stock = 5, Reorder = 20     │ │  ← LOW STOCK DETECTED
    │  │ Item B: Stock = 0, Reorder = 10     │ │  ← OUT OF STOCK
    │  │ Item C: Stock = 50, Reorder = 20    │ │  ← OK
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘
                        ↓
            [Stock Request] Button Appears
                    (SMART)
                        ↓
    ┌──────────────────────────────────────────┐
    │  Staff Clicks Button                      │
    │  • Multi-select modal opens               │
    │  • Shows Items A & B only                 │
    │  • Auto-calculates quantities:            │
    │    - Item A: Request 15 pcs               │
    │    - Item B: Request 10 pcs               │
    └──────────────────────────────────────────┘
                        ↓
                [Submit Requests]
                        ↓
    ┌──────────────────────────────────────────┐
    │  Database: stock_requests                 │
    │  ┌─────────────────────────────────────┐ │
    │  │ Request #101: Item A, 15 pcs        │ │
    │  │ Status: Pending                     │ │
    │  │ Staff: John Doe                     │ │
    │  ├─────────────────────────────────────┤ │
    │  │ Request #102: Item B, 10 pcs        │ │
    │  │ Status: Pending                     │ │
    │  │ Staff: John Doe                     │ │
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ PHASE 2: MANAGER VALIDATION                                             │
└─────────────────────────────────────────────────────────────────────────┘

    ┌──────────────────────────────────────────┐
    │  Manager: Stock Requests Page             │
    │  Route: manager_fuel_stock_requests.php   │
    │                                            │
    │  ┌────────────────────────────────────┐  │
    │  │ SUMMARY CARDS                       │  │
    │  │ • Pending: 2                        │  │
    │  │ • Approved: 0                       │  │
    │  │ • Ready for PO: 0                   │  │
    │  └────────────────────────────────────┘  │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────┐
    │  MERCHANDISE STOCK REQUESTS               │
    │  ┌─────────────────────────────────────┐ │
    │  │ #101 │ Item A │ 5 │ 15 │ Pending   │ │
    │  │      └──[Approve]─[Reject]──────────┘ │
    │  │ #102 │ Item B │ 0 │ 10 │ Pending   │ │
    │  │      └──[Approve]─[Reject]──────────┘ │
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘
                        ↓
         Manager Reviews Each Request
                        ↓
    ┌─────────────────────────────────────────────┐
    │  Manager Actions:                            │
    │                                               │
    │  Option A: APPROVE                            │
    │  • Enter approved quantity (can adjust)       │
    │  • Add manager notes (optional)               │
    │  • Click "Confirm Approve"                    │
    │  • Status → Validated                         │
    │                                               │
    │  Option B: REJECT                             │
    │  • Enter rejection reason (required)          │
    │  • Click "Confirm Reject"                     │
    │  • Status → Rejected                          │
    └─────────────────────────────────────────────┘
                        ↓
                  [Approved]
                        ↓
    ┌──────────────────────────────────────────┐
    │  Database: stock_requests                 │
    │  ┌─────────────────────────────────────┐ │
    │  │ Request #101                        │ │
    │  │ Status: Validated ✓                 │ │
    │  │ Approved Qty: 15                    │ │
    │  │ Manager: Jane Smith                 │ │
    │  │ Processed: 2026-06-04 10:30:00      │ │
    │  ├─────────────────────────────────────┤ │
    │  │ Request #102                        │ │
    │  │ Status: Validated ✓                 │ │
    │  │ Approved Qty: 10                    │ │
    │  │ Manager: Jane Smith                 │ │
    │  │ Processed: 2026-06-04 10:32:00      │ │
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ PHASE 3: PURCHASE ORDER GENERATION                                      │
└─────────────────────────────────────────────────────────────────────────┘

    ┌──────────────────────────────────────────┐
    │  Manager: Stock Requests Page (Refresh)   │
    │                                            │
    │  ┌────────────────────────────────────┐  │
    │  │ SUMMARY CARDS                       │  │
    │  │ • Pending: 0                        │  │
    │  │ • Validated: 2                      │  │
    │  │ • Ready for PO: 2  ← NEW!           │  │
    │  └────────────────────────────────────┘  │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────────────┐
    │  MERCHANDISE STOCK REQUESTS                       │
    │  ┌───────────────────────────────────────────┐  │
    │  │ #101 │ Item A │ 5 │ 15 │ Validated       │  │
    │  │      └──[Generate PO]────────────────────┘  │
    │  │ #102 │ Item B │ 0 │ 10 │ Validated       │  │
    │  │      └──[Generate PO]────────────────────┘  │
    │  └───────────────────────────────────────────┘  │
    └──────────────────────────────────────────────────┘
                        ↓
         Manager Clicks [Generate PO]
                        ↓
    ┌─────────────────────────────────────────────┐
    │  Generate PO Modal                           │
    │  ┌────────────────────────────────────────┐ │
    │  │  Item Name: Item A                     │ │
    │  │  Approved Quantity: 15                 │ │
    │  │                                        │ │
    │  │  ℹ️ This will create a Purchase Order  │ │
    │  │     with status "Pending Admin         │ │
    │  │     Validation"                        │ │
    │  │                                        │ │
    │  │  [Generate PO]  [Cancel]               │ │
    │  └────────────────────────────────────────┘ │
    └─────────────────────────────────────────────┘
                        ↓
                 [Generate PO]
                        ↓
    ┌──────────────────────────────────────────┐
    │  System Creates PO                        │
    │  • Generate unique PO number              │
    │  • Format: PO-20260604-SR0101             │
    │  • Link to stock request (request_id)     │
    │  • Get unit price from inventory          │
    │  • Calculate total amount                 │
    │  • Set status: Pending Admin Validation   │
    │  • Create PO line items                   │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────┐
    │  Database: purchase_orders                │
    │  ┌─────────────────────────────────────┐ │
    │  │ PO: PO-20260604-SR0101              │ │
    │  │ Request ID: 101                     │ │
    │  │ Item: Item A                        │ │
    │  │ Quantity: 15                        │ │
    │  │ Unit Price: ₱150.00                 │ │
    │  │ Total: ₱2,250.00                    │ │
    │  │ Status: Pending Admin Validation    │ │
    │  │ Created By: Jane Smith (Manager)    │ │
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────┐
    │  Database: purchase_order_items           │
    │  ┌─────────────────────────────────────┐ │
    │  │ PO ID: (from above)                 │ │
    │  │ Item: Item A                        │ │
    │  │ Product ID: 123                     │ │
    │  │ Quantity: 15                        │ │
    │  │ Unit Price: ₱150.00                 │ │
    │  │ Total: ₱2,250.00                    │ │
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────────────┐
    │  Success Message                                  │
    │  "✓ Purchase Order PO-20260604-SR0101             │
    │     generated successfully!                       │
    │     Pending Admin validation."                    │
    └──────────────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────────────┐
    │  Page Refreshes - Request Display Updated:        │
    │  ┌───────────────────────────────────────────┐  │
    │  │ #101 │ Item A │ 5 │ 15 │ Validated       │  │
    │  │      └──✓ PO: PO-20260604-SR0101 ────────┘  │
    │  │         (no more Generate PO button)        │  │
    │  └───────────────────────────────────────────┘  │
    └──────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ PHASE 4: ADMIN APPROVAL                                                 │
└─────────────────────────────────────────────────────────────────────────┘

    ┌──────────────────────────────────────────┐
    │  Admin: Purchase Orders Oversight         │
    │  Route: admin_purchase_orders.php         │
    │                                            │
    │  ┌────────────────────────────────────┐  │
    │  │ PENDING ADMIN VALIDATION            │  │
    │  │                                     │  │
    │  │ ┌─────────────────────────────────┐│  │
    │  │ │ PO-20260604-SR0101              ││  │
    │  │ │ Item A - 15 pcs                 ││  │
    │  │ │ Total: ₱2,250.00                ││  │
    │  │ │ Station: 1253                   ││  │
    │  │ │ Manager: Jane Smith             ││  │
    │  │ │                                 ││  │
    │  │ │ [Approve] [Reject]              ││  │
    │  │ └─────────────────────────────────┘│  │
    │  └────────────────────────────────────┘  │
    └──────────────────────────────────────────┘
                        ↓
              Admin Clicks [Approve]
                        ↓
    ┌──────────────────────────────────────────┐
    │  PO Status Updated                        │
    │  • Status: Approved                       │
    │  • Approved By: Admin User ID             │
    │  • Approved At: Timestamp                 │
    └──────────────────────────────────────────┘
                        ↓
              Admin Clicks [Print PO]
                        ↓
    ┌──────────────────────────────────────────┐
    │  System Actions:                          │
    │  • Generate PDF document                  │
    │  • Status → Official                      │
    │  • Create Expected Deliveries             │
    │  • Notify Staff                           │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────┐
    │  Database: purchase_orders                │
    │  ┌─────────────────────────────────────┐ │
    │  │ PO: PO-20260604-SR0101              │ │
    │  │ Status: Official ✓                  │ │
    │  │ Approved By: Admin (ID: 3)          │ │
    │  │ Approved At: 2026-06-04 14:00:00    │ │
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────┐
    │  Staff Can Now See:                       │
    │  • Expected Deliveries tab                │
    │  • Shows: PO-20260604-SR0101              │
    │  •        Item A, 15 pcs expected         │
    └──────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ PHASE 5: DELIVERY ENCODING (Staff)                                      │
└─────────────────────────────────────────────────────────────────────────┘

    ┌──────────────────────────────────────────┐
    │  Actual Delivery Arrives at Station       │
    │  • Driver brings Item A: 15 pcs           │
    │  • Invoice No: INV-2024-5678              │
    │  • Delivery Date: 2026-06-05              │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────┐
    │  Staff: Stock-In Page                     │
    │  Route: staff_stock_in.php                │
    │                                            │
    │  [Encode Delivery]                        │
    │  • PO Reference: PO-20260604-SR0101       │
    │  • Supplier: [Select from dropdown]       │
    │  • Item: Item A                           │
    │  • Quantity Received: 15                  │
    │  • Delivery Date: 2026-06-05              │
    │  • Invoice No: INV-2024-5678              │
    │  • Notes: [Optional]                      │
    │                                            │
    │  [Submit]                                 │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────┐
    │  Delivery Record Created                  │
    │  • Status: Pending                        │
    │  • Awaiting Manager validation            │
    │  • Inventory NOT yet updated              │
    └──────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ PHASE 6: DELIVERY VALIDATION (Manager)                                  │
└─────────────────────────────────────────────────────────────────────────┘

    ┌──────────────────────────────────────────┐
    │  Manager: Deliveries Validation           │
    │  Route: manager_delivery_validation.php   │
    │                                            │
    │  ┌────────────────────────────────────┐  │
    │  │ PENDING DELIVERIES                  │  │
    │  │                                     │  │
    │  │ PO: PO-20260604-SR0101              │  │
    │  │ Item A                              │  │
    │  │ Expected: 15 pcs                    │  │
    │  │ Received: 15 pcs                    │  │
    │  │ Variance: 0 ✓                       │  │
    │  │                                     │  │
    │  │ [Approve] [Reject]                  │  │
    │  └────────────────────────────────────┘  │
    └──────────────────────────────────────────┘
                        ↓
         Manager Reviews Delivery
                        ↓
    ┌─────────────────────────────────────────────┐
    │  Variance Calculation:                       │
    │  Variance = Received - Expected              │
    │           = 15 - 15                          │
    │           = 0 (No discrepancy)               │
    │                                               │
    │  If Variance = 0: Mark as Compliant           │
    │  If Variance ≠ 0: Require variance notes      │
    └─────────────────────────────────────────────┘
                        ↓
              Manager Clicks [Approve]
                        ↓
    ┌──────────────────────────────────────────┐
    │  Delivery Status Updated                  │
    │  • Status: Verified                       │
    │  • Verified By: Manager                   │
    │  • Verified At: Timestamp                 │
    │  • Ready for Admin finalization           │
    └──────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────┐
│ PHASE 7: FINALIZATION & INVENTORY UPDATE (Admin)                        │
└─────────────────────────────────────────────────────────────────────────┘

    ┌──────────────────────────────────────────┐
    │  Admin: Deliveries Oversight              │
    │                                            │
    │  ┌────────────────────────────────────┐  │
    │  │ VERIFIED DELIVERIES                 │  │
    │  │                                     │  │
    │  │ PO: PO-20260604-SR0101              │  │
    │  │ Item A - 15 pcs                     │  │
    │  │ Variance: 0 (Compliant)             │  │
    │  │ Verified By: Jane Smith             │  │
    │  │                                     │  │
    │  │ [Finalize]                          │  │
    │  └────────────────────────────────────┘  │
    └──────────────────────────────────────────┘
                        ↓
              Admin Clicks [Finalize]
                        ↓
    ┌──────────────────────────────────────────┐
    │  System Executes:                         │
    │  1. Update delivery status to Finalized   │
    │  2. Update inventory stock levels         │
    │  3. Update PO status to Received          │
    │  4. Update stock request to Completed     │
    │  5. Log to audit trail                    │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────┐
    │  Database: station_inventory              │
    │  ┌─────────────────────────────────────┐ │
    │  │ Item A                              │ │
    │  │ Old Stock: 5                        │ │
    │  │ Delivered: +15                      │ │
    │  │ New Stock: 20 ✓                     │ │
    │  │ Status: AVAILABLE (was LOW STOCK)   │ │
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────┐
    │  Database: purchase_orders                │
    │  ┌─────────────────────────────────────┐ │
    │  │ PO: PO-20260604-SR0101              │ │
    │  │ Status: Received ✓                  │ │
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘
                        ↓
    ┌──────────────────────────────────────────┐
    │  Database: stock_requests                 │
    │  ┌─────────────────────────────────────┐ │
    │  │ Request #101                        │ │
    │  │ Status: Completed ✓                 │ │
    │  └─────────────────────────────────────┘ │
    └──────────────────────────────────────────┘
                        ↓
            ╔══════════════════════════╗
            ║  WORKFLOW COMPLETE ✓     ║
            ║  Inventory Updated       ║
            ║  Cycle Closed            ║
            ╚══════════════════════════╝
```

---

## Status Progression Summary

```
STOCK REQUEST STATUS:
Pending → Validated → Completed
           ↓
        Rejected

PURCHASE ORDER STATUS:
Draft → Pending Admin Validation → Approved → Official → Received
                                      ↓
                                   Rejected

DELIVERY STATUS:
Pending → Verified → Finalized
           ↓
        Rejected
```

---

## Database Relationships

```
stock_requests
    └── purchase_orders (via request_id)
            ├── purchase_order_items
            └── fuel_deliveries / merchandise_deliveries (via po_reference)
                    └── station_inventory / fuel_inventory (updated on finalize)
```

---

## Key Timestamps

```
Request Created:     2026-06-04 10:15:00
Request Validated:   2026-06-04 10:30:00
PO Generated:        2026-06-04 10:35:00
PO Approved:         2026-06-04 14:00:00
PO Printed:          2026-06-04 14:05:00
Delivery Arrived:    2026-06-05 09:00:00
Delivery Encoded:    2026-06-05 09:15:00
Delivery Verified:   2026-06-05 11:00:00
Delivery Finalized:  2026-06-05 14:30:00
Inventory Updated:   2026-06-05 14:30:00

Total Time: ~1.5 days
```

---

**This visual workflow shows the complete end-to-end process!** 🎉
