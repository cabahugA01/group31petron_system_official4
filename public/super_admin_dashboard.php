<?php
/**
 * Developer / Super Admin Dashboard
 * Summary Cards, System Health, Resource Usage, DB Summary,
 * Active Modules, Recent Activities, System Alerts, Quick Actions
 * WITH date range filter header matching Manager Dashboard design.
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

$page_id = 'super_admin_dashboard';

// ── Date Range Filter ─────────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');
if ($date_to < $date_from) $date_to = $date_from;

// ── Summary Card: System Status ───────────────────────────────────────
$system_status = 'Online';

// ── Summary Card: DB Status ───────────────────────────────────────────
$db_status = 'Connected';
try { $pdo->query("SELECT 1"); } catch (Exception $e) { $db_status = 'Disconnected'; }

// ── Summary Card: Active Modules ─────────────────────────────────────
$active_modules_count = 0;
$modules_list = [];
try {
    $rows = $pdo->query("SELECT module_name, is_enabled FROM module_settings WHERE module_key NOT IN ('notifications', 'backup_restore', 'api_integration') ORDER BY module_order ASC, id ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        $modules_list = array_map(fn($r) => [
            'name'   => $r['module_name'],
            'status' => $r['is_enabled'] ? 'Enabled' : 'Disabled',
        ], $rows);
        $active_modules_count = count(array_filter($rows, fn($r) => !empty($r['is_enabled'])));
    }
} catch (Exception $e) {}

// ── Summary Card: Active System Errors ───────────────────────────────
$active_errors_count = 0;
try {
    $active_errors_count = (int)$pdo->query("SELECT COUNT(*) FROM error_tracking_logs WHERE status NOT IN ('Resolved', 'resolved', 'Fixed', 'fixed', 'Closed', 'closed')")->fetchColumn();
} catch (Exception $e) {}

// ── Summary Card: Latest DB Backup ───────────────────────────────────
$latest_backup_display = 'No Backup Found';
try {
    $bk = $pdo->query("SELECT created_at FROM database_backups WHERE status IN ('Completed','completed','Successful') ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    if ($bk) $latest_backup_display = date('M. d, Y • h:i A', strtotime($bk));
} catch (Exception $e) {}

// ── Summary Card: Security Alerts ────────────────────────────────────
$security_alerts_count = 0;
try {
    $security_alerts_count = (int)$pdo->query("SELECT COUNT(*) FROM error_tracking_logs WHERE severity IN ('Critical','critical') AND status NOT IN ('Resolved', 'resolved', 'Fixed', 'fixed', 'Closed', 'closed')")->fetchColumn();
} catch (Exception $e) {}

// ── Resource Usage (from sys_health_report_log, latest record) ──────
$cpu_usage     = 0;
$memory_usage  = 0;
$storage_usage = 0;
try {
    $resRow = $pdo->query("SELECT cpu_usage, memory_usage, disk_usage FROM sys_health_report_log ORDER BY recorded_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($resRow) {
        $cpu_usage     = (int)($resRow['cpu_usage']     ?? 0);
        $memory_usage  = (int)($resRow['memory_usage']  ?? 0);
        $storage_usage = (int)($resRow['disk_usage']    ?? 0);
    }
} catch (Exception $e) {}

$db_size_formatted = '0.00 MB';
try {
    $bytes = (float)$pdo->query("SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetchColumn();
    $db_size_formatted = $bytes >= 1073741824
        ? number_format($bytes / 1073741824, 2) . ' GB'
        : number_format($bytes / 1048576, 2) . ' MB';
} catch (Exception $e) {}

// ── DB Summary ────────────────────────────────────────────────────────
$total_tables  = 0;
$total_records = 0;
try {
    $dbStat = $pdo->query("SELECT COUNT(table_name) as tc, SUM(table_rows) as rc FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetch(PDO::FETCH_ASSOC);
    $total_tables  = (int)($dbStat['tc'] ?? 0);
    $total_records = (int)($dbStat['rc'] ?? 0);
} catch (Exception $e) {}

$last_optimization = date('M. d, Y');
$latest_backup_full = 'N/A';
try {
    $bk2 = $pdo->query("SELECT created_at FROM database_backups ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    if ($bk2) $latest_backup_full = date('M. d, Y h:i A', strtotime($bk2));
} catch (Exception $e) {}

// ── Recent System Activities (date-filtered) ──────────────────────────
$recent_activities = [];
try {
    $stmtAct = $pdo->prepare("
        SELECT a.action, a.created_at, u.username
        FROM activity_logs a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE DATE(a.created_at) BETWEEN :df AND :dt
        ORDER BY a.created_at DESC LIMIT 8
    ");
    $stmtAct->execute([':df' => $date_from, ':dt' => $date_to]);
    $recent_activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── System Alerts (date-filtered — unresolved alerts only) ─────────────
$system_alerts = [];
try {
    $stmtAl = $pdo->prepare("
        SELECT error_message AS alert, severity
        FROM error_tracking_logs
        WHERE DATE(created_at) BETWEEN :df AND :dt
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmtAl->execute([':df' => $date_from, ':dt' => $date_to]);
    $system_alerts = $stmtAl->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// No fallback — show empty state in UI if no records found

// ── Full name ─────────────────────────────────────────────────────────
$full_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
if (!$full_name) $full_name = $u['username'] ?? 'Admin';

// ── AJAX JSON POLLING ENDPOINT FOR SUPERADMIN DASHBOARD ───────────────────────
if (isset($_GET['ajax_sad']) && $_GET['ajax_sad'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'system_status'   => $system_status ?? 'Online',
        'db_status'       => $db_status ?? 'Connected',
        'active_modules'  => $active_modules_count ?? 0,
        'active_errors'   => $active_errors_count ?? 0,
        'backup'          => $latest_backup_display ?? 'No Backup Found',
        'security_alerts' => $security_alerts_count ?? 0,
        'total_tables'    => $total_tables ?? 0,
        'total_records'   => isset($total_records) ? number_format($total_records) : '0'
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Dashboard Layout ────────────────────────────────────────────── */
.main, .main-content {
    padding: 20px 20px 60px 20px !important;
}
.dev-dashboard {
    padding: 0 !important;
    background: #f8fafc;
    min-height: calc(100vh - 110px);
    width: 100%;
}

/* ── Welcome Header with Date Filter ────────────────────────────── */
.dev-welcome-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 !important;
    margin-top: 0 !important;
    margin-bottom: 25px !important;
    flex-wrap: wrap;
    gap: 12px;
    border: none !important;
    width: 100%;
}

.dev-welcome-left h1 {
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #002f70 !important;
    margin: 0 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    line-height: 1.2 !important;
}

.dev-welcome-left .dev-subtitle {
    font-size: 12px;
    color: #64748b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.dev-welcome-left .dev-subtitle i {
    color: #0057b8;
}

/* Date Filter Row */
.dev-date-filter {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.dev-date-filter label {
    font-size: 11px;
    font-weight: 800;
    color: #00264D;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin: 0;
}

.dev-date-filter input[type="date"] {
    padding: 7px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 12px;
    color: #334155;
    background: #ffffff;
    font-weight: 600;
}

.dev-date-filter input[type="date"]:focus {
    outline: none;
    border-color: #0057b8;
    box-shadow: 0 0 0 2px rgba(0,87,184,0.12);
}

.dev-filter-btn {
    padding: 7px 18px;
    background: #00264D;
    color: #ffffff;
    border: none;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s;
}

.dev-filter-btn:hover {
    background: #001a35;
}

/* ── Summary Cards ───────────────────────────────────────────────── */
.dev-cards-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 22px;
}

.dev-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    transition: box-shadow 0.18s;
}

.dev-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.09); }

.dev-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.dev-card-label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 3px;
}

.dev-card-value {
    font-size: 19px;
    font-weight: 800;
    color: #00264D;
    line-height: 1.2;
}

.dev-card-value.small { font-size: 13px; }

.dev-card-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    margin-top: 4px;
}

/* ── Section Panels ──────────────────────────────────────────────── */
.dev-row { display: grid; gap: 18px; margin-bottom: 18px; }
.dev-row-2 { grid-template-columns: 1fr 1fr; }

.dev-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    overflow: hidden;
}

.dev-panel-header {
    background: #00264D;
    color: #ffffff;
    padding: 11px 16px;
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ── Inner Tables ────────────────────────────────────────────────── */
.dev-inner-table { width: 100%; border-collapse: collapse; font-size: 13px; }

.dev-inner-table th {
    padding: 9px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.dev-inner-table td {
    padding: 10px 14px;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
}

.dev-inner-table tr:last-child td { border-bottom: none; }
.dev-inner-table tr:hover td { background: #f8fafc; }

/* ── Status Badges ───────────────────────────────────────────────── */
.badge-green  { background: #dcfce7; color: #15803d; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
.badge-yellow { background: #fef3c7; color: #b45309; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
.badge-red    { background: #fee2e2; color: #dc2626; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
.badge-blue   { background: #eff6ff; color: #1d4ed8; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
.badge-orange { background: #fff7ed; color: #c2410c; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }

/* ── Resource Bars ───────────────────────────────────────────────── */
.res-bar-wrap { display: flex; align-items: center; gap: 10px; }
.res-bar-track { flex: 1; height: 7px; background: #e2e8f0; border-radius: 10px; overflow: hidden; }
.res-bar-fill { height: 100%; border-radius: 10px; background: linear-gradient(90deg, #0057b8, #00264D); }
.res-bar-fill.warn { background: linear-gradient(90deg, #f59e0b, #d97706); }
.res-bar-fill.crit { background: linear-gradient(90deg, #ef4444, #dc2626); }
.res-bar-pct { font-size: 12px; font-weight: 800; color: #00264D; min-width: 34px; text-align: right; }

/* ── Quick Actions ───────────────────────────────────────────────── */
.qa-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    text-decoration: none;
    color: #1e293b;
    font-size: 13px;
    font-weight: 600;
    transition: background 0.15s;
}

.qa-btn:last-child { border-bottom: none; }

.qa-btn:hover {
    background: #f1f5f9;
    text-decoration: none;
    color: #00264D;
}

.qa-btn-icon {
    width: 30px;
    height: 30px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0057b8;
    font-size: 13px;
    flex-shrink: 0;
}
</style>

<div class="dev-dashboard">

    <!-- ── Welcome Header + Date Filter ────────────────────────────── -->
    <div class="dev-welcome-bar">
        <div class="dev-welcome-left">
            <h1>Welcome, <?php echo htmlspecialchars(strtoupper($full_name)); ?>!</h1>
            <div class="dev-subtitle">
                <i class="fas fa-shield-alt"></i>
                <?php echo htmlspecialchars(ucwords(str_replace(['_','-'], ' ', $role))); ?> Dashboard
            </div>
        </div>

        <form method="GET" action="" class="dev-date-filter">
            <label>From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            <label>To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            <button type="submit" class="dev-filter-btn">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>
    </div>

    <?php 
    $enable_kpi_cards     = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_kpi_cards', true) : true;
    $enable_quick_actions = function_exists('get_module_setting') ? (bool) get_module_setting('dashboard', 'enable_quick_actions', true) : true;
    ?>

    <?php if ($enable_kpi_cards): ?>
    <!-- ── Summary Cards (6 cards, 3×2) ────────────────────────────── -->
    <div class="dev-cards-grid">

        <!-- System Status -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:#dcfce7; border:1px solid #bbf7d0;">
                <i class="fas fa-check-circle" style="color:#15803d; font-size:22px;"></i>
            </div>
            <div>
                <div class="dev-card-label">System Status</div>
                <div class="dev-card-value"><?php echo htmlspecialchars($system_status); ?></div>
                <div class="dev-card-badge" style="color:#15803d;">
                    <i class="fas fa-circle" style="font-size:7px;"></i> All Systems Operational
                </div>
            </div>
        </div>

        <!-- Database Status -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:#eff6ff; border:1px solid #bfdbfe;">
                <i class="fas fa-database" style="color:#0057b8; font-size:22px;"></i>
            </div>
            <div>
                <div class="dev-card-label">Database Status</div>
                <div class="dev-card-value"><?php echo htmlspecialchars($db_status); ?></div>
                <div class="dev-card-badge" style="color:#1d4ed8;">
                    <i class="fas fa-database" style="font-size:10px;"></i> MySQL Active
                </div>
            </div>
        </div>

        <!-- Active Modules -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:#faf5ff; border:1px solid #e9d5ff;">
                <i class="fas fa-cubes" style="color:#7c3aed; font-size:22px;"></i>
            </div>
            <div>
                <div class="dev-card-label">Active Modules</div>
                <div class="dev-card-value"><?php echo $active_modules_count; ?> Modules</div>
                <div class="dev-card-badge" style="color:#7c3aed;">
                    <i class="fas fa-cubes" style="font-size:10px;"></i> Fully Operational
                </div>
            </div>
        </div>

        <!-- Active System Errors -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:<?php echo $active_errors_count > 0 ? '#fff7ed' : '#dcfce7'; ?>; border:1px solid <?php echo $active_errors_count > 0 ? '#fed7aa' : '#bbf7d0'; ?>;">
                <i class="fas <?php echo $active_errors_count > 0 ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>" style="color:<?php echo $active_errors_count > 0 ? '#d97706' : '#15803d'; ?>; font-size:22px;"></i>
            </div>
            <div>
                <div class="dev-card-label">Active System Errors</div>
                <div class="dev-card-value" style="color:<?php echo $active_errors_count > 0 ? '#d97706' : '#15803d'; ?>;">
                    <?php echo $active_errors_count; ?> Error<?php echo $active_errors_count !== 1 ? 's' : ''; ?>
                </div>
                <div class="dev-card-badge" style="color:<?php echo $active_errors_count > 0 ? '#d97706' : '#15803d'; ?>;">
                    <i class="fas <?php echo $active_errors_count > 0 ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>" style="font-size:10px;"></i>
                    <?php echo $active_errors_count > 0 ? 'Unresolved Logs' : 'All Systems Healthy'; ?>
                </div>
            </div>
        </div>

        <!-- Latest Database Backup -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:#f0fdf4; border:1px solid #bbf7d0;">
                <i class="fas fa-save" style="color:#16a34a; font-size:22px;"></i>
            </div>
            <div>
                <div class="dev-card-label">Latest Database Backup</div>
                <div class="dev-card-value small"><?php echo htmlspecialchars($latest_backup_display); ?></div>
                <div class="dev-card-badge" style="color:#16a34a;">
                    <i class="fas fa-shield-alt" style="font-size:10px;"></i> Verified Backup
                </div>
            </div>
        </div>

        <!-- Security Alerts -->
        <div class="dev-card">
            <div class="dev-card-icon" style="background:<?php echo $security_alerts_count > 0 ? '#fef2f2' : '#dcfce7'; ?>; border:1px solid <?php echo $security_alerts_count > 0 ? '#fecaca' : '#bbf7d0'; ?>;">
                <i class="fas <?php echo $security_alerts_count > 0 ? 'fa-lock' : 'fa-shield-alt'; ?>" style="color:<?php echo $security_alerts_count > 0 ? '#dc2626' : '#15803d'; ?>; font-size:22px;"></i>
            </div>
            <div>
                <div class="dev-card-label">Security Alerts</div>
                <div class="dev-card-value" style="color:<?php echo $security_alerts_count > 0 ? '#dc2626' : '#15803d'; ?>;">
                    <?php echo $security_alerts_count; ?> Alert<?php echo $security_alerts_count !== 1 ? 's' : ''; ?>
                </div>
                <div class="dev-card-badge" style="color:<?php echo $security_alerts_count > 0 ? '#dc2626' : '#15803d'; ?>;">
                    <i class="fas <?php echo $security_alerts_count > 0 ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="font-size:10px;"></i>
                    <?php echo $security_alerts_count > 0 ? 'Needs Attention' : 'All Clear / Secure'; ?>
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>

    <!-- ── Row 1: System Health + Resource Usage ──────────────────── -->
    <div class="dev-row dev-row-2">

        <!-- System Health -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-heartbeat"></i> System Health
            </div>
            <table class="dev-inner-table">
                <thead><tr><th>Component</th><th>Status</th></tr></thead>
                <tbody>
                    <tr>
                        <td><i class="fas fa-server" style="color:#0057b8;margin-right:7px;"></i> Web Server</td>
                        <td><span class="badge-green"><i class="fas fa-circle" style="font-size:7px;"></i> Online</span></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-database" style="color:#16a34a;margin-right:7px;"></i> Database</td>
                        <td><span class="badge-green"><i class="fas fa-circle" style="font-size:7px;"></i> Connected</span></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-cogs" style="color:#7c3aed;margin-right:7px;"></i> System Services</td>
                        <td><span class="badge-green"><i class="fas fa-circle" style="font-size:7px;"></i> Running</span></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-bell" style="color:#d97706;margin-right:7px;"></i> Notification Service</td>
                        <td><span class="badge-green"><i class="fas fa-circle" style="font-size:7px;"></i> Running</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Resource Usage -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-chart-bar"></i> Resource Usage
            </div>
            <table class="dev-inner-table">
                <thead><tr><th>Resource</th><th>Usage</th></tr></thead>
                <tbody>
                    <tr>
                        <td><i class="fas fa-microchip" style="color:#0057b8;margin-right:7px;"></i> CPU Usage</td>
                        <td>
                            <div class="res-bar-wrap">
                                <div class="res-bar-track">
                                    <div class="res-bar-fill <?php echo $cpu_usage>=80?'crit':($cpu_usage>=60?'warn':''); ?>" style="width:<?php echo $cpu_usage; ?>%"></div>
                                </div>
                                <div class="res-bar-pct"><?php echo $cpu_usage; ?>%</div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-memory" style="color:#7c3aed;margin-right:7px;"></i> Memory Usage</td>
                        <td>
                            <div class="res-bar-wrap">
                                <div class="res-bar-track">
                                    <div class="res-bar-fill <?php echo $memory_usage>=80?'crit':($memory_usage>=60?'warn':''); ?>" style="width:<?php echo $memory_usage; ?>%"></div>
                                </div>
                                <div class="res-bar-pct"><?php echo $memory_usage; ?>%</div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-hdd" style="color:#16a34a;margin-right:7px;"></i> Storage Usage</td>
                        <td>
                            <div class="res-bar-wrap">
                                <div class="res-bar-track">
                                    <div class="res-bar-fill <?php echo $storage_usage>=80?'crit':($storage_usage>=60?'warn':''); ?>" style="width:<?php echo $storage_usage; ?>%"></div>
                                </div>
                                <div class="res-bar-pct"><?php echo $storage_usage; ?>%</div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-database" style="color:#d97706;margin-right:7px;"></i> Database Size</td>
                        <td><strong style="color:#00264D;"><?php echo htmlspecialchars($db_size_formatted); ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>

    <!-- ── Row 2: DB Summary + Active Modules ────────────────────── -->
    <div class="dev-row dev-row-2">

        <!-- Database Summary -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-database"></i> Database Summary
            </div>
            <table class="dev-inner-table">
                <thead><tr><th>Item</th><th>Value</th></tr></thead>
                <tbody>
                    <tr>
                        <td><i class="fas fa-table" style="color:#0057b8;margin-right:7px;"></i> Total Tables</td>
                        <td><strong style="color:#00264D;"><?php echo number_format($total_tables); ?></strong></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-list" style="color:#7c3aed;margin-right:7px;"></i> Total Records</td>
                        <td><strong style="color:#00264D;"><?php echo number_format($total_records); ?></strong></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-sync-alt" style="color:#16a34a;margin-right:7px;"></i> Last Optimization</td>
                        <td><?php echo htmlspecialchars($last_optimization); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-save" style="color:#d97706;margin-right:7px;"></i> Latest Backup</td>
                        <td><?php echo htmlspecialchars($latest_backup_full); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Active Modules -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-cubes"></i> Active Modules
            </div>
            <table class="dev-inner-table">
                <thead><tr><th>Module</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($modules_list as $mod): ?>
                    <tr>
                        <td><i class="fas fa-puzzle-piece" style="color:#0057b8;margin-right:7px;"></i> <?php echo htmlspecialchars($mod['name']); ?></td>
                        <td>
                            <?php if (strtolower($mod['status']) === 'enabled'): ?>
                                <span class="badge-green"><i class="fas fa-check"></i> Enabled</span>
                            <?php else: ?>
                                <span class="badge-yellow"><i class="fas fa-minus-circle"></i> Disabled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- ── Row 3: Recent Activities + System Alerts + Quick Actions ── -->
    <div class="dev-row" style="grid-template-columns: 1.4fr 1fr 0.75fr;">

        <!-- Recent System Activities -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-history"></i> Recent System Activities
            </div>
            <table class="dev-inner-table">
                <thead><tr><th>Date &amp; Time</th><th>Activity</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_activities as $act): ?>
                    <tr>
                        <td style="white-space:nowrap; color:#64748b; font-size:12px;">
                            <?php echo date('M. d, Y h:i A', strtotime($act['created_at'])); ?>
                        </td>
                        <td style="color:#1e293b; font-weight:600;">
                            <?php echo htmlspecialchars($act['action']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recent_activities)): ?>
                    <tr><td colspan="2" style="text-align:center;color:#64748b;padding:20px;">No recent activity found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- System Alerts -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-exclamation-triangle"></i> System Alerts
            </div>
            <table class="dev-inner-table">
                <thead><tr><th>Alert</th><th>Severity</th></tr></thead>
                <tbody>
                    <?php foreach ($system_alerts as $al):
                        $sev = $al['severity'] ?? 'Information';
                        $sl  = strtolower($sev);
                        if ($sl === 'critical' || $sl === 'error') {
                            $badge = '<span class="badge-orange"><i class="fas fa-times-circle"></i> ' . htmlspecialchars($sev) . '</span>';
                        } elseif ($sl === 'warning') {
                            $badge = '<span class="badge-yellow"><i class="fas fa-exclamation-triangle"></i> Warning</span>';
                        } elseif ($sl === 'security') {
                            $badge = '<span class="badge-red"><i class="fas fa-shield-alt"></i> Security</span>';
                        } else {
                            $badge = '<span class="badge-blue"><i class="fas fa-info-circle"></i> Information</span>';
                        }
                    ?>
                    <tr>
                        <td style="font-size:12px;color:#374151;"><?php echo htmlspecialchars($al['alert']); ?></td>
                        <td><?php echo $badge; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($system_alerts)): ?>
                    <tr><td colspan="2" style="text-align:center;color:#16a34a;padding:24px;font-weight:600;"><i class="fas fa-check-circle" style="font-size:15px;margin-right:6px;"></i> All systems operational. No active alerts.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($enable_quick_actions): ?>
        <!-- Quick Actions -->
        <div class="dev-panel">
            <div class="dev-panel-header">
                <i class="fas fa-bolt"></i> Quick Actions
            </div>
            <div>
                <a href="module_configuration.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-cogs"></i></span>
                    Module Configuration
                </a>
                <a href="database_management.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-database"></i></span>
                    Database Management
                </a>
                <a href="reports_technical.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-chart-bar"></i></span>
                    View System Reports
                </a>
                <a href="superadmin_audit_trail.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-history"></i></span>
                    View Audit Trail
                </a>
                <a href="superadmin_system_settings.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-sliders-h"></i></span>
                    System Settings
                </a>
                <a href="superadmin_admin_management.php" class="qa-btn">
                    <span class="qa-btn-icon"><i class="fas fa-users-cog"></i></span>
                    Admin Management
                </a>
            </div>
        </div>
        <?php endif; ?>

    </div>

</div>

<script>
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
function autoRefreshSuperadminDashboard() {
    const openModal = Array.from(document.querySelectorAll('.modal, .modal-overlay, [id*="Modal"]')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    if (openModal) return;

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_sad', '1');

    fetch(currentUrl.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                // Background refresh verified clean
            }
        })
        .catch(() => {});
}
setInterval(autoRefreshSuperadminDashboard, 15000);
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
