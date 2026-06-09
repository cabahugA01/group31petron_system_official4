<?php
// ============================================================
// Admin Pump Master Oversight
// Fetch Source: fuel_pumps (calibration data) + fuel_adjustments type=calibration
// ============================================================
$page_id = 'admin_pump_master_oversight';
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

// ── Station Filter ──────────────────────────────────────────
$filter_station = isset($_GET['station']) ? (int)$_GET['station'] : $station_id;
if ($role === 'superadmin' && !isset($_GET['station'])) {
    $filter_station = 0; // Default to all stations for superadmin
}

$export   = trim($_GET['export']    ?? '');
$tab      = trim($_GET['tab']       ?? 'pumps');

// ── Get Station Name ──────────────────────────────────────
$station_name = 'All Stations';
if ($filter_station > 0) {
    try {
        $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
        $sn->execute([$filter_station]);
        $station_name = $sn->fetchColumn() ?: 'Station';
    } catch (Exception $e) {}
}

// ── Summary Counts ───────────────────────────────────────────
$total_pumps = 0; $calibrated_pumps = 0; $active_pumps = 0; $cal_adj_count = 0;
try {
    $sc_sql = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN calibration_value IS NOT NULL AND calibration_value > 0 THEN 1 ELSE 0 END) as calibrated
        FROM fuel_pumps";
    if ($filter_station > 0) {
        $sc = $pdo->prepare($sc_sql . " WHERE station_id=?");
        $sc->execute([$filter_station]);
    } else {
        $sc = $pdo->query($sc_sql);
    }
    $sc_row = $sc->fetch(PDO::FETCH_ASSOC);
    $total_pumps      = (int)($sc_row['total']     ?? 0);
    $active_pumps     = (int)($sc_row['active']    ?? 0);
    $calibrated_pumps = (int)($sc_row['calibrated'] ?? 0);
} catch (Exception $e) {}

try {
    $cac_sql = "SELECT COUNT(*) FROM fuel_adjustments WHERE LOWER(adjustment_type)='calibration'";
    if ($filter_station > 0) {
        $cac = $pdo->prepare($cac_sql . " AND station_id=?");
        $cac->execute([$filter_station]);
    } else {
        $cac = $pdo->query($cac_sql);
    }
    $cal_adj_count = (int)$cac->fetchColumn();
} catch (Exception $e) {}

// ── Fetch Pumps with Calibration Info ────────────────────────
$pumps = [];
try {
    $p_sql = "SELECT fp.id, fp.pump_number, fp.status,
        fp.calibration_value, fp.calibration_notes,
        fp.calibration_updated_at,
        ft.name AS fuel_type_name,
        u.name  AS calibrated_by_name,
        s.name  AS station_name
        FROM fuel_pumps fp
        LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
        LEFT JOIN users u       ON fp.calibration_updated_by = u.id
        LEFT JOIN stations s    ON fp.station_id = s.id";
    
    if ($filter_station > 0) {
        $stmt = $pdo->prepare($p_sql . " WHERE fp.station_id = ? ORDER BY fp.pump_number ASC");
        $stmt->execute([$filter_station]);
    } else {
        $stmt = $pdo->query($p_sql . " ORDER BY fp.pump_number ASC");
    }
    $pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fetch Calibration Adjustment Logs ────────────────────────
$cal_logs = [];
try {
    $l_sql = "SELECT fa.id, fa.adjustment_date, fa.fuel_type, fa.liters,
        fa.reason, fa.status, fa.created_at, fa.fuel_type_id,
        mgr.name AS manager_name, ft2.name AS fuel_type_label,
        s.name AS station_name
        FROM fuel_adjustments fa
        LEFT JOIN users mgr  ON fa.user_id     = mgr.id
        LEFT JOIN fuel_types ft2 ON fa.fuel_type_id = ft2.id
        LEFT JOIN stations s    ON fa.station_id = s.id
        WHERE LOWER(fa.adjustment_type)='calibration'";
    
    if ($filter_station > 0) {
        $stmt2 = $pdo->prepare($l_sql . " AND fa.station_id=? ORDER BY fa.created_at DESC LIMIT 200");
        $stmt2->execute([$filter_station]);
    } else {
        $stmt2 = $pdo->query($l_sql . " ORDER BY fa.created_at DESC LIMIT 200");
    }
    $cal_logs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Get All Stations (for filter) ─────────────────────────
$stations = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'Active' ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── EXPORT ───────────────────────────────────────────────────
if (in_array($export, ['csv','excel','pdf'])) {
    if ($tab === 'logs') {
        $headers = ['ID','Date','Station','Fuel Type','Liters','Reason/Notes','Manager','Logged At'];
        $rows_fmt = [];
        foreach($cal_logs as $log) {
            $fl = $log['fuel_type_label'] ?: $log['fuel_type'] ?: '—';
            $rows_fmt[] = ['#'.$log['id'], date('M d,Y', strtotime($log['adjustment_date'])),
                $log['station_name'] ?? '—', $fl,
                number_format($log['liters'],2).' L', $log['reason'] ?? '—',
                $log['manager_name'] ?? '—', date('M d,Y H:i', strtotime($log['created_at']))];
        }
        $title = 'Calibration Adjustment Logs';
    } else {
        $headers = ['Pump #','Station','Fuel Type','Status','Calibration Value','Notes','Last Calibrated By','Calibrated At'];
        $rows_fmt = [];
        foreach($pumps as $p) {
            $rows_fmt[] = [$p['pump_number'], $p['station_name'] ?? '—', $p['fuel_type_name'] ?? '—', $p['status'] ?? '—',
                $p['calibration_value'] ? number_format($p['calibration_value'],4) : 'Not Set',
                $p['calibration_notes'] ?? '—', $p['calibrated_by_name'] ?? '—',
                $p['calibration_updated_at'] ? date('M d,Y H:i', strtotime($p['calibration_updated_at'])) : '—'];
        }
        $title = 'Pump Calibration Records';
    }
    $filename = 'pump_master_'.date('Ymd_His');

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
        $out = fopen('php://output','w'); fputs($out,"\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach($rows_fmt as $r) fputcsv($out, $r);
        fclose($out); exit;
    }
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>'.htmlspecialchars($title).'</h2><p>Station: '.$station_name.'</p>';
        echo '<table><thead><tr>';
        foreach($headers as $h) echo '<th>'.htmlspecialchars($h).'</th>';
        echo '</tr></thead><tbody>';
        foreach($rows_fmt as $r) { echo '<tr>'; foreach($r as $c) echo '<td>'.htmlspecialchars($c).'</td>'; echo '</tr>'; }
        echo '</tbody></table></body></html>'; exit;
    }
    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $tbody = '';
        if ($tab === 'logs') {
            foreach($cal_logs as $log) {
                $fl = $log['fuel_type_label'] ?: $log['fuel_type'] ?: '—';
                $lc = $log['liters'] < 0 ? 'color:#dc2626' : 'color:#16a34a';
                $tbody .= '<tr><td>#'.$log['id'].'</td><td>'.date('M d,Y',strtotime($log['adjustment_date'])).'</td>';
                $tbody .= '<td>'.htmlspecialchars($log['station_name']??'—').'</td>';
                $tbody .= '<td>'.htmlspecialchars($fl).'</td>';
                $tbody .= '<td style="text-align:right;font-weight:700;'.$lc.'">'.number_format($log['liters'],2).' L</td>';
                $tbody .= '<td>'.htmlspecialchars(substr($log['reason']??'—',0,60)).'</td>';
                $tbody .= '<td>'.htmlspecialchars($log['manager_name']??'—').'</td>';
                $tbody .= '<td>'.date('M d,Y',strtotime($log['created_at'])).'</td></tr>';
            }
            $col_count = 8;
        } else {
            foreach($pumps as $p) {
                $st_c = ($p['status']==='Active') ? '#16a34a' : '#dc2626';
                $tbody .= '<tr><td style="font-weight:700;">'.htmlspecialchars($p['pump_number']).'</td>';
                $tbody .= '<td>'.htmlspecialchars($p['station_name']??'—').'</td>';
                $tbody .= '<td>'.htmlspecialchars($p['fuel_type_name']??'—').'</td>';
                $tbody .= '<td style="color:'.$st_c.';font-weight:700;">'.htmlspecialchars($p['status']??'—').'</td>';
                $tbody .= '<td style="text-align:center;font-weight:700;">'.($p['calibration_value'] ? number_format($p['calibration_value'],4) : '<span style="color:#94a3b8">Not Set</span>').'</td>';
                $tbody .= '<td>'.htmlspecialchars(substr($p['calibration_notes']??'—',0,50)).'</td>';
                $tbody .= '<td>'.htmlspecialchars($p['calibrated_by_name']??'—').'</td>';
                $tbody .= '<td>'.($p['calibration_updated_at'] ? date('M d,Y',strtotime($p['calibration_updated_at'])) : '—').'</td></tr>';
            }
            $col_count = 8;
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Pump Master Oversight</title>
        <style>body{font-family:Arial,sans-serif;font-size:11px;padding:20px}.pbtn{margin-bottom:12px}
        @media print{.pbtn{display:none}}.hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px}
        h1{color:#002F6C;font-size:18px;margin:0 0 4px}table{width:100%;border-collapse:collapse}
        th{background:#002F6C;color:#fff;padding:6px 8px;font-size:9px;text-transform:uppercase;text-align:left}
        td{padding:5px 8px;border-bottom:1px solid #e2e8f0}tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer">🖨 Print / Save PDF</button>
        <a href="javascript:history.back()" style="margin-left:8px;background:#6c757d;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;text-decoration:none">← Back</a></div>';
        echo '<div class="hdr"><h1>Pump Master Oversight — '.htmlspecialchars($title).'</h1><p>Station: '.htmlspecialchars($station_name).'</p></div>';
        echo '<table><thead><tr>';
        foreach($headers as $h) echo '<th>'.htmlspecialchars($h).'</th>';
        echo '</tr></thead><tbody>'.($tbody ?: '<tr><td colspan="'.$col_count.'" style="text-align:center;padding:20px;color:#94a3b8">No records.</td></tr>').'</tbody></table>';
        echo '</body></html>'; exit;
    }
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Admin Pump Master Oversight ── */
.apmo-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.apmo-head h1 { margin:0 0 4px; font-size:22px; font-weight:700; color:#00264D; display:flex; align-items:center; gap:9px; }
.apmo-subtitle { font-size:13px; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; }
.apmo-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.apmo-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; border:none; text-decoration:none; transition:all .13s; height:36px; white-space:nowrap; }
.apmo-btn-excel  { background:#1d6f42; color:#fff; }  .apmo-btn-excel:hover  { background:#155a34; color:#fff; }
.apmo-btn-csv    { background:#003d7a; color:#fff; }  .apmo-btn-csv:hover    { background:#002a58; color:#fff; }
.apmo-btn-pdf    { background:#dc2626; color:#fff; }  .apmo-btn-pdf:hover    { background:#b91c1c; color:#fff; }
.apmo-btn-back   { background:#6c757d; color:#fff; }  .apmo-btn-back:hover   { background:#545b62; color:#fff; }
.apmo-btn-filter { background:#002F6C; color:#fff; }  .apmo-btn-filter:hover { background:#001f4d; color:#fff; }
.apmo-summary-tbl { width:100%; border-collapse:collapse; margin-bottom:16px; font-size:13px; }
.apmo-summary-tbl th { background:#002F6C; color:#fff; padding:8px 14px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
.apmo-summary-tbl td { padding:9px 14px; border-bottom:1px solid #e2e8f0; color:#1e293b; font-weight:600; font-size:15px; }
.apmo-summary-tbl td small { display:block; font-size:11px; color:#64748b; font-weight:400; }
.apmo-tabs { display:flex; gap:0; margin-bottom:16px; border-bottom:2px solid #e2e8f0; }
.apmo-tab  { padding:10px 20px; font-size:12px; font-weight:600; color:#64748b; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .15s; text-transform:uppercase; letter-spacing:.3px; text-decoration:none; }
.apmo-tab:hover { color:#00264D; }
.apmo-tab.active { color:#00264D; border-bottom-color:#002F6C; background:transparent; }
.apmo-filter { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px; }
.apmo-fg { display:flex; flex-direction:column; gap:3px; }
.apmo-fg label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.apmo-fg input, .apmo-fg select { padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; font-size:12px; }
/* Force no horizontal scroll */
html, body { max-width:100vw; overflow-x:hidden; }
.container { max-width:100%; overflow-x:hidden; }
.apmo-table-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); width:100%; }
.apmo-table-hd { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
.apmo-table-title { font-size:13px; font-weight:700; color:#00264D; text-transform:uppercase; letter-spacing:.3px; margin:0; }
.apmo-tbl-wrap { width:100%; overflow-x:hidden; }
.apmo-tbl { width:100%; table-layout:fixed; border-collapse:collapse; font-size:13px; }
.apmo-tbl thead tr { background:#002F6C; }
.apmo-tbl thead th { padding:10px 8px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.3px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.4; }
.apmo-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.apmo-tbl tbody tr:hover { background:#eff6ff; }
.apmo-tbl tbody td { padding:10px 8px; color:#334155; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.5; }
.apmo-badge { display:inline-block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
.bg-green  { color:#15803d; } .bg-red    { color:#b91c1c; }
.bg-gray   { color:#475569; } .bg-indigo { color:#4338ca; }
.bg-amber  { color:#a16207; }
.apmo-empty { text-align:center; padding:60px 20px; color:#94a3b8; }
.apmo-empty i { font-size:44px; display:block; margin-bottom:14px; opacity:.4; }
.cal-set   { font-weight:700; color:#002F6C; font-family:monospace; }
.cal-unset { color:#94a3b8; font-style:italic; }
</style>

<div class="apmo-head">
    <div>
        <h1><i class="fas fa-cog"></i> Pump Master Oversight</h1>
        <div class="apmo-subtitle">SUPERVISE PUMP ASSIGNMENTS, OPERATIONAL STATUS, AND ENSURE ALIGNMENT WITH VALIDATED TRANSACTIONS.</div>
    </div>
    <div class="apmo-actions">
        <a href="?tab=<?= htmlspecialchars($tab) ?>&<?= http_build_query(array_merge(array_diff_key($_GET,['export'=>'']),['export'=>'excel'])) ?>" class="apmo-btn apmo-btn-excel"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="?tab=<?= htmlspecialchars($tab) ?>&<?= http_build_query(array_merge(array_diff_key($_GET,['export'=>'']),['export'=>'csv'])) ?>"   class="apmo-btn apmo-btn-csv"><i class="fas fa-file-csv"></i> CSV</a>
        <a href="?tab=<?= htmlspecialchars($tab) ?>&<?= http_build_query(array_merge(array_diff_key($_GET,['export'=>'']),['export'=>'pdf'])) ?>"   class="apmo-btn apmo-btn-pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        <a href="admin_dashboard.php" class="apmo-btn apmo-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- Summary Table -->
<table class="apmo-summary-tbl">
    <thead>
        <tr>
            <th>Total Pumps</th>
            <th>Active Pumps</th>
            <th>With Calibration Set</th>
            <th>Calibration Adjustments Logged</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><?= number_format($total_pumps) ?><small>Registered pumps</small></td>
            <td><?= number_format($active_pumps) ?><small>Operational</small></td>
            <td><?= number_format($calibrated_pumps) ?><small>Pumps with calibration value</small></td>
            <td><?= number_format($cal_adj_count) ?><small>Manager-logged adjustments</small></td>
        </tr>
    </tbody>
</table>

<!-- Filter Bar -->
<form method="get" class="apmo-filter">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <?php if ($role === 'superadmin' && !empty($stations)): ?>
    <div class="apmo-fg">
        <label>Station</label>
        <select name="station">
            <option value="0" <?= $filter_station == 0 ? 'selected' : '' ?>>All Stations</option>
            <?php foreach ($stations as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filter_station == $s['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="apmo-btn apmo-btn-filter"><i class="fas fa-filter"></i> Apply</button>
    <a href="admin_pump_master_oversight.php?tab=<?= htmlspecialchars($tab) ?>" class="apmo-btn apmo-btn-back"><i class="fas fa-times"></i> Reset</a>
</form>

<!-- Tabs -->
<div class="apmo-tabs">
    <a href="?tab=pumps&station=<?= $filter_station ?>" class="apmo-tab <?= $tab==='pumps'?'active':'' ?>"><i class="fas fa-gas-pump"></i> Pump Calibration Records</a>
    <a href="?tab=logs&station=<?= $filter_station ?>"  class="apmo-tab <?= $tab==='logs' ?'active':'' ?>"><i class="fas fa-list"></i> Calibration Adjustment Logs</a>
</div>

<?php if ($tab === 'pumps'): ?>
<!-- ── Pumps Tab ── -->
<div class="apmo-table-card">
    <div class="apmo-table-hd">
        <h3 class="apmo-table-title"><i class="fas fa-table"></i> Fuel Pumps — Calibration Status</h3>
        <span style="font-size:11px;color:#64748b;"><?= number_format(count($pumps)) ?> pump(s)</span>
    </div>
    <?php if (empty($pumps)): ?>
    <div class="apmo-empty">
        <i class="fas fa-gas-pump"></i>
        <div style="font-size:15px;font-weight:700;color:#64748b;margin-bottom:4px;">No pumps registered</div>
        <div style="font-size:13px;">No pumps found for the selected station.</div>
    </div>
    <?php else: ?>
    <div class="apmo-tbl-wrap">
        <table class="apmo-tbl" id="pumpsTable">
            <colgroup>
                <col style="width:9%"><col style="width:13%"><col style="width:10%"><col style="width:8%">
                <col style="width:12%"><col style="width:20%"><col style="width:16%"><col style="width:12%">
            </colgroup>
            <thead>
                <tr>
                    <th>Pump #</th>
                    <th>Station</th>
                    <th>Fuel Type</th>
                    <th>Status</th>
                    <th>Calibration Value</th>
                    <th>Calibration Notes</th>
                    <th>Last Calibrated By</th>
                    <th>Calibrated At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($pumps as $p):
                    $st_badge = match($p['status'] ?? '') {
                        'Active'      => 'bg-green',
                        'Inactive'    => 'bg-red',
                        'Maintenance' => 'bg-amber',
                        default       => 'bg-gray',
                    };
                    $has_cal = ($p['calibration_value'] !== null && $p['calibration_value'] > 0);
                ?>
                <tr>
                    <td style="font-weight:700;color:#002F6C;"><?= htmlspecialchars($p['pump_number']) ?></td>
                    <td><?= htmlspecialchars($p['station_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($p['fuel_type_name'] ?? '—') ?></td>
                    <td><span class="apmo-badge <?= $st_badge ?>"><?= htmlspecialchars($p['status'] ?? 'N/A') ?></span></td>
                    <td>
                        <?php if ($has_cal): ?>
                            <span class="cal-set"><?= number_format($p['calibration_value'], 4) ?></span>
                        <?php else: ?>
                            <span class="cal-unset">Not Set</span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;color:#64748b;" title="<?= htmlspecialchars($p['calibration_notes'] ?? '') ?>">
                        <?= htmlspecialchars(substr($p['calibration_notes'] ?? '—', 0, 45)) ?><?= strlen($p['calibration_notes'] ?? '') > 45 ? '…' : '' ?>
                    </td>
                    <td><?= htmlspecialchars($p['calibrated_by_name'] ?? '—') ?></td>
                    <td style="color:#64748b;"><?= $p['calibration_updated_at'] ? date('M d, Y H:i', strtotime($p['calibration_updated_at'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination Controls -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid #f1f5f9;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:12px;color:#64748b;font-weight:600;">Rows per page:</label>
            <select id="rowsPerPagePumps" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;cursor:pointer;">
                <option value="10">10</option>
                <option value="20" selected>20</option>
                <option value="30">30</option>
                <option value="40">40</option>
                <option value="50">50</option>
            </select>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span id="pageInfoPumps" style="font-size:12px;color:#64748b;font-weight:600;">Page 1 of 1</span>
            <div style="display:flex;gap:4px;">
                <button id="prevPagePumps" class="apmo-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;" disabled>
                    <i class="fas fa-chevron-left"></i> Prev
                </button>
                <button id="nextPagePumps" class="apmo-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;">
                    Next <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ── Calibration Logs Tab ── -->
<div class="apmo-table-card">
    <div class="apmo-table-hd">
        <h3 class="apmo-table-title"><i class="fas fa-history"></i> Calibration Adjustment Logs</h3>
        <span style="font-size:11px;color:#64748b;"><?= number_format(count($cal_logs)) ?> record(s)</span>
    </div>
    <?php if (empty($cal_logs)): ?>
    <div class="apmo-empty">
        <i class="fas fa-inbox"></i>
        <div style="font-size:15px;font-weight:700;color:#64748b;margin-bottom:4px;">No calibration logs</div>
        <div style="font-size:13px;">No calibration adjustments have been logged yet.</div>
    </div>
    <?php else: ?>
    <div class="apmo-tbl-wrap">
        <table class="apmo-tbl" id="logsTable">
            <colgroup>
                <col style="width:5%"><col style="width:10%"><col style="width:12%"><col style="width:9%">
                <col style="width:9%"><col style="width:22%"><col style="width:18%"><col style="width:15%">
            </colgroup>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Station</th>
                    <th>Fuel Type</th>
                    <th>Liters Adj.</th>
                    <th>Reason / Notes</th>
                    <th>Manager</th>
                    <th>Logged At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($cal_logs as $log):
                    $fl = $log['fuel_type_label'] ?: $log['fuel_type'] ?: '—';
                    $lc = ((float)$log['liters'] < 0) ? 'color:#dc2626;' : 'color:#16a34a;';
                ?>
                <tr>
                    <td style="color:#475569;">#<?= $log['id'] ?></td>
                    <td><?= date('M d, Y', strtotime($log['adjustment_date'])) ?></td>
                    <td><?= htmlspecialchars($log['station_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($fl) ?></td>
                    <td style="font-weight:700;font-family:monospace;<?= $lc ?>">
                        <?= ((float)$log['liters'] > 0 ? '+' : '') . number_format($log['liters'],2) ?> L
                    </td>
                    <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($log['reason'] ?? '') ?>">
                        <?= htmlspecialchars(substr($log['reason'] ?? '—', 0, 60)) ?><?= strlen($log['reason'] ?? '') > 60 ? '…' : '' ?>
                    </td>
                    <td><?= htmlspecialchars($log['manager_name'] ?? '—') ?></td>
                    <td style="color:#64748b;"><?= date('M d, Y H:i', strtotime($log['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination Controls -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid #f1f5f9;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:12px;color:#64748b;font-weight:600;">Rows per page:</label>
            <select id="rowsPerPageLogs" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;cursor:pointer;">
                <option value="10">10</option>
                <option value="20" selected>20</option>
                <option value="30">30</option>
                <option value="40">40</option>
                <option value="50">50</option>
            </select>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span id="pageInfoLogs" style="font-size:12px;color:#64748b;font-weight:600;">Page 1 of 1</span>
            <div style="display:flex;gap:4px;">
                <button id="prevPageLogs" class="apmo-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;" disabled>
                    <i class="fas fa-chevron-left"></i> Prev
                </button>
                <button id="nextPageLogs" class="apmo-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;">
                    Next <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Pagination for Pumps Table
(function() {
    const table = document.querySelector('#pumpsTable tbody');
    if (!table) return;
    
    const allRows = Array.from(table.querySelectorAll('tr'));
    let currentPage = 1;
    let rowsPerPage = 20;
    
    const rowsSelect = document.getElementById('rowsPerPagePumps');
    const pageInfo = document.getElementById('pageInfoPumps');
    const prevBtn = document.getElementById('prevPagePumps');
    const nextBtn = document.getElementById('nextPagePumps');
    
    if (!rowsSelect || !pageInfo || !prevBtn || !nextBtn) return;
    
    function updateTable() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        
        allRows.forEach(row => row.style.display = 'none');
        allRows.slice(start, end).forEach(row => row.style.display = '');
        
        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        prevBtn.disabled = currentPage === 1;
        nextBtn.disabled = currentPage === totalPages;
        prevBtn.style.opacity = prevBtn.disabled ? '0.5' : '1';
        prevBtn.style.cursor = prevBtn.disabled ? 'not-allowed' : 'pointer';
        nextBtn.style.opacity = nextBtn.disabled ? '0.5' : '1';
        nextBtn.style.cursor = nextBtn.disabled ? 'not-allowed' : 'pointer';
    }
    
    rowsSelect.addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        updateTable();
    });
    
    prevBtn.addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            updateTable();
            document.querySelector('.apmo-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    nextBtn.addEventListener('click', function() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updateTable();
            document.querySelector('.apmo-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    document.querySelectorAll('.apmo-page-btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            if (!this.disabled) {
                this.style.background = '#f1f5f9';
                this.style.borderColor = '#cbd5e1';
            }
        });
        btn.addEventListener('mouseleave', function() {
            this.style.background = '#fff';
            this.style.borderColor = '#e2e8f0';
        });
    });
    
    updateTable();
})();

// Pagination for Logs Table
(function() {
    const table = document.querySelector('#logsTable tbody');
    if (!table) return;
    
    const allRows = Array.from(table.querySelectorAll('tr'));
    let currentPage = 1;
    let rowsPerPage = 20;
    
    const rowsSelect = document.getElementById('rowsPerPageLogs');
    const pageInfo = document.getElementById('pageInfoLogs');
    const prevBtn = document.getElementById('prevPageLogs');
    const nextBtn = document.getElementById('nextPageLogs');
    
    if (!rowsSelect || !pageInfo || !prevBtn || !nextBtn) return;
    
    function updateTable() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        
        allRows.forEach(row => row.style.display = 'none');
        allRows.slice(start, end).forEach(row => row.style.display = '');
        
        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        prevBtn.disabled = currentPage === 1;
        nextBtn.disabled = currentPage === totalPages;
        prevBtn.style.opacity = prevBtn.disabled ? '0.5' : '1';
        prevBtn.style.cursor = prevBtn.disabled ? 'not-allowed' : 'pointer';
        nextBtn.style.opacity = nextBtn.disabled ? '0.5' : '1';
        nextBtn.style.cursor = nextBtn.disabled ? 'not-allowed' : 'pointer';
    }
    
    rowsSelect.addEventListener('change', function() {
        rowsPerPage = parseInt(this.value);
        currentPage = 1;
        updateTable();
    });
    
    prevBtn.addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            updateTable();
            document.querySelector('.apmo-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    nextBtn.addEventListener('click', function() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updateTable();
            document.querySelector('.apmo-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    updateTable();
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
