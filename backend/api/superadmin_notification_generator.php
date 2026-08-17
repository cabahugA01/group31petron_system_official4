<?php
/**
 * SuperAdmin Notification Generator
 * backend/api/superadmin_notification_generator.php
 *
 * Scans real system tables (activity_logs, system_error_logs, system_settings_audit,
 * module_config_audit) and inserts system-level notifications into the notifications
 * table for all active superadmin/developer users.
 *
 * Called via AJAX from the header on every page load for superadmin.
 * Uses INSERT IGNORE on a unique source key so the same event is never duplicated.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit;
}

// ── Ensure notifications table has the columns we need ────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        type        ENUM('success','warning','error','info') NOT NULL DEFAULT 'info',
        title       VARCHAR(255) NOT NULL,
        message     TEXT NOT NULL,
        event_type  VARCHAR(80) NOT NULL DEFAULT 'general',
        severity    ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
        source_key  VARCHAR(200) NULL,
        redirect_url VARCHAR(500) NULL,
        status      ENUM('unread','read') NOT NULL DEFAULT 'unread',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at     TIMESTAMP NULL,
        INDEX idx_user_status (user_id, status),
        INDEX idx_event_type  (event_type),
        INDEX idx_source_key  (source_key),
        INDEX idx_created_at  (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* table already exists */ }

// ── Collect all active superadmin/developer user IDs ─────────
$sa_ids = [];
try {
    $stmt = $pdo->query(
        "SELECT id FROM users WHERE LOWER(role) IN ('superadmin','developer') AND status = 'Active'"
    );
    $sa_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

if (empty($sa_ids)) {
    echo json_encode(['ok' => true, 'generated' => 0]); exit;
}

$generated = 0;

// ── Real event scanning only (no artificial mock seeding) ─────


/**
 * Insert a notification for every superadmin/developer.
 * source_key prevents duplicates — same event is never inserted twice.
 */
function push_sa_notification(
    PDO $pdo,
    array $sa_ids,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = ''
): int {
    $inserted = 0;
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO notifications
            (user_id, recipient_role, type, event_type, severity, title, message, source_key, redirect_url, status)
         SELECT ?, 'superadmin', ?, ?, ?, ?, ?, ?, ?, 'unread'
         FROM DUAL
         WHERE NOT EXISTS (
             SELECT 1 FROM notifications
             WHERE user_id = ? AND source_key = ?
         )"
    );
    foreach ($sa_ids as $uid) {
        $stmt->execute([$uid, $type, $event_type, $severity, $title, $message,
                        $source_key, $redirect_url, $uid, $source_key]);
        $inserted += $stmt->rowCount();
    }
    return $inserted;
}

// ════════════════════════════════════════════════════════════
// 1. AUTHENTICATION & SECURITY ALERTS
//    Source: activity_logs — failed logins, unauthorized access, lockouts
// ════════════════════════════════════════════════════════════

// 1a. Multiple failed logins per IP (≥3 in last 24h)
try {
    $rows = $pdo->query(
        "SELECT ip_address, COUNT(*) AS cnt,
                MAX(created_at) AS last_at,
                GROUP_CONCAT(DISTINCT COALESCE(u.username,'Unknown') ORDER BY al.created_at DESC SEPARATOR ', ') AS users
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE (al.action LIKE '%Failed%' OR al.action LIKE '%failed%' OR al.details LIKE '%failed%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         GROUP BY ip_address
         HAVING cnt >= 3"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'failed_login_ip_' . md5($r['ip_address']) . '_' . date('Ymd');
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'error', 'failed_login', 'high',
            'Multiple Failed Login Attempts',
            "{$r['cnt']} failed login attempts from IP {$r['ip_address']} in the last 24 hours. Users: {$r['users']}",
            $key,
            'superadmin_audit_trail.php'
        );
    }
} catch (Exception $e) {}

// 1b. Unauthorized access attempts
try {
    $rows = $pdo->query(
        "SELECT al.id, al.ip_address, al.details, al.created_at, u.username AS user_name
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE (al.action LIKE '%Unauthorized%' OR al.action LIKE '%unauthorized%'
                OR al.details LIKE '%Access denied%' OR al.details LIKE '%access denied%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY al.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'unauth_access_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'error', 'unauthorized_access', 'critical',
            'Unauthorized Access Attempt',
            "Unauthorized access attempt detected. User: " . ($r['user_name'] ?? 'Unknown') . " | IP: {$r['ip_address']} | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'superadmin_audit_trail.php'
        );
    }
} catch (Exception $e) {}

// 1c. Account lockouts
try {
    $rows = $pdo->query(
        "SELECT al.id, al.details, al.created_at, u.username AS user_name
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE (al.action = 'Account Locked' OR al.action = 'Account Lockout' OR al.action = 'User Account Locked')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'account_lockout_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'error', 'account_lockout', 'high',
            'Account Locked',
            "Account locked due to repeated failed attempts. User: " . ($r['user_name'] ?? 'Unknown') . " | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'superadmin_admin_management.php'
        );
    }
} catch (Exception $e) {}

// 1d. Password reset requests
try {
    $rows = $pdo->query(
        "SELECT al.id, al.details, al.created_at, u.username AS user_name
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE (al.action = 'Password Reset Request' OR al.action = 'Password Reset Requested')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'password_reset_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'warning', 'account_lockout', 'medium',
            'Password Reset Request',
            "Password reset request received. User: " . ($r['user_name'] ?? 'Unknown') . " | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'superadmin_admin_management.php'
        );
    }
} catch (Exception $e) {}

// 1e. Suspicious activity flagged
try {
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at, u.username AS user_name
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE (al.action LIKE '%Suspicious%' OR al.details LIKE '%suspicious%' OR al.action LIKE '%Abnormal%' OR al.details LIKE '%abnormal%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'suspicious_activity_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'error', 'security_report', 'high',
            'Suspicious Activity Flagged',
            "Suspicious: " . ($r['action'] ?? 'Unknown') . " | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'reports_security.php'
        );
    }
} catch (Exception $e) {}


// ════════════════════════════════════════════════════════════
// 2. SYSTEM HEALTH ALERTS
//    Source: system_error_logs, activity_logs
// ════════════════════════════════════════════════════════════

// 2a. Critical/High system errors in last 24h
try {
    $rows = $pdo->query(
        "SELECT id, severity, error_type, message, created_at
         FROM system_error_logs
         WHERE severity IN ('critical','warning')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY created_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'sys_error_' . $r['id'];
        $sev = $r['severity'] === 'critical' ? 'critical' : 'high';
        $type = $r['severity'] === 'critical' ? 'error' : 'warning';
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            $type, 'system_error', $sev,
            'System Error: ' . ucfirst($r['error_type'] ?? 'Unknown'),
            mb_strimwidth($r['message'] ?? 'System error detected.', 0, 200, '…'),
            $key,
            'reports_technical.php'
        );
    }
} catch (Exception $e) {}

// 2b. Database backup errors
try {
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Backup%' OR al.action LIKE '%backup%')
           AND (al.details LIKE '%fail%' OR al.details LIKE '%error%' OR al.details LIKE '%Error%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'db_backup_error_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'error', 'database_error', 'high',
            'Database Backup Error',
            mb_strimwidth($r['details'] ?? 'Database backup failed.', 0, 200, '…'),
            $key,
            'database_management.php'
        );
    }
} catch (Exception $e) {}

// 2c. Server downtime warnings
try {
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action = 'Server Downtime' OR al.action LIKE 'Server Downtime%' OR al.details LIKE '%server offline%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'server_status_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'warning', 'system_error', 'high',
            'Server Status Alert',
            "Server warning detected: " . ($r['action'] ?? '') . " | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'reports_technical.php'
        );
    }
} catch (Exception $e) {}

// 2d. High CPU / Memory usage
try {
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action = 'High CPU Usage' OR al.action = 'High Memory Usage' OR al.details LIKE '%high cpu usage%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'cpu_memory_usage_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'warning', 'system_error', 'medium',
            'High Resource Usage Warning',
            "Resource warning: " . ($r['action'] ?? '') . " | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'reports_technical.php'
        );
    }
} catch (Exception $e) {}

// 2e. Database connection errors
try {
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Database Connection%' OR al.action LIKE '%db connection%' OR al.details LIKE '%connection fail%' OR al.details LIKE '%PDOException%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'db_connection_err_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'error', 'database_error', 'critical',
            'Database Connection Error',
            "Database error: " . ($r['action'] ?? '') . " | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'database_management.php'
        );
    }
} catch (Exception $e) {}


// ════════════════════════════════════════════════════════════
// 3. AUDIT & LOGS NOTIFICATIONS
//    Source: activity_logs — mass edits/deletes, exports
// ════════════════════════════════════════════════════════════

// 3a. Mass delete/soft-delete events (≥5 deletes by same user in 1h)
try {
    $rows = $pdo->query(
        "SELECT al.user_id, u.username AS user_name, COUNT(*) AS cnt,
                MAX(al.created_at) AS last_at
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE (al.action LIKE '%Delete%' OR al.action LIKE '%delete%'
                OR al.action LIKE '%Soft Delete%' OR al.action LIKE '%Remove%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
         GROUP BY al.user_id
         HAVING cnt >= 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'mass_delete_' . ($r['user_id'] ?? 'anon') . '_' . date('YmdH');
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'warning', 'mass_delete', 'high',
            'Mass Delete Activity Detected',
            "{$r['cnt']} delete operations by " . ($r['user_name'] ?? 'Unknown') . " in the last hour. Review audit trail.",
            $key,
            'superadmin_audit_trail.php'
        );
    }
} catch (Exception $e) {}

// 3b. Log export activity (compliance tracking)
try {
    $rows = $pdo->query(
        "SELECT al.id, al.user_id, u.username AS user_name, al.details, al.created_at
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.action LIKE 'SLA Export%'
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY al.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'log_export_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'info', 'export_logs', 'low',
            'Audit Log Exported',
            "Log export performed by " . ($r['user_name'] ?? 'Unknown') . ". " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'superadmin_audit_trail.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 4. INTEGRATION & API ALERTS
//    Source: activity_logs, integration_audit
// ════════════════════════════════════════════════════════════

// 4a. POS import failures
try {
    $rows = $pdo->query(
        "SELECT al.id, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%POS Import%' OR al.action LIKE '%import%' OR al.action LIKE '%Import%')
           AND (al.details LIKE '%fail%' OR al.details LIKE '%error%' OR al.details LIKE '%Error%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'pos_import_fail_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'error', 'pos_import_failure', 'high',
            'POS Import Failure',
            mb_strimwidth($r['details'] ?? 'POS import failed.', 0, 200, '…'),
            $key,
            'superadmin_integration_settings.php'
        );
    }
} catch (Exception $e) {}

// 4b. Integration audit changes (API endpoint / sync rule changes)
try {
    $rows = $pdo->query(
        "SELECT ia.id, ia.action_type, ia.endpoint_name, ia.changed_by_name, ia.created_at
         FROM integration_audit ia
         WHERE ia.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY ia.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'integration_change_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'info', 'integration_change', 'medium',
            'Integration Setting Changed',
            "Action: {$r['action_type']} on " . ($r['endpoint_name'] ?? 'endpoint') . " by " . ($r['changed_by_name'] ?? 'Unknown'),
            $key,
            'superadmin_integration_settings.php'
        );
    }
} catch (Exception $e) { /* integration_audit may not exist yet */ }

// 4c. API connection failures
try {
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%API%' OR al.action LIKE '%fleet%' OR al.action LIKE '%ERP%' OR al.details LIKE '%API failure%' OR al.details LIKE '%ERP sync%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'api_conn_fail_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'error', 'pos_import_failure', 'high',
            'API Integration Failure',
            "API Connection failed: " . ($r['action'] ?? '') . " | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'superadmin_integration_settings.php'
        );
    }
} catch (Exception $e) {}

// 4d. Git commit / merge conflicts
try {
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Git%' OR al.details LIKE '%conflict%' OR al.action LIKE '%merge%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'git_conflict_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'warning', 'integration_change', 'high',
            'Git Merge Conflict Alert',
            "Git Conflict detected: " . ($r['action'] ?? '') . " | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'reports_developer_audit.php'
        );
    }
} catch (Exception $e) {}

// 4e. Sync job errors / delays
try {
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Sync%' AND (al.details LIKE '%delay%' OR al.details LIKE '%error%' OR al.details LIKE '%fail%'))
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'sync_job_delay_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'warning', 'pos_import_failure', 'medium',
            'Sync Job Error/Delay',
            "Sync Job: " . ($r['action'] ?? '') . " | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'superadmin_integration_settings.php'
        );
    }
} catch (Exception $e) {}


// ════════════════════════════════════════════════════════════
// 5. REPORTS & DEVELOPER OVERSIGHT
//    Source: activity_logs — security events, performance
// ════════════════════════════════════════════════════════════

// 5a. High volume of failed authentications (≥10 in 1h system-wide)
try {
    $cnt = (int)$pdo->query(
        "SELECT COUNT(*) FROM activity_logs
         WHERE (action LIKE '%Failed%' OR action LIKE '%failed%')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    )->fetchColumn();

    if ($cnt >= 10) {
        $key = 'sec_report_failed_auth_' . date('YmdH');
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'error', 'security_report', 'critical',
            'Security Alert: High Failed Authentication Volume',
            "{$cnt} failed authentication events in the last hour. Possible brute-force attack. Review security report.",
            $key,
            'reports_security.php'
        );
    }
} catch (Exception $e) {}

// 5b. Developer/config audit changes
try {
    $rows = $pdo->query(
        "SELECT mca.id, mca.module_key, mca.config_key, mca.action_type,
                u.username AS user_name, mca.timestamp
         FROM module_config_audit mca
         LEFT JOIN users u ON u.id = mca.changed_by
         WHERE mca.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY mca.timestamp DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'module_config_change_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'info', 'config_change', 'medium',
            'Module Config Changed',
            "Module '{$r['module_key']}' — {$r['config_key']} was {$r['action_type']} by " . ($r['user_name'] ?? 'Unknown'),
            $key,
            'reports_developer_audit.php'
        );
    }
} catch (Exception $e) {}

// 5c. Deployment / Rollback logs
try {
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Deploy%' OR al.action LIKE '%Release%' OR al.action LIKE '%Rollback%' OR al.details LIKE '%rollback%' OR al.details LIKE '%deployment%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'deployment_log_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'info', 'config_change', 'medium',
            'System Deployment Logged',
            "Deployment: " . ($r['action'] ?? '') . " | " . mb_strimwidth($r['details'] ?? '', 0, 120, '…'),
            $key,
            'reports_developer_audit.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 6. SYSTEM SETTINGS ALERTS
//    Source: system_settings_audit, activity_logs
// ════════════════════════════════════════════════════════════

// 6a. Branding/UI changes (logo, theme, layout, accessibility)
try {
    $rows = $pdo->query(
        "SELECT ssa.id, ssa.setting_key, ssa.old_value, ssa.new_value,
                ssa.changed_by_name, ssa.created_at
         FROM system_settings_audit ssa
         WHERE ssa.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY ssa.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'settings_change_' . $r['id'];
        $label = match(true) {
            str_contains($r['setting_key'], 'logo')          => 'Logo',
            str_contains($r['setting_key'], 'color')         => 'Color Theme',
            str_contains($r['setting_key'], 'theme')         => 'Theme',
            str_contains($r['setting_key'], 'sidebar')       => 'Sidebar Layout',
            str_contains($r['setting_key'], 'accessibility') => 'Accessibility',
            str_contains($r['setting_key'], 'font')          => 'Typography',
            default                                           => ucwords(str_replace('_', ' ', $r['setting_key']))
        };
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'info', 'settings_change', 'low',
            "System Settings Updated: {$label}",
            "{$label} setting changed by " . ($r['changed_by_name'] ?? 'Unknown') . ". Key: {$r['setting_key']}",
            $key,
            'superadmin_system_settings.php'
        );
    }
} catch (Exception $e) {}

// 6b. Unauthorized settings access attempts
try {
    $rows = $pdo->query(
        "SELECT al.id, al.ip_address, al.details, al.created_at, u.username AS user_name
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE (al.action LIKE '%System Settings%' OR al.action LIKE '%system_settings%')
           AND (al.details LIKE '%denied%' OR al.details LIKE '%unauthorized%'
                OR al.details LIKE '%Unauthorized%' OR al.details LIKE '%403%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $key = 'settings_unauth_' . $r['id'];
        $generated += push_sa_notification(
            $pdo, $sa_ids,
            'error', 'unauthorized_settings', 'critical',
            'Unauthorized Settings Access Attempt',
            "Unauthorized attempt to access System Settings. User: " . ($r['user_name'] ?? 'Unknown') . " | IP: {$r['ip_address']}",
            $key,
            'superadmin_audit_trail.php'
        );
    }
} catch (Exception $e) {}

// ── Clean up old read notifications (>30 days) for superadmins ─────────
try {
    if (!empty($sa_ids)) {
        $in_clause = implode(',', array_map('intval', $sa_ids));
        $pdo->exec(
            "DELETE FROM notifications
             WHERE user_id IN ($in_clause)
               AND status = 'read'
               AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }
} catch (Exception $e) {}

echo json_encode(['ok' => true, 'generated' => $generated]);
