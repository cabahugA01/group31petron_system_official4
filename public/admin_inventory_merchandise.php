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

// â”€â”€ Module gate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// ── Handle Product Information Update (POST) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_product') {
    $prod_id  = (int)($_POST['product_id'] ?? 0);
    $name     = trim($_POST['product_name'] ?? '');
    $reorder  = (float)($_POST['reorder_level'] ?? 24);
    $critical = (float)($_POST['critical_level'] ?? 10);
    $capacity = (float)($_POST['capacity'] ?? 480);
    $price    = (float)($_POST['price'] ?? 0);
    $cost     = (float)($_POST['cost'] ?? 0);
    $unit     = trim($_POST['unit'] ?? 'pcs');

    if ($prod_id > 0 && !empty($name)) {
        try {
            $pdo->beginTransaction();

            // 1. Update station_inventory
            $stmt_si = $pdo->prepare("
                UPDATE station_inventory 
                SET reorder_level = ?, critical_level = ?, capacity = ?, unit = ?, price = ?, cost = ?, last_updated = NOW() 
                WHERE product_id = ? AND (station_id = ? OR station_id = 0 OR station_id IS NULL)
            ");
            $stmt_si->execute([$reorder, $critical, $capacity, $unit, $price, $cost, $prod_id, $station_id]);

            // 2. Update inventory_products
            try {
                $stmt_ip = $pdo->prepare("
                    UPDATE inventory_products 
                    SET product_name = ?, min_stock = ?, max_stock = ?, unit_price = ?, unit_cost = ?, size = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt_ip->execute([$name, $reorder, $capacity, $price, $cost, $unit, $prod_id]);
            } catch (Exception $e_ip) {}

            // 3. Update products table
            try {
                $stmt_p = $pdo->prepare("
                    UPDATE products 
                    SET name = ?, price = ?, cost = ?, min_stock_level = ?, max_stock_level = ?, capacity = ?, unit = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt_p->execute([$name, $price, $cost, $reorder, $capacity, $capacity, $unit, $prod_id]);
            } catch (Exception $e_p) {}

            // 4. Log the action
            try {
                $log_stmt = $pdo->prepare("
                    INSERT INTO inventory_logs (product_id, station_id, user_id, action, quantity_change, previous_stock, new_stock, notes, created_at)
                    VALUES (?, ?, ?, 'PRODUCT_UPDATE', 0, 0, 0, ?, NOW())
                ");
                $log_stmt->execute([$prod_id, $station_id, $me['id'], "Admin updated product settings: {$name}"]);
            } catch (Exception $e_log) {}

            $pdo->commit();
            $_SESSION['success'] = 'Product updated successfully.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error updating product: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = 'Invalid product data.';
    }
    header('Location: admin_inventory_merchandise.php?tab=' . urlencode($_POST['tab'] ?? 'overview'));
    exit;
}

// â”€â”€ Status Badge Styles / Helper Functions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ PRINT FRIENDLY VIEW â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
    $capacity = max(480.0, (float)$item['capacity']);
    $fill_pct = $capacity > 0 ? min(100, ($stock / $capacity) * 100) : 0;
    
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
                Petron Station Management System —¢ Official Inventory Slip
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// â”€â”€ GET MOVEMENT HISTORY (AJAX ENDPOINT) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ GET PRODUCT DETAILS & FIFO BATCHES (AJAX ENDPOINT) â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_product_details') {
    header('Content-Type: application/json');
    $prod_id = (int)($_GET['product_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT msi.*, COALESCE(u.name, 'Staff') AS user_name
            FROM merchandise_stock_in msi
            LEFT JOIN users u ON msi.encoded_by = u.id
            WHERE msi.product_id = ? AND msi.station_id = ?
            ORDER BY msi.encoded_at DESC
            LIMIT 50
        ");
        $stmt->execute([$prod_id, $station_id]);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'deliveries' => $deliveries
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// â”€â”€ GET Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$search_query   = trim($_GET['search_query'] ?? '');
$category_filter = trim($_GET['category'] ?? 'all');
$brand_filter    = trim($_GET['brand'] ?? 'all');
$supplier_filter = trim($_GET['supplier'] ?? 'all');
$unit_filter     = trim($_GET['unit'] ?? 'all');
$status_filter   = strtolower(trim($_GET['status_filter'] ?? 'all'));
$date_from      = trim($_GET['date_from'] ?? '');
$date_to        = trim($_GET['date_to'] ?? '');

// â”€â”€ Active Tab â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$active_tab = trim($_GET['tab'] ?? 'overview');
if (!in_array($active_tab, ['overview', 'movement', 'stockin', 'stockout', 'transfer', 'damaged', 'expired'])) $active_tab = 'overview';

// â”€â”€ Fetch dynamic categories for dropdown â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ Fetch all merchandise items â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
               ON si.product_id = ip.id AND (si.station_id = ? OR si.station_id = 0 OR si.station_id IS NULL)
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
        LEFT JOIN station_inventory si2 ON si2.product_id = p.id AND (si2.station_id = ? OR si2.station_id = 0 OR si2.station_id IS NULL)
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

// ── Stock In map: total received per product (by ID & Name) ─────────────────
$prod_added_map_id   = [];
$prod_added_map_name = [];
try {
    $siStmt = $pdo->prepare("
        SELECT 
            product_id, 
            LOWER(TRIM(product_name)) AS pname, 
            COALESCE(SUM(qty_received), 0) AS total_added
        FROM merchandise_stock_in
        WHERE (station_id = ? OR station_id = 1253 OR station_id = 0 OR station_id IS NULL OR station_id > 0)
        GROUP BY product_id, LOWER(TRIM(product_name))
    ");
    $siStmt->execute([$station_id]);
    foreach ($siStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['total_added'];
        if (!empty($r['product_id']) && (int)$r['product_id'] > 0) {
            $prod_added_map_id[(int)$r['product_id']] = ($prod_added_map_id[(int)$r['product_id']] ?? 0) + $qty;
        }
        if (!empty($r['pname'])) {
            $prod_added_map_name[$r['pname']] = ($prod_added_map_name[$r['pname']] ?? 0) + $qty;
        }
    }
} catch (Exception $e) {}

// ── Stock Out map: total sold/deducted per product (by ID & Name) ────────────
$prod_deducted_map_id   = [];
$prod_deducted_map_name = [];
try {
    $soStmt = $pdo->prepare("
        SELECT 
            ti.product_id, 
            LOWER(TRIM(ti.product_name)) AS pname, 
            COALESCE(SUM(ti.quantity), 0) AS total_deducted
        FROM merchandise_transaction_items ti
        JOIN merchandise_transactions t ON t.id = ti.transaction_id
        WHERE (t.station_id = ? OR t.station_id = 1253 OR t.station_id = 0 OR t.station_id IS NULL OR t.station_id > 0)
          AND LOWER(t.workflow_status) NOT IN ('voided','void','cancelled')
        GROUP BY ti.product_id, LOWER(TRIM(ti.product_name))
    ");
    $soStmt->execute([$station_id]);
    foreach ($soStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['total_deducted'];
        if (!empty($r['product_id']) && (int)$r['product_id'] > 0) {
            $prod_deducted_map_id[(int)$r['product_id']] = ($prod_deducted_map_id[(int)$r['product_id']] ?? 0) + $qty;
        }
        if (!empty($r['pname'])) {
            $prod_deducted_map_name[$r['pname']] = ($prod_deducted_map_name[$r['pname']] ?? 0) + $qty;
        }
    }
} catch (Exception $e) {}

$all_brands = [];
$all_suppliers = ['Petron Corporation'];
$all_units = [];
$all_categories = [];
foreach ($all_items as &$item) {
    $pid = (int)$item['id'];
    $pname_norm = strtolower(trim((string)($item['name'] ?? '')));
    $item['stock_in']  = (int)($prod_added_map_id[$pid] ?? $prod_added_map_name[$pname_norm] ?? 0);
    $item['stock_out'] = (int)($prod_deducted_map_id[$pid] ?? $prod_deducted_map_name[$pname_norm] ?? 0);

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

// â”€â”€ Compute KPI summary metrics & filter list in PHP â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kpi_total_products = 0;
$kpi_total_stock    = 0;
$kpi_low_stock      = 0;
$kpi_critical_stock = 0;
$kpi_out_of_stock   = 0;
$kpi_total_value    = 0;

$stock_movements_today = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_logs WHERE (station_id = ? OR station_id = 0 OR station_id IS NULL) AND DATE(created_at) = CURDATE()");
    $stmt->execute([$station_id]);
    $stock_movements_today = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$pending_adjustments_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE (station_id = ? OR station_id = 0 OR station_id IS NULL) AND status = 'Pending'");
    $stmt->execute([$station_id]);
    $pending_adjustments_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

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

// â”€â”€ KPI: Available Stock count â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kpi_available_stock = 0;
foreach ($all_items as $item) {
    $s = (float)$item['stock_level'];
    $r = (float)($item['reorder_level'] ?? 24);
    $c = (float)($item['critical_level'] ?? 10);
    if ($s > $r) $kpi_available_stock++;
}

// â”€â”€ Fetch Stock Movement History (Unified across logs, deliveries & sales) â”€â”€
$movement_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT il.id AS log_id,
               il.created_at,
               il.action AS movement_type,
               il.quantity_change AS quantity,
               COALESCE(NULLIF(il.notes,''), '—') AS notes,
               COALESCE(NULLIF(CONCAT_WS('-', il.reference_type, il.reference_id),''), CONCAT('LOG-', LPAD(il.id, 5, '0'))) AS reference_no,
               COALESCE(ip.product_name, p.name, 'Unknown') AS product_name,
               COALESCE(ip.sku, CONCAT('P', LPAD(p.id,4,'0')), '') AS sku,
               COALESCE(NULLIF(si.unit,''), NULLIF(ip.size,''), 'pcs') AS unit,
               COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(u.first_name, ' ', u.last_name),' '), u.username, 'System') AS user_name
        FROM inventory_logs il
        LEFT JOIN inventory_products ip ON ip.id = il.product_id
        LEFT JOIN products p ON p.id = il.product_id AND (ip.id IS NULL)
        LEFT JOIN station_inventory si ON si.product_id = il.product_id AND (si.station_id = il.station_id OR si.station_id = 0 OR si.station_id IS NULL)
        LEFT JOIN users u ON u.id = il.user_id

        UNION ALL

        SELECT (1000000 + msi.id) AS log_id,
               msi.encoded_at AS created_at,
               'Stock In' AS movement_type,
               msi.qty_received AS quantity,
               COALESCE(NULLIF(msi.remarks,''), CONCAT('PO: ', COALESCE(msi.po_number,'—'), ' | Batch: ', COALESCE(msi.batch_ref,'—'))) AS notes,
               CONCAT('SI-', LPAD(msi.id, 5, '0')) AS reference_no,
               COALESCE(ip.product_name, p.name, msi.product_name, 'Unknown') AS product_name,
               COALESCE(ip.sku, msi.sku, CONCAT('P', LPAD(p.id,4,'0')), '') AS sku,
               COALESCE(NULLIF(si.unit,''), NULLIF(ip.size,''), 'pcs') AS unit,
               COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(u.first_name, ' ', u.last_name),' '), 'Staff') AS user_name
        FROM merchandise_stock_in msi
        LEFT JOIN inventory_products ip ON ip.id = msi.product_id
        LEFT JOIN products p ON p.id = msi.product_id AND (ip.id IS NULL)
        LEFT JOIN station_inventory si ON si.product_id = msi.product_id AND (si.station_id = msi.station_id OR si.station_id = 0 OR si.station_id IS NULL)
        LEFT JOIN users u ON u.id = msi.encoded_by
        WHERE msi.id NOT IN (SELECT COALESCE(reference_id, 0) FROM inventory_logs WHERE reference_type LIKE '%delivery%' OR reference_type LIKE '%stock_in%')

        UNION ALL

        SELECT (2000000 + mt.id) AS log_id,
               mt.created_at,
               mt.transaction_type AS movement_type,
               -mti.quantity AS quantity,
               COALESCE(NULLIF(mt.manager_notes,''), NULLIF(mt.staff_remarks,''), 'Sale Transaction') AS notes,
               CONCAT('SO-', LPAD(mt.id, 5, '0')) AS reference_no,
               COALESCE(ip.product_name, p.name, 'Unknown') AS product_name,
               COALESCE(ip.sku, CONCAT('P', LPAD(p.id,4,'0')), '') AS sku,
               COALESCE(NULLIF(si.unit,''), NULLIF(ip.size,''), 'pcs') AS unit,
               COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(u.first_name, ' ', u.last_name),' '), 'Staff') AS user_name
        FROM merchandise_transactions mt
        JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
        LEFT JOIN inventory_products ip ON ip.id = mti.product_id
        LEFT JOIN products p ON p.id = mti.product_id AND (ip.id IS NULL)
        LEFT JOIN station_inventory si ON si.product_id = mti.product_id AND (si.station_id = mt.station_id OR si.station_id = 0 OR si.station_id IS NULL)
        LEFT JOIN users u ON u.id = mt.staff_id
        WHERE mt.id NOT IN (SELECT COALESCE(reference_id, 0) FROM inventory_logs WHERE reference_type LIKE '%transaction%' OR reference_type LIKE '%sale%')

        ORDER BY created_at DESC
        LIMIT 200
    ");
    $stmt->execute();
    $movement_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching movement history: " . $e->getMessage());
}

// â”€â”€ Fetch Stock-In History â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stockin_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT msi.id, msi.encoded_at AS date, msi.qty_received, msi.unit_cost, msi.batch_no,
               msi.po_number, msi.status, msi.notes,
               COALESCE(ip.product_name, p.name, 'Unknown') AS product_name,
               COALESCE(ip.sku, CONCAT('P', LPAD(p.id,4,'0')), '') AS sku,
               COALESCE(ip.category, pc.name, 'General') AS category_name,
               COALESCE(u.name, 'Staff') AS received_by,
               COALESCE(ip.brand, 'Petron Corporation') AS supplier
        FROM merchandise_stock_in msi
        LEFT JOIN inventory_products ip ON ip.id = msi.product_id
        LEFT JOIN products p ON p.id = msi.product_id AND (ip.id IS NULL)
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN users u ON u.id = msi.encoded_by
        WHERE msi.station_id = ?
        ORDER BY msi.encoded_at DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $stockin_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// â”€â”€ Fetch Stock-Out History â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stockout_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            CONCAT('SO-', LPAD(mt.id, 5, '0')) AS ref_no,
            mt.created_at AS date,
            mt.transaction_type,
            COALESCE(ip.product_name, p.name, 'Unknown') AS product_name,
            COALESCE(ip.sku, CONCAT('P', LPAD(p.id,4,'0')), '') AS sku,
            COALESCE(mti.quantity, 0) AS qty
        FROM merchandise_transactions mt
        JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
        LEFT JOIN inventory_products ip ON ip.id = mti.product_id
        LEFT JOIN products p ON p.id = mti.product_id AND (ip.id IS NULL)
        WHERE (mt.station_id = ? OR mt.station_id = 0 OR mt.station_id IS NULL)
          AND mt.transaction_type IN ('sale','stock_out','return','wastage')
        ORDER BY mt.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $stockout_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// â”€â”€ Fetch Transfer Records â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$transfer_records = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            CONCAT('TR-', LPAD(il.id, 5, '0')) AS transfer_no,
            COALESCE(ip.product_name, 'Unknown') AS product_name,
            COALESCE(ip.sku, '') AS sku,
            COALESCE(il.notes, '—') AS from_location,
            COALESCE(NULLIF(CONCAT_WS(' ', il.reference_type, il.reference_id), ''), '—') AS to_location,
            ABS(il.quantity_change) AS qty,
            il.created_at AS date,
            COALESCE(u.name, 'Staff') AS performed_by
        FROM inventory_logs il
        LEFT JOIN inventory_products ip ON ip.id = il.product_id
        LEFT JOIN users u ON u.id = il.user_id
        WHERE (il.station_id = ? OR il.station_id = 0 OR il.station_id IS NULL)
          AND LOWER(il.action) IN ('transfer','transfer_out','transfer_in')
        ORDER BY il.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $transfer_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// â”€â”€ Fetch Damaged Items â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$damaged_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            CONCAT('DMG-', LPAD(il.id, 5, '0')) AS damage_no,
            COALESCE(ip.product_name, 'Unknown') AS product_name,
            COALESCE(ip.sku, '') AS sku,
            ABS(il.quantity_change) AS qty,
            COALESCE(il.notes, '—') AS reason,
            il.created_at AS date,
            COALESCE(u.name, 'Staff') AS performed_by
        FROM inventory_logs il
        LEFT JOIN inventory_products ip ON ip.id = il.product_id
        LEFT JOIN users u ON u.id = il.user_id
        WHERE (il.station_id = ? OR il.station_id = 0 OR il.station_id IS NULL)
          AND LOWER(il.action) IN ('damage','damaged','defective','disposal')
        ORDER BY il.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $damaged_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// â”€â”€ Fetch Expired Products â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$expired_products = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(ip.product_name, 'Unknown') AS product_name,
            COALESCE(ip.sku, '') AS sku,
            COALESCE(mb.batch_number, msi.batch_ref, '—') AS batch,
            mb.created_at AS expiry_date,
            COALESCE(mb.remaining_qty, msi.qty_received, 0) AS qty
        FROM merchandise_batches mb
        LEFT JOIN inventory_products ip ON ip.id = mb.product_id
        LEFT JOIN merchandise_stock_in msi ON msi.product_id = mb.product_id AND msi.station_id = mb.station_id
        WHERE (mb.station_id = ? OR mb.station_id = 0 OR mb.station_id IS NULL)
          AND mb.status = 'active'
        ORDER BY mb.id DESC
        LIMIT 200
    ");
    $stmt->execute([$station_id]);
    $expired_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* == GLOBAL OVERFLOW FIX == */
body, html {
    overflow-x: hidden !important;
    overflow-y: auto !important;
    max-width: 100vw !important;
}
.content-wrapper, .main-content {
    overflow-x: hidden !important;
    overflow-y: visible !important;
    max-width: 100% !important;
    padding: 0 !important;
    box-sizing: border-box;
    width: 100%;
}
.table-wrap {
    overflow-x: auto !important;
    overflow-y: visible !important;
    max-width: 100% !important;
    -webkit-overflow-scrolling: touch;
}

/* == PAGE HEADER - Uniform standard across all modules == */
.int-head {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin-top: 0 !important;
    margin-bottom: 25px !important;
    padding: 0 !important;
    border: none !important;
    width: 100%;
}
.int-head h1 {
    margin: 0 !important;
    color: #002f70 !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    line-height: 1.2 !important;
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
.flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-csv { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-csv:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }

/* â”€â”€ Admin Custom Dropdown (always opens downward) â”€â”€ */
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
    max-width: 100%;
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
    width: 100% !important;
    border-collapse: collapse;
    font-size: 10px;
    text-align: left;
    table-layout: auto;
}
/* ── Tab Navigation - Reports-style boxed design ── */
.tab-nav {
    display: flex !important; flex-wrap: wrap !important;
    margin-bottom: 22px !important;
    border: 1px solid #d1d9e6 !important; border-radius: 0 !important;
    overflow: hidden !important; border-bottom: 3px solid #00264D !important;
    gap: 0 !important; background: transparent !important;
    padding: 0 !important; width: 100% !important;
}
.tab-btn {
    flex: 1 !important; min-width: 140px !important;
    padding: 12px 16px !important; font-size: 11.5px !important; font-weight: 700 !important;
    color: #334155 !important; background: #ffffff !important;
    border: none !important; border-right: 1px solid #d1d9e6 !important;
    border-radius: 0 !important; text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important; align-items: center !important;
    justify-content: center !important; gap: 7px !important;
    text-transform: uppercase !important; letter-spacing: 0.3px !important;
    text-align: center !important; cursor: pointer !important;
    margin-bottom: 0 !important; box-shadow: none !important; white-space: nowrap;
}
.tab-btn:last-child { border-right: none !important; }
.tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.tab-btn.active {
    background: #00264D !important; color: #ffffff !important;
    font-weight: 800 !important; box-shadow: none !important;
    border-bottom-color: transparent !important;
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
    overflow: hidden;
    text-overflow: ellipsis;
}
.afto-tbl tbody td:last-child, .afto-tbl thead th:last-child {
    white-space: normal !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    word-wrap: break-word !important;
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

/* â”€â”€ Tab Navigation â”€â”€ */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:22px; }
.tab-btn { padding:10px 24px; background:none; border:none; border-bottom:3px solid transparent; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70 !important; background:#f8fafc !important; }

/* â”€â”€ Modal tab button overrides to prevent global button background â”€â”€ */
.modal-tab-btn {
    background: none !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 10px 16px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    border-bottom: 2px solid transparent !important;
    transition: color 0.15s, border-color 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.modal-tab-btn.active {
    border-bottom: 2px solid #002F70 !important;
    color: #002F70 !important;
    font-weight: 700 !important;
    background: transparent !important;
    background-color: transparent !important;
}
.modal-tab-btn:not(.active) {
    color: #64748b !important;
    background: transparent !important;
    background-color: transparent !important;
}
.modal-tab-btn:hover:not(.active) {
    color: #002F70 !important;
    border-bottom: 2px solid #c7d4ea !important;
    background: #f1f5f9 !important;
}

/* â”€â”€ Outline buttons (View, Print, Close) plain styles â”€â”€ */
.int-btn-outline {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid #002F70 !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    color: #002F70 !important;
    transition: all 0.2s;
    white-space: nowrap;
    text-decoration: none;
    box-shadow: none !important;
}
.int-btn-outline:hover {
    background: #002F70 !important;
    color: #ffffff !important;
}
</style>

<div class="main-content">
<!-- Page Header -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-boxes"></i> Merchandise Inventory Management</h1>
    </div>
</div>

<!-- Summary Cards -->
<div class="afto-cards">
    <!-- Card 1: Total Products -->
    <div class="afto-card blue <?= ($status_filter === 'all' || $status_filter === '') ? 'card-active' : '' ?>" onclick="filterAdminByCard('all')" style="cursor:pointer;" title="View All Products">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Products</span>
            <span class="afto-card-val"><?= number_format($kpi_total_products) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-box"></i></div>
    </div>
    <!-- Card 2: Total Inventory -->
    <div class="afto-card blue">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Inventory</span>
            <span class="afto-card-val"><?= number_format($kpi_total_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-cubes"></i></div>
    </div>
    <!-- Card 3: Total Inventory Value -->
    <div class="afto-card purple">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Total Inventory Value</span>
            <span class="afto-card-val" style="font-size:15px;">₱<?= number_format($kpi_total_value, 2) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-coins"></i></div>
    </div>
    <!-- Card 4: Low Stock Items -->
    <div class="afto-card yellow <?= in_array($status_filter, ['low', 'low stock'], true) ? 'card-active' : '' ?>" onclick="filterAdminByCard('low')" style="cursor:pointer;" title="View Low Stock Items">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Low Stock Items</span>
            <span class="afto-card-val"><?= number_format($kpi_low_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <!-- Card 5: Critical Stock -->
    <div class="afto-card red <?= in_array($status_filter, ['critical', 'critical stock'], true) ? 'card-active' : '' ?>" onclick="filterAdminByCard('critical')" style="cursor:pointer;border-bottom:2px solid #dc2626;" title="View Critical Stock Items">
        <div class="afto-card-info">
            <span class="afto-card-lbl" style="color:#dc2626;">Critical Stock</span>
            <span class="afto-card-val" style="color:#dc2626;"><?= number_format($kpi_critical_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-fire" style="color:#dc2626;"></i></div>
    </div>
    <!-- Card 6: Out of Stock -->
    <div class="afto-card red <?= in_array($status_filter, ['out', 'out of stock'], true) ? 'card-active' : '' ?>" onclick="filterAdminByCard('out')" style="cursor:pointer;" title="View Out of Stock Items">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Out of Stock</span>
            <span class="afto-card-val"><?= number_format($kpi_out_of_stock) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-times-circle"></i></div>
    </div>
    <!-- Card 7: Total Stock Movements Today -->
    <div class="afto-card green">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Movements Today</span>
            <span class="afto-card-val"><?= number_format($stock_movements_today) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-chart-line"></i></div>
    </div>
    <!-- Card 8: Pending Stock Adjustments -->
    <div class="afto-card yellow">
        <div class="afto-card-info">
            <span class="afto-card-lbl">Pending Adjustments</span>
            <span class="afto-card-val"><?= number_format($pending_adjustments_count) ?></span>
        </div>
        <div class="afto-card-icon"><i class="fas fa-clock"></i></div>
    </div>
</div>

<!-- Tab Navigation -->
<div class="tab-nav">
    <a href="admin_inventory_merchandise.php?<?= http_build_query(array_merge($_GET, ['tab' => 'overview'])) ?>"
       class="tab-btn <?= $active_tab === 'overview' ? 'active' : '' ?>">
        <i class="fas fa-boxes"></i> Inventory Overview
    </a>
    <a href="admin_inventory_merchandise.php?tab=movement"
       class="tab-btn <?= $active_tab === 'movement' ? 'active' : '' ?>">
        <i class="fas fa-exchange-alt"></i> Stock Movement Monitoring
    </a>
    <a href="admin_inventory_merchandise.php?tab=alerts"
       class="tab-btn <?= $active_tab === 'alerts' ? 'active' : '' ?>">
        <i class="fas fa-exclamation-triangle"></i> Stock Alerts
        <?php if (($kpi_low_stock + $kpi_critical_stock + $kpi_out_of_stock) > 0): ?>
            <span style="background:#dc2626 !important;color:#fff !important;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;line-height:1;"><?= ($kpi_low_stock + $kpi_critical_stock + $kpi_out_of_stock) ?></span>
        <?php endif; ?>
    </a>
</div>

<?php if ($active_tab === 'overview'): ?>
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
    <div class="table-wrap" style="width:100%; overflow-x:hidden;">
        <table class="afto-tbl" id="adminMerchTable" style="width:100%;">
            <thead>
                <tr>
                    <th>Batch ID</th>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th style="text-align:center;">Category</th>
                    <th style="text-align:center;">UOM</th>
                    <th style="text-align:center;">Expiration Date</th>
                    <th style="text-align:right;">Initial Stock</th>
                    <th>Current Stock</th>
                    <th style="text-align:right;">Reorder Level</th>
                    <th class="align-center">Status</th>
                    <th>Last Updated</th>
                    <th style="text-align:center; white-space:nowrap;">Actions</th>
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
                        $reorder  = (float)($item['reorder_level'] ?? 24);
                        if ($reorder  <= 0) $reorder  = 24;
                        $price    = (float)$item['price'];
                        $value    = $stock * $price;
                        $pid_item = (int)$item['id'];
                        $batch_id = !empty($item['batch_ref']) ? $item['batch_ref'] : (!empty($item['batch_number']) ? $item['batch_number'] : ('B' . str_pad((string)$pid_item, 3, '0', STR_PAD_LEFT)));
                        $exp_date = 'N/A';
                        if (!empty($item['expiration_date']) && $item['expiration_date'] !== '0000-00-00') {
                            $exp_date = date('M d, Y', strtotime($item['expiration_date']));
                        } elseif (!empty($item['date_received'])) {
                            $exp_date = date('M d, Y', strtotime($item['date_received']));
                        } else {
                            try {
                                $dt = new DateTime(!empty($item['last_updated']) ? $item['last_updated'] : '2026-07-20');
                                $cat_str = strtolower((string)($item['category_name'] ?? $item['category'] ?? ''));
                                $name_str = strtolower((string)($item['name'] ?? ''));
                                if (strpos($cat_str, 'accessory') !== false || strpos($cat_str, 'tool') !== false || strpos($name_str, 'wiper') !== false || strpos($name_str, 'mat') !== false) {
                                    $exp_date = 'N/A';
                                } elseif (strpos($cat_str, 'snack') !== false || strpos($cat_str, 'beverage') !== false || strpos($name_str, 'chippy') !== false || strpos($name_str, 'coca') !== false || strpos($name_str, 'choco') !== false) {
                                    $dt->modify('+1 year');
                                    $exp_date = $dt->format('M d, Y');
                                } else {
                                    $dt->modify('+3 years');
                                    $exp_date = $dt->format('M d, Y');
                                }
                            } catch (Exception $e) { $exp_date = 'Jul 20, 2029'; }
                        }
                        $stock_in_qty  = (int)($prod_added_map_id[$pid_item] ?? $prod_added_map_name[strtolower(trim((string)$item['name']))] ?? 0);
                        $initial_qty   = $stock_in_qty > 0 ? $stock_in_qty : max(480, (int)($item['capacity'] ?? 480));
                    ?>
                    <?php
                        $capacity = max(480.0, (float)($item['capacity'] ?? 480));
                        $fill_pct = $capacity > 0 ? min(100, ($stock / $capacity) * 100) : 0;
                        $variance = $item['variance'];
                        $has_variance = ($variance !== null && (float)$variance != 0);
                        $badgeCls = $has_variance ? 'bg-amber' : getStatusBadgeClass($item['computed_status']);
                        $badgeLbl = $has_variance ? 'Variance Detected' : getStatusLabel($item['computed_status']);
                        $status_color = $has_variance ? '#fd7e14' : ($item['computed_status'] === 'available' ? '#28a745' : (in_array($item['computed_status'], ['critical', 'out']) ? '#dc3545' : '#fd7e14'));
                        $updated = $item['last_updated'] ? date('M d, Y h:i A', strtotime($item['last_updated'])) : '-';
                    ?>
                    <tr>
                        <td><code style="font-size:11px;font-weight:700;color:#002F70;"><?= htmlspecialchars($batch_id) ?></code></td>
                        <td><code><?= htmlspecialchars($item['sku'] ?: '-') ?></code></td>
                        <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                        <td class="align-center"><?= htmlspecialchars($item['category_name']) ?></td>
                        <td class="align-center" style="font-weight:600;color:#475569;"><?= htmlspecialchars($item['unit']) ?></td>
                        <td class="align-center" style="font-weight:600;color:<?= $exp_date !== 'N/A' ? '#0f172a' : '#94a3b8' ?>;"><?= htmlspecialchars($exp_date) ?></td>
                        <td style="text-align:right; font-weight:700; color:#0f172a;"><?= number_format($initial_qty) ?></td>
                        <td>
                            <div class="fill-bar-wrap">
                                <div class="fill-bar-inner" style="width:<?= min(100, round($fill_pct)) ?>%;background:<?= $status_color ?>;"></div>
                            </div>
                            <span style="font-size:11px;font-weight:600;color:#334155;"><?= number_format($stock, 0) ?> <?= htmlspecialchars($item['unit']) ?></span>
                        </td>
                        <td class="align-right" style="font-weight:600;color:#ea580c;"><?= number_format($reorder, 0) ?></td>
                        <td class="align-center"><span class="badge-lbl <?= $badgeCls ?>"><?= htmlspecialchars($badgeLbl) ?></span></td>
                        <td style="font-size:11px; color:#64748b;"><?= $updated ?></td>
                        <td class="align-center" style="white-space:nowrap;">
                            <div style="display:inline-flex; gap:4px; align-items:center; justify-content:center;">
                                <button type="button" class="int-btn-outline" style="font-size:11px;height:28px;padding:0 8px;cursor:pointer;"
                                    onclick='adminViewProduct(<?= htmlspecialchars(json_encode([
                                        "id" => $item["id"],
                                        "sku" => $item["sku"],
                                        "name" => $item["name"],
                                        "category_name" => $item["category_name"],
                                        "brand" => $item["brand"] ?? "Petron",
                                        "supplier" => "Petron Corporation",
                                        "unit" => $item["unit"],
                                        "barcode" => $item["barcode"] ?? "",
                                        "stock_level" => $item["stock_level"],
                                        "reorder_level" => $item["reorder_level"],
                                        "critical_level" => $item["critical_level"],
                                        "price" => $item["price"],
                                        "cost" => $item["cost"],
                                        "capacity" => $item["capacity"],
                                        "computed_status" => $item["computed_status"]
                                    ]), ENT_QUOTES) ?>)'>
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button type="button" class="int-btn-outline" style="font-size:11px;height:28px;padding:0 8px;cursor:pointer;color:#002F70;border-color:#002F70;"
                                    onclick='openAdminEditModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES) ?>)'>
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="adminMerchPagination" style="margin: 10px 20px;"></div>
</div>
<?php endif; ?>

<!-- â•â• TAB: STOCK MOVEMENT MONITORING â•â• -->
<?php if ($active_tab === 'movement'): ?>
<div class="tbl-card">
    <div class="tbl-hd">
        <div class="tbl-title"><i class="fas fa-exchange-alt"></i> Stock Movement Monitoring</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <input type="text" id="adminMovSearchInput" placeholder="Search product, ref, user..." oninput="filterAdminMovTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;width:220px;">
            <select id="adminMovTypeFilter" onchange="filterAdminMovTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-weight:600;color:#002F70;">
                <option value="">All Movement Types</option>
                <option value="stock in">Stock In</option>
                <option value="stock out">Stock Out</option>
                <option value="adjustment">Adjustment</option>
                <option value="transfer">Transfer</option>
                <option value="damaged">Damaged</option>
                <option value="expired">Expired</option>
            </select>
        </div>
    </div>
    <div class="table-wrap" style="overflow-x:hidden; width:100%;">
        <table class="afto-tbl" id="adminMovTable" style="width:100%; table-layout:fixed;">
            <colgroup>
                <col style="width:11%;">  <!-- Date -->
                <col style="width:10%;">  <!-- Reference -->
                <col style="width:9%;">   <!-- Type -->
                <col style="width:20%;">  <!-- Product -->
                <col style="width:10%;">  <!-- Quantity -->
                <col style="width:12%;">  <!-- Performed By -->
                <col style="width:15%;">  <!-- Branch -->
                <col style="width:13%;">  <!-- Remarks -->
            </colgroup>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference No.</th>
                    <th style="text-align:center;">Type</th>
                    <th>Product</th>
                    <th style="text-align:right;">Quantity</th>
                    <th>Performed By</th>
                    <th>Branch</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody id="adminMovBody">
            <?php if (empty($movement_history)): ?>
                <tr><td colspan="8" class="align-center" style="padding:24px;color:#64748b;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No movement records found.</td></tr>
            <?php else: ?>
                <?php foreach ($movement_history as $log):
                    $m_date = !empty($log['created_at']) ? date('M d, Y h:i A', strtotime($log['created_at'])) : '—';
                    $m_raw  = strtolower($log['movement_type'] ?? '');
                    $ref_no = !empty($log['reference_no']) ? $log['reference_no'] : ('LOG-' . str_pad($log['log_id'] ?? 0, 5, '0', STR_PAD_LEFT));
                    $qty    = (float)($log['quantity'] ?? 0);
                    $unit   = htmlspecialchars($log['unit'] ?? 'pcs');
                    $user_name = htmlspecialchars($log['user_name'] ?? 'System');
                    $branch = htmlspecialchars($station_name ?: 'Main Station');

                    if (strpos($m_raw, 'in') !== false || strpos($m_raw, 'delivery') !== false || strpos($m_raw, 'receive') !== false) {
                        $type_label = 'Stock In';
                        $badge_style = 'background:#dcfce7;color:#15803d;border:1px solid #a7f3d0;';
                        $qty_text = '+' . number_format(abs($qty), 0);
                        $qty_color = '#15803d';
                    } elseif (strpos($m_raw, 'out') !== false || strpos($m_raw, 'sale') !== false || strpos($m_raw, 'release') !== false) {
                        $type_label = 'Stock Out';
                        $badge_style = 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;';
                        $qty_text = '-' . number_format(abs($qty), 0);
                        $qty_color = '#dc2626';
                    } elseif (strpos($m_raw, 'transfer') !== false) {
                        $type_label = 'Transfer';
                        $badge_style = 'background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;';
                        $qty_text = number_format($qty, 0);
                        $qty_color = '#0284c7';
                    } elseif (strpos($m_raw, 'damage') !== false || strpos($m_raw, 'defective') !== false) {
                        $type_label = 'Damaged';
                        $badge_style = 'background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;';
                        $qty_text = '-' . number_format(abs($qty), 0);
                        $qty_color = '#dc2626';
                    } elseif (strpos($m_raw, 'expire') !== false) {
                        $type_label = 'Expired';
                        $badge_style = 'background:#fff3cd;color:#856404;border:1px solid #ffeeba;';
                        $qty_text = '-' . number_format(abs($qty), 0);
                        $qty_color = '#d97706';
                    } else {
                        $type_label = 'Adjustment';
                        $badge_style = 'background:#f3e8ff;color:#5b21b6;border:1px solid #e9d5ff;';
                        $qty_text = ($qty >= 0 ? '+' : '') . number_format($qty, 0);
                        $qty_color = $qty >= 0 ? '#15803d' : '#dc2626';
                    }
                ?>
                <tr class="mov-row" data-search="<?= strtolower(htmlspecialchars($log['product_name'] . ' ' . $ref_no . ' ' . ($log['user_name'] ?? '') . ' ' . $type_label)) ?>" data-type="<?= strtolower($type_label) ?>" data-raw-type="<?= strtolower(htmlspecialchars($log['movement_type'] ?? '')) ?>">
                    <td style="font-size:11px;color:#64748b;white-space:nowrap;"><?= $m_date ?></td>
                    <td><code style="font-size:11px;font-weight:700;color:#002F70;"><?= htmlspecialchars($ref_no) ?></code></td>
                    <td style="text-align:center;">
                        <span style="<?= $badge_style ?>padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap;">
                            <?= $type_label ?>
                        </span>
                    </td>
                    <td><strong><?= htmlspecialchars($log['product_name']) ?></strong><br><code style="font-size:9px;color:#94a3b8;"><?= htmlspecialchars($log['sku']) ?></code></td>
                    <td style="text-align:right;font-weight:800;font-size:13px;color:<?= $qty_color ?>;"><?= $qty_text ?> <?= $unit ?></td>
                    <td style="font-size:11px;font-weight:600;color:#334155;"><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                    <td style="font-size:11px;color:#475569;"><?= $branch ?></td>
                    <td style="font-size:11px;color:#475569;max-width:200px;"><?= htmlspecialchars($log['notes'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="adminMovPagination" style="margin:10px 20px;"></div>
</div>
<?php endif; ?>

<!-- â•â• TAB: STOCK ALERTS â•â• -->
<?php if ($active_tab === 'alerts'): ?>
<div class="tbl-card">
    <div class="tbl-hd">
        <div class="tbl-title"><i class="fas fa-exclamation-triangle" style="color:#dc2626;"></i> Stock Alerts</div>
        <div style="display:flex;align-items:center;gap:10px;">
            <input type="text" id="adminAlertSearchInput" placeholder="Search alert products..." oninput="filterAdminAlertTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;width:220px;">
        </div>
    </div>
    <div class="table-wrap" style="overflow-x:hidden; width:100%;">
        <table class="afto-tbl" id="adminAlertTable" style="width:100%; table-layout:fixed;">
            <thead>
                <tr>
                    <th>Product</th>
                    <th style="text-align:right;">Current Stock</th>
                    <th style="text-align:right;">Reorder Level</th>
                    <th style="text-align:center;">Status</th>
                </tr>
            </thead>
            <tbody id="adminAlertBody">
            <?php
            $alert_rows = array_filter($all_items, function($i) {
                $st = (float)($i['stock_level'] ?? 0);
                $re = (float)($i['reorder_level'] ?? 24);
                return ($st <= $re || strtolower($i['computed_status'] ?? '') !== 'available');
            });
            ?>
            <?php if (empty($alert_rows)): ?>
                <tr><td colspan="4" class="align-center" style="padding:24px;color:#64748b;"><i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:8px;color:#16a34a;"></i>All stock levels are healthy! No alerts.</td></tr>
            <?php else: ?>
                <?php foreach ($alert_rows as $item):
                    $stock   = (float)$item['stock_level'];
                    $reorder = (float)($item['reorder_level'] ?? 24);
                    $unit    = htmlspecialchars($item['unit'] ?? 'pcs');
                    $st_lbl  = getStatusLabel($item['computed_status']);
                    $st_cls  = getStatusBadgeClass($item['computed_status']);
                ?>
                <tr class="alert-row" data-search="<?= strtolower(htmlspecialchars($item['name'] . ' ' . $item['sku'])) ?>">
                    <td><strong><?= htmlspecialchars($item['name']) ?></strong><br><code style="font-size:9px;color:#94a3b8;"><?= htmlspecialchars($item['sku']) ?></code></td>
                    <td style="text-align:right;font-weight:800;font-size:14px;color:#002F70;"><?= number_format($stock, 0) ?> <?= $unit ?></td>
                    <td style="text-align:right;font-weight:600;color:#ea580c;"><?= number_format($reorder, 0) ?> <?= $unit ?></td>
                    <td style="text-align:center;"><span class="badge-lbl <?= $st_cls ?>"><?= htmlspecialchars($st_lbl) ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="adminAlertPagination" style="margin:10px 20px;"></div>
</div>
<?php endif; ?>

<!-- â•â• VIEW PRODUCT MODAL (WITH SUB-TABS) â•â• -->
<div class="modal-overlay" id="adminViewProdModal" style="z-index:10000;">
    <div style="background:#fff;border-radius:14px;width:96%;max-width:850px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 40px rgba(0,0,0,.18);overflow:hidden;position:relative;z-index:10001;">
        <!-- Header -->
        <div style="padding:16px 22px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;flex-shrink:0;">
            <div style="font-size:15px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-box-open"></i> <span id="vpmTitle">View Product</span>
            </div>
            <button type="button" onclick="closeAdminViewProdModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;line-height:1;">&times;</button>
        </div>
        <!-- Sub-tabs inside modal -->
        <div style="display:flex;border-bottom:2px solid #e2e8f0;background:#f8fafc;flex-shrink:0;padding:0 16px;overflow-x:auto;white-space:nowrap;gap:4px;">
            <button type="button" class="modal-tab-btn active" id="vpmTab1" onclick="vpmSwitchTab(1)"><i class="fas fa-info-circle"></i> Product Information</button>
            <button type="button" class="modal-tab-btn" id="vpmTab2" onclick="vpmSwitchTab(2)"><i class="fas fa-chart-pie"></i> Inventory Summary</button>
            <button type="button" class="modal-tab-btn" id="vpmTab3" onclick="vpmSwitchTab(3)"><i class="fas fa-layer-group"></i> Batch Inventory (FIFO)</button>
        </div>
        <!-- Body -->
        <div style="overflow-y:auto;flex:1;padding:22px;" id="vpmBody">
            <!-- SUB-TAB 1: Product Information -->
            <div id="vpmPane1">
                <div style="font-size:11px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-info-circle"></i> Product Details</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 24px;margin-bottom:20px;">
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">SKU</div><div id="vpmSku" style="font-weight:700;color:#002F70;font-size:14px;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Product Name</div><div id="vpmName" style="font-weight:800;color:#0f172a;font-size:15px;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Category</div><div id="vpmCategory" style="font-weight:600;color:#334155;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Brand</div><div id="vpmBrand" style="font-weight:600;color:#334155;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Supplier</div><div id="vpmSupplier" style="font-weight:600;color:#334155;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Unit of Measure</div><div id="vpmUnit" style="font-weight:600;color:#334155;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Barcode</div><div id="vpmBarcode" style="font-family:monospace;font-weight:700;color:#475569;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Status</div><div id="vpmStatus"></div></div>
                </div>
            </div>
            <!-- SUB-TAB 2: Inventory Summary -->
            <div id="vpmPane2" style="display:none;">
                <div style="font-size:11px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-boxes"></i> Stock &amp; Valuation Summary</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 24px;margin-bottom:20px;">
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Current Stock</div><div id="vpmCurrentStock" style="font-weight:800;color:#002F70;font-size:18px;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Available Stock</div><div id="vpmAvailableStock" style="font-weight:800;color:#16a34a;font-size:18px;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Reorder Level</div><div id="vpmReorderLevel" style="font-weight:600;color:#d97706;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Critical Level</div><div id="vpmCriticalLevel" style="font-weight:600;color:#dc2626;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Unit Cost</div><div id="vpmCost" style="font-weight:600;color:#475569;"></div></div>
                    <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Selling Price</div><div id="vpmPrice" style="font-weight:700;color:#16a34a;"></div></div>
                    <div style="grid-column:span 2;"><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Total Inventory Value</div><div id="vpmInventoryValue" style="font-weight:800;color:#002F70;font-size:18px;"></div></div>
                </div>
            </div>
            <!-- SUB-TAB 3: Batch Inventory (FIFO) -->
            <div id="vpmPane3" style="display:none;">
                <div style="font-size:11px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-layer-group"></i> FIFO Batch Inventory Breakdown</div>
                <div id="vpmFifoTable"><div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
            </div>
        </div>
        <!-- Footer -->
        <div style="padding:12px 22px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;flex-shrink:0;">
            <button type="button" onclick="closeAdminViewProdModal()" class="int-btn-outline" style="border-color:#6b7280;color:#6b7280;"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- â•â• VIEW MOVEMENT MODAL â•â• -->
<div class="modal-overlay" id="adminViewMovModal" style="z-index:10005;">
    <div style="background:#fff;border-radius:14px;width:96%;max-width:520px;box-shadow:0 24px 40px rgba(0,0,0,.22);overflow:hidden;position:relative;z-index:10006;">
        <div style="padding:16px 22px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;">
            <div style="font-size:15px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-exchange-alt"></i> Stock Movement Details
            </div>
            <button type="button" onclick="closeAdminViewMovModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;line-height:1;">&times;</button>
        </div>
        <div style="padding:22px;font-size:13px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Date / Time</div><div id="vmmDate" style="font-weight:700;color:#0f172a;"></div></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Movement Type</div><div id="vmmType"></div></div>
            </div>
            <div style="margin-bottom:12px;"><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Product Name</div><div id="vmmProduct" style="font-weight:700;color:#002F70;font-size:15px;"></div></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Batch / Ref No.</div><div id="vmmRef" style="font-weight:600;color:#475569;"></div></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Quantity</div><div id="vmmQty" style="font-weight:800;font-size:16px;"></div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Remaining Stock</div><div id="vmmRemaining" style="font-weight:700;color:#002F70;"></div></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Performed By</div><div id="vmmBy" style="font-weight:600;color:#334155;"></div></div>
            </div>
            <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Notes / Remarks</div><div id="vmmNotes" style="color:#64748b;font-style:italic;"></div></div>
        </div>
        <div style="padding:12px 22px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;background:#f8fafc;">
            <button type="button" onclick="closeAdminViewMovModal()" class="int-btn-outline" style="border-color:#6b7280;color:#6b7280;"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- â•â• VIEW STOCK-IN MODAL â•â• -->
<div class="modal-overlay" id="adminViewSiModal" style="z-index:10005;">
    <div style="background:#fff;border-radius:14px;width:96%;max-width:540px;box-shadow:0 24px 40px rgba(0,0,0,.22);overflow:hidden;position:relative;z-index:10006;">
        <div style="padding:16px 22px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;">
            <div style="font-size:15px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-truck-loading"></i> Stock-In Record Details
            </div>
            <button type="button" onclick="closeAdminViewSiModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#64748b;line-height:1;">&times;</button>
        </div>
        <div style="padding:22px;font-size:13px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Stock-In No.</div><div id="vsimSiNo" style="font-weight:800;color:#002F70;font-size:15px;"></div></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">PO Number</div><div id="vsimPo" style="font-weight:700;color:#0f172a;"></div></div>
            </div>
            <div style="margin-bottom:12px;"><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Product Name</div><div id="vsimProduct" style="font-weight:700;color:#002F70;font-size:15px;"></div></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Supplier</div><div id="vsimSupplier" style="font-weight:600;color:#334155;"></div></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Delivery Date</div><div id="vsimDate" style="font-weight:600;color:#334155;"></div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;background:#f8fafc;padding:10px;border-radius:6px;border:1px solid #e2e8f0;">
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Received Qty</div><div id="vsimQty" style="font-weight:800;color:#002F70;font-size:15px;"></div></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Unit Cost</div><div id="vsimCost" style="font-weight:700;color:#475569;"></div></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Batch No.</div><div id="vsimBatch" style="font-weight:700;color:#475569;"></div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Received By</div><div id="vsimBy" style="font-weight:600;color:#334155;"></div></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Status</div><div id="vsimStatus"></div></div>
            </div>
            <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Notes</div><div id="vsimNotes" style="color:#64748b;font-style:italic;"></div></div>
        </div>
        <div style="padding:12px 22px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;background:#f8fafc;">
            <button type="button" onclick="closeAdminViewSiModal()" class="int-btn-outline" style="border-color:#6b7280;color:#6b7280;"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- ════ EDIT PRODUCT MODAL (ADMIN) ════ -->
<div class="modal-overlay" id="adminEditProdModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;">
    <div class="modal-box" style="max-width:620px; width:95%; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.25);">
        <div class="modal-head" style="background:#002F70; padding:16px 20px; color:#fff; display:flex; align-items:center; justify-content:space-between;">
            <div class="modal-title" style="font-size:16px; font-weight:700; color:#fff !important; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-edit"></i> Edit Product Information
            </div>
            <button type="button" onclick="closeAdminEditModal()" style="background:none; border:none; color:#fff; font-size:18px; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form method="post" action="admin_inventory_merchandise.php" style="padding:20px;">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            <input type="hidden" name="product_id" id="adminEditProdId">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Product Name <span style="color:red;">*</span></label>
                    <input type="text" name="product_name" id="adminEditProdName" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#0f172a;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Category</label>
                    <input type="text" id="adminEditProdCategory" readonly style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px; background:#f8fafc; color:#64748b;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Unit of Measure <span style="color:red;">*</span></label>
                    <input type="text" name="unit" id="adminEditProdUnit" required placeholder="e.g. pcs, Liter, Bottle" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#0f172a;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Reorder Level <span style="color:red;">*</span></label>
                    <input type="number" name="reorder_level" id="adminEditProdReorder" min="0" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#ea580c;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Critical Level <span style="color:red;">*</span></label>
                    <input type="number" name="critical_level" id="adminEditProdCritical" min="0" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#dc2626;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Max Capacity <span style="color:red;">*</span></label>
                    <input type="number" name="capacity" id="adminEditProdCapacity" min="1" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#002F70;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Selling Price (₱) <span style="color:red;">*</span></label>
                    <input type="number" step="0.01" name="price" id="adminEditProdPrice" min="0" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#16a34a;">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Unit Cost (₱)</label>
                    <input type="number" step="0.01" name="cost" id="adminEditProdCost" min="0" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#475569;">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:16px;">
                <button type="button" onclick="closeAdminEditModal()" style="padding:8px 20px; border:1.5px solid #00264D !important; background:#ffffff !important; color:#00264D !important; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer;">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#002F70 !important; color:#fff !important; padding:8px 20px; border:none; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
var _adminCurrentProd = null;

function openAdminEditModal(item) {
    if (!item) return;
    var modal = document.getElementById('adminEditProdModal');
    if (!modal) return;
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }

    document.getElementById('adminEditProdId').value = item.id || '';
    document.getElementById('adminEditProdName').value = item.name || '';
    document.getElementById('adminEditProdCategory').value = item.category_name || item.category || 'General';
    document.getElementById('adminEditProdUnit').value = item.unit || 'pcs';
    document.getElementById('adminEditProdReorder').value = parseFloat(item.reorder_level) || 24;
    document.getElementById('adminEditProdCritical').value = parseFloat(item.critical_level) || 10;
    document.getElementById('adminEditProdCapacity').value = parseFloat(item.capacity) || 480;
    document.getElementById('adminEditProdPrice').value = parseFloat(item.price) || 0;
    document.getElementById('adminEditProdCost').value = parseFloat(item.cost) || 0;

    modal.classList.add('open');
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.right = '0';
    modal.style.bottom = '0';
    modal.style.zIndex = '10000';
}

function closeAdminEditModal() {
    var modal = document.getElementById('adminEditProdModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function adminViewProduct(item) {
    _adminCurrentProd = item;
    var overlay = document.getElementById('adminViewProdModal');
    if (!overlay) return;
    if (overlay.parentNode !== document.body) {
        document.body.appendChild(overlay);
    }

    document.getElementById('vpmTitle').textContent = 'View Product — ' + (item.name || '');
    document.getElementById('vpmSku').textContent = item.sku || '—';
    document.getElementById('vpmName').textContent = item.name || '—';
    document.getElementById('vpmCategory').textContent = item.category_name || '—';
    document.getElementById('vpmBrand').textContent = item.brand || 'Petron';
    document.getElementById('vpmSupplier').textContent = item.supplier || 'Petron Corporation';
    document.getElementById('vpmUnit').textContent = item.unit || 'pcs';
    document.getElementById('vpmBarcode').textContent = item.barcode || '4800012345678';
    
    var statusMap = {
        'available': '<span class="badge-lbl bg-green">Available</span>',
        'low': '<span class="badge-lbl bg-amber">Low Stock</span>',
        'critical': '<span class="badge-lbl bg-red">Critical Stock</span>',
        'out': '<span class="badge-lbl bg-red">Out of Stock</span>'
    };
    document.getElementById('vpmStatus').innerHTML = statusMap[item.computed_status] || '<span class="badge-lbl bg-green">Available</span>';

    // Sub-tab 2 Data
    var stock = parseFloat(item.stock_level) || 0;
    var price = parseFloat(item.price) || 0;
    var cost  = parseFloat(item.cost) || 0;
    var reorder = parseFloat(item.reorder_level) || 24;
    var critical = parseFloat(item.critical_level) || 10;

    document.getElementById('vpmCurrentStock').textContent = stock.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ' + (item.unit || 'pcs');
    document.getElementById('vpmAvailableStock').textContent = stock.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ' + (item.unit || 'pcs');
    document.getElementById('vpmReorderLevel').textContent = reorder.toLocaleString('en-US') + ' ' + (item.unit || 'pcs');
    document.getElementById('vpmCriticalLevel').textContent = critical.toLocaleString('en-US') + ' ' + (item.unit || 'pcs');
    document.getElementById('vpmCost').textContent = '₱' + cost.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('vpmPrice').textContent = '₱' + price.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('vpmInventoryValue').textContent = '₱' + (stock * price).toLocaleString('en-US', {minimumFractionDigits: 2});

    // Reset sub-tabs
    vpmSwitchTab(1);

    // FIFO table loading placeholder
    document.getElementById('vpmFifoTable').innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading FIFO batches...</div>';

    // Show modal
    overlay.classList.add('open');
    overlay.style.display = 'flex';
    overlay.style.position = 'fixed';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.right = '0';
    overlay.style.bottom = '0';
    overlay.style.zIndex = '10000';

    // Fetch FIFO details via AJAX
    fetch('admin_inventory_merchandise.php?ajax=1&action=get_product_details&product_id=' + item.id)
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.success || !data.deliveries || data.deliveries.length === 0) {
            document.getElementById('vpmFifoTable').innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:#f8fafc;"><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Batch ID</th><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Delivery Date</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Received Qty</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Remaining Qty</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Unit Cost</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Selling Price</th></tr></thead><tbody><tr><td colspan="6" style="text-align:center;padding:16px;color:#94a3b8;">No FIFO batch records found. Defaulting to main inventory pool.</td></tr></tbody></table>';
            return;
        }
        var fHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        fHtml += '<thead><tr style="background:#f8fafc;"><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Batch ID</th><th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Delivery Date</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Received Qty</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Remaining Qty</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Unit Cost</th><th style="padding:8px;text-align:right;border-bottom:1px solid #e2e8f0;">Selling Price</th></tr></thead><tbody>';
        data.deliveries.forEach(function(d) {
            var dateStr = d.encoded_at ? new Date(d.encoded_at).toLocaleDateString() : '—';
            fHtml += '<tr><td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;"><code style="color:#002F70;font-weight:700;">' + esc(d.batch_no || 'BATCH-' + d.id) + '</code></td>';
            fHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;">' + dateStr + '</td>';
            fHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;">' + Number(d.qty_received).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td>';
            fHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:#002F70;">' + Number(d.qty_received).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td>';
            fHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;">₱' + Number(d.unit_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td>';
            fHtml += '<td style="padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right;color:#16a34a;font-weight:700;">₱' + Number(item.price || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td></tr>';
        });
        fHtml += '</tbody></table>';
        document.getElementById('vpmFifoTable').innerHTML = fHtml;
    })
    .catch(function() {
        document.getElementById('vpmFifoTable').innerHTML = '<div style="text-align:center;padding:16px;color:#dc3545;">Connection error loading FIFO data.</div>';
    });
}

function esc(str) {
    if (!str) return '';
    return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function closeAdminViewProdModal() {
    var overlay = document.getElementById('adminViewProdModal');
    if (overlay) {
        overlay.classList.remove('open');
        overlay.style.display = 'none';
    }
}

function vpmSwitchTab(tabNum) {
    for (var i = 1; i <= 3; i++) {
        var pane = document.getElementById('vpmPane' + i);
        var btn = document.getElementById('vpmTab' + i);
        if (pane) pane.style.display = (i === tabNum) ? 'block' : 'none';
        if (btn) {
            if (i === tabNum) {
                btn.classList.add('active');
                btn.style.borderBottom = '2px solid #002F70';
                btn.style.color = '#002F70';
                btn.style.fontWeight = '700';
            } else {
                btn.classList.remove('active');
                btn.style.borderBottom = '2px solid transparent';
                btn.style.color = '#64748b';
                btn.style.fontWeight = '600';
            }
        }
    }
}

function printAdminProductDetails() {
    var r = _adminCurrentProd;
    if (!r) return;
    var pw = window.open('', '_blank');
    pw.document.write('<!DOCTYPE html><html><head><title>Product Details — ' + (r.name || '') + '</title>');
    pw.document.write('<style>body{font-family:Arial,sans-serif;font-size:13px;color:#222;margin:0;padding:24px;}.header{background:#002F6C;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;}.header h2{margin:0;font-size:16px;}.section{border:1px solid #e2e8f0;border-top:none;padding:16px 20px;margin-bottom:12px;}.section h4{margin:0 0 10px;color:#002F6C;font-size:11px;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}table.info{width:100%;border-collapse:collapse;font-size:12px;}table.info td{padding:5px 0;border-bottom:1px solid #f1f5f9;}table.info td:first-child{color:#64748b;font-weight:600;width:180px;}.footer{text-align:center;font-size:10px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}</style></head><body>');
    pw.document.write('<div class="header"><h2>Merchandise Product Slip — ' + (r.name || '') + '</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
    pw.document.write('<div class="section"><h4>Product Information</h4><table class="info">');
    pw.document.write('<tr><td>SKU:</td><td><code>' + (r.sku || '—') + '</code></td></tr>');
    pw.document.write('<tr><td>Product Name:</td><td><strong>' + (r.name || '—') + '</strong></td></tr>');
    pw.document.write('<tr><td>Category:</td><td>' + (r.category_name || '—') + '</td></tr>');
    pw.document.write('<tr><td>Brand:</td><td>' + (r.brand || 'Petron') + '</td></tr>');
    pw.document.write('<tr><td>Supplier:</td><td>' + (r.supplier || 'Petron Corporation') + '</td></tr>');
    pw.document.write('<tr><td>Barcode:</td><td>' + (r.barcode || '4800012345678') + '</td></tr>');
    pw.document.write('<tr><td>Current Stock:</td><td><strong style="color:#002F70;">' + Number(r.stock_level || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ' + (r.unit || 'pcs') + '</strong></td></tr>');
    pw.document.write('<tr><td>Selling Price:</td><td><strong style="color:#16a34a;">₱' + Number(r.price || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</strong></td></tr>');
    pw.document.write('</table></div>');
    pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    pw.document.write('</body></html>');
    pw.document.close();
    setTimeout(function() { pw.print(); }, 300);
}

// â”€â”€ Movement Modal â”€â”€
function openMovModal(d) {
    var modal = document.getElementById('adminViewMovModal');
    if (!modal) return;
    document.getElementById('vmmDate').textContent = d.date;
    document.getElementById('vmmType').innerHTML = '<span style="font-weight:700;color:#002F70;">' + esc(d.type) + '</span>';
    document.getElementById('vmmProduct').textContent = d.product + ' (' + d.sku + ')';
    document.getElementById('vmmRef').textContent = d.ref;
    document.getElementById('vmmQty').textContent = d.qty;
    document.getElementById('vmmQty').style.color = d.qty.indexOf('+') !== -1 ? '#16a34a' : (d.qty.indexOf('-') !== -1 ? '#dc2626' : '#64748b');
    document.getElementById('vmmRemaining').textContent = d.remaining;
    document.getElementById('vmmBy').textContent = d.by;
    document.getElementById('vmmNotes').textContent = d.notes;

    modal.classList.add('open');
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.top = '0'; modal.style.left = '0'; modal.style.right = '0'; modal.style.bottom = '0';
    modal.style.zIndex = '10005';
}

function closeAdminViewMovModal() {
    var modal = document.getElementById('adminViewMovModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

// â”€â”€ Stock-In Modal â”€â”€
function openSiModal(d) {
    var modal = document.getElementById('adminViewSiModal');
    if (!modal) return;
    document.getElementById('vsimSiNo').textContent = d.si_no;
    document.getElementById('vsimPo').textContent = d.po_number;
    document.getElementById('vsimProduct').textContent = d.product + ' (' + d.sku + ')';
    document.getElementById('vsimSupplier').textContent = d.supplier;
    document.getElementById('vsimDate').textContent = d.date;
    document.getElementById('vsimQty').textContent = d.qty;
    document.getElementById('vsimCost').textContent = '₱' + d.unit_cost;
    document.getElementById('vsimBatch').textContent = d.batch;
    document.getElementById('vsimBy').textContent = d.received_by;
    document.getElementById('vsimStatus').innerHTML = '<span style="background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;">' + esc(d.status) + '</span>';
    document.getElementById('vsimNotes').textContent = d.notes;

    modal.classList.add('open');
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.top = '0'; modal.style.left = '0'; modal.style.right = '0'; modal.style.bottom = '0';
    modal.style.zIndex = '10005';
}

function closeAdminViewSiModal() {
    var modal = document.getElementById('adminViewSiModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

// â”€â”€ Movement & Stock-In Table Filters â”€â”€
function filterMovTable() {
    var query = (document.getElementById('movSearchInput') || {}).value || '';
    var type = (document.getElementById('movTypeFilter') || {}).value || '';
    query = query.toLowerCase().trim();
    type = type.toLowerCase().trim();

    var rows = document.querySelectorAll('#adminMovBody tr.mov-row');
    rows.forEach(function(r) {
        var rProd = (r.dataset.product || '').toLowerCase();
        var rType = (r.dataset.type || '').toLowerCase();
        var match = true;
        if (query && rProd.indexOf(query) === -1 && rType.indexOf(query) === -1) match = false;
        if (type && rType.indexOf(type) === -1) match = false;
        r.style.display = match ? '' : 'none';
    });
}

function filterSiTable() {
    var query = (document.getElementById('siSearchInput') || {}).value || '';
    query = query.toLowerCase().trim();

    var rows = document.querySelectorAll('#adminSiBody tr.si-row');
    rows.forEach(function(r) {
        var rProd = (r.dataset.product || '').toLowerCase();
        var rPo = (r.dataset.po || '').toLowerCase();
        var rSup = (r.dataset.supplier || '').toLowerCase();
        var match = true;
        if (query && rProd.indexOf(query) === -1 && rPo.indexOf(query) === -1 && rSup.indexOf(query) === -1) match = false;
        r.style.display = match ? '' : 'none';
    });
}

function filterSoTable() {
    var q = ((document.getElementById('soSearchInput') || {}).value || '').toLowerCase().trim();
    document.querySelectorAll('#adminSoTable .so-row').forEach(function(r) {
        var s = (r.getAttribute('data-search') || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
}

function filterTrTable() {
    var q = ((document.getElementById('trSearchInput') || {}).value || '').toLowerCase().trim();
    document.querySelectorAll('#adminTrTable .tr-row').forEach(function(r) {
        var s = (r.getAttribute('data-search') || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
}

function filterDmgTable() {
    var q = ((document.getElementById('dmgSearchInput') || {}).value || '').toLowerCase().trim();
    document.querySelectorAll('#adminDmgTable .dmg-row').forEach(function(r) {
        var s = (r.getAttribute('data-search') || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
}

function filterAdminMovTable() {
    var search = (document.getElementById('adminMovSearchInput')?.value || '').toLowerCase().trim();
    var type = (document.getElementById('adminMovTypeFilter')?.value || '').toLowerCase().trim();
    var rows = document.querySelectorAll('#adminMovBody .mov-row');
    rows.forEach(function(row) {
        var rowSearch = (row.getAttribute('data-search') || '').toLowerCase();
        var rowType = (row.getAttribute('data-type') || '').toLowerCase();
        var rowRawType = (row.getAttribute('data-raw-type') || '').toLowerCase();
        
        var matchesSearch = !search || rowSearch.indexOf(search) !== -1;
        var matchesType = false;
        
        if (!type) {
            matchesType = true;
        } else if (type === 'stock in' || type === 'stock_in') {
            matchesType = (rowType === 'stock in' || rowType === 'stock_in' || rowRawType === 'delivery' || rowRawType === 'stock_in' || rowRawType === 'stock-in');
        } else if (type === 'stock out' || type === 'stock_out') {
            matchesType = (rowType === 'stock out' || rowType === 'stock_out' || rowRawType === 'sale' || rowRawType === 'stock_out' || rowRawType === 'stock-out' || rowRawType === 'release');
        } else if (type === 'adjustment') {
            matchesType = (rowType === 'adjustment' || rowRawType === 'adjustment');
        } else if (type === 'transfer') {
            matchesType = (rowType === 'transfer' || rowRawType.indexOf('transfer') !== -1);
        } else if (type === 'damaged') {
            matchesType = (rowType === 'damaged' || rowRawType.indexOf('damage') !== -1 || rowRawType.indexOf('defective') !== -1);
        } else if (type === 'expired') {
            matchesType = (rowType === 'expired' || rowRawType.indexOf('expire') !== -1);
        } else {
            matchesType = (rowType === type || rowRawType === type);
        }

        if (matchesSearch && matchesType) {
            row.classList.remove('search-hidden');
            row.style.display = '';
        } else {
            row.classList.add('search-hidden');
            row.style.display = 'none';
        }
    });

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['adminMovTable']) {
        window.tablePaginationTriggers['adminMovTable']();
    }
}

function filterAdminAlertTable() {
    var search = (document.getElementById('adminAlertSearchInput')?.value || '').toLowerCase().trim();
    var rows = document.querySelectorAll('#adminAlertBody .alert-row');
    rows.forEach(function(row) {
        var rowSearch = (row.getAttribute('data-search') || '').toLowerCase();
        if (!search || rowSearch.indexOf(search) !== -1) {
            row.classList.remove('search-hidden');
            row.style.display = '';
        } else {
            row.classList.add('search-hidden');
            row.style.display = 'none';
        }
    });

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['adminAlertTable']) {
        window.tablePaginationTriggers['adminAlertTable']();
    }
}

function filterExpTable() {
    var q = ((document.getElementById('expSearchInput') || {}).value || '').toLowerCase().trim();
    document.querySelectorAll('#adminExpTable .exp-row').forEach(function(r) {
        var s = (r.getAttribute('data-search') || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
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

// â”€â”€ Admin Custom Dropdown (CDD) Logic â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

function setupDownwardFilterSelects(selectors) {
    if (!selectors) return;
    var rawList = Array.isArray(selectors) ? selectors : (selectors instanceof NodeList ? Array.from(selectors) : [selectors]);
    var selects = [];
    rawList.forEach(function(item) {
        if (typeof item === 'string') {
            document.querySelectorAll(item).forEach(function(el) { if (el) selects.push(el); });
        } else if (item instanceof NodeList || (item && item.length && item[0])) {
            Array.from(item).forEach(function(el) { if (el) selects.push(el); });
        } else if (item && item.nodeType === 1) {
            selects.push(item);
        }
    });

    selects.forEach(function(select) {
        if (!select || !select.tagName || select.tagName.toLowerCase() !== 'select') return;
        if (select.dataset && select.dataset.forceDownReady === '1') return;
        if (select.dataset) select.dataset.forceDownReady = '1';
        else select.setAttribute('data-force-down-ready', '1');

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
    // Wire hidden input map: cdd id â†’ hidden input id
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
    setupDownwardFilterSelects(['#adminMovTypeFilter']);

    // Close on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.adm-cdd-wrap')) {
            document.querySelectorAll('.adm-cdd-wrap.adm-cdd-open').forEach(function(w){ w.classList.remove('adm-cdd-open'); });
        }
    });

    <?php if ($active_tab === 'overview'): ?>
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminMerchTable', 'adminMerchRowsLimit', 'adminMerchPagination', 50);
    }
    <?php elseif ($active_tab === 'movement'): ?>
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminMovTable', 'adminMovRowsLimit', 'adminMovPagination', 50);
    }
    <?php elseif ($active_tab === 'alerts'): ?>
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('adminAlertTable', 'adminAlertRowsLimit', 'adminAlertPagination', 50);
    }
    <?php endif; ?>
});
</script>
</div> <!-- /.main-content -->

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
