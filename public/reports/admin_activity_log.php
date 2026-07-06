<?php
/**
 * Activity Log Report — staff login/logout, encodes, edits, validations, exports
 */

$logs = [];
$total_count = 0;
try {  $q = $pdo->prepare("SELECT al.*, CONCAT(u.first_name,' ',u.last_name) AS staff_name, u.role  FROM activity_logs al  LEFT JOIN users u ON al.user_id = u.id  WHERE u.station_id=? AND DATE(al.created_at) BETWEEN ? AND ?  ORDER BY al.created_at DESC LIMIT 200");  $q->execute([$station_id, $date_start, $date_end]);  $logs = $q->fetchAll(PDO::FETCH_ASSOC);  $total_count = count($logs);
} catch (Exception $e) {}

// Action type counts
$action_counts = [];
foreach ($logs as $log) {  $action = $log['action'] ?? 'Unknown';  $action_counts[$action] = ($action_counts[$action] ?? 0) + 1;
}
arsort($action_counts);

// Staff activity counts
$staff_counts = [];
foreach ($logs as $log) {  $name = $log['staff_name'] ?? 'System';  $staff_counts[$name] = ($staff_counts[$name] ?? 0) + 1;
}
arsort($staff_counts);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">  <div>  <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-history"></i> Activity Log Report</h2>  <p style="margin:0;color:#666;font-size:13px;">Timeline of all staff actions — logins, encodes, edits, exports</p>  </div>  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:16px;margin-bottom:28px;">  <div style="background:linear-gradient(135deg,#003366,#004d99);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Total Activities</div>  <div style="font-size:26px;font-weight:700;"><?= $total_count ?></div>  <div style="font-size:11px;opacity:.75;">This period</div>  </div>  <div style="background:linear-gradient(135deg,#1a7c40,#228b22);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Unique Action Types</div>  <div style="font-size:26px;font-weight:700;"><?= count($action_counts) ?></div>  </div>  <div style="background:linear-gradient(135deg,#c55a00,#e06c00);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Active Staff</div>  <div style="font-size:26px;font-weight:700;"><?= count($staff_counts) ?></div>  <div style="font-size:11px;opacity:.75;">With recorded actions</div>  </div>
</div>

<!-- Top Actions & Top Staff side by side -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px;">  <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:18px;">  <h3 style="margin:0 0 12px;color:#003366;font-size:15px;"><i class="fas fa-chart-bar"></i> Top Action Types</h3>  <?php foreach(array_slice($action_counts,0,10,true) as $action => $cnt):  $maxc = max($action_counts);  $pct  = round($cnt/$maxc*100); ?>  <div style="margin-bottom:10px;">  <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px;">  <span><?= htmlspecialchars($action) ?></span><span style="font-weight:700;"><?= $cnt ?></span>  </div>  <div style="height:8px;background:#e0e0e0;border-radius:4px;">  <div style="width:<?=$pct?>%;height:100%;background:#003366;border-radius:4px;"></div>  </div>  </div>  <?php endforeach; ?>  </div>  <div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:18px;">  <h3 style="margin:0 0 12px;color:#003366;font-size:15px;"><i class="fas fa-users"></i> Most Active Staff</h3>  <?php foreach(array_slice($staff_counts,0,10,true) as $name => $cnt):  $maxc = max($staff_counts);  $pct  = round($cnt/$maxc*100); ?>  <div style="margin-bottom:10px;">  <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px;">  <span><?= htmlspecialchars($name) ?></span><span style="font-weight:700;"><?= $cnt ?></span>  </div>  <div style="height:8px;background:#e0e0e0;border-radius:4px;">  <div style="width:<?=$pct?>%;height:100%;background:#28a745;border-radius:4px;"></div>  </div>  </div>  <?php endforeach; ?>  </div>
</div>

<!-- Full Activity Timeline -->
<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-stream"></i> Full Activity Timeline</h3>
<!-- Search -->
<div style="margin-bottom:12px;">  <input type="text" id="actSearch" placeholder="Search by action, staff, or details..."  oninput="filterActLog()"  style="width:100%;max-width:400px;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
</div>
<div style="overflow-x:auto;">
<table class="report-table" id="actTable">  <thead>  <tr><th>Timestamp</th><th>Staff</th><th>Role</th><th>Action</th><th>Details</th></tr>  </thead>  <tbody>  <?php if(empty($logs)): ?>  <tr><td colspan="5" style="text-align:center;color:#999;padding:40px;"><i class="fas fa-inbox" style="display:block;font-size:30px;margin-bottom:8px;"></i>No activity logs for selected period.</td></tr>  <?php else: foreach($logs as $log):  $action = $log['action'] ?? '—';  $acolor = str_contains(strtolower($action),'login') ? '#007bff' : (str_contains(strtolower($action),'delete') ? '#dc3545' : (str_contains(strtolower($action),'export') ? '#6b21a8' : '#003366'));  ?>  <tr class="act-row">  <td style="font-size:12px;white-space:nowrap;"><?= date('M j, Y g:i:s A', strtotime($log['created_at'])) ?></td>  <td style="font-weight:600;"><?= htmlspecialchars($log['staff_name']??'System') ?></td>  <td style="font-size:12px;"><?= htmlspecialchars($log['role']??'—') ?></td>  <td><span style="color:<?=$acolor?>;font-weight:600;font-size:12px;"><?= htmlspecialchars($action) ?></span></td>  <td style="font-size:12px;max-width:350px;word-break:break-word;"><?= htmlspecialchars(mb_strimwidth($log['details']??'',0,150,'…')) ?></td>  </tr>  <?php endforeach; endif; ?>  </tbody>
</table>
</div>
<script>
function filterActLog() {  const q = document.getElementById('actSearch').value.toLowerCase();  document.querySelectorAll('#actTable tbody tr.act-row').forEach(row => {  row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';  });
}
</script>
