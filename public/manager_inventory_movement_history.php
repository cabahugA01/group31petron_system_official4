<?php
$page_id = 'mgr_inv_movement';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

$active_tab = $_GET['tab'] ?? 'merch';
if (!in_array($active_tab, ['merch', 'fuel'])) {
    $active_tab = 'merch';
}

// Initialize arrays/stats
$categories_list = [];
$users_list = [];
$fuel_types_list = [];

// ─────────────────────────────────────────────────────────────────────────────
// DATA FETCHING BASED ON ACTIVE TAB
// ─────────────────────────────────────────────────────────────────────────────

if ($active_tab === 'merch') {
    // 1. Calculate Merchandise Summary Cards
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_count,
            SUM(CASE WHEN il.action IN ('stock_in', 'delivery', 'receive', 'po_receipt') THEN 1 ELSE 0 END) AS delivery_count,
            SUM(CASE WHEN il.action IN ('sale', 'release', 'sold', 'manual_release', 'stock_out') THEN 1 ELSE 0 END) AS release_count,
            SUM(CASE WHEN il.action NOT IN ('stock_in', 'delivery', 'receive', 'po_receipt', 'sale', 'release', 'sold', 'manual_release', 'stock_out') THEN 1 ELSE 0 END) AS adjustment_count
        FROM inventory_logs il
        JOIN inventory_products ip ON il.product_id = ip.id
        WHERE il.station_id = ? AND LOWER(COALESCE(ip.category, '')) != 'fuel'
    ");
    $stmt->execute([$station_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $summary_total_movements = $stats['total_count'] ?? 0;
    $summary_deliveries      = $stats['delivery_count'] ?? 0;
    $summary_releases        = $stats['release_count'] ?? 0;
    $summary_adjustments     = $stats['adjustment_count'] ?? 0;

    // 2. Fetch Merchandise Movements
    $movements_list = [];
    try {
        $stmt = $pdo->prepare("
            SELECT 
                il.id AS movement_id,
                il.created_at,
                ip.product_name,
                ip.sku AS prod_sku,
                ip.category AS product_category,
                il.action AS raw_action,
                il.quantity_change,
                il.quantity_before,
                il.quantity_after,
                u.name AS performed_by,
                il.notes,
                COALESCE(si.unit, ip.unit, 'pcs') AS unit
            FROM inventory_logs il
            JOIN inventory_products ip ON il.product_id = ip.id
            LEFT JOIN station_inventory si ON il.product_id = si.product_id AND si.station_id = il.station_id
            LEFT JOIN users u ON il.user_id = u.id
            WHERE il.station_id = ? AND LOWER(COALESCE(ip.category, '')) != 'fuel'
            ORDER BY il.created_at DESC
        ");
        $stmt->execute([$station_id]);
        $movements_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($movements_list as $m) {
            $cat = $m['product_category'] ?? '';
            if ($cat !== '') {
                $categories_list[$cat] = true;
            }
            $perf = $m['performed_by'] ?? '';
            if ($perf !== '') {
                $users_list[$perf] = true;
            }
        }
        ksort($categories_list);
        ksort($users_list);
    } catch (Exception $e) {
        // Fail silently
    }
} else {
    // ACTIVE TAB IS FUEL
    $fuel_movements = [];
    $mov_deliveries = 0;
    $mov_sales = 0;
    $mov_adjustments = 0;

    // 1. Fetch Fuel Deliveries
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('DEL-', fd.id)         AS movement_id,
                fd.delivery_date              AS movement_date,
                fd.fuel_type,
                COALESCE(fd.tank_assigned,'—') AS tank,
                'Delivery'                    AS movement_type,
                fd.delivery_liters            AS liters,
                NULL                          AS previous_volume,
                NULL                          AS new_volume,
                COALESCE(u.name,'—')          AS performed_by,
                fd.invoice_no                 AS ref_no,
                fd.status,
                fd.notes
            FROM fuel_deliveries fd
            LEFT JOIN users u ON fd.received_by = u.id
            WHERE fd.station_id = ?
            ORDER BY fd.delivery_date DESC, fd.id DESC
            LIMIT 500
        ");
        $s->execute([$station_id]);
        $del_rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $mov_deliveries = count($del_rows);
        $fuel_movements = array_merge($fuel_movements, $del_rows);
    } catch (Exception $e) {}

    // 2. Fetch Fuel Sales
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('SAL-', ft.id)         AS movement_id,
                DATE(ft.transaction_date)     AS movement_date,
                ft.fuel_type,
                COALESCE(CONCAT('Pump #',ft.pump_id),'—') AS tank,
                'Sale'                        AS movement_type,
                ft.liters_sold                AS liters,
                NULL                          AS previous_volume,
                NULL                          AS new_volume,
                COALESCE(u.name,'—')          AS performed_by,
                ft.transaction_id             AS ref_no,
                ft.status,
                ft.notes
            FROM fuel_transactions ft
            LEFT JOIN users u ON ft.staff_id = u.id
            WHERE ft.station_id = ?
            ORDER BY ft.transaction_date DESC, ft.id DESC
            LIMIT 500
        ");
        $s->execute([$station_id]);
        $sale_rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $mov_sales = count($sale_rows);
        $fuel_movements = array_merge($fuel_movements, $sale_rows);
    } catch (Exception $e) {}

    // 3. Fetch Fuel Adjustments
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('ADJ-', fa.id)         AS movement_id,
                fa.adjustment_date            AS movement_date,
                fa.fuel_type,
                '—'                           AS tank,
                CONCAT('Adjustment (',fa.adjustment_type,')') AS movement_type,
                fa.liters,
                fa.previous_value             AS previous_volume,
                fa.new_value                  AS new_volume,
                COALESCE(u.name,'—')          AS performed_by,
                fa.reason                     AS ref_no,
                fa.status,
                fa.notes
            FROM fuel_adjustments fa
            LEFT JOIN users u ON fa.user_id = u.id
            WHERE fa.station_id = ?
            ORDER BY fa.adjustment_date DESC, fa.id DESC
            LIMIT 500
        ");
        $s->execute([$station_id]);
        $adj_rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $mov_adjustments = count($adj_rows);
        $fuel_movements = array_merge($fuel_movements, $adj_rows);
    } catch (Exception $e) {}

    // Sort combined rows by date desc
    usort($fuel_movements, function($a, $b) {
        return strcmp($b['movement_date'], $a['movement_date']);
    });

    $summary_total_movements = count($fuel_movements);
    $summary_deliveries      = $mov_deliveries;
    $summary_releases        = $mov_sales;
    $summary_adjustments     = $mov_adjustments;

    // Gather filter parameters
    foreach ($fuel_movements as $fm) {
        $ft = $fm['fuel_type'] ?? '';
        if ($ft !== '') {
            $fuel_types_list[$ft] = true;
        }
        $perf = $fm['performed_by'] ?? '';
        if ($perf !== '') {
            $users_list[$perf] = true;
        }
    }
    ksort($fuel_types_list);
    ksort($users_list);
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* == PAGE HEADER - matches standard Petron dashboard layout == */
.int-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    margin-top: 0px !important;
}
.int-head h1 {
    font-size: 20px;
    font-weight: 800;
    color: #002F6C;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.5px;
}
.int-head .sub {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}

/* Tab button overrides */
.tab-nav {
    display: flex;
    gap: 4px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 20px;
}
.tab-btn {
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}
.tab-btn:hover {
    color: #002F70;
}
.tab-btn.active {
    color: #002F70;
    border-bottom-color: #002F70;
}

/* Custom Table & Badge styling */
.table-wrap {
    overflow-x: auto;
    background: #fff;
    border-radius: 8px;
}
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-align: left;
    padding: 12px 16px;
    border-bottom: 1px solid #e2e8f0;
}
.table td {
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
.table tr:hover {
    background: #f8fafc;
}

/* Custom Type Badges */
.badge-delivery {
    background: #e6f4ea;
    color: #137333;
    border: 1px solid #c2e7c9;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
}
.badge-release {
    background: #e8f0fe;
    color: #1a73e8;
    border: 1px solid #d2e3fc;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
}
.badge-adjustment {
    background: #fef7e0;
    color: #b06000;
    border: 1px solid #fde293;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
}

.int-btn-outline {
    background: #fff;
    border: 1px solid #cbd5e1;
    color: #475569;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
}
.int-btn-outline:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #1e293b;
}

/* == Shared export/action buttons (flt-btn style) == */
.flt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .15s;
    background: white !important;
    border: 1px solid transparent;
}
.flt-btn-search { color: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-search:hover { background: #002F70 !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.flt-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
.flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }

/* == Transaction Action Buttons (txn-btn style) == */
.txn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    line-height: 1;
    width: 100%;
    transition: all .18s;
    background: white !important;
    border: 1px solid transparent;
    text-decoration: none;
    box-sizing: border-box;
}
.txn-btn-approve { color: #16a34a !important; border-color: #16a34a !important; }
.txn-btn-approve:hover { background: #16a34a !important; color: #fff !important; }
.txn-btn-reject { color: #dc2626 !important; border-color: #dc2626 !important; }
.txn-btn-reject:hover { background: #dc2626 !important; color: #fff !important; }
.txn-btn-adjust { color: #00264D !important; border-color: #00264D !important; }
.txn-btn-adjust:hover { background: #00264D !important; color: #fff !important; }
.txn-btn-info { color: #0284c7 !important; border-color: #0284c7 !important; }
.txn-btn-info:hover { background: #0284c7 !important; color: #fff !important; }
.txn-btn-secondary { color: #6b7280 !important; border-color: #6b7280 !important; }
.txn-btn-secondary:hover { background: #6b7280 !important; color: #fff !important; }

/* Modals */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}
.modal-overlay.open {
    opacity: 1;
    pointer-events: auto;
}
.modal-box {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
    max-width: 95%;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    color: #002F6C;
}
.modal-body {
    padding: 20px;
}
.modal-footer {
    padding: 12px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.btn-cancel {
    background: #fff;
    border: 1px solid #cbd5e1;
    color: #475569;
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    font-size: 13px;
}
.btn-cancel:hover {
    background: #f8fafc;
}
</style>

<!-- ══ Page Title / Header ══ -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-history"></i> Inventory Movement History</h1>
        <div class="sub">Detailed audit trail and logs of all merchandise stock and fuel movements.</div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <a href="manager_dashboard.php" class="ato-btn ato-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- ══ Tabs Nav ══ -->
<div class="tab-nav">
    <a href="manager_inventory_movement_history.php?tab=merch" class="tab-btn <?= $active_tab === 'merch' ? 'active' : '' ?>"><i class="fas fa-box"></i> Merchandise Movements</a>
    <a href="manager_inventory_movement_history.php?tab=fuel" class="tab-btn <?= $active_tab === 'fuel' ? 'active' : '' ?>"><i class="fas fa-gas-pump"></i> Fuel Movements</a>
</div>

<?php if ($active_tab === 'merch'): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     MERCHANDISE MOVEMENTS TAB
     ═══════════════════════════════════════════════════════════════════════════ -->
<!-- ══ Summary Cards ══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
    <!-- Total Merchandise Movements -->
    <div style="background:#fff;border-left:5px solid #002F6C;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Total Merchandise Movements</div>
            <div style="font-size:24px;font-weight:800;color:#002F6C;margin-top:4px;"><?= number_format($summary_total_movements) ?></div>
        </div>
        <div style="background:#e8f4fd;color:#002F6C;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-history"></i></div>
    </div>
    <!-- Merchandise Deliveries -->
    <div style="background:#fff;border-left:5px solid #28a745;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Merchandise Deliveries</div>
            <div style="font-size:24px;font-weight:800;color:#28a745;margin-top:4px;"><?= number_format($summary_deliveries) ?></div>
        </div>
        <div style="background:#e6f4ea;color:#28a745;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-dolly-flatbed"></i></div>
    </div>
    <!-- Merchandise Releases/Sales -->
    <div style="background:#fff;border-left:5px solid #1a73e8;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Merchandise Releases/Sales</div>
            <div style="font-size:24px;font-weight:800;color:#1a73e8;margin-top:4px;"><?= number_format($summary_releases) ?></div>
        </div>
        <div style="background:#e8f0fe;color:#1a73e8;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-shopping-cart"></i></div>
    </div>
    <!-- Merchandise Adjustments -->
    <div style="background:#fff;border-left:5px solid #fd7e14;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Merchandise Adjustments</div>
            <div style="font-size:24px;font-weight:800;color:#fd7e14;margin-top:4px;"><?= number_format($summary_adjustments) ?></div>
        </div>
        <div style="background:#fff3cd;color:#fd7e14;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-balance-scale"></i></div>
    </div>
</div>

<!-- ══ Catalog / Movements List ══ -->
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    
    <!-- Filter bar -->
    <div style="padding:20px; border-bottom:1px solid #e9ecef; display:flex; flex-direction:column; gap:16px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-clipboard-list"></i> Merchandise Movement Records
        </div>
        
        <!-- Grid layout for filters -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; align-items:end;">
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Date Range</label>
                <div style="display:flex; align-items:center; gap:6px;">
                    <input type="date" id="movDateFrom" onchange="filterMovTable()" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <span style="color:#64748b; font-size:12px;">to</span>
                    <input type="date" id="movDateTo" onchange="filterMovTable()" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                </div>
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Performed By</label>
                <select id="movPerformedFilter" onchange="filterMovTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <option value="">All Users</option>
                    <?php foreach (array_keys($users_list) as $perfBy): ?>
                        <option value="<?= htmlspecialchars(strtolower($perfBy)) ?>"><?= htmlspecialchars($perfBy) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Product Category</label>
                <select id="movCategoryFilter" onchange="filterMovTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <option value="">All Categories</option>
                    <?php foreach (array_keys($categories_list) as $c): ?>
                        <option value="<?= htmlspecialchars(strtolower($c)) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Movement Type</label>
                <select id="movTypeFilter" onchange="filterMovTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <option value="">All Types</option>
                    <option value="delivery">📥 Delivery</option>
                    <option value="release">📤 Release/Sale</option>
                    <option value="adjustment">⚖️ Adjustment</option>
                </select>
            </div>

            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Search</label>
                <input type="text" id="movSearch" placeholder="Search ID / Product Name..." oninput="filterMovTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%; box-sizing:border-box;">
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
            <button onclick="exportMovTablePDF()" class="flt-btn flt-btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
            <button onclick="exportMovTableExcel()" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
            <button onclick="exportMovTableCSV()" class="flt-btn flt-btn-search"><i class="fas fa-file-csv"></i> CSV</button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table" id="mgrMovTable">
            <thead>
                <tr>
                    <th style="width:90px;">Movement ID</th>
                    <th>Date</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th style="text-align:center;">Movement Type</th>
                    <th style="text-align:right;">Quantity</th>
                    <th style="text-align:right;">Previous Stock</th>
                    <th style="text-align:right;">New Stock</th>
                    <th>Performed By</th>
                    <th style="text-align:center;">Status</th>
                </tr>
            </thead>
            <tbody id="movTableBody">
            <?php if (empty($movements_list)): ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:24px;color:#64748b;">
                        <i class="fas fa-info-circle" style="color:#002F6C;font-size:24px;margin-bottom:8px;display:block;"></i>
                        No inventory movements found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($movements_list as $m):
                    $date_str = $m['created_at'] ? date('M d, Y h:i A', strtotime($m['created_at'])) : '—';
                    $raw_act = strtolower($m['raw_action']);
                    
                    // Categorize action
                    if (in_array($raw_act, ['stock_in', 'delivery', 'receive', 'po_receipt'])) {
                        $mov_type = 'delivery';
                        $mov_label = '📥 Delivery';
                        $badge_class = 'badge-delivery';
                        $qty_prefix = '+';
                        $qty_style = 'color:#137333; font-weight:700;';
                    } elseif (in_array($raw_act, ['sale', 'release', 'sold', 'manual_release', 'stock_out'])) {
                        $mov_type = 'release';
                        $mov_label = '📤 Release/Sale';
                        $badge_class = 'badge-release';
                        $qty_prefix = '-';
                        $qty_style = 'color:#c5221f; font-weight:700;';
                    } else {
                        $mov_type = 'adjustment';
                        $mov_label = '⚖️ Adjustment';
                        $badge_class = 'badge-adjustment';
                        
                        $qty_val = (float)$m['quantity_change'];
                        if ($qty_val > 0) {
                            $qty_prefix = '+';
                            $qty_style = 'color:#137333; font-weight:700;';
                        } elseif ($qty_val < 0) {
                            $qty_prefix = '';
                            $qty_style = 'color:#c5221f; font-weight:700;';
                        } else {
                            $qty_prefix = '';
                            $qty_style = 'color:#475569; font-weight:700;';
                        }
                    }
                ?>
                    <tr class="mov-row"
                        data-category="<?= strtolower(htmlspecialchars($m['product_category'] ?? '')) ?>"
                        data-type="<?= $mov_type ?>"
                        data-performed-by="<?= strtolower(htmlspecialchars($m['performed_by'] ?? '')) ?>"
                        data-date="<?= date('Y-m-d', strtotime($m['created_at'])) ?>"
                        data-search="<?= strtolower(htmlspecialchars($m['movement_id'] . ' ' . $m['product_name'] . ' ' . ($m['prod_sku'] ?? '') . ' ' . ($m['performed_by'] ?? '') . ' ' . ($m['notes'] ?? ''))) ?>">
                        <td><code style="font-weight:700;">#<?= $m['movement_id'] ?></code></td>
                        <td style="font-size:11px;color:#64748b;"><?= $date_str ?></td>
                        <td>
                            <strong><?= htmlspecialchars($m['product_name']) ?></strong><br>
                            <small style="color:#64748b;">SKU: <?= htmlspecialchars($m['prod_sku'] ?? '—') ?></small>
                        </td>
                        <td><?= htmlspecialchars($m['product_category'] ?? '—') ?></td>
                        <td style="text-align:center;">
                            <span class="<?= $badge_class ?>"><?= $mov_label ?></span>
                        </td>
                        <td style="text-align:right;<?= $qty_style ?>"><?= $qty_prefix . number_format($m['quantity_change']) ?> <span style="font-size:10px;color:#64748b;"><?= htmlspecialchars($m['unit'] ?? 'pcs') ?></span></td>
                        <td style="text-align:right;color:#64748b;"><?= number_format($m['quantity_before']) ?></td>
                        <td style="text-align:right;font-weight:600;"><?= number_format($m['quantity_after']) ?></td>
                        <td><?= htmlspecialchars($m['performed_by'] ?? '—') ?></td>
                        <td style="text-align:center;">
                            <span class="badge-approved">Completed</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrMovPagination" style="padding:10px 20px;"></div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     FUEL MOVEMENTS TAB
     ═══════════════════════════════════════════════════════════════════════════ -->
<!-- ══ Summary Cards ══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
    <!-- Total Fuel Movements -->
    <div style="background:#fff;border-left:5px solid #002F6C;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">⛽ Total Fuel Movements</div>
            <div style="font-size:24px;font-weight:800;color:#002F6C;margin-top:4px;"><?= number_format($summary_total_movements) ?></div>
        </div>
        <div style="background:#e8f4fd;color:#002F6C;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-gas-pump"></i></div>
    </div>
    <!-- Fuel Deliveries -->
    <div style="background:#fff;border-left:5px solid #28a745;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">🛢 Fuel Deliveries</div>
            <div style="font-size:24px;font-weight:800;color:#28a745;margin-top:4px;"><?= number_format($summary_deliveries) ?></div>
        </div>
        <div style="background:#e6f4ea;color:#28a745;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-truck-loading"></i></div>
    </div>
    <!-- Fuel Sales -->
    <div style="background:#fff;border-left:5px solid #1a73e8;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">🚗 Fuel Sales</div>
            <div style="font-size:24px;font-weight:800;color:#1a73e8;margin-top:4px;"><?= number_format($summary_releases) ?></div>
        </div>
        <div style="background:#e8f0fe;color:#1a73e8;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-filter"></i></div>
    </div>
    <!-- Fuel Adjustments -->
    <div style="background:#fff;border-left:5px solid #fd7e14;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;border-left-width:5px;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">⚖️ Fuel Adjustments</div>
            <div style="font-size:24px;font-weight:800;color:#fd7e14;margin-top:4px;"><?= number_format($summary_adjustments) ?></div>
        </div>
        <div style="background:#fff3cd;color:#fd7e14;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-adjust"></i></div>
    </div>
</div>

<!-- ══ Catalog / Fuel Movements List ══ -->
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    
    <!-- Filter bar -->
    <div style="padding:20px; border-bottom:1px solid #e9ecef; display:flex; flex-direction:column; gap:16px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-clipboard-list"></i> Fuel Movement Records
        </div>
        
        <!-- Grid layout for filters -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; align-items:end;">
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Date Range</label>
                <div style="display:flex; align-items:center; gap:6px;">
                    <input type="date" id="fuelMovDateFrom" onchange="filterFuelTable()" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <span style="color:#64748b; font-size:12px;">to</span>
                    <input type="date" id="fuelMovDateTo" onchange="filterFuelTable()" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                </div>
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Performed By</label>
                <select id="fuelMovPerformedFilter" onchange="filterFuelTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <option value="">All Users</option>
                    <?php foreach (array_keys($users_list) as $perfBy): ?>
                        <option value="<?= htmlspecialchars(strtolower($perfBy)) ?>"><?= htmlspecialchars($perfBy) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Fuel Type</label>
                <select id="fuelMovTypeValFilter" onchange="filterFuelTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <option value="">All Fuel Types</option>
                    <?php foreach (array_keys($fuel_types_list) as $ft): ?>
                        <option value="<?= htmlspecialchars(strtolower($ft)) ?>"><?= htmlspecialchars($ft) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Movement Type</label>
                <select id="fuelMovTypeFilter" onchange="filterFuelTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%;">
                    <option value="">All Types</option>
                    <option value="delivery">📥 Delivery</option>
                    <option value="sale">🚗 Sale</option>
                    <option value="adjustment">⚖️ Adjustment</option>
                </select>
            </div>

            <div>
                <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:5px;">Search</label>
                <input type="text" id="fuelMovSearch" placeholder="Search ID / Tank / Fuel..." oninput="filterFuelTable()" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:100%; box-sizing:border-box;">
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
            <button onclick="exportFuelTablePDF()" class="flt-btn flt-btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
            <button onclick="exportFuelTableExcel()" class="flt-btn flt-btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
            <button onclick="exportFuelTableCSV()" class="flt-btn flt-btn-search"><i class="fas fa-file-csv"></i> CSV</button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table" id="mgrFuelMovTable">
            <thead>
                <tr>
                    <th style="width:100px;">Movement ID</th>
                    <th>Date</th>
                    <th>Fuel Type</th>
                    <th>Tank Reference</th>
                    <th style="text-align:center;">Movement Type</th>
                    <th style="text-align:right;">Liters</th>
                    <th style="text-align:right;">Previous Volume</th>
                    <th style="text-align:right;">New Volume</th>
                    <th>Performed By</th>
                    <th style="text-align:center;">Status</th>
                </tr>
            </thead>
            <tbody id="fuelMovTableBody">
            <?php if (empty($fuel_movements)): ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:24px;color:#64748b;">
                        <i class="fas fa-info-circle" style="color:#002F6C;font-size:24px;margin-bottom:8px;display:block;"></i>
                        No fuel movements found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($fuel_movements as $fm):
                    $date_str = $fm['movement_date'] ? date('M d, Y', strtotime($fm['movement_date'])) : '—';
                    $raw_type = strtolower($fm['movement_type']);
                    
                    if (strpos($raw_type, 'delivery') !== false) {
                        $mov_type = 'delivery';
                        $mov_label = '📥 Fuel Delivery';
                        $badge_class = 'badge-delivery';
                        $qty_prefix = '+';
                        $qty_style = 'color:#137333; font-weight:700;';
                    } elseif (strpos($raw_type, 'sale') !== false) {
                        $mov_type = 'sale';
                        $mov_label = '🚗 Fuel Sale';
                        $badge_class = 'badge-release';
                        $qty_prefix = '-';
                        $qty_style = 'color:#c5221f; font-weight:700;';
                    } else {
                        $mov_type = 'adjustment';
                        $mov_label = '⚖️ ' . $fm['movement_type'];
                        $badge_class = 'badge-adjustment';
                        
                        $liters_val = (float)$fm['liters'];
                        // Adjustments can be positive or negative
                        if (strpos(strtolower($fm['movement_type']), 'loss') !== false || $liters_val < 0) {
                            $qty_prefix = '';
                            $qty_style = 'color:#c5221f; font-weight:700;';
                        } else {
                            $qty_prefix = '+';
                            $qty_style = 'color:#137333; font-weight:700;';
                        }
                    }
                ?>
                    <tr class="fuel-row"
                        data-fuel-type="<?= strtolower(htmlspecialchars($fm['fuel_type'] ?? '')) ?>"
                        data-type="<?= $mov_type ?>"
                        data-performed-by="<?= strtolower(htmlspecialchars($fm['performed_by'] ?? '')) ?>"
                        data-date="<?= date('Y-m-d', strtotime($fm['movement_date'])) ?>"
                        data-search="<?= strtolower(htmlspecialchars($fm['movement_id'] . ' ' . $fm['fuel_type'] . ' ' . ($fm['tank'] ?? '') . ' ' . ($fm['performed_by'] ?? '') . ' ' . ($fm['notes'] ?? ''))) ?>">
                        <td><code style="font-weight:700;">#<?= $fm['movement_id'] ?></code></td>
                        <td style="font-size:11px;color:#64748b;"><?= $date_str ?></td>
                        <td><strong><?= htmlspecialchars($fm['fuel_type']) ?></strong></td>
                        <td><?= htmlspecialchars($fm['tank'] ?? '—') ?></td>
                        <td style="text-align:center;">
                            <span class="<?= $badge_class ?>"><?= $mov_label ?></span>
                        </td>
                        <td style="text-align:right;<?= $qty_style ?>"><?= $qty_prefix . number_format($fm['liters'], 2) ?> <span style="font-size:10px;color:#64748b;">L</span></td>
                        <td style="text-align:right;color:#64748b;"><?= $fm['previous_volume'] !== null ? number_format($fm['previous_volume'], 2) . ' L' : '—' ?></td>
                        <td style="text-align:right;font-weight:600;"><?= $fm['new_volume'] !== null ? number_format($fm['new_volume'], 2) . ' L' : '—' ?></td>
                        <td><?= htmlspecialchars($fm['performed_by'] ?? '—') ?></td>
                        <td style="text-align:center;">
                            <span class="badge-approved"><?= htmlspecialchars($fm['status'] ?: 'Completed') ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrFuelMovPagination" style="padding:10px 20px;"></div>
</div>
<?php endif; ?>

<!-- ══ Modal: Details Modal ══ -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box" style="width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-history"></i> Movement Details</h3>
            <button onclick="closeDetailsModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Dynamic columns based on which detail is populated -->
            <table style="width:100%; border-collapse:collapse; font-size:13px;" id="detailTable">
                <!-- Populated via JS -->
            </table>
        </div>
        <div class="modal-footer">
            <button onclick="closeDetailsModal()" class="btn-cancel">Close</button>
        </div>
    </div>
</div>

<script>
function esc(str) {
    if (!str) return '';
    return str.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// ── Search & Filter Merchandise ──
function filterMovTable() {
    var search = (document.getElementById('movSearch').value || '').toLowerCase();
    var category = (document.getElementById('movCategoryFilter').value || '').toLowerCase();
    var type = (document.getElementById('movTypeFilter').value || '').toLowerCase();
    var performedBy = (document.getElementById('movPerformedFilter').value || '').toLowerCase();
    
    var dateFromVal = document.getElementById('movDateFrom').value;
    var dateToVal = document.getElementById('movDateTo').value;
    
    var dateFrom = dateFromVal ? new Date(dateFromVal + 'T00:00:00') : null;
    var dateTo = dateToVal ? new Date(dateToVal + 'T23:59:59') : null;

    document.querySelectorAll('#movTableBody tr.mov-row').forEach(function(row) {
        var rowSearch = (row.dataset.search || '');
        var rowCat = (row.dataset.category || '');
        var rowType = (row.dataset.type || '');
        var rowPerf = (row.dataset.performedBy || '');
        
        var rowDateVal = row.dataset.date;
        var rowDate = rowDateVal ? new Date(rowDateVal + 'T00:00:00') : null;

        var match = true;
        if (search && rowSearch.indexOf(search) === -1) match = false;
        if (category && rowCat !== category) match = false;
        if (type && rowType !== type) match = false;
        if (performedBy && rowPerf !== performedBy) match = false;
        
        if (dateFrom && rowDate && rowDate < dateFrom) match = false;
        if (dateTo && rowDate && rowDate > dateTo) match = false;

        row.style.display = match ? '' : 'none';
    });
}

// ── Search & Filter Fuel ──
function filterFuelTable() {
    var search = (document.getElementById('fuelMovSearch').value || '').toLowerCase();
    var fuelType = (document.getElementById('fuelMovTypeValFilter').value || '').toLowerCase();
    var type = (document.getElementById('fuelMovTypeFilter').value || '').toLowerCase();
    var performedBy = (document.getElementById('fuelMovPerformedFilter').value || '').toLowerCase();
    
    var dateFromVal = document.getElementById('fuelMovDateFrom').value;
    var dateToVal = document.getElementById('fuelMovDateTo').value;
    
    var dateFrom = dateFromVal ? new Date(dateFromVal + 'T00:00:00') : null;
    var dateTo = dateToVal ? new Date(dateToVal + 'T23:59:59') : null;

    document.querySelectorAll('#fuelMovTableBody tr.fuel-row').forEach(function(row) {
        var rowSearch = (row.dataset.search || '');
        var rowFuel = (row.dataset.fuelType || '');
        var rowType = (row.dataset.type || '');
        var rowPerf = (row.dataset.performedBy || '');
        
        var rowDateVal = row.dataset.date;
        var rowDate = rowDateVal ? new Date(rowDateVal + 'T00:00:00') : null;

        var match = true;
        if (search && rowSearch.indexOf(search) === -1) match = false;
        if (fuelType && rowFuel !== fuelType) match = false;
        if (type && rowType !== type) match = false;
        if (performedBy && rowPerf !== performedBy) match = false;
        
        if (dateFrom && rowDate && rowDate < dateFrom) match = false;
        if (dateTo && rowDate && rowDate > dateTo) match = false;

        row.style.display = match ? '' : 'none';
    });
}

// ── View Merchandise Details Modal ──
function viewMovDetails(m, typeLabel) {
    var qty = Number(m.quantity_change);
    var sign = qty > 0 && typeLabel.indexOf('Delivery') !== -1 ? '+' : '';
    if (typeLabel.indexOf('Release') !== -1) sign = '-';

    var html = `
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b; width:150px;">Movement ID:</td><td style="font-weight:700;color:#002F70;">#${m.movement_id}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Product Name:</td><td style="font-weight:700;">${esc(m.product_name)}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">SKU:</td><td>${esc(m.prod_sku || '—')}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Category:</td><td>${esc(m.product_category || '—')}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Movement Type:</td><td style="font-weight:700;">${typeLabel}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Quantity Changed:</td><td style="font-weight:700;color:#002F70;">${sign}${qty.toLocaleString()} ${esc(m.unit || 'pcs')}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Previous Stock:</td><td>${Number(m.quantity_before).toLocaleString()} ${esc(m.unit || 'pcs')}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">New Stock:</td><td style="font-weight:700;">${Number(m.quantity_after).toLocaleString()} ${esc(m.unit || 'pcs')}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Performed By:</td><td>${esc(m.performed_by || '—')}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Date/Time:</td><td>${m.created_at ? new Date(m.created_at).toLocaleString() : '—'}</td></tr>
        <tr><td style="padding:8px 0; font-weight:600; color:#64748b;">Notes / Details:</td><td style="font-style:italic;color:#1e293b;">${esc(m.notes || '—')}</td></tr>
    `;
    
    document.getElementById('detailTable').innerHTML = html;
    document.getElementById('detailsModal').classList.add('open');
}

// ── View Fuel Details Modal ──
function viewFuelMovDetails(fm, typeLabel) {
    var liters = Number(fm.liters);
    var sign = '';
    if (typeLabel.indexOf('Delivery') !== -1) {
        sign = '+';
    } else if (typeLabel.indexOf('Sale') !== -1) {
        sign = '-';
    } else {
        sign = (liters >= 0) ? '+' : '';
    }

    var html = `
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b; width:150px;">Movement ID:</td><td style="font-weight:700;color:#002F70;">#${fm.movement_id}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Fuel Type:</td><td style="font-weight:700;color:#002F6C;">${esc(fm.fuel_type)}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Tank Reference:</td><td>${esc(fm.tank || '—')}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Movement Type:</td><td style="font-weight:700;">${typeLabel}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Liters:</td><td style="font-weight:700;color:#002F70;">${sign}${liters.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})} L</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Previous Volume:</td><td>${fm.previous_volume !== null ? Number(fm.previous_volume).toLocaleString(undefined, {minimumFractionDigits:2}) + ' L' : '—'}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">New Volume:</td><td style="font-weight:700;">${fm.new_volume !== null ? Number(fm.new_volume).toLocaleString(undefined, {minimumFractionDigits:2}) + ' L' : '—'}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Reference No:</td><td><code>${esc(fm.ref_no || '—')}</code></td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Performed By:</td><td>${esc(fm.performed_by || '—')}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Date/Time:</td><td>${fm.movement_date ? new Date(fm.movement_date).toLocaleString() : '—'}</td></tr>
        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; font-weight:600; color:#64748b;">Status:</td><td><span class="badge-approved">${esc(fm.status || 'Completed')}</span></td></tr>
        <tr><td style="padding:8px 0; font-weight:600; color:#64748b;">Notes / Details:</td><td style="font-style:italic;color:#1e293b;">${esc(fm.notes || '—')}</td></tr>
    `;
    
    document.getElementById('detailTable').innerHTML = html;
    document.getElementById('detailsModal').classList.add('open');
}

function closeDetailsModal() {
    document.getElementById('detailsModal').classList.remove('open');
}

// ── Print Merchandise Movement Slip ──
function printMovementSlip(m, typeLabel) {
    var qty = Number(m.quantity_change);
    var sign = qty > 0 && typeLabel.indexOf('Delivery') !== -1 ? '+' : '';
    if (typeLabel.indexOf('Release') !== -1) sign = '-';

    var pw = window.open('', '_blank');
    pw.document.write('<!DOCTYPE html><html><head><title>Inventory Movement Slip — #' + m.movement_id + '</title>');
    pw.document.write('<style>');
    pw.document.write('body{font-family:Arial,sans-serif;font-size:13px;color:#222;margin:0;padding:24px;}');
    pw.document.write('.header{background:#002F6C;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;}');
    pw.document.write('.header h2{margin:0;font-size:16px;letter-spacing:.5px;}');
    pw.document.write('.header p{margin:4px 0 0;font-size:11px;opacity:.8;}');
    pw.document.write('.section{border:1px solid #e2e8f0;border-top:none;padding:16px 20px;margin-bottom:12px;}');
    pw.document.write('.section h4{margin:0 0 10px;color:#002F6C;font-size:11px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}');
    pw.document.write('table.info{width:100%;border-collapse:collapse;font-size:12px;}');
    pw.document.write('table.info tr td:first-child{color:#64748b;font-weight:600;width:180px;padding:5px 0;}');
    pw.document.write('table.info tr td{padding:5px 0;border-bottom:1px solid #f1f5f9;}');
    pw.document.write('.footer{text-align:center;font-size:10px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}');
    pw.document.write('</style></head><body>');
    
    pw.document.write('<div class="header"><h2>Inventory Movement Record</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
    
    pw.document.write('<div class="section"><h4>Movement Info</h4><table class="info">');
    pw.document.write('<tr><td>Movement ID:</td><td><strong>#' + m.movement_id + '</strong></td></tr>');
    pw.document.write('<tr><td>Movement Type:</td><td><strong>' + esc(typeLabel) + '</strong></td></tr>');
    pw.document.write('<tr><td>Performed By:</td><td>' + esc(m.performed_by || 'System') + '</td></tr>');
    pw.document.write('<tr><td>Date & Time:</td><td>' + (m.created_at ? new Date(m.created_at).toLocaleString() : '—') + '</td></tr>');
    pw.document.write('</table></div>');

    pw.document.write('<div class="section"><h4>Item & Stock Specifications</h4><table class="info">');
    pw.document.write('<tr><td>Product Name:</td><td><strong>' + esc(m.product_name) + '</strong></td></tr>');
    pw.document.write('<tr><td>SKU:</td><td>' + esc(m.prod_sku || '—') + '</td></tr>');
    pw.document.write('<tr><td>Category:</td><td>' + esc(m.product_category || '—') + '</td></tr>');
    pw.document.write('<tr><td>Quantity Changed:</td><td><strong style="font-size:14px;color:#002F70;">' + sign + qty.toLocaleString() + ' ' + esc(m.unit || 'pcs') + '</strong></td></tr>');
    pw.document.write('<tr><td>Previous Stock:</td><td>' + Number(m.quantity_before).toLocaleString() + ' ' + esc(m.unit || 'pcs') + '</td></tr>');
    pw.document.write('<tr><td>New Stock:</td><td><strong>' + Number(m.quantity_after).toLocaleString() + ' ' + esc(m.unit || 'pcs') + '</strong></td></tr>');
    pw.document.write('</table></div>');

    pw.document.write('<div class="section"><h4>Details & Log Notes</h4><table class="info">');
    pw.document.write('<tr><td>Notes:</td><td><em>' + esc(m.notes || '—') + '</em></td></tr>');
    pw.document.write('</table></div>');

    pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    pw.document.write('</body></html>');
    pw.document.close();
    pw.print();
}

// ── Print Fuel Movement Slip ──
function printFuelMovementSlip(fm, typeLabel) {
    var liters = Number(fm.liters);
    var sign = '';
    if (typeLabel.indexOf('Delivery') !== -1) {
        sign = '+';
    } else if (typeLabel.indexOf('Sale') !== -1) {
        sign = '-';
    } else {
        sign = (liters >= 0) ? '+' : '';
    }

    var pw = window.open('', '_blank');
    pw.document.write('<!DOCTYPE html><html><head><title>Fuel Movement Slip — #' + fm.movement_id + '</title>');
    pw.document.write('<style>');
    pw.document.write('body{font-family:Arial,sans-serif;font-size:13px;color:#222;margin:0;padding:24px;}');
    pw.document.write('.header{background:#002F6C;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;}');
    pw.document.write('.header h2{margin:0;font-size:16px;letter-spacing:.5px;}');
    pw.document.write('.header p{margin:4px 0 0;font-size:11px;opacity:.8;}');
    pw.document.write('.section{border:1px solid #e2e8f0;border-top:none;padding:16px 20px;margin-bottom:12px;}');
    pw.document.write('.section h4{margin:0 0 10px;color:#002F6C;font-size:11px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}');
    pw.document.write('table.info{width:100%;border-collapse:collapse;font-size:12px;}');
    pw.document.write('table.info tr td:first-child{color:#64748b;font-weight:600;width:180px;padding:5px 0;}');
    pw.document.write('table.info tr td{padding:5px 0;border-bottom:1px solid #f1f5f9;}');
    pw.document.write('.footer{text-align:center;font-size:10px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}');
    pw.document.write('</style></head><body>');
    
    pw.document.write('<div class="header"><h2>Fuel Movement Record</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
    
    pw.document.write('<div class="section"><h4>Movement Info</h4><table class="info">');
    pw.document.write('<tr><td>Movement ID:</td><td><strong>#' + fm.movement_id + '</strong></td></tr>');
    pw.document.write('<tr><td>Movement Type:</td><td><strong>' + esc(typeLabel) + '</strong></td></tr>');
    pw.document.write('<tr><td>Performed By:</td><td>' + esc(fm.performed_by || '—') + '</td></tr>');
    pw.document.write('<tr><td>Date & Time:</td><td>' + (fm.movement_date ? new Date(fm.movement_date).toLocaleString() : '—') + '</td></tr>');
    pw.document.write('</table></div>');

    pw.document.write('<div class="section"><h4>Fuel Specifications</h4><table class="info">');
    pw.document.write('<tr><td>Fuel Type:</td><td><strong>' + esc(fm.fuel_type) + '</strong></td></tr>');
    pw.document.write('<tr><td>Tank Reference:</td><td>' + esc(fm.tank || '—') + '</td></tr>');
    pw.document.write('<tr><td>Liters Changed:</td><td><strong style="font-size:14px;color:#002F70;">' + sign + liters.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L</strong></td></tr>');
    pw.document.write('<tr><td>Previous Volume:</td><td>' + (fm.previous_volume !== null ? Number(fm.previous_volume).toLocaleString(undefined, {minimumFractionDigits:2}) + ' L' : '—') + '</td></tr>');
    pw.document.write('<tr><td>New Volume:</td><td><strong>' + (fm.new_volume !== null ? Number(fm.new_volume).toLocaleString(undefined, {minimumFractionDigits:2}) + ' L' : '—') + '</strong></td></tr>');
    pw.document.write('</table></div>');

    pw.document.write('<div class="section"><h4>Details & Log Notes</h4><table class="info">');
    pw.document.write('<tr><td>Reference No:</td><td><code>' + esc(fm.ref_no || '—') + '</code></td></tr>');
    pw.document.write('<tr><td>Status:</td><td>' + esc(fm.status || 'Completed') + '</td></tr>');
    pw.document.write('<tr><td>Notes:</td><td><em>' + esc(fm.notes || '—') + '</em></td></tr>');
    pw.document.write('</table></div>');

    pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    pw.document.write('</body></html>');
    pw.document.close();
    pw.print();
}

// ── Export Merchandise Functions ──
function exportMovTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrMovTable', 'Inventory Movement History Report');
    } else {
        window.print();
    }
}
function exportMovTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrMovTable', 'inventory_movement_history.xls');
    } else {
        alert('Excel export not supported on this page.');
    }
}
function exportMovTableCSV() {
    var rows = document.querySelectorAll('#mgrMovTable tr');
    var csv  = [];
    rows.forEach(function(row) {
        var cells = row.querySelectorAll('td, th');
        var data  = [];
        cells.forEach(function(cell, idx) {
            if (idx === cells.length - 1) return; // skip Actions column
            var text = cell.innerText.trim().replace(/"/g, '""');
            data.push('"' + text + '"');
        });
        if (data.length) csv.push(data.join(','));
    });
    var blob = new Blob([csv.join('\n')], {type: 'text/csv'});
    var a    = document.createElement('a');
    a.href  = URL.createObjectURL(blob);
    a.download = 'inventory_movement_history_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// ── Export Fuel Functions ──
function exportFuelTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrFuelMovTable', 'Fuel Movement History Report');
    } else {
        window.print();
    }
}
function exportFuelTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrFuelMovTable', 'fuel_movement_history.xls');
    } else {
        alert('Excel export not supported on this page.');
    }
}
function exportFuelTableCSV() {
    var rows = document.querySelectorAll('#mgrFuelMovTable tr');
    var csv  = [];
    rows.forEach(function(row) {
        var cells = row.querySelectorAll('td, th');
        var data  = [];
        cells.forEach(function(cell, idx) {
            if (idx === cells.length - 1) return; // skip Actions column
            var text = cell.innerText.trim().replace(/"/g, '""');
            data.push('"' + text + '"');
        });
        if (data.length) csv.push(data.join(','));
    });
    var blob = new Blob([csv.join('\n')], {type: 'text/csv'});
    var a    = document.createElement('a');
    a.href  = URL.createObjectURL(blob);
    a.download = 'fuel_movement_history_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('mgrMovTable') && typeof setupTablePagination === 'function') {
        setupTablePagination('mgrMovTable', null, 'mgrMovPagination', 10);
    }
    if (document.getElementById('mgrFuelMovTable') && typeof setupTablePagination === 'function') {
        setupTablePagination('mgrFuelMovTable', null, 'mgrFuelMovPagination', 10);
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
