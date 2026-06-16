<?php
$page_id = 'inventory';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php');
    exit;
}

/* ── DATA FETCH ── */
$fuel_inventory  = [];
$merch_inventory = [];
$stock_requests  = [];
$categories      = [];
$msg             = '';

try {
    // Fuel inventory
    $stmt = $pdo->prepare("
        SELECT ip.product_name AS name,
               COALESCE(fi.price_per_liter, ip.unit_cost) AS price,
               COALESCE(fi.current_level, fi.current_stock, ip.stock, 0) AS stock_level,
               COALESCE(fi.capacity, 20000.00)            AS capacity
        FROM inventory_products ip
        LEFT JOIN fuel_inventory fi
               ON ip.product_name = fi.fuel_type AND fi.station_id = ?
        WHERE ip.category = 'Fuel'
        ORDER BY ip.product_name
    ");
    $stmt->execute([$station_id]);
    $fuel_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Merchandise inventory — use station_inventory for per-station stock levels
    $stmt = $pdo->prepare("
        SELECT ip.id,
               ip.product_name AS name,
               ip.category     AS category_name,
               ip.unit_price   AS price,
               ip.unit_cost    AS cost,
               ip.sku,
               COALESCE(si.stock_level, ip.stock, 0) AS stock_level,
               COALESCE(si.reorder_level, 10)        AS reorder_level
        FROM inventory_products ip
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category NOT IN ('Fuel') AND ip.status = 'Active'
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stock requests (this staff member only)
    $stmt = $pdo->prepare("
        SELECT sr.*, u.name AS staff_name, m.name AS manager_name
        FROM stock_requests sr
        JOIN users u ON sr.staff_id = u.id
        LEFT JOIN users m ON sr.manager_id = m.id
        WHERE sr.staff_id = ? AND sr.station_id = ?
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([$me['id'], $station_id]);
    $stock_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $msg = 'Error loading inventory: ' . $e->getMessage();
}

$pending_count = count(array_filter($stock_requests, fn($r) => $r['status'] === 'Pending'));

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Section cards ── */
.inv-section {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border: 1px solid #e9ecef;
    margin-bottom: 28px;
    scroll-margin-top: 24px;
    display: block; /* Default display */
}

/* Initially hide all sections except fuel */
.inv-section:not(#fuel) {
    display: none;
}
.inv-section-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px 14px;
    border-bottom: 1px solid #e9ecef;
    flex-wrap: wrap; gap: 8px;
}
.inv-section-title {
    font-size: 1rem; font-weight: 700; color: #002F70;
    display: flex; align-items: center; gap: 8px;
}
.inv-section-body { padding: 20px 22px; }

/* ── Readonly badge ── */
.readonly-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: #e3f2fd; color: #1565c0; border: 1px solid #90caf9;
    border-radius: 20px; padding: 3px 11px; font-size: 11px; font-weight: 600;
}

/* ── Category header rows ── */
.cat-header td {
    font-weight: 700; background: #e9ecef !important; color: #495057 !important;
    text-transform: uppercase; font-size: .8em; letter-spacing: .5px;
    border-bottom: 2px solid #dee2e6; padding: 8px 12px;
}
.merch-row:hover { background: #f8f9fa; }
.cost-col  { color: #6c757d; font-size: .9em; }
.price-col { color: #28a745; font-weight: 700; }
.profit-sm { font-size: .76em; color: #17a2b8; margin-left: 3px; }

/* ── Status badges ── */
.sbadge {
    display: inline-block; padding: 3px 10px; border-radius: 12px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
}
.sbadge-pending   { background: #fff3cd; color: #856404; }
.sbadge-approved  { background: #d1ecf1; color: #0c5460; }
.sbadge-rejected  { background: #f8d7da; color: #721c24; }

/* ── Dropdown styling (match fuel management) ── */
.select {
    width: 100%;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid #EAEAEA;
    background: #fff;
    outline: none;
    font: inherit;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23667085' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 16px;
    padding-right: 40px;
}

.select:focus {
    border-color: #8099b3;
    box-shadow: 0 0 0 4px rgba(0, 51, 102, .12);
}

/* ── For dropdowns with specific width ── */
.select.auto-width {
    width: auto;
    }
.sbadge-completed { background: #d4edda; color: #155724; }

/* ── History legend ── */
.hist-legend {
    display: flex; gap: 14px; flex-wrap: wrap;
    background: #f8f9fa; border: 1px solid #dee2e6;
    border-radius: 8px; padding: 10px 16px; margin-bottom: 16px; font-size: 12px;
}
.hist-legend span { display: flex; align-items: center; gap: 5px; }

/* ── Search ── */
#merchSearch {
    padding: 8px 12px; border: 1px solid #ced4da;
    border-radius: 4px; font-size: 14px; width: 100%;
}
#merchSearch:focus { border-color: #80bdff; outline: 0; box-shadow: 0 0 0 .2rem rgba(0,123,255,.25); }
.search-wrap { max-width: 300px; margin-bottom: 14px; }

/* ── Stock request btn ── */
.sr-btn {
    background: #002F70; color: #fff; border: none;
    padding: 5px 12px; font-size: 12px; border-radius: 4px; cursor: pointer;
    transition: background .15s;
}
.sr-btn:hover { background: #001F4F; }

/* ── Modal (fuel stock request) ── */
.modal { display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.55); }
.modal.show { display:flex; align-items:center; justify-content:center; }
.modal-box { background:#fff; border-radius:14px; padding:28px; width:90%; max-width:520px; max-height:88vh; overflow-y:auto; position:relative; animation:modalIn .22s ease; }
@keyframes modalIn { from{opacity:0;transform:scale(.95)} to{opacity:1;transform:scale(1)} }
.modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:2px solid #e9ecef; }
.modal-title { font-size:1.05rem; font-weight:700; color:#002F70; }
.modal-close { background:none; border:none; font-size:1.5rem; cursor:pointer; color:#999; line-height:1; }
.modal-close:hover { color:#333; }
.modal-footer { display:flex; gap:10px; margin-top:20px; padding-top:14px; border-top:1px solid #e9ecef; }

/* ── Fuel request table rows ── */
.fsr-row:hover { background:#fdf5f5 !important; }
.fsr-row td { transition: background .12s; }

/* ── Fuel type radio cards (kept for backward compat, no longer used) ── */
.fsr-fuel-card {
    display:flex; align-items:center; gap:12px;
    border:2px solid #e9ecef; border-radius:10px; padding:10px 14px;
    cursor:pointer; transition:all .15s; background:#fff;
}
.fsr-fuel-card:hover { border-color:#c0392b; background:#fff5f5; }
.fsr-fuel-card.selected { border-color:#c0392b; background:#fff0ee; }
.fsr-fuel-card input[type=radio] { accent-color:#c0392b; width:16px; height:16px; flex-shrink:0; }
.fsr-status-badge {
    display:inline-block; padding:2px 9px; border-radius:10px;
    font-size:11px; font-weight:700; white-space:nowrap;
}
.fsr-status-out   { background:#f8d7da; color:#721c24; }
.fsr-status-crit  { background:#f8d7da; color:#721c24; }
.fsr-status-low   { background:#fff3cd; color:#856404; }
.fsr-status-lows  { background:#fff3cd; color:#856404; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-boxes"></i> Inventory</h1>
        <div class="sub">Station #<?php echo (int)$station_id; ?> &mdash; <?php echo htmlspecialchars($me['name'] ?? ''); ?></div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<?php if ($msg): ?>
<div class="card" style="margin-bottom:16px;padding:14px;color:#721c24;background:#f8d7da;border-radius:8px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     SECTION 1 — FUEL INVENTORY  (id="fuel")
     Read-only monitoring + Stock Request button.
══════════════════════════════════════════════════════ -->
<div id="fuel" class="inv-section">
    <div class="inv-section-head">
        <div class="inv-section-title">
            <i class="fas fa-gas-pump"></i> Fuel Inventory
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button id="fuelStockRequestBtn" class="sr-btn" style="background:#c0392b;padding:7px 16px;font-size:13px;border-radius:6px;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-gas-pump"></i> Stock Request
            </button>
        </div>
    </div>
    <div class="inv-section-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th>Current Level</th>
                        <th>Capacity</th>
                        <th>Fill %</th>
                        <th>Price / L</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($fuel_inventory)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:24px;color:#6c757d;">No fuel inventory data available.</td></tr>
                <?php else: ?>
                    <?php foreach ($fuel_inventory as $fuel):
                        $fl  = (float)($fuel['stock_level'] ?? 0);
                        $cap = (float)($fuel['capacity']    ?? 1);
                        $pct = $cap > 0 ? ($fl / $cap) * 100 : 0;
                        if ($fl <= 0)       { $sc = '#dc3545'; }
                        elseif ($pct <= 10) { $sc = '#dc3545'; }
                        elseif ($pct <= 25) { $sc = '#fd7e14'; }
                        elseif ($fl <= 500) { $sc = '#fd7e14'; }
                        else                { $sc = '#28a745'; }
                        $bar_cls = $pct > 50 ? 'fill-ok' : ($pct > 20 ? 'fill-low' : 'fill-crit');
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($fuel['name']); ?></strong></td>
                        <td><?php echo number_format($fl, 2); ?> L</td>
                        <td><?php echo number_format($cap, 2); ?> L</td>
                        <td style="">
                            <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;">
                                <div style="width:<?php echo min(100, round($pct)); ?>%;height:100%;background:<?php echo $sc; ?>;border-radius:4px;"></div>
                            </div>
                            <small style="color:#6c757d;"><?php echo round($pct, 1); ?>%</small>
                        </td>
                        <td>&#8369;<?php echo number_format($fuel['price'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     FUEL STOCK REQUEST MODAL  (multi-select)
══════════════════════════════════════════════════════ -->
<div id="fuelStockRequestModal" class="modal">
    <div class="modal-box" style="max-width:700px;">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#c0392b,#922b21);border-radius:10px 10px 0 0;margin:-28px -28px 20px;padding:16px 22px;display:flex;justify-content:space-between;align-items:center;">
            <div style="color:#fff;font-size:1.05rem;font-weight:700;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-gas-pump"></i> Fuel Stock Request
                <span id="fsrSelCount" style="background:rgba(255,255,255,.25);border-radius:10px;padding:1px 9px;font-size:12px;display:none;">0 selected</span>
            </div>
            <button onclick="closeFuelModal()" style="background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;opacity:.8;line-height:1;">&#x2715;</button>
        </div>

        <!-- Loading -->
        <div id="fsrLoading" style="text-align:center;padding:36px;color:#6c757d;">
            <i class="fas fa-spinner fa-spin" style="font-size:1.8rem;display:block;margin-bottom:10px;"></i>
            Loading fuel status...
        </div>

        <!-- Content -->
        <div id="fsrContent" style="display:none;">


            <!-- All-stocked message -->
            <div id="fsrNoLow" style="display:none;text-align:center;padding:32px;color:#28a745;">
                <i class="fas fa-check-circle" style="font-size:2.5rem;display:block;margin-bottom:10px;"></i>
                <strong>All fuel types are sufficiently stocked.</strong><br>
                <span style="font-size:13px;color:#6c757d;">No stock request is needed at this time.</span>
            </div>

            <!-- Multi-select table -->
            <form id="fuelStockRequestForm" style="display:none;">

                <!-- Select-all row -->
                <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:7px 7px 0 0;border-bottom:none;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;font-weight:700;color:#495057;user-select:none;">
                        <input type="checkbox" id="fsrSelectAll" style="width:15px;height:15px;accent-color:#c0392b;cursor:pointer;">
                        Select All
                    </label>
                    <span style="margin-left:auto;font-size:11px;color:#6c757d;" id="fsrSelHint">Check the fuels you want to request</span>
                </div>

                <!-- Fuel rows table -->
                <div style="border:1px solid #dee2e6;border-radius:0 0 8px 8px;overflow:hidden;margin-bottom:16px;">
                    <table style="width:100%;border-collapse:collapse;" id="fsrFuelTable">
                        <thead>
                            <tr style="background:#f1f3f5;">
                                <th style="width:36px;padding:8px 10px;text-align:center;font-size:11px;color:#6c757d;font-weight:700;border-bottom:1px solid #dee2e6;"></th>
                                <th style="padding:8px 10px;text-align:left;font-size:11px;color:#6c757d;font-weight:700;border-bottom:1px solid #dee2e6;">FUEL TYPE</th>
                                <th style="padding:8px 10px;text-align:center;font-size:11px;color:#6c757d;font-weight:700;border-bottom:1px solid #dee2e6;">LEVEL / CAPACITY</th>
                                <th style="padding:8px 10px;text-align:center;font-size:11px;color:#6c757d;font-weight:700;border-bottom:1px solid #dee2e6;">STATUS</th>
                            </tr>
                        </thead>
                        <tbody id="fsrFuelRows">
                            <!-- Populated by JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Validation error -->
                <div id="fsrError" style="display:none;background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:6px;padding:9px 13px;font-size:13px;margin-bottom:12px;">
                    <i class="fas fa-exclamation-circle"></i> <span id="fsrErrorMsg"></span>
                </div>

                <div class="modal-footer" style="margin-top:0;padding-top:14px;border-top:1px solid #e9ecef;display:flex;align-items:center;gap:10px;">
                    <button type="submit" id="fsrSubmitBtn"
                            style="background:#c0392b;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-weight:700;font-size:14px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
                        <i class="fas fa-paper-plane"></i> Submit Requests
                    </button>
                    <button type="button" onclick="closeFuelModal()"
                            style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;">
                        Cancel
                    </button>
                    <span id="fsrSubmitHint" style="margin-left:auto;font-size:12px;color:#6c757d;"></span>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Fuel Request Success Popup -->
<div id="fsrSuccessOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2999;"></div>
<div id="fsrSuccessPopup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:3000;background:#fff;padding:28px 32px;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.25);text-align:center;max-width:400px;">
    <div style="width:60px;height:60px;background:linear-gradient(135deg,#c0392b,#e74c3c);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
        <i class="fas fa-check" style="color:#fff;font-size:24px;"></i>
    </div>
    <h3 style="margin:0 0 8px;color:#c0392b;">Requests Submitted!</h3>
    <div style="margin:0 0 16px;color:#333;font-size:13px;line-height:1.6;text-align:left;" id="fsrSuccessMsg"></div>
    <p style="margin:0 0 18px;font-size:12px;color:#6c757d;">Track them in <a href="staff_stock_requests.php" style="color:#c0392b;font-weight:700;">Stock Requests</a>.</p>
</div>

<!-- ══════════════════════════════════════════════════════
     SECTION 2 — MERCHANDISE INVENTORY  (id="merch")
     Active transactional tab. Staff encodes Stock Requests.
══════════════════════════════════════════════════════ -->
<div id="merch" class="inv-section">
    <div class="inv-section-head">
        <div class="inv-section-title">
            <i class="fas fa-box"></i> Merchandise Inventory
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button id="merchStockRequestBtn" class="sr-btn" style="background:#002F70;padding:7px 16px;font-size:13px;border-radius:6px;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-box"></i> Stock Request
            </button>
        </div>
    </div>
    <div class="inv-section-body">

        <div class="search-wrap">
            <input id="merchSearch" placeholder="&#128269; Search products..." autocomplete="off" />
        </div>

        <div class="table-wrap">
            <table class="table" id="merchTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Cost</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody id="merchTableBody">
                <?php
                // Group & sort by category
                $categories = [];
                foreach ($merch_inventory as $item) {
                    $cat = $item['category_name'] ?? 'Uncategorized';
                    $categories[$cat][] = $item;
                }
                $cat_order = [
                    'Oils / Lubes / Grease','Car Accessories','Brake System',
                    'Tire','Maintenance','Oil / Fuel Filters','Others (Snacks / Drinks)'
                ];
                $sorted = [];
                foreach ($cat_order as $k) { if (isset($categories[$k])) $sorted[$k] = $categories[$k]; }
                foreach ($categories as $k => $v) { if (!in_array($k, $cat_order)) $sorted[$k] = $v; }

                foreach ($sorted as $cat_label => $items): ?>
                    <tr class="cat-header">
                        <td colspan="7"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td>
                    </tr>
                    <?php foreach ($items as $item):
                        $stock  = (float)($item['stock_level'] ?? 0);
                        $reord  = (float)($item['reorder_level'] ?? 10);
                        $st     = $stock <= 0 ? 'OUT OF STOCK' : ($stock <= $reord ? 'LOW STOCK' : 'AVAILABLE');
                        $sc     = $stock <= 0 ? '#dc3545' : ($stock <= $reord ? '#fd7e14' : '#28a745');
                        $profit = (float)($item['price'] ?? 0) - (float)($item['cost'] ?? 0);
                    ?>
                    <tr class="merch-row" data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>">
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td><?php echo htmlspecialchars($item['sku'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></td>
                        <td><?php echo number_format($stock, 0); ?></td>
                        <td>
                            <span style="color:<?php echo $sc; ?>;font-weight:700;"><?php echo $st; ?></span>
                            <?php if ($item['was_out_of_stock'] ?? false): ?>
                                <span style="font-size:.75em;color:#17a2b8;margin-left:3px;">&#128230; Auto-stocked</span>
                            <?php endif; ?>
                        </td>
                        <td class="cost-col">&#8369;<?php echo number_format($item['cost'], 2); ?></td>
                        <td class="price-col">
                            &#8369;<?php echo number_format($item['price'], 2); ?>
                            <?php if ($profit > 0): ?>
                                <span class="profit-sm">(+<?php echo number_format($profit, 2); ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <?php if (empty($merch_inventory)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:24px;color:#6c757d;">No merchandise data available.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SECTION 3 — removed: Stock Requests moved to staff_stock_requests.php
     The sidebar "Stock Requests" link goes directly to that page.
══════════════════════════════════════════════════════ -->

<!-- ══════════════════════════════════════════════════════
     MERCHANDISE STOCK REQUEST MODAL  (multi-select)
══════════════════════════════════════════════════════ -->
<div id="stockRequestModal" class="modal">
    <div class="modal-box" style="max-width:760px;">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#002F70,#004aad);border-radius:10px 10px 0 0;margin:-28px -28px 20px;padding:16px 22px;display:flex;justify-content:space-between;align-items:center;">
            <div style="color:#fff;font-size:1.05rem;font-weight:700;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-box"></i> Merchandise Stock Request
                <span id="msrSelCount" style="background:rgba(255,255,255,.25);border-radius:10px;padding:1px 9px;font-size:12px;display:none;">0 selected</span>
            </div>
            <button onclick="closeMsrModal()" style="background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;opacity:.8;line-height:1;">&#x2715;</button>
        </div>

        <!-- Loading -->
        <div id="msrLoading" style="text-align:center;padding:36px;color:#6c757d;">
            <i class="fas fa-spinner fa-spin" style="font-size:1.8rem;display:block;margin-bottom:10px;"></i>
            Loading low-stock items...
        </div>

        <!-- Content -->
        <div id="msrContent" style="display:none;">

            <!-- All-stocked message -->
            <div id="msrNoLow" style="display:none;text-align:center;padding:32px;color:#28a745;">
                <i class="fas fa-check-circle" style="font-size:2.5rem;display:block;margin-bottom:10px;"></i>
                <strong>All merchandise items are sufficiently stocked.</strong><br>
                <span style="font-size:13px;color:#6c757d;">No stock request is needed at this time.</span>
            </div>

            <!-- Search filter inside modal -->
            <div id="msrSearchWrap" style="display:none;margin-bottom:10px;">
                <input id="msrSearch" placeholder="&#128269; Filter items..." autocomplete="off"
                       style="width:100%;padding:8px 12px;border:1px solid #ced4da;border-radius:6px;font-size:13px;box-sizing:border-box;">
            </div>

            <form id="msrForm" style="display:none;">

                <!-- Select-all row -->
                <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:7px 7px 0 0;border-bottom:none;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;font-weight:700;color:#495057;user-select:none;">
                        <input type="checkbox" id="msrSelectAll" style="width:15px;height:15px;accent-color:#002F70;cursor:pointer;">
                        Select All
                    </label>
                    <span style="margin-left:auto;font-size:11px;color:#6c757d;" id="msrSelHint">Check the items you want to request</span>
                </div>

                <!-- Items table -->
                <div style="border:1px solid #dee2e6;border-radius:0 0 8px 8px;overflow:hidden;margin-bottom:14px;max-height:340px;overflow-y:auto;">
                    <table style="width:100%;border-collapse:collapse;" id="msrItemTable">
                        <thead style="position:sticky;top:0;z-index:1;">
                            <tr style="background:#f1f3f5;">
                                <th style="width:36px;padding:8px 10px;text-align:center;font-size:11px;color:#6c757d;font-weight:700;border-bottom:1px solid #dee2e6;"></th>
                                <th style="padding:8px 10px;text-align:left;font-size:11px;color:#6c757d;font-weight:700;border-bottom:1px solid #dee2e6;">PRODUCT</th>
                                <th style="padding:8px 10px;text-align:left;font-size:11px;color:#6c757d;font-weight:700;border-bottom:1px solid #dee2e6;">CATEGORY</th>
                                <th style="padding:8px 10px;text-align:center;font-size:11px;color:#6c757d;font-weight:700;border-bottom:1px solid #dee2e6;">STOCK</th>
                                <th style="padding:8px 10px;text-align:center;font-size:11px;color:#6c757d;font-weight:700;border-bottom:1px solid #dee2e6;">STATUS</th>
                            </tr>
                        </thead>
                        <tbody id="msrItemRows">
                            <!-- Populated by JS from PHP data -->
                        </tbody>
                    </table>
                </div>

                <!-- Validation error -->
                <div id="msrError" style="display:none;background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:6px;padding:9px 13px;font-size:13px;margin-bottom:12px;">
                    <i class="fas fa-exclamation-circle"></i> <span id="msrErrorMsg"></span>
                </div>

                <div style="display:flex;align-items:center;gap:10px;padding-top:14px;border-top:1px solid #e9ecef;">
                    <button type="submit" id="msrSubmitBtn"
                            style="background:#002F70;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-weight:700;font-size:14px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
                        <i class="fas fa-paper-plane"></i> Submit Requests
                    </button>
                    <button type="button" onclick="closeMsrModal()"
                            style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;">
                        Cancel
                    </button>
                    <span id="msrSubmitHint" style="margin-left:auto;font-size:12px;color:#6c757d;"></span>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Merchandise Stock Request Success Popup -->
<div id="msrSuccessOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2999;"></div>
<div id="msrSuccessPopup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:3000;background:#fff;padding:28px 32px;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.25);text-align:center;max-width:420px;">
    <div style="width:60px;height:60px;background:linear-gradient(135deg,#002F70,#004aad);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
        <i class="fas fa-check" style="color:#fff;font-size:24px;"></i>
    </div>
    <h3 style="margin:0 0 8px;color:#002F70;">Requests Submitted!</h3>
    <div style="margin:0 0 16px;color:#333;font-size:13px;line-height:1.6;text-align:left;" id="msrSuccessMsg"></div>
    <p style="margin:0 0 18px;font-size:12px;color:#6c757d;">Track them in <a href="staff_stock_requests.php" style="color:#002F70;font-weight:700;">Stock Requests</a>.</p>
    <button onclick="closeMsrSuccess()" style="background:#002F70;color:#fff;border:none;padding:9px 28px;border-radius:6px;cursor:pointer;font-weight:700;">OK</button>
</div>

<!-- ── Legacy success popup (kept for backward compat) ── -->
<div id="successOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1999;"></div>
<div id="successPopup"  style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2000;background:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);text-align:center;">
    <div style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-check" style="color:#fff;font-size:22px;"></i>
    </div>
    <h3 style="margin:0 0 8px;color:#28a745;">Request Submitted!</h3>
    <p style="margin:0 0 18px;color:#333;font-size:14px;line-height:1.5;">
        Your stock request is now <strong>Pending</strong> Manager validation.<br>
        Track it in <a href="staff_stock_requests.php" style="color:#002F70;font-weight:700;">Stock Requests</a>.
    </p>
    <button onclick="closeSuccess()" style="background:#002F70;color:#fff;border:none;padding:9px 26px;border-radius:6px;cursor:pointer;font-weight:600;">OK</button>
</div>

<script>
/* ── Merchandise search ── */
document.getElementById('merchSearch').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        r.style.display = (r.getAttribute('data-name') || '').indexOf(q) !== -1 ? '' : 'none';
    });
});

/* ── Legacy close-modal handler (kept for safety) ── */
document.addEventListener('click', function(e) {
    if (e.target.id === 'successOverlay') closeSuccess();
});

function closeSuccess() {
    document.getElementById('successPopup').style.display  = 'none';
    document.getElementById('successOverlay').style.display = 'none';
    location.reload();
}

/* ══════════════════════════════════════════════════════
   MERCHANDISE STOCK REQUEST MODAL — multi-select JS
   Items are built from PHP-rendered data embedded below.
══════════════════════════════════════════════════════ */

// PHP passes all merch items as JSON for the modal
var msrAllItems = <?php
    $modal_items = [];
    foreach ($merch_inventory as $item) {
        $stock = (float)($item['stock_level'] ?? 0);
        $reord = (float)($item['reorder_level'] ?? 10);
        $st    = $stock <= 0 ? 'OUT OF STOCK' : ($stock <= $reord ? 'LOW STOCK' : 'AVAILABLE');
        if ($st === 'AVAILABLE') continue; // only low/out
        $modal_items[] = [
            'id'       => (int)($item['id'] ?? 0),
            'name'     => $item['name'] ?? '',
            'sku'      => $item['sku'] ?? '',
            'category' => $item['category_name'] ?? '',
            'stock'    => (int)$stock,
            'status'   => $st,
        ];
    }
    echo json_encode($modal_items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

document.getElementById('merchStockRequestBtn').addEventListener('click', openMsrModal);

document.getElementById('stockRequestModal').addEventListener('click', function(e) {
    if (e.target === this) closeMsrModal();
});

function openMsrModal() {
    document.getElementById('stockRequestModal').classList.add('show');
    document.body.style.overflow = 'hidden';

    // Reset
    document.getElementById('msrLoading').style.display  = 'block';
    document.getElementById('msrContent').style.display  = 'none';
    document.getElementById('msrError').style.display    = 'none';
    document.getElementById('msrSelCount').style.display = 'none';
    document.getElementById('msrSelectAll').checked      = false;
    document.getElementById('msrSearch').value           = '';

    // Simulate brief load then render from embedded data
    setTimeout(function() {
        document.getElementById('msrLoading').style.display = 'none';
        document.getElementById('msrContent').style.display = 'block';

        if (msrAllItems.length === 0) {
            document.getElementById('msrNoLow').style.display    = 'block';
            document.getElementById('msrSearchWrap').style.display = 'none';
            document.getElementById('msrForm').style.display     = 'none';
            return;
        }

        document.getElementById('msrNoLow').style.display    = 'none';
        document.getElementById('msrSearchWrap').style.display = 'block';
        document.getElementById('msrForm').style.display     = 'block';

        buildMsrRows(msrAllItems);
        updateMsrCount();
    }, 120);
}

function buildMsrRows(items) {
    var tbody = document.getElementById('msrItemRows');
    var html  = '';

    items.forEach(function(item, i) {
        var isOut    = item.status === 'OUT OF STOCK';
        var badgeBg  = isOut ? '#f8d7da' : '#fff3cd';
        var badgeClr = isOut ? '#721c24' : '#856404';
        var rowId    = 'msr_row_' + i;
        var cbId     = 'msr_cb_' + i;
        var qtyId    = 'msr_qty_' + i;
        var remId    = 'msr_rem_' + i;
        var idata    = encodeURIComponent(JSON.stringify(item));

        html +=
            '<tr id="' + rowId + '" class="msr-row" data-item="' + idata + '" data-name="' + escHtml(item.name.toLowerCase()) + '" style="border-bottom:1px solid #f0f0f0;transition:background .12s;">' +

            // Checkbox
            '<td style="text-align:center;padding:9px 8px;vertical-align:middle;">' +
                '<input type="checkbox" id="' + cbId + '" class="msr-cb" data-row="' + i + '"' +
                '       style="width:16px;height:16px;accent-color:#002F70;cursor:pointer;">' +
            '</td>' +

            // Product name + SKU
            '<td style="padding:9px 10px;vertical-align:middle;">' +
                '<label for="' + cbId + '" style="cursor:pointer;">' +
                    '<strong style="font-size:13px;color:#002F70;">' + escHtml(item.name) + '</strong>' +
                '</label>' +
                '<div style="font-size:10px;color:#aaa;margin-top:2px;">' + escHtml(item.sku) + '</div>' +
            '</td>' +

            // Category
            '<td style="padding:9px 8px;vertical-align:middle;font-size:12px;color:#555;">' +
                escHtml(item.category) +
            '</td>' +

            // Stock
            '<td style="padding:9px 8px;text-align:center;vertical-align:middle;font-size:13px;font-weight:700;color:' + (isOut ? '#dc3545' : '#fd7e14') + ';">' +
                item.stock +
            '</td>' +

            // Status badge
            '<td style="padding:9px 8px;text-align:center;vertical-align:middle;">' +
                '<span style="background:' + badgeBg + ';color:' + badgeClr + ';padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap;">' +
                    escHtml(item.status) +
                '</span>' +
            '</td>' +

            '</tr>';
    });

    tbody.innerHTML = html;

    // Checkbox toggle
    document.querySelectorAll('.msr-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            toggleMsrRow(this.dataset.row, this.checked);
            syncMsrSelectAll();
            updateMsrCount();
        });
    });

    // Select-all
    document.getElementById('msrSelectAll').addEventListener('change', function() {
        var checked = this.checked;
        // Only toggle visible rows
        document.querySelectorAll('.msr-row').forEach(function(row) {
            if (row.style.display === 'none') return;
            var cb = row.querySelector('.msr-cb');
            if (cb && cb.checked !== checked) {
                cb.checked = checked;
                toggleMsrRow(cb.dataset.row, checked);
            }
        });
        updateMsrCount();
    });

    // Modal search filter
    document.getElementById('msrSearch').addEventListener('input', function() {
        var q = this.value.toLowerCase();
        document.querySelectorAll('.msr-row').forEach(function(row) {
            row.style.display = (row.dataset.name || '').indexOf(q) !== -1 ? '' : 'none';
        });
        syncMsrSelectAll();
    });
}

function toggleMsrRow(i, on) {
    var rowEl = document.getElementById('msr_row_' + i);
    rowEl.style.background = on ? '#f0f4ff' : '';
}

function syncMsrSelectAll() {
    var visible  = Array.from(document.querySelectorAll('.msr-row')).filter(function(r) { return r.style.display !== 'none'; });
    var checked  = visible.filter(function(r) { return r.querySelector('.msr-cb').checked; });
    var allCb    = document.getElementById('msrSelectAll');
    allCb.checked       = checked.length > 0 && checked.length === visible.length;
    allCb.indeterminate = checked.length > 0 && checked.length < visible.length;
}

function updateMsrCount() {
    var n    = document.querySelectorAll('.msr-cb:checked').length;
    var el   = document.getElementById('msrSelCount');
    var hint = document.getElementById('msrSubmitHint');
    if (n > 0) {
        el.textContent = n + ' selected';
        el.style.display = 'inline';
        if (hint) hint.textContent = n + ' item' + (n > 1 ? 's' : '') + ' will be submitted';
    } else {
        el.style.display = 'none';
        if (hint) hint.textContent = '';
    }
}

function closeMsrModal() {
    document.getElementById('stockRequestModal').classList.remove('show');
    document.body.style.overflow = '';
}

// Form submit — batch
document.getElementById('msrForm').addEventListener('submit', function(e) {
    e.preventDefault();
    document.getElementById('msrError').style.display = 'none';

    var checked = document.querySelectorAll('.msr-cb:checked');
    if (checked.length === 0) {
        showMsrError('Please select at least one item to request.');
        return;
    }

    var items    = [];
    var hasError = false;

    checked.forEach(function(cb) {
        if (hasError) return;
        var i    = cb.dataset.row;
        var item = JSON.parse(decodeURIComponent(document.getElementById('msr_row_' + i).dataset.item));

        items.push({
            item_id:            item.id,
            sku:                item.sku,
            item_name:          item.name,
            item_category:      item.category,
            current_stock:      item.stock,
            requested_quantity: 0,
            remarks:            ''
        });
    });

    if (items.length === 0) return;

    var btn = document.getElementById('msrSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    var results = [];
    var errors  = [];
    var chain   = Promise.resolve();

    items.forEach(function(item) {
        chain = chain.then(function() {
            return fetch('../backend/api/stock_request.php?action=create', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(item)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    results.push(item.item_name);
                } else {
                    errors.push(item.item_name + ': ' + (res.message || 'Failed'));
                }
            });
        });
    });

    chain.then(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Requests';

        if (results.length > 0) {
            closeMsrModal();

            var msgHtml = '<ul style="margin:6px 0 0;padding-left:18px;text-align:left;">';
            results.forEach(function(r) { msgHtml += '<li style="margin-bottom:3px;">' + escHtml(r) + '</li>'; });
            msgHtml += '</ul>';
            if (errors.length > 0) {
                msgHtml += '<div style="margin-top:10px;color:#dc3545;font-size:12px;"><strong>Could not submit:</strong><ul style="margin:4px 0 0;padding-left:18px;">';
                errors.forEach(function(r) { msgHtml += '<li>' + escHtml(r) + '</li>'; });
                msgHtml += '</ul></div>';
            }

            document.getElementById('msrSuccessMsg').innerHTML =
                '<strong>' + results.length + ' request' + (results.length > 1 ? 's' : '') + '</strong> submitted — now <strong>Pending</strong> Manager validation:' + msgHtml;
            document.getElementById('msrSuccessPopup').style.display  = 'block';
            document.getElementById('msrSuccessOverlay').style.display = 'block';
            setTimeout(closeMsrSuccess, 8000);
        } else {
            showMsrError(errors.join(' | '));
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Requests';
        showMsrError('Network error. Please try again.');
    });
});

function showMsrError(msg) {
    document.getElementById('msrErrorMsg').textContent = msg;
    document.getElementById('msrError').style.display = 'block';
}

function closeMsrSuccess() {
    document.getElementById('msrSuccessPopup').style.display  = 'none';
    document.getElementById('msrSuccessOverlay').style.display = 'none';
    location.reload();
}

/* ── Tab switching functionality ── */
function switchTab(tabName) {
    // If someone navigates to #history, redirect to the dedicated page
    if (tabName === 'history') {
        window.location.href = 'staff_stock_requests.php';
        return;
    }

    // Hide all sections
    document.querySelectorAll('.inv-section').forEach(function(section) {
        section.style.display = 'none';
    });

    // Show selected section
    var targetSection = document.getElementById(tabName);
    if (targetSection) {
        targetSection.style.display = 'block';
    }

    // Update active states in sidebar
    document.querySelectorAll('.sidebar-sub-item').forEach(function(item) {
        item.classList.remove('active');
    });

    // Find and activate the corresponding sidebar item
    var sidebarLinks = document.querySelectorAll('a[href*="#' + tabName + '"]');
    sidebarLinks.forEach(function(link) {
        link.classList.add('active');
    });
}

/* ── Scroll to anchor on load (sidebar sub-item navigation) ── */
function scrollToHash() {
    var hash = window.location.hash;
    if (hash) {
        var tabName = hash.substring(1); // Remove # symbol
        switchTab(tabName);
        
        var el = document.querySelector(hash);
        if (el) {
            setTimeout(function() {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 150);
        }
    } else {
        // Default to showing fuel section if no hash
        switchTab('fuel');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    scrollToHash();
    
    // Add click handlers for sidebar sub-items
    document.querySelectorAll('.sidebar-sub-item').forEach(function(item) {
        item.addEventListener('click', function(e) {
            var href = this.getAttribute('href');
            if (href && href.includes('#')) {
                var tabName = href.split('#')[1];
                switchTab(tabName);
            }
        });
    });
});

window.addEventListener('hashchange', scrollToHash);

/* ══════════════════════════════════════════════════════
   FUEL STOCK REQUEST MODAL — multi-select JS
══════════════════════════════════════════════════════ */

var fsrFuels = [];

document.getElementById('fuelStockRequestBtn').addEventListener('click', openFuelModal);

document.getElementById('fuelStockRequestModal').addEventListener('click', function(e) {
    if (e.target === this) closeFuelModal();
});

function openFuelModal() {
    document.getElementById('fuelStockRequestModal').classList.add('show');
    document.body.style.overflow = 'hidden';

    // Reset UI
    document.getElementById('fsrLoading').style.display  = 'block';
    document.getElementById('fsrContent').style.display  = 'none';
    document.getElementById('fsrNoLow').style.display    = 'none';
    document.getElementById('fuelStockRequestForm').style.display = 'none';
    document.getElementById('fsrError').style.display    = 'none';
    document.getElementById('fsrSelCount').style.display = 'none';
    document.getElementById('fsrSelectAll').checked      = false;

    fetch('../backend/api/fuel_stock_request.php?action=get_low_stock')
        .then(function(r) { return r.json(); })
        .then(function(res) {
            document.getElementById('fsrLoading').style.display = 'none';
            document.getElementById('fsrContent').style.display = 'block';

            if (!res.success) {
                showFsrError(res.message || 'Could not load fuel data.');
                document.getElementById('fuelStockRequestForm').style.display = 'block';
                return;
            }

            fsrFuels = (res.fuels || []).filter(function(f) { return f.is_low; });

            if (fsrFuels.length === 0) {
                document.getElementById('fsrNoLow').style.display = 'block';
                return;
            }

            document.getElementById('fuelStockRequestForm').style.display = 'block';
            buildFuelRows(fsrFuels);
            updateSelCount();
        })
        .catch(function() {
            document.getElementById('fsrLoading').style.display = 'none';
            document.getElementById('fsrContent').style.display = 'block';
            showFsrError('Network error. Please try again.');
            document.getElementById('fuelStockRequestForm').style.display = 'block';
        });
}

function buildFuelRows(fuels) {
    var tbody = document.getElementById('fsrFuelRows');
    var html  = '';

    fuels.forEach(function(f, i) {
        var barColor  = (f.status === 'OUT OF STOCK' || f.status === 'CRITICAL') ? '#dc3545' : '#fd7e14';
        var badgeBg   = (f.status === 'OUT OF STOCK' || f.status === 'CRITICAL') ? '#f8d7da' : '#fff3cd';
        var badgeClr  = (f.status === 'OUT OF STOCK' || f.status === 'CRITICAL') ? '#721c24' : '#856404';
        var fillPct   = Math.min(100, f.fill_pct);
        var rowId     = 'fsr_row_' + i;
        var cbId      = 'fsr_cb_' + i;
        var qtyId     = 'fsr_qty_' + i;
        var remId     = 'fsr_rem_' + i;
        var fdata     = encodeURIComponent(JSON.stringify(f));

        html +=
            '<tr id="' + rowId + '" class="fsr-row" data-fuel="' + fdata + '" style="border-bottom:1px solid #f0f0f0;transition:background .12s;">' +

            // Checkbox
            '<td style="text-align:center;padding:10px 8px;vertical-align:middle;">' +
                '<input type="checkbox" id="' + cbId + '" class="fsr-cb" data-row="' + i + '"' +
                '       style="width:16px;height:16px;accent-color:#c0392b;cursor:pointer;">' +
            '</td>' +

            // Fuel name + bar
            '<td style="padding:10px 10px;vertical-align:middle;">' +
                '<label for="' + cbId + '" style="cursor:pointer;">' +
                    '<strong style="font-size:13px;color:#002F70;">' + escHtml(f.fuel_type) + '</strong>' +
                '</label>' +
                '<div style="background:#e9ecef;border-radius:3px;height:5px;overflow:hidden;margin-top:5px;">' +
                    '<div style="width:' + fillPct + '%;height:100%;background:' + barColor + ';border-radius:3px;"></div>' +
                '</div>' +
            '</td>' +

            // Level / Capacity
            '<td style="padding:10px 8px;text-align:center;vertical-align:middle;font-size:12px;color:#555;white-space:nowrap;">' +
                number_format(f.current_level) + ' L<br>' +
                '<span style="color:#aaa;font-size:10px;">/ ' + number_format(f.capacity) + ' L (' + f.fill_pct + '%)</span>' +
            '</td>' +

            // Status badge
            '<td style="padding:10px 8px;text-align:center;vertical-align:middle;">' +
                '<span style="background:' + badgeBg + ';color:' + badgeClr + ';padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap;">' +
                    escHtml(f.status) +
                '</span>' +
            '</td>' +

            '</tr>';
    });

    tbody.innerHTML = html;

    // Checkbox toggle — highlight row and sync select-all state
    document.querySelectorAll('.fsr-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var i     = this.dataset.row;
            var rowEl = document.getElementById('fsr_row_' + i);

            rowEl.style.background = this.checked ? '#fff8f8' : '';

            syncSelectAll();
            updateSelCount();
        });
    });
}

// Sync the Select All checkbox state based on individual checkboxes
function syncSelectAll() {
    var allCbs     = document.querySelectorAll('.fsr-cb');
    var checkedCbs = document.querySelectorAll('.fsr-cb:checked');
    var selectAll  = document.getElementById('fsrSelectAll');

    if (allCbs.length === 0) {
        selectAll.checked       = false;
        selectAll.indeterminate = false;
    } else if (checkedCbs.length === allCbs.length) {
        selectAll.checked       = true;
        selectAll.indeterminate = false;
    } else if (checkedCbs.length === 0) {
        selectAll.checked       = false;
        selectAll.indeterminate = false;
    } else {
        selectAll.checked       = false;
        selectAll.indeterminate = true;
    }
}

function updateSelCount() {
    var n   = document.querySelectorAll('.fsr-cb:checked').length;
    var el  = document.getElementById('fsrSelCount');
    var hint = document.getElementById('fsrSubmitHint');
    if (n > 0) {
        el.textContent = n + ' selected';
        el.style.display = 'inline';
        if (hint) hint.textContent = n + ' fuel type' + (n > 1 ? 's' : '') + ' will be submitted';
    } else {
        el.style.display = 'none';
        if (hint) hint.textContent = '';
    }
}

// Select All — attached once at page load, not inside buildFuelRows
document.getElementById('fsrSelectAll').addEventListener('change', function() {
    var shouldCheck = this.checked;
    document.querySelectorAll('.fsr-cb').forEach(function(cb) {
        cb.checked = shouldCheck;
        var rowEl = document.getElementById('fsr_row_' + cb.dataset.row);
        if (rowEl) rowEl.style.background = shouldCheck ? '#fff8f8' : '';
    });
    updateSelCount();
});

function closeFuelModal() {
    document.getElementById('fuelStockRequestModal').classList.remove('show');
    document.body.style.overflow = '';
}

function showFsrError(msg) {
    var el = document.getElementById('fsrError');
    document.getElementById('fsrErrorMsg').textContent = msg;
    el.style.display = 'block';
}

// Form submit — batch
document.getElementById('fuelStockRequestForm').addEventListener('submit', function(e) {
    e.preventDefault();
    document.getElementById('fsrError').style.display = 'none';

    // Collect checked rows
    var checked = document.querySelectorAll('.fsr-cb:checked');
    if (checked.length === 0) {
        showFsrError('Please select at least one fuel type to request.');
        return;
    }

    var items = [];
    var hasError = false;

    checked.forEach(function(cb) {
        var i      = cb.dataset.row;
        var f      = JSON.parse(decodeURIComponent(document.getElementById('fsr_row_' + i).dataset.fuel));

        items.push({
            fuel_type:        f.fuel_type,
            current_level:    f.current_level,
            capacity:         f.capacity,
            stock_status:     f.status,
            requested_liters: 0,
            remarks:          ''
        });
    });

    if (items.length === 0) return;

    var btn = document.getElementById('fsrSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    // Submit all as batch (sequential promises)
    var results  = [];
    var errors   = [];
    var chain    = Promise.resolve();

    items.forEach(function(item) {
        chain = chain.then(function() {
            return fetch('../backend/api/fuel_stock_request.php?action=create', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(item)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    results.push(item.fuel_type);
                } else {
                    errors.push(item.fuel_type + ': ' + (res.message || 'Failed'));
                }
            });
        });
    });

    chain.then(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Requests';

        if (results.length > 0) {
            closeFuelModal();

            var msgHtml = '<ul style="margin:6px 0 0;padding-left:18px;text-align:left;">';
            results.forEach(function(r) { msgHtml += '<li style="margin-bottom:3px;">' + escHtml(r) + '</li>'; });
            msgHtml += '</ul>';
            if (errors.length > 0) {
                msgHtml += '<div style="margin-top:10px;color:#dc3545;font-size:12px;"><strong>Could not submit:</strong><ul style="margin:4px 0 0;padding-left:18px;">';
                errors.forEach(function(r) { msgHtml += '<li>' + escHtml(r) + '</li>'; });
                msgHtml += '</ul></div>';
            }

            document.getElementById('fsrSuccessMsg').innerHTML =
                '<strong>' + results.length + ' request' + (results.length > 1 ? 's' : '') + '</strong> submitted — now <strong>Pending</strong> Manager validation:' + msgHtml;
            document.getElementById('fsrSuccessPopup').style.display  = 'block';
            document.getElementById('fsrSuccessOverlay').style.display = 'block';
            setTimeout(closeFuelSuccess, 8000);
        } else {
            // All failed
            showFsrError(errors.join(' | '));
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Requests';
        showFsrError('Network error. Please try again.');
    });
});

function closeFuelSuccess() {
    document.getElementById('fsrSuccessPopup').style.display  = 'none';
    document.getElementById('fsrSuccessOverlay').style.display = 'none';
    location.reload();
}// Utility helpers
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function number_format(n) {
    return parseFloat(n || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
