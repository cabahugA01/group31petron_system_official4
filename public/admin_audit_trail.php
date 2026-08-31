<?php
/**
 * Admin Audit Trail — Full Compliance Log (All Roles: Staff + Manager + Admin)
 * Shows every action at this station with timestamps, user IDs, roles, modules, IP.
 * Functions: View, filter by role/action/date, export CSV, detect anomalies.
 * Visible to: Admin + SuperAdmin only.
 */
$page_id = 'admin_audit_trail';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$date_from    = $_GET['date_from']    ?? date('Y-m-01');
$date_to      = $_GET['date_to']      ?? date('Y-m-d');
$role_f       = trim($_GET['role_f']       ?? '');
$action_f     = trim($_GET['action_f']     ?? '');
$user_f       = (int)($_GET['user_f']      ?? 0);
$log_type_f   = trim($_GET['log_type_f']   ?? '');
$search       = trim($_GET['search']       ?? '');
$show_anomaly = ($_GET['anomaly'] ?? '') === '1';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// ── Users list for filter ─────────────────────────────────────────────────────
$all_users = [];
try {
    $u_stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE station_id=? AND status='Active' ORDER BY role, name");
    $u_stmt->execute([$station_id]);
    $all_users = $u_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Build unified audit query: audit_logs + audit_trail merged ───────────────
// Source A: audit_logs (Staff create, Manager validate, Admin actions)
$al_where  = "WHERE u.station_id = ? AND DATE(al.created_at) BETWEEN ? AND ?";
$al_params = [$station_id, $date_from, $date_to];
if ($role_f   !== '') { $al_where .= " AND LOWER(TRIM(u.role)) = ?"; $al_params[] = strtolower($role_f); }
if ($user_f    > 0)   { $al_where .= " AND al.user_id = ?";          $al_params[] = $user_f; }
if ($action_f !== '') { $al_where .= " AND al.action_type = ?";       $al_params[] = $action_f; }
if ($log_type_f !== '') { $al_where .= " AND UPPER(al.log_type) = ?"; $al_params[] = strtoupper($log_type_f); }
if ($search   !== '') {
    $al_where .= " AND (al.action_details LIKE ? OR CAST(al.entity_id AS CHAR) LIKE ? OR u.username LIKE ? OR CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) LIKE ?)";
    $al_params[] = "%$search%"; $al_params[] = "%$search%"; $al_params[] = "%$search%"; $al_params[] = "%$search%";
}

$audit_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            al.id,
            al.created_at                                             AS logged_at,
            al.user_id,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                     u.username, CONCAT('User #',al.user_id))              AS actor_name,
            COALESCE(u.role,'unknown')                                AS actor_role,
            al.action_type,
            UPPER(COALESCE(al.log_type,'SYSTEM'))                     AS log_type,
            COALESCE(al.entity_type,'')                               AS entity_type,
            al.entity_id,
            COALESCE(al.action_details,'')                            AS details,
            COALESCE(al.status,'SUCCESS')                             AS status,
            COALESCE(al.ip_address,'')                                AS ip_address,
            al.user_agent,
            'audit_logs'                                              AS _source
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        $al_where
        ORDER BY al.created_at DESC
        LIMIT 800
    ");
    $stmt->execute($al_params);
    $audit_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("admin_audit_trail al error: " . $e->getMessage()); }

// Source B: audit_trail (transaction-level manager/admin actions)
try {
    $at_where  = "WHERE at2.station_id = ? AND DATE(at2.timestamp) BETWEEN ? AND ?";
    $at_params = [$station_id, $date_from, $date_to];
    if ($user_f > 0) { $at_where .= " AND at2.manager_id = ?"; $at_params[] = $user_f; }
    if ($search !== '') {
        $at_where .= " AND (at2.new_value LIKE ? OR CAST(at2.transaction_id AS CHAR) LIKE ?)";
        $at_params[] = "%$search%"; $at_params[] = "%$search%";
    }
    $at_src_col = '';
    try {
        $check = $pdo->query("SHOW COLUMNS FROM audit_trail LIKE 'source_table'")->rowCount();
        $at_src_col = $check ? 'at2.source_table' : "'merchandise_transactions'";
    } catch (Exception $e) { $at_src_col = "'merchandise_transactions'"; }

    $at_stmt = $pdo->prepare("
        SELECT
            at2.id,
            at2.timestamp                                              AS logged_at,
            at2.manager_id                                             AS user_id,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                     u.username, CONCAT('User #',at2.manager_id))          AS actor_name,
            COALESCE(u.role,'manager')                                 AS actor_role,
            at2.action_type,
            'TRANSACTION'                                              AS log_type,
            COALESCE($at_src_col,'merchandise_transactions')           AS entity_type,
            at2.transaction_id                                         AS entity_id,
            COALESCE(at2.new_value, at2.old_value,'')                 AS details,
            'SUCCESS'                                                  AS status,
            ''                                                         AS ip_address,
            ''                                                         AS user_agent,
            'audit_trail'                                              AS _source
        FROM audit_trail at2
        LEFT JOIN users u ON u.id = at2.manager_id
        $at_where
        ORDER BY at2.timestamp DESC
        LIMIT 400
    ");
    $at_stmt->execute($at_params);
    $at_rows = $at_stmt->fetchAll(PDO::FETCH_ASSOC);
    // Apply role/action filters to audit_trail rows (not possible in SQL without joins)
    if ($role_f !== '') $at_rows = array_filter($at_rows, fn($r) => strtolower($r['actor_role'] ?? '') === strtolower($role_f));
    if ($action_f !== '') $at_rows = array_filter($at_rows, fn($r) => $r['action_type'] === $action_f);
    $audit_rows = array_merge($audit_rows, array_values($at_rows));
    usort($audit_rows, fn($a,$b) => strtotime($b['logged_at']) - strtotime($a['logged_at']));
    $audit_rows = array_slice($audit_rows, 0, 1000);
} catch (Exception $e) { error_log("admin_audit_trail at error: " . $e->getMessage()); }

// Source C: activity_logs (lib.php log_activity() — all roles, all modules)
try {
    $al3_where  = "WHERE u.station_id = ? AND DATE(al3.created_at) BETWEEN ? AND ?";
    $al3_params = [$station_id, $date_from, $date_to];
    if ($role_f  !== '') { $al3_where .= " AND LOWER(TRIM(u.role)) = ?"; $al3_params[] = strtolower($role_f); }
    if ($user_f   > 0)  { $al3_where .= " AND al3.user_id = ?";          $al3_params[] = $user_f; }
    if ($action_f !== '') { $al3_where .= " AND al3.action = ?";          $al3_params[] = $action_f; }
    if ($search  !== '') {
        $al3_where .= " AND (al3.action LIKE ? OR al3.details LIKE ?)";
        $al3_params[] = "%$search%"; $al3_params[] = "%$search%";
    }
    $al3_stmt = $pdo->prepare("
        SELECT
            al3.id,
            al3.created_at                                              AS logged_at,
            al3.user_id,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                     u.username, CONCAT('User #',al3.user_id))          AS actor_name,
            COALESCE(u.role,'staff')                                    AS actor_role,
            al3.action                                                  AS action_type,
            CASE
                WHEN LOWER(al3.action) LIKE '%fuel%'        THEN 'FUEL'
                WHEN LOWER(al3.action) LIKE '%transaction%'
                  OR LOWER(al3.action) LIKE '%merchandise%' THEN 'TRANSACTION'
                WHEN LOWER(al3.action) LIKE '%stock%'
                  OR LOWER(al3.action) LIKE '%inventory%'   THEN 'INVENTORY'
                WHEN LOWER(al3.action) LIKE '%job%'         THEN 'JOB_ORDER'
                WHEN LOWER(al3.action) LIKE '%user%'
                  OR LOWER(al3.action) LIKE '%login%'       THEN 'USER'
                ELSE 'SYSTEM'
            END                                                         AS log_type,
            ''                                                          AS entity_type,
            NULL                                                        AS entity_id,
            COALESCE(al3.details,'')                                    AS details,
            'SUCCESS'                                                   AS status,
            COALESCE(al3.ip_address,'')                                 AS ip_address,
            ''                                                          AS user_agent,
            'activity_logs'                                             AS _source
        FROM activity_logs al3
        LEFT JOIN users u ON al3.user_id = u.id
        $al3_where
        ORDER BY al3.created_at DESC
        LIMIT 300
    ");
    $al3_stmt->execute($al3_params);
    $al3_rows = $al3_stmt->fetchAll(PDO::FETCH_ASSOC);
    $audit_rows = array_merge($audit_rows, $al3_rows);
    usort($audit_rows, fn($a,$b) => strtotime($b['logged_at']) - strtotime($a['logged_at']));
    $audit_rows = array_slice($audit_rows, 0, 1000);
} catch (Exception $e) { error_log("admin_audit_trail al3 error: " . $e->getMessage()); }

// ── Anomaly detection — look for suspicious patterns ─────────────────────────
$anomalies = [];
$ip_counts = [];
$user_action_counts = [];
foreach ($audit_rows as $r) {
    $ip = $r['ip_address'] ?? '';
    if ($ip) $ip_counts[$ip] = ($ip_counts[$ip] ?? 0) + 1;
    $key = ($r['user_id'] ?? 0) . '|' . date('Y-m-d H', strtotime($r['logged_at']));
    $user_action_counts[$key] = ($user_action_counts[$key] ?? 0) + 1;
}
// Flag IPs with > 50 actions in the period
foreach ($ip_counts as $ip => $cnt) {
    if ($cnt > 50) $anomalies[] = ['type'=>'High frequency', 'detail'=>"IP {$ip} logged {$cnt} actions in the period."];
}
// Flag users doing > 30 actions in one hour
foreach ($user_action_counts as $key => $cnt) {
    if ($cnt > 30) {
        [$uid, $hr] = explode('|', $key);
        $anomalies[] = ['type'=>'Burst activity', 'detail'=>"User ID {$uid} logged {$cnt} actions in 1 hour ({$hr})."];
    }
}

// ── Summary counts ────────────────────────────────────────────────────────────
$total   = count($audit_rows);
$by_role = [];
foreach ($audit_rows as $r) {
    $rl = strtolower($r['actor_role'] ?? 'unknown');
    $by_role[$rl] = ($by_role[$rl] ?? 0) + 1;
}

// ── Helper: log export action to audit_logs ─────────────────────────────────
function aat_log_export(PDO $pdo, int $station_id, int $user_id, string $fmt, int $row_count): void {
    try {
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, station_id, action_type, entity_type, entity_id, action_details, status, log_type, created_at)
            VALUES (?, ?, 'EXPORT', 'audit_trail', NULL, ?, 'SUCCESS', 'SYSTEM', NOW())
        ")->execute([$user_id, $station_id,
            strtoupper($fmt) . ' export of audit trail — ' . $row_count . ' records (' .
            (isset($_GET['date_from']) ? $_GET['date_from'] : 'all') . ' to ' .
            (isset($_GET['date_to'])   ? $_GET['date_to']   : 'all') . ')'
        ]);
    } catch (Exception $e) { /* non-fatal */ }
}

// ── CSV export ────────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    aat_log_export($pdo, $station_id, (int)$me['id'], 'CSV', count($audit_rows));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="admin_audit_trail_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Log ID','Source','Timestamp','User ID','Actor','Role','Action','Log Type','Entity Type','Entity ID','Details','Status','IP Address']);
    foreach ($audit_rows as $r) {
        fputcsv($out, [
            $r['id'], $r['_source'],
            date('M d Y H:i:s', strtotime($r['logged_at'])),
            $r['user_id'], $r['actor_name'], $r['actor_role'],
            $r['action_type'], $r['log_type'],
            $r['entity_type'], $r['entity_id'],
            $r['details'], $r['status'], $r['ip_address']
        ]);
    }
    fclose($out); exit;
}

// ── Excel export ──────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'excel') {
    aat_log_export($pdo, $station_id, (int)$me['id'], 'Excel', count($audit_rows));
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="admin_audit_trail_' . date('Y-m-d') . '.xls"');
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8">';
    echo '<style>table{border-collapse:collapse;}th,td{border:1px solid #ccc;padding:6px 8px;font-size:11px;}';
    echo 'th{background:#002F70;color:#fff;font-weight:bold;}</style></head><body>';
    echo '<h2>Admin Audit Trail — ' . htmlspecialchars(date('F d, Y')) . '</h2>';
    echo '<p>Station #' . $station_id . ' &nbsp;|&nbsp; Exported by: ' . htmlspecialchars($me['name'] ?? $me['username']) . ' &nbsp;|&nbsp; Generated: ' . date('Y-m-d H:i:s') . '</p>';
    echo '<table><thead><tr>';
    foreach (['Log ID','Source','Timestamp','User ID','Actor','Role','Action','Log Type','Module','Ref ID','Details','Status','IP Address'] as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($audit_rows as $r) {
        echo '<tr>';
        $cells = [
            $r['id'], $r['_source'],
            date('M d Y H:i:s', strtotime($r['logged_at'])),
            $r['user_id'], $r['actor_name'], $r['actor_role'],
            $r['action_type'], $r['log_type'],
            $r['entity_type'], $r['entity_id'],
            $r['details'], $r['status'], $r['ip_address']
        ];
        foreach ($cells as $c) {
            echo '<td>' . htmlspecialchars((string)$c) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

// ── PDF export ────────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'pdf') {
    aat_log_export($pdo, $station_id, (int)$me['id'], 'PDF', count($audit_rows));
    header('Content-Type: text/html; charset=utf-8');
    $generated  = date('F d, Y  h:i A');
    $actor_name = htmlspecialchars($me['name'] ?? ($me['username'] ?? 'Admin'));
    echo '<!DOCTYPE html><html lang="en"><head>';
    echo '<meta charset="UTF-8">';
    echo '<title>Admin Audit Trail | Petron Station Management</title>';
    echo '<style>';
    echo 'body{font-family:Arial,Helvetica,sans-serif;font-size:11px;margin:0;padding:0;background:#f1f5f9;color:#1e293b;}';
    echo '.action-bar{background:#002F70;padding:10px 20px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:999;}';
    echo '.action-bar h2{color:#fff;font-size:14px;margin:0;flex:1;}';
    echo '.btn-print{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:#DC0032;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;}';
    echo '.btn-back{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.35);border-radius:6px;font-size:12px;cursor:pointer;text-decoration:none;}';
    echo '.report{background:#fff;max-width:1200px;margin:18px auto;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.12);}';
    echo '.rpt-header{background:linear-gradient(135deg,#002F70,#003d8a);padding:20px 24px;display:flex;align-items:center;gap:16px;}';
    echo '.rpt-header img{height:48px;width:auto;object-fit:contain;}';
    echo '.rpt-header-text h1{color:#fff;font-size:16px;font-weight:800;margin:0 0 3px;}';
    echo '.rpt-header-text p{color:#93c5fd;font-size:10px;margin:0;}';
    echo '.rpt-header-meta{margin-left:auto;text-align:right;color:#bfdbfe;font-size:10px;line-height:1.8;}';
    echo '.rpt-header-meta strong{color:#fff;}';
    echo '.rpt-body{padding:16px;}';
    echo 'table{width:100%;border-collapse:collapse;font-size:10px;}';
    echo 'thead tr{background:#002F70;}';
    echo 'th{padding:7px 5px;color:#fff;font-weight:700;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}';
    echo 'td{padding:6px 5px;border-bottom:1px solid #e2e8f0;vertical-align:top;word-break:break-word;max-width:200px;}';
    echo 'tr:nth-child(even) td{background:#f8fafc;}';
    echo '.rpt-footer{padding:12px 24px;background:#f8fafc;border-top:2px solid #e2e8f0;font-size:9px;color:#64748b;text-align:center;}';
    echo '@media print{';
    echo '  .action-bar{display:none!important;}';
    echo '  body{background:#fff;}';
    echo '  .report{box-shadow:none;border-radius:0;margin:0;max-width:100%;}';
    echo '  table{font-size:8px;}';
    echo '}';
    echo '</style></head><body>';
    echo '<div class="action-bar">';
    echo '  <h2>Admin Audit Trail — Compliance Log</h2>';
    echo '  <a href="javascript:window.print()" class="btn-print">Print</a>';
    echo '  <a href="javascript:void(0)" onclick="window.history.length>1?window.history.back():window.close()" class="btn-back">Back</a>';
    echo '</div>';
    echo '<div class="report">';
    echo '<div class="rpt-header">';
    echo '  <img src="../assets/img/Petron%20Logo.png" alt="Petron Logo">';
    echo '  <div class="rpt-header-text">';
    echo '    <h1>Petron Station Management System</h1>';
    echo '    <p>Admin Audit Trail &mdash; Full Compliance Log</p>';
    echo '  </div>';
    echo '  <div class="rpt-header-meta">';
    echo '    <div><strong>Generated:</strong> ' . $generated . '</div>';
    echo '    <div><strong>Exported by:</strong> ' . $actor_name . '</div>';
    echo '    <div><strong>Period:</strong> ' . htmlspecialchars($date_from) . ' &ndash; ' . htmlspecialchars($date_to) . '</div>';
    echo '    <div><strong>Total Records:</strong> ' . count($audit_rows) . '</div>';
    echo '  </div>';
    echo '</div>';
    echo '<div class="rpt-body">';
    echo '<table><thead><tr>';
    foreach (['ID','Source','Timestamp','Actor','Role','Action','Log Type','Module','Ref','Details','Status','IP'] as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($audit_rows as $r) {
        $rl     = strtolower($r['actor_role'] ?? '');
        $bg_role = str_contains($rl,'staff') || str_contains($rl,'cashier') || str_contains($rl,'pump') ? '#dcfce7'
                 : (str_contains($rl,'manager') ? '#dbeafe'
                 : (str_contains($rl,'admin')    ? '#fee2e2' : '#f1f5f9'));
        $fg_role = str_contains($rl,'staff') || str_contains($rl,'cashier') || str_contains($rl,'pump') ? '#166534'
                 : (str_contains($rl,'manager') ? '#1d4ed8'
                 : (str_contains($rl,'admin')    ? '#991b1b' : '#475569'));
        echo '<tr>';
        echo '<td style="font-family:monospace;color:#94a3b8;font-size:9px;">' . htmlspecialchars((string)$r['id']) . '</td>';
        echo '<td style="font-size:9px;color:#64748b;">' . htmlspecialchars($r['_source']) . '</td>';
        echo '<td style="white-space:nowrap;font-size:9px;">' . date('M j Y', strtotime($r['logged_at'])) . '<br><span style="color:#64748b;">' . date('H:i:s', strtotime($r['logged_at'])) . '</span></td>';
        echo '<td style="font-weight:600;">' . htmlspecialchars(mb_strimwidth($r['actor_name'],0,20,'…')) . '</td>';
        echo '<td><span style="font-size:9px;font-weight:700;background:' . $bg_role . ';color:' . $fg_role . ';padding:1px 5px;border-radius:8px;">' . htmlspecialchars(str_replace('_',' ',ucfirst($r['actor_role']??'?'))) . '</span></td>';
        echo '<td style="font-size:9px;font-weight:700;">' . htmlspecialchars($r['action_type']??'—') . '</td>';
        echo '<td style="font-size:9px;font-weight:600;color:#002F70;">' . htmlspecialchars($r['log_type']??'—') . '</td>';
        echo '<td style="font-size:9px;color:#64748b;">' . htmlspecialchars(mb_strimwidth($r['entity_type'],0,16,'…')) . '</td>';
        echo '<td style="font-family:monospace;font-size:9px;color:#002F70;">' . htmlspecialchars($r['entity_id']??'—') . '</td>';
        echo '<td>' . htmlspecialchars(mb_strimwidth($r['details'],0,60,'…')) . '</td>';
        echo '<td style="font-weight:700;color:' . ($r['status']==='SUCCESS'?'#166534':'#991b1b') . ';font-size:9px;">' . htmlspecialchars($r['status']??'—') . '</td>';
        echo '<td style="font-family:monospace;font-size:9px;color:#64748b;">' . htmlspecialchars($r['ip_address']??'—') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    echo '<div class="rpt-footer">&copy; ' . date('Y') . ' Petron Station &amp; Service Center Management System. All Rights Reserved.</div>';
    echo '</div></body></html>';
    exit;
}

// Distinct values for filter dropdowns
$distinct_actions = array_unique(array_column($audit_rows, 'action_type'));
sort($distinct_actions);
$distinct_log_types = array_unique(array_column($audit_rows, 'log_type'));
sort($distinct_log_types);

// ── AJAX JSON POLLING ENDPOINT FOR ADMIN AUDIT TRAIL ───────────────────────────
if (isset($_GET['ajax_aat']) && $_GET['ajax_aat'] == '1') {
    header('Content-Type: application/json');
    $count = count($audit_rows ?? []);
    $firstRows = array_slice($audit_rows ?? [], 0, 30);
    $signature = md5(json_encode($firstRows) . '_' . $count);
    echo json_encode([
        'success'   => true,
        'count'     => $count,
        'signature' => $signature
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.aat-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:14px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.aat-head{background:#002F70;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.aat-head h3{color:#fff;font-size:13px;font-weight:700;margin:0;display:flex;align-items:center;gap:7px;}
.aat-body{padding:14px 18px;}
.aat-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px;}
.aat-kpi-card{background:#fff;border-radius:8px;border:1px solid #e2e8f0;padding:12px 14px;text-align:center;}
.aat-kpi-num{font-size:22px;font-weight:800;color:#002F70;}
.aat-kpi-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-top:3px;}
.aat-filter-row{display:flex;flex-wrap:wrap;gap:9px;align-items:flex-end;}
.aat-fg{display:flex;flex-direction:column;gap:3px;}
.aat-fl{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;}
.aat-inp{height:34px;padding:0 9px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;color:#1e293b;background:#fff;outline:none;}
.aat-inp:focus{border-color:#002F70;}
.aat-btn{display:inline-flex;align-items:center;gap:5px;height:34px;padding:0 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;background:#fff;text-decoration:none;}
.aat-btn-blue{color:#002F70;border-color:#002F70;}.aat-btn-blue:hover{background:#002F70;color:#fff;}
.aat-btn-gray{color:#4b5563;border-color:#6b7280;}.aat-btn-gray:hover{background:#6b7280;color:#fff;}
.aat-btn-green{color:#16a34a;border-color:#16a34a;}.aat-btn-green:hover{background:#16a34a;color:#fff;}
.aat-btn-red{color:#dc2626;border-color:#dc2626;}.aat-btn-red:hover{background:#dc2626;color:#fff;}
.aat-table{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed;}
.aat-table thead th{background:#002F70;color:#fff;padding:8px 5px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;border-bottom:2px solid #001a3d;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:left;}
.aat-table tbody td{padding:7px 5px;border-bottom:1px solid #f1f5f9;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;max-width:0;color:#1e293b;}
.aat-table tbody tr:hover td{background:#f0f7ff;}
.aat-badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;}
.aat-role-staff{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.aat-role-manager{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;}
.aat-role-admin{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.aat-role-other{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
.aat-anomaly-banner{background:#fff3f3;border:1.5px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:14px;}
</style>

<div class="page-head" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
    <div>
        <h1 class="h1" style="margin:0;"><i class="fas fa-shield-alt"></i> Admin Audit Trail</h1>
        <div class="sub">Full compliance log — all roles, all actions, all modules at this station.</div>
    </div>
    <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;">
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'excel'])) ?>"
           class="aat-btn aat-btn-green" title="Export to Excel">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>"
           class="aat-btn aat-btn-blue" title="Export to CSV">
            <i class="fas fa-file-csv"></i> CSV
        </a>
        <button type="button" onclick="exportPrintableAreaToPDF('.aat-table','Admin Audit Trail','admin_audit_trail_<?= htmlspecialchars($date_from) ?>_to_<?= htmlspecialchars($date_to) ?>',this)"
           class="aat-btn aat-btn-red" title="Export PDF">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <button type="button" onclick="printReportArea()" class="aat-btn aat-btn-gray" title="Print">
            <i class="fas fa-print"></i> Print
        </button>
        <a href="admin_dashboard.php" class="aat-btn aat-btn-gray"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- KPI strip -->
<div class="aat-kpi">
    <div class="aat-kpi-card"><div class="aat-kpi-num"><?= $total ?></div><div class="aat-kpi-lbl">Total Entries</div></div>
    <?php foreach (['staff'=>'#16a34a','manager'=>'#1d4ed8','admin'=>'#dc2626','cashier'=>'#d97706','pump_attendant'=>'#7c3aed'] as $rl => $cl): ?>
    <?php if (isset($by_role[$rl])): ?>
    <div class="aat-kpi-card">
        <div class="aat-kpi-num" style="color:<?= $cl ?>;"><?= $by_role[$rl] ?></div>
        <div class="aat-kpi-lbl"><?= ucfirst(str_replace('_',' ',$rl)) ?></div>
    </div>
    <?php endif; endforeach; ?>
    <?php if (!empty($anomalies)): ?>
    <div class="aat-kpi-card" style="border-color:#fca5a5;background:#fff3f3;">
        <div class="aat-kpi-num" style="color:#dc2626;"><?= count($anomalies) ?></div>
        <div class="aat-kpi-lbl">Anomalies</div>
    </div>
    <?php endif; ?>
</div>

<!-- Anomaly banner -->
<?php if (!empty($anomalies)): ?>
<div class="aat-anomaly-banner">
    <div style="font-weight:700;color:#dc2626;margin-bottom:8px;font-size:13px;">
        <i class="fas fa-exclamation-triangle"></i> <?= count($anomalies) ?> Anomaly Flag<?= count($anomalies)>1?'s':'' ?> Detected
    </div>
    <?php foreach ($anomalies as $an): ?>
    <div style="font-size:12px;padding:4px 0;border-bottom:1px solid #fecaca;color:#7f1d1d;">
        <span style="padding:1px 6px;background:#fee2e2;color:#dc2626;border-radius:4px;font-size:10px;font-weight:700;margin-right:6px;"><?= htmlspecialchars($an['type']) ?></span>
        <?= htmlspecialchars($an['detail']) ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="aat-card">
    <div class="aat-head"><h3><i class="fas fa-filter"></i> Filters</h3></div>
    <div class="aat-body">
        <form method="get" class="aat-filter-row">
            <div class="aat-fg"><label class="aat-fl">Date From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="aat-inp" max="<?= date('Y-m-d') ?>"></div>
            <div class="aat-fg"><label class="aat-fl">Date To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="aat-inp" max="<?= date('Y-m-d') ?>"></div>
            <div class="aat-fg"><label class="aat-fl">Role</label>
                <select name="role_f" class="aat-inp" style="width:130px;">
                    <option value="">All Roles</option>
                    <?php foreach (['staff','cashier','pump_attendant','manager','admin','superadmin'] as $rl): ?>
                    <option value="<?= $rl ?>" <?= $role_f===$rl?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$rl)) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="aat-fg"><label class="aat-fl">User</label>
                <select name="user_f" class="aat-inp" style="width:140px;">
                    <option value="0">All Users</option>
                    <?php foreach ($all_users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $user_f===(int)$u['id']?'selected':'' ?>>
                        <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['role']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select></div>
            <div class="aat-fg"><label class="aat-fl">Action</label>
                <select name="action_f" class="aat-inp" style="width:140px;">
                    <option value="">All Actions</option>
                    <?php foreach ($distinct_actions as $a): ?>
                    <option value="<?= htmlspecialchars($a) ?>" <?= $action_f===$a?'selected':'' ?>><?= htmlspecialchars($a) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="aat-fg"><label class="aat-fl">Log Type</label>
                <select name="log_type_f" class="aat-inp" style="width:130px;">
                    <option value="">All Types</option>
                    <?php foreach ($distinct_log_types as $lt): ?>
                    <option value="<?= htmlspecialchars($lt) ?>" <?= $log_type_f===$lt?'selected':'' ?>><?= htmlspecialchars($lt) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="aat-fg"><label class="aat-fl">Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="aat-inp" placeholder="Details, entity ID, user…" style="width:180px;"></div>
            <div class="aat-fg"><label class="aat-fl">&nbsp;</label>
                <div style="display:flex;gap:6px;">
                    <button type="submit" class="aat-btn aat-btn-blue"><i class="fas fa-search"></i> Search</button>
                    <a href="admin_audit_trail.php" class="aat-btn aat-btn-gray"><i class="fas fa-rotate-left"></i> Reset</a>
                </div></div>
        </form>
    </div>
</div>

<!-- Full Audit Table -->
<div class="aat-card">
    <div class="aat-head">
        <h3><i class="fas fa-list-alt"></i> Full Compliance Log (<?= $total ?> record<?= $total!==1?'s':'' ?>)</h3>
    </div>
    <div style="overflow:hidden;max-height:600px;overflow-y:auto;">
    <table class="aat-table">
        <colgroup>
            <col style="width:4%;">  <!-- ID -->
            <col style="width:9%;">  <!-- Timestamp -->
            <col style="width:7%;">  <!-- Actor -->
            <col style="width:7%;">  <!-- Role -->
            <col style="width:8%;">  <!-- Action -->
            <col style="width:7%;">  <!-- Log Type -->
            <col style="width:7%;">  <!-- Entity Type -->
            <col style="width:5%;">  <!-- Entity ID -->
            <col style="width:21%;">  <!-- Details -->
            <col style="width:5%;">  <!-- Status -->
            <col style="width:7%;">  <!-- IP Address -->
            <col style="width:4%;">  <!-- Source -->
        </colgroup>
        <thead>
            <tr>
                <th>ID</th><th>Timestamp</th><th>Actor</th><th>Role</th>
                <th>Action</th><th>Log Type</th><th>Module</th><th>Ref</th>
                <th>Details</th><th>Status</th><th>IP / Device</th><th>Src</th>
            </tr>
        </thead>
        <tbody id="aatTableBody">
        <?php if (empty($audit_rows)): ?>
        <tr><td colspan="12" style="text-align:center;padding:40px;color:#94a3b8;">
            <i class="fas fa-clipboard-list" style="font-size:24px;display:block;margin-bottom:8px;opacity:.3;"></i>
            No audit records found for the selected filters.
        </td></tr>
        <?php else: foreach ($audit_rows as $r):
            $rl = strtolower($r['actor_role'] ?? '');
            if (str_contains($rl,'staff') || str_contains($rl,'cashier') || str_contains($rl,'pump'))
                $rc = 'aat-role-staff';
            elseif (str_contains($rl,'manager') || str_contains($rl,'supervisor'))
                $rc = 'aat-role-manager';
            elseif (str_contains($rl,'admin'))
                $rc = 'aat-role-admin';
            else
                $rc = 'aat-role-other';

            $act_lower = strtolower($r['action_type']??'');
            if (str_contains($act_lower,'approv') || str_contains($act_lower,'create'))
                $act_color = '#166534';
            elseif (str_contains($act_lower,'reject') || str_contains($act_lower,'return') || str_contains($act_lower,'delete'))
                $act_color = '#991b1b';
            elseif (str_contains($act_lower,'adjust') || str_contains($act_lower,'update'))
                $act_color = '#1d4ed8';
            else
                $act_color = '#475569';

            $lt_colors = ['TRANSACTION'=>'#002F70','INVENTORY'=>'#b45309','JOB_ORDER'=>'#7c3aed','PAYMENT'=>'#0369a1','SYSTEM'=>'#64748b'];
            $lt_c = $lt_colors[$r['log_type'] ?? 'SYSTEM'] ?? '#64748b';
        ?>
        <tr class="aat-row"
            data-search="<?= strtolower(htmlspecialchars(($r['actor_name']??'').' '.($r['action_type']??'').' '.($r['entity_type']??'').' '.($r['entity_id']??'').' '.($r['details']??''))) ?>">
            <td style="font-size:10px;color:#94a3b8;font-family:monospace;"><?= $r['id'] ?></td>
            <td style="font-size:11px;color:#64748b;white-space:nowrap;">
                <?= date('M j, Y', strtotime($r['logged_at'])) ?><br>
                <span style="font-size:10px;"><?= date('H:i:s', strtotime($r['logged_at'])) ?></span>
            </td>
            <td style="font-weight:600;" title="<?= htmlspecialchars($r['actor_name']) ?>">
                <?= htmlspecialchars(mb_strimwidth($r['actor_name'],0,14,'…')) ?>
            </td>
            <td><span class="aat-badge <?= $rc ?>"><?= htmlspecialchars(str_replace('_',' ',ucfirst($r['actor_role']??'?'))) ?></span></td>
            <td><span style="font-size:10px;font-weight:700;color:<?= $act_color ?>;"><?= htmlspecialchars($r['action_type']??'—') ?></span></td>
            <td><span style="font-size:10px;font-weight:700;color:<?= $lt_c ?>;"><?= htmlspecialchars($r['log_type']??'—') ?></span></td>
            <td style="font-size:10px;color:#64748b;" title="<?= htmlspecialchars($r['entity_type']) ?>"><?= htmlspecialchars(mb_strimwidth($r['entity_type'],0,14,'…')) ?></td>
            <td style="font-family:monospace;font-size:11px;font-weight:700;color:#002F70;">
                <?= htmlspecialchars($r['entity_id']??'—') ?>
            </td>
            <td style="font-size:11px;color:#475569;" title="<?= htmlspecialchars($r['details']) ?>">
                <?= htmlspecialchars(mb_strimwidth($r['details'],0,60,'…')) ?: '—' ?>
            </td>
            <td><span style="font-size:10px;color:<?= ($r['status']==='SUCCESS'?'#166534':'#991b1b') ?>;font-weight:700;"><?= htmlspecialchars($r['status']??'—') ?></span></td>
            <td style="font-size:10px;color:#64748b;font-family:monospace;" title="<?= htmlspecialchars($r['user_agent']??'') ?>">
                <?= htmlspecialchars($r['ip_address']??'—') ?>
            </td>
            <td style="font-size:10px;color:#94a3b8;"><?= $r['_source']==='audit_trail'?'AT':'AL' ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    <!-- Pagination -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-top:1px solid #e2e8f0;flex-wrap:wrap;gap:8px;">
        <div style="font-size:12px;color:#64748b;">Showing <span id="aatShowing">—</span> of <?= $total ?> entries</div>
        <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:11px;color:#64748b;">Per page:</label>
            <select id="aatPerPage" onchange="aatRender(1)" style="padding:4px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;background:#fff;cursor:pointer;">
                <option value="25">25</option><option value="50">50</option>
                <option value="100">100</option><option value="200">All (slow)</option>
            </select>
            <button id="aatPrev" onclick="aatRender(aatPage-1)" style="width:28px;height:28px;background:#fff;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-chevron-left"></i></button>
            <span id="aatPageLbl" style="font-size:12px;color:#475569;white-space:nowrap;">Page 1 of 1</span>
            <button id="aatNext" onclick="aatRender(aatPage+1)" style="width:28px;height:28px;background:#fff;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>
<div style="height:60px;"></div>
<script>
var aatPage=1, aatRows=[], aatPerPage=25;
function aatAllRows(){ return Array.from(document.querySelectorAll('#aatTableBody .aat-row')); }
function aatRender(page){
    if(!aatRows.length) aatRows=aatAllRows();
    var perPage=parseInt(document.getElementById('aatPerPage')?.value||25);
    var total=aatRows.length, pages=Math.max(1,Math.ceil(total/perPage));
    page=Math.max(1,Math.min(page,pages)); aatPage=page;
    var s=(page-1)*perPage, e=Math.min(s+perPage,total);
    aatAllRows().forEach(function(r){r.style.display='none';});
    aatRows.slice(s,e).forEach(function(r){r.style.display='';});
    var sh=document.getElementById('aatShowing'); if(sh) sh.textContent=total===0?'0':(s+1)+'-'+e;
    var lbl=document.getElementById('aatPageLbl'); if(lbl) lbl.textContent='Page '+page+' of '+pages;
    var pv=document.getElementById('aatPrev'), nx=document.getElementById('aatNext');
    if(pv){pv.disabled=page<=1;pv.style.opacity=page<=1?'.4':'1';}
    if(nx){nx.disabled=page>=pages;nx.style.opacity=page>=pages?'.4':'1';}
}
document.addEventListener('DOMContentLoaded',function(){ aatRows=aatAllRows(); aatRender(1); });

// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
let lastAatSignature = null;
let lastAatCount = null;

function autoRefreshAdminAuditTrail() {
    const openModal = Array.from(document.querySelectorAll('.modal, .modal-overlay, [id*="Modal"]')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    if (openModal) return;

    if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA' || document.activeElement.tagName === 'SELECT')) {
        return;
    }

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_aat', '1');

    fetch(currentUrl.toString(), { cache: 'no-store', credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                if (lastAatSignature !== null && (lastAatSignature !== data.signature || lastAatCount !== data.count)) {
                    window.location.reload();
                }
                lastAatSignature = data.signature;
                lastAatCount = data.count;
            }
        })
        .catch(() => {});
}

setInterval(autoRefreshAdminAuditTrail, 15000);
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
