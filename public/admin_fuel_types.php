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

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_fuel_type') {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');

        if ($name) {
            try {
                $stmt = $pdo->prepare("INSERT INTO fuel_types (name, description) VALUES (?, ?)");
                $stmt->execute([$name, $description]);
                $msg = "✅ Fuel type added successfully!";
                log_activity($pdo, $user['id'], 'Add Fuel Type', "Added fuel type: $name", 'fuel_types');
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Error: Fuel type name is required.";
        }
    } elseif ($action === 'edit_fuel_type') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');

        if ($id && $name) {
            try {
                $stmt = $pdo->prepare("UPDATE fuel_types SET name = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $description, $id]);
                $msg = "✅ Fuel type updated successfully!";
                log_activity($pdo, $user['id'], 'Edit Fuel Type', "Updated fuel type ID $id to: $name", 'fuel_types');
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Error: Invalid request.";
        }
    } elseif ($action === 'delete_fuel_type') {
        $id = (int)$_POST['id'];
        $csrf_token = $_POST['csrf_token'] ?? '';

        if ($id && $csrf_token === $_SESSION['csrf_token']) {
            try {
                // Check if fuel type is in use
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM fuel_deliveries WHERE fuel_type = ?");
                $stmt->execute([$id]);
                $inUse = $stmt->fetch()['count'] > 0;

                if ($inUse) {
                    $msg = "⚠️ Warning: This fuel type is currently in use and cannot be deleted.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM fuel_types WHERE id = ?");
                    $stmt->execute([$id]);
                    $msg = "✅ Fuel type deleted successfully!";
                    log_activity($pdo, $user['id'], 'Delete Fuel Type', "Deleted fuel type ID $id", 'fuel_types');
                }
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Error: Invalid request.";
        }
    }
}

// Fetch all fuel types
$fuel_types = [];
try {
    $stmt = $pdo->query("SELECT * FROM fuel_types ORDER BY name");
    $fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching fuel types: " . $e->getMessage());
}

require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
.btn-group {
    display: flex;
    gap: 10px;
}
.modal-card {
    width: min(500px, 95vw);
}
</style>

<div class="page-head">
  <h1>FUEL TYPES MANAGEMENT</h1>
  <div class="sub">MANAGE PETRON-BRANDED FUEL TYPES FOR THE SYSTEM</div>
</div>

<?php if($msg): ?>
  <div class="card" style="padding: 15px; margin-bottom: 20px; background: #e6f4ea; color: green;">
    <?php echo $msg; ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div class="card-title">All Fuel Types</div>
    <button class="btn primary" onclick="openAddModal()">
      Add Fuel Type
    </button>
  </div>

  <div class="table-tools">
    <div class="searchbar small">
      <span class="ico">Search</span>
      <input id="fuelTypesSearch" placeholder="Search fuel types..." autocomplete="off" />
    </div>
  </div>

  <div class="table-wrap">
    <table class="table" id="fuelTypesTable">
      <thead>
        <tr>
          <th>ID</th>
          <th>Fuel Type Name</th>
          <th>Description</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!empty($fuel_types)): ?>
          <?php foreach($fuel_types as $type): ?>
            <tr>
              <td><?php echo $type['id']; ?></td>
              <td><strong><?php echo htmlspecialchars($type['name']); ?></strong></td>
              <td><?php echo htmlspecialchars($type['description'] ?? 'N/A'); ?></td>
              <td>
                <div class="btn-group">
                  <button class="btn ghost small" onclick="openEditModal(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars($type['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($type['description'] ?? '', ENT_QUOTES); ?>')">
                    Edit
                  </button>
                  <button class="btn ghost small" onclick="confirmDelete(<?php echo $type['id']; ?>)">
                    Delete
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="4" style="text-align: center; padding: 40px;">
              <div class="empty">
                <div class="empty-ico">No Data</div>
                <div class="muted">No fuel types found</div>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Fuel Type Modal -->
<div class="modal" id="addFuelTypeModal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Add New Fuel Type</div>
      <button class="icon-btn" onclick="closeModal('addFuelTypeModal')">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
      <input type="hidden" name="action" value="add_fuel_type" />

      <div class="pay-section">
        <label class="pay-label">Fuel Type Name</label>
        <input class="input" name="name" type="text" placeholder="e.g., Petron Blaze 100 (High Octane)" required />
      </div>

      <div class="pay-section">
        <label class="pay-label">Description</label>
        <textarea class="input" name="description" rows="3" placeholder="Description of this fuel type..."></textarea>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn ghost" onclick="closeModal('addFuelTypeModal')">Cancel</button>
        <button type="submit" class="btn primary">Add Fuel Type</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Fuel Type Modal -->
<div class="modal" id="editFuelTypeModal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Edit Fuel Type</div>
      <button class="icon-btn" onclick="closeModal('editFuelTypeModal')">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
      <input type="hidden" name="action" value="edit_fuel_type" />
      <input type="hidden" name="id" id="editFuelTypeId" />

      <div class="pay-section">
        <label class="pay-label">Fuel Type Name</label>
        <input class="input" name="name" id="editFuelTypeName" type="text" placeholder="e.g., Petron Blaze 100 (High Octane)" required />
      </div>

      <div class="pay-section">
        <label class="pay-label">Description</label>
        <textarea class="input" name="description" id="editFuelTypeDesc" rows="3" placeholder="Description of this fuel type..."></textarea>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn ghost" onclick="closeModal('editFuelTypeModal')">Cancel</button>
        <button type="submit" class="btn primary">Update Fuel Type</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Confirm Delete</div>
      <button class="icon-btn" onclick="closeModal('deleteModal')">✕</button>
    </div>
    <div style="padding: 20px;">
      <p>Are you sure you want to delete this fuel type?</p>
      <p style="color: var(--danger);"><strong>Warning:</strong> This action cannot be undone.</p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
        <input type="hidden" name="action" value="delete_fuel_type" />
        <input type="hidden" name="id" id="deleteFuelTypeId" />

        <div class="modal-actions">
          <button type="button" class="btn ghost" onclick="closeModal('deleteModal')">Cancel</button>
          <button type="submit" class="btn" style="background: var(--danger); color: white;">Delete</button>
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

// Add Fuel Type
function openAddModal() {
    openModal('addFuelTypeModal');
}

// Edit Fuel Type
function openEditModal(id, name, description) {
    document.getElementById('editFuelTypeId').value = id;
    document.getElementById('editFuelTypeName').value = name;
    document.getElementById('editFuelTypeDesc').value = description || '';
    openModal('editFuelTypeModal');
}

// Delete Fuel Type
function confirmDelete(id) {
    document.getElementById('deleteFuelTypeId').value = id;
    openModal('deleteModal');
}

// Table Search
document.getElementById('fuelTypesSearch').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const rows = document.querySelectorAll('#fuelTypesTable tbody tr');

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
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
