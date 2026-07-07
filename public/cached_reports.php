<?php
/**
 * CACHED REPORTS VIEWER
 * Displays pre-generated reports from cache
 * Available to Admin and Super Admin roles
 */

$page_id = 'cached_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

require_login();
$u = current_user();
$role = role_key($u['role'] ?? 'staff');
$station_id = user_station_id();

// Only Admin and Superadmin can view cached reports
if (!in_array($role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// Get report type from URL
$report_type = $_GET['type'] ?? 'daily';
$report_date = $_GET['date'] ?? date('Y-m-d');

// Validate report type
$valid_types = ['daily', 'shift_am', 'shift_pm'];
if (!in_array($report_type, $valid_types)) {
    $report_type = 'daily';
}

// Get cached report
$stmt = $pdo->prepare("
    SELECT data, created_at
    FROM reports_cache
    WHERE report_type = ? 
    AND report_date = ?
    AND (station_id = ? OR station_id IS NULL)
    AND (? = 'superadmin' OR station_id = ?)
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([$report_type, $report_date, $station_id, $role, $station_id]);
$cached_report = $stmt->fetch(PDO::FETCH_ASSOC);

$report_data = null;
if ($cached_report) {
    $report_data = json_decode($cached_report['data'], true);
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Cached Reports</h1>
        <div class="sub">Pre-generated reports available for quick viewing</div>
    </div>
</div>

<!-- Report Selector -->
<div class="card" style="padding: 20px; margin-bottom: 20px;">
    <form method="get" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; align-items: flex-end;">
        <div>
            <label class="lbl">Report Type</label>
            <select name="type" class="inp">
                <option value="daily" <?php echo $report_type === 'daily' ? 'selected' : ''; ?>>Daily Report</option>
                <option value="shift_am" <?php echo $report_type === 'shift_am' ? 'selected' : ''; ?>>AM Shift</option>
                <option value="shift_pm" <?php echo $report_type === 'shift_pm' ? 'selected' : ''; ?>>PM Shift</option>
            </select>
        </div>
        <div>
            <label class="lbl">Report Date</label>
            <input type="date" name="date" value="<?php echo $report_date; ?>" class="inp">
        </div>
        <div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Load Report</button>
        </div>
    </form>
</div>

<!-- Report Content -->
<?php if ($report_data): ?>
    <div class="card" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h2 style="margin: 0; color: var(--text);">
                    <?php 
                    if ($report_type === 'daily') {
                        echo "Daily Report - " . date('M j, Y', strtotime($report_date));
                    } else {
                        $shift_label = $report_type === 'shift_am' ? 'AM' : 'PM';
                        echo "$shift_label Shift Report - " . date('M j, Y', strtotime($report_date));
                    }
                    ?>
                </h2>
                <small style="color: #999;">Generated: <?php echo date('M j, Y g:i A', strtotime($cached_report['created_at'])); ?></small>
            </div>
            <div>
                <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button class="btn btn-outline" onclick="exportToCSV()"><i class="fas fa-download"></i> Export CSV</button>
            </div>
        </div>

        <?php if ($report_type === 'daily'): ?>
            <!-- Daily Report Content -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px;">
                <!-- Sales Summary -->
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #28A745;">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Total Sales</div>
                    <div style="font-size: 24px; font-weight: bold; color: #28A745;">
                        ₱<?php echo isset($report_data['sales']['total_sales']) ? number_format($report_data['sales']['total_sales'], 2) : '0.00'; ?>
                    </div>
                    <div style="font-size: 12px; color: #999;">
                        <?php echo isset($report_data['sales']['transactions']) ? $report_data['sales']['transactions'] : 0; ?> transactions
                    </div>
                </div>

                <!-- Fuel Summary -->
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #007BFF;">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Fuel Items</div>
                    <div style="font-size: 24px; font-weight: bold; color: #007BFF;">
                        <?php echo count($report_data['fuel'] ?? []) ?? 0; ?>
                    </div>
                    <div style="font-size: 12px; color: #999;">In stock</div>
                </div>

                <!-- Job Orders Summary -->
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #FFC107;">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Job Orders</div>
                    <div style="font-size: 24px; font-weight: bold; color: #FFC107;">
                        <?php 
                        $total_jobs = 0;
                        foreach ($report_data['job_orders'] ?? [] as $job) {
                            $total_jobs += $job['count'] ?? 0;
                        }
                        echo $total_jobs;
                        ?>
                    </div>
                    <div style="font-size: 12px; color: #999;">Processed</div>
                </div>

                <!-- Customer Credit Summary -->
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #DC3545;">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Credit Activity</div>
                    <div style="font-size: 24px; font-weight: bold; color: #DC3545;">
                        <?php echo isset($report_data['customer_credit']['total_customers']) ? $report_data['customer_credit']['total_customers'] : 0; ?>
                    </div>
                    <div style="font-size: 12px; color: #999;">Customers</div>
                </div>
            </div>

            <!-- Detailed Tables -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                <!-- Fuel Details -->
                <div>
                    <h4 style="margin-top: 0; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px;">Fuel Stock Levels</h4>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th style="text-align: left; padding: 8px; border-bottom: 1px solid #ddd;">Fuel Type</th>
                                <th style="text-align: right; padding: 8px; border-bottom: 1px solid #ddd;">Stock Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data['fuel'] ?? [] as $fuel): ?>
                                <tr>
                                    <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($fuel['fuel_type']); ?></td>
                                    <td style="text-align: right; padding: 8px; border-bottom: 1px solid #eee;"><?php echo number_format($fuel['stock_level'], 2); ?> L</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Job Orders by Status -->
                <div>
                    <h4 style="margin-top: 0; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px;">Job Orders</h4>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th style="text-align: left; padding: 8px; border-bottom: 1px solid #ddd;">Status</th>
                                <th style="text-align: right; padding: 8px; border-bottom: 1px solid #ddd;">Count</th>
                                <th style="text-align: right; padding: 8px; border-bottom: 1px solid #ddd;">Total Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data['job_orders'] ?? [] as $job): ?>
                                <tr>
                                    <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($job['status']); ?></td>
                                    <td style="text-align: right; padding: 8px; border-bottom: 1px solid #eee;"><?php echo $job['count']; ?></td>
                                    <td style="text-align: right; padding: 8px; border-bottom: 1px solid #eee;">₱<?php echo number_format($job['total_value'] ?? 0, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- Shift Report Content -->
            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px;">
                <div style="font-size: 14px; color: #666; margin-bottom: 10px;">Shift</div>
                <div style="font-size: 32px; font-weight: bold; color: var(--blue);">
                    <?php echo htmlspecialchars($report_data['shift']); ?>
                </div>
                <div style="font-size: 12px; color: #999;">
                    <?php echo htmlspecialchars($report_data['shift_time']); ?>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #28A745;">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Total Sales</div>
                    <div style="font-size: 28px; font-weight: bold; color: #28A745;">
                        ₱<?php echo number_format($report_data['total_sales'] ?? 0, 2); ?>
                    </div>
                </div>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #007BFF;">
                    <div style="font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px;">Transactions</div>
                    <div style="font-size: 28px; font-weight: bold; color: #007BFF;">
                        <?php echo $report_data['transactions'] ?? 0; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="card" style="padding: 40px; text-align: center;">
        <i class="fas fa-inbox" style="font-size: 48px; color: #ccc; margin-bottom: 20px; display: block;"></i>
        <h3 style="color: #999;">No Report Available</h3>
        <p style="color: #999;">The requested report has not been generated yet. Please check back later or try a different date.</p>
        <p style="color: #999; font-size: 12px;">Reports are generated automatically on a scheduled basis.</p>
    </div>
<?php endif; ?>

<script>
function exportToCSV() {
    const table = document.querySelector('table');
    if (!table) {
        alert('No table data to export');
        return;
    }
    
    let csv = [];
    table.querySelectorAll('tr').forEach(row => {
        let cols = [];
        row.querySelectorAll('td, th').forEach(col => {
            cols.push('"' + col.innerText.replace(/"/g, '""') + '"');
        });
        csv.push(cols.join(','));
    });
    
    const filename = 'report_<?php echo $report_date; ?>_<?php echo $report_type; ?>.csv';
    const link = document.createElement('a');
    link.href = 'data:text/csv;charset=utf-8,' + encodeURI(csv.join('\n'));
    link.download = filename;
    link.click();
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
