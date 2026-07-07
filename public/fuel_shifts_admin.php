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

    if ($action === 'add_shift') {
        $name = trim($_POST['name']);
        $start_time = trim($_POST['start_time']);
        $end_time = trim($_POST['end_time']);
        $description = trim($_POST['description'] ?? '');

        if ($name && $start_time && $end_time) {
            try {
                $stmt = $pdo->prepare("INSERT INTO shifts (name, start_time, end_time, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $start_time, $end_time, $description]);
                $msg = "✅ Shift added successfully!";
                log_activity($pdo, $user['id'], 'Add Shift', "Added shift: $name", 'shift_management');
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Error: Name, start time, and end time are required.";
        }
    } elseif ($action === 'edit_shift') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $start_time = trim($_POST['start_time']);
        $end_time = trim($_POST['end_time']);
        $description = trim($_POST['description'] ?? '');

        if ($id && $name && $start_time && $end_time) {
            try {
                $stmt = $pdo->prepare("UPDATE shifts SET name = ?, start_time = ?, end_time = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $start_time, $end_time, $description, $id]);
                $msg = "✅ Shift updated successfully!";
                log_activity($pdo, $user['id'], 'Edit Shift', "Updated shift ID $id to: $name", 'shift_management');
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Error: All fields are required.";
        }
    } elseif ($action === 'delete_shift') {
        $id = (int)$_POST['id'];
        $csrf_token = $_POST['csrf_token'] ?? '';

        if ($id && $csrf_token === $_SESSION['csrf_token']) {
            try {
                // Check if shift is in use
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM fuel_daily_readings WHERE shift = (SELECT name FROM shifts WHERE id = ?)");
                $stmt->execute([$id]);
                $inUse = $stmt->fetch()['count'] > 0;

                if ($inUse) {
                    $msg = "⚠️ Warning: This shift is currently in use and cannot be deleted.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM shifts WHERE id = ?");
                    $stmt->execute([$id]);
                    $msg = "✅ Shift deleted successfully!";
                    log_activity($pdo, $user['id'], 'Delete Shift', "Deleted shift ID $id", 'shift_management');
                }
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Error: Invalid request.";
        }
    } elseif ($action === 'toggle_shift') {
        $id = (int)$_POST['id'];
        $csrf_token = $_POST['csrf_token'] ?? '';

        if ($id && $csrf_token === $_SESSION['csrf_token']) {
            try {
                $stmt = $pdo->prepare("UPDATE shifts SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$id]);
                $msg = "✅ Shift status updated successfully!";
                log_activity($pdo, $user['id'], 'Toggle Shift', "Toggled shift ID $id", 'shift_management');
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
}

// Fetch all shifts
$shifts = [];
try {
    $stmt = $pdo->query("SELECT * FROM shifts ORDER BY sort_order");
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching shifts: " . $e->getMessage());
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
.shift-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.shift-card {
    padding: 20px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    transition: all 0.2s;
}
.shift-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-color: var(--petron-blue);
}
.shift-card.inactive {
    opacity: 0.6;
}
.shift-time {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
    font-size: 18px;
    font-weight: bold;
    color: var(--petron-blue);
}
.shift-time span {
    font-size: 14px;
    font-weight: normal;
    color: #667085;
}
.shift-description {
    margin-top: 10px;
    color: #667085;
    line-height: 1.5;
}
.shift-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}
.btn-group {
    display: flex;
    gap: 10px;
}
</style>

<div class="page-head">
  <h1>Fuel Shifts Management</h1>
  <div class="sub">Manage shift schedules for fuel operations</div>
</div>

<?php if($msg): ?>
  <div class="card" style="padding: 15px; margin-bottom: 20px; background: #e6f4ea; color: green;">
    <?php echo $msg; ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div class="card-title">All Shifts</div>
    <button class="btn primary" onclick="openAddModal()">
      <i class="fas fa-plus"></i> Add Shift
    </button>
  </div>

  <div class="shift-grid">
    <?php if(!empty($shifts)): ?>
      <?php foreach($shifts as $shift): ?>
        <div class="shift-card <?php echo $shift['is_active'] ? '' : 'inactive'; ?>">
          <div class="shift-time">
            <?php echo htmlspecialchars($shift['name']); ?>
            <span><?php echo htmlspecialchars($shift['start_time']); ?> - <?php echo htmlspecialchars($shift['end_time']); ?></span>
          </div>
          
          <div class="shift-description">
            <?php echo htmlspecialchars($shift['description'] ?? 'No description'); ?>
          </div>
          
          <div class="shift-actions">
            <div class="btn-group">
              <button class="btn ghost small" onclick="openEditModal(<?php echo $shift['id']; ?>, '<?php echo htmlspecialchars($shift['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($shift['start_time'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($shift['end_time'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($shift['description'] ?? '', ENT_QUOTES); ?>', <?php echo (int)$shift['is_active']; ?>)">
                <i class="fas fa-edit"></i> Edit
              </button>
              <button class="btn ghost small" onclick="toggleShift(<?php echo $shift['id']; ?>)">
                <i class="fas fa-<?php echo $shift['is_active'] ? 'eye-slash' : 'eye'; ?>"></i> <?php echo $shift['is_active'] ? 'Deactivate' : 'Activate'; ?>
              </button>
              <button class="btn ghost small" onclick="confirmDelete(<?php echo $shift['id']; ?>)">
                <i class="fas fa-trash"></i> Delete
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div style="grid-column: 1/-1; text-align: center; padding: 40px;">
        <div class="empty">
          <div class="empty-ico"><i class="fas fa-clock"></i></div>
          <div class="muted">No shifts found</div>
        </div>
      <?php endif; ?>
  </div>
</div>

<!-- Add Shift Modal -->
<div class="modal" id="addShiftModal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Add New Shift</div>
      <button class="icon-btn" onclick="closeModal('addShiftModal')">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
      <input type="hidden" name="action" value="add_shift" />

      <div class="pay-section">
        <label class="pay-label">Shift Name</label>
        <input class="input" name="name" type="text" placeholder="e.g., Morning" required />
      </div>

      <div class="pay-section">
        <label class="pay-label">Start Time</label>
        <input class="input" name="start_time" type="time" required />
      </div>

      <div class="pay-section">
        <label class="pay-label">End Time</label>
        <input class="input" name="end_time" type="time" required />
      </div>

      <div class="pay-section">
        <label class="pay-label">Description</label>
        <textarea class="input" name="description" rows="3" placeholder="Description of this shift..."></textarea>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn ghost" onclick="closeModal('addShiftModal')">Cancel</button>
        <button type="submit" class="btn primary">Add Shift</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Shift Modal -->
<div class="modal" id="editShiftModal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-title">Edit Shift</div>
      <button class="icon-btn" onclick="closeModal('editShiftModal')">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
      <input type="hidden" name="action" value="edit_shift" />
      <input type="hidden" name="id" id="editShiftId" />

      <div class="pay-section">
        <label class="pay-label">Shift Name</label>
        <input class="input" name="name" id="editShiftName" type="text" placeholder="e.g., Morning" required />
      </div>

      <div class="pay-section">
        <label class="pay-label">Start Time</label>
        <input class="input" name="start_time" id="editShiftStart" type="time" required />
      </div>

      <div class="pay-section">
        <label class="pay-label">End Time</label>
        <input class="input" name="end_time" id="editShiftEnd" type="time" required />
      </div>

      <div class="pay-section">
        <label class="pay-label">Description</label>
        <textarea class="input" name="description" id="editShiftDesc" rows="3" placeholder="Description of this shift..."></textarea>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn ghost" onclick="closeModal('editShiftModal')">Cancel</button>
        <button type="submit" class="btn primary">Update Shift</button>
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
      <p>Are you sure you want to delete this shift?</p>
      <p style="color: var(--petron-red);"><strong>Warning:</strong> This shift may be in use in fuel readings. Deleting will not remove historical data but will prevent future readings with this shift.</p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
        <input type="hidden" name="action" value="delete_shift" />
        <input type="hidden" name="id" id="deleteShiftId" />

        <div class="modal-actions">
          <button type="button" class="btn ghost" onclick="closeModal('deleteModal')">Cancel</button>
          <button type="submit" class="btn" style="background: var(--petron-red); color: white;">Delete</button>
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

// Add Shift
function openAddModal() {
    openModal('addShiftModal');
}

// Edit Shift
function openEditModal(id, name, startTime, endTime, description, isActive) {
    document.getElementById('editShiftId').value = id;
    document.getElementById('editShiftName').value = name || '';
    document.getElementById('editShiftStart').value = startTime || '';
    document.getElementById('editShiftEnd').value = endTime || '';
    document.getElementById('editShiftDesc').value = description || '';
    openModal('editShiftModal');
}

// Toggle Shift Active/Inactive
function toggleShift(id) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="toggle_shift" />
        <input type="hidden" name="id" value="${id}" />
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
    `;
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
    
    // Reload page to show updated status
    setTimeout(() => window.location.reload(), 300);
}

// Delete Shift
function confirmDelete(id) {
    document.getElementById('deleteShiftId').value = id;
    openModal('deleteModal');
}

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
