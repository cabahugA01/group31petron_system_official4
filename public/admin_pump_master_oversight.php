<?php
$page_id = 'admin_pump_master_oversight';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php'); exit;
}

// ── 17-Tanker Static Config ───────────────────────────────────────────────────
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

// ── Fetch inventory calibration data ─────────────────────────────────────────
$pm_inv_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, latest_calibration, last_updated, fuel_type_id, current_level, current_stock, capacity FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pm_inv_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {}

// ── Fetch latest transaction calibration per (fuel_type, pump_id) ─────────────
$pm_txn_lookup = [];
try {
    $txn_cal_stmt = $pdo->prepare("
        SELECT fuel_type, pump_id, calibration, transaction_date,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, '—') AS staff_name
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id
        WHERE ft.station_id = ?
          AND ft.calibration IS NOT NULL AND ft.calibration > 0
          AND ft.id = (
              SELECT id FROM fuel_transactions ft2
              WHERE ft2.station_id = ft.station_id
                AND LOWER(TRIM(ft2.fuel_type)) = LOWER(TRIM(ft.fuel_type))
                AND ft2.pump_id = ft.pump_id
              ORDER BY ft2.transaction_date DESC, ft2.id DESC LIMIT 1
          )
        GROUP BY ft.fuel_type, ft.pump_id
    ");
    $txn_cal_stmt->execute([$station_id]);
    foreach ($txn_cal_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim($row['fuel_type'])) . '_' . (int)$row['pump_id'];
        $pm_txn_lookup[$key] = $row;
    }
} catch (Exception $e) {}

// ── Build 17-row dataset ──────────────────────────────────────────────────────
$pump_master_fuel_types = [];
foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key  = strtolower(trim($tc['fuel_type']));
    $inv     = $pm_inv_lookup[$ft_key] ?? null;
    $txn_key = $ft_key . '_' . $tc['tanker_num'];
    $txn     = $pm_txn_lookup[$txn_key] ?? null;

    $cal_value  = $txn ? (float)$txn['calibration']      : ($inv ? (float)$inv['latest_calibration'] : 0);
    $cal_date   = $txn ? $txn['transaction_date']         : ($inv ? $inv['last_updated'] : null);
    $encoded_by = $txn ? ($txn['staff_name'] ?? '—')      : '—';
    $tank_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
    $capacity   = $inv ? (float)($inv['capacity'] ?? 0)   : 0;
    $fill_pct   = ($capacity > 0) ? min(100, round($tank_level / $capacity * 100, 1)) : 0;

    $pump_master_fuel_types[] = [
        'fuel_type'    => $tc['fuel_type'],
        'label'        => $tc['label'],
        'tank'         => $tc['tank'],
        'tanker_num'   => $tc['tanker_num'],
        'cal_value'    => $cal_value,
        'cal_date'     => $cal_date,
        'encoded_by'   => $encoded_by,
        'fuel_type_id' => $inv['fuel_type_id'] ?? null,
        'tank_level'   => $tank_level,
        'capacity'     => $capacity,
        'fill_pct'     => $fill_pct,
        'status'       => $cal_value > 0 ? 'Encoded' : 'No Reading',
    ];
}

// ── Pump calibration history (last 50 records) ───────────────────────────────
$cal_history = [];
try {
    $s = $pdo->prepare("
        SELECT pch.*, 
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, '—') AS manager_name
        FROM pump_calibration_history pch
        LEFT JOIN users u ON pch.updated_by = u.id
        WHERE pch.station_id = ?
        ORDER BY pch.updated_at DESC
        LIMIT 50
    ");
    $s->execute([$station_id]);
    $cal_history = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Calibration adjustments from fuel_adjustments ────────────────────────────
$cal_adjustments = [];
try {
    $s = $pdo->prepare("
        SELECT fa.*,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, '—') AS user_name
        FROM fuel_adjustments fa
        LEFT JOIN users u ON fa.user_id = u.id
        WHERE fa.station_id = ? AND fa.adjustment_type = 'Calibration'
        ORDER BY fa.created_at DESC
        LIMIT 50
    ");
    $s->execute([$station_id]);
    $cal_adjustments = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Summary counts ────────────────────────────────────────────────────────────
$total_tankers   = count($pump_master_fuel_types);
$encoded_cnt     = count(array_filter($pump_master_fuel_types, fn($t) => $t['cal_value'] > 0));
$no_reading_cnt  = $total_tankers - $encoded_cnt;
$cal_history_cnt = count($cal_history);

$date_filter = $_GET['date'] ?? date('Y-m-d');

include __DIR__ . '/../partials/header.php';
?>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-x:hidden;max-width:100vw}
/* ── Page Head ── */
.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap;margin-top:-12px !important;}
.page-head h1{margin:0 !important;font-size:22px !important;font-weight:700 !important;color:#00264D !important;text-transform:uppercase !important;letter-spacing:.5px;display:flex;align-items:center;gap:8px}
.page-head .sub{font-size:13px;color:#666;margin-top:4px;text-transform:none !important;}

:root{--blue:#002F70;--green:#28a745;--red:#dc3545;--orange:#fd7e14;--gray:#6c757d;}

.stats-row{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:22px;}
.stat-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;flex:1;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.stat-card .stat-num{font-size:1.6rem;font-weight:800;color:#002F70;}
.stat-card .stat-lbl{font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;margin-top:2px;}
.card{background:#fff;border:1px solid #e9ecef;border-radius:10px;margin-bottom:20px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.06);}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.card-title{font-size:.95rem;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px;}
.card-body{padding:16px 18px;}
.table-wrap{width:100%;}
table{width:100%;border-collapse:collapse;font-size:11px;table-layout:auto;}
thead th{background:var(--blue);color:#fff;padding:8px 6px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;line-height:1.3;}
tbody td{padding:6px 6px;border-bottom:1px solid #f0f0f0;vertical-align:middle;line-height:1.4;}
tbody td:nth-child(1){width:30px;white-space:nowrap;}
tbody td:nth-child(2){font-weight:600;white-space:nowrap;}
tbody td:nth-child(3){white-space:nowrap;}
tbody td:nth-child(4){font-size:10px;max-width:120px;}
tbody td:nth-child(5){white-space:nowrap;text-align:right;}
tbody td:nth-child(6){white-space:nowrap;text-align:right;font-size:10px;}
tbody td:nth-child(7){min-width:90px;}
tbody td:nth-child(8){white-space:nowrap;}
tbody td:nth-child(9){font-size:10px;max-width:100px;}
tbody td:nth-child(10){white-space:nowrap;font-size:10px;}
tbody tr:hover td{background:#e8f4fd;}
.badge{font-size:10px;font-weight:700;text-transform:uppercase;background:none !important;border:none !important;padding:0 !important;display:inline-flex;align-items:center;gap:3px;}
.badge-encoded{color:#28a745 !important;}
.badge-no-reading{color:#dc3545 !important;}
.fill-bar{height:8px;border-radius:4px;background:#e9ecef;min-width:70px;width:100%;max-width:100px;position:relative;}
.fill-bar-inner{height:100%;border-radius:4px;background:var(--green);transition:width .3s;}
.fill-bar-inner.low{background:#dc3545;}
.fill-bar-inner.mid{background:#fd7e14;}
.tab-nav{display:flex;gap:0;border-bottom:2px solid #e9ecef;margin-bottom:20px;}
.tab-btn{padding:10px 22px;background:none;border:none;border-bottom:3px solid transparent;font-size:13px;font-weight:600;color:var(--gray);cursor:pointer;margin-bottom:-2px;transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.tab-btn.active{color:var(--blue);border-bottom-color:var(--blue);}
.tab-btn:hover{color:var(--blue);}
.tab-panel{display:none;}.tab-panel.active{display:block;}
.var-pos{color:#dc3545;font-weight:700;}
.var-zero{color:#28a745;font-weight:700;}
</style>

<div class="page-head">
  <div>
    <h1><i class="fas fa-gas-pump"></i> Pump Master Oversight</h1>
    <div class="sub">Admin view of all 17 tanker calibration records and audit trail.</div>
  </div>
</div>

<!-- Summary Stats -->
<div class="stats-row">
  <div class="stat-card">
    <div class="stat-num"><?= $total_tankers ?></div>
    <div class="stat-lbl">Total Tankers</div>
  </div>
  <div class="stat-card">
    <div class="stat-num"><?= $encoded_cnt ?></div>
    <div class="stat-lbl">With Calibration</div>
  </div>
  <div class="stat-card">
    <div class="stat-num"><?= $no_reading_cnt ?></div>
    <div class="stat-lbl">No Reading</div>
  </div>
  <div class="stat-card">
    <div class="stat-num"><?= $cal_history_cnt ?></div>
    <div class="stat-lbl">Calibration Events</div>
  </div>
</div>

<!-- Tabs -->
<div class="tab-nav">
  <a href="#" class="tab-btn active" onclick="showTab('tankers',this);return false;"><i class="fas fa-list-alt"></i> 17 Tankers</a>
  <a href="#" class="tab-btn" onclick="showTab('history',this);return false;"><i class="fas fa-history"></i> Calibration History</a>
  <a href="#" class="tab-btn" onclick="showTab('adjustments',this);return false;"><i class="fas fa-sliders-h"></i> Adjustment Audit Trail</a>
</div>

<!-- TAB 1: 17-Tanker Grid -->
<div id="tab-tankers" class="tab-panel active">
  <div class="card">
    <div class="card-head">
      <div class="card-title"><i class="fas fa-gas-pump"></i> 17-Tanker Calibration Status</div>
      <span style="font-size:12px;color:var(--gray);">Read-only. Calibrations are managed by the Manager.</span>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Tanker</th>
              <th>Fuel</th>
              <th>Tank</th>
              <th>Cal. Value</th>
              <th>Level/Cap</th>
              <th>Fill %</th>
              <th>Status</th>
              <th>Encoder</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pump_master_fuel_types as $i => $row):
              $fill = $row['fill_pct'];
              $bar_class = $fill < 20 ? 'low' : ($fill < 50 ? 'mid' : '');
            ?>
            <tr>
              <td style="color:var(--gray);font-size:10px;"><?= $i + 1 ?></td>
              <td><strong style="font-size:11px;"><?= htmlspecialchars($row['label']) ?></strong></td>
              <td style="font-size:11px;"><?= htmlspecialchars($row['fuel_type']) ?></td>
              <td style="font-size:10px;color:var(--gray);"><?= htmlspecialchars($row['tank']) ?></td>
              <td style="text-align:right;font-weight:700;font-family:monospace;font-size:11px;">
                <?= $row['cal_value'] > 0 ? number_format($row['cal_value'], 3) . ' L' : '<span style="color:#ccc;">—</span>' ?>
              </td>
              <td style="text-align:right;font-size:10px;">
                <?php if ($row['capacity'] > 0): ?>
                  <?= number_format($row['tank_level'], 0) ?> / <?= number_format($row['capacity'], 0) ?> L
                <?php elseif ($row['tank_level'] > 0): ?>
                  <?= number_format($row['tank_level'], 0) ?> L
                <?php else: ?>
                  <span style="color:#ccc;">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($row['capacity'] > 0): ?>
                <div class="fill-bar">
                  <div class="fill-bar-inner <?= $bar_class ?>" style="width:<?= $fill ?>%;"></div>
                </div>
                <span style="font-size:9px;color:var(--gray);display:block;text-align:center;margin-top:2px;"><?= $fill ?>%</span>
                <?php else: ?>
                <span style="color:#ccc;font-size:10px;">—</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge badge-<?= $row['status'] === 'Encoded' ? 'encoded' : 'no-reading' ?>">
                  <?= $row['status'] === 'Encoded' ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>' ?>
                  <?= $row['status'] ?>
                </span>
              </td>
              <td style="font-size:10px;"><?= htmlspecialchars($row['encoded_by']) ?></td>
              <td style="font-size:10px;color:var(--gray);">
                <?= $row['cal_date'] ? date('M d, Y', strtotime($row['cal_date'])) : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- TAB 2: Calibration History -->
<div id="tab-history" class="tab-panel">
  <div class="card">
    <div class="card-head">
      <div class="card-title"><i class="fas fa-history"></i> Calibration Change History</div>
      <span style="font-size:12px;color:var(--gray);">Last 50 records</span>
    </div>
    <div class="card-body">
      <?php if (empty($cal_history)): ?>
        <div style="text-align:center;padding:40px;color:var(--gray);">
          <i class="fas fa-history" style="font-size:2rem;opacity:.3;display:block;margin-bottom:12px;"></i>
          <strong>No calibration history found.</strong>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Fuel Type</th>
              <th>Previous Cal (L)</th>
              <th>New Cal (L)</th>
              <th>Variance (L)</th>
              <th>Reason</th>
              <th>Updated By</th>
              <th>Updated At</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cal_history as $i => $r):
              $variance = round((float)$r['new_calibration'] - (float)$r['previous_calibration'], 3);
            ?>
            <tr>
              <td style="color:var(--gray);font-size:11px;"><?= $i + 1 ?></td>
              <td><strong><?= htmlspecialchars($r['fuel_type']) ?></strong></td>
              <td style="text-align:right;font-family:monospace;"><?= number_format((float)$r['previous_calibration'], 3) ?></td>
              <td style="text-align:right;font-weight:700;font-family:monospace;"><?= number_format((float)$r['new_calibration'], 3) ?></td>
              <td style="text-align:right;" class="<?= $variance != 0 ? 'var-pos' : 'var-zero' ?>">
                <?= ($variance >= 0 ? '+' : '') . number_format($variance, 3) ?>
              </td>
              <td style="font-size:11px;max-width:200px;"><?= htmlspecialchars($r['reason'] ?? '—') ?></td>
              <td style="font-size:11px;"><?= htmlspecialchars($r['manager_name']) ?></td>
              <td style="font-size:11px;white-space:nowrap;"><?= $r['updated_at'] ? date('M d, Y H:i', strtotime($r['updated_at'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- TAB 3: Adjustment Audit Trail -->
<div id="tab-adjustments" class="tab-panel">
  <div class="card">
    <div class="card-head">
      <div class="card-title"><i class="fas fa-sliders-h"></i> Calibration Adjustment Audit Trail</div>
      <span style="font-size:12px;color:var(--gray);">From fuel_adjustments table, type = Calibration</span>
    </div>
    <div class="card-body">
      <?php if (empty($cal_adjustments)): ?>
        <div style="text-align:center;padding:40px;color:var(--gray);">
          <i class="fas fa-sliders-h" style="font-size:2rem;opacity:.3;display:block;margin-bottom:12px;"></i>
          <strong>No calibration adjustments found.</strong>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Date</th>
              <th>Fuel Type</th>
              <th>Adjustment (L)</th>
              <th>Status</th>
              <th>Reason</th>
              <th>Encoded By</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cal_adjustments as $i => $r):
              $liters = (float)($r['liters'] ?? 0);
            ?>
            <tr>
              <td style="color:var(--gray);font-size:11px;"><?= $i + 1 ?></td>
              <td style="font-size:11px;white-space:nowrap;"><?= $r['adjustment_date'] ? date('M d, Y', strtotime($r['adjustment_date'])) : '—' ?></td>
              <td><strong><?= htmlspecialchars($r['fuel_type'] ?? '—') ?></strong></td>
              <td style="text-align:right;font-family:monospace;" class="<?= abs($liters) > 0 ? 'var-pos' : 'var-zero' ?>">
                <?= ($liters >= 0 ? '+' : '') . number_format($liters, 3) ?> L
              </td>
              <td>
                <span class="badge" style="color:<?= strtolower($r['status'] ?? '') === 'cleared' ? '#28a745' : '#fd7e14' ?> !important;">
                  <?= htmlspecialchars($r['status'] ?? 'Pending') ?>
                </span>
              </td>
              <td style="font-size:11px;max-width:220px;"><?= htmlspecialchars($r['reason'] ?? '—') ?></td>
              <td style="font-size:11px;"><?= htmlspecialchars($r['user_name']) ?></td>
              <td style="font-size:11px;white-space:nowrap;color:var(--gray);"><?= $r['created_at'] ? date('M d, Y H:i', strtotime($r['created_at'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function showTab(name, el) {
    document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.getElementById('tab-' + name).classList.add('active');
    el.classList.add('active');
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
