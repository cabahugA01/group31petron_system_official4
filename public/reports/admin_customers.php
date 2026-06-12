<?php
/**
 * Customer Report — new customers per shift, balances, transaction history
 */

// New customers in period
$new_customers = [];
try {
    $q = $pdo->prepare("SELECT c.*,
        (SELECT COUNT(*) FROM fuel_transactions ft WHERE ft.station_id=c.station_id AND DATE(ft.transaction_date) BETWEEN ? AND ? LIMIT 1) AS _ignore
        FROM customers c WHERE c.station_id=? AND DATE(c.created_at) BETWEEN ? AND ?
        ORDER BY c.created_at DESC");
    $q->execute([$date_start, $date_end, $station_id, $date_start, $date_end]);
    $new_customers = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Credit customers with balances
$credit_customers = [];
try {
    $q2 = $pdo->prepare("SELECT name, type, COALESCE(current_balance,balance,0) AS balance,
        credit_limit, status, contact_number, created_at, payment_terms
        FROM customers WHERE station_id=? AND type='credit'
        ORDER BY balance DESC LIMIT 50");
    $q2->execute([$station_id]);
    $credit_customers = $q2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// New per shift (split by time)
$shift1_new = array_filter($new_customers, function($c) {
    $h = (int)date('H', strtotime($c['created_at']));
    return $h >= 6 && $h < 14;
});
$shift2_new = array_filter($new_customers, function($c) {
    $h = (int)date('H', strtotime($c['created_at']));
    return $h >= 14 && $h < 22;
});

$total_credit_balance = array_sum(array_column($credit_customers,'balance'));
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
  <div>
    <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-users"></i> Customer Report</h2>
    <p style="margin:0;color:#666;font-size:13px;">New registrations by shift, credit balances, account status</p>
  </div>
  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<!-- KPI Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:16px;margin-bottom:28px;">
  <div style="background:linear-gradient(135deg,#003366,#004d99);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">New Customers</div>
    <div style="font-size:26px;font-weight:700;"><?= count($new_customers) ?></div>
    <div style="font-size:11px;opacity:.75;">Added this period</div>
  </div>
  <div style="background:linear-gradient(135deg,#1a5276,#21618c);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Shift 1 New</div>
    <div style="font-size:26px;font-weight:700;"><?= count($shift1_new) ?></div>
    <div style="font-size:11px;opacity:.75;">6AM–2PM</div>
  </div>
  <div style="background:linear-gradient(135deg,#c55a00,#e06c00);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Shift 2 New</div>
    <div style="font-size:26px;font-weight:700;"><?= count($shift2_new) ?></div>
    <div style="font-size:11px;opacity:.75;">2PM–10PM</div>
  </div>
  <div style="background:linear-gradient(135deg,#dc3545,#c82333);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Total Credit Balance</div>
    <div style="font-size:20px;font-weight:700;">₱<?= number_format($total_credit_balance,2) ?></div>
    <div style="font-size:11px;opacity:.75;"><?= count($credit_customers) ?> credit accounts</div>
  </div>
</div>

<!-- New Customers Table -->
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-user-plus"></i> New Customers This Period</h3>
<?php if(empty($new_customers)): ?>
<div style="text-align:center;padding:40px;color:#999;background:#f9f9f9;border-radius:8px;margin-bottom:24px;">
  <i class="fas fa-user-slash" style="font-size:30px;display:block;margin-bottom:8px;"></i>No new customers for selected period.
</div>
<?php else: ?>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">
  <thead>
    <tr>
      <th>Name</th><th>Type</th><th>Contact</th><th>Status</th>
      <th>Shift Registered</th><th>Registered At</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($new_customers as $c):
    $h = (int)date('H', strtotime($c['created_at']));
    $shift_label = $h >= 6 && $h < 14 ? 'Shift 1 (6AM–2PM)' : ($h >= 14 && $h < 22 ? 'Shift 2 (2PM–10PM)' : 'Outside Shifts');
  ?>
    <tr>
      <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
      <td><span style="background:<?= $c['type']==='credit'?'#dc3545':'#28a745' ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;"><?= strtoupper($c['type']) ?></span></td>
      <td><?= htmlspecialchars($c['contact_number']??$c['phone']??'—') ?></td>
      <td><?= htmlspecialchars($c['status']??$c['account_status']??'active') ?></td>
      <td><?= $shift_label ?></td>
      <td><?= date('M j, Y g:i A', strtotime($c['created_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- Credit Accounts with Balances -->
<?php if(!empty($credit_customers)): ?>
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-hand-holding-usd"></i> Credit Account Balances</h3>
<div style="overflow-x:auto;">
<table class="report-table">
  <thead>
    <tr>
      <th>Customer</th><th>Outstanding Balance</th><th>Credit Limit</th>
      <th>Utilization %</th><th>Payment Terms</th><th>Status</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($credit_customers as $c):
    $bal = (float)$c['balance'];
    $lim = (float)($c['credit_limit']??0);
    $util = $lim > 0 ? round($bal/$lim*100,1) : 0;
    $util_color = $util >= 90 ? '#dc3545' : ($util >= 60 ? '#fd7e14' : '#28a745');
  ?>
    <tr>
      <td><strong><?= htmlspecialchars($c['name']) ?></strong><br><span style="font-size:11px;color:#999;"><?= htmlspecialchars($c['contact_number']??'') ?></span></td>
      <td style="font-weight:700;color:<?= $bal>0?'#dc3545':'#28a745' ?>;">₱<?= number_format($bal,2) ?></td>
      <td>₱<?= $lim > 0 ? number_format($lim,2) : '—' ?></td>
      <td>
        <?php if($lim > 0): ?>
        <div style="display:flex;align-items:center;gap:6px;">
          <div style="width:80px;height:8px;background:#e0e0e0;border-radius:4px;">
            <div style="width:<?= min($util,100) ?>%;height:100%;background:<?= $util_color ?>;border-radius:4px;"></div>
          </div>
          <span style="color:<?= $util_color ?>;font-weight:600;"><?= $util ?>%</span>
        </div>
        <?php else: echo '—'; endif; ?>
      </td>
      <td><?= htmlspecialchars($c['payment_terms']??'—') ?></td>
      <td><?= htmlspecialchars($c['status']??'active') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr style="background:#f0f4ff;font-weight:700;">
      <td>TOTAL</td>
      <td style="color:#dc3545;">₱<?= number_format($total_credit_balance,2) ?></td>
      <td colspan="4"></td>
    </tr>
  </tfoot>
</table>
</div>
<?php endif; ?>
