<?php
/**
 * INVENTORY REPORTS
 * 
 * Inventory management and stock reporting for Admin and Manager roles
 * Station-specific filtering for managers
 * Nationwide view for admins
 */
$page_id = 'inventory_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? 'staff'));
$station_id = user_station_id();

// Access Control: Admin and Manager only
if (!in_array($role, ['admin', 'superadmin', 'manager'])) {
    header("Location: dashboard.php");
    exit;
}

// Station ID validation - Managers can only access their own station
if ($role === 'manager' && isset($_GET['station_id']) && $_GET['station_id'] != $station_id) {
    die("Invalid station access");
}

// Parameters
$view = $_GET['view'] ?? 'stock_levels';
$station_filter = $_GET['station_id'] ?? ($role === 'manager' ? $station_id : 'all');

// Fetch station info
$station_name = 'All Stations';
if ($station_filter !== 'all') {
    try {
        $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
        $stmt->execute([$station_filter]);
        $station = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($station) $station_name = $station['name'];
    } catch(Exception $e) {}
}

// --- DATA FETCHING LOGIC ---
$data = [];
$title = "";
$subtitle = "";

try {
    if ($view === 'stock_levels') {
        $title = "Inventory Stock Levels Report";
        $subtitle = "Current stock status for " . htmlspecialchars($station_name);
        
        $station_clause = $station_filter === 'all' ? "1=1" : "i.station_id = ?";
        $params = $station_filter === 'all' ? [] : [$station_filter];
        
        $sql = "SELECT 
                    p.id, p.name, p.sku, p.description,
                    COALESCE(pt.name, 'Other') as category,
                    COALESCE(i.quantity, 0) as current_stock,
                    p.reorder_level,
                    p.unit_price,
                    COALESCE(i.quantity, 0) * p.unit_price as total_value,
                    CASE 
                        WHEN COALESCE(i.quantity, 0) <= p.reorder_level THEN 'Low Stock'
                        WHEN COALESCE(i.quantity, 0) = 0 THEN 'Out of Stock'
                        ELSE 'In Stock'
                    END as stock_status,
                    s.name as station_name
                FROM products p
                LEFT JOIN product_types pt ON p.type_id = pt.id
                LEFT JOIN inventory i ON p.id = i.product_id AND $station_clause
                LEFT JOIN stations s ON i.station_id = s.id
                WHERE p.status = 'active'
                ORDER BY 
                    CASE 
                        WHEN COALESCE(i.quantity, 0) = 0 THEN 1
                        WHEN COALESCE(i.quantity, 0) <= p.reorder_level THEN 2
                        ELSE 3
                    END,
                    pt.name, p.name";
        
        if ($station_filter !== 'all') {
            $sql = str_replace('LEFT JOIN stations s ON i.station_id = s.id', 'LEFT JOIN stations s ON i.station_id = s.id', $sql);
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($view === 'stock_movement') {
        $title = "Stock Movement Report";
        $subtitle = "Inventory transactions for " . htmlspecialchars($station_name);
        
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-d');
        
        $station_clause = $station_filter === 'all' ? "1=1" : "im.station_id = ?";
        $params = $station_filter === 'all' ? [$start, $end] : [$start, $end, $station_filter];
        
        $sql = "SELECT 
                    im.id, im.transaction_type, im.quantity, im.reference,
                    im.created_at, im.notes,
                    p.name as product_name, p.sku,
                    COALESCE(pt.name, 'Other') as category,
                    u.username as created_by,
                    s.name as station_name
                FROM inventory_movements im
                JOIN products p ON im.product_id = p.id
                LEFT JOIN product_types pt ON p.type_id = pt.id
                LEFT JOIN users u ON im.user_id = u.id
                LEFT JOIN stations s ON im.station_id = s.id
                WHERE DATE(im.created_at) BETWEEN ? AND ? AND $station_clause
                ORDER BY im.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($view === 'low_stock') {
        $title = "Low Stock Alert Report";
        $subtitle = "Products requiring reordering for " . htmlspecialchars($station_name);
        
        $station_clause = $station_filter === 'all' ? "1=1" : "i.station_id = ?";
        $params = $station_filter === 'all' ? [] : [$station_filter];
        
        $sql = "SELECT 
                    p.id, p.name, p.sku, p.description,
                    COALESCE(pt.name, 'Other') as category,
                    COALESCE(i.quantity, 0) as current_stock,
                    p.reorder_level,
                    p.unit_price,
                    (p.reorder_level - COALESCE(i.quantity, 0)) as needed_quantity,
                    (p.reorder_level - COALESCE(i.quantity, 0)) * p.unit_price as reorder_value,
                    s.name as station_name
                FROM products p
                LEFT JOIN product_types pt ON p.type_id = pt.id
                LEFT JOIN inventory i ON p.id = i.product_id AND $station_clause
                LEFT JOIN stations s ON i.station_id = s.id
                WHERE p.status = 'active' 
                AND (i.quantity IS NULL OR i.quantity <= p.reorder_level)
                ORDER BY (p.reorder_level - COALESCE(i.quantity, 0)) DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch(Exception $e) {
    $error = "Error generating report: " . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-boxes"></i> <?php echo htmlspecialchars($title); ?></h1>
    <div class="sub"><?php echo htmlspecialchars($subtitle); ?></div>
  </div>
  <div style="display: flex; gap: 10px; align-items: center;">
    <span class="badge" style="background: #3b82f6; color: white; padding: 6px 12px; border-radius: 4px;">
      <i class="fas fa-building"></i> <?php echo htmlspecialchars($station_name); ?>
    </span>
    <span class="badge" style="background: #10b981; color: white; padding: 6px 12px; border-radius: 4px;">
      <i class="fas fa-clock"></i> Last Updated: <?php echo date('M j, Y H:i'); ?>
    </span>
    <span class="muted" style="font-size: 12px;"><?php echo count($data); ?> Records</span>
  </div>
</div>

<!-- Report Controls -->
<div class="card" style="margin-bottom: 20px;">
  <div style="display: flex; gap: 10px; padding: 16px; flex-wrap: wrap; align-items: center;">
    <div style="display: flex; gap: 5px;">
      <a class="btn <?php echo $view === 'stock_levels' ? 'primary' : 'ghost'; ?>" href="inventory_reports.php?view=stock_levels">
        <i class="fas fa-cubes"></i> Stock Levels
      </a>
      <a class="btn <?php echo $view === 'stock_movement' ? 'primary' : 'ghost'; ?>" href="inventory_reports.php?view=stock_movement">
        <i class="fas fa-exchange-alt"></i> Stock Movement
      </a>
      <a class="btn <?php echo $view === 'low_stock' ? 'primary' : 'ghost'; ?>" href="inventory_reports.php?view=low_stock">
        <i class="fas fa-exclamation-triangle"></i> Low Stock
      </a>
    </div>
    
    <?php if ($view === 'stock_movement'): ?>
    <div style="display: flex; gap: 5px; align-items: center;">
      <label style="font-size: 12px; color: #64748b;">Period:</label>
      <input type="date" name="start" value="<?php echo $_GET['start'] ?? date('Y-m-01'); ?>" onchange="updateDateRange(this.value, document.querySelector('input[name=\"end\"]').value)">
      <span style="color: #64748b;">to</span>
      <input type="date" name="end" value="<?php echo $_GET['end'] ?? date('Y-m-d'); ?>" onchange="updateDateRange(document.querySelector('input[name=\"start\"]').value, this.value)">
    </div>
    <?php endif; ?>
    
    <?php if (in_array($role, ['admin', 'superadmin'])): ?>
    <div style="display: flex; gap: 5px; align-items: center;">
      <label style="font-size: 12px; color: #64748b;">Station:</label>
      <select name="station_id" onchange="updateStation(this.value)">
        <option value="all" <?php echo $station_filter === 'all' ? 'selected' : ''; ?>>All Stations</option>
        <?php
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM stations WHERE status = 'active' ORDER BY name");
            $stmt->execute();
            $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($stations as $station) {
                $selected = $station_filter == $station['id'] ? 'selected' : '';
                echo "<option value=\"{$station['id']}\" $selected>" . htmlspecialchars($station['name']) . "</option>";
            }
        } catch(Exception $e) {}
        ?>
      </select>
    </div>
    <?php endif; ?>
    
    <button class="btn primary" onclick="exportReport()">
      <i class="fas fa-download"></i> Export
    </button>
  </div>
</div>

<!-- Report Content -->
<div class="card">
  <div class="card-head">
    <div class="card-title"><i class="fas fa-warehouse"></i> Inventory Analysis</div>
    <div class="muted">Stock status and movement tracking</div>
  </div>
  <div style="padding: 20px;">
    <?php if(isset($error)): ?>
      <div class="alert" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>
    
    <?php if(empty($data)): ?>
      <p class="muted" style="text-align: center; padding: 40px;">No inventory data available.</p>
    <?php else: ?>
      <?php if ($view === 'stock_levels'): ?>
        <?php
        $total_value = 0;
        $low_stock_count = 0;
        $out_of_stock_count = 0;
        
        foreach($data as $row) {
            $total_value += $row['total_value'];
            if ($row['stock_status'] === 'Low Stock') $low_stock_count++;
            if ($row['stock_status'] === 'Out of Stock') $out_of_stock_count++;
        }
        ?>
        
        <!-- Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
          <div class="card" style="background: #f8fafc; border: 1px solid #e2e8f0;">
            <div style="padding: 15px;">
              <div style="color: #64748b; font-size: 12px; margin-bottom: 5px;">Total Products</div>
              <div style="font-size: 24px; font-weight: bold; color: #1e293b;"><?php echo count($data); ?></div>
            </div>
          </div>
          <div class="card" style="background: #fef3c7; border: 1px solid #fbbf24;">
            <div style="padding: 15px;">
              <div style="color: #92400e; font-size: 12px; margin-bottom: 5px;">Low Stock Items</div>
              <div style="font-size: 24px; font-weight: bold; color: #92400e;"><?php echo $low_stock_count; ?></div>
            </div>
          </div>
          <div class="card" style="background: #fee2e2; border: 1px solid #f87171;">
            <div style="padding: 15px;">
              <div style="color: #991b1b; font-size: 12px; margin-bottom: 5px;">Out of Stock</div>
              <div style="font-size: 24px; font-weight: bold; color: #991b1b;"><?php echo $out_of_stock_count; ?></div>
            </div>
          </div>
          <div class="card" style="background: #ecfdf5; border: 1px solid #34d399;">
            <div style="padding: 15px;">
              <div style="color: #065f46; font-size: 12px; margin-bottom: 5px;">Total Value</div>
              <div style="font-size: 20px; font-weight: bold; color: #065f46;">₱<?php echo number_format($total_value, 2); ?></div>
            </div>
          </div>
        </div>
        
        <table class="data-table" style="width: 100%;">
          <thead>
            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
              <th style="padding: 12px; text-align: left;">Product</th>
              <th style="padding: 12px; text-align: left;">Category</th>
              <th style="padding: 12px; text-align: center;">Current Stock</th>
              <th style="padding: 12px; text-align: center;">Reorder Level</th>
              <th style="padding: 12px; text-align: right;">Unit Price</th>
              <th style="padding: 12px; text-align: right;">Total Value</th>
              <th style="padding: 12px; text-align: center;">Status</th>
              <?php if ($station_filter === 'all'): ?>
              <th style="padding: 12px; text-align: left;">Station</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach($data as $row): 
                $status_color = $row['stock_status'] === 'Out of Stock' ? '#dc2626' : 
                               ($row['stock_status'] === 'Low Stock' ? '#f59e0b' : '#10b981');
            ?>
              <tr style="border-bottom: 1px solid #e2e8f0;">
                <td style="padding: 12px;">
                  <div>
                    <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                    <?php if ($row['sku']): ?>
                    <div style="font-size: 11px; color: #64748b;">SKU: <?php echo htmlspecialchars($row['sku']); ?></div>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="padding: 12px;">
                  <span class="badge" style="background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                    <?php echo htmlspecialchars($row['category']); ?>
                  </span>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <strong><?php echo number_format($row['current_stock']); ?></strong>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <?php echo number_format($row['reorder_level']); ?>
                </td>
                <td style="padding: 12px; text-align: right;">
                  ₱<?php echo number_format($row['unit_price'], 2); ?>
                </td>
                <td style="padding: 12px; text-align: right;">
                  <strong>₱<?php echo number_format($row['total_value'], 2); ?></strong>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <span style="color: <?php echo $status_color; ?>; font-weight: 600; font-size: 12px;">
                    <?php echo htmlspecialchars($row['stock_status']); ?>
                  </span>
                </td>
                <?php if ($station_filter === 'all'): ?>
                <td style="padding: 12px;"><?php echo htmlspecialchars($row['station_name'] ?? 'N/A'); ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        
      <?php elseif ($view === 'stock_movement'): ?>
        <table class="data-table" style="width: 100%;">
          <thead>
            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
              <th style="padding: 12px; text-align: left;">Date & Time</th>
              <th style="padding: 12px; text-align: left;">Product</th>
              <th style="padding: 12px; text-align: left;">Type</th>
              <th style="padding: 12px; text-align: center;">Quantity</th>
              <th style="padding: 12px; text-align: left;">Reference</th>
              <th style="padding: 12px; text-align: left;">Created By</th>
              <?php if ($station_filter === 'all'): ?>
              <th style="padding: 12px; text-align: left;">Station</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach($data as $row): 
                $type_color = $row['transaction_type'] === 'IN' ? '#10b981' : '#ef4444';
            ?>
              <tr style="border-bottom: 1px solid #e2e8f0;">
                <td style="padding: 12px;">
                  <div>
                    <strong><?php echo date('M j, Y H:i', strtotime($row['created_at'])); ?></strong>
                    <div style="font-size: 11px; color: #64748b;"><?php echo date('Y-m-d H:i:s', strtotime($row['created_at'])); ?></div>
                  </div>
                </td>
                <td style="padding: 12px;">
                  <div>
                    <strong><?php echo htmlspecialchars($row['product_name']); ?></strong>
                    <div style="font-size: 11px; color: #64748b;">SKU: <?php echo htmlspecialchars($row['sku']); ?></div>
                  </div>
                </td>
                <td style="padding: 12px;">
                  <span class="badge" style="background: <?php echo $type_color; ?>20; color: <?php echo $type_color; ?>; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                    <?php echo htmlspecialchars($row['transaction_type']); ?>
                  </span>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <strong style="color: <?php echo $type_color; ?>;">
                    <?php echo ($row['transaction_type'] === 'IN' ? '+' : '-') . number_format($row['quantity']); ?>
                  </strong>
                </td>
                <td style="padding: 12px;">
                  <?php echo htmlspecialchars($row['reference'] ?: 'N/A'); ?>
                </td>
                <td style="padding: 12px;">
                  <?php echo htmlspecialchars($row['created_by'] ?: 'System'); ?>
                </td>
                <?php if ($station_filter === 'all'): ?>
                <td style="padding: 12px;"><?php echo htmlspecialchars($row['station_name'] ?? 'N/A'); ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        
      <?php elseif ($view === 'low_stock'): ?>
        <?php
        $total_needed = 0;
        $total_reorder_value = 0;
        
        foreach($data as $row) {
            $total_needed += $row['needed_quantity'];
            $total_reorder_value += $row['reorder_value'];
        }
        ?>
        
        <!-- Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
          <div class="card" style="background: #fef3c7; border: 1px solid #fbbf24;">
            <div style="padding: 15px;">
              <div style="color: #92400e; font-size: 12px; margin-bottom: 5px;">Items Need Reorder</div>
              <div style="font-size: 24px; font-weight: bold; color: #92400e;"><?php echo count($data); ?></div>
            </div>
          </div>
          <div class="card" style="background: #fef3c7; border: 1px solid #fbbf24;">
            <div style="padding: 15px;">
              <div style="color: #92400e; font-size: 12px; margin-bottom: 5px;">Total Units Needed</div>
              <div style="font-size: 24px; font-weight: bold; color: #92400e;"><?php echo number_format($total_needed); ?></div>
            </div>
          </div>
          <div class="card" style="background: #fef3c7; border: 1px solid #fbbf24;">
            <div style="padding: 15px;">
              <div style="color: #92400e; font-size: 12px; margin-bottom: 5px;">Reorder Value</div>
              <div style="font-size: 20px; font-weight: bold; color: #92400e;">₱<?php echo number_format($total_reorder_value, 2); ?></div>
            </div>
          </div>
        </div>
        
        <table class="data-table" style="width: 100%;">
          <thead>
            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
              <th style="padding: 12px; text-align: left;">Product</th>
              <th style="padding: 12px; text-align: left;">Category</th>
              <th style="padding: 12px; text-align: center;">Current Stock</th>
              <th style="padding: 12px; text-align: center;">Reorder Level</th>
              <th style="padding: 12px; text-align: center;">Needed Qty</th>
              <th style="padding: 12px; text-align: right;">Reorder Value</th>
              <?php if ($station_filter === 'all'): ?>
              <th style="padding: 12px; text-align: left;">Station</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach($data as $row): ?>
              <tr style="border-bottom: 1px solid #e2e8f0;">
                <td style="padding: 12px;">
                  <div>
                    <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                    <?php if ($row['sku']): ?>
                    <div style="font-size: 11px; color: #64748b;">SKU: <?php echo htmlspecialchars($row['sku']); ?></div>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="padding: 12px;">
                  <span class="badge" style="background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                    <?php echo htmlspecialchars($row['category']); ?>
                  </span>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <strong style="color: #ef4444;"><?php echo number_format($row['current_stock']); ?></strong>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <?php echo number_format($row['reorder_level']); ?>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <strong style="color: #f59e0b;"><?php echo number_format($row['needed_quantity']); ?></strong>
                </td>
                <td style="padding: 12px; text-align: right;">
                  <strong style="color: #f59e0b;">₱<?php echo number_format($row['reorder_value'], 2); ?></strong>
                </td>
                <?php if ($station_filter === 'all'): ?>
                <td style="padding: 12px;"><?php echo htmlspecialchars($row['station_name'] ?? 'N/A'); ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
function updateDateRange(start, end) {
    const url = new URL(window.location);
    url.searchParams.set('start', start);
    url.searchParams.set('end', end);
    window.location.href = url.toString();
}

function updateStation(stationId) {
    const url = new URL(window.location);
    if (stationId === 'all') {
        url.searchParams.delete('station_id');
    } else {
        url.searchParams.set('station_id', stationId);
    }
    window.location.href = url.toString();
}

function exportReport() {
    const url = new URL(window.location);
    url.searchParams.set('export', '1');
    window.open(url.toString(), '_blank');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
