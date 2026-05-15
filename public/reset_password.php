<?php
$page_id = 'reset_password';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../backend/rbac.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
require_permission(RESET_PASSWORD);

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
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $result = $stmt->execute([$hashed_password, $user_id]);
                        
                        // Set password expiry and must_change flag if columns exist
                        try {
                            $expires = (new DateTime("+90 days"))->format('Y-m-d H:i:s');
                            $pdo->prepare("UPDATE users SET password_expires_at = ?, must_change_password = 1 WHERE id = ?")
                                ->execute([$expires, $user_id]);
                        } catch(Exception $e){
                            // Columns don't exist, continue without them
                        }
                        
                        if ($result) {
                            log_user_action('Password Reset', "Reset password for user '$userInfo[username]'");
                            $notice = "Password reset successfully";
                        } else {
                            $notice = "Failed to update password";
                        }
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
    overflow-y: auto;
    overflow-x: hidden;
}

.page-header {
    margin-bottom: 30px;
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

.content-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    overflow-x: hidden;
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
    max-height: calc(100vh - 400px);
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

.status-inactive { 
    background: #DC3545; 
    color: white; 
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

.action-btn.reset { background: #DC3545; color: white; }

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
    padding: 24px;
    border-radius: 12px;
    width: 90%;
    max-width: 450px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}

.modal-header {
    margin-bottom: 24px;
    text-align: center;
}

.modal-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
    line-height: 1.4;
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

.modal-footer {
    display: flex;
    gap: 12px;
    justify-content: center;
    margin-top: 0;
}

.btn {
    padding: 12px 24px;
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
    min-width: 140px;
    justify-content: center;
}

.btn-primary {
    background: var(--blue);
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 47, 108, 0.3);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
}

.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 6px 12px;
    background: #28A745;
    color: white;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
    z-index: 9999;
    display: none;
    animation: slideIn 0.2s ease;
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

.password-display {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 12px;
    margin-top: 16px;
    font-family: monospace;
    font-size: 16px;
    text-align: center;
    color: var(--blue);
    font-weight: 600;
}
</style>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">Reset Password</h1>
        <p class="page-subtitle">Generate new passwords for user accounts</p>
    </div>

    <div class="content-container">
        <?php if ($notice): ?>
            <div class="<?php echo strpos($notice, 'Error') !== false ? 'error-message' : 'success-message'; ?>">
                <?php echo htmlspecialchars($notice); ?>
            </div>
        <?php endif; ?>

        <div class="filters-section">
            <select class="filter-select" id="roleFilter_reset">
                <option value="">All Roles</option>
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
            
            <input type="text" class="filter-input" placeholder="🔍 Search users..." id="searchInput">
        </div>

        <div class="table-container">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Station</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <?php foreach($users as $user): ?>
                    <tr data-role="<?php echo htmlspecialchars($user['role']); ?>" 
                        data-station="<?php echo htmlspecialchars($user['station_name'] ?? ''); ?>" 
                        data-status="<?php echo htmlspecialchars($user['status']); ?>"
                        data-name="<?php echo htmlspecialchars($user['name']); ?>"
                        data-username="<?php echo htmlspecialchars($user['username']); ?>">
                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo ucfirst(htmlspecialchars($user['role'])); ?></td>
                        <td><?php echo htmlspecialchars($user['station_name'] ?? 'Head Office'); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo htmlspecialchars($user['status']); ?>">
                                <?php echo ucfirst(htmlspecialchars($user['status'])); ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-btn reset" onclick="directResetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>', '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['role']); ?>')" title="Reset Password">
                                    <i class="fas fa-key"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="resetModalTitle">Reset password for [User Name]?</h3>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="confirmReset()">
                Generate New Password
            </button>
        </div>
    </div>
</div>

<!-- Hidden Form -->
<form id="resetForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" id="resetUserIdInput" name="user_id">
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

function directResetPassword(userId, userName, username, role) {
    console.log('Direct reset called for:', { userId, userName, username, role });
    
    // Don't show error for missing data, just return silently
    if (!userId || !userName) {
        console.log('Silently ignoring missing data:', { userId, userName });
        return;
    }
    
    // Show loading message
    showToast('Resetting password...', 'info');
    
    // Set form values and submit immediately
    const form = document.getElementById('resetForm');
    if (form) {
        const userIdInput = document.getElementById('resetUserIdInput');
        if (userIdInput) {
            userIdInput.value = userId;
            console.log('✅ Direct reset - Form user ID set to:', userId);
            
            // Submit the form
            form.submit();
        } else {
            console.log('Form input not found, but no error shown');
        }
    } else {
        console.log('Form not found, but no error shown');
    }
}

function closeModal() {
    document.getElementById('resetModal').style.display = 'none';
}

function confirmReset() {
    console.log('confirmReset called, selectedUserId:', selectedUserId);
    
    if (!selectedUserId) {
        console.error('❌ No user selected');
        showToast('No user selected', 'error');
        return;
    }
    
    // Set form values and submit
    const form = document.getElementById('resetForm');
    if (form) {
        const userIdInput = document.getElementById('resetUserIdInput');
        if (userIdInput) {
            userIdInput.value = selectedUserId;
            console.log('✅ Form user ID successfully set to:', selectedUserId);
            console.log('✅ Form validation passed, submitting...');
            
            // Submit the form immediately
            form.submit();
        } else {
            console.error('❌ resetUserIdInput not found');
            showToast('Form error: Missing user ID field', 'error');
        }
    } else {
        console.error('❌ resetForm not found');
        showToast('Form error: Missing form element', 'error');
    }
}

// Show toast notification
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    
    if (type === 'success') {
        toast.style.background = '#28A745'; // Green
    } else if (type === 'error') {
        toast.style.background = '#DC3545'; // Red
    } else if (type === 'info') {
        toast.style.background = '#17A2B8'; // Blue
    } else {
        toast.style.background = '#6C757D'; // Gray
    }
    
    toast.style.display = 'block';
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    
    // Auto hide after 1.5 seconds for smaller, quicker feel
    setTimeout(() => {
        toast.style.display = 'none';
    }, 1500);
}

// Test function to verify JavaScript is loaded
function testResetFunction() {
    console.log('Reset password function test - JavaScript is loaded');
    console.log('showResetModal function exists:', typeof showResetModal);
    console.log('confirmReset function exists:', typeof confirmReset);
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
    
    // Add direct click event listener to Generate New Password button
    const generateBtn = document.querySelector('.btn-primary[onclick="confirmReset()"]');
    if (generateBtn) {
        console.log('✅ Found Generate New Password button');
        generateBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('🔘 Generate New Password button clicked directly');
            confirmReset();
        });
    } else {
        console.log('⚠️ Generate New Password button not found');
    }
});

// Add click event listeners to all reset buttons as backup
const resetButtons = document.querySelectorAll('.action-btn.reset');
console.log('Found reset buttons:', resetButtons.length);

resetButtons.forEach((button, index) => {
    button.addEventListener('click', function(e) {
        e.preventDefault();
        console.log('Reset button clicked:', index);
        
        // Get user data from the row
        const row = this.closest('tr');
        if (row) {
            const userId = row.dataset.userId;
            const userName = row.dataset.name;
            const username = row.dataset.username;
            const role = row.dataset.role;
            
            console.log('User data:', { userId, userName, username, role });
            
            if (userId && userName) {
                showResetModal(userId, userName, username, role);
            } else {
                console.log('Silently ignoring missing user data');
            }
        }
    });
});

<?php if ($notice && strpos($notice, 'successfully') !== false): ?>
showToast('<?php echo htmlspecialchars($notice); ?>', 'success');
<?php endif; ?>
</script>

<script src="../assets/js/data_helper.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    DataHelper.populateRoles('roleFilter_reset', 'All Roles');
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
