<?php
/**
 * FUEL MONITORING MODULE
 * 
 * Manager View: Daily readings, shift comparisons, calibration logs
 * Station-specific: All data filtered by user's assigned station_id
 * Real-time monitoring of fuel inventory and variance
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_monitoring';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
$me = current_user();

$role = role_key($me['role'] ?? 'staff');
if (!in_array($role, ['manager','admin','superadmin'])) { header("Location: dashboard.php"); exit; }

$station_id = user_station_id();
$view = $_GET['view'] ?? 'daily';

// Fetch station info
$station_name = 'Station';
try {
    $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
    $stmt->execute([$station_id]);
    $station = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($station) $station_name = $station['name'];
} catch(Exception $e) {}

// Fetch fuel inventory for this station
$fuel_inventory = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            fi.id, fi.product_id, fi.stock_level, fi.capacity, fi.reorder_level,
            p.name as fuel_name, p.sku
        FROM fuel_inventory fi
        JOIN products p ON fi.product_id = p.id
        WHERE fi.station_id = ?
        ORDER BY p.name
    ");
    $stmt->execute([$station_id]);
    $fuel_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Fetch fuel pumps for this station
$fuel_pumps = [];
try {
    $stmt = $pdo->prepare("SELECT id, pump_number, status FROM fuel_pumps WHERE station_id = ? ORDER BY pump_number");
    $stmt->execute([$station_id]);
    $fuel_pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Fetch daily readings
$daily_readings = [];
if ($view === 'daily') {
    try {
        $sql = "SELECT 
                fdr.id, fdr.reading_date, fdr.shift, 
                fdr.previous_reading, fdr.current_reading, fdr.sales_liters,
                fdr.status, fdr.notes, fdr.created_at,
                fp.pump_number,
                ft.name as fuel_type,
                u.username as recorded_by
            FROM fuel_daily_readings fdr
            JOIN fuel_pumps fp ON fdr.pump_id = fp.id
            JOIN fuel_types ft ON fp.fuel_type_id = ft.id
            LEFT JOIN users u ON fdr.user_id = u.id
            WHERE fdr.station_id = ?
            ORDER BY fdr.reading_date DESC, fdr.shift DESC, fp.pump_number
            LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id]);
        $daily_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

// Fetch shift comparison data
$shift_comparison = [];
if ($view === 'shift_compare') {
    try {
        $sql = "SELECT 
                fdr.reading_date, fdr.shift,
                ft.name as fuel_type,
                SUM(fdr.current_reading - fdr.previous_reading) as total_dispensed,
                SUM(fdr.sales_liters) as total_sales,
                SUM((fdr.current_reading - fdr.previous_reading) - fdr.sales_liters) as variance,
                COUNT(DISTINCT fdr.pump_id) as pumps_recorded
            FROM fuel_daily_readings fdr
            JOIN fuel_pumps fp ON fdr.pump_id = fp.id
            JOIN fuel_types ft ON fp.fuel_type_id = ft.id
            WHERE fdr.station_id = ? AND fdr.reading_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY fdr.reading_date, fdr.shift, ft.name
            ORDER BY fdr.reading_date DESC, fdr.shift DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id]);
        $shift_comparison = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

// Fetch calibration logs
$calibration_logs = [];
if ($view === 'calibration') {
    try {
        $sql = "SELECT 
                fc.id, fc.effective_date as calibration_date,
                NULL as before_reading, NULL as after_reading,
                fc.calibration_constant as adjustment_liters, 
                fc.status as reason, fc.created_at,
                ft.name as fuel_type,
                '' as pump_number,
                '' as performed_by
            FROM fuel_calibration fc
            JOIN fuel_types ft ON fc.fuel_type = ft.name
            WHERE fc.status = 'active'
            ORDER BY fc.effective_date DESC, fc.created_at DESC
            LIMIT 30";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([]);
        $calibration_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-gas-pump"></i> Fuel Monitoring</h1>
    <div class="sub">Real-time fuel inventory tracking for <?php echo htmlspecialchars($station_name); ?></div>
  </div>
  <div style="display: flex; gap: 10px; align-items: center;">
    <span class="badge" style="background: #3b82f6; color: white; padding: 6px 12px; border-radius: 4px;">
      <i class="fas fa-building"></i> <?php echo htmlspecialchars($station_name); ?>
    </span>
    <span class="muted" style="font-size: 12px;"><?php echo count($fuel_pumps); ?> Pumps | <?php echo count($fuel_inventory); ?> Fuel Types</span>
  </div>
</div>

<!-- Tab Navigation -->
<div class="card" style="margin-bottom: 20px;">
  <div style="display: flex; gap: 5px; padding: 16px; flex-wrap: wrap;">
    <a class="btn <?php echo $view === 'daily' ? 'primary' : 'ghost'; ?>" href="fuel_monitoring.php?view=daily">
      <i class="fas fa-calendar-day"></i> Daily Readings
    </a>
    <a class="btn <?php echo $view === 'shift_compare' ? 'primary' : 'ghost'; ?>" href="fuel_monitoring.php?view=shift_compare">
      <i class="fas fa-chart-bar"></i> Shift Comparison
    </a>
    <a class="btn <?php echo $view === 'calibration' ? 'primary' : 'ghost'; ?>" href="fuel_monitoring.php?view=calibration">
      <i class="fas fa-wrench"></i> Calibration Logs
    </a>
    <a class="btn" href="fuel_reconciliation.php" style="margin-left: auto;">
      <i class="fas fa-arrow-right"></i> Open Reconciliation
    </a>
  </div>
</div>

<!-- Fuel Inventory Overview -->
<?php if(!empty($fuel_inventory)): ?>
<div class="card" style="margin-bottom: 20px;">
  <div class="card-head">
    <div class="card-title"><i class="fas fa-cubes"></i> Current Fuel Inventory</div>
    <div class="muted">Stock levels by fuel type at this station</div>
  </div>
  <div style="padding: 20px;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;">
      <?php foreach($fuel_inventory as $fuel): 
        $stock = floatval($fuel['stock_level']);
        $capacity = floatval($fuel['capacity']);
        $reorder = floatval($fuel['reorder_level']);
        $percent = $capacity > 0 ? ($stock / $capacity * 100) : 0;
        
        // Determine status color
        if ($stock <= $reorder) {
          $status_color = '#dc2626'; // red - low stock
          $status_label = 'Low Stock';
        } elseif ($percent >= 80) {
          $status_color = '#10b981'; // green - good
          $status_label = 'Adequate';
        } else {
          $status_color = '#f59e0b'; // amber - moderate
          $status_label = 'Moderate';
        }
      ?>
      <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; background: #f8fafc;">
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
          <div>
            <div style="font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($fuel['fuel_name']); ?></div>
            <div style="font-size: 12px; color: #6b7280;">SKU: <?php echo htmlspecialchars($fuel['sku']); ?></div>
          </div>
          <span style="background: <?php echo $status_color; ?>; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
            <?php echo htmlspecialchars($status_label); ?>
          </span>
        </div>
        
        <!-- Progress bar -->
        <div style="background: #e5e7eb; border-radius: 4px; height: 8px; margin-bottom: 12px; overflow: hidden;">
          <div style="background: <?php echo $status_color; ?>; height: 100%; width: <?php echo min($percent, 100); ?>%;" />
        </div>
        
        <!-- Stock info -->
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <div>
            <div style="font-size: 14px; font-weight: 600; color: #1f2937;">
              <?php echo number_format($stock, 1); ?> L
            </div>
            <div style="font-size: 12px; color: #6b7280;">
              of <?php echo number_format($capacity, 1); ?> L
            </div>
          </div>
          <div style="text-align: right;">
            <div style="font-size: 12px; color: #6b7280;">Reorder at</div>
            <div style="font-size: 13px; font-weight: 600; color: #1f2937;">
              <?php echo number_format($reorder, 1); ?> L
            </div>
          </div>
        </div>
        
        <!-- Percentage -->
        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb; text-align: center; color: #6b7280; font-size: 12px;">
          <?php echo number_format($percent, 1); ?>% Capacity
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if($view === 'daily'): ?>
  <div class="card">
    <div class="card-head">
      <div class="card-title"><i class="fas fa-calendar-day"></i> Daily Readings Summary</div>
      <div class="muted">Recent fuel meter readings by shift and pump</div>
    </div>
    <div style="padding: 20px;">
      <?php if(empty($daily_readings)): ?>
        <p class="muted" style="text-align: center; padding: 40px;">
          <i class="fas fa-info-circle"></i> No daily readings recorded yet for this station.
        </p>
      <?php else: ?>
        <table class="data-table" style="width: 100%;">
          <thead>
            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
              <th style="padding: 12px; text-align: left;">Date</th>
              <th style="padding: 12px; text-align: center;">Shift</th>
              <th style="padding: 12px; text-align: left;">Fuel Type</th>
              <th style="padding: 12px; text-align: center;">Pump</th>
              <th style="padding: 12px; text-align: right;">Previous</th>
              <th style="padding: 12px; text-align: right;">Current</th>
              <th style="padding: 12px; text-align: right;">Dispensed</th>
              <th style="padding: 12px; text-align: center;">Status</th>
              <th style="padding: 12px; text-align: left;">Recorded By</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($daily_readings as $reading): 
              $dispensed = $reading['current_reading'] - $reading['previous_reading'];
              $status_colors = [
                'Pending' => '#f59e0b',
                'Verified' => '#3b82f6',
                'Finalized' => '#10b981'
              ];
              $status_color = $status_colors[$reading['status']] ?? '#6b7280';
            ?>
            <tr style="border-bottom: 1px solid #e2e8f0;">
              <td style="padding: 12px;">
                <strong><?php echo date('M d, Y', strtotime($reading['reading_date'])); ?></strong>
              </td>
              <td style="padding: 12px; text-align: center;">
                <span class="badge" style="background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                  <?php echo htmlspecialchars($reading['shift']); ?>
                </span>
              </td>
              <td style="padding: 12px;">
                <strong><?php echo htmlspecialchars($reading['fuel_type']); ?></strong>
              </td>
              <td style="padding: 12px; text-align: center;">
                <span style="font-weight: 600;">#<?php echo htmlspecialchars($reading['pump_number']); ?></span>
              </td>
              <td style="padding: 12px; text-align: right;">
                <?php echo number_format($reading['previous_reading'], 2); ?> L
              </td>
              <td style="padding: 12px; text-align: right;">
                <?php echo number_format($reading['current_reading'], 2); ?> L
              </td>
              <td style="padding: 12px; text-align: right;">
                <strong style="color: #059669;"><?php echo number_format($dispensed, 2); ?> L</strong>
              </td>
              <td style="padding: 12px; text-align: center;">
                <span style="color: <?php echo $status_color; ?>; font-weight: 600;">
                  <?php echo htmlspecialchars($reading['status']); ?>
                </span>
              </td>
              <td style="padding: 12px;">
                <?php echo htmlspecialchars($reading['recorded_by'] ?? 'N/A'); ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

<?php elseif($view === 'shift_compare'): ?>
  <div class="card">
    <div class="card-head">
      <div class="card-title"><i class="fas fa-chart-bar"></i> Shift Comparison Reports</div>
      <div class="muted">Compare fuel dispensed vs sales by shift (Last 7 days)</div>
    </div>
    <div style="padding: 20px;">
      <?php if(empty($shift_comparison)): ?>
        <p class="muted" style="text-align: center; padding: 40px;">
          <i class="fas fa-info-circle"></i> No shift comparison data available for the past 7 days.
        </p>
      <?php else: ?>
        <table class="data-table" style="width: 100%;">
          <thead>
            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
              <th style="padding: 12px; text-align: left;">Date</th>
              <th style="padding: 12px; text-align: center;">Shift</th>
              <th style="padding: 12px; text-align: left;">Fuel Type</th>
              <th style="padding: 12px; text-align: right;">Dispensed</th>
              <th style="padding: 12px; text-align: right;">Sales</th>
              <th style="padding: 12px; text-align: right;">Variance</th>
              <th style="padding: 12px; text-align: center;">Pumps</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($shift_comparison as $comp): 
              $variance = floatval($comp['variance']);
              $variance_color = abs($variance) < 5 ? '#10b981' : (abs($variance) < 10 ? '#f59e0b' : '#dc2626');
            ?>
            <tr style="border-bottom: 1px solid #e2e8f0;">
              <td style="padding: 12px;">
                <strong><?php echo date('M d, Y', strtotime($comp['reading_date'])); ?></strong>
              </td>
              <td style="padding: 12px; text-align: center;">
                <span class="badge" style="background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                  <?php echo htmlspecialchars($comp['shift']); ?>
                </span>
              </td>
              <td style="padding: 12px;">
                <strong><?php echo htmlspecialchars($comp['fuel_type']); ?></strong>
              </td>
              <td style="padding: 12px; text-align: right;">
                <strong><?php echo number_format($comp['total_dispensed'], 2); ?> L</strong>
              </td>
              <td style="padding: 12px; text-align: right;">
                <strong><?php echo number_format($comp['total_sales'], 2); ?> L</strong>
              </td>
              <td style="padding: 12px; text-align: right;">
                <strong style="color: <?php echo $variance_color; ?>;">
                  <?php echo $variance >= 0 ? '+' : ''; ?><?php echo number_format($variance, 2); ?> L
                </strong>
              </td>
              <td style="padding: 12px; text-align: center;">
                <?php echo number_format($comp['pumps_recorded']); ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

<?php elseif($view === 'calibration'): ?>
  <div class="card">
    <div class="card-head">
      <div class="card-title"><i class="fas fa-wrench"></i> Calibration Logs</div>
      <div class="muted">Pump calibration and adjustment history</div>
    </div>
    <div style="padding: 20px;">
      <?php if(empty($calibration_logs)): ?>
        <p class="muted" style="text-align: center; padding: 40px;">
          <i class="fas fa-info-circle"></i> No calibration logs recorded for this station.
        </p>
      <?php else: ?>
        <table class="data-table" style="width: 100%;">
          <thead>
            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
              <th style="padding: 12px; text-align: left;">Date</th>
              <th style="padding: 12px; text-align: left;">Fuel Type</th>
              <th style="padding: 12px; text-align: center;">Pump</th>
              <th style="padding: 12px; text-align: right;">Before</th>
              <th style="padding: 12px; text-align: right;">After</th>
              <th style="padding: 12px; text-align: right;">Adjustment</th>
              <th style="padding: 12px; text-align: left;">Reason</th>
              <th style="padding: 12px; text-align: left;">Performed By</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($calibration_logs as $log): 
              $adjustment = floatval($log['adjustment_liters']);
              $adj_color = $adjustment >= 0 ? '#10b981' : '#dc2626';
            ?>
            <tr style="border-bottom: 1px solid #e2e8f0;">
              <td style="padding: 12px;">
                <strong><?php echo date('M d, Y', strtotime($log['calibration_date'])); ?></strong>
              </td>
              <td style="padding: 12px;">
                <strong><?php echo htmlspecialchars($log['fuel_type']); ?></strong>
              </td>
              <td style="padding: 12px; text-align: center;">
                <span style="font-weight: 600;">#<?php echo htmlspecialchars($log['pump_number']); ?></span>
              </td>
              <td style="padding: 12px; text-align: right;">
                <?php echo number_format($log['before_reading'], 2); ?> L
              </td>
              <td style="padding: 12px; text-align: right;">
                <?php echo number_format($log['after_reading'], 2); ?> L
              </td>
              <td style="padding: 12px; text-align: right;">
                <strong style="color: <?php echo $adj_color; ?>;">
                  <?php echo $adjustment >= 0 ? '+' : ''; ?><?php echo number_format($adjustment, 2); ?> L
                </strong>
              </td>
              <td style="padding: 12px;">
                <span class="muted"><?php echo htmlspecialchars($log['reason'] ?? 'No reason provided'); ?></span>
              </td>
              <td style="padding: 12px;">
                <?php echo htmlspecialchars($log['performed_by'] ?? 'N/A'); ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
