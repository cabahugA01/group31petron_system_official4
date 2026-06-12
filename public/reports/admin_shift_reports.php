<?php
/**
 * Admin Shift Reports - Real data from DB
 * Shift 1: 06:00-14:00 | Shift 2: 14:00-22:00
 */

// Shift boundaries
$shift_defs = [
    1 => ['label' => 'Shift 1 (6AM–2PM)',  'start' => '06:00:00', 'end' => '14:00:00', 'keys' => ['first','First Shift','1']],
    2 => ['label' => 'Shift 2 (2PM–10PM)', 'start' => '14:00:00', 'end' => '22:00:00', 'keys' => ['second','Second Shift','2']],
];

// Build per-shift, per-day data
$shift_rows = [];
try {
    // Generate list of dates in range
    $d = new DateTime($date_start);
    $dend = new DateTime($date_end);
    while ($d <= $dend) {
        $date = $d->format('Y-m-d');
        foreach ($shift_defs as $snum => $sdef) {
            $s = "$date {$sdef['start']}";
            $e = "$date {$sdef['end']}";
            $keys = $sdef['keys'];

            // Fuel
            $q = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS rev, COALESCE(SUM(liters_sold),0) AS liters
                FROM fuel_transactions WHERE station_id=? AND (transaction_date BETWEEN ? AND ?)");
            $q->execute([$station_id, $s, $e]);
            $fuel = $q->fetch(PDO::FETCH_ASSOC);

            // Merch
            $q2 = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS rev, COUNT(*) AS cnt
                FROM merchandise_transactions WHERE station_id=? AND (created_at BETWEEN ? AND ?)");
            $q2->execute([$station_id, $s, $e]);
            $merch = $q2->fetch(PDO::FETCH_ASSOC);

            // Service (job orders)
            $q3 = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) AS rev, COUNT(*) AS cnt
                FROM job_orders WHERE station_id=? AND status='Completed'
                AND (created_at BETWEEN ? AND ?)");
            $q3->execute([$station_id, $s, $e]);
            $service = $q3->fetch(PDO::FETCH_ASSOC);

            // Payments breakdown
            $q4 = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%cash%' THEN total_amount ELSE 0 END),0) AS cash,
                COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%card%' AND LOWER(payment_method) NOT LIKE '%fleet%' AND LOWER(payment_method) NOT LIKE '%fuel%' THEN total_amount ELSE 0 END),0) AS card,
                COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%wallet%' OR LOWER(payment_method) LIKE '%gcash%' OR LOWER(payment_method) LIKE '%maya%' THEN total_amount ELSE 0 END),0) AS ewallet,
                COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%fleet%' THEN total_amount ELSE 0 END),0) AS fleet,
                COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%fuel card%' OR LOWER(payment_method) LIKE '%efuel%' THEN total_amount ELSE 0 END),0) AS efuel
                FROM fuel_transactions WHERE station_id=? AND transaction_date BETWEEN ? AND ?");
            $q4->execute([$station_id, $s, $e]);
            $pay = $q4->fetch(PDO::FETCH_ASSOC);

            // Customers added
            $q5 = $pdo->prepare("SELECT COUNT(*) AS cnt FROM customers WHERE station_id=? AND created_at BETWEEN ? AND ?");
            $q5->execute([$station_id, $s, $e]);
            $cust = $q5->fetchColumn();

            // Staff who clocked in
            $q6 = $pdo->prepare("SELECT CONCAT(u.first_name,' ',u.last_name) AS name FROM labor_sessions ls
                JOIN users u ON ls.user_id=u.id WHERE ls.station_id=? AND ls.start_time BETWEEN ? AND ? LIMIT 3");
            $q6->execute([$station_id, $s, $e]);
            $staff = implode(', ', array_column($q6->fetchAll(PDO::FETCH_ASSOC), 'name')) ?: '—';

            $fuel_rev  = (float)$fuel['rev'];
            $merch_rev = (float)$merch['rev'];
            $svc_rev   = (float)$service['rev'];
            $total_pay = array_sum(array_values($pay));

            if ($fuel_rev > 0 || $merch_rev > 0 || $svc_rev > 0 || $service['cnt'] > 0) {
                $shift_rows[] = [
                    'date'       => $date,
                    'shift'      => $sdef['label'],
                    'shift_num'  => $snum,
                    'staff'      => $staff,
                    'fuel_rev'   => $fuel_rev,
                    'fuel_ltr'   => (float)$fuel['liters'],
                    'merch_rev'  => $merch_rev,
                    'merch_cnt'  => (int)$merch['cnt'],
                    'svc_rev'    => $svc_rev,
                    'jo_cnt'     => (int)$service['cnt'],
                    'cash'       => (float)$pay['cash'],
                    'card'       => (float)$pay['card'],
                    'ewallet'    => (float)$pay['ewallet'],
                    'fleet'      => (float)$pay['fleet'],
                    'efuel'      => (float)$pay['efuel'],
                    'total_pay'  => $total_pay,
                    'customers'  => (int)$cust,
                ];
            }
        }
        $d->modify('+1 day');
    }
} catch (Exception $ex) { /* silent */ }

// Aggregates
$agg = ['fuel_rev'=>0,'fuel_ltr'=>0,'merch_rev'=>0,'merch_cnt'=>0,'svc_rev'=>0,'jo_cnt'=>0,'total_pay'=>0,'customers'=>0];
foreach ($shift_rows as $r) foreach ($agg as $k => $_) $agg[$k] += $r[$k];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
  <div>
    <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-clock"></i> Shift Reports</h2>
    <p style="margin:0;color:#666;font-size:13px;">
      Detailed breakdown per shift &mdash;
      <?= date('M j, Y', strtotime($date_start)) ?>
      <?= $date_start !== $date_end ? '&ndash; '.date('M j, Y', strtotime($date_end)) : '' ?>
    </p>
  </div>
  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;">
    <i class="fas fa-print"></i> Print
  </button>
</div>

<!-- KPI Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;">
<?php
$cards = [
    ['Fuel Revenue',    '₱'.number_format($agg['fuel_rev'],2), number_format($agg['fuel_ltr'],2).' L', '#003366','#004d99'],
    ['Merchandise',     '₱'.number_format($agg['merch_rev'],2), $agg['merch_cnt'].' transactions',    '#1a7c40','#228b22'],
    ['Service Income',  '₱'.number_format($agg['svc_rev'],2),  $agg['jo_cnt'].' job orders',          '#c55a00','#e06c00'],
    ['Payments Coll.',  '₱'.number_format($agg['total_pay'],2),'All modes combined',                  '#6b21a8','#7c3aed'],
    ['New Customers',   $agg['customers'],                       'Added during period',                 '#b91c1c','#dc2626'],
];
foreach ($cards as [$lbl,$val,$sub,$c1,$c2]): ?>
  <div style="background:linear-gradient(135deg,<?=$c1?>,<?=$c2?>);padding:18px;border-radius:10px;color:#fff;box-shadow:0 4px 8px rgba(0,0,0,.15);">
    <div style="font-size:12px;opacity:.85;margin-bottom:4px;"><?=$lbl?></div>
    <div style="font-size:22px;font-weight:700;margin-bottom:2px;"><?=$val?></div>
    <div style="font-size:11px;opacity:.75;"><?=$sub?></div>
  </div>
<?php endforeach; ?>
</div>

<!-- Shift Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:16px;" id="srTabs">
  <button class="sr-tab sr-active" onclick="filterShift('all',this)">All Shifts</button>
  <button class="sr-tab" onclick="filterShift(1,this)">Shift 1 (6AM–2PM)</button>
  <button class="sr-tab" onclick="filterShift(2,this)">Shift 2 (2PM–10PM)</button>
</div>
<style>
.sr-tab{padding:8px 20px;border:2px solid #003366;border-radius:6px;font-weight:600;cursor:pointer;background:#fff;color:#003366;transition:all .2s;}
.sr-tab.sr-active,.sr-tab:hover{background:#003366;color:#fff;}
</style>

<!-- Detail Table -->
<?php if (empty($shift_rows)): ?>
  <div style="text-align:center;padding:60px;color:#999;">
    <i class="fas fa-inbox" style="font-size:40px;margin-bottom:12px;display:block;"></i>
    No shift data found for the selected date range.
  </div>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="report-table" id="srTable">
  <thead>
    <tr>
      <th>Date</th><th>Shift</th><th>Staff</th>
      <th>Fuel Sales</th><th>Liters</th>
      <th>Merchandise</th><th>Merch Txns</th>
      <th>Service Income</th><th>Job Orders</th>
      <th>Cash</th><th>Card</th><th>E-Wallet</th><th>Fleet</th>
      <th>Total Payments</th><th>New Customers</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($shift_rows as $r): ?>
    <tr data-shift="<?= $r['shift_num'] ?>">
      <td><?= htmlspecialchars($r['date']) ?></td>
      <td><strong><?= htmlspecialchars($r['shift']) ?></strong></td>
      <td style="font-size:12px;"><?= htmlspecialchars($r['staff']) ?></td>
      <td>₱<?= number_format($r['fuel_rev'],2) ?></td>
      <td><?= number_format($r['fuel_ltr'],2) ?> L</td>
      <td>₱<?= number_format($r['merch_rev'],2) ?></td>
      <td><?= $r['merch_cnt'] ?></td>
      <td>₱<?= number_format($r['svc_rev'],2) ?></td>
      <td><?= $r['jo_cnt'] ?></td>
      <td>₱<?= number_format($r['cash'],2) ?></td>
      <td>₱<?= number_format($r['card'],2) ?></td>
      <td>₱<?= number_format($r['ewallet'],2) ?></td>
      <td>₱<?= number_format($r['fleet'],2) ?></td>
      <td><strong>₱<?= number_format($r['total_pay'],2) ?></strong></td>
      <td><?= $r['customers'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr style="background:#f0f4ff;font-weight:700;">
      <td colspan="3">TOTAL</td>
      <td>₱<?= number_format($agg['fuel_rev'],2) ?></td>
      <td><?= number_format($agg['fuel_ltr'],2) ?> L</td>
      <td>₱<?= number_format($agg['merch_rev'],2) ?></td>
      <td><?= $agg['merch_cnt'] ?></td>
      <td>₱<?= number_format($agg['svc_rev'],2) ?></td>
      <td><?= $agg['jo_cnt'] ?></td>
      <td colspan="4"></td>
      <td>₱<?= number_format($agg['total_pay'],2) ?></td>
      <td><?= $agg['customers'] ?></td>
    </tr>
  </tfoot>
</table>
</div>
<?php endif; ?>

<script>
function filterShift(shift, btn) {
    document.querySelectorAll('.sr-tab').forEach(b => b.classList.remove('sr-active'));
    btn.classList.add('sr-active');
    document.querySelectorAll('#srTable tbody tr').forEach(row => {
        row.style.display = (shift === 'all' || row.dataset.shift == shift) ? '' : 'none';
    });
}
</script>
