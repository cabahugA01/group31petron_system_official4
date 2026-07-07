<?php
/**
 * Admin User Management with Shift Assignment
 * 
 * Features:
 * - Create 1 Manager per station (auto-bind to station shift schedule)
 * - Create unlimited Staff accounts
 * - Auto-assign staff to Shift 1 (6AM-2PM) or Shift 2 (2PM-10PM)
 * - Staff auto-bind to active shift on login
 * - View clock-in/out logs and active staff status per shift
 * - Activity logs (login/logout, encodes, edits, validations, exports) per shift
 * - Daily Consolidation (Shift 1 + Shift 2 totals)
 */

$page_id = 'admin_user_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$my_role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Only Admin can access this page
if (!in_array($my_role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error   = $_SESSION['error']   ?? null; unset($_SESSION['error']);

// Helper: generate next employee ID
function gen_emp_id($pdo, $role) {
    $prefix = match(strtolower($role)) { 'manager'=>'MGR','staff'=>'STF','admin'=>'ADM',default=>'USR' };
    $row = $pdo->prepare("SELECT employee_id FROM users WHERE employee_id LIKE ? ORDER BY id DESC LIMIT 1");
    $row->execute([$prefix.'-%']);
    $last = $row->fetchColumn();
    $num  = $last ? ((int)preg_replace('/\D/','',$last) + 1) : 1;
    return $prefix.'-'.str_pad($num,3,'0',STR_PAD_LEFT);
}

// Process POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Toggle status
    if ($action === 'toggle_status') {
        $uid = (int)$_POST['user_id'];
        $new_status = $_POST['new_status'] ?? 'Active';
        $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new_status, $uid]);
        $_SESSION['success'] = "User status updated to $new_status.";
        header('Location: admin_user_management.php'); exit;
    }
    // Reset password
    if ($action === 'reset_password') {
        $uid = (int)$_POST['user_id'];
        $new_pw = trim($_POST['new_password'] ?? '');
        if (strlen($new_pw) < 6) throw new Exception('Password must be at least 6 characters.');
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new_pw, PASSWORD_BCRYPT), $uid]);
        $_SESSION['success'] = 'Password reset successfully.';
        header('Location: admin_user_management.php'); exit;
    }

    try {
        // Create Manager Account (1 per station)
        if ($action === 'create_manager') {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $target_station_id = (int)($_POST['station_id'] ?? $station_id);
            
            // Validation
            if (empty($first_name) || empty($last_name) || empty($username) || empty($password)) {
                throw new Exception('All fields are required');
            }
            
            // Check if station already has a manager
            $check_manager = $pdo->prepare("SELECT COUNT(*) FROM users WHERE station_id = ? AND LOWER(role) = 'manager' AND status = 'Active'");
            $check_manager->execute([$target_station_id]);
            if ($check_manager->fetchColumn() > 0) {
                throw new Exception('This station already has an active Manager');
            }
            // Check username uniqueness
            $check_username = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $check_username->execute([$username]);
            if ($check_username->fetchColumn() > 0) throw new Exception('Username already exists.');
            $emp_id = gen_emp_id($pdo, 'manager');
            $stmt = $pdo->prepare("
                INSERT INTO users (employee_id, first_name, last_name, email, username, password_hash, role, station_id, assigned_shift, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'manager', ?, 'All Shifts', 'Active', NOW())
            ");
            $stmt->execute([$emp_id, $first_name, $last_name, $email, $username, password_hash($password, PASSWORD_BCRYPT), $target_station_id]);
            log_activity($pdo, $me['id'], 'Create Manager', "Created Manager: $first_name $last_name ($emp_id)");
            $_SESSION['success'] = "Manager $first_name $last_name ($emp_id) created.";
            header('Location: admin_user_management.php'); exit;
        }
        
        // Create Staff Account
        if ($action === 'create_staff') {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $shift_assignment = $_POST['shift_assignment'] ?? 'Shift 1'; // Default to Shift 1
            $target_station_id = (int)($_POST['station_id'] ?? $station_id);
            
            // Validation
            if (empty($first_name) || empty($last_name) || empty($username) || empty($password)) {
                throw new Exception('All fields are required');
            }
            
            // Check username uniqueness
            $check_username = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $check_username->execute([$username]);
            if ($check_username->fetchColumn() > 0) {
                throw new Exception('Username already exists');
            }
            
            // Determine shift times
            $shift_start = ($shift_assignment === 'Shift 1') ? '06:00:00' : '14:00:00';
            $shift_end   = ($shift_assignment === 'Shift 1') ? '14:00:00' : '00:00:00';
            
            $emp_id = gen_emp_id($pdo, 'staff');
            $stmt = $pdo->prepare("
                INSERT INTO users (employee_id, first_name, last_name, email, username, password_hash, role, station_id, assigned_shift, shift_assignment, shift_start_time, shift_end_time, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'staff', ?, ?, ?, ?, ?, 'Active', NOW())
            ");
            $stmt->execute([$emp_id, $first_name, $last_name, $email, $username, password_hash($password, PASSWORD_BCRYPT), $target_station_id, $shift_assignment, $shift_assignment, $shift_start, $shift_end]);
            log_activity($pdo, $me['id'], 'Create Staff', "Created Staff: $first_name $last_name ($emp_id) - $shift_assignment");
            $_SESSION['success'] = "Staff $first_name $last_name ($emp_id) created — $shift_assignment.";
            header('Location: admin_user_management.php'); exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: admin_user_management.php');
        exit;
    }
}

// Get all managers for this station (or all stations for superadmin)
$managers_query = "
    SELECT u.id, u.employee_id, u.first_name, u.last_name, u.username, u.email, u.role,
           u.assigned_shift, u.status, u.created_at, s.name AS station_name
    FROM users u
    LEFT JOIN stations s ON u.station_id = s.id
    WHERE LOWER(u.role) = 'manager'
";
if ($my_role !== 'superadmin') {
    $managers_query .= " AND u.station_id = " . (int)$station_id;
}
$managers_query .= " ORDER BY u.created_at DESC";
$managers = $pdo->query($managers_query)->fetchAll(PDO::FETCH_ASSOC);

// Get all staff for this station (or all stations for superadmin)
$staff_query = "
    SELECT u.id, u.employee_id, u.first_name, u.last_name, u.username, u.email, u.role,
           u.assigned_shift, u.shift_assignment, u.status, u.created_at, s.name AS station_name
    FROM users u
    LEFT JOIN stations s ON u.station_id = s.id
    WHERE LOWER(u.role) IN ('staff')
";
if ($my_role !== 'superadmin') {
    $staff_query .= " AND u.station_id = " . (int)$station_id;
}
$staff_query .= " ORDER BY u.assigned_shift, u.created_at DESC";
$staff = $pdo->query($staff_query)->fetchAll(PDO::FETCH_ASSOC);

// Get stations list (for superadmin)
$stations = [];
if ($my_role === 'superadmin') {
    $stations = $pdo->query("SELECT id, name FROM stations WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../partials/header.php';
?>

<div class="main-content" style="margin-left: 250px; padding: 20px; min-height: calc(100vh - 60px);">
    <style>
        .main-content {
            margin-left: 0;
            padding: 20px;
        }
        
        .page-header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .page-header h1 {
            color: #003366;
            margin: 0 0 5px 0;
            font-size: 28px;
        }
        
        .page-header p {
            color: #666666;
            margin: 0;
            font-size: 14px;
        }
        
        .action-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .action-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-left: 4px solid #003366;
            transition: transform 0.3s;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
        }
        
        .action-card h3 {
            color: #003366;
            margin: 0 0 10px 0;
            font-size: 18px;
        }
        
        .action-card p {
            color: #666666;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            white-space: nowrap;
            text-align: center;
        }
        
        .btn-primary {
            background: #003366;
            color: white;
        }
        
        .btn-primary:hover {
            background: #002244;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .users-table {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .users-table h2 {
            color: #003366;
            margin: 0 0 20px 0;
            font-size: 22px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead tr {
            background: #003366;
            color: white;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        tbody tr:hover {
            background: #f7f7f7;
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-shift1 {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-shift2 {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-manager {
            background: #cce5ff;
            color: #004085;
        }
        
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .btn-sm {
            padding: 4px 10px;
            font-size: 11px;
            border-radius: 4px;
        }
        
        .btn-warn {
            background: #dc3545;
            color: white;
        }
        .btn-warn:hover {
            background: #bd2130;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        .btn-info:hover {
            background: #138496;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-content h2 {
            color: #003366;
            margin: 0 0 20px 0;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #003366;
            font-weight: 600;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
    </style>

    <!-- Flash Messages -->
    <?php if ($flash_success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <h1>User Management</h1>
        <p>Create and manage Manager and Staff accounts with shift assignments</p>
    </div>

    <!-- Action Cards -->
    <div class="action-cards">
        <div class="action-card">
            <h3>Create Manager</h3>
            <p>Add 1 Manager per station. Manager is auto-bound to station shift schedule.</p>
            <button class="btn btn-primary" onclick="openModal('managerModal')">+ Create Manager</button>
        </div>
        
        <div class="action-card">
            <h3>Create Staff</h3>
            <p>Add unlimited staff accounts. Auto-assign to Shift 1 (6AM-2PM) or Shift 2 (2PM-10PM).</p>
            <button class="btn btn-success" onclick="openModal('staffModal')">+ Create Staff</button>
        </div>
        
        <div class="action-card">
            <h3>Shift Tracker</h3>
            <p>View clock-in/out logs and active staff status per shift.</p>
            <a href="admin_shift_tracker.php" class="btn btn-primary">View Shift Tracker</a>
        </div>
        
        <div class="action-card">
            <h3>Daily Consolidation</h3>
            <p>View overall totals across Shift 1 + Shift 2.</p>
            <a href="admin_daily_consolidation.php" class="btn btn-primary">View Consolidation</a>
        </div>
    </div>

    <!-- Managers Table -->
    <div class="users-table">
        <h2><i class="fas fa-user-tie" style="font-size:18px;margin-right:8px;"></i> Managers <small style="font-size:13px;color:#64748b;font-weight:400;">(<?= count($managers) ?> records)</small></h2>
        <table>
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <?php if ($my_role === 'superadmin'): ?><th>Station</th><?php endif; ?>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($managers)): ?>
                    <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:30px;">No managers found</td></tr>
                <?php else: ?>
                    <?php foreach ($managers as $mgr): ?>
                        <tr>
                            <td><span style="font-family:monospace;font-weight:700;color:#002F70;font-size:12px;"><?= htmlspecialchars($mgr['employee_id'] ?? '—') ?></span></td>
                            <td><strong><?= htmlspecialchars(trim($mgr['first_name'].' '.$mgr['last_name'])) ?></strong><div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($mgr['email'] ?? '') ?></div></td>
                            <td>@<?= htmlspecialchars($mgr['username']) ?></td>
                            <td><span class="badge badge-manager">Manager</span></td>
                            <?php if ($my_role === 'superadmin'): ?><td><?= htmlspecialchars($mgr['station_name'] ?? 'N/A') ?></td><?php endif; ?>
                            <td><span class="badge <?= strtolower($mgr['status']) === 'active' ? 'badge-active' : 'badge-inactive' ?>"><?= htmlspecialchars($mgr['status']) ?></span></td>
                            <td style="white-space:nowrap;">
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Toggle status?');">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= $mgr['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= strtolower($mgr['status']) === 'active' ? 'Locked' : 'Active' ?>">
                                    <button type="submit" class="btn btn-sm <?= strtolower($mgr['status']) === 'active' ? 'btn-warn' : 'btn-success' ?>"><?= strtolower($mgr['status']) === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <button class="btn btn-sm btn-info" onclick="openResetPw(<?= $mgr['id'] ?>, '<?= addslashes(htmlspecialchars($mgr['first_name'].' '.$mgr['last_name'])) ?>')">Reset PW</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Staff Table -->
    <div class="users-table">
        <h2><i class="fas fa-users" style="font-size:18px;margin-right:8px;"></i> Staff Accounts <small style="font-size:13px;color:#64748b;font-weight:400;">(<?= count($staff) ?> records)</small></h2>
        <table>
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Assigned Shift</th>
                    <?php if ($my_role === 'superadmin'): ?><th>Station</th><?php endif; ?>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staff)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:30px;">No staff found</td></tr>
                <?php else: ?>
                    <?php foreach ($staff as $s): ?>
                        <?php
                        $shift = $s['assigned_shift'] ?: ($s['shift_assignment'] ?: '');
                        $isS1  = stripos($shift,'1') !== false;
                        $isS2  = stripos($shift,'2') !== false;
                        ?>
                        <tr>
                            <td><span style="font-family:monospace;font-weight:700;color:#002F70;font-size:12px;"><?= htmlspecialchars($s['employee_id'] ?? '—') ?></span></td>
                            <td><strong><?= htmlspecialchars(trim($s['first_name'].' '.$s['last_name'])) ?></strong><div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($s['email'] ?? '') ?></div></td>
                            <td>@<?= htmlspecialchars($s['username']) ?></td>
                            <td><span class="badge badge-active" style="background:#e0e7ff;color:#3730a3;">Staff</span></td>
                            <td>
                                <?php if ($isS2): ?>
                                    <span class="badge badge-shift2">Shift 2 (2PM–12AM)</span>
                                <?php elseif ($isS1): ?>
                                    <span class="badge badge-shift1">Shift 1 (6AM–2PM)</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#f1f5f9;color:#64748b;">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($my_role === 'superadmin'): ?><td><?= htmlspecialchars($s['station_name'] ?? 'N/A') ?></td><?php endif; ?>
                            <td><span class="badge <?= strtolower($s['status']) === 'active' ? 'badge-active' : 'badge-inactive' ?>"><?= htmlspecialchars($s['status']) ?></span></td>
                            <td style="white-space:nowrap;">
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Toggle status?');">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= strtolower($s['status']) === 'active' ? 'Locked' : 'Active' ?>">
                                    <button type="submit" class="btn btn-sm <?= strtolower($s['status']) === 'active' ? 'btn-warn' : 'btn-success' ?>"><?= strtolower($s['status']) === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <button class="btn btn-sm btn-info" onclick="openResetPw(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['first_name'].' '.$s['last_name'])) ?>')">Reset PW</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Create Manager Modal -->
    <div id="managerModal" class="modal">
        <div class="modal-content">
            <h2>Create Manager Account</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_manager">
                
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" required>
                </div>
                
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" required>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                
                <?php if ($my_role === 'superadmin' && !empty($stations)): ?>
                    <div class="form-group">
                        <label>Station *</label>
                        <select name="station_id" required>
                            <option value="">Select Station</option>
                            <?php foreach ($stations as $st): ?>
                                <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" required>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create Manager</button>
                    <button type="button" class="btn btn-cancel" onclick="closeModal('managerModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Staff Modal -->
    <div id="staffModal" class="modal">
        <div class="modal-content">
            <h2>Create Staff Account</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_staff">
                
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" required>
                </div>
                
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" required>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                
                <div class="form-group">
                    <label>Shift Assignment *</label>
                    <select name="shift_assignment" required>
                        <option value="Shift 1">Shift 1 (6AM - 2PM)</option>
                        <option value="Shift 2">Shift 2 (2PM - 12AM)</option>
                    </select>
                </div>
                
                <?php if ($my_role === 'superadmin' && !empty($stations)): ?>
                    <div class="form-group">
                        <label>Station *</label>
                        <select name="station_id" required>
                            <option value="">Select Station</option>
                            <?php foreach ($stations as $st): ?>
                                <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" required>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Create Staff</button>
                    <button type="button" class="btn btn-cancel" onclick="closeModal('staffModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetPwModal" class="modal">
        <div class="modal-content">
            <h2>Reset Password</h2>
            <p>Reset password for user: <strong id="resetPwName"></strong></p>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetPwUid">
                
                <div class="form-group">
                    <label>New Password *</label>
                    <input type="password" name="new_password" required minlength="6" placeholder="At least 6 characters">
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                    <button type="button" class="btn btn-cancel" onclick="closeModal('resetPwModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function openResetPw(uid, name) {
            document.getElementById('resetPwUid').value = uid;
            document.getElementById('resetPwName').textContent = name;
            openModal('resetPwModal');
        }
        
        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
