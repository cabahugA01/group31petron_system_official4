<?php
/**
 * Patch: Replace add_user block in public/users.php (lines 75–320)
 * with the new multi-field handler.
 */
$file  = __DIR__ . '/../public/users.php';
$lines = file($file);

$new_block = <<<'PHP'
        if ($action === 'add_user') {

            // Collect individual fields
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
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL))
                throw new Exception('Invalid email address format.');

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

            // Station assignment
            $station_target = null;
            if ($my_role === 'superadmin') {
                if (empty($_POST['station_id'])) throw new Exception('Station selection is required.');
                $station_target = (int)$_POST['station_id'];
                if (!StationManager::isValidActiveStation($station_target))
                    throw new Exception('Selected station is not valid or inactive.');
            } elseif ($my_role === 'admin') {
                if ($role === 'admin') {
                    if (empty($_POST['station_id'])) throw new Exception('Station selection required for Admin creation.');
                    $station_target = (int)$_POST['station_id'];
                    if (!StationManager::isValidActiveStation($station_target))
                        throw new Exception('Selected station is not valid or inactive.');
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
                if (!in_array($role, ['staff', 'manager']))
                    throw new Exception('As Admin, you can only create Staff or Manager users.');
                if ($role === 'manager') {
                    $cm = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='manager' AND station_id=?");
                    $cm->execute([$my_station_id]);
                    if ((int)$cm->fetchColumn() > 0)
                        throw new Exception('Station already has a Manager. Deactivate the existing Manager first.');
                }
            } elseif ($my_role === 'superadmin') {
                if (!in_array($role, ['staff','manager','admin','superadmin']))
                    throw new Exception('Invalid role selected.');
                if ($role === 'admin') {
                    $ca = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND station_id=?");
                    $ca->execute([$station_target]);
                    if ((int)$ca->fetchColumn() > 0)
                        throw new Exception('Station already has an Admin.');
                }
                if ($role === 'manager') {
                    $cm = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='manager' AND station_id=?");
                    $cm->execute([$station_target]);
                    if ((int)$cm->fetchColumn() > 0)
                        throw new Exception('Station already has a Manager.');
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
            if (!empty($contact_input)     && in_array('contact_number', $user_cols)) { $extra_sets[] = 'contact_number = ?'; $extra_vals[] = $contact_input; }
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
                ? "✅ User created! Credentials email sent to <strong>{$email}</strong>."
                : "✅ User created! Temp Password: <strong>{$password}</strong> — share manually.";
        }

PHP;

// Lines are 1-indexed; block to replace: lines 75–320
$before = array_slice($lines, 0, 74);          // lines 1–74
$after  = array_slice($lines, 320);            // lines 321–end

$result = implode('', $before) . $new_block . implode('', $after);
file_put_contents($file, $result);

echo "Done. Lines: " . count(file($file)) . PHP_EOL;
