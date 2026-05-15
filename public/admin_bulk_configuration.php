<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$role = $user['role'];
$msg = '';

// Only admin/manager/superadmin can access
if (!in_array($role, ['admin', 'manager', 'superadmin'])) {
    header('Location: index.php');
    exit;
}

// Handle bulk operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'bulk_assign_fuels') {
        $station_ids = $_POST['station_ids'] ?? [];
        $fuel_type_id = (int)$_POST['fuel_type_id'];

        if (!empty($station_ids) && $fuel_type_id > 0) {
            try {
                $pdo->beginTransaction();

                // Get fuel type name for logging
                $stmt = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
                $stmt->execute([$fuel_type_id]);
                $fuelName = $stmt->fetch()['name'];

                $updated = 0;
                foreach ($station_ids as $station_id) {
                    $stmt = $pdo->prepare("UPDATE fuel_pumps SET fuel_type_id = ? WHERE station_id = ? AND status = 'Active'");
                    $stmt->execute([$fuel_type_id, $station_id]);
                    $updated += $stmt->rowCount();
                }

                $pdo->commit();
                $msg = "✅ Updated $updated pumps to $fuelName across " . count($station_ids) . " stations!";
                log_activity($pdo, $user['id'], 'Bulk Fuel Assignment', "Assigned $fuelName to pumps at " . count($station_ids) . " stations", 'bulk_configuration');
            } catch (PDOException $e) {
                $pdo->rollBack();
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Error: Please select stations and a fuel type.";
        }
    } elseif ($action === 'bulk_activate_pumps') {
        $station_ids = $_POST['station_ids'] ?? [];
        $status = $_POST['status'] ?? 'Active';

        if (!empty($station_ids)) {
            try {
                $pdo->beginTransaction();

                $updated = 0;
                foreach ($station_ids as $station_id) {
                    $stmt = $pdo->prepare("UPDATE fuel_pumps SET status = ? WHERE station_id = ?");
                    $stmt->execute([$status, $station_id]);
                    $updated += $stmt->rowCount();
                }

                $pdo->commit();
                $msg = "✅ Updated status of $updated pumps across " . count($station_ids) . " stations!";
                log_activity($pdo, $user['id'], 'Bulk Pump Activation', "Set pump status to $status at " . count($station_ids) . " stations", 'bulk_configuration');
            } catch (PDOException $e) {
                $pdo->rollBack();
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Error: Please select stations.";
        }
    }
}

// Fetch stations and fuel types
$stations = [];
try {
    if ($role === 'superadmin') {
        $stmt = $pdo->query("SELECT id, name, location FROM stations WHERE status = 'active' ORDER BY name");
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Managers can only see their stations
        $stmt = $pdo->prepare("SELECT s.id, s.name, s.location FROM stations s
                                 JOIN user_stations us ON s.id = us.station_id
                                 WHERE us.user_id = ? AND s.status = 'active'
                                 ORDER BY s.name");
        $stmt->execute([$user['id']]);
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch fuel types
    $stmt = $pdo->query("SELECT id, name FROM fuel_types ORDER BY name");
    $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching data: " . $e->getMessage());
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
.station-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
    margin-top: 20px;
}
.station-card {
    padding: 15px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    transition: all 0.2s;
}
.station-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-color: var(--petron-blue);
}
.station-card input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}
.modal-card {
    width: min(600px, 95vw);
}
.bulk-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}
</style>

<div class="page-head">
  <h1>Bulk Station Configuration</h1>
  <div class="sub">Configure multiple stations at once with pump and fuel settings</div>
</div>

<?php if($msg): ?>
  <div class="card" style="padding: 15px; margin-bottom: 20px; background: #e6f4ea; color: green;">
    <?php echo $msg; ?>
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom: 20px;">
  <div class="card-head">
    <div class="card-title">Bulk Fuel Type Assignment</div>
  </div>

  <div class="pay-section" style="max-width: 600px;">
    <label class="pay-label">Select Stations</label>
    <div class="station-grid">
      <?php foreach($stations as $station): ?>
        <label class="station-card">
          <input type="checkbox" name="station_ids[]" value="<?php echo $station['id']; ?>" class="station-checkbox">
          <div>
            <strong><?php echo htmlspecialchars($station['name']); ?></strong><br>
            <small class="muted"><?php echo htmlspecialchars($station['location'] ?? 'N/A'); ?></small>
          </div>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="pay-section">
    <label class="pay-label">Assign Fuel Type</label>
    <select class="select" name="fuel_type_id" required>
      <option value="">Select Fuel Type</option>
      <?php foreach($fuel_types as $fuel): ?>
        <option value="<?php echo $fuel['id']; ?>">
          <?php echo htmlspecialchars($fuel['name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="bulk-actions">
    <button class="btn primary" onclick="openBulkFuelModal()">Assign Fuel Type to Selected Stations</button>
    <button class="btn primary" onclick="openBulkPumpModal()">Bulk Activate/Deactivate Pumps</button>
    <button class="btn ghost" onclick="selectAllStations()">Select All</button>
    <button class="btn ghost" onclick="clearAllStations()">Clear Selection</button>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div class="card-title">Selected Stations Summary</div>
  </div>

  <div class="table-wrap">
    <table class="table" id="selectedStationsTable">
      <thead>
        <tr>
          <th>Station ID</th>
          <th>Station Name</th>
          <th>Location</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <tr id="noSelectionRow">
          <td colspan="4" style="text-align: center; padding: 40px;">
            <div class="empty">
              <div class="empty-ico"><i class="fas fa-building"></i></div>
              <div class="muted">Select stations from the list above to view summary</div>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Bulk Fuel Assignment Modal -->
<div class="modal" id="bulkFuelModal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Confirm Bulk Fuel Assignment</div>
      <button class="icon-btn" onclick="closeModal('bulkFuelModal')">✕</button>
    </div>
    <div style="padding: 20px;">
      <p>Assign selected fuel type to all pumps at selected stations?</p>
      <p style="color: var(--petron-red);"><strong>Note:</strong> This will update all active pumps at the selected stations.</p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
        <input type="hidden" name="action" value="bulk_assign_fuels" />

        <!-- Store selected station IDs and fuel type -->
        <div id="bulkFormData"></div>

        <div class="modal-actions">
          <button type="button" class="btn ghost" onclick="closeModal('bulkFuelModal')">Cancel</button>
          <button type="submit" class="btn primary">Confirm Assignment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Bulk Pump Activation Modal -->
<div class="modal" id="bulkPumpModal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Bulk Pump Activation</div>
      <button class="icon-btn" onclick="closeModal('bulkPumpModal')">✕</button>
    </div>
    <div style="padding: 20px;">
      <p>Set pump status for selected stations?</p>

      <div class="pay-section">
        <label class="pay-label">Pump Status</label>
        <select class="select" name="status" id="bulkPumpStatus">
          <option value="Active">Active</option>
          <option value="Inactive">Inactive</option>
          <option value="Maintenance">Maintenance</option>
        </select>
      </div>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
        <input type="hidden" name="action" value="bulk_activate_pumps" />

        <div id="bulkPumpFormData"></div>

        <div class="modal-actions">
          <button type="button" class="btn ghost" onclick="closeModal('bulkPumpModal')">Cancel</button>
          <button type="submit" class="btn primary">Update Pump Status</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Modal Functions
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Selection Functions
function selectAllStations() {
    document.querySelectorAll('.station-checkbox').forEach(cb => cb.checked = true);
    updateSelectedStations();
}

function clearAllStations() {
    document.querySelectorAll('.station-checkbox').forEach(cb => cb.checked = false);
    updateSelectedStations();
}

// Update selected stations display
function updateSelectedStations() {
    const selectedIds = [];
    document.querySelectorAll('.station-checkbox:checked').forEach(cb => {
        selectedIds.push(parseInt(cb.value));
    });

    const tbody = document.querySelector('#selectedStationsTable tbody');

    if (selectedIds.length === 0) {
        tbody.innerHTML = `
            <tr id="noSelectionRow">
                <td colspan="4" style="text-align: center; padding: 40px;">
                    <div class="empty">
                        <div class="empty-ico"><i class="fas fa-building"></i></div>
                        <div class="muted">Select stations from the list above to view summary</div>
                    </div>
                </td>
            </tr>
        `;
        return;
    }

    // Get station data
    const stationData = {};
    <?php foreach($stations as $station): ?>
        stationData[<?php echo $station['id']; ?>] = {
            id: <?php echo $station['id']; ?>,
            name: '<?php echo addslashes($station['name']); ?>',
            location: '<?php echo addslashes($station['location'] ?? 'N/A'); ?>'
        };
    <?php endforeach; ?>

    let html = '';
    selectedIds.forEach(id => {
        const station = stationData[id];
        if (station) {
            html += `
                <tr>
                    <td>${station.id}</td>
                    <td><strong>${station.name}</strong></td>
                    <td>${station.location}</td>
                    <td><span class="badge" style="background: #28a745; color: white;">Pending Action</span></td>
                </tr>
            `;
        }
    });

    tbody.innerHTML = html;
}

// Open bulk fuel assignment modal
function openBulkFuelModal() {
    const selectedIds = [];
    document.querySelectorAll('.station-checkbox:checked').forEach(cb => {
        selectedIds.push(cb.value);
    });

    if (selectedIds.length === 0) {
        alert('Please select at least one station.');
        return;
    }

    const fuelTypeId = document.querySelector('select[name="fuel_type_id"]').value;
    if (!fuelTypeId) {
        alert('Please select a fuel type.');
        return;
    }

    // Build form data
    let formData = '';
    selectedIds.forEach(id => {
        formData += `<input type="hidden" name="station_ids[]" value="${id}" />`;
    });
    formData += `<input type="hidden" name="fuel_type_id" value="${fuelTypeId}" />`;

    document.getElementById('bulkFormData').innerHTML = formData;
    openModal('bulkFuelModal');
}

// Open bulk pump activation modal
function openBulkPumpModal() {
    const selectedIds = [];
    document.querySelectorAll('.station-checkbox:checked').forEach(cb => {
        selectedIds.push(cb.value);
    });

    if (selectedIds.length === 0) {
        alert('Please select at least one station.');
        return;
    }

    // Build form data
    let formData = '';
    selectedIds.forEach(id => {
        formData += `<input type="hidden" name="station_ids[]" value="${id}" />`;
    });

    document.getElementById('bulkPumpFormData').innerHTML = formData;
    openModal('bulkPumpModal');
}

// Update selected stations when checkboxes change
document.querySelectorAll('.station-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedStations);
});

// Close modals on outside click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    });
});
</script>


<?php require_once __DIR__ . '/../partials/footer.php'; ?>
