<?php
/**
 * Audit Trail Report — consolidated logs across all shifts
 */

$audit_logs = [];
try {
    $q = $pdo->prepare("SELECT al.*, CONCAT(u.first_name,' ',u.last_name) AS staff_name, u.role, u.username
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE u.station_id=? AND DATE(al.created_at) BETWEEN ? AND ?
        ORDER BY al.created_at DESC LIMIT 200");
    $q->execute([$station_id, $date_start, $date_end]);
    $audit_logs = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Group by entity type
$by_entity = [];
foreach ($audit_logs as $log) {
    $entity = $log['entity_type'] ?? $log['log_type'] ?? 'General';
    $by_entity[$entity] = ($by_entity[$entity] ?? 0) + 1;
}
arsort($by_entity);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
  <div>
    <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-shield-alt"></i> Audit Trail Report</h2>
    <p style="margin:0;color:#666;font-size:13px;">Consolidated logs of all data changes and system events</p>
  </div>
  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:16px;margin-bottom:28px;">
  <div style="background:linear-gradient(135deg,#003366,#004d99);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Total Audit Events</div>
    <div style="font-size:26px;font-weight:700;"><?= count($audit_logs) ?></div>
    <div style="font-size:11px;opacity:.75;">This period</div>
  </div>
  <div style="background:linear-gradient(135deg,#6b21a8,#7c3aed);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Entity Types</div>
    <div style="font-size:26px;font-weight:700;"><?= count($by_entity) ?></div>
    <div style="font-size:11px;opacity:.75;">Different data types</div>
  </div>
  <div style="background:linear-gradient(135deg,#dc3545,#c82333);padding:18px;border-radius:10px;color:#fff;">
    <div style="font-size:12px;opacity:.85;">Unique Staff</div>
    <div style="font-size:26px;font-weight:700;"><?= count(array_unique(array_column($audit_logs,'user_id'))) ?></div>
    <div style="font-size:11px;opacity:.75;">Who made changes</div>
  </div>
</div>

<!-- By Entity Chart -->
<?php if(!empty($by_entity)): ?>
<div style="background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:18px;margin-bottom:28px;max-width:600px;">
  <h3 style="margin:0 0 12px;color:#003366;font-size:15px;"><i class="fas fa-chart-pie"></i> Events by Entity Type</h3>
  <?php foreach(array_slice($by_entity,0,10,true) as $ent => $cnt):
    $maxc = max($by_entity); $pct = round($cnt/$maxc*100); ?>
    <div style="margin-bottom:10px;">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px;">
        <span><?= htmlspecialchars($ent) ?></span><span style="font-weight:700;"><?= $cnt ?></span>
      </div>
      <div style="height:8px;background:#e0e0e0;border-radius:4px;">
        <div style="width:<?=$pct?>%;height:100%;background:#6b21a8;border-radius:4px;"></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Search + Table -->
<div style="margin-bottom:12px;">
  <input type="text" id="auditSearch" placeholder="Search by action, staff, entity..."
    oninput="filterAudit()"
    style="width:100%;max-width:400px;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
</div>
<div style="overflow-x:auto;">
<table class="report-table" id="auditTable">
  <thead>
    <tr><th>Timestamp</th><th>Staff</th><th>Role</th><th>Action</th><th>Entity</th><th>Entity ID</th><th>Details</th></tr>
  </thead>
  <tbody>
  <?php if(empty($audit_logs)): ?>
    <tr><td colspan="7" style="text-align:center;color:#999;padding:40px;"><i class="fas fa-inbox" style="display:block;font-size:30px;margin-bottom:8px;"></i>No audit logs for selected period.</td></tr>
  <?php else: foreach($audit_logs as $log):
    $action = $log['action_type'] ?? '—';
    $acolor = str_contains(strtolower($action),'delete') ? '#dc3545' : (str_contains(strtolower($action),'create') ? '#28a745' : (str_contains(strtolower($action),'update') ? '#007bff' : '#666'));
  ?>
    <tr class="audit-row">
      <td style="font-size:12px;white-space:nowrap;"><?= date('M j, Y g:i:s A', strtotime($log['created_at'])) ?></td>
      <td style="font-weight:600;"><?= htmlspecialchars($log['staff_name']??$log['username']??'System') ?></td>
      <td style="font-size:12px;"><?= htmlspecialchars($log['role']??'—') ?></td>
      <td><span style="color:<?=$acolor?>;font-weight:600;font-size:12px;"><?= htmlspecialchars($action) ?></span></td>
      <td style="font-size:12px;"><?= htmlspecialchars($log['entity_type']??$log['log_type']??'—') ?></td>
      <td style="font-size:12px;font-family:monospace;"><?= htmlspecialchars($log['entity_id']??'—') ?></td>
      <td style="font-size:12px;max-width:300px;word-break:break-word;"><?= htmlspecialchars(mb_strimwidth($log['action_details']??'',0,120,'…')) ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>
<script>
function filterAudit() {
    const q = document.getElementById('auditSearch').value.toLowerCase();
    document.querySelectorAll('#auditTable tbody tr.audit-row').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
