<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../backend/rbac.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

// Check if user is superadmin or developer
$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'admin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

$notice = '';

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- ACTION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $notice = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'assign_admin') {
            $station_id = trim($_POST['station_id'] ?? '');
            $admin_id = trim($_POST['admin_id'] ?? '');
            
            // Station Access Mapping Validation
            if (empty($station_id) || empty($admin_id)) {
                $notice = 'Station and Admin are required for access mapping.';
            } elseif (!is_numeric($station_id) || $station_id <= 0) {
                $notice = 'Invalid station ID provided.';
            } elseif (!is_numeric($admin_id) || $admin_id <= 0) {
                $notice = 'Invalid admin ID provided.';
            } else {
                try {
                    $pdo->beginTransaction();
                    
                    // Validate station exists and is accessible
                    $stmt = $pdo->prepare("SELECT `user_id`, name, status FROM stations WHERE id = ?");
                    $stmt->execute([$station_id]);
                    $station = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$station) {
                        throw new Exception('Station not found. Cannot map access to non-existent station.');
                    }
                    
                    // Validate admin/owner exists and has proper role for station access
                    $stmt = $pdo->prepare("SELECT id, name, role, station_id as current_station, status FROM users WHERE user_id = ? AND role IN ('admin', 'owner') AND status = 'Active'");
                    $stmt->execute([$admin_id]);
                    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$admin) {
                        throw new Exception('Admin/Owner not found or inactive. Only active Admin/Owner accounts can be assigned station access.');
                    }
                    
                    // Check if admin is already assigned to this station
                    if ($admin['current_station'] == $station_id) {
                        throw new Exception('This admin is already assigned to this station.');
                    }
                    
                    // Get current admin assignment for this station (if any) for audit trail
                    $stmt = $pdo->prepare("SELECT `user_id`, name, role FROM users WHERE station_id = ? AND role IN ('admin', 'owner') AND status = 'Active'");
                    $stmt->execute([$station_id]);
                    $previous_admin = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Remove previous station access mapping (unassign previous admin)
                    $stmt = $pdo->prepare("UPDATE users SET station_id = NULL WHERE station_id = ? AND role IN ('admin', 'owner')");
                    $stmt->execute([$station_id]);
                    
                    // Create new station access mapping
                    $stmt = $pdo->prepare("UPDATE users SET station_id = ? WHERE user_id = ?");
                    $stmt->execute([$station_id, $admin_id]);
                    
                    // Update station status to active when access is mapped
                    $stmt = $pdo->prepare("UPDATE stations SET status = 'Active', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$station_id]);
                    
                    // Comprehensive audit logging for access mapping
                    $log_details = "Station Access Mapping: '{$admin['role']}' {$admin['name']} (ID: {$admin_id}) -> Station '{$station['name']}' (ID: {$station_id})";
                    if ($previous_admin) {
                        $log_details .= " | Previous access: '{$previous_admin['role']}' {$previous_admin['name']} (ID: {$previous_admin['id']})";
                    }
                    log_user_action('Station Access Mapping', $log_details);
                    
                    $pdo->commit();
                    $notice = "Station access mapping completed successfully. {$admin['role']} {$admin['name']} now has access to {$station['name']}.";
                    
                } catch (PDOException $e) {
                    $pdo->rollback();
                    $notice = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

// --- FETCH DATA ---
$stations = [];
$admins = [];

try {
    // Fetch stations with admin information
    $stmt = $pdo->query("
        SELECT s.*, 
               (SELECT u.name FROM users u WHERE u.station_id = s.id AND u.role IN ('admin', 'owner') AND u.status = 'Active' LIMIT 1) as admin_name,
               (SELECT u.role FROM users u WHERE u.station_id = s.id AND u.role IN ('admin', 'owner') AND u.status = 'Active' LIMIT 1) as admin_role,
               (SELECT u.id FROM users u WHERE u.station_id = s.id AND u.role IN ('admin', 'owner') AND u.status = 'Active' LIMIT 1) as admin_id,
               (SELECT COUNT(*) FROM users u WHERE u.station_id = s.id AND u.status = 'Active') as active_users
        FROM stations s 
        ORDER BY s.name
    ");
    $stations = $stmt->fetchAll();
    
    // Fetch available admins (not assigned to stations or can be reassigned)
    $stmt = $pdo->query("
        SELECT u.id, u.name, u.role, 
               CASE WHEN u.station_id IS NOT NULL THEN s.name ELSE 'Unassigned' END as current_station
        FROM users u 
        LEFT JOIN stations s ON u.station_id = s.id 
        WHERE u.role IN ('admin', 'owner') AND u.status = 'Active'
        ORDER BY u.name
    ");
    $admins = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $notice = "Database Error: " . $e->getMessage();
}

$page_id = 'station_assignment';
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
    align-items: center;
    margin-bottom: 20px;
    padding: 15px;
    background: var(--card);
    border-radius: 8px;
    border: 1px solid var(--line);
}

.search-input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--card);
    font-size: 14px;
    min-height: 42px;
    max-width: 400px;
    box-sizing: border-box;
}

.table-container {
    flex: 1;
    overflow-y: auto;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--card);
    max-height: 600px;
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

/* Column widths */
.stations-table th:nth-child(1), .stations-table td:nth-child(1) { width: 12%; }  /* Station Code */
.stations-table th:nth-child(2), .stations-table td:nth-child(2) { width: 25%; }  /* Location */
.stations-table th:nth-child(3), .stations-table td:nth-child(3) { width: 20%; }  /* Manager */
.stations-table th:nth-child(4), .stations-table td:nth-child(4) { width: 15%; }  /* Status */
.stations-table th:nth-child(5), .stations-table td:nth-child(5) { width: 28%; }  /* Action */

.stations-table tbody tr:hover {
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

.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 8px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    padding: 6px 10px;
    pointer-events: auto;
    z-index: 1;
    position: relative;
}

.action-btn:hover {
    transform: translateY(-1px);
}

.action-btn.view { background: #007BFF; color: white; }
.action-btn.assign { background: #28A745; color: white; }

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

.form-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--card);
    font-size: 14px;
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

.admin-info {
    font-size: 12px;
    color: #6c757d;
    margin-top: 4px;
}

.station-details {
    margin-bottom: 15px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 6px;
    border-left: 4px solid #007bff;
}

.station-details h4 {
    margin: 0 0 8px 0;
    color: #495057;
    font-size: 14px;
}

.station-details p {
    margin: 4px 0;
    font-size: 13px;
    color: #6c757d;
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1" style="font-weight: 800;">STATION ASSIGNMENT</h1>
        <div class="sub" style="font-weight: 500;">PROFESSIONAL STATION ACCESS MAPPING AND MANAGEMENT - SUPERADMIN/ADMIN/DEVELOPER</div>
    </div>
    <div class="actions">
        <button class="btn dark" onclick="location.reload()">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

    <div class="filters-section">
        <input type="text" class="search-input" placeholder="Search Station Code or Location (e.g., 'Vamenta')" id="searchInput">
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
                <tr data-code="<?php echo htmlspecialchars(str_pad($station['id'], 4, '0', STR_PAD_LEFT)); ?>" 
                    data-location="<?php echo htmlspecialchars($station['name']); ?>"
                    data-manager="<?php echo htmlspecialchars($station['admin_name'] ?? 'Not Assigned'); ?>">
                    <td><?php echo str_pad($station['id'], 4, '0', STR_PAD_LEFT); ?></td>
                    <td><?php echo htmlspecialchars($station['name']); ?></td>
                    <td>
                        <?php if ($station['admin_name']): ?>
                            <div>
                                <strong><?php echo htmlspecialchars($station['admin_name']); ?></strong>
                                <div class="admin-info">
                                    <?php echo htmlspecialchars(ucfirst($station['admin_role'] ?? '')); ?>
                                    <?php if ($station['active_users'] > 0): ?>
                                        | <?php echo $station['active_users']; ?> users
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <span style="color: #6c757d; font-style: italic;">Not Assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge status-<?php echo htmlspecialchars($station['status']); ?>">
                            <?php 
                            if ($station['status'] === 'active') {
                                echo 'Active';
                            } else {
                                echo 'Inactive';
                            }
                            ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="action-btn view" data-station-id="<?php echo $station['id']; ?>" title="View">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="action-btn assign" data-station-id="<?php echo $station['id']; ?>" title="Assign Admin">
                                <i class="fas fa-user-plus"></i>
                            </button>
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
            <h3 class="modal-title">Station Details</h3>
        </div>
        <div class="modal-body" id="viewModalBody">
            <!-- Content will be populated dynamically -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- Assign Admin Modal -->
<div id="assignModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Map Station Access</h3>
        </div>
        <form id="assignForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="assign_admin">
            <input type="hidden" id="assignStationId" name="station_id">
            
            <div class="station-details" id="stationDetails">
                <!-- Station details will be populated here -->
            </div>
            
            <div class="form-group">
                <label>Select Admin/Owner for Station Access <span style="color: red;">*</span></label>
                <select name="admin_id" id="adminSelect" required>
                    <option value="">-- Select Admin/Owner for Access Mapping --</option>
                    <?php foreach($admins as $admin): ?>
                        <option value="<?php echo $admin['id']; ?>">
                            <?php echo htmlspecialchars($admin['name']); ?> (<?php echo htmlspecialchars(ucfirst($admin['role'])); ?>)
                            <?php if ($admin['current_station'] !== 'Unassigned'): ?>
                                - Current Access: <?php echo htmlspecialchars($admin['current_station']); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #6c757d; font-size: 12px;">
                    <strong>Station Access Mapping:</strong> This will map station access to the selected Admin/Owner and remove access from previous station (if any).
                </small>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Map Station Access</button>
            </div>
        </form>
    </div>
</div>


<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<script>
// Add event listeners for action buttons
document.addEventListener('DOMContentLoaded', function() {
    // Search functionality - only if element exists
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', filterTable);
    }
    
    // View button listeners
    const viewButtons = document.querySelectorAll('.action-btn.view');
    viewButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const stationId = this.getAttribute('data-station-id');
            if (stationId) {
                viewStation(stationId);
            }
        });
    });
    
    // Assign button listeners
    const assignButtons = document.querySelectorAll('.action-btn.assign');
    assignButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const stationId = this.getAttribute('data-station-id');
            if (stationId) {
                assignAdmin(stationId);
            }
        });
    });
});


function filterTable() {
    const searchInput = document.getElementById('searchInput');
    if (!searchInput) return;
    
    const searchValue = searchInput.value.toLowerCase();
    const rows = document.querySelectorAll('#stationsTableBody tr');
    
    if (!rows) return;
    
    rows.forEach(row => {
        try {
            const code = (row.dataset.code || '').toLowerCase();
            const location = (row.dataset.location || '').toLowerCase();
            const manager = (row.dataset.manager || '').toLowerCase();
            
            const matchesSearch = !searchValue || 
                                code.includes(searchValue) || 
                                location.includes(searchValue) || 
                                manager.includes(searchValue);
            
            row.style.display = matchesSearch ? '' : 'none';
        } catch (error) {
            // Skip row if there's an error
            row.style.display = '';
        }
    });
}

// Modal functions
function viewStation(id) {
    console.log('View Station called with ID:', id);
    try {
        const button = document.querySelector(`button[data-station-id="${id}"].view`);
        if (!button) {
            console.error('View button not found for ID:', id);
            return;
        }
        
        const row = button.closest('tr');
        if (!row) {
            console.error('Row not found for button');
            return;
        }
        
        const stationCode = row.cells[0] ? row.cells[0].textContent.trim() : '';
        const location = row.cells[1] ? row.cells[1].textContent.trim() : '';
        const managerCell = row.cells[2];
        const statusCell = row.cells[3];
        
        let managerHtml = '';
        if (managerCell && managerCell.textContent.trim() !== 'Not Assigned') {
            managerHtml = managerCell.innerHTML;
        } else {
            managerHtml = '<span style="color: #6c757d; font-style: italic;">No Admin/Owner assigned</span>';
        }
        
        const viewModalBody = document.getElementById('viewModalBody');
        const viewModalTitle = document.querySelector('.modal-title');
        const viewModal = document.getElementById('viewModal');
        
        if (!viewModalBody || !viewModalTitle || !viewModal) {
            console.error('Modal elements not found');
            return;
        }
        
        viewModalBody.innerHTML = `
            <div class="station-details">
                <h4>Station Information</h4>
                <p><strong>Code:</strong> ${stationCode}</p>
                <p><strong>Location:</strong> ${location}</p>
                <p><strong>Status:</strong> ${statusCell ? statusCell.innerHTML : ''}</p>
            </div>
            <div class="station-details">
                <h4>Current Assignment</h4>
                ${managerHtml}
            </div>
        `;
        
        viewModalTitle.textContent = `Station Details - ${stationCode}`;
        viewModal.style.display = 'block';
        console.log('View modal opened successfully');
    } catch (error) {
        console.error('Error in viewStation:', error);
        showToast('Error viewing station details', 'error');
    }
}

function assignAdmin(id) {
    console.log('Assign Admin called with ID:', id);
    try {
        const button = document.querySelector(`button[data-station-id="${id}"].assign`);
        if (!button) {
            console.error('Assign button not found for ID:', id);
            return;
        }
        
        const row = button.closest('tr');
        if (!row) {
            console.error('Row not found for button');
            return;
        }
        
        const stationCode = row.cells[0] ? row.cells[0].textContent.trim() : '';
        const location = row.cells[1] ? row.cells[1].textContent.trim() : '';
        const currentManager = row.cells[2] ? row.cells[2].textContent.trim() : '';
        
        const assignStationId = document.getElementById('assignStationId');
        const stationDetails = document.getElementById('stationDetails');
        const assignModalTitle = document.querySelector('#assignModal .modal-title');
        const assignModal = document.getElementById('assignModal');
        const adminSelect = document.getElementById('adminSelect');
        
        if (!assignStationId || !stationDetails || !assignModalTitle || !assignModal || !adminSelect) {
            console.error('Assign modal elements not found');
            return;
        }
        
        assignStationId.value = id;
        stationDetails.innerHTML = `
            <h4>Station Access Mapping: ${stationCode} - ${location}</h4>
            <p><strong>Current Access:</strong> ${currentManager}</p>
        `;
        
        // Reset admin selection
        adminSelect.value = '';
        
        assignModalTitle.textContent = `Map Access to ${stationCode}`;
        assignModal.style.display = 'block';
        console.log('Assign modal opened successfully');
    } catch (error) {
        console.error('Error in assignAdmin:', error);
        showToast('Error opening admin assignment', 'error');
    }
}


function closeModal(modalId) {
    console.log('Closing modal:', modalId);
    try {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
            console.log('Modal closed successfully');
        } else {
            console.error('Modal not found:', modalId);
        }
    } catch (error) {
        console.error('Error closing modal:', error);
    }
}

// Add click outside to close functionality
document.addEventListener('DOMContentLoaded', function() {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal(modal.id);
            }
        });
    });
    
    // Add ESC key to close modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const openModal = document.querySelector('.modal[style*="block"]');
            if (openModal) {
                closeModal(openModal.id);
            }
        }
    });
});

// Form submissions
document.addEventListener('DOMContentLoaded', function() {
    const assignForm = document.getElementById('assignForm');
    if (assignForm) {
        assignForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.textContent = 'Mapping Access...';
            
            const formData = new FormData(this);
            console.log('Submitting form with data:', Object.fromEntries(formData));
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.text();
            })
            .then(html => {
                console.log('Form submission successful');
                closeModal('assignModal');
                showToast('Station access mapped successfully!', 'success');
                setTimeout(() => location.reload(), 1500);
            })
            .catch(error => {
                console.error('Form submission error:', error);
                showToast('Error mapping station access. Please try again.', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            });
        });
    }
});

// Toast notification
function showToast(message, type = 'success') {
    if (window.showPetronFlash) {
        window.showPetronFlash(message, type);
        return;
    }
    console.log('Showing toast:', message, type);
    try {
        const toast = document.getElementById('toast');
        if (!toast) {
            console.error('Toast element not found');
            return;
        }
        
        toast.textContent = message;
        toast.className = 'toast show';
        
        if (type === 'success') {
            toast.style.background = '#28A745';
        } else if (type === 'error') {
            toast.style.background = '#DC3545';
        } else {
            toast.style.background = '#007BFF';
        }
        
        toast.style.display = 'block';
        
        // Auto-dismiss after 4 seconds
        setTimeout(() => {
            toast.className = 'toast';
            setTimeout(() => {
                toast.style.display = 'none';
            }, 300);
        }, 4000);
        
        console.log('Toast displayed successfully');
    } catch (error) {
        console.error('Error showing toast:', error);
    }
}

<?php if ($notice): ?>
showToast('<?php echo htmlspecialchars($notice); ?>', '<?php echo strpos($notice, 'Error') !== false ? 'error' : 'success'; ?>');
<?php endif; ?>
</script>


<?php include __DIR__ . '/../partials/footer.php'; ?>
