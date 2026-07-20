<?php
$page_id = 'users';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/station_management.php';
require_once __DIR__ . '/../backend/ui_config.php';
require_once __DIR__ . '/../config/email_config.php';
require_login();

// Dynamic Column Detection
$user_cols = [];
try {
    $col_query = $pdo->query("SHOW COLUMNS FROM users");
    while ($col = $col_query->fetch(PDO::FETCH_ASSOC)) {
        $user_cols[] = $col['Field'];
    }
} catch (Exception $e) { /* ignore */ }
// Phone column removed - no longer supported
$s_pass  = in_array('password_hash', $user_cols) ? 'password_hash' : 'password_hash';
$s_uid   = 'id';

$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

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

function normalize_user_status_for_db(string $status): string {
    $normalized = strtolower(trim($status));
    if (in_array($normalized, ['active', 'activate', 'enabled'], true)) {
        return 'Active';
    }
    if (in_array($normalized, ['locked', 'lock'], true)) {
        return 'Locked';
    }
    return 'Disabled';
}

function is_user_active_status(?string $status): bool {
    return strtolower(trim((string)$status)) === 'active';
}

function user_status_label(?string $status): string {
    $normalized = normalize_user_status_for_db((string)$status);
    return $normalized === 'Disabled' ? 'Disabled' : $normalized;
}

function generateEmployeeID($pdo, $role) {
    $role = strtolower(trim($role));
    $prefix = 'STF';
    if ($role === 'superadmin') $prefix = 'SA';
    elseif ($role === 'admin') $prefix = 'ADM';
    elseif ($role === 'manager') $prefix = 'MGR';
    
    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE employee_id LIKE ? ORDER BY employee_id DESC LIMIT 1");
    $stmt->execute([$prefix . '-%']);
    $last_id = $stmt->fetchColumn();
    
    $num = 1;
    if ($last_id) {
        $parts = explode('-', $last_id);
        $last_num = (int)end($parts);
        $num = $last_num + 1;
    }
    
    return $prefix . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
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
            $first_name_input  = trim($_POST['first_name']      ?? '');
            $last_name_input   = trim($_POST['last_name']       ?? '');
            $role_key_input    = $_POST['role']                 ?? '';
            $role              = role_key($role_key_input);
            $employee_id_input = generateEmployeeID($pdo, $role);
            $contact_input     = trim($_POST['contact_number']  ?? '');
            $email_input       = trim($_POST['email']           ?? '');
            $username_input    = trim($_POST['username']        ?? '');
            $assigned_shift    = trim($_POST['assigned_shift']  ?? '');
            $status_input      = trim($_POST['status']          ?? 'Active');
            $raw_password      = trim($_POST['new_password']    ?? '');
            $confirm_password  = trim($_POST['confirm_password']?? '');

            // Derive login identity: email goes in email column, username is explicit or derived from email local part
            $email    = !empty($email_input) ? $email_input : null;
            if (!empty($username_input)) {
                $username = $username_input;
            } elseif (!empty($email_input)) {
                // Use local part of email as default username (before the @), truncated to 50 chars
                $username = substr(explode('@', $email_input)[0], 0, 50);
            } else {
                $username = '';
            }

            // Validate email format
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address format.');
            }

            // Required fields
            if (empty($first_name_input)) throw new Exception('First Name is required.');
            if (empty($last_name_input))  throw new Exception('Last Name is required.');
            if (empty($username))         throw new Exception('Email or Username is required.');
            if (empty($role_key_input))   throw new Exception('Role is required.');

            // Password handling
            if (empty($raw_password)) {
                $password = generateSecurePassword();
            } else {
                if ($raw_password !== $confirm_password) throw new Exception('Passwords do not match.');
                $sym_re = '/[!@#$%^&*(),.?\":{}|<>_\-]/';
                if (strlen($raw_password) < 8 ||
                    !preg_match('/[A-Z]/', $raw_password) ||
                    !preg_match('/[a-z]/', $raw_password) ||
                    !preg_match('/[0-9]/', $raw_password) ||
                    !preg_match($sym_re, $raw_password)) {
                    throw new Exception('Password must be ≥8 chars with uppercase, lowercase, number, and symbol.');
                }
                $password = $raw_password;
            }

            // Uniqueness check
            $dup_sql    = 'SELECT id FROM users WHERE username = ?';
            $dup_params = [$username];
            if (!empty($email)) { $dup_sql .= ' OR email = ?'; $dup_params[] = $email; }
            $chk = $pdo->prepare($dup_sql);
            $chk->execute($dup_params);
            if ($chk->fetch()) throw new Exception('Email or Username is already in use by another account.');

            // Check Employee ID uniqueness if provided
            if (!empty($employee_id_input) && in_array('employee_id', $user_cols)) {
                $chk_emp = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
                $chk_emp->execute([$employee_id_input]);
                if ($chk_emp->fetch()) throw new Exception('Employee ID is already assigned to another account.');
            }

            // Station assignment
            $station_target = null;
            if ($my_role === 'superadmin') {
                if (empty($_POST['station_id'])) throw new Exception('Station selection is required.');
                $station_target = (int)$_POST['station_id'];
                if (!StationManager::isValidActiveStation($station_target)) {
                    throw new Exception('Selected station is not valid or inactive.');
                }
            } elseif ($my_role === 'admin') {
                if ($role === 'admin') {
                    if (empty($_POST['station_id'])) throw new Exception('Station selection required for Admin creation.');
                    $station_target = (int)$_POST['station_id'];
                    if (!StationManager::isValidActiveStation($station_target)) {
                        throw new Exception('Selected station is not valid or inactive.');
                    }
                } else {
                    $station_target = $my_station_id;
                }
            } elseif ($my_role === 'manager') {
                $station_target = $my_station_id;
            } else {
                throw new Exception('You do not have permission to create users.');
            }

            StationManager::logStationAssignmentAttempt($me['id'], $me['role'], $my_station_id, $station_target, true);

            // Role & per-station uniqueness rules
            if ($my_role === 'admin') {
                if (!in_array($role, ['staff', 'manager'])) {
                    throw new Exception('As Admin, you can only create Staff or Manager users.');
                }
                if ($role === 'manager') {
                    $cm = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='manager' AND station_id=?");
                    $cm->execute([$my_station_id]);
                    if ((int)$cm->fetchColumn() > 0) {
                        throw new Exception('Station already has a Manager. Deactivate the existing Manager first.');
                    }
                }
            } elseif ($my_role === 'superadmin') {
                if (!in_array($role, ['staff','manager','admin','superadmin'])) {
                    throw new Exception('Invalid role selected.');
                }
                if ($role === 'admin') {
                    $ca = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND station_id=?");
                    $ca->execute([$station_target]);
                    if ((int)$ca->fetchColumn() > 0) {
                        throw new Exception('Station already has an Admin.');
                    }
                }
                if ($role === 'manager') {
                    $cm = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='manager' AND station_id=?");
                    $cm->execute([$station_target]);
                    if ((int)$cm->fetchColumn() > 0) {
                        throw new Exception('Station already has a Manager.');
                    }
                }
            }

            $hashed = password_hash($password, PASSWORD_DEFAULT);

            // Insert user
            $stmt = $pdo->prepare("INSERT INTO users
                (first_name, last_name, username, role, email, password_hash, station_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$first_name_input, $last_name_input, $username, $role, $email, $hashed, $station_target, $status_input]);
            $new_user_id = (int)$pdo->lastInsertId();

            // Optional extra columns
            $extra_sets = []; $extra_vals = [];
            if (!empty($employee_id_input) && in_array('employee_id',    $user_cols)) { $extra_sets[] = 'employee_id = ?';    $extra_vals[] = $employee_id_input; }
            if (!empty($contact_input)     && in_array('phone_number',   $user_cols)) { $extra_sets[] = 'phone_number = ?';   $extra_vals[] = $contact_input; }
            if (!empty($assigned_shift)    && in_array('assigned_shift', $user_cols)) {
                $extra_sets[] = 'assigned_shift = ?'; $extra_vals[] = $assigned_shift;
                // Also sync shift_assignment and shift times for consistency
                if (in_array('shift_assignment', $user_cols)) { $extra_sets[] = 'shift_assignment = ?'; $extra_vals[] = $assigned_shift; }
                if (in_array('shift_start_time', $user_cols)) {
                    $s_start = ($assigned_shift === 'Shift 1') ? '06:00:00' : '14:00:00';
                    $s_end   = ($assigned_shift === 'Shift 1') ? '14:00:00' : '00:00:00';
                    $extra_sets[] = 'shift_start_time = ?'; $extra_vals[] = $s_start;
                    $extra_sets[] = 'shift_end_time = ?';   $extra_vals[] = $s_end;
                }
            }
            if ($extra_sets) {
                $extra_vals[] = $new_user_id;
                $pdo->prepare("UPDATE users SET " . implode(', ', $extra_sets) . " WHERE id = ?")->execute($extra_vals);
            }

            // Station name for email
            $station_name_for_email = 'Unknown Station';
            if ($station_target) {
                $stn = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
                $stn->execute([$station_target]);
                $stn_row = $stn->fetch(PDO::FETCH_ASSOC);
                if ($stn_row) $station_name_for_email = $stn_row['name'];
            }

            // Send credentials email
            $full_name_for_email = trim($first_name_input . ' ' . $last_name_input);
            $cred_sent = false;
            if (!empty($email)) {
                $cred_sent = (bool)sendAdminCredentialsEmail(
                    $email, $full_name_for_email, $station_name_for_email,
                    $username, $password, $me['role'], $role, $employee_id_input
                );
            }

            log_activity($pdo, $me['id'], 'Add User',
                "Created user $username ($role)" . ($employee_id_input ? " EmpID:$employee_id_input" : ''));

            $msg = $cred_sent
                ? "✅ User created successfully! Credentials email sent to <strong>{$email}</strong>."
                : "✅ User created successfully! Temp Password: <strong>{$password}</strong> — share manually (no email provided).";
        }
        
        elseif ($action === 'edit_user') {
            $id       = $_POST['user_id'];
            $name     = trim($_POST['full_name'] ?? trim(($_POST['first_name'] ?? '') . ' ' . ($_POST['last_name'] ?? '')));
            $login_id = trim($_POST['login_id'] ?? $_POST['email'] ?? '');
            $role     = trim($_POST['role'] ?? 'staff');
            $changePassword = isset($_POST['changePassword']) && $_POST['changePassword'] === 'on';

            if (empty($name))     throw new Exception('Full Name is required.');
            if (empty($login_id)) throw new Exception('Login ID is required.');

            // Parse Login ID (phone support removed)
            $email    = null;
            $username = $login_id;
            if (strpos($login_id, '@') !== false) {
                $email = $login_id;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address format.');
            }
            
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
                
                // ═══════════════════════════════════════════════════════════
                // PREVENT CHANGING STAFF TO MANAGER if Manager already exists
                // ═══════════════════════════════════════════════════════════
                $old_role = strtolower($target_user['role'] ?? 'staff');
                if ($old_role !== 'manager' && $role === 'manager') {
                    // User is being promoted to Manager - check if station already has one
                    $checkManager = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM users 
                        WHERE role = 'manager' 
                          AND station_id = ? 
                          AND id != ?
                    ");
                    $checkManager->execute([$my_station_id, $id]);
                    $managerCount = (int)$checkManager->fetchColumn();
                    
                    if ($managerCount > 0) {
                        throw new Exception("❌ Cannot change role to Manager. This station already has a Manager. Only ONE Manager is allowed per station.");
                    }
                }
            } else {
                // Superadmin editing
                $chk = $pdo->prepare("SELECT id, station_id, role FROM users WHERE id = ?");
                $chk->execute([$id]);
                $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$target_user) throw new Exception("User not found.");
                
                // Check manager limit for superadmin too
                $old_role = strtolower($target_user['role'] ?? 'staff');
                if ($old_role !== 'manager' && $role === 'manager') {
                    $station_id_to_check = $target_user['station_id'];
                    $checkManager = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM users 
                        WHERE role = 'manager' 
                          AND station_id = ? 
                          AND id != ?
                    ");
                    $checkManager->execute([$station_id_to_check, $id]);
                    $managerCount = (int)$checkManager->fetchColumn();
                    
                    if ($managerCount > 0) {
                        throw new Exception("❌ Cannot change role to Manager. This station already has a Manager. Only ONE Manager is allowed per station.");
                    }
                }
            }
            
            // Check login_id uniqueness against other accounts (phone support removed)
            $dup_sql = 'SELECT id FROM users WHERE username = ? AND id != ?';
            $dup_params = [$username, $id];
            if (!empty($email)) { $dup_sql .= ' OR (email = ? AND id != ?)'; $dup_params[] = $email; $dup_params[] = $id; }
            $stmt = $pdo->prepare($dup_sql);
            $stmt->execute($dup_params);
            if ($stmt->fetch()) throw new Exception('This Login ID is already registered to another account.');

            // Split full name into first_name and last_name for database storage
            // 'Edgar Eslit' → first='Edgar', last='Eslit'
            // 'Judy Lastimosa' → first='Judy', last='Lastimosa'
            $name_parts_edit = array_filter(explode(' ', trim($name)));
            $name_parts_edit = array_values($name_parts_edit);
            if (count($name_parts_edit) > 1) {
                $last_name_edit  = array_pop($name_parts_edit);
                $first_name_edit = implode(' ', $name_parts_edit);
            } else {
                $first_name_edit = $name;
                $last_name_edit  = '';
            }

            // Update user details including updated_at
            $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, role = ?, username = ?, email = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$first_name_edit, $last_name_edit, $role, $username, $email, $id]);
            
             // Update password if checkbox is checked
             if ($changePassword) {
                 $new_password = trim($_POST['new_password'] ?? '');
                 
                 // If no password provided, generate one
                 if (empty($new_password)) {
                     $new_password = generateSecurePassword();
                 }
                 
                 $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                 $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
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
            $new_pass = trim($_POST['new_password'] ?? '');
            if (empty($new_pass)) {
                $new_pass = generateSecurePassword();
            }

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
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$hashed, $id]);
            
            log_activity($pdo, $me['id'], 'Reset Password', "Reset password for user #$id");
            $msg = "✅ Password reset successfully. Temporary password: $new_pass";
        }
        
        // 4. Deactivate/Activate User
        elseif ($action === 'toggle_status') {
            $id = $_POST['user_id'];
            $new_status = normalize_user_status_for_db($_POST['new_status'] ?? 'Disabled');
            
            // Get target user info first
            $target_user = null;
            if ($my_role !== 'superadmin') {
                $chk = $pdo->prepare("SELECT id, role, station_id, status FROM users WHERE id = ? AND station_id = ?");
                $chk->execute([$id, $my_station_id]);
                $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$target_user) throw new Exception("Unauthorized access to user.");
                if (!can_manage_role($my_role, (string)($target_user['role'] ?? 'staff'))) {
                    throw new Exception("You cannot change status for this user.");
                }
            } else {
                $chk = $pdo->prepare("SELECT id, role, station_id, status FROM users WHERE id = ?");
                $chk->execute([$id]);
                $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$target_user) throw new Exception("User not found.");
            }
            
            // Prevent deactivating self
            if ($id == $me['id']) throw new Exception("You cannot deactivate your own account.");
            
            // ═══════════════════════════════════════════════════════════
            // PREVENT REACTIVATING MANAGER if another Manager is active
            // ═══════════════════════════════════════════════════════════
            if ($new_status === 'Active' && strtolower($target_user['role']) === 'manager') {
                $station_to_check = $target_user['station_id'];
                
                // Check if station already has an active manager
                $checkActiveManager = $pdo->prepare("
                    SELECT COUNT(*), MAX(name) as existing_name
                    FROM users 
                    WHERE role = 'manager' 
                      AND station_id = ? 
                      AND id != ?
                      AND status = 'Active'
                ");
                $checkActiveManager->execute([$station_to_check, $id]);
                $result = $checkActiveManager->fetch(PDO::FETCH_ASSOC);
                $activeManagerCount = (int)$result['COUNT(*)'];
                $existingName = $result['existing_name'] ?? 'Unknown';
                
                if ($activeManagerCount > 0) {
                    throw new Exception("❌ Cannot reactivate this Manager. Station already has an active Manager: {$existingName}. Only ONE active Manager is allowed per station. Please deactivate the existing Manager first.");
                }
            }
            
            $stmt = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $id]);
            
            log_activity($pdo, $me['id'], 'Change Status', "Changed user #$id status to $new_status");
            $msg = "User status updated to $new_status.";
        }

        // 5. Delete User (disabled by system policy)
        elseif ($action === 'delete_user') {
            throw new Exception('User deletion is permanently disabled to maintain database integrity.');
        }
        
    } catch (Exception $e) {
        $msg = "❌ " . $e->getMessage();
    }
}

// --- FETCH USERS ---
$users = [];
$station_name = '';
$user_list_columns = "
    u.id,
    u.employee_id,
    u.first_name,
    u.last_name,
    CONCAT(u.first_name, ' ', u.last_name) AS name,
    u.username,
    u.role,
    u.email,
    u.station_id,
    u.assigned_shift,
    u.status,
    u.created_at,
    u.updated_at,
    s.name AS station_name
";

if ($my_role === 'superadmin') {
    $stmt = $pdo->query("
        SELECT {$user_list_columns}
        FROM users u
        LEFT JOIN stations s ON u.station_id = s.id
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll();
} else {
    if ($my_role === 'staff' || $my_role === 'manager') {
        $stmt = $pdo->prepare("
            SELECT {$user_list_columns}
            FROM users u
            LEFT JOIN stations s ON u.station_id = s.id
            WHERE u.station_id = ?
              AND LOWER(u.role) IN ('staff', 'operations_staff', 'operations staff')
            ORDER BY u.role, u.username
        ");
        $stmt->execute([$my_station_id]);
    } elseif ($my_role === 'admin') {
        // Admin users can only see Manager and Staff accounts, not other Admin accounts
        $stmt = $pdo->prepare("
            SELECT {$user_list_columns}
            FROM users u
            LEFT JOIN stations s ON u.station_id = s.id
            WHERE u.station_id = ?
              AND LOWER(u.role) IN ('manager', 'staff', 'operations_staff', 'operations staff')
            ORDER BY u.role, u.username
        ");
        $stmt->execute([$my_station_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT {$user_list_columns}
            FROM users u
            LEFT JOIN stations s ON u.station_id = s.id
            WHERE u.station_id = ?
            ORDER BY u.role, u.username
        ");
        $stmt->execute([$my_station_id]);
    }
    $users = $stmt->fetchAll();

    // Fetch station name for admin/manager modal display
    $station_stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $station_stmt->execute([$my_station_id]);
    $station_row = $station_stmt->fetch();
    $station_name = $station_row['name'] ?? get_station_name($my_station_id);
}

// Get UI configuration for station selection
try {
    $station_ui_config = StationManager::getStationUIConfig($my_role, $my_station_id, $station_name);
} catch (Exception $e) {
    error_log("StationManager UI config error: " . $e->getMessage());
    $station_ui_config = [
        'type' => 'readonly_field',
        'value' => $station_name ?: 'Unknown Station',
        'hidden_input_value' => $my_station_id,
        'readonly' => true,
        'help_text' => 'Station assignment information'
    ];
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1" style="font-weight: 800;">USER MANAGEMENT</h1>
    </div>
    <?php if($my_role !== 'staff'): ?>
    <div class="actions">
        <button onclick="openAddModal()"
                style="display:inline-flex !important;align-items:center;gap:6px;padding:8px 16px;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid #00264D !important;background:white !important;color:#00264D !important;transition:all .2s;"
                onmouseover="this.style.background='#00264D';this.style.color='#fff'"
                onmouseout="this.style.background='white';this.style.color='#00264D'">
            <i class="fas fa-user-plus"></i> Add User
        </button>
    </div>
    <?php endif; ?>
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
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Assigned Shift</th>
                    <?php if($my_role === 'superadmin'): ?><th>Station</th><?php endif; ?>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): 
                    $isActive = is_user_active_status($u['status'] ?? '');
                    $statusClass = $isActive ? 'success' : 'danger';
                    $statusLabel = user_status_label($u['status'] ?? '');
                    $roleKey = role_key($u['role'] ?? 'staff');
                    $roleLabel = normalize_role($u['role'] ?? $roleKey);
                    if ($roleLabel === '') { $roleLabel = ucfirst($roleKey); }
                    $roleClass = in_array($roleKey, ['manager','admin','superadmin'], true) ? 'primary' : 'secondary';
                ?>
                <tr>
                    <td style="font-family: monospace; font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($u['employee_id'] ?? '—'); ?></td>
                    <td>
                        <div style="font-weight:bold;"><?php echo htmlspecialchars(isset($u['name']) ? $u['name'] : ($u['username'] ?? 'Unknown')); ?></div>
                        <div class="muted" style="font-size:0.85em;"><?php echo htmlspecialchars($u['email'] ?? ''); ?></div>
                    </td>
                    <td style="font-weight: 500; color: #475569;">@<?php echo htmlspecialchars($u['username'] ?? '—'); ?></td>
                    <td><span class="badge bg-<?php echo $roleClass; ?>"><?php echo htmlspecialchars($roleLabel); ?></span></td>
                    <td>
                        <?php if ($roleKey === 'staff'): ?>
                            <span class="badge" style="background-color: #f1f5f9; color: #1e293b; border: 1px solid #e2e8f0; font-weight: 600;">
                                <?php echo htmlspecialchars($u['assigned_shift'] ?? 'Unassigned'); ?>
                            </span>
                        <?php else: ?>
                            <span class="muted" style="font-size: 0.85em; color: #94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <?php if($my_role === 'superadmin'): ?>
                        <td><?php echo htmlspecialchars($u['station_name'] ?? 'Unassigned'); ?></td>
                    <?php endif; ?>
                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                    <td>
                        <div style="display:flex; flex-direction:column; gap:5px; align-items:flex-end;">

                            <?php if ($roleKey === 'manager'): ?>
                                <!-- Manager: View + Deactivate/Activate only (no Edit, no Reset) -->
                                <button class="action-btn btn-view" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="View Manager Profile">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if($u['id'] != $me['id']): ?>
                                    <?php if($isActive): ?>
                                        <button class="action-btn btn-danger" onclick="toggleStatus(<?php echo (int)$u['id']; ?>, 'Disabled')" title="Deactivate Manager">
                                            <i class="fas fa-times"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button class="action-btn btn-success" onclick="toggleStatus(<?php echo (int)$u['id']; ?>, 'Active')" title="Activate Manager">
                                            <i class="fas fa-check"></i> Activate
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>

                            <?php else: ?>
                                <!-- Staff: View, Edit, Reset, Deactivate/Activate, Delete -->
                                <button class="action-btn btn-view" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="View Staff Profile">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="Edit Staff">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="action-btn btn-reset" onclick="openResetModal(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['username'])); ?>')" title="Reset Password">
                                    <i class="fas fa-key"></i> Reset
                                </button>
                                <?php if($u['id'] != $me['id']): ?>
                                    <?php if($isActive): ?>
                                        <button class="action-btn btn-danger" onclick="toggleStatus(<?php echo (int)$u['id']; ?>, 'Disabled')" title="Deactivate Staff">
                                            <i class="fas fa-times"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button class="action-btn btn-success" onclick="toggleStatus(<?php echo (int)$u['id']; ?>, 'Active')" title="Activate Staff">
                                            <i class="fas fa-check"></i> Activate
                                        </button>
                                    <?php endif; ?>
                                    <?php // Deletion is permanently disabled to protect database integrity. Users can only be deactivated. ?>
                                <?php endif; ?>
                            <?php endif; ?>

                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($users)): ?>
                    <tr><td colspan="<?php echo $my_role === 'superadmin' ? 8 : 7; ?>" style="text-align:center; padding:20px;">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL: Add User -->
<div class="modal" id="addModal">
    <div class="modal-content" style="max-width: 620px; border-radius: 8px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.15);">
        <div class="modal-header" style="background: #ffffff; border-bottom: 1px solid #e8ecf0; padding: 18px 24px;">
            <div>
                <h3 class="modal-title" style="color: #0f172a; font-weight: 700; font-size: 16px; margin: 0;">Add New User</h3>
                <p style="margin: 2px 0 0; font-size: 12px; color: #94a3b8;">Fill in the details below to create a new account.</p>
            </div>
        </div>
        <form method="post" onsubmit="return validatePasswords();" autocomplete="off">
            <div class="modal-body" style="max-height: 68vh; overflow-y: auto; padding: 24px;">
                <input type="hidden" name="action" value="add_user">

                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; padding-left: 10px; border-left: 3px solid #334155; margin-bottom: 14px;">Personal Details</div>

                <div class="grid-2 gap-3" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label class="lbl">First Name <span style="color:red;">*</span></label>
                        <input type="text" name="first_name" class="inp full" required placeholder="e.g. Judy">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Last Name <span style="color:red;">*</span></label>
                        <input type="text" name="last_name" class="inp full" required placeholder="e.g. Lastimosa">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="lbl">Contact Number <span class="muted">(optional)</span></label>
                    <input type="text" name="contact_number" class="inp full" placeholder="e.g. 0917xxxxxxx">
                </div>

                <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; padding-left: 10px; border-left: 3px solid #334155; margin-bottom: 14px; margin-top: 22px;">Account & Role</div>

                <div class="grid-2 gap-3" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label class="lbl">Email Address <span style="color:red;">*</span></label>
                        <input type="email" name="email" class="inp full" required placeholder="e.g. judy@email.com">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Username <span class="muted">(optional, defaults to email)</span></label>
                        <input type="text" name="username" class="inp full" placeholder="e.g. judy.lastimosa">
                    </div>
                </div>

                <div class="grid-2 gap-3" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label class="lbl">Role <span style="color:red;">*</span></label>
                        <select name="role" id="user_role_add" class="inp full" required onchange="toggleStationField()">
                            <option value="">Select role</option>
                            <?php if ($my_role === 'superadmin'): ?>
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
                    <div class="form-group" id="shift_field_group" style="display: none;">
                        <label class="lbl">Assigned Shift <span style="color:red;">*</span></label>
                        <select name="assigned_shift" id="add_assigned_shift" class="inp full">
                            <option value="">Select shift</option>
                            <option value="Shift 1">Shift 1 (6:00 AM – 2:00 PM)</option>
                            <option value="Shift 2">Shift 2 (2:00 PM – 12:00 AM)</option>
                        </select>
                    </div>
                </div>

                <!-- Station Assignment Field - Only show for SuperAdmin and Admin for any role creation -->
                <div class="form-group mb-3" id="station_field_group" style="display: none;">
                    <label class="lbl">Station Assignment <span class="required">*</span></label>
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
                                    <input type="radio" name="station_id" value="<?php echo $station['id']; ?>">
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

                <div class="grid-2 gap-3" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label class="lbl">Temporary Password <span class="muted">(optional)</span></label>
                        <div style="display:flex; gap:10px; align-items:flex-start;">
                            <input type="text" name="new_password" id="new_password" class="inp full" placeholder="Auto-generate if empty">
                            <button type="button" class="btn small ghost" onclick="generateSimplePassword()" title="Generate random password" style="margin-top: 2px; flex-shrink: 0;">
                                <i class="fas fa-dice"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="lbl">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="inp full" placeholder="Re-enter password">
                    </div>
                </div>

                <div class="form-group">
                    <label class="lbl">Status</label>
                    <select name="status" class="inp full">
                        <option value="Active">Active</option>
                        <option value="Disabled">Disabled</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer" style="background: #f8fafc; border-top: 1px solid #e8ecf0; padding: 14px 24px; justify-content: flex-end; gap: 8px;">
                <button type="button" onclick="closeModal('addModal')" style="font-size:11px;font-weight:600;padding:5px 14px;border-radius:4px;cursor:pointer;border:none;background:#dc3545;color:#fff;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" style="font-size:11px;font-weight:600;padding:5px 14px;border-radius:4px;cursor:pointer;border:none;background:#002F70;color:#fff;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-user-plus"></i> Create User</button>
            </div>
        </form>
    </div>
</div>
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
                    <label class="lbl">Full Name <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" id="edit_full_name" class="inp full" required>
                </div>
                <div class="form-group mb-3">
                    <label class="lbl">Login ID <span style="color:red;">*</span></label>
                    <input type="text" name="login_id" id="edit_login_id" class="inp full" required placeholder="Email or Username">
                    <small class="muted">Current login credential. Change to update the login method.</small>
                </div>

                <div class="form-group mb-3">
                    <label class="lbl">Role</label>
                    <select name="role" id="user_role_edit" class="inp full" required>
                        <option value="">-- Select Role --</option>
                        <?php if ($my_role === 'superadmin'): ?>
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
                    <div style="display:flex; gap:10px; align-items:center;">
                        <input type="text" name="new_password" id="reset_password_field" class="inp full" placeholder="Enter password or generate">
                        <button type="button" class="btn small ghost" onclick="generateResetPassword()" title="Generate random password" style="flex-shrink:0;">
                            <i class="fas fa-dice"></i> Generate
                        </button>
                    </div>
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
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Employee ID</div>
                    <div id="view_employee_id" style="font-size:13px;color:#374151;font-weight:600;"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Assigned Shift</div>
                    <div id="view_assigned_shift" style="font-size:13px;color:#374151;font-weight:600;"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Email</div>
                    <div id="view_email" style="font-size:13px;color:#374151;word-break:break-all;"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Contact Number</div>
                    <div id="view_contact_number" style="font-size:13px;color:#374151;"></div>
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

<!-- MODAL: Delete Confirmation permanently removed -->

<script>
function toggleStationField() {
    const roleSelect = document.getElementById('user_role_add');
    const stationFieldGroup = document.getElementById('station_field_group');
    const shiftFieldGroup = document.getElementById('shift_field_group');
    const shiftSelect = document.getElementById('add_assigned_shift');
    const selectedRole = roleSelect.value;
    const currentUserRole = '<?php echo $my_role; ?>';
    
    // Superadmin must pick a station for every new user.
    // Admin/manager accounts use their own station automatically.
    if ((currentUserRole === 'superadmin' && selectedRole !== '') ||
        (currentUserRole === 'admin' && selectedRole === 'admin')) {
        stationFieldGroup.style.display = 'block';
        const stationInputs = stationFieldGroup.querySelectorAll('input[type="radio"]');
        stationInputs.forEach(i => i.required = true);
    } else {
        stationFieldGroup.style.display = 'none';
        const stationInputs = stationFieldGroup.querySelectorAll('input[type="radio"]');
        stationInputs.forEach(i => i.required = false);
    }

    // Shift is required for Staff only
    if (selectedRole === 'staff') {
        shiftFieldGroup.style.display = 'block';
        if (shiftSelect) shiftSelect.required = true;
    } else {
        shiftFieldGroup.style.display = 'none';
        if (shiftSelect) shiftSelect.required = false;
    }
}

// openDeleteModal permanently removed

function validatePasswords() {
    const password = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const role = document.getElementById('user_role_add').value;

    // Validate role
    if (!role) {
        alert('User role is required. Please select a role.');
        return false;
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
    const symbolRegex = /[!@#$%^&*(),.?":{}|<>_\-]/;
    if (
        password.length < 8 ||
        !/[A-Z]/.test(password) ||
        !/[a-z]/.test(password) ||
        !/[0-9]/.test(password) ||
        !symbolRegex.test(password)
    ) {
        alert('Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol.');
        return false;
    }

    return true;
}

function openViewModal(user) {
    var roleKey = (user.role || '').toLowerCase().trim();
    var isManager = roleKey === 'manager';

    var fullName = (user.name || '').trim();
    if (!fullName) {
        fullName = ((user.first_name || '') + ' ' + (user.last_name || '')).trim();
    }
    if (!fullName) {
        fullName = user.username || '—';
    }

    // Avatar initials
    var initials = (fullName || '?').split(' ').filter(Boolean).map(function(w){ return w[0]; }).slice(0,2).join('').toUpperCase();
    document.getElementById('view_avatar').textContent = initials;
    document.getElementById('view_avatar').style.background = isManager ? '#7c3aed' : '#002F6C';

    document.getElementById('view_modal_title').textContent = isManager ? 'Manager Profile' : 'Staff Profile';
    document.getElementById('view_name').textContent = fullName;
    document.getElementById('view_username').textContent = '@' + (user.username || user.email || '—');
    document.getElementById('view_email').textContent = user.email || 'N/A';
    document.getElementById('view_role_text').textContent = isManager ? 'Manager' : 'Staff';
    document.getElementById('view_employee_id').textContent = user.employee_id || '—';
    document.getElementById('view_assigned_shift').textContent = user.assigned_shift || '—';
    document.getElementById('view_contact_number').textContent = user.phone_number || '—';

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

    // Full name
    var fullName = (user.name || '').trim();
    if (!fullName) {
        fullName = ((user.first_name || '') + ' ' + (user.last_name || '')).trim();
    }
    document.getElementById('edit_full_name').value = fullName;

    // Login ID: prefer email, then username (phone support removed)
    var loginId = user.email || user.username || '';
    document.getElementById('edit_login_id').value = loginId;

    // Role
    const normalizedRole = (user.role || '').toLowerCase().trim();
    const roleSelect = document.getElementById('user_role_edit');
    roleSelect.value = normalizedRole;

    // Reset password checkbox and fields
    document.getElementById('changePassword').checked = false;
    document.getElementById('edit_password').value = '';
    document.getElementById('passwordFieldGroup').style.display = 'none';

    openModal('editModal');
}

function openResetModal(id, username) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_username').innerText = username;
    document.getElementById('reset_password_field').value = '';
    openModal('resetModal');
}

function toggleStatus(id, newStatus) {
    const action = String(newStatus).toLowerCase() === 'active' ? 'activate' : 'deactivate';
    if(confirm('Are you sure you want to ' + action + ' this user?')) {
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

function generateCompliantPassword() {
    const upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const lower = 'abcdefghijklmnopqrstuvwxyz';
    const digits = '0123456789';
    const symbols = '_.-!@#';
    const all = upper + lower + digits + symbols;

    // Guarantee at least one of each required type
    let password = '';
    password += upper.charAt(Math.floor(Math.random() * upper.length));
    password += lower.charAt(Math.floor(Math.random() * lower.length));
    password += digits.charAt(Math.floor(Math.random() * digits.length));
    password += symbols.charAt(Math.floor(Math.random() * symbols.length));

    // Fill remaining characters
    for (let i = 4; i < 12; i++) {
        password += all.charAt(Math.floor(Math.random() * all.length));
    }

    // Shuffle characters
    return password.split('').sort(() => 0.5 - Math.random()).join('');
}

function generateSimplePassword() {
    const pwd = generateCompliantPassword();
    document.getElementById('new_password').value = pwd;
    document.getElementById('confirm_password').value = pwd;
    alert('Generated password: ' + pwd);
}

function generatePassword() {
    const pwd = generateCompliantPassword();
    document.getElementById('edit_password').value = pwd;
    alert('Generated password: ' + pwd);
}

function generateResetPassword() {
    const pwd = generateCompliantPassword();
    document.getElementById('reset_password_field').value = pwd;
    alert('Generated password: ' + pwd);
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
    .badge { padding: 5px 10px; border-radius: 4px; font-size: 0.9em; color: white; font-weight: 600; }
    .bg-primary { background: #007bff; }
    .bg-secondary { background: #6c757d; }
    .bg-success { background: #28a745; }
    .bg-danger { background: #dc3545; }
    .btn.small { padding: 4px 8px; font-size: 0.85em; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; }
    .inp.full { width: 100%; }
    .mb-3 { margin-bottom: 1rem; }
    .mt-3 { margin-top: 1rem; }
    
    .action-btn { font-size:13px; padding:6px 12px; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:5px; transition:all .2s; font-weight:600; width:110px; text-decoration:none; background:white !important; border:1px solid transparent; }
    .action-btn:hover { transform:none; filter:none; }
    .btn-view    { color:#16a34a !important; border-color:#16a34a !important; }
    .btn-view:hover { background:#16a34a !important; color:#fff !important; }
    .btn-edit    { color:#00264D !important; border-color:#00264D !important; }
    .btn-edit:hover { background:#00264D !important; color:#fff !important; }
    .btn-reset   { color:#6b7280 !important; border-color:#6b7280 !important; }
    .btn-reset:hover { background:#6b7280 !important; color:#fff !important; }
    .btn-danger  { color:#dc2626 !important; border-color:#dc2626 !important; }
    .btn-danger:hover { background:#dc2626 !important; color:#fff !important; }
    .btn-success { color:#16a34a !important; border-color:#16a34a !important; }
    .btn-success:hover { background:#16a34a !important; color:#fff !important; }
    /* .btn-delete styles permanently removed */

    /* Modal improvements - Database-driven configuration */
    .modal {
        z-index: 99999 !important;
    }
    #addModal .modal-content,
    #editModal .modal-content,
    #resetModal .modal-content,
    #viewModal .modal-content {
        max-width: <?php echo UIConfig::getWithUnit('modal_max_width', 'px', '600'); ?>;
        width: min(<?php echo UIConfig::get('modal_max_width', '600'); ?>px, 95vw);
        max-height: calc(100vh - 40px) !important;
        margin: auto !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }
    
    .modal-body {
        overflow-y: auto !important;
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
    
    /* ===================================================================
       BADGE STYLING - BLACK TEXT FOR ROLE & STATUS
       =================================================================== */
    
    /* Role and Status Badges - BLACK text with light backgrounds */
    .badge {
        color: #000000 !important;
        font-weight: 600 !important;
        border: 1px solid #dee2e6 !important;
    }
    
    .badge.bg-primary {
        background: #cfe2ff !important; /* Light blue background */
        border-color: #b6d4fe !important;
    }
    
    .badge.bg-secondary {
        background: #e2e3e5 !important; /* Light gray background */
        border-color: #d3d4d5 !important;
    }
    
    .badge.bg-success {
        background: #d1e7dd !important; /* Light green background */
        border-color: #badbcc !important;
    }
    
    .badge.bg-danger {
        background: #f8d7da !important; /* Light red background */
        border-color: #f5c2c7 !important;
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
