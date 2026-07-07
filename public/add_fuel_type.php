<?php
$page_id = 'add_fuel_type';
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

// Handle fuel type addition
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fuel_name = trim($_POST['fuel_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (!empty($fuel_name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO fuel_types (name, description) VALUES (?, ?)");
            $stmt->execute([$fuel_name, $description]);
            $msg = "✅ Fuel type '{$fuel_name}' added successfully!";
            log_activity($pdo, $me['id'], 'Add Fuel Type', "Added fuel type: $fuel_name");
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    } else {
        $msg = "❌ Please enter a fuel type name.";
    }
}

// Fetch all fuel types
$fuel_types = [];
try {
    $stmt = $pdo->query("SELECT * FROM fuel_types ORDER BY id");
    $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fuel_types = [];
}

// Fetch stations for superadmin
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
  .fuel-layout { display: grid; grid-template-columns: 1fr; gap: 20px; }
  .fuel-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
  .fuel-header { font-size: 20px; font-weight: 700; margin-bottom: 20px; color: #0f172a; display: flex; align-items: center; gap: 12px; }
  .fuel-table { width: 100%; border-collapse: collapse; }
  .fuel-table th { background: #f8fafc; padding: 14px; text-align: left; font-size: 12px; font-weight: 700; color: #475569; border-bottom: 2px solid #e2e8f0; }
  .fuel-table td { padding: 14px; border-bottom: 1px solid #f1f5f9; }
  .fuel-table tbody tr:hover { background: #f8fafc; }
  .fuel-badge { display: inline-block; padding: 6px 12px; background: #dbeafe; color: #0c4a6e; border-radius: 6px; font-size: 12px; font-weight: 600; }
  .pump-count { font-size: 12px; color: #64748b; }
  .action-btn { padding: 8px 16px; background: #10b981; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; transition: background 0.2s; }
  .action-btn:hover { background: #059669; }
  .info-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; color: #166534; font-size: 13px; }
  .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
  .alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #1e293b; font-size: 14px; }
  .form-group input, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
  .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #0066cc; box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1); }
  @media (max-width: 768px) {
    .fuel-table { font-size: 12px; }
    .fuel-table td, .fuel-table th { padding: 10px; }
  }
</style>

<div class="page-head">
  <div>
    <h1 class="h1"><i class="fas fa-gas-pump"></i> Add Fuel Type</h1>
    <div class="sub">Configure gasoline and fuel types for your station</div>
  </div>
</div>

<?php if($msg): ?>
  <div class="alert <?php echo strpos($msg, '✅') !== false ? 'alert-success' : 'alert-error'; ?>">
    <?php echo htmlspecialchars($msg); ?>
  </div>
<?php endif; ?>

<div class="fuel-layout">
  <div class="fuel-card">
    <div class="fuel-header">
      <i class="fas fa-plus-circle" style="color: #10b981;"></i>
      Add New Fuel Type
    </div>

    <div class="info-box">
      <strong>ℹ Instructions:</strong> Add fuel types to your system. After adding, go to Fuel Pricing Management to set prices per liter.
    </div>

    <form method="post">
      <div class="form-group">
        <label for="fuel_name">Fuel Name *</label>
        <input type="text" id="fuel_name" name="fuel_name" placeholder="e.g., Gasoline, Diesel, LPG" required>
      </div>

      <div class="form-group">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3" placeholder="Optional description"></textarea>
      </div>

      <button type="submit" class="action-btn">
        <i class="fas fa-check"></i> Add Fuel Type
      </button>
    </form>
  </div>

  <div class="fuel-card">
    <div class="fuel-header">
      <i class="fas fa-list" style="color: #10b981;"></i>
      Existing Fuel Types
    </div>

    <?php if(empty($fuel_types)): ?>
      <div style="text-align: center; padding: 20px; color: #888;">
        No fuel types configured yet.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="fuel-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Fuel Type</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($fuel_types as $fuel): ?>
              <tr>
                <td><?php echo $fuel['id']; ?></td>
                <td>
                  <span class="fuel-badge"><?php echo htmlspecialchars($fuel['name']); ?></span>
                </td>
                <td><?php echo htmlspecialchars($fuel['description'] ?? '-'); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
