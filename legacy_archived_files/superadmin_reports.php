<?php
// ============================================================
// SuperAdmin Reports (Developer View) — Auto-redirect to Technical Reports
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'superadmin_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me      = current_user();
$superadmin_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'Super Admin');
$role    = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? ''));

if (!in_array($role, ['superadmin', 'developer'], true)) {
    $_SESSION['error'] = 'Access denied. SuperAdmin access required.';
    header('Location: super_admin_dashboard.php');
    exit;
}

// Auto-redirect to specific reports if requested, default to Technical Reports
$section = $_GET['section'] ?? '';
if ($section === 'security') {
    header('Location: reports_security.php');
} elseif ($section === 'developer_audit') {
    header('Location: reports_developer_audit.php');
} elseif ($section === 'audit_trail') {
    header('Location: reports_audit_trail.php');
} else {
    header('Location: reports_technical.php');
}
exit;
?>// Active section
$section = trim($_GET['section'] ?? 'technical');
$allowed_sections = ['technical', 'security', 'developer_audit', 'audit_trail'];
if (!in_array($section, $allowed_sections)) $section = 'technical';

// Date filters
$date_from = trim($_GET['date_from'] ?? date('Y-m-01'));
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

$severity_filter = trim($_GET['severity'] ?? '');
$module_filter   = trim($_GET['module']   ?? '');
$user_filter     = (int)($_GET['user_id'] ?? 0);

// ── Helpers ───────────────────────────────────────────────────────────────────
function rpt_rows(PDO $pdo, string $sql, array $p = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Exception $e) { return []; }
}
function rpt_val(PDO $pdo, string $sql, array $p = [], $default = 0) {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn() ?? $default; }
    catch (Exception $e) { return $default; }
}

// ── Log this report view for audit trail ─────────────────────────────────────
try {
    $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, action_details, ip_address, status, created_at)
                   VALUES (?, 'view_report', 'superadmin_reports', ?, ?, 'success', NOW())")
        ->execute([
            $me['id'] ?? 0,
            "Viewed SuperAdmin Reports section: {$section}",
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
} catch (Exception $e) { /* silent */ }

// ══════════════════════════════════════════════════════════════════════════════
// STEP 1 — TECHNICAL REPORTS DATA
// ══════════════════════════════════════════════════════════════════════════════
$tech_data = [];
if ($section === 'technical') {
    // DB size
    $tech_data['db_size_mb'] = (float) rpt_val($pdo,
        "SELECT ROUND(SUM(data_length + index_length)/1024/1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $tech_data['total_tables'] = (int) rpt_val($pdo,
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");

    // PHP runtime
    $tech_data['php_version']    = PHP_VERSION;
    $tech_data['memory_limit']   = ini_get('memory_limit');
    $tech_data['max_exec_time']  = ini_get('max_execution_time') . 's';
    $tech_data['upload_max']     = ini_get('upload_max_filesize');

    // DB query response time
    $t0 = microtime(true);
    rpt_val($pdo, "SELECT COUNT(*) FROM users");
    $tech_data['query_ms'] = round((microtime(true) - $t0) * 1000, 2);

    // System uptime proxy — first audit log
    $first_log = rpt_val($pdo, "SELECT MIN(created_at) FROM audit_logs", [], null);
    $tech_data['system_since'] = $first_log ? date('M j, Y', strtotime($first_log)) : 'N/A';

    // Activity volume by day (last 14 days)
    $tech_data['activity_trend'] = rpt_rows($pdo,
        "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
         FROM audit_logs
         WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
         GROUP BY DATE(created_at) ORDER BY day ASC");

    // Top modules by activity
    $tech_data['module_activity'] = rpt_rows($pdo,
        "SELECT entity_type AS module, COUNT(*) AS cnt
         FROM audit_logs
         WHERE DATE(created_at) BETWEEN ? AND ?
         GROUP BY entity_type ORDER BY cnt DESC LIMIT 10",
        [$date_from, $date_to]);

    // Error/warning counts from audit_logs status
    $tech_data['error_count']   = (int) rpt_val($pdo,
        "SELECT COUNT(*) FROM audit_logs WHERE status IN ('error','failed','failure') AND DATE(created_at) BETWEEN ? AND ?",
        [$date_from, $date_to]);
    $tech_data['success_count'] = (int) rpt_val($pdo,
        "SELECT COUNT(*) FROM audit_logs WHERE status = 'success' AND DATE(created_at) BETWEEN ? AND ?",
        [$date_from, $date_to]);
    $tech_data['total_logs']    = $tech_data['error_count'] + $tech_data['success_count'];

    // Recent log entries
    $sql = "SELECT al.id, al.action_type, al.entity_type, al.action_details, al.status, al.ip_address, al.created_at,
                   COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name, u.role AS user_role
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE DATE(al.created_at) BETWEEN ? AND ?";
    $params = [$date_from, $date_to];
    if ($severity_filter) { $sql .= " AND al.status = ?"; $params[] = $severity_filter; }
    if ($module_filter)   { $sql .= " AND al.entity_type LIKE ?"; $params[] = "%{$module_filter}%"; }
    $sql .= " ORDER BY al.created_at DESC LIMIT 50";
    $tech_data['recent_logs'] = rpt_rows($pdo, $sql, $params);
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 2 — SECURITY REPORTS DATA
// ══════════════════════════════════════════════════════════════════════════════
$sec_data = [];
if ($section === 'security') {
    // Failed login attempts
    $sec_data['failed_logins'] = rpt_rows($pdo,
        "SELECT al.user_id, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name, u.role AS user_role,
                al.ip_address, al.action_details, al.created_at
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.action_type IN ('login_failed','Login Failed','failed_login')
           AND DATE(al.created_at) BETWEEN ? AND ?
         ORDER BY al.created_at DESC LIMIT 100",
        [$date_from, $date_to]);

    // Fallback: activity_logs table
    if (empty($sec_data['failed_logins'])) {
        $sec_data['failed_logins'] = rpt_rows($pdo,
            "SELECT al.user_id, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name, u.role AS user_role,
                    NULL AS ip_address, al.details AS action_details, al.created_at
             FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.action = 'Login Failed'
               AND DATE(al.created_at) BETWEEN ? AND ?
             ORDER BY al.created_at DESC LIMIT 100",
            [$date_from, $date_to]);
    }

    // Successful logins
    $sec_data['successful_logins'] = rpt_rows($pdo,
        "SELECT al.user_id, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name, u.role AS user_role,
                al.ip_address, al.created_at
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.action_type IN ('login','login_success','Login')
           AND al.status = 'success'
           AND DATE(al.created_at) BETWEEN ? AND ?
         ORDER BY al.created_at DESC LIMIT 50",
        [$date_from, $date_to]);

    // Suspicious IPs (5+ failed attempts)
    $sec_data['suspicious_ips'] = rpt_rows($pdo,
        "SELECT ip_address, COUNT(*) AS attempts, MAX(created_at) AS last_attempt
         FROM audit_logs
         WHERE action_type IN ('login_failed','Login Failed','failed_login')
           AND DATE(created_at) BETWEEN ? AND ?
           AND ip_address IS NOT NULL AND ip_address != ''
         GROUP BY ip_address HAVING attempts >= 3
         ORDER BY attempts DESC LIMIT 20",
        [$date_from, $date_to]);

    // Summary counts
    $sec_data['total_failed']  = count($sec_data['failed_logins']);
    $sec_data['total_success'] = count($sec_data['successful_logins']);
    $sec_data['suspicious_count'] = count($sec_data['suspicious_ips']);

    // Unauthorized access attempts
    $sec_data['access_denied'] = rpt_rows($pdo,
        "SELECT al.user_id, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name, u.role AS user_role,
                al.action_type, al.entity_type, al.action_details, al.ip_address, al.created_at
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.status IN ('denied','forbidden','unauthorized')
           AND DATE(al.created_at) BETWEEN ? AND ?
         ORDER BY al.created_at DESC LIMIT 50",
        [$date_from, $date_to]);
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 3 — DEVELOPER AUDIT REPORTS DATA
// ══════════════════════════════════════════════════════════════════════════════
$dev_data = [];
if ($section === 'developer_audit') {
    // Config/code changes
    $dev_data['config_changes'] = rpt_rows($pdo,
        "SELECT al.id, al.user_id, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name, u.role AS user_role,
                al.action_type, al.entity_type AS module, al.action_details,
                al.old_values, al.new_values, al.ip_address, al.created_at
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.action_type IN ('config_change','update_config','module_update','setting_change',
                                  'price_change','update','create','delete','bulk_update')
           AND DATE(al.created_at) BETWEEN ? AND ?
         ORDER BY al.created_at DESC LIMIT 100",
        [$date_from, $date_to]);

    // Changes by module
    $dev_data['changes_by_module'] = rpt_rows($pdo,
        "SELECT entity_type AS module, action_type, COUNT(*) AS cnt
         FROM audit_logs
         WHERE DATE(created_at) BETWEEN ? AND ?
           AND action_type NOT IN ('login','login_failed','view_report','Login','Login Failed')
         GROUP BY entity_type, action_type
         ORDER BY cnt DESC LIMIT 20",
        [$date_from, $date_to]);

    // Changes by user
    $dev_data['changes_by_user'] = rpt_rows($pdo,
        "SELECT COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name, u.role AS user_role, COUNT(*) AS total_changes,
                MAX(al.created_at) AS last_change
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE DATE(al.created_at) BETWEEN ? AND ?
           AND al.action_type NOT IN ('login','login_failed','view_report','Login','Login Failed')
         GROUP BY al.user_id, u.name, u.role
         ORDER BY total_changes DESC LIMIT 20",
        [$date_from, $date_to]);

    $dev_data['total_changes'] = count($dev_data['config_changes']);
}

// ══════════════════════════════════════════════════════════════════════════════
// STEP 4 — AUDIT TRAIL LOGGING DATA
// ══════════════════════════════════════════════════════════════════════════════
$trail_data = [];
if ($section === 'audit_trail') {
    $sql = "SELECT al.id, al.user_id, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name, u.role AS user_role,
                   al.action_type, al.entity_type, al.action_details,
                   al.old_values, al.new_values, al.ip_address, al.status, al.created_at
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE DATE(al.created_at) BETWEEN ? AND ?";
    $params = [$date_from, $date_to];
    if ($user_filter)     { $sql .= " AND al.user_id = ?";          $params[] = $user_filter; }
    if ($module_filter)   { $sql .= " AND al.entity_type LIKE ?";   $params[] = "%{$module_filter}%"; }
    if ($severity_filter) { $sql .= " AND al.status = ?";           $params[] = $severity_filter; }
    $sql .= " ORDER BY al.created_at DESC LIMIT 200";
    $trail_data['logs'] = rpt_rows($pdo, $sql, $params);

    // Summary
    $trail_data['total']   = (int) rpt_val($pdo,
        "SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) BETWEEN ? AND ?",
        [$date_from, $date_to]);
    $trail_data['exports'] = (int) rpt_val($pdo,
        "SELECT COUNT(*) FROM audit_logs WHERE action_type LIKE '%export%' AND DATE(created_at) BETWEEN ? AND ?",
        [$date_from, $date_to]);
    $trail_data['report_views'] = (int) rpt_val($pdo,
        "SELECT COUNT(*) FROM audit_logs WHERE action_type = 'view_report' AND DATE(created_at) BETWEEN ? AND ?",
        [$date_from, $date_to]);

    // All users for filter dropdown
    $trail_data['users'] = rpt_rows($pdo,
        "SELECT DISTINCT u.id, u.name, u.role FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE u.id IS NOT NULL ORDER BY u.name");
}

// ── Handle CSV export ─────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Log the export
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, entity_type, action_details, ip_address, status, created_at)
                       VALUES (?, 'export_report', 'superadmin_reports', ?, ?, 'success', NOW())")
            ->execute([$me['id'] ?? 0, "Exported CSV: section={$section}", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
    } catch (Exception $e) {}

    $filename = "superadmin_report_{$section}_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    // Print-style Header for CSV
    fputcsv($out, ['SYSTEM MONITORING & AUDIT REPORTS — ' . mb_strtoupper(str_replace('_', ' ', $section))]);
    fputcsv($out, ['Vamenta Blvd., Carmen, City Of Cagayan De Oro, Misamis Oriental']);
    fputcsv($out, ['Date: ' . $date_from . ' – ' . $date_to]);
    fputcsv($out, []); // Blank separator line

    if ($section === 'technical' && !empty($tech_data['recent_logs'])) {
        fputcsv($out, ['ID','Action Type','Module','Details','Status','IP Address','User','Role','Timestamp']);
        foreach ($tech_data['recent_logs'] as $r) {
            fputcsv($out, [$r['id'],$r['action_type'],$r['entity_type'],$r['action_details'],$r['status'],$r['ip_address'],$r['user_name'],$r['user_role'],$r['created_at']]);
        }
    } elseif ($section === 'security') {
        fputcsv($out, ['User ID','User Name','Role','IP Address','Details','Timestamp','Type']);
        foreach ($sec_data['failed_logins'] as $r) {
            fputcsv($out, [$r['user_id'],$r['user_name'],$r['user_role'],$r['ip_address'],$r['action_details'],$r['created_at'],'Failed Login']);
        }
    } elseif ($section === 'developer_audit' && !empty($dev_data['config_changes'])) {
        fputcsv($out, ['ID','User','Role','Action','Module','Details','Old Values','New Values','IP','Timestamp']);
        foreach ($dev_data['config_changes'] as $r) {
            fputcsv($out, [$r['id'],$r['user_name'],$r['user_role'],$r['action_type'],$r['module'],$r['action_details'],$r['old_values'],$r['new_values'],$r['ip_address'],$r['created_at']]);
        }
    } elseif ($section === 'audit_trail' && !empty($trail_data['logs'])) {
        fputcsv($out, ['ID','User','Role','Action','Module','Details','Old Values','New Values','IP','Status','Timestamp']);
        foreach ($trail_data['logs'] as $r) {
            fputcsv($out, [$r['id'],$r['user_name'],$r['user_role'],$r['action_type'],$r['entity_type'],$r['action_details'],$r['old_values'],$r['new_values'],$r['ip_address'],$r['status'],$r['created_at']]);
        }
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
:root {
    --primary: #00264D;
    --accent: #CC0000;
    --success: #28A745;
    --warning: #FFC107;
    --danger: #DC3545;
    --info: #17A2B8;
    --gray-50: #F8F9FA;
    --gray-100: #E9ECEF;
    --gray-200: #DEE2E6;
    --gray-300: #CED4DA;
    --gray-600: #6C757D;
    --gray-800: #343A40;
    --gray-900: #212529;
}

.rpt-page {
    max-width: 100%;
    padding: 20px;
    background: var(--gray-50);
}

.rpt-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.rpt-header h1 {
    font-size: 28px;
    font-weight: 700;
    color: var(--primary);
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.rpt-header .subtitle {
    font-size: 14px;
    color: var(--gray-600);
    margin-top: 4px;
    text-transform: uppercase;
}

.rpt-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.rpt-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all 0.2s;
}

.rpt-btn-primary {
    background: var(--primary);
    color: white;
}

.rpt-btn-primary:hover {
    background: #001a38;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 38, 77, 0.2);
}

.rpt-btn-success {
    background: var(--success);
    color: white;
}

.rpt-btn-success:hover {
    background: #218838;
}

.rpt-btn-outline {
    background: white;
    color: var(--primary);
    border: 2px solid var(--primary);
}

.rpt-btn-outline:hover {
    background: var(--primary);
    color: white;
}

/* Tab Navigation */
.rpt-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 2px solid var(--gray-200);
    flex-wrap: wrap;
}

.rpt-tab {
    padding: 12px 20px;
    font-size: 14px;
    font-weight: 600;
    color: var(--gray-600);
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.rpt-tab:hover {
    color: var(--primary);
    background: var(--gray-50);
}

.rpt-tab.active {
    color: var(--primary);
    border-bottom-color: var(--accent);
    background: white;
}

/* Filter Bar */
.rpt-filter-bar {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--gray-200);
}

.rpt-filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    align-items: end;
}

.rpt-filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.rpt-filter-group label {
    font-size: 12px;
    font-weight: 700;
    color: var(--gray-600);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.rpt-filter-group input,
.rpt-filter-group select {
    padding: 10px 14px;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s;
}

.rpt-filter-group input:focus,
.rpt-filter-group select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0, 38, 77, 0.1);
}

/* Stats Cards */
.rpt-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.rpt-stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--gray-200);
    border-top: 4px solid var(--primary);
    transition: all 0.2s;
}

.rpt-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.rpt-stat-card.success {
    border-top-color: var(--success);
}

.rpt-stat-card.warning {
    border-top-color: var(--warning);
}

.rpt-stat-card.danger {
    border-top-color: var(--danger);
}

.rpt-stat-card.info {
    border-top-color: var(--info);
}

.rpt-stat-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--gray-600);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.rpt-stat-value {
    font-size: 32px;
    font-weight: 800;
    color: var(--gray-900);
    line-height: 1;
}

.rpt-stat-desc {
    font-size: 13px;
    color: var(--gray-600);
    margin-top: 8px;
}

/* Data Card */
.rpt-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--gray-200);
    margin-bottom: 24px;
    overflow: hidden;
}

.rpt-card-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--gray-200);
    background: linear-gradient(135deg, var(--gray-50) 0%, white 100%);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.rpt-card-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.rpt-card-body {
    padding: 24px;
}

/* Table */
.rpt-table-container {
    overflow:hidden;
    max-width: 100%;
}

.rpt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.rpt-table th {
    background: var(--gray-100);
    padding: 12px 16px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: var(--gray-800);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid var(--gray-300);
    white-space: nowrap;
}

.rpt-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--gray-200);
    color: var(--gray-800);
    vertical-align: top;
}

.rpt-table tbody tr:hover {
    background: var(--gray-50);
}

.rpt-table tbody tr:last-child td {
    border-bottom: none;
}

/* Badges */
.rpt-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.rpt-badge-success {
    background: rgba(40, 167, 69, 0.1);
    color: var(--success);
}

.rpt-badge-warning {
    background: rgba(255, 193, 7, 0.1);
    color: #856404;
}

.rpt-badge-danger {
    background: rgba(220, 53, 69, 0.1);
    color: var(--danger);
}

.rpt-badge-info {
    background: rgba(23, 162, 184, 0.1);
    color: var(--info);
}

.rpt-badge-secondary {
    background: var(--gray-200);
    color: var(--gray-600);
}

/* Empty State */
.rpt-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--gray-600);
}

.rpt-empty i {
    font-size: 48px;
    color: var(--gray-300);
    margin-bottom: 16px;
}

.rpt-empty-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 8px;
}

.rpt-empty-text {
    font-size: 14px;
    color: var(--gray-600);
}

/* Chart Container */
.rpt-chart-container {
    position: relative;
    height: 300px;
    margin-top: 20px;
}

/* Responsive */
@media (max-width: 768px) {
    .rpt-header {
        flex-direction: column;
        align-items: stretch;
    }

    .rpt-filter-grid {
        grid-template-columns: 1fr;
    }

    .rpt-stats-grid {
        grid-template-columns: 1fr;
    }

    .rpt-table {
        font-size: 12px;
    }

    .rpt-table th,
    .rpt-table td {
        padding: 8px 12px;
    }
}

/* Alert Box */
.rpt-alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.rpt-alert-info {
    background: rgba(23, 162, 184, 0.1);
    border-left: 4px solid var(--info);
    color: #0c5460;
}

.rpt-alert-warning {
    background: rgba(255, 193, 7, 0.1);
    border-left: 4px solid var(--warning);
    color: #856404;
}

.rpt-alert-danger {
    background: rgba(220, 53, 69, 0.1);
    border-left: 4px solid var(--danger);
    color: #721c24;
}

.rpt-alert i {
    font-size: 20px;
    flex-shrink: 0;
}

.rpt-alert-content {
    flex: 1;
}

.rpt-alert-title {
    font-weight: 700;
    margin-bottom: 4px;
}

/* Code Block */
.rpt-code {
    background: var(--gray-900);
    color: #00ff00;
    padding: 16px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    overflow:hidden;
    margin: 12px 0;
}

/* Metric Row */
.rpt-metric-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--gray-200);
}

.rpt-metric-row:last-child {
    border-bottom: none;
}

.rpt-metric-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--gray-700);
}

.rpt-metric-value {
    font-size: 16px;
    font-weight: 700;
    color: var(--primary);
}
</style>

<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>

<div class="rpt-page">
    <div class="rpt-header">
        <div>
            <h1><i class="fas fa-chart-line"></i> SYSTEM MONITORING & AUDIT REPORTS</h1>
            <div class="subtitle">SuperAdmin Technical Monitoring & Security Audit</div>
            <div class="rpt-address" style="font-size:12px; color:#475569; margin-top:2px;">Vamenta Blvd., Carmen, City Of Cagayan De Oro, Misamis Oriental</div>
            <div class="rpt-date-range" style="font-size:11px; color:#64748b; margin-top:1px;">Date: <?php echo htmlspecialchars($date_from . ' – ' . $date_to); ?></div>
        </div>
        <div class="rpt-actions">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="rpt-btn rpt-btn-success">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <button onclick="exportPrintableAreaToPDF('.rpt-content', '<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $section))); ?> Report', 'superadmin_report_<?php echo date('Ymd'); ?>', this)" class="rpt-btn rpt-btn-outline" style="color:#dc2626; border-color:#dc2626;">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
            <button onclick="window.print()" class="rpt-btn rpt-btn-outline">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="location.reload()" class="rpt-btn rpt-btn-outline">
                <i class="fas fa-sync"></i> Refresh
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="" class="rpt-filter-bar">
        <input type="hidden" name="section" value="<?php echo htmlspecialchars($section); ?>">
        <div class="rpt-filter-grid">
            <div class="rpt-filter-group">
                <label>Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" required>
            </div>
            <div class="rpt-filter-group">
                <label>Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" required>
            </div>
            <?php if ($section === 'technical' || $section === 'audit_trail'): ?>
            <div class="rpt-filter-group">
                <label>Severity</label>
                <select name="severity">
                    <option value="">All</option>
                    <option value="success" <?php echo $severity_filter === 'success' ? 'selected' : ''; ?>>Success</option>
                    <option value="error" <?php echo $severity_filter === 'error' ? 'selected' : ''; ?>>Error</option>
                    <option value="failed" <?php echo $severity_filter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($section === 'technical' || $section === 'audit_trail'): ?>
            <div class="rpt-filter-group">
                <label>Module</label>
                <input type="text" name="module" value="<?php echo htmlspecialchars($module_filter); ?>" placeholder="e.g., users, products">
            </div>
            <?php endif; ?>
            <?php if ($section === 'audit_trail' && !empty($trail_data['users'])): ?>
            <div class="rpt-filter-group">
                <label>User</label>
                <select name="user_id">
                    <option value="">All Users</option>
                    <?php foreach ($trail_data['users'] as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $user_filter == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['name'] . ' (' . $u['role'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="rpt-filter-group">
                <button type="submit" class="rpt-btn rpt-btn-primary" style="width: 100%;">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
            </div>
        </div>
    </form>

    <!-- ================================================================
         STEP 1: TECHNICAL REPORTS
         ================================================================ -->
    <?php if ($section === 'technical'): ?>

    <!-- KPI Cards -->
    <div class="rpt-stats-grid">
        <div class="rpt-stat-card">
            <div class="rpt-stat-label"><i class="fas fa-database"></i> Database Size</div>
            <div class="rpt-stat-value"><?php echo number_format($tech_data['db_size_mb'], 2); ?> MB</div>
            <div class="rpt-stat-desc"><?php echo $tech_data['total_tables']; ?> tables</div>
        </div>
        <div class="rpt-stat-card success">
            <div class="rpt-stat-label"><i class="fas fa-check-circle"></i> Successful Ops</div>
            <div class="rpt-stat-value"><?php echo number_format($tech_data['success_count']); ?></div>
            <div class="rpt-stat-desc"><?php echo $date_from; ?> – <?php echo $date_to; ?></div>
        </div>
        <div class="rpt-stat-card danger">
            <div class="rpt-stat-label"><i class="fas fa-exclamation-triangle"></i> Errors</div>
            <div class="rpt-stat-value"><?php echo number_format($tech_data['error_count']); ?></div>
            <div class="rpt-stat-desc">In selected period</div>
        </div>
        <div class="rpt-stat-card info">
            <div class="rpt-stat-label"><i class="fas fa-tachometer-alt"></i> DB Query Time</div>
            <div class="rpt-stat-value"><?php echo $tech_data['query_ms']; ?> ms</div>
            <div class="rpt-stat-desc">Live measurement</div>
        </div>
    </div>

    <!-- System Info + Activity Chart -->
    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px; margin-bottom: 24px;">
        <div class="rpt-card">
            <div class="rpt-card-header">
                <h3 class="rpt-card-title"><i class="fas fa-server"></i> System Info</h3>
            </div>
            <div class="rpt-card-body">
                <div class="rpt-metric-row">
                    <span class="rpt-metric-label">PHP Version</span>
                    <span class="rpt-metric-value"><?php echo htmlspecialchars($tech_data['php_version']); ?></span>
                </div>
                <div class="rpt-metric-row">
                    <span class="rpt-metric-label">Memory Limit</span>
                    <span class="rpt-metric-value"><?php echo htmlspecialchars($tech_data['memory_limit']); ?></span>
                </div>
                <div class="rpt-metric-row">
                    <span class="rpt-metric-label">Max Exec Time</span>
                    <span class="rpt-metric-value"><?php echo htmlspecialchars($tech_data['max_exec_time']); ?></span>
                </div>
                <div class="rpt-metric-row">
                    <span class="rpt-metric-label">Upload Max</span>
                    <span class="rpt-metric-value"><?php echo htmlspecialchars($tech_data['upload_max']); ?></span>
                </div>
                <div class="rpt-metric-row">
                    <span class="rpt-metric-label">System Since</span>
                    <span class="rpt-metric-value"><?php echo htmlspecialchars($tech_data['system_since']); ?></span>
                </div>
                <div class="rpt-metric-row">
                    <span class="rpt-metric-label">DB Tables</span>
                    <span class="rpt-metric-value"><?php echo $tech_data['total_tables']; ?></span>
                </div>
            </div>
        </div>

        <div class="rpt-card">
            <div class="rpt-card-header">
                <h3 class="rpt-card-title"><i class="fas fa-chart-area"></i> Activity Trend (Last 14 Days)</h3>
            </div>
            <div class="rpt-card-body">
                <div class="rpt-chart-container">
                    <canvas id="chartActivity"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Module Activity + Recent Logs -->
    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px; margin-bottom: 24px;">
        <div class="rpt-card">
            <div class="rpt-card-header">
                <h3 class="rpt-card-title"><i class="fas fa-cubes"></i> Top Modules</h3>
            </div>
            <div class="rpt-card-body">
                <?php if (!empty($tech_data['module_activity'])): ?>
                <div class="rpt-table-container">
                    <table class="rpt-table">
                        <thead>
                            <tr>
                                <th>Module</th>
                                <th>Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tech_data['module_activity'] as $m): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($m['module'] ?: 'Unknown'); ?></td>
                                <td><strong><?php echo number_format($m['cnt']); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="rpt-empty">
                    <i class="fas fa-inbox"></i>
                    <div class="rpt-empty-title">No Data</div>
                    <div class="rpt-empty-text">No module activity in this period.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="rpt-card">
            <div class="rpt-card-header">
                <h3 class="rpt-card-title"><i class="fas fa-list-alt"></i> Recent Log Entries</h3>
                <span style="font-size: 13px; color: var(--gray-600);">Showing up to 50 records</span>
            </div>
            <div class="rpt-card-body">
                <?php if (!empty($tech_data['recent_logs'])): ?>
                <div class="rpt-table-container">
                    <table class="rpt-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Module</th>
                                <th>Status</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tech_data['recent_logs'] as $log): ?>
                            <tr>
                                <td style="white-space: nowrap;"><?php echo htmlspecialchars($log['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></td>
                                <td><?php echo htmlspecialchars($log['action_type']); ?></td>
                                <td><?php echo htmlspecialchars($log['entity_type'] ?: '—'); ?></td>
                                <td>
                                    <?php
                                    $s = strtolower($log['status'] ?? '');
                                    $cls = $s === 'success' ? 'success' : ($s === 'error' || $s === 'failed' ? 'danger' : 'secondary');
                                    ?>
                                    <span class="rpt-badge rpt-badge-<?php echo $cls; ?>"><?php echo htmlspecialchars($log['status'] ?? 'N/A'); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="rpt-empty">
                    <i class="fas fa-inbox"></i>
                    <div class="rpt-empty-title">No Logs Found</div>
                    <div class="rpt-empty-text">No log entries match the current filters.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const labels = <?php echo json_encode(array_column($tech_data['activity_trend'], 'day')); ?>;
        const counts = <?php echo json_encode(array_map('intval', array_column($tech_data['activity_trend'], 'cnt'))); ?>;
        const ctx = document.getElementById('chartActivity');
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Activity Count',
                    data: counts,
                    borderColor: '#00264D',
                    backgroundColor: 'rgba(0, 38, 77, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#00264D',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    })();
    </script>

    <?php endif; ?>

    <!-- ================================================================
         STEP 2: SECURITY REPORTS
         ================================================================ -->
    <?php if ($section === 'security'): ?>
    <!-- Security KPIs -->
    <div class="rpt-stats-grid">
        <div class="rpt-stat-card danger">
            <div class="rpt-stat-label"><i class="fas fa-times-circle"></i> Failed Logins</div>
            <div class="rpt-stat-value"><?php echo number_format($sec_data['total_failed']); ?></div>
            <div class="rpt-stat-desc"><?php echo $date_from; ?> – <?php echo $date_to; ?></div>
        </div>
        <div class="rpt-stat-card success">
            <div class="rpt-stat-label"><i class="fas fa-check-circle"></i> Successful Logins</div>
            <div class="rpt-stat-value"><?php echo number_format($sec_data['total_success']); ?></div>
            <div class="rpt-stat-desc">In selected period</div>
        </div>
        <div class="rpt-stat-card warning">
            <div class="rpt-stat-label"><i class="fas fa-exclamation-triangle"></i> Suspicious IPs</div>
            <div class="rpt-stat-value"><?php echo number_format($sec_data['suspicious_count']); ?></div>
            <div class="rpt-stat-desc">3+ failed attempts</div>
        </div>
        <div class="rpt-stat-card info">
            <div class="rpt-stat-label"><i class="fas fa-ban"></i> Access Denied</div>
            <div class="rpt-stat-value"><?php echo count($sec_data['access_denied']); ?></div>
            <div class="rpt-stat-desc">Unauthorized attempts</div>
        </div>
    </div>

    <!-- Suspicious IPs -->
    <?php if (!empty($sec_data['suspicious_ips'])): ?>
    <div class="rpt-card" style="margin-bottom: 24px;">
        <div class="rpt-card-header">
            <h3 class="rpt-card-title"><i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> Suspicious IP Addresses</h3>
        </div>
        <div class="rpt-card-body">
            <div class="rpt-table-container">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Failed Attempts</th>
                            <th>Last Attempt</th>
                            <th>Risk Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sec_data['suspicious_ips'] as $ip): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($ip['ip_address']); ?></strong></td>
                            <td><?php echo number_format($ip['attempts']); ?></td>
                            <td><?php echo htmlspecialchars($ip['last_attempt']); ?></td>
                            <td>
                                <?php
                                $risk = $ip['attempts'] >= 10 ? 'danger' : ($ip['attempts'] >= 5 ? 'warning' : 'info');
                                $risk_label = $ip['attempts'] >= 10 ? 'High' : ($ip['attempts'] >= 5 ? 'Medium' : 'Low');
                                ?>
                                <span class="rpt-badge rpt-badge-<?php echo $risk; ?>"><?php echo $risk_label; ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Failed Logins Table -->
    <div class="rpt-card" style="margin-bottom: 24px;">
        <div class="rpt-card-header">
            <h3 class="rpt-card-title"><i class="fas fa-times-circle" style="color: var(--danger);"></i> Failed Login Attempts</h3>
            <span style="font-size: 13px; color: var(--gray-600);">Showing up to 100 records</span>
        </div>
        <div class="rpt-card-body">
            <?php if (!empty($sec_data['failed_logins'])): ?>
            <div class="rpt-table-container">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User ID</th>
                            <th>User Name</th>
                            <th>Role</th>
                            <th>IP Address</th>
                            <th>Details</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sec_data['failed_logins'] as $fl): ?>
                        <tr>
                            <td style="white-space: nowrap;"><?php echo htmlspecialchars($fl['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($fl['user_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($fl['user_name'] ?? 'Unknown'); ?></td>
                            <td><?php echo htmlspecialchars($fl['user_role'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($fl['ip_address'] ?? '—'); ?></td>
                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($fl['action_details'] ?? ''); ?>">
                                <?php echo htmlspecialchars(substr($fl['action_details'] ?? '—', 0, 60)); ?>
                            </td>
                            <td><span class="rpt-badge rpt-badge-danger">Failed</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="rpt-empty">
                <i class="fas fa-shield-check"></i>
                <div class="rpt-empty-title">No Failed Logins</div>
                <div class="rpt-empty-text">No failed login attempts found in the selected period.</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Successful Logins -->
    <div class="rpt-card">
        <div class="rpt-card-header">
            <h3 class="rpt-card-title"><i class="fas fa-check-circle" style="color: var(--success);"></i> Successful Logins</h3>
            <span style="font-size: 13px; color: var(--gray-600);">Showing up to 50 records</span>
        </div>
        <div class="rpt-card-body">
            <?php if (!empty($sec_data['successful_logins'])): ?>
            <div class="rpt-table-container">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User Name</th>
                            <th>Role</th>
                            <th>IP Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sec_data['successful_logins'] as $sl): ?>
                        <tr>
                            <td style="white-space: nowrap;"><?php echo htmlspecialchars($sl['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($sl['user_name'] ?? 'Unknown'); ?></td>
                            <td><?php echo htmlspecialchars($sl['user_role'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($sl['ip_address'] ?? '—'); ?></td>
                            <td><span class="rpt-badge rpt-badge-success">Success</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="rpt-empty">
                <i class="fas fa-inbox"></i>
                <div class="rpt-empty-title">No Login Records</div>
                <div class="rpt-empty-text">No successful login records found in the selected period.</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

    <!-- ================================================================
         STEP 3: DEVELOPER AUDIT REPORTS
         ================================================================ -->
    <?php if ($section === 'developer_audit'): ?>


    <!-- Dev Audit KPIs -->
    <div class="rpt-stats-grid">
        <div class="rpt-stat-card">
            <div class="rpt-stat-label"><i class="fas fa-edit"></i> Total Changes</div>
            <div class="rpt-stat-value"><?php echo number_format($dev_data['total_changes']); ?></div>
            <div class="rpt-stat-desc"><?php echo $date_from; ?> – <?php echo $date_to; ?></div>
        </div>
        <div class="rpt-stat-card info">
            <div class="rpt-stat-label"><i class="fas fa-users-cog"></i> Active Developers</div>
            <div class="rpt-stat-value"><?php echo count($dev_data['changes_by_user']); ?></div>
            <div class="rpt-stat-desc">Made changes in period</div>
        </div>
        <div class="rpt-stat-card warning">
            <div class="rpt-stat-label"><i class="fas fa-cubes"></i> Modules Affected</div>
            <div class="rpt-stat-value"><?php echo count(array_unique(array_column($dev_data['changes_by_module'], 'module'))); ?></div>
            <div class="rpt-stat-desc">Distinct modules</div>
        </div>
    </div>

    <!-- Changes by Module + Changes by User -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
        <div class="rpt-card">
            <div class="rpt-card-header">
                <h3 class="rpt-card-title"><i class="fas fa-cubes"></i> Changes by Module</h3>
            </div>
            <div class="rpt-card-body">
                <?php if (!empty($dev_data['changes_by_module'])): ?>
                <div class="rpt-table-container">
                    <table class="rpt-table">
                        <thead>
                            <tr>
                                <th>Module</th>
                                <th>Action</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dev_data['changes_by_module'] as $m): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($m['module'] ?: 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($m['action_type']); ?></td>
                                <td><strong><?php echo number_format($m['cnt']); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="rpt-empty">
                    <i class="fas fa-inbox"></i>
                    <div class="rpt-empty-title">No Changes</div>
                    <div class="rpt-empty-text">No module changes in this period.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="rpt-card">
            <div class="rpt-card-header">
                <h3 class="rpt-card-title"><i class="fas fa-user-cog"></i> Changes by User</h3>
            </div>
            <div class="rpt-card-body">
                <?php if (!empty($dev_data['changes_by_user'])): ?>
                <div class="rpt-table-container">
                    <table class="rpt-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Changes</th>
                                <th>Last Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dev_data['changes_by_user'] as $u): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($u['user_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($u['user_role'] ?? '—'); ?></td>
                                <td><strong><?php echo number_format($u['total_changes']); ?></strong></td>
                                <td style="white-space: nowrap;"><?php echo htmlspecialchars($u['last_change']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="rpt-empty">
                    <i class="fas fa-inbox"></i>
                    <div class="rpt-empty-title">No User Changes</div>
                    <div class="rpt-empty-text">No user changes in this period.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Full Audit Trail Table -->
    <div class="rpt-card">
        <div class="rpt-card-header">
            <h3 class="rpt-card-title"><i class="fas fa-table"></i> Developer Audit Trail</h3>
            <span style="font-size: 13px; color: var(--gray-600);">Showing up to 100 records</span>
        </div>
        <div class="rpt-card-body">
            <?php if (!empty($dev_data['config_changes'])): ?>
            <div class="rpt-table-container">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Action</th>
                            <th>Module</th>
                            <th>Details</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dev_data['config_changes'] as $c): ?>
                        <tr>
                            <td style="white-space: nowrap;"><?php echo htmlspecialchars($c['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($c['user_name'] ?? 'System'); ?></td>
                            <td><?php echo htmlspecialchars($c['user_role'] ?? '—'); ?></td>
                            <td>
                                <?php
                                $act = strtolower($c['action_type'] ?? '');
                                $act_cls = str_contains($act, 'delete') ? 'danger' : (str_contains($act, 'create') ? 'success' : 'info');
                                ?>
                                <span class="rpt-badge rpt-badge-<?php echo $act_cls; ?>"><?php echo htmlspecialchars($c['action_type']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($c['module'] ?: '—'); ?></td>
                            <td style="max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($c['action_details'] ?? ''); ?>">
                                <?php echo htmlspecialchars(substr($c['action_details'] ?? '—', 0, 50)); ?>
                            </td>
                            <td style="max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($c['old_values'] ?? ''); ?>">
                                <?php echo htmlspecialchars(substr($c['old_values'] ?? '—', 0, 30)); ?>
                            </td>
                            <td style="max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($c['new_values'] ?? ''); ?>">
                                <?php echo htmlspecialchars(substr($c['new_values'] ?? '—', 0, 30)); ?>
                            </td>
                            <td><?php echo htmlspecialchars($c['ip_address'] ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="rpt-empty">
                <i class="fas fa-inbox"></i>
                <div class="rpt-empty-title">No Audit Records</div>
                <div class="rpt-empty-text">No developer changes found in the selected period.</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

    <!-- ================================================================
         STEP 4: AUDIT TRAIL LOGGING
         ================================================================ -->
    <?php if ($section === 'audit_trail'): ?>

    <!-- Audit Trail KPIs -->
    <div class="rpt-stats-grid">
        <div class="rpt-stat-card">
            <div class="rpt-stat-label"><i class="fas fa-list"></i> Total Logs</div>
            <div class="rpt-stat-value"><?php echo number_format($trail_data['total']); ?></div>
            <div class="rpt-stat-desc"><?php echo $date_from; ?> – <?php echo $date_to; ?></div>
        </div>
        <div class="rpt-stat-card success">
            <div class="rpt-stat-label"><i class="fas fa-eye"></i> Report Views</div>
            <div class="rpt-stat-value"><?php echo number_format($trail_data['report_views']); ?></div>
            <div class="rpt-stat-desc">In selected period</div>
        </div>
        <div class="rpt-stat-card info">
            <div class="rpt-stat-label"><i class="fas fa-download"></i> Exports</div>
            <div class="rpt-stat-value"><?php echo number_format($trail_data['exports']); ?></div>
            <div class="rpt-stat-desc">CSV/PDF exports</div>
        </div>
    </div>

    <!-- Full Audit Trail -->
    <div class="rpt-card">
        <div class="rpt-card-header">
            <h3 class="rpt-card-title"><i class="fas fa-history"></i> Complete Audit Trail</h3>
            <span style="font-size: 13px; color: var(--gray-600);">Showing up to 200 records</span>
        </div>
        <div class="rpt-card-body">
            <?php if (!empty($trail_data['logs'])): ?>
            <div class="rpt-table-container">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Action</th>
                            <th>Module</th>
                            <th>Details</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                            <th>IP Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trail_data['logs'] as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['id']); ?></td>
                            <td style="white-space: nowrap;"><?php echo htmlspecialchars($log['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></td>
                            <td><?php echo htmlspecialchars($log['user_role'] ?? '—'); ?></td>
                            <td>
                                <?php
                                $act = strtolower($log['action_type'] ?? '');
                                $act_cls = str_contains($act, 'delete') ? 'danger' : (str_contains($act, 'create') ? 'success' : (str_contains($act, 'view') ? 'info' : 'secondary'));
                                ?>
                                <span class="rpt-badge rpt-badge-<?php echo $act_cls; ?>"><?php echo htmlspecialchars($log['action_type']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($log['entity_type'] ?: '—'); ?></td>
                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($log['action_details'] ?? ''); ?>">
                                <?php echo htmlspecialchars(substr($log['action_details'] ?? '—', 0, 60)); ?>
                            </td>
                            <td style="max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($log['old_values'] ?? ''); ?>">
                                <?php echo htmlspecialchars(substr($log['old_values'] ?? '—', 0, 30)); ?>
                            </td>
                            <td style="max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($log['new_values'] ?? ''); ?>">
                                <?php echo htmlspecialchars(substr($log['new_values'] ?? '—', 0, 30)); ?>
                            </td>
                            <td><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                            <td>
                                <?php
                                $s = strtolower($log['status'] ?? '');
                                $s_cls = $s === 'success' ? 'success' : ($s === 'error' || $s === 'failed' ? 'danger' : 'secondary');
                                ?>
                                <span class="rpt-badge rpt-badge-<?php echo $s_cls; ?>"><?php echo htmlspecialchars($log['status'] ?? 'N/A'); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="rpt-empty">
                <i class="fas fa-inbox"></i>
                <div class="rpt-empty-title">No Audit Logs</div>
                <div class="rpt-empty-text">No audit trail records found matching the current filters.</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- SYSTEM DEVELOPED BY SIGNATURE (Print Only — hidden on web view, visible on print) -->
    <table class="print-only-sig" style="width:100%; margin-top:35px; page-break-inside:avoid; border:none; border-collapse:collapse;">
        <tr>
            <td style="border:none;"></td>
            <td style="border:none; width:220px; text-align:center; vertical-align:bottom;">
                <div style="font-size:10px; font-weight:700; color:#333; margin-bottom:25px; text-transform:uppercase;">SYSTEM DEVELOPED BY:</div>
                <div style="border-top:1px solid #000; padding-top:4px; font-weight:700; font-size:11px; color:#000;">
                    <?= htmlspecialchars($superadmin_name) ?>
                </div>
                <div style="font-size:9.5px; color:#555; margin-top:2px;">Super Admin</div>
            </td>
        </tr>
    </table>

</div>

<script>
// Auto-refresh every 5 minutes (optional)
// setTimeout(() => location.reload(), 300000);

// Print optimization
window.addEventListener('beforeprint', function() {
    document.querySelectorAll('.rpt-btn, .rpt-filter-bar, .rpt-tabs').forEach(el => {
        el.style.display = 'none';
    });
});

window.addEventListener('afterprint', function() {
    document.querySelectorAll('.rpt-btn, .rpt-filter-bar, .rpt-tabs').forEach(el => {
        el.style.display = '';
    });
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
