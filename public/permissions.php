<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

// Only allow superadmin
$me = current_user();
$role = role_key($me['role'] ?? 'staff');

if (!in_array($role, ['superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$page_id = 'permissions';
require_once __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Permissions Management</h1>
        <div class="sub">System Access Control and Role Management</div>
    </div>
</div>

<style>
.permissions-container {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 30px;
}

.roles-section, .permissions-section {
    background: var(--card);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    border: 1px solid var(--border);
}

.role-item, .permission-item {
    background: #f8f9fa;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
}

.role-item:hover, .permission-item:hover {
    background: #e9ecef;
    transform: translateY(-1px);
}

.role-name {
    font-weight: 600;
    color: var(--text);
}

.role-description {
    color: var(--muted);
    font-size: 14px;
}

.role-actions {
    display: flex;
    gap: 8px;
}

.permission-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}

.permission-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 20px;
    transition: all 0.3s ease;
}

.permission-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.permission-name {
    font-weight: 600;
    color: var(--petron-blue);
    margin-bottom: 8px;
}

.permission-description {
    color: var(--muted);
    font-size: 14px;
    margin-bottom: 16px;
    line-height: 1.5;
}

.permission-status {
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.status-enabled {
    background: #10b981;
    color: white;
}

.status-disabled {
    background: #6b7280;
    color: white;
}

.toggle-switch {
    position: relative;
    width: 44px;
    height: 24px;
    background: #cbd5e1;
    border-radius: 12px;
    cursor: pointer;
    transition: background-color 0.3s;
}

.toggle-switch.active {
    background: var(--petron-blue);
}

.toggle-switch::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 20px;
    height: 20px;
    background: white;
    border-radius: 50%;
    transition: transform 0.3s;
}

.toggle-switch.active::after {
    transform: translateX(20px);
}

.role-users {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}

.user-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.user-chip {
    background: var(--petron-blue);
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.modal-content {
    background: var(--card);
    border-radius: 12px;
    padding: 32px;
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.modal-title {
    font-size: 20px;
    font-weight: 600;
    color: var(--text);
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--muted);
}

.modal-body {
    margin-bottom: 24px;
}

.modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text);
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--petron-blue);
    box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1);
}

.btn {
    padding: 12px 20px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.btn-primary {
    background: var(--petron-blue);
    color: white;
    border-color: var(--petron-blue);
}

.btn-secondary {
    background: var(--muted);
    color: var(--text);
    border-color: var(--muted);
}

.btn-danger {
    background: var(--petron-red);
    color: white;
    border-color: var(--petron-red);
}

.btn-success {
    background: #10b981;
    color: white;
    border-color: #10b981;
}

.alert {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-success {
    background: #10b981;
    color: white;
}

.alert-danger {
    background: var(--petron-red);
    color: white;
}

.alert-warning {
    background: #f59e0b;
    color: #000;
}
</style>

<div class="permissions-container">
    <!-- Roles Section -->
    <div class="roles-section">
        <h2><i class="fas fa-user-shield"></i> System Roles</h2>
        <p>Manage system roles and their associated permissions. Each role has specific access levels to different system features.</p>
        
        <?php
        // Get roles and their permissions
        $roles = [
            'superadmin' => [
                'ALL_ACCESS',
                'USER_MANAGEMENT',
                'SYSTEM_ADMINISTRATION',
                'DATABASE_MANAGEMENT',
                'REPORTS_ACCESS',
                'STATION_MANAGEMENT',
                'INVENTORY_MANAGEMENT',
                'JOB_ORDER_MANAGEMENT',
                'SALES_MANAGEMENT',
                'FINANCIAL_REPORTS'
            ],
            'admin' => [
                'USER_MANAGEMENT',
                'STATION_MANAGEMENT',
                'INVENTORY_MANAGEMENT',
                'JOB_ORDER_MANAGEMENT',
                'SALES_MANAGEMENT',
                'REPORTS_ACCESS',
                'FINANCIAL_REPORTS'
            ],
            'manager' => [
                'STATION_MANAGEMENT',
                'INVENTORY_MANAGEMENT',
                'JOB_ORDER_MANAGEMENT',
                'SALES_MANAGEMENT',
                'REPORTS_ACCESS'
            ],
            'staff' => [
                'INVENTORY_MANAGEMENT',
                'JOB_ORDER_MANAGEMENT',
                'SALES_MANAGEMENT'
            ],
            'operations' => [
                'INVENTORY_MANAGEMENT',
                'JOB_ORDER_MANAGEMENT',
                'SALES_MANAGEMENT'
            ]
        ];
        
        foreach ($roles as $role => $permissions) {
            echo '<div class="role-item">';
            echo '<div>';
            echo '<div class="role-name">' . ucfirst($role) . '</div>';
            echo '<div class="role-description">' . implode(', ', array_map('ucfirst', $permissions)) . '</div>';
            echo '<div class="role-actions">';
            echo '<button class="btn btn-secondary" onclick="editRole(\'' . $role . '\')">Edit</button>';
            echo '<button class="btn btn-primary" onclick="viewRoleUsers(\'' . $role . '\')">View Users</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        ?>
    </div>

    <!-- Permissions Section -->
    <div class="permissions-section">
        <h2><i class="fas fa-key"></i> Permission Matrix</h2>
        <p>View and manage specific permissions for each role. Toggle permissions to control access to system features.</p>
        
        <div class="permission-grid">
            <?php
            $all_permissions = [
                'ALL_ACCESS' => 'Full system access',
                'USER_MANAGEMENT' => 'Manage users and roles',
                'SYSTEM_ADMINISTRATION' => 'System configuration',
                'DATABASE_MANAGEMENT' => 'Database operations',
                'REPORTS_ACCESS' => 'View and generate reports',
                'STATION_MANAGEMENT' => 'Manage station settings',
                'INVENTORY_MANAGEMENT' => 'Inventory tracking and adjustments',
                'JOB_ORDER_MANAGEMENT' => 'Job order processing',
                'SALES_MANAGEMENT' => 'Sales transaction processing',
                'FINANCIAL_REPORTS' => 'Financial data access'
            ];
            
            foreach ($all_permissions as $permission => $description) {
                echo '<div class="permission-card">';
                echo '<div class="permission-name">' . str_replace('_', ' ', $permission) . '</div>';
                echo '<div class="permission-description">' . $description . '</div>';
                echo '<div class="permission-status">';
                echo '<div class="status-badge status-enabled">Enabled</div>';
                echo '<div class="toggle-switch active" onclick="togglePermission(\'' . $permission . '\')"></div>';
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>
    </div>
</div>

<!-- Edit Role Modal -->
<div id="editRoleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Edit Role</h3>
            <button class="modal-close" onclick="closeModal('editRoleModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editRoleForm">
                <div class="form-group">
                    <label>Role Name</label>
                    <input type="text" name="role_name" id="edit_role_name" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_description" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label>Permissions</label>
                    <div id="edit_permissions_container">
                        <!-- Permissions will be loaded here -->
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('editRoleModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveRole()">Save Role</button>
        </div>
    </div>
</div>

<!-- View Role Users Modal -->
<div id="viewUsersModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Role Users</h3>
            <button class="modal-close" onclick="closeModal('viewUsersModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="roleUsersList">
                <!-- Users will be loaded here -->
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('viewUsersModal')">Close</button>
        </div>
    </div>
</div>

<script>
// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Permission toggle
function togglePermission(permission) {
    const toggle = event.target;
    toggle.classList.toggle('active');
    
    const isEnabled = toggle.classList.contains('active');
    
    // In real implementation, this would update the database
    console.log('Permission ' + permission + (isEnabled ? 'enabled' : 'disabled'));
    
    showToast('Permission ' + permission + ' ' + (isEnabled ? 'enabled' : 'disabled'), 'info');
}

// Edit Role
function editRole(role) {
    openModal('editRoleModal');
    
    // Load role data
    const roleData = {
        'superadmin': 'Super Administrator',
        'admin': 'Administrator',
        'manager': 'Station Manager',
        'staff': 'Station Staff',
        'operations': 'Operations Staff'
    };
    
    document.getElementById('edit_role_name').value = roleData[role] || role;
    document.getElementById('edit_description').value = 'Role description for ' + (roleData[role] || role);
    
    // Load permissions
    const permissions = [
        'ALL_ACCESS', 'USER_MANAGEMENT', 'SYSTEM_ADMINISTRATION', 'DATABASE_MANAGEMENT', 
        'REPORTS_ACCESS', 'STATION_MANAGEMENT', 'INVENTORY_MANAGEMENT', 'JOB_ORDER_MANAGEMENT', 
        'SALES_MANAGEMENT', 'FINANCIAL_REPORTS'
    ];
    
    const container = document.getElementById('edit_permissions_container');
    container.innerHTML = '';
    
    permissions.forEach(permission => {
        const div = document.createElement('div');
        div.style.marginBottom = '8px';
        div.innerHTML = '<label style="display: flex; align-items: center; gap: 8px;"><input type="checkbox" name="permissions[]" value="' + permission + '" ' + (permission === 'ALL_ACCESS' ? 'checked' : '') + '><span>' + permission.replace(/_/g, ' ') + '</span></label>';
        container.appendChild(div);
    });
}

function saveRole() {
    const form = document.getElementById('editRoleForm');
    const formData = new FormData(form);
    
    // In real implementation, this would save to database
    showToast('Saving role...', 'info');
    
    setTimeout(() => {
        showToast('Role saved successfully', 'success');
        closeModal('editRoleModal');
    }, 1500);
}

// View Role Users
function viewRoleUsers(role) {
    openModal('viewUsersModal');
    
    // Load users for this role
    const userList = document.getElementById('roleUsersList');
    userList.innerHTML = '<div class="alert alert-info">Loading users...</div>';
    
    // Simulate loading users (in real implementation, fetch from database)
    setTimeout(() => {
        const users = [
            {id: 1, name: 'John Doe', username: 'johndoe', email: 'john@example.com'},
            {id: 2, name: 'Jane Smith', username: 'janesmith', email: 'jane@example.com'},
            {id: 3, name: 'Bob Johnson', username: 'bobjohnson', email: 'bob@example.com'}
        ];
        
        let html = '<div class="user-list">';
        users.forEach(user => {
            html += '<div class="user-chip">' + user.name + ' (' + user.username + ')</div>';
        });
        html += '</div>';
        
        userList.innerHTML = html;
    }, 1000);
}

// Toast notification function
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    
    if (type === 'success') {
        toast.style.background = '#10b981';
    } else if (type === 'error' || type === 'danger') {
        toast.style.background = 'var(--petron-red)';
    } else if (type === 'warning') {
        toast.style.background = '#f59e0b';
        toast.style.color = '#000';
    } else {
        toast.style.background = '#007bff';
    }
    
    toast.style.color = type === 'warning' ? '#000' : 'white';
    toast.style.padding = '12px 20px';
    toast.style.borderRadius = '8px';
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.zIndex = '10000';
    toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
