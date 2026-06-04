<?php
$page_id = 'admin_pump_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$msg = '';

// Admin/Superadmin/Manager access
$role = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? ''));
$isAdmin = in_array($role, ['admin', 'superadmin', 'manager']);
$isSuper = ($role === 'superadmin');
$isManager = ($role === 'manager');

if (!$isAdmin) {
    header("Location: dashboard.php");
    exit;
}

// Get all stations (superadmin sees all, admin sees only their station)
$selected_station = $_GET['station'] ?? '';
if ($selected_station === '' && isset($isSuper) && $isSuper) {
     $selected_station = 1;
}
$stations = [];
$pumps = [];
$fuel_types = [];

try {
     // Fetch stations
     if ($isSuper) {
         $stmt = $pdo->query("SELECT id, name FROM stations ORDER BY name");
     } else {
         $station_id = user_station_id();
         $stmt = $pdo->prepare("SELECT id, name FROM stations WHERE id = ?");
         $stmt->execute([$station_id]);
         $selected_station = $station_id;
     }
     $stations = $stmt->fetchAll();
    
    // Fetch fuel types for dropdowns
    $stmt = $pdo->query("SELECT id, name FROM fuel_types ORDER BY name");
    $fuel_types = $stmt->fetchAll();
    
    // Fetch pumps with fuel type info for selected station
    if ($selected_station) {
        $stmt = $pdo->prepare("
            SELECT fp.id, fp.pump_number, fp.status, fp.calibration_value, fp.fuel_type_id, ft.name as fuel_type_name, s.name as station_name
            FROM fuel_pumps fp
            LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
            LEFT JOIN stations s ON fp.station_id = s.id
            WHERE fp.station_id = ?
            ORDER BY fp.pump_number
        ");
        $stmt->execute([$selected_station]);
        $pumps = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $msg = "Error loading data: " . $e->getMessage();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $station_id = $_POST['station_id'] ?? $selected_station;
    
    // ADD PUMP
    if ($action === 'add_pump') {
        $pump_number = trim($_POST['pump_number'] ?? '');
        $fuel_type_id = $_POST['fuel_type_id'] ?? '';
        $status = $_POST['status'] ?? 'active';
        
        if (!$pump_number) {
            $msg = "❌ Error: Pump number is required.";
        } elseif (!$fuel_type_id) {
            $msg = "❌ Error: Fuel type is required.";
        } else {
            try {
                // Check if pump number already exists for this station
                $stmt = $pdo->prepare("SELECT id FROM fuel_pumps WHERE station_id = ? AND pump_number = ?");
                $stmt->execute([$station_id, $pump_number]);
                
                if ($stmt->rowCount() > 0) {
                    $msg = "❌ Error: Pump number already exists for this station.";
                } else {
                    // Insert new pump
                    $stmt = $pdo->prepare("INSERT INTO fuel_pumps (station_id, pump_number, fuel_type_id, status) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$station_id, $pump_number, $fuel_type_id, $status]);
                    
                    // Get fuel type name for logging
                    $stmt = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
                    $stmt->execute([$fuel_type_id]);
                    $fuel_type = $stmt->fetch();
                    
                    log_activity($pdo, $me['id'], 'Add Pump', "Created Pump $pump_number with fuel type " . ($fuel_type['name'] ?? 'Unknown'), 'fuel_management');
                    $msg = "✅ Pump $pump_number created successfully.";
                }
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
    
    // EDIT PUMP
    elseif ($action === 'edit_pump') {
        $pump_id = $_POST['pump_id'] ?? '';
        $fuel_type_id = $_POST['fuel_type_id'] ?? '';
        $status = $_POST['status'] ?? 'active';
        $calibration_value = trim($_POST['calibration_value'] ?? '');
        
        if (!$pump_id) {
            $msg = "❌ Error: Pump ID is required.";
        } elseif (!$fuel_type_id) {
            $msg = "❌ Error: Fuel type is required.";
        } else {
            try {
                // Check if pump exists
                $stmt = $pdo->prepare("SELECT pump_number FROM fuel_pumps WHERE id = ? AND station_id = ?");
                $stmt->execute([$pump_id, $station_id]);
                $pump = $stmt->fetch();
                
                if (!$pump) {
                    $msg = "❌ Error: Pump not found.";
                } else {
                    // Validate calibration_value if provided
                    $calibration_to_store = null;
                    if ($calibration_value !== '') {
                        if (!is_numeric($calibration_value)) {
                            $msg = "❌ Error: Calibration value must be a number.";
                        } else {
                            $calibration_to_store = $calibration_value;
                        }
                    }
                    
                    if (!isset($msg) || strpos($msg, '❌') === false) {
                        // Update pump
                        $stmt = $pdo->prepare("UPDATE fuel_pumps SET fuel_type_id = ?, status = ?, calibration_value = ? WHERE id = ?");
                        $stmt->execute([$fuel_type_id, $status, $calibration_to_store, $pump_id]);
                        
                        // Get fuel type name for logging
                        $stmt = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ?");
                        $stmt->execute([$fuel_type_id]);
                        $fuel_type = $stmt->fetch();
                        
                        $log_msg = "Updated Pump " . $pump['pump_number'] . " - Fuel Type: " . ($fuel_type['name'] ?? 'Unknown') . ", Status: $status";
                        if ($calibration_to_store !== null) {
                            $log_msg .= ", Calibration: $calibration_to_store";
                        }
                        log_activity($pdo, $me['id'], 'Edit Pump', $log_msg, 'fuel_management');
                        $msg = "✅ Pump " . $pump['pump_number'] . " updated successfully.";
                    }
                }
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
    
    // DELETE PUMP (Superadmin only)
    elseif ($action === 'delete_pump') {
        if (!$isSuper) {
            $msg = "❌ Error: Only superadmin can delete pumps.";
        } else {
            $pump_id = $_POST['pump_id'] ?? '';
            
            if (!$pump_id) {
                $msg = "❌ Error: Pump ID is required.";
            } else {
                try {
                    // Get pump details
                    $stmt = $pdo->prepare("SELECT pump_number FROM fuel_pumps WHERE id = ?");
                    $stmt->execute([$pump_id]);
                    $pump = $stmt->fetch();
                    
                    if (!$pump) {
                        $msg = "❌ Error: Pump not found.";
                    } else {
                        // Delete pump
                        $stmt = $pdo->prepare("DELETE FROM fuel_pumps WHERE id = ?");
                        $stmt->execute([$pump_id]);
                        
                        log_activity($pdo, $me['id'], 'Delete Pump', "Deleted Pump " . $pump['pump_number'], 'fuel_management');
                        $msg = "✅ Pump " . $pump['pump_number'] . " deleted successfully.";
                    }
                } catch (PDOException $e) {
                    $msg = "❌ Error: " . $e->getMessage();
                }
            }
        }
    }
    
    // Refresh pumps list after action
    if ($selected_station) {
        try {
            $stmt = $pdo->prepare("
                SELECT fp.id, fp.pump_number, fp.status, fp.calibration_value, fp.fuel_type_id, ft.name as fuel_type_name, s.name as station_name
                FROM fuel_pumps fp
                LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
                LEFT JOIN stations s ON fp.station_id = s.id
                WHERE fp.station_id = ?
                ORDER BY fp.pump_number
            ");
            $stmt->execute([$selected_station]);
            $pumps = $stmt->fetchAll();
        } catch (Exception $e) {}
    }
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
.pump-card { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.pump-table { width: 100%; border-collapse: collapse; }
.pump-table th { background: #667eea; color: white; padding: 12px; text-align: left; }
.pump-table td { padding: 12px; border-bottom: 1px solid #ddd; }
.pump-table tr:hover { background: #f5f5f5; }
.btn { padding: 8px 12px; border-radius: 4px; border: none; cursor: pointer; font-size: 14px; }
.btn-primary { background: #667eea; color: white; }
.btn-primary:hover { background: #5568d3; }
.btn-danger { background: #dc3545; color: white; }
.btn-danger:hover { background: #c82333; }
.btn-success { background: #28a745; color: white; }
.btn-success:hover { background: #218838; }
.btn-warning { background: #ffc107; color: black; }
.btn-warning:hover { background: #e0a800; }
.btn-secondary { background: #6c757d; color: white; }
.btn-secondary:hover { background: #5a6268; }
.btn-sm { padding: 6px 10px; font-size: 12px; }
.status-active { color: #28a745; font-weight: bold; }
.status-inactive { color: #dc3545; font-weight: bold; }
.alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
.alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
.form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
.modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4); }
.modal.show { display: block; }
.modal-content { background-color: #fefefe; margin: auto; margin-top: 10%; padding: 20px; border: 1px solid #888; width: 90%; max-width: 600px; border-radius: 8px; }
.modal-header { border-bottom: 1px solid #ddd; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
.modal-footer { border-top: 1px solid #ddd; margin-top: 15px; padding-top: 15px; text-align: right; }
.close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
.close:hover { color: black; }
</style>

<div style="padding: 20px;">
    <h1>PUMP MANAGEMENT</h1>
    <p>CONFIGURE AND MANAGE FUEL PUMPS FOR STATIONS</p>
    
    <?php if ($msg): ?>
        <div class="alert <?php echo strpos($msg, '✅') === 0 ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>
    
    <!-- Station Selection (for Superadmin) -->
    <?php if ($isSuper): ?>
    <div class="pump-card">
        <form method="GET" style="display: flex; gap: 10px;">
            <div style="flex: 1;">
                <label>Select Station:</label>
                <select name="station" onchange="this.form.submit()">
                    <?php foreach ($stations as $st): ?>
                        <option value="<?php echo $st['id']; ?>" <?php echo $selected_station == $st['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($st['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
    <?php endif; ?>
    
    <?php if ($selected_station): ?>
    <!-- Station Name -->
    <div class="pump-card">
        <h3>
            <?php 
                $station_name = '';
                foreach ($stations as $st) {
                    if ($st['id'] == $selected_station) {
                        $station_name = $st['name'];
                        break;
                    }
                }
                echo htmlspecialchars($station_name);
            ?>
        </h3>
    </div>
    
    <!-- Add Pump Button -->
    <div style="margin-bottom: 20px;">
        <button class="btn btn-primary" onclick="openAddPumpModal()">
            Add New Pump
        </button>
    </div>
    
    <!-- Pumps Table -->
    <div class="pump-card">
        <h3>Fuel Pumps</h3>
        <?php if (!empty($pumps)): ?>
            <div style="overflow-x:hidden;">
                <table class="pump-table">
                    <thead>
                        <tr>
                            <th>Pump Number</th>
                            <th>Fuel Type</th>
                            <th>Status</th>
                            <th>Calibration</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pumps as $pump): ?>
                        <tr>
                            <td><strong>Pump #<?php echo htmlspecialchars($pump['pump_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($pump['fuel_type_name'] ?? 'Not Set'); ?></td>
                            <td>
                                <span class="status-<?php echo strtolower($pump['status']); ?>">
                                    <?php echo ucfirst($pump['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($pump['calibration_value'] !== null): ?>
                                    <?php echo htmlspecialchars($pump['calibration_value']); ?> L
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="openEditPumpModal(<?php echo $pump['id']; ?>, '<?php echo htmlspecialchars($pump['pump_number']); ?>', <?php echo $pump['fuel_type_id'] ?? 'null'; ?>, '<?php echo htmlspecialchars($pump['calibration_value'] ?? ''); ?>', '<?php echo $pump['status']; ?>')">
                                    Edit
                                </button>
                                <?php if ($isSuper): ?>
                                <button class="btn btn-sm btn-danger" onclick="openDeletePumpModal(<?php echo $pump['id']; ?>, '<?php echo htmlspecialchars($pump['pump_number']); ?>')">
                                    Delete
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="text-align: center; padding: 20px; color: #999;">No pumps configured for this station</p>
        <?php endif; ?>
    </div>
    
    <?php else: ?>
    <div class="pump-card" style="text-align: center; padding: 40px;">
        <div style="font-size: 48px; color: #999; margin-bottom: 20px;">ℹ️</div>
        <p>Please select a station to manage its pumps</p>
    </div>
    <?php endif; ?>
</div>

<!-- Add Pump Modal -->
<div id="addPumpModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Pump</h2>
            <span class="close" onclick="closeAddPumpModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_pump">
            <input type="hidden" name="station_id" value="<?php echo htmlspecialchars($selected_station); ?>">
            
            <div class="form-group">
                <label>Pump Number *</label>
                <input type="text" name="pump_number" placeholder="e.g., 1, 2, 3, A, B" required>
            </div>
            
            <div class="form-group">
                <label>Fuel Type *</label>
                <select name="fuel_type_id" required>
                    <option value="">-- Select Fuel Type --</option>
                    <?php foreach ($fuel_types as $ft): ?>
                        <option value="<?php echo $ft['id']; ?>"><?php echo htmlspecialchars($ft['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Status *</label>
                <select name="status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddPumpModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Pump</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Pump Modal -->
<div id="editPumpModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit Pump</h2>
            <span class="close" onclick="closeEditPumpModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_pump">
            <input type="hidden" name="pump_id" id="editPumpId">
            <input type="hidden" name="station_id" value="<?php echo htmlspecialchars($selected_station); ?>">
            
            <div class="form-group">
                <label>Pump Number</label>
                <input type="text" id="editPumpNumber" disabled>
            </div>
            
            <div class="form-group">
                <label>Fuel Type *</label>
                <select name="fuel_type_id" id="editFuelTypeId" required>
                    <option value="">-- Select Fuel Type --</option>
                    <?php foreach ($fuel_types as $ft): ?>
                        <option value="<?php echo $ft['id']; ?>"><?php echo htmlspecialchars($ft['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Status *</label>
                <select name="status" id="editStatus" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Calibration Value (Optional)</label>
                <input type="number" name="calibration_value" id="editCalibrationValue" step="0.000001" placeholder="e.g., 0.05 for variance tracking">
                <small style="color: #666;">Used for variance tracking in fuel reconciliation. Leave empty if not needed.</small>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditPumpModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Pump</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Pump Modal -->
<div id="deletePumpModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Delete Pump</h2>
            <span class="close" onclick="closeDeletePumpModal()">&times;</span>
        </div>
        <p style="margin-bottom: 20px;">Are you sure you want to delete <strong id="deletePumpName"></strong>?</p>
        <p style="color: #dc3545; font-weight: bold;">This action cannot be undone.</p>
        
        <form method="POST">
            <input type="hidden" name="action" value="delete_pump">
            <input type="hidden" name="pump_id" id="deletePumpId">
            <input type="hidden" name="station_id" value="<?php echo htmlspecialchars($selected_station); ?>">
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeletePumpModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Pump</button>
            </div>
        </form>
    </div>
</div>

<script>
// Pump Modal Functions
function openAddPumpModal() {
    document.getElementById('addPumpModal').classList.add('show');
}

function closeAddPumpModal() {
    document.getElementById('addPumpModal').classList.remove('show');
}

function openEditPumpModal(pumpId, pumpNumber, fuelTypeId, calibrationValue, status) {
    document.getElementById('editPumpId').value = pumpId;
    document.getElementById('editPumpNumber').value = pumpNumber;
    document.getElementById('editFuelTypeId').value = fuelTypeId || '';
    document.getElementById('editStatus').value = status;
    document.getElementById('editCalibrationValue').value = calibrationValue || '';
    document.getElementById('editPumpModal').classList.add('show');
}

function closeEditPumpModal() {
    document.getElementById('editPumpModal').classList.remove('show');
}

function openDeletePumpModal(pumpId, pumpNumber) {
    document.getElementById('deletePumpId').value = pumpId;
    document.getElementById('deletePumpName').textContent = 'Pump ' + pumpNumber;
    document.getElementById('deletePumpModal').classList.add('show');
}

function closeDeletePumpModal() {
    document.getElementById('deletePumpModal').classList.remove('show');
}

// Close modals when clicking outside
window.onclick = function(event) {
    let modals = ['addPumpModal', 'editPumpModal', 'deletePumpModal'];
    modals.forEach(function(modalId) {
        let modal = document.getElementById(modalId);
        if (event.target === modal) {
            modal.classList.remove('show');
        }
    });
}
</script>


<?php require_once __DIR__ . '/../partials/footer.php'; ?>
