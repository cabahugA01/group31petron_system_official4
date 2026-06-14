<?php
/**
 * Technical Reports - Standalone Page
 * System Usage Metrics, Performance Logs, Error Tracking, Module Health
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'developer'], true)) {
    header('Location: dashboard.php');
    exit;
}

$page_id = 'superadmin_reports';
$station_name = '';

// Ensure tables exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_performance_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            metric_type VARCHAR(50) NOT NULL COMMENT 'cpu, memory, bandwidth, query_time',
            metric_value DECIMAL(10,2) NOT NULL,
            metric_unit VARCHAR(20) NOT NULL,
            module_name VARCHAR(100),
            endpoint VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_metric_type (metric_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS error_tracking_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            error_type VARCHAR(100) NOT NULL,
            error_message TEXT,
            error_code VARCHAR(50),
            module_name VARCHAR(100),
            severity VARCHAR(20) DEFAULT 'error',
            status VARCHAR(20) DEFAULT 'unresolved',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_error_type (error_type),
            INDEX idx_severity (severity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS module_health_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_name VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL,
            uptime_seconds INT DEFAULT 0,
            downtime_seconds INT DEFAULT 0,
            response_time_ms DECIMAL(10,2),
            health_score INT DEFAULT 100,
            last_check DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_module (module_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log("Technical Reports table creation: " . $e->getMessage());
}

// Fetch filters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$module_filter = $_GET['module'] ?? '';

// Fetch data
$stmt_metrics = $pdo->prepare("
    SELECT 
        metric_type,
        AVG(metric_value) as avg_value,
        MAX(metric_value) as max_value,
        MIN(metric_value) as min_value,
        metric_unit,
        COUNT(*) as measurement_count
    FROM system_performance_logs
    WHERE created_at BETWEEN ? AND ?
    " . ($module_filter ? "AND module_name = ?" : "") . "
    GROUP BY metric_type, metric_unit
");
if ($module_filter) {
    $stmt_metrics->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59', $module_filter]);
} else {
    $stmt_metrics->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
}
$metrics = $stmt_metrics->fetchAll(PDO::FETCH_ASSOC);

$stmt_perf = $pdo->prepare("
    SELECT 
        module_name,
        endpoint,
        AVG(metric_value) as avg_response_time,
        MAX(metric_value) as max_response_time,
        COUNT(*) as request_count,
        DATE(created_at) as log_date
    FROM system_performance_logs
    WHERE metric_type = 'query_time'
    AND created_at BETWEEN ? AND ?
    " . ($module_filter ? "AND module_name = ?" : "") . "
    GROUP BY module_name, endpoint, DATE(created_at)
    ORDER BY created_at DESC
    LIMIT 100
");
if ($module_filter) {
    $stmt_perf->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59', $module_filter]);
} else {
    $stmt_perf->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
}
$performance_logs = $stmt_perf->fetchAll(PDO::FETCH_ASSOC);

$stmt_errors = $pdo->prepare("
    SELECT * FROM error_tracking_logs
    WHERE created_at BETWEEN ? AND ?
    " . ($module_filter ? "AND module_name = ?" : "") . "
    ORDER BY created_at DESC, severity DESC
    LIMIT 50
");
if ($module_filter) {
    $stmt_errors->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59', $module_filter]);
} else {
    $stmt_errors->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
}
$errors = $stmt_errors->fetchAll(PDO::FETCH_ASSOC);

$stmt_health = $pdo->prepare("
    SELECT * FROM module_health_logs
    WHERE last_check BETWEEN ? AND ?
    ORDER BY health_score ASC
");
$stmt_health->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$module_health = $stmt_health->fetchAll(PDO::FETCH_ASSOC);

$stmt_modules = $pdo->query("SELECT DISTINCT module_name FROM system_performance_logs WHERE module_name IS NOT NULL ORDER BY module_name");
$available_modules = $stmt_modules->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../partials/header.php';
?>

<style>
:root {
    --primary-color: #003366;
    --success-color: #10b981;
    --warning-color: #f59e0b;
    --danger-color: #ef4444;
    --info-color: #3b82f6;
    --bg-primary: #ffffff;
    --bg-secondary: #f8fafc;
    --text-primary: #111827;
    --text-secondary: #6b7280;
    --border-color: #e5e7eb;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --radius-md: 8px;
    --radius-lg: 12px;
}

.report-container {
    padding: 24px;
    background: var(--bg-secondary);
    min-height: 100vh;
}

.page-header {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
}

.page-title {
    font-size: 1.875rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}

.filters-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 20px 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.form-group label {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.875rem;
    display: block;
    margin-bottom: 6px;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.875rem;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary { background: var(--primary-color); color: white; }
.btn-success { background: var(--success-color); color: white; }
.btn-secondary { background: #e5e7eb; color: var(--text-primary); }

.actions-bar {
    display: flex;
    justify-content: space-between;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
}

.report-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
    margin-bottom: 24px;
}

.report-card-header {
    padding: 16px 24px;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
}

.report-card-title {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0;
}

.report-card-body {
    padding: 24px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.stat-box {
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    padding: 20px;
    border: 1px solid var(--border-color);
}

.stat-label {
    font-size: 0.813rem;
    color: var(--text-secondary);
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.data-table thead th {
    background: #f1f5f9;
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid var(--border-color);
}

.data-table tbody td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
}

.data-table tbody tr:hover {
    background: var(--bg-secondary);
}

.badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-success { background: rgba(16, 185, 129, 0.1); color: var(--success-color); }
.badge-danger { background: rgba(239, 68, 68, 0.1); color: var(--danger-color); }
.badge-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning-color); }
.badge-secondary { background: rgba(107, 114, 128, 0.1); color: var(--text-secondary); }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-secondary);
}

@media print {
    @page { size: legal portrait; margin: 0.3in 0.4in; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    
    body { background: white !important; }
    
    .filters-card,
    .btn,
    .sidebar,
    .top-header,
    .footer-sidebar-area,
    nav,
    .no-print {
        display: none !important;
    }
    
    .report-container {
        margin: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
    }
    
    .report-card {
        page-break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    
    .data-table {
        font-size: 10px !important;
    }
    
    .data-table thead th {
        background: #f0f0f0 !important;
        border: 1px solid #000 !important;
    }
}
</style>

<div class="report-container">

    <!-- Page Header - Manager Style -->
    <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:20px;">
        <div style="font-size:20px;font-weight:800;color:#003366;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
            TECHNICAL REPORTS
        </div>
        <div style="font-size:16px;font-weight:700;color:#003366;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
            DEVELOPER VIEW
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
            System Usage Metrics • Performance Logs • Error Tracking • Module Health
        </div>
        <div style="font-size:12px;color:#334155;">
            <strong>Date:</strong>
            <?php echo date('F j, Y', strtotime($date_from)); ?>
            <?php echo $date_from !== $date_to ? ' – ' . date('F j, Y', strtotime($date_to)) : ''; ?>
        </div>
    </div>

    <div class="filters-card">
        <form method="GET">
            <div class="filters-grid">
                <div class="form-group">
                    <label>Date From</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="form-group">
                    <label>Date To</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="form-group">
                    <label>Module</label>
                    <select class="form-control" name="module">
                        <option value="">All Modules</option>
                        <?php foreach ($available_modules as $mod): ?>
                            <option value="<?php echo htmlspecialchars($mod); ?>" <?php echo ($module_filter === $mod) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($mod); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="actions-bar">
                <div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
                <div>
                    <button type="button" class="btn btn-success" onclick="exportToCSV()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button type="button" class="btn btn-success" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
    function exportToCSV() {
        const params = new URLSearchParams(window.location.search);
        const dateFrom = params.get('date_from') || '<?php echo $date_from; ?>';
        const dateTo = params.get('date_to') || '<?php echo $date_to; ?>';
        window.location.href = '../backend/api/developer_reports_api.php?action=export_csv&report_type=technical&date_from=' + dateFrom + '&date_to=' + dateTo;
    }
    </script>

    <!-- System Usage Metrics -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-tachometer-alt"></i> System Usage Metrics</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($metrics)): ?>
                <div class="empty-state">
                    <i class="fas fa-chart-line" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No system metrics data available</p>
                </div>
            <?php else: ?>
                <div class="stats-grid">
                    <?php foreach ($metrics as $metric): ?>
                        <div class="stat-box">
                            <div class="stat-label"><?php echo strtoupper(str_replace('_', ' ', $metric['metric_type'])); ?></div>
                            <div class="stat-value">
                                <?php echo number_format($metric['avg_value'], 2); ?> 
                                <small style="font-size:0.5em; font-weight:400;"><?php echo htmlspecialchars($metric['metric_unit']); ?></small>
                            </div>
                            <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:8px;">
                                <div>Min: <?php echo number_format($metric['min_value'], 2); ?></div>
                                <div>Max: <?php echo number_format($metric['max_value'], 2); ?></div>
                                <div>Count: <?php echo number_format($metric['measurement_count']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Performance Logs -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-clock"></i> Performance Logs</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($performance_logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-clock" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No performance logs available</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Module</th>
                                <th>Endpoint</th>
                                <th>Avg Response (ms)</th>
                                <th>Max Response (ms)</th>
                                <th>Requests</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($performance_logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['module_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($log['endpoint'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($log['avg_response_time'], 2); ?></td>
                                    <td><?php echo number_format($log['max_response_time'], 2); ?></td>
                                    <td><?php echo number_format($log['request_count']); ?></td>
                                    <td><?php echo htmlspecialchars($log['log_date']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Error Tracking -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-exclamation-triangle"></i> Error Tracking</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($errors)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle" style="font-size: 3rem; opacity: 0.3; color: var(--success-color);"></i>
                    <p>No errors recorded. System running smoothly!</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Type</th>
                                <th>Message</th>
                                <th>Module</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($errors as $error): ?>
                                <tr>
                                    <td><?php echo $error['id']; ?></td>
                                    <td><?php echo htmlspecialchars($error['error_type']); ?></td>
                                    <td><?php echo htmlspecialchars(substr($error['error_message'], 0, 50)); ?>...</td>
                                    <td><?php echo htmlspecialchars($error['module_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $error['severity'] === 'critical' ? 'danger' : ($error['severity'] === 'warning' ? 'warning' : 'secondary'); ?>">
                                            <?php echo strtoupper($error['severity']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $error['status'] === 'resolved' ? 'success' : 'danger'; ?>">
                                            <?php echo strtoupper($error['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($error['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Module Health -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-heartbeat"></i> Module Health</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($module_health)): ?>
                <div class="empty-state">
                    <i class="fas fa-heartbeat" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No module health data available</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Module</th>
                                <th>Status</th>
                                <th>Health Score</th>
                                <th>Uptime</th>
                                <th>Downtime</th>
                                <th>Response Time (ms)</th>
                                <th>Last Check</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($module_health as $health): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($health['module_name']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $health['status'] === 'up' ? 'success' : ($health['status'] === 'degraded' ? 'warning' : 'danger'); ?>">
                                            <?php echo strtoupper($health['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="color: <?php echo $health['health_score'] >= 80 ? 'var(--success-color)' : ($health['health_score'] >= 50 ? 'var(--warning-color)' : 'var(--danger-color)'); ?>">
                                            <?php echo $health['health_score']; ?>%
                                        </strong>
                                    </td>
                                    <td><?php echo gmdate('H:i:s', $health['uptime_seconds']); ?></td>
                                    <td><?php echo gmdate('H:i:s', $health['downtime_seconds']); ?></td>
                                    <td><?php echo number_format($health['response_time_ms'] ?? 0, 2); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($health['last_check'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
