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

$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

// Access Control:
// Service Staff CANNOT access user management (redirect to dashboard)
// Manager & Admin/Superadmin can view.
if ($my_role === 'staff') {
    header("Location: dashboard.php");
    exit;
}
if (!in_array($my_role, ['manager', 'admin', 'superadmin'], true)) {
    header("Location: dashboard.php");
    exit;
}

$current_tab = $_GET['tab'] ?? 'active';
if (!in_array($current_tab, ['active', 'archived'], true)) {
    $current_tab = 'active';
}

function can_manage_role(string $actor_role, string $target_role): bool {
    $actor = role_key($actor_role);
    $target = role_key($target_role);

    if ($actor === 'superadmin') return true;
    if ($actor === 'admin') return in_array($target, ['staff', 'manager'], true);
    return false; // Manager cannot manage any role
}

function is_user_archived_status(?string $status): bool {
    $normalized = strtolower(trim((string)$status));
    return in_array($normalized, ['disabled', 'archived', 'inactive', 'locked'], true);
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

$is_error = false;

// --- ACTION HANDLER (Admin / Superadmin only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($my_role === 'manager') {
        $msg = "Managers have read-only access to user lists and cannot perform modifications.";
        $is_error = true;
    } else {
        try {
            // 1. Add User & Automatically Send Email Credentials
            if ($action === 'add_user') {
                $first_name_input  = trim($_POST['first_name']      ?? '');
                $last_name_input   = trim($_POST['last_name']       ?? '');
                $role_key_input    = $_POST['role']                 ?? '';
                $role              = role_key($role_key_input);
                $employee_id_input = generateEmployeeID($pdo, $role);
                $contact_input     = trim($_POST['contact_number']  ?? '');
                $email_input       = trim($_POST['email']           ?? '');
                $username_input    = trim($_POST['username']        ?? '');
                $assigned_shift    = ($role === 'staff') ? (trim($_POST['assigned_shift'] ?? '') ?: null) : null;
                $status_input      = 'Active';
                $raw_password      = trim($_POST['new_password']    ?? '');
                $confirm_password  = trim($_POST['confirm_password']?? '');

                $email = !empty($email_input) ? $email_input : null;
                if (!empty($username_input)) {
                    $username = $username_input;
                } elseif (!empty($email_input)) {
                    $username = substr(explode('@', $email_input)[0], 0, 50);
                } else {
                    $username = '';
                }

                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email address format.');
                }

                // Philippine Phone Validation
                if (!empty($contact_input)) {
                    $clean_contact = preg_replace('/[\s\-\(\)\.]/', '', $contact_input);
                    if (!preg_match('/^(09\d{9}|\+639\d{9}|639\d{9})$/', $clean_contact)) {
                        throw new Exception('Invalid Philippine contact number. Must be an 11-digit mobile number starting with 09 (e.g. 09171234567 or +639171234567).');
                    }
                    if (str_starts_with($clean_contact, '+639')) {
                        $contact_input = '09' . substr($clean_contact, 4);
                    } elseif (str_starts_with($clean_contact, '639')) {
                        $contact_input = '09' . substr($clean_contact, 3);
                    } else {
                        $contact_input = $clean_contact;
                    }
                }

                if (empty($first_name_input)) throw new Exception('First Name is required.');
                if (empty($last_name_input))  throw new Exception('Last Name is required.');
                if (empty($username))         throw new Exception('Username or Email is required.');
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
                } elseif ($my_role === 'admin') {
                    $station_target = $my_station_id;
                }

                // Role & per-station uniqueness rules: ONLY 1 Admin and ONLY 1 Manager per station (Staff is unlimited)
                if ($my_role === 'admin') {
                    if (!in_array($role, ['staff', 'manager'])) {
                        throw new Exception('As Admin, you can only create Staff or Manager users.');
                    }
                }

                if ($role === 'manager' && $station_target) {
                    $cm = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role)='manager' AND station_id=? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $cm->execute([$station_target]);
                    if ((int)$cm->fetchColumn() > 0) {
                        throw new Exception('This station already has an active Manager. Each station is allowed ONLY 1 Manager.');
                    }
                }

                if ($role === 'admin' && $station_target) {
                    $ca = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role)='admin' AND station_id=? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $ca->execute([$station_target]);
                    if ((int)$ca->fetchColumn() > 0) {
                        throw new Exception('This station already has an active Admin. Each station is allowed ONLY 1 Admin.');
                    }
                }

                $hashed = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("INSERT INTO users
                    (first_name, last_name, username, role, email, password_hash, station_id, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$first_name_input, $last_name_input, $username, $role, $email, $hashed, $station_target, $status_input]);
                $new_user_id = (int)$pdo->lastInsertId();

                $extra_sets = []; $extra_vals = [];
                if (!empty($employee_id_input) && in_array('employee_id',    $user_cols)) { $extra_sets[] = 'employee_id = ?';    $extra_vals[] = $employee_id_input; }
                if (!empty($contact_input)     && in_array('phone_number',   $user_cols)) { $extra_sets[] = 'phone_number = ?';   $extra_vals[] = $contact_input; }
                if ($role === 'staff' && !empty($assigned_shift) && in_array('assigned_shift', $user_cols)) {
                    $extra_sets[] = 'assigned_shift = ?'; $extra_vals[] = $assigned_shift;
                    if (in_array('shift_assignment', $user_cols)) { $extra_sets[] = 'shift_assignment = ?'; $extra_vals[] = $assigned_shift; }
                } else {
                    if (in_array('assigned_shift', $user_cols)) { $extra_sets[] = 'assigned_shift = NULL'; }
                    if (in_array('shift_assignment', $user_cols)) { $extra_sets[] = 'shift_assignment = NULL'; }
                }
                if ($extra_sets) {
                    $extra_vals[] = $new_user_id;
                    $pdo->prepare("UPDATE users SET " . implode(', ', $extra_sets) . " WHERE id = ?")->execute($extra_vals);
                }

                // Station name for email
                $station_name_for_email = 'Petron Service Station';
                if ($station_target) {
                    $stn = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
                    $stn->execute([$station_target]);
                    $stn_row = $stn->fetch(PDO::FETCH_ASSOC);
                    if ($stn_row) $station_name_for_email = $stn_row['name'];
                }

                // AUTOMATIC EMAIL CREDENTIALS SENDING
                $full_name_for_email = trim($first_name_input . ' ' . $last_name_input);
                $email_sent = false;
                if (!empty($email)) {
                    try {
                        $email_sent = (bool)sendAdminCredentialsEmail(
                            $email, $full_name_for_email, $station_name_for_email,
                            $username, $password, $me['role'], $role, $employee_id_input
                        );
                    } catch (Exception $mailEx) {
                        $email_sent = false;
                    }
                }

                log_activity($pdo, $me['id'], 'Add User',
                    "Created user '$username' ($role)" . ($email_sent ? " (Email sent to $email)" : '') . ($assigned_shift ? " Shift:$assigned_shift" : '') . ($employee_id_input ? " EmpID:$employee_id_input" : ''));

                if ($email_sent) {
                    $msg = "User <strong>" . htmlspecialchars($full_name_for_email) . "</strong> created successfully. Login credentials and setup instructions have been automatically emailed to <strong>" . htmlspecialchars($email) . "</strong>.";
                } else {
                    $msg = "User <strong>" . htmlspecialchars($full_name_for_email) . "</strong> created successfully. Initial Temp Password: <strong>" . htmlspecialchars($password) . "</strong> (Share manually with employee).";
                }
            }
            
            // 2. Edit User
            elseif ($action === 'edit_user') {
                $id            = (int)$_POST['user_id'];
                $first_name    = trim($_POST['first_name'] ?? '');
                $last_name     = trim($_POST['last_name'] ?? '');
                $login_id      = trim($_POST['login_id'] ?? '');
                $contact_input = trim($_POST['contact_number'] ?? '');
                $role          = strtolower(trim($_POST['role'] ?? 'staff'));
                $assigned_shift= ($role === 'staff') ? (trim($_POST['assigned_shift'] ?? '') ?: null) : null;

                if (empty($first_name)) throw new Exception('First Name is required.');
                if (empty($last_name))  throw new Exception('Last Name is required.');
                if (empty($login_id))   throw new Exception('Login ID (Username or Email) is required.');

                $email    = null;
                $username = $login_id;
                if (strpos($login_id, '@') !== false) {
                    $email = $login_id;
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address format.');
                }

                // Philippine Phone Validation
                if (!empty($contact_input)) {
                    $clean_contact = preg_replace('/[\s\-\(\)\.]/', '', $contact_input);
                    if (!preg_match('/^(09\d{9}|\+639\d{9}|639\d{9})$/', $clean_contact)) {
                        throw new Exception('Invalid Philippine contact number. Must be an 11-digit mobile number starting with 09 (e.g. 09171234567 or +639171234567).');
                    }
                    if (str_starts_with($clean_contact, '+639')) {
                        $contact_input = '09' . substr($clean_contact, 4);
                    } elseif (str_starts_with($clean_contact, '639')) {
                        $contact_input = '09' . substr($clean_contact, 3);
                    } else {
                        $contact_input = $clean_contact;
                    }
                }
                
                if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
                    throw new Exception('Invalid role selected.');
                }
                
                if ($my_role !== 'superadmin' && in_array($role, ['admin', 'superadmin'])) {
                    throw new Exception('You cannot assign Admin or Super Admin roles.');
                }

                if ($my_role !== 'superadmin') {
                    $chk = $pdo->prepare("SELECT id, station_id, role FROM users WHERE id = ? AND station_id = ?");
                    $chk->execute([$id, $my_station_id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$target_user) throw new Exception("Unauthorized access to target user.");
                    if (!can_manage_role($my_role, (string)($target_user['role'] ?? 'staff'))) {
                        throw new Exception("You cannot modify this user's role.");
                    }
                } else {
                    $chk = $pdo->prepare("SELECT id, station_id, role FROM users WHERE id = ?");
                    $chk->execute([$id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$target_user) throw new Exception("Target user not found.");
                }
                
                $target_stn = !empty($target_user['station_id']) ? (int)$target_user['station_id'] : ($my_role === 'admin' ? $my_station_id : 0);
                if ($role === 'manager' && $target_stn > 0) {
                    $checkManager = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'manager' AND station_id = ? AND id != ? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $checkManager->execute([$target_stn, $id]);
                    if ((int)$checkManager->fetchColumn() > 0) {
                        throw new Exception("Cannot assign Manager role. This station already has an active Manager (Only 1 Manager allowed per station).");
                    }
                }
                if ($role === 'admin' && $target_stn > 0) {
                    $checkAdmin = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'admin' AND station_id = ? AND id != ? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $checkAdmin->execute([$target_stn, $id]);
                    if ((int)$checkAdmin->fetchColumn() > 0) {
                        throw new Exception("Cannot assign Admin role. This station already has an active Admin (Only 1 Admin allowed per station).");
                    }
                }

                $dup_sql = 'SELECT id FROM users WHERE username = ? AND id != ?';
                $dup_params = [$username, $id];
                if (!empty($email)) { $dup_sql .= ' OR (email = ? AND id != ?)'; $dup_params[] = $email; $dup_params[] = $id; }
                $stmt = $pdo->prepare($dup_sql);
                $stmt->execute($dup_params);
                if ($stmt->fetch()) throw new Exception('This Username or Email is already registered to another account.');

                $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, role = ?, username = ?, email = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$first_name, $last_name, $role, $username, $email, $id]);

                if (in_array('phone_number', $user_cols)) {
                    $pdo->prepare("UPDATE users SET phone_number = ? WHERE id = ?")->execute([$contact_input ?: null, $id]);
                }

                if (in_array('assigned_shift', $user_cols)) {
                    $pdo->prepare("UPDATE users SET assigned_shift = ? WHERE id = ?")->execute([$assigned_shift, $id]);
                }
                if (in_array('shift_assignment', $user_cols)) {
                    $pdo->prepare("UPDATE users SET shift_assignment = ? WHERE id = ?")->execute([$assigned_shift, $id]);
                }
                
                log_activity($pdo, $me['id'], 'Edit User', "Updated details & role for user #$id ($username, $role)");
                $msg = "User details for <strong>" . htmlspecialchars($first_name . ' ' . $last_name) . "</strong> updated successfully.";
            }
            
            // 3. Reset Password
            elseif ($action === 'reset_password') {
                $id = (int)$_POST['user_id'];
                $new_pass = trim($_POST['new_password'] ?? '');
                if (empty($new_pass)) {
                    $new_pass = generateSecurePassword();
                }

                if ($my_role !== 'superadmin') {
                    $chk = $pdo->prepare("SELECT id, role, email, username, first_name, last_name, employee_id, station_id FROM users WHERE id = ? AND station_id = ?");
                    $chk->execute([$id, $my_station_id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$target_user) throw new Exception("Unauthorized access to user.");
                } else {
                    $chk = $pdo->prepare("SELECT id, role, email, username, first_name, last_name, employee_id, station_id FROM users WHERE id = ?");
                    $chk->execute([$id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                }
                
                $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashed, $id]);

                // Station name for email
                $station_name_for_email = 'Petron Service Station';
                if (!empty($target_user['station_id'])) {
                    $stn = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
                    $stn->execute([$target_user['station_id']]);
                    $stn_row = $stn->fetch(PDO::FETCH_ASSOC);
                    if ($stn_row) $station_name_for_email = $stn_row['name'];
                }

                // Automatically send reset credentials email if email is present
                $full_name_reset = trim(($target_user['first_name'] ?? '') . ' ' . ($target_user['last_name'] ?? '')) ?: $target_user['username'];
                $email_sent = false;
                if (!empty($target_user['email'])) {
                    try {
                        $email_sent = (bool)sendAdminCredentialsEmail(
                            $target_user['email'], $full_name_reset, $station_name_for_email,
                            $target_user['username'], $new_pass, $me['role'], $target_user['role'], $target_user['employee_id'] ?? ''
                        );
                    } catch (Exception $mEx) { $email_sent = false; }
                }
                
                log_activity($pdo, $me['id'], 'Reset Password', "Reset password for user #$id ({$target_user['username']})");
                
                if ($email_sent) {
                    $msg = "Password reset successfully. New temporary password has been emailed to <strong>" . htmlspecialchars($target_user['email']) . "</strong>.";
                } else {
                    $msg = "Password reset successfully. Temporary password: <strong>" . htmlspecialchars($new_pass) . "</strong>";
                }
            }
            
            // 4. Archive User (Move to Inactive/Archived state)
            elseif ($action === 'archive_user') {
                $id = (int)$_POST['user_id'];
                
                if ($id == $me['id']) throw new Exception("You cannot archive your own account.");
                
                if ($my_role !== 'superadmin') {
                    $chk = $pdo->prepare("SELECT id, role, username FROM users WHERE id = ? AND station_id = ?");
                    $chk->execute([$id, $my_station_id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$target_user) throw new Exception("Unauthorized access to user.");
                } else {
                    $chk = $pdo->prepare("SELECT id, role, username FROM users WHERE id = ?");
                    $chk->execute([$id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                }
                
                $stmt = $pdo->prepare("UPDATE users SET status = 'Archived', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                
                log_activity($pdo, $me['id'], 'Archive User', "Archived user #$id ({$target_user['username']})");
                $msg = "User <strong>" . htmlspecialchars($target_user['username']) . "</strong> has been archived. They can no longer log in, but all records are saved forever.";
            }

            // 5. Restore User (Move back to Active state)
            elseif ($action === 'restore_user') {
                $id = (int)$_POST['user_id'];
                
                if ($my_role !== 'superadmin') {
                    $chk = $pdo->prepare("SELECT id, role, station_id, username FROM users WHERE id = ? AND station_id = ?");
                    $chk->execute([$id, $my_station_id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$target_user) throw new Exception("Unauthorized access to user.");
                } else {
                    $chk = $pdo->prepare("SELECT id, role, station_id, username FROM users WHERE id = ?");
                    $chk->execute([$id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                }
                
                $target_role = strtolower(trim($target_user['role'] ?? 'staff'));
                $target_stn  = (int)($target_user['station_id'] ?? 0);

                if ($target_role === 'manager' && $target_stn > 0) {
                    $chkMgr = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'manager' AND station_id = ? AND id != ? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $chkMgr->execute([$target_stn, $id]);
                    if ((int)$chkMgr->fetchColumn() > 0) {
                        throw new Exception("Cannot restore this Manager. Station already has an active Manager (Only 1 Manager allowed per station).");
                    }
                }

                if ($target_role === 'admin' && $target_stn > 0) {
                    $chkAdm = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'admin' AND station_id = ? AND id != ? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $chkAdm->execute([$target_stn, $id]);
                    if ((int)$chkAdm->fetchColumn() > 0) {
                        throw new Exception("Cannot restore this Admin. Station already has an active Admin (Only 1 Admin allowed per station).");
                    }
                }

                $stmt = $pdo->prepare("UPDATE users SET status = 'Active', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                
                log_activity($pdo, $me['id'], 'Restore User', "Restored archived user #$id ({$target_user['username']}) to Active status");
                $msg = "User <strong>" . htmlspecialchars($target_user['username']) . "</strong> has been restored to Active status.";
            }

            // Permanent Delete Attempt Check
            elseif ($action === 'delete_user') {
                throw new Exception('User deletion is permanently disabled. Inactive accounts are archived to preserve data integrity.');
            }
            
        } catch (Exception $e) {
            $msg = $e->getMessage();
            $is_error = true;
        }
    }
}

// --- FETCH USERS FOR DISPLAY ---
$user_list_columns = "
    u.id,
    u.employee_id,
    u.first_name,
    u.last_name,
    CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS name,
    u.username,
    u.role,
    u.email,
    u.phone_number,
    u.station_id,
    u.assigned_shift,
    u.status,
    u.created_at,
    u.updated_at,
    s.name AS station_name
";

$all_users = [];
if ($my_role === 'superadmin') {
    $stmt = $pdo->query("
        SELECT {$user_list_columns}
        FROM users u
        LEFT JOIN stations s ON u.station_id = s.id
        ORDER BY u.created_at DESC
    ");
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT {$user_list_columns}
        FROM users u
        LEFT JOIN stations s ON u.station_id = s.id
        WHERE u.station_id = ?
          AND LOWER(u.role) IN ('manager', 'staff', 'operations_staff', 'operations staff')
        ORDER BY u.role, u.username
    ");
    $stmt->execute([$my_station_id]);
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Separate Active vs Archived lists
$active_users   = [];
$archived_users = [];

foreach ($all_users as $u) {
    if (is_user_archived_status($u['status'] ?? '')) {
        $archived_users[] = $u;
    } else {
        $active_users[] = $u;
    }
}

// Fetch Station Name for Header/Modal
$station_name = '';
if ($my_station_id) {
    $stn_stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $stn_stmt->execute([$my_station_id]);
    $station_name = $stn_stmt->fetchColumn() ?: 'Station #' . $my_station_id;
}

// Get UI Config
try {
    $station_ui_config = StationManager::getStationUIConfig($my_role, $my_station_id, $station_name);
} catch (Exception $e) {
    $station_ui_config = [
        'type' => 'readonly_field',
        'value' => $station_name ?: 'Unknown Station',
        'hidden_input_value' => $my_station_id,
        'readonly' => true,
        'help_text' => 'Station assignment'
    ];
}

// ── AJAX JSON POLLING ENDPOINT FOR USER MANAGEMENT ─────────────────
if (isset($_GET['ajax_um']) && $_GET['ajax_um'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'counts' => [
            'active'   => count($active_users),
            'archived' => count($archived_users),
            'total'    => count($all_users)
        ]
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* --- CLEAN MODAL OVERLAY & CONTAINER BOX (With Ample Header & Footer Space) --- */
.modal {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(15, 23, 42, 0.65) !important;
    backdrop-filter: blur(4px) !important;
    z-index: 99999 !important;
    display: none;
    align-items: center !important;
    justify-content: center !important;
    padding-top: 85px !important;   /* Clear top navbar with clean visible space */
    padding-bottom: 75px !important;/* Clear bottom footer */
    padding-left: 20px !important;
    padding-right: 20px !important;
    box-sizing: border-box !important;
}

.modal-content {
    background: #ffffff !important;
    border-radius: 14px !important;
    width: 100% !important;
    max-width: 580px !important;
    max-height: calc(100vh - 170px) !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3) !important;
    border: 1px solid #cbd5e1 !important;
    overflow: hidden !important;
    margin: auto !important;
    animation: modalSlideUp .2s ease-out;
}

@keyframes modalSlideUp {
    from { transform: translateY(14px); opacity: 0; }
    to   { transform: translateY(0); opacity: 1; }
}

@keyframes toastSlideIn {
    from { transform: translateX(50px); opacity: 0; }
    to   { transform: translateX(0); opacity: 1; }
}

.modal-header {
    background: #f8fafc !important;
    border-bottom: 1px solid #e2e8f0 !important;
    padding: 14px 20px !important;
    flex-shrink: 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
}

.modal-title {
    color: #002F70 !important;
    font-weight: 800 !important;
    font-size: 15px !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

.modal-close {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    width: 28px !important;
    height: 28px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 18px !important;
    color: #64748b !important;
    cursor: pointer !important;
    line-height: 1 !important;
    padding: 0 !important;
    transition: all 0.15s !important;
}
.modal-close:hover {
    background: #fee2e2 !important;
    color: #dc2626 !important;
    border-color: #fca5a5 !important;
}

.modal-body {
    padding: 20px 22px !important;
    overflow-y: auto !important;
    flex: 1 !important;
    min-height: 0 !important;
}

.modal-footer {
    background: #f8fafc !important;
    border-top: 1px solid #f1f5f9 !important;
    padding: 12px 20px !important;
    flex-shrink: 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
}

/* --- FORM & INPUT STYLES --- */
.form-section-title {
    font-size: 11px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.6px !important;
    color: #002F70 !important;
    margin: 16px 0 10px !important;
    padding-bottom: 4px !important;
    border-bottom: 1px solid #e2e8f0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.form-section-title:first-child {
    margin-top: 0 !important;
}

.form-grid-2 {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 12px !important;
    margin-bottom: 10px !important;
}
@media(max-width: 520px) {
    .form-grid-2 { grid-template-columns: 1fr !important; }
}

.form-group {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
    margin-bottom: 10px !important;
}

.form-group .lbl,
.lbl {
    font-size: 10.5px !important;
    font-weight: 700 !important;
    color: #475569 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.4px !important;
    margin-bottom: 2px !important;
}

.form-group input.inp,
.form-group select.inp,
.inp {
    height: 38px !important;
    padding: 0 12px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    color: #1e293b !important;
    background: #ffffff !important;
    outline: none !important;
    width: 100% !important;
    box-sizing: border-box !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}

.form-group input.inp:focus,
.form-group select.inp:focus,
.inp:focus {
    border-color: #002F70 !important;
    box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1) !important;
}

.btn-dice {
    height: 38px !important;
    width: 38px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    background: #f8fafc !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    color: #002F70 !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    transition: all 0.15s !important;
}
.btn-dice:hover {
    background: #002F70 !important;
    color: #ffffff !important;
    border-color: #002F70 !important;
}

/* --- BUTTON STYLES --- */
.btn-plain-cancel {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #334155 !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    padding: 0 16px !important;
    height: 36px !important;
    border-radius: 7px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: all 0.15s !important;
}
.btn-plain-cancel:hover {
    background: #f8fafc !important;
    color: #0f172a !important;
    border-color: #94a3b8 !important;
}

.btn-header-add {
    background: #002F70 !important;
    background-color: #002F70 !important;
    color: #ffffff !important;
    border: 1px solid #002F70 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    padding: 0 18px !important;
    height: 36px !important;
    border-radius: 7px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all .15s ease-in-out !important;
    white-space: nowrap !important;
    box-shadow: 0 2px 5px rgba(0,47,112,0.2) !important;
    text-decoration: none !important;
}
.btn-header-add i,
.btn-header-add span {
    color: #ffffff !important;
}
.btn-header-add:hover {
    background: #001f4d !important;
    background-color: #001f4d !important;
    color: #ffffff !important;
    border-color: #001f4d !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 8px rgba(0,47,112,0.3) !important;
}

.btn-plain-submit {
    background: #002F70 !important;
    background-color: #002F70 !important;
    border: 1px solid #002F70 !important;
    color: #ffffff !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    padding: 9px 20px !important;
    border-radius: 7px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    transition: all 0.15s !important;
}
.btn-plain-submit i,
.btn-plain-submit span {
    color: #ffffff !important;
}
.btn-plain-submit:hover {
    background: #001f4d !important;
    background-color: #001f4d !important;
    color: #ffffff !important;
    border-color: #001f4d !important;
}

.btn-plain-danger {
    background: transparent !important;
    border: 1px solid #dc3545 !important;
    color: #dc3545 !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    padding: 8px 18px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: all 0.2s !important;
}
.btn-plain-danger:hover {
    background: #dc3545 !important;
    color: #ffffff !important;
}

.btn-plain-success {
    background: transparent !important;
    border: 1px solid #16a34a !important;
    color: #16a34a !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    padding: 8px 18px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: all 0.2s !important;
}
.btn-plain-success:hover {
    background: #16a34a !important;
    color: #ffffff !important;
}

/* Tabs - Reports-style boxed design */
.um-tabs {
    display: flex !important; flex-wrap: wrap !important;
    margin-bottom: 22px !important;
    border: 1px solid #d1d9e6 !important; border-radius: 0 !important;
    overflow: hidden !important; border-bottom: 3px solid #00264D !important;
    gap: 0 !important; background: transparent !important;
    padding: 0 !important; width: 100% !important;
}
.um-tab-btn {
    flex: 1 !important; min-width: 140px !important;
    padding: 12px 16px !important; font-size: 11.5px !important; font-weight: 700 !important;
    color: #334155 !important; background: #ffffff !important;
    border: none !important; border-right: 1px solid #d1d9e6 !important;
    border-radius: 0 !important; text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important; align-items: center !important;
    justify-content: center !important; gap: 7px !important;
    text-transform: uppercase !important; letter-spacing: 0.3px !important;
    text-align: center !important; cursor: pointer !important;
    margin-bottom: 0 !important; box-shadow: none !important;
}
.um-tab-btn:last-child { border-right: none !important; }
.um-tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.um-tab-btn.active {
    background: #00264D !important; color: #ffffff !important;
    font-weight: 800 !important; box-shadow: none !important;
}
.um-badge-cnt {
    background: #dc2626 !important;
    color: #ffffff !important;
    padding: 2px 7px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}
/* Table Action Buttons: ALL PLAIN OUTLINE (No filled background color) */
.action-btn,
.btn-archive,
.btn-restore {
    border-radius: 4px !important;
    padding: 5px 12px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all .2s !important;
    background: transparent !important;
    box-shadow: none !important;
    width: 80px !important;
    text-align: center !important;
}

/* <i class="fas fa-eye"></i> View Button */
.btn-view,
.action-btn.btn-view {
    border: 1px solid #16a34a !important;
    color: #16a34a !important;
    background: transparent !important;
}
.btn-view:hover,
.action-btn.btn-view:hover {
    background: #16a34a !important;
    color: #ffffff !important;
}

/* <i class="fas fa-pencil-alt"></i>️ Edit Button */
.btn-edit,
.action-btn.btn-edit {
    border: 1px solid #16a34a !important;
    color: #16a34a !important;
    background: transparent !important;
}
.btn-edit:hover,
.action-btn.btn-edit:hover {
    background: #16a34a !important;
    color: #ffffff !important;
}

/* <i class="fas fa-key"></i> Reset Button */
.btn-reset,
.action-btn.btn-reset {
    border: 1px solid #00264D !important;
    color: #00264D !important;
    background: transparent !important;
}
.btn-reset:hover,
.action-btn.btn-reset:hover {
    background: #00264D !important;
    color: #ffffff !important;
}

/* <i class="fas fa-box"></i> Archive Button */
.btn-archive,
.action-btn.btn-archive {
    border: 1px solid #dc3545 !important;
    color: #dc3545 !important;
    background: transparent !important;
}
.btn-archive:hover,
.action-btn.btn-archive:hover {
    background: #dc3545 !important;
    color: #ffffff !important;
}

.btn-restore:hover,
.action-btn.btn-restore:hover {
    background: #16a34a !important;
    color: #ffffff !important;
}

.page-head {
    display:flex; justify-content:space-between; gap:16px; align-items:center;
    margin-top:0 !important; margin-bottom:25px !important;
    padding:0 !important; border:none !important; width:100%;
}
.page-head h1, .page-head .h1 {
    margin:0; color:#002f70 !important; font-size:24px !important;
    font-weight:700 !important; text-transform:uppercase !important;
    letter-spacing:0.5px !important;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif !important;
    display:flex !important; align-items:center !important; gap:10px !important; line-height:1.2 !important;
}
.um-wrap {
    padding: 0 !important;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}
</style>

<div class="um-wrap">
<div class="page-head">
    <div>
        <h1 class="h1">USER MANAGEMENT</h1>
    </div>
    <?php if ($my_role === 'admin' || $my_role === 'superadmin'): ?>
    <div class="actions">
        <button type="button" onclick="openAddModal()" class="btn-header-add">
            <i class="fas fa-plus"></i> <span>Add New User</span>
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($msg): ?>
<!-- Floating Top-Right Toast Notification (Clear of Navbar) -->
<div id="floatingToastMsg" style="position: fixed; top: 82px; right: 24px; z-index: 100002; max-width: 450px; background: #ffffff; border: 1.5px solid <?php echo $is_error ? '#fca5a5' : '#86efac'; ?>; border-radius: 10px; box-shadow: 0 12px 35px rgba(0,0,0,0.18); padding: 14px 18px; display: flex; align-items: flex-start; gap: 12px; animation: toastSlideIn .3s ease-out;">
    <div style="font-size: 20px; color: <?php echo $is_error ? '#dc2626' : '#16a34a'; ?>; line-height: 1; flex-shrink: 0; margin-top: 2px;">
        <i class="fas <?php echo $is_error ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
    </div>
    <div style="flex: 1; font-size: 13px; line-height: 1.5; color: <?php echo $is_error ? '#991b1b' : '#166534'; ?>;">
        <div style="font-weight: 800; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px; color: <?php echo $is_error ? '#dc2626' : '#16a34a'; ?>;">
            <?php echo $is_error ? 'System Notice / Error' : 'Success Notification'; ?>
        </div>
        <?php echo $msg; ?>
    </div>
</div>
<script>
setTimeout(function() {
    var toast = document.getElementById('floatingToastMsg');
    if (toast) {
        toast.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(function() { toast.remove(); }, 500);
    }
}, 8000);
</script>
<?php endif; ?>

<!-- DUAL TAB NAVIGATION -->
<div class="um-tabs">
    <a href="users.php?tab=active" class="um-tab-btn <?php echo $current_tab === 'active' ? 'active' : ''; ?>">
        <i class="fas fa-user-check"></i> Active Users <span class="um-badge-cnt" id="um_badge_active"><?php echo count($active_users); ?></span>
    </a>
    <a href="users.php?tab=archived" class="um-tab-btn <?php echo $current_tab === 'archived' ? 'active' : ''; ?>">
        <i class="fas fa-archive"></i> Archived Users <span class="um-badge-cnt" id="um_badge_archived"><?php echo count($archived_users); ?></span>
    </a>
</div>

<?php if ($current_tab === 'active' || $current_tab === 'archived'): ?>
    <?php 
    $display_list = ($current_tab === 'active') ? $active_users : $archived_users; 
    ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>EMPLOYEE ID</th>
                        <th>NAME</th>
                        <th>USERNAME</th>
                        <th>ROLE</th>
                        <th>ASSIGNED SHIFT</th>
                        <?php if($my_role === 'superadmin'): ?><th>STATION</th><?php endif; ?>
                        <th>STATUS</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($display_list as $u): 
                        $isArchived  = is_user_archived_status($u['status'] ?? '');
                        $rawStatus   = strtolower(trim($u['status'] ?? 'active'));
                        $statusLabel = ucfirst($rawStatus);
                        if ($rawStatus === 'active') {
                            $statusStyle = 'background:#16a34a!important;color:#fff;font-weight:700;padding:3px 10px;border-radius:6px;font-size:12px;display:inline-block;';
                        } elseif ($rawStatus === 'inactive') {
                            $statusStyle = 'background:#dc2626!important;color:#fff;font-weight:700;padding:3px 10px;border-radius:6px;font-size:12px;display:inline-block;';
                        } elseif (in_array($rawStatus, ['archived','disabled','locked'], true)) {
                            $statusStyle = 'background:#dc2626!important;color:#fff;font-weight:700;padding:3px 10px;border-radius:6px;font-size:12px;display:inline-block;';
                        } else {
                            $statusStyle = 'background:#64748b!important;color:#fff;font-weight:700;padding:3px 10px;border-radius:6px;font-size:12px;display:inline-block;';
                        }
                        $roleKey     = role_key($u['role'] ?? 'staff');
                        $roleLabel   = normalize_role($u['role'] ?? $roleKey);
                        if ($roleLabel === '') { $roleLabel = ucfirst($roleKey); }
                        $roleClass   = in_array($roleKey, ['manager','admin','superadmin'], true) ? 'primary' : 'secondary';
                        
                        $fullName = trim(($u['name'] ?? '') ?: (($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')));
                        if (empty($fullName)) $fullName = $u['username'] ?? 'Unknown';
                    ?>
                    <tr>
                        <td style="font-family: monospace; font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($u['employee_id'] ?? '—'); ?></td>
                        <td>
                            <div style="font-weight:700; color:#0f172a;"><?php echo htmlspecialchars($fullName); ?></div>
                            <div class="muted" style="font-size:0.85em; color:#64748b;"><?php echo htmlspecialchars($u['email'] ?? ''); ?></div>
                        </td>
                        <td style="font-weight: 500; color: #475569;">@<?php echo htmlspecialchars($u['username'] ?? '—'); ?></td>
                        <td style="font-weight:600; color:#334155;"><?php echo htmlspecialchars($roleLabel); ?></td>
                        <td>
                            <?php if ($roleKey === 'staff'): ?>
                                <span style="font-weight:600; color:#334155; font-size:13px;"><?php echo htmlspecialchars($u['assigned_shift'] ?? 'Unassigned'); ?></span>
                            <?php else: ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if($my_role === 'superadmin'): ?>
                            <td><?php echo htmlspecialchars($u['station_name'] ?? 'Unassigned'); ?></td>
                        <?php endif; ?>
                        <td>
                            <span style="<?php echo $statusStyle; ?>">
                                <?php echo htmlspecialchars($statusLabel); ?>
                            </span>
                        </td>

                        <td>
                            <div style="display:flex; flex-direction:column; gap:5px; align-items:center;">

                                <!-- ADMIN / SUPERADMIN CONTROLS -->
                                <?php if ($my_role === 'admin' || $my_role === 'superadmin'): ?>
                                    
                                    <?php if (!$isArchived): ?>
                                        <!-- Active Tab Controls -->
                                        <button class="action-btn btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="Edit User">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="action-btn btn-reset" onclick="openResetModal(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['username'])); ?>')" title="Reset Password">
                                            <i class="fas fa-key"></i> Reset
                                        </button>
                                        <?php if ($u['id'] != $me['id']): ?>
                                            <button class="btn-archive" onclick="openArchiveModal(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($fullName)); ?>')" title="Archive User">
                                                <i class="fas fa-archive"></i> Archive
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Archived Tab Controls -->
                                        <button class="btn-restore" onclick="openRestoreModal(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($fullName)); ?>')" title="Restore Account">
                                            <i class="fas fa-undo"></i> Restore
                                        </button>
                                    <?php endif; ?>

                                <?php endif; ?>

                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($display_list)): ?>
                        <tr>
                            <td colspan="<?php echo $my_role === 'superadmin' ? 8 : 7; ?>" style="text-align:center; padding:30px; color:#64748b;">
                                <i class="fas fa-inbox" style="font-size: 24px; margin-bottom: 8px; display:block; color:#94a3b8;"></i>
                                No <?php echo $current_tab === 'active' ? 'active' : 'archived'; ?> users found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>
</div><!-- /.um-wrap -->

<!-- MODAL: Add User -->
<div class="modal" id="addModal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-user-plus"></i> Add New User</span>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="post" id="addUserForm" data-draft-module="user_creation_form" onsubmit="return validateAddForm();" autocomplete="off" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_user">

                <div class="form-section-title"><i class="fas fa-id-card"></i> Personal Details</div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="first_name" id="add_first_name" class="inp" required placeholder="e.g. Judy" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="last_name" id="add_last_name" class="inp" required placeholder="e.g. Lastimosa" autocomplete="off">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">Contact Number <span class="muted">(PH Format)</span></label>
                        <input type="tel" name="contact_number" id="add_contact_number" class="inp"
                               placeholder="e.g. 0917xxxxxxx" maxlength="13"
                               oninput="validatePhoneRealtime(this, 'add_phone_hint')"
                               autocomplete="off">
                        <small id="add_phone_hint" style="font-size: 11px; color: #64748b; margin-top: 2px; display: block;">Format: 09XXXXXXXXX</small>
                    </div>
                    <div class="form-group">
                        <label class="lbl">Email Address <span style="color:#dc2626;">*</span></label>
                        <input type="email" name="email" id="add_email" class="inp" required placeholder="e.g. judy@email.com" autocomplete="new-password">
                    </div>
                </div>

                <div class="form-section-title"><i class="fas fa-shield-alt"></i> Account & Role</div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">Username <span class="muted">(optional)</span></label>
                        <input type="text" name="username" id="add_username" class="inp" placeholder="e.g. judy.lastimosa" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Role <span style="color:#dc2626;">*</span></label>
                        <select name="role" id="user_role_add" class="inp" required onchange="toggleShiftField('add')">
                            <option value="">Select role</option>
                            <?php if ($my_role === 'superadmin'): ?>
                                <option value="staff">Staff</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Admin</option>
                            <?php elseif ($my_role === 'admin'): ?>
                                <option value="staff">Staff</option>
                                <option value="manager">Manager</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2" id="dynamic_role_fields_add">
                    <div class="form-group" id="shift_field_group_add" style="display: none !important;" hidden>
                        <label class="lbl">Assigned Shift <span style="color:#dc2626;">*</span></label>
                        <select name="assigned_shift" id="add_assigned_shift" class="inp">
                            <option value="">Select shift</option>
                            <option value="Shift 1">Shift 1 (6:00 AM – 2:00 PM)</option>
                            <option value="Shift 2">Shift 2 (2:00 PM – 12:00 AM)</option>
                        </select>
                    </div>
                    <?php if ($my_role === 'superadmin'): ?>
                    <div class="form-group" id="station_field_group_add">
                        <label class="lbl">Station Assignment <span style="color:#dc2626;">*</span></label>
                        <select name="station_id" id="add_station_id" class="inp" required>
                            <option value="">Select station</option>
                            <?php 
                            $stns = $pdo->query("SELECT id, name FROM stations WHERE status='active' ORDER BY name")->fetchAll();
                            foreach($stns as $stn) {
                                echo '<option value="' . $stn['id'] . '">' . htmlspecialchars($stn['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="form-section-title"><i class="fas fa-lock"></i> Temporary Password</div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">Password <span class="muted">(auto if empty)</span></label>
                        <div style="display: flex; gap: 6px; align-items: center;">
                            <input type="text" name="new_password" id="new_password" class="inp" placeholder="Leave empty to generate" autocomplete="new-password">
                            <button type="button" class="btn-dice" onclick="generateSimplePassword()" title="Generate secure password">
                                <i class="fas fa-dice"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="lbl">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="inp" placeholder="Re-enter password" autocomplete="new-password">
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" id="btnSubmitAddUser" class="btn-header-add" style="height:36px;padding:0 18px;"><i class="fas fa-paper-plane"></i> <span>Create & Send Credentials</span></button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Edit User -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-user-edit"></i> Edit User Details</span>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="post" onsubmit="return validateEditForm();" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-section-title"><i class="fas fa-id-card"></i> Personal Details</div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="first_name" id="edit_first_name" class="inp" required placeholder="e.g. Judy">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="last_name" id="edit_last_name" class="inp" required placeholder="e.g. Lastimosa">
                    </div>
                </div>
                
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">Login ID / Email <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="login_id" id="edit_login_id" class="inp" required placeholder="Email or Username">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Contact Number <span class="muted">(PH format)</span></label>
                        <input type="tel" name="contact_number" id="edit_contact_number" class="inp"
                               placeholder="e.g. 0917xxxxxxx" maxlength="13"
                               oninput="validatePhoneRealtime(this, 'edit_phone_hint')"
                               autocomplete="off">
                        <small id="edit_phone_hint" style="font-size: 11px; color: #64748b; margin-top: 2px; display: block;">Format: 09XXXXXXXXX</small>
                    </div>
                </div>

                <div class="form-section-title"><i class="fas fa-shield-alt"></i> Account & Role</div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">Role <span style="color:#dc2626;">*</span></label>
                        <select name="role" id="user_role_edit" class="inp" required onchange="toggleShiftField('edit')">
                            <?php if ($my_role === 'superadmin'): ?>
                                <option value="staff">Staff</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Admin</option>
                            <?php elseif ($my_role === 'admin'): ?>
                                <option value="staff">Staff</option>
                                <option value="manager">Manager</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group" id="shift_field_group_edit" style="display: none !important;" hidden>
                        <label class="lbl">Assigned Shift <span style="color:#dc2626;">*</span></label>
                        <select name="assigned_shift" id="edit_assigned_shift" class="inp">
                            <option value="">Select shift</option>
                            <option value="Shift 1">Shift 1 (6:00 AM – 2:00 PM)</option>
                            <option value="Shift 2">Shift 2 (2:00 PM – 12:00 AM)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-header-add" style="height:36px;padding:0 18px;"><i class="fas fa-save"></i> <span>Save Changes</span></button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Reset Password -->
<div class="modal" id="resetModal">
    <div class="modal-content" style="max-width: 440px;">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-key"></i> Reset Password</span>
            <button class="modal-close" onclick="closeModal('resetModal')">&times;</button>
        </div>
        <form method="post" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
            <div class="modal-body">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset_user_id">
                <p style="font-size:13.5px; color:#334155; margin-bottom:14px;">Reset password for user <strong id="reset_username" style="color:#002F70;"></strong>?</p>
                <div class="form-group">
                    <label class="lbl">New Password <span class="muted">(optional)</span></label>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <input type="text" name="new_password" id="reset_password_field" class="inp" placeholder="Auto-generate if empty">
                        <button type="button" class="btn-dice" onclick="generateResetPassword()" title="Generate password">
                            <i class="fas fa-dice"></i>
                        </button>
                    </div>
                    <small style="font-size:11px; color:#64748b; margin-top:4px; display:block;">Leave empty to auto-generate a secure password. Credentials will be sent via email.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain-cancel" onclick="closeModal('resetModal')">Cancel</button>
                <button type="submit" class="btn-header-add" style="height:36px;padding:0 18px;"><i class="fas fa-key"></i> <span>Reset Password</span></button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Archive Confirmation -->
<div class="modal" id="archiveModal">
    <div class="modal-content" style="max-width: 440px;">
        <div class="modal-header">
            <span class="modal-title" style="color:#dc2626!important;"><i class="fas fa-archive"></i> Archive User</span>
            <button class="modal-close" onclick="closeModal('archiveModal')">&times;</button>
        </div>
        <form method="post" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
            <div class="modal-body">
                <input type="hidden" name="action" value="archive_user">
                <input type="hidden" name="user_id" id="archive_user_id">
                <p style="font-size:13.5px; color:#334155; margin-bottom:12px;">Are you sure you want to archive user <strong id="archive_user_name" style="color:#0f172a;"></strong>?</p>
                <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 12px; font-size:12px; color:#991b1b; display:flex; gap:8px; align-items:flex-start;">
                    <i class="fas fa-info-circle" style="margin-top:2px;"></i>
                    <span>This user will no longer be able to log in. All activity records and history will be saved forever (No permanent deletion). You can restore this account anytime.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain-cancel" onclick="closeModal('archiveModal')">Cancel</button>
                <button type="submit" class="btn-plain-danger"><i class="fas fa-archive"></i> Yes, Archive User</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Restore Confirmation -->
<div class="modal" id="restoreModal">
    <div class="modal-content" style="max-width: 440px;">
        <div class="modal-header">
            <span class="modal-title" style="color:#16a34a!important;"><i class="fas fa-undo"></i> Restore User</span>
            <button class="modal-close" onclick="closeModal('restoreModal')">&times;</button>
        </div>
        <form method="post" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
            <div class="modal-body">
                <input type="hidden" name="action" value="restore_user">
                <input type="hidden" name="user_id" id="restore_user_id">
                <p style="font-size:13.5px; color:#334155; margin-bottom:12px;">Bring <strong id="restore_user_name" style="color:#0f172a;"></strong> back to Active status?</p>
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 12px; font-size:12px; color:#166534;">
                    <i class="fas fa-check-circle"></i> Once restored, this user will be able to log in and access their account again.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain-cancel" onclick="closeModal('restoreModal')">Cancel</button>
                <button type="submit" class="btn-plain-success"><i class="fas fa-undo"></i> Yes, Restore Account</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: View Profile -->
<div class="modal" id="viewModal">
    <div class="modal-content" style="max-width: 480px;">
        <div class="modal-header">
            <span class="modal-title" id="view_modal_title"><i class="fas fa-user-circle"></i> User Profile</span>
            <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="modal-body">

            <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #e2e8f0;">
                <div id="view_avatar" style="width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#fff;flex-shrink:0;background:#002F70;"></div>
                <div>
                    <div id="view_name" style="font-size:16px;font-weight:700;color:#0f172a;"></div>
                    <div id="view_username" style="font-size:12px;color:#64748b;margin-top:2px;"></div>
                    <div id="view_role_badge" style="margin-top:5px;"></div>
                </div>
            </div>

            <div class="form-grid-2" style="gap: 14px;">
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                    <div style="font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Employee ID</div>
                    <div id="view_employee_id" style="font-size:13px;color:#002F70;font-weight:700;font-family:monospace;"></div>
                </div>
                <div id="view_shift_container" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; display: none;">
                    <div style="font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Assigned Shift</div>
                    <div id="view_assigned_shift" style="font-size:13px;color:#0f172a;font-weight:600;"></div>
                </div>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                    <div style="font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Email</div>
                    <div id="view_email" style="font-size:13px;color:#0f172a;word-break:break-all;"></div>
                </div>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                    <div style="font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Contact Number</div>
                    <div id="view_contact_number" style="font-size:13px;color:#0f172a;font-weight:600;"></div>
                </div>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; grid-column: 1 / -1;">
                    <div style="font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Account Status</div>
                    <div id="view_status"></div>
                </div>
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn-plain-cancel" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
function toggleShiftField(context) {
    const roleSelect = document.getElementById(context === 'add' ? 'user_role_add' : 'user_role_edit');
    const shiftGroup = document.getElementById(context === 'add' ? 'shift_field_group_add' : 'shift_field_group_edit');
    const shiftInput = document.getElementById(context === 'add' ? 'add_assigned_shift' : 'edit_assigned_shift');
    const selectedRole = roleSelect ? (roleSelect.value || '').toLowerCase().trim() : '';

    if (shiftGroup) {
        if (selectedRole === 'staff') {
            shiftGroup.style.setProperty('display', 'block', 'important');
            shiftGroup.removeAttribute('hidden');
            if (shiftInput) shiftInput.required = true;
        } else {
            shiftGroup.style.setProperty('display', 'none', 'important');
            shiftGroup.setAttribute('hidden', 'hidden');
            if (shiftInput) {
                shiftInput.required = false;
                shiftInput.value = '';
            }
        }
    }
}

function validatePhoneRealtime(input, hintId) {
    input.value = input.value.replace(/[^0-9+\s\-]/g, '');
    const val = input.value.replace(/[\s\-]/g, '');
    const hint = document.getElementById(hintId);
    if (!hint) return;

    if (val === '') {
        hint.style.color = '#64748b';
        hint.textContent = 'Format: 11-digit PH mobile number starting with 09 (e.g. 0917xxxxxxx) or +639';
        input.style.borderColor = '#cbd5e1';
        return;
    }

    const isValid = /^(09\d{9}|\+639\d{9}|639\d{9})$/.test(val);
    if (isValid) {
        hint.style.color = '#16a34a';
        hint.innerHTML = '<i class="fas fa-check-circle"></i> Valid Philippine mobile number';
        input.style.borderColor = '#16a34a';
    } else {
        hint.style.color = '#dc2626';
        hint.innerHTML = '<i class="fas fa-exclamation-circle"></i> Must be 11 digits starting with 09 (e.g. 09171234567) or +639';
        input.style.borderColor = '#dc2626';
    }
}

function isValidPhilippineNumber(val) {
    if (!val) return true;
    const clean = val.replace(/[\s\-\(\)\.]/g, '');
    if (clean === '') return true;
    return /^(09\d{9}|\+639\d{9}|639\d{9})$/.test(clean);
}

function generateSimplePassword() {
    const upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower   = 'abcdefghijkmnopqrstuvwxyz';
    const digits  = '23456789';
    const symbols = '!@#$%^&*';
    const all     = upper + lower + digits + symbols;

    let passArr = [
        upper.charAt(Math.floor(Math.random() * upper.length)),
        lower.charAt(Math.floor(Math.random() * lower.length)),
        digits.charAt(Math.floor(Math.random() * digits.length)),
        symbols.charAt(Math.floor(Math.random() * symbols.length))
    ];

    for (let i = 0; i < 6; i++) {
        passArr.push(all.charAt(Math.floor(Math.random() * all.length)));
    }

    for (let i = passArr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [passArr[i], passArr[j]] = [passArr[j], passArr[i]];
    }

    const pass = passArr.join('');
    const newPassInp = document.getElementById('new_password');
    const confPassInp = document.getElementById('confirm_password');
    if (newPassInp) newPassInp.value = pass;
    if (confPassInp) confPassInp.value = pass;
}

function generateResetPassword() {
    const upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower   = 'abcdefghijkmnopqrstuvwxyz';
    const digits  = '23456789';
    const symbols = '!@#$%^&*';
    const all     = upper + lower + digits + symbols;

    let passArr = [
        upper.charAt(Math.floor(Math.random() * upper.length)),
        lower.charAt(Math.floor(Math.random() * lower.length)),
        digits.charAt(Math.floor(Math.random() * digits.length)),
        symbols.charAt(Math.floor(Math.random() * symbols.length))
    ];

    for (let i = 0; i < 6; i++) {
        passArr.push(all.charAt(Math.floor(Math.random() * all.length)));
    }

    for (let i = passArr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [passArr[i], passArr[j]] = [passArr[j], passArr[i]];
    }

    const pass = passArr.join('');
    const resetInp = document.getElementById('reset_password_field');
    if (resetInp) resetInp.value = pass;
}

function validateAddForm() {
    const roleVal = (document.getElementById('user_role_add').value || '').toLowerCase().trim();
    const shiftVal = (document.getElementById('add_assigned_shift').value || '').trim();
    if (roleVal === 'staff' && shiftVal === '') {
        alert('Please select an Assigned Shift for the staff member.');
        document.getElementById('add_assigned_shift').focus();
        return false;
    }

    const contact = document.getElementById('add_contact_number').value.trim();
    if (contact !== '' && !isValidPhilippineNumber(contact)) {
        alert('Invalid Contact Number: Please enter a valid Philippine mobile number.\n\nExample formats:\n• 09171234567 (11 digits)\n• +639171234567');
        document.getElementById('add_contact_number').focus();
        return false;
    }

    const password = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;

    if (password === '' && confirmPassword === '') {
        const submitBtn = document.getElementById('btnSubmitAddUser');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Creating & Sending...</span>';
        }
        return true;
    }

    if (password !== '' && confirmPassword === '') {
        alert('Please re-enter the password in Confirm Password to verify.');
        return false;
    }

    if (password !== confirmPassword) {
        alert('Passwords do not match. Please ensure both passwords are identical.');
        return false;
    }

    const submitBtn = document.getElementById('btnSubmitAddUser');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Creating & Sending...</span>';
    }

    return true;
}

function validateEditForm() {
    const roleVal = (document.getElementById('user_role_edit').value || '').toLowerCase().trim();
    const shiftVal = (document.getElementById('edit_assigned_shift').value || '').trim();
    if (roleVal === 'staff' && shiftVal === '') {
        alert('Please select an Assigned Shift for the staff member.');
        document.getElementById('edit_assigned_shift').focus();
        return false;
    }

    const contact = document.getElementById('edit_contact_number').value.trim();
    if (contact !== '' && !isValidPhilippineNumber(contact)) {
        alert('Invalid Contact Number: Please enter a valid Philippine mobile number.\n\nExample formats:\n• 09171234567 (11 digits)\n• +639171234567');
        document.getElementById('edit_contact_number').focus();
        return false;
    }
    return true;
}

function clearAddUserForm() {
    const form = document.getElementById('addUserForm');
    if (form) form.reset();

    const ids = [
        'add_first_name', 'add_last_name', 'add_contact_number',
        'add_email', 'add_username', 'user_role_add',
        'add_assigned_shift', 'add_station_id', 'new_password', 'confirm_password'
    ];
    ids.forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.value = '';
            el.style.borderColor = '#cbd5e1';
        }
    });

    const hint = document.getElementById('add_phone_hint');
    if (hint) {
        hint.style.color = '#64748b';
        hint.textContent = 'Format: 11-digit PH mobile number starting with 09 (e.g. 0917xxxxxxx) or +639';
    }

    const shiftGroup = document.getElementById('shift_field_group_add');
    if (shiftGroup) shiftGroup.style.display = 'none';
}

function openAddModal() {
    clearAddUserForm();
    toggleShiftField('add');
    document.getElementById('addModal').style.display = 'flex';
    setTimeout(function() {
        clearAddUserForm();
        toggleShiftField('add');
    }, 50);
    setTimeout(function() {
        toggleShiftField('add');
    }, 150);
}

function openEditModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_first_name').value = (user.first_name || '').trim();
    document.getElementById('edit_last_name').value = (user.last_name || '').trim();
    document.getElementById('edit_login_id').value = user.email || user.username || '';
    
    const contactInp = document.getElementById('edit_contact_number');
    if (contactInp) {
        contactInp.value = user.phone_number || '';
        validatePhoneRealtime(contactInp, 'edit_phone_hint');
    }

    var roleSel = document.getElementById('user_role_edit');
    if (roleSel) {
        roleSel.value = (user.role || 'staff').toLowerCase();
    }
    
    var shiftSel = document.getElementById('edit_assigned_shift');
    if (shiftSel) {
        shiftSel.value = user.assigned_shift || '';
    }
    
    toggleShiftField('edit');
    document.getElementById('editModal').style.display = 'flex';
}

function openResetModal(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username').innerText = username;
    document.getElementById('reset_password_field').value = '';
    document.getElementById('resetModal').style.display = 'flex';
}

function openArchiveModal(userId, fullName) {
    document.getElementById('archive_user_id').value = userId;
    document.getElementById('archive_user_name').innerText = fullName;
    document.getElementById('archiveModal').style.display = 'flex';
}

function openRestoreModal(userId, fullName) {
    document.getElementById('restore_user_id').value = userId;
    document.getElementById('restore_user_name').innerText = fullName;
    document.getElementById('restoreModal').style.display = 'flex';
}

function openViewModal(user) {
    var fullName = (user.name || '').trim();
    if (!fullName) {
        fullName = ((user.first_name || '') + ' ' + (user.last_name || '')).trim();
    }
    document.getElementById('view_name').innerText = fullName;
    document.getElementById('view_username').innerText = '@' + (user.username || '—');
    document.getElementById('view_employee_id').innerText = user.employee_id || '—';
    
    var roleKey = (user.role || 'staff').toLowerCase();
    var shiftCont = document.getElementById('view_shift_container');
    if (shiftCont) {
        if (roleKey === 'staff') {
            shiftCont.style.display = 'block';
            document.getElementById('view_assigned_shift').innerText = user.assigned_shift || 'Unassigned';
        } else {
            shiftCont.style.display = 'none';
        }
    }
    
    document.getElementById('view_email').innerText = user.email || 'Not set';
    
    const viewPhone = document.getElementById('view_contact_number');
    if (viewPhone) {
        viewPhone.innerText = user.phone_number || 'Not set';
    }

    var roleStr = (user.role || 'staff').toUpperCase();
    document.getElementById('view_role_badge').innerHTML = '<span class="badge bg-primary">' + roleStr + '</span>';
    
    var rawStatus = (user.status || 'active').toLowerCase().trim();
    var statusLabel = rawStatus.charAt(0).toUpperCase() + rawStatus.slice(1);
    var statusStyle;
    if (rawStatus === 'active') {
        statusStyle = 'background:#16a34a;color:#fff;font-weight:700;padding:3px 10px;border-radius:6px;font-size:12px;display:inline-block;';
    } else if (['inactive','archived','disabled','locked'].indexOf(rawStatus) !== -1) {
        statusStyle = 'background:#dc2626;color:#fff;font-weight:700;padding:3px 10px;border-radius:6px;font-size:12px;display:inline-block;';
    } else {
        statusStyle = 'background:#64748b;color:#fff;font-weight:700;padding:3px 10px;border-radius:6px;font-size:12px;display:inline-block;';
    }
    document.getElementById('view_status').innerHTML = '<span style="' + statusStyle + '">' + statusLabel + '</span>';

    var initial = fullName.charAt(0).toUpperCase();
    document.getElementById('view_avatar').innerText = initial || 'U';

    document.getElementById('viewModal').style.display = 'flex';
}

function closeModal(modalId) {
    if (modalId === 'addModal') {
        clearAddUserForm();
    }
    document.getElementById(modalId).style.display = 'none';
}

window.onclick = function(event) {
    var modals = ['addModal', 'editModal', 'resetModal', 'archiveModal', 'restoreModal', 'viewModal'];
    modals.forEach(function(m) {
        var el = document.getElementById(m);
        if (event.target == el) {
            el.style.display = 'none';
        }
    });
};

document.addEventListener('DOMContentLoaded', function() {
    var roleAdd = document.getElementById('user_role_add');
    if (roleAdd) {
        roleAdd.addEventListener('change', function() { toggleShiftField('add'); });
        roleAdd.addEventListener('input', function() { toggleShiftField('add'); });
    }
    var roleEdit = document.getElementById('user_role_edit');
    if (roleEdit) {
        roleEdit.addEventListener('change', function() { toggleShiftField('edit'); });
        roleEdit.addEventListener('input', function() { toggleShiftField('edit'); });
    }
    toggleShiftField('add');
    toggleShiftField('edit');
});
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
let lastUmTotalCount = null;
function autoRefreshUserManagement() {
    // Pause polling if any modal is open
    const openModal = Array.from(document.querySelectorAll('.modal')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden';
    });
    if (openModal) return;

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_um', '1');

    fetch(currentUrl.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success && data.counts) {
                if (document.getElementById('um_badge_active'))   document.getElementById('um_badge_active').textContent   = data.counts.active;
                if (document.getElementById('um_badge_archived')) document.getElementById('um_badge_archived').textContent = data.counts.archived;
                
                if (lastUmTotalCount !== null && lastUmTotalCount !== data.counts.total) {
                    window.location.reload();
                }
                lastUmTotalCount = data.counts.total;
            }
        })
        .catch(() => {});
}
setInterval(autoRefreshUserManagement, 2000);
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
