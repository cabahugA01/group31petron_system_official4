<?php
$page_id = 'fuel_pricing_manager';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Allow manager, admin, and superadmin
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// Handle price update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fuel_type_id = (int)($_POST['fuel_type_id'] ?? 0);
    $price_per_liter = (float)($_POST['price_per_liter'] ?? 0);
    
    if ($fuel_type_id > 0 && $price_per_liter >= 0) {
        try {
            // For superadmin, allow selecting station
            $target_station_id = $station_id;
            if ($role === 'superadmin') {
                $target_station_id = (int)($_POST['station_id'] ?? $station_id);
            }
            
            // Check if record exists and fetch old price for audit
            $stmt = $pdo->prepare("SELECT id, price_per_liter FROM fuel_pricing WHERE station_id = ? AND fuel_type_id = ? AND is_active = 1");
            $stmt->execute([$target_station_id, $fuel_type_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get fuel type name for readable log
            $stmtName = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
            $stmtName->execute([$fuel_type_id]);
            $fuel_name = $stmtName->fetchColumn() ?: "ID:$fuel_type_id";
            
            if ($existing) {
                $old_price = $existing['price_per_liter'];
                // Update existing
                $stmt = $pdo->prepare("UPDATE fuel_pricing SET price_per_liter = ?, effective_date = NOW(), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$price_per_liter, $existing['id']]);
                $msg = "✅ Fuel price updated successfully!";
                
                log_activity($pdo, $me['id'], 'Update Fuel Pricing', "Updated $fuel_name price: P{$old_price}/L -> P{$price_per_liter}/L (Station: $target_station_id)");
            } else {
                // Insert new
                $stmt = $pdo->prepare(
                    "INSERT INTO fuel_pricing (station_id, fuel_type_id, price_per_liter, effective_date, created_by, is_active, created_at) 
                     VALUES (?, ?, ?, NOW(), ?, 1, NOW())"
                );
                $stmt->execute([$target_station_id, $fuel_type_id, $price_per_liter, $me['id']]);
                $msg = "✅ Fuel price set successfully!";
                
                log_activity($pdo, $me['id'], 'Set Fuel Pricing', "Set $fuel_name initial price: P{$price_per_liter}/L (Station: $target_station_id)");
            }
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    } else {
        $msg = "❌ Invalid data. Price must be 0 or greater.";
    }
}

// Fetch fuel types with their current prices and nozzle info
$fuel_types = [];
try {
    if ($role === 'superadmin') {
        // For superadmin, show fuel types filtered by selected station
        $stmt = $pdo->prepare(
            "SELECT 
                ft.id,
                ft.name,
                COALESCE(fp.price_per_liter, 0) as price_per_liter,
                COUNT(DISTINCT p.id) as pump_count
             FROM fuel_types ft
             LEFT JOIN fuel_pricing fp ON ft.id = fp.fuel_type_id AND fp.station_id = ? AND fp.is_active = 1
             LEFT JOIN fuel_pumps p ON ft.id = p.fuel_type_id AND p.station_id = ? AND p.status = 'Active'
             GROUP BY ft.id, ft.name
             ORDER BY ft.name"
        );
        $stmt->execute([$station_id, $station_id]);
        $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // For admin/manager, show only their station with pump details
        $stmt = $pdo->prepare(
            "SELECT 
                ft.id,
                ft.name,
                COALESCE(fp.price_per_liter, 0) as price_per_liter,
                COUNT(DISTINCT p.id) as pump_count,
                GROUP_CONCAT(DISTINCT p.pump_number SEPARATOR ', ') as pumps
             FROM fuel_types ft
             LEFT JOIN fuel_pricing fp ON ft.id = fp.fuel_type_id AND fp.station_id = ? AND fp.is_active = 1
             LEFT JOIN fuel_pumps p ON ft.id = p.fuel_type_id AND p.station_id = ? AND p.status = 'Active'
             GROUP BY ft.id, ft.name
             ORDER BY ft.name"
        );
        $stmt->execute([$station_id, $station_id]);
        $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $fuel_types = [];
}

// Get station info for superadmin
$stations = [];
if ($role === 'superadmin') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM stations ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  .pricing-layout { display: grid; grid-template-columns: 1fr; gap: 20px; }
  .pricing-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
  .pricing-header { font-size: 20px; font-weight: 700; margin-bottom: 20px; color: #0f172a; display: flex; align-items: center; gap: 12px; }
  .pricing-table { width: 100%; border-collapse: collapse; }
  .pricing-table th { background: #f8fafc; padding: 14px; text-align: left; font-size: 12px; font-weight: 700; color: #475569; border-bottom: 2px solid #e2e8f0; }
  .pricing-table td { padding: 14px; border-bottom: 1px solid #f1f5f9; }
  .pricing-table tbody tr:hover { background: #f8fafc; }
  .fuel-badge { display: inline-block; padding: 6px 12px; background: #dbeafe; color: #0c4a6e; border-radius: 6px; font-size: 12px; font-weight: 600; }
  .nozzle-info { font-size: 12px; color: #64748b; }
  .price-input { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; width: 120px; font-size: 14px; }
  .price-input:focus { outline: none; border-color: #0066cc; box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1); }
  .price-btn { padding: 8px 16px; background: #0066cc; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; transition: background 0.2s; }
  .price-btn:hover { background: #0052a3; }
  .info-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; color: #166534; font-size: 13px; }
  .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
  .alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  @media (max-width: 768px) { 
    .pricing-table { font-size: 12px; }
    .pricing-table td, .pricing-table th { padding: 10px; }
    .price-input { width: 80px; font-size: 12px; }
  }
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-gas-pump"></i> Fuel Pricing Management</h1>
    <div class="sub">Set prices per liter for each fuel type</div>
  </div>
  <div style="display: flex; gap: 10px; flex-wrap: wrap;">
    <?php if(empty($fuel_types)): ?>
      <a href="add_fuel_type.php" class="price-btn" style="background: #f97316;">
        <i class="fas fa-plus"></i> Configure Gasoline & Fuel Types
      </a>
    <?php endif; ?>
    <a href="add_fuel_pump.php" class="price-btn" style="background: #10b981;">
      <i class="fas fa-plus"></i> Add Pump
    </a>
  </div>
</div>

<?php if($msg): ?>
  <div class="alert <?php echo strpos($msg, '✅') !== false ? 'alert-success' : 'alert-error'; ?>">
    <?php echo htmlspecialchars($msg); ?>
  </div>
<?php endif; ?>

<div class="pricing-layout">
  <div class="pricing-card">
    <div class="pricing-header">
      <i class="fas fa-dollar-sign" style="color: #0066cc;"></i>
      Current Fuel Prices
    </div>
    
    <?php if($role === 'superadmin'): ?>
      <div class="info-box">
        <strong>ℹ Admin View:</strong> You can set prices for any station. Select station below to view/set prices.
      </div>
    <?php else: ?>
      <div class="info-box">
        <strong>ℹ Station:</strong> Setting prices for <strong><?php echo htmlspecialchars($station_name ?? 'Your Station'); ?></strong>
      </div>
    <?php endif; ?>

    <?php if(empty($fuel_types)): ?>
      <div class="info-box" style="background: #fff7ed; border-color: #fed7aa; color: #9a3412;">
        <strong>⚙ Configure Fuel:</strong> No fuel types configured yet. <a href="add_fuel_type.php" style="color: #c2410c; font-weight: 600;">Click here to add gasoline & fuel types</a>
      </div>
    <?php endif; ?>
    
     <div class="table-wrap">
      <table class="pricing-table">
        <thead>
          <tr>
            <th>Fuel Type</th>
            <th>Pumps</th>
            <th>Current Price (₱/L)</th>
            <th>New Price (₱/L)</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($fuel_types)): ?>
            <tr>
              <td colspan="5" style="text-align:center; padding:20px; color:#888;">No fuel types configured.</td>
            </tr>
          <?php else: ?>
            <?php foreach($fuel_types as $fuel): ?>
              <tr>
                <td>
                  <span class="fuel-badge"><?php echo htmlspecialchars($fuel['name']); ?></span>
                </td>
                <td>
                  <?php
                  if ($fuel['pump_count'] > 0) {
                    echo "Pump(s): " . htmlspecialchars($fuel['pump_count']);
                  } else {
                    echo "No pumps configured";
                  }
                  ?>
                </td>
                <td>
                  <strong style="font-size:16px; color:#0066cc;">
                    ₱<?php echo number_format($fuel['price_per_liter'], 2); ?>
                  </strong>
                </td>
                <td>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="fuel_type_id" value="<?php echo $fuel['id']; ?>">
                    <?php if($role === 'superadmin'): ?>
                      <input type="hidden" name="station_id" value="<?php echo $station_id; ?>">
                    <?php endif; ?>
                    <input type="number" name="price_per_liter" class="price-input" step="0.01" min="0" placeholder="0.00" required>
                </td>
                <td>
                    <button type="submit" class="price-btn">
                      <i class="fas fa-check"></i> Update
                    </button>
                    </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Superadmin Station Selector -->
  <?php if($role === 'superadmin' && !empty($stations)): ?>
  <div class="pricing-card">
    <div class="pricing-header">
      <i class="fas fa-building" style="color: #0066cc;"></i>
      Select Station
    </div>
    <div class="info-box" style="margin-bottom: 0;">
      View and set fuel prices for different stations.
    </div>
    <div style="margin-top: 16px;">
      <select id="stationSelector" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;">
        <option value="">Select a station...</option>
        <?php foreach($stations as $station): ?>
          <option value="<?php echo $station['id']; ?>" <?php echo $station['id'] == $station_id ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($station['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if($role === 'superadmin'): ?>
<script>
document.getElementById('stationSelector').addEventListener('change', function() {
    if (this.value) {
        window.location.href = '?station_id=' + this.value;
    }
});

// Check for station_id in URL
const urlParams = new URLSearchParams(window.location.search);
const stationId = urlParams.get('station_id');
if (stationId) {
    document.getElementById('stationSelector').value = stationId;
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
