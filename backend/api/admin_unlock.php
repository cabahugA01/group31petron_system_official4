<?php
/**
 * Admin Unlock API Endpoint
 * REST API for Admin unlock operations
 * 100% Hierarchy Compliance
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// Only Admin can access unlock operations
if (!in_array($role, ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Only Admin can unlock records']);
    exit;
}

try {
    // Initialize unlock operations
    require_once __DIR__ . '/admin_unlock_operations.php';
    $unlockOps = new AdminUnlockOperations($pdo, $me, $station_id);
    
    switch ($action) {
        case 'unlock_fuel_reconciliation':
        case 'unlock_shift_report':
        case 'unlock_job_order':
            if ($method !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $id = (int)($_POST['record_id'] ?? 0);
            $password = $_POST['password'] ?? '';
            $reason = $_POST['reason'] ?? '';
            
            if (empty($id) || empty($password) || empty($reason)) {
                echo json_encode(['success' => false, 'error' => 'ID, password, and reason are required']);
                exit;
            }
            
            // Map action to method
            $method_map = [
                'unlock_fuel_reconciliation' => 'unlockFuelReconciliation',
                'unlock_shift_report' => 'unlockShiftReport',
                'unlock_job_order' => 'unlockJobOrder'
            ];
            
            if (isset($method_map[$action])) {
                $method = $method_map[$action];
                $result = $unlockOps->$method($id, $password, $reason);
                echo json_encode($result);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid table type']);
            }
            break;
            
        case 'get_unlock_history':
            $table = $_GET['table'] ?? '';
            $record_id = (int)($_GET['record_id'] ?? 0);
            
            if (empty($table) || empty($record_id)) {
                echo json_encode(['success' => false, 'error' => 'Table and record_id are required']);
                exit;
            }
            
            $history = $unlockOps->getUnlockHistory($table, $record_id);
            echo json_encode(['success' => true, 'data' => $history]);
            break;
            
        case 'get_locked_records':
            $table = $_GET['table'] ?? '';
            
            if (empty($table)) {
                echo json_encode(['success' => false, 'error' => 'Table name is required']);
                exit;
            }
            
            $records = $unlockOps->getLockedRecords($table);
            echo json_encode(['success' => true, 'data' => $records]);
            break;
            
        case 'get_all_unlocks':
            if ($role !== 'superadmin') {
                echo json_encode(['success' => false, 'error' => 'Only Super Admin can view all unlock history']);
                exit;
            }
            
            $limit = (int)($_GET['limit'] ?? 50);
            $unlocks = $unlockOps->getAllRecentUnlocks($limit);
            echo json_encode(['success' => true, 'data' => $unlocks]);
            break;
            
        default:
            echo json_encode([
                'success' => false, 
                'error' => 'Invalid action',
                'available_actions' => [
                    'unlock_fuel_reconciliation',
                    'unlock_shift_report',
                    'unlock_job_order',
                    'get_unlock_history',
                    'get_locked_records',
                    'get_all_unlocks'
                ]
            ]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
