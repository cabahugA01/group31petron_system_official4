<?php
/**
 * Fuel Variance Report
 * 
 * Compares POS recorded fuel sales against pump readings to identify
 * discrepancies that may indicate calibration issues or data entry errors.
 */

$page_id = 'reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Only managers and admins can view reports
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    die('Access denied. Only managers and administrators can view reports.');
}

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$pump_id_filter = $_GET['pump_id'] ?? null;

// Get all pumps for filter dropdown
$stmt = $pdo->prepare("SELECT id, pump_number FROM fuel_pumps WHERE station_id = ? ORDER BY pump_number");
$stmt->execute([$station_id]);
$all_pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch pump readings for the date range
$sql_readings = "SELECT 
                    fdr.id,
                    fdr.pump_id,
                    fp.pump_number,
                    ft.name as fuel_type,
                    fdr.reading_date,
                    fdr.previous_reading,
                    fdr.current_reading,
                    (fdr.current_reading - fdr.previous_reading) as pump_liters_sold
                FROM fuel_daily_readings fdr
                LEFT JOIN fuel_pumps fp ON fdr.pump_id = fp.id
                LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
                WHERE fdr.station_id = ? 
                        AND fdr.reading_date >= ? 
                        AND fdr.reading_date <= ? 
                        AND fdr.status = 'verified'";

$params_readings = [$station_id, $start_date, $end_date];

if ($pump_id_filter) {
    $sql_readings .= " AND fdr.pump_id = ?";
    $params_readings[] = $pump_id_filter;
}

$sql_readings .= " ORDER BY fdr.reading_date DESC, fp.pump_number ASC";

$stmt = $pdo->prepare($sql_readings);
$stmt->execute($params_readings);
$pump_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// For each pump reading, get corresponding POS sales
$variance_data = [];
foreach ($pump_readings as $reading) {
    // Get POS sales for this pump on this date
    $sql_pos = "SELECT 
                    COALESCE(SUM(si.quantity), 0) as pos_liters,
                    COALESCE(SUM(si.total_amount), 0) as pos_revenue
                FROM sale_items si
                LEFT JOIN sales s ON si.sale_id = s.id
                WHERE si.pump_id = ? 
                  AND s.sale_date = ?
                  AND s.station_id = ?";
    
    $stmt = $pdo->prepare($sql_pos);
    $stmt->execute([$reading['pump_id'], $reading['reading_date'], $station_id]);
    $pos_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $pump_liters = $reading['pump_liters_sold'] ?? 0;
    $pos_liters = $pos_data['pos_liters'] ?? 0;
    $variance = $pump_liters - $pos_liters;
    $variance_percent = $pump_liters > 0 ? (($variance / $pump_liters) * 100) : 0;
    
    $variance_data[] = [
        'reading_id' => $reading['id'],
        'pump_id' => $reading['pump_id'],
        'pump_number' => $reading['pump_number'],
        'fuel_type' => $reading['fuel_type'],
        'reading_date' => $reading['reading_date'],
        'pump_liters' => $pump_liters,
        'pos_liters' => $pos_liters,
        'variance' => $variance,
        'variance_percent' => $variance_percent,
        'pos_revenue' => $pos_data['pos_revenue'] ?? 0
    ];
}

// Sort by variance percent (highest first)
usort($variance_data, function($a, $b) {
    return abs($b['variance_percent']) <=> abs($a['variance_percent']);
});

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Fuel Variance Report</h1>
        <div class="sub">Compare POS sales vs pump readings to identify discrepancies</div>
    </div>
</div>

<div class="card" style="padding: 20px; margin-bottom: 20px;">
    <form method="get" style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 15px; align-items: flex-end;">
        <div class="form-group mb-0">
            <label class="lbl">Start Date</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="inp full">
        </div>
        <div class="form-group mb-0">
            <label class="lbl">End Date</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="inp full">
        </div>
        <div class="form-group mb-0">
            <label class="lbl">Pump (Optional)</label>
            <select name="pump_id" class="inp full">
                <option value="">All Pumps</option>
                <?php foreach ($all_pumps as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $pump_id_filter == $p['id'] ? 'selected' : ''; ?>>
                        Pump <?php echo htmlspecialchars($p['pump_number']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn primary">Filter</button>
        <button type="button" class="btn ghost" onclick="window.print()"><i class="fas fa-print"></i></button>
    </form>
</div>

<div class="card" style="padding: 20px; margin-bottom: 20px;">
    <div style="padding: 15px; background: #e8f4f8; border-left: 4px solid #0066cc; border-radius: 4px; margin-bottom: 20px;">
        <p style="margin: 0; color: #003d7a;"><strong>ℹ️ Variance Analysis:</strong></p>
        <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">
            A positive variance means the pump recorded more liters than POS (possible calibration issue or unreported sales).
            A negative variance means POS recorded more than pump (data entry error or manual adjustments).
        </p>
    </div>
    
    <h3 style="margin-top: 0;">Report Period: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></h3>
    
    <table style="width: 100%; border-collapse: collapse;">
        <thead style="background: #f8f9fa; border-bottom: 2px solid #003d7a;">
            <tr>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #003d7a;">Date</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #003d7a;">Pump #</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #003d7a;">Fuel Type</th>
                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #003d7a;">Pump Reading (L)</th>
                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #003d7a;">POS Sales (L)</th>
                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #003d7a;">Variance (L)</th>
                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #003d7a;">Variance %</th>
                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #003d7a;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            if (empty($variance_data)): 
            ?>
                <tr>
                    <td colspan="8" style="padding: 20px; text-align: center; color: #666;">
                        No readings found for the selected period.
                    </td>
                </tr>
            <?php else:
                foreach ($variance_data as $row):
                    // Determine status color
                    $status_color = '#28a745'; // Green - OK
                    $status_text = '✓ OK';
                    $status_bg = '#d4edda';
                    
                    if (abs($row['variance_percent']) > 5) {
                        $status_color = '#dc3545'; // Red - High variance
                        $status_text = '⚠ High';
                        $status_bg = '#f8d7da';
                    } elseif (abs($row['variance_percent']) > 2) {
                        $status_color = '#ffc107'; // Yellow - Medium variance
                        $status_text = '⚠ Medium';
                        $status_bg = '#fff3cd';
                    }
            ?>
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 12px;"><?php echo date('M d, Y', strtotime($row['reading_date'])); ?></td>
                    <td style="padding: 12px;">Pump <?php echo htmlspecialchars($row['pump_number']); ?></td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars($row['fuel_type'] ?? 'N/A'); ?></td>
                    <td style="padding: 12px; text-align: right;"><?php echo number_format($row['pump_liters'], 2); ?></td>
                    <td style="padding: 12px; text-align: right;"><?php echo number_format($row['pos_liters'], 2); ?></td>
                    <td style="padding: 12px; text-align: right; color: <?php echo $row['variance'] < 0 ? '#dc3545' : '#28a745'; ?>;">
                        <?php echo ($row['variance'] >= 0 ? '+' : '') . number_format($row['variance'], 2); ?>
                    </td>
                    <td style="padding: 12px; text-align: right; color: <?php echo $row['variance'] < 0 ? '#dc3545' : '#28a745'; ?>;">
                        <?php echo ($row['variance_percent'] >= 0 ? '+' : '') . number_format($row['variance_percent'], 1); ?>%
                    </td>
                    <td style="padding: 12px; text-align: right;">
                        <span style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: 500;">
                            <?php echo $status_text; ?>
                        </span>
                    </td>
                </tr>
            <?php
                endforeach;
            endif; ?>
        </tbody>
    </table>
</div>

<style>
    .page-head { margin-bottom: 20px; }
    .h1 { color: #003d7a; margin: 0 0 5px 0; }
    .sub { color: #666; font-size: 14px; }
    .card { background: white; border-radius: 8px; border: 1px solid #ddd; }
    .form-group { margin-bottom: 0; }
    .lbl { display: block; font-weight: 500; margin-bottom: 5px; color: #333; font-size: 13px; }
    .inp { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
    .inp.full { width: 100%; }
    .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500; }
    .btn.primary { background: #003d7a; color: white; }
    .btn.primary:hover { background: #002a56; }
    .btn.ghost { background: #f0f0f0; color: #333; }
    .btn.ghost:hover { background: #e0e0e0; }
    
    @media print {
        .card:first-child { display: none; } /* Hide filter form on print */
    }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
