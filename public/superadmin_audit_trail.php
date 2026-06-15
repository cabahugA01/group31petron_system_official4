<?php
/**
 * Developer Audit Trail - Complete Estate Form
 * System Access, Config Changes, Code/Deployment, Error/Security Tracking, Export & Compliance
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

$page_id = 'audit_trail';
$station_name = '';

// Filters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$user_filter = $_GET['user_filter'] ?? '';
$action_filter = $_GET['action_filter'] ?? '';

// ══════════════════════════════════════════════════════════════════════════════
// SECTION 1: SYSTEM ACCESS LOGS (Login/Logout Events, Session Duration)
// ══════════════════════════════════════════════════════════════════════════════
$access_logs = [];
try {
    $sql = "SELECT al.id, al.user_id, u.username, u.email, al.action_type, al.action_details,
                   al.ip_address, al.user_agent, al.created_at, al.status
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE (al.action_type LIKE '%Login%' OR al.action_type LIKE '%Logout%' OR al.log_type = 'user')
            AND al.created_at BETWEEN ? AND ?";
    
    if ($user_filter) {
        $sql .= " AND (al.user_id = ? OR u.username LIKE ? OR u.email LIKE ?)";
    }
    
    $sql .= " ORDER BY al.created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    if ($user_filter) {
        $stmt->execute([$date_from.' 00:00:00', $date_to.' 23:59:59', $user_filter, "%$user_filter%", "%$user_filter%"]);
    } else {
        $stmt->execute([$date_from.' 00:00:00', $date_to.' 23:59:59']);
    }
    $access_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Access Logs Error: " . $e->getMessage());
    $access_logs = [];
}

// ══════════════════════════════════════════════════════════════════════════════
// SECTION 2: CONFIGURATION CHANGES (System Settings, Integration, Database)
// ══════════════════════════════════════════════════════════════════════════════
$config_changes = [];
try {
    $sql = "SELECT al.id, al.user_id, u.username, u.email, al.action_type, al.entity_type, al.entity_id, 
                   al.action_details, al.old_values, al.new_values, al.created_at
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE (al.action_type IN ('config_update', 'settings_change', 'integration_update', 'database_config', 
                                     'Update', 'Create', 'Settings Update', 'Delete')
                   OR al.log_type = 'system')
            AND al.created_at BETWEEN ? AND ?";
    
    if ($user_filter) {
        $sql .= " AND (al.user_id = ? OR u.username LIKE ? OR u.email LIKE ?)";
    }
    
    $sql .= " ORDER BY al.created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    if ($user_filter) {
        $stmt->execute([$date_from.' 00:00:00', $date_to.' 23:59:59', $user_filter, "%$user_filter%", "%$user_filter%"]);
    } else {
        $stmt->execute([$date_from.' 00:00:00', $date_to.' 23:59:59']);
    }
    $config_changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Config Changes Error: " . $e->getMessage());
    $config_changes = [];
}

// ══════════════════════════════════════════════════════════════════════════════
// SECTION 3: CODE & DEPLOYMENT LOGS (Git Commits, Merges, Deployments)
// ══════════════════════════════════════════════════════════════════════════════
$deployment_logs = [];
try {
    $sql = "SELECT al.id, al.user_id, u.username, u.email, al.action_type, al.action_details, 
                   al.entity_type, al.created_at
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.action_type IN ('git_commit', 'git_merge', 'deployment', 'rollback', 'Code Deployment', 
                                     'System Update', 'Version Update', 'Deploy', 'Release')
            AND al.created_at BETWEEN ? AND ?";
    
    if ($user_filter) {
        $sql .= " AND (al.user_id = ? OR u.username LIKE ? OR u.email LIKE ?)";
    }
    
    $sql .= " ORDER BY al.created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    if ($user_filter) {
        $stmt->execute([$date_from.' 00:00:00', $date_to.' 23:59:59', $user_filter, "%$user_filter%", "%$user_filter%"]);
    } else {
        $stmt->execute([$date_from.' 00:00:00', $date_to.' 23:59:59']);
    }
    $deployment_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Deployment Logs Error: " . $e->getMessage());
    $deployment_logs = [];
}

// ══════════════════════════════════════════════════════════════════════════════
// SECTION 4: ERROR & SECURITY TRACKING (System Errors, Security Alerts, Password Resets)
// ══════════════════════════════════════════════════════════════════════════════
$security_logs = [];
try {
    $sql = "SELECT al.id, al.user_id, u.username, u.email, al.action_type, al.error_message,
                   al.ip_address, al.status, al.action_details, al.created_at
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE (al.action_type IN ('system_error', 'security_alert', 'unauthorized_access', 
                                      'password_reset', 'suspicious_activity', 'failed_validation', 
                                      'Login Failed', 'Access Denied', 'Failed Login')
                   OR al.status = 'Failed'
                   OR al.error_message IS NOT NULL
                   OR al.action_type LIKE '%Failed%'
                   OR al.action_type LIKE '%Error%')
            AND al.created_at BETWEEN ? AND ?";
    
    if ($user_filter) {
        $sql .= " AND (al.user_id = ? OR u.username LIKE ? OR u.email LIKE ?)";
    }
    
    $sql .= " ORDER BY al.created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    if ($user_filter) {
        $stmt->execute([$date_from.' 00:00:00', $date_to.' 23:59:59', $user_filter, "%$user_filter%", "%$user_filter%"]);
    } else {
        $stmt->execute([$date_from.' 00:00:00', $date_to.' 23:59:59']);
    }
    $security_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Security Logs Error: " . $e->getMessage());
    $security_logs = [];
}

// ══════════════════════════════════════════════════════════════════════════════
// SECTION 5: EXPORT & COMPLIANCE (Export Logs, Audit of Audit Actions)
// ══════════════════════════════════════════════════════════════════════════════
$export_logs = [];
try {
    $sql = "SELECT al.id, al.user_id, u.username, u.email, al.action_type, al.action_details,
                   al.entity_type, al.created_at
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE (al.action_type IN ('export_audit', 'export_report', 'view_audit_log', 'compliance_report',
                                     'Export', 'Report Export', 'Data Export', 'View')
                   OR al.action_type LIKE '%Export%'
                   OR al.action_type LIKE '%Download%')
            AND al.created_at BETWEEN ? AND ?";
    
    if ($user_filter) {
        $sql .= " AND (al.user_id = ? OR u.username LIKE ? OR u.email LIKE ?)";
    }
    
    $sql .= " ORDER BY al.created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    if ($user_filter) {
        $stmt->execute([$date_from.' 00:00:00', $date_to.' 23:59:59', $user_filter, "%$user_filter%", "%$user_filter%"]);
    } else {
        $stmt->execute([$date_from.' 00:00:00', $date_to.' 23:59:59']);
    }
    $export_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Export Logs Error: " . $e->getMessage());
    $export_logs = [];
}

// Summary Statistics
$total_access = count($access_logs);
$total_config = count($config_changes);
$total_deploy = count($deployment_logs);
$total_security = count($security_logs);
$total_exports = count($export_logs);

include __DIR__ . '/../partials/header.php';
?>

<style>
:root {
    --petron-blue: #003366;
    --petron-red: #E30613;
    --success-color: #10b981;
    --warning-color: #f59e0b;
    --danger-color: #ef4444;
    --info-color: #3b82f6;
    --bg-primary: #ffffff;
    --bg-secondary: #f8fafc;
    --text-primary: #111827;
    --text-secondary: #6b7280;
    --border-color: #e5e7eb;
}

.audit-container {
    padding: 0 24px 24px;
    background: var(--bg-secondary);
    min-height: 100vh;
}

.filters-card {
    background: var(--bg-primary);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid var(--border-color);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
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
    border-radius: 8px;
    font-size: 0.875rem;
}

.btn {
    padding: 7px 14px;
    border-radius: 4px;
    font-weight: 600;
    font-size: 11px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-primary { 
    background: white !important;
    color: #00264D !important;
    border: 1px solid #00264D !important;
}

.btn-primary:hover {
    background: #00264D !important;
    color: white !important;
}

.btn-secondary { 
    background: white !important;
    color: #6b7280 !important;
    border: 1px solid #6b7280 !important;
}

.btn-secondary:hover {
    background: #6b7280 !important;
    color: white !important;
}

.audit-section {
    background: var(--bg-primary);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid var(--border-color);
    margin-bottom: 24px;
}

.section-header {
    padding: 16px 24px;
    background: linear-gradient(135deg, var(--petron-blue) 0%, #004080 100%);
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.section-header * {
    color: white !important;
}

.section-icon {
    font-size: 1.5rem;
    color: white !important;
}

.section-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: white !important;
    margin: 0;
}

.section-body {
    padding: 24px;
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
    color: var(--text-primary);
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
.badge-info { background: rgba(59, 130, 246, 0.1); color: var(--info-color); }
.badge-secondary { background: rgba(107, 114, 128, 0.1); color: var(--text-secondary); }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-secondary);
}

.empty-state i {
    font-size: 3rem;
    opacity: 0.3;
    margin-bottom: 16px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--bg-primary);
    border-radius: 12px;
    padding: 20px;
    border: 1px solid var(--border-color);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
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
    color: var(--petron-blue);
}

/* Export Actions - Same as Manager Reports */
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

/* ═══════════════════════════════════════════════════════════════════════════ */
/* PRINT STYLES - Same as Reports */
/* ═══════════════════════════════════════════════════════════════════════════ */
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
    nav, header, .no-print, .stats-grid, .stat-card, .stat-box {
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

    /* Audit container centering and width constraints */
    .audit-container {
        display: block !important;
        padding: 0 5px !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        background: white !important;
        overflow: visible !important;
    }

    /* Professional top rule */
    .audit-container::before {
        content: '';
        display: block;
        width: 100%;
        border-top: 3px solid #003366;
        margin-bottom: 2px;
    }

    /* Reduce title spacing */
    .audit-container > div:first-child {
        padding: 8px 0 4px 0 !important;
        margin-bottom: 8px !important;
        border-bottom: 1px solid #9aafcc !important;
    }

    /* Section cards */
    .audit-section {
        page-break-inside: auto !important;
        break-inside: auto !important;
        margin-bottom: 12px !important;
        border: 1px solid #b0bec8 !important;
        box-shadow: none !important;
        width: 100% !important;
        overflow: visible !important;
    }

    .section-header {
        background: #eef2f8 !important;
        padding: 6px 10px !important;
        border-bottom: 1px solid #b0bec8 !important;
        background-image: none !important;
    }

    .section-header *,
    .section-icon,
    .section-title {
        font-size: 10px !important;
        font-weight: 700 !important;
        color: #003366 !important;
        margin: 0 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
    }

    .section-body {
        padding: 8px 10px !important;
        overflow: visible !important;
        height: auto !important;
    }

    /* Kill overflow wrappers around tables in print */
    div[style*="overflow-x"],
    .section-body > div {
        overflow: visible !important;
        overflow-x: visible !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    /* Hide empty state messages */
    .empty-state {
        display: none !important;
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

    .data-table tbody tr { 
        page-break-inside: avoid !important; 
    }

    /* Badges in print */
    .badge {
        display: inline-block !important;
        padding: 2px 6px !important;
        font-size: 6.5px !important;
        border: 1px solid currentColor !important;
        border-radius: 3px !important;
        font-weight: 700 !important;
    }

    /* Code elements */
    code {
        font-size: 6.5px !important;
        font-family: 'Courier New', monospace !important;
        background: #f3f4f6 !important;
        padding: 1px 3px !important;
        border-radius: 2px !important;
    }

    /* Ensure all sections print */
    .audit-section {
        page-break-after: auto !important;
    }
}
</style>

<div class="audit-container">
    <!-- Page Header - Manager Style (same as reports) -->
    <div style="text-align:center;padding:0 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:20px;margin-top:-12px;">
        <div style="font-size:20px;font-weight:800;color:#003366;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">
            DEVELOPER AUDIT TRAIL
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
            System Access • Configuration Changes • Code & Deployment • Error & Security • Export & Compliance
        </div>
        <div style="font-size:12px;color:#334155;">
            <strong>Date:</strong>
            <?php echo date('F j, Y', strtotime($date_from)); ?>
            <?php echo $date_from !== $date_to ? ' – ' . date('F j, Y', strtotime($date_to)) : ''; ?>
        </div>
    </div>

    <!-- Filters -->
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
                    <label>Filter by User</label>
                    <input type="text" class="form-control" name="user_filter" placeholder="Username or ID" value="<?php echo htmlspecialchars($user_filter); ?>">
                </div>
            </div>
            <div style="margin-top: 16px; display: flex; gap: 10px; align-items: center;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <a href="?" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Clear
                </a>
                
                <div class="rpt-export-actions" style="margin-left: auto;">
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

    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Access Logs</div>
            <div class="stat-value"><?php echo number_format($total_access); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Config Changes</div>
            <div class="stat-value"><?php echo number_format($total_config); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Deployments</div>
            <div class="stat-value"><?php echo number_format($total_deploy); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Security Events</div>
            <div class="stat-value"><?php echo number_format($total_security); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Exports</div>
            <div class="stat-value"><?php echo number_format($total_exports); ?></div>
        </div>
    </div>

    <!-- SECTION 1: System Access Logs -->
    <div class="audit-section">
        <div class="section-header">
            <i class="section-icon fas fa-sign-in-alt"></i>
            <h2 class="section-title">System Access Logs</h2>
        </div>
        <div class="section-body">
            <?php if (empty($access_logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No access logs found for the selected period</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>IP Address</th>
                            <th>Device</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($access_logs as $log): ?>
                            <tr>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($log['username'] ?? 'N/A'); ?></strong></td>
                                <td>
                                    <?php
                                    $action_type_lower = strtolower($log['action_type'] ?? '');
                                    if (strpos($action_type_lower, 'failed') !== false) {
                                        $badge_class = 'badge-danger';
                                    } elseif (strpos($action_type_lower, 'logout') !== false) {
                                        $badge_class = 'badge-info';
                                    } elseif (strpos($action_type_lower, 'login') !== false) {
                                        $badge_class = 'badge-success';
                                    } else {
                                        $badge_class = 'badge-secondary';
                                    }
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo strtoupper(htmlspecialchars($log['action_type'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(substr($log['user_agent'] ?? 'N/A', 0, 30)); ?></td>
                                <td><?php echo htmlspecialchars($log['action_details'] ?? 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- SECTION 2: Configuration Changes -->
    <div class="audit-section">
        <div class="section-header">
            <i class="section-icon fas fa-cogs"></i>
            <h2 class="section-title">Configuration Changes</h2>
        </div>
        <div class="section-body">
            <?php if (empty($config_changes)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No configuration changes found for the selected period</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Config Type</th>
                            <th>Entity</th>
                            <th>Details</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($config_changes as $log): ?>
                            <tr>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($log['username'] ?? 'N/A'); ?></strong></td>
                                <td><span class="badge badge-info"><?php echo strtoupper(htmlspecialchars($log['action_type'])); ?></span></td>
                                <td><?php echo htmlspecialchars($log['entity_type'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(substr($log['action_details'] ?? 'N/A', 0, 50)); ?></td>
                                <td><code><?php echo htmlspecialchars(substr($log['old_values'] ?? '', 0, 30)); ?></code></td>
                                <td><code><?php echo htmlspecialchars(substr($log['new_values'] ?? '', 0, 30)); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- SECTION 3: Code & Deployment Logs -->
    <div class="audit-section">
        <div class="section-header">
            <i class="section-icon fas fa-code-branch"></i>
            <h2 class="section-title">Code & Deployment Logs</h2>
        </div>
        <div class="section-body">
            <?php if (empty($deployment_logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No deployment logs found for the selected period</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity Type</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deployment_logs as $log): ?>
                            <tr>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($log['username'] ?? 'N/A'); ?></strong></td>
                                <td>
                                    <?php
                                    $action_type_lower = strtolower($log['action_type'] ?? '');
                                    if (strpos($action_type_lower, 'rollback') !== false) {
                                        $badge_class = 'badge-danger';
                                    } elseif (strpos($action_type_lower, 'deployment') !== false || strpos($action_type_lower, 'deploy') !== false) {
                                        $badge_class = 'badge-warning';
                                    } elseif (strpos($action_type_lower, 'merge') !== false) {
                                        $badge_class = 'badge-info';
                                    } else {
                                        $badge_class = 'badge-success';
                                    }
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo strtoupper(htmlspecialchars($log['action_type'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['entity_type'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(substr($log['action_details'] ?? '', 0, 60)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- SECTION 4: Error & Security Tracking -->
    <div class="audit-section">
        <div class="section-header">
            <i class="section-icon fas fa-shield-alt"></i>
            <h2 class="section-title">Error & Security Tracking</h2>
        </div>
        <div class="section-body">
            <?php if (empty($security_logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle" style="color: var(--success-color);"></i>
                    <p>No security events or errors found. System running smoothly!</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User/IP</th>
                            <th>Event Type</th>
                            <th>Severity</th>
                            <th>Error Message</th>
                            <th>Suspicious Activity</th>
                    </thead>
                    <tbody>
                        <?php foreach ($security_logs as $log): ?>
                            <tr>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($log['username'] ?? 'Unknown'); ?></strong><br>
                                    <small><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></small>
                                </td>
                                <td><span class="badge badge-warning"><?php echo strtoupper(htmlspecialchars($log['action_type'])); ?></span></td>
                                <td>
                                    <?php
                                    $status_lower = strtolower($log['status'] ?? 'medium');
                                    if ($status_lower === 'failed' || $log['error_message']) {
                                        $badge_class = 'badge-danger';
                                        $severity_text = 'HIGH';
                                    } elseif ($status_lower === 'success') {
                                        $badge_class = 'badge-success';
                                        $severity_text = 'LOW';
                                    } else {
                                        $badge_class = 'badge-warning';
                                        $severity_text = 'MEDIUM';
                                    }
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo $severity_text; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars(substr($log['error_message'] ?? $log['action_details'] ?? 'N/A', 0, 60)); ?></td>
                                <td><?php echo $log['status'] === 'Failed' ? '<span class="badge badge-danger">YES</span>' : '<span class="badge badge-success">NO</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- SECTION 5: Export & Compliance -->
    <div class="audit-section">
        <div class="section-header">
            <i class="section-icon fas fa-file-export"></i>
            <h2 class="section-title">Export & Compliance Logs</h2>
        </div>
        <div class="section-body">
            <?php if (empty($export_logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No export or compliance logs found for the selected period</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity Type</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($export_logs as $log): ?>
                            <tr>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($log['username'] ?? 'N/A'); ?></strong></td>
                                <td><span class="badge badge-info"><?php echo strtoupper(htmlspecialchars($log['action_type'])); ?></span></td>
                                <td><?php echo htmlspecialchars($log['entity_type'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(substr($log['action_details'] ?? 'N/A', 0, 80)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
function exportReport(type) {
    if (typeof XLSX === 'undefined') {
        alert('Export library not loaded. Please refresh the page and try again.');
        return;
    }
    
    const tables = Array.from(document.querySelectorAll('.audit-section .data-table')).filter(
        t => t.querySelector('tbody tr')
    );
    
    if (!tables.length) { 
        alert('No table data found to export.'); 
        return; 
    }
    
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Audit_Trail_${dateFrom}_to_${dateTo}`;
    
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
        const section = tbl.closest('.audit-section');
        let sheetName = section?.querySelector('.section-title')?.innerText?.trim() || `Sheet ${i + 1}`;
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
        const section = tbl.closest('.audit-section');
        const heading = section?.querySelector('.section-title')?.innerText?.trim();
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
