<?php
/**
 * Role-Based Access Control (RBAC) System
 * Defines permissions and access control for Super Admin functionality
 */

// Define all permissions
define('VIEW_NATIONWIDE_DASHBOARD', 'VIEW_NATIONWIDE_DASHBOARD');
define('VIEW_ALL_STATIONS', 'VIEW_ALL_STATIONS');
define('VIEW_STATION_PROFILE', 'VIEW_STATION_PROFILE');
define('ACTIVATE_DEACTIVATE_STATION', 'ACTIVATE_DEACTIVATE_STATION');
define('CREATE_STATION_ADMIN', 'CREATE_STATION_ADMIN');
define('CREATE_DEFAULT_ROLES_FOR_STATION', 'CREATE_DEFAULT_ROLES_FOR_STATION');
define('RESET_PASSWORD', 'RESET_PASSWORD');
define('DEACTIVATE_USER', 'DEACTIVATE_USER');
define('VIEW_ALL_USERS', 'VIEW_ALL_USERS');
define('GENERATE_NATIONWIDE_SALES_REPORT', 'GENERATE_NATIONWIDE_SALES_REPORT');
define('GENERATE_FUEL_REPORT', 'GENERATE_FUEL_REPORT');
define('GENERATE_JOB_ORDER_REPORT', 'GENERATE_JOB_ORDER_REPORT');
define('GENERATE_CUSTOMER_CREDIT_REPORT', 'GENERATE_CUSTOMER_CREDIT_REPORT');
define('VIEW_USER_LOGS', 'VIEW_USER_LOGS');
define('VIEW_TRANSACTION_LOGS', 'VIEW_TRANSACTION_LOGS');
define('VIEW_INVENTORY_LOGS', 'VIEW_INVENTORY_LOGS');
define('MANAGE_SERVICE_RATES', 'MANAGE_SERVICE_RATES');
define('MANAGE_CALIBRATION_VALUES', 'MANAGE_CALIBRATION_VALUES');
define('UNLOCK_RECORDS', 'UNLOCK_RECORDS');

// Manager-specific permissions
define('VIEW_MANAGER_DASHBOARD', 'VIEW_MANAGER_DASHBOARD');
define('REVIEW_PENDING_JOB_ORDERS', 'REVIEW_PENDING_JOB_ORDERS');
define('VIEW_JOB_ORDER_HISTORY', 'VIEW_JOB_ORDER_HISTORY');
define('VERIFY_FUEL_RECONCILIATION', 'VERIFY_FUEL_RECONCILIATION');
define('VERIFY_SHIFT_REPORTS', 'VERIFY_SHIFT_REPORTS');
define('FINALIZE_FUEL_RECONCILIATION', 'FINALIZE_FUEL_RECONCILIATION');
define('FINALIZE_SHIFT_REPORTS', 'FINALIZE_SHIFT_REPORTS');
define('APPROVE_INVENTORY_ADJUSTMENTS', 'APPROVE_INVENTORY_ADJUSTMENTS');
define('APPROVE_DELIVERIES', 'APPROVE_DELIVERIES');
define('VIEW_LOGS', 'VIEW_LOGS');
define('APPROVE_REPORTS', 'APPROVE_REPORTS');

// Role permissions mapping
$role_permissions = [
    'superadmin' => [
        // Dashboard permissions
        VIEW_NATIONWIDE_DASHBOARD,
        
        // Station Management permissions
        VIEW_ALL_STATIONS,
        VIEW_STATION_PROFILE,
        ACTIVATE_DEACTIVATE_STATION,
        
        // User Management permissions
        CREATE_STATION_ADMIN,
        CREATE_DEFAULT_ROLES_FOR_STATION,
        RESET_PASSWORD,
        DEACTIVATE_USER,
        VIEW_ALL_USERS,
        
        // Nationwide Reports permissions
        GENERATE_NATIONWIDE_SALES_REPORT,
        GENERATE_FUEL_REPORT,
        GENERATE_JOB_ORDER_REPORT,
        GENERATE_CUSTOMER_CREDIT_REPORT,
        
        // Audit Logs permissions
        VIEW_USER_LOGS,
        VIEW_TRANSACTION_LOGS,
        VIEW_INVENTORY_LOGS,
        
        // System Settings permissions
        MANAGE_SERVICE_RATES,
        MANAGE_CALIBRATION_VALUES,
    ],
    
    'admin' => [
        // Admin (Owner) - Read-only operations, can unlock finalized records
        VIEW_STATION_PROFILE,
        VIEW_ALL_USERS, // Limited to their station
        GENERATE_NATIONWIDE_SALES_REPORT, // Limited to their station
        GENERATE_FUEL_REPORT, // Limited to their station
        GENERATE_JOB_ORDER_REPORT, // Limited to their station
        VIEW_USER_LOGS, // Limited to their station
        VIEW_TRANSACTION_LOGS, // Limited to their station
        VIEW_INVENTORY_LOGS, // Limited to their station
        UNLOCK_RECORDS, // Can unlock finalized records with password + reason
    ],
    
    'manager' => [
        // Manager Dashboard permissions
        VIEW_MANAGER_DASHBOARD,

        // Job Order Review permissions
        REVIEW_PENDING_JOB_ORDERS,
        VIEW_JOB_ORDER_HISTORY,

        // Reconciliation Verification permissions
        VERIFY_FUEL_RECONCILIATION,
        VERIFY_SHIFT_REPORTS,

        // Reconciliation Finalization (Manager can finalize)
        FINALIZE_FUEL_RECONCILIATION,
        FINALIZE_SHIFT_REPORTS,

        // Inventory Approvals (Manager can approve)
        APPROVE_INVENTORY_ADJUSTMENTS,
        APPROVE_DELIVERIES,

        // Audit View permissions
        VIEW_LOGS,

        // Station-specific permissions
        VIEW_STATION_PROFILE,
        RESET_PASSWORD, // Limited to their station
        VIEW_ALL_USERS, // Limited to their station
        GENERATE_NATIONWIDE_SALES_REPORT, // Limited to their station
        GENERATE_FUEL_REPORT, // Limited to their station
        GENERATE_JOB_ORDER_REPORT, // Limited to their station
        VIEW_USER_LOGS, // Limited to their station
        VIEW_TRANSACTION_LOGS, // Limited to their station
    ],
    
    'staff' => [
        // Basic permissions
        VIEW_STATION_PROFILE,
    ]
];

/**
 * Check if user has specific permission
 */
function has_permission($permission, $user_role = null) {
    global $role_permissions;
    
    if ($user_role === null) {
        $user = current_user();
        $user_role = $user['role'] ?? 'staff';
    }
    
    $user_role = strtolower($user_role);
    
    // Normalize role names
    if ($user_role === 'super admin') $user_role = 'superadmin';
    if ($user_role === 'administrator') $user_role = 'admin';
    
    return isset($role_permissions[$user_role]) && 
           in_array($permission, $role_permissions[$user_role]);
}

/**
 * Get all permissions for a role
 */
function get_role_permissions($role) {
    global $role_permissions;
    
    $role = strtolower($role);
    if ($role === 'super admin') $role = 'superadmin';
    if ($role === 'administrator') $role = 'admin';
    
    return $role_permissions[$role] ?? [];
}

/**
 * Check if user can access station-specific data
 */
function can_access_station($station_id, $user = null) {
    if ($user === null) {
        $user = current_user();
    }
    
    $role = strtolower($user['role'] ?? 'staff');
    
    // Super admins can access all stations
    if ($role === 'superadmin' || $role === 'super admin') {
        return true;
    }
    
    // Other roles can only access their assigned station
    return ($user['station_id'] ?? null) == $station_id;
}

/**
 * Filter data based on user's station access
 */
function filter_by_station_access($query, $user = null) {
    if ($user === null) {
        $user = current_user();
    }
    
    $role = strtolower($user['role'] ?? 'staff');
    
    // Super admins see all data
    if ($role === 'superadmin' || $role === 'super admin') {
        return $query;
    }
    
    // Other roles are filtered by their station
    $station_id = $user['station_id'] ?? 0;
    if (strpos($query, 'WHERE') !== false) {
        return $query . " AND station_id = $station_id";
    } else {
        return $query . " WHERE station_id = $station_id";
    }
}

/**
 * Log user action for audit trail
 */
function log_user_action($action, $details = '', $user_id = null) {
    global $pdo;
    
    if ($user_id === null) {
        $user = current_user();
        $user_id = $user['id'] ?? 0;
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $details, $_SERVER['REMOTE_ADDR']]);
    } catch (Exception $e) {
        // Fail silently if logging fails
        error_log("Failed to log user action: " . $e->getMessage());
    }
}
?>
