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

// ── AJAX HANDLER FOR EMPLOYEE DETAILS & DOCUMENTS ─────────────────────────
if (isset($_GET['ajax_emp_details']) && !empty($_GET['user_id'])) {
    header('Content-Type: application/json');
    $uid = (int)$_GET['user_id'];
    
    if ($my_role !== 'superadmin') {
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND station_id = ?");
        $chk->execute([$uid, $my_station_id]);
        if (!$chk->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized station access.']);
            exit;
        }
    }
    
    $stmt = $pdo->prepare("SELECT u.*, s.name AS station_name FROM users u LEFT JOIN stations s ON u.station_id = s.id WHERE u.id = ?");
    $stmt->execute([$uid]);
    $emp_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$emp_info) {
        echo json_encode(['success' => false, 'error' => 'Employee record not found.']);
        exit;
    }
    
    unset($emp_info['password_hash']);
    
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            doc_type VARCHAR(100) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'Complete',
            file_name VARCHAR(255) DEFAULT NULL,
            file_path VARCHAR(255) DEFAULT NULL,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_emp_doc_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $docsStmt = $pdo->prepare("SELECT * FROM employee_documents WHERE user_id = ? ORDER BY id ASC");
    $docsStmt->execute([$uid]);
    $existing_docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

    $docs_by_type = [];
    foreach ($existing_docs as $ed) {
        $docs_by_type[$ed['doc_type']] = $ed;
    }
    
    $default_types = ['SSS', 'PhilHealth', 'Pag-IBIG', 'TIN', 'Valid ID'];
    $docs = [];
    foreach ($default_types as $dt) {
        if (isset($docs_by_type[$dt])) {
            $rec = $docs_by_type[$dt];
            if (empty($rec['file_name']) && strtolower($rec['status']) === 'complete') {
                $rec['status'] = 'Missing';
            }
            $docs[] = $rec;
        } else {
            $docs[] = [
                'user_id' => $uid,
                'doc_type' => $dt,
                'status' => 'Missing',
                'file_name' => null,
                'file_path' => null,
                'uploaded_at' => null
            ];
        }
    }
    
    $login_logs = [];
    try {
        $loginStmt = $pdo->prepare("SELECT action, created_at, shift_period FROM audit_trail WHERE user_id = ? AND action LIKE '%login%' ORDER BY created_at DESC LIMIT 20");
        $loginStmt->execute([$uid]);
        $login_logs = $loginStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($login_logs)) {
            $loginStmt2 = $pdo->prepare("SELECT action, created_at FROM activity_logs WHERE user_id = ? AND (action LIKE '%login%' OR action LIKE '%logout%') ORDER BY created_at DESC LIMIT 20");
            $loginStmt2->execute([$uid]);
            $login_logs = $loginStmt2->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
    
    $activities = [];
    try {
        $actStmt = $pdo->prepare("SELECT action, transaction_id, or_number, created_at FROM audit_trail WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
        $actStmt->execute([$uid]);
        $activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($activities)) {
            $actStmt2 = $pdo->prepare("SELECT action, details, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
            $actStmt2->execute([$uid]);
            $activities = $actStmt2->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
    
    try {
        log_activity($pdo, $me['id'], 'Viewed Employee Details', "Viewed employee #$uid ({$emp_info['username']})");
    } catch (Exception $e) {}
    
    echo json_encode([
        'success' => true,
        'info' => $emp_info,
        'documents' => $docs,
        'login_history' => $login_logs,
        'activity_logs' => $activities
    ]);
    exit;
}


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
                $assigned_shift = null;
                if (in_array('assigned_shift', $user_cols)) { $extra_sets[] = 'assigned_shift = NULL'; }
                if (in_array('shift_assignment', $user_cols)) { $extra_sets[] = 'shift_assignment = NULL'; }
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
                $assigned_shift= null;

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
                    $pdo->prepare("UPDATE users SET assigned_shift = NULL WHERE id = ?")->execute([$id]);
                }
                if (in_array('shift_assignment', $user_cols)) {
                    $pdo->prepare("UPDATE users SET shift_assignment = NULL WHERE id = ?")->execute([$id]);
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

/* COMPREHENSIVE VIEW EMPLOYEE DETAILS MODAL STYLES (Reports-style sub-tabs) */
.vtab-nav {
    display: flex !important;
    flex-wrap: wrap !important;
    margin-bottom: 18px !important;
    border: 1px solid #cbd5e1 !important;
    border-bottom: 3px solid #00264D !important;
    border-radius: 6px !important;
    overflow: hidden !important;
    gap: 0 !important;
    background: #ffffff !important;
    padding: 0 !important;
    width: 100% !important;
}
.vtab-btn {
    flex: 1 !important;
    padding: 11px 16px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #334155 !important;
    background: #ffffff !important;
    border: none !important;
    border-right: 1px solid #cbd5e1 !important;
    border-radius: 0 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    transition: all 0.15s ease !important;
}
.vtab-btn:last-child {
    border-right: none !important;
}
.vtab-btn:hover {
    background: #f1f5f9 !important;
    color: #00264D !important;
}
.vtab-btn.active {
    background: #00264D !important;
    color: #ffffff !important;
    font-weight: 800 !important;
}
.vtab-btn.active i,
.vtab-btn.active span {
    color: #ffffff !important;
}
.vtab-btn i {
    font-size: 13px !important;
}
.vtab-pane {
    display: none;
}
.vtab-pane.active {
    display: block;
}
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
@media(max-width: 580px) {
    .info-grid { grid-template-columns: 1fr; }
}
.info-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
}
.info-lbl {
    font-size: 10.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    display: block;
    margin-bottom: 3px;
}
.info-val {
    font-size: 13px;
    color: #0f172a;
    font-weight: 600;
}


/* Reports-Style Export Bar & Buttons (Identical to Reports Module) */
.rpt-export-group {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    margin-left: auto !important;
    white-space: nowrap !important;
}
.rpt-export-btn {
    padding: 7px 13px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    border-radius: 4px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    background: #ffffff !important;
    border: 1px solid !important;
    transition: all 0.18s !important;
    text-decoration: none !important;
}
.rpt-btn-print  { color: #475569 !important; border-color: transparent !important; background: transparent !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; color: #475569 !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; color: #dc2626 !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; color: #16a34a !important; }
.rpt-btn-csv   { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdf4 !important; color: #16a34a !important; }


/* Exact Horizontal Filter Bar Alignment Overrides */
.um-filter-row {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    margin-bottom: 22px !important;
    background: transparent !important;
    padding: 0 !important;
    border: none !important;
    flex-wrap: wrap !important;
    width: 100% !important;
}
.um-filter-left {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    flex-wrap: wrap !important;
}
.um-filter-right {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    flex-wrap: wrap !important;
    margin-left: auto !important;
}
.um-flt-item {
    height: 32px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    background: #ffffff !important;
    color: #334155 !important;
    display: inline-block !important;
    box-sizing: border-box !important;
    margin: 0 !important;
}


/* Filter Button High Contrast White Text */
.rpt-btn-apply {
    background: #00264D !important;
    background-color: #00264D !important;
    color: #ffffff !important;
    border: 1px solid #00264D !important;
}
.rpt-btn-apply i,
.rpt-btn-apply span,
.rpt-btn-apply * {
    color: #ffffff !important;
}

</style>

<div class="um-wrap">
<div class="page-head">
    <div>
        <h1 class="h1">USER MANAGEMENT</h1>
    </div>
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

<!-- EXACT USER MANAGEMENT FILTER & EXPORT ARRANGEMENT -->
<div class="um-filter-row">
    <!-- LEFT SIDE: Search employee, All Roles, All Status, Filter, Clear -->
    <div class="um-filter-left">
        <!-- 1. Search employee -->
        <div style="position: relative; width: 190px; display: inline-block;">
            <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12px;"></i>
            <input type="text" id="empSearchInput" onkeyup="filterEmployeeTable()" placeholder="Search employee..." class="um-flt-item" style="padding-left: 30px; width: 190px !important;">
        </div>
        
        <!-- 2. All Roles -->
        <select id="empRoleFilter" onchange="filterEmployeeTable()" class="um-flt-item" style="width: 110px !important; padding: 0 8px;">
            <option value="">All Roles</option>
            <option value="manager">Manager</option>
            <option value="staff">Staff</option>
        </select>
        
        <!-- 3. All Status -->
        <select id="empStatusFilter" onchange="filterEmployeeTable()" class="um-flt-item" style="width: 110px !important; padding: 0 8px;">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="archived">Archived</option>
        </select>

        <!-- 4. Filter Button -->
        <button type="button" onclick="filterEmployeeTable()" class="rpt-btn-apply" style="height: 32px !important; padding: 0 14px !important; font-size: 12px !important; font-weight: 700 !important; border-radius: 4px !important; background: #00264D !important; color: #ffffff !important; border: 1px solid #00264D !important; display: inline-flex !important; align-items: center !important; gap: 6px !important;">
            <i class="fas fa-filter" style="color: #ffffff !important;"></i> <span style="color: #ffffff !important;">Filter</span>
        </button>

        <!-- 5. Clear Button -->
        <button type="button" onclick="clearEmployeeFilters()" class="btn-plain-cancel" style="height: 32px !important; padding: 0 12px !important; font-size: 12px !important; border-radius: 4px !important;" title="Reset all filters">
            <i class="fas fa-undo"></i> Clear
        </button>
    </div>

    <!-- RIGHT SIDE: Print, PDF, Excel, CSV ONLY -->
    <div class="um-filter-right">
        <div class="rpt-export-group" style="margin-left: 0 !important; gap: 4px !important;">
            <button type="button" class="rpt-export-btn rpt-btn-print" onclick="triggerEmployeeExport('print')">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" class="rpt-export-btn rpt-btn-pdf" onclick="triggerEmployeeExport('pdf')">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <button type="button" class="rpt-export-btn rpt-btn-excel" onclick="triggerEmployeeExport('excel')">
                <i class="fas fa-file-excel"></i> Excel
            </button>
            <button type="button" class="rpt-export-btn rpt-btn-csv" onclick="triggerEmployeeExport('csv')">
                <i class="fas fa-file-csv"></i> CSV
            </button>
        </div>
    </div>
</div>

<!-- ADD NEW USER BUTTON (BELOW FILTERS & EXPORTS, RIGHT ABOVE TABLE/TABS) -->
<?php if ($my_role === 'admin' || $my_role === 'superadmin'): ?>
<div style="display: flex; justify-content: flex-end; margin-bottom: 14px;">
    <button type="button" onclick="openAddModal()" class="btn-header-add">
        <i class="fas fa-plus"></i> <span>Add New User</span>
    </button>
</div>
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

                                <button class="action-btn btn-view" onclick="openViewEmployeeModal(<?php echo (int)$u['id']; ?>)" title="View Employee Details">
                                    <i class="fas fa-eye"></i> View
                                </button>
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

<!-- COMPREHENSIVE VIEW EMPLOYEE DETAILS MODAL -->
<div id="viewModal" class="modal">
    <div class="modal-content" style="max-width: 760px !important;">
        <div class="modal-header">
            <div>
                <span class="modal-title" id="vmodal_title"><i class="fas fa-user-circle"></i> Employee Details</span>
                
            </div>
            <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
        </div>

        <div class="modal-body" style="padding: 16px 20px;">
            <!-- Tab Navigation -->
            <div class="vtab-nav">
                <button type="button" class="vtab-btn active" id="btn_vtab_info" onclick="switchViewTab('info')">
                    <i class="fas fa-info-circle"></i> Information
                </button>
                <button type="button" class="vtab-btn" id="btn_vtab_docs" onclick="switchViewTab('docs')">
                    <i class="fas fa-folder-open"></i> Documents
                </button>
            </div>

            <!-- Loader -->
            <div id="vmodal_loader" style="text-align: center; padding: 30px; color: #64748b;">
                <i class="fas fa-spinner fa-spin fa-2x" style="color: #002F70;"></i>
                <div style="margin-top: 10px; font-weight: 600;">Loading employee records...</div>
            </div>

            <div id="vmodal_body_wrap" style="display: none;">
                <!-- TAB 1: INFORMATION -->
                <div id="vtab_info" class="vtab-pane active">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-lbl">Employee ID</span>
                            <span class="info-val" id="vi_emp_id" style="font-family: monospace; color: #002F70; font-weight: 700;">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Full Name</span>
                            <span class="info-val" id="vi_full_name">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Username</span>
                            <span class="info-val" id="vi_username">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Role</span>
                            <span class="info-val" id="vi_role">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Station / Branch</span>
                            <span class="info-val" id="vi_station">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Account Status</span>
                            <span class="info-val" id="vi_status">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Date Created</span>
                            <span class="info-val" id="vi_created">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Last Login</span>
                            <span class="info-val" id="vi_last_login">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Email Address</span>
                            <span class="info-val" id="vi_email">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Contact Number</span>
                            <span class="info-val" id="vi_phone">—</span>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: DOCUMENTS -->
                <div id="vtab_docs" class="vtab-pane">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <span style="font-size: 12.5px; font-weight: 700; color: #002F70;">Employee Documents Completeness</span>
                    </div>
                    <table class="table" style="font-size: 12px; width: 100%;">
                        <thead>
                            <tr>
                                <th>DOCUMENT TYPE</th>
                                <th>STATUS</th>
                                <th>UPLOADED FILE</th>
                                <th>DATE</th>
                            </tr>
                        </thead>
                        <tbody id="vdocs_tbody">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>

                    <?php if ($my_role === 'admin' || $my_role === 'superadmin'): ?>
                    <form method="POST" action="users.php" enctype="multipart/form-data" style="margin-top: 14px; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <input type="hidden" name="action" value="update_emp_document">
                        <input type="hidden" name="user_id" id="vdoc_form_user_id">
                        <div style="font-weight: 700; font-size: 11.5px; color: #002F70; margin-bottom: 8px; text-transform: uppercase;">
                            <i class="fas fa-upload"></i> Update Document Record
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <select name="doc_type" class="inp" required style="flex: 1; min-width: 130px; font-size: 12px; height: 34px;">
                                <option value="SSS">SSS</option>
                                <option value="PhilHealth">PhilHealth</option>
                                <option value="Pag-IBIG">Pag-IBIG</option>
                                <option value="TIN">TIN</option>
                                <option value="Valid ID">Valid ID</option>
                                <option value="Employment">Employment Contract / Docs</option>
                                <option value="Other">Other Required Document</option>
                            </select>
                            <select name="doc_status" class="inp" required style="width: 120px; font-size: 12px; height: 34px;">
                                <option value="Complete">Complete</option>
                                <option value="Missing">Missing</option>
                                <option value="Expired">Expired</option>
                                <option value="Expiring Soon">Expiring Soon</option>
                                <option value="Pending Review">Pending Review</option>
                            </select>
                            <input type="file" name="doc_file" class="inp" style="flex: 1; min-width: 160px; font-size: 12px; height: 34px; padding: 3px 8px;">
                            <button type="submit" class="btn-plain-submit" style="height: 34px; padding: 0 14px; font-size: 12px;">Save</button>
                        </div>
                    </form>
                    <?php endif; ?>
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



function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function switchViewTab(tabName) {
    document.querySelectorAll(".vtab-btn").forEach(btn => btn.classList.remove("active"));
    document.querySelectorAll(".vtab-pane").forEach(pane => pane.classList.remove("active"));

    const btn = document.getElementById("btn_vtab_" + tabName);
    const pane = document.getElementById("vtab_" + tabName);
    if (btn) btn.classList.add("active");
    if (pane) pane.classList.add("active");
}

function openViewEmployeeModal(userId) {
    document.getElementById("vmodal_loader").style.display = "block";
    document.getElementById("vmodal_body_wrap").style.display = "none";
    document.getElementById("viewModal").style.display = "flex";
    switchViewTab("info");

    if (document.getElementById("vdoc_form_user_id")) {
        document.getElementById("vdoc_form_user_id").value = userId;
    }

    fetch("users.php?ajax_emp_details=1&user_id=" + userId)
        .then(r => r.json())
        .then(data => {
            document.getElementById("vmodal_loader").style.display = "none";
            if (!data.success) {
                alert(data.error || "Failed to load employee details.");
                closeModal("viewModal");
                return;
            }

            document.getElementById("vmodal_body_wrap").style.display = "block";

            const info = data.info || {};
            const fullName = ((info.first_name || "") + " " + (info.last_name || "")).trim() || info.username;
            document.getElementById("vmodal_title").innerHTML = '<i class="fas fa-user-circle" style="color:#002F70;"></i> ' + escapeHtml(fullName);
            

            document.getElementById("vi_emp_id").innerText = info.employee_id || "—";
            document.getElementById("vi_full_name").innerText = fullName;
            document.getElementById("vi_username").innerText = "@" + (info.username || "—");
            document.getElementById("vi_role").innerText = (info.role || "Staff").toUpperCase();
            document.getElementById("vi_station").innerText = info.station_name || "Petron Carmen";
            document.getElementById("vi_status").innerText = (info.status || "Active").toUpperCase();
            document.getElementById("vi_created").innerText = info.created_at ? info.created_at.substring(0, 10) : "—";
            document.getElementById("vi_last_login").innerText = info.updated_at ? info.updated_at : "—";
            document.getElementById("vi_email").innerText = info.email || "Not set";
            document.getElementById("vi_phone").innerText = info.phone_number || "Not set";

            // Documents Table
            const docsBody = document.getElementById("vdocs_tbody");
            if (docsBody) {
                docsBody.innerHTML = "";
                (data.documents || []).forEach(doc => {
                    const tr = document.createElement("tr");
                    const st = (doc.status || "Complete").toLowerCase();
                    let stBadge = '<span style="color:#16a34a; font-weight:700;">Complete</span>';
                    if (st === "missing") stBadge = '<span style="color:#dc2626; font-weight:700;">Missing</span>';
                    else if (st === "expired" || st === "expiring soon") stBadge = '<span style="color:#d97706; font-weight:700;">' + escapeHtml(doc.status) + '</span>';

                    const fileCell = doc.file_name ? '<a href="' + escapeHtml(doc.file_path) + '" target="_blank" style="color:#002F70; font-weight:600;"><i class="fas fa-paperclip"></i> ' + escapeHtml(doc.file_name) + '</a>' : '<span style="color:#94a3b8;">No file uploaded</span>';

                    tr.innerHTML = '<td><strong>' + escapeHtml(doc.doc_type) + '</strong></td><td>' + stBadge + '</td><td>' + fileCell + '</td><td><span style="font-size:11px; color:#64748b;">' + (doc.uploaded_at ? doc.uploaded_at.substring(0,10) : "Registered") + '</span></td>';
                    docsBody.appendChild(tr);
                });
            }

            // Login History Table (if tab present)
            const loginBody = document.getElementById("vlogin_tbody");
            if (loginBody) {
                loginBody.innerHTML = "";
                if ((data.login_history || []).length === 0) {
                    loginBody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:#94a3b8; padding:12px;">No login records found.</td></tr>';
                } else {
                    data.login_history.forEach(log => {
                        const tr = document.createElement("tr");
                        tr.innerHTML = '<td>' + escapeHtml(log.created_at) + '</td><td><span style="color:#16a34a; font-weight:600;">' + escapeHtml(log.action) + '</span></td><td>' + escapeHtml(log.shift_period || "—") + '</td>';
                        loginBody.appendChild(tr);
                    });
                }
            }

            // Activity Logs Table (if tab present)
            const actBody = document.getElementById("vact_tbody");
            if (actBody) {
                actBody.innerHTML = "";
                if ((data.activity_logs || []).length === 0) {
                    actBody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:#94a3b8; padding:12px;">No recent audit activity found.</td></tr>';
                } else {
                    data.activity_logs.forEach(act => {
                        const tr = document.createElement("tr");
                        const ref = act.or_number || act.transaction_id || act.details || "—";
                        tr.innerHTML = '<td>' + escapeHtml(act.created_at) + '</td><td><strong>' + escapeHtml(act.action) + '</strong></td><td>' + escapeHtml(ref) + '</td>';
                        actBody.appendChild(tr);
                    });
                }
            }
        })
        .catch(err => {
            console.error("AJAX Error:", err);
            document.getElementById("vmodal_loader").style.display = "none";
            alert("Error loading employee data.");
            closeModal("viewModal");
        });
}

function openViewModal(user) {
    if (typeof user === "object" && user.id) {
        openViewEmployeeModal(user.id);
    } else {
        openViewEmployeeModal(user);
    }
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
// ── CLIENT-SIDE REALTIME TABLE FILTERING & EXPORT LINK SYNC ─────────────────

function triggerEmployeeExport(format) {
    const q = (document.getElementById("empSearchInput") ? document.getElementById("empSearchInput").value : "").trim();
    const role = (document.getElementById("empRoleFilter") ? document.getElementById("empRoleFilter").value : "").trim();
    const status = (document.getElementById("empStatusFilter") ? document.getElementById("empStatusFilter").value : "").trim();

    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get("tab") || "active";

    const params = new URLSearchParams();
    params.set("format", format);
    params.set("tab", tab);
    if (q) params.set("q", q);
    if (role) params.set("role", role);
    if (status) params.set("status", status);
    params.set("_ts", Date.now());

    const exportUrl = "export_employee_list.php?" + params.toString();

    if (format === "print") {
        let iframe = document.getElementById("printReportIframe");
        if (!iframe) {
            iframe = document.createElement("iframe");
            iframe.id = "printReportIframe";
            iframe.style.position = "fixed";
            iframe.style.right = "0";
            iframe.style.bottom = "0";
            iframe.style.width = "0";
            iframe.style.height = "0";
            iframe.style.border = "0";
            document.body.appendChild(iframe);
        }
        iframe.src = exportUrl;
    } else if (format === "pdf") {
        window.open(exportUrl, "_blank");
    } else {
        window.location.href = exportUrl;
    }
}

function filterEmployeeTable() {
    const q = (document.getElementById("empSearchInput") ? document.getElementById("empSearchInput").value : "").toLowerCase().trim();
    const role = (document.getElementById("empRoleFilter") ? document.getElementById("empRoleFilter").value : "").toLowerCase().trim();
    const status = (document.getElementById("empStatusFilter") ? document.getElementById("empStatusFilter").value : "").toLowerCase().trim();

    // 1. Filter table rows
    const table = document.querySelector(".table-wrap table.table");
    if (table) {
        const rows = table.querySelectorAll("tbody tr");
        rows.forEach(tr => {
            if (tr.cells.length < 5) return; // skip empty state row
            
            const empIdText  = (tr.cells[0] ? tr.cells[0].innerText : "").toLowerCase();
            const nameText   = (tr.cells[1] ? tr.cells[1].innerText : "").toLowerCase();
            const userText   = (tr.cells[2] ? tr.cells[2].innerText : "").toLowerCase();
            const roleText   = (tr.cells[3] ? tr.cells[3].innerText : "").toLowerCase();
            
            let statusCellIdx = 4;
            if (tr.cells.length >= 7) statusCellIdx = 5;
            const statusText = (tr.cells[statusCellIdx] ? tr.cells[statusCellIdx].innerText : "").toLowerCase();

            // Match query (Employee ID, Name, or Username)
            const matchQ = (q === "") || empIdText.includes(q) || nameText.includes(q) || userText.includes(q);
            
            // Match role (Manager or Staff)
            const matchRole = (role === "") || roleText.includes(role);
            
            // Match status (Active, Inactive, Archived)
            const matchStatus = (status === "") || statusText.includes(status);

            if (matchQ && matchRole && matchStatus) {
                tr.style.display = "";
            } else {
                tr.style.display = "none";
            }
        });
    }

    // 2. Synchronize Export URLs with active filters
    const exportParams = new URLSearchParams();
    if (q) exportParams.set("q", q);
    if (role) exportParams.set("role", role);
    if (status) exportParams.set("status", status);

    const queryString = exportParams.toString() ? ("&" + exportParams.toString()) : "";

    const btnPrint = document.querySelector(".rpt-btn-print");
    if (btnPrint) {
        btnPrint.setAttribute("onclick", "window.open('export_employee_list.php?format=print" + queryString + "', '_blank')");
    }

    const btnPdf = document.querySelector(".rpt-btn-pdf");
    if (btnPdf) {
        btnPdf.setAttribute("href", "export_employee_list.php?format=pdf" + queryString);
    }

    const btnExcel = document.querySelector(".rpt-btn-excel");
    if (btnExcel) {
        btnExcel.setAttribute("href", "export_employee_list.php?format=excel" + queryString);
    }

    const btnCsv = document.querySelector(".rpt-btn-csv");
    if (btnCsv) {
        btnCsv.setAttribute("href", "export_employee_list.php?format=csv" + queryString);
    }
}

function clearEmployeeFilters() {
    if (document.getElementById("empSearchInput")) document.getElementById("empSearchInput").value = "";
    if (document.getElementById("empRoleFilter")) document.getElementById("empRoleFilter").value = "";
    if (document.getElementById("empStatusFilter")) document.getElementById("empStatusFilter").value = "";
    filterEmployeeTable();
}

setInterval(autoRefreshUserManagement, 2000);
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
