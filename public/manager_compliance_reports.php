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
    padding: 14px 18px !important;
    background: #f8f9fa !important;
    border-radius: 6px !important;
    border: 1px solid #e2e8f0 !important;
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
    background: #00264D !important;
    color: white !important;
    border: none !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
}

.rpt-filter-bar button:hover { background: #003d7a !important; }
.rpt-filter-bar button i { margin-right: 4px !important; }

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

.rpt-export-btn:hover { background: #00264D !important; color: white !important; }
.rpt-export-btn i { margin-right: 3px !important; }

/* Compliance Section Tabs */
.cr-section-tabs {
    display: flex;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 0;
    overflow:hidden;
}

.cr-section-tab {
    padding: 12px 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #64748b;
    background: #f8f9fa;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
}

.cr-section-tab:hover { background: #fff; color: #00264D; }
.cr-section-tab.active {
    background: #fff;
    color: #00264D;
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
    background: #002F70;
}

.cr-table thead th {
    padding: 10px 8px;
    text-align: left;
    font-weight: 700;
    color: #ffffff;
    font-size: 11px;
    text-transform: uppercase;
    background: #002F70;
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
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .cr-section-tabs, .rpt-filter-bar, .rpt-export-actions { display: none !important; }
}
</style>

<div class="reports-wrapper">
    <div class="rpt-content">
        <!-- Date Filter Bar -->
        <form method="GET" class="rpt-filter-bar">
            <label><i class="fas fa-calendar"></i> Report Date:</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
            <span style="color: #64748b;">to</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
            <button type="submit"><i class="fas fa-sync-alt"></i> Apply</button>
            
            <div class="rpt-export-actions">
                <button type="button" class="rpt-export-btn" onclick="exportReport('excel')">
                    <i class="fas fa-file-excel"></i> Export Excel
                </button>
                <button type="button" class="rpt-export-btn" onclick="exportReport('csv')">
                    <i class="fas fa-file-csv"></i> Export CSV
                </button>
                <button type="button" class="rpt-export-btn" onclick="printReport()">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </form>

        <!-- Printable Report Content -->
        <div class="rpt-printable">
            <?php
            // Active section
            $section = $_GET['section'] ?? 'activity_logs';
            $valid_sections = ['activity_logs', 'audit_trail', 'calendar'];
            if (!in_array($section, $valid_sections)) $section = 'activity_logs';
            ?>

            <!-- Section Tabs -->
            <div class="cr-section-tabs">
                <button class="cr-section-tab <?= $section === 'activity_logs' ? 'active' : '' ?>"
                        onclick="crSwitchSection('activity_logs')">
                    <i class="fas fa-history"></i> Activity Logs
                </button>
                <button class="cr-section-tab <?= $section === 'audit_trail' ? 'active' : '' ?>"
                        onclick="crSwitchSection('audit_trail')">
                    <i class="fas fa-shield-alt"></i> Audit Trail
                </button>
                <button class="cr-section-tab <?= $section === 'calendar' ? 'active' : '' ?>"
                        onclick="crSwitchSection('calendar')">
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
                                echo '<td><button class="rpt-export-btn" style="padding:4px 10px;font-size:10px;">Monitor</button></td>';
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
                                echo '<td><button class="rpt-export-btn" style="padding:4px 10px;font-size:10px;">Export</button></td>';
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
                // Fetch scheduled tasks (Job Orders + Deliveries)
                try {
                    // Job Orders
                    $jo_q = $pdo->prepare("
                        SELECT
                            'Job Order' AS task_type,
                            COALESCE(jo.job_order_number, CONCAT('JO-', jo.id)) AS task_ref,
                            jo.service_description AS task_description,
                            COALESCE(jo.customer_name, c.name, 'Walk-in') AS customer_name,
                            jo.status,
                            jo.created_at AS scheduled_date,
                            COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unassigned') AS assigned_to
                        FROM job_orders jo
                        LEFT JOIN customers c ON jo.customer_id = c.id
                        LEFT JOIN users u ON jo.user_id = u.id
                        WHERE jo.station_id = ?
                          AND DATE(jo.created_at) BETWEEN ? AND ?
                        ORDER BY jo.created_at DESC
                    ");
                    $jo_q->execute([$station_id, $date_start, $date_end]);
                    $jo_rows = $jo_q->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    // Deliveries
                    $del_q = $pdo->prepare("
                        SELECT
                            'Delivery' AS task_type,
                            COALESCE(d.delivery_ref, CONCAT('DEL-', d.id)) AS task_ref,
                            CONCAT(COALESCE(d.product, 'Unknown'), ' - ', d.quantity, ' ', COALESCE(d.unit, 'units')) AS task_description,
                            COALESCE(d.supplier, 'Unknown Supplier') AS customer_name,
                            d.status,
                            COALESCE(d.delivery_date, d.created_at) AS scheduled_date,
                            COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'System') AS assigned_to
                        FROM deliveries_oversight d
                        LEFT JOIN users u ON d.encoded_by = u.id
                        WHERE d.station_id = ?
                          AND DATE(COALESCE(d.delivery_date, d.created_at)) BETWEEN ? AND ?
                        ORDER BY COALESCE(d.delivery_date, d.created_at) DESC
                    ");
                    $del_q->execute([$station_id, $date_start, $date_end]);
                    $del_rows = $del_q->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    // Merge both arrays
                    $calendar_rows = array_merge($jo_rows, $del_rows);
                    // Sort by date
                    usort($calendar_rows, function($a, $b) {
                        return strtotime($b['scheduled_date']) - strtotime($a['scheduled_date']);
                    });
                } catch (Exception $e) {
                    $calendar_rows = [];
                }
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
                                echo '<td><button class="rpt-export-btn" style="padding:4px 10px;font-size:10px;">Approve</button></td>';
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
function crSwitchSection(sectionKey) {
    // Hide all panels
    document.querySelectorAll('.cr-section-panel').forEach(p => p.classList.remove('active'));
    // Show selected panel
    const panel = document.getElementById('cr-panel-' + sectionKey);
    if (panel) panel.classList.add('active');
    
    // Update tab buttons
    document.querySelectorAll('.cr-section-tab').forEach(btn => btn.classList.remove('active'));
    event.target.closest('.cr-section-tab').classList.add('active');
    
    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('section', sectionKey);
    window.history.pushState({}, '', url);
}

function exportReport(type) {
    if (typeof XLSX === 'undefined') {
        alert('Export library not loaded. Please refresh the page and try again.');
        return;
    }
    const wrap = document.querySelector('.rpt-printable');
    if (!wrap) { alert('No report content found.'); return; }

    const activePanel = wrap.querySelector('.cr-section-panel.active') || wrap;
    const tables = Array.from(activePanel.querySelectorAll('table')).filter(
        t => t.querySelector('tbody tr')
    );

    if (!tables.length) { alert('No table data found to export.'); return; }

    const section  = new URL(window.location).searchParams.get('section') || 'activity_logs';
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo   = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Manager_Compliance_Report_${section}_${dateFrom}_to_${dateTo}`;

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
        XLSX.utils.book_append_sheet(wb, ws, `Report_${i + 1}`);
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
    const wrap   = document.querySelector('.rpt-printable');
    const active = wrap?.querySelector('.cr-section-panel.active') || wrap;
    if (!active) { window.print(); return; }

    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Manager Compliance Report</title>
    <style>
        @page{size:legal portrait;margin:.3in .4in;}
        *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}
        body{font-family:Arial,sans-serif;font-size:11px;color:#000;background:white;margin:0;padding:0;}
        .cr-section-tabs{display:none !important;}
        div[style*="text-align:center"]{text-align:center;padding:10px 0 8px;border-bottom:2px solid #000;margin-bottom:12px;}
        table{width:100%;border-collapse:collapse;font-size:9.5px;margin-bottom:6px;}
        thead tr{background:#f0f0f0 !important;border-top:2px solid #000;border-bottom:1px solid #999;}
        thead th{padding:6px 5px;text-align:left;font-weight:700;font-size:9px;text-transform:uppercase;}
        tbody tr{border-bottom:1px solid #ddd;}
        tbody td{padding:5px;}
        tfoot tr{border-top:2px solid #000;background:#f0f0f0 !important;}
        tfoot td{padding:6px 5px;font-weight:700;}
        .cr-empty{text-align:center;padding:12px;color:#888;font-style:italic;}
        .cr-badge{padding:2px 6px;border-radius:3px;font-size:9px;font-weight:700;}
    </style></head><body>${active.innerHTML}</body></html>`);
    w.document.close();
    w.focus();
    setTimeout(() => { w.print(); w.close(); }, 500);
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
