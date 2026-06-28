<?php
/**
 * Staff Customers Report
 * Generate customer reports and statistics
 */

$page_id = 'report_customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Staff only
if (!in_array($role, ['staff', 'superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

$page_title = "Customer Reports";
include __DIR__ . '/../partials/header.php';

// Date range
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Fetch report data
try {
    // Check if customers table exists
    $tableExists = false;
    try {
        $pdo->query("SELECT 1 FROM customers LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {
        $tableExists = false;
    }
    
    if ($tableExists) {
        // Summary stats
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN customer_type = 'walk-in' THEN 1 ELSE 0 END) as walkin,
                SUM(CASE WHEN customer_type = 'regular' THEN 1 ELSE 0 END) as regular,
                SUM(CASE WHEN customer_type = 'fleet' THEN 1 ELSE 0 END) as fleet,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN DATE(registered_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) as new_period
            FROM customers
            WHERE station_id = ?
        ");
        $stmt->execute([$date_from, $date_to, $station_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // New customers in period
        $stmt = $pdo->prepare("
            SELECT 
                DATE(registered_at) as reg_date,
                COUNT(*) as count
            FROM customers
            WHERE station_id = ? AND DATE(registered_at) BETWEEN ? AND ?
            GROUP BY DATE(registered_at)
            ORDER BY reg_date
        ");
        $stmt->execute([$station_id, $date_from, $date_to]);
        $newCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stats = [
            'total' => 0,
            'walkin' => 0,
            'regular' => 0,
            'fleet' => 0,
            'active' => 0,
            'new_period' => 0
        ];
        $newCustomers = [];
    }
} catch (Exception $e) {
    $stats = [
        'total' => 0,
        'walkin' => 0,
        'regular' => 0,
        'fleet' => 0,
        'active' => 0,
        'new_period' => 0
    ];
    $newCustomers = [];
}
?>

<style>
.report-container{max-width:1200px;margin:0 auto;}
.report-header{background:#fff;border-radius:10px;border:1px solid #e5e7eb;padding:20px;margin-bottom:20px;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:20px;}
.stat-card{background:#fff;border-radius:10px;border:1px solid #e5e7eb;padding:20px;text-align:center;}
.stat-card .number{font-size:36px;font-weight:700;color:#002F70;margin:10px 0;}
.stat-card .label{font-size:13px;color:#6b7280;font-weight:600;}
.chart-card{background:#fff;border-radius:10px;border:1px solid #e5e7eb;padding:20px;margin-bottom:20px;}
.chart-card h3{margin:0 0 20px;font-size:18px;font-weight:700;color:#002F70;}
.date-filters{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;}
.filter-group{flex:1;min-width:200px;}
.filter-group label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;}
.filter-group input{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;}
.empty-state{text-align:center;padding:60px 20px;color:#9ca3af;}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-chart-bar"></i> Customer Reports</h1>
        <div class="sub">View customer statistics and trends</div>
    </div>
</div>

<div class="report-container">
    <!-- Date Filter -->
    <div class="report-header">
        <form method="GET" class="date-filters">
            <div class="filter-group">
                <label>Date From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
            </div>
            <div class="filter-group">
                <label>Date To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Generate Report</button>
            <button type="button" class="btn btn-export" onclick="exportReport()"><i class="fas fa-download"></i> Export</button>
        </form>
    </div>

    <!-- Summary Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="number"><?= number_format($stats['total']) ?></div>
            <div class="label">Total Customers</div>
        </div>
        <div class="stat-card">
            <div class="number"><?= number_format($stats['new_period']) ?></div>
            <div class="label">New (Period)</div>
        </div>
        <div class="stat-card">
            <div class="number"><?= number_format($stats['active']) ?></div>
            <div class="label">Active Customers</div>
        </div>
        <div class="stat-card">
            <div class="number"><?= number_format($stats['regular']) ?></div>
            <div class="label">Regular Customers</div>
        </div>
    </div>

    <!-- Customer Type Breakdown -->
    <div class="chart-card">
        <h3><i class="fas fa-pie-chart"></i> Customer Type Breakdown</h3>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number" style="color:#3730a3;"><?= number_format($stats['walkin']) ?></div>
                <div class="label">Walk-in</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color:#92400e;"><?= number_format($stats['regular']) ?></div>
                <div class="label">Regular</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color:#1e40af;"><?= number_format($stats['fleet']) ?></div>
                <div class="label">Fleet/Company</div>
            </div>
        </div>
    </div>

    <!-- New Customers Trend -->
    <?php if (!empty($newCustomers)): ?>
    <div class="chart-card">
        <h3><i class="fas fa-chart-line"></i> New Customers (<?= date('M d', strtotime($date_from)) ?> - <?= date('M d, Y', strtotime($date_to)) ?>)</h3>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e5e7eb;">
                    <th style="padding:12px;text-align:left;font-size:12px;font-weight:700;color:#374151;">Date</th>
                    <th style="padding:12px;text-align:right;font-size:12px;font-weight:700;color:#374151;">New Customers</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($newCustomers as $row): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px;font-size:14px;color:#374151;"><?= date('M d, Y', strtotime($row['reg_date'])) ?></td>
                    <td style="padding:12px;text-align:right;font-size:14px;color:#374151;font-weight:600;"><?= number_format($row['count']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="chart-card">
        <div class="empty-state">
            <i class="fas fa-chart-line fa-3x" style="margin-bottom:12px;"></i>
            <p>No new customers in the selected period</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function exportReport() {
    const from = '<?= $date_from ?>';
    const to = '<?= $date_to ?>';
    window.location.href = `staff_customer_export.php?format=excel&date_from=${from}&date_to=${to}&station_id=<?= $station_id ?>`;
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
