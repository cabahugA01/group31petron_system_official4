<?php
/**
 * Payments Report — breakdown by mode, vs sales totals variance
 */

// Fuel payments by method
$fuel_pay = [];
try {
    $q = $pdo->prepare("SELECT
        COALESCE(NULLIF(TRIM(payment_method),''),'Cash') AS method,
        COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total
        FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?
        GROUP BY method ORDER BY total DESC");
    $q->execute([$station_id, $date_start, $date_end]); $fuel_pay = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Merch payments by method
$merch_pay = [];
try {
    $q2 = $pdo->prepare("SELECT
        COALESCE(NULLIF(TRIM(payment_method),''),'Cash') AS method,
        COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total
        FROM merchandise_transactions WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY method ORDER BY total DESC");
    $q2->execute([$station_id, $date_start, $date_end]); $merch_pay = $q2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// JO payments by method
$jo_pay = [];
try {
    $q3 = $pdo->prepare("SELECT
        COALESCE(NULLIF(TRIM(payment_method),''),'Cash') AS method,
        COUNT(*) AS cnt, COALESCE(SUM(total_cost),0) AS total
        FROM job_orders WHERE station_id=? AND status='Completed'
        AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY method ORDER BY total DESC");
    $q3->execute([$station_id, $date_start, $date_end]); $jo_pay = $q3->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Normalize method names into canonical buckets
function normalizeMethod(string $m): string {
    $m = strtolower(trim($m));
    if (str_contains($m,'cash'))   return 'Cash';
    if (str_contains($m,'fleet'))  return 'Fleet Card';
    if (str_contains($m,'efuel') || str_contains($m,'fuel card')) return 'E-Fuel Card';
    if (str_contains($m,'card'))   return 'Card (Credit/Debit)';
    if (str_contains($m,'wallet') || str_contains($m,'gcash') || str_contains($m,'maya')) return 'E-Wallet';
    return ucwords($m) ?: 'Other';
}

$combined = [];
foreach ([$fuel_pay, $merch_pay, $jo_pay] as $src => $rows) {
    $label = ['Fuel', 'Merchandise', 'Job Orders'][$src];
    foreach ($rows as $r) {
        $key = normalizeMethod($r['method']);
        if (!isset($combined[$key])) $combined[$key] = ['method'=>$key,'fuel'=>0,'merch'=>0,'jo'=>0,'total'=>0,'cnt'=>0];
        $combined[$key][$src === 0 ? 'fuel' : ($src === 1 ? 'merch' : 'jo')] += (float)$r['total'];
        $combined[$key]['total'] += (float)$r['total'];
        $combined[$key]['cnt']   += (int)$r['cnt'];
    }
}
usort($combined, fn($a,$b) => $b['total'] <=> $a['total']);

// Total sales for variance
$total_fuel_sales  = array_sum(array_column($fuel_pay,'total'));
$total_merch_sales = array_sum(array_column($merch_pay,'total'));
$total_jo_sales    = array_sum(array_column($jo_pay,'total'));
$total_sales       = $total_fuel_sales + $total_merch_sales + $total_jo_sales;
$total_collected   = array_sum(array_column($combined,'total'));
$variance          = $total_collected - $total_sales;
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
  <div>
    <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-money-bill-wave"></i> Payments Report</h2>
    <p style="margin:0;color:#666;font-size:13px;">Collections by payment mode — Fuel, Merchandise, Job Orders</p>
  </div>
  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<!-- KPI Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:16px;margin-bottom:28px;">
  <div style="background:linear-gradient(135deg,#003366,#004d99);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Total Collected</div>
    <div style="font-size:22px;font-weight:700;">₱<?= number_format($total_collected,2) ?></div>
    <div style="font-size:11px;opacity:.75;">All payment modes</div>
  </div>
  <div style="background:linear-gradient(135deg,#1a5276,#21618c);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Fuel Collections</div>
    <div style="font-size:22px;font-weight:700;">₱<?= number_format($total_fuel_sales,2) ?></div>
  </div>
  <div style="background:linear-gradient(135deg,#1a7c40,#228b22);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Merch Collections</div>
    <div style="font-size:22px;font-weight:700;">₱<?= number_format($total_merch_sales,2) ?></div>
  </div>
  <div style="background:linear-gradient(135deg,#c55a00,#e06c00);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">JO Collections</div>
    <div style="font-size:22px;font-weight:700;">₱<?= number_format($total_jo_sales,2) ?></div>
  </div>
  <div style="background:linear-gradient(135deg,<?= abs($variance) < 1 ? '#1a7c40,#228b22' : '#dc3545,#c82333' ?>);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Variance</div>
    <div style="font-size:22px;font-weight:700;">₱<?= number_format(abs($variance),2) ?></div>
    <div style="font-size:11px;opacity:.75;"><?= $variance == 0 ? 'Balanced' : ($variance > 0 ? 'Over by' : 'Short by') ?></div>
  </div>
</div>

<!-- Combined by Mode Table -->
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-th"></i> Collections by Payment Mode</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">
  <thead>
    <tr>
      <th>Payment Mode</th><th>Fuel Sales</th><th>Merchandise</th><th>Job Orders</th>
      <th>Total Collected</th><th>Transactions</th><th>% Share</th>
    </tr>
  </thead>
  <tbody>
  <?php if(empty($combined)): ?>
    <tr><td colspan="7" style="text-align:center;color:#999;padding:40px;"><i class="fas fa-inbox" style="display:block;font-size:30px;margin-bottom:8px;"></i>No payment data for selected period.</td></tr>
  <?php else: foreach($combined as $r):
    $pct = $total_collected > 0 ? round($r['total']/$total_collected*100,1) : 0;
  ?>
    <tr>
      <td><strong><?= htmlspecialchars($r['method']) ?></strong></td>
      <td>₱<?= number_format($r['fuel'],2) ?></td>
      <td>₱<?= number_format($r['merch'],2) ?></td>
      <td>₱<?= number_format($r['jo'],2) ?></td>
      <td><strong>₱<?= number_format($r['total'],2) ?></strong></td>
      <td><?= $r['cnt'] ?></td>
      <td>
        <div style="display:flex;align-items:center;gap:6px;">
          <div style="width:80px;height:8px;background:#e0e0e0;border-radius:4px;">
            <div style="width:<?=$pct?>%;height:100%;background:#003366;border-radius:4px;"></div>
          </div>
          <span><?= $pct ?>%</span>
        </div>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
  <?php if(!empty($combined)): ?>
  <tfoot>
    <tr style="background:#f0f4ff;font-weight:700;">
      <td>TOTAL</td>
      <td>₱<?= number_format($total_fuel_sales,2) ?></td>
      <td>₱<?= number_format($total_merch_sales,2) ?></td>
      <td>₱<?= number_format($total_jo_sales,2) ?></td>
      <td>₱<?= number_format($total_collected,2) ?></td>
      <td><?= array_sum(array_column($combined,'cnt')) ?></td>
      <td>100%</td>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>
</div>

<!-- Chart -->
<?php if(!empty($combined)): ?>
<div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:20px;max-width:600px;">
  <h3 style="margin:0 0 12px;color:#003366;font-size:15px;">Payments by Mode (Pie)</h3>
  <canvas id="payPieChart" height="200"></canvas>
</div>
<script>
new Chart(document.getElementById('payPieChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($combined,'method')) ?>,
        datasets: [{
            data: <?= json_encode(array_values(array_map(fn($r)=>round($r['total'],2), $combined))) ?>,
            backgroundColor: ['#003366','#28a745','#fd7e14','#007bff','#dc3545','#6c757d','#17a2b8']
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'right' } } }
});
</script>
<?php endif; ?>
