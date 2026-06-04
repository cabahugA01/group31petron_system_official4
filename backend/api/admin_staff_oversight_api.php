<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? ''); // use canonical role_key() — handles "Station Admin", "Admin/Manager", etc.

// Only allow admin and superadmin
if (!in_array($role, ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. Admin privileges required.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'fetch_staff_oversight':
            // Fetch staff users and their aggregated metrics
            $sql = "
                SELECT 
                    u.id as staff_id,
                    u.emp_id,
                    u.name,
                    u.username,
                    u.email,
                    u.station_id,
                    u.role as assigned_role,
                    s.name as station_name,
                    u.status as account_status,
                    u.remarks,
                    (SELECT created_at FROM activity_logs WHERE user_id = u.id AND action = 'Login' ORDER BY created_at DESC LIMIT 1) as last_login,
                    (SELECT created_at FROM activity_logs WHERE user_id = u.id AND action LIKE '%Encod%' ORDER BY created_at DESC LIMIT 1) as last_encoded_transaction,
                    (SELECT created_at FROM activity_logs WHERE user_id = u.id AND (action LIKE '%Approve%' OR action LIKE '%Validat%' OR action LIKE '%Reject%') ORDER BY created_at DESC LIMIT 1) as last_validated_transaction,
                    (SELECT COUNT(*) FROM stock_requests WHERE staff_id = u.id) as total_requests_encoded,
                    (SELECT COUNT(*) FROM fuel_deliveries WHERE received_by = u.id) as total_deliveries_encoded,
                    (SELECT COUNT(*) FROM stock_requests WHERE manager_id = u.id AND status IN ('Approved', 'Validated')) as total_requests_validated,
                    (SELECT COUNT(*) FROM fuel_deliveries WHERE verified_by = u.id AND status = 'Delivered') as total_deliveries_validated
                FROM users u
                LEFT JOIN stations s ON u.station_id = s.id
                WHERE u.role IN ('staff', 'operations_staff', 'manager') AND u.is_deleted = 0
            ";
            
            // If admin, only show staff from their station
            $params = [];
            $my_station_id = (int)($me['station_id'] ?? 0);
            
            if ($role === 'admin') {
                // Validate station ID
                if ($my_station_id <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid station assignment. Please contact system administrator.']);
                    exit;
                }
                $sql .= " AND u.station_id = ?";
                $params[] = $my_station_id;
            }
            
            $sql .= " ORDER BY s.name ASC, u.role ASC, u.name ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $staffData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch Summary Metrics
            $pendingSql = "SELECT COUNT(*) FROM stock_requests WHERE status = 'Pending'";
            $delSql = "SELECT COUNT(*) FROM fuel_deliveries WHERE 1=1";
            $metricParams = [];
            
            if ($role === 'admin') {
                $pendingSql .= " AND station_id = ?";
                $delSql .= " AND station_id = ?";
                $metricParams = [$my_station_id, $my_station_id];
            }
            
            $pendingStmt = $pdo->prepare($pendingSql);
            $pendingStmt->execute($role === 'admin' ? [$my_station_id] : []);
            $pendingCount = $pendingStmt->fetchColumn();
            
            $delStmt = $pdo->prepare($delSql);
            $delStmt->execute($role === 'admin' ? [$my_station_id] : []);
            $deliveriesCount = $delStmt->fetchColumn();
            
            echo json_encode([
                'success' => true, 
                'data' => $staffData,
                'metrics' => [
                    'pending_requests' => $pendingCount,
                    'total_deliveries' => $deliveriesCount
                ]
            ]);
            break;

        case 'update_status':
            $staff_id = $_POST['staff_id'] ?? 0;
            $status = $_POST['status'] ?? '';
            
            // Only allow 'active' and 'inactive' until database enum includes 'suspended'
            if (!$staff_id || !in_array($status, ['active', 'inactive'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters. Status must be active or inactive.']);
                exit;
            }
            
            // Ensure target is staff/manager and (if admin) in same station
            $checkSql = "SELECT station_id FROM users WHERE id = ? AND role IN ('staff', 'operations_staff', 'manager') AND is_deleted = 0";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$staff_id]);
            $target = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$target || ($role === 'admin' && $target['station_id'] != $me['station_id'])) {
                echo json_encode(['success' => false, 'error' => 'Staff not found or unauthorized']);
                exit;
            }
            
            $update = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $update->execute([$status, $staff_id]);
            
            log_activity($pdo, $me['id'], 'Change Status', "Updated staff #$staff_id status to $status from admin oversight");
            
            echo json_encode(['success' => true]);
            break;
            
        case 'update_remark':
            $staff_id = $_POST['staff_id'] ?? 0;
            $remarks = $_POST['remarks'] ?? '';
            
            if (!$staff_id) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            
            $checkSql = "SELECT station_id FROM users WHERE id = ? AND role IN ('staff', 'operations_staff', 'manager') AND is_deleted = 0";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$staff_id]);
            $target = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$target || ($role === 'admin' && $target['station_id'] != $me['station_id'])) {
                echo json_encode(['success' => false, 'error' => 'Staff not found or unauthorized']);
                exit;
            }
            
            $update = $pdo->prepare("UPDATE users SET remarks = ? WHERE id = ?");
            $update->execute([$remarks, $staff_id]);
            
            echo json_encode(['success' => true]);
            break;

        case 'edit_user':
            $staff_id = $_POST['staff_id'] ?? 0;
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $edit_role = strtolower(trim($_POST['role'] ?? ''));
            $status = trim($_POST['status'] ?? '');
            
            // Only allow 'active' and 'inactive' until database enum includes 'suspended'
            if (!$staff_id || !$name || !$email || !in_array($edit_role, ['manager', 'staff']) || !in_array($status, ['active', 'inactive'])) {
                echo json_encode(['success' => false, 'error' => 'All fields are required and must be valid. Status must be active or inactive.']);
                exit;
            }
            
            $checkSql = "SELECT station_id FROM users WHERE id = ? AND role IN ('staff', 'operations_staff', 'manager') AND is_deleted = 0";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$staff_id]);
            $target = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$target || ($role === 'admin' && $target['station_id'] != $me['station_id'])) {
                echo json_encode(['success' => false, 'error' => 'Staff not found or unauthorized']);
                exit;
            }

            if ($edit_role === 'manager') {
                $checkMgr = $pdo->prepare("SELECT id FROM users WHERE station_id = ? AND role = 'manager' AND is_deleted = 0 AND id != ?");
                $checkMgr->execute([$target['station_id'], $staff_id]);
                if ($checkMgr->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'This station already has a manager. Only one manager is allowed per station.']);
                    exit;
                }
            }

            $updateSql = "UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ?";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([$name, $email, $edit_role, $status, $staff_id]);

            log_activity($pdo, $me['id'], 'Edit User', "Edited staff #$staff_id ($name) via Admin Oversight");
            
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
