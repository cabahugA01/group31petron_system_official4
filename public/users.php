<?php
$page_id = 'users';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/station_management.php';
require_once __DIR__ . '/../backend/ui_config.php';
require_once __DIR__ . '/../config/email_config.php';
require_login();

$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

// DEBUG: Log session and station_id info
error_log("=== USERS.PHP DEBUG START ===");
error_log("Current User ID: " . ($me['id'] ?? 'NULL'));
error_log("Current User Role: " . ($me['role'] ?? 'NULL'));
error_log("Normalized Role: " . $my_role);
error_log("Station ID from user_station_id(): " . var_export($my_station_id, true));
error_log("Session user data: " . var_export($me, true));
error_log("=== USERS.PHP DEBUG END ===");

// Access Control: Station-scoped user management for Staff/Manager/Admin, global for Super Admin
if (!in_array($my_role, ['staff', 'manager', 'admin', 'superadmin'], true)) {
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

$msg = '';

// Helper function to generate random password
function generate_random_password($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    return substr(str_shuffle($chars), 0, $length);
}

// --- ACTION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // 1. Add User
        if ($action === 'add_user') {
            $first_name     = trim($_POST['first_name'] ?? '');
            $last_name      = trim($_POST['last_name'] ?? '');
            $name           = trim($first_name . ' ' . $last_name);
            $role_key_input = $_POST['role'] ?? '';
            $role           = role_key($role_key_input);
            $phone          = trim($_POST['phone'] ?? '');
            $email          = trim($_POST['email']);
            $raw_password   = trim($_POST['password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');

            // Email IS the username
            $username = $email;

            // Validate required fields
            if (empty($first_name) || empty($last_name)) throw new Exception("First Name and Last Name are required.");
            if (empty($email))          throw new Exception("Email address is required.");
            if (empty($role_key_input)) throw new Exception("Role is required.");
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Invalid email address format.");

            // Password handling
            if (empty($raw_password)) {
                // Auto-generate: 12 chars, allowed symbols _ . - ! @ #
                $password = generateSecurePassword();
            } else {
                // Manual password validation
                if ($raw_password !== $confirmPassword) throw new Exception("Passwords do not match.");
                $allowed_symbol_regex = '/[_.\-!@#]/';
                if (
                    strlen($raw_password) < 8 ||
                    !preg_match('/[A-Z]/', $raw_password) ||
                    !preg_match('/[a-z]/', $raw_password) ||
                    !preg_match('/[0-9]/', $raw_password) ||
                    !preg_match($allowed_symbol_regex, $raw_password)
                ) {
                    throw new Exception("Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #).");
                }
                $password = $raw_password;
            }

            // Check email/username uniqueness
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) throw new Exception("Email address is already in use.");

            // Handle station assignment based on role and creator
            $station_target = null;
            
            if ($my_role === 'superadmin') {
                // SuperAdmin must select station for all roles
                if (empty($_POST['station_id'])) {
                    throw new Exception("Station selection is required for user creation.");
                }
                $station_target = (int)$_POST['station_id'];
                
                // Validate station exists and is active
                if (!StationManager::isValidActiveStation($station_target)) {
                    throw new Exception("Selected station is not valid or inactive.");
                }
            } elseif ($my_role === 'admin') {
                // Admin creating Admin: must select station
                // Admin creating Staff/Manager: auto-assign to admin's station
                if ($role === 'admin') {
                    // Admin creation requires station selection
                    if (empty($_POST['station_id'])) {
                        throw new Exception("Station selection is required for Admin creation.");
                    }
                    $station_target = (int)$_POST['station_id'];
                    
                    // Validate station exists and is active
                    if (!StationManager::isValidActiveStation($station_target)) {
                        throw new Exception("Selected station is not valid or inactive.");
                    }
                } else {
                    // Staff or Manager: auto-assign to admin's station
                    $station_target = $my_station_id;
                }
            } elseif ($my_role === 'manager') {
                // Manager can only create Staff: auto-assign to manager's station
                $station_target = $my_station_id;
            } else {
                // Staff cannot create users
                throw new Exception("You do not have permission to create users.");
            }

            // Log station assignment attempt
            StationManager::logStationAssignmentAttempt(
                $me['id'],
                $me['role'],
                $my_station_id,
                $station_target,
                true
            );

            // Validate role permissions based on creator
            if ($my_role === 'admin') {
                // Admin can create Staff and Manager
                if (!in_array($role, ['staff', 'manager'])) {
                    throw new Exception("As an Admin, you can only create Staff or Manager users.");
                }
                
                // Additional validation: One manager per admin's station (since Manager is auto-assigned)
                if ($role === 'manager') {
                    $checkManager = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'manager' AND station_id = ? AND status = 'active' AND is_deleted = 0");
                    $checkManager->execute([$my_station_id]); // Check admin's station, not selected station
                    $managerCount = $checkManager->fetchColumn();
                    
                    if ($managerCount > 0) {
                        throw new Exception("Your station already has a Manager. Only one Manager is allowed per station.");
                    }
                }
                
                // Note: Staff and Manager auto-assigned to admin's station - no station selection needed
            } elseif ($my_role === 'superadmin') {
                // Super Admin can create any role
                if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
                    throw new Exception("Invalid role selected.");
                }
                
                // Additional validation: One admin per station
                if ($role === 'admin') {
                    $checkAdmin = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND station_id = ? AND status = 'active' AND is_deleted = 0");
                    $checkAdmin->execute([$station_target]);
                    $adminCount = $checkAdmin->fetchColumn();
                    
                    if ($adminCount > 0) {
                        throw new Exception("This station already has an Admin. Only one Admin is allowed per station.");
                    }
                }
                
                // Additional validation: One manager per station
                if ($role === 'manager') {
                    $checkManager = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'manager' AND station_id = ? AND status = 'active' AND is_deleted = 0");
                    $checkManager->execute([$station_target]);
                    $managerCount = $checkManager->fetchColumn();
                    
                    if ($managerCount > 0) {
                        throw new Exception("This station already has a Manager. Only one Manager is allowed per station.");
                    }
                }
            }

            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO users (name, username, role, email, phone, password, station_id, status, must_change_password, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1, NOW())");
            $stmt->execute([$name, $username, $role, $email, $phone, $hashed, $station_target]);

            // Get station name for email
            $station_name_for_email = 'Unknown Station';
            if ($station_target) {
                $stn = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
                $stn->execute([$station_target]);
                $stn_row = $stn->fetch(PDO::FETCH_ASSOC);
                if ($stn_row) $station_name_for_email = $stn_row['name'];
            }

            // Always send credential email for manager and staff
            $email_sent = sendAdminCredentialsEmail($email, $name, $station_name_for_email, $username, $password, $me['role']) ? 1 : 0;

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
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $name = trim($first_name . ' ' . $last_name);
            $role = trim($_POST['role'] ?? 'staff');
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            $changePassword = isset($_POST['changePassword']) && $_POST['changePassword'] === 'on';
            
            // Normalize role to standard format
            $role = strtolower($role);
            if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
                throw new Exception('Invalid role selected');
            }
            
            // Security: Prevent non-superadmins from assigning admin/superadmin roles
            if ($my_role !== 'superadmin' && in_array($role, ['admin', 'superadmin'])) {
                throw new Exception('You cannot assign admin or super admin roles');
            }

            // Role edit restrictions by current user role
            if (($my_role === 'staff' || $my_role === 'manager') && $role !== 'staff') {
                throw new Exception('You can only assign Staff role.');
            }
            if ($my_role === 'admin' && !in_array($role, ['staff', 'manager'], true)) {
                throw new Exception('As an Admin, you can only assign Staff or Manager roles.');
            }
            
            // Security check: Ensure user belongs to my station and role is manageable (unless superadmin)
            if ($my_role !== 'superadmin') {
                $chk = $pdo->prepare("SELECT id, station_id, role FROM users WHERE id = ? AND station_id = ?");
                $chk->execute([$id, $my_station_id]);
                $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$target_user) throw new Exception("Unauthorized access to user.");
                if (!can_manage_role($my_role, (string)($target_user['role'] ?? 'staff'))) {
                    throw new Exception("You cannot modify this user role.");
                }
            }
            
            // Check email uniqueness
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $id]);
            if ($stmt->fetch()) throw new Exception("This email address is already registered to another account.");

            // Update user details
            $stmt = $pdo->prepare("UPDATE users SET name = ?, role = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->execute([$name, $role, $email, $phone, $id]);
            
             // Update password if checkbox is checked
             if ($changePassword) {
                 $new_password = trim($_POST['new_password'] ?? '');
                 
                 // If no password provided, generate one
                 if (empty($new_password)) {
                     $new_password = generate_random_password();
                 }
                 
                 $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                 $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                 $stmt->execute([$hashed, $id]);
                 
                 $msg = "✅ User details and password updated successfully. New password: $new_password";
                 log_activity($pdo, $me['id'], 'Edit User + Password', "Updated details and password for user #$id");
             } else {
                 $msg = "✅ User details updated.";
                 log_activity($pdo, $me['id'], 'Edit User', "Updated details for user #$id");
             }
        }
        
        // 3. Reset Password
        elseif ($action === 'reset_password') {
            $id = $_POST['user_id'];
            $new_pass = $_POST['new_password'] ?? generateSecurePassword();

            if ($my_role !== 'superadmin') {
                $chk = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND station_id = ?");
                $chk->execute([$id, $my_station_id]);
                $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$target_user) throw new Exception("Unauthorized access to user.");
                if (!can_manage_role($my_role, (string)($target_user['role'] ?? 'staff'))) {
                    throw new Exception("You cannot reset password for this user.");
                }
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
error_log("=== FETCH USERS DEBUG START ===");
error_log("My Role: " . $my_role);
error_log("My Station ID: " . var_export($my_station_id, true));
error_log("Is Superadmin check: " . ($my_role === 'superadmin' ? 'YES' : 'NO'));

if ($my_role === 'superadmin') {
    error_log("Executing SUPERADMIN query - fetch ALL users");
    $stmt = $pdo->query("SELECT u.*, s.name as station_name FROM users u LEFT JOIN stations s ON u.station_id = s.id ORDER BY u.created_at DESC");
    $users = $stmt->fetchAll();
    error_log("Superadmin query returned " . count($users) . " users");
    // Fetch stations for dropdown
    $stations = $pdo->query("SELECT id, name FROM stations WHERE status = 'active' ORDER BY name ASC")->fetchAll();
    error_log("Stations fetched: " . count($stations));
} else {
    error_log("Executing ADMIN/MANAGER query - filter by station_id");
    error_log("SQL: SELECT * FROM users WHERE station_id = ? ORDER BY role, name");
    error_log("Param: " . var_export($my_station_id, true));

    if ($my_role === 'staff' || $my_role === 'manager') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE station_id = ? AND LOWER(role) IN ('staff', 'operations_staff', 'operations staff') ORDER BY role, name");
        $stmt->execute([$my_station_id]);
    } elseif ($my_role === 'admin') {
        // Admin users can only see Manager and Staff accounts, not other Admin accounts
        $stmt = $pdo->prepare("SELECT * FROM users WHERE station_id = ? AND LOWER(role) IN ('manager', 'staff', 'operations_staff', 'operations staff') ORDER BY role, name");
        $stmt->execute([$my_station_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE station_id = ? ORDER BY role, name");
        $stmt->execute([$my_station_id]);
    }
    $users = $stmt->fetchAll();
    
    error_log("Query returned " . count($users) . " users");
    error_log("Users array: " . var_export($users, true));
    
    // Fetch station name for admin/manager modal display
    $station_stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $station_stmt->execute([$my_station_id]);
    $station_row = $station_stmt->fetch();
    $station_name = $station_row['name'] ?? get_station_name($my_station_id);
}
error_log("=== FETCH USERS DEBUG END ===");

// Get UI configuration for station selection
try {
    $station_ui_config = StationManager::getStationUIConfig($my_role, $my_station_id, $station_name);
} catch (Exception $e) {
    error_log("StationManager UI config error: " . $e->getMessage());
    $station_ui_config = ['type' => 'readonly_field', 'value' => 'Unknown Station', 'readonly' => true];
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1" style="font-weight: 800;">USER MANAGEMENT</h1>
        <div class="sub" style="font-weight: 500;">PROFESSIONAL USER ACCOUNT MANAGEMENT AND ACCESS CONTROL - SUPERADMIN/DEVELOPER/ADMIN/MANAGER</div>
    </div>
    <div class="actions">
        <button class="btn" onclick="openAddModal()">
            <i class="fas fa-user-plus me-2"></i> Add User
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
                        <div style="display:flex; flex-direction:column; gap:5px; align-items:flex-end;">

                            <?php if ($roleKey === 'manager'): ?>
                                <!-- Manager: View + Deactivate/Activate only (no Edit, no Reset) -->
                                <button class="action-btn btn-view" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="View Manager Profile">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if($u['id'] != $me['id']): ?>
                                    <?php if($u['status'] === 'active'): ?>
                                        <button class="action-btn btn-danger" onclick="toggleStatus(<?php echo $u['id']; ?>, 'inactive')" title="Deactivate Manager">
                                            <i class="fas fa-times"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button class="action-btn btn-success" onclick="toggleStatus(<?php echo $u['id']; ?>, 'active')" title="Activate Manager">
                                            <i class="fas fa-check"></i> Activate
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>

                            <?php else: ?>
                                <!-- Staff: View, Edit, Reset, Deactivate/Activate -->
                                <button class="action-btn btn-view" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="View Staff Profile">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="Edit Staff">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="action-btn btn-reset" onclick="openResetModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['username'])); ?>')" title="Reset Password">
                                    <i class="fas fa-key"></i> Reset
                                </button>
                                <?php if($u['id'] != $me['id']): ?>
                                    <?php if($u['status'] === 'active'): ?>
                                        <button class="action-btn btn-danger" onclick="toggleStatus(<?php echo $u['id']; ?>, 'inactive')" title="Deactivate Staff">
                                            <i class="fas fa-times"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button class="action-btn btn-success" onclick="toggleStatus(<?php echo $u['id']; ?>, 'active')" title="Activate Staff">
                                            <i class="fas fa-check"></i> Activate
                                        </button>
                                    <?php endif; ?>
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
         <form method="post" onsubmit="return validatePasswords();">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_user">
                
                <div class="grid-2 mb-3" style="gap:10px;">
                    <div class="form-group">
                        <label class="lbl">First Name <span style="color:red;">*</span></label>
                        <input type="text" name="first_name" class="inp full" required placeholder="e.g. Juan">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Last Name <span style="color:red;">*</span></label>
                        <input type="text" name="last_name" class="inp full" required placeholder="e.g. Dela Cruz">
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="lbl">Email Address <span style="color:red;">*</span></label>
                    <input type="email" name="email" class="inp full" required placeholder="e.g. juan@email.com">
                    <small class="muted">This will also serve as the username for login.</small>
                </div>
                
                <div class="form-group mb-3">
                    <label class="lbl">Role</label>
                    <select name="role" id="user_role_add" class="inp full" required onchange="toggleStationField()">
                        <option value="">Select role</option>
                        <?php 
                        // Show only roles that current user can create
                        if ($my_role === 'superadmin'): ?>
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                            <option value="superadmin">Super Admin</option>
                        <?php elseif ($my_role === 'admin'): ?>
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                        <?php elseif ($my_role === 'manager' || $my_role === 'staff'): ?>
                            <option value="staff">Staff</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <!-- Station Assignment Field - Only show for SuperAdmin and Admin for any role creation -->
                <div class="form-group mb-3" id="station_field_group" style="display: none;">
                    <label class="lbl">Station <span class="required">*</span></label>
                    <?php if($station_ui_config['type'] === 'radio_buttons'): ?>
                        <?php 
                        $stationConfig = UIConfig::getStationSelectorConfig();
                        $gap = UIConfig::get('station_selector_gap', '8');
                        ?>
                        <div class="station-selector" style="display: flex; flex-direction: column; gap: <?php echo $gap; ?>px; margin-top: 8px; max-height: <?php echo $stationConfig['max_height']; ?>; overflow-y: auto; padding: <?php echo $stationConfig['padding']; ?>; border: 1px solid #ced4da; border-radius: 6px; background: #f8f9fa;">
                            <?php foreach($station_ui_config['stations'] as $station): ?>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 4px; border-radius: 4px;" 
                                       onmouseover="this.style.background='#e9ecef'" 
                                       onmouseout="this.style.background='transparent'">
                                    <input type="radio" name="station_id" value="<?php echo $station['id']; ?>" required>
                                    <span><?php echo htmlspecialchars($station['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <input type="text" 
                               value="<?php echo htmlspecialchars($station_ui_config['value']); ?>" 
                               class="inp full" 
                               readonly 
                               style="background: #f8f9fa; cursor: not-allowed; color: #495057; border: 1px solid #ced4da;">
                        <input type="hidden" name="station_id" value="<?php echo $station_ui_config['hidden_input_value']; ?>">
                    <?php endif; ?>
                    <small class="form-text text-muted">
                        <i class="fas fa-info-circle"></i> <?php echo $station_ui_config['help_text'] ?? 'Station assignment information'; ?>
                    </small>
                </div>
                
                <!-- Hidden auto-assignment for Manager/Staff creation -->
                <input type="hidden" name="auto_station_id" id="auto_station_id" value="<?php echo $my_station_id; ?>">
                
                <div class="grid-2 mb-3" style="gap:10px;">
                    <div class="form-group">
                        <label class="lbl">Phone</label>
                        <input type="text" name="phone" class="inp full" placeholder="e.g. 09095332320">
                    </div>
                </div>
                
                <div class="form-group mb-3">
                    <label class="lbl">Password</label>
                    <div style="display:flex; gap:10px; align-items:flex-start;">
                        <input type="text" name="password" id="new_password" class="inp full" placeholder="Leave empty to auto-generate">
                        <button type="button" class="btn small ghost" onclick="generateSimplePassword()" title="Generate random password" style="margin-top: 2px; flex-shrink: 0;">
                            <i class="fas fa-dice"></i>
                        </button>
                    </div>
                    <small class="muted" style="margin-top: 4px;">Auto-generates random password if left empty</small>
                </div>
                
                <div class="form-group mb-3">
                    <label class="lbl">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="inp full" placeholder="Re-enter password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('addModal')">Cancel</button>
                 <button type="submit" class="btn primary" style="background:linear-gradient(135deg,#003d7a 0%,#0056b3 100%);border:2px solid #003d7a;color:#fff;font-weight:600;">Create User</button>
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
                
                <div class="grid-2 mb-3" style="gap:10px;">
                    <div class="form-group">
                        <label class="lbl">First Name <span style="color:red;">*</span></label>
                        <input type="text" name="first_name" id="edit_first_name" class="inp full" required>
                    </div>
                    <div class="form-group">
                        <label class="lbl">Last Name <span style="color:red;">*</span></label>
                        <input type="text" name="last_name" id="edit_last_name" class="inp full" required>
                    </div>
                </div>
                <div class="form-group mb-3">
                    <label class="lbl">Role</label>
                    <select name="role" id="user_role_edit" class="inp full" required>
                        <option value="">-- Select Role --</option>
                        <?php 
                        // Show only roles that current user can assign
                        if ($my_role === 'superadmin'): ?>
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                            <option value="superadmin">Super Admin</option>
                        <?php elseif ($my_role === 'admin'): ?>
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                        <?php elseif ($my_role === 'manager' || $my_role === 'staff'): ?>
                            <option value="staff">Staff</option>
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
                
                <!-- Password Change Section -->
                <div class="form-group mb-3">
                    <label class="lbl">
                        <input type="checkbox" id="changePassword" name="changePassword" onchange="togglePasswordField()">
                        <span style="margin-left: 8px;">Change Password</span>
                    </label>
                </div>
                
                <div id="passwordFieldGroup" class="form-group mb-3" style="display: none;">
                    <label class="lbl">New Password</label>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="password" name="new_password" id="edit_password" class="inp full" placeholder="Enter new password or leave empty for auto-generate">
                        <button type="button" class="btn small ghost" onclick="generatePassword()" title="Generate random password">
                            <i class="fas fa-dice"></i> Generate
                        </button>
                    </div>
                    <small style="color: #666; margin-top: 5px; display: block;">Leave empty to generate a secure password automatically</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn primary" style="background:linear-gradient(135deg,#003d7a 0%,#0056b3 100%);border:2px solid #003d7a;color:#fff;font-weight:600;">Save Changes</button>
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
                    <input type="password" name="new_password" class="inp full" placeholder="Enter new password or leave empty to auto-generate">
                    <small class="muted">Leave empty for auto-generated secure password</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('resetModal')">Cancel</button>
                <button type="submit" class="btn warning" style="background:linear-gradient(135deg,#003d7a 0%,#0056b3 100%);border:2px solid #003d7a;color:#fff;font-weight:600;">Reset Password</button>
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

<!-- MODAL: View Profile -->
<div class="modal" id="viewModal">
    <div class="modal-content" style="max-width:480px;">
        <div class="modal-header">
            <h3 class="modal-title" id="view_modal_title">User Profile</h3>
            <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="modal-body">

            <!-- Avatar + name -->
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #e5e7eb;">
                <div id="view_avatar" style="width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#fff;flex-shrink:0;background:#002F6C;"></div>
                <div>
                    <div id="view_name" style="font-size:16px;font-weight:700;color:#111827;"></div>
                    <div id="view_username" style="font-size:12px;color:#6b7280;margin-top:2px;"></div>
                    <div id="view_role_badge" style="margin-top:5px;"></div>
                </div>
            </div>

            <!-- Details grid -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Email</div>
                    <div id="view_email" style="font-size:13px;color:#374151;word-break:break-all;"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Phone</div>
                    <div id="view_phone" style="font-size:13px;color:#374151;"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Status</div>
                    <div id="view_status"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Role</div>
                    <div id="view_role_text" style="font-size:13px;color:#374151;font-weight:600;"></div>
                </div>
            </div>

            <!-- Manager-only note -->
            <div id="view_manager_note" style="display:none;margin-top:16px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12px;color:#1d4ed8;">
                <i class="fas fa-info-circle"></i> Manager accounts are read-only. To make changes, contact the Super Admin.
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn ghost" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
function toggleStationField() {
    const roleSelect = document.getElementById('user_role_add');
    const stationFieldGroup = document.getElementById('station_field_group');
    const selectedRole = roleSelect.value;
    const currentUserRole = '<?php echo $my_role; ?>';
    
    // Show station field only for:
    // - SuperAdmin creating Admin role
    // - Admin creating Admin role
    // Hide for Staff and Manager roles (auto-assign)
    if ((currentUserRole === 'superadmin' && selectedRole === 'admin') || 
        (currentUserRole === 'admin' && selectedRole === 'admin')) {
        stationFieldGroup.style.display = 'block';
    } else {
        stationFieldGroup.style.display = 'none';
    }
}

function validatePasswords() {
    const password = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const role = document.getElementById('user_role_add').value;

    // Validate role
    if (!role) {
        alert('User role is required. Please select a role.');
        return false;
    }

    // Check if station field is visible and requires selection
    const stationFieldGroup = document.getElementById('station_field_group');
    if (stationFieldGroup.style.display !== 'none') {
        const stationRadios = document.querySelectorAll('input[name="station_id"]');
        let stationSelected = false;
        stationRadios.forEach(radio => { if (radio.checked) stationSelected = true; });
        if (!stationSelected) {
            alert('Station is required. Please select a station.');
            return false;
        }
    }

    // If both empty → auto-generate on server side, just confirm fields match
    if (password === '' && confirmPassword === '') {
        return true; // server will auto-generate
    }

    // If one is filled, both must match
    if (password !== confirmPassword) {
        alert('⚠️ Passwords do not match! Please ensure both passwords are identical.');
        return false;
    }

    // Manual password rules: min 8, upper, lower, digit, allowed symbol
    const symbolRegex = /[_.\-!@#]/;
    if (
        password.length < 8 ||
        !/[A-Z]/.test(password) ||
        !/[a-z]/.test(password) ||
        !/[0-9]/.test(password) ||
        !symbolRegex.test(password)
    ) {
        alert('Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #).');
        return false;
    }

    return true;
}

function generateSimplePassword() {
    const upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const lower   = 'abcdefghijklmnopqrstuvwxyz';
    const digits  = '0123456789';
    const symbols = '_.-!@#';
    const all     = upper + lower + digits + symbols;

    // Guarantee one of each required type
    let pwd = '';
    pwd += upper[Math.floor(Math.random() * upper.length)];
    pwd += lower[Math.floor(Math.random() * lower.length)];
    pwd += digits[Math.floor(Math.random() * digits.length)];
    pwd += symbols[Math.floor(Math.random() * symbols.length)];

    // Fill to 12 chars
    for (let i = 4; i < 12; i++) {
        pwd += all[Math.floor(Math.random() * all.length)];
    }

    // Shuffle
    pwd = pwd.split('').sort(() => Math.random() - 0.5).join('');

    document.getElementById('new_password').value = pwd;
    document.getElementById('confirm_password').value = pwd;
    alert('Generated password: ' + pwd);
}

function openViewModal(user) {
    var roleKey = (user.role || '').toLowerCase().trim();
    var isManager = roleKey === 'manager';

    // Avatar initials
    var initials = (user.name || '?').split(' ').map(function(w){ return w[0]; }).slice(0,2).join('').toUpperCase();
    document.getElementById('view_avatar').textContent = initials;
    document.getElementById('view_avatar').style.background = isManager ? '#7c3aed' : '#002F6C';

    document.getElementById('view_modal_title').textContent = isManager ? 'Manager Profile' : 'Staff Profile';
    document.getElementById('view_name').textContent = user.name || '—';
    document.getElementById('view_username').textContent = '@' + (user.username || user.email || '—');
    document.getElementById('view_email').textContent = user.email || 'N/A';
    document.getElementById('view_phone').textContent = user.phone || 'N/A';
    document.getElementById('view_role_text').textContent = isManager ? 'Manager' : 'Staff';

    // Role badge
    var badgeColor = isManager ? '#7c3aed' : '#6b7280';
    document.getElementById('view_role_badge').innerHTML =
        '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;color:#fff;background:' + badgeColor + ';">' +
        (isManager ? 'Manager' : 'Staff') + '</span>';

    // Status badge
    var isActive = (user.status || '').toLowerCase() === 'active';
    document.getElementById('view_status').innerHTML =
        '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;color:#fff;background:' +
        (isActive ? '#16a34a' : '#dc2626') + ';">' +
        (isActive ? 'Active' : 'Inactive') + '</span>';

    // Manager note
    document.getElementById('view_manager_note').style.display = isManager ? 'block' : 'none';

    openModal('viewModal');
}

function openAddModal() {
    openModal('addModal');
}

function openEditModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    
    let parts = (user.name || '').trim().split(' ');
    let firstName = parts[0] || '';
    let lastName = parts.slice(1).join(' ') || '';
    
    document.getElementById('edit_first_name').value = firstName;
    document.getElementById('edit_last_name').value = lastName;

    const normalizedRole = (user.role || '').toLowerCase().trim();
    const roleSelect = document.getElementById('user_role_edit');
    roleSelect.value = normalizedRole;

    document.getElementById('edit_phone').value = user.phone || '';
    document.getElementById('edit_email').value = user.email || '';

    // Reset password checkbox and fields
    document.getElementById('changePassword').checked = false;
    document.getElementById('edit_password').value = '';
    document.getElementById('passwordFieldGroup').style.display = 'none';

    openModal('editModal');
}

function openResetModal(id, username) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_username').innerText = username;
    openModal('resetModal');
}

function toggleStatus(id, newStatus) {
    if(confirm('Are you sure you want to ' + (newStatus === 'active' ? 'activate' : 'deactivate') + ' this user?')) {
        document.getElementById('status_user_id').value = id;
        document.getElementById('status_new_val').value = newStatus;
        document.getElementById('statusForm').submit();
    }
}

function closeModal(id) {
    var el = document.getElementById(id);
    if (el) {
        el.classList.remove('show');
        el.style.display = ''; // clear inline style override from user_management.js
    }
}

function openModal(id) {
    var el = document.getElementById(id);
    if (el) {
        el.style.display = ''; // clear any inline override first
        el.classList.add('show');
    }
}

function togglePasswordField() {
    const checkbox = document.getElementById('changePassword');
    const passwordGroup = document.getElementById('passwordFieldGroup');
    if (checkbox.checked) {
        passwordGroup.style.display = 'block';
    } else {
        passwordGroup.style.display = 'none';
        document.getElementById('edit_password').value = '';
    }
}

function generatePassword() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    let password = '';
    for (let i = 0; i < 8; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('edit_password').value = password;
    alert('Generated password: ' + password);
}

function setupStationSelection() {
    // Radio buttons are now used instead of dropdown
    // No additional setup needed
}

document.addEventListener('DOMContentLoaded', function () {
    setupStationSelection();
    toggleStationField(); // Initialize station field visibility
    const params = new URLSearchParams(window.location.search);
    if (params.get('view') === 'create') {
        openAddModal();
    }
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
    
    .action-btn { font-size:12px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:5px; transition:all .15s; font-weight:600; width:100px; text-decoration:none; }
    .action-btn:hover { filter:brightness(.9); transform:translateY(-1px); }
    .btn-view    { background:#28a745; color:#fff; }
    .btn-edit    { background:#002F70; color:#fff; }
    .btn-reset   { background:#ffc107; color:#333; }
    .btn-danger  { background:#dc3545; color:#fff; }
    .btn-success { background:#28a745; color:#fff; }

    /* Modal improvements - Database-driven configuration */
    #addModal .modal-content,
    #editModal .modal-content,
    #resetModal .modal-content,
    #viewModal .modal-content {
        max-width: <?php echo UIConfig::getWithUnit('modal_max_width', 'px', '600'); ?>;
        width: min(<?php echo UIConfig::get('modal_max_width', '600'); ?>px, 95vw);
        max-height: calc(100vh - 60px) !important;
        margin: 30px auto !important;
        overflow-y: auto;
    }
    
    .modal-body {
        padding: <?php echo UIConfig::get('modal_body_padding', '24px 20px'); ?>;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    
    .lbl {
        font-size: 13px;
        font-weight: 600;
        color: #1f2937;
    }
    
    .inp {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
        font-family: inherit;
    }
    
    .inp:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .muted {
        font-size: 12px;
        color: #6b7280;
    }

    .modal-footer {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        padding: 16px 20px;
        border-top: 1px solid #e5e7eb;
    }

    .typeahead-suggestions {
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: #fff;
        margin-top: 6px;
        max-height: <?php echo UIConfig::getWithUnit('typeahead_max_height', 'px', '220'); ?>;
        overflow-y: auto;
        position: relative;
        z-index: 20;
    }

    .typeahead-item {
        width: 100%;
        border: 0;
        background: #fff;
        text-align: left;
        padding: 10px 12px;
        font-size: 13px;
        cursor: pointer;
        display: block;
    }

    .typeahead-item:hover {
        background: #f3f4f6;
    }

    .typeahead-item.empty {
        color: #6b7280;
        cursor: default;
    }
    
    .btn {
        padding: 8px 16px;
        border: 1px solid #d1d5db;
        background: #fff;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }
    
    .btn:hover {
        background: #f3f4f6;
    }
    
    .btn.primary {
        background: #3b82f6;
        color: white;
        border-color: #3b82f6;
    }
    
    .btn.primary:hover {
        background: #2563eb;
    }
    
    .btn.ghost {
        background: transparent;
        border: none;
        color: #6b7280;
    }
    
    .btn.ghost:hover {
        background: #f3f4f6;
        color: #1f2937;
    }
    
    /* Station selector styling */
    .station-selector {
        scrollbar-width: thin;
        scrollbar-color: #d1d5db #f8f9fa;
    }
    
    .station-selector::-webkit-scrollbar {
        width: 8px;
    }
    
    .station-selector::-webkit-scrollbar-track {
        background: #f8f9fa;
        border-radius: 4px;
    }
    
    .station-selector::-webkit-scrollbar-thumb {
        background: #d1d5db;
        border-radius: 4px;
    }
    
    .station-selector::-webkit-scrollbar-thumb:hover {
        background: #9ca3af;
    }
    
    .station-selector label {
        transition: background-color 0.2s ease;
        padding: 8px 12px;
        border-radius: 6px;
        margin: 2px 0;
    }
    
    .station-selector label:hover {
        background-color: #e9ecef !important;
    }
    
    .station-selector input[type="radio"] {
        margin: 0;
        min-width: 16px;
        height: 16px;
    }
    
    .station-selector input[type="radio"]:checked + span {
        font-weight: 600;
        color: #3b82f6;
    }
    
    .station-selector input[type="radio"]:checked + span::before {
        content: " ";
        display: inline-block;
        width: 4px;
        height: 4px;
        background: #3b82f6;
        border-radius: 50%;
        margin-right: 6px;
    }
</style>

<!-- Fix: ensure all modals are properly hidden on load and override conflicting JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Force all modals to be properly hidden (clear any inline style conflicts)
    document.querySelectorAll('.modal').forEach(function(m) {
        m.classList.remove('show');
        m.style.display = '';
    });

    // Override window.closeModal to handle both class and inline style
    window.closeModal = function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.classList.remove('show');
            el.style.display = '';
        }
    };

    // Override window.closeAllModals
    window.closeAllModals = function() {
        document.querySelectorAll('.modal').forEach(function(el) {
            el.classList.remove('show');
            el.style.display = '';
        });
    };

    // Close modal when clicking the backdrop (outside modal-content)
    document.querySelectorAll('.modal').forEach(function(m) {
        m.addEventListener('click', function(e) {
            if (e.target === m) {
                window.closeModal(m.id);
            }
        });
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
