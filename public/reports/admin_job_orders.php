<?php
/**
 * Job Orders Report — status breakdown, income per service type
 */

// Overall stats
$jo_stats = ['Pending'=>0,'In Progress'=>0,'Completed'=>0,'Cancelled'=>0,'total'=>0,'revenue'=>0];
try {
    $q = $pdo->prepare("SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_cost),0) AS rev
        FROM job_orders WHERE station_id=? AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY status");
    $q->execute([$station_id, $date_start, $date_end]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mapped = $row['status'];
        if (in_array($mapped, ['Reviewed','Awaiting Parts'])) $mapped = 'In Progress';
        if (in_array($mapped, ['Verified','finalized']))       $mapped = 'Completed';
        if ($mapped === 'Rejected')                            $mapped = 'Cancelled';
        if (array_key_exists($mapped, $jo_stats)) {
            $jo_stats[$mapped] += (int)$row['cnt'];
            if ($mapped === 'Completed') $jo_stats['revenue'] += (float)$row['rev'];
        }
        $jo_stats['total'] += (int)$row['cnt'];
    }
} catch (Exception $e) {}

// Service type breakdown
$by_service = [];
try {
    $q2 = $pdo->prepare("SELECT COALESCE(NULLIF(service_type,''),'General Service') AS stype,
        COUNT(*) AS cnt, COALESCE(SUM(total_cost),0) AS revenue,
        COALESCE(AVG(total_cost),0) AS avg_cost
        FROM job_orders WHERE station_id=? AND status='Completed'
        AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY stype ORDER BY revenue DESC LIMIT 20");
    $q2->execute([$station_id, $date_start, $date_end]);
    $by_service = $q2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Payment method breakdown for completed JOs
$jo_payments = [];
try {
    $q3 = $pdo->prepare("SELECT COALESCE(NULLIF(payment_method,''),'Cash') AS method,
        COUNT(*) AS cnt, COALESCE(SUM(total_cost),0) AS revenue
        FROM job_orders WHERE station_id=? AND status='Completed'
        AND DATE(created_at) BETWEEN ? AND ? GROUP BY method ORDER BY revenue DESC");
    $q3->execute([$station_id, $date_start, $date_end]);
    $jo_payments = $q3->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Detailed list
$jo_list = [];
try {
    $q4 = $pdo->prepare("SELECT jo.*, CONCAT(u.first_name,' ',u.last_name) AS encoder_name,
        CONCAT(m.first_name,' ',m.last_name) AS mechanic_name
        FROM job_orders jo
        LEFT JOIN users u ON jo.created_by = u.id
        LEFT JOIN users m ON jo.assigned_mechanic_id = m.id
        WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ?
        ORDER BY jo.created_at DESC LIMIT 100");
    $q4->execute([$station_id, $date_start, $date_end]);
    $jo_list = $q4->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
  <div>
    <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-wrench"></i> Job Orders Report</h2>
    <p style="margin:0;color:#666;font-size:13px;">Status breakdown, service income, payment summary</p>
  </div>
  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<!-- KPI Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;">
  <div style="background:linear-gradient(135deg,#003366,#004d99);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Total Job Orders</div>
    <div style="font-size:26px;font-weight:700;"><?= $jo_stats['total'] ?></div>
    <div style="font-size:11px;opacity:.75;">All statuses</div>
  </div>
  <div style="background:linear-gradient(135deg,#fd7e14,#e06c00);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Pending</div>
    <div style="font-size:26px;font-weight:700;"><?= $jo_stats['Pending'] ?></div>
    <div style="font-size:11px;opacity:.75;">Awaiting action</div>
  </div>
  <div style="background:linear-gradient(135deg,#007bff,#0056b3);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">In Progress</div>
    <div style="font-size:26px;font-weight:700;"><?= $jo_stats['In Progress'] ?></div>
    <div style="font-size:11px;opacity:.75;">Active jobs</div>
  </div>
  <div style="background:linear-gradient(135deg,#1a7c40,#228b22);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Completed</div>
    <div style="font-size:26px;font-weight:700;"><?= $jo_stats['Completed'] ?></div>
    <div style="font-size:11px;opacity:.75;">Done this period</div>
  </div>
  <div style="background:linear-gradient(135deg,#dc3545,#c82333);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Cancelled</div>
    <div style="font-size:26px;font-weight:700;"><?= $jo_stats['Cancelled'] ?></div>
    <div style="font-size:11px;opacity:.75;">&nbsp;</div>
  </div>
  <div style="background:linear-gradient(135deg,#6b21a8,#7c3aed);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Service Income</div>
    <div style="font-size:22px;font-weight:700;">₱<?= number_format($jo_stats['revenue'],2) ?></div>
    <div style="font-size:11px;opacity:.75;">From completed JOs</div>
  </div>
</div>

<!-- Service Type Table -->
<?php if(!empty($by_service)): ?>
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-tools"></i> Income by Service Type</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">
  <thead><tr><th>Service Type</th><th>Jobs Completed</th><th>Total Revenue</th><th>Avg Cost/Job</th></tr></thead>
  <tbody>
  <?php $max_rev = max(array_column($by_service,'revenue')); foreach($by_service as $s): ?>
    <tr>
      <td><strong><?= htmlspecialchars($s['stype']) ?></strong></td>
      <td><?= $s['cnt'] ?></td>
      <td>
        <div style="display:flex;align-items:center;gap:8px;">
          <div style="flex:1;height:8px;background:#e0e0e0;border-radius:4px;max-width:120px;">
            <div style="width:<?= $max_rev>0?round($s['revenue']/$max_rev*100):0 ?>%;height:100%;background:#003366;border-radius:4px;"></div>
          </div>
          ₱<?= number_format($s['revenue'],2) ?>
        </div>
      </td>
      <td>₱<?= number_format($s['avg_cost'],2) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- Payment Methods -->
<?php if(!empty($jo_payments)): ?>
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-credit-card"></i> Payment Method Breakdown (Completed JOs)</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">
  <thead><tr><th>Payment Method</th><th>Count</th><th>Total Amount</th></tr></thead>
  <tbody>
  <?php foreach($jo_payments as $p): ?>
    <tr>
      <td><?= htmlspecialchars($p['method']) ?></td>
      <td><?= $p['cnt'] ?></td>
      <td>₱<?= number_format($p['revenue'],2) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- Full List -->
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-list"></i> All Job Orders</h3>
<div style="overflow-x:auto;">
<table class="report-table" id="joFullTable">
  <thead>
    <tr>
      <th>JO #</th><th>Date</th><th>Customer</th><th>Vehicle</th>
      <th>Service</th><th>Mechanic</th><th>Cost</th><th>Status</th><th>Encoder</th>
    </tr>
  </thead>
  <tbody>
  <?php if(empty($jo_list)): ?>
    <tr><td colspan="9" style="text-align:center;color:#999;padding:40px;"><i class="fas fa-inbox" style="display:block;font-size:30px;margin-bottom:8px;"></i>No job orders in selected period.</td></tr>
  <?php else: foreach($jo_list as $jo):
    $status_colors = ['Pending'=>'#fd7e14','In Progress'=>'#007bff','Completed'=>'#28a745','Cancelled'=>'#dc3545','Reviewed'=>'#17a2b8','Verified'=>'#28a745','finalized'=>'#28a745','Awaiting Parts'=>'#6c757d','Rejected'=>'#dc3545'];
    $sc = $status_colors[$jo['status']] ?? '#666';
  ?>
    <tr>
      <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($jo['job_order_number']??'JO-'.$jo['id']) ?></td>
      <td><?= date('M j', strtotime($jo['created_at'])) ?></td>
      <td><?= htmlspecialchars($jo['customer_name']??'Walk-in') ?></td>
      <td><?= htmlspecialchars($jo['vehicle_plate']??'—') ?> <span style="font-size:11px;color:#999;"><?= htmlspecialchars($jo['vehicle_type']??'') ?></span></td>
      <td style="font-size:12px;"><?= htmlspecialchars(mb_strimwidth($jo['service_type']??$jo['service_description']??'—',0,40,'…')) ?></td>
      <td style="font-size:12px;"><?= htmlspecialchars($jo['mechanic_name']??'—') ?></td>
      <td>₱<?= number_format((float)$jo['total_cost'],2) ?></td>
      <td><span style="color:<?=$sc?>;font-weight:700;font-size:12px;"><?= htmlspecialchars($jo['status']) ?></span></td>
      <td style="font-size:12px;"><?= htmlspecialchars($jo['encoder_name']??'—') ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>
