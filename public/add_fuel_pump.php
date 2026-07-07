<?php
$page_id = 'add_fuel_pump';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Allow manager, admin, and superadmin
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header("Location: fuel_pricing_manager.php");
    exit;
}

$msg = '';

// Handle pump addition
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pump_number = trim($_POST['pump_number'] ?? '');
    $fuel_type_id = (int)($_POST['fuel_type_id'] ?? 0);
    $capacity = (float)($_POST['capacity'] ?? 0);

    if (!empty($pump_number) && $fuel_type_id > 0) {
        try {
            $stmt = $pdo->prepare("INSERT INTO fuel_pumps (station_id, pump_number, fuel_type_id, capacity, status) VALUES (?, ?, ?, ?, 'Active')");
            $stmt->execute([$station_id, $pump_number, $fuel_type_id, $capacity]);
            $msg = "✅ Pump '{$pump_number}' added successfully!";
            log_activity($pdo, $me['id'], 'Add Fuel Pump', "Added pump: $pump_number for fuel type ID: $fuel_type_id");
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    } else {
        $msg = "❌ Please fill in all required fields.";
    }
}

// Fetch all fuel types
$fuel_types = [];
try {
    $stmt = $pdo->query("SELECT * FROM fuel_types ORDER BY name");
    $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fuel_types = [];
}

// Fetch pumps for the station
$pumps = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, ft.name as fuel_type_name FROM fuel_pumps p LEFT JOIN fuel_types ft ON p.fuel_type_id = ft.id WHERE p.station_id = ? ORDER BY p.id");
    $stmt->execute([$station_id]);
    $pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pumps = [];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  .pump-layout { display: grid; grid-template-columns: 1fr; gap: 20px; }
  .pump-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
  .pump-header { font-size: 20px; font-weight: 700; margin-bottom: 20px; color: #0f172a; display: flex; align-items: center; gap: 12px; }
  .pump-table { width: 100%; border-collapse: collapse; }
  .pump-table th { background: #f8fafc; padding: 14px; text-align: left; font-size: 12px; font-weight: 700; color: #475569; border-bottom: 2px solid #e2e8f0; }
  .pump-table td { padding: 14px; border-bottom: 1px solid #f1f5f9; }
  .pump-table tbody tr:hover { background: #f8fafc; }
  .pump-badge { display: inline-block; padding: 6px 12px; background: #dbeafe; color: #0c4a6e; border-radius: 6px; font-size: 12px; font-weight: 600; }
  .status-active { color: #16a34a; font-weight: 600; }
  .status-inactive { color: #dc2626; font-weight: 600; }
  .info-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; color: #166534; font-size: 13px; }
  .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
  .alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #1e293b; font-size: 14px; }
  .form-group select, .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
  .form-group select:focus, .form-group input:focus { outline: none; border-color: #0066cc; box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1); }
  @media (max-width: 768px) {
    .pump-table { font-size: 12px; }
    .pump-table td, .pump-table th { padding: 10px; }
  }
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-gas-pump"></i> Add Fuel Pump</h1>
    <div class="sub">Configure pumps for your station</div>
  </div>
</div>

<?php if($msg): ?>
  <div class="alert <?php echo strpos($msg, '✅') !== false ? 'alert-success' : 'alert-error'; ?>">
    <?php echo htmlspecialchars($msg); ?>
  </div>
<?php endif; ?>

<div class="pump-layout">
  <div class="pump-card">
    <div class="pump-header">
      <i class="fas fa-plus-circle" style="color: #10b981;"></i>
      Add New Pump
    </div>

    <div class="info-box">
      <strong>ℹ Instructions:</strong> Add pumps for each fuel type. After adding pumps, you can set pricing in Fuel Pricing Management.
    </div>

    <form method="post">
      <div class="form-group">
        <label for="fuel_type_id">Fuel Type *</label>
        <select id="fuel_type_id" name="fuel_type_id" required>
          <option value="">Select fuel type...</option>
          <?php foreach($fuel_types as $fuel): ?>
            <option value="<?php echo $fuel['id']; ?>">
              <?php echo htmlspecialchars($fuel['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="pump_number">Pump Number *</label>
        <input type="text" id="pump_number" name="pump_number" placeholder="e.g., Pump 1, Pump 2" required>
      </div>

      <div class="form-group">
        <label for="capacity">Capacity (Liters)</label>
        <input type="number" id="capacity" name="capacity" step="0.01" min="0" placeholder="0.00">
      </div>

      <button type="submit" class="action-btn">
        <i class="fas fa-check"></i> Add Pump
      </button>
    </form>
  </div>

  <div class="pump-card">
    <div class="pump-header">
      <i class="fas fa-list" style="color: #10b981;"></i>
      Existing Pumps
    </div>

    <?php if(empty($pumps)): ?>
      <div style="text-align: center; padding: 20px; color: #888;">
        No pumps configured yet.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="pump-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Pump Number</th>
              <th>Fuel Type</th>
              <th>Capacity</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($pumps as $pump): ?>
              <tr>
                <td><?php echo $pump['id']; ?></td>
                <td><?php echo htmlspecialchars($pump['pump_number']); ?></td>
                <td><?php echo htmlspecialchars($pump['fuel_type_name'] ?? '-'); ?></td>
                <td><?php echo number_format($pump['capacity'], 2); ?> L</td>
                <td class="status-<?php echo strtolower($pump['status']); ?>"><?php echo ucfirst($pump['status']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
