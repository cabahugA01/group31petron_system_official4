<?php
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

$TANK_LABEL = [
    'Underground Tank #1'  => 'DIESEL 1 - 1',    'Underground Tank #2'  => 'DIESEL 1 - 2',
    'Underground Tank #3'  => 'DIESEL 1 - 3',    'Underground Tank #4'  => 'DIESEL 1 - 4',
    'Underground Tank #5'  => 'DIESEL 2 - 5',    'Underground Tank #6'  => 'DIESEL 2 - 6',
    'Underground Tank #7'  => 'KEROSENE - 1',    'Underground Tank #8'  => 'TURBO DIESEL - 1',
    'Underground Tank #9'  => 'TURBO DIESEL - 2','Underground Tank #10' => 'XCS PLUS - 1',
    'Underground Tank #11' => 'XCS PLUS - 2',    'Underground Tank #12' => 'XCS PLUS - 3',
    'Underground Tank #13' => 'XCS PLUS - 4',    'Underground Tank #14' => 'XTRA UNL 1 - 1',
    'Underground Tank #15' => 'XTRA UNL 1 - 2',  'Underground Tank #16' => 'XTRA UNL 2 - 3',
    'Underground Tank #17' => 'XTRA UNL 2 - 4',
];

$msg_success = $_SESSION['success'] ?? ''; $msg_error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$date_from = trim($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));

// ── Fuel Transactions (validated/adjusted by manager) ────────
$txn_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT ft.id, ft.transaction_id, ft.transaction_date, ft.fuel_type, ft.pump_id,
               ft.previous_reading, ft.present_reading, ft.calibration,
               ft.liters_sold, ft.price_per_liter, ft.total_amount,
               ft.shift_period, ft.shift_name, ft.status, ft.notes, ft.reject_reason,
               ft.validated_at,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(st.first_name,'')), ' ', TRIM(COALESCE(st.last_name,''))), ' '), st.username, '—') AS staff_name,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(mg.first_name,'')), ' ', TRIM(COALESCE(mg.last_name,''))), ' '), mg.username, '—') AS manager_name,
               fa.liters AS adj_actual_liters, fa.reason AS adj_reason,
               fa.adjustment_type, fa.created_at AS adj_timestamp
        FROM fuel_transactions ft
        LEFT JOIN users st ON ft.staff_id = st.id
        LEFT JOIN users mg ON ft.validated_by = mg.id
        LEFT JOIN fuel_adjustments fa ON fa.station_id = ft.station_id
            AND fa.adjustment_type IN ('verified_sale','adjusted_reading','rejected_reading','daily_log_approved','daily_log_rejected')
            AND fa.reason LIKE CONCAT('%#', ft.transaction_id, '%')
        WHERE ft.station_id = ?
          AND DATE(ft.transaction_date) BETWEEN ? AND ?
        ORDER BY ft.transaction_date DESC, ft.created_at DESC
        LIMIT 300
    ");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $txn_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("txn_rows: ".$e->getMessage()); }

// ── Fuel Deliveries ──────────────────────────────────────────
$del_rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT fd.id AS delivery_id, fd.batch_id, fd.delivery_date, fd.fuel_type,
               fd.tank_assigned, fd.tanker_number, fd.supplier, fd.invoice_no,
               fd.delivery_liters, fd.status, fd.verified_at, fd.notes,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(rc.first_name,'')), ' ', TRIM(COALESCE(rc.last_name,''))), ' '), rc.username, '—') AS staff_name,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(mg.first_name,'')), ' ', TRIM(COALESCE(mg.last_name,''))), ' '), mg.username, '—') AS manager_name,
               fa.liters AS adj_actual_liters, fa.reason AS adj_reason,
               fa.created_at AS adj_timestamp
        FROM fuel_deliveries fd
        LEFT JOIN users rc ON fd.received_by = rc.id
        LEFT JOIN users mg ON fd.verified_by = mg.id
        LEFT JOIN fuel_adjustments fa ON fa.station_id = fd.station_id
            AND fa.adjustment_type = 'delivery'
            AND fa.reason LIKE CONCAT('%#', fd.id, '%')
        WHERE fd.station_id = ?
          AND DATE(fd.delivery_date) BETWEEN ? AND ?
        ORDER BY fd.delivery_date DESC, fd.created_at DESC
        LIMIT 300
    ");
    $stmt->execute([$station_id, $date_from, $date_to]);
    $del_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("del_rows: ".$e->getMessage()); }

$pending_txn = count(array_filter($txn_rows, fn($r) => stripos($r['status'],'pending') !== false));
$pending_del = count(array_filter($del_rows,  fn($r) => stripos($r['status'],'pending') !== false));

// ── 17-Tanker Pump Master Config ──
$TANK_CONFIG_17 = [
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 1',     'tank'=>'Underground Tank #1',  'tanker_num'=>1],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 2',     'tank'=>'Underground Tank #2',  'tanker_num'=>2],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 3',     'tank'=>'Underground Tank #3',  'tanker_num'=>3],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 4',     'tank'=>'Underground Tank #4',  'tanker_num'=>4],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 2 - 5',     'tank'=>'Underground Tank #5',  'tanker_num'=>5],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 2 - 6',     'tank'=>'Underground Tank #6',  'tanker_num'=>6],
    ['fuel_type'=>'Kerosene',    'label'=>'KEROSENE - 1',     'tank'=>'Underground Tank #7',  'tanker_num'=>1],
    ['fuel_type'=>'Turbo Diesel','label'=>'TURBO DIESEL - 1', 'tank'=>'Underground Tank #8',  'tanker_num'=>1],
    ['fuel_type'=>'Turbo Diesel','label'=>'TURBO DIESEL - 2', 'tank'=>'Underground Tank #9',  'tanker_num'=>2],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 1',     'tank'=>'Underground Tank #10', 'tanker_num'=>1],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 2',     'tank'=>'Underground Tank #11', 'tanker_num'=>2],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 3',     'tank'=>'Underground Tank #12', 'tanker_num'=>3],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 4',     'tank'=>'Underground Tank #13', 'tanker_num'=>4],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 1 - 1',  'tank'=>'Underground Tank #14', 'tanker_num'=>1],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 1 - 2',  'tank'=>'Underground Tank #15', 'tanker_num'=>2],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 2 - 3',  'tank'=>'Underground Tank #16', 'tanker_num'=>3],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 2 - 4',  'tank'=>'Underground Tank #17', 'tanker_num'=>4],
];

$pm_inv_lookup = [];
try {
    $inv_stmt = $pdo->prepare("
        SELECT fuel_type, latest_calibration, last_updated
        FROM fuel_inventory WHERE station_id = ?
    ");
    $inv_stmt->execute([$station_id]);
    foreach ($inv_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pm_inv_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) { error_log("pm_inv: ".$e->getMessage()); }

$pm_cal_history = [];
try {
    $history_stmt = $pdo->prepare("
        SELECT pch.*,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, '—') AS manager_name
        FROM pump_calibration_history pch
        LEFT JOIN users u ON pch.updated_by = u.id
        WHERE pch.station_id = ?
          AND pch.id = (
              SELECT MAX(id) FROM pump_calibration_history pch2
              WHERE pch2.station_id = pch.station_id
                AND LOWER(TRIM(pch2.fuel_type)) = LOWER(TRIM(pch.fuel_type))
          )
    ");
    $history_stmt->execute([$station_id]);
    foreach ($history_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pm_cal_history[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) { error_log("pm_cal_history: ".$e->getMessage()); }

require_once __DIR__ . '/../partials/header.php';

// adj_status = fuel_adjustments.status; rec_status = fuel_transactions/deliveries.status
function statusBadge(?string $adj_status, ?string $rec_status = null): string {
    // If there's an adjustment record, use its status as the admin-review indicator
    if ($adj_status !== null) {
        $as = strtolower($adj_status);
        if (str_contains($as,'approv') || str_contains($as,'clear'))
            return '<span class="sb sb-clear">Cleared</span>';
        // adj exists but not yet approved by admin = Pending admin review
        return '<span class="sb sb-pend">Pending</span>';
    }
    // No adjustment record — use the record's own status
    $sl = strtolower($rec_status ?? '');
    if (str_contains($sl,'verif') || str_contains($sl,'approv'))
        return '<span class="sb sb-pend">Pending</span>'; // manager verified, needs admin review
    if (str_contains($sl,'reject') || str_contains($sl,'flag'))
        return '<span class="sb sb-flag">Flagged</span>';
    return '<span class="sb sb-pend">Pending</span>';
}
?>
<style>
/* == PAGE HEADER - matches SuperAdmin int-head standard == */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; margin-top:-12px !important; }
.int-head h1 { font-size:22px !important; font-weight:700 !important; color:var(--petron-blue,#00264D) !important; margin:0 !important; text-transform:uppercase !important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none !important; }

/* Tabs */
.tab-btn { padding:10px 20px; font-size:13px; font-weight:700; color:#64748b; background:transparent; border:none; border-bottom:3px solid transparent; cursor:pointer; transition:all .15s; outline:none; white-space:nowrap; display:inline-flex; align-items:center; gap:7px; }
.tab-btn:hover { color:#002F70; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-content { display:none; }
.tab-content.active { display:block; }

/* Table Card & standard Petron tables */
.tbl-card { background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:24px; }
.tbl-hd { display:flex; align-items:center; justify-content:space-between; padding:13px 16px; border-bottom:1px solid #e9ecef; flex-wrap:wrap; gap:8px; background:#f8fafc; }
.tbl-title { font-size:13px; font-weight:700; color:#00264D; display:flex; align-items:center; gap:7px; }
.afao-tbl { width:100%; table-layout:fixed; border-collapse:collapse; font-size:11px; }
.afao-tbl thead tr { background:#002F70; }
.afao-tbl thead th { padding:9px 10px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; overflow:hidden; text-overflow:ellipsis; border-bottom:2px solid #001a3d; vertical-align:middle; }
.afao-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.afao-tbl tbody tr:hover td { background:#eff6ff; }
.afao-tbl tbody td { padding:9px 10px; color:#334155; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; background:#fff; font-size:11px; }

.enc-block { display:flex; flex-direction:column; gap:1px; font-size:10.5px; }
.enc-row { display:flex; justify-content:space-between; gap:4px; }
.enc-lbl { color:#94a3b8; font-weight:600; font-size:10px; white-space:nowrap; }
.enc-val { font-weight:700; color:#334155; font-family:monospace; }
.var-pos { color:#dc2626; font-weight:700; font-family:monospace; }
.var-neg { color:#16a34a; font-weight:700; font-family:monospace; }
.var-zero { color:#64748b; font-weight:600; font-family:monospace; }
.ref-badge { font-family:monospace; font-size:11px; font-weight:700; background:#eff6ff; color:#1e40af; padding:2px 7px; border-radius:5px; border:1px solid #dbeafe; white-space:nowrap; }

/* Filter bar & Inputs styling */
.afao-filter { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px; }
.afao-fg { display:flex; flex-direction:column; gap:3px; }
.afao-fg label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.afao-fg input, .afao-fg select { height:36px; padding:0 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; color:#1e293b; background:#fff; outline:none; box-sizing:border-box; }
.afao-fg input:focus, .afao-fg select:focus { border-color:#002F70; box-shadow:0 0 0 3px rgba(0,47,112,.1); }

/* Buttons styling */
.ato-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:0 16px; border-radius:7px; font-size:13px; font-weight:600;
    cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s;
    height:36px; white-space:nowrap; background:white !important;
}
.ato-btn-filter { color:#002F70 !important; border-color:#002F70 !important; }
.ato-btn-filter:hover { background:#002F70 !important; color:#fff !important; }
.ato-btn-back { color:#4b5563 !important; border-color:#6b7280 !important; }
.ato-btn-back:hover { background:#6b7280 !important; color:#fff !important; }

.empty-s { text-align:center; padding:50px 20px; color:#94a3b8; }
.empty-s i { font-size:38px; display:block; margin-bottom:12px; opacity:.35; }
.alert-ok { padding:12px 16px; background:#dcfce7; border:1px solid #bbf7d0; color:#15803d; border-radius:8px; margin-bottom:14px; font-weight:600; }
.alert-err { padding:12px 16px; background:#fee2e2; border:1px solid #fecaca; color:#b91c1c; border-radius:8px; margin-bottom:14px; font-weight:600; }
.sb { display:inline-block; padding:3px 9px; border-radius:12px; font-size:10.5px; font-weight:700; white-space:nowrap; }
.sb-clear { background:#e8f5e9; color:#2e7d32; }
.sb-pend { background:#fff3e0; color:#ef6c00; }
.sb-flag { background:#ffebee; color:#c62828; }
.badge-cnt { background:#dc2626; color:#fff; font-size:11px; padding:2px 7px; border-radius:10px; margin-left:4px; }
</style>

<div class="int-head">
    <div>
        <h1><i class="fas fa-sliders-h"></i> Fuel Adjustments Oversight</h1>
        <div class="sub">Admin review of manager-validated fuel transactions & deliveries</div>
    </div>
    <a href="admin_dashboard.php" class="ato-btn ato-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($msg_success): ?><div class="alert-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg_success) ?></div><?php endif; ?>
<?php if ($msg_error):   ?><div class="alert-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($msg_error) ?></div><?php endif; ?>

<form method="get" class="afao-filter">
    <div class="afao-fg"><label>Date From</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
    <div class="afao-fg"><label>Date To</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
    <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-filter"></i> Apply</button>
    <a href="admin_fuel_adjustments_oversight.php" class="ato-btn ato-btn-back"><i class="fas fa-times"></i> Reset</a>
</form>

<div style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:20px;">
    <button class="tab-btn active" onclick="switchTab('tab-txn','tbtn-txn')" id="tbtn-txn">
        <i class="fas fa-tachometer-alt"></i> Fuel Transactions
        <?php if ($pending_txn > 0): ?><span class="badge-cnt"><?= $pending_txn ?></span><?php endif; ?>
    </button>
    <button class="tab-btn" onclick="switchTab('tab-del','tbtn-del')" id="tbtn-del">
        <i class="fas fa-truck-loading"></i> Fuel Deliveries
        <?php if ($pending_del > 0): ?><span class="badge-cnt"><?= $pending_del ?></span><?php endif; ?>
    </button>
</div>

<!-- ══ TAB 1: FUEL TRANSACTIONS ══ -->
<div id="tab-txn" class="tab-content active">
<div class="tbl-card">
    <div class="tbl-hd">
        <span class="tbl-title"><i class="fas fa-tachometer-alt"></i> Fuel Transaction — Meter Reading</span>
        <span style="font-size:11px;color:#64748b;"><?= count($txn_rows) ?> record(s) · <?= $date_from ?> to <?= $date_to ?></span>
    </div>
    <?php if (empty($txn_rows)): ?>
        <div class="empty-s"><i class="fas fa-inbox"></i><div style="font-size:14px;font-weight:700;color:#64748b;">No transaction records found for this period.</div></div>
    <?php else: ?>
    <div style="overflow:hidden;">
    <table class="afao-tbl">
        <colgroup>
            <col style="width:8%"><col style="width:8%"><col style="width:7%"><col style="width:6%">
            <col style="width:18%"><col style="width:7%"><col style="width:7%">
            <col style="width:13%"><col style="width:13%"><col style="width:7%"><col style="width:6%">
        </colgroup>
        <thead><tr>
            <th>Reference ID</th>
            <th>Date / Shift</th>
            <th>Fuel Type</th>
            <th>Tanker Ref</th>
            <th>Encoded Values</th>
            <th>Actual (L)</th>
            <th>Variance (L)</th>
            <th>Reason / Notes</th>
            <th>Manager Name</th>
            <th>Timestamp</th>
            <th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($txn_rows as $r):
            $enc_liters = round((floatval($r['present_reading']) - floatval($r['previous_reading'])) * floatval($r['calibration'] ?: 1), 2);
            $actual_l   = $r['adj_actual_liters'] !== null ? abs((float)$r['adj_actual_liters']) : (float)$r['liters_sold'];
            $variance   = round($enc_liters - $actual_l, 2);
            $vc         = $variance == 0 ? 'var-zero' : ($variance > 0 ? 'var-pos' : 'var-neg');
            $vs         = ($variance > 0 ? '+' : '') . number_format($variance, 2);
            $reason     = $r['adj_reason'] ?: $r['notes'] ?: $r['reject_reason'] ?: '—';
            $ts         = $r['adj_timestamp'] ?: $r['validated_at'] ?: $r['transaction_date'];
        ?>
        <tr>
            <td><span class="ref-badge">#<?= htmlspecialchars($r['transaction_id']) ?></span></td>
            <td style="font-size:10.5px;">
                <div><?= date('M d, Y', strtotime($r['transaction_date'])) ?></div>
                <div style="color:#64748b;"><?= htmlspecialchars($r['shift_name'] ?: $r['shift_period'] ?: '—') ?></div>
            </td>
            <td style="font-weight:700;color:#00264D;white-space:normal;font-size:11px;"><?= htmlspecialchars($r['fuel_type']) ?></td>
            <td style="font-size:11px;color:#475569;">Pump #<?= htmlspecialchars($r['pump_id'] ?? '—') ?></td>
            <td>
                <div class="enc-block">
                    <div class="enc-row"><span class="enc-lbl">Prev:</span><span class="enc-val"><?= number_format((float)$r['previous_reading'], 2) ?></span></div>
                    <div class="enc-row"><span class="enc-lbl">Present:</span><span class="enc-val"><?= number_format((float)$r['present_reading'], 2) ?></span></div>
                    <div class="enc-row"><span class="enc-lbl">Cal:</span><span class="enc-val"><?= number_format((float)($r['calibration'] ?: 1), 4) ?></span></div>
                    <div class="enc-row" style="border-top:1px solid #e2e8f0;margin-top:2px;padding-top:2px;"><span class="enc-lbl">→ Liters:</span><span class="enc-val"><?= number_format($enc_liters, 2) ?> L</span></div>
                </div>
            </td>
            <td style="font-weight:700;font-family:monospace;color:#002F6C;"><?= number_format($actual_l, 2) ?> L</td>
            <td class="<?= $vc ?>"><?= $vs ?> L</td>
            <td style="white-space:normal;line-height:1.4;font-size:10.5px;" title="<?= htmlspecialchars($reason) ?>"><?= htmlspecialchars(substr($reason, 0, 60)) ?><?= strlen($reason) > 60 ? '…' : '' ?></td>
            <td style="font-size:11px;" title="<?= htmlspecialchars($r['manager_name']) ?>"><?= htmlspecialchars($r['manager_name']) ?></td>
            <td style="font-size:10.5px;color:#475569;white-space:normal;"><?= $ts ? date('M d Y H:i', strtotime($ts)) : '—' ?></td>
            <td><?= statusBadge($r['adj_reason'] !== null ? ($r['adjustment_type'] ?? 'pending') : null, $r['status'] ?? 'pending') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ══ TAB 2: FUEL DELIVERIES ══ -->
<div id="tab-del" class="tab-content">
<div class="tbl-card">
    <div class="tbl-hd">
        <span class="tbl-title"><i class="fas fa-truck-loading"></i> Fuel Deliveries — Adjustment Log</span>
        <span style="font-size:11px;color:#64748b;"><?= count($del_rows) ?> record(s) · <?= $date_from ?> to <?= $date_to ?></span>
    </div>
    <?php if (empty($del_rows)): ?>
        <div class="empty-s"><i class="fas fa-inbox"></i><div style="font-size:14px;font-weight:700;color:#64748b;">No delivery records found for this period.</div></div>
    <?php else: ?>
    <div style="overflow:hidden;">
    <table class="afao-tbl">
        <colgroup>
            <col style="width:6%"><col style="width:7%"><col style="width:11%"><col style="width:9%">
            <col style="width:12%"><col style="width:7%"><col style="width:7%"><col style="width:7%">
            <col style="width:6%"><col style="width:11%"><col style="width:11%"><col style="width:7%"><col style="width:6%">
        </colgroup>
        <thead><tr>
            <th>Ref ID</th>
            <th>Date</th>
            <th>Fuel Type / Tanker</th>
            <th>Tanker Ref</th>
            <th>Supplier / DR No.</th>
            <th>DR Qty (L)</th>
            <th>Encoded (L)</th>
            <th>Actual (L)</th>
            <th>Variance (L)</th>
            <th>Reason / Notes</th>
            <th>Manager Name</th>
            <th>Timestamp</th>
            <th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($del_rows as $r):
            $tank_label  = $TANK_LABEL[$r['tank_assigned']] ?? ($r['tank_assigned'] ?? '—');
            $dr_qty      = (float)$r['delivery_liters'];
            $encoded_qty = $dr_qty;
            $actual_qty  = $r['adj_actual_liters'] !== null ? abs((float)$r['adj_actual_liters']) : $dr_qty;
            $variance    = round($dr_qty - $actual_qty, 2);
            $vc          = $variance == 0 ? 'var-zero' : ($variance > 0 ? 'var-pos' : 'var-neg');
            $vs          = ($variance > 0 ? '+' : '') . number_format($variance, 2);
            $reason      = $r['adj_reason'] ?: $r['notes'] ?: '—';
            $ts          = $r['adj_timestamp'] ?: $r['verified_at'] ?: $r['delivery_date'];
        ?>
        <tr>
            <td><span class="ref-badge">#<?= htmlspecialchars($r['delivery_id']) ?></span></td>
            <td style="font-size:10.5px;"><?= date('M d, Y', strtotime($r['delivery_date'])) ?></td>
            <td style="white-space:normal;">
                <div style="font-weight:700;color:#00264D;font-size:11px;"><?= htmlspecialchars($r['fuel_type']) ?></div>
                <div style="font-size:10px;color:#0369a1;font-weight:600;"><?= htmlspecialchars($tank_label) ?></div>
            </td>
            <td style="font-size:10.5px;color:#475569;white-space:normal;"><?= htmlspecialchars($r['tanker_number'] ?? '—') ?></td>
            <td style="white-space:normal;">
                <div style="font-weight:600;font-size:11px;"><?= htmlspecialchars($r['supplier'] ?? '—') ?></div>
                <div style="font-size:10px;color:#0369a1;font-family:monospace;"><?= htmlspecialchars($r['invoice_no'] ?? '—') ?></div>
            </td>
            <td style="font-family:monospace;font-weight:700;"><?= number_format($dr_qty, 2) ?></td>
            <td style="font-family:monospace;"><?= number_format($encoded_qty, 2) ?></td>
            <td style="font-family:monospace;font-weight:700;color:#002F6C;"><?= number_format($actual_qty, 2) ?></td>
            <td class="<?= $vc ?>"><?= $vs ?></td>
            <td style="white-space:normal;line-height:1.4;font-size:10.5px;" title="<?= htmlspecialchars($reason) ?>"><?= htmlspecialchars(substr($reason, 0, 55)) ?><?= strlen($reason) > 55 ? '…' : '' ?></td>
            <td style="font-size:11px;" title="<?= htmlspecialchars($r['manager_name']) ?>"><?= htmlspecialchars($r['manager_name']) ?></td>
            <td style="font-size:10.5px;color:#475569;white-space:normal;"><?= $ts ? date('M d Y H:i', strtotime($ts)) : '—' ?></td>
            <td><?= statusBadge($r['adj_reason'] !== null ? ($r['status'] ?? 'pending') : null, $r['status'] ?? 'Pending') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
function switchTab(id, btnId) {
    document.querySelectorAll('.tab-content').forEach(e => e.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(e => e.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    document.getElementById(btnId).classList.add('active');
}
// Auto-switch if URL param set
(function(){
    const h = window.location.hash.substring(1);
    if (h === 'deliveries') switchTab('tab-del','tbtn-del');
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
