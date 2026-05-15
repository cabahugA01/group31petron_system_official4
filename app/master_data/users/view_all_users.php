<?php
$page_id = 'view_all_users';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../backend/rbac.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
require_permission(VIEW_ALL_USERS);

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
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'reset_password') {
            require_permission(RESET_PASSWORD);
            
            $user_id = $_POST['user_id'] ?? '';
            
            if (empty($user_id)) {
                $notice = 'User ID is required.';
            } else {
                try {
                    // Get user info
                    $user = $pdo->prepare("SELECT username, name, role FROM users WHERE id = ?");
                    $user->execute([$user_id]);
                    $userInfo = $user->fetch();
                    
                    if (!$userInfo) {
                        $notice = 'User not found.';
                    } else {
                        // Generate new password
                        $new_password = generateRandomPassword();
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        
                        // Update password
                        $stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 1, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$hashed_password, $user_id]);
                        
                        log_user_action('Password Reset', "Reset password for user '$userInfo[username]'");
                        $notice = "Password reset successfully. New password: $new_password";
                    }
                } catch (PDOException $e) {
                    $notice = "Error: " . $e->getMessage();
                }
            }
        } elseif ($action === 'update_status') {
            require_permission(DEACTIVATE_USER);
            
            $user_id = $_POST['user_id'] ?? '';
            $new_status = $_POST['new_status'] ?? 'inactive';
            $reason = trim($_POST['reason'] ?? '');
            
            if (empty($user_id)) {
                $notice = 'User ID is required.';
            } elseif (empty($reason)) {
                $notice = 'Reason is required for status change.';
            } elseif ($user_id == $me['id']) {
                $notice = 'You cannot change your own status.';
            } else {
                try {
                    // Get user info
                    $user = $pdo->prepare("SELECT username, name, status FROM users WHERE id = ?");
                    $user->execute([$user_id]);
                    $userInfo = $user->fetch();
                    
                    if (!$userInfo) {
                        $notice = 'User not found.';
                    } else {
                        // Update user status
                        $stmt = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$new_status, $user_id]);
                        
                        log_user_action('User Status Change', "Changed user '$userInfo[username]' status from '$userInfo[status]' to '$new_status'. Reason: $reason");
                        $notice = "User status updated successfully";
                    }
                } catch (PDOException $e) {
                    $notice = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

// --- FETCH USERS ---
$users = [];
try {
    $sql = "SELECT u.*, s.name as station_name 
            FROM users u 
            LEFT JOIN stations s ON u.station_id = s.id 
            ORDER BY u.name";
    $stmt = $pdo->query($sql);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $notice = "Database Error: " . $e->getMessage();
}

function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
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
    display: flex;
    justify-content: space-between;
    align-items: center;
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

.create-user-btn {
    background: var(--blue);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.create-user-btn:hover {
    background: #0056b3;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 47, 108, 0.3);
}

.content-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
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

.users-table {
    width: 100%;
    height: 100%;
    border-collapse: collapse;
}

.users-table thead {
    background: var(--blue);
    color: white;
    position: sticky;
    top: 0;
    z-index: 10;
}

.users-table th, .users-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--line);
}

.users-table tbody {
    overflow-y: auto;
    max-height: calc(100vh - 300px);
}

.users-table tbody tr {
    transition: background-color 0.2s;
    height: 48px;
}

.users-table tbody tr:hover {
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

.status-active::before {
    content: '✅';
}

.status-inactive { 
    background: #DC3545; 
    color: white; 
}

.status-inactive::before {
    content: '❌';
}

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
.action-btn.reset { background: #FFC107; color: #212529; }
.action-btn.activate { background: #28A745; color: white; }
.action-btn.deactivate { background: #DC3545; color: white; }

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

.modal-body {
    margin-bottom: 20px;
    line-height: 1.6;
}

.user-info {
    background: rgba(0, 47, 108, 0.05);
    border-radius: 8px;
    padding: 16px;
    margin: 16px 0;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.info-row:last-child {
    margin-bottom: 0;
}

.info-label {
    font-weight: 500;
    color: var(--muted);
}

.info-value {
    font-weight: 600;
    color: var(--text);
}

.status-comparison {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 16px 0;
    padding: 16px;
    background: #f8f9fa;
    border-radius: 8px;
}

.status-item {
    text-align: center;
    flex: 1;
}

.status-label {
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 4px;
}

.status-value {
    font-weight: 600;
}

.arrow {
    color: var(--blue);
    font-size: 20px;
    margin: 0 16px;
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

.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: var(--card);
    font-size: 14px;
    resize: vertical;
    min-height: 80px;
}

.modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
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

.error-message {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: 14px;
}

.success-message {
    background: #d1e7dd;
    color: #0f5132;
    border: 1px solid #badbcc;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: 14px;
}
</style>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">User Management</h1>
            <p class="page-subtitle">Comprehensive user management dashboard</p>
        </div>
        <a href="create_station_admin.php" class="create-user-btn">
            + Create User
        </a>
    </div>

    <div class="content-container">
        <?php if ($notice): ?>
            <div class="<?php echo strpos($notice, 'Error') !== false ? 'error-message' : 'success-message'; ?>">
                <?php echo htmlspecialchars($notice); ?>
            </div>
        <?php endif; ?>

        <div class="filters-section">
            <select class="filter-select" id="roleFilter">
                <option value="">All Roles</option>
                <option value="superadmin">Super Admin</option>
                <option value="admin">Admin</option>
                <option value="manager">Manager</option>
                <option value="staff">Staff</option>
            </select>
            
            <select class="filter-select" id="stationFilter">
                <option value="">All Stations</option>
                <?php 
                $stations = array_unique(array_filter(array_column($users, 'station_name')));
                foreach($stations as $station): ?>
                    <option value="<?php echo htmlspecialchars($station); ?>"><?php echo htmlspecialchars($station); ?></option>
                <?php endforeach; ?>
            </select>
            
            <select class="filter-select" id="statusFilter">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            
            <input type="text" class="filter-input" placeholder="🔍 Search" id="searchInput">
        </div>

        <div class="table-container">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Station</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <?php foreach($users as $user): ?>
                    <tr data-role="<?php echo htmlspecialchars($user['role']); ?>" 
                        data-station="<?php echo htmlspecialchars($user['station_name'] ?? ''); ?>" 
                        data-status="<?php echo htmlspecialchars($user['status']); ?>"
                        data-name="<?php echo htmlspecialchars($user['name']); ?>"
                        data-username="<?php echo htmlspecialchars($user['username']); ?>"
                        data-user-id="<?php echo $user['id']; ?>">
                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                        <td><?php echo ucfirst(htmlspecialchars($user['role'])); ?></td>
                        <td><?php echo htmlspecialchars($user['station_name'] ?? 'Head Office'); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo htmlspecialchars($user['status']); ?>">
                                <?php echo ucfirst(htmlspecialchars($user['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo isset($user['last_login']) && $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never'; ?></td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-btn view" onclick="viewUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>', '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['role']); ?>', '<?php echo htmlspecialchars($user['station_name'] ?? 'Head Office'); ?>')" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="action-btn reset" onclick="showResetModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>', '<?php echo htmlspecialchars($user['username']); ?>')" title="Reset Password">
                                    <i class="fas fa-key"></i>
                                </button>
                                <?php if ($user['status'] === 'active'): ?>
                                    <button class="action-btn deactivate" onclick="showStatusModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>', '<?php echo htmlspecialchars($user['status']); ?>', 'inactive')" title="Deactivate">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="action-btn activate" onclick="showStatusModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>', '<?php echo htmlspecialchars($user['status']); ?>', 'active')" title="Activate">
                                        <i class="fas fa-check"></i>
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
</div>

<!-- View User Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">User Details</h3>
        </div>
        <div class="modal-body">
            <div class="user-info">
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span class="info-value" id="viewUserName">-</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Username:</span>
                    <span class="info-value" id="viewUsername">-</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Role:</span>
                    <span class="info-value" id="viewRole">-</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Station:</span>
                    <span class="info-value" id="viewStation">-</span>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Reset password for [User Name]?</h3>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to generate a new password for this user?</p>
            
            <div class="user-info">
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span class="info-value" id="resetUserName">-</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Username:</span>
                    <span class="info-value" id="resetUsername">-</span>
                </div>
            </div>
            
            <p style="margin-top: 16px; font-size: 14px; color: var(--muted);">
                A new secure password will be generated. The user will be required to change it on next login.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('resetModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="confirmReset()">
                <i class="fas fa-key"></i> Generate New Password
            </button>
        </div>
    </div>
</div>

<!-- Status Change Modal -->
<div id="statusModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Change Status?</h3>
        </div>
        <div class="modal-body">
            <div class="user-info">
                <div class="info-row">
                    <span class="info-label">User:</span>
                    <span class="info-value" id="statusUserName">-</span>
                </div>
            </div>
            
            <div class="status-comparison">
                <div class="status-item">
                    <div class="status-label">Current Status</div>
                    <div class="status-value" id="currentStatus">-</div>
                </div>
                <div class="arrow">→</div>
                <div class="status-item">
                    <div class="status-label">New Status</div>
                    <div class="status-value" id="newStatus">-</div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="reason">Reason:</label>
                <textarea id="reason" name="reason" placeholder="Please provide a reason for this status change..." required></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('statusModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="confirmStatusChange()">
                <i class="fas fa-check"></i> Confirm
            </button>
        </div>
    </div>
</div>

<!-- Hidden Forms -->
<form id="resetForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" id="resetUserIdInput" name="user_id">
</form>

<form id="statusForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" id="statusUserIdInput" name="user_id">
    <input type="hidden" id="newStatusInput" name="new_status">
    <input type="hidden" id="reasonInput" name="reason">
</form>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<script>
let selectedUserId = null;

// Filter functionality
document.getElementById('roleFilter').addEventListener('change', filterTable);
document.getElementById('stationFilter').addEventListener('change', filterTable);
document.getElementById('statusFilter').addEventListener('change', filterTable);
document.getElementById('searchInput').addEventListener('input', filterTable);

function filterTable() {
    const roleFilter = document.getElementById('roleFilter').value;
    const stationFilter = document.getElementById('stationFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const searchInput = document.getElementById('searchInput').value.toLowerCase();
    
    const rows = document.querySelectorAll('#usersTableBody tr');
    
    rows.forEach(row => {
        const role = row.dataset.role;
        const station = row.dataset.station;
        const status = row.dataset.status;
        const name = row.dataset.name.toLowerCase();
        const username = row.dataset.username.toLowerCase();
        
        const matchesRole = !roleFilter || role === roleFilter;
        const matchesStation = !stationFilter || station === stationFilter;
        const matchesStatus = !statusFilter || status === statusFilter;
        const matchesSearch = !searchInput || name.includes(searchInput) || username.includes(searchInput);
        
        row.style.display = matchesRole && matchesStation && matchesStatus && matchesSearch ? '' : 'none';
    });
}

function viewUser(userId, userName, username, role, station) {
    document.getElementById('viewUserName').textContent = userName;
    document.getElementById('viewUsername').textContent = username;
    document.getElementById('viewRole').textContent = role.charAt(0).toUpperCase() + role.slice(1);
    document.getElementById('viewStation').textContent = station;
    
    document.getElementById('viewModal').style.display = 'block';
}

function showResetModal(userId, userName, username) {
    selectedUserId = userId;
    
    document.getElementById('resetModalTitle').textContent = `Reset password for ${userName}?`;
    document.getElementById('resetUserName').textContent = userName;
    document.getElementById('resetUsername').textContent = username;
    
    document.getElementById('resetModal').style.display = 'block';
}

function showStatusModal(userId, userName, currentStatus, newStatus) {
    selectedUserId = userId;
    
    document.getElementById('statusUserName').textContent = userName;
    document.getElementById('currentStatus').textContent = currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1);
    document.getElementById('newStatus').textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
    
    // Clear previous reason
    document.getElementById('reason').value = '';
    
    document.getElementById('statusModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function confirmReset() {
    if (!selectedUserId) return;
    
    // Set form values and submit
    document.getElementById('resetUserIdInput').value = selectedUserId;
    document.getElementById('resetForm').submit();
}

function confirmStatusChange() {
    const reason = document.getElementById('reason').value.trim();
    
    if (!reason) {
        showToast('Please provide a reason for the status change', 'error');
        return;
    }
    
    if (!selectedUserId) return;
    
    // Get new status from the modal
    const newStatus = document.getElementById('newStatus').textContent.toLowerCase();
    
    // Set form values and submit
    document.getElementById('statusUserIdInput').value = selectedUserId;
    document.getElementById('newStatusInput').value = newStatus;
    document.getElementById('reasonInput').value = reason;
    document.getElementById('statusForm').submit();
}

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

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
});

<?php if ($notice && strpos($notice, 'successfully') !== false): ?>
showToast('<?php echo htmlspecialchars($notice); ?>', 'success');
<?php endif; ?>
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
