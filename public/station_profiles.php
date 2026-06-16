    <?php
$page_id = 'station_profiles';
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
            $address = trim($_POST['address'] ?? '');
            $vat_tin = trim($_POST['vat_tin'] ?? '');

            if (empty($name) || empty($code)) {
                $notice = 'Station name and code are required.';
            } else {
                try {
                    // Ensure address and vat_tin columns exist
                    $existingCols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);
                    if (!in_array('address', $existingCols)) {
                        $pdo->exec("ALTER TABLE stations ADD COLUMN address VARCHAR(500) NULL AFTER location");
                    }
                    if (!in_array('vat_tin', $existingCols)) {
                        $pdo->exec("ALTER TABLE stations ADD COLUMN vat_tin VARCHAR(50) NULL AFTER address");
                    }

                    $stmt = $pdo->prepare("UPDATE stations SET name = ?, code = ?, location = ?, address = ?, vat_tin = ?, manager = ?, phone = ?, email = ?, opening_hours = ?, fuel_types = ?, notes = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$name, $code, $location, $address, $vat_tin, $manager, $phone, $email, $opening_hours, $fuel_types, $notes, $id]);
                    
                    log_user_action('Station Profile Update', "Updated profile for station '$name'");
                    $notice = "Station profile updated successfully.";
                } catch (PDOException $e) {
                    $notice = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

// --- AJAX: fetch single station for edit modal ---
if (isset($_GET['fetch_station_id']) && is_numeric($_GET['fetch_station_id'])) {
    header('Content-Type: application/json');
    try {
        $s = $pdo->prepare("SELECT * FROM stations WHERE id = ? LIMIT 1");
        $s->execute([(int)$_GET['fetch_station_id']]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['station' => $row ?: null]);
    } catch (Exception $e) {
        echo json_encode(['station' => null]);
    }
    exit;
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
}if (!function_exists('split_station_location_parts')) {
    function split_station_location_parts(string $raw): array {
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), static function ($value) {
            return $value !== '';
        }));

        $streetAddress = '';
        $cityProvince = '';
        $region = '';
        $stationType = '';

        $count = count($parts);
        if ($count === 1) {
            $streetAddress = $parts[0];
        } elseif ($count === 2) {
            $streetAddress = $parts[0];
            $cityProvince = $parts[1];
        } elseif ($count === 3) {
            $streetAddress = $parts[0];
            $cityProvince = $parts[1];
            $region = $parts[2];
        } elseif ($count > 3) {
            $streetAddress = implode(', ', array_slice($parts, 0, $count - 3));
            $cityProvince = $parts[$count - 3];
            $region = $parts[$count - 2];
            $stationType = $parts[$count - 1];
        }

        if ($stationType === '' && $region !== '') {
            if (preg_match('/^(.*?)(\s+(CAR CARE CENTER|TREATS STORE|SERVICE STATION|FUEL STATION|STATION))$/i', $region, $matches)) {
                $region = trim($matches[1]);
                $stationType = trim($matches[2]);
            }
        }

        return [$streetAddress, $cityProvince, $region, $stationType];
    }
}

$regionOptions = [];
$stationTypeOptions = [];
foreach ($stations as $station) {
    $locationText = trim((string)($station['location'] ?? ''));
    if ($locationText === '') {
        $locationText = (string)($station['name'] ?? '');
    }
    [, , $region, $stationType] = split_station_location_parts($locationText);
    $region = trim($region);
    $stationType = trim($stationType);
    if ($region !== '') {
        $regionOptions[$region] = true;
    }
    if ($stationType !== '') {
        $stationTypeOptions[$stationType] = true;
    }
}

include __DIR__ . '/../partials/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

.page-container {
    height: calc(100vh - 110px);
    display: flex;
    flex-direction: column;
    padding: 20px;
    overflow: hidden;
    font-family: 'Inter', 'Roboto', sans-serif;
}

.page-header {
    margin-bottom: 20px;
}

.page-title {
    font-size: 34px;
    font-weight: 800;
    letter-spacing: 0.5px;
    color: var(--text);
    margin: 0 0 10px 0;
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
    padding: 16px;
    background: var(--card);
    border-radius: 8px;
    border: 1px solid var(--line);
}

.filter-select, .filter-input {
    padding: 10px 14px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--card);
    font-size: 14px;
    min-height: 42px;
}

.filter-input {
    flex: 1;
    }

.table-container {
    flex: 1;
    overflow: auto;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--card);
}

.profiles-table {
    width: 100%;
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
    padding: 14px 18px;
    text-align: left;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
}

.profiles-table th {
    font-size: 13px;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.profiles-table tbody tr {
    transition: background-color 0.2s;
    min-height: 52px;
}

.profiles-table tbody tr:nth-child(odd) {
    background: rgba(0, 47, 108, 0.03);
}

.profiles-table tbody tr:hover {
    background-color: rgba(0, 47, 108, 0.05);
}

.col-code {
    font-weight: 700;
    white-space: nowrap;
}

.location-cell {
    max-width: 240px;
    word-break: break-word;
}

.status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    display: inline-block;
}

.status-active { background: #28A745; color: white; }
.status-inactive { background: #DC3545; color: white; }

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    width: 34px;
    height: 34px;
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

.action-btn.more { background: #6C757D; color: white; }
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
        <input type="text" class="filter-input" id="searchFilter" placeholder="Search station code, location, manager...">
        
        <select class="filter-select" id="statusFilter">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
        
        <select class="filter-select" id="regionFilter">
            <option value="">All Regions</option>
            <?php foreach(array_keys($regionOptions) as $region): ?>
                <option value="<?php echo htmlspecialchars($region); ?>"><?php echo htmlspecialchars($region); ?></option>
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

        <select class="filter-select" id="stationTypeFilter">
            <option value="">All Station Types</option>
            <?php foreach(array_keys($stationTypeOptions) as $stationType): ?>
                <option value="<?php echo htmlspecialchars($stationType); ?>"><?php echo htmlspecialchars($stationType); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="table-container">
        <table class="profiles-table">
            <thead>
                <tr>
                    <th>Station Code</th>
                    <th>Street Address</th>
                    <th>City/Province</th>
                    <th>Region</th>
                    <th>Station Type</th>
                    <th>Manager</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="profilesTableBody">
                <?php foreach($stations as $station): ?>
                <?php
                    $locationRaw = trim((string)($station['location'] ?? ''));
                    if ($locationRaw === '') {
                        $locationRaw = (string)($station['name'] ?? '');
                    }
                    [$streetAddress, $cityProvince, $region, $stationType] = split_station_location_parts($locationRaw);
                ?>
                <tr data-station="<?php echo htmlspecialchars($station['name']); ?>" 
                    data-status="<?php echo htmlspecialchars($station['status']); ?>" 
                    data-location="<?php echo htmlspecialchars($station['name']); ?>" 
                    data-region="<?php echo htmlspecialchars(trim($region)); ?>"
                    data-station-type="<?php echo htmlspecialchars(trim($stationType)); ?>"
                    data-location-raw="<?php echo htmlspecialchars($locationRaw); ?>"
                    data-manager="<?php echo htmlspecialchars($station['admin_name'] ?? ''); ?>"
                    data-code="<?php echo str_pad($station['id'], 4, '0', STR_PAD_LEFT); ?>">
                    <td class="col-code"><?php echo str_pad($station['id'], 4, '0', STR_PAD_LEFT); ?></td>
                    <td class="location-cell"><?php echo htmlspecialchars($streetAddress); ?></td>
                    <td class="location-cell"><?php echo htmlspecialchars($cityProvince); ?></td>
                    <td class="location-cell"><?php echo htmlspecialchars($region); ?></td>
                    <td class="location-cell"><?php echo htmlspecialchars($stationType); ?></td>
                    <td><?php echo htmlspecialchars($station['admin_name'] ?? 'Not Assigned'); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo htmlspecialchars($station['status']); ?>">
                            <?php 
                            echo strtoupper(htmlspecialchars($station['status'])); 
                            ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <?php if($isSuper): ?>
                            <button class="action-btn edit" onclick="editProfile(<?php echo $station['id']; ?>)" title="Edit Profile">
                                <i class="fas fa-pen"></i>
                            </button>
                            <?php endif; ?>
                            <button class="action-btn more" onclick="viewProfile(<?php echo $station['id']; ?>)" title="More Options">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
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
                    <label>Location <small style="color:#888">(short label, e.g. Carmen, CDO)</small></label>
                    <input type="text" id="editLocation" name="location">
                </div>
                <div class="form-group">
                    <label>Full Address <small style="color:#888">(printed on receipts)</small></label>
                    <input type="text" id="editAddress" name="address" placeholder="e.g. Vamenta Blvd., Carmen, Cagayan de Oro City, Misamis Oriental">
                </div>
                <div class="form-group">
                    <label>VAT TIN <small style="color:#888">(printed on receipts)</small></label>
                    <input type="text" id="editVatTin" name="vat_tin" placeholder="e.g. 236-002-207-0000">
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
document.getElementById('searchFilter').addEventListener('input', filterTable);
document.getElementById('statusFilter').addEventListener('change', filterTable);
document.getElementById('regionFilter').addEventListener('change', filterTable);
document.getElementById('managerFilter').addEventListener('change', filterTable);
document.getElementById('stationTypeFilter').addEventListener('change', filterTable);

function filterTable() {
    const searchFilter = document.getElementById('searchFilter').value.toLowerCase().trim();
    const statusFilter = document.getElementById('statusFilter').value;
    const regionFilter = document.getElementById('regionFilter').value;
    const managerFilter = document.getElementById('managerFilter').value;
    const stationTypeFilter = document.getElementById('stationTypeFilter').value;
    
    const rows = document.querySelectorAll('#profilesTableBody tr');

    const normalize = (value) => (value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    const normalizedRegionFilter = normalize(regionFilter);
    const normalizedStationTypeFilter = normalize(stationTypeFilter);
    
    rows.forEach(row => {
        const station = row.dataset.station;
        const status = row.dataset.status;
        const region = row.dataset.region;
        const stationType = row.dataset.stationType;
        const manager = row.dataset.manager;
        const code = row.dataset.code;
        const locationRaw = row.dataset.locationRaw;
        const haystack = `${code} ${station} ${locationRaw} ${manager} ${status}`.toLowerCase();
        const normalizedRegion = normalize(region);
        const normalizedStationType = normalize(stationType);
        const normalizedLocationRaw = normalize(locationRaw);
        
        const matchesStatus = !statusFilter || status === statusFilter;
        const matchesRegion = !regionFilter || normalizedRegion === normalizedRegionFilter || normalizedLocationRaw.includes(normalizedRegionFilter);
        const matchesManager = !managerFilter || manager === managerFilter;
        const matchesStationType = !stationTypeFilter || normalizedStationType === normalizedStationTypeFilter || normalizedLocationRaw.includes(normalizedStationTypeFilter);
        const matchesSearch = !searchFilter || haystack.includes(searchFilter);
        
        row.style.display = matchesStatus && matchesRegion && matchesManager && matchesStationType && matchesSearch ? '' : 'none';
    });
}

// Modal functions
function viewProfile(id) {
    const row = document.querySelector(`#profilesTableBody tr[data-code="${String(id).padStart(4, '0')}"]`);
    if (!row) return;
    
    // Mock data for demonstration - in real implementation, fetch from database
    document.getElementById('viewName').value = row.dataset.station;
    document.getElementById('viewCode').value = row.dataset.code;
    document.getElementById('viewLocation').value = row.dataset.locationRaw;
    document.getElementById('viewManager').value = row.dataset.manager || 'Not Assigned';
    document.getElementById('viewPhone').value = '+63 912 345 6789';
    document.getElementById('viewEmail').value = 'station' + id + '@petron.com';
    document.getElementById('viewOpeningHours').value = '24/7';
    document.getElementById('viewFuelTypes').value = 'Diesel, Turbo Diesel, XCS Plus, XTRA UNL, Kerosene';
    document.getElementById('viewNotes').value = 'Strategic location with high traffic volume. Modern facilities with convenience store.';
    
    document.getElementById('viewProfileModalTitle').textContent = `Profile - ${row.dataset.code}`;
    document.getElementById('viewProfileModal').style.display = 'block';
}

function editProfile(id) {
    const row = document.querySelector(`#profilesTableBody tr[data-code="${String(id).padStart(4, '0')}"]`);
    if (!row) return;
    
    document.getElementById('editProfileId').value = id;
    document.getElementById('editName').value = row.dataset.station;
    document.getElementById('editCode').value = row.dataset.code;
    document.getElementById('editLocation').value = row.dataset.locationRaw;
    document.getElementById('editManager').value = row.dataset.manager || '';

    // Fetch full station data from DB (address, vat_tin, phone, email, etc.)
    fetch(`?fetch_station_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.station) {
                const s = data.station;
                document.getElementById('editAddress').value       = s.address       || '';
                document.getElementById('editVatTin').value        = s.vat_tin        || '';
                document.getElementById('editPhone').value         = s.phone          || '';
                document.getElementById('editEmail').value         = s.email          || '';
                document.getElementById('editOpeningHours').value  = s.opening_hours  || '';
                document.getElementById('editFuelTypes').value     = s.fuel_types     || '';
                document.getElementById('editNotes').value         = s.notes          || '';
            }
        })
        .catch(() => {});
    
    document.getElementById('editProfileModalTitle').textContent = `Edit Profile - ${row.dataset.code}`;
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
