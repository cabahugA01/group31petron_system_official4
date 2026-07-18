<?php
/**
 * Admin Compliance Reports
 * Activity Logs | Audit Trail | Calendar & Schedule
 * Same design as admin_finance_reports.php
 */

$page_id = 'admin_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) die('Access denied.');

$section   = in_array($_GET['section'] ?? '', ['activity','audit','calendar']) ? $_GET['section'] : 'activity';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

$station_name = '';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $station_name = $s->fetchColumn() ?: '';
} catch (Exception $e) {}

// ── DATA FETCH ────────────────────────────────────────────────────────────────

function crAdminTrackerServiceWhere(string $alias = 'mt'): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "(
        LOWER(COALESCE({$p}transaction_type, '')) IN ('job_order', 'combined', 'service')
        OR ({$p}job_order_service IS NOT NULL AND TRIM({$p}job_order_service) <> '')
        OR {$p}job_order_id IS NOT NULL
        OR {$p}job_order_db_id IS NOT NULL
    )";
}

// ACTIVITY LOGS — from audit_logs (API-level) + activity_logs (lib-level log_activity calls)
$activity_rows = [];
try {
    // Source A: audit_logs (richer — has entity_type, log_type, action_details)
    $q = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                     u.username, CONCAT('User #', al.user_id))  AS staff_name,
            al.action_type                                        AS action_type,
            al.created_at                                         AS timestamp,
            UPPER(COALESCE(al.log_type,'SYSTEM'))                 AS module_affected,
            COALESCE(al.action_details,'')                        AS remarks
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE u.station_id = ?
          AND DATE(al.created_at) BETWEEN ? AND ?
        ORDER BY al.created_at DESC
        LIMIT 400
    ");
    $q->execute([$station_id, $date_from, $date_to]);
    $activity_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $activity_rows = []; }

// Source B: activity_logs (from log_activity() calls in lib.php)
try {
    $q2 = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                     u.username, CONCAT('User #', al.user_id))  AS staff_name,
            al.action                                             AS action_type,
            al.created_at                                         AS timestamp,
            CASE
                WHEN LOWER(al.action) LIKE '%fuel%'        THEN 'FUEL'
                WHEN LOWER(al.action) LIKE '%transaction%'
                  OR LOWER(al.action) LIKE '%merchandise%' THEN 'TRANSACTION'
                WHEN LOWER(al.action) LIKE '%inventory%'
                  OR LOWER(al.action) LIKE '%stock%'       THEN 'INVENTORY'
                WHEN LOWER(al.action) LIKE '%job%'         THEN 'JOB_ORDER'
                WHEN LOWER(al.action) LIKE '%user%'
                  OR LOWER(al.action) LIKE '%login%'       THEN 'USER'
                ELSE 'SYSTEM'
            END                                                   AS module_affected,
            COALESCE(al.details,'')                               AS remarks
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE u.station_id = ?
          AND DATE(al.created_at) BETWEEN ? AND ?
        ORDER BY al.created_at DESC
        LIMIT 300
    ");
    $q2->execute([$station_id, $date_from, $date_to]);
    $al_rows = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $activity_rows = array_merge($activity_rows, $al_rows);
    usort($activity_rows, fn($a,$b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));
    $activity_rows = array_slice($activity_rows, 0, 600);
} catch (Exception $e) { /* keep source A results */ }

// AUDIT TRAIL — fetch from ALL audit sources: audit_logs + audit_trail + merchandise_transaction_audit
$audit_rows = [];
try {
    // Source 1: audit_logs (richest — staff create, manager validate, admin actions)
    $q = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                     u.username, CONCAT('User #', al.user_id))  AS staff_name,
            al.action_type                                        AS action_performed,
            al.created_at                                         AS timestamp,
            CASE
                WHEN TIME(al.created_at) >= '06:00:00' AND TIME(al.created_at) < '14:00:00' THEN 'Shift 1 (6AM–2PM)'
                ELSE 'Shift 2 (2PM–12AM)'
            END                                                   AS shift_assignment,
            CONCAT(UPPER(COALESCE(al.entity_type,'system')),
                   CASE WHEN al.entity_id IS NOT NULL THEN CONCAT(' #', al.entity_id) ELSE '' END)
                                                                  AS system_reference,
            UPPER(COALESCE(al.log_type,'SYSTEM'))                 AS module_affected
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE u.station_id = ?
          AND DATE(al.created_at) BETWEEN ? AND ?
        ORDER BY al.created_at DESC
        LIMIT 400
    ");
    $q->execute([$station_id, $date_from, $date_to]);
    $audit_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $audit_rows = []; }

// Source 2: audit_trail (transaction-level manager actions)
try {
    $at_src = '';
    try { $at_src = $pdo->query("SHOW COLUMNS FROM audit_trail LIKE 'source_table'")->rowCount() ? 'COALESCE(at2.source_table,\'TXN\')' : "'TXN'"; } catch(Exception $e){ $at_src = "'TXN'"; }
    $q2 = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                     u.username, CONCAT('User #', at2.manager_id))  AS staff_name,
            at2.action_type                                           AS action_performed,
            at2.timestamp                                             AS timestamp,
            CASE
                WHEN TIME(at2.timestamp) >= '06:00:00' AND TIME(at2.timestamp) < '14:00:00' THEN 'Shift 1 (6AM–2PM)'
                ELSE 'Shift 2 (2PM–12AM)'
            END                                                       AS shift_assignment,
            CONCAT($at_src,' #', at2.transaction_id)                 AS system_reference,
            'TRANSACTION'                                             AS module_affected
        FROM audit_trail at2
        LEFT JOIN users u ON u.id = at2.manager_id
        WHERE at2.station_id = ?
          AND DATE(at2.timestamp) BETWEEN ? AND ?
        ORDER BY at2.timestamp DESC
        LIMIT 300
    ");
    $q2->execute([$station_id, $date_from, $date_to]);
    $at_rows = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $audit_rows = array_merge($audit_rows, $at_rows);
    usort($audit_rows, fn($a,$b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));
    $audit_rows = array_slice($audit_rows, 0, 600);
} catch (Exception $e) { /* source 2 failed — keep source 1 results */ }

// CALENDAR & SCHEDULE — Job Orders + Deliveries
$calendar_tasks = [];
try {
    // Job Orders
    $q = $pdo->prepare("
        SELECT
            'Job Order' AS task_type,
            COALESCE(jo.job_order_id, COALESCE(jo.job_order_number, CONCAT('JO-', jo.id))) AS task_ref,
            COALESCE(jo.customer_name, '—') AS assigned_to,
            jo.created_at AS scheduled_date,
            COALESCE(jo.status, 'Pending') AS status,
            COALESCE(jo.service_type, '—') AS description
        FROM job_orders jo
        WHERE jo.station_id = ?
          AND DATE(jo.created_at) BETWEEN ? AND ?
    ");
    $q->execute([$station_id, $date_from, $date_to]);
    $jo_tasks = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Service tracker rows that are stored through merchandise_transactions.
    $mt_service_where = crAdminTrackerServiceWhere('mt');
    $qTracker = $pdo->prepare("
        SELECT
            'Job Order' AS task_type,
            COALESCE(mt.job_order_id, mt.transaction_id, CONCAT('MT-', mt.id)) AS task_ref,
            COALESCE(
                NULLIF(TRIM(mt.customer_name), ''),
                NULLIF(TRIM(CONCAT(COALESCE(mt.customer_first_name,''), ' ', COALESCE(mt.customer_last_name,''))), ''),
                'Walk-in'
            ) AS assigned_to,
            COALESCE(mt.transaction_date, mt.created_at) AS scheduled_date,
            COALESCE(NULLIF(mt.workflow_status, ''), NULLIF(mt.validation_status, ''), 'Pending') AS status,
            COALESCE(NULLIF(mt.job_order_service, ''), NULLIF(mt.job_order_description, ''), 'Service') AS description
        FROM merchandise_transactions mt
        WHERE mt.station_id = ?
          AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
          AND {$mt_service_where}
          AND NOT EXISTS (
              SELECT 1
              FROM job_orders jo2
              WHERE jo2.station_id = mt.station_id
                AND (
                    (mt.job_order_db_id IS NOT NULL AND jo2.id = mt.job_order_db_id)
                    OR (mt.job_order_id IS NOT NULL AND mt.job_order_id <> ''
                        AND (jo2.job_order_id = mt.job_order_id OR jo2.job_order_number = mt.job_order_id))
                )
          )
    ");
    $qTracker->execute([$station_id, $date_from, $date_to]);
    $tracker_tasks = $qTracker->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Fuel Deliveries
    $q2 = $pdo->prepare("
        SELECT
            'Fuel Delivery' AS task_type,
            COALESCE(NULLIF(fd.invoice_no, ''), CONCAT('FD-', fd.id)) AS task_ref,
            COALESCE(fd.supplier, '—') AS assigned_to,
            COALESCE(fd.delivery_date, fd.created_at) AS scheduled_date,
            COALESCE(fd.status, 'Pending') AS status,
            COALESCE(fd.fuel_type, '—') AS description
        FROM fuel_deliveries fd
        WHERE fd.station_id = ?
          AND DATE(COALESCE(fd.delivery_date, fd.created_at)) BETWEEN ? AND ?
    ");
    $q2->execute([$station_id, $date_from, $date_to]);
    $fd_tasks = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Merge and sort by date
    $calendar_tasks = array_merge($jo_tasks, $tracker_tasks, $fd_tasks);
    usort($calendar_tasks, fn($a, $b) => strtotime($a['scheduled_date']) <=> strtotime($b['scheduled_date']));
} catch (Exception $e) { $calendar_tasks = []; }

// Group calendar tasks by date for calendar view
$calendar_by_date = [];
foreach ($calendar_tasks as $task) {
    $d = date('Y-m-d', strtotime($task['scheduled_date']));
    $calendar_by_date[$d][] = $task;
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* Same design as Finance Reports */
.rpt-wrapper{background:white;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;}
.cr-filter-bar{display:flex;align-items:center;gap:10px;padding:14px 18px;background:#f8f9fa;border-bottom:1px solid #e2e8f0;flex-wrap:wrap;}
.cr-filter-bar label{font-size:12px;font-weight:600;color:#00264D;margin:0;}
.cr-filter-bar input[type="date"]{padding:7px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;}
.cr-filter-bar button{padding:7px 16px;background:#ffffff;color:#00264D;border:1px solid #00264D;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;}
.cr-filter-bar button:hover{background:#00264D;color:#ffffff;}
.cr-export-btn{padding:7px 14px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;transition:all .2s;border:1px solid;display:inline-flex;align-items:center;gap:6px;background:#ffffff !important;}
.cr-export-btn:nth-child(1){color:#16a34a !important;border-color:#16a34a !important;}
.cr-export-btn:nth-child(1):hover{background:#f0fdf4 !important;border-color:#15803d !important;color:#16a34a !important;}
.cr-export-btn:nth-child(2){color:#1e40af !important;border-color:#1e40af !important;}
.cr-export-btn:nth-child(2):hover{background:#dbeafe !important;border-color:#1e3a8a !important;color:#1e40af !important;}
.cr-export-btn:nth-child(3){color:#dc2626 !important;border-color:#dc2626 !important;}
.cr-export-btn:nth-child(3):hover{background:#fef2f2 !important;border-color:#b91c1c !important;color:#dc2626 !important;}
.cr-export-btn:nth-child(4){color:#334155 !important;border-color:#64748b !important;}
.cr-export-btn:nth-child(4):hover{background:#f8fafc !important;border-color:#334155 !important;color:#334155 !important;}
.cr-tabs{display:flex;border-bottom:2px solid #e2e8f0;overflow:hidden;}
.cr-tab{padding:13px 20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#64748b;background:#f8f9fa;border:none;border-bottom:3px solid transparent;cursor:pointer;white-space:nowrap;transition:all .2s;}
.cr-tab:hover{background:#fff;color:#00264D;}
.cr-tab.active{background:#fff;color:#00264D;border-bottom-color:#00264D;font-weight:800;}
.cr-content{padding:24px;}
.cr-panel{display:none;}
.cr-panel.active{display:block;}
/* Report header — same as finance */
.cr-rpt-header{text-align:center;padding:20px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:18px;}
.cr-rpt-header .rh-title{font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.cr-rpt-header .rh-sub{font-size:15px;font-weight:700;color:#00264D;text-transform:uppercase;margin-bottom:8px;}
.cr-rpt-header .rh-station{font-size:12px;color:#64748b;margin-bottom:2px;}
.cr-rpt-header .rh-date{font-size:12px;color:#334155;}
/* Sub heading */
.cr-sub-heading{font-size:13px;font-weight:700;color:#00264D;text-transform:uppercase;padding:8px 0 6px;border-bottom:1px solid #e2e8f0;margin:20px 0 12px;}
/* Table */
.cr-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.cr-tbl thead tr{border-top:2px solid #00264D;border-bottom:1px solid #e2e8f0;background:#f8f9fa;}
.cr-tbl thead th{padding:10px 8px;text-align:left;font-weight:700;color:#00264D;font-size:11px;text-transform:uppercase;}
.cr-tbl tbody tr{border-bottom:1px solid #f1f5f9;}
.cr-tbl tbody tr:hover{background:#f8fafc;}
.cr-tbl tbody td{padding:9px 8px;color:#334155;font-size:12px;}
.cr-tbl tfoot tr{border-top:2px solid #00264D;background:#f0f4ff;}
.cr-tbl tfoot td{padding:10px 8px;font-weight:700;color:#00264D;font-size:12px;}
.cr-empty{text-align:center;padding:28px;color:#94a3b8;font-size:13px;}
/* Action type badges */
.cr-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;}
.badge-login{background:#dbeafe;color:#1d4ed8;}
.badge-logout{background:#f1f5f9;color:#64748b;}
.badge-encode{background:#dcfce7;color:#16a34a;}
.badge-edit{background:#fef9c3;color:#854d0e;}
.badge-delete{background:#fee2e2;color:#dc2626;}
.badge-approve{background:#f0fdf4;color:#15803d;}
.badge-default{background:#e2e8f0;color:#475569;}
/* Calendar view */
.cr-calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:24px;}
.cr-cal-header{text-align:center;font-size:10px;font-weight:700;color:#00264D;text-transform:uppercase;padding:6px 0;background:#f0f4ff;border-radius:4px;}
.cr-cal-day{min-height:64px;padding:4px;border:1px solid #e2e8f0;border-radius:4px;background:white;font-size:10px;}
.cr-cal-day .day-num{font-weight:700;color:#00264D;margin-bottom:2px;font-size:11px;}
.cr-cal-day.empty{background:#f8f9fa;}
.cr-cal-day.today{border-color:#00264D;border-width:2px;}
.cr-task-dot{font-size:9px;padding:1px 4px;border-radius:3px;margin-bottom:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cr-task-jo{background:#dbeafe;color:#1d4ed8;}
.cr-task-fd{background:#dcfce7;color:#15803d;}
/* Status badges */
.cr-status{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;}
.status-pending{background:#fef9c3;color:#854d0e;}
.status-completed{background:#dcfce7;color:#15803d;}
.status-cancelled{background:#fee2e2;color:#dc2626;}
.status-progress{background:#dbeafe;color:#1d4ed8;}
@media print{
    @page{size:legal portrait;margin:.3in .4in;}
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}
    html,body{background:white !important;padding:0 !important;margin:0 !important;}
    body > *{display:none !important;}
    .rpt-printable{display:block !important;overflow:visible !important;}
    .cr-filter-bar,.cr-tabs,.cr-export-actions,.cr-calendar-grid{display:none !important;}
    .cr-panel{display:none !important;overflow:visible !important;}
    .cr-panel.active{display:block !important;}
    .cr-rpt-header,.cr-sub-heading{
        break-after:avoid !important;
        page-break-after:avoid !important;
    }
    .cr-tbl{
        width:100% !important;
        max-width:100% !important;
        border-collapse:collapse !important;
        table-layout:auto !important;
        font-size:9.5px !important;
        break-inside:auto !important;
        page-break-inside:auto !important;
    }
    .cr-tbl thead{display:table-header-group !important;}
    .cr-tbl tfoot{display:table-footer-group !important;}
    .cr-tbl tr{
        break-inside:avoid !important;
        page-break-inside:avoid !important;
    }
    .cr-tbl thead th{font-size:8.8px !important;padding:5px !important;}
    .cr-tbl tbody td,.cr-tbl tfoot td{
        font-size:9.5px !important;
        padding:5px !important;
        white-space:normal !important;
        word-break:break-word !important;
    }
    .cr-empty{
        break-inside:avoid !important;
        page-break-inside:avoid !important;
    }
}
</style>

<div class="rpt-wrapper">
    <!-- Filter Bar -->
    <form method="GET" class="cr-filter-bar">
        <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
        <label><i class="fas fa-calendar"></i> Report Date:</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
        <span style="color:#64748b;">to</span>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
        <button type="submit"><i class="fas fa-sync-alt"></i> Apply</button>
        <div style="display:flex;gap:6px;margin-left:auto;">
            <button type="button" class="cr-export-btn" onclick="crExport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
            <button type="button" class="cr-export-btn" onclick="crExport('csv')"><i class="fas fa-file-csv"></i> CSV</button>
            <button type="button" class="cr-export-btn" onclick="crExport('pdf')"><i class="fas fa-file-pdf"></i> Export PDF</button>
            <button type="button" class="cr-export-btn" onclick="crPrint()"><i class="fas fa-print"></i> Print</button>
        </div>
    </form>

    <!-- Tabs -->
    <div class="cr-tabs">
        <button class="cr-tab <?= $section==='activity'?'active':'' ?>" onclick="crTab('activity')"><i class="fas fa-history"></i> Activity Logs</button>
        <button class="cr-tab <?= $section==='audit'?'active':'' ?>"    onclick="crTab('audit')"><i class="fas fa-shield-alt"></i> Audit Trail</button>
        <button class="cr-tab <?= $section==='calendar'?'active':'' ?>" onclick="crTab('calendar')"><i class="fas fa-calendar-alt"></i> Calendar &amp; Schedule</button>
    </div>

    <div class="cr-content">
    <div class="rpt-printable">

    <!-- ACTIVITY LOGS -->
    <div id="cr-panel-activity" class="cr-panel <?= $section==='activity'?'active':'' ?>">
        <div class="cr-rpt-header">
            <div class="rh-title">ACTIVITY LOGS REPORT</div>
            <div class="rh-sub">DAILY STAFF & SYSTEM ACTIONS</div>
            <div class="rh-station"><?= htmlspecialchars($station_name) ?></div>
            <div class="rh-date"><strong>Date:</strong> <?= date('F j, Y', strtotime($date_from)) ?><?= $date_from!==$date_to?' – '.date('F j, Y', strtotime($date_to)):'' ?></div>
        </div>
        <table class="cr-tbl">
            <thead><tr>
                <th>#</th><th>Staff Name / Encoder</th><th>Action Type</th>
                <th>Timestamp</th><th>Module Affected</th><th>Remarks</th>
            </tr></thead>
            <tbody>
            <?php if (empty($activity_rows)): ?>
                <tr><td colspan="6" class="cr-empty">No activity log records for this period.</td></tr>
            <?php else: $i=0; foreach ($activity_rows as $r): $i++;
                $action = strtolower($r['action_type']);
                $badge_class = str_contains($action,'login') ? 'badge-login'
                    : (str_contains($action,'logout') ? 'badge-logout'
                    : (str_contains($action,'edit')||str_contains($action,'update') ? 'badge-edit'
                    : (str_contains($action,'delete') ? 'badge-delete'
                    : (str_contains($action,'approve') ? 'badge-approve'
                    : (str_contains($action,'create')||str_contains($action,'encode')||str_contains($action,'add') ? 'badge-encode'
                    : 'badge-default')))));
            ?>
                <tr>
                    <td><?= $i ?></td>
                    <td><?= htmlspecialchars(trim($r['staff_name']))?:'-' ?></td>
                    <td><span class="cr-badge <?= $badge_class ?>"><?= htmlspecialchars($r['action_type']) ?></span></td>
                    <td><?= $r['timestamp'] ? date('m/d/Y H:i:s', strtotime($r['timestamp'])) : '—' ?></td>
                    <td><?= htmlspecialchars($r['module_affected']) ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['remarks']) ?>"><?= htmlspecialchars(strlen($r['remarks'])>60 ? substr($r['remarks'],0,60).'…' : $r['remarks']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($activity_rows)): ?>
            <tfoot><tr>
                <td colspan="6">TOTAL RECORDS: <?= count($activity_rows) ?></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- AUDIT TRAIL -->
    <div id="cr-panel-audit" class="cr-panel <?= $section==='audit'?'active':'' ?>">
        <div class="cr-rpt-header">
            <div class="rh-title">AUDIT TRAIL REPORT</div>
            <div class="rh-sub">CONSOLIDATED COMPLIANCE LOGS</div>
            <div class="rh-station"><?= htmlspecialchars($station_name) ?></div>
            <div class="rh-date"><strong>Date:</strong> <?= date('F j, Y', strtotime($date_from)) ?><?= $date_from!==$date_to?' – '.date('F j, Y', strtotime($date_to)):'' ?></div>
        </div>
        <table class="cr-tbl">
            <thead><tr>
                <th>#</th><th>Staff Name / Encoder</th><th>Action Performed</th>
                <th>Timestamp</th><th>Shift</th><th>System Reference</th><th>Module</th>
            </tr></thead>
            <tbody>
            <?php if (empty($audit_rows)): ?>
                <tr><td colspan="7" class="cr-empty">No audit trail records for this period.</td></tr>
            <?php else: $i=0; foreach ($audit_rows as $r): $i++;
                $action = strtolower($r['action_performed']);
                $badge_class = str_contains($action,'delete') ? 'badge-delete'
                    : (str_contains($action,'approve')||str_contains($action,'validate') ? 'badge-approve'
                    : (str_contains($action,'update')||str_contains($action,'edit') ? 'badge-edit'
                    : (str_contains($action,'add')||str_contains($action,'create') ? 'badge-encode'
                    : 'badge-default')));
            ?>
                <tr>
                    <td><?= $i ?></td>
                    <td><?= htmlspecialchars(trim($r['staff_name']))?:'-' ?></td>
                    <td><span class="cr-badge <?= $badge_class ?>"><?= htmlspecialchars($r['action_performed']) ?></span></td>
                    <td><?= $r['timestamp'] ? date('m/d/Y H:i:s', strtotime($r['timestamp'])) : '—' ?></td>
                    <td><?= htmlspecialchars($r['shift_assignment']) ?></td>
                    <td><?= htmlspecialchars($r['system_reference']) ?></td>
                    <td><?= htmlspecialchars($r['module_affected']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($audit_rows)): ?>
            <tfoot><tr>
                <td colspan="7">TOTAL RECORDS: <?= count($audit_rows) ?></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- CALENDAR & SCHEDULE -->
    <div id="cr-panel-calendar" class="cr-panel <?= $section==='calendar'?'active':'' ?>">
        <div class="cr-rpt-header">
            <div class="rh-title">CALENDAR & SCHEDULE REPORT</div>
            <div class="rh-sub">JOB ORDERS & DELIVERIES SCHEDULE</div>
            <div class="rh-station"><?= htmlspecialchars($station_name) ?></div>
            <div class="rh-date"><strong>Date:</strong> <?= date('F j, Y', strtotime($date_from)) ?><?= $date_from!==$date_to?' – '.date('F j, Y', strtotime($date_to)):'' ?></div>
        </div>

        <!-- Calendar Grid View -->
        <?php
        // Build calendar for the month of date_from
        $cal_year  = (int)date('Y', strtotime($date_from));
        $cal_month = (int)date('m', strtotime($date_from));
        $first_day = mktime(0,0,0,$cal_month,1,$cal_year);
        $days_in_month = (int)date('t', $first_day);
        $start_dow = (int)date('w', $first_day); // 0=Sun
        $today = date('Y-m-d');
        $day_labels = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        ?>
        <div style="margin-bottom:6px;font-size:13px;font-weight:700;color:#00264D;text-transform:uppercase;">
            <?= date('F Y', $first_day) ?>
        </div>
        <div class="cr-calendar-grid">
            <?php foreach ($day_labels as $dl): ?>
                <div class="cr-cal-header"><?= $dl ?></div>
            <?php endforeach; ?>
            <?php
            // Empty cells before first day
            for ($i = 0; $i < $start_dow; $i++) echo '<div class="cr-cal-day empty"></div>';
            // Day cells
            for ($day = 1; $day <= $days_in_month; $day++):
                $date_key = sprintf('%04d-%02d-%02d', $cal_year, $cal_month, $day);
                $is_today = ($date_key === $today);
                $tasks_on_day = $calendar_by_date[$date_key] ?? [];
            ?>
                <div class="cr-cal-day <?= $is_today?'today':'' ?>">
                    <div class="day-num"><?= $day ?></div>
                    <?php foreach (array_slice($tasks_on_day, 0, 3) as $t): ?>
                        <div class="cr-task-dot <?= $t['task_type']==='Job Order'?'cr-task-jo':'cr-task-fd' ?>">
                            <?= htmlspecialchars($t['task_type'] === 'Job Order' ? 'JO' : 'DEL') ?>: <?= htmlspecialchars(substr($t['task_ref'],0,8)) ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($tasks_on_day) > 3): ?>
                        <div style="font-size:9px;color:#64748b;">+<?= count($tasks_on_day)-3 ?> more</div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>

        <!-- Task List Table -->
        <div class="cr-sub-heading"><i class="fas fa-list"></i> Task List</div>
        <table class="cr-tbl">
            <thead><tr>
                <th>#</th><th>Task Type</th><th>Task ID / Reference</th>
                <th>Assigned Staff / Supplier</th><th>Scheduled Date & Time</th>
                <th>Status</th><th>Description</th>
            </tr></thead>
            <tbody>
            <?php if (empty($calendar_tasks)): ?>
                <tr><td colspan="7" class="cr-empty">No scheduled tasks for this period.</td></tr>
            <?php else: $i=0; foreach ($calendar_tasks as $t): $i++;
                $status_lc = strtolower($t['status']);
                $sc = str_contains($status_lc,'complet') ? 'status-completed'
                    : (str_contains($status_lc,'cancel') ? 'status-cancelled'
                    : (str_contains($status_lc,'progress') ? 'status-progress'
                    : 'status-pending'));
            ?>
                <tr>
                    <td><?= $i ?></td>
                    <td><?= htmlspecialchars($t['task_type']) ?></td>
                    <td><?= htmlspecialchars($t['task_ref']) ?></td>
                    <td><?= htmlspecialchars($t['assigned_to']) ?></td>
                    <td><?= $t['scheduled_date'] ? date('m/d/Y H:i', strtotime($t['scheduled_date'])) : '—' ?></td>
                    <td><span class="cr-status <?= $sc ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                    <td><?= htmlspecialchars($t['description']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($calendar_tasks)):
                $counts = array_count_values(array_column($calendar_tasks,'task_type'));
            ?>
            <tfoot><tr>
                <td colspan="7">
                    TOTAL: <?= count($calendar_tasks) ?> tasks
                    &nbsp;|&nbsp; Job Orders: <?= $counts['Job Order'] ?? 0 ?>
                    &nbsp;|&nbsp; Deliveries: <?= $counts['Fuel Delivery'] ?? 0 ?>
                </td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>

    </div><!-- end rpt-printable -->
    </div><!-- end cr-content -->
</div><!-- end rpt-wrapper -->

<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<script>
function crTab(key) {
    const url = new URL(window.location.href);
    url.searchParams.set('section', key);
    const df = document.querySelector('input[name="date_from"]');
    const dt = document.querySelector('input[name="date_to"]');
    if (df) url.searchParams.set('date_from', df.value);
    if (dt) url.searchParams.set('date_to', dt.value);
    window.location.href = url.toString();
}

function tableToAoA(table) {
    const aoa = [];
    table.querySelectorAll('thead tr').forEach(tr =>
        aoa.push([...tr.querySelectorAll('th')].map(th => th.innerText.trim())));
    table.querySelectorAll('tbody tr').forEach(tr =>
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim())));
    table.querySelectorAll('tfoot tr').forEach(tr =>
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim())));
    return aoa;
}

function crExport(type) {
    if (type === 'excel' && typeof XLSX === 'undefined') {
        alert('Export library not loaded. Please refresh the page and try again.');
        return;
    }
    const wrap = document.querySelector('.rpt-printable');
    if (!wrap) { alert('No report content found.'); return; }

    const activePanel = wrap.querySelector('.cr-panel.active') || wrap;
    const tables = Array.from(activePanel.querySelectorAll('table.cr-tbl'))
                       .filter(t => t.querySelector('tbody tr'));

    if (!tables.length) { alert('No table data found to export.'); return; }

    const section  = new URL(window.location).searchParams.get('section') || 'compliance';
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo   = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Compliance_Report_${section}_${dateFrom}_to_${dateTo}`;

    if (type === 'pdf') {
        exportPrintableAreaToPDF(activePanel, 'Admin Compliance Report', filename, document.activeElement);
        return;
    }

    if (type === 'csv') {
        let csv = '';
        tables.forEach((tbl, i) => {
            const heading = tbl.previousElementSibling;
            if (heading && heading.classList.contains('cr-sub-heading'))
                csv += '"' + heading.innerText.trim().replace(/"/g, '""') + '"\n';
            if (i > 0) csv += '\n';
            tableToAoA(tbl).forEach(row => {
                csv += row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',') + '\n';
            });
        });
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = filename + '.csv';
        document.body.appendChild(a); a.click();
        setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
    } else {
        const wb = XLSX.utils.book_new();
        const sheetNames = { activity:'Activity Logs', audit:'Audit Trail', calendar:'Task List' };
        tables.forEach((tbl, i) => {
            const heading = tbl.previousElementSibling;
            let name = heading?.innerText?.trim()?.replace(/[:\\\/?*\[\]]/g,'')?.substring(0,31)
                     || Object.values(sheetNames)[i] || `Sheet${i+1}`;
            const aoa = tableToAoA(tbl);
            const ws  = XLSX.utils.aoa_to_sheet(aoa);
            if (aoa.length && aoa[0]) {
                ws['!cols'] = aoa[0].map((_, ci) => ({
                    wch: Math.min(45, Math.max(10, ...aoa.map(r => String(r[ci] ?? '').length)))
                }));
            }
            XLSX.utils.book_append_sheet(wb, ws, name);
        });
        XLSX.writeFile(wb, filename + '.xlsx');
    }
}

function crPrint() {
    window.print();
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
