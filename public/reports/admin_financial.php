<?php
/**
 * Financial/Payables Report — accounts payable, collections, reconciliation
 */

// Collections by source
$collections = [];
try {
    $q = $pdo->prepare("SELECT 'Fuel' AS source, COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
        FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?
        UNION ALL
        SELECT 'Merchandise', COALESCE(SUM(total_amount),0), COUNT(*)
        FROM merchandise_transactions WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ?
        UNION ALL
        SELECT 'Job Orders (Completed)', COALESCE(SUM(total_cost),0), COUNT(*)
        FROM job_orders WHERE station_id=? AND status='Completed' AND DATE(created_at) BETWEEN ? AND ?");
    $q->execute([$station_id,$date_start,$date_end, $station_id,$date_start,$date_end, $station_id,$date_start,$date_end]);
    $collections = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Accounts payable (open POs)
$payables = [];
try {
    $q2 = $pdo->prepare("SELECT po.product_name, po.total_amount, po.status,
        po.created_at, COALESCE(s.name, po.supplier_name) AS supplier,
        po.expected_delivery_date AS due_date, po.type
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id=s.id
        WHERE po.station_id=? AND po.status NOT IN ('Received','Cancelled','Rejected by Admin','Admin Finalized')
        ORDER BY po.created_at DESC LIMIT 50");
    $q2->execute([$station_id]);
    $payables = $q2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Daily reconciliation: sales vs collections in period
$recon = [];
try {
    $d = new DateTime($date_start); $dend = new DateTime($date_end);
    while ($d <= $dend) {
        $date = $d->format('Y-m-d');
        $q3 = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=?");
        $q3->execute([$station_id,$date]); $fuel = (float)$q3->fetchColumn();
        $q4 = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND DATE(created_at)=?");
        $q4->execute([$station_id,$date]); $merch = (float)$q4->fetchColumn();
        $q5 = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM job_orders WHERE station_id=? AND status='Completed' AND DATE(created_at)=?");
        $q5->execute([$station_id,$date]); $jo = (float)$q5->fetchColumn();
        $total_sales = $fuel + $merch + $jo;
        if ($total_sales > 0) {
            $recon[] = compact('date','fuel','merch','jo','total_sales');
        }
        $d->modify('+1 day');
    }
} catch (Exception $e) {}

$total_collections = array_sum(array_column($collections,'total'));
$total_payables    = array_sum(array_column($payables,'total_amount'));
$total_recon       = array_sum(array_column($recon,'total_sales'));
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
  <div>
    <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-balance-scale"></i> Financial / Payables Report</h2>
    <p style="margin:0;color:#666;font-size:13px;">Collections, accounts payable, and daily reconciliation</p>
  </div>
  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px;">
  <div style="background:linear-gradient(135deg,#003366,#004d99);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Total Collections</div>
    <div style="font-size:22px;font-weight:700;">₱<?= number_format($total_collections,2) ?></div>
    <div style="font-size:11px;opacity:.75;">All revenue streams</div>
  </div>
  <div style="background:linear-gradient(135deg,#dc3545,#c82333);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Accounts Payable</div>
    <div style="font-size:22px;font-weight:700;">₱<?= number_format($total_payables,2) ?></div>
    <div style="font-size:11px;opacity:.75;"><?= count($payables) ?> open POs</div>
  </div>
  <div style="background:linear-gradient(135deg,#1a7c40,#228b22);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Period Revenue</div>
    <div style="font-size:22px;font-weight:700;">₱<?= number_format($total_recon,2) ?></div>
    <div style="font-size:11px;opacity:.75;"><?= count($recon) ?> active days</div>
  </div>
</div>

<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-file-invoice-dollar"></i> Collections by Source</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">
  <thead><tr><th>Revenue Source</th><th>Transactions</th><th>Total</th><th>% Share</th></tr></thead>
  <tbody>
  <?php foreach($collections as $c):
    $pct = $total_collections > 0 ? round($c['total']/$total_collections*100,1) : 0;
  ?>
    <tr>
      <td><strong><?= htmlspecialchars($c['source']) ?></strong></td>
      <td><?= $c['cnt'] ?></td>
      <td>₱<?= number_format($c['total'],2) ?></td>
      <td>
        <div style="display:flex;align-items:center;gap:6px;">
          <div style="width:80px;height:8px;background:#e0e0e0;border-radius:4px;">
            <div style="width:<?=$pct?>%;height:100%;background:#003366;border-radius:4px;"></div>
          </div><?= $pct ?>%
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  <tfoot><tr style="background:#f0f4ff;font-weight:700;"><td>TOTAL</td><td><?= array_sum(array_column($collections,'cnt')) ?></td><td>₱<?= number_format($total_collections,2) ?></td><td>100%</td></tr></tfoot>
</table>
</div>

<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-file-contract"></i> Open Accounts Payable</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">
  <thead><tr><th>Product/Service</th><th>Supplier</th><th>Amount</th><th>Type</th><th>Due Date</th><th>Status</th></tr></thead>
  <tbody>
  <?php if(empty($payables)): ?>
    <tr><td colspan="6" style="text-align:center;color:#28a745;padding:30px;font-weight:600;"><i class="fas fa-check-circle"></i> No open payables — all accounts settled.</td></tr>
  <?php else: foreach($payables as $p):
    $sc = '#fd7e14'; ?>
    <tr>
      <td><?= htmlspecialchars($p['product_name']) ?></td>
      <td><?= htmlspecialchars($p['supplier']??'—') ?></td>
      <td style="font-weight:700;">₱<?= number_format($p['total_amount'],2) ?></td>
      <td><?= htmlspecialchars($p['type']??'—') ?></td>
      <td><?= $p['due_date'] ? date('M j, Y', strtotime($p['due_date'])) : '—' ?></td>
      <td><span style="color:<?=$sc?>;font-weight:700;font-size:12px;"><?= htmlspecialchars($p['status']) ?></span></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-sync-alt"></i> Daily Reconciliation</h3>
<div style="overflow-x:auto;">
<table class="report-table">
  <thead><tr><th>Date</th><th>Fuel</th><th>Merchandise</th><th>Job Orders</th><th>Total Revenue</th></tr></thead>
  <tbody>
  <?php if(empty($recon)): ?>
    <tr><td colspan="5" style="text-align:center;color:#999;padding:30px;">No data in selected period.</td></tr>
  <?php else: foreach($recon as $r): ?>
    <tr>
      <td><strong><?= $r['date'] ?></strong></td>
      <td>₱<?= number_format($r['fuel'],2) ?></td>
      <td>₱<?= number_format($r['merch'],2) ?></td>
      <td>₱<?= number_format($r['jo'],2) ?></td>
      <td><strong>₱<?= number_format($r['total_sales'],2) ?></strong></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
  <?php if(!empty($recon)): ?>
  <tfoot><tr style="background:#f0f4ff;font-weight:700;"><td>TOTAL</td>
    <td>₱<?= number_format(array_sum(array_column($recon,'fuel')),2) ?></td>
    <td>₱<?= number_format(array_sum(array_column($recon,'merch')),2) ?></td>
    <td>₱<?= number_format(array_sum(array_column($recon,'jo')),2) ?></td>
    <td>₱<?= number_format($total_recon,2) ?></td>
  </tr></tfoot>
  <?php endif; ?>
</table>
</div>
