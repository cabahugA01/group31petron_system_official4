<?php
/**
 * Users API Endpoint
 * Handles all user-related operations via REST API
 * Follows the same pattern as customers.php, roles.php, stations.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../station_management.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {
        case 'list':
            // Get current user and their station
            $me = current_user();
            $role = strtolower(trim($me['role'] ?? 'staff'));
            $station_id = $me['station_id'] ?? null;
            
            // Get filter parameters
            $role_filter = $_GET['role_filter'] ?? '';
            $status_filter = $_GET['status_filter'] ?? '';
            $search = $_GET['search'] ?? '';
            
            // Build query based on user role
            $sql = "
                SELECT u.*, s.name as station_name 
                FROM users u 
                LEFT JOIN stations s ON u.station_id = s.id 
                WHERE 1=1
            ";
            $params = [];
            
            // Superadmin: Can see all users
            if ($role !== 'superadmin') {
                // Admin/Manager: Only see users from their station
                if ($station_id) {
                    $sql .= " AND u.station_id = ?";
                    $params[] = $station_id;
                } else {
                    // User has no station_id - return empty
                    echo json_encode(['success' => true, 'data' => []]);
                    exit;
                }
            }
            
            // Apply additional filters
            if ($role_filter) {
                $sql .= " AND u.role = ?";
                $params[] = $role_filter;
            }
            
            if ($status_filter) {
                $sql .= " AND u.status = ?";
                $params[] = $status_filter;
            }
            
            if ($search) {
                $sql .= " AND (u.username LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $sql .= " ORDER BY u.name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Remove sensitive data
            foreach ($users as &$user) {
                unset($user['password']);
            }
            
            echo json_encode(['success' => true, 'data' => $users]);
            break;
            
        case 'get':
            // Get single user by ID
            $user_id = $_GET['id'] ?? 0;
            
            if (!$user_id) {
                echo json_encode(['success' => false, 'error' => 'User ID is required']);
                exit;
            }
            
            // Check if user can access this user
            $me = current_user();
            $role = strtolower(trim($me['role'] ?? 'staff'));
            $my_station_id = $me['station_id'] ?? null;
            
            if ($role !== 'superadmin') {
                // Check if target user is in same station
                $check = $pdo->prepare("SELECT station_id FROM users WHERE id = ?");
                $check->execute([$user_id]);
                $target_station = $check->fetchColumn();
                
                if ($target_station != $my_station_id) {
                    echo json_encode(['success' => false, 'error' => 'You can only view users from your station']);
                    exit;
                }
            }
            
            $stmt = $pdo->prepare("SELECT u.*, s.name as station_name FROM users u LEFT JOIN stations s ON u.station_id = s.id WHERE u.id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            // Remove sensitive data
            unset($user['password']);
            
            echo json_encode(['success' => true, 'data' => $user]);
            break;
            
        case 'add':
            // Create new user
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Method not allowed', 'method_required' => 'POST']);
                exit;
            }
            
            $me = current_user();
            $role = strtolower(trim($me['role'] ?? 'staff'));
            $my_station_id = $me['station_id'] ?? null;
            
            // Only admin and superadmin can create users
            if (!in_array($role, ['admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'error' => 'Only admins can create users']);
                exit;
            }
            
            $name = trim($_POST['name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $user_role = trim($_POST['role'] ?? 'staff');
            $password = $_POST['password'] ?? '';
            $station_id = $_POST['station_id'] ?? '';
            $status = $_POST['status'] ?? 'active';
            
            // Validation
            if (empty($name) || empty($username)) {
                echo json_encode(['success' => false, 'error' => 'Name and Username are required']);
                exit;
            }
            
            // Check if username exists
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Username already exists']);
                exit;
            }
            
            // Validate role
            $valid_roles = ['staff', 'manager', 'admin', 'superadmin'];
            if (!in_array(strtolower($user_role), $valid_roles)) {
                echo json_encode(['success' => false, 'error' => 'Invalid role']);
                exit;
            }
            
            // Determine station_id using StationManager
            try {
                $station_id = StationManager::getTargetStationForUserCreation(
                    $me['role'],
                    $my_station_id,
                    $station_id
                );
                
                // Log the attempt
                StationManager::logStationAssignmentAttempt(
                    $me['id'],
                    $me['role'],
                    $my_station_id,
                    $station_id,
                    true
                );
            } catch (Exception $e) {
                // Log failed attempt
                StationManager::logStationAssignmentAttempt(
                    $me['id'],
                    $me['role'],
                    $my_station_id,
                    $_POST['station_id'] ?? null,
                    false
                );
                
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            
            // Security: Non-superadmin cannot create admin/superadmin users
            if ($role !== 'superadmin' && in_array(strtolower($user_role), ['admin', 'superadmin'])) {
                echo json_encode(['success' => false, 'error' => 'You cannot create admin or superadmin users']);
                exit;
            }
            
            // Hash password
            if (empty($password)) {
                require_once __DIR__ . '/../lib.php';
                $password = generateSecurePassword();
            }
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $pdo->prepare("INSERT INTO users (username, password, name, email, phone, role, station_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$username, $hashed_password, $name, $email, $phone, $user_role, $station_id, $status]);
            
            $user_id = $pdo->lastInsertId();
            
            log_activity($pdo, $me['id'], 'Create User', "Created user $username ($user_role) in station $station_id");
            
            echo json_encode(['success' => true, 'message' => 'User created successfully', 'user_id' => $user_id, 'password' => $password]);
            break;
            
        case 'update':
            // Update existing user
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Method not allowed', 'method_required' => 'POST']);
                exit;
            }
            
            $me = current_user();
            $role = strtolower(trim($me['role'] ?? 'staff'));
            $my_station_id = $me['station_id'] ?? null;
            
            $user_id = $_POST['user_id'] ?? 0;
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $user_role = trim($_POST['role'] ?? 'staff');
            $station_id = $_POST['station_id'] ?? '';
            $status = $_POST['status'] ?? 'active';
            
            // Validation
            if (empty($user_id) || empty($name)) {
                echo json_encode(['success' => false, 'error' => 'User ID and Name are required']);
                exit;
            }
            
            // Check if user exists and has permission to edit
            $check = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $check->execute([$user_id]);
            $target_user = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$target_user) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            // Permission checks
            if ($role !== 'superadmin') {
                // Check if target user is in same station
                if ($target_user['station_id'] != $my_station_id) {
                    echo json_encode(['success' => false, 'error' => 'You can only edit users from your station']);
                    exit;
                }
                
                // Prevent privilege escalation
                if (in_array(strtolower($user_role), ['admin', 'superadmin'])) {
                    echo json_encode(['success' => false, 'error' => 'You cannot assign admin or superadmin roles']);
                    exit;
                }
                
                $station_id = $my_station_id;
            }
            
            // Prevent editing own role if not superadmin
            if ($user_id == $me['id'] && $role !== 'superadmin') {
                if (strtolower($user_role) !== strtolower($me['role'])) {
                    echo json_encode(['success' => false, 'error' => 'You cannot change your own role']);
                    exit;
                }
            }
            
            // Update user
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, role = ?, station_id = ?, status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $email, $phone, $user_role, $station_id, $status, $user_id]);
            
            log_activity($pdo, $me['id'], 'Update User', "Updated user ID $user_id");
            
            echo json_encode(['success' => true, 'message' => 'User updated successfully']);
            break;
            
        case 'delete':
            // Delete user (soft delete - set status to inactive)
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Method not allowed', 'method_required' => 'POST']);
                exit;
            }
            
            $me = current_user();
            $role = strtolower(trim($me['role'] ?? 'staff'));
            
            // Only superadmin can delete users
            if ($role !== 'superadmin') {
                echo json_encode(['success' => false, 'error' => 'Only superadmin can delete users']);
                exit;
            }
            
            $user_id = $_POST['user_id'] ?? 0;
            
            if (empty($user_id)) {
                echo json_encode(['success' => false, 'error' => 'User ID is required']);
                exit;
            }
            
            // Prevent deleting self
            if ($user_id == $me['id']) {
                echo json_encode(['success' => false, 'error' => 'You cannot delete your own account']);
                exit;
            }
            
            // Check if user exists
            $check = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
            $check->execute([$user_id]);
            $target_user = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$target_user) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            // Soft delete - set status to inactive
            $stmt = $pdo->prepare("UPDATE users SET status = 'inactive', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$user_id]);
            
            log_activity($pdo, $me['id'], 'Delete User', "Deleted user {$target_user['username']} (ID: $user_id)");
            
            echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
            break;
            
        case 'reset_password':
            // Reset user password
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Method not allowed', 'method_required' => 'POST']);
                exit;
            }
            
            $me = current_user();
            $role = strtolower(trim($me['role'] ?? 'staff'));
            $my_station_id = $me['station_id'] ?? null;
            
            $user_id = $_POST['user_id'] ?? 0;
            
            if (empty($user_id)) {
                echo json_encode(['success' => false, 'error' => 'User ID is required']);
                exit;
            }
            
            // Get target user
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$target_user) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            // Permission checks
            if ($role !== 'superadmin') {
                // Check if target user is in same station
                if ($target_user['station_id'] != $my_station_id) {
                    echo json_encode(['success' => false, 'error' => 'You can only reset passwords for users in your station']);
                    exit;
                }
                
                // Cannot reset admin/manager passwords
                if (in_array(strtolower($target_user['role']), ['admin', 'manager', 'superadmin'])) {
                    echo json_encode(['success' => false, 'error' => 'You can only reset passwords for staff users']);
                    exit;
                }
            }
            
            // Generate new password
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            $new_password = '';
            for ($i = 0; $i < 12; $i++) {
                $new_password .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password
            $stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            // Set password expiry if column exists
            try {
                $expires = (new DateTime("+90 days"))->format('Y-m-d H:i:s');
                $pdo->prepare("UPDATE users SET password_expires_at = ? WHERE id = ?")->execute([$expires, $user_id]);
            } catch(Exception $e){}
            
            log_activity($pdo, $me['id'], 'Reset Password', "Reset password for user {$target_user['username']} (ID: $user_id)");
            
            echo json_encode(['success' => true, 'message' => 'Password reset successfully', 'new_password' => $new_password]);
            break;
            
        case 'toggle_status':
            // Activate/deactivate user
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Method not allowed', 'method_required' => 'POST']);
                exit;
            }
            
            $me = current_user();
            $role = strtolower(trim($me['role'] ?? 'staff'));
            $my_station_id = $me['station_id'] ?? null;
            
            $user_id = $_POST['user_id'] ?? 0;
            $new_status = $_POST['new_status'] ?? 'active';
            
            if (empty($user_id)) {
                echo json_encode(['success' => false, 'error' => 'User ID is required']);
                exit;
            }
            
            // Validate status
            if (!in_array($new_status, ['active', 'inactive'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid status']);
                exit;
            }
            
            // Prevent deactivating self
            if ($user_id == $me['id']) {
                echo json_encode(['success' => false, 'error' => 'You cannot change your own status']);
                exit;
            }
            
            // Get target user
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$target_user) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            // Permission check
            if ($role !== 'superadmin') {
                if ($target_user['station_id'] != $my_station_id) {
                    echo json_encode(['success' => false, 'error' => 'You can only change status for users in your station']);
                    exit;
                }
            }
            
            // Update status
            $stmt = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $user_id]);
            
            log_activity($pdo, $me['id'], 'Change Status', "Changed user {$target_user['username']} status to $new_status");
            
            echo json_encode(['success' => true, 'message' => "User status changed to $new_status"]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action', 'available_actions' => ['list', 'get', 'add', 'update', 'delete', 'reset_password', 'toggle_status']]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
