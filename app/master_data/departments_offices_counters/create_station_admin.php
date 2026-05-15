<?php
$page_id = 'create_station_admin';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../backend/rbac.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
require_permission(CREATE_STATION_ADMIN);

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
        $notice = 'Only Super Admin can create station admins.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_station_admin') {
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $assigned_station = $_POST['assigned_station'] ?? '';
            $status = $_POST['status'] ?? 'active';
            
            if (empty($full_name) || empty($email) || empty($username) || empty($assigned_station)) {
                $notice = 'All required fields must be filled.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $notice = 'Invalid email address.';
            } elseif (strlen($username) < 3) {
                $notice = 'Username must be at least 3 characters long.';
            } else {
                try {
                    // Check if username already exists
                    $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $chk->execute([$username]);
                    
                    if ($chk->rowCount() > 0) {
                        $notice = "Username '$username' already exists.";
                    } else {
                        // Get station name
                        $station = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
                        $station->execute([$assigned_station]);
                        $station_name = $station->fetchColumn();
                        
                        if (!$station_name) {
                            $notice = 'Invalid station selected.';
                        } else {
                            // Generate default password
                            $temp_password = generateRandomPassword();
                            $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
                            
                            // Create station admin
                            $stmt = $pdo->prepare("INSERT INTO users (username, password, name, email, role, station_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->execute([$username, $hashed_password, $full_name, $email, 'admin', $assigned_station, $status]);
                            
                            log_user_action('Create Station Admin', "Created admin '$username' for station '$station_name'");
                            $notice = "Station Admin created successfully. Temporary Password: " . $temp_password;
                        }
                    }
                } catch (PDOException $e) {
                    $notice = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
}

// --- FETCH STATIONS ---
$stations = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'active' ORDER BY name");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

.form-container {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 20px 0;
}

.form-card {
    background: var(--card);
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 47, 108, 0.1);
    width: 100%;
    max-width: 800px;
    overflow: hidden;
}

.form-header {
    background: linear-gradient(135deg, var(--blue), #001a4d);
    color: white;
    padding: 24px;
    text-align: center;
}

.form-header h3 {
    margin: 0 0 8px 0;
    font-size: 20px;
    font-weight: 600;
}

.form-header p {
    margin: 0;
    opacity: 0.9;
    font-size: 14px;
}

.form-body {
    padding: 40px;
}

.form-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
}

.form-group {
    margin-bottom: 24px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text);
    font-size: 14px;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
    background: var(--card);
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1);
}

.form-group input::placeholder {
    color: #999;
}

.fixed-field {
    background: #f8f9fa !important;
    border-color: #dee2e6 !important;
    color: #6c757d !important;
    cursor: not-allowed;
}

.form-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid #e0e0e0;
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
}

.btn-primary {
    background: var(--blue);
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 47, 108, 0.3);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
    transform: translateY(-1px);
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
    max-width: 400px;
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

@media (max-width: 768px) {
    .form-columns {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .form-body {
        padding: 24px;
    }
    
    .form-footer {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">Create Station Admin</h1>
        <p class="page-subtitle">Create a new administrator account for a specific station</p>
    </div>

    <div class="form-container">
        <div class="form-card">
            <div class="form-header">
                <h3>Station Administrator Account</h3>
                <p>Fill in the details below to create a new station admin</p>
            </div>
            
            <form method="POST" class="form-body">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_station_admin">
                
                <?php if ($notice): ?>
                    <div class="<?php echo strpos($notice, 'Error') !== false || strpos($notice, 'Invalid') !== false ? 'error-message' : 'success-message'; ?>">
                        <?php echo htmlspecialchars($notice); ?>
                    </div>
                <?php endif; ?>
                
                <div class="form-columns">
                    <!-- Left Column -->
                    <div class="left-column">
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" required 
                                   placeholder="Enter full name" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" required 
                                   placeholder="admin@petron.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" required 
                                   placeholder="Enter username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <!-- Right Column -->
                    <div class="right-column">
                        <div class="form-group">
                            <label for="role">Role</label>
                            <input type="text" value="Station Admin" class="fixed-field" readonly>
                            <input type="hidden" name="role" value="admin">
                        </div>
                        
                        <div class="form-group">
                            <label for="assigned_station">Assigned Station</label>
                            <select id="assigned_station" name="assigned_station" required>
                                <option value="">Select a station</option>
                                <?php foreach($stations as $station): ?>
                                    <option value="<?php echo $station['id']; ?>" 
                                            <?php echo (($_POST['assigned_station'] ?? '') == $station['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($station['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="active" <?php echo (($_POST['status'] ?? '') === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo (($_POST['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-footer">
                    <button type="button" class="btn btn-secondary" onclick="history.back()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<script>
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

// Form validation
document.querySelector('form').addEventListener('submit', function(e) {
    const fullName = document.getElementById('full_name').value.trim();
    const email = document.getElementById('email').value.trim();
    const username = document.getElementById('username').value.trim();
    const assignedStation = document.getElementById('assigned_station').value;
    
    if (!fullName || !email || !username || !assignedStation) {
        e.preventDefault();
        showToast('Please fill in all required fields', 'error');
        return false;
    }
    
    if (username.length < 3) {
        e.preventDefault();
        showToast('Username must be at least 3 characters long', 'error');
        return false;
    }
    
    // Email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        e.preventDefault();
        showToast('Please enter a valid email address', 'error');
        return false;
    }
});

<?php if ($notice && strpos($notice, 'successfully') !== false): ?>
showToast('<?php echo htmlspecialchars($notice); ?>', 'success');
<?php endif; ?>
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
