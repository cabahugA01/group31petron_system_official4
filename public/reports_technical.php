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

// Filters
$date_from     = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to       = $_GET['date_to']   ?? date('Y-m-d');
$module_filter = $_GET['module']    ?? '';

// ── System Usage Metrics (real: system_health_metrics) ────────
$system_health_metrics = $pdo->query("
    SELECT metric_name, metric_value, metric_unit, status, recorded_at
    FROM system_health_metrics ORDER BY recorded_at DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Aggregated from system_performance_logs
$stmt_metrics = $pdo->prepare("
    SELECT metric_type,
           AVG(metric_value) as avg_value, MAX(metric_value) as max_value,
           MIN(metric_value) as min_value, metric_unit, COUNT(*) as measurement_count
    FROM system_performance_logs
    WHERE created_at BETWEEN ? AND ?
    " . ($module_filter ? "AND module_name = ?" : "") . "
    GROUP BY metric_type, metric_unit
");
$module_filter
    ? $stmt_metrics->execute([$date_from.' 00:00:00', $date_to.' 23:59:59', $module_filter])
    : $stmt_metrics->execute([$date_from.' 00:00:00', $date_to.' 23:59:59']);
$metrics = $stmt_metrics->fetchAll(PDO::FETCH_ASSOC);

// ── Performance Logs ──────────────────────────────────────────
$stmt_perf = $pdo->prepare("
    SELECT module_name, endpoint,
           AVG(metric_value) as avg_response_time,
           MAX(metric_value) as max_response_time,
           COUNT(*) as request_count, DATE(created_at) as log_date
    FROM system_performance_logs
    WHERE metric_type = 'query_time' AND created_at BETWEEN ? AND ?
    " . ($module_filter ? "AND module_name = ?" : "") . "
    GROUP BY module_name, endpoint, DATE(created_at)
    ORDER BY log_date DESC LIMIT 100
");
$module_filter
    ? $stmt_perf->execute([$date_from.' 00:00:00', $date_to.' 23:59:59', $module_filter])
    : $stmt_perf->execute([$date_from.' 00:00:00', $date_to.' 23:59:59']);
$performance_logs = $stmt_perf->fetchAll(PDO::FETCH_ASSOC);

// ── Error Tracking (real: error_tracking_logs → fallback: audit_logs) ──
$stmt_errors = $pdo->prepare("
    SELECT id, error_type, error_message, module_name, severity, status, created_at
    FROM error_tracking_logs
    WHERE created_at BETWEEN ? AND ?
    " . ($module_filter ? "AND module_name = ?" : "") . "
    ORDER BY created_at DESC, severity DESC LIMIT 100
");
$module_filter
    ? $stmt_errors->execute([$date_from.' 00:00:00', $date_to.' 23:59:59', $module_filter])
    : $stmt_errors->execute([$date_from.' 00:00:00', $date_to.' 23:59:59']);
$errors = $stmt_errors->fetchAll(PDO::FETCH_ASSOC);

if (empty($errors)) {
    $stmt_err_fb = $pdo->prepare("
        SELECT id, action_type AS error_type, action_details AS error_message,
               COALESCE(entity_type,'system') AS module_name,
               'error' AS severity, status, created_at
        FROM audit_logs
        WHERE status = 'Failed' AND created_at BETWEEN ? AND ?
        ORDER BY created_at DESC LIMIT 100
    ");
    $stmt_err_fb->execute([$date_from.' 00:00:00', $date_to.' 23:59:59']);
    $errors = $stmt_err_fb->fetchAll(PDO::FETCH_ASSOC);
}

// ── Module Health (real: module_health_logs → fallback: activity_logs) ──
$stmt_health = $pdo->prepare("
    SELECT * FROM module_health_logs WHERE last_check BETWEEN ? AND ? ORDER BY health_score ASC
");
$stmt_health->execute([$date_from.' 00:00:00', $date_to.' 23:59:59']);
$module_health = $stmt_health->fetchAll(PDO::FETCH_ASSOC);

$module_health_derived = [];
if (empty($module_health)) {
    $module_health_derived = $pdo->query("
        SELECT action AS module_name, COUNT(*) AS total_actions,
               SUM(CASE WHEN action LIKE '%fail%' OR action LIKE '%error%' OR action LIKE '%denied%' THEN 1 ELSE 0 END) AS error_count,
               MAX(created_at) AS last_check
        FROM activity_logs GROUP BY action ORDER BY total_actions DESC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Summary Stats ─────────────────────────────────────────────
$total_errors        = count($errors);
$total_perf_logs     = count($performance_logs);
$total_health_checks = count($module_health) ?: count($module_health_derived);
$total_actions       = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();

$available_modules = [];
try {
    $available_modules = $pdo->query("SELECT DISTINCT module_name FROM system_performance_logs WHERE module_name IS NOT NULL ORDER BY module_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

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
    padding: 0 24px 24px;
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
    color: var(--primary-color) !important;
    margin: 0 0 8px 0;
}

.filters-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 20px 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
    margin-top: -12px !important;
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

.btn-primary { 
    padding: 7px 14px !important;
    background: white !important;
    color: #00264D !important;
    border: 1px solid #00264D !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
}

.btn-primary:hover {
    background: #00264D !important;
    color: white !important;
}

.btn-secondary { 
    padding: 7px 14px !important;
    background: white !important;
    color: #6b7280 !important;
    border: 1px solid #6b7280 !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    text-decoration: none !important;
    display: inline-block !important;
}

.btn-secondary:hover {
    background: #6b7280 !important;
    color: white !important;
}

/* Export Actions - Same as Audit Trail */
.rpt-export-actions {
    display: flex !important;
    gap: 6px !important;
    margin-left: auto !important;
}

.rpt-export-btn {
    padding: 7px 14px !important;
    background: white !important;
    color: #00264D !important;
    border: 1px solid #00264D !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
}

.rpt-export-btn:hover {
    background: #00264D !important;
    color: white !important;
}

.rpt-export-btn i {
    margin-right: 3px !important;
}

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
    @page {
        size: A4 landscape;
        margin: 0.4in 0.4in;
    }

    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        box-sizing: border-box !important;
    }

    html, body {
        background: white !important;
        padding: 0 !important;
        margin: 0 auto !important;
        width: 100% !important;
        height: auto !important;
        overflow: visible !important;
    }

    /* Hide ALL system chrome, navigation elements, filters, and summary cards */
    .filters-card, .btn, .sidebar, .top-header,
    .footer-sidebar-area, .footer-content, .fixed-footer, footer,
    .toggle-scroll-btn, #toggleScrollBtn, .toast,
    nav, header, .no-print, .stats-grid, .stat-box {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
    }

    /* Reset all structural layouts to remove sidebar offsets */
    body .app,
    body .main,
    body.sidebar-expanded .main,
    body.sidebar-collapsed .main,
    body .ss-wrapper,
    body .page-wrapper,
    body main {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        display: block !important;
        float: none !important;
        position: static !important;
        left: 0 !important;
        top: 0 !important;
        right: auto !important;
        bottom: auto !important;
        overflow: visible !important;
    }

    /* Report container centering and width constraints */
    .report-container {
        display: block !important;
        padding: 0 5px !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        background: white !important;
        overflow: visible !important;
    }

    .rpt-printable {
        display: block !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: visible !important;
    }

    /* Professional top rule */
    .rpt-printable::before {
        content: '';
        display: block;
        width: 100%;
        border-top: 3px solid #003366;
        margin-bottom: 2px;
    }

    /* Reduce title spacing */
    .rpt-printable > div:first-child {
        padding: 8px 0 4px 0 !important;
        margin-bottom: 8px !important;
    }

    /* Cards */
    .report-card {
        page-break-inside: auto !important;
        break-inside: auto !important;
        margin-bottom: 12px !important;
        border: 1px solid #b0bec8 !important;
        box-shadow: none !important;
        width: 100% !important;
        overflow: visible !important;
    }

    .report-card-header {
        background: #eef2f8 !important;
        padding: 6px 10px !important;
        border-bottom: 1px solid #b0bec8 !important;
    }

    .report-card-header h3,
    .report-card-title {
        font-size: 10px !important;
        font-weight: 700 !important;
        color: #003366 !important;
        margin: 0 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
    }

    .report-card-body {
        padding: 8px 10px !important;
        overflow: visible !important;
        height: auto !important;
    }

    /* Kill overflow wrappers around tables in print */
    div[style*="overflow-x"],
    .report-card-body > div {
        overflow: visible !important;
        overflow-x: visible !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    /* Tables — Landscape Optimized, Auto Width Distribution */
    .data-table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 8px !important;
        page-break-inside: auto !important;
        table-layout: auto !important;
        word-break: break-all !important;
        overflow-wrap: anywhere !important;
    }

    .data-table thead { display: table-header-group !important; }
    .data-table tbody { display: table-row-group !important; }

    .data-table thead tr {
        background: #00264D !important;
    }

    .data-table thead th {
        padding: 6px 5px !important;
        font-size: 7.5px !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
        border: 1px solid #00264D !important;
        text-align: center !important;
        color: white !important;
        background: #00264D !important;
        white-space: normal !important;
    }

    .data-table tbody td {
        padding: 5px 5px !important;
        font-size: 7.5px !important;
        border: 1px solid #d0d8e4 !important;
        text-align: center !important;
        white-space: normal !important;
        vertical-align: middle !important;
    }

    .data-table tbody tr:nth-child(even) {
        background: #f8fafc !important;
    }

    .data-table tbody tr { page-break-inside: avoid !important; }

    .data-table tfoot td {
        padding: 6px !important;
        font-size: 8px !important;
        border-top: 2px solid #003366 !important;
        border: 1px solid #9aafcc !important;
        font-weight: 700 !important;
        background: #eef2f8 !important;
    }

    /* Badges */
    .badge {
        border: 1px solid #999 !important;
        padding: 1px 4px !important;
        font-size: 6.5px !important;
        border-radius: 2px !important;
        font-weight: 600 !important;
    }

    .empty-state { display: none !important; }

    /* Page footer */
    .rpt-printable::after {
        content: 'PETRON STATION MANAGEMENT SYSTEM  |  CONFIDENTIAL  |  Generated: ' attr(data-print-date);
        display: block;
        font-size: 7px;
        color: #718096;
        text-align: center;
        border-top: 1px solid #cbd5e1;
        padding-top: 6px;
        margin-top: 20px;
    }
}
</style>

<div class="report-container">

    <!-- Date Filters - Moved to Top -->
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
                <div class="rpt-export-actions">
                    <button type="button" class="rpt-export-btn" onclick="window.print()">
                        <i class="fas fa-print"></i> Print PDF
                    </button>
                    <button type="button" class="rpt-export-btn" onclick="exportReport('excel')">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                    <button type="button" class="rpt-export-btn" onclick="exportReport('csv')">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Printable Content: Title + Report Cards -->
    <div class="rpt-printable" data-print-date="<?php echo date('F j, Y g:i A'); ?>">
        <!-- Page Header - Manager Style (Moved below filters) -->
        <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:20px;">
            <div style="font-size:20px;font-weight:800;color:#003366;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">
                TECHNICAL REPORTS
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

    <script>
    function exportToCSV() {
        const params = new URLSearchParams(window.location.search);
        const dateFrom = params.get('date_from') || '<?php echo $date_from; ?>';
        const dateTo = params.get('date_to') || '<?php echo $date_to; ?>';
        window.location.href = '../backend/api/developer_reports_api.php?action=export_csv&report_type=technical&date_from=' + dateFrom + '&date_to=' + dateTo;
    }
    </script>

    <!-- System Usage Metrics (real data: system_health_metrics) -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-tachometer-alt"></i> System Usage Metrics</h3>
        </div>
        <div class="report-card-body">
            <?php if (!empty($system_health_metrics)): ?>
                <div style="overflow:hidden;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>Value</th>
                                <th>Unit</th>
                                <th>Status</th>
                                <th>Recorded At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($system_health_metrics as $m): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars(str_replace('_', ' ', ucwords($m['metric_name']))); ?></strong></td>
                                    <td><?php echo htmlspecialchars($m['metric_value']); ?></td>
                                    <td><?php echo htmlspecialchars($m['metric_unit']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $m['status'] === 'good' ? 'success' : ($m['status'] === 'warning' ? 'warning' : 'danger'); ?>">
                                            <?php echo strtoupper($m['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($m['recorded_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif (!empty($metrics)): ?>
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
                                <div>Samples: <?php echo number_format($metric['measurement_count']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-chart-line" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No system metrics data in selected period</p>
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
                <div style="overflow:hidden;">
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
                <div style="overflow:hidden;">
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
            <?php if (!empty($module_health)): ?>
                <div style="overflow:hidden;">
                    <table class="data-table">
                        <thead><tr>
                            <th>Module</th><th>Status</th><th>Health Score</th>
                            <th>Uptime</th><th>Downtime</th><th>Response (ms)</th><th>Last Check</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($module_health as $health): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($health['module_name']); ?></td>
                                    <td><span class="badge badge-<?php echo $health['status'] === 'up' ? 'success' : ($health['status'] === 'degraded' ? 'warning' : 'danger'); ?>">
                                        <?php echo strtoupper($health['status']); ?></span></td>
                                    <td><strong style="color: <?php echo $health['health_score'] >= 80 ? 'var(--success-color)' : ($health['health_score'] >= 50 ? 'var(--warning-color)' : 'var(--danger-color)'); ?>">
                                        <?php echo $health['health_score']; ?>%</strong></td>
                                    <td><?php echo gmdate('H:i:s', $health['uptime_seconds']); ?></td>
                                    <td><?php echo gmdate('H:i:s', $health['downtime_seconds']); ?></td>
                                    <td><?php echo number_format($health['response_time_ms'] ?? 0, 2); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($health['last_check'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif (!empty($module_health_derived)): ?>
                <!-- Fallback: derived from activity_logs -->
                <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:12px;">
                    <i class="fas fa-info-circle"></i> Derived from system activity logs. Install module health monitors to see detailed uptime data.
                </p>
                <div style="overflow:hidden;">
                    <table class="data-table">
                        <thead><tr>
                            <th>Action / Module</th>
                            <th>Total Activity</th>
                            <th>Error Count</th>
                            <th>Health Est.</th>
                            <th>Last Seen</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($module_health_derived as $h):
                                $err_pct = $h['total_actions'] > 0 ? round(($h['error_count'] / $h['total_actions']) * 100) : 0;
                                $health_est = 100 - $err_pct;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($h['module_name']); ?></td>
                                    <td><?php echo number_format($h['total_actions']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $h['error_count'] > 0 ? 'danger' : 'success'; ?>">
                                            <?php echo number_format($h['error_count']); ?>
                                        </span>
                                    </td>
                                    <td><strong style="color: <?php echo $health_est >= 80 ? 'var(--success-color)' : ($health_est >= 50 ? 'var(--warning-color)' : 'var(--danger-color)'); ?>">
                                        ~<?php echo $health_est; ?>%</strong></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($h['last_check'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-heartbeat" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No module health data available</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div><!-- End .rpt-printable -->
</div>

<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<script>
function exportReport(type) {
    if (typeof XLSX === 'undefined') {
        alert('Export library not loaded. Please refresh the page and try again.');
        return;
    }
    
    const tables = Array.from(document.querySelectorAll('.report-card .data-table')).filter(
        t => t.querySelector('tbody tr')
    );
    
    if (!tables.length) { 
        alert('No table data found to export.'); 
        return; 
    }
    
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Technical_Report_${dateFrom}_to_${dateTo}`;
    
    if (type === 'csv') {
        exportCSV(tables, filename);
    } else {
        exportExcel(tables, filename);
    }
}

function tableToAoA(table) {
    const aoa = [];
    table.querySelectorAll('thead tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('th')].map(th => th.innerText.trim()));
    });
    table.querySelectorAll('tbody tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim()));
    });
    return aoa;
}

function exportExcel(tables, filename) {
    const wb = XLSX.utils.book_new();
    const usedNames = {};
    
    tables.forEach((tbl, i) => {
        const card = tbl.closest('.report-card');
        let sheetName = card?.querySelector('.report-card-title')?.innerText?.trim() || `Sheet ${i + 1}`;
        sheetName = sheetName.replace(/[:\\\/?*\[\]]/g, '').substring(0, 31).trim() || `Sheet${i+1}`;
        
        if (usedNames[sheetName]) {
            usedNames[sheetName]++;
            sheetName = (sheetName.substring(0, 28) + ' ' + usedNames[sheetName]).substring(0,31);
        } else {
            usedNames[sheetName] = 1;
        }
        
        const aoa = tableToAoA(tbl);
        const ws = XLSX.utils.aoa_to_sheet(aoa);
        
        if (aoa.length && aoa[0]) {
            ws['!cols'] = aoa[0].map((_, ci) => ({
                wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] ?? '').length)))
            }));
        }
        XLSX.utils.book_append_sheet(wb, ws, sheetName);
    });
    
    XLSX.writeFile(wb, filename + '.xlsx');
}

function exportCSV(tables, filename) {
    let csv = '';
    tables.forEach((tbl, i) => {
        const card = tbl.closest('.report-card');
        const heading = card?.querySelector('.report-card-title')?.innerText?.trim();
        if (heading) csv += '"' + heading.replace(/"/g, '""') + '"\n';
        else if (i > 0) csv += '\n';
        tableToAoA(tbl).forEach(row => {
            csv += row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',') + '\n';
        });
        csv += '\n';
    });
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename + '.csv';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
