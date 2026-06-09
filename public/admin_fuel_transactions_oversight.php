<?php
// ============================================================
// Admin Fuel Transactions Oversight
// Fetch Source: fuel_transactions (staff-encoded → manager-verified)
// ============================================================
$page_id = 'admin_fuel_transactions_oversight';
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

// ── Filters ──────────────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));
$fuel_type = trim($_GET['fuel_type'] ?? '');
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

// ── Summary Counts (All-time or Station-scoped) ──────────────
$total_validated = 0; $pending_count = 0; $total_liters = 0.0; $total_amount = 0.0;
try {
    $sc_sql = "SELECT
        SUM(CASE WHEN LOWER(status)='verified' THEN 1 ELSE 0 END) as validated,
        SUM(CASE WHEN LOWER(status) IN ('pending validation','pending') OR status IS NULL OR status='' THEN 1 ELSE 0 END) as pending,
        COALESCE(SUM(CASE WHEN LOWER(status)='verified' THEN liters_sold ELSE 0 END),0) as liters,
        COALESCE(SUM(CASE WHEN LOWER(status)='verified' THEN total_amount ELSE 0 END),0) as amount
        FROM fuel_transactions";
    
    if ($filter_station > 0) {
        $sc = $pdo->prepare($sc_sql . " WHERE station_id=?");
        $sc->execute([$filter_station]);
    } else {
        $sc = $pdo->query($sc_sql);
    }
    $sc_row = $sc->fetch(PDO::FETCH_ASSOC);
    $total_validated = (int)($sc_row['validated'] ?? 0);
    $pending_count   = (int)($sc_row['pending']   ?? 0);
    $total_liters    = (float)($sc_row['liters']  ?? 0);
    $total_amount    = (float)($sc_row['amount']  ?? 0);
} catch (Exception $e) {}

// ── Fetch Transactions ───────────────────────────────────────
$where  = ["DATE(ft.transaction_date) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if ($filter_station > 0) {
    $where[] = "ft.station_id = ?";
    $params[] = $filter_station;
}
if ($fuel_type !== '') {
    $where[] = "ft.fuel_type = ?";
    $params[] = $fuel_type;
}

$transactions = [];
try {
    $stmt = $pdo->prepare("SELECT ft.id, ft.transaction_id, ft.fuel_type, ft.pump_id,
        ft.present_reading, ft.previous_reading, ft.liters_sold, ft.calibration,
        ft.price_per_liter, ft.total_amount, ft.payment_method, ft.shift_period,
        ft.status, ft.transaction_date, ft.validated_at,
        staff.name AS staff_name, mgr.name AS manager_name,
        fp.pump_number, s.name AS station_name
        FROM fuel_transactions ft
        LEFT JOIN users staff ON ft.staff_id = staff.id
        LEFT JOIN users mgr   ON ft.validated_by = mgr.id
        LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
        LEFT JOIN stations s ON ft.station_id = s.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ft.transaction_date DESC, ft.id DESC LIMIT 500");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Fuel Type list for filter ────────────────────────────────
$fuel_types = [];
try {
    if ($filter_station > 0) {
        $ft_stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_transactions WHERE station_id=? ORDER BY fuel_type");
        $ft_stmt->execute([$filter_station]);
    } else {
        $ft_stmt = $pdo->query("SELECT DISTINCT fuel_type FROM fuel_transactions ORDER BY fuel_type");
    }
    $fuel_types = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);
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
    $headers = ['Transaction ID','Date','Station','Pump','Fuel Type','Prev Reading','Present Reading','Liters Sold','Calibration','Price/L','Total Amount','Payment Method','Shift','Status','Staff','Manager','Validated At'];
    $rows_fmt = [];
    foreach ($transactions as $tx) {
        $rows_fmt[] = [
            $tx['transaction_id'],
            date('M d, Y H:i', strtotime($tx['transaction_date'])),
            $tx['station_name'] ?? '—',
            $tx['pump_number'] ?? 'P'.$tx['pump_id'],
            $tx['fuel_type'],
            number_format($tx['previous_reading'],2),
            number_format($tx['present_reading'],2),
            number_format($tx['liters_sold'],2),
            number_format($tx['calibration'],2),
            '₱'.number_format($tx['price_per_liter'],2),
            '₱'.number_format($tx['total_amount'],2),
            $tx['payment_method'],
            $tx['shift_period'],
            ucfirst($tx['status'] ?? 'Pending'),
            $tx['staff_name']   ?? '—',
            $tx['manager_name'] ?? '—',
            $tx['validated_at'] ? date('M d, Y H:i', strtotime($tx['validated_at'])) : '—',
        ];
    }
    $filename = 'fuel_transactions_'.$date_from.'_to_'.$date_to;

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
        echo '<h2>Fuel Transactions Oversight</h2><p>Period: '.$date_from.' to '.$date_to.' | Station: '.$station_name.' | Records: '.count($rows_fmt).'</p>';
        echo '<table><thead><tr>';
        foreach($headers as $h) echo '<th>'.htmlspecialchars($h).'</th>';
        echo '</tr></thead><tbody>';
        foreach($rows_fmt as $r) { echo '<tr>'; foreach($r as $c) echo '<td>'.htmlspecialchars($c).'</td>'; echo '</tr>'; }
        echo '</tbody></table></body></html>'; exit;
    }
    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $tbody = '';
        foreach($transactions as $tx) {
            $st = strtolower($tx['status'] ?? '');
            $sc_color = ($st === 'verified') ? '#16a34a' : '#d97706';
            $tbody .= '<tr>';
            $tbody .= '<td style="font-size:10px;color:#475569;">'.htmlspecialchars($tx['transaction_id']).'</td>';
            $tbody .= '<td>'.date('M d, Y', strtotime($tx['transaction_date'])).'</td>';
            $tbody .= '<td>'.htmlspecialchars($tx['station_name'] ?? '—').'</td>';
            $tbody .= '<td>'.htmlspecialchars($tx['pump_number'] ?? 'P'.$tx['pump_id']).'</td>';
            $tbody .= '<td>'.htmlspecialchars($tx['fuel_type']).'</td>';
            $tbody .= '<td style="text-align:right">'.number_format($tx['liters_sold'],2).' L</td>';
            $tbody .= '<td style="text-align:right;font-weight:700;color:#002F6C">₱'.number_format($tx['total_amount'],2).'</td>';
            $tbody .= '<td>'.htmlspecialchars($tx['payment_method']).'</td>';
            $tbody .= '<td style="color:'.$sc_color.';font-weight:700">'.ucfirst($tx['status'] ?? 'Pending').'</td>';
            $tbody .= '<td>'.htmlspecialchars($tx['staff_name']   ?? '—').'</td>';
            $tbody .= '<td>'.htmlspecialchars($tx['manager_name'] ?? '—').'</td>';
            $tbody .= '</tr>';
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fuel Transactions Oversight</title>
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
        echo '<div class="hdr"><h1>Fuel Transactions Oversight</h1><p>Period: '.htmlspecialchars($date_from).' — '.htmlspecialchars($date_to).' | Station: '.htmlspecialchars($station_name).' | Records: '.count($transactions).'</p></div>';
        echo '<table><thead><tr><th>Txn ID</th><th>Date</th><th>Station</th><th>Pump</th><th>Fuel Type</th><th>Liters</th><th>Amount</th><th>Payment</th><th>Status</th><th>Staff</th><th>Manager</th></tr></thead>';
        echo '<tbody>'.($tbody ?: '<tr><td colspan="11" style="text-align:center;padding:20px;color:#94a3b8">No records.</td></tr>').'</tbody></table>';
        echo '</body></html>'; exit;
    }
}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Admin Fuel Transactions Oversight ── */
.afto-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.afto-head h1 { margin:0 0 4px; font-size:22px; font-weight:700; color:#00264D; display:flex; align-items:center; gap:9px; }
.afto-subtitle { font-size:13px; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; }
.afto-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

/* Export buttons — same size/design as transaction module */
.afto-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600;
    cursor:pointer; border:none; text-decoration:none; transition:all .13s;
    height:36px; white-space:nowrap;
}
.afto-btn-excel  { background:#1d6f42; color:#fff; }
.afto-btn-excel:hover  { background:#155a34; color:#fff; }
.afto-btn-csv    { background:#003d7a; color:#fff; }
.afto-btn-csv:hover    { background:#002a58; color:#fff; }
.afto-btn-pdf    { background:#dc2626; color:#fff; }
.afto-btn-pdf:hover    { background:#b91c1c; color:#fff; }
.afto-btn-back   { background:#6c757d; color:#fff; }
.afto-btn-back:hover   { background:#545b62; color:#fff; }
.afto-btn-filter { background:#002F6C; color:#fff; }
.afto-btn-filter:hover { background:#001f4d; color:#fff; }

/* Summary cards */
.afto-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:18px; }
.afto-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; padding:16px; display:flex; align-items:center; gap:14px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.afto-card.c-blue  { border-left:4px solid #1e40af; }
.afto-card.c-amber { border-left:4px solid #d97706; }
.afto-card.c-green { border-left:4px solid #16a34a; }
.afto-card.c-navy  { border-left:4px solid #002F6C; }
.afto-card-ico { width:40px; height:40px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; color:#002F6C; }
.afto-card-meta h3 { margin:0; font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:.5px; font-weight:700; }
.afto-card-meta h2 { margin:2px 0 0; font-size:24px; font-weight:900; color:#00264D; line-height:1; }
.afto-card-meta span { font-size:11px; color:#94a3b8; }

/* Filter bar */
.afto-filter { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px; }
.afto-fg { display:flex; flex-direction:column; gap:3px; }
.afto-fg label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.afto-fg input, .afto-fg select { padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; font-size:12px; }

/* Force no horizontal scroll */
html, body { max-width:100vw; overflow-x:hidden; }
.container { max-width:100%; overflow-x:hidden; }
/* Table */
.afto-table-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); width:100%; }
.afto-table-hd { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
.afto-table-title { font-size:13px; font-weight:700; color:#00264D; text-transform:uppercase; letter-spacing:.3px; margin:0; }
.afto-tbl-wrap { width:100%; overflow-x:hidden; }
.afto-tbl { width:100%; table-layout:fixed; border-collapse:collapse; font-size:13px; }
.afto-tbl thead tr { background:#002F6C; }
.afto-tbl thead th { padding:10px 8px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.3px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.4; }
.afto-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.afto-tbl tbody tr:hover { background:#eff6ff; }
.afto-tbl tbody td { padding:10px 8px; color:#334155; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.5; }
.afto-badge { display:inline-block; padding:4px 9px; border-radius:4px; font-size:11px; font-weight:700; white-space:nowrap; }
.bg-green  { background:#dcfce7; color:#15803d; }
.bg-amber  { background:#fef9c3; color:#a16207; }
.bg-gray   { background:#f1f5f9; color:#475569; }
.bg-red    { background:#fee2e2; color:#b91c1c; }
.afto-empty { text-align:center; padding:60px 20px; color:#94a3b8; }
.afto-empty i { font-size:44px; display:block; margin-bottom:14px; opacity:.4; }
</style>

<div class="afto-head">
    <div>
        <h1><i class="fas fa-gas-pump"></i> Fuel Transactions Oversight</h1>
        <div class="afto-subtitle">MONITOR AND AUDIT ALL FUEL TRANSACTIONS VALIDATED BY MANAGERS, ENSURING COMPLIANCE AND ACCURACY.</div>
    </div>
    <div class="afto-actions">
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'excel'])) ?>" class="afto-btn afto-btn-excel"><i class="fas fa-file-excel"></i> Excel</a>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>"   class="afto-btn afto-btn-csv"><i class="fas fa-file-csv"></i> CSV</a>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'pdf'])) ?>"   class="afto-btn afto-btn-pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        <a href="admin_dashboard.php" class="afto-btn afto-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- Summary Cards -->
<div class="afto-cards">
    <div class="afto-card c-blue">
        <div class="afto-card-ico"><i class="fas fa-check-circle"></i></div>
        <div class="afto-card-meta">
            <h3>Validated Transactions</h3>
            <h2><?= number_format($total_validated) ?></h2>
            <span>Manager-verified readings</span>
        </div>
    </div>
    <div class="afto-card c-amber">
        <div class="afto-card-ico"><i class="fas fa-clock"></i></div>
        <div class="afto-card-meta">
            <h3>Pending Validation</h3>
            <h2><?= number_format($pending_count) ?></h2>
            <span>Awaiting manager review</span>
        </div>
    </div>
    <div class="afto-card c-green">
        <div class="afto-card-ico"><i class="fas fa-tint"></i></div>
        <div class="afto-card-meta">
            <h3>Total Liters (Validated)</h3>
            <h2><?= number_format($total_liters,2) ?></h2>
            <span>Liters sold & verified</span>
        </div>
    </div>
    <div class="afto-card c-navy">
        <div class="afto-card-ico"><i class="fas fa-peso-sign"></i></div>
        <div class="afto-card-meta">
            <h3>Total Sales (Validated)</h3>
            <h2>₱<?= number_format($total_amount,2) ?></h2>
            <span>Verified fuel sales</span>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<form method="get" class="afto-filter">
    <?php if ($role === 'superadmin' && !empty($stations)): ?>
    <div class="afto-fg">
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
    <div class="afto-fg">
        <label>Date From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
    </div>
    <div class="afto-fg">
        <label>Date To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
    </div>
    <div class="afto-fg">
        <label>Fuel Type</label>
        <select name="fuel_type">
            <option value="">All Fuel Types</option>
            <?php foreach($fuel_types as $ft): ?>
                <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel_type===$ft?'selected':'' ?>><?= htmlspecialchars($ft) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="afto-btn afto-btn-filter"><i class="fas fa-filter"></i> Apply</button>
    <a href="admin_fuel_transactions_oversight.php" class="afto-btn afto-btn-back"><i class="fas fa-times"></i> Reset</a>
</form>

<!-- Table -->
<div class="afto-table-card">
    <div class="afto-table-hd">
        <h3 class="afto-table-title"><i class="fas fa-table"></i> Fuel Transaction Records</h3>
        <span style="font-size:11px;color:#64748b;"><?= number_format(count($transactions)) ?> record(s) — <?= htmlspecialchars($date_from) ?> to <?= htmlspecialchars($date_to) ?></span>
    </div>
    <?php if (empty($transactions)): ?>
    <div class="afto-empty">
        <i class="fas fa-inbox"></i>
        <div style="font-size:15px;font-weight:700;color:#64748b;margin-bottom:4px;">No transactions found</div>
        <div style="font-size:13px;">No fuel transactions for the selected period.</div>
    </div>
    <?php else: ?>
    <div class="afto-tbl-wrap">
        <table class="afto-tbl">
            <colgroup>
                <col style="width:9%">
                <col style="width:7%">
                <col style="width:10%">
                <col style="width:4%">
                <col style="width:8%">
                <col style="width:6%">
                <col style="width:6%">
                <col style="width:8%">
                <col style="width:7%">
                <col style="width:7%">
                <col style="width:9%">
                <col style="width:9%">
                <col style="width:10%">
            </colgroup>
            <thead>
                <tr>
                    <th>Txn ID</th>
                    <th>Date</th>
                    <th>Station</th>
                    <th>Pmp</th>
                    <th>Fuel</th>
                    <th>Liters</th>
                    <th>Price/L</th>
                    <th>Amount</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Staff</th>
                    <th>Manager</th>
                    <th>Validated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($transactions as $tx):
                    $st = strtolower($tx['status'] ?? '');
                    $badge = ($st === 'verified') ? 'bg-green' : (in_array($st,['pending','pending validation']) ? 'bg-amber' : 'bg-gray');
                    // Short display labels so badge never clips
                    $st_label = match($st) {
                        'verified'           => 'Verified',
                        'pending'            => 'Pending',
                        'pending validation' => 'Pending',
                        default              => ucfirst($tx['status'] ?? 'Pending'),
                    };
                    // Pump display: use pump_number if not empty, else pump_id
                    $pump_display = !empty($tx['pump_number']) ? $tx['pump_number'] : (!empty($tx['pump_id']) ? '#'.$tx['pump_id'] : '—');
                ?>
                <tr>
                    <td style="font-family:monospace;font-size:11px;color:#475569;" title="<?= htmlspecialchars($tx['transaction_id']) ?>"><?= htmlspecialchars(substr($tx['transaction_id'],0,12)) ?></td>
                    <td><?= date('M d, Y', strtotime($tx['transaction_date'])) ?></td>
                    <td title="<?= htmlspecialchars($tx['station_name'] ?? '') ?>"><?= htmlspecialchars($tx['station_name'] ?? '—') ?></td>
                    <td style="text-align:center;"><?= htmlspecialchars($pump_display) ?></td>
                    <td><?= htmlspecialchars($tx['fuel_type']) ?></td>
                    <td style="font-weight:600;"><?= number_format($tx['liters_sold'],2) ?></td>
                    <td>₱<?= number_format($tx['price_per_liter'],2) ?></td>
                    <td style="font-weight:700;color:#002F6C;" title="₱<?= number_format($tx['total_amount'],2) ?>">₱<?= number_format($tx['total_amount'],2) ?></td>
                    <td><?= htmlspecialchars($tx['payment_method']) ?></td>
                    <td><span class="afto-badge <?= $badge ?>"><?= $st_label ?></span></td>
                    <td title="<?= htmlspecialchars($tx['staff_name']   ?? '') ?>"><?= htmlspecialchars($tx['staff_name']   ?? '—') ?></td>
                    <td title="<?= htmlspecialchars($tx['manager_name'] ?? '') ?>"><?= htmlspecialchars($tx['manager_name'] ?? '—') ?></td>
                    <td><?= $tx['validated_at'] ? date('M d, Y', strtotime($tx['validated_at'])) : '—' ?></td>
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
                <button id="prevPage" class="afto-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;" disabled>
                    <i class="fas fa-chevron-left"></i> Prev
                </button>
                <button id="nextPage" class="afto-page-btn" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#64748b;font-size:12px;cursor:pointer;transition:all .15s;">
                    Next <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Pagination functionality
(function() {
    const table = document.querySelector('.afto-tbl tbody');
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
            document.querySelector('.afto-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    nextBtn.addEventListener('click', function() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updateTable();
            // Scroll to top of table
            document.querySelector('.afto-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    
    // Add hover effects
    document.querySelectorAll('.afto-page-btn').forEach(btn => {
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
