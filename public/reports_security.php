<?php
/**
 * Security Reports - Standalone Page
 * Login Attempts, Access Violations, Password Resets, Suspicious Activity
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
        CREATE TABLE IF NOT EXISTS login_attempts_security (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            attempt_type VARCHAR(20) NOT NULL COMMENT 'success, failed',
            ip_address VARCHAR(45),
            user_agent TEXT,
            failure_reason VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_attempt_type (attempt_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS access_violations_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            username VARCHAR(100),
            attempted_resource VARCHAR(255),
            violation_type VARCHAR(100) COMMENT 'unauthorized_page, privilege_escalation, forbidden_api',
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_violation_type (violation_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_reset_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(100),
            reset_method VARCHAR(50) COMMENT 'email, admin, security_question',
            ip_address VARCHAR(45),
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suspicious_activity_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            username VARCHAR(100),
            activity_type VARCHAR(100) COMMENT 'multiple_failed_logins, unusual_access_pattern, data_exfiltration',
            severity VARCHAR(20) DEFAULT 'medium',
            description TEXT,
            ip_address VARCHAR(45),
            status VARCHAR(20) DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_severity (severity),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log("Security Reports table creation: " . $e->getMessage());
}

// Fetch filters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Fetch data - Login Attempts
$stmt_logins = $pdo->prepare("
    SELECT 
        username,
        attempt_type,
        COUNT(*) as attempt_count,
        ip_address,
        MAX(created_at) as last_attempt
    FROM login_attempts_security
    WHERE created_at BETWEEN ? AND ?
    GROUP BY username, attempt_type, ip_address
    ORDER BY last_attempt DESC
    LIMIT 100
");
$stmt_logins->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$login_attempts = $stmt_logins->fetchAll(PDO::FETCH_ASSOC);

// Summary stats for login attempts
$stmt_login_stats = $pdo->prepare("
    SELECT 
        attempt_type,
        COUNT(*) as count
    FROM login_attempts_security
    WHERE created_at BETWEEN ? AND ?
    GROUP BY attempt_type
");
$stmt_login_stats->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$login_stats = [];
foreach ($stmt_login_stats->fetchAll(PDO::FETCH_ASSOC) as $stat) {
    $login_stats[$stat['attempt_type']] = $stat['count'];
}

// Access Violations
$stmt_violations = $pdo->prepare("
    SELECT * FROM access_violations_log
    WHERE created_at BETWEEN ? AND ?
    ORDER BY created_at DESC
    LIMIT 100
");
$stmt_violations->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$access_violations = $stmt_violations->fetchAll(PDO::FETCH_ASSOC);

// Password Reset Logs
$stmt_resets = $pdo->prepare("
    SELECT * FROM password_reset_logs
    WHERE created_at BETWEEN ? AND ?
    ORDER BY created_at DESC
    LIMIT 100
");
$stmt_resets->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$password_resets = $stmt_resets->fetchAll(PDO::FETCH_ASSOC);

// Suspicious Activity
$stmt_suspicious = $pdo->prepare("
    SELECT * FROM suspicious_activity_alerts
    WHERE created_at BETWEEN ? AND ?
    ORDER BY severity DESC, created_at DESC
    LIMIT 100
");
$stmt_suspicious->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
$suspicious_activity = $stmt_suspicious->fetchAll(PDO::FETCH_ASSOC);

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
            SECURITY REPORTS
        </div>
        <div style="font-size:16px;font-weight:700;color:#003366;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
            DEVELOPER VIEW
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
            Login Attempts • Access Violations • Password Resets • Suspicious Activity
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
        window.location.href = '../backend/api/developer_reports_api.php?action=export_csv&report_type=security&date_from=' + dateFrom + '&date_to=' + dateTo;
    }
    </script>

    <!-- Login Attempts Summary -->
    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-label">Successful Logins</div>
            <div class="stat-value" style="color: var(--success-color);">
                <?php echo number_format($login_stats['success'] ?? 0); ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Failed Logins</div>
            <div class="stat-value" style="color: var(--danger-color);">
                <?php echo number_format($login_stats['failed'] ?? 0); ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Access Violations</div>
            <div class="stat-value" style="color: var(--warning-color);">
                <?php echo number_format(count($access_violations)); ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Suspicious Activity</div>
            <div class="stat-value" style="color: var(--danger-color);">
                <?php echo number_format(count($suspicious_activity)); ?>
            </div>
        </div>
    </div>

    <!-- Login Attempts -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-sign-in-alt"></i> Login Attempts</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($login_attempts)): ?>
                <div class="empty-state">
                    <i class="fas fa-sign-in-alt" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No login attempts recorded</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Type</th>
                                <th>Attempt Count</th>
                                <th>IP Address</th>
                                <th>Last Attempt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($login_attempts as $attempt): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($attempt['username']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $attempt['attempt_type'] === 'success' ? 'success' : 'danger'; ?>">
                                            <?php echo strtoupper($attempt['attempt_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($attempt['attempt_count']); ?></td>
                                    <td><?php echo htmlspecialchars($attempt['ip_address'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($attempt['last_attempt'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Access Violations -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-shield-alt"></i> Access Violations</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($access_violations)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle" style="font-size: 3rem; opacity: 0.3; color: var(--success-color);"></i>
                    <p>No access violations. System is secure!</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Resource</th>
                                <th>Violation Type</th>
                                <th>IP Address</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($access_violations as $violation): ?>
                                <tr>
                                    <td><?php echo $violation['id']; ?></td>
                                    <td><?php echo htmlspecialchars($violation['username'] ?? 'Unknown'); ?></td>
                                    <td><?php echo htmlspecialchars($violation['attempted_resource']); ?></td>
                                    <td>
                                        <span class="badge badge-danger">
                                            <?php echo htmlspecialchars($violation['violation_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($violation['ip_address'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($violation['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Password Reset Logs -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-key"></i> Password Reset Logs</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($password_resets)): ?>
                <div class="empty-state">
                    <i class="fas fa-key" style="font-size: 3rem; opacity: 0.3;"></i>
                    <p>No password resets recorded</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Reset Method</th>
                                <th>Status</th>
                                <th>IP Address</th>
                                <th>Requested</th>
                                <th>Completed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($password_resets as $reset): ?>
                                <tr>
                                    <td><?php echo $reset['id']; ?></td>
                                    <td><?php echo htmlspecialchars($reset['username'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($reset['reset_method'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $reset['status'] === 'completed' ? 'success' : 'warning'; ?>">
                                            <?php echo strtoupper($reset['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($reset['ip_address'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($reset['created_at'])); ?></td>
                                    <td><?php echo $reset['completed_at'] ? date('Y-m-d H:i:s', strtotime($reset['completed_at'])) : 'Pending'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Suspicious Activity -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title"><i class="fas fa-exclamation-triangle"></i> Suspicious Activity Alerts</h3>
        </div>
        <div class="report-card-body">
            <?php if (empty($suspicious_activity)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle" style="font-size: 3rem; opacity: 0.3; color: var(--success-color);"></i>
                    <p>No suspicious activity detected. All clear!</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Activity Type</th>
                                <th>Severity</th>
                                <th>Description</th>
                                <th>IP Address</th>
                                <th>Status</th>
                                <th>Detected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suspicious_activity as $activity): ?>
                                <tr>
                                    <td><?php echo $activity['id']; ?></td>
                                    <td><?php echo htmlspecialchars($activity['username'] ?? 'Unknown'); ?></td>
                                    <td><?php echo htmlspecialchars($activity['activity_type']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $activity['severity'] === 'high' ? 'danger' : 
                                                ($activity['severity'] === 'medium' ? 'warning' : 'info'); 
                                        ?>">
                                            <?php echo strtoupper($activity['severity']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($activity['description'] ?? '', 0, 50)) . '...'; ?></td>
                                    <td><?php echo htmlspecialchars($activity['ip_address'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $activity['status'] === 'resolved' ? 'success' : 'danger'; ?>">
                                            <?php echo strtoupper($activity['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i:s', strtotime($activity['created_at'])); ?></td>
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
