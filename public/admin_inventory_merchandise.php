<?php
// ============================================================
// Admin Merchandise Inventory Oversight - admin_inventory_merchandise.php
// Rebuilt to support summary cards, filters, flat paginated table,
// action buttons (View Details, View History, Print Inventory), and modal popups.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_inventory_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['admin','superadmin'])) { 
    header('Location: dashboard.php'); 
    exit; 
}
if ($station_id <= 0 && $role === 'admin') { 
    render_no_station_page('admin_dashboard.php'); 
}

// ── Status Badge Styles / Helper Functions ───────────────────
if (!function_exists('getStatusBadgeClass')) {
    function getStatusBadgeClass($status) {
        $s = strtolower(trim($status ?? ''));
        if ($s === 'pending') return 'bg-amber';
        if ($s === 'available') return 'bg-green';
        if ($s === 'low' || $s === 'low stock') return 'bg-amber';
        if ($s === 'out' || $s === 'out of stock') return 'bg-red';
        return 'bg-gray';
    }
}

if (!function_exists('getStatusLabel')) {
    function getStatusLabel($status) {
        $s = strtolower(trim($status ?? ''));
        if ($s === 'pending') return 'Pending';
        if ($s === 'available') return 'Available';
        if ($s === 'low') return 'Low Stock';
        if ($s === 'out') return 'Out of Stock';
        if ($s === 'inactive') return 'Inactive';
        return ucfirst($status);
    }
}

// ── PRINT FRIENDLY VIEW ──────────────────────────────────────
if (isset($_GET['print_id'])) {
    $print_id = (int)$_GET['print_id'];
    
    // Fetch product details
    $stmt = $pdo->prepare("
        SELECT ip.*,
               COALESCE(si.unit, ip.size, 'pcs')       AS unit,
               COALESCE(si.status, 'active')          AS status,
               COALESCE(si.stock_level, ip.stock, 0)  AS stock_level,
               COALESCE(si.capacity, ip.max_stock, 100) AS capacity,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               COALESCE(si.variance, 0.00)            AS variance,
               COALESCE(si.last_updated, ip.updated_at, ip.created_at) AS last_updated,
               ip.supplier
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.id = ? AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel')
    ");
    $stmt->execute([$station_id, $print_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        die('Product not found.');
    }
    
    // Fetch station details
    $station_name = 'Petron Carmen';
    $station_address = 'Vamenta Blvd., Carmen, Cagayan de Oro';
    $station_contact = '';
    try {
        $st_stmt = $pdo->prepare("SELECT * FROM stations WHERE id = ? LIMIT 1");
        $st_stmt->execute([$station_id]);
        $station = $st_stmt->fetch(PDO::FETCH_ASSOC);
        if ($station) {
            if (!empty($station['name'])) $station_name = $station['name'];
            if (!empty($station['address'])) $station_address = $station['address'];
            if (!empty($station['contact_number'])) $station_contact = $station['contact_number'];
        }
    } catch (Exception $e) {}
    
    // Fetch logs
    $log_stmt = $pdo->prepare("
        SELECT il.*, 
               COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') as user_fullname
        FROM inventory_logs il
        LEFT JOIN users u ON il.user_id = u.id
        WHERE il.product_id = ? AND il.station_id = ?
        ORDER BY il.created_at DESC 
        LIMIT 10
    ");
    $log_stmt->execute([$print_id, $station_id]);
    $logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Compute inventory values
    $stock = (float)$item['stock_level'];
    $price = (float)$item['unit_price'];
    $value = $stock * $price;
    $capacity = (float)$item['capacity'];
    $fill_pct = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Inventory Sheet - <?= htmlspecialchars($item['product_name']) ?></title>
        <style>
            body { font-family: 'Courier New', Courier, monospace; font-size: 13px; color: #000; padding: 20px; line-height: 1.5; }
            .print-wrap { max-width: 600px; margin: 0 auto; border: 1px dashed #aaa; padding: 20px; }
            .center { text-align: center; }
            .logo { font-weight: bold; font-size: 20px; color: #002F70; margin-bottom: 2px; }
            .title { font-weight: bold; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 15px; font-size: 15px; }
            .row { display: flex; justify-content: space-between; margin-bottom: 5px; }
            .label { font-weight: bold; }
            .value { text-align: right; }
            .section { border-top: 1px dashed #000; margin-top: 15px; padding-top: 15px; }
            .log-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 11px; }
            .log-table th, .log-table td { border-bottom: 1px dotted #ccc; padding: 5px; text-align: left; }
            .log-table th { font-weight: bold; }
            .signatures { display: flex; justify-content: space-between; margin-top: 40px; }
            .sig-line { border-top: 1px solid #000; width: 45%; text-align: center; padding-top: 5px; font-size: 11px; }
            .var-pos { color: #16a34a; font-weight: bold; }
            .var-neg { color: #dc2626; font-weight: bold; }
            @media print {
                body { padding: 0; }
                .print-wrap { border: none; }
            }
        </style>
    </head>
    <body onload="window.print();">
        <div class="print-wrap">
            <div class="center" style="margin-bottom: 20px;">
                <div class="logo" style="letter-spacing: 1px;">PETRON CORPORATION</div>
                <div style="font-weight: bold; font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($station_name) ?></div>
                <div style="font-size: 11px; color: #555;"><?= htmlspecialchars($station_address) ?></div>
                <?php if ($station_contact): ?>
                    <div style="font-size: 11px; color: #555;">Contact: <?= htmlspecialchars($station_contact) ?></div>
                <?php endif; ?>
                <div class="title" style="margin-top: 10px; font-size: 16px;">PRODUCT INVENTORY SHEET</div>
            </div>
            
            <div class="row"><span class="label">Product Name:</span><span class="value"><?= htmlspecialchars($item['product_name']) ?></span></div>
            <div class="row"><span class="label">SKU / Code:</span><span class="value"><code><?= htmlspecialchars($item['sku']) ?></code></span></div>
            <div class="row"><span class="label">Category:</span><span class="value"><?= htmlspecialchars($item['category']) ?></span></div>
            <div class="row"><span class="label">Unit:</span><span class="value"><?= htmlspecialchars($item['unit']) ?></span></div>
            <div class="row"><span class="label">Supplier:</span><span class="value"><?= htmlspecialchars($item['supplier'] ?: '—') ?></span></div>
            
            <div class="section">
                <div class="row"><span class="label">Current Stock:</span><span class="value"><?= number_format($stock, 2) ?> <?= htmlspecialchars($item['unit']) ?></span></div>
                <div class="row"><span class="label">Reorder Level:</span><span class="value"><?= number_format($item['reorder_level'], 2) ?> <?= htmlspecialchars($item['unit']) ?></span></div>
                <div class="row"><span class="label">Maximum Stock:</span><span class="value"><?= number_format($capacity, 2) ?> <?= htmlspecialchars($item['unit']) ?></span></div>
                <div class="row"><span class="label">Capacity Fill:</span><span class="value"><?= number_format($fill_pct, 1) ?>%</span></div>
                <div class="row"><span class="label">Variance:</span><span class="value <?= $item['variance'] < 0 ? 'var-neg' : ($item['variance'] > 0 ? 'var-pos' : '') ?>"><?= ($item['variance'] > 0 ? '+' : '') . number_format($item['variance'], 2) ?></span></div>
            </div>

            <div class="section">
                <div class="row"><span class="label">Unit Price:</span><span class="value">&#8369;<?= number_format($price, 2) ?></span></div>
                <div class="row" style="font-size: 15px; font-weight: bold;"><span class="label">Inventory Value:</span><span class="value">&#8369;<?= number_format($value, 2) ?></span></div>
                <div class="row"><span class="label">Last Updated:</span><span class="value"><?= date('M d, Y H:i', strtotime($item['last_updated'])) ?></span></div>
            </div>

            <?php if (!empty($logs)): ?>
            <div class="section">
                <div class="label" style="font-weight: bold; margin-bottom: 5px;">Recent Stock Movements</div>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Action</th>
                            <th>Change</th>
                            <th>Performed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= date('m/d H:i', strtotime($log['created_at'])) ?></td>
                            <td><?= htmlspecialchars($log['action']) ?></td>
                            <td><?= ($log['quantity_change'] > 0 ? '+' : '') . number_format($log['quantity_change'], 0) ?></td>
                            <td><?= htmlspecialchars($log['user_fullname']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="signatures">
                <div class="sig-line">
                    Prepared By: <?= htmlspecialchars($me['first_name'] . ' ' . $me['last_name']) ?><br>
                    Admin / Staff
                </div>
                <div class="sig-line">
                    Verified By:<br>
                    Station Manager
                </div>
            </div>

            <div class="center section" style="font-size: 10px; color: #666; margin-top: 30px;">
                Petron Station Management System • Official Inventory Slip
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── GET MOVEMENT HISTORY (AJAX ENDPOINT) ──────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');
    $pid = (int)($_GET['product_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT il.*, 
                   COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') as user_fullname
            FROM inventory_logs il
            LEFT JOIN users u ON il.user_id = u.id
            WHERE il.product_id = ? AND il.station_id = ?
            ORDER BY il.created_at DESC 
            LIMIT 30
        ");
        $stmt->execute([$pid, $station_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── GET Filters ──────────────────────────────────────────────
$search_query   = trim($_GET['search_query'] ?? '');
$category_filter = trim($_GET['category'] ?? 'all');
$status_filter   = trim($_GET['status_filter'] ?? 'all');
$date_from      = trim($_GET['date_from'] ?? '');
$date_to        = trim($_GET['date_to'] ?? '');

// ── Fetch dynamic categories for dropdown ────────────────────
$all_categories = [];
try {
    $cat_stmt = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE category NOT IN ('Fuel') AND category IS NOT NULL AND category != '' ORDER BY category");
    $all_categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ── Fetch all merchandise items ──────────────────────────────
$all_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT ip.id,
               ip.product_name AS name,
               ip.category     AS category_name,
               ip.unit_price   AS price,
               ip.unit_cost    AS cost,
               ip.sku,
               COALESCE(si.unit, ip.size, 'pcs')       AS unit,
               COALESCE(si.status, 'active')          AS status,
               COALESCE(si.stock_level, ip.stock, 0)  AS stock_level,
               COALESCE(si.capacity, ip.max_stock, 100) AS capacity,
               COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level,
               COALESCE(si.variance, 0.00)            AS variance,
               COALESCE(si.last_updated, ip.updated_at, ip.created_at) AS last_updated,
               ip.supplier
        FROM inventory_products ip
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel')
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $all_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error loading merchandise inventory: " . $e->getMessage());
}

// ── Compute KPI summary metrics & filter list in PHP ──────────
$kpi_total_products = 0;
$kpi_total_stock    = 0;
$kpi_low_stock      = 0;
$kpi_out_of_stock   = 0;
$kpi_total_value    = 0;
$kpi_total_variance = 0;

$filtered_items = [];

foreach ($all_items as $item) {
    $stock     = (float)$item['stock_level'];
    $capacity  = (float)$item['capacity'];
    $reorder   = (float)$item['reorder_level'];
    $price     = (float)$item['price'];
    $variance  = (float)$item['variance'];
    $item_status = strtolower(trim($item['status'] ?? 'active'));
    
    // Status computation (matching dashboard rules)
    $fill_pct = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
    
    if ($stock <= 0) {
        $computed_status = 'out';
    } elseif ($stock <= $reorder || $fill_pct <= 25) {
        $computed_status = 'low';
    } else {
        $computed_status = 'available';
    }
    
    if ($item_status === 'inactive') {
        $computed_status = 'inactive';
    }

    // Global KPIs (unfiltered metrics for this station)
    $kpi_total_products++;
    $kpi_total_stock += $stock;
    if ($computed_status === 'low') $kpi_low_stock++;
    elseif ($computed_status === 'out') $kpi_out_of_stock++;
    $kpi_total_value += ($stock * $price);
    $kpi_total_variance += $variance;

    // Apply Filters
    // 1. Search Query
    if ($search_query !== '') {
        $s_lower = strtolower($search_query);
        $name_match = (strpos(strtolower($item['name'] ?? ''), $s_lower) !== false);
        $sku_match  = (strpos(strtolower($item['sku'] ?? ''), $s_lower) !== false);
        $cat_match  = (strpos(strtolower($item['category_name'] ?? ''), $s_lower) !== false);
        if (!$name_match && !$sku_match && !$cat_match) {
            continue;
        }
    }

    // 2. Category Filter
    if ($category_filter !== 'all' && $category_filter !== '') {
        if ($item['category_name'] !== $category_filter) {
            continue;
        }
    }

    // 3. Status Filter
    if ($status_filter !== 'all' && $status_filter !== '') {
        if ($computed_status !== $status_filter) {
            continue;
        }
    }

    // 4. Date range filter on last_updated
    $updated_date = date('Y-m-d', strtotime($item['last_updated'] ?? 'now'));
    if ($date_from !== '' && $updated_date < $date_from) {
        continue;
    }
    if ($date_to !== '' && $updated_date > $date_to) {
        continue;
    }

    $item['computed_status'] = $computed_status;
    $filtered_items[] = $item;
}

// Group filtered items by category for grouped rendering (like manager view)
$sorted_filtered = [];
if (!empty($filtered_items)) {
    $grouped_filtered_items = [];
    foreach ($filtered_items as $item) {
        $cat = $item['category_name'] ?: 'Uncategorized';
        $grouped_filtered_items[$cat][] = $item;
    }
    // Sort categories so they match a logical order or alphabetical order
    $cat_order = ['Oils / Lubes / Grease', 'Car Accessories', 'Brake System', 'Tire', 'Maintenance', 'Oil / Fuel Filters', 'Others (Snacks / Drinks)'];
    foreach ($cat_order as $k) { 
        if (isset($grouped_filtered_items[$k])) {
            $sorted_filtered[$k] = $grouped_filtered_items[$k]; 
        } 
    }
    foreach ($grouped_filtered_items as $k => $v) { 
        if (!in_array($k, $cat_order)) {
            $sorted_filtered[$k] = $v; 
        }
    }
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* == PAGE HEADER - Petron standard == */
.int-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    margin-top: -12px !important;
}
.int-head h1 {
    font-size: 22px !important;
    font-weight: 700 !important;
    color: var(--petron-blue, #00264D) !important;
    margin: 0 !important;
    text-transform: uppercase !important;
    display: flex;
    align-items: center;
    gap: 8px;
}
.int-head .sub {
    font-size: 13px;
    color: #666;
    margin-top: 4px;
    text-transform: none !important;
}

/* == SUMMARY CARDS == */
.afto-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.afto-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
    text-decoration: none !important;
}
.afto-card-info {
    display: flex;
    flex-direction: column;
}
.afto-card-lbl {
    font-size: 10px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
    text-decoration: none !important;
}
.afto-card-val {
    font-size: 18px;
    font-weight: 800;
    color: #1e293b;
    text-decoration: none !important;
}
.afto-card-icon {
    font-size: 22px;
    opacity: 0.85;
}

/* Card variants - removed colored borders */
.afto-card.blue .afto-card-icon { color: #2563eb; }
.afto-card.green .afto-card-icon { color: #16a34a; }
.afto-card.yellow .afto-card-icon { color: #d97706; }
.afto-card.red .afto-card-icon { color: #dc2626; }
.afto-card.purple .afto-card-icon { color: #7c3aed; }
.afto-card.orange .afto-card-icon { color: #ea580c; }

/* == FILTER BAR == */
.afto-filter {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.afto-fg {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.afto-fg label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.afto-fg input, .afto-fg select {
    height: 36px;
    padding: 0 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    color: #1e293b;
    background: #ffffff;
    outline: none;
    box-sizing: border-box;
}
.afto-fg input:focus, .afto-fg select:focus {
    border-color: var(--petron-blue, #00264D);
    box-shadow: 0 0 0 3px rgba(0, 38, 77, 0.1);
}
.afto-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* == Buttons styled to match Petron-clean flt-btn & txn-btn == */
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
.flt-btn-csv    { color: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-csv:hover    { background: #002F70 !important; color: #fff !important; }

/* Action Buttons (txn-btn style) */
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
    transition: all .18s;
    background: white !important;
    border: 1px solid transparent;
    text-decoration: none;
    box-sizing: border-box;
}
.txn-btn-adjust { color: #00264D !important; border-color: #00264D !important; }
.txn-btn-adjust:hover { background: #00264D !important; color: #fff !important; }
.txn-btn-info { color: #0284c7 !important; border-color: #0284c7 !important; }
.txn-btn-info:hover { background: #0284c7 !important; color: #fff !important; }
.txn-btn-secondary { color: #6b7280 !important; border-color: #6b7280 !important; }
.txn-btn-secondary:hover { background: #6b7280 !important; color: #fff !important; }

/* == TABLE CARD == */
.tbl-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    margin-bottom: 24px;
}
.tbl-hd {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid #e9ecef;
    flex-wrap: wrap;
    gap: 8px;
    background: #f8fafc;
}
.tbl-title {
    font-size: 14px;
    font-weight: 700;
    color: #00264D;
    display: flex;
    align-items: center;
    gap: 8px;
}
.afto-tbl {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    text-align: left;
}
.afto-tbl thead tr {
    background: #002F70;
}
.afto-tbl thead th {
    padding: 10px 12px;
    font-weight: 700;
    color: #ffffff;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-size: 11px;
    border-bottom: 2px solid #001a3d;
}
.afto-tbl tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.1s ease;
}
.afto-tbl tbody tr:hover td {
    background: #f8fafc;
}
.afto-tbl tbody td {
    padding: 10px 12px;
    color: #334155;
    vertical-align: middle;
}

/* Numeric alignment */
.align-right { text-align: right; font-family: monospace; }
.align-center { text-align: center; }

/* Variance formatting */
.var-pos { color: #16a34a !important; font-weight: 700; }
.var-neg { color: #dc2626 !important; font-weight: 700; }
.var-zero { color: #64748b !important; font-weight: 600; }

/* Status Badges */
.badge-lbl {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
    white-space: nowrap;
}
.bg-amber { background-color: #fef3c7; color: #b45309; }
.bg-green { background-color: #dcfce7; color: #15803d; }
.bg-red   { background-color: #fee2e2; color: #b91c1c; }
.bg-gray  { background-color: #f1f5f9; color: #475569; }

/* Modals */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9000;
}
.modal-overlay.show {
    display: flex;
}
.modal-box {
    background: #ffffff;
    border-radius: 12px;
    padding: 24px;
    width: 480px;
    max-width: 95vw;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}
.modal-box h3 {
    margin: 0 0 12px;
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 8px;
}
.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
    border-top: 1px solid #e2e8f0;
    padding-top: 12px;
}
</style>

<!-- Page Header -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-boxes"></i> Merchandise Inventory Oversight</h1>
        <div class="sub">Stock monitoring &amp; audit oversight &middot; Today: <?= date('F d, Y') ?></div>
    </div>
    
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;">
        <!-- Excel -->
        <button onclick="exportTableToExcel('adminMerchTable','admin_merch_inventory_<?= date('Ymd') ?>')"
                class="flt-btn flt-btn-excel" title="Export to Excel">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <!-- CSV -->
        <button onclick="exportTableToCSV('adminMerchTable','admin_merch_inventory_<?= date('Ymd') ?>.csv')"
                class="flt-btn flt-btn-csv" title="Export to CSV">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <!-- PDF -->
        <button onclick="exportTableToPDF('adminMerchTable','Merchandise Inventory Oversight')"
                class="flt-btn flt-btn-pdf" title="Export to PDF">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
    </div>
</div>

<!-- Summary Cards -->
<div class="afto-cards">
    <!-- Card 1: Total Products -->
    <div class="afto-card blue">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Products</span>
            <span class="afto-card-val"><?= number_format($kpi_total_products) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-box"></i></div>
    </div>
    <!-- Card 2: Total Stock Qty -->
    <div class="afto-card green">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Stock Qty</span>
            <span class="afto-card-val"><?= number_format($kpi_total_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-layer-group"></i></div>
    </div>
    <!-- Card 3: Low Stock Items -->
    <div class="afto-card yellow">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Low Stock Items</span>
            <span class="afto-card-val"><?= number_format($kpi_low_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <!-- Card 4: Out of Stock Items -->
    <div class="afto-card red">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Out of Stock Items</span>
            <span class="afto-card-val"><?= number_format($kpi_out_of_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-times-circle"></i></div>
    </div>
    <!-- Card 5: Total Inventory Value -->
    <div class="afto-card purple">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Value</span>
            <span class="afto-card-val">&#8369;<?= number_format($kpi_total_value, 2) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-coins"></i></div>
    </div>
    <!-- Card 6: Total Variance -->
    <div class="afto-card orange">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Variance</span>
            <span class="afto-card-val <?= $kpi_total_variance < 0 ? 'var-neg' : ($kpi_total_variance > 0 ? 'var-pos' : '') ?>"><?= ($kpi_total_variance > 0 ? '+' : '') . number_format($kpi_total_variance, 2) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-balance-scale"></i></div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" action="admin_inventory_merchandise.php" class="afto-filter">
    <div class="afto-fg" style="flex: 2; min-width: 200px;">
        <label for="search_query">Search Product</label>
        <input type="text" name="search_query" id="search_query" placeholder="Search SKU, name, supplier..." value="<?= htmlspecialchars($search_query) ?>">
    </div>
    
    <div class="afto-fg" style="flex: 1; min-width: 150px;">
        <label for="category">Category</label>
        <select name="category" id="category">
            <option value="all">All Categories</option>
            <?php foreach ($all_categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $category_filter === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="afto-fg" style="flex: 1; min-width: 150px;">
        <label for="status_filter">Status</label>
        <select name="status_filter" id="status_filter">
            <option value="all">All Statuses</option>
            <option value="available" <?= $status_filter === 'available' ? 'selected' : '' ?>>Available</option>
            <option value="low" <?= $status_filter === 'low' ? 'selected' : '' ?>>Low Stock</option>
            <option value="out" <?= $status_filter === 'out' ? 'selected' : '' ?>>Out of Stock</option>
            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>

    <div class="afto-fg" style="flex: 1; min-width: 130px;">
        <label for="date_from">Updated From</label>
        <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>">
    </div>

    <div class="afto-fg" style="flex: 1; min-width: 130px;">
        <label for="date_to">Updated To</label>
        <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>">
    </div>
    
    <div class="afto-actions">
        <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-filter"></i> Filter</button>
        <a href="admin_inventory_merchandise.php" class="flt-btn flt-btn-reset"><i class="fas fa-sync-alt"></i> Reset</a>
    </div>
</form>

<!-- Table Card -->
<div class="tbl-card">
    <div class="tbl-hd">
        <div class="tbl-title"><i class="fas fa-clipboard-list"></i> Merchandise Stock Records</div>
    </div>
    <div style="overflow-x:auto;">
        <table class="afto-tbl" id="adminMerchTable">
            <thead>
                <tr>
                    <th style="width: 100px;">SKU</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th style="width: 70px;">Unit</th>
                    <th style="width: 110px; text-align:right;">Current Stock</th>
                    <th style="width: 110px; text-align:right;">Reorder Level</th>
                    <th style="width: 110px; text-align:right;">Maximum Stock</th>
                    <th style="width: 130px; text-align:right;">Inventory Value</th>
                    <th style="width: 100px;" class="align-center">Status</th>
                    <th style="width: 90px; text-align:right;">Variance</th>
                    <th style="width: 140px;">Last Updated</th>
                    <th style="width: 120px; text-align:center;">Action Buttons</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($sorted_filtered)): ?>
                <tr>
                    <td colspan="12" class="align-center" style="padding: 24px; color: #64748b;">
                        <i class="fas fa-box-open" style="font-size: 24px; margin-bottom: 8px; display:block;"></i>
                        No merchandise inventory records matched your filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($sorted_filtered as $cat_label => $items): ?>
                    <tr class="cat-header">
                        <td colspan="12" style="text-align:center; font-weight:700; background:#e9ecef !important; color:#495057 !important; text-transform:uppercase; font-size:12px; letter-spacing:.5px; border-bottom:2px solid #dee2e6; padding:8px 12px;">
                            <strong><?= htmlspecialchars($cat_label) ?></strong>
                        </td>
                    </tr>
                    <?php foreach ($items as $item): 
                        $stock    = (float)$item['stock_level'];
                        $reorder  = (float)$item['reorder_level'];
                        $capacity = (float)$item['capacity'];
                        $price    = (float)$item['price'];
                        $value    = $stock * $price;
                        $variance = (float)$item['variance'];
                        $badgeCls = getStatusBadgeClass($item['computed_status']);
                        $badgeLbl = getStatusLabel($item['computed_status']);
                        $updated  = $item['last_updated'] ? date('M d, Y h:i A', strtotime($item['last_updated'])) : '—';
                    ?>
                    <tr>
                        <td><code><?= htmlspecialchars($item['sku']) ?></code></td>
                        <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                        <td><?= htmlspecialchars($item['category_name']) ?></td>
                        <td><?= htmlspecialchars($item['unit']) ?></td>
                        <td class="align-right" style="font-weight:700; color:#002F70;"><?= number_format($stock, 2) ?></td>
                        <td class="align-right"><?= number_format($reorder, 2) ?></td>
                        <td class="align-right"><?= number_format($capacity, 2) ?></td>
                        <td class="align-right" style="font-weight:700; color:#16a34a;">&#8369;<?= number_format($value, 2) ?></td>
                        <td class="align-center"><span class="badge-lbl <?= $badgeCls ?>"><?= $badgeLbl ?></span></td>
                        <td class="align-right <?= $variance < 0 ? 'var-neg' : ($variance > 0 ? 'var-pos' : 'var-zero') ?>"><?= ($variance > 0 ? '+' : '') . number_format($variance, 2) ?></td>
                        <td style="font-size:11px; color:#64748b;"><?= $updated ?></td>
                        <td style="text-align:center;">
                            <button type="button" class="txn-btn txn-btn-info" onclick='viewDetails(<?= json_encode($item, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="View Details">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Client-side dynamic pagination injection -->
    <div id="adminMerchPagination" style="margin: 10px 20px;"></div>
</div>

<!-- Details Modal -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box" style="width: 500px; max-width: 95vw;">
        <h3 id="detailsModalTitle">Product Details</h3>
        <div style="margin-top: 15px;">
            <table class="table" style="font-size: 13px; margin: 0;">
                <tbody>
                    <tr><td style="font-weight:bold; width:35%;">SKU / Code</td><td id="detSku"></td></tr>
                    <tr><td style="font-weight:bold;">Product Name</td><td id="detName"></td></tr>
                    <tr><td style="font-weight:bold;">Category</td><td id="detCategory"></td></tr>
                    <tr><td style="font-weight:bold;">Unit</td><td id="detUnit"></td></tr>
                    <tr><td style="font-weight:bold;">Supplier</td><td id="detSupplier"></td></tr>
                    <tr><td style="font-weight:bold;">Unit Price</td><td id="detPrice" style="color:#16a34a; font-weight:bold;"></td></tr>
                    <tr><td style="font-weight:bold;">Unit Cost</td><td id="detCost"></td></tr>
                    <tr><td style="font-weight:bold;">Current Stock</td><td id="detStock" style="font-weight:bold; color:#002F70;"></td></tr>
                    <tr><td style="font-weight:bold;">Reorder Level</td><td id="detReorder"></td></tr>
                    <tr><td style="font-weight:bold;">Maximum Capacity</td><td id="detCapacity"></td></tr>
                    <tr><td style="font-weight:bold;">Inventory Value</td><td id="detValue" style="font-weight:bold; color:#002F70;"></td></tr>
                    <tr><td style="font-weight:bold;">Variance</td><td id="detVariance"></td></tr>
                    <tr><td style="font-weight:bold;">Status</td><td id="detStatus"></td></tr>
                    <tr><td style="font-weight:bold;">Last Updated</td><td id="detUpdated"></td></tr>
                </tbody>
            </table>
        </div>
        <div class="modal-actions">
            <button type="button" onclick="document.getElementById('detailsModal').classList.remove('show')"
                    style="padding:8px 18px;background:#6c757d;color:#fff;border:none;border-radius:5px;cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<!-- Movement History Modal -->
<div class="modal-overlay" id="historyModal">
    <div class="modal-box" style="width: 750px; max-width: 95vw;">
        <h3 id="historyModalTitle">Stock Movement History</h3>
        <div style="max-height: 380px; overflow-y: auto; margin-top: 12px; border: 1px solid #e2e8f0; border-radius: 6px;">
            <table class="afto-tbl" style="width: 100%; margin: 0;">
                <thead>
                    <tr style="background:#002F70; color:#fff;">
                        <th style="padding:10px 8px; font-size:11px;">Date</th>
                        <th style="padding:10px 8px; font-size:11px;">Action</th>
                        <th style="padding:10px 8px; font-size:11px; text-align:right;">Change</th>
                        <th style="padding:10px 8px; font-size:11px; text-align:right;">Before</th>
                        <th style="padding:10px 8px; font-size:11px; text-align:right;">After</th>
                        <th style="padding:10px 8px; font-size:11px;">Performed By</th>
                        <th style="padding:10px 8px; font-size:11px;">Notes</th>
                    </tr>
                </thead>
                <tbody id="historyModalBody">
                    <!-- Dynamic Rows -->
                </tbody>
            </table>
        </div>
        <div class="modal-actions">
            <button type="button" onclick="document.getElementById('historyModal').classList.remove('show')"
                    style="padding:8px 18px;background:#6c757d;color:#fff;border:none;border-radius:5px;cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<script>
// Details modal dynamic content injection
function viewDetails(item) {
    document.getElementById('detSku').textContent = item.sku || '—';
    document.getElementById('detName').textContent = item.name || '—';
    document.getElementById('detCategory').textContent = item.category_name || '—';
    document.getElementById('detUnit').textContent = item.unit || 'pcs';
    document.getElementById('detSupplier').textContent = item.supplier || '—';
    
    var price = parseFloat(item.price);
    document.getElementById('detPrice').innerHTML = '&#8369;' + price.toFixed(2);
    
    var cost = parseFloat(item.cost || 0);
    document.getElementById('detCost').innerHTML = '&#8369;' + cost.toFixed(2);
    
    var stock = parseFloat(item.stock_level);
    document.getElementById('detStock').textContent = stock.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' ' + (item.unit || 'pcs');
    
    var reorder = parseFloat(item.reorder_level);
    document.getElementById('detReorder').textContent = reorder.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' ' + (item.unit || 'pcs');
    
    var capacity = parseFloat(item.capacity);
    document.getElementById('detCapacity').textContent = capacity.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' ' + (item.unit || 'pcs');
    
    var value = stock * price;
    document.getElementById('detValue').innerHTML = '&#8369;' + value.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    var variance = parseFloat(item.variance || 0);
    var varEl = document.getElementById('detVariance');
    varEl.textContent = (variance > 0 ? '+' : '') + variance.toFixed(2);
    varEl.className = variance < 0 ? 'var-neg' : (variance > 0 ? 'var-pos' : 'var-zero');
    
    var statusText = '';
    var statusClass = '';
    if (item.computed_status === 'out') {
        statusText = 'Out of Stock';
        statusClass = 'bg-red';
    } else if (item.computed_status === 'low') {
        statusText = 'Low Stock';
        statusClass = 'bg-amber';
    } else if (item.computed_status === 'inactive') {
        statusText = 'Inactive';
        statusClass = 'bg-gray';
    } else {
        statusText = 'Available';
        statusClass = 'bg-green';
    }
    
    document.getElementById('detStatus').innerHTML = '<span class="badge-lbl ' + statusClass + '">' + statusText + '</span>';
    
    var dateStr = item.last_updated ? new Date(item.last_updated).toLocaleString('en-US', {
        month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
    }) : '—';
    document.getElementById('detUpdated').textContent = dateStr;
    
    document.getElementById('detailsModal').classList.add('show');
}

// Fetch and display product movement logs inside modal
function viewHistory(productId, productName) {
    document.getElementById('historyModalTitle').innerText = 'Stock Movement History - ' + productName;
    var tbody = document.getElementById('historyModalBody');
    tbody.innerHTML = '<tr><td colspan="7" class="align-center" style="padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading movement history...</td></tr>';
    document.getElementById('historyModal').classList.add('show');
    
    fetch('admin_inventory_merchandise.php?action=get_history&product_id=' + productId)
        .then(response => response.json())
        .then(res => {
            if (res.success) {
                if (res.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="align-center" style="padding: 20px; color: #64748b;">No stock movements recorded for this product.</td></tr>';
                } else {
                    var html = '';
                    res.data.forEach(function(row) {
                        var change = parseFloat(row.quantity_change);
                        var changeStr = (change > 0 ? '+' : '') + change.toLocaleString(undefined, {minimumFractionDigits: 2});
                        var changeClass = change > 0 ? 'var-pos' : (change < 0 ? 'var-neg' : 'var-zero');
                        
                        var date = new Date(row.created_at);
                        var dateStr = date.toLocaleDateString('en-US', { month: 'short', day: '2-digit' }) + ' ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                        
                        html += '<tr>' +
                            '<td style="padding:8px; font-size:11px;">' + dateStr + '</td>' +
                            '<td style="padding:8px;">' + row.action + '</td>' +
                            '<td style="padding:8px; text-align:right; font-weight:bold;" class="' + changeClass + '">' + changeStr + '</td>' +
                            '<td style="padding:8px; text-align:right; font-family:monospace;">' + parseFloat(row.quantity_before).toLocaleString(undefined, {minimumFractionDigits: 2}) + '</td>' +
                            '<td style="padding:8px; text-align:right; font-family:monospace;">' + parseFloat(row.quantity_after).toLocaleString(undefined, {minimumFractionDigits: 2}) + '</td>' +
                            '<td style="padding:8px;">' + row.user_fullname + '</td>' +
                            '<td style="padding:8px; font-size:11px; color:#475569;">' + (row.notes || '—') + '</td>' +
                            '</tr>';
                    });
                    tbody.innerHTML = html;
                }
            } else {
                tbody.innerHTML = '<tr><td colspan="7" class="align-center" style="padding: 20px; color: #dc2626;">Error: ' + res.error + '</td></tr>';
            }
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="7" class="align-center" style="padding: 20px; color: #dc2626;">Network error occurred while fetching history.</td></tr>';
        });
}

// Modal click-outside dismissal
document.querySelectorAll('.modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('show');
    });
});

document.addEventListener('DOMContentLoaded', function() {
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminMerchTable', 'adminMerchRowsLimit', 'adminMerchPagination', 50);
    }
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
