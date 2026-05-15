<?php
$page_id = 'users';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/station_management.php';
require_once __DIR__ . '/../config/email_config.php';
require_login();

$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

// Access Control: Station-scoped user management for Staff/Manager/Admin
// SuperAdmin uses superadmin_admin_management.php instead
if ($my_role === 'superadmin') {
    header("Location: superadmin_admin_management.php");
    exit;
}
if (!in_array($my_role, ['staff', 'manager', 'admin'], true)) {
    header("Location: dashboard.php");
    exit;
}

function can_manage_role(string $actor_role, string $target_role): bool {
    $actor = role_key($actor_role);
    $target = role_key($target_role);

    if ($actor === 'superadmin') return true;
    if ($actor === 'admin') return in_array($target, ['staff', 'manager'], true);
    return $target === 'staff'; // manager and staff can only manage staff
}

function log_user_creation($pdo, $created_by, $created_for, $station_id, $user_role, $username, $email_sent, $email_address) {
    $stmt = $pdo->prepare("INSERT INTO user_creation_logs (created_by, created_for, station_id, user_role, username, email_sent, email_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$created_by, $created_for, $station_id, $user_role, $username, $email_sent, $email_address]);
}

$msg = '';

// --- ACTION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // 1. Add User
        if ($action === 'add_user') {
            $name  = trim($_POST['name']);
            $email = trim($_POST['email']);
            $role  = role_key($_POST['role'] ?? 'staff');
            $phone = trim($_POST['phone'] ?? '');

            // Email is the username
            $username = $email;

            // Validation
            if (empty($name))  throw new Exception("Full Name is required.");
            if (empty($email)) throw new Exception("Email address is required.");
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Invalid email address format.");

            // Unique email / username check
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) throw new Exception("Email address is already in use.");

            if (!can_manage_role($my_role, $role)) {
                throw new Exception("You can only create allowed roles for your access level.");
            }

            // Manager: 1 per station only
            if ($role === 'manager') {
                $chk = $pdo->prepare("SELECT id FROM users WHERE role = 'manager' AND station_id = ? AND status = 'active'");
                $chk->execute([$my_station_id]);
                if ($chk->fetch()) throw new Exception("Manager account already exists for this station.");
            }

            // Password handling
            $raw_password = trim($_POST['password'] ?? '');
            if (empty($raw_password)) {
                // Auto-generate: 12 chars, uses only allowed symbols _ . - ! @ #
                $password = generateSecurePassword();
            } else {
                // Manual password validation: min 8 chars, upper, lower, digit, allowed symbol
                if (strlen($raw_password) < 8) {
                    throw new Exception("Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #).");
                }
                if (!preg_match('/[A-Z]/', $raw_password)) {
                    throw new Exception("Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #).");
                }
                if (!preg_match('/[a-z]/', $raw_password)) {
                    throw new Exception("Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #).");
                }
                if (!preg_match('/[0-9]/', $raw_password)) {
                    throw new Exception("Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #).");
                }
                if (!preg_match('/[_.\-!@#]/', $raw_password)) {
                    throw new Exception("Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #).");
                }
                $password = $raw_password;
            }

            $hashed = password_hash($password, PASSWORD_DEFAULT);

            // Station is always the Admin's own station (scoped)
            try {
                $station_target = StationManager::getTargetStationForUserCreation(
                    $me['role'],
                    $my_station_id,
                    $_POST['station_id'] ?? null
                );
                StationManager::logStationAssignmentAttempt($me['id'], $me['role'], $my_station_id, $station_target, true);
            } catch (Exception $e) {
                StationManager::logStationAssignmentAttempt($me['id'], $me['role'], $my_station_id, $_POST['station_id'] ?? null, false);
                throw $e;
            }

            // Insert user — must_change_password = 1 forces password change on first login
            $stmt = $pdo->prepare("INSERT INTO users (name, username, role, email, phone, password, station_id, status, must_change_password, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1, NOW())");
            $stmt->execute([$name, $username, $role, $email, $phone, $hashed, $station_target]);
            $new_user_id = $pdo->lastInsertId();

            // Get station name for email
            $station_name_for_email = 'Unknown Station';
            if ($station_target) {
                $stmt_station = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
                $stmt_station->execute([$station_target]);
                $station_data = $stmt_station->fetch(PDO::FETCH_ASSOC);
                if ($station_data) $station_name_for_email = $station_data['name'];
            }

            // Always send credential email for manager and staff
            $email_sent = sendAdminCredentialsEmail($email, $name, $station_name_for_email, $username, $password, $me['role']) ? 1 : 0;

            // Log user creation
            log_user_creation($pdo, $me['id'], $new_user_id, $station_target, $role, $username, $email_sent, $email);
            log_activity($pdo, $me['id'], 'Add User', "Created user $username ($role)");

            if ($email_sent) {
                $msg = "✅ User created successfully. Credentials have been sent to $email.";
            } else {
                $msg = "✅ User created successfully. Account created but email delivery failed. Please share credentials manually.";
            }
        }
        
        // 2. Edit User
        elseif ($action === 'edit_user') {
            $id = $_POST['user_id'];
            $name = trim($_POST['name']);
            $role = role_key($_POST['role'] ?? 'staff');
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            
            // Security check: Ensure user belongs to my station and role is manageable
            $chk = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND station_id = ?");
            $chk->execute([$id, $my_station_id]);
            $target_user = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$target_user) throw new Exception("Unauthorized access to user.");
            if (!can_manage_role($my_role, (string)($target_user['role'] ?? 'staff'))) {
                throw new Exception("You cannot modify this user role.");
            }

            if (!can_manage_role($my_role, $role)) {
                throw new Exception("You can only assign allowed roles for your access level.");
            }
            
            $stmt = $pdo->prepare("UPDATE users SET name = ?, role = ?, email = ? WHERE id = ?");
            $stmt->execute([$name, $role, $email, $id]);
            
            log_activity($pdo, $me['id'], 'Edit User', "Updated details for user #$id");
            $msg = "✅ User details updated.";
        }
        
        // 3. Reset Password
        elseif ($action === 'reset_password') {
            // SuperAdmin is NOT allowed to manually reset passwords.
            // Passwords are auto-generated at account creation and sent via Gmail only.
            if ($my_role === 'superadmin') {
                throw new Exception("SuperAdmin cannot manually reset passwords. Passwords are auto-generated and sent via Gmail at account creation.");
            }

            $id = $_POST['user_id'];
            $new_pass = $_POST['new_password'] ?: 'Petron123!';
            
            $chk = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND station_id = ?");
            $chk->execute([$id, $my_station_id]);
            $target_user = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$target_user) throw new Exception("Unauthorized access to user.");
            if (!can_manage_role($my_role, (string)($target_user['role'] ?? 'staff'))) {
                throw new Exception("You cannot reset password for this user.");
            }
            
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $id]);
            
            log_activity($pdo, $me['id'], 'Reset Password', "Reset password for user #$id");
            $msg = "✅ Password reset successfully. Temporary password: $new_pass";
        }
        
        // 4. Deactivate/Activate User
        elseif ($action === 'toggle_status') {
            $id = $_POST['user_id'];
            $new_status = $_POST['new_status']; // 'active' or 'inactive'
            
            if ($my_role !== 'superadmin') {
                $chk = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND station_id = ?");
                $chk->execute([$id, $my_station_id]);
                $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$target_user) throw new Exception("Unauthorized access to user.");
                if (!can_manage_role($my_role, (string)($target_user['role'] ?? 'staff'))) {
                    throw new Exception("You cannot change status for this user.");
                }
            }
            
            // Prevent deactivating self
            if ($id == $me['id']) throw new Exception("You cannot deactivate your own account.");
            
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $id]);
            
            log_activity($pdo, $me['id'], 'Change Status', "Changed user #$id status to $new_status");
            $msg = "✅ User status updated to $new_status.";
        }
        
    } catch (Exception $e) {
        $msg = "❌ " . $e->getMessage();
    }
}

// --- FETCH USERS ---
$users = [];
$station_name = '';
if ($my_role === 'superadmin') {
    $stmt = $pdo->query("SELECT u.*, s.name as station_name FROM users u LEFT JOIN stations s ON u.station_id = s.id ORDER BY u.created_at DESC");
    $users = $stmt->fetchAll();
    // Fetch stations for dropdown
    $stations = $pdo->query("SELECT id, name FROM stations WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} else {
    if ($my_role === 'staff' || $my_role === 'manager') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE station_id = ? AND LOWER(role) IN ('staff', 'operations_staff', 'operations staff') ORDER BY role, name");
        $stmt->execute([$my_station_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE station_id = ? ORDER BY role, name");
        $stmt->execute([$my_station_id]);
    }
    $users = $stmt->fetchAll();
    
    // Get station name for read-only display
    $station_name = get_station_name($my_station_id);
}

// Get UI configuration for station selection
$station_ui_config = StationManager::getStationUIConfig($my_role, $my_station_id, $station_name);

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">User Management</h1>
        <div class="sub">Manage Manager and Staff accounts, control access, and maintain security.</div>
    </div>
    <div class="actions">
        <button class="btn dark" onclick="openAddModal()">
            <i class="fas fa-user-plus"></i> Add User
        </button>
    </div>
</div>

<?php if($msg): ?>
<div class="card" style="padding:15px; margin-bottom:20px; background: <?php echo strpos($msg, '❌') !== false ? '#f8d7da' : '#d4edda'; ?>; color: <?php echo strpos($msg, '❌') !== false ? '#721c24' : '#155724'; ?>;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- User List Table -->
<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Name / Username</th>
                    <th>Role</th>
                    <th>Contact Info</th>
                    <?php if($my_role === 'superadmin'): ?><th>Station</th><?php endif; ?>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): 
                    $statusClass = $u['status'] === 'active' ? 'success' : 'danger';
                    $roleKey = role_key($u['role'] ?? 'staff');
                    $roleLabel = normalize_role($u['role'] ?? $roleKey);
                    if ($roleLabel === '') { $roleLabel = ucfirst($roleKey); }
                    $roleClass = in_array($roleKey, ['manager','admin','superadmin'], true) ? 'primary' : 'secondary';
                ?>
                <tr>
                    <td>
                        <div style="font-weight:bold;"><?php echo htmlspecialchars($u['name']); ?></div>
                        <div class="muted" style="font-size:0.85em;">@<?php echo htmlspecialchars($u['username']); ?></div>
                    </td>
                    <td><span class="badge bg-<?php echo $roleClass; ?>"><?php echo htmlspecialchars($roleLabel); ?></span></td>
                    <td>
                        <div><i class="fas fa-phone fa-xs"></i> <?php echo htmlspecialchars($u['phone'] ?? 'N/A'); ?></div>
                        <div><i class="fas fa-envelope fa-xs"></i> <?php echo htmlspecialchars($u['email'] ?? 'N/A'); ?></div>
                    </td>
                    <?php if($my_role === 'superadmin'): ?>
                        <td><?php echo htmlspecialchars($u['station_name'] ?? 'Unassigned'); ?></td>
                    <?php endif; ?>
                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo ucfirst($u['status']); ?></span></td>
                    <td>
                        <div style="display:flex; gap:5px;">
                            <button class="btn small ghost" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="Edit User">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn small ghost" onclick="openResetModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" title="Reset Password">
                                <i class="fas fa-key"></i>
                            </button>
                            <?php if($u['id'] != $me['id']): ?>
                                <?php if($u['status'] === 'active'): ?>
                                    <button class="btn small danger" onclick="toggleStatus(<?php echo $u['id']; ?>, 'inactive')" title="Deactivate">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="btn small success" onclick="toggleStatus(<?php echo $u['id']; ?>, 'active')" title="Activate">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($users)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:20px;">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL: Add User -->
<div class="modal" id="addModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Add New User</h3>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="post" id="addUserForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_user">

                <div class="form-group mb-3">
                    <label class="lbl">Full Name <span style="color:red;">*</span></label>
                    <input type="text" name="name" class="inp full" required placeholder="e.g. Juan Dela Cruz">
                </div>

                <div class="form-group mb-3">
                    <label class="lbl">Email Address <span style="color:red;">*</span></label>
                    <input type="email" name="email" class="inp full" required placeholder="e.g. juan@email.com">
                    <small class="muted">This will also serve as the username for login.</small>
                </div>

                <div class="form-group mb-3">
                    <label class="lbl">Role <span style="color:red;">*</span></label>
                    <select name="role" class="inp full" required>
                        <?php if($my_role === 'admin'): ?>
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                        <?php elseif($my_role === 'superadmin'): ?>
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                        <?php else: ?>
                            <option value="staff">Staff</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="grid-2 mb-3" style="gap:10px;">
                    <div>
                        <label class="lbl">Phone</label>
                        <input type="text" name="phone" class="inp full" placeholder="e.g. 09095332320">
                    </div>
                    <div>
                        <!-- Station: read-only for admin, dropdown for superadmin -->
                        <?php if($station_ui_config['type'] === 'dropdown'): ?>
                        <label class="lbl">Station <span style="color:red;">*</span></label>
                        <select name="station_id" class="inp full" required>
                            <option value="">Select Station</option>
                            <?php foreach($stations as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <label class="lbl">Station</label>
                        <input type="text" value="<?php echo htmlspecialchars($station_ui_config['value']); ?>" class="inp full" readonly style="background:#f8f9fa;cursor:not-allowed;color:#495057;border:1px solid #ced4da;">
                        <input type="hidden" name="station_id" value="<?php echo $station_ui_config['hidden_input_value']; ?>">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="lbl">Password</label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="add_password" class="inp full" placeholder="Leave empty to auto-generate">
                        <button type="button" onclick="togglePasswordVisibility('add_password', this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#6c757d;">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <small class="muted">Leave empty to auto-generate a secure password. If set manually: min. 8 characters, must include uppercase, lowercase, number, and a symbol (_ . - ! @ #).</small>
                </div>

                <div class="form-group mb-3">
                    <label class="lbl">Confirm Password</label>
                    <div style="position:relative;">
                        <input type="password" name="confirm_password" id="add_confirm_password" class="inp full" placeholder="Re-enter password">
                        <button type="button" onclick="togglePasswordVisibility('add_confirm_password', this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#6c757d;">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn primary" onclick="return validateAddUserForm()">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Edit User -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Edit User</h3>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-group mb-3">
                    <label class="lbl">Full Name</label>
                    <input type="text" name="name" id="edit_name" class="inp full" required>
                </div>
                <div class="form-group mb-3">
                    <label class="lbl">Role</label>
                    <select name="role" id="edit_role" class="inp full" required>
                        <option value="staff">Staff</option>
                        <?php if($my_role === 'superadmin'): ?>
                            <option value="manager">Manager</option>
                        <?php else: ?>
                            <option value="manager" <?php echo ($edit_role ?? 'staff') === 'manager' ? 'selected' : ''; ?>>Manager</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="grid-2 mb-3" style="gap:10px;">
                    <div>
                        <label class="lbl">Phone</label>
                        <input type="text" name="phone" id="edit_phone" class="inp full">
                    </div>
                    <div>
                        <label class="lbl">Email</label>
                        <input type="email" name="email" id="edit_email" class="inp full">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Reset Password -->
<div class="modal" id="resetModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3 class="modal-title">Reset Password</h3>
            <button class="modal-close" onclick="closeModal('resetModal')">&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset_user_id">
                <p>Reset password for <strong id="reset_username"></strong>?</p>
                <div class="form-group mt-3">
                    <label class="lbl">New Password</label>
                    <input type="text" name="new_password" class="inp full" value="Petron123!" required>
                    <small class="muted">Default: Petron123!</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('resetModal')">Cancel</button>
                <button type="submit" class="btn warning">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- FORM: Toggle Status (Hidden) -->
<form method="post" id="statusForm" style="display:none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="user_id" id="status_user_id">
    <input type="hidden" name="new_status" id="status_new_val">
</form>

<script>
function openAddModal() {
    document.getElementById('addModal').classList.add('show');
}

function openEditModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_name').value = user.name;
    document.getElementById('edit_role').value = user.role;
    document.getElementById('edit_phone').value = user.phone;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('editModal').classList.add('show');
}

function openResetModal(id, username) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_username').innerText = username;
    document.getElementById('resetModal').classList.add('show');
}

function toggleStatus(id, newStatus) {
    if(confirm('Are you sure you want to ' + (newStatus === 'active' ? 'activate' : 'deactivate') + ' this user?')) {
        document.getElementById('status_user_id').value = id;
        document.getElementById('status_new_val').value = newStatus;
        document.getElementById('statusForm').submit();
    }
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function togglePasswordVisibility(fieldId, btn) {
    const field = document.getElementById(fieldId);
    const icon = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function validateAddUserForm() {
    const password = document.getElementById('add_password').value;
    const confirm  = document.getElementById('add_confirm_password').value;

    // Only validate if password was manually entered
    if (password.length > 0) {
        const symbolRegex = /[_.\-!@#]/;
        const errorMsg = 'Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #).';

        if (password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password) || !symbolRegex.test(password)) {
            alert(errorMsg);
            return false;
        }

        if (password !== confirm) {
            alert('Passwords do not match.');
            return false;
        }
    }

    return true;
}

// Handle role selection for Admin accounts
document.addEventListener('DOMContentLoaded', function() {
    // No dynamic role-based field toggling needed anymore —
    // email is always required and password is always optional.
});
</script>

<style>
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8em; color: white; }
    .bg-primary { background: #007bff; }
    .bg-secondary { background: #6c757d; }
    .bg-success { background: #28a745; }
    .bg-danger { background: #dc3545; }
    .btn.small { padding: 4px 8px; font-size: 0.85em; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; }
    .inp.full { width: 100%; }
    .mb-3 { margin-bottom: 1rem; }
    .mt-3 { margin-top: 1rem; }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
