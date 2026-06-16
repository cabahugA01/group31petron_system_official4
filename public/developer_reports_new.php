<?php
/**
 * Developer Reports - Complete Implementation
 * Real data capture from database (NO precoded data)
 * Includes: Technical Reports, Security Reports, Developer Audit Reports
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

// Only Developer and SuperAdmin can access
if (!in_array($roleKey, ['superadmin', 'developer'], true)) {
    header('Location: dashboard.php');
    exit;
}

$page_id = 'developer_reports';
$station_name = '';

// Ensure required tables exist
try {
    // System Performance Logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_performance_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            metric_type VARCHAR(50) NOT NULL COMMENT 'cpu, memory, bandwidth, query_time',
            metric_value DECIMAL(10,2) NOT NULL,
            metric_unit VARCHAR(20) NOT NULL COMMENT 'percent, mb, ms, etc',
            module_name VARCHAR(100),
            endpoint VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_metric_type (metric_type),
            INDEX idx_created_at (created_at),
            INDEX idx_module (module_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Error Tracking table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS error_tracking_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            error_type VARCHAR(100) NOT NULL COMMENT 'system_error, failed_process, crash',
            error_message TEXT,
            error_code VARCHAR(50),
            module_name VARCHAR(100),
            file_path VARCHAR(255),
            line_number INT,
            stack_trace TEXT,
            severity VARCHAR(20) DEFAULT 'error' COMMENT 'critical, error, warning',
            status VARCHAR(20) DEFAULT 'unresolved' COMMENT 'unresolved, investigating, resolved',
            resolved_by INT,
            resolved_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_error_type (error_type),
            INDEX idx_severity (severity),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Module Health Monitoring table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS module_health_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_name VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL COMMENT 'up, down, degraded',
            uptime_seconds INT DEFAULT 0,
            downtime_seconds INT DEFAULT 0,
            last_check DATETIME DEFAULT CURRENT_TIMESTAMP,
            response_time_ms DECIMAL(10,2),
            health_score INT DEFAULT 100 COMMENT '0-100',
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_module (module_name),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Login Attempts table (extends existing if needed)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts_security (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            username VARCHAR(100),
            attempt_type VARCHAR(20) NOT NULL COMMENT 'success, failed',
            ip_address VARCHAR(45),
            user_agent TEXT,
            failure_reason VARCHAR(255),
            location_info VARCHAR(255) COMMENT 'geolocation if available',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_attempt_type (attempt_type),
            INDEX idx_ip_address (ip_address),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Access Violations table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS access_violations_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            username VARCHAR(100),
            violation_type VARCHAR(50) NOT NULL COMMENT 'unauthorized_access, permission_denied',
            attempted_resource VARCHAR(255),
            required_role VARCHAR(50),
            user_role VARCHAR(50),
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_violation_type (violation_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Password Reset Logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_reset_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            username VARCHAR(100),
            reset_method VARCHAR(50) COMMENT 'email, admin, self_service',
            ip_address VARCHAR(45),
            initiated_by INT COMMENT 'admin user_id if admin-initiated',
            status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending, completed, failed',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME,
            INDEX idx_user_id (user_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Suspicious Activity Alerts table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suspicious_activity_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            alert_type VARCHAR(100) NOT NULL COMMENT 'multiple_failed_logins, unusual_location, etc',
            user_id INT,
            username VARCHAR(100),
            ip_address VARCHAR(45),
            anomaly_description TEXT,
            severity VARCHAR(20) DEFAULT 'medium' COMMENT 'low, medium, high, critical',
            status VARCHAR(20) DEFAULT 'new' COMMENT 'new, investigating, false_positive, confirmed',
            investigated_by INT,
            investigated_at DATETIME,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_alert_type (alert_type),
            INDEX idx_severity (severity),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Code Changes Audit table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS code_changes_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            developer_id INT NOT NULL,
            developer_name VARCHAR(150),
            change_type VARCHAR(50) NOT NULL COMMENT 'commit, rollback, merge',
            commit_hash VARCHAR(255),
            files_modified INT DEFAULT 0,
            files_added INT DEFAULT 0,
            files_deleted INT DEFAULT 0,
            lines_added INT DEFAULT 0,
            lines_removed INT DEFAULT 0,
            branch_name VARCHAR(100),
            commit_message TEXT,
            repository VARCHAR(100),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_developer_id (developer_id),
            INDEX idx_change_type (change_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Configuration Updates Audit table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS config_updates_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            developer_id INT NOT NULL,
            developer_name VARCHAR(150),
            config_type VARCHAR(50) NOT NULL COMMENT 'system_settings, module_config, ui_config',
            config_key VARCHAR(255),
            old_value TEXT,
            new_value TEXT,
            change_reason TEXT,
            ip_address VARCHAR(45),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_developer_id (developer_id),
            INDEX idx_config_type (config_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Deployment Logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deployment_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            deployed_by INT NOT NULL,
            deployer_name VARCHAR(150),
            deployment_type VARCHAR(50) NOT NULL COMMENT 'release, hotfix, rollback',
            version_from VARCHAR(50),
            version_to VARCHAR(50),
            environment VARCHAR(50) DEFAULT 'production' COMMENT 'development, staging, production',
            status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending, in_progress, success, failed',
            deployment_notes TEXT,
            rollback_available BOOLEAN DEFAULT TRUE,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME,
            INDEX idx_deployed_by (deployed_by),
            INDEX idx_deployment_type (deployment_type),
            INDEX idx_status (status),
            INDEX idx_started_at (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Integration Changes Audit table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS integration_changes_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            developer_id INT NOT NULL,
            developer_name VARCHAR(150),
            integration_type VARCHAR(50) NOT NULL COMMENT 'api_key, endpoint, sync_rule',
            integration_name VARCHAR(100),
            change_type VARCHAR(50) COMMENT 'create, update, delete, rotate',
            old_value TEXT,
            new_value TEXT,
            affected_systems TEXT COMMENT 'JSON array of affected external systems',
            change_reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_developer_id (developer_id),
            INDEX idx_integration_type (integration_type),
            INDEX idx_change_type (change_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Report Access Audit table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_access_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            user_name VARCHAR(150),
            report_type VARCHAR(50) NOT NULL COMMENT 'technical, security, developer_audit',
            report_section VARCHAR(100),
            action VARCHAR(50) NOT NULL COMMENT 'view, export_csv, export_pdf, print',
            filters_applied TEXT COMMENT 'JSON of applied filters',
            ip_address VARCHAR(45),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_report_type (report_type),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

} catch (Exception $e) {
    // Tables might already exist or have permissions issues
    error_log("Developer Reports table creation: " . $e->getMessage());
}

include __DIR__ . '/../partials/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root {
    --primary-color: #003366;
    --secondary-color: #667085;
    --success-color: #10b981;
    --warning-color: #f59e0b;
    --danger-color: #ef4444;
    --info-color: #3b82f6;
    --bg-primary: #ffffff;
    --bg-secondary: #f8fafc;
    --bg-tertiary: #f1f5f9;
    --text-primary: #111827;
    --text-secondary: #6b7280;
    --border-color: #e5e7eb;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    --radius-md: 8px;
    --radius-lg: 12px;
}

.main-content {
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

.page-subtitle {
    color: var(--text-secondary);
    font-size: 0.938rem;
}

.tabs-container {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    padding: 16px 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
}

.report-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.tab-btn {
    padding: 10px 20px;
    border: none;
    background: var(--bg-secondary);
    color: var(--text-secondary);
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}

.tab-btn:hover {
    background: var(--bg-tertiary);
    color: var(--text-primary);
}

.tab-btn.active {
    background: var(--primary-color);
    color: white;
}

.report-section {
    display: none;
}

.report-section.active {
    display: block;
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

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-group label {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.875rem;
}

.form-control {
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    background: var(--bg-primary);
    font-size: 0.875rem;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: var(--primary-color);
    color: white;
}

.btn-primary:hover {
    background: #002244;
}

.btn-success {
    background: var(--success-color);
    color: white;
}

.btn-success:hover {
    background: #059669;
}

.btn-secondary {
    background: var(--bg-tertiary);
    color: var(--text-primary);
}

.btn-secondary:hover {
    background: #e2e8f0;
}

.actions-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
}

.actions-left, .actions-right {
    display: flex;
    gap: 8px;
}

.report-card {
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border-color);
    overflow: hidden;
}

.report-card-header {
    padding: 16px 24px;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
}

.report-card-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--text-primary);
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
    box-shadow: var(--shadow-sm);
}

.stat-label {
    font-size: 0.813rem;
    color: var(--text-secondary);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    margin-bottom: 12px;
}

.data-table-container {
    overflow:hidden;
    margin-top: 20px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.data-table thead th {
    background: var(--bg-tertiary);
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: var(--text-primary);
    border-bottom: 2px solid var(--border-color);
    }

.data-table tbody td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-secondary);
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
.badge-info { background: rgba(59, 130, 246, 0.1); color: var(--info-color); }
.badge-secondary { background: rgba(107, 114, 128, 0.1); color: var(--text-secondary); }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-secondary);
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.3;
}

@media (max-width: 768px) {
    .main-content {
        padding: 16px;
    }
    
    .filters-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .actions-bar {
        flex-direction: column;
        gap: 12px;
    }
}
</style>

<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-chart-line"></i> Developer Reports
        </h1>
        <p class="page-subtitle">System/Domain Technical Monitoring & Security Audit</p>
    </div>

    <!-- Report Tabs -->
    <div class="tabs-container">
        <div class="report-tabs">
            <button class="tab-btn active" onclick="switchTab('technical')">
                <i class="fas fa-server"></i> Technical Reports
            </button>
            <button class="tab-btn" onclick="switchTab('security')">
                <i class="fas fa-shield-alt"></i> Security Reports
            </button>
            <button class="tab-btn" onclick="switchTab('developer_audit')">
                <i class="fas fa-code-branch"></i> Developer Audit
            </button>
            <button class="tab-btn" onclick="switchTab('audit_trail')">
                <i class="fas fa-history"></i> Audit Trail
            </button>
        </div>
    </div>

    <!-- Technical Reports Section -->
    <div id="section-technical" class="report-section active">
        <?php include __DIR__ . '/../partials/reports/technical_reports.php'; ?>
    </div>

    <!-- Security Reports Section -->
    <div id="section-security" class="report-section">
        <?php include __DIR__ . '/../partials/reports/security_reports.php'; ?>
    </div>

    <!-- Developer Audit Section -->
    <div id="section-developer_audit" class="report-section">
        <?php include __DIR__ . '/../partials/reports/developer_audit_reports.php'; ?>
    </div>

    <!-- Audit Trail Section -->
    <div id="section-audit_trail" class="report-section">
        <?php include __DIR__ . '/../partials/reports/audit_trail_reports.php'; ?>
    </div>
</main>

<script>
// Tab Switching
function switchTab(tabName) {
    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.target.closest('.tab-btn').classList.add('active');
    
    // Update sections
    document.querySelectorAll('.report-section').forEach(section => section.classList.remove('active'));
    document.getElementById(`section-${tabName}`).classList.add('active');
    
    // Log report access for audit trail
    logReportAccess(tabName, 'view');
}

// Log Report Access
function logReportAccess(reportType, action) {
    fetch('../backend/api/developer_reports_api.php?action=log_access', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            report_type: reportType,
            action: action
        })
    }).catch(err => console.error('Failed to log access:', err));
}

// Export Functions
function exportReport(format, reportType) {
    const filters = gatherFilters(reportType);
    const url = `../backend/api/developer_reports_api.php?action=export&format=${format}&report_type=${reportType}&filters=${encodeURIComponent(JSON.stringify(filters))}`;
    
    // Log export action
    logReportAccess(reportType, `export_${format}`);
    
    // Download
    window.location.href = url;
}

function gatherFilters(reportType) {
    const section = document.getElementById(`section-${reportType}`);
    if (!section) return {};
    
    const filters = {};
    section.querySelectorAll('.form-control').forEach(input => {
        if (input.value) {
            filters[input.name || input.id] = input.value;
        }
    });
    
    return filters;
}

// Refresh Data
function refreshData(reportType) {
    location.reload();
}

// Print Report
function printReport(reportType) {
    logReportAccess(reportType, 'print');
    window.print();
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    console.log('Developer Reports initialized');
    
    // Log initial page view
    logReportAccess('technical', 'view');
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
