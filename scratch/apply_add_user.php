<?php
/**
 * Patch script to apply Add User workflow enhancements to public/users.php
 */

$file = __DIR__ . '/../public/users.php';
$content = file_get_contents($file);

// ─────────────────────────────────────────────────────────────────────────────
// 1. REPLACE ADD USER POST HANDLER
// ─────────────────────────────────────────────────────────────────────────────

$old_handler = <<<'CODE'
        // 1. Add User
        if ($action === 'add_user') {
            $name           = trim($_POST['full_name'] ?? trim(($_POST['first_name'] ?? '') . ' ' . ($_POST['last_name'] ?? '')));
            $login_id       = trim($_POST['login_id'] ?? $_POST['email'] ?? '');
            $role_key_input = $_POST['role'] ?? '';
            $role           = role_key($role_key_input);
            $raw_password   = trim($_POST['password_hash'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');

            // Parse Login ID into email/username (phone support removed)
            $email    = null;
            $username = $login_id;

            if (strpos($login_id, '@') !== false) {
                $email = $login_id;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address format.');
            }

            // Validate required fields
            if (empty($name))           throw new Exception('Full Name is required.');
            if (empty($login_id))       throw new Exception('Login ID is required.');
            if (empty($role_key_input)) throw new Exception('Role is required.');

            // Password handling
            if (empty($raw_password)) {
                // Auto-generate: 12 chars, allowed symbols _ . - ! @ #
                $password = generateSecurePassword();
            } else {
                // Manual password validation
                if ($raw_password !== $confirmPassword) throw new Exception("Passwords do not match.");
                $allowed_symbol_regex = '/[!@#$%^&*(),.?":{}|<>_\-]/';
                if (
                    strlen($raw_password) < 8 ||
                    !preg_match('/[A-Z]/', $raw_password) ||
                    !preg_match('/[a-z]/', $raw_password) ||
                    !preg_match('/[0-9]/', $raw_password) ||
                    !preg_match($allowed_symbol_regex, $raw_password)
                ) {
                    throw new Exception("Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol.");
                }
                $password = $raw_password;
            }

            // Check login_id uniqueness (phone support removed)
            $dup_sql = 'SELECT id FROM users WHERE username = ?';
            $dup_params = [$username];
            if (!empty($email)) { $dup_sql .= ' OR email = ?'; $dup_params[] = $email; }
            $stmt = $pdo->prepare($dup_sql);
            $stmt->execute($dup_params);
            if ($stmt->fetch()) throw new Exception('Login ID (Email or Username) is already in use.');

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
                
                // ═══════════════════════════════════════════════════════════
                // STRICT VALIDATION: One Manager per Station ONLY
                // ═══════════════════════════════════════════════════════════
                if ($role === 'manager') {
                    // Check for ANY existing manager (active OR inactive) at this station
                    $checkManager = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM users 
                        WHERE role = 'manager' 
                          AND station_id = ?
                    ");
                    $checkManager->execute([$my_station_id]);
                    $managerCount = (int)$checkManager->fetchColumn();
                    
                    if ($managerCount > 0) {
                        // Get existing manager name for better error message
                        $existingMgr = $pdo->prepare("
                            SELECT name, status 
                            FROM users 
                            WHERE role = 'manager' 
                              AND station_id = ?
                            LIMIT 1
                        ");
                        $existingMgr->execute([$my_station_id]);
                        $mgrInfo = $existingMgr->fetch(PDO::FETCH_ASSOC);
                        $mgrName = $mgrInfo['name'] ?? 'Unknown';
                        $mgrStatus = $mgrInfo['status'] ?? 'active';
                        
                        throw new Exception("❌ Cannot create Manager. Your station already has a Manager: {$mgrName} (Status: {$mgrStatus}). Only ONE Manager is allowed per station. Please deactivate the existing Manager first if you need to replace them.");
                    }
                }
                
                // Note: Staff and Manager auto-assigned to admin's station - no station selection needed
            } elseif ($my_role === 'superadmin') {
                // Super Admin can create any role
                if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
                    throw new Exception("Invalid role selected.");
                }
                
                // ═══════════════════════════════════════════════════════════
                // STRICT VALIDATION: One Admin per Station ONLY
                // ═══════════════════════════════════════════════════════════
                if ($role === 'admin') {
                    $checkAdmin = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM users 
                        WHERE role = 'admin' 
                          AND station_id = ?
                    ");
                    $checkAdmin->execute([$station_target]);
                    $adminCount = (int)$checkAdmin->fetchColumn();
                    
                    if ($adminCount > 0) {
                        // Get existing admin name
                        $existingAdm = $pdo->prepare("
                            SELECT name, status 
                            FROM users 
                            WHERE role = 'admin' 
                              AND station_id = ?
                            LIMIT 1
                        ");
                        $existingAdm->execute([$station_target]);
                        $admInfo = $existingAdm->fetch(PDO::FETCH_ASSOC);
                        $admName = $admInfo['name'] ?? 'Unknown';
                        $admStatus = $admInfo['status'] ?? 'active';
                        
                        throw new Exception("❌ Cannot create Admin. This station already has an Admin: {$admName} (Status: {$admStatus}). Only ONE Admin is allowed per station.");
                    }
                }
                
                // ═══════════════════════════════════════════════════════════
                // STRICT VALIDATION: One Manager per Station ONLY
                // ═══════════════════════════════════════════════════════════
                if ($role === 'manager') {
                    $checkManager = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM users 
                        WHERE role = 'manager' 
                          AND station_id = ?
                    ");
                    $checkManager->execute([$station_target]);
                    $managerCount = (int)$checkManager->fetchColumn();
                    
                    if ($managerCount > 0) {
                        // Get existing manager name
                        $existingMgr = $pdo->prepare("
                            SELECT name, status 
                            FROM users 
                            WHERE role = 'manager' 
                              AND station_id = ?
                            LIMIT 1
                        ");
                        $existingMgr->execute([$station_target]);
                        $mgrInfo = $existingMgr->fetch(PDO::FETCH_ASSOC);
                        $mgrName = $mgrInfo['name'] ?? 'Unknown';
                        $mgrStatus = $mgrInfo['status'] ?? 'active';
                        
                        throw new Exception("❌ Cannot create Manager. This station already has a Manager: {$mgrName} (Status: {$mgrStatus}). Only ONE Manager is allowed per station. Please contact system administrator if you need to replace the existing Manager.");
                    }
                }
            }

            $hashed = password_hash($password, PASSWORD_DEFAULT);

            // Split full name into first_name and last_name for legacy database compatibility
            $name_parts = explode(' ', trim($name));
            if (count($name_parts) > 1) {
                $last_name_val = array_pop($name_parts);
                $first_name_val = implode(' ', $name_parts);
            } else {
                $first_name_val = $name;
                $last_name_val = '';
            }

            $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, username, role, email, password_hash, station_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', NOW())");
            $stmt->execute([$first_name_val, $last_name_val, $username, $role, $email, $hashed, $station_target]);

            // Get station name for email
            $station_name_for_email = 'Unknown Station';
            if ($station_target) {
                $stn = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
                $stn->execute([$station_target]);
                $stn_row = $stn->fetch(PDO::FETCH_ASSOC);
                if ($stn_row) $station_name_for_email = $stn_row['name'];
            }

            // Send credentials via email only (SMS support removed)
            $cred_sent = false;
            if (!empty($email)) {
                $cred_sent = sendAdminCredentialsEmail($email, $name, $station_name_for_email, $username, $password, $me['role']) ? true : false;
            }

            log_activity($pdo, $me['id'], 'Add User', "Created user $username ($role)");

            if ($cred_sent) {
                $msg = "✅ User created successfully. Credentials sent to {$email}.";
            } else {
                $msg = "✅ User created successfully. Temp Password: {$password} — share manually.";
            }
        }
CODE;

$new_handler = <<<'CODE'
        // 1. Add User
        if ($action === 'add_user') {
            $first_name_input  = trim($_POST['first_name']      ?? '');
            $last_name_input   = trim($_POST['last_name']       ?? '');
            $employee_id_input = trim($_POST['employee_id']     ?? '');
            $contact_input     = trim($_POST['contact_number']  ?? '');
            $email_input       = trim($_POST['email']           ?? '');
            $username_input    = trim($_POST['username']        ?? '');
            $assigned_shift    = trim($_POST['assigned_shift']  ?? '');
            $status_input      = trim($_POST['status']          ?? 'Active');
            $role_key_input    = $_POST['role']                 ?? '';
            $role              = role_key($role_key_input);
            $raw_password      = trim($_POST['new_password']    ?? '');
            $confirm_password  = trim($_POST['confirm_password']?? '');

            // Derive login identity
            $email    = !empty($email_input)    ? $email_input    : null;
            $username = !empty($username_input) ? $username_input : $email_input;

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
            if (!empty($assigned_shift)    && in_array('assigned_shift', $user_cols)) { $extra_sets[] = 'assigned_shift = ?'; $extra_vals[] = $assigned_shift; }
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
CODE;

if (strpos($content, $old_handler) === false) {
    die("Error: Could not locate the old add_user handler in public/users.php\n");
}
$content = str_replace($old_handler, $new_handler, $content);
echo "Successfully replaced the action handler.\n";


// ─────────────────────────────────────────────────────────────────────────────
// 2. REPLACE ADD USER MODAL HTML
// ─────────────────────────────────────────────────────────────────────────────

$old_modal = <<<'CODE'
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
                
                <div class="form-group mb-3">
                    <label class="lbl">Full Name <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" class="inp full" required placeholder="e.g. Juan Dela Cruz">
                </div>

                <div class="form-group mb-3">
                    <label class="lbl">Login ID <span style="color:red;">*</span></label>
                    <input type="text" name="login_id" class="inp full" required placeholder="Email or Username">
                    <small class="muted">Enter email (e.g. juan@email.com) or a username. Credentials will be sent via email.</small>
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
                
                <div class="form-group mb-3">
                    <label class="lbl">Password</label>
                    <div style="display:flex; gap:10px; align-items:flex-start;">
                        <input type="text" name="password_hash" id="new_password" class="inp full" placeholder="Leave empty to auto-generate">
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
CODE;

$new_modal = <<<'CODE'
<!-- MODAL: Add User -->
<div class="modal" id="addModal">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%); color: white;">
            <h3 class="modal-title" style="color: white; font-weight: 700;"><i class="fas fa-user-plus" style="margin-right: 8px;"></i>Add New User</h3>
            <button class="modal-close" onclick="closeModal('addModal')" style="color: white; opacity: 0.8;">&times;</button>
        </div>
        <form method="post" onsubmit="return validatePasswords();" autocomplete="off">
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto; padding: 25px;">
                <input type="hidden" name="action" value="add_user">

                <div style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 15px;">
                    <i class="fas fa-id-card" style="margin-right: 6px; color: #002F6C;"></i>Personal Details
                </div>

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

                <div class="grid-2 gap-3" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label class="lbl">Employee ID <span class="muted">(optional)</span></label>
                        <input type="text" name="employee_id" class="inp full" placeholder="e.g. EMP-1092">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Contact Number <span class="muted">(optional)</span></label>
                        <input type="text" name="contact_number" class="inp full" placeholder="e.g. 0917xxxxxxx">
                    </div>
                </div>

                <div style="font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 15px; margin-top: 25px;">
                    <i class="fas fa-key" style="margin-right: 6px; color: #002F6C;"></i>Account & Role Credentials
                </div>

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
                            <option value="Shift 2">Shift 2 (2:00 PM – 10:00 PM)</option>
                            <option value="Shift 3">Shift 3 (10:00 PM – 6:00 AM)</option>
                            <option value="All Shifts">All Shifts (Rotating)</option>
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
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn primary" style="background: linear-gradient(135deg, #002F6C 0%, #004a9e 100%); border: none; color: #fff; font-weight: 600;">Create User</button>
            </div>
        </form>
    </div>
</div>
CODE;

if (strpos($content, $old_modal) === false) {
    die("Error: Could not locate the old addModal HTML in public/users.php\n");
}
$content = str_replace($old_modal, $new_modal, $content);
echo "Successfully replaced the addModal HTML.\n";


// ─────────────────────────────────────────────────────────────────────────────
// 3. REPLACE JAVASCRIPT FUNCTIONS AT THE BOTTOM
// ─────────────────────────────────────────────────────────────────────────────

$old_js = <<<'CODE'
function toggleStationField() {
    const roleSelect = document.getElementById('user_role_add');
    const stationFieldGroup = document.getElementById('station_field_group');
    const selectedRole = roleSelect.value;
    const currentUserRole = '<?php echo $my_role; ?>';
    
    // Superadmin must pick a station for every new user.
    // Admin/manager accounts use their own station automatically.
    if ((currentUserRole === 'superadmin' && selectedRole !== '') ||
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
CODE;

$new_js = <<<'CODE'
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
        shiftSelect.required = true;
    } else {
        shiftFieldGroup.style.display = 'none';
        shiftSelect.required = false;
        shiftSelect.value = '';
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
CODE;

if (strpos($content, $old_js) === false) {
    die("Error: Could not locate the old JS functions in public/users.php\n");
}
$content = str_replace($old_js, $new_js, $content);
echo "Successfully replaced the JS helper functions.\n";

// Write content back
file_put_contents($file, $content);
echo "All replacements applied successfully to public/users.php!\n";
CODE;
echo "Patcher script created successfully.\n";
