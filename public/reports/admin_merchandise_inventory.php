<?php
/**
 * Merchandise Inventory Report — deliveries in, sales out, stock balances, low-stock alerts
 * Works as both standalone page and included file
 */

// Initialize for standalone access
$is_standalone = !isset($date_start) || !isset($date_end) || !isset($pdo) || !isset($station_id);

if ($is_standalone) {
    // Standalone mode - setup environment
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Include required files
    require_once __DIR__ . '/../../backend/lib.php';
    require_once __DIR__ . '/../db_connect.php';
    
    // Check authentication
    require_login();
    
    // Get user info
    $current_user = current_user();
    $user_role = role_key($current_user['role'] ?? 'staff');
    
    // Get station_id from user session
    $station_id = user_station_id();
    
    // Check if user has station assigned
    if (!$station_id && in_array($user_role, ['admin', 'manager', 'staff'])) {
        render_no_station_page('admin_dashboard.php');
    }
    
    // Get date range from GET parameters with defaults
    $date_start = $_GET['date_from'] ?? date('Y-m-d');
    $date_end = $_GET['date_to'] ?? date('Y-m-d');
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) {
        $date_start = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end)) {
        $date_end = date('Y-m-d');
    }
    
    // Include header for standalone page
    $page_id = 'admin_reports';
    require_once __DIR__ . '/../../partials/header.php';
}

// Current stock per product
$products = [];
try {
    $q = $pdo->prepare("SELECT si.*, ip.product_name, ip.sku, ip.category, ip.unit
        FROM station_inventory si
        LEFT JOIN inventory_products ip ON ip.id = si.product_id
        WHERE si.station_id = ? AND si.status = 'active'
        ORDER BY ip.category, ip.product_name");
    $q->execute([$station_id]);
    $products = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Merch sold in period per product (from merchandise_transaction_items joined to transactions)
$merch_sold = [];
try {
    $q2 = $pdo->prepare("SELECT mti.item_sku AS sku, mti.item_name AS name,
        COALESCE(SUM(mti.quantity),0) AS qty_sold,
        COALESCE(SUM(mti.subtotal),0) AS revenue
        FROM merchandise_transaction_items mti
        INNER JOIN merchandise_transactions mt ON mti.transaction_id = mt.id
        WHERE mt.station_id=? AND DATE(mt.created_at) BETWEEN ? AND ?
        GROUP BY mti.item_sku, mti.item_name ORDER BY revenue DESC");
    $q2->execute([$station_id, $date_start, $date_end]);
    foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $merch_sold[$row['sku']] = $row;
    }
} catch (Exception $e) {
    // Fallback: aggregate from merchandise_transactions directly
    try {
        $q2b = $pdo->prepare("SELECT item_sku AS sku, NULL AS name,
            COALESCE(SUM(quantity),0) AS qty_sold, COALESCE(SUM(total_amount),0) AS revenue
            FROM merchandise_transactions WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY item_sku");
        $q2b->execute([$station_id, $date_start, $date_end]);
        foreach ($q2b->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $merch_sold[$row['sku']] = $row;
        }
    } catch (Exception $e2) {}
}

// Deliveries in period (from purchase_orders with stock_in_done=1 or received status)
$deliveries = [];
try {
    $q3 = $pdo->prepare("SELECT po.product_name, po.quantity AS qty, po.total_amount AS value,
        po.created_at, po.supplier_name
        FROM purchase_orders po WHERE po.station_id=?
        AND po.stock_in_done=1
        AND DATE(COALESCE(po.stock_in_at, po.created_at)) BETWEEN ? AND ?
        ORDER BY po.created_at DESC LIMIT 50");
    $q3->execute([$station_id, $date_start, $date_end]);
    $deliveries = $q3->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Summary aggregates
$total_products = count($products);
$low_stock   = array_filter($products, fn($p) => ($p['stock_level']??0) <= ($p['reorder_level']??10) && ($p['stock_level']??0) > 0);
$out_stock   = array_filter($products, fn($p) => ($p['stock_level']??0) <= 0);
$total_merch_rev = array_sum(array_column($merch_sold,'revenue'));
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
  <div>
    <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-boxes"></i> Merchandise Inventory Report</h2>
    <p style="margin:0;color:#666;font-size:13px;">Stock levels, deliveries in, sales out, and reorder alerts</p>
  </div>
  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<!-- KPI Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:16px;margin-bottom:28px;">
  <div style="background:linear-gradient(135deg,#003366,#004d99);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Total Products</div>
    <div style="font-size:22px;font-weight:700;"><?= $total_products ?></div>
    <div style="font-size:11px;opacity:.75;">Active SKUs</div>
  </div>
  <div style="background:linear-gradient(135deg,#fd7e14,#e06c00);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Low Stock</div>
    <div style="font-size:22px;font-weight:700;"><?= count($low_stock) ?></div>
    <div style="font-size:11px;opacity:.75;">Need reorder</div>
  </div>
  <div style="background:linear-gradient(135deg,#dc3545,#c82333);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Out of Stock</div>
    <div style="font-size:22px;font-weight:700;"><?= count($out_stock) ?></div>
    <div style="font-size:11px;opacity:.75;">Zero stock</div>
  </div>
  <div style="background:linear-gradient(135deg,#1a7c40,#228b22);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Revenue (Period)</div>
    <div style="font-size:22px;font-weight:700;">₱<?= number_format($total_merch_rev,2) ?></div>
    <div style="font-size:11px;opacity:.75;">Merchandise sales</div>
  </div>
</div>

<?php if(!empty($low_stock) || !empty($out_stock)): ?>
<div style="background:#fff3cd;border:1px solid #ffc107;border-left:5px solid #ffc107;border-radius:8px;padding:14px 18px;margin-bottom:20px;">
  <strong><i class="fas fa-exclamation-triangle"></i> Reorder Alerts</strong>
  <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;">
  <?php foreach(array_merge(iterator_to_array($out_stock, false), iterator_to_array($low_stock, false)) as $p):
    $name = htmlspecialchars($p['product_name'] ?? 'Product #'.$p['product_id']);
    $color = ($p['stock_level']??0) <= 0 ? '#dc3545' : '#fd7e14';
  ?>
    <span style="background:<?= $color ?>;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600;">
      <?= $name ?>: <?= number_format($p['stock_level']??0,2) ?> <?= $p['unit']??'' ?>
    </span>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Stock Levels Table -->
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-list"></i> Current Stock Levels</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">
  <thead>
    <tr>
      <th>Product</th><th>Category</th><th>SKU</th><th>Unit</th>
      <th>Stock Level</th><th>Reorder Level</th><th>Price</th><th>Status</th>
    </tr>
  </thead>
  <tbody>
  <?php if(empty($products)): ?>
    <tr><td colspan="8" style="text-align:center;color:#999;padding:40px;">No inventory records found.</td></tr>
  <?php else: foreach($products as $p):
    $lvl = (float)($p['stock_level']??0);
    $ror = (float)($p['reorder_level']??10);
    $status = $lvl <= 0 ? ['Out of Stock','#dc3545'] : ($lvl <= $ror ? ['Low Stock','#fd7e14'] : ['In Stock','#28a745']);
  ?>
    <tr>
      <td><strong><?= htmlspecialchars($p['product_name'] ?? 'Product #'.$p['product_id']) ?></strong></td>
      <td><?= htmlspecialchars($p['category'] ?? '—') ?></td>
      <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($p['sku'] ?? '—') ?></td>
      <td><?= htmlspecialchars($p['unit'] ?? '—') ?></td>
      <td><strong><?= number_format($lvl,2) ?></strong></td>
      <td><?= number_format($ror,2) ?></td>
      <td>₱<?= number_format((float)($p['price']??0),2) ?></td>
      <td><span style="color:<?= $status[1] ?>;font-weight:700;"><?= $status[0] ?></span></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<!-- Top-Selling Products -->
<?php if(!empty($merch_sold)): ?>
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-chart-line"></i> Sales Activity (<?= $date_start ?> to <?= $date_end ?>)</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">
  <thead><tr><th>SKU</th><th>Product</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
  <tbody>
  <?php foreach($merch_sold as $s): ?>
    <tr>
      <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($s['sku']??'—') ?></td>
      <td><?= htmlspecialchars($s['name']??$s['sku']??'—') ?></td>
      <td><?= number_format((float)$s['qty_sold'],2) ?></td>
      <td>₱<?= number_format((float)$s['revenue'],2) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- Deliveries Received -->
<?php if(!empty($deliveries)): ?>
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-truck"></i> Deliveries Received</h3>
<div style="overflow-x:auto;">
<table class="report-table">
  <thead><tr><th>Date</th><th>Product</th><th>Qty</th><th>Value</th><th>Supplier</th></tr></thead>
  <tbody>
  <?php foreach($deliveries as $d): ?>
    <tr>
      <td><?= date('M j, Y', strtotime($d['created_at'])) ?></td>
      <td><?= htmlspecialchars($d['product_name']) ?></td>
      <td><?= number_format((float)$d['qty'],2) ?></td>
      <td>₱<?= number_format((float)$d['value'],2) ?></td>
      <td><?= htmlspecialchars($d['supplier_name']??'—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php
// Include footer if running in standalone mode
if ($is_standalone) {
    require_once __DIR__ . '/../../partials/footer.php';
}
?>
