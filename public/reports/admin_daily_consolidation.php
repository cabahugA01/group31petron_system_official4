<?php
/**
 * Daily Consolidation Report — Shift 1 + Shift 2 totals with real charts
 */

$daily = [];
try {
    $d = new DateTime($date_start); $dend = new DateTime($date_end);
    while ($d <= $dend) {
        $date = $d->format('Y-m-d');
        $s1 = "$date 06:00:00"; $e2 = "$date 22:00:00";

        $q = $pdo->prepare("SELECT
            COALESCE(SUM(total_amount),0) AS fuel_rev,
            COALESCE(SUM(liters_sold),0) AS fuel_ltr,
            COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%cash%' THEN total_amount ELSE 0 END),0) AS cash,
            COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%card%' AND LOWER(payment_method) NOT LIKE '%fleet%' AND LOWER(payment_method) NOT LIKE '%fuel%' THEN total_amount ELSE 0 END),0) AS card,
            COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%wallet%' OR LOWER(payment_method) LIKE '%gcash%' OR LOWER(payment_method) LIKE '%maya%' THEN total_amount ELSE 0 END),0) AS ewallet,
            COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%fleet%' THEN total_amount ELSE 0 END),0) AS fleet,
            COALESCE(SUM(CASE WHEN LOWER(payment_method) LIKE '%efuel%' OR LOWER(payment_method) LIKE '%fuel card%' THEN total_amount ELSE 0 END),0) AS efuel
            FROM fuel_transactions WHERE station_id=? AND transaction_date BETWEEN ? AND ?");
        $q->execute([$station_id, $s1, $e2]);
        $fuel = $q->fetch(PDO::FETCH_ASSOC);

        $q2 = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS merch_rev, COUNT(*) AS merch_cnt
            FROM merchandise_transactions WHERE station_id=? AND created_at BETWEEN ? AND ?");
        $q2->execute([$station_id, $s1, $e2]); $merch = $q2->fetch(PDO::FETCH_ASSOC);

        $q3 = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) AS svc_rev, COUNT(*) AS jo_total,
            SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS jo_done,
            SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) AS jo_pending,
            SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) AS jo_wip,
            SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) AS jo_cancel
            FROM job_orders WHERE station_id=? AND created_at BETWEEN ? AND ?");
        $q3->execute([$station_id, $s1, $e2]); $jo = $q3->fetch(PDO::FETCH_ASSOC);

        $q4 = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE station_id=? AND created_at BETWEEN ? AND ?");
        $q4->execute([$station_id, $s1, $e2]); $cust = (int)$q4->fetchColumn();

        $fuel_rev  = (float)$fuel['fuel_rev'];
        $fuel_ltr  = (float)$fuel['fuel_ltr'];
        $merch_rev = (float)$merch['merch_rev'];
        $svc_rev   = (float)$jo['svc_rev'];
        $total_rev = $fuel_rev + $merch_rev + $svc_rev;
        $total_pay = array_sum([$fuel['cash'],$fuel['card'],$fuel['ewallet'],$fuel['fleet'],$fuel['efuel']]);

        $daily[] = compact('date','fuel_rev','fuel_ltr','merch_rev','svc_rev','total_rev','total_pay','cust') +
                   ['merch_cnt'=>(int)$merch['merch_cnt'],'jo_total'=>(int)$jo['jo_total'],
                    'jo_done'=>(int)$jo['jo_done'],'jo_pending'=>(int)$jo['jo_pending'],
                    'jo_wip'=>(int)$jo['jo_wip'],'jo_cancel'=>(int)$jo['jo_cancel'],
                    'cash'=>(float)$fuel['cash'],'card'=>(float)$fuel['card'],
                    'ewallet'=>(float)$fuel['ewallet'],'fleet'=>(float)$fuel['fleet'],'efuel'=>(float)$fuel['efuel']];
        $d->modify('+1 day');
    }
} catch (Exception $ex) {}

$tot = array_fill_keys(['fuel_rev','fuel_ltr','merch_rev','svc_rev','total_rev','total_pay','cust','jo_done'], 0);
foreach ($daily as $row) foreach ($tot as $k => $_) $tot[$k] += $row[$k] ?? 0;
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
  <div>
    <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-calendar-day"></i> Daily Consolidation</h2>
    <p style="margin:0;color:#666;font-size:13px;">Combined Shift 1 + Shift 2 totals with charts</p>
  </div>
  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<!-- KPI Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px;">
<?php
$cards=[
  ['Total Revenue','₱'.number_format($tot['total_rev'],2),'Fuel+Merch+Services','#003366','#004d99'],
  ['Fuel Sales','₱'.number_format($tot['fuel_rev'],2),number_format($tot['fuel_ltr'],2).' Liters','#1a5276','#21618c'],
  ['Merchandise','₱'.number_format($tot['merch_rev'],2),'Retail sales','#1a7c40','#228b22'],
  ['Service Income','₱'.number_format($tot['svc_rev'],2),$tot['jo_done'].' completed JOs','#c55a00','#e06c00'],
  ['Total Payments','₱'.number_format($tot['total_pay'],2),'All modes','#6b21a8','#7c3aed'],
  ['New Customers',$tot['cust'],'Added this period','#b91c1c','#dc2626'],
];
foreach($cards as [$l,$v,$s,$c1,$c2]): ?>
  <div style="background:linear-gradient(135deg,<?=$c1?>,<?=$c2?>);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;margin-bottom:4px;"><?=$l?></div>
    <div style="font-size:22px;font-weight:700;margin-bottom:2px;"><?=$v?></div>
    <div style="font-size:11px;opacity:.75;"><?=$s?></div>
  </div>
<?php endforeach; ?>
</div>

<!-- Charts Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(400px,1fr));gap:20px;margin-bottom:28px;">
  <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px;">
    <h3 style="margin:0 0 12px;color:#003366;font-size:15px;">Fuel Sales by Day (Bar)</h3>
    <canvas id="dcFuelChart" height="160"></canvas>
  </div>
  <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px;">
    <h3 style="margin:0 0 12px;color:#003366;font-size:15px;">Revenue Mix (Pie)</h3>
    <canvas id="dcMixChart" height="160"></canvas>
  </div>
  <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px;">
    <h3 style="margin:0 0 12px;color:#003366;font-size:15px;">Job Orders Trend (Line)</h3>
    <canvas id="dcJoChart" height="160"></canvas>
  </div>
  <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px;">
    <h3 style="margin:0 0 12px;color:#003366;font-size:15px;">Payments by Mode (Stacked Bar)</h3>
    <canvas id="dcPayChart" height="160"></canvas>
  </div>
</div>

<!-- Daily Table -->
<?php if(empty($daily)): ?>
  <div style="text-align:center;padding:60px;color:#999;"><i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:12px;"></i>No data for selected period.</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="report-table">
  <thead>
    <tr>
      <th>Date</th><th>Fuel Sales</th><th>Liters</th><th>Merchandise</th>
      <th>Service Income</th><th>Total Revenue</th><th>JO Total</th>
      <th>Done</th><th>Pending</th><th>In Progress</th><th>Customers</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($daily as $r): ?>
    <tr>
      <td><strong><?= $r['date'] ?></strong></td>
      <td>₱<?= number_format($r['fuel_rev'],2) ?></td>
      <td><?= number_format($r['fuel_ltr'],2) ?> L</td>
      <td>₱<?= number_format($r['merch_rev'],2) ?></td>
      <td>₱<?= number_format($r['svc_rev'],2) ?></td>
      <td><strong>₱<?= number_format($r['total_rev'],2) ?></strong></td>
      <td><?= $r['jo_total'] ?></td>
      <td style="color:#28a745;font-weight:600;"><?= $r['jo_done'] ?></td>
      <td style="color:#fd7e14;"><?= $r['jo_pending'] ?></td>
      <td style="color:#007bff;"><?= $r['jo_wip'] ?></td>
      <td><?= $r['cust'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr style="background:#f0f4ff;font-weight:700;">
      <td>TOTAL</td>
      <td>₱<?= number_format($tot['fuel_rev'],2) ?></td>
      <td><?= number_format($tot['fuel_ltr'],2) ?> L</td>
      <td>₱<?= number_format($tot['merch_rev'],2) ?></td>
      <td>₱<?= number_format($tot['svc_rev'],2) ?></td>
      <td>₱<?= number_format($tot['total_rev'],2) ?></td>
      <td colspan="5"></td>
    </tr>
  </tfoot>
</table>
</div>

<script>
(function(){
  const dates = <?= json_encode(array_column($daily,'date')) ?>;
  const fuel  = <?= json_encode(array_column($daily,'fuel_rev')) ?>;
  const merch = <?= json_encode(array_column($daily,'merch_rev')) ?>;
  const svc   = <?= json_encode(array_column($daily,'svc_rev')) ?>;
  const jo    = <?= json_encode(array_column($daily,'jo_done')) ?>;
  const cash  = <?= json_encode(array_column($daily,'cash')) ?>;
  const card  = <?= json_encode(array_column($daily,'card')) ?>;
  const ew    = <?= json_encode(array_column($daily,'ewallet')) ?>;
  const fleet = <?= json_encode(array_column($daily,'fleet')) ?>;

  new Chart(document.getElementById('dcFuelChart'),{type:'bar',data:{labels:dates,datasets:[{label:'Fuel ₱',data:fuel,backgroundColor:'rgba(0,51,102,.75)',borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});

  const totFuel=fuel.reduce((a,b)=>a+b,0), totMerch=merch.reduce((a,b)=>a+b,0), totSvc=svc.reduce((a,b)=>a+b,0);
  new Chart(document.getElementById('dcMixChart'),{type:'pie',data:{labels:['Fuel','Merchandise','Services'],datasets:[{data:[totFuel,totMerch,totSvc],backgroundColor:['#003366','#28a745','#fd7e14']}]},options:{responsive:true}});

  new Chart(document.getElementById('dcJoChart'),{type:'line',data:{labels:dates,datasets:[{label:'Completed JOs',data:jo,borderColor:'#dc3545',backgroundColor:'rgba(220,53,69,.1)',tension:.4,fill:true,pointRadius:5}]},options:{responsive:true,scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});

  new Chart(document.getElementById('dcPayChart'),{type:'bar',data:{labels:dates,datasets:[
    {label:'Cash',data:cash,backgroundColor:'#28a745'},
    {label:'Card',data:card,backgroundColor:'#007bff'},
    {label:'E-Wallet',data:ew,backgroundColor:'#fd7e14'},
    {label:'Fleet',data:fleet,backgroundColor:'#6c757d'},
  ]},options:{responsive:true,scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true}}}});
})();
</script>
<?php endif; ?>
