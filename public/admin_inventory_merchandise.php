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
        if ($s === 'critical' || $s === 'critical stock') return 'bg-red';
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
        if ($s === 'critical') return 'Critical Stock';
        if ($s === 'out') return 'Out of Stock';
        if ($s === 'inactive') return 'Inactive';
        return ucfirst($status);
    }
}

// Brand parser helper
if (!function_exists('get_product_brand')) {
    function get_product_brand($product_name) {
        $name = strtolower($product_name);
        if (strpos($name, 'petron') !== false) return 'Petron';
        if (strpos($name, 'sprint') !== false) return 'Sprint';
        if (strpos($name, 'rev-x') !== false || strpos($name, 'revx') !== false) return 'Rev-X';
        if (strpos($name, 'ultron') !== false) return 'Ultron';
        if (strpos($name, 'blaze') !== false) return 'Blaze';
        return 'Petron'; // Fallback brand
    }
}

// Barcode generator helper
if (!function_exists('get_product_barcode')) {
    function get_product_barcode($sku) {
        if (empty($sku)) return '4800012345678';
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $sku);
        $num = '';
        for ($i = 0; $i < strlen($clean); $i++) {
            $num .= ord($clean[$i]) % 10;
        }
        return '480' . str_pad(substr($num, 0, 10), 10, '0', STR_PAD_RIGHT);
    }
}

// ── PRINT FRIENDLY VIEW ──────────────────────────────────────
if (isset($_GET['print_id'])) {
    $print_id = (int)$_GET['print_id'];
    
    // Fetch product details
    $item = null;
    try {
        $stmt = $pdo->prepare("
            SELECT ip.*,
                   ip.product_name AS name,
                   ip.category AS category_name,
                   ip.unit_price AS price,
                   ip.unit_cost AS cost,
                   COALESCE(si.unit, ip.size, 'pcs')       AS unit,
                   COALESCE(si.status, 'active')          AS status,
                   COALESCE(si.stock_level, ip.stock, 0)  AS stock_level,
                   COALESCE(si.capacity, ip.max_stock, 480) AS capacity,
                   COALESCE(si.reorder_level, ip.min_stock, 24) AS reorder_level,
                   COALESCE(si.critical_level, 10)              AS critical_level,
                   COALESCE(si.variance, 0.00)            AS variance,
                   COALESCE(si.last_updated, ip.updated_at, ip.created_at) AS last_updated,
                   COALESCE(ip.brand, 'Petron Corporation') AS supplier
            FROM inventory_products ip
            LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
            WHERE ip.id = ? AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
        ");
        $stmt->execute([$station_id, $print_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $primary_error) {}

    if (!$item) {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   p.name AS product_name,
                   p.name AS name,
                   COALESCE(pc.name, 'General') AS category,
                   COALESCE(pc.name, 'General') AS category_name,
                   COALESCE(si.price, p.price, si.cost, p.cost, 0) AS unit_price,
                   COALESCE(si.price, p.price, si.cost, p.cost, 0) AS price,
                   COALESCE(p.cost, si.cost, 0) AS unit_cost,
                   COALESCE(p.cost, si.cost, 0) AS cost,
                   COALESCE(NULLIF(p.sku, ''), CONCAT('P', LPAD(p.id, 4, '0'))) AS sku,
                   COALESCE(NULLIF(p.unit, ''), NULLIF(si.unit, ''), 'pcs') AS unit,
                   COALESCE(NULLIF(si.status, ''), NULLIF(p.status, ''), 'active') AS status,
                   COALESCE(si.stock_level, p.current_stock, 0) AS stock_level,
                   COALESCE(NULLIF(si.capacity, 0), NULLIF(p.capacity, 0), NULLIF(p.max_stock_level, 0), 480) AS capacity,
                   COALESCE(NULLIF(si.reorder_level, 0), NULLIF(p.min_stock_level, 0), 24) AS reorder_level,
                   COALESCE(NULLIF(si.critical_level, 0), 10) AS critical_level,
                   COALESCE(si.variance, 0.00) AS variance,
                   COALESCE(si.last_updated, p.updated_at, p.created_at) AS last_updated,
                   COALESCE(latest_supplier.supplier, '') AS supplier
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            LEFT JOIN (
                SELECT LOWER(TRIM(poi.item_name)) AS product_key,
                       SUBSTRING_INDEX(GROUP_CONCAT(s.name ORDER BY po.created_at DESC SEPARATOR '||'), '||', 1) AS supplier
                FROM purchase_order_items poi
                JOIN purchase_orders po ON po.id = poi.po_id
                LEFT JOIN suppliers s ON s.id = po.supplier_id
                WHERE po.station_id = ?
                  AND po.type = 'merch'
                  AND s.name IS NOT NULL
                  AND s.name != ''
                GROUP BY LOWER(TRIM(poi.item_name))
            ) latest_supplier ON latest_supplier.product_key = LOWER(TRIM(p.name))
            LEFT JOIN station_inventory si ON si.product_id = p.id AND si.station_id = ?
            WHERE p.id = ?
              AND LOWER(COALESCE(pc.name, '')) NOT IN ('fuel', 'fuel products', 'services')
        ");
        $stmt->execute([$station_id, $station_id, $print_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$item) {
        die('Product not found.');
    }
    
    $item['category_name'] = format_product_category_display(
        $item['category_name'] ?? $item['category'] ?? '',
        $item['product_name'] ?? $item['name'] ?? '',
        $item['description'] ?? ''
    );
    $item['category'] = $item['category_name'];
    $item['brand'] = get_product_brand(
        $item['product_name'] ?? $item['name'] ?? '',
        $item['category_name'] ?? '',
        $item['description'] ?? ''
    );
    $item['barcode'] = get_product_barcode($item['sku']);
    $item['supplier'] = 'Petron Corporation';
    $item['unit'] = format_product_unit_display(
        $item['unit'] ?? 'pcs',
        $item['product_name'] ?? $item['name'] ?? '',
        $item['category_name'] ?? '',
        $item['description'] ?? ''
    );
    
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
            <div class="row"><span class="label">Brand:</span><span class="value"><?= htmlspecialchars($item['brand']) ?></span></div>
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
$brand_filter    = trim($_GET['brand'] ?? 'all');
$supplier_filter = trim($_GET['supplier'] ?? 'all');
$unit_filter     = trim($_GET['unit'] ?? 'all');
$status_filter   = strtolower(trim($_GET['status_filter'] ?? 'all'));
$date_from      = trim($_GET['date_from'] ?? '');
$date_to        = trim($_GET['date_to'] ?? '');

// ── Fetch dynamic categories for dropdown ────────────────────
$all_categories = [];
try {
    $cat_stmt = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE category NOT IN ('fuel', 'fuel products') AND category IS NOT NULL AND category != '' ORDER BY category");
    $all_categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    try {
        $cat_stmt = $pdo->query("
            SELECT DISTINCT pc.name
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE pc.name IS NOT NULL
              AND pc.name != ''
              AND LOWER(pc.name) NOT IN ('fuel', 'fuel products', 'services')
            ORDER BY pc.name
        ");
        $all_categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $fallback_error) {}
}

// ── Fetch all merchandise items ──────────────────────────────
$all_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT ip.id,
               ip.product_name AS name,
               COALESCE(ip.category,'Merchandise') AS category_name,
               COALESCE(ip.unit_price, 0)   AS price,
               COALESCE(ip.unit_cost, 0)    AS cost,
               ip.sku,
               COALESCE(si.unit, ip.size, 'pcs')            AS unit,
               COALESCE(si.status, ip.status, 'active')      AS status,
               COALESCE(si.stock_level, ip.stock, 0)         AS stock_level,
               COALESCE(si.capacity, ip.max_stock, 480)      AS capacity,
               COALESCE(si.reorder_level, ip.min_stock, 24)  AS reorder_level,
               COALESCE(si.critical_level, 10)               AS critical_level,
               si.physical_count,
               si.variance,
               COALESCE(si.last_updated, ip.updated_at, ip.created_at) AS last_updated,
               COALESCE(ip.brand,'Petron Corporation')       AS supplier
        FROM inventory_products ip
        LEFT JOIN station_inventory si
               ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')

        UNION

        SELECT p.id,
               p.name AS name,
               COALESCE(pc.name,'General')                   AS category_name,
               COALESCE(si2.price, p.price, 0)               AS price,
               COALESCE(p.cost, si2.cost, 0)                 AS cost,
               COALESCE(NULLIF(p.sku,''), CONCAT('P', LPAD(p.id,4,'0'))) AS sku,
               COALESCE(NULLIF(p.unit,''), NULLIF(si2.unit,''), 'pcs')  AS unit,
               COALESCE(NULLIF(si2.status,''), NULLIF(p.status,''), 'active') AS status,
               COALESCE(si2.stock_level, p.current_stock, 0) AS stock_level,
               COALESCE(NULLIF(si2.capacity,0), NULLIF(p.capacity,0), NULLIF(p.max_stock_level,0), 480) AS capacity,
               COALESCE(NULLIF(si2.reorder_level,0), NULLIF(p.min_stock_level,0), 24) AS reorder_level,
               COALESCE(NULLIF(si2.critical_level,0), 10)    AS critical_level,
               si2.physical_count,
               si2.variance,
               COALESCE(si2.last_updated, p.updated_at, p.created_at) AS last_updated,
               'Petron Corporation'                           AS supplier
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN station_inventory si2 ON si2.product_id = p.id AND si2.station_id = ?
        WHERE LOWER(COALESCE(pc.name,'')) NOT IN ('fuel','fuel products','services','service')
          AND LOWER(COALESCE(p.status,'active')) NOT IN ('deleted','archived')
          AND p.id NOT IN (SELECT id FROM inventory_products WHERE LOWER(COALESCE(category,'')) NOT IN ('fuel', 'fuel products'))

        ORDER BY category_name, name
    ");
    $stmt->execute([$station_id, $station_id]);
    $all_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error loading merchandise inventory: " . $e->getMessage());
}

// ── Extract brand, barcode, and populate filter options ──────
$last_movements = [];
try {
    $mvStmt = $pdo->prepare("
        SELECT product_id, qty_received AS qty, 'Delivery' AS mtype, encoded_at AS mdate
        FROM merchandise_stock_in WHERE station_id = ? AND product_id IS NOT NULL
    ");
    $mvStmt->execute([$station_id]);
    foreach ($mvStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($last_movements[$pid]) || $r['mdate'] > $last_movements[$pid]['date']) {
            $last_movements[$pid] = ['qty' => (int)$r['qty'], 'type' => $r['mtype'], 'sign' => '+', 'date' => $r['mdate']];
        }
    }
} catch (Exception $e) {}
try {
    $slStmt = $pdo->prepare("
        SELECT ti.product_id, SUM(ti.quantity) AS qty, MAX(t.created_at) AS mdate
        FROM merchandise_transaction_items ti
        JOIN merchandise_transactions t ON t.id = ti.transaction_id
        WHERE t.station_id = ? AND ti.product_id IS NOT NULL
        GROUP BY ti.product_id
    ");
    $slStmt->execute([$station_id]);
    foreach ($slStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($last_movements[$pid]) || $r['mdate'] > $last_movements[$pid]['date']) {
            $last_movements[$pid] = ['qty' => (int)$r['qty'], 'type' => 'Sales', 'sign' => '-', 'date' => $r['mdate']];
        }
    }
} catch (Exception $e) {}

$all_brands = [];
$all_suppliers = ['Petron Corporation'];
$all_units = [];
$all_categories = [];
foreach ($all_items as &$item) {
    $item['category_name'] = format_product_category_display(
        $item['category_name'] ?? '',
        $item['name'] ?? '',
        $item['description'] ?? ''
    );
    if (!empty($item['category_name']) && !in_array($item['category_name'], $all_categories)) {
        $all_categories[] = $item['category_name'];
    }
    $item['brand'] = get_product_brand($item['name'] ?? '', $item['category_name'] ?? '', $item['description'] ?? '');
    $item['barcode'] = get_product_barcode($item['sku']);
    $item['supplier'] = 'Petron Corporation';
    
    if (!in_array($item['brand'], $all_brands)) $all_brands[] = $item['brand'];
    
    $u = format_product_unit_display($item['unit'], $item['name'] ?? '', $item['category_name'] ?? '', $item['description'] ?? '');
    $item['unit'] = $u;
    if (!empty($u) && !in_array($u, $all_units)) $all_units[] = $u;
}
unset($item);
sort($all_brands);
sort($all_suppliers);
sort($all_units);
sort($all_categories);

// ── Compute KPI summary metrics & filter list in PHP ──────────
$kpi_total_products = 0;
$kpi_total_stock    = 0;
$kpi_low_stock      = 0;
$kpi_critical_stock = 0;
$kpi_out_of_stock   = 0;
$kpi_total_value    = 0;

$filtered_items = [];

foreach ($all_items as $item) {
    $stock     = (float)$item['stock_level'];
    $capacity  = (float)$item['capacity'];
    $reorder   = (float)$item['reorder_level'];
    $critical  = (float)$item['critical_level'];
    $price     = (float)$item['price'];
    $variance  = (float)$item['variance'];
    $has_variance = ($item['variance'] !== null && (float)$item['variance'] != 0);
    $item_status = strtolower(trim($item['status'] ?? 'active'));
    
    // Status computation — driven entirely by DB thresholds
    if ($stock <= 0) {
        $computed_status = 'out';
    } elseif ($stock <= $critical) {
        $computed_status = 'critical';
    } elseif ($stock <= $reorder) {
        $computed_status = 'low';
    } else {
        $computed_status = 'available';
    }
    
    if ($item_status === 'inactive') {
        $computed_status = 'inactive';
    }

    // Global KPIs (unfiltered)
    $kpi_total_products++;
    $kpi_total_stock += $stock;
    if ($computed_status === 'low') $kpi_low_stock++;
    elseif ($computed_status === 'critical') $kpi_critical_stock++;
    elseif ($computed_status === 'out') $kpi_out_of_stock++;
    $kpi_total_value += ($stock * $price);

    // Apply Filters
    // 1. Search Query
    if ($search_query !== '') {
        $s_lower = trim(strtolower($search_query));
        $status_match = false;
        if (in_array($s_lower, ['low', 'low stock'], true)) {
            $status_match = ($computed_status === 'low');
        } elseif (in_array($s_lower, ['critical', 'critical stock'], true)) {
            $status_match = ($computed_status === 'critical');
        } elseif (in_array($s_lower, ['out', 'out of stock'], true)) {
            $status_match = ($computed_status === 'out');
        } elseif (in_array($s_lower, ['variance', 'variance detected'], true)) {
            $status_match = $has_variance;
        } elseif ($s_lower === 'available') {
            $status_match = ($computed_status === 'available' && !$has_variance);
        }
        $name_match = (strpos(strtolower($item['name'] ?? ''), $s_lower) !== false);
        $sku_match  = (strpos(strtolower($item['sku'] ?? ''), $s_lower) !== false);
        $cat_match  = (strpos(strtolower($item['category_name'] ?? ''), $s_lower) !== false);
        $sup_match  = (strpos(strtolower($item['supplier'] ?? ''), $s_lower) !== false);
        $brand_match = (strpos(strtolower($item['brand'] ?? ''), $s_lower) !== false);
        if (!$name_match && !$sku_match && !$cat_match && !$sup_match && !$brand_match && !$status_match) {
            continue;
        }
    }

    // 2. Category Filter
    if ($category_filter !== 'all' && $category_filter !== '') {
        if ($item['category_name'] !== $category_filter) {
            continue;
        }
    }

    // 3. Brand Filter
    if ($brand_filter !== 'all' && $brand_filter !== '') {
        if ($item['brand'] !== $brand_filter) {
            continue;
        }
    }

    // 4. Supplier Filter
    if ($supplier_filter !== 'all' && $supplier_filter !== '') {
        if ($item['supplier'] !== $supplier_filter) {
            continue;
        }
    }

    // 5. Unit Filter
    if ($unit_filter !== 'all' && $unit_filter !== '') {
        if ($item['unit'] !== $unit_filter) {
            continue;
        }
    }

    // 6. Status Filter — Low Stock, Critical Stock, Out of Stock all show the same combined stock alert view
    if ($status_filter !== 'all' && $status_filter !== '') {
        $sf_lower = strtolower($status_filter);
        if (in_array($sf_lower, ['warning', 'low', 'low stock', 'critical', 'critical stock', 'out', 'out of stock'], true)) {
            // Any stock-alert filter shows ALL low + critical + out of stock items together
            if (!in_array($computed_status, ['low', 'critical', 'out'], true)) {
                continue;
            }
        } elseif (in_array($sf_lower, ['variance', 'variance detected'], true)) {
            if (!$has_variance) {
                continue;
            }
        } elseif ($sf_lower === 'available') {
            if ($computed_status !== 'available' || $has_variance) {
                continue;
            }
        } elseif ($sf_lower === 'inactive') {
            if ($computed_status !== 'inactive') {
                continue;
            }
        } else {
            if ($computed_status !== $sf_lower) {
                continue;
            }
        }
    }

    // 7. Date range filter
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

// Group filtered items by category for grouped rendering
$sorted_filtered = [];
if (!empty($filtered_items)) {
    $grouped_filtered_items = [];
    foreach ($filtered_items as $item) {
        $cat = $item['category_name'] ?: 'Uncategorized';
        $grouped_filtered_items[$cat][] = $item;
    }
    $cat_order = ['Oils/Lubes/Grease', 'Filters', 'VIC Filters', 'Drinks/Food', 'Snacks', 'Car Accessories', 'Merchandise', 'Others'];
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
    margin-top: 0 !important;
    padding-top: 16px;
    padding-bottom: 16px;
    border-bottom: 2px solid #e9ecef;
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

.afto-card.blue .afto-card-icon { color: #2563eb; }
.afto-card.green .afto-card-icon { color: #16a34a; }
.afto-card.yellow .afto-card-icon { color: #d97706; }
.afto-card.red .afto-card-icon { color: #dc2626; }
.afto-card.purple .afto-card-icon { color: #7c3aed; }
.afto-card.orange .afto-card-icon { color: #ea580c; }

/* == FILTER BAR == */
.afto-filter {
    display: grid;
    grid-template-columns: 1.6fr 1fr 1fr 1fr 0.8fr 1fr 0.9fr 0.9fr auto;
    align-items: flex-end;
    gap: 6px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.afto-fg {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.afto-fg label {
    font-size: 10px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    white-space: nowrap;
}
.afto-fg input, .afto-fg select {
    height: 36px;
    padding: 6px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    color: #1e293b;
    background: #ffffff;
    outline: none;
    box-sizing: border-box;
    width: 100%;
}
.afto-fg input:focus, .afto-fg select:focus {
    border-color: var(--petron-blue, #00264D);
    box-shadow: 0 0 0 3px rgba(0, 38, 77, 0.1);
}
.afto-actions {
    display: flex;
    align-items: flex-end;
    gap: 4px;
    white-space: nowrap;
}
.afto-actions .flt-btn {
    height: 30px;
    padding: 0 10px;
    font-size: 11px;
}

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

/* ── Admin Custom Dropdown (always opens downward) ── */
.adm-cdd-wrap{position:relative;display:block;}
.adm-cdd-trigger{display:flex;align-items:center;gap:6px;height:30px;padding:0 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#374151;background:#fff;cursor:pointer;user-select:none;white-space:nowrap;width:100%;box-sizing:border-box;}
.adm-cdd-trigger:hover{border-color:#94a3b8;}
.adm-cdd-wrap.adm-cdd-open .adm-cdd-trigger{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.adm-cdd-arrow{font-size:9px;color:#94a3b8;margin-left:auto;transition:transform .15s;flex-shrink:0;}
.adm-cdd-wrap.adm-cdd-open .adm-cdd-arrow{transform:rotate(180deg);}
.adm-cdd-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;}
.adm-cdd-menu{display:none;position:absolute;top:calc(100% + 3px);left:0;min-width:100%;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.13);z-index:9999;max-height:260px;overflow-y:auto;}
.adm-cdd-wrap.adm-cdd-open .adm-cdd-menu{display:block;}
.adm-cdd-item{padding:9px 14px;font-size:13px;color:#374151;cursor:pointer;white-space:nowrap;}
.adm-cdd-item:hover{background:#f1f5f9;}
.adm-cdd-item.adm-cdd-active{font-weight:700;color:#fff;background:#1a6fd4;}
.adm-cdd-wrap{display:none!important;}
.afto-filter{overflow:visible;}
.fd-select-source{display:none!important;}
.fd-select{position:relative;display:inline-block;min-width:130px;}
.afto-filter .fd-select{width:100%;}
.fd-select-trigger{display:flex;align-items:center;gap:8px;width:100%;height:36px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#1e293b;font-size:13px;font-family:inherit;cursor:pointer;box-sizing:border-box;white-space:nowrap;}
.fd-select-trigger:hover{border-color:#94a3b8;}
.fd-select.fd-open .fd-select-trigger{border-color:var(--petron-blue, #00264D);box-shadow:0 0 0 3px rgba(0,38,77,.1);}
.fd-select-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;text-align:left;}
.fd-select-arrow{font-size:10px;color:#94a3b8;margin-left:auto;transition:transform .15s;flex-shrink:0;}
.fd-select.fd-open .fd-select-arrow{transform:rotate(180deg);}
.fd-select-menu{display:none;position:absolute;top:calc(100% + 4px);left:0;min-width:100%;max-height:280px;overflow-y:auto;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 8px 24px rgba(15,23,42,.16);z-index:10000;}
.fd-select.fd-open .fd-select-menu{display:block;}
.fd-select-option{padding:9px 14px;font-size:13px;color:#1e293b;cursor:pointer;white-space:nowrap;}
.fd-select-option:hover{background:#f1f5f9;}
.fd-select-option.fd-active{font-weight:700;color:#fff;background:#1a6fd4;}

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
.txn-btn-info { color: #0284c7 !important; border-color: #0284c7 !important; }
.txn-btn-info:hover { background: #0284c7 !important; color: #fff !important; }
.btn-cancel {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 0 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1px solid #6b7280; background: white !important;
    color: #475569 !important; transition: all .15s; height: 36px;
}
.btn-cancel:hover { background: #6b7280 !important; color: #fff !important; }

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
    min-width: 980px;
    border-collapse: collapse;
    font-size: 10px;
    text-align: left;
    table-layout: fixed;
}
.afto-tbl thead tr {
    background: #002F70;
}
.afto-tbl thead th {
    padding: 7px 4px;
    font-weight: 700;
    color: #ffffff;
    text-transform: uppercase;
    letter-spacing: 0.2px;
    font-size: 9px;
    border-bottom: 2px solid #001a3d;
    white-space: nowrap;
}
.afto-tbl tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.1s ease;
}
.afto-tbl tbody tr:hover td {
    background: #f8fafc;
}
.afto-tbl tbody td {
    padding: 6px 4px;
    color: #334155;
    vertical-align: middle;
    white-space: nowrap;
    font-size: 10px;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.afto-tbl tbody td:last-child, .afto-tbl thead th:last-child {
    max-width: none !important;
    overflow: visible !important;
    text-overflow: clip !important;
    white-space: nowrap !important;
}

.align-right { text-align: right; font-family: monospace; }
.align-center { text-align: center; }

.fill-bar-wrap { background:#e9ecef; border-radius:3px; height:5px; overflow:hidden; margin-bottom:2px; width:100%; }
.fill-bar-inner { height:100%; border-radius:3px; }
.mv-pos { color:#16a34a; font-weight:700; }
.mv-neg { color:#dc2626; font-weight:700; }
.mv-none { color:#94a3b8; }

.var-pos { color: #16a34a !important; font-weight: 700; }
.var-neg { color: #dc2626 !important; font-weight: 700; }
.var-zero { color: #64748b !important; font-weight: 600; }

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
    width: 650px;
    max-width: 95vw;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}
.po-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.po-table th {
    background: #f1f5f9;
    color: #475569;
    text-transform: uppercase;
    font-size: 10px;
    font-weight: 700;
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}
.po-table td {
    padding: 8px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
}

/* ── Tab Navigation ── */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:22px; }
.tab-btn { padding:10px 24px; background:none; border:none; border-bottom:3px solid transparent; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70 !important; background:#f8fafc !important; }
</style>

<!-- Page Header -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-boxes"></i> Merchandise Inventory Management</h1>
    </div>
</div>

<!-- Summary Cards -->
<div class="afto-cards">
    <!-- Card 1: Total Merchandise Products -->
    <div class="afto-card blue <?= ($status_filter === 'all' || $status_filter === '') ? 'card-active' : '' ?>" onclick="filterAdminByCard('all')" style="cursor:pointer;" title="Click to view All Products">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Merchandise Products</span>
            <span class="afto-card-val"><?= number_format($kpi_total_products) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-box"></i></div>
    </div>
    <!-- Card 2: Total Stock Quantity -->
    <div class="afto-card green">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Stock Quantity</span>
            <span class="afto-card-val"><?= number_format($kpi_total_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-layer-group"></i></div>
    </div>
    <!-- Card 3: Total Merchandise Inventory Value -->
    <div class="afto-card purple">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Merchandise Inventory Value</span>
            <span class="afto-card-val">₱<?= number_format($kpi_total_value, 2) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-coins"></i></div>
    </div>
    <!-- Card 4: Low Stock Items -->
    <div class="afto-card yellow <?= in_array($status_filter, ['low', 'low stock'], true) ? 'card-active' : '' ?>" onclick="filterAdminByCard('low')" style="cursor:pointer;" title="Click to filter low stock items">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Low Stock Items</span>
            <span class="afto-card-val"><?= number_format($kpi_low_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <!-- Card 5: Critical Stock Items -->
    <div class="afto-card red <?= in_array($status_filter, ['critical', 'critical stock'], true) ? 'card-active' : '' ?>" onclick="filterAdminByCard('critical')" style="cursor:pointer; border-bottom: 2px solid #dc2626;" title="Click to filter critical stock items">
        <div class="afto-card-info">
            <span class="afto-card-lbl" style="color:#dc2626;">Critical Stock Items</span>
            <span class="afto-card-val" style="color:#dc2626;"><?= number_format($kpi_critical_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-fire" style="color:#dc2626;"></i></div>
    </div>
    <!-- Card 6: Out of Stock Items -->
    <div class="afto-card red <?= in_array($status_filter, ['out', 'out of stock'], true) ? 'card-active' : '' ?>" onclick="filterAdminByCard('out')" style="cursor:pointer;" title="Click to filter out of stock items">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Out of Stock Items</span>
            <span class="afto-card-val"><?= number_format($kpi_out_of_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-times-circle"></i></div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" action="admin_inventory_merchandise.php" class="afto-filter">
    <div class="afto-fg">
        <label for="search_query">Search Product</label>
        <input type="text" name="search_query" id="search_query" placeholder="Search SKU, name, brand..." value="<?= htmlspecialchars($search_query) ?>">
    </div>
    
    <!-- Hidden inputs for form submission -->
    <input type="hidden" name="category" id="category" value="<?= htmlspecialchars($category_filter) ?>">
    <input type="hidden" name="brand" id="brand" value="<?= htmlspecialchars($brand_filter) ?>">
    <input type="hidden" name="supplier" id="supplier" value="<?= htmlspecialchars($supplier_filter) ?>">
    <input type="hidden" name="unit" id="unit" value="<?= htmlspecialchars($unit_filter) ?>">
    <input type="hidden" name="status_filter" id="status_filter" value="<?= htmlspecialchars($status_filter) ?>">

    <div class="afto-fg">
        <label>Category</label>
        <div class="adm-cdd-wrap" id="acdd-category">
            <div class="adm-cdd-trigger" onclick="acddToggle('acdd-category')">
                <span class="adm-cdd-label"><?= $category_filter === 'all' || $category_filter === '' ? 'All Categories' : htmlspecialchars($category_filter) ?></span>
                <i class="fas fa-chevron-down adm-cdd-arrow"></i>
            </div>
            <div class="adm-cdd-menu">
                <div class="adm-cdd-item <?= ($category_filter === 'all' || $category_filter === '') ? 'adm-cdd-active' : '' ?>" data-val="all">All Categories</div>
                <?php foreach ($all_categories as $cat): ?>
                <div class="adm-cdd-item <?= $category_filter === $cat ? 'adm-cdd-active' : '' ?>" data-val="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="afto-fg">
        <label>Brand</label>
        <div class="adm-cdd-wrap" id="acdd-brand">
            <div class="adm-cdd-trigger" onclick="acddToggle('acdd-brand')">
                <span class="adm-cdd-label"><?= $brand_filter === 'all' || $brand_filter === '' ? 'All Brands' : htmlspecialchars($brand_filter) ?></span>
                <i class="fas fa-chevron-down adm-cdd-arrow"></i>
            </div>
            <div class="adm-cdd-menu">
                <div class="adm-cdd-item <?= ($brand_filter === 'all' || $brand_filter === '') ? 'adm-cdd-active' : '' ?>" data-val="all">All Brands</div>
                <?php foreach ($all_brands as $b): ?>
                <div class="adm-cdd-item <?= $brand_filter === $b ? 'adm-cdd-active' : '' ?>" data-val="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="afto-fg">
        <label>Supplier</label>
        <div class="adm-cdd-wrap" id="acdd-supplier">
            <div class="adm-cdd-trigger" onclick="acddToggle('acdd-supplier')">
                <span class="adm-cdd-label"><?= $supplier_filter === 'all' || $supplier_filter === '' ? 'All Suppliers' : htmlspecialchars($supplier_filter) ?></span>
                <i class="fas fa-chevron-down adm-cdd-arrow"></i>
            </div>
            <div class="adm-cdd-menu">
                <div class="adm-cdd-item <?= ($supplier_filter === 'all' || $supplier_filter === '') ? 'adm-cdd-active' : '' ?>" data-val="all">All Suppliers</div>
                <?php foreach ($all_suppliers as $s): ?>
                <div class="adm-cdd-item <?= $supplier_filter === $s ? 'adm-cdd-active' : '' ?>" data-val="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="afto-fg">
        <label>UOM</label>
        <div class="adm-cdd-wrap" id="acdd-unit">
            <div class="adm-cdd-trigger" onclick="acddToggle('acdd-unit')">
                <span class="adm-cdd-label"><?= $unit_filter === 'all' || $unit_filter === '' ? 'All UOMs' : htmlspecialchars($unit_filter) ?></span>
                <i class="fas fa-chevron-down adm-cdd-arrow"></i>
            </div>
            <div class="adm-cdd-menu">
                <div class="adm-cdd-item <?= ($unit_filter === 'all' || $unit_filter === '') ? 'adm-cdd-active' : '' ?>" data-val="all">All UOMs</div>
                <?php foreach ($all_units as $u): ?>
                <div class="adm-cdd-item <?= $unit_filter === $u ? 'adm-cdd-active' : '' ?>" data-val="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="afto-fg">
        <label>Status</label>
        <div class="adm-cdd-wrap" id="acdd-status">
            <div class="adm-cdd-trigger" onclick="acddToggle('acdd-status')">
                <?php
                $status_labels = ['all'=>'All Statuses','available'=>'Available','low'=>'Low Stock','critical'=>'Critical Stock','out'=>'Out of Stock','out of stock'=>'Out of Stock','variance detected'=>'Variance Detected','warning'=>'Stock Alerts','inactive'=>'Inactive'];
                $status_display = $status_labels[$status_filter] ?? 'All Statuses';
                ?>
                <span class="adm-cdd-label"><?= htmlspecialchars($status_display) ?></span>
                <i class="fas fa-chevron-down adm-cdd-arrow"></i>
            </div>
            <div class="adm-cdd-menu">
                <div class="adm-cdd-item <?= ($status_filter === 'all' || $status_filter === '') ? 'adm-cdd-active' : '' ?>" data-val="all">All Statuses</div>
                <div class="adm-cdd-item <?= $status_filter === 'available' ? 'adm-cdd-active' : '' ?>" data-val="available">Available</div>
                <div class="adm-cdd-item <?= $status_filter === 'low' ? 'adm-cdd-active' : '' ?>" data-val="low">Low Stock</div>
                <div class="adm-cdd-item <?= $status_filter === 'critical' ? 'adm-cdd-active' : '' ?>" data-val="critical">Critical Stock</div>
                <div class="adm-cdd-item <?= in_array($status_filter, ['out','out of stock'], true) ? 'adm-cdd-active' : '' ?>" data-val="out">Out of Stock</div>
                <div class="adm-cdd-item <?= in_array($status_filter, ['variance','variance detected'], true) ? 'adm-cdd-active' : '' ?>" data-val="variance detected">Variance Detected</div>
                <div class="adm-cdd-item <?= $status_filter === 'inactive' ? 'adm-cdd-active' : '' ?>" data-val="inactive">Inactive</div>
            </div>
        </div>
    </div>

    <div class="afto-fg">
        <label for="date_from">Updated From</label>
        <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>" style="font-size: 11px;">
    </div>

    <div class="afto-fg">
        <label for="date_to">Updated To</label>
        <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>" style="font-size: 11px;">
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
    <div class="table-wrap" style="overflow-x:auto; width:100%; -webkit-overflow-scrolling:touch;">
        <table class="afto-tbl" id="adminMerchTable">
            <colgroup>
                <col style="width:80px">   <!-- SKU -->
                <col style="width:160px">  <!-- Product Name -->
                <col style="width:95px">   <!-- Category -->
                <col style="width:50px">   <!-- UOM -->
                <col style="width:50px">   <!-- Cap -->
                <col style="width:115px">  <!-- Stock/Reorder -->
                <col style="width:50px">   <!-- Phys -->
                <col style="width:55px">   <!-- Variance -->
                <col style="width:100px">  <!-- Status -->
                <col style="width:70px">   <!-- Last Mov -->
                <col style="width:90px">   <!-- Last Updated -->
            </colgroup>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th style="text-align:center;">Category</th>
                    <th style="text-align:center;">UOM</th>
                    <th style="text-align:center;">Cap.</th>
                    <th>Stock / Reorder</th>
                    <th style="text-align:right;">Phys.</th>
                    <th style="text-align:right;">Variance</th>
                    <th class="align-center">Status</th>
                    <th style="text-align:center;">Last Mov.</th>
                    <th>Last Updated</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($sorted_filtered)): ?>
                <tr>
                    <td colspan="11" class="align-center" style="padding: 24px; color: #64748b;">
                        <i class="fas fa-box-open" style="font-size: 24px; margin-bottom: 8px; display:block;"></i>
                        No merchandise inventory records matched your filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($sorted_filtered as $cat_label => $items): ?>
                    <tr class="cat-header">
                        <td colspan="11" style="text-align:center; font-weight:700; background:#e9ecef !important; color:#495057 !important; text-transform:uppercase; font-size:12px; letter-spacing:.5px; border-bottom:2px solid #dee2e6; padding:8px 12px;">
                            <strong><?= htmlspecialchars($cat_label) ?></strong>
                        </td>
                    </tr>
                    <?php foreach ($items as $item): 
                        $stock    = (float)$item['stock_level'];
                        $reorder  = (float)$item['reorder_level'];
                        $price    = (float)$item['price'];
                        $cost     = (float)$item['cost'];
                        $value    = $stock * $price;
                        $updated  = $item['last_updated'] ? date('M d, Y h:i A', strtotime($item['last_updated'])) : '—';
                    ?>
                    <?php
                        $capacity = (float)$item['capacity'];
                        $fill_pct = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
                        $variance = $item['variance'];
                        $has_variance = ($variance !== null && (float)$variance != 0);
                        $badgeCls = $has_variance ? 'bg-amber' : getStatusBadgeClass($item['computed_status']);
                        $badgeLbl = $has_variance ? 'Variance Detected' : getStatusLabel($item['computed_status']);
                        $status_color = $has_variance ? '#fd7e14' : ($item['computed_status'] === 'available' ? '#28a745' : (in_array($item['computed_status'], ['critical', 'out']) ? '#dc3545' : '#fd7e14'));
                        $updated = $item['last_updated'] ? date('M d h:i A', strtotime($item['last_updated'])) : '-';
                        $phys_text = $item['physical_count'] !== null ? number_format((float)$item['physical_count'], 0) : '-';
                        $var_text = '-';
                        $var_style = 'color:#64748b;';
                        if ($variance !== null) {
                            $v_val = (float)$variance;
                            if ($v_val > 0) {
                                $var_text = '+' . number_format($v_val, 0);
                                $var_style = 'color:#28a745;font-weight:700;';
                            } elseif ($v_val < 0) {
                                $var_text = number_format($v_val, 0);
                                $var_style = 'color:#dc3545;font-weight:700;';
                            } else {
                                $var_text = '0';
                                $var_style = 'color:#64748b;font-weight:600;';
                            }
                        }
                        $mv = $last_movements[(int)$item['id']] ?? null;
                        $mv_label = $mv ? ($mv['sign'] . $mv['qty'] . ' ' . $mv['type']) : '';
                        $mv_class = $mv ? ($mv['sign'] === '+' ? 'mv-pos' : ($mv['sign'] === '-' ? 'mv-neg' : 'mv-none')) : 'mv-none';
                    ?>
                    <tr>
                        <td><code><?= htmlspecialchars($item['sku'] ?: '-') ?></code></td>
                        <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                        <td class="align-center"><?= htmlspecialchars($item['category_name']) ?></td>
                        <td class="align-center" style="font-weight:600;color:#475569;"><?= htmlspecialchars($item['unit']) ?></td>
                        <td class="align-center" style="font-weight:600;color:#334155;"><?= number_format($capacity, 0) ?></td>
                        <td>
                            <div class="fill-bar-wrap">
                                <div class="fill-bar-inner" style="width:<?= min(100, round($fill_pct)) ?>%;background:<?= $status_color ?>;"></div>
                            </div>
                            <span style="font-size:10px;font-weight:600;color:#334155;"><?= number_format($stock, 0) ?> <?= htmlspecialchars($item['unit']) ?></span>
                            <span style="font-size:9px;color:#94a3b8;">/ <?= number_format($reorder, 0) ?></span>
                        </td>
                        <td class="align-right" style="font-weight:700;color:#0f172a;"><?= $phys_text ?></td>
                        <td class="align-right" style="<?= $var_style ?>"><?= $var_text ?></td>
                        <td class="align-center"><span class="badge-lbl <?= $badgeCls ?>"><?= htmlspecialchars($badgeLbl) ?></span></td>
                        <td class="align-center">
                            <?php if ($mv_label): ?>
                                <span class="<?= $mv_class ?>" style="font-size:11px;"><?= htmlspecialchars($mv_label) ?></span>
                            <?php else: ?>
                                <span class="mv-none" style="font-size:11px;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px; color:#64748b;"><?= $updated ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="adminMerchPagination" style="margin: 10px 20px;"></div>
</div>

<!-- Details Modal -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box" style="width: 650px; max-width: 95vw;">
        <div style="background:#00264D; color:#ffffff !important; padding:16px 20px; border-radius:12px 12px 0 0; margin:-24px -24px 20px -24px; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; color:#ffffff !important; font-size:15px; font-weight:700; text-transform:uppercase; letter-spacing:.5px;"><i class="fas fa-eye"></i> Product Details</h3>
        </div>
        <div class="modal-body" style="padding:0; overflow-y:auto; max-height: 70vh;">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <!-- Product Information -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px;">
                    <h4 style="margin:0 0 8px; color:#00264D; font-size:12px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px;"><i class="fas fa-info-circle"></i> Product Information</h4>
                    <table style="width:100%; font-size:12px; line-height:1.6;">
                        <tr><td style="font-weight:bold; color:#64748b; width:45%;">Product Code:</td><td id="detSku" style="font-weight:700;"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Product Name:</td><td id="detName" style="font-weight:700;"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Category:</td><td id="detCategory"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Brand:</td><td id="detBrand"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Supplier:</td><td id="detSupplier"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Barcode:</td><td id="detBarcode"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Unit of Measure:</td><td id="detUnit"></td></tr>
                    </table>
                </div>

                <!-- Inventory Information & Pricing -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px;">
                    <h4 style="margin:0 0 8px; color:#00264D; font-size:12px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px;"><i class="fas fa-boxes"></i> Inventory &amp; Pricing</h4>
                    <table style="width:100%; font-size:12px; line-height:1.6;">
                        <tr><td style="font-weight:bold; color:#64748b; width:45%;">Current Stock:</td><td id="detStock" style="font-weight:700; color:#002F70;"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Reorder Level:</td><td id="detReorder"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Critical Level:</td><td id="detCritical"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Unit Cost:</td><td id="detCost"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Selling Price:</td><td id="detPrice" style="color:#16a34a; font-weight:700;"></td></tr>
                        <tr><td style="font-weight:bold; color:#64748b;">Inventory Value:</td><td id="detValue" style="font-weight:700; color:#002F70;"></td></tr>
                    </table>
                </div>
            </div>

            <!-- Recent Movement Table -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; margin-bottom:10px;">
                <h4 style="margin:0 0 8px; color:#00264D; font-size:12px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px;"><i class="fas fa-history"></i> Recent Movement</h4>
                <div style="max-height: 180px; overflow-y: auto; border: 1px solid #cbd5e1; border-radius: 6px; background:#fff;">
                    <table class="po-table" style="width:100%; margin:0;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reference</th>
                                <th>Transaction</th>
                                <th style="text-align:right;">Qty</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody id="detRecentMovement">
                            <!-- Dynamic rows -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal-actions" style="margin-top:15px; padding-top:12px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('detailsModal').classList.remove('show')"
                    class="btn-cancel" style="height:32px; font-size:12px; padding:0 16px;">Close</button>
        </div>
    </div>
</div>

<script>
// Details modal dynamic content injection
function viewDetails(item) {
    document.getElementById('detSku').textContent = item.sku || '—';
    document.getElementById('detName').textContent = item.name || '—';
    document.getElementById('detCategory').textContent = item.category_name || '—';
    document.getElementById('detBrand').textContent = item.brand || '—';
    document.getElementById('detSupplier').textContent = item.supplier || '—';
    document.getElementById('detBarcode').textContent = item.barcode || '—';
    document.getElementById('detUnit').textContent = item.unit || 'pcs';
    
    var price = parseFloat(item.price);
    document.getElementById('detPrice').innerHTML = '₱' + price.toFixed(2);
    
    var cost = parseFloat(item.cost || 0);
    document.getElementById('detCost').innerHTML = '₱' + cost.toFixed(2);
    
    var stock = parseFloat(item.stock_level);
    document.getElementById('detStock').textContent = stock.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' ' + (item.unit || 'pcs');
    
    var reorder  = parseFloat(item.reorder_level);
    var critical = parseFloat(item.critical_level) || (reorder / 2);
    document.getElementById('detReorder').textContent  = reorder.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' ' + (item.unit || 'pcs');
    document.getElementById('detCritical').textContent = critical.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' ' + (item.unit || 'pcs');
    
    var value = stock * price;
    document.getElementById('detValue').innerHTML = '₱' + value.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    // Fetch and display movement history
    var tbody = document.getElementById('detRecentMovement');
    tbody.innerHTML = '<tr><td colspan="5" class="align-center" style="padding: 10px; text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
    
    fetch('admin_inventory_merchandise.php?action=get_history&product_id=' + item.id)
        .then(response => response.json())
        .then(res => {
            if (res.success) {
                if (res.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="align-center" style="padding: 10px; text-align:center; color: #64748b;">No recent movements.</td></tr>';
                } else {
                    var html = '';
                    res.data.forEach(function(row) {
                        var change = parseFloat(row.quantity_change);
                        var changeStr = (change > 0 ? '+' : '') + change.toLocaleString(undefined, {minimumFractionDigits: 2});
                        var changeClass = change > 0 ? 'var-pos' : (change < 0 ? 'var-neg' : 'var-zero');
                        
                        var date = new Date(row.created_at);
                        var dateStr = date.toLocaleDateString('en-US', { month: 'short', day: '2-digit' }) + ' ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                        
                        html += '<tr>' +
                            '<td>' + dateStr + '</td>' +
                            '<td><code>LOG-' + row.id + '</code></td>' +
                            '<td>' + row.action + '</td>' +
                            '<td style="text-align:right; font-weight:bold;" class="' + changeClass + '">' + changeStr + '</td>' +
                            '<td>' + row.user_fullname + '</td>' +
                            '</tr>';
                    });
                    tbody.innerHTML = html;
                }
            } else {
                tbody.innerHTML = '<tr><td colspan="5" class="align-center" style="padding: 10px; text-align:center; color: #dc2626;">Error loading logs.</td></tr>';
            }
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="5" class="align-center" style="padding: 10px; text-align:center; color: #dc2626;">Error.</td></tr>';
        });

    document.getElementById('detailsModal').classList.add('show');
}

function filterAdminByCard(statusKey) {
    var hidden = document.getElementById('status_filter');
    if (!hidden) return;
    var labels = {'all':'All Statuses','available':'Available','low':'Low Stock','critical':'Critical Stock','out':'Out of Stock','variance detected':'Variance Detected','inactive':'Inactive','warning':'Stock Alerts'};
    if (hidden.value === statusKey) {
        hidden.value = 'all';
        acddSetLabel('acdd-status', 'all', 'All Statuses');
    } else {
        hidden.value = statusKey;
        acddSetLabel('acdd-status', statusKey, labels[statusKey] || statusKey);
    }
    var form = hidden.closest('form');
    if (form) form.submit();
}

// ── Admin Custom Dropdown (CDD) Logic ────────────────────────────
function acddToggle(id) {
    var wrap = document.getElementById(id);
    var isOpen = wrap.classList.contains('adm-cdd-open');
    document.querySelectorAll('.adm-cdd-wrap.adm-cdd-open').forEach(function(w){ w.classList.remove('adm-cdd-open'); });
    if (!isOpen) wrap.classList.add('adm-cdd-open');
}

function acddSetLabel(cddId, val, label) {
    var wrap = document.getElementById(cddId);
    if (!wrap) return;
    wrap.querySelector('.adm-cdd-label').textContent = label;
    wrap.querySelectorAll('.adm-cdd-item').forEach(function(item){
        item.classList.toggle('adm-cdd-active', item.dataset.val === val);
    });
}

function setupDownwardFilterSelects(selects) {
    Array.from(selects || []).forEach(function(select) {
        if (!select || select.dataset.forceDownReady === '1') return;
        select.dataset.forceDownReady = '1';

        var wrap = document.createElement('div');
        wrap.className = 'fd-select';
        var computed = window.getComputedStyle(select);
        if (computed.minWidth && computed.minWidth !== '0px') wrap.style.minWidth = computed.minWidth;
        if (select.style.width) wrap.style.width = select.style.width;
        if (select.closest('.afto-fg')) wrap.style.width = '100%';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'fd-select-trigger';
        var label = document.createElement('span');
        label.className = 'fd-select-label';
        var arrow = document.createElement('i');
        arrow.className = 'fas fa-chevron-down fd-select-arrow';
        trigger.appendChild(label);
        trigger.appendChild(arrow);

        var menu = document.createElement('div');
        menu.className = 'fd-select-menu';
        Array.from(select.options).forEach(function(option) {
            var item = document.createElement('div');
            item.className = 'fd-select-option';
            item.dataset.value = option.value;
            item.textContent = option.textContent;
            item.addEventListener('click', function() {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                syncLabel();
                wrap.classList.remove('fd-open');
            });
            menu.appendChild(item);
        });

        function syncLabel() {
            var selected = select.options[select.selectedIndex];
            label.textContent = selected ? selected.textContent.trim() : '';
            Array.from(menu.querySelectorAll('.fd-select-option')).forEach(function(item) {
                item.classList.toggle('fd-active', item.dataset.value === select.value);
            });
        }

        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            document.querySelectorAll('.fd-select.fd-open').forEach(function(openWrap) {
                if (openWrap !== wrap) openWrap.classList.remove('fd-open');
            });
            wrap.classList.toggle('fd-open');
        });

        select.addEventListener('change', syncLabel);
        select.classList.add('fd-select-source');
        select.parentNode.insertBefore(wrap, select.nextSibling);
        wrap.appendChild(trigger);
        wrap.appendChild(menu);
        syncLabel();
    });

    if (!window.__forceDownSelectCloseBound) {
        window.__forceDownSelectCloseBound = true;
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.fd-select')) {
                document.querySelectorAll('.fd-select.fd-open').forEach(function(wrap) {
                    wrap.classList.remove('fd-open');
                });
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Wire hidden input map: cdd id → hidden input id
    var acddInputMap = {
        'acdd-category': 'category',
        'acdd-brand':    'brand',
        'acdd-supplier': 'supplier',
        'acdd-unit':     'unit',
        'acdd-status':   'status_filter'
    };
    document.querySelectorAll('.adm-cdd-wrap').forEach(function(wrap) {
        var id = wrap.id;
        var inputId = acddInputMap[id];
        var hiddenInput = inputId ? document.getElementById(inputId) : null;
        if (hiddenInput && !document.getElementById(id + '-native')) {
            var nativeSelect = document.createElement('select');
            nativeSelect.id = id + '-native';
            var fieldWrap = wrap.closest('.afto-fg');
            var fieldLabel = fieldWrap ? fieldWrap.querySelector('label') : null;
            nativeSelect.setAttribute('aria-label', fieldLabel ? fieldLabel.textContent : 'Filter');
            wrap.querySelectorAll('.adm-cdd-item').forEach(function(item) {
                var option = document.createElement('option');
                option.value = item.dataset.val || '';
                option.textContent = item.textContent.trim();
                if (item.classList.contains('adm-cdd-active')) option.selected = true;
                nativeSelect.appendChild(option);
            });
            nativeSelect.addEventListener('change', function() {
                hiddenInput.value = nativeSelect.value;
                var selected = nativeSelect.options[nativeSelect.selectedIndex];
                acddSetLabel(id, nativeSelect.value, selected ? selected.textContent : '');
            });
            wrap.parentNode.insertBefore(nativeSelect, wrap.nextSibling);
        }
        wrap.querySelectorAll('.adm-cdd-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var val = item.dataset.val;
                var label = item.textContent.trim();
                acddSetLabel(id, val, label);
                wrap.classList.remove('adm-cdd-open');
                if (inputId) {
                    var hidden = document.getElementById(inputId);
                    if (hidden) hidden.value = val;
                }
            });
        });
    });
    setupDownwardFilterSelects(document.querySelectorAll('.afto-filter select'));

    // Close on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.adm-cdd-wrap')) {
            document.querySelectorAll('.adm-cdd-wrap.adm-cdd-open').forEach(function(w){ w.classList.remove('adm-cdd-open'); });
        }
    });

    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminMerchTable', 'adminMerchRowsLimit', 'adminMerchPagination', 50);
    }
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
