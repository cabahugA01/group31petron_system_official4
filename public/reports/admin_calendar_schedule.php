<?php
/**
 * Calendar & Schedule Report — Job Orders + Fuel Deliveries consolidated
 */

// Job orders in period
$jo_schedule = [];
try {
    $q = $pdo->prepare("SELECT jo.job_order_number, jo.customer_name, jo.vehicle_plate,
        jo.service_type, jo.status, jo.created_at, jo.completed_at, jo.total_cost,
        CONCAT(u.first_name,' ',u.last_name) AS mechanic_name
        FROM job_orders jo
        LEFT JOIN users u ON jo.assigned_mechanic_id = u.id
        WHERE jo.station_id=? AND DATE(jo.created_at) BETWEEN ? AND ?
        ORDER BY jo.created_at ASC");
    $q->execute([$station_id, $date_start, $date_end]);
    $jo_schedule = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fuel deliveries in period
$fd_schedule = [];
try {
    $q2 = $pdo->prepare("SELECT fd.*, u.first_name, u.last_name
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        WHERE fd.station_id=? AND fd.delivery_date BETWEEN ? AND ?
        ORDER BY fd.delivery_date ASC");
    $q2->execute([$station_id, $date_start, $date_end]);
    $fd_schedule = $q2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Group JOs by date
$jo_by_date = [];
foreach ($jo_schedule as $jo) {
    $d = date('Y-m-d', strtotime($jo['created_at']));
    $jo_by_date[$d][] = $jo;
}

// Group FDs by date
$fd_by_date = [];
foreach ($fd_schedule as $fd) {
    $fd_by_date[$fd['delivery_date']][] = $fd;
}

// All active dates
$all_dates = array_unique(array_merge(array_keys($jo_by_date), array_keys($fd_by_date)));
sort($all_dates);

$status_colors = ['Pending'=>'#fd7e14','In Progress'=>'#007bff','Completed'=>'#28a745','Cancelled'=>'#dc3545','Reviewed'=>'#17a2b8','Verified'=>'#28a745'];
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
  <div>
    <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-calendar-alt"></i> Calendar & Schedule Report</h2>
    <p style="margin:0;color:#666;font-size:13px;">Consolidated tasks, job orders, and deliveries timeline</p>
  </div>
  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:16px;margin-bottom:28px;">
  <div style="background:linear-gradient(135deg,#003366,#004d99);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Total Job Orders</div>
    <div style="font-size:26px;font-weight:700;"><?= count($jo_schedule) ?></div>
    <div style="font-size:11px;opacity:.75;">This period</div>
  </div>
  <div style="background:linear-gradient(135deg,#1a7c40,#228b22);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Completed JOs</div>
    <div style="font-size:26px;font-weight:700;"><?= count(array_filter($jo_schedule, fn($j)=>$j['status']==='Completed')) ?></div>
  </div>
  <div style="background:linear-gradient(135deg,#fd7e14,#e06c00);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Fuel Deliveries</div>
    <div style="font-size:26px;font-weight:700;"><?= count($fd_schedule) ?></div>
    <div style="font-size:11px;opacity:.75;">Received this period</div>
  </div>
  <div style="background:linear-gradient(135deg,#6b21a8,#7c3aed);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Active Days</div>
    <div style="font-size:26px;font-weight:700;"><?= count($all_dates) ?></div>
    <div style="font-size:11px;opacity:.75;">Days with activity</div>
  </div>
</div>

<!-- Timeline view -->
<?php if(empty($all_dates)): ?>
<div style="text-align:center;padding:60px;color:#999;">
  <i class="fas fa-calendar-times" style="font-size:40px;display:block;margin-bottom:12px;"></i>No scheduled items for selected period.
</div>
<?php else: foreach($all_dates as $date):
  $day_jo = $jo_by_date[$date] ?? [];
  $day_fd = $fd_by_date[$date] ?? [];
  $total_items = count($day_jo) + count($day_fd);
?>
<div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;margin-bottom:16px;overflow:hidden;">
  <!-- Date header -->
  <div style="background:#003366;color:#fff;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;">
    <div style="font-weight:700;font-size:15px;">
      <i class="fas fa-calendar-day"></i> <?= date('l, F j, Y', strtotime($date)) ?>
    </div>
    <div style="font-size:13px;opacity:.8;"><?= $total_items ?> items</div>
  </div>

  <div style="padding:16px 20px;">
    <?php if(!empty($day_fd)): ?>
    <div style="margin-bottom:12px;">
      <div style="font-size:13px;font-weight:700;color:#c55a00;margin-bottom:8px;"><i class="fas fa-truck"></i> Fuel Deliveries</div>
      <?php foreach($day_fd as $fd): ?>
      <div style="background:#fff8f0;border-left:4px solid #fd7e14;padding:10px 14px;border-radius:6px;margin-bottom:6px;display:flex;flex-wrap:wrap;gap:12px;font-size:13px;">
        <div><strong><?= htmlspecialchars($fd['fuel_type']) ?></strong></div>
        <div><?= number_format($fd['delivery_liters'],2) ?> L</div>
        <div style="color:#666;">Supplier: <?= htmlspecialchars($fd['supplier']??'—') ?></div>
        <div style="color:#666;">Invoice: <?= htmlspecialchars($fd['invoice_no']??'—') ?></div>
        <div style="margin-left:auto;"><span style="color:#28a745;font-weight:700;"><?= htmlspecialchars($fd['status']??'—') ?></span></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($day_jo)): ?>
    <div>
      <div style="font-size:13px;font-weight:700;color:#003366;margin-bottom:8px;"><i class="fas fa-wrench"></i> Job Orders</div>
      <?php foreach($day_jo as $jo):
        $sc = $status_colors[$jo['status']] ?? '#666';
      ?>
      <div style="background:#f8faff;border-left:4px solid <?=$sc?>;padding:10px 14px;border-radius:6px;margin-bottom:6px;display:flex;flex-wrap:wrap;gap:12px;font-size:13px;">
        <div style="font-family:monospace;font-size:11px;"><?= htmlspecialchars($jo['job_order_number']??'—') ?></div>
        <div><strong><?= htmlspecialchars($jo['customer_name']??'Walk-in') ?></strong></div>
        <div style="color:#666;"><?= htmlspecialchars($jo['vehicle_plate']??'—') ?></div>
        <div style="color:#666;flex:1;"><?= htmlspecialchars(mb_strimwidth($jo['service_type']??'—',0,50,'…')) ?></div>
        <div><?= htmlspecialchars($jo['mechanic_name']??'—') ?></div>
        <div>₱<?= number_format($jo['total_cost'],2) ?></div>
        <div><span style="color:<?=$sc?>;font-weight:700;"><?= htmlspecialchars($jo['status']) ?></span></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; endif; ?>
