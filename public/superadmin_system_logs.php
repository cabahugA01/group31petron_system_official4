<?php
// ============================================================
// SuperAdmin – System Logs & Audit
// public/superadmin_system_logs.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    header('Location: super_admin_dashboard.php'); exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Active section ────────────────────────────────────────────
$section = $_GET['section'] ?? 'audit_trail';
$allowed = ['audit_trail', 'error_tracking', 'export_logs', 'developer_log'];
if (!in_array($section, $allowed)) $section = 'audit_trail';

$page_id = match($section) {
    'audit_trail'    => 'sla_audit_trail',
    'error_tracking' => 'sla_error_tracking',
    'export_logs'    => 'sla_export_logs',
    'developer_log'  => 'sla_developer_log',
    default          => 'sla_audit_trail',
};

// ── Bootstrap system_error_logs table ────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_error_logs (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        severity    ENUM('critical','warning','info') NOT NULL DEFAULT 'info',
        error_type  VARCHAR(100) NOT NULL,
        message     TEXT NOT NULL,
        context     TEXT,
        user_id     INT NULL,
        ip_address  VARCHAR(45),
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_severity  (severity),
        INDEX idx_created   (created_at),
        INDEX idx_type      (error_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── Handle CSV export (GET action) ───────────────────────────
if (isset($_GET['export']) && in_array($_GET['export'], ['audit_csv','errors_csv'])) {
    $export_type = $_GET['export'];
    $date_from   = $_GET['date_from'] ?? date('Y-m-01');
    $date_to     = $_GET['date_to']   ?? date('Y-m-d');

    if ($export_type === 'audit_csv') {
        $exp_user    = trim($_GET['filter_user']    ?? '');
        $exp_station = trim($_GET['filter_station'] ?? '');
        $exp_role    = trim($_GET['filter_role']    ?? '');
        $exp_action  = trim($_GET['filter_action']  ?? '');

        $exp_where  = ['al.created_at BETWEEN ? AND ?'];
        $exp_params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
        if ($exp_user    !== '') { $exp_where[] = 'al.user_id = ?';    $exp_params[] = (int)$exp_user; }
        if ($exp_station !== '') { $exp_where[] = 'al.station_id = ?'; $exp_params[] = (int)$exp_station; }
        if ($exp_role    !== '') { $exp_where[] = 'u.role = ?';        $exp_params[] = $exp_role; }
        if ($exp_action  !== '') { $exp_where[] = 'al.action LIKE ?';  $exp_params[] = '%'.$exp_action.'%'; }

        $rows = $pdo->prepare(
            "SELECT al.id, u.name AS user_name, u.role, al.action, al.details,
                    al.ip_address, s.name AS station_name, al.created_at
             FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             LEFT JOIN stations s ON s.id = al.station_id
             WHERE " . implode(' AND ', $exp_where) . "
             ORDER BY al.created_at DESC LIMIT 10000"
        );
        $rows->execute($exp_params);
        $data = $rows->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_trail_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['#', 'Timestamp', 'User', 'Role', 'Action', 'Details', 'IP Address', 'Station']);
        foreach ($data as $i => $r) {
            fputcsv($out, [$i+1, $r['created_at'], $r['user_name']??'System', $r['role']??'—',
                           $r['action'], $r['details']??'', $r['ip_address']??'', $r['station_name']??'—']);
        }
        fclose($out);
        log_activity($pdo, $me['id'], 'SLA Export', "Exported audit trail CSV ({$date_from} to {$date_to})");
        exit;
    }

    if ($export_type === 'errors_csv') {
        $rows = $pdo->prepare(
            "SELECT el.id, el.severity, el.error_type, el.message, el.context,
                    u.name AS user_name, el.ip_address, el.created_at
             FROM system_error_logs el
             LEFT JOIN users u ON u.id = el.user_id
             WHERE el.created_at BETWEEN ? AND ?
             ORDER BY el.created_at DESC LIMIT 10000"
        );
        $rows->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
        $data = $rows->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="error_logs_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['#', 'Timestamp', 'Severity', 'Error Type', 'Message', 'Context', 'User', 'IP']);
        foreach ($data as $i => $r) {
            fputcsv($out, [$i+1, $r['created_at'], strtoupper($r['severity']), $r['error_type'],
                           $r['message'], $r['context']??'', $r['user_name']??'System', $r['ip_address']??'']);
        }
        fclose($out);
        log_activity($pdo, $me['id'], 'SLA Export', "Exported error logs CSV ({$date_from} to {$date_to})");
        exit;
    }
}

// ── Shared filter params ──────────────────────────────────────
$date_from      = trim($_GET['date_from']      ?? date('Y-m-01'));
$date_to        = trim($_GET['date_to']        ?? date('Y-m-d'));
$filter_user    = trim($_GET['filter_user']    ?? '');
$filter_action  = trim($_GET['filter_action']  ?? '');
$filter_station = trim($_GET['filter_station'] ?? '');
$filter_role    = trim($_GET['filter_role']    ?? '');
$filter_sev     = trim($_GET['filter_sev']     ?? '');
$page_num       = max(1, (int)($_GET['p'] ?? 1));
$per_page       = 50;
$offset         = ($page_num - 1) * $per_page;

// ── Section 1: Audit Trail ────────────────────────────────────
$audit_rows  = [];
$audit_total = 0;
$audit_pages = 1;
$all_users   = [];
$all_actions = [];

if ($section === 'audit_trail') {
    // Fetch distinct users for filter dropdown
    try {
        $all_users = $pdo->query(
            "SELECT DISTINCT u.id, u.name FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE u.id IS NOT NULL ORDER BY u.name LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Fetch distinct action types for filter dropdown
    try {
        $all_actions = $pdo->query(
            "SELECT DISTINCT action FROM activity_logs ORDER BY action LIMIT 100"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}

    // Fetch all stations for filter dropdown
    $all_stations = [];
    try {
        $all_stations = $pdo->query(
            "SELECT DISTINCT s.id, s.name FROM activity_logs al
             LEFT JOIN stations s ON s.id = al.station_id
             WHERE s.id IS NOT NULL ORDER BY s.name LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $where  = ['al.created_at BETWEEN ? AND ?'];
    $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];

    if ($filter_user !== '') {
        $where[]  = 'al.user_id = ?';
        $params[] = (int)$filter_user;
    }
    if ($filter_action !== '') {
        $where[]  = 'al.action LIKE ?';
        $params[] = '%' . $filter_action . '%';
    }
    if ($filter_station !== '') {
        $where[]  = 'al.station_id = ?';
        $params[] = (int)$filter_station;
    }
    if ($filter_role !== '') {
        $where[]  = 'u.role = ?';
        $params[] = $filter_role;
    }

    $where_sql = implode(' AND ', $where);

    try {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al WHERE {$where_sql}");
        $cnt->execute($params);
        $audit_total = (int)$cnt->fetchColumn();
        $audit_pages = max(1, (int)ceil($audit_total / $per_page));

        $stmt = $pdo->prepare(
            "SELECT al.id, al.user_id, al.action, al.details, al.ip_address,
                    al.created_at, u.name AS user_name, u.role AS user_role,
                    s.name AS station_name
             FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             LEFT JOIN stations s ON s.id = al.station_id
             WHERE {$where_sql}
             ORDER BY al.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $audit_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    log_activity($pdo, $me['id'], 'SLA View', "Viewed audit trail (page {$page_num}, {$date_from} to {$date_to})");
}

// ── Section 2: Error Tracking ─────────────────────────────────
$error_rows  = [];
$error_total = 0;
$error_pages = 1;
$error_stats = ['critical' => 0, 'warning' => 0, 'info' => 0];

if ($section === 'error_tracking') {
    // Seed some real errors from activity_logs (login failures, unauthorized access)
    try {
        // Auto-populate error_logs from activity_logs patterns
        $pdo->exec("
            INSERT IGNORE INTO system_error_logs (severity, error_type, message, user_id, ip_address, created_at)
            SELECT
                CASE
                    WHEN action LIKE '%Failed%' OR action LIKE '%Unauthorized%' OR action LIKE '%denied%' THEN 'critical'
                    WHEN action LIKE '%Error%' OR action LIKE '%Invalid%' THEN 'warning'
                    ELSE 'info'
                END,
                action,
                COALESCE(details, action),
                user_id,
                ip_address,
                created_at
            FROM activity_logs
            WHERE (action LIKE '%Failed%' OR action LIKE '%Error%' OR action LIKE '%Unauthorized%'
                   OR action LIKE '%Invalid%' OR action LIKE '%denied%')
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            LIMIT 500
        ");
    } catch (Exception $e) {}

    // Stats
    try {
        $stats = $pdo->query(
            "SELECT severity, COUNT(*) AS cnt FROM system_error_logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY severity"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stats as $s) {
            $error_stats[$s['severity']] = (int)$s['cnt'];
        }
    } catch (Exception $e) {}

    $where  = ['el.created_at BETWEEN ? AND ?'];
    $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];

    if ($filter_sev !== '') {
        $where[]  = 'el.severity = ?';
        $params[] = $filter_sev;
    }
    if ($filter_action !== '') {
        $where[]  = '(el.error_type LIKE ? OR el.message LIKE ?)';
        $params[] = '%' . $filter_action . '%';
        $params[] = '%' . $filter_action . '%';
    }

    $where_sql = implode(' AND ', $where);

    try {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM system_error_logs el WHERE {$where_sql}");
        $cnt->execute($params);
        $error_total = (int)$cnt->fetchColumn();
        $error_pages = max(1, (int)ceil($error_total / $per_page));

        $stmt = $pdo->prepare(
            "SELECT el.*, u.name AS user_name
             FROM system_error_logs el
             LEFT JOIN users u ON u.id = el.user_id
             WHERE {$where_sql}
             ORDER BY el.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $error_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    log_activity($pdo, $me['id'], 'SLA View', "Viewed error tracking (page {$page_num})");
}

// ── Section 3: Export Logs ────────────────────────────────────
$export_history = [];
if ($section === 'export_logs') {
    try {
        $export_history = $pdo->query(
            "SELECT al.id, al.details, al.created_at, u.name AS user_name
             FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.action LIKE 'SLA Export%'
             ORDER BY al.created_at DESC LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    log_activity($pdo, $me['id'], 'SLA View', "Viewed export logs section");
}

// ── Section 4: SuperAdmin Audit Trail ────────────────────────
$dev_rows  = [];
$dev_total = 0;
$dev_pages = 1;

if ($section === 'developer_log') {
    // Show ALL actions performed by superadmin/developer users
    $where  = ["u.role IN ('superadmin','developer') AND al.created_at BETWEEN ? AND ?"];
    $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];

    if ($filter_action !== '') {
        $where[] = 'al.action LIKE ?';
        $params[] = '%' . $filter_action . '%';
    }

    $where_sql = implode(' AND ', $where);

    try {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id WHERE {$where_sql}");
        $cnt->execute($params);
        $dev_total = (int)$cnt->fetchColumn();
        $dev_pages = max(1, (int)ceil($dev_total / $per_page));

        $stmt = $pdo->prepare(
            "SELECT al.id, al.action, al.details, al.ip_address, al.created_at,
                    u.name AS user_name, u.role AS user_role
             FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE {$where_sql}
             ORDER BY al.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $dev_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    log_activity($pdo, $me['id'], 'SLA View', "Viewed SuperAdmin audit trail (page {$page_num})");
}

// ── Summary stats for header ──────────────────────────────────
$total_audit  = 0;
$total_errors = 0;
$total_exports = 0;
try {
    $total_audit  = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
    $total_errors = (int)$pdo->query("SELECT COUNT(*) FROM system_error_logs")->fetchColumn();
    $total_exports = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'SLA Export%'")->fetchColumn();
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<style>
.sla-page{padding:28px 24px}
.sla-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px}
.sla-head h1{font-size:22px!important;font-weight:700!important;color:var(--petron-blue)!important;margin:0!important;text-transform:uppercase!important}
.sla-head .sub{font-size:13px;color:#666;margin-top:4px;text-transform:none!important}
.sla-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:20px}
.sla-stat{background:#fff;border:1px solid #eaeaea;border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 6px rgba(0,0,0,.04)}
.sla-stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.sla-stat-val{font-size:22px;font-weight:800;color:var(--petron-blue);line-height:1}
.sla-stat-lbl{font-size:11px;color:#888;margin-top:2px}
.sla-card{background:#fff;border:1px solid #eaeaea;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);overflow:hidden;margin-bottom:20px}
.sla-card-header{padding:16px 20px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.sla-card-header h3{font-size:14px!important;font-weight:700!important;color:var(--petron-blue)!important;margin:0!important;text-transform:uppercase!important;display:flex;align-items:center;gap:8px}
.sla-card-body{padding:18px 20px}
.sla-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px}
.sla-filters label{font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:4px}
.sla-select,.sla-input{padding:8px 12px;border:1px solid #ddd;border-radius:9px;font-size:13px;outline:none;background:#fff;transition:border-color .2s}
.sla-select:focus,.sla-input:focus{border-color:var(--petron-blue);box-shadow:0 0 0 3px rgba(0,38,77,.08)}
.sla-table-wrap{overflow-x:auto;border-radius:10px;border:1px solid #eee}
.sla-table{width:100%;border-collapse:collapse;font-size:12px;min-width:700px}
.sla-table thead th{background:var(--petron-blue);color:#fff;padding:10px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.sla-table tbody tr{border-bottom:1px solid #f5f5f5;transition:background .12s}
.sla-table tbody tr:last-child{border-bottom:none}
.sla-table tbody tr:hover{background:#f8fafc}
.sla-table td{padding:9px 14px;vertical-align:middle;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sla-badge{padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap}
.sla-critical{background:rgba(204,0,0,.1);color:#cc0000}
.sla-warning{background:rgba(255,193,7,.15);color:#b8860b}
.sla-info{background:rgba(0,38,77,.08);color:var(--petron-blue)}
.sla-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .2s;background:none;text-decoration:none}
.sla-btn-primary{background:var(--petron-blue);color:#fff;border-color:var(--petron-blue)}
.sla-btn-primary:hover{background:#001a3d}
.sla-btn-success{background:#28a745;color:#fff;border-color:#28a745}
.sla-btn-success:hover{background:#1e7e34}
.sla-btn-outline{color:var(--petron-blue);border-color:var(--petron-blue)}
.sla-btn-outline:hover{background:rgba(0,38,77,.06)}
.sla-pagination{display:flex;align-items:center;gap:8px;padding:12px 0 0;flex-wrap:wrap}
.sla-page-btn{padding:5px 11px;border:1px solid #ddd;border-radius:7px;font-size:12px;cursor:pointer;background:#fff;transition:all .15s;text-decoration:none;color:#333}
.sla-page-btn:hover{border-color:var(--petron-blue);color:var(--petron-blue)}
.sla-page-btn.active{background:var(--petron-blue);color:#fff;border-color:var(--petron-blue)}
.sla-info-box{background:#f8fafc;border:1px solid #e8edf2;border-radius:10px;padding:11px 14px;font-size:12px;color:#555;display:flex;align-items:flex-start;gap:8px;margin-bottom:16px}
.sla-info-box i{color:var(--petron-blue);flex-shrink:0;margin-top:1px}
.sla-export-card{background:#fff;border:1px solid #eaeaea;border-radius:14px;padding:20px;display:flex;flex-direction:column;gap:10px;box-shadow:0 2px 6px rgba(0,0,0,.04)}
.sla-export-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
.sla-export-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px}
.sla-step-bar{display:flex;gap:0;margin-bottom:20px;background:#fff;border:1px solid #eaeaea;border-radius:14px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.sla-step{flex:1;display:flex;align-items:center;gap:10px;padding:13px 16px;font-size:12px;font-weight:600;color:#aaa;border-right:1px solid #f0f0f0}
.sla-step:last-child{border-right:none}
.sla-step .sn{width:24px;height:24px;border-radius:50%;background:#eee;color:#aaa;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0}
.sla-step.active{color:var(--petron-blue);background:rgba(0,38,77,.04)}
.sla-step.active .sn{background:var(--petron-blue);color:#fff}
.sla-step.done .sn{background:#28a745;color:#fff}
.sla-step.done{color:#28a745}
@media(max-width:700px){.sla-step .sd{display:none}.sla-step{padding:10px 8px;gap:6px}}
</style>
<div class="sla-page">
<div class="sla-head">
  <div>
    <h1><i class="fas fa-shield-alt" style="margin-right:8px;"></i>System Logs &amp; Audit
      <span style="font-size:14px;font-weight:500;color:#888;text-transform:none;margin-left:10px;">/ <?php echo match($section){'audit_trail'=>'Audit Trail','error_tracking'=>'Error Tracking','export_logs'=>'Export Logs','developer_log'=>'Developer Log',default=>'Audit Trail'}; ?></span>
    </h1>
    <div class="sub">Full system transparency and compliance monitoring. SuperAdmin / Developer only.</div>
  </div>
</div>

<!-- Step bar -->
<div class="sla-step-bar">
  <?php $steps=[['audit_trail','1','Audit Trail','Full action log'],['error_tracking','2','Error Tracking','Failures & anomalies'],['export_logs','3','Export Logs','CSV download'],['developer_log','4','SuperAdmin Audit','All SA actions']];
  foreach($steps as [$s,$n,$t,$d]): $cls=$section===$s?'active':($section!=='audit_trail'&&array_search([$s,$n,$t,$d],$steps)<array_search([$section,'','',''],$steps)?'done':''); ?>
  <div class="sla-step <?php echo $cls; ?>">
    <div class="sn"><?php echo $cls==='done'?'<i class="fas fa-check" style="font-size:9px;"></i>':$n; ?></div>
    <div><span style="display:block;font-size:12px;font-weight:700;"><?php echo $t; ?></span><span style="display:block;font-size:10px;opacity:.7;" class="sd"><?php echo $d; ?></span></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Stats -->
<div class="sla-stats">
  <div class="sla-stat"><div class="sla-stat-icon" style="background:rgba(0,38,77,.1);color:var(--petron-blue);"><i class="fas fa-list-alt"></i></div><div><div class="sla-stat-val"><?php echo number_format($total_audit); ?></div><div class="sla-stat-lbl">Total Audit Logs</div></div></div>
  <div class="sla-stat"><div class="sla-stat-icon" style="background:rgba(204,0,0,.1);color:#cc0000;"><i class="fas fa-exclamation-triangle"></i></div><div><div class="sla-stat-val"><?php echo number_format($total_errors); ?></div><div class="sla-stat-lbl">Error Records</div></div></div>
  <div class="sla-stat"><div class="sla-stat-icon" style="background:rgba(40,167,69,.1);color:#28a745;"><i class="fas fa-download"></i></div><div><div class="sla-stat-val"><?php echo number_format($total_exports); ?></div><div class="sla-stat-lbl">Exports Done</div></div></div>
  <div class="sla-stat"><div class="sla-stat-icon" style="background:rgba(111,66,193,.1);color:#6f42c1;"><i class="fas fa-user-shield"></i></div><div><div class="sla-stat-val" style="font-size:14px;"><?php echo htmlspecialchars($me['name']??'—'); ?></div><div class="sla-stat-lbl">Logged In As</div></div></div>
</div>

<?php if($section==='audit_trail'): ?>
<!-- ══ AUDIT TRAIL ══ -->
<div class="sla-card">
  <div class="sla-card-header">
    <h3><i class="fas fa-list-alt"></i> Full Audit Trail</h3>
    <a href="?section=audit_trail&export=audit_csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_station=<?php echo urlencode($filter_station); ?>&filter_role=<?php echo urlencode($filter_role); ?>&filter_action=<?php echo urlencode($filter_action); ?>" class="sla-btn sla-btn-success"><i class="fas fa-file-csv"></i> Export CSV</a>
  </div>
  <div class="sla-card-body">
    <div class="sla-info-box"><i class="fas fa-info-circle"></i><span>Read-only view of all system actions. Who performed it, what action, when, and from which IP/device. SuperAdmin cannot modify these records.</span></div>
    <form method="GET" class="sla-filters">
      <input type="hidden" name="section" value="audit_trail">
      <div><label>From</label><input type="date" name="date_from" class="sla-input" value="<?php echo htmlspecialchars($date_from); ?>"></div>
      <div><label>To</label><input type="date" name="date_to" class="sla-input" value="<?php echo htmlspecialchars($date_to); ?>"></div>
      <div><label>User</label>
        <select name="filter_user" class="sla-select">
          <option value="">All Users</option>
          <?php foreach($all_users as $u): ?><option value="<?php echo (int)$u['id']; ?>" <?php echo $filter_user==(string)$u['id']?'selected':''; ?>><?php echo htmlspecialchars($u['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label>Role</label>
        <select name="filter_role" class="sla-select">
          <option value="">All Roles</option>
          <option value="admin"     <?php echo $filter_role==='admin'?'selected':''; ?>>Admin</option>
          <option value="manager"   <?php echo $filter_role==='manager'?'selected':''; ?>>Manager</option>
          <option value="staff"     <?php echo $filter_role==='staff'?'selected':''; ?>>Staff</option>
          <option value="superadmin"<?php echo $filter_role==='superadmin'?'selected':''; ?>>SuperAdmin</option>
        </select>
      </div>
      <div><label>Station</label>
        <select name="filter_station" class="sla-select">
          <option value="">All Stations</option>
          <?php foreach($all_stations as $st): ?><option value="<?php echo (int)$st['id']; ?>" <?php echo $filter_station==(string)$st['id']?'selected':''; ?>><?php echo htmlspecialchars($st['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label>Action</label>
        <select name="filter_action" class="sla-select">
          <option value="">All Actions</option>
          <?php foreach($all_actions as $a): ?><option value="<?php echo htmlspecialchars($a); ?>" <?php echo $filter_action===$a?'selected':''; ?>><?php echo htmlspecialchars($a); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div style="padding-top:18px;"><button type="submit" class="sla-btn sla-btn-primary"><i class="fas fa-search"></i> Filter</button></div>
    </form>
    <div style="font-size:12px;color:#888;margin-bottom:8px;"><?php echo number_format($audit_total); ?> records &nbsp;|&nbsp; Page <?php echo $page_num; ?> of <?php echo $audit_pages; ?></div>
    <div class="sla-table-wrap">
      <table class="sla-table">
        <thead><tr><th>#</th><th>Timestamp</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP Address</th><th>Station</th></tr></thead>
        <tbody>
        <?php if(empty($audit_rows)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:#bbb;">No audit records found for the selected filters.</td></tr>
        <?php else: foreach($audit_rows as $i=>$r): ?>
        <tr>
          <td style="color:#999;font-size:11px;"><?php echo ($page_num-1)*$per_page+$i+1; ?></td>
          <td style="white-space:nowrap;"><?php echo htmlspecialchars(date('M d, Y H:i:s',strtotime($r['created_at']))); ?></td>
          <td style="font-weight:600;"><?php echo htmlspecialchars($r['user_name']??'System'); ?></td>
          <td><span class="sla-badge sla-info"><?php echo htmlspecialchars($r['user_role']??'—'); ?></span></td>
          <td style="font-weight:600;color:var(--petron-blue);"><?php echo htmlspecialchars($r['action']); ?></td>
          <td title="<?php echo htmlspecialchars($r['details']??''); ?>"><?php echo htmlspecialchars(mb_strimwidth($r['details']??'',0,80,'…')); ?></td>
          <td style="font-family:monospace;font-size:11px;"><?php echo htmlspecialchars($r['ip_address']??'—'); ?></td>
          <td><?php echo htmlspecialchars($r['station_name']??'—'); ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if($audit_pages>1): ?><div class="sla-pagination"><?php for($p=1;$p<=min($audit_pages,20);$p++): ?><a href="?section=audit_trail&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_action=<?php echo urlencode($filter_action); ?>&filter_station=<?php echo urlencode($filter_station); ?>&filter_role=<?php echo urlencode($filter_role); ?>&p=<?php echo $p; ?>" class="sla-page-btn <?php echo $p==$page_num?'active':''; ?>"><?php echo $p; ?></a><?php endfor; ?></div><?php endif; ?>
  </div>
</div>

<?php elseif($section==='error_tracking'): ?>
<!-- ══ ERROR TRACKING ══ -->
<div class="sla-stats" style="margin-bottom:16px;">
  <div class="sla-stat"><div class="sla-stat-icon" style="background:rgba(204,0,0,.1);color:#cc0000;"><i class="fas fa-times-circle"></i></div><div><div class="sla-stat-val"><?php echo number_format($error_stats['critical']); ?></div><div class="sla-stat-lbl">Critical</div></div></div>
  <div class="sla-stat"><div class="sla-stat-icon" style="background:rgba(255,193,7,.15);color:#b8860b;"><i class="fas fa-exclamation-circle"></i></div><div><div class="sla-stat-val"><?php echo number_format($error_stats['warning']); ?></div><div class="sla-stat-lbl">Warning</div></div></div>
  <div class="sla-stat"><div class="sla-stat-icon" style="background:rgba(0,38,77,.08);color:var(--petron-blue);"><i class="fas fa-info-circle"></i></div><div><div class="sla-stat-val"><?php echo number_format($error_stats['info']); ?></div><div class="sla-stat-lbl">Info</div></div></div>
</div>
<div class="sla-card">
  <div class="sla-card-header">
    <h3><i class="fas fa-exclamation-triangle"></i> Error Tracking</h3>
    <a href="?section=error_tracking&export=errors_csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="sla-btn sla-btn-success"><i class="fas fa-file-csv"></i> Export CSV</a>
  </div>
  <div class="sla-card-body">
    <div class="sla-info-box"><i class="fas fa-info-circle"></i><span>System errors auto-detected from activity logs: failed logins, unauthorized access, invalid inputs. SuperAdmin monitors only — no direct data editing.</span></div>
    <form method="GET" class="sla-filters">
      <input type="hidden" name="section" value="error_tracking">
      <div><label>From</label><input type="date" name="date_from" class="sla-input" value="<?php echo htmlspecialchars($date_from); ?>"></div>
      <div><label>To</label><input type="date" name="date_to" class="sla-input" value="<?php echo htmlspecialchars($date_to); ?>"></div>
      <div><label>Severity</label>
        <select name="filter_sev" class="sla-select">
          <option value="">All Severities</option>
          <option value="critical" <?php echo $filter_sev==='critical'?'selected':''; ?>>Critical</option>
          <option value="warning"  <?php echo $filter_sev==='warning'?'selected':''; ?>>Warning</option>
          <option value="info"     <?php echo $filter_sev==='info'?'selected':''; ?>>Info</option>
        </select>
      </div>
      <div><label>Search</label><input type="text" name="filter_action" class="sla-input" placeholder="Error type or message…" value="<?php echo htmlspecialchars($filter_action); ?>" style="width:200px;"></div>
      <div style="padding-top:18px;"><button type="submit" class="sla-btn sla-btn-primary"><i class="fas fa-search"></i> Filter</button></div>
    </form>
    <div style="font-size:12px;color:#888;margin-bottom:8px;"><?php echo number_format($error_total); ?> records &nbsp;|&nbsp; Page <?php echo $page_num; ?> of <?php echo $error_pages; ?></div>
    <div class="sla-table-wrap">
      <table class="sla-table">
        <thead><tr><th>#</th><th>Timestamp</th><th>Severity</th><th>Error Type</th><th>Message</th><th>Context</th><th>User</th><th>IP</th></tr></thead>
        <tbody>
        <?php if(empty($error_rows)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:#bbb;">No error records found for the selected filters.</td></tr>
        <?php else: foreach($error_rows as $i=>$r): ?>
        <tr>
          <td style="color:#999;font-size:11px;"><?php echo ($page_num-1)*$per_page+$i+1; ?></td>
          <td style="white-space:nowrap;"><?php echo htmlspecialchars(date('M d, Y H:i:s',strtotime($r['created_at']))); ?></td>
          <td><span class="sla-badge sla-<?php echo htmlspecialchars($r['severity']); ?>"><?php echo strtoupper($r['severity']); ?></span></td>
          <td style="font-weight:600;"><?php echo htmlspecialchars($r['error_type']); ?></td>
          <td title="<?php echo htmlspecialchars($r['message']); ?>"><?php echo htmlspecialchars(mb_strimwidth($r['message'],0,80,'…')); ?></td>
          <td title="<?php echo htmlspecialchars($r['context']??''); ?>"><?php echo htmlspecialchars(mb_strimwidth($r['context']??'',0,60,'…')); ?></td>
          <td><?php echo htmlspecialchars($r['user_name']??'System'); ?></td>
          <td style="font-family:monospace;font-size:11px;"><?php echo htmlspecialchars($r['ip_address']??'—'); ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if($error_pages>1): ?><div class="sla-pagination"><?php for($p=1;$p<=min($error_pages,20);$p++): ?><a href="?section=error_tracking&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&filter_sev=<?php echo urlencode($filter_sev); ?>&filter_action=<?php echo urlencode($filter_action); ?>&p=<?php echo $p; ?>" class="sla-page-btn <?php echo $p==$page_num?'active':''; ?>"><?php echo $p; ?></a><?php endfor; ?></div><?php endif; ?>
  </div>
</div>

<?php elseif($section==='export_logs'): ?>
<!-- ══ EXPORT LOGS ══ -->
<div class="sla-card">
  <div class="sla-card-header"><h3><i class="fas fa-download"></i> Export Logs</h3></div>
  <div class="sla-card-body">
    <div class="sla-info-box"><i class="fas fa-info-circle"></i><span>Export is read-only. No editing of logs. Use for external audit, compliance reporting, or developer debugging. All exports are themselves logged in the audit trail.</span></div>
    <div class="sla-export-grid">
      <div class="sla-export-card">
        <div class="sla-export-icon" style="background:rgba(0,38,77,.1);color:var(--petron-blue);"><i class="fas fa-list-alt"></i></div>
        <div style="font-size:14px;font-weight:700;color:#1a1a1a;">Audit Trail Export</div>
        <div style="font-size:12px;color:#888;line-height:1.5;">Full audit trail with user, action, timestamp, IP, and station. CSV format for Excel filtering.</div>
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
          <input type="hidden" name="section" value="export_logs">
          <input type="hidden" name="export" value="audit_csv">
          <input type="date" name="date_from" class="sla-input" value="<?php echo htmlspecialchars($date_from); ?>">
          <input type="date" name="date_to"   class="sla-input" value="<?php echo htmlspecialchars($date_to); ?>">
          <button type="submit" class="sla-btn sla-btn-primary"><i class="fas fa-file-csv"></i> Download CSV</button>
        </form>
      </div>
      <div class="sla-export-card">
        <div class="sla-export-icon" style="background:rgba(204,0,0,.1);color:#cc0000;"><i class="fas fa-exclamation-triangle"></i></div>
        <div style="font-size:14px;font-weight:700;color:#1a1a1a;">Error Logs Export</div>
        <div style="font-size:12px;color:#888;line-height:1.5;">All system errors with severity, type, message, and context. CSV format for compliance filing.</div>
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
          <input type="hidden" name="section" value="export_logs">
          <input type="hidden" name="export" value="errors_csv">
          <input type="date" name="date_from" class="sla-input" value="<?php echo htmlspecialchars($date_from); ?>">
          <input type="date" name="date_to"   class="sla-input" value="<?php echo htmlspecialchars($date_to); ?>">
          <button type="submit" class="sla-btn" style="background:#cc0000;color:#fff;border-color:#cc0000;"><i class="fas fa-file-csv"></i> Download CSV</button>
        </form>
      </div>
    </div>
    <?php if(!empty($export_history)): ?>
    <div style="margin-top:24px;">
      <div style="font-size:12px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.3px;margin-bottom:10px;"><i class="fas fa-history" style="margin-right:6px;"></i>Recent Export History</div>
      <div class="sla-table-wrap">
        <table class="sla-table">
          <thead><tr><th>#</th><th>Timestamp</th><th>Exported By</th><th>Details</th></tr></thead>
          <tbody>
          <?php foreach($export_history as $i=>$r): ?>
          <tr>
            <td style="color:#999;font-size:11px;"><?php echo $i+1; ?></td>
            <td style="white-space:nowrap;"><?php echo htmlspecialchars(date('M d, Y H:i:s',strtotime($r['created_at']))); ?></td>
            <td style="font-weight:600;"><?php echo htmlspecialchars($r['user_name']??'System'); ?></td>
            <td><?php echo htmlspecialchars($r['details']??'—'); ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif($section==='developer_log'): ?>
<!-- ══ SUPERADMIN AUDIT TRAIL ══ -->
<div class="sla-card">
  <div class="sla-card-header">
    <h3><i class="fas fa-user-shield"></i> SuperAdmin Audit Trail</h3>
    <a href="?section=developer_log&export=audit_csv&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&filter_role=superadmin&filter_action=<?php echo urlencode($filter_action); ?>" class="sla-btn sla-btn-success"><i class="fas fa-file-csv"></i> Export CSV</a>
  </div>
  <div class="sla-card-body">
    <div class="sla-info-box"><i class="fas fa-info-circle"></i><span>Complete audit trail of every action performed by SuperAdmin/Developer accounts — account creation, station management, module config, DB maintenance, system settings, and more. Read-only for compliance.</span></div>
    <form method="GET" class="sla-filters">
      <input type="hidden" name="section" value="developer_log">
      <div><label>From</label><input type="date" name="date_from" class="sla-input" value="<?php echo htmlspecialchars($date_from); ?>"></div>
      <div><label>To</label><input type="date" name="date_to" class="sla-input" value="<?php echo htmlspecialchars($date_to); ?>"></div>
      <div><label>Action</label><input type="text" name="filter_action" class="sla-input" placeholder="e.g. Create Admin, System Settings…" value="<?php echo htmlspecialchars($filter_action); ?>" style="width:220px;"></div>
      <div style="padding-top:18px;"><button type="submit" class="sla-btn sla-btn-primary"><i class="fas fa-search"></i> Filter</button></div>
    </form>
    <div style="font-size:12px;color:#888;margin-bottom:8px;"><?php echo number_format($dev_total); ?> records &nbsp;|&nbsp; Page <?php echo $page_num; ?> of <?php echo $dev_pages; ?></div>
    <div class="sla-table-wrap">
      <table class="sla-table">
        <thead><tr><th>#</th><th>Timestamp</th><th>SuperAdmin</th><th>Role</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php if(empty($dev_rows)): ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:#bbb;">No SuperAdmin actions logged yet for this period.</td></tr>
        <?php else: foreach($dev_rows as $i=>$r): ?>
        <tr>
          <td style="color:#999;font-size:11px;"><?php echo ($page_num-1)*$per_page+$i+1; ?></td>
          <td style="white-space:nowrap;"><?php echo htmlspecialchars(date('M d, Y H:i:s',strtotime($r['created_at']))); ?></td>
          <td style="font-weight:600;"><?php echo htmlspecialchars($r['user_name']??'System'); ?></td>
          <td><span class="sla-badge" style="background:rgba(111,66,193,.1);color:#6f42c1;"><?php echo htmlspecialchars($r['user_role']??'—'); ?></span></td>
          <td style="font-weight:600;color:var(--petron-blue);"><?php echo htmlspecialchars($r['action']); ?></td>
          <td title="<?php echo htmlspecialchars($r['details']??''); ?>"><?php echo htmlspecialchars(mb_strimwidth($r['details']??'',0,80,'…')); ?></td>
          <td style="font-family:monospace;font-size:11px;"><?php echo htmlspecialchars($r['ip_address']??'—'); ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if($dev_pages>1): ?><div class="sla-pagination"><?php for($p=1;$p<=min($dev_pages,20);$p++): ?><a href="?section=developer_log&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&filter_action=<?php echo urlencode($filter_action); ?>&p=<?php echo $p; ?>" class="sla-page-btn <?php echo $p==$page_num?'active':''; ?>"><?php echo $p; ?></a><?php endfor; ?></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>

</div><!-- /.sla-page -->
<?php include __DIR__ . '/../partials/footer.php'; ?>
