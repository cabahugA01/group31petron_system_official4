<?php
// Shared helper: render a shift panel
// $sd = shift data array, $sid = 'shift1'|'shift2'|'daily', $accent = hex color, $label, $hours
function render_shift_panel($sd, $sid, $accent, $label, $hours) {
    $p = $sd['payments'];
    $pay_total = array_sum($p);
    $jo_s = $sd['jo_status'];
    $jo_total = array_sum($jo_s);
?>
<!-- ── Summary Cards ── -->
<div class="sd-cards">
  <div class="sd-card" style="border-top:3px solid #ef4444">
    <div class="sd-card-icon" style="background:#fef2f2;color:#ef4444"><i class="fas fa-gas-pump"></i></div>
    <div><div class="sd-card-label">Fuel Sales</div>
    <div class="sd-card-val">₱<?= number_format($sd['fuel_revenue'],2) ?></div>
    <div class="sd-card-sub"><?= number_format($sd['fuel_liters'],1) ?> liters</div></div>
  </div>
  <div class="sd-card" style="border-top:3px solid #2563eb">
    <div class="sd-card-icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-boxes"></i></div>
    <div><div class="sd-card-label">Merchandise</div>
    <div class="sd-card-val">₱<?= number_format($sd['merch_revenue'],2) ?></div>
    <div class="sd-card-sub"><?= $sd['merch_items'] ?> items sold</div></div>
  </div>
  <div class="sd-card" style="border-top:3px solid #16a34a">
    <div class="sd-card-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-credit-card"></i></div>
    <div><div class="sd-card-label">Payments</div>
    <div class="sd-card-val">₱<?= number_format($pay_total,2) ?></div>
    <div class="sd-card-sub">All modes</div></div>
  </div>
  <div class="sd-card" style="border-top:3px solid #f59e0b">
    <div class="sd-card-icon" style="background:#fffbeb;color:#f59e0b"><i class="fas fa-wrench"></i></div>
    <div><div class="sd-card-label">Job Orders</div>
    <div class="sd-card-val"><?= $jo_total ?> total</div>
    <div class="sd-card-sub"><?= ($jo_s['Completed']??0) ?> completed</div></div>
  </div>
  <div class="sd-card" style="border-top:3px solid #8b5cf6">
    <div class="sd-card-icon" style="background:#f5f3ff;color:#8b5cf6"><i class="fas fa-user-plus"></i></div>
    <div><div class="sd-card-label">New Customers</div>
    <div class="sd-card-val"><?= $sd['new_customers'] ?></div>
    <div class="sd-card-sub"><?= $sd['deliveries'] ?> deliveries</div></div>
  </div>
</div>

<!-- ── Shift Tracker ── -->
<div class="sd-section-title"><i class="fas fa-clock"></i> Shift Tracker</div>
<div class="sd-box" style="margin-bottom:16px">
  <?php if (!empty($sd['clock_logs'])): ?>
  <table class="sd-table">
    <thead><tr><th>Staff</th><th>Clock In</th><th>Clock Out</th><th>Duration</th></tr></thead>
    <tbody>
    <?php foreach ($sd['clock_logs'] as $cl): 
        $h = floor(($cl['duration_min']??0)/60); $m = ($cl['duration_min']??0)%60;
    ?>
    <tr>
      <td><strong><?= htmlspecialchars($cl['full_name']??'—') ?></strong></td>
      <td><?= $cl['start_time'] ? date('h:i A', strtotime($cl['start_time'])) : '—' ?></td>
      <td><?= $cl['end_time'] ? date('h:i A', strtotime($cl['end_time'])) : '<span style="color:#16a34a;font-weight:700">Active</span>' ?></td>
      <td><?= $h>0?"$h h ":'' ?><?= $m ?> min</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="sd-empty"><i class="fas fa-clock"></i> No clock-in records for this shift.</div>
  <?php endif; ?>
</div>

<!-- ── Charts Row ── -->
<div class="sd-grid-2" style="margin-bottom:16px">
  <!-- Fuel Bar Chart -->
  <div class="sd-box">
    <div class="sd-chart-title"><i class="fas fa-gas-pump" style="color:#ef4444"></i> Fuel: Liters per Type</div>
    <?php if (!empty($sd['fuel_by_type'])): ?>
    <div style="position:relative;height:180px"><canvas id="<?= $sid ?>_fuelChart"></canvas></div>
    <?php else: ?>
    <div class="sd-empty">No fuel transactions this shift.</div>
    <?php endif; ?>
  </div>
  <!-- Merch Pie Chart -->
  <div class="sd-box">
    <div class="sd-chart-title"><i class="fas fa-boxes" style="color:#2563eb"></i> Merchandise: Sales by Category</div>
    <?php if (!empty($sd['merch_by_cat'])): ?>
    <div style="position:relative;height:180px"><canvas id="<?= $sid ?>_merchChart"></canvas></div>
    <?php else: ?>
    <div class="sd-empty">No merchandise sales this shift.</div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Job Orders Status ── -->
<div class="sd-section-title"><i class="fas fa-wrench"></i> Job Orders Status</div>
<div class="sd-box" style="margin-bottom:16px">
  <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px">
    <?php 
    $jo_conf = [
      'Pending Validation'=>['#FEF3C7','#92400E','fa-hourglass-half'],
      'Approved'=>['#DBEAFE','#1E40AF','fa-check-circle'],
      'In Progress'=>['#EDE9FE','#6D28D9','fa-spinner'],
      'Completed'=>['#D1FAE5','#065F46','fa-check-double'],
      'Cancelled'=>['#FEE2E2','#991B1B','fa-times-circle'],
    ];
    foreach ($jo_conf as $st=>[$bg,$col,$ico]): ?>
    <div style="background:<?= $bg ?>;color:<?= $col ?>;border-radius:10px;padding:10px 16px;text-align:center;min-width:100px">
      <i class="fas <?= $ico ?>" style="font-size:18px;display:block;margin-bottom:4px"></i>
      <div style="font-size:22px;font-weight:800"><?= $jo_s[$st]??0 ?></div>
      <div style="font-size:10px;font-weight:600"><?= $st ?></div>
    </div>
    <?php endforeach; ?>
    <div style="display:flex;align-items:center;margin-left:auto">
      <a href="staff_transactions_hub.php?section=merchandise&active_tab=encode_jo" style="background:#00264D;color:#fff;padding:8px 16px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:6px">
        <i class="fas fa-plus"></i> New Job Order
      </a>
    </div>
  </div>
  <?php if (!empty($sd['fuel_by_type'])): ?>
  <div style="position:relative;height:120px"><canvas id="<?= $sid ?>_joChart"></canvas></div>
  <?php endif; ?>
</div>

<!-- ── Payments Summary ── -->
<div class="sd-section-title"><i class="fas fa-credit-card"></i> Payments Summary</div>
<div class="sd-grid-2" style="margin-bottom:16px">
  <div class="sd-box">
    <?php 
    $modes = [
      'Cash'=>['cash','#22c55e','fa-money-bill-wave'],
      'Card'=>['card','#3b82f6','fa-credit-card'],
      'E-Wallet'=>['ewallet','#a855f7','fa-mobile-alt'],
      'E-Fuel Card'=>['efuel','#f59e0b','fa-gas-pump'],
      'Fleet Card'=>['fleet','#0891b2','fa-id-card'],
      'Credit/Utang'=>['credit','#ef4444','fa-file-invoice'],
    ];
    foreach ($modes as $lbl=>[$key,$col,$ico]): 
      $val = $p[$key]??0; $pct = $pay_total>0 ? round($val/$pay_total*100,1):0;
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f0">
      <span style="display:flex;align-items:center;gap:8px;font-size:12px;color:#344054;font-weight:600">
        <i class="fas <?= $ico ?>" style="color:<?= $col ?>;width:14px"></i><?= $lbl ?>
      </span>
      <span style="display:flex;align-items:center;gap:8px">
        <span style="font-size:13px;font-weight:700">₱<?= number_format($val,2) ?></span>
        <span style="background:<?= $col ?>22;color:<?= $col ?>;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px"><?= $pct ?>%</span>
      </span>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:8px;display:flex;justify-content:space-between;font-weight:800;color:#00264D;font-size:13px">
      <span>Total Collected</span><span>₱<?= number_format($pay_total,2) ?></span>
    </div>
  </div>
  <div class="sd-box">
    <div class="sd-chart-title"><i class="fas fa-chart-bar" style="color:#0891b2"></i> Payment Mode Comparison</div>
    <div style="position:relative;height:200px"><canvas id="<?= $sid ?>_payChart"></canvas></div>
  </div>
</div>

<!-- ── Activity Log ── -->
<div class="sd-section-title"><i class="fas fa-history"></i> Activity Log</div>
<div class="sd-box" style="margin-bottom:16px">
  <?php if (!empty($sd['activity_log'])): ?>
  <div style="position:relative;padding-left:20px">
    <div style="position:absolute;left:7px;top:8px;bottom:8px;width:2px;background:#e5e7eb"></div>
    <?php foreach ($sd['activity_log'] as $act): ?>
    <div style="position:relative;margin-bottom:10px">
      <div style="position:absolute;left:-13px;top:5px;width:8px;height:8px;border-radius:50%;background:#3b82f6;border:2px solid #fff"></div>
      <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px">
        <div style="display:flex;justify-content:space-between">
          <span style="font-size:11px;font-weight:700;color:#00264D"><?= htmlspecialchars($act['action_type']??'Activity') ?></span>
          <span style="font-size:10px;color:#667085"><?= date('h:i A', strtotime($act['created_at'])) ?></span>
        </div>
        <div style="font-size:11px;color:#667085;margin-top:2px"><?= htmlspecialchars(substr($act['action_details']??'',0,80)) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="sd-empty"><i class="fas fa-history"></i> No activities recorded for this shift.</div>
  <?php endif; ?>
  <div style="text-align:right;margin-top:8px">
    <a href="staff_activity_report.php" style="font-size:12px;color:#2563eb;font-weight:600">View Full Audit Trail &rarr;</a>
  </div>
</div>

<?php }
?>
