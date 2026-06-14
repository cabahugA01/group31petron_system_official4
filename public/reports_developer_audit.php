<?php
/**
 * Developer Audit Reports - Standalone Page
 * Code Changes, Configuration Updates, Deployment Logs, Integration Changes
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
        CREATE TABLE IF NOT EXISTS code_changes_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            commit_hash VARCHAR(100),
            author_id INT,
            author_name VARCHAR(100),
            files_modified TEXT COMMENT 'JSON array of file paths',
            lines_added INT DEFAULT 0,
            lines_removed INT DEFAULT 0,
            commit_message TEXT,
            branch_name VARCHAR(100),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_author_id (author_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS config_updates_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            user_name VARCHAR(100),
            config_type VARCHAR(100) COMMENT 'system_settings, database, permissions, api',
            setting_key VARCHAR(255),
            old_value TEXT,
            new_value TEXT,
            reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_config_type (config_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deployment_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version_number VARCHAR(50),
            deployed_by_id INT,
            deployed_by_name VARCHAR(100),
            deployment_type VARCHAR(50) COMMENT 'release, hotfix, rollback',
            status VARCHAR(20) DEFAULT 'in_progress',
            environment VARCHAR(50) DEFAULT 'production',
            notes TEXT,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_version (version_number),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS integration_changes_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            user_name VARCHAR(100),
            integration_type VARCHAR(100) COMMENT 'api_key, endpoint, webhook, sync_rule',
            integration_name VARCHAR(255),
            change_type VARCHAR(50) COMMENT 'created, updated, deleted',
            old_config TEXT,
            new_config TEXT,
            reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_integration_type (integration_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log("Developer Audit Reports table creation: " . $e->getMessage());
}

// Fetch filters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Fetch data - Code Changes
$stmt_code = $pdo->prepare("
    SELECT * FROM code_changes_audit
    WHERE created_at BETWEEN ? AND ?
    ORDER BY created_at DESC
    LIMIT 100
");
$stmt_code->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$code_changes = $stmt_code->fetchAll(PDO::FETCH_ASSOC);

// Configuration Updates
$stmt_config = $pdo->prepare("
    SELECT * FROM config_updates_audit
    WHERE created_at BETWEEN ? AND ?
    ORDER BY created_at DESC
    LIMIT 100
");
$stmt_config->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$config_updates = $stmt_config->fetchAll(PDO::FETCH_ASSOC);

// Deployment Logs
$stmt_deploy = $pdo->prepare("
    SELECT * FROM deployment_logs
    WHERE started_at BETWEEN ? AND ?
    ORDER BY started_at DESC
    LIMIT 50
");
$stmt_deploy->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$deployments = $stmt_deploy->fetchAll(PDO::FETCH_ASSOC);

// Integration Changes
$stmt_integration = $pdo->prepare("
    SELECT * FROM integration_changes_audit
    WHERE created_at BETWEEN ? AND ?
    ORDER BY created_at DESC
    LIMIT 100
");
$stmt_integration->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$integration_changes = $stmt_integration->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary stats
$total_commits = count($code_changes);
$total_config_changes = count($config_updates);
$total_deployments = count($deployments);
$successful_deployments = count(array_filter($deployments, fn($d) => $d['status'] === 'completed'));

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
    margin-bottom: 24px;
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
.badge-info { background: rgba(59, 130, 246, 0.1); color: var(--info-color); }

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
            DEVELOPER AUDIT REPORTS
        </div>
        <div style="font-size:16px;font-weight:700;color:#003366;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
            DEVELOPER VIEW
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
            Code Changes • Configuration Updates • Deployment Logs • Integration Changes
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
        window.location.href = '../backend/api/developer_reports_api.php?action=export_csv&report_type=developer_audit&date_from=' + dateFrom + '&date_to=' + dateTo;
    }
    </script>

    <!-- Summary Stats -->
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">Code Changes</div>
            <div class="stat-value" style="color: var(--info-color);">
                <?php echo number_format($total_commits); ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Config Updates</div>
            <div class="stat-value" style="color: var(--warning-color);">
                <?php echo number_format($total_config_changes); ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Total Deployments</div>
            <div class="stat-value" style="color: var(--primary-color);">
                <?php echo number_format($total_deployments); ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Successful Deployments</div>
            <div class="stat-value" style="color: var(--success-color);">
                <?php echo number_format($successful_deployments); ?>
            </div>
        </div>
    </div>

    <!-- Code Changes -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-code-branch"></i> Code Changes</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($code_changes)): ?>
                <div class="empty-state">
                    <i class="fas fa-code-branch" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No code changes recorded</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Commit Hash</th>
                                <th>Author</th>
                                <th>Branch</th>
                                <th>Lines +/-</th>
                                <th>Message</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($code_changes as $change): ?>
                                <tr>
                                    <td><?php echo $change['id']; ?></td>
                                    <td><code><?php echo htmlspecialchars(substr($change['commit_hash'] ?? 'N/A', 0, 8)); ?></code></td>
                                    <td><?php echo htmlspecialchars($change['author_name'] ?? 'Unknown'); ?></td>
                                    <td><span class="badge badge-info"><?php echo htmlspecialchars($change['branch_name'] ?? 'main'); ?></span></td>
                                    <td>
                                        <span style="color: var(--success-color);">+<?php echo $change['lines_added']; ?></span> /
                                        <span style="color: var(--danger-color);">-<?php echo $change['lines_removed']; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($change['commit_message'] ?? '', 0, 50)) . '...'; ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($change['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Configuration Updates -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-cog"></i> Configuration Updates</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($config_updates)): ?>
                <div class="empty-state">
                    <i class="fas fa-cog" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No configuration updates recorded</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Config Type</th>
                                <th>Setting Key</th>
                                <th>Old Value</th>
                                <th>New Value</th>
                                <th>Reason</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($config_updates as $config): ?>
                                <tr>
                                    <td><?php echo $config['id']; ?></td>
                                    <td><?php echo htmlspecialchars($config['user_name'] ?? 'System'); ?></td>
                                    <td><span class="badge badge-warning"><?php echo htmlspecialchars($config['config_type']); ?></span></td>
                                    <td><code><?php echo htmlspecialchars($config['setting_key']); ?></code></td>
                                    <td><?php echo htmlspecialchars(substr($config['old_value'] ?? '', 0, 20)); ?></td>
                                    <td><?php echo htmlspecialchars(substr($config['new_value'] ?? '', 0, 20)); ?></td>
                                    <td><?php echo htmlspecialchars(substr($config['reason'] ?? 'N/A', 0, 30)); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($config['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Deployment Logs -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-rocket"></i> Deployment Logs</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($deployments)): ?>
                <div class="empty-state">
                    <i class="fas fa-rocket" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No deployments recorded</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Version</th>
                                <th>Deployed By</th>
                                <th>Type</th>
                                <th>Environment</th>
                                <th>Status</th>
                                <th>Started</th>
                                <th>Completed</th>
                                <th>Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deployments as $deploy): ?>
                                <?php
                                $duration = '';
                                if ($deploy['completed_at']) {
                                    $start = new DateTime($deploy['started_at']);
                                    $end = new DateTime($deploy['completed_at']);
                                    $diff = $start->diff($end);
                                    $duration = $diff->format('%H:%I:%S');
                                }
                                ?>
                                <tr>
                                    <td><?php echo $deploy['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($deploy['version_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($deploy['deployed_by_name'] ?? 'System'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $deploy['deployment_type'] === 'release' ? 'success' : 
                                                ($deploy['deployment_type'] === 'hotfix' ? 'warning' : 'secondary'); 
                                        ?>">
                                            <?php echo strtoupper($deploy['deployment_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($deploy['environment']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $deploy['status'] === 'completed' ? 'success' : 
                                                ($deploy['status'] === 'failed' ? 'danger' : 'info'); 
                                        ?>">
                                            <?php echo strtoupper($deploy['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($deploy['started_at'])); ?></td>
                                    <td><?php echo $deploy['completed_at'] ? date('Y-m-d H:i', strtotime($deploy['completed_at'])) : 'In Progress'; ?></td>
                                    <td><?php echo $duration ?: '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Integration Changes -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-plug"></i> Integration Changes</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($integration_changes)): ?>
                <div class="empty-state">
                    <i class="fas fa-plug" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No integration changes recorded</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Integration Type</th>
                                <th>Integration Name</th>
                                <th>Change Type</th>
                                <th>Reason</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($integration_changes as $integration): ?>
                                <tr>
                                    <td><?php echo $integration['id']; ?></td>
                                    <td><?php echo htmlspecialchars($integration['user_name'] ?? 'System'); ?></td>
                                    <td><span class="badge badge-info"><?php echo htmlspecialchars($integration['integration_type']); ?></span></td>
                                    <td><?php echo htmlspecialchars($integration['integration_name']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $integration['change_type'] === 'created' ? 'success' : 
                                                ($integration['change_type'] === 'deleted' ? 'danger' : 'warning'); 
                                        ?>">
                                            <?php echo strtoupper($integration['change_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($integration['reason'] ?? 'N/A', 0, 40)); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($integration['created_at'])); ?></td>
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
