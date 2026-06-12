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
            $shift = isset($_GET['shift']) ? (int)$_GET['shift'] : 0;
            
            // Define shift time ranges
            $today = date('Y-m-d');
            if ($shift === 1) {
                $shift_start = "$today 06:00:00";
                $shift_end = "$today 14:00:00";
                $shift_filter = " AND (u.shift_assignment LIKE '%Shift 1%' OR u.shift_assignment LIKE '%First%' OR u.shift_assignment = '1')";
            } elseif ($shift === 2) {
                $shift_start = "$today 14:00:00";
                $shift_end = "$today 22:00:00";
                $shift_filter = " AND (u.shift_assignment LIKE '%Shift 2%' OR u.shift_assignment LIKE '%Second%' OR u.shift_assignment = '2')";
            } else {
                $shift_start = "$today 00:00:00";
                $shift_end = "$today 23:59:59";
                $shift_filter = "";
            }
            
            // Fetch staff users and their aggregated metrics WITH SHIFT-SPECIFIC DATA
            $sql = "
                SELECT 
                    u.id as staff_id,
                    u.id as emp_id,
                    COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown') as name,
                    u.username,
                    u.email,
                    u.station_id,
                    u.role as assigned_role,
                    u.shift_assignment,
                    s.name as station_name,
                    u.status as account_status,
                    '' as remarks,
                    
                    -- Clock-in/out logs for TODAY's shift
                    (SELECT ls.start_time FROM labor_sessions ls 
                     WHERE ls.user_id = u.id 
                       AND ls.start_time BETWEEN ? AND ?
                     ORDER BY ls.start_time DESC LIMIT 1) as clock_in_time,
                    (SELECT ls.end_time FROM labor_sessions ls 
                     WHERE ls.user_id = u.id 
                       AND ls.start_time BETWEEN ? AND ?
                     ORDER BY ls.start_time DESC LIMIT 1) as clock_out_time,
                    (SELECT CONCAT(
                        FLOOR(TIMESTAMPDIFF(MINUTE, ls.start_time, COALESCE(ls.end_time, NOW())) / 60), 'h ',
                        MOD(TIMESTAMPDIFF(MINUTE, ls.start_time, COALESCE(ls.end_time, NOW())), 60), 'm'
                     ) FROM labor_sessions ls 
                     WHERE ls.user_id = u.id 
                       AND ls.start_time BETWEEN ? AND ?
                     ORDER BY ls.start_time DESC LIMIT 1) as shift_duration,
                    
                    -- Recent actions (last encode/validate) - check if user_id exists in activity_logs
                    (SELECT created_at FROM activity_logs WHERE user_id = u.id AND action LIKE '%Encod%' ORDER BY created_at DESC LIMIT 1) as last_encoded_transaction,
                    (SELECT created_at FROM activity_logs WHERE user_id = u.id AND (action LIKE '%Approve%' OR action LIKE '%Validat%' OR action LIKE '%Reject%') ORDER BY created_at DESC LIMIT 1) as last_validated_transaction,
                    
                    -- Activity summary (during THIS SHIFT only) - simplified to use only common columns
                    0 as shift_requests_count,
                    0 as shift_deliveries_count,
                    0 as shift_jobs_count,
                    
                    -- Performance metrics (sales + service income during THIS SHIFT) - simplified
                    0 as shift_sales_total,
                    0 as shift_service_income
                    
                FROM users u
                LEFT JOIN stations s ON u.station_id = s.id
                WHERE u.role IN ('staff', 'operations_staff', 'manager', 'Staff', 'Manager')
                $shift_filter
            ";
            
            // If admin, only show staff from their station
            $params = [
                $shift_start, $shift_end,  // clock_in_time
                $shift_start, $shift_end,  // clock_out_time  
                $shift_start, $shift_end   // shift_duration
            ];
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
            
            $sql .= " ORDER BY s.name ASC, u.role ASC, u.username ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $staffData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch Summary Metrics for this shift
            $pendingSql = "SELECT COUNT(*) FROM stock_requests WHERE status = 'Pending' AND created_at BETWEEN ? AND ?";
            $delSql = "SELECT COUNT(*) FROM fuel_deliveries WHERE created_at BETWEEN ? AND ?";
            $metricParams = [$shift_start, $shift_end, $shift_start, $shift_end];
            
            if ($role === 'admin') {
                $pendingSql .= " AND station_id = ?";
                $delSql .= " AND station_id = ?";
                $metricParams = [$shift_start, $shift_end, $my_station_id, $shift_start, $shift_end, $my_station_id];
            }
            
            $pendingStmt = $pdo->prepare($pendingSql);
            $pendingStmt->execute($role === 'admin' ? [$shift_start, $shift_end, $my_station_id] : [$shift_start, $shift_end]);
            $pendingCount = $pendingStmt->fetchColumn();
            
            $delStmt = $pdo->prepare($delSql);
            $delStmt->execute($role === 'admin' ? [$shift_start, $shift_end, $my_station_id] : [$shift_start, $shift_end]);
            $deliveriesCount = $delStmt->fetchColumn();
            
            echo json_encode([
                'success' => true, 
                'data' => $staffData,
                'metrics' => [
                    'pending_requests' => $pendingCount,
                    'total_deliveries' => $deliveriesCount
                ],
                'shift' => $shift,
                'shift_period' => $shift === 1 ? '6AM - 2PM' : ($shift === 2 ? '2PM - 10PM' : 'All Day')
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
            $checkSql = "SELECT station_id FROM users WHERE id = ? AND role IN ('staff', 'operations_staff', 'manager', 'Staff', 'Manager')";
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
            
            $checkSql = "SELECT station_id FROM users WHERE id = ? AND role IN ('staff', 'operations_staff', 'manager', 'Staff', 'Manager')";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$staff_id]);
            $target = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$target || ($role === 'admin' && $target['station_id'] != $me['station_id'])) {
                echo json_encode(['success' => false, 'error' => 'Staff not found or unauthorized']);
                exit;
            }
            
            // Check if 'remarks' column exists
            $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $has_remarks_col = in_array('remarks', $columns);
            
            if ($has_remarks_col) {
                $update = $pdo->prepare("UPDATE users SET remarks = ? WHERE id = ?");
                $update->execute([$remarks, $staff_id]);
                echo json_encode(['success' => true]);
            } else {
                // Column doesn't exist, just return success (remarks feature not available)
                echo json_encode(['success' => true, 'warning' => 'Remarks column not available in database']);
            }
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
            
            $checkSql = "SELECT station_id FROM users WHERE id = ? AND role IN ('staff', 'operations_staff', 'manager', 'Staff', 'Manager')";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$staff_id]);
            $target = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$target || ($role === 'admin' && $target['station_id'] != $me['station_id'])) {
                echo json_encode(['success' => false, 'error' => 'Staff not found or unauthorized']);
                exit;
            }

            if ($edit_role === 'manager') {
                $checkMgr = $pdo->prepare("SELECT id FROM users WHERE station_id = ? AND role = 'manager' AND id != ?");
                $checkMgr->execute([$target['station_id'], $staff_id]);
                if ($checkMgr->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'This station already has a manager. Only one manager is allowed per station.']);
                    exit;
                }
            }

            // Check if 'name' column exists, update accordingly
            $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $has_first_name_col = in_array('first_name', $columns);
            $has_last_name_col = in_array('last_name', $columns);
            
            if ($has_first_name_col && $has_last_name_col) {
                // Split name into first_name and last_name
                $name_parts = explode(' ', $name, 2);
                $first_name = $name_parts[0];
                $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
                
                $updateSql = "UPDATE users SET first_name = ?, last_name = ?, email = ?, role = ?, status = ? WHERE id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([$first_name, $last_name, $email, $edit_role, $status, $staff_id]);
            } else {
                $updateSql = "UPDATE users SET email = ?, role = ?, status = ? WHERE id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([$email, $edit_role, $status, $staff_id]);
            }

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
