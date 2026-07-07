<?php
/**
 * Technical Reports Partial
 * Displays: System Usage Metrics, Performance Logs, Error Tracking, Module Health
 */

// Fetch date range from request or default to last 7 days
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$module_filter = $_GET['module'] ?? '';

// System Usage Metrics - REAL DATA
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
    ORDER BY metric_type
");

if ($module_filter) {
    $stmt_metrics->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59', $module_filter]);
} else {
    $stmt_metrics->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
}
$metrics = $stmt_metrics->fetchAll(PDO::FETCH_ASSOC);

// Performance Logs - REAL DATA
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

// Error Tracking - REAL DATA
$stmt_errors = $pdo->prepare("
    SELECT 
        id,
        error_type,
        error_message,
        error_code,
        module_name,
        severity,
        status,
        created_at
    FROM error_tracking_logs
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

// Module Health - REAL DATA
$stmt_health = $pdo->prepare("
    SELECT 
        module_name,
        status,
        uptime_seconds,
        downtime_seconds,
        health_score,
        response_time_ms,
        last_check
    FROM module_health_logs
    WHERE last_check BETWEEN ? AND ?
    ORDER BY health_score ASC, module_name
");
$stmt_health->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$module_health = $stmt_health->fetchAll(PDO::FETCH_ASSOC);

// Get unique modules for filter dropdown
$stmt_modules = $pdo->query("
    SELECT DISTINCT module_name 
    FROM system_performance_logs 
    WHERE module_name IS NOT NULL 
    ORDER BY module_name
");
$available_modules = $stmt_modules->fetchAll(PDO::FETCH_COLUMN);
?>

<!-- Filters -->
<div class="filters-card">
    <form method="GET" action="" id="filter-form">
        <div class="filters-grid">
            <div class="form-group">
                <label for="date_from">Date From</label>
                <input type="date" class="form-control" id="date_from" name="date_from" 
                       value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="form-group">
                <label for="date_to">Date To</label>
                <input type="date" class="form-control" id="date_to" name="date_to" 
                       value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="form-group">
                <label for="module">Module</label>
                <select class="form-control" id="module" name="module">
                    <option value="">All Modules</option>
                    <?php foreach ($available_modules as $mod): ?>
                        <option value="<?php echo htmlspecialchars($mod); ?>" 
                                <?php echo ($module_filter === $mod) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($mod); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="actions-bar">
            <div class="actions-left">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <button type="button" class="btn btn-secondary" onclick="location.href='?'">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
            <div class="actions-right">
                <button type="button" class="btn btn-success" onclick="exportReport('csv', 'technical')">
                    <i class="fas fa-file-csv"></i> Export CSV
                </button>
                <button type="button" class="btn btn-success" onclick="exportReport('pdf', 'technical')">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
                <button type="button" class="btn btn-secondary" onclick="printReport('technical')">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </form>
</div>

<!-- System Usage Metrics -->
<div class="report-card" style="margin-bottom: 24px;">
    <div class="report-card-header">
        <h3 class="report-card-title">
            <i class="fas fa-tachometer-alt"></i> System Usage Metrics
        </h3>
    </div>
    <div class="report-card-body">
        <?php if (empty($metrics)): ?>
            <div class="empty-state">
                <i class="fas fa-chart-line"></i>
                <p>No system metrics data available for the selected period.</p>
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
                            <div>Min: <?php echo number_format($metric['min_value'], 2); ?> <?php echo htmlspecialchars($metric['metric_unit']); ?></div>
                            <div>Max: <?php echo number_format($metric['max_value'], 2); ?> <?php echo htmlspecialchars($metric['metric_unit']); ?></div>
                            <div>Measurements: <?php echo number_format($metric['measurement_count']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Performance Logs -->
<div class="report-card" style="margin-bottom: 24px;">
    <div class="report-card-header">
        <h3 class="report-card-title">
            <i class="fas fa-clock"></i> Performance Logs
        </h3>
    </div>
    <div class="report-card-body">
        <?php if (empty($performance_logs)): ?>
            <div class="empty-state">
                <i class="fas fa-clock"></i>
                <p>No performance logs available for the selected period.</p>
            </div>
        <?php else: ?>
            <div class="data-table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th>Endpoint</th>
                            <th>Avg Response Time (ms)</th>
                            <th>Max Response Time (ms)</th>
                            <th>Request Count</th>
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
<div class="report-card" style="margin-bottom: 24px;">
    <div class="report-card-header">
        <h3 class="report-card-title">
            <i class="fas fa-exclamation-triangle"></i> Error Tracking
        </h3>
    </div>
    <div class="report-card-body">
        <?php if (empty($errors)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p>No errors recorded for the selected period. System running smoothly!</p>
            </div>
        <?php else: ?>
            <div class="data-table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Message</th>
                            <th>Code</th>
                            <th>Module</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $error): ?>
                            <tr>
                                <td><?php echo $error['id']; ?></td>
                                <td><?php echo htmlspecialchars($error['error_type']); ?></td>
                                <td><?php echo htmlspecialchars(substr($error['error_message'], 0, 100)); ?>...</td>
                                <td><?php echo htmlspecialchars($error['error_code'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($error['module_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="badge badge-<?php 
                                        echo $error['severity'] === 'critical' ? 'danger' : 
                                             ($error['severity'] === 'warning' ? 'warning' : 'secondary'); 
                                    ?>">
                                        <?php echo strtoupper($error['severity']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php 
                                        echo $error['status'] === 'resolved' ? 'success' : 
                                             ($error['status'] === 'investigating' ? 'warning' : 'danger'); 
                                    ?>">
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
        <h3 class="report-card-title">
            <i class="fas fa-heartbeat"></i> Module Health
        </h3>
    </div>
    <div class="report-card-body">
        <?php if (empty($module_health)): ?>
            <div class="empty-state">
                <i class="fas fa-heartbeat"></i>
                <p>No module health data available for the selected period.</p>
            </div>
        <?php else: ?>
            <div class="data-table-container">
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
                                    <span class="badge badge-<?php 
                                        echo $health['status'] === 'up' ? 'success' : 
                                             ($health['status'] === 'degraded' ? 'warning' : 'danger'); 
                                    ?>">
                                        <?php echo strtoupper($health['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: <?php 
                                        echo $health['health_score'] >= 80 ? 'var(--success-color)' : 
                                             ($health['health_score'] >= 50 ? 'var(--warning-color)' : 'var(--danger-color)'); 
                                    ?>">
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
