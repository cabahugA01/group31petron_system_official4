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
$valid_sections = ['activity_logs'];
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
    background: #00264D !important;
    color: #ffffff !important;
    border: 1px solid #00264D !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
}

.rpt-filter-bar button:hover {
    background: #001933 !important;
    color: #ffffff !important;
}

.rpt-filter-bar button i {
    color: #ffffff !important;
}

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

/* Excel Button - Green outline */
.rpt-export-btn:nth-child(1) {
    color: #16a34a !important;
    border-color: #16a34a !important;
    background: #ffffff !important;
}
.rpt-export-btn:nth-child(1):hover {
    background: #f0fdf4 !important;
    border-color: #15803d !important;
    color: #15803d !important;
}

/* CSV Button - Clean slate border */
.rpt-export-btn:nth-child(2) {
    color: #00264D !important;
    border-color: #cbd5e1 !important;
    background: #ffffff !important;
}
.rpt-export-btn:nth-child(2):hover {
    background: #f8fafc !important;
    border-color: #00264D !important;
    color: #00264D !important;
}

/* PDF Button - Clean slate border */
.rpt-export-btn:nth-child(3) {
    color: #00264D !important;
    border-color: #cbd5e1 !important;
    background: #ffffff !important;
}
.rpt-export-btn:nth-child(3):hover {
    background: #f8fafc !important;
    border-color: #00264D !important;
    color: #00264D !important;
}

/* Print Button - Clean slate border */
.rpt-export-btn:nth-child(4) {
    color: #00264D !important;
    border-color: #cbd5e1 !important;
    background: #ffffff !important;
}
.rpt-export-btn:nth-child(4):hover {
    background: #f8fafc !important;
    border-color: #00264D !important;
    color: #00264D !important;
}

.rpt-export-btn i { margin-right: 3px !important; }

/* Compliance Section Tabs */
.cr-section-tabs{display:flex;flex-wrap:wrap;background:#ffffff;border-top:1px solid #cbd5e1;border-bottom:2px solid #00264D;padding:0;margin:0;}
.cr-section-tab{padding:12px 24px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#334155 !important;background:#ffffff !important;border:none !important;border-right:1px solid #e2e8f0 !important;border-radius:0 !important;cursor:pointer;white-space:nowrap;transition:all .15s ease;display:inline-flex;align-items:center;gap:8px;}
.cr-section-tab i{font-size:11px;color:#64748b !important;}
.cr-section-tab:hover{background:#f8fafc !important;color:#00264D !important;}
.cr-section-tab:hover i{color:#00264D !important;}
.cr-section-tab.active{background:#00264D !important;color:#ffffff !important;font-weight:800;border-right-color:#00264D !important;}
.cr-section-tab.active i{color:#ffffff !important;}

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
    @page { size: A4 portrait; margin: 0.3in 0.4in; }
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


            <!-- Activity Logs Section -->
            <div id="cr-panel-activity_logs" class="cr-section-panel active">
                <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
                    <div style="font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
                        AUDIT TRAIL REPORT
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
                        CONSOLIDATED COMPLIANCE & AUDIT LOGS
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
                        WHERE (u.station_id = ? OR u.station_id IS NULL OR ? = 0 OR LOWER(u.role) IN ('admin','superadmin','manager'))
                          AND DATE(al.created_at) BETWEEN ? AND ?
                        ORDER BY al.created_at DESC
                        LIMIT 400
                    ");
                    $q->execute([$station_id, $station_id, $date_start, $date_end]);
                    $act_a = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
                        WHERE (u.station_id = ? OR u.station_id IS NULL OR ? = 0 OR LOWER(u.role) IN ('admin','superadmin','manager'))
                          AND DATE(al2.created_at) BETWEEN ? AND ?
                        ORDER BY al2.created_at DESC
                        LIMIT 400
                    ");
                    $q2->execute([$station_id, $station_id, $date_start, $date_end]);
                    $act_b = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $seen = [];
                    $other = [];
                    foreach (array_merge($act_a, $act_b) as $row) {
                        $act_lower = strtolower(trim((string)$row['action']));
                        if (in_array($act_lower, ['login', 'logout', 'clock in', 'clock out'], true)) {
                            $ts_bucket = floor(strtotime($row['created_at']) / 5);
                            $key = strtolower(trim((string)$row['staff_name'])) . '_' . $act_lower . '_' . $ts_bucket;
                            if (isset($seen[$key])) {
                                if (strlen($row['details']) > strlen($seen[$key]['details'])) {
                                    $seen[$key] = $row;
                                }
                                continue;
                            }
                            $seen[$key] = $row;
                        } else {
                            $other[] = $row;
                        }
                    }
                    $activity_rows = array_merge(array_values($seen), $other);
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
