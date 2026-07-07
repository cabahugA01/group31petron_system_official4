<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_types';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
$me = current_user();

$role = role_key($me['role'] ?? 'staff');
if (!in_array($role, ['staff','manager','admin','superadmin'])) { header("Location: dashboard.php"); exit; }

include __DIR__ . '/../partials/header.php';
?>
<div class="page-head">
  <div>
    <h1 class="h1">Fuel Types</h1>
    <div class="sub">This page is part of the Fuel Types module.</div>
  </div>
</div>
<?php
$fuels = [];
try {
    $stmt = $pdo->query("SELECT name, 'Read-only' as price FROM fuel_types ORDER BY name");
    $fuels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fuels = [];
}
?>
<section class="card">
  <div class="card-head">
    <div class="card-title"><i class="fas fa-gas-pump"></i> Available Fuels</div>
    <div class="muted">Prices are read-only for staff</div>
  </div>
  <div style="padding:16px; overflow:auto;">
    <table class="table" style="width:100%; border-collapse:collapse;">
      <thead><tr>
        <th style="text-align:left; padding:8px; border-bottom:1px solid #eee;">Fuel Type</th>
        <th style="text-align:left; padding:8px; border-bottom:1px solid #eee;">Price</th>
      </tr></thead>
      <tbody>
      <?php foreach($fuels as $f): ?>
        <tr>
          <td style="padding:8px; border-bottom:1px solid #f3f3f3;"><?php echo htmlspecialchars($f['name']); ?></td>
          <td style="padding:8px; border-bottom:1px solid #f3f3f3;"><?php echo htmlspecialchars($f['price']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
