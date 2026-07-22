<?php
/**
 * Manager Compliance Reports - Real Report Format
 * Activity Logs, Audit Trail, Calendar & Schedule
 * Blue theme for Manager role
 */

$page_id = 'manager_compliance_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Check if manager role
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    die('Access denied. Manager privileges required.');
}

// Module gate
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

// Get active tab from URL parameter
$active_tab = $_GET['tab'] ?? 'activity_logs';

// Active compliance sub-report
$section = $_GET['section'] ?? 'activity_logs';
$valid_sections = ['activity_logs', 'audit_trail', 'calendar'];
if (!in_array($section, $valid_sections, true)) {
    $section = 'activity_logs';
}

// Get date range from GET or use current month as default
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d');
}

// Pass these variables to included report files
$date_start = $date_from;
$date_end = $date_to;

// Get Station Name
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) $station_name = $st['name'];
} catch (Exception $e) {}

function crManagerTrackerServiceWhere(string $alias = 'mt'): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "(
        LOWER(COALESCE({$p}transaction_type, '')) IN ('job_order', 'combined', 'service')
        OR ({$p}job_order_service IS NOT NULL AND TRIM({$p}job_order_service) <> '')
        OR {$p}job_order_id IS NOT NULL
        OR {$p}job_order_db_id IS NOT NULL
    )";
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* Reuse same styles from finance reports */
.reports-wrapper {
    background: white !important;
    border-radius: 8px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08) !important;
    margin: 0 !important;
    overflow: hidden !important;
}

.rpt-content { padding: 24px 28px !important; }

.rpt-filter-bar {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    padding: 0 !important;
    background: transparent !important;
    border-radius: 0 !important;
    border: none !important;
    margin-bottom: 24px !important;
    flex-wrap: wrap !important;
}

.rpt-filter-bar label {
    font-size: 12px !important;
    font-weight: 600 !important;
    color: #00264D !important;
    margin: 0 !important;
}

.rpt-filter-bar input[type="date"] {
    padding: 7px 10px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    max-width: 140px !important;
}

.rpt-filter-bar button {
    padding: 7px 16px !important;
    background: #ffffff !important;
    color: #00264D !important;
    border: 1px solid #00264D !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
}

.rpt-filter-bar button:hover {
    background: #00264D !important;
    color: #ffffff !important;
}
.rpt-filter-bar button i { margin-right: 4px !important; }

.rpt-export-actions {
    display: flex !important;
    gap: 6px !important;
    margin-left: auto !important;
}

.rpt-export-btn {
    padding: 7px 14px !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    border: 1px solid !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: #ffffff !important;
}

/* Excel Button - Green Border */
.rpt-export-btn:nth-child(1) {
    color: #16a34a !important;
    border-color: #16a34a !important;
}
.rpt-export-btn:nth-child(1):hover {
    background: #f0fdf4 !important;
    border-color: #15803d !important;
    color: #16a34a !important;
}

/* CSV Button - Dark Blue Border */
.rpt-export-btn:nth-child(2) {
    color: #002F70 !important;
    border-color: #002F70 !important;
}
.rpt-export-btn:nth-child(2):hover {
    background: #eff6ff !important;
    border-color: #001f4d !important;
    color: #002F70 !important;
}

/* PDF Button - Red Border */
.rpt-export-btn:nth-child(3) {
    color: #dc2626 !important;
    border-color: #dc2626 !important;
}
.rpt-export-btn:nth-child(3):hover {
    background: #fef2f2 !important;
    border-color: #b91c1c !important;
    color: #dc2626 !important;
}

/* Print Button - Blue Border */
.rpt-export-btn:nth-child(4) {
    color: #002F70 !important;
    border-color: #002F70 !important;
}
.rpt-export-btn:nth-child(4):hover {
    background: #eff6ff !important;
    border-color: #002F70 !important;
    color: #002F70 !important;
}

.rpt-export-btn i { margin-right: 3px !important; }

/* Compliance Section Tabs */
.cr-section-tabs {
    display: flex;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 0;
    overflow:hidden;
    background: #00264D;
}

.cr-section-tab {
    padding: 12px 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #ffffff !important;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
}

.cr-section-tab:hover { background: rgba(255,255,255,0.15); color: #ffffff !important; }
.cr-section-tab.active {
    background: #ffffff;
    color: #00264D !important;
    border-bottom-color: #002F70;
    font-weight: 800;
}

.cr-section-panel { display: none; }
.cr-section-panel.active { display: block; }

/* Compliance Tables */
.cr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin-top: 16px;
}

.cr-table thead tr {
    border-top: 2px solid #00264D;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
}

.cr-table thead th {
    padding: 10px 8px;
    text-align: left;
    font-weight: 700;
    color: #475569;
    font-size: 11px;
    text-transform: uppercase;
    background: #f8fafc;
}

.cr-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.cr-table tbody tr:hover { background: #f8fafc; }
.cr-table tbody td { padding: 9px 8px; color: #334155; font-size: 12px; }

.cr-table tfoot tr {
    border-top: 2px solid #00264D;
    background: #f0f4ff;
}

.cr-table tfoot td {
    padding: 10px 8px;
    font-weight: 700;
    color: #00264D;
    font-size: 12px;
}

.cr-empty {
    text-align: center;
    padding: 28px;
    color: #94a3b8;
    font-size: 13px;
}

.cr-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
}

.cr-badge-login { background: #e3f2fd; color: #1976d2; }
.cr-badge-logout { background: #fce4ec; color: #c2185b; }
.cr-badge-encode { background: #f3e5f5; color: #7b1fa2; }
.cr-badge-edit { background: #fff3e0; color: #ef6c00; }
.cr-badge-export { background: #e8f5e9; color: #388e3c; }

@media print {
    @page { size: legal portrait; margin: 0.3in 0.4in; }
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        box-sizing: border-box !important;
    }
    html, body {
        background: #fff !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    body * { visibility: hidden !important; }
    .rpt-printable, .rpt-printable * { visibility: visible !important; }
    .rpt-printable {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        overflow: visible !important;
        background: #fff !important;
    }
    .cr-section-tabs, .rpt-filter-bar, .rpt-export-actions { display: none !important; }
    .cr-section-panel { display: none !important; overflow: visible !important; }
    .cr-section-panel.active { display: block !important; }
    .cr-section-panel > div:first-child {
        break-after: avoid !important;
        page-break-after: avoid !important;
    }
    .cr-table {
        width: 100% !important;
        max-width: 100% !important;
        border-collapse: collapse !important;
        table-layout: auto !important;
        font-size: 9.4px !important;
        break-inside: auto !important;
        page-break-inside: auto !important;
    }
    .cr-table thead { display: table-header-group !important; }
    .cr-table tfoot { display: table-footer-group !important; }
    .cr-table tr {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }
    .cr-table thead th {
        font-size: 8.6px !important;
        padding: 5px !important;
        white-space: normal !important;
        word-break: break-word !important;
    }
    .cr-table tbody td,
    .cr-table tfoot td {
        font-size: 9.2px !important;
        padding: 5px !important;
        white-space: normal !important;
        word-break: break-word !important;
    }
    .cr-empty {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }
}
</style>

<div class="reports-wrapper">
    <div class="rpt-content">
        <!-- Date Filter Bar -->
        <form method="GET" class="rpt-filter-bar">
            <input type="hidden" name="section" id="managerComplianceSection" value="<?= htmlspecialchars($section) ?>">
            <label style="font-weight:700;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:.4px;">From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
            <span style="font-weight:700;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:.4px;">To</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
            <button type="submit"><i class="fas fa-sync-alt"></i> Apply</button>
            
            <div class="rpt-export-actions">
                <button type="button" class="rpt-export-btn" onclick="exportReport('excel')">
                    <i class="fas fa-file-excel"></i> Excel
                </button>
                <button type="button" class="rpt-export-btn" onclick="exportReport('csv')">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
                <button type="button" class="rpt-export-btn" onclick="exportReport('pdf', this)">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
                <button type="button" class="rpt-export-btn" onclick="printReport()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </form>

        <!-- Printable Report Content -->
        <div class="rpt-printable">
            <!-- Section Tabs -->
            <div class="cr-section-tabs">
                <button type="button" class="cr-section-tab <?= $section === 'activity_logs' ? 'active' : '' ?>"
                        onclick="crSwitchSection('activity_logs', this)">
                    <i class="fas fa-history"></i> Activity Logs
                </button>
                <button type="button" class="cr-section-tab <?= $section === 'audit_trail' ? 'active' : '' ?>"
                        onclick="crSwitchSection('audit_trail', this)">
                    <i class="fas fa-shield-alt"></i> Audit Trail
                </button>
                <button type="button" class="cr-section-tab <?= $section === 'calendar' ? 'active' : '' ?>"
                        onclick="crSwitchSection('calendar', this)">
                    <i class="fas fa-calendar-check"></i> Calendar & Schedule
                </button>
            </div>

            <!-- Activity Logs Section -->
            <div id="cr-panel-activity_logs" class="cr-section-panel <?= $section === 'activity_logs' ? 'active' : '' ?>">
                <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
                    <div style="font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
                        ACTIVITY LOGS REPORT
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
                        STAFF ACTIONS MONITORING
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
                        <?= htmlspecialchars($station_name) ?>
                    </div>
                    <div style="font-size:12px;color:#334155;">
                        <strong>Date:</strong>
                        <?= date('F j, Y', strtotime($date_start)) ?>
                        <?= $date_start !== $date_end ? ' – ' . date('F j, Y', strtotime($date_end)) : '' ?>
                    </div>
                </div>

                <?php
                // Fetch activity logs from audit_logs + activity_logs (both sources)
                try {
                    // Source A: audit_logs (API-level actions)
                    $q = $pdo->prepare("
                        SELECT
                            al.id,
                            al.action_type                                                              AS action,
                            COALESCE(al.action_details,'')                                             AS details,
                            al.created_at,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                                     u.username, CONCAT('User #',al.user_id))                          AS staff_name,
                            COALESCE(u.role,'unknown')                                                 AS staff_role
                        FROM audit_logs al
                        LEFT JOIN users u ON al.user_id = u.id
                        WHERE u.station_id = ?
                          AND DATE(al.created_at) BETWEEN ? AND ?
                        ORDER BY al.created_at DESC
                        LIMIT 400
                    ");
                    $q->execute([$station_id, $date_start, $date_end]);
                    $activity_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    // Source B: activity_logs (lib.php log_activity() calls)
                    $q2 = $pdo->prepare("
                        SELECT
                            al2.id,
                            al2.action                                                                 AS action,
                            COALESCE(al2.details,'')                                                   AS details,
                            al2.created_at,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),
                                     u.username, CONCAT('User #',al2.user_id))                         AS staff_name,
                            COALESCE(u.role,'unknown')                                                 AS staff_role
                        FROM activity_logs al2
                        LEFT JOIN users u ON al2.user_id = u.id
                        WHERE u.station_id = ?
                          AND DATE(al2.created_at) BETWEEN ? AND ?
                        ORDER BY al2.created_at DESC
                        LIMIT 300
                    ");
                    $q2->execute([$station_id, $date_start, $date_end]);
                    $al2_rows = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $activity_rows = array_merge($activity_rows, $al2_rows);
                    usort($activity_rows, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
                    $activity_rows = array_slice($activity_rows, 0, 600);
                } catch (Exception $e) {
                    $activity_rows = [];
                }
                ?>

                <table class="cr-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Staff Name</th>
                            <th>Role</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>Compliance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($activity_rows)) {
                            echo '<tr><td colspan="6" class="cr-empty">No activity logs for this period</td></tr>';
                        } else {
                            foreach ($activity_rows as $row) {
                                $action = strtolower($row['action'] ?? '');
                                $badge_class = 'cr-badge';
                                if (strpos($action, 'login') !== false) $badge_class .= ' cr-badge-login';
                                elseif (strpos($action, 'logout') !== false) $badge_class .= ' cr-badge-logout';
                                elseif (strpos($action, 'encode') !== false || strpos($action, 'create') !== false) $badge_class .= ' cr-badge-encode';
                                elseif (strpos($action, 'edit') !== false || strpos($action, 'update') !== false) $badge_class .= ' cr-badge-edit';
                                elseif (strpos($action, 'export') !== false) $badge_class .= ' cr-badge-export';

                                echo '<tr>';
                                echo '<td>' . date('M j, Y H:i', strtotime($row['created_at'])) . '</td>';
                                echo '<td>' . htmlspecialchars($row['staff_name']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['staff_role'] ?? '—') . '</td>';
                                echo '<td><span class="' . $badge_class . '">' . htmlspecialchars($row['action']) . '</span></td>';
                                echo '<td>' . htmlspecialchars(substr($row['details'] ?? '', 0, 80)) . (strlen($row['details'] ?? '') > 80 ? '...' : '') . '</td>';
                                echo '<td>Monitor</td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                    <?php if (!empty($activity_rows)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="5" style="text-align:right;"><strong>TOTAL ACTIVITIES:</strong></td>
                            <td><strong><?= count($activity_rows) ?></strong></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Audit Trail Section -->
            <div id="cr-panel-audit_trail" class="cr-section-panel <?= $section === 'audit_trail' ? 'active' : '' ?>">
                <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
                    <div style="font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
                        AUDIT TRAIL REPORT
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
                        CONSOLIDATED LOGS ACROSS SHIFTS
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
                        <?= htmlspecialchars($station_name) ?>
                    </div>
                    <div style="font-size:12px;color:#334155;">
                        <strong>Date:</strong>
                        <?= date('F j, Y', strtotime($date_start)) ?>
                        <?= $date_start !== $date_end ? ' – ' . date('F j, Y', strtotime($date_end)) : '' ?>
                    </div>
                </div>

                <?php
                // Audit trail: audit_logs + audit_trail merged, grouped by date+shift+action
                try {
                    // From audit_logs
                    $q = $pdo->prepare("
                        SELECT
                            DATE(al.created_at)                                                         AS log_date,
                            CASE WHEN HOUR(al.created_at)>=6 AND HOUR(al.created_at)<14 THEN 'Shift 1' ELSE 'Shift 2' END AS shift_period,
                            al.action_type                                                              AS action,
                            COUNT(*)                                                                    AS action_count,
                            GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username) ORDER BY u.id SEPARATOR ', ') AS staff_list
                        FROM audit_logs al
                        LEFT JOIN users u ON al.user_id = u.id
                        WHERE u.station_id = ?
                          AND DATE(al.created_at) BETWEEN ? AND ?
                        GROUP BY log_date, shift_period, al.action_type
                        ORDER BY log_date DESC, shift_period, action_count DESC
                    ");
                    $q->execute([$station_id, $date_start, $date_end]);
                    $audit_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Exception $e) {
                    $audit_rows = [];
                }

                // Also from audit_trail (manager validation actions)
                try {
                    $q2 = $pdo->prepare("
                        SELECT
                            DATE(at2.timestamp)                                                        AS log_date,
                            CASE WHEN HOUR(at2.timestamp)>=6 AND HOUR(at2.timestamp)<14 THEN 'Shift 1' ELSE 'Shift 2' END AS shift_period,
                            at2.action_type                                                            AS action,
                            COUNT(*)                                                                   AS action_count,
                            GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username) ORDER BY u.id SEPARATOR ', ') AS staff_list
                        FROM audit_trail at2
                        LEFT JOIN users u ON u.id = at2.manager_id
                        WHERE at2.station_id = ?
                          AND DATE(at2.timestamp) BETWEEN ? AND ?
                        GROUP BY log_date, shift_period, at2.action_type
                        ORDER BY log_date DESC, shift_period, action_count DESC
                    ");
                    $q2->execute([$station_id, $date_start, $date_end]);
                    $at_rows = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    // Also from activity_logs (lib.php log_activity calls at manager level)
                    $q3 = $pdo->prepare("
                        SELECT
                            DATE(al.created_at)                                                        AS log_date,
                            CASE WHEN HOUR(al.created_at)>=6 AND HOUR(al.created_at)<14 THEN 'Shift 1' ELSE 'Shift 2' END AS shift_period,
                            al.action                                                                  AS action,
                            COUNT(*)                                                                   AS action_count,
                            GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.username) ORDER BY u.id SEPARATOR ', ') AS staff_list
                        FROM activity_logs al
                        LEFT JOIN users u ON al.user_id = u.id
                        WHERE u.station_id = ?
                          AND DATE(al.created_at) BETWEEN ? AND ?
                        GROUP BY log_date, shift_period, al.action
                        ORDER BY log_date DESC, shift_period, action_count DESC
                        LIMIT 200
                    ");
                    $q3->execute([$station_id, $date_start, $date_end]);
                    $al3_rows = $q3->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $audit_rows = array_merge($audit_rows, $at_rows, $al3_rows);
                    usort($audit_rows, fn($a,$b) => strcmp($b['log_date'],$a['log_date']));
                } catch (Exception $e2) { /* keep audit_logs results */ }
                ?>

                <table class="cr-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Shift</th>
                            <th>Action Type</th>
                            <th>Count</th>
                            <th>Staff Involved</th>
                            <th>Validation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($audit_rows)) {
                            echo '<tr><td colspan="6" class="cr-empty">No audit trail records for this period</td></tr>';
                        } else {
                            foreach ($audit_rows as $row) {
                                echo '<tr>';
                                echo '<td>' . date('M j, Y', strtotime($row['log_date'])) . '</td>';
                                echo '<td>' . htmlspecialchars($row['shift_period']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['action']) . '</td>';
                                echo '<td><strong>' . number_format($row['action_count']) . '</strong></td>';
                                echo '<td>' . htmlspecialchars(substr($row['staff_list'], 0, 50)) . (strlen($row['staff_list']) > 50 ? '...' : '') . '</td>';
                                echo '<td>Export</td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                    <?php if (!empty($audit_rows)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align:right;"><strong>TOTAL ACTIONS:</strong></td>
                            <td><strong><?= number_format(array_sum(array_column($audit_rows, 'action_count'))) ?></strong></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Calendar & Schedule Section -->
            <div id="cr-panel-calendar" class="cr-section-panel <?= $section === 'calendar' ? 'active' : '' ?>">
                <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
                    <div style="font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
                        CALENDAR & SCHEDULE REPORT
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
                        JOB ORDERS & DELIVERIES TASKS
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
                        <?= htmlspecialchars($station_name) ?>
                    </div>
                    <div style="font-size:12px;color:#334155;">
                        <strong>Date:</strong>
                        <?= date('F j, Y', strtotime($date_start)) ?>
                        <?= $date_start !== $date_end ? ' – ' . date('F j, Y', strtotime($date_end)) : '' ?>
                    </div>
                </div>

                <?php
                // Fetch scheduled tasks from native job orders, service tracker rows, and fuel deliveries.
                $calendar_rows = [];

                try {
                    $jo_q = $pdo->prepare("
                        SELECT
                            'Job Order' AS task_type,
                            COALESCE(jo.job_order_number, CONCAT('JO-', jo.id)) AS task_ref,
                            COALESCE(NULLIF(jo.service_description, ''), NULLIF(jo.service_type, ''), 'Service') AS task_description,
                            COALESCE(jo.customer_name, c.name, 'Walk-in') AS customer_name,
                            COALESCE(jo.status, 'Pending') AS status,
                            jo.created_at AS scheduled_date,
                            COALESCE(
                                NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                                u.username,
                                'Unassigned'
                            ) AS assigned_to
                        FROM job_orders jo
                        LEFT JOIN customers c ON jo.customer_id = c.id
                        LEFT JOIN users u ON jo.user_id = u.id
                        WHERE jo.station_id = ?
                          AND DATE(jo.created_at) BETWEEN ? AND ?
                        ORDER BY jo.created_at DESC
                    ");
                    $jo_q->execute([$station_id, $date_start, $date_end]);
                    $calendar_rows = array_merge($calendar_rows, $jo_q->fetchAll(PDO::FETCH_ASSOC) ?: []);
                } catch (Exception $e) {}

                try {
                    $service_where = crManagerTrackerServiceWhere('mt');
                    $tracker_q = $pdo->prepare("
                        SELECT
                            'Job Order' AS task_type,
                            COALESCE(NULLIF(mt.job_order_id, ''), mt.transaction_id, CONCAT('MT-', mt.id)) AS task_ref,
                            COALESCE(NULLIF(mt.job_order_service, ''), NULLIF(mt.job_order_description, ''), 'Service') AS task_description,
                            COALESCE(
                                NULLIF(TRIM(mt.customer_name), ''),
                                NULLIF(TRIM(CONCAT(COALESCE(mt.customer_first_name, ''), ' ', COALESCE(mt.customer_last_name, ''))), ''),
                                'Walk-in'
                            ) AS customer_name,
                            COALESCE(NULLIF(mt.workflow_status, ''), NULLIF(mt.validation_status, ''), 'Pending') AS status,
                            COALESCE(mt.transaction_date, mt.created_at) AS scheduled_date,
                            COALESCE(
                                NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                                u.username,
                                'System'
                            ) AS assigned_to
                        FROM merchandise_transactions mt
                        LEFT JOIN users u ON mt.staff_id = u.id
                        WHERE mt.station_id = ?
                          AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                          AND {$service_where}
                          AND NOT EXISTS (
                              SELECT 1
                              FROM job_orders jo2
                              WHERE jo2.station_id = mt.station_id
                                AND (
                                    (mt.job_order_db_id IS NOT NULL AND jo2.id = mt.job_order_db_id)
                                    OR (mt.job_order_id IS NOT NULL AND mt.job_order_id <> '' AND jo2.job_order_number = mt.job_order_id)
                                )
                          )
                        ORDER BY COALESCE(mt.transaction_date, mt.created_at) DESC
                    ");
                    $tracker_q->execute([$station_id, $date_start, $date_end]);
                    $calendar_rows = array_merge($calendar_rows, $tracker_q->fetchAll(PDO::FETCH_ASSOC) ?: []);
                } catch (Exception $e) {}

                try {
                    $del_q = $pdo->prepare("
                        SELECT
                            'Fuel Delivery' AS task_type,
                            COALESCE(NULLIF(fd.invoice_no, ''), CONCAT('FD-', fd.id)) AS task_ref,
                            CONCAT(COALESCE(fd.fuel_type, 'Fuel'), ' - ', FORMAT(COALESCE(fd.delivery_liters, 0), 2), ' L') AS task_description,
                            COALESCE(NULLIF(fd.supplier, ''), 'Unknown Supplier') AS customer_name,
                            COALESCE(fd.status, 'Pending') AS status,
                            COALESCE(fd.delivery_date, fd.created_at) AS scheduled_date,
                            COALESCE(NULLIF(fd.received_by, ''), 'System') AS assigned_to
                        FROM fuel_deliveries fd
                        WHERE fd.station_id = ?
                          AND DATE(COALESCE(fd.delivery_date, fd.created_at)) BETWEEN ? AND ?
                        ORDER BY COALESCE(fd.delivery_date, fd.created_at) DESC
                    ");
                    $del_q->execute([$station_id, $date_start, $date_end]);
                    $calendar_rows = array_merge($calendar_rows, $del_q->fetchAll(PDO::FETCH_ASSOC) ?: []);
                } catch (Exception $e) {}

                usort($calendar_rows, function($a, $b) {
                    return strtotime($b['scheduled_date']) - strtotime($a['scheduled_date']);
                });
                ?>

                <table class="cr-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Task Type</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th>Customer/Supplier</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($calendar_rows)) {
                            echo '<tr><td colspan="8" class="cr-empty">No scheduled tasks for this period</td></tr>';
                        } else {
                            foreach ($calendar_rows as $row) {
                                echo '<tr>';
                                echo '<td>' . date('M j, Y H:i', strtotime($row['scheduled_date'])) . '</td>';
                                echo '<td><span class="cr-badge">' . htmlspecialchars($row['task_type']) . '</span></td>';
                                echo '<td>' . htmlspecialchars($row['task_ref']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['task_description']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['customer_name']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['status']) . '</td>';
                                echo '<td>' . htmlspecialchars($row['assigned_to']) . '</td>';
                                echo '<td>Approve</td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                    <?php if (!empty($calendar_rows)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="7" style="text-align:right;"><strong>TOTAL TASKS:</strong></td>
                            <td><strong><?= count($calendar_rows) ?></strong></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

        </div>
    </div>
</div>

<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<script>
function crSwitchSection(sectionKey, trigger) {
    document.querySelectorAll('.cr-section-panel').forEach(p => p.classList.remove('active'));

    const panel = document.getElementById('cr-panel-' + sectionKey);
    if (panel) panel.classList.add('active');

    document.querySelectorAll('.cr-section-tab').forEach(btn => btn.classList.remove('active'));
    const selectedTab = trigger?.closest('.cr-section-tab')
        || document.querySelector(`.cr-section-tab[onclick*="${sectionKey}"]`);
    if (selectedTab) selectedTab.classList.add('active');

    const sectionInput = document.getElementById('managerComplianceSection');
    if (sectionInput) sectionInput.value = sectionKey;

    const url = new URL(window.location);
    url.searchParams.set('section', sectionKey);
    window.history.replaceState({}, '', url);
}

function exportReport(type) {
    const wrap = document.querySelector('.rpt-printable');
    if (!wrap) { alert('No report content found.'); return; }

    const activePanel = wrap.querySelector('.cr-section-panel.active') || wrap;
    const section  = document.getElementById('managerComplianceSection')?.value
        || new URL(window.location).searchParams.get('section')
        || 'activity_logs';
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo   = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Manager_Compliance_Report_${section}_${dateFrom}_to_${dateTo}`;

    if (type === 'pdf') {
        exportPrintableAreaToPDF(activePanel, 'Manager Compliance Report', filename, document.activeElement);
        return;
    }

    if (typeof XLSX === 'undefined') {
        alert('Export library not loaded. Please refresh the page and try again.');
        return;
    }

    const tables = Array.from(activePanel.querySelectorAll('table')).filter(
        t => t.querySelector('tbody tr')
    );

    if (!tables.length) { alert('No table data found to export.'); return; }

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
    table.querySelectorAll('tfoot tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim()));
    });
    return aoa;
}

function exportExcel(tables, filename) {
    const wb = XLSX.utils.book_new();
    tables.forEach((tbl, i) => {
        const aoa = tableToAoA(tbl);
        const ws  = XLSX.utils.aoa_to_sheet(aoa);
        if (aoa.length && aoa[0]) {
            ws['!cols'] = aoa[0].map((_, ci) => ({
                wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] ?? '').length)))
            }));
        }
        const heading = tbl.closest('.cr-section-panel')?.querySelector('div[style*="font-size:20px"]');
        const sheetName = (heading?.innerText || `Report_${i + 1}`)
            .replace(/[:\\\/?*\[\]]/g, '')
            .substring(0, 31);
        XLSX.utils.book_append_sheet(wb, ws, sheetName || `Report_${i + 1}`);
    });
    XLSX.writeFile(wb, filename + '.xlsx');
}

function exportCSV(tables, filename) {
    let csv = '';
    tables.forEach((tbl, i) => {
        if (i > 0) csv += '\n';
        tableToAoA(tbl).forEach(row => {
            csv += row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',') + '\n';
        });
    });
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = filename + '.csv';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
}

function printReport() {
    const wrap = document.querySelector('.rpt-printable');
    const activePanel = wrap?.querySelector('.cr-section-panel.active') || wrap;
    printReportArea(activePanel);
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
