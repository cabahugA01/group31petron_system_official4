<?php
/**
 * PROFIT & LOSS REPORTS
 * 
 * Financial reporting for Admin and Manager roles
 * Station-specific filtering for managers
 * Nationwide view for admins
 */
$page_id = 'profit_loss_reports';
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
$view = $_GET['view'] ?? 'monthly';
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
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
    if ($view === 'monthly') {
        $title = "Monthly Profit & Loss Report";
        $subtitle = "Financial performance for " . htmlspecialchars($station_name);
        
        $station_clause = $station_filter === 'all' ? "1=1" : "s.station_id = ?";
        $params = $station_filter === 'all' ? [] : [$station_filter];
        
        $sql = "SELECT 
                    DATE_FORMAT(s.sale_date, '%Y-%m') as period,
                    SUM(s.total) as revenue,
                    COALESCE(SUM(si.cost_price * si.quantity), 0) as cost_of_goods,
                    (SUM(s.total) - COALESCE(SUM(si.cost_price * si.quantity), 0)) as gross_profit,
                    COUNT(DISTINCT s.id) as transaction_count
                FROM sales s
                LEFT JOIN sale_items si ON s.id = si.sale_id
                WHERE s.sale_date BETWEEN ? AND ? AND $station_clause
                GROUP BY DATE_FORMAT(s.sale_date, '%Y-%m')
                ORDER BY period DESC";
        
        array_unshift($params, $start, $end);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate profit margins
        foreach ($data as &$row) {
            $row['profit_margin'] = $row['revenue'] > 0 ? round(($row['gross_profit'] / $row['revenue']) * 100, 2) : 0;
        }
        
    } elseif ($view === 'category') {
        $title = "Category Profit & Loss Report";
        $subtitle = "Profitability by product category for " . htmlspecialchars($station_name);
        
        $station_clause = $station_filter === 'all' ? "1=1" : "s.station_id = ?";
        $params = $station_filter === 'all' ? [] : [$station_filter];
        
        $sql = "SELECT 
                    COALESCE(pt.name, 'Other') as category,
                    SUM(s.total) as revenue,
                    COALESCE(SUM(si.cost_price * si.quantity), 0) as cost_of_goods,
                    (SUM(s.total) - COALESCE(SUM(si.cost_price * si.quantity), 0)) as gross_profit,
                    COUNT(DISTINCT s.id) as transaction_count,
                    SUM(si.quantity) as units_sold
                FROM sales s
                LEFT JOIN sale_items si ON s.id = si.sale_id
                LEFT JOIN products p ON si.product_id = p.id
                LEFT JOIN product_types pt ON p.type_id = pt.id
                WHERE s.sale_date BETWEEN ? AND ? AND $station_clause
                GROUP BY COALESCE(pt.name, 'Other')
                ORDER BY gross_profit DESC";
        
        array_unshift($params, $start, $end);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate profit margins
        foreach ($data as &$row) {
            $row['profit_margin'] = $row['revenue'] > 0 ? round(($row['gross_profit'] / $row['revenue']) * 100, 2) : 0;
        }
    }
    
} catch(Exception $e) {
    $error = "Error generating report: " . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-chart-line"></i> <?php echo htmlspecialchars($title); ?></h1>
    <div class="sub"><?php echo htmlspecialchars($subtitle); ?></div>
  </div>
  <div style="display: flex; gap: 10px; align-items: center;">
    <span class="badge" style="background: #10b981; color: white; padding: 6px 12px; border-radius: 4px;">
      <i class="fas fa-calendar"></i> <?php echo htmlspecialchars($start); ?> to <?php echo htmlspecialchars($end); ?>
    </span>
    <span class="badge" style="background: #3b82f6; color: white; padding: 6px 12px; border-radius: 4px;">
      <i class="fas fa-clock"></i> Last Updated: <?php echo date('M j, Y H:i'); ?>
    </span>
    <span class="muted" style="font-size: 12px;"><?php echo count($data); ?> Records</span>
  </div>
</div>

<!-- Report Controls -->
<div class="card" style="margin-bottom: 20px;">
  <div style="display: flex; gap: 10px; padding: 16px; flex-wrap: wrap; align-items: center;">
    <div style="display: flex; gap: 5px;">
      <a class="btn <?php echo $view === 'monthly' ? 'primary' : 'ghost'; ?>" href="profit_loss_reports.php?view=monthly&start=<?php echo $start; ?>&end=<?php echo $end; ?>">
        <i class="fas fa-calendar-alt"></i> Monthly
      </a>
      <a class="btn <?php echo $view === 'category' ? 'primary' : 'ghost'; ?>" href="profit_loss_reports.php?view=category&start=<?php echo $start; ?>&end=<?php echo $end; ?>">
        <i class="fas fa-tags"></i> By Category
      </a>
    </div>
    
    <div style="display: flex; gap: 5px; align-items: center;">
      <label style="font-size: 12px; color: #64748b;">Period:</label>
      <input type="date" name="start" value="<?php echo $start; ?>" onchange="updateDateRange(this.value, document.querySelector('input[name=\"end\"]').value)">
      <span style="color: #64748b;">to</span>
      <input type="date" name="end" value="<?php echo $end; ?>" onchange="updateDateRange(document.querySelector('input[name=\"start\"]').value, this.value)">
    </div>
    
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
    <div class="card-title"><i class="fas fa-chart-pie"></i> Financial Analysis</div>
    <div class="muted">Profitability metrics and performance indicators</div>
  </div>
  <div style="padding: 20px;">
    <?php if(isset($error)): ?>
      <div class="alert" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>
    
    <?php if(empty($data)): ?>
      <p class="muted" style="text-align: center; padding: 40px;">No financial data available for the selected period.</p>
    <?php else: ?>
      <?php if ($view === 'monthly'): ?>
        <table class="data-table" style="width: 100%;">
          <thead>
            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
              <th style="padding: 12px; text-align: left;">Period</th>
              <th style="padding: 12px; text-align: right;">Revenue</th>
              <th style="padding: 12px; text-align: right;">Cost of Goods</th>
              <th style="padding: 12px; text-align: right;">Gross Profit</th>
              <th style="padding: 12px; text-align: right;">Profit Margin</th>
              <th style="padding: 12px; text-align: center;">Transactions</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $total_revenue = 0;
            $total_cogs = 0;
            $total_profit = 0;
            $total_transactions = 0;
            
            foreach($data as $row): 
                $total_revenue += $row['revenue'];
                $total_cogs += $row['cost_of_goods'];
                $total_profit += $row['gross_profit'];
                $total_transactions += $row['transaction_count'];
                
                $profit_color = $row['gross_profit'] >= 0 ? '#10b981' : '#ef4444';
                $margin_color = $row['profit_margin'] >= 20 ? '#10b981' : ($row['profit_margin'] >= 10 ? '#f59e0b' : '#ef4444');
            ?>
              <tr style="border-bottom: 1px solid #e2e8f0;">
                <td style="padding: 12px;">
                  <strong><?php echo htmlspecialchars($row['period']); ?></strong>
                </td>
                <td style="padding: 12px; text-align: right;">
                  <strong>₱<?php echo number_format($row['revenue'], 2); ?></strong>
                </td>
                <td style="padding: 12px; text-align: right;">
                  ₱<?php echo number_format($row['cost_of_goods'], 2); ?>
                </td>
                <td style="padding: 12px; text-align: right; color: <?php echo $profit_color; ?>; font-weight: 600;">
                  ₱<?php echo number_format($row['gross_profit'], 2); ?>
                </td>
                <td style="padding: 12px; text-align: right;">
                  <span style="color: <?php echo $margin_color; ?>; font-weight: 600;">
                    <?php echo $row['profit_margin']; ?>%
                  </span>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <?php echo number_format($row['transaction_count']); ?>
                </td>
              </tr>
            <?php endforeach; ?>
            
            <!-- Totals Row -->
            <tr style="background: #f1f5f9; border-top: 2px solid #e2e8f0; font-weight: bold;">
              <td style="padding: 12px;">TOTAL</td>
              <td style="padding: 12px; text-align: right;">₱<?php echo number_format($total_revenue, 2); ?></td>
              <td style="padding: 12px; text-align: right;">₱<?php echo number_format($total_cogs, 2); ?></td>
              <td style="padding: 12px; text-align: right; color: <?php echo $total_profit >= 0 ? '#10b981' : '#ef4444'; ?>;">
                ₱<?php echo number_format($total_profit, 2); ?>
              </td>
              <td style="padding: 12px; text-align: right;">
                <?php echo $total_revenue > 0 ? round(($total_profit / $total_revenue) * 100, 2) : 0; ?>%
              </td>
              <td style="padding: 12px; text-align: center;"><?php echo number_format($total_transactions); ?></td>
            </tr>
          </tbody>
        </table>
        
      <?php elseif ($view === 'category'): ?>
        <table class="data-table" style="width: 100%;">
          <thead>
            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
              <th style="padding: 12px; text-align: left;">Category</th>
              <th style="padding: 12px; text-align: right;">Revenue</th>
              <th style="padding: 12px; text-align: right;">Cost of Goods</th>
              <th style="padding: 12px; text-align: right;">Gross Profit</th>
              <th style="padding: 12px; text-align: right;">Profit Margin</th>
              <th style="padding: 12px; text-align: center;">Units Sold</th>
              <th style="padding: 12px; text-align: center;">Transactions</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $total_revenue = 0;
            $total_cogs = 0;
            $total_profit = 0;
            $total_units = 0;
            $total_transactions = 0;
            
            foreach($data as $row): 
                $total_revenue += $row['revenue'];
                $total_cogs += $row['cost_of_goods'];
                $total_profit += $row['gross_profit'];
                $total_units += $row['units_sold'];
                $total_transactions += $row['transaction_count'];
                
                $profit_color = $row['gross_profit'] >= 0 ? '#10b981' : '#ef4444';
                $margin_color = $row['profit_margin'] >= 20 ? '#10b981' : ($row['profit_margin'] >= 10 ? '#f59e0b' : '#ef4444');
            ?>
              <tr style="border-bottom: 1px solid #e2e8f0;">
                <td style="padding: 12px;">
                  <strong><?php echo htmlspecialchars($row['category']); ?></strong>
                </td>
                <td style="padding: 12px; text-align: right;">
                  <strong>₱<?php echo number_format($row['revenue'], 2); ?></strong>
                </td>
                <td style="padding: 12px; text-align: right;">
                  ₱<?php echo number_format($row['cost_of_goods'], 2); ?>
                </td>
                <td style="padding: 12px; text-align: right; color: <?php echo $profit_color; ?>; font-weight: 600;">
                  ₱<?php echo number_format($row['gross_profit'], 2); ?>
                </td>
                <td style="padding: 12px; text-align: right;">
                  <span style="color: <?php echo $margin_color; ?>; font-weight: 600;">
                    <?php echo $row['profit_margin']; ?>%
                  </span>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <?php echo number_format($row['units_sold']); ?>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <?php echo number_format($row['transaction_count']); ?>
                </td>
              </tr>
            <?php endforeach; ?>
            
            <!-- Totals Row -->
            <tr style="background: #f1f5f9; border-top: 2px solid #e2e8f0; font-weight: bold;">
              <td style="padding: 12px;">TOTAL</td>
              <td style="padding: 12px; text-align: right;">₱<?php echo number_format($total_revenue, 2); ?></td>
              <td style="padding: 12px; text-align: right;">₱<?php echo number_format($total_cogs, 2); ?></td>
              <td style="padding: 12px; text-align: right; color: <?php echo $total_profit >= 0 ? '#10b981' : '#ef4444'; ?>;">
                ₱<?php echo number_format($total_profit, 2); ?>
              </td>
              <td style="padding: 12px; text-align: right;">
                <?php echo $total_revenue > 0 ? round(($total_profit / $total_revenue) * 100, 2) : 0; ?>%
              </td>
              <td style="padding: 12px; text-align: center;"><?php echo number_format($total_units); ?></td>
              <td style="padding: 12px; text-align: center;"><?php echo number_format($total_transactions); ?></td>
            </tr>
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
