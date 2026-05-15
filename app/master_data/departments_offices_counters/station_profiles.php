<?php
$page_id = 'station_profiles';
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
        $notice = 'Only Super Admin can modify station profiles.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_profile') {
            $id = trim($_POST['id'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $manager = trim($_POST['manager'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $opening_hours = trim($_POST['opening_hours'] ?? '');
            $fuel_types = trim($_POST['fuel_types'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if (empty($name) || empty($code)) {
                $notice = 'Station name and code are required.';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE stations SET name = ?, code = ?, location = ?, manager = ?, phone = ?, email = ?, opening_hours = ?, fuel_types = ?, notes = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$name, $code, $location, $manager, $phone, $email, $opening_hours, $fuel_types, $notes, $id]);
                    
                    // Update station profile in a separate table or extend stations table
                    // For now, we'll just update the main stations table
                    log_user_action('Station Profile Update', "Updated profile for station '$name'");
                    $notice = "Station profile updated successfully.";
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

.profiles-table {
    width: 100%;
    height: 100%;
    border-collapse: collapse;
}

.profiles-table thead {
    background: var(--blue);
    color: white;
    position: sticky;
    top: 0;
    z-index: 10;
}

.profiles-table th, .profiles-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--line);
}

.profiles-table tbody {
    overflow-y: auto;
    max-height: calc(100vh - 300px);
}

.profiles-table tbody tr {
    transition: background-color 0.2s;
    height: 48px;
}

.profiles-table tbody tr:hover {
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
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
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

.profile-section {
    margin-bottom: 25px;
    padding: 20px;
    background: rgba(0, 47, 108, 0.03);
    border-radius: 8px;
    border: 1px solid var(--line);
}

.profile-section h4 {
    margin: 0 0 15px 0;
    color: var(--blue);
    font-size: 16px;
    font-weight: 600;
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
    padding-top: 20px;
    border-top: 1px solid var(--line);
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
        <h1 class="page-title">STATION PROFILES</h1>
        <p class="page-subtitle">Detailed profile information for each station</p>
    </div>

    <div class="filters-section">
        <select class="filter-select" id="stationFilter">
            <option value="">All Station Names</option>
            <?php foreach($stations as $station): ?>
                <option value="<?php echo htmlspecialchars($station['name']); ?>"><?php echo htmlspecialchars($station['name']); ?></option>
            <?php endforeach; ?>
        </select>
        
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
    </div>

    <div class="table-container">
        <table class="profiles-table">
            <thead>
                <tr>
                    <th>Station Code</th>
                    <th>Location</th>
                    <th>Manager</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="profilesTableBody">
                <?php foreach($stations as $station): ?>
                <tr data-station="<?php echo htmlspecialchars($station['name']); ?>" 
                    data-status="<?php echo htmlspecialchars($station['status']); ?>" 
                    data-location="<?php echo htmlspecialchars($station['name']); ?>" 
                    data-manager="<?php echo htmlspecialchars($station['admin_name'] ?? ''); ?>"
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
                            <button class="action-btn view" onclick="viewProfile(<?php echo $station['id']; ?>)" title="View Profile">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if($isSuper): ?>
                            <button class="action-btn edit" onclick="editProfile(<?php echo $station['id']; ?>)" title="Edit Profile">
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

<!-- View Profile Modal -->
<div id="viewProfileModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="viewProfileModalTitle">Station Profile Details</h3>
        </div>
        <div class="modal-body">
            <!-- Basic Info Section -->
            <div class="profile-section">
                <h4>Basic Information</h4>
                <div class="form-group">
                    <label>Station Name</label>
                    <input type="text" id="viewName" readonly>
                </div>
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
            </div>

            <!-- Contact Info Section -->
            <div class="profile-section">
                <h4>Contact Information</h4>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" id="viewPhone" readonly>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="text" id="viewEmail" readonly>
                </div>
            </div>

            <!-- Station Details Section -->
            <div class="profile-section">
                <h4>Station Details</h4>
                <div class="form-group">
                    <label>Opening Hours</label>
                    <input type="text" id="viewOpeningHours" readonly>
                </div>
                <div class="form-group">
                    <label>Fuel Types</label>
                    <input type="text" id="viewFuelTypes" readonly>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="viewNotes" readonly></textarea>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('viewProfileModal')">Close</button>
        </div>
    </div>
</div>

<!-- Edit Profile Modal -->
<div id="editProfileModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="editProfileModalTitle">Edit Station Profile</h3>
        </div>
        <form id="editProfileForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="save_profile">
            <input type="hidden" id="editProfileId" name="id">
            
            <!-- Basic Info Section -->
            <div class="profile-section">
                <h4>Basic Information</h4>
                <div class="form-group">
                    <label>Station Name</label>
                    <input type="text" id="editName" name="name" required>
                </div>
                <div class="form-group">
                    <label>Station Code</label>
                    <input type="text" id="editCode" name="code" required>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" id="editLocation" name="location">
                </div>
                <div class="form-group">
                    <label>Manager</label>
                    <input type="text" id="editManager" name="manager">
                </div>
            </div>

            <!-- Contact Info Section -->
            <div class="profile-section">
                <h4>Contact Information</h4>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" id="editPhone" name="phone">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="editEmail" name="email">
                </div>
            </div>

            <!-- Station Details Section -->
            <div class="profile-section">
                <h4>Station Details</h4>
                <div class="form-group">
                    <label>Opening Hours</label>
                    <input type="text" id="editOpeningHours" name="opening_hours" placeholder="e.g., 24/7 or 6:00 AM - 10:00 PM">
                </div>
                <div class="form-group">
                    <label>Fuel Types</label>
                    <input type="text" id="editFuelTypes" name="fuel_types" placeholder="e.g., Diesel, Gasoline, Premium">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="editNotes" name="notes" placeholder="Additional station information..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('editProfileModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<script>
// Filter functionality
document.getElementById('stationFilter').addEventListener('change', filterTable);
document.getElementById('statusFilter').addEventListener('change', filterTable);
document.getElementById('locationFilter').addEventListener('change', filterTable);
document.getElementById('managerFilter').addEventListener('change', filterTable);

function filterTable() {
    const stationFilter = document.getElementById('stationFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const locationFilter = document.getElementById('locationFilter').value;
    const managerFilter = document.getElementById('managerFilter').value;
    
    const rows = document.querySelectorAll('#profilesTableBody tr');
    
    rows.forEach(row => {
        const station = row.dataset.station;
        const status = row.dataset.status;
        const location = row.dataset.location;
        const manager = row.dataset.manager;
        
        const matchesStation = !stationFilter || station === stationFilter;
        const matchesStatus = !statusFilter || status === statusFilter;
        const matchesLocation = !locationFilter || location === locationFilter;
        const matchesManager = !managerFilter || manager === managerFilter;
        
        row.style.display = matchesStation && matchesStatus && matchesLocation && matchesManager ? '' : 'none';
    });
}

// Modal functions
function viewProfile(id) {
    const row = document.querySelector(`tr:has(button[onclick="viewProfile(${id})"])`);
    if (!row) return;
    
    // Mock data for demonstration - in real implementation, fetch from database
    document.getElementById('viewName').value = row.cells[1].textContent;
    document.getElementById('viewCode').value = row.cells[0].textContent;
    document.getElementById('viewLocation').value = row.cells[1].textContent;
    document.getElementById('viewManager').value = row.cells[2].textContent;
    document.getElementById('viewPhone').value = '+63 912 345 6789';
    document.getElementById('viewEmail').value = 'station' + id + '@petron.com';
    document.getElementById('viewOpeningHours').value = '24/7';
    document.getElementById('viewFuelTypes').value = 'Diesel, Gasoline, Premium, XCS';
    document.getElementById('viewNotes').value = 'Strategic location with high traffic volume. Modern facilities with convenience store.';
    
    document.getElementById('viewProfileModalTitle').textContent = `Profile - ${row.cells[0].textContent}`;
    document.getElementById('viewProfileModal').style.display = 'block';
}

function editProfile(id) {
    const row = document.querySelector(`tr:has(button[onclick="editProfile(${id})"])`);
    if (!row) return;
    
    document.getElementById('editProfileId').value = id;
    document.getElementById('editName').value = row.cells[1].textContent;
    document.getElementById('editCode').value = row.cells[0].textContent;
    document.getElementById('editLocation').value = row.cells[1].textContent;
    document.getElementById('editManager').value = row.cells[2].textContent;
    
    // Mock data for demonstration
    document.getElementById('editPhone').value = '+63 912 345 6789';
    document.getElementById('editEmail').value = 'station' + id + '@petron.com';
    document.getElementById('editOpeningHours').value = '24/7';
    document.getElementById('editFuelTypes').value = 'Diesel, Gasoline, Premium, XCS';
    document.getElementById('editNotes').value = 'Strategic location with high traffic volume. Modern facilities with convenience store.';
    
    document.getElementById('editProfileModalTitle').textContent = `Edit Profile - ${row.cells[0].textContent}`;
    document.getElementById('editProfileModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
});

// Show toast notification
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.style.background = type === 'success' ? '#28A745' : '#DC3545';
    toast.style.display = 'block';
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    
    setTimeout(() => {
        toast.style.display = 'none';
    }, 3000);
}

// Handle form submission
document.getElementById('editProfileForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(() => {
        closeModal('editProfileModal');
        showToast('Station profile updated successfully');
        setTimeout(() => location.reload(), 1000);
    })
    .catch(error => {
        showToast('Error updating station profile', 'error');
    });
});

<?php if ($notice): ?>
showToast('<?php echo htmlspecialchars($notice); ?>', '<?php echo strpos($notice, 'Error') !== false ? 'error' : 'success'; ?>');
<?php endif; ?>
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
