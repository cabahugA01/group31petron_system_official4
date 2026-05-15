<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/station_management.php';
require_once __DIR__ . '/../db_connect.php';

// Ensure user is logged in and has proper permissions
require_login();

// Set content type to JSON
header('Content-Type: application/json');

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    // Get the action from the request
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_user_details':
            $user_id = $_GET['user_id'] ?? 0;
            if (!$user_id) {
                throw new Exception('User ID is required');
            }
            
            $stmt = $pdo->prepare("
                SELECT u.*, s.name as station_name 
                FROM users u 
                LEFT JOIN stations s ON u.station_id = s.id 
                WHERE u.id = ?
            ");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Remove sensitive data
            unset($user['password']);
            
            $response['success'] = true;
            $response['data'] = $user;
            break;
            
        case 'create_station_admin':
            require_permission(CREATE_STATION_ADMIN);
            
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $phone_number = trim($_POST['phone_number'] ?? '');
            $assigned_station = $_POST['assigned_station'] ?? '';
            $status = $_POST['status'] ?? 'active';
            
            if (empty($full_name) || empty($email) || empty($username) || empty($assigned_station)) {
                throw new Exception('All required fields must be filled');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address');
            }
            
            if (strlen($username) < 3) {
                throw new Exception('Username must be at least 3 characters long');
            }
            
            // Check if username already exists
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $chk->execute([$username]);
            
            if ($chk->rowCount() > 0) {
                throw new Exception("Username '$username' already exists");
            }
            
            // Get station name
            $station = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
            $station->execute([$assigned_station]);
            $station_name = $station->fetchColumn();
            
            if (!$station_name) {
                throw new Exception('Invalid station selected');
            }
            
            // Generate default password
            $default_password = 'Admin123!';
            $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
            
            // Create station admin
            $stmt = $pdo->prepare("INSERT INTO users (username, password, name, email, phone, role, station_id, status, created_at) VALUES (?, ?, ?, ?, ?, 'admin', ?, ?, NOW())");
            $stmt->execute([$username, $hashed_password, $full_name, $email, $phone_number, $assigned_station, $status]);
            
            log_user_action('Create Station Admin', "Created admin '$username' for station '$station_name'");
            
            $response['success'] = true;
            $response['message'] = "Station Admin created successfully! Default password: $default_password";
            break;
            
        case 'create_default_accounts':
            require_permission(CREATE_DEFAULT_ROLES_FOR_STATION);
            
            // Use StationManager to determine target station
            $current_user = current_user();
            try {
                $station_id = StationManager::getTargetStationForUserCreation(
                    $current_user['role'],
                    user_station_id(),
                    $_POST['station_id'] ?? null
                );
            } catch (Exception $e) {
                throw new Exception('Station assignment error: ' . $e->getMessage());
            }
            
            // Get station info
            $station = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
            $station->execute([$station_id]);
            $station_name = $station->fetchColumn();
            
            if (!$station_name) {
                throw new Exception('Invalid station selected');
            }
            
            $created_count = 0;
            $clean_name = preg_replace('/[^a-zA-Z0-9]/', '', $station_name);
            
            // Create Manager
            $manager_username = strtolower("manager_" . substr($clean_name, 0, 8));
            $manager_password = password_hash('Manager123!', PASSWORD_DEFAULT);
            
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $chk->execute([$manager_username]);
            if ($chk->rowCount() == 0) {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role, station_id, status, created_at) VALUES (?, ?, ?, 'manager', ?, 'active', NOW())");
                $stmt->execute([$manager_username, $manager_password, "Manager - $station_name", $station_id]);
                $created_count++;
            }
            
            // Create 5 Staff
            for ($i = 1; $i <= 5; $i++) {
                $staff_username = strtolower("staff" . $i . "_" . substr($clean_name, 0, 6));
                $staff_password = password_hash('Staff123!', PASSWORD_DEFAULT);
                
                $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $chk->execute([$staff_username]);
                if ($chk->rowCount() == 0) {
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role, station_id, status, created_at) VALUES (?, ?, ?, 'staff', ?, 'active', NOW())");
                    $stmt->execute([$staff_username, $staff_password, "Staff $i - $station_name", $station_id]);
                    $created_count++;
                }
            }
            
            log_user_action('Create Default Roles', "Created $created_count default users for station '$station_name'");
            
            $response['success'] = true;
            $response['message'] = "Default accounts created successfully";
            $response['data'] = ['created_count' => $created_count];
            break;
            
        case 'reset_password':
            require_permission(RESET_PASSWORD);
            
            $user_id = $_POST['user_id'] ?? '';
            $current_user = current_user();
            
            if (empty($user_id)) {
                throw new Exception('User ID is required');
            }
            
            // Get user info
            $user = $pdo->prepare("SELECT username, name, role, station_id FROM users WHERE id = ?");
            $user->execute([$user_id]);
            $userInfo = $user->fetch();
            
            if (!$userInfo) {
                throw new Exception('User not found');
            }
            
            // STATION RESTRICTION: Admin can only reset password for users in their assigned station
            if ($current_user['role'] === 'admin' || $current_user['role'] === 'Admin') {
                // Admin can only manage staff in their station
                if ($userInfo['station_id'] != $current_user['station_id']) {
                    throw new Exception('You can only reset passwords for staff in your assigned station');
                }
                // Admin cannot reset password for other admins or managers
                if (in_array(strtolower($userInfo['role']), ['admin', 'manager'])) {
                    throw new Exception('You can only reset passwords for staff users');
                }
            }
            
            // Generate new password
            $new_password = generateRandomPassword();
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password
            $stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            // Set password expiry if column exists
            try {
                $expires = (new DateTime("+90 days"))->format('Y-m-d H:i:s');
                $pdo->prepare("UPDATE users SET password_expires_at = ? WHERE id = ?")
                    ->execute([$expires, $user_id]);
            } catch(Exception $e){}
            
            log_user_action('Password Reset', "Reset password for user '$userInfo[username]'");
            
            $response['success'] = true;
            $response['message'] = "Password reset successfully. New password: $new_password";
            break;
            
        case 'update_user_status':
            require_permission(DEACTIVATE_USER);
            
            $user_id = $_POST['user_id'] ?? '';
            $new_status = $_POST['new_status'] ?? 'inactive';
            $reason = trim($_POST['reason'] ?? '');
            $current_user_id = current_user()['id'];
            
            if (empty($user_id)) {
                throw new Exception('User ID is required');
            }
            
            if (empty($reason)) {
                throw new Exception('Reason is required for status change');
            }
            
            if ($user_id == $current_user_id) {
                throw new Exception('You cannot change your own status');
            }
            
            // Get user info
            $user = $pdo->prepare("SELECT username, name, status FROM users WHERE id = ?");
            $user->execute([$user_id]);
            $userInfo = $user->fetch();
            
            if (!$userInfo) {
                throw new Exception('User not found');
            }
            
            // Update user status
            $stmt = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $user_id]);
            
            log_user_action('User Status Change', "Changed user '$userInfo[username]' status from '$userInfo[status]' to '$new_status'. Reason: $reason");
            
            $response['success'] = true;
            $response['message'] = "User status updated successfully";
            break;
            
        case 'get_all_users':
            $role_filter = $_GET['role_filter'] ?? '';
            $station_filter = $_GET['station_filter'] ?? '';
            $status_filter = $_GET['status_filter'] ?? '';
            $search = $_GET['search'] ?? '';
            
            $sql = "
                SELECT u.*, s.name as station_name 
                FROM users u 
                LEFT JOIN stations s ON u.station_id = s.id 
                WHERE 1=1
            ";
            $params = [];
            
            if ($role_filter) {
                $sql .= " AND u.role = ?";
                $params[] = $role_filter;
            }
            
            if ($status_filter) {
                $sql .= " AND u.status = ?";
                $params[] = $status_filter;
            }
            
            if ($search) {
                $sql .= " AND (u.username LIKE ? OR u.name LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $sql .= " ORDER BY u.name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Filter by station if specified (PHP side filtering since it's a JOIN)
            if ($station_filter) {
                $users = array_filter($users, function($user) use ($station_filter) {
                    return $user['station_name'] === $station_filter;
                });
            }
            
            // Remove sensitive data
            foreach ($users as &$user) {
                unset($user['password']);
            }
            
            $response['success'] = true;
            $response['data'] = array_values($users);
            break;
            
        case 'update_user':
            $user_id = $_POST['user_id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $role = $_POST['role'] ?? 'staff';
            $station_id = $_POST['station_id'] ?? '';
            $status = $_POST['status'] ?? 'active';
            
            if (empty($user_id) || empty($name)) {
                throw new Exception('User ID and Name are required');
            }
            
            // Check permissions
            $current_user = current_user();
            if (!$current_user || $current_user['role'] !== 'superadmin') {
                // Non-super admins can only edit users in their station
                if ($station_id != $current_user['station_id']) {
                    throw new Exception('You can only edit users in your station');
                }
                // Prevent privilege escalation
                if ($role === 'superadmin') {
                    $role = 'staff';
                }
            }
            
            $updateFields = "name = ?, email = ?, phone = ?, role = ?, station_id = ?, status = ?, updated_at = NOW()";
            $updateParams = [$name, $email, $phone, $role, $station_id, $status, $user_id];
            
            $stmt = $pdo->prepare("UPDATE users SET $updateFields WHERE id = ?");
            $stmt->execute($updateParams);
            
            log_user_action('User Update', "Updated user ID $user_id");
            
            $response['success'] = true;
            $response['message'] = 'User updated successfully';
            break;
            
        case 'delete_user':
            require_permission(DEACTIVATE_USER);
            
            $user_id = $_POST['user_id'] ?? '';
            $current_user_id = current_user()['id'];
            
            if (empty($user_id)) {
                throw new Exception('User ID is required');
            }
            
            if ($user_id == $current_user_id) {
                throw new Exception('You cannot delete your own account');
            }
            
            // Get user info
            $user = $pdo->prepare("SELECT username, name FROM users WHERE id = ?");
            $user->execute([$user_id]);
            $userInfo = $user->fetch();
            
            if (!$userInfo) {
                throw new Exception('User not found');
            }
            
            // Check if user can be deleted (super admin only)
            $current_user = current_user();
            if (!$current_user || $current_user['role'] !== 'superadmin') {
                throw new Exception('Only Super Admin can delete users');
            }
            
            // Soft delete by setting status to inactive
            $stmt = $pdo->prepare("UPDATE users SET status = 'inactive', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$user_id]);
            
            log_user_action('User Deletion', "Deleted user '$userInfo[username]'");
            
            $response['success'] = true;
            $response['message'] = 'User deleted successfully';
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    $response['success'] = false;
}

// Send JSON response
echo json_encode($response);

function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
}
?>
