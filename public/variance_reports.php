<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_variance_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
$me = current_user();

$role = role_key($me['role'] ?? 'staff');
if (!in_array($role, ['manager','admin','superadmin'])) { header("Location: dashboard.php"); exit; }

include __DIR__ . '/../partials/header.php';
?>
<div class="page-head">
  <div>
    <h1 class="h1">Variance Reports</h1>
    <div class="sub">This page is part of the Variance Reports module.</div>
  </div>
</div>
<?php
$station_id = user_station_id();
$fuel_readings = read_json('fuel_readings.json', []);
$rows = [];
foreach($fuel_readings as $fr){
  if (($fr['station_id'] ?? null) == $station_id && (($fr['computed_liters'] ?? 0) < 0 || ($fr['variance_liters'] ?? 0) != 0)) {
    $rows[] = $fr;
  }
}
?>
<section class="card">
  <div class="card-head">
    <div class="card-title"><i class="fas fa-triangle-exclamation"></i> Fuel Variance Reports</div>
    <div class="muted">Flagged variances for your station</div>
  </div>
  <div style="padding:16px; overflow:auto;">
    <table class="table" style="width:100%; border-collapse: collapse;">
      <thead>
        <tr>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #eee;">Date</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #eee;">Fuel Type</th>
          <th style="text-align:right; padding:8px; border-bottom:1px solid #eee;">Computed Liters</th>
          <th style="text-align:right; padding:8px; border-bottom:1px solid #eee;">Variance</th>
          <th style="text-align:left; padding:8px; border-bottom:1px solid #eee;">Staff</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($rows)): ?>
          <tr><td colspan="5" style="padding:12px;" class="muted">No flagged variances found.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td style="padding:8px; border-bottom:1px solid #f3f3f3;"><?php echo htmlspecialchars($r['date'] ?? ''); ?></td>
            <td style="padding:8px; border-bottom:1px solid #f3f3f3;"><?php echo htmlspecialchars($r['fuel_type'] ?? ''); ?></td>
            <td style="padding:8px; border-bottom:1px solid #f3f3f3; text-align:right;"><?php echo htmlspecialchars((string)($r['computed_liters'] ?? '')); ?></td>
            <td style="padding:8px; border-bottom:1px solid #f3f3f3; text-align:right;"><?php echo htmlspecialchars((string)($r['variance_liters'] ?? '')); ?></td>
            <td style="padding:8px; border-bottom:1px solid #f3f3f3;"><?php echo htmlspecialchars($r['cashier'] ?? ($r['staff'] ?? '')); ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
