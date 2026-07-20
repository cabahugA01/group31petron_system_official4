<?php
$page_id = 'view_stations';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../backend/rbac.php';
require_once __DIR__ . '/db_connect.php';
require_login();
require_permission(VIEW_ALL_STATIONS);

$me = current_user();
$isSuper = ($me['role'] === 'superadmin');

$notice = '';

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- ACTION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $notice = 'Invalid request.';
    } elseif (!$isSuper) {
        $notice = 'Only Super Admin can modify stations.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            require_permission(ACTIVATE_DEACTIVATE_STATION);
            $id = trim($_POST['id'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $manager = trim($_POST['manager'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if (empty($name) || empty($code)) {
                $notice = 'Station name and code are required.';
            } else {
                try {
                    if (empty($id)) {
                        $stmt = $pdo->prepare("INSERT INTO stations (name, location, status, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
                        $stmt->execute([$name, $location, $status]);
                        $new_station_id = (int)$pdo->lastInsertId();

                        // Seed default fuel inventory rows for the new station so Inventory Management isn't empty.
                        // (Merchandise items are added by admin as needed.)
                        try {
                            $stmt = $pdo->query("SELECT name FROM fuel_types");
                            $fuelTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            $ins = $pdo->prepare("INSERT INTO station_inventory (station_id, product_name, stock_level, type) VALUES (?, ?, 0, 'fuel')");
                            foreach ($fuelTypes as $ft) {
                                $ins->execute([$new_station_id, $ft]);
                            }
                        } catch (Exception $e) {
                            // Ignore if DB schema doesn't match.
                        }
                        log_user_action('Station Creation', "Created station '$name'");
                    } else {
                        $stmt = $pdo->prepare("UPDATE stations SET name = ?, location = ?, status = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$name, $location, $status, $id]);
                        log_user_action('Station Update', "Updated station '$name'");
                    }
                    $notice = "Station profile updated successfully";
                } catch (PDOException $e) {
                    $notice = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

// --- FETCH DATA ---
$stations = [];
try {
    $stmt = $pdo->query("SELECT s.*, 
                       (SELECT COUNT(*) FROM users u WHERE u.station_id = s.id AND u.status = 'Active') as active_users,
                       (SELECT u.name FROM users u WHERE u.station_id = s.id AND u.role = 'admin' LIMIT 1) as admin_name
                       FROM stations s 
                       ORDER BY s.name");
    $stations = $stmt->fetchAll();
} catch (PDOException $e) {
    $notice = "Database Error: " . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.page-container {
    display: flex;
    flex-direction: column;
    padding: 20px;
    min-height: 100%;
}

.page-header {
    margin-bottom: 20px;
}

.page-title {
    font-size: 24px;
    font-weight: 700;
    color: var(--text);
    margin: 0 0 8px 0;
}

.page-subtitle {
    color: var(--muted);
    font-size: 14px;
    margin: 0;
}

.filters-section {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: center;
    margin-bottom: 20px;
    padding: 15px;
    background: var(--card);
    border-radius: 8px;
    border: 1px solid var(--line);
}

.filter-input {
    padding: 8px 12px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--card);
    font-size: 14px;
    min-height: 42px;
    max-width: 100%;
    box-sizing: border-box;
}

.table-container {
     flex: 1;
     overflow-y: auto;
     border: 1px solid var(--line);
     border-radius: 8px;
     background: var(--card);
     max-height: 500px;
}

.stations-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.stations-table thead {
    background: var(--blue);
    color: white;
    position: sticky;
    top: 0;
    z-index: 10;
}

.stations-table th, .stations-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--line);
}

/* Define explicit column widths for alignment */
.stations-table th:nth-child(1),
.stations-table td:nth-child(1) { width: 15%; }  /* Station Code */
.stations-table th:nth-child(2),
.stations-table td:nth-child(2) { width: 25%; }  /* Location */
.stations-table th:nth-child(3),
.stations-table td:nth-child(3) { width: 20%; }  /* Manager */
.stations-table th:nth-child(4),
.stations-table td:nth-child(4) { width: 20%; }  /* Status */
.stations-table th:nth-child(5),
.stations-table td:nth-child(5) { width: 20%; }  /* Action */

.stations-table tbody tr:hover {
    background-color: rgba(0, 47, 108, 0.05);
}

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    display: inline-block;
}

.status-active { background: #28A745; color: white; }
.status-inactive { background: #DC3545; color: white; }

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.action-btn:hover {
    transform: translateY(-1px);
}

.action-btn.edit { background: #28A745; color: white; }

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
}

.modal-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: var(--card);
    padding: 30px;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}

.modal-header {
    margin-bottom: 20px;
}

.modal-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: var(--text);
    font-size: 14px;
}

.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--card);
    font-size: 14px;
}

.form-group textarea {
    resize: vertical;
    min-height: 80px;
}

.modal-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-primary {
    background: var(--blue);
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-secondary {
    background: #6C757D;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
}

.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 16px 20px;
    background: #28A745;
    color: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 2000;
    display: none;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
</style>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">STATIONS MANAGEMENT</h1>
        <p class="page-subtitle">List of all stations and basic information</p>
    </div>

    <div class="filters-section">
        <input type="text" class="filter-input" placeholder="Search Station Code / Location" id="searchInput" style="flex: 1; ">
    </div>

    <div class="table-container">
        <table class="stations-table">
            <thead>
                <tr>
                    <th>Station Code</th>
                    <th>Location</th>
                    <th>Manager</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="stationsTableBody">
                <?php foreach($stations as $station): ?>
                <tr data-status="<?php echo htmlspecialchars($station['status']); ?>" 
                    data-location="<?php echo htmlspecialchars($station['name']); ?>" 
                    data-manager="<?php echo htmlspecialchars($station['admin_name'] ?? ''); ?>"
                    data-name="<?php echo htmlspecialchars($station['name']); ?>"
                    data-code="<?php echo str_pad($station['id'], 4, '0', STR_PAD_LEFT); ?>">
                    <td><?php echo str_pad($station['id'], 4, '0', STR_PAD_LEFT); ?></td>
                    <td><?php echo htmlspecialchars($station['name']); ?></td>
                    <td><?php echo htmlspecialchars($station['admin_name'] ?? 'Not Assigned'); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo htmlspecialchars($station['status']); ?>">
                            <?php 
                            $statusIcon = $station['status'] === 'active' ? '✅' : '❌';
                            echo $statusIcon . ' ' . ucfirst(htmlspecialchars($station['status'])); 
                            ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <?php if($isSuper): ?>
                            <button class="action-btn edit" onclick="editStation(<?php echo $station['id']; ?>)" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- Edit Station Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="editModalTitle">Edit Station</h3>
        </div>
        <form id="editForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" id="editId" name="id">
            
            <div class="form-group">
                <label>Station Code</label>
                <input type="text" id="editCode" name="code" readonly>
            </div>
            <div class="form-group">
                <label>Location</label>
                <input type="text" id="editLocation" name="name" required>
            </div>
            <div class="form-group">
                <label>Manager</label>
                <input type="text" id="editManager" name="manager">
            </div>
            <div class="form-group">
                <label>Status</label>
                <div style="display: flex; gap: 20px; margin-top: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="status" value="active" checked>
                        <span>Active</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="status" value="inactive">
                        <span>Inactive</span>
                    </label>
                </div>
            </div>
            <div class="form-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<script>
function filterTable() {
    const searchInput = document.getElementById('searchInput').value.toLowerCase();
    
    const rows = document.querySelectorAll('#stationsTableBody tr');
    
    rows.forEach(row => {
        const name = row.dataset.name.toLowerCase();
        const stationCode = row.dataset.code.toLowerCase();
        
        const matchesSearch = !searchInput || name.includes(searchInput) || stationCode.includes(searchInput);
        
        row.style.display = matchesSearch ? '' : 'none';
    });
}


function editStation(id) {
    const row = document.querySelector(`tr:has(button[onclick="editStation(${id})"])`);
    if (!row) return;
    
    document.getElementById('editId').value = id;
    document.getElementById('editCode').value = row.cells[0].textContent;
    document.getElementById('editLocation').value = row.cells[1].textContent;
    document.getElementById('editManager').value = row.cells[2].textContent;
    
    const statusText = row.cells[3].textContent.trim().toLowerCase();
    // Handle radio buttons instead of dropdown
    const statusRadios = document.querySelectorAll('input[name="status"]');
    statusRadios.forEach(radio => {
        radio.checked = radio.value === statusText;
    });
    
    document.getElementById('editModalTitle').textContent = `Edit Station - ${row.cells[0].textContent}`;
    document.getElementById('editModal').style.display = 'block';
}

function toggleStatus(id) {
    // This function is removed as we only have View and Edit actions
    return;
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Run when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Add real-time search with debounce
    let searchTimeout;
    const searchInput = document.getElementById('searchInput');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                filterTable();
            }, 300); // 300ms debounce
        });
    }
});

// Show toast notification
function showToast(message, type = 'success') {
    if (window.showPetronFlash) {
        window.showPetronFlash(message, type);
        return;
    }
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.style.background = type === 'success' ? '#28A745' : '#DC3545';
    toast.style.display = 'block';
    
    setTimeout(() => {
        toast.style.display = 'none';
    }, 3000);
}

// Handle form submission
document.getElementById('editForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(() => {
        closeModal('editModal');
        showToast('Station profile updated successfully');
        setTimeout(() => location.reload(), 1000);
    })
    .catch(error => {
        showToast('Error updating station', 'error');
    });
});

<?php if ($notice): ?>
showToast('<?php echo htmlspecialchars($notice); ?>', '<?php echo strpos($notice, 'Error') !== false ? 'error' : 'success'; ?>');
<?php endif; ?>
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
