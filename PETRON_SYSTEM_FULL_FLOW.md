# ⛽ PETRON STATION MANAGEMENT & POS SYSTEM
## FULL SYSTEM OPERATIONAL & TECHNICAL FLOW (ENGLISH - BISAYA MIX)

> **Dokumento Bahin Sa:** Tibuok dagan sa Petron POS and Station Management System para sa upat (4) ka user roles (**Super Admin**, **Station Admin**, **Station Manager**, ug **Staff / Cashier / Attendant**). Pure Markdown text kini ug walay diagrams sumala sa imong gipangayo.

---

## 1. MGA LEBEL UG SCOPE SA UPAT KA USER (USER ROLES OVERVIEW)

Aron klaro ang responsibilidad sa matag usa, ania ang summary sa ilang access levels:

1. **Super Admin (Headquarters / Nationwide Oversight)**
   * **Scope:** Tibuok nasod (All Stations Nationwide).
   * **Role:** Nag-manage sa nationwide branches, naghimo sa mga Station Admins, nag-monitor sa system health, nag-set sa global system rules, ug nagtan-aw sa consolidated regional sales reports.

2. **Station Admin (Station Administrator)**
   * **Scope:** Piho nga Branch / Estasyon lamang.
   * **Role:** Branch controller. Siya ang maghimo sa user accounts sa Station Manager, Cashiers, Attendants, ug Mechanics. Siya pud ang ga-setup sa Master Pump layout, Tangke (Tanks), ug naga-approve sa mga dagkong Purchase Orders (PO).

3. **Station Manager (Operations Supervisor & Approver)**
   * **Scope:** Adlaw-adlaw nga operasyon sa estasyon (Day-to-day Station Operations).
   * **Role:** Operations head. Siya ang naga-encode ug naga-schedule sa fuel prices, naga-verify sa delivery sa lana gikan sa Petron tanker, naga-approve sa mga Car Care Job Orders, ug naga-reconcile sa fuel variance (shortage/overage) inig human sa shift.

4. **Staff / Cashier / Pump Attendant (Frontline Operations)**
   * **Scope:** Frontline POS Terminal & Dispenser Pumps.
   * **Role:** Tig-atubang sa kustomer. Sila ang mag-encode sa sales sa lana, C-Store grocery items, mag-receive og customer vehicles sa bay, magdawat og bayad (Cash, Cards, Fleet, Credit), mag-encode sa dipstick sa tangke, ug mag-remit sa halin inig shift closing.

---

## 2. KUMPLETONG END-TO-END SYSTEM FLOW

---

### PHASE 1: STATION ONBOARDING & USER SETUP (Pagsugod sa Estasyon)
*Kini ang initial setup sa dili pa mag-operate ang usa ka branch.*

1. **Super Admin creates Station**:
   * Mo-login ang Super Admin sa `super_admin_dashboard.php`.
   * Moadto sa **Station Management** (`superadmin_station_management.php`).
   * I-input ang Station Details: Branch Name, Station Code, Region (`ph_regions`), Address, Contact Info, ug GPS Coordinates.
   * I-activate ang mga modules nga gamiton sa estasyon pinaagi sa `module_settings` (Fuel Management, C-Store POS, Car Care Bay, Customer Credit).
   * Maghimo og opisyal nga **Station Admin account** para sa maong branch.

2. **Station Admin sets up Users & Pump Master**:
   * Mo-login ang Station Admin gamit ang iyang credentials.
   * Moadto sa **User Management** (`admin_user_management.php`):
     * Maghimo og accounts para sa **Station Manager**, **Cashiers**, **Pump Attendants**, ug **Mechanics**.
     * I-assign ang matag user sa ilang saktong role ug station ID.
   * Moadto sa **Pump Master Oversight** (`admin_pump_master_oversight.php`):
     * I-setup ang underground fuel tanks ug ilang maximum capacity (liters).
     * I-configure ang mga Pump Dispensers ug Nozzles: I-link ang matag Nozzle sa iyang saktong Tangke ug Fuel Type (**Turbo Diesel, Diesel, XCS Plus, Xtra Advance/UNL, ug Kerosene**).
     * I-set ang critical safety inventory threshold (aron mag-warning ang system kung hapit na mahurot ang lana).

---

### PHASE 2: FUEL PRICING & SCHEDULING FLOW (Pag-update sa Presyo)
*Kini ang proseso sa pag-ilis sa presyo sa lana matag Martes o base sa advisory sa Department of Energy (DOE).*

1. **Super Admin / Head Office Directive**:
   * Magpagawas ang Super Admin og official Price Advisory Notice sa sistema bahin sa price increase o rollback.

2. **Station Manager encodes Price Change**:
   * Mo-login ang Station Manager ug mo-abli sa `manager_set_prices.php`.
   * Makita niya ang kasamtangang presyo sa lima (5) ka standard fuel types.
   * I-encode ang bag-ong presyo matag litro (Retail Pump Price).
   * I-set ang **Effective Date ug Time** (pananglitan: Martes sa buntag, 6:00 AM).
   * I-klik ang **"Submit for Activation"**.

3. **Automated Dynamic Pump Sync**:
   * Pag-abot sa eksaktong oras sa effectivity:
     * Awtomatikong i-update sa sistema ang selling prices sa tanang POS screens ug electronic pump dispensers.
     * Walay manual adjustment nga buhaton ang cashier aron malikayan ang charging error.
     * Ang kausaban ma-save sa `fuel_price_change_log` para sa audit compliance ug resibo verification.

---

### PHASE 3: PROCUREMENT & DELIVERY STOCK-IN FLOW (Pag-order ug Pagdawat og Stock)
*Kini ang replenishment sa lana sa tangke ug mga baligya sa C-Store.*

1. **Station Manager creates Stock Request / PO**:
   * Mo-check ang Manager sa `manager_inventory_fuel.php` o `manager_inventory_merchandise.php`.
   * Kung makita nga ubos na ang stock sa tangke o shelves, maghimo siya og **Purchase Order (PO)**.
   * Para sa lana: Pili-on ang Fuel Supplier (Petron Bulk Depot), Fuel Type, ug gidaghanon sa litro nga orderon.
   * I-forward ang PO ngadto sa Station Admin para sa approval.

2. **Station Admin approves Purchase Order**:
   * Mo-abli ang Admin sa `admin_purchase_orders.php`.
   * I-review ang order quantity, budget limit, ug supplier contract terms.
   * I-klik ang **"Approve PO"**. Mo-generate ang sistema og official PO slip (`print_po_new.php`) nga ipadala sa Petron Depot.

3. **Staff receives Delivery at the Station**:
   * Moabot ang Petron Tanker Lorry o truck sa merchandise.
   * Ang Staff / Pump Attendant mo-abli sa `staff_record_delivery.php`.
   * **Sa Lana (Fuel Delivery):**
     * Mag-physical dipstick ang staff sa tanker compartment ug sa underground tank sa estasyon gamit ang dipstick rod ug water paste.
     * Kuhaon ang liquid level (millimeter height) ug conversion to liters gamit ang calibration chart.
     * I-encode sa screen: Supplier Invoice Number, Invoiced Liters (Bill of Lading), ug Actual Dipstick Reading.
   * **Sa C-Store (Merchandise):**
     * I-ihap ang mga karton sa Petron Rev-X engine oil, snacks, ug parts.
     * I-encode ang received batches, expiry dates, ug purchase costs.

4. **Station Manager validates & accepts Delivery**:
   * Moadto ang Manager sa `manager_fuel_deliveries.php` o `manager_merchandise_deliveries.php`.
   * I-cross-check sa Manager: Sakto ba ang gidaghanon sa invoice batok sa aktuwal nga nasulod sa tangke?
   * Kung naa sulod sa acceptable shrinkage/temperature variance tolerance:
     * I-klik sa Manager ang **"Validate & Accept Delivery"**.
     * Awtomatikong mosaka ang stock sa `fuel_inventory` ug `station_inventory`.
     * Mag-create ang system og Accounts Payable entry sa `admin_supplier_billing.php`.

---

### PHASE 4: SHIFT OPENING & CASHIER FLOAT (Pagsugod sa Shift)
*Kini ang pag-abli sa duty sa cashier/attendant aron limpyo ang kwentada.*

1. **Staff Login & Shift Selection**:
   * Mo-login ang Staff sa `login.php`.
   * Pili-on ang iyang duty shift (Morning Shift, Afternoon Shift, o Graveyard Shift gikan sa `shifts` table).

2. **Opening Cash Float & Starting Readings**:
   * I-encode sa Staff ang iyang **Opening Cash Float** (pananglitan: ₱2,500 nga panukli gikan sa kaha).
   * Mag-ikot ang staff sa mga pump dispensers aron i-verify ug i-encode ang **Starting Totalizer Meter Readings** sa matag nozzle.
   * I-save kini sa sistema ubos sa `fuel_sales_closing` nga naka-tag sa iyang `user_id`, petsa, ug oras.
   * Awtomatikong ma-redirect ang staff sa Main POS Operations Hub (`staff_transactions_hub.php`).

---

### PHASE 5: POS TRANSACTIONS HUB (Frontline Daily Operations)
*Mao kini ang pinaka-busy nga module diin gina-proseso ang tanang bayad sa kustomer.*

Ang Staff Transaction Hub naglangkob sa tulo (3) ka dagkong matang sa transaksyon:

#### A. Fuel Dispensing Sales
1. Pili-on sa Staff ang Pump Number ug Nozzle sa screen (e.g., Pump 2 - Diesel).
2. Awtomatikong kuhaon sa system ang active price gikan sa `fuel_pricing`.
3. Pili-on ang transaction mode:
   * **Preset by Amount:** Pila ka pesos ang ipatubil (e.g., ₱1,000).
   * **Preset by Volume:** Pila ka litro ang tubilon (e.g., 20 Liters).
   * **Full Tank:** I-encode ang final dispense volume human mahuman og bomba.
4. I-add sa transaction cart ug proceed sa payment.

#### B. C-Store / Merchandise Sales
1. I-scan sa Staff ang barcode sa item o i-type ang ngalan sa search box (pananglitan: Petron Blaze 100 T-shirt, Rev-X Trekker 15W-40, wiper blades, ilimnon).
2. I-verify sa sistema kung available ba ang stock sa `station_inventory`. Kung zero stock, magpakita og alert error.
3. I-enter ang quantity; awtomatikong kuwentahon sa sistema ang Subtotal, VAT, ug Total Amount.

#### C. Car Care Center & Job Orders (Bay Service)
1. **Customer & Vehicle Check-in:** I-encode ang pangalan sa kustomer, contact number, plaka sa sakyanan (`vehicle_plate`), make/model, ug current mileage.
2. **13-Point Inspection:** I-check sa staff ang battery, tires, fluid levels, brakes, ug wipers gamit ang checklist.
3. **Service Selection:** Pili-on ang serbisyo gikan sa `job_order_service_types` (Change Oil, Brake Cleaning, Wheel Balancing, Engine Diagnostics).
4. **Mechanic Assignment:** I-assign ang duty nga mekaniko gikan sa `mechanics` list aron ma-track ang iyang labor hours ug commission.
5. **Parts Requisition:** Kung kinahanglan og oil filter o engine oil, i-link kini diretso gikan sa C-Store inventory ngadto sa Job Order ticket.
6. Kung standard PMS, diretso sa checkout. Kung high-cost overhaul repair, mo-agi og Manager Approval sa dili pa sugdan ang trabaho.

#### D. Payment Processing & Checkout (Tender Options)
Mopili ang staff sa saktong Mode of Payment (`payment_methods`):
* **Option 1: Cash**
  * I-encode ang Cash Tendered gikan sa kustomer; awtomatikong kuwentahon sa system ang saktong sukli (Change).
* **Option 2: Credit / Debit Card o E-Wallet (GCash/Maya)**
  * I-swipe o i-scan sa external POS terminal; i-type sa system ang Terminal Reference Code / Trace Number.
* **Option 3: Petron Fleet Card**
  * I-encode ang Fleet Card Account Number ug Odometer reading sa sakyanan para sa fleet company billing.
* **Option 4: Customer Credit Account (Utang / Charge Invoice)**
  * I-search ang rehistradong customer account.
  * Susi-on sa system: Aktibo ba ang account? Naa pa ba sulod sa iyang Credit Limit?
  * Kung approved, mag-generate og Accounts Receivable entry ubos sa customer ledger.
* **Loyalty Points System:**
  * Pagsulod sa Petron Value Card / Customer ID, awtomatikong madugang ang loyalty points (Default: ₱100 spend = 1 Point).
  * Pwede pud i-redeem ang naipon nga points aron ibawas sa bayronon.

#### E. Transaction Finalization & Receipt
1. Pag-klik sa **"Complete Transaction"**, buhaton sa database ang mosunod sulod sa usa ka atomic transaction:
   * I-deduct ang litro sa `fuel_inventory` ug ang merchandise items sa `station_inventory`.
   * I-save ang transaction details sa `merchandise_transactions` o `fuel_transactions`.
   * I-log ang transaksyon sa `activity_logs` nga naka-tag sa cashier.
2. Mo-generate ug mo-print dayon ang Official Receipt (`receipt.php` o PDF format).

---

### PHASE 6: EXCEPTIONS, ANOMALY HANDLING & SECURITY OVERRIDES
*Kini ang proseso kung naay masayop nga transaksyon o hangyo nga cancellation.*

1. **Staff triggers Override Request**:
   * Kung nasayop ang staff sa pag-punch sa item o pump number, mangayo siya og **Transaction Void** o **Price Adjustment**.
   * Dili tugotan sa sistema ang Staff nga mag-void og transaksyon sa iyang kaugalingon.

2. **Manager / Admin Verification**:
   * Mo-atubang ang Station Manager o Station Admin sa terminal.
   * I-input ang iyang Security Override PIN o Password.
   * I-encode ang rason sa pag-cancel (Remarks).

3. **Audit Trail Logging**:
   * Walay record nga basta-basta mapapas sa database.
   * Ang transaksyon i-markahan og `status = 'voided'` ug i-kopya sa `admin_voided_transactions`.
   * Tanan detalye (kinsang cashier ang nag-punch, kinsang manager ang nag-approve, oras, ug kantidad) ma-rekord sa `audit_trail`.
   * Makadawat og notification alert ang Station Admin bahin sa nahitabong void.

---

### PHASE 7: SHIFT CLOSING, FUEL METER READING & TANK RECONCILIATION
*Kini ang pinaka-importanteng operational flow sa pagtapos sa matag shift aron masigurong walay nawalang lana o kwarta.*

1. **Staff encodes Closing Totalizers & Cash Count**:
   * Sa pagtapos sa duty, moadto ang Staff sa `staff_fuel_sales_closing.php`.
   * I-encode ang **Ending Totalizer Meter Readings** sa tanang pump nozzles.
   * I-encode ang **Physical Tank Dipstick Level** (actual nga gidaghanon sa lana nga nahabilin sa ilawom sa yuta).
   * Ihapon ang tanang kwarta sa kaha (baryo, papel nga kwarta, card slips, fleet vouchers) ug i-encode sa Cash Remittance section.
   * I-klik ang **"Submit Shift Report"**.

2. **Reconciliation Engine Automatic Computation**:
   * Awtomatikong kuwentahon sa sistema:
     * **Metered Volume Sold:** Ending Meter minus Starting Meter minus Calibration/Testing Volume.
     * **Theoretical Book Stock:** Opening Stock plus Deliveries Received minus Metered Volume Sold.
     * **Fuel Variance (Discrepancy):** Actual Physical Dipstick Volume minus Theoretical Book Stock.
     * **Cash Discrepancy:** Actual Remitted Cash minus Total System Sales.

3. **Station Manager reviews & reconciles Variance**:
   * Mo-abli ang Manager sa `fuel_reconciliation_workflow.php` ug `manager_fuel_reconciliation.php`.
   * **Scenario A: Sulod sa Tolerance ($\le 0.5\%$)**
     * Normal kining evaporation o temperature shrinkage.
     * I-klik sa Manager ang **"Approve & Reconcile"**.
   * **Scenario B: Gawas sa Tolerance ($> 0.5\%$ Shortage o Overage)**
     * Mag-trigger ang system og **High Variance Alert**.
     * Kinahanglang maghimo ang Manager og physical investigation: Susi-on ang pump calibration gamit ang 10-liter calibrating bucket, susi-on kung naay leak sa tangke, o susi-on kung naay unauthorized dispensing.
     * Mag-encode ang Manager og formal investigation notes ug i-post ang adjustment sa `fuel_adjustments`.
   * Human sa validation, i-lock sa Manager ang shift report aron dili na mausab ang sales data.

---

### PHASE 8: STATION CONSOLIDATION, AUDITS & NATIONWIDE HQ ROLLUP
*Kini ang pag-consolidate sa tanang datos padulong sa kataas-taasang lebel (Super Admin).*

1. **Station Manager Daily Sign-off**:
   * Sa pagtapos sa adlaw (Midnight Closing), i-generate sa Manager ang Daily Station Summary sa `manager_reports.php`.
   * I-consolidate ang halin sa Fuel, C-Store, ug Car Care Bay Services.

2. **Station Admin Branch Audit**:
   * Mo-abli ang Station Admin sa `admin_reports.php` ug `admin_audit_trail.php`.
   * I-review ang kinatibuk-ang Monthly Revenue, Inventory Turnover, Gross Profit Margins, ug Supplier Payables.
   * I-check ang system security logs kung duna bay failed login attempts o kadudahan nga mga transactions.

3. **Super Admin Nationwide Rollup**:
   * Mo-login ang Super Admin sa `super_admin_dashboard.php` ug `reports_technical.php`.
   * Makit-an niya sa tibuok Pilipinas:
     * **Nationwide Fuel Volume Sold:** Pila ka milyon ka litro sa Diesel, Gasoline, ug Kerosene ang nahalin sa matag rehiyon (Luzon, Visayas, Mindanao).
     * **Station Performance Ranking:** Kinsang mga estasyon ang pinaka-kusog mohalin ug kinsay ubos ang performance.
     * **National Inventory Balances:** Kinatibuk-ang stock sa lana nga anaa pa sa tanang tangke sa estasyon aron ma-coordinate sa Petron Logistics Depot.
     * **System Maintenance & Backups:** Pagpahigayon og database backups pinaagi sa `database_management.php` ug pag-monitor sa system uptime ug error logs.

---

## 3. SUMMARY SA MGA DATABASE TABLES NGA NALAMBIGIT (DATA FLOW)

Aron masabtan kung giunsa pagtipig sa backend ang mga datos sa matag lakang:

* **Users & Stations:** `stations`, `users`, `user_preferences`, `login_attempts`, `shifts`, `ph_regions`.
* **Fuel & Pumps:** `fuel_types`, `fuel_pumps`, `fuel_pricing`, `fuel_price_change_log`, `fuel_calibration_records`.
* **Inventory & Deliveries:** `fuel_inventory`, `fuel_deliveries`, `station_inventory`, `products`, `merchandise_batches`, `merchandise_stock_in`, `purchase_orders`.
* **Sales & POS:** `fuel_transactions`, `merchandise_transactions`, `merchandise_transaction_items`, `payment_methods`, `customers`, `customer_vehicles`.
* **Car Care Bay:** `job_orders`, `job_order_parts`, `job_order_service_types`, `mechanics`, `labor_sessions`, `vehicle_inspection_items`.
* **Reconciliation & Auditing:** `fuel_sales_closing`, `fuel_reconciliation_sessions`, `fuel_adjustments`, `activity_logs`, `audit_trail`, `admin_voided_transactions`, `sys_health_report_log`.

---

## 4. MAAYONG BATASAN SA OPERASYON (QUICK REFERENCE SUMMARY)

1. **Buntag (Morning Shift Handover):**
   * Staff: Check float $\rightarrow$ starting pump meters $\rightarrow$ open POS.
   * Manager: Check active pump status $\rightarrow$ review pending deliveries.
2. **Adlawan (Operating Hours):**
   * Staff: Process fuel, grocery, and job orders $\rightarrow$ issue receipts $\rightarrow$ receive stock arrivals.
   * Manager: Approve high-value JOs $\rightarrow$ validate delivery dipsticks $\rightarrow$ handle voids.
   * Station Admin: Monitor inventory thresholds $\rightarrow$ approve supplier POs.
3. **Gabii / Turnover (Shift Closing):**
   * Staff: Encode ending totalizers $\rightarrow$ encode physical tank dip $\rightarrow$ count and submit cash.
   * Manager: Review reconciliation variance $\rightarrow$ sign-off shift $\rightarrow$ lock records.
   * Super Admin: View consolidated nationwide daily sales and automated database backups.
