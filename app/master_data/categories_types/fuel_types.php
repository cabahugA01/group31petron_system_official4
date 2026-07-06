<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_types';
require_once __DIR__ . '/../../../backend/lib.php';
require_once __DIR__ . '/../../../public/db_connect.php';
require_login();
$me = current_user();  $role = role_key($me['role'] ?? 'staff');
if (!in_array($role, ['staff','manager','admin','superadmin'])) { header("Location: dashboard.php"); exit; }  // Fetch fuel types and prices from database — not hardcoded
$fuels = [];
try {  // Try fuel_inventory joined with fuel_types for price  $stmt = $pdo->prepare("  SELECT ft.name, COALESCE(fi.price_per_liter, 0) AS price_per_liter,  COALESCE(fi.current_stock, fi.current_level, 0) AS current_stock  FROM fuel_types ft  LEFT JOIN fuel_inventory fi  ON LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(ft.name))  AND fi.station_id = ?  ORDER BY ft.name  ");  $stmt->execute([user_station_id()]);  $fuels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {  error_log("fuel_types page error: " . $e->getMessage());
}  // Fallback: try inventory_products
if (empty($fuels)) {  try {  $stmt = $pdo->prepare("  SELECT ip.product_name AS name,  COALESCE(ip.unit_cost, 0) AS price_per_liter,  COALESCE(si.stock_level, 0) AS current_stock  FROM inventory_products ip  LEFT JOIN station_inventory si  ON si.product_id = ip.id AND si.station_id = ?  WHERE LOWER(ip.category) = 'fuel'  ORDER BY ip.product_name  ");  $stmt->execute([user_station_id()]);  $fuels = $stmt->fetchAll(PDO::FETCH_ASSOC);  } catch (Exception $e) {  error_log("fuel_types fallback error: " . $e->getMessage());  }
}  include __DIR__ . '/../../../partials/header.php';
?>
<div class="page-head">  <div>  <h1 class="h1">Fuel Types</h1>  <div class="sub">Current fuel types and prices at this station.</div>  </div>
</div>  <section class="card">  <div class="card-head">  <div class="card-title"><i class="fas fa-gas-pump"></i> Available Fuels</div>  <div class="muted">Prices are read-only for staff</div>  </div>  <div style="padding:16px; overflow:auto;">  <?php if (empty($fuels)): ?>  <div style="text-align:center;padding:32px;color:#888;">  <i class="fas fa-gas-pump" style="font-size:2rem;opacity:.4;display:block;margin-bottom:10px;"></i>  No fuel types configured for this station.  </div>  <?php else: ?>  <table class="table" style="width:100%; border-collapse:collapse;">  <thead><tr>  <th style="text-align:left; padding:8px; border-bottom:1px solid #eee;">Fuel Type</th>  <th style="text-align:right; padding:8px; border-bottom:1px solid #eee;">Price / Liter</th>  <th style="text-align:right; padding:8px; border-bottom:1px solid #eee;">Current Stock</th>  </tr></thead>  <tbody>  <?php foreach ($fuels as $f): ?>  <tr>  <td style="padding:8px; border-bottom:1px solid #f3f3f3;">  <i class="fas fa-gas-pump" style="color:#003d82;margin-right:6px;"></i>  <?php echo htmlspecialchars($f['name']); ?>  </td>  <td style="padding:8px; border-bottom:1px solid #f3f3f3; text-align:right;">  <?php echo $f['price_per_liter'] > 0 ? '&#8369;' . number_format($f['price_per_liter'], 2) : '<span style="color:#aaa;">—</span>'; ?>  </td>  <td style="padding:8px; border-bottom:1px solid #f3f3f3; text-align:right;">  <?php echo number_format($f['current_stock'], 0); ?> L  </td>  </tr>  <?php endforeach; ?>  </tbody>  </table>  <?php endif; ?>  </div>
</section>  <?php include __DIR__ . '/../../../partials/footer.php'; ?>
