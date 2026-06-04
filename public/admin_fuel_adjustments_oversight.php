<?php
// ============================================================
// Admin Fuel Adjustments Oversight
// Fetch Source: fuel_adjustments (manager-logged/approved)
// ============================================================
$page_id = 'admin_fuel_adjustments_oversight';
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

// ── Handle Approve Action ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_adj') {
    $adj_id = (int)($_POST['adj_id'] ?? 0);
    if ($adj_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE fuel_adjustments SET status='Approved', approved_by=?, approved_at=NOW() WHERE id=?");
            $stmt->execute([$me['id'], $adj_id]);
            $_SESSION['success'] = 'Adjustment #'.$adj_id.' approved successfully.';
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error approving adjustment: '.$e->getMessage();
        }
    }
    header('Location: admin_fuel_adjustments_oversight.php');
    exit;
}

$msg_success = $_SESSION['success'] ?? '';
$msg_error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ── Station Filter ──────────────────────────────────────────
$filter_station = isset($_GET['station']) ? (int)$_GET['station'] : $station_id;
if ($role === 'superadmin' && !isset($_GET['station'])) {
    $filter_station = 0; // Default to all stations for superadmin
}

// ── Filters ──────────────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));
$adj_type  = trim($_GET['type']      ?? '');
$export    = trim($_GET['export']    ?? '');

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
$counts = ['delivery'=>0,'verified_sale'=>0,'unverified_sale'=>0,'calibration'=>0,'physical_inventory'=>0,'price_update'=>0,'adjusted_reading'=>0,'rejected_reading'=>0,'other'=>0,'total'=>0];
try {
    $sc_sql = "SELECT adjustment_type, COUNT(*) as n FROM fuel_adjustments";
    if ($filter_station > 0) {
        $sc = $pdo->prepare($sc_sql . " WHERE station_id=? GROUP BY adjustment_type");
        $sc->execute([$filter_station]);
    } else {
        $sc = $pdo->query($sc_sql . " GROUP BY adjustment_type");
    }
    foreach ($sc->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = strtolower($r['adjustment_type']);
        if (array_key_exists($t, $counts)) {
            $counts[$t] = (int)$r['n'];
            $counts['total'] += (int)$r['n'];
        }
    }
} catch (Exception $e) {}

// ── Fetch Adjustments ────────────────────────────────────────
$where_records  = ["LOWER(fa.status) != 'pending'", "DATE(fa.adjustment_date) BETWEEN ? AND ?"];
$params_records = [$date_from, $date_to];

if ($filter_station > 0) {
    $where_records[] = "fa.station_id = ?";
    $params_records[] = $filter_station;
}
if ($adj_type !== '') {
    $where_records[] = "fa.adjustment_type = ?";
    $params_records[] = $adj_type;
}

$records_adjustments = [];
try {
    $stmt = $pdo->prepare("SELECT fa.id, fa.adjustment_date, fa.fuel_type, fa.adjustment_type,
        fa.liters, fa.reason, fa.status, fa.notes, fa.created_at,
        fa.approved_at, mgr.name AS manager_name, appr.name AS approved_by_name,
        ft_name.name AS fuel_type_label, s.name AS station_name
        FROM fuel_adjustments fa
        LEFT JOIN users mgr  ON fa.user_id     = mgr.id
        LEFT JOIN users appr ON fa.approved_by = appr.id
        LEFT JOIN fuel_types ft_name ON fa.fuel_type_id = ft_name.id
        LEFT JOIN stations s ON fa.station_id = s.id
        WHERE " . implode(' AND ', $where_records) . "
        ORDER BY fa.adjustment_date DESC, fa.id DESC LIMIT 500");
    $stmt->execute($params_records);
    $records_adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fetch Pending Adjustments (Admin Oversight)
$pending_adjustments = [];
$where_pending = ["LOWER(fa.status) = 'pending'"];
$params_pending = [];
if ($filter_station > 0) {
    $where_pending[] = "fa.station_id = ?";
    $params_pending[] = $filter_station;
}
try {
    $stmt = $pdo->prepare("SELECT fa.id, fa.adjustment_date, fa.fuel_type, fa.adjustment_type,
        fa.liters, fa.reason, fa.status, fa.notes, fa.created_at,
        fa.approved_at, mgr.name AS manager_name, appr.name AS approved_by_name,
        ft_name.name AS fuel_type_label, s.name AS station_name
        FROM fuel_adjustments fa
        LEFT JOIN users mgr  ON fa.user_id     = mgr.id
        LEFT JOIN users appr ON fa.approved_by = appr.id
        LEFT JOIN fuel_types ft_name ON fa.fuel_type_id = ft_name.id
        LEFT JOIN stations s ON fa.station_id = s.id
        WHERE " . implode(' AND ', $where_pending) . "
        ORDER BY fa.created_at ASC");
    $stmt->execute($params_pending);
    $pending_adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Get All Stations (for filter) ─────────────────────────
$stations = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM stations WHERE status='active' ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── EXPORT ───────────────────────────────────────────────────
if (in_array($export, ['csv','excel','pdf'])) {
    $headers = ['ID','Adjustment Date','Station','Fuel Type','Type','Liters','Reason','Status','Logged By','Approved By','Approved At'];
    $rows_fmt = [];
    $export_data = $records_adjustments; // Usually you only export the finalized records, or maybe both? We'll export the records tab.
    foreach($export_data as $adj) {
        $fl = $adj['fuel_type_label'] ?: $adj['fuel_type'] ?: '—';
        $rows_fmt[] = [
            '#'.$adj['id'],
            date('M d, Y', strtotime($adj['adjustment_date'])),
            $adj['station_name'] ?? '—',
            $fl,
            ucwords(str_replace('_',' ',$adj['adjustment_type'])),
            number_format($adj['liters'],2).' L',
            $adj['reason'] ?? '—',
            ucfirst($adj['status'] ?? 'Approved'),
            $adj['manager_name'] ?? '—',
            $adj['approved_by_name'] ?? '—',
            $adj['approved_at'] ? date('M d, Y H:i', strtotime($adj['approved_at'])) : '—',
        ];
    }
    $filename = 'fuel_adjustments_'.$date_from.'_to_'.$date_to;

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
        $out = fopen('php://output','w');
        fputs($out,"\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach($rows_fmt as $r) fputcsv($out, $r);
        fclose($out); exit;
    }
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Fuel Adjustments Oversight</h2><p>Period: '.$date_from.' to '.$date_to.' | Station: '.$station_name.' | Records: '.count($rows_fmt).'</p>';
        echo '<table><thead><tr>';
        foreach($headers as $h) echo '<th>'.htmlspecialchars($h).'</th>';
        echo '</tr></thead><tbody>';
        foreach($rows_fmt as $r) { echo '<tr>'; foreach($r as $c) echo '<td>'.htmlspecialchars($c).'</td>'; echo '</tr>'; }
        echo '</tbody></table></body></html>'; exit;
    }
    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $tbody = '';
        foreach($adjustments as $adj) {
            $fl = $adj['fuel_type_label'] ?: $adj['fuel_type'] ?: '—';
            $lc = $adj['liters'] < 0 ? 'color:#dc2626' : 'color:#16a34a';
            $tbody .= '<tr>';
            $tbody .= '<td>#'.htmlspecialchars($adj['id']).'</td>';
            $tbody .= '<td>'.date('M d, Y', strtotime($adj['adjustment_date'])).'</td>';
            $tbody .= '<td>'.htmlspecialchars($adj['station_name'] ?? '—').'</td>';
            $tbody .= '<td>'.htmlspecialchars($fl).'</td>';
            $tbody .= '<td>'.htmlspecialchars(ucwords(str_replace('_',' ',$adj['adjustment_type']))).'</td>';
            $tbody .= '<td style="text-align:right;font-weight:700;'.$lc.'">'.number_format($adj['liters'],2).' L</td>';
            $tbody .= '<td>'.htmlspecialchars(substr($adj['reason']??'—',0,40)).'</td>';
            $tbody .= '<td>'.htmlspecialchars($adj['manager_name']??'—').'</td>';
            $tbody .= '<td>'.($adj['approved_at'] ? date('M d, Y', strtotime($adj['approved_at'])) : '—').'</td>';
            $tbody .= '</tr>';
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fuel Adjustments Oversight</title>
        <style>body{font-family:Arial,sans-serif;font-size:11px;padding:20px}
        .pbtn{margin-bottom:12px}@media print{.pbtn{display:none}}
        .hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px}
        h1{color:#002F6C;font-size:18px;margin:0 0 4px}
        table{width:100%;border-collapse:collapse}
        th{background:#002F6C;color:#fff;padding:6px 8px;font-size:9px;text-transform:uppercase;text-align:left}
        td{padding:5px 8px;border-bottom:1px solid #e2e8f0}
        tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer">🖨 Print / Save PDF</button>
        <a href="javascript:history.back()" style="margin-left:8px;background:#6c757d;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;text-decoration:none">← Back</a></div>';
        echo '<div class="hdr"><h1>Fuel Adjustments Oversight</h1><p>Period: '.htmlspecialchars($date_from).' — '.htmlspecialchars($date_to).' | Station: '.htmlspecialchars($station_name).' | Records: '.count($adjustments).'</p></div>';
        echo '<table><thead><tr><th>ID</th><th>Date</th><th>Station</th><th>Fuel Type</th><th>Type</th><th>Liters</th><th>Reason</th><th>Logged By</th><th>Approved At</th></tr></thead>';
        echo '<tbody>'.($tbody ?: '<tr><td colspan="9" style="text-align:center;padding:20px;color:#94a3b8">No records.</td></tr>').'</tbody></table>';
        echo '</body></html>'; exit;
    }
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Admin Fuel Adjustments Oversight ── */
.afao-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.afao-head h1 { margin:0 0 4px; font-size:22px; font-weight:700; color:#00264D; display:flex; align-items:center; gap:9px; }
.afao-subtitle { font-size:13px; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; }
.afao-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.afao-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; border:none; text-decoration:none; transition:all .13s; height:36px; white-space:nowrap; }
.afao-btn-excel  { background:#1d6f42; color:#fff; }  .afao-btn-excel:hover  { background:#155a34; color:#fff; }
.afao-btn-csv    { background:#003d7a; color:#fff; }  .afao-btn-csv:hover    { background:#002a58; color:#fff; }
.afao-btn-pdf    { background:#dc2626; color:#fff; }  .afao-btn-pdf:hover    { background:#b91c1c; color:#fff; }
.afao-btn-back   { background:#6c757d; color:#fff; }  .afao-btn-back:hover   { background:#545b62; color:#fff; }
.afao-btn-filter { background:#002F6C; color:#fff; }  .afao-btn-filter:hover { background:#001f4d; color:#fff; }
.afao-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:18px; }
.afao-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; padding:16px; display:flex; align-items:center; gap:14px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.afao-card.c-blue   { border-left:4px solid #1e40af; } .afao-card.c-green { border-left:4px solid #16a34a; }
.afao-card.c-orange { border-left:4px solid #ea580c; } .afao-card.c-indigo{ border-left:4px solid #4f46e5; }
.afao-card-ico { width:40px; height:40px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; color:#002F6C; }
.afao-card-meta h3 { margin:0; font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:.5px; font-weight:700; }
.afao-card-meta h2 { margin:2px 0 0; font-size:24px; font-weight:900; color:#00264D; line-height:1; }
.afao-card-meta span { font-size:11px; color:#94a3b8; }
.afao-filter { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px; }
.afao-fg { display:flex; flex-direction:column; gap:3px; }
.afao-fg label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.afao-fg input, .afao-fg select { padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; font-size:12px; }
/* Force no horizontal scroll */
html, body { max-width:100vw; overflow-x:hidden; }
.afao-table-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); max-width:100%; }
.afao-table-hd { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
.afao-table-title { font-size:13px; font-weight:700; color:#00264D; text-transform:uppercase; letter-spacing:.3px; margin:0; }
.afao-tbl { width:100%; table-layout:fixed; border-collapse:collapse; font-size:12px; }
.afao-tbl thead tr { background:#002F6C; }
.afao-tbl thead th { padding:9px 10px; text-align:left; font-size:10px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.afao-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.afao-tbl tbody tr:hover { background:#eff6ff; }
.afao-tbl tbody td { padding:9px 10px; color:#334155; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.afao-badge { display:inline-block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
.bg-blue   { color:#1d4ed8; } .bg-green { color:#15803d; }
.bg-orange { color:#c2410c; } .bg-indigo{ color:#4338ca; }
.bg-gray   { color:#475569; }
.afao-empty { text-align:center; padding:60px 20px; color:#94a3b8; }
.afao-empty i { font-size:44px; display:block; margin-bottom:14px; opacity:.4; }
.tab-btn { padding:12px 24px; font-size:14px; font-weight:700; color:#64748b; background:transparent; border:none; border-bottom:3px solid transparent; cursor:pointer; transition:all .15s; outline:none; }
.tab-btn:hover { color:#002F6C; }
.tab-btn.active { color:#002F6C; border-bottom-color:#002F6C; }
.tab-content { display:none; }
.tab-content.active { display:block; }
</style>

<div class="afao-head">
    <div>
        <h1><i class="fas fa-sliders-h"></i> Fuel Adjustments Oversight</h1>
        <div class="afao-subtitle">Manager-logged manual adjustments and reconciled variances</div>
    </div>
    <div class="afao-actions">
        <a href="admin_dashboard.php" class="afao-btn afao-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<?php if ($msg_success): ?>
<div style="padding:12px 16px; background:#dcfce7; border:1px solid #bbf7d0; color:#15803d; border-radius:8px; margin-bottom:20px; font-weight:600;"><i class="fas fa-check-circle" style="margin-right:6px;"></i><?= htmlspecialchars($msg_success) ?></div>
<?php endif; ?>
<?php if ($msg_error): ?>
<div style="padding:12px 16px; background:#fee2e2; border:1px solid #fecaca; color:#b91c1c; border-radius:8px; margin-bottom:20px; font-weight:600;"><i class="fas fa-exclamation-circle" style="margin-right:6px;"></i><?= htmlspecialchars($msg_error) ?></div>
<?php endif; ?>

<div style="display:flex;gap:4px;border-bottom:2px solid #e2e8f0;margin-bottom:20px;">
    <button class="tab-btn active" onclick="switchTab('oversight-tab')" id="tab_oversight-tab">
        Admin Oversight 
        <?php if(count($pending_adjustments) > 0): ?>
            <span style="background:#dc2626;color:#fff;font-size:11px;padding:2px 8px;border-radius:12px;margin-left:6px;"><?= count($pending_adjustments) ?></span>
        <?php endif; ?>
    </button>
    <button class="tab-btn" onclick="switchTab('records-tab')" id="tab_records-tab">
        Fuel Adjustment Records
    </button>
</div>

<div id="oversight-tab" class="tab-content active">
    <!-- Admin Oversight: Pending Adjustments -->
    <div class="afao-table-card" style="margin-bottom:30px;">
        <div class="afao-table-hd" style="background:#fffbeb;border-bottom:1px solid #fde68a;">
            <h3 class="afao-table-title" style="color:#b45309;"><i class="fas fa-clock"></i> Pending Manager Adjustments</h3>
            <span style="font-size:11px;color:#b45309;font-weight:600;">Requires compliance validation</span>
        </div>
        <?php if (empty($pending_adjustments)): ?>
        <div class="afao-empty">
            <i class="fas fa-check-circle" style="color:#10b981;"></i>
            <div style="font-size:15px;font-weight:700;color:#64748b;margin-bottom:4px;">All Caught Up</div>
            <div style="font-size:13px;">No pending adjustments to review.</div>
        </div>
        <?php else: ?>
        <div style="overflow-x:hidden;max-width:100%;">
            <table class="afao-tbl">
                <colgroup>
                    <col style="width:5%"><col style="width:9%"><col style="width:10%"><col style="width:9%">
                    <col style="width:11%"><col style="width:8%"><col style="width:25%"><col style="width:11%">
                    <col style="width:12%">
                </colgroup>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Station</th>
                        <th>Fuel Type</th>
                        <th>Adj. Type</th>
                        <th>Liters</th>
                        <th>Reason</th>
                        <th>Logged By</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($pending_adjustments as $adj):
                        $fl = $adj['fuel_type_label'] ?: $adj['fuel_type'] ?: '—';
                        $type_badge = match(strtolower($adj['adjustment_type'])) {
                            'delivery'           => 'bg-green',
                            'calibration'        => 'bg-orange',
                            'physical_inventory' => 'bg-blue',
                            'verified_sale'      => 'bg-indigo',
                            default              => 'bg-gray'
                        };
                        $lc = $adj['liters'] < 0 ? 'color:#dc2626;' : 'color:#16a34a;';
                    ?>
                    <tr>
                        <td style="color:#475569;font-weight:600;">#<?= $adj['id'] ?></td>
                        <td><?= date('M d, Y', strtotime($adj['adjustment_date'])) ?></td>
                        <td title="<?= htmlspecialchars($adj['station_name'] ?? '') ?>"><?= htmlspecialchars($adj['station_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($fl) ?></td>
                        <td><span class="afao-badge <?= $type_badge ?>"><?= htmlspecialchars(str_replace('_',' ',$adj['adjustment_type'])) ?></span></td>
                        <td style="font-weight:700;font-family:monospace;<?= $lc ?>">
                            <?= ($adj['liters'] > 0 ? '+' : '') . number_format($adj['liters'],2) ?> L
                        </td>
                        <td title="<?= htmlspecialchars($adj['reason'] ?? '') ?>" style="white-space:normal;line-height:1.4;">
                            <?= htmlspecialchars($adj['reason'] ?? '—') ?>
                        </td>
                        <td title="<?= htmlspecialchars($adj['manager_name'] ?? '') ?>"><?= htmlspecialchars($adj['manager_name'] ?? '—') ?></td>
                        <td>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="approve_adj">
                                <input type="hidden" name="adj_id" value="<?= $adj['id'] ?>">
                                <button type="submit" style="background:#16a34a;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;" onclick="return confirm('Approve this adjustment?');">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="records-tab" class="tab-content">
<!-- Summary Cards -->
<div class="afao-cards">
    <div class="afao-card c-blue">
        <div class="afao-card-ico"><i class="fas fa-list-ul"></i></div>
        <div class="afao-card-meta">
            <h3>Total Adjustments</h3>
            <h2><?= number_format($counts['total']) ?></h2>
            <span>All types registered</span>
        </div>
    </div>
    <div class="afao-card c-green">
        <div class="afao-card-ico"><i class="fas fa-truck"></i></div>
        <div class="afao-card-meta">
            <h3>Deliveries</h3>
            <h2><?= number_format($counts['delivery']) ?></h2>
            <span>Delivery adjustments</span>
        </div>
    </div>
    <div class="afao-card c-orange">
        <div class="afao-card-ico"><i class="fas fa-cog"></i></div>
        <div class="afao-card-meta">
            <h3>Calibrations</h3>
            <h2><?= number_format($counts['calibration']) ?></h2>
            <span>Pump calibration logs</span>
        </div>
    </div>
    <div class="afao-card c-indigo">
        <div class="afao-card-ico"><i class="fas fa-sync-alt"></i></div>
        <div class="afao-card-meta">
            <h3>Reconciliations</h3>
            <h2><?= number_format($counts['physical_inventory'] + $counts['verified_sale']) ?></h2>
            <span>Stock/sales updates</span>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<form method="get" class="afao-filter" id="recordsFilterForm">
    <?php if ($role === 'superadmin' && !empty($stations)): ?>
    <div class="afao-fg">
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
    <div class="afao-fg">
        <label>Date From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
    </div>
    <div class="afao-fg">
        <label>Date To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
    </div>
    <div class="afao-fg">
        <label>Adj. Type</label>
        <select name="type">
            <option value="">All Types</option>
            <option value="delivery" <?= $adj_type==='delivery'?'selected':'' ?>>Delivery</option>
            <option value="verified_sale" <?= $adj_type==='verified_sale'?'selected':'' ?>>Verified Sale</option>
            <option value="unverified_sale" <?= $adj_type==='unverified_sale'?'selected':'' ?>>Unverified Sale</option>
            <option value="calibration" <?= $adj_type==='calibration'?'selected':'' ?>>Calibration</option>
            <option value="physical_inventory" <?= $adj_type==='physical_inventory'?'selected':'' ?>>Physical Inventory</option>
            <option value="price_update" <?= $adj_type==='price_update'?'selected':'' ?>>Price Update</option>
            <option value="adjusted_reading" <?= $adj_type==='adjusted_reading'?'selected':'' ?>>Adjusted Reading</option>
            <option value="rejected_reading" <?= $adj_type==='rejected_reading'?'selected':'' ?>>Rejected Reading</option>
            <option value="other" <?= $adj_type==='other'?'selected':'' ?>>Other</option>
        </select>
    </div>
    <button type="submit" class="afao-btn afao-btn-filter"><i class="fas fa-filter"></i> Apply</button>
    <a href="admin_fuel_adjustments_oversight.php" class="afao-btn afao-btn-back"><i class="fas fa-times"></i> Reset</a>
    
    <div style="margin-left:auto; display:flex; gap:8px;">
        <button type="submit" name="export" value="excel" class="afao-btn afao-btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
        <button type="submit" name="export" value="csv" class="afao-btn afao-btn-csv"><i class="fas fa-file-csv"></i> CSV</button>
        <button type="submit" name="export" value="pdf" formtarget="_blank" class="afao-btn afao-btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
    </div>
</form>

<!-- Table -->
<div class="afao-table-card">
    <div class="afao-table-hd">
        <h3 class="afao-table-title"><i class="fas fa-history"></i> Fuel Adjustment Records</h3>
        <span style="font-size:11px;color:#64748b;"><?= number_format(count($records_adjustments)) ?> record(s) — <?= htmlspecialchars($date_from) ?> to <?= htmlspecialchars($date_to) ?></span>
    </div>
    <?php if (empty($records_adjustments)): ?>
    <div class="afao-empty">
        <i class="fas fa-inbox"></i>
        <div style="font-size:15px;font-weight:700;color:#64748b;margin-bottom:4px;">No adjustments found</div>
        <div style="font-size:13px;">No approved adjustments logged for the selected period.</div>
    </div>
    <?php else: ?>
    <div style="overflow-x:hidden;max-width:100%;">
        <table class="afao-tbl">
            <colgroup>
                <col style="width:5%"><col style="width:9%"><col style="width:9%"><col style="width:9%">
                <col style="width:11%"><col style="width:8%"><col style="width:18%"><col style="width:10%">
                <col style="width:10%"><col style="width:11%">
            </colgroup>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Station</th>
                    <th>Fuel Type</th>
                    <th>Adj. Type</th>
                    <th>Liters</th>
                    <th>Reason</th>
                    <th>Logged By</th>
                    <th>Approved By</th>
                    <th>Approved At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($records_adjustments as $adj):
                    $fl = $adj['fuel_type_label'] ?: $adj['fuel_type'] ?: '—';
                    $type_badge = match(strtolower($adj['adjustment_type'])) {
                        'delivery'           => 'bg-green',
                        'calibration'        => 'bg-orange',
                        'physical_inventory' => 'bg-blue',
                        'verified_sale'      => 'bg-indigo',
                        default              => 'bg-gray'
                    };
                    $lc = $adj['liters'] < 0 ? 'color:#dc2626;' : 'color:#16a34a;';
                ?>
                <tr>
                    <td style="color:#475569;">#<?= $adj['id'] ?></td>
                    <td><?= date('M d, Y', strtotime($adj['adjustment_date'])) ?></td>
                    <td title="<?= htmlspecialchars($adj['station_name'] ?? '') ?>"><?= htmlspecialchars($adj['station_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($fl) ?></td>
                    <td><span class="afao-badge <?= $type_badge ?>"><?= htmlspecialchars(str_replace('_',' ',$adj['adjustment_type'])) ?></span></td>
                    <td style="font-weight:700;font-family:monospace;<?= $lc ?>">
                        <?= ($adj['liters'] > 0 ? '+' : '') . number_format($adj['liters'],2) ?> L
                    </td>
                    <td title="<?= htmlspecialchars($adj['reason'] ?? '') ?>">
                        <?= htmlspecialchars(substr($adj['reason'] ?? '—', 0, 35)) ?><?= strlen($adj['reason'] ?? '') > 35 ? '…' : '' ?>
                    </td>
                    <td title="<?= htmlspecialchars($adj['manager_name'] ?? '') ?>"><?= htmlspecialchars($adj['manager_name'] ?? '—') ?></td>
                    <td title="<?= htmlspecialchars($adj['approved_by_name'] ?? '') ?>"><?= htmlspecialchars($adj['approved_by_name'] ?? '—') ?></td>
                    <td><?= $adj['approved_at'] ? date('M d, Y', strtotime($adj['approved_at'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination Controls -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid #f1f5f9;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:12px;color:#64748b;font-weight:600;">Rows per page:</label>
            <select id="rowsPerPage" style="padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;cursor:pointer;">
                <option value="10">10</option>
                <option value="20" selected>20</option>
                <option value="30">30</option>
                <option value="40">40</option>
                <option value="50">50</option>
            </select>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span id="pageInfo" style="font-size:12px;color:#64748b;font-weight:600;">Page 1 of 1</span>
            <div style="display:flex;gap:4px;">
                <button id="prevPage" class="afao-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;" disabled>
                    <i class="fas fa-chevron-left"></i> Prev
                </button>
                <button id="nextPage" class="afao-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;">
                    Next <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    document.getElementById('tab_' + tabId).classList.add('active');
    
    // Store active tab in URL params or local storage if needed (optional)
}

// Check URL params for export or filter to default to records tab
if (window.location.search.indexOf('type=') > -1 || window.location.search.indexOf('date_from=') > -1) {
    switchTab('records-tab');
}

// Pagination functionality for records tab
(function() {
    const table = document.querySelector('#records-tab .afao-tbl tbody');
    if (!table) return;
    
    const allRows = Array.from(table.querySelectorAll('tr'));
    let currentPage = 1;
    let rowsPerPage = 20;
    
    const rowsSelect = document.getElementById('rowsPerPage');
    const pageInfo = document.getElementById('pageInfo');
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');
    
    function updateTable() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        
        // Hide all rows first
        allRows.forEach(row => row.style.display = 'none');
        
        // Show only current page rows
        allRows.slice(start, end).forEach(row => row.style.display = '');
        
        // Update page info
        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        
        // Update button states
        prevBtn.disabled = currentPage === 1;
        nextBtn.disabled = currentPage === totalPages;
        
        // Update button styles
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
            // Scroll to top of table
            document.querySelector('.afao-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    nextBtn.addEventListener('click', function() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updateTable();
            // Scroll to top of table
            document.querySelector('.afao-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    // Add hover effects
    document.querySelectorAll('.afao-page-btn').forEach(btn => {
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
    
    // Initialize
    updateTable();
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
