<?php
$page_id = 'station_status';
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
        $notice = 'Only Super Admin can modify station status.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_status') {
            $station_id = $_POST['station_id'] ?? '';
            $new_status = $_POST['new_status'] ?? 'active';
            $reason = trim($_POST['reason'] ?? '');
            
            if (empty($reason)) {
                $notice = 'Reason is required for status change.';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE stations SET status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_status, $station_id]);
                    $station_name = $pdo->query("SELECT name FROM stations WHERE id = '$station_id'")->fetchColumn();
                    log_user_action('Station Status Change', "Changed station '$station_name' status to $new_status. Reason: $reason");
                    $notice = "Station status updated successfully.";
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
                       (SELECT u.name FROM users u WHERE u.station_id = s.id AND u.role = 'admin' LIMIT 1) as admin_name,
                       (SELECT SUM(i.stock_level) FROM station_inventory i 
                        JOIN products p ON i.product_id = p.id 
                        JOIN product_types pt ON p.type_id = pt.id 
                        WHERE i.station_id = s.id AND pt.name = 'fuel') as fuel_level
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

.dashboard-container {
    flex: 1;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--card);
}

.status-table {
    width: 100%;
    height: 100%;
    border-collapse: collapse;
}

.status-table thead {
    background: var(--blue);
    color: white;
    position: sticky;
    top: 0;
    z-index: 10;
}

.status-table th, .status-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--line);
}

.status-table tbody {
    overflow-y: auto;
    max-height: calc(100vh - 300px);
}

.status-table tbody tr {
    transition: background-color 0.2s;
    height: 48px;
}

.status-table tbody tr:hover {
    background-color: rgba(0, 47, 108, 0.05);
}

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.status-active { 
    background: #28A745; 
    color: white; 
}

.status-inactive { 
    background: #DC3545; 
    color: white; 
}

.status-maintenance { 
    background: #FFC107; 
    color: #212529; 
}

.fuel-level-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
}

.fuel-bar {
    width: 60px;
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
}

.fuel-fill {
    height: 100%;
    background: linear-gradient(90deg, #28A745, #20c997);
    transition: width 0.3s ease;
}

.fuel-text {
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s;
}

.action-btn:hover {
    transform: translateY(-1px);
}

.action-btn.update {
    background: var(--blue);
    color: white;
}

.action-btn.update:hover {
    background: #0056b3;
}

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

.current-status {
    padding: 12px;
    background: rgba(0, 47, 108, 0.05);
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid var(--blue);
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

.form-group select, .form-group textarea {
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

.status-icon {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}

.status-active .status-icon {
    background: white;
}

.status-inactive .status-icon {
    background: white;
}

.status-maintenance .status-icon {
    background: #212529;
}
</style>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">STATION STATUS</h1>
        <p class="page-subtitle">Real-time operational information and monitoring</p>
    </div>

    <div class="filters-section">
        <select class="filter-select" id="statusFilter">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="maintenance">Maintenance</option>
            <option value="inactive">Inactive</option>
        </select>
        
        <select class="filter-select" id="locationFilter">
            <option value="">All Locations</option>
            <?php 
            $locations = array_unique(array_filter(array_column($stations, 'location')));
            foreach($locations as $location): ?>
                <option value="<?php echo htmlspecialchars($location); ?>"><?php echo htmlspecialchars($location); ?></option>
            <?php endforeach; ?>
        </select>
        
        <select class="filter-select" id="fuelFilter">
            <option value="">All Fuel Levels</option>
            <option value="high">High (>75%)</option>
            <option value="medium">Medium (25-75%)</option>
            <option value="low">Low (<25%)</option>
        </select>
    </div>

    <div class="dashboard-container">
        <table class="status-table">
            <thead>
                <tr>
                    <th>Station Name</th>
                    <th>Status</th>
                    <th>Last Updated</th>
                    <th>Fuel Level</th>
                    <th>Staff On Duty</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="statusTableBody">
                <?php foreach($stations as $station): 
                    $fuelLevel = $station['fuel_level'] ?? 0;
                    $fuelPercentage = $fuelLevel > 0 ? min(($fuelLevel / 10000) * 100, 100) : 0;
                    $fuelCategory = $fuelPercentage > 75 ? 'high' : ($fuelPercentage > 25 ? 'medium' : 'low');
                ?>
                <tr data-status="<?php echo htmlspecialchars($station['status']); ?>" 
                    data-location="<?php echo htmlspecialchars($station['location'] ?? ''); ?>" 
                    data-fuel="<?php echo $fuelCategory; ?>">
                    <td><?php echo htmlspecialchars($station['name']); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo htmlspecialchars($station['status']); ?>">
                            <span class="status-icon"></span>
                            <?php 
                            $statusIcon = $station['status'] === 'active' ? '✅' : 
                                         ($station['status'] === 'maintenance' ? '⚠️' : '❌');
                            echo $statusIcon . ' ' . ucfirst(htmlspecialchars($station['status'])); 
                            ?>
                        </span>
                    </td>
                    <td><?php echo date('M d, Y H:i', strtotime($station['updated_at'] ?? $station['created_at'])); ?></td>
                    <td>
                        <div class="fuel-level-indicator">
                            <div class="fuel-bar">
                                <div class="fuel-fill" style="width: <?php echo $fuelPercentage; ?>%"></div>
                            </div>
                            <span class="fuel-text"><?php echo round($fuelPercentage); ?>%</span>
                        </div>
                    </td>
                    <td><?php echo $station['active_users']; ?> staff</td>
                    <td>
                        <div class="action-buttons">
                            <button class="action-btn update" onclick="updateStatus(<?php echo $station['id']; ?>, '<?php echo htmlspecialchars($station['status']); ?>', '<?php echo htmlspecialchars($station['name']); ?>')">
                                Update Status
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Update Status Modal -->
<div id="updateStatusModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="updateStatusModalTitle">Update Station Status</h3>
        </div>
        <form id="updateStatusForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" id="updateStationId" name="station_id">
            
            <div class="current-status">
                <strong>Current Status:</strong> 
                <span id="currentStatusDisplay" class="status-badge status-active">
                    <span class="status-icon"></span>
                    Active
                </span>
            </div>
            
            <div class="form-group">
                <label for="newStatus">New Status</label>
                <select id="newStatus" name="new_status" required>
                    <option value="active">Active ✅</option>
                    <option value="maintenance">Maintenance ⚠️</option>
                    <option value="inactive">Inactive ❌</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="reason">Reason for Change</label>
                <textarea id="reason" name="reason" placeholder="Please provide a reason for this status change..." required></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Confirm</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('updateStatusModal')">Cancel</button>
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
document.getElementById('fuelFilter').addEventListener('change', filterTable);

function filterTable() {
    const statusFilter = document.getElementById('statusFilter').value;
    const locationFilter = document.getElementById('locationFilter').value;
    const fuelFilter = document.getElementById('fuelFilter').value;
    
    const rows = document.querySelectorAll('#statusTableBody tr');
    
    rows.forEach(row => {
        const status = row.dataset.status;
        const location = row.dataset.location;
        const fuel = row.dataset.fuel;
        
        const matchesStatus = !statusFilter || status === statusFilter;
        const matchesLocation = !locationFilter || location === locationFilter;
        const matchesFuel = !fuelFilter || fuel === fuelFilter;
        
        row.style.display = matchesStatus && matchesLocation && matchesFuel ? '' : 'none';
    });
}

// Modal functions
function updateStatus(id, currentStatus, stationName) {
    document.getElementById('updateStationId').value = id;
    document.getElementById('updateStatusModalTitle').textContent = `Update Status - ${stationName}`;
    
    // Update current status display
    const statusDisplay = document.getElementById('currentStatusDisplay');
    statusDisplay.className = `status-badge status-${currentStatus}`;
    
    let statusIcon = '';
    if (currentStatus === 'active') {
        statusIcon = '✅ Active';
    } else if (currentStatus === 'maintenance') {
        statusIcon = '⚠️ Maintenance';
    } else {
        statusIcon = '❌ Inactive';
    }
    
    statusDisplay.innerHTML = `<span class="status-icon"></span>${statusIcon}`;
    
    // Set new status dropdown to current status
    document.getElementById('newStatus').value = currentStatus;
    
    // Clear reason field
    document.getElementById('reason').value = '';
    
    document.getElementById('updateStatusModal').style.display = 'block';
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
document.getElementById('updateStatusForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const newStatus = formData.get('new_status');
    const currentStatus = document.getElementById('currentStatusDisplay').textContent.trim();
    
    if (newStatus === currentStatus.toLowerCase().split(' ')[1]) {
        showToast('Status is already set to ' + currentStatus, 'error');
        return;
    }
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(() => {
        closeModal('updateStatusModal');
        showToast('Station status updated successfully');
        setTimeout(() => location.reload(), 1000);
    })
    .catch(error => {
        showToast('Error updating station status', 'error');
    });
});

<?php if ($notice): ?>
showToast('<?php echo htmlspecialchars($notice); ?>', '<?php echo strpos($notice, 'Error') !== false ? 'error' : 'success'; ?>');
<?php endif; ?>
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
