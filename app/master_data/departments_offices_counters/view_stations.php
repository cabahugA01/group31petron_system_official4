<?php
$page_id = 'view_stations';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../backend/rbac.php';
require_once __DIR__ . '/../public/db_connect.php';
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
                            $fuelTypes = ['Diesel Max','XCS Plus','XCS Advance','Turbo Diesel','Kerosene'];
                            $ins = $pdo->prepare("INSERT INTO inventory (station_id, product_name, stock_level, type) VALUES (?, ?, 0, 'fuel')");
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
                       (SELECT COUNT(*) FROM users u WHERE u.station_id = s.id AND u.status = 'active') as active_users,
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
    height: calc(100vh - 110px);
    display: flex;
    flex-direction: column;
    padding: 20px;
    overflow: hidden;
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
    gap: 15px;
    align-items: center;
    margin-bottom: 20px;
    padding: 15px;
    background: var(--card);
    border-radius: 8px;
    border: 1px solid var(--line);
}

.filter-select, .filter-input {
    padding: 8px 12px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--card);
    font-size: 14px;
}

.filter-input {
    flex: 1;
    max-width: 300px;
}

.table-container {
    flex: 1;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--card);
}

.stations-table {
    width: 100%;
    height: 100%;
    border-collapse: collapse;
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

.stations-table tbody {
    overflow-y: auto;
    max-height: calc(100vh - 300px);
}

.stations-table tbody tr {
    transition: background-color 0.2s;
    height: 48px;
}

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

.action-btn.view { background: #007BFF; color: white; }
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
        <select class="filter-select" id="statusFilter">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
        
        <select class="filter-select" id="locationFilter">
            <option value="">All Locations</option>
            <?php 
            $locations = array_unique(array_filter(array_column($stations, 'name')));
            foreach($locations as $location): ?>
                <option value="<?php echo htmlspecialchars($location); ?>"><?php echo htmlspecialchars($location); ?></option>
            <?php endforeach; ?>
        </select>
        
        <select class="filter-select" id="managerFilter">
            <option value="">All Managers</option>
            <?php 
            $managers = array_unique(array_filter(array_column($stations, 'admin_name')));
            foreach($managers as $manager): ?>
                <option value="<?php echo htmlspecialchars($manager); ?>"><?php echo htmlspecialchars($manager); ?></option>
            <?php endforeach; ?>
        </select>
        
        <input type="text" class="filter-input" placeholder="🔍 Search Station Code / Location" id="searchInput">
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
                            <button class="action-btn view" onclick="viewStation(<?php echo $station['id']; ?>)" title="View">
                                <i class="fas fa-eye"></i>
                            </button>
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

<!-- View Station Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="viewModalTitle">Station Details</h3>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Station Code</label>
                <input type="text" id="viewCode" readonly>
            </div>
            <div class="form-group">
                <label>Location</label>
                <input type="text" id="viewLocation" readonly>
            </div>
            <div class="form-group">
                <label>Manager</label>
                <input type="text" id="viewManager" readonly>
            </div>
            <div class="form-group">
                <label>Status</label>
                <input type="text" id="viewStatus" readonly>
            </div>
            <div class="form-group">
                <label>Last Updated</label>
                <input type="text" id="viewLastUpdated" readonly>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('viewModal')">Close</button>
        </div>
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
                <select id="editStatus" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
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
// Filter functionality
document.getElementById('statusFilter').addEventListener('change', filterTable);
document.getElementById('locationFilter').addEventListener('change', filterTable);
document.getElementById('managerFilter').addEventListener('change', filterTable);
document.getElementById('searchInput').addEventListener('input', filterTable);

function filterTable() {
    const statusFilter = document.getElementById('statusFilter').value;
    const locationFilter = document.getElementById('locationFilter').value;
    const managerFilter = document.getElementById('managerFilter').value;
    const searchInput = document.getElementById('searchInput').value.toLowerCase();
    
    console.log('Filtering stations:', { statusFilter, locationFilter, managerFilter, searchInput });
    
    const rows = document.querySelectorAll('#stationsTableBody tr');
    console.log('Found rows:', rows.length);
    
    rows.forEach(row => {
        const status = row.dataset.status;
        const location = row.dataset.location;
        const manager = row.dataset.manager;
        const name = row.dataset.name.toLowerCase();
        const stationCode = row.dataset.code.toLowerCase();
        
        const matchesStatus = !statusFilter || status === statusFilter;
        const matchesLocation = !locationFilter || location === locationFilter;
        const matchesManager = !managerFilter || manager === managerFilter;
        const matchesSearch = !searchInput || name.includes(searchInput) || stationCode.includes(searchInput);
        
        const shouldShow = matchesStatus && matchesLocation && matchesManager && matchesSearch;
        row.style.display = shouldShow ? '' : 'none';
        
        if (!shouldShow) {
            console.log('Row hidden:', { name: row.dataset.name, code: row.dataset.code, status, location, manager });
        }
    });
}

// Modal functions
function viewStation(id) {
    const row = document.querySelector(`tr:has(button[onclick="viewStation(${id})"])`);
    if (!row) return;
    
    document.getElementById('viewCode').value = row.cells[0].textContent;
    document.getElementById('viewLocation').value = row.cells[1].textContent;
    document.getElementById('viewManager').value = row.cells[2].textContent;
    document.getElementById('viewStatus').value = row.cells[3].textContent.trim();
    document.getElementById('viewLastUpdated').value = new Date().toLocaleDateString();
    
    document.getElementById('viewModalTitle').textContent = `Station Details - ${row.cells[0].textContent}`;
    document.getElementById('viewModal').style.display = 'block';
}

function editStation(id) {
    const row = document.querySelector(`tr:has(button[onclick="editStation(${id})"])`);
    if (!row) return;
    
    document.getElementById('editId').value = id;
    document.getElementById('editCode').value = row.cells[0].textContent;
    document.getElementById('editLocation').value = row.cells[1].textContent;
    document.getElementById('editManager').value = row.cells[2].textContent;
    
    const statusText = row.cells[3].textContent.trim().toLowerCase();
    document.getElementById('editStatus').value = statusText;
    
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

// Test function to verify search functionality
function testSearchFunction() {
    console.log('Search function test - JavaScript is loaded');
    console.log('filterTable function exists:', typeof filterTable);
    
    // Test search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.value = 'test';
        filterTable();
        console.log('Search test completed');
    }
}

// Run test when page loads
document.addEventListener('DOMContentLoaded', function() {
    testSearchFunction();
    
    // Add real-time search with debounce
    let searchTimeout;
    const searchInput = document.getElementById('searchInput');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                console.log('Real-time search triggered with value:', this.value);
                filterTable();
            }, 300); // 300ms debounce
        });
    }
    
    // Test initial filtering
    console.log('Initial search test...');
    setTimeout(() => {
        if (searchInput) {
            searchInput.value = 'test';
            filterTable();
            searchInput.value = ''; // Clear test
        }
    }, 1000);
});

// Show toast notification
function showToast(message, type = 'success') {
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
